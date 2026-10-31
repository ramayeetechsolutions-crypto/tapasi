<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'db.php';

// Auto-create table if not already created
$mysqli->query("CREATE TABLE IF NOT EXISTS `wk_ts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `job_id` INT(11) DEFAULT NULL,
  `qc_completed_date` DATE DEFAULT NULL,
  `pag_completed_date` DATE DEFAULT NULL,
  `received_date` DATE DEFAULT NULL,
  `issue` VARCHAR(255) DEFAULT NULL,
  `articles` VARCHAR(255) DEFAULT NULL,
  `pages` VARCHAR(50) DEFAULT '0',
  `assigned_pag` VARCHAR(100) DEFAULT NULL,
  `pag_status` VARCHAR(50) DEFAULT 'Need to Assign',
  `pag_start_time` TIME DEFAULT NULL,
  `pag_end_time` TIME DEFAULT NULL,
  `assigned_qc` VARCHAR(100) DEFAULT NULL,
  `qc_status` VARCHAR(50) DEFAULT 'Need to Assign',
  `qc_start_time` TIME DEFAULT NULL,
  `qc_end_time` TIME DEFAULT NULL,
  `due_date` DATE DEFAULT NULL,
  `r_tat` VARCHAR(50) DEFAULT NULL,
  `status1` VARCHAR(100) DEFAULT 'In Progress',
  `file_path` VARCHAR(500) DEFAULT NULL,
  `status2` VARCHAR(100) DEFAULT 'In Progress',
  `remarks` VARCHAR(255) DEFAULT NULL,
  `tat` VARCHAR(50) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

// User Info
$current_user_id = $_SESSION['uid'] ?? 0;
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

// Determine effective reporting manager (if user is TL/Admin without RM, use their own name)
$effective_manager = !empty($reporting_manager) ? $reporting_manager : $current_user_name;

// Fetch active TMs
$tms = [];
if ($effective_manager) {
    $tm_query = "SELECT em.employee_id, em.employee_username, em.employee_name 
                 FROM employee_master em 
                 JOIN user_role_master ur ON em.user_role_id = ur.role_id
                 WHERE (em.reporting_manager = ? OR em.reporting_manager = ?) 
                 AND em.status = 'Active' 
                 ORDER BY em.employee_name";
    $stmt = $mysqli->prepare($tm_query);
    if ($stmt) {
        $stmt->bind_param("ss", $effective_manager, $current_user_name);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($tm = $res->fetch_assoc()) {
            $tms[] = $tm;
        }
        $stmt->close();
    }
}

// AJAX: Update Status (PAG or QC)
if (isset($_POST['ajax']) && $_POST['ajax'] == 'update_status') {
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];
    $stage = $_POST['stage']; // 'pag' or 'qc'
    $status = trim($_POST['status']);

    $col_status = ($stage === 'pag') ? 'pag_status' : 'qc_status';
    $col_start  = ($stage === 'pag') ? 'pag_start_time' : 'qc_start_time';
    $col_end    = ($stage === 'pag') ? 'pag_end_time' : 'qc_end_time';

    // Mandatory check for Completed
    if ($status === 'Completed') {
        $chk = $mysqli->query("SELECT $col_start, $col_end FROM wk_ts WHERE id = $id")->fetch_assoc();
        if (empty($chk[$col_start]) || empty($chk[$col_end])) {
            echo json_encode(['success' => false, 'message' => "Please fill both Start Time and End Time before marking as Completed."]);
            exit;
        }
    }

    $stmt = $mysqli->prepare("UPDATE wk_ts SET $col_status = ?, last_updated = NOW() WHERE id = ?");
    $stmt->bind_param("si", $status, $id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
    }
    exit;
}

// AJAX: Inline Edit
if (isset($_POST['ajax']) && $_POST['ajax'] == 'inline_edit') {
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];
    $field = $_POST['field'];
    $val = $_POST['value'];

    $allowed = [
        'qc_completed_date', 'pag_completed_date', 'received_date', 'issue', 'articles',
        'pages', 'due_date', 'r_tat', 'status1', 'file_path', 'status2', 'remarks', 'tat',
        'pag_start_time', 'pag_end_time', 'qc_start_time', 'qc_end_time'
    ];

    if (in_array($field, $allowed)) {
        $stmt = $mysqli->prepare("UPDATE wk_ts SET $field = ?, last_updated = NOW() WHERE id = ?");
        $stmt->bind_param("si", $val, $id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => $mysqli->error]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid Field']);
    }
    exit;
}

