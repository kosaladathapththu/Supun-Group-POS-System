<?php
include '../includes/auth.php';
include '../db.php';

$from_date = $_GET['from_date'] ?? date('Y-m-d');
$to_date   = $_GET['to_date'] ?? date('Y-m-d');

$sql = "
    SELECT
        DATE(o.created_at) AS sale_date,
        p.product_name,
        SUM(oi.quantity) AS total_qty,
        SUM(oi.line_total) AS total_amount
    FROM orders o
    JOIN order_items oi ON o.order_id = oi.order_id
    JOIN products p ON oi.product_id = p.product_id
    WHERE DATE(o.created_at) BETWEEN ? AND ?
      AND o.payment_status = 'paid'
    GROUP BY DATE(o.created_at), p.product_id, p.product_name
    ORDER BY sale_date DESC, p.product_name ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $from_date, $to_date);
$stmt->execute();
$result = $stmt->get_result();

$sales = [];
$dateTotals = [];

while ($row = $result->fetch_assoc()) {
    $date = $row['sale_date'];
    $sales[$date][] = $row;

    if (!isset($dateTotals[$date])) {
        $dateTotals[$date] = 0;
    }

    $dateTotals[$date] += $row['total_amount'];
}

/* Full-period product performance. Order discounts are distributed across
   products according to each line's share of the order subtotal. */
$productSql = "
    SELECT
        p.product_id,
        p.product_name,
        p.stock_qty AS current_stock,
        p.unit,
        COUNT(DISTINCT o.order_id) AS order_count,
        SUM(oi.quantity) AS total_qty,
        SUM(oi.line_total) AS gross_sales,
        SUM(CASE WHEN o.subtotal > 0
            THEN o.discount * (oi.line_total / o.subtotal) ELSE 0 END) AS allocated_discount,
        SUM(oi.line_total - CASE WHEN o.subtotal > 0
            THEN o.discount * (oi.line_total / o.subtotal) ELSE 0 END) AS net_sales,
        SUM(COALESCE(oi.cost_price, 0) * oi.quantity) AS total_cost,
        SUM(oi.line_total
            - CASE WHEN o.subtotal > 0
                THEN o.discount * (oi.line_total / o.subtotal) ELSE 0 END
            - (COALESCE(oi.cost_price, 0) * oi.quantity)) AS gross_profit
    FROM orders o
    JOIN order_items oi ON o.order_id = oi.order_id
    JOIN products p ON oi.product_id = p.product_id
    WHERE DATE(o.created_at) BETWEEN ? AND ?
      AND o.payment_status = 'paid'
    GROUP BY p.product_id, p.product_name, p.stock_qty, p.unit
    ORDER BY gross_profit DESC, p.product_name ASC
";
$productStmt = $conn->prepare($productSql);
$productStmt->bind_param('ss', $from_date, $to_date);
$productStmt->execute();
$productResult = $productStmt->get_result();
$productRows = [];
while ($row = $productResult->fetch_assoc()) {
    $productRows[] = $row;
}

$summarySql = "
    SELECT
        COUNT(DISTINCT o.order_id) AS total_orders,
        COALESCE(SUM(oi.quantity), 0) AS total_qty,
        COALESCE(SUM(oi.line_total), 0) AS gross_sales,
        COALESCE(SUM(CASE WHEN o.subtotal > 0
            THEN o.discount * (oi.line_total / o.subtotal) ELSE 0 END), 0) AS allocated_discount,
        COALESCE(SUM(oi.line_total - CASE WHEN o.subtotal > 0
            THEN o.discount * (oi.line_total / o.subtotal) ELSE 0 END), 0) AS net_sales,
        COALESCE(SUM(COALESCE(oi.cost_price, 0) * oi.quantity), 0) AS total_cost,
        COALESCE(SUM(oi.line_total
            - CASE WHEN o.subtotal > 0
                THEN o.discount * (oi.line_total / o.subtotal) ELSE 0 END
            - (COALESCE(oi.cost_price, 0) * oi.quantity)), 0) AS gross_profit
    FROM orders o
    JOIN order_items oi ON o.order_id = oi.order_id
    WHERE DATE(o.created_at) BETWEEN ? AND ?
      AND o.payment_status = 'paid'
      AND oi.product_id IS NOT NULL
