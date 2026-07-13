-- Run once on an existing Supun Group POS database.
ALTER TABLE order_items
  ADD COLUMN price_overridden TINYINT(1) NOT NULL DEFAULT 0 AFTER item_type;
