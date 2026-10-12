#!/usr/bin/env node
'use strict';

// Local fixtures exercise administrator UI behavior without financial requests.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/valer/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const ROOT = path.resolve(__dirname, '..');
const csrf = 'orders-test-csrf-only';
const paidId = 'a'.repeat(32);
const malicious = '<img src=x onerror="window.ordersXss=true">';
let checks = 0;
const check = (condition, message) => { checks += 1; assert.ok(condition, message); };
const equal = (actual, expected, message) => { checks += 1; assert.deepEqual(actual, expected, message); };
const fixture = { authorized: false, orderReads: 0, writes: [], failRefund: false, holdRefund: false, releaseRefund: null, orders: [
  { id: paidId, number: 'MH-20261010-0001', status: 'paid', createdAt: '2026-10-10T09:30:00Z', totalAmount: 1690000, deliveryAmount: 0, currency: 'RUB', customer: { name: `Иван ${malicious}`, email: 'customer@example.ru', phone: '+79991234567' }, delivery: { city: 'Москва', point: 'MSK1', address: `Тестовая улица, 1 ${malicious}`, shippingAmount: 36000 }, items: [{ id: 'hoodie-black', title: `Худи ${malicious}`, size: 'M', qty: 1, unitAmount: 1690000, amount: 1690000 }], fulfillmentStatus: 'processing', trackingNumber: '' },
  { id: 'b'.repeat(32), number: 'MH-20261010-0002', status: 'payment_pending', totalAmount: 690000, createdAt: '2026-10-10T10:00:00Z', customer: { name: 'Мария Покупатель', email: 'maria@example.ru', phone: '+79990000001' }, delivery: { city: 'Казань', point: 'KZN1', address: 'Тестовая, 2' }, items: [{ id: 'tee-black', title: 'Футболка MAHARA', size: 'L', qty: 1, unitAmount: 690000, amount: 690000 }] },
  { id: 'c'.repeat(32), number: 'MH-20261010-0003', status: 'refunded', totalAmount: 790000, createdAt: '2026-10-10T10:15:00Z', customer: { name: 'Пётр Возвратов' }, delivery: {}, items: [] },
  { id: 'd'.repeat(32), number: 'MH-20261010-0004', status: 'expired', totalAmount: 1690000, createdAt: '2026-10-10T10:30:00Z', customer: { name: 'Алексей Тестов' }, delivery: {}, items: [] },
] };
const card = page => page.locator(`[data-order-id="${paidId}"]`);

