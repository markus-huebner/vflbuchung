<?php
declare(strict_types=1);

use App\BookingService;
use App\BookingTypeService;
use App\Config;
use App\Database;
use App\Mailer;
use App\Recurrence;

require getcwd() . '/www/vendor/autoload.php';

$config = Config::fromEnvironment('/dev/null', '/dev/null');
if (!preg_match('/^vflbuchung_test_[a-z0-9]+$/', $config->get('DB_NAME'))) {
    throw new RuntimeException('Run this test only against a dedicated vflbuchung_test_* database with install.sql applied.');
}
$pdo = Database::connect($config);
$service = new BookingService($pdo, new Mailer($config), $config);
foreach (['admin', 'manager', 'trainer'] as $role) {
    $pdo->prepare("INSERT INTO users (username, first_name, last_name, role, status) VALUES (:role, :first_name, '', :role_value, 'active')")
        ->execute(['role' => $role, 'first_name' => $role, 'role_value' => $role]);
    $actors[$role] = ['id' => (int) $pdo->lastInsertId(), 'role' => $role];
}
$admin = $actors['admin'];
$manager = $actors['manager'];
foreach (['pitch' => 4, 'cabin' => 2, 'bus' => 2] as $type => $count) {
    for ($index = 0; $index < $count; $index++) {
        $pdo->prepare(
            'INSERT INTO resources (category_id, name)
             SELECT id, :name FROM resource_categories WHERE code = :type'
        )->execute(['name' => $type . $index, 'type' => $type]);
        $resources[$type][] = (int) $pdo->lastInsertId();
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function fails(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException|InvalidArgumentException $exception) {
        check(str_contains($exception->getMessage(), $message), 'Unexpected error: ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}

function createBooking(string $date, array $changes = [], ?array $actor = null): array
{
    global $service, $admin, $resources, $pdo;
    $data = array_replace([
        'event_type' => 'training', 'resource_mode' => 'pitch', 'resource_ids' => [$resources['pitch'][0]],
        'title' => 'Original title', 'private_notes' => 'Original note',
        'starts_at' => $date . 'T10:00', 'ends_at' => $date . 'T11:30', 'frequency' => 'none',
    ], $changes);
    $bookingActor = $actor ?? $admin;
    $result = $service->create($data, $bookingActor);
    $query = $pdo->prepare('SELECT id FROM booking_occurrences WHERE group_id = :id ORDER BY occurrence_no');
    $query->execute(['id' => $result['group_id']]);
    $occurrenceIds = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
    check(
        $occurrenceIds !== [] && $result['occurrence_id'] === $occurrenceIds[0],
        'Return the first created occurrence ID for post-create calendar selection.'
    );
    $requestCutoff = (new DateTimeImmutable('now'))->modify('-3 months')->format('Y-m-d');
    if ($bookingActor['role'] === 'trainer' && $date >= $requestCutoff) {
        $requestPage = $service->requestPageForOccurrence($result['occurrence_id'], $bookingActor);
        check($requestPage !== null, 'Find the page containing a newly created visible request.');
        $requestListing = $service->requests($bookingActor, false, $requestPage);
        $listedOccurrenceIds = array_map('intval', array_column($requestListing['items'], 'occurrence_id'));
        check(
            in_array($result['occurrence_id'], $listedOccurrenceIds, true),
            'The calculated request page must include the new occurrence.'
        );
    }
    return $occurrenceIds;
}

function editData(int $id, array $changes = [], string $scope = 'one'): array
{
    global $service, $admin;
    $details = $service->editableOccurrence($id, $admin);
    $rule = $details['recurrence_rule'] ?? ['frequency' => 'none'];
    if (($rule['limit_type'] ?? '') === 'count') $rule['count'] = $details['scope_counts'][$scope];
    return array_replace($details, $rule, [
        'starts_at' => (new DateTimeImmutable($details['start']))->format('Y-m-d\TH:i'),
        'ends_at' => (new DateTimeImmutable($details['end']))->format('Y-m-d\TH:i'),
        'scope' => $scope,
    ], $changes);
}

$requestEditData = function (int $id, array $actor, array $changes = [], string $scope = 'one') use ($service): array {
    $details = $service->editablePendingRequest($id, $actor, $scope === 'one' ? 'one' : 'all');
    $rule = $details['recurrence_rule'] ?? ['frequency' => 'none'];
    if (($rule['limit_type'] ?? '') === 'count') $rule['count'] = $details['scope_counts'][$scope];
    return array_replace($details, $rule, [
        'starts_at' => (new DateTimeImmutable($details['start']))->format('Y-m-d\TH:i'),
        'ends_at' => (new DateTimeImmutable($details['end']))->format('Y-m-d\TH:i'),
        'scope' => $scope,
    ], $changes);
};

$seriesStart = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))
    ->modify('-4 months')->format('Y-m-d');
$seriesToReview = createBooking($seriesStart, [
    'resource_ids' => [$resources['pitch'][0], $resources['pitch'][1]],
    'frequency' => 'monthly', 'limit_type' => 'count', 'count' => 6,
], $actors['trainer']);
$allPending = $service->pendingSeriesAssignmentIds($seriesToReview[0], $manager);
check(count($allPending) === 12, 'Series actions include old and recent pending assignments.');
$service->approve([$allPending[0]], $manager);
$service->reject([$allPending[2]], $manager, 'Test');
$original = $pdo->prepare(
    'SELECT o.title, o.starts_at, a.status FROM booking_occurrences o
     JOIN booking_resource_assignments a ON a.occurrence_id = o.id WHERE a.id = ?'
);
$original->execute([$allPending[0]]);
$confirmedBefore = $original->fetch();
$original->execute([$allPending[2]]);
$rejectedBefore = $original->fetch();
foreach ([$manager, $actors['trainer']] as $viewer) {
    $rows = array_values(array_filter(
        $service->requests($viewer, true, 1, 50)['items'],
        static fn(array $row): bool => in_array((string) $seriesToReview[0], explode(',', $row['occurrence_ids']), true)
    ));
    check(count($rows) === 1 && (int) $rows[0]['is_series_summary'] === 1
        && (int) $rows[0]['occurrence_count'] === 6 && $rows[0]['assignment_status'] === 'pending',
        'Partially decided series must appear as a single pending series for both roles.');
}
$change = $requestEditData($seriesToReview[0], $manager, ['title' => 'Series changed'], 'all');
$changed = $service->editPendingRequest($seriesToReview[0], $change, $manager, true);
check($changed['approved'] === 10, 'Approval must cover all updated pending series assignments.');
$original->execute([$allPending[0]]);
check($original->fetch() === $confirmedBefore, 'Editing a series must preserve confirmed assignments and their occurrence.');
$original->execute([$allPending[2]]);
check($original->fetch() === $rejectedBefore, 'Editing a series must preserve rejected assignments and their occurrence.');

$seriesToReject = createBooking('2034-01-01', [
    'frequency' => 'daily', 'limit_type' => 'count', 'count' => 3,
], $actors['trainer']);
$rejectionIds = $service->pendingSeriesAssignmentIds($seriesToReject[1], $manager);
$service->approve([$rejectionIds[0]], $manager);
$service->reject($service->pendingSeriesAssignmentIds($seriesToReject[1], $manager), $manager, 'Test');
$original->execute([$rejectionIds[0]]);
check($original->fetch()['status'] === 'confirmed', 'Series rejection must preserve confirmed assignments.');
foreach (array_slice($rejectionIds, 1) as $id) {
    $original->execute([$id]);
    check($original->fetch()['status'] === 'rejected', 'Series rejection must reject all remaining requests.');
}

$seriesToDelete = createBooking('2034-02-01', [
    'frequency' => 'daily', 'limit_type' => 'count', 'count' => 3,
], $actors['trainer']);
$deletionIds = $service->pendingSeriesAssignmentIds($seriesToDelete[0], $actors['trainer']);
$service->approve([$deletionIds[0]], $manager);
$service->deletePendingRequests($service->pendingSeriesAssignmentIds($seriesToDelete[1], $actors['trainer']), $actors['trainer']);
$original->execute([$deletionIds[0]]);
check($original->fetch()['status'] === 'confirmed', 'Series withdrawal must preserve confirmed assignments.');
$original->execute([$deletionIds[1]]);
check($original->fetch() === false, 'Series withdrawal must remove pending assignments.');

$seriesToReschedule = createBooking('2034-03-01', [
    'frequency' => 'daily', 'limit_type' => 'count', 'count' => 3,
], $actors['trainer']);
$rescheduleIds = $service->pendingSeriesAssignmentIds($seriesToReschedule[0], $manager);
$service->approve([$rescheduleIds[0]], $manager);
$original->execute([$rescheduleIds[0]]);
$beforeReschedule = $original->fetch();
$service->editPendingRequest($seriesToReschedule[1], $requestEditData(
    $seriesToReschedule[1], $actors['trainer'], ['count' => 4], 'all'
), $actors['trainer']);
$original->execute([$rescheduleIds[0]]);
check($original->fetch() === $beforeReschedule, 'Changing the recurrence must preserve decided occurrences.');
check(count($service->pendingSeriesAssignmentIds($seriesToReschedule[1], $actors['trainer'])) === 4,
    'Changing recurrence must regenerate the open part of the series.');

$seriesToReduce = createBooking('2034-04-01', [
    'resource_ids' => [$resources['pitch'][0], $resources['pitch'][1]],
    'frequency' => 'daily', 'limit_type' => 'count', 'count' => 2,
], $actors['trainer']);
$reductionIds = $service->pendingSeriesAssignmentIds($seriesToReduce[0], $manager);
$service->approve([$reductionIds[0]], $manager);
$result = $service->editPendingRequest($seriesToReduce[0], $requestEditData(
    $seriesToReduce[0], $manager, ['resource_ids' => [$resources['pitch'][0]]], 'all'
), $manager, true);
check($result['approved'] === 1, 'Removing a resource must not recreate decided assignments or leave empty requests.');
$original->execute([$reductionIds[0]]);
check($original->fetch()['status'] === 'confirmed', 'Removing a pending resource must preserve confirmed assignments.');

$bookingTypeService = new BookingTypeService($pdo);
$restrictedTypeCode = $bookingTypeService->save('Nur für Admins', 'red', null, false);
fails(
    fn() => createBooking('2031-02-01', ['event_type' => $restrictedTypeCode], $actors['trainer']),
    'Buchungsart keine Anfragen'
);

$pendingRequestId = createBooking('2031-03-01', [
    'resource_ids' => [$resources['pitch'][0], $resources['pitch'][1]],
], $actors['trainer'])[0];
$pendingRequestEvents = array_values(array_filter(
    $service->events('2031-03-01', '2031-03-02', 'pitch', false),
    static fn(array $event): bool => (int) $event['occurrence_id'] === $pendingRequestId
));
check(count($pendingRequestEvents) === 2, 'Create a multi-place pending request for the shared edit flow.');
$groupedTrainerRequests = array_values(array_filter(
    $service->requests($actors['trainer'])['items'],
    static fn(array $request): bool => (int) $request['occurrence_id'] === $pendingRequestId
));
check(
    count($groupedTrainerRequests) === 1
        && count(explode(',', $groupedTrainerRequests[0]['pending_assignment_ids'])) === 2
        && count(explode(',', $groupedTrainerRequests[0]['resource_ids'])) === 2,
    'A multi-place request must be shown once with all pending assignment IDs.'
);
$today = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
$submittedFirst = createBooking($today->modify('+2 months')->format('Y-m-d'), [], $actors['trainer'])[0];
$submittedSecond = createBooking($today->modify('+1 month')->format('Y-m-d'), [], $actors['trainer'])[0];
$submissionOrder = $service->requests($actors['trainer'], false, 1, 50);
$submissionOrderIds = array_map('intval', array_column($submissionOrder['items'], 'occurrence_id'));
$submittedFirstIndex = array_search($submittedFirst, $submissionOrderIds, true);
$submittedSecondIndex = array_search($submittedSecond, $submissionOrderIds, true);
check(
    $submittedSecondIndex !== false && $submittedFirstIndex !== false
        && $submittedSecondIndex < $submittedFirstIndex,
    'Requests must be ordered by submission time, not by scheduled booking date.'
);
$oldRequestId = createBooking($today->modify('-4 months')->format('Y-m-d'), [], $actors['trainer'])[0];
$recentRequestId = createBooking($today->modify('-2 months')->format('Y-m-d'), [], $actors['trainer'])[0];
$pendingAssignment = $pdo->prepare(
    "SELECT id FROM booking_resource_assignments WHERE occurrence_id = :occurrence_id AND status = 'pending'"
);
$pendingAssignment->execute(['occurrence_id' => $oldRequestId]);
$oldAssignmentId = (int) $pendingAssignment->fetchColumn();
fails(
    fn() => $service->reject([$oldAssignmentId], $manager, " \t\u{00a0} "),
    'Bitte gib eine Begründung für die Ablehnung an'
);
$service->reject([$oldAssignmentId], $manager, 'Terminüberschneidung');
$reasonQuery = $pdo->prepare('SELECT rejection_reason FROM booking_occurrences WHERE id = :id');
$reasonQuery->execute(['id' => $oldRequestId]);
check($reasonQuery->fetchColumn() === 'Terminüberschneidung', 'Persist the required rejection reason.');
foreach ([false, true] as $includeClosed) {
    $listing = $service->requests($actors['trainer'], $includeClosed);
    $visibleIds = array_map('intval', array_column($listing['items'], 'occurrence_id'));
    check(
        in_array($recentRequestId, $visibleIds, true)
            && !in_array($oldRequestId, $visibleIds, true)
            && $listing['total'] === count($listing['items']),
        'Open/all request cutoff mismatch: ' . json_encode([
            'include_closed' => $includeClosed,
            'recent_id' => $recentRequestId,
            'old_id' => $oldRequestId,
            'visible_ids' => $visibleIds,
            'total' => $listing['total'],
            'page_count' => $listing['page_count'],
        ], JSON_THROW_ON_ERROR)
    );
}
$service->editPendingRequest($pendingRequestId, $requestEditData($pendingRequestId, $actors['trainer'], [
    'event_type' => 'match',
    'resource_ids' => [$resources['pitch'][1], $resources['pitch'][2]],
    'title' => 'Updated request',
    'team_name' => 'U15',
    'starts_at' => '2031-03-01T11:15',
    'ends_at' => '2031-03-01T12:45',
    'private_notes' => 'Updated request note',
]), $actors['trainer']);
$pendingRequestQuery = $pdo->prepare(
    'SELECT o.event_type, o.title, o.team_name, o.private_notes, o.starts_at, o.ends_at,
            o.status, a.status AS assignment_status, a.resource_id
     FROM booking_occurrences o
     JOIN booking_resource_assignments a ON a.occurrence_id = o.id
     WHERE o.id = :id ORDER BY a.resource_id'
);
$pendingRequestQuery->execute(['id' => $pendingRequestId]);
$updatedPendingRequests = $pendingRequestQuery->fetchAll();
check(
    count($updatedPendingRequests) === 2
        && (int) $updatedPendingRequests[0]['resource_id'] === $resources['pitch'][1]
        && (int) $updatedPendingRequests[1]['resource_id'] === $resources['pitch'][2]
        && $updatedPendingRequests[0]['event_type'] === 'match'
        && $updatedPendingRequests[0]['title'] === 'Updated request'
        && $updatedPendingRequests[0]['team_name'] === 'U15'
        && $updatedPendingRequests[0]['private_notes'] === 'Updated request note'
        && $updatedPendingRequests[0]['starts_at'] === Recurrence::toDatabase(
            Recurrence::parseLocalDateTime('2031-03-01T11:15')
        )
        && $updatedPendingRequests[0]['ends_at'] === Recurrence::toDatabase(
            Recurrence::parseLocalDateTime('2031-03-01T12:45')
        )
        && $updatedPendingRequests[0]['status'] === 'pending'
        && $updatedPendingRequests[0]['assignment_status'] === 'pending',
    'Editing a pending request must update all selected creation fields and preserve its pending state.'
);
fails(fn() => createBooking('2031-03-02', [
    'cabin_ids' => [$resources['cabin'][0]],
], $actors['trainer']), 'Kabinen belegen');

$seriesRequestId = createBooking('2031-03-02', [
    'frequency' => 'daily', 'limit_type' => 'count', 'count' => 2,
], $actors['trainer'])[0];
$service->editPendingRequest($seriesRequestId, $requestEditData(
    $seriesRequestId,
    $actors['trainer'],
    ['frequency' => 'daily', 'limit_type' => 'count', 'count' => 3],
    'all'
), $actors['trainer']);
$seriesRequest = $service->editablePendingRequest($seriesRequestId, $actors['trainer']);
check(
    $seriesRequest['scope_counts']['all'] === 3
        && $seriesRequest['recurrence_rule']['count'] === 3,
    'Editing a request must preserve the create form recurrence fields and update the pending series.'
);

$approvalSeriesIds = createBooking('2031-03-05', [
    'frequency' => 'daily', 'limit_type' => 'count', 'count' => 3,
], $actors['trainer']);
$service->editPendingRequest(
    $approvalSeriesIds[1],
    $requestEditData($approvalSeriesIds[1], $manager, ['cabin_ids' => [$resources['cabin'][0]]], 'future'),
    $manager,
    true
);
$seriesCabins = $pdo->prepare('SELECT COUNT(*) FROM booking_cabin_assignments WHERE occurrence_id = ? AND cabin_id = ?');
foreach ($approvalSeriesIds as $index => $seriesId) {
    $seriesCabins->execute([$seriesId, $resources['cabin'][0]]);
    check((int) $seriesCabins->fetchColumn() === ($index === 0 ? 0 : 1), 'Approval must attach cabins only to the selected series occurrences.');
}
$approvalSeriesGroup = $pdo->prepare('SELECT group_id FROM booking_occurrences WHERE id = ?');
$approvalSeriesGroup->execute([$approvalSeriesIds[1]]);
$approvedFutureGroupId = (int) $approvalSeriesGroup->fetchColumn();
$approvedFutureRows = array_values(array_filter(
    $service->requests($manager, true, 1, 50)['items'],
    static fn(array $request): bool => (int) $request['group_id'] === $approvedFutureGroupId
));
check(
    $approvedFutureRows !== []
        && array_reduce(
            $approvedFutureRows,
            static fn(bool $retained, array $request): bool => $retained
                && $request['requester_name'] === 'trainer',
            true
        ),
    'Approving future occurrences in a series must preserve the requester in the manager list.'
);

$approvalRequestId = createBooking('2031-03-03', [
    'resource_ids' => [$resources['pitch'][0], $resources['pitch'][1]],
], $actors['trainer'])[0];
$approvalRequester = $pdo->prepare(
    'SELECT g.requested_by FROM booking_occurrences o JOIN booking_groups g ON g.id = o.group_id WHERE o.id = ?'
);
$approvalRequester->execute([$approvalRequestId]);
$originalRequesterId = (int) $approvalRequester->fetchColumn();
$staffRequests = array_values(array_filter(
    $service->requests($manager)['items'],
    static fn(array $request): bool => (int) $request['occurrence_id'] === $approvalRequestId
));
check(
    count($staffRequests) === 1
        && count(explode(',', $staffRequests[0]['pending_assignment_ids'])) === 2,
    'Staff must see one grouped request with every pending place assignment.'
);
$pdo->prepare(
    "INSERT INTO users (username, first_name, last_name, role, status) VALUES ('request-list', 'Request', 'List', 'trainer', 'active')"
)->execute();
$paginationActor = ['id' => (int) $pdo->lastInsertId(), 'role' => 'trainer'];
$paginationRequests = [];
for ($day = 1; $day <= 21; $day++) {
    $paginationRequests[] = createBooking(sprintf('2032-06-%02d', $day), [], $paginationActor)[0];
}
$paginationEvent = $service->events('2032-06-01', '2032-06-02', 'pitch', false)[0];
$service->reject([$paginationEvent['assignment_id']], $manager, 'Nicht verfügbar');
$openListing = $service->requests($paginationActor, false, 1, 10);
$allListingPageOne = $service->requests($paginationActor, true, 1, 10);
$allListingPageTwo = $service->requests($paginationActor, true, 2, 10);
$allListingPageThree = $service->requests($paginationActor, true, 3, 10);
check(
    $openListing['total'] === 20 && count($openListing['items']) === 10 && $openListing['page_count'] === 2,
    'The default request list must include only open requests.'
);
check(
    $allListingPageOne['total'] === 21
        && count($allListingPageOne['items']) === 10
        && $allListingPageOne['page_count'] === 3
        && count($allListingPageTwo['items']) === 10
        && count($allListingPageThree['items']) === 1
        && in_array(
            $paginationRequests[0],
            array_map('intval', array_column($allListingPageThree['items'], 'occurrence_id')),
            true
        ),
    'The all-requests view must include closed requests and paginate ten entries at a time.'
);
fails(fn() => $service->editPendingRequest(
    $approvalRequestId,
    $requestEditData($approvalRequestId, $actors['trainer']),
    $actors['trainer'],
    true
), 'Rechte');
fails(fn() => $service->editPendingRequest(
    $approvalRequestId,
    $requestEditData($approvalRequestId, $manager, ['cabin_ids' => [$resources['cabin'][0]]]),
    $manager
), 'Kabinen belegen');
$approvalResult = $service->editPendingRequest(
    $approvalRequestId,
    $requestEditData($approvalRequestId, $manager, ['title' => 'Reviewed request', 'cabin_ids' => $resources['cabin']]),
    $manager,
    true
);
$approvedAssignments = $pdo->prepare(
    "SELECT COUNT(*) FROM booking_resource_assignments
     WHERE occurrence_id = :id AND status = 'confirmed'"
);
$approvedAssignments->execute(['id' => $approvalRequestId]);
check(
    $approvalResult['approved'] === 2 && (int) $approvedAssignments->fetchColumn() === 2,
    'Approving from the editor must approve every pending place assignment atomically with edits.'
);
$seriesCabins->execute([$approvalRequestId, $resources['cabin'][0]]);
check((int) $seriesCabins->fetchColumn() === 1, 'Approval from the editor must attach selected cabins.');

$directApprovalId = createBooking('2031-03-06', ['resource_ids' => $resources['pitch']], $actors['trainer'])[0];
$directAssignments = $pdo->prepare("SELECT id FROM booking_resource_assignments WHERE occurrence_id = ? ORDER BY id");
$directAssignments->execute([$directApprovalId]);
$directIds = array_map('intval', $directAssignments->fetchAll(PDO::FETCH_COLUMN));
fails(fn() => $service->approve([$directIds[0]], $actors['trainer'], [$resources['cabin'][0]]), 'Rechte');
fails(fn() => $service->approve([$directIds[0]], $manager, [$resources['pitch'][0]]), 'ungültig');
$service->approve([$directIds[1]], $manager, [$resources['cabin'][0]]);
$partlyApprovedCabins = array_values(array_filter(
    $service->events('2031-03-06', '2031-03-07', 'cabin', false),
    static fn(array $event): bool => $event['occurrence_id'] === $directApprovalId
));
check(
    count($partlyApprovedCabins) === 1 && $partlyApprovedCabins[0]['status'] === 'confirmed'
        && $partlyApprovedCabins[0]['assignment_id'] === $directIds[1],
    'A cabin attached to a partially approved booking must represent the confirmed assignment.'
);
$service->approve([$directIds[0]], $manager, [$resources['cabin'][0], $resources['cabin'][1]]);
foreach ($resources['cabin'] as $cabinId) {
    $seriesCabins->execute([$directApprovalId, $cabinId]);
    check((int) $seriesCabins->fetchColumn() === 1, 'Direct approval must preserve existing cabins without duplicates.');
}
fails(fn() => $service->approve([$directIds[0]], $manager, [$resources['cabin'][0]]), 'offenen Platzanfragen');
$approvalRequester->execute([$approvalRequestId]);
check(
    (int) $approvalRequester->fetchColumn() === $originalRequesterId
        && $originalRequesterId === (int) $actors['trainer']['id'],
    'Approving a request must preserve the original requester.'
);
$approvedRequestRow = array_values(array_filter(
    $service->requests($manager, true, 1, 50)['items'],
    static fn(array $request): bool => (int) $request['occurrence_id'] === $approvalRequestId
));
check(
    count($approvedRequestRow) === 1 && $approvedRequestRow[0]['requester_name'] === 'trainer',
    'The manager request list must continue to show the original requester after approval.'
);
$directBookingIds = [
    createBooking('2031-03-04', ['title' => 'Manager direct booking'], $manager)[0],
    createBooking('2031-03-04', ['title' => 'Admin direct booking'], $admin)[0],
];
$directBookingPlaceholders = implode(',', array_fill(0, count($directBookingIds), '?'));
$directBookingGroups = $pdo->prepare(
    "SELECT group_id FROM booking_occurrences WHERE id IN ({$directBookingPlaceholders})"
);
$directBookingGroups->execute($directBookingIds);
$directBookingGroupIds = array_map('intval', $directBookingGroups->fetchAll(PDO::FETCH_COLUMN));
$listedDirectBookings = array_values(array_filter(
    $service->requests($manager, true, 1, 50)['items'],
    static fn(array $request): bool => in_array((int) $request['group_id'], $directBookingGroupIds, true)
));
check(
    $listedDirectBookings === [],
    'Direct manager and admin bookings must not appear in the request list.'
);

fails(fn() => $service->create([
    'event_type' => 'bus', 'resource_mode' => 'bus', 'resource_ids' => [$resources['bus'][0]],
    'title' => 'Overnight bus', 'starts_at' => '2031-11-01T22:00',
    'ends_at' => '2031-11-02T01:00', 'frequency' => 'none',
], $admin), 'am selben Tag enden');

$id = createBooking('2031-04-01', ['cabin_ids' => [$resources['cabin'][0]]])[0];
$service->editOccurrence($id, editData($id, [
    'event_type' => 'match', 'resource_ids' => [$resources['pitch'][1], $resources['pitch'][2]],
    'cabin_ids' => [$resources['cabin'][1]], 'title' => 'Updated title', 'private_notes' => 'Updated note',
]), $manager);
$details = $service->editableOccurrence($id, $admin);
check($details['event_type'] === 'match' && $details['title'] === 'Updated title', 'Persist the category and title.');
check($details['resource_ids'] === [$resources['pitch'][1], $resources['pitch'][2]], 'Replace the selected pitches.');
check($details['cabin_ids'] === [$resources['cabin'][1]] && $details['private_notes'] === 'Updated note', 'Persist cabins and notes.');
$events = $service->events('2031-04-01', '2031-04-02', 'pitch', true);
check(count($events) === 2, 'Both booked pitches must appear in the calendar.');
foreach ($events as $event) {
    check($event['resource_names'] === 'pitch1, pitch2', 'Event details must list all confirmed resources.');
    check($event['private_notes'] === $details['private_notes'], 'Staff details must include the editable notes.');
    check($event['cabins'] === 'cabin1' && $event['recurrence_rule'] === null, 'Expose cabins and single-booking recurrence.');
}
$publicEvents = $service->events('2031-04-01', '2031-04-02', 'pitch', false);
foreach ($publicEvents as $event) {
    check($event['private_notes'] === null && !array_key_exists('driver_name', $event), 'Public event details must not leak private or removed fields.');
    check($event['resource_names'] === 'pitch1, pitch2', 'Public details must also list every booked pitch.');
}
foreach (['pitch', 'cabin'] as $calendarType) {
    $trainerEvents = $service->events('2031-04-01', '2031-04-02', $calendarType, false, true);
    check(count($trainerEvents) > 0, 'The authenticated calendar must include the booking.');
    foreach ($trainerEvents as $event) {
        check($event['private_notes'] === 'Updated note', 'Authenticated trainers must see internal notes.');
        check($event['cabin_ids'] === [], 'Staff-only cabin ID metadata must not be exposed to trainers.');
    }
}
$cabinEvents = $service->events('2031-04-01', '2031-04-02', 'cabin', false);
check(
    count($cabinEvents) === 1 && $cabinEvents[0]['occurrence_id'] === $id
        && $cabinEvents[0]['resource_id'] === $resources['cabin'][1]
        && $cabinEvents[0]['is_attached_cabin']
        && $cabinEvents[0]['resource_names'] === 'pitch1, pitch2'
        && $cabinEvents[0]['private_notes'] === null,
    'The cabin calendar must show each assigned cabin once per booking without private notes.'
);
$staffCabinEvents = $service->events('2031-04-01', '2031-04-02', 'cabin', true);
check($staffCabinEvents[0]['private_notes'] === 'Updated note', 'Staff cabin calendar details retain private notes.');
fails(fn() => $service->editableOccurrence($id, $actors['trainer']), 'Rechte');
fails(fn() => $service->editOccurrence($id, editData($id), $actors['trainer']), 'Rechte');

createBooking('2031-04-01', [
    'resource_ids' => [$resources['pitch'][0]], 'cabin_ids' => [$resources['cabin'][1]],
    'starts_at' => '2031-04-01T12:00', 'ends_at' => '2031-04-01T13:30',
]);
fails(fn() => createBooking('2031-04-02', [
    'resource_mode' => 'cabin', 'resource_ids' => [$resources['cabin'][0]],
]), 'nur zusammen mit einem Platz');
fails(fn() => $service->editOccurrence($id, editData($id, [
    'resource_mode' => 'cabin', 'resource_ids' => [$resources['cabin'][0]],
]), $manager), 'nur zusammen mit einem Platz');
$conflicting = editData($id, ['starts_at' => '2031-04-01T12:00', 'ends_at' => '2031-04-01T13:30']);
$service->editOccurrence($id, $conflicting, $manager);
check(
    str_contains($service->editableOccurrence($id, $admin)['start'], 'T12:00:00'),
    'Managers must be able to move bookings into occupied cabin times without an override.'
);
check((int) $pdo->query('SELECT COUNT(*) FROM booking_overrides')->fetchColumn() === 0, 'Double bookings no longer require conflict overrides.');

$series = createBooking('2031-05-01', ['frequency' => 'daily', 'limit_type' => 'count', 'count' => 3]);
foreach ($service->events('2031-05-01', '2031-05-04', 'pitch', false) as $event) {
    check(
        $event['recurrence_rule'] === $service->editableOccurrence($event['occurrence_id'], $admin)['recurrence_rule'],
        'Calendar details must expose the same recurrence rule as editing.'
    );
}
$service->editOccurrence($series[1], editData($series[1], ['title' => 'Only one']), $manager);
check($service->editableOccurrence($series[0], $admin)['title'] === 'Original title', 'Single edits must not change earlier dates.');
check($service->editableOccurrence($series[2], $admin)['title'] === 'Original title', 'Single edits must not change later dates.');
$service->editOccurrence($series[1], editData($series[1], [
    'title' => 'Future title', 'event_type' => 'external', 'resource_ids' => [$resources['pitch'][3]],
], 'future'), $manager);
check($service->editableOccurrence($series[0], $admin)['title'] === 'Original title', 'Future edits must preserve earlier dates.');
check($service->editableOccurrence($series[2], $admin)['title'] === 'Future title', 'Future edits must update later dates.');
check($service->editableOccurrence($series[1], $admin)['scope_counts']['all'] === 2, 'Split future occurrences into their own series.');
$result = $service->editOccurrence($series[1], editData($series[1], [
    'frequency' => 'weekly', 'weekdays' => ['MO'], 'limit_type' => 'count', 'count' => 4,
], 'all'), $manager);
check($result['updated'] === 4, 'Changing recurrence must generate the requested number of dates.');
check($service->editableOccurrence($series[1], $admin)['scope_counts']['all'] === 4, 'Expose the updated recurrence.');
$result = $service->editOccurrence($series[1], editData($series[1], ['frequency' => 'none'], 'all'), $manager);
check($result['updated'] === 1 && $service->editableOccurrence($series[1], $admin)['recurrence_rule'] === null, 'Stopping recurrence must remove unneeded dates in scope.');
fails(fn() => $service->editableOccurrence($series[2], $admin), 'nicht gefunden');

$weekly = createBooking('2035-01-01', [
    'frequency' => 'weekly', 'weekdays' => ['WE', 'FR'], 'limit_type' => 'count', 'count' => 3,
]);
$weeklyDetails = $service->editableOccurrence($weekly[0], $admin);
check($weeklyDetails['recurrence_rule']['weekdays'] === ['MO'], 'Weekly series must use the start date weekday.');
$weeklyQuery = $pdo->prepare('SELECT starts_at FROM booking_occurrences WHERE group_id = (SELECT group_id FROM booking_occurrences WHERE id = ?) ORDER BY occurrence_no');
$weeklyQuery->execute([$weekly[0]]);
foreach ($weeklyQuery->fetchAll(PDO::FETCH_COLUMN) as $date) {
    check(Recurrence::fromDatabase($date)->setTimezone(new DateTimeZone('Europe/Berlin'))->format('N') === '1',
        'Each generated weekly occurrence must fall on the start weekday.');
}
$legacyWeekly = createBooking('2035-02-05', [
    'frequency' => 'daily', 'limit_type' => 'count', 'count' => 3,
]);
$legacyRule = ['frequency' => 'weekly', 'interval' => 1, 'weekdays' => ['MO', 'WE'],
    'limit_type' => 'count', 'count' => 3, 'until' => null];
$pdo->prepare('UPDATE booking_groups SET recurrence_rule = ? WHERE id = (SELECT group_id FROM booking_occurrences WHERE id = ?)')
    ->execute([json_encode($legacyRule, JSON_THROW_ON_ERROR), $legacyWeekly[0]]);
$service->editOccurrence($legacyWeekly[0], editData($legacyWeekly[0], ['title' => 'Legacy unchanged'], 'all'), $manager);
check($service->editableOccurrence($legacyWeekly[0], $admin)['recurrence_rule']['weekdays'] === ['MO', 'WE'],
    'An unchanged legacy recurrence must retain multiple weekdays.');
$service->editOccurrence($legacyWeekly[0], editData($legacyWeekly[0], ['interval' => 2], 'all'), $manager);
check($service->editableOccurrence($legacyWeekly[0], $admin)['recurrence_rule']['weekdays'] === ['MO'],
    'Changing a legacy recurrence must use only the selected date weekday.');

$single = createBooking('2031-08-01')[0];
$result = $service->editOccurrence($single, editData($single, [
    'frequency' => 'daily', 'limit_type' => 'count', 'count' => 2,
]), $manager);
check($result['updated'] === 2, 'A single booking must be convertible into a series.');
$isolated = createBooking('2031-08-10', ['frequency' => 'daily', 'limit_type' => 'count', 'count' => 3]);
$service->editOccurrence($isolated[1], editData($isolated[1], ['frequency' => 'none']), $manager);
check($service->editableOccurrence($isolated[1], $admin)['recurrence_rule'] === null, 'A single occurrence can leave its series.');
check($service->editableOccurrence($isolated[0], $admin)['scope_counts']['all'] === 2, 'Other occurrences must remain in their original series.');

$until = createBooking('2031-08-20', [
    'frequency' => 'daily', 'limit_type' => 'until', 'until' => '2031-08-22',
]);
$service->editOccurrence($until[1], editData($until[1], ['title' => 'Until future'], 'future'), $manager);
check($service->editableOccurrence($until[0], $admin)['recurrence_rule']['until'] === '2031-08-20', 'Splitting an until-based series must bound the old series correctly.');
check($service->editableOccurrence($until[1], $admin)['recurrence_rule']['until'] === '2031-08-22', 'The future series retains its end date.');

$shifted = createBooking('2031-09-01', ['frequency' => 'daily', 'limit_type' => 'count', 'count' => 3]);
$service->editOccurrence($shifted[0], editData($shifted[0], [
    'starts_at' => '2031-09-02T10:00', 'ends_at' => '2031-09-02T11:30',
], 'all'), $manager);
check(str_starts_with($service->editableOccurrence($shifted[2], $admin)['start'], '2031-09-04'), 'Moved dates must not collide with their own old positions.');
$history = createBooking('2031-10-01', ['frequency' => 'daily', 'limit_type' => 'count', 'count' => 3]);
$pdo->prepare("UPDATE booking_occurrences SET status = 'rejected' WHERE id = :id")->execute(['id' => $history[2]]);
$pdo->prepare("UPDATE booking_resource_assignments SET status = 'rejected' WHERE occurrence_id = :id")->execute(['id' => $history[2]]);
$service->editOccurrence($history[0], editData($history[0], ['title' => 'Confirmed only'], 'all'), $manager);
$query = $pdo->prepare('SELECT title, status FROM booking_occurrences WHERE id = :id');
$query->execute(['id' => $history[2]]);
check($query->fetch() === ['title' => 'Original title', 'status' => 'rejected'], 'Series edits must not resurrect or overwrite rejected history.');

foreach (['rejected', 'deleted', 'skipped'] as $status) {
    $pending = createBooking('2033-03-01', [], $actors['trainer'])[0];
    $event = $service->events('2033-03-01', '2033-03-02', 'pitch', false)[0];
    if ($status === 'rejected') {
        $service->reject([$event['assignment_id']], $manager, 'Nicht verfügbar');
    } elseif ($status === 'deleted') {
        $assignmentId = (int) $event['assignment_id'];
        $group = $pdo->prepare('SELECT group_id FROM booking_occurrences WHERE id = :id');
        $group->execute(['id' => $pending]);
        $groupId = (int) $group->fetchColumn();
        $pdo->prepare(
            'INSERT INTO booking_overrides (actor_id, new_assignment_id, conflicting_assignment_id)
             VALUES (:actor_id, :new_assignment_id, :conflicting_assignment_id)'
        )->execute([
            'actor_id' => $manager['id'],
            'new_assignment_id' => $assignmentId,
            'conflicting_assignment_id' => $assignmentId,
        ]);
        $service->deletePendingRequests([$event['assignment_id']], $actors['trainer']);
        $deletedLinks = $pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM booking_resource_assignments WHERE id = :assignment_id) AS assignments,
                (SELECT COUNT(*) FROM booking_occurrences WHERE id = :occurrence_id) AS occurrences,
                (SELECT COUNT(*) FROM booking_groups WHERE id = :group_id) AS booking_groups,
                (SELECT COUNT(*) FROM booking_overrides
                 WHERE new_assignment_id = :override_new_id
                    OR conflicting_assignment_id = :override_conflict_id) AS overrides'
        );
        $deletedLinks->execute([
            'assignment_id' => $assignmentId,
            'occurrence_id' => $pending,
            'group_id' => $groupId,
            'override_new_id' => $assignmentId,
            'override_conflict_id' => $assignmentId,
        ]);
        check(
            array_map('intval', $deletedLinks->fetch()) === [
                'assignments' => 0,
                'occurrences' => 0,
                'booking_groups' => 0,
                'overrides' => 0,
            ],
            'Delete all persisted data for an empty request.'
        );
    } else {
        $pdo->prepare("UPDATE booking_resource_assignments SET status = 'skipped' WHERE occurrence_id = :id")
            ->execute(['id' => $pending]);
    }
    check($service->events('2033-03-01', '2033-03-02', 'pitch', false) === [], "$status: Inactive requests must stay hidden.");
    if ($status === 'deleted') {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM booking_occurrences WHERE id = :id');
        $statement->execute(['id' => $pending]);
        check((int) $statement->fetchColumn() === 0, 'Deleted requests must remove their occurrence.');
    }
}

