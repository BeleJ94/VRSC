-- Lot 2 VRSC : stocks multisites (cahier des charges § 5).
-- Le stock d'un site est celui de son magasin central. Toute écriture est datée, justifiée et imputée ;
-- aucune correction ne supprime l'historique (un écart se régularise par un nouveau mouvement approuvé).

-- Grand livre des mouvements : quantité signée, date comptable, valorisation, bon, bénéficiaire.
ALTER TABLE stock_movements
    MODIFY type ENUM('in','out','adjust','opening','receipt','return','transfer_out','transfer_in','inventory') NOT NULL,
    ADD COLUMN delta          DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER quantity,
    ADD COLUMN moved_on       DATE          NULL AFTER stock_after,
    ADD COLUMN unit_cost      DECIMAL(14,4) NULL AFTER moved_on,
    ADD COLUMN value          DECIMAL(16,2) NULL AFTER unit_cost,
    ADD COLUMN document_ref   VARCHAR(40)   NULL AFTER value,
    ADD COLUMN project_id     INT UNSIGNED  NULL AFTER equipment_id,
    ADD COLUMN department_id  INT UNSIGNED  NULL AFTER project_id,
    ADD COLUMN supplier_id    INT UNSIGNED  NULL AFTER department_id,
    ADD COLUMN transfer_id    INT UNSIGNED  NULL AFTER supplier_id,
    ADD COLUMN inventory_id   INT UNSIGNED  NULL AFTER transfer_id,
    ADD KEY idx_sm_site_date (site_id, moved_on),
    ADD KEY idx_sm_transfer (transfer_id),
    ADD KEY idx_sm_inventory (inventory_id),
    ADD CONSTRAINT fk_sm_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_sm_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_sm_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL;

UPDATE stock_movements
   SET delta = CASE WHEN type = 'out' THEN -quantity ELSE quantity END,
       moved_on = DATE(created_at);

ALTER TABLE stock_movements MODIFY moved_on DATE NOT NULL;

-- Coût moyen pondéré par article et par magasin ; quantités réservées (stock disponible = stock - réservé).
ALTER TABLE part_stocks
    ADD COLUMN avg_cost  DECIMAL(14,4) NULL AFTER quantity,
    ADD COLUMN reserved  DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER avg_cost,
    ADD CONSTRAINT chk_ps_reserved CHECK (reserved >= 0);

