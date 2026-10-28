<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Get employee info from session
$uid = $_SESSION['uid'] ?? 0;
$employee_id = $uid; // Store employee ID from session
// Get current user's reporting manager to filter TMs
$current_user_id = $_SESSION['uid'] ?? 0;
$employee_username = '';
if ($current_user_id) {
    $stmt = $mysqli->prepare("SELECT employee_username FROM employee_master WHERE employee_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $stmt->bind_result($employee_username);
        $stmt->fetch();
        $stmt->close();
    }
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

function redirect_with($params = []) {
    $base = 'wordcount_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Function to calculate week number from date
function getWeekNumber($date) {
    $dateObj = new DateTime($date);
    return 'W' . $dateObj->format('W');
}

// Function to get week number as integer
function getWeekNumberInt($date) {
    $dateObj = new DateTime($date);
    return (int)$dateObj->format('W');
}

// Function to check duplicate entry for same employee on same date
function checkDuplicateEntry($mysqli, $date, $employee_id, $exclude_id = 0) {
    $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM wordcount_master WHERE date = ? AND employee_id = ? AND count_id != ?");
    if (!$stmt) return false;
    
    $stmt->bind_param("ssi", $date, $employee_id, $exclude_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['count'] > 0;
}

// Function to update weekly target achieved count
function updateWeeklyTargetAchieved($mysqli, $employee_username, $date, $count_value) {
    // Get week number and year from date
    $dateObj = new DateTime($date);
    $week_number = getWeekNumberInt($date);
    $year = (int)$dateObj->format('Y');
    
    // Debug log
    error_log("Updating weekly target for: employee=$employee_username, date=$date, week=$week_number, year=$year, count=$count_value");
    
    // Check if there's a weekly target for this employee in this week/year
    $stmt = $mysqli->prepare("SELECT id, target_limit, achieved_target FROM weekly_targets 
                              WHERE tm_id = ? AND week_number = ? AND year = ? AND status = 'Active'");
    if (!$stmt) {
        error_log("Prepare failed: " . $mysqli->error);
        return false;
    }
    
    $stmt->bind_param("sii", $employee_username, $week_number, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $target_id = $row['id'];
        $current_achieved = (int)$row['achieved_target'];
        $target_limit = (int)$row['target_limit'];
        
        // Calculate new achieved count (add current word count)
        $new_achieved = $current_achieved + $count_value;
        
        // Update the weekly target
        $update_stmt = $mysqli->prepare("UPDATE weekly_targets 
                                        SET achieved_target = ?, 
                                            status = CASE 
                                                WHEN ? >= target_limit THEN 'Completed' 
                                                ELSE 'Active' 
                                            END
                                        WHERE id = ?");
        if (!$update_stmt) {
            error_log("Update prepare failed: " . $mysqli->error);
            $stmt->close();
            return false;
        }
        
        $update_stmt->bind_param("iii", $new_achieved, $new_achieved, $target_id);
        
        if ($update_stmt->execute()) {
            error_log("Weekly target updated successfully: ID=$target_id, New Achieved=$new_achieved, Target Limit=$target_limit");
            
            // Check if target is completed
            if ($new_achieved >= $target_limit) {
                error_log("Target completed for employee $employee_username in week $week_number");
            }
        } else {
            error_log("Update execute failed: " . $update_stmt->error);
        }
        
        $update_stmt->close();
    } else {
        error_log("No weekly target found for employee $employee_username in week $week_number, year $year");
        
        // Try to find target by date range if week_number/year not set
        $stmt2 = $mysqli->prepare("SELECT id, target_limit, achieved_target, from_date, to_date FROM weekly_targets 
                                   WHERE tm_id = ? AND ? BETWEEN from_date AND to_date AND status = 'Active'");
        if ($stmt2) {
            $stmt2->bind_param("ss", $employee_username, $date);
            $stmt2->execute();
            $result2 = $stmt2->get_result();
            
            if ($row2 = $result2->fetch_assoc()) {
                $target_id = $row2['id'];
                $current_achieved = (int)$row2['achieved_target'];
                $target_limit = (int)$row2['target_limit'];
                $new_achieved = $current_achieved + $count_value;
                
                // Get week number and year from from_date
                $from_date_obj = new DateTime($row2['from_date']);
                $week_number = getWeekNumberInt($row2['from_date']);
                $year = (int)$from_date_obj->format('Y');
                
                // Update weekly target with week_number and year
                $update_stmt2 = $mysqli->prepare("UPDATE weekly_targets 
                                                 SET achieved_target = ?, 
                                                     week_number = ?, 
                                                     year = ?,
                                                     status = CASE 
                                                         WHEN ? >= target_limit THEN 'Completed' 
                                                         ELSE 'Active' 
                                                     END
                                                 WHERE id = ?");
                if ($update_stmt2) {
                    $update_stmt2->bind_param("iiiii", $new_achieved, $week_number, $year, $new_achieved, $target_id);
                    
                    if ($update_stmt2->execute()) {
                        error_log("Weekly target updated (by date range): ID=$target_id, Week=$week_number, Year=$year, New Achieved=$new_achieved");
                    } else {
                        error_log("Update by date range failed: " . $update_stmt2->error);
                    }
                    $update_stmt2->close();
                }
            } else {
                error_log("No weekly target found by date range either");
            }
            $stmt2->close();
        }
    }
    
    $stmt->close();
    return true;
}

// Debug function
function debugLog($message, $data = null) {
    file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - ' . $message . ($data ? ': ' . print_r($data, true) : '') . PHP_EOL, FILE_APPEND);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    debugLog('POST request received', $_POST);
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $date = $_POST['date'] ?? '';
        $count_type = $_POST['count_type'] ?? 'word';
        $count_value = trim($_POST['count_value'] ?? '');
        $employee_id = ($_POST['employee_id'] ?? '');
        $count_id = (int)($_POST['count_id'] ?? 0);
        
        // Calculate week number from date
        $week_number = getWeekNumber($date);
        
        debugLog('Form data', [
            'date' => $date,
            'week_number' => $week_number,
            'count_type' => $count_type,
            'count_value' => $count_value,
            'employee_id' => $employee_id,
            'count_id' => $count_id,
            'action' => $action
        ]);
        
        // Basic validation
        $errors = [];
        if ($date === '') $errors[] = 'Date is required';
        if ($count_value === '' || !is_numeric($count_value) || $count_value <= 0) $errors[] = 'Valid count value is required';
        if ($employee_id == '') $errors[] = 'Employee ID is required';
        
        // Validate date format
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors[] = 'Invalid date format';
        }
        
        // Check for duplicate entry (only one entry per day per employee)
        if ($date !== '' && $employee_id !== '') {
            if ($action === 'add') {
                if (checkDuplicateEntry($mysqli, $date, $employee_id, 0)) {
                    $errors[] = "An entry already exists for employee '$employee_id' on date " . date('d-m-Y', strtotime($date));
                }
            } else if ($action === 'edit' && $count_id > 0) {
                if (checkDuplicateEntry($mysqli, $date, $employee_id, $count_id)) {
                    $errors[] = "Another entry already exists for employee '$employee_id' on date " . date('d-m-Y', strtotime($date));
                }
            }
        }
        
        if (!empty($errors)) {
            debugLog('Validation errors', $errors);
            redirect_with(['msg' => implode('<br>', $errors), 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Insert new word count entry
            $stmt = $mysqli->prepare("INSERT INTO wordcount_master (date, week_number, count_type, count_value, employee_id, created_date, last_updated) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
            
            if (!$stmt) {
                debugLog('Prepare failed for insert word count', $mysqli->error);
                redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
            }
            
            $stmt->bind_param("sssis", $date, $week_number, $count_type, $count_value, $employee_id);
            
            if ($stmt->execute()) {
                $insert_id = $stmt->insert_id;
                debugLog('Word count added successfully', ['id' => $insert_id, 'count_value' => $count_value]);
                
                // ✅ NEW: Update weekly target achieved count
                if (updateWeeklyTargetAchieved($mysqli, $employee_id, $date, (int)$count_value)) {
                    debugLog('Weekly target updated successfully');
                } else {
                    debugLog('Weekly target update failed');
                }
                
                redirect_with(['msg' => "Word count entry added successfully", 'type' => 'success']);
            } else {
                debugLog('Execute failed for insert word count', $stmt->error);
                redirect_with(['msg' => 'Error adding word count: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            if ($count_id > 0) {
                // First get the old count value to calculate difference
                $old_stmt = $mysqli->prepare("SELECT count_value, date, employee_id FROM wordcount_master WHERE count_id = ?");
                $old_count_value = 0;
                $old_date = '';
                $old_employee = '';
                
                if ($old_stmt) {
                    $old_stmt->bind_param("i", $count_id);
                    $old_stmt->execute();
                    $old_stmt->bind_result($old_count_value, $old_date, $old_employee);
                    $old_stmt->fetch();
                    $old_stmt->close();
                }
                
                // Update word count
                $stmt = $mysqli->prepare("UPDATE wordcount_master SET date = ?, week_number = ?, count_type = ?, count_value = ?, employee_id = ?, last_updated = NOW() WHERE count_id = ?");
                if (!$stmt) {
                    redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
                }
                
                $stmt->bind_param("sssisi", $date, $week_number, $count_type, $count_value, $employee_id, $count_id);
                
                if ($stmt->execute()) {
                    // ✅ NEW: Update weekly target achieved count for edit
                    // First, subtract old count if date/employee is the same
                    if ($old_date === $date && $old_employee === $employee_id) {
                        // Same date and employee - calculate net difference
                        $difference = (int)$count_value - (int)$old_count_value;
                        if ($difference != 0) {
                            if (updateWeeklyTargetAchieved($mysqli, $employee_id, $date, $difference)) {
                                debugLog('Weekly target updated for edit (same date/employee)');
                            }
                        }
                    } else {
                        // Different date or employee - handle both
                        // Subtract old count from old date/employee
                        if ($old_employee && $old_date) {
                            if (updateWeeklyTargetAchieved($mysqli, $old_employee, $old_date, -(int)$old_count_value)) {
                                debugLog('Old weekly target adjusted for edit');
                            }
                        }
                        
                        // Add new count to new date/employee
                        if (updateWeeklyTargetAchieved($mysqli, $employee_id, $date, (int)$count_value)) {
                            debugLog('New weekly target updated for edit');
                        }
                    }
                    
                    redirect_with(['msg' => "Word count updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating word count: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    // Delete word count entry
    if ($action === 'delete') {
        $count_id = (int)($_POST['count_id'] ?? 0);
        
        if ($count_id > 0) {
            // First get the count value to subtract from weekly target
            $get_stmt = $mysqli->prepare("SELECT count_value, date, employee_id FROM wordcount_master WHERE count_id = ?");
            $count_value = 0;
            $date = '';
            $employee_id = '';
            
            if ($get_stmt) {
                $get_stmt->bind_param("i", $count_id);
                $get_stmt->execute();
                $get_stmt->bind_result($count_value, $date, $employee_id);
                $get_stmt->fetch();
                $get_stmt->close();
                
                // Subtract from weekly target
                if ($employee_id && $date) {
                    if (updateWeeklyTargetAchieved($mysqli, $employee_id, $date, -(int)$count_value)) {
                        debugLog('Weekly target adjusted for deletion');
                    }
                }
            }
            
            // Now delete the entry
            $stmt = $mysqli->prepare("DELETE FROM wordcount_master WHERE count_id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $count_id);
                if ($stmt->execute()) {
                    redirect_with(['msg' => 'Word count entry deleted successfully', 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error deleting word count: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editWordCount = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT w.*, e.employee_name FROM wordcount_master w LEFT JOIN employee_master e ON w.employee_id = e.employee_username WHERE w.count_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $editWordCount = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
    if (!$editWordCount) $mode = 'view';
}

// Get search and filter parameters
$date_filter = $_GET['date_filter'] ?? '';
$week_filter = trim($_GET['week_filter'] ?? '');
$employee_filter = $_GET['employee_filter'] ?? '';
$count_type_filter = $_GET['count_type_filter'] ?? '';
$sort = $_GET['sort'] ?? 'count_id';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query
$sql = "SELECT w.*, e.employee_name FROM wordcount_master w LEFT JOIN employee_master e ON w.employee_id = e.employee_username";
$cond = [];
$params = [];
$types = '';

if ($date_filter !== '') {
    $cond[] = "w.date = ?";
    $params[] = $date_filter;
    $types .= 's';
}

if ($week_filter !== '') {
    $cond[] = "w.week_number LIKE ?";
    $like = "%$week_filter%";
    $params[] = $like;
    $types .= 's';
}

if ($employee_filter !== '') {
    $cond[] = "w.employee_id = ?";
    $params[] = $employee_filter;
    $types .= 's';
}

if ($count_type_filter !== '' && in_array($count_type_filter, ['word', 'page'])) {
    $cond[] = "w.count_type = ?";
    $params[] = $count_type_filter;
    $types .= 's';
}

// For regular users, only show their own entries unless they have admin rights
if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    $cond[] = "w.employee_id = ?";
    $params[] = $employee_username;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " WHERE " . implode(" AND ", $cond);
}

$sql .= " ORDER BY w.$sort $dir";
//echo $sql;

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $wordcounts = $stmt->get_result();
    } else {
        $wordcounts = false;
    }
} else {
    $wordcounts = $mysqli->query($sql);
}

// Get employee list for filter (only for admins)
$employee_list = [];
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) {
    $emp_result = $mysqli->query("SELECT employee_username, employee_name FROM employee_master ORDER BY employee_name");
    while ($emp = $emp_result->fetch_assoc()) {
        $employee_list[] = $emp;
    }
}

// Get today's entry status for current user
$today_has_entry = false;
$today_date = date('Y-m-d');
if ($employee_username) {
    $check_stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM wordcount_master WHERE date = ? AND employee_id = ?");
    if ($check_stmt) {
        $check_stmt->bind_param("ss", $today_date, $employee_username);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_row = $check_result->fetch_assoc();
        $today_has_entry = $check_row['count'] > 0;
        $check_stmt->close();
    }
}

// If query fails, show empty result
if (!$wordcounts) {
    $wordcounts = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Word Count Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .table th {
            background-color: #2c3e50;
            color: white;
            border: none;
        }
        .table td {
            vertical-align: middle;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .required-field::after {
            content: " *";
            color: #dc3545;
        }
        .date-badge {
            font-size: 0.9em;
            padding: 4px 10px;
        }
        .count-id {
            font-family: monospace;
            background-color: #e9ecef;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
        }
        .week-badge {
            background-color: #6f42c1;
            color: white;
        }
        .employee-badge {
            background-color: #20c997;
            color: white;
        }
        .count-value {
            font-weight: bold;
            font-size: 1.1em;
        }
        .count-type-badge {
            font-size: 0.8em;
            padding: 2px 8px;
        }
        .today-badge {
            background-color: #ffc107;
            color: #212529;
        }
        .duplicate-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .target-info {
            background-color: #e7f3ff;
            border: 1px solid #b6d4fe;
            color: #084298;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .target-progress {
            height: 20px;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
        
        <!-- Add/Edit Form -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-calculator me-2"></i><?= $mode === 'edit' ? 'Edit Word Count Entry' : 'Add New Word Count Entry' ?></h4>
            <a href="wordcount_master.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <!-- Show weekly target info if available -->
        <?php 
        if ($mode === 'add' && $employee_username && isset($employee_username)) {
            // Get current week target
            $week_number = getWeekNumberInt(date('Y-m-d'));
            $year = date('Y');
            
            $target_stmt = $mysqli->prepare("SELECT target_limit, achieved_target, status FROM weekly_targets 
                                           WHERE tm_id = ? AND week_number = ? AND year = ? AND status = 'Active'");
            if ($target_stmt) {
                $target_stmt->bind_param("sii", $employee_username, $week_number, $year);
                $target_stmt->execute();
                $target_result = $target_stmt->get_result();
                
                if ($target_row = $target_result->fetch_assoc()) {
                    $target_limit = (int)$target_row['target_limit'];
                    $achieved = (int)$target_row['achieved_target'];
                    $remaining = max(0, $target_limit - $achieved);
                    $percentage = $target_limit > 0 ? round(($achieved / $target_limit) * 100, 2) : 0;
                    ?>
                    <div class="target-info">
                        <h6><i class="fas fa-bullseye me-2"></i>Weekly Target Progress</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <strong>Target:</strong> <?= number_format($target_limit) ?> words<br>
                                <strong>Achieved:</strong> <?= number_format($achieved) ?> words
                            </div>
                            <div class="col-md-4">
                                <strong>Remaining:</strong> <?= number_format($remaining) ?> words<br>
                                <strong>Progress:</strong> <?= $percentage ?>%
                            </div>
                            <div class="col-md-4">
                                <strong>Week:</strong> W<?= $week_number ?><br>
                                <strong>Status:</strong> <span class="badge bg-<?= $target_row['status'] === 'Completed' ? 'success' : 'primary' ?>"><?= $target_row['status'] ?></span>
                            </div>
                        </div>
                        <div class="progress target-progress">
                            <div class="progress-bar <?= $percentage >= 100 ? 'bg-success' : ($percentage >= 70 ? 'bg-warning' : 'bg-danger') ?>" 
                                 role="progressbar" style="width: <?= min($percentage, 100) ?>%">
                                <?= $percentage ?>%
                            </div>
                        </div>
                        <small class="text-muted mt-2"><i class="fas fa-info-circle me-1"></i> Today's word count will be added to your weekly target.</small>
                    </div>
                    <?php
                }
                $target_stmt->close();
            }
        }
        ?>

        <!-- Check for existing entry if adding for today -->
        <?php if($mode === 'add' && isset($employee_username) && $employee_username !== ''): 
            $check_today_stmt = $mysqli->prepare("SELECT date FROM wordcount_master WHERE date = ? AND employee_id = ?");
            if ($check_today_stmt) {
                $check_date = date('Y-m-d');
                $check_today_stmt->bind_param("ss", $check_date, $employee_username);
                $check_today_stmt->execute();
                $check_today_result = $check_today_stmt->get_result();
                if ($existing_entry = $check_today_result->fetch_assoc()): ?>
                    <div class="duplicate-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> You already have an entry for today (<?= date('d-m-Y', strtotime($existing_entry['date'])) ?>). 
                        You can only add one entry per day. Please edit your existing entry instead.
                    </div>
                <?php endif;
                $check_today_stmt->close();
            }
        endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off" id="wordCountForm" onsubmit="return validateForm()">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="count_id" value="<?= (int)($editWordCount['count_id'] ?? 0) ?>">
                    <?php endif; ?>
                    
                    <input type="hidden" name="employee_id" value="<?= $employee_username ?>">

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="date" class="form-label required-field">Date</label>
                                <input type="date" class="form-control" id="date" name="date" 
                                       value="<?= h($editWordCount['date'] ?? date('Y-m-d')) ?>" 
                                       required onchange="updateWeekNumber(); checkDuplicate();">
                                <div class="form-text">Select the date for this entry</div>
                                <div class="invalid-feedback" id="date_error">Date is required</div>
                                <div class="form-text text-danger" id="duplicate_warning" style="display: none;">
                                    <i class="fas fa-exclamation-circle me-1"></i> An entry already exists for this date
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="week_number" class="form-label">Week Number (Auto-generated)</label>
                                <input type="text" class="form-control" id="week_number" readonly
                                       value="<?= h($editWordCount['week_number'] ?? getWeekNumber(date('Y-m-d'))) ?>">
                                <div class="form-text">Week number is automatically calculated from date</div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="employee_display" class="form-label">Employee</label>
                                <input type="text" class="form-control" id="employee_display" readonly
                                       value="Employee ID: <?= $employee_username ?>">
                                <div class="form-text">Your employee ID (from session)</div>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="count_type" class="form-label required-field">Count Type</label>
                                <select class="form-select" id="count_type" name="count_type" required>
                                    <option value="word" <?= ($editWordCount['count_type'] ?? 'word') === 'word' ? 'selected' : '' ?>>Word Count</option>
                                    <option value="page" <?= ($editWordCount['count_type'] ?? 'word') === 'page' ? 'selected' : '' ?>>Page Count</option>
                                </select>
                                <div class="form-text">Select whether you're counting words or pages</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="count_value" class="form-label required-field">Count Value</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="count_value" name="count_value" 
                                           value="<?= h($editWordCount['count_value'] ?? '') ?>" 
                                           placeholder="Enter number" min="1" step="1" required>
                                    <span class="input-group-text" id="count_unit">words</span>
                                </div>
                                <div class="form-text" id="count_description">Number of words</div>
                                <div class="invalid-feedback" id="count_value_error">Valid count value is required</div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Entry' : 'Save Entry' ?>
                        </button>
                        <a href="wordcount_master.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-calculator me-2"></i>Word Count Master</h4>
            <div>
                <?php if($today_has_entry): ?>
                <span class="badge today-badge me-3">
                    <i class="fas fa-check-circle me-1"></i> Today's entry submitted
                </span>
                <?php else: ?>
                <span class="badge bg-secondary me-3">
                    <i class="fas fa-clock me-1"></i> Today's entry pending
                </span>
                <?php endif; ?>
                <a href="wordcount_master.php?mode=add" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus me-1"></i> Add New Entry
                </a>
            </div>
        </div>

        <!-- Show weekly target summary -->
        <?php 
        if ($employee_username) {
            // Get current week target
            $week_number = getWeekNumberInt(date('Y-m-d'));
            $year = date('Y');
            
            $target_stmt = $mysqli->prepare("SELECT target_limit, achieved_target, status FROM weekly_targets 
                                           WHERE tm_id = ? AND week_number = ? AND year = ?");
            if ($target_stmt) {
                $target_stmt->bind_param("sii", $employee_username, $week_number, $year);
                $target_stmt->execute();
                $target_result = $target_stmt->get_result();
                
                if ($target_row = $target_result->fetch_assoc()) {
                    $target_limit = (int)$target_row['target_limit'];
                    $achieved = (int)$target_row['achieved_target'];
                    $remaining = max(0, $target_limit - $achieved);
                    $percentage = $target_limit > 0 ? round(($achieved / $target_limit) * 100, 2) : 0;
                    ?>
                    <div class="alert <?= $percentage >= 100 ? 'alert-success' : ($percentage >= 70 ? 'alert-warning' : 'alert-info') ?> mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1"><i class="fas fa-bullseye me-2"></i>Weekly Target Progress (Week <?= $week_number ?>)</h6>
                                <div class="progress" style="height: 10px; width: 300px;">
                                    <div class="progress-bar <?= $percentage >= 100 ? 'bg-success' : ($percentage >= 70 ? 'bg-warning' : 'bg-danger') ?>" 
                                         role="progressbar" style="width: <?= min($percentage, 100) ?>%">
                                    </div>
                                </div>
                                <small class="text-muted"><?= number_format($achieved) ?> / <?= number_format($target_limit) ?> words (<?= $percentage ?>%)</small>
                            </div>
                            <div>
                                <span class="badge bg-<?= $target_row['status'] === 'Completed' ? 'success' : 'primary' ?>">
                                    <?= $target_row['status'] ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php
                }
                $target_stmt->close();
            }
        }
        ?>

        <?php if($today_has_entry): ?>
        <div class="alert alert-info mb-3">
            <i class="fas fa-info-circle me-2"></i>
            You have already submitted your entry for today. You can edit it if needed.
        </div>
        <?php else: ?>
        <div class="alert alert-warning mb-3">
            <i class="fas fa-exclamation-triangle me-2"></i>
            You haven't submitted your entry for today yet. Please add it before the end of the day.
        </div>
        <?php endif; ?>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <input type="date" name="date_filter" class="form-control form-control-sm" 
                               value="<?= h($date_filter) ?>" placeholder="Filter by date">
                    </div>
                    <div class="col-md-2">
                        <input type="text" name="week_filter" class="form-control form-control-sm" 
                               value="<?= h($week_filter) ?>" placeholder="Week (W41)">
                    </div>
                    
                    <?php if(isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                    <div class="col-md-3">
                        <select name="employee_filter" class="form-select form-select-sm">
                            <option value="">All Employees</option>
                            <?php foreach($employee_list as $emp): ?>
                            <option value="<?= h($emp['employee_username']) ?>" <?= $employee_filter == $emp['employee_username'] ? 'selected' : '' ?>>
                                <?= h($emp['employee_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="col-md-3">
                        <select name="count_type_filter" class="form-select form-select-sm">
                            <option value="">All Types</option>
                            <option value="word" <?= $count_type_filter === 'word' ? 'selected' : '' ?>>Word Count</option>
                            <option value="page" <?= $count_type_filter === 'page' ? 'selected' : '' ?>>Page Count</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="count_id" <?= $sort === 'count_id' ? 'selected' : '' ?>>Sort by ID</option>
                            <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="week_number" <?= $sort === 'week_number' ? 'selected' : '' ?>>Sort by Week</option>
                            <option value="employee_id" <?= $sort === 'employee_id' ? 'selected' : '' ?>>Sort by Employee</option>
                            <option value="count_value" <?= $sort === 'count_value' ? 'selected' : '' ?>>Sort by Count</option>
                        </select>
                    </div>
                    
                    <!-- <div class="col-md-2">
                        <select name="dir" class="form-select form-select-sm">
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option>
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                        </select>
                    </div> -->
                    
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Filter
                        </button>
                        <a href="wordcount_master.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Word Counts Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="80">ID</th>
                                <th width="100">Date</th>
                                <th width="100">Week</th>
                                <?php if(isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                                <th width="150">Employee</th>
                                <?php endif; ?>
                                <th width="120">Type</th>
                                <th>Count Value</th>
                                <th width="120">Created Date</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($wordcounts && $wordcounts->num_rows > 0): ?>
                                <?php while($row = $wordcounts->fetch_assoc()): 
                                    $is_today = $row['date'] == date('Y-m-d');
                                ?>
                                <tr <?= $is_today ? 'class="table-warning"' : '' ?>>
                                    <td>
                                        <span class="count-id">#<?= (int)$row['count_id'] ?></span>
                                        <?php if($is_today): ?>
                                        <span class="badge today-badge ms-1">Today</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary date-badge"><?= formatDate($row['date']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge week-badge date-badge"><?= h($row['week_number']) ?></span>
                                    </td>
                                    <?php if(isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                                    <td>
                                        <span class="badge employee-badge"><?= h($row['employee_name'] ?? 'N/A') ?></span>
                                        <small class="text-muted d-block">ID: <?= h($row['employee_id']) ?></small>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <span class="badge <?= $row['count_type'] === 'word' ? 'bg-info' : 'bg-warning text-dark' ?> count-type-badge">
                                            <?= $row['count_type'] === 'word' ? 'Word Count' : 'Page Count' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="count-value"><?= number_format((int)$row['count_value']) ?></span>
                                        <small class="text-muted ms-2"><?= $row['count_type'] === 'word' ? 'words' : 'pages' ?></small>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= formatDate($row['created_date']) ?></small>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <!-- Edit Button -->
                                            <a href="wordcount_master.php?mode=edit&id=<?= (int)$row['count_id'] ?>" 
                                               class="btn btn-warning" title="Edit Entry">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <!-- Delete Button -->
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this entry?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="count_id" value="<?= (int)$row['count_id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Entry">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?= (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) ? '8' : '7' ?>" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-calculator fa-3x mb-3"></i>
                                            <h5>No word count entries found</h5>
                                            <p class="mb-0">Get started by adding your first word count entry.</p>
                                            <a href="wordcount_master.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add New Entry
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Function to check for duplicate entry via AJAX
        function checkDuplicate() {
            const dateInput = document.getElementById('date');
            const employeeId = document.querySelector('input[name="employee_id"]').value;
            const countId = document.querySelector('input[name="count_id"]') ? document.querySelector('input[name="count_id"]').value : 0;
            const warningElement = document.getElementById('duplicate_warning');
            
            if (!dateInput.value || !employeeId) return;
            
            // Create form data for AJAX request
            const formData = new FormData();
            formData.append('check_duplicate', 'true');
            formData.append('date', dateInput.value);
            formData.append('employee_id', employeeId);
            formData.append('count_id', countId);
            
            // Send AJAX request to check for duplicate
            fetch('check_duplicate.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.duplicate) {
                    warningElement.style.display = 'block';
                    document.getElementById('submitBtn').disabled = true;
                } else {
                    warningElement.style.display = 'none';
                    document.getElementById('submitBtn').disabled = false;
                }
            })
            .catch(error => {
                console.error('Error checking duplicate:', error);
            });
        }

        function updateWeekNumber() {
            const dateInput = document.getElementById('date');
            const weekInput = document.getElementById('week_number');
            
            if (dateInput.value) {
                const date = new Date(dateInput.value);
                const firstDayOfYear = new Date(date.getFullYear(), 0, 1);
                const pastDaysOfYear = (date - firstDayOfYear) / 86400000;
                const weekNumber = Math.ceil((pastDaysOfYear + firstDayOfYear.getDay() + 1) / 7);
                weekInput.value = 'W' + weekNumber.toString().padStart(2, '0');
            }
        }

        function updateCountTypeDisplay() {
            const countType = document.getElementById('count_type').value;
            const countUnit = document.getElementById('count_unit');
            const countDescription = document.getElementById('count_description');
            
            if (countType === 'word') {
                countUnit.textContent = 'words';
                countDescription.textContent = 'Number of words';
            } else {
                countUnit.textContent = 'pages';
                countDescription.textContent = 'Number of pages';
            }
        }

        function validateForm() {
            let isValid = true;
            const date = document.getElementById('date');
            const countValue = document.getElementById('count_value');
            const warningElement = document.getElementById('duplicate_warning');
            
            // Reset validation states
            [date, countValue].forEach(field => {
                field.classList.remove('is-invalid');
            });
            
            // Validate date
            if (date.value.trim() === '') {
                date.classList.add('is-invalid');
                isValid = false;
            }
            
            // Validate count value
            if (countValue.value.trim() === '' || isNaN(countValue.value) || parseInt(countValue.value) <= 0) {
                countValue.classList.add('is-invalid');
                isValid = false;
            }
            
            // Check if duplicate warning is shown
            if (warningElement.style.display === 'block') {
                alert('An entry already exists for this date. Please select a different date.');
                return false;
            }
            
            if (isValid) {
                // Disable submit button to prevent double submission
                document.getElementById('submitBtn').disabled = true;
                document.getElementById('submitBtn').innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';
            }
            
            return isValid;
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert.isConnected) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                }, 5000);
            });

            // Auto-submit form on filter changes
            const filterSelects = document.querySelectorAll('select[name="sort"], select[name="dir"]');
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });
            
            // Set default date to today for add form
            const dateInput = document.getElementById('date');
            if (dateInput && !dateInput.value) {
                dateInput.valueAsDate = new Date();
                updateWeekNumber();
                // Check for duplicate if adding entry
                if (document.querySelector('input[name="action"]').value === 'add') {
                    checkDuplicate();
                }
            }
            
            // Update count type display
            const countTypeSelect = document.getElementById('count_type');
            if (countTypeSelect) {
                countTypeSelect.addEventListener('change', updateCountTypeDisplay);
                updateCountTypeDisplay(); // Initial call
            }
            
            // Real-time validation
            const formFields = document.querySelectorAll('#wordCountForm input[required], #wordCountForm select[required]');
            formFields.forEach(field => {
                field.addEventListener('blur', function() {
                    if (this.value.trim() === '') {
                        this.classList.add('is-invalid');
                    } else {
                        this.classList.remove('is-invalid');
                    }
                });
            });
            
            // Prevent form resubmission on page refresh
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        });
    </script>
</body>
</html>