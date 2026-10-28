<?php
// pag_update.php
// Handles assignment (Flag 0->1) and completion (Flag 1->2 or 0->2 for Pages) and Status updates.
session_start();
require 'db.php'; 
header('Content-Type: application/json');

$response = [
    'success' => false, 
    'error' => '', 
    'done_by' => null, 
    'pag_date' => null, // Date returned to JS
    'new_data' => null, 
    'status_updated' => false
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    $response['error'] = 'Invalid request. Missing ID.';
    echo json_encode($response);
    exit;
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

$record_job_id = (int)$_POST['id']; 

// --- Field Detection ---
$field = null; $value = null; $status = null;
foreach ($_POST as $key => $val) {
    if (in_array($key, ['pages', 'pag_images', 'pag_tables_count', 'status'])) {
        $field = $key;
        $value = $val;
        if ($key === 'status') $status = $val;
        break;
    }
}

$set_check_flag = (int)$_POST['set_check_flag'] ?? 0; 
$explicit_completion_update = (int)$_POST['update_pag_date'] ?? 0; 

// IMPORTANT: Use the correct session variable for employee name
$employee_username = $_SESSION['username'] ?? 'Unknown User'; 
// Using the raw username for assigned_pag in jobs
$session_username = $_SESSION['username'] ?? 'Unknown User'; 

$current_datetime = date('Y-m-d H:i:s');
$current_date_only = date('d-m'); 

$is_core_pag_field = in_array($field, ['pages', 'pag_images', 'pag_tables_count']);
$is_status_update = ($status !== null);

if (!$field) {
    $response['error'] = 'No valid PAG field provided for update.';
    echo json_encode($response);
    exit;
}

$db_field_map = [
    'pages' => 'pages',
    'pag_images' => 'images',        
    'pag_tables_count' => 'tables_count',
    'status' => 'status'
];
$db_update_field = $db_field_map[$field] ?? $field; 

$check_field = $is_core_pag_field ? $db_update_field . '_check' : null;
$update_tracking_info = false;
$status_update_needed = false;
$new_value_for_db = $value; // Default to the posted value

try {
    $mysqli->begin_transaction(); 
    
    $query_parts = [];
    $types = "";
    $params = [];
    
    // --- Core PAG Field Updates (Assignment or Completion) ---
    if ($is_core_pag_field) {
        
        if ($set_check_flag === 1) { // Flag 1 Assignment (Red -> Yellow)
            
            // Check current status for 'In Progress' status update flag
            $stmt_check = $mysqli->prepare("SELECT status FROM pag WHERE job_id = ?");
            $stmt_check->bind_param("i", $record_job_id);
            $stmt_check->execute();
            $stmt_check->bind_result($current_status);
            $stmt_check->fetch();
            $stmt_check->close();

            if ($current_status === 'Need to Assign' || $current_status === null) {
                $status_update_needed = true;
                $response['status_updated'] = true; 
            }
            
            $response['new_data'] = "{$employee_username} / In Progress"; 
            $update_tracking_info = true;
            
            // --- NEW: Update assigned_pag in jobs table ---
            $stmt_jobs_update = $mysqli->prepare("UPDATE jobs SET assigned_pag = ? WHERE job_id = ?");
            if ($stmt_jobs_update) {
                $stmt_jobs_update->bind_param("si", $session_username, $record_job_id);
                if (!$stmt_jobs_update->execute()) {
                    throw new Exception("Failed to update jobs.assigned_pag: " . $stmt_jobs_update->error);
                }
                $stmt_jobs_update->close();
            }
            // --- END NEW LOGIC ---
            
        } else if ($set_check_flag === 2) { // Flag 2 Completion (Yellow -> Green or Manual Red)
            
            // --- CRITICAL VALUE FORMATTING ---
            if ($field === 'pages') {
                 // 1. Pages: Update only the numeric value (no formatting)
                 $new_value_for_db = (int)$value;
                 $response['new_data'] = $new_value_for_db; 
            } elseif ($explicit_completion_update === 1) {
                // 2. Others (Images/Tables): Use the full formatted string
                $new_value_for_db = "{$employee_username}/{$current_date_only}/{$value}"; 
                $response['new_data'] = $new_value_for_db; 
            } else {
                // Fallback (shouldn't happen with correct JS)
                $new_value_for_db = $value;
                $response['new_data'] = $new_value_for_db; 
            }
            // --- END CRITICAL VALUE FORMATTING ---
            
            $update_tracking_info = true;
        }

        // Add the main field ONLY IF it's completion (Flag 2) or a Red cell manual update for Pages
        if ($set_check_flag === 2 || ($set_check_flag === 0 && $field === 'pages')) { 
            $query_parts[] = "{$db_update_field} = ?";
            // Use 's' for binding as pages/images/tables_count are TEXT/VARCHAR
            $types .= "s"; 
            $params[] = $new_value_for_db; 
        }

        // Check Flag Update Logic
        if ($check_field && $set_check_flag > 0) {
            $query_parts[] = "{$check_field} = ?";
            $types .= "i";
            $params[] = $set_check_flag;
        }
    }
    
    // --- Dedicated Status Update ---
    if ($is_status_update) {
        $query_parts[] = "status = ?";
        $types .= "s";
        $params[] = $value; 
        $update_tracking_info = true;
    }

    // --- Tracking Info Update Logic (Done By & PAG Date/Time) ---
    if ($update_tracking_info) {
        $query_parts[] = "done_by = ?";
        $types .= "s";
        $params[] = $employee_username;
        $response['done_by'] = $employee_username; 

        // pag_date is updated here for assignment/in-progress tracking
        $query_parts[] = "pag_date = ?";
        $types .= "s";
        $params[] = $current_datetime; 
        $response['pag_date'] = date('Y-m-d'); // Send back date format used by DataTables
    }
    
    // --- Status Update Logic (from assignment) ---
    if ($status_update_needed) {
        $query_parts[] = "status = ?";
        $types .= "s";
        $params[] = 'In Progress';
    }

    // 7. Final Query Construction and Execution (for PAG table)
    if (empty($query_parts)) {
        throw new Exception("No fields to update in PAG table.");
    }
    
    $query = "UPDATE pag SET " . implode(', ', $query_parts) . " WHERE job_id = ?"; 
    $types .= "i";
    $params[] = $record_job_id;

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
    
    // 8. FINAL COMPLETION CHECK (ALL PAG checks are 2)
    if ($set_check_flag === 2) {
        $check_sql = "SELECT pages_check, images_check, tables_count_check FROM pag WHERE job_id = ?";
        $stmt_check_comp = $mysqli->prepare($check_sql);
        $stmt_check_comp->bind_param("i", $record_job_id);
        $stmt_check_comp->execute();
        $res = $stmt_check_comp->get_result();
        $row = $res->fetch_assoc();
        $stmt_check_comp->close();

        if ($row && 
            (int)$row['pages_check'] === 2 && 
            (int)$row['images_check'] === 2 && 
            (int)$row['tables_count_check'] === 2) {
            
            // --- 1. FETCH DUE DATE FROM JOBS TABLE ---
            $due_date_pag = null;
            $stmt_fetch_due_date = $mysqli->prepare("SELECT due_date_pag FROM jobs WHERE job_id = ?");
            if ($stmt_fetch_due_date) {
                $stmt_fetch_due_date->bind_param("i", $record_job_id);
                $stmt_fetch_due_date->execute();
                $stmt_fetch_due_date->bind_result($due_date_pag);
                $stmt_fetch_due_date->fetch();
                $stmt_fetch_due_date->close();
            }

            $pag_tat_days = 0;
            $current_completion_datetime_obj = new DateTime($current_datetime);

            // --- 2. CALCULATE TAT IN DAYS ---
            if ($due_date_pag) {
                try {
                    $due_date_obj = new DateTime($due_date_pag);
                    
                    // Calculate difference in seconds and convert to days (keeping sign)
                    $seconds_diff = $current_completion_datetime_obj->getTimestamp() - $due_date_obj->getTimestamp();
                    $pag_tat_days = round($seconds_diff / (60 * 60 * 24), 2); // Round to 2 decimal places

                } catch (Exception $e) {
                    error_log("TAT calculation failed for job_id $record_job_id: " . $e->getMessage());
                }
            } else {
                 error_log("due_date_pag not found in jobs table for job_id $record_job_id.");
            }

            // --- 3. UPDATE PAG TABLE WITH FINAL STATUS, DATE, AND TAT ---
            $update_sql = "UPDATE pag SET status = 'Completed', pag_date = ?, tat = ? WHERE job_id = ?";
            $stmt_update = $mysqli->prepare($update_sql);
            
            if ($stmt_update) {
                // $current_datetime is 'Y-m-d H:i:s'
                $stmt_update->bind_param("sdi", $current_datetime, $pag_tat_days, $record_job_id);
                
                if ($stmt_update->execute()) {
                     $response['status_updated'] = true;
                     // Update the response date to reflect the final update time (for DataTables reload)
                     $response['pag_date'] = $current_completion_datetime_obj->format('Y-m-d H:i:s'); 
                }
                $stmt_update->close();
            } else {
                 error_log("Failed to prepare final status update statement: " . $mysqli->error);
            }
        }
    }
    
    $mysqli->commit(); 
    $response['success'] = true;

} catch (Exception $e) {
    $mysqli->rollback(); 
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
?>