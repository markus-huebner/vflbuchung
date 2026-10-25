import assert from 'node:assert/strict';
import test from 'node:test';
import { addDays, monthDays, shiftMonth } from '../www/assets/js/date-picker.mjs';

test('the month grid has six Monday-first weeks and contains the full month', () => {
  const days = monthDays('2026-10-24');
  assert.equal(days.length, 42);
  assert.equal(days[0], '2026-09-28');
  assert.equal(days[41], '2026-11-08');
  assert.equal(days.filter(day => day.startsWith('2026-10')).length, 31);
  for (let index = 1; index < days.length; index += 1) {
    assert.equal(days[index], addDays(days[index - 1], 1));
  }
});

test('a month starting on Monday starts in the first grid cell', () => {
  assert.equal(monthDays('2027-02-15')[0], '2027-02-01');
});

test('a month starting on Sunday includes the preceding Monday', () => {
  assert.equal(monthDays('2026-11-01')[0], '2026-10-26');
});

test('month navigation clamps the selected day at month end', () => {
  assert.equal(shiftMonth('2026-01-31', 1), '2026-02-28');
  assert.equal(shiftMonth('2028-01-31', 1), '2028-02-29');
  assert.equal(shiftMonth('2028-03-31', -1), '2028-02-29');
  assert.equal(shiftMonth('2026-12-24', 1), '2027-01-24');
  assert.equal(shiftMonth('2028-02-29', -12), '2027-02-28');
});

test('day navigation crosses leap days, month boundaries and year boundaries', () => {
  assert.equal(addDays('2028-02-28', 1), '2028-02-29');
  assert.equal(addDays('2028-02-29', 1), '2028-03-01');
  assert.equal(addDays('2026-12-31', 1), '2027-01-01');
  assert.equal(addDays('2027-01-01', -1), '2026-12-31');
});

test('invalid dates are rejected instead of being normalized to another month', () => {
  for (const value of ['', '2026-02-29', '2026-13-01', '2026-2-01', '0000-01-01']) {
    assert.throws(() => monthDays(value), RangeError);
    assert.throws(() => shiftMonth(value, 1), RangeError);
  }
});

test('early years retain their four-digit year without becoming 1900-based', () => {
  assert.equal(shiftMonth('0001-01-15', 1), '0001-02-15');
  assert.ok(monthDays('0099-01-15').includes('0099-01-01'));
});
