# CoopVote Installation and Deployment Guide

Version 1.0 • 27 October 2026

Audience: developers and server administrators. This guide describes the source currently present in this project. Commands are instructions for a new target or staging host; creating this guide does not install packages or change the running service.

## 1. Deployment choices and prerequisites

The worked example uses Linux, Apache 2.4, PHP 8.3, and MariaDB 11.4, with PHP and MariaDB on the same host and an explicit Unix socket. The inspected CLI environment reported PHP 8.3.36, Apache 2.4.58, and MariaDB client 11.4.12. These observations do not prove the web PHP version or establish a complete supported-version matrix.

The source uses PHP features requiring at least PHP 8.1 syntax, including `never` return types and readonly properties. Use a maintained runtime and validate this application on the chosen version. MariaDB is the documented database target; the TCP encryption verifier explicitly rejects MySQL because its encryption metadata differs.

Required PHP capabilities:

| Capability | Purpose |
| --- | --- |
| PDO and `pdo_mysql` | MariaDB connections |
| `mbstring`, `fileinfo` | Input validation, CSV encoding, image identification |
| OpenSSL | Mail TLS, database certificates, encrypted backup tools |
| Sessions, JSON, hash, filter, ctype | Authentication and application runtime |
| `mysqlnd` | Required by the verified TCP/TLS connection path |
| Writable private session and temporary storage | Login sessions and backup preparation |
| `proc_open` and MariaDB dump client | Administrator database-backup feature |

The PHP dependencies are bundled in `vendor/`; keep that directory in the release. There is no root Composer manifest or Node build process in this checkout. Browser assets are already present. Node.js and Python are needed for the test suite, not normal request handling.

### Example package installation

On a new Ubuntu/Debian-style host, install the distribution packages and check the resulting versions. Package versions and PHP service names vary by distribution. On an existing managed host, use its configured PHP handler and control panel instead of installing a second one.

```bash
sudo apt update
sudo apt install apache2 php php-cli libapache2-mod-php php-mysql php-mbstring php-xml php-curl mariadb-server mariadb-client
sudo a2enmod rewrite
php -v
php -m
apache2 -v
mariadb --version
```

