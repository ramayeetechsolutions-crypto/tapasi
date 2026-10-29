<br><br><br>
<?php
// Helper functions
function formatDateTime($datetime, $format = 'd-m-Y H:i') {
    if (empty($datetime) || 
        $datetime == '0000-00-00 00:00:00' || 
        $datetime == '0000-00-00' ||
        $datetime == '30-11--0001 00:00' ||
        $datetime == '30-11--0001') {
        return '—';
    }
    
    $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    if (!$dateObj) {
        $dateObj = DateTime::createFromFormat('Y-m-d', $datetime);
    }
    
    return $dateObj ? $dateObj->format($format) : '—';
}
function getWorkingDaysWithoutSunday($startDate, $endDate = null) {
    if (empty($startDate) || $startDate == '0000-00-00' || $startDate == '0000-00-00 00:00:00' || $startDate == '30-11--0001') {
        return 0;
    }
    $start = new DateTime(date('Y-m-d', strtotime($startDate)));
    $end = !empty($endDate) && $endDate != '0000-00-00' && $endDate != '0000-00-00 00:00:00' 
            ? new DateTime(date('Y-m-d', strtotime($endDate))) 
            : new DateTime(date('Y-m-d'));
            
    if ($start > $end) return 0;
    
    $days = 0;
    $curr = clone $start;
    while ($curr < $end) {
        $curr->modify('+1 day');
        if ($curr->format('w') != 0) { // 0 = Sunday skip
            $days++;
        }
    }
    return $days;
}

function formatDateOnly($date, $format = 'd-m-Y') {
    if (empty($date) || 
        $date == '0000-00-00' || 
        $date == '0000-00-00 00:00:00' ||
        $date == '30-11--0001 00:00' ||
        $date == '30-11--0001') {
        return '—';
    }
    
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObj) {
        $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $date);
    }
    
    return $dateObj ? $dateObj->format($format) : '—';
}

require 'db.php';

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

if (session_status() === PHP_SESSION_NONE) session_start();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

$current_user = $_SESSION['username'] ?? '';
$uid = $_SESSION['uid'] ?? '';
if (!$current_user) {
    echo "<div class='alert alert-danger'>Session expired. Please log in again.</div>";
    exit;
}

// First, get the logged-in user's employee_name
$user_employee_name = '';
$user_sql = "SELECT reporting_manager FROM employee_master WHERE employee_username = ?";
$stmt_user = $mysqli->prepare($user_sql);
$stmt_user->bind_param("s", $current_user);
$stmt_user->execute();
$stmt_user->bind_result($user_employee_name);
$stmt_user->fetch();
$stmt_user->close();

if (!$user_employee_name) {
    echo "<div class='alert alert-warning'>User profile not found. Please contact administrator.</div>";
    exit;
}

// Filters
$q = trim($_GET['q'] ?? '');
$filter_client = trim($_GET['client'] ?? '');
$filter_process = trim($_GET['process'] ?? '');
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$sort = $_GET['sort'] ?? 'j.job_date';
$dir  = $_GET['dir'] ?? 'DESC';
$dir  = strtoupper($dir)==='ASC' ? 'ASC' : 'DESC';

$valid_sorts = ['job_id','j.job_date','client_name','journal_issue','process'];
if (!in_array($sort, $valid_sorts)) $sort = 'j.job_date';

// Process stage mapping - keeping only QC related
$processStageMap = [
    'QC' => ['QC'],
    'PAG+QC' => ['QC'],
    'CE+PAG+QC' => ['QC'],
    'PAG+QC+XML' => ['QC'],
    'CE+PAG+QC+XML' => ['QC'],
];

// Get all team members under this reporting manager to keep their jobs visible
$team_users = [];
$stmt_team = $mysqli->prepare("SELECT employee_username, employee_name FROM employee_master WHERE reporting_manager = ?");
if ($stmt_team) {
    $stmt_team->bind_param("s", $user_employee_name);
    $stmt_team->execute();
    $res_team = $stmt_team->get_result();
    while ($row_team = $res_team->fetch_assoc()) {
        if (!empty($row_team['employee_username'])) {
            $team_users[] = "'" . $mysqli->real_escape_string($row_team['employee_username']) . "'";
        }
        if (!empty($row_team['employee_name'])) {
            $team_users[] = "'" . $mysqli->real_escape_string($row_team['employee_name']) . "'";
        }
    }
    $stmt_team->close();
}

