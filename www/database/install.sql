CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NULL,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL DEFAULT '',
    username VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NULL,
    role ENUM('admin', 'manager', 'trainer') NOT NULL,
    status ENUM('invited', 'active', 'inactive') NOT NULL DEFAULT 'invited',
    invite_token_hash CHAR(64) NULL,
    invite_expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_invite_token (invite_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS booking_access_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    target_occurrence_id BIGINT UNSIGNED NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_access_tokens_hash (token_hash),
    KEY idx_booking_access_tokens_user (user_id),
    KEY idx_booking_access_tokens_expiry (expires_at),
    CONSTRAINT fk_booking_access_tokens_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_key CHAR(64) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_key_time (attempt_key, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS resource_categories (
    id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(32) NOT NULL,
    name VARCHAR(80) NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_resource_categories_code (code),
    UNIQUE KEY uq_resource_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO resource_categories (code, name, sort_order) VALUES
    ('pitch', 'Platz', 1),
    ('bus', 'Fahrzeug', 2),
    ('cabin', 'Kabine', 3)
ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order);

CREATE TABLE IF NOT EXISTS resources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id TINYINT UNSIGNED NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    name VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_resources_category_name (category_id, name),
    KEY idx_resources_active_category (is_active, category_id),
    CONSTRAINT fk_resources_category FOREIGN KEY (category_id)
        REFERENCES resource_categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS booking_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_by BIGINT UNSIGNED NULL,
    requested_by BIGINT UNSIGNED NULL,
    recurrence_rule JSON NULL,
    recurrence_until DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_booking_groups_created_by (created_by),
    KEY idx_booking_groups_requested_by (requested_by),
    CONSTRAINT fk_booking_groups_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_booking_groups_requested_by FOREIGN KEY (requested_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS booking_types (
    code VARCHAR(40) NOT NULL,
    name VARCHAR(80) NOT NULL,
    color VARCHAR(20) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    trainer_requestable TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (code),
    KEY idx_booking_types_order (is_active, sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO booking_types (code, name, color, sort_order, is_active, trainer_requestable)
SELECT default_types.code, default_types.name, default_types.color, default_types.sort_order, 1,
    default_types.trainer_requestable
FROM (
    SELECT 'training' AS code, 'Training' AS name, 'green-medium' AS color, 10 AS sort_order, 1 AS trainer_requestable
    UNION ALL SELECT 'match', 'Spiel', 'blue-medium', 20, 1
    UNION ALL SELECT 'external', 'Extern', 'orange-medium', 30, 0
) AS default_types
WHERE NOT EXISTS (SELECT 1 FROM booking_types)
ON DUPLICATE KEY UPDATE code = VALUES(code);

CREATE TABLE IF NOT EXISTS booking_occurrences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id BIGINT UNSIGNED NOT NULL,
    occurrence_no INT UNSIGNED NOT NULL DEFAULT 0,
    event_type VARCHAR(40) NOT NULL,
    title VARCHAR(180) NOT NULL,
    team_name VARCHAR(120) NULL,
    destination VARCHAR(180) NULL,
    purpose VARCHAR(180) NULL,
    driver_name VARCHAR(120) NULL,
    private_notes TEXT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status ENUM('pending', 'confirmed', 'rejected', 'withdrawn') NOT NULL,
    rejection_reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_occurrence_number (group_id, occurrence_no),
    KEY idx_booking_occurrences_time (starts_at, ends_at),
    KEY idx_booking_occurrences_group (group_id, status),
    KEY idx_booking_occurrences_type (event_type),
    CONSTRAINT fk_booking_occurrences_group FOREIGN KEY (group_id)
        REFERENCES booking_groups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE booking_occurrences MODIFY event_type VARCHAR(40) NOT NULL;

CREATE TABLE IF NOT EXISTS booking_resource_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    occurrence_id BIGINT UNSIGNED NOT NULL,
    resource_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending', 'confirmed', 'rejected', 'withdrawn', 'skipped') NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_occurrence_resource (occurrence_id, resource_id),
    KEY idx_resource_assignments_conflict (resource_id, status, occurrence_id),
    CONSTRAINT fk_resource_assignments_occurrence FOREIGN KEY (occurrence_id)
        REFERENCES booking_occurrences (id) ON DELETE CASCADE,
    CONSTRAINT fk_resource_assignments_resource FOREIGN KEY (resource_id)
        REFERENCES resources (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS booking_cabin_assignments (
    occurrence_id BIGINT UNSIGNED NOT NULL,
    cabin_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (occurrence_id, cabin_id),
    KEY idx_booking_cabins_cabin (cabin_id),
    CONSTRAINT fk_booking_cabins_occurrence FOREIGN KEY (occurrence_id)
        REFERENCES booking_occurrences (id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_cabins_cabin FOREIGN KEY (cabin_id)
        REFERENCES resources (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS booking_overrides (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id BIGINT UNSIGNED NULL,
    new_assignment_id BIGINT UNSIGNED NOT NULL,
    conflicting_assignment_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_booking_overrides_actor (actor_id, created_at),
    CONSTRAINT fk_booking_overrides_actor FOREIGN KEY (actor_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(80) NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO app_settings (setting_key, setting_value)
VALUES ('initial_admin_created', '0')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);
