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

require 'db.php';
session_start();

// Auto-add priority column if not exists to avoid Unknown column error
$check_priority = $mysqli->query("SHOW COLUMNS FROM ce LIKE 'priority'");
if ($check_priority && $check_priority->num_rows == 0) {
    $mysqli->query("ALTER TABLE ce ADD COLUMN priority VARCHAR(50) NULL DEFAULT NULL");
}

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
$filter_emp = trim($_GET['filter_emp'] ?? '');
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$sort = $_GET['sort'] ?? 'j.job_date';
$dir  = $_GET['dir'] ?? 'DESC';
$dir  = strtoupper($dir)==='ASC' ? 'ASC' : 'DESC';

$valid_sorts = ['job_id','j.job_date','client_name','journal_issue','process'];
if (!in_array($sort, $valid_sorts)) $sort = 'j.job_date';

// Process stage mapping - keeping only CE related
$processStageMap = [
    'CE' => ['CE'],
    'CE+PAG' => ['CE'],
    'CE+PAG+XML' => ['CE'],
    'CE+XML' => ['CE'],
];

// Get all team members under this reporting manager to keep their jobs visible
$team_users = [];
$unique_team_members = [];
$stmt_team = $mysqli->prepare("SELECT employee_username, employee_name FROM employee_master WHERE reporting_manager = ? ORDER BY employee_name ASC");
$stmt_team->bind_param("s", $user_employee_name);
$stmt_team->execute();
$res_team = $stmt_team->get_result();
while ($row_team = $res_team->fetch_assoc()) {
    $team_users[] = "'" . $mysqli->real_escape_string($row_team['employee_username']) . "'";
    $unique_team_members[$row_team['employee_username']] = $row_team['employee_name'];
}
$stmt_team->close();

