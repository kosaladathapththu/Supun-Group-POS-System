<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if (!in_array($_SESSION["role"], ["admin", "manager"], true)) {
    die("Access denied");
}
?>
