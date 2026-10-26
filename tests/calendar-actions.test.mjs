import assert from 'node:assert/strict';
import test from 'node:test';
import { initCalendarActionsMenu, syncCalendarActionsMenu } from '../www/assets/js/calendar-actions.mjs';

class Element extends EventTarget {
  constructor(doc) {
    super();
    this.ownerDocument = doc;
    this.open = false;
    this.children = [];
  }

  contains(element) {
    return element === this || this.children.some(child => child.contains(element));
  }

  focus() {
    this.ownerDocument.activeElement = this;
  }
}

function fixture(role = 'manager') {
  const doc = new EventTarget();
  const menu = new Element(doc);
  const trigger = new Element(doc);
  const booking = role === 'public' ? null : new Element(doc);
  const bookingTrigger = new Element(doc);
  const fullDay = new Element(doc);
  const fullDayWrap = new Element(doc);
  const week = new Element(doc);
  fullDayWrap.children.push(fullDay);
  menu.children.push(trigger, fullDayWrap, week);
  menu.querySelector = () => trigger;
  if (booking) {
    booking.children.push(bookingTrigger);
    booking.querySelector = () => bookingTrigger;
  }
  doc.querySelector = selector => ({
    '#calendar-actions-menu': menu,
    '#booking-create-menu': booking,
    '#full-day-toggle': fullDay,
    '#full-day-toggle-wrap': fullDayWrap,
    '[data-view="week"]': week,
  })[selector];
  initCalendarActionsMenu(doc);
  return { doc, menu, trigger, booking, bookingTrigger, fullDay, fullDayWrap, week };
}

function event(type, properties) {
  const result = new Event(type, { cancelable: true });
  Object.entries(properties).forEach(([key, value]) => {
    Object.defineProperty(result, key, { value });
  });
  return result;
}

test('menus initialize closed for every role without responsive DOM relocation', () => {
  for (const role of ['public', 'trainer', 'manager', 'admin']) {
    const f = fixture(role);
    assert.equal(f.menu.open, false);
    assert.equal(f.booking?.open ?? false, false);
    assert.equal(f.fullDayWrap.hidden, false);
    assert.ok(f.menu.contains(f.fullDay));
  }
});

test('opening one calendar menu closes only its counterpart', () => {
  const f = fixture();
  f.menu.open = true;
  f.booking.open = true;
  f.booking.dispatchEvent(new Event('toggle'));
  assert.equal(f.menu.open, false);
  assert.equal(f.booking.open, true);
  f.menu.open = true;
  f.menu.dispatchEvent(new Event('toggle'));
  assert.equal(f.menu.open, true);
  assert.equal(f.booking.open, false);
});

test('Escape closes either menu and restores focus to the correct trigger', () => {
  const f = fixture();
  for (const [menu, trigger] of [[f.menu, f.trigger], [f.booking, f.bookingTrigger]]) {
    menu.open = true;
    const escape = event('keydown', { key: 'Escape' });
    menu.dispatchEvent(escape);
    assert.equal(menu.open, false);
    assert.ok(escape.defaultPrevented);
    assert.equal(f.doc.activeElement, trigger);
  }
});

test('outside clicks and keyboard focus close menus without closing inside interactions', () => {
  const f = fixture();
  f.menu.open = true;
  f.doc.dispatchEvent(event('pointerdown', { target: f.fullDay }));
  assert.equal(f.menu.open, true);
  f.doc.dispatchEvent(event('pointerdown', { target: f.bookingTrigger }));
  assert.equal(f.menu.open, false);
  f.booking.open = true;
  f.booking.dispatchEvent(event('focusout', { relatedTarget: f.trigger }));
  assert.equal(f.booking.open, false);
});

test('full-day checkbox stays visible and is disabled only for week view', () => {
  const f = fixture();
  f.fullDay.checked = true;
  f.week.checked = true;
  syncCalendarActionsMenu(f.doc);
  assert.equal(f.fullDay.disabled, true);
  assert.equal(f.fullDayWrap.hidden, false);
  f.week.checked = false;
  syncCalendarActionsMenu(f.doc);
  assert.equal(f.fullDay.disabled, false);
  assert.equal(f.fullDay.checked, true);
  assert.equal(f.fullDayWrap.hidden, false);
});

test('non-calendar pages need no menu handlers', () => {
  assert.doesNotThrow(() => initCalendarActionsMenu({ querySelector: () => null }));
});
