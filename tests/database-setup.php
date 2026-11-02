<?php
declare(strict_types=1);

use App\BookingTypeService;
use App\Application;
use App\Config;
use App\Database;
use App\DatabaseSetup;
use App\Security;
use App\SetupWizard;

require dirname(__DIR__) . '/www/vendor/autoload.php';

$config = Config::fromEnvironment();
if ($config->get('DB_NAME') !== 'vflbuchung_setup_test') {
    throw new RuntimeException('Run this test only against the isolated vflbuchung_setup_test database.');
}

$pdo = Database::connect($config);
$setup = new DatabaseSetup($pdo);
$scenario = $argv[1] ?? '';
if ($scenario !== '') {
    Security::startSession();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['_csrf' => Security::csrfToken()];
    if ($scenario === '--wizard-database') {
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
            $_POST[$key] = $config->get($key);
        }
        $_GET = ['action' => 'database-connection-verify'];
        register_shutdown_function(static function (): void {
            echo json_encode([
                'database_verified' => $_SESSION['_setup_database_verified'] ?? false,
                'configuration_verified' => $_SESSION['_database_connection_verified'] ?? false,
                'mail_tested' => isset($_SESSION['_database_connection_mail_recipient']),
            ], JSON_THROW_ON_ERROR);
            session_destroy();
        });
        (new SetupWizard($config, null, true))->run();
        throw new RuntimeException('The first database step did not redirect to mail configuration.');
    }
    if ($scenario !== '--create-admin') {
        throw new RuntimeException('Unknown isolated setup test scenario.');
    }
    $_POST += [
        'email' => 'setup-admin@example.test',
        'username' => 'setup-admin',
        'first_name' => 'Setup',
        'last_name' => 'Admin',
        'password' => 'SetupTestPassword123!',
        'password_confirmation' => 'SetupTestPassword123!',
        'schema_action' => 'keep',
    ];
    register_shutdown_function(static function () use ($pdo): void {
        echo json_encode([
            'admin_count' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn(),
            'logged_in' => isset($_SESSION['user_id']),
            'booking_count' => (int) $pdo->query('SELECT COUNT(*) FROM booking_occurrences')->fetchColumn(),
            'flash_type' => $_SESSION['_flash']['type'] ?? null,
        ], JSON_THROW_ON_ERROR);
        session_destroy();
    });
    (new ReflectionMethod(Application::class, 'handleAction'))->invoke(new Application($config, $pdo), 'setup');
    throw new RuntimeException('Successful administrator creation did not redirect.');
}

$runScenario = static function (string $scenario): array {
    $process = proc_open([PHP_BINARY, __FILE__, $scenario], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the isolated setup scenario.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Isolated setup scenario failed: ' . $errors);
    }
    return json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
};
$connectionResult = $runScenario('--wizard-database');
if ($connectionResult !== ['database_verified' => true, 'configuration_verified' => false, 'mail_tested' => false]) {
    throw new RuntimeException('The database connection must be verified before any mail configuration is required.');
}

Security::startSession();
$_POST = [
    '_csrf' => Security::csrfToken(),
    'email' => 'setup-admin@example.test',
    'username' => 'setup-admin',
    'first_name' => 'Setup',
    'last_name' => 'Admin',
    'password' => 'SetupTestPassword123!',
    'schema_action' => 'keep',
];
$application = new Application($config, $pdo);
$setupAction = new ReflectionMethod(Application::class, 'handleAction');
foreach ([null, 'DifferentPassword123!', 'setuptestpassword123!', 'SetupTestPassword123! '] as $confirmation) {
    $_POST['password_confirmation'] = $confirmation;
    try {
        $setupAction->invoke($application, 'setup');
        throw new LogicException('A missing or mismatched administrator password confirmation was accepted.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'Die beiden Passwörter stimmen nicht überein.') {
            throw $exception;
        }
    }
    if ($setup->status()['existing_table_count'] !== 0) {
        throw new RuntimeException('Mismatched passwords must be rejected before creating application tables.');
    }
}
$setup->initialize(false);

