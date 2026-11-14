<?php
// =========================================================================
// FILE: employee_master-old.php
// PURPOSE: Senior Developer Employee & Compensation Master
// 100% Preserved Functionality • Upgraded Modern UI
// =========================================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}
$emp_username = $_SESSION["ename"] ?? ($_SESSION["username"] ?? '');

// ---------- Helpers ----------
if (!function_exists('h')) {
    function h($s) { 
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
    }
}

if (!function_exists('redirect_with')) {
    function redirect_with($params = []) {
        $base = 'employee_master-old.php';
        if (!empty($params)) $base .= '?' . http_build_query($params);
        header("Location: $base"); 
        exit;
    }
}

if (!function_exists('is_valid_date')) {
    function is_valid_date($d) {
        if (!$d) return false;
        $t = strtotime($d);
        if ($t === false) return false;
        return date('Y-m-d', $t) === $d;
    }
}

$today = date('Y-m-d');
$default_dob = date('Y-m-d', strtotime('-20 years'));

// ---------- Fetch Dropdown Data ----------
$teams = [];
$team_res = $mysqli->query("SELECT team_id, team_name FROM team_master WHERE status='Active' ORDER BY team_name ASC");
if ($team_res) {
    while ($r = $team_res->fetch_assoc()) {
        $teams[$r['team_id']] = $r['team_name'];
    }
}

$designations = [];
$desig_res = $mysqli->query("SELECT designation_id, designation_name FROM designation_master WHERE status='Active' ORDER BY designation_name ASC");
if ($desig_res) {
    while ($r = $desig_res->fetch_assoc()) {
        $designations[$r['designation_id']] = $r['designation_name'];
    }
}

$user_roles = [];
$role_res = $mysqli->query("SELECT role_id, role_name FROM user_role_master WHERE status='Active' ORDER BY role_name ASC");
if ($role_res) {
    while ($r = $role_res->fetch_assoc()) {
        $user_roles[$r['role_id']] = $r['role_name'];
    }
}

$reporting_managers = [];
$mgr_res = $mysqli->query("SELECT employee_id, employee_name FROM employee_master WHERE status='Active' AND user_role_id IN (3, 5, 8) ORDER BY employee_name ASC");
if ($mgr_res) {
    while ($r = $mgr_res->fetch_assoc()) {
        $reporting_managers[$r['employee_id']] = $r['employee_name'];
    }
}

