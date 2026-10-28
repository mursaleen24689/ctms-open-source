# Changelog

All notable changes to this repository are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.2.0] - 2026-10-28

This release brings the public copy up to date with the security and
reliability work done on the internal CTMS installation. No personal,
institutional or client data is included; the sample institution names and
generic configuration paths from 0.1.0 are unchanged.

### Added

- Consultancy Audit Trail screen (`ctms_consult/audit.php`). The navigation
  entry existed but pointed at a missing page. Filtering, paging and a
  before/after detail view are included; access is limited to the same roles
  that see the menu entry.
- `db_port` support in the CTMS API and the Consultancy database router, so a
  MySQL/MariaDB instance on a non-default port can be used without editing
  source files.

### Security

- API responses no longer include PHP error output. Previously a database
  exception could return a stack trace with absolute paths and SQL to the
  caller; errors now go to the server log only (SEC-014). The API
  `.htaccess` also used a module guard (`mod_php8.c`) that never matches on
  stock XAMPP builds, so its `display_errors Off` directive was inert;
  it now uses `php_module`.
- Finance data is restricted centrally in `invoice.php`: reads require a
  management role and writes require a management role, so a newly added
  resource cannot be exposed by omission.
- Only a CTMS Super Administrator can modify a Super Administrator account
  (deactivate, rename, change role or reset password).
- Consultancy invoice authoring is an explicit assignment rather than a
  property of a role. A Super Administrator grants or withdraws it on the
  CTMS Users screen; Consultancy administrators keep the rest of the
  workflow but no longer raise invoices automatically.
- MFA secrets are encrypted with a dedicated key file outside the web root
  instead of a key derived from `JWT_SECRET`. Recovery codes use the same
  separated key material. Existing enrollments and codes are re-hashed or
  re-encrypted transparently the first time they are used, so rotating the
  JWT secret no longer locks users out of their authenticators.
- The printed report verification code fails closed: with no configured
  verification secret it derives a per-deployment key from `JWT_SECRET`
  instead of a publicly known default.
- The Consultancy sign-in hand-off page refuses to forward a single-use
  ticket to another hostname, or from HTTPS down to HTTP.
- The report-template API now uses the columns that exist in the schema
  (`template_id`, `template_name`, `format_family`, `is_active`, …). It also
  validates its JSON body and logs database errors instead of returning the
  driver message.

### Fixed

- Opening, refreshing or previewing a report no longer consumes a report
  series number. The number is allocated and the report is registered only
  when the user prints or downloads in Word, using a signed four-hour token.
  The archive ZIP and email helpers request the same explicit issue action,
  and the archived PDF is only written once the report has a number.
- Cheque serial numbers are allocated from a per-type, per-date counter row
  inside a transaction, so two clerks saving at the same moment can no longer
  receive the same serial. Unknown cheque types are normalised to `other`.
- Saving test results no longer fails after the data is committed: the audit
  entry is written after the commit in its own guard, and the request
  boundary catches `Throwable` rather than only `PDOException`.
- The technical-review switch in the report workflow settings is stored with
  its actual value. It was previously hard-coded to "on" regardless of the
  choice, and the response now reports the saved state.
- CLI scripts can include the API bootstrap without a request context
  (`OPTIONS` detection no longer warns or exits).

### Notes

- This repository remains a software template: it has no database dump, user
  accounts, report archives or branding. Configure your own installation as
  described in `README.md` and `GO_LIVE_READINESS.md`.
- Upgrading an existing 0.1.0 installation: back up the database and the
  private configuration directory (including the new MFA key file) before
  deploying. No destructive migration is required; the cheque counter table
  is created on first use.

## [0.1.0] - 2026-10-26

- Initial public release: sanitized CTMS API (`ctms_server`), React front end
  (`ctms_frontend`) and optional Consultancy application (`ctms_consult`).
