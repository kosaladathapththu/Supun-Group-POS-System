<?php
include "../includes/auth.php";
include "../db.php";
header("Location: product_import.php");
exit();
include "purchase_helpers.php";
include "quick_supplier.php";
$msg = "";
$msgType = "";
$quickSupplier = handleQuickSupplier($conn);
$newSupplierId = (int) $quickSupplier["id"];
if ($quickSupplier["handled"]) {
    $msg = $quickSupplier["message"];
    $msgType = $quickSupplier["type"];
}

if (isset($_POST["create_purchase"])) {
    $supplierId = (int) ($_POST["supplier_id"] ?? 0);
    $invoice = trim($_POST["supplier_invoice"] ?? "");
    $date = $_POST["purchase_date"] ?? date("Y-m-d");
    $discount = max(0, (float) ($_POST["discount"] ?? 0));
    $tax = max(0, (float) ($_POST["tax"] ?? 0));
    $notes = trim($_POST["notes"] ?? "");
    $products = $_POST["product_id"] ?? [];
    $qtys = $_POST["quantity"] ?? [];
    $costs = $_POST["unit_cost"] ?? [];
    $lines = [];
    $subtotal = 0;
    foreach ($products as $i => $pid) {
        $pid = (int) $pid;
        $qty = max(0, (float) ($qtys[$i] ?? 0));
        $cost = max(0, (float) ($costs[$i] ?? 0));
        if ($pid > 0 && $qty > 0 && $cost >= 0) {
            $line = $qty * $cost;
            $lines[$pid] = isset($lines[$pid])
                ? [
                    "quantity" => $lines[$pid]["quantity"] + $qty,
                    "unit_cost" => $cost,
                    "line_total" => ($lines[$pid]["quantity"] + $qty) * $cost,
                ]
                : [
                    "quantity" => $qty,
                    "unit_cost" => $cost,
                    "line_total" => $line,
                ];
        }
    }
    foreach ($lines as $line) {
        $subtotal += $line["line_total"];
    }
    $total = max(0, $subtotal - $discount + $tax);
    if ($supplierId <= 0 || !$lines) {
        $msg = "Select a supplier and add at least one valid product.";
        $msgType = "error";
    } else {
        $conn->begin_transaction();
        try {
            $uid = (int) $_SESSION["user_id"];
            $s = $conn->prepare(
                "INSERT INTO purchases (supplier_id,supplier_invoice,purchase_date,subtotal,discount,tax,total_amount,notes,created_by) VALUES (?,NULLIF(?,''),?,?,?,?,?,?,?)",
            );
            $s->bind_param(
                "issddddsi",
                $supplierId,
                $invoice,
                $date,
                $subtotal,
                $discount,
                $tax,
                $total,
                $notes,
                $uid,
            );
            $s->execute();
            $purchaseId = $conn->insert_id;
            $s->close();
            $number = purchaseNumber($purchaseId);
            $u = $conn->prepare(
                "UPDATE purchases SET purchase_number=? WHERE purchase_id=?",
            );
            $u->bind_param("si", $number, $purchaseId);
            $u->execute();
            $u->close();
            $item = $conn->prepare(
                "INSERT INTO purchase_items (purchase_id,product_id,quantity,unit_cost,line_total) VALUES (?,?,?,?,?)",
            );
            foreach ($lines as $pid => $line) {
                $q = $line["quantity"];
                $c = $line["unit_cost"];
                $lt = $line["line_total"];
                $item->bind_param("iiddd", $purchaseId, $pid, $q, $c, $lt);
                $item->execute();
            }
            $item->close();
            $conn->commit();
            if (($_POST["create_purchase"] ?? "") === "receive") {
                receivePurchase($conn, $purchaseId, $uid);
            }
            header(
                "Location: purchase_view.php?id=" . $purchaseId . "&created=1",
            );
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            $msg = $e->getMessage();
            $msgType = "error";
        }
    }
}

