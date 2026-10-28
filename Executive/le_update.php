<?php
session_start();
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

$employee_username = $_SESSION['username'] ?? 'Unknown User'; 
$current_datetime = date('Y-m-d H:i:s');
$is_word_count_update = false;

// --- Determine which field to update ---
$valid_fields = ['word_count', 'status'];
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

// --- Query Construction Setup ---
$query_parts = [];
$types = "";
$params = [];

// 1. Handle Word Count update (DO NOT change status to Completed)
if ($update_field === 'word_count') {
    $is_word_count_update = true;
    
    // Set word count only - DO NOT change status
    $query_parts[] = "word_count = ?";
    $types .= "s";
    $params[] = $new_value;
    
    // Status remains as it is - NO status update
    $response['status_updated'] = false;
    $update_le_date = 1; // Still update date/by/TAT for word count entry
} 
/*// 2. Handle Status update
else if ($update_field === 'status') {
    // Allow setting to 'In Progress' but prevent 'Completed' via status field
    if ($new_value === 'Completed') {
        $response['error'] = 'Cannot set status to Completed via the status field. Please use the Manage button for final completion.';
        echo json_encode($response);
        exit;
    }
    
    $query_parts[] = "status = ?";
    $types .= "s";
    $params[] = $new_value;
    $response['status_updated'] = true;
    $update_le_date = 1;
}
*/
try {
    $mysqli->begin_transaction(); 
    
    // Always update last_updated
    $query_parts[] = "last_updated = NOW()";
    
    // Always update done_by
    $query_parts[] = "done_by = ?";
    $types .= "s";
    $params[] = $employee_username;
    $response['done_by'] = $employee_username; 

    // 3. LE Date and TAT Update Logic (Only if updating word count or status)
    if ($update_le_date === 1) { 
        
        // a. Set LE Date
        $query_parts[] = "le_date = ?"; 
        $types .= "s";
        $params[] = $current_datetime; 
        $response['le_date'] = date('Y-m-d H:i:s', strtotime($current_datetime)); 
        
        // b. Calculate and set TAT if this was a word count update
        if ($is_word_count_update) {
            
            // i. Fetch job_id from LE record
            $stmt_job_id = $mysqli->prepare("SELECT job_id FROM le WHERE id = ?"); 
            $stmt_job_id->bind_param("i", $record_id);
            $stmt_job_id->execute();
            $stmt_job_id->bind_result($job_id);
            $stmt_job_id->fetch();
            $stmt_job_id->close();
            
            $tat_value = null;
            $due_date_le_str = null;

            if ($job_id) {
                // ii. Fetch due_date_le from JOBS table using job_id
                $stmt_due_date = $mysqli->prepare("SELECT due_date_le FROM jobs WHERE job_id = ?"); 
                $stmt_due_date->bind_param("i", $job_id); 
                $stmt_due_date->execute();
                $stmt_due_date->bind_result($due_date_le_str);
                $stmt_due_date->fetch();
                $stmt_due_date->close();
            }

            if ($due_date_le_str) {
                // iii. Calculate TAT: Completion Date - Due Date LE
                $start_date = new DateTime($due_date_le_str);
                $end_date = new DateTime($current_datetime);
                $interval = $start_date->diff($end_date);
                
                $total_seconds = $interval->days * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
                $tat_days = $total_seconds / 86400;
                
                // Add the negative sign if the job was late (completion date > due date)
                $tat_value = $interval->format('%r') . number_format($tat_days, 2); 
                
                // ADD TAT TO THE QUERY
                $query_parts[] = "tat = ?"; 
                $types .= "s";
                $params[] = $tat_value; 
                $response['tat'] = $tat_value;
            } else {
                 // We will continue the execution to save WC, status, and date/by, but TAT will remain null.
                 $response['error'] = 'Word count updated but could not find due_date_le for TAT calculation.';
            }
        }
    }
    
    // 4. Final Query Construction and Execution
    $query = "UPDATE le SET " . implode(', ', $query_parts) . ", status='In Progress' WHERE id = ?"; 
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

    // 5. Update the jobs table if word count was entered (but status remains In Progress)
    if ($is_word_count_update) {
        // We already fetched job_id in step 3b-i
        if ($job_id) {
            // Update the jobs table to assign the user but keep status as In Progress
            $stmt_jobs = $mysqli->prepare("UPDATE jobs SET assigned_le = ? WHERE job_id = ?"); 
            if (!$stmt_jobs) {
                throw new Exception("Jobs Prepare failed: " . $mysqli->error);
            }
            $stmt_jobs->bind_param("si", $employee_username, $job_id); 
            if (!$stmt_jobs->execute()) {
                throw new Exception("Jobs Execute failed: " . $stmt_jobs->error);
            }
            $stmt_jobs->close();
        }
    }

    $mysqli->commit(); 
    
    $response['success'] = true;
    $response['new_data'] = $new_value;

} catch (Exception $e) {
    $mysqli->rollback(); 
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
?>