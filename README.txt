CoopVote — Cooperative Online Voting System
===========================================
A working PHP + MySQL application: member registration, administrator
approval, secret-ballot voting, live monitoring, results and audit logs.

Stack: PHP 8.2 · MariaDB 10.4 · Apache (XAMPP) · vanilla JS frontend


-----------------------------------------------------------------
1. GETTING STARTED
-----------------------------------------------------------------
1. Start **Apache** and **MySQL** from the XAMPP Control Panel.
2. Open  http://localhost/CoopVote/login.html
3. Sign in with one of the accounts below.

  ⚠  DO NOT open these pages with VS Code's "Live Server"
     (http://127.0.0.1:5500/…) or by double-clicking the .html file
     (file:///…). Those serve files only — they cannot run PHP, so
     every login and every save fails with "HTTP 405".

     The address must start with  http://localhost/CoopVote/

     If you get this wrong the app now tells you, and shows a red bar
     with the correct link, instead of failing silently.

  If a change does not seem to appear, hard-reload with Ctrl+F5 —
  browsers cache these pages aggressively.

The database `coopvote` already exists and is populated. If you ever
need to rebuild it from scratch:

    C:\xampp\mysql\bin\mysql -u root < database\coopvote_full.sql

  database\coopvote_full.sql ....... complete database (schema + data)
  database\schema_only.sql ......... table definitions only
  database\backups\ ................ backup taken before this work started


-----------------------------------------------------------------
2. LOGIN CREDENTIALS
-----------------------------------------------------------------
The original password hashes could not be recovered (bcrypt is one-way),
so every demo account was given a known password:

  Username    Password        Role               Lands on
  ---------   -------------   ----------------   ---------------------
  admin       Admin@123       Administrator      admin/dashboard.html
  voter1      Voter@123       Voter / member     voter/dashboard.html
  voter2 … voter8            Voter@123           Voter / member

The `officer` and `auditor` accounts still exist and still work, but the
Election Officer and Auditor roles they used to hold are gone, so both are
administrators now. Their old passwords are unchanged.

You may sign in with a **username, e-mail address or member number**
(for example `COOP-0001`).

To reset these passwords again:
    C:\xampp\php\php.exe database\reset_demo_passwords.php


-----------------------------------------------------------------
3. WHAT EACH ROLE CAN DO
-----------------------------------------------------------------
There are two:

  admin     full access — members, candidates, elections, security
  voter     own dashboard, candidate list, ballot, results, profile

Election Officer and Auditor were removed. Officer was indistinguishable
from admin in practice, and nobody was using the read-only Auditor. The two
accounts that held them were made administrators rather than deleted, so
the elections they created and their entries in the audit trail still name
who did them.


-----------------------------------------------------------------
4. TRY THE SYSTEM
-----------------------------------------------------------------
Cast a vote
  1. Sign in as `voter1` / `Voter@123`
  2. Cast My Vote → pick one candidate per position → Submit
  3. You receive a receipt code (e.g. CV-M73JNZTY)
  4. Going back to the ballot now shows "Vote Already Submitted"

Release the results
  5. Sign in as `admin` / `Admin@123`
  6. Election Management → Release Results
  7. Members can now see the tallies on their Results page
     (before release the page says "Results Pending" — tallies are
      never sent to the browser until they are released)
  8. Changed your mind? The same card offers Unpublish, which takes the
     tallies back out of the members' Results page.

Register a new member
  8. Sign out → "Create an account" on the login page
  9. Fill the form (ID photos optional) → you get a member number
 10. The new account CANNOT sign in yet — sign in as `admin`,
     open Voter Management and click Approve. Now it can.

Start over
    C:\xampp\php\php.exe database\reset_election_data.php
  clears votes, ballots and the results-release flag so you can run
  the whole election again. Members and candidates are kept.


-----------------------------------------------------------------
5. FOLDER STRUCTURE
-----------------------------------------------------------------
CoopVote/
├── login.html               ← entry point
├── register.html            ← membership registration
│
├── config/
│   ├── config.php           ← database credentials, paths, settings
│   └── database.php         ← PDO connection
│
├── includes/
│   ├── helpers.php          ← JSON responses, formatting, validation
│   └── auth.php             ← sessions, role guards, audit logging
│
├── api/
│   ├── index.php            ← front controller (all endpoints)
│   ├── .htaccess            ← optional pretty URLs
│   └── routes/
│       ├── auth.php         ← login, logout, register, me
│       ├── voter.php        ← dashboard, ballot, vote, results, profile
│       └── admin.php        ← members, candidates, elections, security
│
├── assets/js/app.js         ← shared frontend runtime (API + guards)
│
├── admin/                   ← 8 back-office pages
├── voter/                   ← 6 member pages
├── uploads/ids/             ← uploaded ID photographs
└── database/                ← SQL dumps and maintenance scripts


-----------------------------------------------------------------
6. API
-----------------------------------------------------------------
Every endpoint goes through one file, so no Apache rewrite rules are
needed:

    api/index.php?r=<route>

Open  http://localhost/CoopVote/api/index.php  in a browser to see the
full route list. Authentication uses a PHP session cookie (HttpOnly),
so the pages simply call `CoopVote.api('voter/dashboard')`.

  auth/login  auth/logout  auth/me  auth/register
  voter/dashboard  voter/election  voter/candidates  voter/ballot
  voter/vote  voter/results  voter/profile  voter/change-password
  dashboard  voters  candidates  elections  positions
  monitor  results  reports  security  announcements

CSV exports:  voters/export · candidates/export ·
              results/export&election=1 · security/logs/export


-----------------------------------------------------------------
7. HOW THE SECRET BALLOT WORKS
-----------------------------------------------------------------
This is the most important design decision in the schema, and it was
already correct in your database — the backend preserves it:

  * `votes`   stores election / position / candidate ONLY.
              There is no user_id, so no query can reveal how any
              individual voted.
  * `ballots` records THAT a member voted (with a receipt code and
              timestamp), never WHAT they voted for.

Because of this, the vote-monitoring and security pages show
"Complete ballot" in the position column rather than a candidate name.
That is deliberate, not a missing feature.

Double voting is prevented by a UNIQUE key on (user_id, election_id)
plus a conditional UPDATE inside a transaction, so two simultaneous
submissions cannot both succeed.


-----------------------------------------------------------------
8. SECURITY NOTES
-----------------------------------------------------------------
  * Passwords hashed with bcrypt (`password_hash`)
  * All SQL uses prepared statements (no string concatenation)
  * Sessions are HttpOnly, SameSite=Lax, and the session ID is
    regenerated on login
  * Accounts lock for 15 minutes after 5 failed sign-in attempts
  * Uploaded ID photos are validated by sniffed MIME type, not by the
    file name, and are renamed before being stored
  * Administrators can block IP addresses; blocked addresses are
    refused at the router
  * Every significant action is written to `audit_logs`

Before putting this on a public server:
  - set `DEBUG` to false in config/config.php
  - give MySQL a real password and update config/config.php
  - delete database/reset_demo_passwords.php and change all passwords
  - serve the site over HTTPS


-----------------------------------------------------------------
9. E-MAIL (Gmail)
-----------------------------------------------------------------
CoopVote can e-mail members — all messages in English:

  * registration received       (to the applicant)
  * membership approved         (to the applicant)
  * membership rejected         (to the applicant)
  * voting receipt              (to the voter, after casting a ballot)

It is switched OFF until you configure it, so nothing is sent by
accident. Full instructions, including how to make the Gmail App
Password:

    docs\EMAIL_SETUP.md

Short version:
  1. Turn on 2-Step Verification on your Google account
  2. Create an App Password at myaccount.google.com/apppasswords
  3. Put your Gmail address and that 16-character password into
     config\config.php  (MAIL_USERNAME / MAIL_PASSWORD)
  4. Test it:
        C:\xampp\php\php.exe tools\send_test_email.php you@example.com
  5. Only once that succeeds, set  MAIL_ENABLED = true

Every attempt is logged to  logs\mail.log

A failed e-mail never breaks anything: a vote is still recorded and a
registration still created even if Gmail is unreachable.


-----------------------------------------------------------------
10. KNOWN LIMITATIONS
-----------------------------------------------------------------
  * The `otp_tokens` and `password_resets` tables exist but are not
    used. The original two-step e-mail OTP login was removed because
    the login page has no OTP screen. Now that the mailer works, it
    could be added back if you want that second factor.
  * "Forgot password?" tells the member to contact the office; there
    is no self-service reset flow yet.
  * Report exports produce CSV (which opens in Excel). PDF export is
    not implemented.
  * A candidate must already exist as a cooperative member. Add the
    person under Voter Management first, then nominate them.
