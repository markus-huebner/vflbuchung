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

test('horizontal scrolling in an overflowing day calendar does not navigate', () => {
  const frame = new CalendarFrame();
  const navigation = [];
  const scrollSurface = { scrollWidth: 544, clientWidth: 320 };
  const target = { closest: selector => selector === '.resource-day-scroll' ? scrollSurface : null };
  initCalendarSwipeNavigation(frame, {
    onPrevious: () => navigation.push('previous'),
    onNext: () => navigation.push('next'),
  });
  frame.dispatchEvent(pointerEvent('pointerdown', {
    pointerId: 1, pointerType: 'touch', clientX: 180, clientY: 80, target,
  }));
  frame.dispatchEvent(pointerEvent('pointerup', {
    pointerId: 1, pointerType: 'touch', clientX: 100, clientY: 80, target,
  }));
  assert.deepEqual(navigation, []);
});

function scrollSwipeFixture(scrollLeft = 0) {
  const frame = new CalendarFrame();
  const doc = new EventTarget();
  frame.ownerDocument = doc;
  const navigation = [];
  const surface = { scrollWidth: 544, clientWidth: 320, scrollLeft };
  const button = {
    closest: selector => selector === '.resource-day-scroll' ? surface : button,
  };
  initCalendarSwipeNavigation(frame, {
    onPrevious: () => navigation.push('previous'),
    onNext: () => navigation.push('next'),
  });
  const touch = (x, y = 100, identifier = 1) => ({ identifier, clientX: x, clientY: y });
  const dispatch = (type, touches, changedTouches = [], target = button) => {
    const emitter = type === 'touchstart' ? frame : doc;
    emitter.dispatchEvent(pointerEvent(type, { touches, changedTouches, target }));
  };
  return { frame, doc, surface, button, navigation, touch, dispatch };
}

for (const [description, scrollLeft, endX, endY, expected, finalScrollLeft] of [
  ['left edge outward', 0, 230, 100, ['previous']],
  ['right edge outward', 224, 70, 100, ['next']],
  ['fractional right edge', 223.5, 70, 100, ['next']],
  ['fractional left edge', .5, 230, 100, ['previous']],
  ['left edge inward', 0, 70, 100, []],
  ['right edge inward', 224, 230, 100, []],
  ['middle scrolling left', 100, 230, 100, []],
  ['middle scrolling right', 100, 70, 100, []],
  ['reaching right edge', 100, 70, 100, [], 224],
  ['reaching left edge', 100, 230, 100, [], 0],
  ['short gesture', 224, 103, 100, []],
  ['vertical gesture', 224, 70, 180, []],
  ['minimum distance', 224, 102, 100, ['next']],
]) {
  test(`overflowing day calendar touch gesture: ${description}`, () => {
    const { frame, doc, surface, navigation, touch, dispatch } = scrollSwipeFixture(scrollLeft);
    dispatch('touchstart', [touch(150)]);
    // The browser takes over scrolling and cancels its pointer event sequence.
    doc.dispatchEvent(pointerEvent('pointercancel', { pointerId: 1 }));
    if (finalScrollLeft !== undefined) surface.scrollLeft = finalScrollLeft;
    dispatch('touchend', [], [touch(endX, endY)]);
    frame.dispatchEvent(pointerEvent('pointerup', { pointerId: 1, clientX: endX, clientY: endY }));
    assert.deepEqual(navigation, expected);
  });
}

test('scrolling to the edge requires a separate swipe to navigate', () => {
  const { surface, navigation, touch, dispatch } = scrollSwipeFixture(100);
  dispatch('touchstart', [touch(150)]);
  surface.scrollLeft = 224;
  dispatch('touchend', [], [touch(70)]);
  assert.deepEqual(navigation, []);
  dispatch('touchstart', [touch(150)]);
  dispatch('touchend', [], [touch(70)]);
  assert.deepEqual(navigation, ['next']);
});

test('cancelled, multi-touch and unrelated touch endings do not navigate', () => {
  const { navigation, touch, dispatch } = scrollSwipeFixture(224);
  dispatch('touchstart', [touch(150)]);
  dispatch('touchcancel', [], [touch(70)]);
  dispatch('touchend', [], [touch(70)]);
  dispatch('touchstart', [touch(150)]);
  dispatch('touchstart', [touch(150), touch(200, 100, 2)]);
  dispatch('touchend', [touch(150)], [touch(200, 100, 2)]);
  dispatch('touchend', [], [touch(70)]);
  dispatch('touchstart', [touch(150)]);
  dispatch('touchend', [], [touch(70, 100, 2)]);
  assert.deepEqual(navigation, []);
});

test('non-overflowing calendars do not navigate twice through pointer and touch events', () => {
  const { frame, doc, surface, button, navigation, touch, dispatch } = scrollSwipeFixture();
  surface.scrollWidth = surface.clientWidth;
  frame.dispatchEvent(pointerEvent('pointerdown', {
    pointerId: 1, pointerType: 'touch', clientX: 150, clientY: 100, target: button,
  }));
  dispatch('touchstart', [touch(150)]);
  doc.dispatchEvent(pointerEvent('pointerup', {
    pointerId: 1, clientX: 70, clientY: 100, target: button,
  }));
  dispatch('touchend', [], [touch(70)]);
  assert.deepEqual(navigation, ['next']);
});

test('edge swipe on a booking does not activate the booking', () => {
  const { frame, button, navigation, touch, dispatch } = scrollSwipeFixture(224);
  dispatch('touchstart', [touch(150)]);
  dispatch('touchend', [], [touch(70)]);
  const click = pointerEvent('click', { target: button });
  frame.dispatchEvent(click);
  assert.deepEqual(navigation, ['next']);
  assert.equal(click.defaultPrevented, true);
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
