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
session_start();

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

// Process stage mapping - keeping only LE related
$processStageMap = [
    'LE' => ['LE'],
    'CE+LE' => ['LE'],
    'CE+PAG+LE' => ['LE'],
    'CE+LE+XML' => ['LE'],
];

// Get all team members under this reporting manager to keep their jobs visible
$team_users = [];
$stmt_team = $mysqli->prepare("SELECT employee_username FROM employee_master WHERE reporting_manager = ?");
$stmt_team->bind_param("s", $user_employee_name);
$stmt_team->execute();
$res_team = $stmt_team->get_result();
while ($row_team = $res_team->fetch_assoc()) {
    $team_users[] = "'" . $mysqli->real_escape_string($row_team['employee_username']) . "'";
}
$stmt_team->close();

$team_condition = "j.assigned_to = ?";
if (!empty($team_users)) {
    $team_list = implode(',', $team_users);
    // Show jobs if assigned to TL directly, OR assigned to any TM for LE or LE-QC
    $team_condition = "(j.assigned_to = ? OR le.assigned_to_le IN ($team_list) OR le.assigned_to_le_qc IN ($team_list))";
}

// WHERE conditions - Base conditions
$where = [$team_condition];
$params = [$user_employee_name];
$types  = 's';

// Ensure we only show relevant LE processes (Matches LE Assign logic exactly)
$where[] = "(REPLACE(jm.job_name, ' ', '') = 'CE+PAG' OR REPLACE(jm.job_name, ' ', '') = 'CE+TS' OR REPLACE(jm.job_name, ' ', '') = 'LE')";

// Search query filter
if ($q) {
    $where[] = "(c.client_name LIKE ? OR j.journal_issue LIKE ? OR j.article LIKE ?)";
    $params = array_merge($params, ["%$q%", "%$q%", "%$q%"]);
    $types .= "sss";
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

// MODIFIED QUERY: Prioritizing LE table data for EQ, Images, Tables, and Word Count
$sql = "SELECT 
    j.job_id, j.job_date, c.client_name, c.team_client_name, j.journal_issue, j.article,
    jm.job_name AS process, j.due_date, j.assigned_to AS fqa_user,
    ce.status AS ce_status, ce.ce_date, ce.assigned_to_content, ce.assigned_to_img, ce.ref, 
    COALESCE(NULLIF(le.word_count, 0), NULLIF(ce.word_count, 0), NULLIF(j.word_count, 0), 0) AS word_count,
    le.eq, ce.eq AS ce_eq, 
    le.images, ce.images AS ce_images, 
    le.tables_count, ce.tables_count AS ce_tables,
    le.status AS le_status, le.le_date, le.assigned_to_le, le.assigned_to_le_qc,
    le.le_start_time, le.le_end_time, le.le_qc_start_time, le.le_qc_end_time,
        le.wc_exref, le.status_button, le.priority, le.last_updated
FROM jobs j
JOIN client_master c ON j.client_id=c.client_id
JOIN job_master jm ON j.process_id=jm.job_id
LEFT JOIN le ON le.job_id=j.job_id
LEFT JOIN ce ON ce.job_id=j.job_id
$where_sql
ORDER BY $sort $dir";

$stmt = $mysqli->prepare($sql);
if (!$stmt) die("Prepare failed: ".$mysqli->error);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$results = $stmt->get_result();

// Get unique clients for search dropdown
$clients_query = "SELECT DISTINCT client_name, team_client_name FROM client_master ORDER BY client_name ASC";
$clients_result = $mysqli->query($clients_query);

// Fetch employee names for mapping
$emp_map = [];
$emp_res = $mysqli->query("SELECT employee_username, employee_name FROM employee_master");
if ($emp_res) {
    while ($e = $emp_res->fetch_assoc()) {
        $emp_map[$e['employee_username']] = $e['employee_name'];
    }
}

// Calculate final status counts for dashboard - now only based on LE
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
        $required_stages = $processStageMap[$normalized_process] ?? ['LE']; // Default to LE
        
        // Determine final status based on LE only
        $le_status = $row['le_status'] ?? 'Need to Assign';
        $final_status = $le_status;
        
        // Map intermediate statuses to dashboard count keys to avoid Undefined array key error
        $count_key = 'In Progress'; // Default fallback for 'LE Done', 'LE-QC Done', etc.
        if ($final_status === 'Need to Assign' || $final_status === 'Pending' || $final_status === '') {
            $count_key = 'Need to Assign';
        } elseif ($final_status === 'Completed' || $final_status === 'Done' || $final_status === 'LE & LE-QC Done') {
            $count_key = 'Completed';
        }
        
        $status_counts[$count_key]++;
        $status_counts['Total']++;
        
        // Store for table display
        $row['final_status'] = $final_status;
        $all_jobs[] = $row;
    }
}