$team_condition = "j.assigned_to = ?";
if (!empty($team_users)) {
    $team_list = implode(',', $team_users);
    // Show jobs if assigned to TL, OR assigned to any TM for Content, Images, or FQA
    $team_condition = "(j.assigned_to = ? OR ce.assigned_to_content IN ($team_list) OR ce.assigned_to_img IN ($team_list) OR j.assigned_to IN ($team_list))";
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

    // Only include processes that have CE
    if (strpos($pname, 'CE') !== false) {
        $allowed_process_ids = array_merge($allowed_process_ids, [1,4,5,37]);
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

// Employee filter (Checks Content, Img/Ref, or assigned_to)
if ($filter_emp) {
    $where[] = "(ce.assigned_to_content = ? OR ce.assigned_to_img = ? OR j.assigned_to = ?)";
    $params[] = $filter_emp;
    $params[] = $filter_emp;
    $params[] = $filter_emp;
    $types .= "sss";
}

// Build WHERE SQL
$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

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

// SAFE QUERY: Using only existing columns in database
$sql = "SELECT 
    -- Jobs table fields
    j.job_id, j.job_date, c.client_name, c.team_client_name, j.journal_issue, j.article,
    jm.job_name AS process, j.dispatch_date, j.file_path as jfile_path, j.assigned_to, j.due_date,
    j.word_count,
    
    -- CE table fields
   ce.eq as ce_eq, ce.ref as ce_ref, ce.images as ce_images, ce.tables_count as ce_tables,
    ce.ce_date, ce.ce_start_time, ce.ce_end_time, ce.ref_start_time, ce.ref_end_time, ce.fqa_start_time, ce.fqa_end_time, ce.done_by AS ce_done_by, ce.status AS ce_status,
    ce.assigned_to_content, ce.assigned_to_img, ce.priority, ce.remarks as ce_remarks, 
    
    -- From job_uploads table
    ju.upload_date, ju.upload_by, ju.file_path 

FROM jobs j
JOIN client_master c ON j.client_id=c.client_id
JOIN job_master jm ON j.process_id=jm.job_id
LEFT JOIN ce ON ce.job_id=j.job_id
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

// Calculate final status counts for dashboard - now only based on CE
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
        $required_stages = $processStageMap[$normalized_process] ?? ['CE']; // Default to CE
        
        // Determine final status based on CE only
        $ce_status = $row['ce_status'] ?? 'Need to Assign';
        $final_status = $ce_status;
        
        // FIX FOR WARNING: Group intermediate statuses into predefined dashboard keys
        $count_key = 'In Progress'; // Default fallback for CE Done, REF Done, etc.
        if ($final_status === 'Need to Assign') {
            $count_key = 'Need to Assign';
        } elseif ($final_status === 'Completed' || $final_status === 'Done') {
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
    
    if (strcasecmp($st, 'Completed') === 0 || strcasecmp($st, 'Done') === 0) {
        $badge = '<span class="badge" style="background-color: #28a745; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">Completed</span>';
    } else {
        // WIP / In Progress / Need to Assign -> Orange
        $label = (strcasecmp($st, 'In Progress') === 0 || strcasecmp($st, 'WIP') === 0) ? 'WIP' : h($st);
        $badge = '<span class="badge" style="background-color: #fd7e14; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">' . $label . '</span>';
    }
    
    // Add user name below the badge if assigned
    if (!empty($username)) {
        $name = $emp_map[$username] ?? '';
        $display = $name ? h($name) . " (" . h($username) . ")" : h($username);
        return $badge . '<br><small style="color: #6c757d; font-size: 10px; font-weight: 600; display: block; margin-top: 4px; white-space: nowrap;">' . $display . '</small>';
    }
    
    return $badge;
}
?>

<?php require 'header.php';?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>CE Report</h2>
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
                <div class="label">CE Completed</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card card-progress">
                <div class="count"><?= $status_counts['In Progress'] ?></div>
                <div class="label">CE In Progress</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card card-pending">
                <div class="count"><?= $status_counts['Need to Assign'] ?></div>
                <div class="label">CE Need to Assign</div>
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
                        <option value="CE" <?=$filter_process==='CE'?'selected':''?>>CE</option>
                        <option value="CE+PAG" <?=$filter_process==='CE+PAG'?'selected':''?>>CE+TS</option>
                        <option value="CE+XML" <?=$filter_process==='CE+XML'?'selected':''?>>CE+XML</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small mb-1">Employee</label>
                    <select name="filter_emp" class="form-select form-select-sm">
                        <option value="">All Team Members</option>
                        <?php foreach ($unique_team_members as $uname => $ename): ?>
                            <option value="<?= h($uname) ?>" <?= $filter_emp === $uname ? 'selected' : '' ?>>
                                <?= h($ename) ?> (<?= h($uname) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-1">
                    <label class="form-label small mb-1">From Date</label>
                    <input type="date" name="from_date" value="<?= h($from_date) ?>" class="form-control form-control-sm">
                </div>
                
                <div class="col-md-1">
                    <label class="form-label small mb-1">To Date</label>
                    <input type="date" name="to_date" value="<?= h($to_date) ?>" class="form-control form-control-sm">
                </div>
                
                <div class="col-md-1">
                    <label class="form-label small mb-1">Sort By</label>
                    <select name="sort" class="form-select form-select-sm">
                        <option value="j.job_date" <?=$sort==='j.job_date'?'selected':''?>>Date</option>
                        <option value="client_name" <?=$sort==='client_name'?'selected':''?>>Client</option>
                        <option value="journal_issue" <?=$sort==='journal_issue'?'selected':''?>>Issue</option>
                        <option value="process" <?=$sort==='process'?'selected':''?>>Process</option>
                    </select>
                </div>
                
                <div class="col-md-1">
                    <label class="form-label small mb-1 d-block">&nbsp;</label>
                    <div class="d-flex gap-1">
                        <button class="btn btn-primary btn-sm" type="submit">
                            <i class="fas fa-search"></i>
                        </button>
                        <a href="jobmgmt_ce.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height:650px; overflow:auto;">
                <table class="table table-striped table-bordered align-middle" id="jobTable">
                    <thead class="table-light">
                        <tr>
                            <th>S.No</th>
                            <th>Received Date</th>
                            <th>CE Completed Date</th>
                            <th>Client</th>
                            <th>Issue</th>
                            <th>Articles</th>
                            <th>EQ</th>
                            <th>Images</th>
                            <th>Tables</th>
                            <th>Process</th>
                            <th>Due Date</th>
                            <th>Remarks</th>
                            <th>CE Status</th>
                            <th>Word Count</th>
                            <th>REF Status</th>
                            <th>REF Count</th>
                            <th>FQA Status</th>
                            <th>CE Start</th>
                            <th>CE End</th>
                            <th>REF Start</th>
                            <th>REF End</th>
                            <th>FQA Start</th>
                            <th>FQA End</th>
                            <th>TAT</th>
                            <th>Priority</th>
                        </tr>
                    </thead>
                    <tbody>
                       <?php if (count($all_jobs) > 0): 
                            $i=1; 
                            foreach($all_jobs as $row): 
                                $ce_stat = !empty($row['ce_status']) ? $row['ce_status'] : 'WIP';
                                $ref_stat = !empty($row['assigned_to_img']) ? ($row['ce_status'] ?? 'WIP') : 'WIP';
                                $fqa_stat = ($ce_stat === 'Completed') ? 'Completed' : 'WIP';
                                
                                // TAT Calculation (Current Date - Received Date)
                                $tat_display = '—';
                                if (!empty($row['job_date']) && $row['job_date'] != '0000-00-00' && $row['job_date'] != '0000-00-00 00:00:00') {
                                    $job_date_obj = new DateTime(date('Y-m-d', strtotime($row['job_date'])));
                                    $today_obj = new DateTime(date('Y-m-d'));
                                    $diff_days = $today_obj->diff($job_date_obj)->days;
                                    
                                    if ($diff_days <= 2) {
                                        $tat_display = '<span class="badge bg-success">' . $diff_days . ' Days</span>';
                                    } else {
                                        $tat_display = '<span class="badge bg-danger">' . $diff_days . ' Days</span>';
                                    }
                                }
                        ?>
                        <tr>
                            <!-- # -->
                            <td><?=$i++?></td>

                            <!-- Received Date -->
                            <td><?= formatDateOnly($row['job_date'], 'd-m-Y') ?></td>

                            <!-- CE Completed Date -->
                            <td><?= formatDateOnly($row['ce_date'], 'd-m-Y') ?></td>

                            <!-- 1. Client -->
                            <td><?=h($row['team_client_name'] ?: $row['client_name'])?></td>

                            <!-- 2. Issue -->
                            <td><?=h($row['journal_issue'])?></td>

                            <!-- 3. Articles -->
                            <td><?=h($row['article'])?></td>

                            <!-- 4. EQ -->
                            <td><?=h($row['ce_eq'] ?? '0')?></td>

                            <!-- 5. Images -->
                            <td><?=h($row['ce_images'] ?? '0')?></td>

                            <!-- 6. Tables -->
                            <td><?=h($row['ce_tables'] ?? '0')?></td>

                            <!-- 7. Process -->
                            <td><span class="badge bg-secondary"><?=h($row['process'])?></span></td>

                            <!-- 8. Due Date -->
                            <td><?= formatDateOnly($row['due_date'], 'd-m-Y') ?></td>

                            <!-- Remarks -->
                            <td>
                                <?php if (!empty($row['ce_remarks'])): ?>
                                    <span class="badge bg-light text-dark border"><?= h($row['ce_remarks']) ?></span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <!-- 9. CE Status (Orange: WIP / Green: Completed) -->
                            <td><?= getStatusBadge($ce_stat, $row['assigned_to_content'] ?? '', $emp_map) ?></td>

                            <!-- 10. Word Count -->
                            <td><?= !empty($row['word_count']) ? number_format((int)$row['word_count']) : '—' ?></td>

                            <!-- 11. REF Status (Orange: WIP / Green: Completed) -->
                            <td><?= getStatusBadge($ref_stat, $row['assigned_to_img'] ?? '', $emp_map) ?></td>

                            <!-- 12. REF Count -->
                            <td><?=h($row['ce_ref'] ?? '0')?></td>

                            <!-- 13. FQA Status (Orange: WIP / Green: Completed) -->
                            <td><?= getStatusBadge($fqa_stat, $row['assigned_to'] ?? '', $emp_map) ?></td>

                            <!-- CE Times -->
                            <td><?= !empty($row['ce_start_time']) ? date('H:i', strtotime($row['ce_start_time'])) : '—' ?></td>
                            <td><?= !empty($row['ce_end_time']) ? date('H:i', strtotime($row['ce_end_time'])) : '—' ?></td>

                            <!-- REF Times -->
                            <td><?= !empty($row['ref_start_time']) ? date('H:i', strtotime($row['ref_start_time'])) : '—' ?></td>
                            <td><?= !empty($row['ref_end_time']) ? date('H:i', strtotime($row['ref_end_time'])) : '—' ?></td>

                            <!-- FQA Times -->
                            <td><?= !empty($row['fqa_start_time']) ? date('H:i', strtotime($row['fqa_start_time'])) : '—' ?></td>
                            <td><?= !empty($row['fqa_end_time']) ? date('H:i', strtotime($row['fqa_end_time'])) : '—' ?></td>

                            <!-- 16. TAT -->
                            <td><?= $tat_display ?></td>
                            
                            <!-- 17. Priority -->
                            <td>
                                <?php if (!empty($row['priority'])): ?>
                                    <span class="badge <?= $row['priority'] === 'Urgent' ? 'bg-danger' : 'bg-warning text-dark' ?>"><?= h($row['priority']) ?></span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
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
