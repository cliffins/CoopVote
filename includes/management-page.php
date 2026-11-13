<?php
// Included only by a role-guarded module page; direct requests render no UI.
if (!defined('COOPVOTE_MANAGEMENT_PAGE')) { http_response_code(404); exit; }
$managementTab = COOPVOTE_MANAGEMENT_PAGE;
$managementTitle = ['themes' => 'Themes', 'officers' => 'Officer Management',
    'approvals' => 'Election Approvals', 'branding' => 'Settings'][$managementTab];
?>
<!DOCTYPE html>
<html lang="en" data-cv-theme="<?= device_theme() ?>">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CoopVote <?= $managementTitle ?></title>
  <link rel="stylesheet"
    href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@700;800&display=swap">
  <link rel="stylesheet" href="../assets/css/management.css?v=20261113-officer-scroll">
  <link rel="stylesheet" href="../assets/css/settings-sidebar.css?v=20260915">
  <link rel="stylesheet" href="../assets/css/themes.css?v=20261014-matching-palette">
  <script src="../assets/js/appearance.js?v=20261110-settings"></script>
  <link rel="stylesheet" href="../assets/css/navigation.css?v=20261019-sidebar-first-paint" />
  <script src="../assets/js/navigation.js?v=20261110-shared-profile"></script>
  <script src="../assets/js/branding.js?v=20261019-sidebar-stable"></script>
  <script src="../assets/js/settings-sidebar.js?v=20261019-device-theme2"></script>
  <script src="../assets/js/voter-navigation.js?v=20261019-sidebar-stable"></script>
  <link rel="stylesheet" href="../assets/css/tables.css?v=20260918-scroll-position" />
  <link rel="stylesheet" href="../assets/css/responsive-tables.css?v=20261112-reference-layout" />
  <script src="../assets/js/responsive-tables.js?v=20261112-table-layout" defer></script>
  <link rel="stylesheet" href="../assets/css/compact-ui.css?v=20261113-clean-logo" />
</head>

