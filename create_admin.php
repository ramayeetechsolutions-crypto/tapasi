<?php
require 'db.php';

// Define users you want to create
$users = [
    ["md_user", "Md@123"],   // MD
    ["hr_user", "Hr@123"],   // HR
    ["tl_user", "Tl@123"],   // TL
];

// Loop through and insert each
foreach ($users as $u) {
    [$username, $password] = $u;
    $hash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $mysqli->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    $stmt->bind_param("ss", $username, $hash);

    if ($stmt->execute()) {
        echo "✅ User created: $username<br>Password: $password<br><br>";
    } else {
        echo "⚠️ Error creating $username: " . $stmt->error . "<br><br>";
    }
    $stmt->close();
}

$mysqli->close();
?>
