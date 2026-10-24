CREATE TABLE booking_cabin_assignments_new (
    occurrence_id BIGINT UNSIGNED NOT NULL,
    cabin_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (occurrence_id, cabin_id),
    KEY idx_booking_cabins_cabin (cabin_id),
    CONSTRAINT fk_booking_cabins_occurrence_new FOREIGN KEY (occurrence_id)
        REFERENCES booking_occurrences (id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_cabins_cabin_new FOREIGN KEY (cabin_id)
        REFERENCES resources (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO booking_cabin_assignments_new (occurrence_id, cabin_id)
SELECT DISTINCT a.occurrence_id, bca.cabin_id
FROM booking_cabin_assignments bca
JOIN booking_resource_assignments a ON a.id = bca.assignment_id;

RENAME TABLE
    booking_cabin_assignments TO booking_cabin_assignments_legacy,
    booking_cabin_assignments_new TO booking_cabin_assignments;

DROP TABLE booking_cabin_assignments_legacy;

ALTER TABLE booking_cabin_assignments
    DROP FOREIGN KEY fk_booking_cabins_occurrence_new,
    DROP FOREIGN KEY fk_booking_cabins_cabin_new,
    ADD CONSTRAINT fk_booking_cabins_occurrence FOREIGN KEY (occurrence_id)
        REFERENCES booking_occurrences (id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_booking_cabins_cabin FOREIGN KEY (cabin_id)
        REFERENCES resources (id);
