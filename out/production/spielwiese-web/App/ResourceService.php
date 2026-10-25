<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class ResourceService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(bool $includeInactive = false): array
    {
        $sql = 'SELECT r.id, rc.code AS type, rc.name AS category_name, r.category_id, r.name, r.is_active
                FROM resources r
                JOIN resource_categories rc ON rc.id = r.category_id';
        if (!$includeInactive) {
            $sql .= ' WHERE r.is_active = 1';
        }
        $sql .= ' ORDER BY rc.sort_order, r.sort_order, r.name';

        return $this->pdo->query($sql)->fetchAll();
    }

    public function categories(): array
    {
        return $this->pdo->query(
            'SELECT id, code, name FROM resource_categories ORDER BY sort_order, name'
        )->fetchAll();
    }

    public function save(string $type, string $name, ?int $id = null): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new RuntimeException('Der Ressourcenname muss zwischen 1 und 120 Zeichen lang sein.');
        }

        if ($id === null) {
            $categoryId = $this->categoryId($type);
            $nextOrder = $this->pdo->prepare(
                'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM resources WHERE category_id = :category_id'
            );
            $nextOrder->execute(['category_id' => $categoryId]);
            $statement = $this->pdo->prepare(
                'INSERT INTO resources (category_id, sort_order, name)
                 VALUES (:category_id, :sort_order, :name)'
            );
            $statement->execute([
                'category_id' => $categoryId,
                'sort_order' => $nextOrder->fetchColumn(),
                'name' => $name,
            ]);
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare(
                'SELECT r.id, rc.code AS type
                 FROM resources r
                 JOIN resource_categories rc ON rc.id = r.category_id
                 WHERE r.id = :id
                 FOR UPDATE'
            );
            $existing->execute(['id' => $id]);
            $resource = $existing->fetch();
            if ($resource === false) {
                throw new RuntimeException('Die Ressource wurde nicht gefunden.');
            }

            if ($type !== '' && $resource['type'] !== $type) {
                throw new RuntimeException('Die Kategorie einer Ressource kann nach dem Anlegen nicht mehr geändert werden.');
            }

            $update = $this->pdo->prepare(
                'UPDATE resources SET name = :name WHERE id = :id'
            );
            $update->execute(['name' => $name, 'id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function reorder(string $type, array $orderedIds): void
    {
        $categoryId = $this->categoryId($type);
        $orderedIds = array_map(static function ($id): int {
            $validatedId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($validatedId === false) {
                throw new RuntimeException('Die Ressourcenreihenfolge ist ungültig.');
            }

            return $validatedId;
        }, $orderedIds);
        if ($orderedIds === [] || count($orderedIds) !== count(array_unique($orderedIds))) {
            throw new RuntimeException('Die Ressourcenreihenfolge ist ungültig.');
        }

        $this->pdo->beginTransaction();
        try {
            $current = $this->pdo->prepare(
                'SELECT id FROM resources WHERE category_id = :category_id ORDER BY id FOR UPDATE'
            );
            $current->execute(['category_id' => $categoryId]);
            $currentIds = array_map('intval', $current->fetchAll(PDO::FETCH_COLUMN));
            $expectedIds = $orderedIds;
            sort($currentIds);
            sort($expectedIds);
            if ($currentIds !== $expectedIds) {
                throw new RuntimeException('Die Ressourcenliste wurde geändert. Lade die Seite neu und versuche es erneut.');
            }

            $update = $this->pdo->prepare(
                'UPDATE resources SET sort_order = :sort_order WHERE id = :id AND category_id = :category_id'
            );
            foreach ($orderedIds as $sortOrder => $resourceId) {
                $update->execute([
                    'sort_order' => $sortOrder,
                    'id' => $resourceId,
                    'category_id' => $categoryId,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function categoryId(string $code): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM resource_categories WHERE code = :code');
        $statement->execute(['code' => $code]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('Ressourcenkategorie ungültig.');
        }

        return (int) $id;
    }

    public function setActive(int $id, bool $active): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE resources SET is_active = :is_active WHERE id = :id'
        );
        $statement->execute(['is_active' => $active ? 1 : 0, 'id' => $id]);
        if ($statement->rowCount() === 0) {
            $exists = $this->pdo->prepare('SELECT id FROM resources WHERE id = :id');
            $exists->execute(['id' => $id]);
            if ($exists->fetchColumn() === false) {
                throw new RuntimeException('Die Ressource wurde nicht gefunden.');
            }
        }
    }

    public function delete(int $id): int
    {
        $this->pdo->beginTransaction();
        try {
            $resource = $this->pdo->prepare(
                'SELECT id FROM resources WHERE id = :id FOR UPDATE'
            );
            $resource->execute(['id' => $id]);
            if ($resource->fetchColumn() === false) {
                throw new RuntimeException('Die Ressource wurde nicht gefunden.');
            }

            $bookings = $this->pdo->prepare(
                'SELECT occurrence_id
                 FROM booking_resource_assignments
                 WHERE resource_id = :resource_id
                 UNION
                 SELECT bca.occurrence_id
                 FROM booking_cabin_assignments bca
                 WHERE bca.cabin_id = :cabin_id'
            );
            $bookings->execute(['resource_id' => $id, 'cabin_id' => $id]);
            $occurrenceIds = array_map('intval', $bookings->fetchAll(PDO::FETCH_COLUMN));

            if ($occurrenceIds !== []) {
                $placeholders = implode(',', array_fill(0, count($occurrenceIds), '?'));
                $groupQuery = $this->pdo->prepare(
                    "SELECT DISTINCT group_id FROM booking_occurrences WHERE id IN ({$placeholders})"
                );
                $groupQuery->execute($occurrenceIds);
                $groupIds = array_map('intval', $groupQuery->fetchAll(PDO::FETCH_COLUMN));

                $this->pdo->prepare(
                    "DELETE FROM booking_overrides
                     WHERE new_assignment_id IN (
                         SELECT id FROM booking_resource_assignments WHERE occurrence_id IN ({$placeholders})
                     ) OR conflicting_assignment_id IN (
                         SELECT id FROM booking_resource_assignments WHERE occurrence_id IN ({$placeholders})
                     )"
                )->execute(array_merge($occurrenceIds, $occurrenceIds));

                $this->pdo->prepare(
                    "DELETE FROM booking_occurrences WHERE id IN ({$placeholders})"
                )->execute($occurrenceIds);

                if ($groupIds !== []) {
                    $groupPlaceholders = implode(',', array_fill(0, count($groupIds), '?'));
                    $this->pdo->prepare(
                        "DELETE FROM booking_groups
                         WHERE id IN ({$groupPlaceholders})
                           AND NOT EXISTS (
                               SELECT 1 FROM booking_occurrences
                               WHERE booking_occurrences.group_id = booking_groups.id
                           )"
                    )->execute($groupIds);
                }
            }

            $this->pdo->prepare('DELETE FROM resources WHERE id = :id')->execute(['id' => $id]);
            $this->pdo->commit();

            return count($occurrenceIds);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
