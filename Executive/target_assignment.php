<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Check if user is logged in
if (!isset($_SESSION['uid'])) {
    header("Location: ../index.php");
    exit;
}

$current_user = $_SESSION['username'] ?? '';
$employee_username = $current_user;

// Get current user details
$sql = "SELECT `employee_name`,`employee_id`,`user_role_id` FROM `employee_master` WHERE `employee_username` = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("s", $employee_username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $current_user_name = $row['employee_name'];
    $current_user_id = $row['employee_id'] ?? 0;
    $current_user_role = $row['user_role_id'] ?? 0;
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

function getWeekDates($week_number, $year = null) {
    if ($year === null) $year = date('Y');
    
    // Financial / Operational year starts from April 1
    $april_first = strtotime($year . '-04-01');
    $day_of_week = date('N', $april_first); // 1 (Mon) to 7 (Sun)
    
    // First Monday on or after April 1
    if ($day_of_week == 1) {
        $first_monday = $april_first;
    } else {
        $first_monday = strtotime('+' . (8 - $day_of_week) . ' days', $april_first);
    }
    
    $week_start = strtotime('+' . (($week_number - 1) * 7) . ' days', $first_monday);
    $week_end = strtotime('+5 days', $week_start); // Monday to Saturday (e.g. 26 Oct to 31 Oct)
    
    return [
        'from_date' => date('Y-m-d', $week_start),
        'to_date' => date('Y-m-d', $week_end)
    ];
}

// Get TLs for dropdown (only if current user is Manager/Admin)
$tls = [];
if ($current_user_role == 3) {
    $stmt = $mysqli->prepare("SELECT employee_id, employee_name FROM employee_master 
                             WHERE user_role_id = 3 AND status = 'Active' 
                             ORDER BY employee_name");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $tls[] = $row;
    }
    $stmt->close();
}

// Get selected TL
$selected_tl_id = isset($_GET['tl_id']) ? (int)$_GET['tl_id'] : 0;
if ($current_user_role == 3) {
    $selected_tl_id = $current_user_id;
}

