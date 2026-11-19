-- Moteurs transversaux (cahier des charges § 2.2 et § 9) : numérotation, circuit d'approbation, pièces jointes.

-- Numérotation : un compteur par type de document et par année ; un numéro émis n'est jamais réattribué.
CREATE TABLE document_sequences (
    doc_type     VARCHAR(20)       NOT NULL,
    year         SMALLINT UNSIGNED NOT NULL,
    last_number  INT UNSIGNED      NOT NULL DEFAULT 0,
    PRIMARY KEY (doc_type, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Circuit d'approbation : suite ordonnée d'étapes, chacune confiée à un rôle.
CREATE TABLE approval_flows (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(40)  NOT NULL,
    name        VARCHAR(120) NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_approval_flows_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- kind : opinion = avis (consultatif sauf is_blocking), control = contrôle, authorization = autorisation.
-- min_amount : l'étape ne s'applique qu'à partir de ce montant (seuils de délégation).
CREATE TABLE approval_flow_steps (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    flow_id      INT UNSIGNED NOT NULL,
    position     SMALLINT UNSIGNED NOT NULL,
    code         VARCHAR(40)  NOT NULL,
    name         VARCHAR(120) NOT NULL,
    kind         ENUM('opinion','control','authorization') NOT NULL DEFAULT 'authorization',
    role         VARCHAR(30)  NOT NULL,
    is_blocking  TINYINT(1)   NOT NULL DEFAULT 1,
    min_amount   DECIMAL(14,2) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_afs_flow_position (flow_id, position),
    UNIQUE KEY uq_afs_flow_code (flow_id, code),
    CONSTRAINT fk_afs_flow FOREIGN KEY (flow_id) REFERENCES approval_flows (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE approval_requests (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    flow_id          INT UNSIGNED NOT NULL,
    entity_type      VARCHAR(40)  NOT NULL,
    entity_id        BIGINT UNSIGNED NOT NULL,
    site_id          INT UNSIGNED NOT NULL,
    reference        VARCHAR(40)  NULL,
    amount           DECIMAL(14,2) NULL,
    status           ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    current_step_id  INT UNSIGNED NULL,
    requested_by     INT UNSIGNED NULL,
    requested_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at        DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_ar_entity (entity_type, entity_id),
    KEY idx_ar_pending (status, site_id, current_step_id),
    CONSTRAINT fk_ar_flow FOREIGN KEY (flow_id) REFERENCES approval_flows (id),
    CONSTRAINT fk_ar_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_ar_step FOREIGN KEY (current_step_id) REFERENCES approval_flow_steps (id),
    CONSTRAINT fk_ar_user FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Actes : identifiés, datés, jamais modifiés. Le nom de l'acteur est figé au moment de l'acte.
CREATE TABLE approval_actions (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id       BIGINT UNSIGNED NOT NULL,
    step_id          INT UNSIGNED NOT NULL,
    step_code        VARCHAR(40)  NOT NULL,
    step_kind        ENUM('opinion','control','authorization') NOT NULL,
    decision         ENUM('favorable','unfavorable') NOT NULL,
    comment          VARCHAR(1000) NULL,
    acted_by         INT UNSIGNED NULL,
    actor_name       VARCHAR(170) NOT NULL,
    acted_role       VARCHAR(30)  NOT NULL,
    delegated_from   INT UNSIGNED NULL,
    is_override      TINYINT(1)   NOT NULL DEFAULT 0,
    override_reason  VARCHAR(500) NULL,
    acted_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_aa_request (request_id, id),
    CONSTRAINT fk_aa_request FOREIGN KEY (request_id) REFERENCES approval_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_aa_step FOREIGN KEY (step_id) REFERENCES approval_flow_steps (id),
    CONSTRAINT fk_aa_user FOREIGN KEY (acted_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_aa_delegated FOREIGN KEY (delegated_from) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pièces jointes génériques : rattachées à n'importe quelle transaction, versionnées, jamais écrasées.
CREATE TABLE attachments (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type    VARCHAR(40)  NOT NULL,
    entity_id      BIGINT UNSIGNED NOT NULL,
    site_id        INT UNSIGNED NULL,
    category       VARCHAR(40)  NOT NULL DEFAULT 'document',
    version        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_current     TINYINT(1)   NOT NULL DEFAULT 1,
    file_name      VARCHAR(80)  NOT NULL,
    original_name  VARCHAR(190) NOT NULL,
    mime           VARCHAR(60)  NOT NULL,
    size_bytes     INT UNSIGNED NOT NULL,
    sha256         CHAR(64)     NOT NULL,
    note           VARCHAR(255) NULL,
    uploaded_by    INT UNSIGNED NULL,
    uploaded_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_att_entity (entity_type, entity_id, category, is_current),
    CONSTRAINT fk_att_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_att_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal des imports de fichiers bureautiques (§ 2.1) : qui, quoi, combien de lignes, rapport détaillé.
CREATE TABLE import_logs (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    target        VARCHAR(40)  NOT NULL,
    file_name     VARCHAR(190) NULL,
    rows_total    INT UNSIGNED NOT NULL DEFAULT 0,
    rows_created  INT UNSIGNED NOT NULL DEFAULT 0,
    rows_skipped  INT UNSIGNED NOT NULL DEFAULT 0,
    rows_invalid  INT UNSIGNED NOT NULL DEFAULT 0,
    report        MEDIUMTEXT   NULL,
    imported_by   INT UNSIGNED NULL,
    imported_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_import_logs_target (target, imported_at),
    CONSTRAINT fk_import_logs_user FOREIGN KEY (imported_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
