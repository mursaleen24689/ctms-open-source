# Email configuration

Email delivery is optional. Configure it using the mail provider approved by your organization. Never commit real SMTP credentials.

1. Create a dedicated mail account or application credential for the CTMS service.
2. Copy `ctms_server/email_config.example.php` to a private `email_config.php` outside the web root and set `CTMS_MAIL_CONFIG_PATH` to that file. Populate the SMTP host, port, encryption mode, sender address, and username from your server's protected environment.
3. Keep the SMTP password in the server's secret store or protected local configuration; do not put it in frontend code.
4. Send a test message to an address you control and verify delivery, error handling, and audit records.
5. Restrict the sender identity and outgoing network access to the minimum needed.

Use placeholders in local examples, such as `MAIL_HOST=smtp.example.org`, `MAIL_USERNAME=ctms@example.org`, and `MAIL_PASSWORD=replace-locally`. These values are examples only and will not deliver mail.
