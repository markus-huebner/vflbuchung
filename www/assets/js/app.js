import 'bootstrap/dist/css/bootstrap.min.css';
import '../css/app.css';

import { initBookingDates } from './booking-dates.mjs';
import { initCalendarActionsMenu, syncCalendarActionsMenu } from './calendar-actions.mjs';
import { initCalendarSwipeNavigation, shiftCalendarDate } from './calendar-navigation.mjs';
import { initDatePicker } from './date-picker.mjs';
import {
  DEFAULT_DAY_END_HOUR,
  DEFAULT_DAY_START_HOUR,
  DAY_HOUR_HEIGHT,
  eventDaySegment,
  layoutDayEvents,
} from './calendar-layout.mjs';
import { initTimePicker } from './time-picker.mjs';

const state = {
  date: new Date(),
  view: 'day',
  showFullDay: false,
  resourceType: 'pitch',
  resources: [],
  events: [],
};
const calendarResourceTypeStorageKey = 'vflbuchung:calendar-resource-type';
const dashboardMainShareStorageKey = 'vflbuchung:calendar-dashboard-main-share';
const dashboardMainShareMin = 35;
const dashboardMainShareMax = 85;
const dashboardMainShareDefault = 75;
const savedResourceType = sessionStorage.getItem(calendarResourceTypeStorageKey);
if (savedResourceType === 'pitch' || savedResourceType === 'bus') {
  state.resourceType = savedResourceType;
}

const app = window.VereinApp || {};
const messageBox = document.querySelector('#app-message');
const dialog = document.querySelector('#booking-dialog');
const bookingForm = document.querySelector('#booking-form');
const detailsDialog = document.querySelector('#event-dialog');
const scopeDialog = document.querySelector('#recurrence-scope-dialog');
const actionConfirmDialog = document.querySelector('#action-confirm-dialog');
let selectedEvent = null;
let editingBooking = null;
let editingRequest = null;
let pendingConfirmationForm = null;
let pendingScopeAction = null;
let resourceHeadingObserver = null;

function announce(message, kind = 'info') {
  if (!messageBox || kind === 'success') return;
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
  state.events = await fetchEvents(start, end, state.resourceType);
  renderCalendar();
}

async function navigateCalendar(direction) {
  state.date = shiftCalendarDate(state.date, state.view, direction);
  try {
    await loadCalendar();
  } catch (error) {
    announce(error.message, 'danger');
  }
}

async function fetchEvents(start, end, resourceType) {
  const params = new URLSearchParams({
    api: 'events',
    type: resourceType,
    start: start.toISOString(),
    end: end.toISOString(),
  });
  const response = await fetch(`/?${params.toString()}`, { headers: { Accept: 'application/json' } });
  if (!response.ok) throw new Error('Kalenderdaten konnten nicht geladen werden.');
  return response.json();
}

async function fetchResources() {
  const response = await fetch('/?api=resources', { headers: { Accept: 'application/json' } });
  if (!response.ok) throw new Error('Ressourcen konnten nicht geladen werden.');
  const data = await response.json();
  return data.resources;
}

async function loadResources() {
  state.resources = await fetchResources();
  renderResourceChoices();
}

