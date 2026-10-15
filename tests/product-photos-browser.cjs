#!/usr/bin/env node
'use strict';

// Real admin image preparation and UI against authenticated, in-memory local
// API fixtures. Saves use the actual pure PHP content validator, never app
// dispatch, private configuration, SQLite, production data or provider APIs.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { setTimeout: delay } = require('node:timers/promises');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/valer/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const ROOT = path.resolve(__dirname, '..');
const bundledPHP = path.join(ROOT, '.runtime', 'php', process.platform === 'win32' ? 'php.exe' : 'php');
const PHP = process.env.PHP_BINARY || (fs.existsSync(bundledPHP) ? bundledPHP : 'php');
const defaults = JSON.parse(fs.readFileSync(path.join(ROOT, 'site-defaults.json'), 'utf8'));
const csrf = 'isolated-photo-editor-csrf';
const legacy = structuredClone(defaults.products[0]); delete legacy.images;
const fixture = { content: { ...structuredClone(defaults), products: [legacy], revision: 1 }, calls: [], forbidden: [], uploads: new Map(), uploadCalls: 0, saves: [], rejectedSaves: 0, failUploadAt: 0, uploadGate: null, onFirstUpload: null };
const errors = []; let nativePrompts = 0; let checks = 0;
const check = (value, message) => { checks++; assert.ok(value, message); };
const equal = (actual, expected, message) => { checks++; assert.deepEqual(actual, expected, message); };
const PHOTO = '[data-cms-photo-list] [data-cms-photo-item]';
const INPUT = '[data-cms-upload="images"]';
const photoURLs = page => page.locator(`${PHOTO} img`).evaluateAll(images => images.map(image => new URL(image.getAttribute('src'), location.href).pathname.replace(/^\/+/, '')));
const product = id => fixture.content.products.find(item => item.id === id);

function validate(content) {
  const php = 'define("MAHARA_LIBRARY_ONLY",true); $GLOBALS["mahara_app_test_config"]=[]; require $argv[1]."/server/app.php"; try{$value=mahara_validate_content(json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR)); echo json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}catch(MaharaHttpError $error){echo json_encode(["error"=>$error->getMessage()],JSON_THROW_ON_ERROR);exit(3);}';
  const result = spawnSync(PHP, ['-r', php, ROOT], { cwd: ROOT, input: JSON.stringify(content), encoding: 'utf8', maxBuffer: 2 * 1024 * 1024 });
  assert.ok(result.status === 0 || result.status === 3, `Isolated pure content validation failed: ${result.error?.message || result.stderr}`);
  return { ok: result.status === 0, value: JSON.parse(result.stdout) };
}

async function editorReady(page) {
  await page.locator('[data-cms-workspace][aria-busy="false"]').waitFor();
  await page.locator('[data-cms-field="title"]').waitFor();
}
async function waitPhotos(page, expected) {
  await page.waitForFunction(({ selector, expected }) => JSON.stringify([...document.querySelectorAll(selector)].map(image => new URL(image.getAttribute('src'), location.href).pathname.replace(/^\/+/, ''))) === JSON.stringify(expected), { selector: `${PHOTO} img`, expected });
  equal(await photoURLs(page), expected, 'The editor displays exactly the expected ordered photos');
}
async function save(page, id, expected) {
  const response = page.waitForResponse(value => new URL(value.url()).pathname === '/api/admin/content' && value.request().method() === 'PUT');
  await page.locator('[data-cms-save]').click(); equal((await response).status(), 200, 'Actual admin save passes the real PHP content validator');
  await editorReady(page); await page.locator('[data-cms-status][data-state="saved"]').waitFor();
  equal(product(id).images, expected, 'All ordered photos are persisted in canonical content');
  equal([product(id).image, product(id).altImage], [expected[0], expected[1] || expected[0]], 'Canonical cover and alternate aliases match photo order');
  const raw = fixture.saves.at(-1).products.find(item => item.id === id);
  equal([raw.image, raw.altImage], [expected[0], expected[1] || expected[0]], 'The admin client also sends current aliases before server normalization');
  check(await page.locator('[data-cms-save]').isDisabled(), 'A successful save leaves a clean draft');
}
async function reopen(page, origin, id, expected) {
  await page.goto(`${origin}/admin.html?product=${id}`); await editorReady(page); await waitPhotos(page, expected);
  check(await page.locator('[data-cms-save]').isDisabled(), 'Reopening a product preserves order without inventing unsaved edits');
}
async function noOverflow(page, width) {
  await page.setViewportSize({ width, height: 900 });
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth, photos: document.querySelector('[data-cms-photos]').getBoundingClientRect().width }));
  check(dimensions.document <= dimensions.viewport + 1 && dimensions.photos <= dimensions.viewport + 1, `${width}px: ten-photo editor has no horizontal overflow ${JSON.stringify(dimensions)}`);
}

