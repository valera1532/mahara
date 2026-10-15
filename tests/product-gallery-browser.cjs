#!/usr/bin/env node
'use strict';

// Real PHP SSR + shipped gallery assets, with local GET-only API fixtures.
// The renderer loads no app/config/DB. A separate real app.php asset-dispatch
// probe uses a complete in-memory fixture config and a disposable data path;
// neither path reads the working private configuration/storage or calls providers.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const { setTimeout: delay } = require('node:timers/promises');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/valer/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const ROOT = path.resolve(__dirname, '..');
const bundledPHP = path.join(ROOT, '.runtime', 'php', process.platform === 'win32' ? 'php.exe' : 'php');
const PHP = process.env.PHP_BINARY || (fs.existsSync(bundledPHP) ? bundledPHP : 'php');
const defaults = JSON.parse(fs.readFileSync(path.join(ROOT, 'site-defaults.json'), 'utf8'));
const multi = { ...defaults.products[0], id: 'gallery-dynamic-fixture', url: 'product-gallery-dynamic-fixture.html', title: 'Динамический товар для галереи', sizes: ['S', 'M'] };
const single = { ...multi, id: 'gallery-single-fixture', url: 'product-gallery-single-fixture.html', title: 'Товар с одной фотографией', altImage: multi.image };
assert.notEqual(multi.image, multi.altImage, 'Two-photo fixture must contain different images');
const fixture = { content: { ...structuredClone(defaults), products: [multi, single, structuredClone(defaults.products[0])], revision: 1 }, calls: [], forbidden: [] };
const errors = [];
const GALLERY = '[data-product-gallery]';
const SLIDES = '.product-gallery__viewport > .swiper-wrapper > .swiper-slide';
let checks = 0;
const check = (condition, message) => { checks++; assert.ok(condition, message); };
const equal = (actual, expected, message) => { checks++; assert.deepEqual(actual, expected, message); };
const savedCart = page => page.evaluate(() => JSON.parse(localStorage.getItem('maharaCart') || '[]'));

function render(route) {
  const php = 'require $argv[1] . "/server/render.php"; $content=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); $html=renderPage($argv[2],$content); if(!is_string($html))exit(4); echo $html;';
  const result = spawnSync(PHP, ['-r', php, ROOT, route], { cwd: ROOT, input: JSON.stringify(fixture.content), encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 });
  assert.equal(result.status, 0, `Isolated PHP renderer failed: ${result.error?.message || result.stderr}`);
  assert.match(result.stdout, /<!doctype html>/i, 'SSR fixture must use the actual HTML renderer');
  return result.stdout;
}

async function ready(page, id = multi.id) {
  await page.locator('body.is-ready').waitFor();
  await page.locator(`${GALLERY}[data-gallery-ready="true"]`).waitFor();
  await page.waitForFunction(id => window.MaharaCatalog?.products.some(product => product.id === id) && !document.querySelector('[data-product-detail] [data-add-cart]')?.disabled, id);
}

async function index(page, expected, label) {
  await page.waitForFunction(expected => document.querySelector('[data-product-gallery]')?.dataset.galleryIndex === String(expected), expected);
  equal(await page.locator(`${GALLERY} [data-gallery-current]`).textContent(), String(expected + 1).padStart(2, '0'), `${label}: counter follows the displayed photo`);
  equal(await page.locator(`${GALLERY} [data-gallery-thumb][aria-pressed="true"]`).getAttribute('data-gallery-thumb'), String(expected), `${label}: exactly one matching thumbnail is selected`);
  equal(await page.locator(`${GALLERY} [data-gallery-thumb][aria-pressed="true"]`).count(), 1, `${label}: selection is accessible and unique`);
  equal(await page.locator(`${GALLERY} :is(.swiper-slide-active, .is-gallery-active) img`).evaluate(image => new URL(image.getAttribute('src'), location.href).pathname), '/' + [multi.image, multi.altImage][expected].replace(/^\/+/, ''), `${label}: active slide contains the correct actual image`);
}

async function noOverflow(page, label, waitForFrames = true) {
  // A disabled-JavaScript context can evaluate synchronously but never runs rAF.
  if (waitForFrames) await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth, gallery: document.querySelector('[data-product-gallery]').getBoundingClientRect().width }));
  check(dimensions.document <= dimensions.viewport + 1 && dimensions.gallery <= dimensions.viewport + 1, `${label}: gallery and page fit the viewport ${JSON.stringify(dimensions)}`);
}

