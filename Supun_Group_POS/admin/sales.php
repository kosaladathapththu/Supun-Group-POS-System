<?php
session_start();
include '../db.php';

if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["role"], ["admin", "accountant"], true)) {
    header("Location: ../login.php"); exit;
}

/* ── FILTERS ── */
$filter_from = trim($_GET["from"] ?? date('Y-m-01'));
$filter_to   = trim($_GET["to"]   ?? date('Y-m-d'));
$filter_pm   = trim($_GET["payment_method"] ?? "");
$filter_type = trim($_GET["order_type"] ?? "");

$where = ["o.payment_status='paid'", "DATE(o.created_at) BETWEEN '" . $conn->real_escape_string($filter_from) . "' AND '" . $conn->real_escape_string($filter_to) . "'"];
if ($filter_pm   !== "") $where[] = "o.payment_method='" . $conn->real_escape_string($filter_pm) . "'";
if ($filter_type !== "") $where[] = "o.order_type='"     . $conn->real_escape_string($filter_type) . "'";
$ws = implode(" AND ", $where);

/* ── KPIs ── */
$kpi = $conn->query("
    SELECT COUNT(*) AS total_orders,
           COALESCE(SUM(total_amount),0) AS total_revenue,
           COALESCE(SUM(discount),0)     AS total_discount,
           COALESCE(AVG(total_amount),0) AS avg_order
    FROM orders o WHERE $ws
")->fetch_assoc();

/* ── DAILY BREAKDOWN ── */
$daily = $conn->query("
    SELECT DATE(o.created_at) AS sale_date,
           COUNT(*) AS orders,
           COALESCE(SUM(total_amount),0) AS revenue,
           COALESCE(SUM(discount),0)     AS discount
    FROM orders o WHERE $ws
    GROUP BY DATE(o.created_at)
    ORDER BY sale_date DESC
");

/* ── PAYMENT METHOD ── */
$pm_sum = $conn->query("
    SELECT payment_method, COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total
    FROM orders o WHERE $ws
    GROUP BY payment_method ORDER BY total DESC
");

/* ── ORDER TYPE ── */
$ot_sum = $conn->query("
    SELECT order_type, COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total
    FROM orders o WHERE $ws
    GROUP BY order_type
");

/* ── TOP PRODUCTS ── */
$top_prods = $conn->query("
    SELECT
        COALESCE(MIN(p.product_name), MIN(oi.custom_item_name), 'Unknown') AS item_name,
        SUM(oi.quantity) AS qty,
        SUM(oi.line_total) AS revenue,
        CASE
            WHEN MAX(CASE WHEN oi.custom_item_name IS NOT NULL AND oi.product_id IS NULL THEN 1 ELSE 0 END) = 1
            THEN 1
            ELSE 0
        END AS is_custom
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.product_id
    JOIN orders o ON oi.order_id = o.order_id
    WHERE $ws
    GROUP BY
        COALESCE(oi.product_id, 0),
        COALESCE(oi.custom_item_name, '')
    ORDER BY qty DESC
    LIMIT 10
");

/* PRODUCT SALES & PROFITABILITY */
$product_profit = $conn->query("
    SELECT
        COALESCE(p.product_name, oi.custom_item_name, 'Custom Item') AS item_name,
        CASE WHEN oi.product_id IS NULL THEN 1 ELSE 0 END AS is_custom,
        COUNT(DISTINCT o.order_id) AS order_count,
        SUM(oi.quantity) AS qty_sold,
        SUM(oi.line_total) AS gross_sales,
        SUM(CASE WHEN o.subtotal>0 THEN o.discount*(oi.line_total/o.subtotal) ELSE 0 END) AS allocated_discount,
        SUM(oi.line_total-CASE WHEN o.subtotal>0 THEN o.discount*(oi.line_total/o.subtotal) ELSE 0 END) AS net_sales,
        SUM(oi.cost_price*oi.quantity) AS product_cost,
        SUM(oi.line_total-CASE WHEN o.subtotal>0 THEN o.discount*(oi.line_total/o.subtotal) ELSE 0 END-(oi.cost_price*oi.quantity)) AS gross_profit
    FROM order_items oi
    JOIN orders o ON o.order_id=oi.order_id
    LEFT JOIN products p ON p.product_id=oi.product_id
    WHERE $ws
    GROUP BY oi.product_id,oi.custom_item_name,p.product_name
    ORDER BY is_custom ASC,gross_profit DESC,qty_sold DESC
");

$profit_kpi = $conn->query("
    SELECT
      COALESCE(SUM(oi.cost_price*oi.quantity),0) AS cogs,
      COALESCE(SUM(oi.line_total-CASE WHEN o.subtotal>0 THEN o.discount*(oi.line_total/o.subtotal) ELSE 0 END),0) AS net_product_sales,
      COALESCE(SUM(oi.line_total-CASE WHEN o.subtotal>0 THEN o.discount*(oi.line_total/o.subtotal) ELSE 0 END-(oi.cost_price*oi.quantity)),0) AS gross_profit
    FROM order_items oi JOIN orders o ON o.order_id=oi.order_id WHERE $ws AND oi.product_id IS NOT NULL
")->fetch_assoc();

/* ── CASHIER PERFORMANCE ── */
$cashiers = $conn->query("
    SELECT u.full_name, COUNT(*) AS orders, COALESCE(SUM(o.total_amount),0) AS revenue
    FROM orders o LEFT JOIN users u ON o.user_id=u.user_id
    WHERE $ws GROUP BY o.user_id ORDER BY revenue DESC
");

/* ── PAGINATED ORDERS ── */
$page_num   = max(1, (int)($_GET["page"] ?? 1));
$per_page   = 25;
$offset     = ($page_num - 1) * $per_page;
$total_rows = $conn->query("SELECT COUNT(*) AS c FROM orders o WHERE $ws")->fetch_assoc()['c'];
$total_pages = max(1, ceil($total_rows / $per_page));

$orders_list = $conn->query("
    SELECT o.order_id, o.order_type, o.subtotal, o.discount, o.total_amount,
           o.payment_method, o.cash_given, o.balance, o.payment_status, o.created_at,
           u.full_name AS cashier
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.user_id
    WHERE $ws
    ORDER BY o.created_at DESC
    LIMIT $per_page OFFSET $offset
");

/* PM icon map */
$pm_icons = ['Cash'=>'fa-money-bill-wave','Card'=>'fa-credit-card','QR'=>'fa-qrcode','Bank Transfer'=>'fa-building-columns'];
$pm_cls   = ['Cash'=>'b-green','Card'=>'b-indigo','QR'=>'b-amber','Bank Transfer'=>'b-sky'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sales Report — The La-zogan</title>
<link href="https://fonts.googleapis.com/css2?family=Lora:wght@600;700&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
<?php include 'shared_style.php'; ?>

/* ── Sales-specific styles ── */
.kpi-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 20px; }

.kpi-card {
    background: var(--white); border: 1.5px solid var(--border);
    border-radius: var(--radius); padding: 16px 18px;
    box-shadow: var(--shadow-sm); border-top: 3px solid transparent;
}
.kpi-icon { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 15px; margin-bottom: 10px; }
.kpi-val  { font-size: 21px; font-weight: 900; font-family: 'Lora', serif; margin-bottom: 2px; }
.kpi-lbl  { font-size: 11px; font-weight: 700; color: var(--text-muted); }
.kpi-sub  { font-size: 10px; color: var(--text-muted); font-weight: 600; margin-top: 3px; }

.three-col { display: grid; grid-template-columns: 1.5fr 1fr 1.3fr; gap: 16px; margin-bottom: 20px; }
.two-col-equal { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
.profit-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:12px}.profit-kpi{padding:13px 16px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg)}.profit-kpi span{display:block;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted)}.profit-kpi strong{display:block;margin-top:3px;font-family:'Lora',serif;font-size:18px}.profit-positive{color:var(--green)!important}.profit-negative{color:var(--red)!important}.profit-table td,.profit-table th{white-space:nowrap}.profit-table td:first-child,.profit-table th:first-child{white-space:normal;min-width:190px}.margin-pill{display:inline-flex;padding:3px 8px;border-radius:20px;background:var(--green-lt);color:var(--green);font-size:11px;font-weight:900}.na-pill{display:inline-flex;padding:3px 8px;border-radius:20px;background:var(--amber-lt);color:var(--amber);font-size:10px;font-weight:900}
@media(max-width:800px){.profit-kpis{grid-template-columns:1fr}.three-col,.two-col-equal{grid-template-columns:1fr}}

.mini-row { display: flex; align-items: center; justify-content: space-between; padding: 9px 16px; border-bottom: 1px solid var(--border); }
.mini-row:last-child { border-bottom: none; }
.mini-row:hover { background: #fafbfd; }

.rank-dot { width: 22px; height: 22px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 900; flex-shrink: 0; }

.bar-wrap { height: 5px; background: var(--bg); border-radius: 3px; overflow: hidden; margin-top: 4px; }
.bar-fill { height: 100%; background: var(--primary); border-radius: 3px; }
</style>
</head>
<body>
<?php include 'shared_nav.php'; ?>
<div class="main">
<?php include 'shared_topbar.php'; ?>
<div class="content">

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h2 class="page-title-h"><i class="fa-solid fa-file-invoice-dollar"></i> Sales Report</h2>
            <p class="page-sub">
                <?php echo date('d M Y', strtotime($filter_from)); ?>
                &nbsp;&rarr;&nbsp;
                <?php echo date('d M Y', strtotime($filter_to)); ?>
            </p>
        </div>
        <div style="display:flex;gap:8px;" class="no-print">
            <a class="btn-secondary" target="_blank" href="print_report.php?type=sales&amp;from=<?php echo urlencode($filter_from); ?>&amp;to=<?php echo urlencode($filter_to); ?>&amp;payment_method=<?php echo urlencode($filter_pm); ?>&amp;order_type=<?php echo urlencode($filter_type); ?>">
                <i class="fa-solid fa-file-pdf"></i> Open Detailed Report
            </a>
            <a href="../pos.php" class="btn-primary">
                <i class="fa-solid fa-cash-register"></i> Go to POS
            </a>
        </div>
    </div>

<a href="daily_product_sales.php" class="btn-secondary no-print" style="margin-bottom:18px;">
    <i class="fa-solid fa-chart-column"></i> View Full Product Report
</a>

    <!-- ══ FILTER BAR ══ -->
    <div class="card no-print" style="margin-bottom:18px;">
        <div style="padding:14px 18px;">
            <form method="GET" class="filter-form">
                <div class="ff">
                    <label>From Date</label>
                    <input type="date" name="from" value="<?php echo $filter_from; ?>">
                </div>
                <div class="ff">
                    <label>To Date</label>
                    <input type="date" name="to" value="<?php echo $filter_to; ?>">
                </div>
                <div class="ff">
                    <label>Payment Method</label>
                    <select name="payment_method">
                        <option value="">All Methods</option>
                        <option value="Cash"          <?php echo $filter_pm=='Cash'?'selected':''; ?>>Cash</option>
                        <option value="Card"          <?php echo $filter_pm=='Card'?'selected':''; ?>>Card</option>
                        <option value="QR"            <?php echo $filter_pm=='QR'?'selected':''; ?>>QR</option>
                        <option value="Bank Transfer" <?php echo $filter_pm=='Bank Transfer'?'selected':''; ?>>Bank Transfer</option>
                    </select>
                </div>
                <div class="ff">
                    <label>Order Type</label>
                    <select name="order_type">
                        <option value="">All Types</option>
                        <option value="retail" <?php echo $filter_type=='retail'?'selected':''; ?>>Retail</option>
                        <option value="wholesale" <?php echo $filter_type=='wholesale'?'selected':''; ?>>Wholesale</option>
                    </select>
                </div>
                <button type="submit" class="btn-primary"><i class="fa-solid fa-filter"></i> Apply Filter</button>
                <a href="sales.php" class="btn-secondary"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            </form>
        </div>
    </div>

    <!-- ══ KPI CARDS ══ -->
    <div class="kpi-grid">
        <div class="kpi-card" style="border-top-color:var(--primary);">
            <div class="kpi-icon" style="background:var(--primary-lt);color:var(--primary);"><i class="fa-solid fa-sack-dollar"></i></div>
            <div class="kpi-val" style="color:var(--primary);">Rs. <?php echo number_format($kpi['total_revenue'], 0); ?></div>
            <div class="kpi-lbl">Total Revenue</div>
            <div class="kpi-sub"><?php echo $filter_from; ?> → <?php echo $filter_to; ?></div>
        </div>
        <div class="kpi-card" style="border-top-color:var(--indigo);">
            <div class="kpi-icon" style="background:var(--indigo-lt);color:var(--indigo);"><i class="fa-solid fa-receipt"></i></div>
            <div class="kpi-val" style="color:var(--indigo);"><?php echo number_format($kpi['total_orders']); ?></div>
            <div class="kpi-lbl">Total Orders</div>
            <div class="kpi-sub">In selected period</div>
        </div>
        <div class="kpi-card" style="border-top-color:var(--green);">
            <div class="kpi-icon" style="background:var(--green-lt);color:var(--green);"><i class="fa-solid fa-chart-simple"></i></div>
            <div class="kpi-val" style="color:var(--green);">Rs. <?php echo number_format($kpi['avg_order'], 0); ?></div>
            <div class="kpi-lbl">Avg Order Value</div>
            <div class="kpi-sub">Per transaction</div>
        </div>
        <div class="kpi-card" style="border-top-color:var(--amber);">
            <div class="kpi-icon" style="background:var(--amber-lt);color:var(--amber);"><i class="fa-solid fa-tag"></i></div>
            <div class="kpi-val" style="color:var(--amber);">Rs. <?php echo number_format($kpi['total_discount'], 0); ?></div>
            <div class="kpi-lbl">Total Discounts</div>
            <div class="kpi-sub">Given in period</div>
        </div>
    </div>

    <!-- ══ 3-COL: Daily / Payment+OrderType / TopProducts ══ -->
    <div class="three-col">

        <!-- Daily Breakdown -->
        <div class="card table-card-full">
            <div class="card-header">
                <h3><i class="fa-solid fa-calendar-days"></i> Daily Sales</h3>
                <span class="count-badge"><?php echo $daily ? $daily->num_rows : 0; ?> days</span>
            </div>
            <div class="tbl-wrap" style="max-height:380px;overflow-y:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Day</th>
                            <th>Orders</th>
                            <th>Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($daily && $daily->num_rows > 0):
                            while ($d = $daily->fetch_assoc()): ?>
                        <tr>
                            <td style="white-space:nowrap;"><strong><?php echo date('d M', strtotime($d['sale_date'])); ?></strong></td>
                            <td style="color:var(--text-muted);font-size:11px;"><?php echo date('D', strtotime($d['sale_date'])); ?></td>
                            <td><span class="badge b-indigo"><?php echo $d['orders']; ?></span></td>
                            <td><strong style="color:var(--primary);">Rs.<?php echo number_format($d['revenue'], 0); ?></strong></td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="4" class="empty-row">No data in this range.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Payment + Order Type stacked -->
        <div style="display:flex;flex-direction:column;gap:14px;">
            <!-- By Payment -->
            <div class="card table-card-full">
                <div class="card-header">
                    <h3><i class="fa-solid fa-credit-card"></i> By Payment</h3>
                </div>
                <div>
                    <?php if ($pm_sum && $pm_sum->num_rows > 0):
                        while ($pm = $pm_sum->fetch_assoc()):
                            $pc = $pm_cls[$pm['payment_method']] ?? 'b-green';
                            $pi = $pm_icons[$pm['payment_method']] ?? 'fa-money-bill';
                            $pct = $kpi['total_revenue'] > 0 ? round($pm['total']/$kpi['total_revenue']*100) : 0;
                    ?>
                    <div class="mini-row">
                        <div>
                            <span class="badge <?php echo $pc; ?>"><i class="fa-solid <?php echo $pi; ?>"></i> <?php echo htmlspecialchars($pm['payment_method']); ?></span>
                            <div class="bar-wrap" style="width:80px;margin-top:5px;">
                                <div class="bar-fill" style="width:<?php echo $pct; ?>%;"></div>
                            </div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:13px;font-weight:900;color:var(--text);">Rs.<?php echo number_format($pm['total'],0); ?></div>
                            <div style="font-size:10px;color:var(--text-muted);"><?php echo $pm['cnt']; ?> orders</div>
                        </div>
                    </div>
                    <?php endwhile; else: ?>
                    <div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px;">No data.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- By Order Type -->
            <div class="card table-card-full">
                <div class="card-header">
                    <h3><i class="fa-solid fa-store"></i> By Sale Type</h3>
                </div>
                <div>
                    <?php if ($ot_sum && $ot_sum->num_rows > 0):
                        while ($ot = $ot_sum->fetch_assoc()): ?>
                    <div class="mini-row">
                        <?php if ($ot['order_type']=='retail'): ?>
                            <span class="badge b-orange"><i class="fa-solid fa-basket-shopping"></i> Retail</span>
                        <?php else: ?>
                            <span class="badge b-amber"><i class="fa-solid fa-boxes-stacked"></i> Wholesale</span>
                        <?php endif; ?>
                        <div style="text-align:right;">
                            <div style="font-size:13px;font-weight:900;color:var(--text);">Rs.<?php echo number_format($ot['total'],0); ?></div>
                            <div style="font-size:10px;color:var(--text-muted);"><?php echo $ot['cnt']; ?> orders</div>
                        </div>
                    </div>
                    <?php endwhile; else: ?>
                    <div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px;">No data.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top Products -->
        <div class="card table-card-full">
            <div class="card-header">
                <h3><i class="fa-solid fa-trophy"></i> Top Products</h3>
            </div>
            <div>
                <?php
                $tp_rows = [];
                if ($top_prods) while ($r=$top_prods->fetch_assoc()) $tp_rows[] = $r;
                $max_rev = count($tp_rows) > 0 ? max(array_column($tp_rows,'revenue')) : 1;

                if (!empty($tp_rows)):
                    foreach ($tp_rows as $i => $tp):
                        $rank  = $i + 1;
                        $rbg   = $rank==1?'#fef3c7':($rank==2?'#f1f5f9':($rank==3?'#fff7ed':'var(--bg)'));
                        $rc    = $rank==1?'#d97706':($rank==2?'#64748b':($rank==3?'#ea580c':'var(--text-muted)'));
                        $pct   = $max_rev > 0 ? round($tp['revenue']/$max_rev*100) : 0;
                ?>
                <div style="display:flex;align-items:center;gap:10px;padding:9px 14px;border-bottom:1px solid var(--border);">
                    <div class="rank-dot" style="background:<?php echo $rbg; ?>;color:<?php echo $rc; ?>"><?php echo $rank; ?></div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:12px;font-weight:800;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?php echo htmlspecialchars($tp['item_name']); ?>
                            <?php if ($tp['is_custom']): ?><span style="font-size:9px;background:var(--amber-lt);color:var(--amber);padding:0 4px;border-radius:3px;margin-left:3px;">Custom</span><?php endif; ?>
                        </div>
                        <div style="font-size:10px;color:var(--text-muted);margin-top:1px;"><?php echo $tp['qty']; ?> sold &bull; Rs.<?php echo number_format($tp['revenue'],0); ?></div>
                        <div class="bar-wrap" style="width:100%;margin-top:4px;">
                            <div class="bar-fill" style="width:<?php echo $pct; ?>%;"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; else: ?>
                <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px;">No product data.</div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /three-col -->

    <section class="card table-card-full" style="margin-bottom:20px;">
        <div class="card-header">
            <div><h3><i class="fa-solid fa-chart-line"></i> Product Sales &amp; Profit</h3><div style="font-size:10px;color:var(--text-muted);font-weight:700;margin-top:2px;">Discounts are allocated proportionally to each sold item</div></div>
            <span class="count-badge"><?php echo $product_profit ? $product_profit->num_rows : 0; ?> products</span>
        </div>
        <div class="card-body" style="padding-bottom:12px;">
            <div class="profit-kpis">
                <div class="profit-kpi"><span>Net Product Sales</span><strong>Rs. <?php echo number_format($profit_kpi['net_product_sales'],2); ?></strong></div>
                <div class="profit-kpi"><span>Cost of Goods Sold</span><strong style="color:var(--amber);">Rs. <?php echo number_format($profit_kpi['cogs'],2); ?></strong></div>
                <div class="profit-kpi"><span>Gross Product Profit</span><strong class="<?php echo $profit_kpi['gross_profit']>=0?'profit-positive':'profit-negative'; ?>">Rs. <?php echo number_format($profit_kpi['gross_profit'],2); ?></strong></div>
            </div>
        </div>
        <div class="tbl-wrap"><table class="profit-table"><thead><tr><th>Product</th><th>Orders</th><th>Qty Sold</th><th>Gross Sales</th><th>Discount</th><th>Net Sales</th><th>Product Cost</th><th>Gross Profit</th><th>Margin</th></tr></thead><tbody>
        <?php if($product_profit && $product_profit->num_rows): while($pp=$product_profit->fetch_assoc()): $margin=(float)$pp['net_sales']!=0?((float)$pp['gross_profit']/(float)$pp['net_sales']*100):0; ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($pp['item_name']); ?></strong><?php if($pp['is_custom']): ?> <span class="na-pill">Custom · cost unknown</span><?php endif; ?></td>
                <td><?php echo number_format($pp['order_count']); ?></td><td><?php echo number_format($pp['qty_sold'],3); ?></td>
                <td>Rs. <?php echo number_format($pp['gross_sales'],2); ?></td><td style="color:var(--red);">- Rs. <?php echo number_format($pp['allocated_discount'],2); ?></td><td><strong>Rs. <?php echo number_format($pp['net_sales'],2); ?></strong></td>
                <?php if($pp['is_custom']): ?><td>—</td><td>—</td><td><span class="na-pill">N/A</span></td><?php else: ?><td>Rs. <?php echo number_format($pp['product_cost'],2); ?></td><td class="<?php echo $pp['gross_profit']>=0?'profit-positive':'profit-negative'; ?>"><strong>Rs. <?php echo number_format($pp['gross_profit'],2); ?></strong></td><td><span class="margin-pill"><?php echo number_format($margin,1); ?>%</span></td><?php endif; ?>
            </tr>
        <?php endwhile; else: ?><tr><td colspan="9" class="empty-row">No paid product sales found for this period.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <!-- ══ CASHIER PERFORMANCE ══ -->
    <div class="card table-card-full" style="margin-bottom:20px;">
        <div class="card-header">
            <h3><i class="fa-solid fa-users"></i> Cashier Performance</h3>
        </div>
        <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Cashier</th>
                        <th>Orders</th>
                        <th>Total Revenue</th>
                        <th>Avg per Order</th>
                        <th>Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($cashiers && $cashiers->num_rows > 0):
                        while ($cr = $cashiers->fetch_assoc()):
                            $pct = $kpi['total_revenue'] > 0 ? round($cr['revenue']/$kpi['total_revenue']*100) : 0;
                            $avg = $cr['orders'] > 0 ? $cr['revenue']/$cr['orders'] : 0;
                    ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;color:#fff;flex-shrink:0;">
                                    <?php echo strtoupper(substr($cr['full_name'] ?? 'U', 0, 1)); ?>
                                </div>
                                <strong><?php echo htmlspecialchars($cr['full_name'] ?? 'Unknown'); ?></strong>
                            </div>
                        </td>
                        <td><span class="badge b-indigo"><?php echo $cr['orders']; ?> orders</span></td>
                        <td><strong style="color:var(--primary);">Rs. <?php echo number_format($cr['revenue'], 2); ?></strong></td>
                        <td>Rs. <?php echo number_format($avg, 0); ?></td>
                        <td style="min-width:120px;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div class="bar-wrap" style="flex:1;height:6px;">
                                    <div class="bar-fill" style="width:<?php echo $pct; ?>%;height:100%;"></div>
                                </div>
                                <span style="font-size:11px;font-weight:800;color:var(--text-muted);"><?php echo $pct; ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="5" class="empty-row">No cashier data found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ══ FULL ORDERS TABLE ══ -->
    <div class="card table-card-full">
        <div class="card-header">
            <h3>
                <i class="fa-solid fa-table-list"></i> All Orders
                <span style="font-size:11px;font-weight:700;color:var(--text-muted);margin-left:6px;"><?php echo number_format($total_rows); ?> records</span>
            </h3>
        </div>
        <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Date / Time</th>
                        <th>Cashier</th>
                        <th>Type</th>
                        <th>Subtotal</th>
                        <th>Discount</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Cash Given</th>
                        <th>Balance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($orders_list && $orders_list->num_rows > 0):
                        while ($o = $orders_list->fetch_assoc()):
                            $pc = $pm_cls[$o['payment_method']] ?? 'b-green';
                            $pi = $pm_icons[$o['payment_method']] ?? 'fa-money-bill';
                            $bal = (float)$o['balance'];
                    ?>
                    <tr>
                        <td><strong>#<?php echo $o['order_id']; ?></strong></td>
                        <td style="white-space:nowrap;">
                            <div style="font-weight:800;color:var(--text);"><?php echo date('d M Y', strtotime($o['created_at'])); ?></div>
                            <div style="font-size:11px;color:var(--text-muted);"><?php echo date('h:i A', strtotime($o['created_at'])); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($o['cashier'] ?? 'N/A'); ?></td>
                        <td>
                            <?php if ($o['order_type']=='retail'): ?>
                                <span class="badge b-orange"><i class="fa-solid fa-basket-shopping"></i> Retail</span>
                            <?php else: ?>
                                <span class="badge b-amber"><i class="fa-solid fa-boxes-stacked"></i> Wholesale</span>
                            <?php endif; ?>
                        </td>
                        <td>Rs. <?php echo number_format($o['subtotal'], 2); ?></td>
                        <td>
                            <?php if ($o['discount'] > 0): ?>
                                <span class="badge b-red">- Rs.<?php echo number_format($o['discount'],2); ?></span>
                            <?php else: echo '—'; endif; ?>
                        </td>
                        <td><strong style="color:var(--primary);">Rs. <?php echo number_format($o['total_amount'], 2); ?></strong></td>
                        <td>
                            <span class="badge <?php echo $pc; ?>">
                                <i class="fa-solid <?php echo $pi; ?>"></i>
                                <?php echo htmlspecialchars($o['payment_method']); ?>
                            </span>
                        </td>
                        <td>Rs. <?php echo number_format($o['cash_given'], 2); ?></td>
                        <td>
                            <?php if ($bal > 0): ?>
                                <span class="badge b-green">+Rs.<?php echo number_format($bal,2); ?></span>
                            <?php elseif ($bal < 0): ?>
                                <span class="badge b-red">Rs.<?php echo number_format(abs($bal),2); ?></span>
                            <?php else: echo '—'; endif; ?>
                        </td>
                        <td>
                            <?php if (strtolower($o['payment_status'] ?? '') === 'paid'): ?>
                                <span class="badge b-green"><i class="fa-solid fa-check"></i> Paid</span>
                            <?php else: ?>
                                <span class="badge b-amber">Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="11" class="empty-row">No orders found in this date range.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1):
            $qs = http_build_query(array_filter([
                'from' => $filter_from, 'to' => $filter_to,
                'payment_method' => $filter_pm, 'order_type' => $filter_type
            ]));
            $qs = $qs ? '&' . $qs : '';
        ?>
        <div class="pagination no-print">
            <a href="?page=<?php echo max(1,$page_num-1).$qs; ?>" class="pag-btn <?php echo $page_num==1?'disabled':''; ?>">
                <i class="fa-solid fa-chevron-left"></i>
            </a>
            <?php
            $start = max(1, $page_num - 2);
            $end   = min($total_pages, $page_num + 2);
            for ($p = $start; $p <= $end; $p++):
            ?>
            <a href="?page=<?php echo $p.$qs; ?>" class="pag-btn <?php echo $p==$page_num?'active':''; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <a href="?page=<?php echo min($total_pages,$page_num+1).$qs; ?>" class="pag-btn <?php echo $page_num==$total_pages?'disabled':''; ?>">
                <i class="fa-solid fa-chevron-right"></i>
            </a>
            <span style="font-size:12px;color:var(--text-muted);font-weight:700;margin-left:6px;">
                Page <?php echo $page_num; ?> of <?php echo $total_pages; ?>
            </span>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /content -->
</div><!-- /main -->
</body>
</html>
