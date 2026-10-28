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

// Process stage mapping - keeping only PAG related
$processStageMap = [
    'PAG' => ['PAG'],
    'CE+PAG' => ['PAG'],
    'PAG+XML' => ['PAG'],
    'CE+PAG+XML' => ['PAG'],
];

// WHERE conditions - Show jobs assigned to current user
$where = ["j.assigned_to = ?"];
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

    // Only include processes that have PAG
    if (strpos($pname, 'PAG') !== false) {
        $allowed_process_ids = array_merge($allowed_process_ids, [2,4,5]); // Adjust these IDs based on your PAG process IDs
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

// MODIFIED QUERY: Fetching all required fields to match TS Assign view
$sql = "SELECT 
    j.job_id, j.job_date, c.client_name, c.team_client_name, j.journal_issue, j.article,
    jm.job_name AS process, j.dispatch_date, j.file_path as jfile_path, j.assigned_to, j.due_date,
    
    pag.status AS pag_status, pag.pag_status_col, pag.word_status,
    pag.created_at, pag.pag_date, pag.done_by AS pag_done_by,
    pag.eq, pag.pages, pag.images, pag.tables_count,
    pag.assigned_to_img, pag.assigned_to_pag,
    pag.img_start_time, pag.img_end_time, pag.pag_start_time, pag.pag_end_time,
    pag.path, pag.priority,
    j.assigned_pag,
    
    ju.upload_date, ju.upload_by, ju.file_path,
    
    le.last_updated as le_moved_date, ce.ce_date as ce_moved_date,
    le.status as le_status, qc.status as qc_status,
    qc.rtat, qc.atat

FROM jobs j
JOIN client_master c ON j.client_id=c.client_id
JOIN job_master jm ON j.process_id=jm.job_id
LEFT JOIN pag ON pag.job_id=j.job_id
LEFT JOIN ce ON ce.job_id=j.job_id
LEFT JOIN le ON le.job_id=j.job_id
LEFT JOIN qc ON qc.job_id=j.job_id
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

// Fetch employee names for mapping
$emp_map = [];
$emp_res = $mysqli->query("SELECT employee_username, employee_name FROM employee_master");
if ($emp_res) {
    while ($e = $emp_res->fetch_assoc()) {
        $emp_map[$e['employee_username']] = $e['employee_name'];
    }
}

// Calculate final status counts for dashboard - now only based on PAG
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
        $required_stages = $processStageMap[$normalized_process] ?? ['PAG']; // Default to PAG
        
        // Determine final status based on PAG only
        $pag_status = $row['pag_status'] ?? 'Need to Assign';
        $final_status = $pag_status;
        
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
    
    if (strcasecmp($st, 'Completed') === 0 || strcasecmp($st, 'W-Complete') === 0 || strcasecmp($st, 'ID_Complete') === 0) {
        $badge = '<span class="badge" style="background-color: #28a745; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">Completed</span>';
    } elseif ($st === 'Need to Assign') {
        $badge = '<span class="badge" style="background-color: #ffc107; color: black; padding: 4px 8px; border-radius: 4px; font-size: 11px;">Need to Assign</span>';
    } else {
        $label = (strcasecmp($st, 'In Progress') === 0 || strcasecmp($st, 'W-In process') === 0) ? 'In Progress' : h($st);
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

<div class="container-fluid">
    <!-- <div class="d-flex justify-content-between align-items-center mb-4"> -->
        <h2>TS Report</h2>
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
                <div class="label">TS Completed</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card card-progress">
                <div class="count"><?= $status_counts['In Progress'] ?></div>
                <div class="label">TS In Progress</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card card-pending">
                <div class="count"><?= $status_counts['Need to Assign'] ?></div>
                <div class="label">TS Need to Assign</div>
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
                        <option value="PAG+XML" <?=$filter_process==='PAG+XML'?'selected':''?>>TS+XML</option>
                        <option value="CE+PAG+XML" <?=$filter_process==='CE+PAG+XML'?'selected':''?>>CE+TS+XML</option>
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
                            <th>LE moved Date</th>
                            <th>CE moved Date</th>
                            <th>Received Date</th>
                            <th>Client</th>
                            <th>Journals</th>
                            <th>Articles</th>
                            <th>EQN</th>
                            <th>Pages</th>
                            <th>Images</th>
                            <th>Tables</th>
                            <th>Process</th>
                            <th>Due Date</th>
                            <th>FQA Status</th>
                            <th>LE Status</th>
                            <th>Word Process</th>
                            <th>Word Start</th>
                            <th>Word End</th>
                            <th>TS</th>
                            <th>TS Start</th>
                            <th>TS End</th>
                            <th>R-TAT</th>
                            <th>A-TAT</th>
                            <th>Path</th>
                            <th>Priority</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($all_jobs) > 0): 
                            foreach($all_jobs as $row): 
                        ?>
                        <tr>
                            <td><?= (in_array($row['le_status'], ['Completed', 'Done', 'LE & LE-QC Done', 'LE-QC Done', 'LE Done']) && !empty($row['le_moved_date'])) ? formatDateOnly(substr($row['le_moved_date'], 0, 10), 'd-m-Y') : '—' ?></td>
                            <td><?= !empty($row['ce_moved_date']) ? formatDateOnly(substr($row['ce_moved_date'], 0, 10), 'd-m-Y') : '—' ?></td>
                            <td><?= !empty($row['created_at']) ? formatDateOnly(substr($row['created_at'], 0, 10), 'd-m-Y') : '—' ?></td>
                            <td><?= h(!empty($row['team_client_name']) ? $row['team_client_name'] : $row['client_name']) ?></td>
                            <td><?= h($row['journal_issue']) ?></td>
                            <td><?= h($row['article']) ?></td>
                            <td><?= h($row['eq'] ?? '0') ?></td>
                            <td><?= h($row['pages'] ?? '0') ?></td>
                            <td><?= h($row['images'] ?? '0') ?></td>
                            <td><?= h($row['tables_count'] ?? '0') ?></td>
                            <td><span class="badge bg-secondary"><?= h($row['process']) ?></span></td>
                            <td><?= formatDateOnly($row['due_date'], 'd-m-Y') ?></td>
                            <td><span class="badge bg-info"><?= h($row['qc_status'] ?? 'Pending') ?></span></td>
                            <td><span class="badge bg-info"><?= h($row['le_status'] ?? 'Pending') ?></span></td>
                            
                            <!-- Word Process & Times -->
                            <td><?= getStatusBadge($row['word_status'] ?? 'Need to Assign', $row['assigned_to_img'] ?? '', $emp_map) ?></td>
                            <td><?= !empty($row['img_start_time']) ? date('H:i', strtotime($row['img_start_time'])) : '—' ?></td>
                            <td><?= !empty($row['img_end_time']) ? date('H:i', strtotime($row['img_end_time'])) : '—' ?></td>
                            
                            <!-- TS Process & Times -->
                            <td><?= getStatusBadge($row['pag_status_col'] ?? 'Need to Assign', $row['assigned_to_pag'] ?? '', $emp_map) ?></td>
                            <td><?= !empty($row['pag_start_time']) ? date('H:i', strtotime($row['pag_start_time'])) : '—' ?></td>
                            <td><?= !empty($row['pag_end_time']) ? date('H:i', strtotime($row['pag_end_time'])) : '—' ?></td>
                            
                            <!-- TAT, Path & Priority -->
                            <td class="fw-bold"><?= intval($row['rtat'] ?? 0) ?></td>
                            <td class="fw-bold"><?= intval($row['atat'] ?? 0) ?></td>
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