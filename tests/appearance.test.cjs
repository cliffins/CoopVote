const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const src=fs.readFileSync('assets/js/appearance.js','utf8');
const css=fs.readFileSync('assets/css/themes.css','utf8');
const storage=new Map([['cv.theme.last','forest']]);
const root={dataset:{}};let resolve;
const context=vm.createContext({document:{documentElement:root,querySelector:()=>null},window:{},localStorage:{getItem:k=>storage.get(k),setItem:(k,v)=>storage.set(k,v)},CoopVote:{api:()=>new Promise(r=>resolve=r)}});
vm.runInContext(src,context);
assert.equal(root.dataset.cvTheme,'forest','reload applies remembered appearance before authentication');
const appearance=context.window.CoopVoteAppearance;
(async()=>{
  const pending=appearance.sync({id:2,role:'member'});
  assert.equal(root.dataset.cvTheme,'navy','another account does not inherit the prior account theme');
  appearance.remember({id:2},'light');resolve({theme:'navy'});await pending;
  assert.equal(root.dataset.cvTheme,'light','late preference response cannot overwrite a newly saved choice');
  assert.equal(storage.get('cv.theme.user.2'),'light','cache is scoped to the account');
  assert.equal(storage.get('cv.theme.last'),'light','login retains the most recently selected appearance');
  appearance.apply('unknown');assert.equal(root.dataset.cvTheme,'navy','unknown local values use the default');
  const palettes=[...css.matchAll(/:root\[data-cv-theme="([^"]+)"\]\s*\{([^}]+)\}/g)];
  assert.equal(palettes.length,6);
  function luminance(hex){const rgb=hex.match(/[0-9a-f]{2}/gi).map(v=>parseInt(v,16)/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4);return rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722;}
  function contrast(a,b){const x=luminance(a),y=luminance(b);return(Math.max(x,y)+.05)/(Math.min(x,y)+.05);}
  for(const [,name,body]of palettes){
    const tokens=Object.fromEntries([...body.matchAll(/(--[\w-]+):\s*(#[0-9a-f]{6})\b/gi)].map(m=>[m[1],m[2]]));
    for(const fg of ['--white','--muted','--muted2','--gold'])for(const bg of ['--navy','--navy2'])assert.ok(contrast(tokens[fg],tokens[bg])>=4.5,`${name}: ${fg} readable on ${bg}`);
    assert.ok(contrast(tokens['--gold'],tokens['--on-accent']||'#101c2e')>=4.5,name+' primary action contrast');
  }
  for(const p of ['login.html','management.html',...['admin','voter'].flatMap(d=>fs.readdirSync(d).filter(f=>f.endsWith('.html')).map(f=>d+'/'+f))]){
    const html=fs.readFileSync(p,'utf8'),head=html.split('</head>')[0];assert.match(head,/assets\/css\/themes.css/,p+' theme stylesheet');assert.match(head,/assets\/js\/appearance.js/,p+' early theme initialization');
  }
  new vm.Script(fs.readFileSync('assets/js/management.js','utf8'));
  console.log('Appearance passed: account isolation, reload cache, stale response protection, six palette contrasts, and shared page loading.');
})().catch(e=>{console.error(e);process.exitCode=1;});
