import assert from 'node:assert/strict';
import test from 'node:test';
import {
  initCalendarSwipeNavigation,
  shiftCalendarDate,
} from '../www/assets/js/calendar-navigation.mjs';

class CalendarFrame extends EventTarget {}

function pointerEvent(type, properties) {
  const result = new Event(type, { cancelable: true });
  Object.entries(properties).forEach(([key, value]) => {
    Object.defineProperty(result, key, { value });
  });
  return result;
}

test('calendar dates move by one day or one week in either direction', () => {
  const date = new Date(2026, 0, 1, 12);

  assert.equal(shiftCalendarDate(date, 'day', -1).toDateString(), new Date(2025, 11, 31, 12).toDateString());
  assert.equal(shiftCalendarDate(date, 'day', 1).toDateString(), new Date(2026, 0, 2, 12).toDateString());
  assert.equal(shiftCalendarDate(date, 'week', -1).toDateString(), new Date(2025, 11, 25, 12).toDateString());
  assert.equal(shiftCalendarDate(date, 'week', 1).toDateString(), new Date(2026, 0, 8, 12).toDateString());
  assert.equal(date.toDateString(), new Date(2026, 0, 1, 12).toDateString());
});

test('left and right touch swipes navigate next and previous', () => {
  const frame = new CalendarFrame();
  const navigation = [];
  initCalendarSwipeNavigation(frame, {
    onPrevious: () => navigation.push('previous'),
    onNext: () => navigation.push('next'),
  });

  frame.dispatchEvent(pointerEvent('pointerdown', {
    pointerId: 1, pointerType: 'touch', isPrimary: true, clientX: 180, clientY: 80, target: frame,
  }));
  frame.dispatchEvent(pointerEvent('pointerup', {
    pointerId: 1, pointerType: 'touch', clientX: 100, clientY: 90, target: frame,
  }));

  frame.dispatchEvent(pointerEvent('pointerdown', {
    pointerId: 2, pointerType: 'touch', isPrimary: true, clientX: 100, clientY: 90, target: frame,
  }));
  frame.dispatchEvent(pointerEvent('pointerup', {
    pointerId: 2, pointerType: 'touch', clientX: 180, clientY: 80, target: frame,
  }));

  assert.deepEqual(navigation, ['next', 'previous']);
});

test('short, vertical, mouse, secondary-pointer and cancelled gestures do not navigate', () => {
  const frame = new CalendarFrame();
  const navigation = [];
  initCalendarSwipeNavigation(frame, {
    onPrevious: () => navigation.push('previous'),
    onNext: () => navigation.push('next'),
  });
  const start = (pointerId, pointerType = 'touch', isPrimary = true) => frame.dispatchEvent(pointerEvent('pointerdown', {
    pointerId, pointerType, isPrimary, clientX: 150, clientY: 100, target: frame,
  }));
  const end = (pointerId, clientX, clientY = 100) => frame.dispatchEvent(pointerEvent('pointerup', {
    pointerId, pointerType: 'touch', clientX, clientY, target: frame,
  }));

  start(1);
  end(1, 125);
  start(2);
  end(2, 80, 180);
  start(3, 'mouse');
  end(3, 50);
  start(4, 'touch', false);
  end(4, 50);
  start(5);
  frame.dispatchEvent(pointerEvent('pointercancel', { pointerId: 5, pointerType: 'touch', target: frame }));
  end(5, 50);
  start(6);
  end(7, 50);

  assert.deepEqual(navigation, []);
});

test('swiping an interactive control navigates without activating it', async () => {
  const frame = new CalendarFrame();
  const navigation = [];
  const button = { closest: () => button };
  initCalendarSwipeNavigation(frame, {
    onPrevious: () => navigation.push('previous'),
    onNext: () => navigation.push('next'),
  });

  frame.dispatchEvent(pointerEvent('pointerdown', {
    pointerId: 1, pointerType: 'touch', isPrimary: true, clientX: 180, clientY: 80, target: button,
  }));
  frame.dispatchEvent(pointerEvent('pointerup', {
    pointerId: 1, pointerType: 'touch', clientX: 100, clientY: 80, target: button,
  }));

  const swipeClick = pointerEvent('click', { target: button });
  frame.dispatchEvent(swipeClick);
  assert.deepEqual(navigation, ['next']);
  assert.equal(swipeClick.defaultPrevented, true);

  await new Promise(resolve => setTimeout(resolve, 0));
  const regularClick = pointerEvent('click', { target: button });
  frame.dispatchEvent(regularClick);
  assert.equal(regularClick.defaultPrevented, false);
});
