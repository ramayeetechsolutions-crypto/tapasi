<?php
require 'db.php'; // includes $mysqli
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Fetch all rows with plain passwords
$sql = "SELECT employee_id, password_hash FROM employee_master"; 
$result = $mysqli->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $id = $row['employee_id'];
        $plain = $row['password_hash']; // plain password

        // Generate bcrypt hash
        $hashed = md5($plain);

        // Update the hash column
        $update = $mysqli->prepare("UPDATE employee_master SET password_hash = ? WHERE employee_id = ?");
        $update->bind_param("si", $hashed, $id);

        if ($update->execute()) {
            echo "Updated ID $id — $plain → $hashed<br>";
        } else {
            echo "❌ Failed for ID $id: " . $update->error . "<br>";
        }
    }
    echo "<br><strong>✅ All passwords updated successfully!</strong>";
} else {
    echo "No records found.";
}
?>
