<?php
// -------------------------------------------------------------
// FILE: teamleader_ot_approval.php
// PURPOSE: Team Leader interface to Approve/Reject OT claims
// -------------------------------------------------------------

require 'db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a Team Leader
$superadmin_username = $_SESSION['supername'] ?? 'Super Admin';

// --- 1. HANDLE APPROVAL/REJECTION ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action']) && isset($_POST['ot_id'])) {
        $ot_id = $_POST['ot_id'];
        $action = $_POST['action'];
        $admin_remarks = $mysqli->real_escape_string($_POST['admin_remarks'] ?? '');
        
        // Verify OT claim belongs to team member in same team
        $verify_query = "SELECT la.*, em.team_id 
                         FROM leave_applications la
                         JOIN employee_master em ON la.employee_username = em.employee_username
                         WHERE la.id = '$ot_id' 
                         AND em.user_role_id IN (2, 3, 9, 10) 
                         AND la.request_type IN ('OT_Claim', 'Comp_Off_Leave', 'Permission')";
        $verify_result = $mysqli->query($verify_query);
        
        if ($verify_result->num_rows > 0) {
            $status = ($action == 'approve') ? 'Approved' : 'Rejected';
            
            $update_sql = "UPDATE leave_applications 
                          SET status = '$status', 
                              admin_remarks = '$admin_remarks',
                              approved_by = '$superadmin_username',
                              approved_at = NOW()
                          WHERE id = '$ot_id'";
            
            if ($mysqli->query($update_sql)) {
                $msg = "OT claim " . strtolower($status) . " successfully!";
                $msg_type = "success";
            } else {
                $msg = "Error: " . $mysqli->error;
                $msg_type = "danger";
            }
        } else {
            $msg = "You are not authorized to modify this OT claim!";
            $msg_type = "danger";
        }
    }
    
    // Handle bulk actions (only for pending claims)
    if (isset($_POST['bulk_action']) && isset($_POST['selected_claims'])) {
        $bulk_action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_claims'];
        $bulk_remarks = $mysqli->real_escape_string($_POST['bulk_remarks'] ?? '');
        
        $status = ($bulk_action == 'approve') ? 'Approved' : 'Rejected';
        $ids_str = implode(',', array_map('intval', $selected_ids));
        
        // Verify all selected claims belong to team members AND are pending
        $verify_query = "SELECT la.id 
                         FROM leave_applications la
                         JOIN employee_master em ON la.employee_username = em.employee_username
                         WHERE la.id IN ($ids_str) 
                         AND em.user_role_id IN (2, 3, 9, 10) 
                         AND la.request_type IN ('OT_Claim', 'Comp_Off_Leave', 'Permission')
                         AND la.status = 'Pending'";
        $verify_result = $mysqli->query($verify_query);
        
        if ($verify_result->num_rows > 0) {
            $update_sql = "UPDATE leave_applications 
                          SET status = '$status', 
                              admin_remarks = CONCAT(COALESCE(admin_remarks, ''), ' [Bulk: $bulk_remarks]'),
                              approved_by = '$superadmin_username',
                              approved_at = NOW()
                          WHERE id IN ($ids_str) 
                          AND status = 'Pending'";
            
            if ($mysqli->query($update_sql)) {
                $msg = "Bulk action completed! " . $verify_result->num_rows . " claims " . strtolower($status);
                $msg_type = "success";
            } else {
                $msg = "Error in bulk update: " . $mysqli->error;
                $msg_type = "danger";
            }
        } else {
            $msg = "No valid pending claims found for bulk action!";
            $msg_type = "warning";
        }
    }
}

// --- 2. FILTERS (STATUS, TYPE, EMPLOYEE) ---
$status_filter = $_GET['status'] ?? 'all'; // all, pending, approved, rejected
$status_condition = "";
if ($status_filter == 'pending') {
    $status_condition = "AND la.status = 'Pending'";
} elseif ($status_filter == 'approved') {
    $status_condition = "AND la.status = 'Approved'";
} elseif ($status_filter == 'rejected') {
    $status_condition = "AND la.status = 'Rejected'";
}

