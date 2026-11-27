export function initUserSearch(root = document) {
  const search = root.querySelector('[data-user-search]');
  if (!search) return;

  const input = search.querySelector('input[type="search"]');
  const status = search.querySelector('[data-user-search-status]');
  const rows = [...root.querySelectorAll('[data-user-search-row]')];
  const normalize = value => value.normalize('NFC').toLocaleLowerCase('de');
  const entries = rows.map(row => {
    const user = row.querySelector('[data-edit-user]').dataset;
    return {
      row,
      fields: [user.userFirstName, user.userLastName, user.userUsername, user.userEmail].map(normalize),
    };
  });

  const update = () => {
    const query = normalize(input.value.trim());
    let matches = 0;
    entries.forEach(({ row, fields }) => {
      const visible = query === '' || fields.some(field => field.includes(query));
      row.hidden = !visible;
      if (visible) matches += 1;
    });
    status.textContent = query !== '' && matches === 0 ? 'Keine passenden Benutzer gefunden.' : '';
  };

  input.addEventListener('input', update);
  update();
  search.hidden = false;
}
