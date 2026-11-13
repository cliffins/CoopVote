(() => {
 'use strict';
 // Opening another email link in this tab must inspect the new token too.
 window.addEventListener('hashchange',()=>{if(location.hash)location.reload();});
 // Fragments are not sent in HTTP access logs or referrers; remove it from history.
 let token=new URLSearchParams(location.hash.slice(1)).get('token')||'';
 history.replaceState(null,'',location.pathname);
 const $=id=>document.getElementById(id), form=$('verification-form');let purpose='',busy=false;
 async function request(action,values={}){
  const response=await fetch('api/index.php?r=auth/officer-verification',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,token,...values})});
  const data=await response.json();if(!response.ok)throw Error(data.error||'Verification failed. Try again.');return data;
 }
 $('toggle-password').onclick=()=>{const shown=$('password').type==='password';$('password').type=shown?'text':'password';$('toggle-password').textContent=shown?'Hide':'Show';$('toggle-password').setAttribute('aria-label',shown?'Hide password':'Show password');$('toggle-password').setAttribute('aria-pressed',String(shown));};
 form.onsubmit=async event=>{
  event.preventDefault();if(busy)return;busy=true;$('verify-submit').disabled=true;$('message').textContent='';
  try{
   const result=await request('complete',{password:$('password').value,confirmPassword:$('confirm-password').value});
   form.reset();form.hidden=true;token='';$('heading').textContent='Email verified';$('description').textContent='';$('message').className='success';$('message').textContent=result.message;
  }catch(error){$('message').textContent=error.message;}
  finally{busy=false;$('verify-submit').disabled=false;}
 };
 if(!/^[a-f0-9]{64}$/.test(token)){$('description').textContent='Open the newest verification link from your email.';return;}
 request('inspect').then(data=>{
  purpose=data.purpose;const invite=purpose==='invite';
  $('heading').textContent=invite?'Set up your officer account':'Confirm your new email';
  $('description').textContent=invite?`Hello ${data.name}. Verify ${data.email} and choose your own password.`:`Confirm ${data.email} with your current password. Your current email remains in use until you finish.`;
  $('password-label').textContent=invite?'New password':'Current password';$('password').autocomplete=invite?'new-password':'current-password';
  $('confirmation').hidden=!invite;$('confirm-password').required=invite;$('password-help').hidden=!invite;
  $('verify-submit').textContent=invite?'Verify email and activate account':'Verify and save new email';form.hidden=false;
 }).catch(error=>{$('description').textContent='Unable to verify this link.';$('message').textContent=error.message;});
})();
