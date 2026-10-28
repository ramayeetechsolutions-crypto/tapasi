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
    if (empty($d) || $d === '0000-00-00') return '—';
    return date('d-m-Y', strtotime($d));
}

function calculateAgeMonths($date) {
    if (empty($date) || $date === '0000-00-00') return 0;
    try {
        $installDate = new DateTime($date);
        $today = new DateTime();
        $interval = $today->diff($installDate);
        return ($interval->y * 12) + $interval->m;
    } catch (Exception $e) {
        return 0;
    }
}

function getBatteryStatus($installDate, $warranty) {
    if (empty($installDate) || $installDate === '0000-00-00') {
        return ['status' => 'no_date', 'message' => 'No date', 'class' => 'secondary'];
    }
    
    // Parse warranty (assumes format like "2 years" or "24 months")
    $warrantyYears = 2; // default
    if (!empty($warranty)) {
        if (preg_match('/(\d+)\s*year/i', $warranty, $matches)) {
            $warrantyYears = (int)$matches[1];
        } elseif (preg_match('/(\d+)\s*month/i', $warranty, $matches)) {
            $warrantyYears = (int)$matches[1] / 12;
        }
    }
    
    $installDate = new DateTime($installDate);
    $warrantyEnd = clone $installDate;
    $warrantyEnd->modify("+{$warrantyYears} years");
    $today = new DateTime();
    
    if ($today > $warrantyEnd) {
        return ['status' => 'expired', 'message' => 'Expired', 'class' => 'danger'];
    }
    
    $daysRemaining = $today->diff($warrantyEnd)->days;
    
    if ($daysRemaining <= 15) {
        return ['status' => 'critical', 'message' => "Ends in $daysRemaining days", 'class' => 'danger'];
    } elseif ($daysRemaining <= 60) {
        return ['status' => 'warning', 'message' => "Ends in $daysRemaining days", 'class' => 'warning'];
    } else {
        return ['status' => 'good', 'message' => 'Valid', 'class' => 'success'];
    }
}

function getAgeStatus($months) {
    if ($months >= 36) return ['class' => 'danger', 'label' => 'Critical - Replace Now'];
    if ($months >= 30) return ['class' => 'warning', 'label' => 'Warning - Replace Soon'];
    if ($months >= 24) return ['class' => 'info', 'label' => 'Info - Monitor'];
    return ['class' => 'success', 'label' => 'Good'];
}

