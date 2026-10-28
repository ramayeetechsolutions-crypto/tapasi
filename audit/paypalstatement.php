<?php 
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session and require DB connection FIRST
session_start();
require 'db.php'; // Assume this file provides $mysqli connection

// --- Handle Delete Action ---
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    
    $stmt = $mysqli->prepare("DELETE FROM paypal_transactions WHERE id = ?");
    
    if (!$stmt) {
        $_SESSION['message'] = "Prepare failed: " . $mysqli->error;
    } else {
        $stmt->bind_param("i", $delete_id);
        if ($stmt->execute()) {
            $_SESSION['message'] = "PayPal transaction deleted successfully";
        } else {
            $_SESSION['message'] = "Error deleting PayPal transaction: " . $stmt->error;
        }
        $stmt->close();
    }
    
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

// Now include header.php after handling all POST/GET actions
require 'header.php';
?>

<div class="main-content">
    <div class="bank-transactions-container">

<?php
$_SESSION['username']='Audit'; // Setting a session variable

/**
 * HTML Escape function to prevent XSS.
 * @param string $s The string to escape.
 * @return string The escaped string.
 */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

// --- Search Parameters for PayPal ---
$search_trans_id = $_GET['search_trans_id'] ?? '';
$search_name = $_GET['search_name'] ?? '';
$search_email = $_GET['search_email'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$type_filter = $_GET['type_filter'] ?? '';

// Build WHERE clause for PayPal search
$where_conditions = [];
$params = [];
$types = '';

if (!empty($search_trans_id)) {
    $where_conditions[] = "transaction_id LIKE ?";
    $params[] = "%$search_trans_id%";
    $types .= 's';
}

if (!empty($search_name)) {
    $where_conditions[] = "name LIKE ?";
    $params[] = "%$search_name%";
    $types .= 's';
}

if (!empty($search_email)) {
    $where_conditions[] = "(from_email LIKE ? OR to_email LIKE ?)";
    $params[] = "%$search_email%";
    $params[] = "%$search_email%";
    $types .= 'ss';
}

if (!empty($date_from)) {
    $where_conditions[] = "date >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $where_conditions[] = "date <= ?";
    $params[] = $date_to;
    $types .= 's';
}

if (!empty($type_filter)) {
    $where_conditions[] = "type = ?";
    $params[] = $type_filter;
    $types .= 's';
}

$where_sql = "";
if (!empty($where_conditions)) {
    $where_sql = " WHERE " . implode(" AND ", $where_conditions);
}

// PayPal query 
$paypal_sql = "SELECT `id`, `date`, `time`, `timezone`, `name`, `type`, `status`, `currency`, `gross`, `fee`, `net`, `from_email`, `to_email`, `transaction_id`, `shipping_address`, `address_status`, `item_title`, `item_id`, `shipping_amount`, `insurance_amount`, `sales_tax`, `option1_name`, `option1_value`, `option2_name`, `option2_value`, `reference_txn_id`, `invoice_number`, `custom_number`, `quantity`, `receipt_id`, `balance`, `address_line_1`, `address_line_2`, `city`, `state`, `zip_code`, `country`, `contact_phone_number`, `subject`, `note`, `country_code`, `balance_impact`, `imported_at`, `created_at` FROM `paypal_transactions`
$where_sql
ORDER BY date DESC, id DESC";

$paypal_stmt = $mysqli->prepare($paypal_sql);
$paypal_transactions = [];

if ($paypal_stmt) {
    if (!empty($params)) {
        $paypal_stmt->bind_param($types, ...$params);
    }
    
    $paypal_stmt->execute();
    $paypal_results = $paypal_stmt->get_result();
    $paypal_transactions = $paypal_results->fetch_all(MYSQLI_ASSOC);
    $paypal_stmt->close();
}

// Calculate PayPal totals
$total_gross = 0;
$total_fee = 0;
$total_net = 0;
foreach($paypal_transactions as $transaction) {
    $total_gross += $transaction['gross'];
    $total_fee += $transaction['fee'];
    $total_net += $transaction['net'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PayPal Transactions Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css"/>
    <link rel="stylesheet" href="https://cdn.datatables.net/2.0.8/css/dataTables.bootstrap5.css">
    
    <style>
    /* Custom styles */
    .dataTables_wrapper .dt-paging a.current { background-color: #007bff !important; color: white !important; }
    table td,th { vertical-align: middle; padding: 6px 8px; white-space: nowrap; font-size: 0.9rem; }
    .amount-withdrawal { color: #dc3545; font-weight: 600; }
    .amount-deposit { color: #198754; font-weight: 600; }
    .amount-balance { color: #0d6efd; font-weight: 700; }
    .btn-action { padding: 0.25rem 0.5rem; font-size: 0.8rem; margin: 1px; }
    .search-form .form-control { font-size: 0.9rem; }
    .totals-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
    .totals-card-fee { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; }
    .totals-card-net { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; }
    .remarks-cell { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; cursor: pointer; }
    .remarks-cell:hover { overflow: visible; white-space: normal; background: #f8f9fa; }
    .table-responsive { max-height: 70vh; }
    .card-header { background-color: #f8f9fa; font-weight: 600; }
    </style>
</head>
<body>
<div class="container-fluid mt-3">
    <h2><i class="bi bi-paypal"></i> PayPal Transactions Management</h2>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?= h($_SESSION['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card totals-card">
                <div class="card-body text-center">
                    <h6 class="card-title"><i class="bi bi-arrow-up-circle"></i> Total Gross</h6>
                    <h3 class="mb-0">$<?= number_format($total_gross, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card totals-card-fee">
                <div class="card-body text-center">
                    <h6 class="card-title"><i class="bi bi-percent"></i> Total Fees</h6>
                    <h3 class="mb-0">$<?= number_format($total_fee, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card totals-card-net">
                <div class="card-body text-center">
                    <h6 class="card-title"><i class="bi bi-graph-up-arrow"></i> Total Net</h6>
                    <h3 class="mb-0">$<?= number_format($total_net, 2) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-search"></i> Search Filters</h6>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-2 search-form">
                <div class="col-md-2">
                    <input type="text" name="search_trans_id" class="form-control form-control-sm" 
                           placeholder="Transaction ID" value="<?= h($search_trans_id) ?>">
                </div>
                <div class="col-md-2">
                    <input type="text" name="search_name" class="form-control form-control-sm" 
                           placeholder="Name" value="<?= h($search_name) ?>">
                </div>
                <div class="col-md-2">
                    <input type="text" name="search_email" class="form-control form-control-sm" 
                           placeholder="Email" value="<?= h($search_email) ?>">
                </div>
                <div class="col-md-2">
                    <select name="type_filter" class="form-control form-control-sm">
                        <option value="">All Types</option>
                        <option value="Payment" <?= $type_filter == 'Payment' ? 'selected' : '' ?>>Payment</option>
                        <option value="Withdrawal" <?= $type_filter == 'Withdrawal' ? 'selected' : '' ?>>Withdrawal</option>
                        <option value="Refund" <?= $type_filter == 'Refund' ? 'selected' : '' ?>>Refund</option>
                        <option value="Subscription" <?= $type_filter == 'Subscription' ? 'selected' : '' ?>>Subscription</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="date_from" class="form-label visually-hidden">From Date</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" 
                           value="<?= h($date_from) ?>" id="date_from">
                </div>
                <div class="col-md-2">
                    <label for="date_to" class="form-label visually-hidden">To Date</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" 
                           value="<?= h($date_to) ?>" id="date_to">
                </div>
                <div class="col-md-12 mt-2">
                    <button type="submit" class="btn btn-primary btn-sm me-1">
                        <i class="bi bi-search"></i> Search
                    </button>
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary btn-sm">
                        <i class="bi bi-arrow-clockwise"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-12 d-flex justify-content-between">
            <div>
                <!-- Empty for alignment -->
            </div>
            <div>
                <button type="button" onclick="exportPayPalToExcel()" class="btn btn-success btn-sm me-2">
                    <i class="bi bi-download"></i> Export to Excel
                </button>
                <!-- PayPal Import Button -->
                <button type="button" class="btn btn-warning btn-sm text-white" data-bs-toggle="modal" data-bs-target="#importPayPalModal">
                    <i class="bi bi-paypal"></i> Import PayPal
                </button>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-bordered" id="paypalDataTable" style="width:100%">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Currency</th>
                    <th>Gross</th>
                    <th>Fee</th>
                    <th>Net</th>
                    <th>From Email</th>
                    <th>To Email</th>
                    <th>Transaction ID</th>
                    <th>Item Title</th>
                    <th>Balance</th>
                    <th>Imported At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($paypal_transactions)): ?>
                    <?php foreach($paypal_transactions as $paypal_row): 
                        // Prepare data for modals
                        $paypal_js_safe_remarks = addslashes($paypal_row['note'] ?? '');
                    ?>
                    <tr>
                        <td><?= h($paypal_row['id']) ?></td>
                        <td><?= h($paypal_row['date']) ?></td>
                        <td><?= h($paypal_row['time']) ?></td>
                        <td class="remarks-cell" title="<?= h($paypal_row['name']) ?>">
                            <?= h($paypal_row['name']) ?>
                        </td>
                        <td>
                            <span class="badge 
                                <?= $paypal_row['type'] == 'Payment' ? 'bg-success' : '' ?>
                                <?= $paypal_row['type'] == 'Withdrawal' ? 'bg-danger' : '' ?>
                                <?= $paypal_row['type'] == 'Refund' ? 'bg-warning' : '' ?>
                                <?= !in_array($paypal_row['type'], ['Payment', 'Withdrawal', 'Refund']) ? 'bg-info' : '' ?>
                            ">
                                <?= h($paypal_row['type']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge 
                                <?= $paypal_row['status'] == 'Completed' ? 'bg-success' : '' ?>
                                <?= $paypal_row['status'] == 'Pending' ? 'bg-warning' : '' ?>
                                <?= $paypal_row['status'] == 'Failed' ? 'bg-danger' : '' ?>
                                <?= $paypal_row['status'] == 'Refunded' ? 'bg-info' : '' ?>
                                <?= !in_array($paypal_row['status'], ['Completed', 'Pending', 'Failed', 'Refunded']) ? 'bg-secondary' : '' ?>
                            ">
                                <?= h($paypal_row['status']) ?>
                            </span>
                        </td>
                        <td><?= h($paypal_row['currency']) ?></td>
                        <td class="amount-deposit"><?= $paypal_row['gross'] > 0 ? $paypal_row['currency'] . ' ' . number_format($paypal_row['gross'], 2) : '—' ?></td>
                        <td class="amount-withdrawal"><?= $paypal_row['fee'] > 0 ? $paypal_row['currency'] . ' ' . number_format($paypal_row['fee'], 2) : '—' ?></td>
                        <td class="amount-balance <?= $paypal_row['net'] >= 0 ? 'text-success' : 'text-danger' ?>">
                            <strong><?= $paypal_row['currency'] . ' ' . number_format($paypal_row['net'], 2) ?></strong>
                        </td>
                        <td class="remarks-cell" title="<?= h($paypal_row['from_email']) ?>">
                            <?= h($paypal_row['from_email']) ?>
                        </td>
                        <td class="remarks-cell" title="<?= h($paypal_row['to_email']) ?>">
                            <?= h($paypal_row['to_email']) ?>
                        </td>
                        <td>
                            <strong class="text-primary"><?= h($paypal_row['transaction_id']) ?></strong>
                        </td>
                        <td class="remarks-cell" title="<?= h($paypal_row['item_title']) ?>">
                            <?= h($paypal_row['item_title']) ?>
                        </td>
                        <td class="amount-balance">
                            <?= $paypal_row['currency'] . ' ' . number_format($paypal_row['balance'], 2) ?>
                        </td>
                        <td><?= h($paypal_row['imported_at']) ?></td>
                        <td>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-info btn-sm btn-action" 
                                        onclick="openPayPalDetailsModal(<?= $paypal_row['id'] ?>)" 
                                        title="View Details">
                                    <i class="bi bi-eye"></i>
                                </button>
                                
                                <button type="button" class="btn btn-warning btn-sm btn-action" 
                                        onclick="openPayPalEditModal(<?= $paypal_row['id'] ?>, '<?= $paypal_js_safe_remarks ?>')" 
                                        title="Edit Note">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                
                                <button type="button" class="btn btn-danger btn-sm btn-action" 
                                        onclick="confirmPayPalDelete(<?= $paypal_row['id'] ?>)" 
                                        title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="17" class="text-center text-muted py-4">
                            <i class="bi bi-inbox display-4 d-block mb-2"></i>
                            No PayPal transactions found.
                            <?php if (!empty($where_conditions)): ?>
                                <br><small>Try clearing your search filters.</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- PayPal Details Modal -->
<div class="modal fade" id="paypalDetailsModal" tabindex="-1" aria-labelledby="paypalDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="paypalDetailsModalLabel">
                    <i class="bi bi-paypal text-primary"></i> PayPal Transaction Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="paypalDetailsContent">
                <!-- Details will be loaded via AJAX -->
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- PayPal Edit Note Modal -->
<div class="modal fade" id="paypalEditModal" tabindex="-1" aria-labelledby="paypalEditModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="update_paypal_note.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="paypalEditModalLabel">Edit PayPal Note</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="paypal_edit_id">
                    <div class="mb-3">
                        <label for="paypal_edit_note" class="form-label">Note</label>
                        <textarea class="form-control" id="paypal_edit_note" name="note" rows="4" placeholder="Enter note..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_paypal_note" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Update Note
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- PayPal Import Modal -->
<div class="modal fade" id="importPayPalModal" tabindex="-1" aria-labelledby="importPayPalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <!-- IMPORTANT: Hidden input field to ensure form submission is detected -->
            <form action="upload_paypal.php" method="POST" enctype="multipart/form-data" id="paypalUploadForm">
                <input type="hidden" name="save_paypal_excel_data" value="1">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="importPayPalModalLabel">
                        <i class="bi bi-paypal text-primary"></i> Import PayPal Transactions
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> PayPal File Requirements:</h6>
                        <ul class="mb-0 small">
                            <li>Supported formats: Excel (.xlsx, .xls) or CSV</li>
                            <li>Use standard PayPal export format with all columns</li>
                            <li><strong>Required columns:</strong> Date, Transaction ID</li>
                            <li>Duplicate transactions will be skipped automatically</li>
                            <li>First row should be column headers</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <label for="paypal_import_file" class="form-label">Select PayPal Export File</label>
                        <input type="file" name="import_file" accept=".xlsx,.xls,.csv" class="form-control" id="paypal_import_file" required/>
                        <div class="form-text">
                            Upload your PayPal transaction history export file. Make sure it contains all required columns.
                        </div>
                    </div>

                    <div class="mb-3">
                        <h6>Expected Column Order:</h6>
                        <div class="row small text-muted">
                            <div class="col-md-6">
                                <ol>
                                    <li>Date</li>
                                    <li>Time</li>
                                    <li>Timezone</li>
                                    <li>Name</li>
                                    <li>Type</li>
                                    <li>Status</li>
                                    <li>Currency</li>
                                    <li>Gross</li>
                                    <li>Fee</li>
                                    <li>Net</li>
                                    <li>From Email</li>
                                    <li>To Email</li>
                                    <li>Transaction ID</li>
                                    <li>Shipping Address</li>
                                    <li>Address Status</li>
                                    <li>Item Title</li>
                                    <li>Item ID</li>
                                    <li>Shipping Amount</li>
                                    <li>Insurance Amount</li>
                                    <li>Sales Tax</li>
                                </ol>
                            </div>
                            <div class="col-md-6">
                                <ol start="21">
                                    <li>Option 1 Name</li>
                                    <li>Option 1 Value</li>
                                    <li>Option 2 Name</li>
                                    <li>Option 2 Value</li>
                                    <li>Reference Txn ID</li>
                                    <li>Invoice Number</li>
                                    <li>Custom Number</li>
                                    <li>Quantity</li>
                                    <li>Receipt ID</li>
                                    <li>Balance</li>
                                    <li>Address Line 1</li>
                                    <li>Address Line 2</li>
                                    <li>City</li>
                                    <li>State</li>
                                    <li>Zip Code</li>
                                    <li>Country</li>
                                    <li>Contact Phone</li>
                                    <li>Subject</li>
                                    <li>Note</li>
                                    <li>Country Code</li>
                                    <li>Balance Impact</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-paypal"></i> Import PayPal Data
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Temporary Debug Form -->
<div class="card mt-4 border-warning">
    <div class="card-header bg-warning">
        <h5 class="mb-0"><i class="bi bi-tools"></i> Debug Upload Form</h5>
    </div>
    <div class="card-body">
        <p class="text-muted">Use this form to test if uploads work without the modal.</p>
        <form action="upload_paypal.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="save_paypal_excel_data" value="1">
            <div class="mb-3">
                <label class="form-label">Select Test File</label>
                <input type="file" name="import_file" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-warning">
                <i class="bi bi-rocket-takeoff"></i> Test Upload
            </button>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.bootstrap5.js"></script>

<script>
// Initialize PayPal DataTable
$(document).ready(function() {
    $('#paypalDataTable').DataTable({
        scrollX: true,
        pageLength: 25,
        order: [[1, 'desc']], // Sort by date descending
        language: {
            search: "Search PayPal transactions:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ transactions",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        }
    });
});

// Open PayPal Details Modal
function openPayPalDetailsModal(id) {
    // Show loading spinner
    document.getElementById('paypalDetailsContent').innerHTML = `
        <div class="text-center">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    `;
    
    // Fetch transaction details via AJAX
    fetch('get_paypal_details.php?id=' + id)
        .then(response => response.text())
        .then(data => {
            document.getElementById('paypalDetailsContent').innerHTML = data;
        })
        .catch(error => {
            document.getElementById('paypalDetailsContent').innerHTML = `
                <div class="alert alert-danger">
                    Error loading transaction details: ${error}
                </div>
            `;
        });
    
    // Show modal
    var modal = new bootstrap.Modal(document.getElementById('paypalDetailsModal'));
    modal.show();
}

// Open PayPal Edit Modal
function openPayPalEditModal(id, note) {
    document.getElementById('paypal_edit_id').value = id;
    
    // Clean up note
    let cleanNote = note ? note.replace(/\\'/g, "'").replace(/\\"/g, '"') : '';
    document.getElementById('paypal_edit_note').value = cleanNote;
    
    // Show modal
    var modal = new bootstrap.Modal(document.getElementById('paypalEditModal'));
    modal.show();
}

// Confirm PayPal Delete
function confirmPayPalDelete(id) {
    if (confirm('Are you sure you want to delete this PayPal transaction? This action cannot be undone.')) {
        window.location.href = '<?= $_SERVER['PHP_SELF'] ?>?delete_id=' + id;
    }
}

// Export PayPal to Excel
function exportPayPalToExcel() {
    var tab_text = '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    tab_text = tab_text + '<head><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
    tab_text = tab_text + '<x:Name>PayPal Transactions</x:Name>';
    tab_text = tab_text + '<x:WorksheetOptions><x:Panes></x:Panes></x:WorksheetOptions></x:ExcelWorksheet>';
    tab_text = tab_text + '</x:ExcelWorksheets></x:ExcelWorkbook></xml></head><body>';
    
    var table = document.getElementById('paypalDataTable').cloneNode(true);
    var headRow = table.querySelector('thead tr');
    // Remove the "Actions" header column
    if (headRow && headRow.children.length > 0) {
        headRow.children[headRow.children.length - 1].remove();
    }

    // Remove the "Actions" data cells
    table.querySelectorAll('tbody tr').forEach(function(row) {
        if (row.children.length > 0) {
            row.children[row.children.length - 1].remove();
        }
    });

    tab_text = tab_text + '<table>' + table.innerHTML + '</table>';
    tab_text = tab_text + '</body></html>';

    var downloadLink = document.createElement("a");
    var filename = 'paypal_transactions.xls';
    downloadLink.href = 'data:application/vnd.ms-excel,' + encodeURIComponent(tab_text);
    downloadLink.download = filename;
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

// Handle PayPal form submission
document.addEventListener('DOMContentLoaded', function() {
    const paypalForm = document.getElementById('paypalUploadForm');
    
    if (paypalForm) {
        paypalForm.addEventListener('submit', function(e) {
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Importing...';
            submitBtn.disabled = true;
            
            console.log('PayPal form submitting...');
            // Let the form submit normally
        });
    }
    
    // Reset form when modal is closed
    const importModal = document.getElementById('importPayPalModal');
    if (importModal) {
        importModal.addEventListener('hidden.bs.modal', function() {
            const form = document.getElementById('paypalUploadForm');
            if (form) {
                form.reset();
            }
        });
    }
});

// Auto-close alerts after 5 seconds
$(document).ready(function() {
    setTimeout(function() {
        $('.alert').alert('close');
    }, 5000);
});
</script>
</body>
</html>
</div>
</div>