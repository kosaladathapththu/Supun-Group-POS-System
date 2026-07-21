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
    if (!in_array($method, $methods, true)) $method = 'Cash';
    $conn->begin_transaction();
    try {
        $oq = $conn->query("SELECT order_id,customer_id,total_amount FROM orders WHERE order_id=$order_id AND order_status='open' FOR UPDATE");
        $order = $oq ? $oq->fetch_assoc() : null;
        if (!$order || (int)$order['customer_id'] <= 0) throw new Exception('This open customer order was not found.');
        $customer_id = (int)$order['customer_id'];
        $total = round((float)$order['total_amount'], 2);
        $dq = $conn->query("SELECT transaction_id,remaining_amount FROM advance_payment_transactions WHERE order_id=$order_id AND customer_id=$customer_id AND transaction_type='deposit' AND remaining_amount>0 ORDER BY created_at,transaction_id FOR UPDATE");
        $deposits = []; $advance_used = 0.0;
        while ($dq && $d = $dq->fetch_assoc()) { $deposits[] = $d; $advance_used += (float)$d['remaining_amount']; }
        $advance_used = min($total, round($advance_used, 2));
        $amount_due = max(0, round($total - $advance_used, 2));
        if ($method === 'Cash' && $received + 0.0001 < $amount_due) throw new Exception('Enter at least Rs. '.number_format($amount_due,2).' to complete this payment.');
        if ($method !== 'Cash') $received = $amount_due;

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
        $stmt=$conn->prepare("UPDATE orders SET advance_used=?,payment_method=?,cash_given=?,balance=?,order_status='paid',payment_status='paid',paid_at=NOW() WHERE order_id=? AND order_status='open'");
        $stmt->bind_param('dsddi',$advance_used,$stored_method,$received,$change,$order_id);
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
$transactions = $conn->query("SELECT t.*,c.account_number,c.customer_name,c.phone,u.full_name,o.order_number,o.order_status,o.total_amount,
    (SELECT COALESCE(SUM(x.remaining_amount),0) FROM advance_payment_transactions x WHERE x.order_id=COALESCE(t.order_id,o.order_id) AND x.customer_id=t.customer_id AND x.transaction_type='deposit') order_advance,
    COALESCE(t.order_id,(SELECT o2.order_id FROM orders o2 WHERE o2.customer_id=t.customer_id AND o2.order_status='open' ORDER BY o2.order_id DESC LIMIT 1)) settlement_order_id
    FROM advance_payment_transactions t JOIN customer_accounts c ON c.customer_id=t.customer_id LEFT JOIN users u ON u.user_id=t.created_by LEFT JOIN orders o ON o.order_id=t.order_id ORDER BY t.transaction_id DESC LIMIT 100");
$summary = $conn->query("SELECT COUNT(*) customers,COALESCE(SUM(advance_balance),0) balance FROM customer_accounts WHERE status=1")->fetch_assoc();
$open_summary = $conn->query("SELECT COUNT(*) open_items,COALESCE(SUM(t.settlement_due_date<CURDATE()),0) overdue FROM advance_payment_transactions t JOIN orders o ON o.order_id=t.order_id WHERE t.transaction_type='deposit' AND o.order_status='open'")->fetch_assoc();
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Customer Advance Payments</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"><style>
*{box-sizing:border-box}body{margin:0;background:#f6f7fb;color:#172033;font-family:Arial,sans-serif}.top{height:68px;background:#fff;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;padding:0 24px}.top a{color:#e85d04;text-decoration:none;font-weight:800}.wrap{max-width:1280px;margin:24px auto;padding:0 18px}.head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}.head h1{margin:0;font-size:25px}.btn{border:0;border-radius:9px;background:#e85d04;color:#fff;padding:11px 16px;font-weight:800;cursor:pointer;text-decoration:none}.grid{display:grid;grid-template-columns:380px 1fr;gap:18px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px;box-shadow:0 3px 12px #1118270a}.card h2{font-size:16px;margin:0 0 15px}.field{margin-bottom:12px}.field label{display:block;font-size:11px;font-weight:800;text-transform:uppercase;color:#667085;margin-bottom:5px}.field input,.field select,.field textarea{width:100%;padding:10px;border:1px solid #d0d5dd;border-radius:8px;font:inherit}.field textarea{height:62px}.hint{font-size:12px;color:#667085;line-height:1.5}.or{text-align:center;font-weight:800;color:#98a2b3;margin:8px}.msg{padding:12px;border-radius:9px;margin-bottom:16px;background:#ecfdf3;color:#027a48}.msg.error{background:#fef3f2;color:#b42318}.stats{display:flex;gap:12px;margin-bottom:15px}.stat{flex:1;background:#fff7ed;border-radius:10px;padding:14px}.stat strong{display:block;font-size:21px;color:#e85d04}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:13px}th{text-align:left;color:#667085;background:#f9fafb;padding:10px}td{padding:11px 10px;border-bottom:1px solid #eaecf0}.money{font-weight:900;color:#027a48}.chip{display:inline-block;padding:4px 8px;border-radius:20px;background:#eff8ff;color:#175cd3;font-size:11px;font-weight:800}.deposit{color:#027a48}.sale_usage{color:#b42318}.search{display:flex;gap:8px;margin-bottom:12px}.search input{flex:1;padding:10px;border:1px solid #d0d5dd;border-radius:8px}@media(max-width:850px){.grid{grid-template-columns:1fr}.top{padding:0 14px}.head{align-items:flex-start;flex-direction:column}}
.modal{display:none;position:fixed;inset:0;background:#10182899;z-index:50;align-items:center;justify-content:center;padding:18px}.modal.open{display:flex}.modal-box{width:min(460px,100%);background:#fff;border-radius:16px;padding:22px;box-shadow:0 20px 50px #10182855}.modal-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:16px}.modal-head h2{margin:0}.close{border:0;background:#f2f4f7;border-radius:7px;width:34px;height:34px;cursor:pointer}.summary{background:#f8fafc;border:1px solid #e4e7ec;border-radius:10px;padding:12px;margin:13px 0}.summary div{display:flex;justify-content:space-between;padding:6px 0}.summary .due{border-top:1px dashed #98a2b3;margin-top:5px;padding-top:11px;color:#027a48;font-weight:900;font-size:16px}.modal-actions{display:flex;gap:9px}.modal-actions>*{flex:1}
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
<section class="card"><h2>Individual Advance Payments &amp; Settlements</h2><div class="hint" style="margin-bottom:12px;">Each advance is followed separately. Complete Payment appears only while its order is open. When an order is fully paid it shows Completed. Any advance not applied to that bill remains visible as customer credit and is not lost.</div><div class="table-wrap"><table><thead><tr><th>Date</th><th>Receipt / Customer</th><th>Order</th><th>Advance</th><th>Unused Credit</th><th>Complete By</th><th>Sale Status</th><th>Actions</th></tr></thead><tbody><?php while($t=$transactions->fetch_assoc()): $is_deposit=$t['transaction_type']==='deposit'; $sale_completed=$t['order_status']==='paid'; $can_complete=$is_deposit && !$sale_completed && (int)$t['settlement_order_id']>0; ?><tr><td><?php echo date('d M Y H:i',strtotime($t['created_at'])); ?></td><td><strong><?php echo htmlspecialchars($t['receipt_number']); ?></strong><br><?php echo htmlspecialchars($t['customer_name']); ?> <small><?php echo htmlspecialchars($t['account_number']); ?></small></td><td><?php echo htmlspecialchars($t['order_number']?:($t['settlement_order_id']?'Open sale #'.$t['settlement_order_id']:'Not linked')); ?></td><td><strong>Rs. <?php echo number_format($t['amount'],2); ?></strong><br><small><?php echo htmlspecialchars($t['payment_method']); ?></small></td><td><?php echo $is_deposit?'Rs. '.number_format($t['remaining_amount'],2):'-'; ?><?php if($sale_completed && (float)$t['remaining_amount']>0): ?><br><small style="color:#b54708;">Available for another sale</small><?php endif; ?></td><td><?php echo $sale_completed?'-':($t['settlement_due_date']?date('d M Y',strtotime($t['settlement_due_date'])):'-'); ?></td><td><span class="chip" style="<?php echo $sale_completed?'background:#ecfdf3;color:#027a48;':''; ?>"><?php echo $sale_completed?'Completed':($t['order_status']==='open'?'Awaiting Payment':ucfirst($t['settlement_status'])); ?></span></td><td><a class="btn" style="padding:7px 9px;white-space:nowrap;" href="print_advance.php?transaction_id=<?php echo (int)$t['transaction_id']; ?>">Print</a><?php if($can_complete): ?><a class="btn" style="display:inline-block;margin-top:5px;padding:7px 9px;white-space:nowrap;background:#027a48;" href="pos.php?order_id=<?php echo (int)$t['settlement_order_id']; ?>">Complete Payment</a><?php elseif($is_deposit && !$sale_completed): ?><a class="btn" style="display:inline-block;margin-top:5px;padding:7px 9px;white-space:nowrap;background:#667085;" href="pos.php">Go to POS</a><?php endif; ?></td></tr><?php endwhile; ?></tbody></table></div></section></div></div></main>
<script>function toggleNewCustomer(){document.getElementById('newCustomer').style.opacity=document.getElementById('customerId').value==='0'?'1':'.4'}toggleNewCustomer();document.querySelectorAll('a[href^="pos.php?order_id="]').forEach(a=>{if(a.textContent.includes('Complete Payment'))a.href+=(a.href.includes('?')?'&':'?')+'settle=1'});</script></body></html>
