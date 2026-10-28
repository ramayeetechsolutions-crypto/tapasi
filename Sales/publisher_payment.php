--- START OF FILE Paste April 01, 2026 - 12:33PM ---

<?php
require 'db.php'; 
ini_set('display_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// --- HELPER FUNCTIONS ---
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect_with($params =[]) {
    $base = 'publisher_payment.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Check Modes
$add_mode = isset($_GET['add']) ? true : false;
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
// HANDLE IMPORT LOGIC (XLSX Format)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

        if (!file_exists($libPath)) { 
            redirect_with(['msg' => 'Library file not found.', 'type' => 'danger']);
        }
        
        require_once $libPath;
        $xlsx = null;
        if (class_exists('Shuchkin\SimpleXLSX')) {
            $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        } elseif (class_exists('SimpleXLSX')) {
            $xlsx = SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        }

        if ($xlsx) {
            $rows = $xlsx->rows();
            $count_added = 0;
            $count_skipped = 0;
            
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header

                $month = trim($mysqli->real_escape_string($r[0] ?? ''));
                $year = (int)($r[1] ?? date('Y'));
                $publisher_name = trim($r[2] ?? '');
                $journal_name = trim($mysqli->real_escape_string($r[3] ?? ''));
                
                if (empty($publisher_name) || empty($month)) {
                    $count_skipped++;
                    continue;
                }

                // 1. Find Publisher ID
                $client_id = 0;
                $country = trim($mysqli->real_escape_string($r[4] ?? ''));
                $check_stmt = $mysqli->prepare("SELECT client_id, country FROM client_master WHERE client_name = ? LIMIT 1");
                $check_stmt->bind_param("s", $publisher_name);
                $check_stmt->execute();
                $c_res = $check_stmt->get_result();
                if ($c_res->num_rows > 0) {
                    $c_row = $c_res->fetch_assoc();
                    $client_id = $c_row['client_id'];
                    if (empty($country)) $country = $mysqli->real_escape_string($c_row['country']);
                }
                $check_stmt->close();

                if ($client_id == 0) {
                    $count_skipped++;
                    continue; // Skip if publisher doesn't exist in Master
                }

                $no_of_journals = (int)($r[5] ?? 0);
                $frequency = trim($mysqli->real_escape_string($r[6] ?? ''));
                $services = trim($mysqli->real_escape_string($r[7] ?? ''));
                $amount = (float)($r[8] ?? 0);
                $total_amount = (float)($r[9] ?? 0);
                $employee_username = trim($mysqli->real_escape_string($r[10] ?? ''));
                $employee_name = trim($mysqli->real_escape_string($r[11] ?? ''));
                $status = trim($mysqli->real_escape_string($r[12] ?? 'Pending'));

                $pub_esc = $mysqli->real_escape_string($publisher_name);

                $sql = "INSERT INTO publisher_payments 
                        (client_id, month, year, publisher_name, journal_name, country, no_of_journals, frequency, services, amount, total_amount, employee_username, employee_name, status, created_at, updated_at) 
                        VALUES 
                        ('$client_id', '$month', '$year', '$pub_esc', '$journal_name', '$country', '$no_of_journals', '$frequency', '$services', '$amount', '$total_amount', '$employee_username', '$employee_name', '$status', NOW(), NOW())";
                
                if($mysqli->query($sql)) {
                    $count_added++;
                } else {
                    $count_skipped++;
                }
            }
            
            $msg = "Import Complete. Added: $count_added. Skipped: $count_skipped (Publishers not found in Master).";
            redirect_with(['msg' => $msg, 'type' => ($count_added > 0 ? 'success' : 'warning')]);
        } else {
            redirect_with(['msg' => 'Error parsing Excel file.', 'type' => 'danger']);
        }
    } else {
        redirect_with(['msg' => 'No file uploaded.', 'type' => 'danger']);
    }
}

