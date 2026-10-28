<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Include SimpleXLSX library (for backwards compatibility if they upload .xlsx)
$libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = 'simplexlsx-master/SimpleXLSX.php';

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

function redirect_with($params =[]) {
    $base = 'error_entry.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Fetch dropdown data from master tables
// Departments fetch from team_master as requested
$departments_from_master = $mysqli->query("SELECT team_name AS department_name FROM team_master WHERE status = 'Active' ORDER BY team_name");
$employees = $mysqli->query("SELECT employee_name, employee_username FROM employee_master WHERE status = 'Active' ORDER BY employee_name");
$clients = $mysqli->query("SELECT client_name FROM client_master WHERE status = 'Active' ORDER BY client_name");

// Handle Excel/CSV Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        $ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
        $rows =[];
        $parse_error = '';

        // Handle CSV Import directly
        if ($ext === 'csv') {
            if (($handle = fopen($_FILES['excel_file']['tmp_name'], "r")) !== FALSE) {
                // Handle potential BOM in CSV
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }
                while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
                    $rows[] = $data;
                }
                fclose($handle);
            } else {
                $parse_error = "Failed to open CSV file.";
            }
        } 
        // Handle XLSX Import via Library
        else {
            if (!file_exists($libPath)) {
                redirect_with(['msg' => 'SimpleXLSX library not found. Please install it.', 'type' => 'danger']);
            }
            require_once $libPath;
            $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);

            if ($xlsx) {
                $rows = $xlsx->rows();
            } else {
                $parse_error = \Shuchkin\SimpleXLSX::parseError();
            }
        }

        if (!empty($rows)) {
            $count_added = 0;
            $count_updated = 0;
            $count_skipped = 0;
            $errors =[];

            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header row

                // Shifted indices by +1 to accommodate S.No at index 0
                $sno                = trim($r[0] ?? ''); // S.No is ignored for DB insertion
                $week               = trim($r[1] ?? '');           
                $error_date         = trim($r[2] ?? '');     
                $team               = trim($r[3] ?? '');  
                $department         = trim($r[4] ?? '');         
                $error_taken_by     = trim($r[5] ?? '');             
                $internal_external  = trim($r[6] ?? '');     
                $client_name        = trim($r[7] ?? '');       
                $article            = trim($r[8] ?? ''); 
                $error_type         = trim($r[9] ?? '');        
                $error_subtype      = trim($r[10] ?? '');        
                $yn                 = trim($r[11] ?? '');        
                $error_made_by      = trim($r[12] ?? '');        
                $reason             = trim($r[13] ?? '');        

                // Format Date for MySQL if not empty
                $formatted_date = null;
                if (!empty($error_date)) {
                    $formatted_date = date('Y-m-d', strtotime($error_date));
                }

                // Validate required fields
                if (empty($week) || empty($error_type) || empty($client_name)) {
                    $errors[] = "Row " . ($k + 1) . ": Week, Error Type, and Client are required fields";
                    $count_skipped++;
                    continue;
                }

                // Validate Y/N values
                if (!empty($yn) && !in_array($yn,['Yes', 'No'])) {
                    $errors[] = "Row " . ($k + 1) . ": Invalid Y/N value '$yn'. Must be 'Yes' or 'No'";
                    $count_skipped++;
                    continue;
                }

                $empty_photo = ''; // Images cannot be easily imported from Excel/CSV cells

                // Insert new error log entry (excluding S.No)
                $stmt = $mysqli->prepare("INSERT INTO error_log 
                    (week, error_date, team, department, error_taken_by, internal_external, client_name, article, error_type, error_subtype, yn, error_made_by, reason, error_photo, status, created_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW())");
                
                $stmt->bind_param("ssssssssssssss", 
                    $week, $formatted_date, $team, $department, $error_taken_by, $internal_external, $client_name, $article, $error_type, $error_subtype, $yn, $error_made_by, $reason, $empty_photo);
                
                if ($stmt->execute()) {
                    $count_added++;
                } else {
                    $errors[] = "Row " . ($k + 1) . ": Insert error - " . $stmt->error;
                    $count_skipped++;
                }
                $stmt->close();
            }
            
            $message = "Import completed: ";
            if ($count_added > 0) $message .= "$count_added records added. ";
            if ($count_skipped > 0) $message .= "$count_skipped records skipped. ";
            
            if (!empty($errors)) {
                $message .= "Errors: " . implode("; ", array_slice($errors, 0, 5));
                if (count($errors) > 5) $message .= "... (and " . (count($errors) - 5) . " more)";
                redirect_with(['msg' => $message, 'type' => 'warning']);
            } else {
                redirect_with(['msg' => $message, 'type' => 'success']);
            }
            
        } else {
            redirect_with(['msg' => 'Failed to parse file: ' . ($parse_error ?: 'File contains no valid data'), 'type' => 'danger']);
        }
    } else {
        redirect_with(['msg' => 'Please select a valid CSV/Excel file to upload.', 'type' => 'danger']);
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $week               = trim($_POST['week'] ?? '');
        $error_date         = trim($_POST['error_date'] ?? '');
        $team               = trim($_POST['team'] ?? '');
        $department         = trim($_POST['department'] ?? '');
        $error_taken_by     = trim($_POST['error_taken_by'] ?? '');
        $internal_external  = trim($_POST['internal_external'] ?? '');
        $client_name        = trim($_POST['client_name'] ?? '');
        $article            = trim($_POST['article'] ?? '');
        $error_type         = trim($_POST['error_type'] ?? '');
        $error_subtype      = trim($_POST['error_subtype'] ?? '');
        $yn                 = trim($_POST['yn'] ?? '');
        $error_made_by      = trim($_POST['error_made_by'] ?? '');
        $reason             = trim($_POST['reason'] ?? '');
        
        // Date handling
        $formatted_date = empty($error_date) ? null : date('Y-m-d', strtotime($error_date));

        // Basic validation
        if ($week === '' || $error_type === '' || $client_name === '') {
            redirect_with(['msg' => 'Week, Error Type and Client Name are required.', 'type' => 'danger']);
        }

        // Image Upload Logic
        $error_photo = '';
        if (isset($_FILES['error_photo']) && $_FILES['error_photo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/error_photos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $ext = pathinfo($_FILES['error_photo']['name'], PATHINFO_EXTENSION);
            $error_photo = 'err_' . time() . '_' . rand(1000,9999) . '.' . $ext;
            
            if (!move_uploaded_file($_FILES['error_photo']['tmp_name'], $uploadDir . $error_photo)) {
                $error_photo = ''; // reset if upload failed
            }
        }
        
        if ($action === 'add') {
            $stmt = $mysqli->prepare("INSERT INTO error_log 
                (week, error_date, team, department, error_taken_by, internal_external, client_name, article, error_type, error_subtype, yn, error_made_by, reason, error_photo, status, created_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW())");
            
            $stmt->bind_param("ssssssssssssss", $week, $formatted_date, $team, $department, $error_taken_by, $internal_external, $client_name, $article, $error_type, $error_subtype, $yn, $error_made_by, $reason, $error_photo);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Error log entry added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding entry: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                if ($error_photo !== '') {
                    // Update WITH new photo
                    $stmt = $mysqli->prepare("UPDATE error_log SET 
                        week = ?, error_date = ?, team = ?, department = ?, error_taken_by = ?, internal_external = ?, client_name = ?, article = ?, error_type = ?, error_subtype = ?, yn = ?, error_made_by = ?, reason = ?, error_photo = ? 
                        WHERE id = ?");
                    $stmt->bind_param("ssssssssssssssi", $week, $formatted_date, $team, $department, $error_taken_by, $internal_external, $client_name, $article, $error_type, $error_subtype, $yn, $error_made_by, $reason, $error_photo, $id);
                } else {
                    // Update WITHOUT changing photo
                    $stmt = $mysqli->prepare("UPDATE error_log SET 
                        week = ?, error_date = ?, team = ?, department = ?, error_taken_by = ?, internal_external = ?, client_name = ?, article = ?, error_type = ?, error_subtype = ?, yn = ?, error_made_by = ?, reason = ? 
                        WHERE id = ?");
                    $stmt->bind_param("sssssssssssssi", $week, $formatted_date, $team, $department, $error_taken_by, $internal_external, $client_name, $article, $error_type, $error_subtype, $yn, $error_made_by, $reason, $id);
                }
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Error log updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating entry: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT client_name FROM error_log WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($del_client_name);
            $res->fetch();
            $res->close();
            
            $del = $mysqli->prepare("DELETE FROM error_log WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Entry for client '$del_client_name' deleted", 'type' => 'warning']);
        }
    }
    
    redirect_with();
}

$mode = $_GET['mode'] ?? 'view';
$editEntry = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT id, week, error_date, team, department, error_taken_by, internal_external, client_name, article, error_type, error_subtype, yn, error_made_by, reason, error_photo, status, created_date 
                                 FROM error_log WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editEntry = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editEntry) $mode = 'view';
}

