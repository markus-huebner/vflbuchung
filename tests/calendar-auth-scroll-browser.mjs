import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { once } from 'node:events';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const php = input => execFileSync('docker', ['compose', 'exec', '-T', '--user', 'www-data', 'web', 'php'], {
  input, encoding: 'utf8',
});
const session = php(`<?php
require '/var/www/app/www/vendor/autoload.php';
$config = App\\Config::fromEnvironment();
if ($config->get('APP_ENV') !== 'local') { throw new RuntimeException('Run this browser check only in the local development environment.'); }
$pdo = App\\Database::connect($config);
$user = $pdo->query("SELECT id FROM users WHERE status = 'active' AND role IN ('admin', 'manager', 'trainer') ORDER BY id LIMIT 1")->fetchColumn();
if ($user === false) { throw new RuntimeException('An active calendar account is required for this read-only check.'); }
App\\Security::startSession();
$_SESSION['user_id'] = (int) $user;
echo session_id();
session_write_close();`).trim();
assert.match(session, /^[a-zA-Z0-9,-]+$/, 'The temporary local browser session could not be created');
const profile = await mkdtemp(join(tmpdir(), 'vfl-calendar-auth-scroll-'));
const chrome = spawn('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', [
  '--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`,
  '--no-first-run', '--no-default-browser-check', 'about:blank',
], { stdio: ['ignore', 'ignore', 'pipe'] });
let ws;
try {
  const endpoint = await new Promise((resolve, reject) => {
    let output = '';
    const timer = setTimeout(() => reject(new Error('Chrome startup timeout')), 20000);
    chrome.stderr.on('data', data => {
      output += data;
      const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/);
      if (match) { clearTimeout(timer); resolve(match[1]); }
    });
    chrome.once('error', error => { clearTimeout(timer); reject(error); });
  });
  ws = new WebSocket(endpoint);
  await once(ws, 'open');
  let nextId = 0;
  const pending = new Map();
  const errors = [];
  ws.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);
    const request = pending.get(message.id);
    if (request) {
      pending.delete(message.id);
      if (message.error) request.reject(new Error(JSON.stringify(message.error)));
      else request.resolve(message.result);
    } else if (message.method === 'Runtime.exceptionThrown') {
      errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
    }
  });
  const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++nextId;
    const timer = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 20000);
    pending.set(id, {
      resolve: value => { clearTimeout(timer); resolve(value); },
      reject: error => { clearTimeout(timer); reject(error); },
    });
    ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  const command = (method, params) => send(method, params, sessionId);
  const evaluate = async expression => {
    const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || 'Evaluation failed');
    return result.result.value;
  };
  const waitFor = async expression => {
    for (let attempt = 0; attempt < 100; attempt++) {
      if (await evaluate(expression)) return;
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error(`Browser condition timed out: ${expression}`);
  };
  await command('Runtime.enable');
  await command('Network.enable');
  await command('Network.setCacheDisabled', { cacheDisabled: true });
  await command('Network.setCookie', {
    name: 'verein_session', value: session, url: 'http://localhost:8100',
    httpOnly: true, sameSite: 'Lax',
  });
  await command('Emulation.setTouchEmulationEnabled', { enabled: true });
  await command('Emulation.setEmulatedMedia', {
    features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }],
  });
  for (const width of [320, 390]) {
    await command('Emulation.setDeviceMetricsOverride', {
      width, height: 800, deviceScaleFactor: 1, mobile: true,
    });
    await command('Page.navigate', { url: 'http://localhost:8100/' });
    await waitFor(`window.VereinApp?.isLoggedIn && document.querySelector('.resource-day-grid') && document.querySelector('#request-dashboard')`);
    await evaluate(`document.querySelector('[data-calendar-resource="pitch"]').click()`);
    await waitFor(`!document.querySelector('[data-calendar-view="pitch"]').hidden && !document.querySelector('[data-calendar-resource="pitch"]').disabled`);
    await evaluate(`(() => { const toggle = document.querySelector('#full-day-toggle'); if (!toggle.checked) toggle.click(); })()`);
    await waitFor(`document.querySelector('#pitch-day .resource-time-axis')?.textContent.includes('00:00')`);
    await evaluate(`(() => {
      window.calendarDragTrace = [];
      let lastY;
      let before;
      document.addEventListener('touchstart', event => { lastY = event.touches[0]?.clientY; }, { capture: true, passive: true });
      document.addEventListener('touchmove', event => {
        const surface = event.target.closest('#pitch-day');
        if (!surface) return;
        before = {
          surface, page: scrollY, inner: surface.scrollTop,
          delta: lastY - event.touches[0].clientY,
          available: document.scrollingElement.scrollHeight - innerHeight - scrollY
            + surface.scrollHeight - surface.clientHeight - surface.scrollTop,
        };
        lastY = event.touches[0].clientY;
      }, { capture: true, passive: true });
      document.addEventListener('touchmove', event => {
        if (!before || !event.target.closest('#pitch-day')) return;
        if (event.defaultPrevented && before.delta > 0) {
          window.calendarDragTrace.push({
            expected: Math.min(before.delta, before.available),
            moved: scrollY - before.page + before.surface.scrollTop - before.inner,
          });
        }
      }, { passive: true });
    })()`);
    for (const input of ['vertical', 'angled']) {
      const before = await evaluate(`(() => {
        const surface = document.querySelector('#pitch-day');
        surface.scrollTop = surface.scrollHeight - surface.clientHeight - 20;
        window.scrollTo({ top: 0, behavior: 'instant' });
        const rect = surface.getBoundingClientRect();
        return {
          x: rect.left + rect.width / 2, y: rect.top + rect.height / 2,
          requestTop: document.querySelector('#request-dashboard').getBoundingClientRect().top,
          pageMax: document.scrollingElement.scrollHeight - innerHeight,
        };
      })()`);
      assert.ok(before.requestTop >= 700, 'The real request panel must be below the calendar');
      assert.ok(before.pageMax > 40, 'The real page must have enough space to scroll to the request panel');
      await new Promise(resolve => setTimeout(resolve, 150));
      await command('Input.dispatchTouchEvent', {
        type: 'touchStart', touchPoints: [{ x: before.x, y: before.y, id: 1 }],
      });
      for (let step = 1; step <= 10; step++) {
        await command('Input.dispatchTouchEvent', {
          type: 'touchMove',
          touchPoints: [{
            x: before.x + (input === 'angled' ? 24 : 0),
            y: before.y - 24 - (step - 1) * 16, id: 1,
          }],
        });
        await new Promise(resolve => setTimeout(resolve, 25));
      }
      await command('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
      await new Promise(resolve => setTimeout(resolve, 500));
      const after = await evaluate(`({
        page: scrollY, requestTop: document.querySelector('#request-dashboard').getBoundingClientRect().top,
        trace: window.calendarDragTrace.splice(0),
      })`);
      assert.ok(after.trace.length > 0 && after.trace.every(move => move.moved >= move.expected - 1),
        `${width}px ${input}: an owned drag must consume each finger movement immediately, including after the calendar bottom: ${JSON.stringify(after.trace)}`);
      assert.ok(after.page >= Math.min(80, before.pageMax - 1),
        `${width}px ${input}: touch over the real signed-in calendar must reach the requests, page moved only ${after.page}px`);
      assert.ok(after.requestTop < before.requestTop - 40, 'The actual request panel must move into view');
      console.log(`${width}px ${input}: signed-in page scrolled ${Math.round(after.page)}px toward the request panel.`);
    }
    await evaluate(`document.querySelector('#pitch-day').scrollTop = 0; window.scrollTo({ top: 0, behavior: 'instant' })`);
    const swipeCalendar = async (inspectDuringDrag = false, resizeDuringDrag = false) => {
      const start = await evaluate(`(() => {
        const rect = document.querySelector('#pitch-day').getBoundingClientRect();
        return { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2, page: scrollY };
      })()`);
      await command('Input.dispatchTouchEvent', {
        type: 'touchStart', touchPoints: [{ x: start.x, y: start.y, id: 1 }],
      });
      for (let step = 1; step <= 10; step++) {
        await command('Input.dispatchTouchEvent', {
          type: 'touchMove', touchPoints: [{ x: start.x, y: start.y - step * 16, id: 1 }],
        });
        if (resizeDuringDrag && step === 3) {
          await command('Emulation.setDeviceMetricsOverride', {
            width, height: 860, deviceScaleFactor: 1, mobile: true,
          });
        }
        await new Promise(resolve => setTimeout(resolve, inspectDuringDrag ? 8 : 25));
      }
      const during = await evaluate('scrollY');
      await command('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
      await new Promise(resolve => setTimeout(resolve, 500));
      if (inspectDuringDrag) {
        const minimumMovement = resizeDuringDrag ? 40 : 100;
        assert.ok(during - start.page >= minimumMovement,
          `${width}px: after scrolling the calendar, a new bottom-edge drag must move the page with the finger; moved only ${Math.round(during - start.page)}px`);
      }
    };
    let remaining = Infinity;
    for (let attempt = 0; attempt < 12 && remaining > 1; attempt++) {
      await swipeCalendar();
      remaining = await evaluate(`(() => {
        const surface = document.querySelector('#pitch-day');
        return surface.scrollHeight - surface.clientHeight - surface.scrollTop;
      })()`);
    }
    assert.ok(remaining <= 1, 'Real touch gestures must scroll the calendar contents to the bottom');
    await evaluate(`window.scrollTo({ top: 0, behavior: 'instant' })`);
    await swipeCalendar(true);
    console.log(`${width}px: successive calendar swipes and a fresh bottom-edge drag reach the requests.`);
    await evaluate(`window.scrollTo({ top: 0, behavior: 'instant' })`);
    await swipeCalendar(true, true);
    console.log(`${width}px: bottom-edge drag survives the mobile browser toolbar changing viewport height.`);
  }
  assert.deepEqual(errors, [], 'The real application must not throw JavaScript errors');
  await send('Browser.close');
} finally {
  ws?.close();
  if (chrome.exitCode === null) {
    chrome.kill();
    await Promise.race([once(chrome, 'exit'), new Promise(resolve => setTimeout(resolve, 3000))]);
  }
  await rm(profile, { recursive: true, force: true, maxRetries: 8, retryDelay: 250 });
  php(`<?php session_name('verein_session'); session_id(${JSON.stringify(session)}); session_start(); session_destroy();`);
}
