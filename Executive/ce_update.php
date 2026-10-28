<?php
session_start();
require 'db.php'; // Ensure this file correctly establishes the $mysqli connection

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
$set_check_flag = (int)$_POST['set_check_flag'] ?? 0; 
$explicit_completion_update = (int)$_POST['update_ce_date'] ?? 0;

$employee_username = $_SESSION['username'] ?? 'Unknown User'; 
$current_datetime = date('Y-m-d H:i:s');
$current_date_only = date('d-m'); // Date only for the formatted string

// --- Determine which field to update ---
$valid_fields = ['eq', 'images', 'tables_count', 'word_count', 'ref', 'status'];
$is_core_completion_field = false;
$is_status_update = false;
$is_word_count_update = false;

foreach ($valid_fields as $field) {
    if (isset($_POST[$field])) {
        $update_field = $field;
        $new_value = $_POST[$field]; 
        
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

// Map the data field name to the check flag field name
$check_field = null;
$is_check_field = $is_core_completion_field; 
if ($is_check_field) {
    // Determine the corresponding check field name
    $check_field = ($update_field === 'tables_count') ? 'tables_count_check' : $update_field . '_check';
}

// -------------------------------------------------------------------
// 🔥 MODIFIED LOGIC: done_by/ce_date update ONLY if word_count is updated 🔥
// -------------------------------------------------------------------
$update_tracking_info = $is_word_count_update;

// Status update always happens regardless of the tracking info update
$status_update_needed = false;

try {
    $mysqli->begin_transaction(); 
    
    $query_parts = [];
    $types = "";
    $params = [];
    
    // --- Step 1: Handle Flag 1 Assignment (Flag 0 -> Flag 1) ---
    if ($is_check_field && $set_check_flag === 1) {
        
        // Check current status to see if it needs changing to 'In Progress'
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
        
        // Set the cell value to the assignment string: e.g., 'viji / In Progress'
        $new_value = "{$employee_username} / In Progress"; 
        $response['new_data'] = $new_value; 

    // --- Step 2: Handle Flag 2 Completion (Flag 1 -> Flag 2 or Manual Red Cell Edit) ---
    } else if ($is_check_field && $set_check_flag === 2) {
        
        // **CUSTOM FORMATTING**: [employee_username]/[date]/[number]
        // For completion from a yellow cell (Flag 1 -> 2) or manual red cell (Flag 0 -> 2)
        if ($explicit_completion_update === 1) {
             // Use the current session user name and date for the formatted string (client display)
             $new_value_formatted = "{$employee_username}/{$current_date_only}/{$new_value}"; 
             $new_value = $new_value_formatted; 
             $response['new_data'] = $new_value_formatted;
        } else {
             // For red cell completion (Flag 0 -> 2) without explicit completion flag, keep value simple
             $response['new_data'] = $new_value; 
        }
    }

    // 3. Add the main updated field to the query
    $query_parts[] = "{$update_field} = ?";
    $types .= "s"; // Use 's' for safety across all fields
    $params[] = $new_value; 

    // 4. Update done_by and ce_date ONLY IF word_count was updated
    if ($update_tracking_info) {
        // Update done_by
        $query_parts[] = "done_by = ?";
        $types .= "s";
        $params[] = $employee_username;
        $response['done_by'] = $employee_username; 

        // Update CE Date Logic
        $query_parts[] = "ce_date = ?";
        $types .= "s";
        $params[] = $current_datetime; 
        $response['ce_date'] = $current_datetime;
    }
    
    // 5. Check Flag Update Logic (Flag 1 or Flag 2 update)
    if ($check_field && $set_check_flag > 0) {
        $query_parts[] = "{$check_field} = ?";
        $types .= "i";
        $params[] = $set_check_flag;
    }
    
    // 6. Status Update Logic (from Need to Assign to In Progress, or manual status change)
    if ($status_update_needed || $is_status_update) {
        
        // Status value is already in $new_value if $is_status_update is true, 
        // but we handle it separately here to ensure consistency with the assignment logic.
        $status_to_set = $is_status_update ? $new_value : 'In Progress';
        
        // If it's a manual status change, $update_field is 'status', so we skip adding it again
        if ($update_field !== 'status' || $status_update_needed) {
            $query_parts[] = "status = ?";
            $types .= "s";
            $params[] = $status_to_set;
            
            // If status was changed to 'In Progress' or 'Completed', you might want to force a tracking update here too.
            // Based on your strict instruction ("only word_count"), we will NOT add tracking here.
        }
    }

    // 7. Final Query Construction and Execution
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
        throw new Exception("Bind parameter failed. Types: {$types}, Params: " . print_r($params, true));
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