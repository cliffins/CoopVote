// Exercise real streamed HTML: a fast localhost response hides parser-time flashes.
const assert = require('node:assert/strict');
const http = require('node:http');
const { chromium } = require(process.env.COOPVOTE_PLAYWRIGHT_MODULE || 'playwright');
const upstream = new URL(process.env.COOPVOTE_TEST_URL);
assert.equal(upstream.hostname, '127.0.0.1');

(async () => {
  const proxy = http.createServer((req, res) => {
    const request = http.request(new URL(req.url, upstream), {
      method: req.method, headers: { ...req.headers, host: upstream.host },
    }, response => {
      const chunks = [];
      response.on('data', chunk => chunks.push(chunk));
      response.on('end', () => {
        const body = Buffer.concat(chunks);
        const html = body.toString();
        const start = html.indexOf('<aside class="sidebar"');
        const end = html.indexOf('<script>CoopVoteNavigation.bootstrap(', start);
        const headers = { ...response.headers };
        delete headers['content-length'];
        delete headers['transfer-encoding'];
        res.writeHead(response.statusCode, headers);
        if (start < 0 || end < 0) return res.end(body);
        // Stop once the default branding is parsed, then again before hydration.
        const logoEnd = html.indexOf('</div>', html.indexOf('class="logo-text"', start)) + 6;
        const first = logoEnd > start && logoEnd < end ? logoEnd : end;
        res.write(html.slice(0, first));
        setTimeout(() => {
          res.write(html.slice(first, end));
          setTimeout(() => res.end(html.slice(end)), 200);
        }, 200);
      });
    });
    request.on('error', () => { res.statusCode = 502; res.end(); });
    req.pipe(request);
  });
  await new Promise(resolve => proxy.listen(0, '127.0.0.1', resolve));
  const origin = 'http://127.0.0.1:' + proxy.address().port;
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 650 } });
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const login = await context.request.post(origin + '/api/index.php?r=auth/login', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      data: { username: 'testadmin', password: 'EndpointTest9!' },
    });
    assert.equal(login.status(), 200);
    const brandingUrl = origin + '/api/index.php?r=branding';
    const original = await (await context.request.get(brandingUrl)).json();
    const branding = { text: 'Sidebar Test Brand', coopName: 'Sidebar Test Cooperative', logo: null };
    assert.equal((await context.request.put(brandingUrl, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }, data: branding,
    })).status(), 200);
    try {
      await context.addInitScript(() => {
        window.sidebarFrames = [];
        function sample() {
          const side = document.querySelector('.sidebar');
          if (side) {
            const brand = side.querySelector('.logo-text');
            const link = side.querySelector('.nav-item');
            window.sidebarFrames.push({
              brand: brand?.textContent,
              name: side.querySelector('#sidebar-name')?.textContent,
              visible: !!brand && getComputedStyle(brand).visibility === 'visible',
              count: side.querySelectorAll('.nav-item').length,
              scroll: side.scrollTop,
              y: link?.getBoundingClientRect().y,
            });
          }
          requestAnimationFrame(sample);
        }
        requestAnimationFrame(sample);
      });
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.goto(origin + '/admin/dashboard.php');
      await page.waitForFunction(() => !!window.CoopVote?.user);
      // Keep all three management links on screen so clicking cannot auto-scroll.
      await page.evaluate(() => { document.querySelector('.sidebar').scrollTop = 120; });
      await page.waitForTimeout(50);
      for (const module of ['election', 'candidate', 'voter', 'candidate', 'election']) {
        await page.locator('.sidebar a[href$="' + module + '-management.php"]').click();
        await page.waitForURL(origin + '/admin/' + module + '-management.php');
        await page.waitForFunction(() => !!window.CoopVote?.user);
        await page.waitForTimeout(100);
        const frames = await page.evaluate(() => sidebarFrames);
        assert.ok(frames.length > 0, 'Captured streamed frames');
        const visible = frames.filter(frame => frame.visible);
        assert.ok(visible.length > 0, 'Initialized sidebar becomes visible');
        for (const frame of visible) {
          assert.equal(frame.brand, branding.text, module + ': no default brand flash');
          assert.equal(frame.name, 'testadmin', module + ': no default account flash');
          assert.equal(frame.count, 12, module + ': no incomplete menu flash');
          assert.equal(frame.scroll, 120, module + ': no scroll reset while parsing');
          assert.equal(frame.y, 65, module + ': menu stays in the same position');
        }
      }
      assert.deepEqual(errors, []);
      console.log('Streamed sidebar: PASS (real Election/Candidate/Voter menu clicks, custom branding, account, first visible frame and scroll)');
    } finally {
      await context.request.put(brandingUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, data: original });
      await context.close();
    }
  } finally {
    await browser.close();
    await new Promise(resolve => proxy.close(resolve));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
