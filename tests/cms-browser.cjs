#!/usr/bin/env node
'use strict';

// Real browser coverage against isolated PHP/SQLite storage and a free port.
// This never touches the developer's preview server or published database.
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
const PREVIEWS = path.join(ROOT, '.local', 'cms-browser-preview');
const bundledPHP = path.join(ROOT, '.runtime', 'php', 'php.exe');
const PHP = process.env.PHP_BINARY || (fs.existsSync(bundledPHP) ? bundledPHP : 'php');
const USERNAME = 'cms-browser-owner';
const PASSWORD = `cms-test-${randomUUID()}`;
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
  const tempRoot = fs.realpathSync(os.tmpdir());
  const actual = fs.realpathSync(directory);
  const relative = path.relative(tempRoot, actual);
  assert.ok(relative && !relative.startsWith('..') && !path.isAbsolute(relative), 'Cleanup must stay inside os.tmpdir()');
  assert.ok(path.basename(actual).startsWith('mahara-cms-browser-'), 'Cleanup must target this test directory');
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

async function editorReady(page) {
  await page.locator('[data-cms-workspace][aria-busy="false"]').waitFor();
  await page.locator('[data-cms-field="title"]').waitFor();
}

async function imageLoaded(page, selector, source) {
  await page.locator(selector).scrollIntoViewIfNeeded();
  await page.waitForFunction(({ selector, source }) => {
    const image = document.querySelector(selector);
    return image?.getAttribute('src') === source && !image.hidden && image.complete && image.naturalWidth > 0;
  }, { selector, source });
  check(await page.locator(selector).isVisible(), `${selector}: uploaded photo is visibly decoded`);
}

async function upload(page, key, filename) {
  const photoIndex = await page.locator('[data-cms-photo-item]').count();
  const responsePromise = page.waitForResponse(response => response.url().endsWith('/api/admin/uploads') && response.request().method() === 'POST');
  await page.locator('[data-cms-upload="images"]').setInputFiles(path.join(ROOT, 'assets/images', filename));
  const response = await responsePromise;
  equal(response.status(), 201, `${key}: upload endpoint accepts prepared image bytes`);
  const { url } = await response.json();
  check(/^assets\/uploads\/[a-f0-9]{32}\.(?:webp|png|jpg)$/.test(url), `${key}: API returns a durable upload URL`);
  await imageLoaded(page, `[data-cms-photo-item][data-cms-photo-index="${photoIndex}"] img`, url);
  await page.locator('[data-cms-workspace][aria-busy="false"]').waitFor();
  return url;
}

async function save(page, expectedStatus = 200) {
  const responsePromise = page.waitForResponse(response => response.url().endsWith('/api/admin/content') && response.request().method() === 'PUT');
  await page.locator('[data-cms-save]').click();
  const response = await responsePromise;
  equal(response.status(), expectedStatus, `Content save returns ${expectedStatus}`);
  await page.locator('[data-cms-workspace][aria-busy="false"]').waitFor();
  if (expectedStatus === 200) {
    await page.locator('[data-cms-status][data-state="saved"]').waitFor();
    check(await page.locator('[data-cms-save]').isDisabled(), 'Saved draft cannot submit again');
  }
  return response;
}

async function discard(page) {
  await page.locator('[data-cms-reset]').click();
  await page.locator('[data-cms-reset-confirm]').click();
  await page.locator('#cms-reset-confirm').waitFor({ state: 'hidden' });
  check(await page.locator('[data-cms-save]').isDisabled(), 'Discard returns to a clean saved baseline');
}

async function noOverflow(page, label) {
  // Chrome applies responsive styles on the next rendering frame after resize.
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
  const overflow = dimensions.document > dimensions.viewport + 1 ? await page.evaluate(() => Array.from(document.querySelectorAll('body *')).filter(node => !node.closest('.cms-list')).map(node => ({ tag: node.tagName, class: node.className, right: Math.round(node.getBoundingClientRect().right), width: Math.round(node.getBoundingClientRect().width), scroll: node.scrollWidth })).filter(node => node.right > innerWidth + 1 || node.scroll > node.width + 5).slice(0, 12)) : [];
  if (overflow.length) await page.screenshot({ path: path.join(PREVIEWS, 'admin-overflow-debug.png'), fullPage: false });
  check(dimensions.document <= dimensions.viewport + 1, `${label}: no horizontal overflow ${JSON.stringify({ ...dimensions, overflow })}`);
}

