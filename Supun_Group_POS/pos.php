<?php
session_start();
include 'db.php';
require_once 'includes/advance_accounts.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

ensureAdvancePaymentSchema($conn);

$user_id = (int) $_SESSION["user_id"];
$admin_error = "";
$pay_error = isset($_GET["pay_error"]) ? 1 : 0;
$stock_error = isset($_GET["stock_error"]) ? 1 : 0;
$advance_error = isset($_GET['advance_error']) ? 1 : 0;
$advance_created = isset($_GET['advance_created']) ? 1 : 0;

function esc($conn, $value) {
    return $conn->real_escape_string(trim($value));
}

/* =========================================================
   ADMIN LOGIN
========================================================= */
if (isset($_POST["admin_login_submit"])) {
    $au = trim($_POST["admin_username"] ?? "");
    $ap = $_POST["admin_password"] ?? "";

    $au_safe = $conn->real_escape_string($au);
    $ar = $conn->query("SELECT * FROM users WHERE username='$au_safe' AND role='admin' AND status=1 LIMIT 1");

    if ($ar && $ar->num_rows > 0) {
        $admin_row = $ar->fetch_assoc();

        if (password_verify($ap, $admin_row["password"])) {
            $_SESSION["user_id"]   = $admin_row["user_id"];
            $_SESSION["full_name"] = $admin_row["full_name"];
            $_SESSION["role"]      = "admin";
            header("Location: dashboard.php");
            exit;
        } else {
            $admin_error = "Invalid username or password.";
        }
    } else {
        $admin_error = "Invalid username or password.";
    }
}

/* =========================================================
   CREATE NEW ORDER — QUICK (no modal, default dine-in)
========================================================= */
if (isset($_POST["quick_order"])) {
    $sql = "INSERT INTO orders (
                table_id, user_id, order_type, customer_name,
                subtotal, discount, total_amount,
                payment_status, sync_status, created_at, paid_at,
                payment_method, cash_given, balance, order_status
            ) VALUES (
                NULL, $user_id, 'retail', '',
                0.00, 0.00, 0.00,
                'pending', 0, NOW(), NULL,
                'Cash', 0.00, 0.00, 'open'
            )";

    if ($conn->query($sql)) {
        $new_order_id  = $conn->insert_id;
        $order_number  = 'ORD-' . str_pad($new_order_id, 5, '0', STR_PAD_LEFT);
        $on_safe       = esc($conn, $order_number);
        $conn->query("UPDATE orders SET order_number='$on_safe' WHERE order_id=$new_order_id");
        header("Location: pos.php?order_id=" . $new_order_id);
        exit;
    }
}

/* =========================================================
   CREATE NEW ORDER (modal — full options)
========================================================= */
if (isset($_POST["create_order"])) {
    $order_type    = trim($_POST["new_order_type"] ?? "retail");
    $customer_name = trim($_POST["customer_name"] ?? "");
    $customer_id   = (int)($_POST["customer_id"] ?? 0);
    $table_id      = isset($_POST["table_id"]) && $_POST["table_id"] !== "" ? (int)$_POST["table_id"] : null;

    $allowed_order_types = ["retail", "wholesale"];
    if (!in_array($order_type, $allowed_order_types)) $order_type = "retail";

    if ($customer_id > 0) {
        $customer_q = $conn->query("SELECT customer_name FROM customer_accounts WHERE customer_id=$customer_id AND status=1 LIMIT 1");
        if ($customer_q && $customer_q->num_rows > 0) $customer_name = $customer_q->fetch_assoc()['customer_name'];
        else $customer_id = 0;
    }
    $customer_name_safe = esc($conn, $customer_name);

    $sql = "INSERT INTO orders (
                table_id, user_id, order_type, customer_name, customer_id,
                subtotal, discount, total_amount,
                payment_status, sync_status, created_at, paid_at,
                payment_method, cash_given, balance, order_status
            ) VALUES (
                " . ($table_id === null ? "NULL" : $table_id) . ",
                $user_id,
                '$order_type',
                '$customer_name_safe', " . ($customer_id > 0 ? $customer_id : "NULL") . ",
                0.00, 0.00, 0.00,
                'pending', 0, NOW(), NULL,
                'Cash', 0.00, 0.00, 'open'
            )";

    if ($conn->query($sql)) {
        $new_order_id  = $conn->insert_id;
        $order_number  = 'ORD-' . str_pad($new_order_id, 5, '0', STR_PAD_LEFT);
        $on_safe       = esc($conn, $order_number);
        $conn->query("UPDATE orders SET order_number='$on_safe' WHERE order_id=$new_order_id");
        header("Location: pos.php?order_id=" . $new_order_id);
        exit;
    } else {
        die("Create order error: " . $conn->error);
    }
}

/* =========================================================
   GET CURRENT ORDER ID
========================================================= */
$current_order_id = isset($_GET["order_id"]) ? (int)$_GET["order_id"] : 0;

// Advance-held orders stay out of the normal POS workspace and are reopened
// only through the explicit Complete Payment action.
if ($current_order_id > 0 && !isset($_GET['settle'])) {
    $held_q = $conn->query("SELECT 1 FROM advance_payment_transactions WHERE order_id=$current_order_id AND transaction_type='deposit' AND remaining_amount>0 LIMIT 1");
    if ($held_q && $held_q->num_rows > 0) {
        header('Location: pos.php'); exit;
    }
}

/* =========================================================
   ORDER TYPE IS LOCKED AFTER CREATION
========================================================= */
if (isset($_GET["set_order_type"]) && $current_order_id > 0) {
    $is_ajax = isset($_GET["ajax"]) && $_GET["ajax"] == "1";

    if ($is_ajax) {
        header("Content-Type: application/json");
        echo json_encode([
            "success" => false,
            "message" => "Sale type is locked after the order is created."
        ]);
        exit;
    }

    header("Location: pos.php?order_id=" . $current_order_id);
    exit;
}