-- Transfert intermagasins : demande → autorisation → bon numéroté → sortie (transit) → réception contradictoire.
CREATE TABLE stock_transfers (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference           VARCHAR(20)  NOT NULL,
    from_site_id        INT UNSIGNED NOT NULL,
    to_site_id          INT UNSIGNED NOT NULL,
    status              ENUM('requested','authorized','rejected','shipped','received','cancelled') NOT NULL DEFAULT 'requested',
    reason              VARCHAR(255) NOT NULL,
    carrier_id          INT UNSIGNED NULL,
    requested_by        INT UNSIGNED NULL,
    requested_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    authorized_at       DATETIME     NULL,
    shipped_by          INT UNSIGNED NULL,
    shipped_at          DATETIME     NULL,
    received_by         INT UNSIGNED NULL,
    received_at         DATETIME     NULL,
    has_discrepancy     TINYINT(1)   NOT NULL DEFAULT 0,
    discrepancy_note    VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stock_transfers_reference (reference),
    KEY idx_st_from (from_site_id, status),
    KEY idx_st_to (to_site_id, status),
    CONSTRAINT fk_st_from FOREIGN KEY (from_site_id) REFERENCES sites (id),
    CONSTRAINT fk_st_to FOREIGN KEY (to_site_id) REFERENCES sites (id),
    CONSTRAINT fk_st_carrier FOREIGN KEY (carrier_id) REFERENCES carriers (id) ON DELETE SET NULL,
    CONSTRAINT fk_st_requested_by FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_st_shipped_by FOREIGN KEY (shipped_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_st_received_by FOREIGN KEY (received_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_st_sites CHECK (from_site_id <> to_site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_transfer_lines (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    transfer_id    INT UNSIGNED NOT NULL,
    part_id        INT UNSIGNED NOT NULL,
    qty_requested  DECIMAL(10,2) NOT NULL,
    qty_shipped    DECIMAL(10,2) NULL,
    qty_received   DECIMAL(10,2) NULL,
    unit_cost      DECIMAL(14,4) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stl_transfer_part (transfer_id, part_id),
    CONSTRAINT fk_stl_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers (id) ON DELETE CASCADE,
    CONSTRAINT fk_stl_part FOREIGN KEY (part_id) REFERENCES parts (id),
    CONSTRAINT chk_stl_requested CHECK (qty_requested > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventaire physique : le stock constaté ne modifie le stock théorique qu'après approbation des écarts.
CREATE TABLE stock_inventories (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference     VARCHAR(20)  NOT NULL,
    site_id       INT UNSIGNED NOT NULL,
    status        ENUM('draft','submitted','approved','rejected','cancelled') NOT NULL DEFAULT 'draft',
    counted_on    DATE         NOT NULL,
    note          VARCHAR(255) NULL,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at  DATETIME     NULL,
    closed_at     DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stock_inventories_reference (reference),
    KEY idx_si_site (site_id, status),
    CONSTRAINT fk_si_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_si_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_inventory_lines (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    inventory_id     INT UNSIGNED NOT NULL,
    part_id          INT UNSIGNED NOT NULL,
    qty_theoretical  DECIMAL(10,2) NOT NULL,
    qty_counted      DECIMAL(10,2) NULL,
    unit_cost        DECIMAL(14,4) NULL,
    justification    VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sil_inventory_part (inventory_id, part_id),
    CONSTRAINT fk_sil_inventory FOREIGN KEY (inventory_id) REFERENCES stock_inventories (id) ON DELETE CASCADE,
    CONSTRAINT fk_sil_part FOREIGN KEY (part_id) REFERENCES parts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Réservations : quantité retenue pour un besoin identifié (transfert autorisé, EDB…).
CREATE TABLE stock_reservations (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    part_id      INT UNSIGNED NOT NULL,
    site_id      INT UNSIGNED NOT NULL,
    quantity     DECIMAL(10,2) NOT NULL,
    ref_type     VARCHAR(40)  NOT NULL,
    ref_id       INT UNSIGNED NOT NULL,
    status       ENUM('active','consumed','released') NOT NULL DEFAULT 'active',
    created_by   INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at    DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_sr_ref (ref_type, ref_id, status),
    KEY idx_sr_stock (part_id, site_id, status),
    CONSTRAINT fk_sr_part FOREIGN KEY (part_id) REFERENCES parts (id),
    CONSTRAINT fk_sr_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_sr_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_sr_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Périodes mensuelles clôturées par magasin : plus aucune écriture datée dans une période close.
CREATE TABLE stock_periods (
    site_id    INT UNSIGNED NOT NULL,
    period     CHAR(7)      NOT NULL,
    closed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_by  INT UNSIGNED NULL,
    PRIMARY KEY (site_id, period),
    CONSTRAINT fk_sp_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_sp_user FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Soldes figés à la clôture (état mensuel reproductible à l'identique).
CREATE TABLE stock_period_balances (
    site_id      INT UNSIGNED NOT NULL,
    period       CHAR(7)      NOT NULL,
    part_id      INT UNSIGNED NOT NULL,
    qty_open     DECIMAL(12,2) NOT NULL,
    qty_in       DECIMAL(12,2) NOT NULL,
    qty_out      DECIMAL(12,2) NOT NULL,
    qty_close    DECIMAL(12,2) NOT NULL,
    unit_cost    DECIMAL(14,4) NULL,
    value_close  DECIMAL(16,2) NULL,
    PRIMARY KEY (site_id, period, part_id),
    CONSTRAINT fk_spb_period FOREIGN KEY (site_id, period) REFERENCES stock_periods (site_id, period) ON DELETE CASCADE,
    CONSTRAINT fk_spb_part FOREIGN KEY (part_id) REFERENCES parts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Circuits d'approbation du lot : autorisation Logistique pour un transfert et pour les écarts d'inventaire.
INSERT INTO approval_flows (code, name) VALUES
    ('stock_transfer', 'Transfert intermagasins'),
    ('stock_inventory', 'Écarts d''inventaire');

INSERT INTO approval_flow_steps (flow_id, position, code, name, kind, role, is_blocking)
SELECT id, 10, 'authorization', 'Autorisation Direction Logistique', 'authorization', 'logistics', 1 FROM approval_flows WHERE code = 'stock_transfer';

INSERT INTO approval_flow_steps (flow_id, position, code, name, kind, role, is_blocking)
SELECT id, 10, 'approval', 'Approbation des écarts', 'authorization', 'logistics', 1 FROM approval_flows WHERE code = 'stock_inventory';
