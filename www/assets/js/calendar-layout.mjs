const berlinTime = new Intl.DateTimeFormat('en-GB', {
  timeZone: 'Europe/Berlin',
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
});

function minutesOfDay(value) {
  const parts = Object.fromEntries(berlinTime.formatToParts(new Date(value)).map(part => [part.type, part.value]));
  return Number(parts.hour) * 60 + Number(parts.minute);
}

export function layoutDayEvents(events, firstHour, lastHour) {
  const laneHeight = (lastHour - firstHour) * 64;
  const entries = events.flatMap(event => {
    const start = Math.max(0, minutesOfDay(event.start) - firstHour * 60);
    const end = Math.min((lastHour - firstHour) * 60, minutesOfDay(event.end) - firstHour * 60);
    if (end <= start) return [];
    const top = start * 64 / 60;
    const height = Math.min(Math.max((end - start) * 64 / 60 - 3, 32), laneHeight - top);
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
