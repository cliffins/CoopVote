/* ══════════════════════════════════════════════════════════
   CoopVote — shared frontend runtime
   ──────────────────────────────────────────────────────────
   Loaded by every page. Provides:
     CoopVote.api(route, options)   → fetch wrapper returning JSON
     CoopVote.guard(role)           → redirect unless signed in
     CoopVote.logout()              → end the session properly
     CoopVote.download(route)       → trigger a CSV export
   ══════════════════════════════════════════════════════════ */
(function (window, document) {
  'use strict';

  // Page templates need esc even if later runtime initialization fails.
  // Function declarations below are available before any API request.
  window.esc = esc;

  /* ── Work out where the app lives ──────────────────────────
     This file is always at <root>/assets/js/app.js, so the
     project root can be derived from its own URL. That keeps
     every page working whether it sits at the root (login.html)
     or one level down (admin/…, voter/…).                     */
  const scriptEl = document.currentScript ||
    Array.prototype.slice.call(document.getElementsByTagName('script'))
      .filter(s => (s.src || '').indexOf('assets/js/app.js') !== -1).pop();

  const ROOT = (scriptEl ? scriptEl.src : '').replace(/assets\/js\/app\.js.*$/, '');
  const API = ROOT + 'api/index.php?r=';
  // Protected pages preload branding so the sidebar does not reflow after a slow API call.
  if (window.CoopVoteBranding) {
    document.addEventListener('DOMContentLoaded', () => window.CoopVoteBranding.start(), { once: true });
  } else {
    const brandingScript=document.createElement('script');
    brandingScript.src=ROOT+'assets/js/branding.js?v=20261013-stable-sidebar';
    document.head.appendChild(brandingScript);
  }

  // Close the mobile drawer for navigation, including the current page's link.
  // Use the rendered layout so each page keeps its own responsive breakpoint.
  function closeSidebarOnNavigation(event) {
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest('.sidebar a[href]');
    if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self') || link.getAttribute('aria-disabled') === 'true') return;
    const sidebar = link.closest('.sidebar');
    if (!sidebar.classList.contains('open') || window.getComputedStyle(sidebar).position !== 'fixed') return;
    sidebar.classList.remove('open');
    const overlay = document.getElementById('overlay');
    if (overlay) overlay.classList.remove('show');
    const settingsOverlay = document.getElementById('sidebar-overlay');
    if (settingsOverlay) settingsOverlay.hidden = true;
    document.querySelectorAll('.hamburger, [aria-controls="sidebar"]').forEach(button => button.setAttribute('aria-expanded', 'false'));
    if (sidebar.hasAttribute('data-shared-sidebar')) sidebar.inert = true;
  }
  document.addEventListener('click', closeSidebarOnNavigation);

  /* ── Which election the member is looking at ───────────────
     Several elections can run at once, so the choice is kept here and
     travels with every voter request. localStorage keeps it steady as
     the member moves between Candidates, the Ballot and Results.      */
  const ELECTION_KEY = 'coopvote.election';
  let electionMemory = null;

  function requestedElection() {
    const id = new URLSearchParams(window.location.search).get('election') || '';
    return /^[1-9][0-9]{0,9}$/.test(id) ? id : '';
  }

  function loginDestination(user) {
    const id = requestedElection();
    return id && (user.role === 'member' || user.roleSlug === 'voter')
      ? ROOT + 'voter/election.html?election=' + id
      : ROOT + (user.home || user.redirect || (user.role === 'admin' ? 'admin/dashboard.html' : 'voter/dashboard.html'));
  }

  function selectedElection() {
    if (isElectionPickerPage() && requestedElection()) return requestedElection();
    if (electionMemory !== null) return electionMemory;
    try { return localStorage.getItem(ELECTION_KEY) || ''; } catch (e) { return ''; }
  }

  function setSelectedElection(id) {
    electionMemory = id ? String(id) : '';
    if (isElectionPickerPage() && requestedElection() && id) {
      const link = new URL(window.location.href);
      link.searchParams.set('election', String(id));
      window.history.replaceState(null, '', link.href);
    }
    try {
      if (id) localStorage.setItem(ELECTION_KEY, String(id));
      else localStorage.removeItem(ELECTION_KEY);
    } catch (e) { /* private mode — the default election still works */ }
  }

  /** Build a full API URL for a route such as "voter/dashboard". */
  function url(route) {
    let r = String(route).replace(/^\/+/, '');

    /* Scope member requests to the chosen election. The listing endpoint
       reports on all of them, so it is left alone. */
    const id = selectedElection();
    if (id && r.indexOf('voter/') === 0 && r.indexOf('voter/elections') !== 0
        && r.indexOf('election=') === -1) {
      r += '&election=' + encodeURIComponent(id);
    }
    return API + r;
  }

  /**
   * Call the API and return the parsed JSON body.
   * Throws an Error carrying .status, .code and .fields on failure.
   */
  const pendingReads = new Map();
  function api(route, options) {
    // Share only simultaneous default reads, never completed/stale responses
    // or caller-controlled requests (signals, headers, writes).
    if (options) {
      if (String(options.method || 'GET').toUpperCase() !== 'GET') pendingReads.clear();
      return request(route, options);
    }
    const key = url(route);
    let pending = pendingReads.get(key);
    if (!pending) {
      pending = request(route).finally(() => {
        if (pendingReads.get(key) === pending) pendingReads.delete(key);
      });
      pendingReads.set(key, pending);
    }
    // Consumers may sort or annotate their own payload.
    return pending.then(data => JSON.parse(JSON.stringify(data)));
  }

  async function request(route, options) {
    const opts = Object.assign({ credentials: 'same-origin', headers: {} }, options || {});

    /* JSON-encode plain objects, but leave FormData alone so the
       browser can set its own multipart boundary. */
    if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(opts.body);
    }
    opts.headers['X-Requested-With'] = 'XMLHttpRequest';

    let res;
    try {
      res = await fetch(url(route), opts);
    } catch (e) {
      /* Nothing answered at all — Apache is stopped, or this is a file:// page. */
      const err = new Error(
        window.location.protocol === 'file:'
          ? staticServerMessage()
          : 'Cannot reach the server. Check your connection and try again.'
      );
      err.status = 0;
      err.code = window.location.protocol === 'file:' ? 'PHP_NOT_RUNNING' : 'NETWORK_ERROR';
      if (window.location.protocol === 'file:') showServerBanner();
      throw err;
    }

    const text = await res.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch (e) { /* not JSON */ }

    /*
     * A reply that is not JSON has two very different causes, and telling
     * them apart matters. Blaming the web server for both meant a genuine
     * server-side failure was reported as "Apache is not running" — sending
     * people to the XAMPP Control Panel to restart something that was
     * already running, with no hint of the real problem.
     *
     *   PHP never ran        the page is on file:// or a static file server
     *                        (VS Code Live Server, python -m http.server),
     *                        which answers a POST with a bare 405 and a GET
     *                        with the raw PHP source.
     *   PHP ran and broke    anything else: a 5xx, an HTML error page, or an
     *                        empty body from a request that died part-way.
     */
    if (data === null) {
      const looksLikePhpSource = /^\s*<\?php|<\?=/.test(text);
      const notServedByPhp =
        window.location.protocol === 'file:' || looksLikePhpSource
        || (res.status === 405 && !/^\s*</.test(text) && text.length < 400);

      const err = new Error(
        notServedByPhp
          ? staticServerMessage()
          : 'The server did not answer properly (HTTP ' + res.status + ').'
            + ' Please try again or contact the administrator.'
      );
      err.status = res.status;
      err.code = notServedByPhp ? 'PHP_NOT_RUNNING' : 'BAD_RESPONSE';
      err.fields = {};
      /* The red "start Apache" bar belongs only to the first case. */
      if (notServedByPhp) showServerBanner();
      throw err;
    }

    if (!res.ok) {
      /* A setup problem comes back with the step that fixes it — show that
         too, or the person is left guessing on a fresh installation. */
      const err = new Error(
        data && data.error && data.fix
          ? data.error + ' ' + data.fix
          : (data && data.error) || 'Request failed (HTTP ' + res.status + ').'
      );
      err.status = res.status;
      err.code = data && data.code;
      err.fields = (data && data.fields) || {};
      err.detail = data && data.detail;
      /* Carry the whole body: some failures return useful extras, such as
         the masked address and cooldown on an unverified sign-in. */
      err.data = data || {};
      if (data) {
        Object.keys(data).forEach(k => {
          if (!(k in err)) err[k] = data[k];
        });
      }

      /* Session gone — bounce to the sign-in page. */
      if (res.status === 401 && !/auth\/(login|me)/.test(route)) {
        redirectToLogin();
      }
      throw err;
    }
    return data;
  }

  /**
   * The address this page should be opened at, served by Apache.
   *
   * The folder name is never assumed. This used to build "/CoopVote/..."
   * literally, so once the project was unzipped under any other name the
   * advice pointed at an address that did not exist — on exactly the
   * machine where somebody was already stuck.
   *
   * ROOT comes from this script's own src, so it carries whatever folder
   * the project actually sits in. When a static server is serving that
   * folder as its own root the name is genuinely not knowable from the
   * browser, and the message says so rather than inventing one.
   */
  function apacheUrl() {
    let folder = '';
    let page   = window.location.pathname.replace(/^\//, '');

    try {
      const root = new URL(ROOT || './', window.location.href);
      folder = root.pathname.replace(/^\/|\/$/g, '');
      if (window.location.pathname.startsWith(root.pathname)) {
        page = window.location.pathname.slice(root.pathname.length);
      }
    } catch (e) {
      /* A malformed src is no reason to fail; fall through to null. */
    }

    /*
     * No folder in the path means a static server is serving the project
     * folder as its own web root, and the folder's name on disk simply is
     * not visible to the browser. Return null rather than a guess: a
     * previous version put the words "<your project folder>" into the
     * address, the page parsed them as an HTML tag and dropped them, and
     * the result was a link to http://localhost//login.html that answered
     * Forbidden when anyone clicked it.
     */
    if (!folder) {
      return null;
    }
    return 'http://localhost/' + folder + '/' + (page || 'login.html');
  }

  /** The page's own filename, for telling somebody what to open. */
  function currentPageFile() {
    return window.location.pathname.split('/').filter(Boolean).pop() || 'login.html';
  }

  function staticServerMessage() {
    const url = apacheUrl();
    return 'PHP is not running at this address, so the app cannot reach its database. '
      + 'Open the site through XAMPP\'s Apache server instead: '
      + (url
        ? url
        : 'http://localhost/ followed by this project\'s folder name and /'
          + currentPageFile())
      + '  (VS Code Live Server and file:// cannot run PHP.)';
  }

  /** A dismissable bar explaining the problem, shown once per page. */
  function showServerBanner() {
    if (document.getElementById('cv-server-banner')) return;

    const bar = document.createElement('div');
    bar.id = 'cv-server-banner';
    bar.style.cssText =
      'position:fixed;inset:0 0 auto 0;z-index:99999;background:#3a1e1e;color:#ffd9d9;'
      + 'border-bottom:2px solid #e05c5c;padding:14px 18px;font:14px/1.55 system-ui,sans-serif;'
      + 'box-shadow:0 6px 24px rgba(0,0,0,.45)';
    const url  = apacheUrl();
    const gold = 'color:#ffd166;font-weight:700';

    /*
     * When the folder name is known the address is offered as a link. When
     * it is not, the instruction has to be written out instead — a link
     * built around a placeholder is worse than no link, because it looks
     * clickable and answers Forbidden.
     */
    const whereToGo = url
      ? 'open <a id="cv-apache-link" href="' + esc(url) + '" style="' + gold + '">'
        + esc(url) + '</a> instead.'
      : 'then open this same page under Apache instead:'
        + '<div style="margin:8px 0 2px;font-family:ui-monospace,Consolas,monospace">'
        + '<span style="' + gold + '">http://localhost/</span>'
        + '<span style="background:#5a2b2b;border:1px dashed #ffb3b3;border-radius:4px;'
        + 'padding:1px 7px;margin:0 2px;color:#fff">folder-name</span>'
        + '<span style="' + gold + '">/' + esc(currentPageFile()) + '</span>'
        + '</div>'
        + '<div style="font-size:12.5px;opacity:.85;margin-top:4px">'
        + 'where <b>folder-name</b> is this project\'s folder inside '
        + '<code style="color:#ffb3b3">C:\\xampp\\htdocs</code>. '
        + 'A file server cannot tell us what you named it.</div>';

    bar.innerHTML =
      '<strong style="color:#fff">This page is not being served by PHP.</strong><br>'
      + 'You are on <code style="color:#ffb3b3">' + esc(window.location.origin) + '</code>, '
      + 'which only serves files. Start <strong>Apache</strong> in the XAMPP Control Panel and '
      + whereToGo
      + '<button onclick="this.parentNode.remove()" '
      + 'style="float:right;background:none;border:1px solid #e05c5c;color:#ffd9d9;'
      + 'border-radius:6px;padding:3px 10px;cursor:pointer">Dismiss</button>';

    (document.body || document.documentElement).appendChild(bar);
  }

  function redirectToLogin() {
    // Explicit logout owns its redirect; a late polling 401 must not replace it.
    if (loggingOut) return;
    if (window.CoopVoteNavigation) window.CoopVoteNavigation.clear();
    const here = window.location.pathname.split('/').pop() || '';
    if (here.indexOf('login') === 0) return;
    /* replace(), not href: the signed-out page must not stay in history. */
    const id = isElectionPickerPage() ? requestedElection() : '';
    window.location.replace(ROOT + 'login.html?' + (id ? 'election=' + id : 'expired=1'));
  }

  /* ── Back-button protection ────────────────────────────────
     A page restored from the back/forward cache does not re-run its
     scripts, so guard() never fires and a dashboard belonging to a
     signed-out member would stay on screen. The no-store headers on
     /admin and /voter already stop most browsers caching these pages;
     this re-check covers whatever slips through.                    */

  /** Cover the page while the session is being re-checked. */
  function shroud() {
    if (document.getElementById('cv-shroud')) return;
    const el = document.createElement('div');
    el.id = 'cv-shroud';
    el.style.cssText =
      'position:fixed;inset:0;z-index:2147483647;background:var(--navy);'
      + 'display:flex;align-items:center;justify-content:center;'
      + 'color:var(--muted);font:14px system-ui,sans-serif';
    el.textContent = 'Checking your session…';
    (document.body || document.documentElement).appendChild(el);
  }
  function unshroud() {
    const el = document.getElementById('cv-shroud');
    if (el) el.remove();
  }

  /** Confirm the session is still valid; leave for the sign-in page if not. */
  async function revalidate() {
    if (guardedRole === null || loggingOut) return;
    shroud();
    try {
      const data = await api('auth/me');
      if (loggingOut) { unshroud(); return; }
      if (data.user.role !== guardedRole) {
        window.location.replace(ROOT + data.user.home);
        return;
      }
      unshroud();
    } catch (e) {
      if (e.code === 'PHP_NOT_RUNNING' || e.status === 0) {
        unshroud();
        return;
      }
      redirectToLogin();
    }
  }

  window.addEventListener('pageshow', e => {
    /* persisted === true means this came out of the bfcache. */
    if (e.persisted) revalidate();
  });

  /* Coming back to the tab is another chance to notice a dead session. */
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && guardedRole !== null) revalidate();
  });

  /**
   * Ensure somebody is signed in before a protected page renders.
   * `expected` is 'member' or 'admin'; a mismatch is sent home.
   * Resolves with the user object.
   */
  /* Remembered so the page can re-check itself after a Back navigation. */
  let guardedRole = null;

  async function guard(expected) {
    guardedRole = expected || null;
    try {
      const data = await api('auth/me');
      const user = data.user;

      if (expected && user.role !== expected) {
        window.location.replace(ROOT + user.home);
        return new Promise(() => {});     // stop the page rendering
      }
      window.CoopVote.user = user;
      if (window.CoopVoteNavigation) window.CoopVoteNavigation.remember(user);
      window.CoopVote.coop = data.coop || {};
      if (window.CoopVoteSidebar) window.CoopVoteSidebar.mount(user);
      applyIdentity(user);
      if (window.CoopVoteAppearance) window.CoopVoteAppearance.sync(user, data.preferences);
      if (window.CoopVoteVoterNavigation) window.CoopVoteVoterNavigation.init(user);
      applyCoopName(window.CoopVote.coop);
      if (user.role === 'member') await initElectionPicker();
      // Let the guarded page start its data request before background notices.
      setTimeout(() => { if (!loggingOut) initNotifications(user); }, 0);
      return user;
    } catch (e) {
      /* Bouncing to the sign-in page would just loop when the real
         problem is that PHP is not running — leave the banner up. */
      if (e.code === 'PHP_NOT_RUNNING' || e.status === 0) {
        showServerBanner();
        return new Promise(() => {});
      }
      redirectToLogin();
      return new Promise(() => {});
    }
  }

  /** Fill the sidebar name/ID/avatar that every dashboard shares. */
  function applyIdentity(user) {
    const set = (id, val) => {
      const el = document.getElementById(id);
      if (el && val) el.textContent = val;
    };
    set('sidebar-name', user.name);
    set('sidebar-id', user.memberId && user.memberId !== '—' ? user.memberId : user.roleName);
    set('sidebar-avatar', initials(user.name));
    set('topbar-user', user.name);
  }

  /** Election details, candidate browsing and voting share one selection. */
  function isElectionPickerPage() {
    return /(^|\/)(election|ballot|candidates)\.html$/i.test(window.location.pathname);
  }

  /**
   * Active Election, View Candidates and Cast My Vote share this picker.
   * Resolve the remembered choice before loading each page's election data.
   *
   * The list is drawn by the page rather than by a native <select>, because
   * a browser paints its own dropdown in system colours — a white panel that
   * ignores the theme and swallowed the election titles.
   */
  async function initElectionPicker() {
    if (!isElectionPickerPage()) return;
    let data;
    try { data = await api('voter/elections'); } catch (e) { return; }

    const list = ((data && data.elections) || []).filter(e => e.live || e.status === 'Active' || e.status === 'Upcoming');
    window.CoopVote.elections = list;

    /* Drop a stored id that no longer exists, so the member is not stuck
       pointing at a deleted election. */
    const stored = selectedElection();
    const requested = requestedElection();
    window.CoopVote.electionLinkUnavailable = !!requested && !list.some(e => String(e.id) === requested);
    const chosen = window.CoopVote.electionLinkUnavailable ? null : list.find(e => String(e.id) === stored)
      || list.find(e => String(e.id) === String(data.selectedId))
      || list.find(e => e.live) || list[0] || null;
    // Resolve a stale selection before the page requests its election data.
    setSelectedElection(chosen ? String(chosen.id) : '');

    const host = document.querySelector('.header-actions');
    if (!host || document.getElementById('cv-election-picker')) return;

    const optionLabel = e => {
      const note = e.hasVoted ? 'Voted' : (e.live ? '' : e.status);
      return '<span class="cv-opt-title">' + escapeHtml(e.title) + '</span>'
        + (note ? '<span class="cv-opt-note">' + escapeHtml(note) + '</span>' : '');
    };

    injectPickerStyles();

    const wrap = document.createElement('div');
    wrap.id = 'cv-election-picker';
    wrap.innerHTML =
      '<span class="cv-picker-label">Election</span>'
      + '<div class="cv-picker-shell">'
      + '<button type="button" class="cv-picker-btn" id="cv-picker-btn"'
      + ' aria-haspopup="listbox" aria-expanded="false" aria-label="Choose election"' + (!list.length ? ' disabled' : '') + '>'
      + '<span class="cv-dot' + (chosen?.live ? ' live' : '') + '"></span>'
      + '<span class="cv-picker-current" id="cv-picker-current">' + escapeHtml(chosen?.title || (list.length ? 'Choose another election' : 'No available elections')) + '</span>'
      + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="cv-chev">'
      + '<polyline points="6 9 12 15 18 9"/></svg>'
      + '</button>'
      + '<ul class="cv-picker-menu" id="cv-picker-menu" role="listbox">'
      + list.map(e =>
        '<li role="option" tabindex="-1" data-id="' + e.id + '"'
        + (String(e.id) === String(chosen?.id) ? ' class="sel" aria-selected="true"' : ' aria-selected="false"')
        + '><span class="cv-dot' + (e.live ? ' live' : '') + '"></span>'
        + '<span class="cv-opt-text">' + optionLabel(e) + '</span></li>').join('')
      + '</ul></div>';

    host.insertBefore(wrap, host.firstChild);

    const btn = document.getElementById('cv-picker-btn');
    const menu = document.getElementById('cv-picker-menu');

    const close = () => { menu.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); };
    const open = () => { menu.classList.add('open'); btn.setAttribute('aria-expanded', 'true'); };

    btn.addEventListener('click', e => {
      e.stopPropagation();
      menu.classList.contains('open') ? close() : open();
    });

    menu.addEventListener('click', e => {
      const li = e.target.closest('li[data-id]');
      if (!li) return;
      close();
      if (String(li.dataset.id) === String(chosen?.id)) return;   // no change
      setSelectedElection(li.dataset.id);
      /* Reload so every panel reflects the new election at once. */
      window.location.reload();
    });

    document.addEventListener('click', close);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
  }

  /**
   * Style the picker to match the dark theme.
   *
   * The open dropdown is drawn by the browser, not the page: an <option>
   * defaults to a transparent background on a light system menu, so the
   * near-white option text was white-on-white and unreadable. Naming the
   * colours explicitly — and declaring color-scheme so the native menu
   * itself renders dark — makes every title legible.
   */
  function injectPickerStyles() {
    if (document.getElementById('cv-picker-style')) return;

    /* Every colour here comes from the app's own palette, so the list can
       never fall back to the browser's white system menu. */
    const css = `
      #cv-election-picker {
        min-width: 0;
        width: 340px;
        flex: 0 1 340px;
        max-width: 100%;
        display: flex;
        align-items: center;
        gap: 8px;
        margin-right: 4px;
      }
      .cv-picker-label {
        font-size: .68rem;
        font-weight: 700;
        letter-spacing: .8px;
        text-transform: uppercase;
        color: var(--muted);
        white-space: nowrap;
      }
      .cv-picker-shell { position: relative; flex: 1; min-width: 0; max-width: 100%; }

      .cv-picker-btn {
        min-width: 0;
        width: 100%;
        display: flex;
        align-items: center;
        gap: 8px;
        max-width: 340px;
        padding: 9px 12px;
        border-radius: 10px;
        border: 1px solid rgba(255,255,255,.12);
        background: rgba(255,255,255,.06);
        color: var(--white);
        font-family: inherit;
        font-size: .82rem;
        font-weight: 500;
        cursor: pointer;
        transition: background .2s, border-color .2s;
      }
      .cv-picker-btn:hover { background: rgba(255,255,255,.11); }
      .cv-picker-btn[aria-expanded="true"] {
        border-color: var(--gold);
        background: rgba(201,168,76,.1);
      }
      .cv-picker-current {
        min-width: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .cv-chev { width: 14px; height: 14px; flex-shrink: 0; opacity: .7; }

      .cv-dot {
        width: 7px; height: 7px; border-radius: 50%;
        background: var(--muted); flex-shrink: 0;
      }
      .cv-dot.live { background: #4caf82; box-shadow: 0 0 0 3px rgba(76,175,130,.18); }

      .cv-picker-menu {
        position: absolute;
        top: calc(100% + 6px);
        right: 0;
        min-width: 0;
        width: 100%;
        max-width: 100%;
        max-height: min(340px, 60vh);
        overflow-y: auto;
        margin: 0;
        padding: 5px;
        list-style: none;
        background: var(--navy2);
        border: 1px solid rgba(255,255,255,.12);
        border-radius: 12px;
        box-shadow: 0 18px 44px rgba(0,0,0,.55);
        display: none;
        z-index: 4000;
      }
      .cv-picker-menu.open { display: block; }

      .cv-picker-menu li {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 10px 11px;
        border-radius: 8px;
        cursor: pointer;
        font-size: .82rem;
        color: var(--muted2);
        white-space: normal;
        transition: background .15s, color .15s;
      }
      .cv-picker-menu li:hover { background: rgba(255,255,255,.07); color: #fff; }
      .cv-picker-menu li.sel { background: rgba(201,168,76,.13); color: var(--gold); font-weight: 600; }
      .cv-opt-text { display: flex; flex-direction: column; align-items: flex-start; gap: 3px; flex: 1; min-width: 0; }
      .cv-opt-title { display: block; max-width: 100%; white-space: normal; overflow-wrap: anywhere; line-height: 1.4; }
      .cv-opt-note { display: block; color: var(--muted); font-size: .72rem; font-weight: 400; line-height: 1.4; white-space: normal; }
      .cv-picker-menu li.sel .cv-opt-note { color: rgba(201,168,76,.7); }

      @media (max-width: 900px) {
        #cv-election-picker { width: 190px; flex-basis: 190px; }
        .cv-picker-label { display: none; }
        .cv-picker-btn { max-width: 190px; }
      }`;

    const el = document.createElement('style');
    el.id = 'cv-picker-style';
    el.textContent = css;
    document.head.appendChild(el);
  }

  /** Show every page number so each page can be selected directly. */
  function paginationPages(pages) {
    return Array.from({ length: pages }, (_, i) => i + 1);
  }

  /** Shared controls for the voter, candidate and recent-vote tables. */
  function renderPagination(container, page, pages, onChange) {
    const target = typeof container === 'string' ? document.getElementById(container) : container;
    if (!target) return;
    target.setAttribute('role', 'navigation');
    target.setAttribute('aria-label', 'Table pages');
    const button = (number, label, text, disabled = false, current = false) =>
      `<button type="button" class="pag-btn${current ? ' active' : ''}" data-p="${number}" aria-label="${label}"${current ? ' aria-current="page"' : ''}${disabled ? ' disabled' : ''}>${text}</button>`;
    target.innerHTML = button(page - 1, 'Previous page', '‹', page <= 1)
      + paginationPages(pages).map(number =>
        button(number, 'Page ' + number, number, false, number === page)).join('')
      + button(page + 1, 'Next page', '›', page >= pages);
    target.querySelectorAll('button[data-p]').forEach(el => {
      el.addEventListener('click', () => {
        const next = Number(el.dataset.p);
        if (!el.disabled && next >= 1 && next <= pages && next !== page) onChange(next);
      });
    });
  }

  /**
   * Client-side pagination for a table that already has all its rows.
   *
   * @param opts.items     the full array
   * @param opts.pageSize  rows per page
   * @param opts.render    (pageItems, startIndex) => void — draws the rows
   * @param opts.info      element id for "Showing x–y of z"
   * @param opts.buttons   element id for the page buttons
   * @param opts.noun      what is being counted, e.g. "entry"
   */
  const rememberedPages = new Map();
  function paginate(opts) {
    const items = opts.items || [];
    const size = opts.pageSize || 10;
    const pages = Math.max(1, Math.ceil(items.length / size));
    let page = window.CoopVote.keepPagination ? Math.min(pages, rememberedPages.get(opts.buttons) || 1) : 1;

    const info = () => document.getElementById(opts.info);
    const btns = () => document.getElementById(opts.buttons);

    function draw() {
      rememberedPages.set(opts.buttons, page);
      const start = (page - 1) * size;
      const slice = items.slice(start, start + size);
      opts.render(slice, start);

      const i = info();
      if (i) {
        const noun = opts.noun || 'row';
        i.textContent = items.length === 0
          ? 'No ' + noun + 's'
          : 'Showing ' + (start + 1) + '–' + Math.min(start + size, items.length)
            + ' of ' + items.length + ' ' + noun + (items.length === 1 ? '' : 's');
      }

      const b = btns();
      if (!b) return;
      if (pages < 2) { b.innerHTML = ''; return; }

      renderPagination(b, page, pages, next => {
        page = next;
        draw();
      });
    }

    draw();
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));
  }

  /**
   * Escape a value on its way into HTML.
   *
   * Every page builds its markup with template literals and innerHTML, so
   * anything a person typed — an election title, a candidate's name, an
   * announcement — has to pass through here first. An election titled
   *
   *     <img src=x onerror="...">
   *
   * otherwise ran as script in the browser of every administrator who opened
   * Election Management, and of every member who opened the election page.
   *
   * Missing values become an empty string rather than the words "null" or
   * "undefined", which is what String() would have produced.
   */
  function esc(s) {
    return (s === null || s === undefined) ? '' : escapeHtml(s);
  }

  /**
   * Show the cooperative's own name under the CoopVote mark.
   * The `settings` table carried it all along; nothing read it, so every
   * screen showed only the product name.
   */
  function applyCoopName(coop) {
    const name = coop && coop.name;
    if (!name || name === 'CoopVote') return;

    const logo = document.querySelector('.sidebar-logo');
    if (!logo || document.getElementById('cv-coop-name')) return;

    const el = document.createElement('div');
    el.id = 'cv-coop-name';
    el.textContent = name;
    el.title = name;
    el.style.cssText =
      'width:100%;margin-top:8px;font-size:.68rem;line-height:1.35;color:var(--muted);'
      + 'letter-spacing:.02em';
    logo.style.flexWrap = 'wrap';
    logo.appendChild(el);
  }

  /* ── Notifications ────────────────────────────────────────
     The bell was decoration on all 14 pages — no handler at all. It now
     reports the things that actually need attention, drawn from data the
     page already has a right to see.                                   */
  /* Which notices this person has already read. Each notice carries an id
     built from the fact it reports, e.g. "pending:3". If the underlying
     number changes the id changes too, so genuinely new information comes
     back rather than staying hidden for ever.

     The key is per account, so an administrator and a member sharing one
     computer never inherit each other's read state. */
  function seenKey() {
    const u = window.CoopVote.user;
    return 'coopvote.seenNotices.' + (u ? u.id : 'anon');
  }

  function seenNotices() {
    try { return JSON.parse(localStorage.getItem(seenKey()) || '[]'); } catch (e) { return []; }
  }

  function markSeen(ids) {
    try {
      /* Keep the list short — only recent ids can still match anything. */
      const next = Array.from(new Set(seenNotices().concat(ids))).slice(-40);
      localStorage.setItem(seenKey(), JSON.stringify(next));
    } catch (e) { /* private mode: notices simply reappear */ }
  }

  /** Everything currently worth telling this person, before filtering. */
  async function buildNotices(user) {
    return (await api('notifications')).notices || [];
  }

  /** Non-overlapping polling; preserve the page while offline or editing. */
  function watch(work, interval = 10000, status = null) {
    let stopped = false, busy = false, timer = null, failures = 0;
    const schedule = () => {
      clearTimeout(timer);
      if (!stopped && !loggingOut) timer = setTimeout(tick, Math.min(60000, interval * (2 ** failures)));
    };
    async function tick() {
      if (stopped || busy || loggingOut) return;
      const editing = document.activeElement && document.activeElement.matches('input,textarea,select,[contenteditable="true"]');
      if (document.hidden || navigator.onLine === false || editing || document.querySelector('.modal-backdrop.open,.modal-backdrop.show')) {
        if (status) status.textContent = navigator.onLine === false ? 'Offline — showing last update' : 'Updates paused';
        schedule(); return;
      }
      busy = true;
      try {
        await work(); failures = 0;
        if (status) status.textContent = 'Updated ' + new Date().toLocaleTimeString() + ' · every ' + interval / 1000 + 's';
      } catch (e) {
        failures = Math.min(failures + 1, 3);
        if (status) status.textContent = 'Connection interrupted — retrying';
      } finally { busy = false; schedule(); }
    }
    const resume = () => { if (!document.hidden) { clearTimeout(timer); tick(); } };
    document.addEventListener('visibilitychange', resume);
    window.addEventListener('online', resume);
    window.addEventListener('pagehide', () => clearTimeout(timer), { once: true });
    window.addEventListener('pageshow', resume);
    schedule();
    return () => {
      stopped = true; clearTimeout(timer);
      document.removeEventListener('visibilitychange', resume);
      window.removeEventListener('online', resume);
      window.removeEventListener('pageshow', resume);
    };
  }

  async function initNotifications(user) {
    const bell = document.querySelector('.hbtn[title="Notifications"]');
    if (!bell || bell.dataset.noticesReady) return;
    bell.dataset.noticesReady = '1';
    let unread = [], announced = new Set();
    // Toast delivery is separate from reading a notice. Persist across pages.
    const announcementKey = seenKey() + ':announced';
    const restoreAnnouncements = () => {
      try {
        const ids = JSON.parse(localStorage.getItem(announcementKey) || '[]');
        if (Array.isArray(ids)) ids.forEach(id => announced.add(id));
      } catch (e) { /* Retain the in-memory set if browser storage is unavailable. */ }
    };
    restoreAnnouncements();
    bell.style.position = 'relative';
    bell.style.cursor = 'pointer';
    const paint = () => {
      const old = bell.querySelector('.cv-badge');
      if (old) old.remove();
      if (unread.length) {
        const dot = document.createElement('span');
        dot.className = 'cv-badge';
        dot.textContent = unread.length;
        dot.style.cssText = 'position:absolute;top:-4px;right:-4px;padding:1px 5px;border-radius:99px;background:#e05c5c;color:#fff;font-size:.7rem';
        bell.appendChild(dot);
      }
      bell.title = unread.length ? unread.length + ' unread notifications' : 'Notifications';
      bell.setAttribute('aria-label', bell.title);
    };
    const refresh = async () => {
      unread = await buildNotices(user);
      restoreAnnouncements();
      const fresh = unread.filter(n => !announced.has(n.id));
      unread.forEach(n => announced.add(n.id));
      try { localStorage.setItem(announcementKey, JSON.stringify(Array.from(announced).slice(-1000))); } catch (e) { /* Storage may be disabled. */ }
      if (fresh.length) toast(fresh.length + ' unread election notification' + (fresh.length === 1 ? '' : 's') + '. Open the bell to read.', 'info');
      paint();
    };
    try { await refresh(); } catch (e) { /* Retry without breaking login. */ }
    watch(refresh, 30000);
    bell.addEventListener('click', e => {
      e.stopPropagation();
      const open = document.getElementById('cv-notif');
      if (open) { open.remove(); return; }
      openNoticePanel(bell, unread, async n => {
        await api('notifications', { method: 'POST', body: { id: n.id } });
        if (n.electionId && user.role !== 'admin') setSelectedElection(n.electionId);
        unread = unread.filter(x => x.id !== n.id);
        paint();
      });
    });
  }

  /**
   * @param onRead  called with the notice the person just clicked, so the
   *                caller can drop it and repaint the badge.
   */
  function openNoticePanel(bell, unread, onRead) {
    const box = document.createElement('div');
    box.id = 'cv-notif';
    box.style.cssText =
      'position:absolute;top:calc(100% + 8px);right:0;width:300px;z-index:4000;'
      + 'background:var(--navy2);border:1px solid rgba(255,255,255,.12);border-radius:12px;'
      + 'box-shadow:0 18px 44px rgba(0,0,0,.55);padding:6px;text-align:left';

    if (!unread.length) {
      box.innerHTML =
        '<div style="padding:16px 12px;color:var(--muted);font-size:.8rem;text-align:center">'
        + 'Nothing needs your attention.</div>';
    }

    unread.forEach(n => {
      const a = document.createElement('a');
      a.href = n.href;
      a.style.cssText =
        'display:flex;gap:9px;align-items:flex-start;padding:11px 12px;'
        + 'border-radius:8px;color:var(--muted2);font-size:.8rem;line-height:1.5;'
        + 'text-decoration:none';
      a.innerHTML =
        '<span style="width:6px;height:6px;border-radius:50%;margin-top:6px;'
        + 'flex-shrink:0;background:var(--gold)"></span>'
        + '<span>' + escapeHtml(n.text) + '</span>';

      a.addEventListener('mouseenter', () => {
        a.style.background = 'rgba(255,255,255,.07)'; a.style.color = '#fff';
      });
      a.addEventListener('mouseleave', () => {
        a.style.background = 'transparent'; a.style.color = 'var(--muted2)';
      });

      /* Runs before the link navigates, so the notice is already marked
         read by the time the next page loads. */
      a.addEventListener('click', async event => {
        event.preventDefault();
        if (a.dataset.pending) return;
        a.dataset.pending = '1';
        try { await onRead(n); }
        catch (error) { delete a.dataset.pending; toast('Could not mark the notification as read. Try again.', 'error'); return; }
        a.remove();
        window.location.href = a.href;
        if (!box.querySelector('a')) {
          box.innerHTML =
            '<div style="padding:16px 12px;color:var(--muted);font-size:.8rem;text-align:center">'
            + 'Nothing needs your attention.</div>';
        }
      });

      box.appendChild(a);
    });

    bell.appendChild(box);
    setTimeout(() => document.addEventListener('click', function close() {
      box.remove();
      document.removeEventListener('click', close);
    }, { once: true }), 0);
  }

  function initials(name) {
    return String(name || '')
      .split(' ')
      .filter(Boolean)
      .map(w => w[0])
      .join('')
      .substring(0, 2)
      .toUpperCase() || '?';
  }

  /**
   * End the session, then return to the sign-in page.
   * Redirect as soon as the request finishes; the sign-in page shows
   * the confirmation without making the user wait on this page.
   */
  let loggingOut = false;

  async function logout() {
    if (loggingOut) return;          // ignore a second click
    loggingOut = true;
    unshroud();

    const name = (window.CoopVote.user && window.CoopVote.user.name) || '';
    toast(name ? `Signing out ${name}…` : 'Signing you out…', 'info');

    try {
      /* No success toast here — the sign-in page says the same thing a
         moment later, and showing it twice just delays the redirect. */
      await api('auth/logout', { method: 'POST' });
    } catch (e) {
      /* The session is abandoned locally either way — worth saying. */
      toast('Signed out locally — the server could not be reached.', 'warn');
    }

    /*
     * replace() instead of href so the dashboard is not the entry Back
     * returns to. guardedRole is cleared first, otherwise the pageshow
     * re-check would fire against the sign-in page itself.
     */
    guardedRole = null;
    if (window.CoopVoteNavigation) window.CoopVoteNavigation.clear();
    window.location.replace(ROOT + 'login.html?bye=1');
  }

  const pendingDownloads = new Set();
  /** Download attachments without navigating away; printable previews opt into a tab. */
  async function download(route, options = {}) {
    if (options.preview) {
      const preview = window.open(url(route), '_blank');
      if (!preview) { toast('Allow pop-ups to open the printable report.', 'error'); return false; }
      preview.opener = null;
      return true;
    }
    if (pendingDownloads.has(route)) return false;
    pendingDownloads.add(route);
    const button = options.button, label = button?.textContent;
    if (button) { button.disabled = true; button.textContent = 'Preparing…'; button.setAttribute('aria-busy', 'true'); }
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 30000);
    try {
      const response = await fetch(url(route), { credentials: 'same-origin', signal: controller.signal,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/csv' } });
      const type = response.headers.get('Content-Type') || '';
      if (!response.ok || !/^text\/csv\b/i.test(type)) {
        let message = 'Could not download the CSV. Please try again.';
        if (/application\/json/i.test(type)) {
          const data = await response.json();
          message = data.error || message;
        }
        throw new Error(message);
      }
      const blob = await response.blob();
      if (!blob.size) throw new Error('The export was empty. Please try again.');
      const disposition = response.headers.get('Content-Disposition') || '';
      const match = disposition.match(/filename="([^"]+)"/i);
      const filename = (match ? match[1] : 'coopvote-export.csv').replace(/[\\/\x00-\x1f]/g, '_');
      const objectUrl = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = objectUrl;
      link.download = filename;
      link.hidden = true;
      document.body.appendChild(link);
      try { link.click(); } finally {
        link.remove();
        setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);
      }
      return true;
    } catch (error) {
      toast(error.name === 'AbortError' ? 'The export took too long. Please try again.' : error.message, 'error');
      return false;
    } finally {
      clearTimeout(timer);
      pendingDownloads.delete(route);
      if (button) { button.disabled = false; button.textContent = label; button.removeAttribute('aria-busy'); }
    }
  }

  // Share only motion and lifetime; each page keeps its existing toast design.
  const toastMotion = new WeakMap();
  function presentToast(element, duration = 4200, dismiss = () => element.remove()) {
    const previous = toastMotion.get(element);
    const start = previous && !element.hidden ? window.getComputedStyle(element) : null;
    const from = start ? { opacity: start.opacity, transform: start.transform }
      : { opacity: 0, transform: 'translateY(-6px)' };
    if (previous) previous();
    let timer, animation;
    const cancel = () => {
      clearTimeout(timer);
      if (animation) animation.cancel();
      if (toastMotion.get(element) === cancel) toastMotion.delete(element);
    };
    toastMotion.set(element, cancel);
    element.hidden = false;
    element.classList.add('show');
    element.style.animation = 'none';
    element.style.transition = 'none';
    element.style.opacity = '1';
    element.style.transform = 'none';
    if (!element.hasAttribute('role')) element.setAttribute('role', 'status');
    element.setAttribute('aria-atomic', 'true');
    const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (element.animate && !reduced()) {
      animation = element.animate([from, { opacity: 1, transform: 'none' }],
        { duration: 220, easing: 'cubic-bezier(.22,1,.36,1)' });
    }
    if (duration > 0) timer = setTimeout(() => {
      const finish = () => {
        if (toastMotion.get(element) !== cancel) return;
        cancel(); dismiss();
      };
      if (!element.animate || reduced()) { finish(); return; }
      animation = element.animate([{ opacity: 1, transform: 'none' },
        { opacity: 0, transform: 'translateY(-4px)' }],
        { duration: 160, easing: 'ease-in', fill: 'forwards' });
      animation.finished.then(finish, () => {});
    }, duration);
    return cancel;
  }

  /** Minimal toast used by pages that do not define their own. */
  function toast(msg, type) {
    if (typeof window.showToast === 'function') {
      window.showToast(msg, type || 'info');
      return;
    }
    let wrap = document.getElementById('cv-toast-wrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = 'cv-toast-wrap';
      /* Clear the fixed 64px header on app pages; sit near the top elsewhere. */
      const top = document.querySelector('.header') ? 78 : 20;
      wrap.style.cssText =
        'position:fixed;top:' + top + 'px;right:20px;z-index:9999;'
        + 'display:flex;flex-direction:column;gap:8px';
      document.body.appendChild(wrap);
    }
    const colors = {
      error: '#e05c5c', success: '#4caf82', warn: '#e0a030', info: '#4a9fd4'
    };
    const t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText =
      'background:var(--navy2);color:var(--white);border-left:3px solid ' + (colors[type] || colors.info) +
      ';padding:12px 16px;border-radius:8px;font-size:.85rem;box-shadow:0 8px 24px rgba(0,0,0,.4);' +
      'max-width:340px;font-family:inherit';
    wrap.appendChild(t);
    presentToast(t);
  }

  window.CoopVote = {
    api, guard, logout, download, toast, presentToast, initials, url, ROOT, watch, user: null,
    /* Election selection, for pages that want to read or change it. */
    selectedElection, setSelectedElection, requestedElection, loginDestination, elections: [],
    paginate, paginationPages, renderPagination, escapeHtml, esc, coop: {},
  };
})(window, document);
