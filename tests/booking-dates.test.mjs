import assert from 'node:assert/strict';
import test from 'node:test';
import { initBookingDates } from '../www/assets/js/booking-dates.mjs';

class Field extends EventTarget {
  constructor(value = '') {
    super();
    this.value = value;
    this.validationMessage = '';
  }

  setCustomValidity(message) {
    this.validationMessage = message;
  }
}

function fixture(start = '', end = '') {
  const fields = {
    date: new Field(start.slice(0, 10)),
    startTime: new Field(start.slice(11, 16)),
    endTime: new Field(end.slice(11, 16)),
  };
  const selectors = {
    '[data-booking-date]': fields.date,
    '[data-booking-time="start"]': fields.startTime,
    '[data-booking-time="end"]': fields.endTime,
  };
  const form = {
    elements: { starts_at: new Field(start), ends_at: new Field(end) },
    querySelector: selector => selectors[selector],
  };
  const dates = initBookingDates(form);
  function change(field, value, event = 'input') {
    fields[field].value = value;
    fields[field].dispatchEvent(new Event(event));
  }
  return { fields, form, dates, change };
}

test('new bookings default to 90 minutes and populate separate date/time fields', () => {
  const { fields, form, dates } = fixture();
  assert.equal(form.elements.starts_at.value, '');
  assert.equal(form.elements.ends_at.value, '');
  dates.setRange('2026-10-24T15:00');
  assert.equal(fields.date.value, '2026-10-24');
  assert.equal(fields.startTime.value, '15:00');
  assert.equal(fields.endTime.value, '16:30');
  assert.equal(form.elements.starts_at.value, '2026-10-24T15:00');
  assert.equal(form.elements.ends_at.value, '2026-10-24T16:30');
});

test('keyboard-entered minute-accurate start times update the end and submitted values', () => {
  const { form, dates, change } = fixture();
  dates.setRange('2026-10-24T15:00');
  change('startTime', '17:07');
  assert.equal(form.elements.starts_at.value, '2026-10-24T17:07');
  assert.equal(form.elements.ends_at.value, '2026-10-24T18:37');
});

test('changing the shared date updates both submitted timestamps', () => {
  const { fields, form, change } = fixture('2026-10-24T15:00', '2026-10-24T16:30');
  change('date', '2026-11-03', 'change');
  assert.equal(fields.date.value, '2026-11-03');
  assert.equal(fields.endTime.value, '16:30');
  assert.equal(form.elements.starts_at.value, '2026-11-03T15:00');
  assert.equal(form.elements.ends_at.value, '2026-11-03T16:30');
});

test('a manually changed end time defines the duration for subsequent start changes', () => {
  const { form, dates, change } = fixture();
  dates.setRange('2026-10-24T15:00');
  change('endTime', '17:00');
  change('startTime', '18:00');
  assert.equal(form.elements.ends_at.value, '2026-10-24T20:00');
});

test('existing bookings retain their duration when they are moved', () => {
  const { form, change } = fixture('2026-10-24T10:00', '2026-10-24T13:00');
  change('startTime', '11:15');
  assert.equal(form.elements.ends_at.value, '2026-10-24T14:15');
});

test('the end timestamp always uses the shared date', () => {
  const { form, fields, dates } = fixture('2026-10-24T10:00', '2026-10-26T11:30');
  dates.setRange('2026-10-24T10:00', '2026-10-26T11:30');
  assert.equal(fields.date.value, '2026-10-24');
  assert.equal(form.elements.starts_at.value, '2026-10-24T10:00');
  assert.equal(form.elements.ends_at.value, '2026-10-24T11:30');
});

test('a duration that crosses midnight keeps the end on the shared date and is invalid', () => {
  const { fields, form, dates } = fixture();
  dates.setRange('2026-10-24T23:30');
  assert.equal(form.elements.starts_at.value, '2026-10-24T23:30');
  assert.equal(form.elements.ends_at.value, '2026-10-24T01:00');
  assert.equal(fields.endTime.validationMessage, 'Das Ende muss nach dem Beginn liegen.');
});

