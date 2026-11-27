import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { once } from 'node:events';

const php = `
require '/var/www/app/www/vendor/autoload.php';
$resource = ['id' => 1, 'type' => 'pitch', 'name' => 'Platz A', 'is_active' => 1];
$user = ['id' => 2, 'first_name' => 'Anna', 'last_name' => 'Beispiel',
    'email' => 'sehrlangeemailadresse@example.test', 'username' => 'anna',
    'role' => 'trainer', 'status' => 'active', 'has_password' => 1];
$user2 = ['id' => 4, 'first_name' => 'Jörg', 'last_name' => 'Müller',
    'email' => null, 'username' => 'joerg', 'role' => 'manager',
    'status' => 'inactive', 'has_password' => 1];
$registration = ['id' => 3, 'first_name' => 'Ben', 'last_name' => 'Test',
    'email' => 'ben@example.test', 'username' => 'ben', 'requested_role' => 'trainer'];
$type = ['code' => 'training', 'name' => 'Training', 'color' => 'green-medium',
    'is_active' => 1, 'trainer_requestable' => 1];
echo json_encode([
    'resource' => App\\Views::resources([$resource], [['code' => 'pitch', 'name' => 'Platz']], 'csrf', '', '', 'pitch'),
    'user' => App\\Views::users([$user, $user2], 'csrf', 1),
    'emptyUsers' => App\\Views::users([], 'csrf', 1),
    'registration' => App\\Views::registrations([$registration], 'csrf', 'admin'),
    'booking' => App\\Views::bookingTypes([$type], 'csrf'),
], JSON_THROW_ON_ERROR);
`;
const fixtures = JSON.parse(execFileSync('docker', ['compose', 'exec', '-T', 'web', 'php', '-r', php], { encoding: 'utf8' }));
const css = readFileSync('www/assets/build/app.css', 'utf8');
const profile = await mkdtemp(join(tmpdir(), 'vfl-settings-layout-'));
const chrome = spawn('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', [
  '--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`,
  '--no-first-run', '--no-default-browser-check', 'about:blank',
], { stdio: ['ignore', 'ignore', 'pipe'] });
let ws;

