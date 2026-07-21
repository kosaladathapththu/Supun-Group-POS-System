<?php

function ensureAdvancePaymentSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS customer_accounts (
        customer_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_number VARCHAR(30) NOT NULL UNIQUE,
        customer_name VARCHAR(150) NOT NULL,
        phone VARCHAR(30) NULL,
        address VARCHAR(255) NULL,
        advance_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_customer_name (customer_name),
        INDEX idx_customer_phone (phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS advance_payment_transactions (
        transaction_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        receipt_number VARCHAR(35) NOT NULL UNIQUE,
        customer_id BIGINT UNSIGNED NOT NULL,
        order_id BIGINT UNSIGNED NULL,
        transaction_type ENUM('deposit','sale_usage','refund','adjustment') NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        payment_method VARCHAR(40) NOT NULL DEFAULT 'Cash',
        reference_note VARCHAR(255) NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_advance_customer (customer_id),
        INDEX idx_advance_order (order_id),
        INDEX idx_advance_created (created_at),
        CONSTRAINT fk_advance_customer FOREIGN KEY (customer_id) REFERENCES customer_accounts(customer_id),
        CONSTRAINT fk_advance_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE SET NULL,
        CONSTRAINT fk_advance_user FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $advance_columns = [];
    if ($result = $conn->query("SHOW COLUMNS FROM advance_payment_transactions")) {
        while ($row = $result->fetch_assoc()) $advance_columns[$row['Field']] = true;
    }
    if (!isset($advance_columns['remaining_amount'])) {
        $conn->query("ALTER TABLE advance_payment_transactions ADD remaining_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER amount");
        $conn->query("UPDATE advance_payment_transactions SET remaining_amount=amount WHERE transaction_type='deposit'");
    }
    if (!isset($advance_columns['settlement_status'])) {
        $conn->query("ALTER TABLE advance_payment_transactions ADD settlement_status ENUM('open','partial','settled') NOT NULL DEFAULT 'settled' AFTER remaining_amount");
        $conn->query("UPDATE advance_payment_transactions SET settlement_status='open' WHERE transaction_type='deposit' AND remaining_amount>0");
    }
    if (!isset($advance_columns['settlement_due_date'])) {
        $conn->query("ALTER TABLE advance_payment_transactions ADD settlement_due_date DATE NULL AFTER settlement_status");
        $conn->query("UPDATE advance_payment_transactions SET settlement_due_date=DATE_ADD(DATE(created_at),INTERVAL 1 DAY) WHERE transaction_type='deposit' AND settlement_due_date IS NULL");
    }
    if (!isset($advance_columns['parent_transaction_id'])) {
        $conn->query("ALTER TABLE advance_payment_transactions ADD parent_transaction_id BIGINT UNSIGNED NULL AFTER order_id, ADD INDEX idx_advance_parent (parent_transaction_id)");
    }

    $columns = [];
    if ($result = $conn->query("SHOW COLUMNS FROM orders")) {
        while ($row = $result->fetch_assoc()) $columns[$row['Field']] = true;
    }
    if (!isset($columns['customer_id'])) {
        $conn->query("ALTER TABLE orders ADD customer_id BIGINT UNSIGNED NULL AFTER customer_name, ADD INDEX idx_orders_customer (customer_id), ADD CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customer_accounts(customer_id) ON DELETE SET NULL");
    }
    if (!isset($columns['advance_used'])) {
        $conn->query("ALTER TABLE orders ADD advance_used DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount");
    }
}

function nextAccountNumber(mysqli $conn): string
{
    $next = (int)($conn->query("SELECT COALESCE(MAX(customer_id),0)+1 AS n FROM customer_accounts")->fetch_assoc()['n'] ?? 1);
    return 'CUS-' . str_pad((string)$next, 5, '0', STR_PAD_LEFT);
}

function nextAdvanceReceipt(mysqli $conn): string
{
    do {
        $number = 'ADV-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $safe = $conn->real_escape_string($number);
        $exists = $conn->query("SELECT 1 FROM advance_payment_transactions WHERE receipt_number='$safe' LIMIT 1");
    } while ($exists && $exists->num_rows);
    return $number;
}
