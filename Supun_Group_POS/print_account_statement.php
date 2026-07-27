<?php
session_start();
include "db.php";
require_once "includes/advance_accounts.php";
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}
ensureAdvancePaymentSchema($conn);
$customer_id = (int) ($_GET["customer_id"] ?? 0);
if ($customer_id <= 0) {
    die("Invalid customer account.");
}
$stmt = $conn->prepare(
    "SELECT c.*,(SELECT COALESCE(SUM(d.remaining_amount),0) FROM advance_payment_transactions d WHERE d.customer_id=c.customer_id AND d.transaction_type='deposit' AND d.order_id IS NULL) available_credit FROM customer_accounts c WHERE c.customer_id=? LIMIT 1",
);
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$customer) {
    die("Customer account not found.");
}
$deposits = [];
$total_received = 0;
$stmt = $conn->prepare(
    "SELECT receipt_number,amount,payment_method,reference_note,created_at FROM advance_payment_transactions WHERE customer_id=? AND transaction_type='deposit' AND order_id IS NULL ORDER BY created_at,transaction_id",
);
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $deposits[] = $row;
    $total_received += (float) $row["amount"];
}
$stmt->close();
$usages = [];
$total_used = 0;
$stmt = $conn->prepare(
    "SELECT u.order_id,COALESCE(o.order_number,CONCAT('Order #',u.order_id)) order_number,o.total_amount bill_total,o.order_status,COALESCE(o.paid_at,o.created_at,MAX(u.created_at)) used_at,SUM(u.amount) amount_used,(SELECT GROUP_CONCAT(CONCAT(COALESCE(p.product_name,oi.custom_item_name,'Item'),' x',oi.quantity) ORDER BY oi.order_item_id SEPARATOR ', ') FROM order_items oi LEFT JOIN products p ON p.product_id=oi.product_id WHERE oi.order_id=u.order_id) items FROM advance_payment_transactions u JOIN advance_payment_transactions d ON d.transaction_id=u.parent_transaction_id LEFT JOIN orders o ON o.order_id=u.order_id WHERE u.customer_id=? AND u.transaction_type='sale_usage' AND d.transaction_type='deposit' AND d.order_id IS NULL GROUP BY u.order_id,o.order_number,o.total_amount,o.order_status,o.paid_at,o.created_at ORDER BY used_at,u.order_id",
);
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $usages[] = $row;
    $total_used += (float) $row["amount_used"];
}
$stmt->close();
$available = (float) $customer["available_credit"];
function statementMoney($value)
{
    return "Rs. " . number_format((float) $value, 2);
}
?>
<!doctype html>
<html lang="en">
    <head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Account Statement - <?php echo htmlspecialchars(
        $customer["account_number"],
    ); ?></title><style>
