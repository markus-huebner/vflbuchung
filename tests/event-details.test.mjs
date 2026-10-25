import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../www/assets/js/app.js', import.meta.url), 'utf8');
const showEventSource = source.slice(source.indexOf('function showEvent('), source.indexOf('function createResourceChip('));
const bookingCardSource = source.slice(source.indexOf('function isRecurring('), source.indexOf('function showEvent('));

function setup() {
  const fields = new Map();
  const busFields = [{ hidden: false }, { hidden: false }];
  const pitchFields = [{ hidden: false }, { hidden: false }];
  const dialog = {
    querySelector(selector) {
      if (!fields.has(selector)) {
        fields.set(selector, {
          textContent: '', value: '', hidden: false,
          elements: { occurrence_id: {}, cabin_id: {}, scope: {} },
        });
      }
      return fields.get(selector);
    },
    querySelectorAll(selector) {
      return selector === '[data-event-bus-field]' ? busFields : pitchFields;
    },
    showModal() { this.open = true; },
  };
  const context = vm.createContext({ detailsDialog: dialog, selectedEvent: null, Intl, Date });
  vm.runInContext(showEventSource, context);
  return {
    show: (event) => context.showEvent(event),
    text: (name) => dialog.querySelector(`[data-event-${name}]`).textContent,
    dialog, busFields, pitchFields,
  };
}

function setupBookingCard() {
  const elements = new Map();
  const markers = [];
  const wrapper = {
    querySelector(selector) {
      if (!elements.has(selector)) elements.set(selector, { textContent: '', hidden: false });
      return elements.get(selector);
    },
    append(element) { markers.push(element); },
  };
  const context = vm.createContext({
    Intl,
    Date,
    document: {
      createElement(tagName) {
        const attributes = {};
        return {
          tagName,
          attributes,
          setAttribute(name, value) { attributes[name] = value; },
        };
      },
    },
  });
  vm.runInContext(`${bookingCardSource}; fillBookingCard`, context);
  return {
    fill: (event) => context.fillBookingCard(wrapper, event),
    text: (selector) => wrapper.querySelector(selector).textContent,
    marker: () => markers[markers.length - 1],
  };
}

const pitch = {
  assignment_id: 12, occurrence_id: 3,
  status: 'confirmed', occurrence_status: 'confirmed',
  event_type: 'training', team_name: 'U19', title: 'Training',
  resource_name: 'Platz 1', resource_names: 'Platz 1, Platz 2',
  cabins: 'Kabine 1, Kabine 2',
  start: '2026-10-24T13:00:00Z', end: '2026-10-24T14:30:00Z',
  private_notes: 'Zeile 1\n<script>Kein HTML</script>', recurrence_rule: null,
};

test('recurring bookings have a visible repeat marker in their calendar card', () => {
  const recurringCard = setupBookingCard();
  recurringCard.fill({ ...pitch, recurrence_rule: { frequency: 'weekly' } });
  assert.equal(recurringCard.text('.booking-category'), 'Training');
  assert.equal(recurringCard.marker().textContent, '↻');
  assert.equal(recurringCard.marker().hidden, false);
  assert.equal(recurringCard.marker().attributes['aria-label'], 'Regeltermin');

  const singleCard = setupBookingCard();
  singleCard.fill(pitch);
  assert.equal(singleCard.text('.booking-category'), 'Training');
  assert.equal(singleCard.marker().hidden, true);
});

test('calendar cards show occupied cabins without a prefix', () => {
  const card = setupBookingCard();
  card.fill(pitch);
  assert.equal(card.text('.booking-cabin'), 'Kabine 1, Kabine 2');
});

