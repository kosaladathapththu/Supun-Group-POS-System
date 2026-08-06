<?php
require __DIR__.'/bootstrap.php';require_auth();
if((user()['role_code']??'')!=='main_admin'){http_response_code(403);exit;}
header('Content-Type: application/json; charset=utf-8');
$id=(int)($_GET['id']??0);
$stmt=$db->prepare('SELECT p.id,p.purchase_no,p.supplier_invoice,p.purchase_date,p.created_at,p.payment_type,p.subtotal,p.discount,p.tax,p.total,p.paid_amount,p.balance,p.due_date,p.payment_method,p.notes,p.status,s.supplier_code,s.name supplier,s.phone supplier_phone FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE p.id=?');$stmt->execute([$id]);$bill=$stmt->fetch();
if(!$bill){http_response_code(404);echo json_encode(['error'=>'Supplier bill not found']);exit;}
$stmt=$db->prepare('SELECT pr.item_code,pr.name,pr.unit,COALESCE(c.name,"Uncategorised") category,pi.quantity,pi.unit_cost,pi.discount,pi.tax,pi.line_total FROM purchase_items pi JOIN products pr ON pr.id=pi.product_id LEFT JOIN categories c ON c.id=pr.category_id WHERE pi.purchase_id=? ORDER BY pi.id');$stmt->execute([$id]);$items=$stmt->fetchAll();
$stmt=$db->prepare('SELECT payment_no,payment_date,amount,method,reference_no,notes FROM supplier_payments WHERE purchase_id=? ORDER BY payment_date,id');$stmt->execute([$id]);$payments=$stmt->fetchAll();
echo json_encode(['bill'=>$bill,'items'=>$items,'payments'=>$payments],JSON_UNESCAPED_UNICODE);
