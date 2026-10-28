# Offline MFA and login protection

CTMS uses RFC 6238 time-based one-time passwords (TOTP). The authenticator app computes codes locally from the enrollment secret and device clock, so code generation and verification do not need internet access. The server and the user's phone should have accurate clocks; a few seconds of drift is accepted.

## First sign-in after deployment

1. Sign in with the usual CTMS account, password, and CAPTCHA.
2. On first use, scan the QR code in a TOTP authenticator app. If scanning is unavailable, enter the displayed setup key manually.
3. Enter the current six-digit code to confirm enrollment.
4. Save the displayed recovery codes in a private, protected place. Each code is single-use and is displayed only once.

Subsequent sign-ins require the password, CAPTCHA, and a current authenticator code. A recovery code can be used once instead of the current TOTP code.
TOTP time steps are recorded after use to reject replay of the same authenticator code.

## Administrator recovery and alerts

The Super Administrator can use **Reset MFA** in User Management for another user. The reset invalidates that user's active sessions; that person enrolls a new authenticator at the next sign-in. The Super Administrator should retain their own recovery codes or arrange for a second Super Administrator account.

Administrators can open **Audit Trail** to see local failed-login events and patterns. The Audit Trail navigation item shows a badge when the server detects repeated attempts against an account or from an address. Alerts and logs are stored in the local CTMS database; email is not required.

Account cooldowns increase after repeated failures, cap at 15 minutes, and clear after one hour. A separate IP request limit remains as a backstop. No account is permanently locked by this feature.

## Database setup

The API creates its additive MFA tables on first use if its MySQL account has `CREATE TABLE` permission. Otherwise import `sql/20261021_offline_mfa.sql` into `ctms_db` once. Do not edit or copy the MFA secret values manually.

MFA secrets are encrypted with a dedicated key stored outside the web root (by default `ctms_config/mfa_secret.key`, or the path in `CTMS_MFA_KEY_FILE`). Back that file up together with the database. It is no longer derived from `JWT_SECRET`, so rotating the JWT secret does not affect enrolled authenticators. Enrollments created before the key was separated are re-encrypted automatically the first time they are used. Losing the key file (or restoring the database without it) makes existing enrollments unreadable and requires users to enroll again.

The MFA setup QR code and sign-in codes are sensitive. For other computers connecting over LAN or Eduroam, serve CTMS over **HTTPS using a certificate trusted by those computers**. Offline does not mean unencrypted: plain HTTP exposes passwords and enrollment secrets to anyone who can observe that network traffic.