";
$summaryStmt = $conn->prepare($summarySql);
$summaryStmt->bind_param('ss', $from_date, $to_date);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();
$profitMargin = (float)$summary['net_sales'] > 0
    ? ((float)$summary['gross_profit'] / (float)$summary['net_sales']) * 100
    : 0;

$inventorySummary = $conn->query("
    SELECT
        COUNT(CASE WHEN stock_qty > 0 THEN 1 END) AS products_in_stock,
        COALESCE(SUM(stock_qty), 0) AS units_in_stock
    FROM products
    WHERE status = 1
")->fetch_assoc();
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Full Product Sales Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Lora:wght@600;700&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        <?php include 'shared_style.php'; ?>
        body {
            font-family: 'Nunito', sans-serif;
            background: var(--bg);
            margin: 0;
            padding: 0;
            color: var(--text);
            display:flex;
        }

        .container {
            max-width: 1500px;
            margin: 0 auto;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .card {
            background: #fff;
            padding: 20px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
        }

        h1, h2, h3 {
            margin-top: 0;
        }

        form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: end;
        }

        label {
            font-weight: bold;
            font-size: 14px;
        }

        input {
            padding: 9px;
            border: 1px solid #ccc;
            border-radius: 6px;
        }

        button, .back-btn {
            padding: 10px 14px;
            border: none;
            border-radius: 6px;
            background: var(--primary);
            color: #fff;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
        }

        .print-btn {
            background: var(--indigo);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #f1f1f1;
        }

        .total-row {
            font-weight: bold;
            background: #fafafa;
        }

        .no-print {
            display: block;
        }

        .receipt-title {
            text-align: center;
            display: none;
        }

        .report-heading{display:flex;align-items:center;gap:9px;font-family:'Lora',serif;font-size:22px;margin-bottom:15px;}
        .report-heading i{color:var(--primary);}
        .filter-field{display:flex;flex-direction:column;gap:5px;}
        .filter-field label{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--text-mid);font-weight:900;}
        .filter-field input{font-family:'Nunito',sans-serif;background:var(--bg);border:1.5px solid var(--border);}
        .date-card-title{font-family:'Lora',serif;font-size:16px;display:flex;align-items:center;gap:7px;}
        .summary-grid{display:grid;grid-template-columns:repeat(4,minmax(170px,1fr));gap:14px;margin-bottom:20px;}
        .summary-card{background:#fff;border:1.5px solid var(--border);border-radius:var(--radius);padding:17px;box-shadow:var(--shadow-sm);}
        .summary-card .label{color:var(--text-mid);font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.07em;}
        .summary-card .value{font-family:'Lora',serif;font-size:21px;font-weight:700;margin-top:6px;color:var(--primary-dark);}
        .summary-card.profit .value{color:#0b9f4a;}.summary-card.discount .value{color:#d97706;}
        .section-title{font-family:'Lora',serif;font-size:20px;display:flex;align-items:center;gap:8px;margin:0 0 4px;}
        .section-subtitle{color:var(--text-mid);font-size:13px;margin:0 0 14px;}
        .number{text-align:right;white-space:nowrap;}.profit-positive{color:#07883f;font-weight:900;}.profit-negative{color:#dc2626;font-weight:900;}
        .full-report-table{font-size:13px;}.full-report-table th{font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-mid);}
        @media(max-width:1000px){.summary-grid{grid-template-columns:repeat(2,1fr)}.table-scroll{overflow-x:auto}.full-report-table{min-width:1050px}}
        @media(max-width:560px){.summary-grid{grid-template-columns:1fr}}

        @media print {
            @page {
                size: A4 landscape;
                margin: 8mm;
            }

            body {
                background: #fff;
                padding: 0;
                margin: 0;
                font-size: 12px;
                display: block;
            }

            .main, .content { display:block !important; margin:0 !important; padding:0 !important; }

            .container {
                width: auto;
                max-width: none;
                margin: 0 auto;
            }

            .no-print {
                display: none !important;
            }

            .card {
                box-shadow: none;
                border-radius: 0;
                padding: 0;
                margin-bottom: 10px;
            }

            .receipt-title {
                display: block;
                margin-bottom: 10px;
            }

            h1, h2, h3 {
                font-size: 14px;
                text-align: center;
                margin: 6px 0;
            }

            table {
                width: 100%;
                font-size: 9px;
            }

            th, td {
                padding: 4px 2px;
                border-bottom: 1px dashed #999;
            }

            th {
                background: none;
            }

            .total-row {
                background: none;
                font-weight: bold;
            }
            .summary-grid{grid-template-columns:repeat(4,1fr);gap:5px;margin-bottom:8px;}
            .summary-card{box-shadow:none;border:1px solid #bbb;padding:7px;}
            .summary-card .value{font-size:13px;}.summary-card .label{font-size:8px;}
            .full-report-table{font-size:8px;}.daily-breakdown{break-before:page;}
        }
    </style>
</head>

<body>
<?php include 'shared_nav.php'; ?>
<div class="main">
<?php include 'shared_topbar.php'; ?>
<div class="content">
<div class="container">

    <div class="top-bar no-print">
        <a href="sales.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Sales</a>
        <button onclick="window.print()" class="print-btn"><i class="fa-solid fa-print"></i> Print Full Report</button>
    </div>

    <div class="card no-print">
        <h1 class="report-heading"><i class="fa-solid fa-chart-column"></i> Full Product Sales Report</h1>

        <form method="GET">
            <div class="filter-field">
                <label>From Date</label>
                <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>">
            </div>

            <div class="filter-field">
                <label>To Date</label>
                <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>">
            </div>

            <button type="submit">Filter</button>
        </form>
    </div>

    <div class="receipt-title">
        <h2>SUPUN GROUP OF COMPANIES</h2>
        <p>Full Product Sales &amp; Profit Report</p>
        <p><?php echo htmlspecialchars($from_date); ?> to <?php echo htmlspecialchars($to_date); ?></p>
        <hr>
    </div>

    <div class="summary-grid">
        <div class="summary-card"><div class="label">Paid Orders</div><div class="value"><?php echo (int)$summary['total_orders']; ?></div></div>
        <div class="summary-card"><div class="label">Units Sold</div><div class="value"><?php echo number_format((float)$summary['total_qty'], 0); ?></div></div>
        <div class="summary-card"><div class="label">Gross Product Sales</div><div class="value">Rs. <?php echo number_format((float)$summary['gross_sales'], 2); ?></div></div>
        <div class="summary-card discount"><div class="label">Allocated Discounts</div><div class="value">Rs. <?php echo number_format((float)$summary['allocated_discount'], 2); ?></div></div>
        <div class="summary-card"><div class="label">Net Product Sales</div><div class="value">Rs. <?php echo number_format((float)$summary['net_sales'], 2); ?></div></div>
        <div class="summary-card"><div class="label">Product Cost</div><div class="value">Rs. <?php echo number_format((float)$summary['total_cost'], 2); ?></div></div>
        <div class="summary-card profit"><div class="label">Gross Product Profit</div><div class="value">Rs. <?php echo number_format((float)$summary['gross_profit'], 2); ?></div></div>
        <div class="summary-card profit"><div class="label">Gross Margin</div><div class="value"><?php echo number_format($profitMargin, 2); ?>%</div></div>
        <div class="summary-card"><div class="label">Product Types in Stock</div><div class="value"><?php echo number_format((int)$inventorySummary['products_in_stock']); ?></div></div>
        <div class="summary-card"><div class="label">Total Units in Stock</div><div class="value"><?php echo number_format((float)$inventorySummary['units_in_stock'], 0); ?></div></div>
    </div>

    <div class="card">
        <h2 class="section-title"><i class="fa-solid fa-boxes-stacked"></i> Product Performance — Full Period</h2>
        <p class="section-subtitle">All paid product sales from <?php echo date('d M Y', strtotime($from_date)); ?> to <?php echo date('d M Y', strtotime($to_date)); ?>. Profit is after sale discounts and product cost, before general business expenses.</p>
        <?php if (empty($productRows)) { ?>
            <p>No paid product sales found for this date range.</p>
        <?php } else { ?>
        <div class="table-scroll">
            <table class="full-report-table">
                <thead><tr><th>Product</th><th class="number">In Stock</th><th class="number">Orders</th><th class="number">Qty Sold</th><th class="number">Gross Sales</th><th class="number">Discount</th><th class="number">Net Sales</th><th class="number">Cost</th><th class="number">Gross Profit</th><th class="number">Margin</th></tr></thead>
                <tbody>
                <?php foreach ($productRows as $product) {
                    $margin = (float)$product['net_sales'] > 0 ? ((float)$product['gross_profit'] / (float)$product['net_sales']) * 100 : 0;
                    $profitClass = (float)$product['gross_profit'] >= 0 ? 'profit-positive' : 'profit-negative';
                ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($product['product_name']); ?></strong></td>
                        <td class="number"><strong><?php echo number_format((float)$product['current_stock'], 0); ?></strong> <?php echo htmlspecialchars($product['unit']); ?></td>
                        <td class="number"><?php echo (int)$product['order_count']; ?></td>
                        <td class="number"><?php echo number_format((float)$product['total_qty'], 0); ?></td>
                        <td class="number">Rs. <?php echo number_format((float)$product['gross_sales'], 2); ?></td>
                        <td class="number">Rs. <?php echo number_format((float)$product['allocated_discount'], 2); ?></td>
                        <td class="number">Rs. <?php echo number_format((float)$product['net_sales'], 2); ?></td>
                        <td class="number">Rs. <?php echo number_format((float)$product['total_cost'], 2); ?></td>
                        <td class="number <?php echo $profitClass; ?>">Rs. <?php echo number_format((float)$product['gross_profit'], 2); ?></td>
                        <td class="number <?php echo $profitClass; ?>"><?php echo number_format($margin, 2); ?>%</td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>
    </div>

    <div class="daily-breakdown">
        <h2 class="section-title"><i class="fa-regular fa-calendar-days"></i> Daily Breakdown</h2>
        <p class="section-subtitle">The same selected period grouped by transaction date.</p>
    </div>

    <?php if (empty($sales)) { ?>

        <div class="card">
            <h3>No sales found</h3>
            <p>No paid product sales found for this date range.</p>
        </div>

    <?php } else { ?>

        <?php foreach ($sales as $date => $items) { ?>

            <div class="card">
                <h2 class="date-card-title"><i class="fa-regular fa-calendar"></i> <?php echo date('d M Y', strtotime($date)); ?></h2>

                <table>
                    <tr>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Total</th>
                    </tr>

                    <?php foreach ($items as $item) { ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td><?php echo (int)$item['total_qty']; ?></td>
                            <td>Rs. <?php echo number_format($item['total_amount'], 2); ?></td>
                        </tr>
                    <?php } ?>

                    <tr class="total-row">
                        <td colspan="2">Date Total</td>
                        <td>Rs. <?php echo number_format($dateTotals[$date], 2); ?></td>
                    </tr>
                </table>
            </div>

        <?php } ?>

    <?php } ?>

</div>
</div>
</div>
</body>
</html>
