<?php
require 'db.php'; 
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// --- HELPER FUNCTIONS ---
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function getCurrencySymbol($currency) {
    if (empty($currency)) return '₹';
    $currency = strtoupper(trim((string)$currency));
    $symbols =[
        'USD' => '$', 'EUR' => '€', 'GBP' => '£', 
        'INR' => '₹', 'AUD' => 'A$', 'CAD' => 'C$', 
        'JPY' => '¥', 'SGD' => 'S$'
    ];
    // Return matching symbol, or original string if not mapped, default to ₹
    return $symbols[$currency] ?? ($currency . ' ');
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '<span class="text-muted">—</span>';
    return date('d-M-Y', strtotime($d));
}

function redirect_with($params =[]) {
    if (isset($params['msg'])) {
        $_SESSION['flash_msg'] = $params['msg'];
        $_SESSION['flash_type'] = $params['type'] ?? 'info';
        unset($params['msg'], $params['type']);
    }
    
    $base = 'extra_charges.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

function parseExcelDate($val) {
    if(empty($val)) return NULL;
    $ts = strtotime($val);
    return ($ts !== false) ? date('Y-m-d', $ts) : NULL;
}

// Get all active clients for dropdown
function getClients($mysqli) {
    $clients =[];
    $sql = "SELECT client_id, client_name, country, currency FROM client_master WHERE status = 'Active' ORDER BY client_name";
    $result = $mysqli->query($sql);
    while($row = $result->fetch_assoc()) {
        $clients[] = $row;
    }
    return $clients;
}

// Get services for particulars dropdown
function getServices($mysqli) {
    $services =[];
    $sql = "SELECT service_id, service_name, service_amount, category FROM services_master WHERE is_active = 1 ORDER BY service_name";
    $result = $mysqli->query($sql);
    while($row = $result->fetch_assoc()) {
        $services[] = $row;
    }
    return $services;
}

// Get processes for Job dropdown
function getProcesses($mysqli) {
    $processes =[];
    $sql = "SELECT job_id, job_name FROM job_master WHERE status = 'active' ORDER BY job_name";
    $result = $mysqli->query($sql);
    while($row = $result->fetch_assoc()) {
        $processes[] = $row;
    }
    return $processes;
}

// Get teams for Send Confirm Team dropdown
function getTeams($mysqli) {
    $teams =[];
    $sql = "SELECT `team_id`, `team_name`, `status`, `created_date` FROM `team_master` WHERE 1 ORDER BY `team_name`";
    $result = $mysqli->query($sql);
    if ($result) {
        while($row = $result->fetch_assoc()) {
            $teams[] = $row;
        }
    }
    return $teams;
}

// ---------------------------------------------------------
// HANDLE CRUD OPERATIONS
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ADD NEW EXTRA CHARGE
    if ($action === 'add') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $client_name = trim($_POST['client_name'] ?? '');
        $sales_incharge = trim($_POST['sales_incharge'] ?? '');
        $send_date_client = !empty($_POST['send_date_client']) ? date('Y-m-d', strtotime($_POST['send_date_client'])) : NULL;
        $article_details = trim($_POST['article_details'] ?? '');
        $article_count = (int)($_POST['article_count'] ?? 0);
        $particular = trim($_POST['particular'] ?? '');
        $process = trim($_POST['process'] ?? '');
        $charges = (float)($_POST['charges'] ?? 0);
        $client_confirm_date = !empty($_POST['client_confirm_date']) ? date('Y-m-d', strtotime($_POST['client_confirm_date'])) : NULL;
        $send_confirm_team = trim($_POST['send_confirm_team'] ?? '');
        $confirm_date_team = !empty($_POST['confirm_date_team']) ? date('Y-m-d', strtotime($_POST['confirm_date_team'])) : NULL;
        $remarks = trim($_POST['remarks'] ?? '');
        $description = trim($_POST['description'] ?? ''); 
        
        if ($client_id == 0) {
            redirect_with(['msg' => 'Please select a client.', 'type' => 'danger']);
        }
        
        if (empty($client_name)) {
            $client_res = $mysqli->query("SELECT client_name FROM client_master WHERE client_id = $client_id");
            if ($client_res && $client_res->num_rows > 0) {
                $client_row = $client_res->fetch_assoc();
                $client_name = $client_row['client_name'];
            }
        }
        
        $d1 = $send_date_client ? "'$send_date_client'" : "NULL";
        $d2 = $client_confirm_date ? "'$client_confirm_date'" : "NULL";
        $d3 = $confirm_date_team ? "'$confirm_date_team'" : "NULL";
        
        $sql = "INSERT INTO extra_charges 
                (client_id, client_name, sales_incharge, send_date_client, article_details, article_count, 
                 particular, process, charges, client_confirm_date, send_confirm_team, confirm_date_team, remarks, description, created_at) 
                VALUES 
                ($client_id, '$client_name', '$sales_incharge', $d1, '$article_details', $article_count, 
                 '$particular', '$process', $charges, $d2, '$send_confirm_team', $d3, '$remarks', '$description', NOW())";
        
        if($mysqli->query($sql)) {
            redirect_with(['msg' => '<i class="fas fa-check-circle me-1"></i> Extra charge added successfully.', 'type' => 'success']);
        } else {
            redirect_with(['msg' => 'Error adding record: ' . $mysqli->error, 'type' => 'danger']);
        }
    }
    
    // EDIT EXTRA CHARGE
    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $client_id = (int)($_POST['client_id'] ?? 0);
        $client_name = trim($_POST['client_name'] ?? '');
        $sales_incharge = trim($_POST['sales_incharge'] ?? '');
        $send_date_client = !empty($_POST['send_date_client']) ? date('Y-m-d', strtotime($_POST['send_date_client'])) : NULL;
        $article_details = trim($_POST['article_details'] ?? '');
        $article_count = (int)($_POST['article_count'] ?? 0);
        $particular = trim($_POST['particular'] ?? '');
        $process = trim($_POST['process'] ?? '');
        $charges = (float)($_POST['charges'] ?? 0);
        $client_confirm_date = !empty($_POST['client_confirm_date']) ? date('Y-m-d', strtotime($_POST['client_confirm_date'])) : NULL;
        $send_confirm_team = trim($_POST['send_confirm_team'] ?? '');
        $confirm_date_team = !empty($_POST['confirm_date_team']) ? date('Y-m-d', strtotime($_POST['confirm_date_team'])) : NULL;
        $remarks = trim($_POST['remarks'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if ($id > 0) {
            $d1 = $send_date_client ? "'$send_date_client'" : "NULL";
            $d2 = $client_confirm_date ? "'$client_confirm_date'" : "NULL";
            $d3 = $confirm_date_team ? "'$confirm_date_team'" : "NULL";
            
            $sql = "UPDATE extra_charges SET 
                    client_id = $client_id,
                    client_name = '$client_name',
                    sales_incharge = '$sales_incharge',
                    send_date_client = $d1,
                    article_details = '$article_details',
                    article_count = $article_count,
                    particular = '$particular',
                    process = '$process',
                    charges = $charges,
                    client_confirm_date = $d2,
                    send_confirm_team = '$send_confirm_team',
                    confirm_date_team = $d3,
                    remarks = '$remarks',
                    description = '$description'
                    WHERE id = $id";
            
            if($mysqli->query($sql)) {
                redirect_with(['msg' => '<i class="fas fa-check-circle me-1"></i> Extra charge updated successfully.', 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error updating record: ' . $mysqli->error, 'type' => 'danger']);
            }
        }
    }
    
    // DELETE EXTRA CHARGE
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $del = $mysqli->query("DELETE FROM extra_charges WHERE id = $id");
            if ($del) {
                redirect_with(['msg' => '<i class="fas fa-info-circle me-1"></i> Record deleted successfully.', 'type' => 'warning']);
            } else {
                redirect_with(['msg' => 'Error deleting record.', 'type' => 'danger']);
            }
        }
    }
}

