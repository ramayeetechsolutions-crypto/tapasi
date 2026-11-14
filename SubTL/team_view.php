<?php 
// =========================================================================
// FILE: SubTL/team_view.php
// PURPOSE: Sub-TL Team Master & Hierarchy Organizational Roster
// 100% Preserved Functionality • Senior Developer Modern UI with In-Page Modals
// =========================================================================

require_once 'db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------- Helper functions ----------
if (!function_exists('h')) {
    function h($s) { 
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
    }
}

// ---------- Handle POST Actions (Add / Edit Team Member in Modal) ----------
if (($_SERVER["REQUEST_METHOD"] ?? '') === "POST") {
    $emp_id = (int)($_POST['employee_id'] ?? 0);
    $tl_name = trim($_POST['reporting_manager'] ?? '');
    $process_id = trim($_POST['process_id'] ?? '');

    if ($emp_id > 0 && !empty($tl_name)) {
        $stmt = $mysqli->prepare("UPDATE employee_master SET reporting_manager = ?, process = ? WHERE employee_id = ?");
        if ($stmt) {
            $stmt->bind_param("ssi", $tl_name, $process_id, $emp_id);
            if ($stmt->execute()) {
                header("Location: team_view.php?msg=" . urlencode("Member assigned successfully.") . "&type=success");
                exit;
            } else {
                header("Location: team_view.php?msg=" . urlencode("Database error: " . $stmt->error) . "&type=danger");
                exit;
            }
            $stmt->close();
        }
    } else {
        header("Location: team_view.php?msg=" . urlencode("Please select both an employee and a valid team leader.") . "&type=warning");
        exit;
    }
}

$ename = $mysqli->real_escape_string($_SESSION["ename"] ?? ($_SESSION["username"] ?? ''));
$sess_user = $mysqli->real_escape_string($_SESSION["username"] ?? '');
$sess_uid = (int)($_SESSION['uid'] ?? 0);

// 1. Fetch ALL processes to create a mapping dictionary (ID => Name)
$pm_sql = "SELECT process_id, process_name FROM process_master";
$pm_result = $mysqli->query($pm_sql);
$process_map = [];

if ($pm_result) {
    while($row = $pm_result->fetch_assoc()) {
        if (!empty($row['process_name'])) {
            $process_map[$row['process_id']] = trim($row['process_name']);
            $process_map[$row['process_name']] = trim($row['process_name']);
        }
    }
}
$json_process_map = json_encode($process_map);

