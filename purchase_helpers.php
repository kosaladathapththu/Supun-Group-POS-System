<?php
declare(strict_types=1);

function next_number(PDO $db, string $table, string $prefix): string {
    $allowed=['purchases','customer_payments','supplier_payments','import_batches'];
    if(!in_array($table,$allowed,true)) throw new InvalidArgumentException('Invalid sequence');
    $next=(int)$db->query("SELECT COALESCE(MAX(id),0)+1 FROM $table")->fetchColumn();
    return $prefix.'-'.date('Y').'-'.str_pad((string)$next,6,'0',STR_PAD_LEFT);
}

function post_purchase(PDO $db,array $header,array $lines): int {
    if(!$lines) throw new RuntimeException('Add at least one product.');
    $supplierId=(int)$header['supplier_id'];
    $exists=$db->prepare('SELECT id FROM purchases WHERE supplier_id=? AND supplier_invoice=?');
    $exists->execute([$supplierId,$header['supplier_invoice']]);
    if($exists->fetch()) throw new RuntimeException('This supplier invoice was already entered.');
    $subtotal=0; foreach($lines as $line) $subtotal+=(float)$line['quantity']*(float)$line['unit_cost'];
    $discount=max(0,(float)($header['discount']??0));$tax=max(0,(float)($header['tax']??0));$total=max(0,$subtotal-$discount+$tax);
    $paid=max(0,min($total,(float)($header['paid_amount']??0)));$balance=$total-$paid;
    $purchaseNo=next_number($db,'purchases','PUR');
    $stmt=$db->prepare('INSERT INTO purchases(purchase_no,supplier_id,supplier_invoice,purchase_date,payment_type,subtotal,discount,tax,total,paid_amount,balance,due_date,payment_method,notes,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,"posted",?)');
    $stmt->execute([$purchaseNo,$supplierId,$header['supplier_invoice'],$header['purchase_date'],$balance>0?'credit':'cash',$subtotal,$discount,$tax,$total,$paid,$balance,$header['due_date']?:null,$header['payment_method']??'cash',$header['notes']??'',user()['id']]);
    $purchaseId=(int)$db->lastInsertId();
    $item=$db->prepare('INSERT INTO purchase_items(purchase_id,product_id,quantity,unit_cost,line_total) VALUES(?,?,?,?,?)');
    $update=$db->prepare('UPDATE products SET avg_cost=?,current_stock=?,retail_price=IF(?, ?, retail_price),wholesale_price=IF(?, ?, wholesale_price) WHERE id=?');
    $move=$db->prepare('INSERT INTO stock_movements(product_id,movement_type,quantity,unit_cost,running_quantity,reference_type,reference_id,created_by) VALUES(?,"purchase",?,?,?,?,"purchase",?,?)');
    foreach($lines as $line){
        $lock=$db->prepare('SELECT * FROM products WHERE id=? FOR UPDATE');$lock->execute([(int)$line['product_id']]);$product=$lock->fetch();if(!$product)throw new RuntimeException('A product could not be found.');
        $qty=(float)$line['quantity'];$cost=(float)$line['unit_cost'];if($qty<=0||$cost<0)throw new RuntimeException('Purchase quantity and cost are invalid.');
        $oldQty=(float)$product['current_stock'];$newQty=$oldQty+$qty;$newCost=$newQty>0?(($oldQty*(float)$product['avg_cost'])+($qty*$cost))/$newQty:$cost;
        $item->execute([$purchaseId,$product['id'],$qty,$cost,$qty*$cost]);$purchaseItemId=(int)$db->lastInsertId();
        $change=!empty($line['update_prices']);$update->execute([$newCost,$newQty,$change,$line['retail_price']??0,$change,$line['wholesale_price']??0,$product['id']]);
        $move->execute([$product['id'],$qty,$cost,$newQty,$purchaseId,user()['id']]);
        foreach(($line['serials']??[]) as $serial){if(trim($serial)==='')continue;$db->prepare('INSERT INTO product_serials(product_id,serial_number,status,purchase_item_id) VALUES(?,?,"in_stock",?)')->execute([$product['id'],trim($serial),$purchaseItemId]);}
    }
    if($balance>0)$db->prepare('UPDATE suppliers SET outstanding=outstanding+? WHERE id=?')->execute([$balance,$supplierId]);
    if($paid>0)$db->prepare('INSERT INTO supplier_payments(payment_no,supplier_id,purchase_id,payment_date,amount,method,reference_no,notes,created_by) VALUES(?,?,?,NOW(),?,?,?,?,?)')->execute([next_number($db,'supplier_payments','SPAY'),$supplierId,$purchaseId,$paid,$header['payment_method']??'cash',$header['reference_no']??'','Initial purchase payment',user()['id']]);
    audit($db,'create','purchase',$purchaseId,null,['purchase_no'=>$purchaseNo,'total'=>$total,'balance'=>$balance]);
    return $purchaseId;
}
