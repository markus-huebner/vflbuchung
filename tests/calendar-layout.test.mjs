import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import {
  DEFAULT_DAY_END_HOUR,
  DEFAULT_DAY_START_HOUR,
  DAY_HOUR_HEIGHT,
  eventDaySegment,
  layoutDayEvents,
} from '../www/assets/js/calendar-layout.mjs';

const calendarCss = readFileSync(new URL('../www/assets/css/app.css', import.meta.url), 'utf8');
const calendarJs = readFileSync(new URL('../www/assets/js/app.js', import.meta.url), 'utf8');
const views = readFileSync(new URL('../www/app/Views.php', import.meta.url), 'utf8');

function event(id, start, end, status = 'confirmed') {
  return {
    assignment_id: id, start: `2026-10-24T${start}:00+02:00`, end: `2026-10-24T${end}:00+02:00`, status,
  };
}

function noCoveredEntries(entries) {
  for (const a of entries) {
    for (const b of entries) {
      if (a === b) continue;
      if (a.top < b.top + b.height && b.top < a.top + a.height) {
        assert.notEqual(a.column, b.column, `Events ${a.event.assignment_id} and ${b.event.assignment_id} cover each other`);
        assert.equal(a.columns, b.columns);
      }
    }
  }
}

test('resource booking cards remain absolutely positioned in the time grid', () => {
  const rule = calendarCss.match(/\.resource-event\.booking-card\s*\{([^}]+)\}/);
  assert.ok(rule, 'resource booking cards need a selector specific enough to override booking-card positioning');
  assert.match(rule[1], /position:\s*absolute/);
});

test('week calendar uses fixed, equally sized day columns', () => {
  const rule = calendarCss.match(/\.resource-week-table\s*\{([^}]+)\}/);
  assert.ok(rule, 'week calendar needs an explicit table layout rule');
  assert.match(rule[1], /width:\s*100%/);
  assert.match(rule[1], /table-layout:\s*fixed/);
  assert.doesNotMatch(rule[1], /min-width:\s*\d/);
  assert.match(calendarCss, /\.resource-week-table \.resource-week-resource-column\s*\{[^}]*width:\s*150px;/s);
});

test('day calendar columns share available width without imposing horizontal scrolling', () => {
  const rule = calendarCss.match(/\.resource-day-grid\s*\{([^}]+)\}/);
  assert.ok(rule, 'day calendar needs an explicit grid layout rule');
  assert.match(rule[1], /grid-template-columns:\s*var\(--axis-width\)\s*repeat\(var\(--resource-count\),\s*minmax\(0,\s*1fr\)\)/);
  assert.match(rule[1], /min-width:\s*0/);
  assert.match(calendarJs, /repeat\(\$\{Math\.max\(1,\s*resources\.length\)\},\s*minmax\(0,\s*1fr\)\)/);
  assert.doesNotMatch(calendarJs, /grid\.style\.minWidth/);
});

test('week calendar labels wrap inside narrow day columns', () => {
  const rule = calendarCss.match(/\.booking-category,\s*\.booking-cabin\s*\{([^}]+)\}/);
  assert.ok(rule, 'event labels need a shared wrapping rule');
  assert.match(rule[1], /overflow-wrap:\s*anywhere/);
});

test('week calendar narrows its resource label column on small screens', () => {
  const mediaRule = calendarCss.match(/@media\s*\(max-width:\s*375px\)\s*\{([\s\S]*?)\n\}/);
  assert.ok(mediaRule, 'week calendar needs a narrow-screen layout');

  const resourceColumnRule = mediaRule[1].match(/\.resource-week-table \.resource-week-resource-column\s*\{([^}]+)\}/);
  assert.ok(resourceColumnRule, 'week calendar resource column needs a narrow-screen width');
  assert.match(resourceColumnRule[1], /width:\s*90px/);

  const resourceHeadingRule = mediaRule[1].match(/\.resource-week-table tbody th\s*\{([^}]+)\}/);
  assert.ok(resourceHeadingRule, 'week calendar resource labels need to wrap on small screens');
  assert.match(resourceHeadingRule[1], /min-width:\s*0/);
  assert.match(resourceHeadingRule[1], /white-space:\s*normal/);
});

test('vehicle calendar uses the shared resource day and week layouts', () => {
  assert.match(views, /<div data-calendar-view="bus" hidden>\s*<div id="bus-day" class="resource-day-scroll"><\/div>\s*<div id="bus-week" class="resource-week-scroll" hidden><\/div>\s*<\/div>/);
  assert.match(calendarJs, /function renderResourceDay\(\)/);
  assert.match(calendarJs, /function renderResourceWeek\(\)/);
  assert.match(calendarJs, /eventDaySegment\(event,\s*date\)/);
});

