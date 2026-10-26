# PHP API setup

## Requirements

- PHP 8.1+ with PDO MySQL, OpenSSL, JSON, mbstring, fileinfo, and cURL where required
- MySQL or MariaDB
- Apache with PHP enabled
- Composer for PHP dependencies

## Private configuration

1. Create an empty database and a dedicated database account with only the required privileges.
2. Copy `config.example.php` to a private location outside the web root as `ctms_config.php`.
3. Set `CTMS_CONFIG_PATH` to the full path of that private file, or use the default private configuration directory beside the web root.
4. Fill in the database host, database name, database username, and database password. Set a unique, randomly generated `jwt_secret` for this installation.
5. Set `CTMS_TIMEZONE` to an IANA time-zone identifier for the deploying organization. UTC is the default.
6. Optionally set `CTMS_REPORT_LOGO` to a local PNG file that the PHP process can read.
7. Run `composer install --no-dev` for a production deployment, or `composer install` for development.

Never put real credentials in this repository, a public issue, a browser build, or a database export committed to source control.

## Database

The SQL files in `sql/` are feature migrations for compatible CTMS schemas. Apply them in filename order after backing up the database. They are not a full database dump or a universal base schema; inspect the API's expected base tables and columns before connecting a new database. Do not run migrations against production until they have been reviewed and tested on a copy.

## File storage and web server

Create private writable directories for report PDFs, Word files, and archives outside public static access. The application should run with the minimum permissions needed. Configure Apache so private configuration, logs, and storage cannot be downloaded directly.

Email and single-sign-on integrations are optional. Copy `email_config.example.php` to a private `email_config.php` outside the web root, set `CTMS_MAIL_CONFIG_PATH`, and configure all credentials server-side only.
