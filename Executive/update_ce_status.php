<?php
require 'db.php';
session_start();

header('Content-Type: application/json');

// Enable error logging for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
error_log("Received AJAX request. POST data: " . json_encode($_POST));

$current_user = $_SESSION['username'] ?? '';
if (!$current_user) {
    error_log("Session expired. User not logged in.");
    echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.']);
    exit;
}

$action = $_POST['action'] ?? '';
$job_id = intval($_POST['job_id'] ?? 0);
$ce_id = intval($_POST['ce_id'] ?? 0);
$images_count = intval($_POST['images_count'] ?? 0);

$response = ['success' => false];

// Check for required IDs
if ($job_id === 0 && $ce_id === 0) {
    error_log("Invalid or missing job_id/ce_id.");
    echo json_encode(['success' => false, 'error' => 'Invalid ID.']);
    exit;
}

// Logic for the first click (red-bg to yellow-bg)
if ($action === 'start_editing') {
    $done_by = $current_user;
    $images = 0;
    
    // Check if an existing CE record exists for this job_id
    $stmt_check = $mysqli->prepare("SELECT id FROM ce WHERE job_id = ?");
    $stmt_check->bind_param("i", $job_id);
    $stmt_check->execute();
    $stmt_check->store_result();
    
    if ($stmt_check->num_rows > 0) {
        // Record exists, just update done_by
        $stmt_check->close();
        $stmt = $mysqli->prepare("UPDATE ce SET done_by = ?, last_updated = NOW() WHERE job_id = ?");
        if (!$stmt) {
            error_log("Prepare failed (start_editing UPDATE): " . $mysqli->error);
            echo json_encode(['success' => false, 'error' => 'Database error.']);
            exit;
        }
        $stmt->bind_param("si", $done_by, $job_id);
        $stmt->execute();
        $ce_id = $mysqli->insert_id > 0 ? $mysqli->insert_id : $ce_id;
        $stmt->close();
    } else {
        // No record exists, create a new one
        $stmt_check->close();
        $stmt = $mysqli->prepare("
            INSERT INTO ce (job_id, done_by, images, last_updated)
            VALUES (?, ?, ?, NOW())
        ");
        if (!$stmt) {
            error_log("Prepare failed (start_editing INSERT): " . $mysqli->error);
            echo json_encode(['success' => false, 'error' => 'Database error.']);
            exit;
        }
        $stmt->bind_param("isi", $job_id, $done_by, $images);
        $stmt->execute();
        $ce_id = $mysqli->insert_id;
        $stmt->close();
    }
    
    $response['success'] = true;
    $response['new_ce_id'] = $ce_id;
    
} else if ($action === 'complete_editing') {
    // This action is triggered on the second click (yellow-bg to green-bg)
    $ce_date = date('Y-m-d H:i:s');
    $stmt = $mysqli->prepare("
        UPDATE ce
        SET images = ?, ce_date = ?, last_updated = NOW()
        WHERE id = ?
    ");
    if (!$stmt) {
        error_log("Prepare failed (complete_editing): " . $mysqli->error);
        echo json_encode(['success' => false, 'error' => 'Database error.']);
        exit;
    }
    $stmt->bind_param("isi", $images_count, $ce_date, $ce_id);
    if ($stmt->execute()) {
        $response['success'] = true;
        $response['images_count'] = $images_count;
        $response['ce_date'] = date('Y-m-d');
    } else {
        error_log("Execution failed (complete_editing): " . $stmt->error);
        $response['error'] = 'Execution failed: ' . $stmt->error;
    }
    $stmt->close();
}

echo json_encode($response);
?>