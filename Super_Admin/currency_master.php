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
    return date('d-m-Y H:i:s', strtotime($d));
}

function redirect_with($params = []) {
    $base = 'currency_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Debug function - remove in production
function debugLog($message, $data = null) {
    file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - ' . $message . ($data ? ': ' . print_r($data, true) : '') . PHP_EOL, FILE_APPEND);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    debugLog('POST request received', $_POST);
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $currency_code = trim(strtoupper($_POST['currency_code'] ?? ''));
        $currency_name = trim($_POST['currency_name'] ?? '');
        $currency_symbol = trim($_POST['currency_symbol'] ?? '');
        $exchange_rate = (float)$_POST['exchange_rate'] ?? 0;
        $auto_update = isset($_POST['auto_update']) ? 1 : 0;
        
        debugLog('Form data', [
            'currency_code' => $currency_code,
            'currency_name' => $currency_name,
            'currency_symbol' => $currency_symbol,
            'exchange_rate' => $exchange_rate,
            'auto_update' => $auto_update,
            'action' => $action
        ]);
        
        // Basic validation
        $errors = [];
        if ($currency_code === '') $errors[] = 'Currency Code is required';
        if (strlen($currency_code) !== 3) $errors[] = 'Currency Code must be exactly 3 characters';
        if ($currency_name === '') $errors[] = 'Currency Name is required';
        if ($currency_symbol === '') $errors[] = 'Currency Symbol is required';
        if ($exchange_rate <= 0) $errors[] = 'Exchange Rate must be greater than 0';
        
        if (!empty($errors)) {
            debugLog('Validation errors', $errors);
            redirect_with(['msg' => implode('<br>', $errors), 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Check if currency already exists by code
            $check_stmt = $mysqli->prepare("SELECT currency_id FROM currency_master WHERE currency_code = ?");
            if (!$check_stmt) {
                debugLog('Prepare failed for check currency code', $mysqli->error);
                redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
            }
            
            $check_stmt->bind_param("s", $currency_code);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                debugLog('Currency code already exists', $currency_code);
                redirect_with(['msg' => 'Currency with this code already exists.', 'type' => 'danger']);
            }
            $check_stmt->close();
            
            // Check by name
            $check_stmt = $mysqli->prepare("SELECT currency_id FROM currency_master WHERE currency_name = ?");
            if (!$check_stmt) {
                debugLog('Prepare failed for check currency name', $mysqli->error);
                redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
            }
            
            $check_stmt->bind_param("s", $currency_name);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                debugLog('Currency name already exists', $currency_name);
                redirect_with(['msg' => 'Currency with this name already exists.', 'type' => 'danger']);
            }
            $check_stmt->close();
            
            // Insert new currency
            $stmt = $mysqli->prepare("INSERT INTO currency_master (currency_code, currency_name, currency_symbol, exchange_rate, auto_update, status, created_date, last_updated) VALUES (?, ?, ?, ?, ?, 'Active', NOW(), NOW())");
            
            if (!$stmt) {
                debugLog('Prepare failed for insert currency', $mysqli->error);
                redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
            }
            
            $stmt->bind_param("sssdi", $currency_code, $currency_name, $currency_symbol, $exchange_rate, $auto_update);
            
            if ($stmt->execute()) {
                $insert_id = $stmt->insert_id;
                debugLog('Currency added successfully', ['id' => $insert_id, 'name' => $currency_name]);
                redirect_with(['msg' => "Currency '$currency_name' added successfully", 'type' => 'success']);
            } else {
                debugLog('Execute failed for insert currency', $stmt->error);
                redirect_with(['msg' => 'Error adding currency: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $currency_id = (int)($_POST['currency_id'] ?? 0);
            
            if ($currency_id > 0) {
                // Check if currency already exists (excluding current one)
                $check_stmt = $mysqli->prepare("SELECT currency_id FROM currency_master WHERE currency_code = ? AND currency_id != ?");
                if (!$check_stmt) {
                    redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
                }
                
                $check_stmt->bind_param("si", $currency_code, $currency_id);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $check_stmt->close();
                    redirect_with(['msg' => 'Currency with this code already exists.', 'type' => 'danger']);
                }
                $check_stmt->close();
                
                // Check name uniqueness
                $check_stmt = $mysqli->prepare("SELECT currency_id FROM currency_master WHERE currency_name = ? AND currency_id != ?");
                if (!$check_stmt) {
                    redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
                }
                
                $check_stmt->bind_param("si", $currency_name, $currency_id);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $check_stmt->close();
                    redirect_with(['msg' => 'Currency with this name already exists.', 'type' => 'danger']);
                }
                $check_stmt->close();
                
                // Update currency
                $stmt = $mysqli->prepare("UPDATE currency_master SET currency_code = ?, currency_name = ?, currency_symbol = ?, exchange_rate = ?, auto_update = ?, last_updated = NOW() WHERE currency_id = ?");
                if (!$stmt) {
                    redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
                }
                
                $stmt->bind_param("sssdii", $currency_code, $currency_name, $currency_symbol, $exchange_rate, $auto_update, $currency_id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Currency updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating currency: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    // ... rest of your POST handlers remain the same ...
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editCurrency = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT currency_id, currency_code, currency_name, currency_symbol, exchange_rate, auto_update, status, created_date, last_updated FROM currency_master WHERE currency_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $editCurrency = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
    if (!$editCurrency) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'currency_code';
$dir = strtolower($_GET['dir'] ?? 'asc');

// Build query
$sql = "SELECT currency_id, currency_code, currency_name, currency_symbol, exchange_rate, auto_update, status, created_date, last_updated FROM currency_master";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(currency_code LIKE ? OR currency_name LIKE ? OR currency_symbol LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($fstatus === 'Active' || $fstatus === 'Inactive') {
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
        $currencies = $stmt->get_result();
    } else {
        $currencies = false;
    }
} else {
    $currencies = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$currencies) {
    $currencies = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Currency Master</title>
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
        .currency-symbol {
            font-size: 1.2em;
            font-weight: bold;
        }
        .exchange-rate {
            font-family: monospace;
            font-size: 1.1em;
            color: #28a745;
            font-weight: bold;
        }
        .currency-code {
            font-family: monospace;
            background-color: #e9ecef;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
        }
        .auto-update-badge {
            font-size: 0.7em;
            padding: 2px 6px;
        }
        .last-updated {
            font-size: 0.8em;
            color: #6c757d;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .required-field::after {
            content: " *";
            color: #dc3545;
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
            <h4><i class="fas fa-money-bill-wave me-2"></i><?= $mode === 'edit' ? 'Edit Currency' : 'Add New Currency' ?></h4>
            <a href="currency_master.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off" id="currencyForm" onsubmit="return validateForm()">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="currency_id" value="<?= (int)($editCurrency['currency_id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="currency_code" class="form-label required-field">Currency Code</label>
                                <input type="text" class="form-control text-uppercase" id="currency_code" 
                                       name="currency_code" maxlength="3" 
                                       value="<?= h($editCurrency['currency_code'] ?? '') ?>" 
                                       placeholder="USD" required
                                       oninput="this.value = this.value.toUpperCase()">
                                <div class="form-text">3-letter ISO code (e.g., USD, EUR, GBP)</div>
                                <div class="invalid-feedback" id="currency_code_error">Currency Code must be exactly 3 characters</div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="currency_name" class="form-label required-field">Currency Name</label>
                                <input type="text" class="form-control" id="currency_name" name="currency_name" 
                                       value="<?= h($editCurrency['currency_name'] ?? '') ?>" 
                                       placeholder="US Dollar" required>
                                <div class="form-text">Full currency name</div>
                                <div class="invalid-feedback" id="currency_name_error">Currency Name is required</div>
                            </div>
                        </div>
                        
                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="currency_symbol" class="form-label required-field">Symbol</label>
                                <input type="text" class="form-control" id="currency_symbol" name="currency_symbol" 
                                       value="<?= h($editCurrency['currency_symbol'] ?? '') ?>" 
                                       placeholder="$" required>
                                <div class="form-text">Currency symbol (e.g., $, €, £)</div>
                                <div class="invalid-feedback" id="currency_symbol_error">Currency Symbol is required</div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="exchange_rate" class="form-label required-field">Exchange Rate</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="exchange_rate" name="exchange_rate" 
                                           step="0.0001" min="0.0001"
                                           value="<?= h($editCurrency['exchange_rate'] ?? 1.0) ?>" 
                                           placeholder="1.0000" required>
                                </div>
                                <div class="form-text">Rate relative to base currency (USD)</div>
                                <div class="invalid-feedback" id="exchange_rate_error">Exchange Rate must be greater than 0</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="auto_update" name="auto_update" 
                                       value="1" <?= ($editCurrency['auto_update'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="auto_update">
                                    <strong>Enable auto-update from exchange rate sources</strong>
                                </label>
                                <div class="form-text">
                                    When checked, exchange rate will be periodically updated from external sources.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Currency' : 'Save Currency' ?>
                        </button>
                        <a href="currency_master.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-money-bill-wave me-2"></i>Currency Master</h4>
            <a href="currency_master.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add Currency
            </a>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search by code, name or symbol..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-4">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="currency_code" <?= $sort === 'currency_code' ? 'selected' : '' ?>>Sort by Code</option>
                            <option value="currency_name" <?= $sort === 'currency_name' ? 'selected' : '' ?>>Sort by Name</option>
                            <option value="exchange_rate" <?= $sort === 'exchange_rate' ? 'selected' : '' ?>>Sort by Exchange Rate</option>
                            <option value="created_date" <?= $sort === 'created_date' ? 'selected' : '' ?>>Sort by Date</option>
                        </select>
                    </div>
                    <!-- <div class="col-md-2">
                        <select name="dir" class="form-select form-select-sm">
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option>
                        </select>
                    </div> -->
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="currency_master.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Currencies Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="50">S.No</th>
                                <th width="100">Code</th>
                                <th>Currency Name</th>
                                <th width="80">Symbol</th>
                                <th width="120">Exchange Rate</th>
                                <th width="100">Status</th>
                                <th width="120">Created Date</th>
                                <th width="150" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($currencies && $currencies->num_rows > 0): ?>
                                <?php while($row = $currencies->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?= (int)$row['currency_id'] ?></td>
                                    <td>
                                        <span class="currency-code"><?= h($row['currency_code']) ?></span>
                                        <?php if ($row['auto_update']): ?>
                                        <span class="badge bg-info auto-update-badge" title="Auto-updates enabled">Auto</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= h($row['currency_name']) ?></span>
                                    </td>
                                    <td>
                                        <span class="currency-symbol"><?= h($row['currency_symbol']) ?></span>
                                    </td>
                                    <td>
                                        <span class="exchange-rate"><?= number_format($row['exchange_rate'], 4) ?></span>
                                    </td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="currency_id" value="<?= (int)$row['currency_id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $row['status'] === 'Active' ? 'btn-success' : 'btn-danger' ?>">
                                                <?= h($row['status']) ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= date('d-m-Y', strtotime($row['created_date'])) ?></small>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <!-- Edit Button -->
                                            <a href="currency_master.php?mode=edit&id=<?= (int)$row['currency_id'] ?>" 
                                               class="btn btn-warning" title="Edit Currency">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <!-- Delete Button -->
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete <?= h($row['currency_name']) ?>? This action cannot be undone.')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="currency_id" value="<?= (int)$row['currency_id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Currency">
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
                                            <i class="fas fa-money-bill-wave fa-3x mb-3"></i>
                                            <h5>No currencies found</h5>
                                            <p class="mb-0">Get started by adding your first currency.</p>
                                            <a href="currency_master.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Currency
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
        function validateForm() {
            let isValid = true;
            const currencyCode = document.getElementById('currency_code');
            const currencyName = document.getElementById('currency_name');
            const currencySymbol = document.getElementById('currency_symbol');
            const exchangeRate = document.getElementById('exchange_rate');
            
            // Reset validation states
            [currencyCode, currencyName, currencySymbol, exchangeRate].forEach(field => {
                field.classList.remove('is-invalid');
            });
            
            // Validate currency code
            if (currencyCode.value.trim() === '' || currencyCode.value.length !== 3) {
                currencyCode.classList.add('is-invalid');
                isValid = false;
            }
            
            // Validate currency name
            if (currencyName.value.trim() === '') {
                currencyName.classList.add('is-invalid');
                isValid = false;
            }
            
            // Validate currency symbol
            if (currencySymbol.value.trim() === '') {
                currencySymbol.classList.add('is-invalid');
                isValid = false;
            }
            
            // Validate exchange rate
            if (exchangeRate.value <= 0 || isNaN(exchangeRate.value)) {
                exchangeRate.classList.add('is-invalid');
                isValid = false;
            }
            
            if (isValid) {
                // Disable submit button to prevent double submission
                document.getElementById('submitBtn').disabled = true;
                document.getElementById('submitBtn').innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';
            }
            
            return isValid;
        }

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

            // Auto-submit form on filter changes
            const filterSelects = document.querySelectorAll('select[name="status"], select[name="sort"]');
            // select[name="dir"]
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });
            
            // Auto-uppercase currency code input
            const currencyCodeInput = document.getElementById('currency_code');
            if (currencyCodeInput) {
                currencyCodeInput.addEventListener('input', function(e) {
                    this.value = this.value.toUpperCase();
                    if (this.value.length === 3) {
                        this.classList.remove('is-invalid');
                    }
                });
            }
            
            // Real-time validation
            const formFields = document.querySelectorAll('#currencyForm input[required]');
            formFields.forEach(field => {
                field.addEventListener('blur', function() {
                    if (this.value.trim() === '') {
                        this.classList.add('is-invalid');
                    } else {
                        this.classList.remove('is-invalid');
                    }
                });
            });
            
            // Prevent form resubmission on page refresh
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        });
    </script>
</body>
</html>