START TRANSACTION;

CREATE TEMPORARY TABLE standalone_cabin_bookings AS
SELECT o.id, o.group_id
FROM booking_occurrences o
WHERE EXISTS (
    SELECT 1 FROM booking_resource_assignments a
    JOIN resources r ON r.id = a.resource_id
    JOIN resource_categories rc ON rc.id = r.category_id
    WHERE a.occurrence_id = o.id AND rc.code = 'cabin'
)
AND NOT EXISTS (
    SELECT 1 FROM booking_resource_assignments a
    JOIN resources r ON r.id = a.resource_id
    JOIN resource_categories rc ON rc.id = r.category_id
    WHERE a.occurrence_id = o.id AND rc.code <> 'cabin'
);

DELETE o FROM booking_occurrences o
JOIN standalone_cabin_bookings s ON s.id = o.id;

DELETE g FROM booking_groups g
JOIN (SELECT DISTINCT group_id FROM standalone_cabin_bookings) s ON s.group_id = g.id
WHERE NOT EXISTS (SELECT 1 FROM booking_occurrences o WHERE o.group_id = g.id);

DROP TEMPORARY TABLE standalone_cabin_bookings;

COMMIT;
