import assert from 'node:assert/strict';
import test from 'node:test';
import { initDeletePeriod } from '../www/assets/js/delete-period.mjs';

function setup() {
  const element = () => Object.assign(new EventTarget(), {
    value: '', hidden: false, textContent: '', validationMessage: '',
    setCustomValidity(message) { this.validationMessage = message; },
  });
  const fields = element();
  const confirmation = element();
  const submit = element();
  const cancel = element();
  const open = element();
  const menu = { open: true };
  open.closest = selector => selector === 'details' ? menu : null;
  const dialog = Object.assign(element(), {
    showModal() { this.open = true; },
    close() { this.open = false; },
  });
  const selectors = {
    '[data-delete-period-fields]': fields,
    '[data-delete-period-confirmation]': confirmation,
    '[data-delete-period-submit]': submit,
    '[data-delete-period-cancel]': cancel,
  };
  const form = Object.assign(element(), {
    elements: { from: element(), until: element(), confirmed: element(), selection: element() },
    querySelector: selector => selectors[selector],
    reportValidity() { return !this.elements.until.validationMessage; },
    reset() { this.elements.confirmed.value = ''; },
  });
  form.elements.selection.selectedOptions = [{ textContent: 'Offene Anfragen' }];
  initDeletePeriod({
    querySelector: selector => ({
      '#delete-period-dialog': dialog, '#delete-period-form': form,
      '[data-delete-period-open]': open,
    })[selector],
  });
  function send() {
    const event = new Event('submit', { cancelable: true });
    form.dispatchEvent(event);
    return event.defaultPrevented;
  }
  open.dispatchEvent(new Event('click'));
  form.elements.from.value = '2030-01-01';
  form.elements.until.value = '2030-01-31';
  return { form, fields, confirmation, submit, cancel, dialog, open, menu, send };
}

test('period deletion requires two explicit confirmations after selection', () => {
  const ui = setup();
  assert.equal(ui.menu.open, false);
  assert.equal(ui.dialog.open, true);
  assert.equal(ui.send(), true);
  assert.match(ui.confirmation.textContent, /Erste Bestätigung.*Offene Anfragen.*01\.01\.2030.*31\.01\.2030/);
  assert.equal(ui.form.elements.confirmed.value, '');
  assert.equal(ui.send(), true);
  assert.match(ui.confirmation.textContent, /Letzte Bestätigung.*unwiderruflich/);
  assert.equal(ui.form.elements.confirmed.value, '');
  assert.equal(ui.send(), false);
  assert.equal(ui.form.elements.confirmed.value, 'yes');
});

test('cancelling resets both confirmations', () => {
  const ui = setup();
  ui.send();
  ui.send();
  ui.cancel.dispatchEvent(new Event('click'));
  assert.equal(ui.dialog.open, false);
  assert.equal(ui.fields.hidden, false);
  assert.equal(ui.form.elements.confirmed.value, '');
  ui.open.dispatchEvent(new Event('click'));
  ui.send();
  assert.match(ui.confirmation.textContent, /Erste Bestätigung/);
});

test('reversed date range cannot reach confirmation', () => {
  const ui = setup();
  ui.form.elements.until.value = '2029-12-31';
  assert.equal(ui.send(), true);
  assert.match(ui.form.elements.until.validationMessage, /Bis-Datum/);
  assert.equal(ui.fields.hidden, false);
});

test('Escape resets the confirmation stage', () => {
  const ui = setup();
  ui.send();
  ui.send();
  ui.dialog.dispatchEvent(new Event('cancel'));
  assert.equal(ui.fields.hidden, false);
  assert.equal(ui.form.elements.confirmed.value, '');
  ui.send();
  assert.match(ui.confirmation.textContent, /Erste Bestätigung/);
});
