// Browser regression for sidebar geometry, initial paint, scroll and menu clicks.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.COOPVOTE_PLAYWRIGHT_MODULE || 'playwright');
const api=process.env.COOPVOTE_TEST_URL;
assert.equal(new URL(api).hostname,'127.0.0.1');
const origin=new URL(api).origin;
(async()=>{
  const browser=await chromium.launch({headless:true});
  try {
    for(const role of ['admin','voter']) {
      const context=await browser.newContext({viewport:{width:1280,height:650}});
      await context.route('**/*',async route=>{
        const url=new URL(route.request().url());
        if(url.origin!==origin)return route.abort();
        if(url.searchParams.get('r')==='auth/me')await new Promise(resolve=>setTimeout(resolve,250));
        await route.continue();
      });
      const login=await context.request.post(api+'?r=auth/login',{headers:{'X-Requested-With':'XMLHttpRequest'},data:{username:'test'+role,password:'EndpointTest9!'}});
      assert.equal(login.status(),200);
      await context.addInitScript(()=>{
        window.sidebarFrames=[];
        const start=performance.now();
        function sample(){
          const side=document.querySelector('.sidebar'),link=side?.querySelector('.nav-item');
          if(link && getComputedStyle(link).visibility==='visible'){const rect=side.getBoundingClientRect();window.sidebarFrames.push({x:rect.x,width:rect.width,y:link.getBoundingClientRect().y,scroll:side.scrollTop,count:side.querySelectorAll('.nav-item').length});}
          if(performance.now()-start<1100)requestAnimationFrame(sample);
        }
        requestAnimationFrame(sample);
      });
      const page=await context.newPage();
      const errors=[];
      page.on('pageerror',error=>errors.push(error.message));
      const pages=[role+'/themes.php',...fs.readdirSync(path.join(__dirname,'../..',role)).filter(name=>name.endsWith('.php')).map(name=>role+'/'+name)];
      let baseline, expectedScroll=0;
      async function geometry(){return page.evaluate(()=>{
        const s=document.querySelector('.sidebar'),rect=s.getBoundingClientRect();
        return {width:rect.width,x:rect.x,height:s.scrollHeight,logo:s.querySelector('.sidebar-logo').getBoundingClientRect().height,
          links:[...s.querySelectorAll('.nav-item')].map(el=>({top:el.offsetTop,height:el.offsetHeight,width:el.getBoundingClientRect().width})),scroll:s.scrollTop};
      });}
      for(const name of pages){
        await page.goto(origin+'/'+name);
        await page.waitForFunction(()=>window.CoopVote?.user && document.querySelector('[data-cv-nav="themes"]'));
        await page.waitForFunction(()=>performance.now()>1200);
        const current=await geometry();
        assert.equal(current.width,260,name+' sidebar width');
        assert.equal(current.x,0,name+' sidebar position');
        assert.equal(current.logo,141,name+' logo height');
        assert.equal(current.scroll,expectedScroll,name+' scroll restoration');
        const layout={...current};delete layout.scroll;
        if(!baseline)baseline=layout;
        assert.deepEqual(layout,baseline,name+' menu geometry matches all other pages');
        const frames=await page.evaluate(()=>sidebarFrames);
        assert.ok(frames.length>0);
        for(const frame of frames){
          assert.equal(frame.width,260,name+' initial width');
          assert.equal(frame.x,0,name+' initial position');
          assert.equal(frame.count,role==='admin'?12:8,name+' complete menu before delayed auth/me');
          assert.ok(Math.abs(frame.y+frame.scroll-185)<1,name+' no initial menu jump');
          assert.equal(frame.scroll,expectedScroll,name+' no scroll flash');
        }
        if(name==='admin/candidate-management.php')await page.screenshot({path:'/tmp/coopvote-sidebar-candidate.png'});
        expectedScroll=await page.evaluate(()=>{const s=document.querySelector('.sidebar');s.scrollTop=s.scrollHeight;return s.scrollTop;});
        await page.waitForTimeout(50); // Let the real scroll event persist the position.
      }
      if(role==='admin'){
        for(let i=0;i<3;i++){
          await page.locator('.sidebar a[href$="candidate-management.php"]').click();
          await page.waitForURL(origin+'/admin/candidate-management.php');
          await page.waitForFunction(()=>!!window.CoopVote?.user);
          assert.equal((await geometry()).width,260);
          assert.deepEqual(await page.evaluate(()=>['.main','.summary-strip','.toolbar','.cards-grid','.table-card'].map(selector=>getComputedStyle(document.querySelector(selector)).animationName)),Array(5).fill('none'));
          await page.locator('[data-cv-nav="themes"]').click();
          await page.waitForURL(origin+'/admin/themes.php');
          await page.locator('[data-theme="navy"]').waitFor();
        }
        const original=await (await context.request.get(api+'?r=branding')).json();
        await context.request.put(api+'?r=branding',{headers:{'X-Requested-With':'XMLHttpRequest'},data:{text:'A very long cooperative display name',coopName:'Long cooperative name '.repeat(6).trim(),logo:null}});
        await page.goto(origin+'/admin/candidate-management.php');
        await page.waitForFunction(()=>!!window.CoopVote?.user);
        const long=await geometry();delete long.scroll;
        assert.deepEqual(long,baseline,'Long branding cannot move or widen the menu');
        await context.request.put(api+'?r=branding',{headers:{'X-Requested-With':'XMLHttpRequest'},data:original});
      }
      const module=role==='admin'?'admin/candidate-management.php':'voter/profile.php';
      for(const width of [390,850,900,901]){
        await page.setViewportSize({width,height:844});
        for(const name of [module,role+'/themes.php']){
          await page.goto(origin+'/'+name);
          await page.waitForFunction(()=>!!window.CoopVote?.user);
          if(width<=900){
            await page.waitForFunction(()=>document.querySelector('.sidebar').getBoundingClientRect().right<=1);
            await page.locator('.hamburger').click();
            await page.waitForFunction(()=>Math.abs(document.querySelector('.sidebar').getBoundingClientRect().x)<1);
            assert.equal((await geometry()).width,260,'Drawer width at '+width);
            if(width===390 && name===module)await page.screenshot({path:'/tmp/coopvote-sidebar-'+role+'-phone.png'});
            await page.locator(name.endsWith('/themes.php')?'#sidebar-overlay':'#overlay').click({position:{x:width-20,y:100}});
            await page.waitForFunction(()=>document.querySelector('.sidebar').getBoundingClientRect().right<=1);
          }else{
            assert.equal((await geometry()).x,0,'Desktop sidebar begins at left edge');
            assert.equal(await page.locator('.hamburger').isVisible(),false);
          }
        }
      }
      const modules=role==='admin'
        ? {officers:'officer-management',approvals:'election-approvals',themes:'themes',branding:'branding'}
        : {themes:'themes'};
      for(const [key,filename] of Object.entries(modules)){
        const destination=origin+'/'+role+'/'+filename+'.php';
        await page.locator('[data-cv-nav="'+key+'"]').click();
        await page.waitForURL(destination);
        await page.locator('#'+key+':not([hidden])').waitFor();
        assert.equal(await page.locator('[data-cv-nav="'+key+'"]').getAttribute('aria-current'),'page');
        assert.equal(await page.locator('.module-content > section:not([hidden])').count(),1);
        await page.reload();
        await page.locator('#'+key+':not([hidden])').waitFor();
        assert.equal(page.url(),destination,'Refresh keeps the named module URL');
        // The old fragment exists only in the browser; it must resolve to the
        // equivalent guarded page, including a selected election when present.
        const query=key==='approvals'?'?election=123':'';
        await page.goto(origin+'/management.php'+query+'#'+key);
        await page.waitForURL(destination+query);
        await page.locator('#'+key+':not([hidden])').waitFor();
      }
      await page.goto(origin+'/management.html#themes');
      await page.waitForURL(origin+'/'+role+'/themes.php');
      await page.locator('#themes:not([hidden])').waitFor();
      if(role==='voter'){
        await page.goto(origin+'/management.php#officers');
        await page.waitForURL(origin+'/voter/themes.php');
        await page.locator('#themes:not([hidden])').waitFor();
      }
      console.log(role+' named module URLs: PASS (sidebar links, active menu, reload, legacy PHP/HTML bookmarks and election query)');
      assert.deepEqual(errors,[],'No page errors during sidebar navigation');
      await context.close();
      console.log(role+' sidebar: PASS (all pages, cold settings entry, delayed auth, first paint, widths/positions, scroll, phone/tablet/desktop drawers'+(role==='admin'?', repeated Candidate Management clicks and long branding':'')+')');
    }
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