// AJAX: Quick Assign TM
if (isset($_POST['ajax']) && $_POST['ajax'] == 'quick_assign') {
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];
    $type = $_POST['type']; // 'pag' or 'qc'
    $assigned_to = trim($_POST['assigned_to']);

    $col_tm = ($type === 'pag') ? 'assigned_pag' : 'assigned_qc';
    $col_st = ($type === 'pag') ? 'pag_status' : 'qc_status';

    $stmt = $mysqli->prepare("UPDATE wk_ts SET $col_tm = ?, $col_st = CASE WHEN $col_st = 'Need to Assign' THEN 'In Progress' ELSE $col_st END, last_updated = NOW() WHERE id = ?");
    $stmt->bind_param("si", $assigned_to, $id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
    }
    exit;
}

// Add New Job Action
if (isset($_POST['action']) && $_POST['action'] === 'add_job') {
    $rec_date   = !empty($_POST['received_date']) ? $_POST['received_date'] : date('Y-m-d');
    $issue      = trim($_POST['issue'] ?? '');
    $articles   = trim($_POST['articles'] ?? '');
    $pages      = trim($_POST['pages'] ?? '0');
    $due_date   = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $r_tat      = trim($_POST['r_tat'] ?? '');
    $file_path  = trim($_POST['file_path'] ?? '');
    $remarks    = trim($_POST['remarks'] ?? '');

    $stmt = $mysqli->prepare("INSERT INTO wk_ts (received_date, issue, articles, pages, due_date, r_tat, file_path, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssss", $rec_date, $issue, $articles, $pages, $due_date, $r_tat, $file_path, $remarks);
    $stmt->execute();
    header("Location: wk_ts_assign.php?msg=Job added successfully&type=success");
    exit;
}

// Filter query
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$pag_tm_filter = trim($_GET['pag_tm'] ?? '');
$qc_tm_filter = trim($_GET['qc_tm'] ?? '');
$sort = trim($_GET['sort'] ?? 'id');

$where = ["1=1"];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "(issue LIKE ? OR articles LIKE ? OR remarks LIKE ? OR file_path LIKE ?)";
    $st = "%$search%";
    $params[] = $st; $params[] = $st; $params[] = $st; $params[] = $st;
    $types .= 'ssss';
}

if (!empty($status_filter)) {
    $where[] = "(pag_status = ? OR qc_status = ? OR status1 = ?)";
    $params[] = $status_filter;
    $params[] = $status_filter;
    $params[] = $status_filter;
    $types .= 'sss';
}

if (!empty($pag_tm_filter)) {
    $where[] = "assigned_pag = ?";
    $params[] = $pag_tm_filter;
    $types .= 's';
}

if (!empty($qc_tm_filter)) {
    $where[] = "assigned_qc = ?";
    $params[] = $qc_tm_filter;
    $types .= 's';
}

$where_sql = implode(' AND ', $where);
$order_sql = "ORDER BY $sort DESC";

$stmt_list = $mysqli->prepare("SELECT * FROM wk_ts WHERE $where_sql $order_sql");
if (!empty($params)) {
    $stmt_list->bind_param($types, ...$params);
}
$stmt_list->execute();
$ts_result = $stmt_list->get_result();

// Stats calculation
$stats = $mysqli->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN pag_status = 'Need to Assign' OR qc_status = 'Need to Assign' THEN 1 ELSE 0 END) as need_assign,
    SUM(CASE WHEN pag_status = 'In Progress' OR qc_status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
    SUM(CASE WHEN assigned_pag IS NOT NULL AND assigned_pag != '' THEN 1 ELSE 0 END) as pag_assigned,
    SUM(CASE WHEN assigned_qc IS NOT NULL AND assigned_qc != '' THEN 1 ELSE 0 END) as qc_assigned
    FROM wk_ts")->fetch_assoc();

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WK TS Assign</title>
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
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); display: none; justify-content: center; align-items: center; z-index: 9999; }
        .loading-overlay.active { display: flex; }
    </style>
