<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

function redirect_with($params =[]) {
    $base = 'journal_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Fetch client list for dropdown
function getClients($mysqli) {
    $clients =[];
    $sql = "SELECT client_id, client_name, team_client_name, company_name FROM client_master WHERE status = 'Active' ORDER BY team_client_name";
    $result = $mysqli->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $clients[] = $row;
        }
    }
    return $clients;
}

// Fetch team list from team_master
function getTeams($mysqli) {
    $teams =[];
    $sql = "SELECT team_id, team_name FROM team_master WHERE status = 'Active' ORDER BY team_name";
    $result = $mysqli->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $teams[] = $row;
        }
    }
    return $teams;
}

// Month options
function getMonths() {
    return[
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ---------------------------------------------------------
    // HANDLE IMPORT LOGIC (XLSX Format)
    // ---------------------------------------------------------
    if ($action === 'import_excel') {
        if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
            
            // Locate the SimpleXLSX Library
            $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
            // Fallback paths just in case
            if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
            if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

            if (!file_exists($libPath)) {
                redirect_with(['msg' => 'Library file not found.', 'type' => 'danger']);
            }
            
            require_once $libPath;

            // Initialize Parser
            $xlsx = null;
            if (class_exists('Shuchkin\SimpleXLSX')) {
                $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
            } elseif (class_exists('SimpleXLSX')) {
                $xlsx = SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
            } else {
                redirect_with(['msg' => 'Class SimpleXLSX not found.', 'type' => 'danger']);
            }

            // Process Rows
            if ($xlsx) {
                $rows = $xlsx->rows();
                $imported_count = 0;
                $updated_count = 0;
                $skipped_count = 0;
                $error_details =[];
                
                // Pre-fetch clients and teams to map names to IDs quickly
                $clients_map =[];
                $res = $mysqli->query("SELECT client_id, client_name FROM client_master");
                if($res) {
                    while($row = $res->fetch_assoc()) {
                        $clients_map[strtolower(trim($row['client_name']))] = $row['client_id'];
                    }
                }

                $teams_map =[];
                $res = $mysqli->query("SELECT team_id, team_name FROM team_master");
                if($res) {
                    while($row = $res->fetch_assoc()) {
                        $teams_map[strtolower(trim($row['team_name']))] = $row['team_id'];
                    }
                }
                
                foreach ($rows as $k => $r) {
                    if ($k === 0) continue; // Skip Header Row

                    // Columns: Client, Team, WK_NEW Journals, Year_Volume_Issue, Pubcode, ISSN, Month
                    $client_raw = trim($r[0] ?? '');
                    $team_raw = trim($r[1] ?? '');
                    $wk_new_journals = trim($r[2] ?? '');
                    $year_volume_issue = trim($r[3] ?? '');
                    $pubcode = trim($r[4] ?? '');
                    $issn = trim($r[5] ?? '');
                    $month = trim($r[6] ?? '');

                    // Skip if essential data is missing
                    if (empty($client_raw) || empty($wk_new_journals)) {
                        $skipped_count++;
                        $error_details[] = "Row " . ($k + 1) . ": Missing Client or WK_NEW Journals";
                        continue;
                    }

                    // Resolve Client ID
                    $client_id = $clients_map[strtolower($client_raw)] ?? 0;
                    if ($client_id === 0) {
                        $skipped_count++;
                        $error_details[] = "Row " . ($k + 1) . ": Client '$client_raw' not found in database";
                        continue;
                    }

                    // Resolve Team ID
                    $team_id = NULL;
                    if (!empty($team_raw)) {
                        $team_id = $teams_map[strtolower($team_raw)] ?? NULL;
                    }

                    // Check if entry already exists (by client + journal + pubcode)
                    $checkStmt = $mysqli->prepare("SELECT id FROM journal_master WHERE client_id = ? AND wk_new_journals = ? AND pubcode = ?");
                    $checkStmt->bind_param("iss", $client_id, $wk_new_journals, $pubcode);
                    $checkStmt->execute();
                    $checkRes = $checkStmt->get_result();
                    $exists = $checkRes->num_rows > 0;
                    $row_id = $exists ? $checkRes->fetch_assoc()['id'] : 0;
                    $checkStmt->close();

                    $status = 'Active';

                    if (!$exists) {
                        // Insert new record
                        $insertStmt = $mysqli->prepare("INSERT INTO journal_master (client_id, team_id, wk_new_journals, year_volume_issue, pubcode, issn, month, status, created_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $insertStmt->bind_param("iissssss", $client_id, $team_id, $wk_new_journals, $year_volume_issue, $pubcode, $issn, $month, $status);
                        
                        if ($insertStmt->execute()) {
                            $imported_count++;
                        } else {
                            $skipped_count++;
                            $error_details[] = "Row " . ($k + 1) . ": " . $mysqli->error;
                        }
                        $insertStmt->close();
                    } else {
                        // Update existing record
                        $updateStmt = $mysqli->prepare("UPDATE journal_master SET team_id = ?, year_volume_issue = ?, issn = ?, month = ?, updated_date = NOW() WHERE id = ?");
                        $updateStmt->bind_param("isssi", $team_id, $year_volume_issue, $issn, $month, $row_id);
                        
                        if ($updateStmt->execute()) {
                            $updated_count++;
                        }
                        $updateStmt->close();
                    }
                }
                
                $msg = "Process Complete.<br>";
                $msg .= "<strong>Imported:</strong> $imported_count new journals.<br>";
                $msg .= "<strong>Updated:</strong> $updated_count existing journals.<br>";
                $msg .= "<strong>Skipped:</strong> $skipped_count rows.";
                
                if (!empty($error_details)) {
                    $msg .= "<br><small class='text-danger'>" . implode("<br>", array_slice($error_details, 0, 5)) . "</small>";
                    if (count($error_details) > 5) {
                        $msg .= "<br>... and " . (count($error_details) - 5) . " more errors.";
                    }
                }
                
                $type = ($imported_count > 0 || $updated_count > 0) ? 'success' : 'warning';
                redirect_with(['msg' => $msg, 'type' => $type]);

            } else {
                $err = (method_exists('Shuchkin\SimpleXLSX', 'parseError')) ? Shuchkin\SimpleXLSX::parseError() : 'Unknown Error';
                redirect_with(['msg' => 'SimpleXLSX Parse Error: ' . $err, 'type' => 'danger']);
            }
        } else {
            redirect_with(['msg' => 'No file uploaded or file error.', 'type' => 'danger']);
        }
    }
    
    if ($action === 'add' || $action === 'edit') {
        $client_id = (int)trim($_POST['client_id'] ?? '');
        $team_id = (int)trim($_POST['team_id'] ?? '');
        $wk_new_journals = trim($_POST['wk_new_journals'] ?? '');
        $year_volume_issue = trim($_POST['year_volume_issue'] ?? '');
        $pubcode = trim($_POST['pubcode'] ?? '');
        $issn = trim($_POST['issn'] ?? '');
        $month = trim($_POST['month'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');
        
        if ($client_id === 0) {
            redirect_with(['msg' => 'Client selection is required.', 'type' => 'danger']);
        }
        
        if (empty($wk_new_journals)) {
            redirect_with(['msg' => 'WK_NEW Journals field is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            $check_stmt = $mysqli->prepare("SELECT id FROM journal_master WHERE client_id = ? AND wk_new_journals = ? AND pubcode = ?");
            $check_stmt->bind_param("iss", $client_id, $wk_new_journals, $pubcode);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                redirect_with(['msg' => 'Journal entry already exists for this client with same journal and pubcode.', 'type' => 'danger']);
            }
            $check_stmt->close();
            
            $stmt = $mysqli->prepare("INSERT INTO journal_master (client_id, team_id, wk_new_journals, year_volume_issue, pubcode, issn, month, status, created_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("iissssss", $client_id, $team_id, $wk_new_journals, $year_volume_issue, $pubcode, $issn, $month, $status);
            
            if ($stmt->execute()) {
                $client_stmt = $mysqli->prepare("SELECT client_name FROM client_master WHERE client_id = ?");
                $client_stmt->bind_param("i", $client_id);
                $client_stmt->execute();
                $client_stmt->bind_result($client_name);
                $client_stmt->fetch();
                $client_stmt->close();
                
                redirect_with(['msg' => "Journal entry for '$client_name' added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding journal entry: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                $check_stmt = $mysqli->prepare("SELECT id FROM journal_master WHERE client_id = ? AND wk_new_journals = ? AND pubcode = ? AND id != ?");
                $check_stmt->bind_param("issi", $client_id, $wk_new_journals, $pubcode, $id);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $check_stmt->close();
                    redirect_with(['msg' => 'Journal entry already exists for this client with same journal and pubcode.', 'type' => 'danger']);
                }
                $check_stmt->close();
                
                $stmt = $mysqli->prepare("UPDATE journal_master SET client_id = ?, team_id = ?, wk_new_journals = ?, year_volume_issue = ?, pubcode = ?, issn = ?, month = ?, status = ?, updated_date = NOW() WHERE id = ?");
                $stmt->bind_param("iissssssi", $client_id, $team_id, $wk_new_journals, $year_volume_issue, $pubcode, $issn, $month, $status, $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Journal entry updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating journal entry: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT cm.client_name FROM journal_master jm JOIN client_master cm ON jm.client_id = cm.client_id WHERE jm.id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($client_name);
            $res->fetch();
            $res->close();
            
            $del = $mysqli->prepare("DELETE FROM journal_master WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Journal entry for '$client_name' deleted", 'type' => 'warning']);
        }
    }
    
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT jm.status, cm.client_name FROM journal_master jm JOIN client_master cm ON jm.client_id = cm.client_id WHERE jm.id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($current_status, $client_name);
            $res->fetch();
            $res->close();
            
            if ($client_name) {
                $newStatus = ($current_status === 'Active') ? 'Inactive' : 'Active';
                
                $upd = $mysqli->prepare("UPDATE journal_master SET status = ?, updated_date = NOW() WHERE id = ?");
                $upd->bind_param("si", $newStatus, $id);
                $upd->execute();
                $upd->close();
                
                redirect_with(['msg' => "Journal entry status for '$client_name' changed to $newStatus", 'type' => 'info']);
            }
        }
    }
    
    if ($action !== 'import_excel') {
        redirect_with();
    }
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editEntry = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT jm.id, jm.client_id, jm.team_id, jm.wk_new_journals, jm.year_volume_issue, jm.pubcode, jm.issn, jm.month, jm.status, jm.created_date, jm.updated_date, cm.client_name, cm.team_client_name, cm.company_name, tm.team_name FROM journal_master jm JOIN client_master cm ON jm.client_id = cm.client_id LEFT JOIN team_master tm ON jm.team_id = tm.team_id WHERE jm.id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editEntry = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editEntry) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'created_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Validate sort column
$allowed_sorts =['jm.created_date', 'jm.updated_date', 'cm.client_name', 'jm.client_id', 'jm.wk_new_journals', 'jm.pubcode', 'jm.issn', 'tm.team_name', 'jm.month'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'jm.created_date';
}

// Build query
$sql = "SELECT jm.id, jm.client_id, jm.team_id, jm.wk_new_journals, jm.year_volume_issue, jm.pubcode, jm.issn, jm.month, jm.status, jm.created_date, jm.updated_date, 
               cm.client_name, cm.team_client_name, cm.company_name, tm.team_name 
        FROM journal_master jm 
        JOIN client_master cm ON jm.client_id = cm.client_id
        LEFT JOIN team_master tm ON jm.team_id = tm.team_id";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(cm.client_name LIKE ? OR cm.team_client_name LIKE ? OR cm.company_name LIKE ? OR jm.wk_new_journals LIKE ? OR jm.year_volume_issue LIKE ? OR jm.pubcode LIKE ? OR jm.issn LIKE ? OR tm.team_name LIKE ? OR jm.month LIKE ?)";
    $like = "%$q%";
    $params = array_fill(0, 9, $like);
    $types .= str_repeat('s', 9);
}

if ($fstatus === 'Active' || $fstatus === 'Inactive') {
    $cond[] = "jm.status = ?";
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
        $entries = $stmt->get_result();
    } else {
        $entries = false;
        error_log("Query preparation failed: " . $mysqli->error);
    }
} else {
    $entries = $mysqli->query($sql);
    if (!$entries) {
        error_log("Query execution failed: " . $mysqli->error);
    }
}

// Get clients list
$clients = getClients($mysqli);
$teams = getTeams($mysqli);
$months = getMonths();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journal Master</title>
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
            background-color: #5e6b77 !important;
            color: #ffffff !important;
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
        .journal-cell, .pubcode-cell {
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
            position: relative;
        }
        .journal-cell:hover::after,
        .pubcode-cell:hover::after {
            content: attr(title);
            position: absolute;
            top: 100%;
            left: 0;
            background: white;
            border: 1px solid #ddd;
            padding: 10px;
            z-index: 1000;
            max-width: 400px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            white-space: normal;
            word-wrap: break-word;
        }
        .team-badge {
            font-size: 0.85em;
        }
        .month-badge {
            font-size: 0.85em;
            background-color: #6c757d;
        }
        .issn-cell {
            font-family: monospace;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= $_GET['msg'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
        
        <!-- Add/Edit Form -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-newspaper me-2"></i><?= $mode === 'edit' ? 'Edit Journal Entry' : 'Add New Journal Entry' ?></h4>
            <a href="journal_master.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editEntry['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="client_id" class="form-label">Client Name <span class="text-danger">*</span></label>
                                <select class="form-control" id="client_id" name="client_id" required <?= $mode === 'edit' ? 'disabled' : '' ?>>
                                    <option value="">-- Select Client --</option>
                                    <?php 
                                    $displayed_clients = [];
                                    foreach($clients as $client): 
                                        $display_name = !empty($client['team_client_name']) ? $client['team_client_name'] : $client['client_name'];
                                        
                                        if (($mode === 'edit' && isset($editEntry['client_id']) && $editEntry['client_id'] == $client['client_id']) || !in_array($display_name, $displayed_clients)):
                                            $displayed_clients[] = $display_name;
                                    ?>
                                        <option value="<?= $client['client_id'] ?>" 
                                            <?= ($mode === 'edit' && isset($editEntry['client_id']) && $editEntry['client_id'] == $client['client_id']) ? 'selected' : '' ?>>
                                            <?= h($display_name) ?>
                                        </option>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </select>
                                <?php if($mode === 'edit'): ?>
                                    <input type="hidden" name="client_id" value="<?= isset($editEntry['client_id']) ? $editEntry['client_id'] : '' ?>">
                                <?php endif; ?>
                                <div class="form-text">Select the client for this journal entry</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="team_id" class="form-label">Team</label>
                                <select class="form-control" id="team_id" name="team_id">
                                    <option value="">-- Select Team --</option>
                                    <?php foreach($teams as $team): ?>
                                        <option value="<?= $team['team_id'] ?>" <?= (isset($editEntry['team_id']) && $editEntry['team_id'] == $team['team_id']) ? 'selected' : '' ?>>
                                            <?= h($team['team_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Select the team responsible</div>
                            </div>
                        </div>
                        
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label for="wk_new_journals" class="form-label">New Journals<span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="wk_new_journals" name="wk_new_journals" 
                                       value="<?= isset($editEntry['wk_new_journals']) ? h($editEntry['wk_new_journals']) : '' ?>" 
                                       placeholder="Enter journal name" required>
                                <div class="form-text">Enter the name of the new journal</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="year_volume_issue" class="form-label">Year_Volume_Issue</label>
                                <input type="text" class="form-control" id="year_volume_issue" name="year_volume_issue" 
                                       value="<?= isset($editEntry['year_volume_issue']) ? h($editEntry['year_volume_issue']) : '' ?>" 
                                       placeholder="e.g., 2024_01_01">
                                <div class="form-text">Enter year, volume and issue details</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="pubcode" class="form-label">Pubcode</label>
                                <input type="text" class="form-control" id="pubcode" name="pubcode" 
                                       value="<?= isset($editEntry['pubcode']) ? h($editEntry['pubcode']) : '' ?>" 
                                       placeholder="Enter publication code">
                                <div class="form-text">Enter the publication code</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="issn" class="form-label">ISSN</label>
                                <input type="text" class="form-control" id="issn" name="issn" 
                                       value="<?= isset($editEntry['issn']) ? h($editEntry['issn']) : '' ?>" 
                                       placeholder="e.g., 1234-5678">
                                <div class="form-text">Enter the International Standard Serial Number</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="month" class="form-label">Month</label>
                                <select class="form-control" id="month" name="month">
                                    <option value="">-- Select Month --</option>
                                    <?php foreach($months as $month_option): ?>
                                        <option value="<?= h($month_option) ?>" <?= (isset($editEntry['month']) && $editEntry['month'] === $month_option) ? 'selected' : '' ?>>
                                            <?= h($month_option) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Select the publication month</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-control" id="status" name="status">
                                    <option value="Active" <?= (isset($editEntry['status']) && $editEntry['status'] === 'Active') ? 'selected' : '' ?>>Active</option>
                                    <option value="Inactive" <?= (isset($editEntry['status']) && $editEntry['status'] === 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update' : 'Add Journal Entry' ?>
                        </button>
                        <a href="journal_master.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4><i class="fas fa-newspaper me-2"></i>Assigned New Journals</h4>
                <p class="text-muted mb-0">Manage journals | Import Excel to bulk add/update</p>
            </div>
            <div>
                <button type="button" class="btn btn-primary btn-sm me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-excel me-2"></i> Import Excel
                </button>
                <a href="journal_master.php?mode=add" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus me-1"></i> Add New
                </a>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Sort by client, journal, pubcode, ISSN, team, or month..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">Sort by Status</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="cm.client_name" <?= $sort === 'cm.client_name' ? 'selected' : '' ?>>Sort by Client Name</option>
                            <option value="jm.client_id" <?= $sort === 'jm.client_id' ? 'selected' : '' ?>>Sort by Client ID</option>
                            <option value="jm.wk_new_journals" <?= $sort === 'jm.wk_new_journals' ? 'selected' : '' ?>>Sort by Journal Name</option>
                            <option value="jm.pubcode" <?= $sort === 'jm.pubcode' ? 'selected' : '' ?>>Sort by Pubcode</option>
                            <option value="jm.issn" <?= $sort === 'jm.issn' ? 'selected' : '' ?>>Sort by ISSN</option>
                            <option value="tm.team_name" <?= $sort === 'tm.team_name' ? 'selected' : '' ?>>Sort by Team</option>
                            <option value="jm.month" <?= $sort === 'jm.month' ? 'selected' : '' ?>>Sort by Month</option>
                            <option value="jm.created_date" <?= $sort === 'jm.created_date' ? 'selected' : '' ?>>Sort by Created Date</option>
                            <option value="jm.updated_date" <?= $sort === 'jm.updated_date' ? 'selected' : '' ?>>Sort by Updated Date</option>
                        </select>
                    </div>
                    <!--div class="col-md-2">
                        <select name="dir" class="form-select form-select-sm">
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option>
                        </select>
                    </div-->
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Filter
                        </button>
                        <a href="journal_master.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Entries Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <!-- Table headings matched exactly to required order -->
                        <thead class="table-light">
                            <tr>
                                <th>S.No</th>
                                <th>Team</th>
                                <th>Client ID</th>
                                <!-- <th>WK_NEW Journals</th> -->
                                <th>New Journals</th>
                                <th>Year_Vol_Issue</th>
                                <!-- <th>Pubcode</th>
                                <th>ISSN</th> -->
                                <th>Month & Year</th>
                                <th width="100">Status</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
    <?php if($entries && $entries->num_rows > 0): $i=0;?>
        <?php while($row = $entries->fetch_assoc()): $i=$i+1;?>
        <tr>
            <td><?= $i ?></td>
            <!-- Team Column -->
            <td>
                <?php if(!empty($row['team_name'])): ?>
                    <span class="badge bg-primary team-badge"><?= h($row['team_name']) ?></span>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <!-- Team Client Name Column -->
            <td>
                <span class="fw-semibold"><?= h($row['team_client_name'] ?: $row['client_name']) ?></span>
            </td>
            <!-- Journal Name (New Journals) Column -->
            <td class="journal-cell" title="<?= h($row['wk_new_journals']) ?>">
                <?= !empty($row['wk_new_journals']) ? h($row['wk_new_journals']) : '—' ?>
            </td>
            <!-- Year_Vol_Issue Column -->
            <td>
                <?= !empty($row['year_volume_issue']) ? h($row['year_volume_issue']) : '<span class="text-muted">—</span>' ?>
            </td>
            <!-- Month Column -->
            <td>
                <?= !empty($row['month']) ? h($row['month']) : '<span class="text-muted">—</span>' ?>
            </td>
            <!-- Status Column -->
            <td>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <button type="submit" class="btn btn-sm <?= $row['status'] === 'Active' ? 'btn-success' : 'btn-danger' ?>">
                        <?= h($row['status']) ?>
                    </button>
                </form>
            </td>
            <!-- Actions Column -->
            <td class="text-center">
                <div class="btn-group btn-group-sm" role="group">
                    <a href="journal_master.php?mode=edit&id=<?= (int)$row['id'] ?>" class="btn btn-primary" title="Edit">
                        <i class="fas fa-edit"></i>
                    </a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                        <button type="submit" class="btn btn-danger" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr><td colspan="7" class="text-center py-5">No entries found</td></tr>
    <?php endif; ?>
</tbody>
                        <!-- <tbody>
                            <?php if($entries && $entries->num_rows > 0): $i=0;?>
                                <?php while($row = $entries->fetch_assoc()): $i=$i+1;?>
                                <tr>
                                    <td> <?= $i ?></td>
                                    <td>
                                        <span class="fw-semibold"><?= h($row['client_name']) ?></span>
                                        <?php if(!empty($row['company_name'])): ?>
                                            <br><small class="text-muted"><?= h($row['company_name']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['team_name'])): ?>
                                            <span class="badge bg-primary team-badge"><?= h($row['team_name']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="journal-cell" title="<?= h($row['wk_new_journals']) ?>">
                                        <?= !empty($row['wk_new_journals']) ? h($row['wk_new_journals']) : '—' ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['year_volume_issue'])): ?>
                                            <small><?= h($row['year_volume_issue']) ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pubcode-cell" title="<?= h($row['pubcode']) ?>">
                                        <?php if(!empty($row['pubcode'])): ?>
                                            <span class="badge bg-secondary"><?= h($row['pubcode']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="issn-cell">
                                        <?php if(!empty($row['issn'])): ?>
                                            <?= h($row['issn']) ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['month'])): ?>
                                            <span class="badge month-badge"><?= h($row['month']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $row['status'] === 'Active' ? 'btn-success' : 'btn-danger' ?>">
                                                <?= h($row['status']) ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="journal_master.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this journal entry?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-newspaper fa-3x mb-3"></i>
                                            <h5>No journal entries found</h5>
                                            <p class="mb-0">Get started by adding your first journal entry.</p>
                                            <a href="journal_master.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add New Entry
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody> -->
                    </table>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <!-- IMPORT MODAL -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-file-excel me-2"></i>Import Journals from Excel
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <form action="" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Select Excel File (.xlsx)</label>
                                    <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                                    <small class="text-muted">Maximum file size: 5MB</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Download Sample</label>
                                <button type="button" class="btn btn-outline-success w-100" onclick="downloadSample()">
                                    <i class="fas fa-download me-1"></i> Sample Format
                                </button>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <h6 class="fw-bold"><i class="fas fa-info-circle me-1"></i> Excel Format Required:</h6>
                            <p class="mb-1"><strong>Columns in exact order (No extra columns):</strong></p>
                            <ol class="mb-2">
                                <li><strong>Client</strong> - Required (Must precisely match existing Client Name)</li>
                                <li><strong>Team</strong> - Optional (Matches existing Team Name)</li>
                                <li><strong>WK_NEW Journals</strong> - Required</li>
                                <li><strong>Year_Volume_Issue</strong> - Optional</li>
                                <li><strong>Pubcode</strong> - Optional</li>
                                <li><strong>ISSN</strong> - Optional</li>
                                <li><strong>Month</strong> - Optional</li>
                            </ol>
                            
                            <div class="border-top pt-2 mt-2">
                                <p class="mb-1"><strong><i class="fas fa-exclamation-triangle me-1"></i> Rules:</strong></p>
                                <ul class="mb-0">
                                    <li>New records will be <span class="badge bg-success">INSERTED</span></li>
                                    <li>Existing records (matching Client + Journal + Pubcode) will be <span class="badge bg-warning">UPDATED</span></li>
                                    <li>Rows missing "Client" or "Journal" or containing a missing Client will be <span class="badge bg-danger">SKIPPED</span></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i> Close
                        </button>
                        <button type="submit" class="btn btn-dark">
                            <i class="fas fa-upload me-1"></i> Import Data
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
            const filterSelects = document.querySelectorAll('select[name="status"], select[name="sort"], select[name="dir"]');
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });

            // ISSN formatting
            const issnInput = document.getElementById('issn');
            if (issnInput) {
                issnInput.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/[^\d-]/g, '');
                    
                    // Format as XXXX-XXXX
                    if (value.length > 4 && !value.includes('-')) {
                        value = value.substring(0, 4) + '-' + value.substring(4, 8);
                    }
                    
                    e.target.value = value;
                });
            }
        });

        // Download sample CSV formatted for Journals
        function downloadSample() {
            const sampleContent = "Client,Team,WK_NEW Journals,Year_Volume_Issue,Pubcode,ISSN,Month\n" +
                                 "WK,XML,Journal of Nursing Science and Professional Practice,2024_01_01,NSPP,6666-9999,January\n" +
                                 "WK,XML,Current Trends in Dentistry,2024_01_01,CTID,7777-3333,February\n" +
                                 "WK,XML,Journal of Orthopaedic Trauma and Reconstruction,2024_01_01,ORTR,6666-5555,March";
            
            const blob = new Blob([sampleContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sample_journal_import.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>