?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"/>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css"/>
<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
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

/* Select2 Custom Style */
.select2-container .select2-selection--single
    height: 31px !important;
    border: 1px solid #dee2e6 !important;
    border-radius: 0.25rem !important;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 29px !important;
    font-size: 0.875rem !important;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 29px !important;
}
table td,th { vertical-align: middle; padding: 6px 8px; white-space: nowrap; font-size: 0.9rem; }

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
    if (empty($st)) $st = 'WIP';
    
    if (strcasecmp($st, 'Completed') === 0 || strcasecmp($st, 'Done') === 0 || strcasecmp($st, 'LE-QC Done') === 0) {
        $badge = '<span class="badge" style="background-color: #28a745; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">Completed</span>';
    } else {
        $label = (strcasecmp($st, 'In Progress') === 0 || strcasecmp($st, 'WIP') === 0) ? 'WIP' : h($st);
        $badge = '<span class="badge" style="background-color: #fd7e14; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">' . $label . '</span>';
    }
    
    if (!empty($username)) {
        $name = $emp_map[$username] ?? '';
        $display = $name ? h($name) . " (" . h($username) . ")" : h($username);
        return $badge . '<br><small style="color: #6c757d; font-size: 10px; font-weight: 600; display: block; margin-top: 4px; white-space: nowrap;">' . $display . '</small>';
    }
    return $badge;
}
?>
<?php require 'header.php';?>

<div class="container-fluid px-4">
    <!-- <div class="d-flex justify-content-between align-items-center mb-4"> -->
        <h2 class="mt-2 mb-4">LE Report</h2>
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
                <div class="label">LE Completed</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card card-progress">
                <div class="count"><?= $status_counts['In Progress'] ?></div>
                <div class="label">LE In Progress</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card card-pending">
                <div class="count"><?= $status_counts['Need to Assign'] ?></div>
                <div class="label">LE Need to Assign</div>
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
                    <select name="client" id="clientSearchDropdown" class="form-select form-select-sm">
                        <option value="">All Clients...</option>
                        <?php if (isset($clients_result) && $clients_result): ?>
                            <?php 
                            $displayed_clients = [];
                            while ($cli = $clients_result->fetch_assoc()): 
                                $display_name = !empty($cli['team_client_name']) ? $cli['team_client_name'] : $cli['client_name'];
                                
                                if (!in_array($display_name, $displayed_clients)):
                                    $displayed_clients[] = $display_name;
                            ?>
                                <option value="<?= h($display_name) ?>" <?= $filter_client === $display_name ? 'selected' : '' ?>>
                                    <?= h($display_name) ?>
                                </option>
                            <?php 
                                endif;
                            endwhile; 
                            ?>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small mb-1">Process</label>
                    <select name="process" class="form-select form-select-sm">
                        <option value="">All Processes</option>
                        <option value="CE+PAG" <?=$filter_process==='CE+PAG'?'selected':''?>>CE+PAG</option>
                        <option value="CE+TS" <?=$filter_process==='CE+TS'?'selected':''?>>CE+TS</option>
                        <option value="LE" <?=$filter_process==='LE'?'selected':''?>>LE Only</option>
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
                            <th>Issue</th>
                            <th>Articles</th>
                            <th>EQ</th>
                            <th>Images</th>
                            <th>Tables</th>
                            <th>Process</th>
                            <th>Due Date</th>
                            <th>CE Status</th>
                            <th>Word Count</th>
                            <th>WC-ExRef</th>
                            <th>REF Status</th>
                            <th>REF Count</th>
                            <th>FQA Status</th>
                            <th>LE Assignment</th>
                            <th>LE-QC Assignment</th>
                            <th>LE Start</th>
                            <th>LE End</th>
                            <th>LE-QC Start</th>
                            <th>LE-QC End</th>
                            <th>Status</th>
                            <th>Priority</th>
