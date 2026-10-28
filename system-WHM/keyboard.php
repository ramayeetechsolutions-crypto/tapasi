<?php
require 'db.php';
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
    $base = 'keyboard.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $purchase_date = $_POST['purchase_date'] ?? '';
        $model = trim($_POST['model'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $user = trim($_POST['user'] ?? '');
        $payment = trim($_POST['payment'] ?? '');
        $vendor = trim($_POST['vendor'] ?? '');
        $status = $_POST['status'] ?? 'Available';
        
        // Basic validation
        if ($purchase_date === '') {
            redirect_with(['msg' => 'Purchase Date is required.', 'type' => 'danger']);
        }
        
        if ($model === '') {
            redirect_with(['msg' => 'Model is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Insert new keyboard
            $stmt = $mysqli->prepare("INSERT INTO tbl_keyboard (purchase_date, model, name, user, payment, vendor, status, created_ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssssssss", $purchase_date, $model, $name, $user, $payment, $vendor, $status, $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Keyboard '$model' added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding keyboard: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Update keyboard
                $stmt = $mysqli->prepare("UPDATE tbl_keyboard SET purchase_date = ?, model = ?, name = ?, user = ?, payment = ?, vendor = ?, status = ?, updated_ip = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("ssssssssi", $purchase_date, $model, $name, $user, $payment, $vendor, $status, $_SERVER['REMOTE_ADDR'], $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Keyboard updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating keyboard: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $model = '';
        
        if ($id > 0) {
            // Get model for message
            $res = $mysqli->prepare("SELECT model FROM tbl_keyboard WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($model);
            $res->fetch();
            $res->close();
            
            // Delete keyboard
            $del = $mysqli->prepare("DELETE FROM tbl_keyboard WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Keyboard '$model' deleted", 'type' => 'warning']);
        }
    }
    
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT status, model FROM tbl_keyboard WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($current_status, $model);
            $res->fetch();
            $res->close();
            
            if ($model) {
                $newStatus = ($current_status === 'Available') ? 'Allocated' : 'Available';
                
                $upd = $mysqli->prepare("UPDATE tbl_keyboard SET status = ? WHERE id = ?");
                $upd->bind_param("si", $newStatus, $id);
                $upd->execute();
                $upd->close();
                
                redirect_with(['msg' => "Keyboard '$model' status changed to $newStatus", 'type' => 'info']);
            }
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editKeyboard = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_keyboard WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editKeyboard = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editKeyboard) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'purchase_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query
$sql = "SELECT * FROM tbl_keyboard";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(model LIKE ? OR name LIKE ? OR user LIKE ? OR vendor LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if ($fstatus !== '') {
    $cond[] = "status = ?";
    $params[] = $fstatus;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " WHERE " . implode(" AND ", $cond);
}

$sql .= " ORDER BY $sort $dir";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $keyboards = $stmt->get_result();
} else {
    $keyboards = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$keyboards) {
    $keyboards = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Keyboard Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .table th {
            background-color: #2c3e50;
            color: white;
            border: none;
        }
        .table td {
            vertical-align: middle;
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
            <h4><i class="fas fa-keyboard me-2"></i><?= $mode === 'edit' ? 'Edit Keyboard' : 'Add New Keyboard' ?></h4>
            <a href="keyboard.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editKeyboard['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="purchase_date" class="form-label">Purchase Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="purchase_date" name="purchase_date" 
                                value="<?= h($editKeyboard['purchase_date'] ?? date('Y-m-d')) ?>" required>

                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="model" class="form-label">Model <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="model" name="model" 
                                       value="<?= h($editKeyboard['model'] ?? '') ?>" 
                                       placeholder="Keyboard model" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="name" class="form-label">Name</label>
                                <input type="text" class="form-control" id="name" name="name"
                                       value="<?= h($editKeyboard['name'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="Available" <?= ($editKeyboard && $editKeyboard['status'] == 'Available') ? 'selected' : '' ?>>Available</option>
                                    <option value="Allocated" <?= ($editKeyboard && $editKeyboard['status'] == 'Allocated') ? 'selected' : '' ?>>Allocated</option>
                                    <option value="In-Repair" <?= ($editKeyboard && $editKeyboard['status'] == 'In-Repair') ? 'selected' : '' ?>>In-Repair</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="user" class="form-label">User</label>
                                <input type="text" class="form-control" id="user" name="user"
                                       value="<?= h($editKeyboard['user'] ?? '') ?>" 
                                       placeholder="Assigned to">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="payment" class="form-label">Payment</label>
                                <input type="text" class="form-control" id="payment" name="payment"
                                       value="<?= h($editKeyboard['payment'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="vendor" class="form-label">Vendor</label>
                                <input type="text" class="form-control" id="vendor" name="vendor"
                                       value="<?= h($editKeyboard['vendor'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Keyboard' : 'Save Keyboard' ?>
                        </button>
                        <a href="keyboard.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-keyboard me-2"></i>Keyboard Management</h4>
            <a href="keyboard.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add Keyboard
            </a>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search keyboards..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="Available" <?= $fstatus === 'Available' ? 'selected' : '' ?>>Available</option>
                            <option value="Allocated" <?= $fstatus === 'Allocated' ? 'selected' : '' ?>>Allocated</option>
                            <option value="In-Repair" <?= $fstatus === 'In-Repair' ? 'selected' : '' ?>>In-Repair</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="purchase_date" <?= $sort === 'purchase_date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="model" <?= $sort === 'model' ? 'selected' : '' ?>>Sort by Model</option>
                            <option value="user" <?= $sort === 'user' ? 'selected' : '' ?>>Sort by User</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="dir" class="form-select form-select-sm">
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option>
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                        </select>
                    </div>
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="keyboard.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Keyboards Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Purchase Date</th>
                                <th>Model</th>
                                <th>Name</th>
                                <th>User</th>
                                <th>Payment</th>
                                <th>Vendor</th>
                                <th>Status</th>
                                <th width="150" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($keyboards && $keyboards->num_rows > 0): ?>
                                <?php while($row = $keyboards->fetch_assoc()): ?>
                                <tr>
                                    <td><?= h(formatDate($row['purchase_date'])) ?></td>
                                    <td class="fw-semibold"><?= h($row['model']) ?></td>
                                    <td><?= h($row['name']) ?></td>
                                    <td><?= h($row['user']) ?></td>
                                    <td><?= h($row['payment']) ?></td>
                                    <td><?= h($row['vendor']) ?></td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-sm status-badge <?= 
                                                $row['status'] === 'Available' ? 'btn-success' : 
                                                ($row['status'] === 'Allocated' ? 'btn-primary' : 'btn-warning') ?>">
                                                <?= h($row['status']) ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="keyboard.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit Keyboard">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete <?= h($row['model']) ?>?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Keyboard">
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
                                        <div class="text-muted">
                                            <i class="fas fa-keyboard fa-3x mb-3"></i>
                                            <h5>No keyboards found</h5>
                                            <p class="mb-0">Get started by adding your first keyboard.</p>
                                            <a href="keyboard.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Keyboard
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

            // Auto-submit form on filter changes
            const filterSelects = document.querySelectorAll('select[name="status"], select[name="sort"], select[name="dir"]');
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });
        });
    </script>
</body>
</html>
