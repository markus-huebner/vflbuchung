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
  let suppressedClickTarget = null;
  let suppressedClickTimer = null;

  root.addEventListener('click', (event) => {
    if (!suppressedClickTarget || event.target.closest?.(interactiveSelector) !== suppressedClickTarget) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    suppressedClickTarget = null;
    clearTimeout(suppressedClickTimer);
  }, true);

  root.addEventListener('pointerdown', (event) => {
    if (event.pointerType !== 'touch' || event.isPrimary === false) {
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
    const deltaX = event.clientX - gesture.x;
    const deltaY = event.clientY - gesture.y;
    start = null;

    if (Math.abs(deltaX) < minimumSwipeDistance || Math.abs(deltaX) <= Math.abs(deltaY) * 1.25) return;
    if (gesture.interactiveTarget) {
      suppressedClickTarget = gesture.interactiveTarget;
      clearTimeout(suppressedClickTimer);
      suppressedClickTimer = setTimeout(() => { suppressedClickTarget = null; }, 0);
    }
    if (deltaX < 0) onNext();
    else onPrevious();
  });

  doc.addEventListener('pointercancel', (event) => {
    if (event.pointerId === start?.pointerId) start = null;
  });
}