$bus = createBooking('2031-11-01', [
    'event_type' => 'bus', 'resource_mode' => 'bus', 'resource_ids' => [$resources['bus'][0]],
])[0];
$service->editOccurrence($bus, editData($bus, [
    'resource_ids' => [$resources['bus'][1]], 'destination' => 'New destination',
    'purpose' => 'New purpose', 'driver_name' => 'New driver', 'private_notes' => 'Bus note',
    'ends_at' => '2031-11-01T12:00',
]), $manager);
$details = $service->editableOccurrence($bus, $admin);
check($details['resource_ids'] === [$resources['bus'][1]], 'Persist changed vehicle.');
check($details['private_notes'] === 'Bus note', 'Persist internal vehicle notes.');
foreach (['destination', 'purpose', 'driver_name'] as $field) {
    check(!array_key_exists($field, $details), "Removed vehicle field $field must not appear in edit details.");
}
fails(fn() => $service->editOccurrence($bus, editData($bus, ['frequency' => 'daily', 'limit_type' => 'count', 'count' => 2]), $manager), 'nicht als Serie');
fails(fn() => $service->editOccurrence($bus, editData($bus, ['title' => '  ']), $manager), 'Mannschaft oder einen Hinweis');
fails(fn() => $service->editOccurrence($bus, editData($bus, ['resource_ids' => [$resources['pitch'][0]]]), $manager), 'Buchungsart');
$pdo->prepare('UPDATE resources SET is_active = 0 WHERE id = :id')->execute(['id' => $resources['bus'][1]]);
fails(fn() => $service->editOccurrence($bus, editData($bus), $manager), 'deaktiviert');

