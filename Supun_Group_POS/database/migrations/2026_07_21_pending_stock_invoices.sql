-- Receive stock before its supplier invoice arrives, then finalize accounting later.
ALTER TABLE stock_adjustments
  ADD COLUMN invoice_status ENUM('not_required','pending','finalized') NOT NULL DEFAULT 'not_required' AFTER note,
  ADD COLUMN supplier_id INT UNSIGNED NULL AFTER invoice_status,
  ADD COLUMN purchase_id BIGINT UNSIGNED NULL AFTER supplier_id,
  ADD COLUMN supplier_invoice VARCHAR(80) NULL AFTER purchase_id,
  ADD COLUMN invoice_date DATE NULL AFTER supplier_invoice,
  ADD COLUMN finalized_at DATETIME NULL AFTER invoice_date,
  ADD INDEX idx_stock_adjustments_invoice_status (invoice_status);
