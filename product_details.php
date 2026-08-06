<?php
require __DIR__.'/bootstrap.php';
require_auth();
if (!can('products.view')) { http_response_code(403); exit('Forbidden'); }
header('Content-Type: application/json; charset=utf-8');
$id=(int)($_GET['id']??0);
$stmt=$db->prepare('SELECT p.*,c.name category,b.name brand FROM products p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id WHERE p.id=?');
$stmt->execute([$id]);
$p=$stmt->fetch();
if(!$p){http_response_code(404);echo json_encode(['error'=>'Product not found']);exit;}
$cost=(float)$p['avg_cost'];$retail=(float)$p['retail_price'];$wholesale=(float)$p['wholesale_price'];
$p['retail_profit']=$retail-$cost;$p['retail_margin']=$retail>0?(($retail-$cost)/$retail)*100:0;$p['retail_markup']=$cost>0?(($retail-$cost)/$cost)*100:0;
$p['wholesale_profit']=$wholesale-$cost;$p['wholesale_margin']=$wholesale>0?(($wholesale-$cost)/$wholesale)*100:0;$p['wholesale_markup']=$cost>0?(($wholesale-$cost)/$cost)*100:0;
$p['stock_cost_value']=(float)$p['current_stock']*$cost;
$salesStmt=$db->prepare('SELECT
 COALESCE(SUM(si.quantity),0) sold_quantity,
 COALESCE(SUM(si.line_total),0) sold_value,
 COALESCE(SUM(si.historical_unit_cost*si.quantity),0) sold_cost,
 COALESCE(SUM(si.gross_profit),0) realized_profit,
 COALESCE(SUM(CASE WHEN s.payment_type="cash" THEN si.quantity ELSE 0 END),0) cash_quantity,
 COALESCE(SUM(CASE WHEN s.payment_type="cash" THEN si.line_total ELSE 0 END),0) cash_sales_value,
 COALESCE(SUM(CASE WHEN s.payment_type="credit" THEN si.quantity ELSE 0 END),0) credit_quantity,
 COALESCE(SUM(CASE WHEN s.payment_type="credit" THEN si.line_total ELSE 0 END),0) credit_sales_value,
 COALESCE(SUM(CASE WHEN s.total>0 THEN si.line_total*(s.paid_amount/s.total) ELSE 0 END),0) collected_value,
 COALESCE(SUM(CASE WHEN s.payment_type="credit" AND s.total>0 THEN si.line_total*(s.balance/s.total) ELSE 0 END),0) credit_still_due
 FROM sale_items si JOIN sales s ON s.id=si.sale_id
 WHERE si.product_id=? AND s.status NOT IN("cancelled","returned")');
$salesStmt->execute([$id]);
$sales=$salesStmt->fetch()?:[];
foreach($sales as $key=>$value)$sales[$key]=(float)$value;
$sales['realized_margin']=$sales['sold_value']>0?($sales['realized_profit']/$sales['sold_value'])*100:0;
if((user()['role_code']??'')==='cashier'){foreach(['avg_cost','retail_profit','retail_margin','retail_markup','wholesale_profit','wholesale_margin','wholesale_markup','stock_cost_value'] as $key)unset($p[$key]);foreach(['sold_cost','realized_profit','realized_margin'] as $key)unset($sales[$key]);}
$p['sales_summary']=$sales;
$advanceStmt=$db->prepare('SELECT COUNT(*) open_advance_orders,COALESCE(SUM(reserved_quantity),0) requested_quantity,COALESCE(SUM(amount),0) advance_received,COALESCE(SUM(remaining_amount),0) advance_available,COALESCE(SUM(reserved_quantity*expected_unit_price),0) expected_order_value FROM customer_advances WHERE product_id=? AND status IN("open","partially_applied") AND remaining_amount>0');
$advanceStmt->execute([$id]);$advance=$advanceStmt->fetch()?:[];foreach($advance as $key=>$value)$advance[$key]=(float)$value;
$advance['current_stock']=(float)$p['current_stock'];$advance['units_waiting']=max(0,$advance['requested_quantity']-$advance['current_stock']);$advance['stock_ready']=$advance['requested_quantity']>0&&$advance['current_stock']>=$advance['requested_quantity'];
$p['advance_summary']=$advance;
echo json_encode(['product'=>$p],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