test('clicking a pitch shows all booking details as text in Berlin time', () => {
  const ui = setup();
  ui.show(pitch);
  assert.equal(ui.dialog.open, true);
  assert.equal(ui.text('title'), 'U19 – Training');
  assert.equal(ui.text('resources'), 'Platz 1, Platz 2');
  assert.equal(ui.text('resource'), 'Platz 1');
  assert.equal(ui.text('cabins'), pitch.cabins);
  assert.equal(ui.text('type'), 'Training');
  assert.equal(ui.text('start'), '24.10.2026, 15:00');
  assert.equal(ui.text('end'), '24.10.2026, 16:30');
  assert.equal(ui.text('notes'), pitch.private_notes);
  assert.equal(ui.text('recurrence'), 'Keine');
  assert.ok(ui.busFields.every(field => field.hidden));
  assert.ok(ui.pitchFields.every(field => !field.hidden));
  assert.equal(ui.dialog.querySelector('[data-cancel-form] input[name="assignment_id"]').value, 12);
});

test('pending requests show their status without offering confirmed-booking actions', () => {
  const ui = setup();
  ui.show({ ...pitch, status: 'pending', occurrence_status: 'pending' });
  assert.equal(ui.text('status'), 'Anfrage (offen)');
  assert.equal(ui.text('type'), 'Training');
  for (const selector of ['[data-edit-event]', '[data-cancel-form]', '[data-cancel-cabin-form]']) {
    assert.equal(ui.dialog.querySelector(selector).hidden, true);
  }
  assert.equal(ui.dialog.querySelector('[data-event-request]').hidden, false);
  ui.show(pitch);
  assert.equal(ui.text('status'), 'Bestätigt');
  assert.equal(ui.dialog.querySelector('[data-edit-event]').hidden, false);
  assert.equal(ui.dialog.querySelector('[data-cancel-form]').hidden, false);
  assert.equal(ui.dialog.querySelector('[data-event-request]').hidden, true);
  ui.show({ ...pitch, occurrence_status: 'pending' });
  assert.equal(ui.dialog.querySelector('[data-edit-event]').hidden, true);
});

test('vehicle details retain the title and show destination, purpose, driver and both dates', () => {
  const ui = setup();
  ui.show({
    ...pitch, event_type: 'bus', title: 'Auswärtsfahrt', team_name: null,
    display_title: 'Turnier', destination: 'Berlin', purpose: 'Turnier', driver_name: 'Max',
    end: '2026-10-26T09:00:00Z',
  });
  assert.equal(ui.text('title'), 'Auswärtsfahrt');
  assert.equal(ui.text('destination'), 'Berlin');
  assert.equal(ui.text('purpose'), 'Turnier');
  assert.equal(ui.text('driver'), 'Max');
  assert.equal(ui.text('end'), '26.10.2026, 10:00');
  assert.ok(ui.busFields.every(field => !field.hidden));
  assert.ok(ui.pitchFields.every(field => field.hidden));
  ui.show({ ...pitch, private_notes: null });
  assert.equal(ui.text('driver'), '—');
  assert.equal(ui.text('notes'), '—');
  assert.ok(ui.busFields.every(field => field.hidden));
});

test('weekly series show interval, weekdays and the end date', () => {
  const ui = setup();
  ui.show({
    ...pitch,
    recurrence_rule: { frequency: 'weekly', interval: 2, weekdays: ['MO', 'WE'], limit_type: 'until', until: '2027-03-31' },
  });
  assert.equal(ui.text('recurrence'), 'Alle 2 Wochen · Mo, Mi · bis 31.03.2027');
});

test('all recurrence frequencies display count limits and intervals', () => {
  const ui = setup();
  for (const [frequency, label, unit] of [
    ['daily', 'Täglich', 'Tage'], ['weekly', 'Wöchentlich', 'Wochen'],
    ['monthly', 'Monatlich', 'Monate'], ['yearly', 'Jährlich', 'Jahre'],
  ]) {
    for (const interval of [1, 3]) {
      ui.show({ ...pitch, recurrence_rule: { frequency, interval, limit_type: 'count', count: 10 } });
      assert.equal(ui.text('recurrence'), `${interval === 1 ? label : `Alle 3 ${unit}`} · 10 Termine`);
    }
  }
  ui.show({ ...pitch, cabins: null, recurrence_rule: null });
  assert.equal(ui.text('recurrence'), 'Keine');
  assert.equal(ui.text('cabins'), 'Keine Kabine');
});
