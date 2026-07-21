<?php
session_start();
include 'db.php';
require_once 'includes/advance_accounts.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
ensureAdvancePaymentSchema($conn);

$transaction_id = (int)($_GET['transaction_id'] ?? 0);
$return_order = (int)($_GET['return_order'] ?? 0);
$stmt = $conn->prepare("SELECT t.*,c.account_number,c.customer_name,c.phone,c.address,c.advance_balance,u.full_name cashier_name,o.order_number
    FROM advance_payment_transactions t
    JOIN customer_accounts c ON c.customer_id=t.customer_id
    LEFT JOIN users u ON u.user_id=t.created_by
    LEFT JOIN orders o ON o.order_id=t.order_id
    WHERE t.transaction_id=? LIMIT 1");
$stmt->bind_param('i', $transaction_id); $stmt->execute();
$payment = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$payment) { http_response_code(404); die('Advance payment receipt not found.'); }

$back_url = $return_order > 0 ? 'pos.php?order_id='.$return_order.'&advance_created=1' : 'advance_payments.php';
$is_deposit = $payment['transaction_type'] === 'deposit';
$title = $is_deposit ? 'ADVANCE PAYMENT RECEIPT' : 'ADVANCE USAGE RECEIPT';
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo htmlspecialchars($title); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eceff3;color:#111;font-family:Arial,sans-serif}.page{min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:24px}.receipt{position:relative;width:302px;background:#fff;padding:17px 15px 20px;box-shadow:0 8px 30px #0003;font-size:12px;line-height:1.45;overflow:hidden}.logo{display:block;width:210px;max-height:85px;object-fit:contain;margin:0 auto 3px}.shop{text-align:center}.shop h1{margin:0;font-size:23px}.shop p{margin:3px 0;font-size:10px;font-weight:700}.divider{border-top:1px dashed #222;margin:10px 0}.doc-title{text-align:center;font-size:14px;font-weight:900;letter-spacing:.07em;margin:8px 0}.meta{width:100%;border-collapse:collapse}.meta td{padding:2px 0;vertical-align:top}.meta td:first-child{width:42%;font-weight:700}.amount-box{margin:12px 0;padding:12px 8px;border:2px solid #111;text-align:center}.amount-box small{display:block;font-size:9px;font-weight:800;letter-spacing:.12em}.amount-box strong{display:block;font-size:24px;margin-top:2px}.balance{display:flex;justify-content:space-between;font-weight:800;background:#f3f4f6;padding:8px;margin-top:8px}.note{font-size:10px;margin-top:8px}.footer{text-align:center;font-size:10px;font-weight:700;margin-top:15px}.seal-wrap{position:absolute;right:18px;top:55%;z-index:2;pointer-events:none}.advance-seal{width:105px;height:105px;border:4px double #c2410c;border-radius:50%;color:#c2410c;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;transform:rotate(-12deg);font-weight:900;line-height:1;text-transform:uppercase;opacity:.22;-webkit-print-color-adjust:exact;print-color-adjust:exact}.advance-seal:before,.advance-seal:after{content:'★  ★  ★';font-size:9px;letter-spacing:2px}.advance-seal strong{font-size:13px;letter-spacing:1px;margin:7px 0 4px}.advance-seal small{font-size:9px;letter-spacing:1px}.format-picker{display:flex;align-items:center;gap:9px;margin-top:16px;padding:9px 12px;background:#fff;border-radius:8px;box-shadow:0 3px 12px #0002}.format-picker label{font-size:11px;font-weight:900;text-transform:uppercase}.format-picker select{padding:7px 9px;border:1px solid #bbb;border-radius:6px;font-weight:700}.actions{display:flex;gap:9px;margin-top:10px}.actions button,.actions a{border:0;border-radius:8px;padding:11px 17px;font-weight:800;text-decoration:none;cursor:pointer;font-size:13px}.print{background:#1c2038;color:#fff}.back{background:#fff;color:#333;border:1px solid #bbb!important}
body.format-58 .receipt{width:220px;padding:12px 9px;font-size:9px}body.format-58 .logo{width:160px}body.format-58 .shop h1{font-size:17px}body.format-58 .amount-box strong{font-size:18px}body.format-58 .advance-seal{width:68px;height:68px}body.format-58 .advance-seal strong{font-size:9px}
body.format-a4 .receipt{width:190mm;min-height:267mm;padding:16mm 18mm;font-size:14px}body.format-a4 .logo{width:260px}body.format-a4 .meta td:first-child{width:30%}body.format-a4 .advance-seal{width:145px;height:145px}body.format-a4 .advance-seal strong{font-size:18px}
@media print{@page thermal80{size:80mm auto;margin:0}@page thermal58{size:58mm auto;margin:0}@page advanceA4{size:A4 portrait;margin:10mm}body{background:#fff}.page{padding:0;display:block}.actions,.format-picker{display:none!important}body.format-80 .receipt{page:thermal80;width:76mm;box-shadow:none;margin:0 auto;padding:3mm}body.format-58 .receipt{page:thermal58;width:54mm;box-shadow:none;margin:0 auto;padding:2mm}body.format-a4 .receipt{page:advanceA4;width:190mm;min-height:267mm;box-shadow:none;margin:0 auto;padding:14mm 16mm}}
</style></head><body class="format-80"><main class="page"><section class="receipt">
<div class="seal-wrap"><div class="advance-seal"><strong>Advance<br>Payment</strong><small>Received</small></div></div>
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
</table>
<div class="amount-box"><small><?php echo $is_deposit?'ADVANCE AMOUNT RECEIVED':'ADVANCE AMOUNT USED'; ?></small><strong>Rs. <?php echo number_format((float)$payment['amount'],2); ?></strong></div>
<div class="balance"><span>Current Advance Balance</span><span>Rs. <?php echo number_format((float)$payment['advance_balance'],2); ?></span></div>
<?php if($payment['reference_note']): ?><div class="note"><strong>Reference:</strong> <?php echo htmlspecialchars($payment['reference_note']); ?></div><?php endif; ?>
<div class="divider"></div><div class="footer">This receipt confirms an advance payment only.<br>Thank you for your business.</div>
</section><div class="format-picker"><label for="printFormat">Paper size</label><select id="printFormat" onchange="setPrintFormat(this.value)"><option value="80">Thermal 80mm</option><option value="58">Thermal 58mm</option><option value="a4">A4 Page</option></select></div><div class="actions"><button class="print" onclick="window.print()">Print Advance Bill</button><a class="back" href="<?php echo htmlspecialchars($back_url); ?>">Back</a></div></main>
<script>function setPrintFormat(format){document.body.className='format-'+format;localStorage.setItem('advancePrintFormat',format)}window.addEventListener('load',()=>{const saved=localStorage.getItem('advancePrintFormat')||'80';document.getElementById('printFormat').value=saved;setPrintFormat(saved)});</script></body></html>
