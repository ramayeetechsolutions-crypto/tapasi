<?php
require_once __DIR__ . '/../db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// ---------- Helpers ----------
if (!function_exists('h')) {
    function h($s) { 
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
    }
}

if (!function_exists('redirect_with')) {
    function redirect_with($params = []) {
        $base = 'employee_master.php';
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

if (!function_exists('formatDate')) {
    function formatDate($d) {
        return (!empty($d) && $d != '0000-00-00' && $d != '0000-00-00 00:00:00') ? date('d M, Y', strtotime($d)) : '—';
    }
}

$today = date('Y-m-d');
$default_dob = date('Y-m-d', strtotime('-20 years'));

// --- AJAX for dynamic managers ---
if (isset($_GET['ajax'])) {
    if ($_GET['ajax'] === 'team_leads') {
        $res = $mysqli->query("SELECT employee_name FROM employee_master WHERE user_role_id=3 AND status='Active' ORDER BY employee_name");
        $names = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) $names[] = $r['employee_name'];
        }
        header('Content-Type: application/json'); 
        echo json_encode($names); 
        exit;
    }
    if ($_GET['ajax'] === 'admins') {
        $res = $mysqli->query("SELECT employee_name FROM employee_master WHERE (user_role_id=5 OR user_role_id=8) AND status='Active' ORDER BY employee_name");
        $names = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) $names[] = $r['employee_name'];
        }
        header('Content-Type: application/json'); 
        echo json_encode($names); 
        exit;
    }
}

