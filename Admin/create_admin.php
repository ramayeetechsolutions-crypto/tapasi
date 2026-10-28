<?php
require 'db.php';

$username = "admin";
$password = "Admin@123"; // Change if you want

$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $mysqli->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
$stmt->bind_param("ss", $username, $hash);

if ($stmt->execute()) {
    echo "✅ Admin user created.<br> Username: $username<br> Password: $password";
} else {
    echo "⚠️ Error: " . $stmt->error;
}