function initDashboardResizer() {
  const layout = document.querySelector('.calendar-dashboard-layout.has-dashboard');
  const resizer = layout?.querySelector('[data-dashboard-resizer]');
  if (!layout || !resizer) return;

  const savedShare = sessionStorage.getItem(dashboardMainShareStorageKey);
  const parsedShare = savedShare === null ? dashboardMainShareDefault : Number(savedShare);
  let mainShare = Number.isFinite(parsedShare)
    ? Math.min(dashboardMainShareMax, Math.max(dashboardMainShareMin, parsedShare))
    : dashboardMainShareDefault;

  const setMainShare = (share, persist = false) => {
    mainShare = Math.min(dashboardMainShareMax, Math.max(dashboardMainShareMin, share));
    layout.style.setProperty('--calendar-main-fr', `${mainShare}fr`);
    layout.style.setProperty('--calendar-dashboard-fr', `${100 - mainShare}fr`);
    resizer.setAttribute('aria-valuenow', String(Math.round(mainShare)));
    if (persist) sessionStorage.setItem(dashboardMainShareStorageKey, String(mainShare));
  };
  setMainShare(mainShare);

  let activePointerId = null;
  let pointerOffset = 0;
  resizer.addEventListener('pointerdown', (event) => {
    if (event.button !== 0) return;
    activePointerId = event.pointerId;
    pointerOffset = event.clientX - resizer.getBoundingClientRect().left;
    resizer.setPointerCapture(event.pointerId);
    event.preventDefault();
  });
  resizer.addEventListener('pointermove', (event) => {
    if (event.pointerId !== activePointerId) return;
    const bounds = layout.getBoundingClientRect();
    const gap = Number.parseFloat(window.getComputedStyle(layout).columnGap) || 0;
    const availableWidth = bounds.width - resizer.getBoundingClientRect().width - (gap * 2);
    if (availableWidth <= 0) return;
    const mainWidth = event.clientX - bounds.left - gap - pointerOffset;
    setMainShare((mainWidth / availableWidth) * 100, true);
  });
  const stopResizing = (event) => {
    if (event.pointerId === activePointerId) activePointerId = null;
  };
  resizer.addEventListener('pointerup', stopResizing);
  resizer.addEventListener('pointercancel', stopResizing);
  resizer.addEventListener('keydown', (event) => {
    let nextShare;
    if (event.key === 'ArrowLeft') nextShare = mainShare - 2;
    else if (event.key === 'ArrowRight') nextShare = mainShare + 2;
    else if (event.key === 'Home') nextShare = dashboardMainShareMin;
    else if (event.key === 'End') nextShare = dashboardMainShareMax;
    else return;
    event.preventDefault();
    setMainShare(nextShare, true);
  });
}