// ---------- POST HANDLER (Edit, Add, Delete) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['employee_id'] ?? 0);

    // --- EDIT OPERATION ---
    if ($action === 'edit' && $id > 0) {
        $employee_name     = trim($_POST['employee_name'] ?? '');
        $employee_username = trim($_POST['employee_username'] ?? '');
        $email             = trim($_POST['email'] ?? '');
        $phone             = trim($_POST['phone'] ?? '');
        $date_of_birth     = trim($_POST['date_of_birth'] ?? '');
        $joining_date      = trim($_POST['joining_date'] ?? '') ?: $today;
        $team_id           = (int)($_POST['team_id'] ?? 0);
        $designation_id    = (int)($_POST['designation_id'] ?? 0);
        $user_role_id      = (int)($_POST['user_role_id'] ?? 0);
        $raw_manager       = trim($_POST['reporting_manager'] ?? '');
        $shift             = trim($_POST['shift'] ?? 'General');
        $status            = trim($_POST['status'] ?? 'Active');
        $salary            = !empty($_POST['salary']) ? (float)$_POST['salary'] : 0.0;
        $tds_per           = !empty($_POST['tds_per']) ? (float)$_POST['tds_per'] : 0.0;

        // Resolve reporting manager name if given as ID
        $r_name = '';
        if (is_numeric($raw_manager) && (int)$raw_manager > 0) {
            $stmt_mgr = $mysqli->prepare("SELECT employee_name FROM employee_master WHERE employee_id = ?");
            if ($stmt_mgr) {
                $mgr_int = (int)$raw_manager;
                $stmt_mgr->bind_param("i", $mgr_int);
                $stmt_mgr->execute();
                $mgr_res_row = $stmt_mgr->get_result()->fetch_assoc();
                if ($mgr_res_row) $r_name = $mgr_res_row['employee_name'];
                $stmt_mgr->close();
            }
        }
        if (!$r_name && !empty($raw_manager)) {
            $r_name = $raw_manager;
        }

        $errors = [];
        if (!$employee_name) $errors[] = 'Employee Name is required.';
        if (!$employee_username) $errors[] = 'Username is required.';
        if (!is_valid_date($date_of_birth)) $errors[] = 'Valid Date of Birth is required.';
        if (!is_valid_date($joining_date)) $errors[] = 'Valid Joining Date is required.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid Email is required.';
        if (!$phone) $errors[] = 'Phone Number is required.';

        // Check duplicate username for other users
        $chk = $mysqli->prepare("SELECT employee_id FROM employee_master WHERE employee_username = ? AND employee_id != ?");
        if ($chk) {
            $chk->bind_param("si", $employee_username, $id);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) $errors[] = "Username '{$employee_username}' is already taken.";
            $chk->close();
        }

        if ($errors) {
            redirect_with(['msg' => implode(' ', $errors), 'type' => 'danger']);
        }

        $sql = "UPDATE employee_master SET 
                employee_name = ?,
                employee_username = ?,
                email = ?,
                phone = ?,
                date_of_birth = ?,
                joining_date = ?,
                team_id = ?,
                designation_id = ?,
                user_role_id = ?,
                reporting_manager = ?,
                shift = ?,
                status = ?,
                salary = ?,
                tds_per = ?
                WHERE employee_id = ?";

        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param(
                "ssssssiiiisddsi",
                $employee_name,
                $employee_username,
                $email,
                $phone,
                $date_of_birth,
                $joining_date,
                $team_id,
                $designation_id,
                $user_role_id,
                $r_name,
                $shift,
                $status,
                $salary,
                $tds_per,
                $id
            );
            if ($stmt->execute()) {
                redirect_with(['msg' => "Employee '{$employee_name}' updated successfully.", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error updating employee: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        } else {
            redirect_with(['msg' => 'Prepare failed: ' . $mysqli->error, 'type' => 'danger']);
        }
    }

    // --- DELETE OPERATION ---
    if ($action === 'delete' && $id > 0) {
        $del_name = '';
        $q = $mysqli->prepare("SELECT employee_name FROM employee_master WHERE employee_id = ?");
        if ($q) {
            $q->bind_param("i", $id);
            $q->execute();
            $r = $q->get_result()->fetch_assoc();
            if ($r) $del_name = $r['employee_name'];
            $q->close();
        }
        $stmt_del = $mysqli->prepare("DELETE FROM employee_master WHERE employee_id = ?");
        if ($stmt_del) {
            $stmt_del->bind_param("i", $id);
            if ($stmt_del->execute()) {
                redirect_with(['msg' => "Employee '{$del_name}' deleted successfully.", 'type' => 'warning']);
            } else {
                redirect_with(['msg' => 'Error deleting employee: ' . $stmt_del->error, 'type' => 'danger']);
            }
            $stmt_del->close();
        }
    }

    redirect_with();
}

