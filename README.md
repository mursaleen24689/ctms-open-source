# CTMS — Laboratory Management System

An open-source web application starter for managing laboratory samples, test data, report review, report delivery, user access, notifications, and operational records.

This repository is a software template. It does not include a production database, user accounts, customer reports, passwords, API keys, or organization-specific branding. Configure those for your own installation before use.

## Components

- `ctms_frontend/` — React and Vite user interface
- `ctms_server/api/` — PHP API endpoints
- `ctms_server/sql/` — optional database migrations for included modules
- `ctms_consult/` — optional, separate Consultancy application

## Requirements

- Node.js and npm compatible with the versions declared in `ctms_frontend/package.json`
- PHP 8.1 or later with PDO MySQL, OpenSSL, and the PHP extensions required by Composer packages
- MySQL or MariaDB
- Apache or another PHP-capable web server

## Local development

1. Install frontend dependencies: `cd ctms_frontend && npm install`.
2. Configure the PHP API and database using the sample configuration described in `ctms_server/README.md`.
3. Create an empty database and apply the SQL migrations in `ctms_server/sql/` in chronological order. Back up any existing database before applying migrations.
4. Start the API through Apache/PHP, then start the frontend with `npm run dev` from `ctms_frontend/`.
5. Set the frontend API base URL for your local setup as described in `ctms_frontend/README.md`.

Do not use sample credentials in a real deployment. Create a new administrator through your own controlled setup process, use a unique signing secret, and use a least-privilege database account.

## Configuration and security

- Keep database configuration and signing secrets outside the web root and outside version control.
- Copy `.env.example` or the relevant sample configuration and fill it locally. Never commit real `.env` files, access codes, database dumps, report files, or backups.
- Use HTTPS on shared networks. Restrict Apache access to the API and storage directories.
- Review `GO_LIVE_READINESS.md` before exposing an installation to users.
- Email, cloud backup, and consultancy integrations are optional and require separate credentials and configuration.

## Release history

See `CHANGELOG.md` for what changed in each release, including the security
and reliability fixes, and the upgrade notes for existing installations.

## Contributing

See `CONTRIBUTING.md` for the proposed development and disclosure process. Report security issues privately to the repository maintainers instead of posting exploit details publicly.

## License

This project is released under the MIT License. See `LICENSE`.
