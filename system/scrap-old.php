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
    $base = 'scrap.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $scrap_date = trim($_POST['scrap_date'] ?? '');
        $material = trim($_POST['material'] ?? '');
        $sold_person = trim($_POST['sold_person'] ?? '');
        $count = trim($_POST['count'] ?? '');
        $price = trim($_POST['price'] ?? '');
        $amount = trim($_POST['amount'] ?? '');
        $comments = trim($_POST['comments'] ?? '');
        
        // Basic validation
        if ($scrap_date === '') {
            redirect_with(['msg' => 'Date is required.', 'type' => 'danger']);
        }
        
        if ($material === '') {
            redirect_with(['msg' => 'Material is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Insert new scrap record
            $stmt = $mysqli->prepare("INSERT INTO tbl_scrap (scrap_date, material, sold_person, count, price, amount, comments, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->bind_param("sssiddss", $scrap_date, $material, $sold_person, $count, $price, $amount, $comments, $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Scrap '$material' added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding scrap: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Update scrap record
                $sql = "UPDATE tbl_scrap SET 
                        scrap_date = ?, 
                        material = ?, 
                        sold_person = ?, 
                        count = ?, 
                        price = ?, 
                        amount = ?, 
                        comments = ?, 
                        updated_at = NOW(), 
                        updated_ip = ? 
                        WHERE id = ?";
                
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("sssiddssi", $scrap_date, $material, $sold_person, $count, $price, $amount, $comments, $_SERVER['REMOTE_ADDR'], $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Scrap record updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating scrap: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $material = '';
        
        if ($id > 0) {
            // Get material name for message
            $res = $mysqli->prepare("SELECT material FROM tbl_scrap WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($material);
            $res->fetch();
            $res->close();
            
            // Delete scrap record
            $del = $mysqli->prepare("DELETE FROM tbl_scrap WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Scrap '$material' deleted", 'type' => 'warning']);
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editScrap = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_scrap WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editScrap = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editScrap) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fsoldperson = $_GET['sold_person'] ?? '';
$fmonth = $_GET['month'] ?? '';
$fyear = $_GET['year'] ?? '';

// Build query
$sql = "SELECT * FROM tbl_scrap WHERE 1=1";

$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(material LIKE ? OR sold_person LIKE ? OR comments LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($fsoldperson !== '') {
    $cond[] = "sold_person = ?";
    $params[] = $fsoldperson;
    $types .= 's';
}

if ($fmonth !== '' && $fyear !== '') {
    $cond[] = "MONTH(scrap_date) = ? AND YEAR(scrap_date) = ?";
    $params[] = (int)$fmonth;
    $params[] = (int)$fyear;
    $types .= 'ii';
} elseif ($fyear !== '') {
    $cond[] = "YEAR(scrap_date) = ?";
    $params[] = (int)$fyear;
    $types .= 'i';
}

if (!empty($cond)) {
    $sql .= " AND " . implode(" AND ", $cond);
}

$sql .= " ORDER BY scrap_date DESC, id DESC";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $scrapRecords = $stmt->get_result();
} else {
    $scrapRecords = $mysqli->query($sql);
}

// Get TODAY's statistics
$today = date('Y-m-d');
$todayStatsQuery = $mysqli->prepare("SELECT COUNT(*) as count, IFNULL(SUM(amount), 0) as total FROM tbl_scrap WHERE DATE(scrap_date) = ?");
$todayStatsQuery->bind_param("s", $today);
$todayStatsQuery->execute();
$todayStats = $todayStatsQuery->get_result()->fetch_assoc();
$todayStatsQuery->close();

$todayAmount = $todayStats['total'];
$todayCount = $todayStats['count'];

// Get unique sold persons for filter
$soldPersons = $mysqli->query("SELECT DISTINCT sold_person FROM tbl_scrap WHERE sold_person IS NOT NULL AND sold_person != '' ORDER BY sold_person ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scrap</title>
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
        .stats-card {
            padding: 20px;
            border-radius: 8px;
            color: white;
            margin-bottom: 20px;
        }
        .stats-card h3 {
            margin: 0;
            font-size: 32px;
            font-weight: bold;
        }
        .stats-card p {
            margin: 5px 0 0 0;
            font-size: 14px;
        }
        .stat-total { background: linear-gradient(135deg, #28a745, #218838); }
        .stat-count { background: linear-gradient(135deg, #17a2b8, #138496); }
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
            <h4><i class="fas fa-recycle me-2"></i><?= $mode === 'edit' ? 'Edit Scrap Record' : 'Add New Scrap Sale' ?></h4>
            <a href="scrap.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off" id="scrapForm">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editScrap['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="scrap_date" class="form-label">Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="scrap_date" name="scrap_date" 
                                       value="<?= h($editScrap['scrap_date'] ?? date('Y-m-d')) ?>" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="material" class="form-label">Material <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="material" name="material" 
                                       value="<?= h($editScrap['material'] ?? '') ?>" 
                                       placeholder="Enter Material" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="sold_person" class="form-label">Sold Person</label>
                                <input type="text" class="form-control" id="sold_person" name="sold_person"
                                       value="<?= h($editScrap['sold_person'] ?? '') ?>" 
                                       placeholder="Enter Sold Person">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="count" class="form-label">Count</label>
                                <input type="text" class="form-control" id="count" name="count"
                                       value="<?= h($editScrap['count'] ?? '') ?>" 
                                       placeholder="Enter Count">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="price" class="form-label">Price (per unit)</label>
                                <input type="number" step="0.01" class="form-control" id="price" name="price"
                                       value="<?= h($editScrap['price'] ?? '') ?>" 
                                       placeholder="0.00">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="amount" class="form-label">Amount <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" class="form-control" id="amount" name="amount"
                                       value="<?= h($editScrap['amount'] ?? '') ?>" 
                                       placeholder="0.00" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="comments" class="form-label">Comments</label>
                                <textarea class="form-control" id="comments" name="comments" rows="2"><?= h($editScrap['comments'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Record' : 'Save Record' ?>
                        </button>
                        <a href="scrap.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-recycle me-2"></i>Scrap Sales Management</h4>
            <a href="scrap.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add Scrap Sale
            </a>
        </div>

        <!-- TODAY'S Statistics -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="stats-card stat-total">
                    <h3>₹ <?= number_format($todayAmount, 2) ?></h3>
                    <p><i class="fas fa-calendar-day"></i> Today's Sales Amount (<?= date('d-M-Y') ?>)</p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stats-card stat-count">
                    <h3><?= $todayCount ?></h3>
                    <p><i class="fas fa-list"></i> Today's Records (<?= date('d-M-Y') ?>)</p>
                </div>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search material, person..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="sold_person" class="form-select form-select-sm">
                            <option value="">All Sold Persons</option>
                            <?php while($sp = $soldPersons->fetch_assoc()): ?>
                                <option value="<?= h($sp['sold_person']) ?>" <?= $fsoldperson === $sp['sold_person'] ? 'selected' : '' ?>>
                                    <?= h($sp['sold_person']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="month" class="form-select form-select-sm">
                            <option value="">All Months</option>
                            <?php for($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $fmonth == $m ? 'selected' : '' ?>>
                                    <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="year" class="form-select form-select-sm">
                            <option value="">All Years</option>
                            <?php 
                            $currentYear = date('Y');
                            for($y = $currentYear; $y >= $currentYear - 5; $y--): ?>
                                <option value="<?= $y ?>" <?= $fyear == $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="scrap.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Scrap Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Material</th>
                                <th>Sold Person</th>
                                <th>Count</th>
                                <th>Price</th>
                                <th>Amount</th>
                                <th>Comments</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($scrapRecords && $scrapRecords->num_rows > 0): ?>
                                <?php while($row = $scrapRecords->fetch_assoc()): ?>
                                <tr>
                                    <td><?= formatDate($row['scrap_date']) ?></td>
                                    <td class="fw-semibold"><?= h($row['material']) ?></td>
                                    <td><?= h($row['sold_person']) ?></td>
                                    <td><?= h($row['count']) ?></td>
                                    <td>₹ <?= number_format($row['price'], 2) ?></td>
                                    <td class="fw-bold text-success">₹ <?= number_format($row['amount'], 2) ?></td>
                                    <td><small><?= h($row['comments']) ?></small></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="scrap.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Delete scrap record for <?= h($row['material']) ?>?')">
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
                                            <i class="fas fa-recycle fa-3x mb-3"></i>
                                            <h5>No scrap records found</h5>
                                            <p class="mb-0">Start tracking your scrap sales.</p>
                                            <a href="scrap.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Scrap Sale
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

            // Auto-submit ONLY for the search/filter form (method="get")
            const searchForm = document.querySelector('form[method="get"]');
            if (searchForm) {
                const filterSelects = searchForm.querySelectorAll('select[name="sold_person"], select[name="month"], select[name="year"]');
                filterSelects.forEach(select => {
                    select.addEventListener('change', function() {
                        searchForm.submit();
                    });
                });
            }

            // Auto-calculate amount when price or count changes
            const priceInput = document.getElementById('price');
            const countInput = document.getElementById('count');
            const amountInput = document.getElementById('amount');

            if (priceInput && countInput && amountInput) {
                function calculateAmount() {
                    const price = parseFloat(priceInput.value) || 0;
                    const countStr = countInput.value.trim();
                    
                    // Try to extract numeric value from count
                    const countMatch = countStr.match(/[\d.]+/);
                    const count = countMatch ? parseFloat(countMatch[0]) : 0;
                    
                    if (price > 0 && count > 0) {
                        const amount = price * count;
                        amountInput.value = amount.toFixed(2);
                    }
                }

                priceInput.addEventListener('input', calculateAmount);
                countInput.addEventListener('input', calculateAmount);
            }
        });
    </script>
</body>
</html>
