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

function formatAmount($amount) {
    return '₹' . number_format($amount, 2);
}

function redirect_with($params = []) {
    $base = 'expenses.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Get all active expenses for dropdown
function getExpenseTypes($mysqli) {
    $types = [];
    $result = $mysqli->query("SELECT expense_id, expense_name FROM expense_master WHERE status = 'Active' ORDER BY expense_name");
    while ($row = $result->fetch_assoc()) {
        $types[$row['expense_id']] = $row['expense_name'];
    }
    return $types;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $date = $_POST['date'] ?? '';
        $payee = trim($_POST['payee'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $purpose = (int)($_POST['purpose'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $debit_amount = floatval($_POST['debit_amount'] ?? 0);
        
        // Basic validation
        if (empty($date)) {
            redirect_with(['msg' => 'Date is required.', 'type' => 'danger']);
        }
        
        if (empty($payee)) {
            redirect_with(['msg' => 'Payee is required.', 'type' => 'danger']);
        }
        
        if ($purpose <= 0) {
            redirect_with(['msg' => 'Please select a valid purpose.', 'type' => 'danger']);
        }
        
        if ($debit_amount <= 0) {
            redirect_with(['msg' => 'Debit amount must be greater than 0.', 'type' => 'danger']);
        }
        
        // Convert date to MySQL format
        $mysql_date = date('Y-m-d', strtotime($date));
        
        if ($action === 'add') {
            // Insert new expense
            $stmt = $mysqli->prepare("INSERT INTO expenses (date, payee, contact_person, purpose, description, debit_amount, created_date) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("sssisd", $mysql_date, $payee, $contact_person, $purpose, $description, $debit_amount);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Expense added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding expense: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $expense_id = (int)($_POST['expense_id'] ?? 0);
            
            if ($expense_id > 0) {
                // Update expense
                $stmt = $mysqli->prepare("UPDATE expenses SET date = ?, payee = ?, contact_person = ?, purpose = ?, description = ?, debit_amount = ? WHERE id = ?");
                $stmt->bind_param("sssisdi", $mysql_date, $payee, $contact_person, $purpose, $description, $debit_amount, $expense_id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Expense updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating expense: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $expense_id = (int)($_POST['expense_id'] ?? 0);
        
        if ($expense_id > 0) {
            // Delete expense
            $del = $mysqli->prepare("DELETE FROM expenses WHERE id = ?");
            $del->bind_param("i", $expense_id);
            
            if ($del->execute()) {
                redirect_with(['msg' => "Expense deleted successfully", 'type' => 'warning']);
            } else {
                redirect_with(['msg' => 'Error deleting expense: ' . $del->error, 'type' => 'danger']);
            }
            $del->close();
        }
    }
    
    redirect_with();
}

// Get expense types for dropdown
$expenseTypes = getExpenseTypes($mysqli);

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editExpense = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT id, date, payee, contact_person, purpose, description, debit_amount, created_date FROM expenses WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editExpense = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // Format date for form
        if ($editExpense && !empty($editExpense['date'])) {
            $editExpense['date'] = date('Y-m-d', strtotime($editExpense['date']));
        }
    }
    if (!$editExpense) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$purpose_filter = $_GET['purpose_filter'] ?? '';
$sort = $_GET['sort'] ?? 'created_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query
$sql = "SELECT e.id, e.date, e.payee, e.contact_person, e.purpose, e.description, e.debit_amount, e.created_date, 
               em.expense_name as purpose_name 
        FROM expenses e 
        LEFT JOIN expense_master em ON e.purpose = em.expense_id";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(e.payee LIKE ? OR e.contact_person LIKE ? OR e.description LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if (!empty($start_date)) {
    $cond[] = "e.date >= ?";
    $params[] = date('Y-m-d', strtotime($start_date));
    $types .= 's';
}

if (!empty($end_date)) {
    $cond[] = "e.date <= ?";
    $params[] = date('Y-m-d', strtotime($end_date));
    $types .= 's';
}

if (!empty($purpose_filter)) {
    $cond[] = "e.purpose = ?";
    $params[] = $purpose_filter;
    $types .= 'i';
}

if (!empty($cond)) {
    $sql .= " WHERE " . implode(" AND ", $cond);
}

$sql .= " ORDER BY e.$sort $dir";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $expenses = $stmt->get_result();
} else {
    $expenses = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$expenses) {
    $expenses = false;
}

// Calculate total
$total_amount = 0;
if ($expenses && $expenses->num_rows > 0) {
    $expenses_data = $expenses->fetch_all(MYSQLI_ASSOC);
    foreach ($expenses_data as $exp) {
        $total_amount += $exp['debit_amount'];
    }
    // Reset pointer
    $expenses->data_seek(0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1400px;
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
        .total-amount {
            background-color: #e8f5e8;
            font-weight: bold;
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
            <h4><i class="fas fa-money-bill me-2"></i><?= $mode === 'edit' ? 'Edit Expense' : 'Add New Expense' ?></h4>
            <a href="expenses.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="expense_id" value="<?= (int)($editExpense['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="date" class="form-label">Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="date" name="date" 
                                       value="<?= h($editExpense['date'] ?? date('Y-m-d')) ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="payee" class="form-label">Payee <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="payee" name="payee" 
                                       value="<?= h($editExpense['payee'] ?? '') ?>" 
                                       placeholder="Enter payee name" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="contact_person" class="form-label">Contact Person</label>
                                <input type="text" class="form-control" id="contact_person" name="contact_person" 
                                       value="<?= h($editExpense['contact_person'] ?? '') ?>" 
                                       placeholder="Enter contact person name">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="purpose" class="form-label">Purpose <span class="text-danger">*</span></label>
                                <select class="form-select" id="purpose" name="purpose" required>
                                    <option value="">Select Purpose</option>
                                    <?php foreach($expenseTypes as $id => $name): ?>
                                    <option value="<?= $id ?>" <?= (isset($editExpense['purpose']) && $editExpense['purpose'] == $id) ? 'selected' : '' ?>>
                                        <?= h($name) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" 
                                          rows="3" placeholder="Enter expense description"><?= h($editExpense['description'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="debit_amount" class="form-label">Debit Amount <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="debit_amount" name="debit_amount" 
                                       value="<?= h($editExpense['debit_amount'] ?? '') ?>" 
                                       placeholder="0.00" step="0.01" min="0.01" required>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Expense' : 'Save Expense' ?>
                        </button>
                        <a href="expenses.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-money-bill me-2"></i>Expenses Management</h4>
            <a href="expenses.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add Expense
            </a>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search payee, contact, description..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="start_date" class="form-control form-control-sm" 
                               value="<?= h($start_date) ?>" placeholder="From Date">
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="end_date" class="form-control form-control-sm" 
                               value="<?= h($end_date) ?>" placeholder="To Date">
                    </div>
                    <div class="col-md-2">
                        <select name="purpose_filter" class="form-select form-select-sm">
                            <option value="">All Purposes</option>
                            <?php foreach($expenseTypes as $id => $name): ?>
                            <option value="<?= $id ?>" <?= $purpose_filter == $id ? 'selected' : '' ?>>
                                <?= h($name) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="payee" <?= $sort === 'payee' ? 'selected' : '' ?>>Sort by Payee</option>
                            <option value="debit_amount" <?= $sort === 'debit_amount' ? 'selected' : '' ?>>Sort by Amount</option>
                            <option value="created_date" <?= $sort === 'created_date' ? 'selected' : '' ?>>Sort by Created</option>
                        </select>
                    </div>
                    <!-- <div class="col-md-1">
                        <select name="dir" class="form-select form-select-sm">
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Desc</option>
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Asc</option>
                        </select>
                    </div> -->
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="expenses.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Expenses Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="50">S.No</th>
                                <th width="100">Date</th>
                                <th>Payee</th>
                                <th>Contact Person</th>
                                <th>Purpose</th>
                                <th>Description</th>
                                <th width="120" class="text-end">Amount</th>
                                <th width="120">Created Date</th>
                                <th width="150" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($expenses && $expenses->num_rows > 0): $i=0;?>
                                <?php while($row = $expenses->fetch_assoc()): $i=$i+1;?>
                                <tr>
                                    <td class="fw-bold"><?= $i ?></td>
                                    <td>
                                        <small class="text-muted"><?= h(formatDate($row['date'])) ?></small>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= h($row['payee']) ?></span>
                                    </td>
                                    <td><?= h($row['contact_person'] ?: '—') ?></td>
                                    <td>
                                        <span class="badge bg-info"><?= h($row['purpose_name']) ?></span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= h($row['description'] ?: '—') ?></small>
                                    </td>
                                    <td class="text-end fw-bold text-danger">
                                        <?= h(formatAmount($row['debit_amount'])) ?>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= h(formatDate($row['created_date'])) ?></small>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="expenses.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit Expense">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this expense?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="expense_id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Expense">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                <!-- Total Row -->
                                <tr class="total-amount">
                                    <td colspan="6" class="text-end fw-bold">Total Amount:</td>
                                    <td class="text-end fw-bold text-danger"><?= h(formatAmount($total_amount)) ?></td>
                                    <td colspan="2"></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-money-bill fa-3x mb-3"></i>
                                            <h5>No expenses found</h5>
                                            <p class="mb-0">Get started by adding your first expense.</p>
                                            <a href="expenses.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Expense
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
            const filterSelects = document.querySelectorAll('select[name="purpose_filter"], select[name="sort"]');
            //  select[name="dir"]
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });

            // Set today's date as default for date field in add mode
            const dateField = document.getElementById('date');
            if (dateField && !dateField.value) {
                dateField.valueAsDate = new Date();
            }
        });
    </script>
</body>
</html>