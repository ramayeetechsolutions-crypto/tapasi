<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

function redirect_with($params = []) {
    $base = 'error_entry.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Fetch dropdown data from master tables
$teams = $mysqli->query("SELECT team_name FROM team_master WHERE status = 'Active' ORDER BY team_name");
$employees = $mysqli->query("SELECT employee_name, employee_username FROM employee_master WHERE status = 'Active' ORDER BY employee_name");
$clients = $mysqli->query("SELECT client_name FROM client_master WHERE status = 'Active' ORDER BY client_name");

// DEBUG: Check if employee data is being fetched
error_log("Employee query executed: " . ($employees ? "Yes" : "No"));
if ($employees) {
    error_log("Number of employees: " . $employees->num_rows);
    $employees->data_seek(0);
    while($emp = $employees->fetch_assoc()) {
        error_log("Employee: " . $emp['employee_name'] . ", Username: " . ($emp['employee_username'] ?? 'NULL'));
    }
    $employees->data_seek(0); // Reset pointer
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $week = trim($_POST['week'] ?? '');
        $error_type = trim($_POST['error_type'] ?? '');
        $error_subtype = trim($_POST['error_subtype'] ?? '');
        $client = trim($_POST['client'] ?? '');
        $yn = trim($_POST['yn'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $employee = trim($_POST['employee'] ?? '');
        $employee_username = trim($_POST['employee_username'] ?? '');
        $comment = trim($_POST['comment'] ?? '');
        
        // Debug POST data
        error_log("POST Data - Employee: '$employee', Username: '$employee_username'");
        
        // Basic validation
        if ($week === '' || $error_type === '' || $client === '') {
            redirect_with(['msg' => 'Week, Error Type and Client are required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Insert new error log
            $stmt = $mysqli->prepare("INSERT INTO error_log (week, error_type, error_subtype, client, yn, department, employee, employee_username, comment, status, created_date) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW())");
            $stmt->bind_param("sssssssss", $week, $error_type, $error_subtype, $client, $yn, $department, $employee, $employee_username, $comment);
            
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
                // Update error log
                $stmt = $mysqli->prepare("UPDATE error_log SET week = ?, error_type = ?, error_subtype = ?, 
                                         client = ?, yn = ?, department = ?, employee = ?, employee_username = ?, comment = ? 
                                         WHERE id = ?");
                $stmt->bind_param("sssssssssi", $week, $error_type, $error_subtype, $client, $yn, $department, $employee, $employee_username, $comment, $id);
                
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
            // Get client name for message
            $res = $mysqli->prepare("SELECT client FROM error_log WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($client_name);
            $res->fetch();
            $res->close();
            
            // Delete entry
            $del = $mysqli->prepare("DELETE FROM error_log WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Entry for client '$client_name' deleted", 'type' => 'warning']);
        }
    }
    
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT status, client FROM error_log WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($current_status, $client_name);
            $res->fetch();
            $res->close();
            
            if ($client_name) {
                $newStatus = ($current_status === 'Active') ? 'Inactive' : 'Active';
                
                $upd = $mysqli->prepare("UPDATE error_log SET status = ? WHERE id = ?");
                $upd->bind_param("si", $newStatus, $id);
                $upd->execute();
                $upd->close();
                
                redirect_with(['msg' => "Entry for '$client_name' status changed to $newStatus", 'type' => 'info']);
            }
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editEntry = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT id, week, error_type, error_subtype, client, yn, department, employee, employee_username, comment, status, created_date 
                                 FROM error_log WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editEntry = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editEntry) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$filter_client = trim($_GET['client'] ?? '');
$filter_department = trim($_GET['department'] ?? '');
$filter_error_type = trim($_GET['error_type'] ?? '');
$sort = $_GET['sort'] ?? 'created_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query
$sql = "SELECT id, week, error_type, error_subtype, client, yn, department, employee, employee_username, comment, status, created_date 
        FROM error_log";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(client LIKE ? OR employee LIKE ? OR employee_username LIKE ? OR error_type LIKE ? OR comment LIKE ? OR department LIKE ?)";
    $like = "%$q%";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    $types .= 'ssssss';
}

if ($filter_client !== '') {
    $cond[] = "client = ?";
    $params[] = $filter_client;
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

if ($fstatus === 'Active' || $fstatus === 'Inactive') {
    $cond[] = "status = ?";
    $params[] = $fstatus;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " WHERE " . implode(" AND ", $cond);
}

$sql .= " ORDER BY $sort $dir";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $entries = $stmt->get_result();
} else {
    $entries = $mysqli->query($sql);
}

// Get unique values for filter dropdowns
$unique_clients = $mysqli->query("SELECT DISTINCT client FROM error_log WHERE client != '' ORDER BY client");
$unique_departments = $mysqli->query("SELECT DISTINCT department FROM error_log WHERE department != '' ORDER BY department");
$unique_error_types = $mysqli->query("SELECT DISTINCT error_type FROM error_log WHERE error_type != '' ORDER BY error_type");

// Get previous employee entries from error_log for dropdown
$unique_employees = $mysqli->query("SELECT DISTINCT employee, employee_username FROM error_log WHERE employee != '' ORDER BY employee");

// If query fails, show empty result
if (!$entries) {
    $entries = false;
}
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
            max-width: 1800px;
            margin: 0 auto;
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
        .select2-container--default .select2-selection--multiple {
            border: 1px solid #ced4da;
        }
        .badge {
            font-size: 0.85em;
        }
        .username-field {
            background-color: #f8f9fa;
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
            <h4><i class="fas fa-bug me-2"></i><?= $mode === 'edit' ? 'Edit Error Log Entry' : 'Add New Error Log Entry' ?></h4>
            <a href="error_entry.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off" id="errorLogForm">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editEntry['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="week" class="form-label">Week <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="week" name="week" 
                                       value="<?= h($editEntry['week'] ?? '') ?>" 
                                       placeholder="e.g., Week 1, Jan-2024" required>
                                <div class="form-text">Format: Week 1, Jan-2024</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="error_type" class="form-label">Error Type <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="error_type" name="error_type" 
                                       value="<?= h($editEntry['error_type'] ?? '') ?>" 
                                       placeholder="Type of error" required>
                                <div class="form-text">Enter error type (e.g., Technical, Content, Formatting)</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="error_subtype" class="form-label">Error Subtype</label>
                                <input type="text" class="form-control" id="error_subtype" name="error_subtype" 
                                       value="<?= h($editEntry['error_subtype'] ?? '') ?>" 
                                       placeholder="Subtype if applicable">
                                <div class="form-text">Optional: More specific error category</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="client" class="form-label">Client <span class="text-danger">*</span></label>
                                <select class="form-control select2" id="client" name="client" required
                                        data-placeholder="Select or type client name">
                                    <option value=""></option>
                                    <!-- Existing entries from master table -->
                                    <?php if($clients && $clients->num_rows > 0): ?>
                                        <?php $clients->data_seek(0); ?>
                                        <?php while($client_row = $clients->fetch_assoc()): ?>
                                        <option value="<?= h($client_row['client_name']) ?>" 
                                            <?= ($editEntry['client'] ?? '') === $client_row['client_name'] ? 'selected' : '' ?>>
                                            <?= h($client_row['client_name']) ?>
                                        </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                    <!-- Existing entries from error_log table -->
                                    <?php if($unique_clients && $unique_clients->num_rows > 0): ?>
                                        <?php $unique_clients->data_seek(0); ?>
                                        <?php while($client = $unique_clients->fetch_assoc()): ?>
                                        <option value="<?= h($client['client']) ?>" 
                                            <?= ($editEntry['client'] ?? '') === $client['client'] ? 'selected' : '' ?>>
                                            <?= h($client['client']) ?>
                                        </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                                <div class="form-text">Select from list or type new client name</div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="yn" class="form-label">Y/N</label>
                                <select class="form-control" id="yn" name="yn">
                                    <option value="">Select</option>
                                    <option value="Yes" <?= ($editEntry['yn'] ?? '') === 'Yes' ? 'selected' : '' ?>>Yes</option>
                                    <option value="No" <?= ($editEntry['yn'] ?? '') === 'No' ? 'selected' : '' ?>>No</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="department" class="form-label">Department</label>
                                <select class="form-control select2" id="department" name="department"
                                        data-placeholder="Select or type department">
                                    <option value=""></option>
                                    <!-- Existing entries from master table -->
                                    <?php if($teams && $teams->num_rows > 0): ?>
                                        <?php $teams->data_seek(0); ?>
                                        <?php while($team_row = $teams->fetch_assoc()): ?>
                                        <option value="<?= h($team_row['team_name']) ?>" 
                                            <?= ($editEntry['department'] ?? '') === $team_row['team_name'] ? 'selected' : '' ?>>
                                            <?= h($team_row['team_name']) ?>
                                        </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                    <!-- Existing entries from error_log table -->
                                    <?php if($unique_departments && $unique_departments->num_rows > 0): ?>
                                        <?php $unique_departments->data_seek(0); ?>
                                        <?php while($dept = $unique_departments->fetch_assoc()): ?>
                                        <option value="<?= h($dept['department']) ?>" 
                                            <?= ($editEntry['department'] ?? '') === $dept['department'] ? 'selected' : '' ?>>
                                            <?= h($dept['department']) ?>
                                        </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="employee" class="form-label">Employee Name</label>
                                <select class="form-control select2" id="employee" name="employee"
                                        data-placeholder="Select employee name">
                                    <option value=""></option>
                                    <!-- Existing entries from master table -->
                                    <?php if($employees && $employees->num_rows > 0): ?>
                                        <?php $employees->data_seek(0); ?>
                                        <?php while($emp_row = $employees->fetch_assoc()): ?>
                                        <option value="<?= h($emp_row['employee_name']) ?>" 
                                            data-username="<?= h($emp_row['employee_username'] ?? '') ?>"
                                            <?= ($editEntry['employee'] ?? '') === $emp_row['employee_name'] ? 'selected' : '' ?>>
                                            <?= h($emp_row['employee_name']) ?> (<?= h($emp_row['employee_username'] ?? 'N/A') ?>)
                                        </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                    <!-- Existing entries from error_log table -->
                                    <?php if($unique_employees && $unique_employees->num_rows > 0): ?>
                                        <?php $unique_employees->data_seek(0); ?>
                                        <?php while($emp = $unique_employees->fetch_assoc()): ?>
                                        <option value="<?= h($emp['employee']) ?>" 
                                            data-username="<?= h($emp['employee_username'] ?? '') ?>"
                                            <?= ($editEntry['employee'] ?? '') === $emp['employee'] ? 'selected' : '' ?>>
                                            <?= h($emp['employee']) ?> (<?= h($emp['employee_username'] ?? 'N/A') ?>)
                                        </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                                <div class="form-text">Username will auto-fill</div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="employee_username" class="form-label">Employee Username</label>
                                <input type="text" class="form-control username-field" id="employee_username" name="employee_username" 
                                       value="<?= h($editEntry['employee_username'] ?? '') ?>" 
                                       placeholder="Auto-filled from selection">
                                <div class="form-text">Editable if needed</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="comment" class="form-label">Comment</label>
                                <textarea class="form-control" id="comment" name="comment" 
                                          rows="3" placeholder="Additional comments, observations, or corrective actions taken"><?= h($editEntry['comment'] ?? '') ?></textarea>
                                <div class="form-text">Enter any additional information about this error</div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Entry' : 'Save Entry' ?>
                        </button>
                        <a href="error_entry.php" class="btn btn-outline-secondary">Cancel</a>
                        <?php if($mode === 'edit'): ?>
                        <a href="error_entry.php?mode=add" class="btn btn-outline-success">
                            <i class="fas fa-plus me-1"></i> Add New
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-bug me-2"></i>Error Log Management</h4>
            <div>
                <a href="error_entry.php?mode=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Add New Entry
                </a>
                <button class="btn btn-outline-primary btn-sm" onclick="exportToExcel()">
                    <i class="fas fa-file-excel me-1"></i> Export Excel
                </button>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center" id="searchForm">
                    <div class="col-md-2">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Global search..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="client" class="form-select form-select-sm select2-sm">
                            <option value="">All Clients</option>
                            <?php if($unique_clients && $unique_clients->num_rows > 0): ?>
                                <?php $unique_clients->data_seek(0); ?>
                                <?php while($client = $unique_clients->fetch_assoc()): ?>
                                <option value="<?= h($client['client']) ?>" 
                                    <?= $filter_client === $client['client'] ? 'selected' : '' ?>>
                                    <?= h($client['client']) ?>
                                </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="department" class="form-select form-control-sm select2-sm">
                            <option value="">All Departments</option>
                            <?php if($unique_departments && $unique_departments->num_rows > 0): ?>
                                <?php $unique_departments->data_seek(0); ?>
                                <?php while($dept = $unique_departments->fetch_assoc()): ?>
                                <option value="<?= h($dept['department']) ?>" 
                                    <?= $filter_department === $dept['department'] ? 'selected' : '' ?>>
                                    <?= h($dept['department']) ?>
                                </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="error_type" class="form-select form-control-sm">
                            <option value="">All Error Types</option>
                            <?php if($unique_error_types && $unique_error_types->num_rows > 0): ?>
                                <?php $unique_error_types->data_seek(0); ?>
                                <?php while($err = $unique_error_types->fetch_assoc()): ?>
                                <option value="<?= h($err['error_type']) ?>" 
                                    <?= $filter_error_type === $err['error_type'] ? 'selected' : '' ?>>
                                    <?= h($err['error_type']) ?>
                                </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <select name="status" class="form-select form-control-sm">
                            <option value="">All Status</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="sort" class="form-select form-control-sm">
                            <option value="created_date" <?= $sort === 'created_date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="week" <?= $sort === 'week' ? 'selected' : '' ?>>Sort by Week</option>
                            <option value="client" <?= $sort === 'client' ? 'selected' : '' ?>>Sort by Client</option>
                            <option value="error_type" <?= $sort === 'error_type' ? 'selected' : '' ?>>Sort by Error Type</option>
                        </select>
                        <select name="dir" class="form-select form-control-sm mt-1">
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option>
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                        </select>
                    </div>
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="error_entry.php" class="btn btn-secondary btn-sm me-2">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                        <span class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            <?php if($entries && $entries->num_rows > 0): ?>
                                <?= $entries->num_rows ?> entries found
                            <?php else: ?>
                                No entries found
                            <?php endif; ?>
                        </span>
                    </div>
                </form>
            </div>
        </div>

        <!-- Error Log Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="errorLogTable">
                        <thead class="table-light">
                            <tr>
                                <th width="50">S.No</th>
                                <th width="100">Week</th>
                                <th width="120">Error Type</th>
                                <th width="120">Error Subtype</th>
                                <th>Client Name</th>
                                <th width="80">Y/N</th>
                                <th width="120">Department</th>
                                <th>Employee Name</th>
                                <th width="120">Employee Id</th>
                                <th width="150">Comment</th>
                                <th width="120">Date</th>
                                <th width="150" class="text-center export-remove">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($entries && $entries->num_rows > 0): ?>
                                <?php $counter = 1; ?>
                                <?php while($row = $entries->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?= $counter++ ?></td>
                                    <td>
                                        <span class="badge bg-primary"><?= h($row['week']) ?></span>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= h($row['error_type']) ?></span>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['error_subtype'])): ?>
                                            <span class="badge bg-info text-dark"><?= h($row['error_subtype']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= h($row['client']) ?></span>
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
                                    <td>
                                        <?php if(!empty($row['department'])): ?>
                                            <span class="badge bg-secondary"><?= h($row['department']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($row['employee'] ?: '—') ?></td>
                                    <td><?= h($row['employee_username'] ?: '—') ?></td>
                                    <td><?= h($row['comment'] ?: '—') ?></td>
                                    <td>
                                        <small class="text-muted"><?= h(formatDate($row['created_date'])) ?></small>
                                    </td>
                                    <td class="text-center export-remove">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="error_entry.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit Entry">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete entry for <?= h(addslashes($row['client'])) ?>?')">
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
                                    <td colspan="12" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-clipboard-list fa-3x mb-3"></i>
                                            <h5>No error log entries found</h5>
                                            <p class="mb-0">Get started by adding your first error log entry.</p>
                                            <a href="error_entry.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Entry
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
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script>
        // Auto-dismiss alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert.isConnected) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                }, 5000);
            });

            // Initialize Select2 for form dropdowns
            $('.select2').select2({
                width: '100%',
                placeholder: function() {
                    return $(this).data('placeholder');
                },
                allowClear: true,
                tags: true, // Allow typing new values
                createTag: function (params) {
                    var term = $.trim(params.term);
                    if (term === '') {
                        return null;
                    }
                    return {
                        id: term,
                        text: term,
                        newTag: true
                    };
                }
            });
            
            $('.select2-sm').select2({
                width: '100%',
                placeholder: 'Select',
                minimumResultsForSearch: 10
            });

            // SIMPLIFIED VERSION - Direct JavaScript without Select2 interference
            const employeeSelect = document.getElementById('employee');
            const usernameInput = document.getElementById('employee_username');
            
            if (employeeSelect && usernameInput) {
                // Function to update username
                function updateUsername() {
                    const selectedOption = employeeSelect.options[employeeSelect.selectedIndex];
                    if (selectedOption) {
                        const username = selectedOption.getAttribute('data-username') || '';
                        console.log('Selected employee:', selectedOption.value, 'Username attribute:', username);
                        usernameInput.value = username;
                    } else {
                        usernameInput.value = '';
                    }
                }
                
                // Listen for change event
                employeeSelect.addEventListener('change', updateUsername);
                
                // Also listen for Select2 change (if using Select2)
                $(employeeSelect).on('change.select2', updateUsername);
                
                // Set initial value
                updateUsername();
                
                // Make sure username is included in form submission
                const form = document.getElementById('errorLogForm');
                if (form) {
                    // Add a hidden field to ensure username is submitted
                    const hiddenUsernameField = document.createElement('input');
                    hiddenUsernameField.type = 'hidden';
                    hiddenUsernameField.name = 'employee_username_hidden';
                    hiddenUsernameField.id = 'employee_username_hidden';
                    form.appendChild(hiddenUsernameField);
                    
                    // Before form submit, sync the value
                    form.addEventListener('submit', function(e) {
                        // Ensure username field has value
                        if (!usernameInput.value && employeeSelect.value) {
                            updateUsername(); // Try to get it again
                        }
                        
                        // Update hidden field with current value
                        document.getElementById('employee_username_hidden').value = usernameInput.value;
                        
                        console.log('Form submitting with username:', usernameInput.value);
                    });
                }
            }

            // Auto-submit form on filter changes
            const filterSelects = document.querySelectorAll('select[name="status"], select[name="sort"]');
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });
            
            // Initialize all modals
            var commentModals = document.querySelectorAll('.modal');
            commentModals.forEach(function(modal) {
                new bootstrap.Modal(modal);
            });
            
            // Form validation
            const form = document.getElementById('errorLogForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const clientSelect = document.getElementById('client');
                    const weekInput = document.getElementById('week');
                    const errorTypeInput = document.getElementById('error_type');
                    
                    let isValid = true;
                    
                    // Reset previous errors
                    [clientSelect, weekInput, errorTypeInput].forEach(el => {
                        el.classList.remove('is-invalid');
                        const feedback = el.nextElementSibling;
                        if (feedback && feedback.classList.contains('invalid-feedback')) {
                            feedback.remove();
                        }
                    });
                    
                    // Validate client
                    if (!clientSelect.value) {
                        clientSelect.classList.add('is-invalid');
                        const feedback = document.createElement('div');
                        feedback.className = 'invalid-feedback';
                        feedback.textContent = 'Please select or enter a client name';
                        clientSelect.parentNode.appendChild(feedback);
                        isValid = false;
                    }
                    
                    // Validate week
                    if (!weekInput.value.trim()) {
                        weekInput.classList.add('is-invalid');
                        const feedback = document.createElement('div');
                        feedback.className = 'invalid-feedback';
                        feedback.textContent = 'Week is required';
                        weekInput.parentNode.appendChild(feedback);
                        isValid = false;
                    }
                    
                    // Validate error type
                    if (!errorTypeInput.value.trim()) {
                        errorTypeInput.classList.add('is-invalid');
                        const feedback = document.createElement('div');
                        feedback.className = 'invalid-feedback';
                        feedback.textContent = 'Error type is required';
                        errorTypeInput.parentNode.appendChild(feedback);
                        isValid = false;
                    }
                    
                    if (!isValid) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                });
            }
        });
        
        // Export to Excel function
        function exportToExcel() {
            const table = document.getElementById('errorLogTable');
            if (!table) return;
            
            // Create a new table for export (excluding Actions column)
            const exportTable = document.createElement('table');
            
            // Clone the header row without the last column (Actions)
            const originalHeader = table.rows[0];
            const exportHeader = document.createElement('tr');
            
            for (let i = 0; i < originalHeader.cells.length - 1; i++) {
                const th = document.createElement('th');
                th.textContent = originalHeader.cells[i].textContent;
                exportHeader.appendChild(th);
            }
            exportTable.appendChild(exportHeader);
            
            // Process data rows
            const dataRows = table.querySelectorAll('tbody tr');
            dataRows.forEach(originalRow => {
                // Skip empty rows (no entries message)
                if (originalRow.cells.length <= 1) return;
                
                const exportRow = document.createElement('tr');
                
                for (let i = 0; i < originalRow.cells.length - 1; i++) {
                    const td = document.createElement('td');
                    const originalCell = originalRow.cells[i];
                    
                    // Extract text content, handling badges and other HTML elements
                    let cellText = '';
                    
                    if (i === 0) { // S.No column
                        cellText = originalCell.textContent.trim();
                    } else if (i === 1) { // Week column (has badge)
                        const badge = originalCell.querySelector('.badge');
                        cellText = badge ? badge.textContent.trim() : originalCell.textContent.trim();
                    } else if (i === 3) { // Error Subtype column (has badge)
                        const badge = originalCell.querySelector('.badge');
                        cellText = badge ? badge.textContent.trim() : originalCell.textContent.trim();
                    } else if (i === 5) { // Y/N column (has badges)
                        const badge = originalCell.querySelector('.badge');
                        cellText = badge ? badge.textContent.trim() : originalCell.textContent.trim();
                    } else if (i === 6) { // Department column (has badge)
                        const badge = originalCell.querySelector('.badge');
                        cellText = badge ? badge.textContent.trim() : originalCell.textContent.trim();
                    } else if (i === 10) { // Date column (has small tag)
                        const small = originalCell.querySelector('small');
                        cellText = small ? small.textContent.trim() : originalCell.textContent.trim();
                    } else if (i === 2 || i === 4 || i === 7 || i === 8 || i === 9) {
                        // For text columns (Error Type, Client, Employee Name, Username, Comment)
                        if (originalCell.querySelector('.fw-semibold')) {
                            cellText = originalCell.querySelector('.fw-semibold').textContent.trim();
                        } else {
                            cellText = originalCell.textContent.trim();
                        }
                    } else {
                        cellText = originalCell.textContent.trim();
                    }
                    
                    td.textContent = cellText;
                    exportRow.appendChild(td);
                }
                
                exportTable.appendChild(exportRow);
            });
            
            // Create worksheet
            const ws = XLSX.utils.table_to_sheet(exportTable);
            
            // Set column widths (optional)
            const wscols = [
                {wch: 8},   // S.No
                {wch: 15},  // Week
                {wch: 20},  // Error Type
                {wch: 20},  // Error Subtype
                {wch: 30},  // Client Name
                {wch: 10},  // Y/N
                {wch: 20},  // Department
                {wch: 25},  // Employee Name
                {wch: 20},  // Username
                {wch: 40},  // Comment
                {wch: 15}   // Date
            ];
            ws['!cols'] = wscols;
            
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Error Log");
            
            // Generate filename
            const date = new Date().toISOString().slice(0, 10);
            const filename = `Error_Log_${date}.xlsx`;
            
            XLSX.writeFile(wb, filename);
        }
        
        // Quick search in table (client-side)
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="q"]');
            if (searchInput) {
                // Debounce function for performance
                let debounceTimer;
                searchInput.addEventListener('input', function() {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => {
                        const searchTerm = this.value.toLowerCase();
                        const rows = document.querySelectorAll('#errorLogTable tbody tr');
                        
                        rows.forEach(row => {
                            const text = row.textContent.toLowerCase();
                            row.style.display = text.includes(searchTerm) ? '' : 'none';
                        });
                    }, 300);
                });
            }
        });
    </script>
</body>
</html>