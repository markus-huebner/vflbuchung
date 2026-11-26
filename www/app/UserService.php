<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class UserService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Mailer $mailer,
        private readonly Config $config
    ) {
    }

    public function invite(
        string $email,
        string $username,
        string $firstName,
        string $lastName,
        string $role
    ): void
    {
        if (!in_array($role, ['admin', 'manager', 'trainer'], true)) {
            throw new RuntimeException('Diese Rolle kann nicht eingeladen werden.');
        }

        $email = AuthService::normalizeEmail($email);
        $username = AuthService::normalizeUsername($username);
        $firstName = AuthService::validateFirstName($firstName);
        $lastName = AuthService::validateLastName($lastName);
        $token = Security::randomToken();

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO users (email, username, first_name, last_name, role, status, invite_token_hash, invite_expires_at)
                 VALUES (:email, :username, :first_name, :last_name, :role, 'invited', :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 DAY))"
            );
            $statement->execute([
                'email' => $email,
                'username' => $username,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role' => $role,
                'token_hash' => Security::tokenHash($token),
            ]);

            $this->sendInvitation($email, $username, $firstName, $token, false);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\PDOException $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('Diese E-Mail-Adresse oder dieser Benutzername ist bereits vergeben.', 0, $exception);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function sendInvitation(string $email, string $username, string $firstName, string $token, bool $updated): void
    {
        $url = rtrim($this->config->get('APP_URL'), '/') . '/?page=invite&token=' . rawurlencode($token);
        $heading = $updated ? 'Aktualisierte Einladung' : 'Einladung zum Vereins-Buchungssystem';
        $subject = $updated ? 'Aktualisierte Einladung zum Vereins-Buchungssystem' : $heading;
        $this->mailer->send(
            $email,
            $subject,
            '<!doctype html><html lang="de"><body style="margin:0;padding:32px 12px;background:#f2f5f3;'
            . 'font-family:Arial,Helvetica,sans-serif;color:#24312a">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
            . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;'
            . 'width:100%;background:#fff;border-radius:16px;overflow:hidden">'
            . '<tr><td style="padding:28px 32px;background:#14532d;color:#fff">'
            . '<div style="font-size:13px;letter-spacing:1.5px;text-transform:uppercase;opacity:.8">Vereins-Buchungssystem</div>'
            . '<h1 style="font-size:24px;line-height:1.3;margin:10px 0 0">'
            . Mailer::icon('invitation') . Security::escape($heading) . '</h1></td></tr>'
            . '<tr><td style="padding:28px 32px"><p style="font-size:16px;margin:0 0 8px">Hallo '
            . Security::escape($firstName) . ',</p><p style="color:#536158;margin:0 0 24px">'
            . 'Richte dein Konto über den folgenden Link ein.</p>'
            . '<div style="padding:20px;background:#f4f7f5;border:1px solid #e1e9e3;border-radius:12px;line-height:1.6">'
            . 'Dein Benutzername: <strong>' . Security::escape($username) . '</strong><br>'
            . 'Der Einladungslink ist 5 Tage gültig und kann nur einmal verwendet werden.</div>'
            . '<div style="text-align:center;padding:26px 0 8px"><a href="' . Security::escape($url)
            . '" style="display:inline-block;padding:13px 24px;background:#198754;color:#fff;'
            . 'text-decoration:none;font-weight:bold;border-radius:8px">Konto einrichten</a></div></td></tr>'
            . '<tr><td style="padding:18px 32px;background:#f8faf8;color:#738078;font-size:12px">'
            . 'Automatische Benachrichtigung des Vereins-Buchungssystems</td></tr></table></td></tr></table></body></html>',
            "Hallo {$firstName},\n\nDein Benutzername lautet: {$username}\n\n"
            . "Richte dein Konto über diesen Link ein (5 Tage gültig, einmal verwendbar):\n{$url}"
        );
    }

    public function register(string $email, string $username, string $firstName, string $lastName): array
    {
        $email = AuthService::normalizeEmail($email);
        $username = AuthService::normalizeUsername($username);
        $firstName = AuthService::validateFirstName($firstName);
        $lastName = AuthService::validateLastName($lastName);
        $existing = $this->pdo->prepare('SELECT 1 FROM users WHERE email = :email OR username = :username LIMIT 1');
        $existing->execute(['email' => $email, 'username' => $username]);
        if ($existing->fetchColumn() !== false) {
            throw new RuntimeException('Diese E-Mail-Adresse oder dieser Benutzername ist bereits vergeben.');
        }
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO registration_requests (email, username, first_name, last_name, requested_role)
                 VALUES (:email, :username, :first_name, :last_name, \'trainer\')'
            );
            $statement->execute([
                'email' => $email, 'username' => $username, 'first_name' => $firstName,
                'last_name' => $lastName,
            ]);
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('Für diese E-Mail-Adresse oder diesen Benutzernamen liegt bereits eine Registrierung vor.', 0, $exception);
            }
            throw $exception;
        }

        try {
            $admins = $this->pdo->query(
                "SELECT id, email, first_name, last_name FROM users WHERE role = 'admin' AND status = 'active'"
            )->fetchAll();
        } catch (\PDOException $exception) {
            error_log('Registration notification lookup failed: ' . $exception->getMessage());
            return ['Die Administratoren konnten nicht ermittelt werden.'];
        }
        $errors = [];
        if ($admins === []) {
            error_log('Registration notification failed: no active administrator found.');
            $errors[] = 'Es ist kein aktiver Administrator vorhanden.';
        }
        $name = AuthService::fullName($firstName, $lastName);
        foreach ($admins as $admin) {
            if ($admin['email'] === null || trim((string) $admin['email']) === '') {
                error_log('Registration notification failed: an active administrator has no email address.');
                $errors[] = 'Für ein Administratorkonto ist keine E-Mail-Adresse hinterlegt.';
                continue;
            }
            try {
                $token = Security::randomToken();
                $this->pdo->exec('DELETE FROM registration_access_tokens WHERE expires_at <= UTC_TIMESTAMP()');
                $this->pdo->prepare(
                    'INSERT INTO registration_access_tokens (user_id, token_hash, expires_at)
                     VALUES (:user_id, :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 DAY))'
                )->execute(['user_id' => $admin['id'], 'token_hash' => Security::tokenHash($token)]);
                $url = rtrim($this->config->get('APP_URL'), '/') . '/?page=registration-access&token=' . rawurlencode($token);
                $adminName = AuthService::fullName((string) $admin['first_name'], (string) $admin['last_name']);
                $this->mailer->send(
                    (string) $admin['email'],
                    'Neue Registrierungsanfrage',
                    '<!doctype html><html lang="de"><body style="margin:0;padding:32px 12px;background:#f2f5f3;'
                    . 'font-family:Arial,Helvetica,sans-serif;color:#24312a">'
                    . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
                    . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;'
                    . 'width:100%;background:#fff;border-radius:16px;overflow:hidden">'
                    . '<tr><td style="padding:28px 32px;background:#14532d;color:#fff">'
                    . '<div style="font-size:13px;letter-spacing:1.5px;text-transform:uppercase;opacity:.8">Vereins-Buchungssystem</div>'
                    . '<h1 style="font-size:24px;line-height:1.3;margin:10px 0 0">' . Mailer::icon('registration') . 'Neue Registrierungsanfrage</h1></td></tr>'
                    . '<tr><td style="padding:28px 32px"><p style="font-size:16px;margin:0 0 8px">Hallo '
                    . Security::escape($adminName) . ',</p><p style="color:#536158;margin:0 0 24px">'
                    . 'Eine neue Registrierungsanfrage wartet auf Bearbeitung.</p>'
                    . '<div style="padding:20px;background:#f4f7f5;border:1px solid #e1e9e3;border-radius:12px">'
                    . '<table role="presentation" cellspacing="0" cellpadding="0" style="font-size:14px;line-height:1.7;color:#36443a">'
                    . '<tr><td style="padding-right:14px;color:#647268">Name</td><td>' . Security::escape($name) . '</td></tr>'
                    . '<tr><td style="padding-right:14px;color:#647268">E-Mail</td><td>' . Security::escape($email) . '</td></tr>'
                    . '<tr><td style="padding-right:14px;color:#647268">Benutzername</td><td>' . Security::escape($username) . '</td></tr>'
                    . '</table></div><p>Der Link ist 5 Tage gültig. Die Anmeldung erfolgt wie gewohnt.</p>'
                    . '<div style="text-align:center;padding:26px 0 8px"><a href="' . Security::escape($url)
                    . '" style="display:inline-block;padding:13px 24px;background:#198754;color:#fff;'
                    . 'text-decoration:none;font-weight:bold;border-radius:8px">Registrierungen bearbeiten</a></div></td></tr>'
                    . '<tr><td style="padding:18px 32px;background:#f8faf8;color:#738078;font-size:12px">'
                    . 'Automatische Benachrichtigung des Vereins-Buchungssystems</td></tr></table></td></tr></table></body></html>',
                    "Hallo {$adminName},\n\nEine neue Registrierungsanfrage wartet auf Bearbeitung.\n\nName: {$name}\nE-Mail: {$email}"
                    . "\nBenutzername: {$username}\n\nDer Link ist 5 Tage gültig. Die Anmeldung erfolgt wie gewohnt."
                    . "\nRegistrierungen bearbeiten: {$url}"
                );
            } catch (RuntimeException $exception) {
                error_log('Registration notification failed: ' . $exception->getMessage());
                $errors[] = 'Eine Benachrichtigung konnte nicht versendet werden.';
            }
        }
        return $errors;
    }

    public function registrations(): array
    {
        return $this->pdo->query(
            'SELECT id, email, username, first_name, last_name, requested_role, created_at
             FROM registration_requests ORDER BY created_at, id'
        )->fetchAll();
    }

    public function registrationUsernameSuggestions(string $username): array
    {
        $username = mb_strtolower(trim($username));
        if (!preg_match('/\A[a-z0-9][a-z0-9._-]{2,79}\z/', $username)) {
            return [];
        }
        $query = $this->pdo->prepare(
            'SELECT 1 FROM users WHERE username = :user_name
             UNION ALL SELECT 1 FROM registration_requests WHERE username = :request_name LIMIT 1'
        );
        $isTaken = static function (string $name) use ($query): bool {
            $query->execute(['user_name' => $name, 'request_name' => $name]);
            return $query->fetchColumn() !== false;
        };
        if (!$isTaken($username)) {
            return [];
        }
        $suggestions = [];
        for ($number = 1; $number <= 1000 && count($suggestions) < 3; $number++) {
            $suffix = (string) $number;
            $candidate = substr($username, 0, 80 - strlen($suffix)) . $suffix;
            if (!$isTaken($candidate)) {
                $suggestions[] = $candidate;
            }
        }
        if (count($suggestions) < 3) {
            throw new RuntimeException('Es konnten keine Benutzernamen-Vorschläge erstellt werden. Bitte wähle einen anderen Benutzernamen.');
        }
        return $suggestions;
    }

    public function approveRegistration(int $id, string $role, array $actor): void
    {
        $allowed = match ($actor['role'] ?? '') {
            'admin' => ['trainer', 'manager', 'admin'],
            'manager' => ['trainer', 'manager'],
            default => [],
        };
        if (!in_array($role, $allowed, true)) {
            throw new RuntimeException('Diese Rolle darfst du nicht freigeben.');
        }
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('SELECT * FROM registration_requests WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $id]);
            $request = $statement->fetch();
            if ($request === false) {
                throw new RuntimeException('Die Registrierung wurde bereits bearbeitet oder nicht gefunden.');
            }
            $this->invite($request['email'], $request['username'], $request['first_name'], $request['last_name'], $role);
            $this->pdo->prepare('DELETE FROM registration_requests WHERE id = :id')->execute(['id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function rejectRegistration(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('SELECT * FROM registration_requests WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $id]);
            $request = $statement->fetch();
            if ($request === false) {
                throw new RuntimeException('Die Registrierung wurde bereits bearbeitet oder nicht gefunden.');
            }

            $firstName = $request['first_name'];
            $this->mailer->send(
                $request['email'],
                'Deine Registrierungsanfrage',
                '<!doctype html><html lang="de"><body style="margin:0;padding:32px 12px;background:#f2f5f3;'
                . 'font-family:Arial,Helvetica,sans-serif;color:#24312a">'
                . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
                . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;'
                . 'width:100%;background:#fff;border-radius:16px;overflow:hidden">'
                . '<tr><td style="padding:28px 32px;background:#14532d;color:#fff">'
                . '<div style="font-size:13px;letter-spacing:1.5px;text-transform:uppercase;opacity:.8">Vereins-Buchungssystem</div>'
                . '<h1 style="font-size:24px;line-height:1.3;margin:10px 0 0">' . Mailer::icon('registration') . 'Registrierungsanfrage abgelehnt</h1></td></tr>'
                . '<tr><td style="padding:28px 32px"><p style="font-size:16px;margin:0 0 8px">Hallo '
                . Security::escape($firstName) . ',</p><p style="color:#536158;margin:0">'
                . 'deine Registrierungsanfrage wurde leider abgelehnt. Bei Fragen wende dich bitte an einen Verwalter oder Admin.</p></td></tr>'
                . '<tr><td style="padding:18px 32px;background:#f8faf8;color:#738078;font-size:12px">'
                . 'Automatische Benachrichtigung des Vereins-Buchungssystems</td></tr></table></td></tr></table></body></html>',
                "Hallo {$firstName},\n\ndeine Registrierungsanfrage wurde leider abgelehnt. "
                . 'Bei Fragen wende dich bitte an einen Verwalter oder Admin.'
            );
            $this->pdo->prepare('DELETE FROM registration_requests WHERE id = :id')->execute(['id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function createWithoutEmail(
        string $username,
        string $firstName,
        string $lastName,
        string $role,
        string $password,
        string $passwordConfirmation
    ): void {
        if (!in_array($role, ['admin', 'manager', 'trainer'], true)) {
            throw new RuntimeException('Diese Rolle kann nicht angelegt werden.');
        }

        $username = AuthService::normalizeUsername($username);
        $firstName = AuthService::validateFirstName($firstName);
        $lastName = AuthService::validateLastName($lastName);
        AuthService::validatePassword($password);
        if (!hash_equals($password, $passwordConfirmation)) {
            throw new RuntimeException('Die Passwörter stimmen nicht überein.');
        }

        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO users (email, username, first_name, last_name, password_hash, role, status)
                 VALUES (NULL, :username, :first_name, :last_name, :password_hash, :role, 'active')"
            );
            $statement->execute([
                'username' => $username,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
            ]);
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('Dieser Benutzername ist bereits vergeben.', 0, $exception);
            }
            throw $exception;
        }
    }

    public function acceptInvitation(string $token, string $password): int
    {
        AuthService::validatePassword($password);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "SELECT id FROM users
                 WHERE invite_token_hash = :token_hash
                   AND status = 'invited'
                   AND invite_expires_at > UTC_TIMESTAMP()
                 LIMIT 1 FOR UPDATE"
            );
            $statement->execute(['token_hash' => Security::tokenHash($token)]);
            $id = $statement->fetchColumn();
            if ($id === false) {
                throw new RuntimeException('Der Einladungslink ist ungültig oder abgelaufen.');
            }

            $update = $this->pdo->prepare(
                "UPDATE users
                 SET password_hash = :password_hash, status = 'active',
                     invite_token_hash = NULL, invite_expires_at = NULL
                 WHERE id = :id"
            );
            $update->execute([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $id,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return (int) $id;
    }

    public function requestPasswordReset(string $email): void
    {
        $email = AuthService::normalizeEmail($email);
        $statement = $this->pdo->prepare(
            "SELECT id, first_name, last_name FROM users WHERE email = :email AND status = 'active' LIMIT 1"
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();
        if ($user === false) {
            return;
        }

        $token = Security::randomToken();
        $this->pdo->prepare('DELETE FROM password_resets WHERE user_id = :user_id')
            ->execute(['user_id' => $user['id']]);
        $this->pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 DAY))'
        )->execute([
            'user_id' => $user['id'],
            'token_hash' => Security::tokenHash($token),
        ]);

        $url = rtrim($this->config->get('APP_URL'), '/') . '/?page=reset&token=' . rawurlencode($token);
        $fullName = AuthService::fullName((string) $user['first_name'], (string) $user['last_name']);
        $name = Security::escape($fullName);
        $this->mailer->send(
            $email,
            'Passwort zurücksetzen',
            '<!doctype html><html lang="de"><body style="margin:0;padding:32px 12px;background:#f2f5f3;'
            . 'font-family:Arial,Helvetica,sans-serif;color:#24312a">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
            . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;'
            . 'width:100%;background:#fff;border-radius:16px;overflow:hidden">'
            . '<tr><td style="padding:28px 32px;background:#14532d;color:#fff">'
            . '<div style="font-size:13px;letter-spacing:1.5px;text-transform:uppercase;opacity:.8">Vereins-Buchungssystem</div>'
            . '<h1 style="font-size:24px;line-height:1.3;margin:10px 0 0">' . Mailer::icon('password') . 'Passwort zurücksetzen</h1></td></tr>'
            . '<tr><td style="padding:28px 32px"><p style="font-size:16px;margin:0 0 8px">Hallo ' . $name . ',</p>'
            . '<p style="color:#536158;margin:0 0 24px">Über den folgenden Link kannst du ein neues Passwort festlegen.</p>'
            . '<div style="padding:20px;background:#f4f7f5;border:1px solid #e1e9e3;border-radius:12px;line-height:1.6">'
            . 'Der Link ist 5 Tage gültig und kann nur einmal verwendet werden. '
            . 'Wenn du das Zurücksetzen nicht angefordert hast, kannst du diese E-Mail ignorieren.</div>'
            . '<div style="text-align:center;padding:26px 0 8px"><a href="' . Security::escape($url)
            . '" style="display:inline-block;padding:13px 24px;background:#198754;color:#fff;'
            . 'text-decoration:none;font-weight:bold;border-radius:8px">Passwort zurücksetzen</a></div></td></tr>'
            . '<tr><td style="padding:18px 32px;background:#f8faf8;color:#738078;font-size:12px">'
            . 'Automatische Benachrichtigung des Vereins-Buchungssystems</td></tr></table></td></tr></table></body></html>',
            "Hallo {$fullName},\n\nSetze dein Passwort über diesen Link zurück (5 Tage gültig, einmal verwendbar):\n{$url}"
            . "\n\nWenn du das Zurücksetzen nicht angefordert hast, kannst du diese E-Mail ignorieren."
        );
    }

    public function resetPassword(string $token, string $password): int
    {
        AuthService::validatePassword($password);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "SELECT r.id, r.user_id FROM password_resets r
                 JOIN users u ON u.id = r.user_id AND u.status = 'active'
                 WHERE r.token_hash = :token_hash AND r.expires_at > UTC_TIMESTAMP()
                 LIMIT 1 FOR UPDATE"
            );
            $statement->execute(['token_hash' => Security::tokenHash($token)]);
            $reset = $statement->fetch();
            if ($reset === false) {
                throw new RuntimeException('Der Link zum Zurücksetzen ist ungültig oder abgelaufen.');
            }

            $this->pdo->prepare(
                'UPDATE users SET password_hash = :password_hash WHERE id = :user_id AND status = \'active\''
            )->execute([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'user_id' => $reset['user_id'],
            ]);
            $this->pdo->prepare('DELETE FROM password_resets WHERE user_id = :user_id')
                ->execute(['user_id' => $reset['user_id']]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return (int) $reset['user_id'];
    }

    public function all(): array
    {
        return $this->pdo->query(
            'SELECT id, email, username, first_name, last_name, role, status, created_at
             FROM users ORDER BY first_name, last_name'
        )->fetchAll();
    }

    public function updateDetails(
        int $id,
        string $email,
        string $firstName,
        string $lastName,
        string $role,
        string $status
    ): void {
        if (!in_array($role, ['admin', 'manager', 'trainer'], true)) {
            throw new RuntimeException('Rolle ungültig.');
        }
        if (!in_array($status, ['invited', 'active', 'inactive'], true)) {
            throw new RuntimeException('Kontostatus ungültig.');
        }

        $email = trim($email) === '' ? null : AuthService::normalizeEmail($email);
        $firstName = AuthService::validateFirstName($firstName);
        $lastName = AuthService::validateLastName($lastName);

        $this->pdo->beginTransaction();
        try {
            $target = $this->pdo->prepare(
                'SELECT id, email, username, role, status, password_hash
                 FROM users WHERE id = :id FOR UPDATE'
            );
            $target->execute(['id' => $id]);
            $user = $target->fetch();
            if ($user === false) {
                throw new RuntimeException('Der Benutzer wurde nicht gefunden.');
            }

            if (($user['status'] === 'invited' && $status === 'active')
                || ($user['status'] !== 'invited' && $status === 'invited')) {
                throw new RuntimeException('Der Kontostatus kann nur über den Einladungs- oder Passwortablauf geändert werden.');
            }
            if ($status === 'active' && $user['password_hash'] === null) {
                throw new RuntimeException('Für die Aktivierung muss zuerst ein Passwort festgelegt werden.');
            }

            if ($user['role'] === 'admin' && $user['status'] === 'active'
                && ($role !== 'admin' || $status !== 'active')) {
                $admins = $this->pdo->query(
                    "SELECT id FROM users WHERE role = 'admin' AND status = 'active' FOR UPDATE"
                )->fetchAll();
                if (count($admins) <= 1) {
                    throw new RuntimeException('Der letzte aktive Administrator kann nicht herabgestuft oder deaktiviert werden.');
                }
            }

            $refreshInvitation = $user['status'] === 'invited'
                && $status === 'invited'
                && $email !== ($user['email'] === null ? null : (string) $user['email']);
            if ($status === 'invited' && $email === null) {
                throw new RuntimeException('Eingeladene Benutzer benötigen eine E-Mail-Adresse.');
            }

            $parameters = [
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role' => $role,
                'status' => $status,
                'id' => $id,
            ];
            if ($refreshInvitation) {
                $token = Security::randomToken();
                $statement = $this->pdo->prepare(
                    'UPDATE users
                     SET email = :email, first_name = :first_name, last_name = :last_name,
                         role = :role, status = :status, invite_token_hash = :token_hash,
                         invite_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 DAY)
                     WHERE id = :id'
                );
                $parameters['token_hash'] = Security::tokenHash($token);
                $statement->execute($parameters);

                $this->sendInvitation($email, (string) $user['username'], $firstName, $token, true);
            } else {
                $statement = $this->pdo->prepare(
                    "UPDATE users
                     SET email = :email, first_name = :first_name, last_name = :last_name,
                         role = :role, status = :status,
                         invite_token_hash = CASE WHEN :invite_status = 'inactive' THEN NULL ELSE invite_token_hash END,
                         invite_expires_at = CASE WHEN :invite_expiry_status = 'inactive' THEN NULL ELSE invite_expires_at END
                     WHERE id = :id"
                );
                $parameters['invite_status'] = $status;
                $parameters['invite_expiry_status'] = $status;
                $statement->execute($parameters);
            }

            $this->pdo->commit();
        } catch (\PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('Diese E-Mail-Adresse ist bereits vergeben.', 0, $exception);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function updateOwnProfile(int $userId, string $email, string $firstName, string $lastName): void
    {
        $email = trim($email) === '' ? null : AuthService::normalizeEmail($email);
        $firstName = AuthService::validateFirstName($firstName);
        $lastName = AuthService::validateLastName($lastName);

        try {
            $statement = $this->pdo->prepare(
                "UPDATE users
                 SET email = :email, first_name = :first_name, last_name = :last_name
                 WHERE id = :id AND status = 'active'"
            );
            $statement->execute([
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'id' => $userId,
            ]);
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('Diese E-Mail-Adresse ist bereits vergeben.', 0, $exception);
            }
            throw $exception;
        }
    }

    public function setPassword(int $id, string $password, string $passwordConfirmation): void
    {
        AuthService::validatePassword($password);
        if (!hash_equals($password, $passwordConfirmation)) {
            throw new RuntimeException('Die Passwörter stimmen nicht überein.');
        }

        $this->pdo->beginTransaction();
        try {
            $target = $this->pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
            $target->execute(['id' => $id]);
            if ($target->fetchColumn() === false) {
                throw new RuntimeException('Der Benutzer wurde nicht gefunden.');
            }

            $this->pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id')
                ->execute([
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'id' => $id,
                ]);
            $this->pdo->prepare('DELETE FROM password_resets WHERE user_id = :user_id')
                ->execute(['user_id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function deactivate(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $target = $this->pdo->prepare('SELECT id, role, status FROM users WHERE id = :id FOR UPDATE');
            $target->execute(['id' => $id]);
            $user = $target->fetch();
            if ($user === false || $user['status'] === 'inactive') {
                throw new RuntimeException('Das Konto wurde nicht gefunden oder ist bereits deaktiviert.');
            }

            if ($user['role'] === 'admin') {
                $admins = $this->pdo->query(
                    "SELECT id FROM users WHERE role = 'admin' AND status = 'active' FOR UPDATE"
                )->fetchAll();
                $adminCount = count($admins);
                if ($user['status'] === 'active' && $adminCount <= 1) {
                    throw new RuntimeException('Der letzte aktive Administrator kann nicht deaktiviert werden.');
                }
            }

            $this->pdo->prepare(
                "UPDATE users SET status = 'inactive', invite_token_hash = NULL, invite_expires_at = NULL
                 WHERE id = :id"
            )->execute(['id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function delete(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $target = $this->pdo->prepare('SELECT id, role, status FROM users WHERE id = :id FOR UPDATE');
            $target->execute(['id' => $id]);
            $user = $target->fetch();
            if ($user === false) {
                throw new RuntimeException('Der Benutzer wurde nicht gefunden.');
            }

            if ($user['role'] === 'admin' && $user['status'] === 'active') {
                $admins = $this->pdo->query(
                    "SELECT id FROM users WHERE role = 'admin' AND status = 'active' FOR UPDATE"
                )->fetchAll();
                if (count($admins) <= 1) {
                    throw new RuntimeException('Der letzte aktive Administrator kann nicht gelöscht werden.');
                }
            }

            $this->pdo->prepare(
                'UPDATE booking_groups
                 SET created_by = CASE WHEN created_by = :created_by_set THEN NULL ELSE created_by END,
                     requested_by = CASE WHEN requested_by = :requested_by_set THEN NULL ELSE requested_by END
                 WHERE created_by = :created_by_match OR requested_by = :requested_by_match'
            )->execute([
                'created_by_set' => $id,
                'requested_by_set' => $id,
                'created_by_match' => $id,
                'requested_by_match' => $id,
            ]);
            $this->pdo->prepare('UPDATE booking_overrides SET actor_id = NULL WHERE actor_id = :actor_id')
                ->execute(['actor_id' => $id]);
            $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function changeRole(int $id, string $role): void
    {
        if (!in_array($role, ['admin', 'manager', 'trainer'], true)) {
            throw new RuntimeException('Rolle ungültig.');
        }

        $this->pdo->beginTransaction();
        try {
            $target = $this->pdo->prepare('SELECT id, role, status FROM users WHERE id = :id FOR UPDATE');
            $target->execute(['id' => $id]);
            $user = $target->fetch();
            if ($user === false || $user['status'] === 'inactive') {
                throw new RuntimeException('Das Konto wurde nicht gefunden oder ist deaktiviert.');
            }

            if ($user['role'] === 'admin' && $user['status'] === 'active' && $role !== 'admin') {
                $admins = $this->pdo->query(
                    "SELECT id FROM users WHERE role = 'admin' AND status = 'active' FOR UPDATE"
                )->fetchAll();
                if (count($admins) <= 1) {
                    throw new RuntimeException('Die Rolle des letzten aktiven Administrators kann nicht geändert werden.');
                }
            }

            $this->pdo->prepare('UPDATE users SET role = :role WHERE id = :id')
                ->execute(['role' => $role, 'id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
