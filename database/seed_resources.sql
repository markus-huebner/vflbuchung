INSERT INTO resources (category_id, sort_order, name)
SELECT rc.id, initial_resource.sort_order, initial_resource.name
FROM (
    SELECT 'pitch' AS category_code, 0 AS sort_order, CONVERT(0x506C61747A20312053C3BC64 USING utf8mb4) AS name
    UNION ALL SELECT 'pitch', 1, 'Platz 1 Nord'
    UNION ALL SELECT 'pitch', 2, 'Platz 2'
    UNION ALL SELECT 'pitch', 3, 'Platz 3'
    UNION ALL SELECT 'pitch', 4, 'Platz 4 Ost'
    UNION ALL SELECT 'pitch', 5, 'Platz 4 West'
    UNION ALL SELECT 'pitch', 6, 'Platz 5'
    UNION ALL SELECT 'bus', 0, 'Vereinsbus'
    UNION ALL SELECT 'cabin', 0, 'Kabine 1'
    UNION ALL SELECT 'cabin', 1, 'Kabine 2'
    UNION ALL SELECT 'cabin', 2, 'Kabine 3'
    UNION ALL SELECT 'cabin', 3, 'Kabine 4'
    UNION ALL SELECT 'cabin', 4, 'Kabine 5'
    UNION ALL SELECT 'cabin', 5, 'Kabine 6'
) AS initial_resource
JOIN resource_categories rc ON rc.code = initial_resource.category_code
ON DUPLICATE KEY UPDATE name = VALUES(name);
