<?php
// Start a session if one is not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    if(isset($_SESSION['uid'])) $exeid=$_SESSION['uid'];
}
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';
date_default_timezone_set('Asia/Kolkata');

// --- AUTO FIX DB SCHEMA (Add missing columns automatically) ---
$check_columns = [
    'le_start_time' => "TIME NULL DEFAULT NULL",
    'le_end_time' => "TIME NULL DEFAULT NULL",
    'le_qc_start_time' => "TIME NULL DEFAULT NULL",
    'le_qc_end_time' => "TIME NULL DEFAULT NULL",
    'assigned_to_le_qc' => "VARCHAR(100) NULL DEFAULT NULL",
    'wc_exref' => "VARCHAR(50) NULL DEFAULT NULL",
    'status_button' => "VARCHAR(100) NULL DEFAULT NULL",
    'priority' => "VARCHAR(50) NULL DEFAULT NULL"
];
foreach ($check_columns as $col => $definition) {
    $check = $mysqli->query("SHOW COLUMNS FROM le LIKE '$col'");
    if ($check && $check->num_rows == 0) {
        $mysqli->query("ALTER TABLE le ADD COLUMN $col $definition");
    }
}

// Change status column to VARCHAR if it is ENUM
$check_status = $mysqli->query("SHOW COLUMNS FROM le LIKE 'status'");
if ($check_status) {
    $col_data = $check_status->fetch_assoc();
    if (strpos(strtolower($col_data['Type']), 'enum') !== false) {
        $mysqli->query("ALTER TABLE le MODIFY COLUMN status VARCHAR(100) DEFAULT 'Need to Assign'");
    }
}
// --------------------------------------------------------------
function getWorkingDaysWithoutSunday($startDate, $endDate = null) {
    if (empty($startDate) || $startDate == '0000-00-00' || $startDate == '0000-00-00 00:00:00' || $startDate == '30-11--0001') {
        return 0;
    }
    $start = new DateTime(date('Y-m-d', strtotime($startDate)));
    $end = !empty($endDate) && $endDate != '0000-00-00' && $endDate != '0000-00-00 00:00:00' 
            ? new DateTime(date('Y-m-d', strtotime($endDate))) 
            : new DateTime(date('Y-m-d'));
            
    if ($start > $end) return 0;
    
    $days = 0;
    $curr = clone $start;
    while ($curr < $end) {
        $curr->modify('+1 day');
        if ($curr->format('w') != 0) { // 0 = Sunday skip
            $days++;
        }
    }
    return $days;
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

function formatDate($date, $format = 'd-m-Y') {
    if (!$date) return '';
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    return $dateObj ? $dateObj->format($format) : $date;
}

// Get current user info
$current_user_id = $_SESSION['uid'] ?? 0;
$current_username = $_SESSION['username'] ?? '';
$role_name = $_SESSION['role_name'] ?? '';

// Get reporting manager for current user
$reporting_manager = '';
$current_user_name = '';
if ($current_user_id) {
    $stmt = $mysqli->prepare("SELECT employee_name, reporting_manager FROM employee_master WHERE employee_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $stmt->bind_result($current_user_name, $reporting_manager);
        $stmt->fetch();
        $stmt->close();
    }
}

// Get all employees under the same reporting manager (for assignment dropdown)
$les = [];
if ($reporting_manager) {
    $le_query = "SELECT em.employee_id, em.employee_username, em.employee_name, em.user_role_id
                 FROM employee_master em 
                 JOIN user_role_master ur ON em.user_role_id = ur.role_id
                 WHERE em.reporting_manager = ? 
                 AND em.status = 'Active' 
                 AND ur.role_name IN ('TM', 'TL', 'Admin')
                 ORDER BY em.employee_name";
    
    $stmt = $mysqli->prepare($le_query);
    if ($stmt) {
        $stmt->bind_param("s", $reporting_manager);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($le = $result->fetch_assoc()) {
            $les[] = $le;
        }
        $stmt->close();
    }
}

// --- START: Status Update AJAX ---
if (isset($_POST['ajax']) && $_POST['ajax'] == 'update_status') {
    header('Content-Type: application/json');
    $le_id = (int)$_POST['le_id'];
    $target_type = $_POST['type'] ?? 'le';
    $new_status = trim($_POST['status']);

    // Fetch current status and times to validate
    $get_stat = $mysqli->prepare("SELECT status, le_start_time, le_end_time, le_qc_start_time, le_qc_end_time FROM le WHERE id = ?");
    $get_stat->bind_param("i", $le_id);
    $get_stat->execute();
    $curr_data = $get_stat->get_result()->fetch_assoc();
    $curr_status = $curr_data['status'] ?? '';
    $get_stat->close();

    // Mandatory Time Validation for 'Completed' Status
    if ($new_status === 'Completed') {
        if ($target_type === 'le' && (empty($curr_data['le_start_time']) || empty($curr_data['le_end_time']))) {
            echo json_encode(['success' => false, 'message' => 'Please fill both LE Start Time and End Time before marking as Completed.']);
            exit;
        } elseif ($target_type === 'le_qc' && (empty($curr_data['le_qc_start_time']) || empty($curr_data['le_qc_end_time']))) {
            echo json_encode(['success' => false, 'message' => 'Please fill both LE-QC Start Time and End Time before marking as Completed.']);
            exit;
        }
    }

    $db_status = $curr_status; // default to current

    $le_side_done = in_array($curr_status, ['LE Done', 'Completed', 'LE & LE-QC Done']);
    $qc_side_done = in_array($curr_status, ['LE-QC Done', 'Completed', 'LE & LE-QC Done']);

    if ($target_type === 'le') {
        if ($new_status === 'Completed') {
            $db_status = $qc_side_done ? 'Completed' : 'LE Done';
        } else {
            if ($qc_side_done) $db_status = 'LE-QC Done';
            else $db_status = ($new_status === 'In Progress') ? 'In Progress' : 'Need to Assign';
        }
    } elseif ($target_type === 'le_qc') {
        if ($new_status === 'Completed') {
            $db_status = $le_side_done ? 'Completed' : 'LE-QC Done';
        } else {
            if ($le_side_done) $db_status = 'LE Done';
            else $db_status = ($new_status === 'In Progress') ? 'In Progress' : 'Need to Assign';
        }
    }

    $stmt = $mysqli->prepare("UPDATE le SET status = ?, last_updated = NOW() WHERE id = ?");
    $stmt->bind_param("si", $db_status, $le_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'status' => $db_status]);
    } else {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
    }
    exit;
}