$type_filter = $_GET['type'] ?? 'all'; // all, OT_Claim, Permission
$type_condition = "";
if ($type_filter == 'OT_Claim' || $type_filter == 'ot') {
    $type_condition = "AND la.request_type = 'OT_Claim'";
} elseif ($type_filter == 'Permission' || $type_filter == 'permission') {
    $type_condition = "AND la.request_type = 'Permission'";
}

$employee_filter = $_GET['employee'] ?? 'all';
$employee_condition = "";
if ($employee_filter != 'all' && !empty($employee_filter)) {
    $safe_emp = $mysqli->real_escape_string($employee_filter);
    $employee_condition = "AND la.employee_username = '$safe_emp'";
}

// Fetch employee list for filter dropdown
$emp_dropdown_query = "SELECT DISTINCT em.employee_username, em.employee_name 
                      FROM employee_master em 
                      WHERE em.user_role_id IN (2, 3, 9, 10)
                         OR em.employee_username IN (
                             SELECT DISTINCT la.employee_username 
                             FROM leave_applications la 
                             JOIN employee_master em2 ON la.employee_username = em2.employee_username 
                             WHERE em2.user_role_id IN (2, 3, 9, 10) 
                             AND la.request_type IN ('OT_Claim', 'Comp_Off_Leave', 'Permission')
                         )
                      ORDER BY em.employee_name ASC";
$emp_dropdown_result = $mysqli->query($emp_dropdown_query);
$employee_list = [];
if ($emp_dropdown_result && $emp_dropdown_result->num_rows > 0) {
    while ($emp_row = $emp_dropdown_result->fetch_assoc()) {
        $employee_list[] = $emp_row;
    }
}

// Helper query params for tab links
$type_param = ($type_filter != 'all' && !empty($type_filter)) ? '&type=' . urlencode($type_filter) : '';
$emp_param = ($employee_filter != 'all' && !empty($employee_filter)) ? '&employee=' . urlencode($employee_filter) : '';

// --- 3. HANDLE EXCEL DOWNLOAD ---
if (isset($_GET['download']) && $_GET['download'] == 'excel') {
    // Fetch data for Excel
    $excel_query = "SELECT 
                    la.from_date as Date,
                    em.employee_name as 'Employee Name',
                    em.employee_username as 'Employee ID',
                    la.request_type as 'Type',
                    pm.process_name as Process,
                    la.article as Article,
                    la.count as Count,
                    la.start_time as 'Start Time',
                    la.end_time as 'End Time',
                    la.total_hours as 'Total Hours',
                    la.reason as Reason,
                    la.status as Status,
                    la.admin_remarks as Remarks,
                    la.approved_by as 'Approved By',
                    DATE_FORMAT(la.approved_at, '%d-%b-%Y %H:%i') as 'Approved Date'
                   FROM leave_applications la
                   JOIN employee_master em ON la.employee_username = em.employee_username
                   LEFT JOIN process_master pm ON la.process = pm.process_id
                   WHERE em.user_role_id IN (2, 3, 9, 10) 
                   AND la.request_type IN ('OT_Claim', 'Comp_Off_Leave', 'Permission')
                   $status_condition
                   $type_condition
                   $employee_condition
                   ORDER BY la.from_date DESC, la.id DESC";
    
    $excel_result = $mysqli->query($excel_query);
    
    // Generate Excel content without external libraries
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="OT_Claims_TL_Admin_' . date('Y-m-d') . '.xls"');
    
    echo '<table border="1">';
    echo '<tr><th colspan="15" style="background-color:#4CAF50;color:white;font-size:16px;">OT CLAIMS REPORT - TL & ADMINS</th></tr>';
    echo '<tr><th colspan="15">Generated on: ' . date('d-M-Y H:i:s') . ' | Generated by: ' . $superadmin_username . '</th></tr>';
    echo '<tr>';
    
    // Headers
    if ($excel_result && $excel_result->num_rows > 0) {
        $row = $excel_result->fetch_assoc();
        foreach (array_keys($row) as $header) {
            echo '<th style="background-color:#f2f2f2;font-weight:bold;">' . $header . '</th>';
        }
        
        // Reset pointer
        $excel_result->data_seek(0);
        
        // Data rows
        while ($row = $excel_result->fetch_assoc()) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . htmlspecialchars($cell ?? '') . '</td>';
            }
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="15" style="text-align:center;">No data available</td></tr>';
    }
    
    echo '</table>';
    exit();
}

