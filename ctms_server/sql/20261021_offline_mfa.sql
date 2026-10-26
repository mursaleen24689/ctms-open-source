-- Optional one-time provisioning for offline TOTP MFA and login-security controls.
-- Runtime code also creates these tables automatically when the CTMS DB account
-- has CREATE TABLE permission. This script contains no user secrets or credentials.

CREATE TABLE IF NOT EXISTS ctms_user_mfa (
    user_id INT NOT NULL PRIMARY KEY,
    secret_ciphertext TEXT NOT NULL,
    enabled_at DATETIME NULL,
    last_counter BIGINT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ctms_mfa_recovery_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ctms_mfa_recovery_user (user_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ctms_login_challenges (
    ticket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    user_id INT NOT NULL,
    purpose VARCHAR(16) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ctms_login_challenges_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ctms_auth_failures (
    account_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started DATETIME NOT NULL,
    blocked_until DATETIME NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_ctms_auth_failures_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ctms_auth_security_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    identifier VARCHAR(190) NOT NULL DEFAULT '',
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    event_type VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ctms_auth_events_time (created_at),
    INDEX idx_ctms_auth_events_ip_time (ip_address, created_at),
    INDEX idx_ctms_auth_events_identifier_time (identifier, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