*{box-sizing:border-box}body{margin:0;background:#e5e7eb;color:#111827;font-family:Arial,sans-serif}.sheet{width:210mm;min-height:297mm;margin:18px auto;background:#fff;padding:15mm;box-shadow:0 4px 20px #0002}.head{text-align:center;border-bottom:2px solid #111827;padding-bottom:12px}.logo{max-width:115px;max-height:72px}.head h1{margin:4px 0 2px;font-size:24px}.head p{margin:0;font-size:12px}.details{display:grid;grid-template-columns:1fr 1fr;gap:7px 25px;margin:18px 0;font-size:13px}.details div{display:flex;gap:8px}.details strong{min-width:100px}.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:14px 0}.box{border:1px solid #d1d5db;border-radius:8px;padding:11px;text-align:center}.box span{display:block;font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:700}.box strong{display:block;margin-top:5px;font-size:17px}.balance{color:#047857}h2{font-size:15px;margin:20px 0 8px}table{width:100%;border-collapse:collapse;font-size:11px}th{background:#f3f4f6;text-align:left;padding:7px;border-bottom:1px solid #9ca3af}td{padding:7px;border-bottom:1px solid #e5e7eb;vertical-align:top}.right{text-align:right;white-space:nowrap}.used{color:#b42318;font-weight:700}.footer{text-align:center;border-top:1px dashed #9ca3af;margin-top:24px;padding-top:10px;font-size:11px}.actions{position:fixed;right:20px;top:20px;display:flex;gap:8px}.actions a,.actions button{border:0;border-radius:7px;padding:10px 14px;background:#047857;color:#fff;font-weight:700;text-decoration:none;cursor:pointer}.actions a{background:#374151}@page{size:A4 portrait;margin:0}@media print{body{background:#fff}.sheet{margin:0;box-shadow:none;width:210mm;min-height:297mm}.actions{display:none}tr{break-inside:avoid}}
</style></head><body><div class="actions"><a href="advance_payments.php">Back</a><button onclick="window.print()">Print Whole Statement</button></div><main class="sheet"><header class="head"><img class="logo" src="st-logo.svg" alt="ST Pvt Ltd."><h1>ST Pvt Ltd.</h1><p>Supun Traders Private Limited</p><h2>Customer Account Credit Statement</h2><p>Complete history of money received and used for purchases</p></header>
<section class="details"><div><strong>Account No.</strong><span><?php echo htmlspecialchars(
    $customer["account_number"],
); ?></span></div><div><strong>Printed</strong><span><?php echo date(
    "d M Y h:i A",
); ?></span></div><div><strong>Customer</strong><span><?php echo htmlspecialchars(
    $customer["customer_name"],
); ?></span></div><div><strong>Phone</strong><span><?php echo htmlspecialchars(
    $customer["phone"] ?: "-",
); ?></span></div><div><strong>Address</strong><span><?php echo htmlspecialchars(
    $customer["address"] ?: "-",
); ?></span></div></section>
<section class="summary"><div class="box"><span>Total Credit Received</span><strong><?php echo statementMoney(
    $total_received,
); ?></strong></div><div class="box"><span>Total Used for Purchases</span><strong><?php echo statementMoney(
    $total_used,
); ?></strong></div><div class="box"><span>Remaining Account Credit</span><strong class="balance"><?php echo statementMoney(
    $available,
); ?></strong></div></section>
<h2>Account Credit Payments Received</h2><table><thead><tr><th>No.</th><th>Date</th><th>Receipt</th><th>Method</th><th>Purpose / Note</th><th class="right">Received</th></tr></thead><tbody><?php if (
    !$deposits
): ?><tr><td colspan="6">No account-credit payments recorded.</td></tr><?php else:foreach (
        $deposits
        as $i => $row
    ): ?><tr><td><?php echo $i + 1; ?></td><td><?php echo date(
    "d M Y h:i A",
    strtotime($row["created_at"]),
); ?></td><td><?php echo htmlspecialchars(
    $row["receipt_number"],
); ?></td><td><?php echo htmlspecialchars(
    $row["payment_method"],
); ?></td><td><?php echo htmlspecialchars(
    $row["reference_note"] ?: "-",
); ?></td><td class="right"><?php echo statementMoney(
    $row["amount"],
); ?></td></tr><?php endforeach;endif; ?></tbody></table>
<h2>Purchases Paid from Account Credit</h2><table><thead><tr><th>No.</th><th>Date</th><th>Order</th><th>Purchased Items</th><th class="right">Bill Total</th><th class="right">Credit Used</th><th>Status</th></tr></thead><tbody><?php if (
    !$usages
): ?><tr><td colspan="7">Account credit has not been used for a purchase.</td></tr><?php else:foreach (
        $usages
        as $i => $row
    ): ?><tr><td><?php echo $i + 1; ?></td><td><?php echo date(
    "d M Y h:i A",
    strtotime($row["used_at"]),
); ?></td><td><?php echo htmlspecialchars(
    $row["order_number"],
); ?></td><td><?php echo htmlspecialchars(
    $row["items"] ?: "Order items",
); ?></td><td class="right"><?php echo statementMoney(
    $row["bill_total"],
); ?></td><td class="right used"><?php echo statementMoney(
    $row["amount_used"],
); ?></td><td><?php echo htmlspecialchars(
    ucfirst($row["order_status"] ?: "paid"),
); ?></td></tr><?php endforeach;endif; ?></tbody></table>
<footer class="footer"><strong>Remaining account credit: <?php echo statementMoney(
    $available,
); ?></strong><br>This statement contains the complete account-credit payment and usage history.</footer></main></body></html>
