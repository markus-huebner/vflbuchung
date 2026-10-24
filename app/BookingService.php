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
    public function __construct(
        private readonly PDO $pdo,
        private readonly Mailer $mailer,
        private readonly Config $config
    ) {
    }

    public function create(array $data, array $actor): array
    {
        $eventType = (string) ($data['event_type'] ?? '');
        $resourceIds = array_values(array_unique(array_map('intval', $data['resource_ids'] ?? [])));
        $isTrainer = $actor['role'] === 'trainer';
        $isAdmin = $actor['role'] === 'admin';
        $isBus = $eventType === 'bus';
        $resourceMode = $isBus ? 'bus' : (string) ($data['resource_mode'] ?? '');

        if (!in_array($eventType, ['training', 'match', 'external', 'bus'], true)) {
            throw new RuntimeException('Terminart ungültig.');
        }
        if (!$isBus && !in_array($resourceMode, ['pitch', 'cabin'], true)) {
            throw new RuntimeException('Wähle eine Platzbelegung oder eine separate Kabinenbuchung aus.');
        }
        if ($isTrainer && !in_array($eventType, ['training', 'match', 'bus'], true)) {
            throw new RuntimeException('Trainer dürfen keine externen Belegungen anlegen.');
        }
        if ($eventType === 'external' && !in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new RuntimeException('Für diese Terminart fehlen die Rechte.');
        }
        if ($resourceIds === []) {
            throw new RuntimeException($resourceMode === 'pitch'
                ? 'Wähle mindestens einen Platz aus.'
                : ($resourceMode === 'cabin'
                    ? 'Wähle mindestens eine Kabine aus.'
                    : 'Wähle mindestens eine Ressource aus.'));
        }
        if ($isBus && count($resourceIds) !== 1) {
            throw new RuntimeException('Eine Busbuchung kann genau einen Bus enthalten.');
        }

        $resources = $this->loadActiveResources($resourceIds);
        $resourceTypes = array_values(array_unique(array_column($resources, 'type')));
        if (count($resourceTypes) !== 1) {
            throw new RuntimeException('Plätze und separat gebuchte Kabinen müssen als getrennte Termine gebucht werden.');
        }
        if ($resourceTypes[0] !== $resourceMode) {
            throw new RuntimeException(match ($resourceMode) {
                'pitch' => 'Eine Platzbelegung benötigt mindestens einen Platz.',
                'cabin' => 'Eine separate Kabinenbuchung darf keine Plätze enthalten.',
                default => 'Eine Fahrzeugbuchung benötigt ein Fahrzeug.',
            });
        }
        foreach ($resources as $resource) {
            if (($resource['type'] === 'bus') !== $isBus) {
                throw new RuntimeException('Bus und Plätze müssen als getrennte Termine gebucht werden.');
            }
        }

        $start = Recurrence::parseLocalDateTime((string) ($data['starts_at'] ?? ''));
        $end = Recurrence::parseLocalDateTime((string) ($data['ends_at'] ?? ''));
        if ($end <= $start) {
            throw new RuntimeException('Das Ende muss nach dem Beginn liegen.');
        }
        if (!$isBus && $start->format('Y-m-d') !== $end->format('Y-m-d')) {
            throw new RuntimeException('Platz- und Kabinenbuchungen müssen am selben Tag enden.');
        }

        $recurrenceRule = null;
        if (!$isBus && ($data['frequency'] ?? 'none') !== 'none') {
            $recurrenceRule = [
                'frequency' => (string) $data['frequency'],
                'interval' => (int) ($data['interval'] ?? 1),
                'weekdays' => array_values(array_filter(array_map('strtoupper', $data['weekdays'] ?? []))),
                'limit_type' => (string) ($data['limit_type'] ?? ''),
                'count' => $data['count'] ?? null,
                'until' => $data['until'] ?? null,
            ];
        }
        if ($isBus && ($data['frequency'] ?? 'none') !== 'none') {
            throw new RuntimeException('Busbuchungen können nicht als Serie angelegt werden.');
        }

        $occurrences = Recurrence::generate($start, $end, $recurrenceRule);
        $groupData = $this->validateEventDetails($data, $eventType);
        $cabinIds = $this->validateCabins(
            $data['cabin_ids'] ?? [],
            $resourceMode === 'pitch' && !$isBus
        );

        $status = $isTrainer ? 'pending' : 'confirmed';
        $forceConflicts = $isAdmin && !empty($data['force_conflicts']);
        $conflictOverrides = [];

        $this->pdo->beginTransaction();
        try {
            $this->lockResources(array_merge($resourceIds, $cabinIds));
            $lockedResources = $this->loadActiveResources($resourceIds);
            $lockedResourceTypes = array_values(array_unique(array_column($lockedResources, 'type')));
            if (count($lockedResourceTypes) !== 1 || $lockedResourceTypes[0] !== $resourceTypes[0]) {
                throw new RuntimeException('Die Ressource wurde während der Buchung geändert. Bitte versuche es erneut.');
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
                    (group_id, occurrence_no, event_type, title, team_name, opponent, match_venue,
                     destination, purpose, driver_name, private_notes,
                     starts_at, ends_at, status)
                 VALUES
                    (:group_id, :occurrence_no, :event_type, :title, :team_name, :opponent, :match_venue,
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

                $sharedCabinConflicts = [];
                $conflictsByResource = [];
                if (!$isTrainer) {
                    foreach ($cabinIds as $cabinId) {
                        $sharedCabinConflicts = array_merge(
                            $sharedCabinConflicts,
                            $this->findResourceConflictsIncludingAttached($cabinId, $startsAt, $endsAt)
                        );
                    }
                    $sharedCabinConflicts = $this->uniqueConflicts($sharedCabinConflicts);
                    foreach ($resourceIds as $resourceId) {
                        $conflictsByResource[$resourceId] = $this->uniqueConflicts(array_merge(
                            $this->findResourceConflictsIncludingAttached($resourceId, $startsAt, $endsAt),
                            $sharedCabinConflicts
                        ));
                        if ($conflictsByResource[$resourceId] !== [] && !$forceConflicts) {
                            throw new RuntimeException('Die gewählte Ressource oder Kabine ist in diesem Zeitraum bereits bestätigt belegt.');
                        }
                    }
                }

                foreach ($resourceIds as $resourceId) {
                    $insertAssignment->execute([
                        'occurrence_id' => $occurrenceId,
                        'resource_id' => $resourceId,
                        'status' => $status,
                    ]);
                    $assignmentId = (int) $this->pdo->lastInsertId();

                    if (!$isTrainer && ($conflictsByResource[$resourceId] ?? []) !== []) {
                        foreach ($conflictsByResource[$resourceId] as $conflict) {
                            $conflictOverrides[] = [
                                'new_assignment_id' => $assignmentId,
                                'conflicting_assignment_id' => $conflict['assignment_id'],
                            ];
                        }
                    }
                }
                foreach ($cabinIds as $cabinId) {
                    $insertCabin->execute([
                        'occurrence_id' => $occurrenceId,
                        'cabin_id' => $cabinId,
                    ]);
                }
            }

            if ($conflictOverrides !== []) {
                $this->writeConflictOverrides((int) $actor['id'], $conflictOverrides);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $mailErrors = $isTrainer ? $this->notifyApprovers($groupId) : [];

        return [
            'group_id' => $groupId,
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
                           o.event_type, o.title, o.team_name, o.opponent, o.match_venue,
                           o.destination, o.purpose, o.starts_at, o.ends_at{$privateFields},
                           NULL AS parent_resource_name, NULL AS cabin_id, 0 AS is_attached_cabin,
                           NULL AS cabins
                    FROM booking_resource_assignments a
                    JOIN booking_occurrences o ON o.id = a.occurrence_id
                    JOIN booking_groups g ON g.id = o.group_id
                    JOIN resources r ON r.id = a.resource_id
                    JOIN resource_categories rc ON rc.id = r.category_id AND rc.code = 'cabin'
                    WHERE a.status = 'confirmed' AND o.status = 'confirmed'
                      AND o.starts_at < :direct_range_end AND o.ends_at > :direct_range_start
                    UNION ALL
                    SELECT a.id AS assignment_id, o.id AS occurrence_id, g.id AS group_id,
                           c.id AS resource_id, c.name AS resource_name, cc.code AS resource_type,
                           o.event_type, o.title, o.team_name, o.opponent, o.match_venue,
                           o.destination, o.purpose, o.starts_at, o.ends_at{$privateFields},
                           NULL AS parent_resource_name, c.id AS cabin_id, 1 AS is_attached_cabin,
                           c.name AS cabins
                    FROM booking_cabin_assignments bca
                    JOIN booking_occurrences o ON o.id = bca.occurrence_id
                    JOIN (
                        SELECT a.occurrence_id, MIN(a.id) AS assignment_id
                        FROM booking_resource_assignments a
                        JOIN resources p ON p.id = a.resource_id
                        JOIN resource_categories pc ON pc.id = p.category_id AND pc.code = 'pitch'
                        WHERE a.status = 'confirmed'
                        GROUP BY a.occurrence_id
                    ) pitch_assignment ON pitch_assignment.occurrence_id = o.id
                    JOIN booking_resource_assignments a ON a.id = pitch_assignment.assignment_id
                    JOIN booking_groups g ON g.id = o.group_id
                    JOIN resources p ON p.id = a.resource_id
                    JOIN resources c ON c.id = bca.cabin_id
                    JOIN resource_categories cc ON cc.id = c.category_id AND cc.code = 'cabin'
                    WHERE a.status = 'confirmed' AND o.status = 'confirmed'
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
                        o.event_type, o.title, o.team_name, o.opponent, o.match_venue,
                        o.destination, o.purpose, o.starts_at, o.ends_at{$privateFields},
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
                 WHERE a.status = 'confirmed'
                   AND o.status = 'confirmed'
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

        return array_map(static function (array $row) use ($timezone): array {
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
            $row['display_title'] = self::publicTitle($row);

            return $row;
        }, $statement->fetchAll());
    }

    public function requests(array $actor): array
    {
        $isStaff = in_array($actor['role'], ['admin', 'manager'], true);
        if ($isStaff) {
            $where = "a.status = 'pending'";
            $params = [];
        } else {
            $where = 'g.requested_by = :user_id';
            $params = ['user_id' => $actor['id']];
        }

        $statement = $this->pdo->prepare(
            "SELECT a.id AS assignment_id, o.id AS occurrence_id, g.id AS group_id,
                    a.status AS assignment_status, o.status AS occurrence_status,
                    o.event_type, o.title, o.team_name, o.opponent, o.match_venue,
                    o.destination, o.purpose, o.driver_name, o.private_notes,
                    o.starts_at, o.ends_at, o.rejection_reason,
                    r.id AS resource_id, r.name AS resource_name, rc.code AS resource_type,
                    u.display_name AS requester_name, u.email AS requester_email,
                    (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ')
                     FROM booking_cabin_assignments bca
                     JOIN resources c ON c.id = bca.cabin_id
                     WHERE bca.occurrence_id = o.id) AS cabins
             FROM booking_resource_assignments a
             JOIN booking_occurrences o ON o.id = a.occurrence_id
             JOIN booking_groups g ON g.id = o.group_id
             JOIN resources r ON r.id = a.resource_id
             JOIN resource_categories rc ON rc.id = r.category_id
             LEFT JOIN users u ON u.id = g.requested_by
             WHERE {$where}
             ORDER BY o.starts_at DESC, o.id, r.name"
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function approve(array $assignmentIds, array $actor, array $overrideIds = []): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new RuntimeException('Für Freigaben fehlen die Rechte.');
        }
        $assignmentIds = $this->normalizeIds($assignmentIds);
        if ($assignmentIds === []) {
            throw new RuntimeException('Wähle mindestens eine Anfrage aus.');
        }

        $result = ['approved' => 0, 'skipped' => 0, 'mail_errors' => []];
        $affectedGroups = [];
        $overrides = [];

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

                $conflicts = $this->conflictsForAssignment($assignment);
                $force = $actor['role'] === 'admin' && in_array((int) $assignment['id'], $overrideIds, true);
                if ($conflicts !== [] && !$force) {
                    $this->setAssignmentStatus((int) $assignment['id'], 'skipped');
                    $result['skipped']++;
                } else {
                    $this->setAssignmentStatus((int) $assignment['id'], 'confirmed');
                    $result['approved']++;
                    if ($force) {
                        foreach ($conflicts as $conflict) {
                            $overrides[] = [
                                'new_assignment_id' => (int) $assignment['id'],
                                'conflicting_assignment_id' => (int) $conflict['assignment_id'],
                            ];
                        }
                    }
                }
                $groupId = (int) $assignment['group_id'];
                $affectedGroups[$groupId] ??= ['approved' => false, 'skipped' => false];
                $affectedGroups[$groupId][$conflicts !== [] && !$force ? 'skipped' : 'approved'] = true;
                $this->refreshOccurrenceStatus((int) $assignment['occurrence_id']);
            }

            if ($overrides !== []) {
                $this->writeConflictOverrides((int) $actor['id'], $overrides);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        foreach ($affectedGroups as $groupId => $state) {
            $notice = match (true) {
                $state['approved'] && $state['skipped'] => 'teilweise freigegeben',
                $state['skipped'] => 'wegen eines Konflikts ausgelassen',
                default => 'freigegeben',
            };
            $result['mail_errors'] = array_merge(
                $result['mail_errors'],
                $this->notifyGroupRequester((int) $groupId, $notice)
            );
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
                $groups[(int) $details['group_id']] = true;
                $this->pdo->prepare(
                    'UPDATE booking_occurrences SET rejection_reason = :reason WHERE id = :id'
                )->execute(['reason' => $reason !== '' ? $reason : null, 'id' => $details['occurrence_id']]);
                $this->refreshOccurrenceStatus((int) $details['occurrence_id']);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $mailErrors = [];
        foreach (array_keys($groups) as $groupId) {
            $mailErrors = array_merge(
                $mailErrors,
                $this->notifyGroupRequester((int) $groupId, 'abgelehnt', $reason)
            );
        }

        return $mailErrors;
    }

    public function withdraw(array $assignmentIds, array $actor): void
    {
        $assignmentIds = $this->normalizeIds($assignmentIds);
        if ($assignmentIds === []) {
            throw new RuntimeException('Wähle mindestens eine Anfrage aus.');
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($assignmentIds as $id) {
                $details = $this->assignmentDetails($id);
                if ($details === null
                    || (int) $details['requested_by'] !== (int) $actor['id']
                    || $details['status'] !== 'pending') {
                    throw new RuntimeException('Du kannst nur eigene offene Anfragen zurückziehen.');
                }
                $this->setAssignmentStatus($id, 'withdrawn');
                $this->refreshOccurrenceStatus((int) $details['occurrence_id']);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function editPendingRequest(int $assignmentId, array $data, array $actor): void
    {
        if ($actor['role'] !== 'trainer') {
            throw new RuntimeException('Nur Trainer können eigene offene Anfragen ändern.');
        }

        $this->pdo->beginTransaction();
        try {
            $details = $this->assignmentDetails($assignmentId, true);
            if ($details === null
                || (int) $details['requested_by'] !== (int) $actor['id']
                || $details['status'] !== 'pending') {
                throw new RuntimeException('Du kannst nur eigene offene Anfragen ändern.');
            }

            $start = Recurrence::parseLocalDateTime((string) ($data['starts_at'] ?? ''));
            $end = Recurrence::parseLocalDateTime((string) ($data['ends_at'] ?? ''));
            if ($end <= $start) {
                throw new RuntimeException('Das Ende muss nach dem Beginn liegen.');
            }
            if ($details['event_type'] !== 'bus' && $start->format('Y-m-d') !== $end->format('Y-m-d')) {
                throw new RuntimeException('Platz- und Kabinenbuchungen müssen am selben Tag enden.');
            }

            $title = $this->optionalText($data['title'] ?? null, 180) ?? '';
            $teamName = in_array($details['event_type'], ['training', 'match'], true)
                ? $this->optionalText($data['team_name'] ?? $details['team_name'], 120)
                : null;
            $this->pdo->prepare(
                'UPDATE booking_occurrences
                 SET title = :title, team_name = :team_name, starts_at = :starts_at, ends_at = :ends_at
                 WHERE id = :id AND status = \'pending\''
            )->execute([
                'title' => $title,
                'team_name' => $teamName,
                'starts_at' => Recurrence::toDatabase($start),
                'ends_at' => Recurrence::toDatabase($end),
                'id' => $details['occurrence_id'],
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function cancel(int $assignmentId, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new RuntimeException('Für Stornierungen fehlen die Rechte.');
        }

        $details = $this->assignmentDetails($assignmentId);
        if ($details === null || $details['status'] !== 'confirmed') {
            throw new RuntimeException('Die bestätigte Buchung wurde nicht gefunden.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM booking_resource_assignments WHERE id = :id')
                ->execute(['id' => $assignmentId]);
            $remaining = $this->pdo->prepare(
                'SELECT COUNT(*) FROM booking_resource_assignments WHERE occurrence_id = :occurrence_id'
            );
            $remaining->execute(['occurrence_id' => $details['occurrence_id']]);
            if ((int) $remaining->fetchColumn() === 0) {
                $this->pdo->prepare('DELETE FROM booking_occurrences WHERE id = :id')
                    ->execute(['id' => $details['occurrence_id']]);
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

        return ['mail_errors' => $this->notifyRequester(
            $details['requester_email'] ?? null,
            $details['requester_name'] ?? null,
            'storniert'
        )];
    }

    public function cancelCabin(int $occurrenceId, int $cabinId, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new RuntimeException('Für Stornierungen fehlen die Rechte.');
        }

        $this->pdo->beginTransaction();
        try {
            $details = $this->occurrenceDetails($occurrenceId, true);
            if ($details === null || $details['status'] !== 'confirmed' || $details['event_type'] === 'bus') {
                throw new RuntimeException('Die bestätigte Kabinenbelegung wurde nicht gefunden.');
            }
            $this->lockOccurrenceResources([$occurrenceId], [$cabinId]);
            $delete = $this->pdo->prepare(
                'DELETE FROM booking_cabin_assignments WHERE occurrence_id = :occurrence_id AND cabin_id = :cabin_id'
            );
            $delete->execute(['occurrence_id' => $occurrenceId, 'cabin_id' => $cabinId]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('Die Kabine ist dieser Buchung nicht zugeordnet.');
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return ['mail_errors' => $this->notifyRequester(
            $details['requester_email'] ?? null,
            $details['requester_name'] ?? null,
            'Kabinenbelegung storniert'
        )];
    }

    public function editOccurrence(int $occurrenceId, array $data, array $actor): array
    {
        if (!in_array($actor['role'], ['admin', 'manager'], true)) {
            throw new RuntimeException('Für Änderungen fehlen die Rechte.');
        }

        $scope = (string) ($data['scope'] ?? 'one');
        if (!in_array($scope, ['one', 'future', 'all'], true)) {
            throw new RuntimeException('Änderungsumfang ungültig.');
        }
        $target = $this->occurrenceDetails($occurrenceId);
        if ($target === null || $target['status'] !== 'confirmed') {
            throw new RuntimeException('Der bestätigte Termin wurde nicht gefunden.');
        }

        $updateCabins = (string) ($data['update_cabins'] ?? '0') === '1';
        $cabinIds = [];
        if ($updateCabins) {
            if ($target['event_type'] === 'bus') {
                throw new RuntimeException('Für Fahrzeugbuchungen können keine Kabinen zugeordnet werden.');
            }
            $cabinIds = $this->validateCabins($data['cabin_ids'] ?? [], true);
        }

        $newStart = Recurrence::parseLocalDateTime((string) ($data['starts_at'] ?? ''));
        $newEnd = Recurrence::parseLocalDateTime((string) ($data['ends_at'] ?? ''));
        if ($newEnd <= $newStart) {
            throw new RuntimeException('Das Ende muss nach dem Beginn liegen.');
        }
        if ($target['event_type'] !== 'bus' && $newStart->format('Y-m-d') !== $newEnd->format('Y-m-d')) {
            throw new RuntimeException('Platz- und Kabinenbuchungen müssen am selben Tag enden.');
        }

        $groupId = (int) $target['group_id'];
        $selectedOccurrences = $this->occurrencesForScope($groupId, $occurrenceId, $scope);
        $delta = $newStart->getTimestamp() - Recurrence::fromDatabase($target['starts_at'])->getTimestamp();
        $duration = $newEnd->getTimestamp() - $newStart->getTimestamp();
        $title = trim((string) ($data['title'] ?? $target['title']));
        if (mb_strlen($title) > 180) {
            throw new RuntimeException('Der Titel darf höchstens 180 Zeichen lang sein.');
        }
        $teamName = in_array($target['event_type'], ['training', 'match'], true)
            ? $this->optionalText($data['team_name'] ?? $target['team_name'], 120)
            : null;

        $this->pdo->beginTransaction();
        try {
            $this->lockOccurrenceResources($selectedOccurrences, $cabinIds);
            if ($scope === 'future') {
                $newGroupId = $this->splitFutureOccurrences($groupId, $occurrenceId, (int) $actor['id']);
                $selectedOccurrences = $this->occurrenceIdsForGroup($newGroupId);
            }

            $update = $this->pdo->prepare(
                'UPDATE booking_occurrences
                 SET starts_at = :starts_at, ends_at = :ends_at,
                     title = :title, team_name = :team_name
                 WHERE id = :id'
            );
            foreach ($selectedOccurrences as $id) {
                $row = $this->occurrenceDetails((int) $id);
                if ($row === null) {
                    continue;
                }
                $oldStart = Recurrence::fromDatabase($row['starts_at']);
                $startsAt = $oldStart->modify(($delta >= 0 ? '+' : '') . $delta . ' seconds');
                $endsAt = $startsAt->modify('+' . $duration . ' seconds');
                if ($target['event_type'] !== 'bus' && $startsAt->format('Y-m-d') !== $endsAt->format('Y-m-d')) {
                    throw new RuntimeException('Platz- und Kabinenbuchungen müssen am selben Tag enden.');
                }
                $startsAtDatabase = Recurrence::toDatabase($startsAt);
                $endsAtDatabase = Recurrence::toDatabase($endsAt);
                if ($updateCabins) {
                    $pitchCount = $this->pdo->prepare(
                        "SELECT COUNT(*)
                         FROM booking_resource_assignments a
                         JOIN resources r ON r.id = a.resource_id
                         JOIN resource_categories rc ON rc.id = r.category_id AND rc.code = 'pitch'
                         WHERE a.occurrence_id = :occurrence_id AND a.status = 'confirmed'"
                    );
                    $pitchCount->execute(['occurrence_id' => $id]);
                    if ((int) $pitchCount->fetchColumn() === 0) {
                        throw new RuntimeException('Die bestätigte Platzbuchung wurde nicht gefunden.');
                    }
                    $this->replaceCabinAssignments((int) $id, $cabinIds);
                }
                $this->assertNoOccurrenceConflicts((int) $id, $startsAtDatabase, $endsAtDatabase);
                $update->execute([
                    'starts_at' => $startsAtDatabase,
                    'ends_at' => $endsAtDatabase,
                    'title' => $title,
                    'team_name' => $teamName,
                    'id' => $id,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return ['updated' => count($selectedOccurrences)];
    }

    private function validateEventDetails(array $data, string $eventType): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if (mb_strlen($title) > 180) {
            throw new RuntimeException('Der Titel darf höchstens 180 Zeichen lang sein.');
        }

        $matchVenue = trim((string) ($data['match_venue'] ?? ''));
        $matchVenue = $matchVenue === '' ? null : $matchVenue;
        if ($matchVenue !== null && !in_array($matchVenue, ['home', 'away'], true)) {
            throw new RuntimeException('Bitte wähle Heim- oder Auswärtsspiel aus.');
        }

        return [
            'event_type' => $eventType,
            'title' => $title,
            'team_name' => in_array($eventType, ['training', 'match'], true)
                ? $this->optionalText($data['team_name'] ?? null, 120)
                : null,
            'opponent' => $eventType === 'match' ? $this->optionalText($data['opponent'] ?? null, 120) : null,
            'match_venue' => $eventType === 'match' ? $matchVenue : null,
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

    private function loadActiveResources(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT r.id, rc.code AS type, r.name
             FROM resources r
             JOIN resource_categories rc ON rc.id = r.category_id
             WHERE r.is_active = 1 AND r.id IN ({$placeholders})"
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

    private function assertNoOccurrenceConflicts(int $occurrenceId, string $startsAt, string $endsAt): void
    {
        $statement = $this->pdo->prepare(
            "SELECT a.id, a.resource_id, a.status,
                    (SELECT GROUP_CONCAT(cabin_id)
                     FROM booking_cabin_assignments WHERE occurrence_id = a.occurrence_id) AS cabin_ids_raw
             FROM booking_resource_assignments a
             WHERE a.occurrence_id = :occurrence_id"
        );
        $statement->execute(['occurrence_id' => $occurrenceId]);
        $assignments = $statement->fetchAll();
        $ownIds = array_fill_keys(array_map(
            static fn(array $assignment): int => (int) $assignment['id'],
            $assignments
        ), true);

        foreach ($assignments as $assignment) {
            if ($assignment['status'] !== 'confirmed') {
                continue;
            }

            $conflicts = $this->findResourceConflictsIncludingAttached(
                (int) $assignment['resource_id'],
                $startsAt,
                $endsAt
            );
            $cabinIds = $assignment['cabin_ids_raw'] === null
                ? []
                : array_map('intval', explode(',', $assignment['cabin_ids_raw']));
            foreach ($cabinIds as $cabinId) {
                $conflicts = array_merge(
                    $conflicts,
                    $this->findResourceConflictsIncludingAttached($cabinId, $startsAt, $endsAt)
                );
            }
            foreach ($conflicts as $conflict) {
                if (!isset($ownIds[(int) $conflict['assignment_id']])) {
                    throw new RuntimeException('Die Änderung kollidiert mit einer anderen bestätigten Buchung.');
                }
            }
        }
    }

    private function findConflicts(int $resourceId, string $startsAt, string $endsAt): array
    {
        $statement = $this->pdo->prepare(
            "SELECT a.id AS assignment_id
             FROM booking_resource_assignments a
             JOIN booking_occurrences o ON o.id = a.occurrence_id
             WHERE a.resource_id = :resource_id AND a.status = 'confirmed'
               AND o.status = 'confirmed'
               AND o.starts_at < :ends_at AND o.ends_at > :starts_at"
        );
        $statement->execute([
            'resource_id' => $resourceId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        return $statement->fetchAll();
    }

    private function findCabinConflicts(int $cabinId, string $startsAt, string $endsAt): array
    {
        $statement = $this->pdo->prepare(
            "SELECT a.id AS assignment_id
             FROM booking_cabin_assignments bca
             JOIN booking_occurrences o ON o.id = bca.occurrence_id
             JOIN booking_resource_assignments a ON a.occurrence_id = o.id
             WHERE bca.cabin_id = :cabin_id AND a.status = 'confirmed'
               AND o.status = 'confirmed'
               AND o.starts_at < :ends_at AND o.ends_at > :starts_at"
        );
        $statement->execute([
            'cabin_id' => $cabinId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        return $statement->fetchAll();
    }

    private function findResourceConflictsIncludingAttached(int $resourceId, string $startsAt, string $endsAt): array
    {
        return $this->uniqueConflicts(array_merge(
            $this->findConflicts($resourceId, $startsAt, $endsAt),
            $this->findCabinConflicts($resourceId, $startsAt, $endsAt)
        ));
    }

    private function uniqueConflicts(array $conflicts): array
    {
        $unique = [];
        foreach ($conflicts as $conflict) {
            $unique[(int) $conflict['assignment_id']] = $conflict;
        }

        return array_values($unique);
    }

    private function conflictsForAssignment(array $assignment): array
    {
        $conflicts = $this->findResourceConflictsIncludingAttached(
            (int) $assignment['resource_id'],
            $assignment['starts_at'],
            $assignment['ends_at']
        );
        foreach ($assignment['cabin_ids'] as $cabinId) {
            $conflicts = array_merge(
                $conflicts,
                $this->findResourceConflictsIncludingAttached(
                    (int) $cabinId,
                    $assignment['starts_at'],
                    $assignment['ends_at']
                )
            );
        }

        return $this->uniqueConflicts($conflicts);
    }

    private function writeConflictOverrides(int $actorId, array $overrides): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO booking_overrides
                (actor_id, new_assignment_id, conflicting_assignment_id)
             VALUES (:actor_id, :new_assignment_id, :conflicting_assignment_id)'
        );
        foreach ($overrides as $override) {
            $statement->execute([
                'actor_id' => $actorId,
                'new_assignment_id' => $override['new_assignment_id'],
                'conflicting_assignment_id' => $override['conflicting_assignment_id'],
            ]);
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
                    o.group_id, o.event_type, o.title, o.team_name, g.requested_by, o.starts_at, o.ends_at,
                    u.email AS requester_email, u.display_name AS requester_name
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
                    u.email AS requester_email, u.display_name AS requester_name
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

    private function occurrencesForScope(int $groupId, int $occurrenceId, string $scope): array
    {
        if ($scope === 'one') {
            return [$occurrenceId];
        }

        $target = $this->occurrenceDetails($occurrenceId);
        $sql = 'SELECT id FROM booking_occurrences WHERE group_id = :group_id';
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

    private function splitFutureOccurrences(int $groupId, int $occurrenceId, int $actorId): int
    {
        $target = $this->occurrenceDetails($occurrenceId);
        $newRule = $target['recurrence_rule'] === null
            ? null
            : json_decode($target['recurrence_rule'], true, 512, JSON_THROW_ON_ERROR);
        $remaining = $this->occurrencesForScope($groupId, $occurrenceId, 'future');
        if (is_array($newRule) && ($newRule['limit_type'] ?? '') === 'count') {
            $newRule['count'] = count($remaining);
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO booking_groups (created_by, requested_by, recurrence_rule, recurrence_until)
             SELECT :created_by, requested_by, :recurrence_rule, recurrence_until
             FROM booking_groups WHERE id = :group_id'
        );
        $insert->execute([
            'created_by' => $actorId,
            'recurrence_rule' => $newRule === null ? null : json_encode($newRule, JSON_THROW_ON_ERROR),
            'group_id' => $groupId,
        ]);
        $newGroupId = (int) $this->pdo->lastInsertId();

        $update = $this->pdo->prepare(
            'UPDATE booking_occurrences SET group_id = :new_group_id, occurrence_no = :occurrence_no
             WHERE id = :id'
        );
        foreach ($remaining as $index => $id) {
            $update->execute([
                'new_group_id' => $newGroupId,
                'occurrence_no' => $index,
                'id' => $id,
            ]);
        }

        return $newGroupId;
    }

    private function occurrenceIdsForGroup(int $groupId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM booking_occurrences WHERE group_id = :group_id ORDER BY occurrence_no'
        );
        $statement->execute(['group_id' => $groupId]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function notifyApprovers(int $groupId): array
    {
        $statement = $this->pdo->query(
            "SELECT DISTINCT u.email, u.display_name
             FROM users u
             WHERE u.status = 'active' AND u.email IS NOT NULL
               AND u.role IN ('admin', 'manager')"
        );
        $recipients = $statement->fetchAll();
        $url = $this->config->get('APP_URL') . '/?page=dashboard';
        $errors = [];
        foreach ($recipients as $recipient) {
            try {
                $this->mailer->send(
                    $recipient['email'],
                    'Neue Buchungsanfrage',
                    '<p>Hallo ' . Security::escape($recipient['display_name']) . ',</p><p>Es gibt eine neue Buchungsanfrage.</p><p><a href="' . Security::escape($url) . '">Anfrage prüfen</a></p>',
                    "Hallo {$recipient['display_name']},\n\nEs gibt eine neue Buchungsanfrage. Bitte im Dashboard prüfen: {$url}"
                );
            } catch (RuntimeException $exception) {
                $errors[] = $recipient['email'] . ': ' . $exception->getMessage();
            }
        }

        return $errors;
    }

    private function notifyGroupRequester(int $groupId, string $state, string $reason = ''): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.email, u.display_name
             FROM booking_groups g JOIN users u ON u.id = g.requested_by
             WHERE g.id = :id AND u.status = \'active\' AND u.email IS NOT NULL'
        );
        $statement->execute(['id' => $groupId]);
        $requester = $statement->fetch();
        if ($requester === false) {
            return [];
        }

        $url = $this->config->get('APP_URL') . '/?page=dashboard';
        $reasonHtml = $reason === '' ? '' : '<p>Begründung: ' . Security::escape($reason) . '</p>';
        $textReason = $reason === '' ? '' : "\nBegründung: {$reason}";
        try {
            $this->mailer->send(
                $requester['email'],
                'Buchungsanfrage ' . $state,
                '<p>Hallo ' . Security::escape($requester['display_name']) . ',</p><p>Deine Anfrage wurde ' . Security::escape($state) . '.</p>' . $reasonHtml . '<p><a href="' . Security::escape($url) . '">Zum Dashboard</a></p>',
                "Hallo {$requester['display_name']},\n\nDeine Anfrage wurde {$state}.{$textReason}\n{$url}"
            );
        } catch (RuntimeException $exception) {
            return [$requester['email'] . ': ' . $exception->getMessage()];
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
        $title = trim((string) ($row['title'] ?? ''));
        $opponent = trim((string) ($row['opponent'] ?? ''));
        if ($row['event_type'] === 'match' && $opponent !== '') {
            return $title !== '' ? $title . ' – ' . $opponent : $opponent;
        }

        return $title;
    }
}
