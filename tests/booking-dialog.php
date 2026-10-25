<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

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
    check($xpath->query('//*[@id="booking-create-menu"]/following-sibling::select[@id="calendar-resource-select"]')->length === 1, 'Place the create button before the calendar dropdown.');
    check($xpath->query('//select[@id="calendar-resource-select"]/option')->length === 2, 'Offer only pitch and vehicle calendars.');
    check($xpath->query('//*[@data-resource-type="cabin" or @data-calendar-view="cabin" or @data-resource-mode="cabin"]')->length === 0, 'Cabins must not have their own booking or calendar.');
    foreach (['calendar-prev', 'calendar-next', 'calendar-today', 'calendar-date-picker'] as $control) {
        check($xpath->query('//div[contains(@class, "calendar-navigation")]/*[@id="' . $control . '"]')->length === 1, 'Group date navigation controls together.');
    }
}

$request = [
    'assignment_id' => 42,
    'assignment_status' => 'pending',
    'event_type' => 'training',
    'resource_name' => 'Test pitch',
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
$form = '//form[contains(@class, "request-edit-form")]';
checkDateTimeFields($xpath, $form);
checkRequiredTitle($xpath, $form);
check(
    $xpath->evaluate('string(' . $form . '//input[@data-booking-date]/@value)') === '2026-10-24',
    'Request date: Existing booking times must use Europe/Berlin.'
);
foreach (['start' => '15:07', 'end' => '17:03'] as $part => $time) {
    check(
        $xpath->evaluate('string(' . $form . '//input[@data-booking-time="' . $part . '"]/@value)') === $time,
        "Request $part: Preserve the existing minute-accurate time."
    );
}

check(
    !str_contains(Views::calendar(null, 'test-csrf', []), 'id="booking-form"'),
    'Anonymous visitors must not be offered a booking form.'
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
