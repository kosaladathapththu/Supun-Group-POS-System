<?php
include '../includes/auth.php';
include '../db.php';

$order_id = (int)($_GET['order_id'] ?? 0);
$stmt = $conn->prepare("SELECT o.*, u.full_name FROM orders o LEFT JOIN users u ON o.user_id=u.user_id WHERE o.order_id=? LIMIT 1");
$stmt->bind_param('i', $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) { http_response_code(404); die('Order not found'); }

$stmt = $conn->prepare("SELECT oi.*, COALESCE(p.product_name,oi.custom_item_name,'Custom Item') AS product_name FROM order_items oi LEFT JOIN products p ON oi.product_id=p.product_id WHERE oi.order_id=? ORDER BY oi.order_item_id");
$stmt->bind_param('i', $order_id);
$stmt->execute();
$items = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Sale Details</title>
<link href="https://fonts.googleapis.com/css2?family=Lora:wght@600;700&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
<?php include 'shared_style.php'; ?>
.sale-container{max-width:1200px;margin:0 auto}.sale-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.sale-meta{padding:13px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm)}.sale-meta label{display:block;font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);margin-bottom:4px}.sale-meta strong{font-size:14px;color:var(--text)}.sale-total{color:var(--primary)!important;font-family:'Lora',serif;font-size:18px!important}.items-card{margin-top:16px;overflow:hidden}.num{text-align:right;white-space:nowrap}.custom-chip{font-size:9px;background:var(--amber-lt);color:var(--amber);border:1px solid #fde68a;padding:2px 6px;border-radius:20px;font-weight:900;margin-left:4px}@media(max-width:700px){.sale-summary{grid-template-columns:1fr 1fr}}@media(max-width:480px){.sale-summary{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php include 'shared_nav.php'; ?>
<div class="main">
<?php include 'shared_topbar.php'; ?>
<div class="content"><div class="sale-container">
<div class="page-header">
  <div><h1 class="page-title-h"><i class="fa-solid fa-receipt"></i> Sale #<?php echo $order_id; ?></h1><p class="page-sub">Complete transaction information and purchased items</p></div>
  <div style="display:flex;gap:8px"><a href="sales.php" class="btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a><a href="../print_bill.php?order_id=<?php echo $order_id; ?>" class="btn-primary"><i class="fa-solid fa-print"></i> Invoice</a></div>
</div>
<section class="card">
 <div class="card-header"><h3><i class="fa-solid fa-circle-info"></i> Sale Summary</h3><span class="badge b-green"><?php echo strtoupper(htmlspecialchars($order['payment_status'])); ?></span></div>
 <div class="card-body sale-summary">
  <div class="sale-meta"><label>Sale Type</label><strong><?php echo ucfirst(htmlspecialchars($order['order_type'])); ?></strong></div>
  <div class="sale-meta"><label>Customer</label><strong><?php echo htmlspecialchars($order['customer_name'] ?: 'Walk-in Customer'); ?></strong></div>
  <div class="sale-meta"><label>Cashier</label><strong><?php echo htmlspecialchars($order['full_name'] ?: 'Unknown'); ?></strong></div>
  <div class="sale-meta"><label>Payment</label><strong><?php echo htmlspecialchars($order['payment_method']); ?></strong></div>
  <div class="sale-meta"><label>Date</label><strong><?php echo date('d M Y, h:i A',strtotime($order['created_at'])); ?></strong></div>
  <div class="sale-meta"><label>Total Amount</label><strong class="sale-total">Rs. <?php echo number_format($order['total_amount'],2); ?></strong></div>
 </div>
</section>
<section class="card items-card">
 <div class="card-header"><h3><i class="fa-solid fa-box-open"></i> Purchased Items</h3><span class="count-badge"><?php echo $items->num_rows; ?> lines</span></div>
 <div class="tbl-wrap"><table><thead><tr><th>Product</th><th class="num">Quantity</th><th class="num">Unit Price</th><th class="num">Line Total</th></tr></thead><tbody>
 <?php while($item=$items->fetch_assoc()): ?><tr><td><?php echo htmlspecialchars($item['product_name']); ?><?php if(!$item['product_id']): ?><span class="custom-chip">Custom</span><?php endif; ?></td><td class="num"><?php echo htmlspecialchars($item['quantity']); ?></td><td class="num">Rs. <?php echo number_format($item['unit_price'],2); ?></td><td class="num"><strong>Rs. <?php echo number_format($item['line_total'],2); ?></strong></td></tr><?php endwhile; ?>
 </tbody></table></div>
</section>
</div></div></div>
</body>
</html>
