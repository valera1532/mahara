#!/usr/bin/env node
'use strict';

// Static storefront + isolated API fixtures. Never opens the working PHP server,
// reads credentials, edits inventory or creates orders/provider requests.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/valer/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const ROOT = path.resolve(__dirname, '..');
const defaults = JSON.parse(fs.readFileSync(path.join(ROOT, 'site-defaults.json'), 'utf8'));
const fixture = { content: structuredClone(defaults), failAvailability: false, maxQty: 20, blockedVariant: null, calls: [] };
let checks = 0;
const check = (condition, message) => { checks++; assert.ok(condition, message); };
const equal = (actual, expected, message) => { checks++; assert.deepEqual(actual, expected, message); };
const cart = page => page.evaluate(() => JSON.parse(localStorage.getItem('maharaCart') || '[]'));
const card = (page, id = 'hoodie-black') => page.locator(`.product-card[data-product-id="${id}"]`);
const add = (page, id = 'hoodie-black') => card(page, id).locator('[data-add-cart]');
const waitQty = (page, id, size, qty) => page.waitForFunction(({ id, size, qty }) => JSON.parse(localStorage.getItem('maharaCart') || '[]').find(item => item.id === id && item.size === size)?.qty === qty, { id, size, qty });
async function ready(page) { await page.locator('body.is-ready').waitFor(); await add(page).waitFor(); await page.waitForFunction(() => document.querySelector('[data-stock-status]')?.textContent === 'В наличии'); }
async function openCart(page) { await page.locator('.cart-trigger').click(); await page.locator('[data-cart-drawer][aria-hidden="false"]').waitFor(); }

