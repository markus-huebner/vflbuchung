const minimumSwipeDistance = 48;
const interactiveSelector = 'button, a, input, select, textarea, summary, [role="button"], [contenteditable="true"]';
const scrollSurfaceSelector = '.resource-day-scroll, .resource-week-scroll';

export function initCalendarScrollChaining(root) {
  if (!root) return;
  const doc = root.ownerDocument;
  const page = doc.scrollingElement;
  const view = doc.defaultView;
  let gesture = null;
  let momentumFrame = null;
  const stopMomentum = () => {
    if (momentumFrame !== null) view.cancelAnimationFrame(momentumFrame);
    momentumFrame = null;
  };
  const scrollElement = (element, delta) => {
    if (delta === 0) return 0;
    const before = element.scrollTop;
    const next = Math.max(0, Math.min(element.scrollHeight - element.clientHeight, before + delta));
    // Gesture deltas must stay synchronous even when the page enables CSS smooth scrolling.
    if (next !== before) element.scrollTo({ top: next, behavior: 'instant' });
    return element.scrollTop - before;
  };
  const scroll = (surface, delta) => {
    // Reveal the calendar again before scrolling its contents back up.
    const order = delta < 0 ? [page, surface] : [surface, page];
    let remaining = delta;
    for (const element of order) {
      const moved = scrollElement(element, remaining);
      remaining = remaining > 0 ? Math.max(0, remaining - moved) : Math.min(0, remaining - moved);
    }
    return delta - remaining;
  };
  const startMomentum = (surface, initialVelocity) => {
    let velocity = initialVelocity;
    let lastTime = view.performance.now();
    const step = (time) => {
      momentumFrame = null;
      if (surface.isConnected === false || surface.clientHeight === 0) return;
      const elapsed = Math.min(64, time - lastTime);
      lastTime = time;
      if (elapsed <= 0) {
        momentumFrame = view.requestAnimationFrame(step);
        return;
      }
      velocity *= Math.exp(-elapsed / 220);
      if (Math.abs(velocity) < 0.02 || scroll(surface, velocity * elapsed) === 0) return;
      momentumFrame = view.requestAnimationFrame(step);
    };
    momentumFrame = view.requestAnimationFrame(step);
  };
  root.addEventListener('wheel', (event) => {
    stopMomentum();
    if (!event.cancelable || event.defaultPrevented || event.ctrlKey || event.shiftKey
      || Math.abs(event.deltaY) <= Math.abs(event.deltaX)) return;
    const surface = event.target.closest(scrollSurfaceSelector);
    if (!surface) return;
    let unit = 1;
    if (event.deltaMode === 1) {
      const style = view.getComputedStyle(surface);
      unit = Number.parseFloat(style.lineHeight) || Number.parseFloat(style.fontSize);
    } else if (event.deltaMode === 2) {
      unit = surface.clientHeight;
    }
    if (scroll(surface, event.deltaY * unit) !== 0) event.preventDefault();
  }, { passive: false });
  root.addEventListener('touchstart', (event) => {
    stopMomentum();
    gesture = null;
    if (event.touches.length !== 1) return;
    const surface = event.target.closest(scrollSurfaceSelector);
    if (!surface) return;
    const touch = event.touches[0];
    gesture = {
      surface, id: touch.identifier, x: touch.clientX, y: touch.clientY,
      lastY: touch.clientY, lastTime: event.timeStamp, velocity: 0, vertical: false, native: false,
    };
  }, { passive: true });
  root.addEventListener('touchmove', (event) => {
    if (!gesture || event.touches.length !== 1) {
      gesture = null;
      return;
    }
    const touch = event.touches[0];
    if (touch.identifier !== gesture.id || gesture.native) return;
    const delta = gesture.lastY - touch.clientY;
    if (delta === 0) return;
    if (!gesture.vertical) {
      const distanceX = Math.abs(touch.clientX - gesture.x);
      const distanceY = Math.abs(touch.clientY - gesture.y);
      // Ignore finger jitter and only hand clearly horizontal gestures to the browser.
      if (Math.max(distanceX, distanceY) < 6) return;
      if (distanceX > distanceY * 1.25) {
        gesture.native = true;
        return;
      }
    }
    // Take vertical ownership before native scrolling makes later touch moves non-cancelable.
    const moved = scroll(gesture.surface, delta);
    if (!gesture.vertical && moved === 0) return;
    gesture.vertical = true;
    if (event.cancelable) event.preventDefault();
    const elapsed = Math.max(1, event.timeStamp - gesture.lastTime);
    const velocity = Math.max(-3, Math.min(3, delta / elapsed));
    gesture.velocity = Math.sign(velocity) === Math.sign(gesture.velocity)
      ? velocity * 0.8 + gesture.velocity * 0.2 : velocity;
    gesture.lastY = touch.clientY;
    gesture.lastTime = event.timeStamp;
  }, { passive: false });
  doc.addEventListener('touchend', (event) => {
    const completed = gesture;
    gesture = null;
    if (completed?.vertical && event.touches.length === 0
      && event.timeStamp - completed.lastTime < 80) {
      startMomentum(completed.surface, completed.velocity);
    }
  }, { passive: true });
  doc.addEventListener('touchcancel', () => {
    gesture = null;
    stopMomentum();
  }, { passive: true });
  doc.addEventListener('pointerdown', stopMomentum, { passive: true });
  doc.addEventListener('touchstart', stopMomentum, { passive: true });
  doc.addEventListener('wheel', stopMomentum, { passive: true });
  view.addEventListener('blur', stopMomentum);
}