async function run() {
  const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname === '/admin/login') { response.writeHead(200, { 'Content-Type': 'text/html' }); response.end('<title>Вход в управление</title><h1>Вход</h1>'); return; }
    const relative = decodeURIComponent(url.pathname).replace(/^\//, ''); const file = path.resolve(ROOT, relative);
    const allowed = ['orders.html', 'orders.js', 'orders.css', 'styles.css', 'management.css'].includes(relative) || relative.startsWith('assets/');
    if (!allowed || !file.startsWith(ROOT + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { response.writeHead(404); response.end(); return; }
    response.writeHead(200, { 'Content-Type': { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' }[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' }); response.end(fs.readFileSync(file));
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve)); const origin = `http://127.0.0.1:${server.address().port}`;
  let browser;
  try {
    browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1050 }, reducedMotion: 'reduce' }); const page = await context.newPage();
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await context.route('**/api/admin/**', async route => {
      const request = route.request(); const requestUrl = new URL(request.url()); const pathname = requestUrl.pathname; let status = 200; let body;
      if (pathname === '/api/admin/session') { status = fixture.authorized ? 200 : 401; body = fixture.authorized ? { user: { username: 'test-owner' }, csrfToken: csrf } : { error: 'Требуется вход.' }; }
      else if (!fixture.authorized) { status = 401; body = { error: 'Требуется вход.' }; }
      else if (pathname === '/api/admin/orders' && request.method() === 'GET') {
        fixture.orderReads += 1;
        const groups = { all: [], paid: ['paid'], pending: ['payment_pending'], refunded: ['refunded', 'partial_refunded', 'refund_pending'], failed: ['failed', 'expired', 'canceled'] };
        const q = (requestUrl.searchParams.get('q') || '').toLocaleLowerCase('ru'); const filter = requestUrl.searchParams.get('status') || 'all';
        const selected = fixture.orders.filter(order => (!groups[filter].length || groups[filter].includes(order.status)) && `${order.number} ${order.customer?.name || ''} ${order.customer?.email || ''} ${order.customer?.phone || ''}`.toLocaleLowerCase('ru').includes(q));
        const pageSize = Number(requestUrl.searchParams.get('pageSize') || 50); const pages = Math.max(1, Math.ceil(selected.length / pageSize)); const currentPage = Math.min(pages, Number(requestUrl.searchParams.get('page') || 1));
        body = { orders: selected.slice((currentPage - 1) * pageSize, currentPage * pageSize), pagination: { page: currentPage, pageSize, pages, total: selected.length }, query: q, status: filter };
      }
      else if (/^\/api\/admin\/orders\/[a-f0-9]{32}\/reconcile$/.test(pathname)) {
        fixture.writes.push({ type: 'reconcile', method: request.method(), headers: request.headers(), body: request.postDataJSON() });
        const order = fixture.orders.find(value => pathname.includes(value.id));
        order.operatorMessage = 'Банк пока не подтвердил окончательный статус. Резерв сохранён; сверьте операцию в Т-Кассе.';
        body = { order };
      }
      else if (pathname === `/api/admin/orders/${paidId}/fulfillment`) { fixture.writes.push({ type: 'fulfillment', method: request.method(), headers: request.headers(), body: request.postDataJSON() }); const update = request.postDataJSON(); Object.assign(fixture.orders[0], { fulfillmentStatus: update.status, trackingNumber: update.trackingNumber }); body = { order: fixture.orders[0] }; }
      else if (pathname === `/api/admin/orders/${paidId}/refund`) {
        fixture.writes.push({ type: 'refund', method: request.method(), headers: request.headers(), body: request.postDataJSON() });
        if (fixture.holdRefund) { fixture.holdRefund = false; await new Promise(resolve => { fixture.releaseRefund = resolve; }); }
        if (fixture.failRefund) { fixture.failRefund = false; status = 503; body = { error: 'Ответ возврата задерживается.' }; }
        else { fixture.orders[0].status = 'refund_pending'; fixture.orders[0].operatorMessage = 'Возврат пока не подтверждён банком. Проверьте статус в банке; не создавайте второй возврат.'; body = { order: fixture.orders[0] }; }
      } else { status = 404; body = { error: 'Не найдено.' }; }
      await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
    });

    await page.goto(`${origin}/orders.html`); await page.waitForURL('**/admin/login');
    equal(fixture.orderReads, 0, 'Unauthenticated session is redirected before loading customer orders');
    fixture.authorized = true; await page.goto(`${origin}/orders.html`); await page.locator('[data-orders] details').nth(3).waitFor();
    equal(await page.locator('[data-orders] details').count(), 4, 'Authenticated administrator sees server order list');
    await card(page).locator('summary').click();
    check((await card(page).textContent()).includes(malicious), 'Customer, address and item markup remain readable text');
    equal(await card(page).locator('img,script,svg').count(), 0, 'Untrusted order text never becomes active HTML');
    equal(await page.evaluate(() => window.ordersXss), undefined, 'Order fields cannot execute injected scripts');
    check((await card(page).locator('.orders-card__details').textContent()).includes('360'), 'CDEK estimate is displayed in delivery details');
    check((await card(page).locator('.orders-card__details').textContent()).includes('покупатель оплачивает СДЭК отдельно'), 'Delivery is explicitly charged to the recipient separately');
    check((await card(page).locator('summary b').textContent()).includes('16'), 'Payment total remains the goods total');

    const listAction = async action => {
      await Promise.all([page.waitForResponse(response => new URL(response.url()).pathname === '/api/admin/orders' && response.request().method() === 'GET'), action()]);
      await page.waitForFunction(() => !document.querySelector('[data-refresh]').disabled);
    };
    await listAction(() => page.locator('[data-search]').fill('customer@example.ru')); equal(await page.locator('[data-orders] details').count(), 1, 'Server search matches customer email');
    await listAction(() => page.locator('[data-search]').fill('+79990000001')); equal(await page.locator('[data-orders] details').count(), 1, 'Server search matches customer phone');
    check((await page.locator('[data-orders]').textContent()).includes('Мария'), 'A later search replaces the previous result');
    await listAction(() => page.locator('[data-search]').fill('неизвестный-заказ')); check(await page.locator('.orders-empty').isVisible(), 'Empty search displays a useful message');
    await listAction(() => page.locator('[data-search]').fill(''));
    for (const [filter, expected] of [['paid', 'Оплачен'], ['pending', 'Ожидает оплаты'], ['refunded', 'Оплата возвращена'], ['failed', 'Время оплаты истекло']]) {
      await listAction(() => page.locator('[data-filter]').selectOption(filter)); equal(await page.locator('[data-orders] details').count(), 1, `${filter}: filter selects expected status`); check((await page.locator('.orders-badge').textContent()).includes(expected), `${filter}: label describes payment status`);
    }
    fixture.orders.push(
      { id: 'e'.repeat(32), number: 'MH-20261010-0005', status: 'partial_refunded', totalAmount: 1690000, customer: { name: 'Частичный возврат' }, delivery: {}, items: [] },
      { id: 'f'.repeat(32), number: 'MH-20261010-0006', status: 'refund_pending', totalAmount: 1690000, customer: { name: 'Возврат в обработке' }, delivery: {}, items: [] },
    );
    await listAction(() => page.locator('[data-refresh]').click());
    await listAction(() => page.locator('[data-filter]').selectOption('refunded'));
    equal(await page.locator('[data-orders] details').count(), 3, 'Return filter includes completed, partial and pending refunds');
    check((await page.locator('[data-orders]').textContent()).includes('Частичный возврат'), 'Partial refund has a readable payment status');
    check((await page.locator('[data-orders]').textContent()).includes('Возврат обрабатывается'), 'Return filter retains refunds awaiting the bank');
    await listAction(() => page.locator('[data-filter]').selectOption('all')); await card(page).locator('summary').click();
    await card(page).locator('.orders-fulfillment select').selectOption('shipped'); await card(page).locator('.orders-fulfillment input').fill('1234567890');
    await card(page).locator('.orders-fulfillment button').click(); await page.locator('[data-status]').filter({ hasText: 'Статус заказа сохранён.' }).waitFor();
    const fulfillment = fixture.writes.find(write => write.type === 'fulfillment');
    equal(fulfillment.body, { status: 'shipped', trackingNumber: '1234567890' }, 'Fulfillment writes the selected status and shipment number');
    equal(fulfillment.method, 'POST', 'Fulfillment changes require a POST request');
    equal(fulfillment.headers['x-csrf-token'], csrf, 'Fulfillment requires the authenticated session CSRF token');
    equal(await card(page).locator('.orders-fulfillment input').inputValue(), '1234567890', 'Saved tracking number survives server refresh');
    check(await card(page).evaluate(node => node.open), 'Refreshing the saved order preserves its open state');

    const refund = () => card(page).getByRole('button', { name: 'Вернуть всю оплату' });
    await refund().click(); await page.locator('[data-refund-dialog]').waitFor({ state: 'visible' });
    equal(await page.evaluate(() => document.activeElement.hasAttribute('data-refund-cancel')), true, 'Refund dialog gives default focus to Cancel');
    await page.locator('[data-refund-cancel]').click(); await page.locator('[data-refund-dialog]').waitFor({ state: 'hidden' });
    equal(fixture.writes.filter(write => write.type === 'refund').length, 0, 'Cancel sends no refund request');
    await refund().click(); await page.keyboard.press('Escape'); await page.locator('[data-refund-dialog]').waitFor({ state: 'hidden' });
    equal(fixture.writes.filter(write => write.type === 'refund').length, 0, 'Escape dismisses an idle refund without sending it');
    await refund().click(); fixture.failRefund = true; fixture.holdRefund = true; await page.locator('[data-refund-confirm]').click();
    await page.waitForFunction(() => document.querySelector('[data-refund-confirm]').disabled);
    check(await page.locator('[data-refund-cancel]').isDisabled(), 'Refund cannot be canceled while its request is in progress');
    await page.keyboard.press('Escape'); check(await page.locator('[data-refund-dialog]').isVisible(), 'Escape cannot hide an in-progress refund');
    while (!fixture.releaseRefund) await new Promise(resolve => setTimeout(resolve, 5));
    fixture.releaseRefund(); fixture.releaseRefund = null;
    await page.locator('[data-refund-error]').filter({ hasText: 'Ответ возврата задерживается.' }).waitFor();
    check(await page.locator('[data-refund-dialog]').isVisible(), 'Uncertain refund result keeps the review dialog open');
    check(await page.locator('[data-refund-confirm]').isEnabled(), 'An uncertain refund can be retried safely');
    await page.locator('[data-refund-cancel]').click(); await refund().click(); await page.locator('[data-refund-confirm]').click();
    await page.locator('[data-refund-dialog]').waitFor({ state: 'hidden' }); await page.locator('[data-status]').filter({ hasText: 'Возврат пока не подтверждён банком.' }).waitFor();
    const refunds = fixture.writes.filter(write => write.type === 'refund'); equal(refunds.length, 2, 'Retry sends exactly one additional request');
    equal(refunds[0].body.requestId, refunds[1].body.requestId, 'Cancel/reopen retry reuses the refund idempotency request ID');
    equal(refunds[1].method, 'POST', 'Refunds require a POST request');
    equal(refunds[1].headers['x-csrf-token'], csrf, 'Refund includes the session CSRF token');
    check((await card(page).locator('.orders-badge').textContent()).includes('Возврат обрабатывается'), 'Bank-pending refund is not presented as completed');
    equal(await card(page).getByRole('button', { name: 'Вернуть всю оплату' }).count(), 0, 'A pending refund cannot be requested again from the order card');
    check((await card(page).locator('.orders-review').textContent()).includes('не создавайте второй возврат'), 'Uncertain state includes practical operator guidance');
    const cancelWritesBefore = fixture.writes.filter(write => write.type === 'refund').length;
    await card(page).getByRole('button', { name: 'Проверить статус в банке' }).click();
    await page.locator('[data-status]').filter({ hasText: 'Резерв сохранён; сверьте операцию' }).waitFor();
    const reconcile = fixture.writes.find(write => write.type === 'reconcile');
    equal(reconcile.body, {}, 'Per-order bank check sends no financial operation payload');
    equal(reconcile.method, 'POST', 'Per-order bank check requires explicit POST');
    equal(reconcile.headers['x-csrf-token'], csrf, 'Per-order bank check is protected by the session CSRF token');
    equal(fixture.writes.filter(write => write.type === 'refund').length, cancelWritesBefore, 'Bank check cannot send another refund');

    fixture.orders[0].status = 'paid'; fixture.orders[0].items[0].title = `Футболка ${'A'.repeat(120)}`; await listAction(() => page.locator('[data-refresh]').click());
    await card(page).locator('.orders-fulfillment').waitFor();
    const previews = path.join(ROOT, '.local', 'orders-preview'); fs.mkdirSync(previews, { recursive: true });
    await page.evaluate(() => window.scrollTo(0, 0)); await page.screenshot({ path: path.join(previews, 'desktop.png'), fullPage: true });
    for (const width of [390, 320]) {
      await page.setViewportSize({ width, height: 844 }); await page.evaluate(() => window.scrollTo(0, 0));
      const overflow = await page.evaluate(() => ({ viewport: innerWidth, width: document.documentElement.scrollWidth, nodes: [...document.querySelectorAll('body *')].map(node => ({ tag: node.tagName, className: node.className, parent: node.parentElement.className, text: node.textContent.slice(0, 100), right: node.getBoundingClientRect().right })).filter(node => node.right > innerWidth + 1).slice(0, 10) }));
      await page.screenshot({ path: path.join(previews, `orders-mobile-${width}.png`), fullPage: true });
      check(overflow.width <= width + 1, `${width}px: orders page has no horizontal overflow ${JSON.stringify(overflow)}`);
      const bounds = await card(page).locator('.orders-items li').evaluate(node => { const item = node.getBoundingClientRect(); const amount = node.lastElementChild.getBoundingClientRect(); return { itemRight: item.right, amountRight: amount.right }; });
      check(bounds.amountRight <= bounds.itemRight + 1, `${width}px: long product title cannot clip the amount ${JSON.stringify(bounds)}`);
      await refund().click(); const dialog = await page.locator('[data-refund-dialog]').boundingBox(); check(dialog.x >= 0 && dialog.x + dialog.width <= width + 1, `${width}px: refund review fits within viewport`);
      await page.screenshot({ path: path.join(previews, `refund-mobile-${width}.png`), fullPage: false }); await page.locator('[data-refund-cancel]').click();
    }
    // Older orders must remain reachable and searchable after the first 200.
    for (let index = 0; index < 205; index += 1) fixture.orders.push({ id: (100000 + index).toString(16).padStart(32, '0'), number: `OLD-${String(index).padStart(4, '0')}`, status: 'failed', customer: { name: index === 204 ? 'ЁЛКА Дальний заказ' : 'Архивный покупатель', email: index === 204 ? 'historical@example.ru' : 'archive@example.ru' }, delivery: {}, items: [], totalAmount: 190000 });
    await listAction(() => page.locator('[data-refresh]').click());
    equal(await page.locator('[data-orders] details').count(), 50, 'First page renders a bounded server page');
    check((await page.locator('[data-page-info]').textContent()).includes('всего 211'), 'Pagination displays the global record count');
    check(await page.locator('[data-page-prev]').isDisabled(), 'Previous page is disabled on page one');
    for (let index = 0; index < 4; index += 1) await listAction(() => page.locator('[data-page-next]').click());
    equal(await page.locator('[data-orders] details').count(), 11, 'Last page reaches historical orders beyond the former 200-order limit');
    check(await page.locator('[data-page-next]').isDisabled(), 'Next page is disabled on the last page');
    check((await page.locator('[data-orders]').textContent()).includes('OLD-0204'), 'Oldest order remains reachable from pagination');
    await listAction(() => page.locator('[data-search]').fill('historical@example.ru'));
    equal(await page.locator('[data-orders] details').count(), 1, 'Global server search finds an order outside the current page');
    check((await page.locator('[data-page-info]').textContent()).includes('Страница 1 из 1'), 'Search resets pagination to the first matching page');
    await listAction(() => page.locator('[data-search]').fill('ёлка'));
    check((await page.locator('[data-orders]').textContent()).includes('OLD-0204'), 'Server search supports Russian case folding');
    equal(errors, [], 'Orders UI has no browser runtime errors');
    fixture.authorized = false; await page.locator('[data-refresh]').click(); await page.waitForURL('**/admin/login');
    check(page.url().endsWith('/admin/login'), 'An expired administrator session redirects back to the login page');
    console.log(`PASS: ${checks} orders browser checks (session gate, XSS-safe order fields, global search/pagination, CSRF bank check/fulfillment, reviewed idempotent refund, ambiguous-state guidance, responsive layout)`);
    await context.close();
  } finally { if (browser) await browser.close(); await new Promise(resolve => server.close(resolve)); }
}

run().catch(error => { console.error(error); process.exitCode = 1; });
