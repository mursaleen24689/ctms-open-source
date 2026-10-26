# Consultancy mail setup

Mail is optional. Provide SMTP settings in a private PHP configuration file outside the web root and set `CTMS_MAIL_CONFIG_PATH` to it. Use the configuration shape expected by `inc/mailer.php`; never put a real mail password in this repository or frontend code.

Use a dedicated sending account, restrict its permissions, and test delivery using a synthetic report and an address you control. Disable mail until the private settings are present. Rotate credentials if they are ever committed or shared.
