CREATE TABLE IF NOT EXISTS customer_advances (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 advance_no VARCHAR(60) UNIQUE NOT NULL,
 customer_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NULL,
 reserved_quantity DECIMAL(14,3) NULL,
 expected_unit_price DECIMAL(18,2) NULL,
 amount DECIMAL(18,2) NOT NULL,
 remaining_amount DECIMAL(18,2) NOT NULL,
 received_at DATETIME NOT NULL,
 method VARCHAR(40) NOT NULL,
 reference_no VARCHAR(100) NULL,
 notes TEXT NULL,
 status ENUM('open','partially_applied','applied','refunded','cancelled') NOT NULL DEFAULT 'open',
 created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(customer_id) REFERENCES customers(id),
 FOREIGN KEY(product_id) REFERENCES products(id),
 FOREIGN KEY(created_by) REFERENCES users(id),
 INDEX(customer_id,status)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS customer_advance_applications (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 advance_id BIGINT UNSIGNED NOT NULL,
 sale_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(18,2) NOT NULL,
 applied_at DATETIME NOT NULL,
 applied_by BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(advance_id) REFERENCES customer_advances(id),
 FOREIGN KEY(sale_id) REFERENCES sales(id),
 FOREIGN KEY(applied_by) REFERENCES users(id),
 INDEX(advance_id),INDEX(sale_id)
) ENGINE=InnoDB;
