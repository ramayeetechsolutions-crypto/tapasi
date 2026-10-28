<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'db.php';

// Auto-create table if not already created
$mysqli->query("CREATE TABLE IF NOT EXISTS `ap_assign` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `date` DATE DEFAULT NULL,
  `client` VARCHAR(150) DEFAULT NULL,
  `mail_subject` VARCHAR(255) DEFAULT NULL,
  `process` VARCHAR(100) DEFAULT NULL,
  `articles` VARCHAR(50) DEFAULT NULL,
  `process_member` VARCHAR(100) DEFAULT NULL,
  `process_status` VARCHAR(50) DEFAULT 'Need to Assign',
  `process_start_time` TIME DEFAULT NULL,
  `process_end_time` TIME DEFAULT NULL,
  `supplements` VARCHAR(100) DEFAULT NULL,
  `supplements_member` VARCHAR(100) DEFAULT NULL,
  `supplements_status` VARCHAR(50) DEFAULT 'Need to Assign',
  `supplements_start_time` TIME DEFAULT NULL,
  `supplements_end_time` TIME DEFAULT NULL,
  `note` TEXT DEFAULT NULL,
  `path` VARCHAR(500) DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT 'In Process',
  `remarks` VARCHAR(100) DEFAULT NULL,
  `start_time` TIME DEFAULT NULL,
  `end_time` TIME DEFAULT NULL,
  `total_hrs` VARCHAR(50) DEFAULT NULL,
  `dispatch_date` DATE DEFAULT NULL,
  `tat` VARCHAR(50) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

// Calculate TAT helper
function calculateTAT($received_date, $dispatch_date) {
    if (empty($received_date) || $received_date === '0000-00-00') return '';
    try {
        $start = new DateTime($received_date);
        if (!empty($dispatch_date) && $dispatch_date !== '0000-00-00') {
            $end = new DateTime($dispatch_date);
        } else {
            $end = new DateTime();
        }
        $diff = $start->diff($end);
        $days = (int)$diff->format('%r%a');
        return (string)$days;
    } catch (Exception $e) {
        return '';
    }
}

// Calculate Total Hours helper
function calculateTotalHrs($start_time, $end_time) {
    if (empty($start_time) || empty($end_time)) return '';
    $t1 = strtotime("1970-01-01 $start_time");
    $t2 = strtotime("1970-01-01 $end_time");
    if ($t1 === false || $t2 === false) return '';
    if ($t2 < $t1) {
        $t2 += 86400; // Next day
    }
    $diff = $t2 - $t1;
    $hrs = floor($diff / 3600);
    $mins = floor(($diff % 3600) / 60);
    return sprintf("%02d:%02d", $hrs, $mins);
}

// Hardcoded Lists from Excel
$PROCESS_OPTIONS = [
    'Heavy Corrections',
    'Minor Corrections',
    'AOP Corrections',
    'AOP Date Update',
    'InDesign Package',
    'Bleed Mark Application',
    'TOC Preparation',
    'Combined PDF Creation',
    'Combined Corrections',
    'DOI Processing'
];

$SUPPLEMENTS_OPTIONS = [
    'FM',
    'IMG',
    'Cover',
    'Logo',
    'TBL',
    'REF'
];

$STATUS_OPTIONS = [
    'In Process',
    'Completed',
    'Hold'
];

$REMARKS_OPTIONS = [
    'Hold',
    'Query Sent',
    'Urgent',
    'Send Today',
    'Client priority',
    'Before 11 AM',
    'Before 1 PM',
    'Before 5 PM',
    'Completed'
];

// Fetch active team client names from client_master
$clients_list = [];
$c_res = $mysqli->query("SELECT DISTINCT team_client_name, client_name FROM client_master WHERE status = 'Active' ORDER BY team_client_name");
if ($c_res) {
    while ($c = $c_res->fetch_assoc()) {
        $c_name = !empty(trim($c['team_client_name'] ?? '')) ? trim($c['team_client_name']) : trim($c['client_name'] ?? '');
        if (!empty($c_name) && !in_array($c_name, $clients_list)) {
            $clients_list[] = $c_name;
        }
    }
}

// Fetch mail subjects from mail_subject_master & CE jobs
$mail_subjects_list = [];
$m_res = $mysqli->query("SELECT mail_subject FROM mail_subject_master WHERE status = 'Active' ORDER BY mail_subject");
if ($m_res) {
    while ($m = $m_res->fetch_assoc()) {
        $ms = trim($m['mail_subject']);
        if (!empty($ms) && !in_array($ms, $mail_subjects_list)) {
            $mail_subjects_list[] = $ms;
        }
    }
}
$j_res = $mysqli->query("SELECT DISTINCT mail_subject FROM jobs WHERE mail_subject IS NOT NULL AND mail_subject != '' ORDER BY mail_subject");
if ($j_res) {
    while ($j = $j_res->fetch_assoc()) {
        $ms = trim($j['mail_subject']);
        if (!empty($ms) && !in_array($ms, $mail_subjects_list)) {
            $mail_subjects_list[] = $ms;
        }
    }
}
sort($mail_subjects_list);

// User Info & Team Members
$current_user_id = $_SESSION['uid'] ?? 0;
$reporting_manager = '';
if ($current_user_id) {
    $stmt = $mysqli->prepare("SELECT reporting_manager FROM employee_master WHERE employee_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $stmt->bind_result($reporting_manager);
        $stmt->fetch();
        $stmt->close();
    }
}

$tms = [];
if ($reporting_manager) {
    $tm_query = "SELECT em.employee_id, em.employee_username, em.employee_name 
                 FROM employee_master em 
                 JOIN user_role_master ur ON em.user_role_id = ur.role_id
                 WHERE em.reporting_manager = ? 
                 AND em.status = 'Active' 
                 ORDER BY em.employee_name";
    $stmt = $mysqli->prepare($tm_query);
    if ($stmt) {
        $stmt->bind_param("s", $reporting_manager);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($tm = $res->fetch_assoc()) {
            $tms[] = $tm;
        }
        $stmt->close();
    }
}

if (empty($tms)) {
    $res = $mysqli->query("SELECT employee_id, employee_username, employee_name FROM employee_master WHERE status = 'Active' ORDER BY employee_name");
    if ($res) {
        while ($tm = $res->fetch_assoc()) {
            $tms[] = $tm;
        }
    }
}

// AJAX: Update Status (Process or Supplements Member)
if (isset($_POST['ajax']) && $_POST['ajax'] === 'update_status') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $stage = $_POST['stage'] ?? ''; // 'process' or 'supplements'
    $status = trim($_POST['status'] ?? '');

    $col_status = ($stage === 'process') ? 'process_status' : 'supplements_status';
    $col_start  = ($stage === 'process') ? 'process_start_time' : 'supplements_start_time';
    $col_end    = ($stage === 'process') ? 'process_end_time' : 'supplements_end_time';

    // Mandatory check for Completed
    if ($status === 'Completed') {
        $chk = $mysqli->query("SELECT $col_start, $col_end FROM ap_assign WHERE id = $id")->fetch_assoc();
        if (empty($chk[$col_start]) || empty($chk[$col_end])) {
            echo json_encode(['success' => false, 'message' => "Please fill both Start Time and End Time before marking as Completed."]);
            exit;
        }
    }

    $stmt = $mysqli->prepare("UPDATE ap_assign SET $col_status = ?, last_updated = NOW() WHERE id = ?");
    $stmt->bind_param("si", $status, $id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
    exit;
}

// AJAX: Inline Edit
if (isset($_POST['ajax']) && $_POST['ajax'] === 'inline_edit') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $field = $_POST['field'] ?? '';
    $value = trim($_POST['value'] ?? '');

    $allowed = [
        'date', 'client', 'mail_subject', 'process', 'articles',
        'process_start_time', 'process_end_time',
        'supplements', 'supplements_start_time', 'supplements_end_time',
        'note', 'path', 'status', 'remarks',
        'start_time', 'end_time', 'total_hrs', 'dispatch_date', 'tat'
    ];

    if (!in_array($field, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Field not allowed']);
        exit;
    }

    // Auto recalculate TAT if date or dispatch_date changed
    if ($field === 'date' || $field === 'dispatch_date') {
        $cur = $mysqli->query("SELECT date, dispatch_date FROM ap_assign WHERE id = $id")->fetch_assoc();
        $r_date = ($field === 'date') ? $value : ($cur['date'] ?? '');
        $d_date = ($field === 'dispatch_date') ? $value : ($cur['dispatch_date'] ?? '');
        $new_tat = calculateTAT($r_date, $d_date);

        $stmt = $mysqli->prepare("UPDATE ap_assign SET `$field` = ?, tat = ?, last_updated = NOW() WHERE id = ?");
        $stmt->bind_param("ssi", $value, $new_tat, $id);
        $success = $stmt->execute();
        echo json_encode(['success' => $success, 'tat' => $new_tat]);
        exit;
    }

    // Auto recalculate Total Hrs if start_time or end_time changed
    if ($field === 'start_time' || $field === 'end_time') {
        $cur = $mysqli->query("SELECT start_time, end_time FROM ap_assign WHERE id = $id")->fetch_assoc();
        $st = ($field === 'start_time') ? $value : ($cur['start_time'] ?? '');
        $et = ($field === 'end_time') ? $value : ($cur['end_time'] ?? '');
        $new_hrs = calculateTotalHrs($st, $et);

        $stmt = $mysqli->prepare("UPDATE ap_assign SET `$field` = ?, total_hrs = ?, last_updated = NOW() WHERE id = ?");
        $stmt->bind_param("ssi", $value, $new_hrs, $id);
        $success = $stmt->execute();
        echo json_encode(['success' => $success, 'total_hrs' => $new_hrs]);
        exit;
    }

    $stmt = $mysqli->prepare("UPDATE ap_assign SET `$field` = ?, last_updated = NOW() WHERE id = ?");
    $stmt->bind_param("si", $value, $id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
    exit;
}

// AJAX: Quick Assign TM
if (isset($_POST['ajax']) && $_POST['ajax'] === 'quick_assign') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $type = $_POST['type'] ?? ''; // 'process' or 'supplements'
    $assigned_to = trim($_POST['assigned_to'] ?? '');

    $col_member = ($type === 'process') ? 'process_member' : 'supplements_member';
    $col_status = ($type === 'process') ? 'process_status' : 'supplements_status';

    // Auto promote to 'In Progress' if status was 'Need to Assign'
    $query = "UPDATE ap_assign SET 
              `$col_member` = ?, 
              `$col_status` = CASE WHEN `$col_status` = 'Need to Assign' THEN 'In Progress' ELSE `$col_status` END,
              last_updated = NOW() 
              WHERE id = ?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param("si", $assigned_to, $id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
    exit;
}

// Handle Form POST: Add Job
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_job') {
    $date          = !empty($_POST['date']) ? $_POST['date'] : date('Y-m-d');
    $client        = trim($_POST['client'] ?? '');
    $mail_subject  = trim($_POST['mail_subject'] ?? '');
    $process       = trim($_POST['process'] ?? '');
    $articles      = trim($_POST['articles'] ?? '');
    $supplements   = trim($_POST['supplements'] ?? '');
    $dispatch_date = !empty($_POST['dispatch_date']) ? $_POST['dispatch_date'] : null;
    $note          = trim($_POST['note'] ?? '');
    $path          = trim($_POST['path'] ?? '');
    $remarks       = trim($_POST['remarks'] ?? '');

    $tat = calculateTAT($date, $dispatch_date);

    $stmt = $mysqli->prepare("INSERT INTO ap_assign 
        (`date`, `client`, `mail_subject`, `process`, `articles`, `supplements`, `note`, `path`, `remarks`, `dispatch_date`, `tat`, `status`, `process_status`, `supplements_status`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'In Process', 'Need to Assign', 'Need to Assign')");
    $stmt->bind_param("sssssssssss", $date, $client, $mail_subject, $process, $articles, $supplements, $note, $path, $remarks, $dispatch_date, $tat);
    $stmt->execute();
    $stmt->close();

    header("Location: ap_assign.php");
    exit;
}

// Filtering & Searching
$search          = trim($_GET['search'] ?? '');
$status_filter   = trim($_GET['status'] ?? '');
$process_filter  = trim($_GET['process'] ?? '');
$process_tm      = trim($_GET['process_tm'] ?? '');
$supplements_tm  = trim($_GET['supplements_tm'] ?? '');
$sort            = trim($_GET['sort'] ?? 'id');

$where = ["1=1"];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "(client LIKE ? OR mail_subject LIKE ? OR process LIKE ? OR note LIKE ? OR path LIKE ? OR remarks LIKE ?)";
    $st = "%$search%";
    $params[] = $st; $params[] = $st; $params[] = $st; $params[] = $st; $params[] = $st; $params[] = $st;
    $types .= 'ssssss';
}

if (!empty($status_filter)) {
    $where[] = "(status = ? OR process_status = ? OR supplements_status = ?)";
    $params[] = $status_filter; $params[] = $status_filter; $params[] = $status_filter;
    $types .= 'sss';
}

if (!empty($process_filter)) {
    $where[] = "process = ?";
    $params[] = $process_filter;
    $types .= 's';
}

if (!empty($process_tm)) {
    $where[] = "process_member = ?";
    $params[] = $process_tm;
    $types .= 's';
}

if (!empty($supplements_tm)) {
    $where[] = "supplements_member = ?";
    $params[] = $supplements_tm;
    $types .= 's';
}

$where_sql = implode(' AND ', $where);

$allowed_sorts = [
    'id'            => 'id DESC',
    'date'          => 'date DESC',
    'dispatch_date' => 'dispatch_date DESC',
    'client'        => 'client ASC'
];
$order_sql = "ORDER BY " . ($allowed_sorts[$sort] ?? 'id DESC');

$stmt_list = $mysqli->prepare("SELECT * FROM ap_assign WHERE $where_sql $order_sql");
if (!empty($params)) {
    $stmt_list->bind_param($types, ...$params);
}
$stmt_list->execute();

// Use dedicated $ap_result so header.php will NOT overwrite it!
$ap_result = $stmt_list->get_result();

// Stats calculation (exact same 5-card pattern as WK TS Assign)
$stats = $mysqli->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN process_status = 'Need to Assign' OR supplements_status = 'Need to Assign' THEN 1 ELSE 0 END) as need_assign,
    SUM(CASE WHEN process_status = 'In Progress' OR supplements_status = 'In Progress' OR status = 'In Process' THEN 1 ELSE 0 END) as in_progress,
    SUM(CASE WHEN process_member IS NOT NULL AND process_member != '' THEN 1 ELSE 0 END) as process_assigned,
    SUM(CASE WHEN supplements_member IS NOT NULL AND supplements_member != '' THEN 1 ELSE 0 END) as supplements_assigned
    FROM ap_assign")->fetch_assoc();

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AP Assign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background-color: #f8fafc; }
        .page-header { background: white; border-bottom: 1px solid #e2e8f0; padding: 1rem 0; margin-bottom: 1.5rem; }
        .stats-card { border-radius: 12px; padding: 1rem; display: flex; align-items: center; gap: 1rem; border: 1px solid rgba(0,0,0,0.05); }
        .stats-card.card-total { background: #e0f2fe; }
        .stats-card.card-need { background: #fef3c7; }
        .stats-card.card-progress { background: #dbeafe; }
        .stats-card.card-content { background: #dcfce7; }
        .stats-card.card-images { background: #fae8ff; }
        .stats-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; background: rgba(255,255,255,0.7); }
        
        /* Exact table styling from WK TS Assign */
        .table thead th {
            background-color: #707981 !important; 
            color: white !important;
            font-weight: bold;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            padding: 0.8rem 0.5rem;
            border: 1px solid #5a6268 !important;
            text-align: center;
            vertical-align: middle;
            white-space: nowrap;
        }
        .table tbody td {
            padding: 0.4rem;
            font-size: 0.8rem;
            vertical-align: middle;
            border: 1px solid #cbd5e1;
            background: white;
        }
        .tm-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
            white-space: nowrap;
        }
        .tm-badge.in-progress { background-color: #f3e8ff; color: #7e22ce; border: 1px solid #d8b4fe; font-weight: 600; }
        .tm-badge.completed { background-color: #dcfce7; color: #15803d; border: 1px solid #86efac; font-weight: 600; }
        .editable-input { font-size: 11px; padding: 2px 4px; }
        
        /* Fast One-Click Time Stamp Inputs (No native clock dropdown popup) */
        .time-click-input {
            cursor: pointer;
            background-color: #ffffff;
            font-size: 10px;
            padding: 2px 4px;
            text-align: center;
            user-select: none;
            transition: background-color 0.15s, border-color 0.15s;
        }
        .time-click-input:hover {
            background-color: #eff6ff;
            border-color: #3b82f6;
        }

        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); display: none; justify-content: center; align-items: center; z-index: 9999; }
        .loading-overlay.active { display: flex; }
    </style>
</head>
<body>
    <br><br><br>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-border text-primary" role="status"></div>
    </div>

    <!-- Page Header (1:1 to WK TS Assign) -->
    <div class="page-header">
        <div class="container-fluid px-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0 fw-bold" style="color: #1e293b;">
                        <i class="fas fa-layer-group me-2" style="color: #1e4b85;"></i>AP Assign
                    </h4>
                </div>
                <div>
                    <button class="btn btn-primary btn-sm me-2" data-bs-toggle="modal" data-bs-target="#addJobModal">
                        <i class="fas fa-plus me-1"></i> Add AP Job
                    </button>
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
        <!-- Stats Row (Exact 5-Card Palette from WK TS Assign) -->
        <div class="row g-3 mb-3">
            <div class="col-md-2">
                <div class="stats-card card-total">
                    <div class="stats-icon text-primary"><i class="fas fa-list"></i></div>
                    <div><h4 class="mb-0 fw-bold"><?= $stats['total'] ?? 0 ?></h4><small class="text-muted">Total Pending</small></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card card-need">
                    <div class="stats-icon text-warning"><i class="fas fa-hourglass-start"></i></div>
                    <div><h4 class="mb-0 fw-bold"><?= $stats['need_assign'] ?? 0 ?></h4><small class="text-muted">Need to Assign</small></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card card-progress">
                    <div class="stats-icon text-info"><i class="fas fa-spinner"></i></div>
                    <div><h4 class="mb-0 fw-bold"><?= $stats['in_progress'] ?? 0 ?></h4><small class="text-muted">In Progress</small></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card card-content">
                    <div class="stats-icon text-success"><i class="fas fa-user-check"></i></div>
                    <div><h4 class="mb-0 fw-bold"><?= $stats['process_assigned'] ?? 0 ?></h4><small class="text-muted">Process Assigned</small></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card card-images">
                    <div class="stats-icon text-purple" style="color: #a21caf;"><i class="fas fa-check-double"></i></div>
                    <div><h4 class="mb-0 fw-bold"><?= $stats['supplements_assigned'] ?? 0 ?></h4><small class="text-muted">Supplements Assigned</small></div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="bg-white p-3 rounded-3 border mb-3 shadow-sm">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Search Client / Mail Subject</label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by Client / Subject..." value="<?= h($search) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <option value="Need to Assign" <?= ($status_filter === 'Need to Assign') ? 'selected' : '' ?>>Need to Assign</option>
                        <option value="In Progress" <?= ($status_filter === 'In Process' || $status_filter === 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                        <option value="Completed" <?= ($status_filter === 'Completed') ? 'selected' : '' ?>>Completed</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Process TM</label>
                    <select name="process_tm" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($tms as $tm): ?>
                            <option value="<?= h($tm['employee_username']) ?>" <?= ($process_tm === $tm['employee_username']) ? 'selected' : '' ?>>
                                <?= h($tm['employee_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Supplements TM</label>
                    <select name="supplements_tm" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($tms as $tm): ?>
                            <option value="<?= h($tm['employee_username']) ?>" <?= ($supplements_tm === $tm['employee_username']) ? 'selected' : '' ?>>
                                <?= h($tm['employee_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Sort By</label>
                    <select name="sort" class="form-select form-select-sm">
                        <option value="id" <?= ($sort === 'id') ? 'selected' : '' ?>>Created Date</option>
                        <option value="date" <?= ($sort === 'date') ? 'selected' : '' ?>>Date</option>
                        <option value="dispatch_date" <?= ($sort === 'dispatch_date') ? 'selected' : '' ?>>Dispatch Date</option>
                        <option value="client" <?= ($sort === 'client') ? 'selected' : '' ?>>Client</option>
                    </select>
                </div>
                <div class="col-12 mt-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter me-1"></i> Apply Filters
                    </button>
                    <a href="ap_assign.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-undo me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Main Format Table (17 Columns matching Excel) -->
        <div class="bg-white rounded-3 border overflow-hidden shadow-sm mb-4">
            <div class="table-responsive">
                <table class="table table-hover table-bordered mb-0" style="min-width: 2250px;">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Client</th>
                            <th>Mail Subject</th>
                            <th>Process</th>
                            <th>Articles</th>
                            <th style="min-width: 170px;">Process Member</th>
                            <th>Supplements</th>
                            <th style="min-width: 170px;">Suppl. Member</th>
                            <th>Note</th>
                            <th>Path</th>
                            <th>Status</th>
                            <th>Remarks</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Total Hrs</th>
                            <th>Dispatch Date</th>
                            <th>TAT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($ap_result && $ap_result->num_rows > 0): ?>
                            <?php while ($row = $ap_result->fetch_assoc()): ?>
                                <tr id="row-<?= $row['id'] ?>">
                                    <!-- 1. Date (Editable) -->
                                    <td>
                                        <input type="date" class="form-control form-control-sm editable-input" 
                                               value="<?= h($row['date'] ?? '') ?>" 
                                               onchange="updateField(<?= $row['id'] ?>, 'date', this.value)">
                                    </td>

                                    <!-- 2. Client (Dropdown from Client Master) -->
                                    <td>
                                        <select class="form-select form-select-sm editable-input" style="width: 150px;"
                                                onchange="updateField(<?= $row['id'] ?>, 'client', this.value)">
                                            <option value="">Select Client</option>
                                            <?php 
                                            $c_found = false;
                                            foreach ($clients_list as $cl): 
                                                $sel = (($row['client'] ?? '') === $cl);
                                                if ($sel) $c_found = true;
                                            ?>
                                                <option value="<?= h($cl) ?>" <?= $sel ? 'selected' : '' ?>><?= h($cl) ?></option>
                                            <?php endforeach; ?>
                                            <?php if (!$c_found && !empty($row['client'])): ?>
                                                <option value="<?= h($row['client']) ?>" selected><?= h($row['client']) ?></option>
                                            <?php endif; ?>
                                        </select>
                                    </td>

                                    <!-- 3. Mail Subject (Dropdown from Mail Subject Master / CE Job Entry) -->
                                    <td>
                                        <select class="form-select form-select-sm editable-input" style="width: 170px;"
                                                onchange="updateField(<?= $row['id'] ?>, 'mail_subject', this.value)">
                                            <option value="">Select Subject</option>
                                            <?php 
                                            $m_found = false;
                                            foreach ($mail_subjects_list as $ms): 
                                                $sel = (($row['mail_subject'] ?? '') === $ms);
                                                if ($sel) $m_found = true;
                                            ?>
                                                <option value="<?= h($ms) ?>" <?= $sel ? 'selected' : '' ?>><?= h($ms) ?></option>
                                            <?php endforeach; ?>
                                            <?php if (!$m_found && !empty($row['mail_subject'])): ?>
                                                <option value="<?= h($row['mail_subject']) ?>" selected><?= h($row['mail_subject']) ?></option>
                                            <?php endif; ?>
                                        </select>
                                    </td>

                                    <!-- 4. Process (Hardcoded Dropdown) -->
                                    <td>
                                        <select class="form-select form-select-sm editable-input" style="width: 170px;"
                                                onchange="updateField(<?= $row['id'] ?>, 'process', this.value)">
                                            <option value="">Select Process</option>
                                            <?php foreach ($PROCESS_OPTIONS as $p): ?>
                                                <option value="<?= h($p) ?>" <?= (($row['process'] ?? '') === $p) ? 'selected' : '' ?>>
                                                    <?= h($p) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>

                                    <!-- 5. Articles (Editable) -->
                                    <td>
                                        <input type="text" class="form-control form-control-sm editable-input text-center" style="width: 70px;"
                                               value="<?= h($row['articles'] ?? '') ?>" 
                                               placeholder="Articles"
                                               onchange="updateField(<?= $row['id'] ?>, 'articles', this.value)">
                                    </td>

                                    <!-- 6. Process Member (Assign Cell) -->
                                    <td>
                                        <?php 
                                            $p_st = $row['process_status'] ?? 'Need to Assign';
                                            $p_badge_cls = ($p_st === 'Completed') ? 'completed' : (($p_st === 'In Progress') ? 'in-progress' : '');
                                        ?>
                                        <select class="form-select form-select-sm p-0 px-1 mb-1" style="font-size: 11px; height: 22px;"
                                                onchange="updateStageStatus(<?= $row['id'] ?>, 'process', this.value)">
                                            <option value="Need to Assign" <?= ($p_st === 'Need to Assign') ? 'selected' : '' ?>>Need to Assign</option>
                                            <option value="In Progress" <?= ($p_st === 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                                            <option value="Completed" <?= ($p_st === 'Completed') ? 'selected' : '' ?>>Completed</option>
                                        </select>

                                        <?php if (!empty($row['process_member'])): ?>
                                            <div class="tm-badge <?= $p_badge_cls ?> mb-1">
                                                <i class="fas fa-user"></i> <?= h($row['process_member']) ?>
                                            </div>
                                        <?php endif; ?>

                                        <select class="form-select form-select-sm p-0 px-1" style="font-size: 11px;" 
                                                onchange="assignTM(<?= $row['id'] ?>, 'process', this.value)">
                                            <option value=""><?= !empty($row['process_member']) ? 'Change Process TM' : 'Assign Process TM' ?></option>
                                            <?php foreach ($tms as $tm): ?>
                                                <option value="<?= h($tm['employee_username']) ?>" <?= ($row['process_member'] == $tm['employee_username']) ? 'selected' : '' ?>>
                                                    <?= h($tm['employee_name']) ?> (<?= h($tm['employee_username']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <!-- One-Click Time Stamp (No dropdown popup) -->
                                        <div class="d-flex gap-1 mt-1">
                                            <input type="text" class="form-control form-control-sm p-1 time-click-input" title="Click to fill current time" 
                                                   value="<?= !empty($row['process_start_time']) ? date('H:i', strtotime($row['process_start_time'])) : '' ?>" 
                                                   placeholder="--:--" readonly 
                                                   onclick="stampCurrentTime(<?= $row['id'] ?>, 'process_start_time', this)">
                                            <input type="text" class="form-control form-control-sm p-1 time-click-input" title="Click to fill current time" 
                                                   value="<?= !empty($row['process_end_time']) ? date('H:i', strtotime($row['process_end_time'])) : '' ?>" 
                                                   placeholder="--:--" readonly 
                                                   onclick="stampCurrentTime(<?= $row['id'] ?>, 'process_end_time', this)">
                                        </div>
                                    </td>

                                    <!-- 7. Supplements (Hardcoded Dropdown) -->
                                    <td>
                                        <select class="form-select form-select-sm editable-input" style="width: 95px;"
                                                onchange="updateField(<?= $row['id'] ?>, 'supplements', this.value)">
                                            <option value="">Select</option>
                                            <?php foreach ($SUPPLEMENTS_OPTIONS as $s): ?>
                                                <option value="<?= h($s) ?>" <?= (($row['supplements'] ?? '') === $s) ? 'selected' : '' ?>>
                                                    <?= h($s) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>

                                    <!-- 8. Suppl. Member (Assign Cell) -->
                                    <td>
                                        <?php 
                                            $s_st = $row['supplements_status'] ?? 'Need to Assign';
                                            $s_badge_cls = ($s_st === 'Completed') ? 'completed' : (($s_st === 'In Progress') ? 'in-progress' : '');
                                        ?>
                                        <select class="form-select form-select-sm p-0 px-1 mb-1" style="font-size: 11px; height: 22px;"
                                                onchange="updateStageStatus(<?= $row['id'] ?>, 'supplements', this.value)">
                                            <option value="Need to Assign" <?= ($s_st === 'Need to Assign') ? 'selected' : '' ?>>Need to Assign</option>
                                            <option value="In Progress" <?= ($s_st === 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                                            <option value="Completed" <?= ($s_st === 'Completed') ? 'selected' : '' ?>>Completed</option>
                                        </select>

                                        <?php if (!empty($row['supplements_member'])): ?>
                                            <div class="tm-badge <?= $s_badge_cls ?> mb-1">
                                                <i class="fas fa-check-circle"></i> <?= h($row['supplements_member']) ?>
                                            </div>
                                        <?php endif; ?>

                                        <select class="form-select form-select-sm p-0 px-1" style="font-size: 11px;" 
                                                onchange="assignTM(<?= $row['id'] ?>, 'supplements', this.value)">
                                            <option value=""><?= !empty($row['supplements_member']) ? 'Change Suppl. TM' : 'Assign Suppl. TM' ?></option>
                                            <?php foreach ($tms as $tm): ?>
                                                <option value="<?= h($tm['employee_username']) ?>" <?= ($row['supplements_member'] == $tm['employee_username']) ? 'selected' : '' ?>>
                                                    <?= h($tm['employee_name']) ?> (<?= h($tm['employee_username']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <!-- One-Click Time Stamp (No dropdown popup) -->
                                        <div class="d-flex gap-1 mt-1">
                                            <input type="text" class="form-control form-control-sm p-1 time-click-input" title="Click to fill current time" 
                                                   value="<?= !empty($row['supplements_start_time']) ? date('H:i', strtotime($row['supplements_start_time'])) : '' ?>" 
                                                   placeholder="--:--" readonly 
                                                   onclick="stampCurrentTime(<?= $row['id'] ?>, 'supplements_start_time', this)">
                                            <input type="text" class="form-control form-control-sm p-1 time-click-input" title="Click to fill current time" 
                                                   value="<?= !empty($row['supplements_end_time']) ? date('H:i', strtotime($row['supplements_end_time'])) : '' ?>" 
                                                   placeholder="--:--" readonly 
                                                   onclick="stampCurrentTime(<?= $row['id'] ?>, 'supplements_end_time', this)">
                                        </div>
                                    </td>

                                    <!-- 9. Note (Editable) -->
                                    <td>
                                        <input type="text" class="form-control form-control-sm editable-input" style="width: 120px;"
                                               value="<?= h($row['note'] ?? '') ?>" 
                                               placeholder="Note..."
                                               onchange="updateField(<?= $row['id'] ?>, 'note', this.value)">
                                    </td>

                                    <!-- 10. Path (Editable) -->
                                    <td>
                                        <input type="text" class="form-control form-control-sm editable-input" style="width: 140px;"
                                               value="<?= h($row['path'] ?? '') ?>" 
                                               placeholder="Folder/File Path"
                                               onchange="updateField(<?= $row['id'] ?>, 'path', this.value)">
                                    </td>

                                    <!-- 11. Status (Hardcoded Dropdown) -->
                                    <td>
                                        <select class="form-select form-select-sm editable-input" style="width: 110px;"
                                                onchange="updateField(<?= $row['id'] ?>, 'status', this.value)">
                                            <?php foreach ($STATUS_OPTIONS as $st): ?>
                                                <option value="<?= h($st) ?>" <?= (($row['status'] ?? 'In Process') === $st) ? 'selected' : '' ?>>
                                                    <?= h($st) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>

                                    <!-- 12. Remarks (Editable Dropdown) -->
                                    <td>
                                        <select class="form-select form-select-sm editable-input" style="width: 110px;"
                                                onchange="updateField(<?= $row['id'] ?>, 'remarks', this.value)">
                                            <option value="">Select</option>
                                            <?php foreach ($REMARKS_OPTIONS as $o): ?>
                                                <option value="<?= h($o) ?>" <?= (($row['remarks'] ?? '') === $o) ? 'selected' : '' ?>>
                                                    <?= h($o) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>

                                    <!-- 13. Start Time (One-Click Timestamp - No clock dropdown) -->
                                    <td>
                                        <input type="text" id="start-time-<?= $row['id'] ?>" 
                                               class="form-control form-control-sm time-click-input" style="width: 75px;" 
                                               title="Click to fill current time"
                                               value="<?= !empty($row['start_time']) ? date('H:i', strtotime($row['start_time'])) : '' ?>" 
                                               placeholder="--:--" readonly 
                                               onclick="stampCurrentTime(<?= $row['id'] ?>, 'start_time', this)">
                                    </td>

                                    <!-- 14. End Time (One-Click Timestamp - No clock dropdown) -->
                                    <td>
                                        <input type="text" id="end-time-<?= $row['id'] ?>" 
                                               class="form-control form-control-sm time-click-input" style="width: 75px;" 
                                               title="Click to fill current time"
                                               value="<?= !empty($row['end_time']) ? date('H:i', strtotime($row['end_time'])) : '' ?>" 
                                               placeholder="--:--" readonly 
                                               onclick="stampCurrentTime(<?= $row['id'] ?>, 'end_time', this)">
                                    </td>

                                    <!-- 15. Total Hrs (Auto-calculated) -->
                                    <td>
                                        <input type="text" id="total-hrs-<?= $row['id'] ?>" 
                                               class="form-control form-control-sm editable-input text-center bg-light" style="width: 65px;"
                                               value="<?= h($row['total_hrs'] ?? '') ?>" 
                                               placeholder="--" readonly>
                                    </td>

                                    <!-- 16. Dispatch Date (Editable) -->
                                    <td>
                                        <input type="date" class="form-control form-control-sm editable-input" 
                                               value="<?= h($row['dispatch_date'] ?? '') ?>" 
                                               onchange="updateField(<?= $row['id'] ?>, 'dispatch_date', this.value)">
                                    </td>

                                    <!-- 17. TAT (Auto-calculated) -->
                                    <td>
                                        <input type="text" id="tat-<?= $row['id'] ?>" 
                                               class="form-control form-control-sm editable-input text-center bg-light fw-bold" style="width: 55px;"
                                               value="<?= h($row['tat'] ?? '') ?>" 
                                               placeholder="--" readonly>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="17" class="text-center py-4 text-muted">
                                    <i class="fas fa-layer-group fa-2x mb-2 d-block"></i>
                                    No AP entries found. Click "Add AP Job" to create your first entry.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal to Add New AP Job Entry -->
    <div class="modal fade" id="addJobModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" action="ap_assign.php">
                    <input type="hidden" name="action" value="add_job">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold" style="color: #1e293b;">
                            <i class="fas fa-plus-circle text-primary me-2"></i>Add New AP Job Entry
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Date <span class="text-danger">*</span></label>
                            <input type="date" name="date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Client <span class="text-danger">*</span></label>
                            <select name="client" class="form-select form-select-sm" required>
                                <option value="">Select Client</option>
                                <?php foreach ($clients_list as $cl): ?>
                                    <option value="<?= h($cl) ?>"><?= h($cl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Mail Subject <span class="text-danger">*</span></label>
                            <select name="mail_subject" class="form-select form-select-sm" required>
                                <option value="">Select Mail Subject</option>
                                <?php foreach ($mail_subjects_list as $ms): ?>
                                    <option value="<?= h($ms) ?>"><?= h($ms) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Process</label>
                            <select name="process" class="form-select form-select-sm">
                                <option value="">Select Process</option>
                                <?php foreach ($PROCESS_OPTIONS as $p): ?>
                                    <option value="<?= h($p) ?>"><?= h($p) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Articles</label>
                            <input type="text" name="articles" class="form-control form-control-sm" placeholder="e.g. 5">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Supplements</label>
                            <select name="supplements" class="form-select form-select-sm">
                                <option value="">Select Supplement</option>
                                <?php foreach ($SUPPLEMENTS_OPTIONS as $s): ?>
                                    <option value="<?= h($s) ?>"><?= h($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Dispatch Date</label>
                            <input type="date" name="dispatch_date" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Remarks</label>
                            <select name="remarks" class="form-select form-select-sm">
                                <option value="">Select Remarks</option>
                                <?php foreach ($REMARKS_OPTIONS as $o): ?>
                                    <option value="<?= h($o) ?>"><?= h($o) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Note</label>
                            <input type="text" name="note" class="form-control form-control-sm" placeholder="Any special notes">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Folder / File Path</label>
                            <input type="text" name="path" class="form-control form-control-sm" placeholder="Enter server or folder path">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm px-3">
                            <i class="fas fa-save me-1"></i> Save AP Job
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function showLoading() { document.getElementById('loadingOverlay').classList.add('active'); }
        function hideLoading() { document.getElementById('loadingOverlay').classList.remove('active'); }

        // Field update via AJAX
        function updateField(id, field, value, callback) {
            showLoading();
            fetch('ap_assign.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `ajax=inline_edit&id=${id}&field=${encodeURIComponent(field)}&value=${encodeURIComponent(value)}`
            })
            .then(res => res.json())
            .then(data => {
                hideLoading();
                if (!data.success) {
                    alert('Error updating: ' + (data.message || ''));
                } else {
                    if (data.tat !== undefined) {
                        const tatElem = document.getElementById(`tat-${id}`);
                        if (tatElem) tatElem.value = data.tat;
                    }
                    if (data.total_hrs !== undefined) {
                        const hrsElem = document.getElementById(`total-hrs-${id}`);
                        if (hrsElem) hrsElem.value = data.total_hrs;
                    }
                    if (typeof callback === 'function') callback(data);
                }
            })
            .catch(() => { hideLoading(); });
        }

        // TM Assignment via AJAX
        function assignTM(id, type, assignedTo) {
            if (!assignedTo) return;
            showLoading();
            fetch('ap_assign.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `ajax=quick_assign&id=${id}&type=${type}&assigned_to=${encodeURIComponent(assignedTo)}`
            })
            .then(res => res.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(() => { hideLoading(); });
        }

        // Stage Status Update (Process / Supplements)
        function updateStageStatus(id, stage, status) {
            showLoading();
            fetch('ap_assign.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `ajax=update_status&id=${id}&stage=${stage}&status=${encodeURIComponent(status)}`
            })
            .then(res => res.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Error updating status');
                    location.reload();
                }
            })
            .catch(() => { hideLoading(); });
        }

        // One-Click Time Stamp (NEVER shows dropdown/clock picker)
        function stampCurrentTime(id, field, elem) {
            const now = new Date();
            const hh = String(now.getHours()).padStart(2, '0');
            const mm = String(now.getMinutes()).padStart(2, '0');
            const timeStr = `${hh}:${mm}`;

            elem.value = timeStr;
            updateField(id, field, timeStr);
        }
    </script>
</body>
</html>