// 2. Main Query for Teams & Executives (Strictly UQ Team Members)
$sql = "
    SELECT DISTINCT
        tl.employee_name AS team_leader,
        tl.employee_username AS team_leader_username,
        tl.employee_username AS team_leader_id,
        COALESCE(
            GROUP_CONCAT(
                DISTINCT CONCAT(
                    COALESCE(e.employee_name, 'Unknown'), '::', 
                    COALESCE(e.employee_username, 'N/A'), '::', 
                    COALESCE(e.process, ''), '::',
                    COALESCE(e.process1, ''), '::',
                    COALESCE(e.employee_id, '')
                ) 
                ORDER BY e.employee_username ASC
                SEPARATOR '|||'
            ), ''
        ) AS executives,
        COUNT(DISTINCT e.employee_id) AS team_size
    FROM employee_master tl
    LEFT JOIN employee_master e 
        ON e.employee_id != tl.employee_id
    LEFT JOIN team_master t 
        ON e.team_id = t.team_id
    WHERE (
        tl.employee_id = $sess_uid
        OR tl.employee_username = '$sess_user' 
        OR CAST(tl.employee_name AS CHAR) COLLATE utf8mb4_unicode_ci LIKE '%$ename%'
    )
    AND (
        e.employee_id IS NULL 
        OR (
            (e.user_role_id = 4 OR e.user_role_id = 11)
            AND (e.status = 'Active' OR e.status IS NULL)
            AND (
                t.team_name LIKE '%UQ%' 
                OR e.team_id = 2
                OR e.process LIKE '%UQ%' 
                OR EXISTS (
                    SELECT 1 FROM process_master pm 
                    WHERE FIND_IN_SET(pm.process_id, e.process) 
                    AND pm.process_name LIKE '%UQ%'
                )
                OR CAST(e.reporting_manager AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(tl.employee_name AS CHAR) COLLATE utf8mb4_unicode_ci
                OR CAST(e.reporting_manager AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(tl.employee_username AS CHAR) COLLATE utf8mb4_unicode_ci
            )
        )
    )
    GROUP BY tl.employee_username, tl.employee_name
    ORDER BY tl.employee_name;
";

$result = $mysqli->query($sql);

if (!$result) {
    die("Query failed: " . $mysqli->error);
}

$data = [];
$total_teams = 0;
$total_members = 0;

while($row = $result->fetch_assoc()){
    $data[] = $row;
    $total_teams++;
    $total_members += (int)$row['team_size'];
}

$avg_team_size = $total_teams > 0 ? round($total_members / $total_teams, 1) : 0;
$json_data = json_encode($data);

// 3. Fetch Active Executives for Add/Edit Modals
$emp_query = "SELECT employee_id, employee_username, employee_name FROM employee_master WHERE user_role_id = 4 AND status='Active' ORDER BY employee_username ASC";
$emp_res = $mysqli->query($emp_query);
$modal_executives = [];
$emp_id_map = [];
if ($emp_res) {
    while($row = $emp_res->fetch_assoc()) {
        $modal_executives[] = $row;
        $emp_id_map[$row['employee_username']] = $row['employee_id'];
    }
}
$json_emp_id_map = json_encode($emp_id_map);

// 4. Fetch Processes for Modals
$proc_query = "SELECT process_id, process_name FROM process_master ORDER BY process_name ASC";
$proc_res = $mysqli->query($proc_query);
$modal_processes = [];
if ($proc_res) {
    while($row = $proc_res->fetch_assoc()) {
        if (!empty($row['process_name'])) {
            $modal_processes[$row['process_name']] = trim($row['process_name']);
        }
    }
}
$custom_processes = [
    "AP", "CE", "FQA", "LE", "PAG", "QC", "REF", "TAB", 
    "Sub-TL", "TAB/IMG", "PRE-PAG", "QC/PAG", "AP_QC", 
    "AP_CRX", "AP-QC", "CE-REF", "REF/Table"
];
foreach ($custom_processes as $cp) {
    $modal_processes[$cp] = $cp;
}

$pageTitle = "Sub-TL Team Master";
$currentPage = "team_view.php";
require_once 'header.php';
?>

<!-- DataTables CSS & Dependencies -->
<link rel="stylesheet" href="https://cdn.datatables.net/v/bs5/dt-1.13.6/datatables.min.css">

<style>
/* =========================================================================
   SENIOR DEVELOPER STYLING - SUB-TL TEAM MASTER & ROSTER
   ========================================================================= */
.erp-team-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.03);
    padding: 24px;
    margin-bottom: 30px;
}

/* Header & Icon */
.header-icon-box {
    width: 44px;
    height: 44px;
    background: linear-gradient(135deg, #e0f2fe 0%, #dbeafe 100%);
    border: 1px solid #bfdbfe;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #0284c7;
    font-size: 1.25rem;
    box-shadow: 0 2px 5px rgba(2, 132, 199, 0.12);
}

/* KPI Chips */
.erp-stat-chip {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px 16px;
    display: inline-flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.02);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.erp-stat-chip:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
.erp-stat-icon {
    width: 38px;
    height: 38px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}

/* Table Header - Dark Slate #5a6a7a */
#teamTable thead th {
    background-color: #5a6a7a !important;
    color: #ffffff !important;
    font-weight: 700;
    font-size: 0.82rem;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    border-bottom: 2px solid #485563 !important;
    padding: 13px 16px;
    vertical-align: middle;
}

#teamTable tbody tr {
    transition: background-color 0.2s ease;
}
#teamTable tbody tr:hover {
    background-color: #f8fafc;
}

/* Expand Control Button */
.expand-toggle-btn {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background-color: #f1f5f9;
    color: #0284c7;
    border: 1px solid #cbd5e1;
    cursor: pointer;
    transition: all 0.25s ease;
    font-size: 0.82rem;
}
.expand-toggle-btn:hover {
    background-color: #0284c7;
    color: #ffffff;
    border-color: #0284c7;
    box-shadow: 0 2px 6px rgba(2, 132, 199, 0.25);
}
tr.shown .expand-toggle-btn {
    background-color: #ef4444;
    color: #ffffff;
    border-color: #ef4444;
    transform: rotate(90deg);
}