// ---------------------------------------------------------
// STRICT IMPORT LOGIC
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
        $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);

        if ($xlsx) {
            $rows = $xlsx->rows();
            $count_added = 0; $count_skipped_dupe = 0; $count_skipped_unknown = 0; $debug_log =[];

            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header

                // Col 0 is S.No (Ignored for database processing)
                
                $client_name = trim($mysqli->real_escape_string($r[3] ?? '')); 
                if (empty($client_name)) continue; 

                $client_id = 0;
                $check = $mysqli->query("SELECT client_id FROM client_master WHERE TRIM(client_name) = '$client_name'");
                
                if ($check && $check->num_rows > 0) {
                    $row = $check->fetch_assoc();
                    $client_id = $row['client_id'];
                } else {
                    $count_skipped_unknown++;
                    $debug_log[] = "Row $k: Skipped '$client_name' (Not found)";
                    continue; 
                }

                $sales_inc      = $mysqli->real_escape_string($r[1] ?? '');
                $send_date      = parseExcelDate($r[2] ?? '');
                // $client_name is $r[3]
                $article_det    = $mysqli->real_escape_string($r[4] ?? '');
                $art_count      = (int)($r[5] ?? 0);
                $particular     = $mysqli->real_escape_string($r[6] ?? '');
                $process        = $mysqli->real_escape_string($r[7] ?? '');
                $charges        = (float)($r[8] ?? 0);
                $cli_conf_date  = parseExcelDate($r[9] ?? '');
                $send_conf_team = $mysqli->real_escape_string($r[10] ?? '');
                $team_conf_date = parseExcelDate($r[11] ?? '');
                $remarks        = $mysqli->real_escape_string($r[12] ?? '');
                $description    = $mysqli->real_escape_string($r[13] ?? '');

                $d1 = $send_date ? "'$send_date'" : "NULL";
                $d2 = $cli_conf_date ? "'$cli_conf_date'" : "NULL";
                $d3 = $team_conf_date ? "'$team_conf_date'" : "NULL";

                $dupCheck = $mysqli->query("SELECT id FROM extra_charges WHERE client_name = '$client_name' AND article_details = '$article_det' AND particular = '$particular' LIMIT 1");
                
               /* if ($dupCheck && $dupCheck->num_rows > 0) {
                    $count_skipped_dupe++;
                    continue;
                }*/

                $sql = "INSERT INTO extra_charges 
                (client_id, client_name, sales_incharge, send_date_client, article_details, article_count, particular, process, charges, client_confirm_date, send_confirm_team, confirm_date_team, remarks, description, created_at) 
                VALUES 
                ($client_id, '$client_name', '$sales_inc', $d1, '$article_det', $art_count, '$particular', '$process', $charges, $d2, '$send_conf_team', $d3, '$remarks', '$description', NOW())";
                
                if($mysqli->query($sql)) {
                    $count_added++;
                  /*  $updSql = "UPDATE client_master SET 
                               sales_incharge = '$sales_inc', send_date_client = $d1, article_details = '$article_det',
                               article_count = '$art_count', particular = '$particular', process = '$process',
                               charges = '$charges', client_confirm_date = $d2, send_confirm_team = '$send_conf_team',
                            confirm_date_team = $d3, remarks = '$remarks', description = '$description'
                               WHERE client_id = $client_id";
                    $mysqli->query($updSql);*/
                }
            }
            
            $msg = "<div class='fw-bold mb-1'><i class='fas fa-info-circle me-1'></i> Import Complete!</div>";
            $msg .= "<span class='text-success fw-bold'>✔ Added/Updated:</span> $count_added<br>";
            if ($count_skipped_dupe > 0) $msg .= "<span class='text-warning fw-bold'>⚠ Skipped (Duplicates):</span> $count_skipped_dupe<br>";
            if ($count_skipped_unknown > 0) $msg .= "<span class='text-danger fw-bold'>✖ Skipped (Client Not Found):</span> $count_skipped_unknown<br>";
            
            if (!empty($debug_log)) {
                $msg .= "<hr class='my-2 opacity-25'><small class='text-muted'>" . implode("<br>", array_slice($debug_log, 0, 5));
                if (count($debug_log) > 5) $msg .= "<br>...and " . (count($debug_log) - 5) . " more errors.</small>";
            }

            $type = ($count_added > 0) ? 'success' : 'warning';
            redirect_with(['msg' => $msg, 'type' => $type]);
        } else {
            redirect_with(['msg' => 'Error parsing Excel file.', 'type' => 'danger']);
        }
    } else {
        redirect_with(['msg' => 'No file uploaded or file error.', 'type' => 'danger']);
    }
}

