#!/usr/bin/env node
'use strict';

// Browser behavior against deterministic, local API fixtures. No bank or CDEK
// requests are sent; backend/payment validation is covered by server tests.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const { spawn } = require('node:child_process');
const { setTimeout: delay } = require('node:timers/promises');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/valer/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const ROOT = path.resolve(__dirname, '..');
let checks = 0;
const check = (condition, message) => { checks += 1; assert.ok(condition, message); };
const equal = (actual, expected, message) => { checks += 1; assert.deepEqual(actual, expected, message); };
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.webp': 'image/webp', '.svg': 'image/svg+xml' };
const cart = [{ id: 'hoodie-black', size: 'M', qty: 1 }];
const token = 'local-checkout-test-token-0123456789';
const recoveryToken = 'R'.repeat(43); const expiredRecoveryToken = 'E'.repeat(43);
const recoveryMessage = 'Если номер и почта совпали с заказом, мы отправим ссылку для доступа. Проверьте также папку «Спам».';
const fixture = { enabled: true, legal: { termsUrl: 'https://legal.example.ru/offer', privacyUrl: '/privacy.html' }, unitAmount: 1690000, orderStatus: 'pending', paymentUrl: 'https://qr.nspk.ru/local-fixture', paymentExpiresAt: null, paymentUnavailable: false, conflict: false, failCreate: false, shippingEstimateEnabled: undefined, shippingQuoteOverride: null, shippingQuoteFailure: false, shippingRequests: [], shippingExpiresIn: 1200, shippingQuotes: 0, quoteDelayByPoint: {}, cityRequests: [], created: [], headers: [], recoveryEnabled: false, recoveryRequests: [], recoveryExchanges: [], usedRecoveryTokens: new Set(), recoveryDelay: 0 };
const point = { code: 'MSK1', city: 'Москва', cityCode: 44, address: 'Тестовая улица, 1', name: 'Тестовый пункт' };
const points = [point,
  { code: 'MSK2', city: 'Москва', cityCode: 44, address: 'Лесная улица, 2', name: 'Пункт у метро' },
  { code: 'MSK3', city: 'Москва', cityCode: 44, address: 'Садовая улица, 3', name: 'Пункт на Садовой' },
  ...Array.from({ length: 7 }, (_, index) => ({ code: `MSK${index + 4}`, city: 'Москва', cityCode: 44, address: `Примерный проезд, ${index + 4}`, name: `Пункт выдачи ${index + 4}` })),
];

function quote(items) {
  const rows = items.map(item => ({ ...item, title: 'Худи MAHARA Black', image: 'assets/images/black-hoodie.webp', unitAmount: fixture.unitAmount, amount: fixture.unitAmount * item.qty }));
  const subtotalAmount = rows.reduce((sum, item) => sum + item.amount, 0);
  return { items: rows, subtotalAmount, deliveryAmount: 0, totalAmount: subtotalAmount, currency: 'RUB' };
}

function order() {
  return { order: { id: 'local-order-1', number: 'MH-TEST-1', status: fixture.orderStatus, totalAmount: fixture.unitAmount, currency: 'RUB', createdAt: Math.floor(Date.now() / 1000) }, payment: { url: fixture.paymentUrl, qrSvg: fixture.paymentUnavailable ? null : '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="240"><rect width="240" height="240" fill="white"/><path d="M20 20h60v60H20zM160 20h60v60h-60zM20 160h60v60H20zM105 105h30v30h-30z" fill="black"/></svg>', expiresAt: fixture.paymentExpiresAt || new Date(Date.now() + 1200000).toISOString() } };
}

async function fill(page) {
  await page.locator('#customer-name').fill('Иван Тестов');
  await page.locator('#customer-phone').fill('+7 999 123-45-67');
  await page.locator('#customer-email').fill('customer@example.ru');
  await page.locator('#delivery-city').fill('Москва');
  await page.locator('[data-city-code="44"]').click();
  await page.locator('[data-point-trigger]').click();
  await page.locator('[data-point-code="MSK1"]').click();
  if (fixture.shippingEstimateEnabled === false) await page.locator('[data-shipping-status]').filter({ hasText: 'Пункт выдачи подтверждён' }).waitFor();
  else await page.locator('[data-shipping-period]').waitFor({ state: 'visible' });
  await page.locator('#accepted-terms').check();
}

async function ready(page) {
  await page.locator('[data-loading]').waitFor({ state: 'hidden' });
  await page.locator('[data-checkout]').waitFor({ state: 'visible' });
}

