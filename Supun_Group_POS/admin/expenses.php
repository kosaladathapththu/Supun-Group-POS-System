<?php
session_start();
include '../db.php';

if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["role"], ["admin", "manager"], true)) {
    header("Location: ../login.php"); exit;
}

$msg = ""; $msg_type = "";

/* ── PRESET CATEGORIES ── */
$preset_cats = [
    'Ingredients & Raw Materials',
    'Staff Salaries',
    'Utilities (Electric / Water / Gas)',
    'Rent & Lease',
    'Equipment & Maintenance',
    'Packaging & Supplies',
    'Marketing & Advertising',
    'Cleaning & Sanitation',
    'Transportation & Delivery',
    'Miscellaneous',
];

/* ── ADD ── */
if (isset($_POST['add_expense'])) {
    $cat   = trim($conn->real_escape_string($_POST['category']));
    $title = trim($conn->real_escape_string($_POST['title']));
    $amt   = (float)$_POST['amount'];
    $date  = $conn->real_escape_string($_POST['expense_date']);
    $pm    = $conn->real_escape_string($_POST['payment_method']);
    $note  = trim($conn->real_escape_string($_POST['note'] ?? ''));
    $uid   = (int)$_SESSION['user_id'];

    if ($cat && $title && $amt > 0 && $date) {
        $conn->query("INSERT INTO expenses (category, title, amount, expense_date, payment_method, note, added_by)
                      VALUES ('$cat','$title',$amt,'$date','$pm','$note',$uid)");
        $msg = "Expense added successfully."; $msg_type = "success";
    } else {
        $msg = "Please fill all required fields correctly."; $msg_type = "error";
    }
}

/* ── EDIT ── */
if (isset($_POST['edit_expense'])) {
    $id    = (int)$_POST['edit_id'];
    $cat   = trim($conn->real_escape_string($_POST['category']));
    $title = trim($conn->real_escape_string($_POST['title']));
    $amt   = (float)$_POST['amount'];
    $date  = $conn->real_escape_string($_POST['expense_date']);
    $pm    = $conn->real_escape_string($_POST['payment_method']);
    $note  = trim($conn->real_escape_string($_POST['note'] ?? ''));

    if ($cat && $title && $amt > 0 && $date) {
        $conn->query("UPDATE expenses SET category='$cat', title='$title', amount=$amt,
                      expense_date='$date', payment_method='$pm', note='$note'
                      WHERE expense_id=$id");
        $msg = "Expense updated."; $msg_type = "success";
    } else {
        $msg = "Please fill all required fields."; $msg_type = "error";
    }
}

/* ── DELETE ── */
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $conn->query("DELETE FROM expenses WHERE expense_id=$id");
    $msg = "Expense deleted."; $msg_type = "warning";
}

/* ── FETCH FOR EDIT ── */
$edit_row = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $edit_row = $conn->query("SELECT * FROM expenses WHERE expense_id=$eid")->fetch_assoc();
}

/* ── FILTERS ── */
$f_month = $_GET['month'] ?? date('Y-m');
$f_cat   = trim($_GET['cat'] ?? '');
$f_pm    = trim($_GET['pm']  ?? '');

$where = ["1=1"];
if ($f_month) $where[] = "DATE_FORMAT(expense_date,'%Y-%m')='" . $conn->real_escape_string($f_month) . "'";
if ($f_cat)   $where[] = "category='"        . $conn->real_escape_string($f_cat) . "'";
if ($f_pm)    $where[] = "payment_method='"  . $conn->real_escape_string($f_pm)  . "'";
$ws = implode(" AND ", $where);

/* ── KPIs ── */
$kpi = $conn->query("SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt FROM expenses WHERE $ws")->fetch_assoc();
$total_ever = $conn->query("SELECT COALESCE(SUM(amount),0) AS v FROM expenses")->fetch_assoc()['v'];
$this_month = $conn->query("SELECT COALESCE(SUM(amount),0) AS v FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')='" . date('Y-m') . "'")->fetch_assoc()['v'];

