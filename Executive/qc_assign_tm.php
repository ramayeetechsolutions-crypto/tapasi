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

// --- AUTO FIX DB SCHEMA (For missing time columns) ---
$check_columns = [
    'qc_start_time' => "TIME NULL DEFAULT NULL",
    'qc_end_time' => "TIME NULL DEFAULT NULL",
    'priority' => "VARCHAR(50) NULL DEFAULT NULL",
    'qc_fqa_status' => "VARCHAR(100) DEFAULT 'Need to Assign'",
    'assigned_to_qc_fqa' => "VARCHAR(100) NULL DEFAULT NULL",
    'qc_fqa_start_time' => "TIME NULL DEFAULT NULL",
    'qc_fqa_end_time' => "TIME NULL DEFAULT NULL"
];
foreach ($check_columns as $col => $definition) {
    $check = $mysqli->query("SHOW COLUMNS FROM qc LIKE '$col'");
    if ($check && $check->num_rows == 0) {
        $mysqli->query("ALTER TABLE qc ADD COLUMN $col $definition");
    }
}

// Fetch all active employees for Employee filter
$all_employees = [];
$emp_filter_res = $mysqli->query("SELECT employee_username, employee_name FROM employee_master WHERE status = 'Active' ORDER BY employee_name ASC");
if ($emp_filter_res) {
    while ($e_row = $emp_filter_res->fetch_assoc()) {
        $all_employees[] = $e_row;
    }
}

// Only TS/QC team members for filter section dropdowns
$ts_filter_tms = [];
$res_ts_filter = $mysqli->query("SELECT em.employee_id, em.employee_username, em.employee_name 
                                FROM employee_master em 
                                WHERE (em.reporting_manager LIKE '%Bragadeeshwaran%' OR em.reporting_manager = 'T.G. Bragadeeshwaran') 
                                  AND em.status = 'Active' 
                                  AND (
                                      FIND_IN_SET('3', em.process) 
                                      OR FIND_IN_SET('20', em.process) 
                                      OR FIND_IN_SET('68', em.process) 
                                      OR FIND_IN_SET('67', em.process) 
                                      OR FIND_IN_SET('9', em.process) 
                                      OR em.process = 'TS' 
                                      OR em.team_id = 5
                                  ) 
                                  AND em.employee_username != 'TPS026' 
                                ORDER BY em.employee_name ASC");
if ($res_ts_filter) {
    while ($ts_row = $res_ts_filter->fetch_assoc()) {
        $ts_filter_tms[] = $ts_row;
    }
}

// --- START: Status Update AJAX ---
if (isset($_POST['ajax']) && $_POST['ajax'] == 'update_status') {
    header('Content-Type: application/json');
    $qc_id = (int)$_POST['qc_id'];
    $type = $_POST['type'] ?? 'qc';
    $new_status = trim($_POST['status']);

    if ($type === 'qc_fqa') {
        $stmt = $mysqli->prepare("UPDATE qc SET qc_fqa_status = ?, last_updated = NOW() WHERE id = ?");
        $stmt->bind_param("si", $new_status, $qc_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'status' => $new_status]);
        } else {
            echo json_encode(['success' => false, 'message' => $mysqli->error]);
        }
        exit;
    }

    // Fetch current times
    $get_stat = $mysqli->prepare("SELECT job_id, assigned_to_qc, qc_start_time, qc_end_time FROM qc WHERE id = ?");
    $get_stat->bind_param("i", $qc_id);
    $get_stat->execute();
    $curr_data = $get_stat->get_result()->fetch_assoc();
    $get_stat->close();

    // Mandatory Time Validation
    if ($new_status === 'Completed') {
        if (empty($curr_data['qc_start_time']) || empty($curr_data['qc_end_time'])) {
            echo json_encode(['success' => false, 'message' => 'Please fill both QC Start and End Time before marking as Completed.']);
            exit;
        }
    }

    $update_query = ($new_status === 'Completed') 
    ? "UPDATE qc SET status = ?, qc_date = NOW(), last_updated = NOW() WHERE id = ?"
    : "UPDATE qc SET status = ?, last_updated = NOW() WHERE id = ?";
        $stmt = $mysqli->prepare($update_query);
        $stmt->bind_param("si", $new_status, $qc_id);
    
    if ($stmt->execute()) {
        // Do not automatically sync to qc_crx on Completed - only sync when Move button is clicked
        echo json_encode(['success' => true, 'status' => $new_status]);
    } else {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
    }
    exit;
}

// --- START: Move to QC CRX AJAX ---
if (isset($_POST['ajax']) && $_POST['ajax'] == 'move_to_qc_crx') {
    header('Content-Type: application/json');
    $qc_id = (int)$_POST['qc_id'];
    $job_id = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
    
    $q_res = $mysqli->query("SELECT * FROM qc WHERE id = $qc_id");
    if ($q_row = $q_res->fetch_assoc()) {
        $jid = (int)($job_id > 0 ? $job_id : $q_row['job_id']);
        
        // Sync / insert into qc_crx
        $chk_crx = $mysqli->query("SELECT id FROM qc_crx WHERE job_id = $jid");
        if ($chk_crx && $chk_crx->num_rows > 0) {
            $stmt_crx = $mysqli->prepare("UPDATE qc_crx SET status = 'Completed', assigned_to_qc = ?, qc_start_time = ?, qc_end_time = ?, qc_date = NOW(), last_updated = NOW() WHERE job_id = ?");
            $stmt_crx->bind_param("sssi", $q_row['assigned_to_qc'], $q_row['qc_start_time'], $q_row['qc_end_time'], $jid);
            $stmt_crx->execute();
            $stmt_crx->close();
        } else {
            $stmt_crx = $mysqli->prepare("INSERT INTO qc_crx (job_id, status, assigned_to_qc, qc_start_time, qc_end_time, qc_date, created_at, last_updated) VALUES (?, 'Completed', ?, ?, ?, NOW(), NOW(), NOW())");
            $stmt_crx->bind_param("isss", $jid, $q_row['assigned_to_qc'], $q_row['qc_start_time'], $q_row['qc_end_time']);
            $stmt_crx->execute();
            $stmt_crx->close();
        }
        
        // Mark qc as moved
        $mysqli->query("UPDATE qc SET is_moved = 1, last_updated = NOW() WHERE id = $qc_id");
        if ($jid > 0) {
            $mysqli->query("UPDATE qc SET is_moved = 1, last_updated = NOW() WHERE job_id = $jid");
        }
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'QC record not found.']);
    }
    exit;
}

