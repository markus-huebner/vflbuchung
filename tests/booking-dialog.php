<?php
declare(strict_types=1);

require dirname(__DIR__) . '/www/vendor/autoload.php';

use App\Views;
use App\BookingService;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function checkDateTimeFields(DOMXPath $xpath, string $form): void
{
    check(
        $xpath->query($form . '//input[@type="datetime-local"]')->length === 0,
        'Booking dates and times must be selected separately.'
    );
    $dates = $xpath->query($form . '//input[@data-booking-date]');
    check(
        $dates->length === 1
            && $dates->item(0)->getAttribute('type') === 'date'
            && $dates->item(0)->hasAttribute('required'),
        'Booking forms must have exactly one required date field.'
    );
    $dateId = $dates->item(0)->getAttribute('id');
    check($xpath->query($form . '//label[@for="' . $dateId . '"]')->length === 1, "$dateId: Missing date label.");
    foreach (['start', 'end'] as $part) {
        $inputs = $xpath->query($form . '//input[@data-booking-time="' . $part . '"]');
        check(
            $inputs->length === 1
                && $inputs->item(0)->getAttribute('type') === 'text'
                && $inputs->item(0)->hasAttribute('required'),
            "$part: Missing required time input."
        );
        $id = $inputs->item(0)->getAttribute('id');
        check(
            $xpath->evaluate('string(' . $form . '//label[@for="' . $id . '"])') === ($part === 'start' ? 'Beginn' : 'Ende'),
            "$id: Time field must be labelled " . ($part === 'start' ? 'Beginn' : 'Ende') . '.'
        );
        check(
            $inputs->item(0)->getAttribute('pattern') === '(?:[01][0-9]|2[0-3]):[0-5][0-9]'
                && $inputs->item(0)->getAttribute('maxlength') === '5'
                && $inputs->item(0)->getAttribute('placeholder') === 'HH:MM'
                && $inputs->item(0)->getAttribute('inputmode') === 'text'
                && !$inputs->item(0)->hasAttribute('title'),
            "$part: Allow keyboard entry of any valid minute in 24-hour format."
        );
    }
    check(
        $xpath->query($form . '//div[@data-time-picker]')->length === 2
            && $xpath->query($form . '//button[@data-time-picker-trigger][@aria-haspopup="menu"]')->length === 2
            && $xpath->query($form . '//div[contains(@class, "time-picker-menu")][@role="menu"][@hidden]')->length === 2,
        'Provide a quarter-hour suggestion menu for both booking time fields.'
    );
    check(
        $xpath->query($form . '//text()[contains(., "minutengenau")]')->length === 0,
        'Do not show the removed time-format hint.'
    );
    foreach (['starts_at', 'ends_at'] as $name) {
        check(
            $xpath->query($form . '//input[@type="hidden" and @name="' . $name . '"]')->length === 1,
            "$name: Preserve the booking API field."
        );
    }
}

function checkRequiredTitle(DOMXPath $xpath, string $form): void
{
    $fields = $xpath->query($form . '//textarea[@name="combined_title"]');
    check($fields->length === 1, 'The booking form must offer one multi-line title field.');
    $field = $fields->item(0);
    check($field instanceof DOMElement, 'The title field must be a textarea element.');
    check($field->hasAttribute('required'), 'The team / note field must be required.');
    check(
        $field->hasAttribute('data-required-non-whitespace'),
        'Whitespace-only team / note values must be rejected.'
    );
    check((int) $field->getAttribute('rows') >= 2, 'The team / note field must be multi-line.');
    check(
        $xpath->evaluate('string(' . $form . '//label[@for="' . $field->getAttribute('id') . '"])') === 'Mannschaft / Hinweis',
        'The title field must be labelled Mannschaft / Hinweis.'
    );
}

