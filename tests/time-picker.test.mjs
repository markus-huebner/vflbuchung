import assert from 'node:assert/strict';
import test from 'node:test';
import { QUARTER_HOUR_TIMES, initTimePicker } from '../www/assets/js/time-picker.mjs';

class Element extends EventTarget {
  constructor() {
    super();
    this.attributes = new Map();
    this.dataset = {};
    this.children = [];
    this.hidden = false;
    this.value = '';
  }

  setAttribute(name, value) {
    this.attributes.set(name, String(value));
  }

  getAttribute(name) {
    return this.attributes.get(name) ?? null;
  }

  replaceChildren(...children) {
    this.children = children;
  }

  focus() {
    this.ownerDocument.activeElement = this;
  }
}

function fixture(value = '') {
  const document = new EventTarget();
  document.nodeType = 9;
  document.createElement = () => {
    const element = new Element();
    element.ownerDocument = document;
    return element;
  };
  const input = new Element();
  input.ownerDocument = document;
  input.value = value;
  const trigger = new Element();
  trigger.ownerDocument = document;
  const menu = new Element();
  menu.ownerDocument = document;
  menu.hidden = true;
  const wrapper = new Element();
  wrapper.ownerDocument = document;
  wrapper.nodeType = 1;
  wrapper.querySelector = selector => ({
    '[data-booking-time]': input,
    '[data-time-picker-trigger]': trigger,
    '[role="menu"]': menu,
  })[selector] ?? null;
  wrapper.contains = target => target === wrapper
    || target === input
    || target === trigger
    || target === menu
    || menu.children.includes(target);
  const root = {
    nodeType: 1,
    ownerDocument: document,
    querySelectorAll: selector => selector === '[data-time-picker]' ? [wrapper] : [],
  };
  const picker = initTimePicker(root);
  return { document, input, trigger, menu, wrapper, picker };
}

function keyEvent(key, target = null) {
  const event = new Event('keydown', { cancelable: true });
  Object.defineProperty(event, 'key', { value: key });
  if (target) Object.defineProperty(event, 'target', { value: target });
  return event;
}

test('offers all 48 quarter-hour values from 08:00 through 19:45', () => {
  assert.equal(QUARTER_HOUR_TIMES.length, 48);
  assert.equal(QUARTER_HOUR_TIMES[0], '08:00');
  assert.equal(QUARTER_HOUR_TIMES.at(-1), '19:45');
  assert.ok(QUARTER_HOUR_TIMES.every((time, index) => {
    const [hours, minutes] = time.split(':').map(Number);
    const totalMinutes = hours * 60 + minutes;
    return minutes % 15 === 0
      && (index === 0 || time > QUARTER_HOUR_TIMES[index - 1])
      && totalMinutes >= 8 * 60
      && totalMinutes <= 19 * 60 + 45;
  }));
  assert.deepEqual(QUARTER_HOUR_TIMES.slice(0, 4), ['08:00', '08:15', '08:30', '08:45']);
  assert.deepEqual(QUARTER_HOUR_TIMES.slice(-4), ['19:00', '19:15', '19:30', '19:45']);
});

test('opens the menu with the selected value focused and navigates by keyboard', () => {
  const { trigger, menu, document } = fixture('12:30');
  trigger.dispatchEvent(new Event('click'));
  assert.equal(menu.hidden, false);
  assert.equal(trigger.getAttribute('aria-expanded'), 'true');
  assert.equal(menu.children.length, 48);
  assert.deepEqual(menu.children.slice(0, 4).map(option => option.textContent), ['8:00', '8:15', '8:30', '8:45']);
  assert.deepEqual(menu.children.slice(-4).map(option => option.textContent), ['19:00', '19:15', '19:30', '19:45']);
  assert.equal(menu.children.find(option => option.dataset.timeOption === '12:30').getAttribute('aria-checked'), 'true');
  assert.equal(document.activeElement.dataset.timeOption, '12:30');

  menu.dispatchEvent(keyEvent('ArrowDown', document.activeElement));
  assert.equal(document.activeElement.dataset.timeOption, '13:30');
  menu.dispatchEvent(keyEvent('ArrowRight', document.activeElement));
  assert.equal(document.activeElement.dataset.timeOption, '13:45');
  menu.dispatchEvent(keyEvent('End', document.activeElement));
  assert.equal(document.activeElement.dataset.timeOption, '19:45');
});

test('selecting a suggestion updates the editable field and dispatches input and change', () => {
  const { input, trigger, menu, document } = fixture('12:07');
  let inputEvents = 0;
  let changeEvents = 0;
  input.addEventListener('input', () => { inputEvents += 1; });
  input.addEventListener('change', () => { changeEvents += 1; });

  trigger.dispatchEvent(new Event('click'));
  const option = menu.children.find(item => item.dataset.timeOption === '18:30');
  option.dispatchEvent(new Event('click'));

  assert.equal(input.value, '18:30');
  assert.equal(inputEvents, 1);
  assert.equal(changeEvents, 1);
  assert.equal(menu.hidden, true);
  assert.equal(trigger.getAttribute('aria-expanded'), 'false');
  assert.equal(document.activeElement, input);
});

test('Escape closes the menu and restores focus to its trigger', () => {
  const { wrapper, trigger, menu, document } = fixture();
  trigger.dispatchEvent(new Event('click'));
  wrapper.dispatchEvent(keyEvent('Escape'));
  assert.equal(menu.hidden, true);
  assert.equal(trigger.getAttribute('aria-expanded'), 'false');
  assert.equal(document.activeElement, trigger);
});
