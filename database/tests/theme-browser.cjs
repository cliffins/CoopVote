// Opt-in actual-browser test; the runner supplies a disposable database/server.
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require(process.env.COOPVOTE_PLAYWRIGHT_MODULE || 'playwright');
const api = process.env.COOPVOTE_TEST_URL;
assert.equal(new URL(api).hostname, '127.0.0.1', 'Never run write tests on production');
const origin = new URL(api).origin;
(async () => {
  const browser = await chromium.launch({headless:true});
  try {
    const context = await browser.newContext();
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    page.setDefaultNavigationTimeout(10000);
    const failures = [];
    page.on('pageerror', error => {
      // Chromium can cancel a native cross-document animation during redirects.
      // This is independent of application JS; theme/navigation assertions still run.
      if (error.message === 'Transition was aborted because of invalid state. ViewTransition opt-in disabled') return;
      failures.push(error.message);
    });
    await context.route('**/*', route => new URL(route.request().url()).origin === origin
      ? route.continue() : route.abort());
    async function expectTheme(theme) {
      await page.waitForFunction(value => document.documentElement.dataset.cvTheme === value, theme);
      assert.equal(await page.evaluate(()=>localStorage.getItem('cv.theme.device')),theme);
    }
    async function login(name,role) {
      await page.locator('#username').fill(name);
      await page.locator('#password').fill('EndpointTest9!');
      assert.equal(await page.locator('#username').inputValue(),name);
      assert.equal(await page.locator('#password').inputValue(),'EndpointTest9!');
      await page.locator('#password').press('Tab');
      await page.locator('#login-btn').click();
      try {
        await page.waitForURL(origin+'/'+role+'/dashboard.php');
      } catch (error) {
        console.error('Login diagnostics:',page.url(),failures,
          await page.evaluate(()=>({alert:document.getElementById('alert-msg')?.textContent,
            username:document.getElementById('username')?.value,
            passwordLength:document.getElementById('password')?.value.length})));
        await page.screenshot({path:'/tmp/coopvote-theme-login-failure.png'});
        throw error;
      }
      await page.waitForFunction(()=>!!window.CoopVote?.user);
    }
    async function logout() {
      await page.evaluate(()=>window.CoopVote.logout());
      await page.waitForURL(url=>url.pathname==='/login.php');
    }
    for (const name of ['login','management']) {
      for (const extension of ['php','html']) {
        const response=await context.request.get(origin+'/'+name+'.'+extension,{maxRedirects:0});
        assert.equal(response.status(),name==='login'?200:302);
        assert.match(response.headers()['cache-control'],/no-store/);
      }
    }
    async function expectBlocked(attempt, destination) {
      const responses=[];
      const listener=response=>{if(response.url()===origin+'/'+attempt) responses.push(response);};
      page.on('response',listener);
      try {
        await page.goto(origin+'/'+attempt);
        await page.waitForURL(origin+'/'+destination);
        assert.equal(responses.length,1);
        assert.equal(responses[0].status(),302,'Guard rejects before rendering');
        assert.equal(await page.locator('h1').filter({hasText:'Access denied'}).count(),0);
        if(destination!=='login.php') await page.waitForFunction(()=>!!window.CoopVote?.user);
      } finally {page.off('response',listener);}
    }
    for(const role of ['admin','voter']) for(const extension of ['html','php']) {
      await expectBlocked(role+'/dashboard.'+extension,'login.php');
    }
    await page.goto(origin+'/login.php');
    await login('testadmin','admin');
    await page.goto(origin+'/admin/reports.php');
    await page.waitForFunction(()=>!!window.CoopVote?.user);
    for(const extension of ['html','php']) await expectBlocked('voter/dashboard.'+extension,'admin/reports.php');
    // Keep a conflicting account preference to prove it never wins over the device.
    assert.equal((await context.request.put(api+'?r=management/preferences',{
      headers:{'X-Requested-With':'XMLHttpRequest'},data:{theme:'plum'}
    })).status(),200);
    await page.locator('[data-cv-nav="themes"]').click();
    await page.waitForURL(origin+'/admin/themes.php');
    await page.locator('[data-theme="ocean"]').click();
    await expectTheme('ocean');
    await expectBlocked('voter/dashboard.php','admin/themes.php');
    await page.reload();
    await expectTheme('ocean');
    await logout();
    await expectTheme('ocean');
    await login('testvoter','voter');
    await page.goto(origin+'/voter/profile.php');
    await page.waitForFunction(()=>!!window.CoopVote?.user);
    for(const extension of ['html','php']) await expectBlocked('admin/dashboard.'+extension,'voter/profile.php');
    await expectTheme('ocean');
    await page.locator('[data-cv-nav="themes"]').click();
    await page.waitForURL(origin+'/voter/themes.php');
    await page.locator('[data-theme="ocean"][aria-pressed="true"]').waitFor();
    await page.locator('[data-theme="forest"]').click();
    await expectTheme('forest');
    await expectBlocked('admin/dashboard.html','voter/themes.php');
    // Simulate an already-open legacy page overwriting its account-scoped cache.
    await page.evaluate(()=>localStorage.setItem('cv.theme.last','navy'));
    await logout();
    await expectTheme('forest');
    await login('testadmin','admin');
    await expectTheme('forest');
    await page.locator('[data-cv-nav="themes"]').click();
    await page.locator('[data-theme="forest"][aria-pressed="true"]').waitFor();
    await page.locator('[data-theme="navy"]').click();
    await expectTheme('navy');
    await logout();
    await expectTheme('navy');
    await expectBlocked('admin/dashboard.php','login.php');
    await expectBlocked('voter/dashboard.php','login.php');
    console.log('Page guards: PASS (signed out, voter/admin URL edits, previous page and Themes tab, logout; PHP and legacy HTML URLs)');
    assert.deepEqual(failures,[],'No page script errors during account switching');
    const otherDevice=await browser.newContext();
    await otherDevice.route('**/*', route => new URL(route.request().url()).origin === origin
      ? route.continue() : route.abort());
    const fresh=await otherDevice.newPage();
    await fresh.goto(origin+'/login.php');
    assert.equal(await fresh.evaluate(()=>document.documentElement.dataset.cvTheme),'navy');
    await otherDevice.close();
    // Unlike newContext (private storage), this profile survives browser shutdown.
    const profile = await fs.mkdtemp(path.join(os.tmpdir(),'coopvote-theme-profile-'));
    let persistent;
    try {
      persistent = await chromium.launchPersistentContext(profile,{headless:true});
      await persistent.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
      let reopened = await persistent.newPage();
      await reopened.goto(origin+'/login.php');
      await reopened.locator('#username').fill('testadmin');
      await reopened.locator('#password').fill('EndpointTest9!');
      await reopened.locator('#login-btn').click();
      await reopened.waitForURL(origin+'/admin/dashboard.php');
      await reopened.locator('[data-cv-nav="themes"]').click();
      await reopened.locator('[data-theme="plum"]').click();
      await reopened.waitForFunction(()=>document.documentElement.dataset.cvTheme==='plum');
      const themeCookie=(await persistent.cookies()).find(cookie=>cookie.name==='cv_theme_device');
      assert.equal(themeCookie.value,'plum');
      assert.ok(themeCookie.expires>Date.now()/1000+300*86400,'Theme cookie survives browser sessions');
      await persistent.close();
      persistent = await chromium.launchPersistentContext(profile,{headless:true});
      await persistent.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
      reopened = await persistent.newPage();
      await reopened.goto(origin+'/login.php');
      await reopened.waitForFunction(()=>document.documentElement.dataset.cvTheme==='plum');
      assert.equal(await reopened.evaluate(()=>localStorage.getItem('cv.theme.device')),'plum');
      console.log('Browser restart: PASS (selected Plum, closed Chromium process, reopened the same disk profile; Plum restored)');
      const firstPaint=await persistent.request.get(origin+'/login.php');
      assert.match(await firstPaint.text(),/<html lang="en" data-cv-theme="plum">/);
      await reopened.evaluate(()=>{localStorage.removeItem('cv.theme.device');localStorage.removeItem('cv.theme.last');});
      await persistent.close();
      persistent = await chromium.launchPersistentContext(profile,{headless:true});
      await persistent.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
      reopened = await persistent.newPage();
      await reopened.goto(origin+'/login.php');
      await reopened.waitForFunction(()=>document.documentElement.dataset.cvTheme==='plum');
      assert.equal(await reopened.evaluate(()=>localStorage.getItem('cv.theme.device')),'plum');
      console.log('Theme recovery: PASS (persistent cookie restored Plum after localStorage removal and a second browser restart; initial HTML matches)');
    } finally {
      if(persistent)await persistent.close();
      await fs.rm(profile,{recursive:true,force:true});
    }
    console.log('Browser theme flow: PASS (admin → ocean → logout → voter → forest → logout → admin; reload; legacy-cache overwrite; default; uncached entry pages)');
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
