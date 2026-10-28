<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require 'db.php';
if ($mysqli->connect_error) {
    header('Content-Type: application/json');
    die(json_encode(["error" => "Connection failed: " . $mysqli->connect_error]));
}

function refValues($arr){
    if (version_compare(PHP_VERSION, '5.3.0', '>=')) {
        $refs = array();
        foreach($arr as $key => $value)
            $refs[$key] = &$arr[$key];
        return $refs;
    }
    return $arr;
}

$uid = $_SESSION['uid'] ?? 0;
$username = $_SESSION['username'] ?? '';
$role_name = $_SESSION['role_name'] ?? '';
$job_id = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
$show_completed = isset($_GET['show_completed']) && $_GET['show_completed'] == '1';

// Check user permissions for editing
$canEdit = false;
$allowed_roles = ['TM', 'XML', 'Team Lead', 'Admin']; // Add roles that can edit

if(in_array($role_name, $allowed_roles)) {
    $canEdit = true;
}

$canEditJson = json_encode($canEdit);

// Handle AJAX requests (Update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update') {
        handleUpdateRequest();
    } elseif ($_POST['action'] === 'complete') {
        handleCompleteRequest();
    } elseif ($_POST['action'] === 'assign_to_me') {
        handleAssignToMeRequest();
    } elseif ($_POST['action'] === 'update_sequence') {
        handleUpdateSequenceRequest();
    } elseif ($_POST['action'] === 'update_status_bill') {
        handleStatusBillRequest();
    }
    exit;
}

// Handle data fetch request
if (isset($_GET['fetch']) && $_GET['fetch'] === 'data') {
    fetchData();
    exit;
}

