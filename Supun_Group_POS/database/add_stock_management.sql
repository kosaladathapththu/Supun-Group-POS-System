-- Run once on an existing ST Pvt Ltd. POS database.
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

DROP TRIGGER IF EXISTS trg_order_item_stock_before_insert;
DROP TRIGGER IF EXISTS trg_order_item_stock_before_update;
DELIMITER $$
CREATE TRIGGER trg_order_item_stock_before_insert BEFORE INSERT ON order_items
FOR EACH ROW
BEGIN
  DECLARE available_qty DECIMAL(12,3);
  IF NEW.product_id IS NOT NULL THEN
    SELECT stock_qty INTO available_qty FROM products WHERE product_id=NEW.product_id FOR UPDATE;
    IF available_qty < NEW.quantity THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Insufficient stock for this product'; END IF;
  END IF;
END$$
CREATE TRIGGER trg_order_item_stock_before_update BEFORE UPDATE ON order_items
FOR EACH ROW
BEGIN
  DECLARE available_qty DECIMAL(12,3);
  IF NEW.product_id IS NOT NULL AND NEW.product_id=OLD.product_id AND NEW.quantity>OLD.quantity THEN
    SELECT stock_qty INTO available_qty FROM products WHERE product_id=NEW.product_id FOR UPDATE;
    IF available_qty < (NEW.quantity-OLD.quantity) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Insufficient stock for this product'; END IF;
  ELSEIF NEW.product_id IS NOT NULL AND (OLD.product_id IS NULL OR NEW.product_id<>OLD.product_id) THEN
    SELECT stock_qty INTO available_qty FROM products WHERE product_id=NEW.product_id FOR UPDATE;
    IF available_qty < NEW.quantity THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Insufficient stock for this product'; END IF;
  END IF;
END$$
DELIMITER ;
