<?php
declare(strict_types=1);

use App\Config;
use App\SetupWizard;
use App\Mailer;
use App\Security;

require dirname(__DIR__) . '/www/vendor/autoload.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$scenario = $argv[1] ?? '';
$directory = (string) getenv('VFL_MAIL_TEST_DIRECTORY');
if ($scenario === '--capture') {
    if (getenv('VFL_MAIL_TEST_FAIL') === '1') {
        exit(75);
    }
    expect(is_dir($directory), 'The isolated mail capture directory is missing.');
    $message = stream_get_contents(STDIN);
    expect($message !== false, 'Could not read the message handed to mail().');
    expect(
        file_put_contents($directory . '/' . bin2hex(random_bytes(8)) . '.eml', $message) !== false,
        'Could not capture the message.'
    );
    exit;
}

if ($scenario !== '') {
    $values = [
        'APP_ENV' => 'local',
        'APP_URL' => 'https://setup.example.test',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '1',
        'DB_NAME' => 'setup_test',
        'DB_USER' => 'setup_user',
        'DB_PASSWORD' => 'setup-password',
        'MAIL_FROM' => 'setup@example.test',
        'MAIL_FROM_NAME' => 'Setup Test',
        'MAIL_REPLY_TO' => 'antwort@example.test',
    ];
    if ($scenario === '--setup-success') {
        $values['MAIL_FROM_NAME'] = 'Setup <Club> & Test';
    }
    $config = Config::fromEnvironment()->withOverrides($values);
    if ($scenario === '--send-success') {
        foreach (['', 'antwort@example.test'] as $replyTo) {
            (new Mailer($config->withOverrides(['MAIL_REPLY_TO' => $replyTo])))->send(
                'recipient@example.test',
                'Grüße vom VfL',
                '<p>Grüße als HTML</p>',
                'Grüße als Text'
            );
        }
        exit;
    }
    if (in_array($scenario, ['--send-failure', '--disabled-mail', '--invalid-recipient'], true)) {
        try {
            (new Mailer($config))->send(
                $scenario === '--invalid-recipient' ? "recipient@example.test\r\nBcc: other@example.test" : 'recipient@example.test',
                'Test',
                '<p>Test</p>',
                'Test'
            );
            throw new LogicException('A failed or invalid mail request must not be reported as successful.');
        } catch (RuntimeException $exception) {
            expect(
                str_contains($exception->getMessage(), $scenario === '--disabled-mail' ? 'nicht verfügbar' : 'nicht über PHP mail()'),
                'Mail errors must be reported explicitly.'
            );
        }
        exit;
    }

    Security::startSession();
    $_SESSION['_database_connection_setup'] = $values;
    $_SESSION['_setup_database_verified'] = true;
    $_SESSION['_database_connection_verified'] = true;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['action' => 'database-connection-app'];
    $_POST = $values + [
        '_csrf' => Security::csrfToken(),
        'MAIL_TEST_RECIPIENT' => '  TEST@example.test  ',
    ];
    $path = Config::environmentFilePath();
    $before = is_file($path) ? file_get_contents($path) : null;
    register_shutdown_function(static function () use ($directory, $scenario, $path, $before): void {
        expect((is_file($path) ? file_get_contents($path) : null) === $before, 'Mail tests must not overwrite saved settings.');
        file_put_contents($directory . '/' . substr($scenario, 2) . '.json', json_encode($_SESSION, JSON_THROW_ON_ERROR));
        session_destroy();
    });
    if ($scenario === '--save-failure') {
        $_SESSION['_database_connection_mail_recipient'] = 'test@example.test';
        $_GET = ['action' => 'database-connection-save'];
    }
    if ($scenario === '--mail-before-database') {
        unset($_SESSION['_setup_database_verified']);
    }
    ob_start();
    (new SetupWizard($config))->run();
    $html = (string) ob_get_clean();
    if ($scenario === '--setup-success') {
        throw new LogicException('Successful mail setup must redirect to the database step.');
    }
    $error = match ($scenario) {
        '--save-failure' => 'Die Datenbankverbindung ist fehlgeschlagen',
        '--mail-before-database' => 'Bitte prüfe zuerst die Datenbankverbindung',
        default => 'nicht über PHP mail()',
    };
    expect(
        str_contains($html, $error),
        'Setup must report a failed mail handoff or database recheck.'
    );
    expect($_SESSION['_database_connection_setup'] === $values, 'Failed setup must retain all settings.');
    expect(!isset($_SESSION['_database_connection_verified']), 'Failed setup must invalidate the review.');
    if ($scenario === '--setup-failure') {
        expect($_SESSION['_setup_database_verified'] === true, 'A failed test mail must retain the completed database step.');
        expect(str_contains($html, 'name="MAIL_REPLY_TO" value="antwort@example.test"'), 'A failed test mail lost Reply-To.');
        expect(str_contains($html, 'name="MAIL_TEST_RECIPIENT" value="test@example.test"'), 'A failed test mail lost its recipient.');
    }
    exit;
}

expect(PHP_OS_FAMILY !== 'Windows', 'Run this test in the Linux webcontainer; it isolates PHP mail() through sendmail_path.');
$directory = sys_get_temp_dir() . '/vflbuchung-mail-' . bin2hex(random_bytes(8));
expect(mkdir($directory, 0700), 'Could not create the isolated mail test directory.');