<body data-management-page="<?= $managementTab ?>" data-management-role="<?= $managementRole ?>">
  <div class="settings-shell">
    <aside class="sidebar" id="sidebar" data-shared-sidebar aria-label="Main navigation"></aside>
    <script>CoopVoteNavigation.bootstrap(<?= $sidebarBootstrap ?>);</script>
    <button class="sidebar-overlay" id="sidebar-overlay" type="button" aria-label="Close menu" hidden></button>
    <main id="main" tabindex="-1">
      <header class="page-heading"><button class="hamburger hbtn" id="menu-toggle" type="button" aria-label="Open menu"
          aria-controls="sidebar" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="2">
            <path d="M3 6h18M3 12h18M3 18h18" />
          </svg></button>
        <div class="heading-text">
          <h1 id="heading"><?= $managementTitle ?></h1>
          <p id="section-label"><?= in_array($managementTab, ['themes', 'branding'], true) ? 'Settings' : 'Management' ?></p>
        </div>
        <div class="header-actions"><button class="hbtn" type="button" title="Notifications" aria-label="Notifications"><svg
              viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
              <path d="M13.73 21a2 2 0 0 1-3.46 0" />
            </svg></button><button class="hbtn logout-button" type="button" id="logout" title="Logout"
            aria-label="Log out"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
              <polyline points="16 17 21 12 16 7" />
              <line x1="21" y1="12" x2="9" y2="12" />
            </svg></button></div>
      </header>
      <div class="module-content">
        <p id="page-error" role="alert" hidden></p>
        <section id="themes" hidden aria-label="Theme preferences">
          <div class="module-intro">
            <h2>Your theme</h2>
            <p class="hint">Choose a theme for this browser. It stays the same when you sign in with another account on this device.</p>
          </div>
          <div id="theme-grid" class="theme-grid"></div>
        </section>
        <section id="officers" hidden aria-label="Officer accounts">
          <div class="panel officer-directory">
            <div class="section-heading">
              <div class="module-intro">
                <h2>Administrator accounts</h2>
                <p class="hint">Invite officers to verify their email and choose a password. Existing administrator access is retained.</p>
              </div><span class="count-label" id="officer-count" role="status"></span>
            </div>
            <div class="module-toolbar"><label class="search-box"><span class="sr-only">Search officers</span><input
                  id="officer-search" type="search" placeholder="Search name, username or email"></label><label><span
                  class="sr-only">Officer status</span><select id="officer-status" class="filter-select">
                  <option value="">All Statuses</option>
                  <option value="active">Active</option>
                  <option value="pending_verification">Pending Verification</option>
                  <option value="deactivated">Deactivated</option>
                </select></label><button type="button" id="add-officer" class="primary">Add Officer</button></div>
            <div class="table-wrap">
              <table class="officer-table">
                <colgroup>
                  <col class="officer-col-name">
                  <col class="officer-col-username">
                  <col class="officer-col-email">
                  <col class="officer-col-status">
                  <col class="officer-col-actions">
                </colgroup>
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="accounts"></tbody>
              </table>
            </div>
          </div>
          <section class="panel password-settings" aria-labelledby="password-heading">
            <h2 id="password-heading">Change My Password</h2>
            <form id="password-form" class="compact-form">
              <p class="hint password-intro">Update the password for your own administrator account.</p>
              <div class="password-field">
                <label for="admin-current-password">Current password</label>
                <div class="password-input-wrap">
                  <input id="admin-current-password" name="currentPassword" type="password" required autocomplete="current-password">
                  <button type="button" class="password-visibility" data-password-target="admin-current-password" data-password-label="current password" aria-controls="admin-current-password" aria-label="Show current password" aria-pressed="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="password-eye-slash" d="m3 3 18 18" hidden/></svg>
                  </button>
                </div>
              </div>
              <div class="password-field">
                <label for="admin-new-password">New password</label>
                <div class="password-input-wrap">
                  <input id="admin-new-password" name="newPassword" type="password" required minlength="8" maxlength="72" autocomplete="new-password" aria-describedby="password-strength-text password-guidance">
                  <button type="button" class="password-visibility" data-password-target="admin-new-password" data-password-label="new password" aria-controls="admin-new-password" aria-label="Show new password" aria-pressed="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="password-eye-slash" d="m3 3 18 18" hidden/></svg>
                  </button>
                </div>
                <p class="hint" id="password-guidance">Use at least 8 characters and 3 types: uppercase, lowercase, numbers or symbols.</p>
              <div class="password-strength" data-level="empty">
                <div class="password-track" role="meter" aria-label="Password strength" aria-valuemin="0"
                  aria-valuemax="4" aria-valuenow="0" aria-valuetext="Enter a password" id="password-meter"><span
                    id="password-strength-fill"></span></div>
                <p id="password-strength-text" class="hint" role="status">Enter a password</p>
              </div>
              </div>
              <div class="password-field">
                <label for="admin-confirm-password">Confirm new password</label>
                <div class="password-input-wrap">
                  <input id="admin-confirm-password" name="confirmPassword" type="password" required autocomplete="new-password">
                  <button type="button" class="password-visibility" data-password-target="admin-confirm-password" data-password-label="confirmed password" aria-controls="admin-confirm-password" aria-label="Show confirmed password" aria-pressed="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="password-eye-slash" d="m3 3 18 18" hidden/></svg>
                  </button>
                </div>
              </div>
              <p class="form-error" role="alert"></p>
              <div class="form-actions"><button type="button" id="cancel-password">Cancel</button><button
                  class="primary" type="submit" id="save-password" disabled>Update Password</button></div>
            </form>
          </section>
        </section>
        <section id="branding" hidden aria-label="System settings">
          <form id="candidate-display-form" class="panel">
            <div class="module-intro">
              <h2>Candidate display</h2>
              <p class="hint" id="candidate-display-help">Applies to all elections. Hide Year &amp; Section from candidate forms, profiles, lists, and exports while keeping saved values.</p>
            </div>
            <label class="candidate-setting-toggle"><input type="checkbox" id="show-year-section" aria-describedby="candidate-display-help" disabled>Show Year &amp; Section</label>
            <p class="form-error" role="alert"></p>
            <div class="form-actions single-action"><button type="submit" class="primary" id="candidate-display-save" disabled>Save Display Settings</button></div>
          </form>
          <form id="branding-form" class="panel branding-form">
            <div class="module-intro">
              <h2>Cooperative identity</h2>
              <p class="hint">Set the logo and names members see throughout CoopVote.</p>
            </div>
            <div class="branding-layout">
              <div class="branding-fields">
                <label>Logo text<input id="branding-text" name="text" required maxlength="40" value="CoopVote"></label>
                <label>Cooperative name<input id="branding-coop-name" name="coopName" required maxlength="150"
                    aria-describedby="branding-coop-help"></label>
                <p id="branding-coop-help" class="hint">Shown below the logo in the sidebar.</p>
                <label>Logo image<input id="branding-file" type="file" accept="image/png,image/jpeg,image/webp"
                    aria-describedby="branding-help"></label>
                <p id="branding-help" class="hint">PNG, JPEG or WebP, up to 5 MB. The image will be resized to fit the
                  logo
                  mark.</p>
              </div>
              <aside class="branding-sample" aria-label="Branding preview">
                <span class="eyebrow">Live preview</span>
                <div class="branding-preview">
                  <div class="branding-mark"><img id="branding-preview-image" alt="" hidden><svg
                      id="branding-preview-default" viewBox="0 0 24 24" aria-hidden="true">
                      <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
                    </svg></div>
                  <span id="branding-preview-text">CoopVote</span>
                  <span id="branding-preview-coop">Santiago City Multi-Purpose Cooperative</span>
                </div>
                <p class="hint">Your sidebar identity, as members will see it.</p>
              </aside>
            </div>
            <p class="form-error" role="alert"></p>
            <div class="form-actions"><button type="button" id="branding-remove">Use Default</button><button
                type="submit" class="primary" id="branding-save" disabled>Save Identity</button>
            </div>
          </form>
        </section>
        <section id="approvals" hidden aria-label="Election approvals">
          <form id="approval-config" class="panel config-form">
            <div class="module-intro">
              <h2>Approval requirement</h2>
              <p class="hint">Set how many administrators must approve an election before it opens.</p>
            </div>
            <div class="config-controls"><label>Approvals required<select class="filter-select" name="requiredApprovals"
                  id="threshold" required></select></label><button type="submit" id="save-requirement" class="primary"
                disabled>Save Requirement</button></div>
            <p id="admin-count" class="hint"></p>
            <p class="hint">Applies to new requests. Each Admin account can decide once per request.</p>
            <p class="form-error" role="alert"></p>
          </form>
          <div class="panel election-review">
            <div class="module-intro">
              <h2>Election review</h2>
              <p class="hint">Review the election and record your decision.</p>
            </div>
            <div class="review-toolbar"><label>Election<select class="filter-select"
                  id="election-select"></select></label></div>
            <div id="review"></div>
          </div>
        </section>
      </div>
    </main>
  </div>
  <dialog id="officer-dialog" aria-labelledby="officer-title">
    <form id="officer-form">
      <div class="dialog-heading">
        <h2 id="officer-title">Add Officer</h2>
      </div>
      <input type="hidden" name="id">
      <label>Full name<input name="name" required maxlength="100" autocomplete="name" placeholder="e.g. Maria Santos"></label>
      <label>Username<input name="username" required minlength="3" maxlength="50" pattern="[A-Za-z0-9._\-]{3,50}"
          autocomplete="off" placeholder="e.g. maria.santos"></label>
      <label>Email<input name="email" type="email" required maxlength="100" autocomplete="email" placeholder="e.g. maria.santos@example.com"></label>
      <p class="hint">New officers receive a verification link, valid for 30 minutes, to set their own password. Email changes keep the current address until the new one is verified.</p>
      <p class="form-error" role="alert"></p>
      <div class="form-actions"><button type="button" data-close="officer-dialog">Cancel</button><button type="submit"
          class="primary" id="save-officer">Create Officer</button></div>
    </form>
  </dialog>
  <dialog id="confirm-dialog" aria-labelledby="confirm-title">
    <form id="confirm-form">
      <h2 id="confirm-title">Confirm Action</h2>
      <p id="confirm-message"></p><label id="reason-label" hidden>Reason<textarea name="note" maxlength="1000"
          rows="4"></textarea></label>
      <p class="form-error" role="alert"></p>
      <div class="form-actions"><button type="button" data-close="confirm-dialog">Cancel</button><button type="submit"
          class="primary" id="confirm-action">Confirm</button></div>
    </form>
  </dialog>
  <div id="toast" role="status" aria-live="polite" hidden></div>
  <script src="../assets/js/app.js?v=20261113-top-right-toasts"></script>
  <script src="../assets/js/management.js?v=20261113-password-visible"></script>
</body>

</html>
