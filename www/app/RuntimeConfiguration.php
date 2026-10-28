<?php
declare(strict_types=1);

namespace App;

use JsonException;
use RuntimeException;

final class RuntimeConfiguration
{
    public const KEYS = EnvironmentFile::KEYS;

    private readonly string $path;
    private readonly ?string $legacyPath;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? Config::environmentFilePath();
        $this->legacyPath = $path === null
            ? dirname(__DIR__) . '/storage/setup-config.json'
            : null;
    }

    public function load(): array
    {
        if (is_file($this->path)) {
            return array_intersect_key(
                EnvironmentFile::read($this->path),
                array_flip(self::KEYS)
            );
        }

        if ($this->legacyPath === null || !is_file($this->legacyPath)) {
            return [];
        }

        $contents = file_get_contents($this->legacyPath);
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
            if (in_array($key, EnvironmentFile::OPTIONAL_KEYS, true) && !array_key_exists($key, $values)) {
                continue;
            }
            if (!isset($values[$key]) || !is_string($values[$key])) {
                throw new RuntimeException('Die Anwendungskonfiguration ist unvollständig.');
            }
        }
        if (array_diff(array_keys($values), self::KEYS) !== []) {
            throw new RuntimeException('Die Anwendungskonfiguration enthält nicht erlaubte Werte.');
        }

        $existingValues = EnvironmentFile::read($this->path);
        EnvironmentFile::write($this->path, array_replace($existingValues, $values));
    }
}
