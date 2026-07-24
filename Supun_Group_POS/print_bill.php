<?php
include "db.php";
require_once "includes/advance_accounts.php";
ensureAdvancePaymentSchema($conn);

$order_id = isset($_GET["order_id"]) ? (int) $_GET["order_id"] : 0;
$from_advance = ($_GET["from"] ?? "") === "advance";
$back_url = $from_advance ? "advance_payments.php" : "pos.php";
$back_label = $from_advance ? "Back to Advance Payments" : "Back to POS";
if ($order_id <= 0) {
    die("Invalid order ID.");
}

$order_stmt = $conn->prepare("
    SELECT o.*, t.table_name, u.full_name, c.account_number,
           (SELECT COALESCE(SUM(a.remaining_amount),0) FROM advance_payment_transactions a WHERE a.customer_id=o.customer_id AND a.transaction_type='deposit' AND a.order_id IS NULL AND a.remaining_amount>0) AS customer_advance_balance
    FROM orders o
    LEFT JOIN restaurant_tables t ON o.table_id = t.table_id
    LEFT JOIN customer_accounts c ON o.customer_id = c.customer_id
    JOIN users u ON o.user_id = u.user_id
    WHERE o.order_id = ?
    LIMIT 1
");
$order_stmt->bind_param("i", $order_id);
$order_stmt->execute();
$order = $order_stmt->get_result()->fetch_assoc();
$order_stmt->close();

if (!$order) {
    die("Order not found.");
}

$item_stmt = $conn->prepare("
    SELECT
        oi.*,
        COALESCE(p.product_name, oi.custom_item_name, 'Item') AS item_name
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.product_id
    WHERE oi.order_id = ?
    ORDER BY oi.order_item_id ASC
");
$item_stmt->bind_param("i", $order_id);
$item_stmt->execute();
$items_result = $item_stmt->get_result();

$all_items = [];
while ($row = $items_result->fetch_assoc()) {
    $all_items[] = $row;
}
$item_stmt->close();

$order_number = !empty($order["order_number"])
    ? $order["order_number"]
    : "ORD-" . str_pad($order["order_id"], 5, "0", STR_PAD_LEFT);
$order_type_label = ucwords(
    str_replace("_", " ", $order["order_type"] ?? "retail"),
);
$has_table = !empty($order["table_name"]) && $order["table_name"] !== "N/A";
$has_customer = !empty($order["customer_name"]);
$cash_given = (float) ($order["cash_given"] ?? 0);
$balance = (float) ($order["balance"] ?? 0);
$discount = (float) ($order["discount"] ?? 0);
$subtotal = (float) ($order["subtotal"] ?? 0);
$total = (float) ($order["total_amount"] ?? 0);
$packaging_fee = (float) ($order["packaging_fee"] ?? 0);
$saved_service_charge = (float) ($order["service_charge"] ?? 0);
$service_charge =
    $saved_service_charge > 0
        ? $saved_service_charge
        : max(0, $total - $subtotal + $discount - $packaging_fee);
$pm = $order["payment_method"] ?? "Cash";
$advance_used = (float) ($order["advance_used"] ?? 0);
$remaining_advance = isset($order["customer_advance_balance"])
    ? (float) $order["customer_advance_balance"]
    : null;

$payment_history = [];
$installment_paid = 0.0;
$account_credit_used = 0.0;
$payment_stmt = $conn->prepare("SELECT COALESCE(d.receipt_number,u.receipt_number) receipt_number,u.amount,COALESCE(d.payment_method,u.payment_method) payment_method,COALESCE(d.created_at,u.created_at) created_at,d.order_id source_order_id
    FROM advance_payment_transactions u LEFT JOIN advance_payment_transactions d ON d.transaction_id=u.parent_transaction_id
    WHERE u.order_id=? AND u.transaction_type='sale_usage' ORDER BY COALESCE(d.created_at,u.created_at),u.transaction_id");
$payment_stmt->bind_param("i", $order_id);
$payment_stmt->execute();
$payment_result = $payment_stmt->get_result();
while ($payment_row = $payment_result->fetch_assoc()) {
    $payment_history[] = $payment_row;
    if ($payment_row["source_order_id"] === null) {
        $account_credit_used += (float) $payment_row["amount"];
    } else {
        $installment_paid += (float) $payment_row["amount"];
    }
}
$payment_stmt->close();
if (($order["payment_status"] ?? "") === "paid") {
    $final_payment = max(0, $total - $advance_used);
    if ($final_payment > 0.0001) {
        $payment_history[] = [
            "receipt_number" => "FINAL-" . $order_number,
            "amount" => $final_payment,
            "payment_method" => $pm,
            "created_at" => $order["paid_at"] ?: $order["created_at"],
        ];
    }
}

$total_qty = 0;
foreach ($all_items as $it) {
    $total_qty += (int) $it["quantity"];
}

$is_cash = $pm === "Cash";
$bill_datetime = !empty($order["paid_at"])
    ? $order["paid_at"]
    : $order["created_at"];

function fmt($v)
{
    $s = number_format((float) $v, 2);
    return substr($s, -3) === ".00" ? substr($s, 0, -3) : $s;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Invoice <?php echo htmlspecialchars(
    $order_number,
); ?> — Supun Group</title>

<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
*, *::before, *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

html, body {
    margin: 0;
    padding: 0;
}

body {
    background: #d4d6dc;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 28px 12px 72px;
    font-family: Arial, Helvetica, sans-serif;
    -webkit-font-smoothing: auto;
    text-rendering: geometricPrecision;
    letter-spacing: 0;
}

.receipt {
    position: relative;
    width: 302px;
    background: #fff;
    color: #111;
    padding: 18px 15px 16px;
    box-shadow: 0 8px 32px rgba(0,0,0,.26);
    border-radius: 3px;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 14px;
    line-height: 1.58;
    break-inside: auto;
    page-break-inside: auto;
}

.paid-seal-wrap {
    position: absolute;
    z-index: 2;
    top: 61%;
    right: 8%;
    padding: 0;
    pointer-events: none;
}

.paid-seal {
    width: 76px;
    height: 76px;
    border: 4px double #c62828;
    border-radius: 50%;
    color: #c62828;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    transform: rotate(-12deg);
    font-family: Arial, Helvetica, sans-serif;
    font-weight: 900;
    line-height: 1;
    text-transform: uppercase;
    opacity: .2;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

.paid-seal::before,
.paid-seal::after {
    content: "\2605  \2605  \2605";
    font-size: 8px;
    letter-spacing: 1px;
}

.paid-seal strong {
    font-size: 20px;
    letter-spacing: 2px;
    margin: 4px 0;
}

.paid-seal small {
    font-size: 7px;
    letter-spacing: .5px;
}

.hdr {
    text-align: center;
    padding-bottom: 8px;
}

.logo {
    width: 220px;
    height: auto;
    display: block;
    margin: 0 auto 4px;
}

.shop-name {
    font-size: 28px;
    font-weight: 900;
    letter-spacing: .01em;
    color: #111;
    line-height: 1;
    margin-bottom: 3px;
}

.shop-sub {
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: .2em;
    text-transform: uppercase;
    color: #222;
    margin-bottom: 7px;
}

.shop-addr {
    font-size: 12.5px;
    color: #111;
    line-height: 1.7;
    font-weight: 700;
}

.sep-eq {
    font-size: 11px;
    font-weight: 800;
    text-align: center;
    color: #111;
    letter-spacing: .3px;
    margin: 7px 0;
    line-height: 1;
    overflow: hidden;
}

.meta {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.meta td {
    padding: 1.8px 0;
    vertical-align: top;
}

.meta .lbl {
    width: 92px;
    color: #111;
    font-weight: 700;
}

.meta .sep-col {
    width: 12px;
    color: #111;
}

.meta .val {
    font-weight: 800;
    color: #111;
}

.type-tag {
    display: inline-block;
    border: 1.5px solid #111;
    padding: 0 5px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .1em;
    text-transform: uppercase;
    border-radius: 3px;
    line-height: 1.7;
}

.itbl {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.itbl thead tr {
    border-top: 2px solid #111;
    border-bottom: 2px solid #111;
}

.itbl th {
    padding: 4px 2px;
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: #111;
}

.itbl .h1 {
    text-align: left;
}

.itbl .h2 {
    text-align: center;
    width: 28px;
}

.itbl .h3 {
    text-align: right;
    width: 53px;
}

.itbl .h4 {
    text-align: right;
    width: 58px;
}

.itbl tbody tr {
    border-bottom: 1px dashed #555;
}

.itbl tbody tr:last-child {
    border-bottom: none;
}

.itbl td {
    padding: 4px 2px;
    vertical-align: top;
}

.itbl .d1 {
    text-align: left;
}

.itbl .d2 {
    text-align: center;
    font-weight: 500;
}

.itbl .d3 {
    text-align: right;
    color: #111;
    font-weight: 500;
}

.itbl .d4 {
    text-align: right;
    font-weight: 500;
}

.iname {
    font-size: 14px;
    font-weight: 500;
    color: #111;
    line-height: 1.32;
    word-break: break-word;
}

.ctag {
    font-size: 10px;
    font-weight: 600;
    color: #888;
}

.summ {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.summ td {
    padding: 2.8px 0;
}

.summ .sl {
    color: #111;
    font-weight: 800;
}

.summ .sr {
    text-align: right;
    font-weight: 900;
}

.service-charge-row .sl,
.service-charge-row .sr,
.packing-row .sl,
.packing-row .sr {
    font-weight: 500;
}

.g-row td {
    font-size: 20px;
    font-weight: 900;
    color: #111;
    letter-spacing: .01em;
    border-top: 2px solid #111;
    border-bottom: 2px solid #111;
    padding: 6px 0;
}

.g-row .gr {
    text-align: right;
}

.c-row td {
    font-weight: 700;
}

.b-row td {
    font-weight: 800;
    border-top: 1px dashed #444;
    padding-top: 4px;
}

.ftr {
    text-align: center;
    font-size: 12.5px;
    color: #111;
    line-height: 1.7;
}

.ftr .ft1 {
    font-size: 15px;
    font-weight: 900;
    color: #111;
    letter-spacing: .01em;
}

.ftr .ft2 {
    font-weight: 600;
    color: #111;
}

.ftr .ft3 {
    font-size: 11px;
    color: #111;
    font-weight: 700;
    margin-top: 2px;
}

.dev-credit {
    text-align: center;
    font-size: 9.5px;
    color: #bbb;
    font-weight: 600;
    margin-top: 6px;
    letter-spacing: .03em;
    line-height: 1.45;
    border-top: 1px dashed #ddd;
    padding-top: 5px;
}

.dev-credit strong {
    color: #aaa;
    font-weight: 800;
}

.ref-line {
    text-align: center;
    font-size: 10px;
    font-weight: 600;
    color: #bbb;
    letter-spacing: .04em;
    margin-top: 5px;
}

.actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    flex-wrap: wrap;
    justify-content: center;
}

.a-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 11px 22px;
    border-radius: 9px;
    font-size: 14px;
    font-weight: 800;
    font-family: 'Nunito', sans-serif;
    border: 1.5px solid transparent;
    cursor: pointer;
    text-decoration: none;
    transition: all .16s;
}

.a-print {
    background: #1c2038;
    color: #fff;
    border-color: #1c2038;
}

.a-print:hover {
    background: #2d3252;
    transform: translateY(-1px);
}

.a-drawer {
    background: #15803d;
    color: #fff;
    border-color: #15803d;
    display: <?php echo $is_cash ? "inline-flex" : "none"; ?>;
}

.a-drawer:hover {
    background: #166534;
    transform: translateY(-1px);
}

.a-drawer:disabled {
    background: #888;
    border-color: #888;
    cursor: not-allowed;
    transform: none;
}

.a-back {
    background: #fff;
    color: #454a66;
    border-color: #c8ccd8;
}

.a-back:hover {
    border-color: #777;
    color: #111;
}

.print-status {
    display: <?php echo $is_cash ? "flex" : "none"; ?>;
    align-items: center;
    gap: 8px;
    margin-top: 12px;
    padding: 9px 16px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    font-family: 'Nunito', sans-serif;
    background: #f1f3f8;
    border: 1.5px solid #d0d3de;
    color: #454a66;
    width: 302px;
}

.status-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #bbb;
    flex-shrink: 0;
}

.print-status.ready .status-dot {
    background: #16a34a;
}

.print-status.loading .status-dot {
    background: #d97706;
    animation: pulse .9s infinite;
}

.print-status.error .status-dot {
    background: #dc2626;
}

@keyframes pulse {
    0%,100% {
        opacity: 1;
    }

    50% {
        opacity: .3;
    }
}

/* ==============================
   PRINT SETTINGS FOR 80MM BILL
   ============================== */
@media print {
    @page {
        size: 80mm auto;
        margin: 0;
    }

    html,
    body {
        width: 80mm !important;
        min-width: 80mm !important;
        max-width: 80mm !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        overflow: visible !important;
    }

    body {
        display: block !important;
        min-height: auto !important;
        height: auto !important;
        letter-spacing: 0 !important;
        font-family: Arial, Helvetica, sans-serif !important;
    }

    .receipt {
        width: 76mm !important;
        max-width: 76mm !important;
        margin: 0 auto !important;
        padding: 2mm 2mm 2.5mm !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        overflow: visible !important;

        page-break-inside: auto !important;
        break-inside: auto !important;
        page-break-after: auto !important;
        break-after: auto !important;

        font-family: Arial, Helvetica, sans-serif !important;
        font-size: 12.4px !important;
        font-weight: 700 !important;
        line-height: 1.48 !important;
    }

    .hdr,
    .meta,
    .itbl,
    .summ,
    .ftr,
    .ref-line,
    .dev-credit,
    .sep-eq {
        page-break-inside: auto !important;
        break-inside: auto !important;
    }

    .itbl tr,
    .meta tr,
    .summ tr {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }

    .itbl td,
    .itbl th {
        padding-top: 2.5px !important;
        padding-bottom: 2.5px !important;
    }

    .logo {
        width: 200px !important;
        margin: 0 auto 1px !important;
    }

    .shop-name {
        font-size: 22px !important;
        font-weight: 900 !important;
        margin-bottom: 2px !important;
    }

    .shop-sub {
        font-size: 9.2px !important;
        font-weight: 900 !important;
        margin-bottom: 4px !important;
    }

    .shop-addr {
        font-size: 10.8px !important;
        font-weight: 700 !important;
        line-height: 1.45 !important;
    }

    .meta {
        font-size: 11.6px !important;
    }

    .meta td {
        padding: 1.5px 0 !important;
    }

    .meta .lbl {
        width: 22mm !important;
    }

    .type-tag {
        font-size: 9.2px !important;
        font-weight: 900 !important;
        padding: 0 4px !important;
        line-height: 1.6 !important;
    }

    .itbl {
        font-size: 11.6px !important;
    }

    .itbl th {
        font-size: 10px !important;
        font-weight: 900 !important;
        padding: 3px 1.5px !important;
    }

    .itbl tbody td {
        font-weight: 500 !important;
    }

    .itbl .h2 {
        width: 7mm !important;
    }

    .itbl .h3 {
        width: 13mm !important;
    }

    .itbl .h4 {
        width: 15mm !important;
    }

    .iname {
        font-size: 12px !important;
        font-weight: 500 !important;
        line-height: 1.22 !important;
        word-break: break-word !important;
    }

    .ctag {
        font-size: 8.5px !important;
    }

    .summ {
        font-size: 11.7px !important;
    }

    .summ td {
        padding: 2px 0 !important;
    }

    .service-charge-row .sl,
    .service-charge-row .sr,
    .packing-row .sl,
    .packing-row .sr {
        font-weight: 500 !important;
    }

    .g-row td {
        font-size: 17px !important;
        padding: 4px 0 !important;
    }

    .ftr {
        font-size: 10.8px !important;
        font-weight: 700 !important;
        line-height: 1.45 !important;
    }

    .ftr .ft1 {
        font-size: 13.5px !important;
    }

    .ftr .ft3 {
        font-size: 9.8px !important;
    }

    .sep-eq {
        margin: 4px 0 !important;
        font-size: 9.5px !important;
        line-height: 1 !important;
    }

    .ref-line {
        font-size: 9.4px !important;
    }

    .dev-credit {
        font-size: 8.8px !important;
        margin-top: 4px !important;
        padding-top: 4px !important;
        line-height: 1.35 !important;
    }

    .actions,
    .print-status {
        display: none !important;
    }

    * {
        color: #000 !important;
        background: transparent !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

}
.format-picker { display:flex;align-items:center;gap:9px;margin-top:18px;padding:10px 14px;background:#fff;border-radius:9px;box-shadow:0 3px 14px rgba(0,0,0,.12);font-family:'Nunito',sans-serif; }
.format-picker label { font-size:12px;font-weight:900;color:#454a66;text-transform:uppercase;letter-spacing:.06em; }
.format-picker select { border:1.5px solid #c8ccd8;border-radius:7px;padding:7px 10px;font:700 13px 'Nunito',sans-serif;color:#1c2038;background:#fff; }
body.format-58 .receipt { width:220px;padding:12px 9px;font-size:11px; }
body.format-58 .logo { width:165px; }
body.format-58 .paid-seal-wrap { top:62%;right:5%; }
body.format-58 .paid-seal { width:54px;height:54px;border-width:3px; }
body.format-58 .paid-seal strong { font-size:14px; }
body.format-58 .paid-seal small { font-size:5px; }
body.format-58 .paid-seal::before,body.format-58 .paid-seal::after { font-size:6px; }
body.format-a4 .receipt { width:190mm;min-height:267mm;padding:16mm 18mm; }
body.format-a4 .logo { width:260px; }
body.format-a4 .paid-seal-wrap { top:57%;right:10%; }
body.format-a4 .paid-seal { width:105px;height:105px;border-width:5px; }
body.format-a4 .paid-seal strong { font-size:28px; }
body.format-a4 .paid-seal small { font-size:9px; }

@media print {
    @page thermal80 { size:80mm auto;margin:0; }
    @page thermal58 { size:58mm auto;margin:0; }
    @page invoiceA4 { size:A4 portrait;margin:10mm; }
    .format-picker { display:none !important; }
    body.format-80 .receipt { page:thermal80; }
    html:has(body.format-58),body.format-58 { width:58mm !important;min-width:58mm !important;max-width:58mm !important; }
    body.format-58 .receipt { page:thermal58;width:54mm !important;max-width:54mm !important;padding:2mm !important;font-size:9px !important; }
    body.format-58 .logo { width:42mm !important; }
    body.format-58 .shop-name { font-size:16px !important; }
    body.format-58 .shop-sub { font-size:7px !important;letter-spacing:.07em !important; }
    body.format-58 .shop-addr,body.format-58 .meta,body.format-58 .itbl,body.format-58 .summ { font-size:8px !important; }
    body.format-58 .meta .lbl { width:16mm !important; }
    body.format-58 .itbl th { font-size:7px !important; }
    body.format-58 .iname { font-size:8.5px !important; }
    body.format-58 .g-row td { font-size:13px !important; }
    body.format-58 .paid-seal-wrap { top:62% !important;right:4% !important;padding:0 !important; }
    body.format-58 .paid-seal { width:14mm !important;height:14mm !important;border-width:1mm !important; }
    body.format-58 .paid-seal strong { font-size:10px !important;margin:1px 0 !important; }
    body.format-58 .paid-seal small { font-size:4px !important; }
    body.format-58 .paid-seal::before,body.format-58 .paid-seal::after { font-size:4px !important; }
    html:has(body.format-a4),body.format-a4 { width:210mm !important;min-width:210mm !important;max-width:210mm !important; }
    body.format-a4 .receipt { page:invoiceA4;width:190mm !important;max-width:190mm !important;min-height:267mm !important;padding:14mm 16mm !important;font-size:14px !important; }
    body.format-a4 .logo { width:70mm !important; }
    body.format-a4 .shop-name { font-size:30px !important; }
    body.format-a4 .shop-sub { font-size:11px !important; }
    body.format-a4 .shop-addr,body.format-a4 .meta,body.format-a4 .itbl,body.format-a4 .summ { font-size:13px !important; }
    body.format-a4 .meta .lbl { width:35mm !important; }
    body.format-a4 .itbl th { font-size:11px !important; }
    body.format-a4 .iname { font-size:13px !important; }
    body.format-a4 .g-row td { font-size:21px !important; }
    body.format-a4 .paid-seal-wrap { top:57% !important;right:10% !important; }
    body.format-a4 .paid-seal { width:34mm !important;height:34mm !important;border-width:1.2mm !important; }
    /* Keep the complete invoice, payment history, reference and footer on one A4 sheet. */
    html:has(body.format-a4),body.format-a4 { width:190mm !important;min-width:190mm !important;max-width:190mm !important;height:auto !important;min-height:0 !important;overflow:visible !important; }
    body.format-a4 .receipt { box-sizing:border-box !important;width:190mm !important;max-width:190mm !important;min-height:0 !important;height:auto !important;margin:0 !important;padding:11mm 14mm !important;font-size:14px !important;line-height:1.35 !important;page-break-inside:avoid !important;break-inside:avoid-page !important;page-break-after:avoid !important; }
    body.format-a4 .logo { width:70mm !important;height:auto !important;max-height:30mm !important; }
    body.format-a4 .shop-name { font-size:30px !important;margin:1px 0 !important; }
    body.format-a4 .shop-sub { font-size:11px !important;margin:1px 0 !important; }
    body.format-a4 .shop-addr,body.format-a4 .meta,body.format-a4 .itbl,body.format-a4 .summ { font-size:13px !important;line-height:1.3 !important; }
    body.format-a4 .meta td,body.format-a4 .itbl td,body.format-a4 .itbl th,body.format-a4 .summ td { padding-top:4px !important;padding-bottom:4px !important; }
    body.format-a4 .itbl th { font-size:11px !important; }
    body.format-a4 .iname { font-size:13px !important;line-height:1.25 !important; }
    body.format-a4 .g-row td { font-size:22px !important;padding:7px 0 !important; }
    body.format-a4 .sep-eq { margin:7px 0 !important;line-height:1.1 !important; }
    body.format-a4 .ftr { font-size:11px !important;line-height:1.35 !important; }
    body.format-a4 .ftr .ft1 { font-size:16px !important; }
    body.format-a4 .ftr .ft3,body.format-a4 .ref-line,body.format-a4 .dev-credit { font-size:10px !important;line-height:1.25 !important; }
    body.format-a4 .paid-seal-wrap { top:52% !important;right:9% !important; }
    body.format-a4 .paid-seal { width:32mm !important;height:32mm !important;border-width:1mm !important; }
    /* Remove every non-invoice element from print layout and take the invoice
       out of normal document flow. This prevents Chrome creating a trailing
       blank sheet from flex-body/control dimensions. */
    body.format-a4 { display:block !important;position:relative !important;padding:0 !important;margin:0 !important; }
    body.format-a4 > .format-picker,
    body.format-a4 > .print-status,
    body.format-a4 > .actions { display:none !important;width:0 !important;height:0 !important;margin:0 !important;padding:0 !important; }
    body.format-a4 > .receipt { position:absolute !important;top:0 !important;left:0 !important; }
}
</style>
</head>

<body class="format-80">

<div class="receipt">

    <div class="hdr">
        <img src="supun-logo.png" class="logo" alt="Supun Group logo">
        <div class="shop-name">Supun Group of Companies</div>
        <div class="shop-sub">Retail &amp; Wholesale Electrical Appliances</div>
        <div class="shop-addr">
            Galle Road, Wattala, Sri Lanka<br>
            Tel: 011 234 5678
        </div>
    </div>

    <div class="sep-eq">================================</div>

    <table class="meta">
        <tr>
            <td class="lbl">Invoice No</td>
            <td class="sep-col">:</td>
            <td class="val"><?php echo htmlspecialchars($order_number); ?></td>
        </tr>

        <tr>
            <td class="lbl">Date</td>
            <td class="sep-col">:</td>
            <td class="val"><?php echo date(
                "d M Y",
                strtotime($bill_datetime),
            ); ?></td>
        </tr>

        <tr>
            <td class="lbl">Time</td>
            <td class="sep-col">:</td>
            <td class="val"><?php echo date(
                "h:i A",
                strtotime($bill_datetime),
            ); ?></td>
        </tr>

        <tr>
            <td class="lbl">Sale Type</td>
            <td class="sep-col">:</td>
            <td class="val">
                <span class="type-tag"><?php echo htmlspecialchars(
                    $order_type_label,
                ); ?></span>
            </td>
        </tr>

        <?php if ($has_table): ?>
        <tr>
            <td class="lbl">Table</td>
            <td class="sep-col">:</td>
            <td class="val"><?php echo htmlspecialchars(
                $order["table_name"],
            ); ?></td>
        </tr>
        <?php endif; ?>

        <?php if ($has_customer): ?>
        <tr>
            <td class="lbl">Customer</td>
            <td class="sep-col">:</td>
            <td class="val"><?php echo htmlspecialchars(
                $order["customer_name"],
            ); ?></td>
        </tr>
        <?php endif; ?>

        <tr>
            <td class="lbl">Cashier</td>
            <td class="sep-col">:</td>
            <td class="val"><?php echo htmlspecialchars(
                $order["full_name"],
            ); ?></td>
        </tr>

        <tr>
            <td class="lbl">Payment</td>
            <td class="sep-col">:</td>
            <td class="val"><?php echo htmlspecialchars($pm); ?></td>
        </tr>
    </table>

    <div class="sep-eq">--------------------------------</div>

    <table class="itbl">
        <thead>
            <tr>
                <th class="h1">Item</th>
                <th class="h2">Qty</th>
                <th class="h3">Rate</th>
                <th class="h4">Amount</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($all_items as $item): ?>
            <tr>
                <td class="d1">
                    <div class="iname"><?php echo htmlspecialchars(
                        $item["item_name"],
                    ); ?></div>
                </td>

                <td class="d2"><?php echo (int) $item["quantity"]; ?></td>
                <td class="d3"><?php echo fmt($item["price"]); ?></td>
                <td class="d4"><?php echo fmt($item["line_total"]); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="sep-eq">--------------------------------</div>

    <table class="summ">
        <tr>
            <td class="sl">Items</td>
            <td class="sr"><?php echo count(
                $all_items,
            ); ?> lines / <?php echo $total_qty; ?> pcs</td>
        </tr>

        <tr>
            <td class="sl">Subtotal</td>
            <td class="sr">Rs <?php echo fmt($subtotal); ?></td>
        </tr>

        <?php if ($discount > 0): ?>
        <tr>
            <td class="sl">Discount</td>
            <td class="sr">- Rs <?php echo fmt($discount); ?></td>
        </tr>
        <?php endif; ?>

        <?php if ($service_charge > 0): ?>
        <tr class="service-charge-row">
            <td class="sl">Additional Charge</td>
            <td class="sr">Rs <?php echo fmt($service_charge); ?></td>
        </tr>
        <?php endif; ?>

        <?php if ($packaging_fee > 0): ?>
        <tr class="packing-row">
            <td class="sl">Delivery / Handling</td>
            <td class="sr">Rs <?php echo fmt($packaging_fee); ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <table class="summ" style="margin:6px 0;">
        <tr class="g-row">
            <td>TOTAL</td>
            <td class="gr">Rs <?php echo fmt($total); ?></td>
        </tr>
    </table>

    <?php if (count($payment_history) > 1): ?>
    <div class="sep-eq">----- PAYMENT HISTORY -----</div>
    <table class="summ" style="font-size:10px;margin-bottom:7px;">
        <?php foreach ($payment_history as $index => $history): ?>
        <tr>
            <td class="sl"><?php echo $index +
                1 .
                ". " .
                date(
                    "d M Y",
                    strtotime($history["created_at"]),
                ); ?><br><small><?php echo htmlspecialchars(
    $history["receipt_number"] . " · " . $history["payment_method"],
); ?></small></td>
            <td class="sr">Rs <?php echo fmt($history["amount"]); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <table class="summ">
        <?php if ($advance_used > 0): ?>
        <?php if (
            $installment_paid > 0
        ): ?><tr class="c-row"><td class="sl">Paid by Order Installments</td><td class="sr">Rs <?php echo fmt(
    $installment_paid,
); ?></td></tr><?php endif; ?>
        <?php if (
            $account_credit_used > 0
        ): ?><tr class="c-row"><td class="sl">Paid from Account Credit</td><td class="sr">Rs <?php echo fmt(
    $account_credit_used,
); ?></td></tr><?php endif; ?>
        <?php if ($remaining_advance !== null): ?>
        <tr class="c-row">
            <td class="sl">Remaining Advance Balance</td>
            <td class="sr">Rs <?php echo fmt($remaining_advance); ?></td>
        </tr>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($is_cash): ?>
        <tr class="c-row">
            <td class="sl">Cash Received</td>
            <td class="sr">Rs <?php echo fmt($cash_given); ?></td>
        </tr>

        <tr class="b-row">
            <td><?php echo $balance >= 0
                ? "Change Returned"
                : "Balance Due"; ?></td>
            <td class="sr">Rs <?php echo fmt(abs($balance)); ?></td>
        </tr>
        <?php else: ?>
        <tr class="c-row">
            <td class="sl">Amount Paid</td>
            <td class="sr">Rs <?php echo fmt(
                max(0, $total - $advance_used),
            ); ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <table class="summ" style="margin-top:5px;">
        <tr>
            <td class="sl" style="font-size:10.5px;">Status</td>
            <td class="sr" style="font-size:10.5px;font-weight:900;">
                *** <?php echo strtoupper(
                    $order["payment_status"] ?? "PAID",
                ); ?> ***
            </td>
        </tr>
    </table>

    <?php if (!empty($order["payment_reference"])): ?>
    <div style="margin-top:8px;padding:7px 8px;border:1px dashed #777;font-size:10.5px;line-height:1.35;overflow-wrap:anywhere;">
        <strong>Payment Reference:</strong><br>
        <?php echo nl2br(htmlspecialchars($order["payment_reference"])); ?>
    </div>
    <?php endif; ?>

    <?php if (strtolower($order["payment_status"] ?? "") === "paid"): ?>
    <div class="paid-seal-wrap" aria-label="Paid">
        <div class="paid-seal">
            <small>Payment</small>
            <strong>PAID</strong>
            <small>Confirmed</small>
        </div>
    </div>
    <?php endif; ?>

    <div class="sep-eq">================================</div>

    <div class="ftr">
        <div class="ft1">Thank You For Visiting Us!</div>
        <div class="ft2">Come Again</div>
        <div class="ft3">www.supungroup.example &nbsp;&bull;&nbsp; 011 234 5678</div>
    </div>

    <div class="sep-eq">--------------------------------</div>

    <div class="ref-line">
        <?php echo htmlspecialchars($order_number); ?>
        &nbsp;&nbsp;
        <?php echo date("d/m/Y H:i", strtotime($bill_datetime)); ?>
    </div>

    <div class="dev-credit">
        <strong>Software by A.M.K.D. Athapaththu</strong><br>
        0719148762 &nbsp;&bull;&nbsp; kosalaathapaththu1234@gmail.com
    </div>

</div>

<div class="format-picker">
    <label for="printFormat"><i class="fa-solid fa-ruler-combined"></i> Paper size</label>
    <select id="printFormat" onchange="setPrintFormat(this.value)">
        <option value="80">XPrinter / Thermal 80mm</option>
        <option value="58">Thermal 58mm</option>
        <option value="a4">A4 Invoice</option>
    </select>
</div>

<div class="print-status ready" id="printStatus">
    <div class="status-dot"></div>
    <span id="statusMsg">Local print service ready</span>
</div>

<div class="actions">
    <button class="a-btn a-print" onclick="printBillAndResetDisplay()">
        <i class="fa-solid fa-print"></i> Print Bill
    </button>

    <button class="a-btn a-drawer" id="drawerBtn" onclick="openDrawer()">
        <i class="fa-solid fa-cash-register"></i> Open Drawer
    </button>

    <a class="a-btn a-back" href="<?php echo htmlspecialchars($back_url); ?>">
        <i class="fa-solid fa-arrow-left"></i> <?php echo htmlspecialchars(
            $back_label,
        ); ?>
    </a>
</div>

<script>
const IS_CASH = <?php echo $is_cash ? "true" : "false"; ?>;
const PRINT_SERVICE_URL = "http://localhost:3000/open-drawer";

const statusBox = document.getElementById("printStatus");
const statusMsg = document.getElementById("statusMsg");
const drawerBtn = document.getElementById("drawerBtn");

function setPrintFormat(format) {
    const allowed = ['80', '58', 'a4'];
    const selected = allowed.includes(format) ? format : '80';
    document.body.classList.remove('format-80', 'format-58', 'format-a4');
    document.body.classList.add('format-' + selected);
    document.getElementById('printFormat').value = selected;
    localStorage.setItem('supunPrintFormat', selected);
}

function setStatus(state, msg) {
    if (!statusBox || !statusMsg) return;

    statusBox.className = "print-status " + state;
    statusMsg.textContent = msg;
}

async function openDrawer() {
    if (!IS_CASH) {
        setStatus("error", "Drawer opening is only enabled for cash payments");
        return;
    }

    drawerBtn.disabled = true;
    drawerBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Opening...';
    setStatus("loading", "Sending drawer command...");

    try {
        const response = await fetch(PRINT_SERVICE_URL);

        if (!response.ok) {
            throw new Error("Local print service error");
        }

        const result = await response.text();

        drawerBtn.innerHTML = '<i class="fa-solid fa-check"></i> Drawer Command Sent';
        setStatus("ready", result || "Drawer command sent");

        setTimeout(() => {
            drawerBtn.disabled = false;
            drawerBtn.innerHTML = '<i class="fa-solid fa-cash-register"></i> Open Drawer';
        }, 2500);

    } catch (error) {
        drawerBtn.disabled = false;
        drawerBtn.innerHTML = '<i class="fa-solid fa-cash-register"></i> Open Drawer';
        setStatus("error", "Node print service not running. Start server.js first.");
        console.error(error);
    }
}

window.addEventListener("load", function () {
    setPrintFormat(localStorage.getItem('supunPrintFormat') || '80');
    if (!IS_CASH) return;

    setStatus("ready", "Ready to open drawer using local print service");
});

async function resetCustomerDisplay() {
    try {
        await fetch("http://localhost:3000/customer-display-clear");
        console.log("Customer display reset");
    } catch (err) {
        console.error("Customer display reset failed:", err);
    }
}

function printBillAndResetDisplay() {
    window.print();

    setTimeout(() => {
        resetCustomerDisplay();
    }, 1500);
}
</script>

</body>
</html>
