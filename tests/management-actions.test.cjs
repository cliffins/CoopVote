const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('assets/js/management.js','utf8');
new vm.Script(source);
function extract(name){const m=source.match(new RegExp('(?:async )?function '+name+'\\([^]*?\\n  }'));assert.ok(m,name+' found');return m[0];}
function button(disabled=false){return {disabled,dataset:{},innerHTML:'<span class="theme-preview">Preview</span><span>Navy</span>',attrs:{},setAttribute(k,v){this.attrs[k]=v;},removeAttribute(k){delete this.attrs[k];},closest(){return null;}};}
(async()=>{
  const primary=button(),previouslyDisabled=button(true),messages=[],errors=[],timers=[];
  const context=vm.createContext({document:{querySelectorAll:()=>[primary,previouslyDisabled]},toast:m=>messages.push(m),syncPasswordButton(){},syncRequirementButton(){},syncRefreshButtons(){},refresh:async()=>false,setTimeout:fn=>timers.push(fn),Date:{now:()=>1000}});
  vm.runInContext('let mutation=false,refreshId=0,refreshing=false,nextRefresh=0;',context);
  for(const name of ['busyControls','run','refreshFromButton'])vm.runInContext(extract(name),context);
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
  messages.length=0;let reads=0;context.refresh=async()=>{reads++;return false;};
  await context.refreshFromButton(primary);await context.refreshFromButton(primary);
  assert.equal(reads,1,'refresh cooldown blocks repeated requests');assert.equal(messages.length,0,'failed refresh never announces success');
  vm.runInContext('nextRefresh=0',context);context.refresh=async()=>true;
  await context.refreshFromButton(primary);assert.equal(messages.at(-1),'Latest data loaded.');

  // Exercise the real refresh failure handling, including late responses.
  let resolveOld,rendered=0;
  const dataContext=vm.createContext({active:()=> 'themes',error:m=>errors.push(m),api:()=>new Promise(r=>resolveOld=r),CoopVoteAppearance:{remember(){}},user:{id:1},renderThemes(){rendered++;}});
  vm.runInContext('let refreshId=0;',dataContext);vm.runInContext(extract('refresh'),dataContext);
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
  assert.equal((html.match(/id="refresh-page"/g)||[]).length,1,'one stable header refresh control');
  assert.doesNotMatch(source,/id="refresh-page"/,'rendering modules does not replace the refresh control');
  assert.match(html,/id="confirm-action"/);assert.match(source,/confirm-action'\)\.textContent=title/,'confirm label names its action');
  const nodes={'officer-search':{value:''},'officer-status':{value:''},accounts:{innerHTML:'',querySelectorAll:()=>[]}};
  const filters=vm.createContext({$:id=>nodes[id],user:{id:1},esc:s=>String(s).replaceAll('<','&lt;').replaceAll('>','&gt;')});
  vm.runInContext('const accounts=[{id:1,name:"Jane Santos",username:"jane",email:"jane@test.coop",status:"active"},{id:2,name:"Ana Cruz",username:"ana",email:"ana@test.coop",status:"deactivated"}];',filters);
  vm.runInContext(extract('renderAccounts'),filters);filters.renderAccounts();assert.match(nodes.accounts.innerHTML,/Jane Santos/);assert.match(nodes.accounts.innerHTML,/Ana Cruz/);
  nodes['officer-status'].value='active';filters.renderAccounts();assert.doesNotMatch(nodes.accounts.innerHTML,/Ana Cruz/);
  nodes['officer-status'].value='';nodes['officer-search'].value='ANA@';filters.renderAccounts();assert.match(nodes.accounts.innerHTML,/Ana Cruz/);assert.doesNotMatch(nodes.accounts.innerHTML,/Jane Santos/);
  nodes['officer-search'].value='no match';filters.renderAccounts();assert.match(nodes.accounts.innerHTML,/No officers match/);
  console.log('Management actions passed: duplicate suppression, stable theme previews, retry, disabled state restoration, refresh failure/cooldown, stale reads and password validation.');
})().catch(e=>{console.error(e);process.exitCode=1;});
