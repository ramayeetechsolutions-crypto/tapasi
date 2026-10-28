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
    $base = 'mouse.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// ---------------------------------------------------------
// HANDLE IMPORT LOGIC (XLSX Format)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        // 1. Locate the SimpleXLSX Library
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        // Fallback paths just in case
        if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'Library file not found.', 'type' => 'danger']);
        }
        
        require_once $libPath;

        // 2. Initialize Parser
        $xlsx = null;
        if (class_exists('Shuchkin\SimpleXLSX')) {
            $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        } elseif (class_exists('SimpleXLSX')) {
            $xlsx = SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        } else {
            redirect_with(['msg' => 'Class SimpleXLSX not found.', 'type' => 'danger']);
        }

        // 3. Process Rows
        if ($xlsx) {
            $rows = $xlsx->rows();
            $imported_count = 0;
            $updated_count = 0;
            $skipped_count = 0;
            $error_details = [];
            
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header Row

                // --- COLUMN MAPPING ---
                // Columns: Purchase Date, Model, User, Payment, Payee, Status, Given Date
                
                $purchase_date_raw = $r[0] ?? '';
                $model = trim($r[1] ?? '');
                $user = trim($r[2] ?? '');
                $payment = trim($r[3] ?? '');
                $vendor = trim($r[4] ?? '');
                $status = trim($r[5] ?? '');
                $given_date_raw = $r[6] ?? '';
                
                if (empty($status)) $status = 'Available';

                // Skip if essential data is missing
                if (empty($model)) {
                    $skipped_count++;
                    $error_details[] = "Row " . ($k + 1) . ": Missing Model";
                    continue;
                }

                // Handle Purchase Date conversion (Excel numeric dates)
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
                } else {
                    $purchase_date = date('Y-m-d'); // Default fallback for required field
                }

                // Handle Given Date conversion
                $given_date = NULL;
                if (!empty($given_date_raw)) {
                    if (is_numeric($given_date_raw)) {
                        if (class_exists('Shuchkin\SimpleXLSX')) {
                            $given_date = date('Y-m-d', Shuchkin\SimpleXLSX::unixstamp($given_date_raw));
                        } else {
                            $unix = ($given_date_raw - 25569) * 86400;
                            $given_date = gmdate("Y-m-d", $unix);
                        }
                    } else {
                        $ts = strtotime($given_date_raw);
                        if ($ts !== false) $given_date = date('Y-m-d', $ts);
                    }
                }

                // Check if mouse already exists based on model
                $checkStmt = $mysqli->prepare("SELECT id FROM tbl_mouse WHERE model = ?");
                $checkStmt->bind_param("s", $model);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->num_rows > 0;
                $checkStmt->close();

                if (!$exists) {
                    // Insert new record
                    $insertStmt = $mysqli->prepare("INSERT INTO tbl_mouse 
                        (purchase_date, model, user, payment, vendor, given_date, status, created_ip, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $insertStmt->bind_param("ssssssss", 
                        $purchase_date, $model, $user, $payment, $vendor, $given_date, $status, $ip
                    );
                    
                    if ($insertStmt->execute()) {
                        $imported_count++;
                    } else {
                        $skipped_count++;
                        $error_details[] = "Row " . ($k + 1) . ": " . $mysqli->error;
                    }
                    $insertStmt->close();
                } else {
                    // Update existing record
                    $updateStmt = $mysqli->prepare("UPDATE tbl_mouse SET 
                        purchase_date = ?, user = ?, payment = ?, vendor = ?, given_date = ?, status = ?, updated_ip = ?, updated_at = NOW() 
                        WHERE model = ?");
                    
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $updateStmt->bind_param("ssssssss", 
                        $purchase_date, $user, $payment, $vendor, $given_date, $status, $ip, $model
                    );
                    
                    if ($updateStmt->execute()) {
                        $updated_count++;
                    }
                    $updateStmt->close();
                }
            }
            
            $msg = "Process Complete.<br>";
            $msg .= "<strong>Imported:</strong> $imported_count new mice.<br>";
            $msg .= "<strong>Updated:</strong> $updated_count existing mice.<br>";
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
        $purchase_date = $_POST['purchase_date'] ?? '';
        $model = trim($_POST['model'] ?? '');
        $user = trim($_POST['user'] ?? '');
        $payment = trim($_POST['payment'] ?? '');
        $vendor = trim($_POST['vendor'] ?? '');
        $status = $_POST['status'] ?? 'Available';
        $given_date = $_POST['given_date'] ?? '';
        
        // Basic validation
        if ($purchase_date === '') {
            redirect_with(['msg' => 'Purchase Date is required.', 'type' => 'danger']);
        }
        
        if ($model === '') {
            redirect_with(['msg' => 'Model is required.', 'type' => 'danger']);
        }
        
        // Handle empty given_date
        if ($given_date === '') {
            $given_date = null;
        }
        
        if ($action === 'add') {
            // Insert new mouse
            $stmt = $mysqli->prepare("INSERT INTO tbl_mouse (purchase_date, model, user, payment, vendor, given_date, status, created_ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssssssss", $purchase_date, $model, $user, $payment, $vendor, $given_date, $status, $_SERVER['REMOTE_ADDR']);

            if ($stmt->execute()) {
                redirect_with(['msg' => "Mouse '$model' added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding mouse: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Update mouse
                $stmt = $mysqli->prepare("UPDATE tbl_mouse SET purchase_date = ?, model = ?, user = ?, payment = ?, vendor = ?, given_date = ?, status = ?, updated_ip = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("ssssssssi", $purchase_date, $model, $user, $payment, $vendor, $given_date, $status, $_SERVER['REMOTE_ADDR'], $id);

                if ($stmt->execute()) {
                    redirect_with(['msg' => "Mouse updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating mouse: ' . $stmt->error, 'type' => 'danger']);
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
            $res = $mysqli->prepare("SELECT model FROM tbl_mouse WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($model);
            $res->fetch();
            $res->close();
            
            // Delete mouse
            $del = $mysqli->prepare("DELETE FROM tbl_mouse WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Mouse '$model' deleted", 'type' => 'warning']);
        }
    }
    
    // Toggle Status
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT status, model FROM tbl_mouse WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($current_status, $model);
            $res->fetch();
            $res->close();
            
            if ($model) {
                $newStatus = ($current_status === 'Available') ? 'Allocated' : 'Available';
                
                $upd = $mysqli->prepare("UPDATE tbl_mouse SET status = ? WHERE id = ?");
                $upd->bind_param("si", $newStatus, $id);
                $upd->execute();
                $upd->close();
                
                redirect_with(['msg' => "Mouse '$model' status changed to $newStatus", 'type' => 'info']);
            }
        }
    }
    
    if ($action !== 'import_excel') {
        redirect_with();
    }
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editMouse = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_mouse WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editMouse = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editMouse) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'purchase_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query
$sql = "SELECT * FROM tbl_mouse";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(model LIKE ? OR user LIKE ? OR vendor LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if (!empty($cond)) {
    $sql .= " WHERE " . implode(" AND ", $cond);
}

// Validate sort column to prevent SQL injection
$allowed_sorts = ['purchase_date', 'given_date', 'model', 'user', 'payment', 'vendor', 'status'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'purchase_date';
}

$sql .= " ORDER BY $sort $dir";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $mice = $stmt->get_result();
} else {
    $mice = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$mice) {
    $mice = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mouse</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1200px;
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
            <h4><i class="fas fa-mouse me-2"></i><?= $mode === 'edit' ? 'Edit Mouse' : 'Add New Mouse' ?></h4>
            <a href="mouse.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editMouse['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="purchase_date" class="form-label">Purchase Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="purchase_date" name="purchase_date" 
                                       value="<?= h($editMouse['purchase_date'] ?? date('Y-m-d')) ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="model" class="form-label">Model <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="model" name="model" 
                                       value="<?= h($editMouse['model'] ?? '') ?>" 
                                       placeholder="Mouse model" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="user" class="form-label">User</label>
                                <input type="text" class="form-control" id="user" name="user"
                                       value="<?= h($editMouse['user'] ?? '') ?>" 
                                       placeholder="Assigned to">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="payment" class="form-label">Payment</label>
                                <input type="text" class="form-control" id="payment" name="payment"
                                       value="<?= h($editMouse['payment'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="vendor" class="form-label">Payee</label>
                                <input type="text" class="form-control" id="vendor" name="vendor"
                                       value="<?= h($editMouse['vendor'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status" required>
                                    <?php
                                    $statuses = ['Available', 'Allocated', 'In-Repair'];
                                    $current_status = $editMouse['status'] ?? 'Available';
                                    foreach($statuses as $s) {
                                        $sel = ($current_status == $s) ? 'selected' : '';
                                        echo "<option value=\"$s\" $sel>$s</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="given_date" class="form-label">Given Date</label>
                                <input type="date" class="form-control" id="given_date" name="given_date"
                                       value="<?= h($editMouse['given_date'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Mouse' : 'Save Mouse' ?>
                        </button>
                        <a href="mouse.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4><i class="fas fa-mouse me-2"></i>Mouse Management</h4>
                <p class="text-muted mb-0">Manage inventory | Import Excel to bulk add/update</p>
            </div>
            <div>
                <button type="button" class="btn btn-warning text-dark fw-bold btn-sm me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-excel me-2"></i> Import Excel
                </button>
                <a href="mouse.php?mode=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Add Mouse
                </a>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-6">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search mice..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-4">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="purchase_date" <?= $sort === 'purchase_date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="model" <?= $sort === 'model' ? 'selected' : '' ?>>Sort by Model</option>
                            <option value="user" <?= $sort === 'user' ? 'selected' : '' ?>>Sort by User</option>
                        </select>
                    </div>
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="mouse.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Mice Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>S.No</th>
                                <th>Purchase Date</th>
                                <th>Model</th>
                                <th>User</th>
                                <th>Payment</th>
                                <th>Payee</th>
                                <th>Status</th>
                                <th>Given Date</th>
                                <th width="150" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($mice && $mice->num_rows > 0): $i=0;?>
                                <?php while($row = $mice->fetch_assoc()): $i=$i+1;?>
                                <tr>
                                    <td><?= $i; ?></td>
                                    <td><?= h(formatDate($row['purchase_date'])) ?></td>
                                    <td class="fw-semibold"><?= h($row['model']) ?></td>
                                    <td><?= h($row['user']) ?></td>
                                    <td><?= h($row['payment']) ?></td>
                                    <td><?= h($row['vendor']) ?></td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-sm status-badge <?= 
                                                $row['status'] === 'Available' ? 'btn-success' : 
                                                ($row['status'] === 'Allocated' ? 'btn-primary' : 'btn-warning') ?>" title="Toggle Status">
                                                <?= h($row['status']) ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td><?= h(formatDate($row['given_date'])) ?></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="mouse.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Delete <?= h($row['model']) ?>?')">
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
                                    <td colspan="9" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-mouse fa-3x mb-3"></i>
                                            <h5>No mice found</h5>
                                            <p class="mb-0">Get started by adding your first mouse.</p>
                                            <a href="mouse.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Mouse
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
                        <i class="fas fa-file-excel me-2"></i>Import Mice from Excel
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
                                <li><strong>Purchase Date</strong> - Excel Date or format like MM/DD/YYYY</li>
                                <li><strong>Model</strong> - Required (e.g. Logitech M170)</li>
                                <li><strong>User</strong> - Optional (Assigned employee name)</li>
                                <li><strong>Payment</strong> - Optional (e.g. 600)</li>
                                <li><strong>Payee</strong> - Vendor Name</li>
                                <li><strong>Status</strong> - Available / Allocated / In-Repair</li>
                                <li><strong>Given Date</strong> - Optional</li>
                            </ol>
                            
                            <div class="border-top pt-2 mt-2">
                                <p class="mb-1"><strong><i class="fas fa-exclamation-triangle me-1"></i> Rules:</strong></p>
                                <ul class="mb-0">
                                    <li>New records will be <span class="badge bg-success">INSERTED</span></li>
                                    <li>Existing records (matching <strong>Model</strong>) will be <span class="badge bg-warning">UPDATED</span></li>
                                    <li>Rows missing the "Model" column will be <span class="badge bg-danger">SKIPPED</span></li>
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

        // Download sample Excel file formatted for mouse import
        function downloadSample() {
            // Create sample CSV content
            const sampleContent = "Purchase Date,Model,User,Payment,Payee,Status,Given Date\n" +
                                 "10/24/2025,Logitech M170,John Doe,600,Amazon,Allocated,10/25/2025\n" +
                                 "10/25/2025,Dell MS116,,350,Flipkart,Available,\n" +
                                 "10/26/2025,HP X3000,Jane Smith,750,Local Vendor,In-Repair,10/28/2025";
            
            const blob = new Blob([sampleContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sample_mouse_import.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>