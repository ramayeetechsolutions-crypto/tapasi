<?php
require 'db.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Helper Functions
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDate($d) { if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—'; return date('d-m-Y', strtotime($d)); }
function redirect_with($params = []) {
    $base = 'laptop.php'; 
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base"); exit;
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

                // Check if laptop already exists based on model
                $checkStmt = $mysqli->prepare("SELECT id FROM tbl_laptop WHERE model = ?");
                $checkStmt->bind_param("s", $model);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->num_rows > 0;
                $checkStmt->close();

                if (!$exists) {
                    // Insert new record
                    $insertStmt = $mysqli->prepare("INSERT INTO tbl_laptop 
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
                    $updateStmt = $mysqli->prepare("UPDATE tbl_laptop SET 
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
            $msg .= "<strong>Imported:</strong> $imported_count new laptops.<br>";
            $msg .= "<strong>Updated:</strong> $updated_count existing laptops.<br>";
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

// --- HANDLE POST ACTIONS ---
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
        
        // Basic Validation
        if ($purchase_date === '') redirect_with(['msg' => 'Purchase Date is required.', 'type' => 'danger']);
        if ($model === '') redirect_with(['msg' => 'Model is required.', 'type' => 'danger']);
        
        // Handle empty given_date
        if ($given_date === '') {
            $given_date = null;
        }

        // Insert (Add)
        if ($action === 'add') {
            $stmt = $mysqli->prepare("INSERT INTO tbl_laptop (purchase_date, model, user, payment, vendor, given_date, status, created_ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssssssss", $purchase_date, $model, $user, $payment, $vendor, $given_date, $status, $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) redirect_with(['msg' => "Laptop added successfully", 'type' => 'success']);
            else redirect_with(['msg' => 'Error: ' . $stmt->error, 'type' => 'danger']);
            $stmt->close();
        } 
        // Update (Edit)
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $mysqli->prepare("UPDATE tbl_laptop SET purchase_date = ?, model = ?, user = ?, payment = ?, vendor = ?, given_date = ?, status = ?, updated_ip = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("ssssssssi", $purchase_date, $model, $user, $payment, $vendor, $given_date, $status, $_SERVER['REMOTE_ADDR'], $id);
                
                if ($stmt->execute()) redirect_with(['msg' => "Laptop updated successfully", 'type' => 'success']);
                else redirect_with(['msg' => 'Error: ' . $stmt->error, 'type' => 'danger']);
                $stmt->close();
            }
        }
    }
    
    // Delete
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $del = $mysqli->prepare("DELETE FROM tbl_laptop WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            redirect_with(['msg' => "Laptop deleted successfully", 'type' => 'warning']);
        }
    }
    
    if ($action !== 'import_excel') {
        redirect_with();
    }
}

// --- GET DATA FOR VIEW/EDIT ---
$mode = $_GET['mode'] ?? 'view';
$editData = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_laptop WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editData = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editData) $mode = 'view';
}

// --- SEARCH & FILTER ---
$q = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'purchase_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

$sql = "SELECT * FROM tbl_laptop";
if ($q !== '') {
    $sql .= " WHERE (model LIKE '%$q%' OR user LIKE '%$q%' OR vendor LIKE '%$q%')";
}
$sql .= " ORDER BY $sort $dir";

