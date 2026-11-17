<?php
require 'db.php'; 
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Start session if not started
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ---------------------------------------------------------
// HELPER FUNCTIONS
// ---------------------------------------------------------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

function redirect_with($params =[]) {
    $base = 'inactive_clients.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Get all active clients for dropdown
function getActiveClients($mysqli) {
    $clients =[];
    $sql = "SELECT client_id, client_name, country, currency FROM client_master WHERE status = 'Active' ORDER BY client_name";
    $result = $mysqli->query($sql);
    while($row = $result->fetch_assoc()) {
        $clients[] = $row;
    }
    return $clients;
}

// ---------------------------------------------------------
// HANDLE CRUD OPERATIONS
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ADD NEW INACTIVE CLIENT (Mark active client as inactive)
    if ($action === 'add') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $client_name = trim($_POST['client_name'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $currency = trim($_POST['currency'] ?? '');
        $journal_name = trim($_POST['journal_name'] ?? '');
        $frequency = trim($_POST['frequency'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $year_stopped = trim($_POST['year_stopped'] ?? '');
        $last_received_month_year = trim($_POST['last_received_month_year'] ?? '');
        $last_file_receive_date = !empty($_POST['last_file_receive_date']) ? date('Y-m-d', strtotime($_POST['last_file_receive_date'])) : NULL;
        
        if ($client_id == 0) {
            redirect_with(['msg' => 'Please select a client.', 'type' => 'danger']);
        }
        
        // Get client details if not provided
        if (empty($client_name)) {
            $client_res = $mysqli->query("SELECT client_name, country, currency FROM client_master WHERE client_id = $client_id");
            if ($client_res && $client_res->num_rows > 0) {
                $client_row = $client_res->fetch_assoc();
                $client_name = $mysqli->real_escape_string($client_row['client_name']);
                $country = $country ?: $mysqli->real_escape_string($client_row['country']);
                $currency = $currency ?: $mysqli->real_escape_string($client_row['currency']);
            }
        } else {
            $client_name = $mysqli->real_escape_string($client_name);
        }
        
        // Begin transaction
        $mysqli->begin_transaction();
        
        try {
            // 1. Update client_master status to Inactive (only fields that exist in client_master)
            $dateParam = $last_file_receive_date ? "'$last_file_receive_date'" : "NULL";
            $updSql = "UPDATE client_master SET 
                       country = '$country',
                       currency = '$currency',
                       frequency = '$frequency',
                       status = 'Inactive'
                       WHERE client_id = $client_id";
            
            if (!$mysqli->query($updSql)) {
                throw new Exception("Error updating client: " . $mysqli->error);
            }
            
            // 2. Insert into inactive_clients_data history (fields not in client_master)
            $logSql = "INSERT INTO inactive_clients_data 
                      (client_id, client_name, country, currency, journal_name, frequency, 
                       year_stopped, last_received_month_year, reason, last_file_receive_date, created_at) 
                      VALUES ($client_id, '$client_name', '$country', '$currency', '$journal_name', '$frequency', 
                              '$year_stopped', '$last_received_month_year', '$reason', $dateParam, NOW())";
            
            if (!$mysqli->query($logSql)) {
                throw new Exception("Error logging inactive client: " . $mysqli->error);
            }
            
            $mysqli->commit();
            redirect_with(['msg' => "Client marked as inactive successfully.", 'type' => 'success']);
            
        } catch (Exception $e) {
            $mysqli->rollback();
            redirect_with(['msg' => $e->getMessage(), 'type' => 'danger']);
        }
    }
    
    // EDIT INACTIVE CLIENT
    if ($action === 'edit') {
        $history_id = (int)($_POST['history_id'] ?? 0);
        $client_id = (int)($_POST['client_id'] ?? 0);
        $client_name = trim($_POST['client_name'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $currency = trim($_POST['currency'] ?? '');
        $journal_name = trim($_POST['journal_name'] ?? '');
        $frequency = trim($_POST['frequency'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $year_stopped = trim($_POST['year_stopped'] ?? '');
        $last_received_month_year = trim($_POST['last_received_month_year'] ?? '');
        $last_file_receive_date = !empty($_POST['last_file_receive_date']) ? date('Y-m-d', strtotime($_POST['last_file_receive_date'])) : NULL;
        
        if (empty($client_name) && $client_id > 0) {
            $cnRes = $mysqli->query("SELECT client_name FROM client_master WHERE client_id = $client_id");
            if ($cnRes && $cnRes->num_rows > 0) {
                $client_name = $mysqli->real_escape_string($cnRes->fetch_assoc()['client_name']);
            }
        }

        if ($client_id > 0) {
            $dateParam = $last_file_receive_date ? "'$last_file_receive_date'" : "NULL";
            
            // Update client_master (Only existing fields)
            $updSql = "UPDATE client_master SET 
                       country = '$country',
                       currency = '$currency',
                       frequency = '$frequency'
                       WHERE client_id = $client_id";
            
            if ($mysqli->query($updSql)) {
                // Update the latest record in inactive_clients_data, or insert if it doesn't exist
                if ($history_id > 0) {
                    $updLogSql = "UPDATE inactive_clients_data SET 
                                 country = '$country',
                                 currency = '$currency',
                                 journal_name = '$journal_name',
                                 frequency = '$frequency',
                                 year_stopped = '$year_stopped',
                                 last_received_month_year = '$last_received_month_year',
                                 reason = '$reason',
                                 last_file_receive_date = $dateParam
                                 WHERE id = $history_id";
                    $mysqli->query($updLogSql);
                } else {
                    $insLogSql = "INSERT INTO inactive_clients_data 
                                 (client_id, client_name, country, currency, journal_name, frequency, 
                                  year_stopped, last_received_month_year, reason, last_file_receive_date, created_at) 
                                 VALUES ($client_id, '$client_name', '$country', '$currency', '$journal_name', '$frequency', 
                                         '$year_stopped', '$last_received_month_year', '$reason', $dateParam, NOW())";
                    $mysqli->query($insLogSql);
                }
                redirect_with(['msg' => "Inactive client record updated successfully.", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error updating record: ' . $mysqli->error, 'type' => 'danger']);
            }
        }
    }
    
    // DELETE INACTIVE CLIENT RECORD (from history only, not from master)
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $client_id = (int)($_POST['client_id'] ?? 0);
        
        if ($id > 0) {
            // Get client name for message
            $res = $mysqli->query("SELECT client_name FROM inactive_clients_data WHERE id = $id");
            $client_name = ($res && $res->num_rows > 0) ? $res->fetch_assoc()['client_name'] : 'Unknown';
            
            // Delete from history only
            $del = $mysqli->query("DELETE FROM inactive_clients_data WHERE id = $id");
            
            if ($del) {
                redirect_with(['msg' => "History record for '$client_name' deleted successfully.", 'type' => 'warning']);
            } else {
                redirect_with(['msg' => 'Error deleting record.', 'type' => 'danger']);
            }
        }
    }
    
    // REACTIVATE CLIENT (Change status back to Active)
    if ($action === 'reactivate') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        
        if ($client_id > 0) {
            // Get client name
            $res = $mysqli->query("SELECT client_name FROM client_master WHERE client_id = $client_id");
            $client_name = ($res && $res->num_rows > 0) ? $res->fetch_assoc()['client_name'] : 'Unknown';
            
            // Update status to Active
            $updSql = "UPDATE client_master SET status = 'Active' WHERE client_id = $client_id";
            
            if ($mysqli->query($updSql)) {
                redirect_with(['msg' => "Client '$client_name' reactivated successfully.", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error reactivating client: ' . $mysqli->error, 'type' => 'danger']);
            }
        }
    }
}

// ---------------------------------------------------------
// HANDLE IMPORT LOGIC (XLSX Format)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        // 1. Locate the SimpleXLSX Library
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'Library file not found.', 'type' => 'danger']);
        }
        
        require_once $libPath;

        // 2. Initialize Parser
        $xlsx = null;
        if (class_exists('Shuchkin\SimpleXLSX')) {
            $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        } elseif (class_exists('SimpleXLSX')) {
            $xlsx = SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        } else {
            redirect_with(['msg' => 'Class SimpleXLSX not found.', 'type' => 'danger']);
        }

        // 3. Process Rows
        if ($xlsx) {
            $rows = $xlsx->rows();
            $updated_count = 0;
            $skipped_count = 0;
            
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header Row

                // Shifted indices to match the new 12-column template
                $sno         = trim($r[0] ?? ''); // Ignored
                $client_name = trim($r[1] ?? ''); // Index 1
                if (empty($client_name)) continue;
                
                $country     = $mysqli->real_escape_string($r[2] ?? '');
                $currency    = $mysqli->real_escape_string($r[3] ?? '');
                $journal     = $mysqli->real_escape_string($r[4] ?? '');
                $services    = trim($r[5] ?? ''); // Ignored for insertion
                $freq        = $mysqli->real_escape_string($r[6] ?? '');
                $year_stop   = $mysqli->real_escape_string($r[7] ?? '');
                $last_m_y    = $mysqli->real_escape_string($r[8] ?? '');
                $reason      = $mysqli->real_escape_string($r[9] ?? '');
                $raw_date    = $r[10] ?? null; 
                
                $last_date   = NULL;
                if (!empty($raw_date)) {
                    if (is_numeric($raw_date)) {
                        $last_date = date('Y-m-d', \Shuchkin\SimpleXLSX::unixstamp($raw_date));
                    } else {
                        $ts = strtotime($raw_date);
                        if ($ts !== false) $last_date = date('Y-m-d', $ts);
                    }
                }

                // Col L (Index 11): Status
                $status = trim($r[11] ?? '');
                if (empty($status)) {
                    $status = 'Inactive';
                } else {
                    $status = $mysqli->real_escape_string($status);
                }

                // Check if exists in master
                $checkStmt = $mysqli->prepare("SELECT client_id FROM client_master WHERE client_name = ?");
                $checkStmt->bind_param("s", $client_name);
                $checkStmt->execute();
                $checkRes = $checkStmt->get_result();
                
                if ($checkRes->num_rows > 0) {
                    $row = $checkRes->fetch_assoc();
                    $cid = $row['client_id'];
                    $checkStmt->close();

                    $mysqli->begin_transaction();
                    
                    try {
                        // A. UPDATE client_master
                        $dateParam = $last_date ? "'$last_date'" : "NULL";
                        $sqlUpd = "UPDATE client_master SET 
                                   country = '$country', 
                                   currency = '$currency', 
                                   frequency = '$freq', 
                                   status = '$status'
                                   WHERE client_id = $cid";
                        
                        if (!$mysqli->query($sqlUpd)) {
                            throw new Exception("Update failed: " . $mysqli->error);
                        }

                        // B. INSERT INTO inactive_clients_data
                        $sqlLog = "INSERT INTO inactive_clients_data 
                                  (client_id, client_name, country, currency, journal_name, frequency, 
                                   year_stopped, last_received_month_year, reason, last_file_receive_date, created_at) 
                                  VALUES ($cid, '$client_name', '$country', '$currency', '$journal', '$freq', 
                                          '$year_stop', '$last_m_y', '$reason', $dateParam, NOW())";
                        
                        if (!$mysqli->query($sqlLog)) {
                            throw new Exception("Log insert failed: " . $mysqli->error);
                        }
                        
                        $mysqli->commit();
                        $updated_count++;
                        
                    } catch (Exception $e) {
                        $mysqli->rollback();
                        error_log("Error processing row $k: " . $e->getMessage());
                    }
                } else {
                    $skipped_count++;
                    $checkStmt->close();
                }
            }
            
            $msg = "Process Complete. <br><strong>Updated & Logged:</strong> $updated_count clients. <br><strong>Skipped (Not in Master):</strong> $skipped_count rows.";
            $type = ($updated_count > 0) ? 'success' : 'warning';
            redirect_with(['msg' => $msg, 'type' => $type]);

        } else {
            $err = (method_exists('Shuchkin\SimpleXLSX', 'parseError')) ? Shuchkin\SimpleXLSX::parseError() : 'Unknown Error';
            redirect_with(['msg' => 'SimpleXLSX Parse Error: ' . $err, 'type' => 'danger']);
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
$activeClients = getActiveClients($mysqli);

if ($mode === 'edit') {
    // Rely primarily on client_id for editing
    $client_id = (int)($_GET['client_id'] ?? 0);
    if ($client_id > 0) {
        $result = $mysqli->query("SELECT 
                cm.client_id, cm.client_name, cm.country, cm.currency, cm.frequency, cm.status,
                icd.id as history_id, icd.journal_name, icd.reason, icd.year_stopped, 
                icd.last_received_month_year, icd.last_file_receive_date
            FROM client_master cm
            LEFT JOIN inactive_clients_data icd ON cm.client_id = icd.client_id
            WHERE cm.client_id = $client_id AND cm.status = 'Inactive'
            ORDER BY icd.id DESC LIMIT 1");
            
        if ($result && $result->num_rows > 0) {
            $editRecord = $result->fetch_assoc();
        }
    }
    if (!$editRecord) $mode = 'view';
}

// ---------------------------------------------------------
// BUILD SEARCH QUERY & FETCH DATA (Inactive Clients with history)
// ---------------------------------------------------------
$searchQuery = trim($_GET['q'] ?? '');
$whereClause = "cm.status = 'Inactive'";

if ($searchQuery !== '') {
    $q_esc = $mysqli->real_escape_string($searchQuery);
    $whereClause .= " AND (
        cm.client_name LIKE '%$q_esc%' OR 
        cm.client_id LIKE '%$q_esc%' OR 
        cm.country LIKE '%$q_esc%' OR 
        cm.currency LIKE '%$q_esc%' OR 
        icd.journal_name LIKE '%$q_esc%' OR 
        icd.reason LIKE '%$q_esc%' OR 
        icd.year_stopped LIKE '%$q_esc%'
    )";
}

$sql = "SELECT 
    icd.id as history_id,
    cm.client_id,
    cm.client_name,
    cm.country,
    cm.currency,
    cm.frequency,
    cm.status,
    icd.journal_name,
    icd.reason,
    icd.year_stopped,
    icd.last_received_month_year,
    icd.last_file_receive_date,
    icd.created_at AS inactive_date,
    GROUP_CONCAT(DISTINCT cs.service_name SEPARATOR ', ') AS services
FROM client_master cm
LEFT JOIN (
    SELECT t1.* FROM inactive_clients_data t1
    INNER JOIN (
        SELECT client_id, MAX(id) as max_id FROM inactive_clients_data GROUP BY client_id
    ) t2 ON t1.client_id = t2.client_id AND t1.id = t2.max_id
) icd ON cm.client_id = icd.client_id
LEFT JOIN client_services cs 
    ON cm.client_id = cs.client_id 
    AND cs.is_active = 1
WHERE $whereClause
GROUP BY 
    cm.client_id,
    cm.client_name,
    cm.country,
    cm.currency,
    cm.frequency,
    cm.status,
    icd.journal_name,
    icd.reason,
    icd.year_stopped,
    icd.last_received_month_year,
    icd.last_file_receive_date,
    icd.created_at,
    icd.id
ORDER BY cm.client_id DESC";

$result = $mysqli->query($sql);

require 'header.php'; 
?>
<style>
    .table thead th {
        background-color: #6c757d !important;
        color: white !important;
        border-bottom: none !important;
    }
</style>

<div class="container-fluid py-4" style="margin-top: 60px;">
    
    <!-- PAGE HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-0"><i class="fas fa-user-times me-2"></i>Inactive Clients</h3>
            <p class="text-muted">Manage inactive clients and import from Excel.</p>
        </div>
        
        <div>
             <button type="button" class="btn btn-warning text-dark fw-bold" data-bs-toggle="modal" data-bs-target="#importModal">
                <i class="fas fa-file-excel me-2"></i> Import Excel
            </button>
            <a href="inactive_clients.php?mode=add" class="btn btn-success fw-bold me-2">
                <i class="fas fa-plus me-2"></i> Mark Client Inactive
            </a>
           
        </div>
    </div>

    <!-- ALERTS -->
    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
        <?= h($_GET['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if($mode === 'add' || $mode === 'edit'): ?>
    
    <!-- Add/Edit Form -->
    <div class="card shadow border-0 mb-4">
        <div class="card-header bg-dark text-white py-3">
            <h5 class="mb-0"><i class="fas <?= $mode === 'edit' ? 'fa-edit' : 'fa-plus-circle' ?> me-2"></i>
                <?= $mode === 'edit' ? 'Edit Inactive Client Record' : 'Mark Client as Inactive' ?>
            </h5>
        </div>
        <div class="card-body">
            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="<?= $mode ?>">
                <?php if($mode === 'edit'): ?>
                <input type="hidden" name="history_id" value="<?= (int)($editRecord['history_id'] ?? 0) ?>">
                <input type="hidden" name="client_id" value="<?= (int)($editRecord['client_id'] ?? 0) ?>">
                <?php endif; ?>

                <div class="row">
                    <?php if($mode === 'add'): ?>
                    <!-- Client Selection for Add Mode -->
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Select Client <span class="text-danger">*</span></label>
                        <select class="form-select" name="client_id" id="client_id" required>
                            <option value="">-- Select Active Client --</option>
                            <?php foreach($activeClients as $client): ?>
                                <option value="<?= $client['client_id'] ?>" 
                                        data-name="<?= h($client['client_name']) ?>"
                                        data-country="<?= h($client['country']) ?>"
                                        data-currency="<?= h($client['currency']) ?>">
                                    <?= h($client['client_name']) ?> (<?= h($client['country']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="client_name" id="client_name">
                    </div>
                    <?php else: ?>
                    <!-- Client Name Display for Edit Mode -->
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Client Name</label>
                        <input type="text" class="form-control" name="client_name" value="<?= h($editRecord['client_name'] ?? '') ?>" readonly>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Country</label>
                        <input type="text" class="form-control" name="country" id="country"
                               value="<?= h($editRecord['country'] ?? '') ?>">
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Currency</label>
                        <input type="text" class="form-control" name="currency" id="currency"
                               value="<?= h($editRecord['currency'] ?? '') ?>">
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Journal Name</label>
                        <input type="text" class="form-control" name="journal_name"
                               value="<?= h($editRecord['journal_name'] ?? '') ?>">
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Frequency</label>
                        <select class="form-select" name="frequency">
                            <option value="">Select Frequency</option>
                            <option value="Monthly" <?= (isset($editRecord['frequency']) && $editRecord['frequency'] == 'Monthly') ? 'selected' : '' ?>>Monthly</option>
                            <option value="Bimonthly" <?= (isset($editRecord['frequency']) && $editRecord['frequency'] == 'Bimonthly') ? 'selected' : '' ?>>Bimonthly</option>
                            <option value="Quarterly" <?= (isset($editRecord['frequency']) && $editRecord['frequency'] == 'Quarterly') ? 'selected' : '' ?>>Quarterly</option>
                            <option value="Mixed" <?= (isset($editRecord['frequency']) && $editRecord['frequency'] == 'Mixed') ? 'selected' : '' ?>>Mixed</option>
                        </select>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Reason for Inactivity <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="reason" rows="1" required><?= h($editRecord['reason'] ?? '') ?></textarea>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Year Stopped</label>
                        <input type="text" class="form-control" name="year_stopped" 
                               placeholder="YYYY"
                               value="<?= h($editRecord['year_stopped'] ?? '') ?>">
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Last Received (M/Y)</label>
                        <input type="text" class="form-control" name="last_received_month_year" 
                               placeholder="MM/YYYY"
                               value="<?= h($editRecord['last_received_month_year'] ?? '') ?>">
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Last File Receive Date</label>
                        <input type="date" class="form-control" name="last_file_receive_date" 
                               value="<?= isset($editRecord['last_file_receive_date']) && $editRecord['last_file_receive_date'] != '0000-00-00' ? date('Y-m-d', strtotime($editRecord['last_file_receive_date'])) : '' ?>">
                    </div>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-save me-2"></i><?= $mode === 'edit' ? 'Update' : 'Save' ?>
                    </button>
                    <a href="inactive_clients.php" class="btn btn-secondary px-4">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php endif; ?>

    <!-- SEARCH BAR -->
    <div class="card mb-3 shadow-sm border-0">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-8">
                    <input type="text" name="q" class="form-control" placeholder="Sort by Client Name, ID, Country, Currency, Journal, Reason..." value="<?= h($searchQuery) ?>">
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1"><i class="fas fa-filter me-1"></i>Filter</button>
                    <a href="inactive_clients.php" class="btn btn-outline-secondary flex-grow-1"><i class="fas fa-times me-1"></i>Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- DATA TABLE -->
    <div class="card shadow border-0">
        <div class="card-header bg-dark text-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Inactive Clients List</h5>
            <span class="badge bg-light text-dark">Total: <?= $result->num_rows ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 align-middle" style="font-size: 0.85rem;">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Client Name</th>
                            <th>Country</th>
                            <th>Currency</th>
                            <th>Journal Name</th>
                            <th>Services</th>
                            <th>Frequency of the Journal</th>
                            <th>Year</th>
                            <th>File Last Received (Month & Year)</th>
                            <th>Reason</th>
                            <th>Last Date</th>
                            <th>Status</th>
                            <th width="120">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): $i=0; ?>
                            <?php while ($row = $result->fetch_assoc()): $i++; ?>
                            <tr>
                                <td class="fw-bold"><?= $i ?></td>
                                <td class="fw-bold text-start">
                                    <?= h($row['client_name']) ?><br>
                                    <small class="text-muted">ID: <?= h($row['client_id']) ?></small>
                                </td>
                                <td><?= h($row['country']) ?: '—' ?></td>
                                <td><?= h($row['currency']) ?: '—' ?></td>
                                <td><?= h($row['journal_name']) ?: '—' ?></td>
                                <td class="text-start" style="max-width: 150px; white-space: normal;">
                                    <?php 
                                    if (!empty($row['services'])) {
                                        $services = explode(', ', $row['services']);
                                        foreach ($services as $service) {
                                            echo '<span class="badge bg-info me-1 mb-1">' . h(trim($service)) . '</span>';
                                        }
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td><?= h($row['frequency']) ?: '—' ?></td>
                                <td><span class="badge bg-secondary"><?= h($row['year_stopped']) ?: '—' ?></span></td>
                                <td><?= h($row['last_received_month_year']) ?: '—' ?></td>
                               
                                 <td class="text-danger text-start" style="max-width: 200px; white-space: normal;" title="<?= h($row['reason'] ?? '') ?>">
                                    <?php
                                    $reasonStr = (string)($row['reason'] ?? '');
                                    echo h(substr($reasonStr, 0, 50)) . (strlen($reasonStr) > 50 ? '...' : '');
                                    ?>
                                </td>
                                 <td><?= formatDate($row['last_file_receive_date']) ?></td>
                                <td>
                                    <span class="badge bg-danger">Inactive</span><br>
                                    <small class="text-muted"><?= formatDate($row['inactive_date']) ?></small>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="inactive_clients.php?mode=edit&client_id=<?= (int)$row['client_id'] ?>" 
                                           class="btn btn-warning" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="post" style="display:inline;" 
                                              onsubmit="return confirm('Reactivate this client? This will change status back to Active.')">
                                            <input type="hidden" name="action" value="reactivate">
                                            <input type="hidden" name="client_id" value="<?= (int)$row['client_id'] ?>">
                                            <button type="submit" class="btn btn-success" title="Reactivate">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                        </form>
                                        <?php if (!empty($row['history_id'])): ?>
                                        <form method="post" style="display:inline;" 
                                              onsubmit="return confirm('Delete this history record? This will not affect client status.')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$row['history_id'] ?>">
                                            <input type="hidden" name="client_id" value="<?= (int)$row['client_id'] ?>">
                                            <button type="submit" class="btn btn-danger" title="Delete History">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="13" class="text-center py-5 text-muted">No inactive clients found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- IMPORT MODAL POPUP -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-file-import me-2"></i>Import Inactive Clients</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form action="" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_excel">
                
                <div class="modal-body p-4">
                    <!-- Step 1: Download Template -->
                    <div class="d-flex align-items-center mb-4 p-3 bg-light rounded-3 border">
                        <div class="flex-shrink-0">
                            <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                <i class="fas fa-download"></i>
                            </div>
                        </div>
                        <div class="ms-3 flex-grow-1">
                            <h6 class="mb-1 fw-bold">Step 1: Download Template</h6>
                            <p class="small text-muted mb-0">Get the formatted Excel file to ensure data compatibility.</p>
                        </div>
                        <button type="button" class="btn btn-outline-success btn-sm fw-bold px-3" onclick="downloadClientSample()">
                            Download
                        </button>
                    </div>

                    <!-- Step 2: Upload File -->
                    <div class="mb-0">
                        <h6 class="fw-bold mb-3"><i class="fas fa-upload me-2 text-primary"></i>Step 2: Upload Completed File</h6>
                        <div class="upload-area p-4 border border-2 border-dashed rounded-3 text-center bg-light" id="dropZone">
                            <input type="file" name="excel_file" id="excel_file_input" class="form-control mb-2" accept=".xlsx" required>
                            <p class="small text-muted mb-0">Only .xlsx files are supported. Max size 5MB.</p>
                        </div>
                    </div>

                    <!-- Data Rules Info -->
                    <div class="mt-4 p-3 rounded-3" style="background-color: #e8f4fd; border-left: 5px solid #0d6efd;">
                        <h6 class="small fw-bold text-primary mb-2"><i class="fas fa-info-circle me-1"></i> Mapping Instructions (12 Columns Required):</h6>
                        <div class="row g-0 small">
                            <div class="col-6">Col A: S.No</div>
                            <div class="col-6">Col B: Client Name*</div>
                            <div class="col-6">Col C: Country</div>
                            <div class="col-6">Col D: Currency</div>
                            <div class="col-6">Col E: Journal</div>
                            <div class="col-6">Col F: Services</div>
                            <div class="col-6">Col G: Frequency of the Journal</div>
                            <div class="col-6">Col H: Year Stopped</div>
                            <div class="col-6">Col I: Last Rec (M/Y)</div>
                            <div class="col-6">Col J: Reason*</div>
                            <div class="col-6">Col K: Last File Date</div>
                            <div class="col-6">Col L: Status</div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">
                        <i class="fas fa-cloud-upload-alt me-2"></i>Start Import
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function downloadClientSample() {
    const headers =[
         "S.No", "Client Name*", "Country", "Currency", 
        "Journal Name", "Services", "Frequency of the Journal", "Year", "File Last Received (Month & Year)", "Reason", "Last Date", "Status"
    ];
    const data = [["1", "ABC Corporation", "USA", "USD", "Intl Journal", "CE, TS", "Monthly", "2024", "12/2024", "Budget cuts", "2024-12-15", "Inactive"]
    ];
    
    let csvContent = "data:text/csv;charset=utf-8," 
        + headers.join(",") + "\n"
        + data.map(e => `"${e.join('","')}"`).join("\n"); // Properly escape commas within array items

    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "inactive_clients_template.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        // Auto-dismiss alerts after 3 seconds
        var alertBox = document.querySelector('.alert');
        if (alertBox) {
            setTimeout(function() {
                var alert = new bootstrap.Alert(alertBox);
                alert.close();
            }, 3000);
        }

        // Auto-fill client details when client is selected (for add mode)
        const clientSelect = document.getElementById('client_id');
        if (clientSelect) {
            clientSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                if (selectedOption.value) {
                    document.getElementById('client_name').value = selectedOption.getAttribute('data-name') || '';
                    document.getElementById('country').value = selectedOption.getAttribute('data-country') || '';
                    document.getElementById('currency').value = selectedOption.getAttribute('data-currency') || '';
                }
            });
        }
    });
</script>
</body>
</html>