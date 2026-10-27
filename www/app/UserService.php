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
        $expiresAt = (new \DateTimeImmutable('+48 hours'))->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO users (email, username, first_name, last_name, role, status, invite_token_hash, invite_expires_at)
                 VALUES (:email, :username, :first_name, :last_name, :role, 'invited', :token_hash, :expires_at)"
            );
            $statement->execute([
                'email' => $email,
                'username' => $username,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role' => $role,
                'token_hash' => Security::tokenHash($token),
                'expires_at' => $expiresAt,
            ]);

            $url = $this->config->get('APP_URL') . '/?page=invite&token=' . rawurlencode($token);
            $this->mailer->send(
                $email,
                'Einladung zum Vereins-Buchungssystem',
                '<p>Hallo ' . Security::escape($firstName) . ',</p><p>Dein Benutzername lautet <strong>'
                    . Security::escape($username) . '</strong>. Richte dein Konto über den folgenden Link ein. '
                    . 'Der Link ist 48 Stunden gültig.</p><p><a href="' . Security::escape($url)
                    . '">Konto einrichten</a></p>',
                "Hallo {$firstName},\n\nDein Benutzername lautet: {$username}\n\n"
                    . "Richte dein Konto über diesen Link ein (48 Stunden gültig):\n{$url}"
            );
            $this->pdo->commit();
        } catch (\PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('Diese E-Mail-Adresse oder dieser Benutzername ist bereits vergeben.', 0, $exception);
            }
            throw $exception;
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

    public function acceptInvitation(string $token, string $password): void
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
             VALUES (:user_id, :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))'
        )->execute([
            'user_id' => $user['id'],
            'token_hash' => Security::tokenHash($token),
        ]);

        $url = $this->config->get('APP_URL') . '/?page=reset&token=' . rawurlencode($token);
        $fullName = AuthService::fullName((string) $user['first_name'], (string) $user['last_name']);
        $name = Security::escape($fullName);
        $this->mailer->send(
            $email,
            'Passwort zurücksetzen',
            '<p>Hallo ' . $name . ',</p><p>Über den folgenden Link kannst du dein Passwort zurücksetzen. Er ist eine Stunde gültig.</p><p><a href="' . Security::escape($url) . '">Passwort zurücksetzen</a></p>',
            "Hallo {$fullName},\n\nSetze dein Passwort über diesen Link zurück (1 Stunde gültig):\n{$url}"
        );
    }

    public function resetPassword(string $token, string $password): void
    {
        AuthService::validatePassword($password);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, user_id FROM password_resets
                 WHERE token_hash = :token_hash AND expires_at > UTC_TIMESTAMP()
                 LIMIT 1 FOR UPDATE'
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
                         invite_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 48 HOUR)
                     WHERE id = :id'
                );
                $parameters['token_hash'] = Security::tokenHash($token);
                $statement->execute($parameters);

                $url = $this->config->get('APP_URL') . '/?page=invite&token=' . rawurlencode($token);
                $this->mailer->send(
                    $email,
                    'Aktualisierte Einladung zum Vereins-Buchungssystem',
                    '<p>Hallo ' . Security::escape($firstName) . ',</p><p>Dein Benutzername lautet <strong>'
                        . Security::escape((string) $user['username'])
                        . '</strong>. Richte dein Konto über den folgenden Link ein. Der Link ist 48 Stunden gültig.</p>'
                        . '<p><a href="' . Security::escape($url) . '">Konto einrichten</a></p>',
                    "Hallo {$firstName},\n\nDein Benutzername lautet: {$user['username']}\n\n"
                        . "Richte dein Konto über diesen Link ein (48 Stunden gültig):\n{$url}"
                );
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
