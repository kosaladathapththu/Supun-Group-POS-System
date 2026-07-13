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
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Product Sales</title>
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
            max-width: 1200px;
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

        @media print {
            @page {
                size: 80mm auto;
                margin: 4mm;
            }

            body {
                background: #fff;
                padding: 0;
                margin: 0;
                font-size: 12px;
            }

            .container {
                width: 72mm;
                max-width: 72mm;
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
                font-size: 11px;
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
        <button onclick="window.print()" class="print-btn"><i class="fa-solid fa-print"></i> Print XPrinter Report</button>
    </div>

    <div class="card no-print">
        <h1 class="report-heading"><i class="fa-solid fa-chart-column"></i> Daily Product Sales Report</h1>

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
        <p>Daily Product Sales</p>
        <p><?php echo htmlspecialchars($from_date); ?> to <?php echo htmlspecialchars($to_date); ?></p>
        <hr>
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
