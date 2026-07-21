<?php
session_start();
include '../db.php';

if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["role"], ["admin", "accountant"], true)) {
    header("Location: ../login.php"); exit;
}

/* ── FILTERS ── */
$search      = trim($_GET["search"]         ?? "");
$filter_type = trim($_GET["order_type"]     ?? "");
$filter_pm   = trim($_GET["payment_method"] ?? "");
$filter_date = trim($_GET["date"]           ?? "");
$page        = max(1, (int)($_GET["page"]   ?? 1));
$per_page    = 20;
$offset      = ($page - 1) * $per_page;

$where = ["1=1"];
if ($search !== "") {
    $ss = $conn->real_escape_string($search);
    $where[] = "(o.order_id LIKE '%$ss%' OR u.full_name LIKE '%$ss%' OR o.payment_method LIKE '%$ss%')";
}
if ($filter_type !== "") $where[] = "o.order_type='"     . $conn->real_escape_string($filter_type) . "'";
if ($filter_pm   !== "") $where[] = "o.payment_method='" . $conn->real_escape_string($filter_pm)   . "'";
if ($filter_date !== "") $where[] = "DATE(o.created_at)='" . $conn->real_escape_string($filter_date) . "'";
$ws = implode(" AND ", $where);

$total_rows  = $conn->query("SELECT COUNT(*) AS c FROM orders o LEFT JOIN users u ON o.user_id=u.user_id WHERE $ws")->fetch_assoc()["c"];
$total_pages = max(1, ceil($total_rows / $per_page));

$orders_q = $conn->query("
    SELECT o.*, u.full_name AS cashier
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.user_id
    WHERE $ws
    ORDER BY o.created_at DESC
    LIMIT $per_page OFFSET $offset
");

$sum_q = $conn->query("
    SELECT COALESCE(SUM(o.total_amount),0) AS total, COUNT(*) AS cnt
    FROM orders o LEFT JOIN users u ON o.user_id=u.user_id
    WHERE $ws
")->fetch_assoc();

$pm_map  = [
    'Cash'          => ['b-green',  'fa-money-bill-wave'],
    'Card'          => ['b-indigo', 'fa-credit-card'],
    'QR'            => ['b-amber',  'fa-qrcode'],
    'Bank Transfer' => ['b-sky',    'fa-building-columns'],
    'Cheque'        => ['b-amber',  'fa-money-check-dollar'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>All Orders — The La-zogan</title>
<link href="https://fonts.googleapis.com/css2?family=Lora:wght@600;700&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
<?php include 'shared_style.php'; ?>

/* ── Orders-specific ── */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}

.sum-card {
    background: var(--white);
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 18px;
    box-shadow: var(--shadow-sm);
    border-top: 3px solid var(--border);
    transition: transform .16s, box-shadow .16s;
}
.sum-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.sum-card.sc-primary { border-top-color: var(--primary); }
.sum-card.sc-green   { border-top-color: var(--green); }
.sum-card.sc-indigo  { border-top-color: var(--indigo); }

.sc-icon { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 15px; margin-bottom: 10px; }
.sc-val  { font-size: 22px; font-weight: 900; font-family: 'Lora', serif; line-height: 1.1; margin-bottom: 2px; }
.sc-lbl  { font-size: 11px; font-weight: 700; color: var(--text-muted); }

/* Filter card */
.filter-card {
    background: var(--white);
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    padding: 14px 18px;
    margin-bottom: 16px;
    box-shadow: var(--shadow-sm);
}

.filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-end;
}

.ff-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.ff-group label {
    font-size: 10px; font-weight: 900;
    text-transform: uppercase; letter-spacing: .09em;
    color: var(--text-muted);
}
.ff-group input,
.ff-group select {
    background: var(--bg);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 9px 12px;
    font-size: 13px; font-family: 'Nunito', sans-serif; font-weight: 700;
    color: var(--text); outline: none;
    min-width: 130px;
    transition: border-color .15s;
}
.ff-group input:focus,
.ff-group select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(217,92,43,.08); }

.ff-search {
    flex: 2; min-width: 180px;
    position: relative;
}
.ff-search i {
    position: absolute; left: 11px; top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted); font-size: 12px; pointer-events: none;
}
.ff-search input { width: 100%; padding-left: 32px; }

/* Modal */
.overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(28,32,56,.48);
    backdrop-filter: blur(4px);
    z-index: 999;
    align-items: center; justify-content: center;
}
.overlay.show { display: flex; }

.modal {
    background: var(--white);
    border-radius: 16px;
    width: 580px; max-width: 96vw;
    max-height: 88vh; overflow-y: auto;
    box-shadow: var(--shadow-lg);
    animation: mIn .2s ease;
}
@keyframes mIn { from{transform:translateY(14px);opacity:0} to{transform:none;opacity:1} }

