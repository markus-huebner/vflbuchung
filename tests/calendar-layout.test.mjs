import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { eventDaySegment, layoutDayEvents } from '../www/assets/js/calendar-layout.mjs';

const calendarCss = readFileSync(new URL('../www/assets/css/app.css', import.meta.url), 'utf8');
const calendarJs = readFileSync(new URL('../www/assets/js/app.js', import.meta.url), 'utf8');
const views = readFileSync(new URL('../app/Views.php', import.meta.url), 'utf8');

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

test('calendar uses full width without a dashboard and a 75/25 split when one is available', () => {
  assert.match(calendarCss, /\.page-shell-wide\s*\{[^}]*width:\s*100%;[^}]*max-width:\s*none;/s);
  assert.match(calendarCss, /\.calendar-dashboard-layout\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)/s);
  assert.match(calendarCss, /\.calendar-dashboard-layout\.has-dashboard\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*3fr\)\s*minmax\(0,\s*1fr\)/s);
  assert.match(views, /data-calendar-dashboard-layout/);
  assert.match(views, /has-dashboard/);
  assert.match(views, /data-request-dashboard/);
  assert.match(views, /<h2 class="h4">Buchungsanfragen<\/h2>/);
  assert.match(views, /request-row-actions/);
  assert.match(calendarCss, /\.request-row-actions\s*\{[^}]*align-items:\s*flex-end/s);
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
  assert.ok(entries.every(entry => entry.columns === 3 && entry.top === 128 && entry.height === 93));
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
  assert.ok(entries.every(entry => entry.height === 32 && entry.columns === 2));
  noCoveredEntries(entries);
});

test('visible time limits clip spanning bookings and omit invisible ones', () => {
  const entries = layoutDayEvents([
    event(1, '07:00', '08:30'), event(2, '06:00', '08:00'),
    event(3, '19:30', '21:00'), event(4, '20:00', '21:00'),
  ], 8, 20);
  assert.deepEqual(entries.map(entry => entry.event.assignment_id), [1, 3]);
  assert.equal(entries[0].top, 0);
  assert.ok(entries.every(entry => entry.top + entry.height <= 12 * 64));
  assert.equal(layoutDayEvents([event(1, '07:00', '08:30')], 0, 24)[0].top, 7 * 64);
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
  assert.equal(allDayLayout[0].height, 12 * 64 - 3);
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
  assert.equal(entries[0].top, 128);
  assert.equal(entries[0].height, 61);
});

test('layout is deterministic and does not mutate the incoming calendar data', () => {
  const events = [event(3, '10:00', '11:00'), event(1, '10:00', '11:00'), event(2, '09:30', '10:30')];
  const before = structuredClone(events);
  assert.deepEqual(layoutDayEvents(events, 8, 20), layoutDayEvents([...events].reverse(), 8, 20));
  assert.deepEqual(events, before);
  assert.deepEqual(layoutDayEvents([], 8, 20), []);
});
