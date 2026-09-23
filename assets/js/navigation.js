(function () {
  'use strict';
  // Runs in the head before the browser captures the incoming document.
  // Keep this flag after the transition so the fallback does not play a second time.
  window.addEventListener('pagereveal', function (event) {
    document.documentElement.classList.toggle('cv-native-transition', !!event.viewTransition);
  });

  // The same sidebar is rebuilt on each page, and settings mounts it after login.
  // Keep the requested offset even while its links, branding and fonts are loading.
  let sidebar, key, target = 0, applied = 0, frame = 0;
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
    if (!element || sidebar === element) return;
    sidebar = element;
    const root = new URL(document.querySelector('script[src*="assets/js/navigation.js"]').src).pathname
      .replace(/assets\/js\/navigation\.js$/, '');
    key = 'cv.sidebar.scroll.' + root + '.' + user.role + '.' + user.id;
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
  window.CoopVoteNavigation = { bind, restore };
})();
