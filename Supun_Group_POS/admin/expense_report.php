<?php
session_start();
include '../db.php';

if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["role"], ["admin", "accountant"], true)) {
    header("Location: ../login.php"); exit;
}

/* ── FILTERS ── */
$f_from = $_GET['from'] ?? date('Y-m-01');
$f_to   = $_GET['to']   ?? date('Y-m-d');
$f_cat  = trim($_GET['cat']  ?? '');
$f_pm   = trim($_GET['pm']   ?? '');

$from_s = $conn->real_escape_string($f_from);
$to_s   = $conn->real_escape_string($f_to);

$where  = ["expense_date BETWEEN '$from_s' AND '$to_s'"];
if ($f_cat) $where[] = "category='"        . $conn->real_escape_string($f_cat) . "'";
if ($f_pm)  $where[] = "payment_method='"  . $conn->real_escape_string($f_pm)  . "'";
$ws = implode(" AND ", $where);

/* ── MAIN KPIs ── */
$kpi = $conn->query("SELECT
    COALESCE(SUM(amount),0) AS total,
    COUNT(*)                AS cnt,
    COALESCE(AVG(amount),0) AS avg_amt,
    COALESCE(MAX(amount),0) AS max_amt
    FROM expenses WHERE $ws")->fetch_assoc();

/* ── REVENUE in same period (for profit calc) ── */
$revenue = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS v FROM orders
    WHERE DATE(created_at) BETWEEN '$from_s' AND '$to_s'")->fetch_assoc()['v'];
$profit  = $revenue - $kpi['total'];

/* ── DAILY EXPENSES ── */
$daily_q = $conn->query("SELECT expense_date AS day,
    COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
    FROM expenses WHERE $ws
    GROUP BY expense_date ORDER BY expense_date DESC");
$daily_rows = [];
while ($r = $daily_q->fetch_assoc()) $daily_rows[] = $r;

/* ── CATEGORY BREAKDOWN ── */
$cat_q = $conn->query("SELECT category,
    COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
    FROM expenses WHERE $ws
    GROUP BY category ORDER BY total DESC");
$cat_rows = [];
while ($r = $cat_q->fetch_assoc()) $cat_rows[] = $r;

/* ── PAYMENT METHOD ── */
$pm_q = $conn->query("SELECT payment_method,
    COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
    FROM expenses WHERE $ws
    GROUP BY payment_method ORDER BY total DESC");
$pm_rows = [];
while ($r = $pm_q->fetch_assoc()) $pm_rows[] = $r;

/* ── MONTHLY TREND (last 6 months) ── */
$trend_q = $conn->query("SELECT
    DATE_FORMAT(MIN(expense_date), '%b %Y') AS mon,
    YEAR(expense_date) AS yr,
    MONTH(expense_date) AS mo,
    COALESCE(SUM(amount), 0) AS total
    FROM expenses
    WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
    GROUP BY YEAR(expense_date), MONTH(expense_date)
    ORDER BY YEAR(expense_date), MONTH(expense_date)");

if (!$trend_q) {
    die('Trend query error: ' . $conn->error);
}

$trend_labels = [];
$trend_totals = [];

while ($r = $trend_q->fetch_assoc()) {
    $trend_labels[] = $r['mon'];
    $trend_totals[] = (float)$r['total'];
}

/* ── CHART DATA: category ── */
$chart_cat_labels = array_column($cat_rows, 'category');
$chart_cat_totals = array_map('floatval', array_column($cat_rows, 'total'));

/* ── TOP 10 BIGGEST EXPENSES ── */
$top10 = $conn->query("SELECT * FROM expenses WHERE $ws
    ORDER BY amount DESC LIMIT 10");

/* ── DISTINCT CATEGORIES for filter ── */
$all_cats_q = $conn->query("SELECT DISTINCT category FROM expenses ORDER BY category");
$all_cats = [];
while ($r = $all_cats_q->fetch_assoc()) $all_cats[] = $r['category'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Expense Report — The La-zogan</title>
<link href="https://fonts.googleapis.com/css2?family=Lora:wght@600;700&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<style>
<?php include 'shared_style.php'; ?>

/* KPI grid */
.kpi-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 20px; }
.kpi-card {
    background: var(--white); border: 1.5px solid var(--border);
    border-radius: var(--radius); padding: 16px 18px;
    box-shadow: var(--shadow-sm); border-top: 3px solid transparent;
}
.kc-icon { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 15px; margin-bottom: 10px; }
.kc-val  { font-size: 20px; font-weight: 900; font-family: 'Lora', serif; margin-bottom: 2px; }
.kc-lbl  { font-size: 11px; font-weight: 700; color: var(--text-muted); }
.kc-sub  { font-size: 10px; color: var(--text-muted); margin-top: 2px; }

/* P&L summary box */
.pl-box {
    background: var(--white); border: 1.5px solid var(--border);
    border-radius: var(--radius); padding: 18px 22px;
    box-shadow: var(--shadow-sm); margin-bottom: 20px;
    display: grid; grid-template-columns: repeat(3,1fr); gap: 0;
}
.pl-item { text-align: center; padding: 8px 16px; }
.pl-item + .pl-item { border-left: 1.5px solid var(--border); }
.pl-lbl { font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .1em; color: var(--text-muted); margin-bottom: 6px; }
.pl-val { font-family: 'Lora', serif; font-size: 22px; font-weight: 700; }

/* Two/Three cols */
.two-charts  { display: grid; grid-template-columns: 1.6fr 1fr; gap: 16px; margin-bottom: 20px; }
.three-cols  { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 20px; }

/* Category bar row */
.cat-bar-row {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 16px; border-bottom: 1px solid var(--border);
}
.cat-bar-row:last-child { border-bottom: none; }
.cbr-label { flex: 1; min-width: 0; }
.cbr-name  { font-size: 12px; font-weight: 800; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cbr-cnt   { font-size: 10px; color: var(--text-muted); }
.cbr-bar-wrap { width: 80px; height: 6px; background: var(--bg); border-radius: 3px; overflow: hidden; }
.cbr-bar  { height: 100%; border-radius: 3px; }
.cbr-amt  { font-size: 12px; font-weight: 900; color: var(--red); min-width: 80px; text-align: right; }

.amt-badge { display:inline-block;background:var(--red-lt);color:var(--red);border:1px solid #fca5a5;border-radius:6px;padding:3px 9px;font-size:13px;font-weight:900; }
</style>
</head>
<body>
<?php include 'shared_nav.php'; ?>
<div class="main">
<?php include 'shared_topbar.php'; ?>
<div class="content">

    <!-- Page header -->
    <div class="page-header">
        <div>
            <h2 class="page-title-h"><i class="fa-solid fa-chart-pie"></i> Expense Report</h2>
            <p class="page-sub">
                <?php echo date('d M Y', strtotime($f_from)); ?> &rarr;
                <?php echo date('d M Y', strtotime($f_to)); ?>
            </p>
        </div>
        <div style="display:flex;gap:8px;" class="no-print">
            <a class="btn-secondary" target="_blank" href="print_report.php?type=expenses&amp;from=<?php echo urlencode($f_from); ?>&amp;to=<?php echo urlencode($f_to); ?>&amp;cat=<?php echo urlencode($f_cat); ?>&amp;pm=<?php echo urlencode($f_pm); ?>">
                <i class="fa-solid fa-file-pdf"></i> Open Detailed Report
            </a>
            <a href="expenses.php" class="btn-primary">
                <i class="fa-solid fa-plus"></i> Add Expense
            </a>
        </div>
    </div>

    <!-- ── FILTER BAR ── -->
    <div class="card no-print" style="margin-bottom:18px;">
        <div style="padding:13px 18px;">
            <form method="GET" class="filter-form">
                <div class="ff">
                    <label>From Date</label>
                    <input type="date" name="from" value="<?php echo $f_from; ?>">
                </div>
                <div class="ff">
                    <label>To Date</label>
                    <input type="date" name="to" value="<?php echo $f_to; ?>">
                </div>
                <div class="ff">
                    <label>Category</label>
                    <select name="cat">
                        <option value="">All Categories</option>
                        <?php foreach ($all_cats as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $f_cat==$c?'selected':''; ?>>
                            <?php echo htmlspecialchars($c); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ff">
                    <label>Payment Method</label>
                    <select name="pm">
                        <option value="">All Methods</option>
                        <?php foreach (['Cash','Card','Bank Transfer','Other'] as $p): ?>
                        <option value="<?php echo $p; ?>" <?php echo $f_pm==$p?'selected':''; ?>><?php echo $p; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-primary" style="align-self:flex-end;">
                    <i class="fa-solid fa-filter"></i> Apply
                </button>
                <a href="expense_report.php" class="btn-secondary" style="align-self:flex-end;">
                    <i class="fa-solid fa-rotate"></i> Reset
                </a>
                <!-- Quick presets -->
                <div style="align-self:flex-end;display:flex;gap:6px;flex-wrap:wrap;">
                    <a href="?from=<?php echo date('Y-m-01'); ?>&to=<?php echo date('Y-m-d'); ?>" class="btn-secondary" style="font-size:11px;padding:8px 10px;">This Month</a>
                    <a href="?from=<?php echo date('Y-m-01',strtotime('last month')); ?>&to=<?php echo date('Y-m-t',strtotime('last month')); ?>" class="btn-secondary" style="font-size:11px;padding:8px 10px;">Last Month</a>
                    <a href="?from=<?php echo date('Y-01-01'); ?>&to=<?php echo date('Y-m-d'); ?>" class="btn-secondary" style="font-size:11px;padding:8px 10px;">This Year</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ── KPI CARDS ── -->
    <div class="kpi-row">
        <div class="kpi-card" style="border-top-color:var(--red);">
            <div class="kc-icon" style="background:var(--red-lt);color:var(--red);"><i class="fa-solid fa-money-bill-trend-up"></i></div>
            <div class="kc-val" style="color:var(--red);">Rs. <?php echo number_format($kpi['total'], 0); ?></div>
            <div class="kc-lbl">Total Expenses</div>
            <div class="kc-sub"><?php echo $kpi['cnt']; ?> entries in period</div>
        </div>
        <div class="kpi-card" style="border-top-color:var(--amber);">
            <div class="kc-icon" style="background:var(--amber-lt);color:var(--amber);"><i class="fa-solid fa-calculator"></i></div>
            <div class="kc-val" style="color:var(--amber);">Rs. <?php echo number_format($kpi['avg_amt'], 0); ?></div>
            <div class="kc-lbl">Avg per Entry</div>
            <div class="kc-sub">Max: Rs. <?php echo number_format($kpi['max_amt'], 0); ?></div>
        </div>
        <div class="kpi-card" style="border-top-color:var(--indigo);">
            <div class="kc-icon" style="background:var(--indigo-lt);color:var(--indigo);"><i class="fa-solid fa-sack-dollar"></i></div>
            <div class="kc-val" style="color:var(--indigo);">Rs. <?php echo number_format($revenue, 0); ?></div>
            <div class="kc-lbl">Revenue (same period)</div>
            <div class="kc-sub">From orders</div>
        </div>
        <div class="kpi-card" style="border-top-color:<?php echo $profit >= 0 ? 'var(--green)' : 'var(--red)'; ?>;">
            <div class="kc-icon" style="background:<?php echo $profit >= 0 ? 'var(--green-lt)' : 'var(--red-lt)'; ?>;color:<?php echo $profit >= 0 ? 'var(--green)' : 'var(--red)'; ?>;">
                <i class="fa-solid fa-<?php echo $profit >= 0 ? 'arrow-trend-up' : 'arrow-trend-down'; ?>"></i>
            </div>
            <div class="kc-val" style="color:<?php echo $profit >= 0 ? 'var(--green)' : 'var(--red)'; ?>;">
                Rs. <?php echo number_format(abs($profit), 0); ?>
            </div>
            <div class="kc-lbl"><?php echo $profit >= 0 ? 'Net Profit' : 'Net Loss'; ?></div>
            <div class="kc-sub">Revenue minus expenses</div>
        </div>
    </div>

    <!-- ── P&L SUMMARY BAR ── -->
    <div class="pl-box">
        <div class="pl-item">
            <div class="pl-lbl"><i class="fa-solid fa-sack-dollar"></i> &nbsp;Revenue</div>
            <div class="pl-val" style="color:var(--green);">Rs. <?php echo number_format($revenue, 2); ?></div>
        </div>
        <div class="pl-item">
            <div class="pl-lbl"><i class="fa-solid fa-money-bill-trend-up"></i> &nbsp;Expenses</div>
            <div class="pl-val" style="color:var(--red);">Rs. <?php echo number_format($kpi['total'], 2); ?></div>
        </div>
        <div class="pl-item">
            <div class="pl-lbl"><i class="fa-solid fa-<?php echo $profit>=0?'chart-line':'chart-line-down'; ?>"></i> &nbsp;Net <?php echo $profit>=0?'Profit':'Loss'; ?></div>
            <div class="pl-val" style="color:<?php echo $profit>=0?'var(--green)':'var(--red)'; ?>;">
                <?php echo $profit < 0 ? '-' : ''; ?>Rs. <?php echo number_format(abs($profit), 2); ?>
            </div>
        </div>
    </div>

    <!-- ── CHARTS ROW ── -->
    <div class="two-charts">
        <!-- Monthly Trend -->
        <div class="card" style="padding:18px 20px;">
            <h4 style="font-size:14px;font-weight:900;margin-bottom:14px;display:flex;align-items:center;gap:7px;">
                <i class="fa-solid fa-chart-area" style="color:var(--red);"></i> Monthly Expense Trend
            </h4>
            <canvas id="trendChart" height="100"></canvas>
        </div>
        <!-- Category doughnut -->
        <div class="card" style="padding:18px 20px;">
            <h4 style="font-size:14px;font-weight:900;margin-bottom:14px;display:flex;align-items:center;gap:7px;">
                <i class="fa-solid fa-chart-pie" style="color:var(--red);"></i> By Category
            </h4>
            <canvas id="catChart" height="130"></canvas>
        </div>
    </div>

    <!-- ── 3-COL: Category Table / Payment / Daily ── -->
    <div class="three-cols">

        <!-- Category breakdown -->
        <div class="card table-card-full">
            <div class="card-header">
                <h3><i class="fa-solid fa-tags"></i> By Category</h3>
            </div>
            <?php if (!empty($cat_rows)):
                $cat_colors = ['#d95c2b','#4f46e5','#16a34a','#d97706','#0284c7','#dc2626','#7c3aed','#059669','#b45309','#6b7280'];
                $max_cat = max(array_column($cat_rows,'total'));
                foreach ($cat_rows as $ci => $cr):
                    $pct = $max_cat > 0 ? round($cr['total']/$max_cat*100) : 0;
                    $clr = $cat_colors[$ci % count($cat_colors)];
            ?>
            <div class="cat-bar-row">
                <div style="width:8px;height:8px;border-radius:50%;background:<?php echo $clr; ?>;flex-shrink:0;"></div>
                <div class="cbr-label">
                    <div class="cbr-name"><?php echo htmlspecialchars($cr['category']); ?></div>
                    <div class="cbr-cnt"><?php echo $cr['cnt']; ?> entries</div>
                </div>
                <div class="cbr-bar-wrap"><div class="cbr-bar" style="width:<?php echo $pct; ?>%;background:<?php echo $clr; ?>;"></div></div>
                <div class="cbr-amt">Rs.<?php echo number_format($cr['total'],0); ?></div>
            </div>
            <?php endforeach; else: ?>
            <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px;">No data.</div>
            <?php endif; ?>
        </div>

        <!-- Payment method -->
        <div class="card table-card-full">
            <div class="card-header">
                <h3><i class="fa-solid fa-credit-card"></i> By Payment</h3>
            </div>
            <?php
            $pm_icons = ['Cash'=>'fa-money-bill-wave','Card'=>'fa-credit-card','Bank Transfer'=>'fa-building-columns','Other'=>'fa-circle-dot'];
            $pm_bdg   = ['Cash'=>'b-green','Card'=>'b-indigo','Bank Transfer'=>'b-sky','Other'=>'b-amber'];
            if (!empty($pm_rows)):
                $max_pm = max(array_column($pm_rows,'total'));
                foreach ($pm_rows as $pr):
                    $pct = $max_pm > 0 ? round($pr['total']/$max_pm*100) : 0;
            ?>
            <div class="cat-bar-row">
                <span class="badge <?php echo $pm_bdg[$pr['payment_method']] ?? 'b-amber'; ?>">
                    <i class="fa-solid <?php echo $pm_icons[$pr['payment_method']] ?? 'fa-circle-dot'; ?>"></i>
                    <?php echo htmlspecialchars($pr['payment_method']); ?>
                </span>
                <div class="cbr-bar-wrap"><div class="cbr-bar" style="width:<?php echo $pct; ?>%;background:var(--red);"></div></div>
                <div style="text-align:right;">
                    <div class="cbr-amt">Rs.<?php echo number_format($pr['total'],0); ?></div>
                    <div class="cbr-cnt"><?php echo $pr['cnt']; ?> entries</div>
                </div>
            </div>
            <?php endforeach; else: ?>
            <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px;">No data.</div>
            <?php endif; ?>
        </div>

        <!-- Daily summary -->
        <div class="card table-card-full">
            <div class="card-header">
                <h3><i class="fa-solid fa-calendar-days"></i> Daily Summary</h3>
                <span class="count-badge"><?php echo count($daily_rows); ?> days</span>
            </div>
            <div style="max-height:320px;overflow-y:auto;">
            <?php if (!empty($daily_rows)):
                $max_day = max(array_column($daily_rows,'total'));
                foreach ($daily_rows as $dr):
                    $pct = $max_day > 0 ? round($dr['total']/$max_day*100) : 0;
            ?>
            <div class="cat-bar-row">
                <div style="flex-shrink:0;min-width:80px;">
                    <div style="font-size:12px;font-weight:800;color:var(--text);"><?php echo date('d M', strtotime($dr['day'])); ?></div>
                    <div style="font-size:10px;color:var(--text-muted);"><?php echo date('D', strtotime($dr['day'])); ?></div>
                </div>
                <div class="cbr-bar-wrap"><div class="cbr-bar" style="width:<?php echo $pct; ?>%;background:var(--red);"></div></div>
                <div style="text-align:right;">
                    <div class="cbr-amt">Rs.<?php echo number_format($dr['total'],0); ?></div>
                    <div class="cbr-cnt"><?php echo $dr['cnt']; ?> entries</div>
                </div>
            </div>
            <?php endforeach; else: ?>
            <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px;">No data in this period.</div>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── TOP 10 BIGGEST EXPENSES ── -->
    <div class="card table-card-full" style="margin-bottom:20px;">
        <div class="card-header">
            <h3><i class="fa-solid fa-arrow-up-wide-short"></i> Highest Expenses in Period</h3>
            <span class="count-badge">Top 10</span>
        </div>
        <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Title</th>
                        <th>Amount</th>
                        <th>Payment</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rank = 0;
                    if ($top10 && $top10->num_rows > 0):
                        while ($ex = $top10->fetch_assoc()):
                            $rank++;
                            $pm_cls = ['Cash'=>'b-green','Card'=>'b-indigo','Bank Transfer'=>'b-sky','Cheque'=>'b-amber','Other'=>'b-amber'][$ex['payment_method']] ?? 'b-amber';
                            $pm_ico = ['Cash'=>'fa-money-bill-wave','Card'=>'fa-credit-card','Bank Transfer'=>'fa-building-columns','Cheque'=>'fa-money-check-dollar','Other'=>'fa-circle-dot'][$ex['payment_method']] ?? 'fa-circle-dot';
                    ?>
                    <tr>
                        <td>
                            <div style="width:24px;height:24px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;
                                background:<?php echo $rank<=3?($rank==1?'#fef3c7':($rank==2?'#f1f5f9':'#fff7ed')):'var(--bg)'; ?>;
                                color:<?php echo $rank<=3?($rank==1?'#d97706':($rank==2?'#64748b':'#ea580c')):'var(--text-muted)'; ?>;">
                                <?php echo $rank; ?>
                            </div>
                        </td>
                        <td style="white-space:nowrap;">
                            <strong><?php echo date('d M Y', strtotime($ex['expense_date'])); ?></strong>
                        </td>
                        <td>
                            <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:40px;font-size:11px;font-weight:900;background:var(--red-lt);color:var(--red);border:1px solid #fca5a5;">
                                <i class="fa-solid fa-tag"></i>
                                <?php echo htmlspecialchars($ex['category']); ?>
                            </span>
                        </td>
                        <td><strong style="color:var(--text);"><?php echo htmlspecialchars($ex['title']); ?></strong></td>
                        <td><span class="amt-badge">Rs. <?php echo number_format($ex['amount'], 2); ?></span></td>
                        <td>
                            <span class="badge <?php echo $pm_cls; ?>">
                                <i class="fa-solid <?php echo $pm_ico; ?>"></i>
                                <?php echo htmlspecialchars($ex['payment_method']); ?>
                            </span>
                        </td>
                        <td style="font-size:12px;color:var(--text-muted);max-width:160px;">
                            <?php echo htmlspecialchars($ex['note'] ?: '—'); ?>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="7" class="empty-row">No expenses found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── FULL LIST ── -->
    <div class="card table-card-full">
        <div class="card-header">
            <h3><i class="fa-solid fa-table-list"></i> All Expenses in Period</h3>
            <span class="count-badge"><?php echo $kpi['cnt']; ?> entries</span>
        </div>
        <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Title</th>
                        <th>Amount</th>
                        <th>Payment</th>
                        <th>Note</th>
                        <th class="no-print">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $all_exp = $conn->query("SELECT e.*, u.full_name AS added_by_name FROM expenses e
                        LEFT JOIN users u ON e.added_by=u.user_id WHERE $ws ORDER BY expense_date DESC, expense_id DESC");
                    if ($all_exp && $all_exp->num_rows > 0):
                        while ($ex = $all_exp->fetch_assoc()):
                            $pm_cls = ['Cash'=>'b-green','Card'=>'b-indigo','Bank Transfer'=>'b-sky','Other'=>'b-amber'][$ex['payment_method']] ?? 'b-amber';
                            $pm_ico = ['Cash'=>'fa-money-bill-wave','Card'=>'fa-credit-card','Bank Transfer'=>'fa-building-columns','Other'=>'fa-circle-dot'][$ex['payment_method']] ?? 'fa-circle-dot';
                    ?>
                    <tr>
                        <td style="white-space:nowrap;">
                            <strong style="font-size:12.5px;"><?php echo date('d M Y', strtotime($ex['expense_date'])); ?></strong><br>
                            <span style="font-size:10px;color:var(--text-muted);"><?php echo date('D', strtotime($ex['expense_date'])); ?></span>
                        </td>
                        <td>
                            <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:40px;font-size:11px;font-weight:900;background:var(--red-lt);color:var(--red);border:1px solid #fca5a5;">
                                <i class="fa-solid fa-tag"></i>
                                <?php echo htmlspecialchars($ex['category']); ?>
                            </span>
                        </td>
                        <td><strong style="color:var(--text);"><?php echo htmlspecialchars($ex['title']); ?></strong></td>
                        <td><span class="amt-badge">Rs. <?php echo number_format($ex['amount'], 2); ?></span></td>
                        <td>
                            <span class="badge <?php echo $pm_cls; ?>">
                                <i class="fa-solid <?php echo $pm_ico; ?>"></i>
                                <?php echo htmlspecialchars($ex['payment_method']); ?>
                            </span>
                        </td>
                        <td style="font-size:12px;color:var(--text-muted);max-width:180px;">
                            <?php echo htmlspecialchars($ex['note'] ?: '—'); ?>
                        </td>
                        <td class="no-print">
                            <a href="expenses.php?edit=<?php echo $ex['expense_id']; ?>" class="btn-edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="7" class="empty-row">No expenses found in this period.</td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="background:var(--bg);">
                        <td colspan="3" style="font-weight:900;font-size:13px;color:var(--text);padding:11px 14px;">
                            <strong>TOTAL</strong>
                        </td>
                        <td style="padding:11px 14px;">
                            <span style="display:inline-block;background:var(--red);color:#fff;border-radius:6px;padding:4px 10px;font-size:13px;font-weight:900;">
                                Rs. <?php echo number_format($kpi['total'], 2); ?>
                            </span>
                        </td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

</div><!-- /content -->
</div><!-- /main -->

<script>
Chart.defaults.font.family = "'Nunito', sans-serif";
Chart.defaults.font.weight = '700';
Chart.defaults.color = '#8e94b0';

/* ── Monthly trend ── */
const trendCtx = document.getElementById('trendChart');
if (trendCtx) {
    new Chart(trendCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($trend_labels); ?>,
            datasets: [{
                label: 'Expenses (Rs.)',
                data: <?php echo json_encode($trend_totals); ?>,
                backgroundColor: function(ctx) {
                    const chart = ctx.chart;
                    const {ctx: c, chartArea} = chart;
                    if (!chartArea) return '#d95c2b';
                    const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    g.addColorStop(0, 'rgba(220,38,38,.75)');
                    g.addColorStop(1, 'rgba(220,38,38,.15)');
                    return g;
                },
                borderRadius: 6,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: c => ' Rs. ' + c.raw.toLocaleString() } }
            },
            scales: {
                y: { grid: { color: '#f0f2f8' }, ticks: { callback: v => 'Rs.' + (v>=1000?Math.round(v/1000)+'k':v) } }
            }
        }
    });
}

/* ── Category doughnut ── */
const catCtx = document.getElementById('catChart');
if (catCtx) {
    const colors = ['#d95c2b','#4f46e5','#16a34a','#d97706','#0284c7','#dc2626','#7c3aed','#059669','#b45309','#6b7280'];
    new Chart(catCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($chart_cat_labels); ?>,
            datasets: [{
                data: <?php echo json_encode($chart_cat_totals); ?>,
                backgroundColor: colors,
                borderWidth: 3,
                borderColor: '#fff',
                hoverOffset: 6
            }]
        },
        options: {
            cutout: '65%',
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12, font: { size: 11 } } },
                tooltip: { callbacks: { label: c => ' Rs. ' + c.raw.toLocaleString() + ' — ' + c.label } }
            }
        }
    });
}
</script>
</body>
</html>
