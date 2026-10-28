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

function redirect_with($params =[]) {
    $base = 'scrap.php';
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
            $skipped_count = 0;
            $error_details =[];
            
            // To track duplicates within the uploaded file itself
            $seen_hashes =[];
            
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header Row

                // --- COLUMN MAPPING ---
                $scrap_date_raw = $r[0] ?? '';
                $material = trim($r[1] ?? '');
                $sold_person = trim($r[2] ?? '');
                $count = trim($r[3] ?? '');
                $price = trim($r[4] ?? '');
                $amount = trim($r[5] ?? '');
                $comments = trim($r[6] ?? '');
                
                // Skip if essential data is missing
                if (empty($material)) {
                    $skipped_count++;
                    $error_details[] = "Row " . ($k + 1) . ": Missing Material";
                    continue;
                }

                // Handle Date conversion (Excel numeric dates)
                $scrap_date = NULL;
                if (!empty($scrap_date_raw)) {
                    if (is_numeric($scrap_date_raw)) {
                        if (class_exists('Shuchkin\SimpleXLSX')) {
                            $scrap_date = date('Y-m-d', Shuchkin\SimpleXLSX::unixstamp($scrap_date_raw));
                        } else {
                            $unix = ($scrap_date_raw - 25569) * 86400;
                            $scrap_date = gmdate("Y-m-d", $unix);
                        }
                    } else {
                        $ts = strtotime($scrap_date_raw);
                        if ($ts !== false) $scrap_date = date('Y-m-d', $ts);
                    }
                } else {
                    $scrap_date = date('Y-m-d'); // Default fallback for required field
                }

                // Parse numerical values
                $price = is_numeric($price) ? (float)$price : 0.00;
                $amount = is_numeric($amount) ? (float)$amount : 0.00;
                $count_val = (int)$count;

                // Create a unique hash for this row to prevent duplicates inside the Excel file
                $rowHash = md5($scrap_date . $material . $sold_person . $count_val . $amount);
                if (isset($seen_hashes[$rowHash])) {
                    $skipped_count++;
                    $error_details[] = "Row " . ($k + 1) . ": Exact duplicate inside the Excel file skipped";
                    continue;
                }
                $seen_hashes[$rowHash] = true;

                // Check if exact scrap record already exists in database (Prevents Duplicate Insertion)
                $checkStmt = $mysqli->prepare("SELECT id FROM tbl_scrap WHERE scrap_date = ? AND material = ? AND sold_person = ? AND count = ? AND amount = ?");
                $checkStmt->bind_param("sssid", $scrap_date, $material, $sold_person, $count_val, $amount);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->num_rows > 0;
                $checkStmt->close();

                $ip = $_SERVER['REMOTE_ADDR'];

                if (!$exists) {
                    // Insert new record
                    $insertStmt = $mysqli->prepare("INSERT INTO tbl_scrap 
                        (scrap_date, material, sold_person, count, price, amount, comments, created_at, created_ip) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
                    
                    $insertStmt->bind_param("sssiddss", 
                        $scrap_date, $material, $sold_person, $count, $price, $amount, $comments, $ip
                    );
                    
                    if ($insertStmt->execute()) {
                        $imported_count++;
                    } else {
                        $skipped_count++;
                        $error_details[] = "Row " . ($k + 1) . ": " . $mysqli->error;
                    }
                    $insertStmt->close();
                } else {
                    // Record already exists - Skip to avoid duplication
                    $skipped_count++;
                    $error_details[] = "Row " . ($k + 1) . ": Duplicate record found in database, skipped";
                }
            }
            
            $msg = "Process Complete.<br>";
            $msg .= "<strong>Imported:</strong> $imported_count new scrap records.<br>";
            $msg .= "<strong>Skipped:</strong> $skipped_count duplicate or invalid rows.";
            
            if (!empty($error_details)) {
                $msg .= "<br><small class='text-danger'>" . implode("<br>", array_slice($error_details, 0, 5)) . "</small>";
                if (count($error_details) > 5) {
                    $msg .= "<br>... and " . (count($error_details) - 5) . " more notices.";
                }
            }
            
            $type = ($imported_count > 0) ? 'success' : 'warning';
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
    
    if ($action !== 'import_excel') {
        redirect_with();
    }
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
$params =[];
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

// Get TOTAL statistics (all time)
$totalStatsQuery = $mysqli->query("SELECT IFNULL(SUM(amount), 0) as total_amount FROM tbl_scrap");
$totalStats = $totalStatsQuery->fetch_assoc();
$totalAmount = $totalStats['total_amount'];

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
            <?= $_GET['msg'] ?>
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
            <div>
                <h4><i class="fas fa-recycle me-2"></i>Scrap Sales</h4>
                <p class="text-muted mb-0">Manage scrap records | Import Excel to bulk add</p>
            </div>
            <div>
                <button type="button" class="btn btn-warning text-dark fw-bold btn-sm me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-excel me-2"></i> Import Excel
                </button>
                <a href="scrap.php?mode=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Add Scrap Sale
                </a>
            </div>
        </div>

        <!-- Statistics Cards with Total Amount -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stats-card" style="background: linear-gradient(135deg, #28a745, #218838);">
                    <h3>₹ <?= number_format($todayAmount, 2) ?></h3>
                    <p><i class="fas fa-calendar-day"></i> Today's Sales Amount (<?= date('d-M-Y') ?>)</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card" style="background: linear-gradient(135deg, #17a2b8, #138496);">
                    <h3><?= $todayCount ?></h3>
                    <p><i class="fas fa-list"></i> Today's Records (<?= date('d-M-Y') ?>)</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card" style="background: linear-gradient(135deg, #ffc107, #e0a800); color: #212529;">
                    <h3>₹ <?= number_format($totalAmount, 2) ?></h3>
                    <p><i class="fas fa-chart-line"></i> Total Amount (All Records)</p>
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
                                <th>S.No</th>
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
                            <?php if($scrapRecords && $scrapRecords->num_rows > 0): $i=0;?>
                                <?php while($row = $scrapRecords->fetch_assoc()): $i=$i+1; ?>
                                <tr>
                                    <td><?= $i; ?></td>
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
                                    <td colspan="9" class="text-center py-5">
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

    <!-- IMPORT MODAL -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-file-excel me-2"></i>Import Scrap Records from Excel
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
                                <li><strong>Date</strong> - Excel Date or format like MM/DD/YYYY</li>
                                <li><strong>Material</strong> - Required (e.g., Iron Scrap)</li>
                                <li><strong>Sold person</strong> - Optional</li>
                                <li><strong>Count</strong> - Optional (e.g., 50 kg or 50)</li>
                                <li><strong>Price</strong> - Optional (per unit price)</li>
                                <li><strong>Amount</strong> - Optional (Total sales amount)</li>
                                <li><strong>Comments</strong> - Optional</li>
                            </ol>
                            
                            <div class="border-top pt-2 mt-2">
                                <p class="mb-1"><strong><i class="fas fa-exclamation-triangle me-1"></i> Rules:</strong></p>
                                <ul class="mb-0">
                                    <li>New records will be <span class="badge bg-success">INSERTED</span></li>
                                    <li>Exact duplicate records (matching Date, Material, Person, Count, and Amount) will be <span class="badge bg-warning">SKIPPED</span> to prevent duplication.</li>
                                    <li>Rows missing the "Material" column will be <span class="badge bg-danger">SKIPPED</span></li>
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

        // Download sample Excel file formatted for scrap
        function downloadSample() {
            // Create sample CSV content mapped exactly to your requested format
            const sampleContent = "Date,Material,Sold person,Count,Price,Amount,Comments\n" +
                                 "10/24/2025,Iron Scrap,Raju,100 kg,25,2500,Old iron parts\n" +
                                 "10/25/2025,Copper Wire,Ramesh,10 kg,400,4000,Waste wiring\n" +
                                 "10/26/2025,Plastic,Local Vendor,50 kg,15,750,Broken casings";
            
            const blob = new Blob([sampleContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sample_scrap_import.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>