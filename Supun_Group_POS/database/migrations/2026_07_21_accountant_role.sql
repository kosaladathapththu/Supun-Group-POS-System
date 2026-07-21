-- Replace the former manager role with accountant.
ALTER TABLE users MODIFY role ENUM('admin','manager','accountant','cashier') NOT NULL DEFAULT 'cashier';
UPDATE users SET role = 'accountant' WHERE role = 'manager';
ALTER TABLE users MODIFY role ENUM('admin','accountant','cashier') NOT NULL DEFAULT 'cashier';
UPDATE users
SET full_name = 'Store Accountant', username = 'accountant'
WHERE username = 'manager'
  AND role = 'accountant'
  AND NOT EXISTS (
      SELECT 1 FROM (SELECT username FROM users) existing_users
      WHERE existing_users.username = 'accountant'
  );
