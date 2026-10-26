-- Optional lot-level scope layered on the existing CTMS role.
ALTER TABLE users
  ADD COLUMN lot_access_restricted TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE user_lot_access (
  user_id INT NOT NULL,
  lot_id INT NOT NULL,
  access_level ENUM('view','edit','manage') NOT NULL DEFAULT 'view',
  granted_by INT NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, lot_id),
  KEY idx_user_lot_access_lot (lot_id),
  CONSTRAINT fk_user_lot_access_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_user_lot_access_lot FOREIGN KEY (lot_id) REFERENCES lots(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_user_lot_access_grantor FOREIGN KEY (granted_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
