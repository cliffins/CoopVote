// Responsive checks use real page guards and synthetic read-only display data.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.COOPVOTE_PLAYWRIGHT_MODULE || 'playwright');
const api = process.env.COOPVOTE_TEST_URL;
assert.equal(new URL(api).hostname, '127.0.0.1');
const origin = new URL(api).origin;
const failures = [];
function check(condition, message) { if (!condition) failures.push(message); }
const election = { title: 'Cooperative Annual Election', totalVoters: 1250, votesCast: 725, startDate: '2026-10-19', endDate: '2026-10-20' };
const monitor = {
  election,
  positionBreakdown: [{ name: 'Board of Directors', votesCast: 700, totalVoters: 1250 }],
  recentVotes: [{ id: 1, name: 'Test Voter With A Long Name', memberId: 'TEST-1', position: 'Board of Directors', time: '10:30 AM', status: 'Verified' }],
  activityFeed: [{ type: 'vote', title: 'A test voter submitted a ballot', time: '10:30 AM' }],
};
const candidates = {
  member: { name: 'Test Voter', memberId: 'TEST-1', hasVoted: false }, election,
  candidates: ['Board of Directors', 'Audit and Inventory Committee', 'Election Committee'].map((position, i) => ({
    id: i + 1, first: 'Test', last: 'Candidate ' + (i + 1), position, occ: 'Cooperative member', bio: 'Candidate biography',
    photoUrl: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=',
  })),
};
(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    for (const role of ['admin', 'voter']) {
      const context = await browser.newContext({ reducedMotion: 'reduce' });
      await context.route('**/*', route => {
        const url = new URL(route.request().url());
        if (url.origin !== origin) return route.abort();
        const r = url.searchParams.get('r');
        if (r === 'monitor' || r === 'voter/candidates') return route.fulfill({ json: r === 'monitor' ? monitor : candidates });
        return route.continue();
      });
      assert.equal((await context.request.post(api + '?r=auth/login', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }, data: { username: 'test' + role, password: 'EndpointTest9!' },
      })).status(), 200);
      const page = await context.newPage();
      page.on('pageerror', error => failures.push(role + ' JS error: ' + error.message));
      page.on('crash', () => failures.push(role + ' browser tab crashed'));
      const modules = role === 'admin' ? ['admin/officer-management.php', 'admin/voter-management.php', 'admin/vote-monitoring.php'] : ['voter/profile.php', 'voter/candidates.php'];
      for (const width of [320, 375, 600, 768, 1024, 1280]) {
        await page.setViewportSize({ width, height: 844 });
        for (const module of modules) {
          await page.goto(origin + '/' + module);
          await page.waitForFunction(() => !!window.CoopVote?.user);
          if (module.includes('officer-management')) await page.locator('#accounts td[data-label="Name"]').first().waitFor();
          if (module.includes('monitoring')) await page.locator('.cv-monitor tbody tr').waitFor();
          if (module.includes('candidates')) await page.locator('.cand-av img').first().waitFor();
          if (module.includes('profile')) await page.locator('#photo-choose:enabled').waitFor();
          const prefix = module + ' @' + width;
          const layout = await page.evaluate(() => {
            const content = document.querySelector('.content,.module-content');
            return { width: content.clientWidth, scrollWidth: content.scrollWidth };
          });
          check(layout.scrollWidth <= layout.width + 1, prefix + ': content overflows ' + JSON.stringify(layout));
          if (module.includes('monitoring')) {
            const usable = await page.evaluate(() => ({ header:document.querySelector('.header').offsetHeight, content:document.querySelector('.content').clientHeight }));
            check(usable.header<200 && usable.content>400,prefix+': monitoring header consumes the screen '+JSON.stringify(usable));
            await page.locator('.recent-votes-card').scrollIntoViewIfNeeded();
            check(await page.locator('.table-scroll').evaluate(el=>el.clientWidth<=document.querySelector('.content').clientWidth),prefix+': votes table must scroll inside its card');
            await page.evaluate(()=>document.querySelector('.content').scrollTop=0);
          }
          if (module.includes('officer-management') && width <= 600) {
            const starts = await page.locator('#accounts tr').first().evaluate(row => [...row.querySelectorAll('td')].map(td => td.getBoundingClientRect().x + parseFloat(getComputedStyle(td).paddingLeft)));
            check(Math.max(...starts) - Math.min(...starts) < 1, prefix + ': Name label is not aligned with other fields');
          }
          if (module.includes('voter-management') && width <= 600) {
            const controls = await page.locator('.toolbar').evaluate(toolbar => [...toolbar.children].map(el => { const r=el.getBoundingClientRect(); return { x:r.x, y:r.y, width:r.width, height:r.height }; }));
            check(controls[0].width > controls[1].width * 1.8, prefix + ': search needs its own full row');
            check(Math.abs(controls[1].y-controls[2].y)<1 && Math.abs(controls[1].width-controls[2].width)<1, prefix + ': filters must share an equal row');
            check(controls[3].y > controls[2].y && Math.abs(controls[3].y-controls[4].y)<1, prefix + ': actions need an organized row');
          }
          if (module.includes('profile') && width <= 600) {
            const centered = await page.evaluate(() => {
              const p=document.querySelector('.profile-picture').getBoundingClientRect(), h=document.querySelector('.profile-hero').getBoundingClientRect();
              return Math.abs(p.x+p.width/2-h.x-h.width/2)<1;
            });
            check(centered, prefix + ': profile picture is not centered');
          }
          if (module.includes('candidates')) {
            if (width <= 768) {
              const organized = await page.evaluate(() => {
                const s=document.querySelector('.search-wrap').getBoundingClientRect(), t=document.querySelector('.pos-tabs').getBoundingClientRect();
                return Math.abs(s.x-t.x)<1 && Math.abs(s.width-t.width)<1 && t.y>=s.bottom;
              });
              check(organized, prefix + ': search and positions must occupy separate full rows');
            }
            await page.locator('.cand-av').first().scrollIntoViewIfNeeded();
            check(await page.locator('.cand-av').first().evaluate(el => {
              const r=el.getBoundingClientRect();return el.contains(document.elementFromPoint(r.x+r.width/2,r.bottom-5));
            }), prefix + ': candidate picture bottom is clipped');
            if (width===375) await page.screenshot({path:'/tmp/coopvote-mobile-candidate-list.png',animations:'disabled'});
            await page.locator('.btn-profile').first().click();
            await page.locator('.modal-backdrop.show').waitFor();
            await page.waitForTimeout(50);
            check(await page.locator('.modal-av').evaluate(el => {
              const r=el.getBoundingClientRect();return el.contains(document.elementFromPoint(r.x+r.width/2,r.bottom-5));
            }), prefix + ': profile dialog picture bottom is clipped');
            check(await page.evaluate(()=>document.querySelector('.modal-name').getBoundingClientRect().top-document.querySelector('.modal-av').getBoundingClientRect().bottom>=8),prefix+': candidate name needs space below the full picture');
          }
          if (width===375) await page.screenshot({ path:'/tmp/coopvote-mobile-'+module.replace(/[^a-z]/g,'-')+'.png', animations:'disabled' });
        }
      }
      await context.close();
    }
    // A device without WebGL, CDN fonts or decorative JS must retain the design.
    for (const width of [320, 390, 1366]) {
      const context=await browser.newContext({viewport:{width,height:768},reducedMotion:width===320?'reduce':'no-preference'});
      await context.route('**/*',route=>{
        const url=new URL(route.request().url());
        return url.origin!==origin || /three.min.js|login-background/.test(url.pathname) ? route.abort() : route.continue();
      });
      await context.addInitScript(()=>{
        const original=HTMLCanvasElement.prototype.getContext;
        HTMLCanvasElement.prototype.getContext=function(type,...args){return /webgl/.test(type)?null:original.call(this,type,...args);};
      });
      const page=await context.newPage();
      page.on('pageerror',error=>failures.push('Login JS error: '+error.message));
      await page.goto(origin+'/login.php');
      await page.locator('#tw-tagline').waitFor();
      await page.waitForFunction(()=>parseFloat(getComputedStyle(document.querySelector('.login-card')).opacity)===1);
      const design=await page.evaluate(()=>{
        const card=document.querySelector('.login-card'), backdrop=document.querySelector('.login-backdrop'), style=getComputedStyle(card),rect=card.getBoundingClientRect();
        return {backdrop:!!backdrop&&getComputedStyle(backdrop).backgroundImage!=='none',radius:parseFloat(style.borderRadius),visible:parseFloat(style.opacity)===1, fits:rect.x>=0&&rect.right<=innerWidth};
      });
      check(design.backdrop&&design.radius>0&&design.visible&&design.fits,'Login fallback @'+width+': '+JSON.stringify(design));
      const animation=await page.locator('.login-backdrop').evaluate(el=>getComputedStyle(el,'::before').animationName);
      check(width===320?animation==='none':animation!=='none','Login fallback respects motion preference @'+width);
      if (width!==320) {
        const first=await page.locator('.login-backdrop').evaluate(el=>getComputedStyle(el,'::before').transform);
        await page.waitForTimeout(150);
        const next=await page.locator('.login-backdrop').evaluate(el=>getComputedStyle(el,'::before').transform);
        check(first!==next,'Login background moves without WebGL @'+width);
      }
      await page.screenshot({path:'/tmp/coopvote-login-fallback-'+width+'.png',animations:'disabled'});
      // Confirm the styled form remains usable when graphics are unavailable.
      await page.locator('#username').fill('testvoter');
      await page.locator('#password').fill('EndpointTest9!');
      await page.locator('.btn-login').click();
      await page.waitForURL('**/voter/dashboard.php');
      await context.close();
    }
    assert.deepEqual(failures,[],'Responsive layout failures');
    console.log('Mobile layouts: PASS (320–1280px, officer alignment, monitoring, filters/actions, profile centering, full candidate pictures; login fallback and sign-in at 320/390/1366px)');
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