/* STOCK PURCHASES RECORDED FROM INVENTORY */
$purchase_month_sql = $f_month ? "AND DATE_FORMAT(sa.created_at,'%Y-%m')='".$conn->real_escape_string($f_month)."'" : '';
$purchase_filtered = (float)$conn->query("SELECT COALESCE(SUM(sa.total_cost),0) AS v FROM stock_adjustments sa WHERE sa.adjustment_type='stock_in' $purchase_month_sql")->fetch_assoc()['v'];
$purchase_this_month = (float)$conn->query("SELECT COALESCE(SUM(total_cost),0) AS v FROM stock_adjustments WHERE adjustment_type='stock_in' AND DATE_FORMAT(created_at,'%Y-%m')='".date('Y-m')."'")->fetch_assoc()['v'];
$purchases = $conn->query("SELECT sa.*,p.product_name,p.unit,u.full_name AS added_by_name FROM stock_adjustments sa JOIN products p ON p.product_id=sa.product_id LEFT JOIN users u ON u.user_id=sa.user_id WHERE sa.adjustment_type='stock_in' $purchase_month_sql ORDER BY sa.created_at DESC,sa.adjustment_id DESC");
$filtered_outgoing = (float)$kpi['total'] + $purchase_filtered;
$this_month_outgoing = (float)$this_month + $purchase_this_month;