// --- START: Inline Edit AJAX (For Time) ---
if (isset($_POST['ajax']) && $_POST['ajax'] == 'inline_edit') {
    header('Content-Type: application/json');
    $le_id = (int)$_POST['le_id'];
    $field = $_POST['field'];
    $val = trim($_POST['value']);
    
    // ADDED: eq, images, and tables_count to allowed fields for inline editing
    $allowed_fields = ['eq', 'images', 'tables_count', 'le_start_time', 'le_end_time', 'le_qc_start_time', 'le_qc_end_time', 'word_count', 'wc_exref', 'status_button', 'priority'];
    if (in_array($field, $allowed_fields)) {
        // Safe binding for time columns to avoid MySQL errors
        if ($val === '') {
            $stmt = $mysqli->prepare("UPDATE le SET $field = NULL, last_updated = NOW() WHERE id = ?");
            $stmt->bind_param("i", $le_id);
        } else {
            $stmt = $mysqli->prepare("UPDATE le SET $field = ?, last_updated = NOW() WHERE id = ?");
            $stmt->bind_param("si", $val, $le_id);
        }
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => $mysqli->error]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid field update']);
    }
    exit;
}

// Handle AJAX quick assignment
if (isset($_POST['ajax']) && $_POST['ajax'] == 'quick_assign') {
    header('Content-Type: application/json');
    
    $le_id = (int)$_POST['le_id'];
    $job_id = (int)$_POST['job_id'];
    $type = $_POST['type'] ?? 'le'; 
    $assigned_to = trim($_POST['assigned_to']);
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        if (empty($assigned_to)) { throw new Exception('Please select an LE Member'); }
        $mysqli->begin_transaction();
        
        // 1. Update LE table (Stronger condition to handle all empty/pending states)
        if ($type == 'le') {
            $update_le = "UPDATE le SET assigned_to_le = ?, status = CASE WHEN status IN ('Need to Assign', 'Pending', '') OR status IS NULL THEN 'In Progress' ELSE status END, last_updated = NOW() WHERE id = ?";
        } elseif ($type == 'le_qc') {
            $update_le = "UPDATE le SET assigned_to_le_qc = ?, status = CASE WHEN status IN ('Need to Assign', 'Pending', '') OR status IS NULL THEN 'In Progress' ELSE status END, last_updated = NOW() WHERE id = ?";
        }
        
        $stmt = $mysqli->prepare($update_le);
        $stmt->bind_param("si", $assigned_to, $le_id);
        if (!$stmt->execute()) { throw new Exception('Error updating LE: ' . $stmt->error); }
        $stmt->close();
        
        // 2. Update JOBS table (Syncing with jobs table, this is exactly how CE page works)
        if ($job_id > 0 && $type == 'le') {
            $stmt2 = $mysqli->prepare("UPDATE jobs SET assigned_le = ?, due_date_le = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE job_id = ?");
            if($stmt2) {
                $stmt2->bind_param("si", $assigned_to, $job_id);
                $stmt2->execute();
                $stmt2->close();
            }
        }
        
        $mysqli->commit();
        $response['success'] = true;
        $response['message'] = 'Assigned successfully';
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $response['message'] = $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// Handle regular form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_le') {
    $le_id = (int)$_POST['le_id'];
    $job_id = (int)$_POST['job_id'];
    $assigned_to_le = trim($_POST['assigned_to_le'] ?? '');
    $le_date = $_POST['le_date'] ?: date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Update le table with assignment
        $update_le = "UPDATE le SET 
                      assigned_to_le = ?,
                      status = 'In Progress',
                      le_date = ?,
                      done_by = ?,
                      last_updated = NOW()
                      WHERE id = ?";
        
        $stmt = $mysqli->prepare($update_le);
        if (!$stmt) {
            throw new Exception('Error preparing statement: ' . $mysqli->error);
        }
        
        $stmt->bind_param("sssi", $assigned_to_le, $le_date, $current_username, $le_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Error executing update: ' . $stmt->error);
        }
        
        $stmt->close();
        
        // Update jobs table with assigned_le
       /* if (!empty($assigned_to_le)) {
            $update_jobs = "UPDATE jobs SET 
                            assigned_le = ?,
                            due_date_le = DATE_ADD(NOW(), INTERVAL 1 DAY)
                            WHERE job_id = ?";
            
            $stmt2 = $mysqli->prepare($update_jobs);
            if ($stmt2) {
                $stmt2->bind_param("si", $assigned_to_le, $job_id);
                $stmt2->execute();
                $stmt2->close();
            }
        }
        */
        // Commit transaction
        $mysqli->commit();
        
        $msg = "LE assigned successfully";
        $type = "success";
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $mysqli->rollback();
        $msg = "Error assigning LE: " . $e->getMessage();
        $type = "danger";
    }
    
    header("Location: le_assign_tm.php?msg=" . urlencode($msg) . "&type=" . $type);
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$le_tm_filter = isset($_GET['le_tm']) ? trim($_GET['le_tm']) : '';
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'le.created_at';
$dir = isset($_GET['dir']) && strtoupper($_GET['dir']) == 'ASC' ? 'ASC' : 'DESC';

// Build the query for LE records where CE is completed and Moved
// Keep row on LE Assign page even if completed, until Status is selected as 'Moved'
$where_conditions = [
    "(le.status_button IS NULL OR le.status_button != 'Moved')",
    "(ce.status LIKE '%Completed%' OR ce.status LIKE '%Done%')", 
    "ce.remarks = 'Moved'", 
    "(REPLACE(jm.job_name, ' ', '') = 'CE+PAG' OR REPLACE(jm.job_name, ' ', '') = 'CE+TS')"
];

