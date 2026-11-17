<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db.php';

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Helper functions
if (!function_exists('h')) {
    function h($s) { 
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
    }
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

function redirect_with($params =[]) {
    $base = 'xml_clients.php'; // Ensure this matches your file name
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Season options
$season_options =[
    'Freq-Triannual', 'Bi-Monthly', 'Regular', 'Quaterly', 'Monthly', 'Weekly', 'Daily', 'Other'
];

// Fetch client list for dropdown
function getClients($mysqli) {
    $clients =[];
    $sql = "SELECT client_id, client_name, team_client_name, company_name, production_email FROM client_master WHERE status = 'Active' ORDER BY team_client_name";
    $result = $mysqli->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $clients[] = $row;
        }
    }
    return $clients;
}

// ---------------------------------------------------------
// HANDLE EXCEL IMPORT LOGIC
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        // Locate SimpleXLSX Library
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'SimpleXLSX library not found.', 'type' => 'danger']);
        }
        require_once $libPath;

        $tmpFile = $_FILES['excel_file']['tmp_name'];
        $fileName = $_FILES['excel_file']['name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $rows = null;

        // 1. Try SimpleXLSX for .xlsx files
        if ($ext === 'xlsx' || $ext === '') {
            if (class_exists('Shuchkin\SimpleXLSX')) {
                $xlsx = Shuchkin\SimpleXLSX::parse($tmpFile);
                if ($xlsx) {
                    $rows = $xlsx->rows();
                }
            }
        }

        // 2. If not parsed and ext is xls, try SimpleXLS
        if (!$rows && ($ext === 'xls' || $ext === '')) {
            $xlsLib = dirname(__DIR__) . '/simplexls-master/src/SimpleXLS.php';
            if (!file_exists($xlsLib)) $xlsLib = 'simplexls-master/src/SimpleXLS.php';
            if (file_exists($xlsLib)) {
                require_once $xlsLib;
                if (class_exists('Shuchkin\SimpleXLS')) {
                    $xls = Shuchkin\SimpleXLS::parse($tmpFile);
                    if ($xls) {
                        $rows = $xls->rows();
                    }
                }
            }
        }

        // 3. Fallback: Parse as CSV or text format
        if (!$rows) {
            $csvRows = [];
            if (($handle = fopen($tmpFile, "r")) !== FALSE) {
                while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
                    if (count($data) === 1 && strpos($data[0], ';') !== false) {
                        $data = str_getcsv($data[0], ';');
                    } elseif (count($data) === 1 && strpos($data[0], "\t") !== false) {
                        $data = str_getcsv($data[0], "\t");
                    }
                    $csvRows[] = $data;
                }
                fclose($handle);
            }
            if (!empty($csvRows) && count($csvRows) > 1) {
                $rows = $csvRows;
            }
        }

        // 4. Final attempt with SimpleXLSX if not yet parsed
        if (!$rows && class_exists('Shuchkin\SimpleXLSX')) {
            $xlsx = Shuchkin\SimpleXLSX::parse($tmpFile);
            if ($xlsx) {
                $rows = $xlsx->rows();
            }
        }

        if ($rows && is_array($rows)) {
            // Pre-fetch all clients to map Name to ID
            $clientMap =[];
            $c_res = $mysqli->query("SELECT client_id, client_name FROM client_master");
            while($c = $c_res->fetch_assoc()){
                $clientMap[strtolower(trim((string)$c['client_name']))] = $c['client_id'];
            }

            $imported_count = 0;
            $updated_count = 0;
            $skipped_count = 0;
            $error_details =[];
            
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip header

                // COLUMN MAPPING (Based on the image order)
                // 0: S.No (Ignored)
                // 1: Client Name
                // 2: Journal Name
                // 3: Status
                // 4: Company Name (Ignored in DB insert, fetched from master)
                // 5: Season
                // 6: Mail ID(Production) (Ignored in DB insert, fetched from master)
                // 7: Remarks
                // 8: DTD
                
                $client_name = trim($r[1] ?? '');
                $journal_name = trim($r[2] ?? '');
                $status = trim($r[3] ?? '');
                $season = trim($r[5] ?? '');
                $remarks = trim($r[7] ?? '');
                $dtd = trim($r[8] ?? '');
                
                if (empty($status)) $status = 'Active';

                if (empty($client_name) || empty($journal_name)) {
                    $skipped_count++;
                    $error_details[] = "Row " . ($k + 1) . ": Missing Client or Journal Name";
                    continue;
                }

                $c_key = strtolower($client_name);
                if (!isset($clientMap[$c_key])) {
                    // Auto-create client in client_master if not existing!
                    $company_name = trim($r[4] ?? '');
                    $prod_email = trim($r[6] ?? '');
                    $insClient = $mysqli->prepare("INSERT INTO client_master (client_name, company_name, production_email, status, created_date, template, tds_percentage, igst) VALUES (?, ?, ?, 'Active', NOW(), '', 0.00, 0.00)");
                    if ($insClient) {
                        $insClient->bind_param("sss", $client_name, $company_name, $prod_email);
                        if ($insClient->execute()) {
                            $client_id = $insClient->insert_id;
                            $clientMap[$c_key] = $client_id;
                        }
                        $insClient->close();
                    }
                    if (!isset($clientMap[$c_key])) {
                        $insClient2 = $mysqli->prepare("INSERT INTO client_master (client_name, status, created_date) VALUES (?, 'Active', NOW())");
                        if ($insClient2) {
                            $insClient2->bind_param("s", $client_name);
                            if ($insClient2->execute()) {
                                $client_id = $insClient2->insert_id;
                                $clientMap[$c_key] = $client_id;
                            }
                            $insClient2->close();
                        }
                    }
                    if (!isset($clientMap[$c_key])) {
                        $skipped_count++;
                        $error_details[] = "Row " . ($k + 1) . ": Client '$client_name' could not be added to Client Master";
                        continue;
                    }
                }
                
                $client_id = $clientMap[$c_key];

                // Sync company_name and production_email if provided in Excel
                $company_name = trim($r[4] ?? '');
                $prod_email = trim($r[6] ?? '');
                if (!empty($company_name) || !empty($prod_email)) {
                    $updC = $mysqli->prepare("UPDATE client_master SET company_name = COALESCE(NULLIF(?, ''), company_name), production_email = COALESCE(NULLIF(?, ''), production_email) WHERE client_id = ?");
                    if ($updC) {
                        $updC->bind_param("ssi", $company_name, $prod_email, $client_id);
                        $updC->execute();
                        $updC->close();
                    }
                }

                // Check if entry already exists
                $checkStmt = $mysqli->prepare("SELECT id FROM xml_clients WHERE client_id = ? AND journal_name = ?");
                $checkStmt->bind_param("is", $client_id, $journal_name);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->num_rows > 0;
                $checkStmt->close();

                if (!$exists) {
                    $insertStmt = $mysqli->prepare("INSERT INTO xml_clients (client_id, journal_name, status, season, remarks, dtd, created_date) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $insertStmt->bind_param("isssss", $client_id, $journal_name, $status, $season, $remarks, $dtd);
                    if ($insertStmt->execute()) $imported_count++;
                    else {
                        $skipped_count++;
                        $error_details[] = "Row " . ($k + 1) . ": " . $mysqli->error;
                    }
                    $insertStmt->close();
                } else {
                    $updateStmt = $mysqli->prepare("UPDATE xml_clients SET status = ?, season = ?, remarks = ?, dtd = ?, updated_date = NOW() WHERE client_id = ? AND journal_name = ?");
                    $updateStmt->bind_param("ssssis", $status, $season, $remarks, $dtd, $client_id, $journal_name);
                    if ($updateStmt->execute()) $updated_count++;
                    $updateStmt->close();
                }
            }
            
            $msg = "Process Complete.<br><strong>Imported:</strong> $imported_count <br><strong>Updated:</strong> $updated_count <br><strong>Skipped:</strong> $skipped_count";
            if (!empty($error_details)) {
                $msg .= "<br><small class='text-danger'>" . implode("<br>", array_slice($error_details, 0, 5)) . "</small>";
            }
            redirect_with(['msg' => $msg, 'type' => ($imported_count>0 || $updated_count>0) ? 'success' : 'warning']);
        } else {
            $err = (method_exists('Shuchkin\SimpleXLSX', 'parseError')) ? Shuchkin\SimpleXLSX::parseError() : 'Parse Error';
            redirect_with(['msg' => $err, 'type' => 'danger']);
        }
    } else {
        redirect_with(['msg' => 'No file uploaded or file error.', 'type' => 'danger']);
    }
}

