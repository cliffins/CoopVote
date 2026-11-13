Run `python3 database/tests/run.py` from the project root. Requires PHP with pdo_mysql, Python 3, and the MariaDB server binary.

## NFR21 — stored vote integrity evidence

Run `python3 database/tests/nfr21.py --verify-production --output-dir /tmp/coopvote-nfr21-new-run` from the project root. Choose a fresh output directory. This separately tests legitimate stored signatures, a committed signed-field alteration, a committed sealed-count alteration, and recovery controls using synthetic data on a disposable socket-only server. The explicit `--verify-production` flag adds before/after READ ONLY comparisons of ten production vote/election tables; it performs no production mutations and exports only equality results. Without the flag, production preservation is NOT TESTED and the four-check scope is not marked PASS.

The runner saves redacted expected/actual logs and JSON, excludes keys/passwords/voter data, checks its database guards, and cleans up the disposable instance. See the [27 October 2026 NFR21 report](../../NFR21_TEST_REPORT_2026-10-27.md) for the actual run and its limitations. A scoped NFR21 pass does not establish complete application regression coverage.

## NFR-PERF-02 — concurrent processing evidence

Run `python3 database/tests/perf-concurrency.py --output-dir /tmp/coopvote-perf02-new-run` from the project root on Linux. Choose a fresh output directory. Requires PHP with `pdo_mysql` and multi-worker CLI server support, Python 3, and the MariaDB server/client. Defaults: `--voters 40 --workers 8` (60 total synthetic voters after adding new voters in the mixed batch).

The runner creates a private socket-only MariaDB instance and loopback multi-process PHP server, then sends 220 voting requests in synchronized batches. It covers different eligible voters, competing submissions from independent sessions for the same voter, new voters mixed with duplicates, and post-vote replays. Server start/end traces prove request overlap; accepted responses are reconciled with stored ballots, receipts, exact candidate totals and integrity signatures after every batch. Transaction rollback/deadlock counters and error logs are checked. No production credentials are loaded; email is disabled; temporary services and data are removed afterward.

See the [28 October 2026 passing report](../../NFR_PERF_02_CONCURRENCY_REPORT_2026-10-28.md) and its saved evidence. This controlled correctness test does not establish production capacity or a latency SLA, and need not be repeated merely to verify the existing result.

## General regression suite

Set `COOPVOTE_DASHBOARD_TEST=1` to include the current Admin Overview suite on a separate disposable fixture. Set `COOPVOTE_PLAYWRIGHT_MODULE` to the installed Playwright module and optionally `COOPVOTE_DASHBOARD_SCREENSHOT` to save its desktop screenshot. For a focused run, use `python3 database/tests/management-layout-browser.py --suite overview --output-dir /tmp/coopvote-overview-new-run --playwright-module /path/to/playwright`. Set `PLAYWRIGHT_BROWSERS_PATH` and `FONTCONFIG_FILE` if required by the test host. This tests real account counts, pending/failed/expired verification categories, administrator attribution, access boundaries, shortcuts, responsive layouts, empty states, error recovery, and escaped audit text. The older `dashboard-browser.cjs` is retained as historical coverage of the retired election dashboard; its UI assertions no longer describe the current screen. Existing `endpoints.py` assertions still cover the compatible election-summary API. See [Admin Overview verification](../../docs/ADMIN_OVERVIEW_2026-11-12.md).

FR8 backend restriction and deletion policy: `python3 database/tests/fr8.py --backend-only --regression --output-dir /tmp/coopvote-fr8-backend-new-run` starts a disposable database and loopback server. Direct PUT/PATCH requests exercise start/end changes, date aliases and stale requests after opening. DELETE checks cover Live/Closed elections with no votes, prior opening history, votes and abstentions. A simulated audit-write failure verifies that deletion and its cascades roll back. No browser dependencies are required for this mode. Without `--backend-only`, the existing browser suite checks the corresponding controls.

Within the existing isolated test harness, `python3 database/tests/nfr19.py --application-only` runs the application disclosure checks without the separate backup/migration/rollback exercise. It requires the harness's disposable database configuration and loopback `COOPVOTE_TEST_URL`; the default invocation still includes migration coverage. See [NFR19 verification](../../docs/NFR19.md#verification-after-restoring-reopening) for the latest scoped results.

Reopening with existing votes: `python3 database/tests/reopening.py --output-dir /tmp/coopvote-reopening-new-run --playwright-module /path/to/playwright --browser-path /path/to/playwright-browsers --regression`. Optional `--fontconfig-file /path/to/fonts.conf` supplies fonts for screenshots. This uses a disposable socket-only database and loopback PHP server. It verifies approval and deadline checks, actual Admin UI reopening, preservation of existing voting records and signed reports, duplicate-vote protection, additional voting, result withdrawal, integrity-failure rejection, and authorization. The updated disclosure-policy limitation is documented in [NFR19](../../docs/NFR19.md).

Candidate profile/photo browser checks: run `python3 database/tests/candidate-photo-browser.py --output-dir /tmp/coopvote-photo-new-run --playwright-module /path/to/playwright --browser-path /path/to/playwright-browsers`. Use a fresh output directory. On hosts without system fonts, `--fontconfig-file /path/to/fonts.conf` supplies local font configuration. This creates a private socket-only synthetic MariaDB instance (grant checks disabled only there), uses a loopback PHP server, and tests save/reopen, replacement, cancellation, member-photo fallback, JPG/PNG/WebP, chunked persistence, invalid-image rejection, and permissions. Production credentials are never loaded. The runner saves real browser screenshots, JSON results, and logs, then stops its services and removes the disposable database.

