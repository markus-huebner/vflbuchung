<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class Security
{
    public static function startSession(): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('verein_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        if (!isset($_SESSION['created_at'])) {
            session_regenerate_id(true);
            $_SESSION['created_at'] = time();
        } elseif (time() - (int) $_SESSION['created_at'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['created_at'] = time();
        }
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }

    public static function rotateCsrfToken(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    public static function verifyCsrf(?string $token): void
    {
        if ($token === null || !hash_equals(self::csrfToken(), $token)) {
            throw new RuntimeException('Die Sitzung ist abgelaufen. Bitte lade die Seite neu.');
        }
    }

    public static function escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function randomToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }
}
