<?php
require 'db.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00') return '—';
    return date('d-m-Y', strtotime($d));
}

function redirect_with($params = []) {
    $base = 'backup.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// ---------------------------------------------------------
// HANDLE IMPORT LOGIC (XLSX Format)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        // 1. Locate the SimpleXLSX Library
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        // Fallback paths just in case
        if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'Library file not found.', 'type' => 'danger']);
        }
        
        require_once $libPath;

        // 2. Initialize Parser
        $xlsx = null;
        if (class_exists('Shuchkin\SimpleXLSX')) {
            $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        } elseif (class_exists('SimpleXLSX')) {
            $xlsx = SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        } else {
            redirect_with(['msg' => 'Class SimpleXLSX not found.', 'type' => 'danger']);
        }

        // 3. Process Rows
        if ($xlsx) {
            $rows = $xlsx->rows();
            $imported_count = 0;
            $updated_count = 0;
            $skipped_count = 0;
            $error_details = [];
            
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header Row

                // --- COLUMN MAPPING ---
                // Columns: Team, Folder Name, Backup Date, Backup Name, Year, Path
                
                $team = trim($r[0] ?? '');
                $folder_name = trim($r[1] ?? '');
                $backup_date_raw = $r[2] ?? '';
                $backup_name = trim($r[3] ?? '');
                $year = trim($r[4] ?? '');
                $path = trim($r[5] ?? '');
                
                // Skip if essential data is missing
                if (empty($folder_name)) {
                    $skipped_count++;
                    $error_details[] = "Row " . ($k + 1) . ": Missing Folder Name";
                    continue;
                }

                // Handle Date conversions (Excel numeric dates)
                $backup_date = NULL;
                if (!empty($backup_date_raw)) {
                    if (is_numeric($backup_date_raw)) {
                        // Excel date to PHP date
                        if (class_exists('Shuchkin\SimpleXLSX')) {
                            $backup_date = date('Y-m-d', Shuchkin\SimpleXLSX::unixstamp($backup_date_raw));
                        } else {
                            $unix = ($backup_date_raw - 25569) * 86400;
                            $backup_date = gmdate("Y-m-d", $unix);
                        }
                    } else {
                        $ts = strtotime($backup_date_raw);
                        if ($ts !== false) $backup_date = date('Y-m-d', $ts);
                    }
                }

                // Check if backup already exists based on folder_name and backup_name
                $checkStmt = $mysqli->prepare("SELECT id FROM tbl_backups WHERE folder_name = ? AND backup_name = ?");
                $checkStmt->bind_param("ss", $folder_name, $backup_name);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->num_rows > 0;
                $checkStmt->close();

                if (!$exists) {
                    // Insert new record
                    $insertStmt = $mysqli->prepare("INSERT INTO tbl_backups 
                        (team, folder_name, backup_date, backup_name, year, path, created_at, created_ip) 
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)");
                    
                    $insertStmt->bind_param("sssssss", 
                        $team, 
                        $folder_name, 
                        $backup_date, 
                        $backup_name, 
                        $year, 
                        $path, 
                        $_SERVER['REMOTE_ADDR']
                    );
                    
                    if ($insertStmt->execute()) {
                        $imported_count++;
                    } else {
                        $skipped_count++;
                        $error_details[] = "Row " . ($k + 1) . ": " . $mysqli->error;
                    }
                    $insertStmt->close();
                } else {
                    // Update existing record
                    $updateStmt = $mysqli->prepare("UPDATE tbl_backups SET 
                        team = ?, backup_date = ?, year = ?, path = ?,
                        updated_at = NOW(), updated_ip = ? 
                        WHERE folder_name = ? AND backup_name = ?");
                    
                    $updateStmt->bind_param("sssssss", 
                        $team, 
                        $backup_date, 
                        $year, 
                        $path,
                        $_SERVER['REMOTE_ADDR'],
                        $folder_name,
                        $backup_name
                    );
                    
                    if ($updateStmt->execute()) {
                        $updated_count++;
                    }
                    $updateStmt->close();
                }
            }
            
            $msg = "Process Complete.<br>";
            $msg .= "<strong>Imported:</strong> $imported_count new backups.<br>";
            $msg .= "<strong>Updated:</strong> $updated_count existing backups.<br>";
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
        $team = trim($_POST['team'] ?? '');
        $folder_name = trim($_POST['folder_name'] ?? '');
        $backup_date = trim($_POST['backup_date'] ?? '');
        $backup_name = trim($_POST['backup_name'] ?? '');
        $year = trim($_POST['year'] ?? '');
        $path = trim($_POST['path'] ?? '');
        $status = trim($_POST['status'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date = trim($_POST['end_date'] ?? '');
        
        // Basic validation
        if ($folder_name === '') {
            redirect_with(['msg' => 'Folder Name is required.', 'type' => 'danger']);
        }
        
        // Set default dates to current date if empty
        if (empty($start_date)) $start_date = date('Y-m-d');
        if (empty($end_date)) $end_date = date('Y-m-d');
        if (empty($backup_date)) $backup_date = date('Y-m-d');
        
        if ($action === 'add') {
            // Insert new backup
            $stmt = $mysqli->prepare("INSERT INTO tbl_backups (team, folder_name, backup_date, backup_name, year, path, status, start_date, end_date, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->bind_param("ssssssssss", $team, $folder_name, $backup_date, $backup_name, $year, $path, $status, $start_date, $end_date, $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Backup record added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding backup: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Update backup
                $sql = "UPDATE tbl_backups SET 
                        team = ?, 
                        folder_name = ?, 
                        backup_date = ?, 
                        backup_name = ?, 
                        year = ?,
                        path = ?,
                        status = ?,
                        start_date = ?,
                        end_date = ?,
                        updated_at = NOW(), 
                        updated_ip = ? 
                        WHERE id = ?";
                
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssssssssssi", $team, $folder_name, $backup_date, $backup_name, $year, $path, $status, $start_date, $end_date, $_SERVER['REMOTE_ADDR'], $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Backup record updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating backup: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            // Delete backup
            $del = $mysqli->prepare("DELETE FROM tbl_backups WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Backup record deleted", 'type' => 'warning']);
        }
    }
    
    if ($action !== 'import_excel') {
        redirect_with();
    }
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editBackup = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_backups WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editBackup = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editBackup) $mode = 'view';
}

