# Supun Group Electronics ERP

A clean PHP 8.1 + MySQL ERP foundation for retail and wholesale electronics operations. No framework or Composer installation is required.

## Install on XAMPP

1. Copy `.env.example` to `.env` and update the database values.
2. Create a MySQL database named `supun_group_erp`.
3. Import `database/schema.sql`, then `database/seed.sql`.
4. Open the project URL in a browser.
5. Sign in with `admin@supungroup.lk` / `Admin@123` and change the password immediately.

## First-version roles

- Main Admin: unrestricted access (shared owner/system administrator/accountant account).
- Manager: customers, products, approvals, and permitted reports.
- Cashier: retail/wholesale cash and credit sales, receipts, invoices, permitted balances.
- Storekeeper: purchases, imports, serials, inventory, damages, approved adjustments.

The audit trail identifies shared-account activity as `Main Admin Account`, as accepted in the requirements.

