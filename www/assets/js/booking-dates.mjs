const DEFAULT_DURATION = 90 * 60 * 1000;

function timestamp(value) {
  // Input values are Berlin wall-clock times, not times in the browser's timezone.
  return Date.parse(`${value}:00Z`);
}

function shiftDateTime(value, duration) {
  return new Date(timestamp(value) + duration).toISOString().slice(0, 16);
}

export function initBookingDates(form) {
  const fields = {
    start: {
      date: form.querySelector('[data-booking-date="start"]'),
      time: form.querySelector('[data-booking-time="start"]'),
      combined: form.elements.starts_at,
    },
    end: {
      date: form.querySelector('[data-booking-date="end"]'),
      time: form.querySelector('[data-booking-time="end"]'),
      combined: form.elements.ends_at,
    },
  };
  let duration = DEFAULT_DURATION;

  function read(part) {
    const { date, time } = fields[part];
    return date.value && time.value ? `${date.value}T${time.value.slice(0, 5)}` : '';
  }

  function write(part, value) {
    const [date, time] = value.split('T');
    fields[part].date.value = date;
    fields[part].time.value = time;
    fields[part].combined.value = value;
  }

  function sync() {
    const start = read('start');
    const end = read('end');
    fields.start.combined.value = start;
    fields.end.combined.value = end;
    fields.end.time.setCustomValidity(
      start && end && timestamp(end) <= timestamp(start)
        ? 'Das Ende muss nach dem Beginn liegen.'
        : '',
    );
    return { start, end };
  }

  function rememberDuration() {
    const { start, end } = sync();
    if (start && end) {
      const difference = timestamp(end) - timestamp(start);
      if (difference > 0) duration = difference;
    }
  }

  function startChanged() {
    const start = read('start');
    if (start) write('end', shiftDateTime(start, duration));
    sync();
  }

  function setRange(start, end = shiftDateTime(start, DEFAULT_DURATION)) {
    write('start', start);
    write('end', end);
    duration = DEFAULT_DURATION;
    rememberDuration();
  }

  for (const event of ['input', 'change']) {
    fields.start.date.addEventListener(event, startChanged);
    fields.start.time.addEventListener(event, startChanged);
    fields.end.date.addEventListener(event, rememberDuration);
    fields.end.time.addEventListener(event, rememberDuration);
  }

  if (fields.start.combined.value && fields.end.combined.value) {
    setRange(fields.start.combined.value, fields.end.combined.value);
  } else {
    sync();
  }

  return { setRange };
}
