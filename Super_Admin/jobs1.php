<br><br><br>
<?php
// Start a session if one is not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    if(isset($_SESSION['uid'])) $exeid=$_SESSION['uid'];
}
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

function normalize_process($p){
    if(!$p) return '';
    $parts = array_map('trim', explode('+', strtoupper(str_replace(' ', '', $p))));
    sort($parts);
    return implode('+', $parts);
}

function canDoStage($empStages,$stage,$hier){
    foreach($empStages as $es){
        if(isset($hier[$es]) && in_array($stage,$hier[$es])) return true;
    }
    return false;
}

function redirect_to($params = []) {
    $url = 'jobs.php';
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    header("Location: $url");
    exit;
}

function formatDate($date, $format = 'd-m-Y') {
    if (!$date) return '';
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    return $dateObj ? $dateObj->format($format) : $date;
}

$processStageMap = [
    'CE'          => ['CE','LE'],
    'PAG'         => ['PAG','QC'],
    'CE+PAG'      => ['CE','LE','PAG','QC'],
    'CE+PAG+XML'  => ['CE','LE','PAG','QC','XML'],
    'CE+XML'      => ['CE','LE','XML'],
    'PAG+XML'     => ['PAG','QC','XML'],
    'XML'         => ['XML'],
];

$skillHierarchy = [
    'CE'  => ['CE','LE'],
    'PAG' => ['PAG','QC'],
    'XML' => ['XML'],
];


$logged_user = $_SESSION['username'] ?? '';
$role_name = $_SESSION['role_name'] ?? '';

// Get current user's process/stages
$current_user_process = '';
$current_user_stages = [];
if (isset($_SESSION['uid'])) {
    $user_id = $_SESSION['uid'];
    $stmt = $mysqli->prepare("
        SELECT GROUP_CONCAT(pm.process_name SEPARATOR ',') AS processes 
        FROM employee_master em 
        LEFT JOIN process_master pm ON FIND_IN_SET(pm.process_id, em.process) > 0 
        WHERE em.employee_id = ?
        GROUP BY em.employee_id
    ");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($processes);
        if ($stmt->fetch()) {
            $current_user_process = $processes;
            $norm_proc = normalize_process($processes);
            $current_user_stages = array_filter(explode(',', $norm_proc));
        }
        $stmt->close();
    }
}
//echo "CE". $current_user_process;
$search_client = trim($_GET['search_client'] ?? '');
$sort = $_GET['sort'] ?? 'job_id';
$dir = strtoupper($_GET['dir'] ?? 'DESC');
if (!in_array($dir, ['ASC', 'DESC'], true)) $dir = 'DESC';

$allowed_sorts = ['job_id', 'job_date', 'client_name', 'job_name', 'article', 'assigned_to', 'due_date'];
if (!in_array($sort, $allowed_sorts, true)) $sort = 'job_id';

$default_due_date = date('Y-m-d H:i:s', strtotime('+5 days'));

// Load all processes
$all_processes = [];
$res = $mysqli->query("SELECT job_id, job_name FROM job_master WHERE status='Active' ORDER BY job_name");
if (!$res) die('Error loading processes: ' . $mysqli->error);
while ($r = $res->fetch_assoc()) $all_processes[$r['job_id']] = $r['job_name'];

// Get all clients
$clients_res = $mysqli->query("SELECT client_id, client_name FROM client_master WHERE status='Active' ORDER BY client_name");
if (!$clients_res) die('Error fetching clients: ' . $mysqli->error);