foreach (['trainer', 'manager', 'admin'] as $role) {
    $html = Views::calendar(['role' => $role], 'test-csrf', []);
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $form = '//*[@id="booking-form"]';

    check(
        $xpath->evaluate('string(' . $form . '//*[@id="booking-dialog-title"])') === 'Platzbelegung anlegen',
        "$role: Incorrect pitch booking dialog title."
    );
    check(
        $xpath->query($form . '//*[@id="booking-selection"]')->length === 0,
        "$role: The obsolete booking selection is still displayed."
    );
    check(
        $xpath->query($form . '//*[@name="opponent" or @name="match_venue"]')->length === 0,
        "$role: Match-specific fields are still offered."
    );
    check(
        $xpath->query($form . '//*[@data-event-type-field and @data-event-type-field!="bus"]')->length === 0,
        "$role: Pitch categories must offer the same fields."
    );
    check(
        $xpath->query($form . '//select[@name="event_type"]')->length === 1
            && $xpath->query($form . '//input[@name="event_type"]')->length === 0,
        "$role: The selected category must be submitted without a conflicting hidden input."
    );
    $categories = [];
    foreach ($xpath->query($form . '//select[@name="event_type"]/option[not(@hidden)]') as $option) {
        $categories[] = $option->getAttribute('value');
    }
    check(
        $categories === ($role === 'trainer' ? ['training', 'match'] : ['training', 'match', 'external']),
        "$role: Incorrect pitch booking categories."
    );
    check(
        $xpath->evaluate('string(' . $form . '//*[@id="event-title"]/@maxlength)') === '180',
        "$role: The title input must respect the database limit."
    );
    foreach (['destination', 'purpose', 'driver_name'] as $field) {
        check(
            $xpath->query($form . '//input[@type!="hidden"][@name="' . $field . '"]')->length === 0,
            "$role: The removed vehicle field $field must not be visible."
        );
    }
    check(
        $xpath->query($form . '//div[contains(concat(" ", normalize-space(@class), " "), " col-12 ")][.//textarea[@name="combined_title"]]')->length === 1,
        "$role: The Mannschaft / Hinweis field must span the booking dialog."
    );
    checkDateTimeFields($xpath, $form);
    checkRequiredTitle($xpath, $form);
    check(
        $xpath->query('//*[@id="booking-dialog"]//*[@data-booking-error][@aria-live]')->length === 1,
        "$role: Show booking feedback inside the booking dialog."
    );
    check($xpath->query('//*[@id="booking-form"]')->length === 1, 'Use exactly one create/edit booking form.');
    check($xpath->query('//*[@name="force_conflicts"]')->length === 0, 'Double bookings must not require an override checkbox.');
    check($xpath->query('//*[@id="edit-booking-dialog"]')->length === 0, 'Remove the separate edit dialog.');
    check(
        $xpath->query($form . '//input[@type="hidden" and @name="scope"]')->length === 1,
        'Keep the series editing scope.'
    );
    check($xpath->query($form . '//input[@name="occurrence_id"]')->length === 1, 'The shared form needs the edited ID.');
    check($xpath->query('//*[@id="calendar-date-picker"]')->length === 1, 'The calendar needs direct date selection.');
    check($xpath->query('//section[contains(@class, "calendar-toolbar")]//select[@id="calendar-resource-select"]')->length === 1, 'Choose the calendar in the header.');
    check($xpath->query('//*[@data-resource-type]')->length === 0, 'Replace the old calendar tabs.');
    check($xpath->query('//*[@id="booking-create-menu"]')->length === 1, "$role: Render exactly one booking menu.");
    $staff = in_array($role, ['admin', 'manager'], true);
    if ($staff) {
        check(
            $xpath->query('//*[@id="event-dialog"]//form[@action="/?action=booking-reject"][@data-confirm-action="reject"]')->length === 1,
            "$role: Confirm rejection from the event dialog."
        );
        check(
            $xpath->query('//*[@id="event-dialog"]//form[@data-cancel-form][@data-confirm-action="delete"]')->length === 1
                && $xpath->query('//*[@id="event-dialog"]//form[@data-cancel-cabin-form][@data-confirm-action="delete"]')->length === 1,
            "$role: Confirm deletion of bookings and cabin assignments."
        );
    }
    check(
        $staff
            ? $xpath->query('//section[contains(@class, "calendar-toolbar")]//*[@id="booking-create-menu"]/following-sibling::select[@id="calendar-resource-select"]')->length === 1
            : $xpath->query('//aside[@id="request-dashboard"]//*[@id="booking-create-menu"]')->length === 1
                && $xpath->query('//section[contains(@class, "calendar-toolbar")]//*[@id="booking-create-menu"]')->length === 0,
        "$role: Show booking creation in the toolbar only for staff and in the request list for trainers."
    );
    check(
        $xpath->query('//*[@id="booking-create-menu"]//*[@data-booking-option and @data-resource-mode="pitch"]')->length === 1
            && $xpath->query('//*[@id="booking-create-menu"]//*[@data-booking-option and @data-resource-mode="bus"]')->length === 1,
        "$role: Offer pitch and vehicle requests."
    );
    check(
        $xpath->query('//*[@id="booking-create-menu"]//*[@data-event-type="external"]')->length === 0,
        "$role: Do not offer external bookings in the creation menu."
    );
    check($xpath->query('//select[@id="calendar-resource-select"]/option')->length === 2, 'Offer only pitch and vehicle calendars.');
    check($xpath->query('//*[@data-resource-type="cabin" or @data-calendar-view="cabin" or @data-resource-mode="cabin"]')->length === 0, 'Cabins must not have their own booking or calendar.');
    foreach (['calendar-prev', 'calendar-next', 'calendar-today', 'calendar-date-picker'] as $control) {
        check($xpath->query('//div[contains(@class, "calendar-navigation")]/*[@id="' . $control . '"]')->length === 1, 'Group date navigation controls together.');
    }
}