// ---------------------------------------------------------
// HANDLE FORM SUBMISSION (Add/Edit Record)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['client_id'])) {
    $client_id = $mysqli->real_escape_string($_POST['client_id']);
    $month = $mysqli->real_escape_string($_POST['month']);
    $year = (int)$_POST['year'];
    $journal_name = $mysqli->real_escape_string($_POST['journal_name']);
    $country = $mysqli->real_escape_string($_POST['country']);
    $no_of_journals = (int)$_POST['no_of_journals'];
    $frequency = $mysqli->real_escape_string($_POST['frequency']);
    $services = $mysqli->real_escape_string($_POST['services']);
    $amount = (float)$_POST['amount'];
    $total_amount = (float)$_POST['total_amount'];
    $employee_username = $mysqli->real_escape_string($_POST['employee_username']);
    $employee_name = $mysqli->real_escape_string($_POST['employee_name']);
    $status = $mysqli->real_escape_string($_POST['status']);
    
    // Get client name from client_master
    $client_query = $mysqli->query("SELECT client_name FROM client_master WHERE client_id = '$client_id'");
    $client_data = $client_query->fetch_assoc();
    $publisher_name = $mysqli->real_escape_string($client_data['client_name']);
    
    if (isset($_POST['update_id']) && !empty($_POST['update_id'])) {
        $update_id = (int)$_POST['update_id'];
        $sql = "UPDATE publisher_payments SET 
                client_id = '$client_id', month = '$month', year = '$year', publisher_name = '$publisher_name', journal_name = '$journal_name', country = '$country', 
                no_of_journals = '$no_of_journals', frequency = '$frequency', services = '$services', amount = '$amount', 
                total_amount = '$total_amount', employee_username = '$employee_username', employee_name = '$employee_name', 
                status = '$status', updated_at = NOW() WHERE id = '$update_id'";
        
        if($mysqli->query($sql)) {
            redirect_with(['msg' => 'Record updated successfully!', 'type' => 'success']);
        } else {
            redirect_with(['msg' => 'Error updating record: ' . $mysqli->error, 'type' => 'danger']);
        }
    } else {
        $sql = "INSERT INTO publisher_payments 
                (client_id, month, year, publisher_name, journal_name, country, no_of_journals, frequency, services, amount, total_amount, employee_username, employee_name, status, created_at, updated_at) 
                VALUES 
                ('$client_id', '$month', '$year', '$publisher_name', '$journal_name', '$country', '$no_of_journals', '$frequency', '$services', '$amount', '$total_amount', '$employee_username', '$employee_name', '$status', NOW(), NOW())";
        
        if($mysqli->query($sql)) {
            redirect_with(['msg' => 'Record added successfully!', 'type' => 'success']);
        } else {
            redirect_with(['msg' => 'Error: ' . $mysqli->error, 'type' => 'danger']);
        }
    }
}

// ---------------------------------------------------------
// HANDLE DELETE RECORD
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $delete_id = (int)$_POST['id'];
    if ($delete_id > 0) {
        $sql = "DELETE FROM publisher_payments WHERE id = $delete_id";
        if($mysqli->query($sql)) {
            redirect_with(['msg' => 'Record deleted successfully!', 'type' => 'warning']);
        } else {
            redirect_with(['msg' => 'Error deleting record: ' . $mysqli->error, 'type' => 'danger']);
        }
    }
}

// ---------------------------------------------------------
// FETCH DATA FOR DROPDOWNS & AUTO-CALCULATIONS
// ---------------------------------------------------------
// Clients
$clients_result = $mysqli->query("SELECT client_id, client_name, country, frequency FROM client_master ORDER BY client_name");
$clients =[];
if ($clients_result && $clients_result->num_rows > 0) {
    while($row = $clients_result->fetch_assoc()) {
        $clients[] = $row;
    }
}

// Employees
$employees_result = $mysqli->query("SELECT employee_username, employee_name FROM employee_master WHERE team_id = '9' ORDER BY employee_name");
$employees =[];
if ($employees_result && $employees_result->num_rows > 0) {
    while($row = $employees_result->fetch_assoc()) {
        $employees[] = $row;
    }
}

// Services (From Job Master)
$jobs_result = $mysqli->query("SELECT `job_id`, `job_name`, `job_description`, `rate`, `created_by`, `created_date`, `status` FROM `job_master` WHERE 1 ORDER BY `job_name`");
$jobs =[];
if ($jobs_result && $jobs_result->num_rows > 0) {
    while($row = $jobs_result->fetch_assoc()) {
        $jobs[] = $row;
    }
}