$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(c.client_name LIKE ? OR c.team_client_name LIKE ? OR le.issue LIKE ? OR le.articles LIKE ? OR le.process LIKE ? OR j.journal_issue LIKE ?)";
    $search_term = "%$search%";
    for ($i = 0; $i < 6; $i++) {
        $params[] = $search_term;
    }
    $types .= 'ssssss';
}

if (!empty($status_filter)) {
    $where_conditions[] = "le.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($le_tm_filter)) {
    $where_conditions[] = "le.assigned_to_le = ?";
    $params[] = $le_tm_filter;
    $types .= 's';
}

$where_sql = implode(' AND ', $where_conditions);

// Main query - Join with jobs and client_master to get CE completion status
// Main query - Join with jobs, client_master, and ce table to get exact columns
$le_query = "SELECT le.*, 
                    j.journal_issue, 
                    j.article,
                    j.process_id,
                    j.assigned_to,
                    j.assigned_ce,
                    j.assigned_le as job_assigned_le,
                    j.due_date as job_due_date,
                    j.due_date_le,
                    j.job_date,
                    j.notes as job_notes,
                    j.queries as job_queries,
                    j.word_count as job_word_count,
                    j.mail_subject,
                    c.client_name,
                    c.team_client_name,
                    c.client_id,
                    ce.status as ce_status_full,
                    ce.assigned_to_img,
                    ce.ref as ref_count,
                    ce.eq as ce_eq,
                    ce.images as ce_images,
                    ce.tables_count as ce_tables,
                    ce.ce_date,
                    DATEDIFF(COALESCE(j.due_date_le, j.due_date, CURDATE()), CURDATE()) as days_remaining
             FROM le 
             JOIN jobs j ON le.job_id = j.job_id
             LEFT JOIN client_master c ON j.client_id = c.client_id
             LEFT JOIN ce ON ce.job_id = j.job_id
             LEFT JOIN job_master jm ON j.process_id = jm.job_id
             WHERE $where_sql
             ORDER BY 
                CASE 
                    WHEN le.status IS NULL OR le.status = '' OR le.status = 'Pending' OR le.status = 'Need to Assign' THEN 1
                    WHEN le.status = 'In Progress' OR le.status = 'LE Done' OR le.status = 'LE-QC Done' THEN 2
                    ELSE 3
                END,
                $sort $dir";

$stmt = $mysqli->prepare($le_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$le_result = $stmt->get_result();
$stmt->close();

// Get unique statuses for filter dropdown
$status_query = "SELECT DISTINCT status FROM le WHERE status != 'Completed' AND status != 'Done' ORDER BY status";
$status_result = $mysqli->query($status_query);

// Get count statistics - Only for CE completed and Moved jobs
$stats_query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN le.status IS NULL OR le.status = '' OR le.status = 'Pending' OR le.status = 'Need to Assign' THEN 1 ELSE 0 END) as need_assign,
                    SUM(CASE WHEN le.status = 'In Progress' OR le.status = 'LE Done' OR le.status = 'LE-QC Done' OR le.status = 'Completed' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN le.assigned_to_le IS NOT NULL AND le.assigned_to_le != '' THEN 1 ELSE 0 END) as assigned
                FROM le 
                JOIN jobs j ON le.job_id = j.job_id
                LEFT JOIN job_master jm ON j.process_id = jm.job_id
                LEFT JOIN ce ON ce.job_id = j.job_id
                WHERE (le.status_button IS NULL OR le.status_button != 'Moved')
                AND (ce.status LIKE '%Completed%' OR ce.status LIKE '%Done%')
                AND ce.remarks = 'Moved'
                AND (REPLACE(jm.job_name, ' ', '') = 'CE+PAG' OR REPLACE(jm.job_name, ' ', '') = 'CE+TS')";
$stats_result = $mysqli->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Debug: Check what users are available (visible in HTML source)
/*
echo "<!-- DEBUG: Available team members (" . count($les) . " found) -->\n";
foreach ($les as $index => $le) {
    echo "<!-- DEBUG: " . ($index+1) . ". " . $le['employee_name'] . " (" . $le['employee_username'] . ") -->\n";
}
*/

