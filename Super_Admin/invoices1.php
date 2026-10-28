<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
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
    $base = 'invoices1.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_status') {
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $new_status = $_POST['status'] ?? '';
        
        if ($invoice_id > 0 && in_array($new_status, ['Pending', 'Paid', 'Cancelled'])) {
            $stmt = $mysqli->prepare("UPDATE invoices SET status = ? WHERE invoice_id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $new_status, $invoice_id);
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Invoice status updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating status: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        
        if ($invoice_id > 0) {
            // Get invoice details for message
            $res = $mysqli->prepare("SELECT invoice_no FROM invoices WHERE invoice_id = ?");
            if ($res) {
                $res->bind_param("i", $invoice_id);
                $res->execute();
                $res->bind_result($invoice_no);
                $res->fetch();
                $res->close();
                
                // Delete invoice
                $del = $mysqli->prepare("DELETE FROM invoices WHERE invoice_id = ?");
                if ($del) {
                    $del->bind_param("i", $invoice_id);
                    $del->execute();
                    $del->close();
                    
                    redirect_with(['msg' => "Invoice $invoice_no deleted successfully", 'type' => 'warning']);
                }
            }
        }
    }
    
    // Handle payment record submission
    if ($action === 'record_payment') {
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $transaction_id = trim($_POST['transaction_id'] ?? '');
        $transaction_ref = trim($_POST['transaction_ref'] ?? '');
        $transaction_date = trim($_POST['transaction_date'] ?? '');
        $deposited_amount = (float)($_POST['deposited_amount'] ?? 0);
        $transaction_remarks = trim($_POST['transaction_remarks'] ?? '');
        
        if ($invoice_id > 0 && $deposited_amount > 0) {
            // Get invoice details
            $invoice_stmt = $mysqli->prepare("
                SELECT i.invoice_no, i.grand_total, i.client_id, cm.client_name, cm.tds_percentage 
                FROM invoices i 
                LEFT JOIN client_master cm ON i.client_id = cm.client_id 
                WHERE i.invoice_id = ?
            ");
            $invoice_stmt->bind_param("i", $invoice_id);
            $invoice_stmt->execute();
            $invoice_result = $invoice_stmt->get_result();
            $invoice_data = $invoice_result->fetch_assoc();
            $invoice_stmt->close();
            
            if ($invoice_data) {
                $client_id = $invoice_data['client_id'];
                $client_name = $invoice_data['client_name'];
                $invoice_no = $invoice_data['invoice_no'];
                $grand_total = $invoice_data['grand_total'];
                $tds_percentage = $invoice_data['tds_percentage'] ?? 0;
                
                // Calculate TDS and net amount
                $tds_amount = ($deposited_amount * $tds_percentage) / 100;
                $net_amount = $deposited_amount - $tds_amount;
                
                // Insert payment record
                $payment_stmt = $mysqli->prepare("
                    INSERT INTO payment_reconciliations 
                    (client_id, client_name, invoice_id, invoice_no, invoice_amount, transaction_id, 
                     transaction_ref, transaction_date, transaction_remarks, deposited_amount, 
                     tds_percentage, tds_amount, net_amount, payment_date, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'Pending', NOW())
                ");
                
                $payment_stmt->bind_param("isssdssssdddd", 
                    $client_id, $client_name, $invoice_id, $invoice_no, $grand_total,
                    $transaction_id, $transaction_ref, $transaction_date, $transaction_remarks,
                    $deposited_amount, $tds_percentage, $tds_amount, $net_amount
                );
                
                if ($payment_stmt->execute()) {
                    redirect_with(['msg' => "Payment recorded successfully for invoice $invoice_no", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error recording payment: ' . $payment_stmt->error, 'type' => 'danger']);
                }
                $payment_stmt->close();
            }
        }
    }
    
    redirect_with();
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fclient = $_GET['client'] ?? '';
$fstatus = $_GET['status'] ?? '';
$fmonth = $_GET['month'] ?? '';
$sort = $_GET['sort'] ?? 'invoice_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build main query with JOINs
$sql = "
SELECT 
    i.*,
    cm.`client_name`,
    cm.`company_name`,
    cm.`contact_person`,
    cm.`email` as client_email,
    cm.`phone` as client_phone,
    cm.`address` as client_address,
    cm.`gst_no`,
    cm.`tds_percentage` as client_tds_percentage,
    
    j.`job_date`,
    j.`journal_issue`,
    j.`article`,
    j.`process_id`,
    j.`assigned_to`,
    j.`due_date`,
    j.`team_name`,
    j.`dispatch_date`,
    
    (SELECT COUNT(*) FROM payment_reconciliations pr WHERE pr.invoice_id = i.invoice_id) as payment_count,
    (SELECT SUM(pr.deposited_amount) FROM payment_reconciliations pr WHERE pr.invoice_id = i.invoice_id) as total_paid_amount
    
FROM `invoices` i
LEFT JOIN `client_master` cm ON i.`client_id` = cm.`client_id`
LEFT JOIN `jobs` j ON i.`job_id` = j.`job_id`
WHERE 1
";

$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(i.`invoice_no` LIKE ? OR cm.`client_name` LIKE ? OR cm.`company_name` LIKE ? OR j.`article` LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if ($fclient !== '') {
    $cond[] = "i.`client_id` = ?";
    $params[] = $fclient;
    $types .= 's';
}

if ($fstatus !== '') {
    $cond[] = "i.`status` = ?";
    $params[] = $fstatus;
    $types .= 's';
}

if ($fmonth !== '') {
    $cond[] = "i.`raised_month` = ?";
    $params[] = $fmonth;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " AND " . implode(" AND ", $cond);
}

$sql .= " ORDER BY i.`$sort` $dir";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $invoices = $stmt->get_result();
    } else {
        $invoices = false;
    }
} else {
    $invoices = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$invoices) {
    $invoices = false;
}

// Get clients for filter dropdown
$clients_result = $mysqli->query("SELECT client_id, client_name, company_name FROM client_master WHERE status = 'Active' ORDER BY client_name");
$clients = [];
while($row = $clients_result->fetch_assoc()) {
    $clients[$row['client_id']] = $row;
}

// Get unique months for filter
$unique_months = [];

try {
    $months_result = $mysqli->query("
        SELECT DISTINCT raised_month 
        FROM invoices 
        WHERE raised_month IS NOT NULL 
        AND raised_month != '' 
        AND raised_month != '0000-00-00'
        AND raised_month REGEXP '^[a-zA-Z]'
        ORDER BY 
            CASE raised_month
                WHEN 'January' THEN 1
                WHEN 'February' THEN 2
                WHEN 'March' THEN 3
                WHEN 'April' THEN 4
                WHEN 'May' THEN 5
                WHEN 'June' THEN 6
                WHEN 'July' THEN 7
                WHEN 'August' THEN 8
                WHEN 'September' THEN 9
                WHEN 'October' THEN 10
                WHEN 'November' THEN 11
                WHEN 'December' THEN 12
                ELSE 13
            END
    ");
    
    if ($months_result) {
        while($row = $months_result->fetch_assoc()) {
            $unique_months[] = $row['raised_month'];
        }
    }
} catch (Exception $e) {
    // If query fails, use default months
    echo "<!-- Month query failed: " . $e->getMessage() . " -->";
    $unique_months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
}

// If no months found, use defaults
if (empty($unique_months)) {
    $unique_months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice Management</title>
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
        .invoice-number {
            font-weight: bold;
            color: #2c3e50;
        }
        .client-name {
            font-weight: 500;
        }
        .job-article {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .payment-progress {
            height: 6px;
            margin-top: 5px;
        }
        .payment-info {
            font-size: 0.8em;
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

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-file-invoice me-2"></i>Invoice Management</h4>
            <a href="invoice_add.php" class="btn btn-success">
                <i class="fas fa-plus me-1"></i> Create Invoice
            </a>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control" 
                               placeholder="Search by invoice no, client, article..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="client" class="form-select">
                            <option value="">All Clients</option>
                            <?php foreach($clients as $client_id => $client): ?>
                                <option value="<?= h($client_id) ?>" <?= $fclient === $client_id ? 'selected' : '' ?>>
                                    <?= h($client['client_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="Pending" <?= $fstatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Paid" <?= $fstatus === 'Paid' ? 'selected' : '' ?>>Paid</option>
                            <option value="Cancelled" <?= $fstatus === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
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
                    <div class="col-md-3">
                        <select name="sort" class="form-select">
                            <option value="invoice_date" <?= $sort === 'invoice_date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="invoice_no" <?= $sort === 'invoice_no' ? 'selected' : '' ?>>Sort by Invoice No</option>
                            <option value="client_name" <?= $sort === 'client_name' ? 'selected' : '' ?>>Sort by Client</option>
                            <option value="grand_total" <?= $sort === 'grand_total' ? 'selected' : '' ?>>Sort by Amount</option>
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
                        <a href="invoices1.php" class="btn btn-secondary">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Invoices Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="80">Invoice ID</th>
                                <th width="120">Invoice No</th>
                                <th>Client Details</th>
                                <th>Job Details</th>
                                <th width="100">Invoice Date</th>
                                <th width="100">Process</th>
                                <th width="120">Total Amount (₹)</th>
                                <th width="120">GST/TDS (₹)</th>
                                <th width="120">Grand Total (₹)</th>
                                <th width="120">Payment Status</th>
                                <th width="180" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($invoices && $invoices->num_rows > 0): ?>
                                <?php while($row = $invoices->fetch_assoc()): 
                                    $total_paid = (float)($row['total_paid_amount'] ?? 0);
                                    $grand_total = (float)($row['grand_total'] ?? 0);
                                    $payment_count = (int)($row['payment_count'] ?? 0);
                                    $payment_percentage = $grand_total > 0 ? ($total_paid / $grand_total) * 100 : 0;
                                    $is_fully_paid = $total_paid >= $grand_total;
                                ?>
                                <tr>
                                    <td class="fw-bold"><?= (int)$row['invoice_id'] ?></td>
                                    <td>
                                        <span class="invoice-number"><?= h($row['invoice_no']) ?></span>
                                    </td>
                                    <td>
                                        <div class="client-name"><?= h($row['client_name']) ?></div>
                                        <small class="text-muted"><?= h($row['company_name']) ?></small>
                                        <?php if(!empty($row['contact_person'])): ?>
                                            <br><small><i class="fas fa-user me-1"></i><?= h($row['contact_person']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="job-article" title="<?= h($row['article']) ?>">
                                            <?= h($row['article']) ?>
                                        </div>
                                        <?php if(!empty($row['journal_issue'])): ?>
                                            <small class="text-muted"><?= h($row['journal_issue']) ?></small>
                                        <?php endif; ?>
                                        <?php if(!empty($row['team_name'])): ?>
                                            <br><small><i class="fas fa-users me-1"></i><?= h($row['team_name']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= h(formatDate($row['invoice_date'])) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?= h($row['process']) ?></span>
                                    </td>
                                    <td class="amount-cell">
                                        ₹<?= number_format($row['total_amount'], 2) ?>
                                    </td>
                                    <td class="amount-cell text-info">
                                        <small>
                                            GST: ₹<?= number_format(($row['cgst_9_percent'] + $row['sgst_9_percent'] + $row['gst_18_percent']), 2) ?><br>
                                            TDS: ₹<?= number_format($row['tds_amount'], 2) ?>
                                        </small>
                                    </td>
                                    <td class="amount-cell text-success">
                                        <strong>₹<?= number_format($grand_total, 2) ?></strong>
                                        <?php if($payment_count > 0): ?>
                                            <div class="payment-info text-muted">
                                                Paid: ₹<?= number_format($total_paid, 2) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= 
                                            $is_fully_paid ? 'success' : 
                                            ($payment_count > 0 ? 'warning' : 'secondary')
                                        ?>">
                                            <i class="fas fa-<?= 
                                                $is_fully_paid ? 'check-circle' : 
                                                ($payment_count > 0 ? 'clock' : 'money-bill')
                                            ?> me-1"></i>
                                            <?= $is_fully_paid ? 'Fully Paid' : ($payment_count > 0 ? 'Partially Paid' : 'Unpaid') ?>
                                        </span>
                                        <?php if($payment_count > 0): ?>
                                            <div class="progress payment-progress">
                                                <div class="progress-bar bg-<?= $is_fully_paid ? 'success' : 'warning' ?>" 
                                                     style="width: <?= min($payment_percentage, 100) ?>%">
                                                </div>
                                            </div>
                                            <small class="payment-info">
                                                <?= number_format($payment_percentage, 1) ?>% (<?= $payment_count ?> payment<?= $payment_count > 1 ? 's' : '' ?>)
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="invoice_view.php?id=<?= (int)$row['invoice_id'] ?>" 
                                               class="btn btn-info" title="View Invoice">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <!-- Payment Button with Modal -->
                                            <button type="button" class="btn btn-success" 
                                                    data-bs-toggle="modal" data-bs-target="#paymentModal<?= (int)$row['invoice_id'] ?>"
                                                    title="Record Payment">
                                                <i class="fas fa-money-bill-wave"></i>
                                            </button>
                                            
                                            <!-- View Payments Button -->
                                            <a href="payment_history.php?invoice_id=<?= (int)$row['invoice_id'] ?>" 
                                               class="btn btn-primary" title="View Payment History">
                                                <i class="fas fa-history"></i>
                                            </a>
                                            
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this invoice?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="invoice_id" value="<?= (int)$row['invoice_id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Invoice">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                        
                                        <!-- Payment Modal -->
                                        <div class="modal fade" id="paymentModal<?= (int)$row['invoice_id'] ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Record Payment - <?= h($row['invoice_no']) ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="post">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="action" value="record_payment">
                                                            <input type="hidden" name="invoice_id" value="<?= (int)$row['invoice_id'] ?>">
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Transaction ID *</label>
                                                                <input type="text" name="transaction_id" class="form-control" required>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Transaction Reference</label>
                                                                <input type="text" name="transaction_ref" class="form-control">
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Transaction Date *</label>
                                                                <input type="date" name="transaction_date" class="form-control" required 
                                                                       value="<?= date('Y-m-d') ?>">
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Deposited Amount (₹) *</label>
                                                                <input type="number" name="deposited_amount" class="form-control" 
                                                                       step="0.01" min="0.01" max="<?= $grand_total - $total_paid ?>" 
                                                                       value="<?= $grand_total - $total_paid ?>" required>
                                                                <div class="form-text">
                                                                    Invoice Total: ₹<?= number_format($grand_total, 2) ?> | 
                                                                    Already Paid: ₹<?= number_format($total_paid, 2) ?> | 
                                                                    Remaining: ₹<?= number_format($grand_total - $total_paid, 2) ?>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Remarks</label>
                                                                <textarea name="transaction_remarks" class="form-control" rows="2"></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-success">Record Payment</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-file-invoice fa-3x mb-3"></i>
                                            <h5>No invoices found</h5>
                                            <p class="mb-0">Get started by creating your first invoice.</p>
                                            <a href="invoice_add.php" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Create Invoice
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
        <?php if($invoices && $invoices->num_rows > 0): ?>
        <?php
            // Reset pointer and calculate totals
            $invoices->data_seek(0);
            $total_records = 0;
            $total_amount = 0;
            $total_gst = 0;
            $total_tds = 0;
            $total_grand = 0;
            $total_paid = 0;
            $pending_count = 0;
            $paid_count = 0;
            $cancelled_count = 0;
            $partially_paid_count = 0;
            
            while($row = $invoices->fetch_assoc()) {
                $total_records++;
                $total_amount += $row['total_amount'];
                $total_gst += ($row['cgst_9_percent'] + $row['sgst_9_percent'] + $row['gst_18_percent']);
                $total_tds += $row['tds_amount'];
                $total_grand += $row['grand_total'];
                $total_paid += (float)($row['total_paid_amount'] ?? 0);
                
                $payment_count = (int)($row['payment_count'] ?? 0);
                $paid_amount = (float)($row['total_paid_amount'] ?? 0);
                
                if ($paid_amount >= $row['grand_total']) {
                    $paid_count++;
                } elseif ($payment_count > 0) {
                    $partially_paid_count++;
                } else {
                    $pending_count++;
                }
                
                if ($row['status'] === 'Cancelled') $cancelled_count++;
            }
        ?>
        <div class="card mt-4">
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-2">
                        <h5 class="text-primary"><?= $total_records ?></h5>
                        <small class="text-muted">Total Invoices</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-warning"><?= $pending_count ?></h5>
                        <small class="text-muted">Unpaid</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-info"><?= $partially_paid_count ?></h5>
                        <small class="text-muted">Partially Paid</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-success"><?= $paid_count ?></h5>
                        <small class="text-muted">Fully Paid</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-secondary">₹<?= number_format($total_grand, 2) ?></h5>
                        <small class="text-muted">Total Invoice Amount</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-success">₹<?= number_format($total_paid, 2) ?></h5>
                        <small class="text-muted">Total Received</small>
                    </div>
                </div>
                <div class="row text-center mt-3">
                    <div class="col-md-4">
                        <h6 class="text-muted">Pending: ₹<?= number_format($total_grand - $total_paid, 2) ?></h6>
                    </div>
                    <div class="col-md-4">
                        <h6 class="text-muted">Total GST: ₹<?= number_format($total_gst, 2) ?></h6>
                    </div>
                    <div class="col-md-4">
                        <h6 class="text-muted">Total TDS: ₹<?= number_format($total_tds, 2) ?></h6>
                    </div>
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

            // Add tooltips for truncated content
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>
</html>