# Supun Group POS

Retail and wholesale point-of-sale system built with PHP, MySQL, HTML, CSS, and JavaScript. It runs locally through XAMPP.

## Start here

1. Start Apache and MySQL in XAMPP.
2. Configure the database connection in `db.php`.
3. Open `/Supun_Group_POS/login.php`.
4. Use `pos.php` for checkout and `dashboard.php` for management.

## Main modules

| Module                | Main files                                         | Purpose                                                   |
| --------------------- | -------------------------------------------------- | --------------------------------------------------------- |
| Authentication        | `login.php`, `logout.php`                          | User login and session lifecycle                          |
| POS checkout          | `pos.php`, `save_order.php`                        | Orders, cart, pricing, checkout, credit, and installments |
| Account credit        | `advance_payments.php`, `print_advance.php`        | Customer money held without an order                      |
| Installments          | `installment_payments.php`, `advance_payments.php` | Payments tied to a specific order                         |
| Billing               | `print_bill.php`, `print_account_statement.php`    | Final invoices and customer statements                    |
| Management            | `dashboard.php`, `admin/`                          | Reports, products, users, expenses, and inventory         |
| Shared payment schema | `includes/advance_accounts.php`                    | Creates and reconciles credit/installment records         |

See [docs/DEVELOPER_GUIDE.md](docs/DEVELOPER_GUIDE.md) before modifying payment or billing logic.

## Access roles

- `admin`: full management access.
- `accountant`: finance, reports, inventory, and dashboard access.
- `cashier`: POS and cashier-approved inventory functions.

## Validation

Run PHP syntax validation after editing:

```powershell
D:\Software\Xammp\php\php.exe -l path\to\file.php
```

This project currently has no automated application test suite. Payment changes should also be checked manually using the scenarios in the developer guide.
