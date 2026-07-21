CREATE TABLE IF NOT EXISTS customer_accounts (
  customer_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_number VARCHAR(30) NOT NULL UNIQUE,
  customer_name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NULL,
  address VARCHAR(255) NULL,
  advance_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customer_name (customer_name), INDEX idx_customer_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE orders ADD COLUMN customer_id BIGINT UNSIGNED NULL AFTER customer_name;
ALTER TABLE orders ADD COLUMN advance_used DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount;
ALTER TABLE orders ADD INDEX idx_orders_customer (customer_id);
ALTER TABLE orders ADD CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customer_accounts(customer_id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS advance_payment_transactions (
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
  INDEX idx_advance_customer (customer_id), INDEX idx_advance_order (order_id), INDEX idx_advance_created (created_at),
  CONSTRAINT fk_advance_customer FOREIGN KEY (customer_id) REFERENCES customer_accounts(customer_id),
  CONSTRAINT fk_advance_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE SET NULL,
  CONSTRAINT fk_advance_user FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