/* Team Leader Avatar Box */
.leader-avatar-box {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
    border: 1px solid #7dd3fc;
    color: #0369a1;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    box-shadow: 0 2px 4px rgba(3, 105, 161, 0.1);
}

/* Team Size Badge */
.team-size-badge {
    font-size: 0.82rem;
    font-weight: 700;
    padding: 5px 12px;
    border-radius: 20px;
    letter-spacing: 0.3px;
}

/* Child Row Card Styling */
.executives-detail-container {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #0284c7;
    border-radius: 10px;
    padding: 22px;
    margin: 8px 0;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);
}

/* Inner Table */
.inner-roster-table {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 0;
}
.inner-roster-table thead th {
    background-color: #f1f5f9 !important;
    color: #475569 !important;
    font-weight: 700;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e2e8f0 !important;
    padding: 10px 14px;
}
.inner-roster-table tbody td {
    padding: 11px 14px;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.85rem;
}
.inner-roster-table tbody tr:hover {
    background-color: #fafbfc;
}

/* Member ID Badge */
.emp-code-badge {
    background-color: #f1f5f9;
    color: #334155;
    border: 1px solid #cbd5e1;
    padding: 3px 8px;
    border-radius: 5px;
    font-weight: 700;
    font-size: 0.78rem;
    letter-spacing: 0.3px;
}

/* Process Pills */
.process-pill {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 0.74rem;
    font-weight: 600;
    background-color: #e0f2fe;
    color: #0369a1;
    border: 1px solid #bae6fd;
    margin: 2px 4px 2px 0;
}

/* Empty State */
.empty-team-state {
    text-align: center;
    padding: 35px 20px;
    background: #ffffff;
    border: 2px dashed #cbd5e1;
    border-radius: 10px;
}

/* DataTables Controls Modern Styling */
.dataTables_wrapper .dataTables_length select {
    padding: 5px 28px 5px 10px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    font-size: 0.85rem;
}
.dataTables_wrapper .dataTables_filter input {
    padding: 6px 12px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    font-size: 0.85rem;
    margin-left: 8px;
}
.dataTables_wrapper .dataTables_info {
    font-size: 0.85rem;
    color: #64748b;
    padding-top: 10px;
}
.dataTables_wrapper .dataTables_paginate {
    padding-top: 8px;
}
</style>