test('resource calendars default to 09:00–21:00 and can show the full day', () => {
  assert.equal(DEFAULT_DAY_START_HOUR, 9);
  assert.equal(DEFAULT_DAY_END_HOUR, 21);
  assert.match(calendarJs, /state\.showFullDay \? 0 : DEFAULT_DAY_START_HOUR/);
  assert.match(calendarJs, /state\.showFullDay \? 24 : DEFAULT_DAY_END_HOUR/);

  const entries = layoutDayEvents([
    event(1, '08:30', '09:30'),
    event(2, '20:30', '21:30'),
    event(3, '21:00', '22:00'),
    event(4, '07:30', '09:00'),
    event(5, '19:00', '20:00'),
  ], DEFAULT_DAY_START_HOUR, DEFAULT_DAY_END_HOUR);
  assert.deepEqual(entries.map(entry => entry.event.assignment_id), [1, 5, 2]);
  assert.equal(entries[0].top, 0);
  assert.ok(entries.every(entry => entry.top + entry.height <= 12 * DAY_HOUR_HEIGHT));
});

test('hour rows and event positions use exactly twenty percent less height', () => {
  assert.equal(DAY_HOUR_HEIGHT, 64 * 0.8);
  assert.match(calendarJs, /grid\.style\.setProperty\('--calendar-hour-height', `\$\{DAY_HOUR_HEIGHT\}px`\)/);
  assert.match(calendarJs, /hourCount \* DAY_HOUR_HEIGHT/);
  for (const selector of ['resource-hour', 'resource-hour-line']) {
    const rule = calendarCss.match(new RegExp(`\\.${selector}\\s*\\{([^}]+)\\}`));
    assert.match(rule[1], /height:\s*var\(--calendar-hour-height\)/);
  }
  const [entry] = layoutDayEvents([event(1, '10:00', '11:00')], 9, 21);
  assert.equal(entry.top, DAY_HOUR_HEIGHT);
  assert.equal(entry.height, DAY_HOUR_HEIGHT - 3);
});

