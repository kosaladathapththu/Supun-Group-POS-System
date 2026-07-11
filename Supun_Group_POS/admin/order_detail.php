<?php
session_start();
include '../db.php';
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] != "admin") { echo "Unauthorized"; exit; }

$id = (int)($_GET["id"] ?? 0);
$o  = $conn->query("SELECT o.*, u.full_name AS cashier FROM orders o LEFT JOIN users u ON o.user_id=u.user_id WHERE o.order_id=$id")->fetch_assoc();
if (!$o) { echo '<p style="color:red;padding:20px;">Order not found.</p>'; exit; }

$items = $conn->query("
    SELECT oi.*, COALESCE(p.product_name, oi.custom_item_name, 'Custom Item') AS item_name
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.product_id
    WHERE oi.order_id = $id
");

$pm_cls = ['Cash'=>'bp-cash','Card'=>'bp-card','QR'=>'bp-qr','Bank Transfer'=>'bp-bank'];
$pm_ico = ['Cash'=>'fa-money-bill-wave','Card'=>'fa-credit-card','QR'=>'fa-qrcode','Bank Transfer'=>'fa-building-columns'];
?>
<style>
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;}
.dg-item{background:#f2f4f8;border-radius:8px;padding:9px 12px;}
.dg-lbl{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#8e94b0;}
.dg-val{font-size:14px;font-weight:800;color:#1c2038;margin-top:2px;}
.items-table{width:100%;border-collapse:collapse;}
.items-table th{padding:8px 10px;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.07em;color:#8e94b0;background:#f2f4f8;text-align:left;border-bottom:1.5px solid #e0e3ef;}
.items-table td{padding:9px 10px;font-size:13px;font-weight:700;border-bottom:1px solid #e0e3ef;color:#454a66;}
.items-table tr:last-child td{border-bottom:none;}
.items-total{display:flex;justify-content:space-between;padding:12px 10px;font-size:15px;font-weight:900;color:#d95c2b;border-top:2px solid #e0e3ef;margin-top:4px;}
.badge-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:40px;font-size:11px;font-weight:900;}
.bp-cash{background:#f0fdf4;color:#16a34a;}.bp-card{background:#eef2ff;color:#4f46e5;}.bp-qr{background:#fffbeb;color:#d97706;}.bp-bank{background:#f0f9ff;color:#0284c7;}
.bp-dine{background:#fef3ed;color:#d95c2b;}.bp-take{background:#fffbeb;color:#d97706;}
</style>

<div class="detail-grid">
  <div class="dg-item">
    <div class="dg-lbl">Order ID</div>
    <div class="dg-val">#<?php echo $o['order_id']; ?></div>
  </div>
  <div class="dg-item">
    <div class="dg-lbl">Date &amp; Time</div>
    <div class="dg-val" style="font-size:12px;"><?php echo date('d M Y, h:i A', strtotime($o['created_at'])); ?></div>
  </div>
  <div class="dg-item">
    <div class="dg-lbl">Cashier</div>
    <div class="dg-val"><?php echo htmlspecialchars($o['cashier'] ?? '—'); ?></div>
  </div>
  <div class="dg-item">
    <div class="dg-lbl">Order Type</div>
    <div class="dg-val">
      <?php if ($o['order_type']=='retail'): ?>
        <span class="badge-pill bp-dine"><i class="fa-solid fa-basket-shopping"></i> Retail</span>
      <?php else: ?>
        <span class="badge-pill bp-take"><i class="fa-solid fa-boxes-stacked"></i> Wholesale</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="dg-item">
    <div class="dg-lbl">Payment Method</div>
    <div class="dg-val">
      <span class="badge-pill <?php echo $pm_cls[$o['payment_method']] ?? 'bp-cash'; ?>">
        <i class="fa-solid <?php echo $pm_ico[$o['payment_method']] ?? 'fa-money-bill'; ?>"></i>
        <?php echo htmlspecialchars($o['payment_method']); ?>
      </span>
    </div>
  </div>
  <div class="dg-item">
    <div class="dg-lbl">Cash Given / Balance</div>
    <div class="dg-val">Rs. <?php echo number_format($o['cash_given'],2); ?> / Rs. <?php echo number_format($o['balance'],2); ?></div>
  </div>
</div>

<div style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.09em;color:#8e94b0;margin-bottom:8px;">
  <i class="fa-solid fa-list"></i> Items Ordered
</div>

<table class="items-table">
  <thead><tr><th>Item</th><th>Qty</th><th>Unit Price</th><th>Line Total</th></tr></thead>
  <tbody>
    <?php if ($items && $items->num_rows > 0):
      while ($item = $items->fetch_assoc()): ?>
    <tr>
      <td>
        <?php echo htmlspecialchars($item['item_name']); ?>
        <?php if (!$item['product_id']): ?>
          <span style="font-size:10px;background:#fffbeb;color:#d97706;border:1px solid #fde68a;padding:1px 6px;border-radius:40px;margin-left:4px;font-weight:900;">Custom</span>
        <?php endif; ?>
      </td>
      <td><?php echo $item['quantity']; ?></td>
      <td>Rs. <?php echo number_format($item['unit_price'],2); ?></td>
      <td><strong style="color:#d95c2b;">Rs. <?php echo number_format($item['line_total'],2); ?></strong></td>
    </tr>
    <?php endwhile; else: ?>
    <tr><td colspan="4" style="text-align:center;color:#8e94b0;padding:16px;font-weight:700;">No items found.</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<div class="items-total">
  <span>Subtotal</span><span>Rs. <?php echo number_format($o['subtotal'],2); ?></span>
</div>
<?php if ($o['discount'] > 0): ?>
<div class="items-total" style="color:var(--green);border-top:none;padding-top:0;">
  <span>Discount</span><span>- Rs. <?php echo number_format($o['discount'],2); ?></span>
</div>
<?php endif; ?>
<?php
$packaging_fee = (float)($o['packaging_fee'] ?? 0);
$saved_service_charge = (float)($o['service_charge'] ?? 0);
$service_charge = $saved_service_charge > 0
  ? $saved_service_charge
  : max(0, (float)$o['total_amount'] - (float)$o['subtotal'] + (float)$o['discount'] - $packaging_fee);
?>
<?php if ($service_charge > 0): ?>
<div class="items-total" style="color:var(--primary);border-top:none;padding-top:0;">
  <span>Service Charge 10%</span><span>Rs. <?php echo number_format($service_charge,2); ?></span>
</div>
<?php endif; ?>
<?php if ($packaging_fee > 0): ?>
<div class="items-total" style="color:var(--primary);border-top:none;padding-top:0;">
  <span>Packaging Fee</span><span>Rs. <?php echo number_format($packaging_fee,2); ?></span>
</div>
<?php endif; ?>
<div class="items-total" style="font-size:17px;">
  <span>Grand Total</span><span>Rs. <?php echo number_format($o['total_amount'],2); ?></span>
</div>
