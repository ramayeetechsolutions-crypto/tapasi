<?php
if (
    isset($_GET['ajax']) &&
    $_GET['ajax'] === 'get_employee_details' &&
    isset($_GET['id_number'])
) {
    ini_set('display_errors', 1); // Show errors for debugging (set back to 0 later)
    error_reporting(E_ALL);

    require 'db.php';
    header('Content-Type: application/json');

    $id_number = trim($_GET['id_number']);
    $response = ['success' => false, 'id_number' => $id_number];

    try {
        $stmt = $mysqli->prepare("SELECT employee_name FROM employee_master WHERE employee_username = ?");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
        $stmt->bind_param("s", $id_number);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $employee = $result->fetch_assoc();
            $response['success'] = true;
            $response['employee_name'] = $employee['employee_name'];
        } else {
            $response['message'] = 'Employee not found in employee_master';
        }
        $stmt->close();
    } catch (Exception $e) {
        $response['message'] = 'PHP Error: ' . $e->getMessage();
    }

    echo json_encode($response);
    exit;
}
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
    $base = 'wfh.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}
// Get teams from team_master for dropdown
$teams = [];
$teamResult = $mysqli->query("SELECT team_name FROM team_master ORDER BY team_name");
if ($teamResult) {
    while ($team = $teamResult->fetch_assoc()) {
        $teams[] = $team['team_name'];
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $id_number = trim($_POST['id_number'] ?? '');
        $wfh_no = trim($_POST['wfh_no'] ?? '');
        $system_no = trim($_POST['system_no'] ?? '');
        $employee_name = trim($_POST['employee_name'] ?? '');
        $team = trim($_POST['team'] ?? '');
        $shift = trim($_POST['shift'] ?? '');
        $wfh_type = $_POST['wfh_type'] ?? 'permanent';
        $allocation_date = trim($_POST['allocation_date'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        // Basic validation
        if ($id_number === '') {
            redirect_with(['msg' => 'ID Number is required.', 'type' => 'danger', 'tab' => $wfh_type]);
        }
        
        if ($employee_name === '') {
            redirect_with(['msg' => 'Employee Name is required.', 'type' => 'danger', 'tab' => $wfh_type]);
        }
        
        if ($action === 'add') {
            // Insert new WFH record
            $stmt = $mysqli->prepare("INSERT INTO tbl_wfh (wfh_type, id_number, wfh_no, system_no, employee_name, team, shift, allocation_date, status, created_ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssssssssss", $wfh_type, $id_number, $wfh_no, $system_no, $employee_name, $team, $shift, $allocation_date, $status, $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "WFH record for '$employee_name' added successfully", 'type' => 'success', 'tab' => $wfh_type]);
            } else {
                redirect_with(['msg' => 'Error adding WFH record: ' . $stmt->error, 'type' => 'danger', 'tab' => $wfh_type]);
            }
            $stmt->close();
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Update WFH record
                $sql = "UPDATE tbl_wfh SET 
                        wfh_type = ?, 
                        id_number = ?, 
                        wfh_no = ?, 
                        system_no = ?, 
                        employee_name = ?, 
                        team = ?, 
                        shift = ?, 
                        allocation_date = ?, 
                        status = ?,
                        updated_ip = ?,
                        updated_at = NOW()
                        WHERE id = ?";
                
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssssssssssi", $wfh_type, $id_number, $wfh_no, $system_no, $employee_name, $team, $shift, $allocation_date, $status, $_SERVER['REMOTE_ADDR'], $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "WFH record updated successfully", 'type' => 'success', 'tab' => $wfh_type]);
                } else {
                    redirect_with(['msg' => 'Error updating WFH record: ' . $stmt->error, 'type' => 'danger', 'tab' => $wfh_type]);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $employee_name = '';
        $wfh_type = $_POST['wfh_type'] ?? 'permanent';
        
        if ($id > 0) {
            // Get name for message
            $res = $mysqli->prepare("SELECT employee_name FROM tbl_wfh WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($employee_name);
            $res->fetch();
            $res->close();
            
            // Delete WFH record
            $del = $mysqli->prepare("DELETE FROM tbl_wfh WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "WFH record for '$employee_name' deleted", 'type' => 'warning', 'tab' => $wfh_type]);
        }
    }
    
    redirect_with();
}

// Get mode and tab
$mode = $_GET['mode'] ?? 'view';
$activeTab = $_GET['tab'] ?? 'permanent';
$editWFH = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_wfh WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editWFH = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($editWFH) {
            $activeTab = $editWFH['wfh_type'];
        }
    }
    if (!$editWFH) $mode = 'view';
}

// Get search parameters
$q = trim($_GET['q'] ?? '');
$fteam = $_GET['team'] ?? '';
$fstatus = $_GET['status'] ?? '';

// Query for Permanent WFH
$sqlPermanent = "SELECT * FROM tbl_wfh WHERE wfh_type = 'permanent'";

$condPermanent = [];
$paramsPermanent = [];
$typesPermanent = '';

if ($q !== '') {
    $condPermanent[] = "(id_number LIKE ? OR employee_name LIKE ? OR wfh_no LIKE ?)";
    $like = "%$q%";
    $paramsPermanent[] = $like;
    $paramsPermanent[] = $like;
    $paramsPermanent[] = $like;
    $typesPermanent .= 'sss';
}

if ($fteam !== '') {
    $condPermanent[] = "team = ?";
    $paramsPermanent[] = $fteam;
    $typesPermanent .= 's';
}

if ($fstatus !== '') {
    $condPermanent[] = "status = ?";
    $paramsPermanent[] = $fstatus;
    $typesPermanent .= 's';
}

if (!empty($condPermanent)) {
    $sqlPermanent .= " AND " . implode(" AND ", $condPermanent);
}

$sqlPermanent .= " ORDER BY id_number ASC";

// Execute Permanent WFH query
if (!empty($typesPermanent)) {
    $stmtP = $mysqli->prepare($sqlPermanent);
    $stmtP->bind_param($typesPermanent, ...$paramsPermanent);
    $stmtP->execute();
    $permanentWFH = $stmtP->get_result();
} else {
    $permanentWFH = $mysqli->query($sqlPermanent);
}

// Query for Temporary WFH
$sqlTemporary = "SELECT * FROM tbl_wfh WHERE wfh_type = 'temporary'";

$condTemporary = [];
$paramsTemporary = [];
$typesTemporary = '';

if ($q !== '') {
    $condTemporary[] = "(id_number LIKE ? OR employee_name LIKE ? OR wfh_no LIKE ?)";
    $like = "%$q%";
    $paramsTemporary[] = $like;
    $paramsTemporary[] = $like;
    $paramsTemporary[] = $like;
    $typesTemporary .= 'sss';
}

if ($fteam !== '') {
    $condTemporary[] = "team = ?";
    $paramsTemporary[] = $fteam;
    $typesTemporary .= 's';
}

if ($fstatus !== '') {
    $condTemporary[] = "status = ?";
    $paramsTemporary[] = $fstatus;
    $typesTemporary .= 's';
}

if (!empty($condTemporary)) {
    $sqlTemporary .= " AND " . implode(" AND ", $condTemporary);
}

$sqlTemporary .= " ORDER BY id_number ASC";

// Execute Temporary WFH query
if (!empty($typesTemporary)) {
    $stmtT = $mysqli->prepare($sqlTemporary);
    $stmtT->bind_param($typesTemporary, ...$paramsTemporary);
    $stmtT->execute();
    $temporaryWFH = $stmtT->get_result();
} else {
    $temporaryWFH = $mysqli->query($sqlTemporary);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WFH Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1600px;
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
        .nav-tabs .nav-link {
            color: #495057;
            font-weight: 500;
        }
        .nav-tabs .nav-link.active {
            color: #0d6efd;
            font-weight: 600;
        }
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
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
            <h4><i class="fas fa-home me-2"></i><?= $mode === 'edit' ? 'Edit WFH Record' : 'Add New WFH Record' ?></h4>
            <a href="wfh.php?tab=<?= h($activeTab) ?>" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editWFH['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="wfh_type" class="form-label">WFH Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="wfh_type" name="wfh_type" required>
                                    <option value="permanent" <?= ($editWFH && $editWFH['wfh_type'] == 'permanent') ? 'selected' : '' ?>>Permanent WFH</option>
                                    <option value="temporary" <?= ($editWFH && $editWFH['wfh_type'] == 'temporary') ? 'selected' : '' ?>>Temporary WFH</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="id_number" class="form-label">ID No. <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="id_number" name="id_number" 
                                       value="<?= h($editWFH['id_number'] ?? '') ?>" 
                                       placeholder="Enter Employee ID" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="wfh_no" class="form-label">WFH No.</label>
                                <input type="text" class="form-control" id="wfh_no" name="wfh_no"
                                       value="<?= h($editWFH['wfh_no'] ?? '') ?>" 
                                       placeholder="Enter WFH Number">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="system_no" class="form-label">System No.</label>
                                <input type="text" class="form-control" id="system_no" name="system_no"
                                       value="<?= h($editWFH['system_no'] ?? '') ?>" 
                                       placeholder="Will auto-fill from system allocation" readonly>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="employee_name" class="form-label">Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="employee_name" name="employee_name"
                                       value="<?= h($editWFH['employee_name'] ?? '') ?>" 
                                       placeholder="Will auto-fill from employee master" readonly required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="team" class="form-label">Team</label>
                                <select class="form-select" id="team" name="team">
                                    <option value="">Select Team</option>
                                    <?php foreach($teams as $team): ?>
                                    <option value="<?= h($team) ?>" <?= ($editWFH && $editWFH['team'] == $team) ? 'selected' : '' ?>>
                                        <?= h($team) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="shift" class="form-label">Shift</label>
                                <select class="form-select" id="shift" name="shift">
                                    <option value="">Select Shift</option>
                                    <!-- Added Office Option Below -->
                                    <option value="Office" <?= ($editWFH && $editWFH['shift'] == 'Office') ? 'selected' : '' ?>>Office</option>
                                    <option value="WFH" <?= ($editWFH && $editWFH['shift'] == 'WFH') ? 'selected' : '' ?>>WFH</option>
                                    <option value="T-WFH" <?= ($editWFH && $editWFH['shift'] == 'T-WFH') ? 'selected' : '' ?>>T-WFH</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="allocation_date" class="form-label">Allocation Date</label>
                                <input type="date" class="form-control" id="allocation_date" name="allocation_date"
                                       value="<?= h($editWFH['allocation_date'] ?? date('Y-m-d')) ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="active" <?= ($editWFH && $editWFH['status'] == 'active') ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= ($editWFH && $editWFH['status'] == 'inactive') ? 'selected' : '' ?>>Inactive</option>
                                    <option value="completed" <?= ($editWFH && $editWFH['status'] == 'completed') ? 'selected' : '' ?>>Completed</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update WFH Record' : 'Save WFH Record' ?>
                        </button>
                        <a href="wfh.php?tab=<?= h($activeTab) ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode with Tabs -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-home me-2"></i>WFH Management</h4>
            <a href="wfh.php?mode=add&tab=<?= h($activeTab) ?>" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add WFH Record
            </a>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <input type="hidden" name="tab" value="<?= h($activeTab) ?>">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search WFH records..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="team" class="form-select form-select-sm">
                            <option value="">All Teams</option>
                            <?php foreach($teams as $team): ?>
                            <option value="<?= h($team) ?>" <?= $fteam === $team ? 'selected' : '' ?>>
                                <?= h($team) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="active" <?= $fstatus === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $fstatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            <option value="completed" <?= $fstatus === 'completed' ? 'selected' : '' ?>>Completed</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="wfh.php?tab=<?= h($activeTab) ?>" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabs Navigation -->
        <ul class="nav nav-tabs mb-3" id="wfhTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link <?= $activeTab === 'permanent' ? 'active' : '' ?>" 
                   href="?tab=permanent" role="tab">
                    <i class="fas fa-home me-1"></i> Permanent WFH
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link <?= $activeTab === 'temporary' ? 'active' : '' ?>" 
                   href="?tab=temporary" role="tab">
                    <i class="fas fa-clock me-1"></i> Temporary WFH
                </a>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content">
            <!-- Permanent WFH Tab -->
            <div class="tab-pane fade <?= $activeTab === 'permanent' ? 'show active' : '' ?>">
                <div class="card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID No.</th>
                                        <th>WFH No.</th>
                                        <th>System No.</th>
                                        <th>Name</th>
                                        <th>Team</th>
                                        <th>Shift</th>
                                        <th>Allocation Date</th>
                                        <th>Status</th>
                                        <th width="120" class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if($permanentWFH && $permanentWFH->num_rows > 0): ?>
                                        <?php while($row = $permanentWFH->fetch_assoc()): ?>
                                        <tr>
                                            <td class="fw-semibold"><?= h($row['id_number']) ?></td>
                                            <td><?= h($row['wfh_no']) ?></td>
                                            <td><?= h($row['system_no']) ?></td>
                                            <td><?= h($row['employee_name']) ?></td>
                                            <td><?= h($row['team']) ?></td>
                                            <td><span class="badge bg-info"><?= h($row['shift']) ?></span></td>
                                            <td><?= formatDate($row['allocation_date']) ?></td>
                                            <td>
                                                <span class="badge bg-<?= 
                                                    $row['status'] === 'active' ? 'success' : 
                                                    ($row['status'] === 'inactive' ? 'secondary' : 'warning') ?>">
                                                    <?= ucfirst($row['status']) ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <a href="wfh.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                                       class="btn btn-warning" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <form method="post" style="display:inline;" 
                                                          onsubmit="return confirm('Delete WFH record for <?= h($row['employee_name']) ?>?')">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                        <input type="hidden" name="wfh_type" value="permanent">
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
                                            <td colspan="9" class="text-center py-5">
                                                <div class="text-muted">
                                                    <i class="fas fa-home fa-3x mb-3"></i>
                                                    <h5>No permanent WFH records found</h5>
                                                    <p class="mb-0">Add permanent WFH employees here.</p>
                                                    <a href="wfh.php?mode=add&tab=permanent" class="btn btn-primary mt-2">
                                                        <i class="fas fa-plus me-1"></i> Add Permanent WFH
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
            </div>

            <!-- Temporary WFH Tab -->
            <div class="tab-pane fade <?= $activeTab === 'temporary' ? 'show active' : '' ?>">
                <div class="card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID No.</th>
                                        <th>WFH No.</th>
                                        <th>System No.</th>
                                        <th>Name</th>
                                        <th>Team</th>
                                        <th>Shift</th>
                                        <th>Allocation Date</th>
                                        <th>Status</th>
                                        <th width="120" class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if($temporaryWFH && $temporaryWFH->num_rows > 0): ?>
                                        <?php while($row = $temporaryWFH->fetch_assoc()): ?>
                                        <tr>
                                            <td class="fw-semibold"><?= h($row['id_number']) ?></td>
                                            <td><?= h($row['wfh_no']) ?></td>
                                            <td><?= h($row['system_no']) ?></td>
                                            <td><?= h($row['employee_name']) ?></td>
                                            <td><?= h($row['team']) ?></td>
                                            <td><span class="badge bg-warning text-dark"><?= h($row['shift']) ?></span></td>
                                            <td><?= formatDate($row['allocation_date']) ?></td>
                                            <td>
                                                <span class="badge bg-<?= 
                                                    $row['status'] === 'active' ? 'success' : 
                                                    ($row['status'] === 'inactive' ? 'secondary' : 'warning') ?>">
                                                    <?= ucfirst($row['status']) ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <a href="wfh.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                                       class="btn btn-warning" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <form method="post" style="display:inline;" 
                                                          onsubmit="return confirm('Delete WFH record for <?= h($row['employee_name']) ?>?')">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                        <input type="hidden" name="wfh_type" value="temporary">
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
                                            <td colspan="9" class="text-center py-5">
                                                <div class="text-muted">
                                                    <i class="fas fa-clock fa-3x mb-3"></i>
                                                    <h5>No temporary WFH records found</h5>
                                                    <p class="mb-0">Add temporary WFH employees here.</p>
                                                    <a href="wfh.php?mode=add&tab=temporary" class="btn btn-primary mt-2">
                                                        <i class="fas fa-plus me-1"></i> Add Temporary WFH
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
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
                const filterSelects = searchForm.querySelectorAll('select[name="team"], select[name="status"]');
                filterSelects.forEach(select => {
                    select.addEventListener('change', function() {
                        searchForm.submit();
                    });
                });
            }

            // Auto-fill employee details when ID Number is entered
            const idNumberField = document.getElementById('id_number');
            if (idNumberField) {
                idNumberField.addEventListener('blur', function() {
                    const idNumber = this.value.trim();
                    if (idNumber) {
                        // Show loading state
                        const employeeNameField = document.getElementById('employee_name');
                        const teamField = document.getElementById('team');
                        const systemNoField = document.getElementById('system_no');
                        
                        employeeNameField.value = 'Loading...';
                        systemNoField.value = 'Loading...';

                        // Fetch employee details from the same file
                        fetch(`wfh.php?ajax=get_employee_details&id_number=${encodeURIComponent(idNumber)}`)
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    // Auto-fill employee name
                                    if (data.employee_name) {
                                        employeeNameField.value = data.employee_name;
                                    }
                                    
                                    // Auto-fill team if not already set
                                    if (data.team && (!teamField.value || teamField.value === '')) {
                                        teamField.value = data.team;
                                    }
                                    
                                    // Auto-fill system number
                                    if (data.system_no) {
                                        systemNoField.value = data.system_no;
                                    } else {
                                        systemNoField.value = 'No system allocated';
                                    }
                                } else {
                                    employeeNameField.value = '';
                                    systemNoField.value = '';
                                    if (data.message) {
                                        alert(data.message);
                                    }
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                employeeNameField.value = '';
                                systemNoField.value = '';
                                alert('Error fetching employee details');
                            });
                    }
                });
            }
        });
    </script>
</body>
</html>