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

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ========== EXCEL IMPORT HANDLER ==========
    if ($action === 'import_excel') {
        if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
            
            // Path to Library
            $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
            
            if (!file_exists($libPath)) {
                // Fallback without src
                $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
            }

            if (!file_exists($libPath)) {
                redirect_with(['msg' => 'Library file not found at: ' . $libPath, 'type' => 'danger']);
            }
            
            require_once $libPath;
            
            // Check for Namespace or Global class
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
                $count = 0;
                $errors = 0;
                $errorMessages = [];
                
                foreach ($rows as $k => $r) {
                    if ($k === 0) continue; // Skip Header
                    
                    // Skip empty rows
                    if (empty($r[0]) && empty($r[1]) && empty($r[2]) && empty($r[3])) {
                        continue;
                    }
                    
                    // Map Excel Columns: Team(0), Folder Name(1), Backup Date(2), Backup Name(3), Year(4), Path(5), Status(6), Start Date(7), End Date(8)
                    $team         = $mysqli->real_escape_string(trim($r[0] ?? ''));
                    $folder_name  = $mysqli->real_escape_string(trim($r[1] ?? ''));
                    $backup_date  = $mysqli->real_escape_string(trim($r[2] ?? date('Y-m-d')));
                    $backup_name  = $mysqli->real_escape_string(trim($r[3] ?? ''));
                    $year         = $mysqli->real_escape_string(trim($r[4] ?? date('Y')));
                    $path         = $mysqli->real_escape_string(trim($r[5] ?? ''));
                    $status       = 'Start';
                    $start_date   = $mysqli->real_escape_string(trim($r[7] ?? date('Y-m-d')));
                    $end_date     = $mysqli->real_escape_string(trim($r[8] ?? date('Y-m-d')));
                    
                    // Validate required fields
                    if (empty($folder_name)) {
                        $errorMessages[] = "Row " . ($k + 1) . ": Folder Name is required";
                        $errors++;
                        continue;
                    }
                    
                    // Validate and format dates
                    $dateFormats = [
                        'Y-m-d',
                        'd/m/Y',
                        'm/d/Y',
                        'd-m-Y',
                        'm-d-Y',
                        'Y/m/d',
                        'd.m.Y',
                        'm.d.Y'
                    ];
                    
                    // Format backup_date
                    if (!empty($backup_date)) {
                        $formattedDate = false;
                        foreach ($dateFormats as $format) {
                            $date = DateTime::createFromFormat($format, $backup_date);
                            if ($date) {
                                $formattedDate = $date->format('Y-m-d');
                                break;
                            }
                        }
                        
                        if ($formattedDate) {
                            $backup_date = $formattedDate;
                        } else {
                            $backup_date = date('Y-m-d');
                            $errorMessages[] = "Row " . ($k + 1) . ": Invalid Backup Date format, using today's date";
                        }
                    } else {
                        $backup_date = date('Y-m-d');
                    }
                    
                    // Format start_date
                    if (!empty($start_date)) {
                        $formattedDate = false;
                        foreach ($dateFormats as $format) {
                            $date = DateTime::createFromFormat($format, $start_date);
                            if ($date) {
                                $formattedDate = $date->format('Y-m-d');
                                break;
                            }
                        }
                        
                        if ($formattedDate) {
                            $start_date = $formattedDate;
                        } else {
                            $start_date = date('Y-m-d');
                        }
                    } else {
                        $start_date = date('Y-m-d');
                    }
                    
                    // Format end_date
                    if (!empty($end_date)) {
                        $formattedDate = false;
                        foreach ($dateFormats as $format) {
                            $date = DateTime::createFromFormat($format, $end_date);
                            if ($date) {
                                $formattedDate = $date->format('Y-m-d');
                                break;
                            }
                        }
                        
                        if ($formattedDate) {
                            $end_date = $formattedDate;
                        } else {
                            $end_date = date('Y-m-d');
                        }
                    } else {
                        $end_date = date('Y-m-d');
                    }
                    
                    // Validate status
                    $allowed_statuses = ['start', 'inprogress', 'end'];
                    if (!empty($status) && !in_array(strtolower($status), $allowed_statuses)) {
                        $status = '';
                    }
                    
                    // Validate year
                    if (!empty($year) && !is_numeric($year)) {
                        $year = date('Y');
                        $errorMessages[] = "Row " . ($k + 1) . ": Invalid year, using current year";
                    }
                    
                    // Insert the record using prepared statement
                    $stmt = $mysqli->prepare("INSERT INTO tbl_backups 
                        (team, folder_name, backup_date, backup_name, year, path, status, start_date, end_date, created_at, created_ip) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
                    
                    if (!$stmt) {
                        $errorMessages[] = "Row " . ($k + 1) . ": Database prepare error - " . $mysqli->error;
                        $errors++;
                        continue;
                    }
                    
                    $stmt->bind_param("ssssssssss", 
                        $team, 
                        $folder_name, 
                        $backup_date, 
                        $backup_name, 
                        $year, 
                        $path, 
                        $status, 
                        $start_date, 
                        $end_date, 
                        $_SERVER['REMOTE_ADDR']
                    );
                    
                    if ($stmt->execute()) {
                        $count++;
                    } else {
                        $errorMessages[] = "Row " . ($k + 1) . ": Database error - " . $stmt->error;
                        $errors++;
                    }
                    $stmt->close();
                }
                
                // Store errors in session for display
                if (!empty($errorMessages)) {
                    $_SESSION['import_errors'] = $errorMessages;
                }
                
                if ($count > 0) {
                    redirect_with(['msg' => "Import Complete. Successfully added $count backup record(s)." . ($errors > 0 ? " $errors record(s) had errors." : ""), 'type' => 'success']);
                } else {
                    redirect_with(['msg' => "No records were imported. " . ($errors > 0 ? " $errors error(s) found." : ""), 'type' => 'warning']);
                }
                
            } else {
                $err = (method_exists('Shuchkin\SimpleXLSX', 'parseError')) ? Shuchkin\SimpleXLSX::parseError() : 'Unknown Error';
                redirect_with(['msg' => 'Excel Parse Error: ' . $err, 'type' => 'danger']);
            }
        } else {
            $errorMsg = 'No file uploaded.';
            if (isset($_FILES['excel_file']['error'])) {
                $uploadErrors = [
                    1 => 'File exceeds upload_max_filesize',
                    2 => 'File exceeds MAX_FILE_SIZE',
                    3 => 'File partially uploaded',
                    4 => 'No file uploaded',
                    6 => 'Missing temporary folder',
                    7 => 'Failed to write to disk',
                    8 => 'PHP extension stopped the upload'
                ];
                $errorMsg = $uploadErrors[$_FILES['excel_file']['error']] ?? 'Upload error';
            }
            redirect_with(['msg' => 'File upload error: ' . $errorMsg, 'type' => 'danger']);
        }
    }
    
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
    
    redirect_with();
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

