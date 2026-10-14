// Temporary demo gateway: exposes only CoopVote pages, public assets and its API.
// Run locally with: node tools/tunnel-demo.cjs
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const https = require('node:https');

const root = path.resolve(__dirname, '..');
const prefix = '/CoopVote';
const publicPaths = new Set([
  prefix + '/index.php', prefix + '/login.html', prefix + '/management.html',
  prefix + '/api/index.php',
]);
for (const directory of ['admin', 'voter']) {
  for (const name of fs.readdirSync(path.join(root, directory))) {
    if (name.endsWith('.html')) publicPaths.add(`${prefix}/${directory}/${name}`);
  }
}
function collectAssets(directory, relative = 'assets') {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    if (entry.isSymbolicLink()) continue;
    const next = relative + '/' + entry.name;
    if (entry.isDirectory()) collectAssets(path.join(directory, entry.name), next);
    else if (/\.(?:css|js|png|svg|jpe?g|webp|gif|ico|woff2?|ttf|xlsx)$/.test(entry.name)) {
      publicPaths.add(prefix + '/' + next);
    }
  }
}
collectAssets(path.join(root, 'assets'));

function route(requestPath) {
  // Reject absolute targets and encoded path tricks before forwarding to Apache.
  if (!requestPath || !requestPath.startsWith('/') || requestPath.startsWith('//')) return 'deny';
  const pathname = requestPath.split('?')[0];
  if (['/', prefix, prefix + '/'].includes(pathname)) return 'redirect';
  return publicPaths.has(pathname) ? 'proxy' : 'deny';
}
function stripHopHeaders(headers) {
  const result = { ...headers };
  const connectionTokens = String(result.connection || '').split(',').map(x => x.trim().toLowerCase());
  for (const name of ['connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
    'te', 'trailer', 'transfer-encoding', 'upgrade', ...connectionTokens]) delete result[name];
  return result;
}
function start() {
  const caPath = process.env.COOPVOTE_DEMO_CA || path.resolve(root, '../../coopvote-secrets/ca.crt');
  const ca = fs.readFileSync(caPath);
  const server = http.createServer((req, res) => {
    const action = route(req.url);
    if (action !== 'proxy') {
      res.writeHead(action === 'redirect' ? 302 : 404, {
        'Cache-Control': 'no-store', 'Content-Type': 'text/plain; charset=utf-8',
        ...(action === 'redirect' ? { Location: prefix + '/login.html' } : {}),
      });
      return res.end(action === 'redirect' ? 'Open the CoopVote login page.' : 'Not found.');
    }
    const headers = stripHopHeaders(req.headers);
    for (const name of Object.keys(headers)) {
      if (name === 'forwarded' || name.startsWith('x-forwarded-') || name.startsWith('cf-')) delete headers[name];
    }
    const upstream = https.request({
      hostname: '127.0.0.1', port: 443, servername: 'localhost', ca,
      rejectUnauthorized: true, method: req.method, path: req.url, headers,
    }, response => {
      res.writeHead(response.statusCode, stripHopHeaders(response.headers));
      response.pipe(res);
    });
    upstream.setTimeout(45000, () => upstream.destroy(new Error('Local server timed out')));
    upstream.on('error', error => {
      console.error('Local HTTPS connection:', error.message);
      if (!res.headersSent) {
        res.writeHead(502, { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store' });
        res.end('Cannot reach local CoopVote over verified HTTPS. Check Apache and the gateway terminal.');
      } else res.destroy();
    });
    req.on('aborted', () => upstream.destroy());
    res.on('close', () => { if (!res.writableFinished) upstream.destroy(); });
    req.pipe(upstream);
  });
  server.on('error', error => { console.error(error.message); process.exitCode = 1; });
  server.listen(8088, '127.0.0.1', () => {
    console.log('CoopVote demo gateway ready: http://127.0.0.1:8088');
    console.log('Next terminal: cloudflared tunnel --url http://127.0.0.1:8088');
    console.log('Open the generated HTTPS URL. Keep both terminals and XAMPP running.');
    console.log('This gateway uses your existing CoopVote database. Demo actions change that database.');
    console.log('Press Ctrl+C to stop.');
  });
}
module.exports = { route, stripHopHeaders };
if (require.main === module) {
  try { start(); } catch (error) { console.error('Cannot start gateway:', error.message); process.exitCode = 1; }
}