<th>TAT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($all_jobs) > 0): 
                            foreach($all_jobs as $row): 
                                $ce_stat = !empty($row['ce_status']) ? $row['ce_status'] : 'WIP';
                                $ref_stat = !empty($row['assigned_to_img']) ? ($row['ce_status'] ?? 'WIP') : 'WIP';
                                $fqa_stat = ($ce_stat === 'Completed') ? 'Completed' : 'WIP';
                                $le_stat = !empty($row['le_status']) ? $row['le_status'] : 'WIP';
                                $leqc_stat = in_array($row['le_status'], ['LE-QC Done', 'Completed', 'LE & LE-QC Done']) ? 'Completed' : (!empty($row['assigned_to_le_qc']) ? 'WIP' : 'WIP');
                        ?>
                        <tr>
                            <td><?= (in_array($row['final_status'], ['Completed', 'Done', 'LE & LE-QC Done', 'LE-QC Done', 'LE Done'])) ? formatDateOnly($row['last_updated'], 'd-m-Y') : '—' ?></td>
                            <td><?= formatDateOnly($row['ce_date'], 'd-m-Y') ?></td>
                            <td><?= formatDateOnly($row['job_date'], 'd-m-Y') ?></td>
                            <td><?= h(!empty($row['team_client_name']) ? $row['team_client_name'] : $row['client_name']) ?></td>
                            <td><?= h($row['journal_issue']) ?></td>
                            <td><?= h($row['article']) ?></td>
                            <td><?= h(!empty($row['eq']) ? $row['eq'] : ($row['ce_eq'] ?? '0')) ?></td>
                             <td><?= h(!empty($row['images']) ? $row['images'] : ($row['ce_images'] ?? '0')) ?></td>
                            <td><?= h(!empty($row['tables_count']) ? $row['tables_count'] : ($row['ce_tables'] ?? '0')) ?></td>
                            <td><span class="badge bg-secondary"><?= h($row['process']) ?></span></td>
                            <td><?= formatDateOnly($row['due_date'], 'd-m-Y') ?></td>
                            <td><?= getStatusBadge($ce_stat, $row['assigned_to_content'] ?? '', $emp_map) ?></td>
                            <td><?= !empty($row['word_count']) ? number_format((int)$row['word_count']) : '0' ?></td>
                            <td><?= h($row['wc_exref'] ?? '—') ?></td>
                            <td><?= getStatusBadge($ref_stat, $row['assigned_to_img'] ?? '', $emp_map) ?></td>
                            <td><?= h($row['ref'] ?? '0') ?></td>
                            <td><?= getStatusBadge($fqa_stat, $row['fqa_user'] ?? '', $emp_map) ?></td>
                            <td><?= getStatusBadge($le_stat, $row['assigned_to_le'] ?? '', $emp_map) ?></td>
                            <td><?= getStatusBadge($leqc_stat, $row['assigned_to_le_qc'] ?? '', $emp_map) ?></td>
                            <td><?= !empty($row['le_start_time']) ? date('H:i', strtotime($row['le_start_time'])) : '—' ?></td>
                            <td><?= !empty($row['le_end_time']) ? date('H:i', strtotime($row['le_end_time'])) : '—' ?></td>
                            <td><?= !empty($row['le_qc_start_time']) ? date('H:i', strtotime($row['le_qc_start_time'])) : '—' ?></td>
                            <td><?= !empty($row['le_qc_end_time']) ? date('H:i', strtotime($row['le_qc_end_time'])) : '—' ?></td>
                            <td>
                                <?php if (!empty($row['status_button'])): ?>
                                    <span class="badge <?= $row['status_button'] === 'Moved' ? 'bg-success' : 'bg-info text-dark' ?>"><?= h($row['status_button']) ?></span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['priority'])): ?>
                                    <span class="badge <?= $row['priority'] === 'Urgent' ? 'bg-danger' : 'bg-warning text-dark' ?>"><?= h($row['priority']) ?></span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <!-- TAT Column -->
                                <?php
                                    $le_done = in_array($row['le_status'] ?? '', ['Completed', 'Done', 'LE & LE-QC Done']);
                                    $end_d = $le_done ? ($row['le_date'] ?: $row['last_updated']) : null;
                                    $tat = getWorkingDaysWithoutSunday($row['job_date'], $end_d);
                                    $bg = $le_done ? 'bg-success' : ($tat > 5 ? 'bg-danger' : 'bg-warning text-dark');
                                ?>
                                <td>
                                    <span class="badge <?= $bg ?>"><?= $tat ?> <?= ($tat == 1) ? 'Day' : 'Days' ?></span>
                                </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="20" class="text-center">No jobs found assigned to you.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('#clientSearchDropdown').select2({ width: '100%' });
});

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