if (!empty($current_user)) {
    $team_users[] = "'" . $mysqli->real_escape_string($current_user) . "'";
}

$team_condition = "j.assigned_to = ?";
if (!empty($team_users)) {
    $team_list = implode(',', array_unique($team_users));
    $team_condition = "(j.assigned_to = ? 
        OR j.assigned_to IN ($team_list) 
        OR qc.assigned_to_qc IN ($team_list) 
        OR qc_crx.assigned_to_qc IN ($team_list) 
        OR qc.upload_by IN ($team_list) 
        OR qc_crx.upload_by IN ($team_list))";
}

// WHERE conditions - Show jobs for the whole team
$where = [$team_condition];
$params = [$user_employee_name];
$types  = 's';

// Fetch employee processes (based on logged in user)
$emp_sql = "
    SELECT e.process, e.process1, pm.process_name
    FROM employee_master e
    LEFT JOIN process_master pm ON pm.process_id IN (e.process, e.process1)
    WHERE e.employee_username = ?
";
$stmt = $mysqli->prepare($emp_sql);
$stmt->bind_param("s", $current_user);
$stmt->execute();
$res = $stmt->get_result();

$allowed_process_ids = [];
while ($row = $res->fetch_assoc()) {
    $pname = strtoupper($row['process_name']??'');

    // Only include processes that have QC
    if (strpos($pname, 'QC') !== false) {
        $allowed_process_ids = array_merge($allowed_process_ids, [2,4,5,6]); // Adjust these IDs based on your QC process IDs
    }
}

// Remove duplicates and apply process filter
$allowed_process_ids = array_unique($allowed_process_ids);
if (!empty($allowed_process_ids)) {
    $clean_ids = array_map('intval', $allowed_process_ids);
    $where[] = "j.process_id IN (" . implode(',', $clean_ids) . ")";
}

// Search query filter
if ($q) {
    $where[] = "(c.client_name LIKE ? OR c.team_client_name LIKE ? OR j.journal_issue LIKE ? OR j.article LIKE ?)";
    $params = array_merge($params, ["%$q%", "%$q%", "%$q%", "%$q%"]);
    $types .= "ssss";
}

// Client filter
if ($filter_client) {
    $where[] = "(c.client_name LIKE ? OR c.team_client_name LIKE ?)";
    $params[] = "%$filter_client%";
    $params[] = "%$filter_client%";
    $types .= "ss";
}

// Process filter
if ($filter_process) {
    $where[] = "jm.job_name = ?";
    $params[] = $filter_process;
    $types .= "s";
}

// Date range filters
if ($from_date) {
    $where[] = "j.job_date >= ?";
    $params[] = $from_date;
    $types .= "s";
}
if ($to_date) {
    $where[] = "j.job_date <= ?";
    $params[] = $to_date;
    $types .= "s";
}

// Build WHERE SQL
$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// MODIFIED QUERY: Fetching all required fields to match QC Assign view
$sql = "SELECT 
    j.job_id, j.job_date, c.client_name, c.team_client_name, j.journal_issue, j.article,
    jm.job_name AS process, j.file_path as jfile_path, j.assigned_to, j.due_date,
    j.assigned_ce, j.assigned_le, j.assigned_pag,
    
    qc.status AS qc_status, qc.assigned_to_qc, qc.qc_start_time, qc.qc_end_time,
    qc.rtat, qc.atat, qc.path, qc.priority, qc.dispatch_date, qc.upload_by as qc_upload_by,
    
    ce.status as ce_status, ce.ce_date as ce_moved_date, ce.done_by as ce_done_by, ce.assigned_to_content as ce_assigned_to,
    le.status as le_status, le.last_updated as le_moved_date, le.done_by as le_done_by, le.assigned_to_le as le_assigned_to, le.assigned_to_le_qc as le_qc_assigned_to,
    pag.status as pag_status, pag.created_at as pag_received_date, pag.done_by as pag_done_by, pag.assigned_to_pag as pag_assigned_to,
    pag.eq, pag.pages, pag.images, pag.tables_count,
    
    qc_crx.qc_crx_stage, qc_crx.pag_crx_stage, qc_crx.pag_crx_assigned_to, qc_crx.img_tab_stage, qc_crx.upload_by as crx_upload_by,
    
    ju.upload_date, ju.file_path 
