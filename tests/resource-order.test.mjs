import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../www/assets/js/app.js', import.meta.url), 'utf8');
const ordering = source.slice(source.indexOf('async function saveResourceOrder('), source.indexOf('function selectRequestRow('));

function fixture({ fail = false } = {}) {
  const requests = [];
  const status = {};
  const makeClasses = () => {
    const classes = new Set();
    return { add: value => classes.add(value), remove: value => classes.delete(value), contains: value => classes.has(value) };
  };
  const emitter = () => ({
    listeners: {},
    addEventListener(type, callback) { this.listeners[type] = callback; },
    emit(type, properties = {}) {
      const event = {
        defaultPrevented: false,
        preventDefault() { this.defaultPrevented = true; },
        dataTransfer: { setData() {} },
        ...properties,
      };
      this.listeners[type]?.(event);
      return event;
    },
  });
  const createGroup = (category, ids) => {
    const area = { ...emitter(), classList: makeClasses() };
    const group = {
      ...emitter(), dataset: { resourceOrderGroup: category, csrfToken: 'token' }, rows: [], busy: false,
      closest: () => area,
      getAttribute: () => group.busy ? 'true' : null,
      setAttribute: () => { group.busy = true; },
      removeAttribute: () => { group.busy = false; },
      querySelectorAll: selector => selector === '[data-resource-row]' ? group.rows.slice() : group.rows.map(row => row.handle),
      insertBefore(row, reference) {
        group.rows.splice(group.rows.indexOf(row), 1);
        group.rows.splice(reference ? group.rows.indexOf(reference) : group.rows.length, 0, row);
      },
      append(row) { group.insertBefore(row, null); },
    };
    group.rows = ids.map(id => {
      const row = {
        dataset: { resourceId: id }, parentElement: group, classList: makeClasses(),
        getBoundingClientRect: () => ({ top: group.rows.indexOf(row) * 40 + 100, height: 40 }),
        get nextElementSibling() { return group.rows[group.rows.indexOf(row) + 1] || null; },
      };
      row.handle = { ...emitter(), closest: () => row };
      return row;
    });
    return { area, group };
  };
  const first = createGroup('pitch', ['1', '2', '3']);
  const second = createGroup('bus', ['4', '5']);
  class FormData {
    values = [];
    append(key, value) { this.values.push([key, value]); }
  }
  const context = vm.createContext({
    document: {
      querySelector: () => status,
      querySelectorAll: () => [first.group, second.group],
    },
    app: {},
    FormData,
    fetch: async (url, options) => {
      requests.push({ url, values: options.body.values });
      return { ok: !fail, json: async () => fail ? { error: 'Speichern fehlgeschlagen' } : {} };
    },
  });
  vm.runInContext(ordering, context);
  context.initResourceOrdering();
  const ids = group => group.rows.map(row => row.dataset.resourceId);
  const start = (group, index) => group.emit('dragstart', {
    target: { closest: () => group.rows[index].handle },
  });
  return { first, second, requests, status, ids, start };
}

test('dropping on the moved row or section padding saves the intended order', async () => {
  const { first: { group, area }, requests, ids, start } = fixture();
  start(group, 0);
  assert.equal(area.classList.contains('resource-order-active'), true);
  assert.equal(area.emit('dragover', { clientY: 215 }).defaultPrevented, true);
  assert.deepEqual(ids(group), ['2', '3', '1']);
  assert.equal(area.emit('dragover', { clientY: 195 }).defaultPrevented, true);
  assert.equal(area.emit('drop').defaultPrevented, true);
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(requests[0].values.filter(([key]) => key === 'resource_ids[]').map(([, value]) => value), ['2', '3', '1']);
  assert.equal(area.classList.contains('resource-order-active'), false);
  group.emit('dragend');
  assert.deepEqual(ids(group), ['2', '3', '1']);
});

test('section heading moves to the start, and an unchanged drop does not save', async () => {
  const { first: { group, area }, requests, ids, start } = fixture();
  start(group, 2);
  area.emit('dragover', { clientY: 50 });
  area.emit('drop');
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(ids(group), ['3', '1', '2']);
  assert.equal(requests.length, 1);
  start(group, 0);
  area.emit('dragover', { clientY: 105 });
  area.emit('drop');
  assert.equal(requests.length, 1);
});

test('dropping outside the original category cancels and restores the order', () => {
  const { first, second, requests, ids, start } = fixture();
  start(first.group, 0);
  first.area.emit('dragover', { clientY: 250 });
  assert.equal(second.area.emit('dragover', { clientY: 110 }).defaultPrevented, false);
  assert.equal(second.area.emit('drop').defaultPrevented, false);
  first.group.emit('dragend');
  assert.deepEqual(ids(first.group), ['1', '2', '3']);
  assert.deepEqual(ids(second.group), ['4', '5']);
  assert.equal(first.area.classList.contains('resource-order-active'), false);
  assert.equal(requests.length, 0);
});

test('a failed save restores the previous order and reports the error', async () => {
  const { first: { group, area }, status, ids, start } = fixture({ fail: true });
  start(group, 0);
  area.emit('dragover', { clientY: 250 });
  area.emit('drop');
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(ids(group), ['1', '2', '3']);
  assert.equal(status.textContent, 'Speichern fehlgeschlagen');
  assert.equal(group.busy, false);
});

test('keyboard ordering and the busy guard still work', async () => {
  const { first: { group }, requests, ids, start } = fixture();
  group.rows[1].handle.emit('keydown', { key: 'ArrowUp' });
  assert.deepEqual(ids(group), ['2', '1', '3']);
  assert.equal(start(group, 0).defaultPrevented, true);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(requests.length, 1);
});
