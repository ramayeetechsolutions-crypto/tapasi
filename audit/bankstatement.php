<?php 
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session and require DB connection FIRST
session_start();
require 'db.php'; // Assume this file provides $mysqli connection

// --- Handle Update Action ---
if (isset($_POST['update_remarks_date'])) {
    $id = (int)$_POST['id'];
    $remarks = $_POST['remarks'] ?? '';
    $date = $_POST['date'] ?? '';
    
    error_log("Update attempt - ID: $id, Remarks: $remarks, Date: $date");
    
    $stmt = $mysqli->prepare("UPDATE bank_transactions SET remarks = ?, date = ? WHERE id = ?");
    
    if (!$stmt) {
        $_SESSION['message'] = "Prepare failed: " . $mysqli->error;
        error_log("Prepare failed: " . $mysqli->error);
    } else {
        // 'ssi' type definition for string, string, integer
        $stmt->bind_param("ssi", $remarks, $date, $id);
        if ($stmt->execute()) {
            $_SESSION['message'] = "Remarks and date updated successfully";
            error_log("Update successful for ID: $id");
        } else {
            $_SESSION['message'] = "Error updating: " . $stmt->error;
            error_log("Update failed: " . $stmt->error);
        }
        $stmt->close();
    }
    
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

// --- Handle Delete Action ---
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    
    $stmt = $mysqli->prepare("DELETE FROM bank_transactions WHERE id = ?");
    
    if (!$stmt) {
        $_SESSION['message'] = "Prepare failed: " . $mysqli->error;
    } else {
        $stmt->bind_param("i", $delete_id);
        if ($stmt->execute()) {
            $_SESSION['message'] = "Transaction deleted successfully";
        } else {
            $_SESSION['message'] = "Error deleting transaction: " . $stmt->error;
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
// session_start(); // REMOVED DUPLICATE
$_SESSION['username']='Audit'; // Setting a session variable

/**
 * HTML Escape function to prevent XSS.
 * @param string $s The string to escape.
 * @return string The escaped string.
 */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

// --- Search Parameters ---
$search_trans_id = $_GET['search_trans_id'] ?? '';
$search_remarks = $_GET['search_remarks'] ?? '';
$search_cheque_ref = $_GET['search_cheque_ref'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build WHERE clause for search
$where_conditions = [];
$params = [];
$types = '';

if (!empty($search_trans_id)) {
    $where_conditions[] = "trans_id LIKE ?";
    $params[] = "%$search_trans_id%";
    $types .= 's';
}

if (!empty($search_remarks)) {
    $where_conditions[] = "transaction_remarks LIKE ?";
    $params[] = "%$search_remarks%";
    $types .= 's';
}

if (!empty($search_cheque_ref)) {
    $where_conditions[] = "cheque_ref_no LIKE ?";
    $params[] = "%$search_cheque_ref%";
    $types .= 's';
}

if (!empty($date_from)) {
    $where_conditions[] = "transaction_date >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $where_conditions[] = "transaction_date <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$where_sql = "";
if (!empty($where_conditions)) {
    $where_sql = " WHERE " . implode(" AND ", $where_conditions);
}

// Main query 
$sql = "SELECT 
    id, sn, trans_id, value_date, transaction_date, transaction_posted_date, 
    cheque_ref_no, transaction_remarks, withdrawal, deposit, balance, remarks,
    created_at, date
FROM bank_transactions
$where_sql
ORDER BY transaction_date DESC, id DESC";

$stmt = $mysqli->prepare($sql);
if (!$stmt) die("Prepare failed: ".$mysqli->error);

if (!empty($params)) {
    // Note: The splat operator (...) unpacks the $params array for bind_param
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$results = $stmt->get_result();

// Calculate totals
$total_withdrawal = 0;
$total_deposit = 0;
$transactions = [];
while($row = $results->fetch_assoc()) {
    $transactions[] = $row;
    $total_withdrawal += $row['withdrawal'];
    $total_deposit += $row['deposit'];
}

$stmt->close();
?>

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
.remarks-cell { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; cursor: pointer; }
.remarks-cell:hover { overflow: visible; white-space: normal; background: #f8f9fa; }
</style>

<div class="container-fluid mt-3">
    <h2>Bank Transactions Management</h2>

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
                    <h6 class="card-title">Total Deposits</h6>
                    <h3 class="mb-0">₹<?= number_format($total_deposit, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-light">
                <div class="card-body text-center">
                    <h6 class="card-title">Total Withdrawals</h6>
                    <h3 class="mb-0 text-danger">₹<?= number_format($total_withdrawal, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <h6 class="card-title">Net Flow</h6>
                    <h3 class="mb-0">₹<?= number_format($total_deposit - $total_withdrawal, 2) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h6 class="mb-0">Search Filters</h6>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-2 search-form">
                <div class="col-md-3">
                    <input type="text" name="search_trans_id" class="form-control form-control-sm" 
                           placeholder="Search by Transaction ID" value="<?= h($search_trans_id) ?>">
                </div>
                <div class="col-md-3">
                    <input type="text" name="search_remarks" class="form-control form-control-sm" 
                           placeholder="Search by Remarks" value="<?= h($search_remarks) ?>">
                </div>
                <div class="col-md-2">
                    <input type="text" name="search_cheque_ref" class="form-control form-control-sm" 
                           placeholder="Cheque/Ref No" value="<?= h($search_cheque_ref) ?>">
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
                         </div>
            <div>
                <button type="button" onclick="exportTableToExcel('dataTable','bank_transactions')" class="btn btn-success btn-sm me-2">
                    <i class="bi bi-download"></i> Export to Excel
                </button>
                <button type="button" class="btn btn-info btn-sm text-white" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="bi bi-file-earmark-arrow-up"></i> Import Excel
                </button>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-bordered" id="dataTable" style="width:100%">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>S.N.</th>
                    <th>Trans. ID</th>
                    <th>Value Date</th>
                    <th>Trans. Date</th>
                    <th>Posted Date</th>
                    <th>Cheque/Ref No</th>
                    <th>Transaction Remarks</th>
                    <th>Withdrawal (₹)</th>
                    <th>Deposit (₹)</th>
                    <th>Balance (₹)</th>
                    <th>Created At</th>
                    <th>Remarks</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
          <tbody>
            <?php if (!empty($transactions)): foreach($transactions as $row): 
                
                // FIX APPLIED HERE: Use ?? '' to ensure a string is passed to addslashes()
                $js_safe_remarks = addslashes($row['remarks'] ?? '');
                
                // Also ensuring 'date' is a string for the onclick parameter
                $date_js_safe = h($row['date'] ?? '');
            ?>
            <tr>
                <td><?= h($row['id']) ?></td>
                <td><?= h($row['sn']) ?></td>
                <td><strong><?= h($row['trans_id']) ?></strong></td>
                <td><?= h($row['value_date']) ?></td>
                <td><?= h($row['transaction_date']) ?></td>
                <td><?= h($row['transaction_posted_date']) ?></td>
                <td><?= h($row['cheque_ref_no']) ?></td>
                <td class="remarks-cell" title="<?= h($row['transaction_remarks']) ?>"><?= h($row['transaction_remarks']) ?></td>
                <td class="amount-withdrawal"><?= $row['withdrawal'] > 0 ? '₹' . number_format($row['withdrawal'], 2) : '—' ?></td>
                <td class="amount-deposit"><?= $row['deposit'] > 0 ? '₹' . number_format($row['deposit'], 2) : '—' ?></td>
                <td class="amount-balance">₹<?= number_format($row['balance'], 2) ?></td>
                <td><?= h($row['created_at']) ?></td>
                
                <td class="remarks-cell" title="<?= h($row['remarks']) ?>" 
                    onclick="openEditModal(<?= $row['id'] ?>, '<?= $js_safe_remarks ?>', '<?= $date_js_safe ?>')">
                    <?= h($row['remarks']) ?>
                </td>
                
                <td><?= h($row['date']) ?></td>
                <td>
                    <div class="btn-group" role="group">
                        <!--a href="edit_transaction.php?id=<?= $row['id'] ?>" class="btn btn-warning btn-sm btn-action" title="Edit Full Record">
                            <i class="bi bi-pencil"></i>
                        </a-->
                        
                        <button type="button" class="btn btn-info btn-sm btn-action" 
                                onclick="openEditModal(<?= $row['id'] ?>, '<?= $js_safe_remarks ?>', '<?= $date_js_safe ?>')" title="Edit Remarks & Date">
                            <i class="bi bi-chat-text"></i>
                        </button>
                        
                        <button type="button" class="btn btn-danger btn-sm btn-action" 
                                onclick="confirmDelete(<?= $row['id'] ?>)" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; else: ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</div>

<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="upload.php" method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title" id="importModalLabel">Upload Excel File</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Select an Excel file (.xlsx, .xls, .csv) to import bank transactions.</p>
                    <input type="file" name="import_file" accept=".xlsx,.xls,.csv" class="form-control" required/>
                    <small class="text-muted">File should contain columns: SN, Trans ID, Value Date, Transaction Date, etc.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="save_excel_data" class="btn btn-primary">
                        <i class="bi bi-cloud-upload"></i> Upload & Import
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editRemarksModal" tabindex="-1" aria-labelledby="editRemarksModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= $_SERVER['PHP_SELF'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="editRemarksModalLabel">Edit Remarks & Date</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="mb-3">
                        <label for="edit_remarks" class="form-label">Remarks</label>
                        <textarea class="form-control" id="edit_remarks" name="remarks" rows="4" placeholder="Enter remarks..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="edit_date" class="form-label">Date</label>
                        <input type="date" class="form-control" id="edit_date" name="date">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_remarks_date" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Update
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.bootstrap5.js"></script>

<script>
$(document).ready(function() {
    // Initialize Datatables
    $('#dataTable').DataTable({
        scrollX: true,
        pageLength: 25,
        order: [[4, 'desc']] // Column 4 is "Trans. Date"
    });
});

function exportTableToExcel(tableID, filename='') {
    var tab_text = '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    tab_text = tab_text + '<head><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
    tab_text = tab_text + '<x:Name>Bank Transactions</x:Name>';
    tab_text = tab_text + '<x:WorksheetOptions><x:Panes></x:Panes></x:WorksheetOptions></x:ExcelWorksheet>';
    tab_text = tab_text + '</x:ExcelWorksheets></x:ExcelWorkbook></xml></head><body>';
    
    var table = document.getElementById(tableID).cloneNode(true);
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
    filename = filename ? filename + '.xls' : 'bank_transactions.xls';
    downloadLink.href = 'data:application/vnd.ms-excel,' + encodeURIComponent(tab_text);
    downloadLink.download = filename;
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

function confirmDelete(id) {
    if (confirm('Are you sure you want to delete this transaction? This action cannot be undone.')) {
        window.location.href = '<?= $_SERVER['PHP_SELF'] ?>?delete_id=' + id;
    }
}

/**
 * Opens the Edit Remarks & Date Modal and populates the fields.
 * @param {number} id - The transaction ID.
 * @param {string} remarks - The remarks text (should be PHP addslashes escaped).
 * @param {string} date - The date.
 */
function openEditModal(id, remarks, date) {
    console.log('Opening modal for ID:', id, 'Remarks (escaped):', remarks, 'Date:', date);
    
    try {
        // Set the hidden ID field
        document.getElementById('edit_id').value = id;
        
        // Clean up remarks: 
        // 1. Replaces PHP's addslashes output (e.g., John\'s) back to the original string.
        // 2. The double quote replace might be vestigial but is safe to keep.
        let cleanRemarks = remarks ? remarks.replace(/\\'/g, "'").replace(/\\"/g, '"') : '';
        document.getElementById('edit_remarks').value = cleanRemarks;
        
        // Set the date field
        document.getElementById('edit_date').value = date;
        
        // Show the modal
        var modalElement = document.getElementById('editRemarksModal');
        if (modalElement) {
            // Ensure the Bootstrap Modal object is created/reused correctly
            var modal = bootstrap.Modal.getOrCreateInstance(modalElement);
            modal.show();
            console.log('Modal shown successfully');
        } else {
            console.error('Modal element not found');
        }
    } catch (error) {
        console.error('Error opening modal:', error);
        alert('Error opening edit form. Please check console for details.');
    }
}
</script>

</div>
</div>