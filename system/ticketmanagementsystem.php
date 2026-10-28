<?php
require 'db.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// --- AUTOMATION: Set 'Long Pending' for tickets older than 10 days ---
$ten_days_ago = date('Y-m-d', strtotime('-10 days'));
$mysqli->query("UPDATE tbl_complaints SET status = 'Long Pending' 
                WHERE status = 'Pending' AND complaint_date < '$ten_days_ago'");
// ---------------------------------------------------------------------

// Helper functions
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function formatDate($d) {
    if (empty($d) || strpos($d, '0000-00-00') === 0) return '—';
    return date('d-m-Y', strtotime($d));
}

function formatDateForInput($d) {
    if (empty($d) || strpos($d, '0000-00-00') === 0) return '';
    return date('Y-m-d', strtotime($d));
}

function calculateTAT($complaint_date, $resolved_date) {
    if (empty($resolved_date) || strpos($resolved_date, '0000-00-00') === 0) return '—';
    $start = new DateTime($complaint_date);
    $end = new DateTime($resolved_date);
    $diff = $start->diff($end);
    return $diff->days;
}

function redirect_with($params = []) {
    $base = 'ticketmanagementsystem.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // DEBUG: Log all POST data
    error_log("=== FORM SUBMISSION DEBUG ===");
    error_log("POST ACTION: " . $action);
    error_log("POST DATA: " . print_r($_POST, true));
    
    if ($action === 'add' || $action === 'edit') {
        $complaint_date = trim($_POST['complaint_date'] ?? '');
        $created_by = trim($_POST['created_by'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $system_no = trim($_POST['system_no'] ?? '');
        $cabin = trim($_POST['cabin'] ?? '');
        $issues = trim($_POST['issues'] ?? '');
        $complaint_type = trim($_POST['complaint_type'] ?? '');
        $severity = trim($_POST['severity'] ?? '');
        $assigned_to = trim($_POST['assigned_to'] ?? '');
        $status = trim($_POST['status'] ?? 'Pending');
        $resolved_by = trim($_POST['resolved_by'] ?? '');
        $comments = trim($_POST['comments'] ?? '');
        $resolved_date = trim($_POST['resolved_date'] ?? '');
        
        // DEBUG: Log the values we're trying to update
        error_log("Severity value: " . $severity);
        error_log("Status value: " . $status);
        error_log("Complaint type value: " . $complaint_type);
        
        if ($resolved_date === '') { $resolved_date = null; }
        $tat = null;
        if ($resolved_date !== null && $complaint_date) { $tat = calculateTAT($complaint_date, $resolved_date); }
        if ($complaint_date === '' || $created_by === '' || $issues === '') { redirect_with(['msg' => 'Date, Created By, and Issues are required.', 'type' => 'danger']); }
        
        if ($action === 'add') {
            $stmt = $mysqli->prepare("INSERT INTO tbl_complaints (complaint_date, created_by, department, system_no, cabin, issues, complaint_type, severity, assigned_to, status, resolved_by, resolved_date, tat, comments, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->bind_param("sssssssssssssss", $complaint_date, $created_by, $department, $system_no, $cabin, $issues, $complaint_type, $severity, $assigned_to, $status, $resolved_by, $resolved_date, $tat, $comments, $_SERVER['REMOTE_ADDR']);
            if ($stmt->execute()) { 
                error_log("INSERT SUCCESS");
                redirect_with(['msg' => "Ticket created successfully", 'type' => 'success']); 
            } else { 
                error_log("INSERT ERROR: " . $stmt->error);
                redirect_with(['msg' => 'Error creating ticket: ' . $stmt->error, 'type' => 'danger']); 
            }
            $stmt->close();
        } elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $sql = "UPDATE tbl_complaints SET complaint_date = ?, created_by = ?, department = ?, system_no = ?, cabin = ?, issues = ?, complaint_type = ?, severity = ?, assigned_to = ?, status = ?, resolved_by = ?, resolved_date = ?, tat = ?, comments = ?, updated_at = NOW(), updated_ip = ? WHERE id = ?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("sssssssssssssssi", $complaint_date, $created_by, $department, $system_no, $cabin, $issues, $complaint_type, $severity, $assigned_to, $status, $resolved_by, $resolved_date, $tat, $comments, $_SERVER['REMOTE_ADDR'], $id);
                
                if ($stmt->execute()) { 
                    error_log("UPDATE SUCCESS");
                    redirect_with(['msg' => "Ticket updated successfully", 'type' => 'success']); 
                } else { 
                    error_log("UPDATE ERROR: " . $stmt->error);
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
            redirect_with(['msg' => "Ticket deleted", 'type' => 'warning']); 
        } 
    }
    
    redirect_with();
}

// Get mode and data for display
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

$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$teamFilter = $_GET['team'] ?? ''; // New team filter
$sortBy = $_GET['sort_by'] ?? 'complaint_date';
$sortOrder = $_GET['sort_order'] ?? 'DESC';
$allowedSorts = ['id', 'complaint_date', 'created_by', 'department', 'status', 'severity'];
if (!in_array($sortBy, $allowedSorts)) { $sortBy = 'complaint_date'; }
if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') { $sortOrder = 'DESC'; }

// Build SQL query with filters
$sql = "SELECT * FROM tbl_complaints WHERE 1=1";
$params = [];
$types = '';

// Add search filter
if ($q !== '') { 
    $sql .= " AND (created_by LIKE ? OR issues LIKE ? OR cabin LIKE ? OR system_no LIKE ?)"; 
    $like = "%$q%"; 
    $params[] = $like; 
    $params[] = $like; 
    $params[] = $like; 
    $params[] = $like; 
    $types .= 'ssss'; 
}

// Add status filter
if ($statusFilter !== '') { 
    $sql .= " AND status = ?"; 
    $params[] = $statusFilter; 
    $types .= 's'; 
}

// Add team filter
if ($teamFilter !== '') { 
    $sql .= " AND department = ?"; 
    $params[] = $teamFilter; 
    $types .= 's'; 
}

$sql .= " ORDER BY $sortBy $sortOrder";

// Execute query with parameters if any
if (!empty($params)) { 
    $stmt = $mysqli->prepare($sql); 
    $stmt->bind_param($types, ...$params); 
    $stmt->execute(); 
    $tickets = $stmt->get_result(); 
} else { 
    $tickets = $mysqli->query($sql); 
}

// Get statistics (these could also be filtered if needed, but keeping them as totals)
$totalTickets = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_complaints")->fetch_assoc()['cnt'];
$pendingTickets = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_complaints WHERE status = 'Pending' OR status = 'Long Pending'")->fetch_assoc()['cnt'];
$closedTickets = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_complaints WHERE status = 'Closed'")->fetch_assoc()['cnt'];

// Get employees for dropdown
$employees = $mysqli->query("SELECT employee_id, employee_name FROM employee_master WHERE status = 'Active' ORDER BY employee_name ASC");
$employeeOptions = [];
if ($employees) { while($emp = $employees->fetch_assoc()) { $employeeOptions[] = $emp; } }

// Get distinct teams for filter dropdown
$teamsQuery = $mysqli->query("SELECT DISTINCT department FROM tbl_complaints WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
$teams = [];
if ($teamsQuery && $teamsQuery->num_rows > 0) {
    while($team = $teamsQuery->fetch_assoc()) {
        $teams[] = $team['department'];
    }
}

// Get system data for auto-fill
$systemData = [];
$systemsQuery = $mysqli->query("SELECT employee_name, team, system_no, cabin_no FROM tbl_systems WHERE employee_name IS NOT NULL AND employee_name != ''");
if ($systemsQuery && $systemsQuery->num_rows > 0) { while($system = $systemsQuery->fetch_assoc()) { $systemData[trim($system['employee_name'])] = [ 'team' => $system['team'], 'system_no' => $system['system_no'], 'cabin_no' => $system['cabin_no'] ]; } }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Support Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { padding: 20px; max-width: 1900px; margin: 0 auto; }
        .table th { background-color: #2c3e50; color: white; border: none; font-size: 11px; white-space: nowrap; padding: 8px 5px; }
        .table td { vertical-align: middle; font-size: 12px; padding: 8px 5px; }
        .card { box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); border: 1px solid rgba(0, 0, 0, 0.125); }
        .stats-card { border-left: 4px solid; padding: 15px; margin-bottom: 15px; }
        .stats-card.total { border-left-color: #3498db; }
        .stats-card.pending { border-left-color: #f39c12; }
        .stats-card.closed { border-left-color: #27ae60; }
        .stats-card.long-pending { border-left-color: #e74c3c; }
        .select2-container--default .select2-selection--single { height: 38px; padding: 6px 12px; border: 1px solid #ced4da; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
        .filter-section { background-color: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 15px; }

        /* Style for readonly fields to give them a disabled appearance */
        input[readonly], textarea[readonly] {
            background-color: #e9ecef;
            cursor: not-allowed;
        }
        /* Style for disabled Select2 dropdowns */
        .select2-container--disabled .select2-selection--single {
            background-color: #e9ecef;
            cursor: not-allowed;
        }
        /* Badge styles */
        .badge-long-pending {
            background-color: #e74c3c;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.75em;
        }
        .bg-orange {
    background-color: #ff9800; /* Orange color */
    color: white;
}
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert"><?= h($_GET['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
        
        <!-- Add/Edit Form -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <!--h4><i class="fas fa-ticket-alt me-2"></i><?= $mode === 'edit' ? 'Edit Ticket' : 'Create New Ticket' ?></h4-->
            <a href="ticketmanagementsystem.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to List</a>
        </div>
        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off" id="ticketForm">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?><input type="hidden" name="id" value="<?= (int)($editTicket['id'] ?? 0) ?>"><?php endif; ?>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="complaint_date" class="form-label">Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="complaint_date" name="complaint_date" 
                                       value="<?= h($mode === 'edit' ? formatDateForInput($editTicket['complaint_date']) : date('Y-m-d')) ?>" required
                                       <?= $mode === 'edit' ? 'readonly' : '' ?>>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="created_by" class="form-label">Created By <span class="text-danger">*</span></label>
                                <?php if($mode === 'edit'): ?>
                                    <!-- In edit mode, show as readonly text but submit hidden field -->
                                    <input type="text" class="form-control" value="<?= h($editTicket['created_by'] ?? '') ?>" readonly>
                                    <input type="hidden" name="created_by" value="<?= h($editTicket['created_by'] ?? '') ?>">
                                <?php else: ?>
                                    <select class="form-select" id="created_by" name="created_by" required>
                                        <option value="">Select Employee</option>
                                        <?php foreach($employeeOptions as $emp): ?>
                                        <option value="<?= h($emp['employee_name']) ?>" <?= ($editTicket && $editTicket['created_by'] == $emp['employee_name']) ? 'selected' : '' ?>><?= h(trim($emp['employee_name'])) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="department" class="form-label">Team</label>
                                <input type="text" class="form-control" id="department" name="department" value="<?= h($editTicket['department'] ?? '') ?>" placeholder="Team Name" <?= $mode === 'edit' ? 'readonly' : '' ?>>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="system_no" class="form-label">System No</label>
                                <input type="text" class="form-control" id="system_no" name="system_no" value="<?= h($editTicket['system_no'] ?? '') ?>" placeholder="C14" <?= $mode === 'edit' ? 'readonly' : '' ?>>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="cabin" class="form-label">Cabin No</label>
                                <input type="text" class="form-control" id="cabin" name="cabin" value="<?= h($editTicket['cabin'] ?? '') ?>" placeholder="115" <?= $mode === 'edit' ? 'readonly' : '' ?>>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="issues" class="form-label">Issues <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="issues" name="issues" rows="2" required <?= $mode === 'edit' ? 'readonly' : '' ?>><?= h($editTicket['issues'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="complaint_type" class="form-label">Product</label>
                                <?php if($mode === 'edit'): ?>
                                    <!-- In edit mode, show as readonly text but submit hidden field -->
                                    <input type="text" class="form-control" value="<?= h($editTicket['complaint_type'] ?? '') ?>" readonly>
                                    <input type="hidden" name="complaint_type" value="<?= h($editTicket['complaint_type'] ?? '') ?>">
                                <?php else: ?>
                                    <select class="form-select" id="complaint_type" name="complaint_type">
                                        <option value="">Select</option>
                                        <option value="Mouse" <?= ($editTicket && $editTicket['complaint_type'] === 'Mouse') ? 'selected' : '' ?>>Mouse</option>
                                        <option value="Keyboard" <?= ($editTicket && $editTicket['complaint_type'] === 'Keyboard') ? 'selected' : '' ?>>Keyboard</option>
                                        <option value="Monitor" <?= ($editTicket && $editTicket['complaint_type'] === 'Monitor') ? 'selected' : '' ?>>Monitor</option>
                                        <option value="CPU" <?= ($editTicket && $editTicket['complaint_type'] === 'CPU') ? 'selected' : '' ?>>CPU</option>
                                        <option value="UPS" <?= ($editTicket && $editTicket['complaint_type'] === 'UPS') ? 'selected' : '' ?>>UPS</option>
                                        <option value="Software" <?= ($editTicket && $editTicket['complaint_type'] === 'Software') ? 'selected' : '' ?>>Software</option>
                                        <option value="LAN" <?= ($editTicket && $editTicket['complaint_type'] === 'LAN') ? 'selected' : '' ?>>LAN</option>
                                    </select>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="severity" class="form-label">Severity</label>
                                <select class="form-select" id="severity" name="severity">
                                    <option value="">Select</option>
                                    <option value="High"     <?= ($editTicket && $editTicket['severity'] === 'High') ? 'selected' : '' ?>>High</option>
                                    <option value="Medium"   <?= ($editTicket && $editTicket['severity'] === 'Medium') ? 'selected' : '' ?>>Medium</option>
                                    <option value="Low"      <?= ($editTicket && $editTicket['severity'] === 'Low') ? 'selected' : '' ?>>Low</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="assigned_to" class="form-label">Assigned To</label>
                                <input type="text" class="form-control" id="assigned_to" name="assigned_to" value="<?= h($editTicket['assigned_to'] ?? 'Devmohan') ?>" placeholder="Devmohan">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="Pending" <?= ($editTicket && $editTicket['status'] === 'Pending') ? 'selected' : '' ?>>Pending</option>
                                <option value="Long Pending" <?= ($editTicket && $editTicket['status'] === 'Long Pending') ? 'selected' : '' ?>>Long Pending</option>
                                 <option value="Reviewed" <?= ($editTicket && $editTicket['status'] === 'Reviewed') ? 'selected' : '' ?>>Reviewed</option>
                                <option value="Closed" <?= ($editTicket && $editTicket['status'] === 'Closed') ? 'selected' : '' ?>>Closed</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="resolved_by" class="form-label">Resolved By</label>
                            <input type="text" class="form-control" id="resolved_by" name="resolved_by" 
                                   value="<?= h($editTicket['resolved_by'] ?? 'Pradhap') ?>" 
                                   placeholder="Pradhap">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="resolved_date" class="form-label">Resolved Date</label>
                            <input type="date" class="form-control" id="resolved_date" name="resolved_date"
                                   value="<?= h(formatDateForInput($editTicket['resolved_date'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="comments" class="form-label">Comments</label>
                            <input type="text" class="form-control" id="comments" name="comments" value="<?= h($editTicket['comments'] ?? '') ?>" placeholder="Additional notes">
                        </div>
                    </div>
                </div>
                    <div class="d-flex gap-2 mt-4"><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i><?= $mode === 'edit' ? 'Update Ticket' : 'Create Ticket' ?></button><a href="ticketmanagementsystem.php" class="btn btn-outline-secondary">Cancel</a></div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-ticket-alt me-2"></i>System Support Management</h4>
            <!--a href="ticketmanagementsystem.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Create New Ticket
            </a-->
        </div>

        <!-- Statistics -->
        <div class="row mb-3">
            <div class="col-md-3"><div class="card stats-card total"><h3><?= $totalTickets ?></h3><p class="mb-0">Total Tickets</p></div></div>
            <div class="col-md-3"><div class="card stats-card pending"><h3><?= $pendingTickets ?></h3><p class="mb-0">Pending Tickets</p></div></div>
            <div class="col-md-3"><div class="card stats-card closed"><h3><?= $closedTickets ?></h3><p class="mb-0">Closed Tickets</p></div></div>
            <div class="col-md-3">
                <div class="card stats-card long-pending">
                    <h3><?= $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_complaints WHERE status = 'Long Pending'")->fetch_assoc()['cnt'] ?></h3>
                    <p class="mb-0">Long Pending (>10 days)</p>
                </div>
            </div>
        </div>

        <!-- Search and Filter Section -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search by employee, issue, cabin, system..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select form-control-sm">
                            <option value="">All Status</option>
                            <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Long Pending" <?= $statusFilter === 'Long Pending' ? 'selected' : '' ?>>Long Pending</option>
                            <option value="Reviewed" <?= $statusFilter === 'Reviewed' ? 'selected' : '' ?>>Reviewed</option>
                            <option value="Closed" <?= $statusFilter === 'Closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="team" class="form-select form-control-sm">
                            <option value="">All Teams</option>
                            <?php foreach($teams as $team): ?>
                                <option value="<?= h($team) ?>" <?= $teamFilter === $team ? 'selected' : '' ?>><?= h($team) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="sort_by" class="form-select form-control-sm">
                            <option value="complaint_date" <?= $sortBy === 'complaint_date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="severity" <?= $sortBy === 'severity' ? 'selected' : '' ?>>Sort by Severity</option>
                            <option value="status" <?= $sortBy === 'status' ? 'selected' : '' ?>>Sort by Status</option>
                            <option value="department" <?= $sortBy === 'department' ? 'selected' : '' ?>>Sort by Team</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-search me-1"></i> Apply Filters
                        </button>
                        <a href="ticketmanagementsystem.php" class="btn btn-secondary btn-sm ms-2">
                            <i class="fas fa-times me-1"></i> Clear All
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Active Filters Display -->
        <?php if($q !== '' || $statusFilter !== '' || $teamFilter !== ''): ?>
        <div class="mb-3">
            <strong>Active Filters:</strong>
            <?php if($q !== ''): ?>
                <span class="badge bg-info me-2">Search: "<?= h($q) ?>"</span>
            <?php endif; ?>
            <?php if($statusFilter !== ''): ?>
                <span class="badge bg-info me-2">Status: <?= h($statusFilter) ?></span>
            <?php endif; ?>
            <?php if($teamFilter !== ''): ?>
                <span class="badge bg-info me-2">Team: <?= h($teamFilter) ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Tickets Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Created By</th>
                                <th>Team</th>
                                <th>System No</th>
                                <th>Cabin No</th>
                                <th>Issues</th>
                                <th>Product</th>
                                <th>Severity</th>
                                <th>Assigned To</th>
                                <th>Status</th>
                                <th>Resolved By</th>
                                <th>Resolved Date</th>
                                <th>TAT</th>
                                <th>Comment</th>
                                <th width="100" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if($tickets && $tickets->num_rows > 0): ?>
                            <?php while($row = $tickets->fetch_assoc()): ?>
                            <tr>
                                <td><?= formatDate($row['complaint_date']) ?></td>
                                <td><?= h($row['created_by']) ?></td>
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
                                            echo h($row['severity']);
                                        }
                                    ?>
                                </td>
                                <td><?= h($row['assigned_to']) ?></td>
                                <td>
                                    <?php if($row['status'] === 'Closed'): ?>
                                        <span class="badge bg-success">Closed</span>
                                    <?php elseif($row['status'] === 'Long Pending'): ?>
                                        <span class="badge badge-long-pending">Long Pending</span>
                                    <?php elseif($row['status'] === 'Reviewed'): ?>
                                        <span class="badge bg-orange">Reviewed</span>
                                    <?php else:?>
                                        <span class="badge bg-warning">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($row['resolved_by']) ?></td>
                                <td><?= formatDate($row['resolved_date']) ?></td>
                                <td><?= h($row['tat']) ?></td>
                                <td><small><?= h($row['comments']) ?></small></td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <?php if($row['status'] !== 'Closed'): ?>
                                            <!-- Show Edit button ONLY if not Closed -->
                                            <a href="ticketmanagementsystem.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        <?php else: ?>
                                            <!-- Show Lock icon if Closed -->
                                            <button class="btn btn-secondary" disabled title="Ticket Closed (Locked)">
                                                <i class="fas fa-lock"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="15" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="fas fa-ticket-alt fa-3x mb-3"></i>
                                        <h5>No tickets found</h5>
                                        <?php if($q !== '' || $statusFilter !== '' || $teamFilter !== ''): ?>
                                            <p>Try adjusting your filters or <a href="ticketmanagementsystem.php">clear all filters</a></p>
                                        <?php endif; ?>
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
            // Initialize Select2 for employee dropdown if in add mode
            <?php if($mode === 'add'): ?>
            $('#created_by').select2({
                placeholder: 'Select or type to search employee',
                allowClear: true,
                width: '100%'
            });
            <?php endif; ?>
            
            // Auto-fill team, system, cabin when employee is selected (only in add mode)
            <?php if($mode === 'add'): ?>
            const systemData = <?= json_encode($systemData) ?>;
            
            $('#created_by').on('change', function() {
                const selectedEmployee = $(this).val();
                console.log('Selected employee:', selectedEmployee);
                console.log('System data:', systemData[selectedEmployee]);
                
                if (selectedEmployee && systemData[selectedEmployee]) {
                    const data = systemData[selectedEmployee];
                    $('#department').val(data.team || '');
                    $('#system_no').val(data.system_no || '');
                    $('#cabin').val(data.cabin_no || '');
                } else {
                    $('#department').val('');
                    $('#system_no').val('');
                    $('#cabin').val('');
                }
            });
            <?php endif; ?>
            
            // Auto-hide alerts after 5 seconds
            setTimeout(function() {
                document.querySelectorAll('.alert').forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });
    </script>
</body>
</html>