$public = $service->events('2031-04-01', '2031-04-02', 'pitch', false);
foreach ($public as $event) {
    check($event['private_notes'] === null && !array_key_exists('driver_name', $event), 'Keep private and removed editing details out of the public calendar.');
}
check(
    (int) $pdo->query(
        'SELECT COUNT(*) FROM booking_groups g LEFT JOIN booking_occurrences o ON o.group_id = g.id WHERE o.id IS NULL'
    )->fetchColumn() === 0,
    'Splitting single bookings must not leave empty booking groups behind.'
);

foreach (['training', 'match', 'external', 'bus'] as $eventType) {
    $isBus = $eventType === 'bus';
    $data = [
        'event_type' => $eventType, 'resource_mode' => $isBus ? 'bus' : 'pitch',
        'resource_ids' => [$resources[$isBus ? 'bus' : 'pitch'][0]],
        'cabin_ids' => $isBus ? [] : [$resources['cabin'][0]],
        'title' => 'Parallel ' . $eventType, 'driver_name' => $isBus ? 'Private driver' : null,
    ];
    $first = createBooking('2033-01-01', $data)[0];
    $second = createBooking('2033-01-01', $data, $manager)[0];
    check($first !== $second, "$eventType: Identical resource and cabin bookings must be accepted.");
    $service->editOccurrence($second, editData($second, ['starts_at' => '2033-01-01T10:15']), $manager);
    if ($eventType === 'external') continue;

    $requestData = array_replace($data, ['cabin_ids' => []]);
    $pending = createBooking('2033-01-01', $requestData, $actors['trainer'])[0];
    $type = $isBus ? 'bus' : 'pitch';
    $public = $service->events('2033-01-01', '2033-01-02', $type, false);
    $request = array_values(array_filter($public, fn(array $event): bool => $event['occurrence_id'] === $pending));
    check(count($request) === 1 && $request[0]['status'] === 'pending', "$eventType: Open requests must be publicly visible.");
    check($request[0]['event_type'] === $eventType, 'Requests must preserve their category.');
    check(!array_key_exists('driver_name', $request[0]) && $request[0]['private_notes'] === null, 'Public requests must not expose private or removed details.');
    check($request[0]['resource_names'] !== null, 'Pending resource names must appear in the details.');
    fails(fn() => $service->approve([$request[0]['assignment_id']], $actors['trainer']), 'Rechte');
    fails(fn() => $service->cancel($request[0]['assignment_id'], $manager), 'nicht gefunden');
    $result = $service->approve([$request[0]['assignment_id']], $manager);
    check($result['approved'] === 1, 'Managers must approve overlapping requests without skipping them.');
    $approved = array_values(array_filter(
        $service->events('2033-01-01', '2033-01-02', $type, false),
        fn(array $event): bool => $event['occurrence_id'] === $pending
    ));
    check(count($approved) === 1 && $approved[0]['status'] === 'confirmed', 'Approved requests remain visible with confirmed status.');
    $service->cancel($approved[0]['assignment_id'], $manager);
    check(
        count(array_filter(
            $service->events('2033-01-01', '2033-01-02', $type, false),
            fn(array $event): bool => $event['occurrence_id'] === $pending
        )) === 0,
        'Cancelled bookings must disappear from the calendar.'
    );
}

