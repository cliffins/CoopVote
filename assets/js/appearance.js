(function () {
  'use strict';
  const names = ['navy','light','forest','ocean','plum','slate'];
  const root = document.documentElement;
  // One preference for this browser, shared by every account using it.
  const storageKey = 'cv.theme.device';
  const cookieName = 'cv_theme_device';
  function apply(theme) { root.dataset.cvTheme = names.includes(theme) ? theme : 'navy'; }
  function current() { return root.dataset.cvTheme || 'navy'; }
  function cookieTheme() {
    try {
      const cookie = document.cookie.split(';').map(value => value.trim()).find(value => value.startsWith(cookieName + '='));
      const theme = cookie ? cookie.slice(cookieName.length + 1) : '';
      return names.includes(theme) ? theme : '';
    } catch (_) { return ''; }
  }
  function persist(theme, mirrorLegacy = false) {
    let stored = false;
    try {
      localStorage.setItem(storageKey, theme);
      stored = localStorage.getItem(storageKey) === theme;
      // Old bookmarked pages can read the last explicitly selected color too.
      if (mirrorLegacy) localStorage.setItem('cv.theme.last', theme);
    } catch (_) {}
    try {
      document.cookie = cookieName + '=' + theme + '; Path=/; Max-Age=34560000; SameSite=Lax'
        + (window.location.protocol === 'https:' ? '; Secure' : '');
      stored = cookieTheme() === theme || stored;
    } catch (_) {}
    return stored;
  }
  let saved = '';
  try {
    saved = localStorage.getItem(storageKey);
  } catch (_) {}
  if (!names.includes(saved)) saved = cookieTheme();
  if (!names.includes(saved)) {
    try { saved = localStorage.getItem('cv.theme.last'); } catch (_) {}
  }
  if (!names.includes(saved)) saved = root.dataset.cvTheme;
  apply(saved);
  if (names.includes(saved)) persist(saved);
  function remember(theme) {
    // Old cached callers used remember(user, accountTheme). Never let them
    // overwrite the device choice or silently reset it to the default.
    if (!names.includes(theme)) return false;
    apply(theme);
    return persist(theme, true);
  }
  window.addEventListener('storage', event => {
    if (event.key === storageKey || event.key === null) {
      try {
        const stored = localStorage.getItem(storageKey);
        apply(names.includes(stored) ? stored : cookieTheme());
      } catch (_) { /* Keep the current theme. */ }
    }
  });
  function navigation(user) {
    const sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;
    if (window.CoopVoteNavigation) window.CoopVoteNavigation.bind(user);
    const after = (anchor, element) => {
      if (anchor && anchor.nextElementSibling !== element) anchor.insertAdjacentElement('afterend', element);
    };
    const rootUrl = new URL(document.querySelector('script[src*="assets/js/appearance.js"]').src).href.replace(/assets\/js\/appearance\.js.*$/, '');
    const modulePages={officers:'officer-management',approvals:'election-approvals',themes:'themes',branding:'branding'};
    const icons={officers:'<circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2M18 8l2 2 3-4"/>',approvals:'<rect x="4" y="3" width="16" height="18" rx="2"/><path d="m8 12 3 3 5-6"/>',themes:'<circle cx="12" cy="12" r="9"/><path d="M12 3v18"/>'};
    function link(key,label){
      let a=sidebar.querySelector('[data-cv-nav="'+key+'"]');if(a)return a;
      a=document.createElement('a');a.className='nav-item';a.dataset.cvNav=key;a.href=rootUrl+(user.role==='admin'?'admin/':'voter/')+modulePages[key]+'.php';
      a.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">'+icons[key]+'</svg><span>'+label+'</span>';return a;
    }
    if(user.role==='admin'){
      const voters=sidebar.querySelector('a[href$="voter-management.php"]');
      const elections=sidebar.querySelector('a[href$="election-management.php"]');
      if(voters)after(voters,link('officers','Officer Management'));
      if(elections)after(elections,link('approvals','Election Approvals'));
    }
    let settings=Array.from(sidebar.querySelectorAll('.sidebar-section')).find(el=>el.textContent.trim()==='Settings');
    if(!settings){settings=document.createElement('div');settings.className='sidebar-section';settings.textContent='Settings';sidebar.insertBefore(settings,sidebar.querySelector('.sidebar-footer'));}
    icons.branding='<rect x="3" y="3" width="18" height="18" rx="3"/><path d="m3 17 6-6 4 4 3-3 5 5"/>';
    const themes=link('themes','Themes');
    after(settings,themes);
    if(user.role==='admin')after(themes,link('branding','Settings'));
    sidebar.querySelectorAll('[data-cv-nav]').forEach(a=>{const current=location.pathname===new URL(a.href).pathname;a.classList.toggle('active',current);if(current)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');});
    if (window.CoopVoteNavigation) window.CoopVoteNavigation.restore();
  }
  function sync(user) {
    navigation(user);
  }
  window.CoopVoteAppearance={names,apply,current,remember,sync,navigation};
})();
