(function () {
  'use strict';
  // Runs in the head before the browser captures the incoming document.
  // Keep this flag after the transition so the fallback does not play a second time.
  window.addEventListener('pagereveal', function (event) {
    document.documentElement.classList.toggle('cv-native-transition', !!event.viewTransition);
  });

  // The same sidebar is rebuilt on each page, and settings mounts it after login.
  // Keep the requested offset even while its links, branding and fonts are loading.
  const rootPath = new URL(document.currentScript.src).pathname.replace(/assets\/js\/navigation\.js$/, '');
  const identityKey = 'cv.sidebar.identity.' + rootPath;
  let sidebar, key, target = 0, applied = 0, frame = 0;
  function identity(user) {
    if (!user || !['admin', 'member'].includes(user.role) || !Number.isSafeInteger(user.id) || user.id < 1) return null;
    return { id: user.id, role: user.role, name: String(user.name || ''),
      memberId: String(user.memberId || ''), roleName: String(user.roleName || '') };
  }
  function paintIdentity(user) {
    const name = document.getElementById('sidebar-name');
    const label = document.getElementById('sidebar-id');
    const avatar = document.getElementById('sidebar-avatar');
    if (name) name.textContent = user.name;
    if (label) label.textContent = user.memberId && user.memberId !== '\u2014' ? user.memberId : user.roleName;
    if (avatar) avatar.textContent = user.name.trim().split(/\s+/).slice(0, 2).map(part => part[0] || '').join('').toUpperCase();
  }
  // Presentation only: guards and API permissions must still use auth/me.
  function remember(user) {
    const value = identity(user);
    if (!value) return;
    try { sessionStorage.setItem(identityKey, JSON.stringify(value)); } catch (_) {}
  }
  function clear() {
    try { sessionStorage.removeItem(identityKey); } catch (_) {}
  }
  // Called directly after the aside, before the first paint and before API calls.
  function bootstrap() {
    let user;
    try { user = identity(JSON.parse(sessionStorage.getItem(identityKey))); } catch (_) {}
    const expected = location.pathname.startsWith(rootPath + 'admin/') ? 'admin'
      : location.pathname.startsWith(rootPath + 'voter/') ? 'member' : null;
    if (user && (!expected || expected === user.role)) {
      if (window.CoopVoteSidebar) window.CoopVoteSidebar.mount(user);
      paintIdentity(user);
      if (window.CoopVoteAppearance) window.CoopVoteAppearance.navigation(user);
    }
    if (window.CoopVoteBranding) window.CoopVoteBranding.preview();
    restore();
  }
  function read() {
    try {
      const value = Number(sessionStorage.getItem(key));
      return Number.isFinite(value) && value >= 0 ? value : 0;
    } catch (_) { return target; }
  }
  function save() {
    if (!key) return;
    try { sessionStorage.setItem(key, String(target)); } catch (_) { /* Storage is optional. */ }
  }
  function restore() {
    if (!sidebar) return;
    applied = Math.min(target, Math.max(0, sidebar.scrollHeight - sidebar.clientHeight));
    sidebar.scrollTop = applied;
  }
  function schedule() {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(restore);
  }
  function bind(user) {
    const element = document.querySelector('.sidebar');
    if (!element) return;
    const nextKey = 'cv.sidebar.scroll.' + rootPath + '.' + user.role + '.' + user.id;
    if (sidebar === element) {
      if (key !== nextKey) { key = nextKey; target = read(); restore(); }
      return;
    }
    sidebar = element;
    key = nextKey;
    target = read();
    applied = sidebar.scrollTop;
    sidebar.addEventListener('scroll', function () {
      // Programmatic restoration may be clamped while async content is incomplete.
      // Do not replace the saved offset with that temporary position.
      if (Math.abs(sidebar.scrollTop - applied) < 1) return;
      target = applied = sidebar.scrollTop;
      save();
    }, { passive: true });
    sidebar.addEventListener('click', function (event) {
      if (!event.target.closest('a[href]')) return;
      target = sidebar.scrollTop;
      save();
    });
    new MutationObserver(schedule).observe(sidebar, { childList: true, subtree: true, characterData: true });
    if (window.ResizeObserver) {
      const resize = new ResizeObserver(schedule);
      resize.observe(sidebar);
      const logo = sidebar.querySelector('.sidebar-logo');
      if (logo) resize.observe(logo);
    }
    if (document.fonts) document.fonts.ready.then(schedule);
    restore();
  }
  window.addEventListener('resize', schedule);
  window.addEventListener('pagehide', save);
  window.addEventListener('pageshow', function (event) {
    if (event.persisted && key) target = read();
    restore();
  });
  window.CoopVoteNavigation = { bind, restore, bootstrap, remember, clear };
})();