$pending = createBooking('2033-02-01', [
    'resource_ids' => [$resources['pitch'][0], $resources['pitch'][1]],
], $actors['trainer'])[0];
$public = $service->events('2033-02-01', '2033-02-02', 'pitch', false);
check(count($public) === 2 && $public[0]['resource_names'] === 'pitch0, pitch1', 'Show all requested resources before approval.');
$service->approve([$public[0]['assignment_id']], $manager);
$partial = $service->events('2033-02-01', '2033-02-02', 'pitch', false);
check(
    count($partial) === 2 && array_column($partial, 'status') === ['confirmed', 'pending'],
    'Partially approved bookings must show both the confirmed and pending resource assignments.'
);
check($partial[0]['occurrence_status'] === 'pending', 'Expose aggregate status to guard editing partially approved bookings.');
$service->reject([$partial[1]['assignment_id']], $manager, 'Ressource bereits belegt');
$public = $service->events('2033-02-01', '2033-02-02', 'pitch', false);
check(count($public) === 1 && $public[0]['resource_names'] === 'pitch0', 'Rejected resources must disappear from public details.');
$service->cancel($public[0]['assignment_id'], $manager);

$series = createBooking('2033-04-01', ['frequency' => 'daily', 'limit_type' => 'count', 'count' => 3]);
createBooking('2033-04-01', ['frequency' => 'daily', 'limit_type' => 'count', 'count' => 3], $manager);
$service->editOccurrence($series[0], editData($series[0], ['starts_at' => '2033-04-01T10:15'], 'all'), $manager);
check(count($service->events('2033-04-01', '2033-04-04', 'pitch', false)) === 6, 'Overlapping series must be accepted when creating and editing.');

