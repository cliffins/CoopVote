'use strict';
(() => {
 const $=id=>document.getElementById(id);
 let token='';
 const message=(text,error=false)=>{$('message').textContent=text;$('message').className=error?'error':'success';};
 async function api(body) {
  const response=await fetch('api/index.php?r=auth/password-recovery',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify(body)});
  const data=await response.json().catch(()=>({error:'The server could not complete your request. Please try again.'}));
  if(!response.ok || !data.ok)throw new Error(data.error || 'The request could not be completed.');
  return data;
 }
 $('request-form').addEventListener('submit',async event=>{
  event.preventDefault();const button=$('request-submit');if(button.disabled)return;
  button.disabled=true;button.textContent='Sending…';message('');
  try{const data=await api({action:'request',email:$('email').value.trim()});message(data.message);}
  catch(error){message(error.message,true);}
  finally{button.disabled=false;button.textContent='Send reset link';}
 });
 $('reset-form').addEventListener('submit',async event=>{
  event.preventDefault();const button=$('reset-submit');if(button.disabled)return;
  if($('password').value!==$('confirm-password').value){message('Passwords do not match.',true);return;}
  button.disabled=true;button.textContent='Saving…';message('');
  try{
   const data=await api({action:'complete',token,password:$('password').value,confirmPassword:$('confirm-password').value});
   $('reset-form').reset();$('reset-form').hidden=true;$('heading').textContent='Password reset';$('description').textContent='Your previous sessions have been signed out.';message(data.message);
  }catch(error){message(error.message,true);}
  finally{button.disabled=false;button.textContent='Reset password';}
 });
 $('toggle-password').addEventListener('click',()=>{
  const show=$('password').type==='password';for(const id of ['password','confirm-password'])$(id).type=show?'text':'password';
  $('toggle-password').textContent=show?'Hide':'Show';$('toggle-password').setAttribute('aria-pressed',String(show));$('toggle-password').setAttribute('aria-label',show?'Hide passwords':'Show passwords');
 });
 function readLink() {
  token=new URLSearchParams(location.hash.slice(1)).get('token') || '';
  if(location.hash) history.replaceState(null,'',location.pathname);
  message('');$('reset-form').reset();$('reset-form').hidden=true;
  $('request-form').hidden=Boolean(token);$('new-link').hidden=!token;
  if(!token){$('heading').textContent='Forgot password?';$('description').textContent='Enter the verified email address on your account to request a password reset link.';return;}
  const requested=token;
  $('heading').textContent='Reset your password';$('description').textContent='Checking your reset link…';
  api({action:'inspect',token:requested}).then(()=>{
   if(token!==requested)return;
   $('description').textContent='Choose a new password for your account.';$('reset-form').hidden=false;$('password').focus();
  }).catch(error=>{if(token!==requested)return;$('description').textContent='Request a new link to continue.';message(error.message,true);});
 }
 window.addEventListener('hashchange',readLink);
 readLink();
})();