function redirect_with($params = []) {
    $base = 'ups_assets.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        // UPS Information
        $ups_no = trim($_POST['ups_no'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $team = trim($_POST['team'] ?? '');
        $assigned_user = trim($_POST['assigned_user'] ?? '');
        $ups_status = $_POST['ups_status'] ?? 'Active';
        
        // Purchase Information
        $purchase_date = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $vendor = trim($_POST['vendor'] ?? '');
        $payment_ref = trim($_POST['payment_ref'] ?? '');
        
        // Battery 1 Information
        $battery1_serial = trim($_POST['battery1_serial'] ?? '');
        $battery1_purchase_date = !empty($_POST['battery1_purchase_date']) ? $_POST['battery1_purchase_date'] : null;
        $battery1_warranty = trim($_POST['battery1_warranty'] ?? '');
        $battery1_install_date = !empty($_POST['battery1_install_date']) ? $_POST['battery1_install_date'] : null;
        
        // Battery 2 Information
        $battery2_serial = trim($_POST['battery2_serial'] ?? '');
        $battery2_purchase_date = !empty($_POST['battery2_purchase_date']) ? $_POST['battery2_purchase_date'] : null;
        $battery2_warranty = trim($_POST['battery2_warranty'] ?? '');
        $battery2_install_date = !empty($_POST['battery2_install_date']) ? $_POST['battery2_install_date'] : null;
        
        $remarks = trim($_POST['remarks'] ?? '');
        
        if ($ups_no === '') {
            redirect_with(['msg' => 'UPS Number is required', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            $stmt = $mysqli->prepare("INSERT INTO tbl_ups_assets 
                (ups_no, model, team, assigned_user, ups_status, purchase_date, vendor, payment_ref, 
                 battery1_serial, battery1_purchase_date, battery1_warranty, battery1_install_date,
                 battery2_serial, battery2_purchase_date, battery2_warranty, battery2_install_date,
                 remarks, created_at, created_ip) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            
            if ($stmt) {
                $stmt->bind_param("ssssssssssssssssss", 
                    $ups_no, $model, $team, $assigned_user, $ups_status, 
                    $purchase_date, $vendor, $payment_ref,
                    $battery1_serial, $battery1_purchase_date, $battery1_warranty, $battery1_install_date,
                    $battery2_serial, $battery2_purchase_date, $battery2_warranty, $battery2_install_date,
                    $remarks, $_SERVER['REMOTE_ADDR']
                );
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "UPS added successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => "Error: " . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $mysqli->prepare("UPDATE tbl_ups_assets SET 
                    ups_no=?, model=?, team=?, assigned_user=?, ups_status=?,
                    purchase_date=?, vendor=?, payment_ref=?,
                    battery1_serial=?, battery1_purchase_date=?, battery1_warranty=?, battery1_install_date=?,
                    battery2_serial=?, battery2_purchase_date=?, battery2_warranty=?, battery2_install_date=?,
                    remarks=?, updated_at=NOW(), updated_ip=?
                    WHERE id=?");
                
                if ($stmt) {
                    $stmt->bind_param("sssssssssssssssssssi", 
                        $ups_no, $model, $team, $assigned_user, $ups_status,
                        $purchase_date, $vendor, $payment_ref,
                        $battery1_serial, $battery1_purchase_date, $battery1_warranty, $battery1_install_date,
                        $battery2_serial, $battery2_purchase_date, $battery2_warranty, $battery2_install_date,
                        $remarks, $_SERVER['REMOTE_ADDR'], $id
                    );
                    
                    if ($stmt->execute()) {
                        redirect_with(['msg' => "UPS updated successfully", 'type' => 'success']);
                    } else {
                        redirect_with(['msg' => "Error: " . $stmt->error, 'type' => 'danger']);
                    }
                    $stmt->close();
                }
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $mysqli->query("DELETE FROM tbl_ups_assets WHERE id=$id");
            redirect_with(['msg' => "Record deleted successfully", 'type' => 'warning']);
        }
    }
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editData = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $result = $mysqli->query("SELECT * FROM tbl_ups_assets WHERE id=$id");
        if ($result) $editData = $result->fetch_assoc();
    }
    if (!$editData) $mode = 'view';
}

// Search and filter
$q = trim($_GET['q'] ?? '');
$team_filter = $_GET['team'] ?? '';
$alert_filter = $_GET['alert'] ?? '';

$sql = "SELECT * FROM tbl_ups_assets WHERE 1=1";
$params = [];
$types = '';

if ($q !== '') {
    $sql .= " AND (ups_no LIKE ? OR model LIKE ? OR battery1_serial LIKE ? OR battery2_serial LIKE ?)";
    $like = "%$q%";
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= 'ssss';
}

if ($team_filter !== '') {
    $sql .= " AND team = ?";
    $params[] = $team_filter;
    $types .= 's';
}

$sql .= " ORDER BY ups_no ASC";

// Execute query
if (!empty($params)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $mysqli->query($sql);
}

// Get teams for dropdown
$teams = $mysqli->query("SELECT team_name FROM team_master ORDER BY team_name");

// Calculate statistics
$stats = ['critical' => 0, 'warning' => 0, 'info' => 0, 'total' => 0];
$warranty_alerts = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $age1 = calculateAgeMonths($row['battery1_install_date']);
        $age2 = calculateAgeMonths($row['battery2_install_date']);
        $maxAge = max($age1, $age2);
        
        if ($maxAge >= 36) $stats['critical']++;
        elseif ($maxAge >= 30) $stats['warning']++;
        elseif ($maxAge >= 24) $stats['info']++;
        $stats['total']++;
        
        // Check warranty alerts
        if (!empty($row['battery1_serial']) && !empty($row['battery1_install_date'])) {
            $status = getBatteryStatus($row['battery1_install_date'], $row['battery1_warranty']);
            if ($status['status'] === 'critical' || $status['status'] === 'warning') {
                $warranty_alerts[] = "{$row['ups_no']} - Battery 1: {$status['message']}";
            }
        }
        if (!empty($row['battery2_serial']) && !empty($row['battery2_install_date'])) {
            $status = getBatteryStatus($row['battery2_install_date'], $row['battery2_warranty']);
            if ($status['status'] === 'critical' || $status['status'] === 'warning') {
                $warranty_alerts[] = "{$row['ups_no']} - Battery 2: {$status['message']}";
            }
        }
    }
    $result->data_seek(0); // Reset pointer
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UPS & Battery Asset Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .main-content { padding: 20px; max-width: 1800px; margin: 0 auto; }
        
        .stats-card {
            border-left: 4px solid;
            padding: 15px;
            border-radius: 8px;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
            margin-bottom: 20px;
        }
        .stats-card.critical { border-left-color: #dc3545; }
        .stats-card.warning { border-left-color: #ffc107; }
        .stats-card.info { border-left-color: #17a2b8; }
        .stats-card.total { border-left-color: #0d6efd; }
        .stats-card h3 { margin: 0; font-size: 2rem; font-weight: bold; }
        
        .table th {
            background-color: #2c3e50;
            color: white;
            font-size: 12px;
            white-space: nowrap;
        }
        .table td { vertical-align: middle; font-size: 13px; }
        
        .battery-block {
            padding: 5px;
            border-left: 3px solid #dee2e6;
            margin-bottom: 5px;
        }
        .battery-block:last-child { margin-bottom: 0; }
        
        .warranty-badge {
            font-size: 10px;
            margin-left: 5px;
        }
        
        .alert-panel {
            max-height: 200px;
            overflow-y: auto;
            background-color: #fff3cd;
            border: 1px solid #ffeeba;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .form-section-title {
            background-color: #e9ecef;
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show">
            <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Warranty Alerts -->
        <?php if (!empty($warranty_alerts)): ?>
        <div class="alert-panel">
            <h6 class="alert-heading"><i class="fas fa-exclamation-triangle text-warning me-2"></i>Warranty Alerts</h6>
            <?php foreach($warranty_alerts as $alert): ?>
                <div class="small mb-1">• <?= h($alert) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($mode === 'add' || $mode === 'edit'): ?>
        
        <!-- Add/Edit Form -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-car-battery me-2"></i><?= $mode === 'edit' ? 'Edit UPS Asset' : 'Add New UPS Asset' ?></h4>
            <a href="ups_assets.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editData['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <!-- UPS Information Section -->
                    <div class="form-section-title">
                        <i class="fas fa-server me-2"></i>UPS Information
                    </div>
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <label class="form-label">UPS Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="ups_no" 
                                   value="<?= h($editData['ups_no'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Model</label>
                            <input type="text" class="form-control" name="model" 
                                   value="<?= h($editData['model'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Team</label>
                            <select class="form-select" name="team">
                                <option value="">Select Team</option>
                                <?php if($teams): while($t = $teams->fetch_assoc()): ?>
                                <option value="<?= h($t['team_name']) ?>" 
                                    <?= ($editData['team']??'') == $t['team_name'] ? 'selected' : '' ?>>
                                    <?= h($t['team_name']) ?>
                                </option>
                                <?php endwhile; endif; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Assigned User</label>
                            <input type="text" class="form-control" name="assigned_user" 
                                   value="<?= h($editData['assigned_user'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Purchase Information Section -->
                    <div class="form-section-title">
                        <i class="fas fa-shopping-cart me-2"></i>Purchase Information
                    </div>
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <label class="form-label">Purchase Date</label>
                            <input type="date" class="form-control" name="purchase_date" 
                                   value="<?= h($editData['purchase_date'] ?? date('Y-m-d')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Vendor</label>
                            <input type="text" class="form-control" name="vendor" 
                                   value="<?= h($editData['vendor'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Payment Reference</label>
                            <input type="text" class="form-control" name="payment_ref" 
                                   value="<?= h($editData['payment_ref'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">UPS Status</label>
                            <select class="form-select" name="ups_status">
                                <option value="Active" <?= ($editData['ups_status']??'')=='Active'?'selected':'' ?>>Active</option>
                                <option value="Inactive" <?= ($editData['ups_status']??'')=='Inactive'?'selected':'' ?>>Inactive</option>
                                <option value="Maintenance" <?= ($editData['ups_status']??'')=='Maintenance'?'selected':'' ?>>Maintenance</option>
                            </select>
                        </div>
                    </div>

                    <!-- Battery 1 Section -->
                    <div class="form-section-title" style="background: #cfe2ff;">
                        <i class="fas fa-battery-full me-2"></i>Battery 1 Information
                    </div>
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <label class="form-label">Serial Number</label>
                            <input type="text" class="form-control" name="battery1_serial" 
                                   value="<?= h($editData['battery1_serial'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Purchase Date</label>
                            <input type="date" class="form-control" name="battery1_purchase_date" 
                                   value="<?= h($editData['battery1_purchase_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Warranty</label>
                            <input type="text" class="form-control" name="battery1_warranty" 
                                   value="<?= h($editData['battery1_warranty'] ?? '2 years') ?>" 
                                   placeholder="e.g., 2 years">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Install Date</label>
                            <input type="date" class="form-control" name="battery1_install_date" 
                                   value="<?= h($editData['battery1_install_date'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Battery 2 Section -->
                    <div class="form-section-title" style="background: #e2d9f1;">
                        <i class="fas fa-battery-full me-2"></i>Battery 2 Information
                    </div>
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <label class="form-label">Serial Number</label>
                            <input type="text" class="form-control" name="battery2_serial" 
                                   value="<?= h($editData['battery2_serial'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Purchase Date</label>
                            <input type="date" class="form-control" name="battery2_purchase_date" 
                                   value="<?= h($editData['battery2_purchase_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Warranty</label>
                            <input type="text" class="form-control" name="battery2_warranty" 
                                   value="<?= h($editData['battery2_warranty'] ?? '2 years') ?>" 
                                   placeholder="e.g., 2 years">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Install Date</label>
                            <input type="date" class="form-control" name="battery2_install_date" 
                                   value="<?= h($editData['battery2_install_date'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Remarks -->
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="remarks" rows="2"><?= h($editData['remarks'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Save Record
                        </button>
                        <a href="ups_assets.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-car-battery me-2"></i>UPS & Battery Asset Management</h4>
            <a href="ups_assets.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add New UPS
            </a>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card total">
                    <h3><?= $stats['total'] ?></h3>
                    <p>Total UPS Assets</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card critical">
                    <h3><?= $stats['critical'] ?></h3>
                    <p>Critical (36+ months)</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card warning">
                    <h3><?= $stats['warning'] ?></h3>
                    <p>Warning (30-35 months)</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card info">
                    <h3><?= $stats['info'] ?></h3>
                    <p>Info (24-29 months)</p>
                </div>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control" 
                               placeholder="Search UPS No, Model, Serial..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="team" class="form-select">
                            <option value="">All Teams</option>
                            <?php 
                            $teams->data_seek(0);
                            while($t = $teams->fetch_assoc()): 
                            ?>
                            <option value="<?= h($t['team_name']) ?>" <?= $team_filter == $t['team_name'] ? 'selected' : '' ?>>
                                <?= h($t['team_name']) ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="ups_assets.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Data Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>UPS Info</th>
                                <th>Assignment</th>
                                <th>Purchase Details</th>
                                <th>Battery 1</th>
                                <th>Battery 2</th>
                                <th>Ageing</th>
                                <th>Status</th>
                                <th width="100">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($result && $result->num_rows > 0): ?>
                                <?php while($row = $result->fetch_assoc()): 
                                    $age1 = calculateAgeMonths($row['battery1_install_date']);
                                    $age2 = calculateAgeMonths($row['battery2_install_date']);
                                    $maxAge = max($age1, $age2);
                                    $ageStatus = getAgeStatus($maxAge);
                                    
                                    $warranty1 = getBatteryStatus($row['battery1_install_date'], $row['battery1_warranty']);
                                    $warranty2 = getBatteryStatus($row['battery2_install_date'], $row['battery2_warranty']);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= h($row['ups_no']) ?></strong><br>
                                        <small class="text-muted"><?= h($row['model']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?= h($row['team']) ?></span><br>
                                        <small><?= h($row['assigned_user']) ?></small>
                                    </td>
                                    <td>
                                        <small>Date: <?= formatDate($row['purchase_date']) ?></small><br>
                                        <small>Vendor: <?= h($row['vendor']) ?></small>
                                    </td>
                                    <td>
                                        <div class="battery-block">
                                            <small>S/N: <?= h($row['battery1_serial'] ?: '—') ?></small>
                                            <?php if(!empty($row['battery1_serial'])): ?>
                                            <span class="badge bg-secondary warranty-badge"><?= $age1 ?>m</span>
                                            <span class="badge bg-<?= $warranty1['class'] ?> warranty-badge">
                                                <?= $warranty1['message'] ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="battery-block">
                                            <small>S/N: <?= h($row['battery2_serial'] ?: '—') ?></small>
                                            <?php if(!empty($row['battery2_serial'])): ?>
                                            <span class="badge bg-secondary warranty-badge"><?= $age2 ?>m</span>
                                            <span class="badge bg-<?= $warranty2['class'] ?> warranty-badge">
                                                <?= $warranty2['message'] ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $ageStatus['class'] ?>">
                                            <?= $maxAge ?> months
                                        </span>
                                        <br>
                                        <small><?= $ageStatus['label'] ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $row['ups_status'] == 'Active' ? 'success' : 'secondary' ?>">
                                            <?= h($row['ups_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="ups_assets.php?mode=edit&id=<?= $row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Delete this record?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
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
                                    <td colspan="8" class="text-center py-5">
                                        <i class="fas fa-battery-empty fa-3x mb-3 text-muted"></i>
                                        <h5>No records found</h5>
                                        <a href="ups_assets.php?mode=add" class="btn btn-primary mt-2">
                                            Add Your First UPS
                                        </a>
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
            
            // Auto-submit filter on team change
            const teamSelect = document.querySelector('select[name="team"]');
            if (teamSelect) {
                teamSelect.addEventListener('change', function() {
                    this.form.submit();
                });
            }
        });
    </script>
</body>
</html>