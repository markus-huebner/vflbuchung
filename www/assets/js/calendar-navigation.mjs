const minimumSwipeDistance = 48;
const interactiveSelector = 'button, a, input, select, textarea, summary, [role="button"], [contenteditable="true"]';

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
    const scrollSurface = event.target.closest?.('.resource-day-scroll');
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

  // Native scrolling cancels pointer events, but touchend still completes the gesture.
  root.addEventListener('touchstart', (event) => {
    scrollStart = null;
    if (event.touches.length !== 1) return;
    const surface = event.target.closest?.('.resource-day-scroll');
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
