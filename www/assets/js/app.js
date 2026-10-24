import 'bootstrap/dist/css/bootstrap.min.css';
import '../css/app.css';

import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import timeGridPlugin from '@fullcalendar/timegrid';
import deLocale from '@fullcalendar/core/locales/de';

const state = {
  date: new Date(),
  view: 'day',
  showFullDay: false,
  resourceType: 'pitch',
  resources: [],
  events: [],
  busCalendar: null,
};

const app = window.VereinApp || {};
const messageBox = document.querySelector('#app-message');
const dialog = document.querySelector('#booking-dialog');
const bookingForm = document.querySelector('#booking-form');
const detailsDialog = document.querySelector('#event-dialog');
const editDialog = document.querySelector('#edit-booking-dialog');
const editForm = document.querySelector('#edit-booking-form');
let selectedEvent = null;

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
  const year = date.getFullYear();
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
  return `${fields.year}-${fields.month}-${fields.day}T${fields.hour}:${fields.minute}`;
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
  if (state.resourceType !== 'bus') {
    const dayView = document.querySelector(`#${state.resourceType}-day`);
    const weekView = document.querySelector(`#${state.resourceType}-week`);
    if (dayView) dayView.hidden = state.view !== 'day';
    if (weekView) weekView.hidden = state.view !== 'week';
  }
  document.querySelectorAll('[data-resource-type]').forEach((button) => {
    button.classList.toggle('active', button.dataset.resourceType === state.resourceType);
  });
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
    label.textContent = state.view === 'day'
      ? start.toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
      : `${start.toLocaleDateString('de-DE', { day: 'numeric', month: 'short' })} – ${endLabel.toLocaleDateString('de-DE', { day: 'numeric', month: 'short', year: 'numeric' })}`;
  }

  if (state.resourceType === 'bus') {
    renderBusCalendar();
  } else if (state.view === 'day') {
    renderPitchDay();
  } else {
    renderPitchWeek();
  }
}

function eventCard(event, compact = false) {
  const wrapper = document.createElement('button');
  wrapper.type = 'button';
  wrapper.className = `booking-card booking-${event.event_type || 'training'}${compact ? ' booking-card-compact' : ''}`;
  fillBookingCard(wrapper, event);
  wrapper.addEventListener('click', () => showEvent(event));
  return wrapper;
}

function fillBookingCard(wrapper, event) {
  const kind = event.event_type || 'training';
  const typeLabels = { training: 'Training', match: 'Spiel', external: 'Externe Belegung', bus: 'Fahrzeug' };
  const formatTime = (value) => new Intl.DateTimeFormat('de-DE', {
    timeZone: 'Europe/Berlin',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value));
  const cabin = event.cabins || (event.resource_type === 'cabin' ? event.resource_name : '');
  wrapper.innerHTML = `<span class="booking-category"></span><strong></strong><span class="booking-time"></span><span class="booking-cabin"></span>`;
  wrapper.querySelector('.booking-category').textContent = typeLabels[kind] || kind;
  wrapper.querySelector('strong').textContent = [event.team_name, event.title || event.display_title].filter(Boolean).join(' – ');
  wrapper.querySelector('.booking-time').textContent = `${formatTime(event.start)}–${formatTime(event.end)}`;
  const cabinLabel = wrapper.querySelector('.booking-cabin');
  cabinLabel.textContent = cabin ? `Kabine: ${cabin}` : '';
  cabinLabel.hidden = !cabin;
}

