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
    $base = 'payment_history.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_payment_status') {
        $payment_id = (int)($_POST['payment_id'] ?? 0);
        $new_status = $_POST['status'] ?? '';
        
        if ($payment_id > 0 && in_array($new_status, ['Pending', 'Reconciled', 'Cancelled'])) {
            $stmt = $mysqli->prepare("UPDATE payment_reconciliations SET status = ?, reconciled_date = CASE WHEN ? = 'Reconciled' THEN NOW() ELSE NULL END WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("ssi", $new_status, $new_status, $payment_id);
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Payment status updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating payment status: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete_payment') {
        $payment_id = (int)($_POST['payment_id'] ?? 0);
        
        if ($payment_id > 0) {
            // Get payment details for message
            $res = $mysqli->prepare("SELECT invoice_no, transaction_id FROM payment_reconciliations WHERE id = ?");
            if ($res) {
                $res->bind_param("i", $payment_id);
                $res->execute();
                $res->bind_result($invoice_no, $transaction_id);
                $res->fetch();
                $res->close();
                
                // Delete payment
                $del = $mysqli->prepare("DELETE FROM payment_reconciliations WHERE id = ?");
                if ($del) {
                    $del->bind_param("i", $payment_id);
                    $del->execute();
                    $del->close();
                    
                    redirect_with(['msg' => "Payment record for invoice $invoice_no (TXN: $transaction_id) deleted successfully", 'type' => 'warning']);
                }
            }
        }
    }
    
    if ($action === 'edit_payment') {
        $payment_id = (int)($_POST['payment_id'] ?? 0);
        $transaction_id = trim($_POST['transaction_id'] ?? '');
        $transaction_ref = trim($_POST['transaction_ref'] ?? '');
        $transaction_date = trim($_POST['transaction_date'] ?? '');
        $deposited_amount = (float)($_POST['deposited_amount'] ?? 0);
        $transaction_remarks = trim($_POST['transaction_remarks'] ?? '');
        $payment_date = trim($_POST['payment_date'] ?? '');
        
        if ($payment_id > 0 && $deposited_amount > 0) {
            // Get existing payment to calculate new TDS
            $existing_stmt = $mysqli->prepare("SELECT tds_percentage FROM payment_reconciliations WHERE id = ?");
            $existing_stmt->bind_param("i", $payment_id);
            $existing_stmt->execute();
            $existing_result = $existing_stmt->get_result();
            $existing_data = $existing_result->fetch_assoc();
            $existing_stmt->close();
            
            if ($existing_data) {
                $tds_percentage = $existing_data['tds_percentage'];
                $tds_amount = ($deposited_amount * $tds_percentage) / 100;
                $net_amount = $deposited_amount - $tds_amount;
                
                // Update payment record
                $update_stmt = $mysqli->prepare("
                    UPDATE payment_reconciliations 
                    SET transaction_id = ?, transaction_ref = ?, transaction_date = ?, 
                        transaction_remarks = ?, deposited_amount = ?, tds_amount = ?, 
                        net_amount = ?, payment_date = ?
                    WHERE id = ?
                ");
                
                $update_stmt->bind_param("ssssdddssi", 
                    $transaction_id, $transaction_ref, $transaction_date, $transaction_remarks,
                    $deposited_amount, $tds_amount, $net_amount, $payment_date, $payment_id
                );
                
                if ($update_stmt->execute()) {
                    redirect_with(['msg' => "Payment record updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating payment: ' . $update_stmt->error, 'type' => 'danger']);
                }
                $update_stmt->close();
            }
        }
    }
    
    redirect_with();
}

// Get filter parameters
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$client_id = (int)($_GET['client_id'] ?? 0);
$fstatus = $_GET['status'] ?? '';
$fdate_from = $_GET['date_from'] ?? '';
$fdate_to = $_GET['date_to'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build main query
$sql = "
SELECT 
    pr.*,
    cm.client_name,
    cm.company_name,
    cm.contact_person,
    cm.email as client_email,
    cm.phone as client_phone,
    i.invoice_date,
    i.grand_total as invoice_grand_total,
    i.process,
    i.worked_month,
    i.raised_month
FROM `payment_reconciliations` pr
LEFT JOIN `client_master` cm ON pr.`client_id` = cm.`client_id`
LEFT JOIN `invoices` i ON pr.`invoice_id` = i.`invoice_id`
WHERE 1
";

$cond = [];
$params = [];
$types = '';

if ($invoice_id > 0) {
    $cond[] = "pr.`invoice_id` = ?";
    $params[] = $invoice_id;
    $types .= 'i';
}

if ($client_id > 0) {
    $cond[] = "pr.`client_id` = ?";
    $params[] = $client_id;
    $types .= 'i';
}

if ($fstatus !== '') {
    $cond[] = "pr.`status` = ?";
    $params[] = $fstatus;
    $types .= 's';
}

if ($fdate_from !== '') {
    $cond[] = "pr.`payment_date` >= ?";
    $params[] = $fdate_from;
    $types .= 's';
}

if ($fdate_to !== '') {
    $cond[] = "pr.`payment_date` <= ?";
    $params[] = $fdate_to;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " AND " . implode(" AND ", $cond);
}

$sql .= " ORDER BY pr.`$sort` $dir";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $payments = $stmt->get_result();
    } else {
        $payments = false;
    }
} else {
    $payments = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$payments) {
    $payments = false;
}

// Get clients for filter dropdown
$clients_result = $mysqli->query("SELECT client_id, client_name, company_name FROM client_master WHERE status = 'Active' ORDER BY client_name");
$clients = [];
while($row = $clients_result->fetch_assoc()) {
    $clients[$row['client_id']] = $row;
}

// Get invoices for filter dropdown (if no specific invoice_id)
$invoices_result = $mysqli->query("SELECT invoice_id, invoice_no, client_name FROM invoices,client_master where invoices.client_id=client_master.client_id ORDER BY invoice_date DESC LIMIT 100");
$invoices = [];
while($row = $invoices_result->fetch_assoc()) {
    $invoices[$row['invoice_id']] = $row;
}

// Get invoice details if specific invoice is selected
$invoice_details = null;
if ($invoice_id > 0) {
    $invoice_stmt = $mysqli->prepare("
        SELECT i.*, cm.client_name, cm.company_name 
        FROM invoices i 
        LEFT JOIN client_master cm ON i.client_id = cm.client_id 
        WHERE i.invoice_id = ?
    ");
    $invoice_stmt->bind_param("i", $invoice_id);
    $invoice_stmt->execute();
    $invoice_result = $invoice_stmt->get_result();
    $invoice_details = $invoice_result->fetch_assoc();
    $invoice_stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History</title>
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
        .invoice-info {
            background-color: #e9ecef;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .payment-summary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .summary-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        .summary-value {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .summary-label {
            font-size: 0.875rem;
            color: #6c757d;
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

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4><i class="fas fa-history me-2"></i>Payment History</h4>
                <?php if ($invoice_details): ?>
                    <p class="text-muted mb-0">
                        Showing payments for: <strong><?= h($invoice_details['invoice_no']) ?></strong> - 
                        <?= h($invoice_details['client_name']) ?>
                    </p>
                <?php endif; ?>
            </div>
            <div>
                <a href="invoices1.php" class="btn btn-secondary me-2">
                    <i class="fas fa-arrow-left me-1"></i> Back to Invoices
                </a>
                <?php if ($invoice_id > 0): ?>
                    <a href="payment_history.php" class="btn btn-outline-primary">
                        <i class="fas fa-list me-1"></i> All Payments
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Invoice Summary Card -->
        <?php if ($invoice_details): ?>
        <div class="invoice-info">
            <div class="row">
                <div class="col-md-3">
                    <strong>Invoice No:</strong> <?= h($invoice_details['invoice_no']) ?>
                </div>
                <div class="col-md-3">
                    <strong>Client:</strong> <?= h($invoice_details['client_name']) ?>
                </div>
                <div class="col-md-2">
                    <strong>Invoice Date:</strong> <?= h(formatDate($invoice_details['invoice_date'])) ?>
                </div>
                <div class="col-md-2">
                    <strong>Grand Total:</strong> ₹<?= number_format($invoice_details['grand_total'], 2) ?>
                </div>
                <div class="col-md-2">
                    <strong>Status:</strong> 
                    <span class="badge bg-<?= $invoice_details['status'] === 'Paid' ? 'success' : 'warning' ?>">
                        <?= h($invoice_details['status']) ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <?php if (!$invoice_id): ?>
                    <div class="col-md-3">
                        <select name="client_id" class="form-select">
                            <option value="">All Clients</option>
                            <?php foreach($clients as $client_id_val => $client): ?>
                                <option value="<?= h($client_id_val) ?>" <?= $client_id === $client_id_val ? 'selected' : '' ?>>
                                    <?= h($client['client_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="invoice_id" class="form-select">
                            <option value="">All Invoices</option>
                            <?php foreach($invoices as $inv_id => $invoice): ?>
                                <option value="<?= h($inv_id) ?>" <?= $invoice_id === $inv_id ? 'selected' : '' ?>>
                                    <?= h($invoice['invoice_no']) ?> - <?= h($invoice['client_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="invoice_id" value="<?= $invoice_id ?>">
                    <?php endif; ?>
                    
                    <div class="col-md-2">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="Pending" <?= $fstatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Reconciled" <?= $fstatus === 'Reconciled' ? 'selected' : '' ?>>Reconciled</option>
                            <option value="Cancelled" <?= $fstatus === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <input type="date" name="date_from" class="form-control" placeholder="From Date" value="<?= h($fdate_from) ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <input type="date" name="date_to" class="form-control" placeholder="To Date" value="<?= h($fdate_to) ?>">
                    </div>
                    
                    <div class="col-md-1">
                        <select name="sort" class="form-select">
                            <option value="created_at" <?= $sort === 'created_at' ? 'selected' : '' ?>>Date</option>
                            <option value="deposited_amount" <?= $sort === 'deposited_amount' ? 'selected' : '' ?>>Amount</option>
                            <option value="invoice_no" <?= $sort === 'invoice_no' ? 'selected' : '' ?>>Invoice</option>
                        </select>
                    </div>
                    
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="payment_history.php" class="btn btn-secondary">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Payment Summary -->
        <?php if($payments && $payments->num_rows > 0): ?>
        <?php
            // Reset pointer and calculate totals
            $payments->data_seek(0);
            $total_payments = 0;
            $total_deposited = 0;
            $total_tds = 0;
            $total_net = 0;
            $pending_count = 0;
            $reconciled_count = 0;
            $cancelled_count = 0;
            
            while($row = $payments->fetch_assoc()) {
                $total_payments++;
                $total_deposited += $row['deposited_amount'];
                $total_tds += $row['tds_amount'];
                $total_net += $row['net_amount'];
                
                if ($row['status'] === 'Pending') $pending_count++;
                if ($row['status'] === 'Reconciled') $reconciled_count++;
                if ($row['status'] === 'Cancelled') $cancelled_count++;
            }
            $payments->data_seek(0); // Reset pointer again for display
        ?>
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="summary-card">
                    <div class="summary-value text-primary"><?= $total_payments ?></div>
                    <div class="summary-label">Total Payments</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card">
                    <div class="summary-value text-success">₹<?= number_format($total_deposited, 2) ?></div>
                    <div class="summary-label">Total Deposited</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card">
                    <div class="summary-value text-warning">₹<?= number_format($total_tds, 2) ?></div>
                    <div class="summary-label">Total TDS</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card">
                    <div class="summary-value text-info">₹<?= number_format($total_net, 2) ?></div>
                    <div class="summary-label">Total Net Amount</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Payments Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="80">ID</th>
                                <th width="120">Invoice No</th>
                                <th>Client</th>
                                <th width="150">Transaction Details</th>
                                <th width="120">Payment Date</th>
                                <th width="120">Deposited (₹)</th>
                                <th width="100">TDS %</th>
                                <th width="120">TDS Amount (₹)</th>
                                <th width="120">Net Amount (₹)</th>
                                <th width="120">Status</th>
                                <!--th width="150" class="text-center">Actions</th-->
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($payments && $payments->num_rows > 0): ?>
                                <?php while($row = $payments->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?= (int)$row['id'] ?></td>
                                    <td>
                                        <span class="invoice-number"><?= h($row['invoice_no']) ?></span>
                                        <?php if(!empty($row['process'])): ?>
                                            <br><small class="text-muted"><?= h($row['process']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="client-name"><?= h($row['client_name']) ?></div>
                                        <small class="text-muted"><?= h($row['company_name']) ?></small>
                                    </td>
                                    <td>
                                        <div><strong>TX ID:</strong> <?= h($row['transaction_id']) ?></div>
                                        <?php if(!empty($row['transaction_ref'])): ?>
                                            <div><strong>Ref:</strong> <?= h($row['transaction_ref']) ?></div>
                                        <?php endif; ?>
                                        <?php if(!empty($row['transaction_remarks'])): ?>
                                            <div><small class="text-muted"><?= h($row['transaction_remarks']) ?></small></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= h(formatDate($row['payment_date'])) ?>
                                        <?php if(!empty($row['transaction_date'])): ?>
                                            <br><small class="text-muted">TXN: <?= h(formatDate($row['transaction_date'])) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="amount-cell text-success">
                                        <strong>₹<?= number_format($row['deposited_amount'], 2) ?></strong>
                                    </td>
                                    <td class="text-center">
                                        <?= number_format($row['tds_percentage'], 1) ?>%
                                    </td>
                                    <td class="amount-cell text-warning">
                                        ₹<?= number_format($row['tds_amount'], 2) ?>
                                    </td>
                                    <td class="amount-cell text-primary">
                                        <strong>₹<?= number_format($row['net_amount'], 2) ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= 
                                            $row['status'] === 'Reconciled' ? 'success' : 
                                            ($row['status'] === 'Pending' ? 'warning' : 'danger')
                                        ?> status-badge">
                                            <i class="fas fa-<?= 
                                                $row['status'] === 'Reconciled' ? 'check-circle' : 
                                                ($row['status'] === 'Pending' ? 'clock' : 'times-circle')
                                            ?> me-1"></i>
                                            <?= h($row['status']) ?>
                                        </span>
                                        <?php if(!empty($row['reconciled_date'])): ?>
                                            <br><small class="text-muted"><?= h(formatDate($row['reconciled_date'])) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <!-- Edit Payment Button -->
                                            <!--button type="button" class="btn btn-warning" 
                                                    data-bs-toggle="modal" data-bs-target="#editPaymentModal<?= (int)$row['id'] ?>"
                                                    title="Edit Payment">
                                                <i class="fas fa-edit"></i>
                                            </button-->
                                            
                                            <!-- Status Update Button -->
                                            <!--button type="button" class="btn btn-info" 
                                                    data-bs-toggle="modal" data-bs-target="#statusModal<?= (int)$row['id'] ?>"
                                                    title="Update Status">
                                                <i class="fas fa-sync"></i>
                                            </button-->
                                            
                                            <!-- Delete Button -->
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this payment record?')">
                                                <input type="hidden" name="action" value="delete_payment">
                                                <input type="hidden" name="payment_id" value="<?= (int)$row['id'] ?>">
                                                <!--button type="submit" class="btn btn-danger" title="Delete Payment">
                                                    <i class="fas fa-trash"></i>
                                                </button-->
                                            </form>
                                        </div>
                                        
                                        <!-- Edit Payment Modal -->
                                        <div class="modal fade" id="editPaymentModal<?= (int)$row['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Edit Payment - <?= h($row['invoice_no']) ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="post">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="action" value="edit_payment">
                                                            <input type="hidden" name="payment_id" value="<?= (int)$row['id'] ?>">
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Transaction ID *</label>
                                                                <input type="text" name="transaction_id" class="form-control" 
                                                                       value="<?= h($row['transaction_id']) ?>" required>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Transaction Reference</label>
                                                                <input type="text" name="transaction_ref" class="form-control" 
                                                                       value="<?= h($row['transaction_ref']) ?>">
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Transaction Date *</label>
                                                                <input type="date" name="transaction_date" class="form-control" 
                                                                       value="<?= h($row['transaction_date']) ?>" required>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Payment Date *</label>
                                                                <input type="date" name="payment_date" class="form-control" 
                                                                       value="<?= h($row['payment_date']) ?>" required>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Deposited Amount (₹) *</label>
                                                                <input type="number" name="deposited_amount" class="form-control" 
                                                                       step="0.01" min="0.01" value="<?= $row['deposited_amount'] ?>" required>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Remarks</label>
                                                                <textarea name="transaction_remarks" class="form-control" rows="2"><?= h($row['transaction_remarks']) ?></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-success">Update Payment</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Status Update Modal -->
                                        <div class="modal fade" id="statusModal<?= (int)$row['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Update Payment Status</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="post">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="action" value="update_payment_status">
                                                            <input type="hidden" name="payment_id" value="<?= (int)$row['id'] ?>">
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Payment Status</label>
                                                                <select name="status" class="form-select" required>
                                                                    <option value="Pending" <?= $row['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                                                    <option value="Reconciled" <?= $row['status'] === 'Reconciled' ? 'selected' : '' ?>>Reconciled</option>
                                                                    <option value="Cancelled" <?= $row['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                                                </select>
                                                            </div>
                                                            
                                                            <div class="alert alert-info">
                                                                <small>
                                                                    <strong>Current Status:</strong> <?= h($row['status']) ?><br>
                                                                    <strong>Invoice:</strong> <?= h($row['invoice_no']) ?><br>
                                                                    <strong>Amount:</strong> ₹<?= number_format($row['deposited_amount'], 2) ?>
                                                                </small>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary">Update Status</button>
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
                                            <i class="fas fa-money-bill-wave fa-3x mb-3"></i>
                                            <h5>No payment records found</h5>
                                            <p class="mb-0">No payments match your current filters.</p>
                                            <?php if ($invoice_id): ?>
                                                <a href="invoices1.php" class="btn btn-primary mt-2">
                                                    <i class="fas fa-arrow-left me-1"></i> Back to Invoices
                                                </a>
                                            <?php else: ?>
                                                <a href="payment_history.php" class="btn btn-primary mt-2">
                                                    <i class="fas fa-times me-1"></i> Clear Filters
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Status Summary -->
        <?php if($payments && $payments->num_rows > 0): ?>
        <div class="card mt-4">
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-4">
                        <h5 class="text-warning"><?= $pending_count ?></h5>
                        <small class="text-muted">Pending Payments</small>
                    </div>
                    <div class="col-md-4">
                        <h5 class="text-success"><?= $reconciled_count ?></h5>
                        <small class="text-muted">Reconciled Payments</small>
                    </div>
                    <div class="col-md-4">
                        <h5 class="text-danger"><?= $cancelled_count ?></h5>
                        <small class="text-muted">Cancelled Payments</small>
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

            // Add tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });

            // Date range validation
            const dateFrom = document.querySelector('input[name="date_from"]');
            const dateTo = document.querySelector('input[name="date_to"]');
            
            if (dateFrom && dateTo) {
                dateFrom.addEventListener('change', function() {
                    if (this.value && dateTo.value && this.value > dateTo.value) {
                        dateTo.value = this.value;
                    }
                });
                
                dateTo.addEventListener('change', function() {
                    if (this.value && dateFrom.value && this.value < dateFrom.value) {
                        dateFrom.value = this.value;
                    }
                });
            }
        });
    </script>
</body>
</html>