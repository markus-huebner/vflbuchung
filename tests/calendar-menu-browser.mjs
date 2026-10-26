import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { once } from 'node:events';
import { readFileSync } from 'node:fs';

const profile = await mkdtemp(join(tmpdir(), 'vfl-calendar-menu-'));
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
    chrome.once('error', reject);
  });
  ws = new WebSocket(endpoint);
  await once(ws, 'open');
  let nextId = 0;
  const pending = new Map();
  const exceptions = [];
  const fixtures = new Map();
  const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++nextId;
    const timeout = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 20000);
    pending.set(id, {
      resolve: value => { clearTimeout(timeout); resolve(value); },
      reject: error => { clearTimeout(timeout); reject(error); },
    });
    ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  ws.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);
    if (message.id) {
      const request = pending.get(message.id);
      if (request) {
        pending.delete(message.id);
        if (message.error) request.reject(new Error(JSON.stringify(message.error)));
        else request.resolve(message.result);
      }
    } else if (message.method === 'Runtime.exceptionThrown') {
      exceptions.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
    } else if (message.method === 'Fetch.requestPaused') {
      const role = new URL(message.params.request.url).searchParams.get('menu-test');
      void send('Fetch.fulfillRequest', {
        requestId: message.params.requestId,
        responseCode: 200,
        responseHeaders: [{ name: 'Content-Type', value: 'text/html; charset=utf-8' }],
        body: Buffer.from(fixtures.get(role)).toString('base64'),
      }, message.sessionId).catch(error => exceptions.push(error.message));
    }
  });
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  const command = (method, params) => send(method, params, sessionId);
  await command('Page.enable');
  await command('Runtime.enable');
  await command('Fetch.enable', {
    patterns: [{ urlPattern: 'http://localhost:8100/?menu-test=*', resourceType: 'Document' }],
  });
  const evaluate = async expression => {
    const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || 'Evaluation failed');
    return result.result.value;
  };
  const waitFor = async expression => {
    for (let attempt = 0; attempt < 150; attempt += 1) {
      if (await evaluate(expression)) return;
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error(`Browser condition timed out: ${expression}`);
  };
  const resize = width => command('Emulation.setDeviceMetricsOverride', {
    width, height: 900, deviceScaleFactor: 1, mobile: width <= 700,
  });
  const checkHourGrid = async (type, fullDay = false) => {
    const metrics = await evaluate(`(() => {
      const root = document.querySelector('#${type}-day');
      const hours = Array.from(root.querySelectorAll('.resource-time-axis .resource-hour'));
      return {
        labels: hours.map(hour => hour.textContent),
        rowHeight: parseFloat(getComputedStyle(hours[0]).height),
        laneHeight: root.querySelector('.resource-lane').getBoundingClientRect().height,
        lineHeight: parseFloat(getComputedStyle(root.querySelector('.resource-hour-line')).height),
      };
    })()`);
    const first = fullDay ? 0 : 9;
    const last = fullDay ? 24 : 21;
    assert.deepEqual(metrics.labels, Array.from({ length: last - first }, (_, index) => `${String(first + index).padStart(2, '0')}:00`));
    assert.ok(Math.abs(metrics.rowHeight - 64 * 0.8) < 0.05);
    assert.ok(Math.abs(metrics.lineHeight - 64 * 0.8) < 0.05);
    assert.ok(Math.abs(metrics.laneHeight - (last - first) * 64 * 0.8) < 0.05);
  };
  const checkDateControl = async () => {
    const metrics = await evaluate(`(() => {
      const picker = document.querySelector('#calendar-date-picker');
      const label = document.querySelector('#calendar-range');
      const original = label.textContent;
      const sizes = ['01.01.2026', '31.12.2026', '01.01.–07.01.', '28.12.–03.01.'].map(value => {
        label.textContent = value;
        const rect = picker.getBoundingClientRect();
        return { width: rect.width, height: rect.height, clipped: label.scrollWidth > label.clientWidth };
      });
      label.textContent = original;
      return {
        sizes,
        previousHeight: document.querySelector('#calendar-prev').getBoundingClientRect().height,
        nextHeight: document.querySelector('#calendar-next').getBoundingClientRect().height,
        rootSize: parseFloat(getComputedStyle(document.documentElement).fontSize),
        arrow: picker.textContent.includes('⌄'),
      };
    })()`);
    assert.equal(metrics.arrow, false);
    for (const size of metrics.sizes) {
      assert.equal(size.width, metrics.sizes[0].width, 'Date width must not change between dates or views');
      assert.equal(size.height, metrics.previousHeight);
      assert.equal(size.height, metrics.nextHeight);
      assert.equal(size.clipped, false);
      assert.ok(size.width < 10.5 * metrics.rootSize, 'Date control must shrink below its previous width');
    }
  };
  const open = async () => {
    await evaluate(`if (!document.querySelector('#calendar-actions-menu').open) document.querySelector('#calendar-actions-menu > summary').click()`);
    await waitFor(`document.querySelector('#calendar-actions-menu').open`);
  };
  const checkAccountMenu = async role => {
    if (role === 'public') {
      assert.equal(await evaluate(`document.querySelector('#user-menu')`), null);
      assert.deepEqual(await evaluate(`Array.from(document.querySelectorAll('.navbar-nav a')).map(link => link.getAttribute('href'))`), ['/?page=login']);
      return;
    }
    assert.equal(await evaluate(`document.querySelector('#user-menu').open`), false);
    assert.equal(await evaluate(`document.querySelector('.user-account-name').textContent`), 'Menu Test');
    assert.equal(await evaluate(`document.querySelector('.user-account-name').closest('#user-menu')`), null);
    assert.equal(await evaluate(`document.querySelector('.user-account-name').getBoundingClientRect().right <= document.querySelector('#user-menu').getBoundingClientRect().left`), true);
    assert.equal(await evaluate(`getComputedStyle(document.querySelector('#user-menu > summary')).borderTopWidth`), '0px');
    await evaluate(`document.querySelector('.user-account-name').click()`);
    assert.equal(await evaluate(`document.querySelector('#user-menu').open`), false);
    assert.equal(await evaluate(`document.querySelector('#user-menu > summary svg circle') !== null`), true);
    await evaluate(`document.querySelector('#user-menu > summary').click()`);
    await waitFor(`document.querySelector('#user-menu').open`);
    assert.deepEqual(
      await evaluate(`Array.from(document.querySelectorAll('#user-menu a')).map(link => link.getAttribute('href'))`),
      role === 'admin' ? ['/?page=profile', '/?page=resources', '/?page=users'] : ['/?page=profile'],
    );
    assert.equal(await evaluate(`document.querySelector('#user-menu form').getAttribute('method')`), 'post');
    assert.equal(await evaluate(`document.querySelector('#user-menu form input[name="_csrf"]').value`), 'test-csrf');
    assert.equal(await evaluate(`(() => { const r=document.querySelector('#user-menu .user-actions-menu-items').getBoundingClientRect(); return r.left>=0 && r.right<=innerWidth; })()`), true);
    await evaluate(`document.querySelector('#user-menu > summary').focus()`);
    await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
    await waitFor(`!document.querySelector('#user-menu').open`);
    assert.equal(await evaluate(`document.activeElement === document.querySelector('#user-menu > summary')`), true);
    await evaluate(`document.querySelector('#user-menu > summary').click(); document.querySelector('h1').dispatchEvent(new PointerEvent('pointerdown', {bubbles:true}));`);
    assert.equal(await evaluate(`document.querySelector('#user-menu').open`), false);
  };
  for (const role of ['public', 'trainer', 'manager', 'admin']) {
    const user = role === 'public' ? 'null' : `['role'=>'${role}','first_name'=>'Menu','last_name'=>'Test']`;
    fixtures.set(role, execFileSync('docker', ['exec', '-i', 'php_apache_web', 'php'], {
      input: `<?php require '/var/www/app/www/vendor/autoload.php'; $user=${user}; echo App\\Views::layout('Menu test', App\\Views::calendar($user,'test-csrf',[]), $user, 'test-csrf', 'http://localhost:8100');`,
      encoding: 'utf8',
    }));
    await resize(390);
    await command('Page.navigate', { url: `http://localhost:8100/?menu-test=${role}` });
    await waitFor(`document.querySelector('#calendar-range')?.textContent && document.querySelector('#calendar-actions')?.parentElement.matches('.calendar-heading-toolbar')`);
    await checkHourGrid(await evaluate(`document.querySelector('[data-calendar-resource]:checked').dataset.calendarResource`));
    await checkDateControl();
    const width = await evaluate(`(() => {
      const shell = document.querySelector('main');
      const style = getComputedStyle(shell);
      return {
        viewport: innerWidth,
        document: document.documentElement.scrollWidth,
        available: shell.getBoundingClientRect().width - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight),
        calendar: document.querySelector('.calendar-main-content').getBoundingClientRect().width,
      };
    })()`);
    console.log(`${role}: mobile widths ${JSON.stringify(width)}`);
    assert.ok(Math.abs(width.calendar - width.available) <= 1, `${role}: calendar must use the available mobile width`);
    assert.ok(width.document <= 390 && width.viewport <= 390, `${role}: mobile layout must not exceed the 390px device width`);
    assert.equal(await evaluate(`document.querySelector('#calendar-actions-menu').open`), false);
    assert.equal(await evaluate(`getComputedStyle(document.querySelector('[data-calendar-actions-panel]')).display`), 'none');
    assert.equal(await evaluate(`document.querySelectorAll('#calendar-actions-menu [data-calendar-resource]').length`), 2);
    assert.equal(await evaluate(`document.querySelectorAll('#calendar-actions-menu [data-calendar-resource]:checked').length`), 1);
    assert.equal(await evaluate(`document.querySelector('#calendar-resource-controls').closest('#calendar-actions-menu') !== null`), true);
    assert.equal(await evaluate(`document.querySelector('#calendar-resource-switcher')`), null);
    assert.equal(await evaluate(`document.querySelectorAll('#calendar-resource-controls input[type="radio"]').length`), 2);
    assert.equal(await evaluate(`document.querySelectorAll('#calendar-view-controls input[type="radio"]').length`), 2);
    assert.equal(await evaluate(`document.querySelectorAll('#calendar-view-controls input:checked').length`), 1);
    await checkAccountMenu(role);
    assert.equal(await evaluate(`document.querySelectorAll('[data-booking-option]').length`), role === 'public' ? 0 : 2);
    assert.equal(await evaluate(`document.querySelectorAll('#booking-create-menu').length`), role === 'public' ? 0 : 1);
    assert.equal(await evaluate(`document.querySelector('#calendar-actions-menu').querySelectorAll('[data-booking-option]').length`), 0);
    if (role !== 'public') {
      assert.equal(await evaluate(`document.querySelector('#booking-create-menu').parentElement.id`), 'calendar-actions');
      assert.equal(await evaluate(`document.querySelector('#booking-create-menu > summary').textContent.trim()`), '+');
      assert.equal(await evaluate(`document.querySelector('#booking-create-menu').open`), false);
    }
    await open();
    assert.equal(await evaluate(`(() => { const r=document.querySelector('[data-calendar-actions-panel]').getBoundingClientRect(); return r.left>=0 && r.right<=innerWidth; })()`), true);
    assert.equal(await evaluate(`document.querySelector('#full-day-toggle-wrap').closest('[data-calendar-view-group]') !== null`), true);
    assert.equal(await evaluate(`document.querySelector('#full-day-toggle-wrap .form-check-label').textContent`), 'Ganzen Tag anzeigen');
    assert.equal(await evaluate(`document.querySelector('[data-calendar-settings-group]')`), null);
    const rows = await evaluate(`Array.from(document.querySelectorAll('[data-calendar-actions-panel] .calendar-menu-row, [data-calendar-actions-panel] .booking-create-option, [data-calendar-actions-panel] .calendar-option')).filter(element => element.getBoundingClientRect().height > 0).map(element => { const style=getComputedStyle(element); return [style.minHeight, style.padding, style.borderRadius, style.fontSize]; })`);
    assert.ok(rows.length >= 3);
    for (const row of rows) assert.deepEqual(row, rows[0], `${role}: all menu rows must share one presentation`);
    await evaluate(`document.querySelector('[data-calendar-resource="bus"]').click()`);
    await waitFor(`!document.querySelector('[data-calendar-view="bus"]').hidden && document.querySelector('[data-calendar-resource="bus"]:checked') && !document.querySelector('[data-calendar-resource="bus"]').disabled && !document.querySelector('#calendar-actions-menu').open`);
    await checkHourGrid('bus');
    assert.equal(await evaluate(`document.querySelectorAll('[data-calendar-resource]:checked').length`), 1);
    await open();
    await evaluate(`document.querySelector('#full-day-toggle').click()`);
    await waitFor(`document.querySelector('#bus-day .resource-time-axis')?.textContent.includes('00:00') && !document.querySelector('#calendar-actions-menu').open`);
    await checkHourGrid('bus', true);
    await open();
    await evaluate(`document.querySelector('#full-day-toggle').click()`);
    await waitFor(`!document.querySelector('#bus-day .resource-time-axis')?.textContent.includes('00:00') && !document.querySelector('#calendar-actions-menu').open`);
    await checkHourGrid('bus');
    await open();
    await evaluate(`document.querySelector('[data-view="week"]').click()`);
    await waitFor(`!document.querySelector('#bus-week').hidden && document.querySelector('#full-day-toggle').disabled && !document.querySelector('#calendar-view-controls').disabled`);
    assert.equal(await evaluate(`document.querySelectorAll('[data-view]:checked').length`), 1);
    assert.equal(await evaluate(`document.querySelector('#full-day-toggle-wrap').hidden`), false);
    assert.equal(await evaluate(`document.querySelector('#calendar-actions-menu').open`), false);
    if (role !== 'public') {
      for (const mode of ['pitch', 'bus']) {
        await evaluate(`document.querySelector('#booking-create-menu > summary').click()`);
        await waitFor(`document.querySelector('#booking-create-menu').open && !document.querySelector('#calendar-actions-menu').open`);
        assert.equal(await evaluate(`(() => { const r=document.querySelector('.booking-create-options').getBoundingClientRect(); return r.left>=0 && r.right<=innerWidth; })()`), true);
        await evaluate(`document.querySelector('[data-booking-option][data-resource-mode="${mode}"]').click()`);
        await waitFor(`document.querySelector('#booking-dialog').open && !document.querySelector('#booking-create-menu').open`);
        assert.equal(await evaluate(`document.querySelector('#booking-form').elements.resource_mode.value`), mode);
        assert.equal(await evaluate(`Boolean(document.activeElement.closest('#booking-dialog'))`), true);
        await evaluate(`document.querySelector('#booking-dialog').close()`);
      }
    }
    await resize(1200);
    await waitFor(`document.querySelector('#calendar-actions').parentElement.matches('.calendar-heading-toolbar')`);
    await checkDateControl();
    assert.equal(await evaluate(`getComputedStyle(document.querySelector('#calendar-actions-menu')).display`), 'block');
    assert.equal(await evaluate(`document.querySelector('[data-calendar-resource="bus"]:checked') !== null`), true);
    assert.equal(await evaluate(`document.querySelector('[data-view="week"]').checked`), true);
    await checkAccountMenu(role);
    await open();
    assert.equal(await evaluate(`document.querySelector('#calendar-resource-controls').closest('#calendar-actions-menu') !== null && document.querySelector('#calendar-view-controls').closest('#calendar-actions-menu') !== null`), true);
    await evaluate(`document.querySelector('[data-view="day"]').click()`);
    await waitFor(`!document.querySelector('#bus-day').hidden && !document.querySelector('#calendar-view-controls').disabled && !document.querySelector('#calendar-actions-menu').open`);
    await open();
    await evaluate(`document.querySelector('[data-view="week"]').click()`);
    await waitFor(`!document.querySelector('#bus-week').hidden && !document.querySelector('#calendar-view-controls').disabled && !document.querySelector('#calendar-actions-menu').open`);
    if (role !== 'public') {
      await evaluate(`document.querySelector('#booking-create-menu > summary').click()`);
      await waitFor(`document.querySelector('#booking-create-menu').open && !document.querySelector('#calendar-actions-menu').open`);
      await open();
      await waitFor(`!document.querySelector('#booking-create-menu').open`);
    }
    await resize(320);
    await waitFor(`document.querySelector('#calendar-actions').parentElement.matches('.calendar-heading-toolbar')`);
    await checkDateControl();
    assert.equal(await evaluate(`document.documentElement.scrollWidth <= 320 && innerWidth <= 320`), true, `${role}: 320px width regression`);
    await checkAccountMenu(role);
    await open();
    assert.equal(await evaluate(`(() => { const r=document.querySelector('[data-calendar-actions-panel]').getBoundingClientRect(); return r.left>=0 && r.right<=innerWidth; })()`), true);
    await evaluate(`document.querySelector('#calendar-actions-menu > summary').focus()`);
    await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
    await waitFor(`!document.querySelector('#calendar-actions-menu').open`);
    if (role !== 'public') {
      await evaluate(`document.querySelector('#booking-create-menu > summary').click()`);
      await waitFor(`document.querySelector('#booking-create-menu').open`);
      assert.equal(await evaluate(`(() => { const r=document.querySelector('.booking-create-options').getBoundingClientRect(); return r.left>=0 && r.right<=innerWidth; })()`), true);
      await evaluate(`document.querySelector('#booking-create-menu > summary').focus()`);
      await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
      await waitFor(`!document.querySelector('#booking-create-menu').open`);
    }
    console.log(`${role}: separate plus/calendar menus, radio switching, booking choices and phone/desktop widths passed.`);
  }
  const appSource = readFileSync(new URL('../www/assets/js/app.js', import.meta.url), 'utf8');
  const cardSource = appSource.slice(appSource.indexOf('function eventCard('), appSource.indexOf('function renderResourceDay('));
  await evaluate(`(() => {
    ${cardSource}
    const stage = document.createElement('div');
    stage.id = 'card-density-fixture';
    stage.style.cssText = 'position:fixed;top:0;left:0;width:280px;pointer-events:none';
    const sample = {
      assignment_id: 1, occurrence_id: 1, event_type: 'training', status: 'confirmed',
      title: 'Testbuchung', start: '2026-10-26T10:00:00+01:00', end: '2026-10-26T11:00:00+01:00',
      recurrence_rule: { frequency: 'weekly' },
    };
    for (const [id, width, height, week, recurring] of [
      ['large-day', 240, 120, false, true],
      ['narrow-day', 109, 120, false, true],
      ['short-day', 240, 51, false, true],
      ['boundary-day', 110, 52, false, true],
      ['narrow-week', 109, null, true, true],
      ['large-week', 240, null, true, true],
      ['single-day', 240, 120, false, false],
    ]) {
      const holder = document.createElement('div');
      holder.style.cssText = 'position:relative;width:280px;min-height:160px';
      const card = eventCard({ ...sample, recurrence_rule: recurring ? sample.recurrence_rule : null }, week);
      card.id = id;
      if (!week) card.classList.add('resource-event');
      card.style.boxSizing = 'content-box';
      card.style.width = width + 'px';
      if (height !== null) card.style.height = height + 'px';
      holder.append(card);
      stage.append(holder);
    }
    document.body.append(stage);
    return true;
  })()`);
  const cardVisibility = async id => evaluate(`(() => {
    const card = document.getElementById(${JSON.stringify(id)});
    return {
      category: getComputedStyle(card.querySelector('.booking-category')).display,
      series: getComputedStyle(card.querySelector('.booking-recurring-marker')).display,
      title: card.querySelector('.booking-title').textContent,
      time: card.querySelector('.booking-time').textContent,
      description: card.getAttribute('aria-label'),
      tooltip: card.title,
      height: card.getBoundingClientRect().height,
    };
  })()`);
  for (const id of ['large-day', 'boundary-day', 'large-week']) {
    const card = await cardVisibility(id);
    assert.notEqual(card.category, 'none', `${id}: category must remain visible when space is sufficient`);
    assert.notEqual(card.series, 'none', `${id}: recurrence must remain visible when space is sufficient`);
  }
  for (const id of ['narrow-day', 'short-day', 'narrow-week']) {
    const card = await cardVisibility(id);
    assert.equal(card.category, 'none', `${id}: category must be hidden when space is limited`);
    assert.equal(card.series, 'none', `${id}: recurrence must be hidden when space is limited`);
    assert.equal(card.title, 'Testbuchung');
    assert.ok(card.time.length > 0);
    assert.match(card.description, /Training.*Regeltermin/);
    assert.equal(card.tooltip, card.description);
    assert.ok(card.height > 0, `${id}: size containment must not collapse the card`);
  }
  assert.equal((await cardVisibility('single-day')).series, 'none');
  await evaluate(`document.getElementById('large-day').style.width = '109px'`);
  assert.equal((await cardVisibility('large-day')).category, 'none');
  await evaluate(`document.getElementById('large-day').style.width = '240px'`);
  assert.notEqual((await cardVisibility('large-day')).category, 'none');
  await evaluate(`document.getElementById('large-day').style.height = '51px'`);
  assert.equal((await cardVisibility('large-day')).series, 'none');
  await evaluate(`document.getElementById('large-day').style.height = '120px'`);
  assert.notEqual((await cardVisibility('large-day')).series, 'none');
  await evaluate(`document.getElementById('narrow-week').style.width = '240px'`);
  assert.notEqual((await cardVisibility('narrow-week')).category, 'none');
  await evaluate(`document.getElementById('card-density-fixture').remove()`);
  console.log('Calendar cards: narrow/short hiding, exact size boundaries, accessible descriptions and automatic restoration passed.');
  assert.deepEqual(exceptions, []);
  await send('Browser.close');
} finally {
  ws?.close();
  if (chrome.exitCode === null) {
    chrome.kill();
    await Promise.race([once(chrome, 'exit'), new Promise(resolve => setTimeout(resolve, 3000))]);
  }
  await rm(profile, { recursive: true, force: true, maxRetries: 8, retryDelay: 250 });
}