// --- START: Inline Edit AJAX (For Time) ---
if (isset($_POST['ajax']) && $_POST['ajax'] == 'inline_edit') {
    header('Content-Type: application/json');
    $qc_id = (int)$_POST['qc_id'];
    $field = $_POST['field'];
    $val = trim($_POST['value']);
    
    $allowed_fields = ['qc_start_time', 'qc_end_time', 'dispatch_date', 'upload_by', 'path', 'priority', 'qc_fqa_status', 'assigned_to_qc_fqa', 'qc_fqa_start_time', 'qc_fqa_end_time'];
    if (in_array($field, $allowed_fields)) {
        if ($field === 'assigned_to_qc_fqa' && !$is_tl_or_subtl && $val !== '') {
            $chk_stmt = $mysqli->prepare("SELECT assigned_to_qc_fqa FROM qc WHERE id = ?");
            $chk_stmt->bind_param("i", $qc_id);
            $chk_stmt->execute();
            $chk_res = $chk_stmt->get_result()->fetch_assoc();
            $chk_stmt->close();
            
            $existing_val = $chk_res['assigned_to_qc_fqa'] ?? '';
            if (!empty($existing_val)) {
                echo json_encode(['success' => false, 'message' => 'Only SubTL and Team Lead have access to reassign members.']);
                exit;
            }
            if ($val !== $current_username) {
                echo json_encode(['success' => false, 'message' => 'Only SubTL and Team Lead have access to assign to other members.']);
                exit;
            }
        }
        if ($val === '') {
            $stmt = $mysqli->prepare("UPDATE qc SET $field = NULL, last_updated = NOW() WHERE id = ?");
            $stmt->bind_param("i", $qc_id);
        } else {
            $stmt = $mysqli->prepare("UPDATE qc SET $field = ?, last_updated = NOW() WHERE id = ?");
            $stmt->bind_param("si", $val, $qc_id);
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

if (!function_exists('getWorkingDaysWithoutSunday')) {
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

}
if (!function_exists('formatDate')) {
function formatDate($date, $format = 'd-m-Y') {
    if (!$date) return '';
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    return $dateObj ? $dateObj->format($format) : $date;
}
}

// Get current user info
$current_user_id = $_SESSION['uid'] ?? 0;
$current_username = $_SESSION['username'] ?? '';
$role_name = $_SESSION['role_name'] ?? '';

// Check if current user is Team Lead or SubTL (Managers can reassign & assign others)
$current_folder = basename(__DIR__);
$is_tl_or_subtl = false;
if ($current_folder === 'Team_Lead' || $current_folder === 'SubTL') {
    $is_tl_or_subtl = true;
    if (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'TM') {
        $is_tl_or_subtl = false;
    }
}

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

// Employee map for name display
$emp_map = [];
$emp_res = $mysqli->query("SELECT employee_username, employee_name FROM employee_master");
if ($emp_res) {
    while ($e = $emp_res->fetch_assoc()) {
        $emp_map[$e['employee_username']] = $e['employee_name'];
    }
}

// Determine effective reporting manager (if user is TL/Admin without RM, use their own name)
$effective_manager = !empty($reporting_manager) ? $reporting_manager : $current_user_name;

// Get all employees under the same reporting manager with TM role
$tms = [];
if ($effective_manager) {
    $tm_query = "SELECT em.employee_id, em.employee_username, em.employee_name, em.user_role_id
                 FROM employee_master em 
                 LEFT JOIN user_role_master ur ON em.user_role_id = ur.role_id
                 WHERE ((em.reporting_manager = ? OR em.reporting_manager = ?) AND em.status = 'Active' AND ur.role_name IN ('TM', 'TL', 'Admin'))
                 OR (em.employee_name LIKE '%Manjuparkavi%' AND em.status = 'Active')
                 ORDER BY em.employee_name";
    
    $stmt = $mysqli->prepare($tm_query);
    if ($stmt) {
        $stmt->bind_param("ss", $effective_manager, $current_user_name);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($tm = $result->fetch_assoc()) {
            $tms[] = $tm;
        }
        $stmt->close();
    }
}

// Handle AJAX quick assignment
if (isset($_POST['ajax']) && $_POST['ajax'] == 'quick_assign') {
    header('Content-Type: application/json');
    
    $qc_id = (int)$_POST['qc_id'];
    $job_id = (int)$_POST['job_id'];
    $type = $_POST['type'] ?? 'qc'; // 'qc' or 'qc_fqa'
    $assigned_to = trim($_POST['assigned_to']);
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        if (empty($assigned_to)) {
            throw new Exception('Please select a TM');
        }
        
        $field = ($type === 'qc_fqa') ? 'assigned_to_qc_fqa' : 'assigned_to_qc';
        $status_col = ($type === 'qc_fqa') ? 'qc_fqa_status' : 'status';
        $new_substatus = ($type === 'qc_fqa') ? 'FQA in progress' : 'In Progress';

        // Permission check: Executives can only self-assign to unassigned jobs. Only SubTL/TL can assign others or reassign.
        if (!$is_tl_or_subtl) {
            $chk_stmt = $mysqli->prepare("SELECT $field FROM qc WHERE id = ?");
            $chk_stmt->bind_param("i", $qc_id);
            $chk_stmt->execute();
            $chk_res = $chk_stmt->get_result()->fetch_assoc();
            $chk_stmt->close();
            
            $existing_assigned = $chk_res[$field] ?? '';
            
            if (!empty($existing_assigned)) {
                throw new Exception('Only SubTL and Team Lead have access to reassign members.');
            }
            if ($assigned_to !== $current_username) {
                throw new Exception('Only SubTL and Team Lead have access to assign to other members.');
            }
        }
        
        // Start transaction
        $mysqli->begin_transaction();
        
        // First, check if the QC record exists
        $check_sql = "SELECT id, status FROM qc WHERE id = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("i", $qc_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows == 0) {
            throw new Exception('QC record not found');
        }
        $check_stmt->close();
        
        // Update qc table
        $update_qc = "UPDATE qc SET 
                      $field = ?,
                      $status_col = '$new_substatus',
                      last_updated = NOW()
                      WHERE id = ?";
        
        $stmt = $mysqli->prepare($update_qc);
        if (!$stmt) {
            throw new Exception('Error preparing QC update: ' . $mysqli->error);
        }
        
        $stmt->bind_param("si", $assigned_to, $qc_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Error updating Assignment: ' . $stmt->error);
        }
        
        $stmt->close();
        $mysqli->commit();
        
        $response['success'] = true;
        $response['message'] = 'Assigned successfully';
        
        // Get TM name for response
        foreach ($tms as $tm) {
            if ($tm['employee_username'] == $assigned_to) {
                $response['tm_name'] = $tm['employee_name'];
                $response['tm_username'] = $tm['employee_username'];
                break;
            }
        }
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$employee_filter = isset($_GET['employee']) ? trim($_GET['employee']) : '';
$qc_tm_filter = isset($_GET['qc_tm']) ? trim($_GET['qc_tm']) : '';
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'qc.created_at';
$dir = isset($_GET['dir']) && strtoupper($_GET['dir']) == 'ASC' ? 'ASC' : 'DESC';

// Build the query for QC records
// 1. QC Status is not Completed/Done
// 2. Process must involve PAG or LE or TS
// 3. THE CRITICAL CONDITION: PAG phase OR LE phase must be 'Completed'
$where_conditions = [
    "COALESCE(qc.is_moved, 0) = 0", 
    "qc.status != 'Done'",
    "p.job_name IN ('PAG', 'PAG+XML', 'CE+PAG', 'CE+PAG+XML', 'CE+TS', 'CE+TS+XML', 'TS', 'TS+XML', 'LE', 'CE+LE', 'CE+LE+XML', 'CE+PAG+LE')",
    "(
        ((p.job_name LIKE '%PAG%' OR p.job_name LIKE '%TS%') AND EXISTS (SELECT 1 FROM pag WHERE pag.job_id = qc.job_id AND (pag.status = 'Completed' OR pag.status = 'Done')))
        OR 
        (p.job_name NOT LIKE '%PAG%' AND p.job_name NOT LIKE '%TS%' AND EXISTS (SELECT 1 FROM le WHERE le.job_id = qc.job_id AND le.status IN ('Completed', 'Done', 'LE-QC Done', 'LE & LE-QC Done')))
    )"
];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(c.client_name LIKE ? OR c.team_client_name LIKE ? OR qc.journal_issue LIKE ? OR qc.article LIKE ? OR qc.process LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sssss';
}

if (!empty($status_filter)) {
    $where_conditions[] = "qc.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($qc_tm_filter)) {
    $where_conditions[] = "qc.assigned_to_qc = ?";
    $params[] = $qc_tm_filter;
    $types .= 's';
}

if (!empty($employee_filter)) {
    // Strictly filter only employees visibly displayed on screen
    $where_conditions[] = "(
        qc.assigned_to_qc = ? 
        OR (COALESCE(NULLIF(pag.assigned_to_pag, ''), NULLIF(j.assigned_pag, ''), NULLIF(pag.done_by, '')) COLLATE utf8mb4_general_ci) = ? 
        OR (COALESCE(NULLIF(le.assigned_to_le_qc, ''), NULLIF(le.assigned_to_le, ''), NULLIF(j.assigned_le, ''), NULLIF(le.done_by, '')) COLLATE utf8mb4_general_ci) = ? 
        OR (COALESCE(NULLIF(ce.assigned_to_content, ''), NULLIF(j.assigned_ce, ''), NULLIF(ce.done_by, '')) COLLATE utf8mb4_general_ci) = ?
        OR qc.upload_by = ?
    )";
    for ($k = 0; $k < 5; $k++) {
        $params[] = $employee_filter;
    }
    $types .= 'sssss';
}

$where_sql = implode(' AND ', $where_conditions);

// Main query (Updated to fetch CE, LE, PAG columns for new table structure)
$qc_query = "SELECT qc.*, 
                    j.job_date as date_received,
                    j.journal_issue as jobs_issue, 
                    j.article as jobs_article,
                    j.process_id,
                    j.due_date as job_due_date,
                    j.dispatch_date as job_dispatch_date,
                    j.file_path as job_file_path,
                    c.client_name,
                    c.team_client_name,
                    c.client_id,
                    p.job_name as process_name,
                    j.assigned_ce,
                    j.assigned_le,
                    j.assigned_pag,
                    ce.ce_date as ce_moved_date,
                    ce.status as ce_status,
                    ce.done_by as ce_table_done_by,
                    ce.assigned_to_content as ce_assigned_to,
                    le.status as le_status,
                    le.last_updated as le_moved_date,
                    le.done_by as le_table_done_by,
                    le.assigned_to_le as le_assigned_to,
                    le.assigned_to_le_qc as le_qc_assigned_to,
                    pag.created_at as pag_received_date,
                    pag.done_by as pag_table_done_by,
                    pag.assigned_to_pag as pag_assigned_to,
                    pag.eq, pag.pages, pag.images, pag.tables_count, pag.table_status, pag.assigned_to_table, pag.assigned_to_table_fqa, pag.status as pag_status,
                    qc.rtat, qc.atat,
                    (SELECT upload_by FROM job_uploads WHERE job_id = j.job_id ORDER BY id DESC LIMIT 1) as job_upload_by,
                    (SELECT upload_date FROM job_uploads WHERE job_id = j.job_id ORDER BY id DESC LIMIT 1) as upload_date,
                    DATEDIFF(j.due_date, CURDATE()) as days_remaining
             FROM qc 
             LEFT JOIN jobs j ON qc.job_id = j.job_id
             LEFT JOIN client_master c ON j.client_id = c.client_id
             LEFT JOIN job_master p ON j.process_id = p.job_id
             LEFT JOIN ce ON j.job_id = ce.job_id
             LEFT JOIN le ON j.job_id = le.job_id
             LEFT JOIN pag ON j.job_id = pag.job_id
             WHERE $where_sql
             ORDER BY $sort $dir";

$stmt = $mysqli->prepare($qc_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
if (!$stmt) {
    die("Query Error: " . $mysqli->error);
}
$stmt->execute();
$qc_result = $stmt->get_result();
$stmt->close();

// Get unique statuses for filter dropdown
$status_query = "SELECT DISTINCT status FROM qc WHERE COALESCE(is_moved, 0) = 0 AND status != 'Done' ORDER BY status";
$status_result = $mysqli->query($status_query);

// Get count statistics (Using same conditions for accuracy)
$stats_query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN qc.status = 'Need to Assign' THEN 1 ELSE 0 END) as need_assign,
                    SUM(CASE WHEN qc.status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN qc.assigned_to_qc IS NOT NULL AND qc.assigned_to_qc != '' THEN 1 ELSE 0 END) as qc_assigned
                FROM qc 
                LEFT JOIN jobs j ON qc.job_id = j.job_id
                LEFT JOIN client_master c ON j.client_id = c.client_id
                LEFT JOIN job_master p ON j.process_id = p.job_id
                LEFT JOIN ce ON j.job_id = ce.job_id
                LEFT JOIN le ON j.job_id = le.job_id
                LEFT JOIN pag ON j.job_id = pag.job_id
                WHERE $where_sql";

$stats_stmt = $mysqli->prepare($stats_query);
if (!empty($params)) {
    $stats_stmt->bind_param($types, ...$params);
}
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();
$stats_stmt->close();

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TM Assignment Grid - QC Jobs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
        body { background-color: #f8fafc; }
        .page-header { background: white; border-bottom: 1px solid #e2e8f0; padding: 1rem 0; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .stats-card { border-radius: 12px; padding: 1rem; display: flex; align-items: center; gap: 1rem; border: 1px solid rgba(0,0,0,0.05); box-shadow: 0 2px 4px rgba(0,0,0,0.02); transition: transform 0.2s; }
        .stats-card:hover { transform: translateY(-2px); }
        .stats-card.card-total { background: #e0f2fe; border-color: #bae6fd; }
        .stats-card.card-need { background: #fef3c7; border-color: #fde68a; }
        .stats-card.card-progress { background: #dbeafe; border-color: #bfdbfe; }
        .stats-card.card-qc { background: #dcfce7; border-color: #bbf7d0; }
        .stats-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; background: rgba(255,255,255,0.6); }
        .stats-icon.total { color: #0369a1; }
        .stats-icon.need { color: #92400e; }
        .stats-icon.progress { color: #1e40af; }
        .stats-icon.qc { color: #166534; }
        .stats-info h3 { font-size: 1.5rem; font-weight: 700; margin: 0; line-height: 1.2; }
        .stats-info p { margin: 0; color: #64748b; font-size: 0.8rem; font-weight: 500; }
        .filter-section { background: white; border-radius: 12px; padding: 1rem; margin-bottom: 1.5rem; border: 1px solid #e2e8f0; }
        .table-container { background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .table { margin-bottom: 0; }
        /* Grid Table Styles - Matching xml/xml_unique_start.php (Single 1px #dee2e6 lines) */
        .table {
            border-collapse: separate !important;
            border-spacing: 0 !important;
        }
        .table th,
        .table thead th,
        .table-sticky-assign thead th {
            background-color: #5e6b77 !important; 
            color: #ffffff !important;
            border-top: 1px solid #dee2e6 !important;
            border-bottom: 1px solid #dee2e6 !important;
            border-right: 1px solid #dee2e6 !important;
            border-left: none !important;
            font-weight: 600;
            font-size: 11px !important;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            padding: 6px 8px !important;
            white-space: nowrap;
            text-align: center;
            vertical-align: middle;
            box-shadow: none !important;
        }
        .table th:first-child,
        .table thead th:first-child,
        .table-sticky-assign thead th:first-child {
            border-left: 1px solid #dee2e6 !important;
        }
        
        .table td,
        .table tbody td,
        .table-sticky-assign tbody td {
            vertical-align: middle !important;
            padding: 4px 6px !important;
            font-size: 10px !important;
            color: #334155 !important;
            border-top: none !important;
            border-bottom: 1px solid #dee2e6 !important;
            border-right: 1px solid #dee2e6 !important;
            border-left: none !important;
            text-align: center;
            white-space: nowrap;
            line-height: 1.25 !important;
        }
        .table td:first-child,
        .table tbody td:first-child,
        .table-sticky-assign tbody td:first-child {
            border-left: 1px solid #dee2e6 !important;
        }

        .table tbody td *,
        .table-sticky-assign tbody td * {
            font-size: 10px !important;
        }

        .table tbody td small,
        .table-sticky-assign tbody td small {
            font-size: 9px !important;
            line-height: 1.1 !important;
        }

        .table .badge,
        .table-sticky-assign .badge {
            font-size: 10px !important;
            padding: 2px 6px !important;
            font-weight: 600 !important;
            border-radius: 4px !important;
        }

        .table th.sticky-assign-col-last,
        .table thead th.sticky-assign-col-last,
        .table td.sticky-assign-col-last,
        .table tbody td.sticky-assign-col-last,
        .sticky-assign-col-last {
            border-right: 1px solid #dee2e6 !important;
        }
        
        .table> :not(caption)>*>*,
        .table-sticky-assign> :not(caption)>*>*,
        .table-striped>tbody>tr:nth-of-type(odd)>*,
        .table-striped>tbody>tr:nth-of-type(even)>* {
            --bs-table-accent-bg: transparent !important;
            box-shadow: none !important;
        }

        /* Visible Zebra Striping matching xml/xml_unique_start.php (White and Light Ash) */
        .table tbody tr:nth-child(even) td,
        .table-sticky-assign tbody tr:nth-child(even) td {
            background-color: #f2f4f6 !important;
        }
        
        .table tbody tr:nth-child(odd) td,
        .table-sticky-assign tbody tr:nth-child(odd) td {
            background-color: #ffffff !important;
        }
        
        /* Full Row Hover Effect matching xml/xml_unique_start.php */
        .table tbody tr:hover td,
        .table-sticky-assign tbody tr:hover td {
            background-color: #c4cbd3 !important;
            cursor: pointer;
            transition: background-color 0.1s ease;
        }
        
        .table tbody tr:hover td.sticky-assign-col,
        .table tbody tr:hover td.sticky-assign-cell,
        .table-sticky-assign tbody tr:hover td.sticky-assign-col,
        .table-sticky-assign tbody tr:hover td.sticky-assign-cell {
            background-color: #e9ecef !important;
        }

        .table tbody tr:hover td small,
        .table tbody tr:hover td small i,
        .table-sticky-assign tbody tr:hover td small,
        .table-sticky-assign tbody tr:hover td small i {
            color: #198754 !important;
        }
        .status-badge { display: inline-block; padding: 0.2rem 0.4rem; border-radius: 9999px; font-size: 0.65rem; font-weight: 500; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-progress { background: #dbeafe; color: #1e40af; }
        .tm-badge { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.2rem 0.4rem; border-radius: 4px; font-size: 0.7rem; background: #f1f5f9; color: #475569; white-space: nowrap; }
        .tm-badge.qc { background: #fce7f3; color: #be185d; }
        .tm-badge.in-progress { background-color: #f3e8ff !important; color: #7e22ce !important; border: 1px solid #d8b4fe !important; font-weight: 600; }
        .tm-badge.completed { background-color: #dcfce7 !important; color: #15803d !important; border: 1px solid #86efac !important; font-weight: 600; }
        .tm-badge.pending { background-color: #f1f5f9; color: #64748b; }
        .assign-select { font-size: 0.7rem; padding: 0.2rem 0.4rem; height: auto; width: 100%; border-radius: 4px; border: 1.5px solid #e2e8f0; }
        .assign-select:focus { border-color: #667eea; outline: none; }
        .text-truncate { max-width: 120px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .empty-state { text-align: center; padding: 3rem; }
        .empty-state i { font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem; }
        .due-date {
            font-size: 11px !important;
            font-weight: 600 !important;
            padding: 2px 7px !important;
            border-radius: 4px !important;
            letter-spacing: 0.01em !important;
            box-shadow: none !important;
            border: 1px solid transparent !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 4px !important;
            line-height: 1.3 !important;
            white-space: nowrap !important;
        }
        
        .due-date.urgent,
        .due-date.warning {
            background-color: #fee2e2 !important;
            color: #dc2626 !important;
        }
        
        .due-date.normal {
            background-color: #dcfce7 !important;
            color: #15803d !important;
        }
        .assignment-cell { min-width: 160px; }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.8); display: none; justify-content: center; align-items: center; z-index: 9999; }
        .loading-overlay.active { display: flex; }
        .loading-spinner { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); text-align: center; }
        .notification { position: fixed; top: 20px; right: 20px; z-index: 999999; min-width: 300px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
    </style>
</head>
<body>
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
    <div id="notificationContainer" style="position: fixed; top: 20px; right: 20px; z-index: 999999;"></div>

    <div class="page-header" style="background-color: #6c757d !important; background: #6c757d !important; color: #ffffff !important; border-radius: 12px !important; padding: 12px 20px !important; margin-top: 0 !important; margin-bottom: 20px !important;">
        <div class="container-fluid px-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0 text-white" style="font-weight: 700; color: #ffffff !important; font-size: 1.25rem !important;">
                        <i class="fas fa-table me-2" style="color: #ffffff !important;"></i>
                        QC Assign
                    </h4>
                    <!-- <p class="mb-0 text-muted small">
                        Assign QC tasks to team members under <?= h($reporting_manager) ?>. 
                        <em>(Jobs appear here ONLY after the PAG phase is 100% completed)</em>
                    </p> -->
                </div>
                <div>
                    <a href="jobs.php" class="btn btn-outline-light text-white btn-sm me-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Jobs
                    </a>
                    <span class="badge bg-primary">
                        <i class="fas fa-users me-1"></i> <?= count($tms) ?> Team Members
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid px-4">

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stats-card card-total">
                    <div class="stats-icon total">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stats-info">
                        <h3><?= $stats['total'] ?? 0 ?></h3>
                        <p>Total Ready for QC</p>
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
                <div class="stats-card card-qc">
                    <div class="stats-icon qc">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stats-info">
                        <h3><?= $stats['qc_assigned'] ?? 0 ?></h3>
                        <p>Currently Assigned</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-3">
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
                    <label class="form-label small">Employee</label>
                    <select name="employee" class="form-select form-select-sm">
                        <option value="">All Employees</option>
                        <?php foreach ($ts_filter_tms as $emp): ?>
                            <option value="<?= h($emp['employee_username']) ?>" <?= $employee_filter == $emp['employee_username'] ? 'selected' : '' ?>>
                                <?= h($emp['employee_name']) ?> (<?= h($emp['employee_username']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">QC TM</label>
                    <select name="qc_tm" class="form-select form-select-sm">
                        <option value="">All Team Members</option>
                        <?php foreach ($ts_filter_tms as $tm): ?>
                            <option value="<?= h($tm['employee_username']) ?>" 
                                <?= $qc_tm_filter == $tm['employee_username'] ? 'selected' : '' ?>>
                                <?= h($tm['employee_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Sort By</label>
                    <select name="sort" class="form-select form-select-sm">
                        <option value="qc.created_at" <?= $sort == 'qc.created_at' ? 'selected' : '' ?>>Created Date</option>
                        <option value="c.client_name" <?= $sort == 'c.client_name' ? 'selected' : '' ?>>Client Name</option>
                        <option value="qc.status" <?= $sort == 'qc.status' ? 'selected' : '' ?>>Status</option>
                        <option value="j.due_date" <?= $sort == 'j.due_date' ? 'selected' : '' ?>>Due Date</option>
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
                    <a href="?" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-undo me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Main Grid/Table -->
        <div class="table-container">
            <div class="table-responsive">
                <table class="table table-hover table-striped table-bordered align-middle table-sticky-assign" style="min-width: 1200px; border-collapse: separate; border-spacing: 0;">
                    <thead>
                        <tr>
                            <th>LE Moved Dt</th>
                            <th>CE Moved Dt</th>
                            <th>Rec. Date</th>
                            <th>Client</th>
                            <th>Journal</th>
                            <th class="col-article" style="width: 135px; min-width: 135px; max-width: 135px;">Articles</th>
                            <th>EQN</th>
                            <th>Pages</th>
                            <th>Images</th>
                            <th>Tables</th>
                            <th>CE Status</th>
                            <th>LE Status</th>
                            <th>PAG</th>
                            <th style="width: 140px; min-width: 140px;">QC</th>
                            <th style="width: 140px; min-width: 140px;">QC_FQA</th>
                            <th>Due Date</th>
                            <th>R-TAT</th>
                            <th>A-TAT</th>
                            <th>Dispatch Date</th>
                            <th>Upload By</th>
                            <th>Path</th>
                            <th>Priority</th>
                            <th style="min-width: 80px; text-align: center;">Status</th>
                            <th>TAT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($qc_result && $qc_result->num_rows > 0): ?>
                            <?php while ($row = $qc_result->fetch_assoc()): 
                                $has_qc = !empty($row['assigned_to_qc']);
                                $row_class = $has_qc ? 'assigned' : '';
                                $qc_stat = $row['status'] ?? 'Need to Assign';

                                // Due date color coding
                                $days_remaining = $row['days_remaining'] ?? 0;
                                $due_class = '';
                                if ($days_remaining <= 2) {
                                    $due_class = 'urgent';
                                } elseif ($days_remaining < 4) {
                                    $due_class = 'warning';
                                } else {
                                    $due_class = 'normal';
                                }
                            ?>
                                <tr class="<?= $row_class ?>" id="row-<?= $row['id'] ?>">
                                    <!-- Date Fields -->
                                    <td><?= (in_array($row['le_status'], ['Completed', 'Done', 'LE & LE-QC Done', 'LE-QC Done', 'LE Done']) && !empty($row['le_moved_date'])) ? formatDate(substr($row['le_moved_date'], 0, 10)) : '—' ?></td>
                                    <td><?= !empty($row['ce_moved_date']) ? formatDate(substr($row['ce_moved_date'], 0, 10)) : '—' ?></td>
                                    <td><?= formatDate(substr($row['pag_received_date'] ?? '', 0, 10)) ?></td>
                                    
                                    <!-- Job Info -->
                                    <td><span class="fw-500"><?= h(!empty($row['team_client_name']) ? $row['team_client_name'] : ($row['client_name'] ?? 'N/A')) ?></span></td>
                                    <td><?= h($row['journal_issue'] ?? $row['jobs_issue'] ?? 'N/A') ?></td>
                                    <td class="col-article" style="width: 135px; min-width: 135px; max-width: 135px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= h($row['article'] ?? $row['jobs_article'] ?? '') ?>"><?= h($row['article'] ?? $row['jobs_article'] ?? 'N/A') ?></td>
                                    
                                    <!-- Counts -->
                                    <td><?= h($row['eq'] ?? '0') ?></td>
                                    <td><?= h($row['pages'] ?? '0') ?></td>
                                    <td><?= h($row['images'] ?? '0') ?></td>
                                    <!-- Tables Column with Status and Member -->
                                    <?php 
                                        $tbl_cnt = !empty($row['tables_count']) ? $row['tables_count'] : '0';
                                        $tbl_stat = !empty($row['table_status']) ? $row['table_status'] : ($tbl_cnt > 0 ? 'Completed' : 'Pending');
                                        $tbl_done = in_array($tbl_stat, ['Completed', 'Done']);
                                        $tbl_cls = $tbl_done ? 'bg-success' : 'bg-secondary';
                                        $tbl_emp = !empty($row['assigned_to_table']) ? $row['assigned_to_table'] : (!empty($row['assigned_to_table_fqa']) ? $row['assigned_to_table_fqa'] : '');
                                    ?>
                                    <td>
                                        <span class="badge <?= $tbl_cls ?>"><?= h($tbl_stat) ?></span>
                                        <?php if (!empty($tbl_emp)): 
                                            $tbl_name = $emp_map[$tbl_emp] ?? '';
                                            $tbl_display = $tbl_name ? h($tbl_name) . " (" . h($tbl_emp) . ")" : h($tbl_emp);
                                        ?>
                                            <div class="mt-1" style="font-size: 10px; color: #15803d; font-weight: 600; white-space: nowrap;">
                                                <i class="fas fa-user"></i> <?= $tbl_display ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Other Statuses -->
                                    <?php 
                                        $ce_done = in_array($row['ce_status'] ?? '', ['Completed', 'Done']);
                                        $ce_cls = $ce_done ? 'bg-success' : 'bg-secondary';
                                        $ce_emp = !empty($row['ce_assigned_to']) ? $row['ce_assigned_to'] : (!empty($row['assigned_ce']) ? $row['assigned_ce'] : ($row['ce_done_by'] ?? ''));
                                    ?>
                                    <td>
                                        <span class="badge <?= $ce_cls ?>"><?= h($row['ce_status'] ?? 'Pending') ?></span>
                                        <?php if (!empty($ce_emp)): 
                                            $ce_name = $emp_map[$ce_emp] ?? '';
                                            $ce_display = $ce_name ? h($ce_name) . " (" . h($ce_emp) . ")" : h($ce_emp);
                                        ?>
                                            <div class="mt-1" style="font-size: 10px; color: #15803d; font-weight: 600; white-space: nowrap;">
                                                <i class="fas fa-user"></i> <?= $ce_display ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <?php 
                                        $le_done = in_array($row['le_status'] ?? '', ['Completed', 'Done', 'LE Done', 'LE-QC Done', 'LE & LE-QC Done']);
                                        $le_cls = $le_done ? 'bg-success' : 'bg-secondary';
                                        $le_emp = !empty($row['le_qc_assigned_to']) ? $row['le_qc_assigned_to'] : (!empty($row['le_assigned_to']) ? $row['le_assigned_to'] : (!empty($row['assigned_le']) ? $row['assigned_le'] : ($row['le_done_by'] ?? '')));
                                    ?>
                                    <td>
                                        <span class="badge <?= $le_cls ?>"><?= h($row['le_status'] ?? 'Pending') ?></span>
                                        <?php if (!empty($le_emp)): 
                                            $le_name = $emp_map[$le_emp] ?? '';
                                            $le_display = $le_name ? h($le_name) . " (" . h($le_emp) . ")" : h($le_emp);
                                        ?>
                                            <div class="mt-1" style="font-size: 10px; color: #15803d; font-weight: 600; white-space: nowrap;">
                                                <i class="fas fa-user"></i> <?= $le_display ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <?php 
                                        $pag_done = in_array($row['pag_status'] ?? '', ['Completed', 'Done']);
                                        $pag_cls = $pag_done ? 'bg-success' : 'bg-info';
                                        $pag_emp = !empty($row['pag_assigned_to']) ? $row['pag_assigned_to'] : (!empty($row['assigned_pag']) ? $row['assigned_pag'] : ($row['pag_done_by'] ?? ''));
                                    ?>
                                    <td>
                                        <span class="badge <?= $pag_cls ?>"><?= h($row['pag_status'] ?? 'Pending') ?></span>
                                        <?php if (!empty($pag_emp)): 
                                            $pag_name = $emp_map[$pag_emp] ?? '';
                                            $pag_display = $pag_name ? h($pag_name) . " (" . h($pag_emp) . ")" : h($pag_emp);
                                        ?>
                                            <div class="mt-1" style="font-size: 10px; color: #15803d; font-weight: 600; white-space: nowrap;">
                                                <i class="fas fa-user"></i> <?= $pag_display ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- QC Assignment Column -->
                                    <?php 
                                        $qc_done = in_array($qc_stat, ['Completed']);
                                        $qc_inprogress = (!$qc_done && !empty($row['assigned_to_qc']));
                                        $qc_curr_status = 'Need to Assign';
                                        $qc_badge_cls = 'pending';
                                        
                                        if ($qc_done) { 
                                            $qc_curr_status = 'Completed'; 
                                            $qc_badge_cls = 'completed'; 
                                        } elseif ($qc_inprogress) { 
                                            $qc_curr_status = 'In Progress'; 
                                            $qc_badge_cls = 'in-progress'; 
                                        }
                                    ?>
                                    <td class="assignment-cell" style="width: 140px; min-width: 140px; padding: 4px 6px; vertical-align: top;">
                                        <div class="mb-1">
                                            <select class="form-select form-select-sm p-0 px-1" style="font-size: 11px; width: 100%; height: 22px; border-color: #ccc; background-color: #f9f9f9;" onchange="updateStatus(<?= $row['id'] ?>, this.value)">
                                                <option value="Need to Assign" <?= ($qc_curr_status == 'Need to Assign') ? 'selected' : '' ?>>Need to Assign</option>
                                                <option value="In Progress" <?= ($qc_curr_status == 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                                                <option value="Completed" <?= ($qc_curr_status == 'Completed') ? 'selected' : '' ?>>Completed</option>
                                            </select>
                                        </div>

                                        <?php if (!empty($row['assigned_to_qc'])): ?>
                                            <div class="tm-badge <?= $qc_badge_cls ?> mb-1" title="Assigned for QC">
                                                <i class="fas fa-search"></i> <?= h($row['assigned_to_qc']) ?>
                                            </div>
                                            <small class="d-block mb-1" style="color: #198754 !important; font-weight: 600;">
                                                <i class="fas fa-user" style="color: #198754 !important;"></i>
                                                <?php 
                                                $found_name = '';
                                                foreach ($tms as $tm) { if ($tm['employee_username'] == $row['assigned_to_qc']) { $found_name = $tm['employee_name']; break; } }
                                                echo h($found_name ?: $row['assigned_to_qc']);
                                                ?>
                                            </small>
                                        <?php endif; ?>

                                        <select class="assign-select mt-1 qc-select-<?= $row['id'] ?>" 
                                                data-assigned="<?= h($row['assigned_to_qc'] ?? '') ?>"
                                                data-previous="<?= h($row['assigned_to_qc'] ?? '') ?>"
                                                onchange="quickAssign(<?= $row['id'] ?>, <?= $row['job_id'] ?>, this)">
                                            <option value=""><?= !empty($row['assigned_to_qc']) ? 'Change QC TM' : 'Assign LE-QC' ?></option>
                                            <?php foreach ($ts_filter_tms as $tm): ?>
                                                <option value="<?= h($tm['employee_username']) ?>" data-name="<?= h($tm['employee_name']) ?>" <?= ($row['assigned_to_qc'] == $tm['employee_username']) ? 'selected' : '' ?>>
                                                    <?= h($tm['employee_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        
                                        <div class="d-flex gap-1 mt-2">
                                            <input type="text" readonly placeholder="--:--" class="form-control form-control-sm p-1 text-center" style="font-size:10px; width:50%; cursor:pointer; font-weight:600;" title="Click to fill current time" value="<?= !empty($row['qc_start_time']) ? date('H:i', strtotime($row['qc_start_time'])) : '' ?>" onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'qc_start_time', t, this);">
                                            <input type="text" readonly placeholder="--:--" class="form-control form-control-sm p-1 text-center" style="font-size:10px; width:50%; cursor:pointer; font-weight:600;" title="Click to fill current time" value="<?= !empty($row['qc_end_time']) ? date('H:i', strtotime($row['qc_end_time'])) : '' ?>" onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'qc_end_time', t, this);">
                                        </div>
                                    </td>

                                    <!-- QC_FQA Assignment Column -->
                                    <?php 
                                        $qc_fqa_stat = $row['qc_fqa_status'] ?? 'Need to Assign';
                                        $qc_fqa_done = in_array($qc_fqa_stat, ['FQA completed', 'FQA Completed', 'Completed']);
                                        $qc_fqa_inprogress = (!$qc_fqa_done && !empty($row['assigned_to_qc_fqa']));
                                        $qc_fqa_badge_cls = 'pending';
                                        
                                        if ($qc_fqa_done) { 
                                            $qc_fqa_badge_cls = 'completed'; 
                                        } elseif ($qc_fqa_inprogress) { 
                                            $qc_fqa_badge_cls = 'in-progress'; 
                                        }
                                    ?>
                                    <td class="assignment-cell" style="width: 140px; min-width: 140px; padding: 4px 6px; vertical-align: top;">
                                        <div class="mb-1">
                                            <select class="form-select form-select-sm p-0 px-1" style="font-size: 11px; width: 100%; height: 22px; border-color: #ccc; background-color: #f9f9f9;" onchange="updateStatus(<?= $row['id'] ?>, this.value, 'qc_fqa')">
                                                <option value="Need to Assign" <?= ($qc_fqa_stat == 'Need to Assign') ? 'selected' : '' ?>>Need to Assign</option>
                                                <option value="FQA in progress" <?= ($qc_fqa_stat == 'FQA in progress' || $qc_fqa_stat == 'FQA In Progress' || $qc_fqa_stat == 'In Progress') ? 'selected' : '' ?>>FQA in progress</option>
                                                <option value="FQA completed" <?= ($qc_fqa_stat == 'FQA completed' || $qc_fqa_stat == 'FQA Completed' || $qc_fqa_stat == 'Completed') ? 'selected' : '' ?>>FQA completed</option>
                                            </select>
                                        </div>

                                        <?php if (!empty($row['assigned_to_qc_fqa'])): ?>
                                            <div class="tm-badge <?= $qc_fqa_badge_cls ?> mb-1" title="Assigned for QC_FQA">
                                                <i class="fas fa-search"></i> <?= h($row['assigned_to_qc_fqa']) ?>
                                            </div>
                                            <small class="d-block mb-1" style="color: #198754 !important; font-weight: 600;">
                                                <i class="fas fa-user" style="color: #198754 !important;"></i>
                                                <?php 
                                                $found_name = '';
                                                foreach ($tms as $tm) { if ($tm['employee_username'] == $row['assigned_to_qc_fqa']) { $found_name = $tm['employee_name']; break; } }
                                                echo h($found_name ?: $row['assigned_to_qc_fqa']);
                                                ?>
                                            </small>
                                        <?php endif; ?>

                                        <select class="assign-select mt-1" 
                                                data-assigned="<?= h($row['assigned_to_qc_fqa'] ?? '') ?>"
                                                data-previous="<?= h($row['assigned_to_qc_fqa'] ?? '') ?>"
                                                onchange="quickAssign(<?= $row['id'] ?>, <?= $row['job_id'] ?>, this, 'qc_fqa')">
                                            <option value=""><?= !empty($row['assigned_to_qc_fqa']) ? 'Change TM' : 'Assign TM' ?></option>
                                            <?php foreach ($ts_filter_tms as $tm): ?>
                                                <option value="<?= h($tm['employee_username']) ?>" data-name="<?= h($tm['employee_name']) ?>" <?= (($row['assigned_to_qc_fqa'] ?? '') == $tm['employee_username']) ? 'selected' : '' ?>>
                                                    <?= h($tm['employee_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        
                                        <div class="d-flex gap-1 mt-2">
                                            <input type="text" readonly placeholder="--:--" class="form-control form-control-sm p-1 text-center" style="font-size:10px; width:50%; cursor:pointer; font-weight:600;" title="Click to fill current time" value="<?= !empty($row['qc_fqa_start_time']) ? date('H:i', strtotime($row['qc_fqa_start_time'])) : '' ?>" onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'qc_fqa_start_time', t, this);">
                                            <input type="text" readonly placeholder="--:--" class="form-control form-control-sm p-1 text-center" style="font-size:10px; width:50%; cursor:pointer; font-weight:600;" title="Click to fill current time" value="<?= !empty($row['qc_fqa_end_time']) ? date('H:i', strtotime($row['qc_fqa_end_time'])) : '' ?>" onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'qc_fqa_end_time', t, this);">
                                        </div>
                                    </td>
                                    
                                    <!-- Tail Columns -->
                                    <td>
                                        <?php 
                                        $due = $row['job_due_date'] ?? $row['due_date'] ?? '';
                                        if ($due && $due != '0000-00-00' && $due != '0000-00-00 00:00:00'): 
                                        ?>
                                            <span class="due-date <?= $due_class ?>" title="<?= $days_remaining ?> days remaining">
                                                <?= formatDate(substr($due, 0, 10)) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold"><?= intval($row['rtat'] ?? 0) ?></td>
                                    <td class="fw-bold"><?= intval($row['atat'] ?? 0) ?></td>
                                    
                                    <!-- Dispatch Date -->
                                    <td>
                                        <input type="date" class="form-control form-control-sm p-1" style="font-size:11px;" value="<?= h($row['dispatch_date'] ?? '') ?>" onchange="updateInlineField(<?= $row['id'] ?>, 'dispatch_date', this.value)">
                                    </td>
                                    
                                    <!-- Upload By Dropdown -->
                                    <td>
                                        <select class="form-select form-select-sm p-1" style="min-width: 100px; font-size: 11px;" onchange="updateInlineField(<?= $row['id'] ?>, 'upload_by', this.value)">
                                            <option value="">Select TM</option>
                                            <?php foreach ($ts_filter_tms as $tm): ?>
                                                <option value="<?= h($tm['employee_username']) ?>" <?= (($row['upload_by'] ?? '') == $tm['employee_username']) ? 'selected' : '' ?>>
                                                    <?= h($tm['employee_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    
                                    <!-- Path (Dynamic Colors Logic) -->
                                    <?php 
                                        $path_val = $row['path'] ?? '';
                                        $path_class = '';
                                        if (strtoupper($path_val) === 'CRX') {
                                            $path_class = 'bg-warning text-dark'; // ORANGE
                                        } elseif ($qc_curr_status === 'Completed' && !empty($path_val)) {
                                            $path_class = 'bg-success text-white'; // GREEN
                                        }
                                    ?>
                                    <td>
                                        <input type="text" class="form-control form-control-sm p-1 <?= $path_class ?>" style="min-width: 130px; font-size: 11px;" placeholder="Enter Path" value="<?= h($path_val) ?>" onchange="updateInlineField(<?= $row['id'] ?>, 'path', this.value); setTimeout(() => window.location.reload(), 500);">
                                    </td>
                                    
                                    <!-- Priority Dropdown -->
                                    <td>
                                        <select class="form-select form-select-sm p-1" style="min-width: 110px; font-size: 11px;" onchange="updateInlineField(<?= $row['id'] ?>, 'priority', this.value)">
                                            <option value="">Select</option>
                                            <option value="Client Priority" <?= (($row['priority'] ?? '') == 'Client Priority') ? 'selected' : '' ?>>Client Priority</option>
                                            <option value="Urgent" <?= (($row['priority'] ?? '') == 'Urgent') ? 'selected' : '' ?>>Urgent</option>
                                        </select>
                                    </td>

                                    <!-- Status Column with Move Button -->
                                    <td style="text-align: center; vertical-align: middle;">
                                        <button type="button" class="btn btn-sm btn-success move-btn-<?= $row['id'] ?>"
                                                style="font-size: 11px; padding: 3px 10px; font-weight: 600; border-radius: 4px;"
                                                onclick="moveToCrx(<?= $row['id'] ?>, <?= $row['job_id'] ?>)"
                                                title="Move to QC CRX">
                                            <i class="fas fa-paper-plane me-1"></i> Move
                                        </button>
                                    </td>

                                    <!-- TAT Column -->
                                    <?php
                                        $qc_done = in_array($row['status'] ?? '', ['Completed', 'Done']);
                                        $end_d = $qc_done ? ($row['qc_date'] ?: $row['last_updated']) : null;
                                        $tat = getWorkingDaysWithoutSunday($row['date_received'] ?? $row['created_at'], $end_d);
                                        $bg = $qc_done ? 'bg-success' : ($tat > 5 ? 'bg-danger' : 'bg-warning text-dark');
                                    ?>
                                    <td>
                                        <span class="badge <?= $bg ?>"><?= $tat ?> <?= ($tat == 1) ? 'Day' : 'Days' ?></span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="20" class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <h6>No Pending QC Assignments</h6>
                                    <p class="text-muted small">All jobs have been assigned, or there are no jobs that have completed the PAG phase yet.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination Info -->
        <?php if ($qc_result && $qc_result->num_rows > 0): ?>
            <div class="row mt-3">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="fas fa-list me-1"></i> Showing <?= $qc_result->num_rows ?> ready job(s)
                        </small>
                        <small class="text-muted">
                            Reporting Manager: <strong><?= h($reporting_manager) ?></strong>
                        </small>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
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
            
            setTimeout(() => { notification.remove(); }, 5000);
        }
        
        // Show loading overlay
        function showLoading() { document.getElementById('loadingOverlay').classList.add('active'); }
        function hideLoading() { document.getElementById('loadingOverlay').classList.remove('active'); }

        // Scroll Position Preservation
        function saveScrollPositions() {
            const tableResp = document.querySelector('.table-responsive');
            if (tableResp) {
                sessionStorage.setItem('tl_qc_assign_table_x', tableResp.scrollLeft);
                sessionStorage.setItem('tl_qc_assign_table_y', tableResp.scrollTop);
            }
            sessionStorage.setItem('tl_qc_assign_win_x', window.scrollX || 0);
            sessionStorage.setItem('tl_qc_assign_win_y', window.scrollY || 0);
        }

        function restoreScrollPositions() {
            const tableX = sessionStorage.getItem('tl_qc_assign_table_x');
            const tableY = sessionStorage.getItem('tl_qc_assign_table_y');
            const winX = sessionStorage.getItem('tl_qc_assign_win_x');
            const winY = sessionStorage.getItem('tl_qc_assign_win_y');
            
            const tableResp = document.querySelector('.table-responsive');
            if (tableResp && tableX !== null) {
                tableResp.scrollLeft = parseFloat(tableX);
                if (tableY !== null) tableResp.scrollTop = parseFloat(tableY);
            }
            if (winX !== null || winY !== null) {
                window.scrollTo(parseFloat(winX || 0), parseFloat(winY || 0));
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            restoreScrollPositions();
            const tableResp = document.querySelector('.table-responsive');
            if (tableResp) {
                tableResp.addEventListener('scroll', () => {
                    sessionStorage.setItem('tl_qc_assign_table_x', tableResp.scrollLeft);
                }, { passive: true });
            }
        });
        window.addEventListener('beforeunload', saveScrollPositions);

        // Status Update Function
        function updateStatus(qcId, newStatus, type = 'qc') {
            if (newStatus === 'Completed') {
                let row = document.getElementById('row-' + qcId);
                if (row) {
                    let startInput = (type === 'qc_fqa') ? row.querySelector('input[onclick*="qc_fqa_start_time"]') : row.querySelector('input[title="QC Start"]');
                    let endInput = (type === 'qc_fqa') ? row.querySelector('input[onclick*="qc_fqa_end_time"]') : row.querySelector('input[title="QC End"]');
                    if (startInput && endInput) {
                        if (!startInput.value || !endInput.value) {
                            showNotification('danger', 'Please enter both Start Time and End Time before marking as Completed!');
                            setTimeout(() => { window.location.reload(); }, 1500);
                            return; 
                        }
                    }
                }
            }
            showLoading();
            fetch('qc_assign_tm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=update_status&qc_id=' + qcId + '&type=' + encodeURIComponent(type) + '&status=' + encodeURIComponent(newStatus)
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
                hideLoading(); console.error('Error:', error); 
                setTimeout(() => { window.location.reload(); }, 1500);
            });
        }

        // Inline Field Editor (For Time)
        function updateInlineField(qcId, field, value, element = null) {
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
            fetch('qc_assign_tm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=inline_edit&qc_id=' + qcId + '&field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(value)
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) { showNotification('success', 'Updated successfully!'); } 
                else { showNotification('danger', 'Update failed: ' + (data.message || 'Unknown error')); }
            }).catch(error => { hideLoading(); console.error('Error:', error); });
        }
        
        // Quick assign function
        function quickAssign(qcId, jobId, selectElement, type = 'qc') {
            const selectedValue = (selectElement.value || '').trim();
            const isTlOrSubtl = <?= $is_tl_or_subtl ? 'true' : 'false' ?>;
            const currentUsername = <?= json_encode($current_username) ?>;
            const assignedVal = (selectElement.getAttribute('data-assigned') || '').trim();
            const prevVal = (selectElement.getAttribute('data-previous') || assignedVal).trim();
            
            // If empty selection, revert back to assignedVal
            if (!selectedValue) {
                selectElement.value = assignedVal;
                return;
            }
            
            // Permission check for Executive
            if (!isTlOrSubtl) {
                if (assignedVal !== '') {
                    showNotification('warning', 'Only SubTL and Team Lead have access to reassign members.');
                    selectElement.value = assignedVal;
                    selectElement.setAttribute('data-previous', assignedVal);
                    return;
                }
                if (selectedValue !== currentUsername) {
                    showNotification('warning', 'Only SubTL and Team Lead have access to assign to other members.');
                    selectElement.value = '';
                    selectElement.setAttribute('data-previous', '');
                    return;
                }
            }
            
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const tmName = selectedOption.getAttribute('data-name') || selectedValue;
            const typeLabel = (type === 'qc_fqa') ? 'QC_FQA' : 'QC';
            
            showLoading();
            selectElement.disabled = true;
            
            // Send AJAX
            fetch('?', { // Self-posting
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=quick_assign&type=' + encodeURIComponent(type) + '&qc_id=' + encodeURIComponent(qcId) + '&job_id=' + encodeURIComponent(jobId) + '&assigned_to=' + encodeURIComponent(selectedValue)
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                selectElement.disabled = false;
                
                if (data.success) {
                    showNotification('success', `${typeLabel} assigned to ${tmName} successfully!`);
                    selectElement.setAttribute('data-assigned', selectedValue);
                    selectElement.setAttribute('data-previous', selectedValue);
                    document.getElementById('row-' + qcId).classList.add('assigned');
                    setTimeout(() => { window.location.reload(); }, 500); // Reloads to apply Purple Color & In Progress status
                } else {
                    showNotification('danger', data.message || 'Assignment failed');
                    selectElement.value = assignedVal || '';
                    selectElement.setAttribute('data-previous', assignedVal || '');
                }
            })
            .catch(error => {
                hideLoading();
                selectElement.disabled = false;
                showNotification('danger', 'Network error. Please try again.');
                console.error('Error:', error);
                selectElement.value = assignedVal || '';
                selectElement.setAttribute('data-previous', assignedVal || '');
            });
        }

        // Move to QC CRX
        function moveToCrx(qcId, jobId) {
            if (!confirm('Are you sure you want to move this job to QC-CRX?')) {
                return;
            }
            showLoading();
            fetch('?', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=move_to_qc_crx&qc_id=' + qcId + '&job_id=' + jobId
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showNotification('success', 'Moved to QC-CRX successfully!');
                    const rowElem = document.getElementById('row-' + qcId);
                    if (rowElem) {
                        rowElem.style.transition = 'all 0.5s';
                        rowElem.style.opacity = '0';
                        setTimeout(() => { rowElem.remove(); }, 500);
                    } else {
                        setTimeout(() => window.location.reload(), 500);
                    }
                } else {
                    showNotification('danger', 'Error moving to QC-CRX: ' + (data.message || ''));
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showNotification('danger', 'An error occurred while moving job.');
            });
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>