async function run() {
  const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    const relative = decodeURIComponent(url.pathname).replace(/^\//, '');
    const file = path.resolve(ROOT, relative);
    const allowed = ['checkout.html', 'checkout.js', 'checkout.css', 'styles.css'].includes(relative) || relative.startsWith('assets/');
    if (!allowed || !file.startsWith(ROOT + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { response.writeHead(404); response.end(); return; }
    response.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
    response.end(fs.readFileSync(file));
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const origin = `http://127.0.0.1:${server.address().port}`;
  const previewDirectory = path.join(ROOT, '.local', 'checkout-preview'); fs.mkdirSync(previewDirectory, { recursive: true });
  let browser;
  try {
    browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1050 }, reducedMotion: 'reduce' });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const routeApi = async route => {
      const request = route.request();
      const pathname = new URL(request.url()).pathname;
      let status = 200; let body;
      if (pathname === '/api/checkout/config') body = { enabled: fixture.enabled, currency: 'RUB', delivery: { mode: 'cdek_receiver', label: 'Доставка СДЭК', amount: 0 }, message: fixture.enabled ? '' : 'Оформление временно недоступно.', legal: fixture.legal };
      else if (pathname === '/api/checkout/recovery/config') body = { enabled: fixture.recoveryEnabled };
      else if (pathname === '/api/checkout/recovery/request' && request.method() === 'POST') {
        fixture.recoveryRequests.push(request.postDataJSON()); body = { message: recoveryMessage };
      }
      else if (pathname === '/api/checkout/recovery/exchange' && request.method() === 'POST') {
        const payload = request.postDataJSON(); fixture.recoveryExchanges.push({ payload, url: request.url(), referrer: request.headers().referer || '' });
        await delay(fixture.recoveryDelay);
        if (payload.token !== recoveryToken || fixture.usedRecoveryTokens.has(payload.token)) { status = 404; body = { error: 'Ссылка недействительна или уже использована. Запросите новую.' }; }
        else { fixture.usedRecoveryTokens.add(payload.token); body = { ...order(), accessToken: token }; }
      }
      else if (pathname === '/api/shipping/config') body = { enabled: true, mode: 'cdek_receiver', ...(fixture.shippingEstimateEnabled === undefined ? {} : { estimateEnabled: fixture.shippingEstimateEnabled }), message: '' };
      else if (pathname === '/api/shipping/cities') {
        const query = new URL(request.url()).searchParams.get('q'); fixture.cityRequests.push(query);
        if (query === 'Старый') { await delay(1000); body = { cities: [{ code: 99, name: 'Старое название', region: 'Старый регион' }] }; }
        else if (query === 'Много') body = { cities: Array.from({ length: 20 }, (_, index) => ({ code: 100 + index, name: `Населённый пункт ${index + 1}`, region: 'Пример региона' })) };
        else body = { cities: query === 'Несуществующий' ? [] : [{ code: 44, name: 'Москва', region: 'Москва' }, { code: 45, name: 'Московский', region: 'Москва' }] };
      }
      else if (pathname === '/api/shipping/points') body = { points };
      else if (pathname === '/api/shipping/quote') {
        const quoteNumber = ++fixture.shippingQuotes;
        const requestBody = request.postDataJSON(); fixture.shippingRequests.push(requestBody);
        const requestedPoint = points.find(item => item.code === requestBody.pointCode);
        if (fixture.shippingQuoteFailure) { status = 503; body = { error: 'Не удалось подтвердить пункт выдачи. Попробуйте снова.' }; }
        else if (!requestedPoint) { status = 422; body = { error: 'Неизвестный пункт.' }; }
        else {
          await delay(fixture.quoteDelayByPoint[requestedPoint.code] || 0);
          body = { quoteId: `local-shipping-quote-${quoteNumber}`, currency: 'RUB', point: requestedPoint, expiresAt: Date.now() / 1000 + fixture.shippingExpiresIn,
            ...(fixture.shippingEstimateEnabled === false ? { amount: null, tariffCode: null, periodMin: null, periodMax: null, estimated: false } : { amount: requestedPoint.code === 'MSK2' ? 49000 : 36000, tariffCode: 136, periodMin: 2, periodMax: 3, estimated: true }),
            ...(fixture.shippingQuoteOverride || {}) };
        }
      }
      else if (pathname === '/api/checkout/quote') body = quote(request.postDataJSON().items);
      else if (pathname === '/api/checkout/orders' && request.method() === 'POST') {
        const payload = request.postDataJSON(); fixture.created.push(payload);
        if (fixture.conflict) { fixture.conflict = false; fixture.unitAmount = 1790000; status = 409; body = { error: 'Стоимость изменилась.', orderNotCreated: true }; }
        else if (fixture.failCreate) { fixture.failCreate = false; status = 503; body = { error: 'Ответ задерживается.' }; }
        else body = { ...order(), accessToken: token };
      } else if (pathname === '/api/checkout/orders/local-order-1') { fixture.headers.push(request.headers()['x-order-token']); body = order(); }
      else { status = 404; body = { error: 'Не найдено.' }; }
      await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) }).catch(error => { if (pathname !== '/api/shipping/cities') throw error; });
    };
    await context.route('**/api/**', routeApi);
    await page.goto(`${origin}/checkout.html`);
    await page.locator('[data-empty]').waitFor({ state: 'visible' });
    check(await page.locator('[data-checkout]').isHidden(), 'Empty cart cannot submit a payment');
    check(await page.locator('[data-recovery]').isHidden(), 'Email recovery is hidden when the backend disables mail');

    await page.evaluate(items => localStorage.setItem('maharaCart', JSON.stringify(items)), cart);
    fixture.enabled = false;
    await page.reload(); await ready(page);
    check(await page.locator('[data-submit]').isDisabled(), 'Unconfigured merchant disables payment');
    check(await page.locator('[data-availability]').isVisible(), 'Availability message is visible');
    check(await page.locator('#accepted-terms').isEnabled(), 'Available legal URLs allow consent even when payment is disabled');
    await page.locator('#accepted-terms').check();
    check(await page.locator('#accepted-terms').isChecked(), 'The customer can click consent while payment is disabled');
    await fill(page);
    check(await page.locator('[data-submit]').isDisabled(), 'Consent and confirmed delivery cannot enable a backend-disabled payment');
    equal(await page.locator('[data-submit-label]').textContent(), 'Оплата через СБП недоступна', 'A backend-disabled payment has a clear button label');
    await page.locator('[data-checkout-form]').evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    await delay(100);
    equal(fixture.created.length, 0, 'Even a submitted complete form sends no order POST when payment is disabled');

    fixture.enabled = true;
    for (const field of ['termsUrl', 'privacyUrl']) {
      const savedUrl = fixture.legal[field]; fixture.legal[field] = null;
      await page.reload(); await ready(page);
      check(await page.locator('#accepted-terms').isDisabled(), `Missing ${field} keeps consent disabled`);
      check(await page.locator('[data-submit]').isDisabled(), `Missing ${field} keeps payment disabled`);
      fixture.legal[field] = savedUrl;
    }

    fixture.enabled = true;
    await page.reload(); await ready(page);
    check(await page.locator('#accepted-terms').isEnabled(), 'Enabled checkout with valid legal URLs retains normal consent');
    const cityInput = page.locator('#delivery-city');
    const suggestions = page.locator('[data-city-suggestions]');
    const beforeShortQuery = fixture.cityRequests.length;
    await cityInput.fill('М'); await delay(450);
    equal(fixture.cityRequests.length, beforeShortQuery, 'A one-letter city query sends no provider request');
    check((await page.locator('[data-shipping-status]').textContent()).includes('две буквы'), 'Short city query explains how to get suggestions');
    await cityInput.fill('Несуществующий'); await page.locator('[data-shipping-status]').filter({ hasText: 'Населённый пункт не найден' }).waitFor();
    check(await suggestions.isHidden(), 'No city matches leaves no empty selectable dropdown');
    check((await page.locator('[data-shipping-status]').textContent()).includes('станицы'), 'No-match guidance also covers villages and stanitsas');
    await cityInput.fill('Мос'); await page.locator('[data-city-code="44"]').waitFor({ state: 'visible' });
    equal(await cityInput.getAttribute('aria-expanded'), 'true', 'The city combobox announces its open suggestion list');
    await cityInput.press('ArrowDown'); check((await cityInput.getAttribute('aria-activedescendant')).includes('-44-'), 'Arrow Down highlights the first city');
    await cityInput.press('ArrowUp'); check((await cityInput.getAttribute('aria-activedescendant')).includes('-45-'), 'Arrow Up wraps to the last matching city');
    await cityInput.press('ArrowDown'); await cityInput.press('Enter'); await page.locator('[data-point-options]').waitFor({ state: 'visible' });
    equal(await cityInput.inputValue(), 'Москва', 'Enter selects the highlighted city in the same input');
    check(await suggestions.isHidden(), 'Selecting a city closes its suggestions');
    equal(fixture.created.length, 0, 'Choosing a city with Enter cannot submit an order');
    await cityInput.fill('Мос'); await page.locator('[data-city-code="44"]').waitFor({ state: 'visible' });
    await cityInput.press('Escape'); check(await suggestions.isHidden(), 'Escape closes the city suggestions');
    equal(await cityInput.getAttribute('aria-expanded'), 'false', 'Escape updates the combobox accessibility state');
    await cityInput.press('ArrowDown'); check(await suggestions.isVisible(), 'Arrow Down reopens cached suggestions after Escape');
    await cityInput.press('Tab'); check(await suggestions.isHidden(), 'Leaving the city field closes suggestions');
    await cityInput.fill('Много'); await page.locator('[data-city-code="119"]').waitFor({ state: 'visible' });
    check(await suggestions.evaluate(node => node.scrollHeight > node.clientHeight), 'A longer suggestion list scrolls within its maximum height');
    await cityInput.press('Tab'); check(await suggestions.isHidden(), 'Tab leaves a scrollable city list instead of focusing its scroll container');
    check(!(await page.evaluate(() => document.querySelector('[data-city-field]').contains(document.activeElement))), 'Scrollable city suggestions add no unwanted Tab stop');
    await cityInput.fill('Старый');
    await page.waitForRequest(request => new URL(request.url()).pathname === '/api/shipping/cities' && new URL(request.url()).searchParams.get('q') === 'Старый');
    await cityInput.fill('Москва'); await page.locator('[data-city-code="44"]').waitFor({ state: 'visible' });
    await delay(1050);
    equal(await page.locator('[data-city-code="99"]').count(), 0, 'An older city response cannot replace the latest search');
    check(await page.locator('[data-city-code="44"]').isVisible(), 'The current city suggestions survive an older canceled request');
    await fill(page);
    const pointTrigger = page.locator('[data-point-trigger]'); const pointPanel = page.locator('[data-point-panel]'); const pointSearch = page.locator('#shipping-point-search');
    const choosePoint = async code => { await pointTrigger.click(); await page.locator(`[data-point-code="${code}"]`).click(); };
    await pointTrigger.click();
    equal(await page.locator('[data-point-code]').count(), points.length, 'PVZ picker displays the server-provided points');
    check(await pointSearch.evaluate(node => node === document.activeElement), 'Opening the PVZ picker focuses its labeled search field');
    check(await page.locator('[data-point-list]').evaluate(node => node.scrollHeight > node.clientHeight), 'A larger PVZ list scrolls inside the picker');
    equal(await page.locator('[data-point-code="MSK1"]').getAttribute('aria-selected'), 'true', 'The selected PVZ is marked in the list');
    check(await page.locator('[data-point-code="MSK1"] .checkout-point-check').evaluate(node => getComputedStyle(node).color !== 'rgba(0, 0, 0, 0)'), 'The selected PVZ displays its check indicator');
    await pointSearch.fill('несуществующая улица');
    check(await page.locator('[data-point-empty]').isVisible(), 'PVZ search explains when no address matches');
    equal(await page.locator('[data-point-code]').count(), 0, 'An unmatched PVZ query offers no selectable item');
    await pointSearch.press('Enter'); equal(fixture.created.length, 0, 'Enter in an empty PVZ search never submits an order');
    equal(await page.locator('#shipping-point').inputValue(), 'MSK1', 'Filtering PVZ does not silently change the selected point');
    await pointSearch.fill('лЕсНаЯ');
    equal(await page.locator('[data-point-code]').count(), 1, 'PVZ filtering matches an address without case sensitivity');
    equal(await page.locator('[data-point-code]').getAttribute('data-point-code'), 'MSK2', 'Address search identifies the matching PVZ');
    await pointSearch.press('Enter'); await pointPanel.waitFor({ state: 'hidden' });
    await page.locator('[data-delivery-amount]').filter({ hasText: '490' }).waitFor();
    equal(fixture.created.length, 0, 'Enter selects a matching PVZ without submitting the complete checkout form');
    equal(await page.locator('[data-point-value]').textContent(), points[1].address, 'The closed PVZ picker shows the selected address');
    check((await page.locator('[data-shipping-address]').textContent()).includes(points[1].address), 'The canonical selected address is shown beside the shipping calculation');
    await pointTrigger.click(); await pointSearch.fill('msk1');
    equal(await page.locator('[data-point-code]').count(), 2, 'PVZ filtering supports point codes');
    await pointSearch.fill('MSK2'); equal(await page.locator('[data-point-code]').count(), 1, 'A unique point code narrows the list to one item');
    await pointSearch.fill(''); equal(await page.locator('[data-point-code]').count(), points.length, 'Clearing PVZ search restores every available point');
    await pointSearch.press('Shift+Tab'); check(await pointTrigger.evaluate(node => node === document.activeElement), 'Shift+Tab returns from PVZ search to its trigger');
    await pointTrigger.press('Escape'); check(await pointPanel.isHidden(), 'Escape closes the PVZ panel from the trigger');
    await pointTrigger.press('ArrowDown');
    check((await pointSearch.getAttribute('aria-activedescendant')).endsWith('-0'), 'Arrow Down on the trigger opens and highlights the first PVZ');
    await pointSearch.press('ArrowUp'); check((await pointSearch.getAttribute('aria-activedescendant')).endsWith(`-${points.length - 1}`), 'PVZ Arrow Up wraps to the last option');
    await pointSearch.press('ArrowDown'); await pointSearch.press('Enter'); await pointPanel.waitFor({ state: 'hidden' });
    await page.locator('[data-delivery-amount]').filter({ hasText: '360' }).waitFor();
    equal(await page.locator('#shipping-point').inputValue(), 'MSK1', 'Keyboard selection saves the highlighted PVZ code');
    check(await pointTrigger.evaluate(node => node === document.activeElement), 'Selecting a PVZ restores focus to its trigger');
    equal(fixture.created.length, 0, 'PVZ keyboard selection makes no order request');
    await pointTrigger.click();
    await page.locator('[data-shipping-picker]').screenshot({ path: path.join(previewDirectory, 'point-picker-desktop.png'), animations: 'disabled' });
    await pointSearch.press('Tab');
    check(await pointPanel.isHidden(), 'Tab exits and closes a scrollable PVZ picker');
    check(!(await page.evaluate(() => document.querySelector('[data-point-options]').contains(document.activeElement))), 'PVZ options and scroll container add no unwanted Tab stop');
    fixture.quoteDelayByPoint.MSK2 = 350;
    await choosePoint('MSK2');
    check(await page.locator('[data-submit]').isDisabled(), 'Changing PVZ disables payment until its new quote arrives');
    equal(await page.locator('[data-delivery-amount]').textContent(), 'Не рассчитана', 'Changing PVZ immediately invalidates the previous amount');
    await page.locator('[data-delivery-amount]').filter({ hasText: '490' }).waitFor();
    await page.waitForFunction(() => !document.querySelector('[data-submit]').disabled);
    check((await page.locator('[data-total]').textContent()).includes('16'), 'Changing PVZ only recalculates separately paid delivery, preserving the goods total');
    fixture.quoteDelayByPoint.MSK2 = 650;
    await choosePoint('MSK1'); await page.locator('[data-delivery-amount]').filter({ hasText: '360' }).waitFor();
    await choosePoint('MSK2'); await choosePoint('MSK1');
    await page.locator('[data-delivery-amount]').filter({ hasText: '360' }).waitFor(); await delay(750);
    equal(await page.locator('#shipping-point').inputValue(), 'MSK1', 'A delayed previous PVZ quote cannot change the current selection');
    check((await page.locator('[data-delivery-amount]').textContent()).includes('360'), 'A delayed previous PVZ quote cannot restore its old delivery price');
    fixture.quoteDelayByPoint.MSK2 = 0;
    await cityInput.fill('Москва изменилась');
    check(await page.locator('[data-submit]').isDisabled(), 'Editing a selected city immediately disables payment');
    check(await page.locator('[data-point-options]').isHidden(), 'Editing a selected city clears its pickup point');
    equal(await page.locator('#shipping-point').inputValue(), '', 'Changing the city clears the canonical PVZ code');
    check(await pointPanel.isHidden(), 'Changing the city closes the PVZ picker');
    equal(await page.locator('[data-point-value]').textContent(), 'Выберите пункт выдачи', 'Changing the city resets the visible PVZ address');
    equal(await page.locator('[data-delivery-amount]').textContent(), 'Не рассчитана', 'Editing a city invalidates the previous shipping amount');
    await fill(page);
    equal(await page.locator('[data-consent-copy] a').first().getAttribute('href'), 'https://legal.example.ru/offer', 'Configured external HTTPS legal documents are supported');
    equal(await page.locator('[data-consent-copy] a').count(), 1, 'The order checkbox accepts only the terms of purchase');
    equal(await page.locator('[data-privacy-copy] a').getAttribute('href'), `${origin}/privacy.html`, 'Privacy policy has a separate accessible link');
    check((await page.locator('[data-total]').textContent()).includes('16'), 'Server quote is rendered');
    check((await page.locator('[data-delivery-amount]').textContent()).includes('360'), 'Actual CDEK shipping quote is shown upfront');
    check((await page.locator('[data-shipping-period]').textContent()).includes('при получении'), 'Customer is told shipping is paid separately to CDEK');
    fixture.conflict = true;
    await page.locator('[data-submit]').click();
    await page.locator('[data-error]').waitFor({ state: 'visible' });
    check((await page.locator('[data-total]').textContent()).includes('17'), 'Changed server price refreshes quote before retry');
    equal(fixture.created[0].expectedTotalAmount, 1690000, 'Submitted order includes the amount the customer saw');
    equal(fixture.created[0].delivery.point, 'MSK1', 'Submitted delivery uses the verified pickup point');
    check(fixture.created[0].delivery.quoteId.startsWith('local-shipping-quote-'), 'Submitted order carries the server delivery quote ID');
    check(await page.locator('[data-order]').isHidden(), 'Conflict never presents payment as created');

    fixture.shippingExpiresIn = .25;
    await page.locator('[data-calculate-shipping]').click();
    await page.locator('[data-shipping-status]').filter({ hasText: 'Расчёт устарел' }).waitFor();
    check(await page.locator('[data-submit]').isDisabled(), 'Expired shipping quote disables payment');
    fixture.shippingExpiresIn = 1200;
    await page.locator('[data-calculate-shipping]').click();
    await page.locator('[data-submit]').waitFor({ state: 'visible' });
    await page.waitForFunction(() => !document.querySelector('[data-submit]').disabled);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: path.join(previewDirectory, 'desktop.png'), fullPage: true });
    for (const width of [390, 320]) {
      await page.setViewportSize({ width, height: 844 });
      await page.evaluate(() => window.scrollTo(0, 0));
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
      check(!overflow, `${width}px: checkout has no horizontal overflow`);
      await page.screenshot({ path: path.join(previewDirectory, `mobile-${width}.png`), fullPage: true });
    }
    await page.setViewportSize({ width: 1440, height: 1050 });

    const touchContext = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, reducedMotion: 'reduce' });
    try {
      await touchContext.route('**/api/**', routeApi);
      await touchContext.addInitScript(items => localStorage.setItem('maharaCart', JSON.stringify(items)), cart);
      const touchPage = await touchContext.newPage(); const touchErrors = [];
      touchPage.on('pageerror', error => touchErrors.push(error.message));
      await touchPage.goto(`${origin}/checkout.html`); await ready(touchPage);
      check(await touchPage.evaluate(() => navigator.maxTouchPoints > 0), 'Mobile PVZ regression uses a touch-capable browser context');
      await touchPage.locator('#delivery-city').fill('Москва'); await touchPage.locator('[data-city-code="44"]').tap();
      await touchPage.locator('[data-point-trigger]').tap();
      const touchList = touchPage.locator('[data-point-list]'); await touchList.scrollIntoViewIfNeeded();
      const listBounds = await touchList.boundingBox(); const client = await touchContext.newCDPSession(touchPage);
      const x = listBounds.x + listBounds.width / 2; const startY = listBounds.y + listBounds.height - 24; const endY = listBounds.y + 24;
      await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y: startY }] });
      for (let step = 1; step <= 8; step += 1) { await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y: startY + (endY - startY) * step / 8 }] }); await delay(15); }
      await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); await delay(100);
      check(await touchList.evaluate(node => node.scrollTop > 0), 'A real touch swipe scrolls the PVZ list');
      await touchPage.locator('#shipping-point-search').fill('Садовая'); await touchPage.locator('[data-point-code="MSK3"]').tap();
      await touchPage.locator('[data-point-panel]').waitFor({ state: 'hidden' }); await touchPage.locator('[data-delivery-amount]').filter({ hasText: '360' }).waitFor();
      equal(await touchPage.locator('#shipping-point').inputValue(), 'MSK3', 'Touch selection works after scrolling and filtering the PVZ list');
      equal(await touchPage.locator('[data-point-value]').textContent(), points[2].address, 'Mobile PVZ trigger displays the address chosen by touch');
      await touchPage.locator('[data-point-trigger]').tap(); await touchPage.locator('#shipping-point-search').fill('MSK1'); await touchPage.locator('[data-point-code="MSK1"]').tap();
      await touchPage.locator('[data-shipping-period]').waitFor({ state: 'visible' }); await touchPage.locator('[data-point-trigger]').tap();
      check((await touchPage.locator('[data-point-code="MSK1"]').getAttribute('aria-selected')) === 'true', 'Mobile reopened PVZ list preserves the selected point');
      check(await touchPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Open mobile PVZ picker does not overflow horizontally');
      await touchPage.locator('[data-shipping-picker]').screenshot({ path: path.join(previewDirectory, 'point-picker-mobile.png'), animations: 'disabled' });
      equal(touchErrors, [], 'Touch PVZ interactions produce no browser runtime errors');
      equal(fixture.created.length, 1, 'Touch PVZ selection never creates a payment order');
    } finally { await touchContext.close(); }

    fixture.failCreate = true;
    await page.locator('[data-submit]').click();
    await page.locator('[data-error]').filter({ hasText: 'Ответ задерживается.' }).waitFor();
    check(await page.locator('[data-checkout]').isHidden(), 'An uncertain order blocks a new checkout and exposes a dedicated retry');
    await page.locator('[data-retry]').click();
    await page.locator('[data-order]').waitFor({ state: 'visible' });
    equal(fixture.created[1].requestId, fixture.created[2].requestId, 'Uncertain result retries the same idempotent request');
    equal(fixture.created[2].expectedTotalAmount, 1790000, 'Retry uses refreshed authoritative quote');
    equal(fixture.created[2].customer.email, 'customer@example.ru', 'Guest contacts are sent with the order');
    check(!page.url().includes(token), 'Access token is never written to the URL');
    equal(await page.locator('[data-payment-link]').getAttribute('href'), 'https://qr.nspk.ru/local-fixture', 'Approved bank/SBP links are accepted');
    check((await page.locator('[data-qr]').getAttribute('src')).startsWith('blob:'), 'QR SVG is isolated in an image Blob');
    check((await page.locator('[data-expiry]').textContent()).includes('Оплата доступна до'), 'ISO payment expiry from the real backend is rendered');
    equal(await page.evaluate(() => JSON.parse(localStorage.getItem('maharaCart'))), cart, 'Pending order does not clear the cart');

    // A client-side success marker must never turn pending into paid.
    await page.goto(`${origin}/checkout.html?order=local-order-1&paid=true`);
    await page.locator('[data-order]').waitFor({ state: 'visible' });
    check((await page.locator('[data-order-title]').textContent()).includes('Заказ создан'), 'URL success parameters do not confirm payment');
    equal(fixture.headers.at(-1), token, 'Order status requires its session access token');
    fixture.paymentUrl = 'https://qr.nspk.ru.attacker.example/pay';
    await page.locator('[data-check-status]').click();
    await page.locator('[data-payment-link]').waitFor({ state: 'hidden' });
    check(!(await page.locator('[data-payment-link]').getAttribute('href')), 'Lookalike payment domains are blocked');

    fixture.paymentExpiresAt = new Date(Date.now() - 60000).toISOString(); fixture.paymentUrl = null; fixture.paymentUnavailable = true;
    await page.locator('[data-check-status]').click();
    await page.locator('[data-order-status]').filter({ hasText: 'Время оплаты истекло. Проверяем итоговый статус в банке.' }).waitFor();
    equal(await page.evaluate(() => JSON.parse(localStorage.getItem('maharaCart'))), cart, 'Expired pending payment does not imply paid or clear the cart');
    check(await page.locator('[data-payment-link]').isHidden() && await page.locator('[data-qr-wrap]').isHidden(), 'Expired pending order offers no stale payment action');
    check((await page.locator('[data-order-hint]').textContent()).includes('Дождитесь подтверждения'), 'Expired pending order asks to wait for bank confirmation before retry');

    await page.evaluate(() => localStorage.setItem('maharaCart', JSON.stringify([{ id: 'hoodie-black', size: 'M', qty: 3 }, { id: 'hoodie-black', size: 'L', qty: 1 }])));
    fixture.orderStatus = 'paid';
    await page.locator('[data-check-status]').click();
    await page.locator('[data-order-title]').filter({ hasText: 'Оплата получена' }).waitFor();
    const expected = [{ id: 'hoodie-black', size: 'M', qty: 2 }, { id: 'hoodie-black', size: 'L', qty: 1 }];
    await page.waitForFunction(items => JSON.stringify(JSON.parse(localStorage.getItem('maharaCart'))) === JSON.stringify(items), expected);
    equal(await page.evaluate(() => JSON.parse(localStorage.getItem('maharaCart'))), expected, 'Paid order removes only its purchased quantities');
    await page.reload();
    await page.locator('[data-order-title]').filter({ hasText: 'Оплата получена' }).waitFor();
    equal(await page.evaluate(() => JSON.parse(localStorage.getItem('maharaCart'))), expected, 'Reload never deducts purchased quantities twice');
    check(await page.locator('[data-payment-link]').isHidden(), 'Paid order cannot expose payment action');
    check(await page.locator('[data-qr-wrap]').isHidden(), 'Paid order hides QR code');

    const privateTab = await context.newPage();
    await privateTab.goto(`${origin}/checkout.html?order=local-order-1`);
    await privateTab.locator('[data-error]').waitFor({ state: 'visible' });
    check(await privateTab.locator('[data-order]').isHidden(), 'Order URL alone does not reveal an order without session token');
    check((await privateTab.locator('[data-error]').textContent()).includes('Найти оформленный заказ'), 'A missing session points the customer to order recovery');

    fixture.recoveryEnabled = true;
    await privateTab.reload(); await privateTab.locator('[data-recovery]').waitFor({ state: 'visible' });
    await privateTab.locator('[data-recovery] summary').click();
    await privateTab.locator('#recovery-number').fill('MH-TEST-1');
    await privateTab.locator('#recovery-email').fill('customer@example.ru');
    await privateTab.locator('[data-recovery-form] button').click();
    await privateTab.locator('[data-recovery-message]').filter({ hasText: recoveryMessage }).waitFor();
    const matchingMessage = await privateTab.locator('[data-recovery-message]').textContent();
    equal(fixture.recoveryRequests.at(-1), { number: 'MH-TEST-1', email: 'customer@example.ru' }, 'Recovery sends only the customer-entered order number and email');
    await privateTab.locator('#recovery-number').fill('UNKNOWN');
    await privateTab.locator('#recovery-email').fill('unknown@example.ru');
    await privateTab.locator('[data-recovery-form] button').click();
    await privateTab.locator('[data-recovery-message]').filter({ hasText: recoveryMessage }).waitFor();
    equal(await privateTab.locator('[data-recovery-message]').textContent(), matchingMessage, 'Matching and nonexistent recovery requests display the same generic response');
    check(await privateTab.locator('[data-order]').isHidden(), 'Submitting a recovery request never exposes an order');

    // A separate browser has no original order/cart settlement marker. Recovery
    // must retain even a local cart containing the same product and size.
    const recoveryContext = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
    const unrelatedCart = [{ id: 'hoodie-black', size: 'M', qty: 3 }, { id: 'hoodie-black', size: 'L', qty: 2 }];
    try {
      await recoveryContext.route('**/api/**', routeApi);
      await recoveryContext.addInitScript(items => { if (!localStorage.getItem('maharaCart')) localStorage.setItem('maharaCart', JSON.stringify(items)); }, unrelatedCart);
      const recoveryPage = await recoveryContext.newPage(); const recoveryErrors = [];
      recoveryPage.on('pageerror', error => recoveryErrors.push(error.message));
      fixture.recoveryDelay = 400;
      const exchangeRequest = recoveryPage.waitForRequest(request => new URL(request.url()).pathname === '/api/checkout/recovery/exchange');
      await recoveryPage.goto(`${origin}/checkout.html#access=${recoveryToken}`, { waitUntil: 'domcontentloaded' }); await exchangeRequest;
      check(!recoveryPage.url().includes('#') && !recoveryPage.url().includes(recoveryToken), 'Recovery removes the capability fragment immediately while exchange is pending');
      await recoveryPage.locator('[data-order-title]').filter({ hasText: 'Оплата получена' }).waitFor();
      equal(fixture.recoveryExchanges.at(-1).payload, { token: recoveryToken }, 'Recovery capability is exchanged only in a POST JSON body');
      check(!fixture.recoveryExchanges.at(-1).url.includes(recoveryToken) && !fixture.recoveryExchanges.at(-1).referrer.includes(recoveryToken), 'Capability stays out of HTTP URLs and referrer');
      equal(new URL(recoveryPage.url()).search, '?order=local-order-1', 'Successful recovery replaces the URL with the public order identifier');
      equal(await recoveryPage.evaluate(() => JSON.parse(sessionStorage.getItem('maharaCheckoutOrder:local-order-1'))), { token, items: [], cleared: true }, 'Recovered order bearer is stored only in this browser session with cart settlement disabled');
      equal(await recoveryPage.evaluate(() => JSON.parse(localStorage.getItem('maharaCart'))), unrelatedCart, 'Recovering a paid order never subtracts from a separate local cart');
      check(!(await recoveryPage.evaluate(() => JSON.stringify(Object.entries(localStorage)))).includes(token), 'The private order bearer is absent from persistent localStorage');
      check(await recoveryPage.locator('[data-payment-link]').isHidden(), 'Recovered paid order cannot offer a second payment');
      await recoveryPage.reload(); await recoveryPage.locator('[data-order-title]').filter({ hasText: 'Оплата получена' }).waitFor();
      equal(fixture.headers.at(-1), token, 'Reload uses the recovered session bearer to fetch order status');
      equal(await recoveryPage.evaluate(() => JSON.parse(localStorage.getItem('maharaCart'))), unrelatedCart, 'Reloading a recovered paid order also preserves its unrelated cart');
      fixture.recoveryDelay = 0;
      await recoveryPage.goto(`${origin}/checkout.html#access=${recoveryToken}`);
      await recoveryPage.locator('[data-error]').filter({ hasText: 'Ссылка недействительна или уже использована' }).waitFor();
      check(await recoveryPage.locator('[data-order]').isHidden(), 'A replayed one-use recovery link does not expose an order');
      check(!recoveryPage.url().includes(recoveryToken), 'A rejected replay is still removed from the address bar');
      // Distinct queries force document navigation after recovery removes #access;
      // a same-document hash change would not re-run page initialization.
      await recoveryPage.goto(`${origin}/checkout.html?recovery-case=expired#access=${expiredRecoveryToken}`);
      await recoveryPage.locator('[data-error]').filter({ hasText: 'Ссылка недействительна или уже использована' }).waitFor();
      equal(fixture.recoveryExchanges.at(-1).payload, { token: expiredRecoveryToken }, 'The expired-link case actually attempts its own exchange');
      check(await recoveryPage.locator('[data-order]').isHidden(), 'Expired recovery links show a recovery error without an order');
      const beforeMalformed = fixture.recoveryExchanges.length;
      await recoveryPage.goto(`${origin}/checkout.html?recovery-case=malformed#access=invalid`);
      await recoveryPage.locator('[data-error]').filter({ hasText: 'Ссылка на заказ недействительна' }).waitFor();
      equal(fixture.recoveryExchanges.length, beforeMalformed, 'Malformed recovery capabilities are rejected before a server request');
      equal(await recoveryPage.evaluate(() => JSON.parse(localStorage.getItem('maharaCart'))), unrelatedCart, 'Rejected and expired links do not touch the local cart');
      equal(recoveryErrors, [], 'Recovery interactions produce no browser runtime errors');
      equal(fixture.created.length, 3, 'Order lookup and link recovery never create another payment order');
    } finally { await recoveryContext.close(); }

    // The fake server accepts and reserves once, then loses the response. A new
    // shipping calculation and reload must replay the exact persisted POST,
    // never build another request from the latest quote or the changed cart.
    const pendingContext = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
    const pendingRequests = []; const acceptedRequests = new Map(); let reservedUnits = 0;
    let dropResponses = 2; let expiredQuoteId = null;
    try {
      await pendingContext.route('**/api/**', async route => {
        if (new URL(route.request().url()).pathname !== '/api/checkout/orders' || route.request().method() !== 'POST') return routeApi(route);
        const payload = route.request().postDataJSON(); pendingRequests.push(route.request().postData());
        let result = acceptedRequests.get(payload.requestId);
        if (!result) {
          if (payload.delivery.quoteId === expiredQuoteId) return route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ error: 'Расчёт доставки устарел.', orderNotCreated: true }) });
          reservedUnits += payload.items.reduce((sum, item) => sum + item.qty, 0);
          result = { ...order(), order: { ...order().order, status: 'pending' }, accessToken: token };
          acceptedRequests.set(payload.requestId, result);
        }
        if (dropResponses-- > 0) return route.abort('failed');
        return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify(result) });
      });
      const pendingPage = await pendingContext.newPage(); const pendingErrors = [];
      pendingPage.on('pageerror', error => pendingErrors.push(error.message));
      await pendingPage.goto(`${origin}/checkout.html`);
      await pendingPage.evaluate(items => localStorage.setItem('maharaCart', JSON.stringify(items)), cart);
      await pendingPage.reload(); await ready(pendingPage); await fill(pendingPage);
      await pendingPage.evaluate(() => {
        const original = Storage.prototype.setItem;
        window.restoreCheckoutStorage = () => { Storage.prototype.setItem = original; };
        Storage.prototype.setItem = function (key, value) { if (key === 'maharaCheckoutAttempt') throw new DOMException('Test quota failure', 'QuotaExceededError'); return original.call(this, key, value); };
      });
      await pendingPage.locator('[data-submit]').click();
      await pendingPage.locator('[data-error]').filter({ hasText: 'Заказ не отправлен' }).waitFor();
      equal(pendingRequests.length, 0, 'No order POST is sent when its complete recovery payload cannot be persisted');
      await pendingPage.evaluate(() => window.restoreCheckoutStorage());
      await pendingPage.locator('[data-submit]').click();
      await pendingPage.locator('[data-error]').filter({ hasText: 'Не удалось подтвердить создание заказа' }).waitFor();
      const saved = await pendingPage.evaluate(() => JSON.parse(sessionStorage.getItem('maharaCheckoutAttempt')));
      equal(saved.version, 1, 'The pending recovery record has a version');
      equal(saved.payload, pendingRequests[0], 'The complete immutable POST was saved before the response was lost');
      equal(acceptedRequests.size, 1, 'Lost response still represents exactly one accepted fake order');
      equal(reservedUnits, 1, 'Lost response creates only one fake stock reservation');
      check(await pendingPage.locator('[data-checkout]').isHidden(), 'Unknown creation result hides the editable form');
      const pendingBody = JSON.parse(saved.payload); expiredQuoteId = pendingBody.delivery.quoteId;
      const quotesBefore = fixture.shippingQuotes;
      // Simulate a late/recalculated quote without replacing the pending POST.
      await pendingPage.evaluate(() => document.querySelector('[data-calculate-shipping]').click());
      await pendingPage.waitForFunction(() => document.querySelector('[data-calculate-shipping]').textContent === 'Рассчитать ещё раз');
      equal(fixture.shippingQuotes, quotesBefore + 1, 'The regression actually obtains a distinct shipping quote after the lost response');
      await pendingPage.locator('[data-retry]').click();
      await pendingPage.locator('[data-error]').filter({ hasText: 'Не удалось подтвердить создание заказа' }).waitFor();
      equal(pendingRequests[1], pendingRequests[0], 'A replacement quote never changes the pending request ID or payload');
      const changedCart = [{ id: 'hoodie-black', size: 'L', qty: 2 }];
      await pendingPage.evaluate(items => localStorage.setItem('maharaCart', JSON.stringify(items)), changedCart);
      const quotesBeforeReload = fixture.shippingQuotes;
      await pendingPage.reload(); await pendingPage.locator('[data-order]').waitFor({ state: 'visible' });
      equal(pendingRequests[2], pendingRequests[0], 'Reload automatically retries the original payload even after quote expiry and cart edits');
      equal(fixture.shippingQuotes, quotesBeforeReload, 'Reload recovers the uncertain order before requesting any new delivery quote');
      equal(acceptedRequests.size, 1, 'Recalculation and reload do not create another fake order');
      equal(reservedUnits, 1, 'Recalculation and reload do not reserve the same stock twice');
      equal(await pendingPage.evaluate(() => sessionStorage.getItem('maharaCheckoutAttempt')), null, 'A confirmed order clears the pending request only after access is stored');
      equal((await pendingPage.evaluate(() => JSON.parse(sessionStorage.getItem('maharaCheckoutOrder:local-order-1')))).items, cart, 'Recovered order retains its original purchased items instead of the edited cart');
      equal(await pendingPage.evaluate(() => JSON.parse(localStorage.getItem('maharaCart'))), changedCart, 'Recovering a pending order leaves the current cart untouched');
      check(!pendingPage.url().includes(token), 'Recovered order bearer is absent from the URL');
      equal(pendingErrors, [], 'Uncertain checkout recovery has no browser runtime errors');
    } finally { await pendingContext.close(); }

    // A failed transport before acceptance can leave an expired quote. Only the
    // explicit server marker lets the customer rebuild that rejected attempt.
    const rejectedContext = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
    const rejectedRequests = []; let phase = 'drop'; let rejectionReservations = 0;
    try {
      await rejectedContext.route('**/api/**', async route => {
        if (new URL(route.request().url()).pathname !== '/api/checkout/orders' || route.request().method() !== 'POST') return routeApi(route);
        rejectedRequests.push(route.request().postDataJSON());
        if (phase === 'drop') { phase = 'existing-conflict'; return route.abort('failed'); }
        if (phase === 'existing-conflict') { phase = 'expired'; return route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ error: 'Статус заказа требует проверки магазина.' }) }); }
        if (phase === 'expired') { phase = 'accept'; return route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ error: 'Расчёт доставки устарел.', orderNotCreated: true }) }); }
        rejectionReservations += 1;
        return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ ...order(), order: { ...order().order, status: 'pending' }, accessToken: token }) });
      });
      const rejectedPage = await rejectedContext.newPage();
      await rejectedPage.goto(`${origin}/checkout.html`);
      await rejectedPage.evaluate(items => localStorage.setItem('maharaCart', JSON.stringify(items)), cart);
      await rejectedPage.reload(); await ready(rejectedPage); await fill(rejectedPage);
      await rejectedPage.locator('[data-submit]').click();
      await rejectedPage.locator('[data-error]').filter({ hasText: 'Не удалось подтвердить создание заказа' }).waitFor();
      await rejectedPage.locator('[data-retry]').click();
      await rejectedPage.locator('[data-error]').filter({ hasText: 'Статус заказа требует проверки магазина' }).waitFor();
      check(await rejectedPage.locator('[data-checkout]').isHidden(), 'A generic 409 cannot authorize a second order');
      check(await rejectedPage.evaluate(() => sessionStorage.getItem('maharaCheckoutAttempt') !== null), 'A generic 409 retains the complete uncertain request');
      await rejectedPage.reload(); await ready(rejectedPage);
      await rejectedPage.locator('[data-error]').filter({ hasText: 'Расчёт доставки устарел' }).waitFor();
      equal(rejectedRequests[2], rejectedRequests[0], 'Expired-quote rejection is obtained by replaying the original payload after reload');
      equal(await rejectedPage.evaluate(() => sessionStorage.getItem('maharaCheckoutAttempt')), null, 'A proved pre-INSERT rejection releases the attempt');
      equal(await rejectedPage.locator('#customer-name').inputValue(), 'Иван Тестов', 'Rejected recovery restores the entered name');
      equal(await rejectedPage.locator('#customer-email').inputValue(), 'customer@example.ru', 'Rejected recovery restores the entered email');
      equal(await rejectedPage.locator('#customer-phone').inputValue(), '+7 999 123-45-67', 'Rejected recovery restores the entered phone');
      equal(await rejectedPage.evaluate(() => JSON.parse(localStorage.getItem('maharaCart'))), cart, 'A proved rejection preserves the cart');
      equal(rejectionReservations, 0, 'No order was reserved for the rejected attempt');
      await fill(rejectedPage); await rejectedPage.locator('[data-submit]').click(); await rejectedPage.locator('[data-order]').waitFor({ state: 'visible' });
      check(rejectedRequests[3].requestId !== rejectedRequests[0].requestId, 'A new request ID is allowed only after the previous request was proved uncreated');
      check(rejectedRequests[3].delivery.quoteId !== rejectedRequests[0].delivery.quoteId, 'The accepted new attempt uses a fresh delivery quote');
      equal(rejectionReservations, 1, 'Rebuilding a proved rejected request creates one reservation');
    } finally { await rejectedContext.close(); }
    // Point-only delivery confirms a trusted PVZ for this cart without inventing
    // a free tariff or delivery date. Estimated delivery remains the default above.
    const pointOnlyContext = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
    const pointOnlyErrors = [];
    const previousShipping = { estimateEnabled: fixture.shippingEstimateEnabled, expiresIn: fixture.shippingExpiresIn, orderStatus: fixture.orderStatus };
    fixture.shippingEstimateEnabled = false; fixture.orderStatus = 'pending';
    try {
      await pointOnlyContext.route('**/api/**', routeApi);
      await pointOnlyContext.addInitScript(items => localStorage.setItem('maharaCart', JSON.stringify(items)), cart);
      const pointOnlyPage = await pointOnlyContext.newPage();
      pointOnlyPage.on('pageerror', error => pointOnlyErrors.push(error.message));
      await pointOnlyPage.goto(`${origin}/checkout.html`); await ready(pointOnlyPage); await fill(pointOnlyPage);
      const confirmPoint = pointOnlyPage.locator('[data-calculate-shipping]');
      const pointStatus = pointOnlyPage.locator('[data-shipping-status]');
      const payment = pointOnlyPage.locator('[data-submit]');
      const confirmed = async () => { await pointStatus.filter({ hasText: 'Пункт выдачи подтверждён' }).waitFor(); await pointOnlyPage.waitForFunction(() => !document.querySelector('[data-submit]').disabled); };
      await confirmed();
      equal(await pointOnlyPage.locator('[data-delivery-amount]').textContent(), 'Оплачивается СДЭК при получении', 'Point-only summary shows separate receiver payment instead of a zero price');
      equal(await pointOnlyPage.locator('[data-delivery-label]').textContent(), 'Доставка СДЭК', 'Point-only summary does not promise a price calculation');
      equal(await pointOnlyPage.locator('[data-total]').textContent(), await pointOnlyPage.locator('[data-subtotal]').textContent(), 'Only goods are charged now in point-only delivery');
      check(await pointOnlyPage.locator('[data-shipping-period]').isHidden() && await pointOnlyPage.locator('[data-shipping-disclaimer]').isHidden(), 'Point-only delivery hides estimate dates and price disclaimer');
      equal(await pointOnlyPage.locator('[data-shipping-period]').textContent(), '', 'Point-only response never renders null or invented delivery dates');
      check((await pointOnlyPage.locator('[data-shipping-address]').textContent()).includes(point.address), 'Point-only confirmation displays the trusted PVZ address');
      equal(await confirmPoint.textContent(), 'Подтвердить ПВЗ ещё раз', 'Point-only action confirms the PVZ instead of calculating delivery');
      check(!(await pointOnlyPage.locator('[data-delivery-note]').textContent()).includes('покажем заранее'), 'Point-only introduction makes no upfront price promise');
      for (const width of [390, 320]) {
        await pointOnlyPage.setViewportSize({ width, height: 844 });
        check(!(await pointOnlyPage.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)), `${width}px: separate receiver payment text has no horizontal overflow`);
      }

      for (const override of [{ amount: 0 }, { amount: 36000 }, { tariffCode: 136 }, { periodMin: 0 }, { periodMax: 3 }, { estimated: true }, { estimated: undefined }, { point: points[1] }, { expiresAt: Date.now() / 1000 - 1 }]) {
        fixture.shippingQuoteOverride = override;
        await confirmPoint.click(); await pointStatus.filter({ hasText: 'Не удалось подтвердить пункт выдачи' }).waitFor();
        check(await payment.isDisabled(), `Point-only rejects incoherent quote fields: ${Object.keys(override).join(', ')}`);
        equal(await pointOnlyPage.locator('[data-delivery-amount]').textContent(), 'Оплачивается СДЭК при получении', 'A rejected point-only response never displays a fake tariff');
      }
      fixture.shippingQuoteOverride = null;
      fixture.shippingQuoteFailure = true;
      await confirmPoint.click(); await pointStatus.filter({ hasText: 'Не удалось подтвердить пункт выдачи' }).waitFor();
      check(await payment.isDisabled(), 'A failed PVZ confirmation cannot create an order');
      fixture.shippingQuoteFailure = false;
      await confirmPoint.click(); await confirmed();

      fixture.quoteDelayByPoint.MSK2 = 400;
      await pointOnlyPage.locator('[data-point-trigger]').click(); await pointOnlyPage.locator('[data-point-code="MSK2"]').click();
      check(await payment.isDisabled(), 'Changing a point-only PVZ disables payment until confirmation');
      await pointOnlyPage.locator('[data-point-trigger]').click(); await pointOnlyPage.locator('[data-point-code="MSK1"]').click();
      await confirmed(); await delay(500);
      equal(await pointOnlyPage.locator('#shipping-point').inputValue(), 'MSK1', 'A stale point-only response cannot replace the latest PVZ');
      check((await pointOnlyPage.locator('[data-shipping-address]').textContent()).includes(point.address), 'A stale point-only response cannot restore its old address');
      fixture.quoteDelayByPoint.MSK2 = 0;

      fixture.shippingExpiresIn = .25;
      await confirmPoint.click(); await pointStatus.filter({ hasText: 'Подтверждение пункта выдачи устарело' }).waitFor();
      check(await payment.isDisabled(), 'Expired point-only confirmation disables payment');
      check(await pointOnlyPage.locator('[data-shipping-period]').isHidden(), 'Expiry does not reveal fabricated point-only dates');
      fixture.shippingExpiresIn = 1200;
      await confirmPoint.click(); await confirmed();

      const ordersBeforeCartChange = fixture.created.length;
      const changedCart = [{ ...cart[0], qty: 2 }];
      await pointOnlyPage.evaluate(items => localStorage.setItem('maharaCart', JSON.stringify(items)), changedCart);
      await payment.click(); await pointOnlyPage.locator('[data-error]').filter({ hasText: 'Корзина изменилась' }).waitFor();
      equal(fixture.created.length, ordersBeforeCartChange, 'Cart changes cannot use the old point-only confirmation to create an order');
      check(await payment.isDisabled(), 'Cart changes invalidate the trusted point-only confirmation');
      equal(await confirmPoint.textContent(), 'Подтвердить пункт выдачи', 'Cart changes request a fresh PVZ confirmation');
      await confirmPoint.click(); await confirmed();
      equal(fixture.shippingRequests.at(-1).items, changedCart, 'Renewed point-only confirmation is bound to the updated cart');
      await payment.click(); await pointOnlyPage.locator('[data-order]').waitFor({ state: 'visible' });
      const submitted = fixture.created.at(-1);
      equal(submitted.items, changedCart, 'Point-only order retains the newly confirmed cart');
      equal(submitted.expectedTotalAmount, fixture.unitAmount * 2, 'Point-only payment amount includes goods only');
      equal(submitted.delivery, { city: point.city, point: point.code, address: point.address, quoteId: `local-shipping-quote-${fixture.shippingQuotes}` }, 'Point-only order submits trusted PVZ, address and server confirmation ID');
      equal(pointOnlyErrors, [], 'Point-only delivery has no browser runtime errors');
    } finally {
      fixture.shippingEstimateEnabled = previousShipping.estimateEnabled; fixture.shippingExpiresIn = previousShipping.expiresIn; fixture.orderStatus = previousShipping.orderStatus;
      fixture.shippingQuoteOverride = null; fixture.shippingQuoteFailure = false; fixture.quoteDelayByPoint.MSK2 = 0;
      await pointOnlyContext.close();
    }
    equal(errors, [], 'No browser runtime errors');
    console.log(`PASS: ${checks} checkout browser checks (guest form, server quote, conflicts, idempotency, token privacy, bank links, paid-only settlement, recovery, mobile layout)`);
    console.log('Screenshots: .local/checkout-preview/desktop.png, mobile-390.png, mobile-320.png');
    await context.close();
  } finally {
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
}

