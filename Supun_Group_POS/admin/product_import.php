<?php
$cashierMode = isset($_GET["cashier_mode"]) && $_GET["cashier_mode"] === "1";
if ($cashierMode) {
    session_start();
    if (!isset($_SESSION["user_id"])) {
        header("Location: ../login.php");
        exit();
    }
    if (
        !in_array(
            $_SESSION["role"] ?? "",
            ["cashier", "accountant", "admin"],
            true,
        )
    ) {
        http_response_code(403);
        exit("Access denied.");
    }
} else {
    include "../includes/auth.php";
}
include "../db.php";
include_once "product_import_helpers.php";
if (isset($_GET["template"])) {
    outputProductXlsxTemplate($conn);
}
$msg = "";
$msgType = "";
$preview = $_SESSION["product_import_preview"] ?? [];
if (isset($_POST["clear_preview"])) {
    unset($_SESSION["product_import_preview"]);
    $preview = [];
}
if (isset($_POST["preview_import"])) {
    $preview = [];
    try {
        if (
            !isset($_FILES["product_file"]) ||
            $_FILES["product_file"]["error"] !== UPLOAD_ERR_OK
        ) {
            throw new RuntimeException("Select an XLSX or CSV product file.");
        }
        if ($_FILES["product_file"]["size"] > 5 * 1024 * 1024) {
            throw new RuntimeException(
                "The product file must be smaller than 5 MB.",
            );
        }
        $ext = strtolower(
            pathinfo($_FILES["product_file"]["name"], PATHINFO_EXTENSION),
        );
        if (!in_array($ext, ["xlsx", "csv"], true)) {
            throw new RuntimeException(
                "Only XLSX and CSV files are supported.",
            );
        }
        $rows =
            $ext === "xlsx"
                ? readProductXlsx($_FILES["product_file"]["tmp_name"])
                : readProductCsv($_FILES["product_file"]["tmp_name"]);
        $preview = mapProductImportRows($rows, $conn);
        if (!$preview) {
            throw new RuntimeException("No product rows were found.");
        }
        $_SESSION["product_import_preview"] = $preview;
        $msg = "Product file validated. Review every row before importing.";
        $msgType = "success";
    } catch (Throwable $e) {
        unset($_SESSION["product_import_preview"]);
        $preview = [];
        $msg = $e->getMessage();
        $msgType = "error";
    }
}
if (isset($_POST["confirm_import"])) {
    $preview = $_SESSION["product_import_preview"] ?? [];
    $hasErrors = false;
    foreach ($preview as $r) {
        if ($r["errors"]) {
            $hasErrors = true;
        }
    }
    if (!$preview || $hasErrors) {
        $msg = "Correct all file errors before importing inventory.";
        $msgType = "error";
    } else {
        $conn->begin_transaction();
        try {
            $uid = (int) $_SESSION["user_id"];
            $purchaseGroups = [];
            $count = 0;
            $findCat = $conn->prepare(
                "SELECT category_id FROM categories WHERE category_name=? LIMIT 1",
            );
            $addCat = $conn->prepare(
                "INSERT INTO categories (category_name,status) VALUES (?,1)",
            );
            $findSupplierCode = $conn->prepare(
                "SELECT supplier_id FROM suppliers WHERE supplier_code=? LIMIT 1",
            );
            $findSupplierName = $conn->prepare(
                "SELECT supplier_id FROM suppliers WHERE supplier_name=? LIMIT 1",
            );
            $addSupplier = $conn->prepare(
                "INSERT INTO suppliers (supplier_code,supplier_name,phone,status) VALUES (NULLIF(?,''),?,?,1)",
            );
            $addPurchase = $conn->prepare(
                "INSERT INTO purchases (supplier_id,supplier_invoice,purchase_date,notes,created_by) VALUES (?,NULLIF(?,''),?,?,?)",
            );
            $numberPurchase = $conn->prepare(
                "UPDATE purchases SET purchase_number=? WHERE purchase_id=?",
            );
            $insertProduct = $conn->prepare(
                "INSERT INTO products (category_id,sku,barcode,serial_no,product_name,brand,unit,cost_price,price,wholesale_price,wholesale_min_qty,stock_qty,reorder_level,status) VALUES (?,NULLIF(?,''),NULLIF(?,''),NULLIF(?,''),?,NULLIF(?,''),?,?,?,?,?,?,?,?)",
            );
            $findExistingProduct = $conn->prepare(
                "SELECT product_id,stock_qty,cost_price FROM products WHERE sku=? LIMIT 1 FOR UPDATE",
            );
            $updateExistingProduct = $conn->prepare(
                "UPDATE products SET category_id=?,barcode=COALESCE(NULLIF(?,''),barcode),serial_no=COALESCE(NULLIF(?,''),serial_no),product_name=?,brand=COALESCE(NULLIF(?,''),brand),unit=?,cost_price=?,price=?,wholesale_price=?,wholesale_min_qty=?,stock_qty=?,reorder_level=?,status=? WHERE product_id=?",
            );
            $insertItem = $conn->prepare(
                "INSERT INTO purchase_items (purchase_id,product_id,quantity,received_qty,unit_cost,line_total) VALUES (?,?,?,?,?,?)",
            );
            $log = $conn->prepare(
                "INSERT INTO stock_adjustments (product_id,user_id,adjustment_type,quantity,stock_before,stock_after,unit_cost,total_cost,note) VALUES (?,?,'stock_in',?,?,?,?,?,?)",
            );
            foreach ($preview as $row) {
                $d = $row["data"];
                $category = trim($d["category"]);
                $findCat->bind_param("s", $category);
                $findCat->execute();
                $cat = $findCat->get_result()->fetch_assoc();
                if ($cat) {
                    $catId = (int) $cat["category_id"];
                } else {
                    $addCat->bind_param("s", $category);
                    $addCat->execute();
                    $catId = $conn->insert_id;
                }
                $supplierCode = trim($d["supplier code"] ?? "");
                $supplierName = trim($d["supplier name"]);
                $supplierPhone = trim($d["supplier phone"] ?? "");
                $supplier = null;
                if ($supplierCode !== "") {
                    $findSupplierCode->bind_param("s", $supplierCode);
                    $findSupplierCode->execute();
                    $supplier = $findSupplierCode->get_result()->fetch_assoc();
                }
                if (!$supplier) {
                    $findSupplierName->bind_param("s", $supplierName);
                    $findSupplierName->execute();
                    $supplier = $findSupplierName->get_result()->fetch_assoc();
                }
                if ($supplier) {
                    $supplierId = (int) $supplier["supplier_id"];
                } else {
                    $addSupplier->bind_param(
                        "sss",
                        $supplierCode,
                        $supplierName,
                        $supplierPhone,
                    );
                    $addSupplier->execute();
                    $supplierId = $conn->insert_id;
                }
                $invoice = trim($d["supplier invoice"] ?? "");
                $purchaseDate =
                    trim($d["purchase date"] ?? "") ?: date("Y-m-d");
                $purchaseDate = date("Y-m-d", strtotime($purchaseDate));
                $groupKey = $supplierId . "|" . $invoice . "|" . $purchaseDate;
                if (!isset($purchaseGroups[$groupKey])) {
                    $notes = "Created from unified inventory import";
                    $addPurchase->bind_param(
                        "isssi",
                        $supplierId,
                        $invoice,
                        $purchaseDate,
                        $notes,
                        $uid,
                    );
                    $addPurchase->execute();
                    $purchaseId = $conn->insert_id;
                    $purchaseNumber =
                        "PUR-" .
                        str_pad((string) $purchaseId, 6, "0", STR_PAD_LEFT);
                    $numberPurchase->bind_param(
                        "si",
                        $purchaseNumber,
                        $purchaseId,
                    );
                    $numberPurchase->execute();
                    $purchaseGroups[$groupKey] = [
                        "id" => $purchaseId,
                        "number" => $purchaseNumber,
                        "total" => 0,
                    ];
                }
                $group = &$purchaseGroups[$groupKey];
                $sku = $d["item code"];
                $barcode = $d["barcode"] ?? "";
                $serial = $d["serial number"] ?? "";
                $name = $d["product name"];
                $brand = $d["brand"] ?? "";
                $unit = strtolower($d["unit"] ?: "pcs");
                $cost = max(0, (float) $d["cost price"]);
                $retail = max(0, (float) $d["retail price"]);
                $wholesale = max(0, (float) ($d["wholesale price"] ?? 0));
                $minQty = max(1, (int) ($d["wholesale min qty"] ?? 1));
                $quantity = max(0, (float) ($d["purchase quantity"] ?? 0));
                $reorder = max(0, (float) ($d["reorder level"] ?? 0));
                $status = in_array(
                    strtolower($d["status"] ?? "active"),
                    ["inactive", "0", "no"],
                    true,
                )
                    ? 0
                    : 1;
                $findExistingProduct->bind_param("s", $sku);
                $findExistingProduct->execute();
                $existingProduct = $findExistingProduct
                    ->get_result()
                    ->fetch_assoc();
                if ($existingProduct) {
                    $productId = (int) $existingProduct["product_id"];
                    $stockBefore = (float) $existingProduct["stock_qty"];
                    $stockAfter = $stockBefore + $quantity;
                    $averageCost =
                        $stockAfter > 0
                            ? ($stockBefore *
                                    (float) $existingProduct["cost_price"] +
                                    $quantity * $cost) /
                                $stockAfter
                            : $cost;
                    $updateExistingProduct->bind_param(
                        "isssssdddiddii",
                        $catId,
                        $barcode,
                        $serial,
                        $name,
                        $brand,
                        $unit,
                        $averageCost,
                        $retail,
                        $wholesale,
                        $minQty,
                        $stockAfter,
                        $reorder,
                        $status,
                        $productId,
                    );
                    $updateExistingProduct->execute();
                } else {
                    $stockBefore = 0;
                    $stockAfter = $quantity;
                    $insertProduct->bind_param(
                        "issssssdddiddi",
                        $catId,
                        $sku,
                        $barcode,
                        $serial,
                        $name,
                        $brand,
                        $unit,
                        $cost,
                        $retail,
                        $wholesale,
                        $minQty,
                        $quantity,
                        $reorder,
                        $status,
                    );
                    $insertProduct->execute();
                    $productId = $conn->insert_id;
                }
                $lineTotal = $quantity * $cost;
                $purchaseId = $group["id"];
                $insertItem->bind_param(
                    "iidddd",
                    $purchaseId,
                    $productId,
                    $quantity,
                    $quantity,
                    $cost,
                    $lineTotal,
                );
                $insertItem->execute();
                $note =
                    "Received from unified inventory import - " .
                    $group["number"];
                $log->bind_param(
                    "iiddddds",
                    $productId,
                    $uid,
                    $quantity,
                    $stockBefore,
                    $stockAfter,
                    $cost,
                    $lineTotal,
                    $note,
                );
                $log->execute();
                $group["total"] += $lineTotal;
                $count++;
                unset($group);
            }
            $finishPurchase = $conn->prepare(
                "UPDATE purchases SET subtotal=?,total_amount=?,status='received',received_by=?,received_at=NOW() WHERE purchase_id=?",
            );
            foreach ($purchaseGroups as $group) {
                $total = $group["total"];
                $purchaseId = $group["id"];
                $finishPurchase->bind_param(
                    "ddii",
                    $total,
                    $total,
                    $uid,
                    $purchaseId,
                );
                $finishPurchase->execute();
            }
            $findCat->close();
            $addCat->close();
            $findSupplierCode->close();
            $findSupplierName->close();
            $addSupplier->close();
            $addPurchase->close();
            $numberPurchase->close();
            $insertProduct->close();
            $findExistingProduct->close();
            $updateExistingProduct->close();
            $insertItem->close();
            $log->close();
            $finishPurchase->close();
            $conn->commit();
            unset($_SESSION["product_import_preview"]);
            header(
                "Location:" .
                    ($cashierMode
                        ? "../cashier_products.php?imported=" . $count
                        : "products.php?imported=" . $count),
            );
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            $msg = $e->getMessage();
            $msgType = "error";
        }
    }
}
$valid = 0;
$errors = 0;
$stockTotal = 0;
foreach ($preview as $r) {
    if ($r["errors"]) {
        $errors++;
    } else {
        $valid++;
        $stockTotal += (float) ($r["data"]["purchase quantity"] ?? 0);
    }
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bulk Product Import - Supun ERP</title><link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Lora:wght@600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"><style><?php
include "shared_style.php";
include "erp_style.php";
?>.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px}.step{padding:15px;background:#fff;border:1.5px solid var(--border);border-radius:var(--radius);display:flex;gap:10px}.step-num{width:30px;height:30px;display:grid;place-items:center;border-radius:50%;background:var(--primary);color:#fff;font-weight:900;flex:none}.upload-zone{padding:30px;text-align:center;border:2px dashed var(--border-dk);border-radius:10px;background:#fafcfd}.upload-zone i{font-size:34px;color:var(--green);margin-bottom:10px}.import-error{color:var(--red);font-size:10px;font-weight:900}.import-ok{color:var(--green);font-size:11px;font-weight:900}.template-fields{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.template-fields span{padding:4px 8px;border-radius:15px;background:var(--primary-lt);color:var(--primary);font-size:10px;font-weight:900}@media(max-width:750px){.steps{grid-template-columns:1fr}}</style></head><body>
<?php if (!$cashierMode) {
    include "shared_nav.php";
} ?><main class="main"<?php echo $cashierMode
    ? ' style="margin-left:0"'
    : ""; ?>><?php
if (!$cashierMode) {
    include "shared_topbar.php";
}
if (
    $cashierMode
): ?><div style="display:flex;justify-content:space-between;align-items:center;padding:14px 24px;background:#fff;border-bottom:1px solid #dbe2ea"><strong>Supun Group - Inventory Import</strong><a class="btn-secondary" href="../cashier_products.php"><i class="fa-solid fa-arrow-left"></i> Back to Product Management</a></div><?php endif;
?><div class="content"<?php echo $cashierMode
    ? ' style="max-width:1500px;margin:auto"'
    : ""; ?>><div class="page-header"><div><h1 class="page-title-h"><i class="fa-solid fa-file-arrow-up"></i> Import Inventory</h1><p class="page-sub">Create suppliers, products, purchases and received stock from one Excel workbook</p></div><div class="quick-links"><a class="btn-secondary" href="<?php echo $cashierMode
    ? "../cashier_products.php"
    : "products.php"; ?>"><i class="fa-solid fa-arrow-left"></i> <?php echo $cashierMode
    ? "Products"
    : "Inventory"; ?></a><a class="btn-primary" href="?template=1<?php echo $cashierMode
    ? "&amp;cashier_mode=1"
    : ""; ?>"><i class="fa-solid fa-file-excel"></i> Download Unified XLSX Template</a></div></div>
<?php if (
    $msg
): ?><div class="alert <?php echo $msgType; ?>"><?php echo htmlspecialchars(
    $msg,
); ?></div><?php endif; ?>
<div class="steps"><div class="step"><span class="step-num">1</span><div><strong>Download the Unified Template</strong><p class="muted">Supplier and inventory fields are included together.</p></div></div><div class="step"><span class="step-num">2</span><div><strong>Enter All Inventory</strong><p class="muted">Repeat supplier details for each supplied product row.</p></div></div><div class="step"><span class="step-num">3</span><div><strong>Preview and Import</strong><p class="muted">One confirmation creates the supplier, purchase, product and stock records.</p></div></div></div>
<?php if (
    !$preview
): ?><section class="card"><div class="card-head"><h4><i class="fa-solid fa-cloud-arrow-up"></i> Upload Product Workbook</h4><span class="count-badge">XLSX or CSV</span></div><form method="post" enctype="multipart/form-data" class="erp-form"><div class="upload-zone"><i class="fa-solid fa-file-excel"></i><h3>Select the completed product template</h3><p class="muted" style="margin:6px 0 15px">Maximum 5 MB. Do not rename or remove template columns.</p><input class="inp" type="file" name="product_file" accept=".xlsx,.csv" required></div><div class="template-fields"><?php foreach (
    productTemplateHeaders()
    as $h
): ?><span><?php echo htmlspecialchars(
    $h,
); ?></span><?php endforeach; ?></div><button class="btn-primary" name="preview_import" style="margin-top:15px"><i class="fa-solid fa-magnifying-glass"></i> Validate and Preview Products</button></form></section>
<?php else: ?><div class="erp-stats"><div class="erp-stat green"><span>Valid Inventory Lines</span><strong><?php echo $valid; ?></strong></div><div class="erp-stat <?php echo $errors
    ? "amber"
    : "green"; ?>"><span>Rows With Errors</span><strong><?php echo $errors; ?></strong></div><div class="erp-stat"><span>Purchase Quantity</span><strong><?php echo number_format(
    $stockTotal,
    0,
); ?></strong></div><div class="erp-stat"><span>Suppliers</span><strong><?php echo count(
    array_unique(
        array_map(fn($r) => $r["data"]["supplier name"] ?? "", $preview),
    ),
); ?></strong></div></div>
<section class="card table-card-full">
<div class="card-head"><h4><i class="fa-solid fa-table-list"></i> Unified Inventory Import Preview</h4><form method="post"><button class="btn-small" name="clear_preview"><i class="fa-solid fa-rotate-left"></i> Upload Another File</button></form></div>
<div class="tbl-wrap"><table style="min-width:1550px"><thead><tr><th>Line</th><th>Supplier</th><th>Invoice</th><th>Item Code</th><th>Product Name</th><th>Brand</th><th>Category</th><th>Barcode</th><th>Unit</th><th>Cost</th><th>Retail</th><th>Wholesale</th><th>Purchase Qty</th><th>Reorder</th><th>Validation</th></tr></thead><tbody>
<?php foreach ($preview as $r):
    $d = $r["data"]; ?><tr><td><?php echo (int) $r[
    "line"
]; ?></td><td><strong><?php echo htmlspecialchars(
    $d["supplier name"] ?? "",
); ?></strong><div class="muted"><?php echo htmlspecialchars(
    $d["supplier code"] ?? "",
); ?></div></td><td><?php echo htmlspecialchars(
    $d["supplier invoice"] ?? "-",
); ?></td><td><strong><?php echo htmlspecialchars(
    $d["item code"] ?? "",
); ?></strong></td><td><?php echo htmlspecialchars(
    $d["product name"] ?? "",
); ?></td><td><?php echo htmlspecialchars(
    $d["brand"] ?? "-",
); ?></td><td><?php echo htmlspecialchars(
    $d["category"] ?? "",
); ?></td><td><?php echo htmlspecialchars(
    $d["barcode"] ?? "-",
); ?></td><td><?php echo htmlspecialchars(
    $d["unit"] ?? "pcs",
); ?></td><td class="money">Rs. <?php echo number_format(
    (float) ($d["cost price"] ?? 0),
    2,
); ?></td><td class="money">Rs. <?php echo number_format(
    (float) ($d["retail price"] ?? 0),
    2,
); ?></td><td class="money">Rs. <?php echo number_format(
    (float) ($d["wholesale price"] ?? 0),
    2,
); ?></td><td><?php echo number_format(
    (float) ($d["purchase quantity"] ?? 0),
    0,
); ?></td><td><?php echo number_format(
    (float) ($d["reorder level"] ?? 0),
    0,
); ?></td><td><?php if (
    $r["errors"]
): ?><span class="import-error"><?php echo htmlspecialchars(
    implode(", ", $r["errors"]),
); ?></span><?php else: ?><span class="import-ok"><i class="fa-solid fa-circle-check"></i> <?php echo !empty(
    $d["_existing_product_id"]
)
    ? "Existing item — stock will increase"
    : "New item — ready"; ?></span><?php endif; ?></td></tr><?php
endforeach; ?></tbody></table></div></section>
<section class="card" style="margin-top:18px"><div class="card-body"><p class="muted">This single import creates suppliers, products, received purchase records, inventory quantities and stock history. Missing categories and suppliers are created automatically.</p><form method="post"><button class="btn-primary" name="confirm_import" style="margin-top:12px" <?php echo $errors
    ? 'disabled title="Correct the highlighted rows first"'
    : ""; ?> onclick="return confirm('Create suppliers, products, purchases and received stock from all validated rows?')"><i class="fa-solid fa-boxes-packing"></i> Import Complete Inventory</button></form></div></section><?php endif; ?>
</div></main></body></html>
