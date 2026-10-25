export const QUARTER_HOUR_TIMES = Object.freeze(Array.from({ length: 48 }, (_, index) => {
  const minutes = 8 * 60 + index * 15;
  return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
}));

export function initTimePicker(root = document) {
  const document = root.nodeType === 9 ? root : root.ownerDocument;
  const pickers = Array.from(root.querySelectorAll('[data-time-picker]')).map((wrapper) => {
    const input = wrapper.querySelector('[data-booking-time]');
    const trigger = wrapper.querySelector('[data-time-picker-trigger]');
    const menu = wrapper.querySelector('[role="menu"]');
    if (!input || !trigger || !menu) return null;

    const options = QUARTER_HOUR_TIMES.map((time) => {
      const option = document.createElement('button');
      option.type = 'button';
      option.className = 'time-picker-option';
      option.textContent = time.startsWith('0') ? time.slice(1) : time;
      option.dataset.timeOption = time;
      option.setAttribute('role', 'menuitemradio');
      option.setAttribute('aria-checked', String(input.value === time));
      option.addEventListener('click', () => {
        input.value = time;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        close();
        input.focus();
      });
      return option;
    });
    menu.replaceChildren(...options);

    function syncSelection() {
      options.forEach((option) => {
        option.setAttribute('aria-checked', String(input.value === option.dataset.timeOption));
      });
    }

    function close(restoreFocus = false) {
      menu.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      if (restoreFocus) trigger.focus();
    }

    function open() {
      syncSelection();
      menu.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      (options.find((option) => option.dataset.timeOption === input.value) || options[0])
        ?.focus();
    }

    trigger.addEventListener('click', () => {
      if (menu.hidden) open();
      else close();
    });
    trigger.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown' && menu.hidden) {
        event.preventDefault();
        open();
      }
    });
    for (const eventName of ['input', 'change']) input.addEventListener(eventName, syncSelection);
    wrapper.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !menu.hidden) {
        event.preventDefault();
        close(true);
      }
    });
    menu.addEventListener('keydown', (event) => {
      const current = options.indexOf(event.target);
      if (current < 0) return;
      let next = current;
      if (event.key === 'ArrowDown') next = Math.min(current + 4, options.length - 1);
      else if (event.key === 'ArrowUp') next = Math.max(current - 4, 0);
      else if (event.key === 'ArrowRight') next = Math.min(current + 1, options.length - 1);
      else if (event.key === 'ArrowLeft') next = Math.max(current - 1, 0);
      else if (event.key === 'Home') next = 0;
      else if (event.key === 'End') next = options.length - 1;
      else return;
      event.preventDefault();
      options[next].focus();
    });

    return { wrapper, close };
  }).filter(Boolean);

  document.addEventListener('click', (event) => {
    pickers.forEach(({ wrapper, close }) => {
      if (!wrapper.contains(event.target)) close();
    });
  });

  return { close: () => pickers.forEach(({ close }) => close()) };
}