// ---------------------------------------------------------
// GET MODE AND DATA FOR EDIT
// ---------------------------------------------------------
$mode = $_GET['mode'] ?? 'view';
$editRecord = null;
$clients = getClients($mysqli);
$services = getServices($mysqli);
$processes = getProcesses($mysqli);
$teams = getTeams($mysqli);

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $result = $mysqli->query("SELECT * FROM extra_charges WHERE id = $id");
        if ($result && $result->num_rows > 0) {
            $editRecord = $result->fetch_assoc();
        }
    }
    if (!$editRecord) $mode = 'view';
}

// ---------------------------------------------------------
// SEARCH & FETCH DATA FOR DISPLAY
// ---------------------------------------------------------
$searchQuery = trim($_GET['q'] ?? '');
$whereClause = "1=1";

if ($searchQuery !== '') {
    $q_esc = $mysqli->real_escape_string($searchQuery);
    $whereClause .= " AND (
        ec.client_name LIKE '%$q_esc%' OR 
        ec.sales_incharge LIKE '%$q_esc%' OR 
        ec.particular LIKE '%$q_esc%' OR 
        ec.process LIKE '%$q_esc%' OR 
        ec.article_details LIKE '%$q_esc%' OR 
        ec.remarks LIKE '%$q_esc%' OR 
        ec.send_confirm_team LIKE '%$q_esc%' OR 
        ec.description LIKE '%$q_esc%'
    )";
}

