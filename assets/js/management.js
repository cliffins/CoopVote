(async function () {
  'use strict';
  const $=id=>document.getElementById(id), api=(route,method='GET',body)=>CoopVote.api('management/'+route,{method,body});
  const user=await CoopVote.guard();
  if(!user)return;
  const isAdmin=user.role==='admin';let accounts=[], overview=null, selected=new URLSearchParams(location.search).get('election')||'', refreshId=0, mutation=false, confirmAction=null, toastTimer;
  $('identity').textContent=user.name || user.username;
  $('logout').onclick=()=>CoopVote.logout();
  const tabs=isAdmin?[['officers','Officer Management'],['approvals','Election Approvals'],['themes','Themes'],['branding','Branding']]:[['themes','Themes']];
  function closeMenu(){ $('sidebar').classList.remove('open');$('sidebar-overlay').hidden=true;$('menu-toggle').setAttribute('aria-expanded','false');$('sidebar').inert=window.matchMedia('(max-width:900px)').matches; }
  $('menu-toggle').onclick=()=>{const open=!$('sidebar').classList.contains('open');$('sidebar').classList.toggle('open',open);$('sidebar').inert=!open&&window.matchMedia('(max-width:900px)').matches;$('sidebar-overlay').hidden=!open;$('menu-toggle').setAttribute('aria-expanded',String(open));if(open)$('sidebar').querySelector('a')?.focus({preventScroll:true});};
  $('sidebar-overlay').onclick=closeMenu;
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&$('sidebar').classList.contains('open')){closeMenu();$('menu-toggle').focus();}});
  window.matchMedia('(max-width:900px)').addEventListener('change',closeMenu);closeMenu();
  function toast(message){clearTimeout(toastTimer);$('toast').textContent=message;$('toast').hidden=false;toastTimer=setTimeout(()=>$('toast').hidden=true,4500);}
  function error(message){$('page-error').textContent=message;$('page-error').hidden=!message;}
  function active(){return tabs.some(t=>t[0]===location.hash.slice(1))?location.hash.slice(1):tabs[0][0];}
  async function route(){
    const tab=active();$('heading').textContent=tabs.find(t=>t[0]===tab)[1];document.title='CoopVote '+$('heading').textContent;
    ['themes','officers','approvals','branding'].forEach(id=>$(id).hidden=id!==tab);
    $('section-label').textContent=['themes','branding'].includes(tab)?'Settings':'Management';
    CoopVoteAppearance.navigation(user);closeMenu();
    await refresh();
  }
  async function refresh(){
    const revision=++refreshId, tab=active();error('');
    try {
      if(tab==='themes'){const data=await api('preferences');if(revision!==refreshId)return;CoopVoteAppearance.remember(user,data.theme);renderThemes(data.theme);}
      else if(tab==='officers'){const data=await api('accounts');if(revision!==refreshId)return;accounts=data;renderAccounts();}
      else if(tab==='branding'){const data=await CoopVote.api('branding');if(revision!==refreshId)return;loadBranding(data);}
      else{const data=await api('overview');if(revision!==refreshId)return;overview=data;renderApprovals();}
      return true;
    }catch(e){if(revision===refreshId)error(e.message);return false;}
  }
  // Preserve button contents (including theme previews) through errors and retries.
  function busyControls(busy){
    document.querySelectorAll('main button, main input, main select, main textarea, dialog button, dialog input, dialog textarea').forEach(control=>{
      if(busy){control.dataset.wasDisabled=String(control.disabled);control.disabled=true;}
      else if(control.dataset.wasDisabled!==undefined){control.disabled=control.dataset.wasDisabled==='true';delete control.dataset.wasDisabled;}
    });
  }
  async function run(button,work,after,message){
    if(mutation||!button)return;mutation=true;busyControls(true);button.setAttribute('aria-busy','true');
    const target=button.closest('form')?.querySelector('.form-error');if(target)target.textContent='';
    // Ignore any read that began before this write, so it cannot redraw stale controls.
    refreshId++;
    try{const result=await work();if(after)after(result);if(message)toast(message);await refresh();}
    catch(e){const form=button.closest('form'), target=form?.querySelector('.form-error');if(target)target.textContent=e.message;else toast(e.message);}
    finally{mutation=false;busyControls(false);button.removeAttribute('aria-busy');syncPasswordButton();syncRequirementButton();syncRefreshButtons();syncBrandingButton();}
  }
  let refreshing=false, nextRefresh=0;
  function syncRefreshButtons(){ $('refresh-page').disabled=mutation||refreshing||Date.now()<nextRefresh; }
  async function refreshFromButton(button){
    if(mutation||refreshing||Date.now()<nextRefresh)return;
    refreshing=true;button.disabled=true;button.setAttribute('aria-busy','true');
    try{if(await refresh())toast('Latest data loaded.');}
    finally{refreshing=false;nextRefresh=Date.now()+1500;button.removeAttribute('aria-busy');syncRefreshButtons();setTimeout(syncRefreshButtons,1500);}
  }
  $('refresh-page').onclick=e=>refreshFromButton(e.currentTarget);
  document.addEventListener('click',e=>{if(mutation&&e.target.closest('.sidebar a')){e.preventDefault();toast('Wait for the current action to finish.');}},true);
  const previews={navy:['#0b1931','#162a52','#c9a84c'],light:['#f2f5fa','#d6e1f0','#735615'],forest:['#102820','#20483a','#c8c879'],ocean:['#102b38','#1d4b5d','#84d2de'],plum:['#291f35','#493856','#d5b1cd'],slate:['#242b34','#394652','#b7c9df']};
  function renderThemes(current){
    $('theme-grid').innerHTML=Object.entries(previews).map(([key,p])=>`<button type="button" class="theme-choice" data-theme="${key}" aria-pressed="${key===current}"><span class="theme-preview" aria-hidden="true" style="--preview-bg:${p[0]};--preview-card:${p[1]};--preview-accent:${p[2]}"><span class="preview-nav"><i></i><i></i><i></i><i></i></span><span class="preview-content"><span class="preview-heading"></span><span class="preview-metrics"><i></i><i></i><i></i></span><span class="preview-table"><i></i><i></i><i></i></span></span></span><span class="theme-caption"><strong>${key[0].toUpperCase()+key.slice(1)}</strong><span class="theme-state">${key===current?'Selected':'Choose'}</span></span></button>`).join('');
    $('theme-grid').querySelectorAll('button').forEach(b=>{
      if(b.dataset.theme==='navy'){
        b.querySelector('strong').textContent='Default (Navy)';
        if(isAdmin){b.querySelector('.theme-state').textContent='Restore defaults';b.setAttribute('aria-label','Restore default theme, logo, logo text, and cooperative name');}
      }
      b.onclick=()=>chooseTheme(b,current);
    });
  }
  function chooseTheme(button,current){
    const theme=button.dataset.theme,resetBranding=isAdmin&&theme==='navy';
    if(mutation||(theme===current&&!resetBranding))return;
    return run(button,()=>api('preferences','PUT',{theme,...(resetBranding?{resetBranding:true}:{})}),data=>{
      CoopVoteAppearance.remember(user,data.theme);
      if(data.branding){loadBranding(data.branding);window.CoopVoteBranding?.apply(data.branding);}
    },resetBranding?'Default theme and branding restored.':'Theme saved.');
  }
  function renderAccounts(){
    const query=$('officer-search').value.trim().toLocaleLowerCase(),status=$('officer-status').value;
    const visible=accounts.filter(a=>(!status||a.status===status)&&(!query||[a.name,a.username,a.email].join(' ').toLocaleLowerCase().includes(query)));
    $('officer-count').textContent=visible.length+' of '+accounts.length+' accounts';
    $('accounts').innerHTML=visible.map(a=>`<tr><td>${esc(a.name)}${a.id===user.id?' (you)':''}</td><td>${esc(a.username)}</td><td>${esc(a.email)}</td><td><span class="status ${a.status==='active'?'good':''}">${esc(a.status)}</span></td><td><div class="row-actions officer-actions"><button type="button" class="officer-action" data-edit="${a.id}">Edit</button>${a.id!==user.id?`<button type="button" class="officer-action ${a.status==='active'?'danger-outline':''}" data-status="${a.id}">${a.status==='active'?'Deactivate':'Activate'}</button>`:''}</div></td></tr>`).join('')||'<tr><td colspan="5" class="empty-cell">No officers match your filters.</td></tr>';
    $('accounts').querySelectorAll('.status').forEach(b=>{const active=b.classList.contains('good');b.className='badge '+(active?'badge-green':'badge-muted');b.textContent=active?'Active':'Deactivated';});
    $('accounts').querySelectorAll('[data-edit]').forEach(b=>{
      b.className='icon-btn edit';b.title='Edit officer';b.setAttribute('aria-label','Edit officer');
      b.innerHTML='<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L9 17l-4 1 1-4Z"/></svg>';
      b.onclick=()=>openOfficer(accounts.find(a=>a.id===Number(b.dataset.edit)));
    });
    $('accounts').querySelectorAll('tr').forEach(row=>row.querySelectorAll('td').forEach((cell,i)=>{if(cell.colSpan===1)cell.dataset.label=['Name','Username','Email','Status','Actions'][i];}));
    $('accounts').querySelectorAll('[data-status]').forEach(b=>{
      const a=accounts.find(a=>a.id===Number(b.dataset.status)),status=a.status==='active'?'deactivated':'active',label=status==='active'?'Activate':'Deactivate';
      b.className='icon-btn '+(status==='active'?'activate':'deactivate');b.title=label+' officer';b.setAttribute('aria-label',label+' '+a.name);
      b.innerHTML='<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v10M6.3 5.7a9 9 0 1 0 11.4 0"/></svg>';
      b.onclick=()=>ask(label+' Officer',`${label} ${a.name}'s Administrator account?`,false,()=>api('accounts/'+a.id,'POST',{status}),status==='active'?'Officer activated.':'Officer deactivated.',status!=='active');
    });
  }
  function openOfficer(a){if(mutation)return;const f=$('officer-form');f.reset();f.querySelector('.form-error').textContent='';f.elements.id.value=a?.id||'';f.elements.name.value=a?.name||'';f.elements.username.value=a?.username||'';f.elements.username.disabled=!!a;f.elements.email.value=a?.email||'';$('officer-title').textContent=a?'Edit Officer':'Add Officer';$('save-officer').textContent=a?'Save Changes':'Create Officer';$('officer-dialog').showModal();}
  $('add-officer').onclick=()=>openOfficer();
  $('officer-search').addEventListener('input',renderAccounts);
  $('officer-status').onchange=renderAccounts;
  $('officer-form').elements.name.addEventListener('input',e=>{e.target.value=e.target.value.replace(/[^\p{L}\p{M} .'’\-]/gu,'');});
  $('officer-form').onsubmit=e=>{
    e.preventDefault();const f=e.currentTarget,id=f.elements.id.value;f.querySelector('.form-error').textContent='';
    const body={name:f.elements.name.value.trim(),username:f.elements.username.value.trim(),email:f.elements.email.value.trim()};
    if(!body.name||!body.email||(!id&&!body.username)){f.querySelector('.form-error').textContent='Complete all required fields.';return;}
    run(e.submitter,()=>api('accounts'+(id?'/'+id:''),id?'PUT':'POST',body),data=>{
      $('officer-dialog').close();if(!id){$('credentials').textContent='Username: '+data.username+'\nPassword: '+data.password;$('credentials-dialog').showModal();}
    },id?'Officer updated.':'Officer created.');
  };
  $('copy-credentials').onclick=async()=>{try{await navigator.clipboard.writeText($('credentials').textContent);toast('Credentials copied.');}catch(_){toast('Select the credentials and copy them manually.');}};
  $('credentials-dialog').addEventListener('close',()=>$('credentials').textContent='');
  document.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>{if(!mutation)$(b.dataset.close).close();});
  document.querySelectorAll('dialog').forEach(d=>d.addEventListener('cancel',e=>{if(mutation)e.preventDefault();}));
  function ask(title,message,reason,work,success,danger=false){
    if(mutation)return;$('confirm-form').reset();$('confirm-form').querySelector('.form-error').textContent='';
    $('confirm-title').textContent=title;$('confirm-message').textContent=message;$('reason-label').hidden=!reason;$('confirm-form').elements.note.required=reason;
    $('confirm-action').textContent=title;$('confirm-action').className=danger?'danger-outline':'primary';
    confirmAction={work,success};$('confirm-dialog').showModal();
  }
  $('confirm-dialog').addEventListener('close',()=>{confirmAction=null;});
  $('confirm-form').onsubmit=e=>{e.preventDefault();const note=e.currentTarget.elements.note.value.trim();if(e.currentTarget.elements.note.required&&!note){e.currentTarget.querySelector('.form-error').textContent='Enter a reason for rejection.';return;}const action=confirmAction;if(action)run(e.submitter,()=>action.work(note),()=>$('confirm-dialog').close(),action.success);};
  function passwordIssue(){
    const f=$('password-form'), password=f.elements.newPassword.value;
    if(!f.elements.currentPassword.value.trim())return 'Enter your current password.';
    const strength=[/\p{Lu}/u,/\p{Ll}/u,/\p{N}/u,/[\p{P}\p{S}]/u].filter(r=>r.test(password)).length;
    if([...password].length<8||new TextEncoder().encode(password).length>72||strength<3)return 'Use 8 or more characters and at least 3 character types.';
    return password===f.elements.confirmPassword.value?'':'New passwords do not match.';
  }
  function updatePasswordStrength(){
    const password=$('password-form').elements.newPassword.value;
    const types=[/\p{Lu}/u,/\p{Ll}/u,/\p{N}/u,/[\p{P}\p{S}]/u].filter(r=>r.test(password)).length;
    const valid=[...password].length>=8&&new TextEncoder().encode(password).length<=72&&types>=3;
    const strong=valid&&[...password].length>=12&&types===4;
    const score=!password?0:valid?(strong?4:3):1;
    const label=!password?'Enter a password':new TextEncoder().encode(password).length>72?'Too long — maximum 72 bytes':valid?(strong?'Strong password':'Good password'):'Weak — use 8+ characters and 3 character types';
    const meter=$('password-meter');meter.setAttribute('aria-valuenow',String(score));meter.setAttribute('aria-valuetext',label);
    meter.parentElement.dataset.level=!password?'empty':valid?'good':'weak';
    $('password-strength-fill').style.width=(score*25)+'%';$('password-strength-text').textContent=label;
  }
  function syncPasswordButton(){ $('save-password').disabled=mutation||!!passwordIssue();updatePasswordStrength(); }
  $('password-form').addEventListener('input',()=>{const f=$('password-form');f.querySelector('.form-error').textContent=f.elements.currentPassword.value&&f.elements.newPassword.value&&f.elements.confirmPassword.value?passwordIssue():'';syncPasswordButton();});
  $('cancel-password').onclick=()=>{if(mutation)return;$('password-form').reset();$('password-form').querySelector('.form-error').textContent='';syncPasswordButton();document.querySelector('.password-settings').open=false;};
  $('password-form').onsubmit=e=>{e.preventDefault();const f=e.currentTarget,issue=passwordIssue();if(issue){f.querySelector('.form-error').textContent=issue;return;}const body={currentPassword:f.elements.currentPassword.value,newPassword:f.elements.newPassword.value};run(e.submitter,()=>CoopVote.api('voter/change-password',{method:'POST',body}),()=>f.reset(),'Password updated.');};
  function syncRequirementButton(){ $('save-requirement').disabled=mutation||!overview||Number($('threshold').value)===Number(overview.requiredApprovals); }
  $('threshold').onchange=syncRequirementButton;
  function renderApprovals(){
    $('threshold').innerHTML=Array.from({length:overview.activeAdmins},(_,i)=>`<option value="${i+1}">${i+1}</option>`).join('');$('threshold').value=overview.requiredApprovals;$('admin-count').textContent=overview.activeAdmins+' active Administrator accounts';
    syncRequirementButton();
    const elections=overview.elections;if(!elections.some(e=>String(e.id)===selected))selected=String(elections.find(e=>['Draft','Closed'].includes(e.status))?.id||elections[0]?.id||'');
    $('election-select').innerHTML=elections.map(e=>`<option value="${e.id}">${esc(e.title)} — ${esc(e.status)}</option>`).join('');$('election-select').value=selected;renderReview();
  }
  $('approval-config').onsubmit=e=>{e.preventDefault();if(!overview||Number($('threshold').value)===Number(overview.requiredApprovals))return;run(e.submitter,()=>api('config','PUT',{requiredApprovals:Number($('threshold').value)}),null,'Approval requirement saved.');};
  $('election-select').onchange=e=>{selected=e.target.value;renderReview();};
  function renderReview(){
    const e=overview.elections.find(e=>String(e.id)===selected);if(!e){$('review').textContent='No elections available.';return;}
    const r=e.request, eligible=['Draft','Upcoming','Closed'].includes(e.status), reviewed=r?.decisions.some(d=>Number(d.admin_id)===user.id), ready=r&&!r.stale&&!r.rejections&&r.approvals>=Number(r.required_approvals), resubmit=!r||r.stale||r.rejections;
    const status=!eligible?e.status:!r?'Not submitted':r.stale?'Election changed':r.rejections?'Rejected':ready?'Approved to open':'Awaiting approvals';
    $('review').innerHTML=`<div class="review-summary"><div class="section-heading"><h2>${esc(e.title)}</h2><span class="status ${ready?'good':r?.rejections?'bad':'warning'}">${esc(status)}</span></div><div class="review-dates"><span>Starts: ${esc(e.start)}</span><span>Ends: ${esc(e.end)}</span></div>
    <p>Positions: ${e.positions.map(p=>esc(p.position_name)).join(', ')||'None'}</p><div class="review-candidates">${e.candidates.map(c=>`<article class="review-candidate"><strong>${esc(c.name)}</strong><p>${esc(c.position)}${c.active?'':' · Withdrawn'}</p><p class="hint">${esc(c.platform||'')}</p></article>`).join('')||'<p>No candidates added.</p>'}</div>
    ${r?`<div><strong>${r.approvals} of ${r.required_approvals} approvals</strong><progress max="${r.required_approvals}" value="${Math.min(r.approvals,r.required_approvals)}" aria-label="Admin approvals"></progress></div>${r.proposed_end?`<p>Proposed reopening deadline: ${esc(r.proposed_end)}</p>`:''}<ul class="decision-list">${r.decisions.map(d=>`<li><strong>${esc(d.name)} — ${d.decision==='approve'?'Approved':'Rejected'}</strong><p class="hint">${esc(d.decided_at)}</p>${d.note?`<p>${esc(d.note)}</p>`:''}</li>`).join('')}</ul>`:''}
    ${r?.stale?'<p>The election changed or its deadline passed. Submit it again for review.</p>':''}
    ${eligible&&resubmit?`<form id="request-form" class="request-form">${e.status==='Closed'?'<label>New closing date and time<input name="proposedEnd" type="datetime-local" required></label>':''}<p class="form-error" role="alert"></p><div class="form-actions single-action"><button type="submit" class="primary">${r?'Resubmit for Approval':'Submit for Approval'}</button></div></form>`:''}
    ${eligible&&r&&!resubmit?`<div class="review-actions"><div class="toolbar-actions">${!reviewed?`<button type="button" class="${ready?'':'primary'}" id="approve">Approve</button><button type="button" class="danger-outline" id="reject">Reject</button>`:'<span class="hint">Your decision is recorded.</span>'}</div>${ready?`<button type="button" class="primary" id="open-election">${e.status==='Closed'?'Reopen Election':'Open Election'}</button>`:''}</div>`:''}</div>`;
    if($('request-form'))$('request-form').onsubmit=event=>{event.preventDefault();const body=e.status==='Closed'?{proposedEnd:event.currentTarget.elements.proposedEnd.value}:{};run(event.submitter,()=>api('elections/'+e.id+'/submit','POST',body),null,'Election submitted for approval.');};
    if($('approve'))$('approve').onclick=()=>ask('Approve Election',`Approve ${e.title} for opening?`,false,()=>api('elections/'+e.id+'/decide','POST',{requestId:r.request_id,decision:'approve'}),'Election approved.');
    if($('reject'))$('reject').onclick=()=>ask('Reject Election',`Explain what needs to change in ${e.title}.`,true,note=>api('elections/'+e.id+'/decide','POST',{requestId:r.request_id,decision:'reject',note}),'Election rejected.',true);
    if($('open-election'))$('open-election').onclick=()=>ask(e.status==='Closed'?'Reopen Election':'Open Election',`Open ${e.title} for voting now?`,false,()=>CoopVote.api('elections/'+e.id+'/'+(e.status==='Closed'?'reopen':'activate'),{method:'PATCH',body:e.status==='Closed'?{endDate:r.proposed_end.replace(' ','T').slice(0,16)}:{}}),'Election is open for voting.');
  }
  let brandingLogo=null, savedBranding=null, imageRevision=0, imageLoading=false;
  function loadBranding(data){
    savedBranding={text:data.text.trim(),coopName:(data.coopName||'Santiago City Multi-Purpose Cooperative').trim(),logo:data.logo||null};
    imageRevision++;imageLoading=false;brandingLogo=savedBranding.logo;
    $('branding-text').value=savedBranding.text;$('branding-coop-name').value=savedBranding.coopName;$('branding-file').value='';
    previewBranding();
  }
  function brandingHasChanges(){
    return !!savedBranding && ($('branding-text').value.trim()!==savedBranding.text || $('branding-coop-name').value.trim()!==savedBranding.coopName || brandingLogo!==savedBranding.logo);
  }
  function syncBrandingButton(){
    $('branding-save').disabled=mutation||imageLoading||!$('branding-text').value.trim()||!$('branding-coop-name').value.trim()||!brandingHasChanges();
  }
  function previewBranding(){
    $('branding-preview-text').textContent=$('branding-text').value.trim()||'Logo text';
    $('branding-preview-coop').textContent=$('branding-coop-name').value.trim()||'Cooperative name';
    $('branding-preview-default').toggleAttribute('hidden',!!brandingLogo);
    const img=$('branding-preview-image');img.hidden=!brandingLogo;
    if(brandingLogo)img.src=brandingLogo;else img.removeAttribute('src');
    syncBrandingButton();
  }
  $('branding-text').oninput=previewBranding;
  $('branding-coop-name').oninput=previewBranding;
  function resetBranding(){
    if(mutation)return;
    imageRevision++;imageLoading=false;
    return run($('branding-remove'),()=>CoopVote.api('branding',{method:'PUT',body:{text:'CoopVote',coopName:'Santiago City Multi-Purpose Cooperative',logo:null}}),data=>{
      loadBranding(data);window.CoopVoteBranding?.apply(data);
    },'Default branding restored.');
  }
  $('branding-remove').onclick=resetBranding;
  $('branding-file').onchange=async e=>{
    const file=e.target.files[0],revision=++imageRevision;if(!file)return;
    const error=$('branding-form').querySelector('.form-error');error.textContent='';
    imageLoading=true;previewBranding();
    try{
      if(!['image/png','image/jpeg','image/webp'].includes(file.type)||file.size>5*1024*1024)throw Error('Choose a PNG, JPEG or WebP image under 5 MB.');
      const bitmap=await createImageBitmap(file);
      const scale=Math.min(1,256/Math.max(bitmap.width,bitmap.height));
      const canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(bitmap.width*scale));canvas.height=Math.max(1,Math.round(bitmap.height*scale));
      canvas.getContext('2d').drawImage(bitmap,0,0,canvas.width,canvas.height);bitmap.close();
      const logo=canvas.toDataURL('image/webp',.8);
      if(logo.length>60000)throw Error('Choose a simpler logo image.');
      if(revision===imageRevision)brandingLogo=logo;
    }catch(err){if(revision===imageRevision){error.textContent=err.message;e.target.value='';}}
    finally{if(revision===imageRevision){imageLoading=false;previewBranding();}}
  };
  $('branding-form').onsubmit=e=>{
    e.preventDefault();if(mutation||imageLoading||!brandingHasChanges())return;
    const text=$('branding-text').value.trim(),coopName=$('branding-coop-name').value.trim();if(!text||!coopName)return;
    run(e.submitter,()=>CoopVote.api('branding',{method:'PUT',body:{text,logo:brandingLogo,coopName}}),data=>{loadBranding(data);window.CoopVoteBranding?.apply(data);},'Branding saved.');
  };
  window.addEventListener('hashchange',route);await route();
})();
