const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('assets/js/management.js','utf8');
new vm.Script(source);
function extract(name){const m=source.match(new RegExp('(?:async )?function '+name+'\\([^]*?\\n  }'));assert.ok(m,name+' found');return m[0];}
function button(disabled=false){return {disabled,dataset:{},innerHTML:'<span class="theme-preview">Preview</span><span>Navy</span>',attrs:{},setAttribute(k,v){this.attrs[k]=v;},removeAttribute(k){delete this.attrs[k];},closest(){return null;}};}
(async()=>{
  const primary=button(),previouslyDisabled=button(true),messages=[],errors=[];
  const context=vm.createContext({document:{querySelectorAll:()=>[primary,previouslyDisabled]},toast:m=>messages.push(m),syncPasswordButton(){},syncRequirementButton(){},syncBrandingButton(){},refresh:async()=>false});
  vm.runInContext('let mutation=false,refreshId=0;',context);
  for(const name of ['busyControls','run'])vm.runInContext(extract(name),context);
  let reject,calls=0;
  const work=()=>{calls++;return new Promise((_,r)=>reject=r);};
  const pending=context.run(primary,work,null,'Saved.');
  await context.run(primary,work,null,'Saved.');
  assert.equal(calls,1,'double click sends one mutation');
  assert.equal(primary.disabled,true);assert.equal(primary.attrs['aria-busy'],'true');
  assert.match(primary.innerHTML,/theme-preview/,'busy state keeps preview markup');
  reject(new Error('Save failed'));await pending;
  assert.equal(primary.disabled,false,'failed action can be retried');assert.equal(previouslyDisabled.disabled,true,'unavailable actions remain disabled');
  assert.deepEqual(messages,['Save failed']);assert.match(primary.innerHTML,/theme-preview/,'failed theme save does not destroy preview');
  await context.run(primary,async()=>({ok:true}),()=>calls++,'Theme saved.');
  assert.equal(messages.at(-1),'Theme saved.');assert.equal(primary.disabled,false);

  // Exercise the real refresh failure handling, including late responses.
  let resolveOld,rendered=0;
  const dataContext=vm.createContext({active:()=> 'themes',error:m=>errors.push(m),api:()=>new Promise(r=>resolveOld=r),CoopVoteAppearance:{remember(){}},user:{id:1},renderThemes(){rendered++;}});
  vm.runInContext('let refreshId=0,pendingReads=0;const renderedData=new Map();',dataContext);vm.runInContext(extract('refresh'),dataContext);
  const old=dataContext.refresh();vm.runInContext('refreshId++',dataContext);resolveOld({theme:'navy'});await old;
  assert.equal(rendered,0,'late reads cannot replace newer controls');
  dataContext.api=async()=>{throw Error('Connection failed');};assert.equal(await dataContext.refresh(),false);assert.equal(errors.at(-1),'Connection failed');

  const form={elements:{currentPassword:{value:''},newPassword:{value:'Strong@123'},confirmPassword:{value:'Strong@123'}}};
  const validation=vm.createContext({$:()=>form,TextEncoder});vm.runInContext(extract('passwordIssue'),validation);
  assert.match(validation.passwordIssue(),/current/);form.elements.currentPassword.value='   ';assert.match(validation.passwordIssue(),/current/);
  form.elements.currentPassword.value='Existing@123';form.elements.newPassword.value='weak';assert.match(validation.passwordIssue(),/8 or more/);
  form.elements.newPassword.value='Strong@123';form.elements.confirmPassword.value='different';assert.match(validation.passwordIssue(),/match/);
  form.elements.confirmPassword.value='Strong@123';assert.equal(validation.passwordIssue(),'');
  form.elements.newPassword.value='A1!'+ 'é'.repeat(40);form.elements.confirmPassword.value=form.elements.newPassword.value;assert.match(validation.passwordIssue(),/8 or more/,'bcrypt byte limit is checked');

  const html=fs.readFileSync('management.html','utf8');
  assert.doesNotMatch(html,/id="refresh-page"/,'automatic refresh needs no manual header control');
  assert.doesNotMatch(source,/refresh-page|refreshFromButton|syncRefreshButtons/,'no handlers reference the removed control');
  assert.match(html,/id="confirm-action"/);assert.match(source,/confirm-action'\)\.textContent=title/,'confirm label names its action');
  const nodes={'officer-search':{value:''},'officer-status':{value:''},'officer-count':{textContent:''},accounts:{innerHTML:'',querySelectorAll:()=>[]}};
  const filters=vm.createContext({$:id=>nodes[id],user:{id:1},esc:s=>String(s).replaceAll('<','&lt;').replaceAll('>','&gt;')});
  vm.runInContext('const accounts=[{id:1,name:"Jane Santos",username:"jane",email:"jane@test.coop",status:"active"},{id:2,name:"Ana Cruz",username:"ana",email:"ana@test.coop",status:"deactivated"}];',filters);
  vm.runInContext(extract('renderAccounts'),filters);filters.renderAccounts();assert.match(nodes.accounts.innerHTML,/Jane Santos/);assert.match(nodes.accounts.innerHTML,/Ana Cruz/);
  nodes['officer-status'].value='active';filters.renderAccounts();assert.doesNotMatch(nodes.accounts.innerHTML,/Ana Cruz/);
  nodes['officer-status'].value='';nodes['officer-search'].value='ANA@';filters.renderAccounts();assert.match(nodes.accounts.innerHTML,/Ana Cruz/);assert.doesNotMatch(nodes.accounts.innerHTML,/Jane Santos/);
  nodes['officer-search'].value='no match';filters.renderAccounts();assert.match(nodes.accounts.innerHTML,/No officers match/);
  assert.equal(nodes['officer-count'].textContent,'0 of 2 accounts');
  const brandingError={textContent:'Previous upload error'}, brandingNodes={
    'branding-text':{value:'Custom Brand'},'branding-coop-name':{value:'Custom Cooperative'},
    'branding-file':{value:'custom.png'},'branding-form':{querySelector:()=>brandingError},
    'branding-preview-text':{},'branding-preview-coop':{},'branding-preview-default':{toggleAttribute(k,v){this[k]=v;}},
    'branding-preview-image':{src:'custom.png',removeAttribute(k){delete this[k];}},'branding-save':button(),'branding-remove':button()
  };
  brandingNodes['branding-remove'].closest=()=>brandingNodes['branding-form'];
  const brandingRequests=[],applied=[];
  const branding=vm.createContext({$:id=>brandingNodes[id],toast:m=>messages.push(m),
    document:{querySelectorAll:()=>[brandingNodes['branding-save'],brandingNodes['branding-remove']]},
    CoopVote:{api:async(route,options)=>{brandingRequests.push({route,...options});return {...options.body};}},
    window:{CoopVoteBranding:{apply:data=>applied.push(data)}},refresh:async()=>false,
    syncPasswordButton(){},syncRequirementButton(){}
  });
  vm.runInContext('let mutation=false,refreshId=0,imageRevision=7,imageLoading=true,brandingLogo="custom.png",savedBranding={text:"Custom Brand",coopName:"Custom Cooperative",logo:"custom.png"};',branding);
  for(const name of ['brandingHasChanges','syncBrandingButton','loadBranding','previewBranding','resetBranding','busyControls','run'])vm.runInContext(extract(name),branding);
  const resetting=branding.resetBranding();await branding.resetBranding();await resetting;
  assert.equal(brandingRequests.length,1,'double-clicking default sends only one save');
  assert.equal(brandingRequests[0].route,'branding');assert.equal(brandingRequests[0].method,'PUT');
  assert.equal(JSON.stringify(brandingRequests[0].body),JSON.stringify({text:'CoopVote',coopName:'Santiago City Multi-Purpose Cooperative',logo:null}),'default action saves the complete identity immediately');
  assert.equal(applied.length,1,'saved defaults are immediately applied to the sidebar');
  assert.equal(brandingNodes['branding-text'].value,'CoopVote');
  assert.equal(brandingNodes['branding-coop-name'].value,'Santiago City Multi-Purpose Cooperative');
  assert.equal(brandingNodes['branding-file'].value,'');
  assert.equal(brandingNodes['branding-preview-image'].hidden,true);
  assert.equal(brandingNodes['branding-preview-default'].hidden,false);
  assert.equal(brandingNodes['branding-preview-coop'].textContent,'Santiago City Multi-Purpose Cooperative');
  assert.equal(brandingNodes['branding-save'].disabled,true,'reset has already saved the defaults');
  assert.equal(brandingError.textContent,'');
  assert.ok(vm.runInContext('imageRevision',branding)>7,'reset invalidates a pending image conversion');
  assert.equal(vm.runInContext('refreshId',branding),1,'a late refresh cannot replace the reset values');
  assert.equal(vm.runInContext('brandingLogo',branding),null);
  vm.runInContext('mutation=true',branding);brandingNodes['branding-text'].value='Saving';branding.resetBranding();
  assert.equal(brandingNodes['branding-text'].value,'Saving','reset cannot interrupt a save');
  vm.runInContext('mutation=false',branding);
  branding.loadBranding({text:'CoopVote',coopName:'Santiago City Multi-Purpose Cooperative',logo:null});
  assert.equal(brandingNodes['branding-save'].disabled,true,'unchanged saved branding cannot be submitted');
  await branding.resetBranding();assert.equal(brandingNodes['branding-save'].disabled,true,'resetting saved defaults leaves no pending changes');
  for(const field of ['branding-text','branding-coop-name']){
    brandingNodes[field].value='New Name';branding.previewBranding();
    assert.equal(brandingNodes['branding-save'].disabled,false,'changing '+field+' enables save');
    const original=field==='branding-text'?'CoopVote':'Santiago City Multi-Purpose Cooperative';
    brandingNodes[field].value='  '+original+'  ';branding.previewBranding();
    assert.equal(brandingNodes['branding-save'].disabled,true,'reverting '+field+' disables save, ignoring surrounding spaces');
    brandingNodes[field].value=original;
  }
  vm.runInContext('brandingLogo="new.png"',branding);branding.previewBranding();
  assert.equal(brandingNodes['branding-save'].disabled,false,'logo-only changes enable save');
  vm.runInContext('imageLoading=true',branding);branding.previewBranding();
  assert.equal(brandingNodes['branding-save'].disabled,true,'image conversion blocks save');
  branding.loadBranding({text:'CoopVote',coopName:'Santiago City Multi-Purpose Cooperative',logo:'server-normalized.webp'});
  assert.equal(brandingNodes['branding-save'].disabled,true,'server-normalized saved image becomes the new baseline');
  brandingNodes['branding-text'].value='New Name';branding.previewBranding();
  brandingNodes['branding-coop-name'].value=' ';branding.previewBranding();
  assert.equal(brandingNodes['branding-save'].disabled,true,'blank required fields cannot be saved');
  vm.runInContext('savedBranding=null',branding);brandingNodes['branding-coop-name'].value='CoopVote';branding.previewBranding();
  assert.equal(brandingNodes['branding-save'].disabled,true,'save waits for the initial settings to load');
  branding.loadBranding({text:'Existing Brand',coopName:'Existing Cooperative',logo:'existing.png'});
  branding.CoopVote.api=async()=>{throw Error('Connection failed');};
  const appliedBefore=applied.length;await branding.resetBranding();
  assert.equal(brandingNodes['branding-text'].value,'Existing Brand','failed reset preserves the current logo text');
  assert.equal(brandingNodes['branding-coop-name'].value,'Existing Cooperative');
  assert.equal(vm.runInContext('brandingLogo',branding),'existing.png');
  assert.equal(applied.length,appliedBefore,'failed request never applies an unsaved default');
  assert.equal(brandingError.textContent,'Connection failed');
  assert.equal(brandingNodes['branding-remove'].disabled,false,'failed reset can be retried');
  context.syncBrandingButton=()=>{primary.disabled=true;};
  await context.run(primary,async()=>({ok:true}),null,'Saved.');
  assert.equal(primary.disabled,true,'save state is recalculated after generic busy controls are restored');
  const themeRequests=[],themeApplies=[],brandApplies=[];
  const themeContext=vm.createContext({isAdmin:true,mutation:false,user:{id:1},
    api:async(route,method,body)=>{themeRequests.push({route,method,body});return {theme:body.theme,...(body.resetBranding?{branding:{text:'CoopVote',coopName:'Santiago City Multi-Purpose Cooperative',logo:null}}:{})};},
    run:async(button,work,after)=>after(await work()),
    CoopVoteAppearance:{remember:(user,theme)=>themeApplies.push(theme)},
    loadBranding:data=>brandApplies.push(data),window:{CoopVoteBranding:{apply:data=>brandApplies.push(data)}}
  });
  vm.runInContext(extract('chooseTheme'),themeContext);
  await themeContext.chooseTheme({dataset:{theme:'navy'}},'navy');
  assert.equal(themeRequests.length,1,'selected Default theme still restores custom branding');
  assert.equal(themeRequests[0].body.resetBranding,true);
  assert.equal(themeApplies[0],'navy');assert.equal(brandApplies.length,2,'form and sidebar receive the saved identity');
  await themeContext.chooseTheme({dataset:{theme:'forest'}},'navy');
  assert.equal(themeRequests[1].body.resetBranding,undefined,'other themes preserve branding');
  themeContext.isAdmin=false;
  await themeContext.chooseTheme({dataset:{theme:'navy'}},'forest');
  assert.equal(themeRequests[2].body.resetBranding,undefined,'voter theme changes do not reset the cooperative');
  await themeContext.chooseTheme({dataset:{theme:'navy'}},'navy');assert.equal(themeRequests.length,3,'already selected voter theme remains a no-op');
  themeContext.isAdmin=true;themeContext.api=async()=>{throw Error('Save failed');};
  const appliedCount=themeApplies.length;
  await assert.rejects(themeContext.chooseTheme({dataset:{theme:'navy'}},'forest'),/Save failed/);
  assert.equal(themeApplies.length,appliedCount,'failed default save changes neither theme nor branding locally');
  console.log('Management actions passed: duplicate suppression, theme/branding reset, permissions, retry, disabled states, refresh, stale reads and password validation.');
})().catch(e=>{console.error(e);process.exitCode=1;});
