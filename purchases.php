<?php
require __DIR__.'/bootstrap.php';
require_auth();
if(!can('purchases.view')){http_response_code(403);exit('Forbidden');}
require __DIR__.'/partials.php';
$rows=$db->query('SELECT p.*,s.name supplier FROM purchases p JOIN suppliers s ON s.id=p.supplier_id ORDER BY p.id DESC LIMIT 50')->fetchAll();
page_start('Receive Stock','purchases.php');
?>
<section class="action-banner"><div><span class="step-number">1</span><div><b>How do you want to add stock?</b><p>Use manual entry for one invoice, or Excel when the supplier sends many products.</p></div></div><div class="action-buttons"><a class="btn primary" href="purchase_new.php">Type a Purchase</a><?php if(can('imports.manage')):?><a class="btn secondary" href="bulk_import.php">Upload Excel</a><?php endif;?></div></section>
<section class="panel table-panel">
  <div class="panel-head"><div><span class="eyebrow">Purchase history</span><h3>Received supplier invoices</h3></div></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Our No.</th><th>Supplier Invoice</th><th>Supplier</th><th>Date</th><th>Payment</th><th>Payment Status</th><th class="right">Total</th></tr></thead>
    <tbody>
    <?php if(!$rows):?><tr><td colspan="7"><div class="empty-state compact"><b>No purchases yet</b><span>Choose “Type a Purchase” or “Upload Excel” above.</span></div></td></tr><?php endif;?>
    <?php foreach($rows as $r):
      $paid=(float)$r['paid_amount'];
      $balance=(float)$r['balance'];
      $paymentStatus=$balance<=0?'paid':($paid>0?'partially_paid':'unpaid');
    ?>
      <tr class="purchase-payment-<?=$paymentStatus?>">
        <td><b><?=e($r['purchase_no'])?></b><br><a class="row-edit" href="edit_record.php?type=purchase&id=<?=$r['id']?>">Correct</a></td>
        <td><?=e($r['supplier_invoice'])?></td><td><?=e($r['supplier'])?></td><td><?=date('d M Y',strtotime($r['purchase_date']))?></td>
        <td><span class="tag"><?=e($r['payment_type'])?></span><div class="purchase-payment-values"><small>Paid <?=money($paid)?></small><?php if($balance>0):?><small>Due <?=money($balance)?></small><?php endif;?></div></td>
        <td><span class="status purchase-payment-status <?=$paymentStatus?>"><?=e(ucwords(str_replace('_',' ',$paymentStatus)))?></span></td>
        <td class="right"><b><?=money($r['total'])?></b></td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table></div>
</section>
<?php page_end();
