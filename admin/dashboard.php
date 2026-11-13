<?php require __DIR__ . '/../includes/admin-page.php'; ?>
<!DOCTYPE html>
<html lang="en" data-cv-theme="<?= device_theme() ?>">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content" />
  <title>CoopVote — Admin Overview</title>
  <link
    href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap"
    rel="stylesheet" />
  <style>
    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0
    }

    :root {
      --navy: #0b1931;
      --navy2: #0f2040;
      --navy3: #162a52;
      --navy4: #1e3566;
      --gold: #c9a84c;
      --gold2: #e8c96a;
      --white: #ffffff;
      --muted: #7a8ba0;
      --muted2: #a8b8cc;
      --border: rgba(255, 255, 255, .08);
      --danger: #e05c5c;
      --success: #4caf82;
      --warn: #e0a030;
      --info: #4a9fd4;
      --sidebar-w: 260px;
      --header-h: 64px;
      --radius: 14px;
    }

    html,
    body {
      height: 100%;
      font-family: 'DM Sans', sans-serif;
      background: var(--navy);
      color: var(--white);
      overflow: hidden
    }

    ::-webkit-scrollbar {
      width: 5px;
      height: 5px
    }

    ::-webkit-scrollbar-track {
      background: transparent
    }

    ::-webkit-scrollbar-thumb {
      background: rgba(255, 255, 255, .12);
      border-radius: 99px
    }

    .app {
      display: flex;
      height: 100vh;
      overflow: hidden
    }

    /* ── SIDEBAR ── */
    .sidebar {
      width: var(--sidebar-w);
      flex-shrink: 0;
      background: var(--navy2);
      border-right: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      overflow-y: auto;
      overflow-x: hidden;
      transition: transform .3s cubic-bezier(.22, 1, .36, 1);
      z-index: 200
    }

    .sidebar-logo {
      padding: 28px 22px 22px;
      display: flex;
      align-items: center;
      gap: 12px;
      border-bottom: 1px solid var(--border);
      flex-shrink: 0
    }

    .logo-mark {
      width: 40px;
      height: 40px;
      border-radius: 11px;
      flex-shrink: 0;
      background: linear-gradient(135deg, var(--gold), var(--gold2));
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 16px rgba(201, 168, 76, .3);
      position: relative;
      overflow: hidden
    }

    .logo-mark::after {
      content: '';
      position: absolute;
      top: 0;
      left: -70%;
      width: 40%;
      height: 100%;
      background: rgba(255, 255, 255, .3);
      transform: skewX(-20deg);
      animation: shimmer 4s ease-in-out infinite
    }

    @keyframes shimmer {

      0%,
      100% {
        left: -70%
      }

      50% {
        left: 140%
      }
    }

    .logo-mark svg {
      width: 20px;
      height: 20px;
      fill: var(--navy)
    }

    .logo-text {
      font-family: 'Playfair Display', serif;
      font-size: 1.25rem;
      font-weight: 800;
      letter-spacing: .3px
    }

    .logo-text span {
      color: var(--gold)
    }

    .sidebar-section {
      padding: 20px 14px 8px;
      font-size: .68rem;
      font-weight: 700;
      letter-spacing: 1.8px;
      text-transform: uppercase;
      color: var(--muted);
      flex-shrink: 0
    }

    .nav-item {
      display: flex;
      align-items: center;
      gap: 11px;
      padding: 11px 14px;
      margin: 2px 8px;
      border-radius: 10px;
      cursor: pointer;
      font-size: .875rem;
      font-weight: 500;
      color: var(--muted2);
      transition: all .2s;
      position: relative;
      white-space: nowrap;
      overflow: hidden;
      text-decoration: none
    }

    .nav-item:hover {
      background: rgba(255, 255, 255, .06);
      color: var(--white)
    }

    .nav-item.active {
      background: rgba(201, 168, 76, .14);
      color: var(--gold);
      font-weight: 600
    }

    .nav-item.active::before {
      content: '';
      position: absolute;
      left: 0;
      top: 20%;
      bottom: 20%;
      width: 3px;
      border-radius: 0 3px 3px 0;
      background: var(--gold)
    }

    .nav-item svg {
      width: 17px;
      height: 17px;
      flex-shrink: 0;
      opacity: .7
    }

    .nav-item.active svg {
      opacity: 1
    }

    .nav-badge {
      margin-left: auto;
      background: var(--danger);
      color: #fff;
      font-size: .68rem;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 99px;
      animation: pulse-badge 2s ease-in-out infinite
    }

    @keyframes pulse-badge {

      0%,
      100% {
        box-shadow: 0 0 0 0 rgba(224, 92, 92, .4)
      }

      50% {
        box-shadow: 0 0 0 5px rgba(224, 92, 92, 0)
      }
    }

    .sidebar-footer {
      margin-top: auto;
      padding: 16px 14px;
      border-top: 1px solid var(--border);
      flex-shrink: 0
    }

    .admin-card {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 12px;
      border-radius: 10px;
      background: rgba(255, 255, 255, .05);
      cursor: pointer;
      transition: background .2s
    }

    .admin-card:hover {
      background: rgba(255, 255, 255, .09)
    }

    .admin-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--navy4), var(--navy3));
      border: 2px solid var(--gold);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: .75rem;
      font-weight: 700;
      color: var(--gold);
      flex-shrink: 0
    }

    .admin-info {
      flex: 1;
      overflow: hidden
    }

    .admin-name {
      font-size: .82rem;
      font-weight: 600;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis
    }

    .admin-role {
      font-size: .7rem;
      color: var(--muted);
      margin-top: 1px
    }

    /* ── MAIN ── */
    .main {
      flex: 1;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      min-width: 0
    }

    .header {
      height: var(--header-h);
      flex-shrink: 0;
      background: rgba(11, 25, 49, .85);
      backdrop-filter: blur(20px);
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      padding: 0 28px;
      gap: 16px;
      z-index: 100
    }

    .hamburger {
      display: none;
      background: none;
      border: none;
      cursor: pointer;
      color: var(--muted2);
      padding: 6px;
      border-radius: 8px;
      transition: color .2s, background .2s
    }

    .hamburger:hover {
      color: var(--white);
      background: rgba(255, 255, 255, .07)
    }

    .header-title {
      flex: 1
    }

    .header-title h1 {
      font-family: 'Playfair Display', serif;
      font-size: 1.15rem;
      font-weight: 700
    }

    .header-title p {
      font-size: .75rem;
      color: var(--muted);
      margin-top: 1px
    }

    .header-actions {
      display: flex;
      align-items: center;
      gap: 10px
    }

    .hbtn {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 38px;
      height: 38px;
      border-radius: 10px;
      border: none;
      background: rgba(255, 255, 255, .06);
      color: var(--muted2);
      cursor: pointer;
      transition: all .2s;
      position: relative
    }

    .hbtn:hover {
      background: rgba(255, 255, 255, .12);
      color: var(--white)
    }

    .hbtn svg {
      width: 18px;
      height: 18px
    }

    .hbtn .dot {
      position: absolute;
      top: 7px;
      right: 7px;
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: var(--danger);
      border: 1.5px solid var(--navy);
      animation: pulse-badge 2s ease-in-out infinite
    }

    .election-status {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 7px 14px;
      border-radius: 10px;
      background: rgba(255, 255, 255, .06);
      border: 1px solid var(--border);
      font-size: .78rem;
      font-weight: 600;
      color: var(--muted2);
      cursor: default;
      white-space: nowrap
    }

    .status-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: var(--muted);
      flex-shrink: 0
    }

    .election-status.live {
      background: rgba(76, 175, 130, .12);
      border-color: rgba(76, 175, 130, .25);
      color: var(--success)
    }

    .election-status.live .status-dot {
      background: var(--success);
      animation: pulse-badge 2s ease-in-out infinite
    }

    /* ── CONTENT ── */
    .content {
      flex: 1;
      overflow-y: auto;
      padding: 28px;
      display: flex;
      flex-direction: column;
      gap: 24px
    }

    @keyframes fadeUp {
      from {
        opacity: 0;
        transform: translateY(24px)
      }

      to {
        opacity: 1;
        transform: translateY(0)
      }
    }

    /* ── OVERLAY ── */
    .overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .6);
      z-index: 150;
      backdrop-filter: blur(4px)
    }

    /* ── RESPONSIVE ── */
    @media(max-width:1200px) {
      .stats-grid {
        grid-template-columns: repeat(2, 1fr)
      }
      .stats-grid > .stat-card:last-child { grid-column: 1 / -1; }
    }

    @media(max-width:960px) {
      .mid-row {
        grid-template-columns: 1fr
      }

      .bottom-row {
        grid-template-columns: 1fr
      }
    }

    @media(max-width:768px) {
      .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        transform: translateX(-100%);
        box-shadow: 8px 0 40px rgba(0, 0, 0, .5)
      }

      .sidebar.open {
        transform: translateX(0)
      }

      .overlay {
        display: block;
        pointer-events: none;
        opacity: 0;
        transition: opacity .3s
      }

      .overlay.show {
        pointer-events: all;
        opacity: 1
      }

      .hamburger {
        display: flex
      }

      .content {
        padding: 18px
      }

      .stats-grid {
        grid-template-columns: repeat(2, 1fr)
      }

      .header {
        padding: 0 16px
      }
    }

    @media(max-width:480px) {
      .stats-grid {
        grid-template-columns: 1fr
      }

      .stat-value {
        font-size: 1.7rem
      }

      .election-status span:not(.status-dot) {
        display: none
      }
    }
  
    /* ── Layout fix ──────────────────────────────────────────
       .content is a flex column, and flex items shrink by default.
       Once the page grew taller than the viewport every section was
       squashed, and any card with overflow:hidden clipped its own
       text (the welcome hero showed the name but hid the meta line).
       Sections keep their natural height; .content already scrolls. */
    .content > * { flex-shrink: 0; }
  </style>
  <link rel="stylesheet" href="../assets/css/responsive.css?v=20261019-mobile-layout" />
  <script src="../assets/js/viewport.js?v=20260911-layout1" defer></script>
  <link rel="stylesheet" href="../assets/css/election-operations.css?v=20260913-clean-reports">
  <link rel="stylesheet" href="../assets/css/themes.css?v=20260915">
  <script src="../assets/js/appearance.js?v=20261110-settings"></script>
  <link rel="stylesheet" href="../assets/css/navigation.css?v=20261019-sidebar-first-paint" />
  <script src="../assets/js/navigation.js?v=20261110-shared-profile"></script>
  <script src="../assets/js/branding.js?v=20261019-sidebar-stable"></script>

  <link rel="stylesheet" href="../assets/css/tables.css?v=20261019-activity-top" />
  <link rel="stylesheet" href="../assets/css/responsive-tables.css?v=20261112-reference-layout" />
  <script src="../assets/js/responsive-tables.js?v=20261112-table-layout" defer></script>
  <link rel="stylesheet" href="../assets/css/compact-ui.css?v=20261113-clean-logo" />
  <link rel="stylesheet" href="../assets/css/admin-overview.css?v=20261112-no-status" />