<main class="main-content" style="padding-top: 85px; padding-bottom: 50px;">
    <div class="container-fluid px-lg-4 px-md-3 px-2">

        <!-- FLOATING ERP TOAST NOTIFICATION -->
        <?php if (!empty($_GET['msg'])): 
            $toastMsg  = h($_GET['msg']);
            $toastType = strtolower(trim($_GET['type'] ?? 'info'));
            
            $toastTitle = 'Notification';
            $toastIcon  = 'fa-info-circle';
            $toastBadge = 'Notice';
            $toastClass = 'erp-toast-info';
            
            if (stripos($toastMsg, 'delete') !== false || stripos($toastMsg, 'remove') !== false || $toastType === 'danger' || $toastType === 'warning') {
                $toastTitle = 'Record Deleted';
                $toastIcon  = 'fa-trash-alt';
                $toastBadge = 'Deleted';
                $toastClass = 'erp-toast-danger';
            } elseif (stripos($toastMsg, 'add') !== false || stripos($toastMsg, 'assign') !== false || stripos($toastMsg, 'create') !== false || $toastType === 'success') {
                $toastTitle = 'Member Assigned';
                $toastIcon  = 'fa-check-circle';
                $toastBadge = 'Success';
                $toastClass = 'erp-toast-success';
            } elseif (stripos($toastMsg, 'update') !== false || stripos($toastMsg, 'modified') !== false) {
                $toastTitle = 'Updated Successfully';
                $toastIcon  = 'fa-pen-to-square';
                $toastBadge = 'Updated';
                $toastClass = 'erp-toast-info';
            }
        ?>
        <div class="erp-toast-wrapper" id="erpToastWrapper" role="region" aria-label="Notifications">
            <div class="erp-toast-card <?= $toastClass ?>" id="erpToastCard" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="erp-toast-icon-box">
                    <i class="fas <?= $toastIcon ?>"></i>
                </div>
                <div class="erp-toast-content">
                    <div class="erp-toast-header">
                        <span class="erp-toast-badge"><?= $toastBadge ?></span>
                        <strong class="erp-toast-title"><?= $toastTitle ?></strong>
                        <span class="erp-toast-time">Just now</span>
                    </div>
                    <div class="erp-toast-body">
                        <?= $toastMsg ?>
                    </div>
                </div>
                <button type="button" class="erp-toast-close" onclick="dismissErpToast()" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
                <div class="erp-toast-progress-bar">
                    <div class="erp-toast-progress-fill"></div>
                </div>
            </div>
        </div>

        <script>
        function dismissErpToast() {
            const toast = document.getElementById('erpToastCard');
            if (!toast) return;
            toast.classList.add('erp-toast-hiding');
            setTimeout(() => {
                const wrapper = document.getElementById('erpToastWrapper');
                if (wrapper) wrapper.remove();
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('msg');
                    url.searchParams.delete('type');
                    window.history.replaceState({}, document.title, url.toString());
                } catch(e) {}
            }, 300);
        }

        document.addEventListener('DOMContentLoaded', function() {
            const toast = document.getElementById('erpToastCard');
            if (toast) {
                setTimeout(dismissErpToast, 4500);
            }
        });
        </script>
        <?php endif; ?>

        <!-- PAGE HEADER & BREADCRUMB -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 erp-page-header">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1" style="font-size: 0.78rem;">
                        <li class="breadcrumb-item"><a href="dashboard_subtl.php" class="text-decoration-none text-muted"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                        <li class="breadcrumb-item text-muted">Master</li>
                        <li class="breadcrumb-item active text-primary fw-semibold" aria-current="page">Sub-TL Team Master</li>
                    </ol>
                </nav>
                <div class="d-flex align-items-center gap-3">
                    <div class="header-icon-box">
                        <i class="fas fa-sitemap"></i>
                    </div>
                    <div>
                        <h3 class="mb-0 fw-bold" style="color: #1e293b; font-size: 1.45rem;">
                            Sub-TL Team Master
                        </h3>
                        <p class="text-muted mb-0" style="font-size: 0.85rem; margin-top: 2px;">
                            View organizational structure, team hierarchy, and assigned UQ processes.
                        </p>
                    </div>
                </div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <!-- IN-PAGE MODAL TRIGGER (NO PAGE REDIRECTION) -->
                <button type="button" class="btn btn-sm btn-primary px-3 shadow-sm d-flex align-items-center gap-2 erp-btn-add" data-bs-toggle="modal" data-bs-target="#addTeamMemberModal" style="font-weight: 600; border-radius: 8px;">
                    <i class="fas fa-user-plus"></i> <span>Add Team Member</span>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary px-3 d-flex align-items-center gap-2 btn-refresh" style="border-radius: 8px;">
                    <i class="fas fa-sync-alt"></i> <span>Refresh</span>
                </button>
            </div>
        </div>

        <!-- KPI SUMMARY CARDS -->
        <div class="row g-3 mb-4">
            <div class="col-md-4 col-sm-6 col-12">
                <div class="erp-stat-chip w-100">
                    <div class="erp-stat-icon bg-primary-subtle text-primary">
                        <i class="fas fa-sitemap"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Total Teams</div>
                        <div class="fs-4 fw-bold text-dark"><?= number_format($total_teams) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 col-12">
                <div class="erp-stat-chip w-100">
                    <div class="erp-stat-icon bg-success-subtle text-success">
                        <i class="fas fa-users"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Total Members</div>
                        <div class="fs-4 fw-bold text-success"><?= number_format($total_members) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 col-12">
                <div class="erp-stat-chip w-100">
                    <div class="erp-stat-icon bg-info-subtle text-info">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Avg Team Size</div>
                        <div class="fs-4 fw-bold text-dark"><?= $avg_team_size ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- MAIN DATA CARD & TABLE -->
        <div class="erp-team-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-light text-secondary border px-2 py-1" style="font-size: 0.75rem;">
                        <i class="fas fa-info-circle text-primary me-1"></i> Interactive Hierarchy
                    </span>
                    <span class="text-muted small">Click the <i class="fas fa-chevron-right text-primary mx-1"></i> toggle on any team to view roster & assigned processes</span>
                </div>
            </div>

            <?php if (empty($data)): ?>
                <div class="empty-team-state my-4">
                    <div class="leader-avatar-box mx-auto mb-3" style="width: 56px; height: 56px; font-size: 1.5rem;">
                        <i class="fas fa-users-slash text-muted"></i>
                    </div>
                    <h5 class="fw-bold text-dark">No Team Hierarchy Found</h5>
                    <p class="text-muted small mb-3">There are currently no executives mapped under your reporting profile.</p>
                    <button type="button" class="btn btn-primary btn-sm px-3" data-bs-toggle="modal" data-bs-target="#addTeamMemberModal">
                        <i class="fas fa-user-plus me-1"></i> Assign First Member
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table id="teamTable" class="table table-hover align-middle w-100 mb-0">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 50px;"></th> 
                                <th>Team Leader</th>
                                <th>Team Size</th>
                                <th>Team Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Populated dynamically by DataTables -->
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>
</main>

