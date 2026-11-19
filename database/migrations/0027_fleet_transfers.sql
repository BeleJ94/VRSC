-- Lot 4 VRSC : transferts de flotte entre sites (cahier des charges § 4, recette R3 et R4).
-- Demande motivée → avis hiérarchique → contrôle technique (check-list) → autorisation Direction Logistique (OTV)
-- → remise au conducteur et départ confirmé → réception contradictoire → changement d'affectation.

-- Affectation d'un engin à un site dans le temps : l'historique reste lié à l'identifiant permanent de l'engin.
CREATE TABLE equipment_site_history (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    equipment_id  INT UNSIGNED NOT NULL,
    site_id       INT UNSIGNED NOT NULL,
    started_at    DATETIME     NOT NULL,
    ended_at      DATETIME     NULL,
    transfer_id   INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_esh_site_equipment (equipment_id, started_at),
    KEY idx_esh_site (site_id, started_at),
    CONSTRAINT fk_eqsh_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE CASCADE,
    CONSTRAINT fk_eqsh_site FOREIGN KEY (site_id) REFERENCES sites (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO equipment_site_history (equipment_id, site_id, started_at)
SELECT e.id, e.site_id, e.created_at FROM equipment e;

-- Ordre de transfert intersites (OTV) : numéro unique, jamais réutilisé.
CREATE TABLE fleet_transfers (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference           VARCHAR(20)  NOT NULL,
    equipment_id        INT UNSIGNED NOT NULL,
    from_site_id        INT UNSIGNED NOT NULL,
    to_site_id          INT UNSIGNED NOT NULL,
    status              ENUM('requested','authorized','rejected','departed','received','cancelled') NOT NULL DEFAULT 'requested',
    reason              VARCHAR(500) NOT NULL,
    planned_departure   DATETIME     NULL,
    planned_arrival     DATETIME     NULL,
    transport_mode      ENUM('own_power','low_loader') NOT NULL DEFAULT 'own_power',
    carrier_id          INT UNSIGNED NULL,
    tractor_plate       VARCHAR(30)  NULL,
    trailer_plate       VARCHAR(30)  NULL,
    transport_conditions VARCHAR(500) NULL,
    driver_id           INT UNSIGNED NULL,
    driver_name         VARCHAR(170) NULL,
    driver_phone        VARCHAR(30)  NULL,
    convoy_leader       VARCHAR(170) NULL,
    documents_refs      VARCHAR(1000) NULL,
    observations        VARCHAR(1000) NULL,
    requested_by        INT UNSIGNED NULL,
    requested_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    authorized_at       DATETIME     NULL,
    departed_at         DATETIME     NULL,
    departed_by         INT UNSIGNED NULL,
    departure_hours     INT UNSIGNED NULL,
    departure_km        INT UNSIGNED NULL,
    departure_fuel      TINYINT UNSIGNED NULL,
    handover_to         VARCHAR(170) NULL,
    received_at         DATETIME     NULL,
    received_by         INT UNSIGNED NULL,
    arrival_hours       INT UNSIGNED NULL,
    arrival_km          INT UNSIGNED NULL,
    arrival_fuel        TINYINT UNSIGNED NULL,
    has_discrepancy     TINYINT(1)   NOT NULL DEFAULT 0,
    arrival_note        VARCHAR(1000) NULL,
    cancel_reason       VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fleet_transfers_reference (reference),
    KEY idx_ft_equipment (equipment_id, status),
    KEY idx_ft_from (from_site_id, status),
    KEY idx_ft_to (to_site_id, status),
    CONSTRAINT fk_ft_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id),
    CONSTRAINT fk_ft_from FOREIGN KEY (from_site_id) REFERENCES sites (id),
    CONSTRAINT fk_ft_to FOREIGN KEY (to_site_id) REFERENCES sites (id),
    CONSTRAINT fk_ft_carrier FOREIGN KEY (carrier_id) REFERENCES carriers (id) ON DELETE SET NULL,
    CONSTRAINT fk_ft_driver FOREIGN KEY (driver_id) REFERENCES personnel (id) ON DELETE SET NULL,
    CONSTRAINT fk_ft_requested_by FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_ft_departed_by FOREIGN KEY (departed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_ft_received_by FOREIGN KEY (received_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_ft_sites CHECK (from_site_id <> to_site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE equipment_site_history
    ADD CONSTRAINT fk_eqsh_transfer FOREIGN KEY (transfer_id) REFERENCES fleet_transfers (id) ON DELETE SET NULL;

-- Contrôles techniques : check-list préalable (apte / apte sous conditions / inapte) et procès-verbal d'arrivée.
-- Chaque contrôle est conservé : un engin déclaré inapte ne part qu'après un NOUVEAU contrôle favorable.
CREATE TABLE fleet_transfer_checks (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    transfer_id   INT UNSIGNED NOT NULL,
    kind          ENUM('pre_departure','arrival') NOT NULL,
    conclusion    ENUM('fit','conditional','unfit') NULL,
    remarks       VARCHAR(1000) NULL,
    hour_meter    INT UNSIGNED NULL,
    odometer_km   INT UNSIGNED NULL,
    checked_by    INT UNSIGNED NULL,
    checker_name  VARCHAR(170) NOT NULL,
    checked_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ftc_transfer (transfer_id, kind, id),
    CONSTRAINT fk_ftc_transfer FOREIGN KEY (transfer_id) REFERENCES fleet_transfers (id) ON DELETE CASCADE,
    CONSTRAINT fk_ftc_user FOREIGN KEY (checked_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fleet_transfer_check_items (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    check_id  INT UNSIGNED NOT NULL,
    item      VARCHAR(30)  NOT NULL,
    result    ENUM('ok','reserve','nok','na') NOT NULL,
    note      VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ftci_check_item (check_id, item),
    CONSTRAINT fk_ftci_check FOREIGN KEY (check_id) REFERENCES fleet_transfer_checks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Circuit : l'avis hiérarchique et l'autorisation de la Direction Logistique sont deux actes distincts ;
-- le contrôle technique se valide par la check-list.
INSERT INTO approval_flows (code, name) VALUES ('fleet_transfer', 'Transfert d''engin entre sites');

INSERT INTO approval_flow_steps (flow_id, position, code, name, kind, role, is_blocking)
SELECT id, 10, 'opinion', 'Avis hiérarchique', 'opinion', 'supervisor', 0 FROM approval_flows WHERE code = 'fleet_transfer';

INSERT INTO approval_flow_steps (flow_id, position, code, name, kind, role, is_blocking)
SELECT id, 20, 'control', 'Contrôle technique maintenance', 'control', 'technician,manager', 1 FROM approval_flows WHERE code = 'fleet_transfer';

INSERT INTO approval_flow_steps (flow_id, position, code, name, kind, role, is_blocking)
SELECT id, 30, 'authorization', 'Autorisation Direction Logistique', 'authorization', 'logistics', 1 FROM approval_flows WHERE code = 'fleet_transfer';
