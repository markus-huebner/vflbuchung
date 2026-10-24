UPDATE users
SET username = CONCAT('legacy_', id, '_', REPLACE(UUID(), '-', ''))
WHERE username IS NULL OR username = '';

ALTER TABLE users
    MODIFY username VARCHAR(80) NOT NULL;