async function phpSmoke() {
  const listener = net.createServer();
  await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
  const port = listener.address().port; await new Promise(resolve => listener.close(resolve));
  const origin = `http://127.0.0.1:${port}`;
  const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'mahara-checkout-browser-'));
  const php = process.env.PHP_BINARY || path.join(ROOT, '.runtime', 'php', process.platform === 'win32' ? 'php.exe' : 'php');
  const isolatedConfig = { root: ROOT, data_dir: temporary, origin, production: false, trust_proxy: false, session_ttl: 28800, login_window: 900, login_pair_limit: 8, login_ip_limit: 40, mail: { enabled: false }, payments: { enabled: false }, shipping: { enabled: false } };
  const router = path.join(temporary, 'router.php');
  // Exercise the real dispatcher without opening local configuration/secrets.
  fs.writeFileSync(router, `<?php\ndefine('MAHARA_LIBRARY_ONLY', true);\n$GLOBALS['mahara_app_test_config'] = json_decode(base64_decode('${Buffer.from(JSON.stringify(isolatedConfig)).toString('base64')}'), true, 512, JSON_THROW_ON_ERROR);\n$GLOBALS['mahara_payments_test_secrets'] = ['terminal_key' => '', 'password' => ''];\n$GLOBALS['mahara_shipping_test_secrets'] = ['account' => '', 'password' => ''];\n$GLOBALS['mahara_mail_test_secrets'] = ['username' => '', 'password' => ''];\nrequire base64_decode('${Buffer.from(path.join(ROOT, 'server', 'app.php')).toString('base64')}');\nmahara_dispatch();\n`);
  const child = spawn(php, ['-S', `127.0.0.1:${port}`, router], { cwd: ROOT, env: { ...process.env, APP_ENV: 'development', DATA_DIR: temporary, APP_ORIGIN: origin }, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
  let logs = ''; let processError; let browser;
  child.on('error', error => { processError = error; });
  child.stderr.on('data', value => { logs += value.toString(); }); child.stdout.on('data', value => { logs += value.toString(); });
  try {
    let ready = false;
    for (let attempt = 0; attempt < 60; attempt += 1) {
      if (processError) throw processError;
      try { ready = (await fetch(`${origin}/api/checkout/config`)).ok; } catch { /* PHP is still starting. */ }
      if (ready) break;
      await delay(100);
    }
    check(ready, `Isolated PHP server starts: ${logs}`);
    const config = await (await fetch(`${origin}/api/checkout/config`)).json();
    equal(config.enabled, false, 'Real backend keeps incomplete merchant configuration disabled');
    equal((await (await fetch(`${origin}/api/shipping/config`)).json()).enabled, false, 'Real backend keeps missing CDEK credentials disabled');
    equal((await (await fetch(`${origin}/api/checkout/recovery/config`)).json()).enabled, false, 'Real backend hides email recovery without explicitly configured mail');
    const protectedPage = await fetch(`${origin}/orders.html`, { redirect: 'manual' });
    equal(protectedPage.status, 302, 'Order administration redirects unauthenticated visitors');
    equal(protectedPage.headers.get('location'), '/admin/login', 'Order administration redirects only to local login');
    for (const endpoint of ['/server/config.local.php', '/server/payments.php', '/.local/preview-access.txt', '/data/mahara.sqlite', '/api/checkout/orders/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']) equal((await fetch(origin + endpoint, { redirect: 'manual' })).status, 404, `${endpoint}: private data is not exposed`);

    browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
    await context.addInitScript(items => localStorage.setItem('maharaCart', JSON.stringify(items)), cart);
    const page = await context.newPage(); const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${origin}/checkout.html`, { waitUntil: 'networkidle' });
    await page.locator('[data-loading]').waitFor({ state: 'hidden' });
    check(await page.locator('[data-submit]').isDisabled(), 'Real PHP checkout cannot initiate an unconfigured payment');
    check(await page.locator('#delivery-city').isDisabled(), 'Real PHP checkout does not fake city lookup without CDEK credentials');
    check(await page.locator('[data-availability]').isVisible(), 'Real PHP checkout explains availability');
    equal(errors, [], 'Real PHP checkout has no browser runtime errors');
    check(!logs.includes('PHP Fatal error') && !logs.includes('MAHARA backend error'), 'No PHP backend errors during checkout smoke');
    await page.screenshot({ path: path.join(ROOT, '.local', 'checkout-preview', 'php-disabled-mobile.png'), fullPage: true });
    await context.close();
    console.log('PASS: real PHP/SQLite checkout smoke (disabled merchant/CDEK, protected orders, private files, browser runtime)');
  } finally {
    if (browser) await browser.close();
    if (child.exitCode === null && !processError) await new Promise(resolve => { child.once('exit', resolve); child.kill(); });
    const actual = fs.realpathSync(temporary); const relative = path.relative(fs.realpathSync(os.tmpdir()), actual);
    assert.ok(relative && !relative.startsWith('..') && !path.isAbsolute(relative) && path.basename(actual).startsWith('mahara-checkout-browser-'), 'Cleanup remains in this isolated temporary directory');
    for (const name of ['mahara.sqlite', 'mahara.sqlite-wal', 'mahara.sqlite-shm', 'router.php']) { const file = path.join(actual, name); if (fs.existsSync(file)) fs.unlinkSync(file); }
    fs.rmdirSync(actual);
  }
}

run().then(() => process.argv.includes('--fake-only') ? undefined : phpSmoke()).catch(error => { console.error(error); process.exitCode = 1; });