function renderCalendar() {
  document.querySelectorAll('[data-calendar-view]').forEach((element) => {
    element.hidden = element.dataset.calendarView !== state.resourceType;
  });
  const dayView = document.querySelector(`#${state.resourceType}-day`);
  const weekView = document.querySelector(`#${state.resourceType}-week`);
  if (dayView) dayView.hidden = state.view !== 'day';
  if (weekView) weekView.hidden = state.view !== 'week';
  document.querySelectorAll('[data-calendar-resource]').forEach((option) => {
    option.checked = option.dataset.calendarResource === state.resourceType;
  });
  document.querySelectorAll('[data-view]').forEach((button) => {
    button.checked = button.dataset.view === state.view;
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
  syncCalendarActionsMenu();
}

function eventCard(event, compact = false, visibleRange = null) {
  const wrapper = document.createElement('button');
  wrapper.type = 'button';
  wrapper.className = `booking-card booking-${event.event_type || 'training'}${compact ? ' booking-card-compact' : ''}`;
  wrapper.dataset.assignmentId = event.assignment_id;
  wrapper.dataset.occurrenceId = event.occurrence_id;
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
    isRecurring(event) ? 'Regeltermin' : '',
    wrapper.querySelector('strong').textContent,
    wrapper.querySelector('.booking-time').textContent,
    cabinLabel.textContent,
  ].filter(Boolean).join(' · ');
  wrapper.setAttribute('aria-label', wrapper.title);
}

function renderResourceDay() {
  const target = document.querySelector(`#${state.resourceType}-day`);
  if (!target) return;
  target.replaceChildren();

  const resources = state.resources.filter((resource) => resource.type === state.resourceType);
  const grid = document.createElement('div');
  grid.className = 'resource-day-grid';
  grid.style.setProperty('--resource-count', Math.max(resources.length, 1));
  grid.style.setProperty('--calendar-hour-height', `${DAY_HOUR_HEIGHT}px`);
  grid.style.width = '100%';
  const axis = document.createElement('div');
  axis.className = 'resource-time-axis';
  axis.append(document.createElement('div'));
  const firstHour = state.showFullDay ? 0 : DEFAULT_DAY_START_HOUR;
  const lastHour = state.showFullDay ? 24 : DEFAULT_DAY_END_HOUR;
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
    lane.style.height = `${hourCount * DAY_HOUR_HEIGHT}px`;
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
  const updateHeadingHeight = () => {
    grid.style.removeProperty('--resource-heading-height');
    const headingHeight = Math.max(
      24,
      ...[...grid.querySelectorAll('.resource-column-heading')].map(heading => heading.offsetHeight),
    );
    grid.style.setProperty('--resource-heading-height', `${headingHeight}px`);
  };
  updateHeadingHeight();
  resourceHeadingObserver?.disconnect();
  resourceHeadingObserver = new ResizeObserver(updateHeadingHeight);
  resourceHeadingObserver.observe(grid);
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
  const approveForm = detailsDialog.querySelector('[data-event-approve]');
  const rejectForm = detailsDialog.querySelector('[data-event-reject]');
  [approveForm, rejectForm].forEach((form) => {
    if (!form) return;
    form.hidden = !pending;
    form.elements.assignment_id.value = event.assignment_id;
  });
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
  const verb = action === 'edit' ? 'Änderung' : 'Löschung';
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

function setCombinedTitle(form, value) {
  const field = form.elements.combined_title;
  field.value = value;
  field.dataset.initialValue = value;
  validateRequiredNonWhitespace(field);
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
  for (const resource of [...(editingBooking?.resources || []), ...(editingRequest?.resources || [])]) {
    if (!available.some((item) => Number(item.id) === Number(resource.id))) {
      available.push({ ...resource, name: `${resource.name} (inaktiv)` });
    }
  }
  const resources = available.filter((resource) => resource.type === resourceType);
  const selectedIds = new Set(editingBooking?.resource_ids || editingRequest?.resource_ids || []);
  const cabinIds = new Set(editingBooking?.cabin_ids || []);
  const allowCabins = bookingForm?.dataset.allowCabins === 'true' && !editingRequest;
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
    const initiallySelected = editingBooking || editingRequest
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
  if (mode === 'pitch' && allowCabins) {
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
  const requestEdit = Boolean(editingRequest);
  const editing = Boolean(editingBooking) || requestEdit;
  const eventTypeChoice = document.querySelector('#booking-event-type-choice');
  if (eventTypeChoice) eventTypeChoice.hidden = resourceMode !== 'pitch';
  document.querySelectorAll('[data-event-type-field]').forEach((element) => {
    element.hidden = !element.dataset.eventTypeField.split(',').includes(eventType);
  });
  const recurrence = document.querySelector('#recurrence-fields');
  if (recurrence) recurrence.hidden = eventType === 'bus';
  bookingForm?.querySelectorAll('[data-edit-only]').forEach((element) => {
    element.hidden = !editing;
  });
  if (bookingForm) {
    const labels = { pitch: 'Platzbelegung', bus: 'Fahrzeugbuchung' };
    bookingForm.dataset.mode = requestEdit ? 'request-edit' : editingBooking ? 'edit' : 'create';
    bookingForm.querySelector('#booking-dialog-title').textContent =
      `${labels[resourceMode]} ${editing ? 'ändern' : 'anlegen'}`;
    const submit = bookingForm.querySelector('[data-booking-submit]');
    const saved = bookingForm.dataset.saved === 'true';
    const approve = bookingForm.querySelector('[data-request-approve]');
    if (approve) approve.hidden = !editingRequest?.can_approve;
    submit.textContent = saved ? 'Gespeichert'
      : editingRequest ? 'Speichern'
      : editing ? 'Änderungen speichern' : 'Speichern';
    submit.disabled = saved;
    bookingForm.querySelector('[data-booking-close]').textContent = saved ? 'Schließen' : 'Abbrechen';
    bookingForm.elements.combined_title.maxLength =
      editing && ['training', 'match'].includes(eventType) ? 303 : 180;
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
  if (kind === 'success') return;
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
  const isRequestEdit = Boolean(editingRequest);
  const editing = Boolean(editingBooking) || isRequestEdit;
  syncCombinedTitle(bookingForm, editing && ['training', 'match'].includes(eventType));
  const selectedResource = resourceType === 'bus'
    ? bookingForm.querySelector('[data-resource-choice][data-resource-kind="bus"][aria-pressed="true"]')
    : bookingForm.querySelector(`[data-resource-choice][data-resource-kind="${resourceType}"][aria-pressed="true"]`);
  if (!selectedResource) {
    bookingError(resourceType === 'pitch'
      ? 'Wähle mindestens einen Platz aus.'
      : 'Wähle ein Fahrzeug aus.', 'danger');
    return;
  }
  const submit = bookingForm.querySelector('[data-booking-submit]');
  bookingError();
  bookingForm.dataset.saved = '';
  submit.disabled = true;
  try {
    const formData = new FormData(bookingForm);
    if (!editing) {
      formData.set('show_all_requests', app.showAllRequests ? '1' : '0');
    }
    const api = isRequestEdit ? 'request-edit' : editingBooking ? 'booking-edit' : 'booking-create';
    if (isRequestEdit) {
      formData.set('occurrence_id', editingRequest.occurrence_id);
      formData.set('approve', editingRequest.approve ? '1' : '0');
    }
    const response = await fetch(`/?api=${api}`, {
      method: 'POST', body: formData, headers: { Accept: 'application/json' },
    });
    const result = await response.json();
    if (!response.ok) {
      bookingError(result.error || 'Die Buchung konnte nicht gespeichert werden.');
      return;
    }
    const wasApproved = isRequestEdit && result.approved > 0;
    const label = wasApproved ? ''
      : isRequestEdit ? 'Die Anfrage wurde geändert.'
      : editingBooking ? `Änderungen für ${result.updated} Termin(e) gespeichert.`
      : result.pending ? 'Anfrage wurde übermittelt.' : 'Buchung wurde angelegt.';
    const mailFailed = Boolean(result.mail_errors?.length);
    const message = [label, mailFailed ? 'Hinweis: E-Mail-Versand fehlgeschlagen.' : '']
      .filter(Boolean).join(' ');
    if (message) bookingError(message, mailFailed ? 'warning' : 'success');
    bookingForm.dataset.saved = 'true';
    updateBookingFields();
    const hasRequestList = Boolean(document.querySelector('#request-list'));
    const shouldRefreshRequestDashboard = hasRequestList && (isRequestEdit || result.pending);
    if (result.pending && Number.isInteger(result.request_page) && result.request_page > 0) {
      const returnUrl = new URL(window.location.href);
      returnUrl.searchParams.set('requests_page', String(result.request_page));
      window.history.replaceState(window.history.state, '', returnUrl);
    }
    if (message) announce(message, mailFailed ? 'warning' : 'success');
    dialog.close();
    try {
      if (shouldRefreshRequestDashboard) {
        if (result.pending && result.occurrence_id) {
          const dashboard = await refreshRequestDashboard();
          const button = [...(dashboard?.querySelectorAll('[data-dashboard-jump]') || [])]
            .find((entry) => entry.dataset.occurrenceId === String(result.occurrence_id));
          if (button) {
            await showRequestInCalendar(button);
            return;
          }
        } else {
          const updates = [refreshRequestDashboard(), loadCalendar()];
          await Promise.all(updates);
          return;
        }
      }
      await loadCalendar();
    } catch (error) {
      const refreshMessage = [label, `Die Anzeige konnte nicht aktualisiert werden: ${error.message}`]
        .filter(Boolean).join(' ');
      bookingError(refreshMessage, 'warning');
      announce(refreshMessage, 'warning');
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

function selectRequestRow(button) {
  document.querySelectorAll('.request-row-selected').forEach((row) => {
    row.classList.remove('request-row-selected');
  });
  button.closest('.request-row')?.classList.add('request-row-selected');
}

async function showRequestInCalendar(button) {
  selectRequestRow(button);
  document.querySelectorAll('.booking-card-highlight').forEach((booking) => {
    booking.classList.remove('booking-card-highlight');
  });
  document.querySelectorAll('.resource-lane-selected').forEach((lane) => {
    lane.classList.remove('resource-lane-selected');
  });
  const date = berlinDateTimeInput(button.dataset.start).split('T')[0];
  state.date = new Date(`${date}T12:00:00`);
  state.view = 'day';
  state.resourceType = button.dataset.resourceType;
  sessionStorage.setItem(calendarResourceTypeStorageKey, state.resourceType);
  try {
    await loadCalendar();
    const bookings = [...document.querySelectorAll('.booking-card')]
      .filter((card) => card.dataset.occurrenceId === button.dataset.occurrenceId);
    if (bookings.length === 0) throw new Error('Der angefragte Eintrag ist im Kalender nicht verfügbar.');
    bookings.forEach((booking) => {
      booking.classList.add('booking-card-highlight');
      booking.closest('.resource-lane')?.classList.add('resource-lane-selected');
    });
    bookings[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    bookings[0].focus({ preventScroll: true });
  } catch (error) {
    announce(error.message, 'danger');
  }
}

async function refreshRequestDashboard() {
  const currentDashboard = document.querySelector('#request-dashboard');
  if (!currentDashboard) return null;

  const url = new URL(window.location.href);
  url.searchParams.set('api', 'request-dashboard');
  const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
  const result = await response.json();
  if (!response.ok) throw new Error(result.error || 'Buchungsanfragen konnten nicht aktualisiert werden.');
  if (typeof result.html !== 'string') {
    throw new Error('Die aktualisierte Anfragenliste konnte nicht geladen werden.');
  }

  const template = document.createElement('template');
  template.innerHTML = result.html;
  const nextDashboard = template.content.querySelector('#request-dashboard');
  if (!nextDashboard) throw new Error('Die aktualisierte Anfragenliste ist unvollständig.');

  const selectedOccurrenceId = currentDashboard.querySelector(
    '.request-row-selected [data-occurrence-id]',
  )?.dataset.occurrenceId;
  nextDashboard.scrollTop = currentDashboard.scrollTop;
  currentDashboard.replaceWith(nextDashboard);
  if (selectedOccurrenceId) {
    const selectedButton = [...nextDashboard.querySelectorAll('[data-occurrence-id]')]
      .find((button) => button.dataset.occurrenceId === selectedOccurrenceId);
    selectedButton?.closest('.request-row')?.classList.add('request-row-selected');
  }
  return nextDashboard;
}

function initUserMenu() {
  const menu = document.querySelector('#user-menu');
  if (!menu) return;
  menu.addEventListener('keydown', event => {
    if (event.key === 'Escape' && menu.open) {
      event.preventDefault();
      menu.open = false;
      menu.querySelector('summary').focus();
    }
  });
  document.addEventListener('pointerdown', event => {
    if (menu.open && !menu.contains(event.target)) menu.open = false;
  });
  menu.addEventListener('focusout', event => {
    if (event.relatedTarget && !menu.contains(event.relatedTarget)) menu.open = false;
  });
}

function initControls() {
  initUserMenu();
  initCalendarActionsMenu();
  initDashboardResizer();
  initCalendarSwipeNavigation(document.querySelector('.calendar-frame'), {
    onPrevious: () => { void navigateCalendar(-1); },
    onNext: () => { void navigateCalendar(1); },
  });
  document.addEventListener('click', (event) => {
    const button = event.target.closest('#request-dashboard [data-dashboard-jump]');
    if (button) void showRequestInCalendar(button);
  });
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
  const resourceOptions = [...document.querySelectorAll('[data-calendar-resource]')];
  const viewControls = document.querySelector('#calendar-view-controls');
  resourceOptions.forEach((option) => {
    option.addEventListener('change', async () => {
      if (!option.checked || option.dataset.calendarResource === state.resourceType) return;
      const previous = state.resourceType;
      resourceOptions.forEach((choice) => { choice.disabled = true; });
      if (viewControls) viewControls.disabled = true;
      try {
        state.resourceType = option.dataset.calendarResource;
        await loadCalendar();
        sessionStorage.setItem(calendarResourceTypeStorageKey, state.resourceType);
      } catch (error) {
        state.resourceType = previous;
        resourceOptions.forEach((choice) => {
          choice.checked = choice.dataset.calendarResource === previous;
        });
        announce(error.message, 'danger');
      } finally {
        resourceOptions.forEach((choice) => { choice.disabled = false; });
        if (viewControls) viewControls.disabled = false;
      }
    });
  });
  document.querySelectorAll('[data-view]').forEach((button) => {
    button.addEventListener('change', async () => {
      if (!button.checked) return;
      const previous = state.view;
      resourceOptions.forEach((choice) => { choice.disabled = true; });
      if (viewControls) viewControls.disabled = true;
      try {
        state.view = button.dataset.view;
        await loadCalendar();
      } catch (error) {
        state.view = previous;
        document.querySelectorAll('[data-view]').forEach((choice) => {
          choice.checked = choice.dataset.view === previous;
        });
        announce(error.message, 'danger');
      } finally {
        resourceOptions.forEach((choice) => { choice.disabled = false; });
        if (viewControls) viewControls.disabled = false;
        syncCalendarActionsMenu();
      }
    });
  });
  document.querySelector('#calendar-prev')?.addEventListener('click', () => {
    void navigateCalendar(-1);
  });
  document.querySelector('#calendar-next')?.addEventListener('click', () => {
    void navigateCalendar(1);
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
  document.querySelectorAll('[data-booking-option]').forEach((button) => {
    button.addEventListener('click', () => {
      if (!bookingForm || !dialog) return;
      editingBooking = null;
      editingRequest = null;
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
  async function openPendingRequestEditor(button, canApprove) {
    if (!bookingForm || !dialog || !bookingDates) return;
    const occurrenceId = button.dataset.occurrenceId;
    button.disabled = true;
    try {
      const response = await fetch(`/?api=request-details&occurrence_id=${encodeURIComponent(occurrenceId)}`, {
        headers: { Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Die Anfrage konnte nicht geladen werden.');
      editingBooking = null;
      editingRequest = { ...data, can_approve: canApprove, approve: false };
      bookingError();
      bookingForm.reset();
      bookingForm.dataset.saved = '';
      for (const name of ['occurrence_id', 'event_type', 'resource_mode', 'title', 'team_name',
        'destination', 'purpose', 'driver_name', 'private_notes']) {
        bookingForm.elements[name].value = data[name] ?? '';
      }
      const combined = [data.team_name, data.title].filter(Boolean).join(' – ');
      setCombinedTitle(bookingForm, combined);
      bookingForm.elements.frequency.value = data.recurrence_rule?.frequency || 'none';
      bookingForm.elements.interval.value = data.recurrence_rule?.interval || 1;
      bookingForm.elements.limit_type.value = data.recurrence_rule?.limit_type || 'until';
      bookingForm.elements.until.value = data.recurrence_rule?.until || '';
      bookingForm.elements.count.value = data.recurrence_rule?.limit_type === 'count'
        ? data.scope_counts.one : 10;
      bookingForm.elements.count.dataset.modified = '';
      bookingForm.elements.scope.value = 'one';
      bookingForm.querySelectorAll('[name="weekdays[]"]').forEach((input) => {
        input.checked = (data.recurrence_rule?.weekdays || []).includes(input.value);
      });
      bookingDates.setRange(berlinDateTimeInput(data.start), berlinDateTimeInput(data.end));
      updateBookingFields();
      renderResourceChoices();
      dialog.showModal();
    } catch (error) {
      announce(error.message, 'danger');
    } finally {
      button.disabled = false;
    }
  }

  document.addEventListener('click', (event) => {
    const button = event.target.closest(
      '#request-dashboard [data-request-edit], #request-dashboard [data-request-approve]',
    );
    if (!button) return;
    const canApprove = button.matches('[data-request-approve]');
    selectRequestRow(button);
    void openPendingRequestEditor(button, canApprove);
  });
  bookingForm?.querySelector('[data-request-approve]')?.addEventListener('click', () => {
    if (!editingRequest?.can_approve) return;
    editingRequest.approve = true;
    bookingForm.requestSubmit();
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
  document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm-action]');
    if (!form) return;
    if (form.dataset.confirmed === 'true') {
      delete form.dataset.confirmed;
      return;
    }
    if (form.dataset.confirmAction === 'delete'
      && form.matches('[data-cancel-form], [data-cancel-cabin-form]')
      && isRecurring(selectedEvent)
      && form.dataset.scopeConfirmed !== 'true') {
      event.preventDefault();
      requestRecurrenceScope('delete', form);
      return;
    }

    delete form.dataset.scopeConfirmed;
    event.preventDefault();
    if (!actionConfirmDialog) {
      announce('Der Bestätigungsdialog ist nicht verfügbar.', 'danger');
      return;
    }

    const action = form.dataset.confirmAction;
    const rejecting = action === 'reject';
    const cabinDeletion = form.matches('[data-cancel-cabin-form]');
    const requestDeletion = form.action.includes('booking-delete');
    const title = rejecting
      ? 'Buchungsanfrage ablehnen?'
      : requestDeletion ? 'Buchungsanfrage löschen?' : 'Buchung löschen?';
    const message = rejecting
      ? 'Möchten Sie diese Buchungsanfrage wirklich ablehnen?'
      : cabinDeletion
        ? 'Möchten Sie diese Kabinenbelegung wirklich löschen?'
        : requestDeletion
          ? 'Möchten Sie diese Buchungsanfrage wirklich löschen?'
          : 'Möchten Sie diese Buchung wirklich löschen?';
    actionConfirmDialog.querySelector('[data-confirm-title]').textContent = title;
    actionConfirmDialog.querySelector('[data-confirm-message]').textContent = message;
    actionConfirmDialog.querySelector('[data-confirm-submit]').textContent = rejecting
      ? 'Anfrage ablehnen' : 'Löschen';
    pendingConfirmationForm = form;
    actionConfirmDialog.showModal();
  });
  actionConfirmDialog?.querySelector('[data-confirm-submit]')?.addEventListener('click', () => {
    if (!pendingConfirmationForm) return;
    const form = pendingConfirmationForm;
    pendingConfirmationForm = null;
    actionConfirmDialog.close();
    form.dataset.confirmed = 'true';
    form.requestSubmit();
  });
  actionConfirmDialog?.querySelector('[data-confirm-cancel]')?.addEventListener('click', () => {
    pendingConfirmationForm = null;
    actionConfirmDialog.close();
  });
  actionConfirmDialog?.addEventListener('cancel', () => {
    pendingConfirmationForm = null;
  });
  const openBookingEditor = async (scope) => {
    if (!selectedEvent || !bookingForm) return;
    const button = detailsDialog.querySelector('[data-edit-event]');
    const occurrenceId = selectedEvent.occurrence_id;
    button.disabled = true;
    editingRequest = null;
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
      setCombinedTitle(bookingForm, combined);
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
  .then(async () => {
    await loadCalendar();
  })
  .catch((error) => announce(error.message, 'danger'));