try {
    $run = static function (string $scenario, bool $fail = false, bool $disabled = false) use ($directory): void {
        $command = [
            PHP_BINARY,
            '-d', 'sendmail_path=' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --capture',
            '-d', 'session.save_path=' . $directory,
        ];
        if ($disabled) {
            array_push($command, '-d', 'disable_functions=mail');
        }
        array_push($command, __FILE__, $scenario);
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, array_merge(getenv(), [
            'VFL_MAIL_TEST_DIRECTORY' => $directory,
            'VFL_MAIL_TEST_FAIL' => $fail ? '1' : '0',
        ]));
        expect(is_resource($process), 'Could not start the mail test scenario.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process) === 0, $scenario . ' failed: ' . $output . $errors);
    };

    $run('--send-success');
    $messages = glob($directory . '/*.eml');
    expect(count($messages) === 2, 'PHP mail() must hand off both messages.');
    $replyToCount = 0;
    foreach ($messages as $path) {
        $message = (string) file_get_contents($path);
        expect(str_contains($message, 'To: recipient@example.test'), 'The message has the wrong recipient.');
        expect(str_contains($message, 'From: Setup Test <setup@example.test>'), 'Reply-To must not change the sender.');
        expect(str_contains($message, 'multipart/alternative'), 'Messages must retain HTML and plain text alternatives.');
        $decoded = quoted_printable_decode($message);
        expect(str_contains($decoded, 'Grüße als HTML') && str_contains($decoded, 'Grüße als Text'), 'UTF-8 message bodies were lost.');
        expect(preg_match('/^Subject: (.+)$/m', $message, $subject) === 1, 'The message has no subject.');
        expect(iconv_mime_decode(trim($subject[1]), 0, 'UTF-8') === 'Grüße vom VfL', 'The UTF-8 subject was lost: ' . var_export($subject[1], true));
        if (str_contains($message, 'Reply-To:')) {
            expect(str_contains($message, 'Reply-To: antwort@example.test'), 'The Reply-To header is incorrect.');
            $replyToCount++;
        }
    }
    expect($replyToCount === 1, 'Only a nonempty Reply-To may add a reply header.');
    $run('--send-failure', true);
    $run('--disabled-mail', false, true);
    $run('--invalid-recipient');
    expect(count(glob($directory . '/*.eml')) === 2, 'Invalid or failed messages must not be delivered.');

    $run('--setup-success');
    $state = json_decode((string) file_get_contents($directory . '/setup-success.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(($state['_database_connection_verified'] ?? false) === true, 'A successful handoff must authorize configuration review.');
    expect($state['_database_connection_mail_recipient'] === 'test@example.test', 'The test recipient must be normalized and retained in the session.');
    expect(!array_key_exists('MAIL_TEST_RECIPIENT', $state['_database_connection_setup']), 'The test recipient must not enter saved configuration.');
    expect(count(glob($directory . '/*.eml')) === 3, 'Setup must send exactly one test email.');
    $testMessage = (string) file_get_contents(array_values(array_diff(glob($directory . '/*.eml'), $messages))[0]);
    expect(str_contains($testMessage, 'To: test@example.test'), 'Setup must send to the separate test recipient, not the sender.');
    $decodedTestMessage = quoted_printable_decode($testMessage);
    expect(preg_match('/<!doctype html>.*?<\/html>/s', $decodedTestMessage, $htmlPart) === 1, 'The test email must include a complete HTML layout.');
    $document = new DOMDocument();
    expect($document->loadHTML('<?xml encoding="UTF-8">' . $htmlPart[0]), 'The test email layout must be valid HTML.');
    $xpath = new DOMXPath($document);
    expect($xpath->evaluate('string(//h1)') === 'Deine Test-E-Mail ist da!', 'The test email must have a clear heading.');
    expect($xpath->query('//table[@role="presentation" and @width="600"]')->length === 1, 'The test email must use the booking email card layout.');
    expect($xpath->query('//td[contains(@style, "background:#14532d")]')->length === 1, 'The test email must use the green booking notification header.');
    expect($xpath->evaluate('string(//a[@href="https://setup.example.test/"])') === 'Zur Anwendung', 'The test email must link back to the configured application.');
    foreach (['Absender', 'Testempfänger', 'Antwortadresse', 'Versandart', 'antwort@example.test', 'PHP mail()'] as $detail) {
        expect(str_contains($document->textContent, $detail), 'The test email omitted ' . $detail);
    }
    expect(str_contains($htmlPart[0], 'Setup &lt;Club&gt; &amp; Test'), 'Mail settings must be escaped in the test email.');
    expect(str_contains(str_replace("\r\n", "\n", $decodedTestMessage), "Deine Mail-Einstellungen\nAbsender: Setup <Club> & Test"), 'The styled test email must retain its plain text alternative.');
    $run('--setup-failure', true);
    $run('--save-failure');
    $run('--mail-before-database');
    expect(count(glob($directory . '/*.eml')) === 3, 'Failed setup and save retries must not send additional messages.');
    echo "PHP mail setup test passed.\n";
} finally {
    foreach (glob($directory . '/*') as $path) {
        unlink($path);
    }
    rmdir($directory);
}