$q = trim($_GET['q'] ?? '');
$filter_client = trim($_GET['client_name'] ?? '');
$filter_team = trim($_GET['team'] ?? '');
$filter_department = trim($_GET['department'] ?? '');
$filter_error_type = trim($_GET['error_type'] ?? '');
$filter_yn = trim($_GET['yn'] ?? '');

// Build query
$sql = "SELECT id, week, error_date, team, department, error_taken_by, internal_external, client_name, article, error_type, error_subtype, yn, error_made_by, reason, error_photo, status, created_date 
        FROM error_log";
$cond = [];
$params =[];
$types = '';

if ($q !== '') {
    $cond[] = "(client_name LIKE ? OR error_taken_by LIKE ? OR error_made_by LIKE ? OR error_type LIKE ? OR reason LIKE ? OR department LIKE ? OR team LIKE ? OR article LIKE ?)";
    $like = "%$q%";
    $params = array_merge($params,[$like, $like, $like, $like, $like, $like, $like, $like]);
    $types .= 'ssssssss';
}

if ($filter_client !== '') {
    $cond[] = "client_name = ?";
    $params[] = $filter_client;
    $types .= 's';
}

if ($filter_team !== '') {
    $cond[] = "team = ?";
    $params[] = $filter_team;
    $types .= 's';
}

