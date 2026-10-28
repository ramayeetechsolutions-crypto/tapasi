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

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

function redirect_with($params = []) {
    $base = 'systems.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $system_no = trim($_POST['system_no'] ?? '');
        $old_system_no = trim($_POST['old_system_no'] ?? '');
        $team = trim($_POST['team'] ?? '');
        $ip_address = trim($_POST['ip_address'] ?? '');
        $employee_name = trim($_POST['employee_name'] ?? '');
        $employee_id = trim($_POST['employee_id'] ?? '');
        $service_tag = trim($_POST['service_tag'] ?? '');
        $installation_id = trim($_POST['installation_id'] ?? '');
        $cabin_no = trim($_POST['cabin_no'] ?? '');
        $processor = trim($_POST['processor'] ?? '');
        $ram = trim($_POST['ram'] ?? '');
        $os = trim($_POST['os'] ?? '');
        $cpu_id = !empty($_POST['cpu_id']) ? (int)$_POST['cpu_id'] : null;
        $keyboard_id = !empty($_POST['keyboard_id']) ? (int)$_POST['keyboard_id'] : null;
        $mouse_id = !empty($_POST['mouse_id']) ? (int)$_POST['mouse_id'] : null;
        $remarks = trim($_POST['remarks'] ?? '');
        $status = $_POST['status'] ?? 'inactive';
        
        // Basic validation
        if ($system_no === '') {
            redirect_with(['msg' => 'System No is required.', 'type' => 'danger']);
        }
        
        if ($team === '') {
            redirect_with(['msg' => 'Team is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Insert new system - FIXED: Corrected parameter count
            $stmt = $mysqli->prepare("INSERT INTO tbl_systems (system_no, old_system_no, team, ip_address, employee_name, employee_id, service_tag, installation_id, cabin_no, processor, ram, os, cpu_id, keyboard_id, mouse_id, remarks, status, created_ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssssssssssssiiiiss", 
                $system_no, $old_system_no, $team, $ip_address, $employee_name, $employee_id, 
                $service_tag, $installation_id, $cabin_no, $processor, $ram, $os, 
                $cpu_id, $keyboard_id, $mouse_id, $remarks, $status, $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "System '$system_no' added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding system: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Update system - FIXED: Corrected parameter count
                $sql = "UPDATE tbl_systems SET 
                        system_no = ?, 
                        old_system_no = ?, 
                        team = ?, 
                        ip_address = ?, 
                        employee_name = ?, 
                        employee_id = ?, 
                        service_tag = ?, 
                        installation_id = ?, 
                        cabin_no = ?, 
                        processor = ?, 
                        ram = ?, 
                        os = ?, 
                        cpu_id = ?, 
                        keyboard_id = ?, 
                        mouse_id = ?, 
                        remarks = ?, 
                        status = ?, 
                        updated_ip = ?, 
                        updated_at = NOW() 
                        WHERE id = ?";
                
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssssssssssssiiiissi", 
                    $system_no, $old_system_no, $team, $ip_address, $employee_name, $employee_id,
                    $service_tag, $installation_id, $cabin_no, $processor, $ram, $os, 
                    $cpu_id, $keyboard_id, $mouse_id, $remarks, $status, 
                    $_SERVER['REMOTE_ADDR'], $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "System updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating system: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $system_no = '';
        
        if ($id > 0) {
            // Get system number for message
            $res = $mysqli->prepare("SELECT system_no FROM tbl_systems WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($system_no);
            $res->fetch();
            $res->close();
            
            // Delete system
            $del = $mysqli->prepare("DELETE FROM tbl_systems WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "System '$system_no' deleted", 'type' => 'warning']);
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editSystem = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_systems WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editSystem = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editSystem) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fteam = $_GET['team'] ?? '';
$fstatus = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'system_no';
$dir = strtolower($_GET['dir'] ?? 'asc');

// Build query with JOINs to get mouse, keyboard, cpu model names
$sql = "SELECT s.*, 
        m.model as mouse_model,
        k.model as keyboard_model,
        c.model as cpu_model
        FROM tbl_systems s
        LEFT JOIN tbl_mouse m ON s.mouse_id = m.id
        LEFT JOIN tbl_keyboard k ON s.keyboard_id = k.id
        LEFT JOIN tbl_cpu c ON s.cpu_id = c.id";
        
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(s.system_no LIKE ? OR s.employee_name LIKE ? OR s.employee_id LIKE ? OR s.ip_address LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if ($fteam !== '') {
    $cond[] = "s.team = ?";
    $params[] = $fteam;
    $types .= 's';
}

if ($fstatus !== '') {
    $cond[] = "s.status = ?";
    $params[] = $fstatus;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " WHERE " . implode(" AND ", $cond);
}

$sql .= " ORDER BY s.$sort $dir";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $systems = $stmt->get_result();
} else {
    $systems = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$systems) {
    $systems = false;
}

// Get dropdown data for form
$mice = $mysqli->query("SELECT id, model FROM tbl_mouse ORDER BY model ASC");
$keyboards = $mysqli->query("SELECT id, model FROM tbl_keyboard ORDER BY model ASC");
$cpus = $mysqli->query("SELECT id, model FROM tbl_cpu ORDER BY model ASC");
$teams = $mysqli->query("SELECT team_id, team_name FROM team_master WHERE status = 'Active' ORDER BY team_name ASC");

// Get employees for dropdown - from employee_master table
$employees = $mysqli->query("SELECT employee_id, employee_name FROM employee_master WHERE status = 'Active' ORDER BY employee_name ASC");
$employeeOptions = [];
if ($employees) {
    while($emp = $employees->fetch_assoc()) {
        $employeeOptions[] = $emp;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Systems Management</title>
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
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        .select2-container--default .select2-selection--single {
            height: 38px;
            padding: 6px 12px;
            border: 1px solid #ced4da;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
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
            <h4><i class="fas fa-desktop me-2"></i><?= $mode === 'edit' ? 'Edit System' : 'Add New System' ?></h4>
            <a href="systems.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editSystem['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="team" class="form-label">TEAM <span class="text-danger">*</span></label>
                                <select class="form-select" id="team" name="team" required>
                                    <option value="">Select Team</option>
                                    <?php while($team = $teams->fetch_assoc()): ?>
                                        <option value="<?= h($team['team_name']) ?>" <?= ($editSystem && $editSystem['team'] == $team['team_name']) ? 'selected' : '' ?>>
                                            <?= h($team['team_name']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="employee_name" class="form-label">Employee Name</label>
                                <select class="form-select employee-select" id="employee_name" name="employee_name" data-target="employee_id">
                                    <option value="">Select Employee</option>
                                    <?php foreach($employeeOptions as $emp): ?>
                                        <option value="<?= h($emp['employee_name']) ?>" 
                                                data-employee-id="<?= h($emp['employee_id']) ?>"
                                                <?= ($editSystem && $editSystem['employee_name'] == $emp['employee_name']) ? 'selected' : '' ?>>
                                            <?= h($emp['employee_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="employee_id" class="form-label">Employee ID</label>
                                <select class="form-select employee-select" id="employee_id" name="employee_id" data-target="employee_name">
                                    <option value="">Select Employee ID</option>
                                    <?php foreach($employeeOptions as $emp): ?>
                                        <option value="<?= h($emp['employee_id']) ?>" 
                                                data-employee-name="<?= h($emp['employee_name']) ?>"
                                                <?= ($editSystem && $editSystem['employee_id'] == $emp['employee_id']) ? 'selected' : '' ?>>
                                            <?= h($emp['employee_id']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="cabin_no" class="form-label">Cabin No.</label>
                                <input type="text" class="form-control" id="cabin_no" name="cabin_no"
                                       value="<?= h($editSystem['cabin_no'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="old_system_no" class="form-label">O. No</label>
                                <input type="text" class="form-control" id="old_system_no" name="old_system_no"
                                       value="<?= h($editSystem['old_system_no'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="system_no" class="form-label">N. No <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="system_no" name="system_no" 
                                       value="<?= h($editSystem['system_no'] ?? '') ?>" 
                                       placeholder="New System No" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="processor" class="form-label">Processor</label>
                                <input type="text" class="form-control" id="processor" name="processor"
                                       value="<?= h($editSystem['processor'] ?? '') ?>" 
                                       placeholder="e.g., Intel i5">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="ram" class="form-label">RAM</label>
                                <input type="text" class="form-control" id="ram" name="ram"
                                       value="<?= h($editSystem['ram'] ?? '') ?>" 
                                       placeholder="e.g., 8GB">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="cpu_id" class="form-label">CPU</label>
                                <select class="form-select" id="cpu_id" name="cpu_id">
                                    <option value="">Select CPU</option>
                                    <?php 
                                    if($cpus):
                                        $cpus->data_seek(0);
                                        while($cpu = $cpus->fetch_assoc()): 
                                    ?>
                                        <option value="<?= $cpu['id'] ?>" <?= ($editSystem && $editSystem['cpu_id'] == $cpu['id']) ? 'selected' : '' ?>>
                                            <?= h($cpu['model']) ?>
                                        </option>
                                    <?php endwhile;
                                    endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="os" class="form-label">OS</label>
                                <input type="text" class="form-control" id="os" name="os"
                                       value="<?= h($editSystem['os'] ?? '') ?>" 
                                       placeholder="e.g., Windows 11">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="keyboard_id" class="form-label">Keyboard</label>
                                <select class="form-select" id="keyboard_id" name="keyboard_id">
                                    <option value="">Select Keyboard</option>
                                    <?php 
                                    if($keyboards):
                                        $keyboards->data_seek(0);
                                        while($keyboard = $keyboards->fetch_assoc()): 
                                    ?>
                                        <option value="<?= $keyboard['id'] ?>" <?= ($editSystem && $editSystem['keyboard_id'] == $keyboard['id']) ? 'selected' : '' ?>>
                                            <?= h($keyboard['model']) ?>
                                        </option>
                                    <?php endwhile;
                                    endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="mouse_id" class="form-label">Mouse</label>
                                <select class="form-select" id="mouse_id" name="mouse_id">
                                    <option value="">Select Mouse</option>
                                    <?php 
                                    if($mice):
                                        $mice->data_seek(0);
                                        while($mouse = $mice->fetch_assoc()): 
                                    ?>
                                        <option value="<?= $mouse['id'] ?>" <?= ($editSystem && $editSystem['mouse_id'] == $mouse['id']) ? 'selected' : '' ?>>
                                            <?= h($mouse['model']) ?>
                                        </option>
                                    <?php endwhile;
                                    endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="service_tag" class="form-label">Monitor</label>
                                <input type="text" class="form-control" id="service_tag" name="service_tag"
                                       value="<?= h($editSystem['service_tag'] ?? '') ?>" 
                                       placeholder="Monitor/Service Tag">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="ip_address" class="form-label">IP Address</label>
                                <input type="text" class="form-control" id="ip_address" name="ip_address"
                                       value="<?= h($editSystem['ip_address'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="installation_id" class="form-label">Installation ID</label>
                                <input type="text" class="form-control" id="installation_id" name="installation_id"
                                       value="<?= h($editSystem['installation_id'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="active" <?= ($editSystem && $editSystem['status'] == 'active') ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= ($editSystem && $editSystem['status'] == 'inactive') ? 'selected' : '' ?>>Inactive</option>
                                    <option value="maintenance" <?= ($editSystem && $editSystem['status'] == 'maintenance') ? 'selected' : '' ?>>Maintenance</option>
                                    <option value="wfh" <?= ($editSystem && $editSystem['status'] == 'wfh') ? 'selected' : '' ?>>WFH</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label for="remarks" class="form-label">Remarks</label>
                                <textarea class="form-control" id="remarks" name="remarks" rows="2"><?= h($editSystem['remarks'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update System' : 'Save System' ?>
                        </button>
                        <a href="systems.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-desktop me-2"></i>Systems Management</h4>
            <div class="btn-group">
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-import me-1"></i> Import
                </button>
                <a href="systems.php?mode=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Add System
                </a>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search systems, employees..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="team" class="form-select form-select-sm">
                            <option value="">All Teams</option>
                            <?php 
                            if($teams):
                                $teams->data_seek(0);
                                while($team = $teams->fetch_assoc()): 
                            ?>
                                <option value="<?= h($team['team_name']) ?>" <?= $fteam === $team['team_name'] ? 'selected' : '' ?>>
                                    <?= h($team['team_name']) ?>
                                </option>
                            <?php endwhile;
                            endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="active" <?= $fstatus === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $fstatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            <option value="maintenance" <?= $fstatus === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                            <option value="wfh" <?= $fstatus === 'wfh' ? 'selected' : '' ?>>WFH</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="system_no" <?= $sort === 'system_no' ? 'selected' : '' ?>>Sort by System No</option>
                            <option value="team" <?= $sort === 'team' ? 'selected' : '' ?>>Sort by Team</option>
                            <option value="employee_name" <?= $sort === 'employee_name' ? 'selected' : '' ?>>Sort by Employee</option>
                            <option value="status" <?= $sort === 'status' ? 'selected' : '' ?>>Sort by Status</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="dir" class="form-select form-select-sm">
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option>
                        </select>
                    </div>
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="systems.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Systems Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>TEAM</th>
                                <th>Employee Name</th>
                                <th>Employee ID</th>
                                <th>Cabin No.</th>
                                <th>O. No</th>
                                <th>N. No</th>
                                <th>OS</th>
                                <th>Processor</th>
                                <th>RAM</th>
                                <th>CPU</th>
                                <th>Monitor</th>
                                <th>Keyboard</th>
                                <th>Mouse</th>
                                <th>Status</th>
                                <th>Remarks</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($systems && $systems->num_rows > 0): ?>
                                <?php while($row = $systems->fetch_assoc()): ?>
                                <tr>
                                    <td><?= h($row['team']) ?></td>
                                    <td><?= h($row['employee_name']) ?></td>
                                    <td><?= h($row['employee_id']) ?></td>
                                    <td><?= h($row['cabin_no']) ?></td>
                                    <td><?= h($row['old_system_no']) ?></td>
                                    <td class="fw-semibold"><?= h($row['system_no']) ?></td>
                                    <td><?= h($row['os']) ?></td>
                                    <td><?= h($row['processor']) ?></td>
                                    <td><?= h($row['ram']) ?></td>
                                    <td><?= h($row['cpu_model']) ?></td>
                                    <td><?= h($row['service_tag']) ?></td>
                                    <td><?= h($row['keyboard_model']) ?></td>
                                    <td><?= h($row['mouse_model']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= 
                                            $row['status'] === 'active' ? 'success' : 
                                            ($row['status'] === 'inactive' ? 'secondary' : 
                                            ($row['status'] === 'maintenance' ? 'warning' : 'info')) ?>">
                                            <?= ucfirst($row['status']) ?>
                                        </span>
                                    </td>
                                    <td><small><?= h($row['remarks']) ?></small></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="systems.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Delete <?= h($row['system_no']) ?>?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete">
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
                                            <i class="fas fa-desktop fa-3x mb-3"></i>
                                            <h5>No systems found</h5>
                                            <p class="mb-0">Get started by adding your first system.</p>
                                            <a href="systems.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add System
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
<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="import_systems.php" method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Import Systems</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Upload Excel/CSV File</label>
                        <input type="file" name="import_file" class="form-control" required accept=".csv, .xlsx">
                        <div class="form-text">Allowed formats: .csv, .xlsx</div>
                    </div>
                    <div class="alert alert-info py-2">
                        <small><i class="fas fa-info-circle"></i> Columns should match: Team, Name, Emp ID, Cabin, IP, etc.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="import_data" class="btn btn-primary">Upload & Import</button>
                </div>
            </form>
        </div>
    </div>
</div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert.isConnected) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                }, 5000);
            });

            // Auto-submit ONLY for the search/filter form (method="get")
            const searchForm = document.querySelector('form[method="get"]');
            if (searchForm) {
                const filterSelects = searchForm.querySelectorAll('select[name="team"], select[name="status"], select[name="sort"], select[name="dir"]');
                filterSelects.forEach(select => {
                    select.addEventListener('change', function() {
                        searchForm.submit();
                    });
                });
            }

            // Initialize Select2 for employee dropdowns
            $('.employee-select').select2({
                placeholder: 'Select or type to search',
                allowClear: true,
                width: '100%'
            });

            // Employee name and ID synchronization
            // Employee name and ID synchronization
            // When Name is selected, find the ID from data-attribute and set it
            $('#employee_name').on('select2:select', function (e) {
                var selectedData = e.params.data;
                // The PHP sets data-employee-id on the option
                var empId = $(selectedData.element).data('employee-id'); 
                
                if (empId) {
                    // Update the ID dropdown without triggering a loop
                    $('#employee_id').val(empId).trigger('change.select2');
                }
            });

            // When ID is selected, find the Name from data-attribute and set it
            $('#employee_id').on('select2:select', function (e) {
                var selectedData = e.params.data;
                // The PHP sets data-employee-name on the option
                var empName = $(selectedData.element).data('employee-name');
                
                if (empName) {
                    // Update the Name dropdown without triggering a loop
                    $('#employee_name').val(empName).trigger('change.select2');
                }
            });

            // Clear both fields when one is cleared
            $('#employee_name').on('select2:clear', function () {
                $('#employee_id').val('').trigger('change.select2');
            });

            $('#employee_id').on('select2:clear', function () {
                $('#employee_name').val('').trigger('change.select2');
            });
        });
    </script>
</body>
</html>