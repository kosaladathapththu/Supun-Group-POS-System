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
if((user()['role_code']??'')==='cashier'){foreach(['avg_cost','retail_profit','retail_margin','retail_markup','wholesale_profit','wholesale_margin','wholesale_markup','stock_cost_value'] as $key)unset($p[$key]);}
echo json_encode(['product'=>$p],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
