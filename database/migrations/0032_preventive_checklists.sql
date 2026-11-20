-- Point 3.2 du cahier des charges : liste d'opérations des plans préventifs, copiée dans chaque OT généré
-- et validée ligne par ligne par le technicien (OK, anomalie avec observation, sans objet).
ALTER TABLE preventive_plans ADD COLUMN checklist TEXT NULL AFTER instructions;

CREATE TABLE work_order_checks (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_order_id  INT UNSIGNED NOT NULL,
    position       SMALLINT UNSIGNED NOT NULL,
    label          VARCHAR(255) NOT NULL,
    status         ENUM('todo','ok','nok','na') NOT NULL DEFAULT 'todo',
    note           VARCHAR(255) NULL,
    checked_by     INT UNSIGNED NULL,
    checked_at     DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_wochk_wo (work_order_id, position),
    CONSTRAINT fk_wochk_wo FOREIGN KEY (work_order_id) REFERENCES work_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_wochk_user FOREIGN KEY (checked_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
