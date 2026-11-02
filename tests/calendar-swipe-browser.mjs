import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { readFileSync } from 'node:fs';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const profile = await mkdtemp(join(tmpdir(), 'vfl-calendar-swipe-'));
const surfaceClass = process.argv.includes('--week') ? 'resource-week-scroll' : 'resource-day-scroll';
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
    document.body.innerHTML = '<div class="calendar-frame" style="width:320px"><div id="surface" class="${surfaceClass} has-horizontal-overflow" style="height:300px"><div class="resource-day-grid" style="--resource-count:7"></div></div></div><section id="requests" style="height:900px">Buchungsanfragen</section>';
    const grid = document.querySelector('.resource-day-grid');
    grid.innerHTML = '<div style="height:900px"></div>' + '<section style="height:900px"><button style="width:100%;height:900px">Buchung</button></section>'.repeat(7);
    window.navigations = [];
    window.bookingClicks = 0;
    window.pointerCancels = 0;
    document.addEventListener('pointercancel', () => window.pointerCancels++);
    document.addEventListener('click', event => { if (event.target.closest('button')) window.bookingClicks++; });
    ${navigation.replaceAll('export ', '')}
    initCalendarScrollChaining(document.querySelector('.calendar-frame'));
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
  const transitions = [];
  for (const input of ['wheel', 'touch', 'diagonal-touch']) {
    await evaluate(`(() => {
      const surface = document.querySelector('#surface');
      surface.scrollTop = surface.scrollHeight - surface.clientHeight - 20;
      window.scrollTo(0, 0);
      surface.querySelector('button').focus({ preventScroll: true });
    })()`);
    await new Promise(resolve => setTimeout(resolve, 150));
    if (input === 'wheel') {
      await command('Input.dispatchMouseEvent', { type: 'mouseWheel', x: 150, y: 220, deltaX: 0, deltaY: 180 });
      await new Promise(resolve => setTimeout(resolve, 500));
    } else if (input === 'touch') {
      await swipe(150, 220, 0, -160);
    } else {
      await command('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: 150, y: 220, id: 1 }] });
      for (let step = 1; step <= 10; step++) {
        await command('Input.dispatchTouchEvent', {
          type: 'touchMove',
          touchPoints: [{ x: 174, y: 220 - 24 - (step - 1) * 16, id: 1 }],
        });
        await new Promise(resolve => setTimeout(resolve, 25));
      }
      await command('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
      await new Promise(resolve => setTimeout(resolve, 500));
    }
    transitions.push({
      input,
      ...await evaluate(`(() => {
        const surface = document.querySelector('#surface');
        return { remaining: surface.scrollHeight - surface.clientHeight - surface.scrollTop, page: window.scrollY };
      })()`),
    });
  }
  assert.ok(
    transitions.every(result => result.remaining <= 1 && result.page >= 80),
    `A movement crossing the calendar bottom must continue on the page without restarting: ${JSON.stringify(transitions)}`,
  );
  for (const input of ['wheel', 'touch']) {
    await evaluate(`(() => {
      document.querySelector('#surface').scrollTop = 400;
      window.scrollTo(0, 100);
    })()`);
    await new Promise(resolve => setTimeout(resolve, 150));
    if (input === 'wheel') {
      await command('Input.dispatchMouseEvent', { type: 'mouseWheel', x: 150, y: 140, deltaX: 0, deltaY: -160 });
      await new Promise(resolve => setTimeout(resolve, 500));
    } else {
      await swipe(150, 140, 0, 160);
    }
    const position = await evaluate(`({ page: window.scrollY, calendar: document.querySelector('#surface').scrollTop })`);
    assert.equal(position.page, 0, 'Scrolling back over the calendar must first reveal the page header');
    assert.ok(position.calendar < 400, `The rest of the upward ${input} movement must scroll the calendar contents`);
  }
  await evaluate(`document.querySelector('#surface').scrollTop = 10000; window.scrollTo(0, 0)`);
  await new Promise(resolve => setTimeout(resolve, 150));
  await swipe(150, 200, 0, -100);
  assert.ok(await evaluate('window.scrollY > 0'), 'At the calendar bottom, touch scrolling must continue to the booking requests below');
  await evaluate(`window.scrollTo(0, 0)`);
  await new Promise(resolve => setTimeout(resolve, 150));
  await command('Input.dispatchMouseEvent', { type: 'mouseWheel', x: 150, y: 200, deltaX: 0, deltaY: 180 });
  await new Promise(resolve => setTimeout(resolve, 500));
  assert.ok(await evaluate('window.scrollY > 0'), 'At the calendar bottom, mouse-wheel scrolling must continue to the booking requests below');
  await evaluate(`document.querySelector('#surface').scrollTop = 0; window.scrollTo(0, 100)`);
  await new Promise(resolve => setTimeout(resolve, 150));
  await swipe(150, 100, 0, 80);
  assert.ok(await evaluate('window.scrollY < 100'), 'At the calendar top, a downward gesture must scroll back up the page');
  await evaluate(`window.scrollTo(0, 0)`);
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
  await evaluate(`document.querySelector('#surface').scrollTop = 10000; window.scrollTo(0, 0)`);
  await new Promise(resolve => setTimeout(resolve, 150));
  await swipe(150, 200, 0, -100);
  assert.ok(await evaluate('window.scrollY > 0'), 'After scrolling inside the calendar, the next bottom-edge gesture must reach the requests');
  await evaluate(`window.scrollTo(0, 0); document.querySelector('#surface').scrollTop = 100`);
  await new Promise(resolve => setTimeout(resolve, 150));
  await command('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: 150, y: 150, id: 1 }] });
  await command('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await new Promise(resolve => setTimeout(resolve, 400));
  assert.equal(await evaluate('window.bookingClicks'), 1, 'A regular tap must still open a booking after scrolling');
  console.log(`${surfaceClass}: uninterrupted wheel/touch transitions, reverse scrolling, horizontal navigation and booking taps passed.`);
  await send('Browser.close');
} finally {
  ws?.close();
  if (chrome.exitCode === null) {
    chrome.kill();
    await Promise.race([once(chrome, 'exit'), new Promise(resolve => setTimeout(resolve, 3000))]);
  }
  await rm(profile, { recursive: true, force: true, maxRetries: 8, retryDelay: 250 });
}
