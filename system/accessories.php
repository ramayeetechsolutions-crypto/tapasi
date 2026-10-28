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

// ---------------------------------------------------------
// HANDLE IMPORT LOGIC (XLSX Format)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'Library file not found.', 'type' => 'danger']);
        }
        
        require_once $libPath;

        $xlsx = null;
        if (class_exists('Shuchkin\SimpleXLSX')) {
            $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        } elseif (class_exists('SimpleXLSX')) {
            $xlsx = SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        } else {
            redirect_with(['msg' => 'Class SimpleXLSX not found.', 'type' => 'danger']);
        }

        if ($xlsx) {
            $rows = $xlsx->rows();
            $imported_count = 0;
            $updated_count = 0;
            $skipped_count = 0;
            $error_details = [];
            
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header Row

                // --- UPDATED COLUMN MAPPING TO MATCH YOUR NEW ORDER ---
                // Columns: Purchase Date, Mode, Payee, Product, Team, Product Spec, QTY, Amount, Descriptions, Serial Number, Remarks
                
                $purchase_date_raw = trim($r[0] ?? '');
                $mode          = trim($r[1] ?? '');
                $payee         = trim($r[2] ?? '');
                $product       = trim($r[3] ?? '');
                $team          = trim($r[4] ?? '');
                $product_spec  = trim($r[5] ?? '');
                $qty           = (int)trim($r[6] ?? '1');
                $amount        = (float)trim($r[7] ?? '0');
                $descriptions  = trim($r[8] ?? '');
                $serial_number = trim($r[9] ?? '');
                $remarks       = trim($r[10] ?? '');
                
                if (empty($product)) {
                    $skipped_count++;
                    $error_details[] = "Row " . ($k + 1) . ": Missing Product Name";
                    continue;
                }

                // Handle Date conversions
                $purchase_date = NULL;
                if (!empty($purchase_date_raw)) {
                    if (is_numeric($purchase_date_raw)) {
                        if (class_exists('Shuchkin\SimpleXLSX')) {
                            $purchase_date = date('Y-m-d', Shuchkin\SimpleXLSX::unixstamp($purchase_date_raw));
                        } else {
                            $unix = ($purchase_date_raw - 25569) * 86400;
                            $purchase_date = gmdate("Y-m-d", $unix);
                        }
                    } else {
                        $ts = strtotime($purchase_date_raw);
                        if ($ts !== false) $purchase_date = date('Y-m-d', $ts);
                    }
                }
                if (empty($purchase_date)) {
                    $purchase_date = date('Y-m-d');
                }

                // Check if accessory already exists
                $exists = false;
                $existing_id = 0;
                
                if (!empty($serial_number)) {
                    $checkStmt = $mysqli->prepare("SELECT id FROM tbl_accessories WHERE serial_number = ?");
                    $checkStmt->bind_param("s", $serial_number);
                } else {
                    $checkStmt = $mysqli->prepare("SELECT id FROM tbl_accessories WHERE product = ? AND purchase_date = ? AND payee = ?");
                    $checkStmt->bind_param("sss", $product, $purchase_date, $payee);
                }
                
                $checkStmt->execute();
                $res = $checkStmt->get_result();
                if ($res->num_rows > 0) {
                    $exists = true;
                    $existing_id = $res->fetch_assoc()['id'];
                }
                $checkStmt->close();

                if (!$exists) {
                    $insertStmt = $mysqli->prepare("INSERT INTO tbl_accessories 
                        (purchase_date, mode, payee, product, team, product_spec, qty, amount, descriptions, serial_number, remarks, created_ip, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    
                    $insertStmt->bind_param("ssssssisssss", 
                        $purchase_date, $mode, $payee, $product, $team, $product_spec, $qty, 
                        $amount, $descriptions, $serial_number, $remarks, $_SERVER['REMOTE_ADDR']
                    );
                    
                    if ($insertStmt->execute()) {
                        $imported_count++;
                    } else {
                        $skipped_count++;
                        $error_details[] = "Row " . ($k + 1) . ": " . $mysqli->error;
                    }
                    $insertStmt->close();
                } else {
                    $updateStmt = $mysqli->prepare("UPDATE tbl_accessories SET 
                        purchase_date = ?, mode = ?, payee = ?, product = ?, team = ?, product_spec = ?, qty = ?, 
                        amount = ?, descriptions = ?, serial_number = ?, remarks = ?, updated_ip = ?, updated_at = NOW() 
                        WHERE id = ?");
                    
                    $updateStmt->bind_param("ssssssisssssi", 
                        $purchase_date, $mode, $payee, $product, $team, $product_spec, $qty, 
                        $amount, $descriptions, $serial_number, $remarks, $_SERVER['REMOTE_ADDR'], $existing_id
                    );
                    
                    if ($updateStmt->execute()) {
                        $updated_count++;
                    }
                    $updateStmt->close();
                }
            }
            
            $msg = "Process Complete.<br>";
            $msg .= "<strong>Imported:</strong> $imported_count new accessories.<br>";
            $msg .= "<strong>Updated:</strong> $updated_count existing accessories.<br>";
            $msg .= "<strong>Skipped:</strong> $skipped_count rows.";
            
            if (!empty($error_details)) {
                $msg .= "<br><small class='text-danger'>" . implode("<br>", array_slice($error_details, 0, 5)) . "</small>";
                if (count($error_details) > 5) {
                    $msg .= "<br>... and " . (count($error_details) - 5) . " more errors.";
                }
            }
            
            $type = ($imported_count > 0 || $updated_count > 0) ? 'success' : 'warning';
            redirect_with(['msg' => $msg, 'type' => $type]);

        } else {
            $err = (method_exists('Shuchkin\SimpleXLSX', 'parseError')) ? Shuchkin\SimpleXLSX::parseError() : 'Unknown Error';
            redirect_with(['msg' => 'SimpleXLSX Parse Error: ' . $err, 'type' => 'danger']);
        }
    } else {
        redirect_with(['msg' => 'No file uploaded or file error.', 'type' => 'danger']);
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        // Gathering fields exactly in the new order and type-casting amounts/qty safely
        $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
        $mode = trim($_POST['mode'] ?? '');
        $payee = trim($_POST['payee'] ?? '');
        $product = trim($_POST['product'] ?? '');
        $team = trim($_POST['team'] ?? '');
        $product_spec = trim($_POST['product_spec'] ?? '');
        $qty = (int)($_POST['qty'] ?? 1);
        $amount = !empty($_POST['amount']) ? (float)$_POST['amount'] : 0.00;
        $descriptions = trim($_POST['descriptions'] ?? '');
        $serial_number = trim($_POST['serial_number'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        
        if ($purchase_date === '') {
            redirect_with(['msg' => 'Purchase Date is required.', 'type' => 'danger']);
        }
        
        if ($product === '') {
            redirect_with(['msg' => 'Product is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            $stmt = $mysqli->prepare("INSERT INTO tbl_accessories (purchase_date, mode, payee, product, team, product_spec, qty, amount, descriptions, serial_number, remarks, created_ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            
            $stmt->bind_param("ssssssisssss", 
                $purchase_date, $mode, $payee, $product, $team, $product_spec, $qty, 
                $amount, $descriptions, $serial_number, $remarks, $_SERVER['REMOTE_ADDR']);
            
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
                $stmt = $mysqli->prepare("UPDATE tbl_accessories SET purchase_date = ?, mode = ?, payee = ?, product = ?, team = ?, product_spec = ?, qty = ?, amount = ?, descriptions = ?, serial_number = ?, remarks = ?, updated_ip = ?, updated_at = NOW() WHERE id = ?");
                
                $stmt->bind_param("ssssssisssssi", 
                    $purchase_date, $mode, $payee, $product, $team, $product_spec, $qty, 
                    $amount, $descriptions, $serial_number, $remarks, $_SERVER['REMOTE_ADDR'], $id);
                
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
            $res = $mysqli->prepare("SELECT product FROM tbl_accessories WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($product);
            $res->fetch();
            $res->close();
            
            $del = $mysqli->prepare("DELETE FROM tbl_accessories WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Accessory '$product' deleted", 'type' => 'warning']);
        }
    }
    
    if ($action !== 'import_excel') {
        redirect_with();
    }
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
    $cond[] = "(product LIKE ? OR payee LIKE ? OR product_spec LIKE ? OR serial_number LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
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
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
        }
        .form-control, .form-select {
            font-size: 14px;
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

                    <!-- Row 1: Date, Mode, Payee, Product -->
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
                                        echo "<option value=\"" . h($opt) . "\" $sel>" . h($opt) . "</option>";
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

                    <!-- Row 2: Team, Product Spec, QTY, Amount -->
                    <div class="row">
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
                    </div>

                    <!-- Row 3: Descriptions, Serial Number, Remarks -->
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="descriptions" class="form-label">Descriptions</label>
                                <textarea class="form-control" id="descriptions" name="descriptions" rows="3"><?= h($editAccessory['descriptions'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="serial_number" class="form-label">Serial Number</label>
                                <input type="text" class="form-control" id="serial_number" name="serial_number"
                                       value="<?= h($editAccessory['serial_number'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="remarks" class="form-label">Remarks</label>
                                <textarea class="form-control" id="remarks" name="remarks" rows="3"><?= h($editAccessory['remarks'] ?? '') ?></textarea>
                            </div>
                        </div>
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
            <div>
                <h4><i class="fas fa-tools me-2"></i>Accessories</h4>
                <p class="text-muted mb-0">Manage your accessories and electronics | Import Excel for bulk add</p>
            </div>
            <div>
                <button type="button" class="btn btn-warning text-dark fw-bold btn-sm me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-excel me-1"></i> Import Excel
                </button>
                <a href="accessories.php?mode=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Add Accessory
                </a>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form id="filterForm" method="get" class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search accessories, payee, serial number..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="team" class="form-select form-select-sm">
                            <option value="">All Teams</option>
                            <?php
                            $team_result = $mysqli->query("SELECT DISTINCT team_name FROM team_master WHERE status = 'Active' ORDER BY team_name ASC");
                            while($row_team = $team_result->fetch_assoc()) {
                                $sel = ($fteam == $row_team['team_name']) ? 'selected' : '';
                                echo "<option value=\"" . h($row_team['team_name']) . "\" $sel>" . h($row_team['team_name']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="purchase_date" <?= $sort === 'purchase_date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="product" <?= $sort === 'product' ? 'selected' : '' ?>>Sort by Product</option>
                            <option value="amount" <?= $sort === 'amount' ? 'selected' : '' ?>>Sort by Amount</option>
                            <option value="team" <?= $sort === 'team' ? 'selected' : '' ?>>Sort by Team</option>
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
                                <th>S.No</th>
                                <th>Purchase Date</th>
                                <th>Mode</th>
                                <th>Payee</th>
                                <th>Product</th>
                                <th>Team</th>
                                <th>Product Spec</th>
                                <th>QTY</th>
                                <th>Amount</th>
                                <th>Descriptions</th>
                                <th>Serial Number</th>
                                <th>Remarks</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($accessories && $accessories->num_rows > 0): $i=0; ?>
                                <?php while($row = $accessories->fetch_assoc()): $i=$i+1;?>
                                <tr>
                                    <td><?= $i;?></td>
                                    <td><?= h(formatDate($row['purchase_date'])) ?></td>
                                    <td><?= h($row['mode']) ?></td>
                                    <td><?= h($row['payee']) ?></td>
                                    <td class="fw-bold"><?= h($row['product']) ?></td>
                                    <td><span class="badge bg-secondary"><?= h($row['team']) ?></span></td>
                                    <td><?= h($row['product_spec']) ?></td>
                                    <td><?= (int)$row['qty'] ?></td>
                                    <td class="text-success fw-bold">₹<?= number_format($row['amount'], 2) ?></td>
                                    <td><small><?= h($row['descriptions']) ?></small></td>
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
                                    <td colspan="13" class="text-center py-5">
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

    <!-- IMPORT MODAL -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-file-excel me-2"></i>Import Accessories from Excel
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <form action="" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Select Excel File (.xlsx)</label>
                                    <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                                    <small class="text-muted">Maximum file size: 5MB</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Download Sample</label>
                                <button type="button" class="btn btn-outline-success w-100" onclick="downloadSample()">
                                    <i class="fas fa-download me-1"></i> Sample Format
                                </button>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <h6 class="fw-bold"><i class="fas fa-info-circle me-1"></i> Excel Format Required:</h6>
                            <p class="mb-1"><strong>Columns in exact order (No extra columns):</strong></p>
                            <ol class="mb-2">
                                <li><strong>Purchase Date</strong> - YYYY-MM-DD or Excel Date format</li>
                                <li><strong>Mode</strong> - Payment mode (e.g., Online, Cash)</li>
                                <li><strong>Payee</strong> - Vendor Name</li>
                                <li><strong>Product</strong> - Product Name (Mandatory)</li>
                                <li><strong>Team</strong> - Assigned Team</li>
                                <li><strong>Product Spec</strong> - Specifications</li>
                                <li><strong>QTY</strong> - Quantity (Numeric)</li>
                                <li><strong>Amount</strong> - Cost (Numeric)</li>
                                <li><strong>Descriptions</strong> - About product</li>
                                <li><strong>Serial Number</strong> - Unique identifier (If match found, it will UPDATE)</li>
                                <li><strong>Remarks</strong> - Additional notes</li>
                            </ol>
                            
                            <div class="border-top pt-2 mt-2">
                                <p class="mb-1"><strong><i class="fas fa-exclamation-triangle me-1"></i> Rules:</strong></p>
                                <ul class="mb-0">
                                    <li>If <strong>Serial Number</strong> matches an existing record, the record will be <span class="badge bg-warning text-dark">UPDATED</span>.</li>
                                    <li>If Serial number is empty, we check <strong>Product + Purchase Date + Payee</strong> to prevent duplicates.</li>
                                    <li>If no match is found, a new accessory will be <span class="badge bg-success">INSERTED</span>.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i> Close
                        </button>
                        <button type="submit" class="btn btn-dark">
                            <i class="fas fa-upload me-1"></i> Import Data
                        </button>
                    </div>
                </form>
            </div>
        </div>
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

            // Auto-submit form ONLY on the specific Filter form drop-downs
            const filterSelects = document.querySelectorAll('#filterForm select[name="sort"], #filterForm select[name="team"]');
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });
        });

        // Download sample Excel file formatted exactly for the new Accessories order
        function downloadSample() {
            const sampleContent = "Purchase Date,Mode,Payee,Product,Team,Product Spec,QTY,Amount,Descriptions,Serial Number,Remarks\n" +
                                 "2024-03-20,Online,Amazon,Wireless Mouse,Development,Logitech M331,2,1200.50,Office use,SN10293847,Urgent purchase\n" +
                                 "2024-03-21,Credit Card,Dell India,Monitor,Design,24 inch IPS,1,15000.00,For designer,DELL-24-9988,With warranty";
            
            const blob = new Blob([sampleContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sample_accessories_import.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>