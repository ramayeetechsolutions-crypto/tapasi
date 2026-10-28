<br><br><br>
<?php
// Add these helper functions at the top
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
?>
<?php
require 'db.php';
session_start();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

$current_user = $_SESSION['username'] ?? '';
$uid = $_SESSION['uid'] ?? '';
if (!$current_user) {
    echo "<div class='alert alert-danger'>Session expired. Please log in again.</div>";
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
];

// WHERE conditions
$where = [];
$params = [];
$types  = '';

// 1. Fetch employee processes (based on logged in user)
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
    $pname = strtoupper($row['process_name']);

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
// Initialize arrays
$where = [];
$params = [];
$types = '';

// 1. MANDATORY FILTER: Assignee
// Ensure we use the same array name ($where) used for other filters
$where[] = "j.assigned_to = ?";
$params[] = "R.Shanthi";
$types .= 's';

// 2. Remove duplicates and apply process filter
$allowed_process_ids = array_unique($allowed_process_ids);
if (!empty($allowed_process_ids)) {
    // Sanitize to ensure only integers are passed in the IN clause
    $clean_ids = array_map('intval', $allowed_process_ids);
    $where[] = "j.process_id IN (" . implode(',', $clean_ids) . ")";
}

// 3. Search query filter
if ($q) {
    $where[] = "(c.client_name LIKE ? OR j.journal_issue LIKE ? OR j.article LIKE ?)";
    $params = array_merge($params, ["%$q%", "%$q%", "%$q%"]);
    $types .= "sss";
}

// ... (Rest of your filter logic: $filter_client, $filter_process, $from_date, $to_date) ...
// Ensure they all use $where[]

// 4. Build WHERE SQL
$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Main query - updated to include dispatch_date from jobs and data from job_uploads
$sql = "SELECT 
    j.job_id, j.job_date, c.client_name, j.journal_issue, j.article,
    jm.job_name AS process, j.dispatch_date,j.file_path as jfile_path,
