const MIN_DATE = '0001-01-01';
const MAX_DATE = '9999-12-31';
const calendarIcon = '<svg aria-hidden="true" viewBox="0 0 24 24"><path d="M7 3v4m10-4v4M4 10h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z"/></svg>';

function format(date) {
  return date.toISOString().split('T')[0];
}

function parse(value) {
  const date = new Date(`${value}T12:00:00Z`);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value) || Number.isNaN(date.getTime())
    || format(date) !== value || value < MIN_DATE || value > MAX_DATE) {
    throw new RangeError('Ungültiges Kalenderdatum.');
  }
  return date;
}

export function addDays(value, days) {
  const date = parse(value);
  date.setUTCDate(date.getUTCDate() + days);
  return format(date);
}

export function shiftMonth(value, months) {
  const date = parse(value);
  const day = date.getUTCDate();
  date.setUTCDate(1);
  date.setUTCMonth(date.getUTCMonth() + months);
  const last = new Date(date);
  last.setUTCMonth(last.getUTCMonth() + 1, 0);
  date.setUTCDate(Math.min(day, last.getUTCDate()));
  return format(date);
}

export function monthDays(value) {
  const date = parse(value);
  date.setUTCDate(1);
  date.setUTCDate(1 - ((date.getUTCDay() + 6) % 7));
  return Array.from({ length: 42 }, (_, index) => {
    const day = new Date(date);
    day.setUTCDate(day.getUTCDate() + index);
    return format(day);
  });
}

