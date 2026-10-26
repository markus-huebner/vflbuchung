<?php
declare(strict_types=1);

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
if ($resetUserCount !== 0 || $resourceCount === 0 || !$setup->status()['schema_complete']) {
    throw new RuntimeException('The recreate operation did not reset and rebuild the application schema.');
}

echo "Database setup test passed.\n";
