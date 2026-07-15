<?php

function purchaseNumber(int $id): string
{
    return 'PUR-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

function refreshPurchasePaymentStatus(mysqli $conn, int $purchaseId): void
{
    $stmt = $conn->prepare("SELECT total_amount,COALESCE((SELECT SUM(amount) FROM purchase_payments WHERE purchase_id=?),0) paid FROM purchases WHERE purchase_id=?");
    $stmt->bind_param('ii', $purchaseId, $purchaseId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return;
    $paid = (float)$row['paid'];
    $total = (float)$row['total_amount'];
    $status = $paid <= 0 ? 'unpaid' : ($paid + 0.005 >= $total ? 'paid' : 'partial');
    $update = $conn->prepare("UPDATE purchases SET paid_amount=?,payment_status=? WHERE purchase_id=?");
    $update->bind_param('dsi', $paid, $status, $purchaseId);
    $update->execute();
    $update->close();
}

function receivePurchase(mysqli $conn, int $purchaseId, int $userId): void
{
    $conn->begin_transaction();
    try {
        $purchaseStmt = $conn->prepare("SELECT status FROM purchases WHERE purchase_id=? FOR UPDATE");
        $purchaseStmt->bind_param('i', $purchaseId);
        $purchaseStmt->execute();
        $purchase = $purchaseStmt->get_result()->fetch_assoc();
        $purchaseStmt->close();
        if (!$purchase) throw new RuntimeException('Purchase not found.');
        if ($purchase['status'] !== 'draft') throw new RuntimeException('Only draft purchases can be received.');

        $itemsStmt = $conn->prepare("SELECT purchase_item_id,product_id,quantity,unit_cost FROM purchase_items WHERE purchase_id=?");
        $itemsStmt->bind_param('i', $purchaseId);
        $itemsStmt->execute();
        $items = $itemsStmt->get_result();
        if ($items->num_rows === 0) throw new RuntimeException('Purchase has no products.');

        while ($item = $items->fetch_assoc()) {
            $productId = (int)$item['product_id'];
            $quantity = (float)$item['quantity'];
            $unitCost = (float)$item['unit_cost'];
            if ($quantity <= 0) throw new RuntimeException('Invalid purchase quantity.');

            $productStmt = $conn->prepare("SELECT stock_qty,cost_price FROM products WHERE product_id=? FOR UPDATE");
            $productStmt->bind_param('i', $productId);
            $productStmt->execute();
            $product = $productStmt->get_result()->fetch_assoc();
            $productStmt->close();
            if (!$product) throw new RuntimeException('A purchase product no longer exists.');

            $before = (float)$product['stock_qty'];
            $after = $before + $quantity;
            $oldCost = (float)$product['cost_price'];
            $newCost = $after > 0 ? (($before * $oldCost) + ($quantity * $unitCost)) / $after : $unitCost;

            $updateProduct = $conn->prepare("UPDATE products SET stock_qty=?,cost_price=? WHERE product_id=?");
            $updateProduct->bind_param('ddi', $after, $newCost, $productId);
            $updateProduct->execute();
            $updateProduct->close();

            $note = 'ERP purchase ' . purchaseNumber($purchaseId);
            $totalCost = $quantity * $unitCost;
            $log = $conn->prepare("INSERT INTO stock_adjustments (product_id,user_id,adjustment_type,quantity,stock_before,stock_after,unit_cost,total_cost,note) VALUES (?,?,'stock_in',?,?,?,?,?,?)");
            $log->bind_param('iiddddds', $productId, $userId, $quantity, $before, $after, $unitCost, $totalCost, $note);
            $log->execute();
            $log->close();

            $receivedItem = $conn->prepare("UPDATE purchase_items SET received_qty=quantity WHERE purchase_item_id=?");
            $itemId = (int)$item['purchase_item_id'];
            $receivedItem->bind_param('i', $itemId);
            $receivedItem->execute();
            $receivedItem->close();
        }
        $itemsStmt->close();

        $receive = $conn->prepare("UPDATE purchases SET status='received',received_by=?,received_at=NOW() WHERE purchase_id=?");
        $receive->bind_param('ii', $userId, $purchaseId);
        $receive->execute();
        $receive->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