$cancelSeries = createBooking('2033-05-01', [
    'resource_ids' => [$resources['pitch'][0], $resources['pitch'][1]],
    'cabin_ids' => [$resources['cabin'][0]],
    'frequency' => 'daily', 'limit_type' => 'count', 'count' => 4,
]);

$approvedRequestSeries = createBooking('2033-03-01', [
    'frequency' => 'daily', 'limit_type' => 'count', 'count' => 3,
    'title' => 'Approved recurring request',
], $actors['trainer']);
$seriesPlaceholders = implode(',', array_fill(0, count($approvedRequestSeries), '?'));
$pendingSeriesAssignments = $pdo->prepare(
    "SELECT id FROM booking_resource_assignments
     WHERE occurrence_id IN ({$seriesPlaceholders}) AND status = 'pending'"
);
$pendingSeriesAssignments->execute($approvedRequestSeries);
$service->approve(array_map('intval', $pendingSeriesAssignments->fetchAll(PDO::FETCH_COLUMN)), $manager);
$seriesGroupQuery = $pdo->prepare('SELECT group_id FROM booking_occurrences WHERE id = ?');
$seriesGroupQuery->execute([$approvedRequestSeries[0]]);
$approvedRequestGroupId = (int) $seriesGroupQuery->fetchColumn();
$managerSeriesRows = array_values(array_filter(
    $service->requests($manager, true, 1, 50)['items'],
    static fn(array $request): bool => (int) $request['group_id'] === $approvedRequestGroupId
));
check(
    count($managerSeriesRows) === 1
        && (int) $managerSeriesRows[0]['is_series_summary'] === 1
        && (int) $managerSeriesRows[0]['occurrence_count'] === 3
        && $managerSeriesRows[0]['assignment_status'] === 'confirmed'
        && $managerSeriesRows[0]['requester_name'] === 'trainer',
    'Managers must see approved recurring requests as one series entry.'
);
$trainerSeriesRows = array_values(array_filter(
    $service->requests($actors['trainer'], true, 1, 50)['items'],
    static fn(array $request): bool => (int) $request['group_id'] === $approvedRequestGroupId
));
check(
    count($trainerSeriesRows) === 1 && (int) $trainerSeriesRows[0]['occurrence_count'] === 3,
    'Trainers see approved recurring requests as one series entry.'
);