$supplierFilter = (int) ($_GET["supplier"] ?? 0);
$statusFilter = $_GET["status"] ?? "";
$allowed = ["draft", "received", "cancelled"];
$where = ["1=1"];
$params = [];
$types = "";
if ($supplierFilter) {
    $where[] = "p.supplier_id=?";
    $params[] = $supplierFilter;
    $types .= "i";
}
if (in_array($statusFilter, $allowed, true)) {
    $where[] = "p.status=?";
    $params[] = $statusFilter;
    $types .= "s";
}
$sql =
    "SELECT p.*,s.supplier_name,(p.total_amount-p.paid_amount) balance,(SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_id=p.purchase_id) item_count FROM purchases p JOIN suppliers s ON s.supplier_id=p.supplier_id WHERE " .
    implode(" AND ", $where) .
    " ORDER BY p.purchase_id DESC";
$listStmt = $conn->prepare($sql);
if ($params) {
    $listStmt->bind_param($types, ...$params);
}
$listStmt->execute();
$purchases = $listStmt->get_result();
$suppliers = $conn->query(
    "SELECT supplier_id,supplier_name FROM suppliers WHERE status=1 ORDER BY supplier_name",
);
$supplierOptions = [];
while ($s = $suppliers->fetch_assoc()) {
    $supplierOptions[] = $s;
}
$products = $conn->query(
    "SELECT product_id,product_name,sku,cost_price,stock_qty,unit FROM products WHERE status=1 ORDER BY product_name",
);
$productOptions = [];
while ($p = $products->fetch_assoc()) {
    $productOptions[] = $p;
}
$stats = $conn
    ->query(
        "SELECT COUNT(*) total,COALESCE(SUM(status='draft'),0) drafts,COALESCE(SUM(status='received'),0) received,COALESCE(SUM(CASE WHEN status<>'cancelled' THEN total_amount-paid_amount ELSE 0 END),0) payable FROM purchases",
    )
    ->fetch_assoc();