// Get today's status counts
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
            background-color: #2c3e50;
            color: white;
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

        /* Status summary cards styling */
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
        
        /* Import/Export buttons */
        .btn-import {
            background-color: #0dcaf0;
            border-color: #0dcaf0;
            color: white;
        }
        .btn-import:hover {
            background-color: #0bacd0;
            border-color: #0bacd0;
        }
        .btn-export {
            background-color: #20c997;
            border-color: #20c997;
            color: white;
        }
        .btn-export:hover {
            background-color: #1ba87e;
            border-color: #1ba87e;
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
            <h4><i class="fas fa-database me-2"></i>Backup Management (<?= $totalBackups ?> Records)</h4>
            <div class="d-flex gap-2">
                <!-- Import Button -->
                <button type="button" class="btn btn-import btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-import me-1"></i> Import Excel
                </button>
                <!-- Export Button -->
                <a href="export_backups.php" class="btn btn-export btn-sm">
                    <i class="fas fa-file-export me-1"></i> Export Excel
                </a>
                <!-- Add Button -->
                <a href="backup.php?mode=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Add Backup
                </a>
            </div>
        </div>

        <!-- IMPORT EXCEL MODAL -->
        <div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title"><i class="fas fa-file-excel"></i> Import Backup Records</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="import_excel">
                            <div class="mb-3">
                                <label class="form-label">Select Excel File</label>
                                <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls,.csv" required>
                                <div class="form-text small">
                                    Supported formats: .xlsx, .xls, .csv
                                </div>
                            </div>
                            
                            <div class="alert alert-warning small">
                                <strong>Excel Format Requirements:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>First row should be headers (will be skipped during import)</li>
                                    <li>Columns should be in this order:
                                        <ol class="mb-0 mt-1 small">
                                            <li><strong>Team</strong></li>
                                            <li><strong>Folder Name</strong> (required)</li>
                                            <li>Backup Date (YYYY-MM-DD)</li>
                                            <li>Backup Name</li>
                                            <li>Year</li>
                                            <li>Path</li>
                                            <li>Status (start, inprogress, end)</li>
                                            <li>Start Date (YYYY-MM-DD)</li>
                                            <li>End Date (YYYY-MM-DD)</li>
                                        </ol>
                                    </li>
                                    <!--li class="mt-2">
                                        <a href="template_generator_backup.php" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-download me-1"></i> Download Template
                                        </a>
                                    </li-->
                                </ul>
                            </div>
                            
                            <?php if(isset($_SESSION['import_errors']) && !empty($_SESSION['import_errors'])): ?>
                            <div class="alert alert-danger small">
                                <strong>Import Errors:</strong>
                                <ul class="mb-0 mt-2">
                                    <?php foreach($_SESSION['import_errors'] as $error): ?>
                                        <li><?= h($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php 
                            unset($_SESSION['import_errors']); 
                            endif; 
                            ?>
                            
                            <div class="d-flex justify-content-end">
                                <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Close</button>
                                <button type="submit" class="btn btn-info text-white">Import</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Summary Section -->
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
                    <div class="col-md-2">
                        <select name="sort_order" class="form-select">
                            <option value="DESC" <?= $sortOrder === 'DESC' ? 'selected' : '' ?>>Descending</option>
                            <option value="ASC" <?= $sortOrder === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                        </select>
                    </div>
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
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Team</th>
                                <th>Folder Name</th>
                                <th>Backup Date</th>
                                <th>Backup Name</th>
                                <th>Year</th>
                                <th>Path</th>
                                <th>Status</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Created At</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($backups && $backups->num_rows > 0): ?>
                                <?php while($row = $backups->fetch_assoc()): ?>
                                <tr>
                                    <td><?= (int)$row['id'] ?></td>
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
                                    <td><?= date('d-m-Y H:i', strtotime($row['created_at'])) ?></td>
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
                                    <td colspan="12" class="text-center py-5">
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
    </script>
</body>
</html>