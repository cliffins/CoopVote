/* CSV import stays on Voter Management; the server validates every account. */
(function () {
  'use strict';
  const $=id=>document.getElementById(id), dialog=$('voter-import-dialog');
  const api=(action,body)=>CoopVote.api('voters/import/'+action,body===undefined?{}:{method:'POST',body});
  let batch=null,busy=false,mailBusy=false;
  const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function sync() {
    dialog.querySelectorAll('button').forEach(b=>b.disabled=busy||mailBusy);
    $('import-file').disabled=busy||mailBusy;
    $('import-preview').disabled=busy||mailBusy||!$('import-file').files.length;
    $('import-confirm').disabled=busy||mailBusy||!batch?.valid;
    $('import-close').disabled=busy;
  }
  function render() {
    const done=!!batch?.created;
    $('import-upload').hidden=done;
    $('import-review').hidden=!batch;
    $('import-confirm').hidden=!batch||done;
    $('import-download').hidden=!done;
    $('import-new').hidden=!done;
    $('import-email').hidden=!done||!batch.mailEnabled||!batch.created.some(r=>['pending','failed'].includes(r.delivery));
    $('import-complete-note').hidden=!done;
    if(batch) {
      $('import-summary').textContent=done?batch.created.length+' voter accounts created.':batch.valid+' ready to import · '+batch.invalid+' need correction';
      $('import-confirm').textContent='Import '+batch.valid+' valid voter'+(batch.valid===1?'':'s');
      $('import-skipped').hidden=done||!batch.invalid;
      $('import-result-header').textContent=done?'Email delivery':'Validation';
      $('import-rows').innerHTML=(done?batch.created:batch.rows).map(r=>{
        const d=r.data||r, name=done?r.name:d.first+' '+d.last;
        const detail=done?'Member ID: '+r.memberNo+' ? Username: '+r.username:'Member ID: '+(d.memberId||'Automatic')+' · Username: '+(d.username||'Automatic');
        const more=done?'':`<details><summary>Voter details</summary><p>${esc(d.phone)}<br>${esc(d.address)}<br>Date of birth: ${esc(d.dob)}</p></details>`;
        const labels={unknown:'Delivery unconfirmed ? check the inbox or hand over credentials',pending:'Waiting to send',sending:'Sending — refresh to check',sent:'Sent to mail server',failed:'Failed — retry email',disabled:'Email disabled — download credentials'};
        return `<tr><td>${r.row}</td><td><strong>${esc(name)}</strong><p class="import-hint">${esc(detail)}</p>${more}</td><td>${esc(d.email)}</td><td>${done?esc(labels[r.delivery]||r.delivery):Object.keys(r.errors).length?'<div class="import-errors">'+Object.entries(r.errors).map(([k,v])=>esc(k+': '+v)).join('\n')+'</div>':'<span class="import-ready">Ready</span>'}</td></tr>`;
      }).join('');
    }
    sync();
  }
  async function work(action) {
    if(busy||mailBusy)return;
    busy=true;$('import-error').textContent='';sync();
    try { await action(); }
    catch(e) { $('import-error').textContent=e.message;CoopVote.toast(e.message,'error'); }
    finally { busy=false;render(); }
  }
  $('import-voters-btn').onclick=()=>{
    if(mailBusy){dialog.showModal();render();return;}
    return work(async()=>{
    batch=await api('status');if(!batch.token)batch=null;
    dialog.showModal();render();
    });
  };
  $('import-close').onclick=()=>{if(!busy)dialog.close();};
  dialog.addEventListener('cancel',e=>{if(busy)e.preventDefault();});
  $('import-file').onchange=()=>{batch=null;render();};
  $('import-template').onclick=()=>work(()=>CoopVote.download('voters/import/template&format=xlsx'));
  $('import-template-csv').onclick=()=>work(()=>CoopVote.download('voters/import/template&format=csv'));
  $('import-preview').onclick=()=>work(async()=>{
    const file=$('import-file').files[0];
    if(!file||!file.name.toLowerCase().endsWith('.csv'))throw Error('Choose a CSV file. In Excel, save your list as CSV UTF-8.');
    if(file.size>262144)throw Error('Use a CSV file no larger than 256 KB.');
    const buffer=await file.arrayBuffer();
    let csv;try{csv=new TextDecoder('utf-8',{fatal:true}).decode(buffer);}catch(_){throw Error('Save the file as CSV UTF-8 in Excel, then upload it again.');}
    batch=await api('preview',{csv});
  });
  $('import-confirm').onclick=async()=>{
    await work(async()=>{
      if(!batch?.valid)return;
      if(!await CoopVoteVoterRegistration.check())return;
      batch=await api('commit',{token:batch.token});
      CoopVote.toast(batch.created.length+' voter accounts created.','success');
      if(typeof loadVoters==='function')loadVoters();
    });
    if(batch?.created&&batch.mailEnabled)sendEmails();
  };
  async function sendEmails() {
    if(busy||mailBusy||!batch?.created)return;
    mailBusy=true;sync();
    try {
      for(const row of batch.created.filter(r=>['pending','failed'].includes(r.delivery))) {
        row.delivery='sending';render();
        const result=await api('email',{token:batch.token,id:row.id});
        row.delivery=result.delivery;render();
      }
    } catch(e) { $('import-error').textContent=e.message+' Reopen this dialog to check delivery before retrying.'; }
    finally {mailBusy=false;render();}
  }
  $('import-email').onclick=sendEmails;
  $('import-download').onclick=()=>work(()=>CoopVote.download('voters/import/credentials'));
  $('import-new').onclick=()=>work(async()=>{
    await api('clear',{});batch=null;$('import-file').value='';
  });
  render();
})();