async function swipe(page, direction) {
  const viewport = page.locator(`${GALLERY} .product-gallery__viewport`);
  await viewport.scrollIntoViewIfNeeded();
  const box = await viewport.boundingBox();
  assert.ok(box && box.width > 100, 'Swipe requires a visible, usable gallery viewport');
  const bounds = await page.evaluate(() => ({ width: innerWidth, height: innerHeight }));
  const left = Math.max(20, box.x + box.width * .18);
  const right = Math.min(bounds.width - 20, box.x + box.width * .82);
  const y = Math.min(bounds.height - 30, Math.max(30, box.y + box.height * .45));
  const start = direction === 'left' ? right : left;
  const end = direction === 'left' ? left : right;
  const client = await page.context().newCDPSession(page);
  try {
    await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: start, y }] });
    for (let step = 1; step <= 8; step++) {
      await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: start + (end - start) * step / 8, y }] });
      await delay(15);
    }
    await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  } finally { await client.detach(); }
}

async function dispatchAssets() {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'mahara-gallery-dispatch-'));
  const listener = net.createServer();
  await new Promise((resolve, reject) => { listener.once('error', reject); listener.listen(0, '127.0.0.1', resolve); });
  const port = listener.address().port; await new Promise(resolve => listener.close(resolve));
  const origin = `http://127.0.0.1:${port}`;
  const encoded = value => Buffer.from(value).toString('base64');
  const router = path.join(directory, 'router.php');
  fs.writeFileSync(router, `<?php define('MAHARA_LIBRARY_ONLY',true); $root=base64_decode('${encoded(ROOT)}'); $GLOBALS['mahara_app_test_config']=['root'=>$root,'data_dir'=>base64_decode('${encoded(path.join(directory, 'data'))}'),'origin'=>'${origin}','production'=>false,'trust_proxy'=>false,'merchant'=>['published'=>false],'payments'=>['enabled'=>false],'shipping'=>['enabled'=>false],'mail'=>['enabled'=>false]]; $GLOBALS['mahara_payments_test_secrets']=['terminal_key'=>'FIXTURE','password'=>'FIXTURE']; $GLOBALS['mahara_shipping_test_secrets']=['account'=>'','password'=>'']; $GLOBALS['mahara_mail_test_secrets']=['username'=>'','password'=>'']; require $root.'/server/app.php'; mahara_dispatch();`);
  let output = '';
  const server = spawn(PHP, ['-S', `127.0.0.1:${port}`, router], { cwd: ROOT, env: { ...process.env, APP_ENV: 'development' }, stdio: ['ignore', 'pipe', 'pipe'] });
  server.stdout.on('data', value => { output += value; }); server.stderr.on('data', value => { output += value; });
  let startError; server.on('error', error => { startError = error; });
  try {
    let available = false;
    for (let attempt = 0; attempt < 40; attempt++) {
      if (startError || server.exitCode !== null) throw new Error(`Isolated PHP dispatcher failed: ${startError?.message || output}`);
      try { const response = await fetch(`${origin}/product-gallery.css`, { signal: AbortSignal.timeout(1000) }); await response.arrayBuffer(); if (response.status === 200) { available = true; break; } } catch { /* Local process is still starting. */ }
      await delay(50);
    }
    check(available, 'Isolated real PHP dispatcher starts without the working configuration or database');
    for (const [asset, mime] of [['product-gallery.css', 'text/css'], ['product-gallery.js', 'application/javascript'], ['assets/vendor/swiper/swiper-bundle.min.css', 'text/css'], ['assets/vendor/swiper/swiper-bundle.min.js', 'application/javascript']]) {
      const response = await fetch(`${origin}/${asset}`, { signal: AbortSignal.timeout(5000) });
      equal(response.status, 200, `Real PHP dispatcher permits the exact public gallery asset ${asset}`);
      check(response.headers.get('content-type')?.startsWith(mime), `${asset}: correct browser MIME type`);
      equal(Buffer.from(await response.arrayBuffer()), fs.readFileSync(path.join(ROOT, asset)), `${asset}: actual served bytes match the local asset`);
    }
    for (const asset of ['assets/vendor/swiper/LICENSE', 'assets/vendor/swiper/SOURCE.json', 'assets/vendor/swiper/unknown.js']) {
      const response = await fetch(`${origin}/${asset}`, { signal: AbortSignal.timeout(5000) }); await response.arrayBuffer();
      equal(response.status, 404, `Real PHP dispatcher rejects non-public vendor path ${asset}`);
    }
  } finally {
    if (server.exitCode === null) await new Promise((resolve, reject) => { const timeout = setTimeout(() => reject(new Error('Isolated PHP dispatcher did not stop')), 5000); server.once('exit', () => { clearTimeout(timeout); resolve(); }); server.kill(); });
    const temp = fs.realpathSync(os.tmpdir()); const actual = fs.realpathSync(directory); const relative = path.relative(temp, actual);
    assert.ok(relative && !relative.startsWith('..') && !path.isAbsolute(relative) && path.basename(actual).startsWith('mahara-gallery-dispatch-'), 'Cleanup must stay inside this isolated temp directory');
    fs.rmSync(actual, { recursive: true, force: true });
  }
}

