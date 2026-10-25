import 'bootstrap/dist/css/bootstrap.min.css';
import '../css/app.css';

import { initBookingDates } from './booking-dates.mjs';
import { initDatePicker } from './date-picker.mjs';
import { eventDaySegment, layoutDayEvents } from './calendar-layout.mjs';
import { initTimePicker } from './time-picker.mjs';

const state = {
  date: new Date(),
  view: 'day',
  showFullDay: false,
  resourceType: 'pitch',
  resources: [],
  events: [],
};

const app = window.VereinApp || {};
const messageBox = document.querySelector('#app-message');
const dialog = document.querySelector('#booking-dialog');
const bookingForm = document.querySelector('#booking-form');
const detailsDialog = document.querySelector('#event-dialog');
const scopeDialog = document.querySelector('#recurrence-scope-dialog');
let selectedEvent = null;
let editingBooking = null;
let pendingScopeAction = null;

function announce(message, kind = 'info') {
  if (!messageBox) return;
  messageBox.className = `alert alert-${kind}`;
  messageBox.textContent = message;
  messageBox.hidden = false;
  window.setTimeout(() => {
    messageBox.hidden = true;
  }, 7000);
}

function localDate(date) {
  const year = String(date.getFullYear()).padStart(4, '0');
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function berlinDateTimeInput(value) {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Europe/Berlin',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(new Date(value));
  const fields = Object.fromEntries(parts.map((part) => [part.type, part.value]));
  return `${fields.year.padStart(4, '0')}-${fields.month}-${fields.day}T${fields.hour}:${fields.minute}`;
}

function weekStart(date) {
  const copy = new Date(date);
  const offset = (copy.getDay() + 6) % 7;
  copy.setDate(copy.getDate() - offset);
  copy.setHours(0, 0, 0, 0);
  return copy;
}

function rangeForView() {
  const start = state.view === 'day' ? new Date(state.date) : weekStart(state.date);
  start.setHours(0, 0, 0, 0);
  const end = new Date(start);
  end.setDate(end.getDate() + (state.view === 'day' ? 1 : 7));
  return { start, end };
}

async function loadCalendar() {
  const { start, end } = rangeForView();
  const params = new URLSearchParams({
    api: 'events',
    type: state.resourceType,
    start: start.toISOString(),
    end: end.toISOString(),
  });
  const response = await fetch(`/?${params.toString()}`, { headers: { Accept: 'application/json' } });
  if (!response.ok) throw new Error('Kalenderdaten konnten nicht geladen werden.');
  state.events = await response.json();
  renderCalendar();
}

async function loadResources() {
  const response = await fetch('/?api=resources', { headers: { Accept: 'application/json' } });
  if (!response.ok) throw new Error('Ressourcen konnten nicht geladen werden.');
  const data = await response.json();
  state.resources = data.resources;
  renderResourceChoices();
}

function renderCalendar() {
  document.querySelectorAll('[data-calendar-view]').forEach((element) => {
    element.hidden = element.dataset.calendarView !== state.resourceType;
  });
  const dayView = document.querySelector(`#${state.resourceType}-day`);
  const weekView = document.querySelector(`#${state.resourceType}-week`);
  if (dayView) dayView.hidden = state.view !== 'day';
  if (weekView) weekView.hidden = state.view !== 'week';
  const resourceSelect = document.querySelector('#calendar-resource-select');
  if (resourceSelect) resourceSelect.value = state.resourceType;
  document.querySelectorAll('[data-view]').forEach((button) => {
    button.classList.toggle('active', button.dataset.view === state.view);
  });
  const label = document.querySelector('#calendar-range');
  const fullDayToggle = document.querySelector('#full-day-toggle');
  const fullDayToggleWrap = document.querySelector('#full-day-toggle-wrap');
  if (fullDayToggle) fullDayToggle.checked = state.showFullDay;
  if (fullDayToggleWrap) fullDayToggleWrap.hidden = state.view !== 'day';
  if (label) {
    const { start, end } = rangeForView();
    const endLabel = new Date(end);
    endLabel.setDate(endLabel.getDate() - 1);
    const fullLabel = state.view === 'day'
      ? start.toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
      : `${start.toLocaleDateString('de-DE', { day: 'numeric', month: 'short' })} – ${endLabel.toLocaleDateString('de-DE', { day: 'numeric', month: 'short', year: 'numeric' })}`;
    const shortDate = (date, year = false) => date.toLocaleDateString('de-DE', {
      day: '2-digit', month: '2-digit', ...(year ? { year: 'numeric' } : {}),
    });
    label.textContent = state.view === 'day'
      ? shortDate(start, true) : `${shortDate(start)}–${shortDate(endLabel)}`;
    const picker = document.querySelector('#calendar-date-picker');
    picker.title = fullLabel;
    picker.setAttribute('aria-label', `Datum auswählen: ${fullLabel}`);
  }

  if (state.view === 'day') {
    renderResourceDay();
  } else {
    renderResourceWeek();
  }
}

function eventCard(event, compact = false, visibleRange = null) {
  const wrapper = document.createElement('button');
  wrapper.type = 'button';
  wrapper.className = `booking-card booking-${event.event_type || 'training'}${compact ? ' booking-card-compact' : ''}`;
  wrapper.classList.toggle('booking-card-recurring', isRecurring(event));
  wrapper.classList.toggle('booking-pending', event.status === 'pending');
  fillBookingCard(wrapper, event, visibleRange);
  wrapper.addEventListener('click', () => showEvent(event));
  return wrapper;
}

function isRecurring(event) {
  return Boolean(event?.recurrence_rule && event.recurrence_rule.frequency !== 'none');
}

function fillBookingCard(wrapper, event, visibleRange = null) {
  const kind = event.event_type || 'training';
  const typeLabels = { training: 'Training', match: 'Spiel', external: 'Externe Belegung', bus: 'Fahrzeug' };
  const formatTime = (value) => new Intl.DateTimeFormat('de-DE', {
    timeZone: 'Europe/Berlin',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value));
  const cabin = event.cabins || (event.resource_type === 'cabin' ? event.resource_name : '');
  wrapper.innerHTML = `<span class="booking-category"></span><strong class="booking-title"></strong><span class="booking-time"></span><span class="booking-cabin"></span>`;
  const recurrenceMarker = document.createElement('span');
  recurrenceMarker.className = 'booking-recurring-marker';
  recurrenceMarker.textContent = '↻';
  recurrenceMarker.title = 'Regeltermin';
  recurrenceMarker.setAttribute('role', 'img');
  recurrenceMarker.setAttribute('aria-label', 'Regeltermin');
  recurrenceMarker.hidden = !isRecurring(event);
  wrapper.append(recurrenceMarker);
  wrapper.querySelector('.booking-category').textContent =
    `${typeLabels[kind] || kind}${event.status === 'pending' ? ' · Anfrage' : ''}`;
  wrapper.querySelector('strong').textContent = [event.team_name, event.title || event.display_title].filter(Boolean).join(' – ');
  wrapper.querySelector('.booking-time').textContent = visibleRange?.allDay
    ? 'Ganztägig'
    : `${formatTime(visibleRange?.start || event.start)}–${formatTime(visibleRange?.end || event.end)}`;
  const cabinLabel = wrapper.querySelector('.booking-cabin');
  cabinLabel.textContent = cabin;
  cabinLabel.hidden = !cabin;
  wrapper.title = [
    wrapper.querySelector('.booking-category').textContent,
    wrapper.querySelector('strong').textContent,
    wrapper.querySelector('.booking-time').textContent,
    cabinLabel.textContent,
  ].filter(Boolean).join(' · ');
}

function renderResourceDay() {
  const target = document.querySelector(`#${state.resourceType}-day`);
  if (!target) return;
  target.replaceChildren();

  const resources = state.resources.filter((resource) => resource.type === state.resourceType);
  const grid = document.createElement('div');
  grid.className = 'resource-day-grid';
  grid.style.setProperty('--resource-count', Math.max(resources.length, 1));
  grid.style.width = '100%';
  const axis = document.createElement('div');
  axis.className = 'resource-time-axis';
  axis.append(document.createElement('div'));
  const firstHour = state.showFullDay ? 0 : 8;
  const lastHour = state.showFullDay ? 24 : 20;
  const hourCount = lastHour - firstHour;
  const layouts = resources.map(resource => {
    const events = state.events
      .filter(event => Number(event.resource_id) === Number(resource.id))
      .flatMap(event => {
        const visibleRange = eventDaySegment(event, state.date);
        return visibleRange
          ? [{ ...event, ...visibleRange, originalEvent: event, visibleRange }]
          : [];
      });
    return layoutDayEvents(events, firstHour, lastHour);
  });
  grid.style.gridTemplateColumns =
    `var(--axis-width) repeat(${Math.max(1, resources.length)}, minmax(0, 1fr))`;
  for (let hour = firstHour; hour < lastHour; hour += 1) {
    const label = document.createElement('div');
    label.className = 'resource-hour';
    label.textContent = `${String(hour).padStart(2, '0')}:00`;
    axis.append(label);
  }
  grid.append(axis);

  resources.forEach((resource, index) => {
    const column = document.createElement('section');
    column.className = 'resource-column';
    const heading = document.createElement('h3');
    heading.className = 'resource-column-heading';
    heading.textContent = resource.name;
    heading.title = resource.name;
    column.append(heading);
    const lane = document.createElement('div');
    lane.className = 'resource-lane';
    lane.style.height = `${hourCount * 64}px`;
    for (let hour = firstHour; hour < lastHour; hour += 1) {
      const line = document.createElement('div');
      line.className = 'resource-hour-line';
      lane.append(line);
    }

    layouts[index]
      .forEach(({ event, top, height, column, columns }) => {
        const card = eventCard(event.originalEvent || event, false, event.visibleRange);
        card.classList.add('resource-event');
        card.style.top = `${top}px`;
        card.style.height = `${height}px`;
        card.style.left = `calc(${column * 100 / columns}% + 3px)`;
        card.style.width = `calc(${100 / columns}% - 6px)`;
        lane.append(card);
      });

    column.append(lane);
    grid.append(column);
  });

  target.append(grid);
}

function renderResourceWeek() {
  const target = document.querySelector(`#${state.resourceType}-week`);
  if (!target) return;
  target.replaceChildren();
  const start = weekStart(state.date);
  const days = Array.from({ length: 7 }, (_, index) => {
    const date = new Date(start);
    date.setDate(date.getDate() + index);
    return date;
  });
  const resources = state.resources.filter((resource) => resource.type === state.resourceType);
  const table = document.createElement('table');
  table.className = 'table table-bordered table-sm resource-week-table';
  const columns = document.createElement('colgroup');
  const resourceColumn = document.createElement('col');
  resourceColumn.className = 'resource-week-resource-column';
  columns.append(resourceColumn);
  days.forEach(() => columns.append(document.createElement('col')));
  table.append(columns);
  const head = document.createElement('thead');
  const headingRow = document.createElement('tr');
  const empty = document.createElement('th');
  empty.textContent = { bus: 'Fahrzeug', cabin: 'Kabine', pitch: 'Platz' }[state.resourceType] || 'Ressource';
  headingRow.append(empty);
  days.forEach((date) => {
    const th = document.createElement('th');
    th.textContent = date.toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit' });
    headingRow.append(th);
  });
  head.append(headingRow);
  table.append(head);
  const body = document.createElement('tbody');
  resources.forEach((resource) => {
    const row = document.createElement('tr');
    const name = document.createElement('th');
    name.textContent = resource.name;
    row.append(name);
    days.forEach((date) => {
      const cell = document.createElement('td');
      state.events
        .filter((event) => Number(event.resource_id) === Number(resource.id))
        .forEach((event) => {
          const visibleRange = eventDaySegment(event, date);
          if (visibleRange) cell.append(eventCard(event, true, visibleRange));
        });
      row.append(cell);
    });
    body.append(row);
  });
  table.append(body);
  target.append(table);
}

function showEvent(event) {
  if (!detailsDialog) return;
  detailsDialog.querySelector('[data-event-error]').hidden = true;
  selectedEvent = event;
  const set = (selector, value) => {
    const element = detailsDialog.querySelector(selector);
    if (element) element.textContent = value || '—';
  };
  set('[data-event-title]', [event.team_name, event.title || event.display_title].filter(Boolean).join(' – '));
  set('[data-event-resource]', event.resource_name);
  set('[data-event-resources]', event.resource_names || event.resource_name);
  const formatDateTime = (value) => new Intl.DateTimeFormat('de-DE', {
    timeZone: 'Europe/Berlin',
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value));
  set('[data-event-start]', formatDateTime(event.start));
  set('[data-event-end]', formatDateTime(event.end));
  set('[data-event-cabins]', event.cabins || 'Keine Kabine');
  const typeLabels = { training: 'Training', match: 'Spiel', external: 'Externe Belegung', bus: 'Fahrzeug' };
  set('[data-event-type]', typeLabels[event.event_type] || event.event_type);
  const pending = event.status === 'pending';
  set('[data-event-status]', pending ? 'Anfrage (offen)' : 'Bestätigt');
  const editButton = detailsDialog.querySelector('[data-edit-event]');
  if (editButton) editButton.hidden = pending || event.occurrence_status === 'pending';
  const requestsLink = detailsDialog.querySelector('[data-event-request]');
  if (requestsLink) requestsLink.hidden = !pending;
  set('[data-event-destination]', event.destination);
  set('[data-event-purpose]', event.purpose);
  set('[data-event-driver]', event.driver_name);
  set('[data-event-notes]', event.private_notes);
  detailsDialog.querySelectorAll('[data-event-bus-field]').forEach((element) => {
    element.hidden = event.event_type !== 'bus';
  });
  detailsDialog.querySelectorAll('[data-event-pitch-field]').forEach((element) => {
    element.hidden = event.event_type === 'bus';
  });
  const rule = event.recurrence_rule;
  let recurrence = 'Keine';
  if (rule && rule.frequency !== 'none') {
    const frequencies = { daily: 'Täglich', weekly: 'Wöchentlich', monthly: 'Monatlich', yearly: 'Jährlich' };
    const units = { daily: 'Tage', weekly: 'Wochen', monthly: 'Monate', yearly: 'Jahre' };
    const weekdays = { MO: 'Mo', TU: 'Di', WE: 'Mi', TH: 'Do', FR: 'Fr', SA: 'Sa', SU: 'So' };
    recurrence = Number(rule.interval || 1) === 1
      ? frequencies[rule.frequency] : `Alle ${rule.interval} ${units[rule.frequency]}`;
    if (rule.frequency === 'weekly' && rule.weekdays?.length) {
      recurrence += ` · ${rule.weekdays.map((day) => weekdays[day]).join(', ')}`;
    }
    if (rule.limit_type === 'count') {
      recurrence += ` · ${rule.count} Termine`;
    } else if (rule.limit_type === 'until') {
      const [year, month, day] = rule.until.split('-');
      recurrence += ` · bis ${day}.${month}.${year}`;
    }
  }
  set('[data-event-recurrence]', recurrence);
  const cancel = detailsDialog.querySelector('[data-cancel-form] input[name="assignment_id"]');
  if (cancel) cancel.value = event.assignment_id;
  const cancelScope = detailsDialog.querySelector('[data-cancel-form] input[name="scope"]');
  if (cancelScope) cancelScope.value = 'one';
  const cancelForm = detailsDialog.querySelector('[data-cancel-form]');
  if (cancelForm) cancelForm.hidden = pending || Boolean(event.is_attached_cabin);
  const cancelCabinForm = detailsDialog.querySelector('[data-cancel-cabin-form]');
  if (cancelCabinForm) {
    cancelCabinForm.hidden = pending || event.occurrence_status === 'pending' || !event.is_attached_cabin;
    cancelCabinForm.elements.occurrence_id.value = event.occurrence_id;
    cancelCabinForm.elements.cabin_id.value = event.cabin_id || '';
    cancelCabinForm.elements.scope.value = 'one';
  }
  detailsDialog.showModal();
}

function requestRecurrenceScope(action, form = null) {
  pendingScopeAction = { action, form };
  const verb = action === 'edit' ? 'Änderung' : 'Stornierung';
  scopeDialog.querySelector('[data-scope-message]').textContent =
    `Dieser Termin gehört zu einer Regelterminserie. Soll die ${verb} nur diesen Termin oder diesen und alle zukünftigen Termine betreffen?`;
  detailsDialog.close();
  scopeDialog.showModal();
}

function createResourceChip(resource, fieldName, initiallySelected = false, singleSelect = false) {
  const wrapper = document.createElement('div');
  wrapper.className = 'resource-chip-item';
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'resource-choice-chip';
  button.textContent = resource.name;
  button.dataset.resourceChoice = '';
  button.dataset.resourceKind = resource.type;
  button.setAttribute('aria-pressed', String(initiallySelected));

  const input = document.createElement('input');
  input.type = 'hidden';
  input.name = fieldName;
  input.value = resource.id;
  input.dataset.resourceKind = resource.type;
  input.disabled = !initiallySelected;

  button.addEventListener('click', () => {
    const selected = button.getAttribute('aria-pressed') === 'true';
    if (singleSelect && selected) return;
    if (singleSelect) {
      button.closest('.resource-choice-group').querySelectorAll('[data-resource-choice]').forEach((choice) => {
        choice.setAttribute('aria-pressed', 'false');
        const choiceInput = choice.parentElement.querySelector('input[type="hidden"]');
        if (choiceInput) choiceInput.disabled = true;
      });
    }
    button.setAttribute('aria-pressed', String(!selected));
    input.disabled = selected;
  });

  wrapper.append(button, input);
  return wrapper;
}

function syncCombinedTitle(form, allowTeamName = true) {
  const combinedField = form.elements.combined_title;
  if (combinedField.value === combinedField.dataset.initialValue
    && combinedField.value.trim() !== '' && form.elements.title.value.trim() !== ''
    && (allowTeamName || form.elements.team_name.value.trim() === '')) return;

  const combinedTitle = combinedField.value;
  if (!allowTeamName) {
    form.elements.team_name.value = '';
    form.elements.title.value = combinedTitle.trim();
    return;
  }

  const separator = ' – ';
  const separatorIndex = combinedTitle.indexOf(separator);
  if (separatorIndex === -1 || combinedTitle.slice(separatorIndex + separator.length).trim() === '') {
    form.elements.team_name.value = '';
    form.elements.title.value = combinedTitle.trim();
  } else {
    form.elements.team_name.value = combinedTitle.slice(0, separatorIndex).trim();
    form.elements.title.value = combinedTitle.slice(separatorIndex + separator.length).trim();
  }
}

function validateRequiredNonWhitespace(field) {
  const hasNonWhitespace = /[^\s\p{Z}]/u.test(field.value);
  field.setCustomValidity(hasNonWhitespace ? '' : 'Bitte gib eine Mannschaft oder einen Hinweis an.');
}

function initRequiredNonWhitespace(form) {
  const field = form.querySelector('[data-required-non-whitespace]');
  if (!field) return;
  const validate = () => validateRequiredNonWhitespace(field);
  field.addEventListener('input', validate);
  field.addEventListener('change', validate);
  validate();
}

function renderResourceChoices() {
  const target = document.querySelector('#resource-choices');
  if (!target) return;
  target.replaceChildren();
  const type = bookingForm?.elements.event_type?.value || 'training';

  const isBus = type === 'bus';
  const mode = isBus ? 'bus' : (bookingForm?.elements.resource_mode?.value || 'pitch');
  const resourceType = isBus ? 'bus' : mode;
  const available = [...state.resources];
  for (const resource of editingBooking?.resources || []) {
    if (!available.some((item) => Number(item.id) === Number(resource.id))) {
      available.push({ ...resource, name: `${resource.name} (inaktiv)` });
    }
  }
  const resources = available.filter((resource) => resource.type === resourceType);
  const selectedIds = new Set(editingBooking?.resource_ids || []);
  const cabinIds = new Set(editingBooking?.cabin_ids || []);
  const group = document.createElement('fieldset');
  group.className = 'resource-choice-group';
  if (mode === 'pitch') group.classList.add('pitch-choice-group');
  if (isBus) group.classList.add('bus-choice-group');
  const legend = document.createElement('legend');
  legend.className = 'form-label';
  legend.textContent = mode === 'pitch'
    ? 'Plätze (mindestens einer)'
    : 'Fahrzeug';
  group.append(legend);

  if (resources.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'small text-secondary mb-0';
    empty.textContent = 'Es sind keine aktiven Ressourcen vorhanden.';
    group.append(empty);
  }

  resources.forEach((resource) => {
    const card = document.createElement('div');
    card.className = `resource-card resource-card-${resource.type}`;
    const initiallySelected = editingBooking
      ? selectedIds.has(Number(resource.id))
      : resources.length === 1;
    card.append(createResourceChip(
      resource,
      'resource_ids[]',
      initiallySelected,
      isBus,
    ));

    group.append(card);
  });
  if (mode === 'pitch') {
    const cabins = available.filter((resource) => resource.type === 'cabin');
    const cabinGroup = document.createElement('fieldset');
    cabinGroup.className = 'resource-choice-group cabin-choice-group';
    const cabinLegend = document.createElement('legend');
    cabinLegend.className = 'form-label';
    cabinLegend.textContent = 'Kabinen (optional)';
    cabinGroup.append(cabinLegend);
    if (cabins.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'small text-secondary mb-0';
      empty.textContent = 'Es sind keine aktiven Kabinen vorhanden.';
      cabinGroup.append(empty);
    } else {
      cabins.forEach((cabin) => {
        cabinGroup.append(createResourceChip(cabin, 'cabin_ids[]', cabinIds.has(Number(cabin.id))));
      });
    }
    target.append(group, cabinGroup);
  } else {
    target.append(group);
  }
}

function updateBookingFields() {
  const eventType = bookingForm?.elements.event_type?.value || 'training';
  const resourceMode = bookingForm?.elements.resource_mode?.value || 'pitch';
  const eventTypeChoice = document.querySelector('#booking-event-type-choice');
  if (eventTypeChoice) eventTypeChoice.hidden = resourceMode !== 'pitch';
  document.querySelectorAll('[data-event-type-field]').forEach((element) => {
    element.hidden = !element.dataset.eventTypeField.split(',').includes(eventType);
  });
  const recurrence = document.querySelector('#recurrence-fields');
  if (recurrence) recurrence.hidden = eventType === 'bus';
  bookingForm?.querySelectorAll('[data-edit-only]').forEach((element) => {
    element.hidden = !editingBooking;
  });
  if (bookingForm) {
    const labels = { pitch: 'Platzbelegung', bus: 'Fahrzeugbuchung' };
    bookingForm.dataset.mode = editingBooking ? 'edit' : 'create';
    bookingForm.querySelector('#booking-dialog-title').textContent =
      `${labels[resourceMode]} ${editingBooking ? 'ändern' : 'anlegen'}`;
    const submit = bookingForm.querySelector('[data-booking-submit]');
    const saved = bookingForm.dataset.saved === 'true';
    submit.textContent = saved ? 'Gespeichert' : editingBooking ? 'Änderungen speichern' : 'Speichern';
    submit.disabled = saved;
    bookingForm.querySelector('[data-booking-close]').textContent = saved ? 'Schließen' : 'Abbrechen';
    bookingForm.elements.combined_title.maxLength =
      editingBooking && ['training', 'match'].includes(eventType) ? 303 : 180;
  }
  updateRecurrenceFields();
}

function updateRecurrenceFields() {
  const frequency = bookingForm?.elements.frequency?.value || 'none';
  const limitType = bookingForm?.elements.limit_type?.value || 'until';
  document.querySelector('[data-recurrence-options]')?.toggleAttribute('hidden', frequency === 'none');
  document.querySelector('#weekday-choices')?.toggleAttribute('hidden', frequency !== 'weekly');
  document.querySelectorAll('[data-recurrence-limit]').forEach((element) => {
    element.hidden = element.dataset.recurrenceLimit !== limitType;
  });
}

function bookingError(message = '', kind = 'danger') {
  const error = bookingForm.querySelector('[data-booking-error]');
  error.className = `alert alert-${kind} mb-0`;
  error.setAttribute('role', kind === 'danger' ? 'alert' : 'status');
  error.setAttribute('aria-live', kind === 'danger' ? 'assertive' : 'polite');
  error.textContent = message;
  error.hidden = !message;
  if (message) error.scrollIntoView({ block: 'center' });
}

async function submitBooking(event) {
  event.preventDefault();
  if (bookingForm.dataset.saved === 'true') return;
  validateRequiredNonWhitespace(bookingForm.elements.combined_title);
  if (!bookingForm.reportValidity()) return;
  const eventType = bookingForm.elements.event_type.value;
  const resourceType = eventType === 'bus' ? 'bus' : bookingForm.elements.resource_mode.value;
  syncCombinedTitle(bookingForm, Boolean(editingBooking) && ['training', 'match'].includes(eventType));
  const selectedResource = resourceType === 'bus'
    ? bookingForm.querySelector('[data-resource-choice][data-resource-kind="bus"][aria-pressed="true"]')
    : bookingForm.querySelector(`[data-resource-choice][data-resource-kind="${resourceType}"][aria-pressed="true"]`);
  if (!selectedResource) {
    bookingError(resourceType === 'pitch'
      ? 'Wähle mindestens einen Platz aus.'
      : 'Wähle ein Fahrzeug aus.', 'danger');
    return;
  }
  const editing = Boolean(editingBooking);
  const submit = bookingForm.querySelector('[data-booking-submit]');
  bookingError();
  bookingForm.dataset.saved = '';
  submit.disabled = true;
  try {
    const formData = new FormData(bookingForm);
    const response = await fetch(`/?api=${editing ? 'booking-edit' : 'booking-create'}`, {
      method: 'POST', body: formData, headers: { Accept: 'application/json' },
    });
    const result = await response.json();
    if (!response.ok) {
      bookingError(result.error || 'Die Buchung konnte nicht gespeichert werden.');
      return;
    }
    const label = editing ? `Änderungen für ${result.updated} Termin(e) gespeichert.`
      : result.pending ? 'Anfrage wurde übermittelt.' : 'Buchung wurde angelegt.';
    const mailFailed = Boolean(result.mail_errors?.length);
    bookingError(
      mailFailed ? `${label} Hinweis: E-Mail-Versand fehlgeschlagen.` : label,
      mailFailed ? 'warning' : 'success',
    );
    bookingForm.dataset.saved = 'true';
    updateBookingFields();
    if (document.querySelector('#request-list')) {
      dialog.addEventListener('close', () => window.location.reload(), { once: true });
    }
    if (!editing) {
      announce(
        mailFailed ? `${label} Hinweis: E-Mail-Versand fehlgeschlagen.` : label,
        mailFailed ? 'warning' : 'success',
      );
      dialog.close();
    }
    try {
      await loadCalendar();
    } catch (error) {
      const message = `${label} Der Kalender konnte nicht aktualisiert werden: ${error.message}`;
      bookingError(message, 'warning');
      if (!editing) announce(message, 'warning');
    }
  } catch (error) {
    bookingError(error.message || 'Die Buchung konnte nicht gespeichert werden.');
  } finally {
    if (bookingForm.dataset.saved !== 'true') submit.disabled = false;
  }
}

async function saveResourceOrder(group, previousRows) {
  const status = document.querySelector('#resource-order-status');
  const formData = new FormData();
  formData.append('_csrf', group.dataset.csrfToken || app.csrfToken || '');
  formData.append('category', group.dataset.resourceOrderGroup || '');
  group.querySelectorAll('[data-resource-row]').forEach((row) => {
    formData.append('resource_ids[]', row.dataset.resourceId);
  });

  group.setAttribute('aria-busy', 'true');
  if (status) {
    status.className = 'small text-secondary';
    status.textContent = 'Reihenfolge wird gespeichert …';
  }
  try {
    const response = await fetch('/?api=resource-reorder', { method: 'POST', body: formData });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Die Reihenfolge konnte nicht gespeichert werden.');
    if (status) status.textContent = 'Reihenfolge gespeichert. Sie wird auch im Kalender verwendet.';
  } catch (error) {
    previousRows.forEach((row) => group.append(row));
    if (status) {
      status.className = 'small text-danger';
      status.textContent = error.message || 'Die Reihenfolge konnte nicht gespeichert werden.';
    }
  } finally {
    group.removeAttribute('aria-busy');
  }
}

function initResourceOrdering() {
  document.querySelectorAll('[data-resource-order-group]').forEach((group) => {
    let dragState = null;

    group.addEventListener('dragstart', (event) => {
      if (group.getAttribute('aria-busy') === 'true') {
        event.preventDefault();
        return;
      }
      const handle = event.target.closest('[data-resource-drag-handle]');
      const row = handle?.closest('[data-resource-row]');
      if (!row || row.parentElement !== group) {
        event.preventDefault();
        return;
      }
      dragState = {
        row,
        previousRows: Array.from(group.querySelectorAll('[data-resource-row]')),
      };
      row.classList.add('resource-row-dragging');
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', row.dataset.resourceId);
    });

    group.addEventListener('dragover', (event) => {
      const target = event.target.closest('[data-resource-row]');
      if (!dragState || !target || target === dragState.row || target.parentElement !== group) return;
      event.preventDefault();
      event.dataTransfer.dropEffect = 'move';
      const insertAfter = event.clientY > target.getBoundingClientRect().top + target.offsetHeight / 2;
      const reference = insertAfter ? target.nextElementSibling : target;
      if (reference !== dragState.row) group.insertBefore(dragState.row, reference);
    });

    group.addEventListener('drop', (event) => {
      if (!dragState) return;
      event.preventDefault();
      const { row, previousRows } = dragState;
      row.classList.remove('resource-row-dragging');
      dragState = null;
      void saveResourceOrder(group, previousRows);
    });

    group.addEventListener('dragend', () => {
      if (!dragState) return;
      dragState.row.classList.remove('resource-row-dragging');
      dragState.previousRows.forEach((row) => group.append(row));
      dragState = null;
    });

    group.querySelectorAll('[data-resource-drag-handle]').forEach((handle) => {
      handle.addEventListener('keydown', (event) => {
        if (!['ArrowUp', 'ArrowDown'].includes(event.key) || group.getAttribute('aria-busy') === 'true') return;
        const row = handle.closest('[data-resource-row]');
        const rows = Array.from(group.querySelectorAll('[data-resource-row]'));
        const index = rows.indexOf(row);
        const targetIndex = event.key === 'ArrowUp' ? index - 1 : index + 1;
        if (index < 0 || targetIndex < 0 || targetIndex >= rows.length) return;
        event.preventDefault();
        const previousRows = rows;
        if (event.key === 'ArrowUp') {
          group.insertBefore(row, rows[targetIndex]);
        } else {
          group.insertBefore(row, rows[targetIndex].nextElementSibling);
        }
        void saveResourceOrder(group, previousRows);
      });
    });
  });
}

function initControls() {
  const bookingDates = bookingForm ? initBookingDates(bookingForm) : null;
  if (bookingForm) initRequiredNonWhitespace(bookingForm);
  const datePicker = initDatePicker();
  initTimePicker();
  document.querySelector('#calendar-date-picker')?.addEventListener('click', (event) => {
    datePicker.open({
      value: localDate(state.date),
      trigger: event.currentTarget,
      title: 'Kalenderdatum auswählen',
      onSelect: (value) => {
        state.date = new Date(`${value}T12:00:00`);
        void loadCalendar().catch((error) => announce(error.message, 'danger'));
      },
    });
  });
  initResourceOrdering();
  document.querySelector('#resource-category-filter')?.addEventListener('change', (event) => {
    const selectedCategory = event.currentTarget.value;
    document.querySelectorAll('[data-resource-order-group]').forEach((group) => {
      group.hidden = selectedCategory !== '' && group.dataset.resourceOrderGroup !== selectedCategory;
    });
  });
  const resourceDeleteDialog = document.querySelector('#resource-delete-dialog');
  document.querySelectorAll('[data-resource-delete]').forEach((button) => {
    button.addEventListener('click', () => {
      const idField = resourceDeleteDialog?.querySelector('input[name="id"]');
      const nameField = resourceDeleteDialog?.querySelector('#resource-delete-name');
      if (!resourceDeleteDialog || !idField || !nameField) return;
      idField.value = button.dataset.resourceId;
      nameField.textContent = button.dataset.resourceName;
      resourceDeleteDialog.showModal();
    });
  });
  const resourceEditDialog = document.querySelector('#resource-edit-dialog');
  const resourceEditForm = document.querySelector('#resource-edit-form');
  document.querySelectorAll('[data-resource-edit]').forEach((button) => {
    button.addEventListener('click', () => {
      const idField = resourceEditForm?.querySelector('[name="id"]');
      const nameField = resourceEditForm?.querySelector('[name="name"]');
      if (!resourceEditDialog || !idField || !nameField) return;
      idField.value = button.dataset.resourceId;
      nameField.value = button.dataset.resourceName;
      resourceEditDialog.showModal();
    });
  });
  const deleteUserDialog = document.querySelector('#delete-user-dialog');
  document.querySelectorAll('[data-delete-user]').forEach((button) => {
    button.addEventListener('click', () => {
      const idField = deleteUserDialog?.querySelector('#delete-user-id');
      const nameField = deleteUserDialog?.querySelector('#delete-user-name');
      if (!deleteUserDialog || !idField || !nameField) return;
      idField.value = button.dataset.userId;
      nameField.textContent = button.dataset.userName;
      deleteUserDialog.showModal();
    });
  });
  document.querySelector('#calendar-resource-select')?.addEventListener('change', async (event) => {
    const select = event.currentTarget;
    const previous = state.resourceType;
    select.disabled = true;
    try {
      state.resourceType = select.value;
      await loadCalendar();
    } catch (error) {
      state.resourceType = previous;
      select.value = previous;
      announce(error.message, 'danger');
    } finally {
      select.disabled = false;
    }
  });
  document.querySelectorAll('[data-view]').forEach((button) => {
    button.addEventListener('click', async () => {
      state.view = button.dataset.view;
      await loadCalendar();
    });
  });
  document.querySelector('#calendar-prev')?.addEventListener('click', async () => {
    state.date.setDate(state.date.getDate() + (state.view === 'day' ? -1 : -7));
    await loadCalendar();
  });
  document.querySelector('#calendar-next')?.addEventListener('click', async () => {
    state.date.setDate(state.date.getDate() + (state.view === 'day' ? 1 : 7));
    await loadCalendar();
  });
  document.querySelector('#calendar-today')?.addEventListener('click', async () => {
    state.date = new Date();
    await loadCalendar();
  });
  document.querySelector('#full-day-toggle')?.addEventListener('change', async (event) => {
    state.showFullDay = event.target.checked;
    renderCalendar();
  });
  const bookingMenu = document.querySelector('#booking-create-menu');
  bookingMenu?.querySelectorAll('[data-booking-option]').forEach((button) => {
    button.addEventListener('click', () => {
      if (!bookingForm || !dialog) return;
      editingBooking = null;
      bookingError();
      bookingForm.reset();
      bookingForm.dataset.saved = '';
      bookingForm.elements.occurrence_id.value = '';
      bookingForm.elements.title.value = '';
      bookingForm.elements.team_name.value = '';
      bookingForm.elements.combined_title.dataset.initialValue = '';
      bookingForm.elements.event_type.value = button.dataset.eventType;
      bookingForm.elements.resource_mode.value = button.dataset.resourceMode;
      const start = new Date();
      start.setMinutes(Math.ceil(start.getMinutes() / 15) * 15, 0, 0);
      bookingDates.setRange(`${localDate(state.date)}T${berlinDateTimeInput(start).split('T')[1]}`);
      updateBookingFields();
      renderResourceChoices();
      bookingMenu.open = false;
      dialog.showModal();
    });
  });
  bookingForm?.querySelector('#event-type-select')?.addEventListener('change', updateBookingFields);
  document.addEventListener('click', (event) => {
    if (bookingMenu?.open && !bookingMenu.contains(event.target)) bookingMenu.open = false;
  });
  bookingMenu?.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && bookingMenu.open) {
      bookingMenu.open = false;
      bookingMenu.querySelector('summary')?.focus();
    }
  });
  bookingForm?.elements.frequency?.addEventListener('change', updateRecurrenceFields);
  bookingForm?.elements.limit_type?.addEventListener('change', updateRecurrenceFields);
  bookingForm?.addEventListener('submit', submitBooking);
  bookingForm?.addEventListener('input', () => {
    if (bookingForm.dataset.saved !== 'true') bookingError();
  });
  bookingForm?.addEventListener('change', () => {
    if (bookingForm.dataset.saved !== 'true') bookingError();
  });
  bookingForm?.addEventListener('click', (event) => {
    if (event.target.closest('[data-resource-choice]') && bookingForm.dataset.saved !== 'true') bookingError();
  });
  bookingForm?.elements.count?.addEventListener('input', (event) => {
    event.currentTarget.dataset.modified = 'true';
  });
  bookingForm?.elements.scope?.addEventListener('change', () => {
    if (editingBooking?.recurrence_rule?.limit_type === 'count'
      && bookingForm.elements.count.dataset.modified !== 'true') {
      bookingForm.elements.count.value = editingBooking.scope_counts[bookingForm.elements.scope.value];
    }
  });
  document.querySelectorAll('[data-cancel-form], [data-cancel-cabin-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (form.dataset.scopeConfirmed === 'true') {
        delete form.dataset.scopeConfirmed;
        return;
      }
      if (isRecurring(selectedEvent)) {
        event.preventDefault();
        requestRecurrenceScope('delete', form);
      }
    });
  });
  document.querySelectorAll('.request-edit-form').forEach((form) => {
    initRequiredNonWhitespace(form);
    initBookingDates(form);
    form.addEventListener('submit', () => syncCombinedTitle(form, form.dataset.allowTeamName === 'true'));
  });
  const openBookingEditor = async (scope) => {
    if (!selectedEvent || !bookingForm) return;
    const button = detailsDialog.querySelector('[data-edit-event]');
    const occurrenceId = selectedEvent.occurrence_id;
    button.disabled = true;
    try {
      const response = await fetch(`/?api=booking-details&occurrence_id=${occurrenceId}`, {
        headers: { Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Die Buchung konnte nicht geladen werden.');
      if (selectedEvent.occurrence_id !== occurrenceId) return;
      editingBooking = data;
      bookingError();
      bookingForm.reset();
      bookingForm.dataset.saved = '';
      for (const name of ['occurrence_id', 'event_type', 'resource_mode', 'title', 'team_name',
        'destination', 'purpose', 'driver_name', 'private_notes']) {
        bookingForm.elements[name].value = data[name] ?? '';
      }
      const combined = [data.team_name, data.title].filter(Boolean).join(' – ');
      bookingForm.elements.combined_title.value = combined;
      bookingForm.elements.combined_title.dataset.initialValue = combined;
      bookingDates.setRange(berlinDateTimeInput(data.start), berlinDateTimeInput(data.end));
      const rule = data.recurrence_rule;
      bookingForm.elements.frequency.value = rule?.frequency || 'none';
      bookingForm.elements.interval.value = rule?.interval || 1;
      bookingForm.elements.limit_type.value = rule?.limit_type || 'until';
      bookingForm.elements.until.value = rule?.until || '';
      bookingForm.elements.count.value = rule?.limit_type === 'count' ? data.scope_counts[scope] : 10;
      bookingForm.elements.count.dataset.modified = '';
      bookingForm.elements.scope.value = scope;
      bookingForm.querySelectorAll('[name="weekdays[]"]').forEach((input) => {
        input.checked = (rule?.weekdays || []).includes(input.value);
      });
      updateBookingFields();
      renderResourceChoices();
      detailsDialog.close();
      dialog.showModal();
    } catch (error) {
      if (!detailsDialog.open) detailsDialog.showModal();
      const message = detailsDialog.querySelector('[data-event-error]');
      message.textContent = error.message;
      message.hidden = false;
    } finally {
      button.disabled = false;
    }
  };
  detailsDialog?.querySelector('[data-edit-event]')?.addEventListener('click', () => {
    if (isRecurring(selectedEvent)) {
      requestRecurrenceScope('edit');
    } else {
      void openBookingEditor('one');
    }
  });
  scopeDialog?.querySelectorAll('[data-scope-choice]').forEach((button) => {
    button.addEventListener('click', () => {
      if (!pendingScopeAction) return;
      const { action, form } = pendingScopeAction;
      const scope = button.dataset.scopeChoice;
      pendingScopeAction = null;
      scopeDialog.close();
      if (action === 'edit') {
        void openBookingEditor(scope);
      } else if (form) {
        form.elements.scope.value = scope;
        form.dataset.scopeConfirmed = 'true';
        form.requestSubmit();
      }
    });
  });
  scopeDialog?.querySelectorAll('[data-scope-cancel]').forEach((button) => {
    button.addEventListener('click', () => {
      pendingScopeAction = null;
      scopeDialog.close();
      detailsDialog.showModal();
    });
  });
  scopeDialog?.addEventListener('cancel', (event) => {
    event.preventDefault();
    pendingScopeAction = null;
    scopeDialog.close();
    detailsDialog.showModal();
  });
  document.querySelectorAll('[data-dialog-close]').forEach((button) => {
    button.addEventListener('click', () => button.closest('dialog')?.close());
  });
}

initControls();
loadResources()
  .then(loadCalendar)
  .catch((error) => announce(error.message, 'danger'));