$request = [
    'assignment_id' => 42,
    'occurrence_id' => 9,
    'pending_assignment_ids' => '42,43',
    'assignment_status' => 'pending',
    'event_type' => 'training',
    'resource_type' => 'pitch',
    'resource_names' => 'Test pitch, Test pitch 2',
    'title' => 'Test title',
    'team_name' => null,
    'destination' => null,
    'purpose' => null,
    'cabins' => null,
    'starts_at' => '2026-10-24 13:07:00',
    'ends_at' => '2026-10-24 15:03:00',
];
$document = new DOMDocument();
$document->loadHTML(
    '<?xml encoding="UTF-8">' . Views::dashboard(['role' => 'trainer'], [$request], 'test-csrf'),
    LIBXML_NOERROR | LIBXML_NOWARNING
);
$xpath = new DOMXPath($document);
check(
    $xpath->query('//form[contains(@class, "request-edit-form")]')->length === 0,
    'Do not render a separate inline request-edit form.'
);
$editButton = $xpath->query('//button[@data-request-edit]');
check($editButton->length === 1, 'Offer request editing through a dialog trigger.');
check($editButton->item(0)->textContent === 'Ändern', 'The request action must be labelled Ändern.');
check($editButton->item(0)->getAttribute('data-occurrence-id') === '9', 'The request dialog must identify its occurrence.');
$jumpButton = $xpath->query('//button[@data-dashboard-jump]');
check(
    $jumpButton->length === 1
        && $jumpButton->item(0)->getAttribute('data-occurrence-id') === '9',
    'Request calendar navigation must identify the complete occurrence.'
);
check(
    $xpath->query('//form[@action="/?action=booking-delete"][@data-confirm-action="delete"]//input[@name="assignment_ids[]"]')->length === 2,
    'Delete all pending place assignments together.'
);
check(
    $xpath->query('//form[@action="/?action=booking-delete"]//button[@type="submit"]')->item(0)->textContent === 'Löschen',
    'Label permanent request removal as Löschen.'
);
$allRequestsDocument = new DOMDocument();
$allRequestsDocument->loadHTML(
    '<?xml encoding="UTF-8">' . Views::dashboard(['role' => 'trainer'], [$request], 'test-csrf', '', true, true, 2, 21, 3, 10),
    LIBXML_NOERROR | LIBXML_NOWARNING
);
$allRequestsXPath = new DOMXPath($allRequestsDocument);
check(
    $allRequestsXPath->query('//a[contains(@href, "requests_page=1") and normalize-space(.)="Zurück"]')->length === 1,
    'Show pagination for request lists larger than one page.'
);
check(
    $allRequestsXPath->query('//a[contains(@href, "requests_page=3") and normalize-space(.)="Weiter"]')->length === 1,
    'Show the next ten-item request page.'
);
check(
    $allRequestsXPath->query('//a[contains(@href, "requests_page=4")]')->length === 0,
    'Do not render a page link beyond the last request page.'
);
$trainerCalendar = Views::calendar(['role' => 'trainer'], 'test-csrf', []);
check(str_contains($trainerCalendar, 'data-allow-cabins="false"'), 'Trainers must not be offered cabin choices.');
$staffCalendar = Views::calendar(['role' => 'manager'], 'test-csrf', []);
check(str_contains($staffCalendar, 'data-request-approve hidden'), 'The dialog must offer approval while reviewing a request.');

