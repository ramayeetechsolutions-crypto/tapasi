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

function redirect_with($params =[]) {
    $base = 'access_control.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    echo "<script>window.location.href='$base';</script>";
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
            $error_details =[];
            
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header Row

                // --- NEW COLUMN MAPPING ---
                // Columns: ACCESS No., Emp ID, Name, Team, DOJ, Issue Date, Status (Issue Type), Remarks, Card Fee
                
                $access_no_raw = trim($r[0] ?? '');
                $emp_id = trim($r[1] ?? '');         
                $employee_name = trim($r[2] ?? '');  
                $team = trim($r[3] ?? '');           
                $doj_raw = $r[4] ?? '';
                $issue_date_raw = $r[5] ?? '';
                $issue_type = trim($r[6] ?? 'Card Fee'); // Status moved to column 7 (index 6)
                $remarks = trim($r[7] ?? '');            // Remarks moved to column 8 (index 7)
                $card_fee = trim($r[8] ?? '100');        // Card Fee moved to column 9 (index 8)
                
                // Skip if essential data is missing
                if (empty($employee_name) || empty($emp_id)) {
                    $skipped_count++;
                    $error_details[] = "Row " . ($k + 1) . ": Missing employee name or ID";
                    continue;
                }

                // Generate unique access_no if empty
                $access_no = !empty($access_no_raw) ? $access_no_raw : 'CARD' . date('Ymd') . str_pad($k, 4, '0', STR_PAD_LEFT);

                // Handle Date conversions (Excel numeric dates)
                $doj = NULL;
                if (!empty($doj_raw)) {
                    if (is_numeric($doj_raw)) {
                        if (class_exists('Shuchkin\SimpleXLSX')) {
                            $doj = date('Y-m-d', Shuchkin\SimpleXLSX::unixstamp($doj_raw));
                        } else {
                            $unix = ($doj_raw - 25569) * 86400;
                            $doj = gmdate("Y-m-d", $unix);
                        }
                    } else {
                        $ts = strtotime($doj_raw);
                        if ($ts !== false) $doj = date('Y-m-d', $ts);
                    }
                }

                $issue_date = NULL;
                if (!empty($issue_date_raw)) {
                    if (is_numeric($issue_date_raw)) {
                        if (class_exists('Shuchkin\SimpleXLSX')) {
                            $issue_date = date('Y-m-d', Shuchkin\SimpleXLSX::unixstamp($issue_date_raw));
                        } else {
                            $unix = ($issue_date_raw - 25569) * 86400;
                            $issue_date = gmdate("Y-m-d", $unix);
                        }
                    } else {
                        $ts = strtotime($issue_date_raw);
                        if ($ts !== false) $issue_date = date('Y-m-d', $ts);
                    }
                }

                // Default issue_date to today if not provided
                if (empty($issue_date)) {
                    $issue_date = date('Y-m-d');
                }

                // Check if employee exists in employee_master
                $empCheck = $mysqli->prepare("SELECT employee_id, employee_username, employee_name FROM employee_master WHERE employee_username = ? OR employee_name = ?");
                $empCheck->bind_param("ss", $emp_id, $employee_name);
                $empCheck->execute();
                $empResult = $empCheck->get_result();
                
                if ($empResult->num_rows > 0) {
                    $empData = $empResult->fetch_assoc();
                    $emp_username = $empData['employee_username'];
                    $emp_name = $empData['employee_name'];
                } else {
                    $emp_username = $emp_id;
                    $emp_name = $employee_name;
                }
                $empCheck->close();

                // Check if access card exists by Access No (Preferred) or Fallback
                $exists = false;
                if (!empty($access_no_raw)) {
                    $checkStmt = $mysqli->prepare("SELECT id FROM tbl_access_cards WHERE access_no = ?");
                    $checkStmt->bind_param("s", $access_no_raw);
                    $checkStmt->execute();
                    $exists = $checkStmt->get_result()->num_rows > 0;
                    $checkStmt->close();
                } else {
                    $checkStmt = $mysqli->prepare("SELECT id FROM tbl_access_cards WHERE emp_id = ? AND issue_type = ? AND issue_date = ?");
                    $checkStmt->bind_param("sss", $emp_username, $issue_type, $issue_date);
                    $checkStmt->execute();
                    $exists = $checkStmt->get_result()->num_rows > 0;
                    $checkStmt->close();
                }

                if (!$exists) {
                    // Insert new record
                    $insertStmt = $mysqli->prepare("INSERT INTO tbl_access_cards 
                        (access_no, name, team, emp_id, card_fee, issue_type, issue_date, doj, remarks, created_at, created_ip) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
                    
                    $insertStmt->bind_param("ssssdsssss", 
                        $access_no, 
                        $emp_name, 
                        $team, 
                        $emp_username, 
                        $card_fee, 
                        $issue_type, 
                        $issue_date, 
                        $doj,
                        $remarks,
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
                    if (!empty($access_no_raw)) {
                        $updateStmt = $mysqli->prepare("UPDATE tbl_access_cards SET 
                            name = ?, team = ?, emp_id = ?, card_fee = ?, issue_type = ?, issue_date = ?, doj = ?, remarks = ?,
                            updated_at = NOW(), updated_ip = ? 
                            WHERE access_no = ?");
                        
                        $updateStmt->bind_param("sssdssssss", 
                            $emp_name, $team, $emp_username, $card_fee, $issue_type, $issue_date, $doj, $remarks, $_SERVER['REMOTE_ADDR'], $access_no_raw
                        );
                    } else {
                        $updateStmt = $mysqli->prepare("UPDATE tbl_access_cards SET 
                            access_no = ?, name = ?, team = ?, card_fee = ?, issue_type = ?, issue_date = ?, doj = ?, remarks = ?,
                            updated_at = NOW(), updated_ip = ? 
                            WHERE emp_id = ? AND issue_type = ? AND issue_date = ?");
                        
                        $updateStmt->bind_param("sssdssssssss", 
                            $access_no, $emp_name, $team, $card_fee, $issue_type, $issue_date, $doj, $remarks, $_SERVER['REMOTE_ADDR'], $emp_username, $issue_type, $issue_date
                        );
                    }
                    
                    if ($updateStmt->execute()) {
                        $updated_count++;
                    }
                    $updateStmt->close();
                }
            }
            
            $msg = "Process Complete.<br>";
            $msg .= "<strong>Imported:</strong> $imported_count new cards.<br>";
            $msg .= "<strong>Updated:</strong> $updated_count existing cards.<br>";
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

// Handle POST actions (add, edit, delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $access_no = trim($_POST['access_no'] ?? '');
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $team_id = (int)($_POST['team_id'] ?? 0);
        $card_fee = trim($_POST['card_fee'] ?? '100');
        $doj = $_POST['doj'];
        $remarks = trim($_POST['remarks'] ?? '');
        
        // Get Type and Date
        $issue_type = $_POST['issue_type'] ?? 'Card Fee';
        $issue_date = $_POST['issue_date'] ?? date('Y-m-d');

        // Fetch employee name, username, and DOJ
        $employee_name = '';
        $emp_username = '';
        $emp_doj = '';
        if ($employee_id > 0) {
            $empStmt = $mysqli->prepare("SELECT employee_name, employee_username, joining_date FROM employee_master WHERE employee_id = ?");
            $empStmt->bind_param("i", $employee_id);
            $empStmt->execute();
            $empResult = $empStmt->get_result();
            if ($empRow = $empResult->fetch_assoc()) {
                $employee_name = $empRow['employee_name'];
                $emp_username = $empRow['employee_username'];
                $emp_doj = $empRow['joining_date'];
            }
            $empStmt->close();
        }

        // Fetch team name
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
        }

        // Use DOJ from form if provided, otherwise from employee master
        $doj = !empty($_POST['doj']) ? $_POST['doj'] : $emp_doj;

        // Basic validation
        if ($access_no === '') {
            redirect_with(['msg' => 'Access No is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Check if access_no already exists
            $checkStmt = $mysqli->prepare("SELECT id FROM tbl_access_cards WHERE access_no = ?");
            $checkStmt->bind_param("s", $access_no);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                 redirect_with(['msg' => "Access No '$access_no' already exists!", 'type' => 'danger']);
            }
            $checkStmt->close();
            
            // Generate access_no if not provided
            if (empty($access_no)) {
                $access_no = 'CARD' . date('Ymd') . rand(1000, 9999);
            }
            
            // INSERT with issue_type, issue_date, doj, and remarks
            $stmt = $mysqli->prepare("INSERT INTO tbl_access_cards (access_no, name, team, emp_id, card_fee, issue_type, issue_date, doj, remarks, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->bind_param("ssssdsssss", $access_no, $employee_name, $team_name, $emp_username, $card_fee, $issue_type, $issue_date, $doj, $remarks, $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Access card added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => "Error adding card: " . $mysqli->error, 'type' => 'danger']);
            }
            $stmt->close();
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                // UPDATE with issue_type, issue_date, doj, and remarks
                $sql = "UPDATE tbl_access_cards SET 
                        access_no = ?, name = ?, team = ?, emp_id = ?, card_fee = ?, 
                        issue_type = ?, issue_date = ?, doj = ?, remarks = ?,
                        updated_at = NOW(), updated_ip = ? 
                        WHERE id = ?";
                
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssssdsssssi", $access_no, $employee_name, $team_name, $emp_username, $card_fee, $issue_type, $issue_date, $doj, $remarks, $_SERVER['REMOTE_ADDR'], $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Access card updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => "Error updating card: " . $mysqli->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            $del = $mysqli->prepare("DELETE FROM tbl_access_cards WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Access card deleted", 'type' => 'warning']);
        }
    }
    
    if ($action !== 'import_excel') {
        redirect_with();
    }
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
        
        // If we have an edit card, also get the employee_id for the dropdown
        if ($editCard && !empty($editCard['emp_id'])) {
            $empStmt = $mysqli->prepare("SELECT employee_id FROM employee_master WHERE employee_username = ?");
            $empStmt->bind_param("s", $editCard['emp_id']);
            $empStmt->execute();
            $empResult = $empStmt->get_result();
            if ($empRow = $empResult->fetch_assoc()) {
                $editCard['employee_id'] = $empRow['employee_id'];
            }
            $empStmt->close();
        }
        
        // If we have an edit card, also get the team_id for the dropdown
        if ($editCard && !empty($editCard['team'])) {
            $teamStmt = $mysqli->prepare("SELECT team_id FROM team_master WHERE team_name = ?");
            $teamStmt->bind_param("s", $editCard['team']);
            $teamStmt->execute();
            $teamResult = $teamStmt->get_result();
            if ($teamRow = $teamResult->fetch_assoc()) {
                $editCard['team_id'] = $teamRow['team_id'];
            }
            $teamStmt->close();
        }
    }
    if (!$editCard) $mode = 'view';
}

// Get employees and teams for dropdowns
$employees = $mysqli->query("SELECT employee_id, employee_name, employee_username, joining_date FROM employee_master WHERE status = 'Active' ORDER BY employee_name ASC");
$teams = $mysqli->query("SELECT team_id, team_name FROM team_master WHERE status = 'Active' ORDER BY team_name ASC");

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$sortBy = $_GET['sort_by'] ?? 'id';
$sortOrder = $_GET['sort_order'] ?? 'ASC';

// Validate sort column
$allowedSorts =['id', 'access_no', 'name', 'team', 'emp_id', 'issue_date', 'doj', 'card_fee', 'remarks'];
if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'id';
}

