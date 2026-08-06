<?php
require __DIR__.'/bootstrap.php';
require_auth();
if (!can('customers.view')) { http_response_code(403); exit('Forbidden'); }
require __DIR__.'/partials.php';

$q = trim($_GET['q'] ?? '');
$sql = 'SELECT c.id,c.customer_code,c.name,c.business_name,c.phone,c.credit_enabled,c.outstanding,
 (SELECT COALESCE(SUM(o.amount),0) FROM customer_opening_balances o WHERE o.customer_id=c.id) opening_balance,
 (SELECT COALESCE(SUM(s.total),0) FROM sales s WHERE s.customer_id=c.id AND s.payment_type="credit" AND s.status NOT IN("cancelled","returned")) credit_sales,
 (SELECT COALESCE(SUM(sp.amount),0) FROM sale_payments sp JOIN sales s ON s.id=sp.sale_id WHERE s.customer_id=c.id AND s.payment_type="credit" AND s.status NOT IN("cancelled","returned")) initial_payments,
 (SELECT COALESCE(SUM(cp.amount),0) FROM customer_payments cp WHERE cp.customer_id=c.id AND cp.status="posted") later_payments,
 (SELECT COUNT(*) FROM sales s WHERE s.customer_id=c.id AND s.payment_type="credit" AND s.balance>0 AND s.status NOT IN("cancelled","returned")) open_bills
 FROM customers c WHERE c.status="active" AND (c.credit_enabled=1 OR c.outstanding<>0';
$args = [];
if ($q !== '') { $sql .= ' OR c.name LIKE ? OR c.customer_code LIKE ? OR c.phone LIKE ? OR c.business_name LIKE ?'; $like='%'.$q.'%'; $args=[$like,$like,$like,$like]; }
$sql .= ') ORDER BY c.outstanding DESC,c.name';
$stmt = $db->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();
$totalOpening = array_sum(array_map(fn($r)=>(float)$r['opening_balance'],$rows));
$totalCreditSales = array_sum(array_map(fn($r)=>(float)$r['credit_sales'],$rows));
$totalPayments = array_sum(array_map(fn($r)=>(float)$r['initial_payments']+(float)$r['later_payments'],$rows));
$totalOutstanding = array_sum(array_map(fn($r)=>(float)$r['outstanding'],$rows));
page_start('All Customer Ledgers','customer_ledgers.php');
?>
<section class="action-banner"><div><span class="step-number">L</span><div><b>All customer ledgers</b><p>Use this page for the full credit position. Open one customer to see every invoice and payment transaction.</p></div></div><div class="action-buttons"><a class="btn secondary" href="customers.php">Customers</a><a class="btn secondary" href="payments.php">Receive Payments</a><button class="btn primary" onclick="window.print()">Print Summary</button></div></section>
<div class="toolbar ledger-toolbar"><form><input name="q" value="<?=e($q)?>" placeholder="Search customer, code, phone or business"><button class="btn secondary">Search</button></form></div>
<section class="kpi-grid ledger-summary-kpis"><article class="kpi"><span>Opening Credit Balances</span><strong><?=money($totalOpening)?></strong></article><article class="kpi"><span>Total Credit Sales</span><strong><?=money($totalCreditSales)?></strong></article><article class="kpi"><span>Payments Received</span><strong><?=money($totalPayments)?></strong></article><article class="kpi balance-card"><span>Total Still to Collect</span><strong><?=money($totalOutstanding)?></strong></article></section>
<section class="panel table-panel ledger-control-table"><div class="panel-head"><div><span class="eyebrow">Customer ledger control summary</span><h3><?=count($rows)?> account(s)</h3></div></div><div class="table-wrap"><table><thead><tr><th>Customer</th><th class="right">Opening Balance</th><th class="right">Credit Sales</th><th class="right">Payments Received</th><th class="right">Open Bills</th><th class="right">Current Balance</th><th>Actions</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7"><div class="empty-state compact"><b>No customer ledgers found</b></div></td></tr><?php endif; ?>
<?php foreach ($rows as $r): $payments=(float)$r['initial_payments']+(float)$r['later_payments']; ?>
<tr><td data-label="Customer"><b><?=e($r['name'])?></b><br><small><?=e($r['customer_code'])?><?=!empty($r['phone'])?' - '.e($r['phone']):''?></small></td><td data-label="Opening" class="right"><?=money($r['opening_balance'])?></td><td data-label="Credit Sales" class="right"><?=money($r['credit_sales'])?></td><td data-label="Payments" class="right paid-so-far"><?=money($payments)?></td><td data-label="Open Bills" class="right"><?=(int)$r['open_bills']?></td><td data-label="Balance" class="right yet-to-pay"><b><?=money($r['outstanding'])?></b></td><td data-label="Actions" class="ledger-actions"><a class="btn secondary" href="customer_view.php?id=<?=$r['id']?>#ledger">View Ledger</a><?php if((float)$r['outstanding']>0):?><a class="btn primary" href="payments.php?customer_id=<?=$r['id']?>#receive-payment">Receive</a><?php endif;?></td></tr>
<?php endforeach; ?>
</tbody><tfoot><tr><th>All Customer Accounts</th><th class="right"><?=money($totalOpening)?></th><th class="right"><?=money($totalCreditSales)?></th><th class="right"><?=money($totalPayments)?></th><th></th><th class="right"><?=money($totalOutstanding)?></th><th></th></tr></tfoot></table></div></section>
<div class="plain-help ledger-explanation"><b>How to read this:</b> credit sales and opening balances add to customer debt; payments reduce it. “Current Balance” is the live amount the business still expects to collect.</div>
<?php page_end();
