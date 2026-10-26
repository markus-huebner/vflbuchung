<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class Config
{
    private array $values;

    private function __construct(array $values, private readonly bool $environmentFileExists)
    {
        $this->values = $values;
    }

    public static function fromEnvironment(
        ?string $runtimeConfigPath = null,
        ?string $environmentFilePath = null
    ): self
    {
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
                if (!in_array($key, EnvironmentFile::KEYS, true) || !is_string($value)) {
                    throw new RuntimeException('Die Hosting-Konfiguration enthält ungültige Werte.');
                }
            }
        }

        $environmentPath = $environmentFilePath ?? self::environmentFilePath();
        $environmentFileValues = EnvironmentFile::read($environmentPath);
        $values = [];
        $runtimeValues = (new RuntimeConfiguration($runtimeConfigPath))->load();
        foreach (EnvironmentFile::KEYS as $key) {
            $value = getenv($key);
            $configuredValue = $runtimeValues[$key]
                ?? ($value === false
                    ? ($environmentFileValues[$key] ?? ($hostValues[$key] ?? null))
                    : $value);
            if ($configuredValue !== null) {
                $values[$key] = $configuredValue;
            }
        }

        return new self($values, is_file($environmentPath));
    }

    public static function environmentFilePath(): string
    {
        return dirname(__DIR__) . '/config/.env';
    }

    public function withOverrides(array $overrides): self
    {
        $values = $this->values;
        foreach ($overrides as $key => $value) {
            if (!in_array($key, EnvironmentFile::KEYS, true) || !is_string($value)) {
                throw new RuntimeException('Die Anwendungskonfiguration enthält ungültige Werte.');
            }
            $values[$key] = $value;
        }

        return new self($values, $this->environmentFileExists);
    }

    public function isSetupRequired(): bool
    {
        foreach (EnvironmentFile::KEYS as $key) {
            if (!$this->has($key)) {
                return true;
            }
        }
        foreach (['APP_ENV', 'APP_URL', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'MAIL_FROM', 'MAIL_FROM_NAME'] as $key) {
            if (trim($this->get($key)) === '') {
                return true;
            }
        }
        if (!in_array($this->get('APP_ENV'), ['local', 'production'], true)
            || !in_array($this->get('MAIL_ENCRYPTION'), ['', 'tls', 'ssl'], true)) {
            return true;
        }
        $portKeys = ['DB_PORT'];
        if ($this->get('MAIL_HOST') !== '' || $this->get('MAIL_PORT') !== '') {
            $portKeys[] = 'MAIL_PORT';
        }
        foreach ($portKeys as $key) {
            $port = filter_var($this->get($key), FILTER_VALIDATE_INT);
            if ($port === false || $port < 1 || $port > 65535) {
                return true;
            }
        }
        if ($this->get('MAIL_USERNAME') !== '' && $this->get('MAIL_PASSWORD') === '') {
            return true;
        }

        return $this->get('APP_ENV') === 'production' && !$this->environmentFileExists;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key): string
    {
        if (!$this->has($key)) {
            throw new RuntimeException('Der Konfigurationswert ' . $key . ' fehlt.');
        }
        return $this->values[$key];
    }

    public function isLocal(): bool
    {
        return $this->has('APP_ENV') && $this->get('APP_ENV') === 'local';
    }
}