FROM jobs j
JOIN client_master c ON j.client_id=c.client_id
JOIN job_master jm ON j.process_id=jm.job_id
LEFT JOIN qc ON qc.job_id=j.job_id
LEFT JOIN qc_crx ON qc_crx.job_id=j.job_id
LEFT JOIN ce ON ce.job_id=j.job_id
LEFT JOIN le ON le.job_id=j.job_id
LEFT JOIN pag ON pag.job_id=j.job_id
LEFT JOIN (
    SELECT job_id, upload_date, file_path
    FROM job_uploads 
    WHERE id IN (
        SELECT MAX(id) FROM job_uploads GROUP BY job_id
    )
) ju ON ju.job_id = j.job_id
$where_sql
ORDER BY $sort $dir";

$stmt = $mysqli->prepare($sql);
if (!$stmt) die("Prepare failed: ".$mysqli->error);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$results = $stmt->get_result();

// Fetch employee names for mapping
$emp_map = [];
$emp_res = $mysqli->query("SELECT employee_username, employee_name FROM employee_master");
if ($emp_res) {
    while ($e = $emp_res->fetch_assoc()) {
        $emp_map[$e['employee_username']] = $e['employee_name'];
    }
}


// Calculate final status counts for dashboard - now only based on QC
$status_counts = [
    'Need to Assign' => 0,
    'In Progress' => 0,
    'Completed' => 0,
    'Total' => 0
];

$all_jobs = [];
if ($results->num_rows > 0) {
    $results->data_seek(0);
    while($row = $results->fetch_assoc()) {
        $process_name = $row['process'];
        $normalized_process = strtoupper(str_replace(' ', '', $process_name));
        $required_stages = $processStageMap[$normalized_process] ?? ['QC']; // Default to QC
        
        // Determine final status based on QC only
        $qc_status = $row['qc_status'] ?? 'Need to Assign';
        $final_status = $qc_status;
        
        $status_counts[$final_status]++;
        $status_counts['Total']++;
        
        // Store for table display
        $row['final_status'] = $final_status;
        $all_jobs[] = $row;
    }
}

?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"/>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css"/>
<style>
table td { vertical-align: middle; padding: 6px 8px; white-space: nowrap; font-size: 0.9rem; }
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

/* --- Vibrant Color Scheme --- */
.status-in-progress {
    background-color: #FF6347; 
    color: white; 
    font-weight: 600;
    border-radius: 12px;
    padding: 0.3em 0.8em;
    font-size: 0.9em;
    display: inline-block;
}

.status-completed {
    background-color: #009688; 
    color: white; 
    font-weight: 600;
    border-radius: 12px;
    padding: 0.3em 0.8em;
    font-size: 0.9em;
    display: inline-block;
}

.status-need-to-assign {
    background-color: #FFA500; 
    color: white; 
    font-weight: 600;
    border-radius: 12px;
    padding: 0.3em 0.8em;
    font-size: 0.9em;
    display: inline-block;
}

/* Date and time styling */
table td {
    font-size: 0.85rem;
}

.badge {
    font-size: 0.75rem;
}

/* Dashboard Cards */
.dashboard-card {
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 15px;
    color: white;
    text-align: center;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}