// ---------- CSV EXPORT ----------
if (isset($_GET['export']) && $_GET['export'] === 'true') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=employee_master_export_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Name', 'Username', 'Team', 'Designation', 'User Role', 'Reporting Manager', 'Shift', 'Salary (INR)', 'TDS %', 'Status']);

    $export_sql = "SELECT e.employee_id, e.employee_name, e.employee_username, e.email, e.phone, 
                          e.date_of_birth, e.joining_date, e.shift, e.status, e.reporting_manager,
                          t.team_name, d.designation_name, ur.role_name as user_role,
                          rm.employee_name as reporting_manager_name,
                          e.salary, e.tds_per
                   FROM employee_master e 
                   LEFT JOIN team_master t ON e.team_id = t.team_id
                   LEFT JOIN designation_master d ON e.designation_id = d.designation_id
                   LEFT JOIN user_role_master ur ON e.user_role_id = ur.role_id
                   LEFT JOIN employee_master rm ON e.reporting_manager = rm.employee_id
                   ORDER BY e.employee_id DESC";
    $exp_res = $mysqli->query($export_sql);
    if ($exp_res) {
        $idx = 0;
        while ($row = $exp_res->fetch_assoc()) {
            $idx++;
            $mgr = $row['reporting_manager_name'] ?: ($row['reporting_manager'] ?: '—');
            fputcsv($output, [
                $idx,
                $row['employee_name'],
                $row['employee_username'],
                $row['team_name'] ?: '—',
                $row['designation_name'] ?: '—',
                $row['user_role'] ?: '—',
                $mgr,
                $row['shift'] ?: '—',
                $row['salary'] ?? 0,
                $row['tds_per'] ?? 0,
                $row['status']
            ]);
        }
    }
    fclose($output);
    exit;
}

// ---------- BACKWARDS COMPATIBILITY: EDIT ROW LOOKUP ----------
$edit_id = (isset($_GET['mode']) && $_GET['mode'] === 'edit') ? (int)($_GET['id'] ?? 0) : 0;
$editRow = null;
if ($edit_id > 0) {
    $stmt_er = $mysqli->prepare("SELECT * FROM employee_master WHERE employee_id = ?");
    if ($stmt_er) {
        $stmt_er->bind_param("i", $edit_id);
        $stmt_er->execute();
        $editRow = $stmt_er->get_result()->fetch_assoc();
        $stmt_er->close();
    }
}

// ---------- FETCH EMPLOYEES FOR DATATABLE ----------
$sql = "SELECT e.employee_id, e.employee_name, e.employee_username, e.email, e.phone, 
               e.date_of_birth, e.joining_date, e.shift, e.status, e.reporting_manager,
               e.team_id, e.designation_id, e.user_role_id,
               t.team_name, d.designation_name, ur.role_name as user_role,
               rm.employee_name as reporting_manager_name,
               e.salary, e.tds_per
        FROM employee_master e 
        LEFT JOIN team_master t ON e.team_id = t.team_id
        LEFT JOIN designation_master d ON e.designation_id = d.designation_id
        LEFT JOIN user_role_master ur ON e.user_role_id = ur.role_id
        LEFT JOIN employee_master rm ON e.reporting_manager = rm.employee_id
        ORDER BY e.employee_id DESC";

$employees_result = $mysqli->query($sql);

$pageTitle = "Employee Master";
$currentPage = "employee_master.php";
require_once __DIR__ . '/header.php';
?>

<!-- DataTables CSS & Buttons CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

