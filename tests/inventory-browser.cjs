#!/usr/bin/env node
'use strict';

// Real PHP/SQLite + browser coverage. The database, account and port are isolated
// per run; production content and the developer's running preview are untouched.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { randomUUID } = require('node:crypto');
const { setTimeout: delay } = require('node:timers/promises');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/valer/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const ROOT = path.resolve(__dirname, '..');
const bundledPHP = path.join(ROOT, '.runtime', 'php', 'php.exe');
const PHP = process.env.PHP_BINARY || (fs.existsSync(bundledPHP) ? bundledPHP : 'php');
const USERNAME = 'inventory-test-owner';
const PASSWORD = 'inventory-test-only-password-2026';
let checks = 0;
const check = (condition, message) => { checks += 1; assert.ok(condition, message); };
const equal = (actual, expected, message) => { checks += 1; assert.deepEqual(actual, expected, message); };

async function unusedPort() {
  const listener = net.createServer();
  await new Promise((resolve, reject) => { listener.once('error', reject); listener.listen(0, '127.0.0.1', resolve); });
  const port = listener.address().port;
  await new Promise(resolve => listener.close(resolve));
  return port;
}

function removeTestDirectory(directory) {
  // Resolve both targets before recursive deletion, including their real paths.
  const tempRoot = fs.realpathSync(os.tmpdir());
  const actual = fs.realpathSync(directory);
  const relative = path.relative(tempRoot, actual);
  assert.ok(relative && !relative.startsWith('..') && !path.isAbsolute(relative), 'Cleanup must remain inside os.tmpdir()');
  assert.ok(path.basename(actual).startsWith('mahara-inventory-browser-'), 'Cleanup must target this test directory');
  fs.rmSync(actual, { recursive: true, force: true });
}

async function stopServer(server) {
  if (!server || server.exitCode !== null) return;
  await new Promise((resolve, reject) => {
    const timeout = setTimeout(() => reject(new Error('Isolated PHP process did not exit')), 5000);
    server.once('exit', () => { clearTimeout(timeout); resolve(); });
    server.kill();
  });
}

async function inventoryReady(page) {
  await page.locator('[data-inventory-content]').waitFor({ state: 'visible' });
  await page.locator('[data-inventory-loading]').waitFor({ state: 'hidden' });
}

function row(page, productId, size) {
  return page.locator(`[data-product-id="${productId}"] tr[data-size="${size}"]`);
}

async function quantity(page, productId, size, text) {
  await page.waitForFunction(({ productId, size, text }) => {
    const node = document.querySelector(`[data-product-id="${productId}"] tr[data-size="${size}"] .inventory-quantity`);
    return node?.textContent.trim() === text;
  }, { productId, size, text });
  equal((await row(page, productId, size).locator('.inventory-quantity').textContent()).trim(), text, `${productId}/${size}: visible quantity`);
}

async function move(page, productId, size, type, amount, note = '') {
  await row(page, productId, size).locator(`[data-action="${type}"]`).click();
  const form = page.locator('[data-movement-form]');
  await form.locator('[name="quantity"]').fill(String(amount));
  await form.locator('[name="note"]').fill(note);
  await page.locator('[data-movement-submit]').click();
  await page.locator('[data-movement-dialog]').waitFor({ state: 'hidden' });
}

async function closeMovement(page) {
  await page.locator('[data-movement-dialog] [data-close-dialog]').first().click();
  await page.locator('[data-movement-dialog]').waitFor({ state: 'hidden' });
}

async function noOverflow(page, label) {
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
  check(dimensions.document <= dimensions.viewport + 1, `${label}: horizontal document overflow ${JSON.stringify(dimensions)}`);
}

function csvRows(text) {
  return text.replace(/^\uFEFF/, '').trim().split('\r\n').map(line => {
    const values = [];
    const expression = /"((?:[^"]|"")*)"(?:;|$)/g;
    let match;
    while ((match = expression.exec(line))) values.push(match[1].replaceAll('""', '"'));
    return values;
  });
}

