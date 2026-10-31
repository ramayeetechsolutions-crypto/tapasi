<?php
session_start();
require 'db.php'; 

header('Content-Type: application/json');
$response = ['success' => false, 'error' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id']) || !isset($_POST['job_id'])) {
    $response['error'] = 'Invalid request. Missing ID or Job ID.';
    echo json_encode($response);
    exit;
}

$record_id = (int)$_POST['id'];
$job_id = $_POST['job_id'];
$employee_username = $_SESSION['username'] ?? 'Unknown User';
$current_datetime = date('Y-m-d H:i:s');

try {
    $mysqli->begin_transaction();

    // 1. Fetch start date and word count from the LE record
    $stmt_date = $mysqli->prepare("SELECT date, word_count FROM le WHERE id = ?"); 
    $stmt_date->bind_param("i", $record_id);
    $stmt_date->execute();
    $stmt_date->bind_result($start_date_str, $word_count);
    $stmt_date->fetch();
    $stmt_date->close();
    
    if (empty($word_count) || $word_count <= 0) {
        throw new Exception("Word Count must be entered before final completion.");
    }
    
    // 2. Calculate TAT (This is the crucial step now handled correctly here)
    $tat_value = null;
    if ($start_date_str) {
        $start_date = new DateTime($start_date_str);
        $end_date = new DateTime($current_datetime);
        $interval = $start_date->diff($end_date);
        
        $total_seconds = $interval->days * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
        $tat_days = $total_seconds / 86400;
        $tat_value = number_format($tat_days, 2); // TAT in days (two decimal places)
    }
    
    // 3. Update the LE record to FINAL 'Completed' status, including the TAT
    $stmt_le = $mysqli->prepare("
        UPDATE le SET 
            status = 'Completed', 
            tat = ?,
            last_updated = NOW()
        WHERE id = ?");
        
    if (!$stmt_le) {
        throw new Exception("LE Prepare failed: " . $mysqli->error);
    }
    
    // Note the order: done_by (string), le_date (string), tat (string), id (integer)
    $stmt_le->bind_param("si", $tat_value, $record_id);
    
    if (!$stmt_le->execute()) {
        throw new Exception("LE Execute failed: " . $stmt_le->error);
    }
    $stmt_le->close();

    // 4. Update the jobs table (assuming 'jobs' table has 'assigned_le' and 'le_status' columns)
    $stmt_jobs = $mysqli->prepare("UPDATE jobs SET assigned_le = ? WHERE job_id = ?");
    
    if (!$stmt_jobs) {
        throw new Exception("Jobs Prepare failed: " . $mysqli->error);
    }

    // Assuming job_id is an integer, based on context
    $stmt_jobs->bind_param("si", $employee_username, $job_id); 
    
    if (!$stmt_jobs->execute()) {
        throw new Exception("Jobs Execute failed: " . $stmt_jobs->error);
    }
    $stmt_jobs->close();

    $mysqli->commit();
    $response['success'] = true;

} catch (Exception $e) {
    $mysqli->rollback();
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
?>