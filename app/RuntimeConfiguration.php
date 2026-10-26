<?php
declare(strict_types=1);

namespace App;

use JsonException;
use RuntimeException;

final class RuntimeConfiguration
{
    public const KEYS = [
        'APP_URL',
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'MAIL_FROM',
        'MAIL_FROM_NAME',
    ];

    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? dirname(__DIR__) . '/storage/setup-config.json';
    }

    public function load(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $contents = file_get_contents($this->path);
        if ($contents === false) {
            throw new RuntimeException('Die gespeicherte Anwendungskonfiguration kann nicht gelesen werden.');
        }

        try {
            $values = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Die gespeicherte Anwendungskonfiguration ist ungültig.', 0, $exception);
        }

        if (!is_array($values) || array_is_list($values)) {
            throw new RuntimeException('Die gespeicherte Anwendungskonfiguration ist ungültig.');
        }

        foreach ($values as $key => $value) {
            if (!in_array($key, self::KEYS, true) || !is_string($value)) {
                throw new RuntimeException('Die gespeicherte Anwendungskonfiguration enthält ungültige Werte.');
            }
        }

        return $values;
    }

    public function save(array $values): void
    {
        foreach (self::KEYS as $key) {
            if (!isset($values[$key]) || !is_string($values[$key])) {
                throw new RuntimeException('Die Anwendungskonfiguration ist unvollständig.');
            }
        }
        if (count($values) !== count(self::KEYS) || array_diff(array_keys($values), self::KEYS) !== []) {
            throw new RuntimeException('Die Anwendungskonfiguration enthält nicht erlaubte Werte.');
        }

        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Der geschützte Konfigurationsordner kann nicht angelegt werden.');
        }
        if (!is_writable($directory) || is_link($this->path)) {
            throw new RuntimeException('Der geschützte Konfigurationsordner ist nicht beschreibbar.');
        }

        try {
            $contents = json_encode($values, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Die Anwendungskonfiguration kann nicht gespeichert werden.', 0, $exception);
        }

        $temporaryPath = tempnam($directory, '.setup-config-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Eine temporäre Konfigurationsdatei kann nicht angelegt werden.');
        }

        try {
            if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false || !chmod($temporaryPath, 0600)) {
                throw new RuntimeException('Die Anwendungskonfiguration kann nicht sicher geschrieben werden.');
            }
            if (!rename($temporaryPath, $this->path) || !chmod($this->path, 0600)) {
                throw new RuntimeException('Die Anwendungskonfiguration kann nicht sicher übernommen werden.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}
