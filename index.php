<?php
require __DIR__ . '/bootstrap.php'; require_auth(); require __DIR__ . '/partials.php';
$today = $db->query("SELECT COALESCE(SUM(total),0) sales, COALESCE(SUM(gross_profit),0) profit FROM (SELECT s.id,s.total,SUM(si.gross_profit) gross_profit FROM sales s JOIN sale_items si ON si.sale_id=s.id WHERE DATE(s.sale_date)=CURDATE() AND s.status NOT IN ('cancelled','returned') GROUP BY s.id) x")->fetch() ?: ['sales'=>0,'profit'=>0];
$month = $db->query("SELECT COALESCE(SUM(total),0) total FROM sales WHERE YEAR(sale_date)=YEAR(CURDATE()) AND MONTH(sale_date)=MONTH(CURDATE()) AND status NOT IN ('cancelled','returned')")->fetchColumn();
$receivables = $db->query("SELECT COALESCE(SUM(balance),0) FROM sales WHERE payment_type='credit' AND status IN ('unpaid','partially_paid','overdue')")->fetchColumn();
$stockValue = $db->query("SELECT COALESCE(SUM(current_stock*avg_cost),0) FROM products WHERE status='active'")->fetchColumn();
$supplierPayables = $db->query("SELECT COALESCE(SUM(outstanding),0) FROM suppliers WHERE status='active'")->fetchColumn();
$directSaleMoney = (float)$db->query("SELECT COALESCE(SUM(sp.amount),0) FROM sale_payments sp JOIN sales s ON s.id=sp.sale_id WHERE s.status NOT IN ('cancelled','returned')")->fetchColumn();
$creditCollectionMoney = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM customer_payments WHERE status='posted'")->fetchColumn();
$advanceMoneyHeld = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM customer_advances WHERE status NOT IN ('refunded','cancelled')")->fetchColumn();
$supplierMoneyPaid = (float)$db->query("SELECT COALESCE(SUM(paid_amount),0) FROM purchases WHERE status='posted'")->fetchColumn();
$expenseMoneyPaid = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM expenses")->fetchColumn();
$otherMoneyIn = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM account_transactions WHERE direction='in' AND COALESCE(reference_type,'') IN ('manual','cash_in','other_income')")->fetchColumn();
$otherMoneyOut = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM account_transactions WHERE direction='out' AND COALESCE(reference_type,'') IN ('manual','cash_out','other_payment')")->fetchColumn();
$totalMoneyReceived = $directSaleMoney + $creditCollectionMoney + $advanceMoneyHeld + $otherMoneyIn;
$totalMoneyPaidOut = $supplierMoneyPaid + $expenseMoneyPaid + $otherMoneyOut;
$moneyCurrentlyHeld = $totalMoneyReceived - $totalMoneyPaidOut;
$lowStock = $db->query("SELECT COUNT(*) FROM products WHERE status='active' AND current_stock<=minimum_stock")->fetchColumn();
$recent = $db->query("SELECT s.sale_no,s.sale_date,COALESCE(c.name,'Walk-in Customer') customer,s.sale_type,s.payment_type,s.total,s.status FROM sales s LEFT JOIN customers c ON c.id=s.customer_id ORDER BY s.id DESC LIMIT 6")->fetchAll();
$top = $db->query("SELECT p.name,SUM(si.quantity) qty,SUM(si.line_total) revenue FROM sale_items si JOIN products p ON p.id=si.product_id JOIN sales s ON s.id=si.sale_id WHERE s.sale_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) AND s.status NOT IN ('cancelled','returned') GROUP BY p.id ORDER BY revenue DESC LIMIT 5")->fetchAll();
$salesPeriod = in_array((int)($_GET['sales_period'] ?? 7), [7,30,365], true) ? (int)($_GET['sales_period'] ?? 7) : 7;
$chartPoints = [];
if ($salesPeriod === 365) {
    $chartRows = $db->query("SELECT DATE_FORMAT(sale_date,'%Y-%m') period_key, DATE_FORMAT(sale_date,'%b') label, COALESCE(SUM(total),0) amount FROM sales WHERE sale_date >= DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 11 MONTH),'%Y-%m-01') AND status NOT IN ('cancelled','returned') GROUP BY period_key ORDER BY period_key")->fetchAll();
    $values = [];
    foreach ($chartRows as $row) $values[$row['period_key']] = (float)$row['amount'];
    for ($i=11; $i>=0; $i--) {
        $time = strtotime("first day of -$i month");
        $key = date('Y-m',$time);
        $chartPoints[] = ['label'=>date('M',$time),'amount'=>$values[$key] ?? 0];
    }
} else {
    $start = date('Y-m-d', strtotime('-'.($salesPeriod-1).' days'));
    $stmt = $db->prepare("SELECT DATE(sale_date) sale_day, COALESCE(SUM(total),0) amount FROM sales WHERE DATE(sale_date) BETWEEN ? AND CURDATE() AND status NOT IN ('cancelled','returned') GROUP BY DATE(sale_date)");
    $stmt->execute([$start]);
    $values = [];
    foreach ($stmt->fetchAll() as $row) $values[$row['sale_day']] = (float)$row['amount'];
    for ($i=$salesPeriod-1; $i>=0; $i--) {
        $time = strtotime("-$i days");
        $key = date('Y-m-d',$time);
        $chartPoints[] = ['label'=>$salesPeriod===7?date('D',$time):date('d M',$time),'amount'=>$values[$key] ?? 0];
    }
}
$chartMax = max(array_column($chartPoints,'amount')) ?: 1;
$chartTotal = array_sum(array_column($chartPoints,'amount'));
page_start('Home','index.php'); ?>
<script>window.dashboardSupplierPayables=<?=json_encode((float)$supplierPayables)?>;</script>
<section class="start-here"><div><span class="eyebrow">Start here</span><h2>What do you want to do?</h2><p>Choose one job. The system will guide you step by step.</p></div><div class="task-grid"><a href="sale.php"><i>1</i><b>Make a Sale</b><span>Cash or credit</span></a><a href="bulk_import.php"><i>2</i><b>Add Stock by Excel</b><span>Many products</span></a><a href="purchase_new.php"><i>3</i><b>Type a Purchase</b><span>One supplier invoice</span></a><a href="payments.php"><i>4</i><b>Receive Payment</b><span>Customer credit</span></a><a href="reports.php"><i>5</i><b>See Reports</b><span>Sales, stock, profit</span></a></div></section>
<section class="hero"><div><span class="eyebrow">Live business pulse</span><h2>Good <?=date('H')<12?'morning':(date('H')<17?'afternoon':'evening')?>, <?=e(explode(' ',user()['display_name'])[0])?>.</h2><p>Here’s what is happening across sales, credit and inventory.</p></div><div class="hero-meta"><span>Financial period</span><b><?=date('F Y')?></b></div></section>
<section class="kpi-grid"><article class="kpi"><div class="kpi-top"><span>Today’s sales</span><i class="good">↗</i></div><strong><?=money($today['sales'])?></strong><small>Across cash and credit</small></article><article class="kpi"><div class="kpi-top"><span>Monthly sales</span><i>◫</i></div><strong><?=money($month)?></strong><small><?=date('F')?> revenue</small></article><article class="kpi"><div class="kpi-top"><span>Receivables</span><i class="warn">!</i></div><strong><?=money($receivables)?></strong><small>Outstanding customer credit</small></article><article class="kpi"><div class="kpi-top"><span>Stock at cost</span><i>▦</i></div><strong><?=money($stockValue)?></strong><small><?=$lowStock?> low-stock item<?=$lowStock==1?'':'s'?></small></article></section>
<section class="split"><article class="panel chart-panel"><div class="panel-head"><div><span class="eyebrow">Performance</span><h3>Sales at a glance</h3><small class="chart-total">Total: <?=money($chartTotal)?></small></div><form method="get" class="chart-period-form"><select name="sales_period" aria-label="Sales chart period" onchange="this.form.submit()"><option value="7" <?=$salesPeriod===7?'selected':''?>>Last 7 days</option><option value="30" <?=$salesPeriod===30?'selected':''?>>Last 30 days</option><option value="365" <?=$salesPeriod===365?'selected':''?>>Last 12 months</option></select></form></div><div class="sales-chart" role="img" aria-label="Sales totals for the selected period"><div class="chart-bars"><?php foreach($chartPoints as $point): $height=$point['amount']>0?max(6,($point['amount']/$chartMax)*100):0; ?><div class="chart-column" title="<?=e($point['label'])?>: <?=e(money($point['amount']))?>"><span><?=e($point['amount']>0?money($point['amount']):'')?></span><i style="height:<?=$height?>%"></i><small><?=e($point['label'])?></small></div><?php endforeach;?></div><?php if($chartTotal<=0):?><p class="chart-empty-message">No sales recorded in this period.</p><?php endif;?></div></article><article class="panel"><div class="panel-head"><div><span class="eyebrow">Inventory</span><h3>Top products</h3></div><a href="reports.php">View report →</a></div><?php if(!$top):?><div class="empty-state"><b>No sales data yet</b><span>Your best-selling products will appear here.</span></div><?php else:?><div class="rank-list"><?php foreach($top as $i=>$row):?><div><b><?=str_pad((string)($i+1),2,'0',STR_PAD_LEFT)?></b><span><?=e($row['name'])?><small><?=e($row['qty'])?> units</small></span><strong><?=money($row['revenue'])?></strong></div><?php endforeach;?></div><?php endif;?></article></section>
<section class="panel table-panel"><div class="panel-head"><div><span class="eyebrow">Latest activity</span><h3>Recent sales</h3></div><a href="reports.php">All transactions →</a></div><div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Customer</th><th>Type</th><th>Date</th><th>Status</th><th class="right">Total</th></tr></thead><tbody><?php if(!$recent):?><tr><td colspan="6"><div class="empty-state compact"><b>No sales recorded</b><span>Create your first sale to begin.</span></div></td></tr><?php endif;?><?php foreach($recent as $row):?><tr><td><b><?=e($row['sale_no'])?></b></td><td><?=e($row['customer'])?></td><td><span class="tag"><?=e(ucfirst($row['sale_type']))?></span> <?=e(ucfirst($row['payment_type']))?></td><td><?=date('d M, H:i',strtotime($row['sale_date']))?></td><td><span class="status <?=e($row['status'])?>"><?=e(str_replace('_',' ',$row['status']))?></span></td><td class="right"><b><?=money($row['total'])?></b></td></tr><?php endforeach;?></tbody></table></div></section>
<?php page_end();
