<?php
declare(strict_types=1);

use App\BookingTypeService;
use App\Config;
use App\Database;
use App\DatabaseSetup;

require dirname(__DIR__) . '/www/vendor/autoload.php';

$config = Config::fromEnvironment();
if ($config->get('DB_NAME') !== 'vflbuchung_setup_test') {
    throw new RuntimeException('Run this test only against the isolated vflbuchung_setup_test database.');
}

$pdo = Database::connect($config);
$setup = new DatabaseSetup($pdo);
$setup->initialize(false);

$status = $setup->status();
if (!$status['schema_complete']) {
    throw new RuntimeException('The setup did not create the complete application schema.');
}
$setupPage = App\Views::setup('csrf-test', '', $status, true);
if (!str_contains($setupPage, 'id="setup-recreate"')
    || !str_contains($setupPage, 'setup-confirm-recreate')
    || !str_contains($setupPage, 'Das aktuelle Datenbankschema wurde erkannt')
    || str_contains($setupPage, 'Setup-Schlüssel')) {
    throw new RuntimeException('A current database must offer an explicit rebuild without a setup-key prompt.');
}
$auth = new App\AuthService($pdo);
$auth->createInitialAdmin(
    'setup-admin@example.test',
    'setup-admin',
    'Setup',
    'Admin',
    'SetupTestPassword123!'
);

$pdo->exec("UPDATE booking_types SET name = 'Training angepasst', color = 'purple' WHERE code = 'training'");
$pdo->exec("UPDATE booking_types SET name = 'Externe Belegung' WHERE code = 'external'");
$pdo->exec('ALTER TABLE booking_types DROP COLUMN trainer_requestable');
$setup->initialize(false);
$migratedTraining = $pdo->query(
    "SELECT name, color, trainer_requestable FROM booking_types WHERE code = 'training'"
)->fetch();
$migratedExternalPermission = $pdo->query(
    "SELECT trainer_requestable FROM booking_types WHERE code = 'external'"
)->fetchColumn();
$migratedExternalName = $pdo->query(
    "SELECT name FROM booking_types WHERE code = 'external'"
)->fetchColumn();
if ($migratedTraining['name'] !== 'Training angepasst'
    || $migratedTraining['color'] !== 'purple'
    || (int) $migratedTraining['trainer_requestable'] !== 1
    || (int) $migratedExternalPermission !== 0
    || $migratedExternalName !== 'Extern') {
    throw new RuntimeException('The schema migration must preserve booking types and apply compatible trainer defaults.');
}
$pdo->exec("UPDATE booking_types SET name = 'Externe Belegung' WHERE code = 'external'");
$setup->initialize(false);
if ($pdo->query("SELECT name FROM booking_types WHERE code = 'external'")->fetchColumn() !== 'Externe Belegung') {
    throw new RuntimeException('The booking type migration must not overwrite a later custom name.');
}

$bookingTypeService = new BookingTypeService($pdo);
$loadedTraining = array_values(array_filter(
    $bookingTypeService->all(),
    static fn(array $type): bool => $type['code'] === 'training'
))[0];
if (!isset(BookingTypeService::palette()[$loadedTraining['color']])) {
    throw new RuntimeException('Legacy booking colors must resolve to a color from the new palette.');
}
$restrictedTypeCode = $bookingTypeService->save('Nur für Admins', 'red', null, false);
$setup->initialize(false);
$restrictedTypePermission = $pdo->prepare(
    'SELECT trainer_requestable FROM booking_types WHERE code = :code'
);
$restrictedTypePermission->execute(['code' => $restrictedTypeCode]);
if ((int) $restrictedTypePermission->fetchColumn() !== 0) {
    throw new RuntimeException('The setup must preserve custom trainer permissions.');
}

$pdo->exec(
    "INSERT INTO users (username, first_name, role, status)
     VALUES ('setup-retain-test', 'Setup', 'trainer', 'active')"
);
$setup->initialize(false);
$retainedUserCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE username = 'setup-retain-test'"
)->fetchColumn();
if ($retainedUserCount !== 1) {
    throw new RuntimeException('The keep operation did not preserve existing user data.');
}

$setup->initialize(true);
$resetUserCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$resourceCount = (int) $pdo->query('SELECT COUNT(*) FROM resources')->fetchColumn();
$defaultTrainerPermissions = array_map('intval', $pdo->query(
    "SELECT code, trainer_requestable FROM booking_types ORDER BY code"
)->fetchAll(PDO::FETCH_KEY_PAIR));
if ($resetUserCount !== 0
    || $resourceCount === 0
    || !$setup->status()['schema_complete']
    || $defaultTrainerPermissions !== ['external' => 0, 'match' => 1, 'training' => 1]) {
    throw new RuntimeException('The recreate operation did not reset and rebuild the application schema.');
}

echo "Database setup test passed.\n";
