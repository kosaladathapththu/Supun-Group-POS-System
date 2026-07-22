-- Separate flexible customer account credit from order-specific installments.
-- Account credit consists only of unused deposits that are not linked to an order.

UPDATE customer_accounts c
SET c.advance_balance = COALESCE((
    SELECT SUM(t.remaining_amount)
    FROM advance_payment_transactions t
    WHERE t.customer_id = c.customer_id
      AND t.transaction_type = 'deposit'
      AND t.order_id IS NULL
      AND t.remaining_amount > 0
), 0);

