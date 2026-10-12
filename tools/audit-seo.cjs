#!/usr/bin/env node
'use strict';

// Audit an isolated PHP deployment and its published routes, never the live site.
// The copied deployment excludes credentials/data and disables outgoing PHP I/O.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const { setTimeout: delay } = require('node:timers/promises');

const root = path.resolve(__dirname, '..');
const publicBase = new URL(process.env.SITE_URL || require('../seo.config.json').siteUrl);
assert.ok(['https:', 'http:'].includes(publicBase.protocol) && publicBase.pathname === '/' && !publicBase.search && !publicBase.hash && !publicBase.username && !publicBase.password, 'SITE_URL must be a site origin');
const php = process.env.PHP_BINARY || (fs.existsSync(path.join(root, '.runtime/php/php.exe')) ? path.join(root, '.runtime/php/php.exe') : 'php');
const phpSafety = ['-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,curl_multi_exec,mail,stream_socket_client,fsockopen,pfsockopen'];
const failures = [];
let checks = 0;

function check(condition, message) {
  checks += 1;
  try { assert.ok(condition, message); }
  catch (error) { failures.push(error.message); }
}

async function freePort() {
  const listener = net.createServer();
  await new Promise((resolve, reject) => { listener.once('error', reject); listener.listen(0, '127.0.0.1', resolve); });
  const port = listener.address().port;
  await new Promise(resolve => listener.close(resolve));
  return port;
}

