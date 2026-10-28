<?php
declare(strict_types=1);

use App\Config;
use App\DatabaseConnectionWizard;
use App\EnvironmentFile;
use App\RuntimeConfiguration;
use App\Security;

require dirname(__DIR__) . '/www/vendor/autoload.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/vflbuchung-setup-' . bin2hex(random_bytes(8));
$configPath = $directory . '/settings.env';
$environmentFilePath = $directory . '/.env';
$runtimeConfiguration = new RuntimeConfiguration($configPath);
$defaultPath = new ReflectionProperty(RuntimeConfiguration::class, 'path');
expect(
    $defaultPath->getValue(new RuntimeConfiguration()) === dirname(__DIR__) . '/www/config/.env',
    'Default runtime settings path does not match the protected config directory.'
);
expect(
    Config::environmentFilePath() === dirname(__DIR__) . '/www/config/.env',
    'The production environment file path does not match the protected config directory.'
);
$values = [
    'APP_ENV' => 'production',
    'APP_URL' => 'https://runtime.example.test',
    'DB_HOST' => 'runtime-db',
    'DB_PORT' => '3307',
    'DB_NAME' => 'runtime_database',
    'DB_USER' => 'runtime_user',
    'DB_PASSWORD' => 'runtime-password',
    'MAIL_HOST' => '',
    'MAIL_PORT' => '',
    'MAIL_USERNAME' => '',
    'MAIL_PASSWORD' => '',
    'MAIL_ENCRYPTION' => '',
    'MAIL_FROM' => 'buchung@example.test',
    'MAIL_FROM_NAME' => 'VfL Buchung',
];
$originalEnvironment = [];
foreach (EnvironmentFile::KEYS as $key) {
    $originalEnvironment[$key] = getenv($key);
    putenv($key);
}