$showForm = isset($_GET["new"]) || isset($_POST["create_purchase"]);
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Purchases - ST Pvt Ltd.</title><link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Lora:wght@600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"><style><?php
include "shared_style.php";
include "erp_style.php";
?>.purchase-form{margin-bottom:18px}.purchase-head{padding:18px;display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.purchase-table-area{padding:0 18px 5px}.purchase-footer{display:grid;grid-template-columns:1fr 390px;gap:18px;padding:5px 18px 18px}.purchase-footer textarea{min-height:115px}@media(max-width:900px){.purchase-head{grid-template-columns:1fr 1fr}.purchase-footer{grid-template-columns:1fr}}@media(max-width:550px){.purchase-head{grid-template-columns:1fr}}</style></head><body>
<?php include "shared_nav.php"; ?><main class="main"><?php include "shared_topbar.php"; ?><div class="content"><div class="page-header"><div><h1 class="page-title-h"><i class="fa-solid fa-cart-flatbed"></i> Purchasing</h1><p class="page-sub">Bulk purchases, stock receiving and supplier balances</p></div><div class="quick-links"><a class="btn-secondary" href="suppliers.php"><i class="fa-solid fa-truck-field"></i> Suppliers</a><a class="btn-secondary" href="purchase_import.php"><i class="fa-solid fa-file-excel"></i> Import Sheet</a><a class="btn-primary" href="purchases.php?new=1"><i class="fa-solid fa-plus"></i> New Bulk Purchase</a></div></div>
<?php if (
    $msg
): ?><div class="alert <?php echo $msgType; ?>"><?php echo htmlspecialchars(
    $msg,
); ?></div><?php endif; ?>
<?php renderQuickSupplierForm(); ?>
<?php if (
    $newSupplierId
): ?><script>document.addEventListener('DOMContentLoaded',()=>{const s=document.querySelector('select[name="supplier_id"]');if(s)s.value='<?php echo $newSupplierId; ?>';});</script><?php endif; ?>
<div class="erp-stats"><div class="erp-stat"><span>Total Purchases</span><strong><?php echo (int) $stats[
    "total"
]; ?></strong></div><div class="erp-stat amber"><span>Draft / Awaiting Stock</span><strong><?php echo (int) $stats[
    "drafts"
]; ?></strong></div><div class="erp-stat green"><span>Received</span><strong><?php echo (int) $stats[
    "received"
]; ?></strong></div><div class="erp-stat amber"><span>Supplier Payable</span><strong>Rs. <?php echo number_format(
    (float) $stats["payable"],
    2,
); ?></strong></div></div>
<?php if (
    $showForm
): ?><form method="post" class="card purchase-form" id="purchaseForm"><div class="card-head"><h4><i class="fa-solid fa-file-circle-plus"></i> New Bulk Purchase</h4><a class="btn-small" href="purchases.php"><i class="fa-solid fa-xmark"></i> Close</a></div><div class="purchase-head"><div class="field"><label>Supplier *</label><select class="inp" name="supplier_id" required><option value="">Select supplier</option><?php foreach (
    $supplierOptions
    as $s
): ?><option value="<?php echo (int) $s[
    "supplier_id"
]; ?>"><?php echo htmlspecialchars(
    $s["supplier_name"],
); ?></option><?php endforeach; ?></select></div><div class="field"><label>Supplier Invoice</label><input class="inp" name="supplier_invoice" placeholder="INV-1001"></div><div class="field"><label>Purchase Date *</label><input class="inp" type="date" name="purchase_date" required value="<?php echo date(
    "Y-m-d",
); ?>"></div><div class="field"><label>Entry Mode</label><input class="inp" value="Manual bulk entry" disabled></div></div>
<div class="purchase-table-area tbl-wrap"><table class="purchase-lines"><thead><tr><th style="width:55%">Product</th><th>Current Stock</th><th>Quantity</th><th>Unit Cost</th><th>Line Total</th><th></th></tr></thead><tbody id="lineBody"></tbody></table><button class="btn-secondary" type="button" onclick="addLine()" style="margin:10px 0"><i class="fa-solid fa-plus"></i> Add Product Line</button></div>
<div class="purchase-footer"><div class="field"><label>Purchase Notes</label><textarea class="inp" name="notes" placeholder="Delivery details, credit terms or internal reference"></textarea></div><div class="totals-box"><div class="total-row-flex"><span>Subtotal</span><strong id="subtotalText">Rs. 0.00</strong></div><div class="total-row-flex"><label>Purchase Discount</label><input class="inp" style="width:150px" type="number" name="discount" id="discount" min="0" step="0.01" value="0" oninput="recalc()"></div><div class="total-row-flex"><label>Tax / Other Charge</label><input class="inp" style="width:150px" type="number" name="tax" id="tax" min="0" step="0.01" value="0" oninput="recalc()"></div><div class="total-row-flex grand"><span>Total</span><strong id="grandText">Rs. 0.00</strong></div><div class="actions"><button class="btn-secondary" name="create_purchase" value="draft"><i class="fa-solid fa-floppy-disk"></i> Save Draft</button><button class="btn-primary" name="create_purchase" value="receive" onclick="return confirm('Receive this purchase and increase stock now?')"><i class="fa-solid fa-box-open"></i> Save & Receive Stock</button></div></div></div></form><?php endif; ?>
<section class="card table-card-full"><div class="card-head"><h4><i class="fa-solid fa-clock-rotate-left"></i> Purchase Register</h4><span class="count-badge"><?php echo (int) $stats[
    "total"
]; ?> records</span></div><form class="filter-row" method="get"><div class="field"><label>Supplier</label><select class="inp" name="supplier"><option value="">All suppliers</option><?php foreach (
     $supplierOptions
     as $s
 ): ?><option value="<?php echo (int) $s[
    "supplier_id"
]; ?>" <?php echo $supplierFilter === $s["supplier_id"]
    ? "selected"
    : ""; ?>><?php echo htmlspecialchars(
    $s["supplier_name"],
); ?></option><?php endforeach; ?></select></div><div class="field"><label>Status</label><select class="inp" name="status"><option value="">All statuses</option><option value="draft" <?php echo $statusFilter ===
"draft"
    ? "selected"
    : ""; ?>>Draft</option><option value="received" <?php echo $statusFilter ===
"received"
    ? "selected"
    : ""; ?>>Received</option><option value="cancelled" <?php echo $statusFilter ===
"cancelled"
    ? "selected"
    : ""; ?>>Cancelled</option></select></div><button class="btn-primary"><i class="fa-solid fa-filter"></i> Filter</button><a class="btn-secondary" href="purchases.php">Reset</a></form><div class="tbl-wrap"><table><thead><tr><th>Purchase</th><th>Date</th><th>Supplier</th><th>Items</th><th>Total</th><th>Paid</th><th>Balance</th><th>Stock</th><th>Payment</th><th></th></tr></thead><tbody><?php
