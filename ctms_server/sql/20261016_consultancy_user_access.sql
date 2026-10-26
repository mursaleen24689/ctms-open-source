-- Per-user Consultancy permission and Consultancy-specific role.
-- Existing accounts keep the access they already had; new accounts are denied
-- by default in the Users screen unless an administrator enables them.
ALTER TABLE users
  ADD COLUMN consultancy_access TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN consultancy_role VARCHAR(24) NOT NULL DEFAULT 'viewer';

-- Preserve today's role mapping for existing users as the starting role.
UPDATE users
SET consultancy_role = CASE LOWER(role)
  WHEN 'superadmin' THEN 'superadmin'
  WHEN 'admin' THEN 'admin'
  WHEN 'hod' THEN 'professor'
  WHEN 'chairman' THEN 'professor'
  WHEN 'pa' THEN 'admin'
  WHEN 'deo' THEN 'technician'
  WHEN 'technician' THEN 'technician'
  WHEN 'tester' THEN 'technician'
  WHEN 'mapping' THEN 'reception'
  WHEN 'reception' THEN 'reception'
  ELSE 'viewer'
END;