export function shiftCalendarDate(date, view, direction) {
  const shifted = new Date(date);
  shifted.setDate(shifted.getDate() + direction * (view === 'day' ? 1 : 7));
  return shifted;
}

export function initCalendarSwipeNavigation(root, { onPrevious, onNext }) {
  if (!root) return;

  const doc = root.ownerDocument || root;
  let start = null;
  let scrollStart = null;
  let suppressedClickTarget = null;
  let suppressedClickTimer = null;

  const finishSwipe = (gesture, x, y) => {
    const deltaX = x - gesture.x;
    const deltaY = y - gesture.y;
    if (Math.abs(deltaX) < minimumSwipeDistance || Math.abs(deltaX) <= Math.abs(deltaY) * 1.25) return;
    if (deltaX < 0 ? gesture.canNext === false : gesture.canPrevious === false) return;
    if (gesture.interactiveTarget) {
      suppressedClickTarget = gesture.interactiveTarget;
      clearTimeout(suppressedClickTimer);
      suppressedClickTimer = setTimeout(() => { suppressedClickTarget = null; }, 0);
    }
    if (deltaX < 0) onNext();
    else onPrevious();
  };

  root.addEventListener('click', (event) => {
    if (!suppressedClickTarget || event.target.closest?.(interactiveSelector) !== suppressedClickTarget) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    suppressedClickTarget = null;
    clearTimeout(suppressedClickTimer);
  }, true);

  root.addEventListener('pointerdown', (event) => {
    const scrollSurface = event.target.closest?.(scrollSurfaceSelector);
    if (event.pointerType !== 'touch' || event.isPrimary === false
      || (scrollSurface && scrollSurface.scrollWidth > scrollSurface.clientWidth)) {
      start = null;
      return;
    }
    start = {
      pointerId: event.pointerId,
      x: event.clientX,
      y: event.clientY,
      interactiveTarget: event.target.closest?.(interactiveSelector) || null,
    };
  });

  doc.addEventListener('pointerup', (event) => {
    if (!start || event.pointerId !== start.pointerId) return;

    const gesture = start;
    start = null;
    finishSwipe(gesture, event.clientX, event.clientY);
  });

  doc.addEventListener('pointercancel', (event) => {
    if (event.pointerId === start?.pointerId) start = null;
  });

  root.addEventListener('touchmove', (event) => {
    if (event.touches.length !== 1) return;
    const touch = event.touches[0];
    const isVertical = gesture => gesture
      && Math.abs(touch.clientY - gesture.y) > Math.abs(touch.clientX - gesture.x) * 1.25;
    if (isVertical(start)) start = null;
    if (isVertical(scrollStart)) scrollStart = null;
  }, { passive: true });

  // Native scrolling cancels pointer events, but touchend still completes the gesture.
  root.addEventListener('touchstart', (event) => {
    scrollStart = null;
    if (event.touches.length !== 1) return;
    const surface = event.target.closest?.(scrollSurfaceSelector);
    if (!surface || surface.scrollWidth <= surface.clientWidth) return;
    const touch = event.touches[0];
    scrollStart = {
      identifier: touch.identifier,
      x: touch.clientX,
      y: touch.clientY,
      canPrevious: surface.scrollLeft <= 1,
      canNext: surface.scrollLeft >= surface.scrollWidth - surface.clientWidth - 1,
      interactiveTarget: event.target.closest?.(interactiveSelector) || null,
    };
  }, { passive: true });

  doc.addEventListener('touchend', (event) => {
    const gesture = scrollStart;
    scrollStart = null;
    if (!gesture || event.touches.length !== 0) return;
    const touch = Array.from(event.changedTouches).find(touch => touch.identifier === gesture.identifier);
    if (touch) finishSwipe(gesture, touch.clientX, touch.clientY);
  }, { passive: true });

  doc.addEventListener('touchcancel', () => {
    scrollStart = null;
  }, { passive: true });
}
