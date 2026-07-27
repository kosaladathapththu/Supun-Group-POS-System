-- Run once on an existing ST Pvt Ltd. POS database.
ALTER TABLE stock_adjustments
  ADD COLUMN IF NOT EXISTS unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER stock_after,
  ADD COLUMN IF NOT EXISTS total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unit_cost;

UPDATE stock_adjustments sa
JOIN products p ON p.product_id=sa.product_id
SET sa.unit_cost=p.cost_price,
    sa.total_cost=sa.quantity*p.cost_price
WHERE sa.adjustment_type='stock_in' AND sa.total_cost=0;
