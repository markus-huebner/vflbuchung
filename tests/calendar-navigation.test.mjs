import assert from 'node:assert/strict';
import test from 'node:test';
import {
  initCalendarScrollChaining,
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

function verticalScrollFixture({ smoothPage = false } = {}) {
  const frame = new CalendarFrame();
  const doc = new EventTarget();
  const page = { scrollHeight: 2000, clientHeight: 600 };
  const surface = {
    scrollTop: 600, scrollHeight: 900, clientHeight: 300,
    scrollTo({ top }) { this.scrollTop = top; },
  };
  const movements = [];
  let pageTop = 100;
  Object.defineProperty(page, 'scrollTop', {
    get: () => pageTop,
    set: value => {
      if (smoothPage) return;
      movements.push(value - pageTop);
      pageTop = value;
    },
  });
  page.scrollTo = ({ top, behavior }) => {
    if (behavior !== 'instant') {
      page.scrollTop = top;
      return;
    }
    movements.push(top - pageTop);
    pageTop = top;
  };
  let time = 0;
  let nextFrame = 0;
  const frames = new Map();
  doc.scrollingElement = page;
  const view = new EventTarget();
  Object.assign(view, {
    getComputedStyle: () => ({ lineHeight: '20px', fontSize: '16px' }),
    performance: { now: () => time },
    requestAnimationFrame: callback => { frames.set(++nextFrame, callback); return nextFrame; },
    cancelAnimationFrame: id => frames.delete(id),
  });
  doc.defaultView = view;
  frame.ownerDocument = doc;
  const target = { closest: () => surface };
  initCalendarScrollChaining(frame);
  const wheel = properties => {
    const event = pointerEvent('wheel', { target, deltaX: 0, deltaY: 100, deltaMode: 0, ...properties });
    frame.dispatchEvent(event);
    return event;
  };
  const touch = (x, y, identifier = 1) => ({ clientX: x, clientY: y, identifier });
  const dispatch = (type, touches) => {
    time += 16;
    const event = pointerEvent(type, { target, touches, timeStamp: time });
    (type === 'touchend' || type === 'touchcancel' ? doc : frame).dispatchEvent(event);
    return event;
  };
  const advance = (elapsed = 16) => {
    time += elapsed;
    const callbacks = [...frames.values()];
    frames.clear();
    callbacks.forEach(callback => callback(time));
  };
  return { page, surface, movements, wheel, touch, dispatch, advance, frames, doc, view };
}

for (const [description, surfaceTop, pageTop, properties, expected] of [
  ['bottom edge', 600, 0, {}, [100]],
  ['top edge', 0, 100, { deltaY: -100 }, [-100]],
  ['calendar interior', 300, 100, {}, []],
  ['page bottom', 600, 1400, {}, []],
  ['page top', 0, 0, { deltaY: -100 }, []],
  ['horizontal trackpad movement', 600, 100, { deltaX: 120 }, []],
  ['zoom gesture', 600, 100, { ctrlKey: true }, []],
  ['shift-wheel horizontal scrolling', 600, 100, { shiftKey: true }, []],
  ['line deltas', 600, 100, { deltaMode: 1, deltaY: 3 }, [60]],
  ['page deltas', 600, 100, { deltaMode: 2, deltaY: 1 }, [300]],
]) {
  test(`vertical wheel handoff: ${description}`, () => {
    const fixture = verticalScrollFixture();
    fixture.surface.scrollTop = surfaceTop;
    fixture.page.scrollTop = pageTop;
    fixture.movements.length = 0;
    const event = fixture.wheel(properties);
    assert.deepEqual(fixture.movements, expected);
    assert.equal(event.defaultPrevented, expected.length > 0 || fixture.surface.scrollTop !== surfaceTop);
  });
}

test('a vertical edge gesture scrolls the page without double-scrolling the calendar', () => {
  const { dispatch, touch, movements } = verticalScrollFixture();
  dispatch('touchstart', [touch(150, 200)]);
  assert.equal(dispatch('touchmove', [touch(152, 170)]).defaultPrevented, true);
  assert.equal(dispatch('touchmove', [touch(152, 150)]).defaultPrevented, true);
  assert.deepEqual(movements, [30, 20]);
  dispatch('touchend', []);
  dispatch('touchmove', [touch(152, 100)]);
  assert.deepEqual(movements, [30, 20]);
});

test('vertical dragging scrolls the calendar while horizontal swipes, pinching and cancellation remain native', () => {
  const { dispatch, touch, movements, surface } = verticalScrollFixture();
  surface.scrollTop = 300;
  dispatch('touchstart', [touch(150, 200)]);
  assert.equal(dispatch('touchmove', [touch(150, 150)]).defaultPrevented, true);
  assert.equal(surface.scrollTop, 350);
  surface.scrollTop = 600;
  dispatch('touchstart', [touch(150, 200)]);
  assert.equal(dispatch('touchmove', [touch(80, 190)]).defaultPrevented, false);
  dispatch('touchstart', [touch(150, 200)]);
  assert.equal(dispatch('touchmove', [touch(150, 150), touch(200, 150, 2)]).defaultPrevented, false);
  dispatch('touchstart', [touch(150, 200)]);
  dispatch('touchcancel', []);
  assert.equal(dispatch('touchmove', [touch(150, 150)]).defaultPrevented, false);
  assert.deepEqual(movements, []);
});

test('one wheel movement consumes the calendar remainder and then scrolls the page', () => {
  const { surface, page, wheel, movements } = verticalScrollFixture();
  surface.scrollTop = 580;
  const event = wheel({ deltaY: 180 });
  assert.equal(event.defaultPrevented, true);
  assert.equal(surface.scrollTop, 600);
  assert.equal(page.scrollTop, 260);
  assert.deepEqual(movements, [160]);
});

test('one touch gesture crosses the calendar edge without lifting the finger', () => {
  const { surface, page, dispatch, touch, movements } = verticalScrollFixture();
  surface.scrollTop = 580;
  dispatch('touchstart', [touch(150, 220)]);
  assert.equal(dispatch('touchmove', [touch(150, 190)]).defaultPrevented, true);
  assert.equal(surface.scrollTop, 600);
  assert.equal(page.scrollTop, 110);
  assert.equal(dispatch('touchmove', [touch(150, 140)]).defaultPrevented, true);
  assert.equal(page.scrollTop, 160);
  assert.deepEqual(movements, [10, 50]);
});

test('CSS smooth scrolling cannot delay page handoff after the calendar has scrolled', () => {
  const { surface, page, dispatch, touch, movements } = verticalScrollFixture({ smoothPage: true });
  surface.scrollTop = 500;
  dispatch('touchstart', [touch(150, 300)]);
  dispatch('touchmove', [touch(150, 220)]);
  assert.equal(surface.scrollTop, 580);
  assert.equal(dispatch('touchmove', [touch(150, 180)]).defaultPrevented, true);
  assert.equal(surface.scrollTop, 600);
  assert.equal(page.scrollTop, 120);
  assert.equal(dispatch('touchmove', [touch(150, 140)]).defaultPrevented, true);
  assert.equal(page.scrollTop, 160);
  assert.deepEqual(movements, [20, 40]);
});

test('small sideways finger jitter must not disable the following vertical gesture', () => {
  const { surface, page, dispatch, touch } = verticalScrollFixture();
  surface.scrollTop = 580;
  dispatch('touchstart', [touch(150, 220)]);
  dispatch('touchmove', [touch(152, 219)]);
  assert.equal(dispatch('touchmove', [touch(154, 180)]).defaultPrevented, true);
  dispatch('touchmove', [touch(156, 100)]);
  assert.equal(surface.scrollTop, 600);
  assert.equal(page.scrollTop, 200);
});

test('scrolling up first brings a partly hidden calendar back into view', () => {
  const { surface, page, wheel } = verticalScrollFixture();
  surface.scrollTop = 400;
  wheel({ deltaY: -160 });
  assert.equal(page.scrollTop, 0);
  assert.equal(surface.scrollTop, 340);
});

test('touch momentum continues across the edge and stops on a new gesture or cancellation', () => {
  const { surface, page, dispatch, touch, advance, frames } = verticalScrollFixture();
  surface.scrollTop = 570;
  dispatch('touchstart', [touch(150, 220)]);
  dispatch('touchmove', [touch(150, 200)]);
  dispatch('touchend', []);
  assert.equal(frames.size, 1);
  advance();
  assert.equal(surface.scrollTop, 600);
  assert.ok(page.scrollTop > 100, 'Momentum must cross from the calendar into the page');
  dispatch('touchstart', [touch(150, 200)]);
  assert.equal(frames.size, 0);
  dispatch('touchmove', [touch(150, 180)]);
  dispatch('touchcancel', []);
  advance();
  assert.equal(frames.size, 0);
});

test('a gesture paused before release does not launch momentum', () => {
  const { dispatch, touch, advance, frames } = verticalScrollFixture();
  dispatch('touchstart', [touch(150, 220)]);
  dispatch('touchmove', [touch(150, 200)]);
  advance(100);
  dispatch('touchend', []);
  assert.equal(frames.size, 0);
});

test('reversing a swipe makes momentum follow the final direction', () => {
  const { page, dispatch, touch, advance } = verticalScrollFixture();
  dispatch('touchstart', [touch(150, 200)]);
  dispatch('touchmove', [touch(150, 100)]);
  dispatch('touchmove', [touch(150, 102)]);
  const before = page.scrollTop;
  dispatch('touchend', []);
  advance();
  assert.ok(page.scrollTop < before, 'Momentum must not continue in the previous direction');
});

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
  const target = { closest: selector => selector.includes('.resource-day-scroll') ? scrollSurface : null };
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
    closest: selector => selector.includes('.resource-day-scroll') ? surface : button,
  };
  initCalendarSwipeNavigation(frame, {
    onPrevious: () => navigation.push('previous'),
    onNext: () => navigation.push('next'),
  });
  const touch = (x, y = 100, identifier = 1) => ({ identifier, clientX: x, clientY: y });
  const dispatch = (type, touches, changedTouches = [], target = button) => {
    const emitter = type === 'touchstart' || type === 'touchmove' ? frame : doc;
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

test('a vertical scroll cannot turn into date navigation when the finger later moves sideways', () => {
  const { dispatch, touch, navigation } = scrollSwipeFixture(224);
  dispatch('touchstart', [touch(150, 200)]);
  dispatch('touchmove', [touch(150, 140)]);
  dispatch('touchend', [], [touch(50, 180)]);
  assert.deepEqual(navigation, []);
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
