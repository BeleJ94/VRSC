-- Lot 7 VRSC : compléments de la flotte.
-- Fiche équipement complète (année, puissance, couleur, acquisition, propriétaire, capacité, projet),
-- « non applicable » distinct de « information manquante », statuts « hors service » et « remise en service à valider »,
-- documents « en renouvellement » avec responsable, documents non applicables, validation de remise en service.

ALTER TABLE equipment
    ADD COLUMN year_made      SMALLINT UNSIGNED NULL AFTER model,
    ADD COLUMN power_value    DECIMAL(8,1) NULL AFTER year_made,
    ADD COLUMN power_unit     ENUM('hp','kw','kva') NULL AFTER power_value,
    ADD COLUMN color          VARCHAR(40) NULL AFTER power_unit,
    ADD COLUMN acquired_at    DATE NULL AFTER commissioned_at,
    ADD COLUMN owner_name     VARCHAR(120) NULL AFTER ownership,
    ADD COLUMN capacity_value DECIMAL(10,2) NULL AFTER color,
    ADD COLUMN capacity_unit  ENUM('t','kg','m3','l','seats') NULL AFTER capacity_value,
    ADD COLUMN project_id     INT UNSIGNED NULL AFTER site_id,
    ADD COLUMN not_applicable VARCHAR(255) NULL AFTER notes,
    MODIFY status ENUM('available','breakdown','maintenance','waiting_part','immobilized','pending_release','out_of_service') NOT NULL DEFAULT 'available',
    ADD KEY idx_equipment_project (project_id),
    ADD CONSTRAINT fk_equipment_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL;

ALTER TABLE equipment_status_history
    MODIFY from_status ENUM('available','breakdown','maintenance','waiting_part','immobilized','pending_release','out_of_service') NULL,
    MODIFY to_status   ENUM('available','breakdown','maintenance','waiting_part','immobilized','pending_release','out_of_service') NOT NULL;

-- Document en cours de renouvellement : qui s'en occupe, depuis quand.
ALTER TABLE equipment_documents
    ADD COLUMN responsible_id     INT UNSIGNED NULL AFTER note,
    ADD COLUMN renewal_started_at DATE NULL AFTER responsible_id,
    ADD COLUMN renewal_note       VARCHAR(255) NULL AFTER renewal_started_at,
    ADD CONSTRAINT fk_ed_responsible FOREIGN KEY (responsible_id) REFERENCES users (id) ON DELETE SET NULL;

-- Type de document déclaré « non applicable » à un engin (motif obligatoire) : distinct de « non renseigné ».
CREATE TABLE equipment_document_exemptions (
    equipment_id     INT UNSIGNED NOT NULL,
    document_type_id INT UNSIGNED NOT NULL,
    reason           VARCHAR(255) NOT NULL,
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (equipment_id, document_type_id),
    CONSTRAINT fk_ede_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE CASCADE,
    CONSTRAINT fk_ede_type FOREIGN KEY (document_type_id) REFERENCES document_types (id) ON DELETE CASCADE,
    CONSTRAINT fk_ede_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Demande de remise en service : l'engin ne redevient « disponible » qu'après validation par une autre personne.
CREATE TABLE equipment_releases (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference     VARCHAR(20)  NOT NULL,
    equipment_id  INT UNSIGNED NOT NULL,
    site_id       INT UNSIGNED NOT NULL,
    work_order_id INT UNSIGNED NULL,
    status        ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    reason        VARCHAR(255) NULL,
    requested_by  INT UNSIGNED NULL,
    requested_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_equipment_releases_reference (reference),
    KEY idx_er_equipment (equipment_id, status),
    CONSTRAINT fk_er_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE CASCADE,
    CONSTRAINT fk_er_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_er_wo FOREIGN KEY (work_order_id) REFERENCES work_orders (id) ON DELETE SET NULL,
    CONSTRAINT fk_er_user FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO approval_flows (code, name) VALUES ('equipment_release', 'Remise en service d''un engin');

INSERT INTO approval_flow_steps (flow_id, position, code, name, kind, role, is_blocking)
SELECT id, 10, 'release', 'Validation de remise en service', 'authorization', 'manager,supervisor,logistics', 1 FROM approval_flows WHERE code = 'equipment_release';