function renderPitchDay() {
  const target = document.querySelector(`#${state.resourceType}-day`);
  if (!target) return;
  target.replaceChildren();

  const resources = state.resources.filter((resource) => resource.type === state.resourceType);
  const grid = document.createElement('div');
  grid.className = 'pitch-day-grid';
  grid.style.setProperty('--resource-count', Math.max(resources.length, 1));
  const axis = document.createElement('div');
  axis.className = 'pitch-time-axis';
  axis.append(document.createElement('div'));
  const firstHour = state.showFullDay ? 0 : 8;
  const lastHour = state.showFullDay ? 24 : 20;
  const hourCount = lastHour - firstHour;
  for (let hour = firstHour; hour < lastHour; hour += 1) {
    const label = document.createElement('div');
    label.className = 'pitch-hour';
    label.textContent = `${String(hour).padStart(2, '0')}:00`;
    axis.append(label);
  }
  grid.append(axis);

  resources.forEach((resource) => {
    const column = document.createElement('section');
    column.className = 'pitch-column';
    const heading = document.createElement('h3');
    heading.className = 'pitch-column-heading';
    heading.textContent = resource.name;
    column.append(heading);
    const lane = document.createElement('div');
    lane.className = 'pitch-lane';
    lane.style.height = `${hourCount * 64}px`;
    for (let hour = firstHour; hour < lastHour; hour += 1) {
      const line = document.createElement('div');
      line.className = 'pitch-hour-line';
      lane.append(line);
    }

    state.events
      .filter((event) => Number(event.resource_id) === Number(resource.id))
      .forEach((event) => {
        const start = new Date(event.start);
        const end = new Date(event.end);
        const startMinutes = Math.max(0, (start.getHours() - firstHour) * 60 + start.getMinutes());
        const endMinutes = Math.min(hourCount * 60, (end.getHours() - firstHour) * 60 + end.getMinutes());
        if (endMinutes <= startMinutes) return;
        const card = eventCard(event);
        card.classList.add('pitch-event');
        card.style.top = `${(startMinutes / 60) * 64}px`;
        card.style.height = `${Math.max(((endMinutes - startMinutes) / 60) * 64 - 3, 32)}px`;
        lane.append(card);
      });

    column.append(lane);
    grid.append(column);
  });

  target.append(grid);
}

function renderPitchWeek() {
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
  table.className = 'table table-bordered table-sm pitch-week-table';
  const head = document.createElement('thead');
  const headingRow = document.createElement('tr');
  const empty = document.createElement('th');
  empty.textContent = state.resourceType === 'cabin' ? 'Kabine' : 'Platz';
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
      const dayKey = localDate(date);
      state.events
        .filter((event) => Number(event.resource_id) === Number(resource.id) && localDate(new Date(event.start)) === dayKey)
        .forEach((event) => cell.append(eventCard(event, true)));
      row.append(cell);
    });
    body.append(row);
  });
  table.append(body);
  target.append(table);
}

function renderBusCalendar() {
  const target = document.querySelector('#bus-calendar');
  if (!target) return;
  if (!state.busCalendar) {
    state.busCalendar = new Calendar(target, {
      plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
      locale: deLocale,
      timeZone: 'Europe/Berlin',
      initialView: 'timeGridDay',
      headerToolbar: false,
      allDaySlot: false,
      slotDuration: '00:15:00',
      slotMinTime: state.showFullDay ? '00:00:00' : '08:00:00',
      slotMaxTime: state.showFullDay ? '24:00:00' : '20:00:00',
      nowIndicator: true,
      height: 'auto',
      events: [],
      eventContent(info) {
        const entry = document.createElement('div');
        entry.className = `booking-card booking-${info.event.extendedProps.event_type || 'bus'}`;
        fillBookingCard(entry, info.event.extendedProps);
        return { domNodes: [entry] };
      },
      eventClick(info) {
        showEvent(info.event.extendedProps);
      },
    });
    state.busCalendar.render();
  }
  state.busCalendar.setOption('slotMinTime', state.showFullDay ? '00:00:00' : '08:00:00');
  state.busCalendar.setOption('slotMaxTime', state.showFullDay ? '24:00:00' : '20:00:00');
  state.busCalendar.changeView(state.view === 'day' ? 'timeGridDay' : 'timeGridWeek');
  state.busCalendar.gotoDate(state.date);
  state.busCalendar.removeAllEvents();
  state.events.forEach((event) => {
    state.busCalendar.addEvent({
      id: event.assignment_id,
      title: event.title || event.display_title,
      start: event.start,
      end: event.end,
      classNames: [`booking-${event.event_type}`],
      extendedProps: event,
    });
  });
}

