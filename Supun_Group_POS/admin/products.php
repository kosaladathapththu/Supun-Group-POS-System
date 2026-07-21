<?php
session_start();
include '../db.php';

if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["role"], ["admin", "accountant"], true)) {
    header("Location: ../login.php"); exit;
}

$msg = ""; $msg_type = "";
if (isset($_GET['imported'])) { $msg = (int)$_GET['imported'] . ' inventory lines processed successfully. New items were created and existing item stock was increased.'; $msg_type = 'success'; }

/* ── ADD PRODUCT ── */
if (isset($_POST['add_product'])) {
    $cat_name=trim($_POST['category_name']??''); $prod_name=trim($_POST['product_name']??'');
    $supplier_id_input=(int)($_POST['supplier_id']??0);$supplier_code=trim($_POST['supplier_code']??''); $supplier_name=trim($_POST['supplier_name']??''); $supplier_phone=trim($_POST['supplier_phone']??'');
    $supplier_invoice=trim($_POST['supplier_invoice']??''); $purchase_date=trim($_POST['purchase_date']??'')?:date('Y-m-d');
    $sku=trim($_POST['sku']??''); $barcode=trim($_POST['barcode']??''); $serial=trim($_POST['serial_no']??''); $brand=trim($_POST['brand']??''); $unit=trim($_POST['unit']??'pcs')?:'pcs';
    $cost=max(0,(float)($_POST['cost_price']??0)); $price=max(0,(float)($_POST['price']??0)); $wholesale=max(0,(float)($_POST['wholesale_price']??0));
    $min_qty=max(1,(int)($_POST['wholesale_min_qty']??1)); $stock=max(0,(float)($_POST['stock_qty']??0)); $reorder=max(0,(float)($_POST['reorder_level']??5)); $status=(int)($_POST['status']??1);
    $invoice_pending=isset($_POST['new_product_invoice_pending']);
    if($cat_name===''||$prod_name===''||(!$invoice_pending&&$supplier_name==='')||$price<=0||$stock<=0){$msg='Enter the category, product name, retail price and purchase quantity'.($invoice_pending?'.':', including the supplier.');$msg_type='error';}
    else{
        $conn->begin_transaction();
        try{
            $find=$conn->prepare("SELECT category_id FROM categories WHERE category_name=? LIMIT 1");$find->bind_param('s',$cat_name);$find->execute();$cat=$find->get_result()->fetch_assoc();$find->close();
            if($cat){$category_id=(int)$cat['category_id'];}else{$add=$conn->prepare("INSERT INTO categories(category_name,status) VALUES(?,1)");$add->bind_param('s',$cat_name);$add->execute();$category_id=$conn->insert_id;$add->close();}
            if($invoice_pending){
                $insert=$conn->prepare("INSERT INTO products(category_id,sku,barcode,serial_no,product_name,brand,unit,cost_price,price,wholesale_price,wholesale_min_qty,stock_qty,reorder_level,status) VALUES(?,NULLIF(?,''),NULLIF(?,''),NULLIF(?,''),?,NULLIF(?,''),?,?,?,?,?,?,?,?)");
                $pending_cost=0.0;$insert->bind_param('issssssdddiddi',$category_id,$sku,$barcode,$serial,$prod_name,$brand,$unit,$pending_cost,$price,$wholesale,$min_qty,$stock,$reorder,$status);$insert->execute();$product_id=$conn->insert_id;$insert->close();
                $uid=(int)$_SESSION['user_id'];$pending_note='New product received before supplier invoice';$invoice_status='pending';$log=$conn->prepare("INSERT INTO stock_adjustments(product_id,user_id,adjustment_type,quantity,stock_before,stock_after,unit_cost,total_cost,note,invoice_status) VALUES(?,?,'stock_in',?,0,?,0,0,?,?)");$log->bind_param('iiddss',$product_id,$uid,$stock,$stock,$pending_note,$invoice_status);$log->execute();$log->close();
                $conn->commit();$msg='Product and physical stock added. Its supplier invoice is pending and can be completed below.';$msg_type='success';
            }else{
            $supplier=null;if($supplier_id_input>0){$find=$conn->prepare('SELECT supplier_id FROM suppliers WHERE supplier_id=? AND status=1 LIMIT 1');$find->bind_param('i',$supplier_id_input);$find->execute();$supplier=$find->get_result()->fetch_assoc();$find->close();}
            if($supplier_code!==''){$find=$conn->prepare("SELECT supplier_id FROM suppliers WHERE supplier_code=? LIMIT 1");$find->bind_param('s',$supplier_code);$find->execute();$supplier=$find->get_result()->fetch_assoc();$find->close();}
            if(!$supplier){$find=$conn->prepare("SELECT supplier_id FROM suppliers WHERE supplier_name=? LIMIT 1");$find->bind_param('s',$supplier_name);$find->execute();$supplier=$find->get_result()->fetch_assoc();$find->close();}
            if($supplier){$supplier_id=(int)$supplier['supplier_id'];}else{$add=$conn->prepare("INSERT INTO suppliers(supplier_code,supplier_name,phone,status) VALUES(NULLIF(?,''),?,?,1)");$add->bind_param('sss',$supplier_code,$supplier_name,$supplier_phone);$add->execute();$supplier_id=$conn->insert_id;$add->close();}
            $uid=(int)$_SESSION['user_id'];$notes='Created from manual unified inventory entry';
            $add=$conn->prepare("INSERT INTO purchases(supplier_id,supplier_invoice,purchase_date,notes,created_by) VALUES(?,NULLIF(?,''),?,?,?)");$add->bind_param('isssi',$supplier_id,$supplier_invoice,$purchase_date,$notes,$uid);$add->execute();$purchase_id=$conn->insert_id;$add->close();
            $purchase_number='PUR-'.str_pad((string)$purchase_id,6,'0',STR_PAD_LEFT);$number=$conn->prepare("UPDATE purchases SET purchase_number=? WHERE purchase_id=?");$number->bind_param('si',$purchase_number,$purchase_id);$number->execute();$number->close();
            $insert=$conn->prepare("INSERT INTO products(category_id,sku,barcode,serial_no,product_name,brand,unit,cost_price,price,wholesale_price,wholesale_min_qty,stock_qty,reorder_level,status) VALUES(?,NULLIF(?,''),NULLIF(?,''),NULLIF(?,''),?,NULLIF(?,''),?,?,?,?,?,?,?,?)");
            $insert->bind_param('issssssdddiddi',$category_id,$sku,$barcode,$serial,$prod_name,$brand,$unit,$cost,$price,$wholesale,$min_qty,$stock,$reorder,$status);$insert->execute();$product_id=$conn->insert_id;$insert->close();
            $line_total=$stock*$cost;$item=$conn->prepare("INSERT INTO purchase_items(purchase_id,product_id,quantity,received_qty,unit_cost,line_total) VALUES(?,?,?,?,?,?)");$item->bind_param('iidddd',$purchase_id,$product_id,$stock,$stock,$cost,$line_total);$item->execute();$item->close();
            $finish=$conn->prepare("UPDATE purchases SET subtotal=?,total_amount=?,status='received',received_by=?,received_at=NOW() WHERE purchase_id=?");$finish->bind_param('ddii',$line_total,$line_total,$uid,$purchase_id);$finish->execute();$finish->close();
            $note='Received from manual inventory entry - '.$purchase_number;$log=$conn->prepare("INSERT INTO stock_adjustments(product_id,user_id,adjustment_type,quantity,stock_before,stock_after,unit_cost,total_cost,note) VALUES(?,?,'stock_in',?,0,?,?,?,?)");$log->bind_param('iidddds',$product_id,$uid,$stock,$stock,$cost,$line_total,$note);$log->execute();$log->close();
            $conn->commit();$msg="Product, supplier purchase and stock added successfully ($purchase_number).";$msg_type='success';
            }
        }catch(Throwable $e){$conn->rollback();$msg=$e->getCode()===1062?'The item code, barcode or serial number already exists.':$e->getMessage();$msg_type='error';}
    }
}

