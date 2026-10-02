#!/usr/bin/env node
'use strict';

// Exercise the browser-only CMS on a temporary local server and browser profile.
// No production content, user browser storage or external service is touched.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/valer/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const root = path.resolve(__dirname, '..');
const mime = {
  '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8', '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml', '.webp': 'image/webp', '.png': 'image/png',
  '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg'
};
let checks = 0;
function check(condition, message) {
  checks += 1;
  assert.ok(condition, message);
}
const normalizedText = value => String(value).replace(/\s+/g, ' ').trim();
const formatPrice = value => normalizedText(`${new Intl.NumberFormat('ru-RU').format(value)} ₽`);

const server = http.createServer((request, response) => {
  try {
    const route = decodeURIComponent(new URL(request.url, 'http://localhost').pathname);
    const file = path.resolve(root, `.${route === '/' ? '/index.html' : route}`);
    if (!file.startsWith(`${root}${path.sep}`)) return response.writeHead(400).end('Invalid path');
    if (!fs.existsSync(file) || !fs.statSync(file).isFile()) return response.writeHead(404).end('Not found');
    response.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream' });
    fs.createReadStream(file).pipe(response);
  } catch { response.writeHead(400).end('Invalid path'); }
});

async function expectText(page, selector, expected, message = selector) {
  await page.waitForFunction(({ selector, expected }) => {
    const node = document.querySelector(selector);
    return node && node.textContent.replace(/\s+/g, ' ').trim() === expected;
  }, { selector, expected: normalizedText(expected) });
  check(normalizedText(await page.locator(selector).textContent()) === normalizedText(expected), message);
}

async function expectImage(page, selector, prefix) {
  await page.locator(selector).scrollIntoViewIfNeeded();
  await page.waitForFunction(({ selector, prefix }) => {
    const image = document.querySelector(selector);
    return image?.src.startsWith(prefix) && image.complete && image.naturalWidth > 0;
  }, { selector, prefix });
  check((await page.locator(selector).getAttribute('src')).startsWith(prefix), `${selector}: saved image must be rendered`);
}

async function prepareScreenshot(page) {
  await page.evaluate(() => {
    document.activeElement?.blur();
    window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
  });
  await page.waitForFunction(() => window.scrollY === 0);
}

async function noOverflow(page, label) {
  const result = await page.evaluate(() => ({
    viewport: innerWidth,
    documentWidth: document.documentElement.scrollWidth,
    offenders: [...document.querySelectorAll('body *')].filter(node => {
      const rect = node.getBoundingClientRect();
      return rect.width > 0 && (rect.right > innerWidth + 1 || rect.left < -1) && getComputedStyle(node).position !== 'fixed';
    }).slice(0, 8).map(node => `${node.tagName}.${node.className}`)
  }));
  check(result.documentWidth <= result.viewport + 1, `${label}: horizontal overflow ${JSON.stringify(result)}`);
}

async function editorReady(page) {
  await page.locator('[data-cms-field="title"]').waitFor();
  await page.locator('[data-cms-workspace][aria-busy="false"]').waitFor();
}

async function selectEditor(page, selection) {
  await page.locator(`[data-cms-select="${selection}"]`).click();
  await page.locator(`[data-cms-select="${selection}"][aria-pressed="true"]`).waitFor();
}

async function saveEditor(page) {
  const save = page.locator('[data-cms-save]');
  check(await save.isEnabled(), 'Save must be enabled after a valid edit');
  await save.click();
  await page.locator('[data-cms-status][data-state="saved"]').waitFor();
  check(await save.isDisabled(), 'Save must be disabled after successful persistence');
}

async function noInjectedMarkup(page, label) {
  check(await page.locator('#cms-xss-probe').count() === 0, `${label}: text was interpreted as markup`);
  check(await page.evaluate(() => !window.__cmsXSS), `${label}: injected event/script executed`);
}

