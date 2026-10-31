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

// --- AUTO-CREATE QC_CRX TABLE IF NOT EXISTS ---
$mysqli->query("CREATE TABLE IF NOT EXISTS `qc_crx` (
  `id` int NOT NULL AUTO_INCREMENT,
  `job_id` int DEFAULT NULL,
  `date` date DEFAULT NULL,
  `client` varchar(255) DEFAULT NULL,
  `journal_issue` varchar(255) DEFAULT NULL,
  `article` varchar(255) DEFAULT NULL,
  `process` varchar(100) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Need to Assign',
  `assigned_to_qc` varchar(50) DEFAULT NULL,
  `qc_start_time` time DEFAULT NULL,
  `qc_end_time` time DEFAULT NULL,
  `qc_date` datetime DEFAULT NULL,
  `rtat` int DEFAULT 0,
  `atat` int DEFAULT 0,
  `path` varchar(500) DEFAULT NULL,
  `priority` varchar(50) DEFAULT NULL,
  `dispatch_date` date DEFAULT NULL,
  `upload_by` varchar(50) DEFAULT NULL,
  `done_by` varchar(50) DEFAULT NULL,
  `qc_crx_stage` varchar(50) DEFAULT NULL,
  `pag_crx_stage` varchar(50) DEFAULT NULL,
  `pag_crx_assigned_to` varchar(50) DEFAULT NULL,
  `pag_crx_start_time` time DEFAULT NULL,
  `pag_crx_end_time` time DEFAULT NULL,
  `img_tab_stage` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_job_id` (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// --- AUTO FIX DB SCHEMA (For missing time columns) ---
$check_columns = [
    'qc_start_time' => "TIME NULL DEFAULT NULL",
    'qc_end_time' => "TIME NULL DEFAULT NULL",
    'priority' => "VARCHAR(50) NULL DEFAULT NULL",
    'qc_crx_stage' => "VARCHAR(100) NULL DEFAULT NULL",
    'pag_crx_stage' => "VARCHAR(100) NULL DEFAULT NULL",
    'pag_crx_assigned_to' => "VARCHAR(100) NULL DEFAULT NULL",
    'pag_crx_start_time' => "TIME NULL DEFAULT NULL",
    'pag_crx_end_time' => "TIME NULL DEFAULT NULL",
    'img_tab_stage' => "VARCHAR(100) NULL DEFAULT NULL"
];
foreach ($check_columns as $col => $definition) {
    $check = $mysqli->query("SHOW COLUMNS FROM qc_crx LIKE '$col'");
    if ($check && $check->num_rows == 0) {
        $mysqli->query("ALTER TABLE qc_crx ADD COLUMN $col $definition");
    }
}

// --- AUTO SYNC JOBS FROM JOBS TABLE INTO QC_CRX ---
$mysqli->query("INSERT IGNORE INTO qc_crx (job_id, date, client, journal_issue, article, process, status, created_at, last_updated)
SELECT j.job_id, j.job_date, c.client_name, j.journal_issue, j.article, jm.job_name, 'Need to Assign', NOW(), NOW()
FROM jobs j
JOIN client_master c ON j.client_id = c.client_id
JOIN job_master jm ON j.process_id = jm.job_id
WHERE j.job_id NOT IN (SELECT job_id FROM qc_crx WHERE job_id IS NOT NULL)");

// --- START: Status Update AJAX ---
if (isset($_POST['ajax']) && $_POST['ajax'] == 'update_status') {
    header('Content-Type: application/json');
    $qc_id = (int)$_POST['qc_id'];
    $new_status = trim($_POST['status']);

    // Fetch current times
    $get_stat = $mysqli->prepare("SELECT qc_start_time, qc_end_time FROM qc_crx WHERE id = ?");
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
    ? "UPDATE qc_crx SET status = ?, qc_date = NOW(), last_updated = NOW() WHERE id = ?"
    : "UPDATE qc_crx SET status = ?, last_updated = NOW() WHERE id = ?";
        $stmt = $mysqli->prepare($update_query);
        $stmt->bind_param("si", $new_status, $qc_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'status' => $new_status]);
    } else {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
    }
    exit;
}

// --- START: Inline Edit AJAX (For Time) ---
if (isset($_POST['ajax']) && $_POST['ajax'] == 'inline_edit') {
    header('Content-Type: application/json');
    $qc_id = (int)$_POST['qc_id'];
    $field = $_POST['field'];
    $val = trim($_POST['value']);
    
    $allowed_fields = ['qc_start_time', 'qc_end_time', 'dispatch_date', 'upload_by', 'path', 'priority', 'qc_crx_stage', 'pag_crx_stage', 'pag_crx_assigned_to', 'pag_crx_start_time', 'pag_crx_end_time', 'img_tab_stage'];
    if (in_array($field, $allowed_fields)) {
        if ($val === '') {
            $stmt = $mysqli->prepare("UPDATE qc_crx SET $field = NULL, last_updated = NOW() WHERE id = ?");
            $stmt->bind_param("i", $qc_id);
        } else {
            $stmt = $mysqli->prepare("UPDATE qc_crx SET $field = ?, last_updated = NOW() WHERE id = ?");
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
    $assigned_to = trim($_POST['assigned_to']);
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        if (empty($assigned_to)) {
            throw new Exception('Please select a TM');
        }
        
        // Start transaction
        $mysqli->begin_transaction();
        
        // First, check if the QC record exists
        $check_sql = "SELECT id, status FROM qc_crx WHERE id = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("i", $qc_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows == 0) {
            throw new Exception('QC record not found');
        }
        
        $qc_data = $check_result->fetch_assoc();
        $check_stmt->close();
        
        // Update qc table
        $update_qc = "UPDATE qc_crx SET 
                      assigned_to_qc = ?,
                      status = 'In Progress',
                      last_updated = NOW()
                      WHERE id = ?";
        
        $stmt = $mysqli->prepare($update_qc);
        if (!$stmt) {
            throw new Exception('Error preparing QC update: ' . $mysqli->error);
        }
        
        $stmt->bind_param("si", $assigned_to, $qc_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Error updating QC: ' . $stmt->error);
        }
        
        if ($stmt->affected_rows === 0) {
            throw new Exception('No changes made to QC record');
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
$qc_tm_filter = isset($_GET['qc_tm']) ? trim($_GET['qc_tm']) : '';
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'qc.created_at';
$dir = isset($_GET['dir']) && strtoupper($_GET['dir']) == 'ASC' ? 'ASC' : 'DESC';

// Build the query for QC records
// 1. QC Status is not Completed/Done
// 2. Process must involve PAG or LE or TS
// 3. THE CRITICAL CONDITION: PAG phase OR LE phase must be 'Completed'
$where_conditions = [
    "qc.status != 'Completed'", 
    "qc.status != 'Done'",
    "(qc.qc_crx_stage IS NULL OR qc.qc_crx_stage != 'Moved')",
    "p.job_name IN ('PAG', 'PAG+XML', 'CE+PAG', 'CE+PAG+XML', 'CE+TS', 'CE+TS+XML', 'TS', 'TS+XML', 'LE', 'CE+LE', 'CE+LE+XML', 'CE+PAG+LE')",
    "(
        EXISTS (SELECT 1 FROM pag WHERE pag.job_id = qc.job_id AND (pag.status = 'Completed' OR pag.status = 'Done'))
        OR 
        EXISTS (SELECT 1 FROM le WHERE le.job_id = qc.job_id AND le.status IN ('Completed', 'Done', 'LE-QC Done', 'LE & LE-QC Done'))
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
                    pag.eq, pag.pages, pag.images, pag.tables_count, pag.status as pag_status,
                    qc.rtat, qc.atat,
                    (SELECT upload_by FROM job_uploads WHERE job_id = j.job_id ORDER BY id DESC LIMIT 1) as job_upload_by,
                    (SELECT upload_date FROM job_uploads WHERE job_id = j.job_id ORDER BY id DESC LIMIT 1) as upload_date,
                    DATEDIFF(j.due_date, CURDATE()) as days_remaining
             FROM qc_crx qc 
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
$status_query = "SELECT DISTINCT status FROM qc_crx WHERE status != 'Completed' AND status != 'Done' ORDER BY status";
$status_result = $mysqli->query($status_query);

// Get count statistics (Using same conditions for accuracy)
$stats_query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN qc.status = 'Need to Assign' THEN 1 ELSE 0 END) as need_assign,
                    SUM(CASE WHEN qc.status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN qc.assigned_to_qc IS NOT NULL AND qc.assigned_to_qc != '' THEN 1 ELSE 0 END) as qc_assigned
                FROM qc_crx qc 
                LEFT JOIN jobs j ON qc.job_id = j.job_id
                LEFT JOIN job_master p ON j.process_id = p.job_id
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
        .table thead th { background-color: #707981 !important; color: white !important; font-weight: bold; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.025em; padding: 0.8rem 0.5rem; border: 1px solid #5a6268 !important; white-space: nowrap; }
        .table tbody td { padding: 0.6rem 0.4rem; font-size: 0.75rem; color: #334155; vertical-align: middle; border-bottom: 1px solid #e2e8f0; }
        .table tbody tr:hover { background-color: #f8fafc; }
        .table tbody tr.assigned { background-color: #fdf2f8; }
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
        .due-date { font-weight: 500; }
        .due-date.urgent { color: #dc2626; }
        .due-date.warning { color: #d97706; }
        .due-date.normal { color: #059669; }
        .assignment-cell { min-width: 160px; }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.8); display: none; justify-content: center; align-items: center; z-index: 9999; }
        .loading-overlay.active { display: flex; }
        .loading-spinner { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); text-align: center; }
        .notification { position: fixed; top: 20px; right: 20px; z-index: 10000; min-width: 300px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
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
    <div id="notificationContainer"></div>

    <div class="page-header">
        <div class="container-fluid px-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-1" style="font-weight: 700; color: #1e293b;">
                        <i class="fas fa-table me-2" style="color: #667eea;"></i>
                        QC_CRX Assign
                    </h4>
                    <!-- <p class="mb-0 text-muted small">
                        Assign QC tasks to team members under <?= h($effective_manager) ?>. 
                        <em>(Jobs appear here ONLY after the PAG phase is 100% completed)</em>
                    </p> -->
                </div>
                <div>
                    <a href="jobs.php" class="btn btn-outline-secondary btn-sm me-2">
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
                <div class="col-md-3">
                    <label class="form-label small">QC TM</label>
                    <select name="qc_tm" class="form-select form-select-sm">
                        <option value="">All Team Members</option>
                        <?php foreach ($tms as $tm): ?>
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
                <table class="table table-hover table-bordered" style="min-width: 1200px; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th>LE moved Date</th>
                            <th>CE moved Date</th>
                            <th>Recevied Date</th>
                            <th>Client</th>
                            <th>Journal</th>
                            <th>Articles</th>
                            <th>EQN</th>
                            <th>Pages</th>
                            <th>Images</th>
                            <th>Tables</th>
                            <th>CE Status</th>
                            <th>LE Status</th>
                            <th>PAG</th>
                            <th style="min-width: 250px;">QC</th>
                            <th>Due Date</th>
                            <th>R-TAT</th>
                            <th>A-TAT</th>
                            <th>Dispatch Date</th>
                            <th>Upload By</th>
                            <th>QC_CRX_Stage</th>
                            <th>PAG_CRX_Stage</th>
                            <th>IMG_TAB_Stage</th>
                            <th>Path</th>
                            <th>Priority</th>
                            <th>TAT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($qc_result && $qc_result->num_rows > 0): ?>
                            <?php while ($row = $qc_result->fetch_assoc()): 
                                $has_qc = !empty($row['assigned_to_qc']);
                                $row_class = $has_qc ? 'assigned' : '';
                                $qc_stat = $row['status'] ?? 'Need to Assign';
                            ?>
                                <tr class="<?= $row_class ?>" id="row-<?= $row['id'] ?>">
                                    <!-- Date Fields -->
                                    <td><?= (in_array($row['le_status'], ['Completed', 'Done', 'LE & LE-QC Done', 'LE-QC Done', 'LE Done']) && !empty($row['le_moved_date'])) ? formatDate(substr($row['le_moved_date'], 0, 10)) : '—' ?></td>
                                    <td><?= !empty($row['ce_moved_date']) ? formatDate(substr($row['ce_moved_date'], 0, 10)) : '—' ?></td>
                                    <td><?= formatDate(substr($row['pag_received_date'] ?? '', 0, 10)) ?></td>
                                    
                                    <!-- Job Info -->
                                    <td><span class="fw-500"><?= h(!empty($row['team_client_name']) ? $row['team_client_name'] : ($row['client_name'] ?? 'N/A')) ?></span></td>
                                    <td><?= h($row['journal_issue'] ?? $row['jobs_issue'] ?? 'N/A') ?></td>
                                    <td><?= h($row['article'] ?? $row['jobs_article'] ?? 'N/A') ?></td>
                                    
                                    <!-- Counts -->
                                    <td><?= h($row['eq'] ?? '0') ?></td>
                                    <td><?= h($row['pages'] ?? '0') ?></td>
                                    <td><?= h($row['images'] ?? '0') ?></td>
                                    <td><?= h($row['tables_count'] ?? '0') ?></td>
                                    
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
                                    <td class="assignment-cell" style="padding: 8px; vertical-align: top;">
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
                                            <small class="text-dark d-block mb-1" style="font-weight: 600;">
                                                <?php 
                                                $found_name = '';
                                                foreach ($tms as $tm) { if ($tm['employee_username'] == $row['assigned_to_qc']) { $found_name = $tm['employee_name']; break; } }
                                                echo h($found_name ?: $row['assigned_to_qc']);
                                                ?>
                                            </small>
                                        <?php endif; ?>

                                        <select class="assign-select mt-1 qc-select-<?= $row['id'] ?>" onchange="quickAssign(<?= $row['id'] ?>, <?= $row['job_id'] ?>, this)">
                                            <option value=""><?= !empty($row['assigned_to_qc']) ? 'Change QC TM' : 'Assign LE-QC' ?></option>
                                            <?php foreach ($tms as $tm): ?>
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
                                    
                                    <!-- Tail Columns -->
                                    <td><?= !empty($row['job_due_date'] ?? $row['due_date']) ? formatDate(substr($row['job_due_date'] ?? $row['due_date'], 0, 10)) : '—' ?></td>
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
                                            <?php foreach ($tms as $tm): ?>
                                                <option value="<?= h($tm['employee_username']) ?>" <?= (($row['upload_by'] ?? '') == $tm['employee_username']) ? 'selected' : '' ?>>
                                                    <?= h($tm['employee_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    
                                    <!-- QC_CRX_Stage Column -->
                                    <td>
                                        <select class="form-select form-select-sm p-1" style="min-width: 130px; font-size: 11px;" 
                                                onchange="updateInlineField(<?= $row['id'] ?>, 'qc_crx_stage', this.value); if(this.value === 'Moved') { setTimeout(() => window.location.reload(), 500); }">
                                            <option value="">Select</option>
                                            <option value="QC_CRX" <?= (($row['qc_crx_stage'] ?? '') == 'QC_CRX') ? 'selected' : '' ?>>QC_CRX</option>
                                            <option value="QC_Complete" <?= (($row['qc_crx_stage'] ?? '') == 'QC_Complete') ? 'selected' : '' ?>>QC_Complete</option>
                                            <option value="QC_RECRX" <?= (($row['qc_crx_stage'] ?? '') == 'QC_RECRX') ? 'selected' : '' ?>>QC_RECRX</option>
                                            <option value="QC_RECRX_COMPLETE" <?= (($row['qc_crx_stage'] ?? '') == 'QC_RECRX_COMPLETE') ? 'selected' : '' ?>>QC_RECRX_COMPLETE</option>
                                            <option value="Moved" <?= (($row['qc_crx_stage'] ?? '') == 'Moved') ? 'selected' : '' ?>>Moved</option>
                                        </select>
                                    </td>

                                    <!-- PAG_CRX_Stage Column -->
                                    <td style="min-width: 180px; vertical-align: top; padding: 6px;">
                                        <div class="space-y-1">
                                            <!-- Stage Dropdown -->
                                            <select class="form-select form-select-sm p-0 px-1 mb-1" style="font-size: 11px; width: 100%; height: 22px;" 
                                                    onchange="updateInlineField(<?= $row['id'] ?>, 'pag_crx_stage', this.value)">
                                                <option value="">Select Stage</option>
                                                <option value="PAG_CRX" <?= (($row['pag_crx_stage'] ?? '') == 'PAG_CRX') ? 'selected' : '' ?>>PAG_CRX</option>
                                                <option value="PAG_CRX_Compelete" <?= (($row['pag_crx_stage'] ?? '') == 'PAG_CRX_Compelete') ? 'selected' : '' ?>>PAG_CRX_Compelete</option>
                                            </select>

                                            <!-- Member Name Dropdown -->
                                            <select class="form-select form-select-sm p-0 px-1 mb-1" style="font-size: 11px; width: 100%; height: 22px;" 
                                                    onchange="updateInlineField(<?= $row['id'] ?>, 'pag_crx_assigned_to', this.value)">
                                                <option value="">Assign PAG-CRX</option>
                                                <?php foreach ($tms as $tm): ?>
                                                    <option value="<?= h($tm['employee_username']) ?>" <?= (($row['pag_crx_assigned_to'] ?? '') == $tm['employee_username']) ? 'selected' : '' ?>>
                                                        <?= h($tm['employee_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <!-- Start and End Time with 1-click auto fill -->
                                            <div class="d-flex gap-1">
                                                <input type="text" readonly placeholder="--:--" class="form-control form-control-sm p-1 text-center" 
                                                       style="font-size:10px; width:50%; cursor:pointer; font-weight:600;" 
                                                       title="Click to fill current time" 
                                                       value="<?= !empty($row['pag_crx_start_time']) ? date('H:i', strtotime($row['pag_crx_start_time'])) : '' ?>" 
                                                       onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'pag_crx_start_time', t, this);">
                                                <input type="text" readonly placeholder="--:--" class="form-control form-control-sm p-1 text-center" 
                                                       style="font-size:10px; width:50%; cursor:pointer; font-weight:600;" 
                                                       title="Click to fill current time" 
                                                       value="<?= !empty($row['pag_crx_end_time']) ? date('H:i', strtotime($row['pag_crx_end_time'])) : '' ?>" 
                                                       onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'pag_crx_end_time', t, this);">
                                            </div>
                                        </div>
                                    </td>

                                    <!-- IMG_TAB_Stage Column -->
                                    <td>
                                        <select class="form-select form-select-sm p-1" style="min-width: 120px; font-size: 11px;" 
                                                onchange="updateInlineField(<?= $row['id'] ?>, 'img_tab_stage', this.value)">
                                            <option value="">Select</option>
                                            <option value="IMG_CRX" <?= (($row['img_tab_stage'] ?? '') == 'IMG_CRX') ? 'selected' : '' ?>>IMG_CRX</option>
                                            <option value="IMG_COMPLETE" <?= (($row['img_tab_stage'] ?? '') == 'IMG_COMPLETE') ? 'selected' : '' ?>>IMG_COMPLETE</option>
                                            <option value="TAB_CRX" <?= (($row['img_tab_stage'] ?? '') == 'TAB_CRX') ? 'selected' : '' ?>>TAB_CRX</option>
                                            <option value="TAB_COMPLETE" <?= (($row['img_tab_stage'] ?? '') == 'TAB_COMPLETE') ? 'selected' : '' ?>>TAB_COMPLETE</option>
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
                            Reporting Manager: <strong><?= h($effective_manager) ?></strong>
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

        // Status Update Function
        function updateStatus(qcId, newStatus) {
            if (newStatus === 'Completed') {
                let row = document.getElementById('row-' + qcId);
                if (row) {
                    let startInput = row.querySelector('input[title="QC Start"]');
                    let endInput = row.querySelector('input[title="QC End"]');
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
            fetch('qc_crx_assign_tm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=update_status&qc_id=' + qcId + '&status=' + encodeURIComponent(newStatus)
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
            fetch('qc_crx_assign_tm.php', {
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
        function quickAssign(qcId, jobId, selectElement) {
            const selectedValue = selectElement.value;
            
            if (!selectedValue) return;
            
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const tmName = selectedOption.getAttribute('data-name') || selectedValue;
            
          
            
            selectElement.setAttribute('data-previous', selectedValue);
            showLoading();
            selectElement.disabled = true;
            
            // Send AJAX
            fetch('?', { // Self-posting
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=quick_assign&qc_id=' + qcId + '&job_id=' + jobId + '&assigned_to=' + encodeURIComponent(selectedValue)
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                selectElement.disabled = false;
                
                if (data.success) {
                    showNotification('success', `QC assigned to ${tmName} successfully!`);
                    document.getElementById('row-' + qcId).classList.add('assigned');
                    setTimeout(() => { window.location.reload(); }, 500); // Reloads to apply Purple Color & In Progress status
                    
                } else {
                    showNotification('danger', 'Error: ' + data.message);
                    const previousValue = selectElement.getAttribute('data-previous') || '';
                    selectElement.value = previousValue;
                }
            })
            .catch(error => {
                hideLoading();
                selectElement.disabled = false;
                showNotification('danger', 'Network error. Please try again.');
                console.error('Error:', error);
                const previousValue = selectElement.getAttribute('data-previous') || '';
                selectElement.value = previousValue;
            });
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>