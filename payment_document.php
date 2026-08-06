<?php
require __DIR__.'/bootstrap.php';
require_auth();
require __DIR__.'/partials.php';
$type = ($_GET['type'] ?? '') === 'supplier' ? 'supplier' : 'customer';
$id = (int)($_GET['id'] ?? 0);

if ($type === 'customer') {
    if (!can('payments.view')) { http_response_code(403); exit('Forbidden'); }
    $stmt=$db->prepare('SELECT p.*,c.name party,c.customer_code code,c.phone,c.address,c.outstanding,u.display_name recorded_by FROM customer_payments p JOIN customers c ON c.id=p.customer_id JOIN users u ON u.id=p.collected_by WHERE p.id=?');
    $stmt->execute([$id]); $document=$stmt->fetch();
    if (!$document) { http_response_code(404); exit('Payment receipt not found.'); }
    $alloc=$db->prepare('SELECT s.sale_no,cpa.amount FROM customer_payment_allocations cpa JOIN sales s ON s.id=cpa.sale_id WHERE cpa.payment_id=? ORDER BY cpa.id');
    $alloc->execute([$id]); $allocations=$alloc->fetchAll();
    $title='Customer Payment Receipt'; $number=$document['receipt_no']; $partyLabel='Received From'; $balanceLabel='Customer Balance After Receipt'; $balance=$document['outstanding'];
} else {
    if ((user()['role_code'] ?? '') !== 'main_admin') { http_response_code(403); exit('Forbidden'); }
    $stmt=$db->prepare('SELECT sp.*,s.name party,s.supplier_code code,s.phone,s.address,s.outstanding,p.purchase_no,p.supplier_invoice,u.display_name recorded_by FROM supplier_payments sp JOIN suppliers s ON s.id=sp.supplier_id LEFT JOIN purchases p ON p.id=sp.purchase_id JOIN users u ON u.id=sp.created_by WHERE sp.id=?');
    $stmt->execute([$id]); $document=$stmt->fetch();
    if (!$document) { http_response_code(404); exit('Supplier payment voucher not found.'); }
    $allocations=$document['purchase_no']?[['sale_no'=>$document['purchase_no'].' / '.$document['supplier_invoice'],'amount'=>$document['amount']]]:[];
    $title='Supplier Payment Voucher'; $number=$document['payment_no']; $partyLabel='Paid To'; $balanceLabel='Supplier Balance After Payment'; $balance=$document['outstanding'];
}
page_start($title,'payments.php');
?>
<div class="document-actions"><a class="btn secondary" href="<?=$type==='customer'?'payments.php':'supplier_payments.php'?>">&larr; Back</a><button class="btn primary" onclick="window.print()">Print / Save PDF</button></div>
<section class="payment-document panel">
 <header class="document-brand"><div><img src="assets/supun-traders-logo.png" alt="Supun Traders"><p>114, Second Cross Street, Pettah, Colombo 11</p></div><div class="right"><span class="eyebrow"><?=e($title)?></span><h2><?=e($number)?></h2><p><?=date('d F Y, h:i A',strtotime($document['payment_date']))?></p></div></header>
 <div class="document-party"><div><span><?=e($partyLabel)?></span><h3><?=e($document['party'])?></h3><p><?=e($document['code'])?><?=!empty($document['phone'])?' · '.e($document['phone']):''?></p><p><?=e($document['address'] ?? '')?></p></div><div><span>Payment Method</span><b><?=e(ucwords(str_replace('_',' ',$document['method'])))?></b><p>Reference: <?=e($document['reference_no'] ?: 'None')?></p></div></div>
 <div class="payment-amount-box"><span><?=$type==='customer'?'Amount Received':'Amount Paid'?></span><strong><?=money($document['amount'])?></strong><?php if(($document['status']??'posted')==='reversed'):?><b class="payment-reversed-badge">REVERSED</b><?php endif;?></div>
 <section class="document-allocations"><h3><?=$type==='customer'?'Applied to Invoice(s)':'Related Supplier Bill'?></h3><?php if($allocations):?><table><thead><tr><th>Invoice / Bill</th><th class="right">Applied Amount</th></tr></thead><tbody><?php foreach($allocations as $row):?><tr><td><?=e($row['sale_no'])?></td><td class="right"><b><?=money($row['amount'])?></b></td></tr><?php endforeach;?></tbody></table><?php else:?><p class="muted">Advance or unallocated account payment.</p><?php endif;?></section>
 <div class="document-notes"><div><span>Notes</span><p><?=e($document['notes'] ?: 'None')?></p></div><div><span><?=e($balanceLabel)?></span><strong><?=money($balance)?></strong></div></div>
 <footer><p>Recorded by: <?=e($document['recorded_by'])?></p><p>This computer-generated document is valid without a signature.</p></footer>
</section>
<style>.document-actions{display:flex;justify-content:space-between;margin-bottom:16px}.payment-document{max-width:900px;margin:auto;padding:30px}.document-brand{display:flex;justify-content:space-between;gap:30px;align-items:flex-start;border-bottom:2px solid #174e39;padding-bottom:18px}.document-brand img{display:block;width:310px;max-width:55vw;height:92px;object-fit:contain;object-position:left center}.document-brand p,.document-party p{color:#718078;margin:4px 0}.document-brand h2{margin:7px 0}.document-party{display:grid;grid-template-columns:2fr 1fr;gap:25px;padding:22px 0}.document-party span,.document-notes span,.payment-amount-box span{display:block;text-transform:uppercase;font-size:10px;color:#718078;letter-spacing:.08em}.document-party h3{margin:6px 0}.payment-amount-box{padding:20px;border-radius:12px;background:#edf5e9;border-left:5px solid #174e39;display:flex;align-items:center;justify-content:space-between;gap:15px}.payment-amount-box strong{font-size:28px}.document-allocations{margin-top:22px}.document-allocations table{width:100%;border-collapse:collapse}.document-allocations th,.document-allocations td{padding:11px;border-bottom:1px solid #dfe5df}.document-notes{display:grid;grid-template-columns:1fr 1fr;gap:25px;margin-top:24px}.document-notes>div:last-child{text-align:right}.document-notes strong{font-size:20px}.payment-document footer{display:flex;justify-content:space-between;gap:20px;border-top:1px solid #dfe5df;margin-top:35px;padding-top:15px;color:#718078;font-size:12px}@media(max-width:650px){.document-brand,.document-party,.document-notes,.payment-document footer{display:block}.document-brand .right,.document-notes>div:last-child{text-align:left;margin-top:18px}.payment-document{padding:18px}.payment-amount-box strong{font-size:22px}}@media print{.sidebar,.topbar,.document-actions{display:none!important}.app{margin:0!important;width:100%!important}.content{padding:0!important}.payment-document{border:0!important;box-shadow:none!important;max-width:none}.document-brand img{width:270px}}
</style>
<?php page_end();