.modal-head {
    padding: 16px 20px;
    border-bottom: 1.5px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top: 0; background: var(--white); z-index: 2;
}
.modal-head h3 { font-size: 15px; font-weight: 900; display: flex; align-items: center; gap: 8px; }
.modal-head h3 i { color: var(--primary); }

.modal-close {
    width: 30px; height: 30px;
    background: var(--bg); border: 1.5px solid var(--border);
    border-radius: 7px; font-size: 13px; color: var(--text-muted);
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: all .14s;
}
.modal-close:hover { background: var(--red-lt); border-color: var(--red); color: var(--red); }

.modal-body { padding: 18px 20px 22px; }

/* Order detail grid */
.od-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-bottom: 16px;
}
.od-item {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 9px 12px;
}
.od-lbl { font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; color: var(--text-muted); margin-bottom: 3px; }
.od-val { font-size: 13px; font-weight: 800; color: var(--text); }

/* Items table inside modal */
.items-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.items-tbl thead tr { border-bottom: 1.5px solid var(--border); }
.items-tbl th { padding: 8px 10px; font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); background: var(--bg); text-align: left; }
.items-tbl th:nth-child(2), .items-tbl td:nth-child(2) { text-align: center; }
.items-tbl th:nth-child(3), .items-tbl td:nth-child(3),
.items-tbl th:nth-child(4), .items-tbl td:nth-child(4) { text-align: right; }
.items-tbl tbody tr { border-bottom: 1px solid var(--border); }
.items-tbl tbody tr:last-child { border-bottom: none; }
.items-tbl td { padding: 9px 10px; font-weight: 700; color: var(--text-mid); }

.totals-block { margin-top: 14px; border-top: 1.5px solid var(--border); padding-top: 12px; }
.tot-row { display: flex; justify-content: space-between; align-items: center; padding: 4px 0; font-size: 13px; font-weight: 700; }
.tot-row.grand { font-size: 16px; font-weight: 900; color: var(--primary); border-top: 1.5px solid var(--border); margin-top: 6px; padding-top: 8px; }

