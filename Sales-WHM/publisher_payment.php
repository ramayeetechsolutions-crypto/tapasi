<?php
require 'db.php'; 
ini_set('display_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// --- HELPER FUNCTIONS ---
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect_with($params = []) {
    $base = 'publisher_payment.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}
function parseExcelDate($val) {
    if(empty($val)) return NULL;
    $ts = strtotime($val);
    return ($ts !== false) ? date('Y-m-d', $ts) : NULL;
}

// Check if edit mode
$edit_mode = isset($_GET['edit']) ? true : false;
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$edit_data = null;

if ($edit_mode && $edit_id > 0) {
    $stmt = $mysqli->prepare("SELECT pp.*, cm.client_name, cm.country as cm_country, cm.frequency as cm_frequency 
                              FROM publisher_payments pp
                              LEFT JOIN client_master cm ON pp.client_id = cm.client_id
                              WHERE pp.id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_data = $result->fetch_assoc();
    $stmt->close();
    
    if (!$edit_data) {
        $edit_mode = false;
    }
}

// ---------------------------------------------------------
// HANDLE FORM SUBMISSION (Add/Edit Record)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['client_id'])) {
    $client_id = $mysqli->real_escape_string($_POST['client_id']);
    $month = $mysqli->real_escape_string($_POST['month']);
    $country = $mysqli->real_escape_string($_POST['country']);
    $no_of_journals = (int)$_POST['no_of_journals'];
    $frequency = $mysqli->real_escape_string($_POST['frequency']);
    $services = $mysqli->real_escape_string($_POST['services']);
    $amount = (float)$_POST['amount'];
    $total_amount = (float)$_POST['total_amount'];
    $tds = (float)$_POST['tds'];
    $ba = $mysqli->real_escape_string($_POST['ba']);
    $status = $mysqli->real_escape_string($_POST['status']);
    
    // Get client name from client_master
    $client_query = $mysqli->query("SELECT client_name FROM client_master WHERE client_id = '$client_id'");
    $client_data = $client_query->fetch_assoc();
    $publisher_name = $mysqli->real_escape_string($client_data['client_name']);
    
    if (isset($_POST['update_id']) && !empty($_POST['update_id'])) {
        // UPDATE existing record
        $update_id = (int)$_POST['update_id'];
        $sql = "UPDATE publisher_payments SET 
                client_id = '$client_id', 
                month = '$month', 
                publisher_name = '$publisher_name', 
                country = '$country', 
                no_of_journals = '$no_of_journals', 
                frequency = '$frequency', 
                services = '$services', 
                amount = '$amount', 
                total_amount = '$total_amount', 
                tds = '$tds', 
                ba = '$ba', 
                status = '$status', 
                updated_at = NOW() 
                WHERE id = '$update_id'";
        
        if($mysqli->query($sql)) {
            redirect_with(['msg' => 'Record updated successfully!', 'type' => 'success']);
        } else {
            redirect_with(['msg' => 'Error updating record: ' . $mysqli->error, 'type' => 'danger']);
        }
    } else {
        // INSERT new record
        $sql = "INSERT INTO publisher_payments 
                (client_id, month, publisher_name, country, no_of_journals, frequency, services, amount, total_amount, tds, ba, status, created_at, updated_at) 
                VALUES 
                ('$client_id', '$month', '$publisher_name', '$country', '$no_of_journals', '$frequency', '$services', '$amount', '$total_amount', '$tds', '$ba', '$status', NOW(), NOW())";
        
        if($mysqli->query($sql)) {
            redirect_with(['msg' => 'Record added successfully!', 'type' => 'success']);
        } else {
            redirect_with(['msg' => 'Error: ' . $mysqli->error, 'type' => 'danger']);
        }
    }
}

// ---------------------------------------------------------
// STRICT IMPORT LOGIC (Skips Unknown Clients)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        // --- Locate Library ---
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

        if (!file_exists($libPath)) { 
            redirect_with(['msg' => 'SimpleXLSX library not found. Please install it.', 'type' => 'danger']);
        }
        
        require_once $libPath;
        $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);

        if ($xlsx) {
            $rows = $xlsx->rows();
            
            // Debug Counters
            $count_added = 0;
            $count_skipped_dupe = 0;
            $count_skipped_unknown = 0;
            $errors = [];

            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header

                // Column 0: Month, Column 1: Publisher Name
                $month = trim($mysqli->real_escape_string($r[0] ?? ''));
                $client_name = trim($mysqli->real_escape_string($r[1] ?? '')); 
                
                if (empty($client_name) || empty($month)) {
                    $errors[] = "Row " . ($k + 1) . ": Publisher Name and Month are required";
                    continue;
                }

                // ---------------------------------------------------
                // 1. STRICT CHECK: DOES CLIENT EXIST?
                // ---------------------------------------------------
                $client_id = 0;
                // Check in client_master table
                $check = $mysqli->query("SELECT client_id FROM client_master WHERE TRIM(client_name) = '$client_name'");
                
                if ($check && $check->num_rows > 0) {
                    $row_data = $check->fetch_assoc();
                    $client_id = $row_data['client_id'];
                } else {
                    // *** REJECTION LOGIC ***
                    $count_skipped_unknown++;
                    $errors[] = "Row " . ($k + 1) . ": Skipped '$client_name' (Not found in Client Master)";
                    continue; 
                }

                // 2. PREPARE DATA - Adjusted column indexes
                $country = $mysqli->real_escape_string($r[2] ?? '');
                $no_of_journals = (int)($r[3] ?? 0);
                $frequency = $mysqli->real_escape_string($r[4] ?? '');
                $services = $mysqli->real_escape_string($r[5] ?? '');
                $amount = (float)($r[6] ?? 0);
                $total_amount = (float)($r[7] ?? 0);
                $tds = (float)($r[8] ?? 0);
                $ba = $mysqli->real_escape_string($r[9] ?? '');
                $status = $mysqli->real_escape_string($r[10] ?? '');

                // 3. DUPLICATE CHECK (Prevent double entry)
                $dupCheck = $mysqli->query("SELECT id FROM publisher_payments 
                    WHERE client_id = '$client_id' 
                    AND month = '$month' 
                    AND publisher_name = '$client_name' 
                    AND services = '$services' 
                    LIMIT 1");
                
                if ($dupCheck && $dupCheck->num_rows > 0) {
                    $count_skipped_dupe++;
                    $errors[] = "Row " . ($k + 1) . ": Duplicate entry for '$client_name' in '$month'";
                    continue;
                }

                // 4. INSERT INTO PUBLISHER PAYMENTS
                $sql = "INSERT INTO publisher_payments 
                (client_id, month, publisher_name, country, no_of_journals, frequency, services, amount, total_amount, tds, ba, status, created_at, updated_at) 
                VALUES 
                ('$client_id', '$month', '$client_name', '$country', '$no_of_journals', '$frequency', '$services', '$amount', '$total_amount', '$tds', '$ba', '$status', NOW(), NOW())";
                
                if($mysqli->query($sql)) {
                    $count_added++;
                } else {
                    $errors[] = "Row " . ($k + 1) . ": Database error - " . $mysqli->error;
                }
            }
            
            // Prepare result message
            $message = "Import completed: $count_added records added successfully. ";
            if ($count_skipped_dupe > 0) {
                $message .= "$count_skipped_dupe duplicate records skipped. ";
            }
            if ($count_skipped_unknown > 0) {
                $message .= "$count_skipped_unknown records skipped (client not found). ";
            }
            
            if (!empty($errors)) {
                $message .= "Errors: " . implode("; ", array_slice($errors, 0, 5));
                if (count($errors) > 5) {
                    $message .= "... (and " . (count($errors) - 5) . " more)";
                }
                redirect_with(['msg' => $message, 'type' => 'warning']);
            } else {
                redirect_with(['msg' => $message, 'type' => 'success']);
            }
            
        } else {
            redirect_with(['msg' => 'Failed to parse Excel file.', 'type' => 'danger']);
        }
    } else {
        $upload_error = $_FILES['excel_file']['error'] ?? 0;
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit.',
            UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
        ];
        
        $error_msg = $error_messages[$upload_error] ?? 'Please select a file to upload.';
        redirect_with(['msg' => $error_msg, 'type' => 'danger']);
    }
}

