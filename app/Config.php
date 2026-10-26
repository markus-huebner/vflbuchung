<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class Config
{
    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function fromEnvironment(?string $runtimeConfigPath = null): self
    {
        $defaults = [
            'APP_ENV' => 'local',
            'APP_URL' => 'http://localhost:8100',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_NAME' => 'belegungsplan',
            'DB_USER' => '',
            'DB_PASSWORD' => '',
            'MAIL_HOST' => '',
            'MAIL_PORT' => '587',
            'MAIL_USERNAME' => '',
            'MAIL_PASSWORD' => '',
            'MAIL_ENCRYPTION' => 'tls',
            'MAIL_FROM' => '',
            'MAIL_FROM_NAME' => 'Platz- und Busbelegung',
        ];

        $hostConfigPath = dirname(__DIR__) . '/config/hosting.php';
        $hostValues = [];
        if (is_file($hostConfigPath)) {
            $hostValues = require $hostConfigPath;
            if (!is_array($hostValues)) {
                throw new RuntimeException('Die Hosting-Konfiguration ist ungültig.');
            }
            foreach ($hostValues as $key => $value) {
                if ($key === 'APP_SETUP_TOKEN') {
                    continue;
                }
                if (!array_key_exists($key, $defaults) || !is_string($value)) {
                    throw new RuntimeException('Die Hosting-Konfiguration enthält ungültige Werte.');
                }
            }
        }

        $values = [];
        $runtimeValues = (new RuntimeConfiguration($runtimeConfigPath))->load();
        foreach ($defaults as $key => $default) {
            $value = getenv($key);
            $values[$key] = $runtimeValues[$key]
                ?? ($value === false ? ($hostValues[$key] ?? $default) : $value);
        }

        return new self($values);
    }

    public function withOverrides(array $overrides): self
    {
        $values = $this->values;
        foreach ($overrides as $key => $value) {
            if (!array_key_exists($key, $values) || !is_string($value)) {
                throw new RuntimeException('Die Anwendungskonfiguration enthält ungültige Werte.');
            }
            $values[$key] = $value;
        }

        return new self($values);
    }

    public function get(string $key): string
    {
        return $this->values[$key] ?? '';
    }

    public function isLocal(): bool
    {
        return $this->get('APP_ENV') === 'local';
    }
}