// Sales Calculations Rates for Auto-populating Amount Field
$sales_calc_result = $mysqli->query("SELECT service_identifier, amt_monthly, amt_bimonthly, amt_quarterly, amt_biannually, amt_triannual, amt_annually FROM sales_calculations WHERE 1");
$sales_rates =[];
if ($sales_calc_result && $sales_calc_result->num_rows > 0) {
    while($row = $sales_calc_result->fetch_assoc()) {
        $sales_rates[$row['service_identifier']] =[
            'Monthly' => $row['amt_monthly'],
            'Bi-Monthly' => $row['amt_bimonthly'],
            'Quarterly' => $row['amt_quarterly'],
            'Tri-Annual' => $row['amt_triannual'],
            'Half-Yearly' => $row['amt_biannually'], 
            'Yearly' => $row['amt_annually']         
        ];
    }
}

// ---------------------------------------------------------
// BUILD SEARCH QUERY & FETCH DATA FOR GRID
// ---------------------------------------------------------
$searchQuery  = trim($_GET['q'] ?? '');
$clientFilter = $_GET['client_filter'] ?? '';
$empFilter    = $_GET['emp_filter'] ?? '';
$sortBy       = $_GET['sort_by'] ?? 'id_desc';

$whereClause = "1=1";

// 1. Keyword Search
if ($searchQuery !== '') {
    $q_esc = $mysqli->real_escape_string($searchQuery);
    $whereClause .= " AND (
        pp.publisher_name LIKE '%$q_esc%' OR 
        pp.journal_name LIKE '%$q_esc%' OR 
        pp.month LIKE '%$q_esc%' OR 
        pp.year LIKE '%$q_esc%' OR 
        pp.country LIKE '%$q_esc%' OR 
        cm.country LIKE '%$q_esc%' OR 
        pp.services LIKE '%$q_esc%' OR 
        pp.employee_name LIKE '%$q_esc%' OR 
        pp.status LIKE '%$q_esc%'
    )";
}

// 2. Client Filter
if ($clientFilter !== '') {
    $cf_esc = $mysqli->real_escape_string($clientFilter);
    $whereClause .= " AND pp.client_id = '$cf_esc'";
}

// 3. Employee Filter
if ($empFilter !== '') {
    $ef_esc = $mysqli->real_escape_string($empFilter);
    $whereClause .= " AND pp.employee_username = '$ef_esc'";
}

// 4. Sort Order
$orderBy = "pp.id DESC"; // Default
if ($sortBy === 'client_asc') {
    $orderBy = "pp.publisher_name ASC, pp.id DESC";
} elseif ($sortBy === 'emp_asc') {
    $orderBy = "pp.employee_name ASC, pp.id DESC";
}

$sql = "SELECT pp.*, cm.country as client_country, cm.frequency as client_frequency 
        FROM publisher_payments pp
        LEFT JOIN client_master cm ON pp.client_id = cm.client_id
        WHERE $whereClause
        ORDER BY $orderBy";
$result = $mysqli->query($sql);

require 'header.php'; 
?>

