<?php
require 'db.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['itname']) && !isset($_SESSION['infoname']) && !isset($_SESSION['uid'])) { 
    header('Location: ../index.php'); 
    exit; 
}

$current_user = $_SESSION['username'] ?? '';
$ename = $_SESSION['ename'] ?? '';

// Get employee name from employee_master table
$employee_username = $current_user;
$current_user_name = '';
$reporting_manager_name = '';

$sql = "SELECT `employee_name`, `reporting_manager` FROM `employee_master` WHERE `employee_username` = ?";
$stmt = $mysqli->prepare($sql);
if ($stmt) {
    $stmt->bind_param("s", $employee_username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $current_user_name = $row['employee_name'] ?? '';
        $reporting_manager_name = $row['reporting_manager'] ?? '';
    }
    $stmt->close();
}

// Fallback if employee is from users table or ename
if (empty($current_user_name)) {
    $current_user_name = $ename ?: $current_user;
}

$viewing_label = ($current_user_name && $current_user_name !== $current_user) 
    ? ($current_user_name . "'s Team") 
    : "IT Team";

// Helper functions
if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('formatDate')) {
    function formatDate($d) {
        if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
        return date('d-m-Y', strtotime($d));
    }
}

if (!function_exists('calculateTAT')) {
    function calculateTAT($complaint_date, $resolved_date) {
        if (empty($resolved_date) || $resolved_date === '0000-00-00' || $resolved_date === '0000-00-00 00:00:00') return '—';
        $start = new DateTime($complaint_date);
        $end = new DateTime($resolved_date);
        $diff = $start->diff($end);
        return $diff->days;
    }
}

