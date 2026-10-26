<?php
declare(strict_types=1);

namespace App;

final class Views
{
    public static function layout(
        string $title,
        string $content,
        ?array $user,
        string $csrfToken,
        string $appUrl,
        ?array $flash = null,
        array $clientData = []
    ): string {
        $projectRoot = dirname(__DIR__);
        $assetRoot = is_dir($projectRoot . '/www/assets/build')
            ? $projectRoot . '/www/assets/build'
            : $projectRoot . '/assets/build';
        $assetVersion = is_file($assetRoot . '/app.js') ? (string) filemtime($assetRoot . '/app.js') : 'dev';
        $nav = '<a class="navbar-brand fw-semibold" href="/"><img class="navbar-brand-logo" src="/assets/images/vfl-kaufering-wappen.png" alt="Wappen des VfL Kaufering">Platz- und Busbelegung</a><div class="navbar-nav ms-auto flex-row align-items-center gap-3">';
        if ($user !== null) {
            if ($user['role'] === 'admin') {
                $nav .= '<details class="user-actions-menu admin-settings-menu">'
                    . '<summary class="btn btn-sm btn-outline-light">Einstellungen</summary>'
                    . '<div class="user-actions-menu-items"><a href="/?page=users">Benutzerverwaltung</a>'
                    . '<a href="/?page=resources">Ressourcenverwaltung</a></div></details>';
            }
            $nav .= '<span class="text-white-50 small">' . Security::escape(AuthService::fullName(
                (string) $user['first_name'],
                (string) $user['last_name']
            )) . '</span>';
            $nav .= '<a class="nav-link py-0 text-white" href="/?page=profile">Mein Profil</a>';
            $nav .= '<form method="post" action="/?action=logout" class="m-0"><input type="hidden" name="_csrf" value="' . Security::escape($csrfToken) . '"><button class="btn btn-sm btn-outline-light" type="submit">Abmelden</button></form>';
        } else {
            $nav .= '<a class="nav-link" href="/?page=login">Anmelden</a>';
        }
        $nav .= '</div>';

        $flashHtml = '';
        if ($flash !== null && ($flash['message'] ?? '') !== '') {
            $flashHtml = '<div class="alert alert-' . Security::escape($flash['type'] ?? 'info') . '" role="status">'
                . Security::escape($flash['message']) . '</div>';
        }
        $pageShellClass = str_contains($content, 'data-calendar-dashboard-layout')
            ? 'page-shell page-shell-wide'
            : 'page-shell';

        $appData = json_encode(array_merge([
            'csrfToken' => $csrfToken,
            'isLoggedIn' => $user !== null,
            'role' => $user['role'] ?? null,
            'appUrl' => $appUrl,
        ], $clientData), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        return '<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="' . Security::escape($csrfToken) . '">
    <title>' . Security::escape($title) . ' · Platz- und Busbelegung</title>
    <link rel="stylesheet" href="/assets/build/app.css?v=' . Security::escape($assetVersion) . '">
    <script>window.VereinApp=' . $appData . ';</script>
    <script src="/assets/build/app.js?v=' . Security::escape($assetVersion) . '" defer></script>
</head>
<body>
<nav class="navbar navbar-expand navbar-club"><div class="container-fluid">' . $nav . '</div></nav>
<main class="' . $pageShellClass . '">' . $flashHtml . $content . '</main>
' . self::actionConfirmationDialog() . '
</body>
</html>';
    }

    private static function actionConfirmationDialog(): string
    {
        return '<dialog id="action-confirm-dialog" aria-labelledby="action-confirm-title">
  <div class="dialog-header"><h2 class="h5 mb-0" data-confirm-title>Aktion bestätigen</h2></div>
  <div class="dialog-body"><p class="mb-0" data-confirm-message></p></div>
  <div class="dialog-footer">
    <button type="button" class="btn btn-outline-secondary" data-confirm-cancel>Abbrechen</button>
    <button type="button" class="btn btn-danger" data-confirm-submit>Bestätigen</button>
  </div>
</dialog>';
    }

    public static function calendar(
        ?array $user,
        string $csrf,
        array $resources,
        array $requests = [],
        bool $showAllRequests = false,
        int $requestPage = 1,
        int $requestTotal = 0,
        int $requestPageCount = 1,
        int $requestPageSize = 10,
        string $requestBaseUrl = '/'
    ): string
    {
        $eventTypes = '<option value="training">Training</option><option value="match">Spiel</option>';
        if (in_array($user['role'] ?? '', ['manager', 'admin'], true)) {
            $eventTypes .= '<option value="external">Externe Belegung</option>';
        }
        $staff = in_array($user['role'] ?? '', ['admin', 'manager'], true);
        $createButton = $user !== null && $staff
            ? self::bookingCreateMenu('+ Buchung anlegen')
            : '';

        $bookingDialog = $user === null ? '' : self::bookingDialog($user, $csrf, $eventTypes);
        $detailsDialog = self::eventDialog($user, $csrf);
        $dashboardAvailable = in_array($user['role'] ?? '', ['admin', 'manager', 'trainer'], true);
        $dashboard = $dashboardAvailable
            ? self::dashboard(
                $user,
                $requests,
                $csrf,
                '',
                true,
                $showAllRequests,
                $requestPage,
                $requestTotal,
                $requestPageCount,
                $requestPageSize,
                $requestBaseUrl
            )
            : '';
        $layoutClass = 'calendar-dashboard-layout' . ($dashboardAvailable ? ' has-dashboard' : '');
        $dashboardResizer = $dashboardAvailable
            ? '<div class="calendar-dashboard-resizer" data-dashboard-resizer role="separator"'
                . ' aria-orientation="vertical" aria-valuemin="35" aria-valuemax="85" aria-valuenow="75"'
                . ' aria-label="Aufteilung zwischen Belegungsplan und Buchungsanfragen ändern" tabindex="0"></div>'
            : '';

        return '<div class="' . $layoutClass . '" data-calendar-dashboard-layout>
<div class="calendar-main-content">
<div id="app-message" class="alert" role="status" hidden></div>
<section class="calendar-toolbar">
  <div><h1>Belegungsplan</h1><p class="text-secondary mb-0">Plätze mit ihren Kabinen und Fahrzeuge auf einen Blick.</p></div>
  <div class="calendar-actions">
    ' . $createButton . '
    <a class="btn btn-outline-secondary" href="/?page=mobile-calendar">Handy-Ansicht</a>
    <label class="visually-hidden" for="calendar-resource-select">Kalender auswählen</label>
    <select id="calendar-resource-select" class="form-select calendar-resource-select">
      <option value="pitch">Platzbelegung</option>
      <option value="bus">Fahrzeugkalender</option>
    </select>
  </div>
</section>
<section class="calendar-toolbar">
  <div class="calendar-navigation" role="group" aria-label="Kalenderdatum">
  <button id="calendar-prev" class="btn btn-outline-secondary" type="button" aria-label="Zurück">‹</button>
  <button id="calendar-date-picker" class="calendar-date-button" type="button" aria-haspopup="dialog" aria-label="Datum im Kalender auswählen">
    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M7 3v4m10-4v4M4 10h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z"/></svg>
    <span id="calendar-range" class="fw-semibold" aria-live="polite"></span><span aria-hidden="true">⌄</span>
  </button>
  <button id="calendar-next" class="btn btn-outline-secondary" type="button" aria-label="Weiter">›</button>
  <button id="calendar-today" class="btn btn-outline-secondary" type="button">Heute</button>
  </div>
  <label class="form-check form-switch mb-0" id="full-day-toggle-wrap" hidden>
    <input class="form-check-input" type="checkbox" id="full-day-toggle">
    <span class="form-check-label">Restlichen Tag einblenden</span>
  </label>
  <div class="btn-group" role="group" aria-label="Kalenderansicht">
    <button type="button" class="btn btn-outline-secondary active" data-view="day">Tag</button>
    <button type="button" class="btn btn-outline-secondary" data-view="week">Woche</button>
  </div>
</section>
<section class="calendar-frame">
  <div data-calendar-view="pitch">
    <div id="pitch-day" class="resource-day-scroll"></div>
    <div id="pitch-week" class="resource-week-scroll" hidden></div>
  </div>
  <div data-calendar-view="bus" hidden>
    <div id="bus-day" class="resource-day-scroll"></div>
    <div id="bus-week" class="resource-week-scroll" hidden></div>
  </div>
  <div class="calendar-legend">
    <span class="legend-item" style="--legend-color:var(--booking-training)">Training</span>
    <span class="legend-item" style="--legend-color:var(--booking-match)">Spiel</span>
    <span class="legend-item" style="--legend-color:var(--booking-external)">Externe Belegung</span>
    <span class="legend-item" style="--legend-color:var(--booking-bus)">Fahrzeug</span>
    <span class="legend-item legend-recurring"><span aria-hidden="true">↻</span> Regeltermin</span>
    <span class="legend-item legend-pending"><span class="legend-pending-colors" aria-hidden="true"><span style="--legend-color:var(--booking-training)"></span><span style="--legend-color:var(--booking-match)"></span><span style="--legend-color:var(--booking-external)"></span><span style="--legend-color:var(--booking-bus)"></span></span>Anfrage</span>
  </div>
</section>
</div>
' . $dashboardResizer . $dashboard . '
</div>
' . $bookingDialog . $detailsDialog . self::recurrenceScopeDialog();
    }

    public static function mobileCalendar(): string
    {
        return '<section class="mobile-agenda-page" data-mobile-calendar>
  <header class="mobile-agenda-header">
    <h1>Belegungsplan – Handy-Ansicht</h1>
    <p class="text-secondary">Tagesübersicht der Platz- und Fahrzeugbelegungen.</p>
    <a class="btn btn-outline-secondary" href="/">Zur vollständigen Kalenderansicht</a>
  </header>
  <section class="mobile-agenda-controls panel-card p-3" aria-label="Kalender einstellen">
    <div>
      <label class="form-label" for="mobile-calendar-resource-select">Belegungsart</label>
      <select id="mobile-calendar-resource-select" class="form-select">
        <option value="pitch">Platzbelegung</option>
        <option value="bus">Fahrzeugkalender</option>
      </select>
    </div>
    <div>
      <label class="form-label" for="mobile-calendar-date">Datum</label>
      <div class="mobile-agenda-date-navigation">
        <button id="mobile-calendar-prev" class="btn btn-outline-secondary" type="button" aria-label="Vorheriger Tag">‹</button>
        <input id="mobile-calendar-date" class="form-control" type="date">
        <button id="mobile-calendar-next" class="btn btn-outline-secondary" type="button" aria-label="Nächster Tag">›</button>
      </div>
      <button id="mobile-calendar-today" class="btn btn-outline-secondary mt-2 w-100" type="button">Heute</button>
    </div>
  </section>
  <div id="mobile-calendar-message" class="alert alert-danger" role="alert" hidden></div>
  <div id="mobile-calendar-list" class="mobile-agenda-list" aria-live="polite"></div>
</section>';
    }

    private static function bookingCreateMenu(string $label): string
    {
$bookingChoices = '';
foreach ([
    ['event_type' => 'training', 'resource_mode' => 'pitch', 'label' => 'Platzbelegung'],
    ['event_type' => 'bus', 'resource_mode' => 'bus', 'label' => 'Fahrzeug'],
] as $option) {
    $bookingChoices .= '<button type="button" class="booking-create-option" data-booking-option'
        . ' data-event-type="' . Security::escape($option['event_type']) . '"'
        . ' data-resource-mode="' . Security::escape($option['resource_mode']) . '">'
        . Security::escape($option['label']) . '</button>';
}

return '<details id="booking-create-menu" class="booking-create-menu">'
    . '<summary id="open-booking" class="btn btn-primary">' . Security::escape($label) . '</summary>'
    . '<div class="booking-create-options" aria-label="Buchungsart auswählen">' . $bookingChoices . '</div>'
    . '</details>';
    }

    private static function bookingDialog(array $user, string $csrf, string $eventTypes): string
    {
        $allowCabins = in_array($user['role'] ?? '', ['admin', 'manager'], true);

        return '<dialog id="booking-dialog" aria-labelledby="booking-dialog-title">
<form id="booking-form" method="post" data-allow-cabins="' . ($allowCabins ? 'true' : 'false') . '">
  <div class="dialog-header"><h2 id="booking-dialog-title" class="h5 mb-0">Platzbelegung anlegen</h2><button type="button" class="btn-close" aria-label="Schließen" data-dialog-close></button></div>
  <div class="dialog-body">
    <div class="alert alert-danger mb-0" data-booking-error role="alert" aria-live="assertive" hidden></div>
    <input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '">
    <input type="hidden" name="occurrence_id">
    <div class="row g-3">
      <input type="hidden" id="resource-mode" name="resource_mode" value="pitch">
      <div class="col-md-4" id="booking-event-type-choice"><label class="form-label" for="event-type-select">Kategorie</label><select class="form-select" id="event-type-select" name="event_type">' . $eventTypes . '<option value="bus" hidden>Fahrzeug</option></select></div>
      <div class="col-12"><label class="form-label" id="booking-title-label" for="event-title">Mannschaft / Hinweis</label><textarea class="form-control" id="event-title" name="combined_title" maxlength="180" rows="2" required data-required-non-whitespace data-initial-value=""></textarea><input type="hidden" name="title"><input type="hidden" name="team_name"></div>
      <div class="col-12">' . self::bookingDateTimeFields('booking') . '</div>
      <div class="col-12">
        <div id="resource-choices" class="resource-choices mt-2"></div>
      </div>
      <input type="hidden" name="destination"><input type="hidden" name="purpose"><input type="hidden" name="driver_name">
      <div class="col-12"><label class="form-label" for="private-notes">Interne Notiz (optional)</label><textarea class="form-control" id="private-notes" name="private_notes" maxlength="4000" rows="2"></textarea></div>
      <div class="col-12" id="recurrence-fields">
        <label class="form-label" for="frequency">Wiederholung</label>
        <select class="form-select mb-2" id="frequency" name="frequency">
          <option value="none">Keine</option><option value="daily">Täglich</option><option value="weekly">Wöchentlich</option><option value="monthly">Monatlich</option><option value="yearly">Jährlich</option>
        </select>
        <div data-recurrence-options hidden>
        <div class="row g-2">
          <div class="col-sm-4"><label class="form-label" for="interval">Alle …</label><input class="form-control" id="interval" name="interval" type="number" min="1" max="52" value="1"></div>
          <div class="col-sm-8"><label class="form-label" for="limit-type">Serie beenden nach</label><select class="form-select" id="limit-type" name="limit_type"><option value="until">Enddatum</option><option value="count">Anzahl Termine</option></select></div>
          <div class="col-sm-6" data-recurrence-limit="until"><label class="form-label" for="until">Enddatum</label><input class="form-control" id="until" name="until" type="date"></div>
          <div class="col-sm-6" data-recurrence-limit="count" hidden><label class="form-label" for="count">Anzahl</label><input class="form-control" id="count" name="count" type="number" min="1" max="5000" value="10"></div>
        </div>
        <fieldset class="mt-2" id="weekday-choices"><legend class="form-label">Wochentage</legend>
          ' . self::weekdayCheckboxes() . '
        </fieldset>
        </div>
        <p class="small text-secondary mt-2 mb-0" data-edit-only hidden>Eine geänderte Wiederholung baut die Termine im gewählten Änderungsumfang neu auf. Nicht mehr enthaltene Termine werden entfernt.</p>
      </div>
      <input type="hidden" name="scope" value="one">
    </div>
  </div>
  <div class="dialog-footer"><button type="button" class="btn btn-outline-secondary" data-dialog-close data-booking-close>Abbrechen</button><button type="button" class="btn btn-success" data-request-approve hidden>Freigeben</button><button type="submit" class="btn btn-primary" data-booking-submit>Speichern</button></div>
</form>
</dialog>';
    }

    private static function bookingDateTimeFields(string $prefix, string $startsAt = '', string $endsAt = ''): string
    {
        $dateId = Security::escape($prefix . '-date');
        $html = '<div class="row g-2" data-booking-dates>'
            . '<div class="col-md-4"><label class="form-label" for="' . $dateId . '">Datum</label>'
            . '<input class="form-control" id="' . $dateId . '" type="date" data-booking-date value="'
            . Security::escape(substr($startsAt, 0, 10)) . '" required></div>';
        foreach ([
            ['part' => 'start', 'label' => 'Beginn', 'name' => 'starts_at', 'value' => $startsAt],
            ['part' => 'end', 'label' => 'Ende', 'name' => 'ends_at', 'value' => $endsAt],
        ] as $field) {
            $id = Security::escape($prefix . '-' . $field['part'] . '-time');
            $html .= '<div class="col-md-4"><label class="form-label" for="' . $id . '">'
                . $field['label'] . '</label>'
                . '<div class="time-input-wrap" data-time-picker>'
                . '<input class="form-control time-picker-input" id="' . $id . '" type="text" inputmode="text"'
                . ' pattern="(?:[01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" placeholder="HH:MM"'
                . ' data-booking-time="' . $field['part'] . '" value="'
                . Security::escape(substr($field['value'], 11, 5)) . '" required>'
                . '<button class="time-picker-trigger" type="button" aria-label="Viertelstündliche Uhrzeiten auswählen"'
                . ' aria-haspopup="menu" aria-expanded="false" aria-controls="' . $id . '-menu" data-time-picker-trigger>'
                . '<svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>'
                . '</button><div class="time-picker-menu" id="' . $id . '-menu" role="menu"'
                . ' aria-label="Viertelstündliche Uhrzeiten" hidden></div></div></div>'
                . '<input type="hidden" name="' . $field['name'] . '" value="'
                . Security::escape($field['value']) . '">';
        }

        return $html . '</div>';
    }

    private static function weekdayCheckboxes(): string
    {
        $days = ['MO' => 'Mo', 'TU' => 'Di', 'WE' => 'Mi', 'TH' => 'Do', 'FR' => 'Fr', 'SA' => 'Sa', 'SU' => 'So'];
        $html = '';
        foreach ($days as $value => $label) {
            $html .= '<label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="weekdays[]" value="' . $value . '"><span class="form-check-label">' . $label . '</span></label>';
        }

        return $html;
    }

    private static function eventDialog(?array $user, string $csrf): string
    {
        $cancel = in_array($user['role'] ?? '', ['admin', 'manager'], true)
            ? '<form method="post" action="/?action=booking-cancel" data-cancel-form data-confirm-action="delete"><input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '"><input type="hidden" name="assignment_id" value=""><input type="hidden" name="scope" value="one"><button class="btn btn-outline-danger" type="submit">Löschen</button></form><form method="post" action="/?action=booking-cabin-cancel" data-cancel-cabin-form data-confirm-action="delete" hidden><input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '"><input type="hidden" name="occurrence_id" value=""><input type="hidden" name="cabin_id" value=""><input type="hidden" name="scope" value="one"><button class="btn btn-outline-danger" type="submit">Kabinenbelegung löschen</button></form>'
            : '';
        $edit = in_array($user['role'] ?? '', ['admin', 'manager'], true)
            ? '<button type="button" class="btn btn-outline-primary" data-edit-event>Ändern</button>'
            : '';
        $requestActions = in_array($user['role'] ?? '', ['admin', 'manager'], true)
            ? '<form method="post" action="/?action=booking-approve" data-event-approve hidden>'
                . '<input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '">'
                . '<input type="hidden" name="assignment_id" value="">'
                . '<button class="btn btn-success" type="submit">Anfrage annehmen</button></form>'
                . '<form method="post" action="/?action=booking-reject" data-event-reject data-confirm-action="reject" hidden>'
                . '<input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '">'
                . '<input type="hidden" name="assignment_id" value="">'
                . '<button class="btn btn-outline-danger" type="submit">Ablehnen</button></form>'
            : '';
        $privateDetails = in_array($user['role'] ?? '', ['admin', 'manager'], true)
            ? '<dt class="col-sm-4" data-event-bus-field>Fahrer (intern)</dt><dd class="col-sm-8" data-event-bus-field data-event-driver></dd>
      <dt class="col-sm-4">Interne Notiz</dt><dd class="col-sm-8 event-notes" data-event-notes></dd>'
            : '';

        return '<dialog id="event-dialog" aria-labelledby="event-dialog-title">
  <div class="dialog-header"><h2 class="h5 mb-0 booking-title" id="event-dialog-title" data-event-title></h2><button type="button" class="btn-close" aria-label="Schließen" data-dialog-close></button></div>
  <div class="dialog-body">
    <p class="alert alert-danger" data-event-error role="alert" hidden></p>
    <dl class="row mb-0">
      <dt class="col-sm-4">Ressource</dt><dd class="col-sm-8" data-event-resource></dd>
      <dt class="col-sm-4">Gebuchte Ressourcen</dt><dd class="col-sm-8" data-event-resources></dd>
      <dt class="col-sm-4">Beginn</dt><dd class="col-sm-8" data-event-start></dd>
      <dt class="col-sm-4">Ende</dt><dd class="col-sm-8" data-event-end></dd>
      <dt class="col-sm-4" data-event-pitch-field>Kabinen</dt><dd class="col-sm-8" data-event-pitch-field data-event-cabins></dd>
      <dt class="col-sm-4">Kategorie</dt><dd class="col-sm-8" data-event-type></dd>
      <dt class="col-sm-4">Status</dt><dd class="col-sm-8" data-event-status></dd>
      <dt class="col-sm-4" data-event-bus-field>Ziel</dt><dd class="col-sm-8" data-event-bus-field data-event-destination></dd>
      <dt class="col-sm-4" data-event-bus-field>Zweck</dt><dd class="col-sm-8" data-event-bus-field data-event-purpose></dd>
      <dt class="col-sm-4" data-event-pitch-field>Wiederholung</dt><dd class="col-sm-8" data-event-pitch-field data-event-recurrence></dd>
      ' . $privateDetails . '
    </dl>
  </div>
  <div class="dialog-footer">' . $requestActions . $cancel . $edit . '<button type="button" class="btn btn-primary" data-dialog-close>Schließen</button></div>
</dialog>';
    }

    private static function recurrenceScopeDialog(): string
    {
        return '<dialog id="recurrence-scope-dialog" aria-labelledby="recurrence-scope-title">
  <div class="dialog-header"><h2 class="h5 mb-0" id="recurrence-scope-title">Regeltermin</h2><button type="button" class="btn-close" aria-label="Schließen" data-scope-cancel></button></div>
  <div class="dialog-body"><p data-scope-message>Dieser Termin gehört zu einer Regelterminserie. Auf welche Termine soll die Aktion angewendet werden?</p></div>
  <div class="dialog-footer">
    <button type="button" class="btn btn-outline-secondary" data-scope-cancel>Abbrechen</button>
    <button type="button" class="btn btn-outline-primary" data-scope-choice="one">Nur diesen Termin</button>
    <button type="button" class="btn btn-danger" data-scope-choice="future">Diesen und alle zukünftigen Termine</button>
  </div>
</dialog>';
    }

    public static function login(string $csrf, string $error = ''): string
    {
        return '<section class="panel-card mx-auto p-4" style="max-width:480px">
<h1 class="h3">Anmelden</h1><p class="text-secondary">Für interne Buchungen und Verwaltung.</p>
' . self::inlineError($error) . '
<form method="post" action="/?action=login">
  <input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '">
  <div class="mb-3"><label class="form-label" for="identifier">E-Mail oder Benutzername</label><input class="form-control" id="identifier" name="identifier" type="text" autocomplete="username" required></div>
  <div class="mb-3"><label class="form-label" for="password">Passwort</label><input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required></div>
  <button class="btn btn-primary w-100" type="submit">Anmelden</button>
</form>
<p class="mt-3 mb-0"><a href="/?page=forgot">Passwort vergessen?</a></p></section>';
    }

    public static function dashboard(
        ?array $user,
        array $requests,
        string $csrf,
        string $error = '',
        bool $embedded = false,
        bool $showAllRequests = false,
        int $requestPage = 1,
        int $requestTotal = 0,
        int $requestPageCount = 1,
        int $requestPageSize = 10,
        string $requestBaseUrl = '/'
    ): string
    {
        if ($user === null) {
            return '';
        }

        $staff = in_array($user['role'], ['admin', 'manager'], true);
        $requestCreateButton = $user['role'] === 'trainer'
            ? self::bookingCreateMenu('+ Buchungsanfrage stellen')
            : '';
        $rows = '';
        foreach ($requests as $request) {
            $status = self::statusLabel($request['assignment_status']);
            $isSeriesSummary = (int) ($request['is_series_summary'] ?? 0) === 1;
            if ($isSeriesSummary) {
                $status .= ' · ' . (int) $request['occurrence_count'] . ' Serientermine';
            }
            $pendingIds = array_values(array_filter(
                array_map('intval', explode(',', (string) ($request['pending_assignment_ids'] ?? ''))),
                static fn(int $id): bool => $id > 0
            ));
            $assignmentInputs = implode('', array_map(
                static fn(int $id): string => '<input type="hidden" name="assignment_ids[]" value="' . $id . '">',
                $pendingIds
            ));
            $details = [
                $request['resource_names'],
                $request['team_name'],
                $request['destination'],
                $request['purpose'],
                $request['cabins'] ? 'Kabine: ' . $request['cabins'] : '',
            ];
            $date = Recurrence::fromDatabase($request['starts_at'])
                ->setTimezone(new \DateTimeZone('Europe/Berlin'))
                ->format('d.m.Y H:i');
            $end = Recurrence::fromDatabase($request['ends_at'])
                ->setTimezone(new \DateTimeZone('Europe/Berlin'))
                ->format($isSeriesSummary ? 'd.m.Y H:i' : 'H:i');
            $assignmentId = (int) $request['assignment_id'];
            $resourceType = $request['resource_type'] === 'bus' ? 'bus' : 'pitch';
            $entryDetails = '<strong class="booking-title">' . Security::escape($request['title']) . '</strong>'
                . ' <span class="request-status">' . Security::escape($status) . '</span>'
                . '<span class="request-details">' . Security::escape($date . '–' . $end) . ' · ' . Security::escape(implode(' · ', array_filter($details))) . '</span>';
            $jumpButton = in_array($request['assignment_status'], ['pending', 'confirmed'], true)
                ? '<button type="button" class="dashboard-entry-link" data-dashboard-jump'
                    . ' data-assignment-id="' . $assignmentId . '"'
                    . ' data-occurrence-id="' . (int) $request['occurrence_id'] . '"'
                    . ' data-resource-type="' . $resourceType . '"'
                    . ' data-start="' . Security::escape(Recurrence::fromDatabase($request['starts_at'])->format(DATE_ATOM)) . '">'
                    . $entryDetails . '</button>'
                : '<div class="dashboard-entry-closed">' . $entryDetails . '</div>';
            $rows .= '<article class="request-row"><div class="request-row-layout"><div class="request-row-content">'
                . $jumpButton;
            if ($staff) {
                $rows .= '<div class="small text-secondary">Anfrage von ' . Security::escape($request['requester_name'] ?? 'Verwalter') . '</div>';
            }
            if (($request['rejection_reason'] ?? '') !== '') {
                $rows .= '<div class="small text-danger">Begründung: ' . Security::escape($request['rejection_reason']) . '</div>';
            }
            $rows .= '</div><div class="request-row-actions">';

            if ($staff && $request['assignment_status'] === 'pending') {
                $rows .= '<button class="btn btn-sm btn-success" type="button" data-request-approve'
                    . ' data-occurrence-id="' . (int) $request['occurrence_id'] . '">Freigeben</button>'
                    . '<form method="post" action="/?action=booking-reject" data-confirm-action="reject"><input type="hidden" name="_csrf" value="'
                    . Security::escape($csrf) . '">' . $assignmentInputs . '<button class="btn btn-sm btn-outline-danger"'
                    . ' type="submit">Ablehnen</button></form>';
            } elseif (!$staff && $request['assignment_status'] === 'pending') {
                $rows .= '<button type="button" class="btn btn-sm btn-outline-primary" data-request-edit'
                    . ' data-occurrence-id="' . (int) $request['occurrence_id'] . '">Ändern</button>'
                    . '<form method="post" action="/?action=booking-delete" data-confirm-action="delete"><input type="hidden" name="_csrf" value="'
                    . Security::escape($csrf) . '">' . $assignmentInputs
                    . '<button class="btn btn-sm btn-outline-danger" type="submit">Löschen</button></form>';
            }
            $rows .= '</div></div></article>';
        }

        $empty = $showAllRequests
            ? 'Es gibt keine Buchungsanfragen.'
            : ($staff ? 'Es gibt keine offenen Anfragen.' : 'Du hast keine offenen Anfragen.');
        $description = $showAllRequests
            ? 'Alle Buchungsanfragen mit ihrem aktuellen Status.'
            : 'Offene Buchungsanfragen' . ($staff ? ' zur Freigabe.' : '.');
        $requestQueryPrefix = $requestBaseUrl . (str_contains($requestBaseUrl, '?') ? '&' : '?');
        $toggleUrl = $showAllRequests
            ? $requestBaseUrl
            : $requestQueryPrefix . 'requests=all';
        $toggleLabel = $showAllRequests ? 'Nur offene Anfragen' : 'Alle Anfragen anzeigen';
        $pagination = '';
        if ($requestTotal > $requestPageSize) {
            $pageQuery = $showAllRequests ? 'requests=all&' : '';
            $previous = $requestQueryPrefix . $pageQuery . 'requests_page=' . max(1, $requestPage - 1);
            $next = $requestQueryPrefix . $pageQuery . 'requests_page=' . min($requestPageCount, $requestPage + 1);
            $pagination = '<nav class="request-pagination" aria-label="Seitennavigation Buchungsanfragen">'
                . '<a class="btn btn-sm btn-outline-secondary' . ($requestPage <= 1 ? ' disabled' : '')
                . '" href="' . Security::escape($previous) . '"' . ($requestPage <= 1 ? ' aria-disabled="true" tabindex="-1"' : '') . '>Zurück</a>'
                . '<span aria-live="polite">Seite ' . $requestPage . ' von ' . $requestPageCount . '</span>'
                . '<a class="btn btn-sm btn-outline-secondary' . ($requestPage >= $requestPageCount ? ' disabled' : '')
                . '" href="' . Security::escape($next) . '"' . ($requestPage >= $requestPageCount ? ' aria-disabled="true" tabindex="-1"' : '') . '>Weiter</a>'
                . '</nav>';
        }

        return '<aside id="request-dashboard" class="panel-card calendar-dashboard-panel p-3"'
            . ($embedded ? ' data-request-dashboard' : '') . '>
<h2 class="h4">Buchungsanfragen</h2><p class="text-secondary">' . $description . '</p>
' . self::inlineError($error) . '
<div class="request-list-controls">' . $requestCreateButton
            . '<a class="btn btn-sm btn-outline-primary" href="' . Security::escape($toggleUrl) . '">'
            . $toggleLabel . '</a>'
            . ($requestTotal > 0
                ? '<span class="small text-secondary">' . (($requestPage - 1) * $requestPageSize + 1)
                    . '–' . min($requestPage * $requestPageSize, $requestTotal) . ' von ' . $requestTotal . '</span>'
                : '')
            . '</div>
<div id="request-list">' . ($rows !== '' ? $rows : '<p class="mb-0">' . $empty . '</p>') . '</div>
' . $pagination . '
' . ($embedded ? '' : '<p class="mt-3 mb-0"><a class="btn btn-primary" href="/">Zum Belegungsplan</a></p>') . '</aside>';
    }

    public static function users(array $users, string $csrf, string $error = '', string $form = ''): string
    {
        $rows = '';
        foreach ($users as $user) {
            $id = (int) $user['id'];
            $roleLabels = ['admin' => 'Admin', 'manager' => 'Verwalter', 'trainer' => 'Trainer'];
            $statusLabels = ['active' => 'Aktiv', 'invited' => 'Eingeladen', 'inactive' => 'Deaktiviert'];
            $fullName = AuthService::fullName((string) $user['first_name'], (string) $user['last_name']);
            $rows .= '<tr><td class="fw-semibold">' . Security::escape($user['first_name'])
                . '</td><td>' . Security::escape($user['last_name'])
                . '</td><td>' . Security::escape((string) ($user['email'] ?? '—'))
                . '</td><td>' . Security::escape((string) ($user['username'] ?? '—'))
                . '</td><td><form method="post" action="/?action=user-role" class="d-flex gap-2">'
                . '<input type="hidden" name="_csrf" value="' . Security::escape($csrf)
                . '"><input type="hidden" name="id" value="' . $id
                . '"><select class="form-select form-select-sm" name="role" aria-label="Rolle für '
                . Security::escape($fullName) . '">';
            foreach ($roleLabels as $role => $label) {
                $rows .= '<option value="' . $role . '"' . ($user['role'] === $role ? ' selected' : '')
                    . '>' . $label . '</option>';
            }
            $rows .= '</select><button class="btn btn-sm btn-outline-primary text-nowrap" type="submit">'
                . 'Rolle ändern</button></form></td><td>'
                . Security::escape($statusLabels[$user['status']] ?? $user['status'])
                . '</td><td><div class="d-flex flex-wrap gap-2 align-items-start">'
                . '<details><summary class="btn btn-sm btn-outline-primary text-nowrap">Passwort ändern</summary>'
                . '<form method="post" action="/?action=user-password" class="user-password-form mt-2">'
                . '<input type="hidden" name="_csrf" value="' . Security::escape($csrf)
                . '"><input type="hidden" name="id" value="' . $id
                . '"><label class="form-label small" for="new-password-' . $id . '">Neues Passwort</label>'
                . '<input class="form-control form-control-sm mb-2" id="new-password-' . $id
                . '" type="password" name="password" minlength="12" maxlength="200" autocomplete="new-password" required>'
                . '<label class="form-label small" for="confirm-password-' . $id . '">Passwort wiederholen</label>'
                . '<input class="form-control form-control-sm mb-2" id="confirm-password-' . $id
                . '" type="password" name="password_confirm" minlength="12" maxlength="200" autocomplete="new-password" required>'
                . '<button class="btn btn-sm btn-primary" type="submit">Passwort speichern</button></form></details>'
                . '<button class="btn btn-sm btn-outline-danger text-nowrap" type="button" data-delete-user'
                . ' data-user-id="' . $id . '" data-user-name="' . Security::escape($fullName)
                . '">Löschen</button></div></td></tr>';
        }

        $empty = $rows === '' ? '<tr><td colspan="7" class="text-center text-secondary py-4">'
            . 'Es sind noch keine Benutzer angelegt.</td></tr>' : $rows;

        $header = '<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">'
            . '<h1 class="h3 mb-0">Benutzerverwaltung</h1>'
            . '<details class="user-actions-menu"><summary class="btn btn-primary">Benutzer hinzufügen</summary>'
            . '<div class="user-actions-menu-items">'
            . '<a href="/?page=users&amp;form=invite">Per E-Mail einladen</a>'
            . '<a href="/?page=users&amp;form=create">Ohne E-Mail anlegen</a>'
            . '</div></details></div>' . self::inlineError($error);

        if ($form === 'invite') {
            return $header . self::inviteUserForm($csrf);
        }
        if ($form === 'create') {
            return $header . self::createUserForm($csrf);
        }

        return $header . '<section class="panel-card p-3"><div class="table-responsive">'
            . '<table class="table align-middle mb-0">'
            . '<thead><tr><th>Vorname</th><th>Nachname</th><th>E-Mail</th><th>Benutzername</th><th>Rolle</th><th>Status</th>'
            . '<th>Aktionen</th></tr></thead><tbody>' . $empty . '</tbody></table></div></section>'
            . '<dialog id="delete-user-dialog" aria-labelledby="delete-user-title">'
            . '<form method="post" action="/?action=user-delete">'
            . '<div class="dialog-header"><h2 id="delete-user-title" class="h5 mb-0">Benutzer löschen</h2>'
            . '<button type="button" class="btn-close" aria-label="Schließen" data-dialog-close></button></div>'
            . '<div class="dialog-body"><input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '">'
            . '<input type="hidden" name="id" id="delete-user-id">'
            . '<p>Benutzer <strong id="delete-user-name"></strong> wirklich endgültig löschen?</p>'
            . '<p class="mb-0 text-secondary">Alle zugehörigen Reservierungen bleiben erhalten und werden keinem Benutzer mehr zugeordnet.</p></div>'
            . '<div class="dialog-footer"><button type="button" class="btn btn-outline-secondary" data-dialog-close>'
            . 'Abbrechen</button><button class="btn btn-danger" type="submit">Endgültig löschen</button></div></form></dialog>';
    }

    public static function profile(array $user, string $csrf): string
    {
        return '<section class="panel-card mx-auto p-4" style="max-width:640px">'
            . '<h1 class="h3">Meine Daten</h1>'
            . '<p class="text-secondary">Hier kannst du deinen Vornamen, Nachnamen und deine E-Mail-Adresse ändern.</p>'
            . '<form method="post" action="/?action=profile-update">'
            . '<input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '">'
            . '<div class="mb-3"><label class="form-label" for="profile-username">Benutzername</label>'
            . '<input class="form-control" id="profile-username" readonly tabindex="-1" value="'
            . Security::escape((string) $user['username']) . '">'
            . '<div class="form-text">Der Benutzername kann nicht selbst geändert werden.</div></div>'
            . '<div class="mb-3"><label class="form-label" for="profile-first-name">Vorname</label>'
            . '<input class="form-control" id="profile-first-name" name="first_name" maxlength="120" required value="'
            . Security::escape((string) $user['first_name']) . '"></div>'
            . '<div class="mb-3"><label class="form-label" for="profile-last-name">Nachname</label>'
            . '<input class="form-control" id="profile-last-name" name="last_name" maxlength="120" value="'
            . Security::escape((string) $user['last_name']) . '">'
            . '<div class="form-text">Optional.</div></div>'
            . '<div class="mb-3"><label class="form-label" for="profile-email">E-Mail</label>'
            . '<input class="form-control" id="profile-email" name="email" type="email" maxlength="254" value="'
            . Security::escape((string) ($user['email'] ?? '')) . '"></div>'
            . '<button class="btn btn-primary" type="submit">Daten speichern</button></form></section>';
    }

    private static function inviteUserForm(string $csrf): string
    {
        return '<a class="btn btn-outline-secondary mb-3" href="/?page=users">Zur Benutzerliste</a>'
            . '<section class="panel-card mx-auto p-4" style="max-width:640px">'
            . '<h2 class="h4">Benutzer per E-Mail einladen</h2>'
            . '<p class="text-secondary">Die eingeladene Person legt ihr Passwort über den Einladungslink selbst fest.</p>'
            . '<form method="post" action="/?action=user-invite">'
            . '<input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '">'
            . '<div class="mb-3"><label class="form-label" for="invite-user-first-name">Vorname</label>'
            . '<input class="form-control" id="invite-user-first-name" name="first_name" maxlength="120" required></div>'
            . '<div class="mb-3"><label class="form-label" for="invite-user-last-name">Nachname</label>'
            . '<input class="form-control" id="invite-user-last-name" name="last_name" maxlength="120">'
            . '<div class="form-text">Optional.</div></div>'
            . '<div class="mb-3"><label class="form-label" for="invite-user-email">E-Mail</label>'
            . '<input class="form-control" id="invite-user-email" name="email" type="email" maxlength="254" required></div>'
            . '<div class="mb-3"><label class="form-label" for="invite-user-username">Benutzername</label>'
            . '<input class="form-control" id="invite-user-username" name="username" minlength="3" maxlength="80" '
            . 'pattern="[a-zA-Z0-9][a-zA-Z0-9._-]{2,79}" autocomplete="username" required>'
            . '<div class="form-text">3–80 Zeichen: Buchstaben, Zahlen, Punkt, Bindestrich oder Unterstrich.</div></div>'
            . '<div class="mb-3"><label class="form-label" for="invite-user-role">Rolle</label>'
            . '<select class="form-select" id="invite-user-role" name="role">'
            . '<option value="trainer">Trainer</option><option value="manager">Verwalter</option>'
            . '<option value="admin">Admin</option></select></div>'
            . '<button class="btn btn-primary" type="submit">Einladung senden</button></form></section>';
    }

    private static function createUserForm(string $csrf): string
    {
        return '<a class="btn btn-outline-secondary mb-3" href="/?page=users">Zur Benutzerliste</a>'
            . '<section class="panel-card mx-auto p-4" style="max-width:640px">'
            . '<h2 class="h4">Benutzer ohne E-Mail-Adresse anlegen</h2>'
            . '<p class="text-secondary">Die Anmeldung erfolgt mit Benutzername und Startpasswort.</p>'
            . '<form method="post" action="/?action=user-create">'
            . '<input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '">'
            . '<div class="mb-3"><label class="form-label" for="create-user-first-name">Vorname</label>'
            . '<input class="form-control" id="create-user-first-name" name="first_name" maxlength="120" required></div>'
            . '<div class="mb-3"><label class="form-label" for="create-user-last-name">Nachname</label>'
            . '<input class="form-control" id="create-user-last-name" name="last_name" maxlength="120">'
            . '<div class="form-text">Optional.</div></div>'
            . '<div class="mb-3"><label class="form-label" for="create-user-username">Benutzername</label>'
            . '<input class="form-control" id="create-user-username" name="username" minlength="3" maxlength="80" '
            . 'pattern="[a-zA-Z0-9][a-zA-Z0-9._-]{2,79}" autocomplete="username" required>'
            . '<div class="form-text">3–80 Zeichen: Buchstaben, Zahlen, Punkt, Bindestrich oder Unterstrich.</div></div>'
            . '<div class="mb-3"><label class="form-label" for="create-user-role">Rolle</label>'
            . '<select class="form-select" id="create-user-role" name="role">'
            . '<option value="trainer">Trainer</option><option value="manager">Verwalter</option>'
            . '<option value="admin">Admin</option></select></div>'
            . '<div class="mb-3"><label class="form-label" for="create-user-password">Startpasswort (mindestens 12 Zeichen)</label>'
            . '<input class="form-control" id="create-user-password" type="password" name="password" minlength="12" '
            . 'maxlength="200" autocomplete="new-password" required></div>'
            . '<div class="mb-3"><label class="form-label" for="create-user-password-confirm">Passwort wiederholen</label>'
            . '<input class="form-control" id="create-user-password-confirm" type="password" name="password_confirm" '
            . 'minlength="12" maxlength="200" autocomplete="new-password" required></div>'
            . '<button class="btn btn-primary" type="submit">Benutzer anlegen</button></form></section>';
    }

    public static function resources(
        array $resources,
        array $categories,
        string $csrf,
        string $error = '',
        string $form = ''
    ): string
    {
        $categoryOptions = '';
        $categoryFilterOptions = '<option value="">Alle Kategorien</option>';
        foreach ($categories as $category) {
            $code = (string) $category['code'];
            $name = (string) $category['name'];
            $categoryOptions .= '<option value="' . Security::escape($code) . '">' . Security::escape($name) . '</option>';
            $categoryFilterOptions .= '<option value="' . Security::escape($code) . '">'
                . Security::escape($name) . '</option>';
        }

        $resourceRows = '';
        foreach ($categories as $category) {
            $code = (string) $category['code'];
            $label = (string) $category['name'];
            $resourceRows .= '<tbody data-resource-order-group="' . Security::escape($code)
                . '" data-csrf-token="' . Security::escape($csrf) . '">';
            foreach ($resources as $resource) {
                if ($resource['type'] !== $code) {
                    continue;
                }

                $active = (bool) $resource['is_active'];
                $resourceRows .= '<tr data-resource-row data-resource-id="' . (int) $resource['id']
                    . '" data-resource-category="' . Security::escape($code) . '">'
                    . '<td><button type="button" class="resource-drag-handle" data-resource-drag-handle draggable="true" '
                    . 'aria-label="Ressource verschieben: ' . Security::escape($resource['name']) . '" '
                    . 'title="Ziehen oder mit den Pfeiltasten verschieben">↕</button> '
                    . Security::escape($resource['name']) . '</td><td>' . Security::escape($label) . '</td><td>'
                    . ($active ? 'Aktiv' : 'Deaktiviert') . '</td><td><div class="d-flex flex-wrap gap-2">'
                    . '<form method="post" action="/?action=resource-active"><input type="hidden" name="_csrf" value="'
                    . Security::escape($csrf) . '"><input type="hidden" name="id" value="' . (int) $resource['id']
                    . '"><input type="hidden" name="active" value="' . ($active ? '0' : '1')
                    . '"><button class="btn btn-sm btn-outline-secondary" type="submit">'
                    . ($active ? 'Deaktivieren' : 'Aktivieren') . '</button></form>'
                    . '<button type="button" class="btn btn-sm btn-outline-primary" data-resource-edit'
                    . ' data-resource-id="' . (int) $resource['id'] . '" data-resource-name="'
                    . Security::escape($resource['name']) . '">Bearbeiten</button>'
                    . '<button type="button" class="btn btn-sm btn-outline-danger" data-resource-delete'
                    . ' data-resource-id="' . (int) $resource['id'] . '" data-resource-name="'
                    . Security::escape($resource['name']) . '">Löschen</button></div></td></tr>';
            }
            $resourceRows .= '</tbody>';
        }
        if ($resources === []) {
            $resourceRows = '<tbody data-resource-empty><tr><td colspan="4" class="text-center text-secondary py-4">'
                . 'Es sind noch keine Ressourcen angelegt.</td></tr></tbody>';
        }

        $header = '<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">'
            . '<h1 class="h3 mb-0">Ressourcen</h1>'
            . '<details class="user-actions-menu"><summary class="btn btn-primary">Ressourcenaktionen</summary>'
            . '<div class="user-actions-menu-items"><a href="/?page=resources&amp;form=create">'
            . 'Ressource anlegen</a></div></details></div>' . self::inlineError($error);

        if ($form === 'create') {
            return $header . '<section class="panel-card p-3"><div class="d-flex justify-content-between '
                . 'align-items-center gap-3 mb-3"><h2 class="h5 mb-0">Ressource anlegen</h2>'
                . '<a class="btn btn-outline-secondary btn-sm" href="/?page=resources">Zurück zur Liste</a></div>'
                . '<form method="post" action="/?action=resource-save"><input type="hidden" name="_csrf" value="'
                . Security::escape($csrf) . '"><div class="mb-3"><label class="form-label">Kategorie</label>'
                . '<select class="form-select" name="type">' . $categoryOptions . '</select></div>'
                . '<div class="mb-3"><label class="form-label">Name</label><input class="form-control" '
                . 'name="name" maxlength="120" required></div><button class="btn btn-primary" type="submit">'
                . 'Anlegen</button></form><p class="small text-secondary mt-3 mb-0">Ressourcen können deaktiviert '
                . 'oder gelöscht werden. Beim Löschen werden auch die verknüpften Buchungen entfernt.</p></section>';
        }

        return $header . '
<section class="panel-card p-3"><div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-2"><h2 class="h5 mb-0">Alle Ressourcen</h2><div><label class="form-label mb-1" for="resource-category-filter">Kategorie filtern</label><select id="resource-category-filter" class="form-select form-select-sm">' . $categoryFilterOptions . '</select></div></div><p id="resource-order-status" class="small text-secondary" role="status" aria-live="polite">Ziehe eine Ressource am Griff, um die Reihenfolge zu ändern. Die Reihenfolge gilt auch im Kalender.</p><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Name</th><th>Kategorie</th><th>Status</th><th>Aktionen</th></tr></thead>' . $resourceRows . '</table></div></section>'
            . self::resourceEditDialog($csrf) . self::resourceDeleteDialog($csrf);
    }

    private static function resourceEditDialog(string $csrf): string
    {
        return '<dialog id="resource-edit-dialog" aria-labelledby="resource-edit-title"><form method="post" '
            . 'action="/?action=resource-save" id="resource-edit-form"><div class="dialog-header">'
            . '<h2 id="resource-edit-title" class="h5 mb-0">Ressource bearbeiten</h2>'
            . '<button type="button" class="btn-close" aria-label="Schließen" data-dialog-close></button></div>'
            . '<div class="dialog-body"><input type="hidden" name="_csrf" value="'
            . Security::escape($csrf) . '"><input type="hidden" name="id">'
            . '<label class="form-label" for="resource-edit-name">Name</label><input class="form-control" '
            . 'id="resource-edit-name" name="name" maxlength="120" required></div><div class="dialog-footer">'
            . '<button type="button" class="btn btn-outline-secondary" data-dialog-close>Abbrechen</button>'
            . '<button type="submit" class="btn btn-primary">Änderungen speichern</button></div></form></dialog>';
    }

    private static function resourceDeleteDialog(string $csrf): string
    {
        return '<dialog id="resource-delete-dialog" aria-labelledby="resource-delete-title">'
            . '<form method="post" action="/?action=resource-delete"><div class="dialog-header">'
            . '<h2 id="resource-delete-title" class="h5 mb-0">Ressource löschen</h2>'
            . '<button type="button" class="btn-close" aria-label="Schließen" data-dialog-close></button></div>'
            . '<div class="dialog-body"><input type="hidden" name="_csrf" value="'
            . Security::escape($csrf) . '"><input type="hidden" name="id">'
            . '<p>Möchten Sie die Ressource <strong id="resource-delete-name"></strong> dauerhaft löschen?</p>'
            . '<p class="mb-0">Alle Buchungen, die diese Ressource verwenden, werden vollständig einschließlich '
            . 'ihrer weiteren Ressourcenzuordnungen gelöscht. Es werden keine E-Mails versendet. Dieser Vorgang '
            . 'kann nicht rückgängig gemacht werden.</p></div><div class="dialog-footer">'
            . '<button type="button" class="btn btn-outline-secondary" data-dialog-close>Abbrechen</button>'
            . '<button type="submit" class="btn btn-danger">Ressource und Buchungen löschen</button>'
            . '</div></form></dialog>';
    }

    public static function setup(
        string $csrf,
        string $error = '',
        array $databaseStatus = [],
        bool $allowAdminCreation = true
    ): string
    {
        $existingTableCount = max(0, (int) ($databaseStatus['existing_table_count'] ?? 0));
        $tableCount = max(1, (int) ($databaseStatus['table_count'] ?? 11));
        $schemaComplete = (bool) ($databaseStatus['schema_complete'] ?? false);
        $databaseStatusHtml = !$allowAdminCreation
            ? '<div class="alert alert-info">Ein Administratorkonto ist bereits vorhanden. Fehlende Anwendungstabellen werden ergänzt; vorhandene Daten bleiben erhalten.</div>'
            : ($schemaComplete
            ? '<div class="alert alert-info">Das aktuelle Datenbankschema wurde erkannt. Du kannst die vorhandenen Daten behalten oder alle Anwendungstabellen neu anlegen lassen.</div>'
            : ($existingTableCount === 0
            ? '<div class="alert alert-info">Es wurden noch keine Anwendungstabellen gefunden. Beim Fortfahren werden das Datenbankschema und die Standardressourcen angelegt.</div>'
            : '<div class="alert alert-info">Erkannte Anwendungstabellen: ' . $existingTableCount . ' von ' . $tableCount . '. Du kannst die vorhandenen Daten behalten oder alle Anwendungstabellen neu anlegen.</div>'));
        $databaseChoice = $existingTableCount === 0 || !$allowAdminCreation
            ? '<input type="hidden" name="schema_action" value="keep">'
            : '<fieldset class="mb-4"><legend class="h5">Datenbank</legend>
  <div class="form-check mb-2"><input class="form-check-input" type="radio" id="setup-keep" name="schema_action" value="keep" checked><label class="form-check-label" for="setup-keep">Vorhandene Daten behalten und fehlende Tabellen ergänzen</label></div>
  <div class="form-check mb-2"><input class="form-check-input" type="radio" id="setup-recreate" name="schema_action" value="recreate"><label class="form-check-label" for="setup-recreate">Anwendungstabellen neu anlegen</label></div>
  <div class="alert alert-warning">Beim Neuaufbau werden alle Benutzer, Buchungen und Ressourcen in den Anwendungstabellen unwiderruflich gelöscht.</div>
  <div class="form-check"><input class="form-check-input" type="checkbox" id="setup-confirm-recreate" name="confirm_recreate" value="1"><label class="form-check-label" for="setup-confirm-recreate">Ich bestätige, dass die vorhandenen Anwendungsdaten gelöscht werden dürfen.</label></div>
</fieldset>';

        $accountForm = $allowAdminCreation
            ? '<h2 class="h5">2. Administratorkonto</h2>
  <div class="mb-3"><label class="form-label" for="setup-first-name">Vorname</label><input class="form-control" id="setup-first-name" name="first_name" maxlength="120" required></div>
  <div class="mb-3"><label class="form-label" for="setup-last-name">Nachname</label><input class="form-control" id="setup-last-name" name="last_name" maxlength="120"><div class="form-text">Optional.</div></div>
  <div class="mb-3"><label class="form-label" for="setup-email">E-Mail</label><input class="form-control" id="setup-email" type="email" name="email" maxlength="254" required></div>
  <div class="mb-3"><label class="form-label" for="setup-username">Benutzername</label><input class="form-control" id="setup-username" name="username" minlength="3" maxlength="80" pattern="[a-zA-Z0-9][a-zA-Z0-9._-]{2,79}" autocomplete="username" required><div class="form-text">3–80 Zeichen: Buchstaben, Zahlen, Punkt, Bindestrich oder Unterstrich.</div></div>
  <div class="mb-3"><label class="form-label" for="setup-password">Passwort (mindestens 12 Zeichen)</label><input class="form-control" id="setup-password" type="password" name="password" minlength="12" maxlength="200" autocomplete="new-password" required></div>'
            : '';
        $heading = $allowAdminCreation ? 'System einrichten' : 'Datenbankschema vervollständigen';
        $submitLabel = $allowAdminCreation ? 'System einrichten' : 'Datenbankschema vervollständigen';
        $description = $allowAdminCreation
            ? ($schemaComplete
                ? 'Das Datenbankschema ist bereits aktuell. Du kannst es beibehalten oder ausdrücklich neu aufsetzen.'
                : 'Der Assistent richtet bei Bedarf die Datenbank ein und erstellt das erste Administratorkonto.')
            : 'Der Assistent ergänzt fehlende Anwendungstabellen. Das vorhandene Administratorkonto und alle bestehenden Daten bleiben erhalten.';

        return '<section class="panel-card mx-auto p-4" style="max-width:680px"><h1 class="h3">' . $heading . '</h1><p>' . $description . ' Nach erfolgreicher Einrichtung wird dieser Assistent gesperrt.</p>' . $databaseStatusHtml . self::inlineError($error) . '
<form method="post" action="/?action=setup">
  <input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '">
  <h2 class="h5">1. Datenbank</h2>
  ' . $databaseChoice . '
  ' . $accountForm . '
  <button class="btn btn-primary" type="submit">' . $submitLabel . '</button>
</form></section>';
    }

    public static function invite(string $token, string $csrf, string $error = ''): string
    {
        return '<section class="panel-card mx-auto p-4" style="max-width:480px"><h1 class="h3">Konto einrichten</h1>' . self::inlineError($error) . '
<form method="post" action="/?action=invite-accept"><input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '"><input type="hidden" name="token" value="' . Security::escape($token) . '"><label class="form-label" for="new-password">Passwort (mindestens 12 Zeichen)</label><input id="new-password" class="form-control mb-3" type="password" name="password" minlength="12" autocomplete="new-password" required><button class="btn btn-primary" type="submit">Konto aktivieren</button></form></section>';
    }

    public static function forgot(string $csrf, string $error = ''): string
    {
        return '<section class="panel-card mx-auto p-4" style="max-width:480px"><h1 class="h3">Passwort zurücksetzen</h1>' . self::inlineError($error) . '
<form method="post" action="/?action=password-request"><input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '"><label class="form-label" for="reset-email">E-Mail</label><input id="reset-email" class="form-control mb-3" type="email" name="email" required><button class="btn btn-primary" type="submit">Link anfordern</button></form></section>';
    }

    public static function reset(string $token, string $csrf, string $error = ''): string
    {
        return '<section class="panel-card mx-auto p-4" style="max-width:480px"><h1 class="h3">Neues Passwort</h1>' . self::inlineError($error) . '
<form method="post" action="/?action=password-reset"><input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '"><input type="hidden" name="token" value="' . Security::escape($token) . '"><label class="form-label" for="new-password">Passwort (mindestens 12 Zeichen)</label><input id="new-password" class="form-control mb-3" type="password" name="password" minlength="12" autocomplete="new-password" required><button class="btn btn-primary" type="submit">Passwort speichern</button></form></section>';
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Offen',
            'confirmed' => 'Bestätigt',
            'rejected' => 'Abgelehnt',
            'withdrawn' => 'Zurückgezogen',
            'skipped' => 'Ausgelassen (Konflikt)',
            default => $status,
        };
    }

    private static function inlineError(string $error): string
    {
        return $error === ''
            ? ''
            : '<div class="alert alert-danger" role="alert">' . Security::escape($error) . '</div>';
    }
}
