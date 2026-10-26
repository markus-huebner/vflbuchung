const berlinTime = new Intl.DateTimeFormat('en-GB', {
  timeZone: 'Europe/Berlin',
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
});

export const DEFAULT_DAY_START_HOUR = 9;
export const DEFAULT_DAY_END_HOUR = 21;
export const DAY_HOUR_HEIGHT = 51.2;

function minutesOfDay(value) {
  const parts = Object.fromEntries(berlinTime.formatToParts(new Date(value)).map(part => [part.type, part.value]));
  return Number(parts.hour) * 60 + Number(parts.minute);
}

export function eventDaySegment(event, date) {
  const dayStart = new Date(date);
  dayStart.setHours(0, 0, 0, 0);
  const dayEnd = new Date(dayStart);
  dayEnd.setDate(dayEnd.getDate() + 1);
  const eventStart = new Date(event.start);
  const eventEnd = new Date(event.end);

  if (eventStart >= dayEnd || eventEnd <= dayStart) return null;

  const start = eventStart > dayStart ? eventStart : dayStart;
  const end = eventEnd < dayEnd ? eventEnd : dayEnd;
  return {
    start: start.toISOString(),
    end: end.toISOString(),
    allDay: start.getTime() === dayStart.getTime() && end.getTime() === dayEnd.getTime(),
  };
}

export function layoutDayEvents(events, firstHour, lastHour) {
  const laneHeight = (lastHour - firstHour) * DAY_HOUR_HEIGHT;
  const entries = events.flatMap(event => {
    const firstMinute = firstHour * 60;
    const laneMinutes = (lastHour - firstHour) * 60;
    const start = Math.max(0, minutesOfDay(event.start) - firstMinute);
    let eventEnd = minutesOfDay(event.end);
    if (eventEnd === 0 && new Date(event.end) > new Date(event.start)) eventEnd = 24 * 60;
    const end = Math.min(laneMinutes, Math.max(0, eventEnd - firstMinute));
    if (end <= start) return [];
    const top = start / 60 * DAY_HOUR_HEIGHT;
    const height = Math.min(Math.max((end - start) / 60 * DAY_HOUR_HEIGHT - 3, DAY_HOUR_HEIGHT / 2), laneHeight - top);
    return [{ event, top, height, column: 0, columns: 1 }];
  }).sort((a, b) => a.top - b.top || b.height - a.height
    || Number(a.event.assignment_id) - Number(b.event.assignment_id));

  let group = [];
  let columnEnds = [];
  let groupEnd = 0;
  const finishGroup = () => {
    group.forEach(entry => { entry.columns = columnEnds.length; });
  };
  for (const entry of entries) {
    if (entry.top >= groupEnd) {
      finishGroup();
      group = [];
      columnEnds = [];
    }
    let column = columnEnds.findIndex(end => end <= entry.top);
    if (column === -1) column = columnEnds.length;
    entry.column = column;
    columnEnds[column] = entry.top + entry.height;
    groupEnd = Math.max(...columnEnds);
    group.push(entry);
  }
  finishGroup();
  return entries;
}
