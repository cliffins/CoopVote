(function () {
  'use strict';
  const names = ['navy','light','forest','ocean','plum','slate'];
  const root = document.documentElement;
  let revision = 0;
  function apply(theme) { root.dataset.cvTheme = names.includes(theme) ? theme : 'navy'; }
  try { apply(localStorage.getItem('cv.theme.last')); } catch (_) { apply('navy'); }
  function remember(user, theme) {
    revision++;
    apply(theme);
    try { localStorage.setItem('cv.theme.user.' + user.id, theme); localStorage.setItem('cv.theme.last', theme); } catch (_) {}
  }
  function navigation(user) {
    const sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;
    if (window.CoopVoteNavigation) window.CoopVoteNavigation.bind(user);
    const after = (anchor, element) => {
      if (anchor && anchor.nextElementSibling !== element) anchor.insertAdjacentElement('afterend', element);
    };
    const rootUrl = new URL(document.querySelector('script[src*="assets/js/appearance.js"]').src).href.replace(/assets\/js\/appearance\.js.*$/, '');
    const icons={officers:'<circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2M18 8l2 2 3-4"/>',approvals:'<rect x="4" y="3" width="16" height="18" rx="2"/><path d="m8 12 3 3 5-6"/>',themes:'<circle cx="12" cy="12" r="9"/><path d="M12 3v18"/>'};
    function link(key,label){
      let a=sidebar.querySelector('[data-cv-nav="'+key+'"]');if(a)return a;
      a=document.createElement('a');a.className='nav-item';a.dataset.cvNav=key;a.href=rootUrl+'management.html#'+key;
      a.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">'+icons[key]+'</svg><span>'+label+'</span>';return a;
    }
    if(user.role==='admin'){
      const voters=sidebar.querySelector('a[href$="voter-management.html"]');
      const elections=sidebar.querySelector('a[href$="election-management.html"]');
      if(voters)after(voters,link('officers','Officer Management'));
      if(elections)after(elections,link('approvals','Election Approvals'));
    }
    let settings=Array.from(sidebar.querySelectorAll('.sidebar-section')).find(el=>el.textContent.trim()==='Settings');
    if(!settings){settings=document.createElement('div');settings.className='sidebar-section';settings.textContent='Settings';sidebar.insertBefore(settings,sidebar.querySelector('.sidebar-footer'));}
    icons.branding='<rect x="3" y="3" width="18" height="18" rx="3"/><path d="m3 17 6-6 4 4 3-3 5 5"/>';
    const themes=link('themes','Themes');
    after(settings,themes);
    if(user.role==='admin')after(themes,link('branding','Branding'));
    sidebar.querySelectorAll('[data-cv-nav]').forEach(a=>{const current=location.pathname.endsWith('/management.html')&&a.hash===(location.hash|| (user.role==='admin'?'#officers':'#themes'));a.classList.toggle('active',current);if(current)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');});
    if (window.CoopVoteNavigation) window.CoopVoteNavigation.restore();
  }
  async function sync(user, preferences) {
    const currentRevision = revision;
    navigation(user);
    try { apply(localStorage.getItem('cv.theme.user.'+user.id) || 'navy'); } catch (_) { apply('navy'); }
    if (preferences && names.includes(preferences.theme)) {
      remember(user, preferences.theme);
      return;
    }
    try { const data=await CoopVote.api('management/preferences');if (currentRevision === revision) remember(user,data.theme); } catch (_) { /* Keep cached preference during a network interruption. */ }
  }
  window.CoopVoteAppearance={names,apply,remember,sync,navigation};
})();
