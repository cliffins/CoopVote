// Exercise appearance across account changes, page loads, and separate browsers.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../assets/js/appearance.js'), 'utf8');
function browser(storage = new Map(), blocked = false, cookies = new Map(), cookiesBlocked = false) {
  const listeners = {};
  const document = {documentElement:{dataset:{}}, querySelector:()=>null};
  Object.defineProperty(document,'cookie',{
    get() { if(cookiesBlocked)throw Error('Cookies blocked'); return [...cookies].map(([key,value])=>key+'='+value).join('; '); },
    set(value) { if(cookiesBlocked)throw Error('Cookies blocked'); const [key,theme]=value.split(';')[0].split('=');cookies.set(key,theme); },
  });
  const context = vm.createContext({document,
    window:{location:{protocol:'https:'},addEventListener:(name, callback)=>{listeners[name]=callback;}},
    localStorage:{
      getItem(key) { if(blocked)throw Error('Storage blocked'); return storage.get(key) ?? null; },
      setItem(key,value) { if(blocked)throw Error('Storage blocked'); storage.set(key,value); },
    },
    CoopVote:{api(){throw Error('Themes must not call account preference APIs');}},
  });
  vm.runInContext(source, context);
  return {app:context.window.CoopVoteAppearance, listeners, document};
}
const storage = new Map([['cv.theme.last','forest'], ['cv.theme.user.1','plum'], ['cv.theme.user.2','light']]);
const cookies = new Map();
const first = browser(storage,false,cookies);
assert.equal(first.app.current(), 'forest');
first.app.sync({id:1,role:'member'}, {theme:'plum'});
assert.equal(first.app.current(), 'forest');
assert.equal(first.app.remember('ocean'), true);
// New page load represents logout/login navigation, with the same browser storage.
const second = browser(storage,false,cookies);
second.app.sync({id:2,role:'admin'}, {theme:'light'});
assert.equal(second.app.current(), 'ocean');
second.app.sync({id:1,role:'member'});
assert.equal(second.app.current(), 'ocean');
// A stale account-based page can still write the old key in another tab.
storage.set('cv.theme.last', 'navy');
second.listeners.storage({key:'cv.theme.last'});
assert.equal(browser(storage).app.current(), 'ocean');
assert.equal(second.app.remember({id:1}, 'plum'), false);
assert.equal(second.app.current(), 'ocean');
assert.equal(storage.get('cv.theme.user.1'), 'plum');
assert.equal(storage.get('cv.theme.user.2'), 'light');
const otherDevice = browser();
otherDevice.app.sync({id:1,role:'member'}, {theme:'plum'});
assert.equal(otherDevice.app.current(), 'navy');
second.app.remember('slate');
first.listeners.storage({key:'cv.theme.device'});
assert.equal(first.app.current(), 'slate');
second.app.remember('navy');
assert.equal(browser(storage).app.current(), 'navy');
second.app.remember('invalid');
assert.equal(second.app.current(), 'navy');
const unavailable = browser(new Map(), true);
assert.equal(unavailable.app.remember('light'), true);
unavailable.app.sync({id:3,role:'admin'}, {theme:'plum'});
assert.equal(unavailable.app.current(), 'light');
const noStorage=browser(new Map(),true,new Map(),true);
assert.equal(noStorage.app.remember('light'),false);
const recovered=browser(new Map(),false,new Map([['cv_theme_device','plum']]));
assert.equal(recovered.app.current(),'plum');
assert.equal(browser(new Map(),true,new Map([['cv_theme_device','forest']])).app.current(),'forest');
assert.equal(browser(new Map(),false,new Map([['cv_theme_device','invalid']])).app.current(),'navy');
storage.clear();
first.listeners.storage({key:null});
assert.equal(first.app.current(), 'navy');
console.log('Device theme: PASS (account switching, reload, cookie recovery, separate storage, cross-tab updates, defaults, blocked storage; no preference API calls)');