test('clearing and restoring the start date does not submit stale values or lose the duration', () => {
  const { form, change } = fixture('2026-10-24T15:00', '2026-10-24T17:00');
  change('date', '');
  assert.equal(form.elements.starts_at.value, '');
  change('date', '2026-11-03');
  assert.equal(form.elements.starts_at.value, '2026-11-03T15:00');
  assert.equal(form.elements.ends_at.value, '2026-11-03T17:00');
});

test('clearing and restoring the start time preserves the last valid duration', () => {
  const { form, change } = fixture('2026-10-24T15:00', '2026-10-24T16:30');
  change('startTime', '');
  assert.equal(form.elements.starts_at.value, '');
  change('startTime', '18:00');
  assert.equal(form.elements.ends_at.value, '2026-10-24T19:30');
});

test('an invalid end is preserved for correction and blocks submission', () => {
  const { fields, form, change } = fixture('2026-10-24T15:00', '2026-10-24T16:30');
  for (const end of ['15:00', '14:00']) {
    change('endTime', end);
    assert.equal(form.elements.ends_at.value, `2026-10-24T${end}`);
    assert.equal(fields.endTime.validationMessage, 'Das Ende muss nach dem Beginn liegen.');
  }
  change('startTime', '17:00');
  assert.equal(form.elements.ends_at.value, '2026-10-24T18:30');
  assert.equal(fields.endTime.validationMessage, '');
});

test('clearing the end clears its submitted value without corrupting the next start change', () => {
  const { form, change } = fixture('2026-10-24T15:00', '2026-10-24T16:30');
  change('endTime', '');
  assert.equal(form.elements.ends_at.value, '');
  change('startTime', '18:00');
  assert.equal(form.elements.ends_at.value, '2026-10-24T19:30');
});

test('input followed by change does not apply the time shift twice', () => {
  const { form, change } = fixture('2026-10-24T15:00', '2026-10-24T16:30');
  change('startTime', '16:00');
  change('startTime', '16:00', 'change');
  assert.equal(form.elements.ends_at.value, '2026-10-24T17:30');
});

test('opening another new booking resets a custom duration to 90 minutes', () => {
  const { form, dates, change } = fixture();
  dates.setRange('2026-10-24T15:00');
  change('endTime', '18:00');
  dates.setRange('2026-11-03T10:00');
  assert.equal(form.elements.ends_at.value, '2026-11-03T11:30');
});

test('each booking form maintains its own duration', () => {
  const first = fixture('2026-10-24T15:00', '2026-10-24T16:30');
  const second = fixture('2026-10-24T10:00', '2026-10-24T14:00');
  first.change('endTime', '17:00');
  second.change('startTime', '11:00');
  first.change('startTime', '18:00');
  assert.equal(first.form.elements.ends_at.value, '2026-10-24T20:00');
  assert.equal(second.form.elements.ends_at.value, '2026-10-24T15:00');
});

test('time inputs with zero seconds retain the minute-based API format', () => {
  const { form, change } = fixture('2026-10-24T15:00', '2026-10-24T16:30');
  change('endTime', '17:00:00');
  change('startTime', '18:00:00');
  assert.equal(form.elements.starts_at.value, '2026-10-24T18:00');
  assert.equal(form.elements.ends_at.value, '2026-10-24T20:00');
});

test('date arithmetic is independent of the browser timezone and daylight saving changes', () => {
  const previousTimezone = process.env.TZ;
  try {
    for (const timezone of ['Europe/Berlin', 'America/New_York', 'Pacific/Auckland']) {
      process.env.TZ = timezone;
      const { form, change } = fixture('2027-03-27T10:00', '2027-03-27T11:30');
      change('date', '2027-03-28');
      assert.equal(form.elements.ends_at.value, '2027-03-28T11:30', timezone);
    }
  } finally {
    if (previousTimezone === undefined) delete process.env.TZ;
    else process.env.TZ = previousTimezone;
  }
});