async function run() {
  for (const asset of ['assets/vendor/swiper/swiper-bundle.min.js', 'assets/vendor/swiper/swiper-bundle.min.css']) assert.ok(fs.existsSync(path.join(ROOT, asset)), `Gallery assets must be ready before running: ${asset}`);
  const server = http.createServer((request, response) => {
    try {
      const pathname = new URL(request.url, 'http://localhost').pathname;
      fixture.calls.push([request.method, pathname]);
      const send = (code, body, type = 'application/json') => { response.writeHead(code, { 'Content-Type': type, 'Cache-Control': 'no-store' }); response.end(request.method === 'HEAD' ? '' : body); };
      if (!['GET', 'HEAD'].includes(request.method)) { fixture.forbidden.push([request.method, pathname]); return send(405, '{}'); }
      if (pathname === '/api/content') return send(200, JSON.stringify(fixture.content));
      if (pathname === '/api/catalog/availability') return send(200, JSON.stringify({ products: fixture.content.products.map(product => ({ id: product.id, variants: product.sizes.map(size => ({ size, status: 'available', maxQty: 5 })) })) }));
      if (pathname.startsWith('/api/')) { fixture.forbidden.push([request.method, pathname]); return send(503, '{}'); }
      if ([`/${multi.url}`, `/${single.url}`].includes(pathname)) return send(200, render(pathname), 'text/html; charset=utf-8');
      const relative = decodeURIComponent(pathname).replace(/^\/+/, '');
      const file = path.resolve(ROOT, relative);
      const allowed = relative === 'product-hoodie-black.html' || ['script.js', 'catalog-data.js', 'cms-data.js', 'product-gallery.js', 'product-gallery.css', 'styles.css'].includes(relative) || /^assets\/[a-zA-Z0-9._/-]+$/.test(relative);
      if (!allowed || !file.startsWith(ROOT + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) return send(404, '');
      const mime = { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript', '.css': 'text/css', '.webp': 'image/webp', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
      return send(200, fs.readFileSync(file), mime[path.extname(file)] || 'application/octet-stream');
    } catch (error) { errors.push(error.stack || error.message); response.writeHead(500); response.end('Fixture failed'); }
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const origin = `http://127.0.0.1:${server.address().port}`;
  let browser;
  const contexts = [];
  try {
    browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    async function context(options) {
      const value = await browser.newContext(options); contexts.push(value);
      await value.route('**/*', route => {
        const url = route.request().url();
        if (url.startsWith(origin + '/') || url.startsWith('data:')) return route.continue();
        fixture.forbidden.push(['EXTERNAL', new URL(url).origin]); return route.abort();
      });
      value.on('page', page => page.on('pageerror', error => errors.push(error.message)));
      return value;
    }

    console.log('Gallery: desktop and generated static markup');
    const desktop = await context({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'no-preference' });
    const page = await desktop.newPage(); await page.goto(`${origin}/${multi.url}`); await ready(page);
    equal(await page.locator('[data-product-detail]').getAttribute('data-product-id'), multi.id, 'Real SSR renders a new dynamic product, not an existing static product');
    equal(await page.locator('#product-title').textContent(), multi.title, 'Published dynamic title survives hydration');
    equal(await page.locator(`${GALLERY} ${SLIDES}`).count(), 2, 'Distinct published photos produce two real slides');
    equal(await page.locator(`${GALLERY} [data-gallery-total]`).textContent(), '02', 'Counter reports the actual photo total');
    check(await page.locator(`${GALLERY} [data-gallery-prev]`).isDisabled(), 'Previous is disabled at the first photo');
    await index(page, 0, 'Initial'); await noOverflow(page, 'Desktop1440');
    await page.locator(`${GALLERY} [data-gallery-next]`).click(); await index(page, 1, 'Next');
    check(await page.locator(`${GALLERY} [data-gallery-next]`).isDisabled(), 'Next is disabled at the last photo');
    await page.locator(`${GALLERY} [data-gallery-thumb="0"]`).click(); await index(page, 0, 'Thumbnail');
    await page.locator(GALLERY).focus(); await page.keyboard.press('End'); await index(page, 1, 'End');
    await page.keyboard.press('Home'); await index(page, 0, 'Home');
    await page.keyboard.press('ArrowRight'); await index(page, 1, 'Right arrow');
    await page.keyboard.press('ArrowLeft'); await index(page, 0, 'Left arrow');
    await page.locator('[data-size-choice="M"]').focus(); await page.keyboard.press('ArrowRight');
    equal(await page.locator(GALLERY).getAttribute('data-gallery-index'), '0', 'Keyboard navigation outside the gallery does not move photos');

    await page.goto(`${origin}/product-hoodie-black.html`); await ready(page, 'hoodie-black');
    await page.locator(`${GALLERY} [data-gallery-next]`).click();
    await page.waitForFunction(() => document.querySelector('[data-product-gallery]')?.dataset.galleryIndex === '1');
    check(true, 'Generated static product markup also initializes with the shipped gallery assets');

    console.log('Gallery: touch 390/320px, reduced motion, cart and CMS refresh');
    for (const width of [390, 320]) {
      const mobile = await context({ viewport: { width, height: 844 }, isMobile: true, hasTouch: true, reducedMotion: 'reduce' });
      const touch = await mobile.newPage(); await touch.goto(`${origin}/${multi.url}`); await ready(touch);
      check(await touch.evaluate(() => navigator.maxTouchPoints > 0), `${width}px: uses a real touch-enabled context`);
      await noOverflow(touch, `${width}px initial`);
      await swipe(touch, 'left'); await index(touch, 1, `${width}px left swipe`);
      const transitions = await touch.locator(`${GALLERY} .swiper-wrapper, ${GALLERY} .swiper-slide`).evaluateAll(nodes => nodes.flatMap(node => getComputedStyle(node).transitionDuration.split(',')).map(value => Number.parseFloat(value)));
      check(transitions.every(value => value === 0), `${width}px: reduced-motion navigation has no animated transition`);
      await swipe(touch, 'right'); await index(touch, 0, `${width}px right swipe`);
      await touch.locator(`${GALLERY} [data-gallery-thumb="1"]`).tap(); await index(touch, 1, `${width}px touch thumbnail`);
      await noOverflow(touch, `${width}px after swipe`);
      if (width === 390) {
        await touch.locator('[data-size-choice="M"]').tap(); await touch.locator('[data-product-detail] [data-add-cart]').tap();
        await touch.waitForFunction(id => JSON.parse(localStorage.getItem('maharaCart') || '[]').some(item => item.id === id && item.size === 'M' && item.qty === 1), multi.id);
        await touch.locator('.cart-trigger').tap(); await touch.locator('[data-cart-drawer][aria-hidden="false"]').waitFor();
        await touch.locator('[data-cart-delta="1"]').tap();
        await touch.waitForFunction(() => JSON.parse(localStorage.getItem('maharaCart'))[0]?.qty === 2);
        await touch.locator('[data-cart-delta="-1"]').tap();
        await touch.waitForFunction(() => JSON.parse(localStorage.getItem('maharaCart'))[0]?.qty === 1);
        equal(await savedCart(touch), [{ id: multi.id, size: 'M', qty: 1 }], 'Gallery touch interactions preserve actual cart size and quantity controls');
        await touch.locator('.cart-close').tap();
        multi.title = 'Обновлённый динамический товар'; fixture.content.revision++;
        await touch.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await touch.locator('#product-title').filter({ hasText: multi.title }).waitFor();
        await index(touch, 1, 'Unchanged CMS photos preserve the current image');
        multi.title = 'Новые фотографии динамического товара'; multi.altImage = defaults.products.find(product => product.image !== multi.image && product.image !== multi.altImage).image; fixture.content.revision++;
        await touch.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await touch.locator('#product-title').filter({ hasText: multi.title }).waitFor();
        await touch.waitForFunction(source => { const image = document.querySelectorAll('[data-product-gallery] .swiper-slide img')[1]; return image && new URL(image.getAttribute('src'), location.href).pathname === '/' + source.replace(/^\/+/, ''); }, multi.altImage);
        await index(touch, 0, 'Changed CMS photos reset to the first image');
        equal(await touch.locator('[data-size-choice].is-active').getAttribute('data-size-choice'), 'M', 'Refreshing gallery photos preserves the chosen product size');
        equal(await touch.locator(`${GALLERY} .product-gallery__viewport`).count(), 1, 'CMS refresh does not duplicate the gallery viewport');
        await touch.locator(`${GALLERY} [data-gallery-next]`).tap(); await index(touch, 1, 'CMS gallery rebuild');
        equal(await savedCart(touch), [{ id: multi.id, size: 'M', qty: 1 }], 'CMS photo refresh does not change the saved cart');
      }
      await mobile.close();
    }

    console.log('Gallery: single photo, failed vendor and disabled JavaScript');
    const one = await desktop.newPage(); await one.goto(`${origin}/${single.url}`); await ready(one, single.id);
    equal(await one.locator(`${GALLERY} ${SLIDES}`).count(), 1, 'Identical primary/alternate URLs produce one photo');
    equal(await one.locator(`${GALLERY} [data-gallery-prev], ${GALLERY} [data-gallery-next], ${GALLERY} [data-gallery-thumb], ${GALLERY} [data-gallery-current]`).count(), 0, 'A single photo has no nonfunctional navigation or counter');
    check(await one.locator(`${GALLERY} img`).first().evaluate(image => image.complete && image.naturalWidth > 0), 'The single photo decodes successfully');

    const failedVendor = await context({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
    await failedVendor.route('**/assets/vendor/swiper/swiper-bundle.min.js*', route => route.abort());
    const fallbackJS = await failedVendor.newPage(); await fallbackJS.goto(`${origin}/${multi.url}`); await ready(fallbackJS);
    equal(await fallbackJS.locator(GALLERY).getAttribute('data-gallery-mode'), 'fallback', 'A failed Swiper download selects the usable local gallery fallback');
    await fallbackJS.locator(`${GALLERY} [data-gallery-next]`).click(); await index(fallbackJS, 1, 'Vendor failure next');
    await fallbackJS.locator(`${GALLERY} [data-gallery-thumb="0"]`).click(); await index(fallbackJS, 0, 'Vendor failure thumbnail');
    await fallbackJS.locator(GALLERY).focus(); await fallbackJS.keyboard.press('End'); await index(fallbackJS, 1, 'Vendor failure keyboard');
    await noOverflow(fallbackJS, '390px with Swiper unavailable');

    const noJS = await context({ viewport: { width: 320, height: 844 }, javaScriptEnabled: false });
    const fallback = await noJS.newPage(); await fallback.goto(`${origin}/${multi.url}`);
    const first = fallback.locator(`${GALLERY} ${SLIDES} img`).first();
    check(await first.isVisible() && await first.evaluate(image => image.complete && image.naturalWidth > 0), 'Without JavaScript the SSR primary photo remains visible and decoded');
    equal(await first.getAttribute('src'), multi.image, 'The no-JavaScript fallback shows the published primary photo');
    equal(await first.getAttribute('fetchpriority'), 'high', 'Primary SSR image retains its loading priority');
    await noOverflow(fallback, '320px without JavaScript', false);
    console.log('Gallery: isolated real PHP asset dispatch');
    await dispatchAssets();
    equal(errors, [], 'Gallery interactions produce no browser/runtime/fixture errors');
    equal(fixture.forbidden, [], 'Gallery verification never attempts mutation APIs or external requests');
    check(fixture.calls.filter(([, pathname]) => pathname.startsWith('/api/')).every(([method, pathname]) => method === 'GET' && ['/api/content', '/api/catalog/availability'].includes(pathname)), 'Only local read-only content and stock APIs were used');
    const previews = path.join(ROOT, '.local', 'product-gallery-preview'); fs.mkdirSync(previews, { recursive: true });
    await page.locator(GALLERY).focus(); await page.keyboard.press('Home');
    await page.screenshot({ path: path.join(previews, 'product-gallery-desktop.png'), fullPage: false, animations: 'disabled' });
    const previewContext = await context({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, reducedMotion: 'reduce' });
    const preview = await previewContext.newPage(); await preview.goto(`${origin}/product-hoodie-black.html`); await ready(preview, 'hoodie-black');
    await preview.screenshot({ path: path.join(previews, 'product-gallery-mobile.png'), fullPage: false, animations: 'disabled' });
    console.log(`PASS: ${checks} product-gallery browser checks (actual SSR, static markup, touch, keyboard, thumbnails, reduced motion, CMS refresh, cart, single photo, vendor failure, no-JS, 320/390/1440px)`);
  } finally { for (const value of contexts) await value.close().catch(() => {}); if (browser) await browser.close(); await new Promise(resolve => server.close(resolve)); }
}
run().catch(error => { console.error(error.stack || error); process.exitCode = 1; });
