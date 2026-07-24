<?php
session_start();
include "db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION["cart"])) {
    die("Cart is empty");
}

$user_id = (int) $_SESSION["user_id"];

/* -----------------------------
   GET FORM DATA
------------------------------ */
$order_type = isset($_POST["order_type"])
    ? trim($_POST["order_type"])
    : "retail";
$payment_method = isset($_POST["payment_method"])
    ? trim($_POST["payment_method"])
    : "Cash";
$cash_given = isset($_POST["cash_given"]) ? (float) $_POST["cash_given"] : 0;
$apply_service_charge =
    isset($_POST["apply_service_charge"]) &&
    $_POST["apply_service_charge"] === "1";
$apply_packaging_fee =
    isset($_POST["apply_packaging_fee"]) &&
    $_POST["apply_packaging_fee"] === "1";
$packaging_fee = $apply_packaging_fee
    ? max(0, round((float) ($_POST["packaging_fee"] ?? 0), 2))
    : 0;

$allowed_order_types = ["retail", "wholesale"];
$allowed_payment_methods = ["Cash", "Card", "QR", "Bank Transfer", "Cheque"];

if (!in_array($order_type, $allowed_order_types)) {
    die("Invalid order type.");
}

if (!in_array($payment_method, $allowed_payment_methods)) {
    die("Invalid payment method.");
}

/* -----------------------------
   CALCULATE TOTALS
------------------------------ */
$subtotal = 0;
foreach ($_SESSION["cart"] as $item) {
    $subtotal += $item["price"] * $item["quantity"];
}

$discount = 0;
$service_charge = $apply_service_charge ? round($subtotal * 0.1, 2) : 0;
$total_amount = $subtotal + $service_charge + $packaging_fee - $discount;
$balance = $cash_given - $total_amount;

/* -----------------------------
   VALIDATIONS
------------------------------ */
if ($payment_method === "Cash" && $cash_given < $total_amount) {
    die("Cash given is less than total amount.");
}

if ($payment_method !== "Cash") {
    $cash_given = $total_amount;
    $balance = 0;
}

$payment_status = "paid";
$sync_status = 0;

/* -----------------------------
   START TRANSACTION
------------------------------ */
$conn->begin_transaction();

try {
    $sql = "INSERT INTO orders (
                table_id, user_id, order_type, subtotal, discount, service_charge, packaging_fee, total_amount,
                payment_status, sync_status, payment_method, cash_given, balance
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception(
            "Prepare failed for orders insert: " . $conn->error,
        );
    }

    $table_id = null;

    $stmt->bind_param(
        "iisdddddsisdd",
        $table_id,
        $user_id,
        $order_type,
        $subtotal,
        $discount,
        $service_charge,
        $packaging_fee,
        $total_amount,
        $payment_status,
        $sync_status,
        $payment_method,
        $cash_given,
        $balance,
    );

    if (!$stmt->execute()) {
        throw new Exception("Order insert failed: " . $stmt->error);
    }

    $order_id = $conn->insert_id;
    $stmt->close();

    /* -----------------------------
       INSERT ORDER ITEMS
    ------------------------------ */
    $item_sql = "INSERT INTO order_items (
                    order_id,
                    product_id,
                    custom_item_name,
                    quantity,
                    price,
                    unit_price,
                    cost_price,
                    item_type,
                    line_total
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $item_stmt = $conn->prepare($item_sql);
    if (!$item_stmt) {
        throw new Exception(
            "Prepare failed for order items insert: " . $conn->error,
        );
    }

    foreach ($_SESSION["cart"] as $item) {
        $product_id =
            isset($item["product_id"]) && $item["product_id"] !== null
                ? (int) $item["product_id"]
                : null;

        $custom_item_name =
            isset($item["custom_item_name"]) && $item["custom_item_name"] !== ""
                ? trim($item["custom_item_name"])
                : null;

        $qty = (int) $item["quantity"];
        $price = (float) $item["price"];
        $cost_price = 0.0;
        $item_type = $product_id === null ? "manual" : "product";
        if ($product_id !== null) {
            $cost_q = $conn->prepare(
                "SELECT cost_price FROM products WHERE product_id=? LIMIT 1",
            );
            $cost_q->bind_param("i", $product_id);
            $cost_q->execute();
            $cost_row = $cost_q->get_result()->fetch_assoc();
            $cost_price = (float) ($cost_row["cost_price"] ?? 0);
            $cost_q->close();
        }
        $line_total = $qty * $price;

        $item_stmt->bind_param(
            "iisidddsd",
            $order_id,
            $product_id,
            $custom_item_name,
            $qty,
            $price,
            $price,
            $cost_price,
            $item_type,
            $line_total,
        );

        if (!$item_stmt->execute()) {
            throw new Exception(
                "Order item insert failed: " . $item_stmt->error,
            );
        }
    }

    $item_stmt->close();

    $conn->commit();

    $_SESSION["cart"] = [];

    header("Location: print_bill.php?order_id=" . $order_id);
    exit();
} catch (Exception $e) {
    $conn->rollback();
    die("Error: " . $e->getMessage());
}
?>
