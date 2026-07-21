<?php
session_start();
include 'db.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_role = $_SESSION["role"] ?? "";
if (!in_array($user_role, ["cashier", "admin"])) {
    die("Access denied.");
}

/* ADD PRODUCT */
if (isset($_POST["add_product"])) {
    $name = trim($_POST["product_name"]);
    $price = (float)$_POST["price"];
    $wholesale_price = (float)($_POST["wholesale_price"] ?? 0);
    $wholesale_min_qty = max(1, (int)($_POST["wholesale_min_qty"] ?? 1));
    $category_id = (int)$_POST["category_id"];

    if ($name !== "" && $price > 0 && $wholesale_price > 0 && $category_id > 0) {
        $stmt = $conn->prepare("
            INSERT INTO products (product_name, price, wholesale_price, wholesale_min_qty, category_id, status)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->bind_param("sddii", $name, $price, $wholesale_price, $wholesale_min_qty, $category_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: cashier_products.php");
    exit;
}

/* UPDATE PRODUCT */
if (isset($_POST["update_product"])) {
    $product_id = (int)$_POST["product_id"];
    $name = trim($_POST["product_name"]);
    $price = (float)$_POST["price"];
    $wholesale_price = (float)($_POST["wholesale_price"] ?? 0);
    $wholesale_min_qty = max(1, (int)($_POST["wholesale_min_qty"] ?? 1));
    $category_id = (int)$_POST["category_id"];
    $status = (int)$_POST["status"];

    if ($product_id > 0 && $name !== "" && $price > 0 && $wholesale_price > 0 && $category_id > 0) {
        $stmt = $conn->prepare("
            UPDATE products
            SET product_name = ?, price = ?, wholesale_price = ?, wholesale_min_qty = ?, category_id = ?, status = ?
            WHERE product_id = ?
        ");
        $stmt->bind_param("sddiiii", $name, $price, $wholesale_price, $wholesale_min_qty, $category_id, $status, $product_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: cashier_products.php");
    exit;
}

$categories = $conn->query("SELECT * FROM categories WHERE status=1 ORDER BY category_name ASC");

$products = $conn->query("
    SELECT p.*, c.category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.category_id
    ORDER BY p.product_id DESC
");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Cashier Product Management</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f3f4f6;
            padding: 25px;
        }

        .box {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }

        h2 {
            margin-bottom: 15px;
        }

        input, select {
            padding: 10px;
            margin: 5px;
            border: 1px solid #ccc;
            border-radius: 8px;
        }

        button {
            padding: 10px 16px;
            border: none;
            border-radius: 8px;
            background: #d95c2b;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th, td {
            padding: 10px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #1c2038;
            color: white;
        }

        .back {
            display: inline-block;
            margin-bottom: 15px;
            text-decoration: none;
            color: #1c2038;
            font-weight: bold;
        }

        .import-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            border: 1px solid #99e5d4;
            background: linear-gradient(135deg, #ecfdf7, #ffffff);
        }
        .import-box h2 { margin: 0 0 8px; }
        .import-box p { margin: 0; color: #53647a; line-height: 1.5; }
        .import-actions { flex: 0 0 auto; }
        .import-btn {
            display: inline-block;
            padding: 13px 20px;
            border-radius: 9px;
            background: #087f70;
            color: white;
            text-decoration: none;
            font-weight: bold;
            white-space: nowrap;
        }
        .import-note { display: block; margin-top: 7px; color: #6b7280; font-size: 12px; text-align: center; }

        @media (max-width: 760px) {
            .import-box { align-items: stretch; flex-direction: column; }
            .import-btn { display: block; text-align: center; }
        }
    </style>
</head>
<body>

<a href="pos.php" class="back">← Back to POS</a>

<div class="box import-box">
    <div>
        <h2>Import Inventory from Excel</h2>
        <p>Import product names, selling prices, categories and stock without exposing supplier, cost or purchase information.</p>
    </div>
    <div class="import-actions">
        <?php
        $import_url = 'cashier_product_import.php';
        ?>
        <a href="<?php echo $import_url; ?>" class="import-btn">Open Excel Import</a>
        <span class="import-note">Restricted product details only</span>
    </div>
</div>

<div class="box">
    <h2>Add New Product</h2>

    <form method="POST">
        <input type="text" name="product_name" placeholder="Product name" required>

        <input type="number" name="price" step="0.01" min="0.01" placeholder="Retail price (Rs.)" required>

        <input type="number" name="wholesale_price" step="0.01" min="0.01" placeholder="Wholesale price (Rs.)" required>

        <input type="number" name="wholesale_min_qty" min="1" value="1" title="Minimum quantity for a wholesale sale" placeholder="Wholesale minimum quantity" required>

        <select name="category_id" required>
            <option value="">Select category</option>
            <?php
            mysqli_data_seek($categories, 0);
            while ($cat = $categories->fetch_assoc()):
            ?>
                <option value="<?php echo $cat['category_id']; ?>">
                    <?php echo htmlspecialchars($cat['category_name']); ?>
                </option>
            <?php endwhile; ?>
        </select>

        <button type="submit" name="add_product">Add Product</button>
    </form>
</div>

<div class="box">
    <h2>Edit Products</h2>

    <table>
        <tr>
            <th>ID</th>
            <th>Product</th>
            <th>Retail Price</th>
            <th>Wholesale Price</th>
            <th>Wholesale Min Qty</th>
            <th>Category</th>
            <th>Status</th>
            <th>Action</th>
        </tr>

        <?php while ($p = $products->fetch_assoc()): ?>
        <tr>
            <form method="POST">
                <td>
                    <?php echo $p['product_id']; ?>
                    <input type="hidden" name="product_id" value="<?php echo $p['product_id']; ?>">
                </td>

                <td>
                    <input type="text" name="product_name"
                           value="<?php echo htmlspecialchars($p['product_name']); ?>" required>
                </td>

                <td>
                    <input type="number" name="price" step="0.01" min="0.01"
                           value="<?php echo $p['price']; ?>" required>
                </td>

                <td>
                    <input type="number" name="wholesale_price" step="0.01" min="0.01"
                           value="<?php echo $p['wholesale_price']; ?>" required>
                </td>

                <td>
                    <input type="number" name="wholesale_min_qty" min="1"
                           value="<?php echo max(1, (int)$p['wholesale_min_qty']); ?>" required>
                </td>

                <td>
                    <select name="category_id" required>
                        <?php
                        mysqli_data_seek($categories, 0);
                        while ($cat = $categories->fetch_assoc()):
                        ?>
                            <option value="<?php echo $cat['category_id']; ?>"
                                <?php echo ($cat['category_id'] == $p['category_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </td>

                <td>
                    <select name="status">
                        <option value="1" <?php echo $p['status'] == 1 ? 'selected' : ''; ?>>Active</option>
                        <option value="0" <?php echo $p['status'] == 0 ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </td>

                <td>
                    <button type="submit" name="update_product">Update</button>
                </td>
            </form>
        </tr>
        <?php endwhile; ?>
    </table>
</div>

</body>
</html>
