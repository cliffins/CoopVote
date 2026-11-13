const assert = require('node:assert/strict');
const {chromium} = require(process.env.COOPVOTE_PLAYWRIGHT_MODULE || 'playwright');
const api = process.env.COOPVOTE_TEST_URL;
assert.equal(new URL(api).hostname, '127.0.0.1');
(async () => {
  const browser = await chromium.launch({headless:true,channel:'chromium',args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
  try {
    const context = await browser.newContext({viewport:{width:1280,height:900},acceptDownloads:true});
    assert.equal((await context.request.post(api+'?r=auth/login',{data:{username:'testadmin',password:'EndpointTest9!'}})).status(),200);
    const page = await context.newPage(), errors=[];
    await page.route('https://**/*', route => route.abort());
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(new URL('/admin/security-logs.php',api).href,{waitUntil:'domcontentloaded'});
    await page.locator('#log-from').waitFor();
    await page.locator('#log-from').fill('2020-02-20');
    await page.locator('#log-to').fill('2020-02-20');
    await page.getByRole('button',{name:'Apply filters',exact:true}).click();
    await page.waitForFunction(() => document.querySelector('#log-count')?.textContent.includes('of 525'));
    await page.getByRole('button',{name:'Next',exact:true}).click();
    await page.waitForFunction(() => document.querySelector('#log-count')?.textContent.startsWith('Showing 51'));
    await page.getByRole('button',{name:'Refresh',exact:true}).click();
    await page.waitForTimeout(400);
    assert.equal(await page.locator('#log-from').inputValue(),'2020-02-20');
    assert.match(await page.locator('#log-count').textContent(),/^Showing 51/);
    const csvWait=page.waitForEvent('download');
    await page.getByRole('button',{name:'Export filtered CSV'}).click();
    const csv=await csvWait;
    assert.match(csv.suggestedFilename(),/\.csv$/);
    assert.equal(await csv.failure(),null);
    await page.getByRole('button',{name:'Clear filters',exact:true}).click();
    await page.waitForFunction(() => document.querySelector('#log-from')?.value==='');
    await page.locator('#log-actor').selectOption('9001');
    await page.locator('#log-action').selectOption('candidate.created');
    await page.getByRole('button',{name:'Apply filters',exact:true}).click();
    await page.waitForFunction(() => document.querySelector('#log-count')?.textContent.includes('of 2 matching'));
    for (const width of [320,375,768,1280]) {
      await page.setViewportSize({width,height:900});
      await page.waitForTimeout(450);
      const overflow = await page.evaluate(() => {
        const selectors=['.content','#database-backup','.activity-filters','.log-footer'];
        return selectors.filter(s => {const e=document.querySelector(s);return e.scrollWidth>e.clientWidth+2;});
      });
      assert.deepEqual(overflow,[],`Layout overflow at ${width}px`);
      for (const id of ['log-from','log-to','log-actor','log-action','log-search-input','backup-download']) {
        const box=await page.locator('#'+id).boundingBox();
        assert(box.x>=0 && box.x+box.width<=width+1,`${id} exceeds viewport at ${width}px`);
      }
    }
    const downloadWait=page.waitForEvent('download');
    await page.locator('#backup-download').click();
    const download=await downloadWait;
    assert.match(download.suggestedFilename(),/^coopvote-database-.*\.sql$/);
    assert.equal(await download.failure(),null);
    await page.waitForFunction(() => !document.querySelector('#backup-download')?.disabled);
    await page.route('**/api/index.php?r=security/backup',route => route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({ok:false,error:'Backup test failure'})}));
    await page.locator('#backup-download').click();
    await page.waitForFunction(() => !document.querySelector('#backup-download')?.disabled);
    assert(await page.getByText('Backup test failure',{exact:true}).count());
    assert.deepEqual(errors,[]);
    await page.setViewportSize({width:375,height:900});
    await page.waitForTimeout(4000);
    await page.locator('#database-backup').scrollIntoViewIfNeeded();
    if(process.env.COOPVOTE_SECURITY_SCREENSHOT) {
      await page.screenshot({path:process.env.COOPVOTE_SECURITY_SCREENSHOT});
      await page.locator('.activity-filters').scrollIntoViewIfNeeded();
      await page.waitForTimeout(600);
      const rightEdgeVisible = await page.locator('#log-from').evaluate(e => {
        const r=e.getBoundingClientRect();return document.elementFromPoint(r.right-8,r.top+r.height/2) === e;
      });
      assert(rightEdgeVisible,'Mobile filter is covered by another element');
      await page.screenshot({path:process.env.COOPVOTE_SECURITY_SCREENSHOT.replace(/\.png$/,'-filters.png'),animations:'disabled'});
    }
    console.log('Security browser checks: filters, pagination/refresh, CSV/SQL download, failure recovery, 320–1280px layout PASS');
  } finally {await browser.close();}
})().catch(error => {console.error(error);process.exitCode=1;});