// ---------- POST Handler ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['employee_id'] ?? 0);

    if ($action === 'add' || $action === 'edit') {
        $employee_name     = trim($_POST['employee_name'] ?? '');
        $employee_username = trim($_POST['employee_username'] ?? '');
        $password          = $_POST['password'] ?? '';
        $confirm_password  = $_POST['confirm_password'] ?? '';
        $email             = trim($_POST['email'] ?? '');
        $phone             = trim($_POST['phone'] ?? '');
        $date_of_birth     = trim($_POST['date_of_birth'] ?? '');
        $joining_date      = trim($_POST['joining_date'] ?? '') ?: $today;
        
        $selected_processes = "";
        if (!empty($_POST['process']) && is_array($_POST['process'])) {
            $selected_processes = implode(",", array_map('intval', $_POST['process']));
        }
        
        $reporting_manager = trim($_POST['reporting_manager'] ?? '');
        $user_role_id      = isset($_POST['user_role_id']) ? (int)$_POST['user_role_id'] : 0;

        $errors = [];
        if (!$employee_name) $errors[] = 'Employee Name is required.';
        if (!$employee_username) $errors[] = 'Username is required.';
        if (!is_valid_date($date_of_birth)) $errors[] = 'Valid Date of Birth is required.';
        if (!is_valid_date($joining_date)) $errors[] = 'Valid Joining Date is required.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid Email is required.';
        if (!$phone || !preg_match('/^\+?\d{9,15}$/', $phone)) $errors[] = 'Valid Phone Number is required.';
        if ($user_role_id <= 0) $errors[] = 'User Role is required.';
        
        if (in_array($user_role_id, [3, 4, 5]) && !$reporting_manager) {
            $errors[] = 'Reporting Manager is required.';
        }
        
        if ($action === 'add' || ($action === 'edit' && $password !== '')) {
            if (strlen($password) < 6) $errors[] = 'Password (minimum 6 characters) is required.';
            if ($password !== $confirm_password) $errors[] = 'Passwords do not match.';
        }

        // Check unique username
        if ($employee_username) {
            $sql = "SELECT employee_id FROM employee_master WHERE employee_username = ?";
            $params = [$employee_username]; 
            $types = 's';
            if ($action === 'edit' && $id > 0) { 
                $sql .= " AND employee_id != ?"; 
                $params[] = $id; 
                $types .= 'i'; 
            }
            $stmt = $mysqli->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute(); 
                $stmt->store_result();
                if ($stmt->num_rows > 0) { 
                    $errors[] = "Username '$employee_username' already exists."; 
                }
                $stmt->close();
            }
        }

        if ($errors) {
            redirect_with(['msg' => implode(' ', $errors), 'type' => 'danger']);
        }

        if ($action === 'add') {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $mysqli->prepare("INSERT INTO employee_master 
                (employee_name, employee_username, password_hash, email, phone, date_of_birth, joining_date, process, reporting_manager, user_role_id, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");

            if ($stmt) {
                $stmt->bind_param(
                    "sssssssssi", 
                    $employee_name, 
                    $employee_username, 
                    $password_hash, 
                    $email, 
                    $phone, 
                    $date_of_birth, 
                    $joining_date, 
                    $selected_processes, 
                    $reporting_manager, 
                    $user_role_id
                );
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Employee '{$employee_name}' added successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error adding employee: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            } else {
                redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
            }
        } elseif ($action === 'edit' && $id > 0) {
            if ($password !== '') {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare("UPDATE employee_master 
                    SET employee_name=?, employee_username=?, password_hash=?, email=?, phone=?, date_of_birth=?, joining_date=?, process=?, reporting_manager=?, user_role_id=? 
                    WHERE employee_id=?");
                if ($stmt) {
                    $stmt->bind_param(
                        "sssssssssii", 
                        $employee_name, 
                        $employee_username, 
                        $password_hash, 
                        $email, 
                        $phone, 
                        $date_of_birth, 
                        $joining_date, 
                        $selected_processes, 
                        $reporting_manager, 
                        $user_role_id, 
                        $id
                    );
                    $stmt->execute();
                    $stmt->close();
                    redirect_with(['msg' => "Employee '{$employee_name}' updated successfully", 'type' => 'success']);
                }
            } else {
                $stmt = $mysqli->prepare("UPDATE employee_master 
                    SET employee_name=?, employee_username=?, email=?, phone=?, date_of_birth=?, joining_date=?, process=?, reporting_manager=?, user_role_id=? 
                    WHERE employee_id=?");
                if ($stmt) {
                    $stmt->bind_param(
                        "ssssssssii", 
                        $employee_name, 
                        $employee_username, 
                        $email, 
                        $phone, 
                        $date_of_birth, 
                        $joining_date, 
                        $selected_processes, 
                        $reporting_manager, 
                        $user_role_id, 
                        $id
                    );
                    $stmt->execute();
                    $stmt->close();
                    redirect_with(['msg' => "Employee '{$employee_name}' updated successfully", 'type' => 'success']);
                }
            }
        }
    }

    if ($action === 'delete' && $id > 0) {
        $name = '';
        $q = $mysqli->prepare("SELECT employee_name FROM employee_master WHERE employee_id=?");
        if ($q) {
            $q->bind_param("i", $id);
            $q->execute();
            $row = $q->get_result()->fetch_assoc();
            $q->close();
            if ($row) $name = $row['employee_name'];
        }

        $stmt = $mysqli->prepare("DELETE FROM employee_master WHERE employee_id=?");
        if ($stmt) {
            $stmt->bind_param("i", $id); 
            $stmt->execute(); 
            $stmt->close();
            redirect_with(['msg' => "Employee '$name' deleted successfully", 'type' => 'warning']);
        }
    }

    if ($action === 'toggle' && $id > 0) {
        $stmt = $mysqli->prepare("SELECT status, employee_name FROM employee_master WHERE employee_id=?");
        if ($stmt) {
            $stmt->bind_param("i", $id); 
            $stmt->execute(); 
            $res = $stmt->get_result()->fetch_assoc(); 
            $stmt->close();
            if ($res) {
                $new_status = ($res['status'] === 'Active') ? 'Inactive' : 'Active';
                $upd = $mysqli->prepare("UPDATE employee_master SET status=? WHERE employee_id=?");
                if ($upd) {
                    $upd->bind_param("si", $new_status, $id); 
                    $upd->execute(); 
                    $upd->close();
                    redirect_with(['msg' => "Employee '{$res['employee_name']}' status changed to {$new_status}", 'type' => 'info']);
                }
            }
        }
    }

    redirect_with();
}

// ---------- Data Fetching & Filters ----------
$q       = trim($_GET['q'] ?? '');
$fstatus = trim($_GET['status'] ?? '');
$frole   = (int)($_GET['role'] ?? 0);
$sort    = trim($_GET['sort'] ?? 'employee_id');
$dir     = strtolower(trim($_GET['dir'] ?? 'desc'));

$sortable = ['employee_id','employee_name','employee_username','email','phone','date_of_birth','joining_date','reporting_manager','user_role_id','status'];
if (!in_array($sort, $sortable, true)) $sort = 'employee_id';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

$sql = "SELECT e.*, r.role_name FROM employee_master e 
        LEFT JOIN user_role_master r ON e.user_role_id = r.role_id
        WHERE 1=1";
$cond = [];
$params = [];
$types = '';

if ($q !== '') { 
    $cond[] = "(e.employee_name LIKE ? OR e.employee_username LIKE ? OR e.email LIKE ? OR e.phone LIKE ?)"; 
    $like = "%$q%"; 
    $params = array_merge($params, [$like, $like, $like, $like]); 
    $types .= 'ssss'; 
}
if ($fstatus === 'Active' || $fstatus === 'Inactive') { 
    $cond[] = "e.status = ?"; 
    $params[] = $fstatus; 
    $types .= 's'; 
}
if ($frole > 0) {
    $cond[] = "e.user_role_id = ?";
    $params[] = $frole;
    $types .= 'i';
}

if (!empty($cond)) {
    $sql .= " AND " . implode(" AND ", $cond);
}
$sql .= " ORDER BY $sort $dir";

$rows = false;
if (!empty($types)) { 
    $stmt = $mysqli->prepare($sql); 
    if ($stmt) {
        $stmt->bind_param($types, ...$params); 
        $stmt->execute(); 
        $rows = $stmt->get_result(); 
    }
} else {
    $rows = $mysqli->query($sql);
}

// KPI Metrics
$metricsQuery = $mysqli->query("SELECT 
    COUNT(*) as total, 
    SUM(status='Active') as active, 
    SUM(status='Inactive') as inactive,
    SUM(user_role_id=3) as team_leads,
    SUM(user_role_id=5 OR user_role_id=8) as admins
    FROM employee_master");
$metrics = $metricsQuery ? $metricsQuery->fetch_assoc() : ['total' => 0, 'active' => 0, 'inactive' => 0, 'team_leads' => 0, 'admins' => 0];

// Process Map for Table Display
$processMap = [];
$procResult = $mysqli->query("SELECT process_id, process_name FROM process_master ORDER BY process_id");
if ($procResult) {
    while ($pr = $procResult->fetch_assoc()) {
        $processMap[$pr['process_id']] = $pr['process_name'];
    }
}

// Process Categorization for Form
$ce = []; $pag = []; $others = [];
foreach ($processMap as $pid => $pname) {
    $item = ['process_id' => $pid, 'process_name' => $pname];
    if ($pname === "CE" || str_ends_with($pname, "-CE") || $pname === "LE" || $pname === "Sub TL") {
        $ce[] = $item;
    } elseif ($pname === "PAG" || str_ends_with($pname, "-PAG") || $pname === "QC") {
        $pag[] = $item;
    } else {
        $others[] = $item;
    }
}

// Roles for Form
$rolesList = [];
$rolesResult = $mysqli->query("SELECT role_id, role_name FROM user_role_master WHERE role_name!='Super Admin' ORDER BY role_name");
if ($rolesResult) {
    while ($rr = $rolesResult->fetch_assoc()) {
        $rolesList[] = $rr;
    }
}

$pageTitle = "Employee Master";
$currentPage = "employee_master.php";
require_once __DIR__ . '/header.php';
?>

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
            } elseif (stripos($toastMsg, 'add') !== false || stripos($toastMsg, 'create') !== false || $toastType === 'success') {
                $toastTitle = 'Successfully Processed';
                $toastIcon  = 'fa-check-circle';
                $toastBadge = 'Success';
                $toastClass = 'erp-toast-success';
            } elseif (stripos($toastMsg, 'status') !== false || stripos($toastMsg, 'toggle') !== false) {
                $toastTitle = 'Status Updated';
                $toastIcon  = 'fa-toggle-on';
                $toastBadge = 'Status';
                $toastClass = 'erp-toast-info';
            } elseif (stripos($toastMsg, 'update') !== false) {
                $toastTitle = 'Successfully Updated';
                $toastIcon  = 'fa-pen-to-square';
                $toastBadge = 'Updated';
                $toastClass = 'erp-toast-info';
            }
        ?>
        <div class="erp-toast-wrapper" id="erpToastWrapper" role="region" aria-label="Notifications">
            <div class="erp-toast-card <?= $toastClass ?>" id="erpToastCard" role="alert" aria-live="assertive">
                <div class="erp-toast-icon">
                    <i class="fas <?= $toastIcon ?>"></i>
                </div>
                <div class="erp-toast-content">
                    <div class="erp-toast-header">
                        <span class="erp-toast-title"><?= $toastTitle ?></span>
                        <span class="erp-toast-badge"><?= $toastBadge ?></span>
                    </div>
                    <div class="erp-toast-msg"><?= $toastMsg ?></div>
                </div>
                <button type="button" class="erp-toast-close" onclick="dismissErpToast()" aria-label="Close notification">&times;</button>
                <div class="erp-toast-progress"><div class="erp-toast-progress-bar"></div></div>
            </div>
        </div>
        <script>
            function dismissErpToast() {
                var el = document.getElementById('erpToastCard');
                if (el) {
                    el.classList.add('hide');
                    setTimeout(function() {
                        var wr = document.getElementById('erpToastWrapper');
                        if (wr) wr.remove();
                    }, 400);
                }
            }
            setTimeout(dismissErpToast, 4500);
        </script>
        <?php endif; ?>

        <!-- TOP BAR: TITLE & ACTIONS -->
        <div class="row align-items-center mb-4 gy-2">
            <div class="col-md-7 col-12">
                <div class="d-flex align-items-center gap-3">
                    <div class="header-icon-box shadow-sm" style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #ede7f6, #d1c4e9); display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-users-cog text-purple fs-5" style="color: #673ab7;"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.3px;">Employee Master</h4>
                        <div class="text-muted small">Manage staff profiles, process assignments, and access privileges</div>
                    </div>
                </div>
            </div>
            <div class="col-md-5 col-12 text-md-end text-start">
                <button type="button" 
                        class="btn btn-primary px-3 py-2 shadow-sm erp-btn-add d-inline-flex align-items-center gap-2"
                        onclick="openAddEmployeeModal()"
                        style="border-radius: 8px; font-weight: 600;">
                    <i class="fas fa-plus"></i>
                    <span>Add Employee</span>
                </button>
            </div>
        </div>

        <!-- KPI SUMMARY CARDS -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Total Employees</div>
                            <div class="fs-4 fw-bold text-dark mt-1"><?= (int)($metrics['total'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-light text-primary" style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-id-card fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Active Staff</div>
                            <div class="fs-4 fw-bold text-success mt-1"><?= (int)($metrics['active'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-success-subtle text-success" style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-user-check fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Inactive Staff</div>
                            <div class="fs-4 fw-bold text-danger mt-1"><?= (int)($metrics['inactive'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-danger-subtle text-danger" style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-user-slash fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Team Leads</div>
                            <div class="fs-4 fw-bold text-info mt-1"><?= (int)($metrics['team_leads'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-info-subtle text-info" style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-user-tie fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SEARCH AND FILTER TOOLBAR -->
        <div class="card border-0 shadow-sm rounded-3 mb-4 filter-card">
            <div class="card-body p-3">
                <form method="get" class="row g-2 align-items-center">
                    <div class="col-lg-4 col-md-4 col-12">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="fas fa-search"></i></span>
                            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search name, username, email, phone..." class="form-control border-start-0" autocomplete="off">
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-3 col-6">
                        <select name="role" class="form-select">
                            <option value="0">All Roles</option>
                            <?php foreach ($rolesList as $r): ?>
                                <option value="<?= (int)$r['role_id'] ?>" <?= $frole === (int)$r['role_id'] ? 'selected' : '' ?>>
                                    <?= h($r['role_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-2 col-6">
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-3 col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-dark w-100 py-2 d-flex align-items-center justify-content-center gap-1 shadow-sm">
                            <i class="fas fa-filter fs-6"></i> <span>Filter</span>
                        </button>
                        <?php if ($q !== '' || $fstatus !== '' || $frole > 0): ?>
                            <a href="employee_master.php" class="btn btn-outline-secondary px-3 py-2" title="Reset Filters">
                                <i class="fas fa-rotate-left"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- DATA TABLE CARD -->
        <div class="card border-0 shadow-sm rounded-3 overflow-hidden table-container">
            <div class="table-responsive m-0 p-0">
                <table class="table table-hover align-middle mb-0 erp-modern-table" style="font-size: var(--erp-table-text-size, 12.5px); white-space: nowrap;">
                    <thead>
                        <tr>
                            <th class="text-center py-3" style="width: 50px;">ID</th>
                            <th class="py-3">EMPLOYEE NAME</th>
                            <th class="py-3">USERNAME</th>
                            <th class="py-3">ROLE</th>
                            <th class="py-3">CONTACT INFO</th>
                            <th class="py-3">REPORTING MANAGER</th>
                            <th class="py-3">JOINING DATE</th>
                            <th class="py-3">PROCESSES</th>
                            <th class="text-center py-3" style="width: 120px;">STATUS</th>
                            <th class="text-center py-3" style="width: 110px;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($rows && $rows->num_rows > 0):
                            while ($r = $rows->fetch_assoc()):
                                // Compile processes
                                $procNames = [];
                                if (!empty($r['process'])) {
                                    $pids = explode(',', $r['process']);
                                    foreach ($pids as $pid) {
                                        $pid = (int)$pid;
                                        if (isset($processMap[$pid])) {
                                            $procNames[] = $processMap[$pid];
                                        }
                                    }
                                }
                                $empJson = json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                        ?>
                            <tr>
                                <td class="text-center fw-bold text-secondary">
                                    #<?= (int)$r['employee_id'] ?>
                                </td>
                                <td>
                                    <strong class="text-dark fs-6"><?= h($r['employee_name']) ?></strong>
                                    <?php if (!empty($r['date_of_birth']) && $r['date_of_birth'] != '0000-00-00'): ?>
                                        <div class="text-muted small" style="font-size: 0.72rem;">DOB: <?= formatDate($r['date_of_birth']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border px-2 py-1 font-monospace">
                                        <?= h($r['employee_username']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                        <?= h($r['role_name'] ?: 'Staff') ?>
                                    </span>
                                </td>
                                <td>
                                    <div><i class="fas fa-envelope text-muted me-1 small"></i><?= h($r['email']) ?></div>
                                    <div class="text-muted small"><i class="fas fa-phone text-muted me-1 small"></i><?= h($r['phone']) ?></div>
                                </td>
                                <td>
                                    <span class="fw-semibold text-secondary"><?= h($r['reporting_manager'] ?: '—') ?></span>
                                </td>
                                <td>
                                    <span class="text-muted"><?= formatDate($r['joining_date']) ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($procNames)): ?>
                                        <div class="d-flex flex-wrap gap-1" style="max-width: 250px;">
                                            <?php foreach (array_slice($procNames, 0, 3) as $pn): ?>
                                                <span class="badge bg-light text-dark border px-1" style="font-size: 0.7rem;"><?= h($pn) ?></span>
                                            <?php endforeach; ?>
                                            <?php if (count($procNames) > 3): ?>
                                                <span class="badge bg-secondary-subtle text-secondary px-1" style="font-size: 0.7rem;" title="<?= h(implode(', ', $procNames)) ?>">
                                                    +<?= count($procNames) - 3 ?> more
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <form method="post" class="d-inline m-0 p-0">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="employee_id" value="<?= (int)$r['employee_id'] ?>">
                                        <button type="submit" 
                                                class="badge-status-pill <?= $r['status'] === 'Active' ? 'status-active-pill' : 'status-inactive-pill' ?>" 
                                                onclick="return confirm('Change status of <?= addslashes(h($r['employee_name'])) ?>?')" 
                                                title="Click to toggle status">
                                            <i class="fas fa-circle me-1" style="font-size: 0.55rem;"></i>
                                            <?= h($r['status']) ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="text-center">
                                    <div class="d-inline-flex align-items-center justify-content-center gap-1 action-btn-group">
                                        <button type="button" 
                                                class="btn btn-sm btn-light action-btn btn-edit-employee" 
                                                onclick='openEditEmployeeModal(<?= $empJson ?>)' 
                                                title="Edit Employee">
                                            <i class="fas fa-edit text-warning"></i>
                                        </button>
                                        <form method="post" class="d-inline m-0 p-0" onsubmit="return confirm('Are you sure you want to delete employee: <?= addslashes(h($r['employee_name'])) ?>?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="employee_id" value="<?= (int)$r['employee_id'] ?>">
                                            <button type="submit" 
                                                    class="btn btn-sm btn-light action-btn btn-delete-employee" 
                                                    title="Delete Employee">
                                                <i class="fas fa-trash-alt text-danger"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr>
                                <td colspan="10" class="text-center py-5">
                                    <div class="py-4">
                                        <i class="fas fa-users-slash text-muted opacity-25 fa-3x mb-3"></i>
                                        <h6 class="text-secondary fw-bold">No Employees Found</h6>
                                        <p class="text-muted small mb-0">No records match your search or filter criteria.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</main>

<!-- =========================================================================
     MODAL: ADD / EDIT EMPLOYEE
     Strict Rule: Minimal header with Icon + Title + Subtitle + (X) close.
     Save/Update button strictly placed in modal footer.
========================================================================== -->
<div class="modal fade" id="employeeModal" tabindex="-1" aria-labelledby="employeeModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content erp-modal-content border-0 shadow">
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-3">
                    <div class="modal-icon-badge" style="width: 40px; height: 40px; border-radius: 10px; background: #ede7f6; color: #512da8; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-user-plus fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="employeeModalLabel">Add New Employee</h5>
                        <p class="text-muted small mb-0">Configure profile, system credentials, and process access</p>
                    </div>
                </div>
                <!-- MINIMAL HEADER: CLOSE BUTTON ONLY -->
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" id="employeeForm" class="needs-validation" onsubmit="return checkEmployeeFormPassword()">
                <input type="hidden" name="action" id="employee_action" value="add">
                <input type="hidden" name="employee_id" id="employee_id" value="">

                <div class="modal-body p-4">
                    <!-- SECTION 1: Personal Details -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">
                            <i class="fas fa-id-card text-primary me-2"></i>Personal & Contact Details
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary">Employee Name <span class="text-danger">*</span></label>
                                <input type="text" name="employee_name" id="employee_name" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary">Username <span class="text-danger">*</span></label>
                                <input type="text" name="employee_username" id="employee_username" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary">Email Address <span class="text-danger">*</span></label>
                                <input type="email" name="email" id="email" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary">Phone Number <span class="text-danger">*</span></label>
                                <input type="text" name="phone" id="phone" pattern="\+?\d{9,15}" class="form-control form-control-sm" placeholder="e.g. 9876543210" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary">Date of Birth <span class="text-danger">*</span></label>
                                <input type="date" name="date_of_birth" id="date_of_birth" class="form-control form-control-sm" value="<?= $default_dob ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary">Joining Date <span class="text-danger">*</span></label>
                                <input type="date" name="joining_date" id="joining_date" class="form-control form-control-sm" value="<?= $today ?>" required>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 2: System Credentials -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">
                            <i class="fas fa-key text-warning me-2"></i>Security & Credentials
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary">
                                    Password <span id="pwdReqAsterisk" class="text-danger">*</span>
                                    <span id="pwdHint" class="text-muted fw-normal small">(Min 6 chars)</span>
                                </label>
                                <div class="input-group input-group-sm">
                                    <input type="password" id="modal_password" name="password" class="form-control" minlength="6">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('modal_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary">
                                    Confirm Password <span id="cpwdReqAsterisk" class="text-danger">*</span>
                                </label>
                                <div class="input-group input-group-sm">
                                    <input type="password" id="modal_confirm_password" name="confirm_password" class="form-control" minlength="6">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('modal_confirm_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 3: Role & Reporting Structure -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">
                            <i class="fas fa-sitemap text-info me-2"></i>Role & Reporting Hierarchy
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-secondary">User Role <span class="text-danger">*</span></label>
                                <select name="user_role_id" id="modal_user_role_id" class="form-select form-select-sm" required onchange="handleModalRoleChange()">
                                    <option value="">Select Role</option>
                                    <?php foreach ($rolesList as $r): ?>
                                        <option value="<?= (int)$r['role_id'] ?>"><?= h($r['role_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6" id="modalRmContainer">
                                <label class="form-label small fw-semibold text-secondary">Reporting Manager <span class="text-danger">*</span></label>
                                <input type="text" name="reporting_manager" id="modal_reporting_manager" class="form-control form-control-sm" required>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 4: Process Assignment -->
                    <div class="mb-2">
                        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">
                            <i class="fas fa-diagram-project text-success me-2"></i>Process Permissions & Assignments
                        </h6>
                        
                        <!-- CE Group -->
                        <?php if (!empty($ce)): ?>
                            <div class="mb-3 p-2 bg-light rounded-2 border">
                                <span class="badge bg-secondary mb-2">CE Operations</span>
                                <div class="row g-2">
                                    <?php foreach ($ce as $row): ?>
                                        <div class="col-md-3 col-sm-4 col-6">
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input proc-checkbox" type="checkbox" name="process[]" value="<?= (int)$row['process_id'] ?>" id="proc_<?= (int)$row['process_id'] ?>">
                                                <label class="form-check-label small" for="proc_<?= (int)$row['process_id'] ?>">
                                                    <?= h($row['process_name']) ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- PAG Group -->
                        <?php if (!empty($pag)): ?>
                            <div class="mb-3 p-2 bg-light rounded-2 border">
                                <span class="badge bg-secondary mb-2">PAG & QC Operations</span>
                                <div class="row g-2">
                                    <?php foreach ($pag as $row): ?>
                                        <div class="col-md-3 col-sm-4 col-6">
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input proc-checkbox" type="checkbox" name="process[]" value="<?= (int)$row['process_id'] ?>" id="proc_<?= (int)$row['process_id'] ?>">
                                                <label class="form-check-label small" for="proc_<?= (int)$row['process_id'] ?>">
                                                    <?= h($row['process_name']) ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Other Processes -->
                        <?php if (!empty($others)): ?>
                            <div class="mb-2 p-2 bg-light rounded-2 border">
                                <span class="badge bg-secondary mb-2">Other Processes</span>
                                <div class="row g-2">
                                    <?php foreach ($others as $row): ?>
                                        <div class="col-md-3 col-sm-4 col-6">
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input proc-checkbox" type="checkbox" name="process[]" value="<?= (int)$row['process_id'] ?>" id="proc_<?= (int)$row['process_id'] ?>">
                                                <label class="form-check-label small" for="proc_<?= (int)$row['process_id'] ?>">
                                                    <?= h($row['process_name']) ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
                <div class="modal-footer bg-light border-top py-3 px-4 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary px-3 py-2 btn-sm" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary px-4 py-2 btn-sm shadow-sm" id="btnSubmitEmployee">
                        <i class="fas fa-save me-1"></i> Save Employee
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let employeeModalInstance = null;
let currentLoadedRm = '';

document.addEventListener('DOMContentLoaded', function() {
    const el = document.getElementById('employeeModal');
    if (el && typeof bootstrap !== 'undefined') {
        employeeModalInstance = new bootstrap.Modal(el);
    }
});

function togglePasswordVisibility(fieldId) {
    const input = document.getElementById(fieldId);
    if (input) {
        input.type = (input.type === 'password') ? 'text' : 'password';
    }
}

function checkEmployeeFormPassword() {
    const action = document.getElementById('employee_action').value;
    const pwd = document.getElementById('modal_password').value;
    const cpwd = document.getElementById('modal_confirm_password').value;

    if (action === 'add' || pwd !== '') {
        if (pwd.length < 6) {
            alert('Password must be at least 6 characters long.');
            return false;
        }
        if (pwd !== cpwd) {
            alert('Passwords do not match!');
            return false;
        }
    }
    return true;
}

async function handleModalRoleChange(preselectedRm = '') {
    const roleSelect = document.getElementById('modal_user_role_id');
    const container = document.getElementById('modalRmContainer');
    const roleId = parseInt(roleSelect.value) || 0;

    let targetRm = preselectedRm || currentLoadedRm || '';

    if (roleId === 5 || roleId === 8) {
        container.innerHTML = `
            <label class="form-label small fw-semibold text-secondary">Reporting Manager <span class="text-danger">*</span></label>
            <input type="text" class="form-control form-control-sm" value="Super Admin" disabled>
            <input type="hidden" name="reporting_manager" value="Super Admin">
        `;
    } else if (roleId === 4) {
        try {
            const resp = await fetch('employee_master.php?ajax=team_leads');
            const data = await resp.json();
            let options = data.map(name => `<option value="${name}" ${name === targetRm ? 'selected' : ''}>${name}</option>`).join('');
            container.innerHTML = `
                <label class="form-label small fw-semibold text-secondary">Reporting Manager <span class="text-danger">*</span></label>
                <select name="reporting_manager" class="form-select form-select-sm" required>
                    <option value="">Select Team Lead</option>
                    ${options}
                </select>
            `;
        } catch(e) {
            fallbackRmInput(targetRm);
        }
    } else if (roleId === 3) {
        try {
            const resp = await fetch('employee_master.php?ajax=admins');
            const data = await resp.json();
            let options = data.map(name => `<option value="${name}" ${name === targetRm ? 'selected' : ''}>${name}</option>`).join('');
            container.innerHTML = `
                <label class="form-label small fw-semibold text-secondary">Reporting Manager <span class="text-danger">*</span></label>
                <select name="reporting_manager" class="form-select form-select-sm" required>
                    <option value="">Select Admin</option>
                    ${options}
                </select>
            `;
        } catch(e) {
            fallbackRmInput(targetRm);
        }
    } else {
        fallbackRmInput(targetRm);
    }
}

function fallbackRmInput(val) {
    const container = document.getElementById('modalRmContainer');
    container.innerHTML = `
        <label class="form-label small fw-semibold text-secondary">Reporting Manager <span class="text-danger">*</span></label>
        <input type="text" name="reporting_manager" class="form-control form-control-sm" value="${val}" required>
    `;
}

function openAddEmployeeModal() {
    document.getElementById('employeeForm').reset();
    document.getElementById('employee_action').value = 'add';
    document.getElementById('employee_id').value = '';
    document.getElementById('employeeModalLabel').innerText = 'Add New Employee';
    document.getElementById('btnSubmitEmployee').innerHTML = '<i class="fas fa-save me-1"></i> Save Employee';

    document.getElementById('modal_password').required = true;
    document.getElementById('modal_confirm_password').required = true;
    document.getElementById('pwdReqAsterisk').style.display = 'inline';
    document.getElementById('cpwdReqAsterisk').style.display = 'inline';
    document.getElementById('pwdHint').innerText = '(Min 6 chars)';

    // Uncheck all process checkboxes
    document.querySelectorAll('.proc-checkbox').forEach(cb => cb.checked = false);

    currentLoadedRm = '';
    handleModalRoleChange('');

    if (!employeeModalInstance) {
        employeeModalInstance = new bootstrap.Modal(document.getElementById('employeeModal'));
    }
    employeeModalInstance.show();
}

function openEditEmployeeModal(data) {
    if (!data) return;
    document.getElementById('employeeForm').reset();
    document.getElementById('employee_action').value = 'edit';
    document.getElementById('employee_id').value = data.employee_id || '';
    document.getElementById('employee_name').value = data.employee_name || '';
    document.getElementById('employee_username').value = data.employee_username || '';
    document.getElementById('email').value = data.email || '';
    document.getElementById('phone').value = data.phone || '';
    document.getElementById('date_of_birth').value = data.date_of_birth || '';
    document.getElementById('joining_date').value = data.joining_date || '';

    // Password optional on edit
    document.getElementById('modal_password').value = '';
    document.getElementById('modal_confirm_password').value = '';
    document.getElementById('modal_password').required = false;
    document.getElementById('modal_confirm_password').required = false;
    document.getElementById('pwdReqAsterisk').style.display = 'none';
    document.getElementById('cpwdReqAsterisk').style.display = 'none';
    document.getElementById('pwdHint').innerText = '(Leave blank to keep unchanged)';

    // Role
    const roleSelect = document.getElementById('modal_user_role_id');
    roleSelect.value = data.user_role_id || '';

    currentLoadedRm = data.reporting_manager || '';
    handleModalRoleChange(currentLoadedRm);

    // Processes
    document.querySelectorAll('.proc-checkbox').forEach(cb => cb.checked = false);
    if (data.process) {
        const pids = data.process.split(',');
        pids.forEach(pid => {
            const cb = document.getElementById('proc_' + pid.trim());
            if (cb) cb.checked = true;
        });
    }

    document.getElementById('employeeModalLabel').innerText = 'Edit Employee: ' + (data.employee_name || '');
    document.getElementById('btnSubmitEmployee').innerHTML = '<i class="fas fa-save me-1"></i> Update Employee';

    if (!employeeModalInstance) {
        employeeModalInstance = new bootstrap.Modal(document.getElementById('employeeModal'));
    }
    employeeModalInstance.show();
}
</script>
