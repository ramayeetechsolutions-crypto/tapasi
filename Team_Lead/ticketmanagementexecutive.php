<?php
require 'db.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_user = $_SESSION['username'] ?? '';

// Get employee name from employee_master table
$employee_username = $current_user;
$current_user_name = '';

// Prepare SQL query to fetch employee_name
$sql = "SELECT `employee_name` FROM `employee_master` WHERE `employee_username` = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("s", $employee_username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $current_user_name = $row['employee_name'];
} 

// Get current user's reporting manager name
$reporting_manager_name = '';
$manager_sql = "SELECT reporting_manager FROM employee_master WHERE employee_username = ?";
$manager_stmt = $mysqli->prepare($manager_sql);
$manager_stmt->bind_param("s", $employee_username);
$manager_stmt->execute();
$manager_result = $manager_stmt->get_result();

if ($manager_result->num_rows > 0) {
    $manager_row = $manager_result->fetch_assoc();
    $reporting_manager_name = $manager_row['reporting_manager'] ?? '';
}
$manager_stmt->close();

// Helper functions
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00') return '—';
    return date('d-m-Y', strtotime($d));
}

function calculateTAT($complaint_date, $resolved_date) {
    if (empty($resolved_date) || $resolved_date === '0000-00-00') return '—';
    $start = new DateTime($complaint_date);
    $end = new DateTime($resolved_date);
    $diff = $start->diff($end);
    return $diff->days;
}

