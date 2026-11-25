<?php
declare(strict_types=1);

use App\BookingService;
use App\Config;
use App\Database;
use App\Mailer;

require getcwd() . '/www/vendor/autoload.php';

$config = Config::fromEnvironment('/dev/null', '/dev/null');
if (!preg_match('/^vflbuchung_test_[a-z0-9]+$/', $config->get('DB_NAME'))) {
    throw new RuntimeException('Run this test only against a dedicated test database.');
}
$pdo = Database::connect($config);
$service = new BookingService($pdo, new Mailer($config), $config);
$pdo->exec("INSERT INTO users (username, first_name, last_name, role, status) VALUES ('manager', 'Test', 'Manager', 'manager', 'active')");
$manager = ['id' => (int) $pdo->lastInsertId(), 'role' => 'manager'];

foreach (['pitch', 'pitch', 'cabin'] as $index => $type) {
    $statement = $pdo->prepare(
        'INSERT INTO resources (category_id, name) SELECT id, :name FROM resource_categories WHERE code = :type'
    );
    $statement->execute(['name' => $type . $index, 'type' => $type]);
    $resources[] = (int) $pdo->lastInsertId();
}

$created = $service->create([
    'event_type' => 'training',
    'resource_mode' => 'pitch',
    'resource_ids' => [$resources[0], $resources[1]],
    'cabin_ids' => [$resources[2]],
    'title' => 'Recurring booking',
    'starts_at' => '2033-05-01T10:00',
    'ends_at' => '2033-05-01T11:00',
    'frequency' => 'daily',
    'limit_type' => 'count',
    'count' => 4,
], $manager);
$query = $pdo->prepare(
    'SELECT o.occurrence_no, a.id, a.resource_id FROM booking_occurrences o
     JOIN booking_resource_assignments a ON a.occurrence_id = o.id
     WHERE o.group_id = :group_id ORDER BY o.occurrence_no, a.resource_id'
);
$query->execute(['group_id' => $created['group_id']]);
$before = $query->fetchAll();
$target = array_values(array_filter(
    $before,
    static fn(array $row): bool => (int) $row['occurrence_no'] === 1 && (int) $row['resource_id'] === $resources[0]
));
if (count($target) !== 1) {
    throw new RuntimeException('Could not find the second occurrence.');
}
$result = $service->cancel((int) $target[0]['id'], $manager, 'future');
$query->execute(['group_id' => $created['group_id']]);
$after = $query->fetchAll();
if ($result['cancelled'] !== 3 || count($after) !== 5) {
    throw new RuntimeException('Future cancellation did not remove exactly the selected and later assignments.');
}
foreach ($after as $row) {
    if ((int) $row['resource_id'] === $resources[0] && (int) $row['occurrence_no'] !== 0) {
        throw new RuntimeException('A future assignment was not removed.');
    }
}
$cabins = $pdo->prepare(
    'SELECT COUNT(*) FROM booking_cabin_assignments c JOIN booking_occurrences o ON o.id = c.occurrence_id
     WHERE o.group_id = :group_id'
);
$cabins->execute(['group_id' => $created['group_id']]);
if ((int) $cabins->fetchColumn() !== 4) {
    throw new RuntimeException('Cancelling one resource must preserve cabins and other resources.');
}

$otherTarget = array_values(array_filter(
    $after,
    static fn(array $row): bool => (int) $row['occurrence_no'] === 1 && (int) $row['resource_id'] === $resources[1]
))[0];
$result = $service->cancel((int) $otherTarget['id'], $manager, 'future');
$query->execute(['group_id' => $created['group_id']]);
$remaining = $query->fetchAll();
$cabins->execute(['group_id' => $created['group_id']]);
if ($result['cancelled'] !== 3 || count($remaining) !== 2 || (int) $cabins->fetchColumn() !== 1) {
    throw new RuntimeException('Removing the last resource must remove later occurrences and their cabins.');
}

$service->cancel((int) $remaining[0]['id'], $manager);
$service->cancel((int) $remaining[1]['id'], $manager, 'future');
$group = $pdo->prepare('SELECT COUNT(*) FROM booking_groups WHERE id = :group_id');
$group->execute(['group_id' => $created['group_id']]);
if ((int) $group->fetchColumn() !== 0) {
    throw new RuntimeException('Removing the last assignment must remove the empty series.');
}
echo "Future series cancellation checks passed.\n";