function handleStatusBillRequest() {
    global $mysqli, $username;
    $response = ['success' => false, 'error' => ''];
    
    if (!isset($_POST['id']) || !isset($_POST['status']) || !isset($_POST['billed'])) {
        $response['error'] = 'Invalid request. Missing parameters.';
        echo json_encode($response);
        exit;
    }
    
    $record_id = (int)$_POST['id'];
    $status = $_POST['status'];
    $billed = $_POST['billed'];
    $remarks = $_POST['remarks'] ?? '';
    $final_remarks = $_POST['final_remarks'] ?? '';
    
    try {
        $current_datetime = date('Y-m-d H:i:s');
        
        // Get job_id from unique_xml_jobs
        $stmt = $mysqli->prepare("SELECT job_id FROM unique_xml_jobs WHERE id = ?");
        $stmt->bind_param("i", $record_id);
        $stmt->execute();
        $stmt->bind_result($xml_job_id);
        $stmt->fetch();
        $stmt->close();
        
        $query = "UPDATE unique_xml_jobs SET 
                  status = ?,
                  Billed = ?,
                  remarks = ?,
                  `final Remarks` = ?,
                  updated_at = ?,
                  updated_by = ?
                  WHERE id = ?";
        
        $stmt = $mysqli->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }
        
        $stmt->bind_param("ssssssi", $status, $billed, $remarks, $final_remarks, $current_datetime, $username, $record_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $stmt->close();
        
        // If status is set to Completed, update the jobs table
        if ($status === 'Completed' && $xml_job_id) {
            updateJobsTableForXMLCompletion($xml_job_id, $username);
        }
        
        $response['success'] = true;
        $response['message'] = 'Status and billing updated successfully.';
        
    } catch (Exception $e) {
        $response['error'] = $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

function handleAssignToMeRequest() {
    global $mysqli, $username;
    
    $response = ['success' => false, 'error' => '', 'message' => ''];
    
    if (!isset($_POST['id']) || !isset($_POST['field'])) {
        $response['error'] = 'Invalid request. Missing ID or field.';
        echo json_encode($response);
        exit;
    }

    $record_id = (int)$_POST['id'];
    $field = $_POST['field'] ?? '';
    $sequence_number = $_POST['seq_number'] ?? 1;
    
    $valid_fields = ['Image_user', 'Table_user', 'WPContent_user', 'HTML_user', 'Scielo_user', 'QC_user'];
    
    if (!in_array($field, $valid_fields)) {
        $response['error'] = 'Invalid field.';
        echo json_encode($response);
        exit;
    }

    try {
        $current_date = date('d-m'); // Format: date-month
        $assign_value = $username . '/' . $current_date . '/' . $sequence_number;
        
        // Check current value
        $check_stmt = $mysqli->prepare("SELECT $field FROM unique_xml_jobs WHERE id = ?");
        $check_stmt->bind_param("i", $record_id);
        $check_stmt->execute();
        $check_stmt->bind_result($current_value);
        $check_stmt->fetch();
        $check_stmt->close();
        
        if (!empty($current_value)) {
            // Parse existing value
            $parts = explode('/', $current_value);
            if (count($parts) === 3 && $parts[0] === $username) {
                // Already assigned to current user, just update sequence
                $assign_value = $username . '/' . $current_date . '/' . $sequence_number;
                $response['message'] = 'Sequence updated successfully.';
            } else {
                $response['error'] = 'Already assigned to ' . $parts[0];
                echo json_encode($response);
                exit;
            }
        } else {
            $response['message'] = 'Assigned to you successfully.';
        }
        
        // Update the field
        $query = "UPDATE unique_xml_jobs SET 
                  $field = ?, 
                  updated_at = NOW(),
                  status = CASE WHEN status = 'Need to Assign' THEN 'In Progress' ELSE status END
                  WHERE id = ?";
        
        $stmt = $mysqli->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }
        
        $stmt->bind_param("si", $assign_value, $record_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $stmt->close();
        $response['success'] = true;
        $response['assign_value'] = $assign_value;
        
    } catch (Exception $e) {
        $response['error'] = $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

function handleUpdateSequenceRequest() {
    global $mysqli, $username;
    
    $response = ['success' => false, 'error' => '', 'message' => ''];
    
    if (!isset($_POST['id']) || !isset($_POST['field']) || !isset($_POST['seq_number'])) {
        $response['error'] = 'Invalid request. Missing parameters.';
        echo json_encode($response);
        exit;
    }

    $record_id = (int)$_POST['id'];
    $field = $_POST['field'] ?? '';
    $sequence_number = (int)$_POST['seq_number'];
    
    $valid_fields = ['Image_user', 'Table_user', 'WPContent_user', 'HTML_user', 'Scielo_user', 'QC_user'];
    
    if (!in_array($field, $valid_fields)) {
        $response['error'] = 'Invalid field.';
        echo json_encode($response);
        exit;
    }

    try {
        // Get current value
        $check_stmt = $mysqli->prepare("SELECT $field FROM unique_xml_jobs WHERE id = ?");
        $check_stmt->bind_param("i", $record_id);
        $check_stmt->execute();
        $check_stmt->bind_result($current_value);
        $check_stmt->fetch();
        $check_stmt->close();
        
        if (empty($current_value)) {
            $response['error'] = 'Field not assigned yet.';
            echo json_encode($response);
            exit;
        }
        
        // Parse current value
        $parts = explode('/', $current_value);
        if (count($parts) !== 3) {
            $response['error'] = 'Invalid format in existing value.';
            echo json_encode($response);
            exit;
        }
        
        // Check if current user is the assignee
        if ($parts[0] !== $username) {
            $response['error'] = 'You are not assigned to this task.';
            echo json_encode($response);
            exit;
        }
        
        // Update with new sequence number
        $current_date = date('d-m');
        $new_value = $username . '/' . $current_date . '/' . $sequence_number;
        
        $query = "UPDATE unique_xml_jobs SET 
                  $field = ?, 
                  updated_at = NOW()
                  WHERE id = ?";
        
        $stmt = $mysqli->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }
        
        $stmt->bind_param("si", $new_value, $record_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $stmt->close();
        $response['success'] = true;
        $response['message'] = 'Sequence number updated successfully.';
        $response['new_value'] = $new_value;
        
    } catch (Exception $e) {
        $response['error'] = $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

function handleUpdateRequest() {
    global $mysqli, $username;
    $response = [
        'success' => false, 
        'error' => '', 
        'updated_by' => $username,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    if (!isset($_POST['id']) || !isset($_POST['field'])) {
        $response['error'] = 'Invalid request. Missing ID or field.';
        echo json_encode($response);
        exit;
    }
    
    $record_id = (int)$_POST['id'];
    $field = $_POST['field'];
    $value = $_POST['value'] ?? '';
    
    // Define which fields can be updated and their validation
    $editable_fields = [
        'xml_status',
        'scielo_ebsco_status', 
        'pubmed_biblat_status',
        'html_status',
        'tat_to_client',
        'missed_tat',
        'instruction_to_image',
        'remarks',
        'final Remarks',
        'Billed',
        'status'
    ];
    
    if (!in_array($field, $editable_fields)) {
        $response['error'] = 'Field not editable.';
        echo json_encode($response);
        exit;
    }
    
    try {
        $current_datetime = date('Y-m-d H:i:s');
        
        $query = "UPDATE unique_xml_jobs SET 
                  $field = ?, 
                  updated_at = ?,
                  updated_by = ?
                  WHERE id = ?";
        
        $stmt = $mysqli->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }
        
        $stmt->bind_param("sssi", $value, $current_datetime, $username, $record_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $stmt->close();
        
        // If status field is updated to 'Completed', check if we need to update jobs table
        if ($field === 'status' && $value === 'Completed') {
            // Get job_id from unique_xml_jobs
            $stmt = $mysqli->prepare("SELECT job_id FROM unique_xml_jobs WHERE id = ?");
            $stmt->bind_param("i", $record_id);
            $stmt->execute();
            $stmt->bind_result($xml_job_id);
            $stmt->fetch();
            $stmt->close();
            
            if ($xml_job_id) {
                updateJobsTableForXMLCompletion($xml_job_id, $username);
            }
        }
        
        $response['success'] = true;
        
    } catch (Exception $e) {
        $response['error'] = $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

function handleCompleteRequest() {
    global $mysqli, $username;
    $response = ['success' => false, 'error' => ''];
    
    if (!isset($_POST['id']) || !isset($_POST['field'])) {
        $response['error'] = 'Invalid request. Missing ID or field.';
        echo json_encode($response);
        exit;
    }

    $record_id = (int)$_POST['id'];
    $field = $_POST['field'];
    
    $valid_fields = ['Image_user', 'Table_user', 'WPContent_user', 'HTML_user', 'Scielo_user', 'QC_user'];
    
    if (!in_array($field, $valid_fields)) {
        $response['error'] = 'Invalid field.';
        echo json_encode($response);
        exit;
    }

    try {
        $current_datetime = date('Y-m-d H:i:s');
        
        // Start transaction
        $mysqli->begin_transaction();
        
        // Get current user for this field and job_id
        $check_stmt = $mysqli->prepare("SELECT $field, job_id FROM unique_xml_jobs WHERE id = ?");
        $check_stmt->bind_param("i", $record_id);
        $check_stmt->execute();
        $check_stmt->bind_result($current_value, $xml_job_id);
        $check_stmt->fetch();
        $check_stmt->close();
        
        if (empty($current_value)) {
            throw new Exception("This task is not assigned yet.");
        }
        
        // Parse current value to get username
        $parts = explode('/', $current_value);
        $current_user = $parts[0] ?? '';
        
        if ($current_user !== $username) {
            throw new Exception("This task is assigned to $current_user, not you.");
        }
        
        // Mark as completed - add completion indicator
        $new_value = $current_value . ' ✓';
        
        $query = "UPDATE unique_xml_jobs SET 
                  $field = ?,
                  updated_at = ?,
                  updated_by = ?
                  WHERE id = ?";
        
        $stmt = $mysqli->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }
        
        $stmt->bind_param("sssi", $new_value, $current_datetime, $username, $record_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $stmt->close();
        
        // Check if all fields are completed to mark overall job as completed
        $check_completion_stmt = $mysqli->prepare("
            SELECT 
                Image_user, Table_user, WPContent_user, 
                HTML_user, Scielo_user, QC_user,
                xml_status, scielo_ebsco_status, pubmed_biblat_status, html_status
            FROM unique_xml_jobs 
            WHERE id = ?
        ");
        $check_completion_stmt->bind_param("i", $record_id);
        $check_completion_stmt->execute();
        $check_completion_stmt->bind_result(
            $image_user, $table_user, $wpcontent_user,
            $html_user, $scielo_user, $qc_user,
            $xml_status, $scielo_status, $pubmed_status, $html_status
        );
        $check_completion_stmt->fetch();
        $check_completion_stmt->close();
        
        // Check if all required tasks are completed
        $all_completed = true;
        
        // Check user assignments (check if they have completion mark)
        $user_fields = [
            'Image_user' => $image_user,
            'Table_user' => $table_user,
            'WPContent_user' => $wpcontent_user,
            'HTML_user' => $html_user,
            'Scielo_user' => $scielo_user,
            'QC_user' => $qc_user
        ];
        
        foreach ($user_fields as $field_name => $user_field) {
            if (empty($user_field) || strpos($user_field, '✓') === false) {
                $all_completed = false;
                break;
            }
        }
        
        // Check status fields (assuming 'Completed' status)
        $status_fields = [$xml_status, $scielo_status, $pubmed_status, $html_status];
        foreach ($status_fields as $status_field) {
            if ($status_field !== 'Completed' && !empty($status_field)) {
                $all_completed = false;
                break;
            }
        }
        
        if ($all_completed) {
            // Update overall status to Completed in unique_xml_jobs
            $complete_stmt = $mysqli->prepare("
                UPDATE unique_xml_jobs SET 
                status = 'Completed',
                updated_at = ?,
                updated_by = ?
                WHERE id = ?
            ");
            $complete_stmt->bind_param("ssi", $current_datetime, $username, $record_id);
            $complete_stmt->execute();
            $complete_stmt->close();
            
            // Update the jobs table with the username who completed it
            if ($xml_job_id) {
                updateJobsTableForXMLCompletion($xml_job_id, $username);
            }
            
            $response['all_completed'] = true;
            $response['message'] = 'Job marked as completed. All tasks are done!';
        } else {
            $response['all_completed'] = false;
            $response['message'] = 'Task completed successfully.';
        }
        
        $mysqli->commit();
        $response['success'] = true;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $response['error'] = $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

function updateJobsTableForXMLCompletion($job_id, $username) {
    global $mysqli;
    
    // Check if job exists in jobs table
    $check_stmt = $mysqli->prepare("SELECT job_id FROM jobs WHERE job_id = ?");
    $check_stmt->bind_param("i", $job_id);
    $check_stmt->execute();
    $check_stmt->store_result();
    $job_exists = $check_stmt->num_rows > 0;
    $check_stmt->close();
    
    if ($job_exists) {
        // Update assigned_xml field in jobs table
        $update_stmt = $mysqli->prepare("
            UPDATE jobs 
            SET assigned_xml = ?,
                due_date_xml = CASE WHEN due_date_xml IS NULL THEN CURDATE() ELSE due_date_xml END
            WHERE job_id = ?
        ");
        $update_stmt->bind_param("si", $username, $job_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        // Also update the process status if needed
     /*   $process_stmt = $mysqli->prepare("
            UPDATE jobs 
            SET status = 'Completed'
            WHERE job_id = ? AND assigned_xml IS NOT NULL
        ");
        $process_stmt->bind_param("i", $job_id);
        $process_stmt->execute();
        $process_stmt->close();
        */
        return true;
    }
    
    return false;
}

function fetchData() {
    global $mysqli, $job_id, $username, $show_completed;
    
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    // Build the base query
    $base_query = "SELECT 
                    u.id, 
                    u.job_id, 
                    u.rec_date, 
                    u.journal_name_client, 
                    u.issue, 
                    u.articles, 
                    u.xml_status, 
                    u.scielo_ebsco_status, 
                    u.pubmed_biblat_status, 
                    u.html_status, 
                    u.tat_to_client, 
                    u.missed_tat, 
                    u.instruction_to_image, 
                    u.remarks, 
                    u.created_at, 
                    u.created_by, 
                    u.updated_at, 
                    u.Image_user, 
                    u.Table_user, 
                    u.WPContent_user, 
                    u.HTML_user, 
                    u.Scielo_user, 
                    u.QC_user, 
                    u.`final Remarks`, 
                    u.Billed, 
                    u.status,
                    j.assigned_xml as jobs_assigned_xml,
                    j.process_id,
                    j.job_date,
                    j.mail_subject,
                    j.word_count as jobs_word_count
                  FROM unique_xml_jobs u
                  LEFT JOIN jobs j ON u.job_id = j.job_id
                  WHERE 1=1";

    // Add filters
    $params = [];
    $types = '';
    
    if ($job_id > 0) {
        $base_query .= " AND u.job_id = ?";
        $params[] = $job_id;
        $types .= 'i';
    }
    
    // Add status filter if not showing completed
    if (!$show_completed) {
        $base_query .= " AND (u.status IS NULL OR u.status != 'Completed')";
    }
    
    $order_query = " ORDER BY 
                    CASE 
                        WHEN u.status = 'In Progress' THEN 1
                        WHEN u.status = 'Need to Assign' THEN 2
                        WHEN u.status = 'Completed' THEN 3
                        ELSE 4
                    END,
                    u.rec_date DESC";
    
    $full_query = $base_query . $order_query;
    
    if (!empty($params)) {
        $stmt = $mysqli->prepare($full_query);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            // If prepare fails, fall back to regular query
            $result = $mysqli->query($full_query);
        }
    } else {
        $result = $mysqli->query($full_query);
    }
    
    $data = [];
    $current_date = new DateTime();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            foreach ($row as $key => $value) {
                if ($value === null) {
                    $row[$key] = '';
                }
            }

            // Calculate TAT status
            $tat_status = '';
            if (!empty($row['rec_date']) && !empty($row['tat_to_client'])) {
                try {
                    $rec_date = new DateTime($row['rec_date']);
                    $current_date = new DateTime();
                    $interval = $rec_date->diff($current_date);
                    $days_passed = $interval->days;
                    
                    if ($days_passed > (int)$row['tat_to_client']) {
                        $tat_status = 'Overdue';
                    } elseif ($days_passed == (int)$row['tat_to_client']) {
                        $tat_status = 'Due Today';
                    } else {
                        $days_left = (int)$row['tat_to_client'] - $days_passed;
                        $tat_status = $days_left . ' days left';
                    }
                } catch (Exception $e) {
                    $tat_status = 'N/A';
                }
            }
            $row['tat_status'] = $tat_status;

            // Check which tasks are assigned to current user and extract details
            $row['my_tasks'] = [];
            $task_fields = [
                'Image_user' => 'Image Processing',
                'Table_user' => 'Table Processing',
                'WPContent_user' => 'WP Content',
                'HTML_user' => 'HTML',
                'Scielo_user' => 'Scielo/EBSCO',
                'QC_user' => 'QC'
            ];
            
            foreach ($task_fields as $field => $task_name) {
                if (!empty($row[$field])) {
                    $parts = explode('/', $row[$field]);
                    if (isset($parts[0]) && $parts[0] === $username) {
                        $row['my_tasks'][$field] = [
                            'name' => $task_name,
                            'value' => $row[$field],
                            'username' => $parts[0] ?? '',
                            'date' => $parts[1] ?? '',
                            'sequence' => $parts[2] ?? '',
                            'is_completed' => strpos($row[$field], '✓') !== false
                        ];
                    }
                }
            }

            // Count completed tasks
            $row['completed_tasks'] = 0;
            $row['total_tasks'] = 0;
            
            foreach ($task_fields as $field => $task_name) {
                if (!empty($row[$field])) {
                    $row['total_tasks']++;
                    if (strpos($row[$field], '✓') !== false) {
                        $row['completed_tasks']++;
                    }
                } else {
                    $row['total_tasks']++;
                }
            }

            // Add status flags
            $row['is_assigned_to_me'] = !empty($row['my_tasks']);
            $row['progress_percentage'] = $row['total_tasks'] > 0 ? 
                round(($row['completed_tasks'] / $row['total_tasks']) * 100) : 0;
                
            // Check if job is already assigned in jobs table
            $row['jobs_xml_assigned'] = !empty($row['jobs_assigned_xml']);
            $row['jobs_xml_assignee'] = $row['jobs_assigned_xml'] ?? '';

            $data[] = $row;
        }
        if (isset($stmt)) {
            $stmt->close();
        }
    } else {
        // Log error for debugging
        error_log("Query failed: " . $mysqli->error . " Query: " . $full_query);
    }
    
    echo json_encode(['data' => $data]);
    exit;
}
require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>XML Jobs Management - <?php echo $job_id > 0 ? "Job ID: $job_id" : 'All Jobs'; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .spin {
            animation: spin 1s linear infinite;
        }
        td.editable { cursor: pointer; } 
        .status-badge { font-size: 0.8em; }
        .overdue { background-color: #dc3545 !important; color: white !important; }
        .due-today { background-color: #ffc107 !important; color: black !important; }
        .on-track { background-color: #28a745 !important; color: white !important; }
        .task-badge { 
            font-size: 0.7em; 
            margin: 1px;
            padding: 2px 5px;
        }
        .progress-sm {
            height: 5px;
            margin-top: 3px;
        }
        .assign-btn {
            font-size: 0.7em;
            padding: 1px 4px;
            margin: 1px;
        }
        .my-task-row {
            background-color: #e8f4f8 !important;
        }
        .table th { font-size: 0.85rem; }
        .table td { font-size: 0.82rem; }
        .completed-row {
            opacity: 0.7;
        }
        .status-filter {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        .completed-task {
            text-decoration: line-through;
            color: #28a745;
            font-weight: bold;
        }
        .sequence-input {
            width: 60px;
            display: inline-block;
            margin-left: 5px;
        }
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            min-width: 300px;
            max-width: 400px;
        }
        .toast {
            opacity: 1 !important;
        }
        .status-bill-group {
            background-color: #f0f8ff;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .jobs-info {
            background-color: #fff3cd;
            padding: 5px 10px;
            border-radius: 4px;
            margin: 2px 0;
            font-size: 0.75rem;
        }
        .jobs-assigned {
            background-color: #d1ecf1;
            padding: 5px 10px;
            border-radius: 4px;
            margin: 2px 0;
            font-size: 0.75rem;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Notification Toast Container -->
    <div id="notificationContainer" class="notification"></div>
    
    <div class="container-fluid mt-3">
        <div class="card shadow">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h4 class="mb-0">XML Jobs Management <?php echo $job_id > 0 ? "- Job ID: $job_id" : ''; ?></h4>
                <div class="d-flex align-items-center">
                    <span class="me-3">Logged in as: <strong><?php echo htmlspecialchars($username); ?></strong></span>
                    
                    <!-- Show Completed toggle -->
                    <div class="form-check form-check-inline me-3">
                        <input class="form-check-input" type="checkbox" id="showCompleted" 
                               <?php echo $show_completed ? 'checked' : ''; ?>
                               onchange="toggleShowCompleted()">
                        <label class="form-check-label text-white" for="showCompleted">
                            Show Completed
                        </label>
                    </div>
                    
                    <?php if($job_id > 0): ?>
                        <a href="unassigned_xml_jobs.php" class="btn btn-light btn-sm">View All Jobs</a>
                    <?php else: ?>
                        <div class="input-group" style="width: 250px;">
                            <input type="number" id="jobIdFilter" class="form-control" placeholder="Enter Job ID" value="<?php echo $job_id > 0 ? $job_id : ''; ?>">
                            <button class="btn btn-light" onclick="filterByJobId()">
                                <i class="bi bi-filter"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <!-- Status Summary -->
                <div class="row mb-3 status-filter">
                    <div class="col-md-12">
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="filterByStatus('')">All</button>
                            <button type="button" class="btn btn-outline-warning btn-sm" onclick="filterByStatus('In Progress')">In Progress</button>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="filterByStatus('Need to Assign')">Need to Assign</button>
                            <button type="button" class="btn btn-outline-success btn-sm" onclick="filterByStatus('Completed')">Completed</button>
                            <button type="button" class="btn btn-outline-info btn-sm" onclick="filterByStatus('assigned_to_me')">My Tasks</button>
                        </div>
                    </div>
                </div>
                
                <table id="xmlJobsTable" class="table table-hover table-bordered w-100">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Job ID</th>
                            <th>Date</th>
                            <th>Journal/Client</th>
                            <th>Issue</th>
                            <th>Articles</th>
                            
                            <!-- Status Columns -->
                            <th>XML Status</th>
                            <th>Scielo/EBSCO</th>
                            <th>PubMed/Biblat</th>
                            <th>HTML Status</th>
                            
                            <!-- TAT Info -->
                            <th>TAT Days</th>
                            <th>TAT Status</th>
                            <th>Missed TAT</th>
                            
                            <!-- Task Assignments -->
                            <th>Image</th>
                            <th>Table</th>
                            <th>WP Content</th>
                            <th>HTML</th>
                            <th>Scielo</th>
                            <th>QC</th>
                            
                            <!-- Progress -->
                            <th>Progress</th>
                            <th>My Tasks</th>
                            
                            <!-- Jobs Table Info -->
                            <th>Jobs XML</th>
                            <th>Job Info</th>
                            
                            <!-- Other Info -->
                            <th>Instructions</th>
                            <th>Remarks</th>
                            <th>Final Remarks</th>
                            <th>Billed</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal for Status & Bill Update -->
    <div class="modal fade" id="statusBillModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Status & Billing</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="statusBillForm">
                        <input type="hidden" id="sbRecordId">
                        <input type="hidden" id="sbJobId">
                        
                        <div class="status-bill-group">
                            <h6>Job Information</h6>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Job ID</label>
                                    <input type="text" id="sbJobIdDisplay" class="form-control" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Articles</label>
                                    <input type="text" id="sbArticles" class="form-control" readonly>
                                </div>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> When status is set to "Completed", the username will be automatically updated in the jobs table (assigned_xml field).
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="sbStatus" class="form-label">Status *</label>
                                    <select id="sbStatus" class="form-select" required>
                                        <option value="Need to Assign">Need to Assign</option>
                                        <option value="In Progress">In Progress</option>
                                        <option value="Completed">Completed</option>
                                        <option value="On Hold">On Hold</option>
                                        <option value="Cancelled">Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="sbBilled" class="form-label">Billed *</label>
                                    <select id="sbBilled" class="form-select" required>
                                        <option value="">Select Billing Status</option>
                                        <option value="Yes">Yes</option>
                                        <option value="No">No</option>
                                        <option value="Partial">Partial</option>
                                        <option value="Pending">Pending</option>
                                        <option value="Disputed">Disputed</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="sbRemarks" class="form-label">Remarks</label>
                                <textarea id="sbRemarks" class="form-control" rows="3" placeholder="Enter any remarks about this job"></textarea>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="sbFinalRemarks" class="form-label">Final Remarks</label>
                                <textarea id="sbFinalRemarks" class="form-control" rows="3" placeholder="Enter final remarks for completed jobs"></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveStatusBillChanges()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Assign Task with Sequence Number -->
    <div class="modal fade" id="assignModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Assign Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="assignForm">
                        <input type="hidden" id="assignRecordId">
                        <input type="hidden" id="assignField">
                        <input type="hidden" id="assignFieldName">
                        
                        <div class="mb-3">
                            <label class="form-label">Task: <strong id="modalTaskName"></strong></label>
                            <div class="alert alert-info">
                                Format: <code>username/date-month/number</code><br>
                                Example: <code>john/06-02/50</code>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="assignSequence" class="form-label">Sequence Number</label>
                            <input type="number" id="assignSequence" class="form-control" min="1" value="1" required>
                            <div class="form-text">Enter the sequence number for this task</div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="skipConfirmation">
                                <label class="form-check-label" for="skipConfirmation">
                                    Don't ask for confirmation again
                                </label>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="confirmAssignTask()">Assign</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Update Sequence -->
    <div class="modal fade" id="updateSequenceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Sequence Number</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="updateSequenceForm">
                        <input type="hidden" id="seqRecordId">
                        <input type="hidden" id="seqField">
                        
                        <div class="mb-3">
                            <label class="form-label">Current Value: <code id="currentValue"></code></label>
                        </div>
                        
                        <div class="mb-3">
                            <label for="newSequence" class="form-label">New Sequence Number</label>
                            <input type="number" id="newSequence" class="form-control" min="1" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updateSequenceNumber()">Update</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Remarks/Instructions -->
    <div class="modal fade" id="remarksModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="remarksForm">
                        <input type="hidden" id="modalRecordId">
                        <input type="hidden" id="modalFieldName">
                        
                        <div class="mb-3">
                            <label id="modalFieldLabel" class="form-label"></label>
                            <textarea id="modalFieldValue" class="form-control" rows="4"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveModalChanges()">Save</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Status Update Only -->
    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="statusForm">
                        <input type="hidden" id="statusRecordId">
                        <input type="hidden" id="statusFieldName">
                        
                        <div class="mb-3">
                            <label class="form-label">Select Status</label>
                            <select id="statusValue" class="form-select">
                                <option value="Need to Assign">Need to Assign</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="On Hold">On Hold</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveStatusChanges()">Save</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script>
    var table;
    var skipConfirmation = localStorage.getItem('skipAssignConfirmation') === 'true';
    
    // Notification functions
    function showNotification(message, type = 'success') {
        var alertClass = 'alert-' + type;
        var icon = type === 'success' ? '✓' : '✗';
        
        var toastHtml = `
            <div class="toast show" role="alert">
                <div class="toast-header">
                    <strong class="me-auto">${icon} Notification</strong>
                    <button type="button" class="btn-close" onclick="$(this).closest('.toast').remove()"></button>
                </div>
                <div class="toast-body">
                    ${message}
                </div>
            </div>
        `;
        
        $('#notificationContainer').append(toastHtml);
        
        // Auto remove after 3 seconds
        setTimeout(function() {
            $('.toast').first().fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    }
    
    function filterByJobId() {
        var jobId = $('#jobIdFilter').val();
        var showCompleted = $('#showCompleted').is(':checked') ? '1' : '0';
        
        if(jobId && jobId > 0) {
            window.location.href = 'xml_jobs.php?job_id=' + jobId + '&show_completed=' + showCompleted;
        } else {
            window.location.href = 'xml_jobs.php?show_completed=' + showCompleted;
        }
    }
    
    function toggleShowCompleted() {
        var showCompleted = $('#showCompleted').is(':checked') ? '1' : '0';
        var jobId = $('#jobIdFilter').val();
        
        var url = 'xml_jobs.php?show_completed=' + showCompleted;
        if (jobId && jobId > 0) {
            url += '&job_id=' + jobId;
        }
        
        window.location.href = url;
    }
    
    function filterByStatus(status) {
        if (status === 'assigned_to_me') {
            table.column(20).search('\\S', true, false).draw(); // Search for non-empty in My Tasks column
        } else if (status) {
            table.column(27).search(status).draw(); // Status column (changed from 25 to 27)
        } else {
            table.columns().search('').draw();
        }
    }

    function openStatusBillModal(recordId, jobId, articles, currentStatus, currentBilled, currentRemarks, currentFinalRemarks) {
        $('#sbRecordId').val(recordId);
        $('#sbJobId').val(jobId);
        $('#sbJobIdDisplay').val(jobId);
        $('#sbArticles').val(articles || '');
        $('#sbStatus').val(currentStatus || 'Need to Assign');
        $('#sbBilled').val(currentBilled || '');
        $('#sbRemarks').val(currentRemarks || '');
        $('#sbFinalRemarks').val(currentFinalRemarks || '');
        
        var modal = new bootstrap.Modal(document.getElementById('statusBillModal'));
        modal.show();
    }
    
    function saveStatusBillChanges() {
        var recordId = $('#sbRecordId').val();
        var status = $('#sbStatus').val();
        var billed = $('#sbBilled').val();
        var remarks = $('#sbRemarks').val();
        var finalRemarks = $('#sbFinalRemarks').val();
        
        if (!billed) {
            showNotification('Please select a billing status.', 'danger');
            return;
        }
        
        $.ajax({
            url: '',
            method: 'POST',
            data: {
                action: 'update_status_bill',
                id: recordId,
                status: status,
                billed: billed,
                remarks: remarks,
                final_remarks: finalRemarks
            },
            dataType: 'json',
            success: function(response) {
                if(response.success) {
                    if (status === 'Completed') {
                        showNotification('✓ ' + response.message + ' Job assigned to you in main jobs table.', 'success');
                    } else {
                        showNotification('✓ ' + response.message, 'success');
                    }
                    $('#statusBillModal').modal('hide');
                    table.ajax.reload(null, false);
                } else {
                    showNotification('Error: ' + response.error, 'danger');
                }
            },
            error: function() {
                showNotification('Server communication failed.', 'danger');
            }
        });
    }

    function openAssignModal(recordId, field, fieldName) {
        $('#assignRecordId').val(recordId);
        $('#assignField').val(field);
        $('#assignFieldName').val(fieldName);
        $('#modalTaskName').text(fieldName);
        $('#assignSequence').val(1);
        $('#skipConfirmation').prop('checked', skipConfirmation);
        
        var modal = new bootstrap.Modal(document.getElementById('assignModal'));
        modal.show();
    }
    
    function confirmAssignTask() {
        var recordId = $('#assignRecordId').val();
        var field = $('#assignField').val();
        var fieldName = $('#assignFieldName').val();
        var sequence = $('#assignSequence').val();
        var skipConfirm = $('#skipConfirmation').is(':checked');
        
        // Save preference
        localStorage.setItem('skipAssignConfirmation', skipConfirm);
        skipConfirmation = skipConfirm;
        
        $('#assignModal').modal('hide');
        
        // Direct assignment without confirmation if skip is checked
        if (skipConfirm) {
            assignTaskDirect(recordId, field, fieldName, sequence);
        } else {
            // Show confirmation
            if (confirm('Assign ' + fieldName + ' task to you with sequence number ' + sequence + '?')) {
                assignTaskDirect(recordId, field, fieldName, sequence);
            }
        }
    }
    
    function assignTaskDirect(recordId, field, fieldName, sequence) {
        $.ajax({
            url: '',
            method: 'POST',
            data: {
                action: 'assign_to_me',
                id: recordId,
                field: field,
                seq_number: sequence
            },
            dataType: 'json',
            success: function(response) {
                if(response.success) {
                    showNotification(response.message || 'Task assigned successfully!');
                    table.ajax.reload(null, false);
                } else {
                    showNotification('Error: ' + response.error, 'danger');
                }
            },
            error: function() {
                showNotification('Server communication failed.', 'danger');
            }
        });
    }
    
    function openUpdateSequenceModal(recordId, field, currentValue) {
        $('#seqRecordId').val(recordId);
        $('#seqField').val(field);
        $('#currentValue').text(currentValue);
        
        // Extract current sequence number
        var parts = currentValue.split('/');
        var currentSeq = parts.length === 3 ? parts[2].replace(' ✓', '') : '1';
        $('#newSequence').val(currentSeq);
        
        var modal = new bootstrap.Modal(document.getElementById('updateSequenceModal'));
        modal.show();
    }
    
    function updateSequenceNumber() {
        var recordId = $('#seqRecordId').val();
        var field = $('#seqField').val();
        var newSequence = $('#newSequence').val();
        
        if (!newSequence || newSequence < 1) {
            showNotification('Please enter a valid sequence number (>= 1)', 'danger');
            return;
        }
        
        $.ajax({
            url: '',
            method: 'POST',
            data: {
                action: 'update_sequence',
                id: recordId,
                field: field,
                seq_number: newSequence
            },
            dataType: 'json',
            success: function(response) {
                if(response.success) {
                    showNotification(response.message || 'Sequence number updated!');
                    $('#updateSequenceModal').modal('hide');
                    table.ajax.reload(null, false);
                } else {
                    showNotification('Error: ' + response.error, 'danger');
                }
            },
            error: function() {
                showNotification('Server communication failed.', 'danger');
            }
        });
    }

    function markComplete(recordId, field, fieldName) {
        $.ajax({
            url: '',
            method: 'POST',
            data: {
                action: 'complete',
                id: recordId,
                field: field
            },
            dataType: 'json',
            success: function(response) {
                if(response.success) {
                    if(response.all_completed) {
                        showNotification('🎉 ' + response.message + ' Job assigned to you in main jobs table.', 'success');
                    } else {
                        showNotification('✓ ' + response.message, 'success');
                    }
                    table.ajax.reload(null, false);
                } else {
                    showNotification('Error: ' + response.error, 'danger');
                }
            },
            error: function() {
                showNotification('Server communication failed.', 'danger');
            }
        });
    }

    function openRemarksModal(recordId, field, currentValue, fieldLabel) {
        $('#modalRecordId').val(recordId);
        $('#modalFieldName').val(field);
        $('#modalFieldLabel').text(fieldLabel);
        $('#modalFieldValue').val(currentValue);
        
        var modal = new bootstrap.Modal(document.getElementById('remarksModal'));
        modal.show();
    }
    
    function openStatusModal(recordId, currentValue) {
        $('#statusRecordId').val(recordId);
        $('#statusValue').val(currentValue);
        
        var modal = new bootstrap.Modal(document.getElementById('statusModal'));
        modal.show();
    }

    function saveModalChanges() {
        var recordId = $('#modalRecordId').val();
        var field = $('#modalFieldName').val();
        var value = $('#modalFieldValue').val();
        
        $.ajax({
            url: '',
            method: 'POST',
            data: {
                action: 'update',
                id: recordId,
                field: field,
                value: value
            },
            dataType: 'json',
            success: function(response) {
                if(response.success) {
                    showNotification('✓ Updated successfully');
                    $('#remarksModal').modal('hide');
                    table.ajax.reload(null, false);
                } else {
                    showNotification('Error: ' + response.error, 'danger');
                }
            },
            error: function() {
                showNotification('Server communication failed.', 'danger');
            }
        });
    }
    
    function saveStatusChanges() {
        var recordId = $('#statusRecordId').val();
        var value = $('#statusValue').val();
        
        $.ajax({
            url: '',
            method: 'POST',
            data: {
                action: 'update',
                id: recordId,
                field: 'status',
                value: value
            },
            dataType: 'json',
            success: function(response) {
                if(response.success) {
                    if (value === 'Completed') {
                        showNotification('✓ Status updated successfully. Job assigned to you in main jobs table.', 'success');
                    } else {
                        showNotification('✓ Status updated successfully', 'success');
                    }
                    $('#statusModal').modal('hide');
                    table.ajax.reload(null, false);
                } else {
                    showNotification('Error: ' + response.error, 'danger');
                }
            },
            error: function() {
                showNotification('Server communication failed.', 'danger');
            }
        });
    }

    $(document).ready(function(){
        var canEdit = <?php echo $canEditJson; ?>;
        var currentUser = '<?php echo $username; ?>';

        table = $('#xmlJobsTable').DataTable({
            ajax: '?fetch=data<?php echo $job_id > 0 ? '&job_id=' . $job_id : ''; echo $show_completed ? '&show_completed=1' : ''; ?>',
            dom: 'Bfrtip',
            buttons: [
                'copy', 'csv', 'excel', 'pdf', 'print'
            ],
            columns: [
                {data: 'id'},                     // 0 - ID
                {data: 'job_id'},                 // 1 - Job ID
                {data: 'rec_date'},               // 2 - Date
                {data: 'journal_name_client'},    // 3 - Journal/Client
                {data: 'issue'},                  // 4 - Issue
                {data: 'articles'},               // 5 - Articles
                
                // Status columns (6-9)
                {data: 'xml_status'},             // 6 - XML Status
                {data: 'scielo_ebsco_status'},    // 7 - Scielo/EBSCO
                {data: 'pubmed_biblat_status'},   // 8 - PubMed/Biblat
                {data: 'html_status'},            // 9 - HTML Status
                
                // TAT columns (10-12)
                {data: 'tat_to_client'},          // 10 - TAT Days
                {data: 'tat_status'},             // 11 - TAT Status
                {data: 'missed_tat'},             // 12 - Missed TAT
                
                // Task assignment columns (13-18)
                {data: 'Image_user'},             // 13 - Image
                {data: 'Table_user'},             // 14 - Table
                {data: 'WPContent_user'},         // 15 - WP Content
                {data: 'HTML_user'},              // 16 - HTML
                {data: 'Scielo_user'},            // 17 - Scielo
                {data: 'QC_user'},                // 18 - QC
                
                // Progress columns (19-20)
                {data: 'progress_percentage'},    // 19 - Progress
                {data: 'my_tasks'},               // 20 - My Tasks
                
                // Jobs Table Info columns (21-22)
                {data: 'jobs_xml_assignee'},      // 21 - Jobs XML Assignee
                {data: 'job_date'},               // 22 - Job Info
                
                // Other info columns (23-26)
                {data: 'instruction_to_image'},   // 23 - Instructions
                {data: 'remarks'},                // 24 - Remarks
                {data: 'final Remarks'},          // 25 - Final Remarks
                {data: 'Billed'},                 // 26 - Billed
                {data: 'status'},                 // 27 - Status
                
                // Actions column (28)
                {
                    data: null,
                    render: function(data, type, row) {
                        var actions = '';
                        
                        // Quick actions for tasks assigned to current user
                        if (row.my_tasks && Object.keys(row.my_tasks).length > 0) {
                            for (var field in row.my_tasks) {
                                var task = row.my_tasks[field];
                                if (!task.is_completed) {
                                    actions += '<button class="btn btn-success btn-sm me-1 mb-1" onclick="markComplete(' + row.id + ', \'' + field + '\', \'' + task.name + '\')" title="Mark ' + task.name + ' Complete">✓ ' + task.name.split(' ')[0] + '</button>';
                                }
                            }
                        }
                        
                        // Edit button for remarks/instructions
                        if (canEdit) {
                            // Main action button for status and billing
                            actions += '<button class="btn btn-primary btn-sm me-1 mb-1" onclick="openStatusBillModal(' + row.id + ', ' + row.job_id + ', \'' + (row.articles || '').replace(/'/g, "\\'") + '\', \'' + (row.status || '') + '\', \'' + (row.Billed || '') + '\', \'' + (row.remarks || '').replace(/'/g, "\\'") + '\', \'' + (row['final Remarks'] || '').replace(/'/g, "\\'") + '\')" title="Update Status & Billing">📊 Status/Bill</button>';
                            
                            actions += '<button class="btn btn-info btn-sm me-1 mb-1" onclick="openRemarksModal(' + row.id + ', \'remarks\', \'' + (row.remarks || '').replace(/'/g, "\\'") + '\', \'Update Remarks\')" title="Edit Remarks">📝</button>';
                            
                            // Separate status-only update button
                            actions += '<button class="btn btn-warning btn-sm mb-1" onclick="openStatusModal(' + row.id + ', \'' + (row.status || '') + '\')" title="Update Status Only">Status</button>';
                        }
                        
                        return actions || '<span class="text-muted">-</span>';
                    },
                    orderable: false,
                    searchable: false
                }
            ],
            columnDefs: [
                {
                    targets: [0, 1],
                    className: 'text-center'
                },
                {
                    targets: [2], // Date column
                    render: function(data) {
                        if (!data) return '-';
                        var date = new Date(data);
                        return date.toLocaleDateString();
                    }
                },
                {
                    targets: [6, 7, 8, 9], // Status columns
                    render: function(data) {
                        if (!data) return '<span class="badge bg-secondary">Pending</span>';
                        
                        var badgeClass = 'bg-secondary';
                        if (data === 'Completed') badgeClass = 'bg-success';
                        else if (data === 'In Progress') badgeClass = 'bg-warning';
                        else if (data === 'Need to Assign') badgeClass = 'bg-danger';
                        else if (data === 'On Hold') badgeClass = 'bg-info';
                        else if (data === 'Cancelled') badgeClass = 'bg-dark';
                        else if (!isNaN(data) && data > 0) {
                            // If it's a number, show progress
                            return '<span class="badge bg-info">' + data + '</span>';
                        }
                        
                        return '<span class="badge ' + badgeClass + '">' + data + '</span>';
                    }
                },
                {
                    targets: [11], // TAT Status column
                    render: function(data) {
                        if (!data) return '-';
                        
                        var badgeClass = 'bg-secondary';
                        if (data === 'Overdue') badgeClass = 'bg-danger';
                        else if (data === 'Due Today') badgeClass = 'bg-warning';
                        else if (data.includes('days left')) badgeClass = 'bg-success';
                        
                        return '<span class="badge ' + badgeClass + '">' + data + '</span>';
                    }
                },
                {
                    targets: [12], // Missed TAT
                    render: function(data) {
                        if (!data) return '-';
                        return '<span class="badge bg-danger">' + data + '</span>';
                    }
                },
                {
                    targets: [13, 14, 15, 16, 17, 18], // Task assignment columns
                    render: function(data, type, row, meta) {
                        var field = ['Image_user', 'Table_user', 'WPContent_user', 'HTML_user', 'Scielo_user', 'QC_user'][meta.col - 13];
                        var fieldNames = ['Image', 'Table', 'WP Content', 'HTML', 'Scielo', 'QC'];
                        var fieldName = fieldNames[meta.col - 13];
                        
                        if (data) {
                            // Check if completed
                            var isCompleted = data.includes('✓');
                            var displayValue = data.replace(' ✓', '');
                            
                            // Parse the value
                            var parts = displayValue.split('/');
                            var badgeClass = 'bg-info';
                            var actions = '';
                            
                            if (parts.length === 3) {
                                var assignedUser = parts[0];
                                var assignedDate = parts[1];
                                var sequence = parts[2];
                                
                                if (assignedUser === currentUser) {
                                    badgeClass = isCompleted ? 'bg-success' : 'bg-primary';
                                    
                                    if (!isCompleted) {
                                        actions = ' <button class="btn btn-success btn-sm assign-btn" onclick="markComplete(' + row.id + ', \'' + field + '\', \'' + fieldName + '\')" title="Mark Complete">✓</button>';
                                        actions += ' <button class="btn btn-warning btn-sm assign-btn" onclick="openUpdateSequenceModal(' + row.id + ', \'' + field + '\', \'' + data.replace(/'/g, "\\'") + '\')" title="Update Sequence">#</button>';
                                    }
                                }
                                
                                var displayText = assignedUser + '/' + assignedDate + '/' + sequence;
                                if (isCompleted) {
                                    displayText = '<span class="completed-task">' + displayText + ' ✓</span>';
                                }
                                
                                return '<span class="badge ' + badgeClass + ' task-badge">' + displayText + '</span>' + actions;
                            } else {
                                // Old format or invalid format
                                return '<span class="badge bg-secondary task-badge">' + data + '</span>';
                            }
                        } else {
                            // Not assigned yet
                            return '<button class="btn btn-outline-primary btn-sm assign-btn" onclick="openAssignModal(' + row.id + ', \'' + field + '\', \'' + fieldName + '\')">Assign</button>';
                        }
                    }
                },
                {
                    targets: [19], // Progress column
                    render: function(data, type, row) {
                        var progressColor = 'bg-success';
                        if (data < 50) progressColor = 'bg-danger';
                        else if (data < 80) progressColor = 'bg-warning';
                        
                        return '<div class="d-flex align-items-center">' +
                               '<span class="me-2">' + data + '%</span>' +
                               '<div class="progress w-100 progress-sm">' +
                               '<div class="progress-bar ' + progressColor + '" style="width: ' + data + '%"></div>' +
                               '</div></div>';
                    }
                },
                {
                    targets: [20], // My Tasks column
                    render: function(data) {
                        if (!data || Object.keys(data).length === 0) return '-';
                        
                        var badges = '';
                        for (var field in data) {
                            var task = data[field];
                            var badgeClass = task.is_completed ? 'bg-success' : 'bg-primary';
                            badges += '<span class="badge ' + badgeClass + ' task-badge me-1 mb-1">' + 
                                     task.name + ': ' + task.value + '</span>';
                        }
                        return badges;
                    }
                },
                {
                    targets: [21], // Jobs XML Assignee column
                    render: function(data, type, row) {
                        if (!data) {
                            return '<span class="badge bg-secondary">Not Assigned</span>';
                        }
                        
                        if (data === currentUser) {
                            return '<span class="badge bg-success jobs-assigned">' + data + ' (You)</span>';
                        } else {
                            return '<span class="badge bg-info jobs-assigned">' + data + '</span>';
                        }
                    }
                },
                {
                    targets: [22], // Job Info column
                    render: function(data, type, row) {
                        var info = '';
                        if (row.job_date) {
                            var date = new Date(row.job_date);
                            info += '<div class="jobs-info">Date: ' + date.toLocaleDateString() + '</div>';
                        }
                        if (row.jobs_word_count) {
                            info += '<div class="jobs-info">Words: ' + row.jobs_word_count + '</div>';
                        }
                        if (row.mail_subject) {
                            var shortSubject = row.mail_subject.length > 30 ? row.mail_subject.substring(0, 30) + '...' : row.mail_subject;
                            info += '<div class="jobs-info" title="' + row.mail_subject.replace(/"/g, '&quot;') + '">Subject: ' + shortSubject + '</div>';
                        }
                        return info || '-';
                    }
                },
                {
                    targets: [23, 24, 25], // Instructions, Remarks, Final Remarks
                    render: function(data) {
                        if (!data) return '-';
                        var shortText = data.length > 30 ? data.substring(0, 30) + '...' : data;
                        return '<span title="' + data.replace(/"/g, '&quot;') + '">' + shortText + '</span>';
                    }
                },
                {
                    targets: [26], // Billed column
                    render: function(data) {
                        if (!data) return '<span class="badge bg-danger">No</span>';
                        
                        var badgeClass = 'bg-secondary';
                        if (data === 'Yes') badgeClass = 'bg-success';
                        else if (data === 'No') badgeClass = 'bg-danger';
                        else if (data === 'Partial') badgeClass = 'bg-warning';
                        else if (data === 'Pending') badgeClass = 'bg-info';
                        else if (data === 'Disputed') badgeClass = 'bg-dark';
                        
                        return '<span class="badge ' + badgeClass + '">' + data + '</span>';
                    }
                },
                {
                    targets: [27], // Status column
                    render: function(data) {
                        if (!data) return '<span class="badge bg-secondary">Unknown</span>';
                        
                        var badgeClass = 'bg-secondary';
                        if (data === 'Completed') badgeClass = 'bg-success';
                        else if (data === 'In Progress') badgeClass = 'bg-warning';
                        else if (data === 'Need to Assign') badgeClass = 'bg-danger';
                        else if (data === 'On Hold') badgeClass = 'bg-info';
                        else if (data === 'Cancelled') badgeClass = 'bg-dark';
                        
                        return '<span class="badge ' + badgeClass + '">' + data + '</span>';
                    }
                }
            ],
            rowCallback: function(row, data) {
                // Highlight rows with tasks assigned to current user
                if (data.is_assigned_to_me) {
                    $(row).addClass('my-task-row');
                }
                
                // Highlight overdue rows
                if (data.tat_status === 'Overdue') {
                    $(row).addClass('overdue');
                } else if (data.tat_status === 'Due Today') {
                    $(row).addClass('due-today');
                }
                
                // Dim completed rows
                if (data.status === 'Completed') {
                    $(row).addClass('completed-row');
                }
                
                // Make remarks/instructions clickable for editing
                if (canEdit) {
                    $('td', row).eq(23).addClass('editable').attr('onclick', 
                        'openRemarksModal(' + data.id + ', \'instruction_to_image\', \'' + 
                        (data.instruction_to_image || '').replace(/'/g, "\\'") + '\', \'Instructions to Image\')');
                    
                    $('td', row).eq(24).addClass('editable').attr('onclick', 
                        'openRemarksModal(' + data.id + ', \'remarks\', \'' + 
                        (data.remarks || '').replace(/'/g, "\\'") + '\', \'Remarks\')');
                    
                    $('td', row).eq(25).addClass('editable').attr('onclick', 
                        'openRemarksModal(' + data.id + ', \'final Remarks\', \'' + 
                        (data['final Remarks'] || '').replace(/'/g, "\\'") + '\', \'Final Remarks\')');
                }
            },
            pageLength: 50,
            order: [[0, 'desc']],
            createdRow: function(row, data, dataIndex) {
                // Add data attributes for filtering
                $(row).attr('data-job-id', data.job_id);
                $(row).attr('data-status', data.status);
                $(row).attr('data-assigned-to-me', data.is_assigned_to_me ? 'true' : 'false');
            }
        });

        // Auto-refresh every 60 seconds
        setInterval(function() {
            table.ajax.reload(null, false);
        }, 60000);
    });
    </script>
</body>
</html>