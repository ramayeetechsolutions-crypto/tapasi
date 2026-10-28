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

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

function formatDate($date, $format = 'd-m-Y') {
    if (!$date) return '';
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    return $dateObj ? $dateObj->format($format) : $date;
}

// --- AUTO FIX DB SCHEMA (For missing time, count and status columns) ---
$check_columns = [
    'pag_start_time' => "TIME NULL DEFAULT NULL",
    'pag_end_time' => "TIME NULL DEFAULT NULL",
    'img_start_time' => "TIME NULL DEFAULT NULL",
    'img_end_time' => "TIME NULL DEFAULT NULL",
    'eq' => "VARCHAR(50) NULL DEFAULT '0'",
    'images' => "VARCHAR(50) NULL DEFAULT '0'",
    'tables_count' => "VARCHAR(50) NULL DEFAULT '0'",
    'pages' => "VARCHAR(50) NULL DEFAULT '0'",
    'word_status' => "VARCHAR(100) DEFAULT 'Need to Assign'",
    'pag_status_col' => "VARCHAR(100) DEFAULT 'Need to Assign'",
    'table_status' => "VARCHAR(100) DEFAULT 'Need to Assign'",
    'table_start_time' => "TIME NULL DEFAULT NULL",
    'table_end_time' => "TIME NULL DEFAULT NULL",
    'assigned_to_table' => "VARCHAR(100) NULL DEFAULT NULL",
    'path' => "VARCHAR(255) NULL DEFAULT NULL",
    'priority' => "VARCHAR(50) NULL DEFAULT NULL"
];
foreach ($check_columns as $col => $definition) {
    $check = $mysqli->query("SHOW COLUMNS FROM pag LIKE '$col'");
    if ($check && $check->num_rows == 0) {
        $mysqli->query("ALTER TABLE pag ADD COLUMN $col $definition");
    }
}
// --------------------------------------------------------------

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

// Get all employees under the same reporting manager with TM role
$tms = [];
if ($reporting_manager) {
    $tm_query = "SELECT em.employee_id, em.employee_username, em.employee_name, em.user_role_id
                 FROM employee_master em 
                 LEFT JOIN user_role_master ur ON em.user_role_id = ur.role_id
                 WHERE em.reporting_manager = ? 
                 AND em.status = 'Active' 
                 AND (ur.role_name IN ('TM', 'TL', 'Admin', 'SubTL', 'Sub TL') OR em.employee_username = 'TPS032')
                 ORDER BY em.employee_name";
    
    $stmt = $mysqli->prepare($tm_query);
    if ($stmt) {
        $stmt->bind_param("s", $reporting_manager);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($tm = $result->fetch_assoc()) {
            $tms[] = $tm;
        }
        $stmt->close();
    }
}

// --- START: Status Update AJAX ---
if (isset($_POST['ajax']) && $_POST['ajax'] == 'update_status') {
    header('Content-Type: application/json');
    $pag_id = (int)$_POST['pag_id'];
    $target_type = $_POST['type'] ?? 'pag'; // 'pag' or 'word'
    $new_status = trim($_POST['status']);

    // Fetch current times AND statuses
    $get_stat = $mysqli->prepare("SELECT pag_start_time, pag_end_time, img_start_time, img_end_time, word_status, pag_status_col FROM pag WHERE id = ?");
    $get_stat->bind_param("i", $pag_id);
    $get_stat->execute();
    $curr_data = $get_stat->get_result()->fetch_assoc();
    $get_stat->close();

    // Mandatory Time Validation
    if (in_array($new_status, ['Completed', 'W-Complete', 'ID_Complete'])) {
        if ($target_type === 'pag' && (empty($curr_data['pag_start_time']) || empty($curr_data['pag_end_time']))) {
            echo json_encode(['success' => false, 'message' => 'Please fill both PAG Start and End Time before marking as Completed.']);
            exit;
        } elseif ($target_type === 'word' && (empty($curr_data['img_start_time']) || empty($curr_data['img_end_time']))) {
            echo json_encode(['success' => false, 'message' => 'Please fill both Word Process Start and End Time before marking as Completed.']);
            exit;
        }
    }

    $col = ($target_type === 'word') ? 'word_status' : 'pag_status_col';
    
    // Check both statuses to decide main job status
    $current_word = ($target_type === 'word') ? $new_status : ($curr_data['word_status'] ?? 'Need to Assign');
    $current_pag = ($target_type === 'pag') ? $new_status : ($curr_data['pag_status_col'] ?? 'Need to Assign');

    $main_status = 'In Progress';
    if (in_array($current_word, ['Completed', 'W-Complete', 'ID_Complete']) && $current_pag === 'Completed') {
        $main_status = 'Completed'; // Move to TS Report
    }
    
    $stmt = $mysqli->prepare("UPDATE pag SET $col = ?, status = ?, last_updated = NOW() WHERE id = ?");
    $stmt->bind_param("ssi", $new_status, $main_status, $pag_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'status' => $new_status]);
    } else {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
    }
    exit;
}

