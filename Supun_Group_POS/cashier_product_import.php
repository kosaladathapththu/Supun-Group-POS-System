<?php
session_start();
include 'db.php';
require_once 'admin/product_import_helpers.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!in_array($_SESSION['role'] ?? '', ['cashier', 'admin'], true)) { http_response_code(403); exit('Access denied.'); }

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cashier_product_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Item Code', 'Product Name', 'Retail Price', 'Wholesale Price', 'Wholesale Min Qty', 'Category', 'Stock Quantity']);
    fputcsv($out, ['ITEM-001', 'Example Product', '1500', '1400', '5', 'Small Appliances', '10']);
    fclose($out); exit;
}

$message = ''; $messageType = '';
if (isset($_POST['import_products'])) {
    try {
        if (!isset($_FILES['product_file']) || $_FILES['product_file']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Select an XLSX or CSV file.');
        if ($_FILES['product_file']['size'] > 5 * 1024 * 1024) throw new RuntimeException('File must be smaller than 5 MB.');
        $ext = strtolower(pathinfo($_FILES['product_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) throw new RuntimeException('Only XLSX and CSV files are supported.');
        $rows = $ext === 'xlsx' ? readProductXlsx($_FILES['product_file']['tmp_name']) : readProductCsv($_FILES['product_file']['tmp_name']);
        if (!$rows) throw new RuntimeException('The file is empty.');
        $headers = array_map('normalizeProductHeader', array_shift($rows));
        $required = ['item code', 'product name', 'retail price', 'wholesale price', 'category', 'stock quantity'];
        foreach ($required as $column) if (!in_array($column, $headers, true)) throw new RuntimeException('Missing column: ' . ucwords($column));
        $records = [];
        foreach ($rows as $line => $values) {
            $data = []; foreach ($headers as $i => $header) $data[$header] = trim((string)($values[$i] ?? ''));
            if (implode('', $data) === '') continue;
            if (($data['item code'] ?? '') === '' || ($data['product name'] ?? '') === '') throw new RuntimeException('Line ' . ($line + 2) . ': item code and product name are required.');
            if ((float)($data['retail price'] ?? 0) <= 0 || (float)($data['wholesale price'] ?? 0) <= 0) throw new RuntimeException('Line ' . ($line + 2) . ': prices must be greater than zero.');
            if ((float)($data['stock quantity'] ?? -1) < 0) throw new RuntimeException('Line ' . ($line + 2) . ': stock cannot be negative.');
            $records[] = $data;
        }
        if (!$records) throw new RuntimeException('No product rows were found.');

        $conn->begin_transaction();
        $findCategory = $conn->prepare('SELECT category_id FROM categories WHERE category_name=? LIMIT 1');
        $addCategory = $conn->prepare('INSERT INTO categories (category_name,status) VALUES (?,1)');
        $findProduct = $conn->prepare('SELECT product_id FROM products WHERE sku=? LIMIT 1');
        $insertProduct = $conn->prepare("INSERT INTO products (category_id,sku,product_name,price,wholesale_price,wholesale_min_qty,stock_qty,status) VALUES (?,?,?,?,?,?,?,1)");
        $updateProduct = $conn->prepare('UPDATE products SET category_id=?,product_name=?,price=?,wholesale_price=?,wholesale_min_qty=?,stock_qty=? WHERE product_id=?');
        foreach ($records as $data) {
            $category = $data['category']; $findCategory->bind_param('s', $category); $findCategory->execute(); $cat = $findCategory->get_result()->fetch_assoc();
            if ($cat) $categoryId = (int)$cat['category_id']; else { $addCategory->bind_param('s', $category); $addCategory->execute(); $categoryId = $conn->insert_id; }
            $sku = $data['item code']; $name = $data['product name']; $retail = (float)$data['retail price']; $wholesale = (float)$data['wholesale price']; $minimum = max(1, (int)($data['wholesale min qty'] ?? 1)); $stock = (float)$data['stock quantity'];
            $findProduct->bind_param('s', $sku); $findProduct->execute(); $product = $findProduct->get_result()->fetch_assoc();
            if ($product) { $productId = (int)$product['product_id']; $updateProduct->bind_param('isddidi', $categoryId, $name, $retail, $wholesale, $minimum, $stock, $productId); $updateProduct->execute(); }
            else { $insertProduct->bind_param('issddid', $categoryId, $sku, $name, $retail, $wholesale, $minimum, $stock); $insertProduct->execute(); }
        }
        $conn->commit(); $message = count($records) . ' product(s) imported successfully.'; $messageType = 'success';
    } catch (Throwable $e) {
        if (isset($conn) && $conn->errno === 0) { try { $conn->rollback(); } catch (Throwable $ignored) {} }
        $message = $e->getMessage(); $messageType = 'error';
    }
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Product Excel Import</title>
<style>body{font-family:Arial,sans-serif;background:#f3f4f6;margin:0;padding:30px;color:#17213a}.wrap{max-width:850px;margin:auto}.back{display:inline-block;margin-bottom:18px;color:#17213a;font-weight:bold;text-decoration:none}.card{background:#fff;border-radius:14px;padding:28px;box-shadow:0 3px 14px #00000012}.notice{padding:14px;border-radius:9px;margin:15px 0}.success{background:#e8fff4;color:#067647}.error{background:#fff0f0;color:#c51b1b}.safe{background:#eff8ff;border:1px solid #b9dcff;padding:14px;border-radius:9px;color:#315474}.actions{display:flex;gap:12px;flex-wrap:wrap;margin:22px 0}.btn{display:inline-block;border:0;border-radius:9px;background:#087f70;color:#fff;font-weight:bold;text-decoration:none;padding:13px 18px;cursor:pointer}.secondary{background:#17213a}input[type=file]{display:block;width:100%;box-sizing:border-box;border:2px dashed #bdc7d8;border-radius:10px;padding:25px;margin:18px 0;background:#fafcff}small{color:#65738a;line-height:1.5}</style></head><body><div class="wrap"><a class="back" href="cashier_products.php">&larr; Back to Product Management</a><div class="card"><h1>Import Products from Excel</h1><div class="safe"><strong>Cashier-safe import:</strong> this page contains product names, selling prices, categories and stock only. Supplier, cost, purchase and administrative information is not available here.</div>
<?php if($message):?><div class="notice <?php echo $messageType;?>"><?php echo htmlspecialchars($message);?></div><?php endif;?>
<div class="actions"><a class="btn secondary" href="?template=1">Download Excel/CSV Template</a></div>
<form method="post" enctype="multipart/form-data"><label><strong>Select completed XLSX or CSV file</strong></label><input type="file" name="product_file" accept=".xlsx,.csv" required><small>Maximum 5 MB. Required columns: Item Code, Product Name, Retail Price, Wholesale Price, Category and Stock Quantity.</small><div class="actions"><button class="btn" name="import_products" onclick="return confirm('Import these product and stock details?')">Import Products</button></div></form></div></div></body></html>