// ---------------------------------------------------------
// HANDLE ADD / EDIT / DELETE / TOGGLE LOGIC
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $client_id = (int)trim($_POST['client_id'] ?? '');
        $journal_name = trim($_POST['journal_name'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');
        $season = trim($_POST['season'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $dtd = trim($_POST['dtd'] ?? '');
        $production_email = trim($_POST['production_email'] ?? '');
        
        if ($client_id === 0) redirect_with(['msg' => 'Client selection is required.', 'type' => 'danger']);
        if (empty($journal_name)) redirect_with(['msg' => 'Journal Name is required.', 'type' => 'danger']);
        
        if ($production_email !== '') {
            $upd_mail = $mysqli->prepare("UPDATE client_master SET production_email = ? WHERE client_id = ?");
            $upd_mail->bind_param("si", $production_email, $client_id);
            $upd_mail->execute();
            $upd_mail->close();
        }
        
        if ($action === 'add') {
            $check_stmt = $mysqli->prepare("SELECT id FROM xml_clients WHERE client_id = ? AND journal_name = ?");
            $check_stmt->bind_param("is", $client_id, $journal_name);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                redirect_with(['msg' => "This Journal already exists for the selected client.", 'type' => 'danger']);
            }
            $check_stmt->close();
            
            $stmt = $mysqli->prepare("INSERT INTO xml_clients (client_id, journal_name, status, season, remarks, dtd, created_date) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("isssss", $client_id, $journal_name, $status, $season, $remarks, $dtd);
            
            if ($stmt->execute()) redirect_with(['msg' => "Entry added successfully", 'type' => 'success']);
            else redirect_with(['msg' => 'Error adding entry: ' . $stmt->error, 'type' => 'danger']);
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $check_stmt = $mysqli->prepare("SELECT id FROM xml_clients WHERE client_id = ? AND journal_name = ? AND id != ?");
                $check_stmt->bind_param("isi", $client_id, $journal_name, $id);
                $check_stmt->execute();
                if ($check_stmt->get_result()->num_rows > 0) {
                    redirect_with(['msg' => 'Entry already exists for this client with the same journal name.', 'type' => 'danger']);
                }
                $check_stmt->close();
                
                $stmt = $mysqli->prepare("UPDATE xml_clients SET client_id = ?, journal_name = ?, status = ?, season = ?, remarks = ?, dtd = ?, updated_date = NOW() WHERE id = ?");
                $stmt->bind_param("isssssi", $client_id, $journal_name, $status, $season, $remarks, $dtd, $id);
                
                if ($stmt->execute()) redirect_with(['msg' => "Entry updated successfully", 'type' => 'success']);
                else redirect_with(['msg' => 'Error updating entry: ' . $stmt->error, 'type' => 'danger']);
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $del = $mysqli->prepare("DELETE FROM xml_clients WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            redirect_with(['msg' => "Entry deleted", 'type' => 'warning']);
        }
    }
    
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT status FROM xml_clients WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($current_status);
            $res->fetch();
            $res->close();
            
            $newStatus = ($current_status === 'Active') ? 'Inactive' : 'Active';
            $upd = $mysqli->prepare("UPDATE xml_clients SET status = ?, updated_date = NOW() WHERE id = ?");
            $upd->bind_param("si", $newStatus, $id);
            $upd->execute();
            redirect_with(['msg' => "Status changed to $newStatus", 'type' => 'info']);
        }
    }
}

$mode = $_GET['mode'] ?? 'view';
$editEntry = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT crd.*, cm.client_name, cm.team_client_name, cm.company_name, cm.production_email FROM xml_clients crd JOIN client_master cm ON crd.client_id = cm.client_id WHERE crd.id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editEntry = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editEntry) $mode = 'view';
}