<!-- =========================================================================
     IN-PAGE MODALS: ADD & EDIT TEAM MEMBERS (NO PAGE REDIRECTIONS)
     Strict UI Compliance: Zero Submit Buttons in Modal Header!
     ========================================================================= -->

<!-- 1. ADD TEAM MEMBER MODAL -->
<div class="modal fade" id="addTeamMemberModal" tabindex="-1" aria-labelledby="addTeamMemberModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2" id="addTeamMemberModalLabel">
                    <i class="fas fa-user-plus text-primary"></i>
                    <span>Assign Team Member</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form id="addTeamMemberForm" method="POST" action="team_view.php">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary small">Team Leader Name</label>
                        <input type="text" class="form-control bg-light" name="reporting_manager" id="add_reporting_manager" value="<?= h($ename) ?>" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary small">Select Executive Member <span class="text-danger">*</span></label>
                        <select name="employee_id" id="add_employee_id" class="form-select" required>
                            <option value="">-- Choose employee by ID or Name --</option>
                            <?php foreach($modal_executives as $me): ?>
                                <option value="<?= h($me['employee_id']) ?>">
                                    <?= h($me['employee_username'] . " - " . $me['employee_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary small">Assign Process / Workflow <span class="text-danger">*</span></label>
                        <select name="process_id" id="add_process_id" class="form-select" required>
                            <option value="">-- Select process --</option>
                            <?php foreach($modal_processes as $pName): ?>
                                <option value="<?= h($pName) ?>"><?= h($pName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top bg-light">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                <button type="submit" form="addTeamMemberForm" class="btn btn-primary px-4 fw-semibold" style="border-radius: 8px;">
                    <i class="fas fa-save me-1"></i> Assign Member
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 2. EDIT TEAM MEMBER MODAL -->
<div class="modal fade" id="editTeamMemberModal" tabindex="-1" aria-labelledby="editTeamMemberModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2" id="editTeamMemberModalLabel">
                    <i class="fas fa-user-edit text-warning"></i>
                    <span>Edit Member Assignment</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form id="editTeamMemberForm" method="POST" action="team_view.php">
                    <input type="hidden" name="employee_id" id="edit_employee_id" value="">

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary small">Executive Member</label>
                        <input type="text" class="form-control bg-light" id="edit_employee_display" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary small">Reporting Team Leader</label>
                        <input type="text" class="form-control bg-light" name="reporting_manager" id="edit_reporting_manager" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary small">Assigned Process / Workflow <span class="text-danger">*</span></label>
                        <select name="process_id" id="edit_process_id" class="form-select" required>
                            <option value="">-- Select process --</option>
                            <?php foreach($modal_processes as $pName): ?>
                                <option value="<?= h($pName) ?>"><?= h($pName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top bg-light">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                <button type="submit" form="editTeamMemberForm" class="btn btn-primary px-4 fw-semibold" style="border-radius: 8px;">
                    <i class="fas fa-save me-1"></i> Update Assignment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-1.13.6/datatables.min.js"></script>
<script src="../pop_confirm.js"></script>

<script>
var teamData = <?= $json_data ?>;
var processMap = <?= $json_process_map ?>; // Dictionary: ID -> Process Name
var empIdMap = <?= $json_emp_id_map ?>;
var defaultTL = <?= json_encode($ename) ?>;

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function getMappedProcesses(proc1, proc2) {
    var rawList = [];
    if (proc1) rawList = rawList.concat(proc1.toString().split(','));
    if (proc2) rawList = rawList.concat(proc2.toString().split(','));
    
    var namesList = [];
    rawList.forEach(function(item) {
        var p = item.trim();
        if (p !== '' && p !== '0') {
            namesList.push(processMap[p] ? processMap[p] : p);
        }
    });
    return [...new Set(namesList)];
}

function formatTeamDetails(d) {
    var listHtml = '<div class="executives-detail-container">';
    
    if (d.executives && d.executives.trim() !== '') {
        var executivesArray = d.executives.split('|||').filter(function(item) {
            return item.trim() !== '';
        });
        
        var teamSize = executivesArray.length;
        
        if (teamSize > 0) {
            listHtml += '<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom">' +
                '<div class="d-flex align-items-center gap-3">' +
                    '<div class="leader-avatar-box">' +
                        '<i class="fas fa-user-tie"></i>' +
                    '</div>' +
                    '<div>' +
                        '<h6 class="fw-bold mb-1 text-dark" style="font-size: 1.05rem;">' + escapeHtml(d.team_leader || 'Unknown Leader') + '</h6>' +
                        '<div class="d-flex align-items-center gap-2 flex-wrap">' +
                            '<span class="badge bg-light text-primary border" style="font-size: 0.75rem;">TL ID: ' + escapeHtml(d.team_leader_username || d.team_leader_id || 'N/A') + '</span>' +
                            '<span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.75rem;">' + teamSize + ' Active Members</span>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="mt-2 mt-md-0">' +
                    '<button type="button" class="btn btn-sm btn-primary px-3 shadow-xs d-inline-flex align-items-center gap-2 btn-open-add-member" data-tl-name="' + escapeHtml(d.team_leader) + '" style="border-radius: 7px; font-weight: 600;">' +
                        '<i class="fas fa-user-plus"></i><span>Add Team Member</span>' +
                    '</button>' +
                '</div>' +
            '</div>';
            
            listHtml += '<h6 class="fw-bold mb-3 text-secondary" style="font-size: 0.88rem;">' +
                '<i class="fas fa-users-cog text-primary me-2"></i>Assigned Team Executives & Workflows:' +
            '</h6>';
            
            listHtml += '<div class="table-responsive rounded border">' +
                '<table class="table inner-roster-table w-100">' +
                    '<thead>' +
                        '<tr>' +
                            '<th class="text-center" style="width: 55px;">S.No</th>' +
                            '<th style="width: 140px;">Employee ID</th>' +
                            '<th style="min-width: 200px;">Executive Name</th>' +
                            '<th>Assigned Processes</th>' +
                            '<th class="text-end pe-3" style="width: 110px;">Actions</th>' +
                        '</tr>' +
                    '</thead>' +
                    '<tbody>';
            
            executivesArray.forEach(function(executive, index) {
                var execParts = executive.split('::');
                var execName = execParts[0] ? execParts[0].trim() : 'Unknown';
                var execUsername = execParts[1] ? execParts[1].trim() : (execParts[4] || 'N/A');
                var proc1 = execParts[2] || '';
                var proc2 = execParts[3] || '';
                var execId = execParts[4] ? execParts[4].trim() : (empIdMap[execUsername] || '');
                
                var mappedProcs = getMappedProcesses(proc1, proc2);
                var processBadges = '<span class="text-muted small fst-italic">No process assigned</span>';
                if (mappedProcs.length > 0) {
                    processBadges = mappedProcs.map(function(p) {
                        return '<span class="process-pill"><i class="fas fa-cog me-1" style="font-size: 9px;"></i>' + escapeHtml(p) + '</span>';
                    }).join('');
                }
                
                listHtml += '<tr>' +
                    '<td class="text-center fw-bold text-muted" style="font-size: 0.82rem;">' + (index + 1) + '</td>' +
                    '<td><span class="emp-code-badge">' + escapeHtml(execUsername) + '</span></td>' +
                    '<td>' +
                        '<div class="d-flex align-items-center gap-2">' +
                            '<div class="rounded-circle bg-light d-flex align-items-center justify-content-center text-primary" style="width: 28px; height: 28px; font-size: 0.75rem; border: 1px solid #e2e8f0;">' +
                                '<i class="fas fa-user"></i>' +
                            '</div>' +
                            '<strong class="text-dark">' + escapeHtml(execName) + '</strong>' +
                        '</div>' +
                    '</td>' +
                    '<td>' + processBadges + '</td>' +
                    '<td class="text-end pe-3">' +
                        '<button type="button" class="btn btn-sm btn-outline-warning text-dark py-1 px-2 d-inline-flex align-items-center gap-1 btn-open-edit-member" ' +
                            'data-emp-id="' + escapeHtml(execId) + '" ' +
                            'data-emp-username="' + escapeHtml(execUsername) + '" ' +
                            'data-emp-name="' + escapeHtml(execName) + '" ' +
                            'data-tl-name="' + escapeHtml(d.team_leader) + '" ' +
                            'data-process="' + escapeHtml(proc1) + '" ' +
                            'style="font-size: 0.78rem; font-weight: 600; border-radius: 6px;">' +
                            '<i class="fas fa-pencil-alt text-warning"></i><span>Edit</span>' +
                        '</button>' +
                    '</td>' +
                '</tr>';
            });
            
            listHtml += '</tbody></table></div>';
            
            listHtml += '<div class="d-flex flex-wrap justify-content-between align-items-center mt-3 pt-2 text-muted" style="font-size: 0.78rem;">' +
                '<div><i class="fas fa-clock me-1"></i> Roster synced in real-time from active employee directory</div>' +
                '<span class="badge bg-secondary-subtle text-secondary border">Total ' + teamSize + ' Executive Members</span>' +
            '</div>';
        } else {
            listHtml += getEmptyRosterHtml(d.team_leader);
        }
    } else {
        listHtml += getEmptyRosterHtml(d.team_leader);
    }
    
    listHtml += '</div>';
    return listHtml;
}

function getEmptyRosterHtml(tlName) {
    return '<div class="empty-team-state my-2">' +
        '<i class="fas fa-user-plus text-primary fs-3 mb-2"></i>' +
        '<h6 class="fw-bold mb-1">No Members Assigned to this Team</h6>' +
        '<p class="text-muted small mb-3">Add executives to start building and tracking this team\'s workflow.</p>' +
        '<button type="button" class="btn btn-primary btn-sm px-3 btn-open-add-member" data-tl-name="' + escapeHtml(tlName || '') + '">' +
            '<i class="fas fa-user-plus me-1"></i> Assign Member' +
        '</button>' +
    '</div>';
}

$(document).ready(function() {
    if (teamData.length === 0) return;

    var table = $('#teamTable').DataTable({
        data: teamData,
        columns: [
            {
                className: 'text-center',
                orderable: false,
                data: null,
                width: '50px',
                render: function(data, type, row) {
                    return '<button type="button" class="expand-toggle-btn" title="Expand team roster">' +
                        '<i class="fas fa-chevron-right"></i>' +
                    '</button>';
                }
            },
            { 
                data: 'team_leader',
                render: function(data, type, row) {
                    if (!data) return '<span class="text-muted">Unknown</span>';
                    var leaderId = row.team_leader_username || row.team_leader_id || 'N/A';
                    return '<div class="d-flex align-items-center">' +
                        '<div class="leader-avatar-box me-3"><i class="fas fa-user-tie"></i></div>' +
                        '<div>' +
                            '<strong class="d-block text-dark fw-bold" style="font-size: 0.95rem;">' + escapeHtml(data) + '</strong>' +
                            '<span class="badge bg-light text-muted border px-2 py-0" style="font-size: 0.74rem;">ID: ' + escapeHtml(leaderId) + '</span>' +
                        '</div>' +
                    '</div>';
                }
            },
            { 
                data: 'team_size',
                render: function(data, type, row) {
                    var teamSize = parseInt(data) || 0;
                    var badgeClass = 'bg-secondary';
                    if (teamSize > 7) {
                        badgeClass = 'bg-success';
                    } else if (teamSize > 3) {
                        badgeClass = 'bg-primary';
                    } else if (teamSize > 0) {
                        badgeClass = 'bg-info';
                    }
                    return '<div class="d-flex align-items-center gap-2">' +
                        '<span class="badge ' + badgeClass + ' team-size-badge">' + teamSize + ' member' + (teamSize !== 1 ? 's' : '') + '</span>' +
                        '<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1" style="font-size: 0.72rem;">' +
                            '<i class="fas fa-circle text-success me-1" style="font-size: 6px;"></i>Active' +
                        '</span>' +
                    '</div>';
                }
            },
            { 
                data: 'executives',
                orderable: false,
                render: function(data, type, row) {
                    if (!data || data.trim() === '') {
                        return '<span class="text-muted small fst-italic"><i class="fas fa-info-circle me-1"></i>No members assigned</span>';
                    }
                    
                    var executivesArray = data.split('|||').filter(item => item.trim() !== '');
                    var teamSize = executivesArray.length;
                    
                    if (teamSize > 0) {
                        var sampleNames = [];
                        executivesArray.slice(0, 3).forEach(function(exec) {
                            var parts = exec.split('::');
                            if (parts[0]) sampleNames.push(parts[0].trim().split(' ')[0]);
                        });
                        
                        var snippet = sampleNames.join(', ');
                        if (teamSize > 3) {
                            snippet += ' <span class="badge bg-light text-muted border">+' + (teamSize - 3) + ' more</span>';
                        }
                        
                        return '<div class="d-flex flex-column gap-1">' +
                            '<div class="text-primary small fw-semibold d-inline-flex align-items-center gap-1 cursor-pointer">' +
                                '<i class="fas fa-eye text-primary"></i> <span>Click to expand roster</span>' +
                            '</div>' +
                            '<div class="text-muted small" style="font-size: 0.78rem;">' +
                                'Includes: ' + snippet +
                            '</div>' +
                        '</div>';
                    }
                    return '<span class="text-muted small">No details</span>';
                }
            }
        ],
        order: [[1, 'asc']],
        responsive: true,
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        language: {
            emptyTable: "No team hierarchy data available",
            zeroRecords: "No matching teams found",
            info: "Showing _START_ to _END_ of _TOTAL_ teams",
            infoEmpty: "Showing 0 to 0 of 0 teams",
            infoFiltered: "(filtered from _MAX_ total teams)",
            lengthMenu: "Show _MENU_ teams",
            search: "Search teams:",
            searchPlaceholder: "Type to search...",
            paginate: {
                first: '<i class="fas fa-angle-double-left"></i>',
                last: '<i class="fas fa-angle-double-right"></i>',
                next: '<i class="fas fa-angle-right"></i>',
                previous: '<i class="fas fa-angle-left"></i>'
            }
        },
        initComplete: function() {
            var savedTL = localStorage.getItem('expandedTeamSubTL');
            if (savedTL) {
                setTimeout(function() {
                    table.rows().every(function() {
                        var rData = this.data();
                        if (rData && (rData.team_leader === savedTL || rData.team_leader_username === savedTL)) {
                            var tr = $(this.node());
                            tr.find('.expand-toggle-btn').trigger('click');
                        }
                    });
                }, 150);
            }
        }
    });

    $('#teamTable tbody').on('click', 'button.expand-toggle-btn, td:nth-child(4) .cursor-pointer', function (e) {
        e.stopPropagation();
        var tr = $(this).closest('tr');
        var row = table.row(tr);
        var btn = tr.find('.expand-toggle-btn');
        var rowData = row.data();
        
        if (row.child.isShown()) {
            row.child.hide();
            tr.removeClass('shown');
            btn.html('<i class="fas fa-chevron-right"></i>');
            localStorage.removeItem('expandedTeamSubTL');
        } else {
            row.child(formatTeamDetails(rowData)).show();
            tr.addClass('shown');
            btn.html('<i class="fas fa-chevron-down"></i>');
            if (rowData && rowData.team_leader) {
                localStorage.setItem('expandedTeamSubTL', rowData.team_leader);
            }
        }
    });

    $(document).on('click', '.btn-open-add-member', function(e) {
        e.preventDefault();
        var tl = $(this).data('tl-name') || defaultTL;
        $('#add_reporting_manager').val(tl);
        $('#add_employee_id').val('');
        $('#add_process_id').val('');
        var modalEl = document.getElementById('addTeamMemberModal');
        var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
        modal.show();
    });

    $(document).on('click', '.btn-open-edit-member', function(e) {
        e.preventDefault();
        var empId = $(this).data('emp-id');
        var empUsername = $(this).data('emp-username');
        var empName = $(this).data('emp-name');
        var tlName = $(this).data('tl-name');
        var process = $(this).data('process') || '';

        if (!empId && empUsername && empIdMap[empUsername]) {
            empId = empIdMap[empUsername];
        }

        $('#edit_employee_id').val(empId);
        $('#edit_employee_display').val(empUsername + ' - ' + empName);
        $('#edit_reporting_manager').val(tlName);
        $('#edit_process_id').val(process);

        var modalEl = document.getElementById('editTeamMemberModal');
        var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
        modal.show();
    });

    $('.btn-refresh').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Refreshing...');
        setTimeout(function() {
            location.reload();
        }, 500);
    });
});
</script>
