<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class EnvironmentFile
{
    public const OPTIONAL_KEYS = [
        'MAIL_REPLY_TO',
        // Legacy SMTP settings remain readable but are no longer used for delivery.
        'MAIL_HOST',
        'MAIL_PORT',
        'MAIL_USERNAME',
        'MAIL_PASSWORD',
        'MAIL_ENCRYPTION',
    ];

    public const KEYS = [
        'APP_ENV',
        'APP_URL',
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'MAIL_HOST',
        'MAIL_PORT',
        'MAIL_USERNAME',
        'MAIL_PASSWORD',
        'MAIL_ENCRYPTION',
        'MAIL_FROM',
        'MAIL_FROM_NAME',
        'MAIL_REPLY_TO',
    ];

    public static function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Die .env-Datei kann nicht gelesen werden.');
        }

        $values = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $matches)) {
                continue;
            }

            $key = $matches[1];
            if (in_array($key, self::KEYS, true)) {
                $values[$key] = self::parseValue($matches[2]);
            }
        }

        return $values;
    }

    public static function write(string $path, array $values): void
    {
        foreach ($values as $key => $value) {
            if (!in_array($key, self::KEYS, true) || !is_string($value)) {
                throw new RuntimeException('Die .env-Datei enthält ungültige Werte.');
            }
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Das Konfigurationsverzeichnis kann nicht angelegt werden.');
        }
        if (!is_writable($directory) || is_link($path)) {
            throw new RuntimeException('Das Konfigurationsverzeichnis ist nicht sicher beschreibbar.');
        }

        $lines = [];
        foreach ($values as $key => $value) {
            $lines[] = $key . "='" . str_replace("'", "'\\''", $value) . "'";
        }
        $contents = implode(PHP_EOL, $lines) . PHP_EOL;

        $temporaryPath = tempnam($directory, '.env-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Eine temporäre .env-Datei kann nicht angelegt werden.');
        }

        try {
            if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false || !chmod($temporaryPath, 0600)) {
                throw new RuntimeException('Die .env-Datei kann nicht sicher geschrieben werden.');
            }
            if (!rename($temporaryPath, $path) || !chmod($path, 0600)) {
                throw new RuntimeException('Die .env-Datei kann nicht sicher übernommen werden.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private static function parseValue(string $value): string
    {
        $parsed = '';
        $pendingWhitespace = '';
        $quote = null;
        $escaped = false;
        $value = trim($value);

        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            $character = $value[$index];
            if ($escaped) {
                $parsed .= $quote === '"'
                    ? match ($character) {
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        default => $character,
                    }
                    : $character;
                $escaped = false;
                continue;
            }

            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                } elseif ($character === '\\' && $quote === '"') {
                    $escaped = true;
                } else {
                    $parsed .= $character;
                }
                continue;
            }

            if (ctype_space($character)) {
                $pendingWhitespace .= $character;
            } elseif ($character === '#' && ($index === 0 || $pendingWhitespace !== '')) {
                break;
            } elseif ($character === "'" || $character === '"') {
                $parsed .= $pendingWhitespace;
                $pendingWhitespace = '';
                $quote = $character;
            } elseif ($character === '\\') {
                $parsed .= $pendingWhitespace;
                $pendingWhitespace = '';
                $escaped = true;
            } else {
                $parsed .= $pendingWhitespace;
                $pendingWhitespace = '';
                $parsed .= $character;
            }
        }

        if ($quote !== null || $escaped) {
            throw new RuntimeException('Die .env-Datei enthält einen ungültigen Wert.');
        }

        return $parsed;
    }
}
