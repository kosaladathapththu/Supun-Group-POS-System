<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

if ($_SESSION["role"] !== "cashier" && $_SESSION["role"] !== "admin") {
    header("Location: ../login.php");
    exit();
}

header("Location: products.php");
exit();
