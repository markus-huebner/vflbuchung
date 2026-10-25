ALTER TABLE users
    ADD COLUMN first_name VARCHAR(120) NULL AFTER email,
    ADD COLUMN last_name VARCHAR(120) NOT NULL DEFAULT '' AFTER first_name;

UPDATE users
SET first_name = SUBSTRING_INDEX(TRIM(display_name), ' ', 1),
    last_name = CASE
        WHEN LOCATE(' ', TRIM(display_name)) > 0
            THEN TRIM(SUBSTRING(TRIM(display_name), LOCATE(' ', TRIM(display_name)) + 1))
        ELSE ''
    END;

ALTER TABLE users
    MODIFY first_name VARCHAR(120) NOT NULL,
    DROP COLUMN display_name;
