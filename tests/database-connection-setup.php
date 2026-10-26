<?php
declare(strict_types=1);

use App\Config;
use App\DatabaseConnectionWizard;
use App\RuntimeConfiguration;
use App\Security;

require dirname(__DIR__) . '/vendor/autoload.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/vflbuchung-setup-' . bin2hex(random_bytes(8));
$configPath = $directory . '/setup-config.json';
$runtimeConfiguration = new RuntimeConfiguration($configPath);
$defaultPath = new ReflectionProperty(RuntimeConfiguration::class, 'path');
expect(
    $defaultPath->getValue(new RuntimeConfiguration()) === dirname(__DIR__) . '/storage/setup-config.json',
    'Default runtime settings path does not match the Docker storage mount.'
);
$values = [
    'APP_URL' => 'https://runtime.example.test',
    'DB_HOST' => 'runtime-db',
    'DB_PORT' => '3307',
    'DB_NAME' => 'runtime_database',
    'DB_USER' => 'runtime_user',
    'DB_PASSWORD' => 'runtime-password',
    'MAIL_FROM' => 'buchung@example.test',
    'MAIL_FROM_NAME' => 'VfL Buchung',
];

try {
    $runtimeConfiguration->save($values);
    expect($runtimeConfiguration->load() === $values, 'Runtime settings did not round-trip.');
    if (DIRECTORY_SEPARATOR === '/') {
        expect((fileperms($configPath) & 0777) === 0600, 'Runtime settings file permissions are not private.');
    }

    putenv('APP_URL=https://environment.example.test');
    putenv('DB_NAME=environment_database');
    $config = Config::fromEnvironment($configPath);
    expect($config->get('APP_URL') === $values['APP_URL'], 'Saved URL did not override the environment.');
    expect($config->get('DB_NAME') === $values['DB_NAME'], 'Saved database name did not override the environment.');
    Security::startSession();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    ob_start();
    (new DatabaseConnectionWizard($config, new PDOException('simulated database outage')))->run();
    $html = (string) ob_get_clean();
    expect(str_contains($html, 'Datenbankverbindung einrichten'), 'Database outage did not render the connection wizard.');
    expect(str_contains($html, 'Schritt 1 von 2'), 'The wizard did not start at its first step.');
    expect(!str_contains($html, 'Setup-Schlüssel'), 'The wizard still requires a setup token.');
    expect(!str_contains($html, 'name="DB_PASSWORD"'), 'Database credentials appeared before the second step.');

    echo "Database connection setup test passed.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    if (is_file($configPath)) {
        unlink($configPath);
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }
    putenv('APP_URL');
    putenv('DB_NAME');
}