</head>
<body>
    <br><br><br>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-border text-primary" role="status"></div>
    </div>

    <div class="page-header">
        <div class="container-fluid px-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0 fw-bold" style="color: #1e293b;">
                        <i class="fas fa-tasks me-2" style="color: #1e4b85;"></i>WK TS Assign
                    </h4>
                </div>
                <div>
                    <button class="btn btn-primary btn-sm me-2" data-bs-toggle="modal" data-bs-target="#addJobModal">
                        <i class="fas fa-plus me-1"></i> Add WK TS Job
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
        <!-- Stats Row -->
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
                    <div><h4 class="mb-0 fw-bold"><?= $stats['pag_assigned'] ?? 0 ?></h4><small class="text-muted">PAG Assigned</small></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card card-images">
                    <div class="stats-icon text-purple" style="color: #a21caf;"><i class="fas fa-check-double"></i></div>
                    <div><h4 class="mb-0 fw-bold"><?= $stats['qc_assigned'] ?? 0 ?></h4><small class="text-muted">QC Assigned</small></div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="bg-white p-3 rounded-3 border mb-3 shadow-sm">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Search Issue / Article</label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by Issue / Article..." value="<?= h($search) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <option value="Need to Assign" <?= ($status_filter === 'Need to Assign') ? 'selected' : '' ?>>Need to Assign</option>
                        <option value="In Progress" <?= ($status_filter === 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                        <option value="Completed" <?= ($status_filter === 'Completed') ? 'selected' : '' ?>>Completed</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">PAG TM</label>
                    <select name="pag_tm" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($tms as $tm): ?>
                            <option value="<?= h($tm['employee_username']) ?>" <?= ($pag_tm_filter === $tm['employee_username']) ? 'selected' : '' ?>>
                                <?= h($tm['employee_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">QC TM</label>
                    <select name="qc_tm" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($tms as $tm): ?>
                            <option value="<?= h($tm['employee_username']) ?>" <?= ($qc_tm_filter === $tm['employee_username']) ? 'selected' : '' ?>>
                                <?= h($tm['employee_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Sort By</label>
                    <select name="sort" class="form-select form-select-sm">
                        <option value="id" <?= ($sort === 'id') ? 'selected' : '' ?>>Created Date</option>
                        <option value="due_date" <?= ($sort === 'due_date') ? 'selected' : '' ?>>Due Date</option>
                        <option value="issue" <?= ($sort === 'issue') ? 'selected' : '' ?>>Issue</option>
                    </select>
                </div>
                <div class="col-12 mt-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter me-1"></i> Apply Filters
                    </button>
                    <a href="wk_ts_assign.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-undo me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Main Format Table (Exact Format from Image 3) -->
        <div class="bg-white rounded-3 border overflow-hidden shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover table-bordered mb-0" style="min-width: 1950px;">
                    <thead>
                        <tr>
                            <th>QC Completed Date</th>
                            <th>PAG Completed Date</th>
                            <th>Recevied Date</th>
                            <th>Issue</th>
                            <th>Articles</th>
                            <th>Pages</th>
                            <th style="min-width: 170px;">PAG</th>
                            <th style="min-width: 170px;">QC</th>
                            <th>Due Date</th>
                            <th>R-TAT</th>
                            <th>STATUS</th>
                            <th>Path</th>
                            <th>Status</th>
                            <th>Remarks</th>
                            <th>TAT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($ts_result && $ts_result->num_rows > 0): ?>
                            <?php while ($row = $ts_result->fetch_assoc()): ?>
                                <tr id="row-<?= $row['id'] ?>">
                                    <!-- 1. QC Completed Date (Editable) -->
                                    <td>
                                        <input type="date" class="form-control form-control-sm editable-input" 
                                               value="<?= h($row['qc_completed_date'] ?? '') ?>" 
                                               onchange="updateField(<?= $row['id'] ?>, 'qc_completed_date', this.value)">
                                    </td>

                                    <!-- 2. PAG Completed Date (Editable) -->
                                    <td>
                                        <input type="date" class="form-control form-control-sm editable-input" 
                                               value="<?= h($row['pag_completed_date'] ?? '') ?>" 
                                               onchange="updateField(<?= $row['id'] ?>, 'pag_completed_date', this.value)">
                                    </td>

                                    <!-- 3. Recevied Date (Editable) -->
                                    <td>
                                        <input type="date" class="form-control form-control-sm editable-input" 
                                               value="<?= h($row['received_date'] ?? '') ?>" 
                                               onchange="updateField(<?= $row['id'] ?>, 'received_date', this.value)">
                                    </td>

                                    <!-- 4. Issue (Editable) -->
                                    <td>
                                        <input type="text" class="form-control form-control-sm editable-input" style="width: 130px;"
                                               value="<?= h($row['issue'] ?? '') ?>" 
                                               onchange="updateField(<?= $row['id'] ?>, 'issue', this.value)">
                                    </td>

                                    <!-- 5. Articles (Editable) -->
                                    <td>
                                        <input type="text" class="form-control form-control-sm editable-input" style="width: 130px;"
                                               value="<?= h($row['articles'] ?? '') ?>" 
                                               onchange="updateField(<?= $row['id'] ?>, 'articles', this.value)">
                                    </td>

                                    <!-- 6. Pages (Editable) -->
                                    <td>
                                        <input type="number" class="form-control form-control-sm editable-input text-center fw-bold" style="width: 70px;"
                                               value="<?= h($row['pages'] ?? '0') ?>" 
                                               onchange="updateField(<?= $row['id'] ?>, 'pages', this.value)">
                                    </td>

                                    <!-- 7. PAG (AS per CE Assignment) -->
                                    <td>
                                        <?php 
                                            $pag_st = $row['pag_status'] ?? 'Need to Assign';
                                            $pag_badge_cls = ($pag_st === 'Completed') ? 'completed' : (($pag_st === 'In Progress') ? 'in-progress' : '');
                                        ?>
                                        <select class="form-select form-select-sm p-0 px-1 mb-1" style="font-size: 11px; height: 22px;"
                                                onchange="updateStageStatus(<?= $row['id'] ?>, 'pag', this.value)">
                                            <option value="Need to Assign" <?= ($pag_st == 'Need to Assign') ? 'selected' : '' ?>>Need to Assign</option>
                                            <option value="In Progress" <?= ($pag_st == 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                                            <option value="Completed" <?= ($pag_st == 'Completed') ? 'selected' : '' ?>>Completed</option>
                                        </select>

                                        <?php if (!empty($row['assigned_pag'])): ?>
                                            <div class="tm-badge <?= $pag_badge_cls ?> mb-1">
                                                <i class="fas fa-user"></i> <?= h($row['assigned_pag']) ?>
                                            </div>
                                        <?php endif; ?>

                                        <select class="form-select form-select-sm p-0 px-1" style="font-size: 11px;" 
                                                onchange="assignTM(<?= $row['id'] ?>, 'pag', this.value)">
                                            <option value=""><?= !empty($row['assigned_pag']) ? 'Change PAG TM' : 'Assign PAG' ?></option>
                                            <?php foreach ($tms as $tm): ?>
                                                <option value="<?= h($tm['employee_username']) ?>" <?= ($row['assigned_pag'] == $tm['employee_username']) ? 'selected' : '' ?>>
                                                    <?= h($tm['employee_name']) ?> (<?= h($tm['employee_username']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <div class="d-flex gap-1 mt-1">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; cursor:pointer;" title="PAG Start" 
                                                   value="<?= !empty($row['pag_start_time']) ? date('H:i', strtotime($row['pag_start_time'])) : '' ?>" 
                                                   readonly onclick="const t=new Date().toTimeString().slice(0,5); this.value=t; updateField(<?= $row['id'] ?>, 'pag_start_time', t);">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; cursor:pointer;" title="PAG End" 
                                                   value="<?= !empty($row['pag_end_time']) ? date('H:i', strtotime($row['pag_end_time'])) : '' ?>" 
                                                   readonly onclick="const t=new Date().toTimeString().slice(0,5); this.value=t; updateField(<?= $row['id'] ?>, 'pag_end_time', t);">
                                        </div>
                                    </td>

                                    <!-- 8. QC (AS per CE Assignment) -->
                                    <td>
                                        <?php 
                                            $qc_st = $row['qc_status'] ?? 'Need to Assign';
                                            $qc_badge_cls = ($qc_st === 'Completed') ? 'completed' : (($qc_st === 'In Progress') ? 'in-progress' : '');
                                        ?>
                                        <select class="form-select form-select-sm p-0 px-1 mb-1" style="font-size: 11px; height: 22px;"
                                                onchange="updateStageStatus(<?= $row['id'] ?>, 'qc', this.value)">
                                            <option value="Need to Assign" <?= ($qc_st == 'Need to Assign') ? 'selected' : '' ?>>Need to Assign</option>
                                            <option value="In Progress" <?= ($qc_st == 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                                            <option value="Completed" <?= ($qc_st == 'Completed') ? 'selected' : '' ?>>Completed</option>
                                        </select>

                                        <?php if (!empty($row['assigned_qc'])): ?>
                                            <div class="tm-badge <?= $qc_badge_cls ?> mb-1">
                                                <i class="fas fa-check-circle"></i> <?= h($row['assigned_qc']) ?>
                                            </div>
                                        <?php endif; ?>

                                        <select class="form-select form-select-sm p-0 px-1" style="font-size: 11px;" 
                                                onchange="assignTM(<?= $row['id'] ?>, 'qc', this.value)">
                                            <option value=""><?= !empty($row['assigned_qc']) ? 'Change QC TM' : 'Assign QC' ?></option>
                                            <?php foreach ($tms as $tm): ?>
                                                <option value="<?= h($tm['employee_username']) ?>" <?= ($row['assigned_qc'] == $tm['employee_username']) ? 'selected' : '' ?>>
                                                    <?= h($tm['employee_name']) ?> (<?= h($tm['employee_username']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <div class="d-flex gap-1 mt-1">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; cursor:pointer;" title="QC Start" 
                                                   value="<?= !empty($row['qc_start_time']) ? date('H:i', strtotime($row['qc_start_time'])) : '' ?>" 
                                                   readonly onclick="const t=new Date().toTimeString().slice(0,5); this.value=t; updateField(<?= $row['id'] ?>, 'qc_start_time', t);">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; cursor:pointer;" title="QC End" 
                                                   value="<?= !empty($row['qc_end_time']) ? date('H:i', strtotime($row['qc_end_time'])) : '' ?>" 
                                                   readonly onclick="const t=new Date().toTimeString().slice(0,5); this.value=t; updateField(<?= $row['id'] ?>, 'qc_end_time', t);">
                                        </div>
                                    </td>

                                    <!-- 9. Due Date (Editable) -->
                                    <td>
                                        <input type="date" class="form-control form-control-sm editable-input" 
                                               value="<?= h($row['due_date'] ?? '') ?>" 
                                               onchange="updateField(<?= $row['id'] ?>, 'due_date', this.value)">
                                    </td>

                                    <!-- 10. R-TAT (Editable) -->
                                    <td>
                                        <input type="text" class="form-control form-control-sm editable-input text-center" style="width: 70px;"
                                               value="<?= h($row['r_tat'] ?? '') ?>" 
                                               onchange="updateField(<?= $row['id'] ?>, 'r_tat', this.value)">
                                    </td>

                                    <!-- 11. STATUS (Editable Dropdown) -->
                                    <td>
                                        <select class="form-select form-select-sm editable-input" style="width: 105px;"
                                                onchange="updateField(<?= $row['id'] ?>, 'status1', this.value)">
                                            <option value="In Progress" <?= ($row['status1'] ?? '') === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                            <option value="Completed" <?= ($row['status1'] ?? '') === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                            <option value="Hold" <?= ($row['status1'] ?? '') === 'Hold' ? 'selected' : '' ?>>Hold</option>
                                        </select>
                                    </td>

                                    <!-- 12. Path (Editable) -->
                                    <td>
                                        <input type="text" class="form-control form-control-sm editable-input" style="width: 150px;"
                                               value="<?= h($row['file_path'] ?? '') ?>" 
                                               placeholder="Folder/File Path"
                                               onchange="updateField(<?= $row['id'] ?>, 'file_path', this.value)">
                                    </td>

                                    <!-- 13. Status (Editable) -->
                                    <td>
                                        <input type="text" class="form-control form-control-sm editable-input" style="width: 90px;"
                                               value="<?= h($row['status2'] ?? 'In Progress') ?>" 
                                               onchange="updateField(<?= $row['id'] ?>, 'status2', this.value)">
                                    </td>

                                    <!-- 14. Remarks (Editable Dropdown) -->
                                    <td>
                                        <select class="form-select form-select-sm editable-input" style="width: 110px;"
                                                onchange="updateField(<?= $row['id'] ?>, 'remarks', this.value)">
                                            <option value="">Select</option>
                                            <?php 
                                            $opts = ['Hold', 'Query Sent', 'Urgent', 'Send Today', 'Client priority', 'Before 11 AM', 'Before 1 PM', 'Before 5 PM', 'Completed'];
                                            foreach($opts as $o) {
                                                $sel = (($row['remarks'] ?? '') === $o) ? 'selected' : '';
                                                echo "<option value=\"".h($o)."\" $sel>".h($o)."</option>";
                                            }
                                            ?>
                                        </select>
                                    </td>

                                    <!-- 15. TAT (Editable) -->
                                    <td>
                                        <input type="text" class="form-control form-control-sm editable-input text-center" style="width: 70px;"
                                               value="<?= h($row['tat'] ?? '') ?>" 
                                               onchange="updateField(<?= $row['id'] ?>, 'tat', this.value)">
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="15" class="text-center py-4 text-muted">
                                    <i class="fas fa-folder-open fa-2x mb-2 d-block"></i>
                                    No WK TS entries found. Click "Add WK TS Job" to insert one.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal to Add New WK TS Job Entry -->
    <div class="modal fade" id="addJobModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" action="wk_ts_assign.php">
                    <input type="hidden" name="action" value="add_job">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold" style="color: #1e293b;">
                            <i class="fas fa-plus-circle text-primary me-2"></i>Add New WK TS Job Entry
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Received Date</label>
                            <input type="date" name="received_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Due Date</label>
                            <input type="date" name="due_date" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Issue <span class="text-danger">*</span></label>
                            <input type="text" name="issue" class="form-control form-control-sm" placeholder="Enter Issue Name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Articles <span class="text-danger">*</span></label>
                            <input type="text" name="articles" class="form-control form-control-sm" placeholder="Enter Article Name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Pages</label>
                            <input type="number" name="pages" class="form-control form-control-sm" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">R-TAT</label>
                            <input type="text" name="r_tat" class="form-control form-control-sm" placeholder="e.g. 1 Day">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Remarks</label>
                            <select name="remarks" class="form-select form-select-sm">
                                <option value="">Select Remarks</option>
                                <option value="Hold">Hold</option>
                                <option value="Query Sent">Query Sent</option>
                                <option value="Urgent">Urgent</option>
                                <option value="Send Today">Send Today</option>
                                <option value="Client priority">Client priority</option>
                                <option value="Before 11 AM">Before 11 AM</option>
                                <option value="Before 1 PM">Before 1 PM</option>
                                <option value="Before 5 PM">Before 5 PM</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Folder / File Path</label>
                            <input type="text" name="file_path" class="form-control form-control-sm" placeholder="Enter server or folder path">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm px-3">
                            <i class="fas fa-save me-1"></i> Save Job
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
        function updateField(id, field, value) {
            showLoading();
            fetch('wk_ts_assign.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `ajax=inline_edit&id=${id}&field=${encodeURIComponent(field)}&value=${encodeURIComponent(value)}`
            })
            .then(res => res.json())
            .then(data => {
                hideLoading();
                if (!data.success) {
                    alert('Error updating: ' + (data.message || ''));
                }
            })
            .catch(() => { hideLoading(); });
        }

        // TM Assignment via AJAX
        function assignTM(id, type, assignedTo) {
            if (!assignedTo) return;
            showLoading();
            fetch('wk_ts_assign.php', {
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

        // Stage Status Update (PAG / QC)
        function updateStageStatus(id, stage, status) {
            showLoading();
            fetch('wk_ts_assign.php', {
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
    </script>
</body>
</html>