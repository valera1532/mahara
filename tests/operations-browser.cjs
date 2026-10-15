#!/usr/bin/env node
'use strict';

// Local DTO fixtures and an isolated real router: no working configuration,
// customer data, payment provider requests, or stock changes are used here.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');
const { setTimeout: delay } = require('node:timers/promises');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/valer/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const ROOT = path.resolve(__dirname, '..');
const malicious = '<img src=x onerror="window.operationsXss=true">';
const paidId = 'a'.repeat(32);
const paidNumber = 'MH-20261015-0001';
let checks = 0;
const check = (condition, message) => { checks += 1; assert.ok(condition, message); };
const equal = (actual, expected, message) => { checks += 1; assert.deepEqual(actual, expected, message); };
const stock = (productId, size, quantity, before, after) => ({ productId, size, quantity, before, after });
const item = (productId, title, size, quantity) => ({ productId, title, size, quantity });
const fixture = {
  authorized: false, reads: [], orderReads: [], writes: [], failNext: false, failLogout: false,
  entries: [
    { id: 'movement:1', kind: 'receipt', createdAt: '2026-10-15T10:00:00Z', actor: `Оператор ${malicious}`, items: [item('hoodie-black', `Худи ${malicious}`, 'XL', 10)], stockChanges: [stock('hoodie-black', 'XL', 10, null, 10)], comment: `Поставка ${malicious}`, order: null },
    { id: `order:${paidId}`, kind: 'order', createdAt: '2026-10-15T09:00:00Z', actor: 'T-Bank', items: [item('hoodie-black', 'Худи MAHARA', 'XL', 2), item('tee-white', 'Футболка MAHARA', 'M', 1)], stockChanges: [stock('hoodie-black', 'XL', 2, 10, 8), stock('tee-white', 'M', 1, 5, 4)], comment: '', order: { id: paidId, number: paidNumber, status: 'paid', totalAmount: 4070000, stockApplied: true } },
    { id: `order:${'b'.repeat(32)}`, kind: 'order', createdAt: '2026-10-15T08:00:00Z', actor: 'Покупатель', items: [item('tee-black', 'Футболка MAHARA', 'L', 1)], stockChanges: [], comment: '', order: { id: 'b'.repeat(32), number: 'MH-20261015-0002', status: 'payment_pending', totalAmount: 690000, stockApplied: false } },
    { id: 'movement:2', kind: 'writeoff', createdAt: '2026-10-15T07:00:00Z', actor: 'test-owner', items: [item('hoodie-green', 'Зелёное худи', 'S', 2)], stockChanges: [stock('hoodie-green', 'S', 2, 8, 6)], comment: 'Ручное списание: образцы', order: null },
    { id: 'movement:3', kind: 'set', createdAt: '2026-10-15T06:00:00Z', actor: 'test-owner', items: [item('tee-white', 'Белая футболка', 'M', 7)], stockChanges: [stock('tee-white', 'M', 7, 4, 7)], comment: 'Инвентаризация', order: null },
  ],
};
const row = (page, id) => page.locator(`[data-operation-id="${id}"]`);