// Get teams for dropdown
$teams = $mysqli->query("SELECT team_id, team_name FROM team_master WHERE status = 'Active' ORDER BY team_name ASC");

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$sortBy = $_GET['sort_by'] ?? 'backup_date';
$sortOrder = $_GET['sort_order'] ?? 'DESC';

// Validate sort column
$allowedSorts = ['id', 'team', 'folder_name', 'backup_date', 'backup_name', 'year', 'status', 'start_date', 'end_date'];
if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'backup_date';
}

// Validate sort order
if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
    $sortOrder = 'DESC';
}

// Build query
$sql = "SELECT * FROM tbl_backups WHERE 1=1";

$params = [];
$types = '';

if ($q !== '') {
    $sql .= " AND (team LIKE ? OR folder_name LIKE ? OR backup_name LIKE ? OR path LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

$sql .= " ORDER BY $sortBy $sortOrder";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $backups = $stmt->get_result();
} else {
    $backups = $mysqli->query($sql);
}

// Get total count
$totalBackups = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_backups")->fetch_assoc()['cnt'];

// NEW: Get today's status counts
$today = date('Y-m-d');
$statusCounts = $mysqli->query("
    SELECT status, COUNT(*) as count 
    FROM tbl_backups 
    WHERE DATE(backup_date) = '$today' 
    GROUP BY status
")->fetch_all(MYSQLI_ASSOC);

// Initialize counts
$startCount = 0;
$inprogressCount = 0;
$endCount = 0;
$todayTotal = 0;

// Process status counts
foreach ($statusCounts as $row) {
    switch ($row['status']) {
        case 'start':
            $startCount = $row['count'];
            break;
        case 'inprogress':
            $inprogressCount = $row['count'];
            break;
        case 'end':
            $endCount = $row['count'];
            break;
    }
}
$todayTotal = $startCount + $inprogressCount + $endCount;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1800px;
            margin: 0 auto;
        }
        .table th {
            background-color: #707981 !important;
            color: white !important;
            border: none;
            font-size: 12px;
            white-space: nowrap;
        }
        .table td {
            vertical-align: middle;
            font-size: 13px;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
        }
        .backup-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .status-start { background-color: #28a745; color: white; font-weight: bold; }
        .status-inprogress { background-color: #ffc107; color: #000; font-weight: bold; }
        .status-end { background-color: #dc3545; color: white; font-weight: bold; }

        /* NEW: Status summary cards styling */
        .status-summary-card {
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            margin-bottom: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }
        .status-summary-card:hover {
            transform: translateY(-5px);
        }
        .status-summary-card .count {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .status-summary-card .label {
            font-size: 0.9rem;
            text-transform: uppercase;
            font-weight: 600;
        }
        .summary-total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .summary-start { background-color: #d4edda; color: #155724; border: 2px solid #c3e6cb; }
        .summary-inprogress { background-color: #fff3cd; color: #856404; border: 2px solid #ffeaa7; }
        .summary-end { background-color: #f8d7da; color: #721c24; border: 2px solid #f5c6cb; }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= $_GET['msg'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
        
        <!-- Add/Edit Form -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-database me-2"></i><?= $mode === 'edit' ? 'Edit Backup Record' : 'Add New Backup' ?></h4>
            <a href="backup.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editBackup['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="team" class="form-label">Team</label>
                                <select class="form-select" id="team" name="team">
                                    <option value="">Select Team</option>
                                    <?php 
                                    if($teams):
                                        $teams->data_seek(0);
                                        while($team = $teams->fetch_assoc()): 
                                            $selected = ($editBackup && $editBackup['team'] === $team['team_name']) ? 'selected' : '';
                                        ?>
                                            <option value="<?= h($team['team_name']) ?>" <?= $selected ?>>
                                                <?= h($team['team_name']) ?>
                                            </option>
                                        <?php endwhile;
                                    endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="folder_name" class="form-label">Folder Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="folder_name" name="folder_name" 
                                       value="<?= h($editBackup['folder_name'] ?? '') ?>" 
                                       placeholder="A-H" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="backup_date" class="form-label">Backup Date</label>
                                <input type="date" class="form-control" id="backup_date" name="backup_date"
                                       value="<?= h($editBackup['backup_date'] ?? date('Y-m-d')) ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="backup_name" class="form-label">Backup Name</label>
                                <input type="text" class="form-control" id="backup_name" name="backup_name"
                                       value="<?= h($editBackup['backup_name'] ?? '') ?>" 
                                       placeholder="Hyper_WK_A-H_2025.hbk">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="year" class="form-label">Year</label>
                                <input type="number" class="form-control" id="year" name="year"
                                       value="<?= h($editBackup['year'] ?? date('Y')) ?>" 
                                       min="2000" max="2030" placeholder="<?= date('Y') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="path" class="form-label">Path</label>
                                <input type="text" class="form-control" id="path" name="path"
                                       value="<?= h($editBackup['path'] ?? '') ?>" 
                                       placeholder="D:\Backup\Weekly\">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">Select Status</option>
                                    <option value="start" <?= ($editBackup && $editBackup['status'] === 'start') ? 'selected' : '' ?>>Start</option>
                                    <option value="inprogress" <?= ($editBackup && $editBackup['status'] === 'inprogress') ? 'selected' : '' ?>>In Progress</option>
                                    <option value="end" <?= ($editBackup && $editBackup['status'] === 'end') ? 'selected' : '' ?>>End</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="start_date" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="start_date" name="start_date"
                                       value="<?= h($editBackup['start_date'] ?? date('Y-m-d')) ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="end_date" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="end_date" name="end_date"
                                       value="<?= h($editBackup['end_date'] ?? date('Y-m-d')) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Backup' : 'Save Backup' ?>
                        </button>
                        <a href="backup.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4><i class="fas fa-database me-2"></i>Backup Management </h4>
                <p class="text-muted"><?= $totalBackups ?> Backups | Import Excel to add/update backup records</p>
            </div>
            <div>
                <button type="button" class="btn btn-warning text-dark fw-bold btn-sm me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-excel me-2"></i> Import Excel
                </button>
                <a href="backup.php?mode=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Add Backup
                </a>
            </div>
        </div>

        <!-- NEW: Status Summary Section -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="status-summary-card summary-total">
                    <div class="count"><?= $todayTotal ?></div>
                    <div class="label">Today's Backups</div>
                    <small><?= date('d M Y') ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="status-summary-card summary-start">
                    <div class="count"><?= $startCount ?></div>
                    <div class="label">Start Status</div>
                    <small>Backups in start phase</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="status-summary-card summary-inprogress">
                    <div class="count"><?= $inprogressCount ?></div>
                    <div class="label">In Progress</div>
                    <small>Backups currently running</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="status-summary-card summary-end">
                    <div class="count"><?= $endCount ?></div>
                    <div class="label">End Status</div>
                    <small>Completed backups</small>
                </div>
            </div>
        </div>

        <!-- Search and Filter Section -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control" 
                               placeholder="Search team, folder, backup name, path..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="sort_by" class="form-select">
                            <option value="backup_date" <?= $sortBy === 'backup_date' ? 'selected' : '' ?>>Sort by Backup Date</option>
                            <option value="team" <?= $sortBy === 'team' ? 'selected' : '' ?>>Sort by Team</option>
                            <option value="folder_name" <?= $sortBy === 'folder_name' ? 'selected' : '' ?>>Sort by Folder Name</option>
                            <option value="backup_name" <?= $sortBy === 'backup_name' ? 'selected' : '' ?>>Sort by Backup Name</option>
                            <option value="year" <?= $sortBy === 'year' ? 'selected' : '' ?>>Sort by Year</option>
                            <option value="status" <?= $sortBy === 'status' ? 'selected' : '' ?>>Sort by Status</option>
                            <option value="start_date" <?= $sortBy === 'start_date' ? 'selected' : '' ?>>Sort by Start Date</option>
                            <option value="end_date" <?= $sortBy === 'end_date' ? 'selected' : '' ?>>Sort by End Date</option>
                        </select>
                    </div>
                    <!--div class="col-md-2">
                        <select name="sort_order" class="form-select">
                            <option value="DESC" <?= $sortOrder === 'DESC' ? 'selected' : '' ?>>Descending</option>
                            <option value="ASC" <?= $sortOrder === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                        </select>
                    </div-->
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="backup.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Backups Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead style="background-color:#707981; color:#fff;">
                            <tr>
                                <th>S.No</th>
                                <th>Team</th>
                                <th>Folder Name</th>
                                <th>Backup Date</th>
                                <th>Backup Name</th>
                                <th>Year</th>
                                <th>Path</th>
                                <th>Status</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($backups && $backups->num_rows > 0): $i=0;?>
                                <?php while($row = $backups->fetch_assoc()): $i=$i+1;?>
                                <tr>
                                    <td><?= $i;?></td>
                                    <td>
                                        <?php if($row['team']): ?>
                                            <span class="badge bg-info"><?= h($row['team']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-semibold"><?= h($row['folder_name']) ?></td>
                                    <td><?= formatDate($row['backup_date']) ?></td>
                                    <td><code><?= h($row['backup_name']) ?></code></td>
                                    <td>
                                        <?php if($row['year']): ?>
                                            <span class="badge bg-secondary"><?= h($row['year']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($row['path']): ?>
                                            <small class="text-muted" title="<?= h($row['path']) ?>">
                                                <?= strlen($row['path']) > 30 ? substr($row['path'], 0, 30).'...' : h($row['path']) ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($row['status']): ?>
                                            <?php 
                                            $statusClass = '';
                                            $statusText = '';
                                            switch($row['status']) {
                                                case 'start': 
                                                    $statusClass = 'status-start'; 
                                                    $statusText = 'Start';
                                                    break;
                                                case 'inprogress': 
                                                    $statusClass = 'status-inprogress'; 
                                                    $statusText = 'In Progress';
                                                    break;
                                                case 'end': 
                                                    $statusClass = 'status-end'; 
                                                    $statusText = 'End';
                                                    break;
                                                default: 
                                                    $statusClass = 'bg-secondary';
                                                    $statusText = ucfirst($row['status']);
                                            }
                                            ?>
                                            <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= formatDate($row['start_date']) ?></td>
                                    <td><?= formatDate($row['end_date']) ?></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="backup.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Delete backup record for <?= h($row['folder_name']) ?>?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-database fa-3x mb-3"></i>
                                            <h5>No backup records found</h5>
                                            <p class="mb-0">Start tracking your backups here.</p>
                                            <a href="backup.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Backup
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

    <!-- IMPORT MODAL -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-file-excel me-2"></i>Import Backups from Excel
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
                                <li><strong>Team</strong> - Team Name (e.g. WK, Unique)</li>
                                <li><strong>Folder Name</strong> - Name of the specific folder (e.g. A-H)</li>
                                <li><strong>Backup Date</strong> - Excel Date or format like MM/DD/YYYY</li>
                                <li><strong>Backup Name</strong> - Detailed file name (e.g. Hyper_WK_A-H.hbk)</li>
                                <li><strong>Year</strong> - 4 digit year (e.g. 2021)</li>
                                <li><strong>Path</strong> - Directory location of backup file</li>
                            </ol>
                            
                            <div class="border-top pt-2 mt-2">
                                <p class="mb-1"><strong><i class="fas fa-exclamation-triangle me-1"></i> Rules:</strong></p>
                                <ul class="mb-0">
                                    <li>New records will be <span class="badge bg-success">INSERTED</span></li>
                                    <li>Existing records (matching Folder Name + Backup Name) will be <span class="badge bg-warning">UPDATED</span></li>
                                    <li>Rows missing the "Folder Name" column will be <span class="badge bg-danger">SKIPPED</span></li>
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
    <script>
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

            // Set default dates for new records
            const today = new Date().toISOString().split('T')[0];
            const currentYear = new Date().getFullYear();
            
            // Only set defaults for add mode
            <?php if($mode === 'add'): ?>
            if (!document.getElementById('backup_date').value) {
                document.getElementById('backup_date').value = today;
            }
            if (!document.getElementById('year').value) {
                document.getElementById('year').value = currentYear;
            }
            if (!document.getElementById('start_date').value) {
                document.getElementById('start_date').value = today;
            }
            
            <?php endif; ?>
        });

        // Download sample Excel file formatted for backups
        function downloadSample() {
            // Create sample CSV content
            const sampleContent = "Team,Folder Name,Backup Date,Backup Name,Year,Path\n" +
                                 "WK,A-H,10/24/2025,Hyper_WK_A-H_2025.hbk,2021,ftp:/2024/XML/2021\n" +
                                 "Unique,Unique_2021,10/25/2025,Hyper_Unique_2025.hbk,2022,ftp:/2024/XML/2022\n" +
                                 "WK,I Journals,10/27/2025,Hyper_WK_I_2025.hbk,2023,ftp:/2024/XML/2023\n" +
                                 "WK,Q-Z,10/28/2025,Hyper_WK_Q-Z_2025.hbk,2024,ftp:/2024/XML/2024";
            
            const blob = new Blob([sampleContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sample_backup_import.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>