function showEvent(event) {
  if (!detailsDialog) return;
  selectedEvent = event;
  const set = (selector, value) => {
    const element = detailsDialog.querySelector(selector);
    if (element) element.textContent = value || '—';
  };
  set('[data-event-title]', event.display_title || event.title);
  set('[data-event-resource]', event.resource_name);
  set('[data-event-time]', `${new Date(event.start).toLocaleString('de-DE')} – ${new Date(event.end).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })}`);
  set('[data-event-cabins]', event.cabins || 'Keine Kabine');
  const typeLabels = { training: 'Training', match: 'Spiel', external: 'Externe Belegung', bus: 'Fahrzeug' };
  set('[data-event-type]', typeLabels[event.event_type] || event.event_type);
  set('[data-event-details]', [event.parent_resource_name ? `Zugeordnet zu ${event.parent_resource_name}` : '', event.team_name, event.opponent, event.destination, event.purpose].filter(Boolean).join(' · '));
  const cancel = detailsDialog.querySelector('[data-cancel-form] input[name="assignment_id"]');
  if (cancel) cancel.value = event.assignment_id;
  const cancelForm = detailsDialog.querySelector('[data-cancel-form]');
  if (cancelForm) cancelForm.hidden = Boolean(event.is_attached_cabin);
  const cancelCabinForm = detailsDialog.querySelector('[data-cancel-cabin-form]');
  if (cancelCabinForm) {
    cancelCabinForm.hidden = !event.is_attached_cabin;
    cancelCabinForm.elements.occurrence_id.value = event.occurrence_id;
    cancelCabinForm.elements.cabin_id.value = event.cabin_id || '';
  }
  detailsDialog.showModal();
}

function renderEditCabinChoices(event) {
  const field = document.querySelector('#edit-cabin-field');
  const target = document.querySelector('#edit-cabin-choices');
  if (!field || !target || !editForm) return;

  const isPitchBooking = event.resource_type === 'pitch' || event.is_attached_cabin;
  field.hidden = !isPitchBooking;
  editForm.elements.update_cabins.value = isPitchBooking ? '1' : '0';
  target.replaceChildren();
  if (!isPitchBooking) return;

  const cabins = state.resources.filter((resource) => resource.type === 'cabin');
  if (cabins.length === 0) {
    const note = document.createElement('p');
    note.className = 'small text-secondary mb-0';
    note.textContent = 'Es sind keine aktiven Kabinen vorhanden.';
    target.append(note);
    return;
  }

  const assignedCabins = new Set((event.cabin_ids || []).map(Number));
  cabins.forEach((cabin) => {
    target.append(createResourceChip(cabin, 'cabin_ids[]', assignedCabins.has(Number(cabin.id))));
  });
}

function createResourceChip(resource, fieldName, initiallySelected = false) {
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
    button.setAttribute('aria-pressed', String(!selected));
    input.disabled = selected;
  });

  wrapper.append(button, input);
  return wrapper;
}

function syncCombinedTitle(form, allowTeamName = true) {
  const combinedField = form.elements.combined_title;
  if (combinedField.value === combinedField.dataset.initialValue) return;

  const combinedTitle = combinedField.value;
  if (!allowTeamName) {
    form.elements.team_name.value = '';
    form.elements.title.value = combinedTitle.trim();
    return;
  }

  const separator = ' – ';
  const separatorIndex = combinedTitle.indexOf(separator);
  if (separatorIndex === -1) {
    form.elements.team_name.value = '';
    form.elements.title.value = combinedTitle.trim();
  } else {
    form.elements.team_name.value = combinedTitle.slice(0, separatorIndex).trim();
    form.elements.title.value = combinedTitle.slice(separatorIndex + separator.length).trim();
  }
}

async function submitEdit(event) {
  event.preventDefault();
  try {
    syncCombinedTitle(
      editForm,
      ['training', 'match'].includes(selectedEvent?.event_type)
        && (selectedEvent.resource_type === 'pitch' || selectedEvent.is_attached_cabin),
    );
    const response = await fetch('/?api=booking-edit', {
      method: 'POST',
      body: new FormData(editForm),
      headers: { Accept: 'application/json' },
    });
    const result = await response.json();
    if (!response.ok) {
      announce(result.error || 'Die Änderung konnte nicht gespeichert werden.', 'danger');
      return;
    }
    editDialog.close();
    announce(`Änderungen für ${result.updated} Termin(e) gespeichert.`, 'success');
    await loadCalendar();
  } catch (error) {
    announce(error.message || 'Die Änderung konnte nicht gespeichert werden.', 'danger');
  }
}

