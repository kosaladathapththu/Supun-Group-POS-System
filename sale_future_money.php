<?php
/* Apply only genuinely uncommitted/excess advance money to a newly created sale. */
$futureMoneyPlan = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'create_customer') {
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $requested = max(0, (float) ($_POST['future_money_amount'] ?? 0));

    if ($customerId && $requested > 0) {
        $statement = $db->prepare(
            'SELECT COALESCE(SUM(CASE WHEN product_id IS NULL THEN remaining_amount '
            . 'ELSE GREATEST(remaining_amount-(reserved_quantity*expected_unit_price),0) END),0) '
            . 'FROM customer_advances WHERE customer_id=? '
            . 'AND status IN("open","partially_applied") AND remaining_amount>0'
        );
        $statement->execute([$customerId]);
        $available = (float) $statement->fetchColumn();
        $use = min($requested, $available);

        if ($use > 0) {
            $cashReceived = max(0, (float) ($_POST['paid_amount'] ?? 0));
            $_POST['paid_amount'] = $cashReceived + $use;
            $futureMoneyPlan = [
                'customer_id' => $customerId,
                'requested' => $use,
                'cash_received' => $cashReceived,
            ];

            register_shutdown_function(function () use (&$saleId, &$futureMoneyPlan, $db) {
                if (empty($saleId) || empty($futureMoneyPlan)) {
                    return;
                }

                $db->beginTransaction();
                try {
                    $saleStatement = $db->prepare('SELECT total,paid_amount FROM sales WHERE id=? FOR UPDATE');
                    $saleStatement->execute([$saleId]);
                    $savedSale = $saleStatement->fetch();
                    if (!$savedSale) {
                        $db->rollBack();
                        return;
                    }

                    /* Existing sale code caps paid_amount at the invoice total. Respect that cap. */
                    $maximumAdvance = max(
                        0,
                        (float) $savedSale['paid_amount'] - (float) $futureMoneyPlan['cash_received']
                    );
                    $remaining = min((float) $futureMoneyPlan['requested'], $maximumAdvance);
                    $planned = $remaining;

                    $statement = $db->prepare(
                        'SELECT * FROM customer_advances WHERE customer_id=? '
                        . 'AND status IN("open","partially_applied") AND remaining_amount>0 '
                        . 'ORDER BY received_at,id FOR UPDATE'
                    );
                    $statement->execute([$futureMoneyPlan['customer_id']]);

                    foreach ($statement->fetchAll() as $advance) {
                        if ($remaining <= 0) {
                            break;
                        }
                        $expected = $advance['product_id']
                            ? (float) $advance['reserved_quantity'] * (float) $advance['expected_unit_price']
                            : 0;
                        $extra = $advance['product_id']
                            ? max(0, (float) $advance['remaining_amount'] - $expected)
                            : (float) $advance['remaining_amount'];
                        $take = min($remaining, $extra);
                        if ($take <= 0) {
                            continue;
                        }

                        $newRemaining = (float) $advance['remaining_amount'] - $take;
                        $status = $newRemaining <= 0 ? 'applied' : 'partially_applied';
                        $db->prepare(
                            'INSERT INTO customer_advance_applications'
                            . '(advance_id,sale_id,amount,applied_at,applied_by) VALUES(?,?,?,NOW(),?)'
                        )->execute([$advance['id'], $saleId, $take, user()['id']]);
                        $db->prepare(
                            'UPDATE customer_advances SET remaining_amount=?,status=? WHERE id=?'
                        )->execute([$newRemaining, $status, $advance['id']]);
                        $remaining -= $take;
                    }

                    $used = $planned - $remaining;
                    if ($used > 0) {
                        /* sale_payments must contain newly received cash only, not old advance money. */
                        $payment = $db->prepare(
                            'SELECT id,amount FROM sale_payments WHERE sale_id=? ORDER BY id LIMIT 1 FOR UPDATE'
                        );
                        $payment->execute([$saleId]);
                        $row = $payment->fetch();
                        if ($row) {
                            $cash = max(0, (float) $row['amount'] - $used);
                            if ($cash <= 0) {
                                $db->prepare('DELETE FROM sale_payments WHERE id=?')->execute([$row['id']]);
                            } else {
                                $db->prepare('UPDATE sale_payments SET amount=? WHERE id=?')
                                    ->execute([$cash, $row['id']]);
                            }
                        }
                        audit($db, 'apply', 'customer_future_money', $saleId, null, [
                            'amount' => $used,
                            'customer_id' => $futureMoneyPlan['customer_id'],
                        ]);
                    }
                    $db->commit();
                } catch (Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    error_log('Future money application failed: ' . $exception->getMessage());
                }
            });
        }
    }
}