$result = $mysqli->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laptop Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { 
            background-color: #f8f9fa; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content { 
            padding: 20px 30px; 
            max-width: 100%;
            margin: 0;
        }
        .table th { 
            background-color: #2c3e50; 
            color: white; 
            border: none;
            white-space: nowrap;
        }
        .table td { 
            vertical-align: middle;
        }
        .card { 
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); 
            border: 1px solid rgba(0, 0, 0, 0.125);
            border-radius: 0.5rem;
            width: 100%;
        }
        .container-fluid {
            padding-left: 0;
            padding-right: 0;
        }
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .table {
            min-width: 1200px; /* Ensures table doesn't shrink too much on small screens */
        }
        .badge {
            font-size: 0.85rem;
            padding: 0.35rem 0.65rem;
        }
        .btn-group-sm > .btn, .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
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
            <h4><i class="fas fa-laptop me-2"></i><?= $mode === 'edit' ? 'Edit Laptop' : 'Add New Laptop' ?></h4>
            <a href="laptop.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to List</a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editData['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Purchase Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="purchase_date" 
                                   value="<?= h($editData['purchase_date'] ?? date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Model <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="model" 
                                   value="<?= h($editData['model'] ?? '') ?>" placeholder="Enter Model" required>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">User</label>
                            <input type="text" class="form-control" name="user" 
                                   value="<?= h($editData['user'] ?? '') ?>" placeholder="Assigned to">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Payment</label>
                            <input type="text" class="form-control" name="payment" 
                                   value="<?= h($editData['payment'] ?? '') ?>" placeholder="Amount">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Payee</label>
                            <input type="text" class="form-control" name="vendor" 
                                   value="<?= h($editData['vendor'] ?? '') ?>" placeholder="Vendor">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="Available" <?= ($editData['status']??'')=='Available'?'selected':'' ?>>Available</option>
                                <option value="Allocated" <?= ($editData['status']??'')=='Allocated'?'selected':'' ?>>Allocated</option>
                                <option value="In-Repair" <?= ($editData['status']??'')=='In-Repair'?'selected':'' ?>>In-Repair</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Given Date</label>
                            <input type="date" class="form-control" name="given_date" 
                                   value="<?= h($editData['given_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-flex">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="fas fa-save me-1"></i> <?= $mode === 'edit' ? 'Update Laptop' : 'Save Laptop' ?>
                                </button>
                                <a href="laptop.php" class="btn btn-outline-secondary">Cancel</a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4><i class="fas fa-laptop me-2"></i>Laptop Management</h4>
                <p class="text-muted mb-0">Manage inventory | Import Excel to bulk add/update</p>
            </div>
            <div>
                <button type="button" class="btn btn-warning text-dark fw-bold btn-sm me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-excel me-2"></i> Import Excel
                </button>
                <a href="laptop.php?mode=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Add New Laptop
                </a>
            </div>
        </div>

        <!-- Search Form -->
        <div class="card mb-4">
            <div class="card-body py-3">
                <form method="get" class="row g-2 align-items-center">
                    <div class="col-md-10">
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" name="q" class="form-control" 
                                   placeholder="Search by model, user, or vendor..." value="<?= h($q) ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Search</button>
                        <?php if($q !== ''): ?>
                        <a href="laptop.php" class="btn btn-secondary w-100 mt-1">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Table -->
        <div class="card">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Laptop Inventory</h5>
                    <span class="text-muted">Total: <?= $result ? $result->num_rows : 0 ?> records</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th width="50">S.No</th>
                                <th>Purchase Date</th>
                                <th>Model</th>
                                <th>User</th>
                                <th>Payment</th>
                                <th>Payee</th>
                                <th>Status</th>
                                <th>Given Date</th>
                                <th width="100">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($result && $result->num_rows > 0): $i=0;?>
                                <?php while($row = $result->fetch_assoc()): $i++;?>
                                <tr>
                                    <td class="fw-bold"><?= $i ?></td>
                                    <td><?= h(formatDate($row['purchase_date'])) ?></td>
                                    <td class="fw-bold"><?= h($row['model']) ?></td>
                                    <td><?= h($row['user'] ?: '—') ?></td>
                                    <td><?= h($row['payment'] ?: '—') ?></td>
                                    <td><?= h($row['vendor'] ?: '—') ?></td>
                                    <td>
                                        <span class="badge <?= 
                                            $row['status'] === 'Available' ? 'bg-success' : 
                                            ($row['status'] === 'Allocated' ? 'bg-primary' : 
                                            ($row['status'] === 'In-Repair' ? 'bg-warning' : 'bg-secondary')) ?>">
                                            <?= h($row['status'] ?: '—') ?>
                                        </span>
                                    </td>
                                    <td><?= h(formatDate($row['given_date'])) ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="laptop.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this laptop?')">
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
                                            <i class="fas fa-laptop fa-3x mb-3 opacity-50"></i>
                                            <h5>No laptops found</h5>
                                            <p class="mb-3">Get started by adding your first laptop</p>
                                            <a href="laptop.php?mode=add" class="btn btn-primary">
                                                <i class="fas fa-plus me-1"></i> Add New Laptop
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
                        <i class="fas fa-file-excel me-2"></i>Import Laptops from Excel
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
                                <li><strong>Model</strong> - Required (e.g. Dell Latitude 3420)</li>
                                <li><strong>User</strong> - Optional (Assigned employee name)</li>
                                <li><strong>Payment</strong> - Optional (e.g. 55000)</li>
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

        // Download sample Excel file formatted for laptops
        function downloadSample() {
            // Create sample CSV content
            const sampleContent = "Purchase Date,Model,User,Payment,Payee,Status,Given Date\n" +
                                 "10/24/2025,Dell Latitude 3420,John Doe,55000,Amazon,Allocated,10/25/2025\n" +
                                 "10/25/2025,HP ProBook 450 G8,,62000,Flipkart,Available,\n" +
                                 "10/26/2025,Lenovo ThinkPad E14,Jane Smith,58000,Local Vendor,In-Repair,10/28/2025";
            
            const blob = new Blob([sampleContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sample_laptop_import.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>