async function resetEditor(page) {
  await page.locator('[data-cms-reset]').click();
  await page.locator('[data-cms-reset-confirm]').click();
  await page.locator('[data-cms-status][data-state="saved"]').waitFor();
}

async function run() {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const origin = `http://127.0.0.1:${server.address().port}`;
  const browser = await chromium.launch({
    executablePath: process.env.BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    headless: true
  });
  const errors = [];
  const failedResponses = [];
  const externalRequests = [];
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    await context.route('**/*', route => {
      const url = new URL(route.request().url());
      if (url.origin === origin || ['data:', 'blob:'].includes(url.protocol)) return route.continue();
      externalRequests.push(url.href);
      return route.abort();
    });
    context.on('page', page => {
      page.on('pageerror', error => errors.push(`${page.url()}: ${error.message}`));
      page.on('response', response => {
        if (response.status() >= 400) failedResponses.push(`${response.status()} ${response.url()}`);
      });
    });
    const admin = await context.newPage();
    await admin.goto(`${origin}/admin.html`, { waitUntil: 'load' });
    await editorReady(admin);
    check((await admin.locator('meta[name="robots"]').getAttribute('content')).includes('noindex'), 'Admin must have noindex');
    const defaults = await admin.evaluate(() => window.MaharaCMS.getDefaults());
    const original = defaults.products[0];
    check(original.id === 'hoodie-black', 'Audit expects the first featured hoodie');
    const card = `[data-product-grid] [data-product-id="${original.id}"]`;

    const home = await context.newPage();
    await home.goto(`${origin}/index.html`, { waitUntil: 'load' });
    await expectText(home, '.hero-title__main', defaults.sections.hero.title);
    await expectText(home, `${card} .product-card__price`, formatPrice(original.price));

    console.log('CMS: edit and Save through the UI, persist after reload, update another tab');
    const heroTitle = 'MAHARA / ДЕМО';
    const heroDescription = 'Первая строка демо\nВторая строка демо';
    await admin.locator('[data-cms-field="title"]').fill(heroTitle);
    await admin.locator('[data-cms-field="description"]').fill(heroDescription);
    check((await admin.locator('[data-cms-preview]').textContent()).includes(heroTitle), 'Preview must show unsaved hero text');
    await saveEditor(admin);
    await expectText(home, '.hero-title__main', heroTitle, 'BroadcastChannel must update an already open home tab');
    await expectText(home, '.hero__copy', heroDescription);
    await admin.reload({ waitUntil: 'load' });
    await editorReady(admin);
    check(await admin.locator('[data-cms-field="title"]').inputValue() === heroTitle, 'Hero edit must survive editor reload');

    const productTitle = 'Худи MAHARA / Демо';
    const productDescription = 'Проверенное описание товара после сохранения.';
    const demoPrice = 12345;
    await selectEditor(admin, `product:${original.id}`);
    await admin.locator('[data-cms-field="title"]').fill(productTitle);
    await admin.locator('[data-cms-field="description"]').fill(productDescription);
    await admin.locator('[data-cms-field="price"]').fill(String(demoPrice));
    await saveEditor(admin);
    await expectText(home, `${card} h3 a`, productTitle);
    await expectText(home, `${card} .product-card__price`, formatPrice(demoPrice));
    await admin.reload({ waitUntil: 'load' });
    await editorReady(admin);
    await selectEditor(admin, `product:${original.id}`);
    check(await admin.locator('[data-cms-field="price"]').inputValue() === String(demoPrice), 'Product price must survive reload');

    const catalog = await context.newPage();
    await catalog.goto(`${origin}/catalog.html`, { waitUntil: 'load' });
    await expectText(catalog, `${card} h3 a`, productTitle);
    await expectText(catalog, `${card} .product-card__price`, formatPrice(demoPrice));
    await catalog.locator('.catalog-tabs [data-filter-category="tee"]').click();
    check(await catalog.locator(card).count() === 0, 'Hoodie must be excluded by tee filter');
    await catalog.locator('.catalog-tabs [data-filter-category="all"]').click();
    await expectText(catalog, `${card} .product-card__price`, formatPrice(demoPrice), 'Returning to all products must retain saved price');
    await catalog.locator('[data-sort]').selectOption('price-asc');
    await expectText(catalog, `${card} .product-card__price`, formatPrice(demoPrice));
    await catalog.locator('[data-filter-reset]').first().click();
    await expectText(catalog, `${card} .product-card__price`, formatPrice(demoPrice), 'Resetting filters must not restore stale static HTML');
    await catalog.locator(`${card} [data-quick-open]`).click();
    await expectText(catalog, '[data-quick-title]', productTitle);
    await expectText(catalog, '[data-quick-description]', productDescription);
    await expectText(catalog, '[data-quick-price]', formatPrice(demoPrice));
    await catalog.locator('[data-quick-size="XL"]').click();
    await admin.evaluate(async ({ id, price }) => {
      const content = await window.MaharaCMS.load();
      content.products.find(product => product.id === id).price = price;
      await window.MaharaCMS.save(content);
    }, { id: original.id, price: demoPrice + 111 });
    await expectText(catalog, '[data-quick-price]', formatPrice(demoPrice + 111), 'An open quick view must receive the cross-tab price change');
    check(await catalog.locator('[data-quick-size].is-active').textContent() === 'XL', 'Open quick view must preserve a still available selected size');
    await admin.evaluate(async id => {
      const content = await window.MaharaCMS.load();
      content.products.find(product => product.id === id).sizes = ['XS'];
      await window.MaharaCMS.save(content);
    }, original.id);
    await expectText(catalog, '[data-quick-size].is-active', 'XS', 'Removed quick-view size must fall back to an available size');
    check(await catalog.locator('[data-quick-view]').getAttribute('aria-hidden') === 'false', 'Content refresh must leave the quick view open');
    await admin.evaluate(async ({ id, price, sizes }) => {
      const content = await window.MaharaCMS.load();
      Object.assign(content.products.find(product => product.id === id), { price, sizes });
      await window.MaharaCMS.save(content);
    }, { id: original.id, price: demoPrice, sizes: original.sizes });
    await expectText(catalog, '[data-quick-price]', formatPrice(demoPrice));
    await catalog.keyboard.press('Escape');

    const detail = await context.newPage();
    await detail.goto(`${origin}/${original.url}`, { waitUntil: 'load' });
    await expectText(detail, '#product-title', productTitle);
    await expectText(detail, '.product-detail__description', productDescription);
    await expectText(detail, '.product-detail__price', formatPrice(demoPrice));
    await detail.locator('[data-product-detail] [data-add-cart]').click();
    await detail.locator('.cart-trigger').click();
    await expectText(detail, '[data-cart-total]', formatPrice(demoPrice));
    await expectText(detail, '[data-cart-items] .cart-item__copy strong', productTitle);
    check(await detail.locator('[data-cart-count]').textContent() === '1', 'A single click must add exactly one item');
    await detail.keyboard.press('Escape');

    console.log('CMS: upload image, validation errors and safe literal text');
    const upload = admin.locator('[data-cms-upload="image"]');
    await upload.setInputFiles({ name: 'wrong.txt', mimeType: 'text/plain', buffer: Buffer.from('not an image') });
    await admin.waitForFunction(() => document.querySelector('[data-cms-field-error="image"]')?.textContent.trim());
    check((await admin.locator('[data-cms-field-error="image"]').textContent()).trim().length > 0, 'Unsupported upload must explain the error');
    await upload.setInputFiles({ name: 'too-big.png', mimeType: 'image/png', buffer: Buffer.alloc(11 * 1024 * 1024) });
    await admin.waitForFunction(() => document.querySelector('[data-cms-field-error="image"]')?.textContent.trim());
    check((await admin.locator('[data-cms-field-error="image"]').textContent()).trim().length > 0, 'Oversized upload must explain the error');
    await upload.setInputFiles({ name: 'broken.png', mimeType: 'image/png', buffer: Buffer.from('invalid PNG contents') });
    await admin.waitForFunction(() => document.querySelector('[data-cms-status]')?.dataset.state !== 'busy' && document.querySelector('[data-cms-field-error="image"]')?.textContent.trim());
    check((await admin.locator('[data-cms-field-error="image"]').textContent()).trim().length > 0, 'An image that cannot decode must explain the error');
    await upload.setInputFiles(path.join(root, 'assets/images/hero-woman.webp'));
    await admin.waitForFunction(() => !document.querySelector('[data-cms-field-error="image"]')?.textContent.trim() && !document.querySelector('[data-cms-save]').disabled);
    await saveEditor(admin);
    const uploaded = await admin.evaluate(async id => (await window.MaharaCMS.load()).products.find(product => product.id === id).image, original.id);
    check(uploaded.startsWith('data:image/'), 'Upload must be persisted as image data');
    await expectImage(home, `${card} .product-card__image`, uploaded);
    await expectImage(detail, '.product-detail__gallery img:first-child', uploaded);
    await detail.reload({ waitUntil: 'load' });
    await expectImage(detail, '.product-detail__gallery img:first-child', uploaded);

    const maliciousTitle = '<img id="cms-xss-probe" src=x onerror="window.__cmsXSS=true"> Текст';
    const maliciousDescription = '<script>window.__cmsXSS=true</script> Описание';
    await admin.locator('[data-cms-field="title"]').fill(maliciousTitle);
    await admin.locator('[data-cms-field="description"]').fill(maliciousDescription);
    await saveEditor(admin);
    await expectText(home, `${card} h3 a`, maliciousTitle);
    await expectText(catalog, `${card} h3 a`, maliciousTitle);
    await expectText(detail, '#product-title', maliciousTitle);
    await expectText(detail, '.product-detail__description', maliciousDescription);
    await detail.locator('.cart-trigger').click();
    await expectText(detail, '[data-cart-items] .cart-item__copy strong', maliciousTitle);
    for (const [page, label] of [[admin, 'Editor/preview'], [home, 'Home card'], [catalog, 'Catalog card'], [detail, 'Detail/cart']]) await noInjectedMarkup(page, label);
    await detail.keyboard.press('Escape');

    // Cover normalization and large multiline data without coupling to every editor control.
    await admin.evaluate(async id => {
      const content = await window.MaharaCMS.load();
      content.sections.hero.title = '<img id="cms-xss-probe" src=x onerror="window.__cmsXSS=true"> HERO';
      content.products.find(product => product.id === id).sizes = ['XS', 'XL'];
      await window.MaharaCMS.save(content);
    }, original.id);
    await expectText(home, '.hero-title__main', '<img id="cms-xss-probe" src=x onerror="window.__cmsXSS=true"> HERO');
    await noInjectedMarkup(home, 'Hero');
    await detail.waitForFunction(() => document.querySelectorAll('[data-product-detail] [data-size-choice]').length === 2);
    check(await detail.locator('[data-product-detail] [data-size-choice]').allTextContents().then(sizes => sizes.join(',')) === 'XS,XL', 'Saved sizes must reach the detail controls');
    await detail.locator('[data-product-detail] [data-add-cart]').click();
    check(await detail.locator('[data-cart-count]').textContent() === '2', 'Live CMS refresh must not duplicate add-to-cart handlers');

    console.log('CMS: reset via UI and verify default content in all open tabs');
    // Refresh the editor after the direct API change so its displayed draft matches storage.
    await admin.reload({ waitUntil: 'load' });
    await editorReady(admin);
    await resetEditor(admin);
    check(JSON.stringify(await admin.evaluate(() => window.MaharaCMS.load())) === JSON.stringify(defaults), 'Reset must restore every default field');
    await expectText(home, '.hero-title__main', defaults.sections.hero.title);
    await expectText(home, `${card} .product-card__price`, formatPrice(original.price));
    await expectText(catalog, `${card} h3 a`, original.title);
    await expectText(detail, '#product-title', original.title);
    await expectText(detail, '.product-detail__price', formatPrice(original.price));
    await detail.locator('.cart-trigger').click();
    await expectText(detail, '[data-cart-total]', formatPrice(original.price * 2));
    await detail.keyboard.press('Escape');
    await admin.reload({ waitUntil: 'load' });
    await editorReady(admin);
    check(await admin.locator('[data-cms-field="title"]').inputValue() === defaults.sections.hero.title, 'Reset must survive a fresh editor load');

    console.log('CMS: mobile and desktop breakpoint navigation, overflow and screenshots');
    for (const width of [320, 390, 1080, 1081]) {
      await home.setViewportSize({ width, height: 844 });
      await home.goto(`${origin}/index.html`, { waitUntil: 'load' });
      await home.waitForFunction(() => document.body.classList.contains('is-ready'));
      await expectText(home, '.hero-title__main', defaults.sections.hero.title);
      await noOverflow(home, `Home ${width}px`);
      if (width <= 1080) {
        await home.locator('[data-menu-toggle]').click();
        const link = home.locator('[data-mobile-menu] a[href="admin.html"]');
        await link.waitFor({ state: 'visible' });
        check(await link.isVisible(), `${width}px: mobile menu must expose Management`);
        await link.click();
      } else {
        const link = home.locator('.desktop-nav a[href="admin.html"]');
        check(await link.isVisible(), `${width}px: desktop header must expose Management`);
        const bounds = await home.evaluate(() => ['.brand', '.desktop-nav', '.header-actions'].map(selector => {
          const rect = document.querySelector(selector).getBoundingClientRect();
          return { selector, left: rect.left, right: rect.right };
        }));
        check(bounds[0].right <= bounds[1].left + 1 && bounds[1].right <= bounds[2].left + 1, `${width}px: header elements overlap ${JSON.stringify(bounds)}`);
        await link.click();
      }
      await home.waitForURL('**/admin.html');
      await editorReady(home);
      await noOverflow(home, `Admin ${width}px`);
      if (width <= 390) {
        await selectEditor(home, `product:${original.id}`);
        await home.locator('[data-cms-field="price"]').fill('15555');
        await saveEditor(home);
        await home.reload({ waitUntil: 'load' });
        await editorReady(home);
        const content = await home.evaluate(() => window.MaharaCMS.load());
        check(content.products[0].price === 15555, `${width}px: mobile Save must persist`);
        await resetEditor(home);
      }
    }
    await admin.setViewportSize({ width: 1440, height: 1000 });
    await admin.reload({ waitUntil: 'load' });
    await editorReady(admin);
    fs.mkdirSync(path.join(root, 'preview'), { recursive: true });
    await prepareScreenshot(admin);
    await admin.screenshot({ path: path.join(root, 'preview/admin-desktop.png'), fullPage: true });
    await selectEditor(admin, `product:${original.id}`);
    await prepareScreenshot(admin);
    await admin.screenshot({ path: path.join(root, 'preview/admin-product.png'), fullPage: true });
    await admin.setViewportSize({ width: 390, height: 844 });
    await selectEditor(admin, 'section:hero');
    await prepareScreenshot(admin);
    await admin.screenshot({ path: path.join(root, 'preview/admin-mobile.png'), fullPage: true });
    check(errors.length === 0, `JavaScript errors: ${errors.join('; ')}`);
    check(failedResponses.length === 0, `Failed local requests: ${failedResponses.join('; ')}`);
    check(externalRequests.length === 0, `Unexpected external requests: ${externalRequests.join('; ')}`);
    await context.close();
    console.log(`PASS: ${checks} CMS checks (UI save, IndexedDB, broadcasts, upload, text safety, cart, reset, responsive navigation)`);
    console.log('Screenshots: preview/admin-desktop.png, preview/admin-product.png, preview/admin-mobile.png');
  } finally { await browser.close(); }
}

run().catch(error => { console.error(error); process.exitCode = 1; }).finally(() => server.close());