if ($filter_department !== '') {
    $cond[] = "department = ?";
    $params[] = $filter_department;
    $types .= 's';
}

if ($filter_error_type !== '') {
    $cond[] = "error_type = ?";
    $params[] = $filter_error_type;
    $types .= 's';
}

if ($filter_yn === 'Yes' || $filter_yn === 'No') {
    $cond[] = "yn = ?";
    $params[] = $filter_yn;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " WHERE " . implode(" AND ", $cond);
}

$sql .= " ORDER BY id DESC";

if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $entries = $stmt->get_result();
} else {
    $entries = $mysqli->query($sql);
}

// Get unique values for filter dropdowns
$unique_clients = $mysqli->query("SELECT DISTINCT client_name FROM error_log WHERE client_name != '' ORDER BY client_name");

if (!$entries) $entries = false;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error Log Management</title>
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
            max-width: 100%;
            margin: 0 auto;
        }
        .table th {
            background-color: #6c757d !important;
            color: white !important;
            border: none;
            white-space: nowrap;
        }
        .table td {
            vertical-align: middle;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
        }
        .form-label {
            font-weight: 500;
        }
        .select2-container--default .select2-selection--single {
            height: 38px;
            padding: 5px;
            border: 1px solid #ced4da;
        }
        .badge {
            font-size: 0.85em;
        }
        .photo-thumbnail {
            width: 45px;
            height: 45px;
            object-fit: cover;
            border-radius: 5px;
            border: 1px solid #ddd;
            transition: transform 0.2s;
        }
        .photo-thumbnail:hover {
            transform: scale(1.1);
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
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-bug me-2"></i><?= $mode === 'edit' ? 'Edit Error Log Entry' : 'Add New Error Log Entry' ?></h4>
            <a href="error_entry.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" autocomplete="off" id="errorLogForm">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editEntry['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <!-- Row 1 -->
                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="week" class="form-label">Week <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="week" name="week" 
                                       value="<?= h($editEntry['week'] ?? '') ?>" 
                                       placeholder="e.g., Week 1, Jan-2024" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="error_date" class="form-label">Date</label>
                                <input type="date" class="form-control" id="error_date" name="error_date" 
                                       value="<?= h($editEntry['error_date'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="team" class="form-label">Team</label>
                                <select class="form-control select2" id="team" name="team" data-placeholder="Select team">
                                    <option value=""></option>
                                    <option value="WK" <?= ($editEntry['team'] ?? '') === 'WK' ? 'selected' : '' ?>>WK</option>
                                    <option value="UQ" <?= ($editEntry['team'] ?? '') === 'UQ' ? 'selected' : '' ?>>UQ</option>
                                    
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="department" class="form-label">Department</label>
                                <select class="form-control select2" id="department" name="department" data-placeholder="Select or type department">
                                    <option value=""></option>
                                    <?php if($departments_from_master && $departments_from_master->num_rows > 0): $departments_from_master->data_seek(0); while($dept_row = $departments_from_master->fetch_assoc()): ?>
                                        <option value="<?= h($dept_row['department_name']) ?>" <?= ($editEntry['department'] ?? '') === $dept_row['department_name'] ? 'selected' : '' ?>><?= h($dept_row['department_name']) ?></option>
                                    <?php endwhile; endif; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Row 2 -->
                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="error_taken_by" class="form-label">Error Taken By</label>
                                <select class="form-control select2" id="error_taken_by" name="error_taken_by" data-placeholder="Select employee">
                                    <option value=""></option>
                                    <?php if($employees && $employees->num_rows > 0): $employees->data_seek(0); while($emp_row = $employees->fetch_assoc()): ?>
                                        <option value="<?= h($emp_row['employee_name']) ?>" <?= ($editEntry['error_taken_by'] ?? '') === $emp_row['employee_name'] ? 'selected' : '' ?>><?= h($emp_row['employee_name']) ?></option>
                                    <?php endwhile; endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="internal_external" class="form-label">Internal / External</label>
                                <select class="form-control" id="internal_external" name="internal_external">
                                    <option value="">Select</option>
                                    <option value="Internal" <?= ($editEntry['internal_external'] ?? '') === 'Internal' ? 'selected' : '' ?>>Internal</option>
                                    <option value="External" <?= ($editEntry['internal_external'] ?? '') === 'External' ? 'selected' : '' ?>>External</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="client_name" class="form-label">Client Name <span class="text-danger">*</span></label>
                                <select class="form-control select2" id="client_name" name="client_name" required data-placeholder="Select or type client name">
                                    <option value=""></option>
                                    <?php if($clients && $clients->num_rows > 0): $clients->data_seek(0); while($client_row = $clients->fetch_assoc()): ?>
                                        <option value="<?= h($client_row['client_name']) ?>" <?= ($editEntry['client_name'] ?? '') === $client_row['client_name'] ? 'selected' : '' ?>><?= h($client_row['client_name']) ?></option>
                                    <?php endwhile; endif; ?>
                                    <?php if($unique_clients && $unique_clients->num_rows > 0): $unique_clients->data_seek(0); while($client = $unique_clients->fetch_assoc()): ?>
                                        <option value="<?= h($client['client_name']) ?>" <?= ($editEntry['client_name'] ?? '') === $client['client_name'] ? 'selected' : '' ?>><?= h($client['client_name']) ?></option>
                                    <?php endwhile; endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="article" class="form-label">Article</label>
                                <input type="text" class="form-control" id="article" name="article" value="<?= h($editEntry['article'] ?? '') ?>" placeholder="Article Details">
                            </div>
                        </div>
                    </div>

                    <!-- Row 3 -->
                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="error_type" class="form-label">Error Type <span class="text-danger">*</span></label>
                                <select class="form-control select2" id="error_type" name="error_type" required>
                                    <option value="">Select Error Type</option>
                                    <?php 
                                        $types_list =['NA', 'Acknowledgement Issue', 'Technical Issue', 'Missed TAT'];
                                        foreach($types_list as $type): 
                                            $selected = (($editEntry['error_type'] ?? '') === $type) ? 'selected' : '';
                                    ?>
                                        <option value="<?= h($type) ?>" <?= $selected ?>><?= h($type) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="error_subtype" class="form-label">Error Subtype</label>
                                <select class="form-control select2" id="error_subtype" name="error_subtype">
                                    <option value="">Select Error Subtype</option>
                                    <?php 
                                        $subtypes_list =['Ack_Client', 'Ack_Sales', 'Communication Issue', 'Wrong Upload', 'Commitment not met', 'Feedback not send', 'Improper response', 'Reply late', 'Priority to same client', 'Priority to other client', 'Missed out Crx', 'Misunderstand Crx'];
                                        foreach($subtypes_list as $subtype): 
                                            $selected = (($editEntry['error_subtype'] ?? '') === $subtype) ? 'selected' : '';
                                    ?>
                                        <option value="<?= h($subtype) ?>" <?= $selected ?>><?= h($subtype) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="yn" class="form-label">Y/N</label>
                                <select class="form-control" id="yn" name="yn">
                                    <option value="">Select</option>
                                    <option value="Yes" <?= ($editEntry['yn'] ?? '') === 'Yes' ? 'selected' : '' ?>>Yes</option>
                                    <option value="No" <?= ($editEntry['yn'] ?? '') === 'No' ? 'selected' : '' ?>>No</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="error_made_by" class="form-label">Error Made by</label>
                                <select class="form-control select2" id="error_made_by" name="error_made_by" data-placeholder="Select employee">
                                    <option value=""></option>
                                    <?php if($employees && $employees->num_rows > 0): $employees->data_seek(0); while($emp_row = $employees->fetch_assoc()): ?>
                                        <option value="<?= h($emp_row['employee_name']) ?>" <?= ($editEntry['error_made_by'] ?? '') === $emp_row['employee_name'] ? 'selected' : '' ?>><?= h($emp_row['employee_name']) ?></option>
                                    <?php endwhile; endif; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Row 4 -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="reason" class="form-label">Reason</label>
                                <textarea class="form-control" id="reason" name="reason" rows="2" placeholder="Provide reason or description"><?= h($editEntry['reason'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="error_photo" class="form-label">Attach Error Photo</label>
                                <input class="form-control" type="file" id="error_photo" name="error_photo" accept="image/*">
                                <div class="form-text">Optional. Upload a screenshot/photo.</div>
                                <?php if(!empty($editEntry['error_photo'])): ?>
                                    <div class="mt-2">
                                        <a href="uploads/error_photos/<?= h($editEntry['error_photo']) ?>" target="_blank">
                                            <img src="uploads/error_photos/<?= h($editEntry['error_photo']) ?>" class="img-thumbnail" width="100">
                                        </a>
                                        <span class="d-block small text-muted">Current photo attached</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> <?= $mode === 'edit' ? 'Update Entry' : 'Save Entry' ?>
                        </button>
                        <a href="error_entry.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-bug me-2"></i>Error Logs</h4>
            <div>
                 <button type="button" class="btn btn-warning btn-sm text-black" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-import me-1"></i> Import Excel
                </button>
                <a href="error_entry.php?mode=add" class="btn btn-success btn-sm me-2">
                    <i class="fas fa-plus me-1"></i> Add New Entry
                </a>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center" id="searchForm">
                    <div class="col-md-2">
                        <select name="client_name" class="form-select form-select-sm select2-sm">
                            <option value="">Sort by Client</option>
                            <?php if($unique_clients && $unique_clients->num_rows > 0): $unique_clients->data_seek(0); while($client = $unique_clients->fetch_assoc()): ?>
                                <option value="<?= h($client['client_name']) ?>" <?= $filter_client === $client['client_name'] ? 'selected' : '' ?>><?= h($client['client_name']) ?></option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>
                    
                    <!-- SORT BY TEAM -->
                    <div class="col-md-2">
                        <select name="team" class="form-select form-control-sm">
                            <option value="">Sort by Team</option>
                            <option value="WK" <?= $filter_team === 'WK' ? 'selected' : '' ?>>WK</option>
                            <option value="UQ" <?= $filter_team === 'UQ' ? 'selected' : '' ?>>UQ</option>
                           </select>
                    </div>

                    <!-- SORT BY DEPT -->
                    <div class="col-md-2">
                        <select name="department" class="form-select form-control-sm select2-sm">
                            <option value="">Sort by Dept</option>
                            <?php if($departments_from_master && $departments_from_master->num_rows > 0): $departments_from_master->data_seek(0); while($dept_row = $departments_from_master->fetch_assoc()): ?>
                                <option value="<?= h($dept_row['department_name']) ?>" <?= $filter_department === $dept_row['department_name'] ? 'selected' : '' ?>><?= h($dept_row['department_name']) ?></option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <select name="error_type" class="form-select form-control-sm">
                            <option value="">Sort by Error Types</option>
                            <?php 
                                $types_list =['NA', 'Acknowledgement Issue', 'Technical Issue', 'Missed TAT'];
                                foreach($types_list as $type): 
                            ?>
                                <option value="<?= h($type) ?>" <?= $filter_error_type === $type ? 'selected' : '' ?>><?= h($type) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <select name="yn" class="form-select form-control-sm">
                            <option value="">Sort by Y/N</option>
                            <option value="Yes" <?= $filter_yn === 'Yes' ? 'selected' : '' ?>>Yes</option>
                            <option value="No" <?= $filter_yn === 'No' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="fas fa-search me-1"></i> Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="errorLogTable">
                        <thead>
                            <tr>
                                <th>S. No.</th>
                                <th>Week</th>
                                <th>Date</th>
                                <th>Team</th>
                                <th>Department</th>
                                <th>Error Taken By</th>
                                <th>Internal /External</th>
                                <th>Client Name</th>
                                <th>Article</th>
                                <th>Error Type</th>
                                <th>Error Subtype</th>
                                <th>Y/N</th>
                                <th>Error Made by</th>
                                <th>Reason</th>
                                <th class="text-center export-remove">Photo</th>
                                <th class="text-center export-remove">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($entries && $entries->num_rows > 0): ?>
                                <?php $counter = 1; ?>
                                <?php while($row = $entries->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?= $counter++ ?></td>
                                    <td><span class="badge bg-primary"><?= h($row['week']) ?></span></td>
                                    <td><?= h(formatDate($row['error_date'])) ?></td>
                                    <td><?= h($row['team'] ?: '—') ?></td>
                                    <td>
                                        <?php if(!empty($row['department'])): ?>
                                            <span class="badge bg-secondary"><?= h($row['department']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($row['error_taken_by'] ?: '—') ?></td>
                                    <td><?= h($row['internal_external'] ?: '—') ?></td>
                                    <td><span class="fw-semibold"><?= h($row['client_name']) ?></span></td>
                                    <td><?= h($row['article'] ?: '—') ?></td>
                                    <td><span class="fw-semibold"><?= h($row['error_type']) ?></span></td>
                                    <td>
                                        <?php if(!empty($row['error_subtype'])): ?>
                                            <span class="badge bg-info text-dark"><?= h($row['error_subtype']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($row['yn'] === 'Yes'): ?>
                                            <span class="badge bg-success">Yes</span>
                                        <?php elseif($row['yn'] === 'No'): ?>
                                            <span class="badge bg-danger">No</span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($row['error_made_by'] ?: '—') ?></td>
                                    <td><?= h($row['reason'] ?: '—') ?></td>
                                    
                                    <!-- Photo Column -->
                                    <td class="text-center export-remove">
                                        <?php if(!empty($row['error_photo'])): ?>
                                            <a href="uploads/error_photos/<?= h($row['error_photo']) ?>" target="_blank" title="View Image">
                                                <img src="uploads/error_photos/<?= h($row['error_photo']) ?>" class="photo-thumbnail" alt="Err Photo">
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted" title="No photo"><i class="fas fa-image" style="opacity: 0.2; font-size:1.5rem;"></i></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center export-remove">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="error_entry.php?mode=edit&id=<?= (int)$row['id'] ?>" class="btn btn-warning" title="Edit Entry">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete entry for <?= h(addslashes($row['client_name'])) ?>?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Entry">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="16" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-clipboard-list fa-3x mb-3"></i>
                                            <h5>No error log entries found</h5>
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

    <!-- Import Excel/CSV Modal -->
    <div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="importModalLabel">
                        <i class="fas fa-file-import me-2"></i>Import Error Log Data
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    <div class="modal-body">
                        <div class="alert alert-info small">
                            <h6><i class="fas fa-info-circle me-1"></i>Instructions:</h6>
                            <ul class="mb-2">
                                <li>Columns (A to N): <strong>S.No, Week, Date, Team, Department, Error Taken By, Internal/External, Client Name, Article, Error Type, Error Subtype, Y/N, Error Made by, Reason</strong>.</li>
                                <li>The first row must contain these column headers.</li>
                                <li>Date format should ideally be YYYY-MM-DD.</li>
                            </ul>
                            <button type="button" class="btn btn-sm btn-outline-primary bg-white text-primary mt-1" onclick="downloadSampleCSV()">
                                <i class="fas fa-file-csv me-1"></i> Download Sample CSV
                            </button>
                        </div>
                        
                        <div class="mb-3">
                            <label for="excel_file" class="form-label">Select File (.csv, .xlsx) <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" id="excel_file" name="excel_file" accept=".csv,.xlsx,.xls" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info text-white">
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
        document.addEventListener('DOMContentLoaded', function() {
            $('.select2').select2({ width: '100%', allowClear: true, tags: true });
            $('.select2-sm').select2({ width: '100%', minimumResultsForSearch: 10 });
        });

        // Generate Sample CSV Template for Download
        function downloadSampleCSV() {
            const sampleContent = "S.No,Week,Date,Team,Department,Error Taken By,Internal/External,Client Name,Article,Error Type,Error Subtype,Y/N,Error Made by,Reason\n" +
                                 "1,Week 1 Jan-2024,2024-01-15,WK,Development,John Doe,Internal,Sample Client Ltd,Article 123,Technical Issue,Wrong Upload,Yes,Jane Smith,Optional descriptive comment here";
            
            const blob = new Blob([sampleContent], { type: 'text/csv;charset=utf-8;' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'Error_Log_Import_Template.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>