$staffDashboard = Views::dashboard(['role' => 'manager'], [$request], 'test-csrf');
$document->loadHTML('<?xml encoding="UTF-8">' . $staffDashboard, LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($document);
check(
    $xpath->query('//button[@data-request-approve][@data-occurrence-id="9"]')->length === 1,
    'Staff approval must open the request editor for the occurrence.'
);
check(
    $xpath->query('//form[@action="/?action=booking-reject"][@data-confirm-action="reject"]//input[@name="assignment_ids[]"]')->length === 2,
    'Reject all pending place assignments together.'
);

check(
    $xpath->query('//form[@action="/?action=booking-reject"][@data-confirm-action="reject"]')->length === 1,
    'Confirm rejection from the request dashboard.'
);

$publicCalendar = Views::calendar(null, 'test-csrf', []);
check(
    !str_contains($publicCalendar, 'id="booking-form"')
        && str_contains($publicCalendar, 'href="/?page=mobile-calendar">Handy-Ansicht</a>'),
    'Anonymous visitors must not be offered a booking form and must be able to open the mobile calendar.'
);

$mobileCalendarDocument = new DOMDocument();
$mobileCalendarDocument->loadHTML(
    '<?xml encoding="UTF-8">' . Views::mobileCalendar(),
    LIBXML_NOERROR | LIBXML_NOWARNING
);
$mobileCalendarXPath = new DOMXPath($mobileCalendarDocument);
check(
    $mobileCalendarXPath->query('//*[@data-mobile-calendar]')->length === 1
        && $mobileCalendarXPath->query('//*[@id="mobile-calendar-resource-select"]/option[@value="pitch"]')->length === 1
        && $mobileCalendarXPath->query('//*[@id="mobile-calendar-resource-select"]/option[@value="bus"]')->length === 1
        && $mobileCalendarXPath->query('//*[@id="mobile-calendar-date"][@type="date"]')->length === 1
        && $mobileCalendarXPath->query('//*[@id="mobile-calendar-list"][@aria-live="polite"]')->length === 1
        && $mobileCalendarXPath->query('//a[@href="/" and normalize-space(.)="Zur vollständigen Kalenderansicht"]')->length === 1,
    'The mobile calendar must provide read-only daily navigation and a link back to the full calendar.'
);

foreach ([null, ['role' => 'trainer'], ['role' => 'manager'], ['role' => 'admin']] as $user) {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="UTF-8">' . Views::calendar($user, 'test-csrf', []), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    foreach (['resources', 'start', 'end', 'cabins', 'type', 'status', 'destination', 'purpose', 'recurrence'] as $field) {
        check(
            $xpath->query('//*[@id="event-dialog"]//*[@data-event-' . $field . ']')->length === 1,
            "Event details must include $field."
        );
    }
    $staff = in_array($user['role'] ?? '', ['admin', 'manager'], true);
    foreach (['driver', 'notes'] as $field) {
        check(
            $xpath->query('//*[@id="event-dialog"]//*[@data-event-' . $field . ']')->length === ($staff ? 1 : 0),
            "Only staff may see the private event field $field."
        );
    }
}

