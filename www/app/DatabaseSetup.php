<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

final class DatabaseSetup
{
    private const TABLES = [
        'users',
        'password_resets',
        'booking_access_tokens',
        'login_attempts',
        'resource_categories',
        'resources',
        'booking_groups',
        'booking_types',
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
        'booking_types',
        'booking_occurrences',
        'booking_groups',
        'booking_access_tokens',
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

    public function migrate(): void
    {
        if (!$this->hasTable('booking_types') || !$this->hasTable('users')) {
            return;
        }

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS booking_access_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                target_occurrence_id BIGINT UNSIGNED NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_booking_access_tokens_hash (token_hash),
                KEY idx_booking_access_tokens_user (user_id),
                KEY idx_booking_access_tokens_expiry (expires_at),
                CONSTRAINT fk_booking_access_tokens_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
        );

        if (!$this->hasColumn('booking_types', 'trainer_requestable')) {
            $addedColumn = false;
            try {
                $this->pdo->exec(
                    'ALTER TABLE booking_types
                     ADD COLUMN trainer_requestable TINYINT(1) NOT NULL DEFAULT 1'
                );
                $addedColumn = true;
            } catch (PDOException $exception) {
                if (($exception->errorInfo[1] ?? null) !== 1060
                    || !$this->hasColumn('booking_types', 'trainer_requestable')) {
                    throw $exception;
                }
            }
            if ($addedColumn) {
                $this->pdo->exec(
                    "UPDATE booking_types SET trainer_requestable = 0 WHERE code = 'external'"
                );
            }
        }

        if ($this->hasTable('app_settings')) {
            $migrationKey = 'external_booking_type_renamed';
            $migration = $this->pdo->prepare(
                'SELECT 1 FROM app_settings WHERE setting_key = :setting_key'
            );
            $migration->execute(['setting_key' => $migrationKey]);
            if ($migration->fetchColumn() === false) {
                $this->pdo->exec(
                    "UPDATE booking_types SET name = 'Extern'
                     WHERE code = 'external' AND name = 'Externe Belegung'"
                );
                $statement = $this->pdo->prepare(
                    'INSERT INTO app_settings (setting_key, setting_value)
                     VALUES (:setting_key, :setting_value)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
                );
                $statement->execute([
                    'setting_key' => $migrationKey,
                    'setting_value' => '1',
                ]);
            }
        }
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

        $this->migrate();
        $this->executeSqlFile($this->databaseDirectory . '/install.sql');
        $this->executeSqlFile($this->databaseDirectory . '/seed_resources.sql');
    }

    private function hasColumn(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name
             AND COLUMN_NAME = :column_name'
        );
        $statement->execute(['table_name' => $table, 'column_name' => $column]);

        return $statement->fetchColumn() !== false;
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
