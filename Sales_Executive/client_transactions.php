<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
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
    $base = 'client_transactions.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Get all active clients for dropdown
function getClients($mysqli) {
    $clients = [];
    $sql = "SELECT DISTINCT cm.client_id, cm.client_name, cm.country, cm.currency 
            FROM client_master cm 
            INNER JOIN jobs j ON cm.client_id = j.client_id 
            WHERE cm.status = 'Active' 
            ORDER BY cm.client_name";
    $result = $mysqli->query($sql);
    
    while($row = $result->fetch_assoc()) {
        $clients[] = $row;
    }
    return $clients;
}

// Get unique journals for a specific client from jobs table
function getJournals($mysqli, $client_id) {
    $journals = [];
    $sql = "SELECT DISTINCT journal_issue 
            FROM jobs 
            WHERE client_id = ? AND journal_issue IS NOT NULL AND journal_issue != '' 
            ORDER BY journal_issue";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while($row = $result->fetch_assoc()) {
        $journals[] = $row;
    }
    $stmt->close();
    return $journals;
}

// Get unique articles for a specific client and journal from jobs table
function getArticles($mysqli, $client_id, $journal_issue) {
    $articles = [];
    $sql = "SELECT DISTINCT article, job_id 
            FROM jobs 
            WHERE client_id = ? AND journal_issue = ? AND article IS NOT NULL AND article != '' 
            ORDER BY article";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("is", $client_id, $journal_issue);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while($row = $result->fetch_assoc()) {
        $articles[] = $row;
    }
    $stmt->close();
    return $articles;
}