<!-- Custom UI Styling -->
<style>
    body { background-color: #f4f6f9; font-family: 'Inter', sans-serif; }
    .card { border-radius: 12px; }
    .card-header { border-top-left-radius: 12px !important; border-top-right-radius: 12px !important; }
    .form-label { font-size: 0.85rem; color: #555; text-transform: uppercase; letter-spacing: 0.5px; }
    .table th { background-color: #6c757d !important; color: #fff; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; }
    .table td { vertical-align: middle; font-size: 0.9rem; }
    .btn-rounded { border-radius: 50px; }
    .icon-box { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 10px; background: rgba(52, 152, 219, 0.1); color: #2980b9; margin-right: 15px; }
    .badge-custom { padding: 6px 12px; font-weight: 600; font-size: 0.75rem; border-radius: 30px; }
</style>

<div class="container-fluid py-4" style="margin-top: 60px;">
    
    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 bg-white p-4 shadow-sm rounded-4">
        <div class="d-flex align-items-center">
            <div class="icon-box">
                <i class="fas fa-money-check-alt fa-lg"></i>
            </div>
            <div>
                <h4 class="fw-bold text-dark mb-1">Sales OT</h4>
                <p class="text-muted mb-0 small">Manage financial records & automated sales integrations</p>
            </div>
        </div>
        <div class="mt-3 mt-md-0 d-flex gap-2">
            <?php if($edit_mode || $add_mode): ?>
                <a href="publisher_payment.php" class="btn btn-secondary btn-rounded shadow-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back to List
                </a>
            <?php else: ?>
                <button type="button" class="btn btn-warning btn shadow-sm fw-bold text-dark" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-excel me-1"></i> Import Excel
                </button>
                <a href="publisher_payment.php?add=1" class="btn btn-primary btn shadow-sm">
                    <i class="fas fa-plus-circle me-1"></i> Add Record
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show shadow-sm rounded-3" role="alert">
        <i class="fas <?= ($_GET['type'] == 'success') ? 'fa-check-circle' : (($_GET['type'] == 'warning') ? 'fa-info-circle' : 'fa-exclamation-circle') ?> me-2"></i>
        <?= h($_GET['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if($edit_mode || $add_mode): ?>
    <!-- ADD/EDIT FORM (Hidden unless Add/Edit is clicked) -->
    <div class="card shadow-sm border-0 mb-4 animate__animated animate__fadeIn">
        <div class="card-header bg-primary text-white py-3">
            <h6 class="mb-0 fw-bold">
                <i class="fas <?= $edit_mode ? 'fa-edit' : 'fa-plus-circle' ?> me-2"></i>
                <?= $edit_mode ? 'Edit Publisher Payment' : 'Record New Publisher Payment' ?>
            </h6>
        </div>
        <div class="card-body p-4 bg-white">
            <form action="" method="POST" id="publisherForm">
                <?php if($edit_mode): ?>
                    <input type="hidden" name="update_id" value="<?= $edit_id ?>">
                <?php endif; ?>
                
                <div class="row g-4">
                    <!-- Column 1 -->
                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <label class="form-label fw-bold">Month *</label>
                        <select name="month" class="form-select shadow-none" required>
                            <option value="">Select Month</option>
                            <?php 
                            $months =['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                            foreach($months as $m): ?>
                                <option value="<?= $m ?>" <?= ($edit_mode && $edit_data['month'] == $m) ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <label class="form-label fw-bold">Year *</label>
                        <select name="year" class="form-select shadow-none" required>
                            <option value="">Select Year</option>
                            <?php 
                            $current_year = date('Y');
                            for($y = $current_year - 5; $y <= $current_year + 5; $y++): ?>
                                <option value="<?= $y ?>" 
                                    <?= ($edit_mode && isset($edit_data['year']) && $edit_data['year'] == $y) ? 'selected' : 
                                        (!$edit_mode && $y == $current_year ? 'selected' : '') ?>>
                                    <?= $y ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <label class="form-label fw-bold">Publisher Name *</label>
                        <select name="client_id" class="form-select shadow-none" id="clientSelect" required onchange="fetchClientDetails(this.value)" <?= $edit_mode ? 'disabled' : '' ?>>
                            <option value="">Select Publisher</option>
                            <?php foreach($clients as $client): ?>
                            <option value="<?= h($client['client_id']) ?>" data-country="<?= h($client['country']) ?>" data-frequency="<?= h($client['frequency']) ?>"
                                <?= ($edit_mode && $edit_data['client_id'] == $client['client_id']) ? 'selected' : '' ?>>
                                <?= h($client['client_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if($edit_mode): ?><input type="hidden" name="client_id" value="<?= $edit_data['client_id'] ?>"><?php endif; ?>
                    </div>

                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <label class="form-label fw-bold">Journal Name</label>
                        <input type="text" name="journal_name" class="form-control shadow-none" value="<?= $edit_mode ? h($edit_data['journal_name'] ?? '') : '' ?>">
                    </div>

                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <label class="form-label fw-bold">Country *</label>
                        <input type="text" name="country" class="form-control shadow-none" id="countryField" value="<?= $edit_mode ? h($edit_data['country']) : '' ?>" required>
                    </div>

                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <label class="form-label fw-bold">No. of Journals</label>
                        <input type="number" name="no_of_journals" id="journalsField" class="form-control shadow-none" min="0" value="<?= $edit_mode ? h($edit_data['no_of_journals']) : '0' ?>">
                    </div>

                    <!-- Services/Job Link to Sales Calc -->
                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <label class="form-label fw-bold text-primary">Service (Job) *</label>
                        <select name="services" class="form-select shadow-none border-primary bg-light" id="servicesField" required>
                            <option value="">Select Service / Job</option>
                            <?php foreach($jobs as $job): ?>
                            <option value="<?= h($job['job_name']) ?>" data-id="<?= h($job['job_id']) ?>" data-rate="<?= h($job['rate']) ?>" <?= ($edit_mode && $edit_data['services'] == $job['job_name']) ? 'selected' : '' ?>>
                                <?= h($job['job_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Amount field moved AFTER Services -->
                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <label class="form-label fw-bold">Amount *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light text-muted border-end-0"><i class="fas fa-rupee-sign"></i></span>
                            <input type="number" step="0.01" name="amount" class="form-control shadow-none bg-light border-start-0" 
                               value="<?= $edit_mode ? h($edit_data['amount']) : '0.00' ?>" required id="amountField" readonly>
                        </div>
                    </div>

                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <label class="form-label fw-bold text-primary">Frequency *</label>
                        <select name="frequency" class="form-select shadow-none border-primary bg-light" id="frequencyField" required>
                            <option value="">Select Frequency</option>
                            <option value="Monthly" <?= ($edit_mode && $edit_data['frequency'] == 'Monthly') ? 'selected' : '' ?>>Monthly</option>
                            <option value="Bi-Monthly" <?= ($edit_mode && $edit_data['frequency'] == 'Bi-Monthly') ? 'selected' : '' ?>>Bi-Monthly</option>
                            <option value="Quarterly" <?= ($edit_mode && $edit_data['frequency'] == 'Quarterly') ? 'selected' : '' ?>>Quarterly</option>
                            <option value="Tri-Annual" <?= ($edit_mode && $edit_data['frequency'] == 'Tri-Annual') ? 'selected' : '' ?>>Tri-Annual</option>
                            <option value="Half-Yearly" <?= ($edit_mode && $edit_data['frequency'] == 'Half-Yearly') ? 'selected' : '' ?>>Half-Yearly (Biannually)</option>
                            <option value="Yearly" <?= ($edit_mode && $edit_data['frequency'] == 'Yearly') ? 'selected' : '' ?>>Yearly (Annually)</option>
                            <option value="One-Time" <?= ($edit_mode && $edit_data['frequency'] == 'One-Time') ? 'selected' : '' ?>>One-Time (Manual Entry)</option>
                        </select>
                    </div>

                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <label class="form-label fw-bold">Total Amount *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light text-success border-end-0"><i class="fas fa-rupee-sign"></i></span>
                            <input type="number" step="0.01" name="total_amount" class="form-control shadow-none bg-light border-start-0 text-success fw-bold" 
                               value="<?= $edit_mode ? h($edit_data['total_amount']) : '0.00' ?>" required id="totalAmountField" readonly>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <label class="form-label fw-bold">Employee ID *</label>
                        <select name="employee_username" class="form-select shadow-none" id="employeeSelect" required onchange="fetchEmployeeDetails(this.value)">
                            <option value="">Select Employee</option>
                            <?php foreach($employees as $emp): ?>
                            <option value="<?= h($emp['employee_username']) ?>" data-name="<?= h($emp['employee_name']) ?>"
                                <?= ($edit_mode && $edit_data['employee_username'] == $emp['employee_username']) ? 'selected' : '' ?>>
                                <?= h($emp['employee_username']) ?> - <?= h($emp['employee_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <label class="form-label fw-bold">Employee Name *</label>
                        <input type="text" name="employee_name" class="form-control shadow-none bg-light" id="employeeNameField" 
                               value="<?= $edit_mode ? h($edit_data['employee_name']) : '' ?>" required readonly>
                    </div>
                    
                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <label class="form-label fw-bold">Payment Status *</label>
                        <select name="status" class="form-select shadow-none" required>
                            <option value="Pending" <?= ($edit_mode && $edit_data['status'] == 'Pending') ? 'selected' : '' ?>>Pending</option>
                            <option value="Paid" <?= ($edit_mode && $edit_data['status'] == 'Paid') ? 'selected' : '' ?>>Paid</option>
                            <option value="Partially Paid" <?= ($edit_mode && $edit_data['status'] == 'Partially Paid') ? 'selected' : '' ?>>Partially Paid</option>
                            <option value="Overdue" <?= ($edit_mode && $edit_data['status'] == 'Overdue') ? 'selected' : '' ?>>Overdue</option>
                        </select>
                    </div>

                    <!-- Actions -->
                    <div class="col-12 mt-4 pt-3 border-top">
                        <button type="submit" class="btn btn-primary btn-rounded px-4 fw-bold shadow-sm">
                            <i class="fas fa-save me-2"></i> <?= $edit_mode ? 'Update Payment Record' : 'Save Payment Record' ?>
                        </button>
                        <a href="publisher_payment.php" class="btn btn-light btn-rounded px-4 ms-2 shadow-sm border"> Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ONLY SHOW SEARCH BAR AND DATA TABLE WHEN NOT IN ADD/EDIT MODE -->
    <?php if(!$edit_mode && !$add_mode): ?>
        
        <!-- ADVANCED SEARCH / SORT BAR -->
        <div class="card mb-3 shadow-sm border-0">
            <div class="card-body p-3">
                <form method="get" class="row g-2 align-items-end" action="publisher_payment.php">
                    
                    <!-- Filter By Client -->
                    <div class="col-lg-2 col-md-4">
                        <!--label class="form-label text-muted mb-1 small fw-bold"><i class="fas fa-building me-1"></i> Filter by Client</label-->
                        <select name="client_filter" class="form-select shadow-sm">
                            <option value="">Sort by Client</option>
                            <?php foreach($clients as $c): ?>
                                <option value="<?= h($c['client_id']) ?>" <?= ($clientFilter == $c['client_id']) ? 'selected' : '' ?>><?= h($c['client_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Filter By Employee Name -->
                    <div class="col-lg-2 col-md-4">
                        <!--label class="form-label text-muted mb-1 small fw-bold"><i class="fas fa-user-tie me-1"></i> Filter by Employee Name</label-->
                        <select name="emp_filter" class="form-select shadow-sm">
                            <option value="">Sort by Employee Name</option>
                            <?php foreach($employees as $e): ?>
                                <option value="<?= h($e['employee_username']) ?>" <?= ($empFilter == $e['employee_username']) ? 'selected' : '' ?>><?= h($e['employee_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Sort By -->
                    <div class="col-lg-2 col-md-4">
                        <!--label class="form-label text-muted mb-1 small fw-bold"><i class="fas fa-sort-amount-down me-1"></i> Sort By</label-->
                        <select name="sort_by" class="form-select shadow-sm">
                            <option value="id_desc" <?= ($sortBy == 'id_desc') ? 'selected' : '' ?>>Sort by Latest First</option>
                            <option value="client_asc" <?= ($sortBy == 'client_asc') ? 'selected' : '' ?>>Sort by Client (A-Z)</option>
                            <option value="emp_asc" <?= ($sortBy == 'emp_asc') ? 'selected' : '' ?>>Sort by Employee Name (A-Z)</option>
                        </select>
                    </div>

                    <!-- Search Input -->
                    <div class="col-lg-4 col-md-8">
                        <!--label class="form-label text-muted mb-1 small fw-bold"><i class="fas fa-search me-1"></i> Search Keyword</label-->
                        <input type="text" name="q" class="form-control shadow-sm" placeholder="Sort by Country, Service, Status..." value="<?= h($searchQuery) ?>">
                    </div>

                    <!-- Actions -->
                    <div class="col-lg-2 col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1 shadow-sm"><i class="fas fa-filter me-1"></i> Filter</button>
                        <a href="publisher_payment.php" class="btn btn-light border shadow-sm" title="Clear all filters"><i class="fas fa-undo"></i></a>
                    </div>
                </form>
            </div>
        </div>

        <!-- DATA TABLE -->
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-table text-primary me-2"></i>Recent Financial Records</h6>
                <span class="badge bg-light text-dark border">Total: <?= $result->num_rows ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 text-center align-middle" style="min-width: 1200px;">
                        <thead>
                            <tr>
                                <th class="py-3">Month</th>
                                <th class="py-3">Year</th>
                                <th class="py-3 text-start">Publisher</th>
                                <th class="py-3">Journal Name</th>
                                <th class="py-3">Country</th>
                                <th class="py-3">No.of Journals</th>
                                <th class="py-3">Frequency</th>
                                <th class="py-3">Service Job</th>
                                <th class="py-3">Amount</th>
                                <th class="py-3">Total Amount</th>
                                <th class="py-3">Employee ID</th>
                                <th class="py-3">Employee Name</th>
                                <th class="py-3">Status</th>
                                <th class="py-3 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark border"><?= h($row['month']) ?></span></td>
                                    <td><span class="badge bg-light text-dark border"><?= h($row['year']) ?></span></td>
                                    <td class="text-start fw-bold text-primary">
                                        <?= h($row['publisher_name']) ?>
                                    </td>
                                    <td><?= h($row['journal_name'] ?? '') ?></td>
                                    <td><?= h($row['country'] ?? $row['client_country']) ?></td>
                                    <td><?= h($row['no_of_journals']) ?></td>
                                    <td><span class="text-muted small fw-semibold"><?= h($row['frequency'] ?? $row['client_frequency']) ?></span></td>
                                    
                                    <td><?= h($row['services']) ?></td>
                                    
                                    <!-- Display Amount here (After Service Job) -->
                                    <td><span class="text-muted fw-semibold">₹<?= number_format((float)$row['amount'], 2) ?></span></td>
                                    
                                    <!-- Total Amount -->
                                    <td><span class="fw-bold text-success">₹<?= number_format((float)$row['total_amount'], 2) ?></span></td>
                                    
                                    <!-- Display Employee ID here (After Total Amount) -->
                                    <td><span class="badge bg-light text-secondary border"><?= h($row['employee_username']) ?></span></td>
                                    
                                    <td><div class="small fw-semibold text-secondary"><?= h($row['employee_name']) ?></div></td>
                                    <td>
                                        <span class="badge-custom bg-<?= 
                                            $row['status'] == 'Paid' ? 'success' : 
                                            ($row['status'] == 'Pending' ? 'warning text-dark' : 
                                            ($row['status'] == 'Overdue' ? 'danger' : 'info text-dark')) 
                                        ?>">
                                            <?= h($row['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex justify-content-center gap-1">
                                            <a href="publisher_payment.php?edit=1&id=<?= $row['id'] ?>" class="btn btn-sm btn-light border text-primary rounded-circle" data-bs-toggle="tooltip" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" action="publisher_payment.php" class="m-0 p-0" onsubmit="return confirm('Are you sure you want to delete this record?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-light border text-danger rounded-circle" data-bs-toggle="tooltip" title="Delete">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="14" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-folder-open fa-3x mb-3 text-light"></i>
                                            <p class="mb-0">No publisher payment records found matching your filters.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
    <?php endif; ?> <!-- End of Grid section hidden condition -->
</div>

<!-- IMPORT MODAL POPUP -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold"><i class="fas fa-file-excel me-2"></i>Import Publisher Payments</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form action="" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_excel">
                
                <div class="modal-body p-4">
                    <p class="small text-muted mb-4">Ensure the <strong>Publisher Name</strong> strictly matches an active publisher from the Client Master.</p>
                    
                    <div class="mb-4">
                        <label class="form-label fw-bold">Select Excel File (.xlsx)</label>
                        <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                    </div>

                    <div class="alert alert-light border p-3 small">
                        <strong class="text-primary"><i class="fas fa-columns me-1"></i> Required Column Structure (In exact order):</strong><br>
                        <ol class="mb-0 mt-2" style="column-count: 2;">
                            <li>Month</li>
                            <li>Year</li>
                            <li><strong class="text-danger">Publisher Name</strong></li>
                            <li>Journal Name</li>
                            <li>Country</li>
                            <li>No. of Journals</li>
                            <li>Frequency</li>
                            <li>Service</li>
                            <li>Amount</li>
                            <li>Total Amount</li>
                            <li>Employee ID</li>
                            <li>Employee Name</li>
                            <li>Status</li>
                        </ol>
                    </div>
                </div>
                
                <div class="modal-footer bg-light border-0 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-success fw-bold" onclick="downloadSample()"><i class="fas fa-download me-1"></i> Get Sample Format</button>
                    <div>
                        <button type="button" class="btn btn-secondary px-4 me-2" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-dark px-4 fw-bold"><i class="fas fa-upload me-1"></i> Start Import</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JavaScript Logic -->
<script>
// JSON Export of Sales Calculation Rates
const salesRates = <?= json_encode($sales_rates) ?>;

// Auto-Fetch Client Country & Default Frequency
function fetchClientDetails(clientId) {
    if (!clientId) return;
    const select = document.getElementById('clientSelect');
    const selectedOption = select.options[select.selectedIndex];
    
    document.getElementById('countryField').value = selectedOption.getAttribute('data-country') || '';
    
    const frequencyField = document.getElementById('frequencyField');
    const clientFrequency = selectedOption.getAttribute('data-frequency') || '';
    
    for (let i = 0; i < frequencyField.options.length; i++) {
        if (frequencyField.options[i].value === clientFrequency) {
            frequencyField.selectedIndex = i;
            break;
        }
    }
    autoFetchAmount(); // Attempt matching rate
}

// Auto-Fill Employee Name
function fetchEmployeeDetails(employeeId) {
    const select = document.getElementById('employeeSelect');
    document.getElementById('employeeNameField').value = !employeeId ? '' : (select.options[select.selectedIndex].getAttribute('data-name') || '');
}

// Automatically Fetch Amount Based on Service & Frequency Selection
function autoFetchAmount() {
    const servicesField = document.getElementById('servicesField');
    const frequency = document.getElementById('frequencyField').value;
    const amountField = document.getElementById('amountField');
    
    if (servicesField.selectedIndex <= 0 || !frequency) return;
    
    const selectedOption = servicesField.options[servicesField.selectedIndex];
    const jobName = selectedOption.value;
    const jobId = selectedOption.getAttribute('data-id');

    // Handle Manual/One-Time overrides
    if (frequency === 'One-Time') {
        amountField.removeAttribute('readonly');
        amountField.classList.remove('bg-light');
        amountField.focus();
    } else {
        amountField.setAttribute('readonly', 'true');
        amountField.classList.add('bg-light');
        
        // Match rate by String Identifier OR Job ID
        const rates = salesRates[jobName] || salesRates[jobId];
        
        if (rates && rates[frequency] !== undefined && rates[frequency] !== null) {
            amountField.value = parseFloat(rates[frequency]).toFixed(2);
        } else {
            amountField.value = '0.00'; // Default if matching service/frequency is absent
        }
    }
    calculateTotalAmount();
}

// Calculate Derived Total Amount
function calculateTotalAmount() {
    const amountField = document.getElementById('amountField');
    const journalsField = document.getElementById('journalsField');
    const totalAmountField = document.getElementById('totalAmountField');
    
    const amount = parseFloat(amountField.value) || 0;
    const journals = parseInt(journalsField.value) || 0;
    
    // (Amount * Quantity of Journals) logic. If Journals is 0 or 1, base amount acts as total.
    const multiplier = journals > 0 ? journals : 1; 
    totalAmountField.value = (amount * multiplier).toFixed(2);
}

// Sample file download function for Import Data
function downloadSample() {
    const headers = "Month,Year,Publisher Name,Journal Name,Country,No. of Journals,Frequency,Service,Amount,Total Amount,Employee ID,Employee Name,Status\n";
    const sample = "April,2026,Oxford Publishing,Oxford Medical Journal,USA,12,Monthly,Formatting,150.00,1800.00,EMP001,John Doe,Paid\n";
    const blob = new Blob([headers + sample], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'publisher_payment_sample.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

document.addEventListener("DOMContentLoaded", function() {
    // Enable Bootstrap tooltips
    var tooltipTriggerList =[].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl)
    });

    // Auto-dismiss Alert messages after 4 seconds
    var alertBox = document.querySelector('.alert');
    if (alertBox) {
        setTimeout(() => { new bootstrap.Alert(alertBox).close(); }, 4000);
    }
    
    // Event listeners for automatic calculations
    if(document.getElementById('servicesField')) {
        document.getElementById('servicesField').addEventListener('change', autoFetchAmount);
        document.getElementById('frequencyField').addEventListener('change', autoFetchAmount);
        document.getElementById('amountField').addEventListener('input', calculateTotalAmount);
        document.getElementById('journalsField').addEventListener('input', calculateTotalAmount);
    }
    
    // Edit mode init handling
    <?php if($edit_mode): ?>
        const freqField = document.getElementById('frequencyField');
        if(freqField) {
            const freq = freqField.value;
            if (freq === 'One-Time') {
                document.getElementById('amountField').removeAttribute('readonly');
                document.getElementById('amountField').classList.remove('bg-light');
            }
        }
    <?php endif; ?>
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>