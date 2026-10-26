export function syncCalendarActionsMenu(root = document) {
  const week = root.querySelector('[data-view="week"]')?.checked || false;
  const fullDay = root.querySelector('#full-day-toggle');
  const fullDayWrap = root.querySelector('#full-day-toggle-wrap');
  if (fullDay) fullDay.disabled = week;
  if (fullDayWrap) fullDayWrap.hidden = false;
}

export function initCalendarActionsMenu(root = document) {
  const doc = root.ownerDocument || root;
  const menus = [
    root.querySelector('#calendar-actions-menu'),
    root.querySelector('#booking-create-menu'),
  ].filter(Boolean);
  const calendarMenu = root.querySelector('#calendar-actions-menu');
  if (calendarMenu) {
    const isCalendarOption = event => event.target.closest?.('[data-calendar-menu-option]');
    calendarMenu.addEventListener('change', event => {
      if (isCalendarOption(event)) calendarMenu.open = false;
    });
    calendarMenu.addEventListener('click', event => {
      if (isCalendarOption(event)) queueMicrotask(() => { calendarMenu.open = false; });
    });
  }
  menus.forEach(menu => {
    menu.addEventListener('toggle', () => {
      if (menu.open) menus.forEach(other => { if (other !== menu) other.open = false; });
    });
    menu.addEventListener('keydown', event => {
      if (event.key === 'Escape' && menu.open) {
        event.preventDefault();
        menu.open = false;
        menu.querySelector('summary').focus();
      }
    });
    doc.addEventListener('pointerdown', event => {
      if (menu.open && !menu.contains(event.target)) menu.open = false;
    });
    menu.addEventListener('focusout', event => {
      if (menu.open && event.relatedTarget && !menu.contains(event.relatedTarget)) menu.open = false;
    });
  });
  syncCalendarActionsMenu(root);
}
