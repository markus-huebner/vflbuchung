<?php
declare(strict_types=1);

namespace App;

final class Config
{
    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function fromEnvironment(): self
    {
        $defaults = [
            'APP_ENV' => 'local',
            'APP_URL' => 'http://localhost:8100',
            'APP_SETUP_TOKEN' => '',
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

        $values = [];
        foreach ($defaults as $key => $default) {
            $value = getenv($key);
            $values[$key] = $value === false ? $default : $value;
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