function canonicalFor(route) {
  return new URL(route === '/' || route === '/index.html' ? '' : route.replace(/^\//, ''), publicBase).href;
}

function phpString(value) { return "'" + String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"; }
function copyDeployment(target) {
  fs.mkdirSync(path.join(target, 'server'), { recursive: true });
  for (const entry of fs.readdirSync(root, { withFileTypes: true })) {
    if (entry.isFile() && (/\.(?:html|css|js)$/.test(entry.name) || ['site-defaults.json', 'seo.config.json', 'index.php'].includes(entry.name))) fs.copyFileSync(path.join(root, entry.name), path.join(target, entry.name));
  }
  for (const entry of fs.readdirSync(path.join(root, 'server'), { withFileTypes: true })) {
    if (entry.isFile() && entry.name.endsWith('.php') && entry.name !== 'config.local.php') fs.copyFileSync(path.join(root, 'server', entry.name), path.join(target, 'server', entry.name));
  }
  if (fs.existsSync(path.join(root, 'server/templates'))) fs.cpSync(path.join(root, 'server/templates'), path.join(target, 'server/templates'), { recursive: true });
  for (const directory of ['images', 'icons']) if (fs.existsSync(path.join(root, 'assets', directory))) fs.cpSync(path.join(root, 'assets', directory), path.join(target, 'assets', directory), { recursive: true });
}

function cleanup(directory) {
  const actual = fs.realpathSync(directory);
  const relative = path.relative(fs.realpathSync(os.tmpdir()), actual);
  assert.ok(relative && !relative.startsWith('..') && !path.isAbsolute(relative) && path.basename(actual).startsWith('mahara-seo-audit-'), 'Cleanup must stay in this isolated temporary directory');
  fs.rmSync(actual, { recursive: true, force: true });
}
async function stopServer(server) {
  if (!server || server.exitCode !== null) return;
  await new Promise((resolve, reject) => {
    const timeout = setTimeout(() => reject(new Error('Isolated PHP server did not exit')), 5000);
    server.once('exit', () => { clearTimeout(timeout); resolve(); });
    server.kill();
  });
}

function schemaNodes(value) {
  if (Array.isArray(value)) return value.flatMap(schemaNodes);
  if (!value || typeof value !== 'object') return [];
  return [value, ...Object.values(value).flatMap(schemaNodes)];
}

const references = new Map();
async function checkReference(reference, pageURL, origin, label, kind = 'link') {
  if (!reference || /^(?:mailto:|tel:|data:)/i.test(reference)) return;
  check(!/^javascript:/i.test(reference), `${label}: executable ${kind} URL`);
  if (/^javascript:/i.test(reference)) return;
  let url;
  try { url = new URL(reference, pageURL); }
  catch { check(false, `${label}: invalid ${kind} URL ${reference}`); return; }
  if (![origin, publicBase.origin].includes(url.origin)) return;
  try {
    const requestURL = origin + url.pathname + url.search;
    if (!references.has(requestURL)) references.set(requestURL, (async () => {
      const response = await fetch(requestURL, { redirect: 'manual' });
      const html = response.headers.get('content-type')?.includes('text/html') ? await response.text() : (await response.arrayBuffer(), '');
      return { status: response.status, html };
    })());
    const result = await references.get(requestURL);
    check(result.status === 200 || result.status === 302, `${label}: missing internal ${kind} ${reference} (HTTP ${result.status})`);
    if (url.hash && result.status === 200 && result.html) {
      const id = decodeURIComponent(url.hash.slice(1));
      const escaped = id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      check(new RegExp(`\\bid\\s*=\\s*(["'])${escaped}\\1`).test(result.html), `${label}: missing anchor ${reference}`);
    }
  } catch (error) { check(false, `${label}: ${error.message}`); }
}

async function snapshot(page) {
  return page.evaluate(() => {
    const all = selector => Array.from(document.querySelectorAll(selector));
    const one = selector => document.querySelector(selector);
    const content = selector => all(selector).map(node => node.content);
    const visible = node => {
      if (!node || !node.getBoundingClientRect().width || !node.getBoundingClientRect().height) return false;
      for (let current = node; current; current = current.parentElement) {
        const style = getComputedStyle(current);
        if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0) return false;
      }
      return true;
    };
    const inaccessibleInputs = all('input:not([type="hidden"]), select, textarea').filter(node => {
      if (!visible(node) || node.closest('[inert]')) return false;
      return !node.labels?.length && !node.getAttribute('aria-label') && !node.getAttribute('aria-labelledby');
    }).map(node => node.outerHTML.slice(0, 200));
    return {
      lang: document.documentElement.lang,
      title: all('title').map(node => node.textContent.trim()),
      description: content('meta[name="description"]'),
      canonical: all('link[rel="canonical"]').map(node => node.href),
      robots: content('meta[name="robots"]'),
      og: Object.fromEntries(all('meta[property^="og:"]').map(node => [node.getAttribute('property'), node.content])),
      twitterCard: content('meta[name="twitter:card"]'),
      jsonLD: all('script[type="application/ld+json"]').map(node => node.textContent),
      mainCount: all('main').length,
      mainVisible: visible(one('main')),
      h1: all('h1').map(node => ({ text: node.textContent.trim(), visible: visible(node) })),
      headingLevels: all('main h1, main h2, main h3, main h4, main h5, main h6').map(node => Number(node.tagName.slice(1))),
      duplicateIDs: all('[id]').map(node => node.id).filter((id, index, ids) => ids.indexOf(id) !== index),
      ariaReferences: all('[aria-controls], [aria-labelledby], [aria-describedby]').flatMap(node =>
        ['aria-controls', 'aria-labelledby', 'aria-describedby'].flatMap(attribute =>
          (node.getAttribute(attribute) || '').trim().split(/\s+/).filter(Boolean).map(id => ({ attribute, id, exists: Boolean(document.getElementById(id)) }))
        )
      ),
      ariaBooleans: all('[aria-expanded], [aria-pressed], [aria-hidden]').flatMap(node =>
        ['aria-expanded', 'aria-pressed', 'aria-hidden'].filter(attribute => node.hasAttribute(attribute)).map(attribute => ({
          attribute, value: node.getAttribute(attribute), tag: node.tagName
        }))
      ),
      labels: all('label[for]').map(node => ({ id: node.htmlFor, exists: Boolean(document.getElementById(node.htmlFor)) })),
      inaccessibleInputs,
      orphanTabLists: all('[role="tablist"]').filter(node => !node.querySelector('[role="tab"]')).length,
      hrefs: all('a[href]').map(node => node.getAttribute('href')),
      srcsets: all('img[srcset], source[srcset]').flatMap(node => node.getAttribute('srcset').split(',').map(candidate => candidate.trim().split(/\s+/)[0])),
      resources: all('script[src], link[rel="stylesheet"][href], link[rel="icon"][href]').map(node => node.getAttribute('src') || node.getAttribute('href')),
      images: all('img').map(node => ({
        src: node.getAttribute('src'), alt: node.getAttribute('alt'),
        width: node.getAttribute('width'), height: node.getAttribute('height'),
        loading: node.loading, priority: node.fetchPriority,
        belowFold: visible(node) && node.getBoundingClientRect().top > innerHeight,
        complete: node.complete, naturalWidth: node.naturalWidth
      })),
      featuredCards: all('[data-product-grid="featured"] .product-card').length,
      catalogCards: all('[data-product-grid="catalog"] .product-card').length,
      preloaderVisible: visible(one('[data-preloader]')),
      jsOnlyVisible: all('[data-js-only]').filter(visible).length
    };
  });
}

async function run() {
  const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'mahara-seo-audit-'));
  const deployment = path.join(temporary, 'site');
  const privateData = path.join(temporary, 'private');
  let server;
  let browser;
  let serverOutput = '';
  const externalRequests = [];
  try {
    copyDeployment(deployment);
    fs.mkdirSync(privateData, { recursive: true });
    // Never inherit application credentials/configuration from the user's shell.
    const env = Object.fromEntries(Object.entries(process.env).filter(([key]) => /^(?:PATH|SystemRoot|WINDIR|COMSPEC|PATHEXT|TEMP|TMP)$/i.test(key)));
    Object.assign(env, { APP_ENV: 'development', APP_ORIGIN: publicBase.origin, DATA_DIR: privateData,
      MAHARA_TBANK_SECRETS: path.join(privateData, 'absent-tbank.php'),
      MAHARA_CDEK_SECRETS: path.join(privateData, 'absent-cdek.php'),
      MAHARA_MAIL_SECRETS: path.join(privateData, 'absent-mail.php') });
    fs.writeFileSync(path.join(deployment, 'server/config.local.php'), `<?php return ['root'=>${phpString(deployment)},'data_dir'=>${phpString(privateData)},'origin'=>${phpString(publicBase.origin)},'production'=>false,'payments'=>['enabled'=>false,'receipt_enabled'=>false],'shipping'=>['enabled'=>false],'mail'=>['enabled'=>false],'merchant'=>['published'=>false,'legalVersion'=>'']];`);
    const seed = JSON.parse(fs.readFileSync(path.join(deployment, 'site-defaults.json'), 'utf8'));
    const dynamicID = 'audit-' + randomBytes(8).toString('hex');
    seed.products.push({ ...seed.products[0], id: dynamicID, url: `product-${dynamicID}.html`, title: 'Опубликованная модель для аудита', description: 'Проверка маршрута товара из базы данных, которого нет среди статических HTML.', composition: 'Тестовый состав <>&', care: '', manufacturer: '', measurements: '' });
    seed.revision = 17;
    const seedFile = path.join(temporary, 'seed.json');
    fs.writeFileSync(seedFile, JSON.stringify(seed));
    const initializer = path.join(temporary, 'seed.php');
    fs.writeFileSync(initializer, `<?php define('MAHARA_LIBRARY_ONLY',true); require $argv[1].'/server/app.php'; $input=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR); $content=mahara_validate_content($input); mahara_query('INSERT INTO content(id,revision,document,updated_at) VALUES(1,?,?,?)',[17,json_encode($content,JSON_THROW_ON_ERROR),time()]);`);
    const seeded = spawnSync(php, [...phpSafety, initializer, deployment, seedFile], { cwd: deployment, env, encoding: 'utf8', windowsHide: true });
    assert.equal(seeded.status, 0, `Isolated content seed failed: ${seeded.stderr || seeded.error || ''}`);
    const port = await freePort();
    const origin = `http://127.0.0.1:${port}`;
    server = spawn(php, [...phpSafety, '-S', `127.0.0.1:${port}`, '-t', deployment, path.join(deployment, 'server/router.php')], { cwd: deployment, env, windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
    let spawnError;
    server.on('error', error => { spawnError = error; });
    server.stderr.on('data', chunk => { serverOutput = (serverOutput + chunk).slice(-10000); });
    let published;
    for (let attempt = 0; attempt < 100; attempt += 1) {
      if (spawnError) throw spawnError;
      if (server.exitCode !== null) throw new Error(`Isolated server stopped: ${serverOutput}`);
      try {
        const response = await fetch(origin + '/api/content');
        if (response.ok) { published = await response.json(); break; }
      } catch {}
      await delay(100);
    }
    assert.ok(published, `Isolated PHP server did not start: ${serverOutput}`);
    check(published.revision === 17 && published.products.some(product => product.id === dynamicID), 'Audit uses its uniquely seeded database, not a preview or production database');
    check(!fs.existsSync(path.join(deployment, `product-${dynamicID}.html`)), 'Published dynamic product has no static HTML fallback');
    const publicRoutes = ['/', '/catalog.html', ...published.products.map(product => '/' + product.url)];
    const legalRoutes = ['/privacy.html', '/terms.html', '/delivery.html', '/returns.html', '/contacts.html'];
    const auxiliaryRoutes = [...legalRoutes, '/checkout.html', '/admin/login'];
    const routes = [...publicRoutes, ...auxiliaryRoutes];
    const publicSet = new Set(publicRoutes);
    const titles = new Set();
    const descriptions = new Set();
    const canonicals = new Set();
    const linkedPages = new Set();
    const productCanonicals = new Set();
    const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/valer/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
    browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    async function context(options) {
      const value = await browser.newContext(options);
      await value.route('**/*', route => {
        const url = new URL(route.request().url());
        if (url.origin === origin || ['data:', 'blob:'].includes(url.protocol)) return route.continue();
        externalRequests.push(url.origin + url.pathname);
        return route.abort();
      });
      return value;
    }
    const noJS = await context({ javaScriptEnabled: false, viewport: { width: 1440, height: 900 } });
    for (const route of routes) {
      const page = await noJS.newPage();
      const pageURL = origin + route;
      try {
        const response = await page.goto(pageURL, { waitUntil: 'load' });
        check(response?.status() === 200, `${route}: HTTP status must be 200`);
        const data = await snapshot(page);
        const indexable = publicSet.has(route);
        check(data.lang === 'ru', `${route}: document language must be ru`);
        check(data.title.length === 1 && Boolean(data.title[0]), `${route}: one nonempty title required`);
        check(!titles.has(data.title[0]), `${route}: duplicate title`);
        titles.add(data.title[0]);
        if (indexable) {
          check(data.description.length === 1 && Boolean(data.description[0]?.trim()), `${route}: one nonempty description required`);
          check(!descriptions.has(data.description[0]), `${route}: duplicate description`);
          descriptions.add(data.description[0]);
          check(data.canonical.length === 1 && data.canonical[0] === canonicalFor(route), `${route}: wrong canonical URL`);
          if (data.canonical[0]) canonicals.add(data.canonical[0]);
          check(!data.robots.some(value => /\b(?:noindex|none)\b/i.test(value)), `${route}: published storefront must be indexable`);
          for (const property of ['og:title', 'og:description', 'og:type', 'og:url', 'og:image']) check(Boolean(data.og[property]?.trim()), `${route}: missing ${property}`);
          check(data.og['og:url'] === data.canonical[0], `${route}: og:url differs from canonical`);
          check(data.twitterCard.length === 1 && ['summary', 'summary_large_image'].includes(data.twitterCard[0]), `${route}: missing supported twitter:card`);
          check(data.og['og:image']?.startsWith(publicBase.href), `${route}: og:image must use the configured public origin`);
          await checkReference(data.og['og:image'], pageURL, origin, route, 'OG image');
          check(data.jsonLD.length > 0, `${route}: server-rendered JSON-LD required`);
          let productSchema;
          for (const source of data.jsonLD) {
            try {
              const nodes = schemaNodes(JSON.parse(source));
              productSchema ||= nodes.find(node => [node['@type']].flat().includes('Product'));
              check(!nodes.some(node => [node['@type']].flat().some(type => ['Review', 'AggregateRating'].includes(type))), `${route}: fabricated reviews or ratings`);
              // The isolated audit has no confirmed inventory: it must not claim availability.
              check(!nodes.some(node => ['offers', 'review', 'aggregateRating'].some(key => Object.hasOwn(node, key))), `${route}: unconfirmed offers/reviews in structured data`);
            } catch (error) { check(false, `${route}: JSON-LD parse error: ${error.message}`); }
          }
          if (route.startsWith('/product-')) {
            const product = published.products.find(item => '/' + item.url === route);
            check(Boolean(productSchema) && productSchema.name === product.title, `${route}: Product schema must use the published product`);
            check(data.h1[0]?.text === product.title, `${route}: SSR product title differs from published content`);
            productCanonicals.add(data.canonical[0]);
          }
          for (const legal of legalRoutes) check(data.hrefs.some(href => new URL(href, pageURL).pathname === legal), `${route}: footer is missing ${legal}`);
        } else {
          check(data.robots.some(value => /\b(?:noindex|none)\b/i.test(value)), `${route}: draft/transactional page must have noindex`);
          check(!data.canonical.length, `${route}: draft/transactional page must not claim an indexable canonical`);
          if (legalRoutes.includes(route)) check(await page.locator('.legal-draft').count() === 0, `${route}: internal draft notice must not be shown`);
          if (['/checkout.html', '/admin/login'].includes(route)) {
            check(response.headers()['cache-control']?.includes('no-store'), `${route}: private flow must use no-store`);
            check((await page.locator('noscript').textContent()).includes('JavaScript'), `${route}: explain JavaScript requirement`);
          }
        }
        check(data.mainCount === 1 && data.mainVisible, `${route}: one visible main required without JavaScript`);
        check(data.h1.length === 1 && data.h1[0].text && data.h1[0].visible, `${route}: one visible nonempty H1 required without JavaScript`);
        check(data.headingLevels.every((level, index, levels) => !index || level <= levels[index - 1] + 1), `${route}: main headings skip a level`);
        check(data.duplicateIDs.length === 0, `${route}: duplicate IDs ${data.duplicateIDs.join(', ')}`);
        for (const ref of data.ariaReferences) check(ref.exists, `${route}: ${ref.attribute} references missing #${ref.id}`);
        for (const item of data.ariaBooleans) check(['true', 'false', ...(item.attribute === 'aria-pressed' ? ['mixed'] : [])].includes(item.value), `${route}: invalid ${item.attribute}=${item.value}`);
        for (const item of data.labels) check(item.exists, `${route}: label refers to missing #${item.id}`);
        check(data.inaccessibleInputs.length === 0, `${route}: unlabeled visible controls ${data.inaccessibleInputs.join('; ')}`);
        check(data.orphanTabLists === 0, `${route}: tablist without tabs`);
        for (const href of data.hrefs) {
          check(Boolean(href.trim()) && href !== '#', `${route}: empty or placeholder href`);
          await checkReference(href, pageURL, origin, route);
          try {
            const linkURL = new URL(href, canonicalFor(route));
            linkURL.hash = ''; linkURL.search = '';
            if (linkURL.origin === publicBase.origin) linkedPages.add(linkURL.href);
          } catch {}
        }
        for (const reference of data.srcsets) await checkReference(reference, pageURL, origin, route, 'responsive image');
        for (const resource of data.resources) await checkReference(resource, pageURL, origin, route, 'resource');
        for (const image of data.images) {
          check(image.alt !== null, `${route}: image missing alt (${image.src})`);
          check(Number(image.width) > 0 && Number(image.height) > 0, `${route}: image missing intrinsic dimensions (${image.src})`);
          if (image.src) await checkReference(image.src, pageURL, origin, route, 'image');
          if (image.belowFold && image.priority !== 'high') check(image.loading === 'lazy', `${route}: below-fold image is not lazy (${image.src})`);
          if (image.src && image.loading !== 'lazy') check(image.complete && image.naturalWidth > 0, `${route}: eager image did not decode (${image.src})`);
        }
        check(!data.preloaderVisible, `${route}: preloader blocks content without JavaScript`);
        check(data.jsOnlyVisible === 0, `${route}: unusable JavaScript controls are visible without JavaScript`);
        if (route === '/') check(data.featuredCards === Math.min(4, published.products.length), 'Home featured count differs from published content');
        if (route === '/catalog.html') check(data.catalogCards === published.products.length, 'Catalog count differs from published content');
        await page.setViewportSize({ width: 390, height: 844 });
        const dimensions = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth }));
        check(dimensions.scroll <= dimensions.width + 1, `${route}: horizontal overflow without JS at 390px (${dimensions.scroll}px)`);
        console.log(`Audited PHP without JS: ${route}`);
      } catch (error) { check(false, `${route}: browser audit failed: ${error.message}`); }
      finally { await page.close(); }
    }
    await noJS.close();
    check(productCanonicals.size === published.products.length, 'Every published product has a dedicated Product page');
    for (const canonical of productCanonicals) check(linkedPages.has(canonical), `Product page has no crawlable internal link: ${canonical}`);
    const sitemapResponse = await fetch(origin + '/sitemap.xml');
    check(sitemapResponse.status === 200 && sitemapResponse.headers.get('content-type').includes('xml'), 'Dynamic sitemap is served as XML');
    const xml = await sitemapResponse.text();
    const locations = [...xml.matchAll(/<loc>\s*([^<]+)\s*<\/loc>/g)].map(match => match[1].trim().replace(/&amp;/g, '&'));
    check(xml.includes('http://www.sitemaps.org/schemas/sitemap/0.9'), 'Sitemap namespace missing');
    check(locations.length === canonicals.size && new Set(locations).size === locations.length, 'Sitemap has missing or duplicate canonical pages');
    for (const location of locations) check(canonicals.has(location), `Sitemap contains a draft/private/noncanonical URL: ${location}`);
    for (const canonical of canonicals) check(locations.includes(canonical), `Canonical missing from sitemap: ${canonical}`);
    check(locations.includes(canonicalFor('/product-' + dynamicID + '.html')), 'Dynamic published product is present in the sitemap');
    const robotsResponse = await fetch(origin + '/robots.txt');
    check(robotsResponse.status === 200, 'Dynamic robots.txt is served');
    const robots = await robotsResponse.text();
    check(!/^\s*Disallow:\s*\/\s*$/mi.test(robots), 'robots.txt blocks the whole site');
    check(robots.includes(`Sitemap: ${new URL('sitemap.xml', publicBase).href}`), 'robots.txt references the configured sitemap');
    check(!/^\s*Disallow:\s*\/(?:assets|script\.js|styles\.css)/mi.test(robots), 'robots.txt blocks essential rendering resources');
    for (const route of ['/admin', '/checkout', '/inventory.html', '/orders.html', '/api/', '/server/', '/tests/', '/tools/']) check(robots.includes('Disallow: ' + route), `robots.txt is missing private path ${route}`);
    for (const route of ['/admin.html', '/inventory.html', '/orders.html']) {
      const response = await fetch(origin + route, { redirect: 'manual' });
      check(response.status === 302 && response.headers.get('location') === '/admin/login', `${route}: anonymous request must redirect to login`);
      check(response.headers.get('cache-control')?.includes('no-store'), `${route}: protected response must not be cached`);
    }
    for (const route of ['/server/config.local.php', '/site-defaults.json', '/.local/tbank-secrets.php', '/data/mahara.sqlite', '/product-unpublished-audit.html']) check((await fetch(origin + route)).status === 404, `${route}: private or unpublished file must not be public`);
    check((await fetch(origin + '/api/admin/session')).status === 401, 'Anonymous administrative API must reject access');
    const paymentConfig = await (await fetch(origin + '/api/checkout/config')).json();
    check(paymentConfig.enabled === false, 'Isolated audit has online payment disabled');
    const shippingConfig = await (await fetch(origin + '/api/shipping/config')).json();
    check(shippingConfig.enabled === false, 'Isolated audit has CDEK disabled');
    const availability = await (await fetch(origin + '/api/catalog/availability')).json();
    check(availability.products.length === published.products.length && availability.products.every(product => product.variants.every(variant => variant.status === 'untracked' && variant.maxQty === 0)), 'Unknown inventory must not pretend to be available');
    const enhanced = await context({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
    const enhancedRoutes = ['/', '/catalog.html', '/product-' + dynamicID + '.html', '/privacy.html', '/checkout.html', '/admin/login'];
    for (const route of enhancedRoutes) {
      const page = await enhanced.newPage();
      const errors = []; const failedResponses = []; const mutations = [];
      page.on('pageerror', error => errors.push(error.message));
      page.on('response', response => {
        const pathname = new URL(response.url()).pathname;
        if (route === '/admin/login' && pathname === '/api/admin/session' && response.status() === 401) return;
        if (response.status() >= 400) failedResponses.push(`${response.status()} ${pathname}`);
      });
      page.on('request', request => { if (!['GET', 'HEAD'].includes(request.method())) mutations.push(request.method() + ' ' + new URL(request.url()).pathname); });
      try {
        await page.goto(origin + route, { waitUntil: 'load', timeout: 15000 });
        await page.waitForFunction(() => !document.querySelector('[data-preloader]') || getComputedStyle(document.querySelector('[data-preloader]')).visibility === 'hidden' || getComputedStyle(document.querySelector('[data-preloader]')).display === 'none', null, { timeout: 5000 }).catch(() => {});
        const data = await snapshot(page);
        check(errors.length === 0, `${route}: JavaScript errors: ${errors.join('; ')}`);
        check(failedResponses.length === 0, `${route}: failed requests: ${failedResponses.join('; ')}`);
        check(mutations.length === 0, `${route}: read-only audit triggered a mutation: ${mutations.join('; ')}`);
        check(data.h1.length === 1 && data.h1[0].visible, `${route}: enhanced page lost H1`);
        check(data.duplicateIDs.length === 0, `${route}: JS introduced duplicate IDs`);
        check(!data.preloaderVisible, `${route}: enhanced preloader blocks content`);
        for (const ref of data.ariaReferences) check(ref.exists, `${route}: enhanced ${ref.attribute} references missing #${ref.id}`);
        const dimensions = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth }));
        check(dimensions.scroll <= dimensions.width + 1, `${route}: horizontal overflow with JS at 390px (${dimensions.scroll}px)`);
        if (route === '/catalog.html') check(data.catalogCards === published.products.length, 'Enhanced catalog count differs from published content');
        console.log(`Audited PHP with JS at 390px: ${route}`);
      } catch (error) { check(false, `${route}: enhanced browser audit failed: ${error.message}`); }
      finally { await page.close(); }
    }
    await enhanced.close();
    check(externalRequests.length === 0, `Unexpected external requests were blocked: ${externalRequests.join('; ')}`);
    console.log(`\n${checks} checks across ${routes.length} served pages; ${failures.length} failures`);
    if (failures.length) {
      failures.forEach(message => console.error(`FAIL: ${message}`));
      process.exitCode = 1;
    } else console.log('PASS: isolated PHP metadata, published dynamic routes, legal links/drafts, sitemap/robots, protected routes, accessibility and mobile loading');
  } finally {
    if (browser) await browser.close();
    await stopServer(server);
    cleanup(temporary);
  }
}

run().catch(error => { console.error(error); process.exitCode = 1; });
