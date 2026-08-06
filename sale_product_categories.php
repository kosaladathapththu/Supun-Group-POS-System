<?php
require __DIR__.'/bootstrap.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');
$rows=$db->query('SELECT p.id,COALESCE(c.name,"Uncategorised") category FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.status="active" AND p.current_stock>0 ORDER BY c.name,p.name')->fetchAll();
$products=[];$categories=[];
foreach($rows as $row){$products[(string)$row['id']]=$row['category'];$categories[$row['category']]=true;}
echo json_encode(['products'=>$products,'categories'=>array_keys($categories)],JSON_UNESCAPED_UNICODE);
