/**
 * Local preview server for the PHP storefront, running PHP 8.3 compiled to
 * WebAssembly (@php-wasm/node). This exists only because the authoring sandbox
 * has no native PHP binary. On a normal machine use:
 *
 *   php -S 0.0.0.0:8080 -t site/public site/public/index.php
 *
 * Usage:  node tools/preview.mjs [port]
 */
import { createServer } from 'node:http';
import { readFile, readdir, stat } from 'node:fs/promises';
import { extname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const SITE = resolve(fileURLToPath(new URL('..', import.meta.url)), 'site');
const APP_ROOT = '/app';
const PORT = Number(process.argv[2] || process.env.PORT || 8080);

const MIME = {
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.webp': 'image/webp',
};

const RUNNER = `<?php
$req = json_decode(file_get_contents('/tmp/req.json'), true) ?: [];
$_SERVER = $req['server'];
$_GET = $req['get'] ?? [];
$_POST = $req['post'] ?? [];
$_COOKIE = $req['cookies'] ?? [];
$_REQUEST = array_merge($_GET, $_POST);
$_FILES = [];
$_SERVER['REQUEST_METHOD'] = $req['method'];
$_SERVER['REQUEST_URI'] = $req['uri'];

register_shutdown_function(function () {
    $body = '';
    while (ob_get_level() > 0) { $body = ob_get_clean() . $body; }
    file_put_contents('/tmp/res.json', json_encode([
        'code'    => http_response_code(),
        'headers' => headers_list(),
        'body'    => $body,
    ], JSON_UNESCAPED_UNICODE));
});

ob_start();
require '${APP_ROOT}/public/index.php';
`;

async function walk(dir) {
  const out = [];
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) out.push(...(await walk(full)));
    else if (entry.isFile()) out.push(full);
  }
  return out;
}

const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));

// Copy the site into the WASM filesystem: directories first, then files.
const files = await walk(SITE);
const dirs = new Set(['/tmp', APP_ROOT, `${APP_ROOT}/db`]);
for (const f of files) {
  const rel = relative(SITE, f);
  const target = `${APP_ROOT}/${rel}`;
  dirs.add(target.slice(0, target.lastIndexOf('/')));
}
await php.run({
  code: `<?php foreach (${JSON.stringify([...dirs])} as $d) { if (!is_dir($d)) @mkdir($d, 0777, true); } echo "ok";`,
});
const created = await php.run({
  code: `<?php foreach (${JSON.stringify([...dirs])} as $d) { echo is_dir($d) ? "1" : "0"; }`,
});
if (!String(created.text || '').includes('1')) throw new Error('mkdir failed');

for (const f of files) {
  const rel = relative(SITE, f);
  await php.writeFile(`${APP_ROOT}/${rel}`, await readFile(f));
}
await php.writeFile('/tmp/runner.php', RUNNER);

// Install the database on first boot.
const needsInstall = !(await php.fileExists(`${APP_ROOT}/db/app.sqlite`));
if (needsInstall) {
  const out = await php.run({ code: `<?php chdir('${APP_ROOT}'); ob_start(); include '${APP_ROOT}/bin/install.php'; file_put_contents('/tmp/install.log', ob_get_clean()); echo 'done';` });
  let log = '';
  try { log = await php.readFileAsText('/tmp/install.log'); } catch { log = '(no installer log)'; }
  console.log('[preview] installer output:\n' + log);
  if (out.errors) console.error('[preview] installer errors:', out.errors);
}

// Requests are serialised: one PHP instance, one execution at a time.
let queue = Promise.resolve();
function serial(task) {
  const run = queue.then(task, task);
  queue = run.catch(() => {});
  return run;
}

const server = createServer((req, res) => {
  serial(async () => {
    try {
      const url = new URL(req.url, 'http://local');
      const path = decodeURIComponent(url.pathname);

      // Static files are served straight from disk.
      const ext = extname(path);
      if (ext && MIME[ext] && path.startsWith('/assets/')) {
        const file = join(SITE, 'public', path);
        try {
          const body = await readFile(file);
          res.writeHead(200, { 'Content-Type': MIME[ext], 'Cache-Control': 'no-store' });
          res.end(body);
          return;
        } catch {
          res.writeHead(404).end('not found');
          return;
        }
      }

      const chunks = [];
      for await (const c of req) chunks.push(c);
      const rawBody = Buffer.concat(chunks).toString('utf8');

      const post = {};
      if ((req.headers['content-type'] || '').includes('application/x-www-form-urlencoded')) {
        for (const [k, v] of new URLSearchParams(rawBody)) post[k] = v;
      }
      const get = {};
      for (const [k, v] of url.searchParams) get[k] = v;

      const cookies = {};
      for (const part of (req.headers.cookie || '').split(';')) {
        const i = part.indexOf('=');
        if (i > 0) cookies[part.slice(0, i).trim()] = decodeURIComponent(part.slice(i + 1).trim());
      }

      await php.writeFile('/tmp/req.json', JSON.stringify({
        method: req.method,
        uri: req.url,
        server: {
          HTTPS: '',
          HTTP_HOST: req.headers.host || `localhost:${PORT}`,
          SERVER_NAME: 'localhost',
          SERVER_PORT: String(PORT),
          QUERY_STRING: url.search.slice(1),
          REQUEST_URI: req.url,
          HTTP_USER_AGENT: req.headers['user-agent'] || '',
          HTTP_COOKIE: req.headers.cookie || '',
          HTTP_X_SIGNATURE: req.headers['x-signature'] || '',
          REMOTE_ADDR: req.socket.remoteAddress || '127.0.0.1',
        },
        get,
        post,
        cookies,
      }));

      await php.run({ code: `<?php $r = include '/tmp/runner.php';` });
      const out = JSON.parse(await php.readFileAsText('/tmp/res.json'));

      const headers = {};
      const setCookies = [];
      for (const line of out.headers || []) {
        const idx = line.indexOf(':');
        if (idx < 0) continue;
        const name = line.slice(0, idx).trim();
        const value = line.slice(idx + 1).trim();
        if (/^set-cookie$/i.test(name)) setCookies.push(value);
        else if (!/^(content-type)$/i.test(name)) headers[name] = value;
      }
      const ct = (out.headers || []).find((h) => /^content-type:/i.test(h));
      headers['Content-Type'] = ct ? ct.split(':').slice(1).join(':').trim() : 'text/html; charset=utf-8';
      if (setCookies.length) headers['Set-Cookie'] = setCookies;

      res.writeHead(out.code || 200, headers);
      res.end(out.body || '');
    } catch (err) {
      console.error('[preview] error', err);
      res.writeHead(500, { 'Content-Type': 'text/plain' });
      res.end('Preview harness error: ' + String(err));
    }
  });
});

server.listen(PORT, '0.0.0.0', () => {
  console.log(`[preview] PixelTopUp running at http://0.0.0.0:${PORT}`);
});
