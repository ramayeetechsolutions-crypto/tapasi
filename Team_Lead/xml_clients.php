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
    $base = 'xml_clients.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Season options
$season_options = [
    'Freq-Triannual',
    'Bi-Monthly', 
    'Regular',
    'Quaterly',
    'Monthly',
    'Weekly',
    'Daily',
    'Other'
];

// Fetch client list for dropdown
function getClients($mysqli) {
    $clients = [];
    $sql = "SELECT client_id, client_name, company_name, production_email FROM client_master WHERE status = 'Active' ORDER BY client_name";
    $result = $mysqli->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $clients[] = $row;
        }
    }
    return $clients;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $client_id = (int)trim($_POST['client_id'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $dtd = trim($_POST['dtd'] ?? '');
        $season = trim($_POST['season'] ?? '');
        $journals_input = trim($_POST['journals'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');
        
        // Basic validation
        if ($client_id === 0) {
            redirect_with(['msg' => 'Client selection is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Check if any entry already exists for this client with the same journal names
            $journals_array = array_filter(array_map('trim', explode("\n", $journals_input)));
            
            if (!empty($journals_array)) {
                // Check for existing journal names
                $placeholders = implode(',', array_fill(0, count($journals_array), '?'));
                $check_sql = "SELECT journal_name FROM xml_clients WHERE client_id = ? AND journal_name IN ($placeholders)";
                $check_stmt = $mysqli->prepare($check_sql);
                
                $types = 'i' . str_repeat('s', count($journals_array));
                $params = array_merge([$client_id], $journals_array);
                $check_stmt->bind_param($types, ...$params);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $existing_journals = [];
                while ($row = $check_result->fetch_assoc()) {
                    $existing_journals[] = $row['journal_name'];
                }
                $check_stmt->close();
                
                if (!empty($existing_journals)) {
                    $existing_list = implode(', ', array_slice($existing_journals, 0, 3));
                    if (count($existing_journals) > 3) {
                        $existing_list .= '...';
                    }
                    redirect_with(['msg' => "Some journals already exist for this client: $existing_list", 'type' => 'danger']);
                }
            }
            
            // Process multi-line journals
            $journals_array = array_filter(array_map('trim', explode("\n", $journals_input)));
            
            if (empty($journals_array)) {
                // Insert single entry if no journals
                $stmt = $mysqli->prepare("INSERT INTO xml_clients (client_id, remarks, dtd, season, status, created_date) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("issss", $client_id, $remarks, $dtd, $season, $status);
                
                if ($stmt->execute()) {
                    // Get client name for success message
                    $client_stmt = $mysqli->prepare("SELECT client_name FROM client_master WHERE client_id = ?");
                    $client_stmt->bind_param("i", $client_id);
                    $client_stmt->execute();
                    $client_stmt->bind_result($client_name);
                    $client_stmt->fetch();
                    $client_stmt->close();
                    
                    redirect_with(['msg' => "XML client entry for '$client_name' added successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error adding XML client: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            } else {
                // Insert multiple records for each journal
                $success_count = 0;
                $error_count = 0;
                
                foreach ($journals_array as $journal_name) {
                    if (!empty($journal_name)) {
                        $stmt = $mysqli->prepare("INSERT INTO xml_clients (client_id, journal_name, remarks, dtd, season, status, created_date) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->bind_param("isssss", $client_id, $journal_name, $remarks, $dtd, $season, $status);
                        
                        if ($stmt->execute()) {
                            $success_count++;
                        } else {
                            $error_count++;
                        }
                        $stmt->close();
                    }
                }
                
                // Get client name for success message
                $client_stmt = $mysqli->prepare("SELECT client_name FROM client_master WHERE client_id = ?");
                $client_stmt->bind_param("i", $client_id);
                $client_stmt->execute();
                $client_stmt->bind_result($client_name);
                $client_stmt->fetch();
                $client_stmt->close();
                
                $message = "XML client entry for '$client_name' added successfully";
                if ($success_count > 0) {
                    $message .= " ($success_count journal" . ($success_count > 1 ? 's' : '') . " created)";
                }
                if ($error_count > 0) {
                    $message .= " ($error_count journal" . ($error_count > 1 ? 's' : '') . " failed)";
                }
                
                redirect_with(['msg' => $message, 'type' => $error_count > 0 ? 'warning' : 'success']);
            }
        }
        
        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Check if entry already exists for another client with same journal (excluding current one)
                $journal_name = trim($_POST['journals'] ?? '');
                
                $check_stmt = $mysqli->prepare("SELECT id FROM xml_clients WHERE client_id = ? AND journal_name = ? AND id != ?");
                $check_stmt->bind_param("isi", $client_id, $journal_name, $id);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $check_stmt->close();
                    redirect_with(['msg' => 'XML client entry already exists for this client with the same journal name.', 'type' => 'danger']);
                }
                $check_stmt->close();
                
                // For edit, we only update the single record
                $stmt = $mysqli->prepare("UPDATE xml_clients SET client_id = ?, journal_name = ?, remarks = ?, dtd = ?, season = ?, status = ?, updated_date = NOW() WHERE id = ?");
                $stmt->bind_param("isssssi", $client_id, $journal_name, $remarks, $dtd, $season, $status, $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "XML client entry updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating XML client: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            // Get client name for message
            $res = $mysqli->prepare("SELECT cm.client_name FROM xml_clients crd JOIN client_master cm ON crd.client_id = cm.client_id WHERE crd.id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($client_name);
            $res->fetch();
            $res->close();
            
            // Delete entry
            $del = $mysqli->prepare("DELETE FROM xml_clients WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "XML client entry for '$client_name' deleted", 'type' => 'warning']);
        }
    }
    
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT crd.status, cm.client_name FROM xml_clients crd JOIN client_master cm ON crd.client_id = cm.client_id WHERE crd.id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($current_status, $client_name);
            $res->fetch();
            $res->close();
            
            if ($client_name) {
                $newStatus = ($current_status === 'Active') ? 'Inactive' : 'Active';
                
                $upd = $mysqli->prepare("UPDATE xml_clients SET status = ?, updated_date = NOW() WHERE id = ?");
                $upd->bind_param("si", $newStatus, $id);
                $upd->execute();
                $upd->close();
                
                redirect_with(['msg' => "XML client status for '$client_name' changed to $newStatus", 'type' => 'info']);
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
        $stmt = $mysqli->prepare("SELECT crd.id, crd.client_id, crd.journal_name, crd.remarks, crd.dtd, crd.season, crd.status, crd.created_date, crd.updated_date, cm.client_name, cm.company_name, cm.production_email FROM xml_clients crd JOIN client_master cm ON crd.client_id = cm.client_id WHERE crd.id = ?");
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
$allowed_sorts = ['crd.created_date', 'crd.updated_date', 'cm.client_name', 'crd.client_id', 'crd.journal_name', 'crd.season'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'crd.created_date';
}

// Build query - fetching production_email from client_master
$sql = "SELECT crd.id, crd.client_id, crd.journal_name, crd.remarks, crd.dtd, crd.season, crd.status, crd.created_date, crd.updated_date, 
               cm.client_name, cm.company_name, cm.production_email 
        FROM xml_clients crd 
        JOIN client_master cm ON crd.client_id = cm.client_id";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(cm.client_name LIKE ? OR cm.company_name LIKE ? OR crd.remarks LIKE ? OR crd.dtd LIKE ? OR crd.journal_name LIKE ? OR crd.season LIKE ? OR cm.production_email LIKE ?)";
    $like = "%$q%";
    $params = array_fill(0, 7, $like);
    $types .= str_repeat('s', 7);
}

if ($fstatus === 'Active' || $fstatus === 'Inactive') {
    $cond[] = "crd.status = ?";
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XML Clients Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1600px;
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
        .remarks-cell, .journal-cell {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
            position: relative;
        }
        .remarks-cell:hover::after,
        .journal-cell:hover::after {
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
        .email-cell {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .season-badge {
            font-size: 0.85em;
        }
        .email-display {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 8px 12px;
            border-radius: 4px;
            min-height: 38px;
            display: flex;
            align-items: center;
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
            <h4><i class="fas fa-file-code me-2"></i><?= $mode === 'edit' ? 'Edit XML Client' : 'Add New XML Client' ?></h4>
            <a href="xml_clients.php" class="btn btn-secondary btn-sm">
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
                                <label for="client_id" class="form-label">Select Client <span class="text-danger">*</span></label>
                                <select class="form-control" id="client_id" name="client_id" required <?= $mode === 'edit' ? 'disabled' : '' ?>>
                                    <option value="">-- Select Client --</option>
                                    <?php foreach($clients as $client): ?>
                                        <option value="<?= $client['client_id'] ?>" 
                                            <?= ($mode === 'edit' && isset($editEntry['client_id']) && $editEntry['client_id'] == $client['client_id']) ? 'selected' : '' ?>
                                            data-email="<?= h($client['production_email'] ?? '') ?>">
                                            <?= h($client['client_name']) ?> 
                                            <?= !empty($client['company_name']) ? ' - ' . h($client['company_name']) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if($mode === 'edit'): ?>
                                    <input type="hidden" name="client_id" value="<?= isset($editEntry['client_id']) ? $editEntry['client_id'] : '' ?>">
                                <?php endif; ?>
                                <div class="form-text">Choose a client from the list</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="dtd" class="form-label">DTD (Days to Deliver)</label>
                                <input type="text" class="form-control" id="dtd" name="dtd" 
                                       value="<?= isset($editEntry['dtd']) ? h($editEntry['dtd']) : '' ?>" 
                                       placeholder="e.g., 3-5 days, 1 week, etc.">
                                <div class="form-text">Enter the expected delivery timeline</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="season" class="form-label">Season</label>
                                <select class="form-control" id="season" name="season">
                                    <option value="">-- Select Season --</option>
                                    <?php foreach($season_options as $option): ?>
                                        <option value="<?= h($option) ?>" <?= (isset($editEntry['season']) && $editEntry['season'] === $option) ? 'selected' : '' ?>>
                                            <?= h($option) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Select the publication frequency/season</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Production Email (from Client Master)</label>
                                <div class="email-display" id="production_email_display">
                                    <?php if($mode === 'edit' && isset($editEntry['production_email'])): ?>
                                        <?= h($editEntry['production_email']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">Select a client to see email</span>
                                    <?php endif; ?>
                                </div>
                                <div class="form-text">Email is fetched from client master record</div>
                            </div>
                        </div>
                        
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label for="journals" class="form-label">
                                    <?= $mode === 'edit' ? 'Journal Name' : 'Journal Names (One per line for multiple journals)' ?>
                                </label>
                                <?php if($mode === 'edit'): ?>
                                    <input type="text" class="form-control" id="journals" name="journals" 
                                           value="<?= isset($editEntry['journal_name']) ? h($editEntry['journal_name']) : '' ?>" 
                                           placeholder="Enter journal name">
                                <?php else: ?>
                                    <textarea class="form-control" id="journals" name="journals" rows="4" 
                                              placeholder="Enter journal names, one per line. Each line will create a separate record.&#10;Example:&#10;Journal of Medicine&#10;Medical Research Review&#10;Clinical Studies Weekly"><?= isset($editEntry['journal_name']) ? h($editEntry['journal_name']) : '' ?></textarea>
                                <?php endif; ?>
                                <div class="form-text">
                                    <?php if($mode === 'edit'): ?>
                                        Edit the journal name for this specific record
                                    <?php else: ?>
                                        Enter multiple journal names (one per line) to create multiple records at once
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label for="remarks" class="form-label">Remarks</label>
                                <textarea class="form-control" id="remarks" name="remarks" rows="3" 
                                          placeholder="Enter any special instructions, notes, or remarks..."><?= isset($editEntry['remarks']) ? h($editEntry['remarks']) : '' ?></textarea>
                                <div class="form-text">Add any important notes or instructions</div>
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
                            <?= $mode === 'edit' ? 'Update' : ($mode === 'add' ? 'Add XML Client(s)' : 'Save') ?>
                        </button>
                        <a href="xml_clients.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-file-code me-2"></i>XML Clients Master</h4>
            <a href="xml_clients.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add New
            </a>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search by client, journal, remarks, DTD, season, or email..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="cm.client_name" <?= $sort === 'cm.client_name' ? 'selected' : '' ?>>Sort by Client Name</option>
                            <option value="crd.client_id" <?= $sort === 'crd.client_id' ? 'selected' : '' ?>>Sort by Client ID</option>
                            <option value="crd.journal_name" <?= $sort === 'crd.journal_name' ? 'selected' : '' ?>>Sort by Journal Name</option>
                            <option value="crd.season" <?= $sort === 'crd.season' ? 'selected' : '' ?>>Sort by Season</option>
                            <option value="crd.created_date" <?= $sort === 'crd.created_date' ? 'selected' : '' ?>>Sort by Created Date</option>
                            <option value="crd.updated_date" <?= $sort === 'crd.updated_date' ? 'selected' : '' ?>>Sort by Updated Date</option>
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
                        <a href="xml_clients.php" class="btn btn-secondary btn-sm">
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
                                <th>Journal Name</th>
                                <th>Remarks</th>
                                <th width="100">DTD</th>
                                <th width="120">Season</th>
                                <th width="150">Production Email</th>
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
                                    <td class="journal-cell" title="<?= h($row['journal_name']) ?>">
                                        <?= !empty($row['journal_name']) ? h($row['journal_name']) : '—' ?>
                                    </td>
                                    <td class="remarks-cell" title="<?= h($row['remarks']) ?>">
                                        <?= !empty($row['remarks']) ? (mb_strlen($row['remarks']) > 30 ? mb_substr($row['remarks'], 0, 30) . '...' : $row['remarks']) : '—' ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['dtd'])): ?>
                                            <span class="badge bg-secondary"><?= h($row['dtd']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['season'])): ?>
                                            <span class="badge bg-info season-badge"><?= h($row['season']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="email-cell" title="<?= h($row['production_email']) ?>">
                                        <?php if(!empty($row['production_email'])): ?>
                                            <small><?= h($row['production_email']) ?></small>
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
                                            <a href="xml_clients.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this XML client entry?')">
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
                                    <td colspan="12" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-file-code fa-3x mb-3"></i>
                                            <h5>No XML client entries found</h5>
                                            <p class="mb-0">Get started by adding your first XML client entry.</p>
                                            <a href="xml_clients.php?mode=add" class="btn btn-primary mt-2">
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

            // Update production email display when client is selected
            const clientSelect = document.getElementById('client_id');
            const emailDisplay = document.getElementById('production_email_display');
            
            if (clientSelect && emailDisplay) {
                clientSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const email = selectedOption.getAttribute('data-email') || '';
                    
                    if (email) {
                        emailDisplay.textContent = email;
                        emailDisplay.classList.remove('text-muted');
                    } else {
                        emailDisplay.innerHTML = '<span class="text-muted">No production email found in client master</span>';
                    }
                });
                
                // Trigger change on page load if client is already selected
                if (clientSelect.value) {
                    clientSelect.dispatchEvent(new Event('change'));
                }
            }
        });
    </script>
</body>
</html>