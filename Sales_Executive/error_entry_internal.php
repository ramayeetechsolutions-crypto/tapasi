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

function redirect_with($params =[]) {
    $base = 'error_entry_internal.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Fetch dropdown data from master tables
$employees = $mysqli->query("SELECT employee_name FROM employee_master WHERE status = 'Active' ORDER BY employee_name");
$clients = $mysqli->query("SELECT client_name FROM client_master WHERE status = 'Active' ORDER BY client_name");

// Fetch articles from jobs table
$articles_query = $mysqli->query("
    SELECT DISTINCT j.article, cm.client_name 
    FROM jobs j 
    LEFT JOIN client_master cm ON j.client_id = cm.client_id 
    WHERE j.article != '' AND j.article IS NOT NULL 
    ORDER BY cm.client_name, j.article
");

// Create an array of articles with client names
$articles_data =[];
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
    
    // ---------------------------------------------------------
    // HANDLE IMPORT LOGIC (CSV & XLSX Format)
    // ---------------------------------------------------------
    if ($action === 'import_excel') {
        if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
            
            $ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
            $rows =[];
            $parse_error = '';

            // Handle CSV Import directly
            if ($ext === 'csv') {
                if (($handle = fopen($_FILES['excel_file']['tmp_name'], "r")) !== FALSE) {
                    // Handle potential BOM in CSV
                    $bom = fread($handle, 3);
                    if ($bom !== "\xEF\xBB\xBF") {
                        rewind($handle);
                    }
                    while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
                        $rows[] = $data;
                    }
                    fclose($handle);
                } else {
                    $parse_error = "Failed to open CSV file.";
                }
            } 
            // Handle XLSX Import via Library
            else {
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
                } else {
                    redirect_with(['msg' => 'Class SimpleXLSX not found.', 'type' => 'danger']);
                }

                if ($xlsx) {
                    $rows = $xlsx->rows();
                } else {
                    $parse_error = (method_exists('Shuchkin\SimpleXLSX', 'parseError')) ? Shuchkin\SimpleXLSX::parseError() : 'Unknown Error Parsing XLSX';
                }
            }

            // 3. Process Rows
            if (!empty($rows)) {
                $imported_count = 0;
                $updated_count = 0;
                $skipped_count = 0;
                $error_details =[];
                
                foreach ($rows as $k => $r) {
                    if ($k === 0) continue; // Skip Header Row

                    // --- COLUMN MAPPING ---
                    // Columns: Week, Date, Client, Article, Type of Errors, Team Error Made By, Error Taken By, Reason for Errors
                    
                    $week = trim($r[0] ?? '');
                    $date_raw = $r[1] ?? '';
                    $client = trim($r[2] ?? '');
                    $article = trim($r[3] ?? '');
                    $type_of_errors = trim($r[4] ?? '');
                    $team_error_made_by = trim($r[5] ?? '');
                    $error_taken_by = trim($r[6] ?? '');
                    $reason_for_errors = trim($r[7] ?? '');
                    $status = 'Active';

                    // Skip if essential data is missing
                    if (empty($week) || empty($client)) {
                        $skipped_count++;
                        $error_details[] = "Row " . ($k + 1) . ": Missing Week or Client";
                        continue;
                    }

                    // Handle Date conversion (Excel numeric dates or string dates from CSV)
                    $date = NULL;
                    if (!empty($date_raw)) {
                        if (is_numeric($date_raw) && $ext !== 'csv') {
                            if (class_exists('Shuchkin\SimpleXLSX')) {
                                $date = date('Y-m-d', Shuchkin\SimpleXLSX::unixstamp($date_raw));
                            } else {
                                $unix = ($date_raw - 25569) * 86400;
                                $date = gmdate("Y-m-d", $unix);
                            }
                        } else {
                            $ts = strtotime($date_raw);
                            if ($ts !== false) $date = date('Y-m-d', $ts);
                        }
                    } else {
                        $date = date('Y-m-d'); // Default fallback for required field
                    }

                    // Check if entry already exists based on Week, Date, Client, and Article
                    $checkStmt = $mysqli->prepare("SELECT id FROM article_errors WHERE week = ? AND date = ? AND client = ? AND article = ?");
                    $checkStmt->bind_param("ssss", $week, $date, $client, $article);
                    $checkStmt->execute();
                    $exists = $checkStmt->get_result()->num_rows > 0;
                    $checkStmt->close();

                    if (!$exists) {
                        // Insert new record
                        $insertStmt = $mysqli->prepare("INSERT INTO article_errors 
                            (week, date, client, article, type_of_errors, team_error_made_by, error_taken_by, reason_for_errors, status, created_date) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        
                        $insertStmt->bind_param("sssssssss", 
                            $week, $date, $client, $article, $type_of_errors, $team_error_made_by, $error_taken_by, $reason_for_errors, $status
                        );
                        
                        if ($insertStmt->execute()) {
                            $imported_count++;
                        } else {
                            $skipped_count++;
                            $error_details[] = "Row " . ($k + 1) . ": " . $mysqli->error;
                        }
                        $insertStmt->close();
                    } else {
                        // Update existing record
                        $updateStmt = $mysqli->prepare("UPDATE article_errors SET 
                            type_of_errors = ?, team_error_made_by = ?, error_taken_by = ?, reason_for_errors = ? 
                            WHERE week = ? AND date = ? AND client = ? AND article = ?");
                        
                        $updateStmt->bind_param("ssssssss", 
                            $type_of_errors, $team_error_made_by, $error_taken_by, $reason_for_errors, $week, $date, $client, $article
                        );
                        
                        if ($updateStmt->execute()) {
                            $updated_count++;
                        }
                        $updateStmt->close();
                    }
                }
                
                $msg = "Process Complete.<br>";
                $msg .= "<strong>Imported:</strong> $imported_count new entries.<br>";
                $msg .= "<strong>Updated:</strong> $updated_count existing entries.<br>";
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
                redirect_with(['msg' => 'Parse Error: ' . ($parse_error ?: 'File contains no valid data'), 'type' => 'danger']);
            }
        } else {
            redirect_with(['msg' => 'No file uploaded or file error.', 'type' => 'danger']);
        }
    }
    
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
$params =[];
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
            <?= $_GET['msg'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
        
        <!-- Add/Edit Form -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-file-alt me-2"></i><?= $mode === 'edit' ? 'Edit Article Error Entry' : 'Add New Article Error Entry' ?></h4>
            <a href="error_entry_internal.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <!-- (Your existing Add/Edit form remains exactly the same here) -->
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
                                        <?php $clients->data_seek(0); ?>
                                        <?php while($client_row = $clients->fetch_assoc()): ?>
                                        <option value="<?= h($client_row['client_name']) ?>" 
                                            <?= ($editEntry['client'] ?? '') === $client_row['client_name'] ? 'selected' : '' ?>>
                                            <?= h($client_row['client_name']) ?>
                                        </option>
                                        <?php endwhile; ?>
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
                                <textarea class="form-control" id="type_of_errors" name="type_of_errors" 
                                          rows="3" placeholder="Describe the type of errors"><?= h($editEntry['type_of_errors'] ?? '') ?></textarea>
                                <div class="form-text">Describe the types of errors found (e.g., formatting, content, technical, etc.)</div>
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
                        <a href="error_entry_internal.php" class="btn btn-outline-secondary">Cancel</a>
                        <?php if($mode === 'edit'): ?>
                        <a href="error_entry_internal.php?mode=add" class="btn btn-outline-success">
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
            <div>
                <h4><i class="fas fa-file-alt me-2"></i>Internal Error</h4>
            </div>
            <div>
                <button type="button" class="btn btn-warning text-dark fw-bold btn-sm me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-excel me-2"></i> Import File
                </button>
                <a href="error_entry_internal.php?mode=add" class="btn btn-success btn-sm me-2">
                    <i class="fas fa-plus me-1"></i> Add New Entry
                </a>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center" id="searchForm">
                    <!-- Your Existing Filter Elements -->
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
                       
                    </div>
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="error_entry_internal.php" class="btn btn-secondary btn-sm me-2">
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
                                <th width="50">S.No</th>
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
                            <?php if($entries && $entries->num_rows > 0): $i=0;?>
                                <?php while($row = $entries->fetch_assoc()): $i=$i+1;?>
                                <tr>
                                    <td class="fw-bold"><?= $i; ?></td>
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
                                            <a href="error_entry_internal.php?mode=edit&id=<?= (int)$row['id'] ?>" 
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
                                            <a href="error_entry_internal.php?mode=add" class="btn btn-primary mt-2">
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

    <!-- IMPORT MODAL -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-file-excel me-2"></i>Import Entries from File
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <form action="" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Select File (.csv or .xlsx)</label>
                                    <input type="file" name="excel_file" class="form-control" accept=".xlsx,.csv" required>
                                    <small class="text-muted">Maximum file size: 5MB</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Download Sample</label>
                                <button type="button" class="btn btn-outline-success w-100" onclick="downloadSample()">
                                    <i class="fas fa-file-csv me-1"></i> Sample CSV Format
                                </button>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <h6 class="fw-bold"><i class="fas fa-info-circle me-1"></i> Excel/CSV Format Required:</h6>
                            <p class="mb-1"><strong>Columns in exact order (No extra columns):</strong></p>
                            <ol class="mb-2">
                                <li><strong>Week</strong> - Required (e.g. Week 1, Jan-2024)</li>
                                <li><strong>Date</strong> - Excel Date or format like DD/MM/YYYY</li>
                                <li><strong>Client</strong> - Required</li>
                                <li><strong>Article</strong> - Optional</li>
                                <li><strong>Type of Errors</strong> - Optional</li>
                                <li><strong>Team Error Made By</strong> - Optional</li>
                                <li><strong>Error Taken By</strong> - Optional</li>
                                <li><strong>Reason for Errors</strong> - Optional</li>
                            </ol>
                            
                            <div class="border-top pt-2 mt-2">
                                <p class="mb-1"><strong><i class="fas fa-exclamation-triangle me-1"></i> Rules:</strong></p>
                                <ul class="mb-0">
                                    <li>New records will be <span class="badge bg-success">INSERTED</span></li>
                                    <li>Existing records (matching Week + Date + Client + Article) will be <span class="badge bg-warning">UPDATED</span></li>
                                    <li>Rows missing "Week" or "Client" will be <span class="badge bg-danger">SKIPPED</span></li>
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
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

            $('.select2').select2({ width: '100%', placeholder: function() { return $(this).data('placeholder'); }, allowClear: true, tags: false });
        });
        
        // Generates CSV format
        function downloadSample() {
            const sampleContent = "Week,Date,Client,Article,Type of Errors,Team Error Made By,Error Taken By,Reason for Errors\n" +
                                 "Week 1 Jan-2024,10-01-2024,Tech Solutions,Guide 101,Formatting Issue,John Doe,Jane Smith,Missed guideline\n" +
                                 "Week 2 Jan-2024,15-01-2024,Global Media,,Grammar,Alice,,Lack of review";
            
            const blob = new Blob([sampleContent], { type: 'text/csv;charset=utf-8;' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sample_article_errors_import.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>