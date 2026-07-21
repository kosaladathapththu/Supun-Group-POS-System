-- Replace the former manager role with accountant.
ALTER TABLE users MODIFY role ENUM('admin','manager','accountant','cashier') NOT NULL DEFAULT 'cashier';
UPDATE users SET role = 'accountant' WHERE role = 'manager';
ALTER TABLE users MODIFY role ENUM('admin','accountant','cashier') NOT NULL DEFAULT 'cashier';
