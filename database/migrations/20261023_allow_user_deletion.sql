ALTER TABLE booking_groups
    DROP FOREIGN KEY fk_booking_groups_created_by,
    MODIFY created_by BIGINT UNSIGNED NULL,
    ADD CONSTRAINT fk_booking_groups_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE booking_overrides
    DROP FOREIGN KEY fk_booking_overrides_actor,
    MODIFY actor_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT fk_booking_overrides_actor FOREIGN KEY (actor_id)
        REFERENCES users (id) ON DELETE SET NULL;