function redirect_with($params = []) {
    $base = 'ticketmanagementexecutive.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $complaint_date = trim($_POST['complaint_date'] ?? date('Y-m-d'));
        $created_by = trim($_POST['created_by'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $system_no = trim($_POST['system_no'] ?? '');
        $cabin = trim($_POST['cabin'] ?? '');
        $issues = trim($_POST['issues'] ?? '');
        $complaint_type = trim($_POST['complaint_type'] ?? '');
        $severity = trim($_POST['severity'] ?? '');
        $assigned_to = trim($_POST['assigned_to'] ?? '');
        // Status is always 'Pending' for executive view
        $status = 'Pending';
        $resolved_by = trim($_POST['resolved_by'] ?? '');
        $resolved_date = trim($_POST['resolved_date'] ?? '');
        $comments = trim($_POST['comments'] ?? '');

        // Convert empty resolved_date to NULL for MySQL
        $resolved_date = !empty($resolved_date) ? $resolved_date : NULL;

        // Calculate TAT if resolved
        $tat = NULL;
        if (!empty($resolved_date) && !empty($complaint_date)) {
            $tat = calculateTAT($complaint_date, $resolved_date);
        }

        // Basic validation
        if ($complaint_date === '' || $created_by === '' || $issues === '') {
            redirect_with(['msg' => 'Date, Created By, and Issues are required.', 'type' => 'danger']);
        }

        if ($action === 'add') {
            // Insert new complaint
            $stmt = $mysqli->prepare("INSERT INTO tbl_complaints (complaint_date, created_by, department, system_no, cabin, issues, complaint_type, severity, assigned_to, status, resolved_by, resolved_date, tat, comments, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->bind_param("sssssssssssssss", $complaint_date, $created_by, $department, $system_no, $cabin, $issues, $complaint_type, $severity, $assigned_to, $status, $resolved_by, $resolved_date, $tat, $comments, $_SERVER['REMOTE_ADDR']);

            if ($stmt->execute()) {
                redirect_with(['msg' => "Ticket created successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error creating ticket: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id > 0) {
                // Update complaint
                $sql = "UPDATE tbl_complaints SET
                        complaint_date = ?,
                        created_by = ?,
                        department = ?,
                        system_no = ?,
                        cabin = ?,
                        issues = ?,
                        complaint_type = ?,
                        severity = ?,
                        assigned_to = ?,
                        status = ?,
                        resolved_by = ?,
                        resolved_date = ?,
                        tat = ?,
                        comments = ?,
                        updated_at = NOW(),
                        updated_ip = ?
                        WHERE id = ?";

                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("sssssssssssssssi", $complaint_date, $created_by, $department, $system_no, $cabin, $issues, $complaint_type, $severity, $assigned_to, $status, $resolved_by, $resolved_date, $tat, $comments, $_SERVER['REMOTE_ADDR'], $id);

                if ($stmt->execute()) {
                    redirect_with(['msg' => "Ticket updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating ticket: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            // Delete complaint
            $del = $mysqli->prepare("DELETE FROM tbl_complaints WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();

            redirect_with(['msg' => "Ticket deleted", 'type' => 'warning']);
        }
    }

    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editTicket = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_complaints WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editTicket = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editTicket) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$sortBy = $_GET['sort_by'] ?? 'complaint_date';
$sortOrder = $_GET['sort_order'] ?? 'DESC';

// Validate sort column
$allowedSorts = ['id', 'complaint_date', 'created_by', 'department', 'status', 'severity'];
if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'complaint_date';
}

// Validate sort order
if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
    $sortOrder = 'DESC';
}

// Build query to get:
// 1. Tickets created by current user
// 2. Tickets created by employees where current user is their reporting manager
$sql = "SELECT c.* FROM tbl_complaints c 
        WHERE c.created_by = ? 
        OR c.created_by IN (
            SELECT employee_name 
            FROM employee_master 
            WHERE reporting_manager = ?
        )";

$params = [$current_user_name, $current_user_name];
$types = 'ss';

if ($q !== '') {
    $sql .= " AND (c.created_by LIKE ? OR c.issues LIKE ? OR c.cabin LIKE ? OR c.system_no LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if ($statusFilter !== '') {
    $sql .= " AND c.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

$sql .= " ORDER BY c.$sortBy $sortOrder";

// Execute query with error checking
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    die("Database Error: " . $mysqli->error);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$tickets = $stmt->get_result();

// Get statistics - for current user AND their team members
$total_sql = "SELECT COUNT(*) as cnt FROM tbl_complaints c 
              WHERE c.created_by = ? 
              OR c.created_by IN (
                  SELECT employee_name 
                  FROM employee_master 
                  WHERE reporting_manager = ?
              )";
$stmt = $mysqli->prepare($total_sql);
$stmt->bind_param("ss", $current_user_name, $current_user_name);
$stmt->execute();
$total_result = $stmt->get_result();
$totalTickets = $total_result->fetch_assoc()['cnt'];
$stmt->close();

$pending_sql = "SELECT COUNT(*) as cnt FROM tbl_complaints c 
                WHERE (c.created_by = ? 
                OR c.created_by IN (
                    SELECT employee_name 
                    FROM employee_master 
                    WHERE reporting_manager = ?
                )) AND c.status = 'Pending'";
$stmt = $mysqli->prepare($pending_sql);
$stmt->bind_param("ss", $current_user_name, $current_user_name);
$stmt->execute();
$pending_result = $stmt->get_result();
$pendingTickets = $pending_result->fetch_assoc()['cnt'];
$stmt->close();

$closed_sql = "SELECT COUNT(*) as cnt FROM tbl_complaints c 
               WHERE (c.created_by = ? 
               OR c.created_by IN (
                   SELECT employee_name 
                   FROM employee_master 
                   WHERE reporting_manager = ?
               )) AND c.status = 'Closed'";
$stmt = $mysqli->prepare($closed_sql);
$stmt->bind_param("ss", $current_user_name, $current_user_name);
$stmt->execute();
$closed_result = $stmt->get_result();
$closedTickets = $closed_result->fetch_assoc()['cnt'];
$stmt->close();

// Get dropdown data
$teams = $mysqli->query("SELECT team_id, team_name FROM team_master WHERE status = 'Active' ORDER BY team_name ASC");

// Get employees for dropdown - from employee_master table
$employees = $mysqli->query("SELECT employee_id, employee_name FROM employee_master WHERE status = 'Active' ORDER BY employee_name ASC");
$employeeOptions = [];
if ($employees) {
    while($emp = $employees->fetch_assoc()) {
        $employeeOptions[] = $emp;
    }
}

// Get system data for auto-fill - using employee_name as key
$systemData = [];
$systemsQuery = $mysqli->query("SELECT employee_name, team, system_no, cabin_no FROM tbl_systems WHERE employee_name IS NOT NULL AND employee_name !=''");
if ($systemsQuery && $systemsQuery->num_rows > 0) {
    while($system = $systemsQuery->fetch_assoc()) {
        // Trim whitespace to ensure proper matching
        $employeeName = trim($system['employee_name']);
        $systemData[$employeeName] = [
            'team' => $system['team'],
            'system_no' => $system['system_no'],
            'cabin_no' => $system['cabin_no']
        ];
    }
}

// Get current user's system data for auto-fill in form
$currentUserSystemData = [];
if ($current_user_name) {
    $userSystemQuery = $mysqli->prepare("SELECT team, system_no, cabin_no FROM tbl_systems WHERE employee_name = ?");
    $userSystemQuery->bind_param("s", $current_user_name);
    $userSystemQuery->execute();
    $userSystemResult = $userSystemQuery->get_result();
    
    if ($userSystemResult->num_rows > 0) {
        $currentUserSystemData = $userSystemResult->fetch_assoc();
    }
    $userSystemQuery->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IT Ticket Management - Executive View</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <style>
        body { 
            background-color: #f8f9fa; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
        }
        .main-content { 
            padding: 90px 25px 30px 25px; 
            max-width: 1900px; 
            margin: 0 auto; 
        }
        .table thead th { 
            background-color: #707981 !important; 
            color: white !important; 
            border: 1px solid #5a6268 !important; 
            font-size: 11px; 
            font-weight: bold;
            text-transform: uppercase;
            white-space: nowrap; 
            padding: 8px 5px; 
        }
        .table td { 
            vertical-align: middle; 
            font-size: 12px; 
            padding: 8px 5px; 
        }
        .card { 
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); 
            border: 1px solid rgba(0, 0, 0, 0.125); 
            margin-bottom: 20px;
        }
        .btn-group-sm > .btn { 
            padding: 0.25rem 0.5rem; 
        }
        .stats-card { 
            border-left: 4px solid; 
            padding: 15px; 
            margin-bottom: 15px; 
            border-radius: 4px;
        }
        .stats-card.total { border-left-color: #3498db; }
        .stats-card.pending { border-left-color: #f39c12; }
        .stats-card.closed { border-left-color: #27ae60; }
        .select2-container--default .select2-selection--single { 
            height: 38px; 
            padding: 6px 12px; 
            border: 1px solid #ced4da; 
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow { 
            height: 36px; 
        }
        .section-title {
            border-bottom: 2px solid #2c3e50;
            padding-bottom: 10px;
            margin-bottom: 20px;
            color: #2c3e50;
        }
        .badge {
            font-size: 0.75em;
        }
        .table-responsive {
            border-radius: 4px;
        }
        .form-label {
            font-weight: 500;
        }
        .team-badge {
            background-color: #e3f2fd;
            color: #1976d2;
            font-size: 0.7em;
            padding: 2px 6px;
            border-radius: 10px;
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

        <!-- Debug info (remove in production) -->
        <?php if(false): ?>
        <div class="alert alert-info">
            <strong>Debug Info:</strong><br>
            Current User: <?= h($current_user) ?><br>
            Employee Name: <?= h($current_user_name) ?><br>
            Reporting Manager: <?= h($reporting_manager_name) ?><br>
            Viewing tickets for: <?= h($current_user_name) ?> and their team members
        </div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>

        <!-- Add/Edit Form -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-ticket-alt me-2"></i><?= $mode === 'edit' ? 'Edit Ticket' : 'Create New Ticket' ?></h4>
            <a href="ticketmanagementexecutive.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>
        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off" id="ticketForm">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editTicket['id'] ?? 0) ?>">
                    <?php endif; ?>
                    
                    <!-- Hidden field to store auto-fill data -->
                    <input type="hidden" id="auto_fill_data" value='<?= json_encode($currentUserSystemData) ?>'>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="complaint_date" class="form-label">Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="complaint_date" name="complaint_date"
                                       value="<?= h($editTicket['complaint_date'] ?? date('Y-m-d')) ?>" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label">Employee Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="created_by" name="created_by" 
                                       value="<?= h($current_user_name) ?>" readonly>
                                <small class="text-muted">Your name is auto-filled from your account</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="department" class="form-label">Team</label>
                                <select class="form-select" id="department" name="department">
                                    <option value="">Select Team</option>
                                    <?php
                                    if($teams):
                                        $teams->data_seek(0);
                                        while($team = $teams->fetch_assoc()):
                                            $selected = '';
                                            if ($mode === 'edit' && isset($editTicket)) {
                                                $selected = ($editTicket['department'] == $team['team_name']) ? 'selected' : '';
                                            } elseif ($mode === 'add' && !empty($currentUserSystemData['team'])) {
                                                $selected = ($currentUserSystemData['team'] == $team['team_name']) ? 'selected' : '';
                                            }
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
                                <label for="system_no" class="form-label">System No</label>
                                <input type="text" class="form-control" id="system_no" name="system_no"
                                       value="<?= 
                                            h($editTicket['system_no'] ?? 
                                            (!empty($currentUserSystemData['system_no']) ? $currentUserSystemData['system_no'] : '')) 
                                       ?>"
                                       placeholder="e.g., C14, PC-001">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="cabin" class="form-label">Cabin No</label>
                                <input type="text" class="form-control" id="cabin" name="cabin"
                                       value="<?= 
                                            h($editTicket['cabin'] ?? 
                                            (!empty($currentUserSystemData['cabin_no']) ? $currentUserSystemData['cabin_no'] : '')) 
                                       ?>"
                                       placeholder="e.g., 115, B-12">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="complaint_type" class="form-label">Complaint Type</label>
                                <select class="form-select" id="complaint_type" name="complaint_type">
                                    <option value="">Select Type</option>
                                    <option value="Mouse" <?= (($editTicket['complaint_type'] ?? '') === 'Mouse') ? 'selected' : '' ?>>Mouse</option>
                                    <option value="Keyboard" <?= (($editTicket['complaint_type'] ?? '') === 'Keyboard') ? 'selected' : '' ?>>Keyboard</option>
                                    <option value="Monitor" <?= (($editTicket['complaint_type'] ?? '') === 'Monitor') ? 'selected' : '' ?>>Monitor</option>
                                    <option value="CPU" <?= (($editTicket['complaint_type'] ?? '') === 'CPU') ? 'selected' : '' ?>>CPU</option>
                                    <option value="UPS" <?= (($editTicket['complaint_type'] ?? '') === 'UPS') ? 'selected' : '' ?>>UPS</option>
                                    <option value="Battery Issue" <?= (($editTicket['complaint_type'] ?? '') === 'Battery Issue') ? 'selected' : '' ?>>Battery Issue</option>
                                    <option value="Charger" <?= (($editTicket['complaint_type'] ?? '') === 'Charger') ? 'selected' : '' ?>>Charger</option>
                                    <option value="Software" <?= (($editTicket['complaint_type'] ?? '') === 'Software') ? 'selected' : '' ?>>Software</option>
                                    <option value="LAN" <?= (($editTicket['complaint_type'] ?? '') === 'LAN') ? 'selected' : '' ?>>LAN</option>
                                    <option value="Printer" <?= (($editTicket['complaint_type'] ?? '') === 'Printer') ? 'selected' : '' ?>>Printer</option>
                                    <option value="Network" <?= (($editTicket['complaint_type'] ?? '') === 'Network') ? 'selected' : '' ?>>Network</option>
                                    <option value="Other" <?= (($editTicket['complaint_type'] ?? '') === 'Other') ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="severity" class="form-label">Severity</label>
                                <select class="form-select" id="severity" name="severity">
                                    <option value="">Select Severity</option>
                                    <?php
                                    $currentSeverity = '';
                                    if ($mode === 'edit' && isset($editTicket['severity'])) {
                                        $currentSeverity = strtolower(trim($editTicket['severity']));
                                    }
                                    ?>
                                    <option value="High" <?= ($currentSeverity === 'high') ? 'selected' : '' ?>>High</option>
                                    <option value="Medium" <?= ($currentSeverity === 'medium') ? 'selected' : '' ?>>Medium</option>
                                    <option value="Low" <?= ($currentSeverity === 'low') ? 'selected' : '' ?>>Low</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="assigned_to" class="form-label">Assigned To</label>
                                <input type="text" class="form-control" id="assigned_to" name="assigned_to"
                                       value="<?= h($editTicket['assigned_to'] ?? 'IT Support') ?>">
                                <small class="text-muted">Default: IT Support</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-9">
                            <div class="mb-3">
                                <label for="issues" class="form-label">Issues / Problem Description <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="issues" name="issues" rows="3" required 
                                          placeholder="Describe the issue in detail..."><?= h($editTicket['issues'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label">Auto-filled Data Status</label>
                                <div class="alert alert-info p-2">
                                    <small>
                                        <i class="fas fa-info-circle me-1"></i>
                                        <?php if (!empty($currentUserSystemData)): ?>
                                        System data found: 
                                        Team: <?= h($currentUserSystemData['team'] ?? 'Not set') ?>, 
                                        System: <?= h($currentUserSystemData['system_no'] ?? 'Not set') ?>, 
                                        Cabin: <?= h($currentUserSystemData['cabin_no'] ?? 'Not set') ?>
                                        <?php else: ?>
                                        No system data found for your account. Please fill manually.
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Hidden fields (not editable by executive) -->
                    <input type="hidden" name="resolved_by" value="<?= h($editTicket['resolved_by'] ?? '') ?>">
                    <input type="hidden" name="resolved_date" value="<?= h($editTicket['resolved_date'] ?? '') ?>">
                    <input type="hidden" name="comments" value="<?= h($editTicket['comments'] ?? '') ?>">
                    
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Ticket' : 'Create Ticket' ?>
                        </button>
                        <a href="ticketmanagementexecutive.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode - IT Ticket Management -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-ticket-alt me-2"></i>System Complaints</h4>
            <div class="d-flex align-items-center">
                <?php if($current_user_name): ?>
                <span class="badge bg-info me-2">Viewing: <?= h($current_user_name) ?>'s Team</span>
                <?php endif; ?>
                <a href="ticketmanagementexecutive.php?mode=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Create Ticket
                </a>
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-4">
                <div class="card stats-card total">
                    <h3><?= $totalTickets ?></h3>
                    <p class="mb-0">Total Tickets</p>
                    <small class="text-muted">Your tickets + team members</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stats-card pending">
                    <h3><?= $pendingTickets ?></h3>
                    <p class="mb-0">Pending Tickets</p>
                    <small class="text-muted">Awaiting resolution</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stats-card closed">
                    <h3><?= $closedTickets ?></h3>
                    <p class="mb-0">Closed Tickets</p>
                    <small class="text-muted">Resolved tickets</small>
                </div>
            </div>
        </div>
        
        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control" placeholder="Search by issue, system, or cabin..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Closed" <?= $statusFilter === 'Closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="sort_by" class="form-select">
                            <option value="complaint_date" <?= $sortBy === 'complaint_date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="severity" <?= $sortBy === 'severity' ? 'selected' : '' ?>>Sort by Severity</option>
                            <option value="status" <?= $sortBy === 'status' ? 'selected' : '' ?>>Sort by Status</option>
                            <option value="created_by" <?= $sortBy === 'created_by' ? 'selected' : '' ?>>Sort by Employee</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="sort_order" class="form-select">
                            <option value="DESC" <?= $sortOrder === 'DESC' ? 'selected' : '' ?>>Newest First</option>
                            <option value="ASC" <?= $sortOrder === 'ASC' ? 'selected' : '' ?>>Oldest First</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i> Search</button>
                        <a href="ticketmanagementexecutive.php" class="btn btn-secondary ms-2"><i class="fas fa-times"></i></a>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Ticket ID</th>
                                <th>Date</th>
                                <th>Employee Name</th>
                                <th>Team</th>
                                <th>System</th>
                                <th>Cabin</th>
                                <th>Issues</th>
                                <th>Type</th>
                                <th>Severity</th>
                                <th>Assigned To</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($tickets && $tickets->num_rows > 0): ?>
                                <?php while($row = $tickets->fetch_assoc()): 
                                    $isOwnTicket = ($row['created_by'] === $current_user_name);
                                ?>
                                <tr>
                                    <td><?= $row['id'] ?></td>
                                    <td><?= formatDate($row['complaint_date']) ?></td>
                                    <td>
                                        <?= h($row['created_by']) ?>
                                        <?php if(!$isOwnTicket): ?>
                                            <span class="team-badge">Team Member</span>
                                        <?php else: ?>
                                            <span class="team-badge" style="background-color:#e8f5e8;color:#388e3c;">My Ticket</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($row['department']) ?></td>
                                    <td><?= h($row['system_no']) ?></td>
                                    <td><?= h($row['cabin']) ?></td>
                                    <td><small><?= h($row['issues']) ?></small></td>
                                    <td><?= h($row['complaint_type']) ?></td>
                                    <td>
                                        <?php 
                                            $sev = strtolower(trim($row['severity']));
                                            if ($sev === 'high') {
                                                echo '<span class="badge bg-danger">High</span>';
                                            } 
                                            elseif ($sev === 'medium') {
                                                echo '<span class="badge bg-warning text-dark">Medium</span>';
                                            } 
                                            elseif ($sev === 'low') {
                                                echo '<span class="badge bg-primary">Low</span>';
                                            } 
                                            else {
                                                echo '<span class="badge bg-secondary">' . h($row['severity']) . '</span>';
                                            }
                                        ?>
                                    </td>
                                    <td><?= h($row['assigned_to']) ?></td>
                                    <td>
                                        <?php if($row['status'] === 'Closed'): ?>
                                            <span class="badge bg-success">Closed</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <!-- Edit Ticket -->
                                            <a href="ticketmanagementexecutive.php?mode=edit&id=<?= $row['id'] ?>" class="btn btn-outline-primary" title="Edit Ticket">
                                                <i class="fas fa-edit"></i>
                                            </a>

                                            <!-- Delete Option (Pending tickets only) -->
                                            <?php if($row['status'] === 'Pending'): ?>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to delete this ticket?');" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="12" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-ticket-alt fa-3x mb-3"></i>
                                            <h5>No tickets found</h5>
                                            <?php if(empty($q) && empty($statusFilter)): ?>
                                            <p class="mb-3">No tickets found for you or your team members.</p>
                                            <?php endif; ?>
                                            <a href="ticketmanagementexecutive.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Create Your First Ticket
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
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-close alert messages
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                if (alert.isConnected) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            }, 5000);
        });

        // Initialize Select2 for dropdowns
        if ($('.employee-select').length) {
            $('.employee-select').select2({
                placeholder: "Select Employee",
                allowClear: true
            });
        }

        // Auto-fill form data on page load for "add" mode
        if (window.location.search.includes('mode=add')) {
            try {
                // Get the auto-fill data from hidden field
                const autoFillData = JSON.parse(document.getElementById('auto_fill_data').value);
                
                if (autoFillData && Object.keys(autoFillData).length > 0) {
                    console.log('Auto-fill data found:', autoFillData);
                    
                    // Fill Team dropdown
                    if (autoFillData.team && autoFillData.team.trim() !== '') {
                        $('#department').val(autoFillData.team).trigger('change');
                    }
                    
                    // Fill System No
                    if (autoFillData.system_no && autoFillData.system_no.trim() !== '') {
                        $('#system_no').val(autoFillData.system_no);
                    }
                    
                    // Fill Cabin No
                    if (autoFillData.cabin_no && autoFillData.cabin_no.trim() !== '') {
                        $('#cabin').val(autoFillData.cabin_no);
                    }
                    
                    // Show success message
                    showToast('System information auto-filled successfully!', 'success');
                } else {
                    console.log('No auto-fill data available');
                    showToast('No system information found for your account. Please fill manually.', 'info');
                }
            } catch (error) {
                console.error('Error parsing auto-fill data:', error);
            }
        }

        // Form validation
        const ticketForm = document.getElementById('ticketForm');
        if (ticketForm) {
            ticketForm.addEventListener('submit', function(e) {
                const issues = document.getElementById('issues').value.trim();
                if (!issues) {
                    e.preventDefault();
                    alert('Please describe the issue before submitting.');
                    document.getElementById('issues').focus();
                    return false;
                }
                
                const severity = document.getElementById('severity').value;
                if (!severity) {
                    if (!confirm('No severity selected. Continue with default?')) {
                        e.preventDefault();
                        return false;
                    }
                }
                
                return true;
            });
        }

        // Helper function to show toast messages
        function showToast(message, type = 'info') {
            // Create toast element
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-white bg-${type === 'success' ? 'success' : type === 'error' ? 'danger' : 'info'} border-0`;
            toast.setAttribute('role', 'alert');
            toast.setAttribute('aria-live', 'assertive');
            toast.setAttribute('aria-atomic', 'true');
            
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'} me-2"></i>
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            
            // Add to page
            document.body.appendChild(toast);
            
            // Initialize and show toast
            const bsToast = new bootstrap.Toast(toast, {
                autohide: true,
                delay: 3000
            });
            bsToast.show();
            
            // Remove from DOM after hide
            toast.addEventListener('hidden.bs.toast', function () {
                document.body.removeChild(toast);
            });
        }
    });
    </script>
</body>
</html>