$seriesEvents = $service->events('2033-05-01', '2033-05-05', 'pitch', false);
$targetEvent = array_values(array_filter(
    $seriesEvents,
    fn(array $event): bool => $event['occurrence_id'] === $cancelSeries[1]
        && $event['resource_id'] === $resources['pitch'][0]
))[0];
$cancelResult = $service->cancel($targetEvent['assignment_id'], $manager, 'future');
check($cancelResult['cancelled'] === 3, 'Future cancellation must remove the selected resource from this and later occurrences.');
$seriesEvents = $service->events('2033-05-01', '2033-05-05', 'pitch', false);
check(
    count(array_filter($seriesEvents, fn(array $event): bool => $event['resource_id'] === $resources['pitch'][0])) === 1
        && count(array_filter($seriesEvents, fn(array $event): bool => $event['resource_id'] === $resources['pitch'][1])) === 4,
    'Future cancellation must preserve the earlier occurrence and other resource assignments.'
);
$service->cancelCabin($cancelSeries[1], $resources['cabin'][0], $manager, 'future');
$cabinAssignments = $pdo->prepare(
    'SELECT COUNT(*) FROM booking_cabin_assignments WHERE cabin_id = :cabin_id AND occurrence_id IN (:first, :second, :third, :fourth)'
);
$cabinAssignments->execute([
    'cabin_id' => $resources['cabin'][0],
    'first' => $cancelSeries[0], 'second' => $cancelSeries[1],
    'third' => $cancelSeries[2], 'fourth' => $cancelSeries[3],
]);
check((int) $cabinAssignments->fetchColumn() === 1, 'Future cabin cancellation must preserve only earlier cabin assignments.');