try {
  const endpoint = await new Promise((resolve, reject) => {
    let output = '';
    const timeout = setTimeout(() => reject(new Error('Chrome startup timeout')), 20000);
    chrome.stderr.on('data', data => {
      output += data;
      const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/);
      if (match) {
        clearTimeout(timeout);
        resolve(match[1]);
      }
    });
    chrome.once('error', reject);
  });
  ws = new WebSocket(endpoint);
  await once(ws, 'open');
  let nextId = 0;
  const pending = new Map();
  ws.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);
    const request = pending.get(message.id);
    if (!request) return;
    pending.delete(message.id);
    if (message.error) request.reject(new Error(JSON.stringify(message.error)));
    else request.resolve(message.result);
  });
  const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++nextId;
    pending.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  const command = (method, params) => send(method, params, sessionId);
  await command('Page.enable');
  const { frameTree } = await command('Page.getFrameTree');
  const evaluate = async expression => {
    const response = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (response.exceptionDetails) throw new Error(response.exceptionDetails.text);
    return response.result.value;
  };
  const checkPasswordButtonAlignment = async context => {
    const alignment = await evaluate(`(() => {
      const summary = document.querySelector('.user-row-actions details > summary');
      const range = document.createRange();
      range.selectNodeContents(summary);
      const button = summary.getBoundingClientRect();
      const text = range.getBoundingClientRect();
      const lines = Array.from(range.getClientRects(), rect => ({ left: rect.left, right: rect.right }));
      return { button: { left: button.left, right: button.right, top: button.top, bottom: button.bottom },
        text: { top: text.top, bottom: text.bottom }, lines };
    })()`);
    const center = (alignment.button.left + alignment.button.right) / 2;
    assert.ok(alignment.lines.every(line => Math.abs((line.left + line.right) / 2 - center) < 2),
      `${context}: password button text must be horizontally centered`);
    assert.ok(Math.abs((alignment.text.top + alignment.text.bottom - alignment.button.top - alignment.button.bottom) / 2) < 2,
      `${context}: password button text must be vertically centered`);
  };
  const selectors = {
    resource: '.resource-actions-cell',
    user: '.user-actions-cell',
    registration: '.registration-actions-cell',
    booking: '.booking-type-actions-cell',
  };
  for (const width of [320, 375, 700]) {
    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: true });
    for (const [name, selector] of Object.entries(selectors)) {
      const content = fixtures[name];
      const html = `<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style><main class="page-shell">${content}</main>`;
      await command('Page.setDocumentContent', { frameId: frameTree.frame.id, html });
      const metrics = await evaluate(`(() => {
        const cell = document.querySelector('${selector}');
        const actions = cell.firstElementChild;
        const buttons = Array.from(actions.children).map(child => child.matches('button') ? child : child.querySelector('button, summary'));
        const rects = buttons.map(button => {
          const rect = button.getBoundingClientRect();
          return { y: rect.y, right: rect.right, scroll: button.scrollWidth, client: button.clientWidth };
        });
        const label = getComputedStyle(cell, '::before');
        return { label: label.display, labelContent: label.content, rects,
          width: document.documentElement.scrollWidth, viewport: innerWidth };
      })()`);
      assert.ok(metrics.label === 'none' || metrics.labelContent === 'none', `${name}: action label must be hidden at ${width}px`);
      assert.equal(metrics.rects.length, name === 'registration' ? 2 : 3);
      assert.ok(metrics.rects.every(rect => rect.y === metrics.rects[0].y && rect.scroll <= rect.client),
        `${name}: actions must fit in one row at ${width}px`);
      assert.ok(metrics.width <= metrics.viewport, `${name}: horizontal overflow at ${width}px`);
      if (name === 'resource') {
        assert.equal(await evaluate("document.querySelector('.resource-name-cell > span').textContent.trim()"), '↕ Platz A');
        assert.equal(await evaluate("getComputedStyle(document.querySelector('.resource-name-cell'), '::before').content"), '"Name"');
      }
      if (name === 'user') {
        await checkPasswordButtonAlignment(`${width}px, closed`);
        const passwordForm = await evaluate(`(() => {
          const details = document.querySelector('.user-row-actions details');
          details.open = true;
          const form = details.querySelector('form');
          const rect = form.getBoundingClientRect();
          return { width: rect.width, right: rect.right, viewport: innerWidth };
        })()`);
        assert.ok(passwordForm.width >= 200 && passwordForm.right <= passwordForm.viewport, 'Password form must remain usable');
        await checkPasswordButtonAlignment(`${width}px, open`);
        assert.equal(await evaluate("getComputedStyle(document.querySelector('.user-mobile-account > div'), '::before').content"), '"Benutzername"');
      }
    }
  }
  await command('Emulation.setDeviceMetricsOverride', { width: 1200, height: 900, deviceScaleFactor: 1, mobile: false });
  for (const name of Object.keys(selectors)) {
    const content = fixtures[name];
    const html = `<!doctype html><style>${css}</style><main class="page-shell">${content}</main>`;
    await command('Page.setDocumentContent', { frameId: frameTree.frame.id, html });
    assert.equal(await evaluate("getComputedStyle(document.querySelector('table thead')).display"), 'table-header-group',
      `${name}: desktop table heading must remain visible`);
  }
  assert.equal(await evaluate("getComputedStyle(document.querySelector('.booking-type-actions')).display"), 'flex');
  await command('Page.setDocumentContent', {
    frameId: frameTree.frame.id,
    html: `<!doctype html><style>${css}</style><main class="page-shell">${fixtures.registration}</main>`,
  });
  assert.equal(await evaluate("getComputedStyle(document.querySelector('.registration-actions')).display"), 'flex');
  for (const width of [800, 1200]) {
    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: false });
    await command('Page.setDocumentContent', {
      frameId: frameTree.frame.id,
      html: `<!doctype html><style>${css}</style><main class="page-shell">${fixtures.user}</main>`,
    });
    await checkPasswordButtonAlignment(`${width}px, closed`);
    await evaluate("document.querySelector('.user-row-actions details').open = true");
    await checkPasswordButtonAlignment(`${width}px, open`);
  }
  const searchModule = readFileSync('www/assets/js/user-search.mjs', 'utf8');
  for (const width of [320, 1200]) {
    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 700 });
    await command('Page.setDocumentContent', {
      frameId: frameTree.frame.id,
      html: `<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style><main class="page-shell">${fixtures.user}</main>`,
    });
    assert.equal(await evaluate("document.querySelector('[data-user-search]').hidden"), true);
    await evaluate(`import('data:text/javascript;base64,${Buffer.from(searchModule).toString('base64')}').then(module => module.initUserSearch())`);
    const search = async text => evaluate(`(() => {
      const input = document.querySelector('#user-search');
      input.value = ${JSON.stringify(text)};
      input.dispatchEvent(new Event('input', { bubbles: true }));
      return {
        visible: Array.from(document.querySelectorAll('[data-user-search-row]'))
          .filter(row => getComputedStyle(row).display !== 'none')
          .map(row => row.querySelector('[data-edit-user]').dataset.userId),
        status: document.querySelector('[data-user-search-status]').textContent,
      };
    })()`);
    assert.equal(await evaluate("document.querySelector('[data-user-search]').hidden"), false);
    for (const query of [' ANn ', 'BEISPIEL', 'anna', 'EMAILADRESSE@']) {
      assert.deepEqual((await search(query)).visible, ['2'], `${query}: first user's fields should match`);
    }
    for (const query of ['JÖRG', 'mu\u0308ller', 'OERG']) {
      assert.deepEqual((await search(query)).visible, ['4'], `${query}: second user's fields should match`);
    }
    for (const query of ['manager', 'deaktiviert', 'unbekannt']) {
      const result = await search(query);
      assert.deepEqual(result.visible, []);
      assert.equal(result.status, 'Keine passenden Benutzer gefunden.');
    }
    const cleared = await search('');
    assert.deepEqual(cleared.visible, ['2', '4']);
    assert.equal(cleared.status, '');
    assert.equal(await evaluate("document.querySelectorAll('[data-user-search-row] [data-edit-user]').length"), 2);
  }
  await command('Page.setDocumentContent', {
    frameId: frameTree.frame.id,
    html: `<!doctype html><main>${fixtures.emptyUsers}</main>`,
  });
  assert.equal(await evaluate("document.querySelector('[data-user-search]') === null"), true);
  assert.equal(await evaluate("document.body.textContent.includes('Es sind noch keine Benutzer angelegt.')"), true);
  console.log('Settings layouts fit mobile widths and retain usable actions.');
} finally {
  if (ws) ws.close();
  chrome.kill();
  await once(chrome, 'exit').catch(() => {});
  await rm(profile, { recursive: true, force: true });
}
