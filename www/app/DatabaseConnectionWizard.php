<?php
declare(strict_types=1);

namespace App;

use PDOException;
use RuntimeException;
use Throwable;

final class DatabaseConnectionWizard
{
    private const DRAFT_KEY = '_database_connection_setup';
    private const AUTHORIZED_KEY = '_database_connection_authorized';
    private const VERIFIED_KEY = '_database_connection_verified';

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
            $step = (string) ($_GET['action'] ?? '') === 'database-connection-app'
                ? 'application'
                : $this->errorStep;
            $content = $this->form($exception->getMessage(), $step);
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
            if (($_SESSION[self::VERIFIED_KEY] ?? false) !== true) {
                $this->redirect('/?page=database-connection');
            }
            return $this->review();
        }

        $step = (string) ($_GET['step'] ?? '') === 'database' ? 'database' : 'application';
        if ($step === 'database' && ($_SESSION[self::AUTHORIZED_KEY] ?? false) !== true) {
            $this->redirect('/?page=database-connection');
        }
        return $this->form('', $step);
    }

    private function verifyApplicationDetails(): string
    {
        unset($_SESSION[self::AUTHORIZED_KEY], $_SESSION[self::VERIFIED_KEY]);
        $submittedValues = [
            'APP_ENV' => trim((string) ($_POST['APP_ENV'] ?? '')),
            'APP_URL' => trim((string) ($_POST['APP_URL'] ?? '')),
            'MAIL_HOST' => trim((string) ($_POST['MAIL_HOST'] ?? '')),
            'MAIL_PORT' => trim((string) ($_POST['MAIL_PORT'] ?? '')),
            'MAIL_USERNAME' => trim((string) ($_POST['MAIL_USERNAME'] ?? '')),
            'MAIL_PASSWORD' => (string) ($_POST['MAIL_PASSWORD'] ?? ''),
            'MAIL_ENCRYPTION' => trim((string) ($_POST['MAIL_ENCRYPTION'] ?? '')),
            'MAIL_FROM' => trim((string) ($_POST['MAIL_FROM'] ?? '')),
            'MAIL_FROM_NAME' => trim((string) ($_POST['MAIL_FROM_NAME'] ?? '')),
        ];
        $previous = $_SESSION[self::DRAFT_KEY] ?? [];
        if ($submittedValues['MAIL_PASSWORD'] === '') {
            $submittedValues['MAIL_PASSWORD'] = (string) ($previous['MAIL_PASSWORD']
                ?? ($this->config->has('MAIL_PASSWORD') ? $this->config->get('MAIL_PASSWORD') : ''));
        }
        $_SESSION[self::DRAFT_KEY] = array_merge(
            is_array($previous) ? $previous : [],
            $submittedValues
        );
        $values = $this->validateApplicationValues($submittedValues);
        $_SESSION[self::DRAFT_KEY] = array_merge($_SESSION[self::DRAFT_KEY], $values);
        $this->testMailConnection($values);
        $_SESSION[self::AUTHORIZED_KEY] = true;
        $this->redirect('/?page=database-connection&step=database');
    }

    private function verifyConnection(): string
    {
        unset($_SESSION[self::VERIFIED_KEY]);
        if (($_SESSION[self::AUTHORIZED_KEY] ?? false) !== true) {
            throw new RuntimeException('Die Sitzung ist abgelaufen. Bitte beginne die Einrichtung erneut.');
        }

        $previous = $_SESSION[self::DRAFT_KEY] ?? [];
        $draft = array_merge(
            is_array($previous) ? $previous : [],
            $this->collectValues()
        );
        $_SESSION[self::DRAFT_KEY] = $draft;
        $values = array_merge(
            $this->validateApplicationValues($draft),
            $this->validateDatabaseValues($draft)
        );
        $this->testConnection($values);

        $_SESSION[self::DRAFT_KEY] = $values;
        $_SESSION[self::VERIFIED_KEY] = true;
        $this->redirect('/?page=database-connection-review');
    }

    private function saveConfiguration(): string
    {
        $values = $_SESSION[self::DRAFT_KEY] ?? null;
        if (!is_array($values) || ($_SESSION[self::VERIFIED_KEY] ?? false) !== true) {
            throw new RuntimeException('Die geprüften Angaben sind abgelaufen. Bitte starte die Prüfung erneut.');
        }

        unset($_SESSION[self::VERIFIED_KEY]);
        $this->testConnection($values);
        $this->testMailConnection($values);
        (new RuntimeConfiguration())->save($values);
        unset(
            $_SESSION[self::DRAFT_KEY],
            $_SESSION[self::AUTHORIZED_KEY],
            $_SESSION[self::VERIFIED_KEY]
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
        $mailHost = $values['MAIL_HOST'];
        if ($mailHost !== '' && (strlen($mailHost) > 255 || !preg_match('/\A[a-zA-Z0-9._:\[\]-]+\z/', $mailHost))) {
            throw new RuntimeException('Der SMTP-Host ist ungültig.');
        }
        $mailPort = $values['MAIL_PORT'];
        if ($mailHost !== '' || $mailPort !== '') {
            $port = filter_var($mailPort, FILTER_VALIDATE_INT);
            if ($port === false || $port < 1 || $port > 65535) {
                throw new RuntimeException('Der SMTP-Port muss zwischen 1 und 65535 liegen.');
            }
        }
        if (!in_array($values['MAIL_ENCRYPTION'], ['', 'tls', 'ssl'], true)) {
            throw new RuntimeException('Die SMTP-Verschlüsselung muss tls, ssl oder leer sein.');
        }
        if ($values['MAIL_USERNAME'] !== '' && $values['MAIL_PASSWORD'] === '') {
            throw new RuntimeException('Für den SMTP-Benutzer muss ein Passwort eingegeben werden.');
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

        return [
            'APP_ENV' => $values['APP_ENV'],
            'APP_URL' => rtrim($url, '/'),
            'MAIL_HOST' => $mailHost,
            'MAIL_PORT' => $mailPort,
            'MAIL_USERNAME' => $values['MAIL_USERNAME'],
            'MAIL_PASSWORD' => $values['MAIL_PASSWORD'],
            'MAIL_ENCRYPTION' => $values['MAIL_ENCRYPTION'],
            'MAIL_FROM' => $email,
            'MAIL_FROM_NAME' => $fromName,
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

    private function testMailConnection(array $values): void
    {
        if ($values['MAIL_HOST'] === '') {
            return;
        }

        $this->errorStep = 'application';
        (new Mailer($this->config->withOverrides($values)))->testConnection();
        $this->errorStep = 'database';
    }

    private function form(string $error = '', string $step = 'application'): string
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
                : 'Die Anwendung erreicht die konfigurierte Datenbank nicht. Prüfe zuerst die Anwendungs- und E-Mail-Einstellungen. Die MySQL-Datenbank muss bereits angelegt sein.';
            return '<section class="panel-card mx-auto p-4" style="max-width:720px"><h1 class="h3">' . $heading . '</h1>
<p>' . $introduction . '</p>
<div class="progress mb-4" role="progressbar" aria-label="Einrichtungsschritt" aria-valuenow="1" aria-valuemin="1" aria-valuemax="2"><div class="progress-bar" style="width:50%">Schritt 1 von 2</div></div>'
                . $errorHtml . '
<form method="post" action="/?action=database-connection-app">
  <input type="hidden" name="_csrf" value="' . Security::escape($this->csrf) . '">
  <div class="mb-3"><label class="form-label" for="setup-app-env">Anwendungsumgebung</label><select class="form-select" id="setup-app-env" name="APP_ENV" required><option value="">Bitte wählen</option><option value="local"' . ($value('APP_ENV') === 'local' ? ' selected' : '') . '>local</option><option value="production"' . ($value('APP_ENV') === 'production' ? ' selected' : '') . '>production</option></select></div>
  <div class="mb-3"><label class="form-label" for="setup-app-url">Anwendungs-URL</label><input class="form-control" id="setup-app-url" type="url" name="APP_URL" value="' . Security::escape($value('APP_URL')) . '" maxlength="500" required></div>
  <div class="mb-3"><label class="form-label" for="setup-mail-from">Absender-E-Mail</label><input class="form-control" id="setup-mail-from" type="email" name="MAIL_FROM" value="' . Security::escape($value('MAIL_FROM')) . '" maxlength="254" required></div>
  <div class="mb-3"><label class="form-label" for="setup-mail-name">Absendername</label><input class="form-control" id="setup-mail-name" name="MAIL_FROM_NAME" value="' . Security::escape($value('MAIL_FROM_NAME')) . '" maxlength="120" required></div>
  <div class="mb-3"><label class="form-label" for="setup-mail-host">SMTP-Host</label><input class="form-control" id="setup-mail-host" name="MAIL_HOST" value="' . Security::escape($value('MAIL_HOST')) . '" maxlength="255"><div class="form-text">Leer lassen, um den E-Mail-Versand nicht zu konfigurieren.</div></div>
  <div class="mb-3"><label class="form-label" for="setup-mail-port">SMTP-Port</label><input class="form-control" id="setup-mail-port" type="number" name="MAIL_PORT" value="' . Security::escape($value('MAIL_PORT')) . '" min="1" max="65535"><div class="form-text">Bei konfiguriertem SMTP-Host erforderlich.</div></div>
  <div class="mb-3"><label class="form-label" for="setup-mail-encryption">SMTP-Verschlüsselung</label><select class="form-select" id="setup-mail-encryption" name="MAIL_ENCRYPTION"><option value="">Keine</option><option value="tls"' . ($value('MAIL_ENCRYPTION') === 'tls' ? ' selected' : '') . '>TLS</option><option value="ssl"' . ($value('MAIL_ENCRYPTION') === 'ssl' ? ' selected' : '') . '>SSL</option></select></div>
  <div class="mb-3"><label class="form-label" for="setup-mail-user">SMTP-Benutzer</label><input class="form-control" id="setup-mail-user" name="MAIL_USERNAME" value="' . Security::escape($value('MAIL_USERNAME')) . '"></div>
  <div class="mb-3"><label class="form-label" for="setup-mail-password">SMTP-Passwort</label><input class="form-control" id="setup-mail-password" type="password" name="MAIL_PASSWORD" autocomplete="new-password"><div class="form-text">' . ($value('MAIL_PASSWORD') !== '' ? 'Leer lassen, um das bisher eingegebene Kennwort weiterzuverwenden.' : 'Nur bei SMTP-Authentifizierung erforderlich.') . '</div></div>
  <p>Der konfigurierte Mail-Server wird einschließlich Anmeldung geprüft, ohne eine E-Mail zu versenden. Bei Fehlern bleiben die Eingaben erhalten; gespeicherte Einstellungen werden nicht verändert.</p>
  <button class="btn btn-primary" type="submit">Mail-Server prüfen und weiter</button>
</form></section>';
        }

        $hasPassword = $value('DB_PASSWORD') !== '';
        $passwordHelp = $hasPassword
            ? '<div class="form-text">Leer lassen, um das bisher eingegebene Kennwort weiterzuverwenden.</div>'
            : '<div class="form-text">Das Passwort des Datenbankbenutzers wird benötigt; ein MySQL-Root-Passwort ist nicht erforderlich.</div>';

        return '<section class="panel-card mx-auto p-4" style="max-width:720px"><h1 class="h3">' . $heading . '</h1>
<p>Gib die Zugangsdaten für die bereits angelegte MySQL-Datenbank ein. Anschließend wird die Verbindung geprüft.</p>
<div class="progress mb-4" role="progressbar" aria-label="Einrichtungsschritt" aria-valuenow="2" aria-valuemin="1" aria-valuemax="2"><div class="progress-bar" style="width:100%">Schritt 2 von 2</div></div>'
            . $errorHtml . '
<form method="post" action="/?action=database-connection-verify">
  <input type="hidden" name="_csrf" value="' . Security::escape($this->csrf) . '">
  <div class="mb-3"><label class="form-label" for="setup-db-host">Host</label><input class="form-control" id="setup-db-host" name="DB_HOST" value="' . Security::escape($value('DB_HOST')) . '" maxlength="255" required></div>
  <div class="mb-3"><label class="form-label" for="setup-db-port">Port</label><input class="form-control" id="setup-db-port" type="number" name="DB_PORT" value="' . Security::escape($value('DB_PORT')) . '" min="1" max="65535" required></div>
  <div class="mb-3"><label class="form-label" for="setup-db-name">Datenbankname</label><input class="form-control" id="setup-db-name" name="DB_NAME" value="' . Security::escape($value('DB_NAME')) . '" maxlength="64" required></div>
  <div class="mb-3"><label class="form-label" for="setup-db-user">Datenbankbenutzer</label><input class="form-control" id="setup-db-user" name="DB_USER" value="' . Security::escape($value('DB_USER')) . '" maxlength="32" required></div>
  <div class="mb-3"><label class="form-label" for="setup-db-password">Passwort des Datenbankbenutzers</label><input class="form-control" id="setup-db-password" type="password" name="DB_PASSWORD" autocomplete="new-password" maxlength="1024" ' . ($hasPassword ? '' : 'required') . '>' . $passwordHelp . '</div>
  <div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-secondary" href="/?page=database-connection">Zurück</a><button class="btn btn-primary" type="submit">Verbindung prüfen</button></div>
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
            'SMTP-Host' => $values['MAIL_HOST'] === '' ? 'Nicht konfiguriert' : $values['MAIL_HOST'],
            'SMTP-Port' => $values['MAIL_PORT'],
            'SMTP-Verschlüsselung' => $values['MAIL_ENCRYPTION'] === '' ? 'Keine' : $values['MAIL_ENCRYPTION'],
            'SMTP-Benutzer' => $values['MAIL_USERNAME'],
            'SMTP-Passwort' => $values['MAIL_PASSWORD'] === '' ? 'Nicht gesetzt' : 'Eingegeben (wird nicht angezeigt)',
        ];
        $reviewRows = '';
        foreach ($rows as $label => $value) {
            $reviewRows .= '<dt class="col-sm-4">' . Security::escape($label) . '</dt><dd class="col-sm-8">' . Security::escape((string) $value) . '</dd>';
        }

        return '<section class="panel-card mx-auto p-4" style="max-width:720px"><h1 class="h3">Angaben prüfen</h1>
<div class="alert alert-success" role="status">Die Verbindung zur vorhandenen Datenbank wurde erfolgreich getestet. '
            . ($values['MAIL_HOST'] === ''
                ? 'Der E-Mail-Versand ist nicht konfiguriert; die Mail-Server-Prüfung wurde übersprungen.'
                : 'Die Verbindung zum Mail-Server einschließlich Anmeldung wurde erfolgreich getestet.')
            . '</div>
<dl class="row">' . $reviewRows . '</dl>
<p>Prüfe die Angaben. Du kannst sie vor dem Speichern noch ändern.</p>
<div class="d-flex flex-wrap gap-2">
  <a class="btn btn-outline-secondary" href="/?page=database-connection">Anwendungsdaten ändern</a>
  <a class="btn btn-outline-secondary" href="/?page=database-connection&amp;step=database">Datenbankangaben ändern</a>
  <form method="post" action="/?action=database-connection-save" class="m-0"><input type="hidden" name="_csrf" value="' . Security::escape($this->csrf) . '"><button class="btn btn-primary" type="submit">Einstellungen speichern</button></form>
</div></section>';
    }

    private function redirect(string $location): never
    {
        header('Location: ' . $location, true, 303);
        exit;
    }
}
