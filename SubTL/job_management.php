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

// Calculate days excluding Sundays
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

require 'db.php';
session_start();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

function isStageDone($status) {
    $s = strtolower(trim((string)$status));
    return ($s !== '' && (strpos($s, 'completed') !== false || strpos($s, 'done') !== false));
}
function pickVal(...$vals) {
    foreach ($vals as $v) {
        $t = trim((string)$v);
        if ($t !== '' && $t !== '0') return $t;
    }
    return '';
}
function nameOf($u, $map) {
    $u = trim((string)$u);
    if ($u === '') return '';
    return $map[$u] ?? $u;
}

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

// Get all Team Leaders (user_role_id = 3) - Keep this for the dropdown
$team_leaders = [];
$tl_query = "SELECT em.employee_id, em.employee_username, em.employee_name 
             FROM employee_master em 
             WHERE em.user_role_id = 3 
             AND em.status = 'Active'
             ORDER BY em.employee_name";
$tl_result = $mysqli->query($tl_query);
if ($tl_result) {
    while ($tl = $tl_result->fetch_assoc()) {
        $team_leaders[] = $tl;
    }
}

// Filters
$q = trim($_GET['q'] ?? '');
$filter_client = trim($_GET['client'] ?? '');
$filter_process = trim($_GET['process'] ?? '');
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$selected_tl = trim($_GET['selected_tl'] ?? '');
$sort = $_GET['sort'] ?? 'j.job_date';
$dir  = $_GET['dir'] ?? 'DESC';
$dir  = strtoupper($dir)==='ASC' ? 'ASC' : 'DESC';

$valid_sorts = ['job_id','j.job_date','client_name','journal_issue','process','qc.dispatch_date'];
if (!in_array($sort, $valid_sorts)) $sort = 'j.job_date';

// Process stage mapping
$processStageMap = [
    'CE' => ['CE', 'LE'],
    'PAG' => ['PAG', 'QC'],
    'CE+PAG' => ['CE', 'LE', 'PAG', 'QC'],
    'CE+PAG+XML' => ['CE', 'LE', 'PAG', 'QC', 'XML'],
    'CE+XML' => ['CE', 'LE', 'XML'],
    'PAG+XML' => ['PAG', 'QC', 'XML'],
    'XML' => ['XML'],
    'QC' => ['QC'],
    'PAG+QC' => ['PAG', 'QC'],
    'CE+PAG+QC' => ['CE', 'LE', 'PAG', 'QC'],
    'PAG+QC+XML' => ['PAG', 'QC', 'XML'],
    'CE+PAG+QC+XML' => ['CE', 'LE', 'PAG', 'QC', 'XML'],
];

// Get all team members under this reporting manager to keep their jobs visible
$team_users = [];
$stmt_team = $mysqli->prepare("SELECT employee_username FROM employee_master WHERE reporting_manager = ?");
if ($stmt_team) {
    $stmt_team->bind_param("s", $user_employee_name);
    $stmt_team->execute();
    $res_team = $stmt_team->get_result();
    while ($row_team = $res_team->fetch_assoc()) {
        $team_users[] = "'" . $mysqli->real_escape_string($row_team['employee_username']) . "'";
    }
    $stmt_team->close();
}

$team_condition = "j.assigned_to = ?";
if (!empty($team_users)) {
    $team_list = implode(',', $team_users);
    $team_condition = "(j.assigned_to = ? OR j.assigned_to IN ($team_list) OR le.assigned_to_le IN ($team_list) OR ce.assigned_to_content IN ($team_list))";
}

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
$panme="";
while ($row = $res->fetch_assoc()) {
    $pname = strtoupper($row['process_name']??'');

    if (strpos($pname, 'CE') !== false) {
        $allowed_process_ids = array_merge($allowed_process_ids, [1,4,5,37]);
    }
    if (strpos($pname, 'PAG') !== false) {
        $allowed_process_ids = array_merge($allowed_process_ids, [2,4,5]);
    }
    if (strpos($pname, 'XML') !== false) {
        $allowed_process_ids = array_merge($allowed_process_ids, [3,5,37]);
    }
}

