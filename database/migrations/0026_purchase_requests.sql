-- Lot 3 VRSC : états de besoins (EDB) et approvisionnements (cahier des charges § 6 et § 5.1).
-- EDB → recherche stock local puis autres sites → transfert si disponible, sinon achat → devis → commande
-- référencée EDB → réception (bon de réception, stock) → clôture quand l'intégralité du besoin approuvé est satisfaite.

-- Statuts VRSC : pending = « en attente », approved = « approuvé non exécuté » (sous-statut « exécution partielle »
-- dès qu'une quantité est satisfaite), executed = « approuvé exécuté », cancelled = « annulé » (motif obligatoire).
CREATE TABLE purchase_requests (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference         VARCHAR(20)  NOT NULL,
    site_id           INT UNSIGNED NOT NULL,
    department_id     INT UNSIGNED NULL,
    project_id        INT UNSIGNED NULL,
    cost_center_id    INT UNSIGNED NULL,
    equipment_id      INT UNSIGNED NULL,
    category          ENUM('parts','consumables','fuel','tyres','services','materials','other') NOT NULL DEFAULT 'parts',
    urgency           ENUM('normal','urgent','critical') NOT NULL DEFAULT 'normal',
    subject           VARCHAR(150) NOT NULL,
    reason            VARCHAR(500) NOT NULL,
    status            ENUM('pending','approved','executed','cancelled') NOT NULL DEFAULT 'pending',
    is_rejected       TINYINT(1)   NOT NULL DEFAULT 0,
    estimated_amount  DECIMAL(16,2) NULL,
    requested_by      INT UNSIGNED NULL,
    requested_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at       DATETIME     NULL,
    executed_at       DATETIME     NULL,
    cancelled_at      DATETIME     NULL,
    cancel_reason     VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_purchase_requests_reference (reference),
    KEY idx_pr_site_status (site_id, status),
    KEY idx_pr_requested (requested_at),
    CONSTRAINT fk_pr_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_pr_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL,
    CONSTRAINT fk_pr_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
    CONSTRAINT fk_pr_cost_center FOREIGN KEY (cost_center_id) REFERENCES cost_centers (id) ON DELETE SET NULL,
    CONSTRAINT fk_pr_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE SET NULL,
    CONSTRAINT fk_pr_user FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ligne de besoin : article du catalogue (géré en stock) ou besoin libre (prestation, matériau hors catalogue).
CREATE TABLE purchase_request_lines (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id      INT UNSIGNED NOT NULL,
    part_id         INT UNSIGNED NULL,
    description     VARCHAR(190) NOT NULL,
    unit            VARCHAR(10)  NOT NULL DEFAULT 'u',
    qty_requested   DECIMAL(12,2) NOT NULL,
    est_unit_cost   DECIMAL(14,4) NULL,
    PRIMARY KEY (id),
    KEY idx_prl_request (request_id),
    CONSTRAINT fk_prl_request FOREIGN KEY (request_id) REFERENCES purchase_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_prl_part FOREIGN KEY (part_id) REFERENCES parts (id),
    CONSTRAINT chk_prl_qty CHECK (qty_requested > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Satisfaction datée d'une ligne : servie du stock local, reçue par transfert, ou reçue d'une commande.
-- La somme de ces écritures donne la quantité satisfaite à n'importe quelle date (soldes ouverts à la clôture).
CREATE TABLE purchase_fulfilments (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_line_id  INT UNSIGNED NOT NULL,
    kind             ENUM('stock','transfer','purchase') NOT NULL,
    quantity         DECIMAL(12,2) NOT NULL,
    value            DECIMAL(16,2) NULL,
    document_ref     VARCHAR(40)  NULL,
    done_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    done_by          INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_pf_line (request_line_id, done_at),
    CONSTRAINT fk_pf_line FOREIGN KEY (request_line_id) REFERENCES purchase_request_lines (id) ON DELETE CASCADE,
    CONSTRAINT fk_pf_user FOREIGN KEY (done_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_pf_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un transfert de stock peut répondre à une ligne d'EDB.
ALTER TABLE stock_transfer_lines
    ADD COLUMN request_line_id INT UNSIGNED NULL AFTER part_id,
    ADD CONSTRAINT fk_stl_request_line FOREIGN KEY (request_line_id) REFERENCES purchase_request_lines (id) ON DELETE SET NULL;

-- Devis fournisseurs comparés avant commande.
CREATE TABLE purchase_quotes (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id     INT UNSIGNED NOT NULL,
    supplier_id    INT UNSIGNED NOT NULL,
    reference      VARCHAR(60)  NULL,
    amount         DECIMAL(16,2) NOT NULL,
    delivery_days  SMALLINT UNSIGNED NULL,
    note           VARCHAR(255) NULL,
    is_selected    TINYINT(1)   NOT NULL DEFAULT 0,
    selection_reason VARCHAR(255) NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pq_request (request_id),
    CONSTRAINT fk_pq_request FOREIGN KEY (request_id) REFERENCES purchase_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_pq_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
    CONSTRAINT fk_pq_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Commande fournisseur, toujours référencée à un EDB approuvé.
CREATE TABLE purchase_orders (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference     VARCHAR(20)  NOT NULL,
    request_id    INT UNSIGNED NOT NULL,
    site_id       INT UNSIGNED NOT NULL,
    supplier_id   INT UNSIGNED NOT NULL,
    quote_id      INT UNSIGNED NULL,
    status        ENUM('issued','partially_received','received','cancelled') NOT NULL DEFAULT 'issued',
    total_amount  DECIMAL(16,2) NOT NULL DEFAULT 0,
    expected_on   DATE         NULL,
    note          VARCHAR(255) NULL,
    ordered_by    INT UNSIGNED NULL,
    ordered_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at     DATETIME     NULL,
    cancel_reason VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_purchase_orders_reference (reference),
    KEY idx_po_request (request_id),
    KEY idx_po_site_status (site_id, status),
    CONSTRAINT fk_po_request FOREIGN KEY (request_id) REFERENCES purchase_requests (id),
    CONSTRAINT fk_po_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
    CONSTRAINT fk_po_quote FOREIGN KEY (quote_id) REFERENCES purchase_quotes (id) ON DELETE SET NULL,
    CONSTRAINT fk_po_user FOREIGN KEY (ordered_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_order_lines (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id         INT UNSIGNED NOT NULL,
    request_line_id  INT UNSIGNED NOT NULL,
    qty_ordered      DECIMAL(12,2) NOT NULL,
    unit_price       DECIMAL(14,4) NOT NULL,
    qty_received     DECIMAL(12,2) NOT NULL DEFAULT 0,
    qty_rejected     DECIMAL(12,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pol_order_line (order_id, request_line_id),
    CONSTRAINT fk_pol_order FOREIGN KEY (order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_pol_request_line FOREIGN KEY (request_line_id) REFERENCES purchase_request_lines (id),
    CONSTRAINT chk_pol_qty CHECK (qty_ordered > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bon de réception : contrôle quantité / qualité, bon de livraison, transporteur, plaque, chauffeur (§ 5.1).
CREATE TABLE goods_receipts (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference      VARCHAR(20)  NOT NULL,
    order_id       INT UNSIGNED NOT NULL,
    site_id        INT UNSIGNED NOT NULL,
    delivery_note  VARCHAR(60)  NOT NULL,
    carrier_id     INT UNSIGNED NULL,
    vehicle_plate  VARCHAR(30)  NULL,
    driver_name    VARCHAR(120) NULL,
    note           VARCHAR(255) NULL,
    received_by    INT UNSIGNED NULL,
    received_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_goods_receipts_reference (reference),
    KEY idx_gr_order (order_id),
    CONSTRAINT fk_gr_order FOREIGN KEY (order_id) REFERENCES purchase_orders (id),
    CONSTRAINT fk_gr_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_gr_carrier FOREIGN KEY (carrier_id) REFERENCES carriers (id) ON DELETE SET NULL,
    CONSTRAINT fk_gr_user FOREIGN KEY (received_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE goods_receipt_lines (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    receipt_id     INT UNSIGNED NOT NULL,
    order_line_id  INT UNSIGNED NOT NULL,
    qty_accepted   DECIMAL(12,2) NOT NULL DEFAULT 0,
    qty_rejected   DECIMAL(12,2) NOT NULL DEFAULT 0,
    reject_reason  VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_grl_receipt (receipt_id),
    CONSTRAINT fk_grl_receipt FOREIGN KEY (receipt_id) REFERENCES goods_receipts (id) ON DELETE CASCADE,
    CONSTRAINT fk_grl_order_line FOREIGN KEY (order_line_id) REFERENCES purchase_order_lines (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Circuit d'approbation d'un EDB : visa hiérarchique (avis), approbation Direction Logistique,
-- puis approbation Finance à partir d'un seuil de montant (modifiable dans approval_flow_steps.min_amount).
INSERT INTO approval_flows (code, name) VALUES ('purchase_request', 'État de besoins (EDB)');

INSERT INTO approval_flow_steps (flow_id, position, code, name, kind, role, is_blocking, min_amount)
SELECT id, 10, 'visa', 'Visa hiérarchique', 'opinion', 'supervisor', 0, NULL FROM approval_flows WHERE code = 'purchase_request';

INSERT INTO approval_flow_steps (flow_id, position, code, name, kind, role, is_blocking, min_amount)
SELECT id, 20, 'logistics', 'Approbation Direction Logistique', 'authorization', 'logistics', 1, NULL FROM approval_flows WHERE code = 'purchase_request';

INSERT INTO approval_flow_steps (flow_id, position, code, name, kind, role, is_blocking, min_amount)
SELECT id, 30, 'finance', 'Approbation Finance', 'authorization', 'finance', 1, 5000 FROM approval_flows WHERE code = 'purchase_request';
