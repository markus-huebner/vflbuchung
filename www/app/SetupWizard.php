<?php
declare(strict_types=1);

namespace App;

use PDOException;
use RuntimeException;
use Throwable;

final class SetupWizard
{
    private const DRAFT_KEY = '_database_connection_setup';
    private const DATABASE_VERIFIED_KEY = '_setup_database_verified';
    private const VERIFIED_KEY = '_database_connection_verified';
    private const TEST_RECIPIENT_KEY = '_database_connection_mail_recipient';

    private string $csrf;
    private string $errorStep = 'database';

    public function __construct(
        private readonly Config $config,
        private readonly ?PDOException $connectionError = null,
        private readonly bool $configurationMissing = false
    ) {
        $this->csrf = Security::csrfToken();
    }

    public function run(): void
    {
        $this->errorStep = 'database';
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        if ($this->connectionError !== null) {
            error_log('Database connection unavailable: ' . $this->connectionError->getMessage());
        }

        try {
            $content = $this->dispatch();
        } catch (RuntimeException | \InvalidArgumentException $exception) {
            http_response_code(400);
            $content = $this->form($exception->getMessage(), $this->errorStep);
        } catch (Throwable $exception) {
            error_log('Database connection setup failed: ' . $exception->getMessage());
            http_response_code(500);
            $content = '<section class="panel-card p-4"><h1 class="h3">Einrichtung fehlgeschlagen</h1><p>Die Angaben konnten nicht verarbeitet werden. Prüfe die Eingaben und versuche es erneut.</p></section>';
        }

        echo Views::layout(
            $this->configurationMissing ? 'System einrichten' : 'Datenbankverbindung einrichten',
            $content,
            null,
            $this->csrf,
            $this->config->has('APP_URL') ? $this->config->get('APP_URL') : ''
        );
    }