/* Print bill button */
.btn-bill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px;
    background: var(--primary); color: #fff;
    border: none; border-radius: var(--radius-sm);
    font-size: 12px; font-weight: 800;
    font-family: 'Nunito', sans-serif;
    cursor: pointer; text-decoration: none;
    transition: all .15s;
}
.btn-bill:hover { background: var(--primary-dk); transform: translateY(-1px); }
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
            <h2 class="page-title-h"><i class="fa-solid fa-list-check"></i> All Orders</h2>
            <p class="page-sub">Browse, filter and view every transaction</p>
        </div>
        <a href="../pos.php" class="btn-primary no-print">
            <i class="fa-solid fa-cash-register"></i> Go to POS
        </a>
    </div>

    <!-- ══ SUMMARY CARDS ══ -->
    <div class="summary-grid">
        <div class="sum-card sc-indigo">
            <div class="sc-icon" style="background:var(--indigo-lt);color:var(--indigo);">
                <i class="fa-solid fa-receipt"></i>
            </div>
            <div class="sc-val" style="color:var(--indigo);"><?php echo number_format($sum_q['cnt']); ?></div>
            <div class="sc-lbl">Filtered Orders</div>
        </div>
        <div class="sum-card sc-primary">
            <div class="sc-icon" style="background:var(--primary-lt);color:var(--primary);">
                <i class="fa-solid fa-sack-dollar"></i>
            </div>
            <div class="sc-val" style="color:var(--primary);">Rs. <?php echo number_format($sum_q['total'], 2); ?></div>
            <div class="sc-lbl">Filtered Revenue</div>
        </div>
        <div class="sum-card sc-green">
            <div class="sc-icon" style="background:var(--green-lt);color:var(--green);">
                <i class="fa-solid fa-chart-simple"></i>
            </div>
            <div class="sc-val" style="color:var(--green);">
                Rs. <?php echo $sum_q['cnt'] > 0 ? number_format($sum_q['total'] / $sum_q['cnt'], 2) : '0.00'; ?>
            </div>
            <div class="sc-lbl">Avg Order Value</div>
        </div>
    </div>

    <!-- ══ FILTER BAR ══ -->
    <div class="filter-card no-print">
        <form method="GET" class="filter-form">

            <div class="ff-group ff-search">
                <label>Search</label>
                <div style="position:relative;">
                    <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:12px;pointer-events:none;"></i>
                    <input type="text" name="search"
                           style="padding-left:32px;width:100%;background:var(--bg);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding-top:9px;padding-bottom:9px;font-size:13px;font-family:'Nunito',sans-serif;font-weight:700;color:var(--text);outline:none;transition:border-color .15s;"
                           placeholder="Order ID, cashier name…"
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>

            <div class="ff-group">
                <label>Order Type</label>
                <select name="order_type">
                    <option value="">All Types</option>
                    <option value="retail" <?php echo $filter_type=='retail'?'selected':''; ?>>Retail</option>
                    <option value="wholesale" <?php echo $filter_type=='wholesale'?'selected':''; ?>>Wholesale</option>
                </select>
            </div>

            <div class="ff-group">
                <label>Payment</label>
                <select name="payment_method">
                    <option value="">All Payments</option>
                    <option value="Cash"          <?php echo $filter_pm=='Cash'?'selected':''; ?>>Cash</option>
                    <option value="Card"          <?php echo $filter_pm=='Card'?'selected':''; ?>>Card</option>
                    <option value="QR"            <?php echo $filter_pm=='QR'?'selected':''; ?>>QR</option>
                    <option value="Bank Transfer" <?php echo $filter_pm=='Bank Transfer'?'selected':''; ?>>Bank Transfer</option>
                    <option value="Cheque"        <?php echo $filter_pm=='Cheque'?'selected':''; ?>>Cheque</option>
                </select>
            </div>

            <div class="ff-group">
                <label>Date</label>
                <input type="date" name="date" value="<?php echo htmlspecialchars($filter_date); ?>">
            </div>

            <button type="submit" class="btn-primary" style="align-self:flex-end;">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <a href="orders.php" class="btn-secondary" style="align-self:flex-end;">
                <i class="fa-solid fa-rotate-left"></i> Reset
            </a>

        </form>
    </div>

    <!-- ══ ORDERS TABLE ══ -->
    <div class="card table-card-full">
        <div class="card-header">
            <h3>
                <i class="fa-solid fa-receipt"></i> Orders
                <span style="font-size:11px;font-weight:700;color:var(--text-muted);margin-left:6px;">
                    <?php echo number_format($total_rows); ?> records
                </span>
            </h3>
            <span class="count-badge">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
        </div>

        <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Date &amp; Time</th>
                        <th>Cashier</th>
                        <th>Type</th>
                        <th>Subtotal</th>
                        <th>Discount</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Cash Given</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($orders_q && $orders_q->num_rows > 0):
                        while ($o = $orders_q->fetch_assoc()):
                            $pm_c = $pm_map[$o['payment_method']] ?? ['b-green','fa-money-bill'];
                            $bal  = (float)$o['balance'];
                    ?>
                    <tr>
                        <td><strong style="color:var(--text);">#<?php echo $o['order_id']; ?></strong></td>

                        <td style="white-space:nowrap;">
                            <div style="font-weight:800;color:var(--text);"><?php echo date('d M Y', strtotime($o['created_at'])); ?></div>
                            <div style="font-size:11px;color:var(--text-muted);"><?php echo date('h:i A', strtotime($o['created_at'])); ?></div>
                        </td>

                        <td>
                            <div style="display:flex;align-items:center;gap:7px;">
                                <div style="width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:900;color:#fff;flex-shrink:0;">
                                    <?php echo strtoupper(substr($o['cashier'] ?? 'U', 0, 1)); ?>
                                </div>
                                <?php echo htmlspecialchars($o['cashier'] ?? 'N/A'); ?>
                            </div>
                        </td>

                        <td>
                            <?php if ($o['order_type'] === 'retail'): ?>
                                <span class="badge b-orange"><i class="fa-solid fa-basket-shopping"></i> Retail</span>
                            <?php else: ?>
                                <span class="badge b-amber"><i class="fa-solid fa-boxes-stacked"></i> Wholesale</span>
                            <?php endif; ?>
                        </td>

                        <td>Rs. <?php echo number_format($o['subtotal'], 2); ?></td>

                        <td>
                            <?php if ($o['discount'] > 0): ?>
                                <span class="badge b-red">- Rs.<?php echo number_format($o['discount'], 2); ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">—</span>
                            <?php endif; ?>
                        </td>

                        <td><strong style="color:var(--primary);">Rs. <?php echo number_format($o['total_amount'], 2); ?></strong></td>

                        <td>
                            <span class="badge <?php echo $pm_c[0]; ?>">
                                <i class="fa-solid <?php echo $pm_c[1]; ?>"></i>
                                <?php echo htmlspecialchars($o['payment_method']); ?>
                            </span>
                        </td>

                        <td>Rs. <?php echo number_format($o['cash_given'], 2); ?></td>

                        <td>
                            <?php if ($bal > 0): ?>
                                <span class="badge b-green">+Rs.<?php echo number_format($bal, 2); ?></span>
                            <?php elseif ($bal < 0): ?>
                                <span class="badge b-red">-Rs.<?php echo number_format(abs($bal), 2); ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">—</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (strtolower($o['payment_status'] ?? '') === 'paid'): ?>
                                <span class="badge b-green"><i class="fa-solid fa-check"></i> Paid</span>
                            <?php else: ?>
                                <span class="badge b-amber"><i class="fa-solid fa-clock"></i> Pending</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="action-btns">
                                <button class="btn-edit"
                                        onclick="viewOrder(<?php echo $o['order_id']; ?>, '<?php echo date('d M Y h:i A', strtotime($o['created_at'])); ?>')">
                                    <i class="fa-solid fa-eye"></i> View
                                </button>
                                <a href="../print_bill.php?order_id=<?php echo $o['order_id']; ?>"
                                   target="_blank" class="btn-bill">
                                    <i class="fa-solid fa-print"></i> Bill
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr>
                        <td colspan="12" class="empty-row">
                            <i class="fa-solid fa-receipt" style="font-size:24px;color:var(--border-dk);display:block;margin-bottom:8px;"></i>
                            No orders found matching your filters.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1):
            $qs = http_build_query(array_filter([
                'search'         => $search,
                'order_type'     => $filter_type,
                'payment_method' => $filter_pm,
                'date'           => $filter_date,
            ]));
            $qs = $qs ? '&' . $qs : '';
        ?>
        <div class="pagination no-print">
            <a href="?page=<?php echo max(1, $page-1) . $qs; ?>"
               class="pag-btn <?php echo $page==1?'disabled':''; ?>">
                <i class="fa-solid fa-chevron-left"></i>
            </a>

            <?php if ($page > 3): ?>
                <a href="?page=1<?php echo $qs; ?>" class="pag-btn">1</a>
                <?php if ($page > 4): ?><span style="color:var(--text-muted);padding:0 4px;">…</span><?php endif; ?>
            <?php endif; ?>

            <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?>
            <a href="?page=<?php echo $p . $qs; ?>"
               class="pag-btn <?php echo $p==$page?'active':''; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>

            <?php if ($page < $total_pages - 2): ?>
                <?php if ($page < $total_pages - 3): ?><span style="color:var(--text-muted);padding:0 4px;">…</span><?php endif; ?>
                <a href="?page=<?php echo $total_pages . $qs; ?>" class="pag-btn"><?php echo $total_pages; ?></a>
            <?php endif; ?>

            <a href="?page=<?php echo min($total_pages, $page+1) . $qs; ?>"
               class="pag-btn <?php echo $page==$total_pages?'disabled':''; ?>">
                <i class="fa-solid fa-chevron-right"></i>
            </a>

            <span style="font-size:12px;color:var(--text-muted);font-weight:700;margin-left:4px;">
                <?php echo number_format($total_rows); ?> total
            </span>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /content -->
