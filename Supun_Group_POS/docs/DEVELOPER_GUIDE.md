# Developer Guide

## 1. Request lifecycle

Most pages follow the same sequence:

1. Start or validate the PHP session.
2. Include `db.php`.
3. Check the logged-in role.
4. Process POST/GET actions before rendering HTML.
5. Query data needed by the page.
6. Render PHP, HTML, CSS, and JavaScript.

Keep redirects immediately after a successful write and call `exit` so the request cannot continue rendering stale state.

## 2. Directory map

```text
Supun_Group_POS/
├── admin/                    Management pages and reports
├── assets/                   Front-end assets
├── cashier/                  Cashier-specific pages
├── database/                 Base schema and migrations
├── includes/                 Shared PHP business logic
├── js/                       Standalone JavaScript
├── sql/                      Additional SQL scripts
├── pos.php                   Main checkout controller and UI
├── advance_payments.php      Credit/installment controller and UI
├── installment_payments.php  Installment-only view wrapper
├── print_bill.php            Final invoice
└── print_advance.php         Credit/installment receipt
```

## 3. Payment terminology

These meanings must remain separate:

| Term                 | Database representation               | Meaning                                   |
| -------------------- | ------------------------------------- | ----------------------------------------- |
| Account credit       | `deposit` with `order_id IS NULL`     | Customer money held for a future purchase |
| Order installment    | `deposit` with `order_id IS NOT NULL` | Part payment tied to one order            |
| Credit/payment usage | `sale_usage`                          | Money applied to a completed sale         |
| Refund               | `refund`                              | Money returned to the customer            |

`remaining_amount` is the unused portion of a deposit. Never calculate account-credit liability from historical deposit totals; sum only unused unlinked deposits.

## 4. Important payment invariants

- Account credit must not become an installment merely because the customer later opens an order.
- An installment must not appear in account-credit liability.
- Applying credit must create an auditable `sale_usage` record linked by `parent_transaction_id`.
- Updates to deposits, usage rows, and orders must occur inside one database transaction.
- A final invoice must show account credit and installments separately when both were used.
- A failed allocation must roll back every related change.

## 5. Main workflows

### Normal sale

`pos.php` creates or opens an order, updates its items, receives payment, marks it paid, and redirects to `print_bill.php`.

### Account credit

`advance_payments.php` creates an unlinked deposit. The current customer liability is the sum of its unused `remaining_amount` values.

### Installment sale

An installment creates a deposit linked to the open order. Later payments remain linked to that order. When the bill closes, usage records provide the audit trail used by the invoice.

### Closing with account credit

The cashier/accountant explicitly selects account credit. The system locks available deposits, allocates only what is required, records usage, completes the order, and prints the final bill.

## 6. Reports

- `admin/account_credit_report.php`: account-credit-only report.
- `admin/installment_report.php`: installment-only report.
- `admin/payment_reports.php`: shared report implementation.

The wrapper pages define the report type and reuse the common implementation. Keep authorization inside the shared implementation so direct access remains protected.

## 7. Coding conventions

- Use four spaces for PHP and two spaces for front-end code.
- Prefer descriptive names such as `$available_credit` and `$installment_paid`.
- Use prepared statements for user-controlled values.
- Use section comments for request handlers, queries, and rendering.
- Keep calculation variables close to the query or input that supplies them.
- Avoid adding new one-line controller blocks. Expand validation, transactions, and error handling vertically.
- Escape displayed values with `htmlspecialchars`.
- Validate allowed values with explicit allowlists.
- Preserve POST field names, query parameters, element IDs, and database column names during readability-only work.

## 8. Manual regression checklist

After changing payment code, verify:

1. Normal cash sale prints one final bill.
2. Account credit can be saved without an order.
3. Account credit liability equals only unused customer money.
4. Existing and new customer installment paths show the correct form.
5. An installment keeps an underpaid order open.
6. Account credit can optionally close an installment bill.
7. Final bill shows total, installments, account credit used, and remaining credit.
8. Account statement lists deposits and purchases using credit.
9. Admin/accountant reports filter, print, and export.
10. Cashiers cannot open protected finance reports.

## 9. Safe refactoring rule

For readability-only changes, do not rename public form fields, query parameters, DOM IDs, session keys, routes, database fields, or transaction types. Those strings connect PHP, JavaScript, forms, reports, and receipts.