async function run() {
  const privateDir = fs.mkdtempSync(path.join(os.tmpdir(), 'mahara-cms-browser-'));
  fs.mkdirSync(PREVIEWS, { recursive: true });
  let server;
  let browser;
  let serverOutput = '';
  try {
    const port = await unusedPort();
    check(port !== 8000, 'The developer preview port is not used');
    const origin = `http://127.0.0.1:${port}`;
    const env = { ...process.env, APP_ENV: 'development', APP_ORIGIN: origin, DATA_DIR: privateDir, MAHARA_ADMIN_USERNAME: USERNAME, MAHARA_ADMIN_PASSWORD: PASSWORD };
    // Both CLI provisioning and HTTP dispatch must bypass config.local.php,
    // whose explicit settings otherwise override the disposable environment.
    const fixtureConfig = Buffer.from(JSON.stringify({ root: ROOT, data_dir: privateDir, origin, production: false, trust_proxy: false, payments: { enabled: false }, shipping: { enabled: false }, mail: { enabled: false } })).toString('base64');
    const fixtureRoot = Buffer.from(ROOT).toString('base64');
    const fixtureBootstrap = `$GLOBALS['mahara_app_test_config']=json_decode(base64_decode('${fixtureConfig}'),true,512,JSON_THROW_ON_ERROR);$GLOBALS['mahara_payments_test_secrets']=['terminal_key'=>'','password'=>''];$GLOBALS['mahara_shipping_test_secrets']=['account'=>'','password'=>''];$GLOBALS['mahara_mail_test_secrets']=['username'=>'','password'=>''];$fixtureRoot=base64_decode('${fixtureRoot}');`;
    const account = spawnSync(PHP, ['-r', fixtureBootstrap + `$argv=[__FILE__,'create'];require $fixtureRoot.'/tools/admin.php';`], { cwd: ROOT, env, encoding: 'utf8', windowsHide: true });
    equal(account.status, 0, `Isolated administrator is provisioned: ${account.stderr || account.error || ''}`);
    check(!account.stdout.includes(PASSWORD), 'Account provisioning never prints the password');
    const router = path.join(privateDir, 'cms-browser-router.php');
    fs.writeFileSync(router, `<?php define('MAHARA_LIBRARY_ONLY',true);${fixtureBootstrap}require $fixtureRoot.'/server/app.php';mahara_dispatch();`);
    server = spawn(PHP, ['-S', `127.0.0.1:${port}`, '-t', ROOT, router], { cwd: ROOT, env, stdio: ['ignore', 'ignore', 'pipe'], windowsHide: true });
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
    check(started, `Isolated PHP server starts: ${serverOutput}`);
    const protectedPage = await fetch(origin + '/admin.html', { redirect: 'manual' });
    equal(protectedPage.status, 302, 'Admin HTML requires authentication');
    equal(protectedPage.headers.get('location'), '/admin/login', 'Unauthenticated admin redirects to login');
    equal((await fetch(origin + '/api/admin/session')).status, 401, 'Session API rejects anonymous access');
    const snapshot = async () => (await (await fetch(origin + '/api/content')).json());
    const initial = await snapshot();

    browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    const errors = [];
    const failedAssets = [];
    const externalRequests = [];
    let nativeLeavePrompts = 0;
    await context.route('**/*', route => {
      const url = new URL(route.request().url());
      if (url.origin === origin || ['data:', 'blob:'].includes(url.protocol)) return route.continue();
      externalRequests.push(url.href);
      return route.abort();
    });
    context.on('page', page => {
      page.on('pageerror', error => errors.push(`${page.url()}: ${error.message}`));
      page.on('response', response => {
        if (response.status() >= 400 && !new URL(response.url()).pathname.startsWith('/api/')) failedAssets.push(`${response.status()} ${response.url()}`);
      });
      page.on('dialog', dialog => { if (dialog.type() === 'beforeunload') nativeLeavePrompts += 1; return dialog.accept(); });
    });
    const page = await context.newPage();
    console.log('CMS: login, fresh profile, protected editor and product validation');
    const login = await page.goto(origin + '/admin/login', { waitUntil: 'load' });
    check(login.headers()['cache-control'].includes('no-store'), 'Login HTML is never cached');
    await page.locator('[name="username"]').fill(USERNAME);
    await page.locator('[name="password"]').fill('incorrect-test-password');
    await page.locator('[data-login-submit]').click();
    await page.waitForFunction(() => document.querySelector('[data-login-error]')?.textContent.includes('Неверный'));
    check(await page.locator('[data-login-error]').isVisible(), 'Incorrect login has a visible error');
    await page.locator('[name="password"]').fill(PASSWORD);
    await page.locator('[data-login-submit]').click();
    await page.waitForURL('**/admin.html');
    await editorReady(page);
    const cookies = await context.cookies(origin);
    check(cookies.some(cookie => cookie.httpOnly && cookie.sameSite === 'Strict'), 'Admin session is stored in an HttpOnly SameSite cookie');
    check(await page.locator('.cms-topbar a[href="/inventory.html"]').isVisible(), 'Editor exposes the protected warehouse');
    equal(await page.locator('.cms-topbar .search-trigger, .cms-topbar [aria-label="Личный кабинет"]').count(), 0, 'Admin header has no removed icons');
    const adminNavigation = await page.locator('header nav a').allTextContents();
    check(await page.locator('header [data-cms-logout]').isVisible(), 'Shared editor header exposes sign out');
    equal(adminNavigation.map(text => text.trim()).sort(), ['Заказы', 'Операции', 'Контент и товары', 'Склад', 'Витрина ↗'].sort(), 'Shared header exposes every management section');
    equal(await page.locator('header [aria-current="page"]').getAttribute('href'), '/admin.html', 'Content header marks the current section');

    console.log('CMS: warehouse creation can be abandoned without form validation');
    await page.locator('header a[href="/inventory.html"]').click();
    await page.waitForURL('**/inventory.html');
    check(await page.locator('header [data-logout]').isVisible(), 'Shared warehouse header exposes sign out');
    equal(await page.locator('header nav a').allTextContents(), adminNavigation, 'Warehouse and editor use identical navigation labels');
    equal(await page.locator('header [aria-current="page"]').getAttribute('href'), '/inventory.html', 'Warehouse header marks the current section');
    await page.locator('a[href="/admin.html?newProduct=1"]').click();
    await editorReady(page);
    const promptsBeforeEmptyDraft = nativeLeavePrompts;
    check(await page.locator('[data-cms-save]').isDisabled(), 'Empty warehouse draft has invalid fields');
    await page.locator('header a[href="/inventory.html"]').click();
    await page.waitForURL('**/inventory.html');
    equal(nativeLeavePrompts, promptsBeforeEmptyDraft, 'An untouched new card never blocks return with a browser prompt');
    equal((await snapshot()).products.length, initial.products.length, 'Abandoning an empty card creates no published product');
    await page.locator('a[href="/admin.html?newProduct=1"]').click();
    await editorReady(page);
    await page.goBack();
    await page.waitForURL('**/inventory.html');
    equal(nativeLeavePrompts, promptsBeforeEmptyDraft, 'Browser Back also leaves an untouched new card freely');

    await page.locator('a[href="/admin.html?newProduct=1"]').click();
    await editorReady(page);
    await page.locator('[data-cms-field="title"]').fill('Передумал добавлять товар');
    check(await page.locator('[data-cms-save]').isDisabled(), 'Partially filled card remains invalid');
    await page.locator('header a[href="/inventory.html"]').click();
    await page.locator('[data-cms-leave-dialog]').waitFor({ state: 'visible' });
    await page.locator('[data-cms-leave-cancel]').click();
    equal(await page.locator('[data-cms-field="title"]').inputValue(), 'Передумал добавлять товар', 'Staying preserves the incomplete draft');
    await page.locator('header a[href="/inventory.html"]').click();
    await page.locator('[data-cms-leave-confirm]').click();
    await page.waitForURL('**/inventory.html');
    equal(nativeLeavePrompts, promptsBeforeEmptyDraft, 'Explicit departure has no second browser prompt');
    equal((await snapshot()).products.length, initial.products.length, 'Leaving an incomplete card never submits or publishes it');

    await page.goto(origin + '/admin.html?product=' + initial.products[0].id, { waitUntil: 'load' });
    await editorReady(page);
    await page.locator('[data-cms-field="title"]').fill('');
    await page.locator('header a[href="/inventory.html"]').click();
    await page.locator('[data-cms-leave-dialog]').waitFor({ state: 'visible' });
    await page.locator('[data-cms-leave-confirm]').click();
    await page.waitForURL('**/inventory.html');
    equal((await snapshot()).products[0].title, initial.products[0].title, 'Leaving invalid edits preserves the existing published product');

    await page.goto(origin + '/admin.html?newProduct=1', { waitUntil: 'load' });
    await editorReady(page);
    equal(await page.locator('#cms-editor-title').textContent(), 'Новый товар', 'Creation hook opens a new product');
    check(!new URL(page.url()).searchParams.has('newProduct'), 'Creation query is consumed without adding another history entry');
    const selected = await page.locator('[data-cms-select][aria-pressed="true"]').getAttribute('data-cms-select');
    const productId = selected.slice('product:'.length);
    check(/^item-[a-f0-9-]{36}$/.test(productId), 'New product gets a persistent UUID identifier');
    equal((await snapshot()).products.length, initial.products.length, 'A blank draft is never published automatically');
    check(await page.locator('[data-cms-save]').isDisabled(), 'A blank new product cannot save');
    check((await page.locator('[data-cms-field-error="images"]').textContent()).trim().length > 0, 'A new product requires at least one photo');
    const productTitle = 'Тестовая футболка MAHARA & Art';
    const productDescription = 'Плотный хлопок. Проверка новой коллекции и второго ракурса.';
    const productFacts = {composition:'100% хлопок (тестовые данные)',care:'Бережная стирка\nНе отбеливать',manufacturer:'Тестовый изготовитель, Россия',measurements:'<img id="facts-xss-probe" src=x>\nM: длина 70 см, ширина 56 см'};
    for (const key of Object.keys(productFacts)) {
      equal(await page.locator(`[data-cms-field="${key}"]`).inputValue(), '', `${key}: new factual field starts empty`);
      equal(await page.locator(`[data-cms-field="${key}"]`).getAttribute('maxlength'), '2000', `${key}: factual field is bounded`);
    }
    await page.locator('[data-cms-field="title"]').fill(productTitle);
    await page.locator('[data-cms-field="subtitle"]').fill('Heavy cotton / Test collection');
    await page.locator('[data-cms-field="category"]').selectOption('tee');
    await page.locator('[data-cms-field="color"]').selectOption('burgundy');
    await page.locator('[data-cms-field="price"]').fill('12.5');
    check((await page.locator('[data-cms-field-error="price"]').textContent()).includes('целую'), 'Fractional prices are rejected before saving');
    await page.locator('[data-cms-field="price"]').fill('13579');
    await page.locator('[data-cms-field="sizes"]').fill('UNKNOWN');
    check((await page.locator('[data-cms-field-error="sizes"]').textContent()).includes('XS'), 'Unknown sizes have a clear validation error');
    await page.locator('[data-cms-field="sizes"]').fill('XS, M, XL');
    await page.locator('[data-cms-field="description"]').fill(productDescription);
    for (const [key,value] of Object.entries(productFacts)) await page.locator(`[data-cms-field="${key}"]`).fill(value);
    check((await page.locator('[data-cms-preview-fact="composition"]').textContent()).includes(productFacts.composition), 'Product preview includes entered facts');
    equal(await page.locator('[data-cms-preview] #facts-xss-probe').count(), 0, 'Factual preview escapes HTML-like text');
    await page.locator('[data-cms-field="label"]').fill('New collection');

    console.log('CMS: real image uploads, decode, original-photo restore and pending-upload guards');
    let releaseUpload;
    let announceUpload;
    const uploadGate = new Promise(resolve => { releaseUpload = resolve; });
    const uploadStarted = new Promise(resolve => { announceUpload = resolve; });
    await page.route('**/api/admin/uploads', async route => { announceUpload(); await uploadGate; await route.continue(); });
    const mainUpload = upload(page, 'image', 'hero-woman.webp');
    await uploadStarted;
    for (const selector of ['[data-cms-save]', '[data-cms-reset]', '[data-cms-add-product]', '[data-cms-logout]', '[data-cms-photos-reset]', '[data-cms-select="section:hero"]']) {
      check(await page.locator(selector).isDisabled(), `${selector}: frozen while upload is pending`);
    }
    releaseUpload();
    await mainUpload;
    await page.unroute('**/api/admin/uploads');
    await page.locator('[data-cms-photos-reset]').click();
    equal(await page.locator('[data-cms-photo-item]').count(), 0, 'Restoring a new product photo collection clears it without a missing-baseline exception');
    check(await page.locator('[data-cms-save]').isDisabled(), 'Removing the primary photo makes the new product invalid again');
    const mainImage = await upload(page, 'image', 'hero-woman.webp');
    await imageLoaded(page, '[data-cms-preview] .cms-mini-product__photo img', mainImage);
    await page.locator('[data-cms-upload="images"]').setInputFiles({ name: 'broken.webp', mimeType: 'image/webp', buffer: Buffer.from('RIFF0000WEBPinvalid-image') });
    await page.waitForFunction(() => document.querySelector('[data-cms-field-error="images"]')?.textContent.trim().length > 0);
    check(await page.locator('[data-cms-photo-error-dismiss]').isEnabled(), 'A failed additional upload can be acknowledged while preserving the current photo');
    await page.locator('[data-cms-photo-error-dismiss]').click();
    equal((await page.locator('[data-cms-field-error="images"]').textContent()).trim(), '', 'Keeping the previous photos clears the failed batch error');
    const altImage = await upload(page, 'altImage', 'black-hoodie.webp');
    await page.locator('[data-cms-photo-preview="1"]').click();
    await imageLoaded(page, '[data-cms-preview] .cms-mini-product__photo img', altImage);
    for (const source of [mainImage, altImage]) {
      const image = await context.request.get(origin + '/' + source);
      equal(image.status(), 200, 'Saved upload is publicly readable');
      check(image.headers()['content-type'].startsWith('image/'), 'Upload is served as an image');
      check((await image.body()).equals(fs.readFileSync(path.join(privateDir, 'uploads', path.basename(source)))), 'Uploaded bytes persist inside the isolated private storage');
    }
    const savedResponse = await save(page);
    const payload = savedResponse.request().postDataJSON();
    equal(Object.keys(payload).sort(), ['version', 'sections', 'products', 'revision'].sort(), 'Editor submits the strict content document shape');
    check(Number.isInteger(payload.revision), 'Optimistic revision accompanies the save');
    const content = await snapshot();
    equal(content.products.length, initial.products.length + 1, 'New product is persisted once');
    const product = content.products.find(item => item.id === productId);
    check(Boolean(product), 'Persistent identifier survives save normalization');
    equal([product.title, product.category, product.color, product.price, product.sizes, product.image, product.altImage, product.description], [productTitle, 'tee', 'burgundy', 13579, ['XS', 'M', 'XL'], mainImage, altImage, productDescription], 'All new-product fields persist together');
    for (const [key,value] of Object.entries(productFacts)) equal(product[key], value, `${key}: factual content is persisted`);
    check(Number.isInteger(product.width) && product.width > 0 && product.width <= 1600 && Number.isInteger(product.height) && product.height <= 1600, 'Uploaded primary image stores its prepared dimensions');

    console.log('CMS: published catalog/detail, metadata, sitemap and reload persistence');
    const catalog = await context.newPage();
    await catalog.goto(origin + '/catalog.html', { waitUntil: 'load' });
    const cardSelector = `[data-product-id="${productId}"]`;
    const card = catalog.locator(cardSelector);
    await card.waitFor({ state: 'visible' });
    equal(await card.locator('h3 a').textContent(), productTitle, 'Published catalog shows the new product title');
    equal(await card.getAttribute('data-category'), 'tee', 'Catalog receives the selected category');
    equal(await card.getAttribute('data-color'), 'burgundy', 'Catalog receives the selected color');
    equal(await card.locator('h3 a').getAttribute('href'), product.url, 'Catalog links to the generated product URL');
    await imageLoaded(catalog, `${cardSelector} .product-card__image`, mainImage);
    const detail = await context.newPage();
    const detailResponse = await detail.goto(origin + '/' + product.url, { waitUntil: 'load' });
    equal(detailResponse.status(), 200, 'Generated product URL is available without a static product file');
    equal(await detail.locator('#product-title').textContent(), productTitle, 'Product page displays the persisted title');
    equal(await detail.locator('.product-detail__description').textContent(), productDescription, 'Product page displays the persisted description');
    for (const [key,value] of Object.entries(productFacts)) equal(await detail.locator(`[data-product-fact="${key}"] dd`).textContent(), value, `${key}: product page displays factual content literally`);
    equal(await detail.locator('#facts-xss-probe').count(), 0, 'Product facts cannot inject markup');
    equal((await detail.locator('.product-detail__price').textContent()).replace(/\s/g, ''), '13579₽', 'Product page displays the integer price');
    equal(await detail.locator('[data-product-detail] [data-size-choice]').allTextContents(), ['XS', 'M', 'XL'], 'Product page displays the saved sizes');
    await imageLoaded(detail, '.product-gallery__viewport .swiper-slide:nth-child(1) > img', mainImage);
    await detail.locator('[data-gallery-thumb="1"]').click();
    await imageLoaded(detail, '.product-gallery__viewport .swiper-slide:nth-child(2) > img', altImage);
    check((await detail.title()).includes(productTitle), 'Product title reaches page metadata');
    check((await detail.locator('meta[name="description"]').getAttribute('content')).includes(productDescription), 'Product description reaches search metadata');
    check((await detail.locator('link[rel="canonical"]').getAttribute('href')).endsWith('/' + product.url), 'Canonical URL follows the generated URL');
    check((await detail.locator('meta[property="og:image"]').getAttribute('content')).endsWith('/' + mainImage), 'Social metadata uses the uploaded primary image');
    const graph = JSON.parse(await detail.locator('script[type="application/ld+json"]').textContent())['@graph'];
    const productSchema = graph.find(item => item['@type'] === 'Product');
    equal(productSchema.name, productTitle, 'Structured product data uses the persisted title');
    equal(productSchema.size, product.sizes, 'Structured product data uses the persisted sizes');
    equal(productSchema.image.map(url => new URL(url).pathname.slice(1)), [mainImage, altImage], 'Structured data includes both uploaded views');
    check((await (await fetch(origin + '/sitemap.xml')).text()).includes('/' + product.url + '</loc>'), 'Sitemap includes the generated product URL');
    await page.goto(origin + '/admin.html?product=' + productId, { waitUntil: 'load' });
    await editorReady(page);
    equal(await page.locator('[data-cms-field="title"]').inputValue(), productTitle, 'Product query opens the persisted editor after reload');
    for (const [key,value] of Object.entries(productFacts)) equal(await page.locator(`[data-cms-field="${key}"]`).inputValue(), value, `${key}: editor restores saved factual content`);
    await imageLoaded(page, '[data-cms-photo-item][data-cms-photo-index="0"] img', mainImage);
    await imageLoaded(page, '[data-cms-photo-item][data-cms-photo-index="1"] img', altImage);
    await upload(page, 'image', 'black-hoodie.webp');
    await page.locator('[data-cms-photos-reset]').click();
    await imageLoaded(page, '[data-cms-photo-item][data-cms-photo-index="0"] img', mainImage);
    equal(await page.locator('[data-cms-photo-item]').count(), 2, 'Photo restore removes the unsaved addition and restores the saved collection');
    check(await page.locator('[data-cms-save]').isDisabled(), 'Photo restore returns an existing product to its saved baseline');
    await page.locator('[data-cms-add-product]').click();
    await page.locator('[data-cms-field="title"]').fill('Несохранённый товар');
    await discard(page);
    equal(Number(await page.locator('[data-cms-product-count]').textContent()), content.products.length, 'Discard removes a new draft product');
    equal((await snapshot()).products.length, content.products.length, 'Discard does not rewrite the published catalog');

    console.log('CMS: section rendering, escaped text, concurrent save conflicts and responsive admin');
    await page.locator('[data-cms-select="section:hero"]').click();
    const heroTitle = 'MAHARA TEST & CULTURE';
    const heroDescription = '<img id="cms-xss-probe" src=x onerror="window.__cmsXSS=true"> Авторский текст';
    await page.locator('[data-cms-field="title"]').fill(heroTitle);
    await page.locator('[data-cms-field="description"]').fill(heroDescription);
    await save(page);
    const home = await context.newPage();
    await home.goto(origin + '/', { waitUntil: 'load' });
    equal(await home.locator('.hero-title__main').textContent(), heroTitle, 'Saved section title reaches the storefront');
    equal(await home.locator('.hero__copy').textContent(), heroDescription, 'Saved section text remains literal text');
    equal(await home.locator('#cms-xss-probe').count(), 0, 'Saved HTML-like text cannot inject markup');
    check((await home.title()).includes(heroTitle), 'Saved hero title reaches home metadata');
    const noJS = await browser.newContext({ javaScriptEnabled: false });
    const rendered = await noJS.newPage();
    await rendered.goto(origin + '/', { waitUntil: 'load' });
    equal(await rendered.locator('.hero-title__main').textContent(), heroTitle, 'Published edits are rendered without JavaScript');
    await rendered.goto(origin + '/' + product.url, { waitUntil: 'load' });
    equal(await rendered.locator('#product-title').textContent(), productTitle, 'New product pages render without JavaScript');
    for (const [key,value] of Object.entries(productFacts)) equal(await rendered.locator(`[data-product-fact="${key}"] dd`).textContent(), value, `${key}: factual content also renders without JavaScript`);
    await noJS.close();
    const stale = await context.newPage();
    await stale.goto(origin + '/admin.html', { waitUntil: 'load' });
    await editorReady(stale);
    await page.locator('[data-cms-field="title"]').fill(heroTitle + ' 2');
    await save(page);
    await stale.locator('[data-cms-field="title"]').fill('Конфликтующий черновик');
    await save(stale, 409);
    check((await stale.locator('[data-cms-error]').textContent()).includes('другой вкладке'), 'A stale revision has a visible explanation');
    equal(await stale.locator('[data-cms-field="title"]').inputValue(), 'Конфликтующий черновик', 'A rejected stale save preserves the draft');
    equal((await snapshot()).sections.hero.title, heroTitle + ' 2', 'A stale save cannot overwrite a newer publication');
    await stale.close();
    await page.goto(origin + '/admin.html?product=' + productId, { waitUntil: 'load' });
    await editorReady(page);
    fs.mkdirSync(PREVIEWS, { recursive: true });
    await noOverflow(page, 'Admin desktop');
    await page.screenshot({ path: path.join(PREVIEWS, 'admin-secure.png'), fullPage: false });
    for (const width of [390, 360, 320]) {
      await page.setViewportSize({ width, height: 844 });
      await noOverflow(page, `Admin ${width}px`);
      const header = page.locator('.cms-topbar');
      const bounds = await header.locator('.brand, .cms-topbar__actions').evaluateAll(nodes => nodes.map(node => { const rect = node.getBoundingClientRect(); return { left: rect.left, right: rect.right }; }));
      check(bounds[0].right <= bounds[1].left + 1, `${width}px: brand and administration actions do not overlap`);
      check(bounds[1].right <= width, `${width}px: administration actions fit on screen`);
      check(await header.locator('a[href="/inventory.html"]').isVisible(), `${width}px: warehouse link remains available`);
      if (width === 390) await page.screenshot({ path: path.join(PREVIEWS, 'admin-secure-mobile.png'), fullPage: false });
    }
    await page.goto(origin + '/admin.html?product=unknown-product', { waitUntil: 'load' });
    await editorReady(page);
    equal(await page.locator('[data-cms-select][aria-pressed="true"]').getAttribute('data-cms-select'), 'section:hero', 'Unknown product query safely falls back to the hero editor');
    await page.locator('[data-cms-logout]').click();
    await page.waitForURL('**/admin/login');
    equal((await context.request.get(origin + '/api/admin/session')).status(), 401, 'Logout invalidates the server session');
    await page.goto(origin + '/admin.html', { waitUntil: 'load' });
    await page.waitForURL('**/admin/login');
    equal(errors, [], 'Fresh browser has no uncaught JavaScript errors');
    equal(failedAssets, [], 'Fresh browser loads every local script, style and photo');
    equal(externalRequests, [], 'Editor and storefront have no external runtime dependencies');
    await context.close();
    console.log(`PASS: ${checks} CMS checks (UI login, product creation, real uploads, undo, persistence, SSR/SEO, conflicts, mobile layout, logout)`);
    console.log('Screenshots: .local/cms-browser-preview/admin-secure.png, .local/cms-browser-preview/admin-secure-mobile.png');
  } catch (error) {
    if (serverOutput.includes('PHP Fatal error') || serverOutput.includes('MAHARA backend error')) console.error(serverOutput);
    throw error;
  } finally {
    try { if (browser) await browser.close(); }
    finally { try { await stopServer(server); } finally { removeTestDirectory(privateDir); } }
  }
}

run().catch(error => { console.error(error); process.exitCode = 1; });