$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'cm.client_name';
$dir = strtolower($_GET['dir'] ?? 'asc');

$sql = "SELECT crd.id, crd.client_id, crd.journal_name, crd.remarks, crd.dtd, crd.season, crd.status, crd.created_date, 
               cm.client_name, cm.team_client_name, cm.company_name, cm.production_email 
        FROM xml_clients crd 
        JOIN client_master cm ON crd.client_id = cm.client_id";
$cond = []; $params =[]; $types = '';

if ($q !== '') {
    $cond[] = "(cm.client_name LIKE ? OR cm.team_client_name LIKE ? OR cm.company_name LIKE ? OR crd.remarks LIKE ? OR crd.dtd LIKE ? OR crd.journal_name LIKE ? OR cm.production_email LIKE ?)";
    $like = "%$q%";
    $params = array_fill(0, 7, $like);
    $types .= str_repeat('s', 7);
}
if ($fstatus !== '') {
    $cond[] = "crd.status = ?";
    $params[] = $fstatus;
    $types .= 's';
}
if (!empty($cond)) $sql .= " WHERE " . implode(" AND ", $cond);
$sql .= " ORDER BY $sort $dir";

if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $entries = $stmt->get_result();
} else {
    $entries = $mysqli->query($sql);
}

$clients = getClients($mysqli);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XML Client Configuration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Global Tapasi Stylesheet -->
    <link rel="stylesheet" href="../custom_style.css">
    <style>
        /* Ash Page Header Banner (Matching Navbar Color) */
        .page-title-container {
            background: var(--ash, #6c757d);
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title-container h1,
        .page-title-container h2,
        .page-title-container h3,
        .page-title-container h4,
        .page-title-container h5,
        .page-title-container h6 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title-container p {
            color: rgba(255, 255, 255, 0.85);
            margin: 0;
        }

        .page-title-container i {
            color: white;
        }

        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 13px; }
        .main-content { padding: 20px; max-width: 1600px; margin: 0 auto; }
        .table th { background-color: var(--ash, #6c757d) !important; color: #ffffff !important; border: none; padding: 10px; font-weight: 600; white-space: nowrap;}
        .table td { vertical-align: middle; padding: 8px 10px; }
        .card { box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); border: 1px solid rgba(0, 0, 0, 0.125); }
        .text-truncate-custom { max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; cursor: pointer; }
        .readonly-box { background-color: #e9ecef; border: 1px solid #ced4da; padding: 0.375rem 0.75rem; border-radius: 0.25rem; min-height: 35px; }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show">
            <?= $_GET['msg'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
        
        <!-- ============================== ENTRY FORM ============================== -->
        <div class="page-title-container" style="background-color: #6c757d !important; background: #6c757d !important; color: #ffffff !important; padding: 12px 20px !important; border-radius: 12px !important; margin-bottom: 20px !important; display: flex !important; justify-content: space-between !important; align-items: center !important;">
            <h5 class="fw-bold mb-0 text-white"><i class="fas fa-file-code me-2"></i><?= $mode === 'edit' ? 'Edit XML Client' : 'Add New XML Client' ?></h5>
            <a href="xml_clients.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?><input type="hidden" name="id" value="<?= (int)($editEntry['id'] ?? 0) ?>"><?php endif; ?>

                    <!-- Form Row 1: Client, Journal, Status -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Client Name <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="client_id" name="client_id" required <?= $mode === 'edit' ? 'disabled' : '' ?>>
                                <option value="">Select Client...</option>
                                <?php 
                                $displayed_clients = [];
                                foreach($clients as $client): 
                                    $display_name = !empty($client['team_client_name']) ? $client['team_client_name'] : $client['client_name'];
                                    
                                    if (($mode === 'edit' && isset($editEntry['client_id']) && $editEntry['client_id'] == $client['client_id']) || !in_array($display_name, $displayed_clients)):
                                        $displayed_clients[] = $display_name;
                                ?>
                                    <option value="<?= $client['client_id'] ?>" 
                                        <?= ($mode === 'edit' && isset($editEntry['client_id']) && $editEntry['client_id'] == $client['client_id']) ? 'selected' : '' ?>
                                        data-company="<?= h($client['company_name'] ?? '') ?>"
                                        data-email="<?= h($client['production_email'] ?? '') ?>">
                                        <?= h($display_name) ?>
                                    </option>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </select>
                            <?php if($mode === 'edit'): ?>
                                <input type="hidden" name="client_id" value="<?= $editEntry['client_id'] ?>">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Journal Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" name="journal_name" value="<?= isset($editEntry['journal_name']) ? h($editEntry['journal_name']) : '' ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Status</label>
                            <select class="form-select form-select-sm" name="status">
                                <option value="Active" <?= (isset($editEntry['status']) && $editEntry['status'] === 'Active') ? 'selected' : '' ?>>Active</option>
                                <option value="Inactive" <?= (isset($editEntry['status']) && $editEntry['status'] === 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <!-- Form Row 2: Company Name, Season, Mail ID -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted">Company Name (Auto-fetched)</label>
                            <div class="readonly-box text-muted" id="company_display">
                                <?= $mode === 'edit' ? h($editEntry['company_name']) : 'Select a client first...' ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Season</label>
                            <select class="form-select form-select-sm" name="season">
                                <option value="">-- Select Season --</option>
                                <?php foreach($season_options as $option): ?>
                                    <option value="<?= h($option) ?>" <?= (isset($editEntry['season']) && $editEntry['season'] === $option) ? 'selected' : '' ?>><?= h($option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold" for="production_email">Mail ID(Production)</label>
                            <input type="text" class="form-control form-control-sm" name="production_email" id="production_email" placeholder="Enter production mail ID..." value="<?= $mode === 'edit' ? h($editEntry['production_email'] ?? '') : '' ?>">
                        </div>
                    </div>

                    <!-- Form Row 3: Remarks, DTD -->
                    <div class="row mb-3">
                        <div class="col-md-8">
                            <label class="form-label fw-bold">Remarks</label>
                            <input type="text" class="form-control form-control-sm" name="remarks" value="<?= isset($editEntry['remarks']) ? h($editEntry['remarks']) : '' ?>" placeholder="Special instructions...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">DTD</label>
                            <input type="text" class="form-control form-control-sm" name="dtd" value="<?= isset($editEntry['dtd']) ? h($editEntry['dtd']) : '' ?>" placeholder="e.g. 3 Days">
                        </div>
                    </div>

                    <hr>
                    <button type="submit" class="btn btn-primary btn-sm px-4"><i class="fas fa-save me-1"></i> <?= $mode === 'edit' ? 'Update Entry' : 'Save Entry' ?></button>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- ============================== VIEW/GRID MODE ============================== -->
        <div class="page-title-container" style="background-color: #6c757d !important; background: #6c757d !important; color: #ffffff !important; padding: 12px 20px !important; border-radius: 12px !important; margin-bottom: 20px !important; display: flex !important; justify-content: space-between !important; align-items: center !important;">
            <div>
                <h5 class="fw-bold mb-0 mb-0 text-white"><i class="fas fa-list me-2"></i>XML Clients</h5>
            </div>
            <div>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-excel me-2"></i> Import Excel
                </button>
                <a href="xml_clients.php?mode=add" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus me-1"></i> Add Entry
                </a>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-body p-2">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <!--label class="form-label mb-0 fw-bold text-muted" style="font-size:11px;">Search</label-->
                        <input type="text" name="q" class="form-control form-control-sm" placeholder="Sort by Client, Journal, etc..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <!--label class="form-label mb-0 fw-bold text-muted" style="font-size:11px;">Status</label-->
                        <select name="status" class="form-select form-select-sm">
                            <option value="">Sort by Status</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-4 text-end ms-auto">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filter</button>
                        <a href="xml_clients.php" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i> Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- GRID EXACTLY MATCHING THE REQUESTED ORDER -->
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-bordered mb-0">
                        <thead>
                            <tr>
                                <th>S.No</th>
                                <th>Client Name</th>
                                <th>Journal Name</th>
                                <th>Status</th>
                                <th>Company Name</th>
                                <th>Season</th>
                                <th>Mail ID(Production)</th>
                                <th>Remarks</th>
                                <th>DTD</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($entries && $entries->num_rows > 0): $i = 1;?>
                                <?php while($row = $entries->fetch_assoc()): ?>
                                <tr>
                                    <td class="text-center fw-bold"><?= $i++ ?></td>
                                    <td class="fw-bold text-primary"><?= h($row['team_client_name'] ?: $row['client_name']) ?></td>
                                    <td><?= h($row['journal_name']) ?: '-' ?></td>
                                    <td class="text-center">
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $row['status'] === 'Active' ? 'btn-success' : 'btn-danger' ?>" style="font-size:10px; padding:2px 5px;">
                                                <?= h($row['status']) ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td><span class="text-truncate-custom" title="<?= h($row['company_name']) ?>"><?= h($row['company_name']) ?: '-' ?></span></td>
                                    <td><?= h($row['season']) ?: '-' ?></td>
                                    <td><span class="text-truncate-custom" title="<?= h($row['production_email']) ?>"><?= h($row['production_email']) ?: '-' ?></span></td>
                                    <td><span class="text-truncate-custom" title="<?= h($row['remarks']) ?>"><?= h($row['remarks']) ?: '-' ?></span></td>
                                    <td><?= h($row['dtd']) ?: '-' ?></td>
                                    
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <a href="xml_clients.php?mode=edit&id=<?= (int)$row['id'] ?>" class="btn btn-warning"><i class="fas fa-edit"></i></a>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this entry?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="10" class="text-center py-4 text-muted"><i class="fas fa-inbox fa-2x mb-2"></i><h6>No records found</h6></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============================== IMPORT MODAL ============================== -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title fw-bold"><i class="fas fa-file-excel me-2"></i>Import Excel (Matching Grid Order)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <label class="form-label fw-bold">Select Excel or CSV File (.xlsx, .xls, .csv)</label>
                                <input type="file" name="excel_file" class="form-control" accept=".xlsx, .xls, .csv" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Download Format</label>
                                <button type="button" class="btn btn-outline-success w-100" onclick="downloadSample()">
                                    <i class="fas fa-download me-1"></i> Sample Template
                                </button>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3" style="font-size: 13px;">
                            <h6 class="fw-bold"><i class="fas fa-info-circle me-1"></i> Column Order Must Be Exactly:</h6>
                            <ol class="mb-2">
                                <li><strong>S.No</strong> (Ignored)</li>
                                <li><strong>Client Name</strong> (Required - Must exactly match Client Master)</li>
                                <li><strong>Journal Name</strong> (Required)</li>
                                <li><strong>Status</strong> (Active / Inactive)</li>
                                <li><strong>Company Name</strong> (Ignored - DB auto fetches this)</li>
                                <li><strong>Season</strong></li>
                                <li><strong>Mail ID(Production)</strong> (Ignored - DB auto fetches this)</li>
                                <li><strong>Remarks</strong></li>
                                <li><strong>DTD</strong></li>
                            </ol>
                            <p class="mb-0 text-danger"><i class="fas fa-exclamation-triangle"></i> Note: If the Client Name and Journal Name match an existing row, it will UPDATE. Otherwise, it will INSERT.</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-dark btn-sm"><i class="fas fa-upload me-1"></i> Import Now</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form Auto-Fill Client Data
        document.addEventListener('DOMContentLoaded', function() {
            const clientSelect = document.getElementById('client_id');
            const companyDisplay = document.getElementById('company_display');
            const emailInput = document.getElementById('production_email');
            
            if (clientSelect) {
                clientSelect.addEventListener('change', function() {
                    const opt = this.options[this.selectedIndex];
                    const comp = opt.getAttribute('data-company');
                    const email = opt.getAttribute('data-email');
                    
                    companyDisplay.textContent = comp || 'N/A';
                    if (emailInput && (clientSelect.getAttribute('data-user-selected') === 'true' || !emailInput.value)) {
                        emailInput.value = email || '';
                    }
                });
                clientSelect.addEventListener('click', function() {
                    this.setAttribute('data-user-selected', 'true');
                });
                if (clientSelect.value && emailInput && !emailInput.value) {
                    clientSelect.dispatchEvent(new Event('change'));
                }
            }
        });

        // Download Excel Sample ensuring it matches Grid exactl
       function downloadSample() {
            const header = "S.No,Client Name,Journal Name,Status,Company Name,Season,Mail ID(Production),Remarks,DTD\n";
            const row1 = "1,Springer,Nature,Active,Springer Nature,Regular,prod@springer.com,Priority,2 days\n";
            const row2 = "2,Elsevier,Cell,Active,Elsevier B.V,Monthly,support@elsevier.com,,3 days";
            
            const blob = new Blob([header + row1 + row2], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'xml_clients_import_format.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>