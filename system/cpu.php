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
    $base = 'cpu.php';
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
                // Columns: Purchase Date, Model, Processor, RAM, HDD, SSD, Service Tag, Team, Cabin, IP, User, Payment, Vendor, Remarks
                
                $purchase_date_raw = $r[0] ?? '';
                $model = trim($r[1] ?? '');
                $processor = trim($r[2] ?? '');
                $ram = trim($r[3] ?? '');
                $hdd = trim($r[4] ?? '');
                $ssd = trim($r[5] ?? '');
                $service_tag = trim($r[6] ?? '');
                $team = trim($r[7] ?? '');
                $cabin = trim($r[8] ?? '');
                $ip = trim($r[9] ?? '');
                $user = trim($r[10] ?? '');
                $payment = trim($r[11] ?? '');
                $vendor = trim($r[12] ?? '');
                $remarks = trim($r[13] ?? '');

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

                // Check if CPU already exists based on Model AND Service Tag
                $checkStmt = $mysqli->prepare("SELECT id FROM tbl_cpu WHERE model = ? AND service_tag = ?");
                $checkStmt->bind_param("ss", $model, $service_tag);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->num_rows > 0;
                $checkStmt->close();

                $client_ip = $_SERVER['REMOTE_ADDR'];

                if (!$exists) {
                    // Insert new record
                    $insertStmt = $mysqli->prepare("INSERT INTO tbl_cpu 
                        (purchase_date, model, processor, ram, hdd, ssd, service_tag, team, cabin, ip, user, payment, vendor, remarks, created_ip, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    
                    $insertStmt->bind_param("sssssssssssssss", 
                        $purchase_date, $model, $processor, $ram, $hdd, $ssd, $service_tag, $team, $cabin, $ip, $user, $payment, $vendor, $remarks, $client_ip
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
                    $updateStmt = $mysqli->prepare("UPDATE tbl_cpu SET 
                        purchase_date = ?, processor = ?, ram = ?, hdd = ?, ssd = ?, team = ?, cabin = ?, ip = ?, user = ?, payment = ?, vendor = ?, remarks = ?, updated_ip = ?, updated_at = NOW() 
                        WHERE model = ? AND service_tag = ?");
                    
                    $updateStmt->bind_param("ssssssssssssss", 
                        $purchase_date, $processor, $ram, $hdd, $ssd, $team, $cabin, $ip, $user, $payment, $vendor, $remarks, $client_ip, $model, $service_tag
                    );
                    
                    if ($updateStmt->execute()) {
                        $updated_count++;
                    }
                    $updateStmt->close();
                }
            }
            
            $msg = "Process Complete.<br>";
            $msg .= "<strong>Imported:</strong> $imported_count new CPUs.<br>";
            $msg .= "<strong>Updated:</strong> $updated_count existing CPUs.<br>";
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
        $processor = trim($_POST['processor'] ?? '');
        $ram = trim($_POST['ram'] ?? '');
        $hdd = trim($_POST['hdd'] ?? '');
        $ssd = trim($_POST['ssd'] ?? '');
        $service_tag = trim($_POST['service_tag'] ?? '');
        $team = trim($_POST['team'] ?? '');
        $cabin = trim($_POST['cabin'] ?? '');
        $ip = trim($_POST['ip'] ?? '');
        $user = trim($_POST['user'] ?? '');
        $payment = trim($_POST['payment'] ?? '');
        $vendor = trim($_POST['vendor'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        
        // Basic validation
        if ($purchase_date === '') {
            redirect_with(['msg' => 'Purchase Date is required.', 'type' => 'danger']);
        }
        
        if ($model === '') {
            redirect_with(['msg' => 'Model is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Insert new CPU
            $stmt = $mysqli->prepare("INSERT INTO tbl_cpu (purchase_date, model, processor, ram, hdd, ssd, service_tag, team, cabin, ip, user, payment, vendor, remarks, created_ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("sssssssssssssss", $purchase_date, $model, $processor, $ram, $hdd, $ssd, $service_tag, $team, $cabin, $ip, $user, $payment, $vendor, $remarks, $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "CPU '$model' added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding CPU: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Update CPU
                $stmt = $mysqli->prepare("UPDATE tbl_cpu SET purchase_date = ?, model = ?, processor = ?, ram = ?, hdd = ?, ssd = ?, service_tag = ?, team = ?, cabin = ?, ip = ?, user = ?, payment = ?, vendor = ?, remarks = ?, updated_ip = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("sssssssssssssssi", $purchase_date, $model, $processor, $ram, $hdd, $ssd, $service_tag, $team, $cabin, $ip, $user, $payment, $vendor, $remarks, $_SERVER['REMOTE_ADDR'], $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "CPU updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating CPU: ' . $stmt->error, 'type' => 'danger']);
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
            $res = $mysqli->prepare("SELECT model FROM tbl_cpu WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($model);
            $res->fetch();
            $res->close();
            
            // Delete CPU
            $del = $mysqli->prepare("DELETE FROM tbl_cpu WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "CPU '$model' deleted", 'type' => 'warning']);
        }
    }
    
    if ($action !== 'import_excel') {
        redirect_with();
    }
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editCpu = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_cpu WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editCpu = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editCpu) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fteam = $_GET['team'] ?? '';
$sort = $_GET['sort'] ?? 'purchase_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query
$sql = "SELECT * FROM tbl_cpu";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(model LIKE ? OR processor LIKE ? OR service_tag LIKE ? OR user LIKE ? OR ip LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sssss';
}

if ($fteam !== '') {
    $cond[] = "team = ?";
    $params[] = $fteam;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " WHERE " . implode(" AND ", $cond);
}

// Validate sort column to prevent SQL injection
$allowed_sorts = ['purchase_date', 'model', 'user', 'team', 'processor'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'purchase_date';
}

$sql .= " ORDER BY $sort $dir";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $cpus = $stmt->get_result();
} else {
    $cpus = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$cpus) {
    $cpus = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CPU</title>
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
            <h4><i class="fas fa-desktop me-2"></i><?= $mode === 'edit' ? 'Edit CPU' : 'Add New CPU' ?></h4>
            <a href="cpu.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editCpu['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="purchase_date" class="form-label">Purchase Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="purchase_date" name="purchase_date" 
                                       value="<?= h($editCpu['purchase_date'] ?? date('Y-m-d')) ?>" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="model" class="form-label">Model <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="model" name="model" 
                                       value="<?= h($editCpu['model'] ?? '') ?>" 
                                       placeholder="CPU model" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="processor" class="form-label">Processor</label>
                                <input type="text" class="form-control" id="processor" name="processor"
                                       value="<?= h($editCpu['processor'] ?? '') ?>" 
                                       placeholder="e.g., Intel i5">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="ram" class="form-label">RAM</label>
                                <input type="text" class="form-control" id="ram" name="ram"
                                       value="<?= h($editCpu['ram'] ?? '') ?>" 
                                       placeholder="e.g., 8GB">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="hdd" class="form-label">HDD</label>
                                <input type="text" class="form-control" id="hdd" name="hdd"
                                       value="<?= h($editCpu['hdd'] ?? '') ?>" 
                                       placeholder="e.g., 1TB">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="ssd" class="form-label">SSD</label>
                                <input type="text" class="form-control" id="ssd" name="ssd"
                                       value="<?= h($editCpu['ssd'] ?? '') ?>" 
                                       placeholder="e.g., 256GB">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="service_tag" class="form-label">Service Tag</label>
                                <input type="text" class="form-control" id="service_tag" name="service_tag"
                                       value="<?= h($editCpu['service_tag'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="team" class="form-label">Team</label>
                                <input type="text" class="form-control" id="team" name="team"
                                       value="<?= h($editCpu['team'] ?? '') ?>" 
                                       placeholder="e.g., IT, Development">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="cabin" class="form-label">Cabin</label>
                                <input type="text" class="form-control" id="cabin" name="cabin"
                                       value="<?= h($editCpu['cabin'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="ip" class="form-label">IP</label>
                                <input type="text" class="form-control" id="ip" name="ip"
                                       value="<?= h($editCpu['ip'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="user" class="form-label">User</label>
                                <input type="text" class="form-control" id="user" name="user"
                                       value="<?= h($editCpu['user'] ?? '') ?>" 
                                       placeholder="Assigned to">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="payment" class="form-label">Payment</label>
                                <input type="text" class="form-control" id="payment" name="payment"
                                       value="<?= h($editCpu['payment'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="vendor" class="form-label">Payee</label>
                                <input type="text" class="form-control" id="vendor" name="vendor"
                                       value="<?= h($editCpu['vendor'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="remarks" class="form-label">Remarks</label>
                                <textarea class="form-control" id="remarks" name="remarks" rows="2"><?= h($editCpu['remarks'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update CPU' : 'Save CPU' ?>
                        </button>
                        <a href="cpu.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4><i class="fas fa-desktop me-2"></i>CPU Management</h4>
                <p class="text-muted mb-0">Manage inventory | Import Excel to bulk add/update</p>
            </div>
            <div>
                <button type="button" class="btn btn-warning text-dark fw-bold btn-sm me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-excel me-2"></i> Import Excel
                </button>
                <a href="cpu.php?mode=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Add CPU
                </a>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search CPUs..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="team" class="form-control form-control-sm" 
                               placeholder="Filter by team..." value="<?= h($fteam) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="purchase_date" <?= $sort === 'purchase_date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="model" <?= $sort === 'model' ? 'selected' : '' ?>>Sort by Model</option>
                            <option value="user" <?= $sort === 'user' ? 'selected' : '' ?>>Sort by User</option>
                            <option value="team" <?= $sort === 'team' ? 'selected' : '' ?>>Sort by Team</option>
                        </select>
                    </div>
                    <!--div class="col-md-2">
                        <select name="dir" class="form-select form-select-sm">
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option>
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                        </select>
                    </div-->
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="cpu.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- CPUs Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>S.No</th>
                                <th>Purchase Date</th>
                                <th>Model</th>
                                <th>Processor</th>
                                <th>RAM</th>
                                <th>HDD</th>
                                <th>SSD</th>
                                <th>Service Tag</th>
                                <th>Team</th>
                                <th>Cabin</th>
                                <th>IP</th>
                                <th>User</th>
                                <th>Payment</th>
                                <th>Payee</th>
                                <th>Remarks</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($cpus && $cpus->num_rows > 0): $i=0;?>
                                <?php while($row = $cpus->fetch_assoc()): $i=$i+1; ?>
                                <tr>
                                    <td><?= $i;?></td>
                                    <td><?= h(formatDate($row['purchase_date'])) ?></td>
                                    <td><?= h($row['model']) ?></td>
                                    <td><?= h($row['processor']) ?></td>
                                    <td><?= h($row['ram']) ?></td>
                                    <td><?= h($row['hdd']) ?></td>
                                    <td><?= h($row['ssd']) ?></td>
                                    <td><code><?= h($row['service_tag']) ?></code></td>
                                    <td><?= h($row['team']) ?></td>
                                    <td><?= h($row['cabin']) ?></td>
                                    <td><code><?= h($row['ip']) ?></code></td>
                                    <td><?= h($row['user']) ?></td>
                                    <td><?= h($row['payment']) ?></td>
                                    <td><?= h($row['vendor']) ?></td>
                                    <td><small><?= h($row['remarks']) ?></small></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="cpu.php?mode=edit&id=<?= (int)$row['id'] ?>" 
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
                                    <td colspan="16" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-desktop fa-3x mb-3"></i>
                                            <h5>No CPUs found</h5>
                                            <p class="mb-0">Get started by adding your first CPU.</p>
                                            <a href="cpu.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add CPU
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
                        <i class="fas fa-file-excel me-2"></i>Import CPUs from Excel
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
                            <ol class="mb-2" style="columns: 2;">
                                <li><strong>Purchase Date</strong> (Required)</li>
                                <li><strong>Model</strong> (Required)</li>
                                <li><strong>Processor</strong></li>
                                <li><strong>RAM</strong></li>
                                <li><strong>HDD</strong></li>
                                <li><strong>SSD</strong></li>
                                <li><strong>Service Tag</strong> (Used for Updates)</li>
                                <li><strong>Team</strong></li>
                                <li><strong>Cabin</strong></li>
                                <li><strong>IP</strong></li>
                                <li><strong>User</strong></li>
                                <li><strong>Payment</strong></li>
                                <li><strong>Vendor</strong></li>
                                <li><strong>Remarks</strong></li>
                            </ol>
                            
                            <div class="border-top pt-2 mt-2">
                                <p class="mb-1"><strong><i class="fas fa-exclamation-triangle me-1"></i> Rules:</strong></p>
                                <ul class="mb-0">
                                    <li>New records will be <span class="badge bg-success">INSERTED</span></li>
                                    <li>Existing records (matching <strong>Model + Service Tag</strong>) will be <span class="badge bg-warning">UPDATED</span></li>
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

        // Download sample Excel file formatted for CPU import
        function downloadSample() {
            // Create sample CSV content
            const sampleContent = "Purchase Date,Model,Processor,RAM,HDD,SSD,Service Tag,Team,Cabin,IP,User,Payment,Vendor,Remarks\n" +
                                 "10/24/2025,Dell OptiPlex 3080,Intel i5,8GB,1TB,256GB,ABC1234,IT,C-1,192.168.1.10,John Doe,45000,Dell,New System\n" +
                                 "10/25/2025,HP EliteDesk 800,Intel i7,16GB,,512GB,XYZ9876,Development,C-2,192.168.1.11,Jane Smith,55000,Amazon,\n" +
                                 "10/26/2025,Lenovo ThinkCentre,AMD Ryzen 5,8GB,500GB,,LNV555,HR,C-3,192.168.1.12,,35000,Local Vendor,Spare Unit";
            
            const blob = new Blob([sampleContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sample_cpu_import.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>