include 'header.php';
?>

<style>
    /* Status Badges */
    .status-Pending { background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
    .status-Approved { background-color: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
    .status-Rejected { background-color: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }
    
    /* Type Badges */
    .badge-ot { background-color: #6f42c1; color: white; }
    .badge-comp { background-color: #198754; color: white; }
    .badge-permission { background-color: #0dcaf0; color: #000; }

    /* Highlight rows */
    .table-hover tbody tr:hover { background-color: rgba(0,123,255,0.1); }
    
    /* Checkbox column */
    .select-checkbox { width: 30px; }
    
    /* Action buttons */
    .btn-approve { background-color: #28a745; color: white; }
    .btn-reject { background-color: #dc3545; color: white; }
    
    /* Disabled action buttons */
    .btn-disabled { 
        background-color: #6c757d; 
        color: white;
        cursor: not-allowed;
        opacity: 0.6;
    }
    
    /* Bulk action panel */
    .bulk-panel {
        background-color: var(--ash, #6c757d) !important;
        color: white;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    /* Team stats cards */
    .stat-card {
        background: white;
        border-radius: 8px;
        padding: 16px 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        margin-bottom: 15px;
        display: flex !important;
        flex-direction: column !important;
        align-items: flex-start !important;
        justify-content: center !important;
    }
    
    .stat-card .stat-number {
        font-size: 24px;
        font-weight: bold;
        color: #2196F3;
        line-height: 1.2;
        margin: 0 0 6px 0 !important;
        display: block;
    }
    
    .stat-card .stat-label {
        font-size: 12px;
        color: #666;
        text-transform: uppercase;
        font-weight: 700;
        letter-spacing: 0.5px;
        margin: 0;
        display: block;
    }
    
    /* Status filter tabs */
    .status-filter .nav-link {
        color: #6c757d;
        border: none;
        border-bottom: 2px solid transparent;
    }
    
    .status-filter .nav-link:hover {
        color: #495057;
    }
    
    .status-filter .nav-link.active {
        color: #0d6efd;
        border-bottom: 2px solid #0d6efd;
        font-weight: bold;
    }
    
    /* Approved row styling */
    .row-approved {
        background-color: #f8fff9;
    }
    
    /* Rejected row styling */
    .row-rejected {
        background-color: #fff8f8;
    }
    
    /* Readonly remarks for non-pending */
    .remarks-readonly {
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 0.25rem;
        padding: 0.375rem 0.75rem;
        font-size: 0.875rem;
        min-height: calc(1.5em + 0.75rem);
        word-break: break-word;
    }

    /* Ash Page Header Banner (Matching Navbar Color) */
    .page-title-container {
        background: var(--ash, #6c757d);
        color: white;
        padding: 12px 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }
    .page-title-container h1, .page-title-container h4 {
        margin: 0;
        font-size: 1.15rem;
        font-weight: 600;
        color: white;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* Sticky Table Header */
    .table-responsive {
        max-height: 70vh !important;
        overflow-y: auto !important;
        overflow-x: auto !important;
        position: relative !important;
        -webkit-overflow-scrolling: touch;
    }
    .table thead th, .table th {
        position: sticky !important;
        top: 0 !important;
        z-index: 100 !important;
        background-color: var(--ash, #6c757d) !important;
        font-weight: 600;
        font-size: 0.82rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        color: white !important;
        padding: 0.75rem 0.5rem;
        border: 1px solid #5a6268 !important;
        box-shadow: 0 2px 4px -1px rgba(0, 0, 0, 0.25) !important;
        background-clip: padding-box !important;
    }
    .table {
        border-collapse: separate !important;
        border-spacing: 0 !important;
    }
    .card-header {
        background-color: var(--ash, #6c757d) !important;
        color: white !important;
    }
</style>

<div class="container-fluid main-content py-4" style="margin-top: 70px;">

    <!-- Ash Page Header Banner (Matching Navbar Color) -->
    <div class="page-title-container">
        <div>
            <h1 class="mb-0">
                <i class="fas fa-check-circle"></i> OT Approvals Management
            </h1>
            <p class="mb-0 small opacity-75">Review and manage OT claims from your team members.</p>
        </div>
        <div class="d-flex gap-2">
            <!-- Excel Download Button -->
            <a href="?download=excel" class="btn btn-success shadow-sm">
                <i class="fa fa-file-excel-o"></i> Download Excel
            </a>
        </div>
    </div>

    <!-- Feedback Message -->
    <?php if(isset($msg)): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show" role="alert">
            <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Team Statistics -->
    <div class="row mb-4">
        <?php
        // Get team statistics
        $stats_query = "SELECT 
                        COUNT(CASE WHEN la.status = 'Pending' THEN 1 END) as pending_count,
                        COUNT(CASE WHEN la.status = 'Approved' THEN 1 END) as approved_count,
                        COUNT(CASE WHEN la.status = 'Rejected' THEN 1 END) as rejected_count,
                        SUM(CASE WHEN la.status = 'Approved' THEN la.total_hours ELSE 0 END) as total_approved_hours,
                        COUNT(DISTINCT la.employee_username) as team_members
                       FROM leave_applications la
                       JOIN employee_master em ON la.employee_username = em.employee_username
                       WHERE em.user_role_id IN (2, 3, 9, 10) 
                       AND la.request_type IN ('OT_Claim', 'Comp_Off_Leave', 'Permission')";
        
        $stats_result = $mysqli->query($stats_query);
        $stats = $stats_result->fetch_assoc();
        ?>
        
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['pending_count'] ?? 0 ?></div>
                <div class="stat-label">Pending Claims</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['approved_count'] ?? 0 ?></div>
                <div class="stat-label">Approved Claims</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['rejected_count'] ?? 0 ?></div>
                <div class="stat-label">Rejected Claims</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-number"><?= round($stats['total_approved_hours'] ?? 0, 1) ?></div>
                <div class="stat-label">Total Approved Hours</div>
            </div>
        </div>
    </div>

    <!-- Status Filter Tabs & Filter Controls -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="status-filter">
                    <ul class="nav nav-tabs border-0 mb-0">
                        <li class="nav-item">
                            <a class="nav-link <?= $status_filter == 'all' ? 'active' : '' ?>" href="?status=all<?= $type_param ?><?= $emp_param ?>">
                                All Claims (<?= ($stats['pending_count'] + $stats['approved_count'] + $stats['rejected_count']) ?? 0 ?>)
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $status_filter == 'pending' ? 'active' : '' ?>" href="?status=pending<?= $type_param ?><?= $emp_param ?>">
                                Pending (<?= $stats['pending_count'] ?? 0 ?>)
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $status_filter == 'approved' ? 'active' : '' ?>" href="?status=approved<?= $type_param ?><?= $emp_param ?>">
                                Approved (<?= $stats['approved_count'] ?? 0 ?>)
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $status_filter == 'rejected' ? 'active' : '' ?>" href="?status=rejected<?= $type_param ?><?= $emp_param ?>">
                                Rejected (<?= $stats['rejected_count'] ?? 0 ?>)
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Filter Controls Form -->
                <form method="GET" action="" class="d-flex flex-wrap align-items-center gap-2 m-0" id="filterForm">
                    <!-- Type Filter -->
                    <div class="d-flex align-items-center gap-1">
                        <label class="small text-muted fw-bold mb-0 text-nowrap"><i class="fas fa-filter text-secondary me-1"></i>Type:</label>
                        <select name="type" class="form-select form-select-sm" style="width: auto; min-width: 125px;" onchange="this.form.submit()">
                            <option value="all" <?= $type_filter == 'all' ? 'selected' : '' ?>>All Types</option>
                            <option value="OT_Claim" <?= ($type_filter == 'OT_Claim' || $type_filter == 'ot') ? 'selected' : '' ?>>OT Claim</option>
                            <option value="Permission" <?= ($type_filter == 'Permission' || $type_filter == 'permission') ? 'selected' : '' ?>>Permission</option>
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div class="d-flex align-items-center gap-1">
                        <label class="small text-muted fw-bold mb-0 text-nowrap">Status:</label>
                        <select name="status" class="form-select form-select-sm" style="width: auto; min-width: 120px;" onchange="this.form.submit()">
                            <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="approved" <?= $status_filter == 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="rejected" <?= $status_filter == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>

                    <!-- Employee Filter -->
                    <div class="d-flex align-items-center gap-1">
                        <label class="small text-muted fw-bold mb-0 text-nowrap">Employee:</label>
                        <select name="employee" class="form-select form-select-sm" style="width: auto; min-width: 160px;" onchange="this.form.submit()">
                            <option value="all">All Employees</option>
                            <?php foreach ($employee_list as $emp): ?>
                                <option value="<?= htmlspecialchars($emp['employee_username']) ?>" <?= $employee_filter == $emp['employee_username'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($emp['employee_name']) ?> (<?= htmlspecialchars($emp['employee_username']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($status_filter != 'all' || ($type_filter != 'all' && !empty($type_filter)) || ($employee_filter != 'all' && !empty($employee_filter))): ?>
                        <a href="<?= basename($_SERVER['PHP_SELF']) ?>" class="btn btn-outline-secondary btn-sm py-1 px-2" title="Reset Filters">
                            <i class="fas fa-undo me-1"></i> Reset
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Action Panel (Only shown for pending claims) -->
    <?php if ($status_filter == 'all' || $status_filter == 'pending'): ?>
    <div class="bulk-panel" id="bulkPanel" style="display: none;">
        <form method="POST" action="" id="bulkForm">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <strong>Bulk Action for <span id="selectedCount">0</span> pending claims:</strong>
                </div>
                <div class="col-md-3">
                    <select name="bulk_action" class="form-select" required>
                        <option value="" selected disabled>-- Select Action --</option>
                        <option value="approve">Approve Selected</option>
                        <option value="reject">Reject Selected</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <input type="text" name="bulk_remarks" class="form-control" placeholder="Remarks for bulk action" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-light w-100">Apply</button>
                    <button type="button" class="btn btn-outline-light w-100 mt-1" onclick="clearSelection()">Cancel</button>
                </div>
            </div>
            <input type="hidden" name="selected_claims[]" id="selectedClaims">
        </form>
    </div>
    <?php endif; ?>

    <!-- OT CLAIMS TABLE -->
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header text-white py-3" style="background-color: var(--ash, #6c757d) !important;">
            <h6 class="mb-0 fw-bold">
                OT Approvals - TL & Admins 
                <span class="float-end">
                    <?php
                    $showing_parts = [ucfirst($status_filter)];
                    if ($type_filter != 'all') {
                        $showing_parts[] = ($type_filter == 'OT_Claim' ? 'OT Claim' : $type_filter);
                    }
                    if ($employee_filter != 'all') {
                        $emp_display_name = $employee_filter;
                        foreach ($employee_list as $emp_item) {
                            if ($emp_item['employee_username'] == $employee_filter) {
                                $emp_display_name = $emp_item['employee_name'];
                                break;
                            }
                        }
                        $showing_parts[] = htmlspecialchars($emp_display_name);
                    }
                    $showing_text = implode(' | ', $showing_parts);
                    ?>
                    <small>Showing: <?= $showing_text ?></small>
                </span>
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="overflow: visible;">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <?php if ($status_filter == 'all' || $status_filter == 'pending'): ?>
                            <th class="select-checkbox">
                                <input type="checkbox" id="selectAll" onclick="toggleAllSelection()">
                            </th>
                            <?php else: ?>
                            <th class="select-checkbox" style="width: 30px;"></th>
                            <?php endif; ?>
                            <th>Date</th>
                            <th>Employee</th>
                            <th>Type</th>
                            <th>Process</th>
                            <th>Article</th>
                            <th>Time & Duration</th>
                            <th>Count</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Remarks</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Fetch OT claims from same team with status, type, and employee filters
                        $query = "SELECT 
                                la.*,
                                em.employee_name,
                                em.process as emp_process,
                                pm.process_name
                               FROM leave_applications la
                               JOIN employee_master em ON la.employee_username = em.employee_username
                               LEFT JOIN process_master pm ON la.process = pm.process_id
                               WHERE em.user_role_id IN (2, 3, 9, 10) 
                               AND la.request_type IN ('OT_Claim', 'Comp_Off_Leave', 'Permission')
                               $status_condition
                               $type_condition
                               $employee_condition
                               ORDER BY la.from_date DESC, la.id DESC";
                        
                        $result = $mysqli->query($query);

                        if ($result && $result->num_rows > 0) {
                            while ($row = $result->fetch_assoc()) {
                                $type_label = str_replace('_', ' ', $row['request_type']);
                                $ot_date = date('d-M-Y', strtotime($row['from_date']));
                                $process_display = !empty($row['process_name']) ? $row['process_name'] : $row['emp_process'];
                                $is_pending = ($row['status'] == 'Pending');
                                $row_class = $is_pending ? '' : ($row['status'] == 'Approved' ? 'row-approved' : 'row-rejected');
                                ?>
                                <tr id="row-<?= $row['id'] ?>" class="<?= $row_class ?>">
    <?php if ($status_filter == 'all' || $status_filter == 'pending'): ?>
    <td class="select-checkbox">
        <?php if ($is_pending): ?>
            <input type="checkbox" class="claim-checkbox" value="<?= $row['id'] ?>" onchange="updateSelection()">
        <?php else: ?>
            <!-- Empty cell for non-pending claims -->
        <?php endif; ?>
    </td>
    <?php else: ?>
    <td class="select-checkbox"></td>
    <?php endif; ?>
    
    <td class="fw-bold"><?= $ot_date ?></td>
    <td>
        <div class="fw-bold"><?= htmlspecialchars((string)($row['employee_name'] ?? '')) ?></div>
        <div class="small text-muted"><?= htmlspecialchars((string)($row['employee_username'] ?? '')) ?></div>
    </td>
    <td>
        <span class="badge <?= $row['request_type'] == 'OT_Claim' ? 'badge-ot' : ($row['request_type'] == 'Permission' ? 'badge-permission' : 'badge-comp') ?>">
            <?= $type_label ?>
        </span>
    </td>
    <td>
        <span class="badge bg-info"><?= htmlspecialchars((string)($process_display ?? '')) ?></span>
    </td>
    <td>
        <?php if (!empty($row['article'])): ?>
            <span class="badge bg-success"><?= htmlspecialchars((string)($row['article'] ?? '')) ?></span>
        <?php else: ?>
            <span class="text-muted">-</span>
        <?php endif; ?>
    </td>
    <td>
        <div class="small">
            <?php if (!empty($row['start_time']) && !empty($row['end_time'])): ?>
                <?= date('g:i A', strtotime($row['start_time'])) ?> to 
                <?= date('g:i A', strtotime($row['end_time'])) ?>
            <?php endif; ?>
        </div>
        <?php if (!empty($row['total_hours'])): ?>
            <span class="badge bg-warning text-dark"><?= $row['total_hours'] ?> hrs</span>
        <?php endif; ?>
    </td>
    <td class="fw-bold"><?= !empty($row['count']) ? number_format($row['count']) : '-' ?></td>
    <td class="small text-secondary" style="max-width: 200px;">
        <?= htmlspecialchars((string)($row['reason'] ?? '')) ?>
    </td>
    <td>
        <span class="badge status-<?= $row['status'] ?>">
            <?= $row['status'] ?>
        </span>
    </td>
    <td>
        <?php if ($is_pending): ?>
            <input type="text" 
                   class="form-control form-control-sm remarks-input" 
                   id="remarks-<?= $row['id'] ?>" 
                   placeholder="Enter remarks">
        <?php else: ?>
            <div class="remarks-readonly small">
                <?= !empty($row['admin_remarks']) ? htmlspecialchars((string)($row['admin_remarks'] ?? '')) : '-' ?>
                <?php if (!empty($row['approved_by'])): ?>
                    <div class="text-muted mt-1">
                        <small>By: <?= $row['approved_by'] ?></small>
                        <?php if (!empty($row['approved_at'])): ?>
                            <br><small><?= date('d-M-y H:i', strtotime($row['approved_at'])) ?></small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </td>
                                    <td>
                                        <?php if ($is_pending): ?>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-approve" onclick="approveClaim(<?= $row['id'] ?>)">
                                                    <i class="fa fa-check"></i> Approve
                                                </button>
                                                <button class="btn btn-reject" onclick="rejectClaim(<?= $row['id'] ?>)">
                                                    <i class="fa fa-times"></i> Reject
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-disabled" disabled>
                                                    <i class="fa fa-check"></i> Approved
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php
                            }
                        } else {
                            $colspan = ($status_filter == 'all' || $status_filter == 'pending') ? 12 : 11;
                            echo '<tr><td colspan="' . $colspan . '" class="text-center py-4 text-muted">No OT claims found.</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Hidden form for single actions -->
<form method="POST" action="" id="actionForm" style="display: none;">
    <input type="hidden" name="ot_id" id="otId">
    <input type="hidden" name="action" id="actionType">
    <input type="hidden" name="admin_remarks" id="actionRemarks">
</form>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Single claim approval/rejection (only for pending claims)
    function approveClaim(otId) {
        const remarks = document.getElementById('remarks-' + otId).value;
        if (confirm('Are you sure you want to approve this OT claim?')) {
            document.getElementById('otId').value = otId;
            document.getElementById('actionType').value = 'approve';
            document.getElementById('actionRemarks').value = remarks;
            document.getElementById('actionForm').submit();
        }
    }
    
    function rejectClaim(otId) {
        const remarks = document.getElementById('remarks-' + otId).value;
        if (!remarks) {
            alert('Please enter remarks for rejection');
            document.getElementById('remarks-' + otId).focus();
            return;
        }
        if (confirm('Are you sure you want to reject this OT claim?')) {
            document.getElementById('otId').value = otId;
            document.getElementById('actionType').value = 'reject';
            document.getElementById('actionRemarks').value = remarks;
            document.getElementById('actionForm').submit();
        }
    }
    
    // Bulk selection functions (only for pending claims)
    let selectedClaims = [];
    
    function updateSelection() {
        selectedClaims = [];
        const checkboxes = document.querySelectorAll('.claim-checkbox:checked');
        
        checkboxes.forEach(checkbox => {
            selectedClaims.push(checkbox.value);
        });
        
        document.getElementById('selectedCount').textContent = selectedClaims.length;
        document.getElementById('selectedClaims').value = selectedClaims.join(',');
        
        // Show/hide bulk panel
        const bulkPanel = document.getElementById('bulkPanel');
        if (bulkPanel && selectedClaims.length > 0) {
            bulkPanel.style.display = 'block';
        } else if (bulkPanel) {
            bulkPanel.style.display = 'none';
        }
        
        // Update select all checkbox
        const allCheckboxes = document.querySelectorAll('.claim-checkbox');
        const selectAll = document.getElementById('selectAll');
        if (selectAll) {
            selectAll.checked = (selectedClaims.length === allCheckboxes.length);
        }
    }
    
    function toggleAllSelection() {
        const selectAll = document.getElementById('selectAll');
        if (!selectAll) return;
        
        const checkboxes = document.querySelectorAll('.claim-checkbox');
        
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
        });
        
        updateSelection();
    }
    
    function clearSelection() {
        const checkboxes = document.querySelectorAll('.claim-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        
        const selectAll = document.getElementById('selectAll');
        if (selectAll) {
            selectAll.checked = false;
        }
        
        selectedClaims = [];
        const bulkPanel = document.getElementById('bulkPanel');
        if (bulkPanel) {
            bulkPanel.style.display = 'none';
        }
        
        // Reset bulk form
        const bulkForm = document.getElementById('bulkForm');
        if (bulkForm) {
            bulkForm.reset();
        }
    }
    
    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        // Set today's date max for filters if any
        const today = new Date().toISOString().split('T')[0];
        
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    });
</script>