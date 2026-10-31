<?php
session_start();
require 'db.php'; // Includes your $mysqli connection

header('Content-Type: application/json');
$response = ['success' => false, 'error' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    $response['error'] = 'Invalid request. Missing record ID.';
    echo json_encode($response);
    exit;
}

$record_id = (int)$_POST['id'];
$employee_username = $_SESSION['username'] ?? null;
$current_datetime = date('Y-m-d H:i:s');

if (!$employee_username) {
    $response['error'] = 'User session not found. Please log in.';
    echo json_encode($response);
    exit;
}

try {
    // Update the record: Set status to 'In Progress', stamp assignment time, and set done_by
    $stmt_update = $mysqli->prepare("
        UPDATE ce
        SET status = 'In Progress', 
            ce_date = ?,           
            done_by = ?           
        WHERE id = ?
    ");
    
    if (!$stmt_update) {
        throw new Exception("Update preparation failed: " . $mysqli->error);
    }

    $stmt_update->bind_param("ssi", $current_datetime, $employee_username, $record_id);
    
    if (!$stmt_update->execute()) {
        throw new Exception("Update execution failed: " . $stmt_update->error);
    }
    
    if ($stmt_update->affected_rows === 0) {
        throw new Exception("Record ID " . $record_id . " not found, or status was not 'Need to Assign'.");
    }
    
    $stmt_update->close();
    
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
?>