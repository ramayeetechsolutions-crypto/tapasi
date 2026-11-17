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

function redirect_with($params = []) {
    $base = 'journal_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Fetch client list for dropdown
function getClients($mysqli) {
    $clients = [];
    $sql = "SELECT client_id, client_name, company_name FROM client_master WHERE status = 'Active' ORDER BY client_name";
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
    $teams = [];
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
    return [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $client_id = (int)trim($_POST['client_id'] ?? '');
        $team_id = (int)trim($_POST['team_id'] ?? '');
        $wk_new_journals = trim($_POST['wk_new_journals'] ?? '');
        $year_volume_issue = trim($_POST['year_volume_issue'] ?? '');
        $pubcode = trim($_POST['pubcode'] ?? '');
        $issn = trim($_POST['issn'] ?? '');
        $month = trim($_POST['month'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');
        
        // Basic validation
        if ($client_id === 0) {
            redirect_with(['msg' => 'Client selection is required.', 'type' => 'danger']);
        }
        
        if (empty($wk_new_journals)) {
            redirect_with(['msg' => 'WK_NEW Journals field is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Check if entry already exists (optional, based on your business logic)
            // For example, check by client + journal + pubcode combination
            $check_stmt = $mysqli->prepare("SELECT id FROM journal_master WHERE client_id = ? AND wk_new_journals = ? AND pubcode = ?");
            $check_stmt->bind_param("iss", $client_id, $wk_new_journals, $pubcode);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                redirect_with(['msg' => 'Journal entry already exists for this client with same journal and pubcode.', 'type' => 'danger']);
            }
            $check_stmt->close();
            
            // Insert new entry
            $stmt = $mysqli->prepare("INSERT INTO journal_master (client_id, team_id, wk_new_journals, year_volume_issue, pubcode, issn, month, status, created_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("iissssss", $client_id, $team_id, $wk_new_journals, $year_volume_issue, $pubcode, $issn, $month, $status);
            
            if ($stmt->execute()) {
                // Get client name for success message
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
                // Check if entry already exists for another record (excluding current one)
                $check_stmt = $mysqli->prepare("SELECT id FROM journal_master WHERE client_id = ? AND wk_new_journals = ? AND pubcode = ? AND id != ?");
                $check_stmt->bind_param("issi", $client_id, $wk_new_journals, $pubcode, $id);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $check_stmt->close();
                    redirect_with(['msg' => 'Journal entry already exists for this client with same journal and pubcode.', 'type' => 'danger']);
                }
                $check_stmt->close();
                
                // Update entry
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
            // Get client name for message
            $res = $mysqli->prepare("SELECT cm.client_name FROM journal_master jm JOIN client_master cm ON jm.client_id = cm.client_id WHERE jm.id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($client_name);
            $res->fetch();
            $res->close();
            
            // Delete entry
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
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editEntry = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT jm.id, jm.client_id, jm.team_id, jm.wk_new_journals, jm.year_volume_issue, jm.pubcode, jm.issn, jm.month, jm.status, jm.created_date, jm.updated_date, cm.client_name, cm.company_name, tm.team_name FROM journal_master jm JOIN client_master cm ON jm.client_id = cm.client_id LEFT JOIN team_master tm ON jm.team_id = tm.team_id WHERE jm.id = ?");
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
$allowed_sorts = ['jm.created_date', 'jm.updated_date', 'cm.client_name', 'jm.client_id', 'jm.wk_new_journals', 'jm.pubcode', 'jm.issn', 'tm.team_name', 'jm.month'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'jm.created_date';
}

// Build query - JOIN with team_master to get team_name
$sql = "SELECT jm.id, jm.client_id, jm.team_id, jm.wk_new_journals, jm.year_volume_issue, jm.pubcode, jm.issn, jm.month, jm.status, jm.created_date, jm.updated_date, 
               cm.client_name, cm.company_name, tm.team_name 
        FROM journal_master jm 
        JOIN client_master cm ON jm.client_id = cm.client_id
        LEFT JOIN team_master tm ON jm.team_id = tm.team_id";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(cm.client_name LIKE ? OR cm.company_name LIKE ? OR jm.wk_new_journals LIKE ? OR jm.year_volume_issue LIKE ? OR jm.pubcode LIKE ? OR jm.issn LIKE ? OR tm.team_name LIKE ? OR jm.month LIKE ?)";
    $like = "%$q%";
    $params = array_fill(0, 8, $like);
    $types .= str_repeat('s', 8);
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
        .journal-cell, .pubcode-cell {
            max-width: 200px;
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
        .client-id-cell {
            font-weight: bold;
            color: #2c3e50;
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
            <?= h($_GET['msg']) ?>
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
                                <label for="client_id" class="form-label">Client <span class="text-danger">*</span></label>
                                <select class="form-control" id="client_id" name="client_id" required <?= $mode === 'edit' ? 'disabled' : '' ?>>
                                    <option value="">-- Select Client --</option>
                                    <?php foreach($clients as $client): ?>
                                        <option value="<?= $client['client_id'] ?>" 
                                            <?= ($mode === 'edit' && isset($editEntry['client_id']) && $editEntry['client_id'] == $client['client_id']) ? 'selected' : '' ?>>
                                            <?= h($client['client_name']) ?> 
                                            <?= !empty($client['company_name']) ? ' - ' . h($client['company_name']) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
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
                                <label for="wk_new_journals" class="form-label">WK_NEW Journals <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="wk_new_journals" name="wk_new_journals" 
                                       value="<?= isset($editEntry['wk_new_journals']) ? h($editEntry['wk_new_journals']) : '' ?>" 
                                       placeholder="Enter journal name" required>
                                <div class="form-text">Enter the name of the new journal</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="year_volume_issue" class="form-label">Year/Volume/Issue</label>
                                <input type="text" class="form-control" id="year_volume_issue" name="year_volume_issue" 
                                       value="<?= isset($editEntry['year_volume_issue']) ? h($editEntry['year_volume_issue']) : '' ?>" 
                                       placeholder="e.g., 2023/Vol. 15/Issue 3">
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
            <h4><i class="fas fa-newspaper me-2"></i>Journal Master</h4>
            <a href="journal_master.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add New
            </a>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search by client, journal, pubcode, ISSN, team, or month..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
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
                    <div class="col-md-2">
                        <select name="dir" class="form-select form-select-sm">
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option>
                        </select>
                    </div>
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
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
                        <thead class="table-light">
                            <tr>
                                <th width="50">ID</th>
                                <th width="80">Client ID</th>
                                <th>Client Name</th>
                                <th>Team</th>
                                <th>WK_NEW Journals</th>
                                <th>Year/Volume/Issue</th>
                                <th>Pubcode</th>
                                <th>ISSN</th>
                                <th>Month</th>
                                <th width="100">Status</th>
                                <th width="120">Created Date</th>
                                <th width="120">Updated Date</th>
                                <th width="150" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($entries && $entries->num_rows > 0): ?>
                                <?php while($row = $entries->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?= (int)$row['id'] ?></td>
                                    <td class="client-id-cell"><?= (int)$row['client_id'] ?></td>
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
                                    <td>
                                        <small class="text-muted"><?= h(formatDate($row['created_date'])) ?></small>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= h(formatDate($row['updated_date'])) ?></small>
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
                                    <td colspan="13" class="text-center py-5">
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
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php endif; ?>
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
    </script>
</body>
</html>