// Load all active employees
$employees = [];
$st = $mysqli->prepare("SELECT employee_id, employee_username, employee_name, user_role_id, COALESCE(jm.job_name, em.process) AS process
                        FROM employee_master em
                        LEFT JOIN job_master jm ON em.process = jm.job_id
                        WHERE em.status='Active'");
if ($st) {
    $st->execute();
    $resEmps = $st->get_result();
    while ($e = $resEmps->fetch_assoc()) {
        $procNorm = normalize_process($e['process']);
        $stages = array_filter(explode('+', $procNorm));
        $e['label'] = $e['employee_name'] . ' (' . $e['process'] . ')';
        $e['stages'] = $stages;
        $employees[] = $e;
    }
    $st->close();
}

// Get current user's reporting manager to filter TMs
$current_user_id = $_SESSION['uid'] ?? 0;
$reporting_manager = '';
if ($current_user_id) {
    $stmt = $mysqli->prepare("SELECT reporting_manager FROM employee_master WHERE employee_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $stmt->bind_result($reporting_manager);
        $stmt->fetch();
        $stmt->close();
    }
}

// Get TMs under the same reporting manager
$tms_under_manager = [];
if ($reporting_manager) {
    $tm_query = "SELECT em.employee_id, em.employee_username, em.employee_name 
                 FROM employee_master em 
                 JOIN user_role_master ur ON em.user_role_id = ur.role_id
                 WHERE em.reporting_manager = ? 
                 AND em.status = 'Active' 
                 AND ur.role_name = 'TM'
                 ORDER BY em.employee_name";
    
    $stmt = $mysqli->prepare($tm_query);
    if ($stmt) {
        $stmt->bind_param("s", $reporting_manager);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($tm = $result->fetch_assoc()) {
            $tms_under_manager[] = $tm;
        }
        $stmt->close();
    }
}

// --- Handle POST requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $job_id = (int)($_POST['job_id'] ?? 0);
    $job_date = $_POST['job_date'] ?: date('Y-m-d');
    $client_id = (int)($_POST['client_id'] ?? 0);
    $journal = trim($_POST['journal_issue'] ?? '');
    $mail_subject = trim($_POST['mail_subject'] ?? '');
    $assigned_to_tm = trim($_POST['assigned_to_tm'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $queries = trim($_POST['queries'] ?? '');
    $word_count = (int)($_POST['word_count'] ?? 0);
    $due_date = $default_due_date;
    
    if ($action === 'add') {
        // Validation
        if (!$client_id || !$journal) {
            redirect_to(['msg' => 'Please fill all required fields', 'type' => 'danger']);
        }
        
        // Get the COMMON process ID
        $process_id = (int)($_POST['process_id'] ?? 0);
        if ($process_id <= 0) {
            redirect_to(['msg' => 'Please select a valid process', 'type' => 'danger']);
        }
        
        // Get articles from either textarea OR individual fields
        $articles = [];
        
        if (isset($_POST['articles_textarea']) && !empty(trim($_POST['articles_textarea']))) {
            // Method 1: From textarea (split by new lines)
            $raw_articles = trim($_POST['articles_textarea']);
            $articles = array_filter(
                array_map('trim', explode("\n", $raw_articles)),
                function($article) {
                    return !empty($article);
                }
            );
        } elseif (isset($_POST['article_individual']) && is_array($_POST['article_individual'])) {
            // Method 2: From individual fields
            $articles = array_filter(
                array_map('trim', $_POST['article_individual']),
                function($article) {
                    return !empty($article);
                }
            );
        } else {
            redirect_to(['msg' => 'Please enter at least one article', 'type' => 'danger']);
        }
        
        if (empty($articles)) {
            redirect_to(['msg' => 'No valid articles provided', 'type' => 'danger']);
        }
        
        // Get reporting manager for assignment
        $uid = $_SESSION['uid'] ?? 0;
        $assigned_to = '';
        $assigned_name = '';
        $assigned_name1 = '';
        
        $sql = "SELECT employee_name, reporting_manager FROM employee_master WHERE employee_id = ?";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) die("Error preparing: " . $mysqli->error);
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($row = $res->fetch_assoc()) {
            $assigned_name = $row['reporting_manager'];
            $assigned_name1 = $row['employee_name'];
        }
        $stmt->close();

        if ($role_name == 'TM') {
            $assigned_to = $assigned_name;
        } else if ($role_name == 'TL') {
            $assigned_to = $assigned_name1;
        }

        // Process each article (with SAME process for all)
        $success_count = 0;
        $errors = [];
        
        foreach ($articles as $article) {
            // Check for duplicates for each article
            $checkSql = "SELECT COUNT(*) FROM jobs WHERE journal_issue = ? AND article = ?";
            $stmtC = $mysqli->prepare($checkSql);
            if (!$stmtC) die('Error preparing uniqueness check: ' . $mysqli->error);
            $stmtC->bind_param('ss', $journal, $article);
            $stmtC->execute();
            $stmtC->bind_result($dupCount);
            $stmtC->fetch();
            $stmtC->close();
            
            if ($dupCount > 0) {
                $errors[] = "Journal '{$journal}' and Article '{$article}' combination already exists.";
                continue;
            }
            
            // Insert job
            $stmt = $mysqli->prepare("
                INSERT INTO jobs (
                    job_date, client_id, journal_issue, mail_subject, article, process_id,
                    assigned_to, assigned_to_tm, assigned_ce, assigned_le, assigned_pag,
                    assigned_qc, assigned_xml, due_date, due_date_ce,
                    due_date_le, due_date_pag, due_date_qc,
                    notes, queries, word_count
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,?)
            ");
            if (!$stmt) {
                $errors[] = 'Error preparing jobs insert: ' . $mysqli->error;
                continue;
            }

            // Set default values for assigned stages
            $assigned_ce = null;
            $assigned_le = null;
            $assigned_pag = null;
            $assigned_qc = null;
            $assigned_xml = null;
            $due_date_ce = date('Y-m-d H:i:s', strtotime('+1 days'));
            $due_date_le = date('Y-m-d H:i:s', strtotime('+2 days'));
            $due_date_pag = date('Y-m-d H:i:s', strtotime('+3 days'));
            $due_date_qc = date('Y-m-d H:i:s', strtotime('+4 days'));

            $stmt->bind_param(
                'sissssssssssssssssssi',
                $job_date,
                $client_id,
                $journal,
                $mail_subject,
                $article,
                $process_id,
                $assigned_to,
                $assigned_to_tm,
                $assigned_ce,
                $assigned_le,
                $assigned_pag,
                $assigned_qc,
                $assigned_xml,
                $due_date,
                $due_date_ce,
                $due_date_le,
                $due_date_pag,
                $due_date_qc,
                $notes,
                $queries,
                $word_count
            );

            if ($stmt->execute()) {
                $new_job_id = $mysqli->insert_id;
                $success_count++;
                
                // Get client name
                $client_name = '';
                $stmt2 = $mysqli->prepare("SELECT client_name FROM client_master WHERE client_id = ?");
                $stmt2->bind_param("i", $client_id);
                $stmt2->execute();
                $stmt2->bind_result($client_name);
                $stmt2->fetch();
                $stmt2->close();

                // Get process name
                $process_name = '';
                $stmt3 = $mysqli->prepare("SELECT job_name FROM job_master WHERE job_id = ?");
                $stmt3->bind_param("i", $process_id);
                $stmt3->execute();
                $stmt3->bind_result($process_name);
                $stmt3->fetch();
                $stmt3->close();

                // Insert into respective tables based on process
                if (in_array($process_id, [1, 4, 5, 37])) {
                    // CE
                    $stmt4 = $mysqli->prepare("
                        INSERT INTO ce (job_id, date, client, issue, articles, process, status, created_at, last_updated)
                        VALUES (?, ?, ?, ?, ?, ?, 'Need to Assign', NOW(), NOW())
                    ");
                    $stmt4->bind_param("isssss", $new_job_id, $job_date, $client_name, $journal, $article, $process_name);
                    $stmt4->execute();
                    $stmt4->close();

                    // LE
                    $stmt5 = $mysqli->prepare("
                        INSERT INTO le (job_id, date, client, issue, articles, process, status, created_at, last_updated)
                        VALUES (?, ?, ?, ?, ?, ?, 'Need to Assign', NOW(), NOW())
                    ");
                    $stmt5->bind_param("isssss", $new_job_id, $job_date, $client_name, $journal, $article, $process_name);
                    $stmt5->execute();
                    $stmt5->close();
                }

                if (in_array($process_id, [2, 4, 5])) {
                    // PAG
                    $stmt6 = $mysqli->prepare("
                        INSERT INTO pag (job_id, date, client, issue, articles, process, status, created_at, last_updated)
                        VALUES (?, ?, ?, ?, ?, ?, 'Need to Assign', NOW(), NOW())
                    ");
                    $stmt6->bind_param("isssss", $new_job_id, $job_date, $client_name, $journal, $article, $process_name);
                    $stmt6->execute();
                    $stmt6->close();

                    // QC
                    $stmt7 = $mysqli->prepare("
                        INSERT INTO qc 
                        (job_id, date, client, journal_issue, article, process, status, created_at, last_updated)
                        VALUES (?, ?, ?, ?, ?, ?, 'Need to Assign', NOW(), NOW())
                    ");
                    $stmt7->bind_param("isssss", $new_job_id, $job_date, $client_name, $journal, $article, $process_name);
                    $stmt7->execute();
                    $stmt7->close();
                }
            } else {
                $errors[] = "Failed to insert article '{$article}': " . $stmt->error;
            }
            $stmt->close();
        }

        // Prepare response message
        if ($success_count > 0) {
            $msg = $success_count . ' job(s) added successfully';
            $type = 'success';
            
            if (!empty($errors)) {
                $msg .= ' (' . count($errors) . ' skipped due to errors)';
            }
        } else {
            $msg = 'No jobs were added. ' . implode(' ', array_slice($errors, 0, 3));
            $type = 'danger';
        }
        
        redirect_to(['msg' => $msg, 'type' => $type]);
    }
    elseif ($action === 'edit') {
        // Edit logic for single article
        $article = isset($_POST['article']) ? trim($_POST['article']) : '';
        $process_id = (int)($_POST['process_id'] ?? 0);
        
        if (!$article || !$process_id) {
            redirect_to(['msg' => 'Article and Process are required', 'type' => 'danger']);
        }

        // Check for duplicates excluding current job
        $checkSql = "SELECT COUNT(*) FROM jobs WHERE journal_issue = ? AND article = ? AND job_id != ?";
        $stmtC = $mysqli->prepare($checkSql);
        if (!$stmtC) die('Error preparing uniqueness check: ' . $mysqli->error);
        $stmtC->bind_param('ssi', $journal, $article, $job_id);
        $stmtC->execute();
        $stmtC->bind_result($dupCount);
        $stmtC->fetch();
        $stmtC->close();
        
        if ($dupCount > 0) {
            redirect_to(['msg' => 'Journal and Article combination already exists.', 'type' => 'danger']);
        }

        // Update the job record
        $stmt = $mysqli->prepare("UPDATE jobs SET job_date=?, client_id=?, journal_issue=?, mail_subject=?, article=?, process_id=?, due_date=?, assigned_to_tm=?, notes=?, queries=?, word_count=? WHERE job_id=?");
        if (!$stmt) die('Error preparing update: ' . $mysqli->error);
        $stmt->bind_param('sissssssssii', $job_date, $client_id, $journal, $mail_subject, $article, $process_id, $due_date, $assigned_to_tm, $notes, $queries, $word_count, $job_id);
        if (!$stmt->execute()) {
            die('Update failed: ' . $stmt->error);
        }
        $stmt->close();
        
        redirect_to(['msg' => 'Job updated successfully', 'type' => 'success']);
    } 
    elseif ($action === 'delete') {
        $stmt = $mysqli->prepare("DELETE FROM jobs WHERE job_id = ?");
        if (!$stmt) die('Error preparing delete: ' . $mysqli->error);
        $stmt->bind_param('i', $job_id);
        if (!$stmt->execute()) {
            die('Deletion failed: ' . $stmt->error);
        }
        $stmt->close();
        redirect_to(['msg' => 'Job deleted', 'type' => 'warning']);
    }
}

// --- Main query to fetch job list ---
$where_clauses = [];
$params = [];
$types = '';

if (!empty($search_client)) {
    $where_clauses[] = "c.client_name LIKE ?";
    $params[] = "%$search_client%";
    $types .= 's';
}
// MANDATORY FILTER: Only show jobs assigned to 'T.G. Bragadeesh'
$where_clauses[] = "j.assigned_to = ?";
$params[] = "R.Shanthi";
$types .= 's';

$where_sql = '';
if ($where_clauses) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

$sql = "SELECT j.job_id, j.job_date, j.journal_issue, j.mail_subject, j.article, 
               j.assigned_to, j.assigned_to_tm, j.notes, j.queries, j.word_count,
               j.assigned_ce, j.assigned_le, j.assigned_pag, j.assigned_qc, j.assigned_xml, 
               j.due_date, j.process_id, c.client_name, p.job_name,
               CASE 
                   WHEN p.job_name = 'CE' THEN 
                       CASE 
                           WHEN j.assigned_ce IS NULL AND j.assigned_le IS NULL THEN 'Pending'
                           WHEN j.assigned_le IS NULL THEN 'In Progress' 
                           WHEN j.assigned_ce IS NOT NULL AND j.assigned_le IS NOT NULL THEN 'Completed'
                           ELSE 'In Progress'
                       END
                   WHEN p.job_name = 'PAG' THEN 
                       CASE 
                           WHEN j.assigned_pag IS NULL AND j.assigned_qc IS NULL THEN 'Pending'
                           WHEN j.assigned_qc IS NULL THEN 'In Progress'
                           WHEN j.assigned_pag IS NOT NULL AND j.assigned_qc IS NOT NULL THEN 'Completed'
                           ELSE 'In Progress'
                       END
                   WHEN p.job_name = 'XML' THEN 
                       CASE 
                           WHEN j.assigned_xml IS NULL THEN 'Pending'
                           WHEN j.assigned_xml IS NOT NULL THEN 'Completed'
                           ELSE 'Pending'
                       END
                   WHEN p.job_name IN ('CE+PAG', 'CE+PAG+XML') THEN 
                       CASE 
                           WHEN j.assigned_ce IS NULL AND j.assigned_le IS NULL AND j.assigned_pag IS NULL AND j.assigned_qc IS NULL THEN 'Pending'
                           WHEN j.assigned_ce IS NOT NULL AND j.assigned_le IS NOT NULL AND j.assigned_pag IS NOT NULL AND j.assigned_qc IS NOT NULL THEN 'Completed'
                           ELSE 'In Progress'
                       END
                   WHEN p.job_name IN ('CE+XML', 'PAG+XML') THEN 
                       CASE 
                           WHEN (j.assigned_ce IS NULL AND j.assigned_le IS NULL AND j.assigned_xml IS NULL) OR 
                                (j.assigned_pag IS NULL AND j.assigned_qc IS NULL AND j.assigned_xml IS NULL) THEN 'Pending'
                           WHEN (j.assigned_ce IS NOT NULL AND j.assigned_le IS NOT NULL AND j.assigned_xml IS NOT NULL) OR 
                                (j.assigned_pag IS NOT NULL AND j.assigned_qc IS NOT NULL AND j.assigned_xml IS NOT NULL) THEN 'Completed'
                           ELSE 'In Progress'
                       END
                   ELSE 'Pending'
               END as status
        FROM jobs j
        JOIN client_master c ON j.client_id = c.client_id
        JOIN job_master p ON j.process_id = p.job_id
        $where_sql
        ORDER BY $sort $dir";

if ($where_sql && $types) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) die('Error preparing jobs query: ' . $mysqli->error);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $jobs_res = $stmt->get_result();
    $stmt->close();
} else {
    $jobs_res = $mysqli->query($sql);
    if (!$jobs_res) die('Error executing jobs query: ' . $mysqli->error);
}

// Check for edit mode
$mode = $_GET['mode'] ?? '';
$edit_id = (int)($_GET['id'] ?? 0);
$edit_job = null;

if ($mode === 'edit' && $edit_id > 0) {
    $stmt = $mysqli->prepare("SELECT * FROM jobs WHERE job_id = ? LIMIT 1");
    if (!$stmt) die('Error preparing edit query: ' . $mysqli->error);
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_job = $result->fetch_assoc();
    $stmt->close();
}
require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jobs Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <style>
        .btn-brown { background-color: brown !important; color: white !important; }
        .compact-form { max-height: 80vh; overflow-y: auto; }
        .text-truncate { max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .table th { font-size: 0.85rem; font-weight: 600; background-color: #f8f9fa; }
        .table td { font-size: 0.85rem; vertical-align: middle; }
        .badge { font-size: 0.75rem; font-weight: 500; }
        .text-truncate:hover { overflow: visible; white-space: normal; background: white; box-shadow: 0 0 10px rgba(0,0,0,0.1); z-index: 1000; position: relative; }
        .article-field-group { margin-bottom: 5px; }
        .article-process-group { border: 1px solid #dee2e6; padding: 10px; border-radius: 5px; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="container-fluid py-3">
        <?php if (!empty($_GET['msg'])): ?>
            <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show">
                <?= h($_GET['msg']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($mode === 'add' || $mode === 'edit'): ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5><?= $mode === 'edit' ? 'Edit' : 'Add' ?> Job</h5>
                <a href="jobs.php" class="btn btn-sm btn-secondary">Back</a>
            </div>

            <div class="card mx-auto" style="max-width:800px;">
                <div class="card-body">
                    <form method="post" autocomplete="off" id="jobForm">
                        <input type="hidden" name="action" value="<?= $mode ?>" />
                        <?php if ($mode === 'edit'): ?>
                            <input type="hidden" name="job_id" value="<?= (int)$edit_job['job_id'] ?>" />
                        <?php endif; ?>

                        <div class="row g-2 mb-2">
                            <div class="col-md-4">
                                <label class="form-label small">Job Date *</label>
                                <input type="date" name="job_date" class="form-control form-control-sm" required value="<?= h($mode === 'edit' ? $edit_job['job_date'] : date('Y-m-d')) ?>" />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">Due Date</label>
                                <input type="date" name="due_date" class="form-control form-control-sm" value="<?= h($mode === 'edit' ? $edit_job['due_date'] : date('Y-m-d', strtotime('+5 days'))) ?>" />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">Client *</label>
                                <select name="client_id" class="form-select form-select-sm" required>
                                    <option value="">Select Client</option>
                                    <?php $clients_res->data_seek(0); while ($client = $clients_res->fetch_assoc()): ?>
                                        <option value="<?= $client['client_id'] ?>" <?= ($mode === 'edit' && $edit_job['client_id'] == $client['client_id']) ? 'selected' : '' ?>><?= h($client['client_name']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label small">Journal / Issue *</label>
                                <input type="text" name="journal_issue" class="form-control form-control-sm" required value="<?= h($mode === 'edit' ? $edit_job['journal_issue'] : '') ?>" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">Mail Subject</label>
                                <input type="text" name="mail_subject" class="form-control form-control-sm" value="<?= h($mode === 'edit' ? ($edit_job['mail_subject'] ?? '') : '') ?>" placeholder="Optional mail subject" />
                            </div>
                        </div>

                        <!-- COMMON PROCESS SELECTION -->
                        <div class="mb-2">
                            <label class="form-label small">Process for All Articles *</label>
                            <select name="process_id" class="form-select form-select-sm" required>
                                <option value="">Select Process</option>
                                <?php foreach ($all_processes as $pid => $pname): ?>
                                    <option value="<?= $pid ?>" <?= ($mode === 'edit' && $edit_job['process_id'] == $pid) ? 'selected' : '' ?>>
                                        <?= h($pname) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Selected process will be applied to ALL articles</small>
                        </div>

                        <!-- ASSIGNED TO TM FIELD -->
                        <div class="mb-2">
                            <label class="form-label small">Assigned to TM *</label>
                            <select name="assigned_to_tm" class="form-select form-select-sm" required>
                                <option value="">Select Team Member</option>
                                <?php foreach ($tms_under_manager as $tm): ?>
                                    <option value="<?= h($tm['employee_username']) ?>" 
                                        <?= ($mode === 'edit' && isset($edit_job['assigned_to_tm']) && $edit_job['assigned_to_tm'] == $tm['employee_username']) ? 'selected' : '' ?>>
                                        <?= h($tm['employee_name']) ?> (<?= h($tm['employee_username']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Team Members under your reporting manager</small>
                        </div>

                        <!-- ARTICLE TEXTAREA -->
                        <div class="mb-2">
                            <label class="form-label small">Articles (one per line) * <small class="text-muted">(Each line will create a separate job record)</small></label>
                            
                            <?php if ($mode === 'edit'): ?>
                                <!-- For edit mode, show single article field -->
                                <input type="text" name="article" class="form-control form-control-sm" required 
                                       value="<?= h($edit_job['article'] ?? '') ?>" />
                            <?php else: ?>
                                <!-- For add mode, show textarea -->
                                <textarea name="articles_textarea" id="articlesTextarea" class="form-control form-control-sm" 
                                          rows="5" placeholder="Enter each article on a new line:
Article 1
Article 2
Article 3
...
" required><?= h($_POST['articles_textarea'] ?? '') ?></textarea>
                                
                                <!-- OR use dynamic input fields -->
                                <div class="mt-2">
                                    <label class="form-check-label small">
                                        <input type="checkbox" id="useIndividualFields" onclick="toggleInputMethod()" />
                                        Use individual input fields instead of textarea?
                                    </label>
                                </div>
                                
                                <div id="individualFieldsContainer" style="display:none;" class="mt-2">
                                    <div class="card p-2">
                                        <div id="articleFields">
                                            <div class="row g-2 mb-2 align-items-end">
                                                <div class="col-10">
                                                    <label class="form-label small">Article 1 *</label>
                                                    <input type="text" name="article_individual[]" class="form-control form-control-sm" placeholder="Enter article name" />
                                                </div>
                                                <div class="col-2">
                                                    <button type="button" class="btn btn-outline-danger btn-sm mt-4" onclick="removeArticleField(this)" style="display:none;">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-outline-primary btn-sm mt-1" onclick="addArticleField()">
                                            <i class="fas fa-plus"></i> Add Another Article
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- ADDITIONAL FIELDS: Notes, Queries, Word Count -->
                        <div class="row g-2 mb-2">
                            <div class="col-md-12">
                                <label class="form-label small">Notes</label>
                                <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="Optional notes"><?= h($mode === 'edit' ? ($edit_job['notes'] ?? '') : '') ?></textarea>
                            </div>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label small">Queries</label>
                                <textarea name="queries" class="form-control form-control-sm" rows="2" placeholder="Any queries related to this job"><?= h($mode === 'edit' ? ($edit_job['queries'] ?? '') : '') ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">Word Count</label>
                                <input type="number" name="word_count" class="form-control form-control-sm" min="0" value="<?= h($mode === 'edit' ? ($edit_job['word_count'] ?? 0) : 0) ?>" placeholder="Enter word count" />
                            </div>
                        </div>

                        <div class="d-flex gap-2 pt-2">
                            <button type="submit" class="btn btn-sm btn-brown"><?= $mode === 'edit' ? 'Update' : 'Save' ?> Job(s)</button>
                            <a href="jobs.php" class="btn btn-sm btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- JavaScript for dynamic form behavior -->
            <script>
                // Toggle between textarea and individual fields
                function toggleInputMethod() {
                    const checkbox = document.getElementById('useIndividualFields');
                    const textarea = document.getElementById('articlesTextarea');
                    const individualContainer = document.getElementById('individualFieldsContainer');
                    
                    if (checkbox && checkbox.checked) {
                        individualContainer.style.display = 'block';
                        if (textarea) {
                            textarea.required = false;
                            textarea.style.display = 'none';
                        }
                    } else {
                        individualContainer.style.display = 'none';
                        if (textarea) {
                            textarea.required = true;
                            textarea.style.display = 'block';
                        }
                    }
                }
                
                // Individual field management
                let articleFieldCounter = 1;
                
                function addArticleField() {
                    articleFieldCounter++;
                    const container = document.getElementById('articleFields');
                    const newField = document.createElement('div');
                    newField.className = 'row g-2 mb-2 align-items-end';
                    newField.innerHTML = `
                        <div class="col-10">
                            <label class="form-label small">Article ${articleFieldCounter} *</label>
                            <input type="text" name="article_individual[]" class="form-control form-control-sm" placeholder="Enter article name" />
                        </div>
                        <div class="col-2">
                            <button type="button" class="btn btn-outline-danger btn-sm mt-4" onclick="removeArticleField(this)">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    `;
                    container.appendChild(newField);
                    
                    updateRemoveButtons();
                }
                
                function removeArticleField(button) {
                    const fieldRow = button.closest('.row');
                    if (fieldRow) {
                        fieldRow.remove();
                        articleFieldCounter--;
                        renumberFields();
                    }
                }
                
                function renumberFields() {
                    const fields = document.querySelectorAll('#articleFields .row');
                    fields.forEach((field, index) => {
                        const label = field.querySelector('.form-label');
                        const input = field.querySelector('input');
                        const fieldNumber = index + 1;
                        
                        if (label) label.textContent = `Article ${fieldNumber} *`;
                        if (input) input.placeholder = `Article ${fieldNumber}`;
                    });
                    articleFieldCounter = fields.length;
                    updateRemoveButtons();
                }
                
                function updateRemoveButtons() {
                    const fields = document.querySelectorAll('#articleFields .row');
                    const removeButtons = document.querySelectorAll('#articleFields .btn-outline-danger');
                    
                    if (fields.length > 1) {
                        removeButtons.forEach(btn => btn.style.display = 'block');
                    } else {
                        removeButtons.forEach(btn => btn.style.display = 'none');
                    }
                }
                
                // Form validation
                document.getElementById('jobForm').addEventListener('submit', function(e) {
                    const useIndividualFields = document.getElementById('useIndividualFields')?.checked;
                    const processSelect = document.querySelector('select[name="process_id"]');
                    const assignedTMSelect = document.querySelector('select[name="assigned_to_tm"]');
                    
                    // Validate process
                    if (!processSelect || !processSelect.value) {
                        alert('Please select a process.');
                        e.preventDefault();
                        return false;
                    }
                    
                    // Validate assigned TM
                    if (!assignedTMSelect || !assignedTMSelect.value) {
                        alert('Please select a Team Member to assign.');
                        e.preventDefault();
                        return false;
                    }
                    
                    if (useIndividualFields) {
                        // Validate individual fields
                        const articleInputs = document.querySelectorAll('input[name="article_individual[]"]');
                        let hasArticles = false;
                        
                        articleInputs.forEach((input) => {
                            if (input.value.trim() !== '') {
                                hasArticles = true;
                            }
                        });
                        
                        if (!hasArticles) {
                            alert('Please enter at least one article.');
                            e.preventDefault();
                            return false;
                        }
                    } else {
                        // Validate textarea
                        const textarea = document.getElementById('articlesTextarea');
                        if (textarea && textarea.value.trim() === '') {
                            alert('Please enter at least one article in the textarea.');
                            e.preventDefault();
                            return false;
                        }
                    }
                    
                    return true;
                });
                
                // Initialize on page load
                document.addEventListener('DOMContentLoaded', function() {
                    updateRemoveButtons();
                });
            </script>

        <!-- Main job list display -->
        <?php else: ?>
           <div class="d-flex align-items-center mb-2">
                <h5 class="mb-0 flex-grow-1">Jobs</h5>
                <div class="d-flex gap-2">
                    <?php if (in_array('CE', $current_user_stages)): ?>
                        <a href="assigned_jobs.php?process=ce" class="btn btn-sm btn-primary <?= (in_array('CE', $current_user_stages))? '' : 'btn-disabled' ?>">
                             CE Jobs
                        </a>
                        <a href="unassigned_jobs.php?process=ce" class="btn btn-sm btn-warning <?= (in_array('CE', $current_user_stages)) ? '' : 'btn-disabled' ?>">
                             CE Unassigned
                        </a>
                    <?php endif; ?>
                    <?php if (in_array('LE', $current_user_stages)): ?>
                        <a href="assigned_jobs_le.php?process=le" class="btn btn-sm btn-primary <?= (in_array('LE', $current_user_stages)) ? '' : 'btn-disabled' ?>">
                             LE Jobs
                        </a>
                        <a href="unassigned_jobs_le.php?process=le" class="btn btn-sm btn-warning <?= (in_array('LE', $current_user_stages)) ? '' : 'btn-disabled' ?>">
                             LE Unassigned
                        </a>
                    <?php endif; ?>
                    
                    <?php if (in_array('PAG', $current_user_stages)):?>
                        <a href="assigned_jobs_pag.php?process=pag" class="btn btn-sm btn-primary <?= (in_array('PAG', $current_user_stages)) ? '' : 'btn-disabled' ?>">
                             PAG Jobs
                        </a>
                        <a href="unassigned_jobs_pag.php?process=pag" class="btn btn-sm btn-warning <?= (in_array('PAG', $current_user_stages)) ? '' : 'btn-disabled' ?>">
                             PAG Unassigned
                        </a>
                    <?php endif; ?>
                    <?php if (in_array('QC', $current_user_stages)): ?>
                        <a href="qc.php?process=qc" class="btn btn-sm btn-primary <?= (in_array('QC', $current_user_stages)) ? '' : 'btn-disabled' ?>">
                             QC Jobs
                        </a>
                        <!--a href="unassigned_jobs_pag.php?process=qc" class="btn btn-sm btn-warning <?= (in_array('QC', $current_user_stages)) ? '' : 'btn-disabled' ?>">
                             PAG Unassigned
                        </a-->
                    <?php endif; ?>
                    
                    <?php if (in_array('XML', $current_user_stages)): ?>
                        <!--a href="assigned_jobs.php?process=xml" class="btn btn-sm btn-primary <?= in_array('XML', $current_user_stages) ? '' : 'btn-disabled' ?>">
                             XML Jobs
                        </a-->
                        <a href="unassigned_xml_jobs.php?process=xml" class="btn btn-sm btn-warning <?= in_array('XML', $current_user_stages) ? '' : 'btn-disabled' ?>">
                             XML Unassigned
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($role_name === 'TM'): ?>
                        <!-- For TM users, show all assigned jobs button -->
                        <!--a href="assigned_jobs.php" class="btn btn-sm btn-info">
                            My Assigned Jobs
                        </a-->
                    <?php endif; ?>
                    
                    <?php if ($role_name === 'TL' || $role_name === 'Manager'): ?>
                        <a href="jobs.php?mode=add" class="btn btn-sm btn-success">
                            <i class="fa fa-plus"></i> Add Job(s)
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Filter form -->
            <form method="get" class="row gy-2 gx-2 mb-3 align-items-end">
                <div class="col-md-4">
                    <input type="text" name="search_client" value="<?= h($search_client) ?>" placeholder="Search by client" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <select name="sort" class="form-select form-select-sm">
                        <?php foreach ($allowed_sorts as $s): ?>
                            <option value="<?= $s ?>" <?= $sort === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="dir" class="form-select form-select-sm">
                        <option value="ASC" <?= $dir === 'ASC' ? 'selected' : '' ?>>Asc</option>
                        <option value="DESC" <?= $dir === 'DESC' ? 'selected' : '' ?>>Desc</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="jobs.php" class="btn btn-secondary btn-sm">Clear</a>
                </div>
            </form>

            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Date</th>
                                    <th>Client</th>
                                    <th>Journal/Issue</th>
                                    <th>Article</th>
                                    <th>Mail Subject</th>
                                    <th>Process</th>
                                    <th>Assigned TM</th>
                                    <th>Job Assign</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                    <th>Queries</th>
                                    <th>Word Count</th>
                                    <th>Due Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($jobs_res && $jobs_res->num_rows):
                                    $i=0;
                                    while ($job = $jobs_res->fetch_assoc()): 
                                    $i=$i+1;
                                ?>
                                    <tr>
                                        <td><?= $i; ?></td>
                                        <td><?= formatDate($job['job_date']) ?></td>
                                        <td><?= h($job['client_name']) ?></td>
                                        <td><?= h($job['journal_issue']) ?></td>
                                        <td><?= h($job['article']) ?></td>
                                        <td><?= h($job['mail_subject']) ?></td>
                                        <td><?= h($job['job_name']) ?></td>
                                        
                                        <!-- Assigned TM Column -->
                                        <td>
                                            <?php if (!empty($job['assigned_to_tm'])): ?>
                                                <span class="badge bg-info text-white">
                                                    <?= h($job['assigned_to_tm']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Not Assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td>
                                            <?php
                                            $process_name = $job['job_name'];
                                            $norm_proc = normalize_process($process_name);
                                            $stages = $processStageMap[$norm_proc] ?? [];

                                            $bg_colors = [
                                                'CE'  => 'bg-primary text-white',
                                                'LE'  => 'bg-info text-dark',
                                                'PAG' => 'bg-success text-white',
                                                'QC'  => 'bg-warning text-dark',
                                                'XML' => 'bg-danger text-white'
                                            ];

                                            foreach ($stages as $stage):
                                                $stage_field = 'assigned_' . strtolower($stage);
                                                $stage_assigned = $job[$stage_field] ?? null;

                                                if ($stage_assigned) {
                                                    $display_text = $stage . '-' . h($stage_assigned); 
                                                    $bg_class = $bg_colors[$stage] ?? 'bg-light text-dark';
                                                } else {
                                                    $display_text = $stage . '-Awaiting'; 
                                                    $bg_class = 'bg-secondary text-white';
                                                }
                                            ?>
                                                <span class="badge <?= $bg_class ?> d-block mb-1" style="padding:4px 8px; font-size:0.8rem;">
                                                    <?= $display_text ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </td>
                                        
                                        <td>
                                            <?php
                                            $status = $job['status'] ?? 'Pending';
                                            $status_colors = [
                                                'Pending' => 'bg-warning text-dark',
                                                'In Progress' => 'bg-info text-white', 
                                                'Completed' => 'bg-success text-white'
                                            ];
                                            $badge_class = $status_colors[$status] ?? 'bg-secondary text-white';
                                            ?>
                                            <span class="badge <?= $badge_class ?>" style="padding:6px 10px; font-size:0.85rem;">
                                                <?= h($status) ?>
                                            </span>
                                        </td>
                                        
                                        <!-- Notes Column -->
                                        <td>
                                            <?php if (!empty($job['notes'])): ?>
                                                <span class="d-inline-block text-truncate" style="max-width: 150px;" title="<?= h($job['notes']) ?>">
                                                    <?= h(substr($job['notes'], 0, 50)) . (strlen($job['notes']) > 50 ? '...' : '') ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <!-- Queries Column -->
                                        <td>
                                            <?php if (!empty($job['queries'])): ?>
                                                <span class="d-inline-block text-truncate" style="max-width: 150px;" title="<?= h($job['queries']) ?>">
                                                    <?= h(substr($job['queries'], 0, 50)) . (strlen($job['queries']) > 50 ? '...' : '') ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <!-- Word Count Column -->
                                        <td>
                                            <?php if (!empty($job['word_count']) && $job['word_count'] > 0): ?>
                                                <span class="badge bg-primary text-white">
                                                    <?= number_format($job['word_count']) ?> words
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td><?= formatDate($job['due_date']) ?></td>
                                        
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="jobs.php?mode=edit&id=<?= (int)$job['job_id'] ?>" class="btn btn-warning" title="Edit"><i class="fa fa-edit"></i></a>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this job?');">
                                                    <input type="hidden" name="job_id" value="<?= (int)$job['job_id'] ?>" />
                                                    <button type="submit" class="btn btn-danger" title="Delete"><i class="fa fa-trash"></i></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <tr>
                                        <td colspan="15" class="text-center py-3">
                                            No jobs found.
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
</body>
</html>