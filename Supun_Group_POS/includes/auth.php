<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if (($_SESSION['role'] ?? '') === 'manager') $_SESSION['role'] = 'accountant';
if (!in_array($_SESSION["role"], ["admin", "accountant"], true)) {
    die("Access denied");
}
?>
