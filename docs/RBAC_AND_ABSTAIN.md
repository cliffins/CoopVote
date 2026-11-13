# Administrator page access and abstention

All eight admin pages and seven voter pages use `includes/role-page.php` through
their respective `admin-page.php` and `voter-page.php` wrappers before producing
HTML. Active users with the matching role receive 200. Cross-role requests in
either direction receive an empty HTTP 302 redirect to their last permitted page,
without rendering an Access denied page or any dashboard markup. The session
records allowed pages; a validated browser cookie preserves the current election
or management tab on the same page. Destinations are restricted to local pages
allowed for the current role. New logins reset the remembered page to that role's
home. Visitors without an active session redirect to login. Shared management
pages also require an active session. API denials remain HTTP 401/403.
For voter links with a valid `election` parameter, signed-out visitors receive
302 to `login.php?election=ID`, preserving previously printed QR links.
An authorization failure returns 503 without rendering admin content. Permissions
come from the current database user, not browser storage or a cached session role.

The public `admin/*.html` and `voter/*.html` URLs are preserved by each directory's
`.htaccess`, which rewrites them internally to the corresponding guarded PHP pages.
Deploy the PHP pages and remove the old protected HTML files together. Existing static HTML files would bypass
PHP on hosts that serve static files directly through nginx. Apache must enable
mod_rewrite and AllowOverride. Direct PHP requests use the same guard.
App navigation now uses PHP URLs to avoid old browser-cached static HTML pages.
The root login and management HTML aliases also rewrite to uncached PHP pages.

Each position with candidates offers a candidate or an explicit Abstain choice.
Abstaining clears the candidate selection, completes the position, and appears in
the review and confirmation. Positions with no candidates remain skipped.
The API requires one answer for every position with active candidates and rejects
duplicate positions, contradictory choices, inactive/foreign candidates, and
invalid abstentions. Abstentions use `positionId`, `candidateId: null`, and
`abstain: true`.

A ballot containing only abstentions still claims the ballot atomically and
receives a receipt. It increases participation without inserting candidate votes.
No schema migration is required, and no individual abstention choices are stored
against a voter. Candidate signatures and duplicate-vote protection remain in use.

Verification:

- `python3 database/tests/run.py`: isolated database/API tests, all admin and voter pages
  through HTML and PHP URLs, anonymous/voter/admin roles, mixed and all-abstain
  ballots, invalid submissions, receipts, tally and duplicate-vote checks.
- `node database/tests/ballot-ui.cjs`: ballot script behavior using a DOM adapter;
  this is not a full browser rendering test.
- Live unauthenticated requests verify that the hosting rewrite invokes PHP.

For manual FR-RBAC-01 testing, sign in as a voter, open Profile or Themes, then
request `admin/dashboard.html` or `.php`: expect a 302 back to the previous
permitted page, with no admin markup. Repeat as an administrator from Reports or
Themes using `voter/dashboard.html` or `.php`. Signed-out visitors requesting
either dashboard or management must return to login. Authorized pages still
return 200. The optional Chromium suite verifies these actual browser flows.

Election QR codes now encode `login.php?election=ID`: scan first, sign in, then
open that election. Already signed-in voters continue automatically through the
login page; administrators go to their own dashboard. Invalid election IDs are
not carried forward. Existing election availability checks still run after login.
Run `node database/tests/qr-login.cjs` to check QR payloads, copied links, login
destinations and invalid IDs. The HTTP suite also covers legacy QR redirects,
preserved election IDs, and wrong-role rejection with an election parameter.
