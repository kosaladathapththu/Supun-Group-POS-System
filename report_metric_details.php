<?php
require __DIR__ . '/bootstrap.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');
if (!can('reports.view')) {
    http_response_code(403);
    echo json_encode(['error' => 'Not permitted']);
    exit;
}

$metric = $_GET['metric'] ?? '';
$reportType = $_GET['report_type'] ?? 'invoice';
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$payment = $_GET['payment'] ?? '';
$channel = $_GET['channel'] ?? '';
$where = 'DATE(s.sale_date) BETWEEN ? AND ? AND s.status NOT IN("cancelled","returned")';
$params = [$from, $to];
if (in_array($payment, ['cash', 'credit'], true)) {
    $where .= ' AND s.payment_type=?';
    $params[] = $payment;
}
if (in_array($channel, ['retail', 'wholesale'], true)) {
    $where .= ' AND s.sale_type=?';
    $params[] = $channel;
}

$titles = [
    'all_sales' => 'All Sales - Full Details',
    'cash_sales' => 'Cash Sales - Full Details',
    'credit_sales' => 'Credit Sales - Full Details',
    'collected' => 'All Money Collected - Full Details',
    'credit_collected' => 'Money Collected from Credit Sales',
    'receivables' => 'Current Customer Receivables',
    'profit' => 'Expected Gross Profit - Full Details',
    'quantity' => 'Quantity - Full Details',
    'total_value' => 'Total Value - Full Details',
    'saved_cost' => 'Stock / Saved Cost - Full Details',
    'potential_profit' => 'Potential Profit - Full Details',
];
if (!isset($titles[$metric])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown report card']);
    exit;
}

$columns = [];
$rows = [];
$summary = [];

if ($metric === 'receivables') {
    $columns = ['Customer', 'Phone', 'Open bills', 'Outstanding', 'Last purchase'];
    $rows = $db->query('SELECT c.name,c.phone,COUNT(s.id) open_bills,c.outstanding,COALESCE(DATE_FORMAT(c.last_purchase_at,"%d %b %Y"),"—") last_purchase FROM customers c LEFT JOIN sales s ON s.customer_id=c.id AND s.balance>0 AND s.status IN("unpaid","partially_paid","overdue") WHERE c.status="active" AND c.outstanding>0 GROUP BY c.id ORDER BY c.outstanding DESC')->fetchAll(PDO::FETCH_NUM);
    $summary['Current receivables'] = array_sum(array_map(fn($row) => (float) $row[3], $rows));
} elseif (in_array($metric, ['quantity', 'total_value', 'saved_cost', 'potential_profit'], true) && $reportType === 'stock') {
    $columns = ['Item code', 'Product', 'Category', 'In stock', 'Buying cost', 'Retail value', 'Potential profit'];
    $rows = $db->query('SELECT p.item_code,p.name,COALESCE(c.name,"Uncategorised"),p.current_stock,p.avg_cost,p.current_stock*p.retail_price,p.current_stock*(p.retail_price-p.avg_cost) FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.status="active" ORDER BY p.name')->fetchAll(PDO::FETCH_NUM);
    $summary = [
        'Total quantity' => array_sum(array_map(fn($row) => (float) $row[3], $rows)),
        'Stock at cost' => array_sum(array_map(fn($row) => (float) $row[3] * (float) $row[4], $rows)),
        'Retail stock value' => array_sum(array_map(fn($row) => (float) $row[5], $rows)),
        'Potential profit' => array_sum(array_map(fn($row) => (float) $row[6], $rows)),
    ];
} elseif (in_array($metric, ['quantity', 'total_value', 'saved_cost', 'potential_profit'], true) && $reportType === 'purchase') {
    $columns = ['Purchase', 'Supplier', 'Buying date', 'Items', 'Quantity', 'Bill total', 'Paid', 'Still owed'];
    $statement = $db->prepare('SELECT p.purchase_no,s.name,p.purchase_date,COUNT(pi.id),SUM(pi.quantity),p.total,p.paid_amount,p.balance FROM purchases p JOIN suppliers s ON s.id=p.supplier_id JOIN purchase_items pi ON pi.purchase_id=p.id WHERE p.purchase_date BETWEEN ? AND ? AND p.status="posted" GROUP BY p.id ORDER BY p.purchase_date DESC,p.id DESC');
    $statement->execute([$from, $to]);
    $rows = $statement->fetchAll(PDO::FETCH_NUM);
    $summary = [
        'Purchased quantity' => array_sum(array_map(fn($row) => (float) $row[4], $rows)),
        'Purchase value' => array_sum(array_map(fn($row) => (float) $row[5], $rows)),
        'Paid to suppliers' => array_sum(array_map(fn($row) => (float) $row[6], $rows)),
        'Still payable' => array_sum(array_map(fn($row) => (float) $row[7], $rows)),
    ];
} else {
    $extra = '';
    if ($metric === 'cash_sales') {
        $extra = ' AND s.payment_type="cash"';
    } elseif (in_array($metric, ['credit_sales', 'credit_collected'], true)) {
        $extra = ' AND s.payment_type="credit"';
    }
    $columns = ['Invoice', 'Date', 'Customer', 'Type', 'Status', 'Total', 'Collected', 'Still due', 'Saved cost', 'Gross profit'];
    $sql = 'SELECT s.sale_no,DATE_FORMAT(s.sale_date,"%d %b %Y %H:%i"),COALESCE(c.name,"Walk-in"),CONCAT(UPPER(s.sale_type)," / ",UPPER(s.payment_type)),REPLACE(UPPER(s.status),"_"," "),s.total,s.paid_amount,s.balance,SUM(si.historical_unit_cost*si.quantity),SUM(si.gross_profit) FROM sales s JOIN sale_items si ON si.sale_id=s.id LEFT JOIN customers c ON c.id=s.customer_id WHERE ' . $where . $extra . ' GROUP BY s.id ORDER BY s.sale_date DESC';
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_NUM);
    $summary = [
        'Invoice value' => array_sum(array_map(fn($row) => (float) $row[5], $rows)),
        'Money collected' => array_sum(array_map(fn($row) => (float) $row[6], $rows)),
        'Still due' => array_sum(array_map(fn($row) => (float) $row[7], $rows)),
        'Gross profit' => array_sum(array_map(fn($row) => (float) $row[9], $rows)),
    ];
}

echo json_encode([
    'title' => $titles[$metric],
    'period' => $from . ' to ' . $to,
    'columns' => $columns,
    'rows' => $rows,
    'summary' => $summary,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