/* ── EXPENSES LIST ── */
$expenses = $conn->query("
    SELECT e.*, u.full_name AS added_by_name
    FROM expenses e
    LEFT JOIN users u ON e.added_by = u.user_id
    WHERE $ws
    ORDER BY e.expense_date DESC, e.expense_id DESC
");

/* ── CATEGORY TOTALS for mini chart data ── */
$cat_totals = $conn->query("
    SELECT category, COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
    FROM expenses WHERE $ws
    GROUP BY category ORDER BY total DESC
");
$cat_data = [];
while ($r = $cat_totals->fetch_assoc()) $cat_data[] = $r;

/* Distinct categories already in DB */
$db_cats = $conn->query("SELECT DISTINCT category FROM expenses ORDER BY category ASC");
$all_cats = $preset_cats;
while ($r = $db_cats->fetch_assoc()) {
    if (!in_array($r['category'], $all_cats)) $all_cats[] = $r['category'];
}
sort($all_cats);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Expenses — The La-zogan</title>
<link href="https://fonts.googleapis.com/css2?family=Lora:wght@600;700&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
<?php include 'shared_style.php'; ?>

.form-sticky { position: sticky; top: calc(var(--topbar-h) + 16px); }

/* Category colour map */
.cat-chip {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; border-radius: 40px;
    font-size: 11px; font-weight: 900; white-space: nowrap;
    border: 1px solid transparent;
}

/* Amount badge */
.amt-badge {
    display: inline-block;
    background: var(--red-lt);
    color: var(--red);
    border: 1px solid #fca5a5;
    border-radius: 6px;
    padding: 3px 9px;
    font-size: 13px;
    font-weight: 900;
}

/* PM badge colours */
.pm-cash  { background: var(--green-lt); color: var(--green);  border-color: #86efac; }
.pm-card  { background: var(--indigo-lt); color: var(--indigo); border-color: #c7d2fe; }
.pm-bank  { background: var(--sky-lt);   color: var(--sky);    border-color: #bae6fd; }
.pm-other { background: var(--bg);       color: var(--text-muted); border-color: var(--border); }

/* Cat breakdown rows */
.cat-row {
    display: flex; align-items: center; gap: 12px;
    padding: 9px 16px; border-bottom: 1px solid var(--border);
}
.cat-row:last-child { border-bottom: none; }
.cat-bar-wrap { flex: 1; height: 6px; background: var(--bg); border-radius: 3px; overflow: hidden; }
.cat-bar      { height: 100%; background: var(--red); border-radius: 3px; }

.note-text {
    font-size: 11px; color: var(--text-muted); font-weight: 600;
    margin-top: 2px; font-style: italic;
    max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.purchase-total{font-weight:900;color:var(--indigo);white-space:nowrap}.purchase-qty{display:inline-flex;padding:3px 8px;border-radius:20px;background:var(--indigo-lt);color:var(--indigo);font-size:11px;font-weight:900}
@media(max-width:1000px){.expense-kpis{grid-template-columns:1fr 1fr!important}}
@media(max-width:560px){.expense-kpis{grid-template-columns:1fr!important}}
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
            <h2 class="page-title-h"><i class="fa-solid fa-money-bill-trend-up"></i> Expenses</h2>
            <p class="page-sub">Track and manage all business expenses</p>
        </div>
        <a href="expense_report.php" class="btn-primary">
            <i class="fa-solid fa-chart-pie"></i> Expense Report
        </a>
    </div>

    <!-- Alert -->
    <?php if ($msg): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <i class="fa-solid <?php echo $msg_type=='success'?'fa-circle-check':($msg_type=='warning'?'fa-triangle-exclamation':'fa-circle-exclamation'); ?>"></i>
        <?php echo htmlspecialchars($msg); ?>
    </div>
    <?php endif; ?>

    <!-- KPI strip -->
    <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:20px;" class="expense-kpis">
        <div class="stat-tile" style="border-left:4px solid var(--red);">
            <div class="st-icon" style="background:var(--red-lt);color:var(--red);"><i class="fa-solid fa-calendar-day"></i></div>
            <div>
                <div class="st-val" style="color:var(--red);">Rs. <?php echo number_format($this_month_outgoing, 0); ?></div>
                <div class="st-lbl">Total Outgoing This Month</div>
            </div>
        </div>
        <div class="stat-tile" style="border-left:4px solid var(--amber);">
            <div class="st-icon" style="background:var(--amber-lt);color:var(--amber);"><i class="fa-solid fa-filter"></i></div>
            <div>
                <div class="st-val" style="color:var(--amber);">Rs. <?php echo number_format($kpi['total'], 0); ?></div>
                <div class="st-lbl">Operating Expenses &bull; <?php echo $kpi['cnt']; ?> entries</div>
            </div>
        </div>
        <div class="stat-tile" style="border-left:4px solid var(--text-muted);">
            <div class="st-icon" style="background:var(--indigo-lt);color:var(--indigo);"><i class="fa-solid fa-boxes-stacked"></i></div>
            <div>
                <div class="st-val" style="color:var(--indigo);">Rs. <?php echo number_format($purchase_filtered, 0); ?></div>
                <div class="st-lbl">Stock Purchases</div>
            </div>
        </div>
        <div class="stat-tile" style="border-left:4px solid var(--primary);">
            <div class="st-icon" style="background:var(--primary-lt);color:var(--primary);"><i class="fa-solid fa-money-bill-transfer"></i></div>
            <div>
                <div class="st-val" style="color:var(--primary);">Rs. <?php echo number_format($filtered_outgoing, 0); ?></div>
                <div class="st-lbl">Filtered Total Outgoing</div>
            </div>
        </div>
    </div>

    <div class="two-col" id="expenseMainGrid" style="align-items:start;">

        <!-- ═══ FORM ═══ -->
        <div class="card form-sticky">
            <div class="card-header">
                <h3>
                    <i class="fa-solid fa-<?php echo $edit_row ? 'pen' : 'plus-circle'; ?>"></i>
                    <?php echo $edit_row ? 'Edit Expense' : 'Add New Expense'; ?>
                </h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php if ($edit_row): ?>
                        <input type="hidden" name="edit_id" value="<?php echo $edit_row['expense_id']; ?>">
                    <?php endif; ?>

                    <!-- Category -->
                    <div class="field">
                        <label>Category <span style="color:var(--red);">*</span></label>
                        <div class="inp-wrap">
                            <i class="fa-solid fa-tag"></i>
                            <input type="text" name="category" class="inp" style="padding-left:34px;"
                                   list="cat_list"
                                   placeholder="Select or type category"
                                   value="<?php echo htmlspecialchars($edit_row['category'] ?? ''); ?>"
                                   required autocomplete="off">
                        </div>
                        <datalist id="cat_list">
                            <?php foreach ($all_cats as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <!-- Title -->
                    <div class="field">
                        <label>Expense Title <span style="color:var(--red);">*</span></label>
                        <div class="inp-wrap">
                            <i class="fa-solid fa-file-invoice"></i>
                            <input type="text" name="title" class="inp" style="padding-left:34px;"
                                   placeholder="e.g. Monthly rice supply"
                                   value="<?php echo htmlspecialchars($edit_row['title'] ?? ''); ?>"
                                   required>
                        </div>
                    </div>

                    <!-- Amount + Date -->
                    <div class="two-field">
                        <div class="field">
                            <label>Amount (Rs.) <span style="color:var(--red);">*</span></label>
                            <div class="inp-wrap">
                                <i class="fa-solid fa-coins"></i>
                                <input type="number" name="amount" class="inp" style="padding-left:34px;"
                                       step="0.01" min="0.01" placeholder="0.00"
                                       value="<?php echo $edit_row ? number_format($edit_row['amount'], 2, '.', '') : ''; ?>"
                                       required>
                            </div>
                        </div>
                        <div class="field">
                            <label>Date <span style="color:var(--red);">*</span></label>
                            <input type="date" name="expense_date" class="inp"
                                   value="<?php echo htmlspecialchars($edit_row['expense_date'] ?? date('Y-m-d')); ?>"
                                   required>
                        </div>
                    </div>

                    <!-- Payment Method -->
                    <div class="field">
                        <label>Payment Method</label>
                        <select name="payment_method" class="inp">
                            <?php foreach (['Cash','Card','Bank Transfer','Other'] as $pm): ?>
                            <option value="<?php echo $pm; ?>"
                                <?php echo ($edit_row && $edit_row['payment_method']==$pm) ? 'selected' : ($pm=='Cash'&&!$edit_row?'selected':''); ?>>
                                <?php echo $pm; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Note -->
                    <div class="field">
                        <label>Note <span style="font-size:10px;color:var(--text-muted);text-transform:none;letter-spacing:0;font-weight:600;">(optional)</span></label>
                        <textarea name="note" class="inp" style="padding:10px 12px;height:70px;resize:vertical;font-family:'Nunito',sans-serif;"
                                  placeholder="Additional details…"><?php echo htmlspecialchars($edit_row['note'] ?? ''); ?></textarea>
                    </div>

                    <div style="display:flex;gap:8px;margin-top:4px;">
                        <button type="submit"
                                name="<?php echo $edit_row ? 'edit_expense' : 'add_expense'; ?>"
                                class="btn-primary" style="flex:1;justify-content:center;">
                            <i class="fa-solid fa-<?php echo $edit_row ? 'floppy-disk' : 'plus'; ?>"></i>
                            <?php echo $edit_row ? 'Update Expense' : 'Add Expense'; ?>
                        </button>
                        <?php if ($edit_row): ?>
                        <a href="expenses.php" class="btn-secondary" style="padding:9px 14px;">
                            <i class="fa-solid fa-xmark"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Category breakdown -->
            <?php if (!empty($cat_data)): ?>
            <div style="border-top:1.5px solid var(--border);padding:14px 18px 6px;">
                <div style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--text-muted);margin-bottom:10px;">
                    <i class="fa-solid fa-chart-pie"></i> &nbsp;By Category (filtered)
                </div>
                <?php
                $max_cat = max(array_column($cat_data, 'total'));
                $cat_colors = ['#d95c2b','#4f46e5','#16a34a','#d97706','#0284c7','#dc2626','#7c3aed','#059669','#b45309','#6b7280'];
                foreach ($cat_data as $ci => $cd):
                    $pct = $max_cat > 0 ? round($cd['total']/$max_cat*100) : 0;
                    $clr = $cat_colors[$ci % count($cat_colors)];
                ?>
                <div class="cat-row">
                    <div style="width:8px;height:8px;border-radius:50%;background:<?php echo $clr; ?>;flex-shrink:0;"></div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:11.5px;font-weight:800;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?php echo htmlspecialchars($cd['category']); ?>
                        </div>
                        <div class="cat-bar-wrap"><div class="cat-bar" style="width:<?php echo $pct; ?>%;background:<?php echo $clr; ?>;"></div></div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;">
                        <div style="font-size:12px;font-weight:900;color:var(--red);">Rs.<?php echo number_format($cd['total'],0); ?></div>
                        <div style="font-size:10px;color:var(--text-muted);"><?php echo $cd['cnt']; ?> entries</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══ TABLE ═══ -->
        <div style="display:flex;flex-direction:column;gap:12px;">

            <!-- Filter bar -->
            <form method="GET" class="filter-bar">
                <div class="ff">
                    <label>Month</label>
                    <input type="month" name="month" value="<?php echo $f_month; ?>">
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
                    <label>Payment</label>
                    <select name="pm">
                        <option value="">All</option>
                        <?php foreach (['Cash','Card','Bank Transfer','Other'] as $p): ?>
                        <option value="<?php echo $p; ?>" <?php echo $f_pm==$p?'selected':''; ?>><?php echo $p; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-primary" style="align-self:flex-end;">
                    <i class="fa-solid fa-filter"></i> Filter
                </button>
                <a href="expenses.php" class="btn-secondary" style="align-self:flex-end;">
                    <i class="fa-solid fa-rotate"></i> Reset
                </a>
            </form>

            <!-- Expenses table -->
            <div class="card table-card-full">
                <div class="card-header">
                    <h3><i class="fa-solid fa-list"></i> Expense Entries</h3>
                    <span class="count-badge"><?php echo $kpi['cnt']; ?> entries</span>
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
                                <th>Added By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($expenses && $expenses->num_rows > 0):
                            while ($ex = $expenses->fetch_assoc()):
                                /* Payment badge class */
                                $pm_cls = [
                                    'Cash'         => 'pm-cash',
                                    'Card'         => 'pm-card',
                                    'Bank Transfer'=> 'pm-bank',
                                    'Other'        => 'pm-other',
                                ][$ex['payment_method']] ?? 'pm-other';
                                $pm_ico = [
                                    'Cash'         => 'fa-money-bill-wave',
                                    'Card'         => 'fa-credit-card',
                                    'Bank Transfer'=> 'fa-building-columns',
                                    'Other'        => 'fa-circle-dot',
                                ][$ex['payment_method']] ?? 'fa-circle-dot';
                        ?>
                        <tr>
                            <td style="color:var(--text-muted);font-size:12px;"><?php echo $ex['expense_id']; ?></td>
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
                            <td>
                                <div style="font-weight:800;color:var(--text);font-size:13px;">
                                    <?php echo htmlspecialchars($ex['title']); ?>
                                </div>
                                <?php if (!empty($ex['note'])): ?>
                                <div class="note-text"><?php echo htmlspecialchars($ex['note']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="amt-badge">Rs. <?php echo number_format($ex['amount'], 2); ?></span>
                            </td>
                            <td>
                                <span class="badge <?php echo $pm_cls; ?>">
                                    <i class="fa-solid <?php echo $pm_ico; ?>"></i>
                                    <?php echo htmlspecialchars($ex['payment_method']); ?>
                                </span>
                            </td>
                            <td style="font-size:12px;">
                                <?php echo htmlspecialchars($ex['added_by_name'] ?? 'System'); ?>
                            </td>
                            <td>
                                <div class="action-btns">
                                    <a href="expenses.php?edit=<?php echo $ex['expense_id']; ?>" class="btn-edit">
                                        <i class="fa-solid fa-pen"></i> Edit
                                    </a>
                                    <a href="expenses.php?delete=<?php echo $ex['expense_id']; ?>"
                                       class="btn-del"
                                       onclick="return confirm('Delete this expense entry?')">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="8" class="empty-row">
                                <i class="fa-solid fa-receipt" style="font-size:24px;color:var(--border-dk);display:block;margin-bottom:8px;"></i>
                                No expenses found. Add your first expense using the form on the left.
                            </td>
                        </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div><!-- /two-col -->

    <section class="card table-card-full" id="stockPurchasesSection" style="margin:0 0 18px;">
        <div class="card-header">
            <div><h3><i class="fa-solid fa-truck-ramp-box"></i> Stock Purchases</h3><div style="font-size:10px;color:var(--text-muted);font-weight:700;margin-top:2px;">Automatically recorded from Inventory → Stock In</div></div>
            <div style="display:flex;align-items:center;gap:8px;"><span class="count-badge"><?php echo $purchases ? $purchases->num_rows : 0; ?> purchases</span><a href="products.php" class="btn-secondary no-print"><i class="fa-solid fa-plus"></i> Record Purchase</a></div>
        </div>
        <div class="tbl-wrap"><table><thead><tr><th>Date</th><th>Product</th><th>Quantity</th><th>Unit Cost</th><th>Total Purchase</th><th>Reference / Note</th><th>Recorded By</th></tr></thead><tbody>
        <?php if($purchases && $purchases->num_rows): while($purchase=$purchases->fetch_assoc()): ?>
            <tr><td style="white-space:nowrap;"><strong><?php echo date('d M Y',strtotime($purchase['created_at'])); ?></strong><br><small style="color:var(--text-muted);"><?php echo date('h:i A',strtotime($purchase['created_at'])); ?></small></td><td><strong><?php echo htmlspecialchars($purchase['product_name']); ?></strong></td><td><span class="purchase-qty"><?php echo number_format($purchase['quantity'],0).' '.htmlspecialchars($purchase['unit']); ?></span></td><td>Rs. <?php echo number_format($purchase['unit_cost'],2); ?></td><td class="purchase-total">Rs. <?php echo number_format($purchase['total_cost'],2); ?></td><td><?php echo htmlspecialchars($purchase['note'] ?: '—'); ?></td><td><?php echo htmlspecialchars($purchase['added_by_name'] ?: 'System'); ?></td></tr>
        <?php endwhile; else: ?><tr><td colspan="7" class="empty-row"><i class="fa-solid fa-box-open" style="font-size:22px;display:block;margin-bottom:7px;"></i>No stock purchases recorded for this month. Use Stock In from Inventory.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

<script>document.getElementById('expenseMainGrid')?.before(document.getElementById('stockPurchasesSection'));</script>
</div><!-- /content -->
</div><!-- /main -->
</body>
</html>
