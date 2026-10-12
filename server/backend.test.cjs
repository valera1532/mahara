'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { setTimeout: delay } = require('node:timers/promises');

const ROOT = path.resolve(__dirname, '..');
const PHP = process.env.PHP_BINARY || (process.platform === 'win32' ? path.join(ROOT, '.runtime', 'php', 'php.exe') : 'php');
const PASSWORD = 'test-only-strong-password-2026';

async function unusedPort() {
  const server = net.createServer();
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;
  await new Promise(resolve => server.close(resolve));
  return port;
}

test('PHP backend: authentication, content publication, upload and storage security', { timeout: 90000 }, async t => {
  const privateDir = fs.mkdtempSync(path.join(os.tmpdir(), 'mahara-backend-'));
  const port = await unusedPort();
  const origin = `http://127.0.0.1:${port}`;
  const env = { ...process.env, APP_ENV: 'development', DATA_DIR: privateDir, APP_ORIGIN: origin, MAHARA_ADMIN_USERNAME: 'test-owner', MAHARA_ADMIN_PASSWORD: PASSWORD };
  let server;
  let serverOutput = '';
  const start = async () => {
    server = spawn(PHP, ['-S', `127.0.0.1:${port}`, '-t', ROOT, path.join(ROOT, 'server', 'router.php')], { cwd: ROOT, env, stdio: ['ignore', 'ignore', 'pipe'], windowsHide: true });
    server.stderr.on('data', chunk => { serverOutput = (serverOutput + chunk).slice(-6000); });
    let spawnError;
    server.on('error', error => { spawnError = error; });
    for (let i = 0; i < 80; i++) {
      if (spawnError) throw spawnError;
      try { if ((await fetch(origin + '/api/content')).status === 200) return; } catch {}
      if (server.exitCode !== null) throw new Error(`PHP server stopped: ${serverOutput}`);
      await delay(100);
    }
    throw new Error(`PHP server did not start: ${serverOutput}`);
  };
  const stop = async () => {
    if (!server || server.exitCode !== null) return;
    await new Promise(resolve => { server.once('exit', resolve); server.kill(); });
  };
  t.after(async () => {
    await stop();
    const resolved = fs.realpathSync(privateDir);
    const relative = path.relative(fs.realpathSync(os.tmpdir()), resolved);
    assert.ok(relative && !relative.startsWith('..') && !path.isAbsolute(relative) && path.basename(resolved).startsWith('mahara-backend-'));
    fs.rmSync(resolved, { recursive: true, force: true });
  });
  const runAdmin = (args, overrides = {}) => spawnSync(PHP, [path.join(ROOT, 'tools', 'admin.php'), ...args], { cwd: ROOT, env: { ...env, ...overrides }, encoding: 'utf8', windowsHide: true });
  const created = runAdmin(['create']);
  assert.equal(created.status, 0, created.stderr);
  assert.ok(!created.stdout.includes(PASSWORD));
  await start();

  let cookie;
  let csrfToken;
  let content;
  const request = (route, options = {}) => fetch(origin + route, options);
  const write = (route, method, body, overrides = {}) => request(route, { method, headers: { Origin: origin, Cookie: cookie, 'X-CSRF-Token': csrfToken, 'Content-Type': 'application/json', ...overrides }, body: JSON.stringify(body) });

  await t.test('public content works and protected pages/API require a session', async () => {
    const response = await request('/api/content');
    assert.equal(response.status, 200);
    assert.equal(response.headers.get('cache-control'), 'no-store');
    content = await response.json();
    assert.equal(content.revision, 1);
    assert.ok(content.products.length > 0);
    const admin = await request('/admin.html', { redirect: 'manual' });
    assert.equal(admin.status, 302);
    assert.equal(admin.headers.get('location'), '/admin/login');
    assert.equal((await request('/api/admin/session')).status, 401);
    assert.equal((await request('/api/admin/content', { method: 'PUT' })).status, 401);
    assert.equal((await request('/api/admin/uploads', { method: 'POST' })).status, 401);
    const head = await request('/api/content', { method: 'HEAD' });
    assert.equal(head.status, 200); assert.equal(await head.text(), '');
  });

  await t.test('login checks origin, returns opaque HttpOnly cookie and CSRF token', async () => {
    assert.equal((await request('/api/admin/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: 'test-owner', password: PASSWORD }) })).status, 403);
    const bad = await request('/api/admin/login', { method: 'POST', headers: { Origin: origin, 'Content-Type': 'application/json' }, body: JSON.stringify({ username: 'test-owner', password: 'incorrect' }) });
    assert.equal(bad.status, 401);
    const response = await request('/api/admin/login', { method: 'POST', headers: { Origin: origin, 'Content-Type': 'application/json' }, body: JSON.stringify({ username: 'test-owner', password: PASSWORD }) });
    assert.equal(response.status, 200);
    const header = response.headers.get('set-cookie');
    assert.match(header, /HttpOnly/i); assert.match(header, /SameSite=Strict/i);
    assert.ok(!header.includes(PASSWORD));
    cookie = header.split(';')[0];
    const data = await response.json(); csrfToken = data.csrfToken;
    assert.equal(data.user.username, 'test-owner'); assert.match(csrfToken, /^[a-zA-Z0-9_-]{43}$/);
    const session = await request('/api/admin/session', { headers: { Cookie: cookie } });
    assert.equal((await session.json()).csrfToken, csrfToken);
    const admin = await request('/admin.html', { headers: { Cookie: cookie } });
    assert.equal(admin.status, 200); assert.equal(admin.headers.get('cache-control'), 'no-store');
  });

  await t.test('writes reject missing CSRF and other origins', async () => {
    assert.equal((await write('/api/admin/content', 'PUT', content, { 'X-CSRF-Token': '' })).status, 403);
    assert.equal((await write('/api/admin/content', 'PUT', content, { Origin: 'https://attacker.example' })).status, 403);
    assert.equal((await write('/api/admin/logout', 'POST', {}, { 'X-CSRF-Token': '' })).status, 403);
  });

  await t.test('server validates product identity, dimensions, images, prices and unknown fields', async () => {
    const modifications = [
      value => { value.products[0].title = ''; },
      value => { value.products[0].price = -1; },
      value => { value.products[0].price = 12.5; },
      value => { value.products[0].image = 'data:image/png;base64,AAAA'; },
      value => { value.products[0].altImage = 'https://attacker.example/test.jpg'; },
      value => { value.products[0].width = 100000; },
      value => { value.products[0].sizes = ['NOPE']; },
      value => { value.products[0].url = 'admin.html'; },
      value => { value.products[0].category = 'unknown'; },
      value => { value.products[0].color = 'unknown'; },
      value => { value.products.push(structuredClone(value.products[0])); },
      value => { value.products[0].hiddenScript = '<script>alert(1)</script>'; },
      value => { value.sections.hero.image = 'assets/images/../private.png'; },
    ];
    for (const modify of modifications) {
      const input = structuredClone(content); modify(input);
      assert.equal((await write('/api/admin/content', 'PUT', input)).status, 400);
    }
    assert.equal((await (await request('/api/content')).json()).revision, 1);
  });

  await t.test('uploads validate the actual image and persist outside public assets', async () => {
    const rejected = await request('/api/admin/uploads', { method: 'POST', headers: { Origin: origin, Cookie: cookie, 'X-CSRF-Token': csrfToken, 'Content-Type': 'image/png' }, body: Buffer.from('<svg onload="alert(1)"></svg>') });
    assert.equal(rejected.status, 400);
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=', 'base64');
    const response = await request('/api/admin/uploads', { method: 'POST', headers: { Origin: origin, Cookie: cookie, 'X-CSRF-Token': csrfToken, 'Content-Type': 'image/png' }, body: png });
    assert.equal(response.status, 201);
    const uploaded = (await response.json()).url;
    assert.match(uploaded, /^assets\/uploads\/[a-f0-9]{32}\.png$/);
    assert.ok(fs.existsSync(path.join(privateDir, 'uploads', path.basename(uploaded))));
    assert.ok(!fs.existsSync(path.join(ROOT, uploaded)));
    const image = await request('/' + uploaded);
    assert.equal(image.headers.get('content-type'), 'image/png');
    assert.deepEqual(Buffer.from(await image.arrayBuffer()), png);
    const oversized = await request('/api/admin/uploads', { method: 'POST', headers: { Origin: origin, Cookie: cookie, 'X-CSRF-Token': csrfToken }, body: Buffer.alloc(8 * 1024 * 1024 + 1) });
    assert.equal(oversized.status, 413);
  });

  await t.test('new products publish globally with conflict prevention and survive restart', async () => {
    const input = structuredClone(content);
    const product = { ...input.products[0], id: 'integration-test-shirt', title: 'Integration Test Shirt', url: 'product-integration-test-shirt.html', price: 12345 };
    input.products.push(product);
    const response = await write('/api/admin/content', 'PUT', input);
    assert.equal(response.status, 200);
    const updated = await response.json(); assert.equal(updated.revision, 2);
    assert.equal((await write('/api/admin/content', 'PUT', input)).status, 409);
    assert.ok((await (await request('/api/content')).json()).products.some(item => item.id === product.id));
    const detail = await request('/' + product.url);
    assert.equal(detail.status, 200);
    assert.ok((await detail.text()).includes(product.title));
    const sitemap = await request('/sitemap.xml');
    assert.equal(sitemap.status, 200); assert.ok((await sitemap.text()).includes(product.url));
    assert.ok((await (await request('/robots.txt')).text()).includes('Disallow: /admin'));
    await stop(); await start();
    const persisted = await (await request('/api/content')).json();
    assert.equal(persisted.revision, 2); assert.equal(persisted.products.at(-1).price, 12345);
    assert.equal((await request('/api/admin/session', { headers: { Cookie: cookie } })).status, 200);
  });

  await t.test('private files, source, traversal and unsupported methods are denied', async () => {
    for (const route of ['/data/mahara.sqlite', '/server/app.php', '/tools/admin.php', '/.git/config', '/.htaccess', '/.local/preview-access.txt', '/.runtime/php/php.ini', '/site-defaults.json', '/package.json', '/assets/images/../../server/app.php', '/assets/uploads/../mahara.sqlite']) assert.equal((await request(route)).status, 404, route);
    assert.equal((await request('/api/content', { method: 'POST' })).status, 405);
    assert.equal((await request('/catalog.html', { method: 'POST' })).status, 405);
    assert.equal((await request('/product-no-such-product.html')).status, 404);
    assert.equal((await request('/styles.css', { method: 'HEAD' })).headers.get('x-content-type-options'), 'nosniff');
  });

  await t.test('brute force is throttled by source and username; another user remains usable', async () => {
    let response;
    for (let i = 0; i < 9; i++) response = await request('/api/admin/login', { method: 'POST', headers: { Origin: origin, 'Content-Type': 'application/json' }, body: JSON.stringify({ username: 'unknown-owner', password: 'wrong-password-value' }) });
    assert.equal(response.status, 429); assert.ok(Number(response.headers.get('retry-after')) > 0);
    const success = await request('/api/admin/login', { method: 'POST', headers: { Origin: origin, 'Content-Type': 'application/json' }, body: JSON.stringify({ username: 'test-owner', password: PASSWORD }) });
    assert.equal(success.status, 200);
    cookie = success.headers.get('set-cookie').split(';')[0]; csrfToken = (await success.json()).csrfToken;
  });

  await t.test('logout and password replacement revoke old sessions', async () => {
    assert.equal((await write('/api/admin/logout', 'POST', {})).status, 200);
    assert.equal((await request('/api/admin/session', { headers: { Cookie: cookie } })).status, 401);
    const login = await request('/api/admin/login', { method: 'POST', headers: { Origin: origin, 'Content-Type': 'application/json' }, body: JSON.stringify({ username: 'test-owner', password: PASSWORD }) });
    cookie = login.headers.get('set-cookie').split(';')[0];
    assert.equal(runAdmin(['create', '--replace'], { MAHARA_ADMIN_PASSWORD: 'new-test-password-2026' }).status, 0);
    assert.equal((await request('/api/admin/session', { headers: { Cookie: cookie } })).status, 401);
  });

  await t.test('CLI generates private credentials without printing the secret or overwriting files', async () => {
    const output = path.join(privateDir, 'generated-access.txt');
    const created = runAdmin(['create', '--generate', '--output', output], { MAHARA_ADMIN_USERNAME: '' });
    assert.equal(created.status, 0, created.stderr);
    const secret = fs.readFileSync(output, 'utf8').match(/Password: (.+)/)[1];
    assert.ok(!created.stdout.includes(secret));
    assert.equal(runAdmin(['create', '--generate', '--output', output], { MAHARA_ADMIN_USERNAME: '' }).status, 1);
    const unsafe = runAdmin(['create'], { APP_ENV: 'production', APP_ORIGIN: 'https://example.ru', DATA_DIR: path.join(ROOT, 'data', 'unsafe-production') });
    assert.equal(unsafe.status, 1); assert.match(unsafe.stderr, /outside the public document root/);
  });
});
