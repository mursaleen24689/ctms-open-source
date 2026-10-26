-- Encrypted, append-only team chat shared by CTMS and Consultancy users.
-- Message bodies and encrypted per-user key envelopes are ciphertext only.
CREATE TABLE IF NOT EXISTS ctms_chat_identities (
  user_id INT NOT NULL PRIMARY KEY,
  key_id CHAR(64) NOT NULL,
  public_key MEDIUMTEXT NOT NULL,
  encrypted_private_backup MEDIUMTEXT NOT NULL,
  registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ctms_chat_identity_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ctms_chat_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  room_key VARCHAR(32) NOT NULL DEFAULT 'general',
  sender_id INT NOT NULL,
  sender_name_snapshot VARCHAR(160) NOT NULL,
  ciphertext MEDIUMTEXT NOT NULL,
  nonce VARCHAR(32) NOT NULL,
  envelopes_json MEDIUMTEXT NOT NULL,
  ciphertext_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ctms_chat_room_id (room_key, id),
  KEY idx_ctms_chat_sender_created (sender_id, created_at),
  CONSTRAINT fk_ctms_chat_message_sender FOREIGN KEY (sender_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ctms_chat_hidden_messages (
  user_id INT NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  hidden_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, message_id),
  CONSTRAINT fk_ctms_chat_hidden_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ctms_chat_hidden_message FOREIGN KEY (message_id) REFERENCES ctms_chat_messages(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ctms_chat_audit_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  action VARCHAR(32) NOT NULL,
  message_id BIGINT UNSIGNED NULL,
  details_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ctms_chat_audit_created (created_at),
  KEY idx_ctms_chat_audit_user (user_id, created_at),
  CONSTRAINT fk_ctms_chat_audit_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_ctms_chat_audit_message FOREIGN KEY (message_id) REFERENCES ctms_chat_messages(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
