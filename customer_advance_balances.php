<?php
require __DIR__.'/bootstrap.php';
require_auth();
if(!can('customers.view')){http_response_code(403);exit('Forbidden');}
header('Content-Type: application/json; charset=utf-8');
$sql='SELECT c.id customer_id,
 COALESCE(SUM(CASE WHEN a.status IN("open","partially_applied") THEN a.remaining_amount ELSE 0 END),0) advance_available,
 COALESCE(SUM(CASE WHEN a.status IN("open","partially_applied") AND a.product_id IS NOT NULL THEN LEAST(a.remaining_amount,a.reserved_quantity*a.expected_unit_price) ELSE 0 END),0) reserved_for_orders,
 COALESCE(SUM(CASE WHEN a.status IN("open","partially_applied") THEN CASE WHEN a.product_id IS NULL THEN a.remaining_amount ELSE GREATEST(a.remaining_amount-(a.reserved_quantity*a.expected_unit_price),0) END ELSE 0 END),0) extra_for_future,
 COUNT(CASE WHEN a.status IN("open","partially_applied") AND a.remaining_amount>0 THEN 1 END) open_advances
 FROM customers c LEFT JOIN customer_advances a ON a.customer_id=c.id GROUP BY c.id';
$rows=$db->query($sql)->fetchAll();
$balances=[];
foreach($rows as $row)$balances[(string)$row['customer_id']]=['advance_available'=>(float)$row['advance_available'],'reserved_for_orders'=>(float)$row['reserved_for_orders'],'extra_for_future'=>(float)$row['extra_for_future'],'open_advances'=>(int)$row['open_advances']];
echo json_encode(['balances'=>$balances],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