async function run() {
  const server = http.createServer((request, response) => {
    const pathname = new URL(request.url, 'http://localhost').pathname;
    const relative = decodeURIComponent(pathname).replace(/^\/+/, '') || 'index.html';
    const file = path.resolve(ROOT, relative);
    const allowed = /^(?:index|catalog|product-[a-z0-9-]+)\.html$/.test(relative) || ['script.js', 'catalog-data.js', 'cms-data.js', 'styles.css', 'legal-documents.css'].includes(relative) || /^assets\/[a-zA-Z0-9._/-]+$/.test(relative);
    if (!allowed || !file.startsWith(ROOT + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { response.writeHead(404); response.end(); return; }
    const mime = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.webp': 'image/webp', '.svg': 'image/svg+xml' };
    response.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' }); response.end(fs.readFileSync(file));
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const origin = `http://127.0.0.1:${server.address().port}`;
  let browser;
  try {
    browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    const errors = []; context.on('page', page => page.on('pageerror', error => errors.push(error.message)));
    const routeApi = async route => {
      const request = route.request(); const pathname = new URL(request.url()).pathname; fixture.calls.push([request.method(), pathname]);
      if (pathname === '/api/content') return route.fulfill({ json: { ...fixture.content, revision: 1 } });
      if (pathname === '/api/catalog/availability') {
        if (fixture.failAvailability) return route.fulfill({ status: 503, json: { error: 'Fixture unavailable' } });
        return route.fulfill({ json: { products: fixture.content.products.map(product => ({ id: product.id, variants: product.sizes.map(size => {
          const blocked = fixture.blockedVariant?.id === product.id && fixture.blockedVariant.size === size;
          const status = blocked || product.id === 'hoodie-black' && size === 'S' ? 'out' : product.id === 'hoodie-black' && size === 'XL' ? 'untracked' : 'available';
          return { size, status, maxQty: status === 'available' ? fixture.maxQty : 0 };
        }) })) } });
      }
      return route.fulfill({ status: 503, json: { error: 'Not permitted in storefront fixture' } });
    };
    await context.route('**/api/**', routeApi);
    const page = await context.newPage(); await page.goto(`${origin}/catalog.html`); await ready(page);
    check(await card(page).locator('[data-size-choice="S"]').isDisabled(), 'Known sold-out size is disabled');
    check(await card(page).locator('[data-size-choice="XL"]').isDisabled(), 'Untracked size cannot be advertised as available');
    check(await card(page).locator('[data-size-choice="M"]').isEnabled(), 'Confirmed available size remains usable');
    check(await page.locator('[data-favorites-filter]').isVisible(), 'Catalog exposes a usable favorites view');
    await add(page).click(); await waitQty(page, 'hoodie-black', 'M', 1);
    await openCart(page);
    const row = page.locator('[data-cart-key="hoodie-black:M"]');
    await row.locator('[data-cart-delta="1"]').click(); await waitQty(page, 'hoodie-black', 'M', 2);
    equal((await cart(page))[0].qty, 2, 'Quantity plus updates the saved cart');
    await row.locator('[data-cart-delta="-1"]').click(); await waitQty(page, 'hoodie-black', 'M', 1);
    check(await row.locator('[data-cart-delta="-1"]').isDisabled(), 'Quantity cannot decrease below one');
    await page.locator('.cart-close').click();

    const other = await context.newPage(); await other.goto(`${origin}/catalog.html`); await ready(other);
    await other.evaluate(() => localStorage.setItem('maharaCart', '[]'));
    await page.waitForFunction(() => document.querySelector('[data-cart-count]').textContent === '0');
    await add(page, 'hoodie-green').click(); await waitQty(page, 'hoodie-green', 'M', 1);
    equal(await cart(page), [{ id: 'hoodie-green', size: 'M', qty: 1 }], 'Old catalog tab cannot resurrect quantities removed after payment');
    await other.evaluate(() => localStorage.setItem('maharaCart', '[]'));
    await Promise.all([add(page).click(), add(other).click()]);
    await waitQty(page, 'hoodie-black', 'M', 2);
    equal((await cart(page))[0].qty, 2, 'Shared cart lock preserves simultaneous adds from two tabs');
    await page.waitForFunction(() => document.querySelector('[data-cart-count]').textContent === '2');
    await other.evaluate(() => localStorage.setItem('maharaCart', JSON.stringify([{ id: 'hoodie-green', size: 'M', qty: 1 }])));
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
    await page.waitForFunction(() => document.querySelector('[data-cart-count]').textContent === '1');
    check(true, 'Restoring a page resynchronizes the saved cart');

    await other.evaluate(() => localStorage.setItem('maharaCart', JSON.stringify([{ id: 'removed-product', size: 'M', qty: 1 }, { id: 'hoodie-black', size: 'M', qty: 1 }])));
    await openCart(page);
    const missing = page.locator('[data-cart-key="removed-product:M"]');
    check((await missing.textContent()).includes('Товар удалён из каталога'), 'Removed product remains visible with honest guidance');
    check(await page.locator('[data-checkout]').isDisabled(), 'Removed product cannot silently enter checkout');
    await missing.locator('[data-cart-remove]').click(); await page.waitForFunction(() => !JSON.parse(localStorage.getItem('maharaCart')).some(item => item.id === 'removed-product'));
    check(await page.locator('[data-checkout]').isEnabled(), 'Removing unavailable product restores a valid checkout');
    await page.locator('.cart-close').click();

    await page.evaluate(() => localStorage.setItem('maharaCart', JSON.stringify([null, { id: 'hoodie-black', size: 'M', qty: 999 }, { id: 'bad', size: '<img>', qty: 1 }, { id: 'hoodie-black', size: 'M', qty: 2 }])));
    await page.reload(); await ready(page);
    await waitQty(page, 'hoodie-black', 'M', 20);
    equal(await cart(page), [{ id: 'hoodie-black', size: 'M', qty: 20 }], 'Malformed cart is reconciled and duplicate quantity is capped at backend limit');
    await openCart(page);
    check(await page.locator('[data-cart-delta="1"]').isDisabled(), 'Twenty is the consistent maximum quantity');
    await page.locator('.cart-close').click();
    await add(page).click();
    equal((await cart(page))[0].qty, 20, 'Adding the same size cannot exceed twenty');

    fixture.maxQty = 2; await page.reload(); await ready(page); await openCart(page);
    check((await page.locator('.cart-item__warning').textContent()).includes('Доступно только 2'), 'Lower current stock explains an oversized existing quantity');
    check(await page.locator('[data-checkout]').isDisabled(), 'Cart exceeding current stock must be corrected before checkout');
    await page.locator('.cart-close').click(); fixture.maxQty = 20;

    await card(page).locator('[data-favorite]').click();
    await page.waitForFunction(() => JSON.parse(localStorage.getItem('maharaFavorites') || '[]').includes('hoodie-black'));
    await page.reload(); await ready(page);
    equal(await card(page).locator('[data-favorite]').getAttribute('aria-pressed'), 'true', 'Favorite persists after reload');
    await other.waitForFunction(() => document.querySelector('.product-card[data-product-id="hoodie-black"] [data-favorite]').getAttribute('aria-pressed') === 'true');
    check(true, 'Favorites synchronize across tabs');
    await page.locator('[data-favorites-filter]').click();
    equal(await page.locator('.product-card').count(), 1, 'Favorites filter shows only saved products');
    await card(page).locator('[data-favorite]').click();
    await page.locator('[data-catalog-empty].is-visible').waitFor();
    equal(await page.locator('.product-card').count(), 0, 'Removing the last favorite presents a real empty state');
    check(await page.locator('[data-favorites-filter]').evaluate(node => node === document.activeElement), 'Removing a favorite restores keyboard focus to the filter');
    await page.locator('[data-filter-reset]').first().click();
    check(await page.locator('.product-card').count() > 1, 'Reset exits favorites view');
    await page.goto(`${origin}/catalog.html?favorites=1`); await readyWithoutCards(page);
    equal(await page.locator('[data-favorites-filter]').getAttribute('aria-pressed'), 'true', 'Favorites link opens the saved-products view');

    fixture.failAvailability = true; await page.goto(`${origin}/catalog.html`);
    await page.locator('[data-stock-retry]').waitFor({ state: 'visible' });
    check(await add(page).isDisabled(), 'API failure never invents stock availability');
    check(await page.locator('.product-card').count() > 1, 'API failure keeps catalog browsing available');
    equal((await cart(page))[0].qty, 20, 'API failure does not erase an existing cart');
    fixture.failAvailability = false; await page.locator('[data-stock-retry]').click(); await ready(page);
    check(await add(page).isEnabled(), 'Retry restores stock controls after a transient error');

    await card(page).hover(); await card(page).locator('[data-quick-open]').click();
    check(await page.locator('[data-quick-facts]').isHidden(), 'Quick view does not invent missing factual product data');
    await page.locator('.quick-view__close').click();
    await page.evaluate(() => { const product = window.MaharaCatalog.products.find(item => item.id === 'hoodie-black'); product.composition = '100% хлопок'; product.care = '<img src=x onerror="window.factsInjected=true">'; product.measurements = 'M: ширина 60 см'; });
    await card(page).hover(); await card(page).locator('[data-quick-open]').click();
    check((await page.locator('[data-quick-facts]').textContent()).includes('100% хлопок'), 'Quick view displays factual fields when supplied');
    equal(await page.locator('[data-quick-facts] img').count(), 0, 'Factual fields are rendered as text, never HTML');
    equal(await page.evaluate(() => window.factsInjected), undefined, 'Factual text cannot execute scripts');
    check(await page.locator('[data-quick-size="S"]').isDisabled(), 'Quick view also blocks unavailable sizes');
    await page.locator('.quick-view__close').click();

    const savedFavorites = await page.evaluate(() => localStorage.getItem('maharaFavorites'));
    await page.evaluate(() => { window.originalStorageWrite = Storage.prototype.setItem; Storage.prototype.setItem = function () { throw new DOMException('Fixture storage blocked', 'QuotaExceededError'); }; });
    await card(page).locator('[data-favorite]').click();
    await page.locator('[data-toast]').filter({ hasText: 'Не удалось сохранить избранное' }).waitFor();
    equal(await page.evaluate(() => localStorage.getItem('maharaFavorites')), savedFavorites, 'Failed favorites persistence does not claim a saved choice');
    await add(page, 'hoodie-green').click();
    await page.locator('[data-toast]').filter({ hasText: 'Не удалось сохранить корзину' }).waitFor();
    equal((await cart(page))[0].qty, 20, 'Blocked storage cannot overwrite the saved cart');
    await page.evaluate(() => { Storage.prototype.setItem = window.originalStorageWrite; });

    await page.goto(`${origin}/index.html`); await ready(page);
    equal(await page.locator('[data-newsletter-form]').count(), 0, 'Unimplemented newsletter form does not collect email');
    equal(await page.locator('[data-newsletter-social] a').count(), 2, 'Newsletter area offers real social destinations');
    check(!(await page.locator('body').textContent()).includes('по миру'), 'Storefront does not promise unsupported worldwide shipping');

    console.log('Storefront: chosen sizes survive returning to a refreshed catalog/product page');
    for (const [label, route, selector, titleSelector] of [
      ['Catalog', 'catalog.html', '.product-card[data-product-id="hoodie-green"]', 'h3 a'],
      ['Product page', 'product-hoodie-green.html', '[data-product-detail]', '#product-title']
    ]) {
      const selectionContext = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
      await selectionContext.route('**/api/**', routeApi);
      const selectionPage = await selectionContext.newPage();
      selectionPage.on('pageerror', error => errors.push(error.message));
      try {
        await selectionPage.goto(`${origin}/${route}`);
        await selectionPage.locator('body.is-ready').waitFor();
        const product = fixture.content.products.find(item => item.id === 'hoodie-green');
        const productNode = selectionPage.locator(selector);
        await selectionPage.waitForFunction(selector => !document.querySelector(`${selector} [data-size-choice="XL"]`)?.disabled, selector);
        await productNode.locator('[data-size-choice="XL"]').click();
        const refresh = async suffix => {
          product.title = `Худи MAHARA Green — ${label} ${suffix}`;
          const stockResponse = selectionPage.waitForResponse(response => new URL(response.url()).pathname === '/api/catalog/availability');
          await selectionPage.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
          await productNode.locator(titleSelector).filter({ hasText: product.title }).waitFor();
          await stockResponse;
          await selectionPage.waitForFunction(({ selector, unavailable }) => document.querySelector(`${selector} [data-add-cart]`)?.disabled === unavailable, { selector, unavailable: Boolean(fixture.blockedVariant) });
        };
        await refresh('updated');
        equal(await productNode.locator('[data-size-choice].is-active').getAttribute('data-size-choice'), 'XL', `${label}: CMS refresh preserves the chosen XL size`);
        equal(await productNode.locator('[data-size-choice][aria-pressed="true"]').count(), 1, `${label}: restored size remains the single accessible selection`);
        await productNode.locator('[data-add-cart]').click();
        await waitQty(selectionPage, 'hoodie-green', 'XL', 1);
        equal(await cart(selectionPage), [{ id: 'hoodie-green', size: 'XL', qty: 1 }], `${label}: adding after refresh puts XL into the cart`);

        fixture.blockedVariant = { id: 'hoodie-green', size: 'XL' };
        await refresh('sold out');
        check(await productNode.locator('[data-size-choice="XL"]').isDisabled(), `${label}: preserved XL is disabled when availability changes`);
        check(await productNode.locator('[data-add-cart]').isDisabled(), `${label}: preserving a choice cannot bypass stock restrictions`);
        equal(await productNode.locator('[data-size-choice].is-active').getAttribute('data-size-choice'), 'XL', `${label}: sold-out selection does not silently change the buyer's size`);

        fixture.blockedVariant = null;
        product.sizes = product.sizes.filter(size => size !== 'XL');
        await refresh('size removed');
        equal(await productNode.locator('[data-size-choice="XL"]').count(), 0, `${label}: removed size is not restored into published controls`);
        check(product.sizes.includes(await productNode.locator('[data-size-choice].is-active').getAttribute('data-size-choice')), `${label}: removing XL selects a size that still exists`);
        equal(await cart(selectionPage), [{ id: 'hoodie-green', size: 'XL', qty: 1 }], `${label}: refreshing sizes does not rewrite an existing cart item`);
      } finally {
        fixture.content = structuredClone(defaults);
        fixture.blockedVariant = null;
        await selectionContext.close();
      }
    }

    const mobile = await browser.newContext({ viewport: { width: 320, height: 740 }, isMobile: true, hasTouch: true, reducedMotion: 'reduce' });
    await mobile.route('**/api/**', routeApi);
    const touch = await mobile.newPage(); touch.on('pageerror', error => errors.push(error.message));
    await touch.goto(`${origin}/catalog.html`); await ready(touch); await add(touch).tap(); await waitQty(touch, 'hoodie-black', 'M', 1); await touch.locator('.cart-trigger').tap();
    await touch.locator('[data-cart-delta="1"]').tap(); await waitQty(touch, 'hoodie-black', 'M', 2);
    check(await touch.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Touch cart controls do not cause mobile horizontal overflow');
    await touch.locator('.cart-close').tap(); await touch.locator('[data-favorites-filter]').tap();
    check(await touch.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Favorites view fits the 320px mobile viewport');
    equal(errors, [], 'Storefront changes produce no browser runtime errors');
    check(fixture.calls.every(([method, pathname]) => method === 'GET' && ['/api/content', '/api/catalog/availability'].includes(pathname)), 'All fixture calls are read-only; no stock, orders or provider endpoints');
    await mobile.close(); await context.close();
    console.log(`PASS: ${checks} storefront checks (cart synchronization, bounded quantities, selected-size refresh, unavailable products, favorites, stock, honest subscription, mobile touch)`);
  } finally { if (browser) await browser.close(); await new Promise(resolve => server.close(resolve)); }
}
async function readyWithoutCards(page) { await page.locator('body.is-ready').waitFor(); await page.locator('[data-favorites-filter]').waitFor(); }
run().catch(error => { console.error(error.stack || error); process.exitCode = 1; });
