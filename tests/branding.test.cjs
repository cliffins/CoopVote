const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const text={textContent:'CoopVote',style:{}};
const mark={innerHTML:'<svg>Original mark</svg>',image:null,querySelector(){return this.image;},replaceChildren(img){this.image=img;this.innerHTML='<img>';}};
let observer;
let coopLabel=null;
const sidebarLogo={style:{},appendChild(el){coopLabel=el;}};
const context=vm.createContext({window:{},CoopVote:{api:async()=>({text:'CoopVote',logo:null})},
  MutationObserver:class{constructor(fn){observer=fn;}observe(){}},
  document:{querySelector:()=>sidebarLogo,getElementById:()=>coopLabel,querySelectorAll:s=>s.includes('logo-text')?[text]:[mark],createElement:()=>({style:{},getAttribute(key){return this[key];}})}});
vm.runInContext(fs.readFileSync('assets/js/branding.js','utf8'),context);
(async()=>{
  await context.window.CoopVoteBranding.load();
  const apply=context.window.CoopVoteBranding.apply;
  apply({text:'<img onerror=alert(1)>',logo:'data:image/png;base64,test'});
  assert.equal(text.textContent,'<img onerror=alert(1)>','name is inserted as text, not HTML');
  assert.equal(mark.image.alt,'','logo is decorative next to the system name');
  const img=mark.image;observer();assert.equal(mark.image,img,'sidebar observer does not repeatedly replace the image');
  apply({text:'CoopVote',logo:null});assert.equal(mark.innerHTML,'<svg>Original mark</svg>','default mark restored');
  apply({text:'CoopVote',logo:null,coopName:'New Cooperative'});
  const label=coopLabel;assert.equal(label.textContent,'New Cooperative','sidebar name created');
  apply({text:'CoopVote',logo:null,coopName:'<b>Renamed</b>'});
  assert.equal(coopLabel,label,'existing sidebar label reused');
  assert.equal(label.textContent,'<b>Renamed</b>','cooperative name stays plain text');
  console.log('Branding rendering passed: literal text, shared logo, stable observer, original mark restoration.');
})().catch(e=>{console.error(e);process.exitCode=1;});
