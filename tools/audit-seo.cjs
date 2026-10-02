#!/usr/bin/env node
'use strict';

// Audit the served HTML and the browser-visible result without JavaScript.
// No live site, account, or external validator is contacted.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');

const root = path.resolve(__dirname, '..');
const publicBase = new URL(process.env.SITE_URL || require('../seo.config.json').siteUrl);
if (!publicBase.pathname.endsWith('/')) publicBase.pathname += '/';
const failures = [];
let checks = 0;

function check(condition, message) {
  checks += 1;
  try { assert.ok(condition, message); }
  catch (error) { failures.push(error.message); }
}

function htmlFiles(directory = root) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
    if (entry.name.startsWith('.') || ['node_modules', 'preview', 'tools'].includes(entry.name)) return [];
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) return htmlFiles(file);
    return entry.isFile() && entry.name.endsWith('.html') ? [file] : [];
  });
}

function relative(file) { return path.relative(root, file).split(path.sep).join('/'); }
function canonicalFor(file) {
  const route = relative(file);
  return new URL(route === 'index.html' ? '' : route, publicBase).href;
}

function localPath(url) {
  const pathname = decodeURIComponent(url.pathname);
  const basePath = publicBase.pathname;
  const isPublic = url.origin === publicBase.origin;
  const route = isPublic && pathname.startsWith(basePath) ? pathname.slice(basePath.length) : pathname.replace(/^\//, '');
  const file = path.resolve(root, route || 'index.html');
  if (file !== root && !file.startsWith(`${root}${path.sep}`)) throw new Error(`Path escapes project: ${url.href}`);
  return fs.existsSync(file) && fs.statSync(file).isDirectory() ? path.join(file, 'index.html') : file;
}

const mime = {
  '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8', '.json': 'application/json; charset=utf-8',
  '.xml': 'application/xml; charset=utf-8', '.svg': 'image/svg+xml',
  '.webp': 'image/webp', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg'
};

const server = http.createServer((request, response) => {
  try {
    const file = localPath(new URL(request.url, 'http://localhost/'));
    if (!fs.existsSync(file) || !fs.statSync(file).isFile()) {
      response.writeHead(404).end('Not found');
      return;
    }
    response.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream' });
    fs.createReadStream(file).pipe(response);
  } catch {
    response.writeHead(400).end('Invalid path');
  }
});

function schemaNodes(value) {
  if (Array.isArray(value)) return value.flatMap(schemaNodes);
  if (!value || typeof value !== 'object') return [];
  return [value, ...Object.values(value).flatMap(schemaNodes)];
}

function checkReference(reference, pageURL, origin, label, kind = 'link') {
  if (!reference || /^(?:mailto:|tel:|data:)/i.test(reference)) return;
  check(!/^javascript:/i.test(reference), `${label}: executable ${kind} URL`);
  if (/^javascript:/i.test(reference)) return;
  let url;
  try { url = new URL(reference, pageURL); }
  catch { check(false, `${label}: invalid ${kind} URL ${reference}`); return; }
  if (![origin, publicBase.origin].includes(url.origin)) return;
  try {
    const file = localPath(url);
    check(fs.existsSync(file) && fs.statSync(file).isFile(), `${label}: missing internal ${kind} ${reference}`);
    if (url.hash && fs.existsSync(file) && path.extname(file) === '.html') {
      const id = decodeURIComponent(url.hash.slice(1));
      const source = fs.readFileSync(file, 'utf8');
      const escaped = id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      check(new RegExp(`\\bid\\s*=\\s*(["'])${escaped}\\1`).test(source), `${label}: missing anchor ${reference}`);
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
  const files = htmlFiles().sort();
  check(files.length >= 10, `Expected home, catalog and eight product pages; found ${files.length} HTML files`);
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const origin = `http://127.0.0.1:${server.address().port}`;
  const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/valer/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
  const browser = await chromium.launch({
    executablePath: process.env.BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    headless: true
  });
  const titles = new Set();
  const descriptions = new Set();
  const canonicals = new Set();
  const linkedPages = new Set();
  const productCanonicals = new Set();
  let productPages = 0;
  try {
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1440, height: 900 } });
    for (const file of files) {
      const label = relative(file);
      const page = await context.newPage();
      const pageURL = `${origin}/${label}`;
      try {
        const response = await page.goto(pageURL, { waitUntil: 'load' });
        check(response?.status() === 200, `${label}: HTTP status is not 200`);
        const data = await snapshot(page);
        const isAdmin = label === 'admin.html';
        check(data.lang === 'ru', `${label}: document language must be ru`);
        check(data.title.length === 1 && data.title[0], `${label}: exactly one nonempty title required`);
        check(!titles.has(data.title[0]), `${label}: duplicate title`);
        titles.add(data.title[0]);
        if (isAdmin) {
          check(data.robots.some(value => /\b(?:noindex|none)\b/i.test(value)), `${label}: demo administration must have noindex`);
        } else {
          check(data.description.length === 1 && data.description[0]?.trim(), `${label}: exactly one nonempty description required`);
          check(!descriptions.has(data.description[0]), `${label}: duplicate description`);
          descriptions.add(data.description[0]);
          check(data.canonical.length === 1 && data.canonical[0] === canonicalFor(file), `${label}: canonical must be ${canonicalFor(file)}`);
          if (data.canonical[0]) canonicals.add(data.canonical[0]);
          check(!data.robots.some(value => /\b(?:noindex|none)\b/i.test(value)), `${label}: indexable page has noindex`);
          for (const property of ['og:title', 'og:description', 'og:type', 'og:url', 'og:image']) {
            check(Boolean(data.og[property]?.trim()), `${label}: missing ${property}`);
          }
          check(data.og['og:url'] === data.canonical[0], `${label}: og:url differs from canonical`);
          check(data.twitterCard.length === 1 && ['summary', 'summary_large_image'].includes(data.twitterCard[0]), `${label}: missing supported twitter:card`);
          if (data.og['og:image']) {
            check(data.og['og:image'].startsWith(publicBase.href), `${label}: og:image must use the production base URL`);
            checkReference(data.og['og:image'], pageURL, origin, label, 'OG image');
          }
          check(data.jsonLD.length > 0, `${label}: no static JSON-LD`);
          let pageHasProduct = false;
          for (const source of data.jsonLD) {
            try {
              const nodes = schemaNodes(JSON.parse(source));
              pageHasProduct ||= nodes.some(node => [node['@type']].flat().includes('Product'));
              check(!nodes.some(node => [node['@type']].flat().some(type => ['Offer', 'AggregateOffer', 'Review', 'AggregateRating'].includes(type))), `${label}: unconfirmed commerce/review schema`);
              check(!nodes.some(node => ['offers', 'review', 'aggregateRating'].some(key => Object.hasOwn(node, key))), `${label}: unconfirmed offers/reviews in structured data`);
            } catch (error) { check(false, `${label}: JSON-LD parse error: ${error.message}`); }
          }
          if (pageHasProduct) {
            productPages += 1;
            productCanonicals.add(data.canonical[0]);
          }
        }
        check(data.mainCount === 1 && data.mainVisible, `${label}: one visible main required without JavaScript`);
        check(data.h1.length === 1 && data.h1[0].text && data.h1[0].visible, `${label}: one visible, nonempty H1 required without JavaScript`);
        check(data.headingLevels.every((level, index, levels) => !index || level <= levels[index - 1] + 1), `${label}: main heading sequence skips a level`);
        check(data.duplicateIDs.length === 0, `${label}: duplicate IDs ${data.duplicateIDs.join(', ')}`);
        for (const ref of data.ariaReferences) check(ref.exists, `${label}: ${ref.attribute} references missing #${ref.id}`);
        for (const item of data.ariaBooleans) {
          check(['true', 'false', ...(item.attribute === 'aria-pressed' ? ['mixed'] : [])].includes(item.value), `${label}: invalid ${item.attribute}=${item.value}`);
        }
        for (const item of data.labels) check(item.exists, `${label}: label refers to missing #${item.id}`);
        check(data.inaccessibleInputs.length === 0, `${label}: unlabeled visible form controls ${data.inaccessibleInputs.join('; ')}`);
        check(data.orphanTabLists === 0, `${label}: tablist without tab children`);
        for (const href of data.hrefs) {
          check(Boolean(href.trim()), `${label}: empty href`);
          check(href !== '#', `${label}: placeholder href="#"`);
          checkReference(href, pageURL, origin, label);
          try {
            const linkURL = new URL(href, canonicalFor(file));
            linkURL.hash = '';
            if (linkURL.origin === publicBase.origin) linkedPages.add(linkURL.href);
          } catch { /* The reference check above reports invalid links. */ }
        }
        for (const candidate of data.srcsets) checkReference(candidate, pageURL, origin, label, 'responsive image');
        for (const resource of data.resources) checkReference(resource, pageURL, origin, label, 'resource');
        for (const image of data.images) {
          check(image.alt !== null, `${label}: image missing alt (${image.src || 'dynamic image'})`);
          check(Number(image.width) > 0 && Number(image.height) > 0, `${label}: image missing intrinsic dimensions (${image.src || 'dynamic image'})`);
          if (image.src) checkReference(image.src, pageURL, origin, label, 'image');
          if (image.belowFold && image.priority !== 'high') check(image.loading === 'lazy', `${label}: below-fold image is not lazy (${image.src})`);
          if (image.src && image.loading !== 'lazy') check(image.complete && image.naturalWidth > 0, `${label}: eager image did not decode (${image.src})`);
        }
        check(!data.preloaderVisible, `${label}: preloader blocks the page without JavaScript`);
        if (label === 'index.html') check(data.featuredCards === 4, `${label}: expected four static featured products, found ${data.featuredCards}`);
        if (label === 'catalog.html') check(data.catalogCards === 8, `${label}: expected eight static catalog products, found ${data.catalogCards}`);
        console.log(`Audited without JS: ${label}`);
      } catch (error) { check(false, `${label}: browser audit failed: ${error.message}`); }
      finally { await page.close(); }
    }
    await context.close();
    check(productPages === 8, `Expected eight dedicated Product pages, found ${productPages}`);
    for (const canonical of productCanonicals) check(linkedPages.has(canonical), `Product page has no crawlable internal link: ${canonical}`);

    const sitemapFile = path.join(root, 'sitemap.xml');
    check(fs.existsSync(sitemapFile), 'Missing sitemap.xml');
    if (fs.existsSync(sitemapFile)) {
      const xml = fs.readFileSync(sitemapFile, 'utf8');
      const locations = [...xml.matchAll(/<loc>\s*([^<]+)\s*<\/loc>/g)].map(match => match[1].trim().replace(/&amp;/g, '&'));
      check(xml.includes('http://www.sitemaps.org/schemas/sitemap/0.9'), 'Sitemap namespace missing');
      check(locations.length === canonicals.size && new Set(locations).size === locations.length, 'Sitemap has missing or duplicate canonical pages');
      for (const location of locations) check(canonicals.has(location), `Sitemap contains a noncanonical URL: ${location}`);
      for (const canonical of canonicals) check(locations.includes(canonical), `Canonical missing from sitemap: ${canonical}`);
    }
    const robotsFile = path.join(root, 'robots.txt');
    check(fs.existsSync(robotsFile), 'Missing robots.txt');
    if (fs.existsSync(robotsFile)) {
      const robots = fs.readFileSync(robotsFile, 'utf8');
      check(!/^\s*Disallow:\s*\/\s*$/mi.test(robots), 'robots.txt blocks the whole site');
      check(robots.includes(`Sitemap: ${new URL('sitemap.xml', publicBase).href}`), 'robots.txt must reference the production sitemap');
      check(!/^\s*Disallow:\s*\/(?:assets|script\.js|styles\.css)/mi.test(robots), 'robots.txt blocks essential rendering resources');
    }

    const enhanced = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
    for (const file of files) {
      const label = relative(file);
      const page = await enhanced.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      const failedResponses = [];
      page.on('response', response => { if (response.status() >= 400) failedResponses.push(`${response.status()} ${response.url()}`); });
      try {
        await page.goto(`${origin}/${label}`, { waitUntil: 'load' });
        await page.waitForTimeout(2000);
        const data = await snapshot(page);
        check(errors.length === 0, `${label}: JavaScript errors: ${errors.join('; ')}`);
        check(failedResponses.length === 0, `${label}: failed requests: ${failedResponses.join('; ')}`);
        check(data.h1.length === 1 && data.h1[0].visible, `${label}: enhanced page lost its visible H1`);
        check(data.duplicateIDs.length === 0, `${label}: JavaScript introduced duplicate IDs`);
        for (const ref of data.ariaReferences) check(ref.exists, `${label}: enhanced ${ref.attribute} references missing #${ref.id}`);
        if (label === 'index.html') check(data.featuredCards === 4, `${label}: enhanced featured grid changed count`);
        if (label === 'catalog.html') check(data.catalogCards === 8, `${label}: enhanced catalog changed count`);
      } catch (error) { check(false, `${label}: enhanced browser audit failed: ${error.message}`); }
      finally { await page.close(); }
    }
    await enhanced.close();
  } finally { await browser.close(); }

  console.log(`\n${checks} checks across ${files.length} pages; ${failures.length} failures`);
  if (failures.length) {
    failures.forEach(message => console.error(`FAIL: ${message}`));
    process.exitCode = 1;
  } else {
    console.log('PASS: static metadata, local links, schema, sitemap, no-JS content and browser loading');
  }
}

run().catch(error => { console.error(error); process.exitCode = 1; }).finally(() => server.close());