async function run() {
  const privateDir = fs.mkdtempSync(path.join(os.tmpdir(), 'mahara-inventory-browser-'));
  let server;
  let browser;
  let serverOutput = '';
  try {
    const port = await unusedPort();
    const origin = `http://127.0.0.1:${port}`;
    const env = { ...process.env, APP_ENV: 'development', APP_ORIGIN: origin, DATA_DIR: privateDir, MAHARA_ADMIN_USERNAME: USERNAME, MAHARA_ADMIN_PASSWORD: PASSWORD };
    const created = spawnSync(PHP, [path.join(ROOT, 'tools/admin.php'), 'create'], { cwd: ROOT, env, encoding: 'utf8', windowsHide: true });
    equal(created.status, 0, `Create isolated administrator: ${created.stderr || created.error || ''}`);
    check(!created.stdout.includes(PASSWORD), 'Account CLI must not disclose its password');
    server = spawn(PHP, ['-S', `127.0.0.1:${port}`, '-t', ROOT, path.join(ROOT, 'server/router.php')], { cwd: ROOT, env, stdio: ['ignore', 'ignore', 'pipe'], windowsHide: true });
    let spawnError;
    server.on('error', error => { spawnError = error; });
    server.stderr.on('data', chunk => { serverOutput = (serverOutput + chunk).slice(-10000); });
    let started = false;
    for (let attempt = 0; attempt < 100; attempt += 1) {
      if (spawnError) throw spawnError;
      try { if ((await fetch(origin + '/api/content')).status === 200) { started = true; break; } } catch {}
      if (server.exitCode !== null) throw new Error(`PHP server stopped: ${serverOutput}`);
      await delay(100);
    }
    check(started, `Isolated PHP server must start: ${serverOutput}`);
    const unauthenticated = route => fetch(origin + route, { redirect: 'manual' });
    const protectedPage = await unauthenticated('/inventory.html');
    equal(protectedPage.status, 302, 'Inventory HTML must require a session');
    equal(protectedPage.headers.get('location'), '/admin/login', 'Inventory redirects to login');
    equal((await unauthenticated('/api/admin/inventory')).status, 401, 'Inventory read API must require a session');
    equal((await fetch(origin + '/api/admin/inventory/movements', { method: 'POST' })).status, 401, 'Movement API must require a session');
    equal((await fetch(origin + '/api/admin/inventory/threshold', { method: 'PUT' })).status, 404, 'Retired threshold API is absent');

    browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce', acceptDownloads: true });
    const errors = [];
    const failedAssets = [];
    const externalRequests = [];
    await context.route('**/*', route => {
      const url = new URL(route.request().url());
      if (url.origin === origin || ['data:', 'blob:'].includes(url.protocol)) return route.continue();
      externalRequests.push(url.href); return route.abort();
    });
    context.on('page', page => {
      page.on('pageerror', error => errors.push(`${page.url()}: ${error.message}`));
      page.on('response', response => {
        if (response.status() >= 400 && !new URL(response.url()).pathname.startsWith('/api/')) failedAssets.push(`${response.status()} ${response.url()}`);
      });
    });
    const page = await context.newPage();
    await page.goto(origin + '/admin/login', { waitUntil: 'load' });
    await page.locator('[name="username"]').fill(USERNAME);
    await page.locator('[name="password"]').fill(PASSWORD);
    await page.locator('[data-login-submit]').click();
    await page.waitForURL('**/admin.html');
    await page.goto(origin + '/inventory.html', { waitUntil: 'load' });
    await inventoryReady(page);
    check((await page.locator('meta[name="robots"]').getAttribute('content')).includes('noindex'), 'Inventory page must have noindex');
    equal(await page.locator('a[href="/admin.html?newProduct=1"]').count(), 1, 'Inventory must link to product creation');
    equal(await page.locator('[data-threshold-dialog], [data-action="threshold"], [data-status-filter="low"], [data-status] option[value="low"]').count(), 0, 'Replenishment settings and low-stock filters have been removed');
    equal(await page.locator('.inventory-product:first-child thead th').allTextContents(), ['Размер', 'На складе', 'Операции'], 'Stock table has only the three useful columns');
    const session = await (await context.request.get(origin + '/api/admin/session')).json();
    const cookies = await context.cookies(origin);
    const cookie = cookies.map(item => `${item.name}=${item.value}`).join('; ');
    const request = (route, options = {}) => fetch(origin + route, { ...options, headers: { Cookie: cookie, ...options.headers } });
    const write = (route, method, body, headers = {}) => request(route, { method, headers: { Origin: origin, 'X-CSRF-Token': session.csrfToken, 'Content-Type': 'application/json', ...headers }, body: JSON.stringify(body) });
    const snapshot = async () => (await request('/api/admin/inventory')).json();
    const variant = (data, id, size) => data.products.find(product => product.id === id).variants.find(item => item.size === size);
    let content = await (await request('/api/content')).json();
    const initialProducts = structuredClone(content.products);
    const target = content.products.find(product => product.category === 'hoodie' && product.sizes.length > 1 && content.products.filter(other => `${other.title} ${other.id} ${other.subtitle}`.toLowerCase().includes(product.id)).length === 1);
    const size = target.sizes[0];
    const targetCard = page.locator(`[data-product-id="${target.id}"]`);
    equal(await targetCard.locator(`a[href="/admin.html?product=${target.id}"]`).count(), 1, 'Each product must link to its catalog editor');
    const editor = await context.newPage();
    await editor.goto(origin + await targetCard.locator(`a[href="/admin.html?product=${target.id}"]`).getAttribute('href'), { waitUntil: 'load' });
    await editor.locator('[data-cms-workspace][aria-busy="false"]').waitFor();
    equal(await editor.locator('[data-cms-field="title"]').inputValue(), target.title, 'Edit link opens the selected product');
    await editor.goto(origin + '/admin.html?newProduct=1', { waitUntil: 'load' });
    await editor.waitForFunction(() => document.querySelector('#cms-editor-title')?.textContent === 'Новый товар');
    equal(await editor.locator('[data-cms-field="title"]').inputValue(), '', 'Add link opens a fresh product form');
    equal((await (await request('/api/content')).json()).products.length, initialProducts.length, 'Opening Add does not publish a blank product');
    await editor.close();
    let data = await snapshot();
    equal(data.movements.length, 0, 'A new isolated database has no fictitious movements');
    check(data.products.every(product => product.variants.every(item => !item.initialized)), 'All new balances must be explicitly unknown');
    equal(data.summary.units, 0, 'Unknown balances do not add units');
    equal(data.summary.out, 0, 'Unknown balances must not be reported as sold out');
    await quantity(page, target.id, size, 'Не задано');
    check(await row(page, target.id, size).locator('[data-action="writeoff"]').isDisabled(), 'Unknown stock cannot be written off');

    console.log('Inventory: session/CSRF guards, unknown stock, receipt → writeoff → recount');
    const initialMovement = { requestId: randomUUID(), productId: target.id, size, type: 'receipt', quantity: 5, expectedRevision: 0, note: '' };
    equal((await write('/api/admin/inventory/movements', 'POST', initialMovement, { 'X-CSRF-Token': '' })).status, 403, 'Movement requires CSRF');
    equal((await write('/api/admin/inventory/movements', 'POST', initialMovement, { Origin: 'https://attacker.example' })).status, 403, 'Movement requires the exact origin');
    await move(page, target.id, size, 'receipt', 5, 'Первая поставка');
    await quantity(page, target.id, size, '5 шт.');
    equal((await snapshot()).products.find(product => product.id === target.id).status, 'in_stock', 'Positive stock is in stock');
    await move(page, target.id, size, 'writeoff', 2, 'Продано в магазине');
    await quantity(page, target.id, size, '3 шт.');
    equal((await snapshot()).products.find(product => product.id === target.id).status, 'in_stock', 'Small positive stock has no replenishment warning');
    await move(page, target.id, size, 'set', 0, 'Инвентаризация: фактически пусто');
    await quantity(page, target.id, size, '0 шт.');
    check(await row(page, target.id, size).locator('[data-action="writeoff"]').isDisabled(), 'Zero stock cannot be written off');
    data = await snapshot();
    check(data.products.every(product => product.variants.every(item => !('threshold' in item))), 'Public inventory has no threshold values');
    check(!('low' in data.summary), 'Summary has no replenishment count');
    equal(data.movements.map(item => [item.type, item.quantity, item.before, item.after, item.actor]), [['set', 0, 3, 0, USERNAME], ['writeoff', 2, 5, 3, USERNAME], ['receipt', 5, null, 5, USERNAME]], 'History preserves unknown/known before balances, the actor and every movement in reverse order');
    check(data.movements[0].note.includes('фактически пусто'), 'History preserves recount reasons');
    equal(await page.locator('[data-movement-list] tbody tr').count(), 3, 'Browser history displays all three operations');
    check((await page.locator('[data-movement-list]').textContent()).includes('Инвентаризация: фактически пусто'), 'History displays the saved note as text');
    await page.reload({ waitUntil: 'load' });
    await inventoryReady(page);
    await quantity(page, target.id, size, '0 шт.');
    equal(variant(await snapshot(), target.id, size).quantity, 0, 'Reload preserves the balance');

    console.log('Inventory: stale browser tabs and nonnegative balances');
    const stale = await context.newPage();
    await stale.goto(origin + '/inventory.html', { waitUntil: 'load' });
    await inventoryReady(stale);
    await row(stale, target.id, size).locator('[data-action="receipt"]').click();
    await stale.locator('[data-movement-form] [name="quantity"]').fill('9');
    await move(page, target.id, size, 'receipt', 2, 'Пополнение после пересчёта');
    await stale.locator('[data-movement-submit]').click();
    await stale.waitForFunction(() => document.querySelector('[data-movement-error]')?.textContent.includes('другим пользователем'));
    check(await stale.locator('[data-movement-error]').isVisible(), 'A stale revision must show a visible explanation');
    check(await stale.locator('[data-movement-submit]').isDisabled(), 'A stale dialog cannot resubmit against its old revision');
    equal(variant(await snapshot(), target.id, size).quantity, 2, 'A stale tab cannot overwrite a newer balance');
    await closeMovement(stale);
    await quantity(stale, target.id, size, '2 шт.');
    await row(page, target.id, size).locator('[data-action="writeoff"]').click();
    await page.locator('[data-movement-form] [name="quantity"]').fill('3');
    await page.locator('[data-movement-form] [name="note"]').fill('Проверка запрета отрицательного остатка');
    await page.locator('[data-movement-submit]').click();
    await page.waitForFunction(() => document.querySelector('[data-movement-error]')?.textContent.includes('больше'));
    check(await page.locator('[data-movement-error]').isVisible(), 'Invalid writeoff explains the problem visibly');
    equal(variant(await snapshot(), target.id, size).quantity, 2, 'Browser validation prevents negative stock');
    await closeMovement(page);
    const current = variant(await snapshot(), target.id, size);
    equal((await write('/api/admin/inventory/movements', 'POST', { requestId: randomUUID(), productId: target.id, size, type: 'writeoff', quantity: 3, expectedRevision: current.revision, note: 'API must reject overspending' })).status, 409, 'API independently prevents negative stock');
    equal((await write('/api/admin/inventory/movements', 'POST', { requestId: randomUUID(), productId: target.id, size, type: 'set', quantity: -1, expectedRevision: current.revision, note: 'API must reject negative counts' })).status, 400, 'API rejects negative absolute stock');
    equal(variant(await snapshot(), target.id, size).quantity, 2, 'Rejected operations leave the balance unchanged');
    await stale.close();

    console.log('Inventory: uncertain network result retries the same operation once');
    const otherSize = target.sizes[1];
    let lostResponse = false;
    await page.route('**/api/admin/inventory/movements', async route => {
      if (lostResponse) return route.continue();
      lostResponse = true;
      const actual = await route.fetch();
      equal(actual.status(), 201, 'Simulated lost response happens after the server committed');
      return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ error: 'Тест: ответ потерян после сохранения' }) });
    });
    await row(page, target.id, otherSize).locator('[data-action="receipt"]').click();
    await page.locator('[data-movement-form] [name="quantity"]').fill('1');
    await page.locator('[data-movement-submit]').click();
    await page.waitForFunction(() => document.querySelector('[data-movement-submit]')?.textContent === 'Проверить и повторить');
    check(await page.locator('[data-movement-error]').isVisible(), 'An uncertain save visibly offers a safe retry');
    check(await page.locator('[data-movement-form] [name="note"]').isDisabled(), 'An uncertain operation freezes its comment together with quantity/type');
    equal(variant(await snapshot(), target.id, otherSize).quantity, 1, 'The first uncertain request was committed');
    await page.locator('[data-movement-submit]').click();
    await page.locator('[data-movement-dialog]').waitFor({ state: 'hidden' });
    await page.unroute('**/api/admin/inventory/movements');
    equal(variant(await snapshot(), target.id, otherSize).quantity, 1, 'Retrying the same movement does not double the receipt');
    equal((await snapshot()).movements.filter(item => item.productId === target.id && item.size === otherSize).length, 1, 'An uncertain retry creates one history entry');

    const recover = await context.newPage();
    let failedLoad = false;
    await recover.route('**/api/admin/inventory', route => {
      if (failedLoad) return route.continue();
      failedLoad = true;
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ error: 'Тест: временно нет связи' }) });
    });
    await recover.goto(origin + '/inventory.html', { waitUntil: 'load' });
    await recover.locator('[data-inventory-error]').waitFor({ state: 'visible' });
    check((await recover.locator('[data-inventory-error]').textContent()).includes('Тест: временно нет связи'), 'Initial load error is explained visibly');
    check(await recover.locator('[data-reload]').isVisible(), 'Initial load error provides a visible retry');
    await recover.locator('[data-reload]').click();
    await inventoryReady(recover);
    await quantity(recover, target.id, size, '2 шт.');
    await recover.close();

    console.log('Inventory: filters, empty result recovery, CSV export');
    await page.locator('[data-search]').fill(target.id);
    equal(await page.locator('[data-product-list] [data-product-id]').count(), 1, 'Search matches a product code');
    await page.locator('[data-category]').selectOption('tee');
    equal(await page.locator('[data-product-list] [data-product-id]').count(), 0, 'Category combines with search');
    check(await page.locator('[data-empty]').isVisible(), 'No matches must explain the empty result');
    check(await page.locator('[data-export]').isDisabled(), 'An empty selection cannot export an empty data file');
    await page.locator('[data-clear-filters]').click();
    equal(await page.locator('[data-product-list] [data-product-id]').count(), initialProducts.length, 'Clear restores every active product');
    await page.locator('[data-category]').selectOption('tee');
    const teeIds = initialProducts.filter(product => product.category === 'tee').map(product => product.id);
    equal(await page.locator('[data-product-list] [data-product-id]').evaluateAll(nodes => nodes.map(node => node.dataset.productId)), teeIds, 'Category filter renders only tees');
    await page.locator('[data-clear-filters]').click();
    await page.locator('[data-status]').selectOption('in_stock');
    equal(await page.locator('[data-product-list] [data-product-id]').evaluateAll(nodes => nodes.map(node => node.dataset.productId)), [target.id], 'In-stock filter shows the product with initialized positive balances');
    await page.locator('[data-status]').selectOption('untracked');
    check(await page.locator(`[data-product-list] [data-product-id="${target.id}"]`).count() === 0, 'Untracked filter excludes the partially tracked product');
    await page.locator('[data-clear-filters]').click();
    await page.locator('[data-search]').fill(target.id);
    await page.locator('[data-category]').selectOption('hoodie');
    await page.locator('[data-status]').selectOption('in_stock');
    const downloadEvent = page.waitForEvent('download');
    await page.locator('[data-export]').click();
    const download = await downloadEvent;
    check(download.suggestedFilename().startsWith('mahara-stock-') && download.suggestedFilename().endsWith('.csv'), 'Export must have a useful CSV filename');
    const exported = csvRows(fs.readFileSync(await download.path(), 'utf8'));
    equal(exported[0], ['Товар', 'Код', 'Категория', 'Размер', 'Остаток', 'Статус'], 'CSV includes stock without the retired replenishment column');
    equal(exported.length, target.sizes.length + 1, 'CSV follows the visible filtered products');
    check(exported.slice(1).every(values => values[1] === target.id && values[2] === 'Худи'), 'CSV excludes products outside search/category filters');
    equal(exported.find(values => values[3] === size).slice(4, 6), ['2', 'В наличии'], 'CSV includes the saved balance and availability');
    check(exported.slice(1).some(values => values[4] === '' && values[5] === 'Не задано'), 'CSV distinguishes unknown stock from zero');
    await page.locator('[data-clear-filters]').click();

    const soldOut = initialProducts.find(product => product.id !== target.id);
    for (const soldOutSize of soldOut.sizes) {
      const response = await write('/api/admin/inventory/movements', 'POST', { requestId: randomUUID(), productId: soldOut.id, size: soldOutSize, type: 'set', quantity: 0, expectedRevision: 0, note: 'Тест инвентаризации пустого склада' });
      equal(response.status, 201, 'Initialize an explicitly empty size in the isolated test database');
    }
    await page.locator('[data-refresh]').click();
    await page.waitForFunction(() => !document.querySelector('[data-refresh]').disabled);
    await page.locator('[data-status]').selectOption('out');
    equal(await page.locator('[data-product-list] [data-product-id]').evaluateAll(nodes => nodes.map(node => node.dataset.productId)), [soldOut.id], 'Sold-out filter requires all sizes to be explicitly empty');
    await page.locator('[data-clear-filters]').click();

    console.log('Inventory: removed sizes/products are archived with balances and history');
    content = await (await request('/api/content')).json();
    content.products.find(product => product.id === target.id).sizes = target.sizes.filter(item => item !== size);
    equal((await write('/api/admin/content', 'PUT', content)).status, 200, 'Catalog may remove a tracked size');
    await page.locator('[data-refresh]').click();
    await page.waitForFunction(() => !document.querySelector('[data-refresh]').disabled);
    data = await snapshot();
    equal(variant(data, target.id, size).archived, true, 'A removed size must remain archived');
    equal(variant(data, target.id, size).quantity, 2, 'Removing a size preserves its balance');
    equal(await row(page, target.id, size).locator('[data-action]').count(), 0, 'Archived sizes cannot change stock');
    check((await row(page, target.id, size).textContent()).includes('Убран из продажи'), 'Archived size is explained in the browser');
    await page.locator(`[data-remove-product="${target.id}"]`).click();
    await page.waitForFunction(() => !document.querySelector('[data-delete-submit]').disabled);
    check((await page.locator('[data-delete-product]').textContent()).includes(target.id), 'Deletion confirmation identifies the exact product');
    await page.locator('[data-delete-submit]').click();
    await page.locator('[data-delete-dialog]').waitFor({ state: 'hidden' });
    await page.waitForFunction(() => !document.querySelector('[data-refresh]').disabled);
    check(!(await (await request('/api/content')).json()).products.some(product => product.id === target.id), 'UI removal updates the published catalog');
    equal(await page.locator(`[data-product-list] [data-product-id="${target.id}"]`).count(), 0, 'Removed product is hidden from active inventory');
    await page.locator('[data-status]').selectOption('archived');
    equal(await page.locator('[data-product-list] [data-product-id]').evaluateAll(nodes => nodes.map(node => node.dataset.productId)), [target.id], 'Archive filter exposes the removed product');
    equal(await row(page, target.id, size).locator('[data-action]').count(), 0, 'Archived product operations are disabled');
    data = await snapshot();
    check(data.products.find(product => product.id === target.id).archived, 'API marks the removed product as archived');
    equal(variant(data, target.id, size).quantity, 2, 'Archived product preserves its quantity');
    equal(data.movements.filter(item => item.productId === target.id).length, 5, 'Catalog removal preserves the complete target history');
    content = await (await request('/api/content')).json();
    content.products = initialProducts;
    equal((await write('/api/admin/content', 'PUT', content)).status, 200, 'Restore the temporary catalog for final UI checks');
    await page.locator('[data-clear-filters]').click();
    await page.locator('[data-refresh]').click();
    await page.waitForFunction(() => !document.querySelector('[data-refresh]').disabled);
    await quantity(page, target.id, size, '2 шт.');
    check(!variant(await snapshot(), target.id, size).archived, 'Restoring a product reuses its saved balance');

    console.log('Inventory: desktop/mobile layout, dialog bounds, browser errors');
    fs.mkdirSync(path.join(ROOT, 'preview'), { recursive: true });
    await noOverflow(page, 'Desktop 1440px');
    await page.waitForFunction(() => !document.querySelector('[data-toast]').textContent.trim());
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: path.join(ROOT, 'preview/inventory-desktop.png'), fullPage: false });
    for (const width of [390, 360]) {
      await page.setViewportSize({ width, height: 844 });
      await noOverflow(page, `Inventory ${width}px`);
      await page.locator('[data-search]').fill(target.id);
      await page.evaluate(() => { document.activeElement?.blur(); window.scrollTo(0, 0); });
      await page.screenshot({ path: path.join(ROOT, `preview/inventory-mobile-${width}.png`), fullPage: true });
      await row(page, target.id, size).locator('[data-action="receipt"]').click();
      await noOverflow(page, `Inventory dialog ${width}px`);
      const dialog = await page.locator('[data-movement-dialog]').boundingBox();
      check(dialog.x >= -1 && dialog.x + dialog.width <= width + 1, `${width}px: movement dialog fits the viewport`);
      if (width === 360) await page.screenshot({ path: path.join(ROOT, 'preview/inventory-dialog-mobile.png'), fullPage: false });
      await closeMovement(page);
      await page.locator('[data-clear-filters]').click();
    }
    equal(errors, [], 'No uncaught browser JavaScript errors');
    equal(failedAssets, [], 'All UI scripts, styles and images load');
    equal(externalRequests, [], 'Inventory does not depend on external requests');
    await context.close();
    console.log(`PASS: ${checks} inventory checks (HTTP guards, persistent workflow, stale tabs, nonnegative stock, filters, CSV, archives, responsive UI)`);
    console.log('Screenshots: preview/inventory-desktop.png, preview/inventory-mobile-390.png, preview/inventory-mobile-360.png, preview/inventory-dialog-mobile.png');
  } catch (error) {
    if (serverOutput.includes('PHP Fatal error') || serverOutput.includes('MAHARA backend error')) console.error(serverOutput);
    throw error;
  } finally {
    try { if (browser) await browser.close(); }
    finally { try { await stopServer(server); } finally { removeTestDirectory(privateDir); } }
  }
}

run().catch(error => { console.error(error); process.exitCode = 1; });
