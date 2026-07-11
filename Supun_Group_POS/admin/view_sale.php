<?php
include '../includes/auth.php';
include '../db.php';

$order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;

$order = $conn->query("
    SELECT o.*, t.table_name, u.full_name
    FROM orders o
    JOIN restaurant_tables t ON o.table_id = t.table_id
    JOIN users u ON o.user_id = u.user_id
    WHERE o.order_id = $order_id
")->fetch_assoc();

if (!$order) {
    die("Order not found");
}

$items = $conn->query("
    SELECT oi.*, p.product_name
    FROM order_items oi
    JOIN products p ON oi.product_id = p.product_id
    WHERE oi.order_id = $order_id
");
?>

<!DOCTYPE html>
<html>
<head>
    <title>View Sale</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 900px;
            margin: auto;
        }

        .card {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 0 8px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }

        a {
            text-decoration: none;
        }
    </style>
</head>
<body>
<div class="container">

    <p><a href="sales.php">← Back to Sales</a></p>

    <div class="card">
        <h1>Order #<?php echo $order['order_id']; ?></h1>
        <p><strong>Table:</strong> <?php echo $order['table_name']; ?></p>
        <p><strong>Cashier:</strong> <?php echo $order['full_name']; ?></p>
        <p><strong>Payment Status:</strong> <?php echo ucfirst($order['payment_status']); ?></p>
        <p><strong>Date:</strong> <?php echo $order['created_at']; ?></p>
        <p><strong>Total Amount:</strong> Rs. <?php echo number_format($order['total_amount'], 2); ?></p>
    </div>

    <div class="card">
        <h2>Order Items</h2>

        <table>
            <tr>
                <th>Product</th>
                <th>Quantity</th>
                <th>Unit Price</th>
                <th>Line Total</th>
            </tr>

            <?php while ($item = $items->fetch_assoc()) { ?>
                <tr>
                    <td><?php echo $item['product_name']; ?></td>
                    <td><?php echo $item['quantity']; ?></td>
                    <td>Rs. <?php echo number_format($item['unit_price'], 2); ?></td>
                    <td>Rs. <?php echo number_format($item['line_total'], 2); ?></td>
                </tr>
            <?php } ?>
        </table>
    </div>

</div>
</body>
</html>