export function initDeletePeriod(root = document) {
  const dialog = root.querySelector('#delete-period-dialog');
  const form = root.querySelector('#delete-period-form');
  const open = root.querySelector('[data-delete-period-open]');
  if (!dialog || !form || !open) return;
  const fields = form.querySelector('[data-delete-period-fields]');
  const confirmation = form.querySelector('[data-delete-period-confirmation]');
  const submit = form.querySelector('[data-delete-period-submit]');
  let stage = 0;
  let summary = '';
  function reset() {
    stage = 0;
    form.elements.confirmed.value = '';
    fields.hidden = false;
    confirmation.hidden = true;
    submit.textContent = 'Prüfen';
    form.elements.until.setCustomValidity('');
  }
  open.addEventListener('click', () => {
    form.reset();
    reset();
    const menu = open.closest('details');
    if (menu) menu.open = false;
    dialog.showModal();
  });
  form.querySelector('[data-delete-period-cancel]').addEventListener('click', () => {
    reset();
    dialog.close();
  });
  dialog.addEventListener('cancel', reset);
  for (const field of [form.elements.from, form.elements.until]) {
    field.addEventListener('input', () => form.elements.until.setCustomValidity(''));
  }
  form.addEventListener('submit', (event) => {
    if (stage === 2) {
      form.elements.confirmed.value = 'yes';
      return;
    }
    event.preventDefault();
    if (stage === 0) {
      if (form.elements.until.value < form.elements.from.value) {
        form.elements.until.setCustomValidity('Das Bis-Datum darf nicht vor dem Von-Datum liegen.');
      }
      if (!form.reportValidity()) return;
      const format = value => value.split('-').reverse().join('.');
      summary = `${form.elements.selection.selectedOptions[0].textContent} vom ${format(form.elements.from.value)} bis ${format(form.elements.until.value)}`;
      fields.hidden = true;
      confirmation.hidden = false;
      confirmation.textContent = `Erste Bestätigung: Möchtest du ${summary} löschen? Termine außerhalb des Zeitraums bleiben erhalten.`;
      submit.textContent = 'Ja, weiter zur letzten Bestätigung';
      stage = 1;
    } else {
      confirmation.textContent = `Letzte Bestätigung: ${summary} wirklich unwiderruflich löschen? Es werden keine E-Mails versendet.`;
      submit.textContent = 'Ja, endgültig löschen';
      stage = 2;
    }
  });
}
