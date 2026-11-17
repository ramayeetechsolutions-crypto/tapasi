<?php
session_start();
require 'db.php';

header('Content-Type: application/json');
$response = ['success' => false, 'error' => '', 'tat_value' => '', 'ce_date' => '', 'due_date' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id']) || !isset($_POST['job_id'])) {
    $response['error'] = 'Invalid request. Missing ID or Job ID.';
    echo json_encode($response);
    exit;
}

$record_id = (int)$_POST['id'];
$job_id = htmlspecialchars($_POST['job_id']); 
$current_datetime = date('Y-m-d H:i:s');
$done_by = $_SESSION['username'] ?? 'System';

try {
    $mysqli->begin_transaction();
    
    // Step 1: Get the current due_date_ce for TAT calculation from the 'jobs' table
    $stmt_fetch = $mysqli->prepare("SELECT due_date_ce FROM jobs WHERE job_id = ?");
    if (!$stmt_fetch) {
        throw new Exception("Fetch preparation failed: " . $mysqli->error);
    }
    
    $stmt_fetch->bind_param("s", $job_id); // Assuming job_id is VARCHAR/string
    $stmt_fetch->execute();
    $stmt_fetch->bind_result($due_date_ce);
    
    if (!$stmt_fetch->fetch()) {
        throw new Exception("Job record not found or fetch failed in 'jobs' table for Job ID: " . $job_id);
    }
    $stmt_fetch->close();
    
    if (!$due_date_ce) {
        $due_date_ce = $current_datetime; 
    }
    
    // Calculate TAT
    $due_date = new DateTime($due_date_ce);
    $now = new DateTime($current_datetime);
    
    $interval = $due_date->diff($now);
    $tat_value = $interval->format('%a'); 
  //  echo $due_date;
//    die;
    // Step 2: Update the record in the 'ce' table
    $stmt_update = $mysqli->prepare("
        UPDATE ce
        SET status = 'Completed', 
            ce_date = ?, 
            done_by = ?, 
            tat = ?,
            eq_check = 2,       
            images_check = 2,
            tables_count_check = 2,
            ref_check = 2
        WHERE id = ?
    ");

    if (!$stmt_update) {
        throw new Exception("Update preparation failed: " . $mysqli->error);
    }

    $stmt_update->bind_param("sssi", $current_datetime, $done_by, $tat_value, $record_id);
    
    if (!$stmt_update->execute()) {
        throw new Exception("Update execution failed: " . $stmt_update->error);
    }
    
    if ($stmt_update->affected_rows === 0) {
        throw new Exception("Record ID " . $record_id . " not found in 'ce' table or already completed.");
    }
    
    $stmt_update->close();
    
    // Commit transaction
    $mysqli->commit();
    
    $response['success'] = true;
    $response['tat_value'] = $tat_value;
    $response['ce_date'] = $current_datetime;
    $response['due_date'] = $due_date_ce;
    
} catch (Exception $e) {
    $mysqli->rollback();
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
?>