$legacy = createBooking('2032-04-01')[0];
$pdo->prepare('UPDATE booking_resource_assignments SET resource_id = :cabin WHERE occurrence_id = :id')
    ->execute(['cabin' => $resources['cabin'][0], 'id' => $legacy]);
fails(fn() => $service->editableOccurrence($legacy, $admin), 'nur zusammen mit einem Platz');
$periodSeries = createBooking('2038-03-27', [
    'resource_ids' => [$resources['pitch'][0], $resources['pitch'][1]],
    'cabin_ids' => [$resources['cabin'][0]],
    'frequency' => 'daily', 'limit_type' => 'count', 'count' => 4,
]);
$periodRequest = createBooking('2038-03-28', [
    'resource_ids' => [$resources['pitch'][2], $resources['pitch'][3]],
], $actors['trainer'])[0];
$pendingIds = $service->pendingSeriesAssignmentIds($periodRequest, $manager);
$service->approve([$pendingIds[0]], $manager);
$periodBus = createBooking('2038-03-28', [
    'event_type' => 'bus', 'resource_mode' => 'bus', 'resource_ids' => [$resources['bus'][0]],
])[0];
fails(fn() => $service->deleteInPeriod('2038-03-28', '2038-03-29', 'all', $actors['trainer']), 'Rechte');
fails(fn() => $service->deleteInPeriod('2038-03-28', '2038-03-29', 'all', $manager), 'Rechte');
fails(fn() => $service->deleteInPeriod('2038-03-29', '2038-03-28', 'all', $admin), 'Bis-Datum');
fails(fn() => $service->deleteInPeriod('2038-02-30', '2038-03-28', 'all', $admin), 'gültig');
fails(fn() => $service->deleteInPeriod('2038-03-28', '2038-03-29', 'invalid', $admin), 'Auswahl');
$deleted = $service->deleteInPeriod('2038-03-28', '2038-03-28', 'pending', $admin);
check($deleted['affected'] === 1 && $deleted['deleted'] === 0 && $deleted['assignments'] === 1,
    'Pending-only deletion must preserve confirmed resources of a mixed occurrence.');
