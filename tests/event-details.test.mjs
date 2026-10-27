import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../www/assets/js/app.js', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../www/assets/css/app.css', import.meta.url), 'utf8');
const views = readFileSync(new URL('../www/app/Views.php', import.meta.url), 'utf8');
const application = readFileSync(new URL('../www/app/Application.php', import.meta.url), 'utf8');
const bookingService = readFileSync(new URL('../www/app/BookingService.php', import.meta.url), 'utf8');
const bookingTypesService = readFileSync(new URL('../www/app/BookingTypeService.php', import.meta.url), 'utf8');
const bookingTypeLookup = code => ({
  training: { name: 'Training', color_hex: '#27834a', text_hex: '#ffffff' },
  match: { name: 'Spiel', color_hex: '#2865a6', text_hex: '#ffffff' },
  external: { name: 'Extern', color_hex: '#bf6a18', text_hex: '#ffffff' },
  yellow: { name: 'Gelb', color_hex: '#f4d03f', text_hex: '#000000' },
}[code] || null);
const bookingTypeColorPickerSource = source.slice(
  source.indexOf('function selectBookingTypeColor('),
  source.indexOf('function initControls()'),
);
const showEventSource = source.slice(source.indexOf('function showEvent('), source.indexOf('function createResourceChip('));
const eventCardSource = source.slice(source.indexOf('function eventCard('), source.indexOf('function isRecurring('));
const bookingCardSource = source.slice(source.indexOf('function isRecurring('), source.indexOf('function showEvent('));
const bookingTitleSource = source.slice(source.indexOf('function syncCombinedTitle('), source.indexOf('function renderResourceChoices('));
const bookingFeedbackSource = source.slice(source.indexOf('function bookingError('), source.indexOf('async function submitBooking('));
const submitBookingSource = source.slice(source.indexOf('async function submitBooking('), source.indexOf('async function saveResourceOrder('));
const pendingRequestEditorSource = source.slice(
  source.indexOf('async function openPendingRequestEditor('),
  source.indexOf("const canApprove = button.matches('[data-request-approve]')"),
);
const requestEditorBindingsSource = source.slice(
  source.indexOf("const canApprove = button.matches('[data-request-approve]')"),
  source.indexOf("bookingForm?.querySelector('[data-request-approve]')"),
);
const bookingEditorSource = source.slice(
  source.indexOf('const openBookingEditor = async'),
  source.indexOf("detailsDialog?.querySelector('[data-edit-event]')"),
);

function setup() {
  const fields = new Map();
  const groupedFields = new Map();
  const busFields = [{ hidden: false }, { hidden: false }];
  const pitchFields = [{ hidden: false }, { hidden: false }];
  const dialog = {
    querySelector(selector) {
      if (!fields.has(selector)) {
        fields.set(selector, {
          textContent: '', value: '', hidden: false,
          elements: { occurrence_id: {}, cabin_id: {}, scope: {}, assignment_id: { value: '' } },
        });
      }
      return fields.get(selector);
    },
    querySelectorAll(selector) {
      if (selector === '[data-event-bus-field]') return busFields;
      if (selector === '[data-event-pitch-field]') return pitchFields;
      if (!groupedFields.has(selector)) groupedFields.set(selector, [{ hidden: false }, { hidden: false }]);
      return groupedFields.get(selector);
    },
    showModal() { this.open = true; },
  };
  const context = vm.createContext({
    detailsDialog: dialog,
    selectedEvent: null,
    bookingTypeInfo: bookingTypeLookup,
    Intl,
    Date,
  });
  vm.runInContext(showEventSource, context);
  return {
    show: (event) => context.showEvent(event),
    text: (name) => dialog.querySelector(`[data-event-${name}]`).textContent,
    hidden: (name) => (
      groupedFields.get(`[data-event-${name}]`)?.[0]
      || dialog.querySelector(`[data-event-${name}]`)
    ).hidden,
    dialog, busFields, pitchFields,
  };
}

function setupBookingCard() {
  const elements = new Map();
  const markers = [];
  const stylesApplied = {};
  const wrapper = {
    attributes: {},
    dataset: {},
    classList: { toggle() {} },
    style: { setProperty(name, value) { stylesApplied[name] = value; } },
    setAttribute(name, value) { this.attributes[name] = value; },
    querySelector(selector) {
      if (!elements.has(selector)) elements.set(selector, { textContent: '', hidden: false });
      return elements.get(selector);
    },
    append(element) { markers.push(element); },
    addEventListener() {},
  };
  const context = vm.createContext({
    Intl,
    Date,
    bookingTypeInfo: bookingTypeLookup,
    document: {
      createElement(tagName) {
        const attributes = {};
        return {
          tagName,
          attributes,
          dataset: {},
          classList: { toggle() {} },
          style: { setProperty(name, value) { stylesApplied[name] = value; } },
          setAttribute(name, value) { attributes[name] = value; },
          querySelector: (selector) => {
            if (!elements.has(selector)) elements.set(selector, { textContent: '', hidden: false });
            return elements.get(selector);
          },
          append(element) { markers.push(element); },
          addEventListener() {},
        };
      },
    },
  });
  vm.runInContext(`${eventCardSource}; ${bookingCardSource}; eventCard; fillBookingCard`, context);
  return {
    fill: (event) => context.fillBookingCard(wrapper, event),
    create: (event) => context.eventCard(event),
    text: (selector) => wrapper.querySelector(selector).textContent,
    hidden: (selector) => wrapper.querySelector(selector).hidden,
    styles: () => stylesApplied,
    marker: () => markers[markers.length - 1],
    description: () => wrapper.attributes['aria-label'],
    title: () => wrapper.title,
  };
}

