-- Lot 5 VRSC : finance, caisse et contrôle des engagements (cahier des charges § 7).
-- Demande autorisée → vérification des pièces et de la disponibilité → approbation → caisse ou virement
-- → preuve de paiement → rapprochement. Chaque décaissement se rattache à un EDB ou à un engagement approuvé,
-- à un site et à une preuve. Un paiement ne clôture jamais un EDB.

-- Direction générale : approuve les décaissements, vues consolidées.
ALTER TABLE users
    MODIFY role ENUM('admin','manager','technician','viewer','encoder',
                     'requester','supervisor','logistics','storekeeper','buyer','cashier','finance','director') NOT NULL DEFAULT 'viewer';

-- Facture fournisseur : le couple (fournisseur, numéro) est unique — une même facture ne s'enregistre qu'une fois.
CREATE TABLE supplier_invoices (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id     INT UNSIGNED NOT NULL,
    invoice_number  VARCHAR(60)  NOT NULL,
    invoice_date    DATE         NOT NULL,
    due_date        DATE         NULL,
    amount          DECIMAL(16,2) NOT NULL,
    order_id        INT UNSIGNED NOT NULL,
    site_id         INT UNSIGNED NOT NULL,
    status          ENUM('recorded','cancelled') NOT NULL DEFAULT 'recorded',
    note            VARCHAR(255) NULL,
    cancel_reason   VARCHAR(255) NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_supplier_invoices_number (supplier_id, invoice_number),
    KEY idx_sinv_order (order_id),
    KEY idx_sinv_site (site_id, status),
    CONSTRAINT fk_sinv_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
    CONSTRAINT fk_sinv_order FOREIGN KEY (order_id) REFERENCES purchase_orders (id),
    CONSTRAINT fk_sinv_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_sinv_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_sinv_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Demande de décaissement. kind : invoice = règlement d'une facture ; advance = avance à justifier sur un EDB approuvé ;
-- expense = dépense directe, dont l'approbation vaut engagement.
CREATE TABLE payment_requests (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference          VARCHAR(20)  NOT NULL,
    kind               ENUM('invoice','advance','expense') NOT NULL,
    site_id            INT UNSIGNED NOT NULL,
    project_id         INT UNSIGNED NULL,
    cost_center_id     INT UNSIGNED NULL,
    department_id      INT UNSIGNED NULL,
    request_id         INT UNSIGNED NULL,
    order_id           INT UNSIGNED NULL,
    invoice_id         INT UNSIGNED NULL,
    supplier_id        INT UNSIGNED NULL,
    beneficiary        VARCHAR(170) NOT NULL,
    category           ENUM('parts','consumables','fuel','tyres','services','materials','maintenance','other') NOT NULL DEFAULT 'other',
    amount             DECIMAL(16,2) NOT NULL,
    reason             VARCHAR(500) NOT NULL,
    status             ENUM('pending','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'pending',
    payment_method_id  INT UNSIGNED NULL,
    payment_reference  VARCHAR(80)  NULL,
    cash_register_id   INT UNSIGNED NULL,
    justified_amount   DECIMAL(16,2) NOT NULL DEFAULT 0,
    returned_amount    DECIMAL(16,2) NOT NULL DEFAULT 0,
    justified_at       DATETIME     NULL,
    requested_by       INT UNSIGNED NULL,
    requested_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at        DATETIME     NULL,
    paid_at            DATETIME     NULL,
    paid_by            INT UNSIGNED NULL,
    cancel_reason      VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_requests_reference (reference),
    KEY idx_pay_site_status (site_id, status),
    KEY idx_pay_request (request_id),
    KEY idx_pay_order (order_id),
    KEY idx_pay_invoice (invoice_id),
    KEY idx_pay_paid (paid_at),
    CONSTRAINT fk_pay_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_pay_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
    CONSTRAINT fk_pay_cost_center FOREIGN KEY (cost_center_id) REFERENCES cost_centers (id) ON DELETE SET NULL,
    CONSTRAINT fk_pay_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL,
    CONSTRAINT fk_pay_request FOREIGN KEY (request_id) REFERENCES purchase_requests (id),
    CONSTRAINT fk_pay_order FOREIGN KEY (order_id) REFERENCES purchase_orders (id),
    CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id) REFERENCES supplier_invoices (id),
    CONSTRAINT fk_pay_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
    CONSTRAINT fk_pay_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods (id),
    CONSTRAINT fk_pay_requested_by FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_pay_paid_by FOREIGN KEY (paid_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_pay_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Caisse d'un site.
CREATE TABLE cash_registers (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id     INT UNSIGNED NOT NULL,
    name        VARCHAR(80)  NOT NULL,
    balance     DECIMAL(16,2) NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cash_registers_site_name (site_id, name),
    CONSTRAINT fk_cr_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT chk_cr_balance CHECK (balance >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payment_requests
    ADD CONSTRAINT fk_pay_register FOREIGN KEY (cash_register_id) REFERENCES cash_registers (id);

-- Écritures de caisse : jamais modifiées ni supprimées ; une erreur se corrige par une écriture inverse.
CREATE TABLE cash_entries (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    register_id         INT UNSIGNED NOT NULL,
    entry_date          DATE         NOT NULL,
    reference           VARCHAR(20)  NOT NULL,
    direction           ENUM('in','out') NOT NULL,
    amount              DECIMAL(16,2) NOT NULL,
    balance_after       DECIMAL(16,2) NOT NULL,
    label               VARCHAR(255) NOT NULL,
    payment_request_id  INT UNSIGNED NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cash_entries_reference (reference),
    KEY idx_ce_register_date (register_id, entry_date, id),
    CONSTRAINT fk_ce_register FOREIGN KEY (register_id) REFERENCES cash_registers (id),
    CONSTRAINT fk_ce_payment FOREIGN KEY (payment_request_id) REFERENCES payment_requests (id),
    CONSTRAINT fk_ce_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_ce_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Arrêté quotidien : soldes d'ouverture et de clôture, comptage physique, écart. Un jour arrêté est verrouillé.
CREATE TABLE cash_closings (
    register_id      INT UNSIGNED NOT NULL,
    closing_date     DATE         NOT NULL,
    opening_balance  DECIMAL(16,2) NOT NULL,
    total_in         DECIMAL(16,2) NOT NULL,
    total_out        DECIMAL(16,2) NOT NULL,
    closing_balance  DECIMAL(16,2) NOT NULL,
    counted_balance  DECIMAL(16,2) NOT NULL,
    gap              DECIMAL(16,2) NOT NULL,
    gap_note         VARCHAR(255) NULL,
    closed_by        INT UNSIGNED NULL,
    closed_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (register_id, closing_date),
    CONSTRAINT fk_cc_register FOREIGN KEY (register_id) REFERENCES cash_registers (id),
    CONSTRAINT fk_cc_user FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Circuit d'un décaissement : vérification des pièces et de la disponibilité (Finance), puis approbation
-- (Direction générale ou Direction Logistique, selon les délégations).
INSERT INTO approval_flows (code, name) VALUES ('payment_request', 'Demande de décaissement');

INSERT INTO approval_flow_steps (flow_id, position, code, name, kind, role, is_blocking)
SELECT id, 10, 'verification', 'Vérification des pièces et de la disponibilité', 'control', 'finance', 1 FROM approval_flows WHERE code = 'payment_request';

INSERT INTO approval_flow_steps (flow_id, position, code, name, kind, role, is_blocking)
SELECT id, 20, 'approval', 'Approbation du décaissement', 'authorization', 'director,logistics', 1 FROM approval_flows WHERE code = 'payment_request';