check($service->editableOccurrence($periodRequest, $admin)['resource_ids'] === [$resources['pitch'][2]],
    'The confirmed place must remain after deleting pending places.');
$deleted = $service->deleteInPeriod('2038-03-28', '2038-03-29', 'confirmed', $admin);
check($deleted['affected'] === 4 && $deleted['deleted'] === 4,
    'Confirmed period deletion must include both dates and vehicles.');
check($service->editableOccurrence($periodSeries[0], $admin)['cabin_ids'] === [$resources['cabin'][0]]
    && $service->editableOccurrence($periodSeries[3], $admin)['cabin_ids'] === [$resources['cabin'][0]],
    'Period deletion must preserve outside series occurrences and their cabins.');
$closed = createBooking('2038-04-01', [], $actors['trainer'])[0];
$service->reject($service->pendingSeriesAssignmentIds($closed, $manager), $manager, 'Test');
$open = createBooking('2038-04-01', ['resource_ids' => [$resources['pitch'][1]]], $actors['trainer'])[0];
check($service->deleteInPeriod('2038-04-01', '2038-04-01', 'all', $admin)['deleted'] === 2,
    'All-period deletion must include open and rejected requests.');
check($service->deleteInPeriod('2038-04-01', '2038-04-01', 'all', $admin)['affected'] === 0,
    'Repeated period deletion must report no matches.');
$service->deleteInPeriod('2038-03-27', '2038-03-30', 'all', $admin);
fails(fn() => $service->editableOccurrence($periodSeries[0], $admin), 'nicht gefunden');
$boundaryBefore = createBooking('2038-03-27', [
    'starts_at' => '2038-03-27T23:58', 'ends_at' => '2038-03-27T23:59',
])[0];
$boundaryStart = createBooking('2038-03-28', [
    'starts_at' => '2038-03-28T00:00', 'ends_at' => '2038-03-28T01:00',
    'resource_ids' => [$resources['pitch'][1]], 'cabin_ids' => [$resources['cabin'][1]],
])[0];
$boundaryLast = createBooking('2038-03-28', [
    'starts_at' => '2038-03-28T23:58', 'ends_at' => '2038-03-28T23:59',
])[0];
$boundaryAfter = createBooking('2038-03-29', [
    'starts_at' => '2038-03-29T00:00', 'ends_at' => '2038-03-29T01:00',
    'resource_ids' => [$resources['pitch'][1]],
])[0];
$boundaryUpdate = $pdo->prepare('UPDATE booking_occurrences SET starts_at = ?, ends_at = ? WHERE id = ?');
foreach ([[$boundaryBefore, '2038-03-27', '2038-03-28'], [$boundaryLast, '2038-03-28', '2038-03-29']] as [$id, $day, $nextDay]) {
    $boundaryUpdate->execute([
        Recurrence::toDatabase(Recurrence::parseLocalDateTime($day . 'T23:59')),
        Recurrence::toDatabase(Recurrence::parseLocalDateTime($nextDay . 'T00:01')),
        $id,
    ]);
}
$assignmentFor = $pdo->prepare('SELECT id FROM booking_resource_assignments WHERE occurrence_id = ?');
$assignmentFor->execute([$boundaryBefore]);
$outsideAssignment = (int) $assignmentFor->fetchColumn();
$assignmentFor->execute([$boundaryStart]);
$insideAssignment = (int) $assignmentFor->fetchColumn();
$pdo->prepare(
    'INSERT INTO booking_overrides (new_assignment_id, conflicting_assignment_id, actor_id) VALUES (?, ?, ?)'
)->execute([$insideAssignment, $outsideAssignment, $admin['id']]);
$overrideId = (int) $pdo->lastInsertId();
check($service->deleteInPeriod('2038-03-28', '2038-03-28', 'all', $admin)['deleted'] === 2,
    'The local DST day must include midnight and 23:59, not adjacent days.');
check($service->editableOccurrence($boundaryBefore, $admin)['occurrence_id'] === $boundaryBefore
    && $service->editableOccurrence($boundaryAfter, $admin)['occurrence_id'] === $boundaryAfter,
    'An overlapping term starting before the range and the next midnight must survive.');
$overrideCheck = $pdo->prepare('SELECT COUNT(*) FROM booking_overrides WHERE id = ?');
$overrideCheck->execute([$overrideId]);
check((int) $overrideCheck->fetchColumn() === 0, 'Remove override references, including references to surviving assignments.');
$cabinCheck = $pdo->prepare('SELECT COUNT(*) FROM booking_cabin_assignments WHERE occurrence_id = ?');
$cabinCheck->execute([$boundaryStart]);
check((int) $cabinCheck->fetchColumn() === 0, 'Remove cabins of deleted occurrences.');
check((int) $pdo->query(
    'SELECT COUNT(*) FROM booking_groups g WHERE NOT EXISTS (SELECT 1 FROM booking_occurrences o WHERE o.group_id = g.id)'
)->fetchColumn() === 0, 'Period deletion must not leave empty booking groups.');
echo "Full booking editing checks passed.\n";