The runner creates a private temporary directory, a MariaDB instance with networking disabled, and a PHP server bound to loopback. It imports the bundled schema and synthetic accounts, disables outgoing email, and tests the API through real HTTP requests. It does not load production credentials. Both servers stop and the fixture directory is removed when the run finishes.

Coverage includes every routed action and supported method's authentication boundary; admin/member login and sessions; member, candidate, election and account CRUD; CSV import/export; preferences, branding and settings; election approvals, opening, voting, duplicate-vote rejection, closing, publication and reopening; notifications; profile photos and password changes; security controls; signed reports; singleton identity, native prepares, timezone, schema, rollback and safe connection-error responses.

Live production checks are deliberately separate: the recorded live sweep tested public responses and unauthenticated access guards. External SMTP delivery is not exercised.

Admin and voter HTML/PHP page access is also checked as anonymous, voter, and administrator
(FR-RBAC-01). Voting coverage includes mixed and all-abstain ballots, malformed or
duplicate answers, receipt creation, unchanged candidate totals for abstentions,
and duplicate-vote rejection. Run `node database/tests/ballot-ui.cjs` for the
ballot selection/progress/confirmation behavior checks using a DOM adapter.

Unauthorized pages redirect to the last permitted page (or login without an active
session) with empty response bodies; API denials keep HTTP 401/403. Run
`php database/tests/page-navigation.php` for local destination and forged-cookie
checks. The optional Chromium suite also tests manual URL edits in both role
directions, returning to Profile/Reports/Themes, and direct access after logout.

Run `node database/tests/qr-login.cjs` for QR link and post-login destination
checks. The HTTP suite covers old QR links redirecting signed-out visitors to
login with the selected election, invalid parameters, and cross-role rejection.

Run `node database/tests/device-theme.cjs` for browser-scoped theme persistence
across accounts and reloads, independent browser storage, cross-tab updates,
defaults and unavailable storage. Themes no longer read or write account
preferences from the UI; existing account preference API data is ignored.

For an actual Chromium account-switch test, install Playwright separately and run
`COOPVOTE_BROWSER_TEST=1 python3 database/tests/run.py`. If installed outside this
project, set `COOPVOTE_PLAYWRIGHT_MODULE` to its module path and
`PLAYWRIGHT_BROWSERS_PATH` to the browser install directory. This optional test
uses only the disposable database, exercises the real login and theme controls,
and verifies uncached entry pages and protection against legacy theme writes.

Set `COOPVOTE_SIDEBAR_TEST=1` to run `sidebar-browser.cjs` with the same Playwright
configuration. It checks every role page and settings, records initial animation
frames while delaying authentication responses, compares menu dimensions and
scroll positions, clicks Candidate Management repeatedly, checks long branding,
and opens/closes the drawer at phone, tablet and desktop breakpoints.
It also verifies named module URLs, their active menu items and reloads, plus
conversion of legacy `management.php#...` and `management.html#...` bookmarks.

Set `COOPVOTE_MOBILE_TEST=1` with the same Playwright configuration for Officer
Management, Voter Management, Vote Monitoring, My Profile and View Candidates at
320, 375, 600, 768, 1024 and 1280 pixels. It checks aligned fields, organized
controls, usable monitoring height, scrolling within the votes table, centered
profile pictures, and unclipped candidate pictures in cards and dialogs. Real
page guards use the disposable database; monitoring and candidate display data
are synthetic browser fixtures. Login checks block decorative scripts and
external fonts, disable WebGL, verify the CSS background and motion preferences,
and complete real sign-in at phone and desktop widths. Screenshots go to `/tmp`.

## Officer email verification

Run `python3 database/tests/officer-verification.py --output-dir /tmp/coopvote-officer-new-run --playwright-module /path/to/playwright --regression`. Choose a new output directory. Omit `--playwright-module` only for API-only testing (browser coverage is then not run). Requires PHP/pdo_mysql, MariaDB server/client, Python 3, and Playwright Chromium for browser coverage. Set `PLAYWRIGHT_BROWSERS_PATH` and `FONTCONFIG_FILE` if required by your test host.

The runner creates a private socket-only database named `coopvote_test` and a loopback PHP server. It captures synthetic invitation emails in a private temporary outbox, sends no real mail, tests single use/expiry/resend limits, password ownership, authorization, simultaneous acceptance, and preservation of nonempty synthetic election records. The browser checks Add Officer and verification at 320, 375, 768 and 1280 px, activation/login, email changes, same-tab links, and reveal-button stability. `--regression` resets only that disposable fixture before running the existing HTTP and database suites. Services and private tokens are removed on exit. Exported evidence contains expected/actual results and synthetic screenshots, without passwords or verification tokens. This does not test SMTP delivery to a real inbox.

Current mobile table behavior (12 November 2026): native table rows and visible column headings are retained; wide tables scroll inside their container. Run `management-layout-browser.py --suite table` with the existing output-directory and Playwright arguments. The suite now checks horizontal reachability of Actions instead of expecting stacked cards. See [mobile table verification](../../docs/MOBILE_TABLE_LAYOUT_2026-11-12.md).
