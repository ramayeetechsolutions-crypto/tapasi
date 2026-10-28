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

function redirect_with($params = []) {
    $base = 'access_control.php';
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
                    
                    // Map Excel Columns: Access No(0), Name(1), Team(2), Emp ID(3), Card Fee(4), Issue Type(5), Issue Date(6)
                    $access_no      = $mysqli->real_escape_string(trim($r[0] ?? ''));
                    $name           = $mysqli->real_escape_string(trim($r[1] ?? ''));
                    $team           = $mysqli->real_escape_string(trim($r[2] ?? ''));
                    $emp_id         = $mysqli->real_escape_string(trim($r[3] ?? ''));
                    $card_fee       = !empty($r[4]) ? floatval($r[4]) : 100;
                    $issue_type     = $mysqli->real_escape_string(trim($r[5] ?? 'New'));
                    $issue_date     = $mysqli->real_escape_string(trim($r[6] ?? date('Y-m-d')));
                    
                    // Validate required fields
                    if (empty($access_no)) {
                        $errorMessages[] = "Row " . ($k + 1) . ": Access No is required";
                        $errors++;
                        continue;
                    }
                    
                    // Check if access_no already exists
                    $checkStmt = $mysqli->prepare("SELECT id FROM tbl_access_cards WHERE access_no = ?");
                    $checkStmt->bind_param("s", $access_no);
                    $checkStmt->execute();
                    $exists = $checkStmt->get_result()->num_rows > 0;
                    $checkStmt->close();
                    
                    if ($exists) {
                        $errorMessages[] = "Row " . ($k + 1) . ": Access No '$access_no' already exists";
                        $errors++;
                        continue;
                    }
                    
                    // Format issue date
                    if (!empty($issue_date)) {
                        $issue_date = date('Y-m-d', strtotime($issue_date));
                    } else {
                        $issue_date = date('Y-m-d');
                    }
                    
                    // Validate issue type
                    $allowed_types = ['New', 'Missing', 'Fine'];
                    if (!in_array($issue_type, $allowed_types)) {
                        $issue_type = 'New';
                    }
                    
                    // Insert the record
                    $stmt = $mysqli->prepare("INSERT INTO tbl_access_cards 
                        (access_no, name, team, emp_id, card_fee, issue_type, issue_date, created_at, created_ip) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
                    
                    $stmt->bind_param("ssssdsss", 
                        $access_no, 
                        $name, 
                        $team, 
                        $emp_id, 
                        $card_fee, 
                        $issue_type, 
                        $issue_date, 
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
                
                redirect_with(['msg' => "Import Complete. Added: $count, Errors: $errors", 'type' => 'success']);
                
            } else {
                $err = (method_exists('Shuchkin\SimpleXLSX', 'parseError')) ? Shuchkin\SimpleXLSX::parseError() : 'Unknown Error';
                redirect_with(['msg' => 'SimpleXLSX Parse Error: ' . $err, 'type' => 'danger']);
            }
        } else {
            redirect_with(['msg' => 'No file uploaded or file error.', 'type' => 'danger']);
        }
    }
    
    if ($action === 'add' || $action === 'edit') {
        $access_no = trim($_POST['access_no'] ?? '');
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $team_id = (int)($_POST['team_id'] ?? 0);
        $card_fee = trim($_POST['card_fee'] ?? '100');
        
        // Get Type and Date
        $issue_type = $_POST['issue_type'] ?? 'New';
        $issue_date = $_POST['issue_date'] ?? date('Y-m-d'); // Default to today

        // Get employee details
        $employee_name = '';
        $emp_id = '';
        if ($employee_id > 0) {
            $empStmt = $mysqli->prepare("SELECT employee_name, employee_username FROM employee_master WHERE employee_id = ?");
            $empStmt->bind_param("i", $employee_id);
            $empStmt->execute();
            $empResult = $empStmt->get_result();
            if ($empRow = $empResult->fetch_assoc()) {
                $employee_name = $empRow['employee_name'];
                $emp_id = $empRow['employee_username'];
            }
            $empStmt->close();
        } else {
            // If no employee selected, use manual input
            $employee_name = trim($_POST['employee_name'] ?? '');
            $emp_id = trim($_POST['emp_id'] ?? '');
        }

        // Get team name
        $team_name = '';
        if ($team_id > 0) {
            $teamStmt = $mysqli->prepare("SELECT team_name FROM team_master WHERE team_id = ?");
            $teamStmt->bind_param("i", $team_id);
            $teamStmt->execute();
            $teamResult = $teamStmt->get_result();
            if ($teamRow = $teamResult->fetch_assoc()) {
                $team_name = $teamRow['team_name'];
            }
            $teamStmt->close();
        } else {
            $team_name = trim($_POST['team_name'] ?? '');
        }

        // Basic validation
        if ($access_no === '') {
            redirect_with(['msg' => 'Access No is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Check exists logic
            $checkStmt = $mysqli->prepare("SELECT id FROM tbl_access_cards WHERE access_no = ?");
            $checkStmt->bind_param("s", $access_no);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                 redirect_with(['msg' => "Access No '$access_no' already exists!", 'type' => 'danger']);
            }
            $checkStmt->close();
            
            // UPDATED INSERT: Added issue_type and issue_date
            $stmt = $mysqli->prepare("INSERT INTO tbl_access_cards (access_no, name, team, emp_id, card_fee, issue_type, issue_date, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->bind_param("ssssdsss", $access_no, $employee_name, $team_name, $emp_id, $card_fee, $issue_type, $issue_date, $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Access card added successfully", 'type' => 'success']);
            }
            $stmt->close();
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                // UPDATED UPDATE: Added issue_type and issue_date
                $sql = "UPDATE tbl_access_cards SET 
                        access_no = ?, name = ?, team = ?, emp_id = ?, card_fee = ?, 
                        issue_type = ?, issue_date = ?, 
                        updated_at = NOW(), updated_ip = ? 
                        WHERE id = ?";
                
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssssdsssi", $access_no, $employee_name, $team_name, $emp_id, $card_fee, $issue_type, $issue_date, $_SERVER['REMOTE_ADDR'], $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Access card updated successfully", 'type' => 'success']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            // Delete access card
            $del = $mysqli->prepare("DELETE FROM tbl_access_cards WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Access card deleted", 'type' => 'warning']);
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editCard = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_access_cards WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editCard = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editCard) $mode = 'view';
}

