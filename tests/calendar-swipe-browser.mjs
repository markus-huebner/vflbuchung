import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { readFileSync } from 'node:fs';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const profile = await mkdtemp(join(tmpdir(), 'vfl-calendar-swipe-'));
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
      if (match) { clearTimeout(timeout); resolve(match[1]); }
    });
    chrome.once('error', error => { clearTimeout(timeout); reject(error); });
  });
  ws = new WebSocket(endpoint);
  await once(ws, 'open');
  let nextId = 0;
  const pending = new Map();
  const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++nextId;
    const timeout = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 20000);
    pending.set(id, {
      resolve: result => { clearTimeout(timeout); resolve(result); },
      reject: error => { clearTimeout(timeout); reject(error); },
    });
    ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  ws.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);
    const request = pending.get(message.id);
    if (!request) return;
    pending.delete(message.id);
    if (message.error) request.reject(new Error(JSON.stringify(message.error)));
    else request.resolve(message.result);
  });
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  const command = (method, params) => send(method, params, sessionId);
  const evaluate = async expression => {
    const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || 'Evaluation failed');
    return result.result.value;
  };
  await command('Emulation.setDeviceMetricsOverride', {
    width: 390, height: 600, deviceScaleFactor: 1, mobile: true,
  });
  await command('Emulation.setTouchEmulationEnabled', { enabled: true });
  const css = readFileSync(new URL('../www/assets/css/app.css', import.meta.url), 'utf8');
  const navigation = readFileSync(new URL('../www/assets/js/calendar-navigation.mjs', import.meta.url), 'utf8');
  await evaluate(`(() => {
    document.head.innerHTML = '<meta name="viewport" content="width=device-width, initial-scale=1">';
    const style = document.createElement('style');
    style.textContent = ${JSON.stringify(css)};
    document.head.append(style);
    document.body.innerHTML = '<div class="calendar-frame" style="width:320px"><div id="surface" class="resource-day-scroll has-horizontal-overflow" style="height:300px"><div class="resource-day-grid" style="--resource-count:7"></div></div></div>';
    const grid = document.querySelector('.resource-day-grid');
    grid.innerHTML = '<div style="height:900px"></div>' + '<section style="height:900px"><button style="width:100%;height:150px">Buchung</button></section>'.repeat(7);
    window.navigations = [];
    window.bookingClicks = 0;
    window.pointerCancels = 0;
    document.addEventListener('pointercancel', () => window.pointerCancels++);
    document.addEventListener('click', event => { if (event.target.closest('button')) window.bookingClicks++; });
    ${navigation.replaceAll('export ', '')}
    initCalendarSwipeNavigation(document.querySelector('.calendar-frame'), {
      onPrevious: () => window.navigations.push('previous'),
      onNext: () => window.navigations.push('next'),
    });
  })()`);
  const swipe = async (x, y, deltaX, deltaY = 0) => {
    const point = (x, y) => [{ x, y, id: 1 }];
    await command('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: point(x, y) });
    for (let step = 1; step <= 10; step++) {
      await command('Input.dispatchTouchEvent', {
        type: 'touchMove', touchPoints: point(x + deltaX * step / 10, y + deltaY * step / 10),
      });
      await new Promise(resolve => setTimeout(resolve, 25));
    }
    await command('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await new Promise(resolve => setTimeout(resolve, 500));
  };
  const setPosition = async expression => {
    await evaluate(`(() => { const surface = document.querySelector('#surface'); surface.scrollLeft = ${expression}; surface.scrollTop = 0; })()`);
    await new Promise(resolve => setTimeout(resolve, 100));
  };
  const calls = () => evaluate('window.navigations');
  await setPosition('80');
  await swipe(220, 100, -80);
  assert.deepEqual(await calls(), []);
  assert.ok(await evaluate('document.querySelector("#surface").scrollLeft > 80'), 'Native horizontal scrolling must remain functional');
  assert.ok(await evaluate('window.pointerCancels > 0'), 'Exercise native pointer cancellation rather than synthetic pointer swipes');

  await setPosition('surface.scrollWidth - surface.clientWidth - 30');
  await swipe(220, 100, -100);
  assert.deepEqual(await calls(), [], 'Reaching the edge must not change the day');
  await swipe(220, 100, -80);
  assert.deepEqual(await calls(), ['next'], 'A fresh outward swipe at the right edge must change the day once');

  await setPosition('0');
  await swipe(100, 100, 80);
  assert.deepEqual(await calls(), ['next', 'previous']);
  await swipe(220, 100, -80);
  assert.deepEqual(await calls(), ['next', 'previous'], 'An inward swipe at the left edge must scroll, not navigate');
  await setPosition('0');
  await swipe(150, 200, 0, -100);
  assert.ok(await evaluate('document.querySelector("#surface").scrollTop > 0'), 'Vertical scrolling must remain functional');
  assert.deepEqual(await calls(), ['next', 'previous']);
  assert.equal(await evaluate('window.bookingClicks'), 0, 'Swiping bookings must not open them');
  console.log('Native touch scrolling, edge navigation, pointer cancellation, vertical scrolling and booking click suppression passed.');
  await send('Browser.close');
} finally {
  ws?.close();
  if (chrome.exitCode === null) {
    chrome.kill();
    await Promise.race([once(chrome, 'exit'), new Promise(resolve => setTimeout(resolve, 3000))]);
  }
  await rm(profile, { recursive: true, force: true, maxRetries: 8, retryDelay: 250 });
}