function setupBookingFeedback() {
  const box = {
    textContent: '',
    hidden: true,
    className: '',
    attributes: {},
    setAttribute(name, value) { this.attributes[name] = value; },
    scrollIntoView() {},
  };
  const context = vm.createContext({
    bookingForm: { querySelector: () => box },
  });
  vm.runInContext(`${bookingFeedbackSource}; bookingError`, context);
  return { show: (message, kind) => context.bookingError(message, kind), box };
}

function setupBookingSubmission({ kind = 'booking-edit', success = true, requestList = false, refreshFails = false, mailErrors = [], pending = false, approveRequest = false } = {}) {
  const feedback = [];
  const announcements = [];
  const requests = [];
  const navigation = [];
  const storage = new Map();
  const submit = { disabled: false };
  let refreshes = 0;
  let dashboardRefreshes = 0;
  const location = { href: 'https://calendar.example/?page=dashboard', assign: url => navigation.push(url) };
  const form = {
    dataset: {},
    elements: {
      combined_title: { value: 'Team' },
      event_type: { value: 'training' },
      resource_mode: { value: 'pitch' },
    },
    reportValidity: () => true,
    querySelector: selector => selector === '[data-booking-submit]' ? submit : {},
  };
  const dialog = new EventTarget();
  dialog.open = true;
  dialog.close = () => {
    dialog.open = false;
    dialog.dispatchEvent(new Event('close'));
  };
  const context = vm.createContext({
    bookingForm: form,
    dialog,
    editingBooking: kind === 'booking-edit' ? { occurrence_id: 42 } : null,
    editingRequest: kind === 'request-edit' ? { occurrence_id: 42, approve: approveRequest } : null,
    state: { date: new Date(2026, 9, 24) },
    localDate: date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`,
    validateRequiredNonWhitespace() {},
    syncCombinedTitle() {},
    updateBookingFields() {},
    app: {},
    FormData: class { set() {} },
    URL,
    document: { querySelector: () => requestList ? {} : null },
    sessionStorage: { setItem: (key, value) => storage.set(key, value) },
    bookingError: (message, type) => feedback.push({ message, type }),
    announce: (message, type) => announcements.push({ message, type }),
    fetch: async url => {
      requests.push(url);
      return {
        ok: success,
        json: async () => success
          ? { updated: 2, approved: approveRequest ? 1 : 0, mail_errors: mailErrors, pending, occurrence_id: 42, request_page: 2 }
          : { error: 'Speichern fehlgeschlagen.' },
      };
    },
    loadCalendar: async () => {
      refreshes += 1;
      if (refreshFails) throw new Error('offline');
    },
    refreshRequestDashboard: async () => {
      dashboardRefreshes += 1;
      return { querySelectorAll: () => [] };
    },
    showRequestInCalendar: async () => {},
    window: {
      location,
      history: { state: null, replaceState: (_state, _title, url) => { location.href = new URL(url, location.href).toString(); } },
    },
  });
  vm.runInContext(`${submitBookingSource}; submitBooking`, context);
  return {
    submit: () => context.submitBooking({ preventDefault() {} }),
    dialog, form, button: submit, feedback, announcements, requests, navigation, storage,
    refreshes: () => refreshes, dashboardRefreshes: () => dashboardRefreshes,
  };
}

test('successful booking edits close the dialog', async () => {
  const ui = setupBookingSubmission();
  await ui.submit();
  assert.deepEqual(ui.requests, ['/?api=booking-edit']);
  assert.equal(ui.form.dataset.saved, 'true');
  assert.equal(ui.dialog.open, false);
});

test('creation and request edits also close after saving', async () => {
  for (const kind of ['booking-create', 'request-edit']) {
    const ui = setupBookingSubmission({ kind });
    await ui.submit();
    assert.equal(ui.dialog.open, false);
    assert.equal(ui.form.dataset.saved, 'true');
    assert.equal(ui.refreshes(), 1);
    assert.deepEqual(ui.requests, [`/?api=${kind}`]);
  }
});

test('failed saves keep the editor open and allow correction', async () => {
  const ui = setupBookingSubmission({ success: false });
  await ui.submit();
  assert.equal(ui.dialog.open, true);
  assert.notEqual(ui.form.dataset.saved, 'true');
  assert.equal(ui.button.disabled, false);
  assert.equal(ui.refreshes(), 0);
  assert.equal(ui.feedback.at(-1).message, 'Speichern fehlgeschlagen.');
  assert.deepEqual(ui.announcements, []);
});

test('successful edits close even if refresh or notification delivery fails', async () => {
  const ui = setupBookingSubmission({ refreshFails: true, mailErrors: ['mail failed'] });
  await ui.submit();
  assert.equal(ui.dialog.open, false);
  assert.equal(ui.form.dataset.saved, 'true');
  assert.equal(ui.announcements[0].type, 'warning');
  assert.match(ui.announcements[0].message, /E-Mail-Versand fehlgeschlagen/);
  assert.equal(ui.announcements.at(-1).type, 'warning');
  assert.match(ui.announcements.at(-1).message, /Anzeige konnte nicht aktualisiert werden: offline/);
  await ui.submit();
  assert.equal(ui.requests.length, 1);
});

test('request changes refresh the calendar and list without navigating away', async () => {
  for (const kind of ['booking-edit', 'request-edit', 'booking-create']) {
    const ui = setupBookingSubmission({ kind, requestList: true, pending: kind === 'booking-create' });
    await ui.submit();
    assert.equal(ui.dialog.open, false);
    assert.equal(ui.refreshes(), 1);
    assert.equal(ui.dashboardRefreshes(), kind === 'booking-edit' ? 0 : 1);
    assert.equal(ui.navigation.length, 0);
  }
});

test('request edits and approvals refresh in place', async () => {
  for (const approveRequest of [false, true]) {
    const ui = setupBookingSubmission({ kind: 'request-edit', requestList: true, approveRequest });
    await ui.submit();
    assert.equal(ui.navigation.length, 0);
    assert.equal(ui.dashboardRefreshes(), 1);
    assert.equal(ui.refreshes(), 1);
  }
});

test('approving a request suppresses the success message but still reports mail failures', async () => {
  const approved = setupBookingSubmission({ kind: 'request-edit', requestList: true, approveRequest: true });
  await approved.submit();
  assert.equal(approved.announcements.length, 0);

  const mailFailed = setupBookingSubmission({
    kind: 'request-edit', requestList: true, approveRequest: true, mailErrors: ['mail failed'],
  });
  await mailFailed.submit();
  assert.equal(mailFailed.announcements.at(-1).type, 'warning');
  assert.equal(mailFailed.announcements.at(-1).message, 'Hinweis: E-Mail-Versand fehlgeschlagen.');
});

const pitch = {
  assignment_id: 12, occurrence_id: 3,
  status: 'confirmed', occurrence_status: 'confirmed',
  event_type: 'training', team_name: 'U19', title: 'Training',
  resource_name: 'Platz 1', resource_names: 'Platz 1, Platz 2',
  cabins: 'Kabine 1, Kabine 2',
  start: '2026-10-24T13:00:00Z', end: '2026-10-24T14:30:00Z',
  private_notes: 'Zeile 1\n<script>Kein HTML</script>', recurrence_rule: null,
};

test('booking feedback omits success alerts but still shows warnings inside the dialog', () => {
  const feedback = setupBookingFeedback();
  feedback.show('Buchung wurde angelegt.', 'success');
  assert.equal(feedback.box.textContent, '');
  assert.equal(feedback.box.hidden, true);
  assert.equal(feedback.box.className, '');

  feedback.show('E-Mail-Versand fehlgeschlagen.', 'warning');
  assert.equal(feedback.box.textContent, 'E-Mail-Versand fehlgeschlagen.');
  assert.equal(feedback.box.hidden, false);
  assert.equal(feedback.box.className, 'alert alert-warning mb-0');
  assert.equal(feedback.box.attributes.role, 'status');
  assert.equal(feedback.box.attributes['aria-live'], 'polite');

  assert.match(submitBookingSource, /bookingError\(resourceType/);
  assert.match(submitBookingSource, /if \(bookingForm\.dataset\.saved === 'true'\) return/);
  assert.match(submitBookingSource, /bookingForm\.dataset\.saved = 'true'/);
  assert.match(submitBookingSource, /announce\([\s\S]*?dialog\.close\(\);/);
});

test('application branding shows the VfL crest and new name', () => {
  assert.match(views, /class="navbar-brand fw-semibold" href="\/"><img class="navbar-brand-logo" src="\/assets\/images\/vfl-kaufering-wappen\.png" alt="Wappen des VfL Kaufering">Platz- und Busbelegung<\/a>/);
  assert.match(views, /<title>' \. Security::escape\(\$title\) \. ' · Platz- und Busbelegung<\/title>/);
  assert.match(styles, /\.navbar-brand-logo\s*\{[^}]*object-fit:\s*contain;/s);
});

test('recurring bookings include a repeat marker for space-aware display', () => {
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

test('vehicle calendar cards omit the redundant category but retain request status', () => {
  const confirmedCard = setupBookingCard();
  confirmedCard.fill({ ...pitch, event_type: 'bus', resource_type: 'bus', resource_name: 'Bus 1' });
  assert.equal(confirmedCard.text('.booking-category'), '');
  assert.equal(confirmedCard.hidden('.booking-category'), true);
  assert.doesNotMatch(confirmedCard.description(), /Fahrzeug/);

  const pendingCard = setupBookingCard();
  pendingCard.fill({ ...pitch, event_type: 'bus', status: 'pending' });
  assert.equal(pendingCard.text('.booking-category'), 'Anfrage');
  assert.doesNotMatch(pendingCard.description(), /Fahrzeug/);
});

test('compact cards retain category and recurrence information in accessible descriptions', () => {
  const card = setupBookingCard();
  card.fill({ ...pitch, recurrence_rule: { frequency: 'weekly' } });
  assert.match(card.description(), /Training/);
  assert.match(card.description(), /Regeltermin/);
  assert.match(card.description(), /U19/);
  assert.equal(card.title(), card.description());
  card.fill(pitch);
  assert.doesNotMatch(card.description(), /Regeltermin/);
});

test('calendar cards show occupied cabins without a prefix', () => {
  const card = setupBookingCard();
  card.fill(pitch);
  assert.equal(card.text('.booking-cabin'), 'Kabine 1, Kabine 2');
});

test('calendar cards preserve line breaks in multiline titles', () => {
  const card = setupBookingCard();
  card.fill({ ...pitch, title: 'Training\nHinweis' });
  assert.equal(card.text('strong'), 'U19 – Training\nHinweis');
  assert.match(bookingCardSource, /<strong class="booking-title"><\/strong>/);
  assert.match(styles, /\.booking-title,\s*\.request-details,\s*\.event-notes\s*\{[^}]*white-space:\s*pre-wrap;[^}]*overflow-wrap:\s*anywhere;/s);
});

test('event detail and request titles preserve line breaks', () => {
  const ui = setup();
  ui.show({ ...pitch, team_name: 'U19', title: 'Training\nHinweis' });
  assert.equal(ui.text('title'), 'U19 – Training\nHinweis');
  assert.match(views, /<h2 class="h5 mb-0 booking-title"[^>]*data-event-title/);
  assert.match(views, /<strong class="booking-title">'\s*\. Security::escape\(\$request\['title'\]\)/);
  assert.match(views, /class="request-details">'\s*\. Security::escape\(\$date/);
});

test('pending bookings use lighter category colors and standard borders', () => {
  const pendingStyle = styles.match(/\.booking-pending\s*\{([^}]+)\}/);
  assert.ok(pendingStyle);
  assert.match(pendingStyle[1], /color-mix\(in srgb, var\(--event-color\) 40%, white\)/);
  assert.doesNotMatch(pendingStyle[1], /border-style/);
  assert.match(styles, /\.legend-pending::before\s*\{[^}]*display:\s*none/);
  assert.match(styles, /\.legend-pending-colors > span\s*\{[^}]*border:\s*1px solid var\(--legend-color\);[^}]*color-mix\(in srgb, var\(--legend-color\) 40%, white\)/s);

  assert.match(views, /foreach \(\$legendTypes as \$type\)/);
  assert.match(views, /\$bookingLegend \.= '<span class="legend-item" style="--legend-color:' \. \$color/);
  assert.match(views, /\$pendingLegend \.= '<span style="--legend-color:' \. \$color \. '"><\/span>'/);
  assert.doesNotMatch(views, /--booking-(training|match|external|bus)/);
});

test('light booking colors use dark text for readability', () => {
  const card = setupBookingCard();
  const element = card.create({
    ...pitch,
    event_type: 'yellow',
    event_type_color: '#f4d03f',
    event_type_text_color: '#000000',
  });

  assert.equal(card.styles()['--event-color'], '#f4d03f');
  assert.equal(card.styles()['--event-text-color'], '#000000');
  assert.match(styles, /color:\s*var\(--event-text-color,\s*#fff\)/);
});

test('booking type color dropdown updates its selected swatch and accessible label', () => {
  const attributes = {};
  const swatchProperties = {};
  let focused = false;
  const colorInput = { value: 'green' };
  const currentColor = {
    setAttribute(name, value) { attributes[name] = value; },
    querySelector: () => ({ style: { setProperty: (name, value) => { swatchProperties[name] = value; } } }),
    getBoundingClientRect: () => ({ left: 270, top: 20, bottom: 60 }),
    focus() { focused = true; },
  };
  const dropdown = {
    open: false,
    addEventListener(_name, listener) { this.toggle = listener; },
  };
  const optionsPanel = { offsetWidth: 140, offsetHeight: 80, style: {} };
  const options = ['green', 'blue'].map((code, index) => {
    const optionAttributes = {};
    return {
      dataset: {
        colorCode: code,
        colorLabel: index === 0 ? 'Grün' : 'Blau',
        colorHex: index === 0 ? '#27834a' : '#2865a6',
      },
      setAttribute(name, value) { optionAttributes[name] = value; },
      getAttribute(name) { return optionAttributes[name]; },
      addEventListener(_name, listener) { this.click = listener; },
    };
  });
  const picker = {
    querySelector(selector) {
      if (selector === '[data-booking-color-value]') return colorInput;
      if (selector === '[data-booking-color-current]') return currentColor;
      if (selector === '.booking-type-color-options') return optionsPanel;
      return dropdown;
    },
    querySelectorAll: () => options,
    addEventListener() {},
    contains: () => true,
  };
  const context = vm.createContext({
    document: {
      querySelectorAll: () => [picker],
      addEventListener() {},
    },
    window: {
      innerWidth: 320,
      innerHeight: 240,
      addEventListener() {},
    },
    requestAnimationFrame: callback => callback(),
  });
  vm.runInContext(`${bookingTypeColorPickerSource}; initBookingTypeColorPickers()`, context);
  dropdown.open = true;
  dropdown.toggle();

  assert.equal(optionsPanel.style.left, '172px');
  assert.equal(optionsPanel.style.top, '64px');
  assert.equal(optionsPanel.style.visibility, 'visible');
  options[1].click();

  assert.equal(colorInput.value, 'blue');
  assert.equal(swatchProperties['--booking-type-color'], '#2865a6');
  assert.equal(attributes['aria-label'], 'Kalenderfarbe: Blau');
  assert.equal(options[0].getAttribute('aria-pressed'), 'false');
  assert.equal(options[1].getAttribute('aria-pressed'), 'true');
  assert.equal(dropdown.open, false);
  assert.equal(focused, true);
});

test('booking type edit button opens the dialog with the selected type values', () => {
  const attributes = {};
  const swatchProperties = {};
  let dialogOpened = false;
  let nameFocused = false;
  const codeField = { value: '' };
  const nameField = {
    value: '',
    focus() { nameFocused = true; },
  };
  const trainerRequestableField = { checked: true };
  const colorInput = { value: 'green' };
  const currentColor = {
    setAttribute(name, value) { attributes[name] = value; },
    querySelector: () => ({ style: { setProperty: (name, value) => { swatchProperties[name] = value; } } }),
  };
  const optionAttributes = [{}, {}];
  const options = ['green', 'blue'].map((code, index) => ({
    dataset: {
      colorCode: code,
      colorLabel: index === 0 ? 'Grün' : 'Blau',
      colorHex: index === 0 ? '#27834a' : '#2865a6',
    },
    setAttribute(name, value) { optionAttributes[index][name] = value; },
  }));
  const picker = {
    querySelector(selector) {
      if (selector === '[data-booking-color-value]') return colorInput;
      if (selector === '[data-booking-color-current]') return currentColor;
      return null;
    },
    querySelectorAll: () => options,
  };
  const form = {
    querySelector(selector) {
      if (selector === '[name="code"]') return codeField;
      if (selector === '[name="name"]') return nameField;
      if (selector === '[name="trainer_requestable"]') return trainerRequestableField;
      return picker;
    },
  };
  const dialog = { showModal() { dialogOpened = true; } };
  const button = {
    dataset: {
      bookingTypeCode: 'custom-match',
      bookingTypeName: 'Turnier',
      bookingTypeColor: 'blue',
      bookingTypeTrainerRequestable: '0',
    },
    addEventListener(_name, listener) { this.click = listener; },
  };
  const context = vm.createContext({
    document: {
      querySelector: selector => selector === '#booking-type-edit-dialog' ? dialog : form,
      querySelectorAll: selector => selector === '[data-booking-type-edit]' ? [button] : [],
    },
  });
  vm.runInContext(`${bookingTypeColorPickerSource}; initBookingTypeEditor()`, context);
  button.click();

  assert.equal(codeField.value, 'custom-match');
  assert.equal(nameField.value, 'Turnier');
  assert.equal(trainerRequestableField.checked, false);
  assert.equal(colorInput.value, 'blue');
  assert.equal(swatchProperties['--booking-type-color'], '#2865a6');
  assert.equal(attributes['aria-label'], 'Kalenderfarbe: Blau');
  assert.equal(optionAttributes[0]['aria-pressed'], 'false');
  assert.equal(optionAttributes[1]['aria-pressed'], 'true');
  assert.equal(dialogOpened, true);
  assert.equal(nameFocused, true);
  button.dataset.bookingTypeTrainerRequestable = '1';
  button.click();
  assert.equal(trainerRequestableField.checked, true);
});

test('pitch and cabin resource choices have three columns and concise wording', () => {
  const choicesSource = source.slice(
    source.indexOf('function renderResourceChoices('),
    source.indexOf('function updateBookingFields('),
  );
  assert.match(choicesSource, /group\.classList\.add\('pitch-choice-group'\)/);
  assert.match(choicesSource, /cabinGroup\.className = 'resource-choice-group cabin-choice-group'/);
  assert.match(choicesSource, /'Plätze \(mindestens einer\)'/);
  assert.doesNotMatch(choicesSource, /Plätze \(mindestens einen auswählen\)/);
  assert.doesNotMatch(choicesSource, /Eine Platzbuchung braucht mindestens einen Platz/);
  assert.match(styles, /\.pitch-choice-group,\s*\.cabin-choice-group\s*\{[^}]*grid-template-columns:\s*repeat\(3,\s*minmax\(0,\s*1fr\)\);/s);
});

test('vehicle resource choices use two columns with single selection', () => {
  const choicesSource = source.slice(
    source.indexOf('function renderResourceChoices('),
    source.indexOf('function updateBookingFields('),
  );
  assert.match(choicesSource, /group\.classList\.add\('bus-choice-group'\)/);
  assert.match(choicesSource, /createResourceChip\([\s\S]*?isBus,\s*\)/);
  assert.match(choicesSource, /const initiallySelected = editingBooking \|\| editingRequest\s*\?\s*selectedIds\.has\(Number\(resource\.id\)\)\s*:\s*resources\.length === 1;/);
  assert.match(styles, /\.bus-choice-group\s*\{[^}]*grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\);/s);
  assert.match(source, /function createResourceChip\(resource, fieldName, initiallySelected = false, singleSelect = false\)/);
});

test('trainers see request wording for vehicle and pitch booking dialog titles', () => {
  assert.match(source, /const isTrainer = bookingForm\.dataset\.userRole === 'trainer'/);
  assert.match(source, /bus:\s*isTrainer \? 'Fahrzeug' : 'Fahrzeugbuchung'/);
  assert.match(source, /const action = editing \? 'ändern' : \(isTrainer \? 'anfragen' : 'anlegen'\)/);
});

test('pitch detail dialog puts category and hint first, followed by date, time, resources and cabins', () => {
  const eventDialog = views.slice(
    views.indexOf('private static function eventDialog'),
    views.indexOf('private static function statusLabel'),
  );
  assert.match(eventDialog, /<p class="event-dialog-category" data-event-type><\/p><h2[^>]*data-event-title/);
  const fieldOrder = [
    'data-event-date', 'data-event-time', 'data-event-resources', 'data-event-cabins',
    'data-event-status', 'data-event-destination',
  ].map(field => eventDialog.indexOf(field));
  assert.ok(fieldOrder.every((position, index) => position >= 0 && (index === 0 || position > fieldOrder[index - 1])));
});

test('clicking a pitch shows all booking details as text in Berlin time', () => {
  const ui = setup();
  ui.show(pitch);
  assert.equal(ui.dialog.open, true);
  assert.equal(ui.text('title'), 'U19 – Training');
  assert.equal(ui.text('resources'), 'Platz 1, Platz 2');
  assert.equal(ui.text('cabins'), pitch.cabins);
  assert.equal(ui.text('resource-label'), 'Plätze');
  assert.equal(ui.text('type'), 'Training');
  assert.equal(ui.text('date'), '24.10.2026');
  assert.equal(ui.text('time'), '15:00–16:30 Uhr');
  assert.equal(ui.text('notes'), pitch.private_notes);
  assert.equal(ui.text('recurrence'), 'Keine');
  assert.ok(ui.busFields.every(field => field.hidden));
  assert.ok(ui.pitchFields.every(field => !field.hidden));
  assert.equal(ui.dialog.querySelector('[data-cancel-form] input[name="assignment_id"]').value, 12);
});

test('pending requests expose approval actions rather than confirmed-booking actions', () => {
  const ui = setup();
  ui.show({ ...pitch, status: 'pending', occurrence_status: 'pending' });
  assert.equal(ui.text('status'), 'Anfrage (offen)');
  assert.equal(ui.text('type'), 'Training');
  for (const selector of ['[data-edit-event]', '[data-cancel-form]', '[data-cancel-cabin-form]']) {
    assert.equal(ui.dialog.querySelector(selector).hidden, true);
  }
  assert.equal(ui.dialog.querySelector('[data-event-approve]').hidden, false);
  assert.equal(ui.dialog.querySelector('[data-event-reject]').hidden, false);
  assert.equal(ui.dialog.querySelector('[data-event-approve]').elements.assignment_id.value, 12);
  ui.show(pitch);
  assert.equal(ui.text('status'), 'Bestätigt');
  assert.equal(ui.dialog.querySelector('[data-edit-event]').hidden, false);
  assert.equal(ui.dialog.querySelector('[data-cancel-form]').hidden, false);
  assert.equal(ui.dialog.querySelector('[data-event-approve]').hidden, true);
  assert.equal(ui.dialog.querySelector('[data-event-reject]').hidden, true);
  ui.show({ ...pitch, occurrence_status: 'pending' });
  assert.equal(ui.dialog.querySelector('[data-edit-event]').hidden, true);
});

test('pending requests open the shared booking dialog for editing', () => {
  assert.match(views, /<form id="booking-form"/);
  assert.match(views, /data-request-edit/);
  assert.doesNotMatch(views, /request-edit-form/);
  assert.match(source, /#request-dashboard \[data-request-edit\], #request-dashboard \[data-request-approve\]/);
  assert.match(source, /api=request-details/);
  assert.match(source, /editingRequest = \{ \.\.\.data, can_approve: canApprove, approve: false \}/);
  assert.match(submitBookingSource, /const api = isRequestEdit \? 'request-edit'/);
  assert.match(submitBookingSource, /const shouldRefreshRequestDashboard = hasRequestList && \(isRequestEdit \|\| result\.pending\)/);
  assert.match(submitBookingSource, /await Promise\.all\(updates\)/);
  assert.match(application, /if \(\$api === 'request-dashboard'/);
  assert.match(source, /url\.searchParams\.set\('api', 'request-dashboard'\)/);
  assert.match(source, /submit\.textContent = saved \? 'Gespeichert'\s*:\s*editingRequest \? 'Speichern'\s*:\s*editing \? 'Änderungen speichern' : 'Speichern'/);
  assert.match(application, /if \(\$api === 'request-edit'/);
});

test('the selected request remains highlighted when the list refreshes in place', () => {
  assert.match(requestEditorBindingsSource, /selectRequestRow\(button\)/);
  assert.match(source, /currentDashboard\.querySelector\([\s\S]*?\.request-row-selected \[data-occurrence-id\]/);
  assert.match(source, /selectedButton\?\.closest\('\.request-row'\)\?\.classList\.add\('request-row-selected'\)/);
});

test('new trainer requests select the list row and open the matching calendar entry', () => {
  assert.match(bookingService, /'occurrence_id' => \$firstOccurrenceId/);
  assert.match(bookingService, /public function requestPageForOccurrence\(/);
  assert.match(application, /'occurrence_id' => \$result\['occurrence_id'\]/);
  assert.match(application, /requestPageForOccurrence\(/);
  assert.match(application, /'showAllRequests' => \$includeClosedRequests/);
  assert.match(submitBookingSource, /if \(result\.pending && result\.occurrence_id\)/);
  assert.match(submitBookingSource, /refreshRequestDashboard\(\)/);
  assert.match(source, /await showRequestInCalendar\(button\)/);
});

test('prefilling an unchanged booking title clears stale required-field validity', () => {
  assert.match(pendingRequestEditorSource, /setCombinedTitle\(bookingForm, combined\)/);
  assert.match(bookingEditorSource, /setCombinedTitle\(bookingForm, combined\)/);

  const context = vm.createContext({});
  const titleHelpers = vm.runInContext(
    `${bookingTitleSource}; ({ setCombinedTitle, syncCombinedTitle, validateRequiredNonWhitespace })`,
    context,
  );
  const field = {
    value: '',
    dataset: { initialValue: '' },
    validityMessage: '',
    setCustomValidity(message) { this.validityMessage = message; },
  };
  const form = {
    elements: {
      combined_title: field,
      title: { value: 'Unchanged team note' },
      team_name: { value: '' },
    },
  };

  titleHelpers.validateRequiredNonWhitespace(field);
  assert.notEqual(field.validityMessage, '');

  titleHelpers.setCombinedTitle(form, 'Unchanged team note');
  assert.equal(field.validityMessage, '');
  titleHelpers.syncCombinedTitle(form, true);
  assert.equal(form.elements.title.value, 'Unchanged team note');
});

test('booking requests are grouped and review opens the editable dialog', () => {
  const requestQuery = bookingService.slice(
    bookingService.indexOf('public function requests('),
    bookingService.indexOf('public function approve('),
  );
  assert.match(requestQuery, /GROUP_CONCAT\(DISTINCT CASE WHEN a\.status = 'pending' THEN a\.id END ORDER BY a\.id SEPARATOR ','\)\s+AS pending_assignment_ids/);
  assert.match(requestQuery, /COUNT\(DISTINCT o\.id\) AS occurrence_count/);
  assert.match(requestQuery, /GROUP BY \{\$requestEntryKey\}/);
  assert.match(requestQuery, /g\.recurrence_rule IS NOT NULL AND o\.status = 'confirmed'/);
  assert.match(views, /data-request-approve/);
  assert.match(views, /\$request\['is_series_summary'\]/);
  assert.match(views, /Serientermine/);
  assert.match(views, />Ändern<\/button>/);
  assert.match(source, /#request-dashboard \[data-request-edit\], #request-dashboard \[data-request-approve\]/);
  assert.match(source, /data-request-approve/);
});

test('booking requests cannot reserve cabins and retain creation fields while editing', () => {
  assert.match(bookingService, /Buchungsanfragen können keine Kabinen belegen/);
  assert.match(application, /request-details/);
  assert.match(application, /editablePendingRequest/);
  assert.match(views, /data-allow-cabins=/);
  assert.doesNotMatch(source, /data-request-edit-hidden/);
  assert.match(source, /editingRequest\?\.resource_ids/);
  assert.match(source, /editingRequest\.approve/);
});

test('admin management links stay inside the role-gated account menu', () => {
  const layoutSource = views.slice(
    views.indexOf('public static function layout('),
    views.indexOf('private static function actionConfirmationDialog('),
  );
  assert.match(
    layoutSource,
    /if \(\$user\['role'\] === 'admin'\) \{\s*\$nav \.= '<a href="\/\?page=resources">Ressourcenverwaltung<\/a>'[\s\S]*?href="\/\?page=users">Benutzerverwaltung<\/a>[\s\S]*?href="\/\?page=booking-types">Buchungsarten<\/a>'[\s\S]*?<hr class="my-1">';\s*\}\s*\$nav \.= '<a href="\/\?page=profile">Mein Profil<\/a>'/,
  );
  assert.doesNotMatch(application, /case 'settings':/);
  assert.match(application, /case 'users':\s*\$this->requireRole\(\['admin'\]\)/);
  assert.match(application, /case 'resources':\s*\$this->requireRole\(\['admin'\]\)/);
});

test('login view includes a button to return to the calendar', () => {
  const loginView = views.slice(
    views.indexOf('public static function login('),
    views.indexOf('public static function dashboard('),
  );
  assert.match(loginView, /<a class="btn btn-outline-secondary w-100 mt-2" href="\/">Zurück<\/a>/);
});

test('hidden rejection action is not forced visible by a display utility', () => {
  const rejectForm = views.match(/<form method="post" action="\/\?action=booking-reject"([^>]*)data-event-reject[^>]*hidden>/);
  assert.ok(rejectForm);
  assert.doesNotMatch(rejectForm[1], /\bclass="[^"]*\bd-flex\b/);
});

test('rejecting a booking request requires confirmation', () => {
  assert.match(views, /id="action-confirm-dialog"[\s\S]*data-confirm-title[\s\S]*data-confirm-message[\s\S]*data-confirm-submit/);
  assert.equal([...views.matchAll(/action="\/\?action=booking-reject"[^>]*data-confirm-action="reject"/g)].length, 2);
  assert.match(source, /document\.addEventListener\('submit', \(event\) => \{\s*const form = event\.target\.closest\('form\[data-confirm-action\]'\)/);
  assert.match(source, /actionConfirmDialog\.showModal\(\)/);
  assert.match(source, /actionConfirmDialog\.querySelector\('\[data-confirm-submit\]'\)[\s\S]*?form\.requestSubmit\(\)/);
  assert.doesNotMatch(source, /window\.confirm/);
});

test('deleting a booking or cabin requires custom confirmation after choosing series scope', () => {
  assert.equal([...views.matchAll(/data-cancel-form data-confirm-action="delete"/g)].length, 1);
  assert.equal([...views.matchAll(/data-cancel-cabin-form data-confirm-action="delete"/g)].length, 1);
  assert.match(views, /action="\/\?action=booking-delete" data-confirm-action="delete"/);
  assert.match(source, /form\.dataset\.scopeConfirmed !== 'true'[\s\S]*?requestRecurrenceScope\('delete', form\)/);
  assert.match(source, /Möchten Sie diese Kabinenbelegung wirklich löschen\?/);
  assert.match(source, /Möchten Sie diese Buchung wirklich löschen\?/);
});

test('booking removal uses deletion wording throughout the UI and feedback', () => {
  assert.match(views, />Löschen<\/button>/);
  assert.match(views, />Kabinenbelegung löschen<\/button>/);
  assert.match(application, /Die Buchung wurde gelöscht/);
  assert.match(application, /Die Kabinenbelegung wurde gelöscht/);
  assert.match(source, /action === 'edit' \? 'Änderung' : 'Löschung'/);
  for (const text of [views, application, bookingService, source]) {
    assert.doesNotMatch(text, /stornier/i);
  }
});

test('booking type removal requires confirmation and preserves types used by bookings', () => {
  assert.match(views, /action="\/\?action=booking-type-remove" data-confirm-action="delete"/);
  assert.match(source, /form\.action\.includes\('booking-type-remove'\)/);
  assert.match(source, /Bei vorhandenen Terminen wird die Buchungsart archiviert/);
  assert.match(bookingTypesService, /SELECT 1 FROM booking_occurrences WHERE event_type = :code LIMIT 1/);
  assert.match(bookingTypesService, /UPDATE booking_types SET is_active = 0 WHERE code = :code/);
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
  assert.equal(ui.text('date'), '24.10.2026 – 26.10.2026');
  assert.equal(ui.text('time'), '15:00–10:00 Uhr');
  assert.equal(ui.text('resource-label'), 'Fahrzeug');
  assert.equal(ui.hidden('type'), true);
  assert.ok(ui.busFields.every(field => !field.hidden));
  assert.ok(ui.pitchFields.every(field => field.hidden));
  assert.ok(['destination-field', 'purpose-field', 'driver-field', 'notes-field']
    .every(field => !ui.hidden(field)));
  ui.show({
    ...pitch, destination: '', purpose: null, driver_name: '  ', private_notes: null,
  });
  assert.ok(['destination-field', 'purpose-field', 'driver-field', 'notes-field']
    .every(field => ui.hidden(field)));
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