// Get employees and teams for dropdowns
$employees = $mysqli->query("SELECT employee_id, employee_name, employee_username FROM employee_master WHERE status = 'Active' ORDER BY employee_name ASC");
$teams = $mysqli->query("SELECT team_id, team_name FROM team_master WHERE status = 'Active' ORDER BY team_name ASC");

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$sortBy = $_GET['sort_by'] ?? 'access_no';
$sortOrder = $_GET['sort_order'] ?? 'ASC';

// Validate sort column
$allowedSorts = ['id', 'access_no', 'name', 'team', 'emp_id'];
if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'access_no';
}

// Validate sort order
if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
    $sortOrder = 'ASC';
}

// Build query
$sql = "SELECT * FROM tbl_access_cards WHERE 1=1";

$params = [];
$types = '';

if ($q !== '') {
    $sql .= " AND (access_no LIKE ? OR name LIKE ? OR team LIKE ? OR emp_id LIKE ?)";
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
    $cards = $stmt->get_result();
} else {
    $cards = $mysqli->query($sql);
}

// Get total count
$totalCards = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_access_cards")->fetch_assoc()['cnt'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Card Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
        .select2-container .select2-selection--single {
            height: 38px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 38px;
        }
        /* Import button styling */
        .btn-info {
            background-color: #0dcaf0;
            border-color: #0dcaf0;
        }
        .btn-info:hover {
            background-color: #0bacd0;
            border-color: #0bacd0;
        }
        /* Modal styling */
        .modal-header.bg-info {
            background-color: #0d6efd !important;
        }
        /* Template download link */
        .btn-outline-primary {
            border-color: #0d6efd;
            color: #0d6efd;
        }
        .btn-outline-primary:hover {
            background-color: #0d6efd;
            color: white;
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
            <h4><i class="fas fa-id-card me-2"></i><?= $mode === 'edit' ? 'Edit Access Card' : 'Add New Access Card' ?></h4>
            <a href="access_control.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editCard['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="access_no" class="form-label">Access No. <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="access_no" name="access_no" 
                                       value="<?= h($editCard['access_no'] ?? '') ?>" 
                                       placeholder=" " required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="employee_id" class="form-label">Employee <span class="text-danger">*</span></label>
                                <select class="form-select select2" id="employee_id" name="employee_id" required>
                                    <option value="">Select Employee</option>
                                    <?php 
                                    if($employees):
                                        $employees->data_seek(0);
                                        while($emp = $employees->fetch_assoc()): ?>
                                            <option value="<?= $emp['employee_id'] ?>" 
                                                <?= (isset($editCard['emp_id']) && $editCard['emp_id'] == $emp['employee_username']) ? 'selected' : '' ?>>
                                                <?= h($emp['employee_name']) ?> (<?= h($emp['employee_username']) ?>)
                                            </option>
                                        <?php endwhile;
                                    endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="team_id" class="form-label">Team</label>
                                <select class="form-select" id="team_id" name="team_id">
                                    <option value="">Select Team</option>
                                    <?php 
                                    if($teams):
                                        $teams->data_seek(0);
                                        while($team = $teams->fetch_assoc()): ?>
                                            <option value="<?= $team['team_id'] ?>"
                                                <?= (isset($editCard['team']) && $editCard['team'] == $team['team_name']) ? 'selected' : '' ?>>
                                                <?= h($team['team_name']) ?>
                                            </option>
                                        <?php endwhile;
                                    endif; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Issue Type Column -->
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="issue_type" class="form-label">Card Status (Type)</label>
                                <select class="form-select" id="issue_type" name="issue_type" required>
                                    <option value="New" <?= (isset($editCard['issue_type']) && $editCard['issue_type'] == 'New') ? 'selected' : '' ?>>New</option>
                                    <option value="Missing" <?= (isset($editCard['issue_type']) && $editCard['issue_type'] == 'Missing') ? 'selected' : '' ?>>Missing</option>
                                    <option value="Fine" <?= (isset($editCard['issue_type']) && $editCard['issue_type'] == 'Fine') ? 'selected' : '' ?>>Fine</option>
                                </select>
                            </div>
                        </div>

                        <!-- Issue Date Column -->
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="issue_date" class="form-label">Issue Date</label>
                                <input type="date" class="form-control" id="issue_date" name="issue_date"
                                       value="<?= h($editCard['issue_date'] ?? date('Y-m-d')) ?>" required>
                            </div>
                        </div>

                        <!-- Fee Column -->
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="card_fee" class="form-label">Card Fee</label>
                                <input type="number" step="0.01" class="form-control" id="card_fee" name="card_fee"
                                       value="<?= $mode === 'edit' ? h($editCard['card_fee'] ?? '100') : '100' ?>" 
                                       placeholder="0.00">
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Card' : 'Save Card' ?>
                        </button>
                        <a href="access_control.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-id-card me-2"></i>Access Card Management (<?= $totalCards ?> Cards)</h4>
            <div class="d-flex gap-2">
                <!-- Import Button -->
                <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-excel me-1"></i> Import Excel
                </button>
                <!-- Export Button -->
                <a href="export_access_cards.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-file-export me-1"></i> Export
                </a>
                <a href="access_control.php?mode=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Add Card
                </a>
            </div>
        </div>

        <!-- IMPORT EXCEL MODAL -->
        <div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title"><i class="fas fa-file-excel"></i> Import Access Cards</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="import_excel">
                            <div class="mb-3">
                                <label class="form-label">Select Excel File (.xlsx)</label>
                                <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls,.csv" required>
                                <div class="form-text small">
                                    Supported formats: .xlsx, .xls, .csv
                                </div>
                            </div>
                            
                            <div class="alert alert-warning small">
                                <strong>Excel Format Requirements:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>First row should be headers</li>
                                    <li>Columns should be in this order:
                                        <ol class="mb-0 mt-1">
                                            <li><strong>Access No</strong> (required)</li>
                                            <li>Name</li>
                                            <li>Team</li>
                                            <li>Emp ID</li>
                                            <li>Card Fee (default: 100)</li>
                                            <li>Issue Type (New/Missing/Fine)</li>
                                            <li>Issue Date (YYYY-MM-DD)</li>
                                        </ol>
                                    </li>
                                    <!--li class="mt-2">
                                        <a href="template_generator.php" class="btn btn-sm btn-outline-primary">
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

        <!-- Search and Filter Section -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control" 
                               placeholder="Search access no, employee, team..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="sort_by" class="form-select">
                            <option value="access_no" <?= $sortBy === 'access_no' ? 'selected' : '' ?>>Sort by Access No</option>
                            <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Sort by Name</option>
                            <option value="team" <?= $sortBy === 'team' ? 'selected' : '' ?>>Sort by Team</option>
                            <option value="emp_id" <?= $sortBy === 'emp_id' ? 'selected' : '' ?>>Sort by Emp ID</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="sort_order" class="form-select">
                            <option value="ASC" <?= $sortOrder === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                            <option value="DESC" <?= $sortOrder === 'DESC' ? 'selected' : '' ?>>Descending</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="access_control.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Cards Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Access No</th>
                                <th>Name</th>
                                <th>Team</th>
                                <th>Emp ID</th>
                                <th>Status</th>
                                <th>Issue Date</th>
                                <th>Card Fee</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($cards && $cards->num_rows > 0): ?>
                                <?php while($row = $cards->fetch_assoc()): ?>
                                <?php 
                                    // Logic for Refund Notification
                                    $refundBadge = '';
                                    if ($row['issue_type'] === 'New' && !empty($row['issue_date'])) {
                                        $iDate = new DateTime($row['issue_date']);
                                        $now = new DateTime();
                                        $diff = $iDate->diff($now);
                                        // Calculate total months difference
                                        $months = $diff->m + ($diff->y * 12);
                                        
                                        if ($months >= 3) {
                                            $refundBadge = '<div class="mt-1"><span class="badge bg-success">Return ₹100</span></div>';
                                        }
                                    }
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?= h($row['access_no']) ?></td>
                                    <td><?= h($row['name']) ?></td>
                                    <td><span class="badge bg-primary"><?= h($row['team']) ?></span></td>
                                    <td><code><?= h($row['emp_id']) ?></code></td>
                                    
                                    <!-- New Status Column -->
                                    <td>
                                        <?php if($row['issue_type'] == 'New'): ?>
                                            <span class="badge bg-info text-dark">New</span>
                                        <?php elseif($row['issue_type'] == 'Missing'): ?>
                                            <span class="badge bg-danger">Missing</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?= h($row['issue_type']) ?></span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- New Date Column with Refund Logic -->
                                    <td>
                                        <?= $row['issue_date'] ? date('d-M-Y', strtotime($row['issue_date'])) : '-' ?>
                                        <?= $refundBadge ?>
                                    </td>

                                    <td><?= $row['card_fee'] ? '₹ ' . number_format($row['card_fee'], 2) : '₹ 0.00' ?></td>
                                    
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="access_control.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Delete access card <?= h($row['access_no']) ?>?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-id-card fa-3x mb-3"></i>
                                            <h5>No access cards found</h5>
                                            <p class="mb-0">Start managing employee access cards.</p>
                                            <a href="access_control.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Card
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {

    // Logic for Access Card Fee
    $('#issue_type').on('change', function() {
        var type = $(this).val();
        
        // If New or Missing is selected, set fee to 100
        if (type === 'New' || type === 'Missing') {
            $('#card_fee').val('100');
        } 
        
        // If Fine, set fee to 10
        else if (type === 'Fine') {
            $('#card_fee').val('10');
        }
        // If Fine is selected (or empty), clear the field so you can type manually
        else {
            $('#card_fee').val(''); 
        }
    });

    // ... rest of your code ...
            // Initialize Select2 for employee dropdown
            $('.select2').select2({
                placeholder: 'Search employee...',
                allowClear: true
            });
            
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
    </script>
</body>
</html>