.card-total { background: linear-gradient(45deg, #667eea, #764ba2); }
.card-completed { background: linear-gradient(45deg, #11998e, #38ef7d); }
.card-progress { background: linear-gradient(45deg, #ff8c00, #ff2d00); }
.card-pending { background: linear-gradient(45deg, #f46b45, #eea849); }

.dashboard-card .count {
    font-size: 2rem;
    font-weight: bold;
    margin-bottom: 5px;
}
.dashboard-card .label {
    font-size: 0.9rem;
    opacity: 0.9;
}

/* Team Leader Badge */
.team-leader-badge {
    background: linear-gradient(45deg, #8e2de2, #4a00e0);
    color: white;
    border-radius: 20px;
    padding: 8px 16px;
    font-size: 1rem;
    font-weight: 600;
    display: inline-block;
}

/* Assigned TL Column */
.tl-column {
    min-width: 150px;
}
</style>
<?php
function getStatusBadge($status, $username = '', $emp_map = []) {
    $st = trim((string)$status);
    if (empty($st) || $st === 'Need to Assign') $st = 'Need to Assign';
    
    if (strcasecmp($st, 'Completed') === 0 || strcasecmp($st, 'Done') === 0) {
        $badge = '<span class="badge" style="background-color: #28a745; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">Completed</span>';
    } elseif ($st === 'Need to Assign') {
        $badge = '<span class="badge" style="background-color: #ffc107; color: black; padding: 4px 8px; border-radius: 4px; font-size: 11px;">Need to Assign</span>';
    } else {
        $label = (strcasecmp($st, 'In Progress') === 0) ? 'In Progress' : h($st);
        $badge = '<span class="badge" style="background-color: #fd7e14; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">' . $label . '</span>';
    }
    
    if (!empty($username)) {
        $name = $emp_map[$username] ?? '';
        $display = $name ? h($name) . " (" . h($username) . ")" : h($username);
        $user_color = (strcasecmp($st, 'Completed') === 0 || strcasecmp($st, 'Done') === 0) ? '#15803d' : '#6c757d';
        return $badge . '<br><small style="color: ' . $user_color . '; font-size: 10px; font-weight: 600; display: block; margin-top: 4px; white-space: nowrap;"><i class="fas fa-user"></i> ' . $display . '</small>';
    }
    return $badge;
}
?>
<?php require 'header.php';?>

<div class="container-fluid">
    <!-- <div class="d-flex justify-content-between align-items-center mb-4"> -->
        <h2>QC Report</h2>
        <!-- <div class="team-leader-badge">
            <i class="fas fa-user-tie me-1"></i> <?= h($user_employee_name) ?>
        </div> -->
    </div>

    <!-- Dashboard Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="dashboard-card card-total">
                <div class="count"><?= $status_counts['Total'] ?></div>
                <div class="label">Total Jobs</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card card-completed">
                <div class="count"><?= $status_counts['Completed'] ?></div>
                <div class="label">QC Completed</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card card-progress">
                <div class="count"><?= $status_counts['In Progress'] ?></div>
                <div class="label">QC In Progress</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card card-pending">
                <div class="count"><?= $status_counts['Need to Assign'] ?></div>
                <div class="label">QC Need to Assign</div>
            </div>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row gy-2 gx-3 align-items-center">
                <div class="col-md-2">
                    <label class="form-label small mb-1">Search</label>
                    <input type="text" name="q" placeholder="Client/Issue/Article" value="<?=h($q)?>" class="form-control form-control-sm">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small mb-1">Client</label>
                    <input type="text" name="client" placeholder="Client" value="<?=h($filter_client)?>" class="form-control form-control-sm">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small mb-1">Process</label>
                    <select name="process" class="form-select form-select-sm">
                        <option value="">All Processes</option>
                        <option value="QC" <?=$filter_process==='QC'?'selected':''?>>QC</option>
                        <option value="PAG+QC" <?=$filter_process==='PAG+QC'?'selected':''?>>TS+QC</option>
                        <option value="CE+PAG+QC" <?=$filter_process==='CE+PAG+QC'?'selected':''?>>CE+TS+QC</option>
                        <option value="PAG+QC+XML" <?=$filter_process==='PAG+QC+XML'?'selected':''?>>TS+QC+XML</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small mb-1">From Date</label>
                    <input type="date" name="from_date" value="<?= h($from_date) ?>" class="form-control form-control-sm">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small mb-1">To Date</label>
                    <input type="date" name="to_date" value="<?= h($to_date) ?>" class="form-control form-control-sm">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small mb-1">Sort By</label>
                    <select name="sort" class="form-select form-select-sm">
                        <option value="j.job_date" <?=$sort==='j.job_date'?'selected':''?>>Date</option>
                        <option value="client_name" <?=$sort==='client_name'?'selected':''?>>Client</option>
                        <option value="journal_issue" <?=$sort==='journal_issue'?'selected':''?>>Issue</option>
                        <option value="process" <?=$sort==='process'?'selected':''?>>Process</option>
                    </select>
                </div>
                
                <!-- <div class="col-md-1">
                    <label class="form-label small mb-1">Order</label>
                    <select name="dir" class="form-select form-select-sm">
                        <option value="DESC" <?=$dir==='DESC'?'selected':''?>>Desc</option>
                        <option value="ASC" <?=$dir==='ASC'?'selected':''?>>Asc</option>
                    </select>
                </div> -->
                
                <div class="col-md-2">
                    <label class="form-label small mb-1 d-block">&nbsp;</label>
                    <button class="btn btn-primary btn-sm" type="submit">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="jobmgmt_qc.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height:650px; overflow:auto;">
                <table class="table table-striped table-bordered" id="jobTable">
                    <thead class="table-light">
                        <tr>
                            <th>LE moved Date</th>
                            <th>CE moved Date</th>
                            <th>Received Date</th>
                            <th>Client</th>
                            <th>Journal</th>
                            <th>Articles</th>
                            <th>EQN</th>
                            <th>Pages</th>
                            <th>Images</th>
                            <th>Tables</th>
                            <th>Process</th>
                            <th>CE Status</th>
                            <th>LE Status</th>
                            <th>PAG Status</th>
                            <th>QC Assign</th>
                            <th>QC Start</th>
                            <th>QC End</th>
                            <th>Due Date</th>
                            <th>R-TAT</th>
                            <th>A-TAT</th>
                            <th>Dispatch Date</th>
                            <th>Upload By</th>
                            <th>QC_CRX_Stage</th>
                            <th>PAG_CRX_Stage</th>
                            <th>IMG_TAB_Stage</th>
                            <th>Path</th>
                            <th>Priority</th>
                            <th>TAT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($all_jobs) > 0): 
                            foreach($all_jobs as $row): 
                        ?>
                        <tr>
                            <td><?= (in_array($row['le_status'], ['Completed', 'Done', 'LE & LE-QC Done', 'LE-QC Done', 'LE Done']) && !empty($row['le_moved_date'])) ? formatDateOnly(substr($row['le_moved_date'], 0, 10), 'd-m-Y') : '—' ?></td>
                            <td><?= !empty($row['ce_moved_date']) ? formatDateOnly(substr($row['ce_moved_date'], 0, 10), 'd-m-Y') : '—' ?></td>
                            <td><?= !empty($row['pag_received_date']) ? formatDateOnly(substr($row['pag_received_date'], 0, 10), 'd-m-Y') : '—' ?></td>
                            <td><?= h(!empty($row['team_client_name']) ? $row['team_client_name'] : $row['client_name']) ?></td>
                            <td><?= h($row['journal_issue']) ?></td>
                            <td><?= h($row['article']) ?></td>
                            <td><?= h($row['eq'] ?? '0') ?></td>
                            <td><?= h($row['pages'] ?? '0') ?></td>
                            <td><?= h($row['images'] ?? '0') ?></td>
                            <td><?= h($row['tables_count'] ?? '0') ?></td>
                            <td><span class="badge bg-secondary"><?= h($row['process']) ?></span></td>
                            <!-- CE Status (Green when Completed + Completed By Who) -->
                            <?php 
                                $ce_done = in_array($row['ce_status'] ?? '', ['Completed', 'Done']);
                                $ce_cls = $ce_done ? 'bg-success' : 'bg-secondary';
                                $ce_emp = !empty($row['ce_assigned_to']) ? $row['ce_assigned_to'] : (!empty($row['assigned_ce']) ? $row['assigned_ce'] : ($row['ce_done_by'] ?? ''));
                            ?>
                            <td>
                                <span class="badge <?= $ce_cls ?>"><?= h($row['ce_status'] ?? 'Pending') ?></span>
                                <?php if (!empty($ce_emp)): 
                                    $ce_name = $emp_map[$ce_emp] ?? '';
                                    $ce_display = $ce_name ? h($ce_name) . " (" . h($ce_emp) . ")" : h($ce_emp);
                                ?>
                                    <div class="mt-1" style="font-size: 10px; color: #15803d; font-weight: 600; white-space: nowrap;">
                                        <i class="fas fa-user"></i> <?= $ce_display ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- LE Status (Green when Completed + Completed By Who) -->
                            <?php 
                                $le_done = in_array($row['le_status'] ?? '', ['Completed', 'Done', 'LE Done', 'LE-QC Done', 'LE & LE-QC Done']);
                                $le_cls = $le_done ? 'bg-success' : 'bg-secondary';
                                $le_emp = !empty($row['le_qc_assigned_to']) ? $row['le_qc_assigned_to'] : (!empty($row['le_assigned_to']) ? $row['le_assigned_to'] : (!empty($row['assigned_le']) ? $row['assigned_le'] : ($row['le_done_by'] ?? '')));
                            ?>
                            <td>
                                <span class="badge <?= $le_cls ?>"><?= h($row['le_status'] ?? 'Pending') ?></span>
                                <?php if (!empty($le_emp)): 
                                    $le_name = $emp_map[$le_emp] ?? '';
                                    $le_display = $le_name ? h($le_name) . " (" . h($le_emp) . ")" : h($le_emp);
                                ?>
                                    <div class="mt-1" style="font-size: 10px; color: #15803d; font-weight: 600; white-space: nowrap;">
                                        <i class="fas fa-user"></i> <?= $le_display ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- PAG Status (Green when Completed + Completed By Who) -->
                            <?php 
                                $pag_done = in_array($row['pag_status'] ?? '', ['Completed', 'Done']);
                                $pag_cls = $pag_done ? 'bg-success' : 'bg-info';
                                $pag_emp = !empty($row['pag_assigned_to']) ? $row['pag_assigned_to'] : (!empty($row['assigned_pag']) ? $row['assigned_pag'] : ($row['pag_done_by'] ?? ''));
                            ?>
                            <td>
                                <span class="badge <?= $pag_cls ?>"><?= h($row['pag_status'] ?? 'Pending') ?></span>
                                <?php if (!empty($pag_emp)): 
                                    $pag_name = $emp_map[$pag_emp] ?? '';
                                    $pag_display = $pag_name ? h($pag_name) . " (" . h($pag_emp) . ")" : h($pag_emp);
                                ?>
                                    <div class="mt-1" style="font-size: 10px; color: #15803d; font-weight: 600; white-space: nowrap;">
                                        <i class="fas fa-user"></i> <?= $pag_display ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            
                            <!-- QC Process & Times -->
                            <td><?= getStatusBadge($row['qc_status'] ?? 'Need to Assign', $row['assigned_to_qc'] ?? '', $emp_map) ?></td>
                            <td><?= !empty($row['qc_start_time']) ? date('H:i', strtotime($row['qc_start_time'])) : '—' ?></td>
                            <td><?= !empty($row['qc_end_time']) ? date('H:i', strtotime($row['qc_end_time'])) : '—' ?></td>
                            
                            <td><?= formatDateOnly($row['due_date'], 'd-m-Y') ?></td>
                            <td class="fw-bold"><?= intval($row['rtat'] ?? 0) ?></td>
                            <td class="fw-bold"><?= intval($row['atat'] ?? 0) ?></td>
                            
                            <!-- Dispatch & Upload -->
                            <td><?= !empty($row['dispatch_date']) && $row['dispatch_date'] != '0000-00-00 00:00:00' ? formatDateOnly(substr($row['dispatch_date'], 0, 10), 'd-m-Y') : '—' ?></td>
                            <?php 
                                // QC Completed = QC row. CRX columns must show dash.
                                // Only show CRX data when QC is NOT Completed AND QC_CRX stage IS Moved.
                                $qc_completed = in_array(($row['qc_status'] ?? ''), ['Completed', 'Done']);
                                $is_crx_moved = !$qc_completed && (($row['qc_crx_stage'] ?? '') === 'Moved');
                                $up_by = $is_crx_moved ? ($row['crx_upload_by'] ?? '') : ($row['qc_upload_by'] ?? '');
                            ?>
                            <td>
                                <?php echo !empty($up_by) ? h($emp_map[$up_by] ?? $up_by) : '—'; ?>
                            </td>
                            
                            <!-- QC_CRX_Stage Column (ONLY FOR QC_CRX JOBS) -->
                            <td>
                                <?php 
                                    if ($is_crx_moved) {
                                        echo '<span class="badge bg-success text-white">Moved</span>';
                                    } else {
                                        echo '—';
                                    }
                                ?>
                            </td>

                            <!-- PAG_CRX_Stage Column (Stage + Member only, NO TIME - ONLY FOR QC_CRX JOBS) -->
                            <td>
                                <?php if ($is_crx_moved && !empty($row['pag_crx_stage'])): ?>
                                    <div class="fw-semibold"><?= h($row['pag_crx_stage']) ?></div>
                                    <?php if (!empty($row['pag_crx_assigned_to'])): 
                                        $tm_name = $emp_map[$row['pag_crx_assigned_to']] ?? '';
                                        $tm_display = $tm_name ? h($tm_name) . " (" . h($row['pag_crx_assigned_to']) . ")" : h($row['pag_crx_assigned_to']);
                                    ?>
                                        <div class="mt-1" style="font-size: 10px; color: #15803d; font-weight: 600; white-space: nowrap;">
                                            <i class="fas fa-user"></i> <?= $tm_display ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <!-- IMG_TAB_Stage Column (ONLY FOR QC_CRX JOBS) -->
                            <td>
                                <?php 
                                    if ($is_crx_moved && !empty($row['img_tab_stage'])) {
                                        echo h($row['img_tab_stage']);
                                    } else {
                                        echo '—';
                                    }
                                ?>
                            </td>

                            <!-- Path & Priority -->
                            <td>
                                <?php if(!empty($row['path'])): ?>
                                    <span class="badge <?= (strtoupper($row['path']) === 'CRX') ? 'bg-warning text-dark' : 'bg-success text-white' ?>">
                                        <?= h($row['path']) ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if(!empty($row['priority'])): ?>
                                    <span class="badge <?= ($row['priority'] === 'Urgent') ? 'bg-danger' : 'bg-warning text-dark' ?>">
                                        <?= h($row['priority']) ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <!-- TAT Column -->
                            <?php
                                $qc_done = in_array($row['qc_status'] ?? '', ['Completed', 'Done']);
                                $end_d = $qc_done ? (($row['dispatch_date'] ?? null) ?: ($row['qc_date'] ?? null) ?: ($row['last_updated'] ?? null) ?: date('Y-m-d')) : null;
                                $tat = getWorkingDaysWithoutSunday($row['job_date'] ?? '', $end_d);
                                $bg = $qc_done ? 'bg-success' : ($tat > 5 ? 'bg-danger' : 'bg-warning text-dark');
                            ?>
                            <td>
                                <span class="badge <?= $bg ?>"><?= $tat ?> <?= ($tat == 1) ? 'Day' : 'Days' ?></span>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="24" class="text-center">No jobs found assigned to you.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function exportTableToExcel(tableID, filename=''){
  var table = document.getElementById(tableID);
  var html = table.outerHTML.replace(/ /g, '%20');
  var downloadLink = document.createElement("a");
  filename = filename ? filename + '.xls' : 'export.xls';
  downloadLink.href = 'data:application/vnd.ms-excel,' + html;
  downloadLink.download = filename;
  document.body.appendChild(downloadLink);
  downloadLink.click();
  document.body.removeChild(downloadLink);
}
</script>