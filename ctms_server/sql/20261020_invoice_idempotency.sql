-- Makes invoice creation safe to retry after a browser or network timeout.
-- Apply once to ctms_db before deploying the matching invoice.php change.
CREATE TABLE IF NOT EXISTS api_idempotency_keys (
  user_id INT NOT NULL,
  idempotency_key VARCHAR(80) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  response_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, idempotency_key),
  KEY idx_api_idempotency_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
