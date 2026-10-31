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

// Get ID from URL for mail subject
$mail_subject="";
$mail_id = (int)($_GET['mail_id'] ?? 0);
$editMailSubject = null;
if ($mail_id > 0) {
    $stmt = $mysqli->prepare("SELECT * FROM mail_subject_master WHERE mail_id = ?");
    $stmt->bind_param("i", $mail_id);
    $stmt->execute();
    $editMailSubject = $stmt->get_result()->fetch_assoc();
    $mail_subject=$editMailSubject['mail_subject'];
    $stmt->close();
}

$logged_user = $_SESSION['username'] ?? '';
$role_name = $_SESSION['role_name'] ?? '';

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
$clients_res = $mysqli->query("SELECT client_id, client_name, team_client_name FROM client_master WHERE status='Active' ORDER BY client_name");
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

// --- Handle POST requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $job_id = (int)($_POST['job_id'] ?? 0);
    $job_date = $_POST['job_date'] ?: date('Y-m-d');
    $client_id = (int)($_POST['client_id'] ?? 0);
    $journal = trim($_POST['journal_issue'] ?? '');
    $mail_subject = trim($_POST['mail_subject'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $queries = trim($_POST['queries'] ?? '');
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
        
        // Get articles and word counts from separate textareas
        $articles_data = [];
        
        if (isset($_POST['articles_textarea']) && !empty(trim($_POST['articles_textarea']))) {
            $raw_articles = trim($_POST['articles_textarea']);
            $articles = array_filter(array_map('trim', explode("\n", $raw_articles)), 'strlen');
            
            // Get word counts if provided
            $word_counts = [];
            if (isset($_POST['wordcount_textarea']) && !empty(trim($_POST['wordcount_textarea']))) {
                $raw_wordcounts = trim($_POST['wordcount_textarea']);
                $word_counts = array_filter(array_map('trim', explode("\n", $raw_wordcounts)), 'strlen');
            }
            
            // Combine articles with their word counts
            foreach ($articles as $index => $article) {
                $word_count = isset($word_counts[$index]) ? (int)$word_counts[$index] : 0;
                $articles_data[] = [
                    'article' => $article,
                    'word_count' => $word_count
                ];
            }
        } elseif (isset($_POST['article_individual']) && is_array($_POST['article_individual'])) {
            // Method 2: From individual fields with per-article word counts
            $article_individuals = array_map('trim', $_POST['article_individual']);
            $article_word_counts = isset($_POST['article_word_count']) ? $_POST['article_word_count'] : [];
            
            foreach ($article_individuals as $index => $article) {
                if (!empty($article)) {
                    $articles_data[] = [
                        'article' => $article,
                        'word_count' => isset($article_word_counts[$index]) ? (int)$article_word_counts[$index] : 0
                    ];
                }
            }
        } else {
            redirect_to(['msg' => 'Please enter at least one article', 'type' => 'danger']);
        }
        
        if (empty($articles_data)) {
            redirect_to(['msg' => 'No valid articles provided', 'type' => 'danger']);
        }
        
        // Get assigned_to from session (this remains for workflow)
        $uid = $_SESSION['uid'] ?? 0;
        $assigned_to = '';
        
        $sql = "SELECT employee_name, reporting_manager FROM employee_master WHERE employee_id = ?";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) die("Error preparing: " . $mysqli->error);
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($row = $res->fetch_assoc()) {
            $assigned_to = !empty($row['reporting_manager']) ? $row['reporting_manager'] : $row['employee_name']; // Assign to current user / TL
        }
        $stmt->close();
        
        // Process each article (with SAME process for all)
        $success_count = 0;
        $errors = [];
        
        foreach ($articles_data as $article_data) {
            $article = $article_data['article'];
            $word_count = $article_data['word_count'];
            
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
                    assigned_to, assigned_ce, assigned_le, assigned_pag,
                    assigned_qc, assigned_xml, due_date, due_date_ce,
                    due_date_le, due_date_pag, due_date_qc,
                    notes, queries, word_count
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            $assigned_to_tm = ''; // Empty as removed

            $stmt->bind_param(
                'sisssssssssssssssssi',
                $job_date,
                $client_id,
                $journal,
                $mail_subject,
                $article,
                $process_id,
                $assigned_to,
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

                    // --- AUTO-CREATE QC_CRX TABLE IF NOT EXISTS ---
$mysqli->query("CREATE TABLE IF NOT EXISTS `qc_crx` (
  `id` int NOT NULL AUTO_INCREMENT,
  `job_id` int DEFAULT NULL,
  `date` date DEFAULT NULL,
  `client` varchar(255) DEFAULT NULL,
  `journal_issue` varchar(255) DEFAULT NULL,
  `article` varchar(255) DEFAULT NULL,
  `process` varchar(100) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Need to Assign',
  `assigned_to_qc` varchar(50) DEFAULT NULL,
  `qc_start_time` time DEFAULT NULL,
  `qc_end_time` time DEFAULT NULL,
  `qc_date` datetime DEFAULT NULL,
  `rtat` int DEFAULT 0,
  `atat` int DEFAULT 0,
  `path` varchar(500) DEFAULT NULL,
  `priority` varchar(50) DEFAULT NULL,
  `dispatch_date` date DEFAULT NULL,
  `upload_by` varchar(50) DEFAULT NULL,
  `done_by` varchar(50) DEFAULT NULL,
  `qc_crx_stage` varchar(50) DEFAULT NULL,
  `pag_crx_stage` varchar(50) DEFAULT NULL,
  `pag_crx_assigned_to` varchar(50) DEFAULT NULL,
  `pag_crx_start_time` time DEFAULT NULL,
  `pag_crx_end_time` time DEFAULT NULL,
  `img_tab_stage` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_job_id` (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    // QC_CRX
                    $stmt_crx = $mysqli->prepare("
                        INSERT INTO qc_crx 
                        (job_id, date, client, journal_issue, article, process, status, created_at, last_updated)
                        VALUES (?, ?, ?, ?, ?, ?, 'Need to Assign', NOW(), NOW())
                    ");
                    if ($stmt_crx) {
                        $stmt_crx->bind_param("isssss", $new_job_id, $job_date, $client_name, $journal, $article, $process_name);
                        $stmt_crx->execute();
                        $stmt_crx->close();
                    }
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
        $word_count = (int)($_POST['word_count'] ?? 0);
        
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
        $stmt = $mysqli->prepare("UPDATE jobs SET job_date=?, client_id=?, journal_issue=?, mail_subject=?, article=?, process_id=?, due_date=?, notes=?, queries=?, word_count=? WHERE job_id=?");
        if (!$stmt) die('Error preparing update: ' . $mysqli->error);
        $stmt->bind_param('sisssssssii', $job_date, $client_id, $journal, $mail_subject, $article, $process_id, $due_date, $notes, $queries, $word_count, $job_id);
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
// --- Main query to fetch job list ---
$where_clauses = [];
$params = [];
$types = '';

// ADDED FIX: Hide XML Team jobs from this view
$where_clauses[] = "p.job_name != 'XML'";

if (!empty($search_client)) {
    $where_clauses[] = "(c.client_name LIKE ? OR c.team_client_name LIKE ?)";
    $params[] = "%$search_client%";
    $params[] = "%$search_client%";
    $types .= 'ss';
}

$where_sql = '';
if ($where_clauses) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

$sql = "SELECT j.job_id, j.job_date, j.journal_issue, j.mail_subject, j.article, 
               j.assigned_to, j.notes, j.queries, j.word_count,
               j.assigned_ce, j.assigned_le, j.assigned_pag, j.assigned_qc, j.assigned_xml, 
               j.due_date, j.process_id, c.client_name, c.team_client_name, p.job_name,
               ce.status AS ce_status, le.status AS le_status, 
               pag.status AS pag_status, qc.status AS qc_status,
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
        LEFT JOIN ce ON j.job_id = ce.job_id
        LEFT JOIN le ON j.job_id = le.job_id
        LEFT JOIN pag ON j.job_id = pag.job_id
        LEFT JOIN qc ON j.job_id = qc.job_id
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
    <title>Manage Jobs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        body {
            background-color: #f8fafc;
        }
        
        /* Header Styles */
        .page-header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.75rem 0;
            margin-bottom: 1rem;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }
        
        /* Form Card Styles (for Add/Edit mode) */
        .form-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
            overflow: hidden;
            margin-bottom: 1rem;
        }
        
        .form-card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 0.75rem 1.25rem;
            color: white;
        }
        
        .form-card-header h3 {
            margin: 0;
            font-weight: 600;
            font-size: 1rem;
            letter-spacing: 0.025em;
        }
        
        .form-card-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.75rem;
        }
        
        .form-card-body {
            padding: 1rem;
        }
        
        .form-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
            border: 1px solid #e2e8f0;
        }
        
        .form-section-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .form-section-title i {
            color: #667eea;
            font-size: 0.9rem;
        }
        
        .form-label {
            font-weight: 500;
            color: #334155;
            margin-bottom: 0.125rem;
            font-size: 0.75rem;
        }
        
        .form-control, .form-select {
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.3rem 0.5rem;
            font-size: 0.85rem;
            transition: all 0.15s ease;
            background-color: white;
            height: auto;
            min-height: 32px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
            outline: none;
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 80px;
            font-size: 0.8rem;
        }
        
        .input-group-text {
            background-color: #f1f5f9;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            color: #475569;
            font-weight: 500;
            padding: 0.3rem 0.5rem;
            font-size: 0.8rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 8px;
            padding: 0.4rem 1rem;
            font-weight: 500;
            font-size: 0.85rem;
            letter-spacing: 0.025em;
            box-shadow: 0 2px 4px rgba(102, 126, 234, 0.3);
            transition: all 0.15s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 6px rgba(102, 126, 234, 0.4);
        }
        
        .btn-outline-secondary {
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.4rem 1rem;
            font-weight: 500;
            font-size: 0.85rem;
            color: #475569;
            background: white;
        }
        
        .btn-outline-secondary:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: #1e293b;
        }
        
        .btn-outline-primary {
            border: 1.5px dashed #667eea;
            color: #667eea;
            border-radius: 8px;
            padding: 0.3rem 0.75rem;
            font-weight: 500;
            font-size: 0.8rem;
            background: white;
        }
        
        .btn-outline-primary:hover {
            background: #f0f3ff;
            border-color: #667eea;
        }
        
        .btn-outline-danger {
            border: 1.5px solid #fecaca;
            color: #dc2626;
            border-radius: 8px;
            padding: 0.3rem 0.75rem;
            font-size: 0.8rem;
            background: white;
        }
        
        .btn-outline-danger:hover {
            background: #fee2e2;
            border-color: #f87171;
        }
        
        .method-toggle {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            margin-bottom: 0.75rem;
        }
        
        .method-toggle .form-check {
            margin-right: 1.5rem;
        }
        
        .method-toggle .form-check-input {
            border: 2px solid #cbd5e1;
            cursor: pointer;
            margin-top: 2px;
        }
        
        .method-toggle .form-check-input:checked {
            background-color: #667eea;
            border-color: #667eea;
        }
        
        .method-toggle .form-check-label {
            font-weight: 500;
            color: #334155;
            cursor: pointer;
            font-size: 0.8rem;
        }
        
        .info-box {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 0.4rem 0.75rem;
            font-size: 0.75rem;
            color: #0369a1;
            margin-top: 0.5rem;
        }
        
        .info-box i {
            margin-right: 0.25rem;
            color: #0284c7;
            font-size: 0.75rem;
        }
        
        .required-asterisk {
            color: #dc2626;
            margin-left: 2px;
        }
        
        .badge-soft {
            background: #f1f5f9;
            color: #475569;
            font-weight: 500;
            padding: 0.125rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.7rem;
        }
        
        .article-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .dual-textarea-container {
            display: flex;
            gap: 0.75rem;
        }
        
        .textarea-wrapper {
            flex: 1;
            background: white;
            border-radius: 8px;
            padding: 0.5rem;
            border: 1px solid #e2e8f0;
        }
        
        .textarea-wrapper h6 {
            font-size: 0.75rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .textarea-wrapper h6 i {
            color: #667eea;
            font-size: 0.8rem;
        }
        
        .textarea-wrapper textarea {
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 0.4rem;
            font-size: 0.8rem;
            line-height: 1.4;
            background: #f8fafc;
            min-height: 80px;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid #e2e8f0;
        }
        
        /* Grid/Table Styles */
        .grid-header {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        
        .table {
            margin-bottom: 0;
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
            white-space: nowrap;
        }
        
        .table tbody td {
            padding: 0.75rem 0.5rem;
            font-size: 0.8rem;
            color: #334155;
            vertical-align: middle;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .table tbody tr:hover {
            background-color: #f8fafc;
        }
        
        .badge {
            font-weight: 500;
            padding: 0.35rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.7rem;
        }
        
        .badge.bg-warning {
            background-color: #fbbf24 !important;
            color: #1e293b;
        }
        
        .badge.bg-info {
            background-color: #38bdf8 !important;
            color: white;
        }
        
        .badge.bg-success {
            background-color: #34d399 !important;
            color: white;
        }
        
        .badge.bg-secondary {
            background-color: #94a3b8 !important;
            color: white;
        }
        
        .badge.bg-primary {
            background-color: #667eea !important;
            color: white;
        }
        
        .badge.bg-danger {
            background-color: #f87171 !important;
            color: white;
        }
        
        .stage-badge {
            display: inline-block;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 500;
            margin-right: 0.25rem;
            margin-bottom: 0.25rem;
            white-space: nowrap;
        }
        
        .btn-group-sm .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
            border-radius: 6px;
        }
        
        .filter-form {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #e2e8f0;
        }
        
        .text-truncate {
            max-width: 150px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .text-truncate:hover {
            overflow: visible;
            white-space: normal;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            z-index: 1000;
            position: relative;
        }
        
        .row {
            margin: 0 -0.375rem;
        }
        
        .col-md-1, .col-md-2, .col-md-3, .col-md-4, .col-md-5, .col-md-6, .col-md-7, .col-md-8, .col-md-9, .col-md-10, .col-md-11, .col-md-12 {
            padding: 0 0.375rem;
        }
        
        .mb-2 {
            margin-bottom: 0.5rem !important;
        }
        
        .mt-2 {
            margin-top: 0.5rem !important;
        }
        
        .g-3 {
            gap: 0.5rem !important;
        }
        
        small.text-muted {
            font-size: 0.7rem;
            display: block;
            margin-top: 0.125rem;
        }
        
        .h5, h5 {
            font-size: 1rem;
        }
        
        .container-fluid {
            padding-left: 1rem;
            padding-right: 1rem;
        }
    </style>
</head>
<body>
    <div class="page-header">
        <div class="container-fluid px-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0" style="font-weight: 700; color: #1e293b;">
                        <i class="fas fa-briefcase me-1" style="color: #667eea;"></i>
                        Manage Jobs
                    </h5>
                    <small class="text-muted">Manage and track all jobs</small>
                </div>
                <?php if ($mode !== 'add' && $mode !== 'edit'): ?>
                    <a href="jobs.php?mode=add" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus me-1"></i> Add Job(s)
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="container-fluid px-3">
        <?php if (!empty($_GET['msg'])): ?>
            <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show py-2">
                <i class="fas <?= ($_GET['type'] ?? 'info') === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> me-1"></i>
                <?= h($_GET['msg']) ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert" style="font-size: 0.7rem;"></button>
            </div>
        <?php endif; ?>

        <?php if ($mode === 'add' || $mode === 'edit'): ?>
            <!-- ADD/EDIT FORM -->
            <div class="form-card">
                <div class="form-card-header">
                    <h3><i class="fas <?= $mode === 'edit' ? 'fa-edit' : 'fa-plus-circle' ?> me-1"></i> <?= $mode === 'edit' ? 'Edit Job' : 'Create New Job' ?></h3>
                    <p><?= $mode === 'edit' ? 'Update job details' : 'Enter job details below' ?></p>
                </div>
                
                <div class="form-card-body">
                    <form method="post" autocomplete="off" id="jobForm">
                        <input type="hidden" name="action" value="<?= $mode ?>" />
                        <?php if ($mode === 'edit'): ?>
                            <input type="hidden" name="job_id" value="<?= (int)$edit_job['job_id'] ?>" />
                        <?php endif; ?>

                        <!-- Basic Information Section -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-info-circle"></i>
                                <span>Basic Information</span>
                            </div>
                            
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label">
                                        Job Date <span class="required-asterisk">*</span>
                                    </label>
                                    <input type="date" name="job_date" class="form-control" required 
                                           value="<?= h($mode === 'edit' ? $edit_job['job_date'] : date('Y-m-d')) ?>">
                                </div>
                                
                                <div class="col-md-4">
                                    <label class="form-label">Due Date</label>
                                    <input type="date" name="due_date" class="form-control" 
                                           value="<?= h($mode === 'edit' ? $edit_job['due_date'] : date('Y-m-d', strtotime('+5 days'))) ?>">
                                </div>
                                
                                <div class="col-md-4">
                                    <label class="form-label">
                                        Client <span class="required-asterisk">*</span>
                                    </label>
                                    <select name="client_id" class="form-select" required>
                                        <option value="">Select</option>
                                        <?php 
                                        $clients_res->data_seek(0); 
                                        $displayed_clients = [];
                                        while ($client = $clients_res->fetch_assoc()): 
                                            $display_name = !empty($client['team_client_name']) ? $client['team_client_name'] : $client['client_name'];
                                            
                                            
                                            if (($mode === 'edit' && $edit_job['client_id'] == $client['client_id']) || !in_array($display_name, $displayed_clients)):
                                                $displayed_clients[] = $display_name;
                                        ?>
                                            <option value="<?= $client['client_id'] ?>" 
                                                <?= ($mode === 'edit' && $edit_job['client_id'] == $client['client_id']) ? 'selected' : '' ?>>
                                                <?= h($display_name) ?>
                                            </option>
                                        <?php 
                                            endif;
                                        endwhile; 
                                        ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Journal & Process Section -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-newspaper"></i>
                                <span>Journal & Process</span>
                            </div>
                            
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label">
                                        Journal / Issue <span class="required-asterisk">*</span>
                                    </label>
                                    <input type="text" name="journal_issue" class="form-control" required 
                                           value="<?= h($mode === 'edit' ? $edit_job['journal_issue'] : '') ?>" 
                                           placeholder="e.g., Nature V25">
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Mail Subject</label>
                                    <input type="text" name="mail_subject" class="form-control" 
                                           value="<?= h($mode === 'edit' ? $edit_job['mail_subject'] : $mail_subject); ?>" 
                                           placeholder="Optional">
                                </div>
                            </div>
                            
                            <div class="row mt-2">
                                <div class="col-12">
                                    <label class="form-label">
                                        Process <span class="required-asterisk">*</span>
                                    </label>
                                    <select name="process_id" class="form-select" required>
                                        <option value="">Select Process</option>
                                        <?php foreach ($all_processes as $pid => $pname): ?>
                                            <option value="<?= $pid ?>" 
                                                <?= ($mode === 'edit' && $edit_job['process_id'] == $pid) ? 'selected' : '' ?>>
                                                <?= h($pname) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Articles Section -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-file-alt"></i>
                                <span>Articles & Word Counts</span>
                                <?php if ($mode !== 'edit'): ?>
                                    <span class="badge-soft ms-2">Multiple</span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($mode === 'edit'): ?>
                                <!-- Edit Mode - Single Article -->
                                <div class="row g-2">
                                    <div class="col-md-8">
                                        <label class="form-label">Article Title <span class="required-asterisk">*</span></label>
                                        <input type="text" name="article" class="form-control" required 
                                               value="<?= h($edit_job['article'] ?? '') ?>" 
                                               placeholder="Article title">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Word Count</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-calculator"></i></span>
                                            <input type="number" name="word_count" class="form-control" 
                                                   value="<?= h($edit_job['word_count'] ?? 0) ?>" min="0" placeholder="0">
                                        </div>
                                    </div>
                                </div>
                                
                            <?php else: ?>
                                <!-- Add Mode - Multiple Articles -->
                                <div class="method-toggle">
                                    <div class="d-flex align-items-center">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="input_method" 
                                                   id="methodTextarea" value="textarea" checked onclick="toggleInputMethod()">
                                            <label class="form-check-label" for="methodTextarea">
                                                <i class="fas fa-pencil-alt me-1"></i> Textarea
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="input_method" 
                                                   id="methodIndividual" value="individual" onclick="toggleInputMethod()">
                                            <label class="form-check-label" for="methodIndividual">
                                                <i class="fas fa-list me-1"></i> Individual
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Textarea Method (Default) -->
                                <div id="textareaMethod">
                                    <div class="dual-textarea-container">
                                        <div class="textarea-wrapper">
                                            <h6>
                                                <i class="fas fa-file-lines"></i>
                                                Articles <span class="required-asterisk">*</span>
                                            </h6>
                                            <textarea name="articles_textarea" class="form-control" 
                                                      rows="3" placeholder="Article 1&#10;Article 2&#10;Article 3"><?= h($_POST['articles_textarea'] ?? '') ?></textarea>
                                        </div>
                                        
                                        <div class="textarea-wrapper">
                                            <h6>
                                                <i class="fas fa-calculator"></i>
                                                Word Counts
                                            </h6>
                                            <textarea name="wordcount_textarea" class="form-control" 
                                                      rows="3" placeholder="1500&#10;2500&#10;3200"><?= h($_POST['wordcount_textarea'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                    
                                    <div class="info-box">
                                        <i class="fas fa-info-circle"></i>
                                        Line 1 → Article 1, Line 2 → Article 2, etc. Default word count: 0
                                    </div>
                                </div>
                                
                                <!-- Individual Fields Method (Hidden by Default) -->
                                <div id="individualMethod" style="display:none;">
                                    <div id="articleFields">
                                        <div class="article-card">
                                            <div class="row g-2 align-items-center">
                                                <div class="col-md-7">
                                                    <input type="text" name="article_individual[]" class="form-control form-control-sm" 
                                                           placeholder="Article title">
                                                </div>
                                                <div class="col-md-4">
                                                    <input type="number" name="article_word_count[]" class="form-control form-control-sm" 
                                                           min="0" value="0" placeholder="WC">
                                                </div>
                                                <div class="col-md-1">
                                                    <button type="button" class="btn btn-outline-danger btn-sm w-100" 
                                                            onclick="removeArticleField(this)" style="display:none;">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <button type="button" class="btn btn-outline-primary btn-sm mt-1" onclick="addArticleField()">
                                        <i class="fas fa-plus me-1"></i> Add Article
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Additional Information Section -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-paperclip"></i>
                                <span>Additional Info</span>
                            </div>
                            
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label">Notes</label>
                                    <textarea name="notes" class="form-control" rows="2" 
                                              placeholder="Optional notes..."><?= h($mode === 'edit' ? ($edit_job['notes'] ?? '') : '') ?></textarea>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Queries</label>
                                    <textarea name="queries" class="form-control" rows="2" 
                                              placeholder="Any queries..."><?= h($mode === 'edit' ? ($edit_job['queries'] ?? '') : '') ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas <?= $mode === 'edit' ? 'fa-save' : 'fa-plus-circle' ?> me-1"></i>
                                <?= $mode === 'edit' ? 'Update' : 'Create' ?>
                            </button>
                            <a href="jobs.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-times me-1"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>

        <?php else: ?>
            <!-- GRID VIEW - Jobs List -->
            
            <!-- Filter Form -->
            <div class="filter-form">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-4">
    <label class="form-label">Search Client</label>
    <select name="search_client" class="form-select form-select-sm">
        <option value="">All Clients...</option>
        <?php 
        $clients_res->data_seek(0); 
        $filter_displayed = [];
        while ($client_row = $clients_res->fetch_assoc()): 
            $display_name = !empty($client_row['team_client_name']) ? $client_row['team_client_name'] : $client_row['client_name'];
            
            if (!in_array($display_name, $filter_displayed)):
                $filter_displayed[] = $display_name;
        ?>
            <option value="<?= h($display_name) ?>" <?= ($search_client === $display_name) ? 'selected' : '' ?>>
                <?= h($display_name) ?>
            </option>
        <?php 
            endif;
        endwhile; 
        ?>
    </select>
</div>
                    <div class="col-md-2">
                        <label class="form-label">Sort By</label>
                        <select name="sort" class="form-select form-select-sm">
                            <?php foreach ($allowed_sorts as $s): ?>
                                <option value="<?= $s ?>" <?= $sort === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- <div class="col-md-2">
                        <label class="form-label">Direction</label>
                        <select name="dir" class="form-select form-select-sm">
                            <option value="ASC" <?= $dir === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                            <option value="DESC" <?= $dir === 'DESC' ? 'selected' : '' ?>>Descending</option>
                        </select>
                    </div> -->
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary btn-sm me-1">
                            <i class="fas fa-filter me-1"></i> Apply Filters
                        </button>
                        <a href="jobs.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Jobs Table -->
            <div class="table-container">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>S.No</th>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Journal/Issue</th>
                                <th>Article</th>
                                <th>Process</th>
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
                                    <td><?= h($job['team_client_name'] ?: $job['client_name']) ?></td>
                                    <td><?= h($job['journal_issue']) ?></td>
                                    <td><?= h($job['article']) ?></td>
                                    <td><?= h($job['job_name']) ?></td>
                                    
                                    <td>
                                        <?php
                                        $process_name = $job['job_name'];
                                        $norm_proc = normalize_process($process_name);
                                        $stages = $processStageMap[$norm_proc] ?? [];

                                        $bg_colors = [
                                            'CE'  => 'bg-primary',
                                            'LE'  => 'bg-info',
                                            'PAG' => 'bg-success',
                                            'QC'  => 'bg-warning',
                                            'XML' => 'bg-danger'
                                        ];

                                        foreach ($stages as $stage):
    $stage_field = 'assigned_' . strtolower($stage);
    $stage_assigned = $job[$stage_field] ?? null;
    
    // Grab the individual status we joined in the SQL query
    $stage_status_field = strtolower($stage) . '_status';
    $indiv_status = $job[$stage_status_field] ?? '';

    if ($stage_assigned) {
        // If it has a status, show it in brackets. Example: CE-TPS051 (Completed)
        $status_text = $indiv_status ? " (" . h($indiv_status) . ")" : "";
        $display_text = $stage . '-' . h($stage_assigned) . $status_text; 
        $bg_class = $bg_colors[$stage] ?? 'bg-secondary';
    } else {
        $display_text = $stage . '-Awaiting'; 
        $bg_class = 'bg-secondary';
    }
?>
                                            <span class="badge <?= $bg_class ?> d-block mb-1" style="padding:4px 8px; font-size:0.7rem;">
                                                <?= $display_text ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </td>
                                    
                                    <td>
                                        <?php
                                        $status = $job['status'] ?? 'Pending';
                                        $status_colors = [
                                            'Pending' => 'bg-warning',
                                            'In Progress' => 'bg-info', 
                                            'Completed' => 'bg-success'
                                        ];
                                        $badge_class = $status_colors[$status] ?? 'bg-secondary';
                                        ?>
                                        <span class="badge <?= $badge_class ?>" style="padding:6px 10px; font-size:0.75rem;">
                                            <?= h($status) ?>
                                        </span>
                                    </td>
                                    
                                    <!-- Notes Column -->
                                    <td>
                                        <?php if (!empty($job['notes'])): ?>
                                            <span class="d-inline-block text-truncate" style="max-width: 120px;" title="<?= h($job['notes']) ?>">
                                                <?= h(substr($job['notes'], 0, 30)) . (strlen($job['notes']) > 30 ? '...' : '') ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Queries Column -->
                                    <td>
                                        <?php if (!empty($job['queries'])): ?>
                                            <span class="d-inline-block text-truncate" style="max-width: 120px;" title="<?= h($job['queries']) ?>">
                                                <?= h(substr($job['queries'], 0, 30)) . (strlen($job['queries']) > 30 ? '...' : '') ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Word Count Column -->
                                    <td>
                                        <?php if (!empty($job['word_count']) && $job['word_count'] > 0): ?>
                                            <span class="badge bg-primary">
                                                <?= number_format($job['word_count']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td><?= formatDate($job['due_date']) ?></td>
                                    
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="jobs.php?mode=edit&id=<?= (int)$job['job_id'] ?>" class="btn btn-warning" title="Edit">
                                                <i class="fa fa-edit"></i>
                                            </a>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this job?');">
                                                <input type="hidden" name="action" value="delete" />
                                                <input type="hidden" name="job_id" value="<?= (int)$job['job_id'] ?>" />
                                                <button type="submit" class="btn btn-danger" title="Delete">
                                                    <i class="fa fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr>
                                    <td colspan="14" class="text-center py-4 text-muted">
                                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                        No jobs found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Summary Stats -->
            <?php if ($jobs_res && $jobs_res->num_rows > 0): ?>
            <div class="row mt-3">
                <div class="col-12">
                    <div class="d-flex justify-content-end">
                        <small class="text-muted">
                            <i class="fas fa-list me-1"></i> Showing <?= $jobs_res->num_rows ?> job(s)
                        </small>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
        <?php endif; ?>
    </div>

    <!-- JavaScript -->
    <script>
        // Toggle between textarea and individual fields
        function toggleInputMethod() {
            const methodTextarea = document.getElementById('methodTextarea');
            const methodIndividual = document.getElementById('methodIndividual');
            const textareaMethod = document.getElementById('textareaMethod');
            const individualMethod = document.getElementById('individualMethod');
            
            if (methodIndividual && methodIndividual.checked) {
                individualMethod.style.display = 'block';
                textareaMethod.style.display = 'none';
            } else {
                individualMethod.style.display = 'none';
                textareaMethod.style.display = 'block';
            }
        }
        
        // Individual field management
        let articleFieldCounter = 1;
        
        function addArticleField() {
            articleFieldCounter++;
            const container = document.getElementById('articleFields');
            const newField = document.createElement('div');
            newField.className = 'article-card';
            newField.innerHTML = `
                <div class="row g-2 align-items-center">
                    <div class="col-md-7">
                        <input type="text" name="article_individual[]" class="form-control form-control-sm" 
                               placeholder="Article title">
                    </div>
                    <div class="col-md-4">
                        <input type="number" name="article_word_count[]" class="form-control form-control-sm" 
                               min="0" value="0" placeholder="WC">
                    </div>
                    <div class="col-md-1">
                        <button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="removeArticleField(this)">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            `;
            container.appendChild(newField);
            
            updateRemoveButtons();
        }
        
        function removeArticleField(button) {
            const fieldRow = button.closest('.article-card');
            if (fieldRow) {
                fieldRow.remove();
                articleFieldCounter--;
                updateRemoveButtons();
            }
        }
        
        function updateRemoveButtons() {
            const fields = document.querySelectorAll('#articleFields .article-card');
            const removeButtons = document.querySelectorAll('#articleFields .btn-outline-danger');
            
            if (fields.length > 1) {
                removeButtons.forEach(btn => btn.style.display = 'block');
            } else {
                removeButtons.forEach(btn => btn.style.display = 'none');
            }
        }
        
        // Form validation
        document.getElementById('jobForm')?.addEventListener('submit', function(e) {
            const methodIndividual = document.getElementById('methodIndividual');
            const processSelect = document.querySelector('select[name="process_id"]');
            
            if (!processSelect || !processSelect.value) {
                alert('Please select a process.');
                e.preventDefault();
                return false;
            }
            
            const clientSelect = document.querySelector('select[name="client_id"]');
            if (!clientSelect || !clientSelect.value) {
                alert('Please select a client.');
                e.preventDefault();
                return false;
            }
            
            const journalInput = document.querySelector('input[name="journal_issue"]');
            if (!journalInput || !journalInput.value.trim()) {
                alert('Please enter a journal/issue.');
                e.preventDefault();
                return false;
            }
            
            if (methodIndividual && methodIndividual.checked) {
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
                const articlesTextarea = document.querySelector('textarea[name="articles_textarea"]');
                if (!articlesTextarea || articlesTextarea.value.trim() === '') {
                    alert('Please enter at least one article.');
                    e.preventDefault();
                    return false;
                }
                
                const articleLines = articlesTextarea.value.trim().split('\n').filter(line => line.trim() !== '');
                if (articleLines.length === 0) {
                    alert('Please enter at least one valid article.');
                    e.preventDefault();
                    return false;
                }
                
                const wordcountTextarea = document.querySelector('textarea[name="wordcount_textarea"]');
                if (wordcountTextarea && wordcountTextarea.value.trim() !== '') {
                    const wordcountLines = wordcountTextarea.value.trim().split('\n').filter(line => line.trim() !== '');
                    for (let i = 0; i < wordcountLines.length; i++) {
                        if (isNaN(parseInt(wordcountLines[i]))) {
                            alert(`Word count on line ${i+1} is not a valid number.`);
                            e.preventDefault();
                            return false;
                        }
                    }
                }
            }
            
            return true;
        });
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateRemoveButtons();
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>