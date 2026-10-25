import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { layoutDayEvents } from '../www/assets/js/calendar-layout.mjs';

const calendarCss = readFileSync(new URL('../www/assets/css/app.css', import.meta.url), 'utf8');

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

test('pitch booking cards remain absolutely positioned in the time grid', () => {
  const rule = calendarCss.match(/\.pitch-event\.booking-card\s*\{([^}]+)\}/);
  assert.ok(rule, 'pitch booking cards need a selector specific enough to override booking-card positioning');
  assert.match(rule[1], /position:\s*absolute/);
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