function renderResourceChoices() {
  const target = document.querySelector('#resource-choices');
  if (!target) return;
  target.replaceChildren();
  const type = bookingForm?.elements.event_type?.value || 'training';

  const isBus = type === 'bus';
  const mode = isBus ? 'bus' : (bookingForm?.elements.resource_mode?.value || 'pitch');
  const resourceType = isBus ? 'bus' : mode;
  const resources = state.resources.filter((resource) => resource.type === resourceType);
  const group = document.createElement('fieldset');
  group.className = 'resource-choice-group';
  const legend = document.createElement('legend');
  legend.className = 'form-label';
  legend.textContent = mode === 'pitch'
    ? 'Plätze (mindestens einen auswählen)'
    : mode === 'cabin'
      ? 'Kabinen (mindestens eine auswählen)'
      : 'Fahrzeug';
  group.append(legend);

  const description = document.createElement('p');
  description.className = 'small text-secondary mb-2';
  description.textContent = mode === 'pitch'
    ? 'Eine Platzbuchung braucht mindestens einen Platz. Kabinen werden optional der gesamten Buchung zugeordnet.'
    : mode === 'cabin'
      ? 'Für eine separate Kabinenbuchung ist kein Platz erforderlich.'
      : 'Wähle das zu buchende Fahrzeug aus.';
  group.append(description);

  if (resources.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'small text-secondary mb-0';
    empty.textContent = mode === 'cabin' ? 'Es sind keine aktiven Kabinen vorhanden.' : 'Es sind keine aktiven Ressourcen vorhanden.';
    group.append(empty);
  }

  resources.forEach((resource) => {
    const card = document.createElement('div');
    card.className = `resource-card resource-card-${resource.type}`;
    if (isBus) {
      const label = document.createElement('label');
      label.className = 'resource-choice form-check';
      const radio = document.createElement('input');
      radio.type = 'radio';
      radio.name = 'resource_ids[]';
      radio.value = resource.id;
      radio.className = 'form-check-input';
      radio.dataset.resourceKind = resource.type;
      const text = document.createElement('span');
      text.className = 'form-check-label';
      text.textContent = resource.name;
      label.append(radio, text);
      card.append(label);
    } else {
      card.append(createResourceChip(
        resource,
        'resource_ids[]',
        resources.length === 1 && (mode === 'pitch' || mode === 'cabin'),
      ));
    }

    group.append(card);
  });
  if (mode === 'pitch') {
    const cabins = state.resources.filter((resource) => resource.type === 'cabin');
    const cabinGroup = document.createElement('fieldset');
    cabinGroup.className = 'resource-choice-group cabin-choice-group';
    const cabinLegend = document.createElement('legend');
    cabinLegend.className = 'form-label';
    cabinLegend.textContent = 'Kabinen (optional für die gesamte Buchung)';
    cabinGroup.append(cabinLegend);
    if (cabins.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'small text-secondary mb-0';
      empty.textContent = 'Es sind keine aktiven Kabinen vorhanden.';
      cabinGroup.append(empty);
    } else {
      cabins.forEach((cabin) => {
        cabinGroup.append(createResourceChip(cabin, 'cabin_ids[]'));
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
  const titleLabel = document.querySelector('#booking-title-label');
  if (titleLabel) {
    titleLabel.textContent = resourceMode === 'pitch' && ['training', 'match'].includes(eventType)
      ? 'Mannschaft / Gegner (optional)'
      : 'Titel (optional)';
  }
  document.querySelectorAll('[data-event-type-field]').forEach((element) => {
    const types = element.dataset.eventTypeField.split(',');
    const isVisibleForType = types.includes(eventType);
    const isPitchOnlyField = types.some((type) => ['training', 'match'].includes(type));
    element.hidden = !isVisibleForType || (resourceMode === 'cabin' && isPitchOnlyField);
  });
  const recurrence = document.querySelector('#recurrence-fields');
  if (recurrence) recurrence.hidden = eventType === 'bus';
  updateRecurrenceFields();
  renderResourceChoices();
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

async function submitBooking(event) {
  event.preventDefault();
  const eventType = bookingForm.elements.event_type.value;
  const resourceType = eventType === 'bus' ? 'bus' : bookingForm.elements.resource_mode.value;
  syncCombinedTitle(bookingForm, resourceType === 'pitch' && ['training', 'match'].includes(eventType));
  const selectedResource = resourceType === 'bus'
    ? bookingForm.querySelector('[name="resource_ids[]"][data-resource-kind="bus"]:checked')
    : bookingForm.querySelector(`[data-resource-choice][data-resource-kind="${resourceType}"][aria-pressed="true"]`);
  if (!selectedResource) {
    announce(resourceType === 'pitch'
      ? 'Wähle mindestens einen Platz aus.'
      : resourceType === 'cabin'
        ? 'Wähle mindestens eine Kabine aus.'
        : 'Wähle ein Fahrzeug aus.', 'danger');
    return;
  }
  const formData = new FormData(bookingForm);
  formData.append('_csrf', app.csrfToken || '');
  const response = await fetch('/?api=booking-create', { method: 'POST', body: formData });
  const result = await response.json();
  if (!response.ok) {
    announce(result.error || 'Die Buchung konnte nicht gespeichert werden.', 'danger');
    return;
  }
  dialog.close();
  bookingForm.reset();
  updateBookingFields();
  const label = result.pending ? 'Anfrage wurde übermittelt.' : 'Buchung wurde angelegt.';
  announce(result.mail_errors?.length ? `${label} Hinweis: E-Mail-Versand fehlgeschlagen.` : label, result.mail_errors?.length ? 'warning' : 'success');
  await loadCalendar();
  if (document.querySelector('#request-list')) window.location.reload();
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
  document.querySelectorAll('[data-resource-type]').forEach((button) => {
    button.addEventListener('click', async () => {
      state.resourceType = button.dataset.resourceType;
      await loadCalendar();
    });
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
      bookingForm.reset();
      bookingForm.elements.event_type.value = button.dataset.eventType;
      bookingForm.elements.resource_mode.value = button.dataset.resourceMode;
      bookingForm.querySelector('#event-type-select').value = button.dataset.eventType;
      bookingForm.querySelector('#booking-selection').textContent = button.dataset.bookingLabel;
      const start = new Date();
      start.setMinutes(Math.ceil(start.getMinutes() / 15) * 15, 0, 0);
      const end = new Date(start);
      end.setMinutes(end.getMinutes() + 90);
      bookingForm.elements.starts_at.value = `${localDate(start)}T${String(start.getHours()).padStart(2, '0')}:${String(start.getMinutes()).padStart(2, '0')}`;
      bookingForm.elements.ends_at.value = `${localDate(end)}T${String(end.getHours()).padStart(2, '0')}:${String(end.getMinutes()).padStart(2, '0')}`;
      updateBookingFields();
      bookingMenu.open = false;
      dialog.showModal();
    });
  });
  bookingForm?.querySelector('#event-type-select')?.addEventListener('change', (event) => {
    bookingForm.elements.event_type.value = event.currentTarget.value;
    updateBookingFields();
  });
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
  editForm?.addEventListener('submit', submitEdit);
  document.querySelectorAll('.request-edit-form').forEach((form) => {
    form.addEventListener('submit', () => syncCombinedTitle(form, form.dataset.allowTeamName === 'true'));
  });
  detailsDialog?.querySelector('[data-edit-event]')?.addEventListener('click', () => {
    if (!selectedEvent || !editForm) return;
    editForm.elements.occurrence_id.value = selectedEvent.occurrence_id;
    const typeLabels = { training: 'Training', match: 'Spiel', external: 'Externe Belegung', bus: 'Fahrzeug' };
    editForm.querySelector('#edit-event-type').value = typeLabels[selectedEvent.event_type] || selectedEvent.event_type;
    const title = selectedEvent.title || '';
    const teamName = selectedEvent.team_name || '';
    const combinedTitle = [teamName, title].filter(Boolean).join(' – ');
    editForm.elements.title.value = title;
    editForm.elements.team_name.value = teamName;
    editForm.elements.combined_title.value = combinedTitle;
    editForm.elements.combined_title.dataset.initialValue = combinedTitle;
    editForm.querySelector('#edit-title-label').textContent = ['training', 'match'].includes(selectedEvent.event_type)
      && (selectedEvent.resource_type === 'pitch' || selectedEvent.is_attached_cabin)
      ? 'Mannschaft / Gegner (optional)'
      : 'Titel (optional)';
    editForm.elements.starts_at.value = berlinDateTimeInput(selectedEvent.start);
    editForm.elements.ends_at.value = berlinDateTimeInput(selectedEvent.end);
    editForm.elements.scope.value = 'one';
    renderEditCabinChoices(selectedEvent);
    detailsDialog.close();
    editDialog.showModal();
  });
  document.querySelectorAll('[data-dialog-close]').forEach((button) => {
    button.addEventListener('click', () => button.closest('dialog')?.close());
  });
}

initControls();
loadResources()
  .then(loadCalendar)
  .catch((error) => announce(error.message, 'danger'));