<style>
    /* =========================================================================
       SENIOR DEVELOPER STYLING & CLEAN CARD CONTAINER
       ========================================================================= */
    .header-icon-box {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: #eef2ff;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .emp-card-container {
        background: #ffffff;
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
        overflow: hidden;
    }
    .table-senior {
        margin-bottom: 0;
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    .table-senior thead th {
        background-color: #5a6a7a !important;
        color: #ffffff !important;
        font-weight: 700;
        font-size: 0.76rem;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        white-space: nowrap;
        border-bottom: 1px solid #4a5568;
        vertical-align: middle;
        padding: 12px 14px;
    }
    .table-senior thead th a,
    .table-senior thead th i {
        color: #ffffff !important;
    }
    .table-senior tbody td {
        font-size: 0.86rem;
        vertical-align: middle;
        white-space: nowrap;
        color: #334155;
        padding: 11px 14px;
        border-bottom: 1px solid #f1f5f9;
        transition: background-color 0.15s ease;
    }
    .table-senior tbody tr:hover td {
        background-color: #f8fafc;
    }
    .emp-code-pill {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-weight: 700;
        font-size: 0.8rem;
        color: #0284c7;
        background: #e0f2fe;
        padding: 3px 8px;
        border-radius: 6px;
        border: 1px solid #bae6fd;
        display: inline-block;
    }
    .btn-edit-employee {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        background: #ffc107;
        border: 1px solid #ffb300;
        color: #000000;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        transition: all 0.2s ease;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .btn-edit-employee:hover {
        background: #e0a800;
        color: #000000;
        transform: translateY(-1px);
        box-shadow: 0 3px 6px rgba(0,0,0,0.15);
    }

    /* Custom DataTables Elements */
    div.dataTables_wrapper div.dataTables_filter {
        text-align: right;
    }
    div.dataTables_wrapper div.dataTables_filter input {
        border-radius: 20px;
        padding: 6px 14px;
        border: 1px solid #cbd5e1;
        outline: none;
        transition: all 0.2s ease;
        width: 220px;
        display: inline-block;
        margin-left: 8px;
    }
    div.dataTables_wrapper div.dataTables_filter input:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
    }
    .dt-buttons .btn {
        font-size: 0.84rem;
        font-weight: 600;
        border-radius: 20px !important;
        padding: 6px 16px;
        margin-right: 6px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    }

    /* =========================================================================
       ERP FLOATING TOAST NOTIFICATION
       ========================================================================= */
    .erp-toast-wrapper {
        position: fixed;
        top: 25px;
        right: 25px;
        z-index: 1090;
        pointer-events: auto;
    }
    .erp-toast-card {
        min-width: 330px;
        max-width: 420px;
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        border: 1px solid #e2e8f0;
        display: flex;
        align-items: flex-start;
        padding: 14px 16px;
        position: relative;
        overflow: hidden;
        animation: erpToastSlideIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    @keyframes erpToastSlideIn {
        from { transform: translateX(110%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes erpToastSlideOut {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(110%); opacity: 0; }
    }
    .erp-toast-icon-box {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        flex-shrink: 0;
        margin-right: 12px;
    }
    .erp-toast-success .erp-toast-icon-box { background: #dcfce7; color: #16a34a; }
    .erp-toast-danger .erp-toast-icon-box { background: #fee2e2; color: #dc2626; }
    .erp-toast-info .erp-toast-icon-box { background: #e0f2fe; color: #0284c7; }
    .erp-toast-content { flex: 1; padding-right: 10px; }
    .erp-toast-header { display: flex; align-items: center; gap: 6px; margin-bottom: 2px; }
    .erp-toast-title { font-size: 0.88rem; font-weight: 700; color: #1e293b; }
    .erp-toast-badge {
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        padding: 2px 6px;
        border-radius: 4px;
        background: #f1f5f9;
        color: #475569;
    }
    .erp-toast-body { font-size: 0.82rem; color: #475569; line-height: 1.35; }
    .erp-toast-close {
        background: none;
        border: none;
        color: #94a3b8;
        font-size: 14px;
        cursor: pointer;
        padding: 4px;
        line-height: 1;
        transition: color 0.15s;
    }
    .erp-toast-close:hover { color: #1e293b; }
    .erp-toast-progress-bar {
        position: absolute;
        bottom: 0;
        left: 0;
        height: 3px;
        width: 100%;
        background: rgba(0, 0, 0, 0.05);
    }
    .erp-toast-progress-fill {
        height: 100%;
        width: 100%;
        animation: erpToastTimer 4.5s linear forwards;
    }
    .erp-toast-success .erp-toast-progress-fill { background: #16a34a; }
    .erp-toast-danger .erp-toast-progress-fill { background: #dc2626; }
    .erp-toast-info .erp-toast-progress-fill { background: #0284c7; }
    @keyframes erpToastTimer { from { width: 100%; } to { width: 0%; } }
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
                $toastTitle = 'Notice';
                $toastIcon  = 'fa-trash-alt';
                $toastBadge = 'Deleted';
                $toastClass = 'erp-toast-danger';
            } elseif (stripos($toastMsg, 'add') !== false || stripos($toastMsg, 'create') !== false || $toastType === 'success') {
                $toastTitle = 'Successfully Updated';
                $toastIcon  = 'fa-check-circle';
                $toastBadge = 'Success';
                $toastClass = 'erp-toast-success';
            } elseif (stripos($toastMsg, 'update') !== false || stripos($toastMsg, 'updated') !== false) {
                $toastTitle = 'Successfully Updated';
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
                const card = document.getElementById('erpToastCard');
                if (card) {
                    card.style.animation = 'erpToastSlideOut 0.3s cubic-bezier(0.4, 0, 0.2, 1) forwards';
                    setTimeout(() => {
                        const wrapper = document.getElementById('erpToastWrapper');
                        if (wrapper) wrapper.remove();
                    }, 300);
                }
            }

            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(dismissErpToast, 4500);
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('msg');
                    url.searchParams.delete('type');
                    window.history.replaceState({}, document.title, url.toString());
                } catch(e) {}
            });
        </script>
        <?php endif; ?>

        <!-- PAGE HEADER / ACTIONS -->
        <div class="row align-items-center mb-4">
            <div class="col-md-7 col-12 mb-md-0 mb-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="header-icon-box">
                        <i class="fas fa-users text-primary fs-5"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.3px;">Employee Master</h4>
                        <div class="text-muted small">Directory of workforce profiles, team assignments, reporting lines, and compensation.</div>
                    </div>
                </div>
            </div>
            <div class="col-md-5 col-12 text-md-end text-start">
                <div class="d-inline-flex align-items-center gap-2">
                    <a href="employee_master.php" class="btn btn-outline-secondary px-3 py-2 rounded-pill shadow-sm d-inline-flex align-items-center gap-2 text-decoration-none" style="font-size: 0.84rem; font-weight: 600;">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Hub</span>
                    </a>
                    <a href="employee_master-old.php?export=true" class="btn btn-outline-primary px-3 py-2 rounded-pill shadow-sm d-inline-flex align-items-center gap-2 text-decoration-none" style="font-size: 0.84rem; font-weight: 600;">
                        <i class="fas fa-file-excel"></i>
                        <span>Export CSV</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- MAIN DATATABLE CARD -->
        <div class="card border-0 shadow-sm rounded-3 overflow-hidden emp-card-container">
            <div class="card-body p-3">
                <div class="table-responsive">
                    <table id="employeesTable" class="table table-hover align-middle mb-0 table-senior" style="width:100%">
                        <thead>
                            <tr>
                                <th width="50" class="text-center">#</th>
                                <th>NAME</th>
                                <th>USERNAME</th>
                                <th>TEAM</th>
                                <th>DESIGNATION</th>
                                <th>USER ROLE</th>
                                <th>REPORTING MANAGER</th>
                                <th>SHIFT</th>
                                <th class="text-end">SALARY (₹)</th>
                                <th class="text-center">TDS %</th>
                                <th class="text-center">STATUS</th>
                                <th class="text-center" width="80">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($employees_result && $employees_result->num_rows > 0): 
                                $i = 0;
                                while($r = $employees_result->fetch_assoc()): 
                                    $i++;
                                    $mgr_display = $r['reporting_manager_name'] ?: ($r['reporting_manager'] ?: '—');
                                    $status_class = match($r['status']) {
                                        'Active' => 'bg-success-subtle text-success border border-success-subtle',
                                        'Inactive' => 'bg-secondary-subtle text-secondary border',
                                        'On Leave' => 'bg-info-subtle text-info border border-info-subtle',
                                        'Terminated' => 'bg-danger-subtle text-danger border border-danger-subtle',
                                        'Resigned' => 'bg-warning-subtle text-warning border border-warning-subtle',
                                        default => 'bg-secondary-subtle text-secondary border'
                                    };
                            ?>
                            <tr>
                                <td class="text-center fw-bold text-secondary"><?= $i ?></td>
                                <td>
                                    <strong class="text-dark"><?= h($r['employee_name']) ?></strong>
                                </td>
                                <td>
                                    <span class="emp-code-pill font-monospace"><?= h($r['employee_username']) ?></span>
                                </td>
                                <td><?= h($r['team_name'] ?: '—') ?></td>
                                <td><?= h($r['designation_name'] ?: '—') ?></td>
                                <td>
                                    <span class="badge bg-light text-secondary border"><?= h($r['user_role'] ?: '—') ?></span>
                                </td>
                                <td><?= h($mgr_display) ?></td>
                                <td>
                                    <span class="badge bg-light text-secondary border"><?= h($r['shift'] ?: 'General') ?></span>
                                </td>
                                <td class="text-end fw-semibold">
                                    &#8377;<?= number_format($r['salary'] ?? 0, 2) ?>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($r['tds_per']) && (float)$r['tds_per'] > 0): ?>
                                        <span class="badge bg-warning text-dark"><?= number_format($r['tds_per'], 1) ?>%</span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?= $status_class ?> rounded-pill px-2 py-1">
                                        <i class="fas fa-circle me-1" style="font-size:0.5rem;"></i><?= h($r['status']) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <button type="button" 
                                            class="btn-edit-employee" 
                                            onclick='openEditEmployeeModal(<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' 
                                            title="Edit Details">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="12" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="fas fa-users fa-3x mb-3 text-secondary opacity-50"></i>
                                        <h5 class="fw-semibold">No employees found</h5>
                                        <p class="mb-0 small">No employee master data available.</p>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</main>

<!-- =========================================================================
     MODAL: EDIT EMPLOYEE DETAILS (SENIOR DEVELOPER MODAL)
     CRITICAL: STRICT MODAL HEADER (NO SAVE BUTTON IN HEADER - ONLY IN FOOTER)
     ========================================================================= -->
<div class="modal fade" id="editEmployeeModal" tabindex="-1" aria-labelledby="editEmployeeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-3 overflow-hidden">
            
            <!-- Strict Modal Header: ONLY Icon, Title, Subtitle, and ✕ Close Button! -->
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <span class="header-icon-box me-2" style="width: 36px; height: 36px; border-radius: 8px; background: rgba(245, 158, 11, 0.1); display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-user-pen text-warning fs-5"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-dark fs-6" id="editEmployeeModalLabel">Edit Employee Details</h5>
                        <div class="text-muted" style="font-size: 0.75rem;">Update workforce profile, team assignment, reporting hierarchy, and compensation.</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Edit Form -->
            <form method="post" action="employee_master-old.php" autocomplete="off" id="editEmployeeForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="employee_id" id="edit_employee_id" value="">
                
                <div class="modal-body p-4">
                    <!-- Section 1: Personal Info -->
                    <h6 class="fw-bold text-primary mb-3 d-flex align-items-center gap-2" style="font-size: 0.85rem;">
                        <i class="fas fa-user"></i> Personal Information
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary" style="font-size: 0.82rem;">Employee Name <span class="text-danger">*</span></label>
                            <input type="text" name="employee_name" id="edit_employee_name" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary" style="font-size: 0.82rem;">Username <span class="text-danger">*</span></label>
                            <input type="text" name="employee_username" id="edit_employee_username" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary" style="font-size: 0.82rem;">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="edit_email" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary" style="font-size: 0.82rem;">Phone Number <span class="text-danger">*</span></label>
                            <input type="text" name="phone" id="edit_phone" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary" style="font-size: 0.82rem;">Date of Birth <span class="text-danger">*</span></label>
                            <input type="date" name="date_of_birth" id="edit_date_of_birth" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary" style="font-size: 0.82rem;">Joining Date <span class="text-danger">*</span></label>
                            <input type="date" name="joining_date" id="edit_joining_date" class="form-control form-control-sm" required>
                        </div>
                    </div>

                    <!-- Section 2: Organizational Assignment -->
                    <h6 class="fw-bold text-primary mb-3 d-flex align-items-center gap-2" style="font-size: 0.85rem;">
                        <i class="fas fa-sitemap"></i> Organization & Work Details
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary" style="font-size: 0.82rem;">Team <span class="text-danger">*</span></label>
                            <select name="team_id" id="edit_team_id" class="form-select form-select-sm" required>
                                <option value="">Select Team</option>
                                <?php foreach ($teams as $t_id => $t_name): ?>
                                    <option value="<?= (int)$t_id ?>"><?= h($t_name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary" style="font-size: 0.82rem;">Designation <span class="text-danger">*</span></label>
                            <select name="designation_id" id="edit_designation_id" class="form-select form-select-sm" required>
                                <option value="">Select Designation</option>
                                <?php foreach ($designations as $d_id => $d_name): ?>
                                    <option value="<?= (int)$d_id ?>"><?= h($d_name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary" style="font-size: 0.82rem;">User Role <span class="text-danger">*</span></label>
                            <select name="user_role_id" id="edit_user_role_id" class="form-select form-select-sm" required>
                                <option value="">Select User Role</option>
                                <?php foreach ($user_roles as $r_id => $r_name): ?>
                                    <option value="<?= (int)$r_id ?>"><?= h($r_name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary" style="font-size: 0.82rem;">Reporting Manager</label>
                            <select name="reporting_manager" id="edit_reporting_manager" class="form-select form-select-sm">
                                <option value="">Select Reporting Manager</option>
                                <?php foreach ($reporting_managers as $m_id => $m_name): ?>
                                    <option value="<?= (int)$m_id ?>"><?= h($m_name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary" style="font-size: 0.82rem;">Shift <span class="text-danger">*</span></label>
                            <select name="shift" id="edit_shift" class="form-select form-select-sm" required>
                                <option value="General">General</option>
                                <option value="Day">Day</option>
                                <option value="Night">Night</option>
                                <option value="Rotational">Rotational</option>
                                <option value="WFH">WFH</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary" style="font-size: 0.82rem;">Status <span class="text-danger">*</span></label>
                            <select name="status" id="edit_status" class="form-select form-select-sm" required>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                                <option value="On Leave">On Leave</option>
                                <option value="Terminated">Terminated</option>
                                <option value="Resigned">Resigned</option>
                            </select>
                        </div>
                    </div>

                    <!-- Section 3: Compensation Parameters -->
                    <h6 class="fw-bold text-primary mb-3 d-flex align-items-center gap-2" style="font-size: 0.85rem;">
                        <i class="fas fa-indian-rupee-sign"></i> Compensation Details
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary" style="font-size: 0.82rem;">Salary (₹)</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light text-muted">&#8377;</span>
                                <input type="number" step="0.01" name="salary" id="edit_salary" class="form-control" placeholder="0.00">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary" style="font-size: 0.82rem;">TDS Percentage (%)</label>
                            <div class="input-group input-group-sm">
                                <input type="number" step="0.01" name="tds_per" id="edit_tds_per" class="form-control" placeholder="0.00">
                                <span class="input-group-text bg-light text-muted">%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Strict Modal Footer: Cancel and Update Details -->
                <div class="modal-footer bg-light border-top px-4 py-2 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 shadow-sm erp-btn-save d-flex align-items-center gap-2" style="font-weight: 600; border-radius: 8px;">
                        <i class="fas fa-save"></i> <span>Update Details</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DataTables & jQuery Scripts -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

<script>
// Open Edit Employee Modal & Populate Values
function openEditEmployeeModal(emp) {
    if (!emp) return;
    document.getElementById('edit_employee_id').value = emp.employee_id || '';
    document.getElementById('edit_employee_name').value = emp.employee_name || '';
    document.getElementById('edit_employee_username').value = emp.employee_username || '';
    document.getElementById('edit_email').value = emp.email || '';
    document.getElementById('edit_phone').value = emp.phone || '';
    
    let dob = (emp.date_of_birth && emp.date_of_birth !== '0000-00-00') ? emp.date_of_birth : '';
    let doj = (emp.joining_date && emp.joining_date !== '0000-00-00') ? emp.joining_date : '';
    document.getElementById('edit_date_of_birth').value = dob;
    document.getElementById('edit_joining_date').value = doj;
    
    document.getElementById('edit_team_id').value = emp.team_id || '';
    document.getElementById('edit_designation_id').value = emp.designation_id || '';
    document.getElementById('edit_user_role_id').value = emp.user_role_id || '';
    
    // Select reporting manager by ID or by matching name
    let mgrSelect = document.getElementById('edit_reporting_manager');
    let mgrVal = emp.reporting_manager || '';
    let matched = false;
    for (let i = 0; i < mgrSelect.options.length; i++) {
        if (mgrSelect.options[i].value == mgrVal || mgrSelect.options[i].text.trim() == mgrVal.trim()) {
            mgrSelect.selectedIndex = i;
            matched = true;
            break;
        }
    }
    if (!matched) mgrSelect.value = '';
    
    document.getElementById('edit_shift').value = emp.shift || 'General';
    document.getElementById('edit_status').value = emp.status || 'Active';
    document.getElementById('edit_salary').value = parseFloat(emp.salary || 0).toFixed(2);
    document.getElementById('edit_tds_per').value = parseFloat(emp.tds_per || 0).toFixed(2);
    
    let modalEl = document.getElementById('editEmployeeModal');
    let modalInstance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    modalInstance.show();
}

$(document).ready(function() {
    // Initialize DataTable with exact layout & custom buttons
    if ($.fn.DataTable.isDataTable('#employeesTable')) {
        $('#employeesTable').DataTable().destroy();
    }
    
    var table = $('#employeesTable').DataTable({
        "dom": '<"row align-items-center mb-3"<"col-md-6 d-flex align-items-center gap-2"B><"col-md-6 d-flex justify-content-md-end justify-content-start"f>>rt<"row align-items-center mt-3"<"col-md-6 text-muted small"l><"col-md-6 d-flex justify-content-md-end"p>>',
        "buttons": [
            { 
                extend: 'copyHtml5', 
                className: 'btn btn-sm btn-secondary rounded-pill px-3 shadow-sm', 
                text: '<i class="fas fa-copy me-1"></i> Copy' 
            },
            { 
                extend: 'print', 
                className: 'btn btn-sm btn-info text-white rounded-pill px-3 shadow-sm', 
                text: '<i class="fas fa-print me-1"></i> Print' 
            }
        ],
        "scrollX": true,
        "paging": true,
        "pageLength": 25,
        "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        "order": [[0, 'asc']],
        "language": {
            "search": "_INPUT_",
            "searchPlaceholder": "Search...",
            "lengthMenu": "Show _MENU_ entries",
            "paginate": {
                "previous": '<i class="fas fa-chevron-left"></i>',
                "next": '<i class="fas fa-chevron-right"></i>'
            }
        }
    });

    // Style search input to match senior developer look
    $('.dataTables_filter input')
        .addClass('form-control form-control-sm rounded-pill px-3')
        .css({'display': 'inline-block', 'width': '220px'});
    $('.dataTables_length select')
        .addClass('form-select form-select-sm rounded-pill px-3');

    // Auto-open modal if navigated with ?mode=edit&id=...
    <?php if ($edit_id > 0 && !empty($editRow)): ?>
        openEditEmployeeModal(<?= json_encode($editRow, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>);
    <?php endif; ?>
});
</script>
</body>
</html>
