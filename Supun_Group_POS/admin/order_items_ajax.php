<?php
session_start();
include '../db.php';

if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["role"], ["admin", "accountant"], true)) {
    http_response_code(403); echo "Unauthorized"; exit;
}

$id = (int)($_GET["order_id"] ?? 0);
if ($id <= 0) { echo '<p style="color:red;padding:16px;">Invalid order ID.</p>'; exit; }

$o = $conn->query("
    SELECT o.*, u.full_name AS cashier, t.table_name
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.user_id
    LEFT JOIN restaurant_tables t ON o.table_id = t.table_id
    WHERE o.order_id = $id
")->fetch_assoc();

if (!$o) { echo '<p style="color:red;padding:16px;">Order not found.</p>'; exit; }

$items = $conn->query("
    SELECT oi.*,
           COALESCE(p.product_name, oi.custom_item_name, 'Item') AS item_name
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.product_id
    WHERE oi.order_id = $id
    ORDER BY oi.order_item_id ASC
");

$pm_map = [
    'Cash'          => ['b-green',  'fa-money-bill-wave'],
    'Card'          => ['b-indigo', 'fa-credit-card'],
    'QR'            => ['b-amber',  'fa-qrcode'],
    'Bank Transfer' => ['b-sky',    'fa-building-columns'],
    'Cheque'        => ['b-amber',  'fa-money-check-dollar'],
];
$pm_c      = $pm_map[$o['payment_method']] ?? ['b-green', 'fa-money-bill'];
$bal       = (float)$o['balance'];
$has_table = !empty($o['table_name']) && $o['table_name'] !== 'N/A';

$all_items  = [];
$item_count = 0;
$qty_total  = 0;
while ($row = $items->fetch_assoc()) {
    $all_items[] = $row;
    $item_count++;
    $qty_total += (int)$row['quantity'];
}
?>
<style>
.od-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;}
.od-item{background:#f1f3f8;border:1px solid #e0e3ef;border-radius:8px;padding:9px 12px;}
.od-lbl{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#8e94b0;margin-bottom:3px;}
.od-val{font-size:13px;font-weight:800;color:#1c2038;}
.sec-lbl{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:#8e94b0;margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.itbl{width:100%;border-collapse:collapse;font-size:13px;}
.itbl thead tr{border-bottom:2px solid #e0e3ef;}
.itbl th{padding:8px 10px;font-size:9.5px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#8e94b0;background:#f1f3f8;text-align:left;}
.itbl th:nth-child(2),.itbl td:nth-child(2){text-align:center;width:42px;}
.itbl th:nth-child(3),.itbl td:nth-child(3){text-align:right;width:80px;}
.itbl th:nth-child(4),.itbl td:nth-child(4){text-align:right;width:86px;}
.itbl tbody tr{border-bottom:1px solid #e0e3ef;}
.itbl tbody tr:last-child{border-bottom:none;}
.itbl tbody tr:hover td{background:#fafbfd;}
.itbl td{padding:10px 10px;font-weight:700;color:#454a66;}
.iname{font-family:'Nunito',sans-serif;font-size:13px;font-weight:800;color:#1c2038;}
.cust-chip{font-size:9px;background:#fffbeb;color:#d97706;border:1px solid #fde68a;padding:1px 5px;border-radius:40px;margin-left:4px;font-weight:900;}
.tot-block{margin-top:14px;border-top:1.5px solid #e0e3ef;padding-top:12px;}
.tot-row{display:flex;justify-content:space-between;align-items:center;padding:3.5px 0;font-size:13px;font-weight:700;color:#454a66;}
.tot-row.t-muted{color:#8e94b0;font-size:12px;}
.tot-row.t-disc{color:#d97706;}
.tot-row.t-grand{font-size:16px;font-weight:900;color:#d95c2b;border-top:1.5px solid #e0e3ef;margin-top:8px;padding-top:9px;}
.tot-row.t-cash{color:#16a34a;font-weight:800;}
.tot-row.t-change{border-top:1px dashed #e0e3ef;padding-top:6px;margin-top:2px;font-weight:800;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:40px;font-size:11px;font-weight:900;white-space:nowrap;border:1px solid transparent;}
.b-green{background:#f0fdf4;color:#16a34a;border-color:#86efac;}
.b-indigo{background:#eef2ff;color:#4f46e5;border-color:#c7d2fe;}
.b-amber{background:#fffbeb;color:#d97706;border-color:#fde68a;}
.b-sky{background:#f0f9ff;color:#0284c7;border-color:#bae6fd;}
.b-orange{background:#fef3ed;color:#d95c2b;border-color:#f9c4a6;}
.b-red{background:#fef2f2;color:#dc2626;border-color:#fca5a5;}
.act-row{display:flex;gap:8px;margin-top:16px;padding-top:14px;border-top:1.5px solid #e0e3ef;}
.btn-bill{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;background:#d95c2b;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:800;font-family:'Nunito',sans-serif;cursor:pointer;text-decoration:none;box-shadow:0 2px 8px rgba(217,92,43,.28);transition:all .15s;}
.btn-bill:hover{background:#b84a1f;transform:translateY(-1px);}
.btn-cls{display:inline-flex;align-items:center;gap:7px;padding:10px 16px;background:#f1f3f8;color:#454a66;border:1.5px solid #e0e3ef;border-radius:8px;font-size:13px;font-weight:800;font-family:'Nunito',sans-serif;cursor:pointer;transition:all .15s;}
.btn-cls:hover{border-color:#c8ccd8;color:#1c2038;}
</style>

<!-- Meta -->
<div class="od-grid">
  <div class="od-item"><div class="od-lbl">Order ID</div><div class="od-val">#<?php printf('%05d',$o['order_id']); ?></div></div>
  <div class="od-item"><div class="od-lbl">Date &amp; Time</div><div class="od-val" style="font-size:12px;"><?php echo date('d M Y, h:i A', strtotime($o['created_at'])); ?></div></div>
  <div class="od-item"><div class="od-lbl">Cashier</div><div class="od-val"><?php echo htmlspecialchars($o['cashier'] ?? 'N/A'); ?></div></div>
  <div class="od-item">
    <div class="od-lbl">Order Type</div>
    <div class="od-val">
      <?php if ($o['order_type']==='retail'): ?>
        <span class="badge b-orange"><i class="fa-solid fa-basket-shopping"></i> Retail</span>
      <?php else: ?>
        <span class="badge b-amber"><i class="fa-solid fa-boxes-stacked"></i> Wholesale</span>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($has_table): ?>
  <div class="od-item"><div class="od-lbl">Table</div><div class="od-val"><?php echo htmlspecialchars($o['table_name']); ?></div></div>
  <?php endif; ?>
  <div class="od-item">
    <div class="od-lbl">Payment</div>
    <div class="od-val">
      <span class="badge <?php echo $pm_c[0]; ?>"><i class="fa-solid <?php echo $pm_c[1]; ?>"></i> <?php echo htmlspecialchars($o['payment_method']); ?></span>
    </div>
  </div>
  <div class="od-item">
    <div class="od-lbl">Status</div>
    <div class="od-val">
      <?php if (strtolower($o['payment_status'] ?? '')==='paid'): ?>
        <span class="badge b-green"><i class="fa-solid fa-check"></i> Paid</span>
      <?php else: ?>
        <span class="badge b-amber"><i class="fa-solid fa-clock"></i> Pending</span>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($o['payment_method']==='Cash'): ?>
  <div class="od-item"><div class="od-lbl">Cash Given</div><div class="od-val">Rs. <?php echo number_format($o['cash_given'],2); ?></div></div>
  <?php endif; ?>
</div>

<!-- Items -->
<div class="sec-lbl"><i class="fa-solid fa-list" style="color:#d95c2b;"></i> Items Ordered</div>
<table class="itbl">
  <thead><tr><th>Item</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
  <tbody>
  <?php if (!empty($all_items)):
    foreach ($all_items as $item): ?>
  <tr>
    <td>
      <span class="iname"><?php echo htmlspecialchars($item['item_name']); ?></span>
      <?php if (!$item['product_id']): ?><span class="cust-chip">Custom</span><?php endif; ?>
    </td>
    <td style="text-align:center;font-weight:800;"><?php echo (int)$item['quantity']; ?></td>
    <td style="text-align:right;">Rs. <?php echo number_format($item['price'],2); ?></td>
    <td style="text-align:right;font-weight:800;color:#d95c2b;">Rs. <?php echo number_format($item['line_total'],2); ?></td>
  </tr>
  <?php endforeach; else: ?>
  <tr><td colspan="4" style="text-align:center;padding:20px;color:#8e94b0;font-weight:700;">No items found.</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<!-- Totals -->
<div class="tot-block">
  <div class="tot-row t-muted"><span><?php echo $item_count; ?> line(s) &bull; <?php echo $qty_total; ?> pcs total</span></div>
  <div class="tot-row"><span style="color:#8e94b0;">Subtotal</span><span>Rs. <?php echo number_format($o['subtotal'],2); ?></span></div>
  <?php if ((float)$o['discount']>0): ?>
  <div class="tot-row t-disc"><span>Discount</span><span>- Rs. <?php echo number_format($o['discount'],2); ?></span></div>
  <?php endif; ?>
  <?php
  $packaging_fee = (float)($o['packaging_fee'] ?? 0);
  $saved_service_charge = (float)($o['service_charge'] ?? 0);
  $service_charge = $saved_service_charge > 0
    ? $saved_service_charge
    : max(0, (float)$o['total_amount'] - (float)$o['subtotal'] + (float)$o['discount'] - $packaging_fee);
  ?>
  <?php if ($service_charge > 0): ?>
  <div class="tot-row"><span style="color:#8e94b0;">Service Charge 10%</span><span>Rs. <?php echo number_format($service_charge,2); ?></span></div>
  <?php endif; ?>
  <?php if ($packaging_fee > 0): ?>
  <div class="tot-row"><span style="color:#8e94b0;">Packaging Fee</span><span>Rs. <?php echo number_format($packaging_fee,2); ?></span></div>
  <?php endif; ?>
  <div class="tot-row t-grand"><span>Grand Total</span><span>Rs. <?php echo number_format($o['total_amount'],2); ?></span></div>
  <?php if ($o['payment_method']==='Cash'): ?>
  <div class="tot-row t-cash"><span>Cash Received</span><span>Rs. <?php echo number_format($o['cash_given'],2); ?></span></div>
  <div class="tot-row t-change">
    <span><?php echo $bal>=0?'Change Returned':'Amount Due'; ?></span>
    <span style="color:<?php echo $bal>=0?'#16a34a':'#dc2626'; ?>;">Rs. <?php echo number_format(abs($bal),2); ?></span>
  </div>
  <?php else: ?>
  <div class="tot-row t-cash"><span>Amount Paid</span><span>Rs. <?php echo number_format($o['total_amount'],2); ?></span></div>
  <?php endif; ?>
</div>

<!-- Actions -->
<div class="act-row">
  <a href="../print_bill.php?order_id=<?php echo $o['order_id']; ?>" target="_blank" class="btn-bill">
    <i class="fa-solid fa-print"></i> Print Bill
  </a>
  <button type="button" class="btn-cls" onclick="closeModal()">
    <i class="fa-solid fa-xmark"></i> Close
  </button>
</div>
