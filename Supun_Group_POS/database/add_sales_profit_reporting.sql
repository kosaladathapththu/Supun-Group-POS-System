-- Run once on an existing ST Pvt Ltd. POS database.
ALTER TABLE order_items
  ADD COLUMN IF NOT EXISTS cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unit_price;

DROP TRIGGER IF EXISTS trg_order_item_stock_after_update;

UPDATE order_items oi
JOIN products p ON p.product_id=oi.product_id
SET oi.cost_price=p.cost_price
WHERE oi.product_id IS NOT NULL AND oi.cost_price=0;

DELIMITER $$
CREATE TRIGGER trg_order_item_stock_after_update AFTER UPDATE ON order_items
FOR EACH ROW
BEGIN
  IF OLD.product_id IS NOT NULL THEN
    UPDATE products SET stock_qty=stock_qty+OLD.quantity WHERE product_id=OLD.product_id;
  END IF;
  IF NEW.product_id IS NOT NULL THEN
    UPDATE products SET stock_qty=stock_qty-NEW.quantity WHERE product_id=NEW.product_id;
  END IF;
END$$
DELIMITER ;
