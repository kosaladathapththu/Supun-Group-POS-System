<?php
require __DIR__.'/bootstrap.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');
if (!can('customers.view')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$customerId = (int)($_GET['customer_id'] ?? 0);
$sales = $db->prepare('SELECT id,sale_no,total,paid_amount,balance,status,due_date FROM sales WHERE customer_id=? AND payment_type="credit" AND status NOT IN("cancelled","returned") ORDER BY sale_date,id');
$sales->execute([$customerId]);
$saleRows = $sales->fetchAll();
$references = [];
foreach ($saleRows as $sale) {
    $references[$sale['sale_no']] = $sale['sale_no'];
    $references[$sale['sale_no'].'-INITIAL'] = $sale['sale_no'];
}

$receipts = $db->prepare('SELECT cp.receipt_no,GROUP_CONCAT(DISTINCT s.sale_no ORDER BY s.sale_date,s.id) sale_numbers FROM customer_payments cp JOIN customer_payment_allocations cpa ON cpa.payment_id=cp.id JOIN sales s ON s.id=cpa.sale_id WHERE cp.customer_id=? AND cp.status="posted" GROUP BY cp.id,cp.receipt_no');
$receipts->execute([$customerId]);
foreach ($receipts as $receipt) {
    $saleNumbers = array_values(array_filter(explode(',', (string)$receipt['sale_numbers'])));
    if ($saleNumbers) $references[$receipt['receipt_no']] = $saleNumbers[0];
}

$paymentBreakdowns = [];
$paymentRows = $db->prepare('SELECT s.id sale_id,CONCAT(s.sale_no,"-INITIAL") receipt_no,s.sale_date payment_date,sp.method,sp.amount allocated_amount FROM sale_payments sp JOIN sales s ON s.id=sp.sale_id WHERE s.customer_id=? AND s.payment_type="credit" UNION ALL SELECT cpa.sale_id,cp.receipt_no,cp.payment_date,cp.method,cpa.amount allocated_amount FROM customer_payment_allocations cpa JOIN customer_payments cp ON cp.id=cpa.payment_id JOIN sales s ON s.id=cpa.sale_id WHERE cp.customer_id=? AND s.payment_type="credit" AND cp.status="posted" ORDER BY payment_date,receipt_no');
$paymentRows->execute([$customerId, $customerId]);
foreach ($paymentRows as $payment) {
    $saleId = (string)$payment['sale_id'];
    if (!isset($paymentBreakdowns[$saleId])) $paymentBreakdowns[$saleId] = [];
    $paymentBreakdowns[$saleId][] = [
        'receipt_no' => $payment['receipt_no'],
        'payment_date' => $payment['payment_date'],
        'method' => $payment['method'],
        'amount' => (float)$payment['allocated_amount'],
    ];
}

$reversed=$db->prepare('SELECT receipt_no FROM customer_payments WHERE customer_id=? AND status="reversed"');$reversed->execute([$customerId]);$reversedReceipts=$reversed->fetchAll(PDO::FETCH_COLUMN);
echo json_encode(['sales' => array_column($saleRows, 'sale_no'), 'references' => $references, 'invoice_summaries' => $saleRows, 'payment_breakdowns' => $paymentBreakdowns, 'reversed_receipts'=>$reversedReceipts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