async function browserChecks() {
  const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname === '/admin/login') { response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); response.end('<title>Вход в управление</title><h1>Вход</h1>'); return; }
    const relative = decodeURIComponent(url.pathname).replace(/^\//, '');
    const file = path.resolve(ROOT, relative);
    const allowed = ['operations.html', 'operations.js', 'operations.css', 'orders.html', 'orders.js', 'orders.css', 'styles.css', 'management.css'].includes(relative) || relative.startsWith('assets/');
    if (!allowed || !file.startsWith(ROOT + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { response.writeHead(404); response.end(); return; }
    response.writeHead(200, { 'Content-Type': { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' }[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
    response.end(fs.readFileSync(file));
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const origin = `http://127.0.0.1:${server.address().port}`;
  let browser;
  try {
    browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1050 }, reducedMotion: 'reduce' });
    const page = await context.newPage(); const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await context.route('**/api/**', async route => {
      const request = route.request(); const url = new URL(request.url());
      let status = 200; let body;
      if (url.pathname === '/api/admin/logout' && request.method() === 'POST') { fixture.writes.push({ path: url.pathname, method: request.method(), csrf: request.headers()['x-csrf-token'] }); status = fixture.failLogout ? 503 : 200; body = fixture.failLogout ? { error: 'Выход временно недоступен. Повторите попытку.' } : { ok: true }; }
      else if (request.method() !== 'GET') { fixture.writes.push({ path: url.pathname, method: request.method() }); status = 405; body = { error: 'Тест допускает только чтение.' }; }
      else if (url.pathname === '/api/admin/session') { status = fixture.authorized ? 200 : 401; body = fixture.authorized ? { user: { username: 'test-owner' }, csrfToken: 'operations-fixture-csrf' } : { error: 'Требуется вход.' }; }
      else if (!fixture.authorized) { status = 401; body = { error: 'Требуется вход.' }; }
      else if (url.pathname === '/api/admin/operations') {
        fixture.reads.push(Object.fromEntries(url.searchParams));
        if (fixture.failNext) { fixture.failNext = false; status = 503; body = { error: 'Журнал временно недоступен. Повторите загрузку.' }; }
        else {
          const q = (url.searchParams.get('q') || '').toLocaleLowerCase('ru'); const kind = url.searchParams.get('kind') || 'all';
          const selected = fixture.entries.filter(entry => (kind === 'all' || entry.kind === kind) && [entry.comment, entry.actor, entry.order?.number, ...entry.items.flatMap(value => [value.title, value.productId, value.size])].filter(Boolean).join(' ').toLocaleLowerCase('ru').includes(q));
          const pageSize = Number(url.searchParams.get('pageSize')); const pages = Math.max(1, Math.ceil(selected.length / pageSize)); const current = Math.min(pages, Number(url.searchParams.get('page')));
          body = { entries: selected.slice((current - 1) * pageSize, current * pageSize), pagination: { page: current, pageSize, pages, total: selected.length }, query: q, kind };
        }
      } else if (url.pathname === '/api/admin/orders') {
        fixture.orderReads.push(Object.fromEntries(url.searchParams));
        const order = { id: paidId, number: paidNumber, status: 'paid', totalAmount: 4070000, createdAt: '2026-10-15T09:00:00Z', customer: { name: 'Тестовый покупатель' }, delivery: {}, items: [{ id: 'hoodie-black', title: 'Худи MAHARA', size: 'XL', qty: 2, unitAmount: 1690000, amount: 3380000 }], fulfillmentStatus: 'processing' };
        body = { orders: url.searchParams.get('q') === paidNumber ? [order] : [], pagination: { page: 1, pageSize: 50, pages: 1, total: 1 } };
      } else { status = 404; body = { error: 'Не найдено.' }; }
      await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
    });

    const waitLoaded = () => page.waitForFunction(() => !document.querySelector('[data-refresh]').disabled && document.querySelector('[data-page-info]').textContent.length > 0);
    const listAction = async action => {
      await Promise.all([page.waitForResponse(response => new URL(response.url()).pathname === '/api/admin/operations'), action()]);
      await waitLoaded();
    };
    await page.goto(`${origin}/operations.html`); await page.waitForURL('**/admin/login');
    equal(fixture.reads.length, 0, 'Anonymous session redirects before reading operation entries');
    fixture.authorized = true; await page.goto(`${origin}/operations.html`); await waitLoaded();
    equal(await page.locator('[data-operation-id]').count(), 5, 'Unified list shows receipt, two orders, manual writeoff, and adjustment');
    equal(fixture.reads.at(-1), { page: '1', pageSize: '50', q: '', kind: 'all' }, 'Initial list requests one bounded server page');
    const receipt = row(page, 'movement:1'); const paid = row(page, `order:${paidId}`); const pending = row(page, `order:${'b'.repeat(32)}`);
    check((await receipt.textContent()).includes('Приход') && (await receipt.textContent()).includes('+10 шт.'), 'Receipt displays its actual incoming quantity');
    check((await receipt.textContent()).includes('Не задано → 10'), 'Initial receipt distinguishes an untracked balance from zero');
    check((await receipt.textContent()).includes(malicious), 'Untrusted note, actor, and title remain readable literal text');
    equal(await page.locator('[data-entries] img,[data-entries] script,[data-entries] svg').count(), 0, 'Untrusted operation strings never create active elements');
    equal(await page.evaluate(() => window.operationsXss), undefined, 'Operation fields cannot execute injected JavaScript');
    check((await paid.textContent()).includes('Оплачен'), 'Paid order shows the current payment status');
    equal(await paid.locator('.operations-item').count(), 2, 'A multi-item order retains both purchased variants');
    equal(await paid.locator('.operations-balance').count(), 2, 'Paid order contains both corresponding stock changes');
    check((await paid.textContent()).includes('10 → 8') && (await paid.textContent()).includes('5 → 4'), 'Paid order displays actual before and after balances');
    equal(await page.locator(`[data-operation-id="order:${paidId}"]`).count(), 1, 'Paid order is rendered once, without a second payment writeoff row');
    equal(await page.locator('.operations-badge--writeoff').count(), 1, 'Only the manual writeoff appears separately from the paid order');
    check((await paid.textContent()).includes('Товар списан после оплаты'), 'Paid order explains the completed warehouse writeoff');
    check((await pending.textContent()).includes('Ожидает оплаты') && (await pending.textContent()).includes('Без списания') && (await pending.textContent()).includes('Товар со склада не списан'), 'Pending order clearly leaves stock untouched');
    check((await row(page, 'movement:2').textContent()).includes('−2 шт.') && (await row(page, 'movement:2').textContent()).includes('8 → 6'), 'Manual writeoff shows a negative quantity and resulting stock');
    check((await row(page, 'movement:3').textContent()).includes('Факт: 7 шт.') && (await row(page, 'movement:3').textContent()).includes('4 → 7'), 'Adjustment displays the new physical balance');
    check((await page.locator('[data-status]').textContent()).includes('Найдено операций: 5'), 'Status reports the total across operation types');
    equal(await page.locator('.management-nav a').evaluateAll(nodes => nodes.map(node => node.getAttribute('href'))), ['/admin.html', '/inventory.html', '/orders.html', '/operations.html', '/catalog.html'], 'Unified header exposes the five expected management sections');
    equal(await page.locator('.management-nav [aria-current="page"]').getAttribute('href'), '/operations.html', 'Operations is the active management section');

    const href = await paid.locator('.operations-order-link').getAttribute('href'); const target = new URL(href, origin);
    equal(target.pathname, '/orders.html', 'Operation order link opens the existing order administration');
    equal(Object.fromEntries(target.searchParams), { order: paidId, q: paidNumber }, 'Order deep link preserves the exact order ID and number query');
    equal([...target.searchParams.keys()].length, 2, 'Order link adds no unrelated query parameters');
    await paid.locator('.operations-order-link').click(); await page.locator(`[data-order-id="${paidId}"]`).waitFor();
    equal(await page.locator('[data-search]').inputValue(), paidNumber, 'Order administration pre-fills the linked order number');
    equal(fixture.orderReads.at(-1), { page: '1', pageSize: '50', q: paidNumber, status: 'all' }, 'Order administration searches the requested order on the server');
    check(await page.locator(`[data-order-id="${paidId}"]`).evaluate(node => node.open), 'Linked order card opens automatically');
    await page.goto(`${origin}/operations.html`); await waitLoaded();

    for (const [kind, count, label] of [['receipt', 1, 'Приход'], ['order', 2, 'Заказ'], ['writeoff', 1, 'Списание'], ['set', 1, 'Корректировка']]) {
      await listAction(() => page.locator('[data-kind]').selectOption(kind));
      equal(fixture.reads.at(-1).kind, kind, `${kind}: filter is sent to the server`);
      equal(fixture.reads.at(-1).page, '1', `${kind}: filter starts from page one`);
      equal(await page.locator('[data-operation-id]').count(), count, `${kind}: only matching operation rows are displayed`);
      equal(await page.locator('.operations-badge').first().textContent(), label, `${kind}: matching operation label is shown`);
    }
    await listAction(() => page.locator('[data-kind]').selectOption('all'));
    await listAction(() => page.locator('[data-search]').fill('  образцы  '));
    equal(fixture.reads.at(-1).q, 'образцы', 'Search sends trimmed text to the server');
    equal(await page.locator('[data-operation-id]').count(), 1, 'Server search renders its matching operation');
    check((await page.locator('[data-entries]').textContent()).includes('Ручное списание'), 'Search uses comment matches beyond product labels');
    await listAction(() => page.locator('[data-search]').fill('неизвестная-операция'));
    check(await page.locator('[data-empty]').isVisible(), 'An empty search has a readable empty state');
    equal(await page.locator('[data-operation-id]').count(), 0, 'Empty search removes stale rows');
    check(await page.locator('[data-page-prev]').isDisabled() && await page.locator('[data-page-next]').isDisabled(), 'Empty result disables both page controls');
    await listAction(() => page.locator('[data-search]').fill(''));

    const previews = path.join(ROOT, '.local', 'operations-preview'); fs.mkdirSync(previews, { recursive: true });
    await page.locator('h1').click();
    await page.screenshot({ path: path.join(previews, 'desktop.png'), fullPage: true });
    for (const width of [390, 320]) {
      await page.setViewportSize({ width, height: 844 });
      await page.evaluate(async () => { await document.fonts.ready; await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))); window.scrollTo(0, 0); document.querySelector('.operations-table-wrap').scrollLeft = 0; document.querySelector('.management-nav').scrollLeft = 0; });
      const layout = await page.evaluate(() => {
        const table = document.querySelector('.operations-table-wrap'); const nav = document.querySelector('.management-nav');
        return { pageWidth: document.documentElement.scrollWidth, width: innerWidth, tableClient: table.clientWidth, tableScroll: table.scrollWidth, tableOverflow: getComputedStyle(table).overflowX, navWidth: nav.clientWidth, navScroll: nav.scrollWidth, navOverflow: getComputedStyle(nav).overflowX };
      });
      await page.screenshot({ path: path.join(previews, `mobile-${width}.png`), fullPage: true });
      check(layout.pageWidth <= width + 1, `${width}px: page stays within viewport: ${JSON.stringify(layout)}`);
      check(layout.tableScroll > layout.tableClient && layout.tableOverflow === 'auto', `${width}px: wide operation table owns its horizontal scroll`);
      await page.locator('.operations-table-wrap').evaluate(node => { node.scrollLeft = node.scrollWidth; });
      check(await page.locator('.operations-table-wrap').evaluate(node => node.scrollLeft > 0), `${width}px: table can be scrolled to order comments`);
      equal(await page.locator('.management-nav a').count(), 5, `${width}px: all five navigation links remain available`);
      check(layout.navScroll <= layout.navWidth + 1 || layout.navOverflow === 'auto', `${width}px: management navigation fits or owns its horizontal scroll`);
      await page.locator('.management-nav').evaluate(node => { node.scrollLeft = node.scrollWidth; });
      const last = await page.locator('.management-nav a').last().boundingBox();
      check(last.x >= 0 && last.x + last.width <= width + 1, `${width}px: last navigation link can be reached without page overflow`);
    }
    await page.setViewportSize({ width: 1440, height: 1050 });
    for (let index = 0; index < 205; index += 1) fixture.entries.push({ id: `movement:${100 + index}`, kind: 'receipt', createdAt: '2026-10-01T00:00:00Z', actor: 'fixture-owner', items: [item(`archive-${index}`, index === 204 ? 'ЁЛКА Архивная позиция' : `Архивный товар ${index}`, 'M', 1)], stockChanges: [stock(`archive-${index}`, 'M', 1, 0, 1)], comment: index === 204 ? 'Уникальный-дальний-комментарий' : 'Архивная поставка', order: null });
    await listAction(() => page.locator('[data-refresh]').click());
    equal(await page.locator('[data-operation-id]').count(), 50, 'Growing history still renders a bounded server page');
    check((await page.locator('[data-status]').textContent()).includes('210'), 'Global count includes entries outside the current page');
    check(await page.locator('[data-page-prev]').isDisabled(), 'Previous is disabled on the first server page');
    for (let pageNumber = 2; pageNumber <= 5; pageNumber += 1) {
      await listAction(() => page.locator('[data-page-next]').click());
      equal(fixture.reads.at(-1).page, String(pageNumber), `Next requests server page ${pageNumber}`);
    }
    equal(await page.locator('[data-operation-id]').count(), 10, 'Last page reaches historical entries beyond the first 200');
    check(await row(page, 'movement:304').isVisible(), 'Oldest operation is reachable from page controls');
    check(await page.locator('[data-page-next]').isDisabled(), 'Next is disabled on the last server page');
    await listAction(() => page.locator('[data-page-prev]').click()); equal(fixture.reads.at(-1).page, '4', 'Previous requests the prior server page');
    await listAction(() => page.locator('[data-search]').fill('уникальный-дальний-комментарий'));
    equal(fixture.reads.at(-1).page, '1', 'Search from a later page resets to page one');
    equal(await page.locator('[data-operation-id]').count(), 1, 'Global search finds an entry outside the initial 200');
    check(await row(page, 'movement:304').isVisible(), 'Search displays the actual remote-page match');
    await listAction(() => page.locator('[data-search]').fill('ёлка'));
    check(await row(page, 'movement:304').isVisible(), 'Russian case-folded search displays the server match');
    fixture.failNext = true; await listAction(() => page.locator('[data-refresh]').click());
    check((await page.locator('[data-error]').textContent()).includes('Журнал временно недоступен'), 'A failed read displays the safe server error');
    check(await row(page, 'movement:304').isVisible(), 'Failed refresh retains the previously loaded history');
    check(await page.locator('[data-refresh]').isEnabled(), 'Failed refresh can be retried');
    await listAction(() => page.locator('[data-refresh]').click());
    check(await page.locator('[data-error]').isHidden(), 'Successful retry clears the previous error');
    equal(fixture.writes, [], 'Opening, filtering, paging, and linking operations never send financial or inventory writes');
    fixture.failLogout = true;
    await page.locator('[data-logout]').click();
    await page.locator('[data-error]').filter({ hasText: 'Выход временно недоступен' }).waitFor();
    check(await page.locator('[data-logout]').isEnabled(), 'Failed logout restores the button for an explicit retry');
    check(page.url().endsWith('/operations.html'), 'Failed logout keeps the administrator on the current page');
    check(await row(page, 'movement:304').isVisible(), 'Failed logout retains the loaded operation history');
    equal(fixture.writes, [{ path: '/api/admin/logout', method: 'POST', csrf: 'operations-fixture-csrf' }], 'The only explicit write is the CSRF-protected mock logout');
    fixture.failLogout = false;
    equal(errors, [], 'Operations and linked orders have no browser runtime errors');
    fixture.authorized = false; await page.locator('[data-refresh]').click(); await page.waitForURL('**/admin/login');
    check(page.url().endsWith('/admin/login'), 'An expired session returns to login');
    await context.close();
  } finally { if (browser) await browser.close(); await new Promise(resolve => server.close(resolve)); }
}

