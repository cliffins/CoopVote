// QR entry and post-login destination behavior, using the actual browser scripts.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.join(__dirname, '../..');
let encoded, copied;
const canvas = { dataset: {}, getContext: () => ({fillRect() {}}), toDataURL: () => 'data:image/png;base64,test' };
const copy = {}, download = {}, open = {};
const parts = {'canvas':canvas, '[data-qr-copy]':copy, '[data-qr-download]':download, '[data-qr-link]':open};
const host = {querySelector: selector => parts[selector]};
const qrContext = vm.createContext({
  URL, location:new URL('https://example.test/coop/admin/election-management.html'),
  document:{getElementById:()=>host}, window:{isSecureContext:true},
  navigator:{clipboard:{writeText:async value => { copied=value; }}},
  CoopVote:{toast(){}}, setTimeout:fn=>fn(),
  qrcode:()=>({addData:value=>{encoded=value;},make(){},getModuleCount:()=>1,isDark:()=>true}),
});
vm.runInContext(fs.readFileSync(path.join(root,'assets/js/election-qr.js'),'utf8'), qrContext);
qrContext.window.CoopVoteElectionQR.render(42);
assert.equal(encoded, 'https://example.test/coop/login.php?election=42');
assert.equal(open.href, encoded);
assert.equal(canvas.dataset.url, encoded);
assert.equal(download.download, 'coopvote-election-42-qr.png');

const runtime = vm.createContext({URL, URLSearchParams,
  window:{location:new URL(encoded), addEventListener(){}, CoopVoteBranding:{start(){}}},
  document:{currentScript:{src:'https://example.test/coop/assets/js/app.js'},addEventListener(){}},
});
vm.runInContext(fs.readFileSync(path.join(root,'assets/js/app.js'),'utf8'), runtime);
const app = runtime.window.CoopVote;
assert.equal(app.loginDestination({role:'member',home:'voter/dashboard.php'}),
  'https://example.test/coop/voter/election.php?election=42');
assert.equal(app.loginDestination({roleSlug:'voter'}),
  'https://example.test/coop/voter/election.php?election=42');
assert.equal(app.loginDestination({role:'admin',home:'admin/dashboard.php'}),
  'https://example.test/coop/admin/dashboard.php');
for (const value of ['', '0', '-1', 'https://example.org', '12345678901']) {
  runtime.window.location = new URL('https://example.test/coop/login.php?election='+encodeURIComponent(value));
  assert.equal(app.loginDestination({role:'member',home:'voter/dashboard.php'}),
    'https://example.test/coop/voter/dashboard.php');
}
(async () => {
  await copy.onclick();
  assert.equal(copied, encoded);
  console.log('QR/login: PASS (QR and copy link, subdirectory hosting, voter destination, admin separation, invalid IDs)');
})().catch(error=>{console.error(error);process.exitCode=1;});
