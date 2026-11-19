-- Habilitations par rôle, fonction et site (cahier des charges § 2.2).
-- users.role reste le profil principal (page d'accueil, périmètre user_sites) ; user_roles ajoute des fonctions
-- supplémentaires, chacune limitée à un site (site_id NULL = tous les sites).
ALTER TABLE users
    MODIFY role ENUM('admin','manager','technician','viewer','encoder',
                     'requester','supervisor','logistics','storekeeper','buyer','cashier','finance') NOT NULL DEFAULT 'viewer';

CREATE TABLE user_roles (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    role        VARCHAR(30)  NOT NULL,
    site_id     INT UNSIGNED NULL,
    granted_by  INT UNSIGNED NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_roles_user (user_id),
    KEY idx_user_roles_role_site (role, site_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_granted_by FOREIGN KEY (granted_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Délégation : le délégant confie une de ses fonctions au délégataire, sur une période, avec un motif.
-- Jamais supprimée : une délégation se révoque (trace conservée).
CREATE TABLE delegations (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    from_user_id  INT UNSIGNED NOT NULL,
    to_user_id    INT UNSIGNED NOT NULL,
    role          VARCHAR(30)  NOT NULL,
    site_id       INT UNSIGNED NULL,
    starts_on     DATE         NOT NULL,
    ends_on       DATE         NOT NULL,
    reason        VARCHAR(255) NOT NULL,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at    DATETIME     NULL,
    revoked_by    INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_delegations_to (to_user_id, starts_on, ends_on),
    KEY idx_delegations_from (from_user_id),
    CONSTRAINT fk_delegations_from FOREIGN KEY (from_user_id) REFERENCES users (id),
    CONSTRAINT fk_delegations_to FOREIGN KEY (to_user_id) REFERENCES users (id),
    CONSTRAINT fk_delegations_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_delegations_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_delegations_revoked_by FOREIGN KEY (revoked_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_delegations_period CHECK (ends_on >= starts_on),
    CONSTRAINT chk_delegations_distinct CHECK (from_user_id <> to_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
