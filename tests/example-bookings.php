<?php
declare(strict_types=1);

use App\Config;
use App\Database;
use App\DatabaseSetup;

require getcwd() . '/www/vendor/autoload.php';

$config = Config::fromEnvironment()->withOverrides([
    'DB_HOST' => (string) getenv('DB_HOST'),
    'DB_PORT' => (string) getenv('DB_PORT'),
    'DB_NAME' => (string) getenv('DB_NAME'),
    'DB_USER' => (string) getenv('DB_USER'),
    'DB_PASSWORD' => (string) getenv('DB_PASSWORD'),
]);
if (!preg_match('/^vflbuchung_test_[a-z0-9]+$/', $config->get('DB_NAME'))) {
    throw new RuntimeException('Run this test only against a dedicated vflbuchung_test_* database.');
}
$pdo = Database::connect($config);
$directory = getcwd() . '/example-database';
$setup = new DatabaseSetup($pdo, $directory);
$setup->initialize(false);
$import = static fn(): array => $setup->seedExampleBookings();
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$snapshot = static function () use ($pdo): array {
    $result = [];
    foreach (['booking_groups', 'booking_occurrences', 'booking_resource_assignments', 'users', 'resources', 'booking_types'] as $table) {
        $order = $table === 'booking_types' ? 'code' : 'id';
        $result[$table] = $pdo->query("SELECT * FROM $table ORDER BY $order")->fetchAll();
    }
    return $result;
};
$fails = static function (string $expected, ?callable $operation = null) use ($pdo, $import, $check, $snapshot): void {
    $before = $snapshot();
    try {
        ($operation ?? $import)();
    } catch (RuntimeException $error) {
        $check(str_contains($error->getMessage(), $expected), 'Unexpected import error: ' . $error->getMessage());
        $check(!$pdo->inTransaction(), 'Failed import left an open transaction.');
        $check($snapshot() === $before, 'Failed import changed existing data or left partial bookings.');
        return;
    }
    throw new RuntimeException('Expected import to fail: ' . $expected);
};

foreach (['source_key', 'destination', 'purpose', 'driver_name'] as $column) {
    $check($pdo->query("SHOW COLUMNS FROM booking_occurrences LIKE '$column'")->fetch() === false,
        "The import must work without the removed column $column.");
}
$fails('active administrator');
$pdo->exec("INSERT INTO users (username, first_name, role, status) VALUES ('example-test-admin', 'Test', 'admin', 'active')");
$pdo->exec("UPDATE booking_types SET is_active = 0 WHERE code = 'match'");
$fails('Active booking types');
$pdo->exec("UPDATE booking_types SET is_active = 1 WHERE code = 'match'");
$pdo->exec("UPDATE resources SET is_active = 0 WHERE name = 'Platz 5'");
$fails('seven active default pitches');
$pdo->exec("UPDATE resources SET is_active = 1 WHERE name = 'Platz 5'");

foreach (['pending', 'confirmed', 'rejected', 'withdrawn'] as $status) {
    $pdo->exec('INSERT INTO booking_groups (created_by) SELECT id FROM users');
    $groupId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO booking_occurrences (group_id, event_type, title, private_notes, starts_at, ends_at, status)
        VALUES ($groupId, 'training', 'Existing booking', 'Retained note', '2026-10-05 13:00', '2026-10-05 14:00', '$status')");
    $fails('leerem Buchungsbestand');
    $pdo->exec("DELETE FROM booking_groups WHERE id = $groupId");
}
$pdo->exec('INSERT INTO booking_groups (created_by) SELECT id FROM users');
$fails('leerem Buchungsbestand');
$pdo->exec('DELETE FROM booking_groups');

$sql = file_get_contents($directory . '/seed_example_bookings.sql');
$check(is_string($sql), 'The source snapshot could not be read.');
$failureDirectory = sys_get_temp_dir() . '/vfl-example-' . bin2hex(random_bytes(8));
$check(mkdir($failureDirectory, 0700), 'Could not create an isolated failure fixture.');
try {
    $failureSql = str_replace(
        'SET inserted_count = inserted_count + 1;',
        "IF inserted_count = 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated import failure'; END IF;
        SET inserted_count = inserted_count + 1;",
        $sql,
        $replacementCount
    );
    $check($replacementCount === 1, 'The late-failure fixture must inject exactly one failure.');
    $check(file_put_contents($failureDirectory . '/seed_example_bookings.sql', $failureSql) !== false, 'Could not write the failure fixture.');
    $failureSetup = new DatabaseSetup($pdo, $failureDirectory);
    $fails('Simulated import failure', static fn(): array => $failureSetup->seedExampleBookings());
} finally {
    if (is_file($failureDirectory . '/seed_example_bookings.sql')) {
        unlink($failureDirectory . '/seed_example_bookings.sql');
    }
    rmdir($failureDirectory);
}

