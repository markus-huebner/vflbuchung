<?php
declare(strict_types=1);

use App\Config;
use App\DatabaseConnectionWizard;
use App\Mailer;
use App\Security;

require dirname(__DIR__) . '/www/vendor/autoload.php';

if (($argv[1] ?? '') === '--smtp-server') {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($server === false) {
        throw new RuntimeException($errorMessage, $errorCode);
    }
    echo stream_socket_get_name($server, false) . "\n";
    flush();
    while ($client = stream_socket_accept($server, 30)) {
        stream_set_timeout($client, 5);
        fwrite($client, "220 setup-test ESMTP\r\n");
        while (($line = fgets($client)) !== false) {
            $command = trim($line);
            if (str_starts_with($command, 'EHLO')) {
                fwrite($client, "250-setup-test\r\n250 AUTH LOGIN\r\n");
            } elseif ($command === 'AUTH LOGIN') {
                fwrite($client, "334 VXNlcm5hbWU6\r\n");
                $username = base64_decode(trim((string) fgets($client)));
                fwrite($client, "334 UGFzc3dvcmQ6\r\n");
                $password = base64_decode(trim((string) fgets($client)));
                fwrite($client, $username === 'mailer' && $password === 'correct-password'
                    ? "235 Authentication successful\r\n"
                    : "535 Authentication failed\r\n");
            } elseif ($command === 'QUIT') {
                fwrite($client, "221 Bye\r\n");
                break;
            } else {
                echo $command . "\n";
                flush();
                fwrite($client, "502 Command not supported\r\n");
            }
        }
        fclose($client);
    }
    fclose($server);
    exit;
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$process = proc_open([PHP_BINARY, __FILE__, '--smtp-server'], [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the SMTP fixture.');
}

try {
    stream_set_timeout($pipes[1], 5);
    $address = trim((string) fgets($pipes[1]));
    expect(str_starts_with($address, '127.0.0.1:'), 'The SMTP fixture did not start.');
    $port = substr($address, strrpos($address, ':') + 1);
    $config = Config::fromEnvironment()->withOverrides([
        'MAIL_HOST' => '127.0.0.1',
        'MAIL_PORT' => $port,
        'MAIL_USERNAME' => 'mailer',
        'MAIL_PASSWORD' => 'correct-password',
        'MAIL_ENCRYPTION' => '',
        'MAIL_FROM' => 'setup@example.test',
        'MAIL_FROM_NAME' => 'Setup Test',
    ]);

    (new Mailer($config))->testConnection();
    (new Mailer($config->withOverrides(['MAIL_USERNAME' => '', 'MAIL_PASSWORD' => ''])))->testConnection();
    foreach ([
        ['MAIL_PASSWORD' => 'wrong-password'],
        ['MAIL_ENCRYPTION' => 'tls'],
    ] as $invalidValues) {
        try {
            (new Mailer($config->withOverrides($invalidValues)))->testConnection();
            throw new LogicException('Invalid SMTP settings were accepted.');
        } catch (RuntimeException $exception) {
            expect(str_contains($exception->getMessage(), 'Verbindung zum Mail-Server ist fehlgeschlagen'), 'SMTP failure did not report an actionable error.');
        }
    }

    $configure = new ReflectionMethod(Mailer::class, 'configuredMailer');
    foreach (['tls', 'ssl'] as $encryption) {
        $mail = $configure->invoke(new Mailer($config->withOverrides(['MAIL_ENCRYPTION' => $encryption])));
        expect($mail->SMTPSecure === $encryption, 'The SMTP test must use the configured encryption.');
        expect($mail->SMTPAuth && $mail->Username === 'mailer', 'The SMTP test must use configured authentication.');
    }

    Security::startSession();
    $draft = [];
    foreach (App\EnvironmentFile::KEYS as $key) {
        $draft[$key] = $config->get($key);
    }
    $draft['MAIL_PASSWORD'] = 'wrong-password';
    $_SESSION['_database_connection_setup'] = $draft;
    $_SESSION['_database_connection_verified'] = true;
    $_SESSION['_database_connection_authorized'] = true;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['action' => 'database-connection-save'];
    $_POST = ['_csrf' => Security::csrfToken()];
    $path = Config::environmentFilePath();
    $before = is_file($path) ? file_get_contents($path) : null;
    ob_start();
    (new DatabaseConnectionWizard($config))->run();
    $html = (string) ob_get_clean();
    expect(str_contains($html, 'Verbindung zum Mail-Server ist fehlgeschlagen'), 'Saving must recheck SMTP after the real database connection succeeds.');
    expect(str_contains($html, 'name="MAIL_HOST" value="127.0.0.1"'), 'A failed SMTP recheck must return to the mail settings.');
    expect($_SESSION['_database_connection_setup'] === $draft, 'A failed recheck must retain all settings.');
    expect(!isset($_SESSION['_database_connection_verified']), 'A failed recheck must invalidate the review.');
    expect((is_file($path) ? file_get_contents($path) : null) === $before, 'A failed recheck must not overwrite saved settings.');

    stream_set_blocking($pipes[1], false);
    expect(!preg_match('/^(MAIL|RCPT|DATA)\b/m', (string) stream_get_contents($pipes[1])), 'The SMTP test must not send email.');
    echo "Mail setup test passed.\n";
} finally {
    proc_terminate($process);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
