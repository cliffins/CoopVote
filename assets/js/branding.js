(function(){
  'use strict';
  let current=null;
  let started=false;
  const script=document.currentScript;
  const cacheKey='cv.branding.'+(script ? new URL(script.src).pathname.replace(/assets\/js\/branding\.js$/, '') : '');
  const original=new WeakMap();
  function apply(data){
    current=data;
    try { sessionStorage.setItem(cacheKey,JSON.stringify(data)); } catch (_) {}
    const logo=document.querySelector('.sidebar-logo');
    if(logo && typeof data.coopName==='string'){
      let name=document.getElementById('cv-coop-name');
      if(!name){name=document.createElement('div');name.id='cv-coop-name';name.style.cssText='width:100%;margin-top:8px;font-size:.68rem;line-height:1.35;color:var(--muted);letter-spacing:.02em;overflow-wrap:anywhere';logo.style.flexWrap='wrap';logo.appendChild(name);}
      if(name.textContent!==data.coopName)name.textContent=data.coopName;
      name.title=data.coopName;
    }
    document.querySelectorAll('.logo-text,.brand-name').forEach(el=>{if(el.textContent!==data.text)el.textContent=data.text;el.style.overflowWrap='anywhere';el.style.minWidth='0';});
    document.querySelectorAll('.logo-mark,.brand-icon').forEach(el=>{
      if(!original.has(el))original.set(el,el.innerHTML);
      if(data.logo){
        if(el.querySelector('img')?.getAttribute('src')===data.logo)return;
        const img=document.createElement('img');img.src=data.logo;img.alt='';img.style.cssText='width:100%;height:100%;object-fit:contain;display:block';el.replaceChildren(img);
      }else if(el.innerHTML!==original.get(el))el.innerHTML=original.get(el);
    });
  }
  async function load(){const data=await CoopVote.api('branding');apply(data);return data;}
  function preview(){
    try {
      const data=JSON.parse(sessionStorage.getItem(cacheKey));
      if(data && typeof data.text==='string' && typeof data.coopName==='string'
          && (data.logo===null || (typeof data.logo==='string' && /^data:image\/(png|jpeg|webp);base64,/.test(data.logo)))) apply(data);
    } catch (_) {}
  }
  function start(){
    if(started)return;
    started=true;
    // Shared sidebar markup can also arrive after authentication.
    const sidebar=document.querySelector('.sidebar');
    if(sidebar)new MutationObserver(()=>{if(current)apply(current);}).observe(sidebar,{childList:true,subtree:true});
    load().catch(()=>{});
  }
  window.CoopVoteBranding={apply,load,preview,start};
  if(typeof CoopVote!=='undefined')start();
})();
