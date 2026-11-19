-- Lot 6 VRSC : carburant et coût mensuel par équipement (cahier des charges § 8, recette R8).
-- Coût mensuel = carburant valorisé + pièces consommées + main-d'œuvre + prestations externes + lubrifiants
-- + pneus / filtres + autres charges imputables, chaque coût compté une seule fois, depuis sa pièce source.
-- Une donnée absente est « manquante » (NULL), jamais remplacée par zéro.

-- Ravitaillement : depuis la cuve du site (sortie de stock valorisée) ou en station (prix saisi).
CREATE TABLE fuel_refills (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference          VARCHAR(20)  NOT NULL,
    equipment_id       INT UNSIGNED NOT NULL,
    site_id            INT UNSIGNED NOT NULL,
    refill_date        DATE         NOT NULL,
    hour_meter         INT UNSIGNED NULL,
    odometer_km        INT UNSIGNED NULL,
    litres             DECIMAL(10,2) NOT NULL,
    source             ENUM('stock','external') NOT NULL,
    part_id            INT UNSIGNED NULL,
    stock_movement_id  BIGINT UNSIGNED NULL,
    station            VARCHAR(120) NULL,
    unit_price         DECIMAL(14,4) NULL,
    voucher_ref        VARCHAR(60)  NULL,
    meter_alert        VARCHAR(255) NULL,
    note               VARCHAR(255) NULL,
    recorded_by        INT UNSIGNED NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fuel_refills_reference (reference),
    KEY idx_fr_equipment_date (equipment_id, refill_date),
    KEY idx_fr_site_date (site_id, refill_date),
    CONSTRAINT fk_fr_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id),
    CONSTRAINT fk_fr_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_fr_part FOREIGN KEY (part_id) REFERENCES parts (id),
    CONSTRAINT fk_fr_movement FOREIGN KEY (stock_movement_id) REFERENCES stock_movements (id),
    CONSTRAINT fk_fr_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_fr_litres CHECK (litres > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Consommation de référence (L/100 km ou L/h selon le compteur de suivi) : par modèle, surchargeable par engin.
ALTER TABLE equipment_models ADD COLUMN fuel_reference DECIMAL(8,2) NULL AFTER tank_capacity_l;
ALTER TABLE equipment ADD COLUMN fuel_reference DECIMAL(8,2) NULL AFTER tank_capacity_l;

-- Taux horaire de la main-d'œuvre (inconnu = coût de main-d'œuvre « manquant »).
ALTER TABLE personnel ADD COLUMN hourly_rate DECIMAL(10,2) NULL AFTER license_number;

-- Prestations externes rattachées à un ordre de travail.
CREATE TABLE work_order_services (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_order_id  INT UNSIGNED NOT NULL,
    supplier_id    INT UNSIGNED NULL,
    description    VARCHAR(190) NOT NULL,
    service_date   DATE         NOT NULL,
    amount         DECIMAL(16,2) NULL,
    document_ref   VARCHAR(60)  NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wos_work_order (work_order_id),
    CONSTRAINT fk_wos_work_order FOREIGN KEY (work_order_id) REFERENCES work_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_wos_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL,
    CONSTRAINT fk_wos_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Autres charges imputables à un engin (assurance, taxe, location…), saisies avec leur référence.
CREATE TABLE equipment_charges (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    equipment_id  INT UNSIGNED NOT NULL,
    site_id       INT UNSIGNED NOT NULL,
    charge_date   DATE         NOT NULL,
    label         VARCHAR(190) NOT NULL,
    amount        DECIMAL(16,2) NOT NULL,
    document_ref  VARCHAR(60)  NULL,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ec_equipment_date (equipment_id, charge_date),
    CONSTRAINT fk_ec_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id),
    CONSTRAINT fk_ec_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_ec_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_ec_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Validation mensuelle des coûts d'un site : la période est close, ses chiffres sont figés.
CREATE TABLE equipment_cost_periods (
    site_id    INT UNSIGNED NOT NULL,
    period     CHAR(7)      NOT NULL,
    note       VARCHAR(500) NULL,
    closed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_by  INT UNSIGNED NULL,
    PRIMARY KEY (site_id, period),
    CONSTRAINT fk_ecp_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_ecp_user FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rapport figé d'un engin pour une période close (les montants NULL restent « manquants »).
CREATE TABLE equipment_cost_snapshots (
    site_id         INT UNSIGNED NOT NULL,
    period          CHAR(7)      NOT NULL,
    equipment_id    INT UNSIGNED NOT NULL,
    fuel            DECIMAL(16,2) NOT NULL DEFAULT 0,
    parts           DECIMAL(16,2) NOT NULL DEFAULT 0,
    lubricants      DECIMAL(16,2) NOT NULL DEFAULT 0,
    tyres_filters   DECIMAL(16,2) NOT NULL DEFAULT 0,
    labor           DECIMAL(16,2) NOT NULL DEFAULT 0,
    external        DECIMAL(16,2) NOT NULL DEFAULT 0,
    other           DECIMAL(16,2) NOT NULL DEFAULT 0,
    total           DECIMAL(16,2) NOT NULL DEFAULT 0,
    missing_count   INT UNSIGNED  NOT NULL DEFAULT 0,
    litres          DECIMAL(12,2) NOT NULL DEFAULT 0,
    hours_start     INT UNSIGNED  NULL,
    hours_end       INT UNSIGNED  NULL,
    km_start        INT UNSIGNED  NULL,
    km_end          INT UNSIGNED  NULL,
    PRIMARY KEY (site_id, period, equipment_id),
    KEY idx_ecs_equipment (equipment_id, period),
    CONSTRAINT fk_ecs_period FOREIGN KEY (site_id, period) REFERENCES equipment_cost_periods (site_id, period) ON DELETE CASCADE,
    CONSTRAINT fk_ecs_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Observations du rapport mensuel d'un engin.
CREATE TABLE equipment_cost_notes (
    equipment_id  INT UNSIGNED NOT NULL,
    period        CHAR(7)      NOT NULL,
    note          VARCHAR(500) NOT NULL,
    updated_by    INT UNSIGNED NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (equipment_id, period),
    CONSTRAINT fk_ecn_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE CASCADE,
    CONSTRAINT fk_ecn_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