$sql = "SELECT ec.*, cm.country, cm.currency 
        FROM extra_charges ec 
        LEFT JOIN client_master cm ON ec.client_id = cm.client_id 
        WHERE $whereClause
        ORDER BY ec.id DESC";
$result = $mysqli->query($sql);

require 'header.php'; 
?>

<!-- CUSTOM UI STYLES -->
<style>
    body { background-color: #f4f7fa; font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
    
    /* Card & General UI */
    .card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.04); margin-bottom: 24px; transition: all 0.3s ease; }
    .card-header { border-radius: 12px 12px 0 0 !important; border-bottom: none; padding: 1rem 1.25rem; }
    .card-header.bg-gradient-primary { background: linear-gradient(135deg, #1e293b 0%, #334155 100%); color: white; }
    .card-header.bg-gradient-dark { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: white; }
    
    /* Form Inputs */
    .form-label { font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 0.4rem; }
    .form-control, .form-select { border-radius: 8px; border: 1px solid #cbd5e1; padding: 0.6rem 0.8rem; font-size: 0.9rem; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
    .form-control:focus, .form-select:focus { border-color: #3b82f6; box-shadow: 0 0 0 0.25rem rgba(59, 130, 246, 0.15); }
    .form-section { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1.5rem; margin-bottom: 1.5rem; }
    
    /* Buttons */
    .btn { border-radius: 8px; font-weight: 500; padding: 0.5rem 1rem; transition: all 0.2s ease; }
    .btn:hover { transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
    .btn-icon { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; padding: 0; }
    
    /* Table Styling */
    .table-container { border-radius: 10px; overflow: auto; border: 1px solid #e2e8f0; background: white; }
    .table { margin-bottom: 0; }
    .table thead th { background-color: #6c757d !important; color: white !important; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; padding: 1rem 0.75rem; border-bottom: none !important; white-space: nowrap; position: sticky; top: 0; z-index: 1; }
    .table tbody td { padding: 0.85rem 0.75rem; vertical-align: middle; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 0.85rem; }
    .table tbody tr:hover { background-color: #f8fafc; }
    .table tbody tr:last-child td { border-bottom: none; }
    
    /* Badges & Text */
    .badge-soft-info { background-color: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; font-weight: 600; padding: 0.4em 0.8em; border-radius: 6px; }
    .badge-soft-secondary { background-color: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; font-weight: 600; padding: 0.4em 0.8em; border-radius: 6px; }
    .badge-soft-warning { background-color: #fef3c7; color: #b45309; border: 1px solid #fde68a; font-weight: 600; padding: 0.4em 0.8em; border-radius: 6px; }
    .amount-text { font-family: 'Courier New', Courier, monospace; font-weight: 700; color: #16a34a; font-size: 0.95rem; }
    .text-truncate-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; white-space: normal; line-height: 1.4; max-width: 180px; }
    
    /* Custom Scrollbar */
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>

<div class="container-fluid py-4" style="margin-top: 60px; max-width: 1600px;">
    
    <!-- HEADER SECTION -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div>
            <h2 class="fw-bold text-dark mb-1"><i class="fas fa-file-invoice-dollar text-primary me-2"></i>Extra Charges</h2>
            <p class="text-secondary mb-0 fs-6">Manage supplementary client billings, descriptions, and team approvals.</p>
        </div>
        <div class="d-flex gap-2">
            <?php if($mode === 'add' || $mode === 'edit'): ?>
                <a href="extra_charges.php" class="btn btn-secondary shadow-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back to List
                </a>
            <?php else: ?>
                 <button type="button" class="btn btn-outline-dark bg-white shadow-sm" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-cloud-upload-alt me-1 text-danger"></i> Import Excel
                </button>
                <a href="extra_charges.php?mode=add" class="btn btn-primary shadow-sm">
                    <i class="fas fa-plus me-1"></i> Add Record
                </a>
               
            <?php endif; ?>
        </div>
    </div>

    <!-- FLASH MESSAGES -->
    <?php if (!empty($_SESSION['flash_msg'])): ?>
        <div class="alert alert-<?= h($_SESSION['flash_type'] ?? 'info') ?> alert-dismissible fade show shadow-sm border-0 rounded-3 d-flex align-items-center" role="alert">
            <div class="flex-grow-1"><?= $_SESSION['flash_msg'] ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <?php if($mode === 'add' || $mode === 'edit'): ?>
    
    <!-- ADD / EDIT FORM -->
    <div class="card shadow-sm border-0 mb-4 animate__animated animate__fadeIn">
        <div class="card-header bg-gradient-primary">
            <h5 class="mb-0 fw-bold">
                <i class="fas <?= $mode === 'edit' ? 'fa-edit' : 'fa-plus-circle' ?> me-2"></i>
                <?= $mode === 'edit' ? 'Edit Charge Record' : 'Create New Extra Charge' ?>
            </h5>
        </div>
        <div class="card-body p-4 bg-white rounded-bottom">
            <form method="post" autocomplete="off" action="extra_charges.php">
                <input type="hidden" name="action" value="<?= $mode ?>">
                <?php if($mode === 'edit'): ?>
                <input type="hidden" name="id" value="<?= (int)($editRecord['id'] ?? 0) ?>">
                <?php endif; ?>

                <!-- SECTION 1: Client & Article Info -->
                <h6 class="text-primary fw-bold mb-3"><i class="fas fa-user-tie me-2"></i>Client & Article Details</h6>
                <div class="form-section row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-building me-1"></i> Client <span class="text-danger">*</span></label>
                        <select class="form-select" name="client_id" id="client_id" required>
                            <option value="" data-currency="₹">-- Select Client --</option>
                            <?php foreach($clients as $client): ?>
                                <option value="<?= $client['client_id'] ?>" 
                                    data-name="<?= h($client['client_name']) ?>" 
                                    data-currency="<?= h(getCurrencySymbol($client['currency'])) ?>"
                                    <?= (isset($editRecord['client_id']) && $editRecord['client_id'] == $client['client_id']) ? 'selected' : '' ?>>
                                    <?= h($client['client_name']) ?> (<?= h($client['country']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="client_name" id="client_name" value="<?= h($editRecord['client_name'] ?? '') ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-id-badge me-1"></i> Sales Incharge</label>
                        <input type="text" class="form-control" name="sales_incharge" placeholder="E.g. John Doe" value="<?= h($editRecord['sales_incharge'] ?? '') ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label"><i class="far fa-calendar-paper me-1"></i> Send Date</label>
                        <input type="date" class="form-control" name="send_date_client" value="<?= isset($editRecord['send_date_client']) ? date('Y-m-d', strtotime($editRecord['send_date_client'])) : '' ?>">
                    </div>

                    <div class="col-md-8">
                        <label class="form-label"><i class="fas fa-book-open me-1"></i> Article / Journal Details</label>
                        <input type="text" class="form-control" name="article_details" placeholder="Enter journal volume, issue, or article ID" value="<?= h($editRecord['article_details'] ?? '') ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-layer-group me-1"></i> Article Count</label>
                        <input type="number" class="form-control" name="article_count" placeholder="0" value="<?= (int)($editRecord['article_count'] ?? 0) ?>">
                    </div>
                </div>

                <!-- SECTION 2: Billing & Processing -->
                <h6 class="text-primary fw-bold mb-3 mt-4"><i class="fas fa-wallet me-2"></i>Service & Billing Info</h6>
                <div class="form-section row g-3">
                    <div class="col-md-5">
                        <label class="form-label"><i class="fas fa-tags me-1"></i> Particular <span class="text-danger">*</span></label>
                        <select class="form-select" name="particular" required>
                            <option value="">-- Select Service --</option>
                            <?php foreach($services as $service): ?>
                                <option value="<?= h($service['service_name']) ?>" data-amount="<?= $service['service_amount'] ?>"
                                    <?= (isset($editRecord['particular']) && $editRecord['particular'] == $service['service_name']) ? 'selected' : '' ?>>
                                    <?= h($service['service_name']) ?> 
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-cogs me-1"></i> Process</label>
                        <select class="form-select" name="process">
                            <option value="">-- Select Process --</option>
                            <?php foreach($processes as $process): ?>
                                <option value="<?= h($process['job_name']) ?>" <?= (isset($editRecord['process']) && $editRecord['process'] == $process['job_name']) ? 'selected' : '' ?>>
                                    <?= h($process['job_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label text-success"><i class="fas fa-money-bill-wave me-1"></i> Charges Amount</label>
                        <div class="input-group">
                            <?php 
                                $currentCurrency = '₹';
                                if (isset($editRecord['client_id'])) {
                                    foreach($clients as $c) {
                                        if ($c['client_id'] == $editRecord['client_id']) {
                                            $currentCurrency = getCurrencySymbol($c['currency']);
                                            break;
                                        }
                                    }
                                }
                            ?>
                            <span class="input-group-text bg-white border-end-0 text-muted" id="currency_symbol"><?= h($currentCurrency) ?></span>
                            <input type="number" step="0.01" class="form-control border-start-0 ps-0 fw-bold amount-text" name="charges" id="charges" value="<?= (float)($editRecord['charges'] ?? 0) ?>">
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: Approvals & Remarks -->
                <h6 class="text-primary fw-bold mb-3 mt-4"><i class="fas fa-clipboard-check me-2"></i>Approvals & Notes</h6>
                <div class="form-section row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><i class="far fa-calendar-check me-1"></i> Client Confirmated Date</label>
                        <input type="date" class="form-control" name="client_confirm_date" value="<?= isset($editRecord['client_confirm_date']) ? date('Y-m-d', strtotime($editRecord['client_confirm_date'])) : '' ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-users-cog me-1"></i> Send Confirmtion Team</label>
                        <select class="form-select" name="send_confirm_team">
                            <option value="">-- Select Team --</option>
                            <?php foreach($teams as $team): ?>
                                <option value="<?= h($team['team_name']) ?>" <?= (isset($editRecord['send_confirm_team']) && $editRecord['send_confirm_team'] == $team['team_name']) ? 'selected' : '' ?>>
                                    <?= h($team['team_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-calendar-check me-1"></i> Confirmation Date to Team</label>
                        <input type="date" class="form-control" name="confirm_date_team" value="<?= isset($editRecord['confirm_date_team']) ? date('Y-m-d', strtotime($editRecord['confirm_date_team'])) : '' ?>">
                    </div>

                    <div class="col-md-6 mt-4">
                        <label class="form-label"><i class="fas fa-comment-dots me-1"></i> Short Remarks</label>
                        <textarea class="form-control" name="remarks" rows="3" placeholder="Brief notes for quick viewing..."><?= h($editRecord['remarks'] ?? '') ?></textarea>
                    </div>

                    <div class="col-md-6 mt-4">
                        <label class="form-label"><i class="fas fa-align-left me-1"></i> Detailed Description</label>
                        <textarea class="form-control" name="description" rows="3" placeholder="Extensive details regarding the charges, specific requests, etc."><?= h($editRecord['description'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- ACTION BUTTONS -->
                <div class="d-flex gap-2 justify-content-end mt-4 pt-3 border-top">
                    <a href="extra_charges.php" class="btn btn-light border px-4">Cancel</a>
                    <button type="submit" class="btn btn-primary px-5">
                        <i class="fas fa-save me-2"></i> <?= $mode === 'edit' ? 'Update Record' : 'Save Record' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ONLY SHOW SEARCH BAR & GRID IF NOT IN ADD/EDIT MODE -->
    <?php if($mode !== 'add' && $mode !== 'edit'): ?>
        
        <!-- SEARCH BAR -->
        <div class="card shadow-sm border-0 mb-4 animate__animated animate__fadeIn">
            <div class="card-body p-3">
                <form method="get" class="row g-2 align-items-end" action="extra_charges.php">
                    <div class="col-md-8">
                        
                        <input type="text" name="q" class="form-control" placeholder="Sort by Client, Sales Incharge, Particular, Process, Team, Article, Remarks..." value="<?= h($searchQuery) ?>">
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1 shadow-sm"><i class="fas fa-search me-1"></i> Filter</button>
                        <a href="extra_charges.php" class="btn btn-light border flex-grow-1 shadow-sm"><i class="fas fa-times me-1"></i> Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- DATA TABLE SECTION -->
        <div class="card shadow-sm border-0">
            <div class="card-header bg-gradient-dark d-flex justify-content-between align-items-center py-3">
                <h6 class="mb-0 fw-bold text-white"><i class="fas fa-list-ul me-2"></i> Recorded Extra Charges</h6>
                <span class="badge bg-white text-dark rounded-pill shadow-sm px-3 py-2">Total Records: <?= $result->num_rows ?></span>
            </div>
            
            <div class="card-body p-0">
                <div class="table-responsive table-container" style="max-height: 700px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="text-center border-end" width="50">S.No</th>
                                <th class="border-end">Sales InCharge</th>
                                <th class="text-center border-end">Send Date</th>
                                <th class="border-end">Client Name</th>
                                <th class="border-end">Article Details</th>
                                <th class="text-center border-end">Count</th>
                                <th class="border-end">Particular</th>
                                <th class="border-end">Process</th>
                                <th class="text-end border-end">Charges</th>
                                <th class="text-center border-end">Client Confirmated Date</th>
                                <th class="text-center border-end">Send Confirmation Team</th>
                                <th class="text-center border-end">Confirmtion Date to Team</th>
                                <th class="border-end" width="150">Remarks</th>
                                <th width="200">Description</th>
                                <th class="text-center bg-white border-start shadow-sm" style="position: sticky; right: 0; z-index: 2;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): $i=0; ?>
                                <?php while ($row = $result->fetch_assoc()): $i++; ?>
                                <tr>
                                    <td class="text-center text-muted fw-bold border-end"><?= $i ?></td>
                                       <td class="border-end"><span class="text-secondary text-nowrap"><?= h($row['sales_incharge']) ?: '—' ?></span></td>
                                        <td class="text-center border-end text-nowrap">
                                        <?= formatDate($row['send_date_client']) ?>
                                    </td>
                                    <td class="border-end">
                                        <div class="fw-bold text-dark text-nowrap">
                                            <?= h($row['client_name']) ?>
                                            <?php if($row['client_id'] > 0): ?>
                                                <i class="fas fa-check-circle text-success ms-1" style="font-size: 0.7rem;" data-bs-toggle="tooltip" title="Verified Master Client"></i>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    
                                  <td class="border-end">
                                        <div class="text-truncate" style="max-width: 150px;" title="<?= h($row['article_details']) ?>">
                                            <?= h($row['article_details']) ?: '—' ?>
                                        </div>
                                    </td>
                                    <td class="text-center fw-bold text-secondary border-end"><?= (int)$row['article_count'] ?></td>
                                    <td class="border-end">
                                        <span class="badge-soft-info text-nowrap"><?= h($row['particular']) ?></span>
                                    </td>
                                    
                                    <td class="border-end">
                                        <?php if(!empty($row['process'])): ?>
                                            <span class="badge-soft-secondary text-nowrap"><?= h($row['process']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="text-end amount-text border-end">
                                        <?= h(getCurrencySymbol($row['currency'] ?? 'INR')) ?><?= number_format((float)$row['charges'], 2) ?>
                                    </td>
                                    
                                   
                                    <td class="text-center border-end text-nowrap">
                                        <?= formatDate($row['client_confirm_date']) ?>
                                    </td>

                                    <td class="text-center border-end">
                                        <?php if(!empty($row['send_confirm_team'])): ?>
                                            <span class="badge-soft-warning text-nowrap"><?= h($row['send_confirm_team']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center border-end text-nowrap">
                                        <?= formatDate($row['confirm_date_team']) ?>
                                    </td>
                                    
                                    <td class="border-end">
                                        <div class="text-truncate-2 text-muted small" title="<?= h($row['remarks']) ?>">
                                            <?= h($row['remarks']) ?: '—' ?>
                                        </div>
                                    </td>
                                    
                                    <td>
                                        <div class="text-truncate-2 text-muted small" title="<?= h($row['description']) ?>">
                                            <?= h($row['description']) ?: '—' ?>
                                        </div>
                                    </td>
                                    
                                    <td class="text-center bg-white border-start shadow-sm" style="position: sticky; right: 0;">
                                        <div class="d-flex justify-content-center gap-1">
                                            <a href="extra_charges.php?mode=edit&id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-light text-primary border shadow-sm btn-icon" data-bs-toggle="tooltip" title="Edit Record">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                            <form method="post" action="extra_charges.php" class="m-0 p-0" onsubmit="return confirm('Are you sure you want to delete this record forever?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-light text-danger border shadow-sm btn-icon" data-bs-toggle="tooltip" title="Delete Record">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="15" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-box-open fa-3x mb-3 text-light"></i>
                                            <h5 class="fw-normal">No records found</h5>
                                            <p class="mb-0">Click 'Add Record' or 'Import Data' to get started.</p>
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

<!-- BEAUTIFUL IMPORT MODAL -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            
            <div class="modal-header bg-gradient-dark text-white p-4 border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-file-excel text-success me-2 fs-4"></i> Batch Import Extra Charges</h5>
                <button type="button" class="btn-close btn-close-white opacity-75" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form action="extra_charges.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_excel">
                <div class="modal-body p-4 bg-light">
                    
                    <!-- File Upload Zone -->
                    <div class="row align-items-center bg-white p-4 rounded-3 border shadow-sm mb-4">
                        <div class="col-md-8 border-end">
                            <label class="form-label text-dark fw-bold mb-2">Select Excel File (.xlsx)</label>
                            <input type="file" name="excel_file" class="form-control form-control-lg border-secondary bg-light" accept=".xlsx" required>
                        </div>
                        <div class="col-md-4 text-center mt-3 mt-md-0">
                            <p class="text-muted small mb-2 fw-bold text-uppercase">Need the template?</p>
                            <button type="button" class="btn btn-success shadow-sm w-100" onclick="downloadSample()">
                                <i class="fas fa-download me-2"></i> Download Sample
                            </button>
                        </div>
                    </div>

                    <!-- Warning Alert -->
                    <div class="alert alert-warning border-warning shadow-sm d-flex align-items-center mb-4">
                        <i class="fas fa-exclamation-triangle fs-3 text-warning me-3"></i>
                        <div>
                            <strong>Crucial Step:</strong> The sample format downloads as a CSV file. You <u>must</u> open it in Excel, click <em>Save As</em>, and save it specifically as an <strong>Excel Workbook (*.xlsx)</strong> before uploading it here.
                        </div>
                    </div>
                    
                    <!-- Required Columns Box -->
                    <div class="bg-white p-3 rounded-3 border shadow-sm">
                        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3">
                            <i class="fas fa-columns me-2"></i> Required Column Order (Strict Match)
                        </h6>
                        <ol class="text-secondary small mb-0 fw-medium" style="column-count: 2; column-gap: 2rem;">
                            <li>S.No <span class="text-muted">(Ignored)</span></li>
                            <li>Sales Incharge</li>
                            <li>Send Date <span class="text-muted">(YYYY-MM-DD)</span></li>
                            <li><span class="text-danger fw-bold">Client Name *</span></li>
                            <li>Article Details</li>
                            <li>Count <span class="text-muted">(Number)</span></li>
                            <li>Particular</li>
                            <li>Process</li>
                            <li>Charges <span class="text-muted">(Number)</span></li>
                            <li>Client Confirm Date</li>
                            <li>Send Confirm Team</li>
                            <li>Team Confirm Date</li>
                            <li>Remarks</li>
                            <li class="text-dark fw-bold">Description</li>
                        </ol>
                    </div>

                </div>
                <div class="modal-footer bg-white p-3 border-top-0 d-flex justify-content-between">
                    <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-dark px-5 shadow-sm">
                        <i class="fas fa-cloud-upload-alt me-2"></i> Start Import
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        
        // Initialize tooltips
        var tooltipTriggerList =[].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });

        // Auto-dismiss alerts (keep success visible longer, skip danger/info)
        var alertBox = document.querySelector('.alert.alert-success');
        if (alertBox) {
            setTimeout(function() {
                var alert = new bootstrap.Alert(alertBox);
                alert.close();
            }, 6000); 
        }

        // Set client name and currency symbol when client is selected
        const clientSelect = document.getElementById('client_id');
        const clientNameInput = document.getElementById('client_name');
        const currencySymbolSpan = document.getElementById('currency_symbol');
        
        if (clientSelect) {
            clientSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                if (selectedOption.value) {
                    clientNameInput.value = selectedOption.getAttribute('data-name') || '';
                    if (currencySymbolSpan) {
                        currencySymbolSpan.innerHTML = selectedOption.getAttribute('data-currency') || '₹';
                    }
                } else {
                    clientNameInput.value = '';
                    if (currencySymbolSpan) {
                        currencySymbolSpan.innerHTML = '₹';
                    }
                }
            });
        }
    });

    function downloadSample() {
        const headers = "S.No,Sales Incharge,Send Date,Client Name,Article Details,Count,Particular,Process,Charges,Client Confirmated Date,Send Confirmation Team,Confirmation Date to team,Remarks,Description\n";
        const row1 = '1,John Doe,2026-02-27,Acme Corp,Journal Vol 1,5,Formatting,Typesetting,150.00,2026-03-01,Support Team,2026-03-02,"Rush processing requested","Client requested advanced formatting for the specific tables and figures. Proceeded with the custom rate."';
        
        const blob = new Blob([headers + row1], { type: 'text/csv;charset=utf-8;' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'extra_charges_sample.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        
        // Show sweet alert or default alert
        alert("Action Required:\n\nThe sample file downloaded is in CSV format. \nTo ensure a successful import, please open it in Microsoft Excel, click 'Save As', and save it as an 'Excel Workbook (*.xlsx)'.");
    }
</script>

</body>
</html>