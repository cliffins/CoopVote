CoopVote — the test suite
=========================

Run everything:

    C:\xampp\php\php tests\run.php

Run one or more suites by number:

    C:\xampp\php\php tests\run.php 7          (security only)
    C:\xampp\php\php tests\run.php 1 2 3      (unit, module, integration)

Write a machine-readable report as well:

    C:\xampp\php\php tests\run.php --json     -> tests\reports\results.json

Focused candidate election checks (no MySQL, server, or login needed):

    C:\xampp\php\php tests\candidate-election.php
    node tests\candidate-election.js

The PHP check runs the candidate save handler against SQLite in memory.
The JavaScript check exercises the form with sample elections and positions.

Focused voter profile checks (also isolated from real accounts):

    C:\xampp\php\php tests\profile-settings.php
    node tests\profile-settings.js

Focused password change checks (SQLite memory and simulated requests only):

    C:\xampp\php\php tests\password-change.php
    node tests\password-change.js

These check wrong-current-password recovery, stored password hashes, retry,
and the shared API client's distinction between validation and session expiry.

Focused shared input validation checks (no live data or network):

    C:\xampp\php\php tests\input-validation.php
    node tests\input-validation.js

Focused admin save correctness (SQLite memory and simulated forms):

    C:\xampp\php\php tests\voter-save.php
    node tests\save-forms.js

Responsive viewport behavior (simulated keyboard/rotation/zoom events):

    node tests\viewport.js

This checks the viewport script. It does not replace visual testing on devices.


The twelve suites
-----------------
    1   Unit                       pure functions: dates, names, statuses
    2   Component / module         auth, one-time codes, audit, queries
    3   Integration                routing, sessions, guards, cross-module writes
    4   System                     a whole election, creation to released result
    5   Acceptance (UAT)           the scenarios the cooperative asked for
    6   Performance                response times, 16 members voting at once
    7   Security                   injection, XSS, authorisation, file exposure
    8   Usability                  labels, wording, phone widths, screen readers
    9   Maintainability            parses, no leftovers, no dead code, size
   10   Portability                renamed folder, case sensitivity, Apache 2.2
   11   Regression                 one check per fault this system has had
   12   Pilot / deployment         fresh install, dump safety, go-live settings


What it will not touch
----------------------
The suite never opens the live database. Everything that needs data runs
against "coopvote_test", which is dropped and rebuilt from
database\schema_only.sql at the start of every run.

Three things enforce that, because getting it wrong once is enough:

  * run.php sets COOPVOTE_DB before any configuration is loaded, and stops
    if DB_NAME does not come back as coopvote_test.
  * The disposable web servers are handed that variable explicitly, and
    start() asks each one which database it is on. If any answers with
    anything else, the run aborts before a single test executes.
  * tests\lib\testdb.php refuses to drop or rebuild any database whose name
    is not coopvote_test.

Mail is switched off for the run (COOPVOTE_MAIL=0), so a test that
registers a member or casts a ballot does not post real email through the
cooperative's Gmail account -- and does not wait five seconds for it.


What it needs
-------------
    PHP 8.0+ with pdo_mysql and curl   (curl is used by the tests only)
    MySQL or MariaDB running
    Apache serving the project         (suites 7 and 12 check .htaccess
                                        rules, which only Apache applies)
    Node.js and Chrome or Edge         (suites 8 and 11 drive a browser;
                                        without them those checks are
                                        skipped, not failed)

Nothing has to be installed with Composer. The harness is about a hundred
lines in tests\lib\harness.php.


Ports
-----
The run starts four throwaway PHP servers on 8091-8094, and suite 10 uses
8110. If a previous run was interrupted they may still be listening; the
suite says so and stops rather than testing against stale code. Close them
in Task Manager (php.exe) and run again.


Reading a failure
-----------------
Each line names the behaviour, not the assertion, so a failure reads as a
statement about the system:

    FAIL  a member who did not vote sees no count
          expected 0, got 1

The regression suite goes further and names the original report, so a
returning fault is recognisable:

    R-09  "the election result must be seen by the voters who voted only"