function today() {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export function initDatePicker(root = document) {
  const picker = document.createElement('dialog');
  picker.id = 'date-picker-dialog';
  picker.className = 'date-picker-dialog';
  picker.setAttribute('aria-labelledby', 'date-picker-title');
  picker.innerHTML = `<div class="date-picker-heading">
    <div><p class="date-picker-eyebrow" id="date-picker-title">Datum auswählen</p><p class="date-picker-caption"></p></div>
    <button type="button" class="btn-close" aria-label="Schließen" data-date-close></button>
  </div>
  <div class="date-picker-content">
    <div class="date-picker-navigation">
      <button type="button" class="date-picker-nav" aria-label="Vorheriger Monat" data-date-previous>&#8249;</button>
      <div class="date-picker-month-controls">
        <select aria-label="Monat" class="form-select" data-date-month></select>
        <input aria-label="Jahr" class="form-control" type="number" min="1" max="9999" required data-date-year>
      </div>
      <button type="button" class="date-picker-nav" aria-label="Nächster Monat" data-date-next>&#8250;</button>
    </div>
    <div role="grid" aria-label="Kalender" class="date-picker-grid" data-date-grid></div>
    <p class="date-picker-hint">Mit den Pfeiltasten navigieren, mit Enter auswählen.</p>
  </div>
  <div class="date-picker-footer"><button type="button" class="btn btn-outline-primary" data-date-today>Heute</button>
    <button type="button" class="btn btn-light" data-date-close>Abbrechen</button></div>`;
  document.body.append(picker);
  const grid = picker.querySelector('[data-date-grid]');
  const monthInput = picker.querySelector('[data-date-month]');
  const yearInput = picker.querySelector('[data-date-year]');
  const longDate = new Intl.DateTimeFormat('de-DE', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC',
  });
  const monthName = new Intl.DateTimeFormat('de-DE', { month: 'long', timeZone: 'UTC' });
  for (let month = 0; month < 12; month += 1) {
    const option = document.createElement('option');
    option.value = String(month + 1);
    option.textContent = monthName.format(new Date(Date.UTC(2026, month, 1)));
    monthInput.append(option);
  }
  let active = null;
  let focused = today();
  let visible = focused;
  let returnFocus = null;

  function allowed(value) {
    return /^\d{4}-\d{2}-\d{2}$/.test(value)
      && value >= (active?.min || MIN_DATE) && value <= (active?.max || MAX_DATE);
  }

  function choose(value) {
    if (!allowed(value)) return;
    const onSelect = active.onSelect;
    picker.close();
    onSelect(value);
  }

  function render(focusDay = false) {
    monthInput.value = String(Number(visible.slice(5, 7)));
    yearInput.value = String(Number(visible.slice(0, 4)));
    picker.querySelector('[data-date-previous]').disabled = visible.startsWith('0001-01');
    picker.querySelector('[data-date-next]').disabled = visible.startsWith('9999-12');
    picker.querySelector('[data-date-today]').disabled = !allowed(today());
    grid.setAttribute('aria-label', `${monthInput.selectedOptions[0].textContent} ${yearInput.value}`);
    grid.replaceChildren();
    const header = document.createElement('div');
    header.className = 'date-picker-week date-picker-weekdays';
    header.setAttribute('role', 'row');
    for (const day of ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']) {
      const cell = document.createElement('span');
      cell.setAttribute('role', 'columnheader');
      cell.textContent = day;
      header.append(cell);
    }
    grid.append(header);
    const days = monthDays(visible);
    for (let week = 0; week < 6; week += 1) {
      const row = document.createElement('div');
      row.className = 'date-picker-week';
      row.setAttribute('role', 'row');
      for (const value of days.slice(week * 7, week * 7 + 7)) {
        const cell = document.createElement('div');
        cell.setAttribute('role', 'gridcell');
        cell.setAttribute('aria-selected', String(value === active.value));
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'date-picker-day';
        button.dataset.date = value;
        button.textContent = String(Number(value.slice(-2)));
        button.classList.toggle('is-outside', value.slice(0, 7) !== visible.slice(0, 7));
        button.classList.toggle('is-selected', value === active.value);
        button.disabled = !allowed(value);
        button.tabIndex = value === focused ? 0 : -1;
        button.setAttribute('aria-label', longDate.format(new Date(`${value}T12:00:00Z`)));
        if (value === today()) button.setAttribute('aria-current', 'date');
        button.addEventListener('click', () => choose(value));
        cell.append(button);
        row.append(cell);
      }
      grid.append(row);
    }
    if (focusDay) grid.querySelector(`[data-date="${focused}"]`)?.focus();
  }

  function moveMonth(amount) {
    focused = shiftMonth(focused, amount);
    visible = focused;
    render();
  }

  function open(options) {
    active = options;
    focused = options.value || today();
    parse(focused);
    visible = focused;
    returnFocus = options.trigger || document.activeElement;
    picker.querySelector('#date-picker-title').textContent = options.title || 'Datum auswählen';
    picker.querySelector('.date-picker-caption').textContent = longDate.format(parse(focused));
    render();
    picker.showModal();
    grid.querySelector(`[data-date="${focused}"]`)?.focus();
  }

  picker.querySelector('[data-date-previous]').addEventListener('click', () => moveMonth(-1));
  picker.querySelector('[data-date-next]').addEventListener('click', () => moveMonth(1));
  picker.querySelector('[data-date-today]').addEventListener('click', () => choose(today()));
  picker.querySelectorAll('[data-date-close]').forEach((button) => {
    button.addEventListener('click', () => picker.close());
  });
  picker.addEventListener('close', () => returnFocus?.focus());
  function changeMonth() {
    if (!yearInput.reportValidity()) return;
    visible = `${yearInput.value.padStart(4, '0')}-${monthInput.value.padStart(2, '0')}-01`;
    focused = visible;
    render();
  }
  monthInput.addEventListener('change', changeMonth);
  yearInput.addEventListener('change', changeMonth);
  grid.addEventListener('keydown', (event) => {
    const value = event.target.dataset.date;
    if (!value) return;
    let next;
    const weekday = (parse(value).getUTCDay() + 6) % 7;
    switch (event.key) {
      case 'ArrowLeft': next = addDays(value, -1); break;
      case 'ArrowRight': next = addDays(value, 1); break;
      case 'ArrowUp': next = addDays(value, -7); break;
      case 'ArrowDown': next = addDays(value, 7); break;
      case 'Home': next = addDays(value, -weekday); break;
      case 'End': next = addDays(value, 6 - weekday); break;
      case 'PageUp': next = shiftMonth(value, event.shiftKey ? -12 : -1); break;
      case 'PageDown': next = shiftMonth(value, event.shiftKey ? 12 : 1); break;
      default: return;
    }
    event.preventDefault();
    if (!allowed(next)) return;
    focused = next;
    visible = next;
    render(true);
  });

  root.querySelectorAll('input[type="date"]').forEach((input) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'date-input-wrap';
    input.before(wrapper);
    input.classList.add('date-picker-input');
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'date-picker-trigger';
    trigger.innerHTML = calendarIcon;
    trigger.setAttribute('aria-haspopup', 'dialog');
    const label = root.querySelector(`label[for="${input.id}"]`)?.textContent || 'Datum';
    const legend = input.closest('fieldset')?.querySelector('legend')?.textContent;
    const title = `${label}${legend ? ` für ${legend}` : ''} auswählen`;
    trigger.setAttribute('aria-label', title);
    const show = () => open({
      value: input.value, trigger, title, min: input.min, max: input.max,
      onSelect: (value) => {
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      },
    });
    trigger.addEventListener('click', show);
    input.addEventListener('keydown', (event) => {
      if ((event.altKey && event.key === 'ArrowDown') || event.key === 'F4') {
        event.preventDefault();
        show();
      }
    });
    wrapper.append(input, trigger);
  });
  return { open };
}
