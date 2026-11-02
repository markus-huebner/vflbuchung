-- Optional manual import into an initialized MySQL 8 database; never run by installation.
-- Snapshot: https://belegungsplan.vflkaufering.de/ retrieved 2026-11-02.
-- Published dated pitch entries, October 2026 through March 2027; no projections.
-- Times represent the visible 09:00-21:30 calendar at 30-minute resolution.
-- Details require login, so exact internal timestamps/recurrence rules are not asserted.
-- Source CSS and user-confirmed color meanings: A=training, B=match, C=external.
-- See docs/beispieldaten-quelle.md for source URLs, coverage and limitations.
-- Requires CREATE ROUTINE, ALTER ROUTINE and EXECUTE privileges.
-- Requires an empty booking inventory; repeated imports fail without changing bookings.
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS seed_example_bookings_202610_202703;
DELIMITER $$
CREATE PROCEDURE seed_example_bookings_202610_202703()
BEGIN
    DECLARE finished BOOLEAN DEFAULT FALSE;
    DECLARE locked INT DEFAULT 0;
    DECLARE actor_id BIGINT UNSIGNED;
    DECLARE group_id BIGINT UNSIGNED;
    DECLARE occurrence_id BIGINT UNSIGNED;
    DECLARE pitch_id BIGINT UNSIGNED;
    DECLARE booking_type VARCHAR(40);
    DECLARE booking_title VARCHAR(180);
    DECLARE local_start DATETIME;
    DECLARE local_end DATETIME;
    DECLARE utc_offset VARCHAR(6);
    DECLARE locked_resource_count INT DEFAULT 0;
    DECLARE planned_count INT DEFAULT 0;
    DECLARE inserted_count INT DEFAULT 0;
    DECLARE bookings CURSOR FOR
        SELECT CASE WHEN plan.title = 'TuL' THEN 'external'
                ELSE CASE plan.source_type WHEN 'A' THEN 'training' WHEN 'B' THEN 'match' WHEN 'C' THEN 'external' END
            END,
            plan.title, plan.local_start, plan.local_end, resource.id
        FROM example_booking_plan plan
        JOIN resources resource ON resource.name = plan.pitch_name AND resource.is_active = 1
        JOIN resource_categories category ON category.id = resource.category_id AND category.code = 'pitch'
        ORDER BY plan.local_start, plan.seed_marker;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET finished = TRUE;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        DROP TEMPORARY TABLE IF EXISTS example_booking_plan;
        IF locked = 1 THEN
            DO RELEASE_LOCK('vflbuchung-example-202610-202703');
        END IF;
        RESIGNAL;
    END;

    SET locked = GET_LOCK('vflbuchung-example-202610-202703', 10);
    IF locked IS NULL OR locked <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Example import is already running; retry later.';
    END IF;

    START TRANSACTION;
    SELECT COUNT(*) INTO locked_resource_count FROM resources FOR UPDATE;
    IF EXISTS (SELECT 1 FROM booking_occurrences) OR EXISTS (SELECT 1 FROM booking_groups) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Beispieldaten können nur bei leerem Buchungsbestand importiert werden.';
    END IF;
    SELECT MIN(id) INTO actor_id FROM users WHERE role = 'admin' AND status = 'active';
    IF actor_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Complete installation with an active administrator before importing examples.';
    END IF;
    IF (SELECT COUNT(*) FROM booking_types WHERE code IN ('training', 'match', 'external') AND is_active = 1) <> 3 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active booking types training, match and external are required.';
    END IF;

    CREATE TEMPORARY TABLE example_booking_plan (
        seed_marker VARCHAR(180) NOT NULL PRIMARY KEY,
        source_type ENUM('A', 'B', 'C') NOT NULL,
        title VARCHAR(180) NOT NULL,
        local_start DATETIME NOT NULL,
        local_end DATETIME NOT NULL,
        pitch_name VARCHAR(120) NOT NULL,
        source_series BOOLEAN NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

    -- BEGIN PUBLISHED SNAPSHOT
    INSERT INTO example_booking_plan VALUES
        ('vflbuchung-example-202610-202703:source-2026-10-01-r2-1020-1110-a', 'A', 'U15', '2026-10-01 17:00:00', '2026-10-01 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-01-r2-1110-1200-a', 'A', 'U17', '2026-10-01 18:30:00', '2026-10-01 20:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-03-r2-630-750-b', 'B', 'U12/1 ASV Dachau', '2026-10-03 10:30:00', '2026-10-03 12:30:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-05-r2-1020-1200-c', 'C', 'DFB Stützpunkt', '2026-10-05 17:00:00', '2026-10-05 20:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-06-r2-1020-1110-a', 'A', 'U15', '2026-10-06 17:00:00', '2026-10-06 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-06-r2-1110-1200-a', 'A', 'U19', '2026-10-06 18:30:00', '2026-10-06 20:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-08-r2-1020-1110-a', 'A', 'U15', '2026-10-08 17:00:00', '2026-10-08 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-08-r2-1110-1200-a', 'A', 'U17', '2026-10-08 18:30:00', '2026-10-08 20:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-10-r2-630-750-b', 'B', 'U13/1 TSV Grünwald', '2026-10-10 10:30:00', '2026-10-10 12:30:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-12-r2-1020-1200-c', 'C', 'DFB Stützpunkt', '2026-10-12 17:00:00', '2026-10-12 20:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-13-r2-1020-1110-a', 'A', 'U15', '2026-10-13 17:00:00', '2026-10-13 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-13-r2-1110-1200-a', 'A', 'U19', '2026-10-13 18:30:00', '2026-10-13 20:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-14-r2-1140-1260-b', 'B', 'U17 TSV Geiselbullach - Neu-Esting', '2026-10-14 19:00:00', '2026-10-14 21:00:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-15-r2-1020-1110-a', 'A', 'U15', '2026-10-15 17:00:00', '2026-10-15 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-15-r2-1110-1200-a', 'A', 'U17', '2026-10-15 18:30:00', '2026-10-15 20:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-16-r2-960-1110-a', 'A', 'U12/1 + U13/1', '2026-10-16 16:00:00', '2026-10-16 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-17-r2-630-750-b', 'B', 'U13/1 VfB Eichstätt', '2026-10-17 10:30:00', '2026-10-17 12:30:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-18-r2-630-750-b', 'B', 'U12/1 DJK Ingolstadt', '2026-10-18 10:30:00', '2026-10-18 12:30:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-19-r2-1020-1200-c', 'C', 'DFB Stützpunkt', '2026-10-19 17:00:00', '2026-10-19 20:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-20-r2-1020-1110-a', 'A', 'U15', '2026-10-20 17:00:00', '2026-10-20 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-20-r2-1110-1200-a', 'A', 'U19', '2026-10-20 18:30:00', '2026-10-20 20:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-22-r2-1020-1110-a', 'A', 'U15', '2026-10-22 17:00:00', '2026-10-22 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-22-r2-1110-1200-a', 'A', 'U17', '2026-10-22 18:30:00', '2026-10-22 20:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-24-r2-570-690-b', 'B', 'U10/2 SC Riederau', '2026-10-24 09:30:00', '2026-10-24 11:30:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-26-r2-1020-1200-c', 'C', 'DFB Stützpunkt', '2026-10-26 17:00:00', '2026-10-26 20:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-27-r2-1110-1200-a', 'A', 'U19', '2026-10-27 18:30:00', '2026-10-27 20:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-27-r2-1200-1260-a', 'A', 'Senioren 2 + 3', '2026-10-27 20:00:00', '2026-10-27 21:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-28-r2-1020-1110-a', 'A', 'U9', '2026-10-28 17:00:00', '2026-10-28 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-29-r2-1020-1110-a', 'A', 'U15', '2026-10-29 17:00:00', '2026-10-29 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-29-r2-1110-1170-a', 'A', 'U17', '2026-10-29 18:30:00', '2026-10-29 19:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-29-r2-1170-1260-a', 'A', 'Senioren 2', '2026-10-29 19:30:00', '2026-10-29 21:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-30-r2-1110-1230-b', 'B', 'U17 (SG) SV Althegnenberg', '2026-10-30 18:30:00', '2026-10-30 20:30:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-31-r2-720-840-b', 'B', 'Senioren 3 SG Hurlach/Obermeitingen', '2026-10-31 12:00:00', '2026-10-31 14:00:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-31-r2-960-1080-b', 'B', 'Senioren 2 TSV Haunstetten', '2026-10-31 16:00:00', '2026-10-31 18:00:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-01-r1-1020-1110-a', 'A', 'U15', '2026-10-01 17:00:00', '2026-10-01 18:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-02-r1-990-1080-a', 'A', 'U10', '2026-10-02 16:30:00', '2026-10-02 18:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-03-r1-630-750-b', 'B', 'U12/1 ASV Dachau', '2026-10-03 10:30:00', '2026-10-03 12:30:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-05-r1-1020-1200-c', 'C', 'DFB Stützpunkt', '2026-10-05 17:00:00', '2026-10-05 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-06-r1-1020-1110-a', 'A', 'U15', '2026-10-06 17:00:00', '2026-10-06 18:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-06-r1-1110-1200-a', 'A', 'U17', '2026-10-06 18:30:00', '2026-10-06 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-07-r1-1020-1140-a', 'A', 'U12/1', '2026-10-07 17:00:00', '2026-10-07 19:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-08-r1-1020-1110-a', 'A', 'U15', '2026-10-08 17:00:00', '2026-10-08 18:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-08-r1-1110-1200-a', 'A', 'U19', '2026-10-08 18:30:00', '2026-10-08 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-09-r1-990-1080-a', 'A', 'U10', '2026-10-09 16:30:00', '2026-10-09 18:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-10-r1-630-750-b', 'B', 'U13/1 TSV Grünwald', '2026-10-10 10:30:00', '2026-10-10 12:30:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-12-r1-1020-1200-c', 'C', 'DFB Stützpunkt', '2026-10-12 17:00:00', '2026-10-12 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-13-r1-1020-1110-a', 'A', 'U15', '2026-10-13 17:00:00', '2026-10-13 18:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-13-r1-1110-1200-a', 'A', 'U17', '2026-10-13 18:30:00', '2026-10-13 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-14-r1-1140-1260-b', 'B', 'U17 TSV Geiselbullach - Neu-Esting', '2026-10-14 19:00:00', '2026-10-14 21:00:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-15-r1-1020-1110-a', 'A', 'U15', '2026-10-15 17:00:00', '2026-10-15 18:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-15-r1-1110-1200-a', 'A', 'U19', '2026-10-15 18:30:00', '2026-10-15 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-16-r1-990-1080-a', 'A', 'U10', '2026-10-16 16:30:00', '2026-10-16 18:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-17-r1-630-750-b', 'B', 'U13/1 VfB Eichstätt', '2026-10-17 10:30:00', '2026-10-17 12:30:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-18-r1-630-750-b', 'B', 'U12/1 DJK Ingolstadt', '2026-10-18 10:30:00', '2026-10-18 12:30:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-19-r1-1020-1200-c', 'C', 'DFB Stützpunkt', '2026-10-19 17:00:00', '2026-10-19 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-20-r1-1020-1110-a', 'A', 'U15', '2026-10-20 17:00:00', '2026-10-20 18:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-20-r1-1110-1200-a', 'A', 'U17', '2026-10-20 18:30:00', '2026-10-20 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-21-r1-1020-1140-a', 'A', 'U12/1', '2026-10-21 17:00:00', '2026-10-21 19:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-22-r1-1020-1110-a', 'A', 'U15', '2026-10-22 17:00:00', '2026-10-22 18:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-22-r1-1110-1200-a', 'A', 'U19', '2026-10-22 18:30:00', '2026-10-22 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-23-r1-990-1080-a', 'A', 'U10', '2026-10-23 16:30:00', '2026-10-23 18:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-24-r1-570-690-b', 'B', 'U10/1 TSV Steingaden', '2026-10-24 09:30:00', '2026-10-24 11:30:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-26-r1-1020-1200-c', 'C', 'DFB Stützpunkt', '2026-10-26 17:00:00', '2026-10-26 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-27-r1-1110-1200-a', 'A', 'U17', '2026-10-27 18:30:00', '2026-10-27 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-27-r1-1200-1260-a', 'A', 'Senioren 2 + 3', '2026-10-27 20:00:00', '2026-10-27 21:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-28-r1-1020-1140-a', 'A', 'U12/1', '2026-10-28 17:00:00', '2026-10-28 19:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-29-r1-1020-1110-a', 'A', 'U11 Mädels', '2026-10-29 17:00:00', '2026-10-29 18:30:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-29-r1-1110-1200-a', 'A', 'U19', '2026-10-29 18:30:00', '2026-10-29 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-29-r1-1200-1260-a', 'A', 'Senioren 2', '2026-10-29 20:00:00', '2026-10-29 21:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-30-r1-990-1080-a', 'A', 'U10', '2026-10-30 16:30:00', '2026-10-30 18:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-30-r1-1110-1230-b', 'B', 'U17 (SG) SV Althegnenberg', '2026-10-30 18:30:00', '2026-10-30 20:30:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-31-r1-720-840-b', 'B', 'Senioren 3 SG Hurlach/Obermeitingen', '2026-10-31 12:00:00', '2026-10-31 14:00:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-31-r1-960-1080-b', 'B', 'Senioren 2 TSV Haunstetten', '2026-10-31 16:00:00', '2026-10-31 18:00:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-02-r3-1110-1230-b', 'B', 'Senioren 1  SV Egg a.d. Günz', '2026-10-02 18:30:00', '2026-10-02 20:30:00', 'Platz 2', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-03-r3-720-840-b', 'B', 'Senioren 3 TSV Königsbrunn', '2026-10-03 12:00:00', '2026-10-03 14:00:00', 'Platz 2', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-03-r3-840-960-b', 'B', 'Senioren 2 TSV Königsbrunn', '2026-10-03 14:00:00', '2026-10-03 16:00:00', 'Platz 2', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-09-r3-1050-1170-b', 'B', 'U15 JFG Grünbachtal', '2026-10-09 17:30:00', '2026-10-09 19:30:00', 'Platz 2', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-09-r3-1170-1290-b', 'B', 'U19 SG 5-Seen-Land', '2026-10-09 19:30:00', '2026-10-09 21:30:00', 'Platz 2', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-16-r3-1080-1200-b', 'B', 'Senioren 2 FSV Großaitingen', '2026-10-16 18:00:00', '2026-10-16 20:00:00', 'Platz 2', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-17-r3-630-750-b', 'B', 'U15 SV Polling', '2026-10-17 10:30:00', '2026-10-17 12:30:00', 'Platz 2', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-18-r3-630-780-b', 'B', 'U17 SG Nordkreis LL', '2026-10-18 10:30:00', '2026-10-18 13:00:00', 'Platz 2', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-23-r3-1140-1290-b', 'B', 'U19 TSV Gilching/Argelsried', '2026-10-23 19:00:00', '2026-10-23 21:30:00', 'Platz 2', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-27-r3-1080-1200-b', 'B', 'U15 SG Birkland/Reichling/Rott/Wessobrunn', '2026-10-27 18:00:00', '2026-10-27 20:00:00', 'Platz 2', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-31-r3-840-960-b', 'B', 'Senioren 1  TSV Legau', '2026-10-31 14:00:00', '2026-10-31 16:00:00', 'Platz 2', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-01-r4-1140-1230-a', 'A', 'Senioren 1', '2026-10-01 19:00:00', '2026-10-01 20:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-02-r4-990-1080-a', 'A', 'U7', '2026-10-02 16:30:00', '2026-10-02 18:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-05-r4-1050-1140-a', 'A', 'Förderkader', '2026-10-05 17:30:00', '2026-10-05 19:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-05-r4-1140-1260-a', 'A', 'Senioren 1', '2026-10-05 19:00:00', '2026-10-05 21:00:00', 'Platz 3', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-06-r4-990-1080-a', 'A', 'U10', '2026-10-06 16:30:00', '2026-10-06 18:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-07-r4-1020-1110-a', 'A', 'U9', '2026-10-07 17:00:00', '2026-10-07 18:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-07-r4-1140-1260-a', 'A', 'Senioren 1', '2026-10-07 19:00:00', '2026-10-07 21:00:00', 'Platz 3', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-09-r4-990-1080-a', 'A', 'U7', '2026-10-09 16:30:00', '2026-10-09 18:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-12-r4-1050-1140-a', 'A', 'Förderkader', '2026-10-12 17:30:00', '2026-10-12 19:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-12-r4-1170-1260-a', 'A', 'AH', '2026-10-12 19:30:00', '2026-10-12 21:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-13-r4-990-1080-a', 'A', 'U10', '2026-10-13 16:30:00', '2026-10-13 18:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-13-r4-1140-1230-a', 'A', 'Senioren 1', '2026-10-13 19:00:00', '2026-10-13 20:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-14-r4-1020-1110-a', 'A', 'U9', '2026-10-14 17:00:00', '2026-10-14 18:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-14-r4-1140-1230-a', 'A', 'Senioren 2', '2026-10-14 19:00:00', '2026-10-14 20:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-15-r4-1140-1230-a', 'A', 'Senioren 1', '2026-10-15 19:00:00', '2026-10-15 20:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-16-r4-990-1080-a', 'A', 'U7', '2026-10-16 16:30:00', '2026-10-16 18:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-19-r4-1050-1140-a', 'A', 'Förderkader', '2026-10-19 17:30:00', '2026-10-19 19:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-19-r4-1140-1230-a', 'A', 'Senioren 1', '2026-10-19 19:00:00', '2026-10-19 20:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-20-r4-990-1080-a', 'A', 'U10', '2026-10-20 16:30:00', '2026-10-20 18:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-21-r4-1020-1110-a', 'A', 'U9', '2026-10-21 17:00:00', '2026-10-21 18:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-21-r4-1140-1230-a', 'A', 'Senioren 1', '2026-10-21 19:00:00', '2026-10-21 20:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-23-r4-990-1080-a', 'A', 'U7', '2026-10-23 16:30:00', '2026-10-23 18:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-26-r4-1050-1140-a', 'A', 'Förderkader', '2026-10-26 17:30:00', '2026-10-26 19:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-26-r4-1170-1260-a', 'A', 'AH', '2026-10-26 19:30:00', '2026-10-26 21:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-27-r4-990-1080-a', 'A', 'U10', '2026-10-27 16:30:00', '2026-10-27 18:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-27-r4-1140-1230-a', 'A', 'Senioren 1', '2026-10-27 19:00:00', '2026-10-27 20:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-29-r4-1140-1230-a', 'A', 'Senioren 1', '2026-10-29 19:00:00', '2026-10-29 20:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-30-r4-990-1080-a', 'A', 'U7', '2026-10-30 16:30:00', '2026-10-30 18:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-01-r5-1050-1140-a', 'A', 'U13/2', '2026-10-01 17:30:00', '2026-10-01 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-01-r5-1170-1260-a', 'A', 'Senioren 2', '2026-10-01 19:30:00', '2026-10-01 21:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-02-r5-990-1080-a', 'A', 'U8 + U9', '2026-10-02 16:30:00', '2026-10-02 18:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-05-r5-1050-1110-a', 'A', 'U8', '2026-10-05 17:30:00', '2026-10-05 18:30:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-05-r5-1170-1260-a', 'A', 'AH', '2026-10-05 19:30:00', '2026-10-05 21:00:00', 'Platz 4 West', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-06-r5-1050-1140-a', 'A', 'U12/2 + U13/2', '2026-10-06 17:30:00', '2026-10-06 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-06-r5-1170-1260-a', 'A', 'Senioren 2 + 3', '2026-10-06 19:30:00', '2026-10-06 21:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-07-r5-1020-1140-a', 'A', 'TW Jugend', '2026-10-07 17:00:00', '2026-10-07 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-08-r5-1050-1140-a', 'A', 'U12/2', '2026-10-08 17:30:00', '2026-10-08 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-08-r5-1170-1260-a', 'A', 'Senioren 2', '2026-10-08 19:30:00', '2026-10-08 21:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-09-r5-990-1080-a', 'A', 'U8 + U9', '2026-10-09 16:30:00', '2026-10-09 18:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-11-r5-570-690-b', 'B', 'U10/2 TSV Erling-Andechs', '2026-10-11 09:30:00', '2026-10-11 11:30:00', 'Platz 4 West', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-12-r5-1020-1050-a', 'A', 'TW Jugend', '2026-10-12 17:00:00', '2026-10-12 17:30:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-12-r5-1050-1110-a', 'A', 'U8 + TW Jugend', '2026-10-12 17:30:00', '2026-10-12 18:30:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-12-r5-1110-1140-a', 'A', 'TW Jugend', '2026-10-12 18:30:00', '2026-10-12 19:00:00', 'Platz 4 West', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-13-r5-1050-1140-a', 'A', 'U12/2 + U13/2', '2026-10-13 17:30:00', '2026-10-13 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-13-r5-1170-1260-a', 'A', 'Senioren 2 + 3', '2026-10-13 19:30:00', '2026-10-13 21:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-14-r5-1050-1170-a', 'A', 'U11', '2026-10-14 17:30:00', '2026-10-14 19:30:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-15-r5-1050-1140-a', 'A', 'U12/2', '2026-10-15 17:30:00', '2026-10-15 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-16-r5-990-1080-a', 'A', 'U8 + U9', '2026-10-16 16:30:00', '2026-10-16 18:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-17-r5-570-690-b', 'B', 'U10/1 (SG) TSV Herrsching', '2026-10-17 09:30:00', '2026-10-17 11:30:00', 'Platz 4 West', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-19-r5-1050-1110-a', 'A', 'U8', '2026-10-19 17:30:00', '2026-10-19 18:30:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-19-r5-1170-1260-a', 'A', 'AH', '2026-10-19 19:30:00', '2026-10-19 21:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-20-r5-1050-1140-a', 'A', 'U12/2 + U13/2', '2026-10-20 17:30:00', '2026-10-20 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-20-r5-1170-1260-a', 'A', 'Senioren 2 + 3', '2026-10-20 19:30:00', '2026-10-20 21:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-21-r5-1020-1140-a', 'A', 'TW Jugend', '2026-10-21 17:00:00', '2026-10-21 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-22-r5-1050-1140-a', 'A', 'U12/2', '2026-10-22 17:30:00', '2026-10-22 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-22-r5-1170-1260-a', 'A', 'Senioren 2', '2026-10-22 19:30:00', '2026-10-22 21:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-23-r5-990-1080-a', 'A', 'U8 + U9', '2026-10-23 16:30:00', '2026-10-23 18:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-26-r5-1050-1110-a', 'A', 'U8', '2026-10-26 17:30:00', '2026-10-26 18:30:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-27-r5-1050-1140-a', 'A', 'U12/2 + U13/2', '2026-10-27 17:30:00', '2026-10-27 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-28-r5-1020-1140-a', 'A', 'TW Jugend', '2026-10-28 17:00:00', '2026-10-28 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-29-r5-1050-1140-a', 'A', 'U12/2', '2026-10-29 17:30:00', '2026-10-29 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-30-r5-990-1080-a', 'A', 'U8 + U9', '2026-10-30 16:30:00', '2026-10-30 18:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-01-r7-1020-1140-b', 'B', 'U12/2 MTV Diessen', '2026-10-01 17:00:00', '2026-10-01 19:00:00', 'Platz 4 Ost', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-01-r7-1170-1260-a', 'A', 'Senioren 2', '2026-10-01 19:30:00', '2026-10-01 21:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-02-r7-1050-1170-b', 'B', 'U11 TSV Schondorf', '2026-10-02 17:30:00', '2026-10-02 19:30:00', 'Platz 4 Ost', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-03-r7-570-690-b', 'B', 'U12/2 TSV Altenstadt', '2026-10-03 09:30:00', '2026-10-03 11:30:00', 'Platz 4 Ost', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-05-r7-1050-1140-b', 'B', 'U11 DJK Schwabhausen', '2026-10-05 17:30:00', '2026-10-05 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-05-r7-1170-1260-a', 'A', 'AH', '2026-10-05 19:30:00', '2026-10-05 21:00:00', 'Platz 4 Ost', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-06-r7-1050-1140-a', 'A', 'U12/2 + U13/2', '2026-10-06 17:30:00', '2026-10-06 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-06-r7-1170-1260-a', 'A', 'Senioren 2 + 3', '2026-10-06 19:30:00', '2026-10-06 21:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-07-r7-1050-1170-a', 'A', 'U11', '2026-10-07 17:30:00', '2026-10-07 19:30:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-08-r7-1050-1140-a', 'A', 'U13/2', '2026-10-08 17:30:00', '2026-10-08 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-08-r7-1170-1260-a', 'A', 'Senioren 2', '2026-10-08 19:30:00', '2026-10-08 21:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-12-r7-1050-1170-a', 'A', 'U11', '2026-10-12 17:30:00', '2026-10-12 19:30:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-13-r7-1050-1140-a', 'A', 'U12/2 + U13/2', '2026-10-13 17:30:00', '2026-10-13 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-13-r7-1170-1260-a', 'A', 'Senioren 2 + 3', '2026-10-13 19:30:00', '2026-10-13 21:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-14-r7-1050-1140-b', 'B', 'U12/2 SG Erpfting/Igling', '2026-10-14 17:30:00', '2026-10-14 19:00:00', 'Platz 4 Ost', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-15-r7-1050-1140-a', 'A', 'U13/2', '2026-10-15 17:30:00', '2026-10-15 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-16-r7-1050-1170-b', 'B', 'U11 SV Inning', '2026-10-16 17:30:00', '2026-10-16 19:30:00', 'Platz 4 Ost', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-17-r7-570-690-b', 'B', 'U12/2 TSV Utting', '2026-10-17 09:30:00', '2026-10-17 11:30:00', 'Platz 4 Ost', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-19-r7-1050-1170-a', 'A', 'U11', '2026-10-19 17:30:00', '2026-10-19 19:30:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-19-r7-1170-1260-a', 'A', 'AH', '2026-10-19 19:30:00', '2026-10-19 21:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-20-r7-1050-1140-a', 'A', 'U12/2 + U13/2', '2026-10-20 17:30:00', '2026-10-20 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-20-r7-1170-1260-a', 'A', 'Senioren 2 + 3', '2026-10-20 19:30:00', '2026-10-20 21:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-21-r7-1050-1170-a', 'A', 'U11', '2026-10-21 17:30:00', '2026-10-21 19:30:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-22-r7-1050-1140-a', 'A', 'U13/2', '2026-10-22 17:30:00', '2026-10-22 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-22-r7-1170-1260-a', 'A', 'Senioren 2', '2026-10-22 19:30:00', '2026-10-22 21:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-26-r7-1050-1170-a', 'A', 'U11', '2026-10-26 17:30:00', '2026-10-26 19:30:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-27-r7-1050-1140-a', 'A', 'U12/2 + U13/2', '2026-10-27 17:30:00', '2026-10-27 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-28-r7-1050-1170-b', 'B', 'U11 TSV Landsberg', '2026-10-28 17:30:00', '2026-10-28 19:30:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-29-r7-1050-1140-a', 'A', 'U13/2', '2026-10-29 17:30:00', '2026-10-29 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-01-r6-1080-1170-a', 'A', 'U17w/TuL', '2026-10-01 18:00:00', '2026-10-01 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-01-r6-1170-1260-a', 'A', 'Senioren 3', '2026-10-01 19:30:00', '2026-10-01 21:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-02-r6-960-1110-a', 'A', 'U12/1 + U13/1', '2026-10-02 16:00:00', '2026-10-02 18:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-05-r6-1020-1140-c', 'C', 'Hilti', '2026-10-05 17:00:00', '2026-10-05 19:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-06-r6-1050-1170-a', 'A', 'U12/1 + U13/1', '2026-10-06 17:30:00', '2026-10-06 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-06-r6-1170-1260-a', 'A', 'TuL', '2026-10-06 19:30:00', '2026-10-06 21:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-08-r6-1080-1170-a', 'A', 'U17w/TuL', '2026-10-08 18:00:00', '2026-10-08 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-08-r6-1170-1260-a', 'A', 'Senioren 3', '2026-10-08 19:30:00', '2026-10-08 21:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-09-r6-960-1110-a', 'A', 'U12/1 + U13/1', '2026-10-09 16:00:00', '2026-10-09 18:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-11-r6-600-720-b', 'B', 'U13/2 SC Wörthsee', '2026-10-11 10:00:00', '2026-10-11 12:00:00', 'Platz 5', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-12-r6-1020-1140-c', 'C', 'Hilti', '2026-10-12 17:00:00', '2026-10-12 19:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-13-r6-1050-1170-a', 'A', 'U12/1 + U13/1', '2026-10-13 17:30:00', '2026-10-13 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-13-r6-1170-1260-a', 'A', 'TuL', '2026-10-13 19:30:00', '2026-10-13 21:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-14-r6-1020-1170-a', 'A', 'U12/1', '2026-10-14 17:00:00', '2026-10-14 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-15-r6-1080-1170-a', 'A', 'U17w/TuL', '2026-10-15 18:00:00', '2026-10-15 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-15-r6-1170-1260-a', 'A', 'Senioren 3', '2026-10-15 19:30:00', '2026-10-15 21:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-18-r6-630-720-b', 'B', 'U13/2 FC Stätzling', '2026-10-18 10:30:00', '2026-10-18 12:00:00', 'Platz 5', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-19-r6-1020-1140-c', 'C', 'Hilti', '2026-10-19 17:00:00', '2026-10-19 19:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-20-r6-1050-1170-a', 'A', 'U12/1 + U13/1', '2026-10-20 17:30:00', '2026-10-20 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-20-r6-1170-1260-a', 'A', 'TuL', '2026-10-20 19:30:00', '2026-10-20 21:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-22-r6-1080-1170-a', 'A', 'U17w/TuL', '2026-10-22 18:00:00', '2026-10-22 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-22-r6-1170-1260-a', 'A', 'Senioren 3', '2026-10-22 19:30:00', '2026-10-22 21:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-23-r6-960-1110-a', 'A', 'U12/1 + U13/1', '2026-10-23 16:00:00', '2026-10-23 18:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-24-r6-630-750-b', 'B', 'U17w SG Blonhofen/Stöttwang', '2026-10-24 10:30:00', '2026-10-24 12:30:00', 'Platz 5', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-25-r6-600-720-b', 'B', 'U13/2 FC Grün-Weiß Gröbenzell', '2026-10-25 10:00:00', '2026-10-25 12:00:00', 'Platz 5', 0),
        ('vflbuchung-example-202610-202703:source-2026-10-26-r6-1020-1140-c', 'C', 'Hilti', '2026-10-26 17:00:00', '2026-10-26 19:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-27-r6-1050-1170-a', 'A', 'U12/1 + U13/1', '2026-10-27 17:30:00', '2026-10-27 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-27-r6-1170-1260-a', 'A', 'TuL', '2026-10-27 19:30:00', '2026-10-27 21:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-29-r6-1080-1170-a', 'A', 'U17w/TuL', '2026-10-29 18:00:00', '2026-10-29 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-29-r6-1170-1260-a', 'A', 'Senioren 3', '2026-10-29 19:30:00', '2026-10-29 21:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-30-r6-960-1110-a', 'A', 'U12/1 + U13/1', '2026-10-30 16:00:00', '2026-10-30 18:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-10-31-r6-840-960-b', 'B', 'U13/2 (SG) SV Althegnenberg', '2026-10-31 14:00:00', '2026-10-31 16:00:00', 'Platz 5', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-02-r2-1050-1110-a', 'A', 'U8', '2026-11-02 17:30:00', '2026-11-02 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-02-r2-1170-1260-a', 'A', 'AH', '2026-11-02 19:30:00', '2026-11-02 21:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-03-r2-1020-1110-a', 'A', 'U15', '2026-11-03 17:00:00', '2026-11-03 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-03-r2-1110-1200-a', 'A', 'U19', '2026-11-03 18:30:00', '2026-11-03 20:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-03-r2-1200-1260-a', 'A', 'Senioren 2 + 3', '2026-11-03 20:00:00', '2026-11-03 21:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-05-r2-1020-1110-a', 'A', 'U15', '2026-11-05 17:00:00', '2026-11-05 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-05-r2-1110-1170-a', 'A', 'U17', '2026-11-05 18:30:00', '2026-11-05 19:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-05-r2-1170-1260-a', 'A', 'Senioren 2', '2026-11-05 19:30:00', '2026-11-05 21:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-07-r2-630-750-b', 'B', 'U13/1 FC Stätzling', '2026-11-07 10:30:00', '2026-11-07 12:30:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-08-r2-630-750-b', 'B', 'U12/1 FC Kempten', '2026-11-08 10:30:00', '2026-11-08 12:30:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-09-r2-1050-1110-a', 'A', 'U8', '2026-11-09 17:30:00', '2026-11-09 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-10-r2-1020-1110-a', 'A', 'U15', '2026-11-10 17:00:00', '2026-11-10 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-10-r2-1110-1170-a', 'A', 'U19', '2026-11-10 18:30:00', '2026-11-10 19:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-10-r2-1170-1260-a', 'A', 'Senioren 2 + 3', '2026-11-10 19:30:00', '2026-11-10 21:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-11-r2-1020-1110-a', 'A', 'U9', '2026-11-11 17:00:00', '2026-11-11 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-12-r2-1020-1110-a', 'A', 'U15', '2026-11-12 17:00:00', '2026-11-12 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-12-r2-1110-1170-a', 'A', 'U17', '2026-11-12 18:30:00', '2026-11-12 19:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-12-r2-1170-1260-a', 'A', 'Senioren 2', '2026-11-12 19:30:00', '2026-11-12 21:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-14-r2-870-1020-b', 'B', 'U19 Gautinger SC', '2026-11-14 14:30:00', '2026-11-14 17:00:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-15-r2-630-750-b', 'B', 'U12/1 TSV Nördlingen', '2026-11-15 10:30:00', '2026-11-15 12:30:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-16-r2-1050-1110-a', 'A', 'U8', '2026-11-16 17:30:00', '2026-11-16 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-17-r2-1020-1110-a', 'A', 'U15', '2026-11-17 17:00:00', '2026-11-17 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-17-r2-1110-1170-a', 'A', 'U19', '2026-11-17 18:30:00', '2026-11-17 19:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-17-r2-1170-1260-a', 'A', 'Senioren 2 + 3', '2026-11-17 19:30:00', '2026-11-17 21:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-18-r2-1020-1110-a', 'A', 'U9', '2026-11-18 17:00:00', '2026-11-18 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-19-r2-1020-1110-a', 'A', 'U15', '2026-11-19 17:00:00', '2026-11-19 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-19-r2-1110-1170-a', 'A', 'U17', '2026-11-19 18:30:00', '2026-11-19 19:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-19-r2-1170-1260-a', 'A', 'Senioren 2', '2026-11-19 19:30:00', '2026-11-19 21:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-21-r2-630-750-b', 'B', 'U13/1 VfR Garching', '2026-11-21 10:30:00', '2026-11-21 12:30:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-23-r2-1050-1110-a', 'A', 'U8', '2026-11-23 17:30:00', '2026-11-23 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-24-r2-1020-1110-a', 'A', 'U15', '2026-11-24 17:00:00', '2026-11-24 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-24-r2-1110-1170-a', 'A', 'U19', '2026-11-24 18:30:00', '2026-11-24 19:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-24-r2-1170-1260-a', 'A', 'Senioren 2 + 3', '2026-11-24 19:30:00', '2026-11-24 21:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-26-r2-1020-1110-a', 'A', 'U15', '2026-11-26 17:00:00', '2026-11-26 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-26-r2-1110-1170-a', 'A', 'U17', '2026-11-26 18:30:00', '2026-11-26 19:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-26-r2-1170-1260-a', 'A', 'Senioren 2', '2026-11-26 19:30:00', '2026-11-26 21:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-30-r2-1050-1110-a', 'A', 'U8', '2026-11-30 17:30:00', '2026-11-30 18:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-02-r1-1050-1170-a', 'A', 'U11', '2026-11-02 17:30:00', '2026-11-02 19:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-02-r1-1170-1260-a', 'A', 'AH', '2026-11-02 19:30:00', '2026-11-02 21:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-03-r1-990-1080-a', 'A', 'U10', '2026-11-03 16:30:00', '2026-11-03 18:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-03-r1-1110-1170-a', 'A', 'U17', '2026-11-03 18:30:00', '2026-11-03 19:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-03-r1-1170-1260-a', 'A', 'Senioren 2 + 3', '2026-11-03 19:30:00', '2026-11-03 21:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-04-r1-1020-1140-a', 'A', 'U12/1', '2026-11-04 17:00:00', '2026-11-04 19:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-05-r1-1020-1110-a', 'A', 'U15', '2026-11-05 17:00:00', '2026-11-05 18:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-05-r1-1110-1200-a', 'A', 'U19', '2026-11-05 18:30:00', '2026-11-05 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-05-r1-1200-1260-a', 'A', 'Senioren 2', '2026-11-05 20:00:00', '2026-11-05 21:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-06-r1-990-1080-a', 'A', 'U10', '2026-11-06 16:30:00', '2026-11-06 18:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-07-r1-630-750-b', 'B', 'U13/1 FC Stätzling', '2026-11-07 10:30:00', '2026-11-07 12:30:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-08-r1-630-750-b', 'B', 'U12/1 FC Kempten', '2026-11-08 10:30:00', '2026-11-08 12:30:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-09-r1-1050-1170-a', 'A', 'U11', '2026-11-09 17:30:00', '2026-11-09 19:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-10-r1-990-1080-a', 'A', 'U10', '2026-11-10 16:30:00', '2026-11-10 18:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-10-r1-1110-1200-a', 'A', 'U17', '2026-11-10 18:30:00', '2026-11-10 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-10-r1-1200-1260-a', 'A', 'Senioren 2 + 3', '2026-11-10 20:00:00', '2026-11-10 21:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-11-r1-1020-1140-a', 'A', 'U12/1', '2026-11-11 17:00:00', '2026-11-11 19:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-12-r1-1020-1110-a', 'A', 'U15', '2026-11-12 17:00:00', '2026-11-12 18:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-12-r1-1110-1200-a', 'A', 'U19', '2026-11-12 18:30:00', '2026-11-12 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-12-r1-1200-1260-a', 'A', 'Senioren 2', '2026-11-12 20:00:00', '2026-11-12 21:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-13-r1-990-1080-a', 'A', 'U10', '2026-11-13 16:30:00', '2026-11-13 18:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-14-r1-870-1020-b', 'B', 'U19 Gautinger SC', '2026-11-14 14:30:00', '2026-11-14 17:00:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-15-r1-630-750-b', 'B', 'U12/1 TSV Nördlingen', '2026-11-15 10:30:00', '2026-11-15 12:30:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-16-r1-1050-1170-a', 'A', 'U11', '2026-11-16 17:30:00', '2026-11-16 19:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-17-r1-990-1080-a', 'A', 'U10', '2026-11-17 16:30:00', '2026-11-17 18:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-17-r1-1110-1200-a', 'A', 'U17', '2026-11-17 18:30:00', '2026-11-17 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-17-r1-1200-1260-a', 'A', 'Senioren 2 + 3', '2026-11-17 20:00:00', '2026-11-17 21:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-18-r1-1020-1140-a', 'A', 'U12/1', '2026-11-18 17:00:00', '2026-11-18 19:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-19-r1-1020-1110-a', 'A', 'U15', '2026-11-19 17:00:00', '2026-11-19 18:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-19-r1-1110-1200-a', 'A', 'U19', '2026-11-19 18:30:00', '2026-11-19 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-19-r1-1200-1260-a', 'A', 'Senioren 2', '2026-11-19 20:00:00', '2026-11-19 21:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-20-r1-990-1080-a', 'A', 'U10', '2026-11-20 16:30:00', '2026-11-20 18:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-21-r1-630-750-b', 'B', 'U13/1 VfR Garching', '2026-11-21 10:30:00', '2026-11-21 12:30:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-23-r1-1050-1170-a', 'A', 'U11', '2026-11-23 17:30:00', '2026-11-23 19:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-24-r1-1020-1110-a', 'A', 'U15', '2026-11-24 17:00:00', '2026-11-24 18:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-24-r1-1110-1200-a', 'A', 'U17', '2026-11-24 18:30:00', '2026-11-24 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-24-r1-1200-1260-a', 'A', 'Senioren 2 + 3', '2026-11-24 20:00:00', '2026-11-24 21:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-25-r1-1020-1140-a', 'A', 'U12/1', '2026-11-25 17:00:00', '2026-11-25 19:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-26-r1-1020-1110-a', 'A', 'U15', '2026-11-26 17:00:00', '2026-11-26 18:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-26-r1-1110-1200-a', 'A', 'U19', '2026-11-26 18:30:00', '2026-11-26 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-26-r1-1200-1260-a', 'A', 'Senioren 2', '2026-11-26 20:00:00', '2026-11-26 21:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-27-r1-990-1080-a', 'A', 'U10', '2026-11-27 16:30:00', '2026-11-27 18:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-30-r1-1050-1170-a', 'A', 'U11', '2026-11-30 17:30:00', '2026-11-30 19:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-06-r3-1050-1170-b', 'B', 'U15 TSV Schongau', '2026-11-06 17:30:00', '2026-11-06 19:30:00', 'Platz 2', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-08-r3-630-780-b', 'B', 'U17 FT Jahn Landsberg', '2026-11-08 10:30:00', '2026-11-08 13:00:00', 'Platz 2', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-12-r3-1140-1260-b', 'B', 'Senioren 3 SpVgg Lagerlechfeld', '2026-11-12 19:00:00', '2026-11-12 21:00:00', 'Platz 2', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-14-r3-840-960-b', 'B', 'Senioren 1  FC Königsbrunn', '2026-11-14 14:00:00', '2026-11-14 16:00:00', 'Platz 2', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-14-r3-960-1080-b', 'B', 'Senioren 2 FC Kleinaitingen', '2026-11-14 16:00:00', '2026-11-14 18:00:00', 'Platz 2', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-03-r4-1140-1230-a', 'A', 'Senioren 1', '2026-11-03 19:00:00', '2026-11-03 20:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-04-r4-1020-1110-a', 'A', 'U9', '2026-11-04 17:00:00', '2026-11-04 18:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-05-r4-1140-1230-a', 'A', 'Senioren 1', '2026-11-05 19:00:00', '2026-11-05 20:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-06-r4-990-1080-a', 'A', 'U7', '2026-11-06 16:30:00', '2026-11-06 18:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-10-r4-1140-1230-a', 'A', 'Senioren 1', '2026-11-10 19:00:00', '2026-11-10 20:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-12-r4-1140-1230-a', 'A', 'Senioren 1', '2026-11-12 19:00:00', '2026-11-12 20:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-13-r4-990-1080-a', 'A', 'U7', '2026-11-13 16:30:00', '2026-11-13 18:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-17-r4-1140-1230-a', 'A', 'Senioren 1', '2026-11-17 19:00:00', '2026-11-17 20:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-19-r4-1140-1230-a', 'A', 'Senioren 1', '2026-11-19 19:00:00', '2026-11-19 20:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-20-r4-990-1080-a', 'A', 'U7', '2026-11-20 16:30:00', '2026-11-20 18:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-24-r4-990-1080-a', 'A', 'U10', '2026-11-24 16:30:00', '2026-11-24 18:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-24-r4-1140-1230-a', 'A', 'Senioren 1', '2026-11-24 19:00:00', '2026-11-24 20:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-25-r4-1020-1110-a', 'A', 'U9', '2026-11-25 17:00:00', '2026-11-25 18:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-26-r4-1140-1230-a', 'A', 'Senioren 1', '2026-11-26 19:00:00', '2026-11-26 20:30:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-27-r4-990-1080-a', 'A', 'U7', '2026-11-27 16:30:00', '2026-11-27 18:00:00', 'Platz 3', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-03-r5-1050-1140-a', 'A', 'U12/2 + U13/2', '2026-11-03 17:30:00', '2026-11-03 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-04-r5-1020-1140-a', 'A', 'TW Jugend', '2026-11-04 17:00:00', '2026-11-04 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-05-r5-1050-1140-a', 'A', 'U12/2', '2026-11-05 17:30:00', '2026-11-05 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-06-r5-990-1080-a', 'A', 'U8 + U9', '2026-11-06 16:30:00', '2026-11-06 18:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-10-r5-1050-1140-a', 'A', 'U12/2 + U13/2', '2026-11-10 17:30:00', '2026-11-10 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-11-r5-1020-1140-a', 'A', 'TW Jugend', '2026-11-11 17:00:00', '2026-11-11 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-12-r5-1050-1140-a', 'A', 'U12/2', '2026-11-12 17:30:00', '2026-11-12 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-13-r5-990-1080-a', 'A', 'U8 + U9', '2026-11-13 16:30:00', '2026-11-13 18:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-17-r5-1050-1140-a', 'A', 'U12/2 + U13/2', '2026-11-17 17:30:00', '2026-11-17 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-18-r5-1020-1140-a', 'A', 'TW Jugend', '2026-11-18 17:00:00', '2026-11-18 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-19-r5-1050-1140-a', 'A', 'U12/2', '2026-11-19 17:30:00', '2026-11-19 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-20-r5-990-1080-a', 'A', 'U8 + U9', '2026-11-20 16:30:00', '2026-11-20 18:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-24-r5-1050-1140-a', 'A', 'U12/2 + U13/2', '2026-11-24 17:30:00', '2026-11-24 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-25-r5-1020-1140-a', 'A', 'TW Jugend', '2026-11-25 17:00:00', '2026-11-25 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-26-r5-1050-1140-a', 'A', 'U12/2', '2026-11-26 17:30:00', '2026-11-26 19:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-27-r5-990-1080-a', 'A', 'U8 + U9', '2026-11-27 16:30:00', '2026-11-27 18:00:00', 'Platz 4 West', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-02-r7-1050-1140-a', 'A', 'Förderkader', '2026-11-02 17:30:00', '2026-11-02 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-03-r7-1050-1140-a', 'A', 'U12/2 + U13/2', '2026-11-03 17:30:00', '2026-11-03 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-04-r7-1050-1170-a', 'A', 'U11', '2026-11-04 17:30:00', '2026-11-04 19:30:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-05-r7-1050-1140-a', 'A', 'U13/2', '2026-11-05 17:30:00', '2026-11-05 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-07-r7-570-690-b', 'B', 'U12/2 TSV Tutzing', '2026-11-07 09:30:00', '2026-11-07 11:30:00', 'Platz 4 Ost', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-09-r7-1050-1140-a', 'A', 'Förderkader', '2026-11-09 17:30:00', '2026-11-09 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-10-r7-1050-1140-a', 'A', 'U12/2 + U13/2', '2026-11-10 17:30:00', '2026-11-10 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-11-r7-1050-1170-a', 'A', 'U11', '2026-11-11 17:30:00', '2026-11-11 19:30:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-12-r7-1050-1140-a', 'A', 'U13/2', '2026-11-12 17:30:00', '2026-11-12 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-14-r7-570-690-b', 'B', 'U12/2 VfL Denklingen', '2026-11-14 09:30:00', '2026-11-14 11:30:00', 'Platz 4 Ost', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-16-r7-1050-1140-a', 'A', 'Förderkader', '2026-11-16 17:30:00', '2026-11-16 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-17-r7-1050-1140-a', 'A', 'U12/2 + U13/2', '2026-11-17 17:30:00', '2026-11-17 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-18-r7-1050-1170-a', 'A', 'U11', '2026-11-18 17:30:00', '2026-11-18 19:30:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-19-r7-1050-1140-a', 'A', 'U13/2', '2026-11-19 17:30:00', '2026-11-19 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-23-r7-1050-1140-a', 'A', 'Förderkader', '2026-11-23 17:30:00', '2026-11-23 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-24-r7-1050-1140-a', 'A', 'U12/2 + U13/2', '2026-11-24 17:30:00', '2026-11-24 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-25-r7-1050-1170-a', 'A', 'U11', '2026-11-25 17:30:00', '2026-11-25 19:30:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-26-r7-1050-1140-a', 'A', 'U13/2', '2026-11-26 17:30:00', '2026-11-26 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-30-r7-1050-1140-a', 'A', 'Förderkader', '2026-11-30 17:30:00', '2026-11-30 19:00:00', 'Platz 4 Ost', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-03-r6-1050-1170-a', 'A', 'U12/1 + U13/1', '2026-11-03 17:30:00', '2026-11-03 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-03-r6-1170-1260-a', 'A', 'TuL', '2026-11-03 19:30:00', '2026-11-03 21:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-05-r6-1080-1170-a', 'A', 'U17w/TuL', '2026-11-05 18:00:00', '2026-11-05 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-05-r6-1170-1260-a', 'A', 'Senioren 3', '2026-11-05 19:30:00', '2026-11-05 21:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-06-r6-960-1110-a', 'A', 'U12/1 + U13/1', '2026-11-06 16:00:00', '2026-11-06 18:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-08-r6-600-720-b', 'B', 'U13/2 TSV Geltendorf', '2026-11-08 10:00:00', '2026-11-08 12:00:00', 'Platz 5', 0),
        ('vflbuchung-example-202610-202703:source-2026-11-10-r6-1050-1170-a', 'A', 'U12/1 + U13/1', '2026-11-10 17:30:00', '2026-11-10 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-10-r6-1170-1260-a', 'A', 'TuL', '2026-11-10 19:30:00', '2026-11-10 21:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-12-r6-1080-1170-a', 'A', 'U17w/TuL', '2026-11-12 18:00:00', '2026-11-12 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-12-r6-1170-1260-a', 'A', 'Senioren 3', '2026-11-12 19:30:00', '2026-11-12 21:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-13-r6-960-1110-a', 'A', 'U12/1 + U13/1', '2026-11-13 16:00:00', '2026-11-13 18:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-17-r6-1050-1170-a', 'A', 'U12/1 + U13/1', '2026-11-17 17:30:00', '2026-11-17 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-17-r6-1170-1260-a', 'A', 'TuL', '2026-11-17 19:30:00', '2026-11-17 21:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-19-r6-1080-1170-a', 'A', 'U17w/TuL', '2026-11-19 18:00:00', '2026-11-19 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-19-r6-1170-1260-a', 'A', 'Senioren 3', '2026-11-19 19:30:00', '2026-11-19 21:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-20-r6-960-1110-a', 'A', 'U12/1 + U13/1', '2026-11-20 16:00:00', '2026-11-20 18:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-24-r6-1050-1170-a', 'A', 'U12/1 + U13/1', '2026-11-24 17:30:00', '2026-11-24 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-24-r6-1170-1260-a', 'A', 'TuL', '2026-11-24 19:30:00', '2026-11-24 21:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-26-r6-1080-1170-a', 'A', 'U17w/TuL', '2026-11-26 18:00:00', '2026-11-26 19:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-26-r6-1170-1260-a', 'A', 'Senioren 3', '2026-11-26 19:30:00', '2026-11-26 21:00:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-11-27-r6-960-1110-a', 'A', 'U12/1 + U13/1', '2026-11-27 16:00:00', '2026-11-27 18:30:00', 'Platz 5', 1),
        ('vflbuchung-example-202610-202703:source-2026-12-06-r2-540-780-b', 'B', 'U12 + U13 Intl. Turnier', '2026-12-06 09:00:00', '2026-12-06 13:00:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2026-12-07-r2-1020-1200-c', 'C', 'DFB Stützpunkt', '2026-12-07 17:00:00', '2026-12-07 20:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-12-14-r2-1020-1200-c', 'C', 'DFB Stützpunkt', '2026-12-14 17:00:00', '2026-12-14 20:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-12-21-r2-1020-1200-c', 'C', 'DFB Stützpunkt', '2026-12-21 17:00:00', '2026-12-21 20:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-12-28-r2-1020-1200-c', 'C', 'DFB Stützpunkt', '2026-12-28 17:00:00', '2026-12-28 20:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2026-12-06-r1-540-780-b', 'B', 'U12 + U13 Intl. Turnier', '2026-12-06 09:00:00', '2026-12-06 13:00:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2026-12-07-r1-1020-1200-c', 'C', 'DFB Stützpunkt', '2026-12-07 17:00:00', '2026-12-07 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-12-14-r1-1020-1200-c', 'C', 'DFB Stützpunkt', '2026-12-14 17:00:00', '2026-12-14 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-12-21-r1-1020-1200-c', 'C', 'DFB Stützpunkt', '2026-12-21 17:00:00', '2026-12-21 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2026-12-28-r1-1020-1200-c', 'C', 'DFB Stützpunkt', '2026-12-28 17:00:00', '2026-12-28 20:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-01-r2-1140-1230-a', 'A', 'Senioren 1', '2027-02-01 19:00:00', '2027-02-01 20:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-03-r2-1140-1230-a', 'A', 'Senioren 1', '2027-02-03 19:00:00', '2027-02-03 20:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-05-r2-1140-1230-a', 'A', 'Senioren 1', '2027-02-05 19:00:00', '2027-02-05 20:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-06-r2-570-750-b', 'B', 'Herren 2 TSV Göggingen', '2027-02-06 09:30:00', '2027-02-06 12:30:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2027-02-08-r2-1140-1230-a', 'A', 'Senioren 1', '2027-02-08 19:00:00', '2027-02-08 20:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-10-r2-1140-1230-a', 'A', 'Senioren 1', '2027-02-10 19:00:00', '2027-02-10 20:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-11-r2-1140-1230-a', 'A', 'Senioren 1', '2027-02-11 19:00:00', '2027-02-11 20:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-13-r2-780-960-b', 'B', 'Senioren 2 FC Weil', '2027-02-13 13:00:00', '2027-02-13 16:00:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2027-02-15-r2-1140-1230-a', 'A', 'Senioren 1', '2027-02-15 19:00:00', '2027-02-15 20:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-17-r2-1140-1260-b', 'B', 'Senioren 1 TSV Schwabmünchen', '2027-02-17 19:00:00', '2027-02-17 21:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-18-r2-1140-1230-a', 'A', 'Senioren 1', '2027-02-18 19:00:00', '2027-02-18 20:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-20-r2-780-960-b', 'B', 'Senioren 1 SC Oberweikertshofen', '2027-02-20 13:00:00', '2027-02-20 16:00:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2027-02-20-r2-960-1110-b', 'B', 'Senioren 2 FC Penzing', '2027-02-20 16:00:00', '2027-02-20 18:30:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2027-02-22-r2-1080-1230-b', 'B', 'Senioren 2 SV Igling', '2027-02-22 18:00:00', '2027-02-22 20:30:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2027-02-24-r2-1140-1260-b', 'B', 'Senioren 1 TSV Bobingen', '2027-02-24 19:00:00', '2027-02-24 21:00:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-25-r2-1140-1230-a', 'A', 'Senioren 1', '2027-02-25 19:00:00', '2027-02-25 20:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-27-r2-840-960-b', 'B', 'Senioren 1 1.FC Garmisch-Partenkirchen', '2027-02-27 14:00:00', '2027-02-27 16:00:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2027-02-28-r2-780-960-b', 'B', 'Senioren 2 (SG) Jengen/Waal', '2027-02-28 13:00:00', '2027-02-28 16:00:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2027-02-01-r1-1140-1230-a', 'A', 'Senioren 1', '2027-02-01 19:00:00', '2027-02-01 20:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-03-r1-1140-1230-a', 'A', 'Senioren 1', '2027-02-03 19:00:00', '2027-02-03 20:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-05-r1-1140-1230-a', 'A', 'Senioren 1', '2027-02-05 19:00:00', '2027-02-05 20:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-06-r1-570-750-b', 'B', 'Herren 2 TSV Göggingen', '2027-02-06 09:30:00', '2027-02-06 12:30:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2027-02-08-r1-1140-1230-a', 'A', 'Senioren 1', '2027-02-08 19:00:00', '2027-02-08 20:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-10-r1-1140-1230-a', 'A', 'Senioren 1', '2027-02-10 19:00:00', '2027-02-10 20:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-11-r1-1140-1230-a', 'A', 'Senioren 1', '2027-02-11 19:00:00', '2027-02-11 20:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-13-r1-780-960-b', 'B', 'Senioren 2 FC Weil', '2027-02-13 13:00:00', '2027-02-13 16:00:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2027-02-15-r1-1140-1230-a', 'A', 'Senioren 1', '2027-02-15 19:00:00', '2027-02-15 20:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-17-r1-1140-1260-b', 'B', 'Senioren 1 TSV Schwabmünchen', '2027-02-17 19:00:00', '2027-02-17 21:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-18-r1-1140-1230-a', 'A', 'Senioren 1', '2027-02-18 19:00:00', '2027-02-18 20:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-20-r1-780-960-b', 'B', 'Senioren 1 SC Oberweikertshofen', '2027-02-20 13:00:00', '2027-02-20 16:00:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2027-02-20-r1-960-1110-b', 'B', 'Senioren 2 FC Penzing', '2027-02-20 16:00:00', '2027-02-20 18:30:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2027-02-22-r1-1080-1230-b', 'B', 'Senioren 2 SV Igling', '2027-02-22 18:00:00', '2027-02-22 20:30:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2027-02-24-r1-1140-1260-b', 'B', 'Senioren 1 TSV Bobingen', '2027-02-24 19:00:00', '2027-02-24 21:00:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-25-r1-1140-1230-a', 'A', 'Senioren 1', '2027-02-25 19:00:00', '2027-02-25 20:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-02-27-r1-840-960-b', 'B', 'Senioren 1 1.FC Garmisch-Partenkirchen', '2027-02-27 14:00:00', '2027-02-27 16:00:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2027-02-28-r1-780-960-b', 'B', 'Senioren 2 (SG) Jengen/Waal', '2027-02-28 13:00:00', '2027-02-28 16:00:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2027-03-01-r2-1140-1230-a', 'A', 'Senioren 1', '2027-03-01 19:00:00', '2027-03-01 20:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2027-03-03-r2-1140-1230-a', 'A', 'Senioren 1', '2027-03-03 19:00:00', '2027-03-03 20:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2027-03-04-r2-1140-1230-a', 'A', 'Senioren 1', '2027-03-04 19:00:00', '2027-03-04 20:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2027-03-05-r2-1080-1200-c', 'C', 'FC Auerbach-Stetten Mädels', '2027-03-05 18:00:00', '2027-03-05 20:00:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2027-03-06-r2-540-660-c', 'C', 'FC Auerbach-Stetten Mädels', '2027-03-06 09:00:00', '2027-03-06 11:00:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2027-03-06-r2-660-810-b', 'B', 'Senioren 3 SV Wald', '2027-03-06 11:00:00', '2027-03-06 13:30:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2027-03-06-r2-810-960-b', 'B', 'Senioren 1 BC Rinnenthal', '2027-03-06 13:30:00', '2027-03-06 16:00:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2027-03-06-r2-960-1110-b', 'B', 'Senioren 2 SV Prittriching', '2027-03-06 16:00:00', '2027-03-06 18:30:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2027-03-07-r2-540-660-c', 'C', 'FC Auerbach-Stetten Mädels', '2027-03-07 09:00:00', '2027-03-07 11:00:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2027-03-08-r2-1140-1230-a', 'A', 'Senioren 1', '2027-03-08 19:00:00', '2027-03-08 20:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2027-03-10-r2-1140-1230-a', 'A', 'Senioren 1', '2027-03-10 19:00:00', '2027-03-10 20:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2027-03-11-r2-1140-1230-a', 'A', 'Senioren 1', '2027-03-11 19:00:00', '2027-03-11 20:30:00', 'Platz 1 Süd', 1),
        ('vflbuchung-example-202610-202703:source-2027-03-13-r2-660-810-b', 'B', 'Senioren 3 FC Buchloe 2', '2027-03-13 11:00:00', '2027-03-13 13:30:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2027-03-13-r2-840-960-b', 'B', 'Senioren 1 FC Thalhofen', '2027-03-13 14:00:00', '2027-03-13 16:00:00', 'Platz 1 Süd', 0),
        ('vflbuchung-example-202610-202703:source-2027-03-01-r1-1140-1230-a', 'A', 'Senioren 1', '2027-03-01 19:00:00', '2027-03-01 20:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-03-03-r1-1140-1230-a', 'A', 'Senioren 1', '2027-03-03 19:00:00', '2027-03-03 20:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-03-04-r1-1140-1230-a', 'A', 'Senioren 1', '2027-03-04 19:00:00', '2027-03-04 20:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-03-05-r1-1080-1200-c', 'C', 'FC Auerbach-Stetten Mädels', '2027-03-05 18:00:00', '2027-03-05 20:00:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2027-03-06-r1-540-660-c', 'C', 'FC Auerbach-Stetten Mädels', '2027-03-06 09:00:00', '2027-03-06 11:00:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2027-03-06-r1-660-810-b', 'B', 'Senioren 3 SV Wald', '2027-03-06 11:00:00', '2027-03-06 13:30:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2027-03-06-r1-810-960-b', 'B', 'Senioren 1 BC Rinnenthal', '2027-03-06 13:30:00', '2027-03-06 16:00:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2027-03-06-r1-960-1110-b', 'B', 'Senioren 2 SV Prittriching', '2027-03-06 16:00:00', '2027-03-06 18:30:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2027-03-07-r1-540-660-c', 'C', 'FC Auerbach-Stetten Mädels', '2027-03-07 09:00:00', '2027-03-07 11:00:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2027-03-08-r1-1140-1230-a', 'A', 'Senioren 1', '2027-03-08 19:00:00', '2027-03-08 20:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-03-10-r1-1140-1230-a', 'A', 'Senioren 1', '2027-03-10 19:00:00', '2027-03-10 20:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-03-11-r1-1140-1230-a', 'A', 'Senioren 1', '2027-03-11 19:00:00', '2027-03-11 20:30:00', 'Platz 1 Nord', 1),
        ('vflbuchung-example-202610-202703:source-2027-03-13-r1-660-810-b', 'B', 'Senioren 3 FC Buchloe 2', '2027-03-13 11:00:00', '2027-03-13 13:30:00', 'Platz 1 Nord', 0),
        ('vflbuchung-example-202610-202703:source-2027-03-13-r1-840-960-b', 'B', 'Senioren 1 FC Thalhofen', '2027-03-13 14:00:00', '2027-03-13 16:00:00', 'Platz 1 Nord', 0);
    -- END PUBLISHED SNAPSHOT

    IF EXISTS (
        SELECT 1 FROM example_booking_plan plan
        WHERE NOT EXISTS (
            SELECT 1 FROM resources resource
            JOIN resource_categories category ON category.id = resource.category_id
            WHERE category.code = 'pitch' AND resource.name = plan.pitch_name AND resource.is_active = 1
        )
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The seven active default pitches are required; check resource names.';
    END IF;

    SELECT COUNT(*) INTO planned_count FROM example_booking_plan;
    IF planned_count <> 437 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Incomplete source snapshot; no bookings were imported.';
    END IF;

    OPEN bookings;
    booking_loop: LOOP
        FETCH bookings INTO booking_type, booking_title, local_start, local_end, pitch_id;
        IF finished THEN
            LEAVE booking_loop;
        END IF;

        -- Fixed DST boundaries for this snapshot avoid requiring MySQL timezone tables.
        SET utc_offset = IF(DATE(local_start) < '2026-10-25' OR DATE(local_start) >= '2027-03-28', '+02:00', '+01:00');
        INSERT INTO booking_groups (created_by, requested_by, recurrence_rule, recurrence_until)
        VALUES (actor_id, NULL, NULL, NULL);
        SET group_id = LAST_INSERT_ID();

        INSERT INTO booking_occurrences
            (group_id, occurrence_no, event_type, title, team_name, private_notes, starts_at, ends_at, status)
        VALUES (group_id, 0, booking_type, booking_title, NULL, NULL,
            CONVERT_TZ(local_start, utc_offset, '+00:00'),
            CONVERT_TZ(local_end, utc_offset, '+00:00'), 'confirmed');
        SET occurrence_id = LAST_INSERT_ID();
        SET inserted_count = inserted_count + 1;
        INSERT INTO booking_resource_assignments (occurrence_id, resource_id, status)
        VALUES (occurrence_id, pitch_id, 'confirmed');
    END LOOP;
    CLOSE bookings;
    COMMIT;

    DROP TEMPORARY TABLE example_booking_plan;
    DO RELEASE_LOCK('vflbuchung-example-202610-202703');
    SET locked = 0;
    SELECT planned_count AS planned_bookings, inserted_count AS inserted_bookings;
END$$
DELIMITER ;

CALL seed_example_bookings_202610_202703();
DROP PROCEDURE seed_example_bookings_202610_202703;
