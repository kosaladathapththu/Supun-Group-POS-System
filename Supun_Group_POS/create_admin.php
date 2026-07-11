<?php
include 'db.php';

$name = "Admin User";
$username = "admin";
$password = password_hash("123456", PASSWORD_DEFAULT);
$role = "admin";

$sql = "INSERT INTO users (full_name, username, password, role)
        VALUES ('$name', '$username', '$password', '$role')";

if ($conn->query($sql)) {
    echo "Admin created successfully";
} else {
    echo "Error: " . $conn->error;
}
?>