$otherConnection = Database::connect($config);
$otherConnection->beginTransaction();
$otherConnection->query('SELECT id FROM resources ORDER BY id FOR UPDATE')->fetchAll();
$lockTimeout = (int) $pdo->query('SELECT @@innodb_lock_wait_timeout')->fetchColumn();
$pdo->exec('SET innodb_lock_wait_timeout = 1');
try {
    $fails('Lock wait timeout');
} finally {
    $otherConnection->rollBack();
    $pdo->exec('SET innodb_lock_wait_timeout = ' . $lockTimeout);
}

preg_match_all(
    "/\\('([^']+)', '([ABC])', '((?:[^']|'')*)', '([^']+)', '([^']+)', '((?:[^']|'')*)', [01]\\)/u",
    $sql,
    $sourceRows,
    PREG_SET_ORDER
);
$check(count($sourceRows) === 437, 'The captured source snapshot must have 437 entries.');
$sourceTypes = ['A' => 'training', 'B' => 'match', 'C' => 'external'];
$sourceFingerprint = [];
$expected = [];
foreach ($sourceRows as [, $marker, $sourceType, $title, $start, $end, $pitch]) {
    $title = str_replace("''", "'", $title);
    $pitch = str_replace("''", "'", $pitch);
    $sourceFingerprint[$marker] = implode("\t", [$marker, $sourceTypes[$sourceType], $title, $start, $end, $pitch]);
    $expected[] = implode("\t", [$title === 'TuL' ? 'external' : $sourceTypes[$sourceType], $title, $start, $end, $pitch]);
}
ksort($sourceFingerprint, SORT_STRING);
$check(hash('sha256', implode("\n", $sourceFingerprint)) === '02eb13049be8d8f958f0d5d4714b8da06cedd23202a6bb7b1b22f326562ce005',
    'The captured original titles, dates, types, pitch names or times were changed.');
$summary = $import();
$check($summary === ['planned_bookings' => 437, 'inserted_bookings' => 437], 'Incorrect import summary.');
$rows = $pdo->query("SELECT o.*, g.created_by, g.requested_by, g.recurrence_rule,
    (SELECT GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR '|')
        FROM booking_resource_assignments a JOIN resources r ON r.id = a.resource_id
        WHERE a.occurrence_id = o.id) AS pitches
    FROM booking_occurrences o JOIN booking_groups g ON g.id = o.group_id")->fetchAll();
$zone = new DateTimeZone('Europe/Berlin');
$utc = new DateTimeZone('UTC');
$counts = ['training' => 0, 'match' => 0, 'external' => 0];
$months = ['2026-10' => 0, '2026-11' => 0, '2026-12' => 0, '2027-01' => 0, '2027-02' => 0, '2027-03' => 0];
$actual = [];
foreach ($rows as $row) {
    $start = (new DateTimeImmutable($row['starts_at'], $utc))->setTimezone($zone);
    $end = (new DateTimeImmutable($row['ends_at'], $utc))->setTimezone($zone);
    $check($start < $end && $start->format('Y-m-d') === $end->format('Y-m-d'), 'Invalid source interval.');
    $check($row['status'] === 'confirmed', 'Example bookings must be confirmed.');
    $check($row['requested_by'] === null && $row['created_by'] !== null, 'Examples must be direct bookings.');
    $check($row['recurrence_rule'] === null, 'Source series markers must not invent recurrence rules.');
    $check($row['team_name'] === null && $row['private_notes'] === null, 'Retain the original title and empty private notes.');
    $actual[] = implode("\t", [$row['event_type'], $row['title'], $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $row['pitches']]);
    $counts[$row['event_type']]++;
    $months[$start->format('Y-m')]++;
}
sort($expected, SORT_STRING);
sort($actual, SORT_STRING);
$check($actual === $expected, 'Imported bookings differ from the captured public data, including UTC conversion.');
$check($counts === ['training' => 311, 'match' => 92, 'external' => 34], 'Incorrect booking type totals.');
$check($months === ['2026-10' => 207, '2026-11' => 156, '2026-12' => 10, '2027-01' => 0, '2027-02' => 36, '2027-03' => 28], 'Incorrect month coverage.');
$check((int) $pdo->query('SELECT COUNT(*) FROM booking_groups')->fetchColumn() === 437, 'Incorrect booking group count.');
$check((int) $pdo->query('SELECT COUNT(*) FROM booking_resource_assignments')->fetchColumn() === 437, 'Incorrect pitch assignment count.');
$check((int) $pdo->query("SELECT COUNT(*) FROM booking_resource_assignments WHERE status <> 'confirmed'")->fetchColumn() === 0, 'All assignments must be confirmed.');
$fails('leerem Buchungsbestand');
$check((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'Import must not create user accounts.');
echo "Example import: exact source snapshot, current schema, empty-only guard, rollback, resource locking and repeat rejection passed.\n";
