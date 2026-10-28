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
    $base = 'accessories.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $purchase_date = $_POST['purchase_date'] ?? '';
        $mode = trim($_POST['mode'] ?? '');
        $payee = trim($_POST['payee'] ?? '');
        $product = trim($_POST['product'] ?? '');
        $product_spec = trim($_POST['product_spec'] ?? '');
        $qty = (int)($_POST['qty'] ?? 0);
        $descriptions = trim($_POST['descriptions'] ?? '');
        $amount = trim($_POST['amount'] ?? '0');
        $team = trim($_POST['team'] ?? '');
        $serial_number = trim($_POST['serial_number'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        
        // Basic validation
        if ($purchase_date === '') {
            redirect_with(['msg' => 'Purchase Date is required.', 'type' => 'danger']);
        }
        
        if ($product === '') {
            redirect_with(['msg' => 'Product is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Insert new accessory
            $stmt = $mysqli->prepare("INSERT INTO tbl_accessories (purchase_date, mode, payee, product, product_spec, qty, descriptions, amount, team, serial_number, remarks, created_ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("sssssisdssss", $purchase_date, $mode, $payee, $product, $product_spec, $qty, $descriptions, $amount, $team, $serial_number, $remarks, $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Accessory '$product' added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding accessory: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Update accessory
                $stmt = $mysqli->prepare("UPDATE tbl_accessories SET purchase_date = ?, mode = ?, payee = ?, product = ?, product_spec = ?, qty = ?, descriptions = ?, amount = ?, team = ?, serial_number = ?, remarks = ?, updated_ip = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("sssssisdssss", $purchase_date, $mode, $payee, $product, $product_spec, $qty, $descriptions, $amount, $team, $serial_number, $remarks, $_SERVER['REMOTE_ADDR'], $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Accessory updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating accessory: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $product = '';
        
        if ($id > 0) {
            // Get product name for message
            $res = $mysqli->prepare("SELECT product FROM tbl_accessories WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($product);
            $res->fetch();
            $res->close();
            
            // Delete accessory
            $del = $mysqli->prepare("DELETE FROM tbl_accessories WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Accessory '$product' deleted", 'type' => 'warning']);
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editAccessory = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_accessories WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editAccessory = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editAccessory) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fteam = $_GET['team'] ?? '';
$sort = $_GET['sort'] ?? 'purchase_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query
$sql = "SELECT * FROM tbl_accessories";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(product LIKE ? OR payee LIKE ? OR product_spec LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($fteam !== '') {
    $cond[] = "team = ?";
    $params[] = $fteam;
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
    $accessories = $stmt->get_result();
} else {
    $accessories = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$accessories) {
    $accessories = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accessories Management</title>
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
            font-size: 13px;
        }
        .table td {
            vertical-align: middle;
            font-size: 14px;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
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
            <h4><i class="fas fa-tools me-2"></i><?= $mode === 'edit' ? 'Edit Accessory' : 'Add New Accessory' ?></h4>
            <a href="accessories.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editAccessory['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="purchase_date" class="form-label">Purchase Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="purchase_date" name="purchase_date" 
                                       value="<?= h($editAccessory['purchase_date'] ?? date('Y-m-d')) ?>" required>
                            </div>
                        </div>
                        <div class="col-md-3">
    <div class="mb-3">
        <label for="mode" class="form-label">Mode</label>
        <select class="form-select" id="mode" name="mode">
            <option value="">Select Mode</option>
            <?php
            $mode_options = ['Credit Card', 'Online', 'Debit Card', 'Cash', 'NEFT', 'Internet Banking'];
            $current_mode = $editAccessory['mode'] ?? '';
            foreach ($mode_options as $opt) {
                $sel = (strcasecmp($current_mode, $opt) == 0) ? 'selected' : '';
                echo "<option value=\"$opt\" $sel>$opt</option>";
            }
            ?>
        </select>
    </div>
</div>

                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="payee" class="form-label">Payee</label>
                                <input type="text" class="form-control" id="payee" name="payee"
                                       value="<?= h($editAccessory['payee'] ?? '') ?>" 
                                       placeholder="Vendor name">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="product" class="form-label">Product <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="product" name="product" 
                                       value="<?= h($editAccessory['product'] ?? '') ?>" 
                                       placeholder="Product name" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="product_spec" class="form-label">Product Spec</label>
                                <input type="text" class="form-control" id="product_spec" name="product_spec"
                                       value="<?= h($editAccessory['product_spec'] ?? '') ?>" 
                                       placeholder="Specifications">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label for="qty" class="form-label">QTY</label>
                                <input type="number" class="form-control" id="qty" name="qty"
                                       value="<?= h($editAccessory['qty'] ?? 1) ?>" min="1">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="amount" class="form-label">Amount (₹)</label>
                                <input type="number" step="0.01" class="form-control" id="amount" name="amount"
                                       value="<?= h($editAccessory['amount'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
    <div class="mb-3">
        <label for="team" class="form-label">Team</label>
        <select class="form-select" id="team" name="team">
            <option value="">Select Team</option>
            <?php
            $team_result = $mysqli->query("SELECT team_name FROM team_master WHERE status = 'Active' ORDER BY team_name ASC");
            $current_team = $editAccessory['team'] ?? '';
            while($row_team = $team_result->fetch_assoc()) {
                $sel = ($current_team == $row_team['team_name']) ? 'selected' : '';
                echo "<option value=\"" . h($row_team['team_name']) . "\" $sel>" . h($row_team['team_name']) . "</option>";
            }
            ?>
        </select>
    </div>
</div>


                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="descriptions" class="form-label">Descriptions</label>
                                <textarea class="form-control" id="descriptions" name="descriptions" rows="3"><?= h($editAccessory['descriptions'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="remarks" class="form-label">Remarks</label>
                                <textarea class="form-control" id="remarks" name="remarks" rows="3"><?= h($editAccessory['remarks'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="serial_number" class="form-label">Serial Number</label>
                        <input type="text" class="form-control" id="serial_number" name="serial_number"
                               value="<?= h($editAccessory['serial_number'] ?? '') ?>">
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Accessory' : 'Save Accessory' ?>
                        </button>
                        <a href="accessories.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-tools me-2"></i>Accessories Management</h4>
            <a href="accessories.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add Accessory
            </a>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search accessories..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="team" class="form-control form-control-sm" 
                               placeholder="Filter by team..." value="<?= h($fteam) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="purchase_date" <?= $sort === 'purchase_date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="product" <?= $sort === 'product' ? 'selected' : '' ?>>Sort by Product</option>
                            <option value="amount" <?= $sort === 'amount' ? 'selected' : '' ?>>Sort by Amount</option>
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
                        <a href="accessories.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Accessories Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Purchase Date</th>
                                <th>Mode</th>
                                <th>Payee</th>
                                <th>Product</th>
                                <th>Product Spec</th>
                                <th>QTY</th>
                                <th>Descriptions</th>
                                <th>Amount</th>
                                <th>Team</th>
                                <th>Serial Number</th>
                                <th>Remarks</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($accessories && $accessories->num_rows > 0): ?>
                                <?php while($row = $accessories->fetch_assoc()): ?>
                                <tr>
                                    <td><?= h(formatDate($row['purchase_date'])) ?></td>
                                    <td><?= h($row['mode']) ?></td>
                                    <td><?= h($row['payee']) ?></td>
                                    <td><?= h($row['product']) ?></td>
                                    <td><?= h($row['product_spec']) ?></td>
                                    <td><?= (int)$row['qty'] ?></td>
                                    <td><small><?= h($row['descriptions']) ?></small></td>
                                    <td>₹<?= number_format($row['amount'], 2) ?></td>
                                    <td><?= h($row['team']) ?></td>
                                    <td><code><?= h($row['serial_number']) ?></code></td>
                                    <td><small><?= h($row['remarks']) ?></small></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="accessories.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Delete <?= h($row['product']) ?>?')">
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
                                            <i class="fas fa-tools fa-3x mb-3"></i>
                                            <h5>No accessories found</h5>
                                            <p class="mb-0">Get started by adding your first accessory.</p>
                                            <a href="accessories.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Accessory
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
            const filterSelects = document.querySelectorAll('select[name="sort"], select[name="dir"]');
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });
        });
    </script>
</body>
</html>
