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
    $base = 'ups.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $ups_no = trim($_POST['ups_no'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $capacity = trim($_POST['capacity'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $purchase_date = trim($_POST['purchase_date'] ?? '');
        $warranty_expiry = trim($_POST['warranty_expiry'] ?? '');
        $serial_no = trim($_POST['serial_no'] ?? '');
        $manufacturer = trim($_POST['manufacturer'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        // Basic validation
        if ($ups_no === '') {
            redirect_with(['msg' => 'UPS No is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Insert new UPS record
            $stmt = $mysqli->prepare("INSERT INTO tbl_ups (ups_no, model, capacity, location, purchase_date, warranty_expiry, serial_no, manufacturer, remarks, status, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            
            $stmt->bind_param("sssssssssss", 
                $ups_no, 
                $model, 
                $capacity, 
                $location, 
                $purchase_date, 
                $warranty_expiry, 
                $serial_no, 
                $manufacturer, 
                $remarks, 
                $status, 
                $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "UPS '$ups_no' added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding UPS: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Update UPS record
                $sql = "UPDATE tbl_ups SET 
                        ups_no = ?, 
                        model = ?,
                        capacity = ?,
                        location = ?,
                        purchase_date = ?,
                        warranty_expiry = ?,
                        serial_no = ?,
                        manufacturer = ?,
                        remarks = ?, 
                        status = ?, 
                        updated_at = NOW(), 
                        updated_ip = ? 
                        WHERE id = ?";
                
                $stmt = $mysqli->prepare($sql);
                
                $stmt->bind_param("sssssssssssi", 
                    $ups_no, 
                    $model, 
                    $capacity, 
                    $location, 
                    $purchase_date, 
                    $warranty_expiry, 
                    $serial_no, 
                    $manufacturer, 
                    $remarks, 
                    $status, 
                    $_SERVER['REMOTE_ADDR'], 
                    $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "UPS record updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating UPS: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $ups_no = '';
        
        if ($id > 0) {
            // Get UPS number for message
            $res = $mysqli->prepare("SELECT ups_no FROM tbl_ups WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($ups_no);
            $res->fetch();
            $res->close();
            
            // Delete UPS record
            $del = $mysqli->prepare("DELETE FROM tbl_ups WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "UPS '$ups_no' deleted", 'type' => 'warning']);
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editUPS = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_ups WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editUPS = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editUPS) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$fmanufacturer = $_GET['manufacturer'] ?? '';

// Build query
$sql = "SELECT * FROM tbl_ups WHERE 1=1";

$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(ups_no LIKE ? OR model LIKE ? OR location LIKE ? OR serial_no LIKE ? OR manufacturer LIKE ? OR remarks LIKE ?)";
    $like = "%$q%";
    for ($i = 0; $i < 6; $i++) {
        $params[] = $like;
    }
    $types .= 'ssssss';
}

if ($fstatus !== '') {
    $cond[] = "status = ?";
    $params[] = $fstatus;
    $types .= 's';
}

if ($fmanufacturer !== '') {
    $cond[] = "manufacturer = ?";
    $params[] = $fmanufacturer;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " AND " . implode(" AND ", $cond);
}

$sql .= " ORDER BY ups_no ASC";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $upsRecords = $stmt->get_result();
} else {
    $upsRecords = $mysqli->query($sql);
}

// Get statistics
$totalUPS = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_ups")->fetch_assoc()['cnt'];
$activeUPS = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_ups WHERE status = 'active'")->fetch_assoc()['cnt'];
$maintenanceUPS = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_ups WHERE status = 'maintenance'")->fetch_assoc()['cnt'];
$inactiveUPS = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_ups WHERE status = 'inactive'")->fetch_assoc()['cnt'];

// Get manufacturer list for filter
$manufacturers = $mysqli->query("SELECT DISTINCT manufacturer FROM tbl_ups WHERE manufacturer != '' ORDER BY manufacturer");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UPS Inventory</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        .stats-container {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .stat-card {
            flex: 1;
            min-width: 150px;
            padding: 15px;
            border-radius: 8px;
            color: white;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .stat-card h3 {
            margin: 0;
            font-size: 32px;
            font-weight: bold;
        }
        .stat-card p {
            margin: 5px 0 0 0;
            font-size: 14px;
        }
        .stat-total { background: linear-gradient(135deg, #3498db, #2980b9); }
        .stat-active { background: linear-gradient(135deg, #27ae60, #229954); }
        .stat-maintenance { background: linear-gradient(135deg, #f39c12, #e67e22); }
        .stat-inactive { background: linear-gradient(135deg, #95a5a6, #7f8c8d); }
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
        }
        .warranty-expiring {
            color: #e74c3c;
            font-weight: 600;
        }
        .warranty-valid {
            color: #27ae60;
            font-weight: 600;
        }
        .badge-manufacturer {
            background-color: #6c757d;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
        }
        .info-label {
            font-size: 11px;
            color: #6c757d;
            display: block;
        }
        .inventory-id {
            font-size: 11px;
            color: #6c757d;
            display: block;
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
            <h4><i class="fas fa-plug me-2"></i><?= $mode === 'edit' ? 'Edit UPS Details' : 'Add New UPS to Inventory' ?></h4>
            <a href="ups.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to Inventory
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editUPS['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="ups_no" class="form-label">UPS ID/No. <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="ups_no" name="ups_no" 
                                       value="<?= h($editUPS['ups_no'] ?? '') ?>" 
                                       placeholder="UPS-001" required>
                                <small class="text-muted">Unique identifier for this UPS</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="serial_no" class="form-label">Serial Number</label>
                                <input type="text" class="form-control" id="serial_no" name="serial_no"
                                       value="<?= h($editUPS['serial_no'] ?? '') ?>" 
                                       placeholder="SN12345678">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="manufacturer" class="form-label">Manufacturer</label>
                                <input type="text" class="form-control" id="manufacturer" name="manufacturer"
                                       value="<?= h($editUPS['manufacturer'] ?? '') ?>" 
                                       placeholder="APC / Eaton / Emerson">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="model" class="form-label">Model</label>
                                <input type="text" class="form-control" id="model" name="model"
                                       value="<?= h($editUPS['model'] ?? '') ?>" 
                                       placeholder="SUA1000I">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="capacity" class="form-label">Capacity (VA/Watts)</label>
                                <input type="text" class="form-control" id="capacity" name="capacity"
                                       value="<?= h($editUPS['capacity'] ?? '') ?>" 
                                       placeholder="1000 VA / 600 W">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="location" class="form-label">Storage Location</label>
                                <input type="text" class="form-control" id="location" name="location"
                                       value="<?= h($editUPS['location'] ?? '') ?>" 
                                       placeholder="Warehouse A / Rack 3 / Shelf 2">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="purchase_date" class="form-label">Purchase Date</label>
                                <input type="date" class="form-control" id="purchase_date" name="purchase_date"
                                       value="<?= h($editUPS['purchase_date'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="warranty_expiry" class="form-label">Warranty Expiry Date</label>
                                <input type="date" class="form-control" id="warranty_expiry" name="warranty_expiry"
                                       value="<?= h($editUPS['warranty_expiry'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active" <?= ($editUPS && $editUPS['status'] == 'active') ? 'selected' : '' ?>>In Stock / Active</option>
                                    <option value="inactive" <?= ($editUPS && $editUPS['status'] == 'inactive') ? 'selected' : '' ?>>Out of Stock / Inactive</option>
                                    <option value="maintenance" <?= ($editUPS && $editUPS['status'] == 'maintenance') ? 'selected' : '' ?>>Under Maintenance</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label for="remarks" class="form-label">Remarks / Notes</label>
                                <textarea class="form-control" id="remarks" name="remarks" rows="2"><?= h($editUPS['remarks'] ?? '') ?></textarea>
                                <small class="text-muted">Additional information about condition, usage, etc.</small>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update UPS' : 'Add to Inventory' ?>
                        </button>
                        <a href="ups.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-warehouse me-2"></i>UPS Inventory</h4>
            <a href="ups.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add UPS to Inventory
            </a>
        </div>

        <!-- Statistics -->
        <div class="stats-container">
            <div class="stat-card stat-total">
                <h3><?= $totalUPS ?></h3>
                <p><i class="fas fa-boxes me-1"></i> Total Units</p>
            </div>
            <div class="stat-card stat-active">
                <h3><?= $activeUPS ?></h3>
                <p><i class="fas fa-check-circle me-1"></i> In Stock</p>
            </div>
            <div class="stat-card stat-maintenance">
                <h3><?= $maintenanceUPS ?></h3>
                <p><i class="fas fa-tools me-1"></i> Maintenance</p>
            </div>
            <div class="stat-card stat-inactive">
                <h3><?= $inactiveUPS ?></h3>
                <p><i class="fas fa-times-circle me-1"></i> Out of Stock</p>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search UPS ID, Model, Serial, Location..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="manufacturer" class="form-select form-select-sm">
                            <option value="">All Manufacturers</option>
                            <?php if($manufacturers && $manufacturers->num_rows > 0): ?>
                                <?php while($man = $manufacturers->fetch_assoc()): ?>
                                <option value="<?= h($man['manufacturer']) ?>" <?= $fmanufacturer === $man['manufacturer'] ? 'selected' : '' ?>>
                                    <?= h($man['manufacturer']) ?>
                                </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="active" <?= $fstatus === 'active' ? 'selected' : '' ?>>In Stock</option>
                            <option value="inactive" <?= $fstatus === 'inactive' ? 'selected' : '' ?>>Out of Stock</option>
                            <option value="maintenance" <?= $fstatus === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="ups.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- UPS Inventory Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>UPS ID</th>
                                <th>Manufacturer</th>
                                <th>Model</th>
                                <th>Capacity</th>
                                <th>Serial No.</th>
                                <th>Location</th>
                                <th>Purchase Date</th>
                                <th>Warranty</th>
                                <th>Status</th>
                                <th>Remarks</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($upsRecords && $upsRecords->num_rows > 0): ?>
                                <?php while($row = $upsRecords->fetch_assoc()): 
                                    // Check warranty status
                                    $warrantyClass = '';
                                    $warrantyText = formatDate($row['warranty_expiry']);
                                    
                                    if (!empty($row['warranty_expiry']) && $row['warranty_expiry'] !== '0000-00-00') {
                                        $expiryDate = new DateTime($row['warranty_expiry']);
                                        $today = new DateTime();
                                        
                                        if ($expiryDate < $today) {
                                            $warrantyClass = 'text-danger fw-bold';
                                            $warrantyText .= ' (Expired)';
                                        } else {
                                            $daysToExpiry = $today->diff($expiryDate)->days;
                                            if ($daysToExpiry <= 30) {
                                                $warrantyClass = 'text-warning fw-bold';
                                                $warrantyText .= ' (Expiring soon)';
                                            } else {
                                                $warrantyClass = 'text-success';
                                            }
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><span class="inventory-id">#<?= (int)$row['id'] ?></span></td>
                                    <td class="fw-semibold"><?= h($row['ups_no']) ?></td>
                                    <td>
                                        <?php if(!empty($row['manufacturer'])): ?>
                                        <span class="badge-manufacturer"><?= h($row['manufacturer']) ?></span>
                                        <?php else: ?>
                                        —
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($row['model']) ?: '—' ?></td>
                                    <td><?= h($row['capacity']) ?: '—' ?></td>
                                    <td><small><?= h($row['serial_no']) ?: '—' ?></small></td>
                                    <td><?= h($row['location']) ?: '—' ?></td>
                                    <td><?= formatDate($row['purchase_date']) ?></td>
                                    <td class="<?= $warrantyClass ?>">
                                        <?= $warrantyText ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $statusClass = '';
                                        $statusText = '';
                                        switch($row['status']) {
                                            case 'active':
                                                $statusClass = 'bg-success';
                                                $statusText = 'In Stock';
                                                break;
                                            case 'inactive':
                                                $statusClass = 'bg-secondary';
                                                $statusText = 'Out of Stock';
                                                break;
                                            case 'maintenance':
                                                $statusClass = 'bg-warning';
                                                $statusText = 'Maintenance';
                                                break;
                                            default:
                                                $statusClass = 'bg-info';
                                                $statusText = h($row['status']);
                                        }
                                        ?>
                                        <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                                    </td>
                                    <td><small><?= h($row['remarks']) ?: '—' ?></small></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="ups.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Delete UPS <?= h($row['ups_no']) ?> from inventory?')">
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
                                    <td colspan="12" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-warehouse fa-3x mb-3"></i>
                                            <h5>No UPS units in inventory</h5>
                                            <p class="mb-0">Add UPS devices to track your inventory.</p>
                                            <a href="ups.php?mode=add" class="btn btn-primary mt-3">
                                                <i class="fas fa-plus me-1"></i> Add First UPS
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

            // Auto-submit for filter selects
            const searchForm = document.querySelector('form[method="get"]');
            if (searchForm) {
                const filterSelects = searchForm.querySelectorAll('select[name="manufacturer"], select[name="status"]');
                filterSelects.forEach(select => {
                    select.addEventListener('change', function() {
                        searchForm.submit();
                    });
                });
            }
        });
    </script>
</body>
</html>