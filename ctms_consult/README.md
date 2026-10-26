# Consultancy module

This PHP application provides the optional Consultancy section. It can use a separate database or a configured shared database with its own prefixed tables.

## Install

1. Install the PHP dependencies with Composer.
2. Create a private `ctms_consult_config.php` outside the web root based on `config.example.php`.
3. Set `CTMS_CONSULT_CONFIG_PATH` to that private file, or use the default private configuration directory beside the web root.
4. Choose a separate Consultancy database unless you have reviewed the shared-database option and its table prefixes.
5. Import `sql/01_schema.sql` and, if desired, `sql/02_seed_standards.sql` into the configured database.
6. Configure writable upload and report storage outside public static access.
7. Set a unique application secret and secure session settings. Configure HTTPS for shared or production access.
8. Configure mail separately with `CTMS_MAIL_CONFIG_PATH`. Leave mail disabled until credentials are installed privately.

The repository contains no live user database, uploaded images, generated reports, archives, vendor packages, or institution logo. The report logo can be configured with `CTMS_CONSULT_REPORT_LOGO`.

## CTMS single sign-on

Single sign-on is optional and requires the main CTMS API to be configured with the corresponding Consultancy ticket settings. For standalone development, use the Consultancy login flow and do not expose sign-in tickets in logs or screenshots.

## Data and security

Use synthetic data for development. Do not commit database contents, access codes, user exports, uploads, backups, or local configuration. Review `../GO_LIVE_READINESS.md` before deployment.
