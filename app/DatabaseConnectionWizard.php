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

    public function __construct(
        private readonly Config $config,
        private readonly PDOException $connectionError
    ) {
        $this->csrf = Security::csrfToken();
    }

    public function run(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        error_log('Database connection unavailable: ' . $this->connectionError->getMessage());

        try {
            $content = $this->dispatch();
        } catch (RuntimeException | \InvalidArgumentException $exception) {
            http_response_code(400);
            $step = (string) ($_GET['action'] ?? '') === 'database-connection-app'
                ? 'application'
                : 'database';
            $content = $this->form($exception->getMessage(), $step);
        } catch (Throwable $exception) {
            error_log('Database connection setup failed: ' . $exception->getMessage());
            http_response_code(500);
            $content = '<section class="panel-card p-4"><h1 class="h3">Einrichtung fehlgeschlagen</h1><p>Die Angaben konnten nicht verarbeitet werden. Prüfe die Eingaben und versuche es erneut.</p></section>';
        }

        echo Views::layout(
            'Datenbankverbindung einrichten',
            $content,
            null,
            $this->csrf,
            $this->config->get('APP_URL')
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
        $submittedValues = [
            'APP_URL' => trim((string) ($_POST['APP_URL'] ?? '')),
            'MAIL_FROM' => trim((string) ($_POST['MAIL_FROM'] ?? '')),
            'MAIL_FROM_NAME' => trim((string) ($_POST['MAIL_FROM_NAME'] ?? '')),
        ];
        $previous = $_SESSION[self::DRAFT_KEY] ?? [];
        $_SESSION[self::DRAFT_KEY] = array_merge(
            is_array($previous) ? $previous : [],
            $submittedValues
        );
        $values = $this->validateApplicationValues($submittedValues);
        $_SESSION[self::DRAFT_KEY] = array_merge($_SESSION[self::DRAFT_KEY], $values);
        $_SESSION[self::AUTHORIZED_KEY] = true;
        $this->redirect('/?page=database-connection&step=database');
    }

    private function verifyConnection(): string
    {
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

        $this->testConnection($values);
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
            $password = (string) ($previous['DB_PASSWORD'] ?? $this->config->get('DB_PASSWORD'));
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
            'APP_URL' => rtrim($url, '/'),
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

    private function form(string $error = '', string $step = 'application'): string
    {
        $draft = $_SESSION[self::DRAFT_KEY] ?? [];
        $value = fn(string $key): string => (string) ($draft[$key] ?? $this->config->get($key));
        $errorHtml = $error === ''
            ? ''
            : '<div class="alert alert-danger" role="alert">' . Security::escape($error) . '</div>';
        if ($step === 'application') {
            return '<section class="panel-card mx-auto p-4" style="max-width:720px"><h1 class="h3">Datenbankverbindung einrichten</h1>
<p>Die Anwendung erreicht die konfigurierte Datenbank nicht. Gib zuerst die Anwendungs- und Absenderdaten ein. Die MySQL-Datenbank muss bereits angelegt sein.</p>
<div class="progress mb-4" role="progressbar" aria-label="Einrichtungsschritt" aria-valuenow="1" aria-valuemin="1" aria-valuemax="2"><div class="progress-bar" style="width:50%">Schritt 1 von 2</div></div>'
                . $errorHtml . '
<form method="post" action="/?action=database-connection-app">
  <input type="hidden" name="_csrf" value="' . Security::escape($this->csrf) . '">
  <div class="mb-3"><label class="form-label" for="setup-app-url">Anwendungs-URL</label><input class="form-control" id="setup-app-url" type="url" name="APP_URL" value="' . Security::escape($value('APP_URL')) . '" maxlength="500" required></div>
  <div class="mb-3"><label class="form-label" for="setup-mail-from">Absender-E-Mail</label><input class="form-control" id="setup-mail-from" type="email" name="MAIL_FROM" value="' . Security::escape($value('MAIL_FROM')) . '" maxlength="254" required></div>
  <div class="mb-3"><label class="form-label" for="setup-mail-name">Absendername</label><input class="form-control" id="setup-mail-name" name="MAIL_FROM_NAME" value="' . Security::escape($value('MAIL_FROM_NAME')) . '" maxlength="120" required></div>
  <button class="btn btn-primary" type="submit">Weiter zu den Datenbankangaben</button>
</form></section>';
        }

        $hasPassword = $value('DB_PASSWORD') !== '';
        $passwordHelp = $hasPassword
            ? '<div class="form-text">Leer lassen, um das bisher eingegebene Kennwort weiterzuverwenden.</div>'
            : '<div class="form-text">Das Passwort des Datenbankbenutzers wird benötigt; ein MySQL-Root-Passwort ist nicht erforderlich.</div>';

        return '<section class="panel-card mx-auto p-4" style="max-width:720px"><h1 class="h3">Datenbankverbindung einrichten</h1>
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
            'Anwendungs-URL' => $values['APP_URL'],
            'Datenbank-Host' => $values['DB_HOST'],
            'Datenbank-Port' => $values['DB_PORT'],
            'Datenbankname' => $values['DB_NAME'],
            'Datenbankbenutzer' => $values['DB_USER'],
            'Datenbankpasswort' => 'Eingegeben (wird nicht angezeigt)',
            'Absender-E-Mail' => $values['MAIL_FROM'],
            'Absendername' => $values['MAIL_FROM_NAME'],
        ];
        $reviewRows = '';
        foreach ($rows as $label => $value) {
            $reviewRows .= '<dt class="col-sm-4">' . Security::escape($label) . '</dt><dd class="col-sm-8">' . Security::escape((string) $value) . '</dd>';
        }

        return '<section class="panel-card mx-auto p-4" style="max-width:720px"><h1 class="h3">Angaben prüfen</h1>
<div class="alert alert-success" role="status">Die Eingaben sind gültig und die Verbindung zur vorhandenen Datenbank wurde erfolgreich getestet.</div>
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
