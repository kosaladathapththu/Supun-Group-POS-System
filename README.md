# Supun Group Retail & Wholesale POS

PHP/MySQL point-of-sale and inventory system designed for XAMPP.

## Local deployment (XAMPP)

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open `http://localhost/phpmyadmin` and choose **Import**.
3. Import `Supun_Group_POS/database/supun_group_pos.sql`.
4. Keep this repository under XAMPP's `htdocs` directory.
5. Browse to `http://localhost/Supun_Group_POS/Supun-Group-POS-System/Supun_Group_POS/` (adjust the URL if your folder layout differs).
6. Sign in with `admin` / `password` or `cashier` / `password`, then change those passwords from Staff / Users.

Database connection settings are in `Supun_Group_POS/db.php`. The default is MySQL on `127.0.0.1:3306`, user `root`, blank password, database `supun_group_pos`.

## Production deployment

Use PHP 8.1+, MySQL 8/MariaDB 10.5+, HTTPS, a dedicated database user, and a non-empty database password. Import the same SQL file, update `db.php`, remove or protect the account-creation helper scripts, and point the web root at `Supun_Group_POS`.

The included schema provides users, categories, stock-aware products, retail and wholesale pricing, sales, line items, expenses, starter products, and inventory triggers.
