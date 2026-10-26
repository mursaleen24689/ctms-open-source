-- Durable staff announcements, lot-created alerts, and read receipts.
-- HOD and Chairman exclusion is enforced by the API recipient selection.
CREATE TABLE IF NOT EXISTS ctms_announcements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(30) NOT NULL DEFAULT 'broadcast',
  title VARCHAR(200) NOT NULL,
  message TEXT NOT NULL,
  audience_roles TEXT NOT NULL,
  lot_id INT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_ctms_announcements_created (created_at),
  INDEX idx_ctms_announcements_lot (lot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ctms_announcement_receipts (
  announcement_id BIGINT UNSIGNED NOT NULL,
  user_id INT NOT NULL,
  delivered_at DATETIME NOT NULL,
  read_at DATETIME NULL,
  PRIMARY KEY (announcement_id,user_id),
  INDEX idx_ctms_announcement_user_unread (user_id,read_at,delivered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
