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
        $assetRoot = dirname(__DIR__) . '/www/assets/build';
        $assetVersion = is_file($assetRoot . '/app.js') ? (string) filemtime($assetRoot . '/app.js') : 'dev';
        $nav = '<a class="navbar-brand fw-semibold" href="/"><img class="navbar-brand-logo" src="/assets/images/vfl-kaufering-wappen.png" alt="Wappen des VfL Kaufering">Platz- und Busbelegung</a><div class="navbar-nav ms-auto flex-row align-items-center gap-3">';
        if ($user !== null) {
            $nav .= '<a class="nav-link" href="/?page=dashboard">Dashboard</a>';
            if ($user['role'] === 'admin') {
                $nav .= '<a class="nav-link" href="/?page=users">Benutzer</a><a class="nav-link" href="/?page=resources">Ressourcen</a>';
            }
            $nav .= '<span class="text-white-50 small">' . Security::escape($user['display_name']) . '</span>';
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
<main class="page-shell">' . $flashHtml . $content . '</main>
</body>
</html>';
    }

    public static function calendar(?array $user, string $csrf, array $resources): string
    {
        $bookingOptions = [
            ['event_type' => 'training', 'resource_mode' => 'pitch', 'label' => 'Platzbelegung'],
        ];
        $eventTypes = '<option value="training">Training</option><option value="match">Spiel</option>';
        if (in_array($user['role'] ?? '', ['manager', 'admin'], true)) {
            $eventTypes .= '<option value="external">Externe Belegung</option>';
        }
        $bookingOptions[] = ['event_type' => 'bus', 'resource_mode' => 'bus', 'label' => 'Fahrzeug'];

        $bookingChoices = '';
        foreach ($bookingOptions as $option) {
            $bookingChoices .= '<button type="button" class="booking-create-option" data-booking-option'
                . ' data-event-type="' . Security::escape($option['event_type']) . '"'
                . ' data-resource-mode="' . Security::escape($option['resource_mode']) . '">'
                . Security::escape($option['label']) . '</button>';
        }
        $createButton = $user === null ? '' : '<details id="booking-create-menu" class="booking-create-menu">'
            . '<summary id="open-booking" class="btn btn-primary">+ Buchung anlegen</summary>'
            . '<div class="booking-create-options" aria-label="Buchungsart auswählen">' . $bookingChoices . '</div>'
            . '</details>';

        $bookingDialog = $user === null ? '' : self::bookingDialog($user, $csrf, $eventTypes);
        $detailsDialog = self::eventDialog($user, $csrf);

        return '<div id="app-message" class="alert" role="status" hidden></div>
<section class="calendar-toolbar">
  <div><h1>Belegungsplan</h1><p class="text-secondary mb-0">Plätze mit ihren Kabinen und Fahrzeuge auf einen Blick.</p></div>
  <div class="calendar-actions">
    ' . $createButton . '
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
' . $bookingDialog . $detailsDialog . self::recurrenceScopeDialog();
    }

    private static function bookingDialog(array $user, string $csrf, string $eventTypes): string
    {
        return '<dialog id="booking-dialog" aria-labelledby="booking-dialog-title">
<form id="booking-form" method="post">
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
  <div class="dialog-footer"><button type="button" class="btn btn-outline-secondary" data-dialog-close data-booking-close>Abbrechen</button><button type="submit" class="btn btn-primary" data-booking-submit>Speichern</button></div>
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
            ? '<form method="post" action="/?action=booking-cancel" data-cancel-form><input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '"><input type="hidden" name="assignment_id" value=""><input type="hidden" name="scope" value="one"><button class="btn btn-outline-danger" type="submit">Stornieren</button></form><form method="post" action="/?action=booking-cabin-cancel" data-cancel-cabin-form hidden><input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '"><input type="hidden" name="occurrence_id" value=""><input type="hidden" name="cabin_id" value=""><input type="hidden" name="scope" value="one"><button class="btn btn-outline-danger" type="submit">Kabinenbelegung stornieren</button></form>'
            : '';
        $edit = in_array($user['role'] ?? '', ['admin', 'manager'], true)
            ? '<button type="button" class="btn btn-outline-primary" data-edit-event>Ändern</button><a class="btn btn-outline-primary" href="/?page=dashboard" data-event-request hidden>Anfragen verwalten</a>'
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
  <div class="dialog-footer">' . $cancel . $edit . '<button type="button" class="btn btn-primary" data-dialog-close>Schließen</button></div>
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

    public static function dashboard(?array $user, array $requests, string $csrf, string $error = ''): string
    {
        if ($user === null) {
            return '';
        }

        $staff = in_array($user['role'], ['admin', 'manager'], true);
        $rows = '';
        foreach ($requests as $request) {
            $status = self::statusLabel($request['assignment_status']);
            $details = [
                $request['resource_name'],
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
                ->format('H:i');
            $startInput = Recurrence::fromDatabase($request['starts_at'])
                ->setTimezone(new \DateTimeZone('Europe/Berlin'))
                ->format('Y-m-d\TH:i');
            $endInput = Recurrence::fromDatabase($request['ends_at'])
                ->setTimezone(new \DateTimeZone('Europe/Berlin'))
                ->format('Y-m-d\TH:i');
            $requestTitle = (string) ($request['title'] ?? '');
            $requestTeamName = (string) ($request['team_name'] ?? '');
            $combinedTitle = implode(' – ', array_filter(
                [$requestTeamName, $requestTitle],
                static fn (string $part): bool => trim($part) !== ''
            ));
            $titleInputId = 'request-title-' . (int) $request['assignment_id'];
            $allowTeamName = in_array($request['event_type'], ['training', 'match'], true);
            $titleLabel = 'Mannschaft / Hinweis';
            $rows .= '<article class="request-row"><div class="d-flex flex-wrap justify-content-between gap-2"><div><strong class="booking-title">' . Security::escape($request['title']) . '</strong> <span class="request-status">' . Security::escape($status) . '</span><div class="request-details">' . Security::escape($date . '–' . $end) . ' · ' . Security::escape(implode(' · ', array_filter($details))) . '</div>';
            if ($staff) {
                $rows .= '<div class="small text-secondary">Anfrage von ' . Security::escape($request['requester_name'] ?? 'Verwalter') . '</div>';
            }
            if (($request['rejection_reason'] ?? '') !== '') {
                $rows .= '<div class="small text-danger">Begründung: ' . Security::escape($request['rejection_reason']) . '</div>';
            }
            if (!$staff && $request['assignment_status'] === 'pending') {
                $rows .= '<details class="mt-2"><summary>Anfrage ändern</summary>'
                    . '<form method="post" action="/?action=request-edit" class="request-edit-form mt-2"'
                    . ' data-allow-team-name="' . ($allowTeamName ? 'true' : 'false') . '">'
                    . '<input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '">'
                    . '<input type="hidden" name="assignment_id" value="' . (int) $request['assignment_id'] . '">'
                    . '<input type="hidden" name="title" value="' . Security::escape($requestTitle) . '">'
                    . '<input type="hidden" name="team_name" value="' . Security::escape($requestTeamName) . '">'
                    . '<div class="mb-2"><label class="form-label" for="' . $titleInputId . '">'
                    . Security::escape($titleLabel) . '</label><textarea class="form-control form-control-sm"'
                    . ' id="' . $titleInputId . '" name="combined_title" maxlength="303" rows="2" required'
                    . ' data-required-non-whitespace data-initial-value="' . Security::escape($combinedTitle) . '">'
                    . Security::escape($combinedTitle) . '</textarea></div>'
                    . self::bookingDateTimeFields('request-' . (int) $request['assignment_id'], $startInput, $endInput)
                    . '<button class="btn btn-sm btn-outline-primary mt-2"'
                    . ' type="submit">Änderung speichern</button></form></details>';
            }
            $rows .= '</div><div class="d-flex align-items-start flex-wrap gap-2">';

            if ($staff && $request['assignment_status'] === 'pending') {
                $rows .= '<form method="post" action="/?action=booking-approve" class="d-flex gap-2 align-items-center"><input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '"><input type="hidden" name="assignment_id" value="' . (int) $request['assignment_id'] . '">';
                $rows .= '<button class="btn btn-sm btn-success" type="submit">Freigeben</button></form>
<form method="post" action="/?action=booking-reject" class="d-flex gap-2"><input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '"><input type="hidden" name="assignment_id" value="' . (int) $request['assignment_id'] . '"><input class="form-control form-control-sm" name="reason" maxlength="500" placeholder="Begründung (optional)"><button class="btn btn-sm btn-outline-danger" type="submit">Ablehnen</button></form>';
            } elseif (!$staff && $request['assignment_status'] === 'pending') {
                $rows .= '<form method="post" action="/?action=booking-withdraw"><input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '"><input type="hidden" name="assignment_id" value="' . (int) $request['assignment_id'] . '"><button class="btn btn-sm btn-outline-secondary" type="submit">Zurückziehen</button></form>';
            }
            $rows .= '</div></div></article>';
        }

        $empty = $staff ? 'Es gibt keine offenen Anfragen.' : 'Du hast noch keine Anfragen.';
        $description = $staff
            ? 'Offene Traineranfragen und ihre Freigaben.'
            : 'Deine Anfragen und deren aktueller Status.';

        return '<section class="panel-card p-4">
<h1 class="h3">Dashboard</h1><p class="text-secondary">' . $description . '</p>
' . self::inlineError($error) . '
<div id="request-list">' . ($rows !== '' ? $rows : '<p class="mb-0">' . $empty . '</p>') . '</div>
<p class="mt-3 mb-0"><a class="btn btn-primary" href="/">Zum Belegungsplan</a></p></section>';
    }

    public static function users(array $users, string $csrf, string $error = '', string $form = ''): string
    {
        $rows = '';
        foreach ($users as $user) {
            $id = (int) $user['id'];
            $roleLabels = ['admin' => 'Admin', 'manager' => 'Verwalter', 'trainer' => 'Trainer'];
            $statusLabels = ['active' => 'Aktiv', 'invited' => 'Eingeladen', 'inactive' => 'Deaktiviert'];
            $rows .= '<tr><td class="fw-semibold">' . Security::escape($user['display_name'])
                . '</td><td>' . Security::escape((string) ($user['email'] ?? '—'))
                . '</td><td>' . Security::escape((string) ($user['username'] ?? '—'))
                . '</td><td><form method="post" action="/?action=user-role" class="d-flex gap-2">'
                . '<input type="hidden" name="_csrf" value="' . Security::escape($csrf)
                . '"><input type="hidden" name="id" value="' . $id
                . '"><select class="form-select form-select-sm" name="role" aria-label="Rolle für '
                . Security::escape($user['display_name']) . '">';
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
                . ' data-user-id="' . $id . '" data-user-name="' . Security::escape($user['display_name'])
                . '">Löschen</button></div></td></tr>';
        }

        $empty = $rows === '' ? '<tr><td colspan="6" class="text-center text-secondary py-4">'
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
            . '<thead><tr><th>Name</th><th>E-Mail</th><th>Benutzername</th><th>Rolle</th><th>Status</th>'
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

    private static function inviteUserForm(string $csrf): string
    {
        return '<a class="btn btn-outline-secondary mb-3" href="/?page=users">Zur Benutzerliste</a>'
            . '<section class="panel-card mx-auto p-4" style="max-width:640px">'
            . '<h2 class="h4">Benutzer per E-Mail einladen</h2>'
            . '<p class="text-secondary">Die eingeladene Person legt ihr Passwort über den Einladungslink selbst fest.</p>'
            . '<form method="post" action="/?action=user-invite">'
            . '<input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '">'
            . '<div class="mb-3"><label class="form-label" for="invite-user-name">Name</label>'
            . '<input class="form-control" id="invite-user-name" name="display_name" maxlength="120" required></div>'
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
            . '<div class="mb-3"><label class="form-label" for="create-user-name">Name</label>'
            . '<input class="form-control" id="create-user-name" name="display_name" maxlength="120" required></div>'
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

    public static function setup(string $csrf, string $error = ''): string
    {
        return '<section class="panel-card mx-auto p-4" style="max-width:560px"><h1 class="h3">Ersten Admin einrichten</h1><p>Der Setup-Schlüssel ist erforderlich. Nach dem Anlegen des Kontos ist dieser Assistent gesperrt.</p>' . self::inlineError($error) . '
<form method="post" action="/?action=setup"><input type="hidden" name="_csrf" value="' . Security::escape($csrf) . '"><div class="mb-3"><label class="form-label" for="setup-token">Setup-Schlüssel</label><input class="form-control" id="setup-token" type="password" name="setup_token" autocomplete="off" required></div><div class="mb-3"><label class="form-label" for="setup-name">Name</label><input class="form-control" id="setup-name" name="display_name" maxlength="120" required></div><div class="mb-3"><label class="form-label" for="setup-email">E-Mail</label><input class="form-control" id="setup-email" type="email" name="email" maxlength="254" required></div><div class="mb-3"><label class="form-label" for="setup-username">Benutzername</label><input class="form-control" id="setup-username" name="username" minlength="3" maxlength="80" pattern="[a-zA-Z0-9][a-zA-Z0-9._-]{2,79}" autocomplete="username" required><div class="form-text">3–80 Zeichen: Buchstaben, Zahlen, Punkt, Bindestrich oder Unterstrich.</div></div><div class="mb-3"><label class="form-label" for="setup-password">Passwort (mindestens 12 Zeichen)</label><input class="form-control" id="setup-password" type="password" name="password" minlength="12" maxlength="200" autocomplete="new-password" required></div><button class="btn btn-primary" type="submit">Admin-Konto erstellen</button></form></section>';
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
