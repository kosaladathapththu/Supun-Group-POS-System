<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (!in_array($_SESSION['role'] ?? '', ['cashier', 'admin'], true)) {
    http_response_code(403);
    exit('Access denied.');
}
header('Location: admin/product_import.php?cashier_mode=1');
exit;
