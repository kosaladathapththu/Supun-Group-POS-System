-- Run once on an existing Supun Group POS database.
CREATE TABLE IF NOT EXISTS stock_adjustments (
  adjustment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  adjustment_type ENUM('stock_in','stock_out','set') NOT NULL,
  quantity DECIMAL(12,3) NOT NULL,
  stock_before DECIMAL(12,3) NOT NULL,
  stock_after DECIMAL(12,3) NOT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_stock_adjustments_product (product_id),
  INDEX idx_stock_adjustments_created (created_at),
  CONSTRAINT fk_stock_adjustment_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
  CONSTRAINT fk_stock_adjustment_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;