/* ── EDIT PRODUCT ── */
if (isset($_POST['edit_product'])) {
    $id        = (int)$_POST['edit_id'];
    $cat_name  = trim($conn->real_escape_string($_POST['category_name']));
    $prod_name = trim($conn->real_escape_string($_POST['product_name']));
    $price     = (float)$_POST['price'];
    $sku = trim($conn->real_escape_string($_POST['sku'] ?? '')); $barcode=trim($conn->real_escape_string($_POST['barcode']??'')); $serial=trim($conn->real_escape_string($_POST['serial_no']??'')); $brand=trim($conn->real_escape_string($_POST['brand']??'')); $cost=(float)($_POST['cost_price']??0); $wholesale=(float)($_POST['wholesale_price']??0); $min_qty=max(1,(int)($_POST['wholesale_min_qty']??10)); $stock=max(0,(float)($_POST['stock_qty']??0)); $reorder=max(0,(float)($_POST['reorder_level']??5)); $unit=trim($conn->real_escape_string($_POST['unit']??'pcs'));
    $status    = (int)($_POST['status'] ?? 1);

    if ($cat_name !== "" && $prod_name !== "" && $price > 0) {
        $cat_q = $conn->query("SELECT category_id FROM categories WHERE category_name='$cat_name' LIMIT 1");
        if ($cat_q->num_rows > 0) {
            $category_id = $cat_q->fetch_assoc()['category_id'];
        } else {
            $conn->query("INSERT INTO categories (category_name, status) VALUES ('$cat_name', 1)");
            $category_id = $conn->insert_id;
        }
        $sku_sql=$sku===''?'NULL':"'$sku'";$barcode_sql=$barcode===''?'NULL':"'$barcode'";$serial_sql=$serial===''?'NULL':"'$serial'";$brand_sql=$brand===''?'NULL':"'$brand'"; $conn->query("UPDATE products SET category_id=$category_id,sku=$sku_sql,barcode=$barcode_sql,serial_no=$serial_sql,brand=$brand_sql,product_name='$prod_name',unit='$unit',cost_price=$cost,price=$price,wholesale_price=$wholesale,wholesale_min_qty=$min_qty,reorder_level=$reorder,status=$status WHERE product_id=$id");
        $msg = "Product updated."; $msg_type = "success";
    }
}

/* ── DELETE ── */
if (isset($_POST['adjust_stock'])) {
    $product_id = (int)($_POST['stock_product_id'] ?? 0);
    $action = $_POST['stock_action'] ?? 'stock_in';
    $quantity = max(0, (float)($_POST['stock_quantity'] ?? 0));
    $purchase_unit_cost = max(0, (float)($_POST['purchase_unit_cost'] ?? 0));
    $note = trim($_POST['stock_note'] ?? '');
    $invoice_pending = $action === 'stock_in' && isset($_POST['invoice_pending']);
    $allowed_actions = ['stock_in', 'stock_out', 'set'];
    if ($product_id > 0 && in_array($action, $allowed_actions, true) && ($quantity > 0 || $action === 'set')) {
        $conn->begin_transaction();
        try {
            $lock = $conn->prepare("SELECT stock_qty,cost_price FROM products WHERE product_id=? FOR UPDATE");
            $lock->bind_param('i', $product_id); $lock->execute();
            $stock_row = $lock->get_result()->fetch_assoc(); $lock->close();
            if (!$stock_row) throw new Exception('Product not found.');
            $before = (float)$stock_row['stock_qty'];
            $after = $action === 'stock_in' ? $before + $quantity : ($action === 'stock_out' ? $before - $quantity : $quantity);
            if ($after < 0) throw new Exception('Cannot remove more stock than is available.');
            $unit_cost = $action === 'stock_in' && !$invoice_pending ? ($purchase_unit_cost > 0 ? $purchase_unit_cost : (float)$stock_row['cost_price']) : 0;
            $total_cost = $action === 'stock_in' && !$invoice_pending ? $unit_cost * $quantity : 0;
            $update = $conn->prepare("UPDATE products SET stock_qty=?,cost_price=CASE WHEN ?='stock_in' AND ?>0 THEN ? ELSE cost_price END WHERE product_id=?");
            $update->bind_param('dsddi', $after, $action, $unit_cost, $unit_cost, $product_id); $update->execute(); $update->close();
            $user_id = (int)$_SESSION['user_id'];
            $invoice_status = $invoice_pending ? 'pending' : 'not_required';
            $log = $conn->prepare("INSERT INTO stock_adjustments (product_id,user_id,adjustment_type,quantity,stock_before,stock_after,unit_cost,total_cost,note,invoice_status) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $log->bind_param('iisdddddss', $product_id, $user_id, $action, $quantity, $before, $after, $unit_cost, $total_cost, $note, $invoice_status); $log->execute(); $log->close();
            $conn->commit();
            $msg = $invoice_pending ? 'Stock received. Supplier invoice is pending and can be completed below without adding stock again.' : 'Stock updated successfully.'; $msg_type = 'success';
        } catch (Throwable $e) {
            $conn->rollback(); $msg = $e->getMessage(); $msg_type = 'error';
        }
    } else { $msg = 'Select a product and enter a valid quantity.'; $msg_type = 'error'; }
}

/* COMPLETE A SUPPLIER INVOICE FOR STOCK THAT WAS ALREADY RECEIVED */
if (isset($_POST['finalize_pending_invoice'])) {
    $adjustment_id=(int)($_POST['adjustment_id']??0);$supplier_id_input=(int)($_POST['pending_supplier_id']??0);$supplier_code=trim($_POST['pending_supplier_code']??'');$supplier_name=trim($_POST['pending_supplier_name']??'');$supplier_phone=trim($_POST['pending_supplier_phone']??'');
    $supplier_invoice=trim($_POST['pending_supplier_invoice']??'');$invoice_date=trim($_POST['pending_invoice_date']??'')?:date('Y-m-d');$unit_cost=max(0,(float)($_POST['pending_unit_cost']??0));$payment_method=trim($_POST['pending_payment_method']??'Cash');$is_paid=isset($_POST['pending_paid']);
    if($adjustment_id<=0||$supplier_name===''||$supplier_invoice===''||$unit_cost<=0){$msg='Enter the supplier, invoice number and actual cost per item.';$msg_type='error';}
    else{$conn->begin_transaction();try{
        $lock=$conn->prepare("SELECT sa.*,p.product_name FROM stock_adjustments sa JOIN products p ON p.product_id=sa.product_id WHERE sa.adjustment_id=? AND sa.adjustment_type='stock_in' AND sa.invoice_status='pending' FOR UPDATE");$lock->bind_param('i',$adjustment_id);$lock->execute();$pending=$lock->get_result()->fetch_assoc();$lock->close();if(!$pending)throw new RuntimeException('This pending stock invoice was already completed or no longer exists.');
        $supplier=null;if($supplier_id_input>0){$find=$conn->prepare('SELECT supplier_id FROM suppliers WHERE supplier_id=? AND status=1 LIMIT 1');$find->bind_param('i',$supplier_id_input);$find->execute();$supplier=$find->get_result()->fetch_assoc();$find->close();}if(!$supplier&&$supplier_code!==''){$find=$conn->prepare('SELECT supplier_id FROM suppliers WHERE supplier_code=? LIMIT 1');$find->bind_param('s',$supplier_code);$find->execute();$supplier=$find->get_result()->fetch_assoc();$find->close();}if(!$supplier){$find=$conn->prepare('SELECT supplier_id FROM suppliers WHERE supplier_name=? LIMIT 1');$find->bind_param('s',$supplier_name);$find->execute();$supplier=$find->get_result()->fetch_assoc();$find->close();}
        if($supplier){$supplier_id=(int)$supplier['supplier_id'];}else{$add=$conn->prepare("INSERT INTO suppliers(supplier_code,supplier_name,phone,status) VALUES(NULLIF(?,''),?,?,1)");$add->bind_param('sss',$supplier_code,$supplier_name,$supplier_phone);$add->execute();$supplier_id=$conn->insert_id;$add->close();}
        $quantity=(float)$pending['quantity'];$total=$quantity*$unit_cost;$uid=(int)$_SESSION['user_id'];$paid_amount=$is_paid?$total:0;$payment_status=$is_paid?'paid':'unpaid';$notes='Invoice completed for stock received earlier. Adjustment #'.$adjustment_id;
        $purchase=$conn->prepare("INSERT INTO purchases(supplier_id,supplier_invoice,purchase_date,status,payment_status,subtotal,total_amount,paid_amount,notes,created_by,received_by,received_at) VALUES(?,? ,?,'received',?,?,?,?,?,?,?,NOW())");$purchase->bind_param('isssdddsii',$supplier_id,$supplier_invoice,$invoice_date,$payment_status,$total,$total,$paid_amount,$notes,$uid,$uid);$purchase->execute();$purchase_id=$conn->insert_id;$purchase->close();
        $purchase_number='PUR-'.str_pad((string)$purchase_id,6,'0',STR_PAD_LEFT);$number=$conn->prepare('UPDATE purchases SET purchase_number=? WHERE purchase_id=?');$number->bind_param('si',$purchase_number,$purchase_id);$number->execute();$number->close();
        $product_id=(int)$pending['product_id'];$item=$conn->prepare('INSERT INTO purchase_items(purchase_id,product_id,quantity,received_qty,unit_cost,line_total) VALUES(?,?,?,?,?,?)');$item->bind_param('iidddd',$purchase_id,$product_id,$quantity,$quantity,$unit_cost,$total);$item->execute();$item->close();
        if($is_paid){$pay=$conn->prepare('INSERT INTO purchase_payments(purchase_id,payment_date,amount,payment_method,reference_no,notes,added_by) VALUES(?,?,?,?,?, ?,?)');$pay_note='Payment recorded when pending invoice was completed';$pay->bind_param('isdsssi',$purchase_id,$invoice_date,$total,$payment_method,$supplier_invoice,$pay_note,$uid);$pay->execute();$pay->close();}
        $product=$conn->prepare('UPDATE products SET cost_price=? WHERE product_id=?');$product->bind_param('di',$unit_cost,$product_id);$product->execute();$product->close();
        $finish=$conn->prepare("UPDATE stock_adjustments SET unit_cost=?,total_cost=?,invoice_status='finalized',supplier_id=?,purchase_id=?,supplier_invoice=?,invoice_date=?,finalized_at=NOW(),note=CONCAT(COALESCE(note,''),' | Invoice finalized: ',?) WHERE adjustment_id=?");$finish->bind_param('ddiisssi',$unit_cost,$total,$supplier_id,$purchase_id,$supplier_invoice,$invoice_date,$purchase_number,$adjustment_id);$finish->execute();$finish->close();
        $conn->commit();$msg="Invoice completed as $purchase_number. Stock quantity was not changed; Rs. ".number_format($total,2).' was added to purchase expenses.';$msg_type='success';
    }catch(Throwable $e){$conn->rollback();$msg='Invoice could not be completed: '.$e->getMessage();$msg_type='error';}}
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $conn->query("DELETE FROM products WHERE product_id=$id");
    $msg = "Product deleted."; $msg_type = "warning";
}

/* ── TOGGLE STATUS ── */
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $conn->query("UPDATE products SET status=IF(status=1,0,1) WHERE product_id=$id");
    header("Location: products.php"); exit;
}

