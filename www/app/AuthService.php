<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class AuthService
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function currentUser(): ?array
    {
        $id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id === null) {
            return null;
        }

        $statement = $this->pdo->prepare(
            "SELECT id, email, username, first_name, last_name, role
             FROM users
             WHERE id = :id AND status = 'active'"
        );
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();
        if ($user === false) {
            $this->logout();
            return null;
        }

        return $user;
    }

    public function authenticate(string $identifier, string $password): bool
    {
        $identifier = mb_strtolower(trim($identifier));
        if (str_contains($identifier, '@')) {
            $statement = $this->pdo->prepare(
                'SELECT id, password_hash, status FROM users WHERE email = :identifier LIMIT 1'
            );
        } else {
            $statement = $this->pdo->prepare(
                'SELECT id, password_hash, status FROM users WHERE username = :identifier LIMIT 1'
            );
        }
        $statement->execute(['identifier' => $identifier]);
        $user = $statement->fetch();

        $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $hash = is_array($user) && is_string($user['password_hash'])
            ? $user['password_hash']
            : $dummyHash;
        $validPassword = password_verify($password, $hash);

        if (!is_array($user) || $user['status'] !== 'active' || !$validPassword) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['created_at'] = time();
        Security::rotateCsrfToken();

        return true;
    }

    public function createBookingAccessToken(int $userId, ?int $targetOccurrenceId = null): string
    {
        $token = Security::randomToken();
        $this->pdo->exec('DELETE FROM booking_access_tokens WHERE expires_at <= UTC_TIMESTAMP()');
        $statement = $this->pdo->prepare(
            "INSERT INTO booking_access_tokens (user_id, target_occurrence_id, token_hash, expires_at)
             SELECT id, :target_occurrence_id, :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 48 HOUR)
             FROM users WHERE id = :user_id AND status = 'active'"
        );
        $statement->execute([
            'user_id' => $userId,
            'target_occurrence_id' => $targetOccurrenceId,
            'token_hash' => Security::tokenHash($token),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Für das Empfängerkonto konnte kein Anmeldelink erstellt werden.');
        }

        return $token;
    }

    public function authenticateBookingAccessToken(string $token): ?int
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            throw new RuntimeException('Der Anmeldelink ist ungültig oder abgelaufen.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "SELECT t.user_id, t.target_occurrence_id
                 FROM booking_access_tokens t
                 JOIN users u ON u.id = t.user_id
                 WHERE t.token_hash = :token_hash
                   AND t.expires_at > UTC_TIMESTAMP()
                   AND u.status = 'active'
                 LIMIT 1 FOR UPDATE"
            );
            $statement->execute(['token_hash' => Security::tokenHash($token)]);
            $access = $statement->fetch();
            if ($access === false) {
                throw new RuntimeException('Der Anmeldelink ist ungültig oder abgelaufen.');
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $this->loginAfterEmailVerification((int) $access['user_id']);

        return $access['target_occurrence_id'] === null
            ? null
            : (int) $access['target_occurrence_id'];
    }

    public function prepareEmailAction(string $page, string $token): void
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            throw new RuntimeException('Der Link ist ungültig oder abgelaufen.');
        }
        $sql = match ($page) {
            'invite' => "SELECT id FROM users
                         WHERE invite_token_hash = :token_hash AND status = 'invited'
                           AND invite_expires_at > UTC_TIMESTAMP()",
            'reset' => "SELECT r.user_id FROM password_resets r
                        JOIN users u ON u.id = r.user_id AND u.status = 'active'
                        WHERE r.token_hash = :token_hash AND r.expires_at > UTC_TIMESTAMP()",
        };
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['token_hash' => Security::tokenHash($token)]);
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('Der Link ist ungültig oder abgelaufen.');
        }
        if (isset($_SESSION['user_id'])) {
            $this->replaceEmailSession(null);
        }
    }

    public function loginAfterEmailVerification(int $userId): void
    {
        $statement = $this->pdo->prepare("SELECT id FROM users WHERE id = :id AND status = 'active'");
        $statement->execute(['id' => $userId]);
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('Das Empfängerkonto ist nicht mehr aktiv.');
        }
        $this->replaceEmailSession($userId);
    }

    private function replaceEmailSession(?int $userId): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        if ($userId !== null) {
            $_SESSION['user_id'] = $userId;
        }
        $_SESSION['created_at'] = time();
        Security::rotateCsrfToken();
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    public function createInitialAdmin(
        string $email,
        string $username,
        string $firstName,
        string $lastName,
        string $password
    ): void {
        $this->validatePassword($password);
        $username = self::normalizeUsername($username);
        $firstName = self::validateFirstName($firstName);
        $lastName = self::validateLastName($lastName);
        $this->pdo->beginTransaction();
        try {
            $setting = $this->pdo->query(
                "SELECT setting_value FROM app_settings WHERE setting_key = 'initial_admin_created' FOR UPDATE"
            )->fetchColumn();
            $adminCount = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM users WHERE role = 'admin'"
            )->fetchColumn();

            if ($setting !== '0' || $adminCount > 0) {
                throw new RuntimeException('Der erste Administrator wurde bereits eingerichtet.');
            }

            $statement = $this->pdo->prepare(
                "INSERT INTO users (email, username, first_name, last_name, password_hash, role, status)
                 VALUES (:email, :username, :first_name, :last_name, :password_hash, 'admin', 'active')"
            );
            $statement->execute([
                'email' => self::normalizeEmail($email),
                'username' => $username,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $this->pdo->prepare(
                "UPDATE app_settings SET setting_value = '1' WHERE setting_key = 'initial_admin_created'"
            )->execute();
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

    public function loginAfterSetup(string $email): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $statement->execute(['email' => $this->normalizeEmail($email)]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('Das Administratorkonto konnte nicht geladen werden.');
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $id;
        $_SESSION['created_at'] = time();
        Security::rotateCsrfToken();
    }

    public static function normalizeEmail(string $email): string
    {
        $normalized = mb_strtolower(trim($email));
        if (!filter_var($normalized, FILTER_VALIDATE_EMAIL) || strlen($normalized) > 254) {
            throw new RuntimeException('Bitte gib eine gültige E-Mail-Adresse an.');
        }

        return $normalized;
    }

    public static function validateFirstName(string $firstName): string
    {
        $firstName = trim($firstName);
        if ($firstName === '' || mb_strlen($firstName) > 120) {
            throw new RuntimeException('Der Vorname muss zwischen 1 und 120 Zeichen lang sein.');
        }

        return $firstName;
    }

    public static function validateLastName(string $lastName): string
    {
        $lastName = trim($lastName);
        if (mb_strlen($lastName) > 120) {
            throw new RuntimeException('Der Nachname darf höchstens 120 Zeichen lang sein.');
        }

        return $lastName;
    }

    public static function fullName(string $firstName, string $lastName): string
    {
        return trim($firstName . ' ' . $lastName);
    }

    public static function normalizeUsername(string $username): string
    {
        $username = mb_strtolower(trim($username));
        if (!preg_match('/\A[a-z0-9][a-z0-9._-]{2,79}\z/', $username)) {
            throw new RuntimeException('Der Benutzername muss 3 bis 80 Zeichen lang sein und darf nur Buchstaben, Zahlen, Punkt, Bindestrich und Unterstrich enthalten.');
        }

        return $username;
    }

    public static function validatePassword(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 200) {
            throw new RuntimeException('Das Passwort muss zwischen 12 und 200 Zeichen lang sein.');
        }
    }
}