// Remove duplicates and apply process filter
$allowed_process_ids = array_unique($allowed_process_ids);
if (!empty($allowed_process_ids)) {
    // Sanitize to ensure only integers are passed in the IN clause
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

// Main query - updated to include dispatch_date from jobs and data from job_uploads
$sql = "SELECT 
    j.job_id, j.job_date, c.client_name, c.team_client_name, j.journal_issue, j.article,
    jm.job_name AS process, qc.dispatch_date, j.file_path as jfile_path, j.assigned_to, j.assigned_ce,
    ce.eq as ce_eq, ce.ref as ce_ref, ce.images as ce_images, ce.tables_count as ce_tables,
    ce.ce_date, ce.done_by AS ce_done_by, ce.status AS ce_status,
    le.le_date, le.done_by AS le_done_by, le.status AS le_status,
    le.last_updated AS le_moved_date, le.assigned_to_le, le.assigned_to_le_qc, le.status_button AS le_status_button,
    le.eq AS le_eq, le.images AS le_images, le.tables_count AS le_tables,
    pag.pag_date, pag.done_by AS pag_done_by, pag.status AS pag_status,
    qc.qc_date, qc.done_by AS qc_done_by, qc.status AS qc_status, qc.assigned_to_qc,
    pag.eq AS pag_eq, pag.pages, pag.images, pag.tables_count, j.due_date, qc.rtat, qc.atat,
    ju.upload_date, ju.upload_by, ju.file_path 

FROM jobs j
JOIN client_master c ON j.client_id=c.client_id
JOIN job_master jm ON j.process_id=jm.job_id

LEFT JOIN ce  ON ce.job_id=j.job_id
LEFT JOIN le  ON le.job_id=j.job_id
LEFT JOIN pag ON pag.job_id=j.job_id
LEFT JOIN qc  ON qc.job_id=j.job_id

-- Join with job_uploads table to get upload information
LEFT JOIN (
    SELECT job_id, upload_date, upload_by, file_path
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

$emp_map = [];
$emp_res = $mysqli->query("SELECT employee_username, employee_name FROM employee_master");
if ($emp_res) {
    while ($e = $emp_res->fetch_assoc()) {
        $emp_map[$e['employee_username']] = $e['employee_name'];
    }
}

// Calculate final status counts for dashboard
$status_counts = [
    'Need to Assign' => 0,
    'In Progress' => 0,
    'Completed' => 0,
    'Total' => 0
];

$all_jobs = [];
if ($results->num_rows > 0) {
    $results->data_seek(0); // Reset pointer
    while($row = $results->fetch_assoc()) {
        $process_name = $row['process'];
        $normalized_process = strtoupper(str_replace(' ', '', $process_name));
        $required_stages = $processStageMap[$normalized_process] ?? [];
        
        // Determine final status based on required stages
        $all_completed = true;
        $has_in_progress = false;
        $has_need_to_assign = false;
        $final_status = 'Completed';
        
        foreach ($required_stages as $stage) {
            $status_field = strtolower($stage) . '_status';
            $stage_status = $row[$status_field] ?? 'Need to Assign';
            
            // For CE process, LE stage is ONLY considered completed if status_button is 'Moved'
            $stage_is_done = isStageDone($stage_status);
            if ($stage === 'LE' && $normalized_process === 'CE' && ($row['le_status_button'] ?? '') !== 'Moved') {
                $stage_is_done = false;
            }
            
            if (!$stage_is_done) {
                $all_completed = false;
                
                if ($stage_status === 'In Progress' || isStageDone($stage_status)) {
                    $has_in_progress = true;
                } elseif ($stage_status === 'Need to Assign') {
                    $has_need_to_assign = true;
                }
            }
        }
        
        // Determine final status
        if ($all_completed) {
            $final_status = 'Completed';
        } elseif ($has_in_progress) {
            $final_status = 'In Progress';
        } elseif ($has_need_to_assign) {
            $final_status = 'Need to Assign';
        } else {
            $final_status = 'In Progress';
        }
        
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

/* Ensure proper spacing for date columns */
table th:nth-child(2),
table td:nth-child(2),
table th:nth-child(8),
table td:nth-child(8),
table th:nth-child(11),
table td:nth-child(11),
table th:nth-child(14),
table td:nth-child(14),
table th:nth-child(17),
table td:nth-child(17),
table th:nth-child(25),
table td:nth-child(25),
table th:nth-child(26),
table td:nth-child(26) {
    white-space: nowrap;
    min-width: 120px;
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
<?php require 'header.php';?>

<div class="container-fluid">
    <!-- <div class="d-flex justify-content-between align-items-center mb-4"> -->
        <h2>My Assigned Jobs</h2>
        <!-- <div class="team-leader-badge"> -->
            <!-- <i class="fas fa-user-tie me-1"></i> <?= h($user_employee_name) ?> -->
        </div>
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
                <div class="label">Completed</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card card-progress">
                <div class="count"><?= $status_counts['In Progress'] ?></div>
                <div class="label">In Progress</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card card-pending">
                <div class="count"><?= $status_counts['Need to Assign'] ?></div>
                <div class="label">Need to Assign</div>
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
                        <option value="PAG" <?=$filter_process==='PAG'?'selected':''?>>TS</option>
                        <option value="CE+PAG" <?=$filter_process==='CE+PAG'?'selected':''?>>CE+TS</option>
                        <option value="CE" <?=$filter_process==='CE'?'selected':''?>>CE</option>
                        <option value="LE" <?=$filter_process==='LE'?'selected':''?>>LE</option>
                        <option value="QC" <?=$filter_process==='QC'?'selected':''?>>QC</option>
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
                        <option value="qc.dispatch_date" <?=$sort==='qc.dispatch_date'?'selected':''?>>Dispatch Date</option>
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
                    <a href="job_management.php" class="btn btn-outline-secondary btn-sm">
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
                        <th>LE Completed Date</th>
                        <th>CE Completed Date</th>
                        <th>Received Date</th>
                        <th>Client</th>
                        <th>Journal</th>
                        <th>Articles</th>
                        <th>Pages</th>
                        <th>EQN</th>
                        <th>Images</th>
                        <th>Tables</th>
                        <th>Process</th>
                        <th>CE</th>
                        <th>LE</th>
                        <th>PAG</th>
                        <th>QC</th>
                        <th>Due Date</th>
                        <th>R-TAT</th>
                        <th>A-TAT</th>
                        <th>Dispatch Date</th>
                        <th>Upload By</th>
                        <th>Path</th>
                        <th>Client</th>
                        <th>H</th>
                        <th>CE</th>
                        <th>LE</th>
                        <th>TS</th>
                        <th>QC</th>
                        <th>Week</th>
                        <th>Final Status</th>
                    </tr>
                    </thead>
                    <tbody>
                        <?php if (count($all_jobs) > 0): 
                            $i=1; 
                            foreach($all_jobs as $row): 
                                $process_name = $row['process'];
                                $normalized_process = strtoupper(str_replace(' ', '', $process_name));
                                $required_stages = $processStageMap[$normalized_process] ?? [];
                                
                                $final_status = $row['final_status'];
                                $status_class = '';
                                if ($final_status === 'Completed') {
                                    $status_class = 'status-completed';
                                } elseif ($final_status === 'In Progress') {
                                    $status_class = 'status-in-progress';
                                } else {
                                    $status_class = 'status-need-to-assign';
                                }
                        ?>
                        <tr>
                            <?php 
$le_is_done_flag = (isStageDone($row['le_status']) && ($row['process'] !== 'CE' || ($row['le_status_button'] ?? '') === 'Moved'));
?>
<td><?= ($le_is_done_flag && !empty($row['le_moved_date'])) ? formatDateOnly(substr($row['le_moved_date'],0,10),'d-m-Y') : '—' ?></td>
                            <td><?= !empty($row['ce_date']) ? formatDateOnly(substr($row['ce_date'],0,10),'d-m-Y') : '—' ?></td>
                            <td><?= formatDateTime($row['job_date'], 'd-m-Y') ?></td>
                            <td><?=h($row['team_client_name'] ?: $row['client_name'])?></td>
                            <td><?=h($row['journal_issue'])?></td>
                            <td><?=h($row['article'])?></td>
                            <td><?= h(pickVal($row['pages'])) ?></td>
                            <td><?= h(pickVal($row['pag_eq'], $row['le_eq'], $row['ce_eq'])) ?></td>
                            <td><?= h(pickVal($row['images'], $row['le_images'], $row['ce_images'])) ?></td>
                            <td><?= h(pickVal($row['tables_count'], $row['le_tables'], $row['ce_tables'])) ?></td>

                            <!-- Process Column -->
                            <td><span class="badge bg-secondary"><?= h($row['process'] ?? '—') ?></span></td>

                            <!-- CE -->
                            <td>
                            <?php if (in_array('CE', $required_stages)):
                                $ce_st = $row['ce_status'] ?? 'Need to Assign'; ?>
                                <div class="fw-bold"><?= h(nameOf(($row['ce_done_by'] ?: $row['assigned_ce']), $emp_map) ?: '—') ?></div>
                                <span class="badge mt-1 <?= isStageDone($ce_st) ? 'bg-success' : ($ce_st === 'In Progress' ? 'bg-warning' : 'bg-secondary') ?>"><?= h($ce_st) ?></span>
                            <?php else: ?>—<?php endif; ?>
                            </td>

                            <!-- LE -->
                            <td>
                            <?php if (in_array('LE', $required_stages)):
                                $le_st = $row['le_status'] ?? 'Need to Assign'; 
                                $le_completed_now = (isStageDone($le_st) && ($row['process'] !== 'CE' || ($row['le_status_button'] ?? '') === 'Moved'));
                            ?>
                                <div class="fw-bold"><?= h(nameOf(($row['assigned_to_le'] ?: $row['le_done_by']), $emp_map) ?: '—') ?></div>
                                <?php if (!empty($row['assigned_to_le_qc'])): ?>
                                    <div class="small text-muted">LE-QC: <?= h(nameOf($row['assigned_to_le_qc'], $emp_map)) ?></div>
                                <?php endif; ?>
                                <span class="badge mt-1 <?= $le_completed_now ? 'bg-success' : ($le_st === 'In Progress' || isStageDone($le_st) ? 'bg-warning' : 'bg-secondary') ?>">
                                    <?= $le_completed_now ? 'Completed' : ($le_st === 'Need to Assign' ? 'Need to Assign' : 'In Progress') ?>
                                </span>
                            <?php else: ?>—<?php endif; ?>
                            </td>

                            <!-- PAG -->
                            <td>
                            <?php if (in_array('PAG', $required_stages)):
                                $pag_st = $row['pag_status'] ?? 'Need to Assign'; ?>
                                <div class="fw-bold"><?= h(nameOf($row['pag_done_by'], $emp_map) ?: '—') ?></div>
                                <span class="badge mt-1 <?= isStageDone($pag_st) ? 'bg-success' : ($pag_st === 'In Progress' ? 'bg-warning' : 'bg-secondary') ?>"><?= h($pag_st) ?></span>
                            <?php else: ?>—<?php endif; ?>
                            </td>

                            <!-- QC -->
                            <td>
                            <?php if (in_array('QC', $required_stages)):
                                $qc_st = $row['qc_status'] ?? 'Need to Assign'; ?>
                                <div class="fw-bold"><?= h(nameOf(($row['assigned_to_qc'] ?: $row['qc_done_by']), $emp_map) ?: '—') ?></div>
                                <span class="badge mt-1 <?= isStageDone($qc_st) ? 'bg-success' : ($qc_st === 'In Progress' ? 'bg-warning' : 'bg-secondary') ?>"><?= h($qc_st) ?></span>
                            <?php else: ?>—<?php endif; ?>
                            </td>

                            <td><?= formatDateOnly($row['due_date'], 'd-m-Y') ?></td>

                            <?php 
                                // 1. R-TAT
                                $rtat_days = 0;
                                if (!empty($row['job_date']) && !empty($row['due_date']) && $row['due_date'] != '0000-00-00' && $row['job_date'] != '0000-00-00') {
                                    $rec_ts = strtotime(substr($row['job_date'], 0, 10));
                                    $due_ts = strtotime(substr($row['due_date'], 0, 10));
                                    if ($rec_ts && $due_ts) {
                                        $rtat_days = round(abs($due_ts - $rec_ts) / 86400);
                                    }
                                }

                                // 2. A-TAT
                                $atat_days = 0;
                                if (!empty($row['job_date']) && $row['job_date'] != '0000-00-00') {
                                    $rec_ts   = strtotime(substr($row['job_date'], 0, 10));
                                    $today_ts = strtotime(date('Y-m-d'));
                                    if ($rec_ts && $today_ts) {
                                        $diff = round(($today_ts - $rec_ts) / 86400);
                                        $atat_days = max(0, $diff);
                                    }
                                }
                            ?>
                            <td><?= $rtat_days ?></td>
                            <td><?= $atat_days ?></td>

                            <!-- Dispatch Date -->
                            <td>
                                <?php if (!empty($row['dispatch_date']) && $row['dispatch_date'] != '0000-00-00 00:00:00' && $row['dispatch_date'] != '30-11--0001 00:00'): ?>
                                    <span class="badge bg-success">
                                        <?= formatDateOnly(substr($row['dispatch_date'],0,10), 'd-m-Y') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Not Dispatched</span>
                                <?php endif; ?>
                            </td>

                            <td><?= h($row['upload_by'] ?? '—') ?></td>
                            
                            <!-- Path -->
                            <td>
                                <?php if (!empty($row['file_path'])): ?>
                                    <a href="<?= h($row['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.75rem;">
                                        <i class="fas fa-download"></i> View
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                                                        <!-- 1. Client TAT (Red if > 5 days, green otherwise) -->
                            <?php 
                                $client_tat = getWorkingDaysWithoutSunday($row['job_date']);
                                $client_badge = ($client_tat > 5) ? 'bg-danger' : 'bg-success';
                            ?>
                            <td>
                                <span class="badge <?= $client_badge ?>"><?= $client_tat ?> Days</span>
                            </td>

                            <!-- 2. H (Editable Text Field) -->
                            <td>
                                <input type="text" class="form-control form-control-sm p-1 text-center" style="width: 70px; font-size: 11px;" value="<?= h($row['h'] ?? '') ?>" placeholder="H" onchange="updateJobField(<?= $row['job_id'] ?>, 'h', this.value)">
                            </td>

                            <!-- 3. CE TAT (Stops if CE Completed) -->
                            <?php 
                                $ce_is_done = isStageDone($row['ce_status']);
                                $ce_end = $ce_is_done ? $row['ce_date'] : null;
                                $ce_tat = getWorkingDaysWithoutSunday($row['job_date'], $ce_end);
                            ?>
                            <td>
                                <span class="badge <?= $ce_is_done ? 'bg-success' : 'bg-warning text-dark' ?>">
                                    <?= $ce_tat ?> <?= ($ce_tat == 1) ? 'Day' : 'Days' ?>
                                </span>
                            </td>

                            <!-- 4. LE TAT (Stops if LE Completed) -->
                            <?php 
                                $le_is_done = isStageDone($row['le_status']);
                                $le_end = $le_is_done ? ($row['le_moved_date'] ?: $row['le_date']) : null;
                                $le_tat = getWorkingDaysWithoutSunday($row['job_date'], $le_end);
                            ?>
                            <td>
                                <span class="badge <?= $le_is_done ? 'bg-success' : 'bg-warning text-dark' ?>">
                                    <?= $le_tat ?> <?= ($le_tat == 1) ? 'Day' : 'Days' ?>
                                </span>
                            </td>

                            <!-- 5. TS TAT (Stops if TS Completed) -->
                            <?php 
                                $pag_is_done = isStageDone($row['pag_status']);
                                $pag_end = $pag_is_done ? $row['pag_date'] : null;
                                $pag_tat = getWorkingDaysWithoutSunday($row['job_date'], $pag_end);
                            ?>
                            <td>
                                <span class="badge <?= $pag_is_done ? 'bg-success' : 'bg-warning text-dark' ?>">
                                    <?= $pag_tat ?> <?= ($pag_tat == 1) ? 'Day' : 'Days' ?>
                                </span>
                            </td>

                            <!-- 6. QC TAT (Stops if QC Completed) -->
                            <?php 
                                $qc_is_done = isStageDone($row['qc_status']);
                                $qc_end = $qc_is_done ? ($row['dispatch_date'] ?: $row['qc_date']) : null;
                                $qc_tat = getWorkingDaysWithoutSunday($row['job_date'], $qc_end);
                            ?>
                            <td>
                                <span class="badge <?= $qc_is_done ? 'bg-success' : 'bg-warning text-dark' ?>">
                                    <?= $qc_tat ?> <?= ($qc_tat == 1) ? 'Day' : 'Days' ?>
                                </span>
                            </td>

                            <!-- 7. Week Dropdown -->
                            <td>
                                <select class="form-select form-select-sm p-1" style="width: 80px; font-size: 11px;" onchange="updateJobField(<?= $row['job_id'] ?>, 'week', this.value)">
                                    <option value="">Select</option>
                                    <?php for($w=1; $w<=53; $w++): 
                                        $w_val = "W".$w;
                                        $sel = (isset($row['week']) && $row['week'] === $w_val) ? 'selected' : '';
                                    ?>
                                        <option value="<?= $w_val ?>" <?= $sel ?>><?= $w_val ?></option>
                                    <?php endfor; ?>
                                </select>
                            </td>

                            <!-- Final Status -->
                            <td>
                                <span class="<?= $status_class ?>">
                                    <?= h($final_status) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="22" class="text-center">No jobs found assigned to you.</td></tr>
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