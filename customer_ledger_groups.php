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
$sales = $db->prepare('SELECT id,sale_no FROM sales WHERE customer_id=? AND payment_type="credit" AND status NOT IN("cancelled","returned") ORDER BY sale_date,id');
$sales->execute([$customerId]);
$saleRows = $sales->fetchAll();
$references = [];
foreach ($saleRows as $sale) {
    $references[$sale['sale_no']] = $sale['sale_no'];
    $references[$sale['sale_no'].'-INITIAL'] = $sale['sale_no'];
}

$receipts = $db->prepare('SELECT cp.receipt_no,GROUP_CONCAT(DISTINCT s.sale_no ORDER BY s.sale_date,s.id) sale_numbers FROM customer_payments cp JOIN customer_payment_allocations cpa ON cpa.payment_id=cp.id JOIN sales s ON s.id=cpa.sale_id WHERE cp.customer_id=? GROUP BY cp.id,cp.receipt_no');
$receipts->execute([$customerId]);
foreach ($receipts as $receipt) {
    $saleNumbers = array_values(array_filter(explode(',', (string)$receipt['sale_numbers'])));
    if ($saleNumbers) $references[$receipt['receipt_no']] = $saleNumbers[0];
}

echo json_encode(['sales' => array_column($saleRows, 'sale_no'), 'references' => $references], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
