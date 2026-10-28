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

function formatCurrency($amount) {
    if (empty($amount) || $amount == 0) return '—';
    return '₹ ' . number_format($amount, 2);
}

function redirect_with($params =[]) {
    $base = 'product_purchases.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Get all products from product_master for dropdown
function getAllProducts($mysqli) {
    $result = $mysqli->query("SELECT id, product_name, status FROM product_master WHERE status = 'active' ORDER BY product_name");
    $products =[];
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    return $products;
}

// ---------------------------------------------------------
// HANDLE IMPORT LOGIC (XLSX Format)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        // 1. Locate the SimpleXLSX Library
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'Library file not found. Please check paths.', 'type' => 'danger']);
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
            $error_details =[];
            
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header Row

                // --- COLUMN MAPPING ---
                // Purchase Date, Product Name, Quantity, Batch No, Amount, Payee, Status, Description, Remarks
                
                $purchase_date_raw = $r[0] ?? '';
                $product_name = trim($r[1] ?? '');
                $quantity = (int)($r[2] ?? 1);
                $batch_no = trim($r[3] ?? '');
                $amount = (float)($r[4] ?? 0);
                $payee = trim($r[5] ?? '');
                $status = trim(strtolower($r[6] ?? 'stock'));
                $description = trim($r[7] ?? '');
                $remarks = trim($r[8] ?? '');
                
                if (empty($status) || !in_array($status, ['stock', 'used'])) $status = 'stock';

                // Skip if essential data is missing
                if (empty($product_name) || empty($batch_no)) {
                    $skipped_count++;
                    $error_details[] = "Row " . ($k + 1) . ": Missing Product Name or Batch No";
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
                    $purchase_date = date('Y-m-d'); // Default fallback
                }

                // Find or Auto-Create Product ID based on Product Name
                $product_id = 0;
                $checkProd = $mysqli->prepare("SELECT id FROM product_master WHERE product_name = ?");
                $checkProd->bind_param("s", $product_name);
                $checkProd->execute();
                $resProd = $checkProd->get_result();
                if ($resProd->num_rows > 0) {
                    $product_id = $resProd->fetch_assoc()['id'];
                } else {
                    $insProd = $mysqli->prepare("INSERT INTO product_master (product_name, status, created_at) VALUES (?, 'active', NOW())");
                    $insProd->bind_param("s", $product_name);
                    $insProd->execute();
                    $product_id = $insProd->insert_id;
                    $insProd->close();
                }
                $checkProd->close();

                // Check if purchase record already exists based on batch_no and product_id
                $checkStmt = $mysqli->prepare("SELECT id FROM product_purchases WHERE batch_no = ? AND product_id = ?");
                $checkStmt->bind_param("si", $batch_no, $product_id);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->num_rows > 0;
                $checkStmt->close();

                if (!$exists) {
                    // Insert new record
                    $insertStmt = $mysqli->prepare("INSERT INTO product_purchases 
                        (product_id, quantity, description, batch_no, amount, payee, status, remarks, purchase_date, created_at, created_ip) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
                    
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $insertStmt->bind_param("iisssdssss", 
                        $product_id, $quantity, $description, $batch_no, $amount, $payee, $status, $remarks, $purchase_date, $ip
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
                    $updateStmt = $mysqli->prepare("UPDATE product_purchases SET 
                        quantity = ?, description = ?, amount = ?, payee = ?, status = ?, remarks = ?, purchase_date = ?, updated_at = NOW(), updated_ip = ? 
                        WHERE batch_no = ? AND product_id = ?");
                    
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $updateStmt->bind_param("isdssssssi", 
                        $quantity, $description, $amount, $payee, $status, $remarks, $purchase_date, $ip, $batch_no, $product_id
                    );
                    
                    if ($updateStmt->execute()) {
                        $updated_count++;
                    }
                    $updateStmt->close();
                }
            }
            
            $msg = "Process Complete.<br>";
            $msg .= "<strong>Imported:</strong> $imported_count new records.<br>";
            $msg .= "<strong>Updated:</strong> $updated_count existing records.<br>";
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
        $product_id = !empty($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        $quantity = !empty($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
        $description = trim($_POST['description'] ?? '');
        $batch_no = trim($_POST['batch_no'] ?? '');
        $amount = !empty($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $payee = trim($_POST['payee'] ?? '');
        $status = $_POST['status'] ?? 'In Stock';
        $remarks = trim($_POST['remarks'] ?? '');
        $purchase_date = trim($_POST['purchase_date'] ?? date('Y-m-d'));
        
        if ($product_id === 0) {
            redirect_with(['msg' => 'Please select a product.', 'type' => 'danger']);
        }
        if ($batch_no === '') {
            redirect_with(['msg' => 'Batch number is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            $stmt = $mysqli->prepare("INSERT INTO product_purchases (product_id, quantity, description, batch_no, amount, payee, status, remarks, purchase_date, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->bind_param("iisssdssss", 
                $product_id, $quantity, $description, $batch_no, $amount, $payee, $status, $remarks, $purchase_date, $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Purchase record added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding record: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $sql = "UPDATE product_purchases SET 
                        product_id = ?, quantity = ?, description = ?, batch_no = ?, amount = ?, payee = ?, status = ?, remarks = ?, purchase_date = ?, updated_at = NOW(), updated_ip = ? 
                        WHERE id = ?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("iisssdssssi", 
                    $product_id, $quantity, $description, $batch_no, $amount, $payee, $status, $remarks, $purchase_date, $_SERVER['REMOTE_ADDR'], $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Purchase record updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating record: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $batch_no = '';
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT batch_no FROM product_purchases WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($batch_no);
            $res->fetch();
            $res->close();
            
            $del = $mysqli->prepare("DELETE FROM product_purchases WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Purchase record (Batch: $batch_no) deleted", 'type' => 'warning']);
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT status FROM product_purchases WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($current_status);
            $res->fetch();
            $res->close();
            
            $newStatus = ($current_status === 'stock') ? 'used' : 'stock';
            
            $upd = $mysqli->prepare("UPDATE product_purchases SET status = ? WHERE id = ?");
            $upd->bind_param("si", $newStatus, $id);
            $upd->execute();
            $upd->close();
            
            redirect_with(['msg' => "Record status changed to " . ucfirst($newStatus), 'type' => 'info']);
        }
    }
    
    if ($action !== 'import_excel') {
        redirect_with();
    }
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editRecord = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT pp.*, p.product_name FROM product_purchases pp LEFT JOIN product_master p ON pp.product_id = p.id WHERE pp.id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editRecord = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editRecord) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fproduct_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$fstatus = $_GET['status'] ?? '';

// Build query with join to get product name
$sql = "SELECT pp.*, p.product_name 
        FROM product_purchases pp 
        LEFT JOIN product_master p ON pp.product_id = p.id 
        WHERE 1=1";

$cond = [];
$params =[];
$types = '';

if ($q !== '') {
    $cond[] = "(pp.batch_no LIKE ? OR pp.payee LIKE ? OR pp.description LIKE ? OR pp.remarks LIKE ?)";
    $like = "%$q%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'ssss';
}

if ($fproduct_id > 0) {
    $cond[] = "pp.product_id = ?";
    $params[] = $fproduct_id;
    $types .= 'i';
}

if ($fstatus !== '') {
    $cond[] = "pp.status = ?";
    $params[] = $fstatus;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " AND " . implode(" AND ", $cond);
}

$sql .= " ORDER BY pp.id DESC";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $purchases = $stmt->get_result();
} else {
    $purchases = $mysqli->query($sql);
}

// Get statistics
$totalRecords = $mysqli->query("SELECT COUNT(*) as cnt FROM product_purchases")->fetch_assoc()['cnt'];
$totalAmount = $mysqli->query("SELECT SUM(amount) as total FROM product_purchases")->fetch_assoc()['total'];
$stockCount = $mysqli->query("SELECT COUNT(*) as cnt FROM product_purchases WHERE status = 'stock'")->fetch_assoc()['cnt'];
$usedCount = $mysqli->query("SELECT COUNT(*) as cnt FROM product_purchases WHERE status = 'used'")->fetch_assoc()['cnt'];

// Get all products for dropdown
$allProducts = getAllProducts($mysqli);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Purchases</title>
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
        .stat-amount { background: linear-gradient(135deg, #27ae60, #229954); }
        .stat-stock { background: linear-gradient(135deg, #2ecc71, #27ae60); }
        .stat-used { background: linear-gradient(135deg, #95a5a6, #7f8c8d); }
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
        }
        .amount-positive {
            color: #27ae60;
            font-weight: 600;
        }
        .batch-badge {
            background-color: #6c757d;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            display: inline-block;
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
            <h4><i class="fas fa-shopping-cart me-2"></i><?= $mode === 'edit' ? 'Edit Purchase Record' : 'Add New Purchase' ?></h4>
            <a href="product_purchases.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editRecord['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="product_id" class="form-label">Product <span class="text-danger">*</span></label>
                                <select class="form-select" id="product_id" name="product_id" required>
                                    <option value="">Select Product</option>
                                    <?php foreach($allProducts as $product): ?>
                                    <option value="<?= $product['id'] ?>" 
                                        <?= ($editRecord && $editRecord['product_id'] == $product['id']) ? 'selected' : '' ?>>
                                        <?= h($product['product_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="batch_no" class="form-label">Batch Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="batch_no" name="batch_no" 
                                       value="<?= h($editRecord['batch_no'] ?? '') ?>" 
                                       placeholder="BATCH-001" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="quantity" class="form-label">Quantity <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="quantity" name="quantity" min="1" 
                                       value="<?= h($editRecord['quantity'] ?? '1') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="purchase_date" class="form-label">Purchase Date</label>
                                <input type="date" class="form-control" id="purchase_date" name="purchase_date"
                                       value="<?= h($editRecord['purchase_date'] ?? date('Y-m-d')) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="amount" class="form-label">Amount (₹)</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" step="0.01" class="form-control" id="amount" name="amount"
                                           value="<?= h($editRecord['amount'] ?? '') ?>" 
                                           placeholder="0.00">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="payee" class="form-label">Payee</label>
                                <input type="text" class="form-control" id="payee" name="payee"
                                       value="<?= h($editRecord['payee'] ?? '') ?>" 
                                       placeholder="Payee name">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="stock" <?= ($editRecord && $editRecord['status'] == 'stock') ? 'selected' : '' ?>>In Stock</option>
                                    <option value="used" <?= ($editRecord && $editRecord['status'] == 'used') ? 'selected' : '' ?>>Used</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="2"><?= h($editRecord['description'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="remarks" class="form-label">Remarks</label>
                                <textarea class="form-control" id="remarks" name="remarks" rows="2"><?= h($editRecord['remarks'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Record' : 'Save Record' ?>
                        </button>
                        <a href="product_purchases.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4><i class="fas fa-shopping-cart me-2"></i>Product Purchases</h4>
                <p class="text-muted mb-0">Manage purchases | Import Excel to bulk add/update</p>
            </div>
            <div>
                <button type="button" class="btn btn-warning text-dark fw-bold btn-sm me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-excel me-2"></i> Import Excel
                </button>
                <a href="product_purchases.php?mode=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Add Purchase
                </a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-container">
            <div class="stat-card stat-total">
                <h3><?= $totalRecords ?></h3>
                <p><i class="fas fa-shopping-cart me-1"></i> Total Records</p>
            </div>
            <div class="stat-card stat-amount">
                <h3>₹ <?= number_format($totalAmount ?? 0, 2) ?></h3>
                <p><i class="fas fa-rupee-sign me-1"></i> Total Amount</p>
            </div>
            <div class="stat-card stat-stock">
                <h3><?= $stockCount ?></h3>
                <p><i class="fas fa-box me-1"></i> In Stock</p>
            </div>
            <div class="stat-card stat-used">
                <h3><?= $usedCount ?></h3>
                <p><i class="fas fa-tools me-1"></i> Used</p>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search Batch, Payee, Description..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="product_id" class="form-select form-select-sm">
                            <option value="">All Products</option>
                            <?php foreach($allProducts as $product): ?>
                            <option value="<?= $product['id'] ?>" <?= $fproduct_id == $product['id'] ? 'selected' : '' ?>>
                                <?= h($product['product_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="stock" <?= $fstatus === 'stock' ? 'selected' : '' ?>>In Stock</option>
                            <option value="used" <?= $fstatus === 'used' ? 'selected' : '' ?>>Used</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="product_purchases.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Purchases Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>S. No.</th>
                                <th>Date</th>
                                <th>Product</th>
                                
                                <th>Description</th>
                                <th>Batch</th>
                                <th>Quantity</th>
                                <th>Amount</th>
                                <th>Payee</th>
                                <th>Status</th>
                                <th>Remarks</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($purchases && $purchases->num_rows > 0):?>
                                <?php 
                                $serial = 1;
                                while($row = $purchases->fetch_assoc()): 
                                ?>
                                <tr>
                                    <td><?= $serial++ ?></td>
                                    <td><?= formatDate($row['purchase_date'] ?? '') ?></td>
                                    <td class="fw-semibold"><?= h($row['product_name'] ?? '—') ?></td>
                                   
                                    <td><?= h($row['description']) ?: '—' ?></td>
                                    <td>
                                        <span class="batch-badge"><?= h($row['batch_no']) ?></span>
                                    </td>
                                     <td><span class="badge bg-info text-dark"><?= (int)($row['quantity'] ?? 1) ?></span></td>
                                    <td class="<?= ($row['amount'] > 0) ? 'amount-positive' : '' ?>">
                                        <?= formatCurrency($row['amount']) ?>
                                    </td>
                                    <td><?= h($row['payee']) ?: '—' ?></td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-sm badge <?= 
                                                $row['status'] === 'stock' ? 'bg-success' : 'bg-secondary' ?>">
                                                <?= $row['status'] === 'stock' ? 'In Stock' : 'Used' ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td><small><?= h($row['remarks']) ?: '—' ?></small></td>
                                    
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="product_purchases.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Delete this record (Batch: <?= h($row['batch_no']) ?>)?')">
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
                                    <td colspan="11" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-shopping-cart fa-3x mb-3"></i>
                                            <h5>No purchase records found</h5>
                                            <p class="mb-0">Add product purchase records or import via Excel.</p>
                                            <a href="product_purchases.php?mode=add" class="btn btn-primary mt-3">
                                                <i class="fas fa-plus me-1"></i> Add First Record
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
                        <i class="fas fa-file-excel me-2"></i>Import Purchases from Excel
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
                                <li><strong>Purchase Date</strong> - Optional (Default is Today)</li>
                                <li><strong>Product Name</strong> - Required (Will Auto-create if not exists)</li>
                                <li><strong>Quantity</strong> - Number (Default 1)</li>
                                <li><strong>Batch No</strong> - Required (e.g. BATCH-001)</li>
                                <li><strong>Amount</strong> - Optional (e.g. 500.00)</li>
                                <li><strong>Payee</strong> - Vendor / Payee Name</li>
                                <li><strong>Status</strong> - "stock" or "used"</li>
                                <li><strong>Description</strong> - Optional</li>
                                <li><strong>Remarks</strong> - Optional</li>
                            </ol>
                            
                            <div class="border-top pt-2 mt-2">
                                <p class="mb-1"><strong><i class="fas fa-exclamation-triangle me-1"></i> Rules:</strong></p>
                                <ul class="mb-0">
                                    <li>New records will be <span class="badge bg-success">INSERTED</span></li>
                                    <li>Existing records (matching <strong>Batch No + Product ID</strong>) will be <span class="badge bg-warning text-dark">UPDATED</span></li>
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

            // Auto-submit for filter selects
            const searchForm = document.querySelector('form[method="get"]');
            if (searchForm) {
                const filterSelects = searchForm.querySelectorAll('select[name="product_id"], select[name="status"]');
                filterSelects.forEach(select => {
                    select.addEventListener('change', function() {
                        searchForm.submit();
                    });
                });
            }
        });

        // Download sample Excel file formatted for purchases
        function downloadSample() {
            // Create sample CSV content
            const sampleContent = "Purchase Date,Product Name,Quantity,Batch No,Amount,Payee,Status,Description,Remarks\n" +
                                 "10/24/2025,Logitech Mouse,10,BATCH-001,2500,Amazon,stock,Wireless mouse,Good condition\n" +
                                 "10/25/2025,Dell Keyboard,2,BATCH-002,1200,Flipkart,used,Wired keyboard,Allocated to team\n";
            
            const blob = new Blob([sampleContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sample_purchase_import.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>