// ---------------------------------------------------------
// DELETE RECORD
// ---------------------------------------------------------
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $delete_id = (int)$_GET['id'];
    $sql = "DELETE FROM publisher_payments WHERE id = '$delete_id'";
    if($mysqli->query($sql)) {
        redirect_with(['msg' => 'Record deleted successfully!', 'type' => 'warning']);
    } else {
        redirect_with(['msg' => 'Error deleting record: ' . $mysqli->error, 'type' => 'danger']);
    }
}

// ---------------------------------------------------------
// FETCH CLIENTS FOR DROPDOWN
// ---------------------------------------------------------
$clients_result = $mysqli->query("SELECT client_id, client_name, country, frequency FROM client_master ORDER BY client_name");
$clients = [];
if ($clients_result && $clients_result->num_rows > 0) {
    while($row = $clients_result->fetch_assoc()) {
        $clients[] = $row;
    }
}

// ---------------------------------------------------------
// FETCH PUBLISHER PAYMENTS DATA
// ---------------------------------------------------------
$sql = "SELECT pp.*, cm.country as client_country, cm.frequency as client_frequency 
        FROM publisher_payments pp
        LEFT JOIN client_master cm ON pp.client_id = cm.client_id
        ORDER BY pp.id DESC";
$result = $mysqli->query($sql);

