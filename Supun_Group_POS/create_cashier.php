<?php
include 'db.php';

$name = "Cashier User";
$username = "cashier";
$password = password_hash("123456", PASSWORD_DEFAULT);
$role = "cashier";

$sql = "INSERT INTO users (full_name, username, password, role)
        VALUES ('$name', '$username', '$password', '$role')";

if ($conn->query($sql)) {
    echo "Cashier created successfully";
} else {
    echo "Error: " . $conn->error;
}
?>