<?php
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
    $base = 'error_entry_team.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Fetch dropdown data from master tables
$employees = $mysqli->query("SELECT employee_name FROM employee_master WHERE status = 'Active' ORDER BY employee_name");
$clients = $mysqli->query("SELECT client_name, team_client_name FROM client_master WHERE status = 'Active' ORDER BY client_name");

// Fetch articles from jobs table
$articles_query = $mysqli->query("
    SELECT DISTINCT j.article, cm.client_name 
    FROM jobs j 
    LEFT JOIN client_master cm ON j.client_id = cm.client_id 
    WHERE j.article != '' AND j.article IS NOT NULL 
    ORDER BY cm.client_name, j.article
");

// Create an array of articles with client names
$articles_data = [];
if ($articles_query && $articles_query->num_rows > 0) {
    while($row = $articles_query->fetch_assoc()) {
        $articles_data[] = [
            'article' => $row['article'],
            'client_name' => $row['client_name']
        ];
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $week = trim($_POST['week'] ?? '');
        $date = trim($_POST['date'] ?? '');
        $client = trim($_POST['client'] ?? '');
        $article = trim($_POST['article'] ?? '');
        $type_of_errors = trim($_POST['type_of_errors'] ?? '');
        $team_error_made_by = trim($_POST['team_error_made_by'] ?? '');
        $error_taken_by = trim($_POST['error_taken_by'] ?? '');
        $reason_for_errors = trim($_POST['reason_for_errors'] ?? '');
        
        // Basic validation
        if ($week === '' || $date === '' || $client === '') {
            redirect_with(['msg' => 'Week, Date and Client are required.', 'type' => 'danger']);
        }
        
        // Convert date format for MySQL
        $mysql_date = date('Y-m-d', strtotime($date));
        
        if ($action === 'add') {
            // Insert new error log
            $stmt = $mysqli->prepare("INSERT INTO article_errors (week, date, client, article, type_of_errors, team_error_made_by, error_taken_by, reason_for_errors, status, created_date) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW())");
            $stmt->bind_param("ssssssss", $week, $mysql_date, $client, $article, $type_of_errors, $team_error_made_by, $error_taken_by, $reason_for_errors);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Article error entry added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding entry: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Update error log
                $stmt = $mysqli->prepare("UPDATE article_errors SET week = ?, date = ?, client = ?, article = ?, 
                                         type_of_errors = ?, team_error_made_by = ?, error_taken_by = ?, reason_for_errors = ? 
                                         WHERE id = ?");
                $stmt->bind_param("ssssssssi", $week, $mysql_date, $client, $article, $type_of_errors, $team_error_made_by, $error_taken_by, $reason_for_errors, $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Article error updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating entry: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            // Get client name for message
            $res = $mysqli->prepare("SELECT client FROM article_errors WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($client_name);
            $res->fetch();
            $res->close();
            
            // Delete entry
            $del = $mysqli->prepare("DELETE FROM article_errors WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Entry for client '$client_name' deleted", 'type' => 'warning']);
        }
    }
    
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT status, client FROM article_errors WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($current_status, $client_name);
            $res->fetch();
            $res->close();
            
            if ($client_name) {
                $newStatus = ($current_status === 'Active') ? 'Inactive' : 'Active';
                
                $upd = $mysqli->prepare("UPDATE article_errors SET status = ? WHERE id = ?");
                $upd->bind_param("si", $newStatus, $id);
                $upd->execute();
                $upd->close();
                
                redirect_with(['msg' => "Entry for '$client_name' status changed to $newStatus", 'type' => 'info']);
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
        $stmt = $mysqli->prepare("SELECT id, week, date, client, article, type_of_errors, team_error_made_by, error_taken_by, reason_for_errors, status, created_date 
                                 FROM article_errors WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editEntry = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // Format date for display
        if ($editEntry && $editEntry['date'] && $editEntry['date'] !== '0000-00-00') {
            $editEntry['date'] = date('d-m-Y', strtotime($editEntry['date']));
        }
    }
    if (!$editEntry) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$filter_client = trim($_GET['client'] ?? '');
$filter_team = trim($_GET['team'] ?? '');
$filter_week = trim($_GET['week'] ?? '');
$filter_article = trim($_GET['article'] ?? '');
$sort = $_GET['sort'] ?? 'created_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query
$sql = "SELECT id, week, date, client, article, type_of_errors, team_error_made_by, error_taken_by, reason_for_errors, status, created_date 
        FROM article_errors";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(client LIKE ? OR article LIKE ? OR type_of_errors LIKE ? OR team_error_made_by LIKE ? OR error_taken_by LIKE ? OR reason_for_errors LIKE ?)";
    $like = "%$q%";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    $types .= 'ssssss';
}

if ($filter_client !== '') {
    $cond[] = "client = ?";
    $params[] = $filter_client;
    $types .= 's';
}

if ($filter_team !== '') {
    $cond[] = "(team_error_made_by = ? OR error_taken_by = ?)";
    $params[] = $filter_team;
    $params[] = $filter_team;
    $types .= 'ss';
}

if ($filter_week !== '') {
    $cond[] = "week = ?";
    $params[] = $filter_week;
    $types .= 's';
}

if ($filter_article !== '') {
    $cond[] = "article = ?";
    $params[] = $filter_article;
    $types .= 's';
}

if ($fstatus === 'Active' || $fstatus === 'Inactive') {
    $cond[] = "status = ?";
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
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $entries = $stmt->get_result();
} else {
    $entries = $mysqli->query($sql);
}

// Get unique values for filter dropdowns
$unique_clients = $mysqli->query("SELECT DISTINCT client FROM article_errors WHERE client != '' ORDER BY client");
$unique_weeks = $mysqli->query("SELECT DISTINCT week FROM article_errors WHERE week != '' ORDER BY week");
$unique_teams = $mysqli->query("SELECT DISTINCT team_error_made_by FROM article_errors WHERE team_error_made_by != '' ORDER BY team_error_made_by");
$unique_error_takers = $mysqli->query("SELECT DISTINCT error_taken_by FROM article_errors WHERE error_taken_by != '' ORDER BY error_taken_by");
$unique_articles = $mysqli->query("SELECT DISTINCT article FROM article_errors WHERE article != '' ORDER BY article");

// If query fails, show empty result
if (!$entries) {
    $entries = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Article Error Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
        .table thead th {
            background-color: #707981 !important;
            font-weight: bold;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            color: white !important;
            padding: 0.8rem 0.5rem;
            border: 1px solid #5a6268 !important;
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
        .form-label {
            font-weight: 500;
        }
        .select2-container--default .select2-selection--single {
            height: 38px;
            padding: 5px;
            border: 1px solid #ced4da;
        }
        .badge {
            font-size: 0.85em;
        }
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
        }
        .article-option {
            display: flex;
            justify-content: space-between;
        }
        .article-text {
            font-weight: 500;
        }
        .client-text {
            font-size: 0.85em;
            color: #666;
        }
        .view-btn {
            font-size: 0.8rem;
            padding: 0.15rem 0.5rem;
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
            <h4><i class="fas fa-file-alt me-2"></i><?= $mode === 'edit' ? 'Edit Article Error Entry' : 'Add New Article Error Entry' ?></h4>
            <a href="error_entry_team.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off" id="articleErrorForm">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editEntry['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="week" class="form-label">Week <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="week" name="week" 
                                       value="<?= h($editEntry['week'] ?? '') ?>" 
                                       placeholder="e.g., Week 1, Jan-2024" required>
                                <div class="form-text">Format: Week 1, Jan-2024</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="date" class="form-label">Date <span class="text-danger">*</span></label>
                                <input type="text" class="form-control datepicker" id="date" name="date" 
                                       value="<?= h($editEntry['date'] ?? '') ?>" 
                                       placeholder="DD-MM-YYYY" required>
                                <div class="form-text">Format: DD-MM-YYYY</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="client" class="form-label">Client <span class="text-danger">*</span></label>
                                <select class="form-control select2" id="client" name="client" required
                                        data-placeholder="Select client" onchange="filterArticlesByClient()">
                                    <option value=""></option>
                                    <!-- Existing entries from master table -->
                                    <?php if($clients && $clients->num_rows > 0): ?>
                                        <?php 
                                        $clients->data_seek(0); 
                                        $displayed_clients = [];
                                        while($client_row = $clients->fetch_assoc()): 
                                            $display_name = !empty($client_row['team_client_name']) ? $client_row['team_client_name'] : $client_row['client_name'];
                                            
                                            if (!in_array($display_name, $displayed_clients)):
                                                $displayed_clients[] = $display_name;
                                        ?>
                                        <option value="<?= h($display_name) ?>" 
                                            <?= ($editEntry['client'] ?? '') === $display_name ? 'selected' : '' ?>>
                                            <?= h($display_name) ?>
                                        </option>
                                        <?php 
                                            endif;
                                        endwhile; 
                                        ?>
                                    <?php endif; ?>
                                    <!-- Existing entries from article_errors table -->
                                    <?php if($unique_clients && $unique_clients->num_rows > 0): ?>
                                        <?php $unique_clients->data_seek(0); ?>
                                        <?php while($client = $unique_clients->fetch_assoc()): ?>
                                        <option value="<?= h($client['client']) ?>" 
                                            <?= ($editEntry['client'] ?? '') === $client['client'] ? 'selected' : '' ?>>
                                            <?= h($client['client']) ?>
                                        </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="article" class="form-label">Article</label>
                                <select class="form-control select2" id="article" name="article"
                                        data-placeholder="Select article from jobs">
                                    <option value=""></option>
                                    <!-- Articles from jobs table -->
                                    <?php if(!empty($articles_data)): ?>
                                        <?php foreach($articles_data as $article_item): ?>
                                        <option value="<?= h($article_item['article']) ?>" 
                                            data-client="<?= h($article_item['client_name']) ?>"
                                            <?= ($editEntry['article'] ?? '') === $article_item['article'] ? 'selected' : '' ?>>
                                            <div class="article-option">
                                                <span class="article-text"><?= h($article_item['article']) ?></span>
                                                <span class="client-text"><?= h($article_item['client_name']) ?></span>
                                            </div>
                                        </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <!-- Existing entries from article_errors table (for backward compatibility) -->
                                    <?php if($unique_articles && $unique_articles->num_rows > 0): ?>
                                        <?php $unique_articles->data_seek(0); ?>
                                        <?php while($article = $unique_articles->fetch_assoc()): ?>
                                        <option value="<?= h($article['article']) ?>" 
                                            <?= ($editEntry['article'] ?? '') === $article['article'] ? 'selected' : '' ?>>
                                            <?= h($article['article']) ?>
                                        </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                                <div class="form-text">Select from existing articles in jobs</div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="type_of_errors" class="form-label">Type of Errors</label>
                                <select class="form-select select2" id="type_of_errors" name="type_of_errors" data-placeholder="Select type of error">
                                    <option value=""></option>
                                    <option value="Acknowledgement Issue" <?= ($editEntry['type_of_errors'] ?? '') === 'Acknowledgement Issue' ? 'selected' : '' ?>>Acknowledgement Issue</option>
                                    <option value="NA" <?= ($editEntry['type_of_errors'] ?? '') === 'NA' ? 'selected' : '' ?>>NA</option>
                                    <option value="Technical Issue" <?= ($editEntry['type_of_errors'] ?? '') === 'Technical Issue' ? 'selected' : '' ?>>Technical Issue</option>
                                    <option value="Missed TAT" <?= ($editEntry['type_of_errors'] ?? '') === 'Missed TAT' ? 'selected' : '' ?>>Missed TAT</option>
                                    <option value="Entry Miss" <?= ($editEntry['type_of_errors'] ?? '') === 'Entry Miss' ? 'selected' : '' ?>>Entry Miss</option>
                                </select>
                                <div class="form-text">Select the category of the error</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="team_error_made_by" class="form-label">Team/Error Made By</label>
                                <select class="form-control select2" id="team_error_made_by" name="team_error_made_by"
                                        data-placeholder="Select employee">
                                    <option value=""></option>
                                    <!-- Existing entries from employee_master table -->
                                    <?php if($employees && $employees->num_rows > 0): ?>
                                        <?php $employees->data_seek(0); ?>
                                        <?php while($emp_row = $employees->fetch_assoc()): ?>
                                        <option value="<?= h($emp_row['employee_name']) ?>" 
                                            <?= ($editEntry['team_error_made_by'] ?? '') === $emp_row['employee_name'] ? 'selected' : '' ?>>
                                            <?= h($emp_row['employee_name']) ?>
                                        </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                    <!-- Existing entries from article_errors table (for backward compatibility) -->
                                    <?php if($unique_teams && $unique_teams->num_rows > 0): ?>
                                        <?php $unique_teams->data_seek(0); ?>
                                        <?php while($team = $unique_teams->fetch_assoc()): ?>
                                        <option value="<?= h($team['team_error_made_by']) ?>" 
                                            <?= ($editEntry['team_error_made_by'] ?? '') === $team['team_error_made_by'] ? 'selected' : '' ?>>
                                            <?= h($team['team_error_made_by']) ?>
                                        </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                                <div class="form-text">Select employee who made the error</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="error_taken_by" class="form-label">Error Taken By</label>
                                <select class="form-control select2" id="error_taken_by" name="error_taken_by"
                                        data-placeholder="Select employee">
                                    <option value=""></option>
                                    <!-- Existing entries from employee_master table -->
                                    <?php if($employees && $employees->num_rows > 0): ?>
                                        <?php $employees->data_seek(0); ?>
                                        <?php while($emp_row = $employees->fetch_assoc()): ?>
                                        <option value="<?= h($emp_row['employee_name']) ?>" 
                                            <?= ($editEntry['error_taken_by'] ?? '') === $emp_row['employee_name'] ? 'selected' : '' ?>>
                                            <?= h($emp_row['employee_name']) ?>
                                        </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                    <!-- Existing entries from article_errors table (for backward compatibility) -->
                                    <?php if($unique_error_takers && $unique_error_takers->num_rows > 0): ?>
                                        <?php $unique_error_takers->data_seek(0); ?>
                                        <?php while($taker = $unique_error_takers->fetch_assoc()): ?>
                                        <option value="<?= h($taker['error_taken_by']) ?>" 
                                            <?= ($editEntry['error_taken_by'] ?? '') === $taker['error_taken_by'] ? 'selected' : '' ?>>
                                            <?= h($taker['error_taken_by']) ?>
                                        </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                                <div class="form-text">Select employee who took/corrected the error</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="reason_for_errors" class="form-label">Reason for Errors</label>
                                <textarea class="form-control" id="reason_for_errors" name="reason_for_errors" 
                                          rows="3" placeholder="Reason for errors"><?= h($editEntry['reason_for_errors'] ?? '') ?></textarea>
                                <div class="form-text">Explain why the errors occurred</div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Entry' : 'Save Entry' ?>
                        </button>
                        <a href="error_entry_team.php" class="btn btn-outline-secondary">Cancel</a>
                        <?php if($mode === 'edit'): ?>
                        <a href="error_entry_team.php?mode=add" class="btn btn-outline-success">
                            <i class="fas fa-plus me-1"></i> Add New
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-file-alt me-2"></i>Article Error Management</h4>
            <div>
                <a href="error_entry_team.php?mode=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Add New Entry
                </a>
                <button class="btn btn-outline-primary btn-sm" onclick="exportToExcel()">
                    <i class="fas fa-file-excel me-1"></i> Export Excel
                </button>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center" id="searchForm">
                    <div class="col-md-2">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Global search..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="week" class="form-select form-select-sm">
                            <option value="">All Weeks</option>
                            <?php if($unique_weeks && $unique_weeks->num_rows > 0): ?>
                                <?php $unique_weeks->data_seek(0); ?>
                                <?php while($week = $unique_weeks->fetch_assoc()): ?>
                                <option value="<?= h($week['week']) ?>" 
                                    <?= $filter_week === $week['week'] ? 'selected' : '' ?>>
                                    <?= h($week['week']) ?>
                                </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="client" class="form-select form-select-sm">
                            <option value="">All Clients</option>
                            <?php if($unique_clients && $unique_clients->num_rows > 0): ?>
                                <?php $unique_clients->data_seek(0); ?>
                                <?php while($client = $unique_clients->fetch_assoc()): ?>
                                <option value="<?= h($client['client']) ?>" 
                                    <?= $filter_client === $client['client'] ? 'selected' : '' ?>>
                                    <?= h($client['client']) ?>
                                </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="article" class="form-select form-select-sm">
                            <option value="">All Articles</option>
                            <?php if($unique_articles && $unique_articles->num_rows > 0): ?>
                                <?php $unique_articles->data_seek(0); ?>
                                <?php while($article = $unique_articles->fetch_assoc()): ?>
                                <option value="<?= h($article['article']) ?>" 
                                    <?= $filter_article === $article['article'] ? 'selected' : '' ?>>
                                    <?= h($article['article']) ?>
                                </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="team" class="form-select form-control-sm">
                            <option value="">All Employees</option>
                            <?php 
                            $all_employees = $mysqli->query("
                                SELECT employee_name as name FROM employee_master WHERE status = 'Active' AND employee_name != ''
                                ORDER BY employee_id 
                            ");
                            if($all_employees && $all_employees->num_rows > 0):
                            ?>
                                <?php $all_employees->data_seek(0); ?>
                                <?php while($emp = $all_employees->fetch_assoc()): ?>
                                <option value="<?= h($emp['name']) ?>" 
                                    <?= $filter_team === $emp['name'] ? 'selected' : '' ?>>
                                    <?= h($emp['name']) ?>
                                </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <select name="status" class="form-select form-control-sm">
                            <option value="">All Status</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <select name="sort" class="form-select form-control-sm">
                            <option value="created_date" <?= $sort === 'created_date' ? 'selected' : '' ?>>Date</option>
                            <option value="week" <?= $sort === 'week' ? 'selected' : '' ?>>Week</option>
                            <option value="client" <?= $sort === 'client' ? 'selected' : '' ?>>Client</option>
                        </select>
                        <!-- <select name="dir" class="form-select form-control-sm mt-1">
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Desc</option>
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Asc</option>
                        </select> -->
                    </div>
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="error_entry_team.php" class="btn btn-secondary btn-sm me-2">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                        <span class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            <?php if($entries && $entries->num_rows > 0): ?>
                                <?= $entries->num_rows ?> entries found
                            <?php else: ?>
                                No entries found
                            <?php endif; ?>
                        </span>
                    </div>
                </form>
            </div>
        </div>

        <!-- Article Errors Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="articleErrorTable">
                        <thead class="table-light">
                            <tr>
                                <th width="50">ID</th>
                                <th width="100">Week</th>
                                <th width="100">Date</th>
                                <th>Client</th>
                                <th>Article</th>
                                <th width="120">Type of Errors</th>
                                <th width="120">Error Made By</th>
                                <th width="120">Error Taken By</th>
                                <th width="120">Reason for Errors</th>
                                
                                <th width="120">Created Date</th>
                                <th width="150" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($entries && $entries->num_rows > 0): ?>
                                <?php while($row = $entries->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?= (int)$row['id'] ?></td>
                                    <td>
                                        <span class="badge bg-primary"><?= h($row['week']) ?></span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= h(formatDate($row['date'])) ?></small>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= h($row['client']) ?></span>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['article'])): ?>
                                            <span class="text-primary fw-medium"><?= h($row['article']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['type_of_errors'])): 
                                             echo   $row['type_of_errors'];
                                           
                                        else:?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($row['team_error_made_by'] ?: '—') ?></td>
                                    <td><?= h($row['error_taken_by'] ?: '—') ?></td>
                                    <td>
                                        <?php if(!empty($row['reason_for_errors'])):
                                        echo ($row['reason_for_errors']);   
                                        else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= h(formatDate($row['created_date'])) ?></small>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="error_entry_team.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit Entry">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete entry for <?= h(addslashes($row['client'])) ?>?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Entry">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                        
                                        <!-- Type of Errors Modal -->
                                        <?php if(!empty($row['type_of_errors'])): ?>
                                        <div class="modal fade" id="typeModal<?= $row['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">
                                                            <i class="fas fa-exclamation-triangle me-2"></i>Type of Errors - <?= h($row['client']) ?>
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="bg-light p-3 rounded">
                                                            <p class="mb-0"><?= nl2br(h($row['type_of_errors'])) ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <!-- Reason for Errors Modal -->
                                        <?php if(!empty($row['reason_for_errors'])): ?>
                                        <div class="modal fade" id="reasonModal<?= $row['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">
                                                            <i class="fas fa-comment me-2"></i>Reason for Errors - <?= h($row['client']) ?>
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="row mb-3">
                                                            <div class="col-md-6">
                                                                <p><strong>Week:</strong> <?= h($row['week']) ?></p>
                                                                <p><strong>Date:</strong> <?= h(formatDate($row['date'])) ?></p>
                                                                <p><strong>Client:</strong> <?= h($row['client']) ?></p>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <p><strong>Article:</strong> <?= h($row['article'] ?: '—') ?></p>
                                                                <p><strong>Error Made By:</strong> <?= h($row['team_error_made_by'] ?: '—') ?></p>
                                                                <p><strong>Error Taken By:</strong> <?= h($row['error_taken_by'] ?: '—') ?></p>
                                                            </div>
                                                        </div>
                                                        <hr>
                                                        <h6><i class="fas fa-sticky-note me-2"></i>Reason for Errors:</h6>
                                                        <div class="bg-light p-3 rounded">
                                                            <p class="mb-0"><?= nl2br(h($row['reason_for_errors'])) ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="12" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-file-alt fa-3x mb-3"></i>
                                            <h5>No article error entries found</h5>
                                            <p class="mb-0">Get started by adding your first article error entry.</p>
                                            <a href="error_entry_team.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Entry
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
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

            // Initialize Select2 for form dropdowns with custom template for articles
            $('.select2').select2({
                width: '100%',
                placeholder: function() {
                    return $(this).data('placeholder');
                },
                allowClear: true,
                tags: false, // Disable tags since we're selecting from predefined lists
                templateResult: function(data) {
                    if (!data.id) {
                        return data.text;
                    }
                    
                    // Check if this is an article option with custom HTML
                    if (data.element && $(data.element).find('.article-option').length) {
                        return $('<span class="article-option">' + 
                               '<span class="article-text">' + data.text + '</span>' +
                               '<span class="client-text">' + $(data.element).data('client') + '</span>' +
                               '</span>');
                    }
                    
                    return data.text;
                },
                templateSelection: function(data) {
                    if (!data.id) {
                        return data.text;
                    }
                    
                    // For selected item, show just the name
                    if (data.element && $(data.element).find('.article-option').length) {
                        return data.text;
                    }
                    
                    return data.text;
                }
            });
            
            $('.select2-sm').select2({
                width: '100%',
                placeholder: 'Select',
                minimumResultsForSearch: 10
            });

            // Initialize Select2 for employee dropdowns with no tags
            $('#team_error_made_by, #error_taken_by').select2({
                width: '100%',
                placeholder: 'Select employee',
                allowClear: true,
                tags: false,
                minimumResultsForSearch: 5
            });

            // Date picker functionality
            const dateInput = document.getElementById('date');
            if (dateInput) {
                dateInput.addEventListener('focus', function() {
                    if (!this.value) {
                        const today = new Date();
                        const day = String(today.getDate()).padStart(2, '0');
                        const month = String(today.getMonth() + 1).padStart(2, '0');
                        const year = today.getFullYear();
                        this.value = `${day}-${month}-${year}`;
                    }
                });
            }

            // Form validation
            const form = document.getElementById('articleErrorForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const clientSelect = document.getElementById('client');
                    const weekInput = document.getElementById('week');
                    const dateInput = document.getElementById('date');
                    
                    let isValid = true;
                    
                    // Reset previous errors
                    [clientSelect, weekInput, dateInput].forEach(el => {
                        el.classList.remove('is-invalid');
                        const feedback = el.nextElementSibling;
                        if (feedback && feedback.classList.contains('invalid-feedback')) {
                            feedback.remove();
                        }
                    });
                    
                    // Validate client
                    if (!clientSelect.value) {
                        clientSelect.classList.add('is-invalid');
                        const feedback = document.createElement('div');
                        feedback.className = 'invalid-feedback';
                        feedback.textContent = 'Please select a client';
                        clientSelect.parentNode.appendChild(feedback);
                        isValid = false;
                    }
                    
                    // Validate week
                    if (!weekInput.value.trim()) {
                        weekInput.classList.add('is-invalid');
                        const feedback = document.createElement('div');
                        feedback.className = 'invalid-feedback';
                        feedback.textContent = 'Week is required';
                        weekInput.parentNode.appendChild(feedback);
                        isValid = false;
                    }
                    
                    // Validate date
                    if (!dateInput.value.trim()) {
                        dateInput.classList.add('is-invalid');
                        const feedback = document.createElement('div');
                        feedback.className = 'invalid-feedback';
                        feedback.textContent = 'Date is required';
                        dateInput.parentNode.appendChild(feedback);
                        isValid = false;
                    } else if (!/^\d{2}-\d{2}-\d{4}$/.test(dateInput.value)) {
                        dateInput.classList.add('is-invalid');
                        const feedback = document.createElement('div');
                        feedback.className = 'invalid-feedback';
                        feedback.textContent = 'Date must be in DD-MM-YYYY format';
                        dateInput.parentNode.appendChild(feedback);
                        isValid = false;
                    }
                    
                    if (!isValid) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                });
            }
            
            // Initialize all modals
            var commentModals = document.querySelectorAll('.modal');
            commentModals.forEach(function(modal) {
                new bootstrap.Modal(modal);
            });
        });
        
        // Function to filter articles by selected client
        function filterArticlesByClient() {
            const clientSelect = document.getElementById('client');
            const articleSelect = document.getElementById('article');
            const selectedClient = clientSelect.value;
            
            if (!selectedClient) {
                // If no client selected, show all articles
                $(articleSelect).find('option').show();
                return;
            }
            
            // Hide all options first
            $(articleSelect).find('option').hide();
            
            // Show the empty option
            $(articleSelect).find('option[value=""]').show();
            
            // Show articles that match the selected client or don't have client data
            $(articleSelect).find('option').each(function() {
                const $option = $(this);
                const articleClient = $option.data('client');
                
                if (!articleClient || articleClient === selectedClient) {
                    $option.show();
                }
            });
            
            // Reset selection if current selection is not visible
            const currentValue = articleSelect.value;
            const currentOption = $(articleSelect).find(`option[value="${currentValue}"]`);
            if (!currentOption.is(':visible')) {
                articleSelect.value = '';
                $(articleSelect).trigger('change');
            }
        }
        
        // Export to Excel function
        function exportToExcel() {
            const table = document.getElementById('articleErrorTable');
            if (!table) return;
            
            // Create a new table for export (excluding Actions column)
            const exportTable = document.createElement('table');
            
            // Clone the header row without the last column (Actions)
            const originalHeader = table.rows[0];
            const exportHeader = document.createElement('tr');
            
            // Export headers (excluding Actions column)
            const headers = ['ID', 'Week', 'Date', 'Client', 'Article', 'Type of Errors', 'Error Made By', 'Error Taken By', 'Reason for Errors', 'Created Date'];
            
            headers.forEach(headerText => {
                const th = document.createElement('th');
                th.textContent = headerText;
                exportHeader.appendChild(th);
            });
            
            exportTable.appendChild(exportHeader);
            
            // Process data rows
            const dataRows = table.querySelectorAll('tbody tr');
            dataRows.forEach(originalRow => {
                // Skip empty rows (no entries message)
                if (originalRow.cells.length <= 1) return;
                
                const exportRow = document.createElement('tr');
                
                // Extract data from each column
                const cells = originalRow.cells;
                
                // ID
                let cellText = cells[0].textContent.trim();
                let td = document.createElement('td');
                td.textContent = cellText;
                exportRow.appendChild(td);
                
                // Week
                const weekBadge = cells[1].querySelector('.badge');
                cellText = weekBadge ? weekBadge.textContent.trim() : cells[1].textContent.trim();
                td = document.createElement('td');
                td.textContent = cellText;
                exportRow.appendChild(td);
                
                // Date
                const dateSmall = cells[2].querySelector('small');
                cellText = dateSmall ? dateSmall.textContent.trim() : cells[2].textContent.trim();
                td = document.createElement('td');
                td.textContent = cellText;
                exportRow.appendChild(td);
                
                // Client
                const clientSpan = cells[3].querySelector('.fw-semibold');
                cellText = clientSpan ? clientSpan.textContent.trim() : cells[3].textContent.trim();
                td = document.createElement('td');
                td.textContent = cellText;
                exportRow.appendChild(td);
                
                // Article
                const articleSpan = cells[4].querySelector('.text-primary');
                cellText = articleSpan ? articleSpan.textContent.trim() : cells[4].textContent.trim();
                td = document.createElement('td');
                td.textContent = cellText === '—' ? '' : cellText;
                exportRow.appendChild(td);
                
                // Type of Errors (extract from modal data if available)
                cellText = cells[5].textContent.replace('View', '').trim();
                td = document.createElement('td');
                td.textContent = cellText === '—' ? '' : cellText;
                exportRow.appendChild(td);
                
                // Error Made By
                cellText = cells[6].textContent.trim();
                td = document.createElement('td');
                td.textContent = cellText === '—' ? '' : cellText;
                exportRow.appendChild(td);
                
                // Error Taken By
                cellText = cells[7].textContent.trim();
                td = document.createElement('td');
                td.textContent = cellText === '—' ? '' : cellText;
                exportRow.appendChild(td);
                
                // Reason for Errors
                cellText = cells[8].textContent.replace('View', '').trim();
                td = document.createElement('td');
                td.textContent = cellText === '—' ? '' : cellText;
                exportRow.appendChild(td);
                
                
                // Created Date
                const createdSmall = cells[9].querySelector('small');
                cellText = createdSmall ? createdSmall.textContent.trim() : cells[10].textContent.trim();
                td = document.createElement('td');
                td.textContent = cellText;
                exportRow.appendChild(td);
                
                exportTable.appendChild(exportRow);
            });
            
            // Create worksheet
            const ws = XLSX.utils.table_to_sheet(exportTable);
            
            // Set column widths
            const wscols = [
                {wch: 8},   // ID
                {wch: 15},  // Week
                {wch: 12},  // Date
                {wch: 25},  // Client
                {wch: 30},  // Article
                {wch: 40},  // Type of Errors
                {wch: 20},  // Error Made By
                {wch: 20},  // Error Taken By
                {wch: 40},  // Reason for Errors
                
                {wch: 15}   // Created Date
            ];
            ws['!cols'] = wscols;
            
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Article Errors");
            
            // Generate filename
            const date = new Date().toISOString().slice(0, 10);
            const filename = `Article_Errors_${date}.xlsx`;
            
            XLSX.writeFile(wb, filename);
        }
    </script>
</body>
</html>