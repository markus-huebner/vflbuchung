<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class DatabaseSetup
{
    private const TABLES = [
        'users',
        'password_resets',
        'login_attempts',
        'resource_categories',
        'resources',
        'booking_groups',
        'booking_occurrences',
        'booking_resource_assignments',
        'booking_cabin_assignments',
        'booking_overrides',
        'app_settings',
    ];

    private const DROP_ORDER = [
        'booking_overrides',
        'booking_cabin_assignments',
        'booking_resource_assignments',
        'booking_occurrences',
        'booking_groups',
        'password_resets',
        'resources',
        'resource_categories',
        'login_attempts',
        'app_settings',
        'users',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $databaseDirectory = __DIR__ . '/../database'
    ) {
    }

    public function status(): array
    {
        $statement = $this->pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        );
        $existing = array_fill_keys(
            array_map(static fn(array $row): string => (string) $row['TABLE_NAME'], $statement->fetchAll()),
            true
        );
        $applicationTables = array_values(array_filter(
            self::TABLES,
            static fn(string $table): bool => isset($existing[$table])
        ));

        return [
            'existing_table_count' => count($applicationTables),
            'table_count' => count(self::TABLES),
            'schema_complete' => count($applicationTables) === count(self::TABLES),
        ];
    }

    public function hasTable(string $table): bool
    {
        if (!in_array($table, self::TABLES, true)) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
        );
        $statement->execute(['table_name' => $table]);

        return $statement->fetchColumn() !== false;
    }

    public function initialize(bool $recreate): void
    {
        if ($recreate) {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach (self::DROP_ORDER as $table) {
                    $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
                }
            } finally {
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        }

        $this->executeSqlFile($this->databaseDirectory . '/install.sql');
        $this->executeSqlFile($this->databaseDirectory . '/seed_resources.sql');
    }

    private function executeSqlFile(string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Die Datenbankdatei konnte nicht gelesen werden: ' . basename($path));
        }

        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') {
                $this->pdo->exec($statement);
            }
        }
    }
}
