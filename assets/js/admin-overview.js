function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('show');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('overlay').classList.remove('show');}
(async()=>{
  'use strict';
  const $=id=>document.getElementById(id), user=await CoopVote.guard('admin');
  if(!user)return;
  const text=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const number=value=>Number(value).toLocaleString();
  $('header-date').textContent=new Date().toLocaleDateString('en-US',{weekday:'long',month:'long',day:'numeric',year:'numeric',timeZone:'Asia/Manila'});
  let busy=false,loaded=false;
  async function refresh(){
    if(busy)return;busy=true;
    try{
      const data=await CoopVote.api('dashboard&view=overview');
      $('overview-voters').textContent=number(data.voters.registered);
      $('overview-active-voters').textContent=number(data.voters.active)+' currently active';
      $('overview-officers').textContent=number(data.officers.active);
      $('overview-total-officers').textContent=number(data.officers.total)+' officer accounts in total';
      $('overview-pending').textContent=number(data.pendingVerification);
      $('attention-count').textContent=number(data.pendingVerification)+' pending';
      const attention=data.attention.length?data.attention.map(item=>`<a class="ov-attention-item" href="officer-management.php"><span class="ov-item-marker ${item.kind==='failed'?'ov-marker-error':''}" aria-hidden="true">${item.kind==='failed'?'!':'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/></svg>'}</span><span><strong>${text(item.title)}</strong><small>${text(item.description)}</small></span><span class="ov-count">${number(item.count)}</span></a>`).join(''):'<div class="ov-empty ov-clear"><span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg></span><strong>You’re all caught up</strong><p>No officer verification requests need attention.</p></div>';
      // Preserve focused links and scroll position when background data has not changed.
      if($('overview-attention').innerHTML!==attention)$('overview-attention').innerHTML=attention;
      const activity=data.recentActivity.length?data.recentActivity.map(item=>`<li class="ov-activity-item"><span class="ov-timeline-dot" aria-hidden="true"></span><div><p>${text(item.title)}</p><div class="ov-activity-meta"><strong>${text(item.actor)}</strong><span title="${text(item.date)}">${text(item.time)}</span></div></div></li>`).join(''):'<li class="ov-empty">No administrator activity recorded yet.</li>';
      if($('overview-activity').innerHTML!==activity)$('overview-activity').innerHTML=activity;
      $('dashboard-error').hidden=true;loaded=true;
    }catch(error){
      $('dashboard-error').textContent=(loaded?'Showing the last loaded overview. ':'')+(error.message||'Unable to load the overview.')+' The dashboard will retry automatically.';
      $('dashboard-error').hidden=false;
      if(!loaded){$('overview-attention').innerHTML='<p class="ov-empty">Requests could not be loaded.</p>';$('overview-activity').innerHTML='<li class="ov-empty">Activity could not be loaded.</li>';}
    }finally{busy=false;}
  }
  await refresh();CoopVote.watch(refresh,30000);
})();
