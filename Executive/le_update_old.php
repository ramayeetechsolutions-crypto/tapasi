<?php
session_start();
// NOTE: Ensure this file correctly establishes the $mysqli connection
require 'db.php'; 

header('Content-Type: application/json');
$response = [
    'success' => false, 
    'error' => '', 
    'done_by' => null, 
    'le_date' => null, 
    'new_data' => null, 
    'status_updated' => false, 
    'tat' => null              
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    $response['error'] = 'Invalid request. Missing ID.';
    echo json_encode($response);
    exit;
}

// Function to correctly handle references for mysqli_stmt::bind_param
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
$update_le_date = (int)$_POST['update_le_date'] ?? 0; 
$set_check_flag = (int)$_POST['set_check_flag'] ?? 0; 

$employee_username = $_SESSION['username'] ?? 'Unknown User'; 
$current_datetime = date('Y-m-d H:i:s');
$current_date_only = date('Y-m-d'); 

// --- Determine which field to update ---
$valid_fields = ['eq', 'images', 'tables_count', 'word_count', 'ref', 'status'];
foreach ($valid_fields as $field) {
    if (isset($_POST[$field])) {
        $update_field = $field;
        $new_value = $_POST[$field]; 
        break;
    }
}

if (!$update_field) {
    $response['error'] = 'No valid field provided for update.';
    echo json_encode($response);
    exit;
}

// Map the data field name to the check flag field name
$check_field = null;
$is_check_field = in_array($update_field, ['eq', 'images', 'tables_count', 'ref']);
if ($is_check_field) {
    $check_field = $update_field . '_check';
    if ($update_field == 'tables_count') {
        $check_field = 'table_count_check'; 
    }
}

$status_update_needed = false;
$update_field_status = null;
$new_value_status = null;

// --- Word Count Logic: Auto-Complete ---
if ($update_field === 'word_count') {
    $status_update_needed = true;
    $response['status_updated'] = true;
    $update_field_status = 'status'; 
    $new_value_status = 'Completed';
    // Ensure date update is forced for TAT calculation
    $update_le_date = 1;
}

try {
    $mysqli->begin_transaction(); 
    
    $query_parts = [];
    $types = "";
    $params = [];
    $tat_value = null; 
    
    // --- Step 1: Handle Flag 1 Assignment (Flag 0 -> Flag 1) ---
    if ($is_check_field && $set_check_flag === 1) {
        
        $stmt_check = $mysqli->prepare("SELECT status FROM le WHERE id = ?"); 
        $stmt_check->bind_param("i", $record_id);
        $stmt_check->execute();
        $stmt_check->bind_result($current_status);
        $stmt_check->fetch();
        $stmt_check->close();

        if ($current_status === 'Need to Assign') {
            $status_update_needed = true;
            $response['status_updated'] = true; 
            $update_field_status = 'status';
            $new_value_status = 'In Progress';
        }
        
        // Set the cell value to the assignment string: e.g., 'viji / In Progress'
        $new_value = "{$employee_username} / In Progress"; 
        $response['new_data'] = $new_value; 

    // --- Step 2: Handle Flag 2 Completion (Flag 1 -> Flag 2 or Manual Red Cell Edit) ---
    } else if ($is_check_field && $set_check_flag === 2) {
        
        // Apply composite string format only if update_le_date is explicitly set (i.e., yellow cell completion)
        if ($update_le_date === 1) {
             $new_value_formatted = "{$employee_username}/{$current_date_only}/{$new_value}"; 
             $new_value = $new_value_formatted; 
             $response['new_data'] = $new_value_formatted;
        } else {
             $response['new_data'] = $new_value; 
        }

        // Ensure LE date is set
        $update_le_date = 1; 
    }

    // 3. Add the main updated field to the query 
    $query_parts[] = "{$update_field} = ?";
    $types .= "s";
    $params[] = $new_value; 

    // 4. Always update done_by
    $query_parts[] = "done_by = ?";
    $types .= "s";
    $params[] = $employee_username;
    $response['done_by'] = $employee_username; 

    // 5. Check Flag Update Logic
    if ($check_field && $set_check_flag > 0) {
        $query_parts[] = "{$check_field} = ?";
        $types .= "i";
        $params[] = $set_check_flag;
    }
    
    // 6. Status Update Logic (Handles Word Count override and Flag 1 status change)
    if ($status_update_needed && $update_field_status) {
        $query_parts[] = "status = ?";
        $types .= "s";
        $params[] = $new_value_status;
    }

    // 7. LE Date and TAT Update Logic
    if ($update_le_date === 1) { 
        // a. Set LE Date
        $query_parts[] = "le_date = ?"; 
        $types .= "s";
        $params[] = $current_datetime; 
        $response['le_date'] = $current_datetime; 

        // b. Calculate TAT
        $stmt_date = $mysqli->prepare("SELECT date FROM le WHERE id = ?"); 
        $stmt_date->bind_param("i", $record_id);
        $stmt_date->execute();
        $stmt_date->bind_result($start_date_str);
        $stmt_date->fetch();
        $stmt_date->close();
        
        if ($start_date_str) {
            $start_date = new DateTime($start_date_str);
            $end_date = new DateTime($current_datetime);
            $interval = $start_date->diff($end_date);
            
            // Convert interval to total seconds
            $total_seconds = $interval->days * 86400; 
            $total_seconds += $interval->h * 3600; 
            $total_seconds += $interval->i * 60;   
            $total_seconds += $interval->s;        

            // Calculate total days (1 day = 86400 seconds)
            $tat_days = $total_seconds / 86400; 
            
            // Format to two decimal places (e.g., 1.50)
            $tat_value = number_format($tat_days, 2); 
            
            $query_parts[] = "tat = ?"; 
            $types .= "s";
            $params[] = $tat_value; 
            $response['tat'] = $tat_value;
        }
    }
    
    // 8. Final Query Construction and Execution
    $query = "UPDATE le SET " . implode(', ', $query_parts) . " WHERE id = ?"; 
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