    private function dispatch(): string
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf($_POST['_csrf'] ?? null);
            return match ((string) ($_GET['action'] ?? '')) {
                'database-connection-app' => $this->verifyApplicationDetails(),
                'database-connection-verify' => $this->verifyConnection(),
                'database-connection-save' => $this->saveConfiguration(),
                default => throw new RuntimeException('Die angeforderte Einrichtungsaktion ist ungültig.'),
            };
        }

        if ((string) ($_GET['page'] ?? '') === 'database-connection-review') {
            if (($_SESSION[self::DATABASE_VERIFIED_KEY] ?? false) !== true
                || ($_SESSION[self::VERIFIED_KEY] ?? false) !== true || !isset($_SESSION[self::TEST_RECIPIENT_KEY])) {
                $this->redirect('/?page=database-connection');
            }
            return $this->review();
        }

        $step = (string) ($_GET['step'] ?? '') === 'mail' ? 'application' : 'database';
        if ($step === 'application' && ($_SESSION[self::DATABASE_VERIFIED_KEY] ?? false) !== true) {
            $this->redirect('/?page=database-connection');
        }
        return $this->form('', $step);
    }

    private function verifyApplicationDetails(): string
    {
        unset($_SESSION[self::VERIFIED_KEY]);
        if (($_SESSION[self::DATABASE_VERIFIED_KEY] ?? false) !== true) {
            throw new RuntimeException('Bitte prüfe zuerst die Datenbankverbindung.');
        }
        $this->errorStep = 'application';
        $_SESSION[self::TEST_RECIPIENT_KEY] = trim((string) ($_POST['MAIL_TEST_RECIPIENT'] ?? ''));
        $submittedValues = [
            'APP_ENV' => trim((string) ($_POST['APP_ENV'] ?? '')),
            'APP_URL' => trim((string) ($_POST['APP_URL'] ?? '')),
            'MAIL_FROM' => trim((string) ($_POST['MAIL_FROM'] ?? '')),
            'MAIL_FROM_NAME' => trim((string) ($_POST['MAIL_FROM_NAME'] ?? '')),
            'MAIL_REPLY_TO' => trim((string) ($_POST['MAIL_REPLY_TO'] ?? '')),
        ];
        $previous = $_SESSION[self::DRAFT_KEY] ?? [];
        $_SESSION[self::DRAFT_KEY] = array_merge(
            is_array($previous) ? $previous : [],
            $submittedValues
        );
        $values = $this->validateApplicationValues($submittedValues);
        $_SESSION[self::DRAFT_KEY] = array_merge($_SESSION[self::DRAFT_KEY], $values);
        $this->testMailDelivery($values);
        $_SESSION[self::VERIFIED_KEY] = true;
        $this->redirect('/?page=database-connection-review');
    }

    private function verifyConnection(): string
    {
        unset($_SESSION[self::DATABASE_VERIFIED_KEY], $_SESSION[self::VERIFIED_KEY]);

        $previous = $_SESSION[self::DRAFT_KEY] ?? [];
        $draft = array_merge(
            is_array($previous) ? $previous : [],
            $this->collectValues()
        );
        $_SESSION[self::DRAFT_KEY] = $draft;
        $values = $this->validateDatabaseValues($draft);
        $this->testConnection($values);

        $_SESSION[self::DRAFT_KEY] = array_merge($draft, $values);
        $_SESSION[self::DATABASE_VERIFIED_KEY] = true;
        $this->redirect('/?page=database-connection&step=mail');
    }

    private function saveConfiguration(): string
    {
        $values = $_SESSION[self::DRAFT_KEY] ?? null;
        if (!is_array($values) || ($_SESSION[self::VERIFIED_KEY] ?? false) !== true
            || ($_SESSION[self::DATABASE_VERIFIED_KEY] ?? false) !== true || !isset($_SESSION[self::TEST_RECIPIENT_KEY])) {
            throw new RuntimeException('Die geprüften Angaben sind abgelaufen. Bitte starte die Prüfung erneut.');
        }

        unset($_SESSION[self::VERIFIED_KEY], $_SESSION[self::DATABASE_VERIFIED_KEY]);
        $this->testConnection($values);
        (new RuntimeConfiguration())->save($values);
        unset(
            $_SESSION[self::DRAFT_KEY],
            $_SESSION[self::DATABASE_VERIFIED_KEY],
            $_SESSION[self::VERIFIED_KEY],
            $_SESSION[self::TEST_RECIPIENT_KEY]
        );
        Security::rotateCsrfToken();
        $this->redirect('/');
    }

    private function collectValues(): array
    {
        $previous = $_SESSION[self::DRAFT_KEY] ?? [];
        $password = (string) ($_POST['DB_PASSWORD'] ?? '');
        if ($password === '') {
            $password = (string) ($previous['DB_PASSWORD']
                ?? ($this->config->has('DB_PASSWORD') ? $this->config->get('DB_PASSWORD') : ''));
        }

        return [
            'DB_HOST' => trim((string) ($_POST['DB_HOST'] ?? '')),
            'DB_PORT' => trim((string) ($_POST['DB_PORT'] ?? '')),
            'DB_NAME' => trim((string) ($_POST['DB_NAME'] ?? '')),
            'DB_USER' => trim((string) ($_POST['DB_USER'] ?? '')),
            'DB_PASSWORD' => $password,
        ];
    }

    private function validateApplicationValues(array $values): array
    {
        if (!in_array($values['APP_ENV'], ['local', 'production'], true)) {
            throw new RuntimeException('Die Anwendungsumgebung muss local oder production sein.');
        }

        $url = $values['APP_URL'];
        $urlParts = parse_url($url);
        if (!is_array($urlParts)
            || !in_array(strtolower((string) ($urlParts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($urlParts['host'] ?? '')) === ''
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || isset($urlParts['user'])
            || isset($urlParts['pass'])
            || isset($urlParts['query'])
            || isset($urlParts['fragment'])
        ) {
            throw new RuntimeException('Die Anwendungs-URL muss eine gültige HTTP- oder HTTPS-Adresse sein.');
        }

        $email = mb_strtolower($values['MAIL_FROM']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new RuntimeException('Die Absender-E-Mail-Adresse ist ungültig.');
        }
        $fromName = $values['MAIL_FROM_NAME'];
        if ($fromName === '' || mb_strlen($fromName) > 120 || preg_match('/[\x00-\x1F\x7F]/', $fromName)) {
            throw new RuntimeException('Der Absendername muss zwischen 1 und 120 Zeichen lang sein.');
        }
        $replyTo = mb_strtolower(trim($values['MAIL_REPLY_TO'] ?? ''));
        if ($replyTo !== '' && (!filter_var($replyTo, FILTER_VALIDATE_EMAIL) || strlen($replyTo) > 254)) {
            throw new RuntimeException('Die Reply-To-E-Mail-Adresse ist ungültig.');
        }

        return [
            'APP_ENV' => $values['APP_ENV'],
            'APP_URL' => rtrim($url, '/'),
            'MAIL_FROM' => $email,
            'MAIL_FROM_NAME' => $fromName,
            'MAIL_REPLY_TO' => $replyTo,
        ];
    }

    private function validateDatabaseValues(array $values): array
    {
        $host = $values['DB_HOST'];
        if ($host === '' || strlen($host) > 255 || !preg_match('/\A[a-zA-Z0-9._:\[\]-]+\z/', $host)) {
            throw new RuntimeException('Der Datenbank-Host ist ungültig.');
        }

        $port = filter_var($values['DB_PORT'], FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) {
            throw new RuntimeException('Der Datenbank-Port muss zwischen 1 und 65535 liegen.');
        }
        if (!preg_match('/\A[A-Za-z0-9_$-]{1,64}\z/', $values['DB_NAME'])) {
            throw new RuntimeException('Der Datenbankname darf nur Buchstaben, Zahlen, Unterstriche und Bindestriche enthalten.');
        }
        if (!preg_match('/\A[A-Za-z0-9_.-]{1,32}\z/', $values['DB_USER'])) {
            throw new RuntimeException('Der Datenbankbenutzer ist ungültig.');
        }
        if ($values['DB_PASSWORD'] === '' || strlen($values['DB_PASSWORD']) > 1024 || str_contains($values['DB_PASSWORD'], "\0")) {
            throw new RuntimeException('Das Passwort des Datenbankbenutzers muss eingegeben werden.');
        }

        return [
            'DB_HOST' => $host,
            'DB_PORT' => (string) $port,
            'DB_NAME' => $values['DB_NAME'],
            'DB_USER' => $values['DB_USER'],
            'DB_PASSWORD' => $values['DB_PASSWORD'],
        ];
    }

    private function testConnection(array $values): void
    {
        try {
            $pdo = Database::connect($this->config->withOverrides($values));
            $pdo->query('SELECT 1')->fetchColumn();
        } catch (PDOException $exception) {
            error_log('Database connection setup validation failed: ' . $exception->getMessage());
            throw new RuntimeException(
                'Die Datenbankverbindung ist fehlgeschlagen. Prüfe Host, Port, Datenbankname, Benutzer und Passwort. Die MySQL-Datenbank muss bereits angelegt sein.'
            );
        }
    }

    private function testMailDelivery(array $values): void
    {
        $recipient = mb_strtolower((string) ($_SESSION[self::TEST_RECIPIENT_KEY] ?? ''));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || strlen($recipient) > 254) {
            throw new RuntimeException('Bitte gib eine gültige E-Mail-Adresse für die Test-E-Mail ein.');
        }
        $_SESSION[self::TEST_RECIPIENT_KEY] = $recipient;
        $url = rtrim($values['APP_URL'], '/') . '/';
        $details = [
            'Absender' => $values['MAIL_FROM_NAME'] . ' <' . $values['MAIL_FROM'] . '>',
            'Testempfänger' => $recipient,
            'Antwortadresse' => ($values['MAIL_REPLY_TO'] ?? '') !== '' ? $values['MAIL_REPLY_TO'] : $values['MAIL_FROM'],
            'Versandart' => 'PHP mail()',
        ];
        $htmlRows = '';
        $textRows = '';
        foreach ($details as $label => $value) {
            $htmlRows .= '<tr><td style="padding-right:14px;color:#647268;vertical-align:top">'
                . Security::escape($label) . '</td><td style="overflow-wrap:anywhere">'
                . Security::escape($value) . '</td></tr>';
            $textRows .= "{$label}: {$value}\n";
        }
        $html = '<!doctype html><html lang="de"><body style="margin:0;padding:32px 12px;background:#f2f5f3;'
            . 'font-family:Arial,Helvetica,sans-serif;color:#24312a">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
            . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;'
            . 'width:100%;background:#fff;border-radius:16px;overflow:hidden">'
            . '<tr><td style="padding:28px 32px;background:#14532d;color:#fff">'
            . '<div style="font-size:13px;letter-spacing:1.5px;text-transform:uppercase;opacity:.8">'
            . 'Vereins-Buchungssystem</div><h1 style="font-size:24px;line-height:1.3;margin:10px 0 0">'
            . 'Deine Test-E-Mail ist da!</h1></td></tr>'
            . '<tr><td style="padding:28px 32px"><p style="font-size:16px;margin:0 0 8px">Hallo,</p>'
            . '<p style="color:#536158;margin:0 0 24px">Diese Nachricht gehört zur Einrichtung der Platz- und Busbelegung. '
            . 'Wenn du sie lesen kannst, ist die Test-E-Mail bei dir angekommen.</p>'
            . '<div style="padding:20px;background:#f4f7f5;border:1px solid #e1e9e3;border-radius:12px">'
            . '<div style="font-size:12px;color:#5d6b62;text-transform:uppercase;letter-spacing:1px">Einrichtung</div>'
            . '<div style="font-size:20px;font-weight:bold;margin:7px 0 16px">Deine Mail-Einstellungen</div>'
            . '<table role="presentation" cellspacing="0" cellpadding="0" style="font-size:14px;line-height:1.7;'
            . 'color:#36443a">' . $htmlRows . '</table></div>'
            . '<p style="font-size:14px;line-height:1.7;color:#536158;margin:20px 0 0">'
            . 'Kehre zur Anwendung zurück und schließe die Einrichtung ab. Mit dieser Test-E-Mail wird keine Buchung angelegt.</p>'
            . '<div style="text-align:center;padding:26px 0 8px"><a href="' . Security::escape($url)
            . '" style="display:inline-block;padding:13px 24px;background:#198754;color:#fff;'
            . 'text-decoration:none;font-weight:bold;border-radius:8px">Zur Anwendung</a></div>'
            . '</td></tr><tr><td style="padding:18px 32px;background:#f8faf8;color:#738078;font-size:12px">'
            . 'Automatische Test-E-Mail des Vereins-Buchungssystems</td></tr></table></td></tr></table></body></html>';
        $text = "Hallo,\n\nDeine Test-E-Mail ist da!\n\n"
            . "Diese Nachricht gehört zur Einrichtung der Platz- und Busbelegung. Wenn du sie lesen kannst, ist die Test-E-Mail bei dir angekommen.\n\n"
            . "Deine Mail-Einstellungen\n{$textRows}\n"
            . "Kehre zur Anwendung zurück und schließe die Einrichtung ab. Mit dieser Test-E-Mail wird keine Buchung angelegt.\n\n"
            . "Zur Anwendung: {$url}\n\nAutomatische Test-E-Mail des Vereins-Buchungssystems";
        (new Mailer($this->config->withOverrides($values)))->send(
            $recipient,
            'Test-E-Mail: Platz- und Busbelegung',
            $html,
            $text
        );
    }

    private function form(string $error = '', string $step = 'database'): string
    {
        $draft = $_SESSION[self::DRAFT_KEY] ?? [];
        $value = fn(string $key): string => (string) ($draft[$key]
            ?? ($this->config->has($key) ? $this->config->get($key) : ''));
        $errorHtml = $error === ''
            ? ''
            : '<div class="alert alert-danger" role="alert">' . Security::escape($error) . '</div>';
        $heading = $this->configurationMissing ? 'System einrichten' : 'Datenbankverbindung einrichten';
        if ($step === 'application') {
            $introduction = $this->configurationMissing
                ? 'Die Konfiguration fehlt oder ist unvollständig. Gib alle Einstellungen ausdrücklich an; es werden keine Standardwerte verwendet.'
                : 'Die Datenbankverbindung wurde geprüft. Richte nun den Mailversand und die Anwendungsadresse ein.';
            return '<section class="panel-card mx-auto p-4" style="max-width:720px"><h1 class="h3">' . $heading . '</h1>
<p>' . $introduction . '</p>'
                . Views::setupProgress(2)
                . $errorHtml . '
<form method="post" action="/?action=database-connection-app">
  <input type="hidden" name="_csrf" value="' . Security::escape($this->csrf) . '">
  <div class="mb-3"><label class="form-label" for="setup-app-env">Anwendungsumgebung</label><select class="form-select" id="setup-app-env" name="APP_ENV" required><option value="">Bitte wählen</option><option value="local"' . ($value('APP_ENV') === 'local' ? ' selected' : '') . '>local</option><option value="production"' . ($value('APP_ENV') === 'production' ? ' selected' : '') . '>production</option></select></div>
  <div class="mb-3"><label class="form-label" for="setup-app-url">Anwendungs-URL</label><input class="form-control" id="setup-app-url" type="url" name="APP_URL" value="' . Security::escape($value('APP_URL')) . '" maxlength="500" required></div>
  <div class="mb-3"><label class="form-label" for="setup-mail-from">Absender-E-Mail</label><input class="form-control" id="setup-mail-from" type="email" name="MAIL_FROM" value="' . Security::escape($value('MAIL_FROM')) . '" maxlength="254" required></div>
  <div class="mb-3"><label class="form-label" for="setup-mail-name">Absendername</label><input class="form-control" id="setup-mail-name" name="MAIL_FROM_NAME" value="' . Security::escape($value('MAIL_FROM_NAME')) . '" maxlength="120" required></div>
  <div class="mb-3"><label class="form-label" for="setup-mail-reply-to">Reply-To-E-Mail-Adresse (optional)</label><input class="form-control" id="setup-mail-reply-to" type="email" name="MAIL_REPLY_TO" value="' . Security::escape($value('MAIL_REPLY_TO')) . '" maxlength="254"><div class="form-text">Antworten gehen an diese Adresse. Leer lassen, um die Absenderadresse zu verwenden.</div></div>
  <div class="mb-3"><label class="form-label" for="setup-mail-test-recipient">Empfänger der Test-E-Mail</label><input class="form-control" id="setup-mail-test-recipient" type="email" name="MAIL_TEST_RECIPIENT" value="' . Security::escape((string) ($_SESSION[self::TEST_RECIPIENT_KEY] ?? '')) . '" maxlength="254" required><div class="form-text">Nur für diesen Test; die Adresse wird nicht als Anwendungseinstellung gespeichert.</div></div>
  <p>Beim Fortfahren wird eine Test-E-Mail über PHP mail() verschickt. Der Webserver muss den Mailversand unterstützen. Eine erfolgreiche Übergabe bestätigt noch keine Zustellung; prüfe auch den Spam-Ordner. Bei Fehlern bleiben die Eingaben erhalten und gespeicherte Einstellungen unverändert.</p>
  <div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-secondary" href="/?page=database-connection">Zurück zur Datenbank</a><button class="btn btn-primary" type="submit">Test-E-Mail senden und weiter</button></div>
</form></section>';
        }

        $hasPassword = $value('DB_PASSWORD') !== '';
        $passwordHelp = $hasPassword
            ? '<div class="form-text">Leer lassen, um das bisher eingegebene Kennwort weiterzuverwenden.</div>'
            : '<div class="form-text">Das Passwort des Datenbankbenutzers wird benötigt; ein MySQL-Root-Passwort ist nicht erforderlich.</div>';

        return '<section class="panel-card mx-auto p-4" style="max-width:720px"><h1 class="h3">' . $heading . '</h1>
<p>Gib die Zugangsdaten für die bereits angelegte MySQL-Datenbank ein. Anschließend wird die Verbindung geprüft.</p>'
            . Views::setupProgress(1)
            . $errorHtml . '
<form method="post" action="/?action=database-connection-verify">
  <input type="hidden" name="_csrf" value="' . Security::escape($this->csrf) . '">
  <div class="mb-3"><label class="form-label" for="setup-db-host">Host</label><input class="form-control" id="setup-db-host" name="DB_HOST" value="' . Security::escape($value('DB_HOST')) . '" maxlength="255" required></div>
  <div class="mb-3"><label class="form-label" for="setup-db-port">Port</label><input class="form-control" id="setup-db-port" type="number" name="DB_PORT" value="' . Security::escape($value('DB_PORT')) . '" min="1" max="65535" required></div>
  <div class="mb-3"><label class="form-label" for="setup-db-name">Datenbankname</label><input class="form-control" id="setup-db-name" name="DB_NAME" value="' . Security::escape($value('DB_NAME')) . '" maxlength="64" required></div>
  <div class="mb-3"><label class="form-label" for="setup-db-user">Datenbankbenutzer</label><input class="form-control" id="setup-db-user" name="DB_USER" value="' . Security::escape($value('DB_USER')) . '" maxlength="32" required></div>
  <div class="mb-3"><label class="form-label" for="setup-db-password">Passwort des Datenbankbenutzers</label><input class="form-control" id="setup-db-password" type="password" name="DB_PASSWORD" autocomplete="new-password" maxlength="1024" ' . ($hasPassword ? '' : 'required') . '>' . $passwordHelp . '</div>
  <button class="btn btn-primary" type="submit">Verbindung prüfen und weiter zur Mail-Konfiguration</button>
</form></section>';
    }

    private function review(): string
    {
        $values = $_SESSION[self::DRAFT_KEY] ?? null;
        if (!is_array($values)) {
            unset($_SESSION[self::VERIFIED_KEY]);
            $this->redirect('/?page=database-connection');
        }

        $rows = [
            'Anwendungsumgebung' => $values['APP_ENV'],
            'Anwendungs-URL' => $values['APP_URL'],
            'Datenbank-Host' => $values['DB_HOST'],
            'Datenbank-Port' => $values['DB_PORT'],
            'Datenbankname' => $values['DB_NAME'],
            'Datenbankbenutzer' => $values['DB_USER'],
            'Datenbankpasswort' => 'Eingegeben (wird nicht angezeigt)',
            'Absender-E-Mail' => $values['MAIL_FROM'],
            'Absendername' => $values['MAIL_FROM_NAME'],
            'Reply-To-E-Mail-Adresse' => ($values['MAIL_REPLY_TO'] ?? '') === '' ? 'Nicht gesetzt (Absenderadresse)' : $values['MAIL_REPLY_TO'],
            'Versandart' => 'PHP mail()',
            'Empfänger der Test-E-Mail' => $_SESSION[self::TEST_RECIPIENT_KEY] ?? '',
        ];
        $reviewRows = '';
        foreach ($rows as $label => $value) {
            $reviewRows .= '<dt class="col-sm-4">' . Security::escape($label) . '</dt><dd class="col-sm-8">' . Security::escape((string) $value) . '</dd>';
        }

        return '<section class="panel-card mx-auto p-4" style="max-width:720px"><h1 class="h3">Angaben prüfen</h1>'
            . Views::setupProgress(2) . '
<div class="alert alert-success" role="status">Die Verbindung zur vorhandenen Datenbank wurde erfolgreich getestet. Die Test-E-Mail wurde über PHP mail() an den Maildienst übergeben. Dies bestätigt noch keine Zustellung.</div>
<dl class="row">' . $reviewRows . '</dl>
<p>Prüfe die Angaben und den Eingang der Test-E-Mail. Du kannst die Angaben vor dem Speichern noch ändern. Beim Speichern wird die Datenbank erneut geprüft, aber keine weitere Test-E-Mail versendet.</p>
<div class="d-flex flex-wrap gap-2">
  <a class="btn btn-outline-secondary" href="/?page=database-connection&amp;step=mail">Mail-Konfiguration ändern</a>
  <a class="btn btn-outline-secondary" href="/?page=database-connection">Datenbankangaben ändern</a>
  <form method="post" action="/?action=database-connection-save" class="m-0"><input type="hidden" name="_csrf" value="' . Security::escape($this->csrf) . '"><button class="btn btn-primary" type="submit">Einstellungen speichern und weiter</button></form>
</div></section>';
    }

    private function redirect(string $location): never
    {
        header('Location: ' . $location, true, 303);
        exit;
    }
}
