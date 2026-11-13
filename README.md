# CoopVote

PHP and MariaDB cooperative voting application with administrator and voter interfaces.

## Hosting

Production is hosted on Ludom. This repository stores the application source. GitHub Pages is disabled, and the Cloudflare Worker is disconnected from automatic Git builds. Pushing code here does not deploy it to Ludom.

## Setup

This repository contains the contents of the `public_html` web root. Clone it into your deployment's `public_html` directory and follow [Installation and Deployment](INSTALLATION_DEPLOYMENT.md) for the database schema, private configuration, first administrator, encryption keys, and Apache/PHP setup.

**Repository configuration difference:** SMTP credentials are intentionally absent. Set `COOPVOTE_MAIL_USERNAME`, `COOPVOTE_MAIL_PASSWORD`, and optionally `COOPVOTE_MAIL_FROM_ADDRESS` in the PHP process environment. Mail is disabled unless `COOPVOTE_MAIL=1`. Do not hard-code credentials from a deployment into this repository.

Production database records, keys, uploaded personal files, logs, local configuration, and generated test evidence are excluded. Supply deployment-specific keys and credentials using the installation guide. Never replace an existing installation's integrity key: existing vote signatures depend on it.

## Source directories

- `admin/`, `voter/`: application pages
- `api/`: API entry point and handlers
- `assets/`: CSS, JavaScript, logos and import template
- `config/`, `includes/`: configuration loaders and shared functionality
- `database/`: schema, migrations, and isolated tests
- `tools/`: maintenance utilities
- `vendor/`: bundled PHP dependencies and their licenses
- `docs/`: technical documentation

## Tests

See [test instructions](database/tests/README.md). These use synthetic accounts and disposable databases. Do not run fixtures against production. Passing focused checks does not establish a full-system regression pass.

This export preserves the current source; it does not include the deployment's previous Git history.
