# Database Backup and Activity Logs

Open **Admin → Security & Logs** (`admin/security-logs.php`).

## Download a database backup

The web download is refused with `BALLOT_PRIVACY_BACKUP_RESTRICTED` once recorded ballots or votes exist. Use the reviewed hosting-administrator maintenance procedure for a populated voting database. The steps below apply only while the web download is permitted.

1. Select **Download database backup**.
2. Wait for preparation to finish, then check your browser's Downloads for `coopvote-database-YYYYMMDD-HHMMSS.sql`.
3. Store the file privately. It contains account records, password hashes, election/voting data, settings and audit history.

This downloads the database schema and records, including views, triggers, routines and events. Website code, configuration/signing keys and filesystem uploads must be backed up separately for a complete system recovery. Database-stored binary data is included. The SQL file is not encrypted.

**Last prepared** records successful server preparation, not proof that the browser saved the file. Successful and failed preparation are recorded in Activity Logs. Only an authenticated administrator can request the backup. One backup can be prepared at a time; temporary server files are private and removed after use. Errors return an explanation instead of a partial SQL download.

The server needs `mariadb-dump` or `mysqldump`, PHP `proc_open`, private temporary storage and database privileges to read the schema/data and its routines/events/triggers. All application tables must use InnoDB. The dump uses a consistent transaction; avoid concurrent schema migrations. Preparation is limited to 120 seconds. Large databases may need a hosting-level backup.

Restore into a new, empty database with a compatible MariaDB client or an administrator-managed import tool. Test a restore before replacing production data. This page does not overwrite or restore the live database.

## Find activity

Choose **From date**, **To date**, **Administrator**, and/or **Activity type**; optionally enter an event, name, username or IP address in **Search**. Select **Apply filters**. Dates include the whole selected day in Philippine time. **Clear filters** returns to all entries.

Results cover the full saved history, with 50 entries per page. **Previous/Next** changes the page; **Refresh** keeps the selected filters and page. Click an entry to see its recorded details. **Export filtered CSV** downloads every matching entry, including entries on other pages.

The existing scope is preserved: administrator activity only, excluding theme/branding changes. Voter turnout is available in Vote Monitoring. Inactive administrators remain available in the filter if their account still has the administrator role and saved history. CSV cells are protected against spreadsheet formula interpretation.

## Verification

`python3 database/tests/run.py` runs the existing API/database checks plus `security-backup.py`. Tests create a disposable socket-only database, exercise authorization and filters, export more than 500 matching rows, and restore an actual SQL download. Every restored table's rows are compared with the source; binary/Unicode data, views, routines, triggers and events are checked.

Install the MariaDB server/client support files for the test runner. Minimal installations can set `COOPVOTE_TEST_SYSTEM_SQL` to the matching official MariaDB `scripts/mariadb_system_tables.sql` source file. The runner imports it only into the disposable test database.

Set `COOPVOTE_SECURITY_BROWSER_TEST=1` and `COOPVOTE_PLAYWRIGHT_MODULE` to an installed Playwright module to run browser checks for filters, pagination, downloads, error recovery and layouts at 320, 375, 768 and 1280 pixels. `COOPVOTE_SECURITY_SCREENSHOT` optionally saves a mobile screenshot.