</head>

<body>
  <div class="overlay" id="overlay" onclick="closeSidebar()"></div>

  <div class="app">

    <!-- ══ SIDEBAR ══ -->
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-logo">
        <div class="logo-mark">
          <svg viewBox="0 0 24 24">
            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
          </svg>
        </div>
        <div class="logo-text">Coop<span>Vote</span></div>
      </div>

      <div class="sidebar-section">Main</div>
      <a class="nav-item active" href="dashboard.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="7" height="7" />
          <rect x="14" y="3" width="7" height="7" />
          <rect x="3" y="14" width="7" height="7" />
          <rect x="14" y="14" width="7" height="7" />
        </svg>
        Dashboard
      </a>

      <div class="sidebar-section">Management</div>
      <a class="nav-item" href="voter-management.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
          <circle cx="9" cy="7" r="4" />
          <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
          <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
        Voter Management
      </a>
      <a class="nav-item" href="candidate-management.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
          <circle cx="12" cy="7" r="4" />
        </svg>
        Candidate Management
      </a>
      <a class="nav-item" href="election-management.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="4" width="18" height="18" rx="2" />
          <line x1="16" y1="2" x2="16" y2="6" />
          <line x1="8" y1="2" x2="8" y2="6" />
          <line x1="3" y1="10" x2="21" y2="10" />
        </svg>
        Election Management
      </a>

      <div class="sidebar-section">Analytics</div>
      <a class="nav-item" href="vote-monitoring.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
        </svg>
        Vote Monitoring
      </a>
      <a class="nav-item" href="results.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="18" y1="20" x2="18" y2="10" />
          <line x1="12" y1="20" x2="12" y2="4" />
          <line x1="6" y1="20" x2="6" y2="14" />
        </svg>
        Results
      </a>
      <a class="nav-item" href="reports.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
          <polyline points="14 2 14 8 20 8" />
          <line x1="16" y1="13" x2="8" y2="13" />
          <line x1="16" y1="17" x2="8" y2="17" />
        </svg>
        Reports
      </a>

      <div class="sidebar-section">System</div>
      <a class="nav-item" href="security-logs.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
        </svg>
        Security &amp; Logs
      </a>

      <div class="sidebar-footer">
        <div class="admin-card">
          <div class="admin-avatar" id="sidebar-avatar">?</div>
          <div class="admin-info">
            <div class="admin-name" id="sidebar-name">—</div>
            <div class="admin-role" id="sidebar-id">Administrator</div>
          </div>
        </div>
      </div>
    </aside>
    <script>CoopVoteNavigation.bootstrap(<?= $sidebarBootstrap ?>);</script>

    <!-- ══ MAIN ══ -->
    <div class="main">

      <!-- HEADER -->
      <header class="header">
        <button class="hamburger" onclick="toggleSidebar()" aria-label="Toggle menu">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="3" y1="6" x2="21" y2="6" />
            <line x1="3" y1="12" x2="21" y2="12" />
            <line x1="3" y1="18" x2="21" y2="18" />
          </svg>
        </button>

        <div class="header-title">
          <h1>Admin Overview</h1>
          <p id="header-date">Welcome back, System Admin</p>
        </div>

        <div class="header-actions">


          <button class="hbtn" title="Notifications">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
              <path d="M13.73 21a2 2 0 0 1-3.46 0" />
            </svg>
          </button>

          <button class="hbtn" title="Logout" onclick="CoopVote.logout()"
            style="border:1px solid rgba(224,92,92,.2);color:var(--danger)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
              <polyline points="16 17 21 12 16 7" />
              <line x1="21" y1="12" x2="9" y2="12" />
            </svg>
          </button>
        </div>
      </header>

      <main class="content overview" id="admin-overview">
        <p id="dashboard-error" class="ov-error" role="alert" hidden></p>
        <section class="ov-stats" aria-label="Account summary">
          <article class="ov-stat" aria-labelledby="voter-summary-title"><h2 class="ov-stat-label" id="voter-summary-title">Registered Voters</h2><strong id="overview-voters">—</strong><span id="overview-active-voters">Loading voter accounts…</span></article>
          <article class="ov-stat" aria-labelledby="officer-summary-title"><h2 class="ov-stat-label" id="officer-summary-title">Active Officers</h2><strong id="overview-officers">—</strong><span id="overview-total-officers">Loading officer accounts…</span></article>
          <article class="ov-stat ov-stat-attention" aria-labelledby="verification-summary-title"><h2 class="ov-stat-label" id="verification-summary-title">Pending Verification</h2><strong id="overview-pending">—</strong><span>Officer invitations &amp; email changes</span></article>
        </section>
        <div class="ov-columns">
          <section class="ov-panel" id="needs-attention" aria-labelledby="attention-title">
            <div class="ov-panel-head"><div><p class="ov-eyebrow">NEXT STEPS</p><h2 id="attention-title">Needs Attention</h2></div><span class="ov-pill" id="attention-count">—</span></div>
            <div id="overview-attention" class="ov-panel-body"><p class="ov-empty">Loading requests…</p></div>
            <a class="ov-panel-footer" href="officer-management.php">Open Officer Management <span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 12h16m-6-6 6 6-6 6"/></svg></span></a>
          </section>
          <section class="ov-panel" aria-labelledby="activity-title">
            <div class="ov-panel-head"><div><p class="ov-eyebrow">LATEST UPDATES</p><h2 id="activity-title">Recent Activity</h2></div><a href="security-logs.php" class="ov-text-link">View logs <span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg></span></a></div>
            <ol id="overview-activity" class="ov-activity"><li class="ov-empty">Loading administrator activity…</li></ol>
          </section>
        </div>
        <section class="ov-panel ov-shortcuts" aria-labelledby="quick-actions-title">
          <div class="ov-panel-head"><div><h2 id="quick-actions-title">Quick Actions</h2><p>Go straight to your everyday tasks.</p></div></div>
          <div class="ov-actions">
            <a href="voter-management.php?action=add"><span class="ov-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></span><span><strong>Register Voter</strong><small>Add a voter account</small></span><span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg></span></a>
            <a href="officer-management.php?action=add"><span class="ov-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></span><span><strong>Add Officer</strong><small>Send an account invitation</small></span><span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg></span></a>
            <a href="branding.php"><span class="ov-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 7h8m4 0h4M4 17h2m4 0h10"/><circle cx="14" cy="7" r="2"/><circle cx="8" cy="17" r="2"/></svg></span><span><strong>Settings</strong><small>Branding &amp; display preferences</small></span><span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg></span></a>
          </div>
        </section>
      </main><!-- /content -->
    </div><!-- /main -->
  </div><!-- /app -->

  <script src="../assets/js/validation.js?v=20260911-required2"></script>
  <script src="../assets/js/app.js?v=20261113-top-right-toasts"></script>
  <script src="../assets/js/election-operations.js?v=20260913-clean-reports"></script>
  <script src="../assets/js/admin-overview.js?v=20261112-no-status"></script>
</body>
</html>