if (!function_exists('redirect_with')) {
    function redirect_with($params = []) {
        $base = 'ticketmanagementexecutive.php';
        if (!empty($params)) $base .= '?' . http_build_query($params);
        header("Location: $base");
        exit;
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $complaint_date = trim($_POST['complaint_date'] ?? date('Y-m-d'));
        $created_by = trim($_POST['created_by'] ?? $current_user_name);
        $department = trim($_POST['department'] ?? 'IT');
        $system_no = trim($_POST['system_no'] ?? '');
        $cabin = trim($_POST['cabin'] ?? '');
        $issues = trim($_POST['issues'] ?? '');
        $complaint_type = trim($_POST['complaint_type'] ?? '');
        $severity = trim($_POST['severity'] ?? 'Medium');
        $assigned_to = trim($_POST['assigned_to'] ?? 'IT Support');
        $status = 'Pending';
        $resolved_by = trim($_POST['resolved_by'] ?? '');
        $resolved_date = trim($_POST['resolved_date'] ?? '');
        $resolved_date = ($resolved_date === '') ? null : $resolved_date; 
        $comments = trim($_POST['comments'] ?? '');

        // Calculate TAT if resolved
        $tat = null; 
        if ($resolved_date && $complaint_date) {
            $tat = calculateTAT($complaint_date, $resolved_date);
        }

        // Basic validation
        if ($complaint_date === '' || $created_by === '' || $issues === '') {
            redirect_with(['msg' => 'Date, Employee Name, and Issues are required.', 'type' => 'danger']);
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
            $del = $mysqli->prepare("DELETE FROM tbl_complaints WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();

            redirect_with(['msg' => "Ticket deleted successfully", 'type' => 'warning']);
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

// IT Ticket Filtering:
// Show complaints created by the logged-in IT employee OR under department = 'IT' OR by employees in IT team (team_id = 8)
$it_where = "(
    c.created_by = ? 
    OR c.created_by = ? 
    OR c.department = 'IT' 
    OR c.created_by IN (SELECT employee_name FROM employee_master WHERE team_id = 8)
)";
$params = [$current_user_name, $current_user];
$types = 'ss';

$sql = "SELECT c.* FROM tbl_complaints c WHERE $it_where";

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

// Get statistics - strictly for IT complaints
$total_sql = "SELECT COUNT(*) as cnt FROM tbl_complaints c WHERE $it_where";
$stmt = $mysqli->prepare($total_sql);
$stmt->bind_param("ss", $current_user_name, $current_user);
$stmt->execute();
$totalTickets = $stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
$stmt->close();

$pending_sql = "SELECT COUNT(*) as cnt FROM tbl_complaints c WHERE $it_where AND c.status = 'Pending'";
$stmt = $mysqli->prepare($pending_sql);
$stmt->bind_param("ss", $current_user_name, $current_user);
$stmt->execute();
$pendingTickets = $stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
$stmt->close();

$closed_sql = "SELECT COUNT(*) as cnt FROM tbl_complaints c WHERE $it_where AND c.status = 'Closed'";
$stmt = $mysqli->prepare($closed_sql);
$stmt->bind_param("ss", $current_user_name, $current_user);
$stmt->execute();
$closedTickets = $stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
$stmt->close();

// Get dropdown data: Teams
$teams = $mysqli->query("SELECT team_id, team_name FROM team_master WHERE status = 'Active' ORDER BY team_name ASC");

// Get system data for auto-fill in form
$currentUserSystemData = [];
if ($current_user_name) {
    $userSystemQuery = $mysqli->prepare("SELECT team, system_no, cabin_no FROM tbl_systems WHERE employee_name = ? LIMIT 1");
    if ($userSystemQuery) {
        $userSystemQuery->bind_param("s", $current_user_name);
        $userSystemQuery->execute();
        $userSystemResult = $userSystemQuery->get_result();
        if ($userSystemResult && $userSystemResult->num_rows > 0) {
            $currentUserSystemData = $userSystemResult->fetch_assoc();
        }
        $userSystemQuery->close();
    }
}
if (empty($currentUserSystemData['system_no'])) {
    $v2 = $mysqli->prepare("SELECT system_no, os, processor FROM tbl_systems_v2 WHERE id_no = ? LIMIT 1");
    if ($v2) {
        $v2->bind_param("s", $employee_username);
        $v2->execute();
        $r2 = $v2->get_result();
        if ($r2 && $r2->num_rows > 0) {
            $row2 = $r2->fetch_assoc();
            $currentUserSystemData['system_no'] = !empty($row2['system_no']) ? ('Sys #' . $row2['system_no']) : '';
        }
        $v2->close();
    }
}
if (empty($currentUserSystemData['team'])) {
    $currentUserSystemData['team'] = 'IT';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IT Ticket Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body { 
            background-color: #f8f9fa; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
        }
        .main-content { 
            padding: 95px 25px 30px 25px; 
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
            padding: 8px 6px; 
        }
        .table td { 
            vertical-align: middle; 
            font-size: 12px; 
            padding: 8px 6px; 
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

        <?php if($mode === 'add' || $mode === 'edit'): ?>

        <!-- Add/Edit Form (Matches Executive UI) -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-desktop me-2"></i><?= $mode === 'edit' ? 'Edit Ticket' : 'Create New Ticket' ?></h4>
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
                                            } else {
                                                $defaultTeam = !empty($currentUserSystemData['team']) ? $currentUserSystemData['team'] : 'IT';
                                                $selected = ($defaultTeam == $team['team_name']) ? 'selected' : '';
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
                                    <option value="Software" <?= (($editTicket['complaint_type'] ?? '') === 'Software') ? 'selected' : '' ?>>Software</option>
                                    <option value="LAN" <?= (($editTicket['complaint_type'] ?? '') === 'LAN') ? 'selected' : '' ?>>LAN</option>
                                    <option value="Printer" <?= (($editTicket['complaint_type'] ?? '') === 'Printer') ? 'selected' : '' ?>>Printer</option>
                                    <option value="Network" <?= (($editTicket['complaint_type'] ?? '') === 'Network') ? 'selected' : '' ?>>Network</option>
                                    <option value="Battery Issue" <?= (($editTicket['complaint_type'] ?? '') === 'Battery Issue') ? 'selected' : '' ?>>Battery Issue</option>
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
                                    <option value="Medium" <?= ($currentSeverity === 'medium' || empty($currentSeverity)) ? 'selected' : '' ?>>Medium</option>
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
                                <div class="alert alert-info p-2 mb-0">
                                    <small>
                                        <i class="fas fa-info-circle me-1"></i>
                                        <?php if (!empty($currentUserSystemData['system_no'])): ?>
                                        System data found: 
                                        Team: <?= h($currentUserSystemData['team'] ?? 'IT') ?>, 
                                        System: <?= h($currentUserSystemData['system_no']) ?>, 
                                        Cabin: <?= h($currentUserSystemData['cabin_no'] ?? '—') ?>
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

        <!-- View Mode - Matches Executive Image 1 -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-desktop me-2"></i>System Complaints</h4>
            <div class="d-flex align-items-center">
                <span class="badge" style="background-color: #0dcaf0; color: #fff; font-size: 0.85rem; padding: 6px 14px; margin-right: 12px; font-weight: 500;">Viewing: <?= h($viewing_label) ?></span>
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
                    <div class="col-md-3">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Closed" <?= $statusFilter === 'Closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="sort_by" class="form-select">
                            <option value="complaint_date" <?= $sortBy === 'complaint_date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="id" <?= $sortBy === 'id' ? 'selected' : '' ?>>Sort by ID</option>
                            <option value="severity" <?= $sortBy === 'severity' ? 'selected' : '' ?>>Sort by Severity</option>
                            <option value="created_by" <?= $sortBy === 'created_by' ? 'selected' : '' ?>>Sort by Name</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="sort_order" class="form-select">
                            <option value="DESC" <?= $sortOrder === 'DESC' ? 'selected' : '' ?>>Newest First</option>
                            <option value="ASC" <?= $sortOrder === 'ASC' ? 'selected' : '' ?>>Oldest First</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex">
                        <button type="submit" class="btn btn-primary me-2"><i class="fas fa-search me-1"></i> Search</button>
                        <a href="ticketmanagementexecutive.php" class="btn btn-secondary"><i class="fas fa-times"></i></a>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-secondary">
                            <tr>
                                <th>TICKET ID</th>
                                <th>DATE</th>
                                <th>EMPLOYEE NAME</th>
                                <th>TEAM</th>
                                <th>SYSTEM</th>
                                <th>CABIN</th>
                                <th>ISSUES</th>
                                <th>TYPE</th>
                                <th>SEVERITY</th>
                                <th>ASSIGNED TO</th>
                                <th>STATUS</th>
                                <th>ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($tickets && $tickets->num_rows > 0): ?>
                                <?php while($row = $tickets->fetch_assoc()): 
                                    $isOwnTicket = ($row['created_by'] === $current_user_name || $row['created_by'] === $current_user);
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
                                            <?php if($isOwnTicket): ?>
                                                <a href="ticketmanagementexecutive.php?mode=edit&id=<?= $row['id'] ?>" class="btn btn-outline-primary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php if($row['status'] === 'Pending'): ?>
                                                <form method="post" style="display:inline;">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to delete this ticket?');" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <button class="btn btn-outline-secondary" title="View Only (Team Member Ticket)" disabled>
                                                    <i class="fas fa-eye"></i>
                                                </button>
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
                                            <p class="mb-3">No IT tickets found for your account.</p>
                                            <?php endif; ?>
                                            <a href="ticketmanagementexecutive.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Create Ticket
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
        const alerts = document.querySelectorAll('.alert:not(.alert-info)');
        alerts.forEach(alert => {
            setTimeout(() => {
                if (alert.isConnected) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            }, 5000);
        });

        // Auto-fill form data on page load for "add" mode
        if (window.location.search.includes('mode=add')) {
            try {
                const autoFillData = JSON.parse(document.getElementById('auto_fill_data').value || '{}');
                
                if (autoFillData && Object.keys(autoFillData).length > 0) {
                    if (autoFillData.team && autoFillData.team.trim() !== '') {
                        $('#department').val(autoFillData.team);
                    }
                    if (autoFillData.system_no && autoFillData.system_no.trim() !== '') {
                        $('#system_no').val(autoFillData.system_no);
                    }
                    if (autoFillData.cabin_no && autoFillData.cabin_no.trim() !== '') {
                        $('#cabin').val(autoFillData.cabin_no);
                    }
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
                return true;
            });
        }
    });
    </script>
</body>
</html>
