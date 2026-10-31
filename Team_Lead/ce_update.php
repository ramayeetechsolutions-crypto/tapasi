<?php
session_start();
require 'db.php'; 

header('Content-Type: application/json');
$response = [
    'success' => false, 
    'error' => '', 
    'done_by' => null, 
    'ce_date' => null, 
    'new_data' => null, 
    'status_updated' => false
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    $response['error'] = 'Invalid request. Missing ID.';
    echo json_encode($response);
    exit;
}

// Helper function for mysqli bind_param
function refValues($arr){
    if (version_compare(PHP_VERSION, '5.3.0', '>=')) {
        $refs = array();
        foreach($arr as $key => $value)
            $refs[$key] = &$arr[$key];
        return $refs;
    }
    return $arr;
}

$record_id = (int)$_POST['id'];
$update_field = null;
$new_value = null;
$set_check_flag = (int)($_POST['set_check_flag'] ?? 0); 
$update_tracking = (int)($_POST['update_tracking'] ?? 0);

// Get user info from session
$employee_username = $_SESSION['username'] ?? 'Unknown User'; 
$current_datetime = date('Y-m-d H:i:s');
$current_date_only = date('d-m');

// Determine which field to update
$valid_fields = ['eq', 'images', 'tables_count', 'word_count', 'ref', 'status'];
$is_core_completion_field = false;
$is_status_update = false;
$is_word_count_update = false;

foreach ($valid_fields as $field) {
    if (isset($_POST[$field])) {
        $update_field = $field;
        $new_value = trim($_POST[$field]); 
        
        if (in_array($update_field, ['eq', 'images', 'tables_count', 'ref'])) {
            $is_core_completion_field = true;
        }
        if ($update_field === 'status') {
            $is_status_update = true;
        }
        if ($update_field === 'word_count') {
            $is_word_count_update = true;
        }
        break;
    }
}

if (!$update_field) {
    $response['error'] = 'No valid field provided for update.';
    echo json_encode($response);
    exit;
}

// Map field to check field
$check_field = null;
if ($is_core_completion_field) {
    $check_field = ($update_field === 'tables_count') ? 'tables_count_check' : $update_field . '_check';
}

// Check if we need to update status to 'In Progress'
$status_update_needed = false;
if ($is_core_completion_field && $set_check_flag === 1) {
    $stmt_check = $mysqli->prepare("SELECT status FROM ce WHERE id = ?");
    $stmt_check->bind_param("i", $record_id);
    $stmt_check->execute();
    $stmt_check->bind_result($current_status);
    $stmt_check->fetch();
    $stmt_check->close();

    if ($current_status === 'Need to Assign') {
        $status_update_needed = true;
        $response['status_updated'] = true;
    }
}

try {
    $mysqli->begin_transaction(); 
    
    $query_parts = [];
    $types = "";
    $params = [];
    
    // Handle field value formatting
    $final_value = $new_value;
    
    // For core completion fields on final update (Flag 2)
    if ($is_core_completion_field && $set_check_flag === 2) {
        // Format: username/date/value
        $final_value = "{$employee_username}/{$current_date_only}/{$new_value}";
        $response['new_data'] = $final_value;
    }
    
    // For assignment (Flag 1)
    if ($is_core_completion_field && $set_check_flag === 1) {
        $final_value = "{$employee_username} / In Progress";
        $response['new_data'] = $final_value;
    }
    
    // Add main field update
    $query_parts[] = "{$update_field} = ?";
    $types .= "s";
    $params[] = $final_value;
    
    // Update tracking info (username and timestamp)
    if ($update_tracking) {
        $query_parts[] = "done_by = ?";
        $types .= "s";
        $params[] = $employee_username;
        $response['done_by'] = $employee_username;
        
        $query_parts[] = "ce_date = ?";
        $types .= "s";
        $params[] = $current_datetime;
        $response['ce_date'] = $current_datetime;
    }
    
    // Update check flag for core fields
    if ($check_field && $set_check_flag > 0) {
        $query_parts[] = "{$check_field} = ?";
        $types .= "i";
        $params[] = $set_check_flag;
    }
    
    // Update status if needed
    if ($status_update_needed) {
        $query_parts[] = "status = ?";
        $types .= "s";
        $params[] = 'In Progress';
    }
    
    // Manual status update
    if ($is_status_update) {
        $query_parts[] = "status = ?";
        $types .= "s";
        $params[] = $new_value;
    }
    
    // Build and execute query
    if (empty($query_parts)) {
        throw new Exception("No fields to update.");
    }
    
    $query = "UPDATE ce SET " . implode(', ', $query_parts) . " WHERE id = ?";
    $types .= "i";
    $params[] = $record_id;
    
    $stmt = $mysqli->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Preparation failed: " . $mysqli->error);
    }
    
    $bind_args = refValues(array_merge([$types], $params));
    
    if (!call_user_func_array([$stmt, 'bind_param'], $bind_args)) {
        throw new Exception("Bind parameter failed.");
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Execution failed: " . $stmt->error);
    }
    
    $stmt->close();
    $mysqli->commit(); 
    
    $response['success'] = true;
    
} catch (Exception $e) {
    $mysqli->rollback(); 
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
?>