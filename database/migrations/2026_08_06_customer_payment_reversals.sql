ALTER TABLE customer_payments
 ADD COLUMN IF NOT EXISTS status ENUM('posted','reversed') NOT NULL DEFAULT 'posted' AFTER notes,
 ADD COLUMN IF NOT EXISTS reversal_type ENUM('cheque_returned','cash_returned','payment_reversed','other') NULL AFTER status,
 ADD COLUMN IF NOT EXISTS reversal_reason VARCHAR(500) NULL AFTER reversal_type,
 ADD COLUMN IF NOT EXISTS reversed_at DATETIME NULL AFTER reversal_reason,
 ADD COLUMN IF NOT EXISTS reversed_by BIGINT UNSIGNED NULL AFTER reversed_at,
 ADD INDEX IF NOT EXISTS idx_customer_payments_status (status);
