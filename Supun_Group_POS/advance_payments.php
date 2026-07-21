<?php
session_start();
include 'db.php';
require_once 'includes/advance_accounts.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
ensureAdvancePaymentSchema($conn);
reconcileClosedOrderAdvances($conn);

$message = ''; $message_type = 'success';
$methods = ['Cash','Card','QR','Bank Transfer'];
if (isset($_POST['complete_order'])) {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $method = trim($_POST['settlement_method'] ?? 'Cash');
    $received = round((float)($_POST['settlement_received'] ?? 0), 2);
    $payment_reference = trim($_POST['settlement_reference'] ?? '');
    if (!in_array($method, $methods, true)) $method = 'Cash';
    $conn->begin_transaction();
    try {
        $oq = $conn->query("SELECT o.order_id,o.customer_id,o.total_amount,o.discount,o.service_charge,o.packaging_fee,(SELECT COALESCE(SUM(oi.line_total),0) FROM order_items oi WHERE oi.order_id=o.order_id) item_total FROM orders o WHERE o.order_id=$order_id AND o.order_status='open' FOR UPDATE");
        $order = $oq ? $oq->fetch_assoc() : null;
        if (!$order || (int)$order['customer_id'] <= 0) throw new Exception('This open customer order was not found.');
        $customer_id = (int)$order['customer_id'];
        $total = (float)$order['total_amount'];
        if ($total <= 0) $total = max(0, (float)$order['item_total'] + (float)$order['service_charge'] + (float)$order['packaging_fee'] - (float)$order['discount']);
        $total = round($total, 2);
        $dq = $conn->query("SELECT transaction_id,remaining_amount FROM advance_payment_transactions WHERE order_id=$order_id AND customer_id=$customer_id AND transaction_type='deposit' AND remaining_amount>0 ORDER BY created_at,transaction_id FOR UPDATE");
        $deposits = []; $advance_used = 0.0;
        while ($dq && $d = $dq->fetch_assoc()) { $deposits[] = $d; $advance_used += (float)$d['remaining_amount']; }
        $advance_used = min($total, round($advance_used, 2));
        $amount_due = max(0, round($total - $advance_used, 2));
        if ($received <= 0) throw new Exception('Enter the amount the customer is paying now.');
        if ($method !== 'Cash') $received = min($received, $amount_due);

        // A payment below the remaining bill is another linked installment.
        // It remains available as advance credit until the final installment closes the sale.
        if ($received + 0.0001 < $amount_due) {
            $receipt = nextAdvanceReceipt($conn); $uid = (int)$_SESSION['user_id'];
            $note = $payment_reference !== '' ? $payment_reference : 'Additional installment for order';
            $stmt = $conn->prepare("INSERT INTO advance_payment_transactions (receipt_number,customer_id,order_id,transaction_type,amount,remaining_amount,settlement_status,settlement_due_date,payment_method,reference_note,created_by) VALUES (?,?,?,'deposit',?,?,'open',DATE_ADD(CURDATE(),INTERVAL 1 DAY),?,?,?)");
            $stmt->bind_param('siiddssi',$receipt,$customer_id,$order_id,$received,$received,$method,$note,$uid);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $installment_id=$conn->insert_id; $stmt->close();
            $stmt=$conn->prepare('UPDATE customer_accounts SET advance_balance=advance_balance+? WHERE customer_id=?');
            $stmt->bind_param('di',$received,$customer_id); $stmt->execute(); $stmt->close();
            $item_subtotal=round((float)$order['item_total'],2);
            $stmt=$conn->prepare('UPDATE orders SET subtotal=?,total_amount=? WHERE order_id=? AND order_status=\'open\'');
            $stmt->bind_param('ddi',$item_subtotal,$total,$order_id); $stmt->execute(); $stmt->close();
            $conn->commit();
            header("Location: print_advance.php?transaction_id=$installment_id"); exit;
        }

        $to_allocate = $advance_used; $uid = (int)$_SESSION['user_id'];
        foreach ($deposits as $d) {
            if ($to_allocate <= 0.0001) break;
            $source_id = (int)$d['transaction_id'];
            $applied = min($to_allocate, (float)$d['remaining_amount']);
            $remaining = max(0, (float)$d['remaining_amount'] - $applied);
            $status = $remaining <= 0.0001 ? 'settled' : 'partial';
            $conn->query("UPDATE advance_payment_transactions SET remaining_amount=$remaining,settlement_status='$status' WHERE transaction_id=$source_id");
            $receipt = nextAdvanceReceipt($conn); $note = 'Applied when final payment was completed';
            $stmt = $conn->prepare("INSERT INTO advance_payment_transactions (receipt_number,customer_id,order_id,parent_transaction_id,transaction_type,amount,remaining_amount,settlement_status,payment_method,reference_note,created_by) VALUES (?,?,?,?,'sale_usage',?,0,'settled','Advance Account',?,?)");
            $stmt->bind_param('siiidsi', $receipt,$customer_id,$order_id,$source_id,$applied,$note,$uid);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $stmt->close(); $to_allocate -= $applied;
        }
        if ($advance_used > 0) {
            $stmt=$conn->prepare('UPDATE customer_accounts SET advance_balance=GREATEST(0,advance_balance-?) WHERE customer_id=?');
            $stmt->bind_param('di',$advance_used,$customer_id); $stmt->execute(); $stmt->close();
        }
        $change = max(0, round($received - $amount_due, 2));
        $stored_method = $advance_used >= $total && $total > 0 ? 'Credit' : $method;
        $item_subtotal = round((float)$order['item_total'],2);
        $stmt=$conn->prepare("UPDATE orders SET subtotal=?,total_amount=?,advance_used=?,payment_method=?,payment_reference=?,cash_given=?,balance=?,order_status='paid',payment_status='paid',paid_at=NOW() WHERE order_id=? AND order_status='open'");
        $stmt->bind_param('dddssddi',$item_subtotal,$total,$advance_used,$stored_method,$payment_reference,$received,$change,$order_id);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) throw new Exception('The order could not be completed.');
        $stmt->close(); $conn->commit();
        header("Location: print_bill.php?order_id=$order_id"); exit;
    } catch (Throwable $e) {
        $conn->rollback(); $message='Could not complete payment: '.$e->getMessage(); $message_type='error';
    }
}
if (isset($_POST['save_advance'])) {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $name = trim($_POST['customer_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $method = trim($_POST['payment_method'] ?? 'Cash');
    $note = trim($_POST['reference_note'] ?? '');
    if (!in_array($method, $methods, true)) $method = 'Cash';

    if ($amount <= 0 || ($customer_id <= 0 && $name === '')) {
        $message = 'Select a customer (or enter a new customer) and enter a valid amount.'; $message_type = 'error';
    } else {
        $conn->begin_transaction();
        try {
            if ($customer_id > 0) {
                $stmt = $conn->prepare('SELECT customer_id FROM customer_accounts WHERE customer_id=? AND status=1 FOR UPDATE');
                $stmt->bind_param('i', $customer_id); $stmt->execute();
                if (!$stmt->get_result()->fetch_assoc()) throw new Exception('Customer account was not found.');
                $stmt->close();
            } else {
                $account = nextAccountNumber($conn);
                $stmt = $conn->prepare('INSERT INTO customer_accounts (account_number,customer_name,phone,address) VALUES (?,?,?,?)');
                $stmt->bind_param('ssss', $account, $name, $phone, $address);
                if (!$stmt->execute()) throw new Exception($stmt->error);
                $customer_id = $conn->insert_id; $stmt->close();
            }
            $stmt = $conn->prepare('UPDATE customer_accounts SET advance_balance=advance_balance+? WHERE customer_id=?');
            $stmt->bind_param('di', $amount, $customer_id); $stmt->execute(); $stmt->close();
            $receipt = nextAdvanceReceipt($conn); $uid = (int)$_SESSION['user_id'];
            $stmt = $conn->prepare("INSERT INTO advance_payment_transactions (receipt_number,customer_id,transaction_type,amount,remaining_amount,settlement_status,settlement_due_date,payment_method,reference_note,created_by) VALUES (?,?,'deposit',?,?,'open',DATE_ADD(CURDATE(),INTERVAL 1 DAY),?,?,?)");
            $stmt->bind_param('siddssi', $receipt, $customer_id, $amount, $amount, $method, $note, $uid);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $advance_transaction_id = $conn->insert_id;
            $stmt->close(); $conn->commit();
            header("Location: print_advance.php?transaction_id=$advance_transaction_id"); exit;
        } catch (Throwable $e) {
            $conn->rollback(); $message = 'Could not save advance payment: ' . $e->getMessage(); $message_type = 'error';
        }
    }
}

$search = trim($_GET['search'] ?? '');
$where = $search === '' ? '1=1' : "(c.customer_name LIKE '%".$conn->real_escape_string($search)."%' OR c.phone LIKE '%".$conn->real_escape_string($search)."%' OR c.account_number LIKE '%".$conn->real_escape_string($search)."%')";
$customers = $conn->query("SELECT c.*,COUNT(t.transaction_id) transaction_count FROM customer_accounts c LEFT JOIN advance_payment_transactions t ON t.customer_id=c.customer_id WHERE $where GROUP BY c.customer_id ORDER BY c.customer_name");
$select_customers = $conn->query("SELECT customer_id,account_number,customer_name,phone,advance_balance FROM customer_accounts WHERE status=1 ORDER BY customer_name");
$transactions = $conn->query("SELECT t.*,c.account_number,c.customer_name,c.phone,u.full_name,o.order_number,o.order_status,
    CASE WHEN o.total_amount>0 THEN o.total_amount ELSE GREATEST(0,(SELECT COALESCE(SUM(oi2.line_total),0) FROM order_items oi2 WHERE oi2.order_id=o.order_id)+COALESCE(o.service_charge,0)+COALESCE(o.packaging_fee,0)-COALESCE(o.discount,0)) END total_amount,
    o.discount,o.service_charge,o.packaging_fee,
    (SELECT COALESCE(SUM(oi.line_total),0) FROM order_items oi WHERE oi.order_id=COALESCE(t.order_id,o.order_id)) item_total,
    (SELECT COALESCE(SUM(x.remaining_amount),0) FROM advance_payment_transactions x WHERE x.order_id=COALESCE(t.order_id,o.order_id) AND x.customer_id=t.customer_id AND x.transaction_type='deposit') order_advance,
    COALESCE(t.order_id,(SELECT o2.order_id FROM orders o2 WHERE o2.customer_id=t.customer_id AND o2.order_status='open' ORDER BY o2.order_id DESC LIMIT 1)) settlement_order_id
    FROM advance_payment_transactions t JOIN customer_accounts c ON c.customer_id=t.customer_id LEFT JOIN users u ON u.user_id=t.created_by LEFT JOIN orders o ON o.order_id=t.order_id ORDER BY t.transaction_id DESC LIMIT 100");
$order_payment_history = [];
$history_q = $conn->query("SELECT order_id,receipt_number,amount,payment_method,created_at FROM advance_payment_transactions WHERE transaction_type='deposit' AND order_id IS NOT NULL ORDER BY created_at,transaction_id");
while ($history_q && $h=$history_q->fetch_assoc()) $order_payment_history[(int)$h['order_id']][]=$h;
$pending_orders = $conn->query("SELECT o.order_id,o.order_number,o.customer_id,o.customer_name,c.phone,
    CASE WHEN o.total_amount>0 THEN o.total_amount ELSE GREATEST(0,(SELECT COALESCE(SUM(oi.line_total),0) FROM order_items oi WHERE oi.order_id=o.order_id)+COALESCE(o.service_charge,0)+COALESCE(o.packaging_fee,0)-COALESCE(o.discount,0)) END bill_total,
    (SELECT COALESCE(SUM(t.remaining_amount),0) FROM advance_payment_transactions t WHERE t.order_id=o.order_id AND t.transaction_type='deposit') paid_amount
    FROM orders o JOIN customer_accounts c ON c.customer_id=o.customer_id
    WHERE o.order_status='open' AND EXISTS(SELECT 1 FROM advance_payment_transactions t2 WHERE t2.order_id=o.order_id AND t2.transaction_type='deposit' AND t2.remaining_amount>0)
    ORDER BY o.order_id DESC");
$pending_order_rows=[];
while($pending_orders && $po=$pending_orders->fetch_assoc()) {
    $po['bill_total']=(float)$po['bill_total'];
    $po['paid_amount']=min($po['bill_total'],(float)$po['paid_amount']);
    $po['remaining_amount']=max(0,$po['bill_total']-$po['paid_amount']);
    $pending_order_rows[]=$po;
}
$customer_payment_details=[];
$detail_q=$conn->query("SELECT t.transaction_id,t.receipt_number,t.customer_id,t.order_id,t.transaction_type,t.amount,t.remaining_amount,t.payment_method,t.created_at,c.account_number,c.customer_name,c.phone,o.order_number,o.order_status,o.total_amount bill_total,o.advance_used,o.payment_method final_payment_method,o.paid_at FROM advance_payment_transactions t JOIN customer_accounts c ON c.customer_id=t.customer_id LEFT JOIN orders o ON o.order_id=t.order_id WHERE t.transaction_type='deposit' ORDER BY t.created_at DESC,t.transaction_id DESC");
while($detail_q && $detail=$detail_q->fetch_assoc()) $customer_payment_details[]=$detail;
$summary = $conn->query("SELECT COUNT(*) customers,COALESCE(SUM(advance_balance),0) balance FROM customer_accounts WHERE status=1")->fetch_assoc();
$open_summary = $conn->query("SELECT COUNT(*) open_items,COALESCE(SUM(t.settlement_due_date<CURDATE()),0) overdue FROM advance_payment_transactions t JOIN orders o ON o.order_id=t.order_id WHERE t.transaction_type='deposit' AND o.order_status='open'")->fetch_assoc();
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Customer Advance Payments</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"><style>
*{box-sizing:border-box}body{margin:0;background:#f6f7fb;color:#172033;font-family:Arial,sans-serif}.top{height:68px;background:#fff;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;padding:0 24px}.top a{color:#e85d04;text-decoration:none;font-weight:800}.wrap{max-width:1280px;margin:24px auto;padding:0 18px}.head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}.head h1{margin:0;font-size:25px}.btn{border:0;border-radius:9px;background:#e85d04;color:#fff;padding:11px 16px;font-weight:800;cursor:pointer;text-decoration:none}.grid{display:grid;grid-template-columns:380px 1fr;gap:18px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px;box-shadow:0 3px 12px #1118270a}.card h2{font-size:16px;margin:0 0 15px}.field{margin-bottom:12px}.field label{display:block;font-size:11px;font-weight:800;text-transform:uppercase;color:#667085;margin-bottom:5px}.field input,.field select,.field textarea{width:100%;padding:10px;border:1px solid #d0d5dd;border-radius:8px;font:inherit}.field textarea{height:62px}.hint{font-size:12px;color:#667085;line-height:1.5}.or{text-align:center;font-weight:800;color:#98a2b3;margin:8px}.msg{padding:12px;border-radius:9px;margin-bottom:16px;background:#ecfdf3;color:#027a48}.msg.error{background:#fef3f2;color:#b42318}.stats{display:flex;gap:12px;margin-bottom:15px}.stat{flex:1;background:#fff7ed;border-radius:10px;padding:14px}.stat strong{display:block;font-size:21px;color:#e85d04}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:13px}th{text-align:left;color:#667085;background:#f9fafb;padding:10px}td{padding:11px 10px;border-bottom:1px solid #eaecf0}.money{font-weight:900;color:#027a48}.chip{display:inline-block;padding:4px 8px;border-radius:20px;background:#eff8ff;color:#175cd3;font-size:11px;font-weight:800}.deposit{color:#027a48}.sale_usage{color:#b42318}.search{display:flex;gap:8px;margin-bottom:12px}.search input{flex:1;padding:10px;border:1px solid #d0d5dd;border-radius:8px}@media(max-width:850px){.grid{grid-template-columns:1fr}.top{padding:0 14px}.head{align-items:flex-start;flex-direction:column}}
.modal{display:none;position:fixed;inset:0;background:#10182899;z-index:50;align-items:center;justify-content:center;padding:18px}.modal.open{display:flex}.modal-box{width:min(460px,100%);background:#fff;border-radius:16px;padding:22px;box-shadow:0 20px 50px #10182855}.modal-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:16px}.modal-head h2{margin:0}.close{border:0;background:#f2f4f7;border-radius:7px;width:34px;height:34px;cursor:pointer}.summary{background:#f8fafc;border:1px solid #e4e7ec;border-radius:10px;padding:12px;margin:13px 0}.summary div{display:flex;justify-content:space-between;padding:6px 0}.summary .due{border-top:1px dashed #98a2b3;margin-top:5px;padding-top:11px;color:#027a48;font-weight:900;font-size:16px}.modal-actions{display:flex;gap:9px}.modal-actions>*{flex:1}
.modal{overflow-y:auto;align-items:flex-start}.modal-box{margin:auto;max-height:calc(100vh - 36px);overflow-y:auto;overflow-x:hidden}#paymentHistory{display:block!important;width:100%}#paymentHistory>strong{display:block;margin-bottom:7px}#paymentHistory>div{display:grid!important;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:start;padding:6px 0!important;border-bottom:1px dotted #d0d5dd}#paymentHistory>div:last-child{border-bottom:0}#paymentHistory span{min-width:0;overflow-wrap:anywhere;line-height:1.35}#paymentHistory>div>strong{white-space:nowrap;text-align:right}.modal-actions{position:sticky;bottom:-22px;background:#fff;padding:10px 0 0;z-index:2}@media(max-height:760px){.modal{padding:8px}.modal-box{max-height:calc(100vh - 16px);padding:16px}.modal-head{margin-bottom:10px}.summary{margin:8px 0;padding:9px}.field{margin-bottom:8px}}
#customerHistoryModal .modal-box{width:min(900px,100%)}#customerHistoryModal table{font-size:12px}#customerHistoryModal .history-total{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}#customerHistoryModal .history-total .stat{min-width:150px}
.order-payment-card .table-wrap{overflow-x:auto}.order-payment-card table{min-width:870px;table-layout:auto}.order-payment-card th,.order-payment-card td{vertical-align:middle}.order-payment-card th:nth-child(1),.order-payment-card td:nth-child(1){width:105px;white-space:nowrap}.order-payment-card th:nth-child(2),.order-payment-card td:nth-child(2){width:115px;white-space:nowrap}.order-payment-card th:nth-child(3),.order-payment-card td:nth-child(3){min-width:150px}.order-payment-card th:nth-child(4),.order-payment-card td:nth-child(4){width:75px;text-align:center;white-space:nowrap}.order-payment-card th:nth-child(5),.order-payment-card td:nth-child(5){width:135px;white-space:nowrap}.order-payment-card th:nth-child(6),.order-payment-card td:nth-child(6){width:90px;white-space:nowrap}.order-payment-card th:nth-child(7),.order-payment-card td:nth-child(7){width:185px}.order-payment-card td:nth-child(7){display:flex;flex-direction:column;align-items:stretch;gap:6px}.order-payment-card td:nth-child(7) .btn{display:block!important;width:100%;margin:0!important;text-align:center}.order-payment-card .chip{white-space:nowrap}.order-payment-card td:first-child strong{white-space:nowrap}
.order-payment-card td:nth-child(7){display:table-cell!important}.order-payment-card td:nth-child(7) .btn{display:block!important;width:100%;margin:0 0 6px!important;text-align:center}.order-payment-card td:nth-child(7) .btn:last-child{margin-bottom:0!important}
</style></head><body>
<div class="top"><strong><i class="fa-solid fa-wallet"></i> Customer Advances</strong><div><a href="pos.php"><i class="fa-solid fa-cash-register"></i> POS</a><?php if(in_array($_SESSION['role']??'', ['admin','manager'],true)): ?> &nbsp; <a href="admin/sales.php">Admin</a><?php endif; ?></div></div>
<main class="wrap"><div class="head"><div><h1>Advance Payment Accounts</h1><div class="hint">Receive customer deposits now and apply them safely to a later POS sale.</div></div></div>
<?php if($message): ?><div class="msg <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
<div class="grid"><section class="card"><h2><i class="fa-solid fa-circle-plus"></i> Receive Advance Payment</h2><form method="post">
<div class="field"><label>Existing customer account</label><select name="customer_id" id="customerId" onchange="toggleNewCustomer()"><option value="0">Create a new customer</option><?php while($c=$select_customers->fetch_assoc()): ?><option value="<?php echo (int)$c['customer_id']; ?>"><?php echo htmlspecialchars($c['account_number'].' · '.$c['customer_name'].' · Rs. '.number_format($c['advance_balance'],2)); ?></option><?php endwhile; ?></select></div>
<div class="or">OR NEW CUSTOMER</div><div id="newCustomer"><div class="field"><label>Customer name *</label><input name="customer_name" placeholder="Full name / business name"></div><div class="field"><label>Phone / Account contact</label><input name="phone" placeholder="Phone number"></div><div class="field"><label>Address</label><input name="address" placeholder="Optional address"></div></div>
<div class="field"><label>Advance amount *</label><input type="number" min="0.01" step="0.01" name="amount" required></div><div class="field"><label>Received by</label><select name="payment_method"><?php foreach($methods as $m): ?><option><?php echo $m; ?></option><?php endforeach; ?></select></div><div class="field"><label>Reference / purpose</label><textarea name="reference_note" placeholder="Order purpose, bank reference, or note"></textarea></div><button class="btn" name="save_advance" style="width:100%"><i class="fa-solid fa-floppy-disk"></i> Save Advance &amp; Issue Receipt No.</button></form></section>
<div><div class="stats"><div class="stat"><strong><?php echo number_format($summary['customers']); ?></strong><span>Customer accounts</span></div><div class="stat"><strong>Rs. <?php echo number_format($summary['balance'],2); ?></strong><span>Total advance liability</span></div><div class="stat"><strong><?php echo number_format((int)$open_summary['open_items']); ?></strong><span>Open advances</span></div><div class="stat"><strong><?php echo number_format((int)$open_summary['overdue']); ?></strong><span>Past follow-up date</span></div></div>
<section class="card" style="margin-bottom:18px"><h2>Customer Balances</h2><form class="search"><input name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name, account or phone"><button class="btn">Search</button></form><div class="table-wrap"><table><thead><tr><th>Account</th><th>Customer</th><th>Phone</th><th>Transactions</th><th>Available</th></tr></thead><tbody><?php while($c=$customers->fetch_assoc()): ?><tr><td><span class="chip"><?php echo htmlspecialchars($c['account_number']); ?></span></td><td><strong><?php echo htmlspecialchars($c['customer_name']); ?></strong></td><td><?php echo htmlspecialchars($c['phone']?:'-'); ?></td><td><?php echo (int)$c['transaction_count']; ?></td><td class="money">Rs. <?php echo number_format($c['advance_balance'],2); ?></td></tr><?php endwhile; ?></tbody></table></div></section>
<section class="card"><h2>Individual Advance Payments &amp; Settlements</h2><div class="hint" style="margin-bottom:12px;">Each advance is followed separately. Complete Payment appears only while its order is open. When an order is fully paid it shows Completed. Any advance not applied to that bill remains visible as customer credit and is not lost.</div><div class="table-wrap"><table><thead><tr><th>Date</th><th>Receipt / Customer</th><th>Order</th><th>Advance</th><th>Unused Credit</th><th>Complete By</th><th>Sale Status</th><th>Actions</th></tr></thead><tbody><?php while($t=$transactions->fetch_assoc()): $is_deposit=$t['transaction_type']==='deposit'; $sale_completed=$t['order_status']==='paid'; $can_complete=$is_deposit && !$sale_completed && (int)$t['settlement_order_id']>0; $order_total=(float)($t['total_amount']??0); $order_advance=min($order_total,(float)($t['order_advance']??0)); $order_due=max(0,$order_total-$order_advance); ?><tr><td><?php echo date('d M Y H:i',strtotime($t['created_at'])); ?></td><td><strong><?php echo htmlspecialchars($t['receipt_number']); ?></strong><br><?php echo htmlspecialchars($t['customer_name']); ?> <small><?php echo htmlspecialchars($t['account_number']); ?></small></td><td><?php echo htmlspecialchars($t['order_number']?:($t['settlement_order_id']?'Open sale #'.$t['settlement_order_id']:'Not linked')); ?></td><td><strong>Rs. <?php echo number_format($t['amount'],2); ?></strong><br><small><?php echo htmlspecialchars($t['payment_method']); ?></small></td><td><?php echo $is_deposit?'Rs. '.number_format($t['remaining_amount'],2):'-'; ?><?php if($sale_completed && (float)$t['remaining_amount']>0): ?><br><small style="color:#b54708;">Available for another sale</small><?php endif; ?></td><td><?php echo $sale_completed?'-':($t['settlement_due_date']?date('d M Y',strtotime($t['settlement_due_date'])):'-'); ?></td><td><span class="chip" style="<?php echo $sale_completed?'background:#ecfdf3;color:#027a48;':''; ?>"><?php echo $sale_completed?'Completed':($t['order_status']==='open'?'Awaiting Payment':ucfirst($t['settlement_status'])); ?></span></td><td><a class="btn" style="padding:7px 9px;white-space:nowrap;" href="print_advance.php?transaction_id=<?php echo (int)$t['transaction_id']; ?>">Print</a><?php if($can_complete): ?><button type="button" class="btn" style="display:block;margin-top:5px;padding:7px 9px;white-space:nowrap;background:#027a48;" onclick='openSettlement(<?php echo json_encode(["order_id"=>(int)$t["settlement_order_id"],"order"=>$t["order_number"]?:"Order #".$t["settlement_order_id"],"customer"=>$t["customer_name"],"phone"=>$t["phone"],"total"=>$order_total,"advance"=>$order_advance,"due"=>$order_due]); ?>)'>Complete Payment</button><?php elseif($is_deposit && !$sale_completed): ?><a class="btn" style="display:inline-block;margin-top:5px;padding:7px 9px;white-space:nowrap;background:#667085;" href="pos.php">Go to POS</a><?php endif; ?></td></tr><?php endwhile; ?></tbody></table></div></section></div></div></main>
<div class="modal" id="settlementModal"><div class="modal-box"><div class="modal-head"><div><h2>Complete Customer Payment</h2><div class="hint" id="settleCustomer"></div></div><button type="button" class="close" onclick="closeSettlement()">&times;</button></div><form method="post" id="settlementForm"><input type="hidden" name="order_id" id="settleOrderId"><div class="field"><label>Order</label><input id="settleOrder" readonly></div><div class="summary"><div><span>Bill total</span><strong id="settleTotal"></strong></div><div><span>Advance already paid</span><strong id="settleAdvance"></strong></div><div class="due"><span>Remaining payment</span><strong id="settleDue"></strong></div></div><div class="field"><label>Payment method</label><select name="settlement_method" id="settleMethod" onchange="updateSettlementMethod()"><?php foreach($methods as $m): ?><option><?php echo $m; ?></option><?php endforeach; ?></select></div><div class="field"><label id="receivedLabel">Cash received *</label><input type="number" min="0" step="0.01" name="settlement_received" id="settleReceived" required></div><div class="summary" id="changeBox" style="margin-top:0"><div><span>Change to customer</span><strong id="settleChange">Rs. 0.00</strong></div></div><div class="modal-actions"><button type="button" class="btn" style="background:#667085" onclick="closeSettlement()">Cancel</button><button class="btn" name="complete_order" onclick="return confirm('Complete this order and print the final bill?')">Complete &amp; Print Bill</button></div></form></div></div>
<script>
const orderPaymentHistory=<?php echo json_encode($order_payment_history,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const pendingOrders=<?php echo json_encode($pending_order_rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
const customerPaymentDetails=<?php echo json_encode($customer_payment_details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
function safeText(value){const el=document.createElement('span');el.textContent=value??'';return el.innerHTML}
function renderRemainingOrders(){
 document.querySelectorAll('table button').forEach(button=>{if(button.textContent.trim()==='Complete Payment')button.remove()});
 const individual=[...document.querySelectorAll('.card')].find(card=>card.querySelector('h2')?.textContent.includes('Individual Advance Payments'));
 if(!individual)return;
 const historyHint=individual.querySelector('.hint');if(historyHint)historyHint.textContent='Payment history for each order. Use Print for an individual receipt; use the Remaining Orders section above to add a payment or complete a bill.';
 const rows=pendingOrders.length?pendingOrders.map((o,index)=>`<tr><td><strong>${safeText(o.order_number)}</strong></td><td>${safeText(o.customer_name)}<br><small>${safeText(o.phone||'-')}</small></td><td>${money(o.bill_total)}</td><td class="money">${money(o.paid_amount)}</td><td><strong style="color:#b54708">${money(o.remaining_amount)}</strong></td><td><button type="button" class="btn order-payment-btn" data-index="${index}" style="padding:8px 10px;background:#027a48;white-space:nowrap">Add Payment / Complete Bill</button></td></tr>`).join(''):'<tr><td colspan="6" style="text-align:center;color:#667085">No orders have a remaining payment.</td></tr>';
 individual.insertAdjacentHTML('beforebegin',`<section class="card remaining-orders" style="margin-bottom:18px"><h2><i class="fa-solid fa-file-invoice-dollar"></i> Remaining Orders / Complete Bill</h2><div class="hint" style="margin-bottom:12px">Choose an order here to record another installment or complete its bill.</div><div class="table-wrap"><table><thead><tr><th>Order</th><th>Customer</th><th>Bill Total</th><th>Paid</th><th>Remaining</th><th>Action</th></tr></thead><tbody>${rows}</tbody></table></div></section>`);
 document.querySelectorAll('.order-payment-btn').forEach(button=>button.addEventListener('click',()=>{const o=pendingOrders[Number(button.dataset.index)];openSettlement({order_id:Number(o.order_id),order:o.order_number,customer:o.customer_name,phone:o.phone,total:Number(o.bill_total),advance:Number(o.paid_amount),due:Number(o.remaining_amount)})}));
}
function renderCustomerPaymentSummary(){
 const card=[...document.querySelectorAll('.card')].find(item=>item.querySelector('h2')?.textContent.includes('Individual Advance Payments'));
 if(!card)return;
 card.classList.add('order-payment-card');
 const grouped=new Map();
 customerPaymentDetails.forEach(payment=>{const key=String(payment.customer_id)+'|'+String(payment.order_id||'unlinked');if(!grouped.has(key))grouped.set(key,{customer_id:String(payment.customer_id),order_id:Number(payment.order_id||0),order_number:payment.order_number||'Not linked',order_status:payment.order_status||'open',bill_total:Number(payment.bill_total||0),final_payment_method:payment.final_payment_method||'Cash',paid_at:payment.paid_at,account_number:payment.account_number,customer_name:payment.customer_name,phone:payment.phone,payments:[],received:0,available:0});const order=grouped.get(key);order.payments.push(payment);order.received+=Number(payment.amount||0);order.available+=Number(payment.remaining_amount||0);if(payment.order_status)order.order_status=payment.order_status});
 grouped.forEach(order=>{if(order.order_status==='paid'&&order.bill_total>order.received+0.0001){const finalAmount=order.bill_total-order.received;order.payments.push({transaction_id:-order.order_id,is_final:true,receipt_number:'FINAL-'+order.order_number,order_number:order.order_number,payment_method:order.final_payment_method,amount:finalAmount,remaining_amount:0,created_at:order.paid_at||new Date().toISOString().slice(0,19).replace('T',' ')});order.received+=finalAmount}});
 window.customerPaymentGroups=[...grouped.values()];
 const rows=window.customerPaymentGroups.length?window.customerPaymentGroups.map((order,index)=>{const closed=order.order_status==='paid';return `<tr><td><strong>${safeText(order.order_number)}</strong></td><td><span class="chip">${safeText(order.account_number)}</span></td><td><strong>${safeText(order.customer_name)}</strong><br><small>${safeText(order.phone||'-')}</small></td><td>${order.payments.length}</td><td>Rs. ${Number(order.received).toLocaleString('en-LK',{minimumFractionDigits:2})}</td><td><span class="chip" style="${closed?'background:#ecfdf3;color:#027a48':'background:#eff8ff;color:#175cd3'}">${closed?'Closed':'Open'}</span></td><td><button type="button" class="btn view-payments-btn" data-index="${index}" style="padding:8px 11px;white-space:nowrap">View Payments</button>${closed&&order.order_id?` <a class="btn" href="print_bill.php?order_id=${order.order_id}" style="display:inline-block;padding:8px 11px;background:#027a48;white-space:nowrap">View Closed Bill</a>`:''}</td></tr>`}).join(''):'<tr><td colspan="7" style="text-align:center;color:#667085">No order payments found.</td></tr>';
 card.innerHTML=`<h2><i class="fa-solid fa-file-invoice"></i> Customer Orders &amp; Payments</h2><div class="hint" style="margin-bottom:12px">Each order is shown separately, even when the customer is the same. View its installments or open the completed bill.</div><div class="table-wrap"><table><thead><tr><th>Order</th><th>Account</th><th>Customer</th><th>Payments</th><th>Total Received</th><th>Bill Status</th><th>Actions</th></tr></thead><tbody>${rows}</tbody></table></div>`;
 document.querySelectorAll('.view-payments-btn').forEach(button=>button.addEventListener('click',()=>openCustomerHistory(Number(button.dataset.index))));
}
function openCustomerHistory(index){const customer=window.customerPaymentGroups[index];if(!customer)return;document.getElementById('customerHistoryTitle').textContent=customer.order_number+' · '+customer.customer_name;document.getElementById('customerHistoryPhone').textContent=customer.account_number+' · '+(customer.phone||'No phone number');document.getElementById('customerHistoryStats').innerHTML=`<div class="stat"><strong>${customer.payments.length}</strong><span>Payments for this order</span></div><div class="stat"><strong>${money(customer.received)}</strong><span>Total received</span></div><div class="stat"><strong>${customer.order_status==='paid'?'Closed':'Open'}</strong><span>Bill status</span></div>`;document.getElementById('customerHistoryBody').innerHTML=customer.payments.map((payment,index)=>`<tr><td>${index+1}</td><td>${safeText(new Date(payment.created_at.replace(' ','T')).toLocaleString())}</td><td><strong>${safeText(payment.receipt_number)}</strong></td><td>${safeText(payment.order_number||'Not linked')}</td><td>${safeText(payment.payment_method)}</td><td><strong>${money(payment.amount)}</strong></td><td>${Number(payment.remaining_amount)>0?'<span class="chip">Available</span>':'<span class="chip" style="background:#ecfdf3;color:#027a48">Completed</span>'}</td><td><a class="btn" style="padding:7px 9px" href="print_advance.php?transaction_id=${Number(payment.transaction_id)}">Print</a></td></tr>`).join('');document.getElementById('customerHistoryModal').classList.add('open')}
function closeCustomerHistory(){document.getElementById('customerHistoryModal').classList.remove('open')}
function toggleNewCustomer(){document.getElementById('newCustomer').style.opacity=document.getElementById('customerId').value==='0'?'1':'.4'}toggleNewCustomer();
let settlementDue=0;
function money(v){return 'Rs. '+Number(v||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2})}
function openSettlement(data){settlementDue=Number(data.due||0);document.getElementById('settleOrderId').value=data.order_id;document.getElementById('settleOrder').value=data.order;document.getElementById('settleCustomer').textContent=data.customer+(data.phone?' · '+data.phone:'');document.getElementById('settleTotal').textContent=money(data.total);document.getElementById('settleAdvance').textContent=money(data.advance);document.getElementById('settleDue').textContent=money(data.due);const old=document.getElementById('paymentHistory');if(old)old.remove();const history=orderPaymentHistory[data.order_id]||[];const box=document.createElement('div');box.id='paymentHistory';box.style.cssText='border-top:1px dashed #98a2b3;margin-top:7px;padding-top:7px';box.innerHTML='<strong style="display:block;margin-bottom:5px">Payments received</strong>'+history.map((p,i)=>'<div style="font-size:12px"><span>'+(i+1)+'. '+p.receipt_number+' · '+p.payment_method+'</span><strong>'+money(p.amount)+'</strong></div>').join('');document.querySelector('#settlementForm .summary').appendChild(box);document.getElementById('settleMethod').value='Cash';document.getElementById('settleReceived').value=Number(data.due).toFixed(2);updateSettlementMethod();document.getElementById('settlementModal').classList.add('open')}
function closeSettlement(){document.getElementById('settleReference').value='';document.getElementById('settlementModal').classList.remove('open')}
function updateSettlementMethod(){const method=document.getElementById('settleMethod').value,reference=document.getElementById('settleReference');document.getElementById('settleReceived').readOnly=false;document.getElementById('receivedLabel').textContent='Amount paying now *';reference.placeholder=method==='Card'?'Card slip / approval number':method==='QR'?'QR transaction/reference number':method==='Bank Transfer'?'Bank transaction/reference number':'Optional cash payment note';reference.required=method!=='Cash';document.getElementById('referenceLabel').textContent=method==='Cash'?'Payment note (optional)':'Payment reference / transaction no. *';updateChange()}
function updateChange(){const received=Math.max(0,Number(document.getElementById('settleReceived').value||0)),cash=document.getElementById('settleMethod').value==='Cash',remaining=Math.max(0,settlementDue-received),isFull=remaining<=0.0001;document.getElementById('settleChange').textContent=money(cash&&isFull?Math.max(0,received-settlementDue):0);document.getElementById('changeBox').style.display=cash&&isFull?'block':'none';document.getElementById('remainingAfter').textContent=money(remaining);document.getElementById('paymentResultType').textContent=isFull?'Final payment — order will be completed':'Installment — order will remain open';document.getElementById('paymentResultType').style.color=isFull?'#027a48':'#b54708';const button=document.querySelector('#settlementForm button[name="complete_order"]');button.innerHTML=isFull?'Complete &amp; Print Final Bill':'Save Installment &amp; Print Receipt';button.onclick=()=>confirm(isFull?'Complete this order and print the final bill?':'Save this installment and keep the remaining balance open?')}
const referenceField=document.createElement('div');referenceField.className='field';referenceField.innerHTML='<label id="referenceLabel" for="settleReference">Payment note (optional)</label><input type="text" maxlength="255" name="settlement_reference" id="settleReference" placeholder="Optional cash payment note">';const methodField=document.getElementById('settleMethod').closest('.field');methodField.insertAdjacentElement('afterend',referenceField);
document.body.insertAdjacentHTML('beforeend','<div class="modal" id="customerHistoryModal"><div class="modal-box"><div class="modal-head"><div><h2 id="customerHistoryTitle">Customer Payments</h2><div class="hint" id="customerHistoryPhone"></div></div><button type="button" class="close" onclick="closeCustomerHistory()">&times;</button></div><div class="history-total" id="customerHistoryStats"></div><div class="table-wrap"><table><thead><tr><th>No.</th><th>Date</th><th>Receipt</th><th>Order</th><th>Method</th><th>Amount</th><th>Status</th><th>Bill</th></tr></thead><tbody id="customerHistoryBody"></tbody></table></div><div style="text-align:right;margin-top:14px"><button type="button" class="btn" style="background:#667085" onclick="closeCustomerHistory()">Close</button></div></div></div>');
const remainingPreview=document.createElement('div');remainingPreview.className='summary';remainingPreview.id='remainingPreview';remainingPreview.innerHTML='<div><span>Remaining after this payment</span><strong id="remainingAfter">Rs. 0.00</strong></div><div style="padding-top:2px"><small id="paymentResultType"></small></div>';document.getElementById('settlementForm').insertBefore(remainingPreview,document.getElementById('changeBox'));
document.getElementById('settleReceived').addEventListener('input',updateChange);document.getElementById('settlementModal').addEventListener('click',e=>{if(e.target.id==='settlementModal')closeSettlement()});
renderRemainingOrders();
renderCustomerPaymentSummary();
</script></body></html>