</div><!-- /main -->

<!-- ══════════════════════════════════
     ORDER DETAIL MODAL
══════════════════════════════════ -->
<div class="overlay" id="orderOverlay">
    <div class="modal">
        <div class="modal-head">
            <h3><i class="fa-solid fa-receipt"></i> Order Details — <span id="modalTitle">…</span></h3>
            <button class="modal-close" onclick="closeModal()" type="button">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="modal-body" id="modalBody">
            <div style="text-align:center;padding:36px;color:var(--text-muted);">
                <i class="fa-solid fa-spinner fa-spin" style="font-size:24px;"></i>
                <p style="margin-top:10px;font-weight:700;">Loading order details…</p>
            </div>
        </div>
    </div>
</div>

<script>
/* ── View Order Modal ── */
function viewOrder(orderId, dateStr) {
    document.getElementById('modalTitle').textContent = '#' + String(orderId).padStart(5, '0') + ' · ' + dateStr;
    document.getElementById('modalBody').innerHTML =
        '<div style="text-align:center;padding:36px;color:var(--text-muted);">' +
        '<i class="fa-solid fa-spinner fa-spin" style="font-size:24px;"></i>' +
        '<p style="margin-top:10px;font-weight:700;">Loading…</p></div>';
    document.getElementById('orderOverlay').classList.add('show');

    fetch('order_items_ajax.php?order_id=' + orderId)
        .then(r => r.text())
        .then(html => { document.getElementById('modalBody').innerHTML = html; })
        .catch(() => {
            document.getElementById('modalBody').innerHTML =
                '<div style="text-align:center;padding:28px;color:var(--red);font-weight:700;">' +
                '<i class="fa-solid fa-triangle-exclamation" style="font-size:22px;display:block;margin-bottom:8px;"></i>' +
                'Failed to load order items.</div>';
        });
}

function closeModal() {
    document.getElementById('orderOverlay').classList.remove('show');
}

document.getElementById('orderOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>

</body>
</html>