ce.eq as ce_eq,ce.ref as ce_ref,ce.images as ce_images,ce.tables_count as ce_tables,
    ce.ce_date, ce.done_by AS ce_done_by, ce.status AS ce_status,
    le.le_date, le.done_by AS le_done_by, le.status AS le_status,
    pag.pag_date, pag.done_by AS pag_done_by, pag.status AS pag_status,
    qc.qc_date, qc.done_by AS qc_done_by, qc.status AS qc_status,
    pag.pages, pag.images, pag.tables_count, j.due_date, qc.rtat, qc.atat,
    
    -- From job_uploads table
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
            
            if ($stage_status !== 'Completed') {
                $all_completed = false;
                
                if ($stage_status === 'In Progress') {
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
</style>
<?php require 'header.php';?>

<div class="container-fluid">
    <h2>Job Management</h2>

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
                    <input type="text" name="q" placeholder="Search client / issue / article" value="<?=h($q)?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <input type="text" name="client" placeholder="Client" value="<?=h($filter_client)?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <select name="process" class="form-select form-select-sm">
                        <option value="">All Processes</option>
                        <option value="PAG" <?=$filter_process==='PAG'?'selected':''?>>PAG</option>
                        <option value="CE+PAG" <?=$filter_process==='CE+PAG'?'selected':''?>>CE+PAG</option>
                        <option value="CE" <?=$filter_process==='CE'?'selected':''?>>CE</option>
                        <option value="LE" <?=$filter_process==='LE'?'selected':''?>>LE</option>
                        <option value="QC" <?=$filter_process==='QC'?'selected':''?>>QC</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" name="from_date" value="<?= h($from_date) ?>" class="form-control form-control-sm" placeholder="From Date">
                </div>
                <div class="col-md-2">
                    <input type="date" name="to_date" value="<?= h($to_date) ?>" class="form-control form-control-sm" placeholder="To Date">
                </div>
                <div class="col-md-1">
                    <select name="sort" class="form-select form-select-sm">
                        <option value="j.job_date" <?=$sort==='j.job_date'?'selected':''?>>Date</option>
                        <option value="client_name" <?=$sort==='client_name'?'selected':''?>>Client</option>
                        <option value="journal_issue" <?=$sort==='journal_issue'?'selected':''?>>Issue</option>
                        <option value="process" <?=$sort==='process'?'selected':''?>>Process</option>
                        <option value="qc.dispatch_date" <?=$sort==='qc.dispatch_date'?'selected':''?>>Dispatch Date</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <select name="dir" class="form-select form-select-sm">
                        <option value="DESC" <?=$dir==='DESC'?'selected':''?>>Desc</option>
                        <option value="ASC" <?=$dir==='ASC'?'selected':''?>>Asc</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary btn-sm" type="submit">
                        <i class="fas fa-search"></i> Search
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
                            <th>#</th><th>Date</th><th>Client</th><th>Issue</th><th>Article</th><th>Process</th>
                            <th>CE Status</th><th>CE EQN</th><th>CE REF</th><th>CE Images</th><th>CE Tables</th><th>CE Date</th><th>CE By</th>
                            <th>LE Status</th><th>LE Date</th><th>LE By</th>
                            <th>PAG Status</th><th>PAG Date</th><th>PAG By</th>
                            <th>QC Status</th><th>QC Date</th><th>QC By</th>
                            <th>Pages</th><th>Images</th><th>Tables</th>
                            <th>Due Date</th><th>R-TAT</th><th>A-TAT</th>
                            <th>Dispatch Date</th><th>Upload Date</th><th>Upload By</th><th>File Path</th>
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
                            <td><?=$i++?></td>
                            <td><?= formatDateTime($row['job_date'], 'd-m-Y') ?></td>
                            <td><?=h($row['client_name'])?></td>
                            <td><?=h($row['journal_issue'])?></td>
                            <td><?=h($row['article'])?></td>
                            <td><?=h($row['process'])?></td>

                            <!-- CE Status, Date, By -->
                            <td>
                                <?php if (in_array('CE', $required_stages)): ?>
                                    <span class="badge <?= $row['ce_status'] === 'Completed' ? 'bg-success' : ($row['ce_status'] === 'In Progress' ? 'bg-warning' : 'bg-secondary') ?>">
                                        <?= h($row['ce_status'] ?? 'Need to Assign') ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?=$row['ce_eq']?></td>
                            <td><?=$row['ce_ref']?></td>
                            <td><?=$row['ce_images']?></td>
                            <td><?=$row['ce_tables']?></td>

                            <td><?= formatDateTime($row['ce_date'], 'd-m-Y H:i') ?></td>
                            <td><?=h($row['ce_done_by']??'—')?></td>

                            <!-- LE Status, Date, By -->
                            <td>
                                <?php if (in_array('LE', $required_stages)): ?>
                                    <span class="badge <?= $row['le_status'] === 'Completed' ? 'bg-success' : ($row['le_status'] === 'In Progress' ? 'bg-warning' : 'bg-secondary') ?>">
                                        <?= h($row['le_status'] ?? 'Need to Assign') ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?= formatDateTime($row['le_date'], 'd-m-Y H:i') ?></td>
                            <td><?=h($row['le_done_by']??'—')?></td>

                            <!-- PAG Status, Date, By -->
                            <td>
                                <?php if (in_array('PAG', $required_stages)): ?>
                                    <span class="badge <?= $row['pag_status'] === 'Completed' ? 'bg-success' : ($row['pag_status'] === 'In Progress' ? 'bg-warning' : 'bg-secondary') ?>">
                                        <?= h($row['pag_status'] ?? 'Need to Assign') ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?= formatDateTime($row['pag_date'], 'd-m-Y H:i') ?></td>
                            <td><?=h($row['pag_done_by']??'—')?></td>

                            <!-- QC Status, Date, By -->
                            <td>
                                <?php if (in_array('QC', $required_stages)): ?>
                                    <span class="badge <?= $row['qc_status'] === 'Completed' ? 'bg-success' : ($row['qc_status'] === 'In Progress' ? 'bg-warning' : 'bg-secondary') ?>">
                                        <?= h($row['qc_status'] ?? 'Need to Assign') ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?= formatDateTime($row['qc_date'], 'd-m-Y H:i') ?></td>
                            <td><?=h($row['qc_done_by']??'—')?></td>

                            <td><?=$row['pages']?></td>
                            <td><?=$row['images']?></td>
                            <td><?=$row['tables_count']?></td>
                            <td><?= formatDateOnly($row['due_date'], 'd-m-Y') ?></td>
                            <td><?=intval($row['rtat'])?></td>
                            <td><?=intval($row['atat'])?></td>

                            <!-- Dispatch Date from jobs table -->
                            <td>
                                <?php if (!empty($row['dispatch_date']) && $row['dispatch_date'] != '0000-00-00 00:00:00' && $row['dispatch_date'] != '30-11--0001 00:00'): ?>
                                    <span class="badge bg-success">
                                        <?= formatDateTime($row['dispatch_date'], 'd-m-Y H:i') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Not Yet Dispatched</span>
                                <?php endif; ?>
                            </td>

                            <!-- Upload information from job_uploads table -->
                            <td><?= formatDateTime($row['upload_date'], 'd-m-Y H:i') ?></td>
                            <td><?= h($row['upload_by'] ?? '—') ?></td>
                            <td>
                                <?php if (!empty($row['file_path'])): ?>
                                    <a href="<?= h($row['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-download"></i> <?= basename($row['file_path']) ?>
                                    </a>
                                    <!-- Recommended Option -->
                                    <div class="small mt-1 text-center">
                                        <span class="text-white bg-danger p-1 rounded d-inline-block" style="max-width: 200px; overflow: hidden; text-overflow: ellipsis;">
                                            <?= "TPS Server Path: " . $row['jfile_path'] ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <!-- Final Status -->
                            <td>
                                <span class="<?= $status_class ?>">
                                    <?= h($final_status) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="32" class="text-center">No jobs found.</td></tr>
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