// --- START: Inline Edit AJAX (For Time & Counts) ---
if (isset($_POST['ajax']) && $_POST['ajax'] == 'inline_edit') {
    header('Content-Type: application/json');
    $pag_id = (int)$_POST['pag_id'];
    $field = $_POST['field'];
    $val = trim($_POST['value']);
    
    $allowed_fields = ['eq', 'images', 'tables_count', 'pages', 'pag_start_time', 'pag_end_time', 'img_start_time', 'img_end_time', 'table_status', 'table_start_time', 'table_end_time', 'assigned_to_table', 'path', 'priority'];
    if (in_array($field, $allowed_fields)) {
        if ($val === '') {
            $stmt = $mysqli->prepare("UPDATE pag SET $field = NULL, last_updated = NOW() WHERE id = ?");
            $stmt->bind_param("i", $pag_id);
        } else {
            $stmt = $mysqli->prepare("UPDATE pag SET $field = ?, last_updated = NOW() WHERE id = ?");
            $stmt->bind_param("si", $val, $pag_id);
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
    $pag_id = (int)$_POST['pag_id'];
    $job_id = (int)$_POST['job_id'];
    $type = $_POST['type']; // 'pag' or 'word' (images)
    $assigned_to = trim($_POST['assigned_to']);
    
    $response = ['success' => false, 'message' => ''];
    try {
        if (empty($assigned_to)) throw new Exception('Please select a TM');
        $mysqli->begin_transaction();
        
        $field = ($type == 'pag') ? 'assigned_to_pag' : 'assigned_to_img';
        $status_col = ($type == 'pag') ? 'pag_status_col' : 'word_status'; // Target specific column

        // Updates assigned TM, sets sub-status to In Progress, and main status to In Progress
        $update_pag = "UPDATE pag SET $field = ?, $status_col = 'In Progress', status = 'In Progress', last_updated = NOW() WHERE id = ?";
        
        $stmt = $mysqli->prepare($update_pag);
        $stmt->bind_param("si", $assigned_to, $pag_id);
        if (!$stmt->execute()) throw new Exception('Error updating Assignment: ' . $stmt->error);
        $stmt->close();
        
        $mysqli->commit();
        $response['success'] = true;
        foreach ($tms as $tm) {
            if ($tm['employee_username'] == $assigned_to) {
                $response['tm_name'] = $tm['employee_name']; break;
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
$pag_tm_filter = isset($_GET['pag_tm']) ? trim($_GET['pag_tm']) : '';
$images_tm_filter = isset($_GET['images_tm']) ? trim($_GET['images_tm']) : '';
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'pag.created_at';
$dir = isset($_GET['dir']) && strtoupper($_GET['dir']) == 'ASC' ? 'ASC' : 'DESC';

// Build the query for PAG records
// 1. Status is not Completed/Done
// 2. Process must involve PAG
// 3. IF process involves CE, job MUST be present in job_uploads table
$where_conditions = [
    "pag.status != 'Completed'", 
    "pag.status != 'Done'",
    "p.job_name IN ('PAG', 'PAG+XML', 'CE+PAG', 'CE+PAG+XML', 'TS', 'TS+XML', 'CE+TS', 'CE+TS+XML')",
    "(p.job_name NOT LIKE '%CE%' OR le.status_button = 'Moved' OR EXISTS (SELECT 1 FROM job_uploads ju WHERE ju.job_id = pag.job_id))"
];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(c.client_name LIKE ? OR c.team_client_name LIKE ? OR pag.issue LIKE ? OR pag.articles LIKE ? OR pag.process LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sssss';
}

if (!empty($status_filter)) {
    $where_conditions[] = "pag.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($pag_tm_filter)) {
    $where_conditions[] = "pag.assigned_to_pag = ?";
    $params[] = $pag_tm_filter;
    $types .= 's';
}

if (!empty($images_tm_filter)) {
    $where_conditions[] = "pag.assigned_to_img = ?";
    $params[] = $images_tm_filter;
    $types .= 's';
}

$where_sql = implode(' AND ', $where_conditions);

// Main query (Updated to fetch CE, LE, QC statuses and dates)
$pag_query = "SELECT pag.*, 
                    j.job_date as job_date_received,
                    j.journal_issue, 
                    j.article,
                    j.process_id,
                    j.assigned_to,
                    j.due_date,
                    j.notes as job_notes,
                    c.client_name,
                    c.team_client_name,
                    c.client_id,
                    p.job_name as process_name,
                    ce.ce_date as ce_moved_date,
                    le.status as le_status,
                    le.last_updated as le_moved_date,
                    qc.status as qc_status,
                    qc.rtat,
                    qc.atat,
                    DATEDIFF(j.due_date, CURDATE()) as days_remaining
             FROM pag 
             LEFT JOIN jobs j ON pag.job_id = j.job_id
             LEFT JOIN client_master c ON j.client_id = c.client_id
             LEFT JOIN job_master p ON j.process_id = p.job_id
             LEFT JOIN ce ON j.job_id = ce.job_id
             LEFT JOIN le ON j.job_id = le.job_id
             LEFT JOIN qc ON j.job_id = qc.job_id
             WHERE $where_sql
             ORDER BY $sort $dir";

$stmt = $mysqli->prepare($pag_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
if (!$stmt) {
    die("Query Error: " . $mysqli->error);
}
$stmt->execute();
$pag_result = $stmt->get_result();
$stmt->close();

// Get unique statuses for filter dropdown
$status_query = "SELECT DISTINCT status FROM pag WHERE status != 'Completed' AND status != 'Done' ORDER BY status";
$status_result = $mysqli->query($status_query);

// Get count statistics (Using same conditions for accuracy)
$stats_query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN pag.status = 'Need to Assign' THEN 1 ELSE 0 END) as need_assign,
                    SUM(CASE WHEN pag.status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN pag.assigned_to_pag IS NOT NULL AND pag.assigned_to_pag != '' THEN 1 ELSE 0 END) as pag_assigned,
                    SUM(CASE WHEN pag.assigned_to_img IS NOT NULL AND pag.assigned_to_img != '' THEN 1 ELSE 0 END) as images_assigned
                FROM pag 
                LEFT JOIN jobs j ON pag.job_id = j.job_id
                LEFT JOIN job_master p ON j.process_id = p.job_id
                LEFT JOIN le ON j.job_id = le.job_id
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
    <title>TS Assign</title>
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
        .stats-card.card-pag { background: #dcfce7; border-color: #bbf7d0; }
        .stats-card.card-images { background: #fae8ff; border-color: #f3e8ff; }
        .stats-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; background: rgba(255,255,255,0.6); }
        .stats-icon.total { color: #0369a1; }
        .stats-icon.need { color: #92400e; }
        .stats-icon.progress { color: #1e40af; }
        .stats-icon.pag { color: #166534; }
        .stats-icon.images { color: #a21caf; }
        .stats-info h3 { font-size: 1.5rem; font-weight: 700; margin: 0; line-height: 1.2; }
        .stats-info p { margin: 0; color: #64748b; font-size: 0.8rem; font-weight: 500; }
        .filter-section { background: white; border-radius: 12px; padding: 1rem; margin-bottom: 1.5rem; border: 1px solid #e2e8f0; }
        .table-container { background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .table { margin-bottom: 0; }
        .table thead th { background-color: #707981 !important; color: white !important; font-weight: bold; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.025em; padding: 0.8rem 0.5rem; border: 1px solid #5a6268 !important; white-space: nowrap; }
        .table tbody td { padding: 0.6rem 0.4rem; font-size: 0.75rem; color: #334155; vertical-align: middle; border-bottom: 1px solid #e2e8f0; }
        .table tbody tr:hover { background-color: #f8fafc; }
        .table tbody tr.assigned { background-color: #f0fdf4; }
        .status-badge { display: inline-block; padding: 0.2rem 0.4rem; border-radius: 9999px; font-size: 0.65rem; font-weight: 500; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-progress { background: #dbeafe; color: #1e40af; }
        .tm-badge { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.2rem 0.4rem; border-radius: 4px; font-size: 0.7rem; background: #f1f5f9; color: #475569; white-space: nowrap; }
        .tm-badge.pag { background: #dcfce7; color: #166534; }
        .tm-badge.images { background: #fae8ff; color: #a21caf; }
        /* Dynamic Workflow Badges (Same as LE Assign) */
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
        .assignment-cell { min-width: 130px; }
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
                        TS Assign
                    </h4>
                    <!-- <p class="mb-0 text-muted small">
                        Assign TS and Images separately to team members under <?= h($reporting_manager) ?>. 
                        <em>(Dependent jobs appear only after CE is uploaded)</em>
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
            <div class="col-md-2">
                <div class="stats-card card-total">
                    <div class="stats-icon total">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stats-info">
                        <h3><?= $stats['total'] ?? 0 ?></h3>
                        <p>Total Ready</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
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
            <div class="col-md-2">
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
                <div class="stats-card card-pag">
                    <div class="stats-icon pag">
                        <i class="fas fa-file-pdf"></i>
                    </div>
                    <div class="stats-info">
                        <h3><?= $stats['pag_assigned'] ?? 0 ?></h3>
                        <p>TS Assigned</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card card-images">
                    <div class="stats-icon images">
                        <i class="fas fa-image"></i>
                    </div>
                    <div class="stats-info">
                        <h3><?= $stats['images_assigned'] ?? 0 ?></h3>
                        <p>Images Assigned</p>
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
                    <label class="form-label small">TS TM</label>
                    <select name="pag_tm" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($tms as $tm): ?>
                            <option value="<?= h($tm['employee_username']) ?>" 
                                <?= $pag_tm_filter == $tm['employee_username'] ? 'selected' : '' ?>>
                                <?= h($tm['employee_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Images TM</label>
                    <select name="images_tm" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($tms as $tm): ?>
                            <option value="<?= h($tm['employee_username']) ?>" 
                                <?= $images_tm_filter == $tm['employee_username'] ? 'selected' : '' ?>>
                                <?= h($tm['employee_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Sort By</label>
                    <select name="sort" class="form-select form-select-sm">
                        <option value="pag.created_at" <?= $sort == 'pag.created_at' ? 'selected' : '' ?>>Created Date</option>
                        <option value="c.client_name" <?= $sort == 'c.client_name' ? 'selected' : '' ?>>Client Name</option>
                        <option value="pag.status" <?= $sort == 'pag.status' ? 'selected' : '' ?>>Status</option>
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
                <table class="table table-hover table-bordered" style="min-width: 1800px; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th>LE moved Date</th>
                            <th>CE moved Date</th>
                            <th>Received Date</th>
                            <th>Client</th>
                            <th>Journals</th>
                            <th>Articles</th>
                            <th>EQN</th>
                            <th>Pages</th>
                            <th>Images</th>
                            <th>Tables</th>
                            <th>Process</th>
                            <th>Due Date</th>
                            <th>FQA Status</th>
                            <th>LE Status</th>
                            <th style="min-width: 300px;">Word process</th>
                            <th style="min-width: 300px;">TS</th>
                            <th>R-TAT</th>
                            <th>A-TAT</th>
                            <th>Path</th>
                            <th>Priority</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($pag_result && $pag_result->num_rows > 0): ?>
                            <?php while ($row = $pag_result->fetch_assoc()): 
                                $has_pag = !empty($row['assigned_to_pag']);
                                $has_word = !empty($row['assigned_to_img']);
                                $row_class = ($has_pag || $has_word) ? 'assigned' : '';
                                
                                $word_stat = $row['word_status'] ?? 'Need to Assign';
                                $pag_stat = $row['pag_status_col'] ?? 'Need to Assign';
                            ?>
                                <tr class="<?= $row_class ?>" id="row-<?= $row['id'] ?>">
                                    <!-- LE Moved Date -->
                                    <td><?= (in_array($row['le_status'], ['Completed', 'Done', 'LE & LE-QC Done', 'LE-QC Done', 'LE Done'])) ? formatDate(substr($row['le_moved_date'], 0, 10)) : '—' ?></td>
                                    <!-- CE Moved Date -->
                                    <td><?= !empty($row['ce_moved_date']) ? formatDate(substr($row['ce_moved_date'], 0, 10)) : '—' ?></td>
                                    <!-- Received Date (Using PAG Creation Date) -->
                                    <td><?= formatDate(substr($row['created_at'], 0, 10)) ?></td>
                                    <!-- Client -->
                                    <td><span class="fw-500"><?= h(!empty($row['team_client_name']) ? $row['team_client_name'] : ($row['client_name'] ?? 'N/A')) ?></span></td>
                                    <!-- Journals -->
                                    <td><?= h($row['journal_issue'] ?? 'N/A') ?></td>
                                    <!-- Articles -->
                                    <td><?= h($row['articles'] ?? 'N/A') ?></td>
                                    
                                    <!-- EQN, Pages, Images, Tables (Inline Editable) -->
                                    <td><input type="number" class="form-control form-control-sm p-1 text-center" style="width: 50px;" value="<?= h($row['eq'] ?? '0') ?>" onchange="updateInlineField(<?= $row['id'] ?>, 'eq', this.value)"></td>
                                    <td><input type="number" class="form-control form-control-sm p-1 text-center" style="width: 50px;" value="<?= h($row['pages'] ?? '0') ?>" onchange="updateInlineField(<?= $row['id'] ?>, 'pages', this.value)"></td>
                                    <td><input type="number" class="form-control form-control-sm p-1 text-center" style="width: 50px;" value="<?= h($row['images'] ?? '0') ?>" onchange="updateInlineField(<?= $row['id'] ?>, 'images', this.value)"></td>
                                    <!-- Tables (Count, Status Dropdown, Assign TM & Times) -->
                                <td class="assignment-cell" style="padding: 8px; vertical-align: top; min-width: 130px;">
                                    <!-- Count Input -->
                                    <div class="mb-1">
                                        <input type="number" class="form-control form-control-sm p-1 text-center w-100" style="height: 22px; font-size: 11px;" placeholder="Count" value="<?= h($row['tables_count'] ?? '0') ?>" onchange="updateInlineField(<?= $row['id'] ?>, 'tables_count', this.value)">
                                    </div>
                                    
                                    <!-- Status Dropdown -->
                                    <div class="mb-1">
                                        <select class="form-select form-select-sm p-0 px-1 w-100" style="font-size: 11px; height: 22px; border-color: #ccc; background-color: #f9f9f9;" onchange="updateInlineField(<?= $row['id'] ?>, 'table_status', this.value)">
                                            <option value="Need to Assign" <?= (($row['table_status'] ?? 'Need to Assign') == 'Need to Assign') ? 'selected' : '' ?>>Need to Assign</option>
                                            <option value="In Progress" <?= (($row['table_status'] ?? '') == 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                                            <option value="Completed" <?= (($row['table_status'] ?? '') == 'Completed') ? 'selected' : '' ?>>Completed</option>
                                        </select>
                                    </div>
                                    
                                    <!-- NEW ASSIGN TM DROPDOWN -->
                                    <div class="mb-1">
                                        <select class="assign-select mt-1 w-100" onchange="updateInlineField(<?= $row['id'] ?>, 'assigned_to_table', this.value)">
                                            <option value=""><?= !empty($row['assigned_to_table']) ? 'Change TM' : 'Assign TM' ?></option>
                                            <?php foreach ($tms as $tm): ?>
                                                <option value="<?= h($tm['employee_username']) ?>" <?= (($row['assigned_to_table'] ?? '') == $tm['employee_username']) ? 'selected' : '' ?>>
                                                    <?= h($tm['employee_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Start & End Time Inputs (Retained) -->
                                    <div class="d-flex gap-1 mt-1">
                                        <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; width:50%; cursor:pointer;" title="Table Start" value="<?= !empty($row['table_start_time']) ? date('H:i', strtotime($row['table_start_time'])) : '' ?>" readonly onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'table_start_time', t, this);">
                                        <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; width:50%; cursor:pointer;" title="Table End" value="<?= !empty($row['table_end_time']) ? date('H:i', strtotime($row['table_end_time'])) : '' ?>" readonly onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'table_end_time', t, this);">
                                            </div>
                                    
                                    <!-- Process -->
                                    <td><span class="badge bg-secondary"><?= h($row['process_name'] ?? 'N/A') ?></span></td>
                                    <!-- Due Date -->
                                    <td><?= formatDate($row['due_date']) ?></td>
                                    <!-- FQA Status -->
                                    <td><span class="badge bg-info"><?= h($row['qc_status'] ?? 'Pending') ?></span></td>
                                    <!-- LE Status -->
                                    <td><span class="badge bg-info"><?= h($row['le_status'] ?? 'Pending') ?></span></td>
                                    
                                    <!-- Word Process Column -->
                                    <?php 
                                        $word_done = in_array($word_stat, ['Completed', 'W-Complete', 'ID_Complete']);
                                        $word_inprogress = (!$word_done && !empty($row['assigned_to_img']));
                                        $word_badge_cls = 'pending';
                                        if ($word_done) { $word_badge_cls = 'completed'; }
                                        elseif ($word_inprogress) { $word_badge_cls = 'in-progress'; }
                                    ?>
                                    <td class="assignment-cell" style="padding: 8px; vertical-align: top;">
                                        <div class="mb-1">
                                            <select class="form-select form-select-sm p-0 px-1" style="font-size: 11px; width: 130px; height: 22px; border-color: #ccc; background-color: #f9f9f9;" onchange="updateStatus(<?= $row['id'] ?>, 'word', this.value)">
                                                <option value="Need to Assign" <?= ($word_stat == 'Need to Assign') ? 'selected' : '' ?>>Need to Assign</option>
                                                <option value="W-In process" <?= ($word_stat == 'W-In process' || $word_stat == 'In Progress') ? 'selected' : '' ?>>W-In process</option>
                                                <option value="W-Complete" <?= ($word_stat == 'W-Complete' || $word_stat == 'Completed') ? 'selected' : '' ?>>W-Complete</option>
                                                <option value="ID_Complete" <?= ($word_stat == 'ID_Complete') ? 'selected' : '' ?>>ID_Complete</option>
                                            </select>
                                        </div>

                                        <?php if (!empty($row['assigned_to_img'])): ?>
                                            <div class="tm-badge <?= $word_badge_cls ?> mb-1" title="Word Process TM">
                                                <i class="fas fa-file-word"></i> <?= h($row['assigned_to_img']) ?>
                                            </div>
                                            <small class="text-muted d-block mb-1" style="font-weight: 600;">
                                                <?php 
                                                $found_name = '';
                                                foreach ($tms as $tm) { if ($tm['employee_username'] == $row['assigned_to_img']) { $found_name = $tm['employee_name']; break; } }
                                                echo h($found_name ?: $row['assigned_to_img']);
                                                ?>
                                            </small>
                                        <?php endif; ?>

                                        <select class="assign-select mt-1" onchange="quickAssign(<?= $row['id'] ?>, <?= $row['job_id'] ?>, 'word', this)">
                                            <option value=""><?= !empty($row['assigned_to_img']) ? 'Change TM' : 'Assign TM' ?></option>
                                            <?php foreach ($tms as $tm): ?>
                                                <option value="<?= h($tm['employee_username']) ?>" data-name="<?= h($tm['employee_name']) ?>" <?= ($row['assigned_to_img'] == $tm['employee_username']) ? 'selected' : '' ?>>
                                                    <?= h($tm['employee_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        
                                        <div class="d-flex gap-1 mt-2">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; width:50%; cursor:pointer;" title="Word Start" value="<?= !empty($row['img_start_time']) ? date('H:i', strtotime($row['img_start_time'])) : '' ?>" readonly onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'img_start_time', t, this);">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; width:50%; cursor:pointer;" title="Word End" value="<?= !empty($row['img_end_time']) ? date('H:i', strtotime($row['img_end_time'])) : '' ?>" readonly onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'img_end_time', t, this);">
                                        </div>
                                    </td>

                                    <!-- PAG Assignment Column -->
                                    <?php 
                                        $pag_done = in_array($pag_stat, ['Completed']);
                                        $pag_inprogress = (!$pag_done && !empty($row['assigned_to_pag']));
                                        $pag_curr_status = 'Need to Assign';
                                        $pag_badge_cls = 'pending';
                                        if ($pag_done) { $pag_curr_status = 'Completed'; $pag_badge_cls = 'completed'; }
                                        elseif ($pag_inprogress) { $pag_curr_status = 'In Progress'; $pag_badge_cls = 'in-progress'; }
                                    ?>
                                    <td class="assignment-cell" style="padding: 8px; vertical-align: top;">
                                        <div class="mb-1">
                                            <select class="form-select form-select-sm p-0 px-1" style="font-size: 11px; width: 130px; height: 22px; border-color: #ccc; background-color: #f9f9f9;" onchange="updateStatus(<?= $row['id'] ?>, 'pag', this.value)">
                                                <option value="Need to Assign" <?= ($pag_curr_status == 'Need to Assign') ? 'selected' : '' ?>>Need to Assign</option>
                                                <option value="In Progress" <?= ($pag_curr_status == 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                                                <option value="Completed" <?= ($pag_curr_status == 'Completed') ? 'selected' : '' ?>>Completed</option>
                                            </select>
                                        </div>

                                        <?php if (!empty($row['assigned_to_pag'])): ?>
                                            <div class="tm-badge <?= $pag_badge_cls ?> mb-1" title="PAG TM">
                                                <i class="fas fa-file-pdf"></i> <?= h($row['assigned_to_pag']) ?>
                                            </div>
                                            <small class="text-muted d-block mb-1" style="font-weight: 600;">
                                                <?php 
                                                $found_name = '';
                                                foreach ($tms as $tm) { if ($tm['employee_username'] == $row['assigned_to_pag']) { $found_name = $tm['employee_name']; break; } }
                                                echo h($found_name ?: $row['assigned_to_pag']);
                                                ?>
                                            </small>
                                        <?php endif; ?>

                                        <select class="assign-select mt-1" onchange="quickAssign(<?= $row['id'] ?>, <?= $row['job_id'] ?>, 'pag', this)">
                                            <option value=""><?= !empty($row['assigned_to_pag']) ? 'Change TM' : 'Assign TM' ?></option>
                                            <?php foreach ($tms as $tm): ?>
                                                <option value="<?= h($tm['employee_username']) ?>" data-name="<?= h($tm['employee_name']) ?>" <?= ($row['assigned_to_pag'] == $tm['employee_username']) ? 'selected' : '' ?>>
                                                    <?= h($tm['employee_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        
                                        <div class="d-flex gap-1 mt-2">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; width:50%; cursor:pointer;" title="PAG Start" value="<?= !empty($row['pag_start_time']) ? date('H:i', strtotime($row['pag_start_time'])) : '' ?>" readonly onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'pag_start_time', t, this);">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; width:50%; cursor:pointer;" title="PAG End" value="<?= !empty($row['pag_end_time']) ? date('H:i', strtotime($row['pag_end_time'])) : '' ?>" readonly onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'pag_end_time', t, this);">
                                        </div>
                                    </td>
                                    
                                    <!-- TAT -->
                                    <td class="fw-bold"><?= intval($row['rtat'] ?? 0) ?></td>
                                    <td class="fw-bold"><?= intval($row['atat'] ?? 0) ?></td>
                                    
                                    <!-- Path (Text Field) -->
                                    <td>
                                        <input type="text" class="form-control form-control-sm p-1" style="min-width: 150px; font-size: 11px;" placeholder="Enter Path" value="<?= h($row['path'] ?? '') ?>" onchange="updateInlineField(<?= $row['id'] ?>, 'path', this.value)">
                                    </td>
                                    
                                    <!-- Priority (Dropdown) -->
                                    <td>
                                        <select class="form-select form-select-sm p-1" style="min-width: 110px; font-size: 11px;" onchange="updateInlineField(<?= $row['id'] ?>, 'priority', this.value)">
                                            <option value="">Select Priority</option>
                                            <option value="Client Priority" <?= (($row['priority'] ?? '') == 'Client Priority') ? 'selected' : '' ?>>Client Priority</option>
                                            <option value="Urgent" <?= (($row['priority'] ?? '') == 'Urgent') ? 'selected' : '' ?>>Urgent</option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="18" class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <h6>No Pending TS Assignments</h6>
                                    <p class="text-muted small">All TS jobs have been assigned, completed, or are still waiting on CE to upload files.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination Info -->
        <?php if ($pag_result && $pag_result->num_rows > 0): ?>
            <div class="row mt-3">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="fas fa-list me-1"></i> Showing <?= $pag_result->num_rows ?> ready job(s)
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

        // Status Update Function
        function updateStatus(pagId, type, newStatus) {
            if (newStatus === 'Completed' || newStatus === 'W-Complete' || newStatus === 'ID_Complete') {
                let row = document.getElementById('row-' + pagId);
                if (row) {
                    let startTitle = (type === 'pag') ? 'PAG Start' : 'Word Start';
                    let endTitle = (type === 'pag') ? 'PAG End' : 'Word End';
                    let startInput = row.querySelector('input[title="' + startTitle + '"]');
                    let endInput = row.querySelector('input[title="' + endTitle + '"]');
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
            fetch('pag_assign_tm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=update_status&pag_id=' + pagId + '&type=' + encodeURIComponent(type) + '&status=' + encodeURIComponent(newStatus)
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

        // Inline Field Editor (For Time and Counts)
        function updateInlineField(pagId, field, value, element = null) {
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
            fetch('pag_assign_tm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=inline_edit&pag_id=' + pagId + '&field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(value)
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) { showNotification('success', 'Updated successfully!'); } 
                else { showNotification('danger', 'Update failed: ' + (data.message || 'Unknown error')); }
            }).catch(error => { hideLoading(); console.error('Error:', error); });
        }
        
        // Quick assign function
        function quickAssign(pagId, jobId, type, selectElement) {
            const selectedValue = selectElement.value;
            
            if (!selectedValue) return;
            
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const tmName = selectedOption.getAttribute('data-name') || selectedValue;
            const typeLabel = type === 'pag' ? 'TS' : 'Images';
            
           
            selectElement.setAttribute('data-previous', selectedValue);
            showLoading();
            selectElement.disabled = true;
            
            // Send AJAX
            fetch('?', { // Self-posting
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=quick_assign&pag_id=' + pagId + '&job_id=' + jobId + '&type=' + type + '&assigned_to=' + encodeURIComponent(selectedValue)
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                selectElement.disabled = false;
                
                if (data.success) {
                    showNotification('success', `${typeLabel} assigned to ${tmName} successfully!`);
                    document.getElementById('row-' + pagId).classList.add('assigned');
                    setTimeout(() => { window.location.reload(); }, 800);
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