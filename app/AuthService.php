<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class AuthService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config
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
        string $setupToken,
        string $email,
        string $username,
        string $firstName,
        string $lastName,
        string $password
    ): void {
        $expectedToken = $this->config->get('APP_SETUP_TOKEN');
        if ($expectedToken === '' || strlen($expectedToken) < 32 || !hash_equals($expectedToken, $setupToken)) {
            throw new RuntimeException('Der Setup-Schlüssel ist ungültig.');
        }

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
                'email' => $this->normalizeEmail($email),
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
