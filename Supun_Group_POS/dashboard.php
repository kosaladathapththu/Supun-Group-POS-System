<?php
session_start();
include 'db.php';

if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["role"], ["admin", "manager"], true)) {
    header("Location: login.php");
    exit;
}

/* ===============================
   SAFE QUERY FUNCTION
=============================== */
function getValue($conn, $query) {
    $res = $conn->query($query);
    if (!$res) {
        die("Query Error: " . $conn->error);
    }
    return $res->fetch_assoc()["v"] ?? 0;
}

/* ===============================
   MAIN KPI DATA (ONLY PAID)
=============================== */
$total_sales   = getValue($conn, "SELECT COALESCE(SUM(total_amount),0) AS v FROM orders WHERE payment_status='paid'");
$total_orders  = getValue($conn, "SELECT COUNT(*) AS v FROM orders WHERE payment_status='paid'");

$today_sales   = getValue($conn, "SELECT COALESCE(SUM(total_amount),0) AS v FROM orders WHERE payment_status='paid' AND DATE(created_at)=CURDATE()");
$today_orders  = getValue($conn, "SELECT COUNT(*) AS v FROM orders WHERE payment_status='paid' AND DATE(created_at)=CURDATE()");

$month_sales   = getValue($conn, "SELECT COALESCE(SUM(total_amount),0) AS v FROM orders WHERE payment_status='paid' AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
$month_orders  = getValue($conn, "SELECT COUNT(*) AS v FROM orders WHERE payment_status='paid' AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");

$avg_order_val = $total_orders > 0 ? $total_sales / $total_orders : 0;

$yesterday_sales = getValue($conn, "SELECT COALESCE(SUM(total_amount),0) AS v FROM orders WHERE payment_status='paid' AND DATE(created_at)=DATE_SUB(CURDATE(),INTERVAL 1 DAY)");

$today_vs_yesterday = $yesterday_sales > 0
    ? (($today_sales - $yesterday_sales) / $yesterday_sales) * 100
    : 0;

/* ===============================
   LAST 7 DAYS
=============================== */
$daily_q = $conn->query("
    SELECT DATE(created_at) AS day,
           COALESCE(SUM(total_amount),0) AS total,
           COUNT(*) AS cnt
    FROM orders
    WHERE payment_status='paid'
      AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day ASC
");

if (!$daily_q) {
    die("Daily query error: " . $conn->error);
}

$daily_labels = [];
$daily_amounts = [];
$daily_counts = [];

$days_map = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $days_map[$d] = ['total' => 0, 'cnt' => 0];
}

while ($row = $daily_q->fetch_assoc()) {
    $days_map[$row['day']] = [
        'total' => (float)$row['total'],
        'cnt'   => (int)$row['cnt']
    ];
}

foreach ($days_map as $d => $v) {
    $daily_labels[]  = date('D d', strtotime($d));
    $daily_amounts[] = round($v['total'], 2);
    $daily_counts[]  = $v['cnt'];
}

/* ===============================
   PAYMENT METHODS
=============================== */
$pm_q = $conn->query("
    SELECT payment_method,
           COUNT(*) AS cnt,
           COALESCE(SUM(total_amount),0) AS total
    FROM orders
    WHERE payment_status='paid'
    GROUP BY payment_method
    ORDER BY total DESC
");

if (!$pm_q) {
    die("Payment method query error: " . $conn->error);
}

$pm_labels = [];
$pm_totals = [];

while ($row = $pm_q->fetch_assoc()) {
    $pm_labels[] = $row['payment_method'] ?: 'Unknown';
    $pm_totals[] = round((float)$row['total'], 2);
}

/* ===============================
   ORDER TYPES
=============================== */
$ot_q = $conn->query("
    SELECT order_type,
           COUNT(*) AS cnt,
           COALESCE(SUM(total_amount),0) AS total
    FROM orders
    WHERE payment_status='paid'
    GROUP BY order_type
");

if (!$ot_q) {
    die("Order type query error: " . $conn->error);
}

$ot_data = [];
while ($r = $ot_q->fetch_assoc()) {
    $ot_data[$r['order_type']] = $r;
}

/* ===============================
   TOP PRODUCTS
=============================== */
$top_q = $conn->query("
    SELECT
        p.product_name,
        SUM(oi.quantity) AS qty_sold,
        SUM(oi.line_total) AS revenue
    FROM order_items oi
    JOIN products p ON oi.product_id = p.product_id
    JOIN orders o ON oi.order_id = o.order_id
    WHERE oi.product_id IS NOT NULL
      AND o.payment_status='paid'
    GROUP BY oi.product_id, p.product_name
    ORDER BY qty_sold DESC
    LIMIT 8
");

if (!$top_q) {
    die("Top products error: " . $conn->error);
}

$top_products = [];
while ($r = $top_q->fetch_assoc()) {
    $top_products[] = $r;
}

/* ===============================
   RECENT ORDERS
=============================== */
$recent_q = $conn->query("
    SELECT o.order_id, o.order_number, o.order_type, o.payment_method,
           o.total_amount, o.created_at,
           u.full_name AS cashier
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.user_id
    WHERE o.payment_status='paid'
    ORDER BY o.created_at DESC
    LIMIT 10
");

if (!$recent_q) {
    die("Recent orders query error: " . $conn->error);
}

$recent_orders = [];
while ($r = $recent_q->fetch_assoc()) {
    $recent_orders[] = $r;
}

/* ===============================
   TODAY HOURLY ACTIVITY
=============================== */
$hourly_q = $conn->query("
    SELECT HOUR(created_at) AS hr,
           COUNT(*) AS cnt,
           COALESCE(SUM(total_amount),0) AS total
    FROM orders
    WHERE payment_status='paid'
      AND DATE(created_at)=CURDATE()
    GROUP BY HOUR(created_at)
");

if (!$hourly_q) {
    die("Hourly query error: " . $conn->error);
}

$hourly = array_fill(0, 24, ['cnt' => 0, 'total' => 0]);

while ($r = $hourly_q->fetch_assoc()) {
    $hourly[(int)$r['hr']] = [
        'cnt'   => (int)$r['cnt'],
        'total' => (float)$r['total']
    ];
}

/* ===============================
   MONTHLY TREND
=============================== */
$monthly_q = $conn->query("
    SELECT DATE_FORMAT(MIN(created_at),'%b %Y') AS mon,
           COALESCE(SUM(total_amount),0) AS total,
           COUNT(*) AS cnt
    FROM orders
    WHERE payment_status='paid'
      AND created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
    GROUP BY YEAR(created_at), MONTH(created_at)
    ORDER BY YEAR(created_at), MONTH(created_at)
");

if (!$monthly_q) {
    die("Monthly query error: " . $conn->error);
}

$mon_labels = [];
$mon_totals = [];

while ($r = $monthly_q->fetch_assoc()) {
    $mon_labels[] = $r['mon'];
    $mon_totals[] = round((float)$r['total'], 2);
}

/* ===============================
   COUNTS
=============================== */
$total_products   = getValue($conn, "SELECT COUNT(*) AS v FROM products WHERE status=1");
$total_categories = getValue($conn, "SELECT COUNT(*) AS v FROM categories WHERE status=1");
$total_users      = getValue($conn, "SELECT COUNT(*) AS v FROM users WHERE status=1");

/* ===============================
   ORDER TYPE SPLIT
=============================== */
$dine_cnt = $ot_data['retail']['cnt'] ?? 0;
$take_cnt = $ot_data['wholesale']['cnt'] ?? 0;

$dine_rev = $ot_data['retail']['total'] ?? 0;
$take_rev = $ot_data['wholesale']['total'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Supun Group — Owner Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Lora:wght@600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

<style>
:root {
    --primary:    #0f766e;
    --primary-dk: #b84a1f;
    --primary-lt: #fef3ed;
    --indigo:     #4f46e5;
    --indigo-lt:  #eef2ff;
    --green:      #16a34a;
    --green-lt:   #f0fdf4;
    --amber:      #d97706;
    --amber-lt:   #fffbeb;
    --sky:        #0284c7;
    --sky-lt:     #f0f9ff;
    --red:        #dc2626;
    --red-lt:     #fef2f2;
    --bg:         #f1f3f8;
    --white:      #ffffff;
    --border:     #e0e3ef;
    --border-dk:  #c8ccd8;
    --text:       #1c2038;
    --text-mid:   #454a66;
    --text-muted: #8e94b0;
    --sidebar-w:  264px;
    --topbar-h:   70px;
    --shadow-sm:  0 1px 3px rgba(0,0,0,.07);
    --shadow-md:  0 4px 16px rgba(0,0,0,.09);
    --radius:     12px;
    --radius-sm:  8px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { min-height: 100%; }
body {
    font-family: 'Nunito', sans-serif;
    background: var(--bg);
    color: var(--text);
}

/* Sidebar */
.sidebar {
    width: var(--sidebar-w);
    background: var(--white);
    border-right: 1.5px solid var(--border);
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0;
    left: 0;
    height: 100vh;
    z-index: 200;
    box-shadow: 2px 0 14px rgba(0,0,0,.06);
    overflow: hidden;
}

.sb-brand {
    padding: 16px 18px 14px;
    border-bottom: 1.5px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

.sb-logo {
    width: 36px;
    height: 36px;
    background: var(--primary);
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 16px;
    box-shadow: 0 3px 8px rgba(217,92,43,.3);
    flex-shrink: 0;
}
.sb-logo img { width:100%; height:100%; object-fit:contain; border-radius:9px; background:#fff; padding:2px; }

.sb-brand-text h2 {
    font-family: 'Lora', serif;
    font-size: 14px;
    color: var(--text);
    line-height: 1.1;
}

.sb-brand-text small {
    font-size: 9px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .1em;
    font-weight: 700;
}

.sb-nav {
    flex: 1;
    overflow-y: auto;
    padding: 8px 0 12px;
    scrollbar-width: thin;
    scrollbar-color: var(--border) transparent;
}

.nav-sec-lbl {
    font-size: 9px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .14em;
    color: var(--text-muted);
    padding: 12px 18px 3px;
    user-select: none;
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 800;
    color: var(--text-mid);
    text-decoration: none;
    cursor: pointer;
    transition: background .15s, color .15s;
    border: none;
    background: none;
    width: 100%;
    text-align: left;
    font-family: 'Nunito', sans-serif;
    position: relative;
}

.nav-item i {
    width: 17px;
    text-align: center;
    font-size: 13px;
    color: var(--text-muted);
    transition: color .15s;
    flex-shrink: 0;
}

.nav-item span { flex: 1; }

.nav-item:hover {
    background: var(--bg);
    color: var(--primary);
}
.nav-item:hover i { color: var(--primary); }

.nav-item.active {
    background: var(--primary-lt);
    color: var(--primary);
}
.nav-item.active i { color: var(--primary); }

.nav-item.active::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px;
    background: var(--primary);
    border-radius: 0 3px 3px 0;
}

.nav-grp-btn {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 11px 16px 11px 18px;
    border: none;
    background: none;
    cursor: pointer;
    font-family: 'Nunito', sans-serif;
    font-size: 13px;
    font-weight: 900;
    color: var(--text-mid);
    transition: background .15s, color .15s;
    margin-top: 6px;
}

.nav-grp-btn:hover {
    background: var(--bg);
    color: var(--primary);
}

.nav-grp-btn.grp-open {
    color: var(--primary);
    background: var(--bg);
}

.grp-left {
    display: flex;
    align-items: center;
    gap: 8px;
}

.grp-icon {
    font-size: 13px;
    color: var(--text-muted);
    transition: color .15s;
    flex-shrink: 0;
}
.nav-grp-btn:hover .grp-icon,
.nav-grp-btn.grp-open .grp-icon {
    color: var(--primary);
}

.grp-arrow {
    font-size: 10px;
    color: var(--border-dk);
    transition: transform .22s ease, color .15s;
}
.nav-grp-btn.grp-open .grp-arrow {
    transform: rotate(90deg);
    color: var(--primary);
}

.nav-grp-body {
    overflow: hidden;
    max-height: 0;
    transition: max-height .28s cubic-bezier(.4,0,.2,1);
}
.nav-grp-body.grp-open {
    max-height: 500px;
}

.nav-child {
    padding: 9px 18px 9px 44px;
    font-size: 12.5px;
    font-weight: 700;
}
.nav-child i {
    font-size: 12px;
    width: 15px;
}

.sb-footer {
    padding: 12px 14px;
    border-top: 1.5px solid var(--border);
    flex-shrink: 0;
}

.sb-user {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 9px 11px;
    background: var(--bg);
    border-radius: var(--radius-sm);
    margin-bottom: 8px;
}

.sb-avatar {
    width: 30px;
    height: 30px;
    background: linear-gradient(135deg,#6366f1,#8b5cf6);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 900;
    color: #fff;
    flex-shrink: 0;
}

.sb-user-info .name {
    font-size: 12px;
    font-weight: 800;
    color: var(--text);
}
.sb-user-info .role {
    font-size: 9px;
    font-weight: 900;
    color: var(--primary);
    text-transform: uppercase;
    letter-spacing: .06em;
}

.sb-footer-btns {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.sb-pos-btn, .btn-logout-sb {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    width: 100%;
    padding: 10px;
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-weight: 800;
    font-family: 'Nunito', sans-serif;
    text-decoration: none;
    transition: all .15s;
}

.sb-pos-btn {
    background: var(--primary-lt);
    border: 1.5px solid #f9c4a6;
    color: var(--primary);
}
.sb-pos-btn:hover {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}

.btn-logout-sb {
    background: var(--red-lt);
    border: 1.5px solid #fca5a5;
    color: var(--red);
}
.btn-logout-sb:hover {
    background: var(--red);
    color: #fff;
    border-color: var(--red);
}

/* Main */
.main {
    margin-left: var(--sidebar-w);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

.topbar {
    background: var(--white);
    border-bottom: 1.5px solid var(--border);
    height: var(--topbar-h);
    padding: 0 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: var(--shadow-sm);
}

.topbar-left { display: flex; align-items: center; gap: 10px; }
.page-title-main { font-family: 'Lora', serif; font-size: 18px; color: var(--text); }

.breadcrumb {
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 5px;
}

.topbar-right { display: flex; align-items: center; gap: 10px; }

.btn-pos {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 8px 16px;
    background: var(--primary);
    color: #fff;
    border: none;
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 800;
    text-decoration: none;
    box-shadow: 0 2px 8px rgba(217,92,43,.3);
    transition: all .17s;
}
.btn-pos:hover { background: var(--primary-dk); transform: translateY(-1px); }

.date-badge {
    background: var(--bg);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 7px 13px;
    font-size: 12px;
    font-weight: 800;
    color: var(--text-mid);
    display: flex;
    align-items: center;
    gap: 6px;
}

.content { padding: 22px 24px 32px; flex: 1; }
.section { margin-bottom: 28px; }

.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
}

.section-title {
    font-size: 15px;
    font-weight: 900;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-title i { color: var(--primary); font-size: 14px; }

.section-action {
    font-size: 12px;
    font-weight: 800;
    color: var(--primary);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 5px;
}

.kpi-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; }

.kpi-card {
    background: var(--white);
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    padding: 18px 18px 16px;
    box-shadow: var(--shadow-sm);
    position: relative;
    overflow: hidden;
}

.kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
}
.kpi-orange::before { background: var(--primary); }
.kpi-indigo::before { background: var(--indigo); }
.kpi-green::before  { background: var(--green); }
.kpi-amber::before  { background: var(--amber); }

.kpi-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 12px;
}

.kpi-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
}
.ki-orange { background: var(--primary-lt); color: var(--primary); }
.ki-indigo { background: var(--indigo-lt); color: var(--indigo); }
.ki-green  { background: var(--green-lt); color: var(--green); }
.ki-amber  { background: var(--amber-lt); color: var(--amber); }

.kpi-badge {
    font-size: 11px;
    font-weight: 800;
    padding: 3px 8px;
    border-radius: 40px;
    display: flex;
    align-items: center;
    gap: 4px;
}
.badge-up  { background: var(--green-lt); color: var(--green); }
.badge-down{ background: var(--red-lt); color: var(--red); }
.badge-neu { background: var(--bg); color: var(--text-muted); }

.kpi-value {
    font-family: 'Lora', serif;
    font-size: 24px;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 2px;
    line-height: 1.1;
}

.kpi-label {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-muted);
}

.quick-grid { display: grid; grid-template-columns: repeat(5,1fr); gap: 10px; }

.ql-card {
    background: var(--white);
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 12px;
    text-align: center;
    text-decoration: none;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    transition: all .17s;
    box-shadow: var(--shadow-sm);
}
.ql-card:hover { border-color: var(--primary); transform: translateY(-2px); }

.ql-icon {
    width: 42px;
    height: 42px;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}
.ql-label {
    font-size: 12px;
    font-weight: 900;
    color: var(--text-mid);
}

.charts-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 14px;
    align-items: start;
}

.metrics-grid {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 14px;
    align-items: start;
}

.chart-box {
    position: relative;
    width: 100%;
    height: 320px;
}

.chart-box.sm {
    height: 260px;
}

.chart-box.md {
    height: 300px;
}

.chart-box.lg {
    height: 360px;
}

.chart-card, .table-card {
    background: var(--white);
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
}

.chart-card { padding: 18px 20px; }
.chart-card h4 {
    font-size: 14px;
    font-weight: 900;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 7px;
}
.chart-card h4 i { color: var(--primary); font-size: 13px; }

.table-card { overflow: hidden; }

.table-card-header {
    padding: 15px 20px;
    border-bottom: 1.5px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.table-card-header h4 {
    font-size: 14px;
    font-weight: 900;
    display: flex;
    align-items: center;
    gap: 7px;
}
.table-card-header h4 i { color: var(--primary); font-size: 13px; }

table {
    width: 100%;
    border-collapse: collapse;
}

th {
    padding: 10px 16px;
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--text-muted);
    background: var(--bg);
    text-align: left;
    border-bottom: 1.5px solid var(--border);
}

td {
    padding: 11px 16px;
    font-size: 13px;
    font-weight: 700;
    border-bottom: 1px solid var(--border);
    color: var(--text-mid);
}

.badge-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 40px;
    font-size: 11px;
    font-weight: 900;
}

.bp-cash { background: var(--green-lt); color: var(--green); }
.bp-card { background: var(--indigo-lt); color: var(--indigo); }
.bp-qr   { background: var(--amber-lt); color: var(--amber); }
.bp-bank { background: var(--sky-lt); color: var(--sky); }
.bp-dine { background: var(--primary-lt); color: var(--primary); }
.bp-take { background: var(--amber-lt); color: var(--amber); }

.prod-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 9px 16px;
    border-bottom: 1px solid var(--border);
}

.prod-rank {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 900;
    flex-shrink: 0;
}
.rank-1 { background: #fef3c7; color: #d97706; }
.rank-2 { background: #f1f5f9; color: #64748b; }
.rank-3 { background: #fff7ed; color: #ea580c; }
.rank-n { background: var(--bg); color: var(--text-muted); }

.prod-name { flex: 1; font-size: 13px; font-weight: 800; color: var(--text); }

.prod-bar-wrap {
    width: 90px;
    height: 6px;
    background: var(--bg);
    border-radius: 3px;
    overflow: hidden;
}

.prod-bar {
    height: 100%;
    background: var(--primary);
    border-radius: 3px;
}

.prod-qty {
    font-size: 12px;
    font-weight: 800;
    color: var(--text-mid);
    min-width: 52px;
    text-align: right;
}

.prod-rev {
    font-size: 12px;
    font-weight: 800;
    color: var(--green);
    min-width: 80px;
    text-align: right;
}

.heatmap-grid {
    display: grid;
    grid-template-columns: repeat(12, 1fr);
    gap: 4px;
}

.hm-cell {
    aspect-ratio: 1;
    border-radius: 5px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    font-weight: 800;
    color: var(--text-muted);
    background: var(--bg);
    cursor: default;
    transition: all .15s;
    position: relative;
}

.hm-cell:hover::after {
    content: attr(data-tip);
    position: absolute;
    bottom: calc(100% + 5px);
    left: 50%;
    transform: translateX(-50%);
    background: var(--text);
    color: #fff;
    padding: 4px 8px;
    border-radius: 5px;
    font-size: 11px;
    white-space: nowrap;
    z-index: 10;
    pointer-events: none;
}

canvas {
    display: block;
    width: 100% !important;
    height: 100% !important;
}

@media (max-width: 1100px) {
    .kpi-grid { grid-template-columns: repeat(2,1fr); }
    .quick-grid { grid-template-columns: repeat(3,1fr); }
    .charts-grid { grid-template-columns: 1.5fr 1fr; }
    .metrics-grid { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 768px) {
    .sidebar {
        position: static;
        width: 100%;
        height: auto;
    }

    .main {
        margin-left: 0;
    }

    .topbar {
        position: static;
    }

    .kpi-grid { grid-template-columns: 1fr; }
    .quick-grid { grid-template-columns: repeat(2,1fr); }

    .charts-grid,
    .metrics-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<nav class="sidebar">
    <div class="sb-brand">
        <div class="sb-logo"><img src="supun-logo.png" alt="Supun Group"></div>
        <div class="sb-brand-text">
            <h2>Supun Group</h2>
            <small><?php echo $_SESSION['role']==='manager' ? 'Manager Panel' : 'Owner / Admin Panel'; ?></small>
        </div>
    </div>

    <div class="sb-nav">
        <div class="nav-sec-lbl">Overview</div>

        <a class="nav-item active" href="dashboard.php">
            <i class="fa-solid fa-gauge-high"></i><span>Dashboard</span>
        </a>

        <button class="nav-grp-btn" type="button" onclick="toggleGrp('reports', this)">
            <span class="grp-left"><i class="fa-solid fa-chart-bar grp-icon"></i>Reports</span>
            <i class="fa-solid fa-chevron-right grp-arrow"></i>
        </button>
        <div class="nav-grp-body" id="grp-reports">
            <a class="nav-item nav-child" href="admin/sales.php">
                <i class="fa-solid fa-file-invoice-dollar"></i><span>Sales Report</span>
            </a>
            <a class="nav-item nav-child" href="admin/orders.php">
                <i class="fa-solid fa-receipt"></i><span>All Orders</span>
            </a>
            <a class="nav-item nav-child" href="admin/expense_report.php">
                <i class="fa-solid fa-chart-pie"></i><span>Expense Report</span>
            </a>
        </div>

        <button class="nav-grp-btn" type="button" onclick="toggleGrp('finance', this)">
            <span class="grp-left"><i class="fa-solid fa-coins grp-icon"></i>Finance</span>
            <i class="fa-solid fa-chevron-right grp-arrow"></i>
        </button>
        <div class="nav-grp-body" id="grp-finance">
            <a class="nav-item nav-child" href="admin/expenses.php">
                <i class="fa-solid fa-money-bill-trend-up"></i><span>Expenses</span>
            </a>
        </div>

        <button class="nav-grp-btn" type="button" onclick="toggleGrp('management', this)">
            <span class="grp-left"><i class="fa-solid fa-sliders grp-icon"></i>Management</span>
            <i class="fa-solid fa-chevron-right grp-arrow"></i>
        </button>
        <div class="nav-grp-body" id="grp-management">
            <a class="nav-item nav-child" href="admin/products.php">
                <i class="fa-solid fa-boxes-stacked"></i><span>Inventory</span>
            </a>
            <a class="nav-item nav-child" href="admin/categories.php">
                <i class="fa-solid fa-tags"></i><span>Categories</span>
            </a>
            <?php if ($_SESSION['role']==='admin'): ?><a class="nav-item nav-child" href="admin/users.php">
                <i class="fa-solid fa-users"></i><span>Staff / Users</span>
            </a><?php endif; ?>
        </div>
    </div>

    <div class="sb-footer">
        <div class="sb-user">
            <div class="sb-avatar"><?php echo strtoupper(substr($_SESSION["full_name"] ?? "A", 0, 1)); ?></div>
            <div class="sb-user-info">
                <div class="name"><?php echo htmlspecialchars($_SESSION["full_name"] ?? "Admin"); ?></div>
                <div class="role"><?php echo $_SESSION['role']==='manager' ? 'Manager' : 'Owner / Admin'; ?></div>
            </div>
        </div>
        <div class="sb-footer-btns">
            <a href="pos.php" class="sb-pos-btn">
                <i class="fa-solid fa-cash-register"></i> Go to POS
            </a>
            <a href="logout.php" class="btn-logout-sb">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </div>
</nav>

<!-- MAIN -->
<div class="main">

    <div class="topbar">
        <div class="topbar-left">
            <div>
                <div class="page-title-main"><?php echo $_SESSION['role']==='manager' ? 'Manager Dashboard' : 'Owner Dashboard'; ?></div>
                <div class="breadcrumb">
                    <i class="fa-solid fa-house"></i>&nbsp;Home
                    <i class="fa-solid fa-chevron-right"></i> Dashboard
                </div>
            </div>
        </div>
        <div class="topbar-right">
            <div class="date-badge">
                <i class="fa-regular fa-calendar"></i>
                <?php echo date('D, d M Y'); ?>
            </div>
            <a href="pos.php" class="btn-pos">
                <i class="fa-solid fa-cash-register"></i> Go to POS
            </a>
        </div>
    </div>

    <div class="content">

        <div class="section">
            <div class="section-header">
                <div class="section-title"><i class="fa-solid fa-gauge-high"></i> Key Performance</div>
                <span style="font-size:12px;color:var(--text-muted);font-weight:700;"><i class="fa-regular fa-clock"></i> Live data</span>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card kpi-orange">
                    <div class="kpi-top">
                        <div class="kpi-icon ki-orange"><i class="fa-solid fa-coins"></i></div>
                        <?php $dir = $today_vs_yesterday >= 0 ? 'up' : 'down'; $icon = $dir === 'up' ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down'; ?>
                        <span class="kpi-badge badge-<?php echo $dir; ?>">
                            <i class="fa-solid <?php echo $icon; ?>"></i><?php echo abs(round($today_vs_yesterday, 1)); ?>%
                        </span>
                    </div>
                    <div class="kpi-value">Rs. <?php echo number_format($today_sales, 0); ?></div>
                    <div class="kpi-label">Today's Sales • <?php echo $today_orders; ?> orders</div>
                </div>

                <div class="kpi-card kpi-indigo">
                    <div class="kpi-top">
                        <div class="kpi-icon ki-indigo"><i class="fa-solid fa-calendar-check"></i></div>
                        <span class="kpi-badge badge-neu"><?php echo date('M'); ?></span>
                    </div>
                    <div class="kpi-value">Rs. <?php echo number_format($month_sales, 0); ?></div>
                    <div class="kpi-label">This Month • <?php echo $month_orders; ?> orders</div>
                </div>

                <div class="kpi-card kpi-green">
                    <div class="kpi-top">
                        <div class="kpi-icon ki-green"><i class="fa-solid fa-sack-dollar"></i></div>
                        <span class="kpi-badge badge-up"><i class="fa-solid fa-check"></i> All time</span>
                    </div>
                    <div class="kpi-value">Rs. <?php echo number_format($total_sales, 0); ?></div>
                    <div class="kpi-label">Total Revenue • <?php echo number_format($total_orders); ?> orders</div>
                </div>

                <div class="kpi-card kpi-amber">
                    <div class="kpi-top">
                        <div class="kpi-icon ki-amber"><i class="fa-solid fa-chart-simple"></i></div>
                        <span class="kpi-badge badge-neu">avg</span>
                    </div>
                    <div class="kpi-value">Rs. <?php echo number_format($avg_order_val, 0); ?></div>
                    <div class="kpi-label">Avg Order Value</div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <div class="section-title"><i class="fa-solid fa-briefcase"></i> Retail Operations</div>
            </div>

            <div class="quick-grid">
                <a href="admin/product_import.php" class="ql-card">
                    <div class="ql-icon" style="background:var(--green-lt);color:var(--green);"><i class="fa-solid fa-file-excel"></i></div>
                    <div class="ql-label">Import Inventory</div>
                    <div style="font-size:11px;color:var(--text-muted);font-weight:700;">Suppliers, products and stock</div>
                </a>

                <a href="admin/products.php" class="ql-card">
                    <div class="ql-icon" style="background:var(--primary-lt);color:var(--primary);"><i class="fa-solid fa-boxes-stacked"></i></div>
                    <div class="ql-label">Inventory</div>
                    <div style="font-size:11px;color:var(--text-muted);font-weight:700;"><?php echo $total_products; ?> active</div>
                </a>

                <a href="admin/categories.php" class="ql-card">
                    <div class="ql-icon" style="background:var(--indigo-lt);color:var(--indigo);"><i class="fa-solid fa-tags"></i></div>
                    <div class="ql-label">Categories</div>
                    <div style="font-size:11px;color:var(--text-muted);font-weight:700;"><?php echo $total_categories; ?> active</div>
                </a>

                <?php if ($_SESSION['role']==='admin'): ?><a href="admin/users.php" class="ql-card">
                    <div class="ql-icon" style="background:var(--green-lt);color:var(--green);"><i class="fa-solid fa-users"></i></div>
                    <div class="ql-label">Staff</div>
                    <div style="font-size:11px;color:var(--text-muted);font-weight:700;"><?php echo $total_users; ?> users</div>
                </a><?php endif; ?>

                <a href="admin/orders.php" class="ql-card">
                    <div class="ql-icon" style="background:var(--amber-lt);color:var(--amber);"><i class="fa-solid fa-list-check"></i></div>
                    <div class="ql-label">All Orders</div>
                    <div style="font-size:11px;color:var(--text-muted);font-weight:700;"><?php echo number_format($total_orders); ?> total</div>
                </a>

                <a href="admin/expenses.php" class="ql-card">
                    <div class="ql-icon" style="background:var(--red-lt);color:var(--red);"><i class="fa-solid fa-money-bill-trend-up"></i></div>
                    <div class="ql-label">Expenses</div>
                    <div style="font-size:11px;color:var(--text-muted);font-weight:700;">Track costs</div>
                </a>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <div class="section-title"><i class="fa-solid fa-chart-line"></i> Sales — Last 7 Days</div>
            </div>

            <div class="charts-grid">
                <div class="chart-card">
                    <h4><i class="fa-solid fa-chart-bar"></i> Daily Sales (Rs.)</h4>
                    <div class="chart-box md">
                        <canvas id="dailyChart"></canvas>
                    </div>
                </div>

                <div class="chart-card">
                    <h4><i class="fa-solid fa-credit-card"></i> Payment Methods</h4>
                    <div class="chart-box sm">
                        <canvas id="pmChart"></canvas>
                    </div>
                    <div id="pmLegend" style="margin-top:12px;"></div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <div class="section-title"><i class="fa-solid fa-chart-area"></i> Monthly Revenue Trend</div>
            </div>

            <div class="chart-card">
                <h4><i class="fa-solid fa-calendar"></i> Last 6 Months</h4>
                <div class="chart-box md">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="metrics-grid">
                <div class="chart-card">
                    <h4><i class="fa-solid fa-store"></i> Retail vs Wholesale Sales</h4>
                    <div class="chart-box sm">
                        <canvas id="otChart"></canvas>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px;">
                        <div style="background:var(--primary-lt);border-radius:8px;padding:10px 12px;">
                            <div style="font-size:11px;color:var(--primary);font-weight:900;text-transform:uppercase;letter-spacing:.07em;">Retail</div>
                            <div style="font-size:18px;font-weight:900;color:var(--text);"><?php echo $dine_cnt; ?></div>
                            <div style="font-size:11px;color:var(--text-muted);font-weight:700;">Rs. <?php echo number_format($dine_rev, 0); ?></div>
                        </div>

                        <div style="background:var(--amber-lt);border-radius:8px;padding:10px 12px;">
                            <div style="font-size:11px;color:var(--amber);font-weight:900;text-transform:uppercase;letter-spacing:.07em;">Wholesale</div>
                            <div style="font-size:18px;font-weight:900;color:var(--text);"><?php echo $take_cnt; ?></div>
                            <div style="font-size:11px;color:var(--text-muted);font-weight:700;">Rs. <?php echo number_format($take_rev, 0); ?></div>
                        </div>
                    </div>
                </div>

                <div class="chart-card">
                    <h4><i class="fa-solid fa-clock"></i> Today's Hourly Activity</h4>
                    <div class="heatmap-grid" id="heatmapGrid"></div>

                    <div style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:11px;color:var(--text-muted);font-weight:700;">
                        <span>Low</span>
                        <?php $bg=['#f1f3f8','#fde8dc','#f9c4a6','#f4a07a','#ea7044','#d95c2b']; for($i=0;$i<=5;$i++): ?>
                            <div style="width:18px;height:18px;border-radius:4px;background:<?php echo $bg[$i]; ?>;"></div>
                        <?php endfor; ?>
                        <span>High</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <div class="section-title"><i class="fa-solid fa-trophy"></i> Top Selling Products</div>
                <a href="admin/products.php" class="section-action">Manage Products <i class="fa-solid fa-arrow-right"></i></a>
            </div>

            <div class="table-card">
                <div class="table-card-header">
                    <h4><i class="fa-solid fa-ranking-star"></i> By Quantity Sold</h4>
                </div>

                <?php if (!empty($top_products)): ?>
                    <?php $max_qty = max(array_column($top_products, 'qty_sold')); ?>
                    <?php foreach ($top_products as $i => $p): ?>
                        <?php
                        $rank = $i + 1;
                        $pct = $max_qty > 0 ? round($p['qty_sold'] / $max_qty * 100) : 0;
                        $rcls = $rank == 1 ? 'rank-1' : ($rank == 2 ? 'rank-2' : ($rank == 3 ? 'rank-3' : 'rank-n'));
                        ?>
                        <div class="prod-row">
                            <div class="prod-rank <?php echo $rcls; ?>"><?php echo $rank; ?></div>
                            <div class="prod-name"><?php echo htmlspecialchars($p['product_name']); ?></div>
                            <div class="prod-bar-wrap"><div class="prod-bar" style="width:<?php echo $pct; ?>%"></div></div>
                            <div class="prod-qty"><?php echo number_format($p['qty_sold']); ?> sold</div>
                            <div class="prod-rev">Rs. <?php echo number_format($p['revenue'], 0); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="padding:24px;text-align:center;color:var(--text-muted);font-weight:700;font-size:13px;">No sales data yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <div class="section-title"><i class="fa-solid fa-receipt"></i> Recent Orders</div>
                <a href="admin/orders.php" class="section-action">View All <i class="fa-solid fa-arrow-right"></i></a>
            </div>

            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date &amp; Time</th>
                            <th>Cashier</th>
                            <th>Type</th>
                            <th>Payment</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recent_orders)): ?>
                            <?php foreach ($recent_orders as $o): ?>
                                <?php
                                $pm = $o['payment_method'];
                                $cls_map  = ['Cash'=>'bp-cash','Card'=>'bp-card','QR'=>'bp-qr','Bank Transfer'=>'bp-bank'];
                                $icon_map = ['Cash'=>'fa-money-bill-wave','Card'=>'fa-credit-card','QR'=>'fa-qrcode','Bank Transfer'=>'fa-building-columns'];
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($o['order_number'] ?: ('#' . $o['order_id'])); ?></strong></td>
                                    <td><?php echo date('d M, h:i A', strtotime($o['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($o['cashier'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php if ($o['order_type'] == 'retail'): ?>
                                            <span class="badge-pill bp-dine"><i class="fa-solid fa-basket-shopping"></i> Retail</span>
                                        <?php else: ?>
                                            <span class="badge-pill bp-take"><i class="fa-solid fa-boxes-stacked"></i> Wholesale</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-pill <?php echo $cls_map[$pm] ?? 'bp-cash'; ?>">
                                            <i class="fa-solid <?php echo $icon_map[$pm] ?? 'fa-money-bill'; ?>"></i>
                                            <?php echo htmlspecialchars($pm); ?>
                                        </span>
                                    </td>
                                    <td><strong style="color:var(--primary);">Rs. <?php echo number_format($o['total_amount'], 2); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align:center;color:var(--text-muted);padding:24px;">No orders found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
function toggleGrp(name, btnEl) {
    const body = document.getElementById('grp-' + name);
    if (!body) return;

    const btn = btnEl || body.previousElementSibling;
    const open = body.classList.contains('grp-open');

    body.classList.toggle('grp-open', !open);
    if (btn) btn.classList.toggle('grp-open', !open);
}

Chart.defaults.font.family = "'Nunito', sans-serif";
Chart.defaults.font.weight = '700';
Chart.defaults.color = '#8e94b0';

const prim = '#0f766e';
const indigo = '#4f46e5';
const green = '#16a34a';
const amber = '#d97706';
const sky = '#0284c7';

/* Daily bar chart */
const dailyCtx = document.getElementById('dailyChart');
if (dailyCtx) {
    new Chart(dailyCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($daily_labels); ?>,
            datasets: [
                {
                    label: 'Sales (Rs.)',
                    data: <?php echo json_encode($daily_amounts); ?>,
                    backgroundColor: 'rgba(217,92,43,0.75)',
                    borderRadius: 6,
                    borderSkipped: false
                },
                {
                    label: 'Orders',
                    data: <?php echo json_encode($daily_counts); ?>,
                    type: 'line',
                    borderColor: indigo,
                    backgroundColor: 'rgba(79,70,229,.08)',
                    borderWidth: 2,
                    pointBackgroundColor: indigo,
                    pointRadius: 4,
                    tension: .4,
                    yAxisID: 'y2',
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'top',
                    labels: { usePointStyle: true, padding: 16, font: { size: 12 } }
                },
                tooltip: {
                    callbacks: {
                        label: c => c.datasetIndex === 0 ? ' Rs. ' + Number(c.raw).toLocaleString() : ' ' + c.raw + ' orders'
                    }
                }
            },
            scales: {
                y: {
                    grid: { color: '#f0f2f8' },
                    ticks: {
                        callback: v => 'Rs.' + (v >= 1000 ? Math.round(v / 1000) + 'k' : v)
                    }
                },
                y2: {
                    position: 'right',
                    grid: { display: false },
                    ticks: {
                        callback: v => v + ' ord'
                    }
                }
            }
        }
    });
}

/* Payment doughnut */
const pmCtx = document.getElementById('pmChart');
if (pmCtx) {
    const colors = [prim, indigo, amber, sky, green, '#ec4899'];
    const pmLabels = <?php echo json_encode($pm_labels); ?>;
    const pmTotals = <?php echo json_encode($pm_totals); ?>;

    new Chart(pmCtx, {
        type: 'doughnut',
        data: {
            labels: pmLabels,
            datasets: [{
                data: pmTotals,
                backgroundColor: colors,
                borderWidth: 3,
                borderColor: '#fff',
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: c => ' Rs. ' + Number(c.raw).toLocaleString() + ' — ' + c.label
                    }
                }
            }
        }
    });

    const leg = document.getElementById('pmLegend');
    if (leg) {
        pmLabels.forEach((l, i) => {
            const div = document.createElement('div');
            div.style = 'display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:12px;font-weight:800;color:#454a66;';
            div.innerHTML = `<span style="width:10px;height:10px;border-radius:3px;background:${colors[i]};flex-shrink:0;"></span><span style="flex:1;">${l}</span><span style="color:#1c2038;">Rs. ${Number(pmTotals[i]).toLocaleString()}</span>`;
            leg.appendChild(div);
        });
    }
}

/* Monthly line */
const mCtx = document.getElementById('monthlyChart');
if (mCtx) {
    new Chart(mCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($mon_labels); ?>,
            datasets: [{
                label: 'Monthly Revenue',
                data: <?php echo json_encode($mon_totals); ?>,
                borderColor: prim,
                borderWidth: 3,
                backgroundColor: 'rgba(217,92,43,.15)',
                fill: true,
                tension: .4,
                pointBackgroundColor: prim,
                pointRadius: 5,
                pointHoverRadius: 7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: c => ' Rs. ' + Number(c.raw).toLocaleString()
                    }
                }
            },
            scales: {
                y: {
                    grid: { color: '#f0f2f8' },
                    ticks: {
                        callback: v => 'Rs.' + (v >= 1000 ? Math.round(v / 1000) + 'k' : v)
                    }
                }
            }
        }
    });
}

/* Order type doughnut */
const otCtx = document.getElementById('otChart');
if (otCtx) {
    new Chart(otCtx, {
        type: 'doughnut',
        data: {
            labels: ['Retail', 'Wholesale'],
            datasets: [{
                data: [<?php echo $dine_cnt; ?>, <?php echo $take_cnt; ?>],
                backgroundColor: [prim, amber],
                borderWidth: 3,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { usePointStyle: true, padding: 14 }
                }
            }
        }
    });
}

/* Hourly heatmap */
(function() {
    const hourly = <?php echo json_encode($hourly); ?>;
    const grid = document.getElementById('heatmapGrid');
    if (!grid) return;

    const maxCnt = Math.max(...hourly.map(h => h.cnt), 1);
    const colors = ['#f1f3f8','#fde8dc','#f9c4a6','#f4a07a','#ea7044','#d95c2b'];

    hourly.forEach((h, i) => {
        const lvl = Math.round((h.cnt / maxCnt) * 5);
        const label = i === 0 ? '12am' : (i < 12 ? i + 'am' : (i === 12 ? '12pm' : (i - 12) + 'pm'));

        const cell = document.createElement('div');
        cell.className = 'hm-cell';
        cell.style.background = colors[lvl];
        cell.style.color = lvl >= 3 ? '#fff' : '#8e94b0';
        cell.textContent = label;
        cell.setAttribute('data-tip', `${label}: ${h.cnt} orders • Rs.${Math.round(h.total).toLocaleString()}`);
        grid.appendChild(cell);
    });
})();
</script>
</body>
</html>