$status = $setup->status();
if (!$status['schema_complete']) {
    throw new RuntimeException('The setup did not create the complete application schema.');
}
foreach (['source_key', 'destination', 'purpose', 'driver_name'] as $column) {
    if ($pdo->query("SHOW COLUMNS FROM booking_occurrences LIKE '$column'")->fetch() !== false) {
        throw new RuntimeException("The removed column $column must not exist in new installations.");
    }
}
$setupPage = App\Views::setup('csrf-test', '', $status, true);
if (!str_contains($setupPage, 'id="setup-recreate"')
    || !str_contains($setupPage, 'setup-confirm-recreate')
    || str_contains($setupPage, 'seed_example_bookings')
    || !str_contains($setupPage, 'Das aktuelle Datenbankschema wurde erkannt')
    || str_contains($setupPage, 'Setup-Schlüssel')) {
    throw new RuntimeException('A current database must offer an explicit rebuild without a setup-key prompt.');
}
if (!str_contains($setupPage, 'Schritt 3 von 3: Erstes Administratorkonto')
    || !str_contains($setupPage, 'name="password_confirmation"')) {
    throw new RuntimeException('Administrator setup must be the third step and require password confirmation.');
}
foreach (['password', 'password_confirmation'] as $field) {
    if (preg_match('/<input[^>]*name="' . $field . '"[^>]*>/', $setupPage, $input) !== 1
        || !str_contains($input[0], 'type="password"')
        || !str_contains($input[0], 'autocomplete="new-password"')
        || !str_contains($input[0], 'required')
        || str_contains($input[0], 'value=')) {
        throw new RuntimeException('Both administrator password fields must be required and must not echo a password.');
    }
}
$pdo->exec(
    "INSERT INTO users (username, first_name, role, status)
     VALUES ('password-mismatch-retain-test', 'Setup', 'trainer', 'active')"
);
$_POST['schema_action'] = 'recreate';
$_POST['confirm_recreate'] = '1';
$_POST['password_confirmation'] = 'DifferentPassword123!';
try {
    $setupAction->invoke($application, 'setup');
    throw new LogicException('Mismatched passwords were accepted for a schema rebuild.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'Die beiden Passwörter stimmen nicht überein.') {
        throw $exception;
    }
}
if ((int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'password-mismatch-retain-test'")->fetchColumn() !== 1) {
    throw new RuntimeException('Mismatched passwords must be rejected before deleting existing data.');
}
$adminResult = $runScenario('--create-admin');
if ($adminResult !== ['admin_count' => 1, 'logged_in' => true, 'booking_count' => 0, 'flash_type' => 'success']) {
    throw new RuntimeException('The setup must create the first administrator without importing example bookings.');
}
session_destroy();

$pdo->exec("INSERT INTO booking_groups (created_by) SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$groupId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO booking_occurrences (group_id, event_type, title, private_notes, starts_at, ends_at, status)
    VALUES ($groupId, 'bus', 'Migration booking', 'Retained note', '2026-11-02 12:00', '2026-11-02 13:00', 'confirmed')");
$occurrenceId = (int) $pdo->lastInsertId();
$beforeMigration = $pdo->query("SELECT * FROM booking_occurrences WHERE id = $occurrenceId")->fetch();
$pdo->exec('ALTER TABLE booking_occurrences
    ADD COLUMN source_key VARCHAR(180) NULL,
    ADD UNIQUE KEY uq_booking_occurrences_source_key (source_key),
    ADD COLUMN destination VARCHAR(180) NULL,
    ADD COLUMN purpose VARCHAR(180) NULL,
    ADD COLUMN driver_name VARCHAR(120) NULL');
$pdo->exec("UPDATE booking_occurrences SET source_key = 'legacy-example', destination = 'Destination',
    purpose = 'Purpose', driver_name = 'Driver' WHERE id = $occurrenceId");
$setup->migrate();
$setup->migrate();
foreach (['source_key', 'destination', 'purpose', 'driver_name'] as $column) {
    if ($pdo->query("SHOW COLUMNS FROM booking_occurrences LIKE '$column'")->fetch() !== false) {
        throw new RuntimeException("Migration did not remove $column.");
    }
}
$afterMigration = $pdo->query("SELECT * FROM booking_occurrences WHERE id = $occurrenceId")->fetch();
unset($beforeMigration['updated_at'], $afterMigration['updated_at']);
if ($beforeMigration !== $afterMigration) {
    throw new RuntimeException('Removing legacy fields must preserve bookings and internal notes.');
}

$pdo->exec("UPDATE booking_types SET name = 'Training angepasst', color = 'purple' WHERE code = 'training'");
$pdo->exec("DELETE FROM app_settings WHERE setting_key = 'external_booking_type_renamed'");
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