// Validate sort order
if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
    $sortOrder = 'DESC';
}

// Build query
$sql = "SELECT * FROM tbl_access_cards WHERE 1=1";

$params =[];
$types = '';

if ($q !== '') {
    $sql .= " AND (access_no LIKE ? OR name LIKE ? OR team LIKE ? OR emp_id LIKE ? OR issue_type LIKE ? OR remarks LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssssss';
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
    <title>Access Card</title>
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
            margin-top: 60px;
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
        .select2-container .select2-selection--single {
            height: 38px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 38px;
        }
        .form-section {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <!-- PAGE HEADER -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold text-dark mb-0"><i class="fas fa-id-card me-2"></i>Access Card</h4>
                <p class="text-muted"><?= $totalCards ?> Cards | Import Excel to add/update cards</p>
            </div>
            
            <div>
                <button type="button" class="btn btn-warning text-dark fw-bold me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-excel me-2"></i> Import from Excel
                </button>
                <a href="access_control.php?mode=add" class="btn btn-success fw-bold">
                    <i class="fas fa-plus me-2"></i> Add New Card
                </a>
            </div>
        </div>

        <!-- ALERTS -->
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= $_GET['msg'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
        
        <!-- Add/Edit Form Section -->
        <div class="card">
            <div class="card-header bg-<?= $mode === 'add' ? 'success' : 'warning' ?> text-white">
                <h5 class="mb-0">
                    <i class="fas fa-<?= $mode === 'add' ? 'plus' : 'edit' ?> me-2"></i>
                    <?= $mode === 'add' ? 'Add New Access Card' : 'Edit Access Card' ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editCard['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="access_no" class="form-label">Access No. <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="access_no" name="access_no" 
                                       value="<?= h($editCard['access_no'] ?? '') ?>" 
                                       placeholder="e.g., CARD001" required>
                                <small class="text-muted">Unique identifier for the card</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="employee_id" class="form-label">Employee <span class="text-danger">*</span></label>
                                <select class="form-select select2" id="employee_id" name="employee_id" required>
                                    <option value="">Select Employee</option>
                                    <?php 
                                    if($employees):
                                        $employees->data_seek(0);
                                        while($emp = $employees->fetch_assoc()): 
                                            $selected = '';
                                            if ($mode === 'edit' && isset($editCard['emp_id']) && $editCard['emp_id'] == $emp['employee_username']) {
                                                $selected = 'selected';
                                            }
                                            ?>
                                            <option value="<?= $emp['employee_id'] ?>" data-doj="<?= $emp['joining_date'] ?>" <?= $selected ?>>
                                                <?= h($emp['employee_name']) ?> (<?= h($emp['employee_username']) ?>)
                                            </option>
                                        <?php endwhile;
                                    endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="team_id" class="form-label">Team</label>
                                <select class="form-select" id="team_id" name="team_id">
                                    <option value="">Select Team</option>
                                    <?php 
                                    if($teams):
                                        $teams->data_seek(0);
                                        while($team = $teams->fetch_assoc()): 
                                            $selected = '';
                                            if ($mode === 'edit' && isset($editCard['team_id']) && $editCard['team_id'] == $team['team_id']) {
                                                $selected = 'selected';
                                            }
                                            ?>
                                            <option value="<?= $team['team_id'] ?>" <?= $selected ?>>
                                                <?= h($team['team_name']) ?>
                                            </option>
                                        <?php endwhile;
                                    endif; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="doj" class="form-label">Date of Joining (DOJ)</label>
                                <input type="date" class="form-control" id="doj" name="doj"
                                       value="<?= h($editCard['joining_date'] ??  date('Y-m-d')) ?>">
                                <small class="text-muted">Auto-fetches from employee master</small>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="issue_type" class="form-label">Card Status <span class="text-danger">*</span></label>
                                <select class="form-select" id="issue_type" name="issue_type" required>
                                    <option value="Card Fee" <?= (isset($editCard['issue_type']) && $editCard['issue_type'] == 'Card Fee') ? 'selected' : '' ?>>Card Fee</option>
                                    <option value="Card Missing" <?= (isset($editCard['issue_type']) && $editCard['issue_type'] == 'Card Missing') ? 'selected' : '' ?>>Card Missing</option>
                                    <option value="Fine" <?= (isset($editCard['issue_type']) && $editCard['issue_type'] == 'Fine') ? 'selected' : '' ?>>Fine</option>
                                    <option value="N/A" <?= (isset($editCard['issue_type']) && $editCard['issue_type'] == 'N/A') ? 'selected' : '' ?>>N/A</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="issue_date" class="form-label">Issue Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="issue_date" name="issue_date"
                                       value="<?= h($editCard['issue_date'] ?? date('Y-m-d')) ?>" required>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="card_fee" class="form-label">Card Fee (₹)</label>
                                <input type="number" step="0.01" class="form-control" id="card_fee" name="card_fee"
                                       value="<?= $mode === 'edit' ? h($editCard['card_fee'] ?? '100') : '100' ?>" 
                                       placeholder="0.00">
                                <small class="text-muted">Auto-updates based on status</small>
                            </div>
                        </div>
                    </div>

                    <!-- NEW REMARKS FIELD -->
                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label for="remarks" class="form-label">Remarks</label>
                                <textarea class="form-control" id="remarks" name="remarks" rows="2" placeholder="Any additional notes..."><?= h($editCard['remarks'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-<?= $mode === 'add' ? 'success' : 'warning' ?>">
                            <i class="fas fa-<?= $mode === 'add' ? 'plus' : 'save' ?> me-1"></i>
                            <?= $mode === 'add' ? 'Add Card' : 'Update Card' ?>
                        </button>
                        <a href="access_control.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-1"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode - Search and Filter Section -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-5">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" name="q" class="form-control" 
                                   placeholder="Search by Access No, Name, Team, ID, Status..." value="<?= h($q) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select name="sort_by" class="form-select">
                            <option value="id" <?= $sortBy === 'id' ? 'selected' : '' ?>>Sort by S.NO</option>
                            <option value="access_no" <?= $sortBy === 'access_no' ? 'selected' : '' ?>>Sort by Access No</option>
                            <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Sort by Name</option>
                            <option value="team" <?= $sortBy === 'team' ? 'selected' : '' ?>>Sort by Team</option>
                            <option value="emp_id" <?= $sortBy === 'emp_id' ? 'selected' : '' ?>>Sort by ID</option>
                            <option value="issue_date" <?= $sortBy === 'issue_date' ? 'selected' : '' ?>>Sort by Issue Date</option>
                            <option value="doj" <?= $sortBy === 'doj' ? 'selected' : '' ?>>Sort by DOJ</option>
                            <option value="card_fee" <?= $sortBy === 'card_fee' ? 'selected' : '' ?>>Sort by Card Fee</option>
                        </select>
                    </div>
                    <!--div class="col-md-2">
                        <select name="sort_order" class="form-select">
                            <option value="DESC" <?= $sortOrder === 'DESC' ? 'selected' : '' ?>>Descending</option>
                            <option value="ASC" <?= $sortOrder === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                        </select>
                    </div-->
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-1"></i> Apply
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Cards Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead style="background-color:#707981; color:#fff;">
                            <tr>
                                <th>S.No</th>
                                <th>Access No</th>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Team</th>
                                
                                <th>DOJ</th>
                                <th>Issue Date</th>
                                <th>Status</th>
                                <th>Remarks</th>
                                <th>Card Fee</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($cards && $cards->num_rows > 0): $i=0;?>
                                <?php while($row = $cards->fetch_assoc()): $i++;?>
                                <?php 
                                    // Logic for Refund Notification (only for Card Fee)
                                    $refundBadge = '';
                                    if ($row['issue_type'] === 'Card Fee' && !empty($row['issue_date'])) {
                                        $iDate = new DateTime($row['issue_date']);
                                        $now = new DateTime();
                                        $diff = $iDate->diff($now);
                                        $months = $diff->m + ($diff->y * 12);
                                        
                                        if ($months >= 3) {
                                            $refundBadge = '<span class="badge bg-success ms-1">Return ₹100</span>';
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><?= $i ?></td>
                                    <td><code><?= h($row['access_no']) ?></code></td>
                                    <td><code><?= h($row['emp_id']) ?></code></td>
                                    <td class="fw-bold"><?= h($row['name']) ?></td>
                                    <td><span class="badge bg-info"><?= h($row['team']) ?></span></td>
                                    
                                    <td><?= !empty($row['doj']) ? date('d-m-Y', strtotime($row['doj'])) : '-' ?></td>
                                    <td>
                                        <?= date('d-m-Y', strtotime($row['issue_date'])) ?>
                                        <?= $refundBadge ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $badgeClass = 'secondary';
                                        if($row['issue_type'] == 'Card Fee') $badgeClass = 'info';
                                        elseif($row['issue_type'] == 'Card Missing') $badgeClass = 'danger';
                                        elseif($row['issue_type'] == 'Fine') $badgeClass = 'warning';
                                        ?>
                                        <span class="badge bg-<?= $badgeClass ?>"><?= h($row['issue_type']) ?></span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= h($row['remarks']) ?></small>
                                    </td>
                                    <td>₹ <?= number_format($row['card_fee'], 2) ?></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="access_control.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button type="button" class="btn btn-danger" title="Delete"
                                                    onclick="confirmDelete(<?= $row['id'] ?>, '<?= h($row['access_no']) ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-id-card fa-3x mb-3"></i>
                                            <h5>No access cards found</h5>
                                            <p class="mb-0">Click "Add New Card" to create your first access card.</p>
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
                        <i class="fas fa-file-excel me-2"></i>Import Access Cards from Excel
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
                            <p class="mb-1"><strong>Columns in exact order:</strong></p>
                            <ol class="mb-2">
                                <li><strong>ACCESS No.</strong> - Unique identifier (If blank, auto-generated)</li>
                                <li><strong>Emp ID</strong> - Unique employee identifier</li>
                                <li><strong>Name</strong> - Full name of employee</li>
                                <li><strong>Team</strong> - Department/Team name</li>
                                <li><strong>DOJ</strong> - Date of Joining (YYYY-MM-DD or Excel date)</li>
                                <li><strong>Issue Date</strong> - Card issue date (YYYY-MM-DD or Excel date)</li>
                                <li><strong>Status</strong> - Card Fee / Card Missing / Fine / N/A</li>
                                <li><strong>Remarks</strong> - Any additional notes</li>
                                <li><strong>Card Fee</strong> - Fee amount (100, 10, etc.)</li>
                            </ol>
                            
                            <div class="border-top pt-2 mt-2">
                                <p class="mb-1"><strong><i class="fas fa-exclamation-triangle me-1"></i> Rules:</strong></p>
                                <ul class="mb-0">
                                    <li>If <strong>ACCESS No.</strong> matches an existing record, it will <span class="badge bg-warning">UPDATE</span> the record.</li>
                                    <li>If no match, a new card will be <span class="badge bg-success">INSERTED</span>.</li>
                                    <li>Rows with missing Employee Name or ID will be <span class="badge bg-danger">SKIPPED</span>.</li>
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

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete access card <strong id="deleteCardNo"></strong>?</p>
                    <p class="text-danger mb-0"><small>This action cannot be undone.</small></p>
                </div>
                <div class="modal-footer">
                    <form method="post" id="deleteForm">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="deleteId" value="">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </div>
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
                placeholder: 'Search employee...',
                allowClear: true,
                width: '100%'
            });

            // Auto-populate DOJ when employee is selected
            $('#employee_id').on('change', function() {
                var selectedOption = $(this).find('option:selected');
                var doj = selectedOption.data('joining_date');
                if (doj) {
                    $('#doj').val(doj);
                }
            });

            // Logic for Access Card Fee auto-population
            $('#issue_type').on('change', function() {
                var type = $(this).val();
                
                if (type === 'Card Fee' || type === 'Card Missing') {
                    $('#card_fee').val('100');
                } else if (type === 'Fine') {
                    $('#card_fee').val('10');
                } else {
                    $('#card_fee').val(''); 
                }
            });

            // Trigger change on page load for edit mode to set correct fee
            $('#issue_type').trigger('change');
            
            // Auto-dismiss alerts after 5 seconds
            setTimeout(function() {
                var alertBox = document.querySelector('.alert');
                if (alertBox) {
                    var alert = new bootstrap.Alert(alertBox);
                    alert.close();
                }
            }, 5000);
        });

        // Delete confirmation function
        function confirmDelete(id, accessNo) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteCardNo').textContent = accessNo;
            var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            deleteModal.show();
        }

        // Download sample Excel file
        function downloadSample() {
            // Create sample CSV content
            const sampleContent = "ACCESS No.,Emp ID,Name,Team,DOJ,Issue Date,Status,Remarks,Card Fee\n" +
                                 "CARD001,EMP001,John Doe,IT,2024-01-15,2024-03-20,Card Fee,New employee,100\n" +
                                 "CARD002,EMP002,Jane Smith,HR,2024-02-01,2024-03-21,Card Missing,Card replacement,100\n" +
                                 "CARD003,EMP003,Bob Johnson,Finance,2024-01-10,2024-03-22,Fine,Late fee,10";
            
            const blob = new Blob([sampleContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sample_access_card_import.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>
<?php
if (isset($mysqli)) {
    $mysqli->close();
}
?>