if (
    !$purchases->num_rows
): ?><tr><td colspan="10" class="empty-row">No purchases found.</td></tr><?php endif;
while (
    $p = $purchases->fetch_assoc()
): ?><tr><td><strong><?php echo htmlspecialchars(
    $p["purchase_number"],
); ?></strong><div class="muted"><?php echo htmlspecialchars(
    $p["supplier_invoice"] ?: "No supplier invoice",
); ?></div></td><td><?php echo date(
    "d M Y",
    strtotime($p["purchase_date"]),
); ?></td><td><?php echo htmlspecialchars(
    $p["supplier_name"],
); ?></td><td><?php echo (int) $p[
    "item_count"
]; ?></td><td class="money">Rs. <?php echo number_format(
    (float) $p["total_amount"],
    2,
); ?></td><td class="money">Rs. <?php echo number_format(
    (float) $p["paid_amount"],
    2,
); ?></td><td class="money">Rs. <?php echo number_format(
    (float) $p["balance"],
    2,
); ?></td><td><span class="status-pill <?php echo htmlspecialchars(
    $p["status"],
); ?>"><?php echo htmlspecialchars(
    $p["status"],
); ?></span></td><td><span class="status-pill <?php echo htmlspecialchars(
    $p["payment_status"],
); ?>"><?php echo htmlspecialchars(
    $p["payment_status"],
); ?></span></td><td><a class="btn-small" href="purchase_view.php?id=<?php echo (int) $p[
    "purchase_id"
]; ?>"><i class="fa-solid fa-eye"></i> View</a></td></tr><?php endwhile;
?></tbody></table></div></section>
</div></main>
<template id="lineTemplate"><tr><td><select name="product_id[]" required onchange="productChanged(this)"><option value="">Select product / SKU</option><?php foreach (
    $productOptions
    as $p
): ?><option value="<?php echo (int) $p[
    "product_id"
]; ?>" data-cost="<?php echo (float) $p[
    "cost_price"
]; ?>" data-stock="<?php echo (float) $p[
    "stock_qty"
]; ?>" data-unit="<?php echo htmlspecialchars(
    $p["unit"],
); ?>"><?php echo htmlspecialchars(
    $p["product_name"] . " - " . ($p["sku"] ?: "No SKU"),
); ?></option><?php endforeach; ?></select></td><td class="stock-cell muted">-</td><td><input class="qty" type="number" name="quantity[]" min="0.001" step="0.001" value="1" required oninput="recalc()"></td><td><input class="cost" type="number" name="unit_cost[]" min="0" step="0.01" value="0" required oninput="recalc()"></td><td class="line-total">Rs. 0.00</td><td><button class="remove-line" type="button" onclick="this.closest('tr').remove();recalc()"><i class="fa-solid fa-trash"></i></button></td></tr></template>
<script>const fmt=n=>'Rs. '+Number(n||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});function addLine(){document.getElementById('lineBody').append(document.getElementById('lineTemplate').content.cloneNode(true));recalc()}function productChanged(s){const o=s.options[s.selectedIndex],r=s.closest('tr');r.querySelector('.cost').value=o.dataset.cost||0;r.querySelector('.stock-cell').textContent=o.value?`${Number(o.dataset.stock).toLocaleString()} ${o.dataset.unit}`:'-';recalc()}function recalc(){let sub=0;document.querySelectorAll('#lineBody tr').forEach(r=>{const q=parseFloat(r.querySelector('.qty').value)||0,c=parseFloat(r.querySelector('.cost').value)||0,t=q*c;sub+=t;r.querySelector('.line-total').textContent=fmt(t)});const d=parseFloat(document.getElementById('discount')?.value)||0,t=parseFloat(document.getElementById('tax')?.value)||0;document.getElementById('subtotalText').textContent=fmt(sub);document.getElementById('grandText').textContent=fmt(Math.max(0,sub-d+t))}<?php if (
    $showForm
): ?>addLine();<?php endif; ?></script></body></html>