try {
    $emptyConfig = Config::fromEnvironment($configPath, $environmentFilePath);
    expect($emptyConfig->isSetupRequired(), 'Missing configuration must require setup.');
    expect(!$emptyConfig->isLocal(), 'An unspecified environment must not imply local mode.');
    foreach (EnvironmentFile::KEYS as $key) {
        expect(!$emptyConfig->has($key), 'An unspecified setting received a default: ' . $key);
        try {
            $emptyConfig->get($key);
            throw new LogicException('Missing configuration did not raise an error: ' . $key);
        } catch (RuntimeException $exception) {
            expect(str_contains($exception->getMessage(), $key), 'Missing-setting error did not identify the key.');
        }
    }
    try {
        $runtimeConfiguration->save(array_diff_key($values, ['APP_ENV' => true]));
        throw new LogicException('Saving incomplete settings must not insert APP_ENV.');
    } catch (RuntimeException $exception) {
        expect(!is_file($configPath), 'Incomplete settings must not be persisted.');
    }
    $runtimeConfiguration->save($values);
    expect($runtimeConfiguration->load() === $values, 'Runtime settings did not round-trip.');
    expect(
        str_contains((string) file_get_contents($configPath), 'APP_ENV='),
        'Saving runtime settings did not persist the application environment.'
    );
    if (DIRECTORY_SEPARATOR === '/') {
        expect((fileperms($configPath) & 0777) === 0600, 'Runtime settings file permissions are not private.');
    }

    putenv('APP_URL=https://environment.example.test');
    putenv('DB_NAME=environment_database');
    $config = Config::fromEnvironment($configPath);
    expect($config->get('APP_URL') === $values['APP_URL'], 'Saved URL did not override the environment.');
    expect($config->get('DB_NAME') === $values['DB_NAME'], 'Saved database name did not override the environment.');

    EnvironmentFile::write($environmentFilePath, $values);
    file_put_contents($environmentFilePath,
        "APP_URL=https://dotenv.example.test\nDB_PASSWORD='p'\\''a#ss'\nMAIL_FROM=mail@example.test # comment\n",
        FILE_APPEND
    );
    putenv('APP_URL');
    putenv('DB_NAME');
    putenv('DB_PASSWORD');
    putenv('MAIL_FROM');
    $dotenvConfig = Config::fromEnvironment($directory . '/missing-config.json', $environmentFilePath);
    expect($dotenvConfig->get('APP_URL') === 'https://dotenv.example.test', 'The config .env file was not loaded.');
    expect($dotenvConfig->get('DB_PASSWORD') === "p'a#ss", 'Quoted .env credentials were not parsed correctly.');
    expect($dotenvConfig->get('MAIL_FROM') === 'mail@example.test', 'A trailing .env comment was not ignored.');
    expect(!$dotenvConfig->isSetupRequired(), 'A complete explicit configuration incorrectly required setup.');
    foreach (EnvironmentFile::KEYS as $key) {
        EnvironmentFile::write($environmentFilePath, array_diff_key($values, [$key => true]));
        expect(
            Config::fromEnvironment($directory . '/missing-runtime.env', $environmentFilePath)->isSetupRequired(),
            'A missing setting must require setup, including optional settings: ' . $key
        );
    }
    foreach (['APP_ENV', 'APP_URL', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'MAIL_FROM', 'MAIL_FROM_NAME'] as $key) {
        EnvironmentFile::write($environmentFilePath, array_replace($values, [$key => '']));
        expect(
            Config::fromEnvironment($directory . '/missing-runtime.env', $environmentFilePath)->isSetupRequired(),
            'An empty required setting must require setup: ' . $key
        );
    }
    foreach ([
        ['APP_ENV' => 'invalid'],
        ['DB_PORT' => '0'],
        ['DB_PORT' => 'invalid'],
        ['MAIL_HOST' => 'smtp.example.test', 'MAIL_PORT' => ''],
        ['MAIL_PORT' => '65536'],
        ['MAIL_ENCRYPTION' => 'invalid'],
        ['MAIL_USERNAME' => 'mailer', 'MAIL_PASSWORD' => ''],
    ] as $invalidValues) {
        EnvironmentFile::write($environmentFilePath, array_replace($values, $invalidValues));
        expect(
            Config::fromEnvironment($directory . '/missing-runtime.env', $environmentFilePath)->isSetupRequired(),
            'Invalid settings must require setup rather than use implicit connection defaults.'
        );
    }
    EnvironmentFile::write($environmentFilePath, $values);
    $missingProductionConfig = Config::fromEnvironment(
        $directory . '/missing-runtime.env',
        $directory . '/missing-production.env'
    )->withOverrides(['APP_ENV' => 'production']);
    expect($missingProductionConfig->isSetupRequired(), 'A missing production .env file did not require setup.');
    expect(
        !$dotenvConfig->withOverrides(['APP_ENV' => 'production'])->isSetupRequired(),
        'An existing production .env file incorrectly required setup.'
    );
    putenv('APP_URL=https://environment.example.test');
    expect(
        Config::fromEnvironment($directory . '/missing-config.json', $environmentFilePath)->get('APP_URL')
            === 'https://environment.example.test',
        'Process environment values must take precedence over the .env file.'
    );

    Security::startSession();
    $wizard = new DatabaseConnectionWizard($emptyConfig, null, true);
    $validate = new ReflectionMethod(DatabaseConnectionWizard::class, 'validateApplicationValues');
    $applicationValues = array_diff_key($values, array_flip(['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD']));
    expect($validate->invoke($wizard, $applicationValues) === $applicationValues, 'Explicit empty SMTP settings were changed.');
    $smtpValues = array_replace($applicationValues, [
        'APP_ENV' => 'local',
        'MAIL_HOST' => 'smtp.example.test',
        'MAIL_PORT' => '2525',
        'MAIL_USERNAME' => 'mailer',
        'MAIL_PASSWORD' => 'smtp-secret',
        'MAIL_ENCRYPTION' => 'tls',
    ]);
    expect($validate->invoke($wizard, $smtpValues) === $smtpValues, 'Explicit SMTP settings were changed.');
    foreach ([
        ['APP_ENV' => ''],
        ['APP_ENV' => 'invalid'],
        ['MAIL_HOST' => 'smtp.example.test', 'MAIL_PORT' => ''],
        ['MAIL_PORT' => '65536'],
        ['MAIL_ENCRYPTION' => 'invalid'],
        ['MAIL_USERNAME' => 'mailer', 'MAIL_PASSWORD' => ''],
    ] as $invalidValues) {
        try {
            $validate->invoke($wizard, array_replace($applicationValues, $invalidValues));
            throw new LogicException('Invalid application settings were accepted.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage() !== '', 'Invalid settings must report an explicit error.');
        }
    }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    ob_start();
    $wizard->run();
    $emptyHtml = (string) ob_get_clean();
    foreach (['APP_ENV', 'APP_URL', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_ENCRYPTION', 'MAIL_FROM', 'MAIL_FROM_NAME'] as $key) {
        expect(str_contains($emptyHtml, 'name="' . $key . '"'), 'The setup form is missing ' . $key);
    }
    expect(!str_contains($emptyHtml, ' selected'), 'The empty setup form selected a default.');
    $_SESSION['_database_connection_authorized'] = true;
    $_GET['step'] = 'database';
    ob_start();
    $wizard->run();
    $databaseHtml = (string) ob_get_clean();
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER'] as $key) {
        expect(str_contains($databaseHtml, 'name="' . $key . '" value=""'), 'The empty database form supplied a default: ' . $key);
    }
    $_GET = [];
    unset($_SESSION['_database_connection_authorized']);
    ob_start();
    (new DatabaseConnectionWizard($missingProductionConfig, null, true))->run();
    $setupHtml = (string) ob_get_clean();
    expect(str_contains($setupHtml, 'System einrichten'), 'A missing production .env did not render the setup wizard.');

    ob_start();
    (new DatabaseConnectionWizard($config, new PDOException('simulated database outage')))->run();
    $html = (string) ob_get_clean();
    expect(str_contains($html, 'Datenbankverbindung einrichten'), 'Database outage did not render the connection wizard.');
    expect(str_contains($html, 'Schritt 1 von 2'), 'The wizard did not start at its first step.');
    expect(!str_contains($html, 'Setup-Schlüssel'), 'The wizard still requires a setup token.');
    expect(!str_contains($html, 'name="DB_PASSWORD"'), 'Database credentials appeared before the second step.');
    $review = new ReflectionMethod(DatabaseConnectionWizard::class, 'review');
    $_SESSION['_database_connection_setup'] = array_replace($values, $smtpValues);
    $reviewHtml = $review->invoke($wizard);
    expect(str_contains($reviewHtml, 'smtp.example.test'), 'The review omitted SMTP configuration.');
    expect(!str_contains($reviewHtml, 'smtp-secret'), 'The review exposed the SMTP password.');
    expect(!str_contains($reviewHtml, 'runtime-password'), 'The review exposed the database password.');
    expect(str_contains($reviewHtml, 'Mail-Server einschließlich Anmeldung wurde erfolgreich getestet'), 'The review omitted the SMTP test result.');
    $_SESSION['_database_connection_setup'] = $values;
    expect(str_contains($review->invoke($wizard), 'Mail-Server-Prüfung wurde übersprungen'), 'Disabled mail must not be reported as tested.');

    $_SESSION['_database_connection_setup'] = $values;
    $_SESSION['_database_connection_authorized'] = true;
    $_SESSION['_database_connection_verified'] = true;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['action' => 'database-connection-app'];
    $_POST = array_replace($smtpValues, [
        '_csrf' => Security::csrfToken(),
        'MAIL_HOST' => '127.0.0.1',
        'MAIL_PORT' => '1',
    ]);
    $savedContents = file_get_contents($configPath);
    ob_start();
    $wizard->run();
    $mailErrorHtml = (string) ob_get_clean();
    expect(str_contains($mailErrorHtml, 'Verbindung zum Mail-Server ist fehlgeschlagen'), 'An unreachable SMTP server must fail setup.');
    expect(str_contains($mailErrorHtml, 'name="MAIL_HOST" value="127.0.0.1"'), 'Failed SMTP settings were lost.');
    expect(str_contains($mailErrorHtml, 'name="MAIL_PORT" value="1"'), 'Failed SMTP port was lost.');
    expect($_SESSION['_database_connection_setup']['MAIL_PASSWORD'] === 'smtp-secret', 'Failed SMTP password was lost.');
    expect(!isset($_SESSION['_database_connection_authorized']) && !isset($_SESSION['_database_connection_verified']), 'Changed SMTP settings retained stale verification.');
    expect(file_get_contents($configPath) === $savedContents, 'Failed SMTP verification changed saved settings.');

    $_POST['MAIL_PASSWORD'] = '';
    ob_start();
    $wizard->run();
    ob_end_clean();
    expect($_SESSION['_database_connection_setup']['MAIL_PASSWORD'] === 'smtp-secret', 'Retrying SMTP verification lost the password.');

    $_SESSION['_database_connection_authorized'] = true;
    $_SESSION['_database_connection_verified'] = true;
    $_GET = ['action' => 'database-connection-verify'];
    $_POST = [
        '_csrf' => Security::csrfToken(),
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '1',
        'DB_NAME' => 'retry_database',
        'DB_USER' => 'retry_user',
        'DB_PASSWORD' => 'retry-password',
    ];
    ob_start();
    $wizard->run();
    $databaseErrorHtml = (string) ob_get_clean();
    expect(str_contains($databaseErrorHtml, 'Die Datenbankverbindung ist fehlgeschlagen'), 'An unreachable database must fail setup.');
    expect(str_contains($databaseErrorHtml, 'name="DB_NAME" value="retry_database"'), 'Failed database settings were lost.');
    expect($_SESSION['_database_connection_setup']['DB_PASSWORD'] === 'retry-password', 'Failed database password was lost.');
    expect($_SESSION['_database_connection_setup']['MAIL_PASSWORD'] === 'smtp-secret', 'Database failure lost SMTP settings.');
    expect(!isset($_SESSION['_database_connection_verified']), 'Changed database settings retained stale verification.');
    expect(file_get_contents($configPath) === $savedContents, 'Failed database verification changed saved settings.');

    $_POST['DB_PASSWORD'] = '';
    ob_start();
    $wizard->run();
    ob_end_clean();
    expect($_SESSION['_database_connection_setup']['DB_PASSWORD'] === 'retry-password', 'Retrying database verification lost the password.');

    echo "Database connection setup test passed.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    if (is_file($configPath)) {
        unlink($configPath);
    }
    if (is_file($environmentFilePath)) {
        unlink($environmentFilePath);
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }
    foreach ($originalEnvironment as $key => $value) {
        putenv($value === false ? $key : $key . '=' . $value);
    }
}
