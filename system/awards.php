<?php
require 'db.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// --- AUTO-FIX DATABASE SCHEMA ---
// Automatically add 'emp_id' column if it doesn't exist to prevent Fatal SQL Errors
$checkCol = $mysqli->query("SHOW COLUMNS FROM tbl_awards LIKE 'emp_id'");
if ($checkCol && $checkCol->num_rows === 0) {
    $mysqli->query("ALTER TABLE tbl_awards ADD COLUMN emp_id VARCHAR(50) NULL AFTER venue");
}
// ---------------------------------

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00') return '—';
    return date('d-m-Y', strtotime($d));
}

function redirect_with($params = []) {
    $base = 'awards.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// ---------------------------------------------------------
// HANDLE IMPORT LOGIC (XLSX Format)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'Library file not found.', 'type' => 'danger']);
        }
        
        require_once $libPath;

        $xlsx = null;
        if (class_exists('Shuchkin\SimpleXLSX')) {
            $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        } elseif (class_exists('SimpleXLSX')) {
            $xlsx = SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        } else {
            redirect_with(['msg' => 'Class SimpleXLSX not found.', 'type' => 'danger']);
        }

        if ($xlsx) {
            $rows = $xlsx->rows();
            $imported_count = 0;
            $updated_count = 0;
            $skipped_count = 0;
            $error_details = [];
            
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header Row

                $award_name   = trim($r[0] ?? '');
                $award_date_r = trim($r[1] ?? '');
                $venue        = trim($r[2] ?? '');
                $emp_id       = trim($r[3] ?? '');
                $name         = trim($r[4] ?? '');
                $joined_date_r= trim($r[5] ?? '');
                $designation  = trim($r[6] ?? '');
                $joined_team  = trim($r[7] ?? '');
                $current_team = trim($r[8] ?? '');
                $remarks      = trim($r[9] ?? '');
                
                if (empty($award_name) || empty($emp_id) || empty($name)) {
                    $skipped_count++;
                    $error_details[] = "Row " . ($k + 1) . ": Missing Award Name, Employee ID or Name";
                    continue;
                }

                $award_date = NULL;
                if (!empty($award_date_r)) {
                    if (is_numeric($award_date_r)) {
                        if (class_exists('Shuchkin\SimpleXLSX')) {
                            $award_date = date('Y-m-d', Shuchkin\SimpleXLSX::unixstamp($award_date_r));
                        } else {
                            $unix = ($award_date_r - 25569) * 86400;
                            $award_date = gmdate("Y-m-d", $unix);
                        }
                    } else {
                        $ts = strtotime($award_date_r);
                        if ($ts !== false) $award_date = date('Y-m-d', $ts);
                    }
                }
                
                $joined_date = NULL;
                if (!empty($joined_date_r)) {
                    if (is_numeric($joined_date_r)) {
                        if (class_exists('Shuchkin\SimpleXLSX')) {
                            $joined_date = date('Y-m-d', Shuchkin\SimpleXLSX::unixstamp($joined_date_r));
                        } else {
                            $unix = ($joined_date_r - 25569) * 86400;
                            $joined_date = gmdate("Y-m-d", $unix);
                        }
                    } else {
                        $ts = strtotime($joined_date_r);
                        if ($ts !== false) $joined_date = date('Y-m-d', $ts);
                    }
                }

                $checkStmt = $mysqli->prepare("SELECT id FROM tbl_awards WHERE award_name = ? AND emp_id = ? AND award_date = ?");
                $checkStmt->bind_param("sss", $award_name, $emp_id, $award_date);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->num_rows > 0;
                $checkStmt->close();

                if (!$exists) {
                    $insertStmt = $mysqli->prepare("INSERT INTO tbl_awards 
                        (award_name, award_date, venue, emp_id, name, joined_date, designation, joined_team, current_team, remarks, created_at, created_ip) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
                    
                    $insertStmt->bind_param("sssssssssss", 
                        $award_name, $award_date, $venue, $emp_id, $name, $joined_date, 
                        $designation, $joined_team, $current_team, $remarks, $_SERVER['REMOTE_ADDR']
                    );
                    
                    if ($insertStmt->execute()) {
                        $imported_count++;
                    } else {
                        $skipped_count++;
                        $error_details[] = "Row " . ($k + 1) . ": " . $mysqli->error;
                    }
                    $insertStmt->close();
                } else {
                    $updateStmt = $mysqli->prepare("UPDATE tbl_awards SET 
                        venue = ?, name = ?, joined_date = ?, designation = ?, joined_team = ?, 
                        current_team = ?, remarks = ?, updated_at = NOW(), updated_ip = ? 
                        WHERE award_name = ? AND emp_id = ? AND award_date = ?");
                    
                    $updateStmt->bind_param("sssssssssss", 
                        $venue, $name, $joined_date, $designation, $joined_team, 
                        $current_team, $remarks, $_SERVER['REMOTE_ADDR'],
                        $award_name, $emp_id, $award_date
                    );
                    
                    if ($updateStmt->execute()) {
                        $updated_count++;
                    }
                    $updateStmt->close();
                }
            }
            
            $msg = "Process Complete.<br>";
            $msg .= "<strong>Imported:</strong> $imported_count new awards.<br>";
            $msg .= "<strong>Updated:</strong> $updated_count existing awards.<br>";
            $msg .= "<strong>Skipped:</strong> $skipped_count rows.";
            
            if (!empty($error_details)) {
                $msg .= "<br><small class='text-danger'>" . implode("<br>", array_slice($error_details, 0, 5)) . "</small>";
                if (count($error_details) > 5) {
                    $msg .= "<br>... and " . (count($error_details) - 5) . " more errors.";
                }
            }
            
            $type = ($imported_count > 0 || $updated_count > 0) ? 'success' : 'warning';
            redirect_with(['msg' => $msg, 'type' => $type]);

        } else {
            $err = (method_exists('Shuchkin\SimpleXLSX', 'parseError')) ? Shuchkin\SimpleXLSX::parseError() : 'Unknown Error';
            redirect_with(['msg' => 'SimpleXLSX Parse Error: ' . $err, 'type' => 'danger']);
        }
    } else {
        redirect_with(['msg' => 'No file uploaded or file error.', 'type' => 'danger']);
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $award_name = trim($_POST['award_name'] ?? '');
        $award_date = trim($_POST['award_date'] ?? '');
        $venue = trim($_POST['venue'] ?? '');
        $emp_id = trim($_POST['emp_id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $joined_date = trim($_POST['joined_date'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $joined_team = trim($_POST['joined_team'] ?? '');
        $current_team = trim($_POST['current_team'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        
        if ($award_name === '') {
            redirect_with(['msg' => 'Award Name is required.', 'type' => 'danger']);
        }
        if ($emp_id === '') {
            redirect_with(['msg' => 'Employee ID is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            $stmt = $mysqli->prepare("INSERT INTO tbl_awards (award_name, award_date, venue, emp_id, name, joined_date, designation, joined_team, current_team, remarks, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->bind_param("sssssssssss", $award_name, $award_date, $venue, $emp_id, $name, $joined_date, $designation, $joined_team, $current_team, $remarks, $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Award record added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding award: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                $sql = "UPDATE tbl_awards SET 
                award_name = ?, award_date = ?, venue = ?, emp_id = ?, name = ?, 
                joined_date = ?, designation = ?, joined_team = ?, current_team = ?, 
                remarks = ?, updated_at = NOW(), updated_ip = ? WHERE id = ?";

                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("sssssssssssi", $award_name, $award_date, $venue, $emp_id, $name, $joined_date, $designation, $joined_team, $current_team, $remarks, $_SERVER['REMOTE_ADDR'], $id);
        
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Award record updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating award: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            $del = $mysqli->prepare("DELETE FROM tbl_awards WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Award record deleted", 'type' => 'warning']);
        }
    }
    
    if ($action !== 'import_excel') {
        redirect_with();
    }
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editAward = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_awards WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editAward = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editAward) $mode = 'view';
}

// Get employees and teams for dropdowns
$employees = $mysqli->query("SELECT employee_id, employee_name, employee_username FROM employee_master WHERE status = 'Active' ORDER BY employee_username ASC");
$teams = $mysqli->query("SELECT team_id, team_name FROM team_master WHERE status = 'Active' ORDER BY team_name ASC");

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$team_filter = trim($_GET['team_filter'] ?? '');
$award_filter = trim($_GET['award_filter'] ?? '');
$sortBy = $_GET['sort_by'] ?? 'award_date';
$sortOrder = 'DESC'; // Fixed to Descending, ascending/descending toggle removed

// Validate sort column
$allowedSorts = ['id', 'award_name', 'award_date', 'name', 'joined_date'];
if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'award_date';
}

// Get unique award names for filter dropdown
$award_names = $mysqli->query("SELECT DISTINCT award_name FROM tbl_awards ORDER BY award_name");

// Build query
$sql = "SELECT * FROM tbl_awards WHERE 1=1";
$params = [];
$types = '';

if ($q !== '') {
    $sql .= " AND (award_name LIKE ? OR name LIKE ? OR emp_id LIKE ? OR venue LIKE ? OR designation LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sssss';
}

if ($team_filter !== '') {
    $sql .= " AND (joined_team LIKE ? OR current_team LIKE ?)";
    $team_like = "%$team_filter%";
    $params[] = $team_like;
    $params[] = $team_like;
    $types .= 'ss';
}

if ($award_filter !== '') {
    $sql .= " AND award_name = ?";
    $params[] = $award_filter;
    $types .= 's';
}

$sql .= " ORDER BY $sortBy $sortOrder";

if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $awards = $stmt->get_result();
} else {
    $awards = $mysqli->query($sql);
}

// Group awards by award name and date
$groupedAwards = [];
if ($awards) {
    while ($row = $awards->fetch_assoc()) {
        $key = $row['award_name'] . '_' . $row['award_date'];
        if (!isset($groupedAwards[$key])) {
            $groupedAwards[$key] = [
                'award_name' => $row['award_name'],
                'award_date' => $row['award_date'],
                'venue' => $row['venue'],
                'recipients' => []
            ];
        }
        $groupedAwards[$key]['recipients'][] = $row;
    }
}

function getAwardColor($award_name) {
    // $colors = [
    //     '#4e73df', '#1cc88a', '#36b9cc', '#e74a3b', '#6f42c1', 
    //     '#fd7e14', '#20c9a6', '#3a7bd5', '#00b09b', '#5a5c69', 
    //     '#2c3e50', '#8e44ad', '#16a085', '#27ae60', '#2980b9', 
    //     '#8e44ad', '#2c3e50', '#d35400', '#c0392b', '#7f8c8d'
    // ];
    // $hash = abs(crc32($award_name)) % count($colors);
    // return $colors[$hash];
    return '#707981';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Awards Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body {
             background-color: #f4f6f9; 
             font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
           
        }
        .main-content {
            padding: 15px;
            max-width: 1800px;
            margin: 0 auto;
        }
        .award-section {
            margin-bottom: 15px;
            border: 1px solid #e0e4e8;
            border-radius: 6px;
            overflow: hidden;
            background-color: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .award-header {
            color: white;
            padding: 8px 15px;
        }
        .award-header h5 {
            margin: 0;
            font-weight: 600;
            font-size: 1.1rem;
        }
        .award-header p {
            margin: 2px 0 0 0;
            font-size: 0.85rem;
            opacity: 0.9;
        }
        .table-awards {
            margin-bottom: 0;
        }
        .table-awards th {
            background-color: #707981 !important;
            color: white !important;
            font-weight: 600;
            font-size: 0.85rem;
            padding: 8px 10px;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
        }
        .table-awards td {
            vertical-align: middle;
            font-size: 0.85rem;
            padding: 8px 10px;
            border-bottom: 1px solid #edf2f7;
        }
        .table-awards tr:last-child td {
            border-bottom: none;
        }
        .table-awards tr:hover {
            background-color: #f8fafc;
        }
        .card {
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            border: 1px solid #e0e4e8;
        }
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
        }
        .select2-container .select2-selection--single {
            height: 38px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 38px;
        }
        .filter-badge {
            background-color: #e9ecef;
            padding: 4px 8px;
            border-radius: 16px;
            margin-right: 5px;
            margin-bottom: 5px;
            display: inline-block;
            font-size: 0.85rem;
        }
        .employee-info {
            font-weight: 500;
            color: #2c3e50;
        }
        .team-badge {
            background-color: #edf2f7;
            color: #4a5568;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.8rem;
            display: inline-block;
            margin: 1px;
        }
        .remarks-text {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #6c757d;
            font-size: 0.85rem;
        }
        .action-buttons {
            white-space: nowrap;
        }
        .action-buttons .btn {
            padding: 0.2rem 0.4rem;
            font-size: 0.75rem;
        }
        .recipient-count {
            font-size: 0.75rem;
            background-color: rgba(255,255,255,0.2);
            padding: 2px 8px;
            border-radius: 12px;
        }
        .bg-readonly {
            background-color: #e9ecef !important;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show py-2" role="alert">
            <small><?= h($_GET['msg']) ?></small>
            <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
        
        <!-- Add/Edit Form -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5><i class="fas fa-trophy me-2"></i><?= $mode === 'edit' ? 'Edit Award Record' : 'Add New Award' ?></h5>
            <a href="awards.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>

        <div class="card">
            <div class="card-body p-3">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editAward['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="award_name" class="form-label small mb-1">Award Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="award_name" name="award_name" 
                                   value="<?= h($editAward['award_name'] ?? '') ?>" 
                                   placeholder="Everlasting Excellence 2025" required>
                        </div>
                        <div class="col-md-4">
                            <label for="award_date" class="form-label small mb-1">Award Date</label>
                            <input type="date" class="form-control form-control-sm" id="award_date" name="award_date"
                                   value="<?= h($editAward['award_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="venue" class="form-label small mb-1">Venue</label>
                            <input type="text" class="form-control form-control-sm" id="venue" name="venue"
                                   value="<?= h($editAward['venue'] ?? '') ?>" 
                                   placeholder="Hotel Lakshmi">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="emp_id" class="form-label small mb-1">ID <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm select2" id="emp_id" name="emp_id" required>
                                <option value="">Select ID...</option>
                                <?php 
                                if($employees):
                                    $employees->data_seek(0);
                                    while($emp = $employees->fetch_assoc()): ?>
                                        <option value="<?= h($emp['employee_username']) ?>"
                                                data-name="<?= h($emp['employee_name']) ?>"
                                            <?= (isset($editAward['emp_id']) && $editAward['emp_id'] === $emp['employee_username']) ? 'selected' : '' ?>>
                                            <?= h($emp['employee_username']) ?>
                                        </option>
                                    <?php endwhile;
                                endif; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="name" class="form-label small mb-1">Name</label>
                            <input type="text" class="form-control form-control-sm bg-readonly" id="name" name="name"
                                   value="<?= h($editAward['name'] ?? '') ?>" 
                                   placeholder="Auto-fills from ID..." readonly>
                        </div>
                        <div class="col-md-4">
                            <label for="joined_date" class="form-label small mb-1">Joined Date</label>
                            <input type="date" class="form-control form-control-sm" id="joined_date" name="joined_date"
                                   value="<?= h($editAward['joined_date'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="designation" class="form-label small mb-1">Designation</label>
                            <input type="text" class="form-control form-control-sm" id="designation" name="designation"
                                   value="<?= h($editAward['designation'] ?? '') ?>" 
                                   placeholder="Team Leader">
                        </div>
                        <div class="col-md-4">
                            <label for="joined_team" class="form-label small mb-1">Joined Team</label>
                            <select class="form-select form-select-sm" id="joined_team" name="joined_team">
                                <option value="">Select Team</option>
                                <?php 
                                if($teams):
                                    $teams->data_seek(0);
                                    while($team = $teams->fetch_assoc()): ?>
                                        <option value="<?= $team['team_name'] ?>"
                                            <?= (isset($editAward['joined_team']) && $editAward['joined_team'] == $team['team_name']) ? 'selected' : '' ?>>
                                            <?= h($team['team_name']) ?>
                                        </option>
                                    <?php endwhile;
                                endif; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="current_team" class="form-label small mb-1">Current Team</label>
                            <select class="form-select form-select-sm" id="current_team" name="current_team">
                                <option value="">Select Team</option>
                                <?php 
                                if($teams):
                                    $teams->data_seek(0);
                                    while($team = $teams->fetch_assoc()): ?>
                                        <option value="<?= $team['team_name'] ?>"
                                            <?= (isset($editAward['current_team']) && $editAward['current_team'] == $team['team_name']) ? 'selected' : '' ?>>
                                            <?= h($team['team_name']) ?>
                                        </option>
                                    <?php endwhile;
                                endif; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <label for="remarks" class="form-label small mb-1">Remarks</label>
                            <textarea class="form-control form-control-sm" id="remarks" name="remarks" rows="2" placeholder="Any comments..."><?= h($editAward['remarks'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm px-4">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update' : 'Save' ?>
                        </button>
                        <a href="awards.php" class="btn btn-outline-secondary btn-sm px-4">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5><i class="fas fa-trophy me-2"></i>Employee Awards & Recognition</h5>
            </div>
            <div>
                <button type="button" class="btn btn-warning text-dark fw-bold btn-sm me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-excel me-1"></i> Import Excel
                </button>
                <a href="awards.php?mode=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Add Award
                </a>
            </div>
        </div>

        <!-- Search and Filter Section -->
        <div class="card mb-3">
            <div class="card-body p-3">
                <form method="get" class="row g-2">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search awards, employees, ID..." value="<?= h($q) ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <select name="team_filter" class="form-select form-select-sm">
                            <option value="">All Teams</option>
                            <?php 
                            $teams_query = $mysqli->query("SELECT DISTINCT joined_team as team FROM tbl_awards WHERE joined_team != '' AND joined_team IS NOT NULL UNION SELECT DISTINCT current_team FROM tbl_awards WHERE current_team != '' AND current_team IS NOT NULL ORDER BY team");
                            if($teams_query):
                                while($team_row = $teams_query->fetch_assoc()): 
                                    $team_name = $team_row['team'];
                                    if(!empty($team_name)):
                            ?>
                                <option value="<?= h($team_name) ?>" <?= $team_filter === $team_name ? 'selected' : '' ?>>
                                    <?= h($team_name) ?>
                                </option>
                            <?php 
                                    endif;
                                endwhile;
                            endif; 
                            ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <select name="award_filter" class="form-select form-select-sm">
                            <option value="">All Awards</option>
                            <?php 
                            if($award_names):
                                while($award_row = $award_names->fetch_assoc()): 
                            ?>
                                <option value="<?= h($award_row['award_name']) ?>" <?= $award_filter === $award_row['award_name'] ? 'selected' : '' ?>>
                                    <?= h($award_row['award_name']) ?>
                                </option>
                            <?php 
                                endwhile;
                            endif; 
                            ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="fas fa-search me-1"></i> Filter
                        </button>
                    </div>
                    
                    <?php if($team_filter || $award_filter || $q): ?>
                    <div class="col-12 mt-2">
                        <div class="d-flex flex-wrap align-items-center">
                            <span class="small me-2">Active filters:</span>
                            <?php if($q): ?>
                                <span class="filter-badge">
                                    Search: <?= h($q) ?>
                                </span>
                            <?php endif; ?>
                            <?php if($team_filter): ?>
                                <span class="filter-badge">
                                    Team: <?= h($team_filter) ?>
                                </span>
                            <?php endif; ?>
                            <?php if($award_filter): ?>
                                <span class="filter-badge">
                                    Award: <?= h($award_filter) ?>
                                </span>
                            <?php endif; ?>
                            <a href="awards.php" class="btn btn-outline-secondary btn-sm ms-2">
                                <i class="fas fa-times me-1"></i> Clear All
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Awards Display -->
        <?php if(!empty($groupedAwards)): ?>
            <?php foreach($groupedAwards as $awardGroup): ?>
                <?php $awardColor = getAwardColor($awardGroup['award_name']); ?>
                <div class="award-section mb-2">
                    <div class="award-header d-flex justify-content-between align-items-center" style="background-color: <?= $awardColor ?>;">
                        <div>
                            <h5 class="mb-0"><?= h($awardGroup['award_name']) ?></h5>
                            <p class="mb-0 small">
                                <i class="fas fa-calendar me-1"></i><?= formatDate($awardGroup['award_date']) ?> 
                                <i class="fas fa-map-marker-alt ms-2 me-1"></i><?= h($awardGroup['venue']) ?>
                            </p>
                        </div>
                        <span class="recipient-count">
                            <i class="fas fa-users me-1"></i>
                            <?= count($awardGroup['recipients']) ?>
                        </span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-awards table-hover mb-0">
                            <thead style="background-color:#707981; color:#fff;">
                                <tr>
                                    <th width="4%">#</th>
                                    <th width="8%">ID</th>
                                    <th width="12%">Name</th>
                                    <th width="10%">Joined Date</th>
                                    <th width="12%">Designation</th>
                                    <th width="10%">Joined Team</th>
                                    <th width="10%">Current Team</th>
                                    <th width="20%">Remarks</th>
                                    <th width="14%">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $counter = 1; ?>
                                <?php foreach($awardGroup['recipients'] as $recipient): ?>
                                    <tr>
                                        <td><?= $counter++ ?></td>
                                        <td><code><?= h($recipient['emp_id'] ?? '') ?></code></td>
                                        <td>
                                            <div class="employee-info">
                                                <i class="fas fa-user-circle me-1 text-muted"></i>
                                                <?= h($recipient['name']) ?>
                                            </div>
                                        </td>
                                        <td><?= formatDate($recipient['joined_date']) ?></td>
                                        <td>
                                            <?php if(!empty($recipient['designation'])): ?>
                                                <span class="team-badge">
                                                    <i class="fas fa-briefcase me-1"></i>
                                                    <?= h($recipient['designation']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if(!empty($recipient['joined_team'])): ?>
                                                <span class="team-badge">
                                                    <i class="fas fa-users me-1"></i>
                                                    <?= h($recipient['joined_team']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if(!empty($recipient['current_team'])): ?>
                                                <span class="team-badge">
                                                    <i class="fas fa-users me-1"></i>
                                                    <?= h($recipient['current_team']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if(!empty($recipient['remarks'])): ?>
                                                <div class="remarks-text" title="<?= h($recipient['remarks']) ?>">
                                                    <i class="fas fa-comment me-1 text-muted"></i>
                                                    <?= h($recipient['remarks']) ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="action-buttons">
                                            <a href="awards.php?mode=edit&id=<?= (int)$recipient['id'] ?>" 
                                               class="btn btn-sm btn-warning" 
                                               title="Edit Record">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Delete award record for <?= h($recipient['name']) ?>?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$recipient['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" title="Delete Record">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <!-- Summary Section -->
            <div class="card mt-3">
                <div class="card-body p-3">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <div class="text-center p-2 bg-light rounded">
                                <h6 class="text-muted mb-1 small">Total Awards</h6>
                                <h4 class="mb-0"><?= count($groupedAwards) ?></h4>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center p-2 bg-light rounded">
                                <h6 class="text-muted mb-1 small">Total Recipients</h6>
                                <?php 
                                $totalRecipients = 0;
                                foreach($groupedAwards as $awardGroup) {
                                    $totalRecipients += count($awardGroup['recipients']);
                                }
                                ?>
                                <h4 class="mb-0"><?= $totalRecipients ?></h4>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center p-2 bg-light rounded">
                                <h6 class="text-muted mb-1 small">Unique Awards</h6>
                                <?php 
                                $uniqueAwards = array_unique(array_column($groupedAwards, 'award_name'));
                                ?>
                                <h4 class="mb-0"><?= count($uniqueAwards) ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
        <?php else: ?>
            <div class="card text-center py-4">
                <i class="fas fa-trophy fa-3x text-muted mb-2"></i>
                <h6 class="mb-1">No awards found</h6>
                <p class="small text-muted mb-2">Start recognizing your employees' achievements!</p>
                <a href="awards.php?mode=add" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus me-1"></i> Add Award
                </a>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- IMPORT MODAL -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-file-excel me-2"></i>Import Awards from Excel
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <form action="" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Select Excel File (.xlsx)</label>
                                    <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                                    <small class="text-muted">Maximum file size: 5MB</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Download Sample</label>
                                <button type="button" class="btn btn-outline-success w-100" onclick="downloadSample()">
                                    <i class="fas fa-download me-1"></i> Sample Format
                                </button>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <h6 class="fw-bold"><i class="fas fa-info-circle me-1"></i> Excel Format Required:</h6>
                            <p class="mb-1"><strong>Columns in exact order (No extra columns):</strong></p>
                            <ol class="mb-2">
                                <li><strong>Award Name</strong> - Name of the award (Mandatory)</li>
                                <li><strong>Award Date</strong> - Excel Date or YYYY-MM-DD</li>
                                <li><strong>Venue</strong> - Award Location</li>
                                <li><strong>id</strong> - ID (Mandatory)</li>
                                <li><strong>Name</strong> - Name (Mandatory)</li>
                                <li><strong>Joined date</strong> - Excel Date or YYYY-MM-DD</li>
                                <li><strong>Designation</strong> - Current designation</li>
                                <li><strong>Joined Team</strong> - Original team name</li>
                                <li><strong>Current team</strong> - Current team name</li>
                                <li><strong>Remarks</strong> - Additional notes</li>
                            </ol>
                            
                            <div class="border-top pt-2 mt-2">
                                <p class="mb-1"><strong><i class="fas fa-exclamation-triangle me-1"></i> Rules:</strong></p>
                                <ul class="mb-0">
                                    <li>If <strong>Award Name + Emp ID + Award Date</strong> matches an existing record, it will be <span class="badge bg-warning text-dark">UPDATED</span></li>
                                    <li>If no match, a new record will be <span class="badge bg-success">INSERTED</span></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i> Close
                        </button>
                        <button type="submit" class="btn btn-dark">
                            <i class="fas fa-upload me-1"></i> Import Data
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Select2 for employee dropdown
            $('.select2').select2({
                placeholder: 'Search employee ID...',
                allowClear: true,
                width: '100%'
            });
            
            // Trigger auto-fill on Employee ID select change
            $('#emp_id').on('change', function() {
                var selectedOption = $(this).find('option:selected');
                var empName = selectedOption.data('name');
                if (empName) {
                    $('#name').val(empName);
                } else {
                    $('#name').val('');
                }
            });

            // If editing an existing record, trigger change to set the name correctly 
            // (even though PHP sets it, this ensures it looks consistent)
            if ($('#emp_id').val() !== '') {
                $('#emp_id').trigger('change.select2');
            }
            
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
        });

        // Download sample Excel file formatted for Awards
        function downloadSample() {
            const sampleContent = "Award Name,Award Date,Venue,id,Name,Joined date,Designation,Joined Team,Current team,Remarks\n" +
                                 "Everlasting Excellence,2025-01-15,Hotel Chennai,EMP001,John Doe,2022-05-10,Team Leader,Development,Development,Great Performance\n" +
                                 "Star Performer,2025-02-01,Office Campus,EMP002,Jane Smith,2023-01-20,Senior Dev,QA,Development,Exceeded Targets";
            
            const blob = new Blob([sampleContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sample_awards_import.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>