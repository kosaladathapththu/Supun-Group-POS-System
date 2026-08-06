<?php
require __DIR__.'/bootstrap.php';
require_auth();
if ((user()['role_code'] ?? '') !== 'main_admin') { http_response_code(403); exit('Only the Main Admin Account can manage supplier payments.'); }
require __DIR__.'/partials.php';
require __DIR__.'/purchase_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $purchaseId = (int)($_POST['purchase_id'] ?? 0);
    $amount = max(0, (float)($_POST['amount'] ?? 0));
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT p.*,s.name supplier FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE p.id=? FOR UPDATE');
        $stmt->execute([$purchaseId]);
        $purchase = $stmt->fetch();
        if (!$purchase) throw new RuntimeException('Choose a supplier bill.');
        if ($amount <= 0) throw new RuntimeException('Enter the payment amount.');
        if ($amount > (float)$purchase['balance']) throw new RuntimeException('Payment cannot be more than the amount still owed.');
        $newPaid = (float)$purchase['paid_amount'] + $amount;
        $newBalance = (float)$purchase['balance'] - $amount;
        $db->prepare('UPDATE purchases SET paid_amount=?,balance=?,payment_type=? WHERE id=?')->execute([$newPaid,$newBalance,$newBalance > 0 ? 'credit' : 'cash',$purchaseId]);
        $db->prepare('UPDATE suppliers SET outstanding=GREATEST(0,outstanding-?) WHERE id=?')->execute([$amount,$purchase['supplier_id']]);
        $paymentNo = next_number($db,'supplier_payments','SPAY');
        $db->prepare('INSERT INTO supplier_payments(payment_no,supplier_id,purchase_id,payment_date,amount,method,reference_no,notes,created_by) VALUES(?,?,?,NOW(),?,?,?,?,?)')->execute([$paymentNo,$purchase['supplier_id'],$purchaseId,$amount,$_POST['method'] ?? 'cash',trim($_POST['reference_no'] ?? ''),trim($_POST['notes'] ?? ''),user()['id']]);
        audit($db,'create','supplier_payment',(int)$db->lastInsertId(),null,['payment_no'=>$paymentNo,'purchase_id'=>$purchaseId,'amount'=>$amount]);
        $db->commit();
        flash('success',"Supplier payment $paymentNo saved.");
        redirect('supplier_payments.php');
    } catch (Throwable $e) {
        $db->rollBack();
        flash('danger',$e->getMessage());
        redirect('supplier_payments.php');
    }
}