/* =========================================================
   ADD PRODUCT TO ORDER - AJAX FRIENDLY
========================================================= */
if (isset($_GET["add"]) && $current_order_id > 0) {
    $product_id = (int) $_GET["add"];
    $is_ajax = isset($_GET["ajax"]) && $_GET["ajax"] == "1";

    $response = [
        "success" => false,
        "message" => "Could not add item.",
        "grand_total" => 0,
        "cart_count" => 0,
        "item_name" => ""
    ];

    $order_check = $conn->query("
        SELECT * FROM orders
        WHERE order_id=$current_order_id
        AND order_status='open'
        LIMIT 1
    ");

    if ($order_check && $order_check->num_rows > 0) {
        $q = $conn->query("
            SELECT * FROM products
            WHERE product_id=$product_id
            AND status=1
            LIMIT 1
        ");

        if ($q && $q->num_rows > 0) {
            $p = $q->fetch_assoc();

            if ((float)$p['stock_qty'] < 1) {
                $response['message'] = 'This product is out of stock.';
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode($response);
                    exit;
                }
                header('Location: pos.php?order_id=' . $current_order_id . '&stock_error=1');
                exit;
            }

            $price = ($current_order_id > 0 && ($order_check->fetch_assoc()['order_type'] ?? 'retail') === 'wholesale' && (float)$p['wholesale_price'] > 0) ? (float)$p['wholesale_price'] : (float)$p["price"];
            $cost_price = (float)($p['cost_price'] ?? 0);
            $item_name = $p["product_name"];

            $item_check = $conn->query("
                SELECT * FROM order_items
                WHERE order_id=$current_order_id
                AND product_id=$product_id
                AND item_type='product'
                LIMIT 1
            ");

            $item_ok = false;
            try {
            if ($item_check && $item_check->num_rows > 0) {
                $item = $item_check->fetch_assoc();
                $new_qty = (int)$item["quantity"] + 1;
                $effective_price = (int)($item["price_overridden"] ?? 0) === 1
                    ? (float)$item["price"]
                    : $price;
                $new_lt = $effective_price * $new_qty;

                $item_ok = $conn->query("
                    UPDATE order_items
                    SET quantity=$new_qty, price=$effective_price,
                        unit_price=$effective_price, line_total=$new_lt
                    WHERE order_item_id=" . (int)$item["order_item_id"]
                );
            } else {
                $item_ok = $conn->query("
                    INSERT INTO order_items
                    (order_id, product_id, custom_item_name, quantity, price, unit_price, cost_price, item_type, line_total)
                    VALUES
                    ($current_order_id, $product_id, NULL, 1, $price, $price, $cost_price, 'product', $price)
                ");
            }
            } catch (Throwable $e) {
                $item_ok = false;
                $response['message'] = 'Not enough stock is available.';
            }

            $sum_q = $item_ok ? $conn->query("
                SELECT
                    COALESCE(SUM(line_total), 0) AS grand_total,
                    COALESCE(SUM(quantity), 0) AS cart_count
                FROM order_items
                WHERE order_id=$current_order_id
            ") : false;

            if ($sum_q && $sum_q->num_rows > 0) {
                $sum = $sum_q->fetch_assoc();

                $response["success"] = true;
                $response["message"] = "Item added.";
                $response["grand_total"] = (float)$sum["grand_total"];
                $response["cart_count"] = (int)$sum["cart_count"];
                $response["item_name"] = $item_name;
            }
        }
    }

    if ($is_ajax) {
        header("Content-Type: application/json");
        echo json_encode($response);
        exit;
    }

    $back_url = "pos.php?order_id=" . $current_order_id;

    if (isset($_GET["category"]) && (int)$_GET["category"] > 0) {
        $back_url .= "&category=" . (int)$_GET["category"];
    }

    if (isset($_GET["search"]) && trim($_GET["search"]) !== "") {
        $back_url .= "&search=" . urlencode(trim($_GET["search"]));
    }

    header("Location: " . $back_url);
    exit;
}

/* =========================================================
   ADD MANUAL ITEM
========================================================= */
if (isset($_POST["add_manual_item"])) {
    $order_id = (int)($_POST["order_id"] ?? 0);
    $mn       = trim($_POST["manual_item_name"] ?? "");
    $mp       = (float)($_POST["manual_item_price"] ?? 0);
    $mq       = (int)($_POST["manual_item_qty"] ?? 1);

    if ($order_id > 0 && $mn !== "" && $mp > 0 && $mq > 0) {
        $mn_safe    = esc($conn, $mn);
        $line_total = $mp * $mq;
        $conn->query("INSERT INTO order_items (order_id,product_id,custom_item_name,quantity,price,item_type,line_total) VALUES ($order_id,NULL,'$mn_safe',$mq,$mp,'manual',$line_total)");
    }

    header("Location: pos.php?order_id=" . $order_id);
    exit;
}

/* =========================================================
   EDIT / RESTORE ORDER-LINE PRICE
========================================================= */
if (isset($_POST["update_line_price"]) && $current_order_id > 0) {
    $order_item_id = (int)($_POST["order_item_id"] ?? 0);
    $new_price = round((float)($_POST["new_unit_price"] ?? 0), 2);

    if ($order_item_id > 0 && $new_price > 0) {
        $stmt = $conn->prepare("
            UPDATE order_items oi
            INNER JOIN orders o ON o.order_id = oi.order_id
            SET oi.price = ?, oi.unit_price = ?,
                oi.line_total = oi.quantity * ?, oi.price_overridden = 1
            WHERE oi.order_item_id = ? AND oi.order_id = ? AND o.order_status = 'open'
        ");
        $stmt->bind_param("dddii", $new_price, $new_price, $new_price, $order_item_id, $current_order_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: pos.php?order_id=" . $current_order_id);
    exit;
}


if (isset($_GET["restore_price"]) && $current_order_id > 0) {
    $order_item_id = (int)$_GET["restore_price"];
    $conn->query("
        UPDATE order_items oi
        INNER JOIN orders o ON o.order_id = oi.order_id
        INNER JOIN products p ON p.product_id = oi.product_id
        SET oi.price = CASE
                WHEN o.order_type = 'wholesale' AND p.wholesale_price > 0 THEN p.wholesale_price
                ELSE p.price
            END,
            oi.unit_price = CASE
                WHEN o.order_type = 'wholesale' AND p.wholesale_price > 0 THEN p.wholesale_price
                ELSE p.price
            END,
            oi.line_total = oi.quantity * CASE
                WHEN o.order_type = 'wholesale' AND p.wholesale_price > 0 THEN p.wholesale_price
                ELSE p.price
            END,
            oi.price_overridden = 0
        WHERE oi.order_item_id = $order_item_id
          AND oi.order_id = $current_order_id
          AND o.order_status = 'open'
    ");
    header("Location: pos.php?order_id=" . $current_order_id);
    exit;
}

/* =========================================================
   INCREASE / DECREASE / REMOVE / CLEAR
========================================================= */
if (isset($_GET["inc"]) && $current_order_id > 0) {
    $oid = (int)$_GET["inc"];
    $r   = $conn->query("SELECT oi.quantity,oi.price,p.stock_qty FROM order_items oi JOIN orders o ON oi.order_id=o.order_id LEFT JOIN products p ON p.product_id=oi.product_id WHERE oi.order_item_id=$oid AND oi.order_id=$current_order_id AND o.order_status='open' LIMIT 1");
    if ($r && $r->num_rows > 0) {
        $row   = $r->fetch_assoc();
        if ($row['stock_qty'] !== null && (float)$row['stock_qty'] < 1) {
            header("Location: pos.php?order_id=" . $current_order_id . "&stock_error=1"); exit;
        }
        $nq    = (int)$row["quantity"] + 1;
        $price = (float)$row["price"];
        $conn->query("UPDATE order_items SET quantity=$nq, line_total=" . ($price * $nq) . " WHERE order_item_id=$oid");
    }
    header("Location: pos.php?order_id=" . $current_order_id); exit;
}

if (isset($_GET["dec"]) && $current_order_id > 0) {
    $oid = (int)$_GET["dec"];
    $r   = $conn->query("SELECT oi.quantity,oi.price FROM order_items oi JOIN orders o ON oi.order_id=o.order_id WHERE oi.order_item_id=$oid AND oi.order_id=$current_order_id AND o.order_status='open' LIMIT 1");
    if ($r && $r->num_rows > 0) {
        $row   = $r->fetch_assoc();
        $qty   = (int)$row["quantity"];
        $price = (float)$row["price"];
        if ($qty > 1) {
            $nq = $qty - 1;
            $conn->query("UPDATE order_items SET quantity=$nq, line_total=" . ($price * $nq) . " WHERE order_item_id=$oid");
        } else {
            $conn->query("DELETE FROM order_items WHERE order_item_id=$oid");
        }
    }
    header("Location: pos.php?order_id=" . $current_order_id); exit;
}

if (isset($_GET["remove"]) && $current_order_id > 0) {
    $conn->query("DELETE FROM order_items WHERE order_item_id=" . (int)$_GET["remove"] . " AND order_id=$current_order_id");
    header("Location: pos.php?order_id=" . $current_order_id); exit;
}

if (isset($_GET["clear"]) && $current_order_id > 0) {
    $conn->query("DELETE oi FROM order_items oi JOIN orders o ON oi.order_id=o.order_id WHERE oi.order_id=$current_order_id AND o.order_status='open'");
    header("Location: pos.php?order_id=" . $current_order_id); exit;
}

/* =========================================================
   CREATE CUSTOMER ADVANCE FROM CHECKOUT
========================================================= */
if (isset($_POST['add_checkout_installment'])) {
    $order_id=(int)($_POST['order_id']??0); $customer_id=(int)($_POST['checkout_customer_id']??0);
    $amount=round((float)($_POST['installment_amount']??0),2); $method=trim($_POST['installment_method']??'Cash');
    $allowed_advance_methods=['Cash','Card','QR','Bank Transfer','Cheque'];
    if(!in_array($method,$allowed_advance_methods,true)) $method='Cash';
    if($order_id<=0||$customer_id<=0||$amount<=0){header("Location: pos.php?order_id=$order_id&advance_error=1");exit;}
    $conn->begin_transaction();
    try{
        $customer_result=$conn->query("SELECT customer_name FROM customer_accounts WHERE customer_id=$customer_id AND status=1 FOR UPDATE");
        $customer=$customer_result?$customer_result->fetch_assoc():null;
        if(!$customer) throw new Exception('Customer account not found.');
        $receipt=nextAdvanceReceipt($conn);$uid=(int)$_SESSION['user_id'];$note='Additional installment received at POS';
        $stmt=$conn->prepare("INSERT INTO advance_payment_transactions (receipt_number,customer_id,order_id,transaction_type,amount,remaining_amount,settlement_status,settlement_due_date,payment_method,reference_note,created_by) VALUES (?,?,?,'deposit',?,?,'open',DATE_ADD(CURDATE(),INTERVAL 1 DAY),?,?,?)");
        $stmt->bind_param('siiddssi',$receipt,$customer_id,$order_id,$amount,$amount,$method,$note,$uid);
        if(!$stmt->execute()) throw new Exception($stmt->error);
        $transaction_id=$conn->insert_id;$stmt->close();
        $name_safe=esc($conn,$customer['customer_name']);
        $conn->query("UPDATE orders SET customer_id=$customer_id,customer_name='$name_safe' WHERE order_id=$order_id AND order_status='open'");
        $conn->commit();header("Location: print_advance.php?transaction_id=$transaction_id&return_order=$order_id");exit;
    }catch(Throwable $e){$conn->rollback();header("Location: pos.php?order_id=$order_id&advance_error=1");exit;}
}

if (isset($_POST['create_checkout_advance'])) {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $name = trim($_POST['advance_customer_name'] ?? '');
    $phone = trim($_POST['advance_customer_phone'] ?? '');
    $amount = round((float)($_POST['new_advance_amount'] ?? 0), 2);
    $method = trim($_POST['new_advance_method'] ?? 'Cash');
    $allowed_advance_methods = ['Cash','Card','QR','Bank Transfer','Cheque'];
    if (!in_array($method, $allowed_advance_methods, true)) $method = 'Cash';

    if ($order_id <= 0 || $name === '' || $amount <= 0) {
        header("Location: pos.php?order_id=$order_id&advance_error=1"); exit;
    }

    $conn->begin_transaction();
    try {
        $account = nextAccountNumber($conn);
        $stmt = $conn->prepare('INSERT INTO customer_accounts (account_number,customer_name,phone,advance_balance) VALUES (?,?,?,0)');
        $stmt->bind_param('sss', $account, $name, $phone);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $customer_id = $conn->insert_id; $stmt->close();

        $receipt = nextAdvanceReceipt($conn); $uid = (int)$_SESSION['user_id']; $note = 'Advance received at POS checkout';
        $stmt = $conn->prepare("INSERT INTO advance_payment_transactions (receipt_number,customer_id,order_id,transaction_type,amount,remaining_amount,settlement_status,settlement_due_date,payment_method,reference_note,created_by) VALUES (?,?,?,'deposit',?,?,'open',DATE_ADD(CURDATE(),INTERVAL 1 DAY),?,?,?)");
        $stmt->bind_param('siiddssi', $receipt, $customer_id, $order_id, $amount, $amount, $method, $note, $uid);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $advance_transaction_id = $conn->insert_id; $stmt->close();

        $name_safe = esc($conn, $name);
        if (!$conn->query("UPDATE orders SET customer_id=$customer_id,customer_name='$name_safe' WHERE order_id=$order_id AND order_status='open'") || $conn->affected_rows !== 1) {
            throw new Exception('Open order was not found.');
        }
        $conn->commit();
        header("Location: print_advance.php?transaction_id=$advance_transaction_id&return_order=$order_id"); exit;
    } catch (Throwable $e) {
        $conn->rollback();
        header("Location: pos.php?order_id=$order_id&advance_error=1"); exit;
    }
}

/* =========================================================
   PAY ORDER
========================================================= */
if (isset($_POST["pay_order"])) {
    $order_id       = (int)($_POST["order_id"] ?? 0);
    $order_type     = "retail";
    $payment_method = trim($_POST["payment_method"] ?? "Cash");
    $cash_given     = (float)($_POST["cash_given"] ?? 0);
    $discount_type  = trim($_POST["discount_type"] ?? "fixed");
    $discount_value = max(0, (float)($_POST["discount_value"] ?? 0));
    $apply_service_charge = isset($_POST["apply_service_charge"]) && $_POST["apply_service_charge"] === "1";
    $apply_packaging_fee = isset($_POST["apply_packaging_fee"]) && $_POST["apply_packaging_fee"] === "1";
    $packaging_fee = $apply_packaging_fee ? max(0, round((float)($_POST["packaging_fee"] ?? 0), 2)) : 0;
    $apply_advance = isset($_POST['apply_advance']) && $_POST['apply_advance'] === '1';
    $requested_advance = $apply_advance ? max(0, round((float)($_POST['advance_to_use'] ?? 0), 2)) : 0.0;
    $selected_customer_id = (int)($_POST['checkout_customer_id'] ?? 0);

    $allowed_payment_methods = ["Cash", "Card", "QR", "Bank Transfer", "Cheque"];

    $type_q = $conn->query("SELECT order_type,customer_id FROM orders WHERE order_id=$order_id AND order_status='open' LIMIT 1");
    $customer_id = 0;
    if ($type_q && $type_q->num_rows > 0) {
        $order_payment_row = $type_q->fetch_assoc();
        $saved_type = $order_payment_row["order_type"] ?? "retail";
        $customer_id = (int)($order_payment_row['customer_id'] ?? 0);
        $order_type = $saved_type === "wholesale" ? "wholesale" : "retail";
    }
    if ($selected_customer_id > 0) $customer_id = $selected_customer_id;
    if (!in_array($payment_method, $allowed_payment_methods)) $payment_method = "Cash";

    // Re-apply the authoritative product price before calculating payment.
    // Manual/custom items are intentionally excluded because their price is typed by the cashier.
    $checkout_price_expression = $order_type === "wholesale"
        ? "CASE WHEN p.wholesale_price > 0 THEN p.wholesale_price ELSE p.price END"
        : "p.price";
    $conn->query("
        UPDATE order_items oi
        INNER JOIN products p ON p.product_id = oi.product_id
        SET oi.price = $checkout_price_expression,
            oi.unit_price = $checkout_price_expression,
            oi.line_total = oi.quantity * ($checkout_price_expression)
        WHERE oi.order_id = $order_id
          AND oi.product_id IS NOT NULL
          AND oi.price_overridden = 0
    ");

    $sum_q    = $conn->query("SELECT SUM(line_total) AS subtotal FROM order_items WHERE order_id=$order_id");
    $subtotal = 0;
    if ($sum_q && $sum_q->num_rows > 0) { $subtotal = (float)($sum_q->fetch_assoc()["subtotal"] ?? 0); }

    $service_charge = $apply_service_charge ? round($subtotal * 0.10, 2) : 0;
    if ($discount_type === "percentage") {
        $discount_value = min(100, $discount_value);
        $discount = round($subtotal * ($discount_value / 100), 2);
    } else {
        $discount_type = "fixed";
        $discount = round($discount_value, 2);
    }
    $discount = min($subtotal + $service_charge + $packaging_fee, $discount);
    $total_amount = max(0, $subtotal + $service_charge + $packaging_fee - $discount);
    $advance_used = 0.0;
    if ($requested_advance > 0 && $customer_id > 0) {
        $aq = $conn->query("SELECT COALESCE(SUM(remaining_amount),0) advance_balance FROM advance_payment_transactions WHERE customer_id=$customer_id AND transaction_type='deposit' AND order_id IS NULL AND remaining_amount>0");
        $available_advance = $aq ? (float)$aq->fetch_assoc()['advance_balance'] : 0;
        $advance_used = min($requested_advance, $available_advance, $total_amount);
    }
    $amount_due = max(0, $total_amount - $advance_used);
    if ($payment_method !== "Cash") $cash_given = $amount_due;
    $balance = $cash_given - $amount_due;

    if ($payment_method === "Cash" && $cash_given < $amount_due) {
        header("Location: pos.php?order_id=$order_id&pay_error=1"); exit;
    }

    $pm_safe = esc($conn, $advance_used >= $total_amount && $total_amount > 0 ? 'Credit' : $payment_method);
    $conn->begin_transaction();
    try {
        if ($customer_id > 0) {
            $customer_result = $conn->query("SELECT customer_name FROM customer_accounts WHERE customer_id=$customer_id AND status=1 FOR UPDATE");
            $customer_row = $customer_result ? $customer_result->fetch_assoc() : null;
            if (!$customer_row) throw new Exception('Selected customer advance account was not found.');
            $customer_name_safe = esc($conn, $customer_row['customer_name']);
            $conn->query("UPDATE orders SET customer_id=$customer_id,customer_name='$customer_name_safe' WHERE order_id=$order_id AND order_status='open'");
        }
        if ($advance_used > 0) {
            $lock = $conn->query("SELECT advance_balance FROM customer_accounts WHERE customer_id=$customer_id FOR UPDATE")->fetch_assoc();
            if ((float)$lock['advance_balance'] < $advance_used) throw new Exception('Customer advance balance changed. Please try again.');
            $stmt = $conn->prepare('UPDATE customer_accounts SET advance_balance=advance_balance-? WHERE customer_id=?');
            $stmt->bind_param('di', $advance_used, $customer_id); $stmt->execute(); $stmt->close();
            $to_allocate = $advance_used; $uid = (int)$_SESSION['user_id']; $note = 'Applied to POS order';
            $deposits = $conn->query("SELECT transaction_id,remaining_amount FROM advance_payment_transactions WHERE customer_id=$customer_id AND transaction_type='deposit' AND order_id IS NULL AND remaining_amount>0 ORDER BY created_at,transaction_id FOR UPDATE");
            while ($to_allocate > 0.0001 && $deposit = $deposits->fetch_assoc()) {
                $source_id = (int)$deposit['transaction_id'];
                $applied = min($to_allocate, (float)$deposit['remaining_amount']);
                $new_remaining = max(0, (float)$deposit['remaining_amount'] - $applied);
                $new_status = $new_remaining <= 0.0001 ? 'settled' : 'partial';
                $conn->query("UPDATE advance_payment_transactions SET remaining_amount=$new_remaining,settlement_status='$new_status' WHERE transaction_id=$source_id");
                $receipt = nextAdvanceReceipt($conn);
                $stmt = $conn->prepare("INSERT INTO advance_payment_transactions (receipt_number,customer_id,order_id,parent_transaction_id,transaction_type,amount,remaining_amount,settlement_status,payment_method,reference_note,created_by) VALUES (?,?,?,?,'sale_usage',?,0,'settled','Advance Account',?,?)");
                $stmt->bind_param('siiidsi', $receipt, $customer_id, $order_id, $source_id, $applied, $note, $uid);
                if (!$stmt->execute()) throw new Exception($stmt->error);
                $stmt->close(); $to_allocate -= $applied;
            }
            if ($to_allocate > 0.0001) throw new Exception('Advance deposit allocation could not be completed.');
        }
        $updated = $conn->query("UPDATE orders SET order_type='$order_type', subtotal=$subtotal, discount=$discount, service_charge=$service_charge, packaging_fee=$packaging_fee, total_amount=$total_amount, advance_used=$advance_used, payment_method='$pm_safe', cash_given=$cash_given, balance=$balance, order_status='paid', payment_status='paid', paid_at=NOW() WHERE order_id=$order_id AND order_status='open'");
        if (!$updated || $conn->affected_rows !== 1) throw new Exception('Order could not be completed.');
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback(); die('Payment error: '.htmlspecialchars($e->getMessage()));
    }
    header("Location: print_bill.php?order_id=" . $order_id); exit;
}

/* =========================================================
   FILTERS & DATA
========================================================= */
$filter_category = isset($_GET["category"]) ? (int)$_GET["category"] : 0;
$search          = isset($_GET["search"])   ? trim($_GET["search"])   : "";

$categories  = $conn->query("SELECT * FROM categories WHERE status=1 ORDER BY category_name ASC");
$tables      = false;

$product_sql = "SELECT * FROM products WHERE status=1";
if ($filter_category > 0)  $product_sql .= " AND category_id=$filter_category";
if ($search !== "")        $product_sql .= " AND product_name LIKE '%" . $conn->real_escape_string($search) . "%'";
$product_sql .= " ORDER BY product_name ASC";
$products = $conn->query($product_sql);

$open_orders = $conn->query("SELECT o.*,t.table_name FROM orders o LEFT JOIN restaurant_tables t ON o.table_id=t.table_id WHERE o.order_status='open' AND NOT EXISTS (SELECT 1 FROM advance_payment_transactions apt WHERE apt.order_id=o.order_id AND apt.transaction_type='deposit' AND apt.remaining_amount>0) ORDER BY o.order_id DESC");
$advance_customers = $conn->query("SELECT customer_id,account_number,customer_name,phone,advance_balance FROM customer_accounts WHERE status=1 ORDER BY customer_name");
$checkout_customers = $conn->query("SELECT c.customer_id,c.account_number,c.customer_name,c.phone,c.advance_balance,
    (SELECT t.transaction_id FROM advance_payment_transactions t WHERE t.customer_id=c.customer_id AND t.transaction_type='deposit' AND t.order_id IS NULL ORDER BY t.transaction_id DESC LIMIT 1) latest_advance_receipt_id
    FROM customer_accounts c WHERE c.status=1 ORDER BY c.customer_name");
$modal_customers = $conn->query("SELECT c.customer_id,c.account_number,c.customer_name,c.phone,c.advance_balance,(SELECT COALESCE(SUM(t.amount),0) FROM advance_payment_transactions t WHERE t.customer_id=c.customer_id AND t.order_id=$current_order_id AND t.transaction_type='deposit') installment_paid FROM customer_accounts c WHERE c.status=1 ORDER BY c.customer_name");

/* =========================================================
   LOAD CURRENT ORDER
========================================================= */
$current_order = null;
$order_items   = null;
$grand_total   = 0;
$cart_count    = 0;

if ($current_order_id > 0) {
    $cq = $conn->query("SELECT o.*,t.table_name,c.account_number,c.advance_balance FROM orders o LEFT JOIN restaurant_tables t ON o.table_id=t.table_id LEFT JOIN customer_accounts c ON c.customer_id=o.customer_id WHERE o.order_id=$current_order_id LIMIT 1");
    if ($cq && $cq->num_rows > 0) {
        $current_order = $cq->fetch_assoc();
        $order_items   = $conn->query("SELECT oi.*,p.product_name FROM order_items oi LEFT JOIN products p ON oi.product_id=p.product_id WHERE oi.order_id=$current_order_id ORDER BY oi.order_item_id ASC");
        if ($order_items) {
            while ($item = $order_items->fetch_assoc()) { $grand_total += (float)$item["line_total"]; $cart_count++; }
            mysqli_data_seek($order_items, 0);
        }
    }
}

$user_role = $_SESSION["role"] ?? "cashier";

// Count open orders for badge
$open_count_q = $conn->query("SELECT COUNT(*) AS cnt FROM orders o WHERE o.order_status='open' AND NOT EXISTS (SELECT 1 FROM advance_payment_transactions apt WHERE apt.order_id=o.order_id AND apt.transaction_type='deposit' AND apt.remaining_amount>0)");
$open_count   = ($open_count_q) ? (int)$open_count_q->fetch_assoc()["cnt"] : 0;

$display_total = number_format($grand_total, 2, '.', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Supun Group — Retail &amp; Wholesale POS</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Lora:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --primary: #0f766e;
    --primary-dk: #b84a1f;
    --primary-lt: #fef3ed;
    --accent: #1a7a5e;
    --accent-lt: #e8f5f0;
    --bg: #f2f4f8;
    --white: #ffffff;
    --border: #dde0ea;
    --border-dk: #c8ccd8;
    --text: #1c2038;
    --text-mid: #454a66;
    --text-muted: #8e94b0;
    --red: #dc2626;
    --red-lt: #fef2f2;
    --green: #15803d;
    --green-lt: #f0fdf4;
    --yellow: #b45309;
    --yellow-lt: #fffbeb;
    --blue: #2563eb;
    --blue-lt: #eff6ff;
    --shadow-sm: 0 1px 3px rgba(0,0,0,.07),0 1px 2px rgba(0,0,0,.04);
    --shadow-md: 0 4px 12px rgba(0,0,0,.09);
    --shadow-lg: 0 10px 40px rgba(0,0,0,.15);
    --radius: 12px;
    --radius-sm: 8px;
    --radius-xs: 5px;
}
*{box-sizing:border-box;margin:0;padding:0;}
html,body{height:100%;overflow:auto;}
body{font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);}
.topbar{background:var(--white);border-bottom:1.5px solid var(--border);min-height:58px;padding:8px 18px;display:flex;align-items:center;justify-content:space-between;box-shadow:var(--shadow-sm);gap:12px;flex-wrap:wrap;}
.brand{display:flex;align-items:center;gap:10px;}
.brand-logo{width:36px;height:36px;background:var(--primary);border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;box-shadow:0 3px 8px rgba(217,92,43,.35);}
.brand-logo img{width:100%;height:100%;object-fit:contain;border-radius:8px;background:#fff;padding:2px;}
.brand-text h1{font-family:'Lora',serif;font-size:17px;line-height:1.1;}
.brand-text small{display:block;font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.12em;font-weight:700;}
.topbar-right{display:flex;align-items:center;gap:7px;flex-wrap:wrap;}
.cashier-pill{display:flex;align-items:center;gap:7px;background:var(--bg);border:1.5px solid var(--border);border-radius:40px;padding:4px 12px 4px 4px;}
.avatar{width:26px;height:26px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;color:#fff;}
.cashier-pill .name{font-size:13px;font-weight:800;color:var(--text-mid);}
.role-badge{font-size:10px;background:var(--primary-lt);color:var(--primary);border-radius:40px;padding:2px 7px;font-weight:900;text-transform:uppercase;letter-spacing:.05em;}
.tb-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:var(--radius-sm);font-size:13px;font-weight:800;border:none;cursor:pointer;text-decoration:none;transition:all .16s;white-space:nowrap;font-family:'Nunito',sans-serif;}
.btn-new{background:var(--accent);color:#fff;}
.btn-new:hover{background:#15694f;}
.btn-quick{background:var(--primary);color:#fff;font-size:14px;padding:9px 18px;}
.btn-quick:hover{background:var(--primary-dk);}
.btn-owner{background:#1c2038;color:#fff;}
.btn-logout{background:var(--bg);color:var(--text-mid);border:1.5px solid var(--border);}
.pos-body{display:grid;grid-template-columns:270px 1fr 380px;gap:0;min-height:calc(100vh - 58px);}
.orders-panel{background:var(--white);border-right:1.5px solid var(--border);display:flex;flex-direction:column;}
.op-head{padding:13px 14px;border-bottom:1.5px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.op-head h3{font-size:13px;font-weight:900;display:flex;align-items:center;gap:7px;}
.op-badge{background:var(--primary);color:#fff;font-size:10px;font-weight:900;padding:2px 8px;border-radius:40px;}
.qs-banner{margin:10px;background:linear-gradient(135deg,var(--primary),var(--primary-dk));border-radius:var(--radius);padding:14px 14px 12px;color:#fff;text-align:center;}
.qs-banner p{font-size:12px;font-weight:700;margin-bottom:9px;opacity:.88;}
.qs-btn{display:inline-flex;align-items:center;gap:6px;background:#fff;color:var(--primary);border:none;border-radius:var(--radius-sm);padding:9px 16px;font-size:13px;font-weight:900;cursor:pointer;font-family:'Nunito',sans-serif;width:100%;justify-content:center;}
.qs-btn:hover{background:#fef3ed;}
.orders-list{padding:8px;overflow-y:auto;flex:1;}
.order-card{display:block;text-decoration:none;color:inherit;background:var(--bg);border:1.5px solid var(--border);border-radius:var(--radius);padding:11px 12px;margin-bottom:8px;transition:.16s;position:relative;}
.order-card:hover{border-color:var(--accent);background:var(--accent-lt);}
.order-card.active{background:var(--blue-lt);border-color:var(--blue);}
.oc-top{display:flex;justify-content:space-between;margin-bottom:5px;gap:6px;align-items:center;}
.oc-no{font-size:13px;font-weight:900;}
.oc-status{font-size:10px;font-weight:900;background:var(--yellow-lt);color:var(--yellow);border:1px solid #fde68a;border-radius:30px;padding:2px 7px;text-transform:uppercase;}
.oc-meta{font-size:11px;color:var(--text-mid);display:flex;flex-direction:column;gap:2px;font-weight:700;}
.no-orders{text-align:center;color:var(--text-muted);font-size:13px;font-weight:700;padding:30px 16px;}
.left-panel{overflow-y:auto;padding:14px 16px;display:flex;flex-direction:column;gap:14px;max-height:calc(100vh - 58px);}
.card{background:var(--white);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-sm);}
.manual-card{padding:13px 16px;border-left:4px solid var(--yellow);}
.ch{display:flex;align-items:center;gap:8px;margin-bottom:11px;}
.ch-icon{width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:12px;}
.chi-y{background:var(--yellow-lt);color:var(--yellow);}
.chi-b{background:#eff6ff;color:#2563eb;}
.ch h3{font-size:13px;font-weight:900;}
.manual-form{display:grid;grid-template-columns:2fr 1fr 72px auto;gap:7px;align-items:center;}
.inp,.search-inp,.minp,.mselect,.cash-inp{background:var(--bg);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:9px 10px;font-size:13px;font-family:'Nunito',sans-serif;color:var(--text);width:100%;outline:none;font-weight:600;}
.inp:focus,.search-inp:focus,.minp:focus,.mselect:focus,.cash-inp:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(217,92,43,.1);}
.btn-manual,.btn-search,.btn-padd,.m-sub,.pay-btn{border:none;cursor:pointer;font-family:'Nunito',sans-serif;}
.btn-manual{background:var(--primary);color:#fff;border-radius:var(--radius-sm);padding:9px 14px;font-size:13px;font-weight:900;}
.menu-card{overflow:visible;}
.filter-area{padding:13px 16px 0;}
.filter-row{display:flex;gap:7px;margin-bottom:9px;}
.sw{flex:1;position:relative;}
.sw i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:12px;}
.search-inp{padding-left:30px;}
.btn-search{background:var(--primary);color:#fff;border-radius:var(--radius-sm);padding:9px 14px;font-size:13px;font-weight:800;display:flex;align-items:center;gap:5px;}
.cat-pills{display:flex;flex-wrap:wrap;gap:5px;padding-bottom:11px;border-bottom:1px solid var(--border);}
.cpill{text-decoration:none;padding:5px 11px;border-radius:40px;font-size:12px;font-weight:800;border:1.5px solid var(--border);color:var(--text-mid);background:var(--bg);display:flex;align-items:center;gap:4px;transition:.14s;}
.cpill:hover{border-color:var(--primary-dk);color:var(--primary);}
.cpill.active{background:var(--primary);border-color:var(--primary);color:#fff;}
.products-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:10px;padding:12px 16px 16px;}
.pcard{background:var(--bg);border:1.5px solid var(--border);border-radius:var(--radius);padding:13px 10px;text-align:center;cursor:pointer;transition:.17s;display:flex;flex-direction:column;gap:6px;min-height:150px;justify-content:center;position:relative;user-select:none;}
.pcard:hover{border-color:var(--primary);background:var(--primary-lt);transform:translateY(-2px);box-shadow:var(--shadow-md);}
.pcard:active{transform:translateY(0);box-shadow:none;}
.pcard.flash{animation:cardFlash .35s ease;}
@keyframes cardFlash{0%{background:var(--primary-lt);border-color:var(--primary);}100%{}}
.pcard.disabled{opacity:.65;}
.pcard-icon{width:44px;height:44px;background:var(--white);border:1.5px solid var(--border);border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:17px;color:var(--primary);margin:0 auto 3px;}
.pcard-name{font-size:13px;font-weight:800;line-height:1.3;}
.pcard-price{font-size:13px;font-weight:900;color:var(--primary);}
.pcard-sub{font-size:11px;font-weight:700;color:var(--text-muted);}
.pcard-stock{font-size:10px;font-weight:900;color:var(--green);margin-top:2px}.pcard-stock.low{color:var(--red)}
.pcard-badge{position:absolute;top:6px;right:7px;background:var(--primary);color:#fff;font-size:10px;font-weight:900;padding:1px 6px;border-radius:30px;display:none;}
.pcard:hover .pcard-badge{display:inline-block;}
.no-prods{grid-column:1/-1;text-align:center;padding:32px 18px;color:var(--text-muted);font-size:14px;font-weight:700;}
.right-panel{background:var(--white);border-left:1.5px solid var(--border);display:flex;flex-direction:column;overflow:hidden;}
.rp-form{display:flex;flex-direction:column;height:100%;min-height:0;}
.rp-head{padding:13px 16px 11px;border-bottom:1.5px solid var(--border);}
.rp-head h2{font-size:14px;font-weight:900;display:flex;align-items:center;gap:6px;}
.order-meta-box{margin-top:9px;background:var(--bg);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:9px 10px;display:grid;gap:4px;font-size:12px;color:var(--text-mid);font-weight:700;}
.count-badge{background:var(--primary);color:#fff;font-size:11px;font-weight:900;padding:2px 9px;border-radius:40px;display:inline-block;margin-top:7px;}
.cart-empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1;gap:12px;padding:30px;text-align:center;}
.cart-empty-state i{font-size:32px;color:var(--border-dk);}
.cart-empty-state p{font-size:14px;font-weight:800;color:var(--text-muted);}
.cart-empty-state small{font-size:12px;color:var(--text-muted);font-weight:600;}
.ot-wrap{padding:10px 16px;border-bottom:1.5px solid var(--border);}
.ot-lbl{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--text-muted);margin-bottom:6px;}
.ot-row{display:grid;grid-template-columns:1fr 1fr;background:var(--bg);border:1.5px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;}
.otb{padding:7px;font-size:13px;font-weight:800;border:none;background:transparent;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;gap:5px;font-family:'Nunito',sans-serif;}
.otb.active{background:var(--primary);color:#fff;}
.ot-selected{height:42px;border:1.5px solid #99f6e4;border-radius:var(--radius-sm);background:#f0fdfa;color:var(--primary);display:flex;align-items:center;justify-content:space-between;padding:0 12px;font-size:13px;font-weight:900;}
.ot-selected small{font-size:9px;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);background:#fff;border:1px solid var(--border);border-radius:20px;padding:3px 7px;}
.cart-scroll{flex:1;overflow-y:auto;padding:2px 16px;min-height:0;}
.empty-cart{display:flex;flex-direction:column;align-items:center;justify-content:center;height:110px;gap:7px;color:var(--text-muted);text-align:center;}
.ci{display:flex;align-items:center;gap:8px;padding:9px 0;border-bottom:1px solid var(--border);}
.ci-info{flex:1;}
.ci-name{font-size:13px;font-weight:800;}
.ci-price{font-size:12px;font-weight:700;color:var(--primary);margin-top:1px;}
.ci-price-line{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.price-edit{position:relative;z-index:2;flex:0 0 30px;border:1px solid #99f6e4;background:var(--primary-lt);color:var(--primary);width:30px;height:30px;border-radius:7px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:11px;}
.price-edit:hover{background:var(--primary);color:#fff;}
.override-chip{font-size:9px;font-weight:900;color:#92400e;background:#fef3c7;border:1px solid #fde68a;border-radius:20px;padding:1px 5px;}
.restore-price{font-size:9px;font-weight:900;color:var(--primary);text-decoration:none;}
.mchip{display:inline-block;font-size:9px;background:var(--yellow-lt);color:var(--yellow);border:1px solid #fde68a;padding:1px 5px;border-radius:40px;margin-left:3px;font-weight:900;}
.qc{display:flex;align-items:center;border:1.5px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;}
.qcb{width:28px;height:28px;background:var(--bg);border:none;color:var(--text-mid);font-size:13px;font-weight:700;display:flex;align-items:center;justify-content:center;text-decoration:none;}
.qcb:hover{background:var(--primary-lt);color:var(--primary);}
.qn{font-size:12px;font-weight:900;min-width:26px;text-align:center;border-left:1px solid var(--border);border-right:1px solid var(--border);height:28px;line-height:28px;}
.rm{width:28px;height:28px;background:var(--red-lt);border:1.5px solid #fecaca;border-radius:var(--radius-xs);color:var(--red);font-size:11px;display:flex;align-items:center;justify-content:center;text-decoration:none;}
.rm:hover{background:#fca5a5;}
.clear-bar{padding:6px 16px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;}
.btn-clear{background:none;border:1.5px solid var(--border);border-radius:var(--radius-sm);color:var(--text-muted);font-size:12px;font-weight:800;padding:5px 11px;cursor:pointer;text-decoration:none;display:flex;align-items:center;gap:4px;font-family:'Nunito',sans-serif;}
.btn-clear:hover{border-color:#fca5a5;color:var(--red);}
.pay-section{border-top:2px solid var(--border);padding:12px 16px 14px;background:#fafbfd;}
.total-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;}
.total-lbl{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);}
.total-amt{font-family:'Lora',serif;font-size:22px;font-weight:700;color:var(--primary);}
.service-row{display:flex;align-items:center;justify-content:space-between;gap:10px;background:var(--white);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:8px 10px;margin-bottom:10px;}
.service-check{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:900;color:var(--text-mid);cursor:pointer;user-select:none;}
.service-check input{width:16px;height:16px;accent-color:var(--primary);cursor:pointer;}
.service-amt{font-size:12px;font-weight:900;color:var(--primary);white-space:nowrap;}
.fee-input-wrap{position:relative;width:112px;flex-shrink:0;}
.fee-input-wrap span{position:absolute;left:8px;top:50%;transform:translateY(-50%);font-size:11px;font-weight:900;color:var(--text-muted);}
.fee-input{width:100%;padding:6px 7px 6px 31px;font-size:12px;font-weight:900;text-align:right;background:var(--white);}
.fee-input:disabled{background:var(--bg);color:var(--text-muted);cursor:not-allowed;}
.discount-row{display:block;padding:10px 11px;border-color:#99f6e4;background:#f0fdfa;}
.discount-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;}
.discount-head .service-check{color:var(--primary-dk);}
.discount-controls{display:flex;flex-direction:column;gap:9px;width:100%;}
.discount-type-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;width:100%;}
.discount-type-btn{height:38px;border:1.5px solid var(--border);border-radius:7px;background:#fff;color:var(--text-mid);font:800 11px 'Nunito',sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:.15s;}
.discount-type-btn:hover{border-color:var(--primary);color:var(--primary);}
.discount-type-btn.active{background:var(--primary);border-color:var(--primary);color:#fff;box-shadow:0 2px 7px rgba(15,118,110,.2);}
.discount-value-wrap{position:relative;width:100%;}
.discount-unit{position:absolute;left:12px;top:50%;transform:translateY(-50%);z-index:1;font-size:12px;font-weight:900;color:var(--primary);pointer-events:none;}
.discount-input{width:100%;height:42px;border:1.5px solid var(--border-dk);border-radius:7px;background:#fff;color:var(--text);font-family:'Nunito',sans-serif;font-size:15px;font-weight:900;outline:none;padding:0 12px 0 50px;text-align:right;}
.discount-input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(15,118,110,.12);}
.discount-summary{background:#ecfdf5;border:1px solid #99f6e4;border-radius:7px;padding:7px 10px;margin:-2px 0 10px;}
.advance-box{background:#fffaf5;border:1.5px solid #fdba74;border-radius:10px;padding:11px;margin:7px 0 10px;}
.advance-title{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:8px;color:#c2410c;font-size:13px;font-weight:900;}.advance-title small{color:var(--text-muted);font-size:9px;text-align:right;}
.advance-tabs{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:9px}.advance-tab{border:1px solid #fed7aa;background:#fff;color:#9a3412;border-radius:7px;padding:8px 4px;font-size:10px;font-weight:900;cursor:pointer}.advance-tab.active{background:#c2410c;color:#fff;border-color:#c2410c}
.advance-label{display:block;font-size:9px;font-weight:900;text-transform:uppercase;color:var(--text-mid);margin:6px 0 4px}.advance-control{width:100%;height:37px;border:1px solid #d0d5dd;border-radius:7px;background:#fff;padding:0 9px;font:inherit;font-size:12px;color:var(--text)}
.advance-balance-row,.advance-due{display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:10px;padding:7px 2px;color:var(--text-muted)}.advance-balance-row strong{color:var(--accent)}.advance-due{margin-top:7px;background:#ecfdf5;border-radius:7px;padding:8px;color:#047857;font-weight:800}.advance-due strong{font-size:13px}
.advance-money{display:flex;align-items:center;width:100%;height:39px;border:1.5px solid #fdba74;border-radius:7px;background:#fff;overflow:hidden}.advance-money span{padding:0 9px;color:#c2410c;font-size:11px;font-weight:900;background:#fff7ed;height:100%;display:flex;align-items:center}.advance-money input{min-width:0;width:100%;height:100%;border:0;padding:0 9px;font:inherit;font-weight:900;outline:0}
.advance-print-btn{display:flex;align-items:center;justify-content:center;gap:6px;margin:0 0 8px;padding:8px;border:1px solid #c2410c;border-radius:7px;background:#fff;color:#c2410c;text-decoration:none;font-size:10px;font-weight:900}.advance-print-btn:hover{background:#c2410c;color:#fff}
.installment-box{border:1px solid #a7f3d0;border-radius:7px;background:#f0fdf4;padding:8px;margin-bottom:9px}.installment-box summary{cursor:pointer;color:#047857;font-size:11px;font-weight:900}.installment-box p{font-size:9px;color:var(--text-muted);margin:6px 0}
.advance-auto-note{font-size:9px;line-height:1.35;color:#175cd3;background:#eff8ff;border-radius:6px;padding:7px;margin-top:6px;font-weight:800}
.payment-choice-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin:7px 0}.payment-choice{min-height:72px;border:1.5px solid #cbd5e1;border-radius:8px;padding:8px 5px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;cursor:pointer;font-family:'Nunito',sans-serif;background:#f8fafc;color:#475467}.payment-choice i{font-size:16px}.payment-choice strong{font-size:11px}.payment-choice small{font-size:8px;line-height:1.2}.advance-choice{background:#fff7ed;border-color:#fb923c;color:#c2410c}.full-choice{background:#ecfdf5;border-color:#34d399;color:#047857}.payment-choice.active{box-shadow:0 0 0 3px rgba(15,118,110,.18);border-width:2px}.payment-choice:disabled{opacity:.45;cursor:not-allowed;transform:none}.payment-choice:not(:disabled):hover{filter:brightness(.97);transform:translateY(-1px)}.payment-modal{width:430px;max-height:94vh;overflow-y:auto}.simple-payment-box .advance-control{margin-bottom:4px}
.customer-advance-launch{width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;margin:8px 0;padding:10px 12px;border:1.5px solid #fdba74;border-radius:9px;background:#fff7ed;color:#c2410c;font-family:'Nunito',sans-serif;font-weight:900;cursor:pointer}.customer-advance-launch span{display:flex;align-items:center;gap:8px}.customer-advance-launch small{font-size:9px;color:#9a3412;font-weight:800}.customer-payment-backdrop{display:none;position:fixed;inset:0;background:rgba(16,24,40,.62);z-index:180}.customer-payment-backdrop.open{display:block}.simple-payment-box{display:none!important;position:fixed;z-index:181;left:50%;top:50%;transform:translate(-50%,-50%);width:min(430px,calc(100vw - 24px));max-height:calc(100vh - 30px);overflow-y:auto;margin:0!important;padding:18px!important;box-shadow:0 24px 70px rgba(16,24,40,.35)}.simple-payment-box.open{display:block!important}.customer-payment-close{border:0;background:#ffedd5;color:#9a3412;border-radius:7px;width:32px;height:32px;cursor:pointer;flex:0 0 auto}.simple-payment-box .advance-title{align-items:center}.simple-payment-box .advance-title>div:first-child{font-size:16px}.simple-payment-box .advance-control{margin-bottom:4px}
.payment-summary{background:#f8fafc;border:1px solid #dbe2ea;border-radius:8px;padding:8px;margin:9px 0}.payment-summary>div{display:flex;justify-content:space-between;gap:10px;padding:4px 2px;font-size:11px;color:var(--text-mid)}.payment-summary .remaining{border-top:1px dashed #94a3b8;margin-top:4px;padding-top:8px;color:#047857;font-size:13px;font-weight:900}
#paymentOverlay .advance-tabs{display:none!important}#existingPaymentForm>.mf:nth-of-type(1),#existingPaymentForm>.mf:nth-of-type(2){display:none!important}
.advance-two{display:grid;grid-template-columns:1fr 1fr;gap:7px}.advance-help{font-size:10px;color:var(--text-muted);line-height:1.35;margin-bottom:5px}.create-advance-btn{width:100%;margin-top:9px;padding:9px;border:0;border-radius:7px;background:#c2410c;color:#fff;font:inherit;font-size:11px;font-weight:900;cursor:pointer}.advance-message{padding:7px;border-radius:6px;font-size:10px;font-weight:800;margin-bottom:7px}.advance-message.ok{background:#ecfdf3;color:#027a48}.advance-message.err{background:#fef3f2;color:#b42318}
.pm-lbl{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);margin-bottom:5px;}
.pm-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:5px;margin-bottom:8px;}
.pmb{padding:7px 3px;border:1.5px solid var(--border);border-radius:var(--radius-sm);background:var(--white);color:var(--text-mid);font-size:11px;font-weight:800;text-align:center;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:3px;font-family:'Nunito',sans-serif;transition:.14s;}
.pmb:hover{border-color:var(--primary);color:var(--primary);}
.pmb.active{background:var(--primary-lt);border-color:var(--primary);color:var(--primary);}
.pmb:disabled{opacity:.42;cursor:not-allowed;background:var(--bg);border-color:var(--border);color:var(--text-muted);transform:none;}
.cash-wrap{position:relative;margin-bottom:6px;}
.cash-pfx{position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:12px;font-weight:900;color:var(--text-muted);}
.cash-inp{padding-left:40px;background:var(--white);}
.cash-inp:read-only{background:var(--bg);opacity:.65;cursor:not-allowed;}
.bal-pill{display:flex;justify-content:space-between;align-items:center;padding:7px 10px;border-radius:var(--radius-sm);margin-bottom:8px;font-size:13px;font-weight:800;border:1.5px solid;}
.bp-zero{background:var(--bg);border-color:var(--border);color:var(--text-muted);}
.bp-pos{background:var(--green-lt);border-color:#86efac;color:var(--green);}
.bp-neg{background:var(--red-lt);border-color:#fca5a5;color:var(--red);}
.pay-btn{width:100%;padding:13px 18px;background:var(--primary);color:#fff;border-radius:var(--radius);font-size:15px;font-weight:900;cursor:pointer;font-family:'Nunito',sans-serif;transition:.15s;}
.pay-btn:hover{background:var(--primary-dk);}
.pay-btn[disabled]{background:#e4e6f0;color:var(--text-muted);cursor:not-allowed;}
.overlay{position:fixed;inset:0;background:rgba(28,32,56,.45);backdrop-filter:blur(5px);z-index:999;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
.overlay.show{opacity:1;pointer-events:all;}
.modal{background:var(--white);border-radius:18px;box-shadow:var(--shadow-lg);padding:26px 24px 22px;width:380px;max-width:94vw;position:relative;transform:translateY(14px) scale(.97);transition:transform .2s;}
.overlay.show .modal{transform:translateY(0) scale(1);}
.mcl{position:absolute;top:13px;right:13px;width:28px;height:28px;background:var(--bg);border:1.5px solid var(--border);border-radius:7px;font-size:13px;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-family:'Nunito',sans-serif;}
.m-head{text-align:center;margin-bottom:18px;}
.m-icon{width:48px;height:48px;background:var(--primary-lt);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;color:var(--primary);margin:0 auto 10px;}
.m-head h2{font-family:'Lora',serif;font-size:18px;margin-bottom:3px;}
.m-head p{font-size:12px;color:var(--text-muted);font-weight:600;}
.m-err,.warn-box{background:var(--red-lt);border:1.5px solid #fca5a5;border-radius:var(--radius-sm);padding:8px 11px;font-size:13px;color:var(--red);font-weight:800;margin-bottom:12px;}
.mf{margin-bottom:11px;}
.mf label{display:block;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.09em;color:var(--text-mid);margin-bottom:4px;}
.miw{position:relative;}
.miw i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px;}
.minp{padding-left:34px;}
.m-sub{width:100%;padding:11px;background:var(--primary);color:#fff;border-radius:var(--radius-sm);font-size:14px;font-weight:900;display:flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;font-family:'Nunito',sans-serif;}
.m-sub.green{background:var(--accent);}
.m-note{text-align:center;font-size:12px;color:var(--text-muted);margin-top:10px;font-weight:700;}
.ot-modal{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:4px;}
.otm-btn{padding:10px 8px;border:1.5px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text-mid);font-size:13px;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;font-family:'Nunito',sans-serif;transition:.14s;}
.otm-btn.active{background:var(--primary-lt);border-color:var(--primary);color:var(--primary);}
.qz-info{margin-top:8px;padding:8px 10px;border-radius:8px;background:#f8fafc;border:1px solid #dde3ee;font-size:11px;font-weight:700;color:#56607a;}
@media(max-width:1200px){.pos-body{grid-template-columns:240px 1fr 350px;}}
@media(max-width:1024px){
    .pos-body{grid-template-columns:1fr;}
    .orders-panel,.left-panel,.right-panel{max-height:none;overflow:visible;border:none;border-bottom:1.5px solid var(--border);}
    .orders-list,.left-panel,.cart-scroll{max-height:none;}
    .manual-form{grid-template-columns:1fr 1fr;}
    .manual-form button{grid-column:1/-1;}
}
</style>
</head>
<body>

<div class="topbar">
    <div class="brand">
        <div class="brand-logo"><img src="supun-logo.png" alt="Supun Group logo"></div>
        <div class="brand-text">
            <h1>Supun Group</h1>
            <small>Point of Sale</small>
        </div>
    </div>

    <div class="topbar-right">
        <form method="POST" style="display:inline;">
            <button type="submit" name="quick_order" class="tb-btn btn-quick">
                <i class="fa-solid fa-cart-plus"></i> New Retail Sale
            </button>
        </form>

        <button class="tb-btn btn-new" type="button" onclick="openOrderModal()">
            <i class="fa-solid fa-user-tag"></i> Customer / Wholesale Sale
        </button>

        <a class="tb-btn btn-new" href="advance_payments.php">
            <i class="fa-solid fa-wallet"></i> Account Credit
        </a>
        <a class="tb-btn btn-new" href="installment_payments.php">
            <i class="fa-solid fa-file-invoice-dollar"></i> Installments
        </a>

        <?php if (in_array($user_role, ['admin', 'accountant'], true)): ?>
        <a class="tb-btn btn-owner" href="dashboard.php">
            <i class="fa-solid fa-gauge-high"></i> Dashboard
        </a>
        <?php else: ?>
        <button class="tb-btn btn-owner" type="button" onclick="openAdminModal()">
            <i class="fa-solid fa-lock"></i> Admin
        </button>
        <?php endif; ?>

      <a href="<?php echo in_array($user_role,['admin','accountant'],true)?'admin/products.php':'cashier_products.php'; ?>" class="tb-btn btn-owner">
    <i class="fa-solid fa-boxes-stacked"></i> Add Stock
</a>

        <div class="cashier-pill">
            <div class="avatar"><?php echo strtoupper(substr($_SESSION["full_name"] ?? "U", 0, 1)); ?></div>
            <span class="name"><?php echo htmlspecialchars($_SESSION["full_name"] ?? "Cashier"); ?></span>
            <span class="role-badge"><?php echo ucfirst($user_role); ?></span>
        </div>

        <a href="logout.php" class="tb-btn btn-logout">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </div>
</div>

<div class="pos-body">

    <div class="orders-panel">
        <div class="op-head">
            <h3><i class="fa-solid fa-layer-group"></i> Open Orders</h3>
            <?php if ($open_count > 0): ?>
                <span class="op-badge"><?php echo $open_count; ?></span>
            <?php endif; ?>
        </div>

        <?php if (!$current_order): ?>
        <div class="qs-banner">
            <p>No order selected. Start one now!</p>
            <form method="POST">
                <button type="submit" name="quick_order" class="qs-btn">
                    <i class="fa-solid fa-cart-plus"></i> New Retail Sale
                </button>
            </form>
        </div>
        <?php endif; ?>

        <div class="orders-list">
            <?php if ($open_orders && $open_orders->num_rows > 0): ?>
                <?php while ($oo = $open_orders->fetch_assoc()): ?>
                    <a href="pos.php?order_id=<?php echo (int)$oo["order_id"]; ?>" class="order-card <?php echo ($current_order_id == $oo["order_id"]) ? 'active' : ''; ?>">
                        <div class="oc-top">
                            <div class="oc-no"><?php echo htmlspecialchars($oo["order_number"] ?: ('ORD-' . str_pad($oo["order_id"], 5, '0', STR_PAD_LEFT))); ?></div>
                            <div class="oc-status">Open</div>
                        </div>
                        <div class="oc-meta">
                            <div><i class="fa-solid fa-bag-shopping"></i> <?php echo ucfirst(str_replace('_', ' ', $oo["order_type"])); ?></div>
                            <div><i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($oo["customer_name"] ?: 'Walk-in'); ?></div>
                            <?php if ($oo["table_name"]): ?>
                            <div><i class="fa-solid fa-table"></i> <?php echo htmlspecialchars($oo["table_name"]); ?></div>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="no-orders">
                    <i class="fa-solid fa-receipt" style="font-size:24px;display:block;margin-bottom:8px;color:var(--border-dk);"></i>
                    No active sales.<br>Click <strong>New Retail Sale</strong> to start.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="left-panel">

        <div class="card manual-card">
            <div class="ch">
                <div class="ch-icon chi-y"><i class="fa-solid fa-pen-to-square"></i></div>
                <h3>Add Custom Item</h3>
            </div>

            <?php if ($current_order && $current_order["order_status"] === "open"): ?>
                <form method="POST" class="manual-form">
                    <input type="hidden" name="order_id" value="<?php echo (int)$current_order_id; ?>">
                    <input type="text"   name="manual_item_name"  class="inp" placeholder="Item name" required>
                    <input type="number" name="manual_item_price" class="inp" step="0.01" min="0.01" placeholder="Price (Rs.)" required>
                    <input type="number" name="manual_item_qty"   class="inp" min="1" value="1" required>
                    <button type="submit" name="add_manual_item" class="btn-manual">
                        <i class="fa-solid fa-circle-plus"></i> Add
                    </button>
                </form>
            <?php else: ?>
                <div class="warn-box"><i class="fa-solid fa-info-circle"></i> Select or create an order to add items.</div>
            <?php endif; ?>
        </div>

        <div class="card menu-card">
            <div class="filter-area">
                <div class="ch" style="margin-bottom:10px;">
                    <div class="ch-icon chi-b"><i class="fa-solid fa-boxes-stacked"></i></div>
                    <h3>Menu Items</h3>
                </div>

                <form method="GET" class="filter-row">
                    <?php if ($current_order_id > 0): ?>
                        <input type="hidden" name="order_id" value="<?php echo (int)$current_order_id; ?>">
                    <?php endif; ?>
                    <?php if ($filter_category > 0): ?>
                        <input type="hidden" name="category" value="<?php echo (int)$filter_category; ?>">
                    <?php endif; ?>

                    <div class="sw">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" name="search" class="search-inp" placeholder="Search products or SKU…" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <button type="submit" class="btn-search">
                        <i class="fa-solid fa-magnifying-glass"></i> Search
                    </button>
                </form>

                <div class="cat-pills">
                    <a href="pos.php<?php echo $current_order_id > 0 ? '?order_id='.$current_order_id : ''; ?>" class="cpill <?php echo ($filter_category == 0 && $search == '') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-border-all"></i> All
                    </a>
                    <?php
                    if ($categories && $categories->num_rows > 0) {
                        mysqli_data_seek($categories, 0);
                        while ($cat = $categories->fetch_assoc()) {
                            $cls = ($filter_category == $cat["category_id"]) ? "active" : "";
                            $url = "pos.php?order_id=".(int)$current_order_id."&category=".(int)$cat["category_id"];
                            echo '<a href="'.$url.'" class="cpill '.$cls.'"><i class="fa-solid fa-tag"></i> '.htmlspecialchars($cat["category_name"]).'</a>';
                        }
                    }
                    ?>
                </div>
            </div>

            <div class="products-grid">
                <?php if ($products && $products->num_rows > 0): ?>
                    <?php while ($row = $products->fetch_assoc()): ?>
                        <?php
                        $is_wholesale_sale = $current_order && $current_order['order_type'] === 'wholesale';
                        $display_price = $is_wholesale_sale && (float)$row['wholesale_price'] > 0
                            ? (float)$row['wholesale_price']
                            : (float)$row['price'];
                        $price_label = $is_wholesale_sale ? 'Wholesale price' : 'Retail price';
                        ?>
                        <?php if ($current_order && $current_order["order_status"] === "open" && (float)$row['stock_qty'] >= 1): ?>
                            <div class="pcard"
                                 onclick="addItem(<?php echo (int)$current_order_id; ?>, <?php echo (int)$row['product_id']; ?>, this)"
                                 title="Click to add to order">
                                <div class="pcard-icon"><i class="fa-solid fa-box"></i></div>
                                <div class="pcard-name"><?php echo htmlspecialchars($row["product_name"]); ?></div>
                                <div class="pcard-price">Rs. <?php echo number_format($display_price, 2); ?></div>
                                <div class="pcard-sub"><?php echo $price_label; ?> · Tap to add</div>
                                <div class="pcard-stock <?php echo (float)$row['stock_qty'] <= (float)$row['reorder_level'] ? 'low' : ''; ?>"><?php echo number_format($row['stock_qty'],0); ?> <?php echo htmlspecialchars($row['unit']); ?> available</div>
                                <span class="pcard-badge">+ Add</span>
                            </div>
                        <?php elseif ($current_order && $current_order["order_status"] === "open"): ?>
                            <div class="pcard disabled" title="Out of stock">
                                <div class="pcard-icon"><i class="fa-solid fa-box-open"></i></div>
                                <div class="pcard-name"><?php echo htmlspecialchars($row["product_name"]); ?></div>
                                <div class="pcard-price">Rs. <?php echo number_format($display_price, 2); ?></div>
                                <div class="pcard-stock low">Out of stock</div>
                            </div>
                        <?php else: ?>
                            <div class="pcard disabled"
                                 onclick="noOrderAlert()"
                                 title="Create an order first">
                                <div class="pcard-icon"><i class="fa-solid fa-box"></i></div>
                                <div class="pcard-name"><?php echo htmlspecialchars($row["product_name"]); ?></div>
                                <div class="pcard-price">Rs. <?php echo number_format($row["price"], 2); ?></div>
                                <div class="pcard-sub">Retail price · Select sale first</div>
                                <div class="pcard-stock <?php echo (float)$row['stock_qty'] <= (float)$row['reorder_level'] ? 'low' : ''; ?>"><?php echo number_format($row['stock_qty'],0); ?> <?php echo htmlspecialchars($row['unit']); ?> available</div>
                            </div>
                        <?php endif; ?>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="no-prods">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        No items found.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="right-panel">
        <form method="POST" id="orderForm" class="rp-form">

            <div class="rp-head">
                <h2><i class="fa-solid fa-receipt"></i> Current Order</h2>

                <?php if ($current_order): ?>
                    <div class="order-meta-box">
                        <div><strong>Order:</strong> <?php echo htmlspecialchars($current_order["order_number"] ?: ('ORD-' . str_pad($current_order["order_id"], 5, '0', STR_PAD_LEFT))); ?></div>
                        <div><strong>Customer:</strong> <?php echo htmlspecialchars($current_order["customer_name"] ?: 'Walk-in Customer'); ?></div>
                        <?php if ($current_order["table_name"]): ?>
                        <div><strong>Table:</strong> <?php echo htmlspecialchars($current_order["table_name"]); ?></div>
                        <?php endif; ?>
                    </div>
                    <span class="count-badge"><?php echo (int)$cart_count; ?> item<?php echo $cart_count != 1 ? 's' : ''; ?></span>
                <?php else: ?>
                    <div style="margin-top:10px;font-size:12px;color:var(--text-muted);font-weight:700;">No order selected</div>
                <?php endif; ?>
            </div>

            <?php if ($current_order): ?>
            <div class="ot-wrap">
                <div class="ot-lbl">Sale Type</div>
                <div class="ot-selected">
                    <span>
                        <i class="fa-solid <?php echo $current_order["order_type"] === "wholesale" ? 'fa-boxes-stacked' : 'fa-basket-shopping'; ?>"></i>
                        <?php echo $current_order["order_type"] === "wholesale" ? 'Wholesale Sale' : 'Retail Sale'; ?>
                    </span>
                    <small>Selected</small>
                </div>
                <input type="hidden" name="order_type" id="ot_val" value="<?php echo htmlspecialchars($current_order["order_type"] ?? 'retail'); ?>">
            </div>
            <?php endif; ?>

            <?php if ($current_order): ?>
            <div class="cart-scroll">
                <?php if ($order_items && $order_items->num_rows > 0): ?>
                    <?php while ($item = $order_items->fetch_assoc()): ?>
                        <?php
                        $lt        = (float)$item["line_total"];
                        $item_name = $item["item_type"] === "manual" ? $item["custom_item_name"] : $item["product_name"];
                        ?>
                        <div class="ci">
                            <div class="ci-info">
                                <div class="ci-name">
                                    <?php echo htmlspecialchars($item_name); ?>
                                    <?php if ($item["item_type"] === "manual"): ?>
                                        <span class="mchip">Custom</span>
                                    <?php endif; ?>
                                </div>
                                <div class="ci-price-line">
                                    <div class="ci-price">Rs. <?php echo number_format((float)$item["price"], 2); ?> each · Rs. <?php echo number_format($lt, 2); ?></div>
                                    <?php if ((int)($item["price_overridden"] ?? 0) === 1): ?>
                                        <span class="override-chip">Edited</span>
                                        <?php if ($item["product_id"]): ?>
                                            <a class="restore-price" href="pos.php?order_id=<?php echo (int)$current_order_id; ?>&restore_price=<?php echo (int)$item["order_item_id"]; ?>">Restore</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($current_order["order_status"] === "open"): ?>
                                <button type="button" class="price-edit" title="Edit unit price" aria-label="Edit unit price"
                                    data-price-edit="1"
                                    data-item-id="<?php echo (int)$item['order_item_id']; ?>"
                                    data-item-name="<?php echo htmlspecialchars($item_name, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-current-price="<?php echo htmlspecialchars(number_format((float)$item['price'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <div class="qc">
                                    <a class="qcb" href="pos.php?order_id=<?php echo (int)$current_order_id; ?>&dec=<?php echo (int)$item["order_item_id"]; ?>">
                                        <i class="fa-solid fa-minus"></i>
                                    </a>
                                    <span class="qn"><?php echo (int)$item["quantity"]; ?></span>
                                    <a class="qcb" href="pos.php?order_id=<?php echo (int)$current_order_id; ?>&inc=<?php echo (int)$item["order_item_id"]; ?>">
                                        <i class="fa-solid fa-plus"></i>
                                    </a>
                                </div>
                                <a class="rm" href="pos.php?order_id=<?php echo (int)$current_order_id; ?>&remove=<?php echo (int)$item["order_item_id"]; ?>" title="Remove">
                                    <i class="fa-solid fa-xmark"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-cart">
                        <i class="fa-solid fa-cart-shopping"></i>
                        <p>No items yet — tap a menu item to add.</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($current_order["order_status"] === "open" && $cart_count > 0): ?>
                <div class="clear-bar">
                    <a class="btn-clear" href="pos.php?order_id=<?php echo (int)$current_order_id; ?>&clear=1" onclick="return confirm('Clear all items from this order?')">
                        <i class="fa-solid fa-trash-can"></i> Clear Order
                    </a>
                </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="cart-empty-state">
                <i class="fa-solid fa-receipt"></i>
                <p>No active order</p>
                <small>Click <strong>New Retail Sale</strong> in the top bar<br>or select an active sale from the left.</small>
                <form method="POST" style="width:100%;">
                    <button type="submit" name="quick_order" class="pay-btn" style="margin-top:6px;">
                        <i class="fa-solid fa-cart-plus"></i> Start Retail Sale
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <div class="pay-section">
                <?php $service_charge_preview = round($grand_total * 0.10, 2); ?>
                <div class="total-row">
                    <span class="total-lbl">Subtotal</span>
                    <span class="total-amt">Rs. <span id="subtotalAmt"><?php echo number_format($grand_total, 2, '.', ''); ?></span></span>
                </div>

                <div class="qz-info" id="qzInfo">
                    Display/Drawer Status: waiting...
                </div>

                <?php if ($pay_error): ?>
                    <div class="warn-box" style="margin-top:10px;"><i class="fa-solid fa-triangle-exclamation"></i> Cash given is less than the total amount.</div>
                <?php endif; ?>
                <?php if ($stock_error): ?>
                    <div class="warn-box" style="margin-top:10px;"><i class="fa-solid fa-box-open"></i> Not enough stock is available for that product.</div>
                <?php endif; ?>

                <?php if ($current_order && $current_order["order_status"] === "open" && $cart_count > 0): ?>
                    <input type="hidden" name="order_id" value="<?php echo (int)$current_order_id; ?>">

                    <div class="service-row">
                        <label class="service-check" for="serviceChargeToggle">
                            <input type="checkbox" name="apply_service_charge" id="serviceChargeToggle" value="1" onchange="updateOrderFees()">
                            <span>Additional Charge (10%)</span>
                        </label>
                        <span class="service-amt">Rs. <span id="serviceChargeAmt"><?php echo number_format($service_charge_preview, 2, '.', ''); ?></span></span>
                    </div>

                    <div class="service-row discount-row">
                        <div class="discount-head">
                            <label class="service-check" for="discountValue">
                                <i class="fa-solid fa-tags"></i>
                                <span>Apply Discount</span>
                            </label>
                            <small style="color:var(--text-muted);font-size:10px;font-weight:800;">Optional</small>
                        </div>
                        <div class="discount-controls">
                            <input type="hidden" name="discount_type" id="discountType" value="percentage">
                            <div class="discount-type-grid" role="group" aria-label="Discount type">
                                <button type="button" class="discount-type-btn active" data-discount-type="percentage" onclick="setDiscountType('percentage')">
                                    <i class="fa-solid fa-percent"></i> Percentage
                                </button>
                                <button type="button" class="discount-type-btn" data-discount-type="fixed" onclick="setDiscountType('fixed')">
                                    <i class="fa-solid fa-money-bill"></i> Fixed Amount
                                </button>
                            </div>
                            <div class="discount-value-wrap">
                                <span class="discount-unit" id="discountUnit">%</span>
                                <input type="number" name="discount_value" id="discountValue" class="discount-input" aria-label="Discount value" step="0.01" min="0" max="100" value="0.00" placeholder="Enter discount" oninput="updateOrderFees()">
                            </div>
                        </div>
                    </div>

                    <div class="total-row discount-summary" id="discountSummary" style="display:none;">
                        <span class="total-lbl">Discount</span>
                        <span class="service-amt">- Rs. <span id="discountAmt">0.00</span></span>
                    </div>

                    <div class="service-row">
                        <label class="service-check" for="packagingFeeToggle">
                            <input type="checkbox" name="apply_packaging_fee" id="packagingFeeToggle" value="1" onchange="updateOrderFees()">
                            <span>Delivery / Handling</span>
                        </label>
                        <div class="fee-input-wrap">
                            <span>Rs.</span>
                            <input type="number" name="packaging_fee" id="packagingFeeInput" class="fee-input" step="0.01" min="0" value="0.00" disabled oninput="updateOrderFees()">
                        </div>
                    </div>

                    <div class="total-row">
                        <span class="total-lbl">Grand Total</span>
                        <span class="total-amt">Rs. <span id="gt"><?php echo number_format($grand_total, 2, '.', ''); ?></span></span>
                    </div>

                    <button type="button" class="customer-advance-launch" onclick="openCustomerPaymentPopup()"><span><i class="fa-solid fa-wallet"></i> Credit / Installments</span><small id="customerAdvanceLaunchText">Optional</small></button>
                    <div class="customer-payment-backdrop" id="customerPaymentBackdrop" onclick="closeCustomerPaymentPopup()"></div>
                    <div class="advance-box simple-payment-box">
                        <div class="advance-title"><div><i class="fa-solid fa-user-check"></i> Customer Payment Options</div><button type="button" class="customer-payment-close" onclick="closeCustomerPaymentPopup()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
                        <?php if ($advance_error): ?><div class="advance-message err"><i class="fa-solid fa-triangle-exclamation"></i> Select a customer and enter a valid payment.</div><?php endif; ?>
                        <label class="advance-label">1. Select customer</label>
                        <select name="checkout_customer_id" id="checkoutCustomerId" class="advance-control" onchange="selectAdvanceCustomer()">
                            <option value="0" data-balance="0">Walk-in / no customer</option>
                            <?php if ($checkout_customers): while($ac=$checkout_customers->fetch_assoc()): ?>
                            <option value="<?php echo (int)$ac['customer_id']; ?>" data-balance="<?php echo number_format((float)$ac['advance_balance'],2,'.',''); ?>" data-receipt-id="<?php echo (int)($ac['latest_advance_receipt_id']??0); ?>" data-search="<?php echo htmlspecialchars(strtolower($ac['account_number'].' '.$ac['customer_name'].' '.$ac['phone']),ENT_QUOTES,'UTF-8'); ?>" <?php echo (int)($current_order['customer_id']??0)===(int)$ac['customer_id']?'selected':''; ?>><?php echo htmlspecialchars($ac['account_number'].' · '.$ac['customer_name'].' · '.$ac['phone']); ?></option>
                            <?php endwhile; endif; ?>
                        </select>
                        <div class="advance-balance-row"><span id="advanceAvailable">Available account credit: <strong>Rs. <?php echo number_format((float)($current_order['advance_balance']??0),2); ?></strong></span><span id="customerSearchResult"></span></div>
                        <input type="checkbox" name="apply_advance" id="useAdvanceToggle" value="1" style="display:none;">
                        <input type="hidden" name="advance_to_use" id="advanceToUse" max="<?php echo number_format((float)($current_order['advance_balance']??0),2,'.',''); ?>" value="0.00">
                        <label class="advance-label">2. Choose one option</label>
                        <div class="payment-choice-grid">
                            <button type="button" id="useAdvanceChoice" class="payment-choice advance-choice" onclick="chooseAdvancePayment()"><i class="fa-solid fa-wallet"></i><strong>Use Account Credit</strong><small>Deduct available credit from this sale</small></button>
                            <button type="button" class="payment-choice" onclick="openSimpleInstallment()"><i class="fa-solid fa-calendar-check"></i><strong>Pay by Installments</strong><small>Receive part payment and keep bill open</small></button>
                        </div>
                        <div id="advanceDecision" class="hint" style="margin:7px 0;color:#667085;font-weight:800;">Close this window to continue with a normal payment.</div>
                        <div class="advance-due"><span>Amount to pay now</span><strong>Rs. <span id="remainingAfterAdvance"><?php echo number_format($grand_total,2); ?></span></strong></div>
                        <div class="advance-due" id="customerAdvanceAfterRow" style="display:none;background:#fff7ed;color:#9a3412;"><span>Account credit left</span><strong>Rs. <span id="customerAdvanceAfter">0.00</span></strong></div>
                        <a id="printAdvanceReceipt" class="advance-print-btn" href="#" target="_blank" style="display:none;"><i class="fa-solid fa-print"></i> Reprint Latest Part-Payment Receipt</a>
                    </div>

                    <div class="advance-box legacy-advance-box" style="display:none;">
                        <div class="advance-title"><div><i class="fa-solid fa-wallet"></i> Customer Part Payments</div><small>Select customer → record payment → complete bill</small></div>
                        <?php if ($advance_created): ?><div class="advance-message ok"><i class="fa-solid fa-circle-check"></i> New advance account created and selected.</div><?php endif; ?>
                        <?php if ($advance_error): ?><div class="advance-message err"><i class="fa-solid fa-triangle-exclamation"></i> Enter customer name and a valid advance amount.</div><?php endif; ?>
                        <div class="advance-tabs">
                            <button type="button" class="advance-tab active" id="existingAdvanceTab" onclick="showAdvanceMode('existing')"><i class="fa-solid fa-users"></i> Existing Customer</button>
                            <button type="button" class="advance-tab" id="newAdvanceTab" onclick="showAdvanceMode('new')"><i class="fa-solid fa-user-plus"></i> New Customer + 1st Payment</button>
                        </div>
                        <div id="existingAdvancePanel">
                            <label class="advance-label">Customer account</label>
                            <select name="checkout_customer_id" id="checkoutCustomerId" class="advance-control" onchange="selectAdvanceCustomer()">
                                <option value="0" data-balance="0">Select customer</option>
                                <?php if ($checkout_customers): while($ac=$checkout_customers->fetch_assoc()): ?>
                                <option value="<?php echo (int)$ac['customer_id']; ?>" data-balance="<?php echo number_format((float)$ac['advance_balance'],2,'.',''); ?>" data-receipt-id="<?php echo (int)($ac['latest_advance_receipt_id']??0); ?>" <?php echo (int)($current_order['customer_id']??0)===(int)$ac['customer_id']?'selected':''; ?>><?php echo htmlspecialchars($ac['account_number'].' · '.$ac['customer_name'].' · Rs. '.number_format($ac['advance_balance'],2)); ?></option>
                                <?php endwhile; endif; ?>
                            </select>
                            <div class="advance-balance-row"><span id="advanceAvailable">Total paid in advance: <strong>Rs. <?php echo number_format((float)($current_order['advance_balance'] ?? 0),2); ?></strong></span><span>Applied automatically</span></div>
                            <a id="printAdvanceReceipt" class="advance-print-btn" href="#" target="_blank" style="display:none;"><i class="fa-solid fa-print"></i> Print Latest Advance Receipt</a>
<details class="installment-box"><summary><i class="fa-solid fa-circle-plus"></i> Add Next Payment</summary><p>Use this for the 2nd, 3rd, or any later payment.</p><div class="advance-two"><div><label class="advance-label">Amount received</label><div class="advance-money"><span>Rs.</span><input type="number" name="installment_amount" min="0.01" step="0.01" placeholder="0.00"></div></div><div><label class="advance-label">Received by</label><select class="advance-control" name="installment_method"><option>Cash</option><option>Card</option><option>QR</option><option>Bank Transfer</option><option>Cheque</option></select></div></div><button type="submit" name="add_checkout_installment" class="create-advance-btn" formnovalidate><i class="fa-solid fa-floppy-disk"></i> Save Payment &amp; Print Receipt</button></details>
                            <input type="hidden" name="advance_to_use" id="advanceToUse" max="<?php echo number_format((float)($current_order['advance_balance'] ?? 0),2,'.',''); ?>" value="0.00">
                            <div class="advance-due"><span>Balance to collect now</span><strong>Rs. <span id="remainingAfterAdvance"><?php echo number_format($grand_total,2); ?></span></strong></div>
                            <div class="advance-auto-note"><i class="fa-solid fa-circle-info"></i> Pay &amp; Print Bill will use all available advance and complete the sale.</div>
                        </div>
                        <div id="newAdvancePanel" style="display:none;">
                            <p class="advance-help">Enter the customer and their first payment. The receipt prints immediately, then you return to this order.</p>
                            <div class="advance-two"><div><label class="advance-label">Customer name *</label><input class="advance-control" name="advance_customer_name" placeholder="Customer / business name"></div><div><label class="advance-label">Phone</label><input class="advance-control" name="advance_customer_phone" placeholder="Phone number"></div></div>
                            <div class="advance-two"><div><label class="advance-label">Advance received *</label><div class="advance-money"><span>Rs.</span><input type="number" name="new_advance_amount" min="0.01" step="0.01" placeholder="0.00"></div></div><div><label class="advance-label">Received by</label><select class="advance-control" name="new_advance_method"><option>Cash</option><option>Card</option><option>QR</option><option>Bank Transfer</option><option>Cheque</option></select></div></div>
                            <button type="submit" name="create_checkout_advance" class="create-advance-btn" formnovalidate><i class="fa-solid fa-wallet"></i> Save 1st Payment &amp; Print Receipt</button>
                        </div>
                    </div>

                    <div class="pm-lbl">Payment Method</div>
                    <div class="pm-grid">
                        <button type="button" class="pmb active" data-method="Cash" onclick="selMethod('Cash')">
                            <i class="fa-solid fa-money-bill-wave"></i> Cash
                        </button>
                        <button type="button" class="pmb" data-method="Card" onclick="selMethod('Card')">
                            <i class="fa-solid fa-credit-card"></i> Card
                        </button>
                        <button type="button" class="pmb" data-method="QR" onclick="selMethod('QR')">
                            <i class="fa-solid fa-qrcode"></i> QR Pay
                        </button>
                        <button type="button" class="pmb" data-method="Bank Transfer" onclick="selMethod('Bank Transfer')">
                            <i class="fa-solid fa-building-columns"></i> Bank
                        </button>
                        <button type="button" class="pmb" data-method="Cheque" onclick="selMethod('Cheque')">
                            <i class="fa-solid fa-money-check-dollar"></i> Cheque
                        </button>
                    </div>

                    <input type="hidden" name="payment_method" id="pm_val" value="Cash">

                    <div class="cash-wrap">
                        <span class="cash-pfx">Rs.</span>
                        <input type="number" name="cash_given" id="cash_given" class="cash-inp" step="0.01" min="0" placeholder="Enter cash amount…" required oninput="calcBal()">
                    </div>

                    <div id="balPill" class="bal-pill bp-zero">
                        <span id="balLbl">Balance / Change</span>
                        <span id="balAmt">Rs. 0.00</span>
                    </div>

                    <button type="submit" name="pay_order" class="pay-btn" id="payBtn">
                        <i class="fa-solid fa-circle-check"></i> Pay &amp; Print Bill
                    </button>
                <?php else: ?>
                    <button type="button" class="pay-btn" disabled>
                        <i class="fa-solid fa-cart-shopping"></i>
                        <?php echo $current_order ? 'Add items to pay' : 'No Active Order'; ?>
                    </button>
                <?php endif; ?>
            </div>

        </form>
    </div>
</div>

<div class="overlay" id="paymentOverlay">
    <div class="modal payment-modal">
        <button class="mcl" type="button" onclick="closePaymentModal()"><i class="fa-solid fa-xmark"></i></button>
        <div class="m-head"><div class="m-icon"><i class="fa-solid fa-wallet"></i></div><h2>Order Installment</h2><p id="paymentModalCustomer">Select an existing customer or create a new one</p></div>
        <div class="advance-tabs"><button type="button" class="advance-tab active" id="existingPaymentTab" onclick="setPaymentCustomerMode('existing')">Selected Customer</button><button type="button" class="advance-tab" id="newPaymentTab" onclick="setPaymentCustomerMode('new')">New Customer</button></div>
        <form method="post" id="existingPaymentForm">
            <input type="hidden" name="order_id" value="<?php echo (int)$current_order_id; ?>">
            <div class="mf"><label>Search customer</label><input type="search" id="modalCustomerSearch" class="advance-control" placeholder="Name, phone or account number" oninput="filterModalCustomers()"></div>
            <div class="mf"><label>Select customer *</label><select name="checkout_customer_id" id="modalCustomerId" class="advance-control" required onchange="selectModalCustomer()"><option value="">Choose customer</option><?php if($modal_customers): while($mc=$modal_customers->fetch_assoc()): ?><option value="<?php echo (int)$mc['customer_id']; ?>" data-installments="<?php echo number_format((float)$mc['installment_paid'],2,'.',''); ?>" data-search="<?php echo htmlspecialchars(strtolower($mc['account_number'].' '.$mc['customer_name'].' '.$mc['phone']),ENT_QUOTES,'UTF-8'); ?>"><?php echo htmlspecialchars($mc['account_number'].' · '.$mc['customer_name'].' · '.$mc['phone']); ?></option><?php endwhile; endif; ?></select><small id="modalCustomerMatches" style="display:block;margin-top:4px;color:var(--text-muted);font-size:10px;"></small></div>
            <div class="mf"><label>Amount received *</label><div class="advance-money"><span>Rs.</span><input type="number" id="modalInstallmentAmount" name="installment_amount" min="0.01" step="0.01" required placeholder="0.00" oninput="updatePaymentModalSummary()"></div></div>
            <div class="mf"><label>Payment method</label><select class="advance-control" name="installment_method"><option>Cash</option><option>Card</option><option>QR</option><option>Bank Transfer</option><option>Cheque</option></select></div>
            <label id="installmentCreditOption" style="display:none;align-items:flex-start;gap:9px;padding:10px;border:1px solid #a7f3d0;border-radius:8px;background:#f0fdf4;margin-bottom:10px;cursor:pointer"><input type="checkbox" name="use_account_credit_to_close" id="useInstallmentCredit" value="1" onchange="updatePaymentModalSummary()" style="margin-top:3px"><span><strong style="display:block;color:#047857;font-size:12px">Use account credit to close this bill</strong><small id="installmentCreditHelp" style="color:#667085">Available account credit will be used only if it can complete the bill.</small></span></label>
            <div class="payment-summary"><div><span>Bill total</span><strong>Rs. <span data-modal-bill-total>0.00</span></strong></div><div><span>Previously paid</span><strong>Rs. <span data-modal-previous>0.00</span></strong></div><div><span>This payment</span><strong>Rs. <span data-modal-current>0.00</span></strong></div><div class="remaining"><span>Remaining after payment</span><strong>Rs. <span data-modal-remaining>0.00</span></strong></div></div>
            <button class="m-sub green" name="add_checkout_installment"><i class="fa-solid fa-floppy-disk"></i> Save Payment &amp; Print Receipt</button>
        </form>
        <form method="post" id="newPaymentForm" style="display:none;">
            <input type="hidden" name="order_id" value="<?php echo (int)$current_order_id; ?>">
            <div class="mf"><label>Customer name *</label><input class="advance-control" name="advance_customer_name" required placeholder="Customer / business name"></div>
            <div class="mf"><label>Phone number</label><input class="advance-control" name="advance_customer_phone" placeholder="Phone number"></div>
            <div class="mf"><label>First payment *</label><div class="advance-money"><span>Rs.</span><input type="number" id="modalNewPaymentAmount" name="new_advance_amount" min="0.01" step="0.01" required placeholder="0.00" oninput="updatePaymentModalSummary()"></div></div>
            <div class="mf"><label>Payment method</label><select class="advance-control" name="new_advance_method"><option>Cash</option><option>Card</option><option>QR</option><option>Bank Transfer</option><option>Cheque</option></select></div>
            <div class="payment-summary"><div><span>Bill total</span><strong>Rs. <span data-modal-bill-total>0.00</span></strong></div><div><span>Previously paid</span><strong>Rs. <span data-modal-previous>0.00</span></strong></div><div><span>This payment</span><strong>Rs. <span data-modal-current>0.00</span></strong></div><div class="remaining"><span>Remaining after payment</span><strong>Rs. <span data-modal-remaining>0.00</span></strong></div></div>
            <button class="m-sub green" name="create_checkout_advance"><i class="fa-solid fa-user-plus"></i> Create Customer &amp; Save Payment</button>
        </form>
    </div>
</div>

<div class="overlay" id="priceOverlay">
    <div class="modal">
        <button class="mcl" type="button" onclick="closePriceModal()"><i class="fa-solid fa-xmark"></i></button>
        <div class="m-head">
            <div class="m-icon"><i class="fa-solid fa-tag"></i></div>
            <h2>Edit Sale Price</h2>
            <p id="priceItemName">Selected cart item</p>
        </div>
        <form method="POST" id="priceEditForm">
            <input type="hidden" name="order_item_id" id="priceOrderItemId">
            <div class="mf">
                <label>New Unit Price (Rs.)</label>
                <div class="miw">
                    <i class="fa-solid fa-coins"></i>
                    <input type="number" name="new_unit_price" id="newUnitPrice" class="minp" step="0.01" min="0.01" required>
                </div>
            </div>
            <button type="submit" name="update_line_price" class="m-sub green">
                <i class="fa-solid fa-check"></i> Apply to This Order
            </button>
        </form>
        <p class="m-note">This changes only this cart line. The product's saved retail and wholesale prices stay unchanged.</p>
    </div>
</div>

<div class="overlay" id="orderOverlay">
    <div class="modal">
        <button class="mcl" type="button" onclick="closeOrderModal()"><i class="fa-solid fa-xmark"></i></button>

        <div class="m-head">
            <div class="m-icon"><i class="fa-solid fa-sliders"></i></div>
            <h2>Customer Sale</h2>
            <p>Select retail or wholesale and enter the customer</p>
        </div>

        <form method="POST">
            <div class="mf">
                <label>Sale Type</label>
                <div class="ot-modal">
                    <button type="button" class="otm-btn active" id="otm_dine" onclick="setModalOT('retail')">
                        <i class="fa-solid fa-basket-shopping"></i> Retail
                    </button>
                    <button type="button" class="otm-btn" id="otm_take" onclick="setModalOT('wholesale')">
                        <i class="fa-solid fa-boxes-stacked"></i> Wholesale
                    </button>
                </div>
                <input type="hidden" name="new_order_type" id="modal_ot_val" value="retail">
            </div>

            <div class="mf">
                <label>Advance Account <span style="font-weight:600;text-transform:none;letter-spacing:0;">(optional)</span></label>
                <div class="miw">
                    <i class="fa-solid fa-wallet"></i>
                    <select name="customer_id" class="minp" style="padding-left:34px;" onchange="document.querySelector('[name=customer_name]').disabled=this.value!=='0'">
                        <option value="0">Walk-in / enter customer below</option>
                        <?php if ($advance_customers): while($ac=$advance_customers->fetch_assoc()): ?>
                            <option value="<?php echo (int)$ac['customer_id']; ?>"><?php echo htmlspecialchars($ac['account_number'].' · '.$ac['customer_name'].' · Rs. '.number_format($ac['advance_balance'],2)); ?></option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>
                <label style="margin-top:10px;">Customer Name <span style="font-weight:600;text-transform:none;letter-spacing:0;">(optional)</span></label>
                <div class="miw">
                    <i class="fa-solid fa-user"></i>
                    <input type="text" name="customer_name" class="minp" placeholder="Walk-in Customer">
                </div>
            </div>

            <div class="mf" id="tableFieldWrap" style="display:none">
                <label>Table</label>
                <select name="table_id" class="mselect">
                    <option value="">— No table —</option>
                    <?php
                    if ($tables && $tables->num_rows > 0) {
                        mysqli_data_seek($tables, 0);
                        while ($table = $tables->fetch_assoc()) {
                            echo '<option value="'.(int)$table["table_id"].'">'.htmlspecialchars($table["table_name"]).'</option>';
                        }
                    }
                    ?>
                </select>
            </div>

            <button type="submit" name="create_order" class="m-sub green">
                <i class="fa-solid fa-circle-plus"></i> Create Order
            </button>
        </form>

        <p class="m-note">Order stays open until payment is completed</p>
    </div>
</div>

<div class="overlay" id="adminOverlay">
    <div class="modal">
        <button class="mcl" type="button" onclick="closeAdminModal()"><i class="fa-solid fa-xmark"></i></button>

        <div class="m-head">
            <div class="m-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <h2>Owner Access</h2>
            <p>Enter credentials to open the dashboard</p>
        </div>

        <?php if (!empty($admin_error)): ?>
            <div class="m-err"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($admin_error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mf">
                <label>Username</label>
                <div class="miw">
                    <i class="fa-solid fa-user"></i>
                    <input type="text" name="admin_username" class="minp" placeholder="Owner username" required>
                </div>
            </div>
            <div class="mf">
                <label>Password</label>
                <div class="miw">
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" name="admin_password" class="minp" placeholder="Password" required>
                </div>
            </div>
            <button type="submit" name="admin_login_submit" class="m-sub">
                <i class="fa-solid fa-right-to-bracket"></i> Login to Dashboard
            </button>
        </form>
        <p class="m-note">Owner / Admin access only</p>
    </div>
</div>

<!-- ============================================================
     REPLACE THE ENTIRE <script>...</script> BLOCK IN pos.php
     WITH THIS CONTENT
     ============================================================ -->
<script>
let CART_SUBTOTAL = parseFloat("<?php echo number_format($grand_total, 2, '.', ''); ?>") || 0;
let GT = CART_SUBTOTAL;
let AMOUNT_DUE = GT;

function openPriceModal(itemId, itemName, currentPrice) {
    document.getElementById('priceOrderItemId').value = itemId;
    document.getElementById('priceItemName').textContent = itemName;
    const input = document.getElementById('newUnitPrice');
    input.value = currentPrice;
    document.getElementById('priceOverlay').classList.add('show');
    setTimeout(() => { input.focus(); input.select(); }, 50);
}

function closePriceModal() {
    document.getElementById('priceOverlay').classList.remove('show');
}

// Cart contents are replaced after AJAX actions, so handle price buttons at document level.
document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-price-edit]');
    if (!button) return;
    event.preventDefault();
    event.stopPropagation();
    openPriceModal(
        button.dataset.itemId,
        button.dataset.itemName || 'Selected cart item',
        button.dataset.currentPrice || '0.00'
    );
});
const CURRENT_ORDER_ID = <?php echo (int)$current_order_id; ?>;
let displayCashTimer = null;

function setDiscountType(type) {
    const selected = type === 'fixed' ? 'fixed' : 'percentage';
    const typeInput = document.getElementById('discountType');
    const valueInput = document.getElementById('discountValue');
    const unit = document.getElementById('discountUnit');

    if (typeInput) typeInput.value = selected;
    if (unit) unit.textContent = selected === 'fixed' ? 'Rs.' : '%';
    if (valueInput) {
        if (selected === 'percentage') valueInput.max = '100';
        else valueInput.removeAttribute('max');
    }
    document.querySelectorAll('.discount-type-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.discountType === selected);
    });
    updateOrderFees();
}

function selectAdvanceCustomer() {
    const select = document.getElementById('checkoutCustomerId');
    const input = document.getElementById('advanceToUse');
    const label = document.getElementById('advanceAvailable');
    const printLink = document.getElementById('printAdvanceReceipt');
    const toggle = document.getElementById('useAdvanceToggle');
    if (!select || !input) return;
    const balance = Math.max(0, parseFloat(select.options[select.selectedIndex]?.dataset.balance) || 0);
    input.max = balance.toFixed(2);
    input.value = '0.00';
    if (toggle) { toggle.checked = false; toggle.disabled = select.value === '0' || balance <= 0; }
    document.getElementById('normalPaymentChoice')?.classList.add('active');
    document.getElementById('useAdvanceChoice')?.classList.remove('active');
    const useButton = document.getElementById('useAdvanceChoice');
    if (useButton) useButton.disabled = select.value === '0' || balance <= 0;
    const decision = document.getElementById('advanceDecision');
    if (decision) decision.textContent = balance > 0 ? 'Choose Use Account Credit, or close this window for normal payment.' : (select.value === '0' ? 'Select a customer to use credit or start an installment.' : 'No account credit available. You can still start an installment.');
    const afterRow = document.getElementById('customerAdvanceAfterRow');
    const afterAmount = document.getElementById('customerAdvanceAfter');
    if (afterRow) afterRow.style.display = 'none';
    if (afterAmount) afterAmount.textContent = balance.toFixed(2);
    const launchText = document.getElementById('customerAdvanceLaunchText');
    if (launchText) launchText.textContent = select.value === '0' ? 'Optional' : (balance > 0 ? 'Rs. ' + balance.toFixed(2) + ' available' : 'Customer selected');
    if (label) label.innerHTML = 'Available account credit: <strong>Rs. ' + balance.toFixed(2) + '</strong>';
    const receiptId = parseInt(select.options[select.selectedIndex]?.dataset.receiptId || '0', 10);
    if (printLink) {
        printLink.href = receiptId > 0 ? 'print_advance.php?transaction_id=' + receiptId + '&return_order=<?php echo (int)$current_order_id; ?>' : '#';
        printLink.style.display = receiptId > 0 ? 'flex' : 'none';
    }
    updateOrderFees();
}

function openCustomerPaymentPopup() {
    document.querySelector('.simple-payment-box')?.classList.add('open');
    document.getElementById('customerPaymentBackdrop')?.classList.add('open');
}

function closeCustomerPaymentPopup() {
    document.querySelector('.simple-payment-box')?.classList.remove('open');
    document.getElementById('customerPaymentBackdrop')?.classList.remove('open');
}

function setAdvancePaymentChoice(useAdvance) {
    const toggle = document.getElementById('useAdvanceToggle');
    const input = document.getElementById('advanceToUse');
    if (!toggle || !input) return;
    const available = Math.max(0, parseFloat(input.max) || 0);
    const enabled = useAdvance && !toggle.disabled && available > 0;
    toggle.checked = enabled;
    input.value = enabled ? Math.min(Math.max(0, parseFloat(input.max) || 0), GT).toFixed(2) : '0.00';
    document.getElementById('normalPaymentChoice')?.classList.toggle('active', !enabled);
    document.getElementById('useAdvanceChoice')?.classList.toggle('active', enabled);
    const decision = document.getElementById('advanceDecision');
    if (decision) decision.textContent = enabled ? 'Account credit will be deducted from this sale.' : 'Close this window to continue with a normal payment.';
    const afterRow = document.getElementById('customerAdvanceAfterRow');
    if (afterRow) afterRow.style.display = enabled ? 'flex' : 'none';
    const launchText = document.getElementById('customerAdvanceLaunchText');
    if (launchText) launchText.textContent = enabled ? 'Using Rs. ' + Number(input.value).toFixed(2) : (Number(input.max) > 0 ? 'Advance kept for later' : 'Customer selected');
    updateOrderFees();
}

function chooseNormalPayment() {
    setAdvancePaymentChoice(false);
    closeCustomerPaymentPopup();
    chooseFullPayment();
}

function chooseAdvancePayment() {
    setAdvancePaymentChoice(true);
    closeCustomerPaymentPopup();
    chooseFullPayment();
}

function openSimpleInstallment() {
    const customerId = parseInt(document.getElementById('checkoutCustomerId')?.value || '0', 10);
    setAdvancePaymentChoice(false);
    closeCustomerPaymentPopup();
    if (customerId > 0) openPaymentModal();
    else openNewCustomerInstallment();
}

function filterPaymentCustomers() {
    const query = (document.getElementById('customerPaymentSearch')?.value || '').trim().toLowerCase();
    const select = document.getElementById('checkoutCustomerId');
    if (!select) return;
    let matches = 0;
    Array.from(select.options).forEach((option, index) => {
        if (index === 0) return;
        const match = !query || (option.dataset.search || option.textContent.toLowerCase()).includes(query);
        option.hidden = !match;
        if (match) matches++;
    });
    const result = document.getElementById('customerSearchResult');
    if (result) result.textContent = query ? matches + ' found' : '';
    if (query && matches === 1) {
        const only = Array.from(select.options).find((option,index) => index>0 && !option.hidden);
        if (only) { select.value=only.value; selectAdvanceCustomer(); }
    }
}

function setPaymentCustomerMode(mode) {
    const isNew = mode === 'new';
    document.getElementById('existingPaymentForm').style.display = isNew ? 'none' : 'block';
    document.getElementById('newPaymentForm').style.display = isNew ? 'block' : 'none';
    document.getElementById('existingPaymentTab').classList.toggle('active', !isNew);
    document.getElementById('newPaymentTab').classList.toggle('active', isNew);
    updatePaymentModalSummary();
}

function updatePaymentModalSummary() {
    const select = document.getElementById('modalCustomerId');
    const previous = Math.min(GT, Math.max(0, parseFloat(select?.options[select.selectedIndex]?.dataset.installments) || 0));
    document.querySelectorAll('.payment-summary').forEach(summary => {
        const isNew = summary.closest('form')?.id === 'newPaymentForm';
        const paidBefore = isNew ? 0 : previous;
        const input = document.getElementById(isNew ? 'modalNewPaymentAmount' : 'modalInstallmentAmount');
        const current = Math.max(0, parseFloat(input?.value) || 0);
        const dueBefore = Math.max(0, GT - paidBefore);
        if (input) input.max = dueBefore.toFixed(2);
        summary.querySelector('[data-modal-bill-total]').textContent = GT.toFixed(2);
        summary.querySelector('[data-modal-previous]').textContent = paidBefore.toFixed(2);
        summary.querySelector('[data-modal-current]').textContent = current.toFixed(2);
        summary.querySelector('[data-modal-remaining]').textContent = Math.max(0, dueBefore - current).toFixed(2);
    });
}

function filterModalCustomers() {
    const query=(document.getElementById('modalCustomerSearch')?.value||'').trim().toLowerCase();
    const select=document.getElementById('modalCustomerId');
    if(!select)return;
    let matches=0;
    Array.from(select.options).forEach((option,index)=>{if(index===0)return;const match=!query||(option.dataset.search||option.textContent.toLowerCase()).includes(query);option.hidden=!match;if(match)matches++;});
    const label=document.getElementById('modalCustomerMatches');
    if(label)label.textContent=query?(matches+' customer'+(matches===1?'':'s')+' found'):'';
    if(query&&matches===1){const only=Array.from(select.options).find((o,i)=>i>0&&!o.hidden);if(only){select.value=only.value;selectModalCustomer();}}
}

function selectModalCustomer() {
    const modalSelect=document.getElementById('modalCustomerId');
    const mainSelect=document.getElementById('checkoutCustomerId');
    if(mainSelect&&modalSelect?.value){mainSelect.value=modalSelect.value;selectAdvanceCustomer();}
    document.getElementById('paymentModalCustomer').textContent=modalSelect?.value?modalSelect.options[modalSelect.selectedIndex].textContent:'Search and select a customer';
    updatePaymentModalSummary();
}

function openPaymentModal() {
    closeCustomerPaymentPopup();
    const select = document.getElementById('checkoutCustomerId');
    const customerId = parseInt(select?.value || '0',10);
    document.getElementById('modalCustomerId').value = customerId > 0 ? String(customerId) : '';
    document.querySelector('#paymentOverlay .m-head h2').textContent = 'Add Installment Payment';
    document.getElementById('paymentModalCustomer').textContent = customerId > 0 ? select.options[select.selectedIndex].textContent : '';
    setPaymentCustomerMode('existing');
    updatePaymentModalSummary();
    document.getElementById('paymentOverlay').classList.add('show');
}

function openNewCustomerInstallment() {
    closeCustomerPaymentPopup();
    document.querySelector('#paymentOverlay .m-head h2').textContent = 'New Customer Installment';
    document.getElementById('paymentModalCustomer').textContent = 'Create customer account and receive the first installment';
    setPaymentCustomerMode('new');
    updatePaymentModalSummary();
    document.getElementById('paymentOverlay').classList.add('show');
}

function closePaymentModal() { document.getElementById('paymentOverlay').classList.remove('show'); }

function chooseFullPayment() {
    document.querySelector('.pm-lbl')?.scrollIntoView({behavior:'smooth',block:'center'});
    document.getElementById('cash_given')?.focus({preventScroll:true});
}

function showAdvanceMode(mode) {
    const isNew = mode === 'new';
    document.getElementById('existingAdvancePanel').style.display = isNew ? 'none' : 'block';
    document.getElementById('newAdvancePanel').style.display = isNew ? 'block' : 'none';
    document.getElementById('existingAdvanceTab').classList.toggle('active', !isNew);
    document.getElementById('newAdvanceTab').classList.toggle('active', isNew);
}

function updateOrderFees() {
    const serviceToggle = document.getElementById('serviceChargeToggle');
    const packagingToggle = document.getElementById('packagingFeeToggle');
    const packagingInput = document.getElementById('packagingFeeInput');
    const chargeEl = document.getElementById('serviceChargeAmt');
    const subtotalEl = document.getElementById('subtotalAmt');
    const totalEl = document.getElementById('gt');
    const discountType = document.getElementById('discountType')?.value || 'fixed';
    const discountValue = Math.max(0, parseFloat(document.getElementById('discountValue')?.value) || 0);
    const discountEl = document.getElementById('discountAmt');
    const discountSummary = document.getElementById('discountSummary');
    const serviceCharge = serviceToggle && serviceToggle.checked ? CART_SUBTOTAL * 0.10 : 0;
    const packagingFee = packagingToggle && packagingToggle.checked ? Math.max(0, parseFloat(packagingInput?.value) || 0) : 0;

    if (packagingInput) {
        packagingInput.disabled = !(packagingToggle && packagingToggle.checked);
        if (!packagingToggle || !packagingToggle.checked) {
            packagingInput.value = '0.00';
        }
    }

    const beforeDiscount = CART_SUBTOTAL + serviceCharge + packagingFee;
    const discount = discountType === 'percentage'
        ? Math.min(CART_SUBTOTAL, CART_SUBTOTAL * Math.min(100, discountValue) / 100)
        : Math.min(beforeDiscount, discountValue);
    GT = Math.max(0, beforeDiscount - discount);
    const advanceInput = document.getElementById('advanceToUse');
    const maxAdvance = Math.max(0, parseFloat(advanceInput?.max) || 0);
    const useAdvance = document.getElementById('useAdvanceToggle')?.checked === true;
    let advanceUsed = useAdvance ? Math.min(maxAdvance, GT) : 0;
    if (advanceInput && parseFloat(advanceInput.value || 0) !== advanceUsed) advanceInput.value = advanceUsed.toFixed(2);
    AMOUNT_DUE = Math.max(0, GT - advanceUsed);

    if (subtotalEl) subtotalEl.textContent = CART_SUBTOTAL.toFixed(2);
    if (chargeEl) chargeEl.textContent = serviceCharge.toFixed(2);
    if (totalEl) totalEl.textContent = GT.toFixed(2);
    if (discountEl) discountEl.textContent = discount.toFixed(2);
    if (discountSummary) discountSummary.style.display = discount > 0 ? 'flex' : 'none';
    const remainingEl = document.getElementById('remainingAfterAdvance');
    if (remainingEl) remainingEl.textContent = AMOUNT_DUE.toFixed(2);
    const customerAdvanceAfter = document.getElementById('customerAdvanceAfter');
    const advanceRemaining = Math.max(0, maxAdvance - advanceUsed);
    if (customerAdvanceAfter) customerAdvanceAfter.textContent = advanceRemaining.toFixed(2);

    const pm = document.getElementById('pm_val')?.value || 'Cash';
    const ci = document.getElementById('cash_given');
    const fullyPaidByAdvance = useAdvance && AMOUNT_DUE <= 0.004;
    document.querySelectorAll('.pmb').forEach(button => {
        button.disabled = fullyPaidByAdvance;
        if (fullyPaidByAdvance) button.classList.remove('active');
        else button.classList.toggle('active', button.dataset.method === pm);
    });
    const paymentMethodLabel = document.querySelector('.pm-lbl');
    if (paymentMethodLabel) paymentMethodLabel.textContent = fullyPaidByAdvance ? 'Payment Method — Not required (paid by advance)' : 'Payment Method';
    const launchText = document.getElementById('customerAdvanceLaunchText');
    if (launchText && useAdvance) launchText.textContent = 'Using Rs. ' + advanceUsed.toFixed(2) + ' · Left Rs. ' + advanceRemaining.toFixed(2);
    if (ci && fullyPaidByAdvance) {
        ci.readOnly = true;
        ci.dataset.advanceLocked = '1';
        ci.value = '0.00';
        ci.placeholder = 'Fully paid by advance';
    } else if (ci && pm !== 'Cash') {
        ci.readOnly = true;
        delete ci.dataset.advanceLocked;
        ci.value = AMOUNT_DUE.toFixed(2);
    } else if (ci) {
        if (ci.dataset.advanceLocked === '1') ci.value = '';
        ci.readOnly = false;
        delete ci.dataset.advanceLocked;
        ci.placeholder = 'Enter cash amount…';
    }

    calcBal();
    if (GT > 0) {
        sendToCustomerDisplay(GT, "TOTAL");
    }
}

function updateServiceCharge() {
    updateOrderFees();
}

/* ==============================
   ORDER TYPE
============================== */
function setOT(t) {
    const ot = document.getElementById('ot_val');
    if (ot) ot.value = t;

    document.querySelectorAll('.otb').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.type === t);
    });

    if (!CURRENT_ORDER_ID) return;

    fetch(`pos.php?order_id=${encodeURIComponent(CURRENT_ORDER_ID)}&set_order_type=${encodeURIComponent(t)}&ajax=1`, {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            throw new Error('Order type was not saved.');
        }
    })
    .catch(err => {
        console.error('Order type update failed:', err);
        alert('Could not save order type. Please try again.');
    });
}

function setModalOT(t) {
    document.getElementById('modal_ot_val').value = t;
    document.getElementById('otm_dine').classList.toggle('active', t === 'retail');
    document.getElementById('otm_take').classList.toggle('active', t === 'wholesale');
    document.getElementById('tableFieldWrap').style.display = 'none';
}

/* ==============================
   PAYMENT METHOD
============================== */
function selMethod(m) {
    const pm = document.getElementById('pm_val');
    if (pm) pm.value = m;

    document.querySelectorAll('.pmb').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.method === m);
    });

    const ci = document.getElementById('cash_given');
    if (!ci) return;

    const fullyPaidByAdvance = document.getElementById('useAdvanceToggle')?.checked === true && AMOUNT_DUE <= 0.004;
    if (fullyPaidByAdvance) {
        ci.readOnly = true;
        ci.dataset.advanceLocked = '1';
        ci.value = '0.00';
        ci.placeholder = 'Fully paid by advance';
    } else if (m === 'Cash') {
        ci.readOnly = false;
        delete ci.dataset.advanceLocked;
        ci.value = '';
        ci.placeholder = 'Enter cash amount…';
    } else {
        ci.readOnly = true;
        ci.value = AMOUNT_DUE.toFixed(2);
        sendToCustomerDisplay(GT, "COLLECT");
    }

    calcBal();
}

/* ==============================
   BALANCE
============================== */
function calcBal() {
    const ci   = document.getElementById('cash_given');
    const pill = document.getElementById('balPill');
    const lbl  = document.getElementById('balLbl');
    const amt  = document.getElementById('balAmt');

    if (!ci || !pill || !lbl || !amt) return;

    const given = parseFloat(ci.value) || 0;
    const diff  = given - AMOUNT_DUE;

    pill.className = 'bal-pill';

    if (given === 0) {
        pill.classList.add('bp-zero');
        lbl.textContent = 'Balance / Change';
        amt.textContent = 'Rs. 0.00';
    } else if (diff >= 0) {
        pill.classList.add('bp-pos');
        lbl.textContent = 'Change to Return';
        amt.textContent = 'Rs. ' + diff.toFixed(2);
    } else {
        pill.classList.add('bp-neg');
        lbl.textContent = 'Amount Due';
        amt.textContent = 'Rs. ' + Math.abs(diff).toFixed(2);
    }
}

/* ==============================
   ADD ITEM AJAX
============================== */
function addItem(orderId, productId, el) {
    if (!orderId || !productId) return;

    const currentScrollY = window.scrollY;
    const params = new URLSearchParams(window.location.search);
    const currentCategory = params.get('category') || '';
    const currentSearch   = params.get('search') || '';

    let url = `pos.php?order_id=${encodeURIComponent(orderId)}&add=${encodeURIComponent(productId)}&ajax=1`;

    if (currentCategory) url += `&category=${encodeURIComponent(currentCategory)}`;
    if (currentSearch) url += `&search=${encodeURIComponent(currentSearch)}`;

    if (el) {
        el.classList.add('flash');
        el.style.pointerEvents = 'none';
    }

    fetch(url, {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(async response => {
        const text = await response.text();

        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Server did not return JSON:', text);
            throw new Error('Invalid JSON response from PHP.');
        }
    })
    .then(data => {
        if (!data.success) {
            alert('Error: ' + (data.message || 'Could not add item.'));
            return;
        }

        CART_SUBTOTAL = parseFloat(data.grand_total) || 0;
        updateOrderFees();

        const badge = document.querySelector('.count-badge');
        if (badge) {
            badge.textContent = data.cart_count + ' item' + (data.cart_count !== 1 ? 's' : '');
        }

        const gtEl = document.getElementById('gt');
        if (gtEl) {
            gtEl.textContent = GT.toFixed(2);
        }

        refreshCart(orderId, currentScrollY).then(() => {
            sendToCustomerDisplay(GT, "TOTAL");
        });

        showToast((data.item_name || 'Item') + ' added!');
        window.scrollTo(0, currentScrollY);
    })
    .catch(err => {
        console.error('Add item failed:', err);
        alert('Item add failed. Check PHP AJAX response.');
    })
    .finally(() => {
        if (el) {
            setTimeout(() => {
                el.classList.remove('flash');
                el.style.pointerEvents = '';
                window.scrollTo(0, currentScrollY);
            }, 300);
        }
    });
}

/* ==============================
   REFRESH CART
============================== */
function refreshCart(orderId, oldScrollY) {
    return new Promise((resolve, reject) => {
        const params = new URLSearchParams(window.location.search);

        let url = `pos.php?order_id=${encodeURIComponent(orderId)}&cart_partial=1`;

        const currentCategory = params.get('category') || '';
        const currentSearch   = params.get('search') || '';

        if (currentCategory) url += `&category=${encodeURIComponent(currentCategory)}`;
        if (currentSearch) url += `&search=${encodeURIComponent(currentSearch)}`;

        fetch(url, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            const newScroll = doc.querySelector('.cart-scroll');
            const newBadge  = doc.querySelector('.count-badge');
            const newTotal  = doc.querySelector('#gt');
            const newPaySec = doc.querySelector('.pay-section');

            const curScroll = document.querySelector('.cart-scroll');
            const curBadge  = document.querySelector('.count-badge');
            const curTotal  = document.getElementById('gt');
            const curPaySec = document.querySelector('.pay-section');
            const keepServiceCharge = document.getElementById('serviceChargeToggle')?.checked || false;
            const keepPackagingFee = document.getElementById('packagingFeeToggle')?.checked || false;
            const keepPackagingAmount = document.getElementById('packagingFeeInput')?.value || '0.00';
            const keepDiscountType = document.getElementById('discountType')?.value || 'fixed';
            const keepDiscountValue = document.getElementById('discountValue')?.value || '0.00';

            if (newScroll && curScroll) curScroll.innerHTML = newScroll.innerHTML;
            if (newBadge && curBadge) curBadge.textContent = newBadge.textContent;

            if (newTotal && curTotal) {
                curTotal.textContent = newTotal.textContent;
                GT = parseFloat(newTotal.textContent) || GT;
            }

            const newSubtotal = doc.querySelector('#subtotalAmt');
            const curSubtotal = document.getElementById('subtotalAmt');
            if (newSubtotal && curSubtotal) {
                curSubtotal.textContent = newSubtotal.textContent;
                CART_SUBTOTAL = parseFloat(newSubtotal.textContent) || CART_SUBTOTAL;
            }

            if (newPaySec && curPaySec) {
                curPaySec.innerHTML = newPaySec.innerHTML;
                const svc = document.getElementById('serviceChargeToggle');
                if (svc) svc.checked = keepServiceCharge;
                const pkg = document.getElementById('packagingFeeToggle');
                if (pkg) pkg.checked = keepPackagingFee;
                const pkgAmt = document.getElementById('packagingFeeInput');
                if (pkgAmt) pkgAmt.value = keepPackagingAmount;
                const discountType = document.getElementById('discountType');
                if (discountType) discountType.value = keepDiscountType;
                const discountValue = document.getElementById('discountValue');
                if (discountValue) discountValue.value = keepDiscountValue;
                setDiscountType(keepDiscountType);
                bindPaySection();
                bindOrderForm();
            }

            updateOrderFees();

            if (typeof oldScrollY === 'number') {
                window.scrollTo(0, oldScrollY);
            }

            resolve();
        })
        .catch(err => {
            console.error('Cart refresh failed:', err);
            reject(err);
        });
    });
}

/* ==============================
   BIND PAY SECTION
============================== */
function bindPaySection() {
    document.querySelectorAll('.pmb').forEach(btn => {
        btn.onclick = function () {
            selMethod(this.dataset.method);
        };
    });

    const ci = document.getElementById('cash_given');
    const svc = document.getElementById('serviceChargeToggle');
    const pkg = document.getElementById('packagingFeeToggle');
    const pkgAmt = document.getElementById('packagingFeeInput');

    if (svc) {
        svc.onchange = updateOrderFees;
    }

    if (pkg) {
        pkg.onchange = updateOrderFees;
    }

    if (pkgAmt) {
        pkgAmt.oninput = updateOrderFees;
    }

    if (ci) {
        ci.oninput = function () {
            calcBal();

            clearTimeout(displayCashTimer);

            displayCashTimer = setTimeout(() => {
                const given = parseFloat(ci.value) || 0;

                if (given > 0) {
                    sendToCustomerDisplay(given, "COLLECT");
                }
            }, 500);
        };
    }
}

/* ==============================
   CUSTOMER DISPLAY
============================== */
function sendToCustomerDisplay(amount, label) {
    const info = document.getElementById("qzInfo");

    if (info) {
        info.textContent = "Display: sending...";
    }

    fetch("http://localhost:3000/customer-display", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            amount: Number(amount) || 0,
            label: label || "TOTAL"
        })
    })
    .then(r => r.json())
    .then(data => {
        const latestInfo = document.getElementById("qzInfo");
        if (!latestInfo) return;

        if (data.success) {
            latestInfo.textContent = "Display: " + (label || "TOTAL") + " Rs. " + Number(amount).toFixed(2);
        } else {
            latestInfo.textContent = "Display Error: " + data.message;
        }
    })
    .catch(err => {
        const latestInfo = document.getElementById("qzInfo");
        if (latestInfo) latestInfo.textContent = "Display: Not connected";
        console.warn("Customer display:", err.message);
    });
}

function sendPaySequenceToDisplay(total, collect, change) {
    fetch("http://localhost:3000/customer-display-pay", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            total: Number(total) || 0,
            collect: Number(collect) || 0,
            change: Number(change) || 0
        })
    }).catch(console.warn);
}

/* ==============================
   TOAST
============================== */
(function createToastContainer() {
    if (document.getElementById('toastContainer')) return;

    const d = document.createElement('div');
    d.id = 'toastContainer';
    d.style.cssText = `
        position:fixed;
        bottom:20px;
        right:20px;
        z-index:9999;
        display:flex;
        flex-direction:column;
        gap:8px;
        pointer-events:none;
    `;
    document.body.appendChild(d);
})();

function showToast(msg) {
    const box = document.getElementById('toastContainer');
    if (!box) return;

    const t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = `
        background:#1a7a5e;
        color:#fff;
        padding:10px 18px;
        border-radius:10px;
        font-family:'Nunito',sans-serif;
        font-size:13px;
        font-weight:800;
        box-shadow:0 4px 14px rgba(0,0,0,.2);
        opacity:0;
        transform:translateY(8px);
        transition:opacity .2s, transform .2s;
    `;

    box.appendChild(t);

    requestAnimationFrame(() => {
        t.style.opacity = '1';
        t.style.transform = 'translateY(0)';
    });

    setTimeout(() => {
        t.style.opacity = '0';
        t.style.transform = 'translateY(8px)';
        setTimeout(() => t.remove(), 300);
    }, 1600);
}

/* ==============================
   MODALS
============================== */
function noOrderAlert() {
    alert('Please create or select an active sale first.\n\nClick "New Retail Sale" to start.');
}

function openOrderModal() {
    document.getElementById('orderOverlay')?.classList.add('show');
}

function closeOrderModal() {
    document.getElementById('orderOverlay')?.classList.remove('show');
}

function openAdminModal() {
    document.getElementById('adminOverlay')?.classList.add('show');
}

function closeAdminModal() {
    document.getElementById('adminOverlay')?.classList.remove('show');
}

const orderOverlay = document.getElementById('orderOverlay');
if (orderOverlay) {
    orderOverlay.addEventListener('click', function(e) {
        if (e.target === this) closeOrderModal();
    });
}

const priceOverlay = document.getElementById('priceOverlay');
if (priceOverlay) {
    priceOverlay.addEventListener('click', function(e) {
        if (e.target === this) closePriceModal();
    });
}

const paymentOverlay = document.getElementById('paymentOverlay');
if (paymentOverlay) {
    paymentOverlay.addEventListener('click', function(e) {
        if (e.target === this) closePaymentModal();
    });
}

const adminOverlay = document.getElementById('adminOverlay');
if (adminOverlay) {
    adminOverlay.addEventListener('click', function(e) {
        if (e.target === this) closeAdminModal();
    });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePaymentModal();
        closePriceModal();
        closeOrderModal();
        closeAdminModal();
    }
});

<?php if (!empty($admin_error) || isset($_GET['admin_login'])): ?>
window.addEventListener('load', function() {
    openAdminModal();
});
<?php endif; ?>

/* ==============================
   PAY FORM
============================== */
function bindOrderForm() {
    const oForm = document.getElementById('orderForm');
    if (!oForm || oForm.dataset.bound === "1") return;

    oForm.dataset.bound = "1";

    oForm.addEventListener('submit', function(e) {
        if (e.submitter && ['create_checkout_advance','add_checkout_installment'].includes(e.submitter.name)) return;
        const m = document.getElementById('pm_val')?.value || 'Cash';
        const given = parseFloat(document.getElementById('cash_given')?.value) || 0;
        const collect = given || AMOUNT_DUE;
        const change = Math.max(0, collect - AMOUNT_DUE);

        if (m === 'Cash' && given < AMOUNT_DUE) {
            alert('Cash given is less than the total amount. Please enter the correct amount.');
            e.preventDefault();
            return;
        }

        if (GT > 0) {
            sendPaySequenceToDisplay(GT, collect, change);
        }
    });
}

/* ==============================
   PAGE LOAD
============================== */
window.addEventListener("load", function () {
    document.querySelectorAll('.legacy-advance-box input,.legacy-advance-box select,.legacy-advance-box button').forEach(el => el.disabled=true);
    bindPaySection();
    bindOrderForm();
    updateOrderFees();
    selectAdvanceCustomer();
    <?php if ($advance_error): ?>showAdvanceMode('new');<?php endif; ?>

    if (GT > 0) {
        sendToCustomerDisplay(GT, "TOTAL");
    }
});
</script>
</body>
</html>
