-- Invoice creation is a separately assignable Consultancy permission.
-- Administrators retain full access; reception starts enabled for existing accounts.
ALTER TABLE users
  ADD COLUMN consultancy_can_create_invoice TINYINT(1) NOT NULL DEFAULT 0 AFTER consultancy_role;

UPDATE users
   SET consultancy_can_create_invoice = CASE
     WHEN role IN ('admin', 'superadmin') OR consultancy_role IN ('admin', 'superadmin', 'reception') THEN 1
     ELSE 0
   END;
