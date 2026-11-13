# Module page URLs

Settings and management modules use role-guarded PHP pages:

| Module | Administrator | Voter |
| --- | --- | --- |
| Themes | `admin/themes.php` | `voter/themes.php` |
| Officer Management | `admin/officer-management.php` | — |
| Election Approvals | `admin/election-approvals.php` | — |
| Branding | `admin/branding.php` | — |

Each entry point runs its existing server-side role guard before including
`includes/management-page.php`. The shared template rejects direct requests.
The module is selected by the entry point, not a query string or fragment.
Navigation links and active states use the page pathname. The same device theme,
sidebar initialization, saved menu scroll and API permissions remain in use.

`management.php` is a compatibility entry point for old bookmarks. Since URL
fragments never reach PHP, its small browser redirect maps allowlisted fragments
to the correct role's named page with `location.replace`. It renders no forms and
preserves only a valid election ID. `management.html` uses the existing rewrite.
Unknown or unauthorized legacy modules go to the role's default module.