test('request selection marks every related calendar entry without selecting its resource heading', () => {
  assert.match(views, /data-occurrence-id="'\s*\.\s*\(int\) \$request\['occurrence_id'\]/);
  assert.match(calendarJs, /wrapper\.dataset\.occurrenceId = event\.occurrence_id/);
  assert.match(calendarJs, /\.filter\(\(card\) => card\.dataset\.occurrenceId === button\.dataset\.occurrenceId\)/);
  assert.match(calendarJs, /booking\.closest\('\.resource-lane'\)\?\.classList\.add\('resource-lane-selected'\)/);
  assert.match(calendarJs, /button\.closest\('\.request-row'\)\?\.classList\.add\('request-row-selected'\)/);
  assert.match(calendarJs, /bookings\.forEach\(\(booking\) => \{\s*booking\.classList\.add\('booking-card-highlight'\);\s*booking\.closest\('\.resource-lane'\)\?\.classList\.add\('resource-lane-selected'\);/);
  assert.ok(calendarJs.indexOf('bookings.forEach((booking)') < calendarJs.indexOf('bookings[0].focus({ preventScroll: true })'));
  assert.doesNotMatch(calendarJs, /\.resource-column-selected/);
  assert.match(calendarCss, /\.booking-card-highlight\s*\{[^}]*box-shadow:\s*inset 0 0 0 4px #f2b632/s);
  assert.doesNotMatch(calendarCss, /\.booking-card-highlight\s*\{[^}]*outline:/s);
  const requestSelection = calendarCss.match(/\.request-row-selected\s*\{([^}]+)\}/);
  assert.ok(requestSelection);
  assert.match(requestSelection[1], /background-color:\s*#fff9e6/);
  assert.doesNotMatch(requestSelection[1], /border|outline|box-shadow/);
  assert.match(calendarCss, /\.resource-lane-selected\s*\{[^}]*background:\s*repeating-linear-gradient\([\s\S]*?#fff9e6/s);
  assert.doesNotMatch(calendarCss, /\.resource-column-selected\s+\.resource-column-heading/);
  assert.doesNotMatch(calendarCss, /@keyframes booking-card-highlight/);
});

test('calendar dashboard has an adjustable split that remains usable on narrow screens', () => {
  assert.match(calendarCss, /\.page-shell-wide\s*\{[^}]*width:\s*100%;[^}]*max-width:\s*none;/s);
  assert.match(calendarCss, /\.calendar-dashboard-layout\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)/s);
  const dashboardLayout = calendarCss.match(/\.calendar-dashboard-layout\.has-dashboard\s*\{([^}]+)\}/);
  assert.ok(dashboardLayout);
  assert.match(dashboardLayout[1], /minmax\(360px,\s*var\(--calendar-main-fr,\s*3fr\)\)\s*12px\s*minmax\(240px,\s*var\(--calendar-dashboard-fr,\s*1fr\)\)/);
  assert.match(dashboardLayout[1], /align-items:\s*stretch/);
  assert.match(calendarCss, /\.calendar-dashboard-resizer\s*\{[^}]*cursor:\s*col-resize/);
  assert.match(calendarCss, /@media \(max-width:\s*1000px\)[\s\S]*?\.calendar-dashboard-resizer\s*\{[^}]*display:\s*none/s);
  const requestPanel = calendarCss.match(/\.panel-card\.calendar-dashboard-panel\s*\{([^}]+)\}/);
  assert.ok(requestPanel);
  assert.match(requestPanel[1], /overflow:\s*auto/);
  assert.doesNotMatch(requestPanel[1], /max-height:/);
  assert.match(views, /data-calendar-dashboard-layout/);
  assert.match(views, /data-dashboard-resizer role="separator"/);
  assert.match(views, /has-dashboard/);
  assert.match(views, /data-request-dashboard/);
  assert.match(calendarJs, /initDashboardResizer\(\)/);
  assert.match(calendarJs, /sessionStorage\.getItem\(dashboardMainShareStorageKey\)/);
  assert.match(calendarJs, /sessionStorage\.setItem\(dashboardMainShareStorageKey, String\(mainShare\)\)/);
  assert.match(calendarJs, /resizer\.addEventListener\('pointerdown'/);
  assert.match(calendarJs, /resizer\.addEventListener\('pointermove'/);
  assert.match(calendarJs, /resizer\.addEventListener\('keydown'/);
  assert.match(views, /<h2 class="h4">Buchungsanfragen<\/h2>/);
  assert.match(views, /request-row-actions/);
  assert.match(calendarCss, /\.request-row-actions\s*\{[^}]*display:\s*grid;[^}]*width:\s*max-content;[^}]*grid-template-columns:\s*max-content;[^}]*align-items:\s*stretch/s);
  assert.match(calendarJs, /const shouldRefreshRequestDashboard = hasRequestList && \(isRequestEdit \|\| result\.pending\)/);
  assert.match(calendarJs, /await Promise\.all\(updates\)/);
});

test('calendar resource selection persists in the current window session', () => {
  assert.match(calendarJs, /sessionStorage\.getItem\(calendarResourceTypeStorageKey\)/);
  assert.match(calendarJs, /savedResourceType === 'pitch' \|\| savedResourceType === 'bus'/);
  assert.match(calendarJs, /sessionStorage\.setItem\(calendarResourceTypeStorageKey, state\.resourceType\)/);
  assert.match(views, /id="calendar-resource-switcher"/);
  assert.match(views, /data-calendar-resource="pitch" aria-label="Platzbelegung" aria-pressed="true"/);
  assert.match(views, /data-calendar-resource="bus" aria-label="Fahrzeugkalender" aria-pressed="false"/);
  assert.doesNotMatch(views, /id="calendar-actions-menu"[\s\S]*?data-calendar-resource/);
  assert.match(calendarJs, /button\.setAttribute\('aria-pressed', String\(button\.dataset\.calendarResource === state\.resourceType\)\)/);
  assert.match(calendarCss, /\.request-row-actions\s+\.btn\s*\{[^}]*width:\s*100%/s);
  assert.doesNotMatch(views, /name="reason"|placeholder="Begründung/);
  assert.doesNotMatch(views, /data-dashboard-toggle|Dashboard ausblenden|Dashboard einblenden/);
  assert.doesNotMatch(calendarJs, /data-dashboard-toggle/);
  assert.match(calendarJs, /data-dashboard-jump/);
  assert.match(calendarJs, /data-event-approve/);
  assert.match(calendarJs, /data-event-reject/);
});

test('identical bookings and requests receive separate equally wide columns', () => {
  const entries = layoutDayEvents([
    event(1, '10:00', '11:30'), event(2, '10:00', '11:30', 'pending'), event(3, '10:00', '11:30'),
  ], 8, 20);
  assert.equal(entries.length, 3);
  assert.deepEqual(entries.map(entry => entry.column), [0, 1, 2]);
  assert.ok(entries.every(entry => entry.columns === 3 && entry.top === 2 * DAY_HOUR_HEIGHT && entry.height === 1.5 * DAY_HOUR_HEIGHT - 3));
  noCoveredEntries(entries);
});

test('nested and chained overlaps stay accessible and reuse free columns', () => {
  const entries = layoutDayEvents([
    event(1, '09:00', '12:00'), event(2, '09:30', '10:30'),
    event(3, '10:00', '11:00'), event(4, '11:00', '13:00'), event(5, '12:30', '14:00'),
    event(6, '15:00', '16:00'),
  ], 8, 20);
  noCoveredEntries(entries);
  assert.ok(entries.slice(0, 5).every(entry => entry.columns === 3));
  assert.equal(entries[5].columns, 1);
  assert.equal(entries[5].column, 0);
});

test('adjacent long bookings occupy the full lane rather than overlapping', () => {
  const entries = layoutDayEvents([event(1, '10:00', '11:00'), event(2, '11:00', '12:00')], 8, 20);
  assert.ok(entries.every(entry => entry.columns === 1));
  noCoveredEntries(entries);
});

test('minimum card height cannot cover the next short booking', () => {
  const entries = layoutDayEvents([
    event(1, '10:00', '10:15'), event(2, '10:15', '10:30'), event(3, '10:30', '10:45'),
  ], 8, 20);
  assert.ok(entries.every(entry => entry.height === DAY_HOUR_HEIGHT / 2 && entry.columns === 2));
  noCoveredEntries(entries);
});

test('visible time limits clip spanning bookings and omit invisible ones', () => {
  const entries = layoutDayEvents([
    event(1, '07:00', '08:30'), event(2, '06:00', '08:00'),
    event(3, '19:30', '21:00'), event(4, '20:00', '21:00'),
  ], 8, 20);
  assert.deepEqual(entries.map(entry => entry.event.assignment_id), [1, 3]);
  assert.equal(entries[0].top, 0);
  assert.ok(entries.every(entry => entry.top + entry.height <= 12 * DAY_HOUR_HEIGHT));
  assert.equal(layoutDayEvents([event(1, '07:00', '08:30')], 0, 24)[0].top, 7 * DAY_HOUR_HEIGHT);
});

test('multi-day events are clipped to each overlapping resource calendar day', () => {
  const event = {
    start: new Date(2026, 9, 25, 22).toISOString(),
    end: new Date(2026, 9, 27, 2).toISOString(),
  };
  const date = new Date(2026, 9, 26, 12);

  const middleSegment = eventDaySegment(event, date);
  assert.deepEqual(middleSegment, {
    start: new Date(2026, 9, 26, 0).toISOString(),
    end: new Date(2026, 9, 27, 0).toISOString(),
    allDay: true,
  });
  const allDayLayout = layoutDayEvents([{ ...event, ...middleSegment, assignment_id: 1 }], 8, 20);
  assert.equal(allDayLayout.length, 1);
  assert.equal(allDayLayout[0].top, 0);
  assert.equal(allDayLayout[0].height, 12 * DAY_HOUR_HEIGHT - 3);
  assert.deepEqual(eventDaySegment(event, new Date(2026, 9, 25, 12)), {
    start: event.start,
    end: new Date(2026, 9, 26, 0).toISOString(),
    allDay: false,
  });
  assert.deepEqual(eventDaySegment(event, new Date(2026, 9, 27, 12)), {
    start: new Date(2026, 9, 27, 0).toISOString(),
    end: event.end,
    allDay: false,
  });
  assert.equal(eventDaySegment(event, new Date(2026, 9, 28, 12)), null);
});

test('positions use Berlin wall time regardless of timestamp offset or browser timezone', () => {
  const entries = layoutDayEvents([{
    assignment_id: 1, start: '2026-10-24T08:00:00Z', end: '2026-10-24T09:00:00Z',
  }], 8, 20);
  assert.equal(entries[0].top, 2 * DAY_HOUR_HEIGHT);
  assert.equal(entries[0].height, DAY_HOUR_HEIGHT - 3);
});

test('layout is deterministic and does not mutate the incoming calendar data', () => {
  const events = [event(3, '10:00', '11:00'), event(1, '10:00', '11:00'), event(2, '09:30', '10:30')];
  const before = structuredClone(events);
  assert.deepEqual(layoutDayEvents(events, 8, 20), layoutDayEvents([...events].reverse(), 8, 20));
  assert.deepEqual(events, before);
  assert.deepEqual(layoutDayEvents([], 8, 20), []);
});