// Get TMs for the selected TL (Only UQ Team Members)
$tms = [];
if ($current_user_role > 0) {
    $query = "SELECT DISTINCT em.employee_id, em.employee_name, em.employee_username 
              FROM employee_master em
              LEFT JOIN team_master tm_tbl ON em.team_id = tm_tbl.team_id
              WHERE (
                  tm_tbl.team_name LIKE '%UQ%'
                  OR em.team_id LIKE '%UQ%'
                  OR em.process LIKE '%UQ%'
                  OR EXISTS (
                      SELECT 1 FROM process_master pm 
                      WHERE FIND_IN_SET(pm.process_id, em.process) 
                      AND pm.process_name LIKE '%UQ%'
                  )
              ) 
              AND em.status = 'Active' 
              ORDER BY em.employee_username ASC";
              
    $stmt = $mysqli->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $tms[] = $row;
    }
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'assign_target') {
        $tm_id = $_POST['tm_id'] ?? '';
        $target_limit = (int)($_POST['target_limit'] ?? 0);
        $week_input = $_POST['week_input'] ?? '';
        $year = (int)($_POST['year'] ?? date('Y'));
        
        // Validation
        $errors = [];
        
        if ($tm_id == '') {
            $errors[] = "Please select a Team Member";
        }
        
        if ($target_limit <= 0) {
            $errors[] = "Target limit must be greater than 0";
        }
        
        if (empty($week_input)) {
            $errors[] = "Week is required (e.g., W1, W2)";
        }
        
        // Parse week input (W1, W2, etc.)
        preg_match('/W(\d+)/i', $week_input, $matches);
        $week_number = isset($matches[1]) ? (int)$matches[1] : 0;
        
        if ($week_number < 1 || $week_number > 53) {
            $errors[] = "Week number must be between 1 and 53";
        }
        
        if (empty($errors)) {
            // Get dates for the week
            $week_dates = getWeekDates($week_number, $year);
            $from_date = $week_dates['from_date'];
            $to_date = $week_dates['to_date'];
            
            // Check for overlapping targets
            $check_stmt = $mysqli->prepare("SELECT id FROM weekly_targets 
                                           WHERE tm_id = ? 
                                           AND (
                                               (from_date <= ? AND to_date >= ?) OR
                                               (from_date <= ? AND to_date >= ?) OR
                                               (from_date >= ? AND to_date <= ?)
                                           )");
            $check_stmt->bind_param("sssssss", $tm_id, $to_date, $from_date, $from_date, $to_date, $from_date, $to_date);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $errors[] = "Target already exists for this TM in the selected week";
            }
            $check_stmt->close();
        }
        
        if (empty($errors)) {
            // Insert target assignment
            $stmt = $mysqli->prepare("INSERT INTO weekly_targets 
                                     (tl_id, tm_id, target_limit, from_date, to_date, week_number, year, assigned_by, assigned_date) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $assigned_by = $current_user_id;
            $stmt->bind_param("isissiii", $selected_tl_id, $tm_id, $target_limit, $from_date, $to_date, $week_number, $year, $assigned_by);
            
            if ($stmt->execute()) {
                $success_message = "Target assigned successfully for Week $week_number!";
            } else {
                $errors[] = "Error assigning target: " . $stmt->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'update_progress') {
        $target_id = (int)($_POST['target_id'] ?? 0);
        $status = $_POST['status'] ?? 'Active';
        
        if ($target_id > 0) {
            // Get target details
            $target_stmt = $mysqli->prepare("SELECT tm_id, from_date, to_date FROM weekly_targets WHERE id = ?");
            $target_stmt->bind_param("i", $target_id);
            $target_stmt->execute();
            $target_result = $target_stmt->get_result();
            
            if ($target_row = $target_result->fetch_assoc()) {
                $tm_id = $target_row['tm_id'];
                $from_date = $target_row['from_date'];
                $to_date = $target_row['to_date'];
                
                // Get TM's username
                $tm_stmt = $mysqli->prepare("SELECT employee_username FROM employee_master WHERE employee_username = ?");
                $tm_stmt->bind_param("i", $tm_id);
                $tm_stmt->execute();
                $tm_result = $tm_stmt->get_result();
                
                $achieved_target = 0;
                if ($tm_row = $tm_result->fetch_assoc()) {
                    $tm_username = $tm_row['employee_username'];
                    
                    // Calculate achieved target
                    $achieved_query = "SELECT COUNT(*) as job_count 
                                      FROM jobs 
                                      WHERE (
                                          job_date BETWEEN ? AND ?
                                      ) AND (
                                          assigned_ce = ? OR 
                                          assigned_le = ? OR 
                                          assigned_pag = ? OR 
                                          assigned_qc = ? OR 
                                          assigned_xml = ?
                                      )";
                    
                    $achieved_stmt = $mysqli->prepare($achieved_query);
                    $achieved_stmt->bind_param("sssssss", $from_date, $to_date, 
                                              $tm_username, $tm_username, $tm_username, 
                                              $tm_username, $tm_username);
                    $achieved_stmt->execute();
                    $achieved_result = $achieved_stmt->get_result();
                    
                    if ($achieved_row = $achieved_result->fetch_assoc()) {
                        $achieved_target = $achieved_row['job_count'];
                    }
                    $achieved_stmt->close();
                }
                $tm_stmt->close();
                
                // Update weekly_targets
                $update_stmt = $mysqli->prepare("UPDATE weekly_targets 
                                               SET achieved_target = ?, status = ? 
                                               WHERE id = ?");
                $update_stmt->bind_param("isi", $achieved_target, $status, $target_id);
                
                if ($update_stmt->execute()) {
                    $success_message = "Progress updated successfully! Achieved target: " . $achieved_target;
                } else {
                    $errors[] = "Error updating progress: " . $update_stmt->error;
                }
                $update_stmt->close();
            }
            $target_stmt->close();
        }
    } elseif ($action === 'delete_target') {
        $target_id = (int)($_POST['target_id'] ?? 0);
        
        if ($target_id > 0) {
            $stmt = $mysqli->prepare("DELETE FROM weekly_targets WHERE id = ?");
            $stmt->bind_param("i", $target_id);
            
            if ($stmt->execute()) {
                $success_message = "Target deleted successfully!";
            }
            $stmt->close();
        }
    } elseif ($action === 'update_target') {
        $target_id = (int)($_POST['target_id'] ?? 0);
        $target_limit = (int)($_POST['target_limit'] ?? 0);
        $week_input = $_POST['week_input'] ?? '';
        $year = (int)($_POST['year'] ?? date('Y'));
        
        if ($target_id > 0 && $target_limit > 0 && !empty($week_input)) {
            preg_match('/W(\d+)/i', $week_input, $matches);
            $week_number = isset($matches[1]) ? (int)$matches[1] : 0;
            
            if ($week_number >= 1 && $week_number <= 53) {
                $week_dates = getWeekDates($week_number, $year);
                
                $stmt = $mysqli->prepare("UPDATE weekly_targets 
                                         SET target_limit = ?, week_number = ?, year = ?, 
                                         from_date = ?, to_date = ? 
                                         WHERE id = ?");
                $stmt->bind_param("iiissi", $target_limit, $week_number, $year, 
                                 $week_dates['from_date'], $week_dates['to_date'], $target_id);
                
                if ($stmt->execute()) {
                    $success_message = "Weekly Target updated successfully!";
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'save_revisions') {
        $tm_ids = $_POST['tm_id_rev'] ?? [];
        $rev_months = $_POST['rev_month'] ?? [];
        $current_targets = $_POST['current_target'] ?? [];
        $revision_targets = $_POST['revision_target'] ?? [];
        $success_count = 0;
        
        for ($i = 0; $i < count($tm_ids); $i++) {
            $tm = $tm_ids[$i];
            $rm = $rev_months[$i] ?? '';
            $ct = !empty($current_targets[$i]) ? (int)$current_targets[$i] : 0;
            $rt = !empty($revision_targets[$i]) ? (int)$revision_targets[$i] : 0;
            
            // Only process if month is selected
            if (!empty($rm)) {
                $check = $mysqli->prepare("SELECT id FROM weekly_targets WHERE tm_id = ? AND revision_month = ?");
                $check->bind_param("ss", $tm, $rm);
                $check->execute();
                $res = $check->get_result();
                
                if ($res->num_rows > 0) {
                    $row = $res->fetch_assoc();
                    $upd = $mysqli->prepare("UPDATE weekly_targets SET target_limit = ?, revision_target = ? WHERE id = ?");
                    $upd->bind_param("iii", $ct, $rt, $row['id']);
                    if($upd->execute()) $success_count++;
                    $upd->close();
                } else {
                    // Fix for strict mode: replaced '0000-00-00' with CURDATE() and added 'status'
                        $ins = $mysqli->prepare("INSERT INTO weekly_targets (tl_id, tm_id, revision_month, target_limit, revision_target, week_number, year, assigned_by, assigned_date, from_date, to_date, status) VALUES (?, ?, ?, ?, ?, 0, 0, ?, NOW(), CURDATE(), CURDATE(), 'Active')");
                        $ins->bind_param("issiii", $selected_tl_id, $tm, $rm, $ct, $rt, $current_user_id);
                        if($ins->execute()) $success_count++;
                        $ins->close();
                }
                $check->close();
            }
        }
        if ($success_count > 0) {
            $success_message = "$success_count Target Revisions saved successfully!";
        }
    }
}

// Get existing targets
$targets = [];
$target_query = "SELECT 
    wt.id,
    wt.tl_id,
    wt.tm_id,
    wt.target_limit,
    wt.achieved_target,
    wt.from_date,
    wt.to_date,
    wt.week_number,
    wt.year,
    wt.status,
    wt.assigned_date,
    tl.employee_name as tl_name,
    tm.employee_name as tm_name,
    tm.employee_username as tm_username,
    ass.employee_name as assigned_by_name
FROM weekly_targets wt
LEFT JOIN employee_master tl ON wt.tl_id = tl.employee_id
LEFT JOIN employee_master tm ON wt.tm_id = tm.employee_username
LEFT JOIN employee_master ass ON wt.assigned_by = ass.employee_id
WHERE wt.week_number > 0";

$params = [];
$types = '';

if ($selected_tl_id > 0) {
    if ($current_user_role == 2) {
        $target_query .= " AND wt.tl_id = ?";
        $params[] = $selected_tl_id;
        $types .= 'i';
    } elseif ($current_user_role == 3 && isset($_GET['tl_id'])) {
        $target_query .= " AND wt.tl_id = ?";
        $params[] = $selected_tl_id;
        $types .= 'i';
    }
}

// Apply Team Member filter if specified
if (isset($_GET['tm_filter']) && $_GET['tm_filter'] !== '') {
    $target_query .= " AND wt.tm_id = ?";
    $params[] = $_GET['tm_filter'];
    $types .= 's';
}

// Apply week filter if specified
if (isset($_GET['week_filter']) && $_GET['week_filter']) {
    $week_filter = $_GET['week_filter'];
    if ($week_filter === 'current_week') {
        $target_query .= " AND wt.week_number = WEEK(CURDATE(), 1) AND wt.year = YEAR(CURDATE())";
    } elseif ($week_filter === 'next_week') {
        $target_query .= " AND wt.week_number = WEEK(DATE_ADD(CURDATE(), INTERVAL 1 WEEK), 1) AND wt.year = YEAR(CURDATE())";
    } elseif ($week_filter === 'previous_week') {
        $target_query .= " AND wt.week_number = WEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1) AND wt.year = YEAR(CURDATE())";
    } elseif (preg_match('/W(\d+)/i', $week_filter, $matches)) {
        $week_num = (int)$matches[1];
        $target_query .= " AND wt.week_number = ?";
        $params[] = $week_num;
        $types .= 'i';
        
        if (isset($_GET['year_filter']) && $_GET['year_filter']) {
            $target_query .= " AND wt.year = ?";
            $params[] = $_GET['year_filter'];
            $types .= 'i';
        }
    }
}

$target_query .= " ORDER BY wt.year DESC, wt.week_number DESC, tm.employee_name";

if (!empty($params)) {
    $stmt = $mysqli->prepare($target_query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $mysqli->query($target_query);
}

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $targets[] = $row;
    }
}

// Calculate percentage achieved
foreach ($targets as &$target) {
    if ($target['target_limit'] > 0) {
        $target['achieved_percentage'] = round(($target['achieved_target'] / $target['target_limit']) * 100, 2);
    } else {
        $target['achieved_percentage'] = 0;
    }
}
unset($target); // FIX: Breaks the reference to prevent overwriting the array in the next loop
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly Target Assignment</title>
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
            margin-top: 80px; /* Added this line to push the content down below the header */
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
            margin-bottom: 20px;
        }
        .progress {
            height: 20px;
        }
        .bg-target {
            background-color: #6f42c1;
        }
        .filter-section {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .week-badge {
            font-size: 1.1em;
            padding: 8px 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .target-cell {
            background-color: #e9ecef;
            padding: 10px;
            border-radius: 5px;
            text-align: center;
            font-weight: bold;
        }
        .week-input-container {
            display: flex;
            gap: 10px;
        }
        .week-input-container input {
            text-transform: uppercase;
        }
        .progress-cell {
            min-width: 200px;
        }
        .card-header {
            background-color: #707981 !important;
            color: white !important;
}

    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-bullseye me-2"></i>Employee Target</h4>
        </div>

        <?php if(isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= h($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if(!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <ul class="mb-0">
                <?php foreach($errors as $error): ?>
                <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="get" class="row g-3 align-items-center">
                <?php if($current_user_role == 3): ?>
                <div class="col-md-2">
                    <label for="tl_id" class="form-label">Select Team Leader</label>
                    <select name="tl_id" id="tl_id" class="form-select" onchange="this.form.submit()">
                        <option value="">All Team Leaders</option>
                        <?php foreach($tls as $tl): ?>
                        <option value="<?= h($tl['employee_username']) ?>" 
                                <?= $selected_tl_id == $tl['employee_username'] ? 'selected' : '' ?>>
                            <?= h($tl['employee_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-md-3">
                    <label for="tm_filter" class="form-label">Team Member</label>
                    <select name="tm_filter" id="tm_filter" class="form-select">
                        <option value="">All UQ Members</option>
                        <?php foreach($tms as $tm): ?>
                        <option value="<?= h($tm['employee_username']) ?>" <?= (isset($_GET['tm_filter']) && $_GET['tm_filter'] == $tm['employee_username']) ? 'selected' : '' ?>>
                            <?= h($tm['employee_name']) ?> (<?= h($tm['employee_username']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="week_filter" class="form-label">Week</label>
                    <select name="week_filter" id="week_filter" class="form-select">
                        <option value="">All Weeks</option>
                        <?php for($w=1; $w<=53; $w++): ?>
                            <option value="W<?= $w ?>" <?= (isset($_GET['week_filter']) && $_GET['week_filter'] == 'W'.$w) ? 'selected' : '' ?>>W<?= str_pad($w, 2, '0', STR_PAD_LEFT) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="year_filter" class="form-label">Year</label>
                    <select name="year_filter" id="year_filter" class="form-select">
                        <?php for($y = 2023; $y <= 2030; $y++): ?>
                        <option value="<?= $y ?>" <?= ($_GET['year_filter'] ?? date('Y')) == $y ? 'selected' : '' ?>>
                            <?= $y ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-md-3 d-flex align-items-end gap-2">
                    <div class="w-100">
                        <label class="form-label d-block">&nbsp;</label>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary w-50">
                                <i class="fas fa-filter me-1"></i> Filter
                            </button>
                            <a href="target_assignment.php" class="btn btn-secondary w-50">
                                <i class="fas fa-times me-1"></i> Clear
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div class="row">
            <!-- Assign Target Form -->
            <div class="col-md-4">
                <div class="card">
                    <!-- <div class="card-header bg-primary text-white"> -->
                        <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Assign Weekly Target</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" autocomplete="off">
                            <input type="hidden" name="action" value="assign_target">
                            
                            <div class="mb-3">
                                <label for="tm_id" class="form-label">Team Member <span class="text-danger">*</span></label>
                                <select name="tm_id" id="tm_id" class="form-select" required <?= count($tms) == 0 ? 'disabled' : '' ?>>
                                    <option value="">Select Team Member</option>
                                    <?php foreach($tms as $tm): ?>
                                    <option value="<?= h($tm['employee_username']) ?>">
                                        <?= h($tm['employee_name']) ?> (<?= h($tm['employee_username']) ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if(count($tms) == 0): ?>
                                <div class="form-text text-warning">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    No team members found for the selected TL
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mb-3">
                                <label for="target_limit" class="form-label">Target Limit <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="target_limit" name="target_limit" 
                                       min="1" required placeholder="Enter target number">
                            </div>
                            
                            <div class="mb-3">
                                <label for="week_input" class="form-label">Week <span class="text-danger">*</span></label>
                                <div class="week-input-container">
                                    <select class="form-select" id="week_input" name="week_input" required>
                                        <option value="">Select Week</option>
                                        <?php for($w=1; $w<=53; $w++): ?>
                                            <option value="W<?= $w ?>">W<?= str_pad($w, 2, "0", STR_PAD_LEFT) ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <input type="number" class="form-control" id="year" name="year" 
                                           value="<?= date('Y') ?>" min="2023" max="2030" style="width: 100px;">
                                </div>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i> Select week number
                                </div>
                            </div>
                            
                            <div id="weekDates" class="alert alert-info">
                                <small>Week dates will be calculated automatically</small>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-success" <?= count($tms) == 0 ? 'disabled' : '' ?>>
                                    <i class="fas fa-paper-plane me-1"></i> Assign Target
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="card mt-3">
                    <!-- <div class="card-header bg-info text-white"> -->
                        <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Quick Stats</h6>
                    </div>
                    <div class="card-body">
                        <?php if(!empty($targets)): ?>
                        <?php 
                        $total_target = array_sum(array_column($targets, 'target_limit'));
                        $total_achieved = array_sum(array_column($targets, 'achieved_target'));
                        $overall_percentage = $total_target > 0 ? round(($total_achieved / $total_target) * 100, 2) : 0;
                        ?>
                        <div class="text-center">
                            <div class="display-6 fw-bold 
                                <?= $overall_percentage >= 100 ? 'text-success' : 
                                   ($overall_percentage >= 70 ? 'text-warning' : 'text-danger') ?>">
                                <?= $overall_percentage ?>%
                            </div>
                            <small class="text-muted">Overall Achievement Rate</small>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-6 text-center">
                                <div class="h4 fw-bold text-primary"><?= count($targets) ?></div>
                                <small class="text-muted">Targets</small>
                            </div>
                            <div class="col-6 text-center">
                                <div class="h4 fw-bold text-success"><?= $total_achieved ?></div>
                                <small class="text-muted">Achieved</small>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="text-center text-muted">
                            <i class="fas fa-chart-bar fa-2x mb-2"></i>
                            <p>No data available</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Targets List -->
            <div class="col-md-8">
                <div class="card">
                    <!-- <div class="card-header bg-info text-white d-flex justify-content-between align-items-center"> -->
                         <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list-check me-2"></i>Assigned Targets</h5>
                        <span class="badge bg-light text-dark">
                            <?= count($targets) ?> Target(s)
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th width="50">ID</th>
                                        <?php if($current_user_role == 3): ?>
                                        <th>TL</th>
                                        <?php endif; ?>
                                        <th>Team Member</th>
                                        <th width="80">Week</th>
                                        <th width="120" class="text-center">Target Limit</th>
                                        <th width="200">Progress</th>
                                        <th width="100">Status</th>
                                        <th width="100" class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(!empty($targets)): ?>
                                        <?php foreach($targets as $target): ?>
                                        <tr>
                                            <td class="fw-bold"><?= h($target['id']) ?></td>
                                            <?php if($current_user_role == 3): ?>
                                            <td>
                                                <small><?= h($target['tl_name']) ?></small>
                                            </td>
                                            <?php endif; ?>
                                            <td>
                                                <div class="fw-semibold"><?= h($target['tm_name']) ?></div>
                                                <small class="text-muted"><?= h($target['tm_username']) ?></small>
                                            </td>
                                            <td>
                                                <span class="badge week-badge">W<?= h($target['week_number']) ?></span>
                                                <small class="d-block text-muted"><?= h($target['year']) ?></small>
                                                <small class="d-block"><?= formatDate($target['from_date']) ?> - <?= formatDate($target['to_date']) ?></small>
                                            </td>
                                            <td class="text-center">
                                                <div class="target-cell">
                                                    <?= h($target['target_limit']) ?>
                                                </div>
                                            </td>
                                            <td class="progress-cell">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <small>Achieved: <?= h($target['achieved_target']) ?></small>
                                                    <small class="fw-bold 
                                                        <?= $target['achieved_percentage'] >= 100 ? 'text-success' : 
                                                           ($target['achieved_percentage'] >= 70 ? 'text-warning' : 'text-danger') ?>">
                                                        <?= h($target['achieved_percentage']) ?>%
                                                    </small>
                                                </div>
                                                <div class="progress">
                                                    <div class="progress-bar <?= $target['achieved_percentage'] >= 100 ? 'bg-success' : ($target['achieved_percentage'] >= 70 ? 'bg-warning' : 'bg-danger') ?>" 
                                                         role="progressbar" 
                                                         style="width: <?= min($target['achieved_percentage'], 100) ?>%">
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge 
                                                    <?= $target['status'] == 'Active' ? 'bg-primary' : 
                                                       ($target['status'] == 'Completed' ? 'bg-success' : 'bg-danger') ?>">
                                                    <?= h($target['status']) ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm">
                                                    <!--button class="btn btn-info" title="Update Progress"
                                                            onclick="updateProgress(<?= $target['id'] ?>, '<?= h($target['tm_name']) ?>')">
                                                        <i class="fas fa-chart-line"></i>
                                                    </button-->
                                                    <button class="btn btn-warning" title="Edit Target"
                                                            onclick="editTarget(<?= $target['id'] ?>, <?= h($target['target_limit']) ?>, 'W<?= h($target['week_number']) ?>', <?= h($target['year']) ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="post" style="display:inline;" 
                                                          onsubmit="return confirm('Delete this target?')">
                                                        <input type="hidden" name="action" value="delete_target">
                                                        <input type="hidden" name="target_id" value="<?= h($target['id']) ?>">
                                                        <button type="submit" class="btn btn-danger" title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="<?= $current_user_role == 3 ? 8 : 7 ?>" class="text-center py-5">
                                                <div class="text-muted">
                                                    <i class="fas fa-bullseye fa-3x mb-3"></i>
                                                    <h5>No targets found</h5>
                                                    <p class="mb-0">Assign your first weekly target using the form.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Target Revision Section (NEW ROW) -->
        <div class="row mt-4">
            <div class="col-12">
                <form method="post">
                    <input type="hidden" name="action" value="save_revisions">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Target Revision</h5>
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i> Save Revisions</button>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th width="200">Month with year</th>
                                            <th>Team Member</th>
                                            <th width="200">Current Target</th>
                                            <th width="200">Target Revision</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                            // Fetch saved revisions from DB to show them in input fields
                                            $revisions = [];
                                            $rev_res = $mysqli->query("SELECT tm_id, revision_month, target_limit, revision_target FROM weekly_targets WHERE week_number = 0 ORDER BY id ASC");
                                            if ($rev_res && $rev_res->num_rows > 0) {
                                                while($row = $rev_res->fetch_assoc()) {
                                                    $revisions[$row['tm_id']] = $row;
                                                }
                                            }
                                        ?>
                                        <?php if(!empty($tms)): ?>
                                            <?php foreach($tms as $tm): 
                                                $tm_un = $tm['employee_username'];
                                                $saved_month = isset($revisions[$tm_un]) ? $revisions[$tm_un]['revision_month'] : '';
                                                $saved_ct = isset($revisions[$tm_un]) ? $revisions[$tm_un]['target_limit'] : '';
                                                $saved_rt = isset($revisions[$tm_un]) ? $revisions[$tm_un]['revision_target'] : '';
                                            ?>
                                            <tr>
                                                <td class="fw-bold">
                                                    <?= h($tm['employee_username']) ?>
                                                    <input type="hidden" name="tm_id_rev[]" value="<?= h($tm['employee_username']) ?>">
                                                </td>
                                                <td>
                                                    <input type="month" class="form-control form-control-sm" name="rev_month[]" value="<?= h($saved_month) ?>">
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?= h($tm['employee_name']) ?></div>
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control form-control-sm" name="current_target[]" placeholder="Enter Target" min="0" value="<?= h($saved_ct) ?>">
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control form-control-sm" name="revision_target[]" placeholder="Enter Revision" min="0" value="<?= h($saved_rt) ?>">
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4 text-muted">
                                                    No team members found
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </div>

    <!-- Progress Update Modal -->
    <div class="modal fade" id="progressModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Target Progress</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="progressForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_progress">
                        <input type="hidden" name="target_id" id="modal_target_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Team Member</label>
                            <input type="text" class="form-control" id="modal_tm_name" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Week</label>
                            <input type="text" class="form-control" id="modal_week" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Calculated Achieved Target</label>
                            <div class="alert alert-info">
                                <h4 class="text-center" id="achieved_count">Calculating...</h4>
                                <p class="text-center small mb-0">This is automatically calculated from assigned jobs</p>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select name="status" id="status" class="form-select">
                                <option value="Active">Active</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Progress</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Target Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Target</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="editForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_target">
                        <input type="hidden" name="target_id" id="edit_target_id">
                        
                        <div class="mb-3">
                            <label for="edit_target_limit" class="form-label">Target Limit</label>
                            <input type="number" class="form-control" id="edit_target_limit" name="target_limit" min="1" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_week_input" class="form-label">Week</label>
                            <div class="week-input-container">
                                <select class="form-select" id="edit_week_input" name="week_input" required>
                                    <option value="">Select Week</option>
                                    <?php for($w=1; $w<=53; $w++): ?>
                                        <option value="W<?= $w ?>">W<?= str_pad($w, 2, "0", STR_PAD_LEFT) ?></option>
                                    <?php endfor; ?>
                                </select>
                                <input type="number" class="form-control" id="edit_year" name="year" 
                                       min="2023" max="2030" style="width: 100px;">
                            </div>
                        </div>
                        
                        <div id="editWeekDates" class="alert alert-info">
                            <small>Week dates will be calculated automatically</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Target</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Calculate and display week dates when week input changes
        document.addEventListener('DOMContentLoaded', function() {
            const weekInput = document.getElementById('week_input');
            const yearInput = document.getElementById('year');
            const weekDatesDiv = document.getElementById('weekDates');
            
            const editWeekInput = document.getElementById('edit_week_input');
            const editYearInput = document.getElementById('edit_year');
            const editWeekDatesDiv = document.getElementById('editWeekDates');
            
            function calculateWeekDates(weekInput, yearInput, displayDiv) {
                const weekMatch = weekInput.value.match(/W(\d+)/i);
                if (weekMatch) {
                    const weekNumber = parseInt(weekMatch[1]);
                    const year = parseInt(yearInput.value);
                    
                    if (weekNumber >= 1 && weekNumber <= 53) {
                        // Operational year starts from April 1
                        const aprilFirst = new Date(year, 3, 1); // Month 3 is April
                        const dayOfWeek = aprilFirst.getDay(); // 0 is Sunday, 1 is Monday
                        
                        // Find first Monday on or after April 1
                        const diffToMonday = (dayOfWeek === 1) ? 0 : (dayOfWeek === 0 ? 1 : (8 - dayOfWeek));
                        const firstMonday = new Date(aprilFirst);
                        firstMonday.setDate(firstMonday.getDate() + diffToMonday);
                        
                        const weekStart = new Date(firstMonday);
                        weekStart.setDate(weekStart.getDate() + (weekNumber - 1) * 7);
                        
                        const weekEnd = new Date(weekStart);
                        weekEnd.setDate(weekEnd.getDate() + 5); // Monday to Saturday (e.g. 26 Oct to 31 Oct)
                        
                        const formatDate = (date) => {
                            return date.toLocaleDateString('en-GB', {
                                day: '2-digit',
                                month: 'short',
                                year: 'numeric'
                            });
                        };
                        
                        displayDiv.innerHTML = `
                            <div class="fw-bold">Week ${weekNumber}, ${year}</div>
                            <small>${formatDate(weekStart)} to ${formatDate(weekEnd)}</small>
                        `;
                    }
                }
            }
            
            // For new target form
            weekInput.addEventListener('input', function() {
                calculateWeekDates(weekInput, yearInput, weekDatesDiv);
            });
            
            yearInput.addEventListener('change', function() {
                calculateWeekDates(weekInput, yearInput, weekDatesDiv);
            });
            
            // For edit form
            editWeekInput.addEventListener('input', function() {
                calculateWeekDates(editWeekInput, editYearInput, editWeekDatesDiv);
            });
            
            editYearInput.addEventListener('change', function() {
                calculateWeekDates(editWeekInput, editYearInput, editWeekDatesDiv);
            });
            
            // Auto-format week input
            weekInput.addEventListener('blur', function() {
                const value = this.value.trim().toUpperCase();
                if (value && !value.startsWith('W')) {
                    this.value = 'W' + value.replace(/\D/g, '');
                }
            });
            
            editWeekInput.addEventListener('blur', function() {
                const value = this.value.trim().toUpperCase();
                if (value && !value.startsWith('W')) {
                    this.value = 'W' + value.replace(/\D/g, '');
                }
            });
            
            // Calculate initial week dates
            calculateWeekDates(weekInput, yearInput, weekDatesDiv);
        });
        
        // Update progress
        function updateProgress(targetId, tmName) {
            document.getElementById('modal_target_id').value = targetId;
            document.getElementById('modal_tm_name').value = tmName;
            
            fetch(`calculate_achieved_target.php?target_id=${targetId}`)
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        document.getElementById('achieved_count').textContent = data.achieved_target;
                        document.getElementById('status').value = data.status;
                        
                        const achievedEl = document.getElementById('achieved_count');
                        if (data.achieved_target >= data.target_limit) {
                            achievedEl.className = "text-center text-success";
                        } else if (data.achieved_target >= (data.target_limit * 0.7)) {
                            achievedEl.className = "text-center text-warning";
                        } else {
                            achievedEl.className = "text-center text-danger";
                        }
                    } else {
                        document.getElementById('achieved_count').textContent = "Error calculating";
                        document.getElementById('achieved_count').className = "text-center text-danger";
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('achieved_count').textContent = "Error calculating";
                    document.getElementById('achieved_count').className = "text-center text-danger";
                });
            
            const modal = new bootstrap.Modal(document.getElementById('progressModal'));
            modal.show();
        }
        
        // Edit target
        function editTarget(targetId, targetLimit, weekInput, year) {
            document.getElementById('edit_target_id').value = targetId;
            document.getElementById('edit_target_limit').value = targetLimit;
            document.getElementById('edit_week_input').value = weekInput;
            document.getElementById('edit_year').value = year;
            
            // Trigger date calculation
            const editWeekInput = document.getElementById('edit_week_input');
            const editYearInput = document.getElementById('edit_year');
            const editWeekDatesDiv = document.getElementById('editWeekDates');
            
            // Calculate and display week dates (April Year Base)
            const weekMatch = weekInput.match(/W(\d+)/i);
            if (weekMatch) {
                const weekNumber = parseInt(weekMatch[1]);
                const aprilFirst = new Date(year, 3, 1);
                const dayOfWeek = aprilFirst.getDay();
                const diffToMonday = (dayOfWeek === 1) ? 0 : (dayOfWeek === 0 ? 1 : (8 - dayOfWeek));
                const firstMonday = new Date(aprilFirst);
                firstMonday.setDate(firstMonday.getDate() + diffToMonday);
                
                const weekStart = new Date(firstMonday);
                weekStart.setDate(weekStart.getDate() + (weekNumber - 1) * 7);
                
                const weekEnd = new Date(weekStart);
                weekEnd.setDate(weekEnd.getDate() + 5);
                
                const formatDate = (date) => {
                    return date.toLocaleDateString('en-GB', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric'
                    });
                };
                
                editWeekDatesDiv.innerHTML = `
                    <div class="fw-bold">Week ${weekNumber}, ${year}</div>
                    <small>${formatDate(weekStart)} to ${formatDate(weekEnd)}</small>
                `;
            }
            
            const modal = new bootstrap.Modal(document.getElementById('editModal'));
            modal.show();
        }
        
        // Auto-dismiss alerts
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert:not(.alert-info)');
            alerts.forEach(alert => {
                if (alert.isConnected) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            });
        }, 5000);
    </script>
</body>
</html>