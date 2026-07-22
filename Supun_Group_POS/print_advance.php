<?php
session_start();
include 'db.php';
require_once 'includes/advance_accounts.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
ensureAdvancePaymentSchema($conn);

$transaction_id = (int)($_GET['transaction_id'] ?? 0);
$return_order = (int)($_GET['return_order'] ?? 0);
$stmt = $conn->prepare("SELECT t.*,c.account_number,c.customer_name,c.phone,c.address,c.advance_balance,u.full_name cashier_name,o.order_number,o.total_amount order_total,o.subtotal order_subtotal,o.order_type,o.order_status,oc.cancellation_reason
    FROM advance_payment_transactions t
    JOIN customer_accounts c ON c.customer_id=t.customer_id
    LEFT JOIN users u ON u.user_id=t.created_by
    LEFT JOIN orders o ON o.order_id=t.order_id
    LEFT JOIN order_cancellations oc ON oc.refund_transaction_id=t.transaction_id
    WHERE t.transaction_id=? LIMIT 1");
$stmt->bind_param('i', $transaction_id); $stmt->execute();
$payment = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$payment) { http_response_code(404); die('Advance payment receipt not found.'); }

$order_items=[]; $calculated_total=0.0; $payment_history=[]; $total_paid=0.0;
if (!empty($payment['order_id'])) {
    $oid=(int)$payment['order_id'];
    $items=$conn->query("SELECT COALESCE(p.product_name,oi.custom_item_name,'Item') item_name,oi.quantity,oi.price,oi.line_total FROM order_items oi LEFT JOIN products p ON p.product_id=oi.product_id WHERE oi.order_id=$oid ORDER BY oi.order_item_id");
    if($items) while($item=$items->fetch_assoc()){ $order_items[]=$item; $calculated_total+=(float)$item['line_total']; }
    $history=$conn->query("SELECT transaction_id,receipt_number,amount,payment_method,created_at FROM advance_payment_transactions WHERE order_id=$oid AND transaction_type='deposit' AND transaction_id<=$transaction_id ORDER BY transaction_id");
    if($history) while($row=$history->fetch_assoc()){ $payment_history[]=$row; $total_paid+=(float)$row['amount']; }
}
$bill_total=(float)($payment['order_total']??0)>0?(float)$payment['order_total']:$calculated_total;
$remaining_balance=max(0,$bill_total-$total_paid);
$payment_number=count($payment_history);
function ordinalPayment(int $number): string {
    $mod100=$number%100;
    if($mod100>=11 && $mod100<=13) $suffix='th';
    else $suffix=match($number%10){1=>'st',2=>'nd',3=>'rd',default=>'th'};
    return $number.$suffix;
}
$payment_ordinal=$payment_number>0?ordinalPayment($payment_number):'';

