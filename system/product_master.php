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
    $base = 'product_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $product_name = trim($_POST['product_name'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        // Basic validation
        if ($product_name === '') {
            redirect_with(['msg' => 'Product Name is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Check if product name already exists
            $check = $mysqli->prepare("SELECT id FROM product_master WHERE product_name = ?");
            $check->bind_param("s", $product_name);
            $check->execute();
            $check->store_result();
            
            if ($check->num_rows > 0) {
                $check->close();
                redirect_with(['msg' => 'Product Name already exists. Please use a different name.', 'type' => 'danger']);
            }
            $check->close();
            
            // Insert new product
            $stmt = $mysqli->prepare("INSERT INTO product_master (product_name, status, created_at, created_ip) VALUES (?, ?, NOW(), ?)");
            
            $stmt->bind_param("sss", 
                $product_name, 
                $status, 
                $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Product '$product_name' added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding product: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Check if product name already exists for another record
                $check = $mysqli->prepare("SELECT id FROM product_master WHERE product_name = ? AND id != ?");
                $check->bind_param("si", $product_name, $id);
                $check->execute();
                $check->store_result();
                
                if ($check->num_rows > 0) {
                    $check->close();
                    redirect_with(['msg' => 'Product Name already exists. Please use a different name.', 'type' => 'danger']);
                }
                $check->close();
                
                // Update product
                $sql = "UPDATE product_master SET 
                        product_name = ?, 
                        status = ?, 
                        updated_at = NOW(), 
                        updated_ip = ? 
                        WHERE id = ?";
                
                $stmt = $mysqli->prepare($sql);
                
                $stmt->bind_param("sssi", 
                    $product_name, 
                    $status, 
                    $_SERVER['REMOTE_ADDR'], 
                    $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Product updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating product: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $product_name = '';
        
        if ($id > 0) {
            // Get product name for message
            $res = $mysqli->prepare("SELECT product_name FROM product_master WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($product_name);
            $res->fetch();
            $res->close();
            
            // Delete product
            $del = $mysqli->prepare("DELETE FROM product_master WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Product '$product_name' deleted", 'type' => 'warning']);
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editProduct = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT id, product_name, status, created_at, created_ip, updated_at, updated_ip FROM product_master WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editProduct = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editProduct) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';

// Build query
$sql = "SELECT id, product_name, status, created_at, created_ip, updated_at, updated_ip FROM product_master WHERE 1=1";

$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(product_name LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $types .= 's';
}

if ($fstatus !== '') {
    $cond[] = "status = ?";
    $params[] = $fstatus;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " AND " . implode(" AND ", $cond);
}

$sql .= " ORDER BY product_name ASC";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $products = $stmt->get_result();
} else {
    $products = $mysqli->query($sql);
}

// Get statistics
$totalProducts = $mysqli->query("SELECT COUNT(*) as cnt FROM product_master")->fetch_assoc()['cnt'];
$activeProducts = $mysqli->query("SELECT COUNT(*) as cnt FROM product_master WHERE status = 'active'")->fetch_assoc()['cnt'];
$inactiveProducts = $mysqli->query("SELECT COUNT(*) as cnt FROM product_master WHERE status = 'inactive'")->fetch_assoc()['cnt'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Master</title>
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
        .stat-inactive { background: linear-gradient(135deg, #95a5a6, #7f8c8d); }
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
            <h4><i class="fas fa-box me-2"></i><?= $mode === 'edit' ? 'Edit Product' : 'Add New Product' ?></h4>
            <a href="product_master.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editProduct['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label for="product_name" class="form-label">Product Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="product_name" name="product_name"
                                       value="<?= h($editProduct['product_name'] ?? '') ?>" 
                                       placeholder="Enter product name" required>
                                <small class="text-muted">Unique product name</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active" <?= ($editProduct && $editProduct['status'] == 'active') ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= ($editProduct && $editProduct['status'] == 'inactive') ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Product' : 'Save Product' ?>
                        </button>
                        <a href="product_master.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-boxes me-2"></i>Product Master</h4>
            <a href="product_master.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add Product
            </a>
        </div>

        <!-- Statistics -->
        <div class="stats-container">
            <div class="stat-card stat-total">
                <h3><?= $totalProducts ?></h3>
                <p><i class="fas fa-boxes me-1"></i> Total Products</p>
            </div>
            <div class="stat-card stat-active">
                <h3><?= $activeProducts ?></h3>
                <p><i class="fas fa-check-circle me-1"></i> Active</p>
            </div>
            <div class="stat-card stat-inactive">
                <h3><?= $inactiveProducts ?></h3>
                <p><i class="fas fa-times-circle me-1"></i> Inactive</p>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-6">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search by Product Name..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-4">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="active" <?= $fstatus === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $fstatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="product_master.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Products Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>S.No</th>
                                <th>Product Name</th>
                                <th>Status</th>
                                <th>Created</th>
                                <!--th>Created IP</th-->
                                <th>Updated</th>
                                <!--th>Updated IP</th-->
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($products && $products->num_rows > 0): $i=0; ?>
                                <?php while($row = $products->fetch_assoc()): $i=$i+1;?>
                                <tr>
                                    <td><?= $i; ?></td>
                                    <td class="fw-semibold"><?= h($row['product_name']) ?></td>
                                    <td>
                                        <?php 
                                        $statusClass = ($row['status'] == 'active') ? 'bg-success' : 'bg-secondary';
                                        $statusText = ($row['status'] == 'active') ? 'Active' : 'Inactive';
                                        ?>
                                        <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                                    </td>
                                    <td><small><?= formatDate($row['created_at'] ?? '') ?></small></td>
                                    <!--td><small><?= h($row['created_ip'] ?? '—') ?></small></td-->
                                    <td><small><?= formatDate($row['updated_at'] ?? '') ?></small></td>
                                    <!--td><small><?= h($row['updated_ip'] ?? '—') ?></small></td-->
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="product_master.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Delete product <?= h($row['product_name']) ?>?')">
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
                                    <td colspan="8" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-box-open fa-3x mb-3"></i>
                                            <h5>No products found</h5>
                                            <p class="mb-0">Add products to your master list.</p>
                                            <a href="product_master.php?mode=add" class="btn btn-primary mt-3">
                                                <i class="fas fa-plus me-1"></i> Add First Product
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
                const filterSelects = searchForm.querySelectorAll('select[name="status"]');
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