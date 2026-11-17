<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Include SimpleXLSX library
$libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = 'simplexlsx-master/SimpleXLSX.php';

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

function redirect_with($params = []) {
    $base = 'client_rate_list.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Get all active clients for dropdown
function getClients($mysqli, $selectedId = null) {
    $clients = [];
    $sql = "SELECT client_id, client_name, country, currency, address, gst_no, invoice_email, phone, contact_person 
            FROM client_master WHERE status = 'Active' ORDER BY client_name";
    $result = $mysqli->query($sql);
    
    while($row = $result->fetch_assoc()) {
        $clients[] = $row;
    }
    return $clients;
}

// Get service amounts from services_master
function getServiceAmounts($mysqli) {
    $amounts = [];
    $sql = "SELECT service_name, service_amount FROM services_master";
    $result = $mysqli->query($sql);
    
    while($row = $result->fetch_assoc()) {
        $amounts[$row['service_name']] = $row['service_amount'];
    }
    return $amounts;
}

// Function to find client ID by name
function getClientIdByName($mysqli, $client_name) {
    $stmt = $mysqli->prepare("SELECT client_id FROM client_master WHERE client_name = ? LIMIT 1");
    $stmt->bind_param("s", $client_name);
    $stmt->execute();
    $stmt->bind_result($client_id);
    $stmt->fetch();
    $stmt->close();
    return $client_id ?: 0;
}

// Handle Excel Import - USING YOUR WORKING METHOD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        // Check if library exists
        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'SimpleXLSX library not found. Please install it.', 'type' => 'danger']);
        }
        
        require_once $libPath;
        $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);

        if ($xlsx) {
            $rows = $xlsx->rows();
            
            // Debug Counters
            $count_added = 0;
            $count_updated = 0;
            $count_skipped_unknown = 0;
            $errors = [];

            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header row

                // Get data from Excel columns
                $client_name = trim($r[0] ?? ''); // Column A: client_name
              // Get data from Excel columns
$client_name = trim($r[0] ?? ''); // Column A: client_name
$payment = trim($r[1] ?? '');     // Column B: payment
$frequency = trim($r[2] ?? '');   // Column C: frequency

// Validate payment and frequency values
$valid_payment = ['Monthly', 'Bimonthly', 'Quarterly', 'Mixed'];
$valid_frequency = ['Monthly', 'Bimonthly', 'Quarterly', 'Mixed'];

if (!in_array($payment, $valid_payment) && !empty($payment)) {
    $errors[] = "Row " . ($k + 1) . ": Invalid payment value '$payment'. Must be Monthly, Bimonthly, Quarterly, or Mixed";
}

if (!in_array($frequency, $valid_frequency) && !empty($frequency)) {
    $errors[] = "Row " . ($k + 1) . ": Invalid frequency value '$frequency'. Must be Monthly, Bimonthly, Quarterly, or Mixed";
} 
 $y0j = trim($r[3] ?? '');         // Column D: y0j
               // $initial = trim($r[4] ?? '');     // Column E: initial
                $invoice_name = trim($r[5] ?? ''); // Column F: invoice_name
                $all_services = trim($r[6] ?? ''); // Column G: all_services
                $ce_ts = trim($r[7] ?? '');       // Column H: ce_ts
                $ce_ts_xml = trim($r[8] ?? '');   // Column I: ce_ts_xml
                $ce = trim($r[9] ?? '');          // Column J: ce
                $ts = trim($r[10] ?? '');         // Column K: ts
                $xml = trim($r[11] ?? '');        // Column L: xml
                $html = trim($r[12] ?? '');       // Column M: html
                $xml_html = trim($r[13] ?? '');   // Column N: xml_html

                // Validate client name
                if (empty($client_name)) {
                    $errors[] = "Row " . ($k + 1) . ": Client Name is required";
                    continue;
                }

                // STRICT CHECK: DOES CLIENT EXIST IN MASTER?
                $client_id = getClientIdByName($mysqli, $client_name);
                
                if ($client_id === 0) {
                    // SKIP THIS ROW - Client not found
                    $count_skipped_unknown++;
                    continue; 
                }

                // Convert checkbox values to 'Yes'/'No'
                $all_services_db = (!empty($all_services) && strtolower($all_services) === 'yes') ? 'Yes' : 'No';
                $ce_ts_db = (!empty($ce_ts) && strtolower($ce_ts) === 'yes') ? 'Yes' : 'No';
                $ce_ts_xml_db = (!empty($ce_ts_xml) && strtolower($ce_ts_xml) === 'yes') ? 'Yes' : 'No';
                $ce_db = (!empty($ce) && strtolower($ce) === 'yes') ? 'Yes' : 'No';
                $ts_db = (!empty($ts) && strtolower($ts) === 'yes') ? 'Yes' : 'No';
                $xml_db = (!empty($xml) && strtolower($xml) === 'yes') ? 'Yes' : 'No';
                $html_db = (!empty($html) && strtolower($html) === 'yes') ? 'Yes' : 'No';
                $xml_html_db = (!empty($xml_html) && strtolower($xml_html) === 'yes') ? 'Yes' : 'No';

                // Check if rate already exists for this client
                $check_stmt = $mysqli->prepare("SELECT id FROM client_rate_list WHERE client_id = ?");
                $check_stmt->bind_param("i", $client_id);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    // UPDATE existing record
                    $check_stmt->close();
                    
                    $stmt = $mysqli->prepare("UPDATE client_rate_list SET 
                        payment = ?, frequency = ?, y0j = ?,
                        invoice_name = ?, 
                        all_services = ?, ce_ts = ?, ce_ts_xml = ?, 
                        ce = ?, ts = ?, xml = ?, html = ?, xml_html = ?,
                        updated_date = NOW() WHERE client_id = ?");
                    
                    $stmt->bind_param("ssssssssssssi", 
                        $payment, $frequency, $y0j,
                        $invoice_name,
                        $all_services_db, $ce_ts_db, $ce_ts_xml_db, 
                        $ce_db, $ts_db, $xml_db, $html_db, $xml_html_db,
                        $client_id);
                    
                    if ($stmt->execute()) {
                        $count_updated++;
                    } else {
                        $errors[] = "Row " . ($k + 1) . ": Update error - " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    // INSERT new record
                    $check_stmt->close();
                    
                    $stmt = $mysqli->prepare("INSERT INTO client_rate_list 
                        (client_id, client_name, payment, frequency, y0j, invoice_name, 
                         all_services, ce_ts, ce_ts_xml, ce, ts, xml, html, xml_html, created_date) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    
                    $stmt->bind_param("issssssssssssss", 
                        $client_id, $client_name,
                        $payment, $frequency, $y0j, $invoice_name,
                        $all_services_db, $ce_ts_db, $ce_ts_xml_db, 
                        $ce_db, $ts_db, $xml_db, $html_db, $xml_html_db);
                    
                    if ($stmt->execute()) {
                        $count_added++;
                    } else {
                        $errors[] = "Row " . ($k + 1) . ": Insert error - " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
            
            // Prepare result message
            $message = "Import completed: ";
            if ($count_added > 0) {
                $message .= "$count_added new records added. ";
            }
            if ($count_updated > 0) {
                $message .= "$count_updated existing records updated. ";
            }
            if ($count_skipped_unknown > 0) {
                $message .= "$count_skipped_unknown rows skipped (client not found in master). ";
            }
            
            if (!empty($errors)) {
                $message .= "Errors: " . implode("; ", array_slice($errors, 0, 3));
                if (count($errors) > 3) {
                    $message .= "... (and " . (count($errors) - 3) . " more)";
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

// Handle other POST actions (add, edit, delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $payment = trim($_POST['payment'] ?? '');
        $frequency = trim($_POST['frequency'] ?? '');
        $y0j = trim($_POST['y0j'] ?? '');
        //$initial = trim($_POST['initial'] ?? '');
        $invoice_name = trim($_POST['invoice_name'] ?? '');
        
        // Service flags
        $all_services = isset($_POST['all_services']) ? 'Yes' : 'No';
        $ce_ts = isset($_POST['ce_ts']) ? 'Yes' : 'No';
        $ce_ts_xml = isset($_POST['ce_ts_xml']) ? 'Yes' : 'No';
        $ce = isset($_POST['ce']) ? 'Yes' : 'No';
        $ts = isset($_POST['ts']) ? 'Yes' : 'No';
        $xml = isset($_POST['xml']) ? 'Yes' : 'No';
        $html = isset($_POST['html']) ? 'Yes' : 'No';
        $xml_html = isset($_POST['xml_html']) ? 'Yes' : 'No';
        
        // Validation
        if ($client_id === 0) {
            redirect_with(['msg' => 'Please select a client.', 'type' => 'danger']);
        }
        
        // Get client name from database
        $client_name = '';
        $get_client_stmt = $mysqli->prepare("SELECT client_name FROM client_master WHERE client_id = ?");
        $get_client_stmt->bind_param("i", $client_id);
        $get_client_stmt->execute();
        $get_client_stmt->bind_result($client_name);
        $get_client_stmt->fetch();
        $get_client_stmt->close();
        
        if ($action === 'add') {
            // Check if rate already exists for this client
            $check_stmt = $mysqli->prepare("SELECT id FROM client_rate_list WHERE client_id = ?");
            $check_stmt->bind_param("i", $client_id);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                redirect_with(['msg' => 'Rate list already exists for this client.', 'type' => 'danger']);
            }
            $check_stmt->close();
            
            // Insert new rate list
            $stmt = $mysqli->prepare("INSERT INTO client_rate_list 
                (client_id, client_name, payment, frequency, y0j,invoice_name, 
                 all_services, ce_ts, ce_ts_xml, ce, ts, xml, html, xml_html, created_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            
            $stmt->bind_param("isssssssssssss", 
                $client_id, $client_name, $payment, $frequency, $y0j, $invoice_name,
                $all_services, $ce_ts, $ce_ts_xml, $ce, $ts, $xml, $html, $xml_html);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Client rate list added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding client rate list: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Update client rate list
                $stmt = $mysqli->prepare("UPDATE client_rate_list SET 
                    client_id = ?, client_name = ?, payment = ?, frequency = ?, y0j = ?,
                    invoice_name = ?, 
                    all_services = ?, ce_ts = ?, ce_ts_xml = ?, ce = ?, ts = ?, xml = ?, html = ?, xml_html = ?,
                    updated_date = NOW() WHERE id = ?");
                
                $stmt->bind_param("isssssssssssssi", 
                    $client_id, $client_name, $payment, $frequency, $y0j, $invoice_name,
                    $all_services, $ce_ts, $ce_ts_xml, $ce, $ts, $xml, $html, $xml_html, $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Client rate list updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating client rate list: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $client_name = '';
        
        if ($id > 0) {
            // Get client name for message
            $res = $mysqli->prepare("SELECT cm.client_name 
                                   FROM client_rate_list crl 
                                   JOIN client_master cm ON crl.client_id = cm.client_id 
                                   WHERE crl.id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($client_name);
            $res->fetch();
            $res->close();
            
            // Delete rate list
            $del = $mysqli->prepare("DELETE FROM client_rate_list WHERE id = ?");
            $del->bind_param("i", $id);
            
            if ($del->execute()) {
                redirect_with(['msg' => "Rate list for '$client_name' deleted successfully", 'type' => 'warning']);
            } else {
                redirect_with(['msg' => 'Error deleting rate list: ' . $del->error, 'type' => 'danger']);
            }
            $del->close();
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editRate = null;
$clientData = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT crl.*, cm.client_name, cm.country, cm.currency, cm.address as invoice_address, 
                                 cm.gst_no, cm.invoice_email, cm.phone, cm.contact_person as cm_contact_person
                                 FROM client_rate_list crl 
                                 JOIN client_master cm ON crl.client_id = cm.client_id 
                                 WHERE crl.id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editRate = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editRate) $mode = 'view';
}

// Get clients for dropdown
$clients = getClients($mysqli);

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'cm.client_name';
$dir = strtolower($_GET['dir'] ?? 'asc');

// Build query with JOIN to client_master
$sql = "SELECT 
            crl.id,
            crl.client_id,
            cm.client_name,
            cm.country,
            cm.currency,
            crl.payment,
            crl.frequency,
            crl.y0j,
            cm.initial,
            crl.invoice_name,
            cm.address as invoice_address1,
            cm.additional_address as invoice_address,
            cm.gst_no,
            cm.invoice_email,
            cm.email_files,
            cm.general_email,
            crl.queries,
            crl.link,
            crl.contact_number,
            crl.contact_person,
            crl.all_services,
            crl.ce_ts,
            crl.ce_ts_xml,
            crl.ce,
            crl.ts,
            crl.xml,
            crl.html,
            crl.xml_html,
            cm.ref_rate,
            cm.math_rate,
            crl.created_date,
            crl.updated_date
        FROM client_rate_list crl 
        JOIN client_master cm ON crl.client_id = cm.client_id 
        WHERE 1=1";
        
$params = [];
$types = '';

if ($q !== '') {
    $sql .= " AND (cm.client_name LIKE ? OR cm.country LIKE ? OR crl.payment LIKE ? OR crl.contact_person LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

$sql .= " ORDER BY $sort $dir";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rateLists = $stmt->get_result();
} else {
    $rateLists = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$rateLists) {
    $rateLists = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Rate List</title>
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
            font-size: 0.85rem;
            white-space: nowrap;
        }
        .table td {
            vertical-align: middle;
            font-size: 0.82rem;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
        }
        .service-badge {
            font-size: 0.65rem;
            margin: 1px;
        }
        .form-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .client-info-box {
            background: #e9ecef;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .template-download {
            background-color: #e7f3ff;
            border-left: 4px solid #0d6efd;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
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
            <h4><i class="fas fa-list-alt me-2"></i><?= $mode === 'edit' ? 'Edit Client Rate List' : 'Add New Client Rate List' ?></h4>
            <a href="client_rate_list.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editRate['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <!-- Client Selection -->
                    <div class="form-section">
                        <h5 class="mb-3"><i class="fas fa-building me-2"></i>Client Selection</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="client_id" class="form-label">Select Client <span class="text-danger">*</span></label>
                                    <select class="form-control" id="client_id" name="client_id" required <?= $mode === 'edit' ? 'disabled' : '' ?>>
                                        <option value="">Select Client</option>
                                        <?php foreach($clients as $client): ?>
                                            <option value="<?= $client['client_id'] ?>" 
                                                data-country="<?= h($client['country']) ?>"
                                                data-currency="<?= h($client['currency']) ?>"
                                                data-address="<?= h($client['address']) ?>"
                                                data-gst="<?= h($client['gst_no']) ?>"
                                                data-invoice-email="<?= h($client['invoice_email']) ?>"
                                                data-phone="<?= h($client['phone']) ?>"
                                                data-contact-person="<?= h($client['contact_person']) ?>"
                                                <?= (isset($editRate['client_id']) && $editRate['client_id'] == $client['client_id']) ? 'selected' : '' ?>>
                                                <?= h($client['client_name']) ?> (<?= h($client['country']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if($mode === 'edit'): ?>
                                    <input type="hidden" name="client_id" value="<?= h($editRate['client_id'] ?? '') ?>">
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment & Invoice Information -->
                    <div class="form-section">
                        <h5 class="mb-3"><i class="fas fa-money-bill-wave me-2"></i>Payment & Invoice Information</h5>
                        <div class="row">
                           <div class="col-md-3">
            <div class="mb-3">
                <label for="payment" class="form-label">Payment</label>
                <select class="form-control" id="payment" name="payment">
                    <option value="">Select Payment</option>
                    <option value="Monthly" <?= (isset($editRate['payment']) && $editRate['payment'] == 'Monthly') ? 'selected' : '' ?>>Monthly</option>
                    <option value="Bimonthly" <?= (isset($editRate['payment']) && $editRate['payment'] == 'Bimonthly') ? 'selected' : '' ?>>Bimonthly</option>
                    <option value="Quarterly" <?= (isset($editRate['payment']) && $editRate['payment'] == 'Quarterly') ? 'selected' : '' ?>>Quarterly</option>
                    <option value="Mixed" <?= (isset($editRate['payment']) && $editRate['payment'] == 'Mixed') ? 'selected' : '' ?>>Mixed</option>
                </select>
            </div>
        </div>
        <div class="col-md-3">
            <div class="mb-3">
                <label for="frequency" class="form-label">Frequency</label>
                <select class="form-control" id="frequency" name="frequency">
                    <option value="">Select Frequency</option>
                    <option value="Monthly" <?= (isset($editRate['frequency']) && $editRate['frequency'] == 'Monthly') ? 'selected' : '' ?>>Monthly</option>
                    <option value="Bimonthly" <?= (isset($editRate['frequency']) && $editRate['frequency'] == 'Bimonthly') ? 'selected' : '' ?>>Bimonthly</option>
                    <option value="Quarterly" <?= (isset($editRate['frequency']) && $editRate['frequency'] == 'Quarterly') ? 'selected' : '' ?>>Quarterly</option>
                    <option value="Mixed" <?= (isset($editRate['frequency']) && $editRate['frequency'] == 'Mixed') ? 'selected' : '' ?>>Mixed</option>
                </select>
            </div>
        </div>                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="y0j" class="form-label">Year Of Joining</label>
                                    <input type="text" class="form-control" id="y0j" name="y0j" 
                                           value="<?= h($editRate['y0j'] ?? '') ?>">
                                </div>
                            </div>
                            <!--div class="col-md-3">
                                <div class="mb-3">
                                    <label for="initial" class="form-label">Initial</label>
                                    <input type="text" class="form-control" id="initial" name="initial" 
                                           value="<?= h($editRate['initial'] ?? '') ?>">
                                </div>
                            </div-->
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="invoice_name" class="form-label">Invoice Name</label>
                                    <input type="text" class="form-control" id="invoice_name" name="invoice_name" 
                                           value="<?= h($editRate['invoice_name'] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Service Types -->
                    <div class="form-section">
                        <h5 class="mb-3"><i class="fas fa-cogs me-2"></i>Service Types</h5>
                        <div class="row">
                            <?php 
                            $service_amounts = getServiceAmounts($mysqli);
                            $services = [
                                'all_services' => 'All Services',
                                'ce_ts' => 'CE+TS',
                                'ce_ts_xml' => 'CE+TS+XML',
                                'ce' => 'CE',
                                'ts' => 'TS',
                                'xml' => 'XML',
                                'html' => 'HTML',
                                'xml_html' => 'XML+HTML'
                            ];
                            
                            foreach($services as $key => $label): 
                                $checked = (isset($editRate[$key]) && $editRate[$key] === 'Yes') ? 'checked' : '';
                                
                                $amount_display = '';
                                if (isset($service_amounts[$label]) && $service_amounts[$label] > 0) {
                                    $amount_display = ' <span class="fw-bold text-success">(₹ ' . number_format($service_amounts[$label], 2) . ')</span>';
                                }
                            ?>
                            <div class="col-md-3 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="<?= $key ?>" id="<?= $key ?>" <?= $checked ?>>
                                    <label class="form-check-label" for="<?= $key ?>"><?= $label . $amount_display ?></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Rate List' : 'Save Rate List' ?>
                        </button>
                        <a href="client_rate_list.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-list-alt me-2"></i>Client Rate List</h4>
            <div>
                <a href="client_rate_list.php?mode=add" class="btn btn-success btn-sm me-2">
                    <i class="fas fa-plus me-1"></i> Add Rate List
                </a>
                <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-import me-1"></i> Import Excel
                </button>
            </div>
        </div>

        <!-- Excel Import Template Download -->
        <div class="template-download">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-1"><i class="fas fa-download me-2"></i>Excel Template</h6>
                    <p class="mb-0 text-muted">Download the Excel template with correct column format for import.</p>
                    <small class="text-muted">Required columns in order: client_name, payment, frequency, y0j, invoice_name, all_services, ce_ts, ce_ts_xml, ce, ts, xml, html, xml_html</small>
                    <br><small class="text-muted">Service values: "Yes" or "No" (case-insensitive)</small>
                </div>
                <div>
                    <!--a href="download_excel_client_rate.php" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-file-excel me-1"></i> Download Template
                    </a-->
                </div>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search clients, countries, payment..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="cm.client_name" <?= $sort === 'cm.client_name' ? 'selected' : '' ?>>Sort by Name</option>
                            <option value="cm.country" <?= $sort === 'cm.country' ? 'selected' : '' ?>>Sort by Country</option>
                            <option value="crl.payment" <?= $sort === 'crl.payment' ? 'selected' : '' ?>>Sort by Payment</option>
                            <option value="crl.created_date" <?= $sort === 'crl.created_date' ? 'selected' : '' ?>>Sort by Date</option>
                        </select>
                    </div>
                    <!--div class="col-md-2">
                        <select name="dir" class="form-select form-select-sm">
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option>
                        </select>
                    </div-->
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary btn-sm me-2 w-100">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                    </div>
                    <div class="col-md-12 mt-2">
                        <a href="client_rate_list.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Rate Lists Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="30">ID</th>
                                <th>Client Name</th>
                                <th>Country</th>
                                <th>Currency</th>
                                <th>Payment</th>
                                <th>Frequency</th>
                                <th>Y0J</th>
                                <th>Initial</th>
                                <th>Invoice Name</th>
                                <th>Invoice Address</th>
                                <th>GST</th>
                                <th>Email Invoice</th>
                                <th>Email Files</th>
                               <th>Email Payment Queries</th>
                               <th>Link</th>
                               <th>Contact Number</th>
                                <th>Contact Person</th>
                                <th>Services</th>
                                <th width="100">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($rateLists && $rateLists->num_rows > 0): $i=0;?>
                                <?php while($row = $rateLists->fetch_assoc()): $i=$i+1;?>
                                <tr>
                                    <td class="fw-bold"><?= $i ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= h($row['client_name']) ?></div>
                                        <small class="text-muted">ID: <?= h($row['client_id']) ?></small>
                                    </td>
                                    <td><?= h($row['country']) ?: '—' ?></td>
                                    <td><?= h($row['currency']) ?: '—' ?></td>
                                    <td>
    <?php if(!empty($row['payment'])): ?>
        <span class="badge bg-info"><?= h($row['payment']) ?></span>
    <?php else: ?>
        —
    <?php endif; ?>
</td>
<td>
    <?php if(!empty($row['frequency'])): ?>
        <span class="badge bg-secondary"><?= h($row['frequency']) ?></span>
    <?php else: ?>
        —
    <?php endif; ?>
</td>
                                    <td><?= h($row['y0j']) ?: '—' ?></td>
                                    <td><?= h($row['initial']) ?: '—' ?></td>
                                    <td>
                                        <?php if(!empty($row['invoice_name'])): ?>
                                            <?= h($row['invoice_name']) ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                     <td>
                                        <?php if(!empty($row['invoice_address'])): ?>
                                            <?= h($row['invoice_address']) ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($row['gst_no']) ?: '—' ?></td>
                                    <td><?= h($row['invoice_email']) ?: '—' ?></td>
                                    <td><?= h($row['email_files']) ?: '—' ?></td>
                                    <td><?= h($row['general_email']) ?: '—' ?></td>
                                    <td class="basic-col">
                                        <?php if(!empty($row['link'])): ?>
                                            <small class="text-muted"><?= h($row['link']) ?></small>
                                            <a href="<?= h($row['link']) ?>" target="_blank"><i class="fa fa-link"></i></a>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($row['contact_number']) ?: '—' ?></td>
                                    <td><?= h($row['contact_person']) ?: '—' ?></td>
                                    
                                    <td>
                                        <?php
                                        $activeServices = [];
                                        $serviceFields = ['all_services', 'ce_ts', 'ce_ts_xml', 'ce', 'ts', 'xml', 'html', 'xml_html'];
                                        foreach($serviceFields as $service) {
                                            if (isset($row[$service]) && $row[$service] === 'Yes') {
                                                $activeServices[] = ucfirst(str_replace('_', '+', $service));
                                            }
                                        }
                                        if (!empty($activeServices)) {
                                            echo '<div class="d-flex flex-wrap gap-1">';
                                            foreach($activeServices as $service) {
                                                echo '<span class="badge bg-primary service-badge">' . $service . '</span>';
                                            }
                                            echo '</div>';
                                        } else {
                                            echo '<span class="text-muted">—</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="client_rate_list.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit Rate List">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete rate list for <?= h($row['client_name']) ?>?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Rate List">
                                                    <i class="fas fa-trash"></i>
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
                                            <i class="fas fa-list-alt fa-3x mb-3"></i>
                                            <h5>No rate lists found</h5>
                                            <p class="mb-0">Get started by adding your first client rate list.</p>
                                            <a href="client_rate_list.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Rate List
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

    <!-- Import Excel Modal -->
    <div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="importModalLabel">
                        <i class="fas fa-file-import me-2"></i>Import Client Rate List from Excel
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle me-2"></i>Instructions:</h6>
                            <ul class="mb-0 small">
                                <li>Excel file must have these columns IN ORDER:</li>
                                <li>Column A: <strong>client_name</strong> (must exist in database)</li>
                                <li>Column B: <strong>payment</strong></li>
                                <li>Column C: <strong>frequency</strong></li>
                                <li>Column D: <strong>y0j</strong></li>
                                <li>Column E: <strong>initial</strong></li>
                                <li>Column F: <strong>invoice_name</strong></li>
                                <li>Column G: <strong>all_services</strong> (Yes/No)</li>
                                <li>Column H: <strong>ce_ts</strong> (Yes/No)</li>
                                <li>Column I: <strong>ce_ts_xml</strong> (Yes/No)</li>
                                <li>Column J: <strong>ce</strong> (Yes/No)</li>
                                <li>Column K: <strong>ts</strong> (Yes/No)</li>
                                <li>Column L: <strong>xml</strong> (Yes/No)</li>
                                <li>Column M: <strong>html</strong> (Yes/No)</li>
                                <li>Column N: <strong>xml_html</strong> (Yes/No)</li>
                                <li><strong>Important:</strong> Rows with unknown clients will be skipped</li>
                                <li>Existing records will be updated, new records will be inserted</li>
                            </ul>
                        </div>
                        
                        <div class="mb-3">
                            <label for="excel_file" class="form-label">Select Excel File <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" id="excel_file" name="excel_file" accept=".xlsx,.xls" required>
                            <div class="form-text">Supported formats: .xlsx, .xls</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info">
                            <i class="fas fa-upload me-1"></i> Import Data
                        </button>
                    </div>
                </form>
            </div>
        </div>
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
            const filterSelects = document.querySelectorAll('select[name="sort"], select[name="dir"]');
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });

            // All services checkbox functionality
            const allServicesCheckbox = document.getElementById('all_services');
            const serviceCheckboxes = document.querySelectorAll('input[type="checkbox"][name!="all_services"]');
            
            if (allServicesCheckbox) {
                allServicesCheckbox.addEventListener('change', function() {
                    serviceCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                });

                // If any service checkbox is unchecked, uncheck "All Services"
                serviceCheckboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        if (!this.checked) {
                            allServicesCheckbox.checked = false;
                        }
                    });
                });
            }

            // Client selection and info display
            const clientSelect = document.getElementById('client_id');
            const clientInfo = document.getElementById('clientInfo');
            
            function updateClientInfo() {
                const selectedOption = clientSelect.options[clientSelect.selectedIndex];
                if (selectedOption.value !== '') {
                    // Show client info
                    clientInfo.style.display = 'block';
                    
                    // Update info fields
                    document.getElementById('infoCountry').textContent = selectedOption.getAttribute('data-country') || '—';
                    document.getElementById('infoCurrency').textContent = selectedOption.getAttribute('data-currency') || '—';
                    document.getElementById('infoGst').textContent = selectedOption.getAttribute('data-gst') || '—';
                    document.getElementById('infoPhone').textContent = selectedOption.getAttribute('data-phone') || '—';
                    document.getElementById('infoInvoiceEmail').textContent = selectedOption.getAttribute('data-invoice-email') || '—';
                    document.getElementById('infoContactPerson').textContent = selectedOption.getAttribute('data-contact-person') || '—';
                    document.getElementById('infoAddress').textContent = selectedOption.getAttribute('data-address') || '—';
                } else {
                    clientInfo.style.display = 'none';
                }
            }
            
            if (clientSelect) {
                clientSelect.addEventListener('change', updateClientInfo);
                
                // Initialize on page load if editing
                <?php if($mode === 'edit' && isset($editRate['client_id'])): ?>
                updateClientInfo();
                <?php endif; ?>
            }
        });
    </script>
</body>
</html>