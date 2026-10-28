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

function formatDate($date, $format = 'd-m-Y') {
    if (!$date) return '';
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    return $dateObj ? $dateObj->format($format) : $date;
}

function formatDateTime($datetime, $format = 'd-m-Y H:i:s') {
    if (!$datetime) return '';
    $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    return $dateObj ? $dateObj->format($format) : $datetime;
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

$logged_user = $_SESSION['username'] ?? '';
$role_name = $_SESSION['role_name'] ?? '';

$search_client = trim($_GET['search_client'] ?? '');
$sort = $_GET['sort'] ?? 'job_id';
$dir = strtoupper($_GET['dir'] ?? 'DESC');
if (!in_array($dir, ['ASC', 'DESC'], true)) $dir = 'DESC';

$allowed_sorts = ['job_id', 'job_date', 'client_name', 'job_name', 'article', 'assigned_to', 'due_date'];
if (!in_array($sort, $allowed_sorts, true)) $sort = 'job_id';

// Handle start/stop actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role_name === 'TM') {
    $action = $_POST['action'] ?? '';
    $job_id = (int)($_POST['job_id'] ?? 0);
    $process_id = (int)($_POST['process_id'] ?? 0);
    
    if ($action === 'start_job' && $job_id > 0 && $process_id > 0) {
        // Check if job is already started by this user
        $check_stmt = $mysqli->prepare("
            SELECT tracking_id FROM job_time_tracking 
            WHERE employee_username = ? AND job_id = ? AND end_datetime IS NULL
        ");
        $check_stmt->bind_param("si", $logged_user, $job_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            // Start the job
            $start_time = date('Y-m-d H:i:s');
            $stmt = $mysqli->prepare("
                INSERT INTO job_time_tracking (employee_username, job_id, process_id, start_datetime, assigned_tm_status)
                VALUES (?, ?, ?, ?, 'In Progress')
            ");
            $stmt->bind_param("siis", $logged_user, $job_id, $process_id, $start_time);
            
            if ($stmt->execute()) {
                header("Location: assigned_jobs_le.php?msg=Job+started+successfully&type=success");
            } else {
                header("Location: assigned_jobs_le.php?msg=Failed+to+start+job&type=danger");
            }
            $stmt->close();
        } else {
            header("Location: assigned_jobs_le.php?msg=Job+already+started&type=warning");
        }
        $check_stmt->close();
        exit;
    }
    elseif ($action === 'stop_job' && $job_id > 0) {
        // Stop the job
        $end_time = date('Y-m-d H:i:s');
        
        // Get the tracking record
        $get_stmt = $mysqli->prepare("
            SELECT tracking_id, start_datetime 
            FROM job_time_tracking 
            WHERE employee_username = ? AND job_id = ? AND end_datetime IS NULL
            ORDER BY tracking_id DESC LIMIT 1
        ");
        $get_stmt->bind_param("si", $logged_user, $job_id);
        $get_stmt->execute();
        $result = $get_stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $tracking_id = $row['tracking_id'];
            $start_datetime = $row['start_datetime'];
            
            // Calculate total minutes
            $start = new DateTime($start_datetime);
            $end = new DateTime($end_time);
            $interval = $start->diff($end);
            $total_minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
            
            // Update tracking record
            $update_stmt = $mysqli->prepare("
                UPDATE job_time_tracking 
                SET end_datetime = ?, total_minutes = ?, assigned_tm_status = 'Completed'
                WHERE tracking_id = ?
            ");
            $update_stmt->bind_param("sii", $end_time, $total_minutes, $tracking_id);
            
            if ($update_stmt->execute()) {
                header("Location: assigned_jobs_le.php?msg=Job+stopped+successfully&type=success");
            } else {
                header("Location: assigned_jobs_le.php?msg=Failed+to+stop+job&type=danger");
            }
            $update_stmt->close();
        } else {
            header("Location: assigned_jobs_le.php?msg=No+active+job+found+to+stop&type=warning");
        }
        $get_stmt->close();
        exit;
    }
}

// Load all processes (for reference only)
$all_processes = [];
$res = $mysqli->query("SELECT job_id, job_name FROM job_master WHERE status='Active' ORDER BY job_name");
if (!$res) die('Error loading processes: ' . $mysqli->error);
while ($r = $res->fetch_assoc()) $all_processes[$r['job_id']] = $r['job_name'];

// Get all clients (for reference only)
$clients_res = $mysqli->query("SELECT client_id, client_name FROM client_master WHERE status='Active' ORDER BY client_name");
if (!$clients_res) die('Error fetching clients: ' . $mysqli->error);

// --- Main query to fetch job list ---
$where_clauses = [];
$params = [];
$types = '';

// Get current logged-in username
$current_username = $_SESSION['username'] ?? '';

// FIXED: Show only jobs where LE is assigned to the current user in the le table
if ($role_name === 'TM' && !empty($current_username)) {
    $where_clauses[] = "le.assigned_to_le = ?"; // Filter by LE assignment in le table
    $params[] = $current_username;
    $types .= 's';
}

// FIXED: Filter to show only CE-related jobs where CE is COMPLETED
if ($role_name === 'TM') {
    // Show jobs where process includes CE
    $where_clauses[] = "(p.job_name IN ('CE', 'CE+PAG', 'CE+XML', 'CE+PAG+XML'))";
    // CE must be completed
    $where_clauses[] = "ce.status = 'Completed'";
    // LE is not completed yet (optional - remove if you want to show completed LE jobs too)
    $where_clauses[] = "(le.status IS NULL OR le.status != 'Completed')";
}

if (!empty($search_client)) {
    $where_clauses[] = "c.client_name LIKE ?";
    $params[] = "%$search_client%";
    $types .= 's';
}

$where_sql = '';
if ($where_clauses) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

// FIXED: Use INNER JOIN for le to ensure we only get records that exist in le table
$sql = "SELECT j.job_id, j.job_date, j.journal_issue, j.mail_subject, j.article, 
               j.assigned_to, j.assigned_to_tm, j.notes, j.queries, j.word_count,
               j.assigned_ce, j.assigned_le, j.assigned_pag, j.assigned_qc, j.assigned_xml, 
               j.due_date, j.process_id, c.client_name, p.job_name,
               
               ce.ce_date, ce.done_by AS ce_done_by, ce.status AS ce_status,
               le.le_date, le.done_by AS le_done_by, le.status AS le_status,
               le.assigned_to_le, -- LE assignment field from le table
               
               (SELECT tracking_id FROM job_time_tracking 
                WHERE employee_username = le.assigned_to_le
                AND job_id = j.job_id 
                AND end_datetime IS NULL 
                ORDER BY tracking_id DESC LIMIT 1) as active_tracking_id,
               (SELECT start_datetime FROM job_time_tracking 
                WHERE employee_username = le.assigned_to_le 
                AND job_id = j.job_id 
                AND end_datetime IS NULL 
                ORDER BY tracking_id DESC LIMIT 1) as active_start_time,
               (SELECT assigned_tm_status FROM job_time_tracking 
                WHERE employee_username = le.assigned_to_le 
                AND job_id = j.job_id 
                ORDER BY tracking_id DESC LIMIT 1) as latest_tm_status,
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
        
        -- FIXED: Use INNER JOIN for ce since we need CE completed
        JOIN ce ON ce.job_id = j.job_id
        
        -- FIXED: Use INNER JOIN for le to ensure records exist and are assigned
        JOIN le ON le.job_id = j.job_id
        
        $where_sql
        ORDER BY $sort $dir";
//echo $sql;
if ($where_sql && $types) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) die('Error preparing jobs query: ' . $mysqli->error);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $jobs_res = $stmt->get_result();
    
    // Debug: Check if any results
    if ($jobs_res->num_rows == 0) {
        // Log for debugging
        error_log("No results found for user: " . $current_username);
    }
    
    $stmt->close();
} else {
    $jobs_res = $mysqli->query($sql);
    if (!$jobs_res) die('Error executing jobs query: ' . $mysqli->error);
}

// Debug query (uncomment to see the SQL)
// echo "<!-- SQL: " . str_replace('--', '', $sql) . " -->";

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My LE Assigned Jobs</title>
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
        .timer-badge { background-color: #dc3545; color: white; animation: pulse 2s infinite; }
        .btn-action-group .btn { margin-right: 3px; margin-bottom: 3px; }
        .ce-completed-badge { background-color: #28a745; color: white; }
        .le-pending-badge { background-color: #ffc107; color: #000; }
        .assigned-to-me {
            border-left: 4px solid #0d6efd;
        }
        .welcome-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
        }
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
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

        <!-- Welcome Banner -->
        <div class="welcome-banner d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-1">
                    <i class="fas fa-user-check me-2"></i> My LE Assigned Jobs
                </h4>
                <p class="mb-0 opacity-75">
                    <i class="fas fa-user me-2"></i> <?= h($logged_user) ?> | 
                    <i class="fas fa-check-circle me-2 ms-2"></i> Showing CE completed jobs assigned to you for LE
                </p>
            </div>
            <?php if ($role_name === 'TM'): ?>
                <a href="time_tracking_report.php?employee=<?= urlencode($logged_user) ?>" 
                   class="btn btn-light btn-sm">
                    <i class="fas fa-chart-line"></i> My Time Report
                </a>
            <?php endif; ?>
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
                <a href="assigned_jobs_le.php" class="btn btn-secondary btn-sm">Clear</a>
            </div>
        </form>

        <!-- Debug info (commented out) -->
        <!-- 
        <div class="alert alert-info">
            <strong>Debug:</strong> User: <?= h($logged_user) ?><br>
            <strong>Query:</strong> <?= h(str_replace('  ', ' ', $sql)) ?><br>
            <strong>Results:</strong> <?= $jobs_res ? $jobs_res->num_rows : 0 ?> records
        </div>
        -->

        <div class="card">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <i class="fas fa-list me-2"></i> My LE Assignments
                        <span class="badge bg-primary ms-2"><?= $jobs_res ? $jobs_res->num_rows : 0 ?> Jobs</span>
                    </h6>
                    <span class="badge bg-info">Assigned to: <?= h($logged_user) ?></span>
                </div>
            </div>
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
                                <th>Assigned To (LE)</th>
                                <th>Job Assign</th>
                                <th>CE Status</th>
                                <th>LE Status</th>
                                <th>TM Status</th>
                                <th>Notes</th>
                                <th>Queries</th>
                                <th>Word Count</th>
                                <th>Due Date</th>
                                <?php if ($role_name === 'TM'): ?>
                                    <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($jobs_res && $jobs_res->num_rows > 0):
                                $i=0;
                                while ($job = $jobs_res->fetch_assoc()): 
                                $i=$i+1;
                                $is_job_active = !empty($job['active_tracking_id']);
                                // Use latest_tm_status from tracking table
                                $tm_status = $job['latest_tm_status'] ?? 'Not Started';
                                
                                // Check if process includes CE (for CE button)
                                $process_name = $job['job_name'];
                                $norm_proc = normalize_process($process_name);
                                $has_ce_process = in_array('CE', $processStageMap[$norm_proc] ?? []);
                                
                                // Get CE and LE status
                                $ce_status = $job['ce_status'] ?? 'Pending';
                                $le_status = $job['le_status'] ?? 'Pending';
                                
                                // Check if CE is completed and LE is not completed
                                $ce_completed = ($ce_status === 'Completed');
                                $le_not_completed = ($le_status !== 'Completed');
                                
                                // Check if this job is assigned to current user for LE
                                $assigned_to_me = ($job['assigned_to_le'] == $logged_user);
                            ?>
                                <tr class="<?= $assigned_to_me ? 'assigned-to-me' : '' ?>">
                                    <td><?= $i; ?></td>
                                    <td><?= formatDate($job['job_date']) ?></td>
                                    <td><?= h($job['client_name']) ?></td>
                                    <td><?= h($job['journal_issue']) ?></td>
                                    <td><?= h($job['article']) ?></td>
                                    <td><?= h($job['mail_subject']) ?></td>
                                    <td><?= h($job['job_name']) ?></td>
                                    
                                    <!-- Assigned To (LE) Column -->
                                    <td>
                                        <?php if (!empty($job['assigned_to_le'])): ?>
                                            <span class="badge bg-info text-white">
                                                <i class="fas fa-user me-1"></i><?= h($job['assigned_to_le']) ?>
                                            </span>
                                            <?php if ($job['assigned_to_le'] == $logged_user): ?>
                                                <span class="badge bg-success ms-1">You</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td>
                                        <?php
                                        $stages = $processStageMap[$norm_proc] ?? [];

                                        $bg_colors = [
                                            'CE'  => $ce_completed ? 'bg-success text-white' : 'bg-primary text-white',
                                            'LE'  => $le_not_completed ? 'bg-warning text-dark' : 'bg-info text-dark',
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
                                    
                                    <!-- CE Status Column -->
                                    <td>
                                        <?php if ($ce_completed): ?>
                                            <span class="badge ce-completed-badge" style="padding:6px 10px; font-size:0.85rem;">
                                                <i class="fas fa-check-circle me-1"></i>Completed
                                            </span>
                                            <?php if (!empty($job['ce_done_by'])): ?>
                                                <div class="small text-muted mt-1">By: <?= h($job['ce_done_by']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark" style="padding:6px 10px; font-size:0.85rem;">
                                                <?= h($ce_status) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- LE Status Column -->
                                    <td>
                                        <?php if ($le_not_completed): ?>
                                            <span class="badge le-pending-badge" style="padding:6px 10px; font-size:0.85rem;">
                                                <i class="fas fa-clock me-1"></i><?= h($le_status ?: 'Pending') ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success text-white" style="padding:6px 10px; font-size:0.85rem;">
                                                <i class="fas fa-check-circle me-1"></i>Completed
                                            </span>
                                            <?php if (!empty($job['le_done_by'])): ?>
                                                <div class="small text-muted mt-1">By: <?= h($job['le_done_by']) ?></div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- TM Status Column -->
                                    <td>
                                        <?php
                                        $status_colors = [
                                            'Not Started' => 'bg-secondary text-white',
                                            'In Progress' => 'bg-warning text-dark',
                                            'Completed' => 'bg-success text-white'
                                        ];
                                        $badge_class = $status_colors[$tm_status] ?? 'bg-secondary text-white';
                                        
                                        if ($is_job_active): ?>
                                            <span class="badge timer-badge" id="timer-<?= $job['job_id'] ?>" style="padding:6px 10px; font-size:0.85rem;">
                                                <i class="fas fa-clock"></i> Running
                                            </span>
                                        <?php else: ?>
                                            <span class="badge <?= $badge_class ?>" style="padding:6px 10px; font-size:0.85rem;">
                                                <?= h($tm_status) ?>
                                            </span>
                                        <?php endif; ?>
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
                                    
                                    <!-- Action Column for TM -->
                                    <?php if ($role_name === 'TM'): ?>
                                    <td>
                                        <div class="btn-action-group">
                                            <?php if ($assigned_to_me && $ce_completed && $le_not_completed): ?>
                                                <?php if (!$is_job_active && $tm_status !== 'Completed'): ?>
                                                    <!-- Start Job Button (for LE work) -->
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="action" value="start_job">
                                                        <input type="hidden" name="job_id" value="<?= (int)$job['job_id'] ?>">
                                                        <input type="hidden" name="process_id" value="<?= (int)$job['process_id'] ?>">
                                                        <button type="submit" class="btn btn-success btn-sm mb-1" 
                                                                onclick="return confirm('Start working on LE for this job?')"
                                                                title="Start LE Work">
                                                            <i class="fas fa-play me-1"></i> Start LE
                                                        </button>
                                                    </form>
                                                <?php elseif ($is_job_active): ?>
                                                    <!-- Stop Job Button -->
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="action" value="stop_job">
                                                        <input type="hidden" name="job_id" value="<?= (int)$job['job_id'] ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm mb-1"
                                                                onclick="return confirm('Stop working on this job?')"
                                                                title="Stop Job">
                                                            <i class="fas fa-stop me-1"></i> Stop
                                                        </button>
                                                    </form>
                                                    
                                                    <!-- Move to LE Button -->
                                                    <a href="le.php?job_id=<?= (int)$job['job_id'] ?>" 
                                                       class="btn btn-info btn-sm mb-1"
                                                       title="Go to Language Editing">
                                                        <i class="fas fa-arrow-right me-1"></i> LE Work
                                                    </a>
                                                <?php endif; ?>
                                            <?php elseif (!$ce_completed): ?>
                                                <span class="text-muted small">CE not completed</span>
                                            <?php elseif ($le_status === 'Completed'): ?>
                                                <span class="badge bg-success">LE Completed</span>
                                            <?php elseif (!$assigned_to_me): ?>
                                                <span class="text-muted small">Assigned to <?= h($job['assigned_to_le']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr>
                                    <td colspan="<?= $role_name === 'TM' ? 17 : 16 ?>" class="text-center py-5">
                                        <div class="py-4">
                                            <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                                            <h5 class="text-muted">No LE jobs assigned to you</h5>
                                            <p class="text-muted">
                                                You don't have any LE assignments yet.<br>
                                                Jobs will appear here when they are assigned to you for Language Editing.
                                            </p>
                                            <?php if ($role_name === 'TM'): ?>
                                                <a href="le_assign.php" class="btn btn-primary btn-sm mt-2">
                                                    <i class="fas fa-eye me-2"></i>View Available LE Jobs
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <?php if ($role_name === 'TM'): ?>
    <script>
        // Function to update timers for active jobs
        function updateTimers() {
            <?php
            // Re-fetch active jobs to update timers
            $active_jobs_sql = "SELECT j.job_id, jtt.start_datetime 
                               FROM jobs j 
                               JOIN job_time_tracking jtt ON j.job_id = jtt.job_id 
                               WHERE jtt.employee_username = ? AND jtt.end_datetime IS NULL";
            $active_stmt = $mysqli->prepare($active_jobs_sql);
            $active_stmt->bind_param("s", $logged_user);
            $active_stmt->execute();
            $active_result = $active_stmt->get_result();
            
            while ($active_job = $active_result->fetch_assoc()):
                $start_time = strtotime($active_job['start_datetime']);
                $job_id = $active_job['job_id'];
            ?>
                var startTime<?= $job_id ?> = <?= $start_time ?> * 1000; // Convert to milliseconds
                var timerElement = document.getElementById('timer-<?= $job_id ?>');
                if (timerElement) {
                    var updateTimer = function() {
                        var now = new Date().getTime();
                        var elapsed = now - startTime<?= $job_id ?>;
                        var hours = Math.floor(elapsed / (1000 * 60 * 60));
                        var minutes = Math.floor((elapsed % (1000 * 60 * 60)) / (1000 * 60));
                        var seconds = Math.floor((elapsed % (1000 * 60)) / 1000);
                        
                        timerElement.innerHTML = '<i class="fas fa-clock"></i> ' + 
                            hours.toString().padStart(2, '0') + ':' + 
                            minutes.toString().padStart(2, '0') + ':' + 
                            seconds.toString().padStart(2, '0');
                    };
                    setInterval(updateTimer, 1000);
                    updateTimer(); // Initial call
                }
            <?php endwhile; 
            $active_stmt->close();
            ?>
        }
        
        // Initialize timers when page loads
        document.addEventListener('DOMContentLoaded', function() {
            updateTimers();
        });
    </script>
    <?php endif; ?>
</body>
</html>