require 'header.php'; 
?>

<div class="container-fluid py-4" style="margin-top: 60px;">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-0"><i class="fas fa-money-bill-wave me-2"></i>Publisher Payments</h3>
            <p class="text-muted">Manage publisher payments and track financial records</p>
        </div>
        <div>
            <?php if($edit_mode): ?>
                <a href="publisher_payment.php" class="btn btn-secondary me-2">
                    <i class="fas fa-arrow-left me-1"></i> Back to List
                </a>
            <?php endif; ?>
            <button type="button" class="btn btn-danger fw-bold" data-bs-toggle="modal" data-bs-target="#importModal">
                <i class="fas fa-file-excel me-2"></i> Import Excel
            </button>
            <a href="download_publisher_template.php" class="btn btn-success fw-bold ms-2">
                <i class="fas fa-download me-2"></i> Download Template
            </a>
        </div>
    </div>

    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
        <?= h($_GET['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ADD/EDIT NEW RECORD FORM -->
    <div class="card shadow border-0 mb-4">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0">
                <i class="fas <?= $edit_mode ? 'fa-edit' : 'fa-plus-circle' ?> me-2"></i>
                <?= $edit_mode ? 'Edit Publisher Payment' : 'Add New Publisher Payment' ?>
            </h5>
        </div>
        <div class="card-body">
            <form action="" method="POST" id="publisherForm">
                <?php if($edit_mode): ?>
                    <input type="hidden" name="update_id" value="<?= $edit_id ?>">
                <?php endif; ?>
                
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Month *</label>
                        <select name="month" class="form-select" required>
                            <option value="">Select Month</option>
                            <?php 
                            $months = ['January', 'February', 'March', 'April', 'May', 'June', 
                                      'July', 'August', 'September', 'October', 'November', 'December'];
                            foreach($months as $m): 
                            ?>
                                <option value="<?= $m ?>" <?= ($edit_mode && $edit_data['month'] == $m) ? 'selected' : '' ?>>
                                    <?= $m ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Publisher Name *</label>
                        <select name="client_id" class="form-select" id="clientSelect" required onchange="fetchClientDetails(this.value)" <?= $edit_mode ? 'disabled' : '' ?>>
                            <option value="">Select Client</option>
                            <?php foreach($clients as $client): ?>
                            <option value="<?= h($client['client_id']) ?>" 
                                data-country="<?= h($client['country']) ?>" 
                                data-frequency="<?= h($client['frequency']) ?>"
                                <?= ($edit_mode && $edit_data['client_id'] == $client['client_id']) ? 'selected' : '' ?>>
                                <?= h($client['client_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if($edit_mode): ?>
                            <input type="hidden" name="client_id" value="<?= $edit_data['client_id'] ?>">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Country *</label>
                        <input type="text" name="country" class="form-control" id="countryField" 
                               value="<?= $edit_mode ? h($edit_data['country']) : '' ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">No. of Journals</label>
                        <input type="number" name="no_of_journals" class="form-control" min="0" 
                               value="<?= $edit_mode ? h($edit_data['no_of_journals']) : '0' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Frequency *</label>
                        <select name="frequency" class="form-select" id="frequencyField" required>
                            <option value="">Select Frequency</option>
                            <option value="Monthly" <?= ($edit_mode && $edit_data['frequency'] == 'Monthly') ? 'selected' : '' ?>>Monthly</option>
                            <option value="Quarterly" <?= ($edit_mode && $edit_data['frequency'] == 'Quarterly') ? 'selected' : '' ?>>Quarterly</option>
                            <option value="Half-Yearly" <?= ($edit_mode && $edit_data['frequency'] == 'Half-Yearly') ? 'selected' : '' ?>>Half-Yearly</option>
                            <option value="Yearly" <?= ($edit_mode && $edit_data['frequency'] == 'Yearly') ? 'selected' : '' ?>>Yearly</option>
                            <option value="One-Time" <?= ($edit_mode && $edit_data['frequency'] == 'One-Time') ? 'selected' : '' ?>>One-Time</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Services</label>
                        <select name="services" class="form-select">
                            <option value="">Select Service</option>
                            <option value="Editing" <?= ($edit_mode && $edit_data['services'] == 'Editing') ? 'selected' : '' ?>>Editing</option>
                            <option value="Proofreading" <?= ($edit_mode && $edit_data['services'] == 'Proofreading') ? 'selected' : '' ?>>Proofreading</option>
                            <option value="Typesetting" <?= ($edit_mode && $edit_data['services'] == 'Typesetting') ? 'selected' : '' ?>>Typesetting</option>
                            <option value="XML" <?= ($edit_mode && $edit_data['services'] == 'XML') ? 'selected' : '' ?>>XML</option>
                            <option value="Complete Package" <?= ($edit_mode && $edit_data['services'] == 'Complete Package') ? 'selected' : '' ?>>Complete Package</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Amount *</label>
                        <input type="number" step="0.01" name="amount" class="form-control" 
                               value="<?= $edit_mode ? h($edit_data['amount']) : '0.00' ?>" required id="amountField">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Total Amount *</label>
                        <input type="number" step="0.01" name="total_amount" class="form-control" 
                               value="<?= $edit_mode ? h($edit_data['total_amount']) : '0.00' ?>" required id="totalAmountField" readonly>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">TDS (%)</label>
                        <input type="number" step="0.01" name="tds" class="form-control" 
                               value="<?= $edit_mode ? h($edit_data['tds']) : '0.00' ?>" id="tdsField">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">BA</label>
                        <input type="text" name="ba" class="form-control" placeholder="Bank Account/Reference"
                               value="<?= $edit_mode ? h($edit_data['ba']) : '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Status *</label>
                        <select name="status" class="form-select" required>
                            <option value="Pending" <?= ($edit_mode && $edit_data['status'] == 'Pending') ? 'selected' : '' ?>>Pending</option>
                            <option value="Paid" <?= ($edit_mode && $edit_data['status'] == 'Paid') ? 'selected' : '' ?>>Paid</option>
                            <option value="Partially Paid" <?= ($edit_mode && $edit_data['status'] == 'Partially Paid') ? 'selected' : '' ?>>Partially Paid</option>
                            <option value="Overdue" <?= ($edit_mode && $edit_data['status'] == 'Overdue') ? 'selected' : '' ?>>Overdue</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-2"></i>
                            <?= $edit_mode ? 'Update Record' : 'Save Record' ?>
                        </button>
                        <?php if($edit_mode): ?>
                            <a href="publisher_payment.php" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i> Cancel
                            </a>
                        <?php else: ?>
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-redo me-2"></i> Reset
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- PUBLISHER PAYMENTS TABLE -->
    <div class="card shadow border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-bordered mb-0 align-middle text-center" style="font-size: 0.85rem;">
                    <thead class="bg-dark text-white">
                        <tr>
                            <th>ID</th>
                            <th>Month</th>
                            <th>Publisher Name</th>
                            <th>Country</th>
                            <th>Journals</th>
                            <th>Frequency</th>
                            <th>Services</th>
                            <th>Amount</th>
                            <th>Total Amount</th>
                            <th>TDS</th>
                            <th>BA</th>
                            <th>Status</th>
                            <th>Created Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?= h($row['id']) ?></td>
                                <td><?= h($row['month']) ?></td>
                                <td class="fw-bold text-start">
                                    <?= h($row['publisher_name']) ?>
                                    <?php if($row['client_id'] > 0): ?>
                                        <i class="fas fa-link text-success ms-1 small"></i>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($row['country'] ?? $row['client_country']) ?></td>
                                <td><?= h($row['no_of_journals']) ?></td>
                                <td><?= h($row['frequency'] ?? $row['client_frequency']) ?></td>
                                <td><?= h($row['services']) ?></td>
                                <td class="fw-bold text-primary">₹ <?= number_format((float)$row['amount'], 2) ?></td>
                                <td class="fw-bold text-success">₹ <?= number_format((float)$row['total_amount'], 2) ?></td>
                                <td class="text-danger"><?= number_format((float)$row['tds'], 2) ?>%</td>
                                <td><?= h($row['ba']) ?></td>
                                <td>
                                    <span class="badge bg-<?= 
                                        $row['status'] == 'Paid' ? 'success' : 
                                        ($row['status'] == 'Pending' ? 'warning' : 
                                        ($row['status'] == 'Overdue' ? 'danger' : 
                                        ($row['status'] == 'Partially Paid' ? 'info' : 'secondary'))) 
                                    ?>">
                                        <?= h($row['status']) ?>
                                    </span>
                                </td>
                                <td><?= date('d-m-Y', strtotime($row['created_at'])) ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="publisher_payment.php?edit=1&id=<?= $row['id'] ?>" 
                                           class="btn btn-outline-primary" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="publisher_payment.php?delete=1&id=<?= $row['id'] ?>" 
                                           class="btn btn-outline-danger" 
                                           onclick="return confirm('Are you sure you want to delete this record?')" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="14" class="text-center py-5 text-muted">
                                    <i class="fas fa-money-bill-wave fa-2x mb-3"></i><br>
                                    No publisher payments records found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- IMPORT MODAL -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-file-import me-2"></i>Import Publisher Payments</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <form action="" method="post" enctype="multipart/form-data" id="importForm">
                <input type="hidden" name="action" value="import_excel">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Excel File (.xlsx or .xls) *</label>
                        <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls" required id="excelFile">
                    </div>
                    
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i>Excel Format Requirements:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead>
                                    <tr class="bg-light">
                                        <th>Column</th>
                                        <th>Field</th>
                                        <th>Example</th>
                                        <th>Required</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>A</td>
                                        <td><strong>Month</strong></td>
                                        <td>January, February, etc.</td>
                                        <td><span class="badge bg-danger">Yes</span></td>
                                    </tr>
                                    <tr>
                                        <td>B</td>
                                        <td><strong>Publisher Name</strong></td>
                                        <td>Client Name (must exist in Client Master)</td>
                                        <td><span class="badge bg-danger">Yes</span></td>
                                    </tr>
                                    <tr>
                                        <td>C</td>
                                        <td><strong>Country</strong></td>
                                        <td>USA, UK, India, etc.</td>
                                        <td><span class="badge bg-warning">No</span></td>
                                    </tr>
                                    <tr>
                                        <td>D</td>
                                        <td><strong>No. of Journals</strong></td>
                                        <td>10, 25, 50</td>
                                        <td><span class="badge bg-warning">No</span></td>
                                    </tr>
                                    <tr>
                                        <td>E</td>
                                        <td><strong>Frequency</strong></td>
                                        <td>Monthly, Quarterly, Yearly</td>
                                        <td><span class="badge bg-warning">No</span></td>
                                    </tr>
                                    <tr>
                                        <td>F</td>
                                        <td><strong>Services</strong></td>
                                        <td>Editing, Proofreading, XML</td>
                                        <td><span class="badge bg-warning">No</span></td>
                                    </tr>
                                    <tr>
                                        <td>G</td>
                                        <td><strong>Amount</strong></td>
                                        <td>1000.00, 2500.50</td>
                                        <td><span class="badge bg-danger">Yes</span></td>
                                    </tr>
                                    <tr>
                                        <td>H</td>
                                        <td><strong>Total Amount</strong></td>
                                        <td>950.00 (after TDS)</td>
                                        <td><span class="badge bg-danger">Yes</span></td>
                                    </tr>
                                    <tr>
                                        <td>I</td>
                                        <td><strong>TDS (%)</strong></td>
                                        <td>5.00, 10.00</td>
                                        <td><span class="badge bg-warning">No</span></td>
                                    </tr>
                                    <tr>
                                        <td>J</td>
                                        <td><strong>BA</strong></td>
                                        <td>Bank Account/Reference</td>
                                        <td><span class="badge bg-warning">No</span></td>
                                    </tr>
                                    <tr>
                                        <td>K</td>
                                        <td><strong>Status</strong></td>
                                        <td>Pending, Paid, Overdue</td>
                                        <td><span class="badge bg-warning">No</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="mb-0"><strong>Note:</strong> Rows with unknown clients will be skipped automatically.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info" id="importBtn">
                        <i class="fas fa-upload me-1"></i> Import Data
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
function fetchClientDetails(clientId) {
    if (!clientId) return;
    
    // Get selected option data
    const select = document.getElementById('clientSelect');
    const selectedOption = select.options[select.selectedIndex];
    
    // Populate fields
    document.getElementById('countryField').value = selectedOption.getAttribute('data-country') || '';
    
    // Set frequency dropdown
    const frequencyField = document.getElementById('frequencyField');
    const clientFrequency = selectedOption.getAttribute('data-frequency') || '';
    
    // Find and select the matching option
    for (let i = 0; i < frequencyField.options.length; i++) {
        if (frequencyField.options[i].value === clientFrequency) {
            frequencyField.selectedIndex = i;
            break;
        }
    }
}

// Calculate total amount based on amount and TDS
function calculateTotalAmount() {
    const amountField = document.getElementById('amountField');
    const tdsField = document.getElementById('tdsField');
    const totalAmountField = document.getElementById('totalAmountField');
    
    if (!amountField || !tdsField || !totalAmountField) return;
    
    const amount = parseFloat(amountField.value) || 0;
    const tds = parseFloat(tdsField.value) || 0;
    const tdsAmount = (amount * tds) / 100;
    const total = amount - tdsAmount;
    totalAmountField.value = total.toFixed(2);
}

// Initialize when page loads
document.addEventListener("DOMContentLoaded", function() {
    // Auto-dismiss alerts
    var alertBox = document.querySelector('.alert');
    if (alertBox) {
        setTimeout(function() {
            var alert = new bootstrap.Alert(alertBox);
            alert.close();
        }, 5000);
    }
    
    // If in edit mode, fetch client details
    <?php if($edit_mode && $edit_data): ?>
        fetchClientDetails(<?= $edit_data['client_id'] ?>);
    <?php endif; ?>
    
    // Attach event listeners for amount calculation
    const amountField = document.getElementById('amountField');
    const tdsField = document.getElementById('tdsField');
    
    if (amountField && tdsField) {
        amountField.addEventListener('input', calculateTotalAmount);
        tdsField.addEventListener('input', calculateTotalAmount);
    }
    
    // Calculate initial total if in edit mode
    <?php if($edit_mode): ?>
        calculateTotalAmount();
    <?php endif; ?>
    
    // Form validation
    document.getElementById('publisherForm').addEventListener('submit', function(e) {
        const clientId = document.getElementById('clientSelect').value;
        const month = document.querySelector('select[name="month"]').value;
        
        if (!clientId) {
            e.preventDefault();
            alert('Please select a publisher/client');
            return false;
        }
        
        if (!month) {
            e.preventDefault();
            alert('Please select a month');
            return false;
        }
    });
    
    // Import form validation
    document.getElementById('importForm').addEventListener('submit', function(e) {
        const fileInput = document.getElementById('excelFile');
        const importBtn = document.getElementById('importBtn');
        
        if (!fileInput.files.length) {
            e.preventDefault();
            alert('Please select an Excel file to upload');
            return false;
        }
        
        // Check file extension
        const fileName = fileInput.value.toLowerCase();
        if (!fileName.endsWith('.xlsx') && !fileName.endsWith('.xls')) {
            e.preventDefault();
            alert('Please select an Excel file (.xlsx or .xls)');
            return false;
        }
        
        // Show loading state
        importBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Processing...';
        importBtn.disabled = true;
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>