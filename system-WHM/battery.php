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

function redirect_with($params = []) {
    $base = 'battery.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $serial_no = trim($_POST['serial_no'] ?? '');
        $purchase_date = trim($_POST['purchase_date'] ?? '');
        $warranty = trim($_POST['warranty'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');
        
        // Basic validation
        if ($serial_no === '') {
            redirect_with(['msg' => 'Serial Number is required.', 'type' => 'danger']);
        }
        if ($purchase_date === '') {
            $purchase_date = date('Y-m-d');
        }
        
        if ($action === 'add') {
            // Insert new battery
            $stmt = $mysqli->prepare("INSERT INTO tbl_battery (serial_no, purchase_date, warranty, status, created_at, created_ip) VALUES (?, ?, ?, ?, NOW(), ?)");
            $stmt->bind_param("sssss", $serial_no, $purchase_date, $warranty, $status, $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Battery added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding battery: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                // Update battery
                $sql = "UPDATE tbl_battery SET 
                        serial_no = ?, 
                        purchase_date = ?, 
                        warranty = ?, 
                        status = ?, 
                        updated_at = NOW(), 
                        updated_ip = ? 
                        WHERE id = ?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("sssssi", $serial_no, $purchase_date, $warranty, $status, $_SERVER['REMOTE_ADDR'], $id);
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Battery updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating battery: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $del = $mysqli->prepare("DELETE FROM tbl_battery WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            redirect_with(['msg' => "Battery deleted", 'type' => 'warning']);
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editBattery = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_battery WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editBattery = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editBattery) $mode = 'view';
}

// Get search/filter
$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$sortBy = $_GET['sort_by'] ?? 'id';
$sortOrder = $_GET['sort_order'] ?? 'DESC';

$allowedSorts = ['id', 'serial_no', 'purchase_date', 'warranty', 'status'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'id';
if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') $sortOrder = 'DESC';

// Build query
$sql = "SELECT * FROM tbl_battery WHERE 1=1";
$params = [];
$types = '';

if ($q !== '') {
    $sql .= " AND (serial_no LIKE ? OR warranty LIKE ?)";
    $like = "%$q%";
    $params[] = $like; $params[] = $like;
    $types .= 'ss';
}
if ($statusFilter !== '') {
    $sql .= " AND status = ?";
    $params[] = $statusFilter; $types .= 's';
}
$sql .= " ORDER BY $sortBy $sortOrder";

if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $batteries = $stmt->get_result();
} else {
    $batteries = $mysqli->query($sql);
}

$totalBatteries = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_battery")->fetch_assoc()['cnt'];
$activeBatteries = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_battery WHERE status = 'Active'")->fetch_assoc()['cnt'];
$underWarranty = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_battery WHERE status = 'Under Warranty'")->fetch_assoc()['cnt'];
$expiredBatteries = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_battery WHERE status = 'Expired'")->fetch_assoc()['cnt'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Battery Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {background-color: #f8f9fa;font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;}
        .main-content {padding: 20px;max-width: 1800px;margin: 0 auto;}
        .table th {background-color: #2c3e50;color: white;border: none;font-size: 12px;white-space: nowrap;}
        .table td {vertical-align: middle;font-size: 13px;}
        .card {box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075);border: 1px solid rgba(0,0,0,.125);}
        .btn-group-sm > .btn {padding: 0.25rem 0.5rem;}
        .stats-card {border-left: 4px solid;padding: 15px;margin-bottom: 15px;border-radius: 8px;background: white;}
        .stats-card h3 {margin: 0;font-size: 2rem;font-weight: bold;}
        .stats-card p {margin: 5px 0 0 0;color: #6c757d;}
        .stats-card.total { border-left-color: #3498db;}
        .stats-card.active { border-left-color: #27ae60;}
        .stats-card.warranty { border-left-color: #f39c12;}
        .stats-card.expired { border-left-color: #e74c3c;}
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
            <h4><i class="fas fa-battery-full me-2"></i><?= $mode === 'edit' ? 'Edit Battery' : 'Add New Battery' ?></h4>
            <a href="battery.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editBattery['id'] ?? 0) ?>">
                    <?php endif; ?>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="serial_no" class="form-label">Serial Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="serial_no" name="serial_no" 
                                       value="<?= h($editBattery['serial_no'] ?? '') ?>" 
                                       placeholder="BAT-2025-001" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="purchase_date" class="form-label">Purchase Date</label>
                                <input type="date" class="form-control" id="purchase_date" name="purchase_date"
                                       value="<?= h($editBattery['purchase_date'] ?? ($mode === 'add' ? date('Y-m-d') : '')) ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="warranty" class="form-label">Warranty</label>
                                <input type="text" class="form-control" id="warranty" name="warranty"
                                       value="<?= h($editBattery['warranty'] ?? '') ?>" 
                                       placeholder="2 years / 24 months">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="Active" <?= ($editBattery && $editBattery['status'] === 'Active') ? 'selected' : '' ?>>Active</option>
                                    <option value="Under Warranty" <?= ($editBattery && $editBattery['status'] === 'Under Warranty') ? 'selected' : '' ?>>Under Warranty</option>
                                    <option value="Expired" <?= ($editBattery && $editBattery['status'] === 'Expired') ? 'selected' : '' ?>>Expired</option>
                                    <option value="Inactive" <?= ($editBattery && $editBattery['status'] === 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Battery' : 'Save Battery' ?>
                        </button>
                        <a href="battery.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-battery-full me-2"></i>Battery Management (<?= $totalBatteries ?> Batteries)</h4>
            <a href="battery.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add Battery
            </a>
        </div>

        <!-- Statistics -->
        <div class="row mb-3">
            <div class="col-md-3"><div class="card stats-card total"><h3><?= $totalBatteries ?></h3><p class="mb-0">Total Batteries</p></div></div>
            <div class="col-md-3"><div class="card stats-card active"><h3><?= $activeBatteries ?></h3><p class="mb-0">Active Batteries</p></div></div>
            <div class="col-md-3"><div class="card stats-card warranty"><h3><?= $underWarranty ?></h3><p class="mb-0">Under Warranty</p></div></div>
            <div class="col-md-3"><div class="card stats-card expired"><h3><?= $expiredBatteries ?></h3><p class="mb-0">Expired Batteries</p></div></div>
        </div>

        <!-- Search and Filter Section -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control" 
                               placeholder="Search serial no, warranty..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Under Warranty" <?= $statusFilter === 'Under Warranty' ? 'selected' : '' ?>>Under Warranty</option>
                            <option value="Expired" <?= $statusFilter === 'Expired' ? 'selected' : '' ?>>Expired</option>
                            <option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="sort_by" class="form-select">
                            <option value="id" <?= $sortBy === 'id' ? 'selected' : '' ?>>Sort by ID</option>
                            <option value="serial_no" <?= $sortBy === 'serial_no' ? 'selected' : '' ?>>Sort by Serial No</option>
                            <option value="purchase_date" <?= $sortBy === 'purchase_date' ? 'selected' : '' ?>>Sort by Purchase Date</option>
                            <option value="status" <?= $sortBy === 'status' ? 'selected' : '' ?>>Sort by Status</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="sort_order" class="form-select">
                            <option value="DESC" <?= $sortOrder === 'DESC' ? 'selected' : '' ?>>Descending</option>
                            <option value="ASC" <?= $sortOrder === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="battery.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Batteries Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Serial Number</th>
                                <th>Purchase Date</th>
                                <th>Warranty</th>
                                <th>Status</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($batteries && $batteries->num_rows > 0): ?>
                                <?php while($row = $batteries->fetch_assoc()): ?>
                                <tr>
                                    <td><?= (int)$row['id'] ?></td>
                                    <td class="fw-semibold"><?= h($row['serial_no']) ?></td>
                                    <td><?= formatDate($row['purchase_date']) ?></td>
                                    <td><?= h($row['warranty']) ?: '—' ?></td>
                                    <td>
                                        <?php 
                                        $statusClass = '';
                                        switch($row['status']) {
                                            case 'Active': $statusClass = 'bg-success'; break;
                                            case 'Under Warranty': $statusClass = 'bg-warning'; break;
                                            case 'Expired': $statusClass = 'bg-danger'; break;
                                            case 'Inactive': $statusClass = 'bg-secondary'; break;
                                            default: $statusClass = 'bg-info';
                                        }
                                        ?>
                                        <span class="badge <?= $statusClass ?>"><?= h($row['status']) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="battery.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Delete battery <?= h($row['serial_no']) ?>?')">
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
                                    <td colspan="6" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-battery-full fa-3x mb-3"></i>
                                            <h5>No batteries found</h5>
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
        });
    </script>
</body>
</html>
