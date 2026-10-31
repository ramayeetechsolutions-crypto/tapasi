<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

$response = [
    'success' => false,
    'error' => '',
    'new_value' => '',
    'done_by' => '',
    'ce_date' => ''
];

// Check if all required parameters are present
if (!isset($_POST['id']) || !isset($_POST['field']) || !isset($_POST['value'])) {
    $response['error'] = 'Missing parameters';
    echo json_encode($response);
    exit;
}

// Validate user
if (!isset($_SESSION['username'])) {
    $response['error'] = 'User not logged in';
    echo json_encode($response);
    exit;
}

$id = (int)$_POST['id'];
$field = $_POST['field'];
$newValue = $_POST['value'];
$username = $_SESSION['username'];
$currentDateTime = date('Y-m-d H:i:s');
$currentDate = date('d-m');

// Validate field
$allowedFields = ['eq', 'images', 'tables_count', 'word_count'];
if (!in_array($field, $allowedFields)) {
    $response['error'] = 'Invalid field';
    echo json_encode($response);
    exit;
}

// Format the new value based on field type
$formattedValue = $newValue;
if (in_array($field, ['eq', 'images', 'tables_count'])) {
    // For EQ, Images, Tables: username/date/count
    $formattedValue = "$username/$currentDate/$newValue";
}

// Update the record
$sql = "UPDATE ce SET 
        $field = ?,
        done_by = ?,
        ce_date = ?,
        status = IF(status = 'Need to Assign', 'In Progress', status)
        WHERE id = ?";
        
$stmt = $mysqli->prepare($sql);
if ($stmt) {
    $stmt->bind_param("sssi", $formattedValue, $username, $currentDateTime, $id);
    
    if ($stmt->execute()) {
        $response['success'] = true;
        $response['new_value'] = $formattedValue;
        $response['done_by'] = $username;
        $response['ce_date'] = $currentDateTime;
        
        // Also update the check flag for tracking
        if ($field != 'word_count') {
            $checkField = $field . '_check';
            $checkSql = "UPDATE ce SET $checkField = 2 WHERE id = ?";
            $checkStmt = $mysqli->prepare($checkSql);
            if ($checkStmt) {
                $checkStmt->bind_param("i", $id);
                $checkStmt->execute();
                $checkStmt->close();
            }
        }
    } else {
        $response['error'] = 'Database update failed: ' . $stmt->error;
    }
    $stmt->close();
} else {
    $response['error'] = 'SQL preparation failed: ' . $mysqli->error;
}

echo json_encode($response);
?>