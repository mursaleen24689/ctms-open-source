# Deployment readiness checklist

Use this checklist for each installation. The sample project is not production-ready until it has been configured, reviewed, and tested for the deploying organization.

## Before deployment

- Replace all example institution, department, email-domain, logo, and contact information.
- Create a production database and a dedicated database user with only the required permissions.
- Store database credentials and signing secrets outside the web root. Generate a unique random signing secret for every environment.
- Confirm `.env`, local configuration, access codes, database exports, logs, and generated reports are ignored by Git.
- Configure HTTPS, Apache/PHP restrictions, upload size/type limits, storage permissions, and backups.
- Create real user accounts through an administrator-controlled process. Remove any demo accounts.
- Configure report approval, lot permissions, invoice permissions, mail delivery, and integrations for local policy.
- Review third-party dependency licenses and update dependencies through their package managers.

## Before go-live

- Test authentication, MFA recovery, rate limiting, authorization boundaries, and sign-out.
- Test each role against permitted and denied actions.
- Test sample intake, results entry, approval, report generation, and archive downloads using synthetic data.
- Verify PDFs and Word documents on the supported browsers and operating systems.
- Test restore from a recent backup in an isolated environment.
- Confirm log retention, security alerts, incident contacts, and account recovery procedures.
- Remove demo records and test uploads from the production database and storage.

## After deployment

- Keep PHP, Apache, MySQL/MariaDB, Node build dependencies, and operating systems updated.
- Monitor failed logins, unexpected role changes, failed backups, and report delivery errors.
- Rotate any credential that may have been exposed. Do not put credentials in issues, screenshots, chat, or source control.