This example selects Apache's PHP module. PHP-FPM is also possible, but requires the host's Apache/FastCGI handler configuration and environment forwarding. Verify extensions in both CLI and the web runtime. See the [PHP package installation documentation](https://www.php.net/manual/en/install.unix.debian.php).

## 2. Place the files and configure permissions

Use this layout, substituting your deployment path for `/srv/coopvote`:

```text
/srv/coopvote/
├── private/
│   ├── coopvote-db.php
│   └── backup.key             # For encrypted CLI backup/migration tools
└── public_html/
    ├── index.php
    ├── login.php
    ├── config/
    ├── database/
    └── ...remaining application files...
```

Copy the application with its hidden `.htaccess` and `.user.ini` files. Point Apache at `public_html`, never at its parent. Transfer deployment credentials and signing keys separately from general source archives. Do not distribute live `config/security-local.php`, `config/integrity-key.php`, mail credentials, logs, or database/test-data exports as sample data.

The PHP worker needs read access to application files and the private database configuration. It needs write access to its session directory, temporary directory, and `public_html/logs/` if mail logging is enabled. Profile images are stored in the database; the source does not require making the entire application writable. A typical arrangement uses a deployment owner, the PHP worker's group for private files, directories at `0750`, and secrets at `0640`. Substitute the actual service account; avoid recursive ownership changes on an existing managed installation.

Check any `open_basedir` restrictions: the web runtime must be able to read the sibling private configuration and certificate/key files and use its configured temporary storage.

## 3. Configure Apache

For an isolated staging installation, create an Apache site configuration similar to the following. Replace the domain and paths. The PHP handler must already be enabled by the package or hosting configuration.

```apache
<VirtualHost *:80>
    ServerName coopvote.example.test
    DocumentRoot /srv/coopvote/public_html
    DirectoryIndex index.php index.html

    <Directory /srv/coopvote/public_html>
        Options -Indexes +FollowSymLinks -MultiViews
        AllowOverride All
        Require all granted
    </Directory>

    <DirectoryMatch "^/srv/coopvote/public_html/(config|database|includes|tools|logs|audit|vendor|docs|\.vscode|\.git)(/|$)">
        Require all denied
    </DirectoryMatch>
    <Directory /srv/coopvote/public_html/api/routes>
        Require all denied
    </Directory>
    <FilesMatch "(?i)(^\.|\.(sql|log|bak|md|docx|ini)$)">
        Require all denied
    </FilesMatch>

    SetEnv COOPVOTE_MAIL 0
    SetEnv COOPVOTE_DEBUG 0
    ErrorLog ${APACHE_LOG_DIR}/coopvote-error.log
    CustomLog ${APACHE_LOG_DIR}/coopvote-access.log combined
</VirtualHost>
```

The extra deny rules protect internal files and documentation; share documentation as files rather than relying on public URLs. Do not block `assets/js/vendor/`, which contains browser libraries. Ensure a fronting proxy or static-file server applies equivalent restrictions rather than bypassing Apache.

Enable the site using your host's normal method, run `sudo apache2ctl configtest`, and reload Apache only after a successful check. On Debian-style installations a new file named `coopvote.conf` can be enabled with `sudo a2ensite coopvote.conf` followed by `sudo systemctl reload apache2`.

`AllowOverride` controls whether Apache reads `.htaccess` files. This project needs its authorization rules and rewrite directives honored; enabling only rewrite overrides is insufficient for all shipped rules. See the [Apache .htaccess documentation](https://httpd.apache.org/docs/2.4/howto/htaccess.html).

For production, provision a valid HTTPS certificate through the hosting provider or certificate-management tooling, apply the same application directory rules to the HTTPS virtual host, and redirect public HTTP traffic to HTTPS. Do not open real voting on the staging HTTP example. The application checks `$_SERVER['HTTPS']` when setting secure session cookies; configure a trusted reverse proxy/PHP handler accordingly and verify the cookie has the Secure flag.

The browser normally calls `api/index.php?r=<route>`. Pretty API URLs and legacy `.html` bookmarks also use rewrite rules. A PHP source download, directory listing, or accessible SQL dump is a failed deployment check.

### PHP settings

The bundled `.user.ini` sets `memory_limit=256M`, `post_max_size=128M`, and `max_execution_time=120`. `.user.ini` applies to CGI/FastCGI; for Apache module PHP, configure corresponding values in that runtime's `php.ini` or server configuration. The CLI has its own settings. See the [PHP .user.ini documentation](https://www.php.net/manual/en/configuration.file.per-user.php).

Keep `display_errors=Off` and `log_errors=On` in production. Configure a writable session directory and enough private temporary storage for backups. The application uses `Asia/Manila` and sets its database session timezone to the corresponding offset; keep the host clock synchronized.

## 4. Create and configure the database

### 4.1 Create an empty database and account

For a genuinely new installation, run the following through a privileged MariaDB administration session. Replace the password placeholder with a unique secret using your secure database administration tool. Do not use `IF NOT EXISTS` to conceal an existing database, and do not paste these commands over a live installation.

```sql
CREATE DATABASE coopvote CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'coopvote_app'@'localhost' IDENTIFIED BY 'REPLACE_WITH_UNIQUE_DATABASE_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON coopvote.* TO 'coopvote_app'@'localhost';
```

Use a separate administrator/migration account for schema import and changes. Runtime CRUD grants cover ordinary application records; backup routines/events/triggers or encryption metadata may require additional privileges. Have the DBA grant the specific privileges required by the selected feature rather than using the database root account in PHP.

### 4.2 Required private credentials

Create `/srv/coopvote/private/coopvote-db.php` with no BOM, HTML, closing PHP tag, or output:

```php
<?php
return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'coopvote',
    'user' => 'coopvote_app',
    'pass' => 'REPLACE_WITH_UNIQUE_DATABASE_PASSWORD',
];
```

The application requires this file in production even if environment variables override database fields. `COOPVOTE_CONFIG_FILE` does not replace this requirement. Do not add database credentials directly to `config/config.php` following older import notes.

### 4.3 Same-host Unix socket profile

Confirm the MariaDB socket path with the DBA, for example `/run/mysqld/mysqld.sock`. Create the following deployment-specific `public_html/config/security-local.php`:

```php
<?php
return [
    'environment' => 'production',
    'db_socket' => '/run/mysqld/mysqld.sock',
    'db_ssl_ca' => '',
    'db_ssl_cert' => '',
    'db_ssl_key' => '',
    'db_encryption_required' => false,
];
```

This profile matches the socket mode described in the existing connection report. It deliberately disables the application's mandatory table-encryption check, and does not provide database encryption at rest. Use it only when the deployment owner accepts that storage arrangement. A socket profile with encryption enforced is also possible when MariaDB is configured for it. Do not combine a socket with TLS certificate settings.

### 4.4 TCP/TLS profile

For a remote database or any TCP connection, omit the socket, keep mandatory encryption enabled, and configure a trusted CA file:

```php
<?php
return [
    'environment' => 'production',
    'db_socket' => '',
    'db_ssl_ca' => '/srv/coopvote/private/database-ca.crt',
    'db_encryption_required' => true,
];
```

Set the private credential file's host to the certificate-matching database hostname. The provider must configure TLS 1.2 or 1.3, appropriate host grants, and native MariaDB InnoDB encryption for every application table. The current verifier checks explicit `ENCRYPTED=YES`, an encryption scheme of 1, and a current key version of at least 1. It also needs access to the encryption metadata, normally involving the PROCESS privilege. Obtain any additional client certificate and key from the provider when mutual TLS is required.

Configure the database encryption plugin and managed keys before applying table encryption; this is provider-specific, not a PHP setting. The schema import alone does not enable table encryption. Have the DBA prepare and verify the imported tables before starting the application. See [MariaDB data-at-rest encryption](https://mariadb.com/docs/server/security/encryption/data-at-rest-encryption/data-at-rest-encryption-tde-fundamentals.md).

The application will not allow disabling mandatory table encryption for a TCP connection. Do not use `COOPVOTE_ENV=test` as a production workaround; test mode is restricted to a local `coopvote_test` database under CLI or the isolated PHP test server.

### Configuration precedence

`COOPVOTE_DB_HOST`, `COOPVOTE_DB_PORT`, `COOPVOTE_DB_NAME`, `COOPVOTE_DB_USER`, `COOPVOTE_DB_PASSWORD`, and `COOPVOTE_DB_SOCKET` override the corresponding settings. TLS variables use `COOPVOTE_DB_SSL_CA`, `COOPVOTE_DB_SSL_CERT`, and `COOPVOTE_DB_SSL_KEY`; encryption uses `COOPVOTE_DB_ENCRYPTION_REQUIRED`. An absolute `COOPVOTE_CONFIG_FILE` selects deployment settings instead of `config/security-local.php`, but the separate private credentials file is still loaded in production.

Ensure CLI and web PHP use the same intended production settings when operating the installation. A shell export does not necessarily reach PHP-FPM. Backup-key resolution has a separate rule described below.

## 5. Import the schema and create the first administrator

### 5.1 Import the empty schema

From the new target's `public_html` directory, import into the new empty database using the privileged deployment account. On a local host with MariaDB root socket authentication:

```bash
sudo mariadb coopvote < database/schema_only.sql
```

On managed hosting, select the new empty database in its import tool and import that same file. The current file creates 25 tables with constraints and no user or configuration rows, including candidate-photo storage. It does not create or select a database. Do not apply all numbered migrations after this current schema; its definitions already include the corresponding changes.

For older installations missing the relational constraints, migration 15 provides an additive upgrade. Inspect existing constraints and data first, back up, and validate on a restored copy before applying it once. See [the database constraint report](docs/DATABASE_CONSTRAINTS_REPORT_2026-11-09.md) for verification and rollback details.

For an existing installation predating candidate photo uploads, apply `database/migration_13_candidate_photos.sql` with the deployment account. It adds `candidate_photo_chunks` without changing existing candidate, member, election, or vote rows. Candidate photos are stored in database chunks and included in normal full-database backups. No writable upload folder is required. The application still needs `fileinfo` for validating the actual image content. Rehearse the migration on a separate database first.

### 5.2 Generate an administrator password hash

Choose a unique administrator password. In Bash, this example reads it without displaying it and sends it to PHP through standard input rather than a command-line argument:

```bash
read -r -s -p 'New administrator password: ' coopvote_admin_password
printf '\n'
printf '%s' "$coopvote_admin_password" | php -r '$p = stream_get_contents(STDIN); if (strlen($p) < 12 || strlen($p) > 72) { fwrite(STDERR, "Use 12–72 bytes for this initial password.\n"); exit(1); } echo password_hash($p, PASSWORD_BCRYPT), PHP_EOL;'
unset coopvote_admin_password
```

Use only the generated bcrypt hash in the next SQL block, never the plaintext password. If generation fails, stop and repeat with a suitable password. Keep shell tracing disabled while handling secrets.

### 5.3 Initialize roles, administrator profile, and approval settings

Run this only on the newly imported empty schema. Check that `roles`, `users`, and `governance_config` contain zero rows first. Replace the username, email, display name, and hash placeholders in a private SQL editor; escape apostrophes in SQL string values. The hash placeholder must be replaced by the full PHP-generated hash.

```sql
START TRANSACTION;
INSERT INTO roles (role_id, role_name, slug)
VALUES (1, 'Administrator', 'admin'), (3, 'Voter', 'voter');

INSERT INTO users
    (username, email, password_hash, role_id, status, email_verified_at)
VALUES
    ('setup_admin', 'REPLACE_WITH_ADMIN_EMAIL', 'REPLACE_WITH_BCRYPT_HASH',
     1, 'active', NOW());

SET @coopvote_admin_id = LAST_INSERT_ID();
INSERT INTO admin_profiles (user_id, display_name)
VALUES (@coopvote_admin_id, 'REPLACE_WITH_ADMIN_DISPLAY_NAME');

INSERT INTO governance_config (id, required_approvals) VALUES (1, 1);
COMMIT;
```

If any statement fails, issue `ROLLBACK` and resolve the problem rather than continuing to `COMMIT`. Do not rerun this bootstrap on a populated installation. No default shared password is supplied. After signing in, create any additional administrator accounts through Officer Management and set the intended approval requirement in Election Approvals. One approval is the initial setting because only one administrator exists.

Do not run `database/tests/seed.php` or import `database/test-data/` into production. Those are test fixtures, not installation data.

### Officer email verification upgrade

For existing installations without `officer_email_verifications`, apply `database/migration_16_officer_email_verification.sql` to the configured application database before using the updated Officer Management module. It adds one table without modifying existing accounts, votes or elections. Fresh installations using the current `database/schema_only.sql` already include it. Do not import the full schema over an existing database.

For a reviewed upgrade, this CLI command uses the existing private database configuration without putting credentials in the command:

```bash
php -r 'require "config/database.php"; require "includes/sql-import.php"; sql_import(db(), file_get_contents("database/migration_16_officer_email_verification.sql"));'
```

Set `COOPVOTE_PUBLIC_URL` in the PHP server environment to the canonical public HTTPS application URL, including its subdirectory if applicable, for example `https://coopvote.example.org`. This deployment defaults to `https://coopvote.ludom.app`. Verification links are never derived from request Host headers. Configure SMTP and install the existing mail dependencies before inviting officers. New officers verify their email and choose their password before activation; existing administrators retain access. See [officer verification usage and evidence](OFFICER_EMAIL_VERIFICATION_2026-11-12.md) for limits, recovery actions and untested real-mail delivery.

## 6. Configure signing keys, backups, and email

### Vote integrity key

For an existing installation or restored database, preserve its original signing key. Replacing it invalidates verification of existing signatures. For a fresh empty installation only, create a unique `config/integrity-key.php` that returns a random secret. The following command refuses to overwrite an existing file:

```bash
php -r 'umask(0077); $f = fopen("config/integrity-key.php", "x"); if (!$f) { exit(1); } fwrite($f, "<?php\nreturn " . var_export(bin2hex(random_bytes(32)), true) . ";\n"); fclose($f);'
```

If a copied release already contains a key, establish whether it belongs to the database being restored before proceeding. A fresh installation needs its own key, provisioned separately; do not reuse another installation's key. Adjust owner/group permissions so PHP can read the resulting file. Alternatively, provision the secret in `COOPVOTE_INTEGRITY_KEY`; the runtime reads that value first. Keep a protected copy with the recovery materials.

### Database backups

The administrator backup feature needs an executable `/usr/bin/mariadb-dump` or `/usr/bin/mysqldump`, `proc_open`, writable private temporary storage, and sufficient database privileges to dump the schema, data, routines, events, and triggers. All application tables must be InnoDB. Backup preparation is limited to 120 seconds; larger installations may need hosting-level backups. See [Database Backup and Activity Logs](docs/DATABASE_BACKUP_AND_ACTIVITY_LOGS.md).

The administrator SQL download and `tools/nfr19-backup.php` maintenance dump are **plaintext SQL**, not encrypted archives. Protect them with private storage and a separately validated encryption/transfer process. Web backup downloads are refused once recorded ballots or votes exist; use the reviewed maintenance backup procedure instead.

`includes/backup-crypto.php` provides standalone AES-256-GCM envelope functions, but the current download and maintenance-dump paths do not call them. Its optional key loader reads `COOPVOTE_BACKUP_KEY_FILE` or `backup_key_file` from `config/security-local.php`; setting those values alone does not encrypt a dump.

The privacy migration uses a separate AES-256-GCM rollback archive. `tools/nfr19-migrate.php` accepts explicit `--archive` and `--key` paths and creates a new random key during apply. Both paths must be new and outside the web root in private directories; do not pre-create or overwrite them. Retain the matching key for rollback and keep it separate from the archive. This rollback protection does not imply live database-at-rest encryption.

### Email

The actual source enables email unless `COOPVOTE_MAIL=0`. Keep that variable set in staging and during initial setup; the older email guide's statement that sending is off by default is obsolete. Apache module PHP can use the example `SetEnv`; for PHP-FPM, configure the worker's environment through the hosting settings/pool configuration and verify it reaches PHP.

The current SMTP values are constants in `config/config.php`. Configure the target's own SMTP account, sender, and password privately before enabling sending. Do not copy or publish another installation's mail credentials. The current implementation does not resolve these mail constants from `COOPVOTE_CONFIG_FILE` automatically. `docs/EMAIL_SETUP.md` gives background on the Gmail account process, but its enablement code and test-command references need the corrections listed in section 9.

Enable mail only after verifying account delivery with a controlled test address in staging. Inspect the private `logs/mail.log` for failures. Treat this log as sensitive: some mail-disabled paths can include verification codes. Restrict web access and file permissions.

## 7. Verify before opening the site to voters

1. Confirm Apache configuration syntax and PHP execution. Request `/login.php` and check that a rendered sign-in page appears rather than PHP source.
2. Request `/api/index.php`; it should return JSON. This entry-point response alone is not a database health test.
3. Request `/api/index.php?r=branding` and check that configuration/database initialization succeeds. Review server logs for any error code.
4. Sign in with the newly created administrator. Confirm the dashboard and Officer Management load. A successful CLI connection alone does not verify web-runtime settings or session storage.
5. Confirm private paths such as `/config/config.php`, `/database/schema_only.sql`, `/logs/mail.log`, and `/api/routes/admin.php` are denied without returning file contents. If a proxy serves static files, test through the public hostname too.
6. On a separate staging database, register synthetic voters, add a candidate and election, obtain approvals, submit a ballot, and verify the receipt. Never insert test ballots into a real election.
7. For a staging release-results check, use at least five synthetic participants and varied selections: privacy rules can withhold small or unanimous position results. Verify that submitted ballots cannot be changed or repeated.
8. Verify HTTPS, secure session cookies, logout, error logging, backup download, and a restore rehearsal into a different database before accepting production traffic.

Run the existing functional suite independently of production:

```bash
python3 database/tests/run.py
```

It needs Python 3, PHP with `pdo_mysql`, Node.js, MariaDB server/client binaries, and `mariadb-install-db` support files. It creates a temporary socket-only database, uses synthetic accounts, disables outgoing mail, and removes the temporary instance afterward. Optional browser checks need Playwright as described in [the test README](database/tests/README.md). The suite exercises the application on PHP's test server, not the target Apache/TLS configuration; perform both sets of checks.

## 8. Upgrade, move, or roll back an existing installation

Do not use the fresh-schema import or initial administrator SQL against an existing database.

1. Record the deployed source version and database/schema state. Arrange a maintenance period that prevents new ballots and administrative writes during any schema migration or final migration snapshot.
2. Capture a consistent database backup, the matching application files, private configuration, original integrity key, and any archive-encryption keys. Verify a restore in an isolated environment.
3. Rehearse the target release against a restored copy. Determine which migrations are actually missing; numbered files are not a blanket run list.
4. Treat `database/migration_12_voter_anonymity.sql` as a review reference only. The actual migration is `tools/nfr19-migrate.php`, which has backup, integrity, and rollback preconditions. Inspect its current command interface and rehearse it separately rather than executing the SQL reference by itself.
5. Deploy the reviewed files and required migrations, preserving deployment-specific credentials and keys. Verify API behavior, sign-in, schema, integrity, permissions, and backups before resuming voting.
6. For a host move, restore into a new empty database and update the target credentials/socket or certificates. Keep traffic and writes on only the intended installation when switching the hostname.

Rollback requires a compatible application/database/key set. If no writes have occurred since the maintenance snapshot, restore the validated set under maintenance and recheck it. If new votes or administrative changes exist, do not overwrite them with an old backup; plan a data-preserving recovery with the operator. MariaDB DDL can commit independently, so switching the code directory alone is not a database rollback.

## 9. Known documentation gaps and troubleshooting

These older references are not valid setup instructions for this checkout:

- `setup.php`, `SECURITY_DEPLOYMENT.txt`, `tools/encrypt_database.php`, and `tools/send_test_email.php` are referenced in older messages or notes but are absent.
- `database/READ_IMPORT.txt` refers to `coopvote_full.sql` and an export manifest that are absent. Obtain an operator-generated backup for restoration; do not substitute the test-data SQL export.
- There is no production account bootstrap utility. Section 5 supplies the manual initialization procedure.
- `docs/EMAIL_SETUP.md` describes an obsolete `MAIL_ENABLED` constant and an absent email test command. Current enablement uses the `COOPVOTE_MAIL` environment variable.
- The bundled sample security configuration contains example paths and does not by itself establish a working database connection.

| Error or symptom | Check |
| --- | --- |
| `CONFIG_FILE_UNREADABLE` / `CONFIG_FILE_INVALID` | Required sibling private file, optional alternate settings path, permissions, PHP syntax, and no output/BOM |
| `DB_CONFIG_INVALID` | Required values, socket/TLS combinations, production password, and supported encryption profile |
| `DB_DRIVER_MISSING` | `pdo_mysql` in the web runtime as well as CLI |
| `DB_AUTH_FAILED` / `DB_NOT_FOUND` | Database credentials, host grants, and selected database name |
| `DB_CONNECTION_FAILED` | MariaDB service and configured socket or TCP address |
| TLS error codes | CA readability, certificate hostname/expiry, mysqlnd/OpenSSL, and provider TLS settings |
| `DB_ENCRYPTION_REQUIRED` / `DB_ENCRYPTION_UNVERIFIABLE` | Provider table encryption and metadata privileges; schema import alone is insufficient |
| `DB_SCHEMA_MISMATCH` | Matching schema/migrations; use source checks rather than the absent setup page |
| `SESSION_STORAGE_UNAVAILABLE` / repeated login redirects | Writable session storage, cookie/HTTPS settings, and web-runtime permissions |
| No usable login after schema import | Initialize roles, active administrator/profile, and governance settings as in section 5 |
| API 404 or Apache 500 | PHP handler, rewrite module, AllowOverride, and server error log |
| Integrity failures after a move | Original signing key and matching database snapshot; do not generate a replacement key |
| Backup unavailable | Dump binary, `proc_open`, temporary storage, timeout, and database privileges |

Use private server logs to diagnose errors; do not enable production debug output for public users. Never paste database passwords, SMTP credentials, signing keys, or voter records into support reports.

## 10. Validation record

This guide was prepared from the application source on 27 October 2026. PHP/Bash examples were syntax-checked, the socket and TCP settings were checked with the application's configuration resolver, and both key-generation commands were tested for private permissions and refusal to overwrite existing keys. Local documentation links were checked.

The documented schema and administrator SQL were executed against a disposable socket-only MariaDB database. The check confirmed 25 tables, two roles, one active administrator and profile, and the initial approval setting; that administrator successfully signed in and loaded the dashboard through the real API with outgoing mail disabled. This focused test used grant checks disabled on the temporary server because the host lacked MariaDB installer support files. It does not validate the example production account grants or replace the full functional suite.

Apache/HTTPS deployment, actual database privileges, provider-managed database encryption, and mail delivery still require validation on the destination host. No live database or server configuration was changed to prepare this guide.