$service = (new ReflectionClass(BookingService::class))->newInstanceWithoutConstructor();
$layoutDocument = new DOMDocument();
$layoutDocument->loadHTML(
    '<?xml encoding="UTF-8">' . Views::layout('Test', '', null, 'test-csrf', 'https://example.test'),
    LIBXML_NOERROR | LIBXML_NOWARNING
);
$layoutXPath = new DOMXPath($layoutDocument);
check(
    $layoutXPath->query('//dialog[@id="action-confirm-dialog"]//*[@data-confirm-submit]')->length === 1,
    'Render the shared confirmation dialog on every page.'
);
$adminLayoutDocument = new DOMDocument();
$adminLayoutDocument->loadHTML(
    '<?xml encoding="UTF-8">' . Views::layout(
        'Test',
        '',
        ['role' => 'admin', 'first_name' => 'Admin', 'last_name' => ''],
        'test-csrf',
        'https://example.test'
    ),
    LIBXML_NOERROR | LIBXML_NOWARNING
);
$adminLayoutXPath = new DOMXPath($adminLayoutDocument);
$settingsMenu = '//nav//details[contains(concat(" ", normalize-space(@class), " "), " admin-settings-menu ")]';
check(
    $adminLayoutXPath->query($settingsMenu . '/summary[contains(@class, "btn") and normalize-space()="Einstellungen"]')->length === 1
        && $adminLayoutXPath->query($settingsMenu . '//a[@href="/?page=users"]')->length === 1
        && $adminLayoutXPath->query($settingsMenu . '//a[@href="/?page=resources"]')->length === 1
        && $adminLayoutXPath->query('//nav//a[@href="/?page=profile" and normalize-space()="Mein Profil"]')->length === 1
        && $adminLayoutXPath->query('//nav//a[@href="/?page=users" and not(ancestor::details)]')->length === 0
        && $adminLayoutXPath->query('//nav//a[@href="/?page=resources" and not(ancestor::details)]')->length === 0,
    'Admins must have a visible settings button with user and resource pages as submenu items.'
);
$managerLayoutDocument = new DOMDocument();
$managerLayoutDocument->loadHTML(
    '<?xml encoding="UTF-8">' . Views::layout(
        'Test',
        '',
        ['role' => 'manager', 'first_name' => 'Manager', 'last_name' => ''],
        'test-csrf',
        'https://example.test'
    ),
    LIBXML_NOERROR | LIBXML_NOWARNING
);
check(
    (new DOMXPath($managerLayoutDocument))->query('//nav//details[contains(@class, "admin-settings-menu")]')->length === 0,
    'The settings menu must remain restricted to admins.'
);
$profileDocument = new DOMDocument();
$profileDocument->loadHTML(
    '<?xml encoding="UTF-8">' . Views::profile([
        'first_name' => 'Test',
        'last_name' => 'User',
        'username' => 'test.user',
        'email' => null,
    ], 'test-csrf'),
    LIBXML_NOERROR | LIBXML_NOWARNING
);
$profileXPath = new DOMXPath($profileDocument);
$profileFieldIds = [];
foreach ($profileXPath->query('//form[@action="/?action=profile-update"]/div/input[@id]') as $input) {
    $profileFieldIds[] = $input->getAttribute('id');
}
check(
    $profileXPath->query('//form[@action="/?action=profile-update"]//input[@name="first_name"]')->length === 1
        && $profileXPath->query('//form[@action="/?action=profile-update"]//input[@name="last_name"]')->length === 1
        && $profileXPath->query('//form[@action="/?action=profile-update"]//input[@name="username"]')->length === 0
        && $profileXPath->query('//form[@action="/?action=profile-update"]//input[@readonly and @tabindex="-1" and @value="test.user"]')->length === 1
        && $profileFieldIds === ['profile-username', 'profile-first-name', 'profile-last-name', 'profile-email'],
    'The profile must put the read-only, keyboard-skipped username first, followed by editable fields.'
);
$setupHtml = Views::setup('test-csrf');
check(
    str_contains($setupHtml, 'name="first_name"')
        && str_contains($setupHtml, 'name="last_name"')
        && !str_contains($setupHtml, 'name="display_name"'),
    'The initial admin setup must collect first and last names separately.'
);
$emptyDatabaseSetupHtml = Views::setup('test-csrf', '', [
    'existing_table_count' => 0,
    'table_count' => 11,
]);
check(
    str_contains($emptyDatabaseSetupHtml, 'name="schema_action" value="keep"')
        && !str_contains($emptyDatabaseSetupHtml, 'id="setup-recreate"')
        && str_contains($emptyDatabaseSetupHtml, 'Datenbankschema und die Standardressourcen angelegt'),
    'An empty database must be prepared by the web setup without offering a destructive reset.'
);
$existingDatabaseSetupHtml = Views::setup('test-csrf', '', [
    'existing_table_count' => 5,
    'table_count' => 11,
]);
check(
    str_contains($existingDatabaseSetupHtml, 'id="setup-keep"')
        && str_contains($existingDatabaseSetupHtml, 'id="setup-recreate"')
        && str_contains($existingDatabaseSetupHtml, 'name="confirm_recreate"')
        && str_contains($existingDatabaseSetupHtml, 'unwiderruflich gelöscht'),
    'Existing application tables must offer preservation or an explicitly confirmed recreation.'
);
$schemaRepairHtml = Views::setup('test-csrf', '', [
    'existing_table_count' => 5,
    'table_count' => 11,
], false);
check(
    str_contains($schemaRepairHtml, 'Datenbankschema vervollständigen')
        && str_contains($schemaRepairHtml, 'name="schema_action" value="keep"')
        && !str_contains($schemaRepairHtml, 'id="setup-recreate"')
        && !str_contains($schemaRepairHtml, 'name="email"'),
    'An existing admin must only be offered a non-destructive schema repair.'
);
$inviteHtml = Views::users([], 'test-csrf', '', 'invite');
$createHtml = Views::users([], 'test-csrf', '', 'create');
check(
    str_contains($inviteHtml, 'name="first_name"')
        && str_contains($inviteHtml, 'name="last_name"')
        && str_contains($createHtml, 'name="first_name"')
        && str_contains($createHtml, 'name="last_name"'),
    'Admin user forms must collect first and last names separately.'
);
$validateDetails = new ReflectionMethod(BookingService::class, 'validateEventDetails');
foreach (['training', 'match', 'external', 'bus'] as $eventType) {
    foreach ([null, '', " \t\n", "\u{00A0}\u{2003}"] as $title) {
        try {
            $validateDetails->invoke($service, ['title' => $title, 'purpose' => 'Vehicle purpose'], $eventType);
        } catch (RuntimeException $exception) {
            check(
                $exception->getMessage() === 'Bitte gib eine Mannschaft oder einen Hinweis an.',
                "$eventType: Missing titles must report the required-field error."
            );
            continue;
        }
        throw new RuntimeException("$eventType: The server accepted an empty title.");
    }
    foreach (['  Test team  ' => 'Test team', str_repeat('ä', 180) => str_repeat('ä', 180)] as $title => $expected) {
        $details = $validateDetails->invoke($service, ['title' => $title], $eventType);
        check($details['title'] === $expected, "$eventType: Valid titles must be retained and trimmed.");
    }
    try {
        $validateDetails->invoke($service, ['title' => str_repeat('ä', 181)], $eventType);
    } catch (RuntimeException $exception) {
        check(
            $exception->getMessage() === 'Mannschaft / Hinweis darf höchstens 180 Zeichen lang sein.',
            "$eventType: Enforce the existing 180-character title limit."
        );
        continue;
    }
    throw new RuntimeException("$eventType: The server accepted an overlong title.");
}

echo "Booking dialog checks passed.\n";