/* ── EDIT ROW ── */
$edit_row = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $edit_row = $conn->query("
        SELECT p.*, c.category_name
        FROM products p
        JOIN categories c ON p.category_id = c.category_id
        WHERE p.product_id = $eid
    ")->fetch_assoc();
}

/* ── SEARCH / FILTER ── */
$search      = trim($_GET['search'] ?? '');
$filter_cat  = (int)($_GET['cat'] ?? 0);
$filter_stat = $_GET['stat'] ?? '';

$where = ["1=1"];
if ($search) $where[] = "(p.product_name LIKE '%".$conn->real_escape_string($search)."%' OR p.sku LIKE '%".$conn->real_escape_string($search)."%')";
if ($filter_cat)  $where[] = "p.category_id = $filter_cat";
if ($filter_stat !== '') $where[] = "p.status = " . (int)$filter_stat;
$ws = implode(" AND ", $where);

$products = $conn->query("
    SELECT p.*, c.category_name
    FROM products p
    JOIN categories c ON p.category_id = c.category_id
    WHERE $ws
    ORDER BY p.product_id DESC
");

$categories = $conn->query("SELECT * FROM categories WHERE status=1 ORDER BY category_name ASC");
$supplier_options=[];$supplier_result=$conn->query("SELECT supplier_id,supplier_code,supplier_name,phone FROM suppliers WHERE status=1 ORDER BY supplier_name ASC");if($supplier_result)while($supplier_row=$supplier_result->fetch_assoc())$supplier_options[]=$supplier_row;
$stock_products = $conn->query("SELECT product_id,product_name,stock_qty,unit FROM products ORDER BY product_name ASC");
$stock_history = $conn->query("SELECT sa.*,p.product_name,u.full_name FROM stock_adjustments sa JOIN products p ON p.product_id=sa.product_id LEFT JOIN users u ON u.user_id=sa.user_id ORDER BY sa.adjustment_id DESC LIMIT 8");
$pending_invoices = $conn->query("SELECT sa.*,p.product_name,p.unit,u.full_name received_by_name FROM stock_adjustments sa JOIN products p ON p.product_id=sa.product_id LEFT JOIN users u ON u.user_id=sa.user_id WHERE sa.adjustment_type='stock_in' AND sa.invoice_status='pending' ORDER BY sa.created_at ASC");

/* Counts */
$total_active   = $conn->query("SELECT COUNT(*) AS v FROM products WHERE status=1")->fetch_assoc()['v'];
$total_inactive = $conn->query("SELECT COUNT(*) AS v FROM products WHERE status=0")->fetch_assoc()['v'];
$total_all      = $total_active + $total_inactive;
$total_stock    = (float)$conn->query("SELECT COALESCE(SUM(stock_qty),0) AS v FROM products")->fetch_assoc()['v'];
$low_stock      = (int)$conn->query("SELECT COUNT(*) AS v FROM products WHERE stock_qty<=reorder_level AND status=1")->fetch_assoc()['v'];
$inventory_cost = (float)$conn->query("SELECT COALESCE(SUM(cost_price*stock_qty),0) AS v FROM products")->fetch_assoc()['v'];
$potential_profit = (float)$conn->query("SELECT COALESCE(SUM((price-cost_price)*stock_qty),0) AS v FROM products")->fetch_assoc()['v'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventory — Supun Group</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Lora:wght@600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
<?php include 'shared_style.php'; ?>

/* ── Page-specific extras ── */
.stat-strip {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 18px;
}

.stat-tile {
    background: var(--white);
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    padding: 14px 16px;
    display: flex; align-items: center; gap: 12px;
    box-shadow: var(--shadow-sm);
}

.st-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}

.st-val { font-size: 20px; font-weight: 900; font-family: 'Lora', serif; color: var(--text); }
.st-lbl { font-size: 11px; font-weight: 700; color: var(--text-muted); }

/* Filter bar */
.filter-bar {
    background: var(--white);
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    padding: 13px 16px;
    margin-bottom: 14px;
    display: flex; flex-wrap: wrap; gap: 9px; align-items: flex-end;
    box-shadow: var(--shadow-sm);
}

.ff { display: flex; flex-direction: column; gap: 3px; }
.ff label { font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .09em; color: var(--text-muted); }
.ff input, .ff select {
    background: var(--bg); border: 1.5px solid var(--border);
    border-radius: var(--radius-sm); padding: 7px 11px;
    font-size: 13px; font-family: 'Nunito', sans-serif; font-weight: 700;
    color: var(--text); outline: none; min-width: 130px;
    transition: border-color .15s;
}
.ff input:focus, .ff select:focus { border-color: var(--primary); }

/* Price badge */
.price-badge {
    display: inline-block;
    background: var(--primary-lt);
    color: var(--primary);
    border: 1px solid #f9c4a6;
    border-radius: 6px;
    padding: 3px 9px;
    font-size: 13px;
    font-weight: 900;
}

/* Category chip */
.cat-chip {
    display: inline-block;
    background: var(--indigo-lt);
    color: var(--indigo);
    border: 1px solid #c7d2fe;
    border-radius: 40px;
    padding: 2px 9px;
    font-size: 11px;
    font-weight: 800;
}
.price-stack{display:flex;flex-direction:column;gap:3px;white-space:nowrap}.price-stack strong{color:var(--primary);font-size:13px}.price-stack small{color:var(--text-muted);font-size:10px;font-weight:800}.stock-value{display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:900}.stock-low{color:var(--red)}.stock-ok{color:var(--green)}
.profit-preview{grid-column:span 4;display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:11px;border:1px solid #bbf7d0;background:var(--green-lt);border-radius:var(--radius-sm)}.profit-item{display:flex;align-items:center;justify-content:space-between;gap:10px}.profit-item span{font-size:11px;font-weight:800;color:var(--text-mid)}.profit-item strong{font-family:'Lora',serif;font-size:16px;color:var(--green)}.profit-negative strong{color:var(--red)}

/* Sticky form card */
.form-sticky { position: sticky; top: calc(var(--topbar-h) + 16px); }
.inventory-stack{display:flex;flex-direction:column;gap:16px;}
.product-form{display:flex;flex-direction:column;gap:14px;}
.simple-fields{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;align-items:start;}
.simple-fields .field{margin:0;}
.span-2{grid-column:span 2;}
.form-section-title{grid-column:1/-1;padding:9px 12px;border-radius:8px;background:var(--primary-lt);color:var(--primary);font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.05em}.form-section-title small{display:block;margin-top:2px;color:var(--text-muted);font-size:10px;text-transform:none;letter-spacing:0}
.advanced-box{border:1.5px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);overflow:hidden;}
.advanced-box summary{list-style:none;cursor:pointer;padding:11px 13px;font-size:12px;font-weight:900;color:var(--text-mid);display:flex;align-items:center;justify-content:space-between;gap:8px;}
.advanced-box summary::-webkit-details-marker{display:none;}
.advanced-box summary::after{content:'+';font-size:18px;color:var(--primary);line-height:1;}
.advanced-box[open] summary::after{content:'−';}
.advanced-box[open] summary{border-bottom:1px solid var(--border);background:var(--white);}
.advanced-fields{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;padding:13px;}
.advanced-fields .field{margin:0;}
.form-actions{display:flex;gap:8px;justify-content:flex-end;}
.stock-manager{margin-bottom:18px;overflow:hidden}.stock-form{display:grid;grid-template-columns:2fr 1fr 1fr 2fr auto;gap:10px;align-items:end}.stock-form .field{margin:0}.stock-history{border-top:1px solid var(--border);padding:10px 18px;background:#fafcfd}.stock-history-title{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:7px}.history-list{display:flex;gap:7px;overflow-x:auto;padding-bottom:2px}.history-chip{min-width:190px;background:#fff;border:1px solid var(--border);border-radius:7px;padding:7px 9px;font-size:10px;color:var(--text-mid)}.history-chip strong{display:block;font-size:11px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.history-chip .plus{color:var(--green);font-weight:900}.history-chip .minus{color:var(--red);font-weight:900}
@media(max-width:1100px){.simple-fields{grid-template-columns:repeat(2,1fr)}.advanced-fields{grid-template-columns:repeat(3,1fr)}.profit-preview{grid-column:span 2}}
@media(max-width:1000px){.stock-form{grid-template-columns:1fr 1fr}.stock-form .product-choice,.stock-form .stock-note{grid-column:span 2}}
@media(max-width:650px){.simple-fields,.advanced-fields{grid-template-columns:1fr}.span-2,.profit-preview{grid-column:span 1}.profit-preview{grid-template-columns:1fr}.form-actions{flex-direction:column}.form-actions>*{width:100%!important;justify-content:center}}
.stock-form{grid-template-columns:2fr 1fr 1fr 1fr 2fr auto;}
.beginner-guide{background:linear-gradient(135deg,#ecfdf5,#f8fafc);border:1.5px solid #99f6e4;border-radius:var(--radius);padding:16px;margin-bottom:18px}.beginner-guide h3{font-size:15px;margin-bottom:4px}.beginner-guide>p{font-size:12px;color:var(--text-mid);margin-bottom:12px}.beginner-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.beginner-action{display:flex;align-items:center;gap:11px;text-align:left;padding:13px;border:1.5px solid var(--border);border-radius:10px;background:#fff;color:var(--text);text-decoration:none;cursor:pointer;font-family:'Nunito',sans-serif;transition:.15s}.beginner-action:hover{border-color:var(--primary);transform:translateY(-1px);box-shadow:var(--shadow-sm)}.beginner-action.recommended{border-color:var(--primary);background:var(--primary-lt)}.beginner-action .ba-icon{width:38px;height:38px;border-radius:9px;display:grid;place-items:center;background:var(--primary-lt);color:var(--primary);font-size:16px;flex:none}.beginner-action strong{display:block;font-size:13px}.beginner-action small{display:block;color:var(--text-muted);font-size:10px;margin-top:2px}.recommended-tag{display:inline-block;font-size:8px;text-transform:uppercase;color:#fff;background:var(--primary);padding:2px 6px;border-radius:10px;margin-left:5px}.collapsible-panel{display:none}.collapsible-panel.panel-open{display:block}.panel-close{border:0;background:var(--bg);color:var(--text-muted);border-radius:7px;padding:6px 9px;font-size:11px;font-weight:900;cursor:pointer}.panel-help{padding:10px 18px;background:var(--sky-lt);color:var(--text-mid);font-size:11px;font-weight:700;border-bottom:1px solid #bae6fd}.panel-help i{color:var(--sky);margin-right:5px}
@media(max-width:1000px){.stock-form{grid-template-columns:1fr 1fr}.stock-form .product-choice,.stock-form .stock-note{grid-column:span 2}}
@media(max-width:800px){.beginner-actions{grid-template-columns:1fr}}@media(max-width:600px){.stock-form{grid-template-columns:1fr}.stock-form .product-choice,.stock-form .stock-note{grid-column:span 1}.stock-form .btn-primary{width:100%;justify-content:center}}
</style>
</head>
<body>

<?php include 'shared_nav.php'; ?>

<div class="main">
<?php include 'shared_topbar.php'; ?>

<div class="content">

    <!-- Page header -->
    <div class="page-header">
        <div>
            <h2 class="page-title-h"><i class="fa-solid fa-boxes-stacked"></i> Inventory &amp; Products</h2>
            <p class="page-sub">Manage products, stock levels, retail and wholesale pricing</p>
        </div>
        <div class="quick-links"><a class="btn-secondary" target="_blank" href="print_report.php?type=inventory"><i class="fa-solid fa-file-pdf"></i> Inventory Report</a></div>
    </div>

    <!-- Alert -->
    <?php if ($msg): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <i class="fa-solid <?php echo $msg_type=='success'?'fa-circle-check':($msg_type=='warning'?'fa-triangle-exclamation':'fa-circle-exclamation'); ?>"></i>
        <?php echo htmlspecialchars($msg); ?>
    </div>
    <?php endif; ?>

    <section class="beginner-guide">
        <h3>What would you like to do?</h3>
        <p>Choose one option. Only the form you need will be shown.</p>
        <div class="beginner-actions">
            <a href="product_import.php" class="beginner-action recommended"><span class="ba-icon"><i class="fa-solid fa-file-excel"></i></span><span><strong>Import Inventory <span class="recommended-tag">Recommended</span></strong><small>Add suppliers, products and stock together from Excel</small></span></a>
            <button type="button" class="beginner-action" onclick="openInventoryPanel('productFormCard')"><span class="ba-icon"><i class="fa-solid fa-box"></i></span><span><strong>Add One Product</strong><small>Use this when you only have one new item</small></span></button>
            <button type="button" class="beginner-action" onclick="openInventoryPanel('stockPanel')"><span class="ba-icon"><i class="fa-solid fa-cubes-stacked"></i></span><span><strong>Correct Existing Stock</strong><small>Add, remove or correct the quantity of an existing product</small></span></button>
        </div>
    </section>

    <!-- Stat strip -->
    <div class="stat-strip">
        <div class="stat-tile">
            <div class="st-icon" style="background:var(--primary-lt);color:var(--primary);"><i class="fa-solid fa-boxes-stacked"></i></div>
            <div><div class="st-val"><?php echo $total_all; ?></div><div class="st-lbl">Total Products</div></div>
        </div>
        <div class="stat-tile">
            <div class="st-icon" style="background:var(--green-lt);color:var(--green);"><i class="fa-solid fa-cubes"></i></div>
            <div><div class="st-val"><?php echo number_format($total_stock,0); ?></div><div class="st-lbl">Total Units in Stock</div></div>
        </div>
        <div class="stat-tile">
            <div class="st-icon" style="background:var(--amber-lt);color:var(--amber);"><i class="fa-solid fa-wallet"></i></div>
            <div><div class="st-val" style="font-size:16px;">Rs. <?php echo number_format($inventory_cost,0); ?></div><div class="st-lbl">Inventory Cost</div></div>
        </div>
        <div class="stat-tile">
            <div class="st-icon" style="background:var(--green-lt);color:var(--green);"><i class="fa-solid fa-arrow-trend-up"></i></div>
            <div><div class="st-val" style="font-size:16px;color:var(--green);">Rs. <?php echo number_format($potential_profit,0); ?></div><div class="st-lbl">Potential Retail Profit</div></div>
        </div>
    </div>

    <?php if($pending_invoices && $pending_invoices->num_rows): ?>
    <section class="card" style="margin-bottom:18px;border:1.5px solid #f6c56f">
        <div class="card-head"><h4><i class="fa-solid fa-file-circle-exclamation"></i> Pending Supplier Invoices</h4><span class="count-badge"><?php echo $pending_invoices->num_rows; ?> waiting</span></div>
        <div class="panel-help"><i class="fa-solid fa-circle-info"></i> These quantities are already in stock. Completing an invoice records its purchase cost and expense only—it will not add stock again.</div>
        <div class="card-body" style="display:grid;gap:12px">
        <?php while($pi=$pending_invoices->fetch_assoc()): ?>
            <form method="post" class="supplier-form" style="border:1px solid var(--border);border-radius:10px;padding:13px;background:#fafcfd">
                <input type="hidden" name="adjustment_id" value="<?php echo (int)$pi['adjustment_id']; ?>">
                <input type="hidden" name="pending_supplier_id" class="supplier-id" value="">
                <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:10px;flex-wrap:wrap"><strong><?php echo htmlspecialchars($pi['product_name']); ?> — <?php echo number_format((float)$pi['quantity'],3); ?> <?php echo htmlspecialchars($pi['unit']); ?></strong><span class="muted">Received <?php echo date('d M Y, h:i A',strtotime($pi['created_at'])); ?><?php if($pi['note']): ?> · <?php echo htmlspecialchars($pi['note']); ?><?php endif; ?></span></div>
                <div style="display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:9px">
                    <div class="field" style="grid-column:span 3"><label>Find Existing Supplier</label><input class="inp supplier-search" list="supplierDirectory" placeholder="Type supplier name, code or phone" autocomplete="off"><small class="supplier-hint">Choose a suggestion, or use New Supplier.</small></div>
                    <div class="field"><label>&nbsp;</label><button type="button" class="btn-secondary" onclick="clearSupplierSelection(this)" style="height:44px"><i class="fa-solid fa-user-plus"></i> New Supplier</button></div>
                    <div class="field"><label>Supplier Code</label><input class="inp supplier-code" name="pending_supplier_code" placeholder="SUP-001"></div>
                    <div class="field"><label>Supplier Name *</label><input class="inp supplier-name" name="pending_supplier_name" required></div>
                    <div class="field"><label>Supplier Phone</label><input class="inp supplier-phone" name="pending_supplier_phone"></div>
                    <div class="field"><label>Invoice Number *</label><input class="inp" name="pending_supplier_invoice" required></div>
                    <div class="field"><label>Invoice Date *</label><input class="inp" type="date" name="pending_invoice_date" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="field"><label>Actual Cost Per Item *</label><input class="inp" type="number" min="0.01" step="0.01" name="pending_unit_cost" required></div>
                    <div class="field"><label>Payment Method</label><select class="inp" name="pending_payment_method"><option>Cash</option><option>Card</option><option>Bank Transfer</option><option>Cheque</option><option>Credit</option></select></div>
                    <div class="field"><label style="display:flex;gap:7px;align-items:center;margin-top:26px;text-transform:none;letter-spacing:0"><input type="checkbox" name="pending_paid" value="1"> Invoice already paid</label></div>
                </div>
                <button class="btn-primary" name="finalize_pending_invoice" style="margin-top:10px" onclick="return confirm('Complete this invoice without changing the stock quantity?')"><i class="fa-solid fa-file-circle-check"></i> Complete Invoice &amp; Record Expense</button>
            </form>
        <?php endwhile; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="card stock-manager collapsible-panel <?php echo isset($_POST['adjust_stock'])?'panel-open':''; ?>" id="stockPanel">
        <div class="card-head"><h4><i class="fa-solid fa-warehouse"></i> Correct Existing Stock</h4><button type="button" class="panel-close" onclick="closeInventoryPanel('stockPanel')"><i class="fa-solid fa-xmark"></i> Close</button></div>
        <div class="panel-help"><i class="fa-solid fa-circle-info"></i> Select the product, choose what happened, enter the quantity, and click Save Stock Change.</div>
        <div class="card-body">
            <form method="POST" class="stock-form">
                <div class="field product-choice"><label>Product</label><select name="stock_product_id" class="inp" required><option value="">Select product</option><?php while($sp=$stock_products->fetch_assoc()): ?><option value="<?php echo (int)$sp['product_id']; ?>"><?php echo htmlspecialchars($sp['product_name']); ?> — <?php echo number_format($sp['stock_qty'],0).' '.htmlspecialchars($sp['unit']); ?></option><?php endwhile; ?></select></div>
                <div class="field"><label>What happened?</label><select name="stock_action" id="stockAction" class="inp" onchange="updateStockFormHelp()"><option value="stock_in">Stock was added</option><option value="stock_out">Stock was removed / damaged</option><option value="set">Correct to an exact quantity</option></select></div>
                <div class="field"><label id="stockQtyLabel">Quantity added</label><input type="number" name="stock_quantity" class="inp" min="0" step="1" placeholder="Enter quantity" required></div>
                <div class="field" id="costField"><label>Cost per item (optional)</label><input type="number" name="purchase_unit_cost" class="inp" min="0" step="0.01" placeholder="Leave empty to keep current cost"></div>
                <div class="field stock-note"><label>Reason / Reference</label><input type="text" name="stock_note" class="inp" maxlength="255" placeholder="e.g. Delivery note or temporary reference"><label style="display:flex;gap:7px;align-items:center;margin-top:8px;text-transform:none;letter-spacing:0"><input type="checkbox" name="invoice_pending" value="1"> Supplier invoice/details will be added later</label></div>
                <button type="submit" name="adjust_stock" class="btn-primary"><i class="fa-solid fa-check"></i> Save Stock Change</button>
            </form>
        </div>
        <?php if($stock_history && $stock_history->num_rows): ?><div class="stock-history"><div class="stock-history-title">Recent stock adjustments</div><div class="history-list"><?php while($sh=$stock_history->fetch_assoc()): $delta=(float)$sh['stock_after']-(float)$sh['stock_before']; ?><div class="history-chip"><strong><?php echo htmlspecialchars($sh['product_name']); ?></strong><span class="<?php echo $delta>=0?'plus':'minus'; ?>"><?php echo $delta>=0?'+':''; ?><?php echo number_format($delta,0); ?></span> · now <?php echo number_format($sh['stock_after'],0); ?><br><?php echo date('d M, h:i A',strtotime($sh['created_at'])); ?><?php if($sh['note']): ?> · <?php echo htmlspecialchars($sh['note']); ?><?php endif; ?></div><?php endwhile; ?></div></div><?php endif; ?>
    </section>

    <!-- Main 2-col layout -->
    <div class="inventory-stack">

        <!-- ═══ FORM PANEL ═══ -->
        <div class="card form-card collapsible-panel <?php echo ($edit_row||isset($_POST['add_product']))?'panel-open':''; ?>" id="productFormCard">
            <div class="card-head">
                <h4>
                    <i class="fa-solid <?php echo $edit_row ? 'fa-pen' : 'fa-plus-circle'; ?>"></i>
                    <?php echo $edit_row ? 'Edit Product' : 'Add New Product'; ?>
                </h4>
                <button type="button" class="panel-close" onclick="closeInventoryPanel('productFormCard')"><i class="fa-solid fa-xmark"></i> Close</button>
            </div>
            <div class="panel-help"><i class="fa-solid fa-circle-info"></i> This form uses the same information as one row of the Inventory Excel template.</div>
            <div class="card-body">
                <form method="POST" class="product-form supplier-form">
                    <?php if ($edit_row): ?>
                        <input type="hidden" name="edit_id" value="<?php echo $edit_row['product_id']; ?>">
                    <?php endif; ?>

                    <div class="simple-fields">
                    <?php if (!$edit_row): ?>
                    <div class="form-section-title">1. Supplier &amp; Purchase <small>The supplier is reused if its code or name already exists.</small><label style="display:flex;align-items:center;gap:7px;margin-top:7px;text-transform:none;letter-spacing:0"><input type="checkbox" id="newProductInvoicePending" name="new_product_invoice_pending" value="1" onchange="toggleNewProductInvoice()" <?php echo isset($_POST['new_product_invoice_pending'])?'checked':''; ?>> Receive stock now — supplier invoice/details will be added later</label></div>
                    <input type="hidden" name="supplier_id" class="supplier-id" value="<?php echo (int)($_POST['supplier_id'] ?? 0); ?>">
                    <div class="field span-2"><label>Find Existing Supplier</label><input class="inp supplier-search" list="supplierDirectory" placeholder="Type supplier name, code or phone" autocomplete="off"><small class="supplier-hint">Select a matching supplier to fill the details automatically.</small></div>
                    <div class="field"><label>&nbsp;</label><button type="button" class="btn-secondary" onclick="clearSupplierSelection(this)" style="height:44px"><i class="fa-solid fa-user-plus"></i> New Supplier</button></div>
                    <div class="field"><label>Supplier Code</label><input class="inp supplier-code" name="supplier_code" placeholder="SUP-001" value="<?php echo htmlspecialchars($_POST['supplier_code'] ?? ''); ?>"></div>
                    <div class="field span-2"><label>Supplier Name <span id="supplierRequiredMark">*</span></label><input class="inp supplier-name" id="newProductSupplierName" name="supplier_name" placeholder="e.g. Cammy" required value="<?php echo htmlspecialchars($_POST['supplier_name'] ?? ''); ?>"></div>
                    <div class="field"><label>Supplier Phone</label><input class="inp supplier-phone" name="supplier_phone" placeholder="071 234 5678" value="<?php echo htmlspecialchars($_POST['supplier_phone'] ?? ''); ?>"></div>
                    <div class="field"><label>Supplier Invoice</label><input class="inp" name="supplier_invoice" placeholder="INV-1001" value="<?php echo htmlspecialchars($_POST['supplier_invoice'] ?? ''); ?>"></div>
                    <div class="field"><label>Purchase Date *</label><input class="inp" type="date" name="purchase_date" required value="<?php echo htmlspecialchars($_POST['purchase_date'] ?? date('Y-m-d')); ?>"></div>
                    <div class="field"><label>Purchase Quantity *</label><input class="inp" type="number" name="stock_qty" step="0.001" min="0.001" required value="<?php echo htmlspecialchars($_POST['stock_qty'] ?? '1'); ?>"></div>
                    <div class="form-section-title">2. Product &amp; Pricing <small>These fields match the product columns in the Excel template.</small></div>
                    <?php endif; ?>
                    <!-- Category -->
                    <div class="field span-2">
                        <label>Category</label>
                        <div class="inp-wrap">
                            <i class="fa-solid fa-tag"></i>
                            <input
                                type="text"
                                name="category_name"
                                class="inp"
                                list="cat_list"
                                placeholder="Type or pick a category"
                                value="<?php echo htmlspecialchars($edit_row['category_name'] ?? ''); ?>"
                                required
                                autocomplete="off"
                            >
                        </div>
                        <datalist id="cat_list">
                            <?php
                            if ($categories && $categories->num_rows > 0) {
                                mysqli_data_seek($categories, 0);
                                while ($c = $categories->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($c['category_name']) . '">';
                                }
                            }
                            ?>
                        </datalist>
                        <div style="font-size:11px;color:var(--text-muted);font-weight:700;margin-top:4px;">
                            <i class="fa-solid fa-circle-info"></i> Pick an existing category or type a new one.
                            <a href="categories.php" style="color:var(--primary);margin-left:5px;font-weight:900;">Manage Categories</a>
                        </div>
                    </div>

                    <!-- Product Name -->
                    <div class="field span-2">
                        <label>Product Name</label>
                        <div class="inp-wrap">
                            <i class="fa-solid fa-box"></i>
                            <input
                                type="text"
                                name="product_name"
                                class="inp"
                                placeholder="e.g. Inverter Air Conditioner 12000 BTU"
                                value="<?php echo htmlspecialchars($edit_row['product_name'] ?? ''); ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="field"><label>Cost / Buying Price (Rs.)</label><input class="inp" id="costPrice" type="number" name="cost_price" step="0.01" min="0" placeholder="0.00" value="<?php echo htmlspecialchars($edit_row['cost_price'] ?? '0.00'); ?>" oninput="calculateProductProfit()"></div>

                    <!-- Retail Price -->
                    <div class="field">
                        <label>Retail Price (Rs.)</label>
                        <div class="inp-wrap">
                            <i class="fa-solid fa-coins"></i>
                            <input
                                type="number"
                                name="price"
                                id="retailPrice"
                                class="inp"
                                step="0.01"
                                min="0.01"
                                placeholder="0.00"
                                value="<?php echo $edit_row ? number_format($edit_row['price'], 2, '.', '') : ''; ?>"
                                required
                                oninput="calculateProductProfit()"
                            >
                        </div>
                    </div>

                    <div class="field"><label>Wholesale Price (Rs.)</label><input class="inp" id="wholesalePrice" type="number" name="wholesale_price" step="0.01" min="0" placeholder="0.00" value="<?php echo htmlspecialchars($edit_row['wholesale_price'] ?? '0.00'); ?>" oninput="calculateProductProfit()"></div>
                    <?php if ($edit_row): ?><div class="field"><label>Current Stock</label><input class="inp" type="number" name="stock_qty" step="0.001" min="0" value="<?php echo htmlspecialchars($edit_row['stock_qty']); ?>" readonly title="Use Correct Existing Stock to change stock"></div><?php endif; ?>

                    <!-- Status -->
                    <div class="field">
                        <label>Status</label>
                        <select name="status" class="inp" style="padding-left:14px;">
                            <option value="1" <?php echo (!$edit_row || $edit_row['status']==1) ? 'selected' : ''; ?>>
                                Active (visible on POS)
                            </option>
                            <option value="0" <?php echo ($edit_row && $edit_row['status']==0) ? 'selected' : ''; ?>>
                                Inactive (hidden from POS)
                            </option>
                        </select>
                    </div>
                    <div class="profit-preview" id="profitPreview">
                        <div class="profit-item" id="retailProfitItem"><span>Profit per Retail Sale</span><strong>Rs. <span id="retailProfit">0.00</span></strong></div>
                        <div class="profit-item" id="wholesaleProfitItem"><span>Profit per Wholesale Sale</span><strong>Rs. <span id="wholesaleProfit">0.00</span></strong></div>
                    </div>
                    </div>

                    <details class="advanced-box" open>
                        <summary><span><i class="fa-solid fa-barcode"></i> Product codes &amp; additional template fields</span><small style="color:var(--text-muted);font-weight:700;">Optional</small></summary>
                        <div class="advanced-fields">
                            <div class="field"><label>Item Code / SKU</label><input class="inp" name="sku" placeholder="SG-001" value="<?php echo htmlspecialchars($edit_row['sku'] ?? ($_POST['sku'] ?? '')); ?>"></div>
                            <div class="field"><label>Barcode</label><input class="inp" name="barcode" placeholder="Scan or type" value="<?php echo htmlspecialchars($edit_row['barcode'] ?? ($_POST['barcode'] ?? '')); ?>"></div>
                            <div class="field"><label>Serial Number</label><input class="inp" name="serial_no" placeholder="Optional" value="<?php echo htmlspecialchars($edit_row['serial_no'] ?? ($_POST['serial_no'] ?? '')); ?>"></div>
                            <div class="field"><label>Brand</label><input class="inp" name="brand" placeholder="e.g. Supun
                            " value="<?php echo htmlspecialchars($edit_row['brand'] ?? ($_POST['brand'] ?? '')); ?>"></div>
                            <div class="field"><label>Unit</label><input class="inp" name="unit" placeholder="pcs" value="<?php echo htmlspecialchars($edit_row['unit'] ?? 'pcs'); ?>"></div>
                            <div class="field"><label>Wholesale Min Qty</label><input class="inp" type="number" name="wholesale_min_qty" min="1" value="<?php echo htmlspecialchars($edit_row['wholesale_min_qty'] ?? '1'); ?>"></div>
                            <div class="field"><label>Low-stock Alert</label><input class="inp" type="number" name="reorder_level" step="1" min="0" value="<?php echo htmlspecialchars($edit_row['reorder_level'] ?? '5'); ?>"></div>
                        </div>
                    </details>

                    <!-- Buttons -->
                    <div class="form-actions">
                    <?php if ($edit_row): ?>
                        <button type="submit" name="edit_product" class="btn-primary">
                            <i class="fa-solid fa-save"></i> Update Product
                        </button>
                        <a href="products.php" class="btn-secondary">
                            <i class="fa-solid fa-xmark"></i> Cancel Edit
                        </a>
                    <?php else: ?>
                        <button type="submit" name="add_product" class="btn-primary">
                            <i class="fa-solid fa-plus"></i> Add Product
                        </button>
                    <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- ═══ TABLE PANEL ═══ -->
        <div style="display:flex;flex-direction:column;gap:12px;">

            <!-- Filter bar -->
            <form method="GET" class="filter-bar">
                <div class="ff">
                    <label>Search</label>
                    <input type="text" name="search" placeholder="Product name…" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="ff">
                    <label>Category</label>
                    <select name="cat">
                        <option value="">All Categories</option>
                        <?php
                        $cat_filter_q = $conn->query("SELECT * FROM categories ORDER BY category_name ASC");
                        while ($c = $cat_filter_q->fetch_assoc()) {
                            $sel = $filter_cat == $c['category_id'] ? 'selected' : '';
                            echo "<option value='{$c['category_id']}' $sel>" . htmlspecialchars($c['category_name']) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="ff">
                    <label>Status</label>
                    <select name="stat">
                        <option value="">All</option>
                        <option value="1" <?php echo $filter_stat==='1'?'selected':''; ?>>Active</option>
                        <option value="0" <?php echo $filter_stat==='0'?'selected':''; ?>>Inactive</option>
                    </select>
                </div>
                <button type="submit" class="btn-primary" style="align-self:flex-end;">
                    <i class="fa-solid fa-magnifying-glass"></i> Filter
                </button>
                <a href="products.php" class="btn-secondary" style="align-self:flex-end;">
                    <i class="fa-solid fa-rotate"></i> Reset
                </a>
            </form>

            <!-- Product Table -->
            <div class="card table-card-full">
                <div class="card-head">
                    <h4><i class="fa-solid fa-list"></i> Product List</h4>
                    <span class="count-badge"><?php echo $products ? $products->num_rows : 0; ?> found</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Category</th>
                            <th>Product Name</th>
                            <th>Prices</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($products && $products->num_rows > 0):
                            while ($row = $products->fetch_assoc()): ?>
                        <tr>
                            <td style="color:var(--text-muted);font-size:12px;"><?php echo $row['product_id']; ?></td>
                            <td><span class="cat-chip"><?php echo htmlspecialchars($row['category_name']); ?></span></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <div style="width:30px;height:30px;border-radius:8px;background:var(--primary-lt);border:1.5px solid #f9c4a6;display:flex;align-items:center;justify-content:center;font-size:13px;color:var(--primary);flex-shrink:0;">
                                        <i class="fa-solid fa-box"></i>
                                    </div>
                                    <strong><?php echo htmlspecialchars($row['product_name']); ?></strong>
                                </div>
                            </td>
                            <td><div class="price-stack"><small>Cost: Rs. <?php echo number_format($row['cost_price'], 2); ?></small><strong>Retail: Rs. <?php echo number_format($row['price'], 2); ?></strong><small>Wholesale: Rs. <?php echo number_format($row['wholesale_price'] > 0 ? $row['wholesale_price'] : $row['price'], 2); ?></small><small style="color:var(--green);">Retail profit: Rs. <?php echo number_format($row['price']-$row['cost_price'], 2); ?></small></div></td>
                            <td><span class="stock-value <?php echo $row['stock_qty'] <= $row['reorder_level'] ? 'stock-low' : 'stock-ok'; ?>"><i class="fa-solid <?php echo $row['stock_qty'] <= $row['reorder_level'] ? 'fa-triangle-exclamation' : 'fa-cubes'; ?>"></i><?php echo number_format($row['stock_qty'], 0); ?> <?php echo htmlspecialchars($row['unit']); ?></span></td>
                            <td>
                                <a href="products.php?toggle=<?php echo $row['product_id']; ?>"
                                   class="status-badge <?php echo $row['status'] ? 'st-active' : 'st-inactive'; ?>">
                                    <i class="fa-solid <?php echo $row['status'] ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
                                    <?php echo $row['status'] ? 'Active' : 'Inactive'; ?>
                                </a>
                            </td>
                            <td>
                                <div class="action-btns">
                                    <a href="products.php?edit=<?php echo $row['product_id']; ?>"
                                       class="btn-edit">
                                        <i class="fa-solid fa-pen"></i> Edit
                                    </a>
                                    <a href="products.php?delete=<?php echo $row['product_id']; ?>"
                                       class="btn-del"
                                       onclick="return confirm('Delete \'<?php echo addslashes($row['product_name']); ?>\'?')">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="7" class="empty-row">
                                <i class="fa-solid fa-box-open" style="font-size:22px;color:var(--border-dk);display:block;margin-bottom:8px;"></i>
                                No products found. Try adjusting your filters.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div><!-- /inventory-stack -->

</div><!-- /content -->
</div><!-- /main -->

<datalist id="supplierDirectory">
<?php foreach($supplier_options as $supplier_option):
    $supplier_label=implode(' · ',array_filter([$supplier_option['supplier_code'],$supplier_option['supplier_name'],$supplier_option['phone']])); ?>
    <option value="<?php echo htmlspecialchars($supplier_label); ?>"></option>
<?php endforeach; ?>
</datalist>

<script>
const supplierDirectory=<?php echo json_encode($supplier_options,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
function supplierLabel(s){return [s.supplier_code,s.supplier_name,s.phone].filter(Boolean).join(' · ');}
function findSupplier(value){
    const query=(value||'').trim().toLocaleLowerCase();
    if(!query)return null;
    return supplierDirectory.find(s=>[supplierLabel(s),s.supplier_code,s.supplier_name,s.phone].filter(Boolean).some(v=>String(v).trim().toLocaleLowerCase()===query))||null;
}
function setSupplier(form,supplier){
    form.querySelector('.supplier-id').value=supplier?supplier.supplier_id:'';
    form.querySelector('.supplier-code').value=supplier?.supplier_code||'';
    form.querySelector('.supplier-name').value=supplier?.supplier_name||'';
    form.querySelector('.supplier-phone').value=supplier?.phone||'';
    const hint=form.querySelector('.supplier-hint');
    if(hint)hint.textContent=supplier?'Existing supplier selected — details filled automatically.':'Enter the new supplier details below.';
}
document.querySelectorAll('.supplier-search').forEach(input=>{
    input.addEventListener('input',()=>{const supplier=findSupplier(input.value);if(supplier)setSupplier(input.closest('.supplier-form'),supplier);});
    input.addEventListener('change',()=>{const supplier=findSupplier(input.value);if(supplier)setSupplier(input.closest('.supplier-form'),supplier);});
});
function clearSupplierSelection(button){
    const form=button.closest('.supplier-form');
    const search=form.querySelector('.supplier-search');
    if(search)search.value='';
    setSupplier(form,null);
    form.querySelector('.supplier-name')?.focus();
}
function openInventoryPanel(id){
    document.querySelectorAll('.collapsible-panel').forEach(panel=>panel.classList.remove('panel-open'));
    const panel=document.getElementById(id);
    if(panel){panel.classList.add('panel-open');setTimeout(()=>panel.scrollIntoView({behavior:'smooth',block:'start'}),50);}
}
function closeInventoryPanel(id){document.getElementById(id)?.classList.remove('panel-open');}
function updateStockFormHelp(){
    const action=document.getElementById('stockAction')?.value;
    const label=document.getElementById('stockQtyLabel');
    const cost=document.getElementById('costField');
    if(label)label.textContent=action==='stock_in'?'Quantity added':(action==='stock_out'?'Quantity removed':'Correct quantity now in stock');
    if(cost)cost.style.display=action==='stock_in'?'flex':'none';
}
function toggleNewProductInvoice(){
    const pending=document.getElementById('newProductInvoicePending')?.checked||false;
    const supplier=document.getElementById('newProductSupplierName');
    const mark=document.getElementById('supplierRequiredMark');
    if(supplier)supplier.required=!pending;
    if(mark)mark.style.display=pending?'none':'inline';
}
function calculateProductProfit(){
    const cost=parseFloat(document.getElementById('costPrice')?.value)||0;
    const retail=parseFloat(document.getElementById('retailPrice')?.value)||0;
    const wholesaleInput=parseFloat(document.getElementById('wholesalePrice')?.value)||0;
    const wholesale=wholesaleInput>0?wholesaleInput:retail;
    const retailProfit=retail-cost;
    const wholesaleProfit=wholesale-cost;
    document.getElementById('retailProfit').textContent=retailProfit.toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('wholesaleProfit').textContent=wholesaleProfit.toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('retailProfitItem').classList.toggle('profit-negative',retailProfit<0);
    document.getElementById('wholesaleProfitItem').classList.toggle('profit-negative',wholesaleProfit<0);
}
calculateProductProfit();
updateStockFormHelp();
toggleNewProductInvoice();
<?php if($edit_row):?>setTimeout(()=>document.getElementById('productFormCard')?.scrollIntoView({behavior:'smooth',block:'start'}),100);<?php endif;?>
</script>

</body>
</html>