// Fetch employee names for mapping
$emp_map = [];
$emp_res = $mysqli->query("SELECT employee_username, employee_name FROM employee_master");
if ($emp_res) {
    while ($e = $emp_res->fetch_assoc()) {
        $emp_map[$e['employee_username']] = $e['employee_name'];
    }
}

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LE Assign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        body {
            background-color: #f8fafc;
        }
        
        .page-header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 0;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .stats-card {
            border-radius: 12px;
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border: 1px solid rgba(0,0,0,0.05);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            transition: transform 0.2s;
        }
        .stats-card:hover { transform: translateY(-2px); }
        
        /* New Full Box Colors */
        .stats-card.card-total { background: #e0f2fe; border-color: #bae6fd; }
        .stats-card.card-need { background: #fef3c7; border-color: #fde68a; }
        .stats-card.card-progress { background: #dbeafe; border-color: #bfdbfe; }
        .stats-card.card-assigned { background: #dcfce7; border-color: #bbf7d0; }
        
        .stats-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: rgba(255,255,255,0.6); /* White transparency for icon bg */
        }
        
        .stats-icon.total { color: #0369a1; }
        .stats-icon.need { color: #92400e; }
        .stats-icon.progress { color: #1e40af; }
        .stats-icon.assigned { color: #166534; }
        
        .stats-icon.total {
            background: #e0f2fe;
            color: #0369a1;
        }
        
        .stats-icon.need {
            background: #fef3c7;
            color: #92400e;
        }
        
        .stats-icon.progress {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .stats-icon.assigned {
            background: #dcfce7;
            color: #166534;
        }
        
        .stats-info h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
            line-height: 1.2;
        }
        
        .stats-info p {
            margin: 0;
            color: #64748b;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .ce-completed-badge {
            background: #10b981;
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.65rem;
            font-weight: 500;
            display: inline-block;
        }
        
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e2e8f0;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table thead th {
            background-color: #707981 !important; 
            color: white !important;
            font-weight: bold;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            padding: 0.8rem 0.5rem;
            border: 1px solid #5a6268 !important;
            white-space: nowrap;
        }
        
        .table tbody td {
            padding: 0.6rem 0.4rem;
            font-size: 0.75rem;
            color: #334155;
            vertical-align: middle;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .table tbody tr:hover {
            background-color: #f8fafc;
        }
        
        .table tbody tr.assigned {
            background-color: #f0fdf4;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.4rem;
            border-radius: 9999px;
            font-size: 0.65rem;
            font-weight: 500;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-progress {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .le-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
            background: #f1f5f9;
            color: #475569;
            white-space: nowrap;
        }
        
        /* Dynamic Workflow Badges */
        .le-badge.in-progress {
            background-color: #f3e8ff !important;
            color: #7e22ce !important;
            border: 1px solid #d8b4fe !important;
            font-weight: 600;
        }
        .le-badge.completed {
            background-color: #dcfce7 !important;
            color: #15803d !important;
            border: 1px solid #86efac !important;
            font-weight: 600;
        }
        .le-badge.pending {
            background-color: #f1f5f9;
            color: #64748b;
        }
        
        .le-badge i {
            font-size: 0.6rem;
        }
        
        .ce-info {
            background: #f1f5f9;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.65rem;
            color: #475569;
            margin-top: 0.2rem;
        }
        
        .assignment-dropdown {
            min-width: 140px;
        }
        
        .assign-select {
            font-size: 0.7rem;
            padding: 0.2rem 0.4rem;
            height: auto;
            width: 160px;
            max-width: 100%;
            border-radius: 4px;
            border: 1.5px solid #e2e8f0;
            background-color: white;
        }
        
        .assign-select:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 2px rgba(102,126,234,0.1);
        }
        
        .btn-group-sm .btn {
            padding: 0.2rem 0.4rem;
            font-size: 0.7rem;
        }
        
        .text-truncate {
            max-width: 120px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .sort-link {
            color: #1e293b;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .sort-link:hover {
            color: #667eea;
        }
        
        .sort-link i {
            font-size: 0.6rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }
        
        .due-date {
            font-weight: 500;
        }
        
        .due-date.urgent {
            color: #dc2626;
        }
        
        .due-date.warning {
            color: #d97706;
        }
        
        .due-date.normal {
            color: #059669;
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
        }
        
        .tooltip-inner {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
        }
        
        .assignment-cell {
            min-width: 200px; /* Increased width to make the column bigger */
            padding: 0.6rem !important; /* Added a little padding for a neat look */
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .loading-overlay.active {
            display: flex;
        }
        
        .loading-spinner {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            min-width: 300px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .dropdown-scroll {
            max-height: 300px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <br><br><br>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner-border text-primary mb-2" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mb-0 small">Processing assignment...</p>
        </div>
    </div>

    <!-- Notification Container -->
    <div id="notificationContainer" style="position: fixed; top: 20px; right: 20px; z-index: 10000;"></div>

    <div class="page-header">
        <div class="container-fluid px-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-1" style="font-weight: 700; color: #1e293b;">
                        <i class="fas fa-table me-2" style="color: #667eea;"></i>
                        LE Assign
                    </h4>
                    <!-- <p class="mb-0 text-muted small">
                        Assign LE jobs to team members
                        <span class="ce-completed-badge ms-2">
                            <i class="fas fa-check-circle"></i> CE Completed Only
                        </span>
                    </p> -->
                </div>
                <div>
                    <a href="jobs.php" class="btn btn-outline-secondary btn-sm me-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Jobs
                    </a>
                    <span class="badge bg-primary">
                        <i class="fas fa-users me-1"></i> <?= count($les) ?> Team Members Available
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid px-4">
        <?php if (!empty($_GET['msg'])): ?>
            <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show">
                <i class="fas <?= ($_GET['type'] ?? 'info') === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> me-2"></i>
                <?= h($_GET['msg']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stats-card card-total">
                    <div class="stats-icon total">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stats-info">
                        <h3><?= $stats['total'] ?? 0 ?></h3>
                        <p>Total Pending (CE Done)</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card card-need">
                    <div class="stats-icon need">
                        <i class="fas fa-hourglass-start"></i>
                    </div>
                    <div class="stats-info">
                        <h3><?= $stats['need_assign'] ?? 0 ?></h3>
                        <p>Need to Assign</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card card-progress">
                    <div class="stats-icon progress">
                        <i class="fas fa-spinner"></i>
                    </div>
                    <div class="stats-info">
                        <h3><?= $stats['in_progress'] ?? 0 ?></h3>
                        <p>In Progress</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card card-assigned">
                    <div class="stats-icon assigned">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stats-info">
                        <h3><?= $stats['assigned'] ?? 0 ?></h3>
                        <p>LE Assigned</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" 
                           placeholder="Client, Journal, Article..." 
                           value="<?= h($search) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <?php if ($status_result): ?>
                            <?php while ($status = $status_result->fetch_assoc()): ?>
                                <option value="<?= h($status['status']) ?>" 
                                    <?= $status_filter == $status['status'] ? 'selected' : '' ?>>
                                    <?= h($status['status']) ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">LE Member</label>
                    <select name="le_tm" class="form-select form-select-sm">
                        <option value="">All Members</option>
                        <?php foreach ($les as $le): ?>
                            <option value="<?= h($le['employee_username']) ?>" 
                                <?= $le_tm_filter == $le['employee_username'] ? 'selected' : '' ?>>
                                <?= h($le['employee_name']) ?> (<?= h($le['employee_username']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Sort By</label>
                    <select name="sort" class="form-select form-select-sm">
                        <option value="le.created_at" <?= $sort == 'le.created_at' ? 'selected' : '' ?>>Created Date</option>
                        <option value="c.client_name" <?= $sort == 'c.client_name' ? 'selected' : '' ?>>Client Name</option>
                        <option value="le.status" <?= $sort == 'le.status' ? 'selected' : '' ?>>Status</option>
                        <option value="j.due_date_le" <?= $sort == 'j.due_date_le' ? 'selected' : '' ?>>LE Due Date</option>
                    </select>
                </div>
                <!-- <div class="col-md-1">
                    <label class="form-label small">Dir</label>
                    <select name="dir" class="form-select form-select-sm">
                        <option value="DESC" <?= $dir == 'DESC' ? 'selected' : '' ?>>Desc</option>
                        <option value="ASC" <?= $dir == 'ASC' ? 'selected' : '' ?>>Asc</option>
                    </select>
                </div> -->
                <div class="col-12 mt-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter me-1"></i> Apply Filters
                    </button>
                    <a href="le_assign_tm.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-undo me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Main Grid/Table -->
        <div class="table-container">
            <div class="table-responsive">
                <table class="table table-hover" style="min-width: 1400px;">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>CE Completed Date</th>
                            <th>Received Date</th>
                            <th>Client</th>
                            <th>Issue</th>
                            <th>Articles</th>
                            <th>EQ</th>
                            <th>Images</th>
                            <th>Tables</th>
                            <th>Process</th>
                            <th>Due Date</th>
                            <th>CE Status</th>
                            <th>Word Count</th>
                            <th>WC-ExRef</th>
                            <th>REF Status</th>
                            <th>REF Count</th>
                            <th>FQA Status</th>
                            <th>LE Assignment</th>
                            <th>LE-QC Assignment</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>TAT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($le_result && $le_result->num_rows > 0): $i=0;?>
                            <?php while ($row = $le_result->fetch_assoc()): $i=$i+1;
                                $has_le = !empty($row['assigned_to_le']);
                                $row_class = $has_le ? 'assigned' : '';
                                
                                // Due date color coding
                                $days_remaining = $row['days_remaining'] ?? 0;
                                $due_class = '';
                                if ($days_remaining < 2) {
                                    $due_class = 'urgent';
                                } elseif ($days_remaining < 4) {
                                    $due_class = 'warning';
                                } else {
                                    $due_class = 'normal';
                                }
                                
                                $le_status = $row['status'] ?? 'Need to Assign';
                                $le_status_class = (empty($le_status) || $le_status == 'Need to Assign' || $le_status == 'Pending') ? 'status-pending' : 'status-progress';
                            ?>
                                <tr class="<?= $row_class ?>" id="row-<?= $row['id'] ?>">
                                    <td><?php echo $i; ?></td>
                                    <td>
                                        <span class="fw-500" style="color: #10b981;"><?= !empty($row['ce_date']) ? date('d-m-Y', strtotime($row['ce_date'])) : '—' ?></span>
                                    </td>
                                    <td>
                                        <span class="fw-500 text-muted"><?= formatDate($row['job_date'] ?? '') ?></span>
                                    </td>
                                    <td>
                                        <span class="fw-500"><?= h(!empty($row['team_client_name']) ? $row['team_client_name'] : ($row['client_name'] ?? $row['client'] ?? 'N/A')) ?></span>
                                    </td>
                                    <td>
                                        <span class="text-truncate d-block" title="<?= h($row['issue'] ?? '') ?>">
                                            <?= h($row['issue'] ?? 'N/A') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-truncate d-block" title="<?= h($row['articles'] ?? '') ?>">
                                            <?= h($row['articles'] ?? 'N/A') ?>
                                        </span>
                                    </td>
                                    <!-- EQ (Editable) -->
                                    <td>
                                        <input type="number" class="form-control form-control-sm p-1 text-center" style="width: 50px; font-size:11px;" value="<?= h(!empty($row['eq']) ? $row['eq'] : ($row['ce_eq'] ?? '0')) ?>" onchange="updateInlineField(<?= $row['id'] ?>, 'eq', this.value)">
                                    </td>

                                    <!-- Images (Editable) -->
                                    <td>
                                        <input type="number" class="form-control form-control-sm p-1 text-center" style="width: 50px; font-size:11px;" value="<?= h(!empty($row['images']) ? $row['images'] : ($row['ce_images'] ?? '0')) ?>" onchange="updateInlineField(<?= $row['id'] ?>, 'images', this.value)">
                                    </td>

                                    <!-- Tables (Editable) -->
                                    <td>
                                        <input type="number" class="form-control form-control-sm p-1 text-center" style="width: 50px; font-size:11px;" value="<?= h(!empty($row['tables_count']) ? $row['tables_count'] : ($row['ce_tables'] ?? '0')) ?>" onchange="updateInlineField(<?= $row['id'] ?>, 'tables_count', this.value)">
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?= h($row['process'] ?? 'N/A') ?></span>
                                    </td>
                                    <td>
                                        <?php 
                                        $due = $row['due_date_le'] ?? $row['job_due_date'] ?? $row['due_date'] ?? '';
                                        if ($due && $due != '0000-00-00'): 
                                        ?>
                                            <span class="due-date <?= $due_class ?>" title="<?= $days_remaining ?> days remaining">
                                                <?= formatDate($due) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- CE Status -->
                                    <td>
                                        <?php 
                                            $ce_val = $row['ce_status_full'] ?? 'Completed';
                                            $ce_cls = (strpos(strtolower($ce_val), 'completed') !== false || strpos(strtolower($ce_val), 'done') !== false) ? 'bg-success' : 'bg-warning';
                                        ?>
                                        <span class="badge <?= $ce_cls ?>"><?= h($ce_val) ?></span>
                                        <?php if (!empty($row['assigned_ce'])): 
                                            $ce_name = $emp_map[$row['assigned_ce']] ?? '';
                                            $ce_display = $ce_name ? h($ce_name) . " (" . h($row['assigned_ce']) . ")" : h($row['assigned_ce']);
                                        ?>
                                            <div class="mt-1" style="font-size: 10px; color: #15803d; font-weight: 600; white-space: nowrap;">
                                                <i class="fas fa-user"></i> <?= $ce_display ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Word Count -->
                                    <td>
                                         <?php 
                                         $word_count = (!empty($row['word_count']) && (int)$row['word_count'] > 0) ? (int)$row['word_count'] : ((!empty($row['job_word_count']) && (int)$row['job_word_count'] > 0) ? (int)$row['job_word_count'] : 0);
                                         ?>
                                         <input type="number" class="form-control form-control-sm p-1 text-center fw-bold text-primary" style="width: 70px; font-size:13px; border: 1px solid #667eea;" value="<?= h($word_count) ?>" onchange="updateInlineField(<?= $row['id'] ?>, 'word_count', this.value)">
                                    </td>

                                    <!-- WC-ExRef -->
                                    <td>
                                        <input type="text" class="form-control form-control-sm p-1 text-center" style="width: 70px; font-size:12px;" value="<?= h($row['wc_exref'] ?? '') ?>" placeholder="ExRef" onchange="updateInlineField(<?= $row['id'] ?>, 'wc_exref', this.value)">
                                    </td>

                                    <!-- REF Status -->
                                    <td>
                                        <?php 
                                            $ce_status_val = $row['ce_status_full'] ?? '';
                                            if (in_array($ce_status_val, ['Completed', 'CE & REF Done', 'REF Done'])) {
                                                $ref_stat = 'Completed';
                                                $ref_badge = 'bg-success';
                                            } elseif (!empty($row['assigned_to_img'])) {
                                                $ref_stat = 'Assigned';
                                                $ref_badge = 'bg-primary';
                                            } else {
                                                $ref_stat = 'Pending';
                                                $ref_badge = 'bg-warning';
                                            }
                                        ?>
                                        <span class="badge <?= $ref_badge ?>"><?= $ref_stat ?></span>
                                        <?php if (!empty($row['assigned_to_img'])): 
                                            $ref_name = $emp_map[$row['assigned_to_img']] ?? '';
                                            $ref_display = $ref_name ? h($ref_name) . " (" . h($row['assigned_to_img']) . ")" : h($row['assigned_to_img']);
                                        ?>
                                            <div class="mt-1" style="font-size: 10px; color: #15803d; font-weight: 600; white-space: nowrap;">
                                                <i class="fas fa-user"></i> <?= $ref_display ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- REF Count -->
                                    <td><?= h($row['ref_count'] ?? '0') ?></td>

                                    <!-- FQA Status -->
                                    <td>
                                        <?php 
                                            if ($ce_status_val === 'Completed') {
                                                $fqa_stat = 'Completed';
                                                $fqa_badge = 'bg-success';
                                            } elseif (!empty($row['assigned_to'])) {
                                                $fqa_stat = 'Assigned';
                                                $fqa_badge = 'bg-primary';
                                            } else {
                                                $fqa_stat = 'Pending';
                                                $fqa_badge = 'bg-warning';
                                            }
                                        ?>
                                        <span class="badge <?= $fqa_badge ?>"><?= $fqa_stat ?></span>
                                        <?php if (!empty($row['assigned_to'])): 
                                            $fqa_name = $emp_map[$row['assigned_to']] ?? '';
                                            $fqa_display = $fqa_name ? h($fqa_name) . " (" . h($row['assigned_to']) . ")" : h($row['assigned_to']);
                                        ?>
                                            <div class="mt-1" style="font-size: 10px; color: #15803d; font-weight: 600; white-space: nowrap;">
                                                <i class="fas fa-user"></i> <?= $fqa_display ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- LE Assignment Column -->
                                    <td class="assignment-cell">
                                        <?php 
                                            $le_done = in_array($row['status'], ['LE Done', 'Completed', 'LE & LE-QC Done']);
                                            $le_inprogress = (!$le_done && !empty($row['assigned_to_le']));

                                            $le_curr_status = 'Need to Assign';
                                            $le_badge_cls = 'pending';

                                            if ($le_done) {
                                                $le_curr_status = 'Completed';
                                                $le_badge_cls = 'completed'; // Green
                                            } elseif ($le_inprogress) {
                                                $le_curr_status = 'In Progress';
                                                $le_badge_cls = 'in-progress'; // Purple
                                            }
                                        ?>
                                        <div class="mb-1">
                                            <select class="form-select form-select-sm p-0 px-1" 
                                                    style="font-size: 11px; width: 130px; height: 22px; border-color: #ccc; background-color: #f9f9f9;" 
                                                    onchange="updateStatus(<?= $row['id'] ?>, 'le', this.value)">
                                                <option value="Need to Assign" <?= ($le_curr_status == 'Need to Assign') ? 'selected' : '' ?>>Need to Assign</option>
                                                <option value="In Progress" <?= ($le_curr_status == 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                                                <option value="Completed" <?= ($le_curr_status == 'Completed') ? 'selected' : '' ?>>Completed</option>
                                            </select>
                                        </div>

                                        <?php if (!empty($row['assigned_to_le'])): ?>
                                            <div class="le-badge <?= $le_badge_cls ?> mb-1" title="LE TM">
                                                <i class="fas fa-file-alt"></i> <?= h($row['assigned_to_le']) ?>
                                            </div>
                                            <small class="text-muted d-block mb-1">
                                                <?php 
                                                $found_name = '';
                                                foreach ($les as $le) { if ($le['employee_username'] == $row['assigned_to_le']) { $found_name = $le['employee_name']; break; } }
                                                echo h($found_name ?: $row['assigned_to_le']);
                                                ?>
                                            </small>
                                        <?php endif; ?>
                                        
                                        <select class="assign-select mt-1 le-select-<?= $row['id'] ?>" onchange="quickAssign(<?= $row['id'] ?>, <?= $row['job_id'] ?>, 'le', this)">
                                            <option value=""><?= !empty($row['assigned_to_le']) ? 'Change LE TM' : 'Assign LE' ?></option>
                                            <?php foreach ($les as $le): ?>
                                                <option value="<?= h($le['employee_username']) ?>" data-name="<?= h($le['employee_name']) ?>" <?= ($row['assigned_to_le'] == $le['employee_username']) ? 'selected' : '' ?>>
                                                    <?= h($le['employee_name']) ?> (<?= h($le['employee_username']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <!-- LE Time Inputs -->
                                        <div class="d-flex gap-1 mt-2">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; width:50%; cursor:pointer;" title="LE Start" value="<?= !empty($row['le_start_time']) ? date('H:i', strtotime($row['le_start_time'])) : '' ?>" readonly onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'le_start_time', t, this);">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; width:50%; cursor:pointer;" title="LE End" value="<?= !empty($row['le_end_time']) ? date('H:i', strtotime($row['le_end_time'])) : '' ?>" readonly onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'le_end_time', t, this);">
                                        </div>
                                    </td>

                                    <!-- LE-QC Assignment Column -->
                                    <td class="assignment-cell">
                                        <?php 
                                            $leqc_done = in_array($row['status'], ['LE-QC Done', 'Completed', 'LE & LE-QC Done']);
                                            $leqc_inprogress = (!$leqc_done && !empty($row['assigned_to_le_qc']));

                                            $leqc_curr_status = 'Need to Assign';
                                            $leqc_badge_cls = 'pending';

                                            if ($leqc_done) {
                                                $leqc_curr_status = 'Completed';
                                                $leqc_badge_cls = 'completed'; // Green
                                            } elseif ($leqc_inprogress) {
                                                $leqc_curr_status = 'In Progress';
                                                $leqc_badge_cls = 'in-progress'; // Purple
                                            }
                                        ?>
                                        <div class="mb-1">
                                            <select class="form-select form-select-sm p-0 px-1" 
                                                    style="font-size: 11px; width: 130px; height: 22px; border-color: #ccc; background-color: #f9f9f9;" 
                                                    onchange="updateStatus(<?= $row['id'] ?>, 'le_qc', this.value)">
                                                <option value="Need to Assign" <?= ($leqc_curr_status == 'Need to Assign') ? 'selected' : '' ?>>Need to Assign</option>
                                                <option value="In Progress" <?= ($leqc_curr_status == 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                                                <option value="Completed" <?= ($leqc_curr_status == 'Completed') ? 'selected' : '' ?>>Completed</option>
                                            </select>
                                        </div>

                                        <?php if (!empty($row['assigned_to_le_qc'])): ?>
                                            <div class="le-badge <?= $leqc_badge_cls ?> mb-1" title="LE QC TM">
                                                <i class="fas fa-search"></i> <?= h($row['assigned_to_le_qc']) ?>
                                            </div>
                                            <small class="text-muted d-block mb-1">
                                                <?php 
                                                $found_name_qc = '';
                                                foreach ($les as $le) { if ($le['employee_username'] == $row['assigned_to_le_qc']) { $found_name_qc = $le['employee_name']; break; } }
                                                echo h($found_name_qc ?: $row['assigned_to_le_qc']);
                                                ?>
                                            </small>
                                        <?php endif; ?>
                                        
                                        <select class="assign-select mt-1 le-qc-select-<?= $row['id'] ?>" onchange="quickAssign(<?= $row['id'] ?>, <?= $row['job_id'] ?>, 'le_qc', this)">
                                            <option value=""><?= !empty($row['assigned_to_le_qc']) ? 'Change LE-QC TM' : 'Assign LE-QC' ?></option>
                                            <?php foreach ($les as $le): ?>
                                                <option value="<?= h($le['employee_username']) ?>" data-name="<?= h($le['employee_name']) ?>" <?= (($row['assigned_to_le_qc'] ?? '') == $le['employee_username']) ? 'selected' : '' ?>>
                                                    <?= h($le['employee_name']) ?> (<?= h($le['employee_username']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <!-- LE-QC Time Inputs -->
                                        <div class="d-flex gap-1 mt-2">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; width:50%; cursor:pointer;" title="LE QC Start" value="<?= !empty($row['le_qc_start_time']) ? date('H:i', strtotime($row['le_qc_start_time'])) : '' ?>" readonly onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'le_qc_start_time', t, this);">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; width:50%; cursor:pointer;" title="LE QC End" value="<?= !empty($row['le_qc_end_time']) ? date('H:i', strtotime($row['le_qc_end_time'])) : '' ?>" readonly onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'le_qc_end_time', t, this);">
                                        </div>
                                    </td>
                                    <!-- Status Button Column -->
                                    <td>
                                        <?php $sb_val = $row['status_button'] ?? ''; ?>
                                        <select class="form-select form-select-sm p-1 <?= $sb_val === 'Moved' ? 'bg-success text-white' : '' ?>" style="font-size: 11px; width: 140px;" onchange="updateInlineField(<?= $row['id'] ?>, 'status_button', this.value); this.className='form-select form-select-sm p-1 ' + (this.value==='Moved'?'bg-success text-white':'');">
                                            <option value="">Select</option>
                                            <?php 
                                            $sb_options = ['Yet to move', 'Hold', 'Chk Highlights', 'Doubt', 'Query', 'Not in server', 'Not in drive', 'Take drive', 'Chk rename', 'Before 11 AM', 'Before 1 PM', 'Before 4 PM', 'Before 5 PM', 'Chk Updated Instruction', 'Moved'];
                                            foreach($sb_options as $opt) {
                                                $selected = ($sb_val === $opt) ? 'selected' : '';
                                                echo "<option value=\"".h($opt)."\" $selected>".h($opt)."</option>";
                                            }
                                            ?>
                                        </select>
                                    </td>

                                    <!-- Priority Column -->
                                    <td>
                                        <select class="form-select form-select-sm p-1" style="font-size: 11px; width: 110px;" onchange="updateInlineField(<?= $row['id'] ?>, 'priority', this.value)">
                                            <option value="">Select Priority</option>
                                            <option value="Client Priority" <?= ($row['priority'] ?? '') == 'Client Priority' ? 'selected' : '' ?>>Client Priority</option>
                                            <option value="Urgent" <?= ($row['priority'] ?? '') == 'Urgent' ? 'selected' : '' ?>>Urgent</option>
                                        </select>
                                    </td>
                                    <!-- TAT Column -->
                                    <?php
                                        $le_done = in_array($row['status'] ?? '', ['Completed', 'Done', 'LE & LE-QC Done']);
                                        $end_d = $le_done ? ($row['le_date'] ?: $row['last_updated']) : null;
                                        $tat = getWorkingDaysWithoutSunday($row['job_date'] ?? $row['date'], $end_d);
                                        $bg = $le_done ? 'bg-success' : ($tat > 5 ? 'bg-danger' : 'bg-warning text-dark');
                                    ?>
                                    <td>
                                        <span class="badge <?= $bg ?>"><?= $tat ?> <?= ($tat == 1) ? 'Day' : 'Days' ?></span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <h6>No Pending LE Assignments</h6>
                                    <p class="text-muted small">All CE completed jobs have been assigned for LE or no CE completed jobs are available.</p>
                                    <?php if (empty($les)): ?>
                                        <p class="text-warning small mt-2">
                                            <i class="fas fa-exclamation-triangle"></i> 
                                            No team members found in database. Please check employee_master table.
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination Info -->
        <?php if ($le_result && $le_result->num_rows > 0): ?>
            <div class="row mt-3">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="fas fa-list me-1"></i> Showing <?= $le_result->num_rows ?> job(s) with CE completed
                        </small>
                        <small class="text-muted">
                            Total Team Members: <strong><?= count($les) ?></strong>
                        </small>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });
        
        // Show notification
        function showNotification(type, message) {
            const container = document.getElementById('notificationContainer');
            const notification = document.createElement('div');
            notification.className = `alert alert-${type} alert-dismissible fade show notification`;
            notification.setAttribute('role', 'alert');
            notification.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} me-2"></i>
                    <div>${message}</div>
                    <button type="button" class="btn-close ms-3" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
            container.appendChild(notification);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                notification.remove();
            }, 5000);
        }
        
        // Show loading overlay
        function showLoading() {
            document.getElementById('loadingOverlay').classList.add('active');
        }
        
        // Hide loading overlay
        function hideLoading() {
            document.getElementById('loadingOverlay').classList.remove('active');
        }
        
        // Status Update Function
        function updateStatus(leId, type, newStatus) {
            // Frontend validation to enforce time completion
            if (newStatus === 'Completed') {
                let row = document.getElementById('row-' + leId);
                if (row) {
                    let startTitle = (type === 'le') ? 'LE Start' : 'LE QC Start';
                    let endTitle = (type === 'le') ? 'LE End' : 'LE QC End';
                    
                    let startInput = row.querySelector('input[title="' + startTitle + '"]');
                    let endInput = row.querySelector('input[title="' + endTitle + '"]');
                    
                    if (startInput && endInput) {
                        if (!startInput.value || !endInput.value) {
                            showNotification('danger', 'Please enter both Start Time and End Time before marking as Completed!');
                            setTimeout(() => { window.location.reload(); }, 1500); // reload to revert the dropdown change
                            return; 
                        }
                    }
                }
            }

            showLoading();
            fetch('le_assign_tm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=update_status&le_id=' + leId + '&type=' + encodeURIComponent(type) + '&status=' + encodeURIComponent(newStatus)
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showNotification('success', 'Status updated successfully!');
                    setTimeout(() => { window.location.reload(); }, 500);
                } else { 
                    showNotification('danger', 'Error updating status: ' + (data.message || '')); 
                    setTimeout(() => { window.location.reload(); }, 1500);
                }
            }).catch(error => { 
                hideLoading(); 
                console.error('Error:', error); 
                setTimeout(() => { window.location.reload(); }, 1500);
            });
        }

        // Inline Field Editor (For Time)
        function updateInlineField(leId, field, value, element = null) {
            if ((field.includes('start_time') || field.includes('end_time')) && value !== '') {
                const now = new Date();
                const currentHour = String(now.getHours()).padStart(2, '0');
                const currentMinute = String(now.getMinutes()).padStart(2, '0');
                const currentTime = currentHour + ':' + currentMinute;
                if (value !== currentTime) {
                    if (element) { element.value = currentTime; value = currentTime; } 
                    else { return; }
                }
            }

            showLoading();
            fetch('le_assign_tm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=inline_edit&le_id=' + leId + '&field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(value)
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) { 
                    if (field === 'status_button' && value === 'Moved') {
                        showNotification('success', 'Job moved to LE Report & TS Assign successfully!');
                        const rowEl = document.getElementById('row-' + leId);
                        if (rowEl) {
                            rowEl.style.transition = 'all 0.4s ease';
                            rowEl.style.opacity = '0';
                            setTimeout(() => { 
                                rowEl.remove(); 
                                window.location.reload(); 
                            }, 400);
                        }
                    } else {
                        showNotification('success', 'Updated successfully!'); 
                    }
                } 
                else { showNotification('danger', 'Update failed: ' + (data.message || 'Unknown error')); }
            }).catch(error => { hideLoading(); console.error('Error:', error); });
        }
        
        // Quick assign function
        function quickAssign(leId, jobId, type, selectElement) {
            const selectedValue = selectElement.value;
            if (!selectedValue) return;
            
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const leName = selectedOption.getAttribute('data-name') || selectedValue;
            
            let typeLabel = (type === 'le_qc') ? 'LE-QC' : 'LE';
            
            selectElement.setAttribute('data-previous', selectedValue);
            selectElement.disabled = true;
            showLoading();
            
            fetch('le_assign_tm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=quick_assign&le_id=' + leId + '&job_id=' + jobId + '&type=' + type + '&assigned_to=' + encodeURIComponent(selectedValue)
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                selectElement.disabled = false;
                if (data.success) {
                    showNotification('success', `${typeLabel} assigned to ${leName} successfully!`);
                    document.getElementById('row-' + leId).classList.add('assigned');
                    setTimeout(() => { window.location.reload(); }, 800);
                } else {
                    showNotification('danger', 'Error: ' + data.message);
                    selectElement.value = selectElement.getAttribute('data-previous') || '';
                }
            })
            .catch(error => {
                hideLoading(); selectElement.disabled = false;
                showNotification('danger', 'Network error. Please try again.');
                selectElement.value = selectElement.getAttribute('data-previous') || '';
            });
        }
      
    </script>
</body>
</html>