async function run() {
  const server = http.createServer(async (request, response) => {
    try {
      const pathname = new URL(request.url, 'http://localhost').pathname;
      fixture.calls.push([request.method, pathname]);
      const send = (status, value, type = 'application/json') => { response.writeHead(status, { 'Content-Type': type, 'Cache-Control': 'no-store' }); response.end(type === 'application/json' ? JSON.stringify(value) : value); };
      if (request.method === 'GET' && pathname === '/api/admin/session') return send(200, { user: { username: 'photo-fixture-owner' }, csrfToken: csrf });
      if (request.method === 'GET' && pathname === '/api/content') return send(200, fixture.content);
      if (pathname.startsWith('/api/')) {
        if (request.headers['x-csrf-token'] !== csrf) return send(403, { error: 'Fixture CSRF missing' });
        const chunks = []; let size = 0;
        for await (const chunk of request) { size += chunk.length; if (size > 11 * 1024 * 1024) throw new Error('Fixture body limit exceeded'); chunks.push(chunk); }
        const body = Buffer.concat(chunks);
        if (request.method === 'POST' && pathname === '/api/admin/uploads') {
          const sequence = ++fixture.uploadCalls; fixture.onFirstUpload?.(); fixture.onFirstUpload = null;
          if (fixture.uploadGate) await fixture.uploadGate;
          if (sequence === fixture.failUploadAt) return send(503, { error: 'Тестовый отказ загрузки' });
          check(request.headers['content-type'] === 'image/webp' && body.subarray(0, 4).toString() === 'RIFF' && body.subarray(8, 12).toString() === 'WEBP', 'The real browser prepares and uploads actual WebP image bytes');
          const url = `assets/uploads/${sequence.toString(16).padStart(32, '0')}.webp`;
          fixture.uploads.set(url, body); return send(201, { url });
        }
        if (request.method === 'PUT' && pathname === '/api/admin/content') {
          const input = JSON.parse(body.toString('utf8'));
          if (input.revision !== fixture.content.revision) return send(409, { error: 'Fixture revision conflict' });
          const validated = validate(input);
          if (!validated.ok) { fixture.rejectedSaves++; return send(400, validated.value); }
          fixture.saves.push(structuredClone(input)); fixture.content = { ...validated.value, revision: fixture.content.revision + 1 }; return send(200, fixture.content);
        }
        fixture.forbidden.push([request.method, pathname]); return send(503, { error: 'Not permitted by photo fixture' });
      }
      const relative = decodeURIComponent(pathname).replace(/^\/+/, '');
      if (request.method !== 'GET') { fixture.forbidden.push([request.method, pathname]); return send(405, {}); }
      if (fixture.uploads.has(relative)) return send(200, fixture.uploads.get(relative), 'image/webp');
      const file = path.resolve(ROOT, relative);
      const allowed = ['admin.html', 'admin.js', 'cms-data.js', 'catalog-data.js', 'admin.css', 'management.css', 'styles.css'].includes(relative) || /^assets\/[a-zA-Z0-9._/-]+$/.test(relative);
      if (!allowed || !file.startsWith(ROOT + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) return send(404, '');
      const mime = { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript', '.css': 'text/css', '.webp': 'image/webp', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
      return send(200, fs.readFileSync(file), mime[path.extname(file)] || 'application/octet-stream');
    } catch (error) { errors.push(error.stack || error.message); response.writeHead(500); response.end('Photo fixture failed'); }
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const origin = `http://127.0.0.1:${server.address().port}`;
  let browser; let releaseUpload;
  try {
    browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    await context.route('**/*', route => {
      const url = route.request().url(); if (url.startsWith(origin + '/') || /^(?:data|blob):/.test(url)) return route.continue();
      fixture.forbidden.push(['EXTERNAL', new URL(url).origin]); return route.abort();
    });
    const page = await context.newPage(); page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => { nativePrompts++; return dialog.accept(); });
    console.log('Product photos: legacy card, eleven-file rejection and ten real image uploads');
    await page.goto(`${origin}/admin.html?product=${legacy.id}`); await editorReady(page);
    await waitPhotos(page, [...new Set([legacy.image, legacy.altImage])]);
    equal(await page.locator(INPUT).getAttribute('multiple'), '', 'The photo input supports a batch selection');
    await page.goto(`${origin}/admin.html?newProduct=1`); await editorReady(page);
    const id = (await page.locator('[data-cms-select][aria-pressed="true"]').getAttribute('data-cms-select')).replace(/^product:/, '');
    await page.locator('[data-cms-field="title"]').fill('Товар с десятью фотографиями');
    await page.locator('[data-cms-field="description"]').fill('Изолированная проверка фото, их порядка и сохранения.');
    await page.locator('[data-cms-field="price"]').fill('100');
    const bytes = fs.readFileSync(path.join(ROOT, defaults.products[0].image));
    const files = count => Array.from({ length: count }, (_, index) => ({ name: `photo-${index + 1}.webp`, mimeType: 'image/webp', buffer: bytes }));
    await page.locator(INPUT).setInputFiles(files(11));
    await page.locator('[data-cms-field-error="images"]').filter({ hasText: /10/ }).waitFor();
    equal(fixture.uploadCalls, 0, 'Eleven files are rejected before any upload API call');
    equal(await page.locator(PHOTO).count(), 0, 'An invalid oversized batch cannot partially alter the draft');
    check(await page.locator('[data-cms-save]').isDisabled(), 'The rejected photo batch cannot be saved accidentally');
    fixture.uploadGate = new Promise(resolve => { releaseUpload = resolve; });
    const firstUpload = new Promise(resolve => { fixture.onFirstUpload = resolve; });
    await page.locator(INPUT).setInputFiles(files(10));
    await new Promise((resolve, reject) => { const timer = setTimeout(() => reject(new Error('The accepted photo batch did not start uploading')), 10000); firstUpload.then(() => { clearTimeout(timer); resolve(); }); });
    check(await page.locator('[data-cms-save]').isDisabled(), 'Saving is blocked while a batch is uploading');
    equal(await page.locator('[data-cms-workspace]').getAttribute('aria-busy'), 'true', 'Uploading exposes the busy editor state');
    releaseUpload(); fixture.uploadGate = null;
    await page.waitForFunction(selector => document.querySelectorAll(selector).length === 10 && document.querySelector('[data-cms-workspace]')?.getAttribute('aria-busy') === 'false', PHOTO);
    const uploaded = [...fixture.uploads.keys()]; equal(uploaded.length, 10, 'Ten uploads have ten independent durable fixture URLs');
    await waitPhotos(page, uploaded);
    equal(await page.locator('[data-cms-photo-cover]').count(), 1, 'Exactly one first photo is labelled as the cover');
    check((await page.locator('[data-cms-photo-count]').textContent()).includes('10'), 'The editor reports all ten photos');
    check(await page.locator('[data-cms-photo-move="-1"][data-cms-photo-index="0"]').isDisabled(), 'The first photo cannot move before the cover position');
    check(await page.locator('[data-cms-photo-move="1"][data-cms-photo-index="9"]').isDisabled(), 'The last photo cannot move beyond the photo list');
    await noOverflow(page, 390); await noOverflow(page, 320); await page.setViewportSize({ width: 1440, height: 1000 });
    await save(page, id, uploaded); await reopen(page, origin, id, uploaded);

    console.log('Product photos: accessible reorder, cover aliases, removal and persistence');
    let expected = uploaded.slice();
    await page.locator('[data-cms-photo-move="-1"][data-cms-photo-index="9"]').focus(); await page.keyboard.press('Enter');
    [expected[8], expected[9]] = [expected[9], expected[8]]; await waitPhotos(page, expected);
    await page.locator('[data-cms-photo-move="1"][data-cms-photo-index="0"]').click();
    [expected[0], expected[1]] = [expected[1], expected[0]]; await waitPhotos(page, expected);
    await page.locator('[data-cms-photo-preview="0"]').click();
    equal(await page.locator('[data-cms-preview] .cms-mini-product__photo img').evaluate(image => new URL(image.getAttribute('src'), location.href).pathname.replace(/^\/+/, '')), expected[0], 'Changing the first position also changes the actual cover preview');
    const removed = expected[4]; await page.locator('[data-cms-photo-remove="4"]').click(); expected.splice(4, 1); await waitPhotos(page, expected);
    check(!expected.includes(removed), 'Removing a photo preserves the order of the remaining nine');
    await save(page, id, expected); await reopen(page, origin, id, expected);

    console.log('Product photos: failed batch is atomic, one-photo aliases and server maximum');
    await page.locator('[data-cms-photo-remove="8"]').click(); expected.pop(); await waitPhotos(page, expected);
    fixture.failUploadAt = fixture.uploadCalls + 2;
    await page.locator(INPUT).setInputFiles(files(2));
    await page.locator('[data-cms-field-error="images"]').filter({ hasText: /не изменены/ }).waitFor();
    await page.locator('[data-cms-workspace][aria-busy="false"]').waitFor();
    await waitPhotos(page, expected);
    check(await page.locator('[data-cms-save]').isDisabled(), 'A partially failed upload batch requires an explicit decision before save');
    await page.locator('[data-cms-photo-error-dismiss]').click();
    await save(page, id, expected); await reopen(page, origin, id, expected);
    while (expected.length > 1) { await page.locator(`[data-cms-photo-remove="${expected.length - 1}"]`).click(); expected.pop(); }
    await waitPhotos(page, expected); await save(page, id, expected); await reopen(page, origin, id, expected);
    equal([product(id).image, product(id).altImage], [expected[0], expected[0]], 'A remaining single photo is used for both legacy aliases');
    const beforeRejected = structuredClone(fixture.content);
    const oversized = structuredClone(fixture.content); oversized.products.find(item => item.id === id).images = [...uploaded, legacy.image];
    const rejected = await fetch(`${origin}/api/admin/content`, { method: 'PUT', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(oversized) });
    equal(rejected.status, 400, 'The real PHP validator independently rejects eleven photo entries');
    equal(fixture.content, beforeRejected, 'Rejected oversized content cannot change saved products or revision');
    equal(fixture.rejectedSaves, 1, 'The invalid API payload was rejected before persistence');
    equal(fixture.content.sections, defaults.sections, 'The photo workflow preserves every home-page section');
    equal([product(legacy.id).title, product(legacy.id).price, product(legacy.id).image, product(legacy.id).altImage], [legacy.title, legacy.price, legacy.image, legacy.altImage], 'Editing a new photo collection preserves the existing product');
    equal(nativePrompts, 0, 'Saved reloads and photo actions never trigger native leave prompts');
    equal(errors, [], 'The photo editor produces no runtime or fixture errors');
    equal(fixture.forbidden, [], 'Photo editing never requests stock, checkout, bank, or external endpoints');
    check(fixture.calls.every(([method, route]) => !route.startsWith('/api/') || method === 'GET' && ['/api/content', '/api/admin/session'].includes(route) || method === 'POST' && route === '/api/admin/uploads' || method === 'PUT' && route === '/api/admin/content'), 'The workflow uses only the existing authenticated content/upload route contract');
    await context.close();
    console.log(`PASS: ${checks} product-photo browser checks (ten uploads, max eleven rejection, busy state, reorder, cover aliases, remove, save/reopen, atomic upload failure, legacy and single photos, 320/390px)`);
  } finally { releaseUpload?.(); if (browser) await browser.close(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
}
run().catch(error => { console.error(error.stack || error); process.exitCode = 1; });