// Get job details for a specific article
function getJobDetails($mysqli, $job_id) {
    $sql = "SELECT j.*, cm.client_name 
            FROM jobs j 
            JOIN client_master cm ON j.client_id = cm.client_id 
            WHERE j.job_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $job_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $job = $result->fetch_assoc();
    $stmt->close();
    return $job;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $sales_in_charge = trim($_POST['sales_in_charge'] ?? '');
        $send_date = trim($_POST['send_date'] ?? '');
        $client_id = (int)($_POST['client_id'] ?? 0);
        $journal_issue = trim($_POST['journal_issue'] ?? '');
        $article = trim($_POST['article'] ?? '');
        $job_id = (int)($_POST['job_id'] ?? 0);
        $article_count = (int)($_POST['article_count'] ?? 1);
        $particular = trim($_POST['particular'] ?? '');
        $process = trim($_POST['process'] ?? '');
        $charges = (float)($_POST['charges'] ?? 0);
        $client_confirmed_date = trim($_POST['client_confirmed_date'] ?? '');
        $send_confirmation_to_team = isset($_POST['send_confirmation_to_team']) ? 1 : 0;
        $confirmation_date_to_team = trim($_POST['confirmation_date_to_team'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        
        // Validation
        if ($client_id === 0 || empty($journal_issue) || empty($article)) {
            redirect_with(['msg' => 'Please select client, journal and article.', 'type' => 'danger']);
        }
        
        if (empty($send_date)) {
            redirect_with(['msg' => 'Send date is required.', 'type' => 'danger']);
        }
        
        // Convert empty dates to NULL
        $client_confirmed_date = empty($client_confirmed_date) ? null : $client_confirmed_date;
        $confirmation_date_to_team = empty($confirmation_date_to_team) ? null : $confirmation_date_to_team;
        
        if ($action === 'add') {
            // Insert new transaction
            $stmt = $mysqli->prepare("INSERT INTO client_transactions 
                (sales_in_charge, send_date, client_id, journal_issue, article, job_id,
                 article_count, particular, process, charges, client_confirmed_date,
                 send_confirmation_to_team, confirmation_date_to_team, remarks) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->bind_param("ssissiisddssss", 
                $sales_in_charge, $send_date, $client_id, $journal_issue, $article, $job_id,
                $article_count, $particular, $process, $charges, $client_confirmed_date,
                $send_confirmation_to_team, $confirmation_date_to_team, $remarks);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Transaction added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding transaction: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $transaction_id = (int)($_POST['transaction_id'] ?? 0);
            
            if ($transaction_id > 0) {
                // Update transaction
                $stmt = $mysqli->prepare("UPDATE client_transactions SET 
                    sales_in_charge = ?, send_date = ?, client_id = ?, journal_issue = ?, 
                    article = ?, job_id = ?, article_count = ?, particular = ?, process = ?, 
                    charges = ?, client_confirmed_date = ?, send_confirmation_to_team = ?, 
                    confirmation_date_to_team = ?, remarks = ?, updated_date = NOW() 
                    WHERE transaction_id = ?");
                
                $stmt->bind_param("ssissiisddssssi", 
                    $sales_in_charge, $send_date, $client_id, $journal_issue, $article, $job_id,
                    $article_count, $particular, $process, $charges, $client_confirmed_date,
                    $send_confirmation_to_team, $confirmation_date_to_team, $remarks, $transaction_id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Transaction updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating transaction: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $transaction_id = (int)($_POST['transaction_id'] ?? 0);
        
        if ($transaction_id > 0) {
            // Get transaction details for message
            $res = $mysqli->prepare("SELECT cm.client_name, ct.journal_issue, ct.article 
                                   FROM client_transactions ct
                                   JOIN client_master cm ON ct.client_id = cm.client_id
                                   WHERE ct.transaction_id = ?");
            $res->bind_param("i", $transaction_id);
            $res->execute();
            $res->bind_result($client_name, $journal_issue, $article);
            $res->fetch();
            $res->close();
            
            // Delete transaction
            $del = $mysqli->prepare("DELETE FROM client_transactions WHERE transaction_id = ?");
            $del->bind_param("i", $transaction_id);
            
            if ($del->execute()) {
                redirect_with(['msg' => "Transaction for '$client_name - $journal_issue - $article' deleted successfully", 'type' => 'warning']);
            } else {
                redirect_with(['msg' => 'Error deleting transaction: ' . $del->error, 'type' => 'danger']);
            }
            $del->close();
        }
    }
    
    redirect_with();
}

// AJAX handlers for dropdowns
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'get_journals' && isset($_GET['client_id'])) {
        $client_id = (int)$_GET['client_id'];
        $journals = getJournals($mysqli, $client_id);
        echo json_encode($journals);
        exit;
    }
    
    if ($_GET['ajax'] === 'get_articles' && isset($_GET['client_id']) && isset($_GET['journal_issue'])) {
        $client_id = (int)$_GET['client_id'];
        $journal_issue = $_GET['journal_issue'];
        $articles = getArticles($mysqli, $client_id, $journal_issue);
        echo json_encode($articles);
        exit;
    }
    
    if ($_GET['ajax'] === 'get_job_details' && isset($_GET['job_id'])) {
        $job_id = (int)$_GET['job_id'];
        $job = getJobDetails($mysqli, $job_id);
        echo json_encode($job);
        exit;
    }
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editTransaction = null;

if ($mode === 'edit') {
    $transaction_id = (int)($_GET['id'] ?? 0);
    if ($transaction_id > 0) {
        $stmt = $mysqli->prepare("SELECT ct.*, cm.client_name
                                 FROM client_transactions ct
                                 JOIN client_master cm ON ct.client_id = cm.client_id
                                 WHERE ct.transaction_id = ?");
        $stmt->bind_param("i", $transaction_id);
        $stmt->execute();
        $editTransaction = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editTransaction) $mode = 'view';
}

// Get clients for dropdown
$clients = getClients($mysqli);

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$client_filter = (int)($_GET['client_filter'] ?? 0);
$sort = $_GET['sort'] ?? 'ct.created_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query with JOINs
$sql = "SELECT 
            ct.transaction_id,
            ct.sales_in_charge,
            ct.send_date,
            ct.client_id,
            cm.client_name,
            ct.journal_issue,
            ct.article,
            ct.job_id,
            ct.article_count,
            ct.particular,
            ct.process,
            ct.charges,
            ct.client_confirmed_date,
            ct.send_confirmation_to_team,
            ct.confirmation_date_to_team,
            ct.remarks,
            ct.created_date,
            ct.updated_date
        FROM client_transactions ct
        JOIN client_master cm ON ct.client_id = cm.client_id
        WHERE 1=1";
        
$params = [];
$types = '';

if ($q !== '') {
    $sql .= " AND (cm.client_name LIKE ? OR ct.journal_issue LIKE ? OR ct.article LIKE ? OR ct.particular LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if ($client_filter > 0) {
    $sql .= " AND cm.client_id = ?";
    $params[] = $client_filter;
    $types .= 'i';
}

$sql .= " ORDER BY $sort $dir";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $transactions = $stmt->get_result();
} else {
    $transactions = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$transactions) {
    $transactions = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Transactions</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 2000px;
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
        .dropdown-loading {
            color: #6c757d;
            font-style: italic;
        }
        .job-info-box {
            background: #d1ecf1;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: none;
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
            <h4><i class="fas fa-exchange-alt me-2"></i><?= $mode === 'edit' ? 'Edit Transaction' : 'Add New Transaction' ?></h4>
            <a href="client_transactions.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off" id="transactionForm">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <input type="hidden" name="job_id" id="job_id" value="<?= h($editTransaction['job_id'] ?? '') ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="transaction_id" value="<?= (int)($editTransaction['transaction_id'] ?? 0) ?>">
                    <?php endif; ?>

                    <!-- Basic Information -->
                    <div class="form-section">
                        <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Basic Information</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="sales_in_charge" class="form-label">Sales In Charge</label>
                                    <input type="text" class="form-control" id="sales_in_charge" name="sales_in_charge" 
                                           value="<?= h($editTransaction['sales_in_charge'] ?? '') ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="send_date" class="form-label">Send Date to Client <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="send_date" name="send_date" 
                                           value="<?= h($editTransaction['send_date'] ?? '') ?>" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Client, Journal & Article Selection -->
                    <div class="form-section">
                        <h5 class="mb-3"><i class="fas fa-layer-group me-2"></i>Client & Article Selection</h5>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="client_id" class="form-label">Client Name <span class="text-danger">*</span></label>
                                    <select class="form-control" id="client_id" name="client_id" required>
                                        <option value="">Select Client</option>
                                        <?php foreach($clients as $client): ?>
                                            <option value="<?= $client['client_id'] ?>" 
                                                <?= (isset($editTransaction['client_id']) && $editTransaction['client_id'] == $client['client_id']) ? 'selected' : '' ?>>
                                                <?= h($client['client_name']) ?> (<?= h($client['country']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="journal_issue" class="form-label">Journal Issue <span class="text-danger">*</span></label>
                                    <select class="form-control" id="journal_issue" name="journal_issue" required <?= !isset($editTransaction['journal_issue']) ? 'disabled' : '' ?>>
                                        <option value="">Select Journal Issue</option>
                                        <?php if(isset($editTransaction['journal_issue'])): ?>
                                            <option value="<?= h($editTransaction['journal_issue']) ?>" selected>
                                                <?= h($editTransaction['journal_issue']) ?>
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="article" class="form-label">Article <span class="text-danger">*</span></label>
                                    <select class="form-control" id="article" name="article" required <?= !isset($editTransaction['article']) ? 'disabled' : '' ?>>
                                        <option value="">Select Article</option>
                                        <?php if(isset($editTransaction['article'])): ?>
                                            <option value="<?= h($editTransaction['article']) ?>" data-job-id="<?= h($editTransaction['job_id'] ?? '') ?>" selected>
                                                <?= h($editTransaction['article']) ?>
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="article_count" class="form-label">Article Count</label>
                                    <input type="number" class="form-control" id="article_count" name="article_count" 
                                           value="<?= h($editTransaction['article_count'] ?? 1) ?>" min="1" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="charges" class="form-label">Charges</label>
                                    <input type="number" class="form-control" id="charges" name="charges" 
                                           value="<?= h($editTransaction['charges'] ?? '') ?>" step="0.01" min="0">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Job Information Display -->
                    <div id="jobInfo" class="job-info-box">
                        <h6 class="mb-3"><i class="fas fa-tasks me-2"></i>Job Information</h6>
                        <div class="row">
                            <div class="col-md-3">
                                <strong>Job Date:</strong> <span id="infoJobDate">—</span>
                            </div>
                            <div class="col-md-3">
                                <strong>Due Date:</strong> <span id="infoDueDate">—</span>
                            </div>
                            <div class="col-md-3">
                                <strong>Assigned To:</strong> <span id="infoAssignedTo">—</span>
                            </div>
                            <div class="col-md-3">
                                <strong>Team:</strong> <span id="infoTeam">—</span>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-4">
                                <strong>Assigned CE:</strong> <span id="infoAssignedCE">—</span>
                            </div>
                            <div class="col-md-4">
                                <strong>Assigned LE:</strong> <span id="infoAssignedLE">—</span>
                            </div>
                            <div class="col-md-4">
                                <strong>Assigned XML:</strong> <span id="infoAssignedXML">—</span>
                            </div>
                        </div>
                    </div>

                    <!-- Process Details -->
                    <div class="form-section">
                        <h5 class="mb-3"><i class="fas fa-cogs me-2"></i>Process Details</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="particular" class="form-label">Particular</label>
                                    <textarea class="form-control" id="particular" name="particular" rows="3"><?= h($editTransaction['particular'] ?? '') ?></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="process" class="form-label">Process</label>
                                    <textarea class="form-control" id="process" name="process" rows="3"><?= h($editTransaction['process'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Confirmation Details -->
                    <div class="form-section">
                        <h5 class="mb-3"><i class="fas fa-calendar-check me-2"></i>Confirmation Details</h5>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="client_confirmed_date" class="form-label">Client Confirmed Date</label>
                                    <input type="date" class="form-control" id="client_confirmed_date" name="client_confirmed_date" 
                                           value="<?= h($editTransaction['client_confirmed_date'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <div class="form-check mt-4">
                                        <input class="form-check-input" type="checkbox" name="send_confirmation_to_team" 
                                               id="send_confirmation_to_team" <?= (isset($editTransaction['send_confirmation_to_team']) && $editTransaction['send_confirmation_to_team']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="send_confirmation_to_team">
                                            Send Confirmation to Team
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="confirmation_date_to_team" class="form-label">Confirmation Date to Team</label>
                                    <input type="date" class="form-control" id="confirmation_date_to_team" name="confirmation_date_to_team" 
                                           value="<?= h($editTransaction['confirmation_date_to_team'] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Remarks -->
                    <div class="form-section">
                        <h5 class="mb-3"><i class="fas fa-sticky-note me-2"></i>Remarks</h5>
                        <div class="mb-3">
                            <textarea class="form-control" id="remarks" name="remarks" rows="3"><?= h($editTransaction['remarks'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Transaction' : 'Save Transaction' ?>
                        </button>
                        <a href="client_transactions.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-exchange-alt me-2"></i>Client Transactions</h4>
            <a href="client_transactions.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add Transaction
            </a>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search clients, journals, articles..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="client_filter" class="form-select form-select-sm">
                            <option value="0">All Clients</option>
                            <?php foreach($clients as $client): ?>
                                <option value="<?= $client['client_id'] ?>" <?= $client_filter == $client['client_id'] ? 'selected' : '' ?>>
                                    <?= h($client['client_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="ct.created_date" <?= $sort === 'ct.created_date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="cm.client_name" <?= $sort === 'cm.client_name' ? 'selected' : '' ?>>Sort by Client</option>
                            <option value="ct.journal_issue" <?= $sort === 'ct.journal_issue' ? 'selected' : '' ?>>Sort by Journal</option>
                            <option value="ct.charges" <?= $sort === 'ct.charges' ? 'selected' : '' ?>>Sort by Charges</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="dir" class="form-select form-select-sm">
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option>
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2 w-100">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                    </div>
                    <div class="col-md-12 mt-2">
                        <a href="client_transactions.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Transactions Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="30">ID</th>
                                <th>Sales In Charge</th>
                                <th>Send Date</th>
                                <th>Client Name</th>
                                <th>Journal Issue</th>
                                <th>Article</th>
                                <th>Article Count</th>
                                <th>Particular</th>
                                <th>Process</th>
                                <th>Charges</th>
                                <th>Client Confirmed</th>
                                <th>Team Confirmation</th>
                                <th>Team Confirm Date</th>
                                <th>Remarks</th>
                                <th>Created Date</th>
                                <th width="100">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($transactions && $transactions->num_rows > 0): $i=0;?>
                                <?php while($row = $transactions->fetch_assoc()): $i=$i+1;?>
                                <tr>
                                    <td class="fw-bold"><?= $i ?></td>
                                    <td><?= h($row['sales_in_charge']) ?: '—' ?></td>
                                    <td><?= formatDate($row['send_date']) ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= h($row['client_name']) ?></div>
                                        <small class="text-muted">ID: <?= h($row['client_id']) ?></small>
                                    </td>
                                    <td><?= h($row['journal_issue']) ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= h($row['article']) ?></div>
                                        <?php if($row['job_id']): ?>
                                            <small class="text-muted">Job ID: <?= h($row['job_id']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($row['article_count']) ?></td>
                                    <td>
                                        <?php if(!empty($row['particular'])): ?>
                                            <span title="<?= h($row['particular']) ?>">
                                                <?= strlen($row['particular']) > 50 ? substr(h($row['particular']), 0, 50) . '...' : h($row['particular']) ?>
                                            </span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['process'])): ?>
                                            <span title="<?= h($row['process']) ?>">
                                                <?= strlen($row['process']) > 50 ? substr(h($row['process']), 0, 50) . '...' : h($row['process']) ?>
                                            </span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($row['charges'] > 0): ?>
                                            <span class="fw-bold text-success"><?= number_format($row['charges'], 2) ?></span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td><?= formatDate($row['client_confirmed_date']) ?></td>
                                    <td class="text-center">
                                        <?php if($row['send_confirmation_to_team']): ?>
                                            <span class="badge bg-success"><i class="fas fa-check"></i></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="fas fa-times"></i></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= formatDate($row['confirmation_date_to_team']) ?></td>
                                    <td>
                                        <?php if(!empty($row['remarks'])): ?>
                                            <span title="<?= h($row['remarks']) ?>">
                                                <?= strlen($row['remarks']) > 30 ? substr(h($row['remarks']), 0, 30) . '...' : h($row['remarks']) ?>
                                            </span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td><?= formatDate($row['created_date']) ?></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="client_transactions.php?mode=edit&id=<?= (int)$row['transaction_id'] ?>" 
                                               class="btn btn-warning" title="Edit Transaction">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this transaction?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="transaction_id" value="<?= (int)$row['transaction_id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Transaction">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="16" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-exchange-alt fa-3x mb-3"></i>
                                            <h5>No transactions found</h5>
                                            <p class="mb-0">Get started by adding your first client transaction.</p>
                                            <a href="client_transactions.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Transaction
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
            // Auto-dismiss alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert.isConnected) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                }, 5000);
            });

            // Client-Journal-Article cascading dropdowns
            const clientSelect = document.getElementById('client_id');
            const journalSelect = document.getElementById('journal_issue');
            const articleSelect = document.getElementById('article');
            const jobIdInput = document.getElementById('job_id');
            const jobInfoBox = document.getElementById('jobInfo');

            function loadJournals(clientId) {
                if (!clientId) {
                    journalSelect.innerHTML = '<option value="">Select Journal Issue</option>';
                    journalSelect.disabled = true;
                    articleSelect.innerHTML = '<option value="">Select Article</option>';
                    articleSelect.disabled = true;
                    jobInfoBox.style.display = 'none';
                    return;
                }

                journalSelect.innerHTML = '<option value="" class="dropdown-loading">Loading journals...</option>';
                journalSelect.disabled = false;

                fetch(`client_transactions.php?ajax=get_journals&client_id=${clientId}`)
                    .then(response => response.json())
                    .then(journals => {
                        journalSelect.innerHTML = '<option value="">Select Journal Issue</option>';
                        journals.forEach(journal => {
                            const option = document.createElement('option');
                            option.value = journal.journal_issue;
                            option.textContent = journal.journal_issue;
                            journalSelect.appendChild(option);
                        });
                        
                        // Clear articles
                        articleSelect.innerHTML = '<option value="">Select Article</option>';
                        articleSelect.disabled = true;
                        jobInfoBox.style.display = 'none';
                    })
                    .catch(error => {
                        console.error('Error loading journals:', error);
                        journalSelect.innerHTML = '<option value="">Error loading journals</option>';
                    });
            }

            function loadArticles(clientId, journalIssue) {
                if (!clientId || !journalIssue) {
                    articleSelect.innerHTML = '<option value="">Select Article</option>';
                    articleSelect.disabled = true;
                    jobInfoBox.style.display = 'none';
                    return;
                }

                articleSelect.innerHTML = '<option value="" class="dropdown-loading">Loading articles...</option>';
                articleSelect.disabled = false;

                fetch(`client_transactions.php?ajax=get_articles&client_id=${clientId}&journal_issue=${encodeURIComponent(journalIssue)}`)
                    .then(response => response.json())
                    .then(articles => {
                        articleSelect.innerHTML = '<option value="">Select Article</option>';
                        articles.forEach(article => {
                            const option = document.createElement('option');
                            option.value = article.article;
                            option.textContent = article.article;
                            option.setAttribute('data-job-id', article.job_id);
                            articleSelect.appendChild(option);
                        });
                        jobInfoBox.style.display = 'none';
                    })
                    .catch(error => {
                        console.error('Error loading articles:', error);
                        articleSelect.innerHTML = '<option value="">Error loading articles</option>';
                    });
            }

            function loadJobDetails(jobId) {
                if (!jobId) {
                    jobInfoBox.style.display = 'none';
                    return;
                }

                fetch(`client_transactions.php?ajax=get_job_details&job_id=${jobId}`)
                    .then(response => response.json())
                    .then(job => {
                        if (job) {
                            document.getElementById('infoJobDate').textContent = job.job_date ? new Date(job.job_date).toLocaleDateString() : '—';
                            document.getElementById('infoDueDate').textContent = job.due_date ? new Date(job.due_date).toLocaleDateString() : '—';
                            document.getElementById('infoAssignedTo').textContent = job.assigned_to || '—';
                            document.getElementById('infoTeam').textContent = job.team_name || '—';
                            document.getElementById('infoAssignedCE').textContent = job.assigned_ce || '—';
                            document.getElementById('infoAssignedLE').textContent = job.assigned_le || '—';
                            document.getElementById('infoAssignedXML').textContent = job.assigned_xml || '—';
                            jobInfoBox.style.display = 'block';
                        } else {
                            jobInfoBox.style.display = 'none';
                        }
                    })
                    .catch(error => {
                        console.error('Error loading job details:', error);
                        jobInfoBox.style.display = 'none';
                    });
            }

            if (clientSelect) {
                clientSelect.addEventListener('change', function() {
                    loadJournals(this.value);
                });

                // Initialize if editing
                <?php if($mode === 'edit' && isset($editTransaction['client_id'])): ?>
                loadJournals(<?= $editTransaction['client_id'] ?>);
                <?php endif; ?>
            }

            if (journalSelect) {
                journalSelect.addEventListener('change', function() {
                    const clientId = clientSelect.value;
                    loadArticles(clientId, this.value);
                });

                // Initialize if editing
                <?php if($mode === 'edit' && isset($editTransaction['journal_issue'])): ?>
                loadArticles(<?= $editTransaction['client_id'] ?>, '<?= $editTransaction['journal_issue'] ?>');
                <?php endif; ?>
            }

            if (articleSelect) {
                articleSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const jobId = selectedOption.getAttribute('data-job-id');
                    jobIdInput.value = jobId || '';
                    loadJobDetails(jobId);
                });

                // Initialize if editing
                <?php if($mode === 'edit' && isset($editTransaction['article'])): ?>
                loadJobDetails(<?= $editTransaction['job_id'] ?? 'null' ?>);
                <?php endif; ?>
            }

            // Auto-submit form on filter changes
            const filterSelects = document.querySelectorAll('select[name="sort"], select[name="dir"], select[name="client_filter"]');
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });
        });
    </script>
</body>
</html>