$payables = $db->query('SELECT p.id,p.purchase_no,p.supplier_invoice,p.purchase_date,p.total,p.paid_amount,p.balance,s.name supplier FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE p.balance>0 AND p.status="posted" ORDER BY p.purchase_date,p.id')->fetchAll();
$recent = $db->query('SELECT sp.id,sp.payment_no,sp.payment_date,sp.amount,sp.method,sp.reference_no,sp.status,sp.reversal_type,sp.reversal_reason,s.name supplier,p.purchase_no FROM supplier_payments sp JOIN suppliers s ON s.id=sp.supplier_id LEFT JOIN purchases p ON p.id=sp.purchase_id ORDER BY sp.id DESC LIMIT 30')->fetchAll();
$selected = (int)($_GET['purchase_id'] ?? 0);
page_start('Supplier Bills & Payments','purchases.php');
?>
<section class="action-banner"><div><span class="step-number">Rs</span><div><b>Supplier bills and payments</b><p>See the full bill, payments already made, and the amount still to pay.</p></div></div><a class="btn secondary" href="purchases.php">&larr; Stock &amp; Purchases</a></section>
<div class="page-grid supplier-payment-layout">
 <section class="panel table-panel">
  <div class="panel-head"><div><span class="eyebrow">Money the business owes</span><h3><?=count($payables)?> supplier bill(s) still to pay</h3></div></div>
  <div class="table-wrap"><table><thead><tr><th>Supplier</th><th>Purchase / Status</th><th>Date</th><th class="right">Full Bill Price</th><th class="right">Paid So Far</th><th class="right">Yet to Pay</th><th></th></tr></thead><tbody>
  <?php if (!$payables): ?><tr><td colspan="7"><div class="empty-state compact"><b>No supplier bills waiting for payment</b></div></td></tr><?php endif; ?>
  <?php foreach ($payables as $row): $billStatus=(float)$row['paid_amount'] > 0 ? 'Partially Paid' : 'Unpaid'; ?>
   <tr><td><b><?=e($row['supplier'])?></b></td><td><?=e($row['purchase_no'])?><br><small><?=e($row['supplier_invoice'])?></small><br><span class="bill-payment-status <?=strtolower(str_replace(' ','-',$billStatus))?>"><?=e($billStatus)?></span></td><td><?=date('d M Y',strtotime($row['purchase_date']))?></td><td class="right"><?=money($row['total'])?></td><td class="right paid-so-far"><?=money($row['paid_amount'])?></td><td class="right yet-to-pay"><b><?=money($row['balance'])?></b></td><td><a class="btn secondary" href="?purchase_id=<?=$row['id']?>#pay-supplier">Pay</a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
 </section>
 <aside class="panel" id="pay-supplier">
  <div class="panel-head"><div><span class="eyebrow">New supplier payment</span><h3>Pay a supplier bill</h3></div></div>
  <form method="post"><input type="hidden" name="_csrf" value="<?=csrf()?>"><div class="simple-fields">
   <label>Supplier bill<select name="purchase_id" required><option value="">Choose unpaid bill</option><?php foreach($payables as $row):?><option value="<?=$row['id']?>" data-total="<?=$row['total']?>" data-paid="<?=$row['paid_amount']?>" data-balance="<?=$row['balance']?>" <?=$selected===$row['id']?'selected':''?>><?=e($row['supplier'].' - '.$row['purchase_no'].' - Yet to pay '.money($row['balance']))?></option><?php endforeach;?></select></label>
   <div class="selected-bill-breakdown" hidden><div><span>Full Bill Price</span><b data-bill-total><?=money(0)?></b></div><div><span>Paid So Far</span><b data-bill-paid><?=money(0)?></b></div><div><span>Yet to Pay</span><b data-bill-due><?=money(0)?></b></div><strong data-bill-status>Unpaid</strong></div>
   <label>Amount paying now<input type="number" name="amount" min=".01" step=".01" required></label>
   <label>Paid using<select name="method"><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="cheque">Cheque</option><option value="card">Card</option></select></label>
   <label>Reference<input name="reference_no" placeholder="Bank or cheque reference"></label><label>Notes<textarea name="notes" rows="2"></textarea></label><button class="btn primary wide">Save Supplier Payment</button>
  </div></form>
 </aside>
</div>
<section class="panel table-panel" style="margin-top:20px"><div class="panel-head"><div><span class="eyebrow">Payment history</span><h3>Recent supplier payments</h3></div></div><div class="table-wrap"><table><thead><tr><th>Payment</th><th>Supplier</th><th>Purchase</th><th>Date</th><th>Method</th><th>Reference</th><th class="right">Payment Amount</th></tr></thead><tbody><?php if(!$recent):?><tr><td colspan="7"><div class="empty-state compact"><b>No supplier payments recorded</b></div></td></tr><?php endif;?><?php foreach($recent as $row):?><tr><td><b><?=e($row['payment_no'])?></b></td><td><?=e($row['supplier'])?></td><td><?=e($row['purchase_no']??'-')?></td><td><?=date('d M Y, H:i',strtotime($row['payment_date']))?></td><td><?=e(str_replace('_',' ',$row['method']))?></td><td><?=e($row['reference_no']?:'-')?></td><td class="right"><b><?=money($row['amount'])?></b></td></tr><?php endforeach;?></tbody></table></div></section>
<script>window.supplierPaymentEditMap=<?=json_encode(array_column($recent,'id','payment_no'))?>;window.supplierPaymentStatusMap=<?=json_encode(array_column($recent,'status','payment_no'))?>;</script>
<?php page_end();