$back_url = $return_order > 0 ? 'pos.php' : 'advance_payments.php';
$back_label = $return_order > 0 ? 'Back to POS' : 'Back to Advance Payments';
$is_deposit = $payment['transaction_type'] === 'deposit';
$is_refund = $payment['transaction_type'] === 'refund';
$is_bill_refund = $is_refund && ($payment['order_status']??'') === 'cancelled';
$is_order_installment=$is_deposit && !empty($payment['order_id']) && $payment_number>0;
$title = $is_bill_refund ? 'BILL CANCELLATION & REFUND RECEIPT' : ($is_order_installment ? strtoupper($payment_ordinal.' PAYMENT RECEIPT') : ($is_deposit ? 'ADVANCE PAYMENT RECEIPT' : ($is_refund ? 'ADVANCE SETTLEMENT RECEIPT' : 'ADVANCE USAGE RECEIPT')));
$seal_main = $is_bill_refund ? 'Bill Cancelled' : ($is_refund ? 'Advance Settled' : ($is_order_installment ? $payment_ordinal.' Payment' : 'Advance Payment'));
$seal_small = $is_refund ? 'Refunded' : ($is_deposit ? ($is_order_installment?'Installment Received':'Received') : 'Applied');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo htmlspecialchars($title); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eceff3;color:#111;font-family:Arial,sans-serif}.page{min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:24px}.receipt{position:relative;width:302px;background:#fff;padding:17px 15px 20px;box-shadow:0 8px 30px #0003;font-size:12px;line-height:1.45;overflow:hidden}.logo{display:block;width:210px;max-height:85px;object-fit:contain;margin:0 auto 3px}.shop{text-align:center}.shop h1{margin:0;font-size:23px}.shop p{margin:3px 0;font-size:10px;font-weight:700}.divider{border-top:1px dashed #222;margin:10px 0}.doc-title{text-align:center;font-size:14px;font-weight:900;letter-spacing:.07em;margin:8px 0}.meta{width:100%;border-collapse:collapse}.meta td{padding:2px 0;vertical-align:top}.meta td:first-child{width:42%;font-weight:700}.items{width:100%;border-collapse:collapse;font-size:10px;margin:7px 0}.items th{border-bottom:1px solid #111;text-align:left;padding:4px 2px}.items td{border-bottom:1px dotted #aaa;padding:4px 2px}.items th:not(:first-child),.items td:not(:first-child){text-align:right}.section-title{text-align:center;font-size:10px;font-weight:900;letter-spacing:.08em;margin-top:9px}.payments{width:100%;border-collapse:collapse;font-size:10px}.payments td{padding:3px 2px;border-bottom:1px dotted #bbb}.payments td:last-child{text-align:right;font-weight:800}.amount-box{margin:12px 0;padding:12px 8px;border:2px solid #111;text-align:center}.amount-box small{display:block;font-size:9px;font-weight:800;letter-spacing:.12em}.amount-box strong{display:block;font-size:24px;margin-top:2px}.balance{display:flex;justify-content:space-between;font-weight:800;background:#f3f4f6;padding:8px;margin-top:8px}.balance.due{background:#ecfdf5;color:#047857;font-size:13px}.note{font-size:10px;margin-top:8px}.footer{text-align:center;font-size:10px;font-weight:700;margin-top:15px}.seal-wrap{position:absolute;right:18px;top:55%;z-index:2;pointer-events:none}.advance-seal{width:105px;height:105px;border:4px double #c2410c;border-radius:50%;color:#c2410c;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;transform:rotate(-12deg);font-weight:900;line-height:1;text-transform:uppercase;opacity:.22;-webkit-print-color-adjust:exact;print-color-adjust:exact}.advance-seal:before,.advance-seal:after{content:'★  ★  ★';font-size:9px;letter-spacing:2px}.advance-seal strong{font-size:13px;letter-spacing:1px;margin:7px 0 4px}.advance-seal small{font-size:9px;letter-spacing:1px}.format-picker{display:flex;align-items:center;gap:9px;margin-top:16px;padding:9px 12px;background:#fff;border-radius:8px;box-shadow:0 3px 12px #0002}.format-picker label{font-size:11px;font-weight:900;text-transform:uppercase}.format-picker select{padding:7px 9px;border:1px solid #bbb;border-radius:6px;font-weight:700}.actions{display:flex;gap:9px;margin-top:10px}.actions button,.actions a{border:0;border-radius:8px;padding:11px 17px;font-weight:800;text-decoration:none;cursor:pointer;font-size:13px}.print{background:#1c2038;color:#fff}.back{background:#fff;color:#333;border:1px solid #bbb!important}
body.format-58 .receipt{width:220px;padding:12px 9px;font-size:9px}body.format-58 .logo{width:160px}body.format-58 .shop h1{font-size:17px}body.format-58 .amount-box strong{font-size:18px}body.format-58 .advance-seal{width:68px;height:68px}body.format-58 .advance-seal strong{font-size:9px}
body.format-a4 .receipt{width:190mm;min-height:267mm;padding:16mm 18mm;font-size:14px}body.format-a4 .logo{width:260px}body.format-a4 .meta td:first-child{width:30%}body.format-a4 .advance-seal{width:145px;height:145px}body.format-a4 .advance-seal strong{font-size:18px}
@media print{
@page thermal80{size:80mm auto;margin:0}@page thermal58{size:58mm auto;margin:0}@page advanceA4{size:A4 portrait;margin:10mm}
body{background:#fff}.page{padding:0;display:block}.actions,.format-picker{display:none!important}
body.format-80 .receipt{page:thermal80;width:76mm;box-shadow:none;margin:0 auto;padding:3mm}
body.format-58 .receipt{page:thermal58;width:54mm;box-shadow:none;margin:0 auto;padding:2mm}
html:has(body.format-a4),body.format-a4{width:190mm!important;min-width:190mm!important;max-width:190mm!important;height:auto!important;min-height:0!important;margin:0!important;padding:0!important;overflow:visible!important}
body.format-a4 .page{position:relative!important;width:190mm!important;height:0!important;min-height:0!important;margin:0!important;padding:0!important;overflow:visible!important}
body.format-a4 .page>.actions,body.format-a4 .page>.format-picker{display:none!important;width:0!important;height:0!important;margin:0!important;padding:0!important}
body.format-a4 .receipt{page:advanceA4;position:absolute!important;top:0!important;left:0!important;box-sizing:border-box!important;width:190mm!important;max-width:190mm!important;min-height:0!important;height:auto!important;margin:0!important;padding:8mm 12mm!important;box-shadow:none!important;font-size:13px!important;line-height:1.28!important;overflow:visible!important;page-break-inside:avoid!important;break-inside:avoid-page!important;display:block!important}
body.format-a4 .logo{width:62mm!important;max-height:25mm!important}body.format-a4 .shop h1{font-size:26px!important}body.format-a4 .shop p{font-size:10px!important}
body.format-a4 .divider{margin:6px 0!important}body.format-a4 .doc-title{font-size:16px!important;margin:5px 0!important}
body.format-a4 .meta{font-size:12px!important;line-height:1.2!important}body.format-a4 .meta td{padding:2px 0!important}body.format-a4 .meta td:first-child{width:31%!important}
body.format-a4 .items,body.format-a4 .payments{font-size:11px!important;margin:6px 0!important}body.format-a4 .items th,body.format-a4 .items td,body.format-a4 .payments td{padding:4px 3px!important}
body.format-a4 .section-title{font-size:11px!important;margin-top:7px!important}body.format-a4 .amount-box{margin:8px 0!important;padding:9px!important}body.format-a4 .amount-box small{font-size:10px!important}body.format-a4 .amount-box strong{font-size:25px!important}
body.format-a4 .balance{padding:7px 9px!important;margin-top:5px!important;font-size:12px!important}body.format-a4 .balance.due{font-size:13px!important}body.format-a4 .note{font-size:11px!important;margin-top:6px!important}body.format-a4 .footer{font-size:10.5px!important;margin-top:8mm!important;padding-top:0!important;page-break-before:avoid!important;break-before:avoid-page!important}
body.format-a4 .seal-wrap{top:52%!important;right:8%!important}body.format-a4 .advance-seal{width:30mm!important;height:30mm!important;border-width:1mm!important}body.format-a4 .advance-seal strong{font-size:14px!important}
}
</style></head><body class="format-80"><main class="page"><section class="receipt">
<div class="seal-wrap"><div class="advance-seal"><strong><?php echo htmlspecialchars($seal_main); ?></strong><small><?php echo htmlspecialchars($seal_small); ?></small></div></div>
<div class="shop"><img class="logo" src="supun-logo.png" alt="Supun Group"><h1>SUPUN GROUP</h1><p>Retail &amp; Wholesale</p></div>
<div class="divider"></div><div class="doc-title"><?php echo htmlspecialchars($title); ?></div><div class="divider"></div>
<table class="meta">
<tr><td>Receipt No.</td><td>: <?php echo htmlspecialchars($payment['receipt_number']); ?></td></tr>
<tr><td>Date / Time</td><td>: <?php echo date('d M Y, h:i A',strtotime($payment['created_at'])); ?></td></tr>
<tr><td>Account No.</td><td>: <?php echo htmlspecialchars($payment['account_number']); ?></td></tr>
<tr><td>Customer</td><td>: <?php echo htmlspecialchars($payment['customer_name']); ?></td></tr>
<?php if($payment['phone']): ?><tr><td>Phone</td><td>: <?php echo htmlspecialchars($payment['phone']); ?></td></tr><?php endif; ?>
<tr><td>Payment Method</td><td>: <?php echo htmlspecialchars($payment['payment_method']); ?></td></tr>
<tr><td>Received By</td><td>: <?php echo htmlspecialchars($payment['cashier_name']??'-'); ?></td></tr>
<?php if($payment['order_number']): ?><tr><td>Order</td><td>: <?php echo htmlspecialchars($payment['order_number']); ?></td></tr><?php endif; ?>
<?php if($payment_number>0): ?><tr><td>Payment No.</td><td>: <?php echo $payment_number; ?></td></tr><?php endif; ?>
</table>
<?php if($order_items): ?><div class="section-title">ITEMS / PRODUCTS</div><table class="items"><thead><tr><th>Item</th><th>Qty</th><th>Rate</th><th>Amount</th></tr></thead><tbody><?php foreach($order_items as $item): ?><tr><td><?php echo htmlspecialchars($item['item_name']); ?></td><td><?php echo number_format((float)$item['quantity'],(float)$item['quantity']==(int)$item['quantity']?0:2); ?></td><td><?php echo number_format((float)$item['price'],2); ?></td><td><?php echo number_format((float)$item['line_total'],2); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
<?php if($payment['order_id']): ?><div class="balance"><span>Bill Total</span><span>Rs. <?php echo number_format($bill_total,2); ?></span></div><?php endif; ?>
<div class="amount-box"><small><?php echo $is_deposit?'ADVANCE AMOUNT RECEIVED':($is_refund?'ADVANCE AMOUNT REFUNDED':'ADVANCE AMOUNT USED'); ?></small><strong>Rs. <?php echo number_format((float)$payment['amount'],2); ?></strong></div>
<?php if($payment_history): ?><div class="section-title">PAYMENT HISTORY</div><table class="payments"><?php foreach($payment_history as $index=>$paid): ?><tr><td><?php echo ($index+1).'. '.date('d M Y',strtotime($paid['created_at'])); ?><br><small><?php echo htmlspecialchars($paid['receipt_number'].' · '.$paid['payment_method']); ?></small></td><td>Rs. <?php echo number_format((float)$paid['amount'],2); ?></td></tr><?php endforeach; ?></table><?php endif; ?>
<?php if($is_bill_refund): ?><div class="balance"><span>Original Bill Total</span><span>Rs. <?php echo number_format($bill_total,2); ?></span></div><div class="balance due" style="background:#fef3f2;color:#b42318"><span>Bill Status</span><span>CANCELLED &amp; REFUNDED</span></div>
<?php else: ?><div class="balance"><span>Total Paid</span><span>Rs. <?php echo number_format($total_paid,2); ?></span></div><div class="balance due"><span>Remaining Balance</span><span>Rs. <?php echo number_format($remaining_balance,2); ?></span></div><?php endif; ?>
<?php if($payment['reference_note']): ?><div class="note"><strong>Reference:</strong> <?php echo htmlspecialchars($payment['reference_note']); ?></div><?php endif; ?>
<div class="divider"></div><div class="footer"><?php echo $is_bill_refund?'This receipt confirms the bill cancellation and customer refund.':'This receipt confirms an advance payment only.'; ?><br>Thank you for your business.</div>
</section><div class="format-picker"><label for="printFormat">Paper size</label><select id="printFormat" onchange="setPrintFormat(this.value)"><option value="80">Thermal 80mm</option><option value="58">Thermal 58mm</option><option value="a4">A4 Page</option></select></div><div class="actions"><button class="print" onclick="window.print()">Print Advance Bill</button><a class="back" href="<?php echo htmlspecialchars($back_url); ?>">&larr; <?php echo htmlspecialchars($back_label); ?></a></div></main>
<script>function setPrintFormat(format){document.body.className='format-'+format;localStorage.setItem('advancePrintFormat',format)}window.addEventListener('load',()=>{const saved=localStorage.getItem('advancePrintFormat')||'a4';document.getElementById('printFormat').value=saved;setPrintFormat(saved)});</script></body></html>