async function routerChecks() {
  const listener = net.createServer(); await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
  const port = listener.address().port; await new Promise(resolve => listener.close(resolve));
  const origin = `http://127.0.0.1:${port}`;
  const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'mahara-operations-browser-'));
  const router = path.join(temporary, 'router.php');
  const php = process.env.PHP_BINARY || path.join(ROOT, '.runtime', 'php', process.platform === 'win32' ? 'php.exe' : 'php');
  const isolated = { root: ROOT, data_dir: temporary, origin, production: false, trust_proxy: false, mail: { enabled: false }, payments: { enabled: false }, shipping: { enabled: false } };
  fs.writeFileSync(router, `<?php\ndefine('MAHARA_LIBRARY_ONLY', true);\n$GLOBALS['mahara_app_test_config'] = json_decode(base64_decode('${Buffer.from(JSON.stringify(isolated)).toString('base64')}'), true, 512, JSON_THROW_ON_ERROR);\n$GLOBALS['mahara_payments_test_secrets'] = ['terminal_key' => '', 'password' => ''];\n$GLOBALS['mahara_shipping_test_secrets'] = ['account' => '', 'password' => ''];\n$GLOBALS['mahara_mail_test_secrets'] = ['username' => '', 'password' => ''];\nrequire base64_decode('${Buffer.from(path.join(ROOT, 'server', 'app.php')).toString('base64')}');\nmahara_dispatch();\n`);
  const child = spawn(php, ['-S', `127.0.0.1:${port}`, router], { cwd: ROOT, env: { ...process.env, APP_ENV: 'development', DATA_DIR: temporary, APP_ORIGIN: origin }, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
  let logs = ''; let processError;
  child.on('error', error => { processError = error; }); child.stdout.on('data', value => { logs += value; }); child.stderr.on('data', value => { logs += value; });
  try {
    let ready = false;
    for (let attempt = 0; attempt < 60; attempt += 1) {
      if (processError) throw processError;
      try { ready = (await fetch(`${origin}/operations.css`)).ok; } catch { /* Isolated PHP is starting. */ }
      if (ready) break; await delay(100);
    }
    check(ready, 'Isolated real application router starts without working configuration');
    for (const endpoint of ['/operations.html', '/admin/operations']) {
      const response = await fetch(origin + endpoint, { redirect: 'manual' });
      equal(response.status, 302, `${endpoint}: real router protects the operations page`);
      equal(response.headers.get('location'), '/admin/login', `${endpoint}: anonymous users are redirected to local login`);
      check((response.headers.get('cache-control') || '').includes('no-store'), `${endpoint}: protected redirect cannot be cached`);
      check(!(await response.text()).includes('data-entries'), `${endpoint}: anonymous response has no journal HTML`);
    }
    const response = await fetch(`${origin}/api/admin/operations`, { redirect: 'manual' });
    equal(response.status, 401, 'Real operations API rejects anonymous reads');
    check((response.headers.get('content-type') || '').includes('application/json'), 'Unauthenticated operations API responds with JSON');
    const body = await response.json();
    check(typeof body.error === 'string' && !Object.hasOwn(body, 'entries'), 'Unauthenticated API contains an error and no history');
    check(!logs.includes('PHP Fatal error') && !logs.includes('MAHARA backend error'), 'Isolated router reports no backend failures');
  } finally {
    if (child.exitCode === null && !processError) await new Promise(resolve => { child.once('exit', resolve); child.kill(); });
    const actual = fs.realpathSync(temporary); const relative = path.relative(fs.realpathSync(os.tmpdir()), actual);
    assert.ok(relative && !relative.startsWith('..') && !path.isAbsolute(relative) && path.basename(actual).startsWith('mahara-operations-browser-'), 'Cleanup stays in the verified isolated temporary directory');
    for (const name of ['mahara.sqlite', 'mahara.sqlite-wal', 'mahara.sqlite-shm', 'router.php']) { const file = path.join(actual, name); if (fs.existsSync(file)) fs.unlinkSync(file); }
    fs.rmdirSync(actual);
  }
}

browserChecks().then(routerChecks).then(() => {
  console.log(`PASS: ${checks} operations browser/router checks (unified journal, safe text, order deep link, global pagination/search, read-only requests, mobile layout, isolated anonymous guards)`);
  console.log('Screenshots: .local/operations-preview/desktop.png, mobile-390.png, mobile-320.png');
}).catch(error => { console.error(error); process.exitCode = 1; });
