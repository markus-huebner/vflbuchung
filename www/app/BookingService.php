<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class BookingService
{
    private BookingTypeService $bookingTypes;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Mailer $mailer,
        private readonly Config $config,
        ?BookingTypeService $bookingTypes = null
    ) {
        $this->bookingTypes = $bookingTypes ?? new BookingTypeService($pdo);
    }

    public function create(array $data, array $actor): array
    {
        [
            'resource_mode' => $resourceMode, 'resource_ids' => $resourceIds,
            'cabin_ids' => $cabinIds, 'start' => $start, 'end' => $end,
            'rule' => $recurrenceRule, 'details' => $groupData,
        ] = $this->validateBookingInput($data, $actor);
        $isTrainer = $actor['role'] === 'trainer';
        $occurrences = Recurrence::generate($start, $end, $recurrenceRule);

        $status = $isTrainer ? 'pending' : 'confirmed';

        $this->pdo->beginTransaction();
        try {
            $this->lockResources(array_merge($resourceIds, $cabinIds));
            $lockedResources = $this->loadActiveResources($resourceIds, true);
            $lockedResourceTypes = array_values(array_unique(array_column($lockedResources, 'type')));
            if (count($lockedResourceTypes) !== 1 || $lockedResourceTypes[0] !== $resourceMode) {
                throw new RuntimeException('Die Ressource wurde während der Buchung geändert. Bitte versuche es erneut.');
            }
            if ($cabinIds !== []) {
                $this->loadActiveResources($cabinIds, true);
            }
            $insertGroup = $this->pdo->prepare(
                'INSERT INTO booking_groups (created_by, requested_by, recurrence_rule, recurrence_until)
                 VALUES (:created_by, :requested_by, :recurrence_rule, :recurrence_until)'
            );
            $insertGroup->execute([
                'created_by' => $actor['id'],
                'requested_by' => $isTrainer ? $actor['id'] : null,
                'recurrence_rule' => $recurrenceRule === null ? null : json_encode($recurrenceRule, JSON_THROW_ON_ERROR),
                'recurrence_until' => $recurrenceRule !== null && $recurrenceRule['limit_type'] === 'until'
                    ? $recurrenceRule['until']
                    : null,
            ]);
            $groupId = (int) $this->pdo->lastInsertId();

            $insertOccurrence = $this->pdo->prepare(
                'INSERT INTO booking_occurrences
                    (group_id, occurrence_no, event_type, title, team_name,
                     destination, purpose, driver_name, private_notes,
                     starts_at, ends_at, status)
                 VALUES
                    (:group_id, :occurrence_no, :event_type, :title, :team_name,
                     :destination, :purpose, :driver_name, :private_notes,
                     :starts_at, :ends_at, :status)'
            );
            $insertAssignment = $this->pdo->prepare(
                'INSERT INTO booking_resource_assignments (occurrence_id, resource_id, status)
                 VALUES (:occurrence_id, :resource_id, :status)'
            );
            $insertCabin = $this->pdo->prepare(
                'INSERT INTO booking_cabin_assignments (occurrence_id, cabin_id)
                 VALUES (:occurrence_id, :cabin_id)'
            );
            $occurrenceNo = 0;
            $firstOccurrenceId = 0;
            foreach ($occurrences as $occurrence) {
                $startsAt = Recurrence::toDatabase($occurrence['start']);
                $endsAt = Recurrence::toDatabase($occurrence['end']);
                $insertOccurrence->execute($groupData + [
                    'group_id' => $groupId,
                    'occurrence_no' => $occurrenceNo++,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'status' => $status,
                ]);
                $occurrenceId = (int) $this->pdo->lastInsertId();
                if ($firstOccurrenceId === 0) {
                    $firstOccurrenceId = $occurrenceId;
                }

                foreach ($resourceIds as $resourceId) {
                    $insertAssignment->execute([
                        'occurrence_id' => $occurrenceId,
                        'resource_id' => $resourceId,
                        'status' => $status,
                    ]);
                }
                foreach ($cabinIds as $cabinId) {
                    $insertCabin->execute([
                        'occurrence_id' => $occurrenceId,
                        'cabin_id' => $cabinId,
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $mailErrors = $isTrainer ? $this->notifyApprovers('created', $firstOccurrenceId) : [];

        return [
            'group_id' => $groupId,
            'occurrence_id' => $firstOccurrenceId,
            'occurrence_count' => count($occurrences),
            'mail_errors' => $mailErrors,
        ];
    }

    public function events(string $startValue, string $endValue, string $resourceType, bool $includePrivate): array
    {
        if (!in_array($resourceType, ['pitch', 'bus', 'cabin'], true)) {
            throw new RuntimeException('Kalenderansicht ungültig.');
        }

        $start = Recurrence::toDatabase(Recurrence::parseDateTime($startValue));
        $end = Recurrence::toDatabase(Recurrence::parseDateTime($endValue));
        $detailFields = ", a.status AS status, o.status AS occurrence_status, g.recurrence_rule,
               (SELECT GROUP_CONCAT(r2.name ORDER BY r2.sort_order, r2.id SEPARATOR ', ')
                FROM booking_resource_assignments a2
                JOIN resources r2 ON r2.id = a2.resource_id
                WHERE a2.occurrence_id = o.id AND a2.status IN ('confirmed', 'pending')) AS resource_names";
        $privateFields = $includePrivate
            ? ", o.driver_name, o.private_notes,
               (SELECT GROUP_CONCAT(bca.cabin_id ORDER BY c.name)
                FROM booking_cabin_assignments bca
                JOIN resources c ON c.id = bca.cabin_id
                WHERE bca.occurrence_id = o.id) AS cabin_ids_raw"
            : ', NULL AS driver_name, NULL AS private_notes, NULL AS cabin_ids_raw';
        if ($resourceType === 'cabin') {
            $sql = "SELECT a.id AS assignment_id, o.id AS occurrence_id, g.id AS group_id,
                           r.id AS resource_id, r.name AS resource_name, rc.code AS resource_type,
                           o.event_type, o.title, o.team_name,
                           o.destination, o.purpose, o.starts_at, o.ends_at{$detailFields}{$privateFields},
                           NULL AS parent_resource_name, NULL AS cabin_id, 0 AS is_attached_cabin,
                           NULL AS cabins
                    FROM booking_resource_assignments a
                    JOIN booking_occurrences o ON o.id = a.occurrence_id
                    JOIN booking_groups g ON g.id = o.group_id
                    JOIN resources r ON r.id = a.resource_id
                    JOIN resource_categories rc ON rc.id = r.category_id AND rc.code = 'cabin'
                    WHERE a.status IN ('confirmed', 'pending') AND o.status IN ('confirmed', 'pending')
                      AND o.starts_at < :direct_range_end AND o.ends_at > :direct_range_start
                    UNION ALL
                    SELECT a.id AS assignment_id, o.id AS occurrence_id, g.id AS group_id,
                           c.id AS resource_id, c.name AS resource_name, cc.code AS resource_type,
                           o.event_type, o.title, o.team_name,
                           o.destination, o.purpose, o.starts_at, o.ends_at{$detailFields}{$privateFields},
                           NULL AS parent_resource_name, c.id AS cabin_id, 1 AS is_attached_cabin,
                           c.name AS cabins
                    FROM booking_cabin_assignments bca
                    JOIN booking_occurrences o ON o.id = bca.occurrence_id
                    JOIN (
                        SELECT a.occurrence_id, MIN(a.id) AS assignment_id
                        FROM booking_resource_assignments a
                        JOIN resources p ON p.id = a.resource_id
                        JOIN resource_categories pc ON pc.id = p.category_id AND pc.code = 'pitch'
                        WHERE a.status IN ('confirmed', 'pending')
                        GROUP BY a.occurrence_id
                    ) pitch_assignment ON pitch_assignment.occurrence_id = o.id
                    JOIN booking_resource_assignments a ON a.id = pitch_assignment.assignment_id
                    JOIN booking_groups g ON g.id = o.group_id
                    JOIN resources p ON p.id = a.resource_id
                    JOIN resources c ON c.id = bca.cabin_id
                    JOIN resource_categories cc ON cc.id = c.category_id AND cc.code = 'cabin'
                    WHERE a.status IN ('confirmed', 'pending') AND o.status IN ('confirmed', 'pending')
                      AND o.starts_at < :attached_range_end AND o.ends_at > :attached_range_start
                    ORDER BY starts_at, resource_name";
            $statement = $this->pdo->prepare($sql);
            $statement->execute([
                'direct_range_start' => $start,
                'direct_range_end' => $end,
                'attached_range_start' => $start,
                'attached_range_end' => $end,
            ]);
        } else {
            $statement = $this->pdo->prepare(
                "SELECT a.id AS assignment_id, o.id AS occurrence_id, g.id AS group_id,
                        r.id AS resource_id, r.name AS resource_name, rc.code AS resource_type,
                        o.event_type, o.title, o.team_name,
                        o.destination, o.purpose, o.starts_at, o.ends_at{$detailFields}{$privateFields},
                        NULL AS parent_resource_name, NULL AS cabin_id, 0 AS is_attached_cabin,
                        (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ')
                         FROM booking_cabin_assignments bca
                         JOIN resources c ON c.id = bca.cabin_id
                         WHERE bca.occurrence_id = o.id) AS cabins
                 FROM booking_resource_assignments a
                 JOIN booking_occurrences o ON o.id = a.occurrence_id
                 JOIN booking_groups g ON g.id = o.group_id
                 JOIN resources r ON r.id = a.resource_id
                 JOIN resource_categories rc ON rc.id = r.category_id
                 WHERE a.status IN ('confirmed', 'pending')
                   AND o.status IN ('confirmed', 'pending')
                   AND rc.code = :resource_type
                   AND o.starts_at < :range_end
                   AND o.ends_at > :range_start
                 ORDER BY o.starts_at, r.name"
            );
            $statement->execute([
                'resource_type' => $resourceType,
                'range_start' => $start,
                'range_end' => $end,
            ]);
        }
        $timezone = new DateTimeZone('Europe/Berlin');

        $typesByCode = array_column($this->bookingTypes->all(), null, 'code');
        return array_map(static function (array $row) use ($timezone, $typesByCode): array {
            $type = $typesByCode[$row['event_type']] ?? null;
            $row['event_type_name'] = $type['name'] ?? ($row['event_type'] === 'bus' ? 'Fahrzeug' : $row['event_type']);
            $row['event_type_color'] = $type['color_hex'] ?? BookingTypeService::palette()['violet-medium']['hex'];
            $row['event_type_text_color'] = $type['text_hex'] ?? '#ffffff';
            $row['start'] = Recurrence::fromDatabase($row['starts_at'])->setTimezone($timezone)->format(DATE_ATOM);
            $row['end'] = Recurrence::fromDatabase($row['ends_at'])->setTimezone($timezone)->format(DATE_ATOM);
            unset($row['starts_at'], $row['ends_at']);
            $row['resource_id'] = (int) $row['resource_id'];
            $row['assignment_id'] = (int) $row['assignment_id'];
            $row['occurrence_id'] = (int) $row['occurrence_id'];
            $row['group_id'] = (int) $row['group_id'];
            $row['is_attached_cabin'] = (bool) $row['is_attached_cabin'];
            $row['cabin_id'] = $row['cabin_id'] === null ? null : (int) $row['cabin_id'];
            $row['cabin_ids'] = $row['cabin_ids_raw'] === null
                ? []
                : array_map('intval', explode(',', $row['cabin_ids_raw']));
            unset($row['cabin_ids_raw']);
            $row['recurrence_rule'] = $row['recurrence_rule'] === null
                ? null : json_decode($row['recurrence_rule'], true, 512, JSON_THROW_ON_ERROR);
            $row['display_title'] = self::publicTitle($row);

            return $row;
        }, $statement->fetchAll());
    }

    public function requests(array $actor, bool $includeClosed = false, int $page = 1, int $pageSize = 10): array
    {
        $isStaff = in_array($actor['role'], ['admin', 'manager'], true);
        $groupConfirmedSeries = $isStaff && $includeClosed
            ? "g.recurrence_rule IS NOT NULL AND o.status = 'confirmed'"
            : '0 = 1';
        $requestEntryKey = "CASE WHEN {$groupConfirmedSeries}"
            . " THEN CONCAT('series:', g.id) ELSE CONCAT('occurrence:', o.id) END";
        if ($isStaff) {
            $where = 'g.requested_by IS NOT NULL';
            $params = [];
        } else {
            $where = 'g.requested_by = :user_id';
            $params = ['user_id' => $actor['id']];
        }
        $requestCutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-3 months')
            ->format('Y-m-d H:i:s');
        $where .= ' AND o.starts_at >= :request_cutoff';
        $params['request_cutoff'] = $requestCutoff;
        if (!$includeClosed) {
            $where .= " AND a.status = 'pending'";
        }

        $pageSize = max(1, min(50, $pageSize));
        $count = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT {$requestEntryKey})
             FROM booking_resource_assignments a
             JOIN booking_occurrences o ON o.id = a.occurrence_id
             JOIN booking_groups g ON g.id = o.group_id
             WHERE {$where}"
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pageCount = max(1, (int) ceil($total / $pageSize));
        $page = min(max(1, $page), $pageCount);
        $offset = ($page - 1) * $pageSize;

        $statement = $this->pdo->prepare(
            "SELECT COALESCE(MIN(CASE WHEN a.status = 'pending' THEN a.id END), MIN(a.id)) AS assignment_id,
                    GROUP_CONCAT(DISTINCT CASE WHEN a.status = 'pending' THEN a.id END ORDER BY a.id SEPARATOR ',')
                        AS pending_assignment_ids,
                    MIN(o.id) AS occurrence_id, g.id AS group_id,
                    CASE WHEN SUM(a.status = 'pending') > 0 THEN 'pending' ELSE MIN(o.status) END AS assignment_status,
                    MIN(o.status) AS occurrence_status,
                    MIN(o.event_type) AS event_type, MIN(o.title) AS title, MIN(o.team_name) AS team_name,
                    MIN(o.destination) AS destination, MIN(o.purpose) AS purpose,
                    MIN(o.driver_name) AS driver_name, MIN(o.private_notes) AS private_notes,
                    MIN(o.starts_at) AS starts_at, MAX(o.ends_at) AS ends_at,
                    MIN(o.rejection_reason) AS rejection_reason,
                    MAX(CASE WHEN {$groupConfirmedSeries} THEN 1 ELSE 0 END) AS is_series_summary,
                    COUNT(DISTINCT o.id) AS occurrence_count,
                    GROUP_CONCAT(DISTINCT o.id ORDER BY o.id SEPARATOR ',') AS occurrence_ids,
                    GROUP_CONCAT(DISTINCT r.id ORDER BY r.id SEPARATOR ',') AS resource_ids,
                    GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') AS resource_names,
                    MIN(rc.code) AS resource_type,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS requester_name,
                    u.email AS requester_email,
                    GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS cabins
             FROM booking_resource_assignments a
             JOIN booking_occurrences o ON o.id = a.occurrence_id
             JOIN booking_groups g ON g.id = o.group_id
             JOIN resources r ON r.id = a.resource_id
             JOIN resource_categories rc ON rc.id = r.category_id
             LEFT JOIN users u ON u.id = g.requested_by
             LEFT JOIN booking_cabin_assignments bca ON bca.occurrence_id = o.id
             LEFT JOIN resources c ON c.id = bca.cabin_id
             WHERE {$where}
             GROUP BY {$requestEntryKey}, g.id, u.id, u.first_name, u.last_name, u.email
             ORDER BY MIN(o.starts_at) DESC, MIN(o.id)
             LIMIT {$pageSize} OFFSET {$offset}"
        );
        $statement->execute($params);

        return [
            'items' => $statement->fetchAll(),
            'total' => $total,
            'page' => $page,
            'page_count' => $pageCount,
            'page_size' => $pageSize,
        ];
    }

    public function requestLinkDetails(int $occurrenceId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT o.id, o.starts_at, o.status,
                    CASE WHEN SUM(a.status = 'pending') > 0 THEN 'pending' ELSE o.status END AS assignment_status,
                    COALESCE(MIN(rc.code), 'pitch') AS resource_type
             FROM booking_occurrences o
             JOIN booking_groups g ON g.id = o.group_id
             LEFT JOIN booking_resource_assignments a ON a.occurrence_id = o.id
             LEFT JOIN resources r ON r.id = a.resource_id
             LEFT JOIN resource_categories rc ON rc.id = r.category_id
             WHERE o.id = :occurrence_id AND g.requested_by IS NOT NULL
             GROUP BY o.id, o.starts_at, o.status"
        );
        $statement->execute(['occurrence_id' => $occurrenceId]);
        $details = $statement->fetch();
        if ($details === false) {
            return null;
        }

        return [
            'occurrence_id' => (int) $details['id'],
            'start' => Recurrence::fromDatabase($details['starts_at'])
                ->setTimezone(new DateTimeZone('Europe/Berlin'))
                ->format(DATE_ATOM),
            'resource_type' => $details['resource_type'],
            'status' => $details['assignment_status'],
        ];
    }

    public function requestPageForOccurrence(
        int $occurrenceId,
        array $actor,
        bool $includeClosed = false,
        int $pageSize = 10
    ): ?int {
        $isStaff = in_array($actor['role'], ['admin', 'manager'], true);
        $where = $isStaff ? '1 = 1' : 'g.requested_by = :user_id';
        $params = $isStaff ? [] : ['user_id' => $actor['id']];
        $requestCutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-3 months')
            ->format('Y-m-d H:i:s');
        $where .= ' AND o.starts_at >= :request_cutoff';
        $params['request_cutoff'] = $requestCutoff;
        if (!$includeClosed) {
            $where .= " AND a.status = 'pending'";
        }

        $target = $this->pdo->prepare(
            "SELECT o.starts_at
             FROM booking_resource_assignments a
             JOIN booking_occurrences o ON o.id = a.occurrence_id
             JOIN booking_groups g ON g.id = o.group_id
             WHERE o.id = :occurrence_id AND {$where}
             LIMIT 1"
        );
        $target->execute($params + ['occurrence_id' => $occurrenceId]);
        $startsAt = $target->fetchColumn();
        if ($startsAt === false) {
            return null;
        }

        $pageSize = max(1, min(50, $pageSize));
        $ahead = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT o.id)
             FROM booking_resource_assignments a
             JOIN booking_occurrences o ON o.id = a.occurrence_id
             JOIN booking_groups g ON g.id = o.group_id
             WHERE {$where}
               AND (o.starts_at > :target_starts_at
                    OR (o.starts_at = :target_starts_at_equal AND o.id < :target_occurrence_id))"
        );
        $ahead->execute($params + [
            'target_starts_at' => $startsAt,
            'target_starts_at_equal' => $startsAt,
            'target_occurrence_id' => $occurrenceId,
        ]);
        return intdiv((int) $ahead->fetchColumn(), $pageSize) + 1;
    }

    public function approve(array $assignmentIds, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new RuntimeException('Für Freigaben fehlen die Rechte.');
        }
        $assignmentIds = $this->normalizeIds($assignmentIds);
        if ($assignmentIds === []) {
            throw new RuntimeException('Wähle mindestens eine Anfrage aus.');
        }

        $result = ['approved' => 0, 'mail_errors' => []];
        $affectedGroups = [];

        $this->pdo->beginTransaction();
        try {
            $assignments = $this->loadAssignmentsForUpdate($assignmentIds);
            if (count($assignments) !== count($assignmentIds)) {
                throw new RuntimeException('Eine oder mehrere Anfragen wurden nicht gefunden.');
            }

            $lockIds = [];
            foreach ($assignments as $assignment) {
                $lockIds[] = (int) $assignment['resource_id'];
                $lockIds = array_merge($lockIds, $assignment['cabin_ids']);
            }
            $this->lockResources($lockIds);
            foreach ($assignments as $assignment) {
                if ($assignment['status'] !== 'pending') {
                    continue;
                }

                $this->setAssignmentStatus((int) $assignment['id'], 'confirmed');
                $result['approved']++;
                $groupId = (int) $assignment['group_id'];
                $this->refreshOccurrenceStatus((int) $assignment['occurrence_id']);
                if (!array_key_exists($groupId, $affectedGroups)) {
                    $affectedGroups[$groupId] = $this->requestNotificationDetails(
                        (int) $assignment['occurrence_id']
                    );
                }
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        foreach ($affectedGroups as $details) {
            if ($details !== null) {
                $result['mail_errors'] = array_merge(
                    $result['mail_errors'],
                    $this->notifyRequesterOfRequest($details, 'freigegeben')
                );
            }
        }

        return $result;
    }

    public function reject(array $assignmentIds, array $actor, string $reason = ''): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new RuntimeException('Für Ablehnungen fehlen die Rechte.');
        }
        $assignmentIds = $this->normalizeIds($assignmentIds);
        if ($assignmentIds === []) {
            throw new RuntimeException('Wähle mindestens eine Anfrage aus.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) > 500) {
            throw new RuntimeException('Die Begründung darf höchstens 500 Zeichen lang sein.');
        }

        $groups = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($assignmentIds as $id) {
                $details = $this->assignmentDetails($id);
                if ($details === null || $details['status'] !== 'pending') {
                    throw new RuntimeException('Eine oder mehrere Anfragen sind nicht mehr offen.');
                }
                $this->setAssignmentStatus($id, 'rejected');
                $this->pdo->prepare(
                    'UPDATE booking_occurrences SET rejection_reason = :reason WHERE id = :id'
                )->execute(['reason' => $reason !== '' ? $reason : null, 'id' => $details['occurrence_id']]);
                $this->refreshOccurrenceStatus((int) $details['occurrence_id']);
                $groupId = (int) $details['group_id'];
                if (!array_key_exists($groupId, $groups)) {
                    $groups[$groupId] = $this->requestNotificationDetails((int) $details['occurrence_id']);
                }
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $mailErrors = [];
        foreach ($groups as $details) {
            if ($details !== null) {
                $mailErrors = array_merge(
                    $mailErrors,
                    $this->notifyRequesterOfRequest($details, 'abgelehnt', $reason)
                );
            }
        }

        return $mailErrors;
    }

    public function deletePendingRequests(array $assignmentIds, array $actor): array
    {
        if ($actor['role'] !== 'trainer') {
            throw new RuntimeException('Für das Löschen fehlen die Rechte.');
        }
        $assignmentIds = $this->normalizeIds($assignmentIds);
        if ($assignmentIds === []) {
            throw new RuntimeException('Wähle mindestens eine Anfrage aus.');
        }

        $this->pdo->beginTransaction();
        try {
            $assignments = [];
            foreach ($assignmentIds as $id) {
                $details = $this->assignmentDetails($id, true);
                if ($details === null
                    || (int) $details['requested_by'] !== (int) $actor['id']
                    || $details['status'] !== 'pending') {
                    throw new RuntimeException('Du kannst nur eigene offene Anfragen löschen.');
                }
                $assignments[] = $details;
            }

            $notificationDetails = [];
            foreach ($assignments as $assignment) {
                $occurrenceId = (int) $assignment['occurrence_id'];
                if (!array_key_exists($occurrenceId, $notificationDetails)) {
                    $notificationDetails[$occurrenceId] = $this->requestNotificationDetails($occurrenceId);
                }
            }

            $this->deleteOverrideReferences($assignmentIds);
            $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
            $this->pdo->prepare(
                "DELETE FROM booking_resource_assignments WHERE id IN ({$placeholders})"
            )->execute($assignmentIds);

            $occurrences = [];
            $groups = [];
            foreach ($assignments as $assignment) {
                $occurrenceId = (int) $assignment['occurrence_id'];
                $groupId = (int) $assignment['group_id'];
                $occurrences[$occurrenceId] = true;
                $groups[$groupId] = true;
            }

            $remainingAssignments = $this->pdo->prepare(
                "SELECT COUNT(*) FROM booking_resource_assignments
                 WHERE occurrence_id = :id AND status IN ('pending', 'confirmed')"
            );
            foreach (array_keys($occurrences) as $occurrenceId) {
                $remainingAssignments->execute(['id' => $occurrenceId]);
                if ((int) $remainingAssignments->fetchColumn() === 0) {
                    $remainingIds = $this->pdo->prepare(
                        'SELECT id FROM booking_resource_assignments WHERE occurrence_id = :id'
                    );
                    $remainingIds->execute(['id' => $occurrenceId]);
                    $this->deleteOverrideReferences(array_map('intval', $remainingIds->fetchAll(PDO::FETCH_COLUMN)));
                    $this->pdo->prepare('DELETE FROM booking_occurrences WHERE id = :id')
                        ->execute(['id' => $occurrenceId]);
                } else {
                    $this->refreshOccurrenceStatus($occurrenceId);
                }
            }

            $remainingOccurrences = $this->pdo->prepare(
                'SELECT COUNT(*) FROM booking_occurrences WHERE group_id = :id'
            );
            foreach (array_keys($groups) as $groupId) {
                $remainingOccurrences->execute(['id' => $groupId]);
                if ((int) $remainingOccurrences->fetchColumn() === 0) {
                    $this->pdo->prepare('DELETE FROM booking_groups WHERE id = :id')
                        ->execute(['id' => $groupId]);
                }
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $details = reset($notificationDetails);
        if ($details === false || $details === null) {
            return [];
        }

        $firstOccurrenceId = (int) array_key_first($notificationDetails);
        $details['affected_count'] = count($notificationDetails);

        return array_merge(
            $this->notifyApprovers('deleted', $firstOccurrenceId, $details),
            $this->notifyRequesterOfRequest($details, 'gelöscht')
        );
    }

    public function cancel(int $assignmentId, array $actor, string $scope = 'one'): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new RuntimeException('Für das Löschen fehlen die Rechte.');
        }
        if (!in_array($scope, ['one', 'future'], true)) {
            throw new RuntimeException('Löschumfang ungültig.');
        }

        $details = $this->assignmentDetails($assignmentId);
        if ($details === null || $details['status'] !== 'confirmed') {
            throw new RuntimeException('Die bestätigte Buchung wurde nicht gefunden.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('SELECT id FROM booking_groups WHERE id = :id FOR UPDATE')
                ->execute(['id' => $details['group_id']]);
            $details = $this->assignmentDetails($assignmentId, true);
            if ($details === null || $details['status'] !== 'confirmed') {
                throw new RuntimeException('Die bestätigte Buchung wurde inzwischen geändert.');
            }
            $assignments = [$details];
            if ($scope === 'future') {
                $statement = $this->pdo->prepare(
                    "SELECT a.id, a.status, a.resource_id, o.id AS occurrence_id, o.group_id,
                            o.occurrence_no, o.event_type, o.title, o.team_name,
                            g.requested_by, o.starts_at, o.ends_at,
                            u.email AS requester_email,
                            TRIM(CONCAT(u.first_name, \' \', u.last_name)) AS requester_name
                     FROM booking_resource_assignments a
                     JOIN booking_occurrences o ON o.id = a.occurrence_id
                     JOIN booking_groups g ON g.id = o.group_id
                     LEFT JOIN users u ON u.id = g.requested_by AND u.status = 'active'
                     WHERE o.group_id = :group_id AND o.occurrence_no >= :occurrence_no
                       AND a.resource_id = :resource_id AND a.status IN ('confirmed', 'pending')
                     ORDER BY o.occurrence_no
                     FOR UPDATE"
                );
                $statement->execute([
                    'group_id' => $details['group_id'],
                    'occurrence_no' => $details['occurrence_no'],
                    'resource_id' => $details['resource_id'],
                ]);
                $assignments = $statement->fetchAll();
            }

            $delete = $this->pdo->prepare('DELETE FROM booking_resource_assignments WHERE id = :id');
            $remaining = $this->pdo->prepare(
                'SELECT COUNT(*) FROM booking_resource_assignments WHERE occurrence_id = :occurrence_id'
            );
            $deleteOccurrence = $this->pdo->prepare('DELETE FROM booking_occurrences WHERE id = :id');
            foreach ($assignments as $assignment) {
                $delete->execute(['id' => $assignment['id']]);
                $remaining->execute(['occurrence_id' => $assignment['occurrence_id']]);
                if ((int) $remaining->fetchColumn() === 0) {
                    $deleteOccurrence->execute(['id' => $assignment['occurrence_id']]);
                }
            }
            $remainingOccurrences = $this->pdo->prepare(
                'SELECT COUNT(*) FROM booking_occurrences WHERE group_id = :group_id'
            );
            $remainingOccurrences->execute(['group_id' => $details['group_id']]);
            if ((int) $remainingOccurrences->fetchColumn() === 0) {
                $this->pdo->prepare('DELETE FROM booking_groups WHERE id = :id')
                    ->execute(['id' => $details['group_id']]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'cancelled' => count($assignments),
            'mail_errors' => $this->notifyRequester(
            $details['requester_email'] ?? null,
            $details['requester_name'] ?? null,
            'gelöscht'
            ),
        ];
    }

    public function cancelCabin(int $occurrenceId, int $cabinId, array $actor, string $scope = 'one'): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new RuntimeException('Für das Löschen fehlen die Rechte.');
        }
        if (!in_array($scope, ['one', 'future'], true)) {
            throw new RuntimeException('Löschumfang ungültig.');
        }

        $this->pdo->beginTransaction();
        try {
            $details = $this->occurrenceDetails($occurrenceId, true);
            if ($details === null || $details['status'] !== 'confirmed' || $details['event_type'] === 'bus') {
                throw new RuntimeException('Die bestätigte Kabinenbelegung wurde nicht gefunden.');
            }
            $this->pdo->prepare('SELECT id FROM booking_groups WHERE id = :id FOR UPDATE')
                ->execute(['id' => $details['group_id']]);
            $occurrences = $this->occurrencesForScope(
                (int) $details['group_id'], $occurrenceId, $scope, false, true
            );
            $this->lockOccurrenceResources($occurrences, [$cabinId]);
            $delete = $this->pdo->prepare(
                'DELETE FROM booking_cabin_assignments WHERE occurrence_id = :occurrence_id AND cabin_id = :cabin_id'
            );
            $deleted = 0;
            foreach ($occurrences as $selectedOccurrenceId) {
                $delete->execute(['occurrence_id' => $selectedOccurrenceId, 'cabin_id' => $cabinId]);
                $deleted += $delete->rowCount();
            }
            if ($deleted === 0) {
                throw new RuntimeException('Die Kabine ist dieser Buchung nicht zugeordnet.');
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return ['cancelled' => $deleted, 'mail_errors' => $this->notifyRequester(
            $details['requester_email'] ?? null,
            $details['requester_name'] ?? null,
            'Kabinenbelegung gelöscht'
        )];
    }

    public function editableOccurrence(int $occurrenceId, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new RuntimeException('Für Änderungen fehlen die Rechte.');
        }
        $row = $this->occurrenceDetails($occurrenceId);
        if ($row === null || $row['status'] !== 'confirmed') {
            throw new RuntimeException('Der bestätigte Termin wurde nicht gefunden.');
        }
        $resources = $this->pdo->prepare(
            "SELECT r.id, r.name, rc.code AS type, r.is_active
             FROM booking_resource_assignments a
             JOIN resources r ON r.id = a.resource_id
             JOIN resource_categories rc ON rc.id = r.category_id
             WHERE a.occurrence_id = :id AND a.status = 'confirmed' ORDER BY r.sort_order, r.id"
        );
        $resources->execute(['id' => $occurrenceId]);
        $assigned = $resources->fetchAll();
        if ($assigned === []) {
            throw new RuntimeException('Die Buchung hat keine bestätigte Ressourcenzuordnung.');
        }
        if ($assigned[0]['type'] === 'cabin') {
            throw new RuntimeException('Kabinen können nur zusammen mit einem Platz gebucht werden.');
        }
        $cabins = $this->pdo->prepare(
            "SELECT r.id, r.name, rc.code AS type, r.is_active
             FROM booking_cabin_assignments bca
             JOIN resources r ON r.id = bca.cabin_id
             JOIN resource_categories rc ON rc.id = r.category_id
             WHERE bca.occurrence_id = :id ORDER BY r.sort_order, r.id"
        );
        $cabins->execute(['id' => $occurrenceId]);
        $assignedCabins = $cabins->fetchAll();
        $zone = new DateTimeZone('Europe/Berlin');

        return [
            'occurrence_id' => $occurrenceId,
            'event_type' => $row['event_type'],
            'resource_mode' => $assigned[0]['type'],
            'resource_ids' => array_map('intval', array_column($assigned, 'id')),
            'cabin_ids' => array_map('intval', array_column($assignedCabins, 'id')),
            'resources' => array_merge($assigned, $assignedCabins),
            'title' => $row['title'],
            'team_name' => $row['team_name'],
            'destination' => $row['destination'],
            'purpose' => $row['purpose'],
            'driver_name' => $row['driver_name'],
            'private_notes' => $row['private_notes'],
            'start' => Recurrence::fromDatabase($row['starts_at'])->setTimezone($zone)->format(DATE_ATOM),
            'end' => Recurrence::fromDatabase($row['ends_at'])->setTimezone($zone)->format(DATE_ATOM),
            'recurrence_rule' => $row['recurrence_rule'] === null
                ? null : json_decode($row['recurrence_rule'], true, 512, JSON_THROW_ON_ERROR),
            'scope_counts' => [
                'one' => 1,
                'future' => count($this->occurrencesForScope((int) $row['group_id'], $occurrenceId, 'future', true)),
                'all' => count($this->occurrencesForScope((int) $row['group_id'], $occurrenceId, 'all', true)),
            ],
        ];
    }

    public function editablePendingRequest(int $occurrenceId, array $actor): array
    {
        $row = $this->occurrenceDetails($occurrenceId);
        $isStaff = in_array($actor['role'], ['admin', 'manager'], true);
        if ($row === null || $row['status'] !== 'pending'
            || (!$isStaff && ($actor['role'] !== 'trainer' || (int) $row['requested_by'] !== (int) $actor['id']))) {
            throw new RuntimeException('Die offene Buchungsanfrage wurde nicht gefunden.');
        }

        $resources = $this->pdo->prepare(
            "SELECT r.id, r.name, rc.code AS type, r.is_active
             FROM booking_resource_assignments a
             JOIN resources r ON r.id = a.resource_id
             JOIN resource_categories rc ON rc.id = r.category_id
             WHERE a.occurrence_id = :id AND a.status = 'pending' ORDER BY r.sort_order, r.id"
        );
        $resources->execute(['id' => $occurrenceId]);
        $assigned = $resources->fetchAll();
        if ($assigned === []) {
            throw new RuntimeException('Die Anfrage hat keine offenen Ressourcenzuordnungen.');
        }

        $zone = new DateTimeZone('Europe/Berlin');
        $pendingScopeCounts = [
            'one' => 1,
            'future' => count($this->occurrencesForScope(
                (int) $row['group_id'], $occurrenceId, 'future', false, false, true
            )),
            'all' => count($this->occurrencesForScope(
                (int) $row['group_id'], $occurrenceId, 'all', false, false, true
            )),
        ];

        return [
            'occurrence_id' => $occurrenceId,
            'event_type' => $row['event_type'],
            'resource_mode' => $assigned[0]['type'],
            'resource_ids' => array_map('intval', array_column($assigned, 'id')),
            'cabin_ids' => [],
            'resources' => $assigned,
            'title' => $row['title'],
            'team_name' => $row['team_name'],
            'destination' => $row['destination'],
            'purpose' => $row['purpose'],
            'driver_name' => $row['driver_name'],
            'private_notes' => $row['private_notes'],
            'start' => Recurrence::fromDatabase($row['starts_at'])->setTimezone($zone)->format(DATE_ATOM),
            'end' => Recurrence::fromDatabase($row['ends_at'])->setTimezone($zone)->format(DATE_ATOM),
            'recurrence_rule' => $row['recurrence_rule'] === null
                ? null : json_decode($row['recurrence_rule'], true, 512, JSON_THROW_ON_ERROR),
            'scope_counts' => $pendingScopeCounts,
        ];
    }

    public function editOccurrence(int $occurrenceId, array $data, array $actor): array
    {
        return $this->editOccurrenceData($occurrenceId, $data, $actor, false, false);
    }

    public function editPendingRequest(
        int $occurrenceId, array $data, array $actor, bool $approve = false
    ): array {
        if ($approve && !in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new RuntimeException('Für Freigaben fehlen die Rechte.');
        }

        return $this->editOccurrenceData($occurrenceId, $data, $actor, true, $approve);
    }

    private function editOccurrenceData(
        int $occurrenceId, array $data, array $actor, bool $requestEdit, bool $approve
    ): array {
        $scope = (string) ($data['scope'] ?? 'one');
        if (!in_array($scope, ['one', 'future', 'all'], true)) {
            throw new RuntimeException('Änderungsumfang ungültig.');
        }
        $target = $this->occurrenceDetails($occurrenceId);
        if ($requestEdit) {
            $isStaff = in_array($actor['role'], ['admin', 'manager'], true);
            if ($target === null || $target['status'] !== 'pending'
                || (!$isStaff && ($actor['role'] !== 'trainer'
                    || (int) $target['requested_by'] !== (int) $actor['id']))) {
                throw new RuntimeException('Die offene Buchungsanfrage wurde nicht gefunden.');
            }
        } elseif (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new RuntimeException('Für Änderungen fehlen die Rechte.');
        } elseif ($target === null || $target['status'] !== 'confirmed') {
            throw new RuntimeException('Der bestätigte Termin wurde nicht gefunden.');
        }

        $input = $this->validateBookingInput($data, $actor, (string) $target['event_type']);
        if ($requestEdit && $input['cabin_ids'] !== []) {
            throw new RuntimeException('Buchungsanfragen können keine Kabinen belegen.');
        }
        $this->pdo->beginTransaction();
        $approved = 0;
        $approvalGroupId = null;
        try {
            $groupId = (int) $target['group_id'];
            $this->pdo->prepare('SELECT id FROM booking_groups WHERE id = :id FOR UPDATE')
                ->execute(['id' => $groupId]);
            $target = $this->occurrenceDetails($occurrenceId, true);
            $expectedStatus = $requestEdit ? 'pending' : 'confirmed';
            if ($target === null || $target['status'] !== $expectedStatus
                || (int) $target['group_id'] !== $groupId
                || ($requestEdit && !in_array($actor['role'], ['admin', 'manager'], true)
                    && (int) $target['requested_by'] !== (int) $actor['id'])) {
                throw new RuntimeException('Die Buchung wurde inzwischen geändert. Bitte öffne sie erneut.');
            }
            $selected = $this->occurrencesForScope(
                $groupId,
                $occurrenceId,
                $scope,
                !$requestEdit,
                false,
                $requestEdit
            );
            $this->lockOccurrenceResources($selected, array_merge($input['resource_ids'], $input['cabin_ids']));
            $this->loadActiveResources(array_merge($input['resource_ids'], $input['cabin_ids']), true);
            $oldRule = $target['recurrence_rule'] === null ? null
                : $this->recurrenceRule(json_decode($target['recurrence_rule'], true, 512, JSON_THROW_ON_ERROR));
            if ($oldRule !== null && $oldRule['limit_type'] === 'count') {
                $oldRule['count'] = count($selected);
            }
            $ruleChanged = $oldRule !== $input['rule'];
            $shift = Recurrence::fromDatabase($target['starts_at'])
                ->setTimezone(new DateTimeZone('Europe/Berlin'))->diff($input['start']);
            $duration = $input['end']->getTimestamp() - $input['start']->getTimestamp();
            $schedule = [];
            foreach ($selected as $id) {
                $row = $this->occurrenceDetails($id, true);
                if ($row === null || $row['status'] !== $expectedStatus) {
                    throw new RuntimeException('Ein Termin der Buchung wurde inzwischen geändert.');
                }
                $start = Recurrence::fromDatabase($row['starts_at'])
                    ->setTimezone(new DateTimeZone('Europe/Berlin'))->add($shift);
                $end = $start->modify('+' . $duration . ' seconds');
                if ($start->format('Y-m-d') !== $end->format('Y-m-d')) {
                    throw new RuntimeException('Buchungen müssen am selben Tag enden.');
                }
                $schedule[] = ['start' => $start, 'end' => $end];
            }
            if ($ruleChanged) {
                $schedule = Recurrence::generate($schedule[0]['start'], $schedule[0]['end'], $input['rule']);
            }
            $splitPendingRequest = false;
            if ($requestEdit && $scope === 'all') {
                $confirmedCount = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM booking_occurrences
                     WHERE group_id = :group_id AND status = 'confirmed'"
                );
                $confirmedCount->execute(['group_id' => $groupId]);
                $splitPendingRequest = (int) $confirmedCount->fetchColumn() > 0;
            }
            if ($scope === 'future' || ($scope === 'one' && $ruleChanged) || $splitPendingRequest) {
                $groupId = $this->splitOccurrences($groupId, $selected, (int) $actor['id'], $input['rule']);
            } elseif ($scope === 'all') {
                $this->updateGroupRule($groupId, $input['rule']);
            }

            $update = $this->pdo->prepare(
                'UPDATE booking_occurrences
                 SET starts_at = :starts_at, ends_at = :ends_at,
                     event_type = :event_type, title = :title, team_name = :team_name,
                     destination = :destination, purpose = :purpose, driver_name = :driver_name,
                     private_notes = :private_notes
                 WHERE id = :id'
            );
            $insert = $this->pdo->prepare(
                "INSERT INTO booking_occurrences
                    (group_id, occurrence_no, event_type, title, team_name, destination, purpose,
                     driver_name, private_notes, starts_at, ends_at, status)
                 VALUES (:group_id, :occurrence_no, :event_type, :title, :team_name, :destination, :purpose,
                         :driver_name, :private_notes, :starts_at, :ends_at, :status)"
            );
            $maximum = $this->pdo->prepare(
                'SELECT COALESCE(MAX(occurrence_no), -1) FROM booking_occurrences WHERE group_id = :id'
            );
            $maximum->execute(['id' => $groupId]);
            $nextNumber = (int) $maximum->fetchColumn() + 1;
            $updated = [];
            foreach ($schedule as $index => $dates) {
                if ($dates['start']->format('Y-m-d') !== $dates['end']->format('Y-m-d')) {
                    throw new RuntimeException('Buchungen müssen am selben Tag enden.');
                }
                $values = $input['details'] + [
                    'starts_at' => Recurrence::toDatabase($dates['start']),
                    'ends_at' => Recurrence::toDatabase($dates['end']),
                ];
                if (isset($selected[$index])) {
                    $id = $selected[$index];
                    $update->execute($values + ['id' => $id]);
                } else {
                    $insert->execute($values + [
                        'group_id' => $groupId,
                        'occurrence_no' => $nextNumber++,
                        'status' => $expectedStatus,
                    ]);
                    $id = (int) $this->pdo->lastInsertId();
                }
                $this->replaceResourceAssignments(
                    $id, $input['resource_ids'], $expectedStatus, $requestEdit
                );
                $this->replaceCabinAssignments($id, $requestEdit ? [] : $input['cabin_ids']);
                if ($requestEdit) {
                    $this->refreshOccurrenceStatus($id);
                }
                $updated[$id] = $values;
            }
            foreach (array_slice($selected, count($schedule)) as $id) {
                $this->pdo->prepare('DELETE FROM booking_occurrences WHERE id = :id')->execute(['id' => $id]);
            }
            if ($requestEdit && $approve) {
                $pending = $this->pdo->prepare(
                    "SELECT id FROM booking_resource_assignments
                     WHERE occurrence_id = :id AND status = 'pending' FOR UPDATE"
                );
                $pending->execute(['id' => $occurrenceId]);
                $pendingIds = array_map('intval', $pending->fetchAll(PDO::FETCH_COLUMN));
                if ($pendingIds === []) {
                    throw new RuntimeException('Die Anfrage enthält keine offenen Plätze mehr.');
                }
                foreach ($pendingIds as $id) {
                    $this->setAssignmentStatus($id, 'confirmed');
                }
                $this->refreshOccurrenceStatus($occurrenceId);
                $approvalGroupId = (int) $this->occurrenceDetails($occurrenceId)['group_id'];
                $approved = count($pendingIds);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $mailErrors = [];
        if ($approvalGroupId !== null) {
            $requestDetails = $this->requestNotificationDetails($occurrenceId);
            if ($requestDetails !== null) {
                $mailErrors = array_merge(
                    $mailErrors,
                    $this->notifyRequesterOfRequest($requestDetails, 'freigegeben')
                );
            }
        }

        return [
            'updated' => count($updated),
            'approved' => $approved,
            'mail_errors' => $mailErrors,
        ];
    }

    private function validateBookingInput(array $data, array $actor, ?string $allowArchivedType = null): array
    {
        $eventType = (string) ($data['event_type'] ?? '');
        $isBus = $eventType === 'bus';
        $resourceMode = $isBus ? 'bus' : (string) ($data['resource_mode'] ?? '');
        if (!$isBus && !$this->bookingTypes->isActive($eventType) && $eventType !== $allowArchivedType) {
            throw new RuntimeException('Terminart ungültig.');
        }
        if (!$isBus && $resourceMode !== 'pitch') {
            throw new RuntimeException('Kabinen können nur zusammen mit einem Platz gebucht werden.');
        }
        if (!$isBus && $actor['role'] === 'trainer' && !$this->bookingTypes->isTrainerRequestable($eventType)) {
            throw new RuntimeException('Trainer dürfen für diese Buchungsart keine Anfragen stellen.');
        }
        if (!is_array($data['resource_ids'] ?? null)) {
            throw new RuntimeException('Bitte wähle die zu buchenden Ressourcen aus.');
        }
        $resourceIds = $this->normalizeIds($data['resource_ids']);
        if ($resourceIds === []) {
            throw new RuntimeException(match ($resourceMode) {
                'pitch' => 'Wähle mindestens einen Platz aus.',
                default => 'Wähle ein Fahrzeug aus.',
            });
        }
        if ($isBus && count($resourceIds) !== 1) {
            throw new RuntimeException('Eine Busbuchung kann genau einen Bus enthalten.');
        }
        $cabinInput = $data['cabin_ids'] ?? [];
        if (!is_array($cabinInput)) {
            throw new RuntimeException('Die Kabinenauswahl ist ungültig.');
        }
        if ($actor['role'] === 'trainer' && $this->normalizeIds($cabinInput) !== []) {
            throw new RuntimeException('Buchungsanfragen können keine Kabinen belegen.');
        }
        $types = array_values(array_unique(array_column($this->loadActiveResources($resourceIds), 'type')));
        if ($types !== [$resourceMode]) {
            throw new RuntimeException('Die ausgewählten Ressourcen passen nicht zur Buchungsart.');
        }
        $start = Recurrence::parseLocalDateTime((string) ($data['starts_at'] ?? ''));
        $end = Recurrence::parseLocalDateTime((string) ($data['ends_at'] ?? ''));
        if ($end <= $start) {
            throw new RuntimeException('Das Ende muss nach dem Beginn liegen.');
        }
        if ($start->format('Y-m-d') !== $end->format('Y-m-d')) {
            throw new RuntimeException('Buchungen müssen am selben Tag enden.');
        }

        return [
            'resource_mode' => $resourceMode,
            'resource_ids' => $resourceIds,
            'cabin_ids' => $this->validateCabins($cabinInput, $resourceMode === 'pitch'),
            'start' => $start,
            'end' => $end,
            'rule' => $this->recurrenceRule($data, $isBus),
            'details' => $this->validateEventDetails($data, $eventType),
        ];
    }

    private function recurrenceRule(array $data, bool $isBus = false): ?array
    {
        $frequency = (string) ($data['frequency'] ?? 'none');
        if ($frequency === 'none') {
            return null;
        }
        if ($isBus) {
            throw new RuntimeException('Busbuchungen können nicht als Serie angelegt werden.');
        }
        if (!is_array($data['weekdays'] ?? [])) {
            throw new RuntimeException('Die Wochentage der Wiederholung sind ungültig.');
        }
        $weekdays = $frequency === 'weekly'
            ? array_values(array_unique(array_map('strtoupper', $data['weekdays'] ?? [])))
            : [];
        sort($weekdays);
        $limit = (string) ($data['limit_type'] ?? '');

        return [
            'frequency' => $frequency,
            'interval' => (int) ($data['interval'] ?? 1),
            'weekdays' => $weekdays,
            'limit_type' => $limit,
            'count' => $limit === 'count' ? (int) ($data['count'] ?? 0) : null,
            'until' => $limit === 'until' ? (string) ($data['until'] ?? '') : null,
        ];
    }

    private function validateEventDetails(array $data, string $eventType): array
    {
        $title = $this->requiredTitle($data['title'] ?? null);

        return [
            'event_type' => $eventType,
            'title' => $title,
            'team_name' => in_array($eventType, ['training', 'match'], true)
                ? $this->optionalText($data['team_name'] ?? null, 120)
                : null,
            'destination' => $eventType === 'bus' ? $this->optionalText($data['destination'] ?? null, 180) : null,
            'purpose' => $eventType === 'bus' ? $this->optionalText($data['purpose'] ?? null, 180) : null,
            'driver_name' => $eventType === 'bus' ? $this->optionalText($data['driver_name'] ?? null, 120) : null,
            'private_notes' => $this->optionalText($data['private_notes'] ?? null, 4000),
        ];
    }

    private function validateCabins(array $input, bool $allow): array
    {
        if (!is_array($input)) {
            throw new RuntimeException('Die Kabinenauswahl ist ungültig.');
        }

        $ids = $this->normalizeIds($input);
        if ($ids === []) {
            return [];
        }
        if (!$allow) {
            throw new RuntimeException('Kabinen können nur einer Platzbuchung zugeordnet werden.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT r.id
             FROM resources r
             JOIN resource_categories rc ON rc.id = r.category_id
             WHERE rc.code = 'cabin' AND r.is_active = 1 AND r.id IN ({$placeholders})"
        );
        $statement->execute($ids);
        $validIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        if (count($validIds) !== count($ids)) {
            throw new RuntimeException('Eine ausgewählte Kabine ist ungültig oder deaktiviert.');
        }

        return $validIds;
    }

    private function loadActiveResources(array $ids, bool $forUpdate = false): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT r.id, rc.code AS type, r.name
             FROM resources r
             JOIN resource_categories rc ON rc.id = r.category_id
             WHERE r.is_active = 1 AND r.id IN ({$placeholders})"
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute($ids);
        $resources = $statement->fetchAll();
        if (count($resources) !== count($ids)) {
            throw new RuntimeException('Eine oder mehrere Ressourcen sind ungültig oder deaktiviert.');
        }

        return $resources;
    }

    private function lockResources(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return;
        }

        sort($ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id FROM resources WHERE id IN ({$placeholders}) ORDER BY id FOR UPDATE"
        );
        $statement->execute($ids);
        $statement->fetchAll();
    }

    private function lockOccurrenceResources(array $occurrenceIds, array $additionalResourceIds = []): void
    {
        if ($occurrenceIds === []) {
            $this->lockResources($additionalResourceIds);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($occurrenceIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT a.resource_id, bca.cabin_id
             FROM booking_resource_assignments a
             LEFT JOIN booking_cabin_assignments bca ON bca.occurrence_id = a.occurrence_id
             WHERE a.occurrence_id IN ({$placeholders})"
        );
        $statement->execute($occurrenceIds);
        $ids = [];
        foreach ($statement->fetchAll() as $row) {
            $ids[] = (int) $row['resource_id'];
            if ($row['cabin_id'] !== null) {
                $ids[] = (int) $row['cabin_id'];
            }
        }
        $this->lockResources(array_merge($ids, $additionalResourceIds));
    }

    private function replaceResourceAssignments(
        int $occurrenceId, array $resourceIds, string $status = 'confirmed', bool $pendingRequest = false
    ): void
    {
        $existing = $this->pdo->prepare(
            'SELECT id, resource_id, status FROM booking_resource_assignments WHERE occurrence_id = :id FOR UPDATE'
        );
        $existing->execute(['id' => $occurrenceId]);
        $remaining = array_fill_keys($resourceIds, true);
        foreach ($existing->fetchAll() as $assignment) {
            $resourceId = (int) $assignment['resource_id'];
            if ($pendingRequest) {
                if ($assignment['status'] === 'confirmed') {
                    if (isset($remaining[$resourceId])) {
                        throw new RuntimeException('Ein bereits freigegebener Platz kann nicht erneut angefragt werden.');
                    }
                    continue;
                }
                if ($assignment['status'] === 'pending') {
                    if (isset($remaining[$resourceId])) {
                        unset($remaining[$resourceId]);
                    } else {
                        $this->pdo->prepare('DELETE FROM booking_resource_assignments WHERE id = :id')
                            ->execute(['id' => $assignment['id']]);
                    }
                    continue;
                }
                if (isset($remaining[$resourceId]) && in_array($assignment['status'], ['rejected', 'withdrawn'], true)) {
                    $this->setAssignmentStatus((int) $assignment['id'], $status);
                    unset($remaining[$resourceId]);
                } elseif (isset($remaining[$resourceId])) {
                    throw new RuntimeException('Ein bereits abgeschlossener Platz kann nicht erneut angefragt werden.');
                }
                continue;
            }
            if (isset($remaining[$resourceId])) {
                $this->setAssignmentStatus((int) $assignment['id'], $status);
                unset($remaining[$resourceId]);
            } elseif ($assignment['status'] === 'confirmed') {
                $this->pdo->prepare('DELETE FROM booking_resource_assignments WHERE id = :id')
                    ->execute(['id' => $assignment['id']]);
            }
        }
        $insert = $this->pdo->prepare(
            "INSERT INTO booking_resource_assignments (occurrence_id, resource_id, status)
             VALUES (:occurrence_id, :resource_id, :status)"
        );
        foreach (array_keys($remaining) as $resourceId) {
            $insert->execute([
                'occurrence_id' => $occurrenceId,
                'resource_id' => $resourceId,
                'status' => $status,
            ]);
        }
    }

    private function replaceCabinAssignments(int $occurrenceId, array $cabinIds): void
    {
        $this->pdo->prepare(
            'DELETE FROM booking_cabin_assignments WHERE occurrence_id = :occurrence_id'
        )->execute(['occurrence_id' => $occurrenceId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO booking_cabin_assignments (occurrence_id, cabin_id)
             VALUES (:occurrence_id, :cabin_id)'
        );
        foreach ($cabinIds as $cabinId) {
            $insert->execute(['occurrence_id' => $occurrenceId, 'cabin_id' => $cabinId]);
        }
    }

    private function loadAssignmentsForUpdate(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT a.id, a.resource_id, a.status, o.id AS occurrence_id, o.group_id,
                    o.starts_at, o.ends_at,
                    (SELECT GROUP_CONCAT(cabin_id)
                     FROM booking_cabin_assignments WHERE occurrence_id = a.occurrence_id) AS cabin_ids_raw
             FROM booking_resource_assignments a
             JOIN booking_occurrences o ON o.id = a.occurrence_id
             WHERE a.id IN ({$placeholders})
             FOR UPDATE"
        );
        $statement->execute($ids);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['cabin_ids'] = $row['cabin_ids_raw'] === null
                ? []
                : array_map('intval', explode(',', $row['cabin_ids_raw']));
        }
        unset($row);

        return $rows;
    }

    private function setAssignmentStatus(int $id, string $status): void
    {
        $this->pdo->prepare(
            'UPDATE booking_resource_assignments SET status = :status WHERE id = :id'
        )->execute(['status' => $status, 'id' => $id]);
    }

    private function refreshOccurrenceStatus(int $occurrenceId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT status FROM booking_resource_assignments WHERE occurrence_id = :id'
        );
        $statement->execute(['id' => $occurrenceId]);
        $statuses = $statement->fetchAll(PDO::FETCH_COLUMN);
        $status = 'rejected';
        if (in_array('pending', $statuses, true)) {
            $status = 'pending';
        } elseif (in_array('confirmed', $statuses, true)) {
            $status = 'confirmed';
        } elseif (in_array('withdrawn', $statuses, true)) {
            $status = 'withdrawn';
        }

        $this->pdo->prepare('UPDATE booking_occurrences SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $occurrenceId]);
    }

    private function assignmentDetails(int $assignmentId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.status, a.resource_id, rc.code AS resource_type, o.id AS occurrence_id,
                    o.occurrence_no,
                    o.group_id, o.event_type, o.title, o.team_name, g.requested_by, o.starts_at, o.ends_at,
                    u.email AS requester_email,
                    TRIM(CONCAT(u.first_name, \' \', u.last_name)) AS requester_name
             FROM booking_resource_assignments a
             JOIN booking_occurrences o ON o.id = a.occurrence_id
             JOIN booking_groups g ON g.id = o.group_id
             JOIN resources r ON r.id = a.resource_id
             JOIN resource_categories rc ON rc.id = r.category_id
             LEFT JOIN users u ON u.id = g.requested_by AND u.status = \'active\'
             WHERE a.id = :id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['id' => $assignmentId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    private function occurrenceDetails(int $occurrenceId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.*, g.recurrence_rule, g.requested_by,
                    u.email AS requester_email,
                    TRIM(CONCAT(u.first_name, \' \', u.last_name)) AS requester_name
             FROM booking_occurrences o
             JOIN booking_groups g ON g.id = o.group_id
             LEFT JOIN users u ON u.id = g.requested_by AND u.status = \'active\'
             WHERE o.id = :id'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['id' => $occurrenceId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    private function occurrencesForScope(
        int $groupId,
        int $occurrenceId,
        string $scope,
        bool $confirmedOnly = false,
        bool $activeOnly = false,
        bool $pendingOnly = false
    ): array
    {
        if ($scope === 'one') {
            return [$occurrenceId];
        }

        $target = $this->occurrenceDetails($occurrenceId);
        $sql = 'SELECT id FROM booking_occurrences WHERE group_id = :group_id';
        if ($confirmedOnly) {
            $sql .= " AND status = 'confirmed'";
        } elseif ($pendingOnly) {
            $sql .= " AND status = 'pending'";
        } elseif ($activeOnly) {
            $sql .= " AND status IN ('confirmed', 'pending')";
        }
        if ($scope === 'future') {
            $sql .= ' AND occurrence_no >= :occurrence_no';
        }
        $sql .= ' ORDER BY occurrence_no';
        $statement = $this->pdo->prepare($sql);
        $parameters = ['group_id' => $groupId];
        if ($scope === 'future') {
            $parameters['occurrence_no'] = $target['occurrence_no'];
        }
        $statement->execute($parameters);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function splitOccurrences(int $groupId, array $selected, int $actorId, ?array $rule): int
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO booking_groups (created_by, requested_by, recurrence_rule, recurrence_until)
             SELECT :created_by, requested_by, :recurrence_rule, recurrence_until
             FROM booking_groups WHERE id = :group_id'
        );
        $insert->execute([
            'created_by' => $actorId,
            'recurrence_rule' => $rule === null ? null : json_encode($rule, JSON_THROW_ON_ERROR),
            'group_id' => $groupId,
        ]);
        $newGroupId = (int) $this->pdo->lastInsertId();

        $update = $this->pdo->prepare(
            'UPDATE booking_occurrences SET group_id = :new_group_id, occurrence_no = :occurrence_no
             WHERE id = :id'
        );
        foreach ($selected as $index => $id) {
            $update->execute([
                'new_group_id' => $newGroupId,
                'occurrence_no' => $index,
                'id' => $id,
            ]);
        }
        $this->updateGroupRule($newGroupId, $rule);
        $old = $this->pdo->prepare(
            "SELECT g.recurrence_rule, COUNT(o.id) AS remaining, MAX(o.starts_at) AS last_start
             FROM booking_groups g LEFT JOIN booking_occurrences o
               ON o.group_id = g.id AND o.status IN ('confirmed', 'pending')
             WHERE g.id = :id GROUP BY g.id"
        );
        $old->execute(['id' => $groupId]);
        $row = $old->fetch();
        if ($row !== false && $row['recurrence_rule'] !== null && (int) $row['remaining'] > 0) {
            $oldRule = json_decode($row['recurrence_rule'], true, 512, JSON_THROW_ON_ERROR);
            if ($oldRule['limit_type'] === 'count') {
                $oldRule['count'] = (int) $row['remaining'];
            } else {
                $oldRule['until'] = Recurrence::fromDatabase($row['last_start'])
                    ->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
            }
            $this->updateGroupRule($groupId, $oldRule);
        }
        $remaining = $this->pdo->prepare('SELECT COUNT(*) FROM booking_occurrences WHERE group_id = :id');
        $remaining->execute(['id' => $groupId]);
        if ((int) $remaining->fetchColumn() === 0) {
            $this->pdo->prepare('DELETE FROM booking_groups WHERE id = :id')->execute(['id' => $groupId]);
        }

        return $newGroupId;
    }

    private function updateGroupRule(int $groupId, ?array $rule): void
    {
        $this->pdo->prepare(
            'UPDATE booking_groups SET recurrence_rule = :rule, recurrence_until = :until WHERE id = :id'
        )->execute([
            'id' => $groupId,
            'rule' => $rule === null ? null : json_encode($rule, JSON_THROW_ON_ERROR),
            'until' => $rule !== null && $rule['limit_type'] === 'until' ? $rule['until'] : null,
        ]);
    }

    private function requestNotificationDetails(int $occurrenceId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT o.id, o.event_type, o.title, o.team_name, o.destination, o.purpose,
                    o.driver_name, o.starts_at, o.ends_at,
                    COALESCE(bt.name, 'Buchung') AS type_name,
                    TRIM(CONCAT(requester.first_name, ' ', requester.last_name)) AS requester_name,
                    requester.email AS requester_email,
                    GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') AS resource_names
             FROM booking_occurrences o
             JOIN booking_groups g ON g.id = o.group_id
             LEFT JOIN booking_types bt ON bt.code = o.event_type
             LEFT JOIN users requester ON requester.id = g.requested_by
             LEFT JOIN booking_resource_assignments a ON a.occurrence_id = o.id
             LEFT JOIN resources r ON r.id = a.resource_id
             WHERE o.id = :id
             GROUP BY o.id, o.event_type, o.title, o.team_name, o.destination, o.purpose,
                      o.driver_name, o.starts_at, o.ends_at, bt.name, requester.first_name,
                      requester.last_name, requester.email"
        );
        $statement->execute(['id' => $occurrenceId]);

        $details = $statement->fetch();
        return $details === false ? null : $details;
    }

    private function requestEmailDetails(array $details): array
    {
        $start = Recurrence::fromDatabase($details['starts_at'])
            ->setTimezone(new DateTimeZone('Europe/Berlin'));
        $end = Recurrence::fromDatabase($details['ends_at'])
            ->setTimezone(new DateTimeZone('Europe/Berlin'));
        $dateLabel = $start->format('d.m.Y') . ' · ' . $start->format('H:i')
            . '–' . $end->format('H:i') . ' Uhr';
        $fields = [
            ['Termin', $dateLabel],
            ['Ressource', trim((string) ($details['resource_names'] ?? '')) ?: 'Keine Ressource angegeben'],
            ['Mannschaft', trim((string) ($details['team_name'] ?? ''))],
            ['Ziel', trim((string) ($details['destination'] ?? ''))],
            ['Zweck', trim((string) ($details['purpose'] ?? ''))],
            ['Fahrer', trim((string) ($details['driver_name'] ?? ''))],
        ];
        $htmlRows = '';
        $textLines = [];
        foreach ($fields as [$label, $value]) {
            if ($value === '') {
                continue;
            }
            $htmlRows .= '<tr><td style="padding-right:14px;color:#647268">'
                . Security::escape($label) . '</td><td>' . Security::escape($value) . '</td></tr>';
            $textLines[] = $label . ': ' . $value;
        }

        return [
            'title' => self::publicTitle($details),
            'type_name' => (string) $details['type_name'],
            'html_rows' => $htmlRows,
            'text' => implode("\n", $textLines),
        ];
    }

    private function notifyApprovers(
        string $action,
        ?int $targetOccurrenceId,
        ?array $details = null
    ): array {
        if (!in_array($action, ['created', 'updated', 'deleted'], true)) {
            throw new RuntimeException('Der Benachrichtigungsanlass ist ungültig.');
        }
        if ($details === null && $targetOccurrenceId !== null) {
            $details = $this->requestNotificationDetails($targetOccurrenceId);
        }
        if ($details === null) {
            return [];
        }

        $recipients = $this->pdo->query(
            "SELECT DISTINCT id, email, TRIM(CONCAT(first_name, ' ', last_name)) AS display_name
             FROM users
             WHERE status = 'active' AND email IS NOT NULL AND email <> ''
               AND role IN ('admin', 'manager')"
        )->fetchAll();
        $errors = [];
        $emailDetails = $this->requestEmailDetails($details);
        $title = $emailDetails['title'];
        $actionLabels = [
            'created' => ['subject' => 'Neue Buchungsanfrage', 'heading' => 'Neue Buchungsanfrage'],
            'updated' => ['subject' => 'Buchungsanfrage geändert', 'heading' => 'Buchungsanfrage geändert'],
            'deleted' => ['subject' => 'Buchungsanfrage gelöscht', 'heading' => 'Buchungsanfrage gelöscht'],
        ];
        $label = $actionLabels[$action];
        $accentColor = $action === 'deleted' ? '#b42318' : '#14532d';
        $buttonColor = $action === 'deleted' ? '#b42318' : '#198754';
        $safeTitle = Security::escape($title);
        $safeType = Security::escape($emailDetails['type_name']);
        $safeRequester = Security::escape((string) ($details['requester_name'] ?? ''));
        $plainRequester = (string) ($details['requester_name'] ?? '');
        $affectedCount = max(1, (int) ($details['affected_count'] ?? 1));
        $ctaLabel = $action === 'deleted' && $targetOccurrenceId === null
            ? 'Zu den Buchungsanfragen'
            : 'Anfrage direkt öffnen';
        $appUrl = rtrim($this->config->get('APP_URL'), '/');
        $this->pdo->exec('DELETE FROM booking_access_tokens WHERE expires_at <= UTC_TIMESTAMP()');
        foreach ($recipients as $recipient) {
            try {
                $token = Security::randomToken();
                $this->pdo->prepare(
                    'INSERT INTO booking_access_tokens
                        (user_id, target_occurrence_id, token_hash, expires_at)
                     VALUES (:user_id, :target_occurrence_id, :token_hash,
                                DATE_ADD(UTC_TIMESTAMP(), INTERVAL 48 HOUR))'
                )->execute([
                    'user_id' => (int) $recipient['id'],
                    'target_occurrence_id' => $targetOccurrenceId,
                    'token_hash' => Security::tokenHash($token),
                ]);
                $url = $appUrl . '/?page=booking-access&token=' . rawurlencode($token);
                $html = '<!doctype html><html lang="de"><body style="margin:0;padding:32px 12px;background:#f2f5f3;'
                    . 'font-family:Arial,Helvetica,sans-serif;color:#24312a">'
                    . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
                    . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;'
                    . 'width:100%;background:#fff;border-radius:16px;overflow:hidden">'
                    . '<tr><td style="padding:28px 32px;background:' . $accentColor . ';color:#fff">'
                    . '<div style="font-size:13px;letter-spacing:1.5px;text-transform:uppercase;opacity:.8">'
                    . 'Vereins-Buchungssystem</div><h1 style="font-size:24px;line-height:1.3;margin:10px 0 0">'
                    . Security::escape($label['heading']) . '</h1></td></tr>'
                    . '<tr><td style="padding:28px 32px"><p style="font-size:16px;margin:0 0 8px">Hallo '
                    . Security::escape((string) $recipient['display_name']) . ',</p><p style="color:#536158;margin:0 0 24px">'
                    . ($action === 'deleted'
                        ? ($affectedCount === 1
                            ? 'Eine Buchungsanfrage wurde gelöscht.'
                            : 'Eine Buchungsanfrage mit ' . $affectedCount . ' Terminen wurde gelöscht.')
                        : ($action === 'created'
                            ? 'Eine neue Buchungsanfrage wartet auf Bearbeitung.'
                            : 'Eine Buchungsanfrage wurde geändert.'))
                    . '</p><div style="padding:20px;background:#f4f7f5;border:1px solid #e1e9e3;border-radius:12px">'
                    . '<div style="font-size:12px;color:#5d6b62;text-transform:uppercase;letter-spacing:1px">'
                    . $safeType . '</div><div style="font-size:20px;font-weight:bold;margin:7px 0 16px">'
                    . $safeTitle . '</div><table role="presentation" cellspacing="0" cellpadding="0"'
                    . ' style="font-size:14px;line-height:1.7;color:#36443a">' . $emailDetails['html_rows']
                    . '<tr><td style="padding-right:14px;color:#647268">Angefragt von</td><td>'
                    . ($safeRequester !== '' ? $safeRequester : '—') . '</td></tr></table></div>'
                    . '<div style="text-align:center;padding:26px 0 8px"><a href="' . Security::escape($url)
                    . '" style="display:inline-block;padding:13px 24px;background:' . $buttonColor . ';color:#fff;'
                    . 'text-decoration:none;font-weight:bold;border-radius:8px">'
                    . Security::escape($ctaLabel) . '</a></div>'
                    . '<p style="font-size:12px;line-height:1.6;color:#738078;text-align:center;margin:14px 0 0">'
                    . 'Der Anmeldelink ist 48 Stunden gültig und kann innerhalb dieses Zeitraums mehrfach verwendet werden.</p></td></tr>'
                    . '<tr><td style="padding:18px 32px;background:#f8faf8;color:#738078;font-size:12px">'
                    . 'Automatische Benachrichtigung des Vereins-Buchungssystems</td></tr></table></td></tr></table></body></html>';
                $text = "Hallo {$recipient['display_name']},\n\n"
                    . $label['heading'] . "\n{$title}\n{$emailDetails['text']}\n"
                    . 'Angefragt von: ' . ($plainRequester !== '' ? $plainRequester : '—') . "\n\n"
                    . "{$ctaLabel} und melde dich dabei automatisch an (48 Stunden gültig und mehrfach verwendbar):\n{$url}";
                $this->mailer->send(
                    $recipient['email'],
                    $label['subject'],
                    $html,
                    $text
                );
            } catch (Throwable $exception) {
                error_log('Booking notification failed: ' . $exception->getMessage());
                $errors[] = $recipient['email'] . ': Die Benachrichtigung konnte nicht versendet werden.';
            }
        }

        return $errors;
    }

    private function notifyRequesterOfRequest(array $details, string $state, string $reason = ''): array
    {
        $email = trim((string) ($details['requester_email'] ?? ''));
        if ($email === '') {
            return [];
        }

        $stateLabels = [
            'freigegeben' => [
                'message' => 'Deine Buchungsanfrage wurde freigegeben.',
                'color' => '#14532d',
                'button_color' => '#198754',
            ],
            'abgelehnt' => [
                'message' => 'Deine Buchungsanfrage wurde abgelehnt.',
                'color' => '#b42318',
                'button_color' => '#b42318',
            ],
            'gelöscht' => [
                'message' => 'Deine Buchungsanfrage wurde gelöscht.',
                'color' => '#b42318',
                'button_color' => '#b42318',
            ],
        ];
        if (!isset($stateLabels[$state])) {
            throw new RuntimeException('Der Status der Anfragebenachrichtigung ist ungültig.');
        }

        $emailDetails = $this->requestEmailDetails($details);
        $name = trim((string) ($details['requester_name'] ?? ''));
        $greeting = $name === '' ? 'Hallo,' : 'Hallo ' . Security::escape($name) . ',';
        $plainGreeting = $name === '' ? 'Hallo,' : "Hallo {$name},";
        $safeType = Security::escape($emailDetails['type_name']);
        $safeTitle = Security::escape($emailDetails['title']);
        $safeState = Security::escape($state);
        $reason = trim($reason);
        $reasonHtml = $reason === ''
            ? ''
            : '<p style="margin:18px 0 0"><strong>Begründung:</strong> '
                . Security::escape($reason) . '</p>';
        $reasonText = $reason === '' ? '' : "\nBegründung: {$reason}";
        $url = rtrim($this->config->get('APP_URL'), '/') . '/?page=dashboard';
        $label = $stateLabels[$state];
        try {
            $this->mailer->send(
                $email,
                'Buchungsanfrage ' . $state,
                '<!doctype html><html lang="de"><body style="margin:0;padding:32px 12px;background:#f2f5f3;'
                    . 'font-family:Arial,Helvetica,sans-serif;color:#24312a"><table role="presentation" width="100%"'
                    . ' cellspacing="0" cellpadding="0"><tr><td align="center"><table role="presentation" width="600"'
                    . ' cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#fff;'
                    . 'border-radius:16px;overflow:hidden"><tr><td style="padding:28px 32px;background:'
                    . $label['color'] . ';color:#fff"><div style="font-size:13px;letter-spacing:1.5px;'
                    . 'text-transform:uppercase;opacity:.8">Vereins-Buchungssystem</div><h1 style="font-size:24px;'
                    . 'line-height:1.3;margin:10px 0 0">Buchungsanfrage ' . $safeState
                    . '</h1></td></tr><tr><td style="padding:28px 32px"><p style="font-size:16px;margin:0 0 8px">'
                    . $greeting . '</p><p style="color:#536158;margin:0 0 24px">'
                    . Security::escape($label['message']) . '</p><div style="padding:20px;background:#f4f7f5;'
                    . 'border:1px solid #e1e9e3;border-radius:12px"><div style="font-size:12px;color:#5d6b62;'
                    . 'text-transform:uppercase;letter-spacing:1px">' . $safeType
                    . '</div><div style="font-size:20px;font-weight:bold;margin:7px 0 16px">' . $safeTitle
                    . '</div><table role="presentation" cellspacing="0" cellpadding="0"'
                    . ' style="font-size:14px;line-height:1.7;color:#36443a">'
                    . $emailDetails['html_rows'] . '</table>' . $reasonHtml . '</div>'
                    . '<div style="text-align:center;padding:26px 0 8px"><a href="'
                    . Security::escape($url) . '" style="display:inline-block;padding:13px 24px;background:'
                    . $label['button_color'] . ';color:#fff;text-decoration:none;font-weight:bold;border-radius:8px">'
                    . 'Zum Dashboard</a></div></td></tr><tr><td style="padding:18px 32px;background:#f8faf8;'
                    . 'color:#738078;font-size:12px">Automatische Benachrichtigung des Vereins-Buchungssystems'
                    . '</td></tr></table></td></tr></table></body></html>',
                "{$plainGreeting}\n\n{$label['message']}\n{$emailDetails['title']}\n"
                    . "{$emailDetails['text']}{$reasonText}\n{$url}"
            );
        } catch (RuntimeException $exception) {
            return [$email . ': ' . $exception->getMessage()];
        }

        return [];
    }

    private function notifyRequester(?string $email, ?string $name, string $state): array
    {
        if ($email === null || $name === null) {
            return [];
        }

        $url = $this->config->get('APP_URL') . '/?page=dashboard';
        try {
            $this->mailer->send(
                $email,
                'Buchungsanfrage ' . $state,
                '<p>Hallo ' . Security::escape($name) . ',</p><p>Deine Anfrage wurde ' . Security::escape($state) . '.</p><p><a href="' . Security::escape($url) . '">Zum Dashboard</a></p>',
                "Hallo {$name},\n\nDeine Anfrage wurde {$state}.\n{$url}"
            );
        } catch (RuntimeException $exception) {
            return [$email . ': ' . $exception->getMessage()];
        }

        return [];
    }

    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
    }

    private function deleteOverrideReferences(array $assignmentIds): void
    {
        if ($assignmentIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
        $this->pdo->prepare(
            "DELETE FROM booking_overrides
             WHERE new_assignment_id IN ({$placeholders})
                OR conflicting_assignment_id IN ({$placeholders})"
        )->execute(array_merge($assignmentIds, $assignmentIds));
    }

    private function requiredTitle(mixed $value): string
    {
        $title = trim((string) ($value ?? ''));
        if ($title === '' || preg_match('/[^\s\p{Z}]/u', $title) !== 1) {
            throw new RuntimeException('Bitte gib eine Mannschaft oder einen Hinweis an.');
        }
        if (mb_strlen($title) > 180) {
            throw new RuntimeException('Mannschaft / Hinweis darf höchstens 180 Zeichen lang sein.');
        }

        return $title;
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $maxLength) {
            throw new RuntimeException("Ein Eingabefeld darf höchstens {$maxLength} Zeichen lang sein.");
        }

        return $text;
    }

    private static function publicTitle(array $row): string
    {
        if ($row['event_type'] === 'bus' && $row['purpose'] !== null && $row['purpose'] !== '') {
            return $row['purpose'];
        }
        return trim((string) ($row['title'] ?? ''));
    }
}
