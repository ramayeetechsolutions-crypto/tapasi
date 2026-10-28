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

function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

function redirect_with($params = []) {
    $base = 'commission.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Get all TDS members for dropdown
$members_result = $mysqli->query("SELECT member_id, full_name, tds_per FROM tds_members WHERE status = 'Active' ORDER BY full_name");
$tds_members = [];
while($row = $members_result->fetch_assoc()) {
    $tds_members[$row['member_id']] = $row;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $member_id = trim($_POST['member_id'] ?? '');
        $commission_date = $_POST['commission_date'] ?? '';
        $month = trim($_POST['month'] ?? '');
        $commission_amount = !empty($_POST['commission_amount']) ? (float)$_POST['commission_amount'] : 0;
        
        // Basic validation
        $errors = [];
        if (empty($member_id)) $errors[] = 'Member ID is required';
        if (empty($commission_date)) $errors[] = 'Commission Date is required';
        if (empty($month)) $errors[] = 'Month is required';
        if (empty($commission_amount) || $commission_amount <= 0) $errors[] = 'Valid Commission Amount is required';
        
        if (!empty($errors)) {
            $_SESSION['form_data'] = [
                'member_id' => $member_id,
                'commission_date' => $commission_date,
                'month' => $month,
                'commission_amount' => $commission_amount
            ];
            redirect_with(['msg' => implode(', ', $errors), 'type' => 'danger']);
        }
        
        // Get member details and calculate TDS
        $member_name = $tds_members[$member_id]['full_name'] ?? '';
        $tds_percentage = $tds_members[$member_id]['tds_per'] ?? 0;
        $tds_amount = ($commission_amount * $tds_percentage) / 100;
        $net_amount = $commission_amount - $tds_amount;
        
        if ($action === 'add') {
            // Insert new commission
            $stmt = $mysqli->prepare("INSERT INTO commission_master (member_id, member_name, commission_date, month, commission_amount, tds_percentage, tds_amount, net_amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
            if ($stmt) {
                $stmt->bind_param("ssssdddd", $member_id, $member_name, $commission_date, $month, $commission_amount, $tds_percentage, $tds_amount, $net_amount);
                
                if ($stmt->execute()) {
                    unset($_SESSION['form_data']);
                    redirect_with(['msg' => "Commission record added successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error adding commission: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            } else {
                redirect_with(['msg' => 'Database preparation error', 'type' => 'danger']);
            }
        }
        
        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Update commission
                $stmt = $mysqli->prepare("UPDATE commission_master SET member_id = ?, member_name = ?, commission_date = ?, month = ?, commission_amount = ?, tds_percentage = ?, tds_amount = ?, net_amount = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("ssssddddi", $member_id, $member_name, $commission_date, $month, $commission_amount, $tds_percentage, $tds_amount, $net_amount, $id);
                    
                    if ($stmt->execute()) {
                        redirect_with(['msg' => "Commission record updated successfully", 'type' => 'success']);
                    } else {
                        redirect_with(['msg' => 'Error updating commission: ' . $stmt->error, 'type' => 'danger']);
                    }
                    $stmt->close();
                } else {
                    redirect_with(['msg' => 'Database preparation error', 'type' => 'danger']);
                }
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $member_name = '';
        
        if ($id > 0) {
            // Get commission details for message
            $res = $mysqli->prepare("SELECT member_name, month FROM commission_master WHERE id = ?");
            if ($res) {
                $res->bind_param("i", $id);
                $res->execute();
                $res->bind_result($member_name, $month);
                $res->fetch();
                $res->close();
                
                // Delete commission
                $del = $mysqli->prepare("DELETE FROM commission_master WHERE id = ?");
                if ($del) {
                    $del->bind_param("i", $id);
                    $del->execute();
                    $del->close();
                    
                    redirect_with(['msg' => "Commission record for $member_name ($month) deleted", 'type' => 'warning']);
                }
            }
        }
    }
    
    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT status, member_name, month FROM commission_master WHERE id = ?");
            if ($res) {
                $res->bind_param("i", $id);
                $res->execute();
                $res->bind_result($current_status, $member_name, $month);
                $res->fetch();
                $res->close();
                
                if ($member_name) {
                    $newStatus = ($current_status === 'Pending') ? 'Paid' : 'Pending';
                    
                    $upd = $mysqli->prepare("UPDATE commission_master SET status = ? WHERE id = ?");
                    if ($upd) {
                        $upd->bind_param("si", $newStatus, $id);
                        $upd->execute();
                        $upd->close();
                        
                        redirect_with(['msg' => "Commission status for $member_name ($month) changed to $newStatus", 'type' => 'info']);
                    }
                }
            }
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editCommission = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT id, member_id, member_name, commission_date, month, commission_amount, tds_percentage, tds_amount, net_amount, status, created_at FROM commission_master WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $editCommission = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
    if (!$editCommission) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fmember = $_GET['member'] ?? '';
$fmonth = $_GET['month'] ?? '';
$fstatus = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'commission_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query
$sql = "SELECT id, member_id, member_name, commission_date, month, commission_amount, tds_percentage, tds_amount, net_amount, status, created_at FROM commission_master";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(member_name LIKE ? OR member_id LIKE ? OR month LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($fmember !== '') {
    $cond[] = "member_id = ?";
    $params[] = $fmember;
    $types .= 's';
}

if ($fmonth !== '') {
    $cond[] = "month = ?";
    $params[] = $fmonth;
    $types .= 's';
}

if ($fstatus === 'Pending' || $fstatus === 'Paid') {
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
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $commissions = $stmt->get_result();
    } else {
        $commissions = false;
    }
} else {
    $commissions = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$commissions) {
    $commissions = false;
}

// Get unique months for filter
$months_result = $mysqli->query("SELECT DISTINCT month FROM commission_master ORDER BY FIELD(month, 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December')");
$unique_months = [];
while($row = $months_result->fetch_assoc()) {
    $unique_months[] = $row['month'];
}

// Get form data from session if exists
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commission</title>
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
        .amount-cell {
            text-align: right;
            font-weight: 500;
        }
        .status-badge {
            font-size: 0.75em;
        }
        .calculation-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php if (file_exists('header.php')) require 'header.php'; ?>
    
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
            <h4><i class="fas fa-money-bill-wave me-2"></i><?= $mode === 'edit' ? 'Edit Commission Record' : 'Add New Commission Record' ?></h4>
            <a href="commission.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off" id="commissionForm">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editCommission['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="member_id" class="form-label">Member <span class="text-danger">*</span></label>
                                <select class="form-select" id="member_id" name="member_id" required>
                                    <option value="">Select Member</option>
                                    <?php foreach($tds_members as $member_id => $member): ?>
                                        <option value="<?= h($member_id) ?>" 
                                            <?= (($editCommission['member_id'] ?? $formData['member_id'] ?? '') === $member_id) ? 'selected' : '' ?>
                                            data-tds-per="<?= h($member['tds_per']) ?>">
                                            <?= h($member['full_name']) ?> (<?= h($member_id) ?>) - TDS: <?= h($member['tds_per']) ?>%
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="commission_date" class="form-label">Commission Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="commission_date" name="commission_date" 
                                       value="<?= h($editCommission['commission_date'] ?? $formData['commission_date'] ?? date('Y-m-d')) ?>" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="month" class="form-label">Month <span class="text-danger">*</span></label>
                                <select class="form-select" id="month" name="month" required>
                                    <option value="">Select Month</option>
                                    <?php 
                                    $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                                    foreach($months as $month): ?>
                                        <option value="<?= h($month) ?>" <?= (($editCommission['month'] ?? $formData['month'] ?? '') === $month) ? 'selected' : '' ?>>
                                            <?= h($month) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="commission_amount" class="form-label">Commission Amount (₹) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="commission_amount" name="commission_amount" 
                                       value="<?= h($editCommission['commission_amount'] ?? $formData['commission_amount'] ?? '0') ?>" 
                                       placeholder="Enter commission amount" step="0.01" min="0" required>
                            </div>
                        </div>
                    </div>

                    <!-- Calculation Preview -->
                    <div class="calculation-box" id="calculationPreview" style="display: none;">
                        <h6 class="mb-3">Commission Calculation Preview:</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <strong>Commission Amount:</strong>
                                <div id="previewCommission">₹0.00</div>
                            </div>
                            <div class="col-md-4">
                                <strong>TDS Percentage:</strong>
                                <div id="previewTdsPer">0%</div>
                            </div>
                            <div class="col-md-4">
                                <strong>TDS Amount:</strong>
                                <div id="previewTdsAmount" class="text-danger">₹0.00</div>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-12">
                                <strong>Net Amount Payable:</strong>
                                <div id="previewNetAmount" class="text-success h5">₹0.00</div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Commission' : 'Save Commission' ?>
                        </button>
                        <a href="commission.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-money-bill-wave me-2"></i>Commission</h4>
            <a href="commission.php?mode=add" class="btn btn-success">
                <i class="fas fa-plus me-1"></i> Add Commission
            </a>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control" 
                               placeholder="Search by member name, ID, month..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="member" class="form-select">
                            <option value="">All Members</option>
                            <?php foreach($tds_members as $member_id => $member): ?>
                                <option value="<?= h($member_id) ?>" <?= $fmember === $member_id ? 'selected' : '' ?>>
                                    <?= h($member['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="month" class="form-select">
                            <option value="">All Months</option>
                            <?php foreach($unique_months as $month): ?>
                                <option value="<?= h($month) ?>" <?= $fmonth === $month ? 'selected' : '' ?>>
                                    <?= h($month) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="Pending" <?= $fstatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Paid" <?= $fstatus === 'Paid' ? 'selected' : '' ?>>Paid</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="sort" class="form-select">
                            <option value="commission_date" <?= $sort === 'commission_date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="member_name" <?= $sort === 'member_name' ? 'selected' : '' ?>>Sort by Name</option>
                            <option value="month" <?= $sort === 'month' ? 'selected' : '' ?>>Sort by Month</option>
                            <option value="commission_amount" <?= $sort === 'commission_amount' ? 'selected' : '' ?>>Sort by Amount</option>
                        </select>
                    </div>
                    <!-- <div class="col-md-1">
                        <select name="dir" class="form-select">
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Desc</option>
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Asc</option>
                        </select>
                    </div> -->
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="commission.php" class="btn btn-secondary">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Commissions Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="60">S.No</th>
                                <th>Member ID</th>
                                <th>Member Name</th>
                                <th width="100">Date</th>
                                <th width="100">Month</th>
                                <th width="120">Commission (₹)</th>
                                <th width="100">TDS %</th>
                                <th width="120">TDS Amount (₹)</th>
                                <th width="120">Net Amount (₹)</th>
                                <th width="100">Status</th>
                                <th width="150" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($commissions && $commissions->num_rows > 0): ?>
                                <?php while($row = $commissions->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?= (int)$row['id'] ?></td>
                                    <td>
                                        <span class="badge bg-primary"><?= h($row['member_id']) ?></span>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= h($row['member_name']) ?></span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= h(formatDate($row['commission_date'])) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?= h($row['month']) ?></span>
                                    </td>
                                    <td class="amount-cell">
                                        ₹<?= number_format($row['commission_amount'], 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-warning"><?= $row['tds_percentage'] ?>%</span>
                                    </td>
                                    <td class="amount-cell text-danger">
                                        ₹<?= number_format($row['tds_amount'], 2) ?>
                                    </td>
                                    <td class="amount-cell text-success">
                                        <strong>₹<?= number_format($row['net_amount'], 2) ?></strong>
                                    </td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $row['status'] === 'Paid' ? 'btn-success' : 'btn-warning' ?> status-badge">
                                                <?= h($row['status']) ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="commission.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit Commission">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this commission record?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Commission">
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
                                            <i class="fas fa-money-bill-wave fa-3x mb-3"></i>
                                            <h5>N/A for commission records found</h5>
                                            <p class="mb-0">Get started by adding your first commission record.</p>
                                            <a href="commission.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Commission
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

        <!-- Summary Card -->
        <?php if($commissions && $commissions->num_rows > 0): ?>
        <?php
            // Reset pointer and calculate totals
            $commissions->data_seek(0);
            $total_records = 0;
            $total_commission = 0;
            $total_tds = 0;
            $total_net = 0;
            $pending_count = 0;
            $paid_count = 0;
            
            while($row = $commissions->fetch_assoc()) {
                $total_records++;
                $total_commission += $row['commission_amount'];
                $total_tds += $row['tds_amount'];
                $total_net += $row['net_amount'];
                if ($row['status'] === 'Pending') $pending_count++;
                if ($row['status'] === 'Paid') $paid_count++;
            }
        ?>
        <div class="card mt-4">
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-2">
                        <h5 class="text-primary"><?= $total_records ?></h5>
                        <small class="text-muted">Total Records</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-info"><?= $pending_count ?></h5>
                        <small class="text-muted">Pending</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-success"><?= $paid_count ?></h5>
                        <small class="text-muted">Paid</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-warning">₹<?= number_format($total_commission, 2) ?></h5>
                        <small class="text-muted">Total Commission</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-danger">₹<?= number_format($total_tds, 2) ?></h5>
                        <small class="text-muted">Total TDS</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-success">₹<?= number_format($total_net, 2) ?></h5>
                        <small class="text-muted">Total Net Payable</small>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

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

            // Real-time commission calculation
            const memberSelect = document.getElementById('member_id');
            const commissionAmountInput = document.getElementById('commission_amount');
            const calculationPreview = document.getElementById('calculationPreview');
            
            function calculateCommission() {
                const selectedMember = memberSelect.options[memberSelect.selectedIndex];
                const tdsPercentage = selectedMember ? parseFloat(selectedMember.getAttribute('data-tds-per')) || 0 : 0;
                const commissionAmount = parseFloat(commissionAmountInput.value) || 0;
                
                if (memberSelect.value && commissionAmount > 0) {
                    const tdsAmount = (commissionAmount * tdsPercentage) / 100;
                    const netAmount = commissionAmount - tdsAmount;
                    
                    // Update preview
                    document.getElementById('previewCommission').textContent = '₹' + commissionAmount.toLocaleString('en-IN', {minimumFractionDigits: 2});
                    document.getElementById('previewTdsPer').textContent = tdsPercentage + '%';
                    document.getElementById('previewTdsAmount').textContent = '₹' + tdsAmount.toLocaleString('en-IN', {minimumFractionDigits: 2});
                    document.getElementById('previewNetAmount').textContent = '₹' + netAmount.toLocaleString('en-IN', {minimumFractionDigits: 2});
                    
                    // Show preview
                    calculationPreview.style.display = 'block';
                } else {
                    calculationPreview.style.display = 'none';
                }
            }
            
            if (memberSelect) memberSelect.addEventListener('change', calculateCommission);
            if (commissionAmountInput) commissionAmountInput.addEventListener('input', calculateCommission);
            
            // Initial calculation if editing
            <?php if($mode === 'edit' && isset($editCommission)): ?>
            calculateCommission();
            <?php endif; ?>
        });
    </script>
</body>
</html>