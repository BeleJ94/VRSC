-- Point 3.2 / exploitation : journal des tâches planifiées (OT préventifs, alertes e-mail, sauvegarde),
-- pour signaler une tâche en retard ou en échec dans l'application.
CREATE TABLE scheduled_runs (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task         VARCHAR(40)  NOT NULL,
    origin       ENUM('scheduled','manual') NOT NULL DEFAULT 'scheduled',
    status       ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
    message      VARCHAR(500) NULL,
    started_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at  DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_sr_task (task, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
