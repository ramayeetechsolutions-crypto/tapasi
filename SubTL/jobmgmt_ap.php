<?php
// Helper functions
function formatDateTime($datetime, $format = 'd-m-Y H:i') {
    if (empty($datetime) || $datetime == '0000-00-00 00:00:00' || $datetime == '0000-00-00') return '—';
    $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    if (!$dateObj) $dateObj = DateTime::createFromFormat('Y-m-d', $datetime);
    return $dateObj ? $dateObj->format($format) : '—';
}

function formatDateOnly($date, $format = 'd-m-Y') {
    if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '—';
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObj) $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $date);
    return $dateObj ? $dateObj->format($format) : '—';
}

require 'db.php';
session_start();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

$current_user = $_SESSION['username'] ?? '';
if (!$current_user) {
    echo "<div class='alert alert-danger'>Session expired. Please log in again.</div>";
    exit;
}

// Filters
$q = trim($_GET['q'] ?? '');
$filter_client = trim($_GET['client'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';

// Build WHERE conditions
$where = ["1=1"];
$params = [];
$types  = '';

if ($q) {
    $where[] = "(client LIKE ? OR mail_subject LIKE ? OR articles LIKE ?)";
    $params = array_merge($params, ["%$q%", "%$q%", "%$q%"]);
    $types .= "sss";
}

if ($filter_client) {
    $where[] = "client LIKE ?";
    $params[] = "%$filter_client%";
    $types .= "s";
}

if ($filter_status) {
    $where[] = "status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

if ($from_date) {
    $where[] = "date >= ?";
    $params[] = $from_date;
    $types .= "s";
}
if ($to_date) {
    $where[] = "date <= ?";
    $params[] = $to_date;
    $types .= "s";
}

$where_sql = "WHERE " . implode(" AND ", $where);

// SAFE QUERY for AP Report
$sql = "SELECT *, (SELECT word_count FROM jobs WHERE jobs.job_id = ap_assign.job_id) as article_count FROM ap_assign $where_sql ORDER BY date DESC, id DESC";
$stmt = $mysqli->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$results = $stmt->get_result();

$status_counts = [
    'Need to Assign' => 0,
    'In Progress' => 0,
    'Completed' => 0,
    'Total' => 0
];

$all_jobs = [];
if ($results->num_rows > 0) {
    while($row = $results->fetch_assoc()) {
        $final_status = $row['status'] ?? 'In Process';
        
        if ($row['process_status'] === 'Need to Assign') {
            $status_counts['Need to Assign']++;
        } elseif ($final_status === 'Completed') {
            $status_counts['Completed']++;
        } else {
            $status_counts['In Progress']++;
        }
        
        $status_counts['Total']++;
        $all_jobs[] = $row;
    }
}
?>

<?php require 'header.php';?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"/>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css"/>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
        /* Ash Page Header Banner (Matching Navbar Color) */
        .page-title-container {
            background: var(--ash, #6c757d);
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title-container h1,
        .page-title-container h2,
        .page-title-container h3,
        .page-title-container h4,
        .page-title-container h5,
        .page-title-container h6 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title-container p {
            color: rgba(255, 255, 255, 0.85);
            margin: 0;
        }

        .page-title-container i {
            color: white;
        }

        /* Grid Table Styles - Matching xml/xml_unique_start.php */
        .table th,
        .table thead th {
            background-color: #5e6b77 !important; 
            color: #ffffff !important;
            border: 1px solid #dee2e6 !important;
            font-weight: 600;
            font-size: 12.5px;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            padding: 8px 10px;
            white-space: nowrap;
            text-align: center;
            vertical-align: middle;
        }
        
        .table td,
        .table tbody td {
            vertical-align: middle;
            padding: 6px 10px;
            font-size: 12.5px;
            color: #334155;
            border: 1px solid #dee2e6 !important;
            text-align: center;
            white-space: nowrap;
        }
        
        /* Visible Zebra Striping matching xml/xml_unique_start.php */
        .table tbody tr:nth-child(even) td {
            background-color: #f2f4f6 !important;
        }
        
        .table tbody tr:nth-child(odd) td {
            background-color: #ffffff !important;
        }
        
        /* Full Row Hover Effect matching xml/xml_unique_start.php */
        .table tbody tr:hover td {
            background-color: #c4cbd3 !important;
            cursor: pointer;
            transition: background-color 0.1s ease;
        }
        
        .table tbody tr:hover td.sticky-assign-col,
        .table tbody tr:hover td.sticky-assign-cell {
            background-color: #e9ecef !important;
        }
table td { vertical-align: middle; padding: 6px 8px; font-size: 0.85rem; }
.dashboard-card { border-radius: 10px; padding: 15px; margin-bottom: 15px; color: white; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
.card-total { background: linear-gradient(45deg, #667eea, #764ba2); }
.card-completed { background: linear-gradient(45deg, #11998e, #38ef7d); }
.card-progress { background: linear-gradient(45deg, #ff8c00, #ff2d00); }
.card-pending { background: linear-gradient(45deg, #0d6efd, #0b5ed7); }
.dashboard-card .count { font-size: 2rem; font-weight: bold; margin-bottom: 5px; }
.dashboard-card .label { font-size: 0.9rem; opacity: 0.9; }
</style>

<?php
function getStatusBadge($status) {
    $st = trim((string)$status);
    if (empty($st) || $st === 'Need to Assign') {
        return '<span class="badge" style="background-color: #0d6efd !important; color: white !important; padding: 2px 6px !important; border-radius: 4px !important; font-size: 10px !important; font-weight: 600 !important;">Need to Assign</span>';
    }
    if (strcasecmp($st, 'Completed') === 0 || strcasecmp($st, 'Done') === 0) {
        return '<span class="badge" style="background-color: #28a745 !important; color: white !important; padding: 2px 6px !important; border-radius: 4px !important; font-size: 10px !important; font-weight: 600 !important;">Completed</span>';
    }
    if (strcasecmp($st, 'Pending') === 0) {
        return '<span class="badge" style="background-color: #ffc107 !important; color: #212529 !important; padding: 2px 6px !important; border-radius: 4px !important; font-size: 10px !important; font-weight: 600 !important;">Pending</span>';
    }
    $label = (strcasecmp($st, 'WIP') === 0 || strcasecmp($st, 'Inprogress') === 0) ? 'In Progress' : h($st);
    return '<span class="badge" style="background-color: #fd7e14 !important; color: white !important; padding: 2px 6px !important; border-radius: 4px !important; font-size: 10px !important; font-weight: 600 !important;">' . $label . '</span>';
}
?>

<div class="container-fluid" style="padding-top: 0 !important; margin-top: 0 !important;">
    <div class="page-title-container" style="background-color: #6c757d !important; background: #6c757d !important; color: #ffffff !important; padding: 12px 20px !important; border-radius: 12px !important; margin-top: 0 !important; margin-bottom: 20px !important; display: flex !important; justify-content: space-between !important; align-items: center !important;">
        <h2 class="mb-0 text-white" style="color: #ffffff !important; font-size: 1.25rem !important; margin: 0 !important;">
            <i class="fas fa-file-alt me-2" style="color: #ffffff !important;"></i>AP Report
        </h2>
    </div>

    <!-- Dashboard Cards -->
    <div class="row mb-4">
        <div class="col-md-3"><div class="dashboard-card card-total"><div class="count"><?= $status_counts['Total'] ?></div><div class="label">Total AP Jobs</div></div></div>
        <div class="col-md-3"><div class="dashboard-card card-completed"><div class="count"><?= $status_counts['Completed'] ?></div><div class="label">AP Completed</div></div></div>
        <div class="col-md-3"><div class="dashboard-card card-progress"><div class="count"><?= $status_counts['In Progress'] ?></div><div class="label">AP In Progress</div></div></div>
        <div class="col-md-3"><div class="dashboard-card card-pending"><div class="count"><?= $status_counts['Need to Assign'] ?></div><div class="label">AP Need to Assign</div></div></div>
    </div>

    <!-- Filter Form -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row gy-2 gx-3 align-items-center">
                <div class="col-md-2">
                    <label class="form-label small mb-1">Search</label>
                    <input type="text" name="q" placeholder="Client/Mail Subject/Article" value="<?=h($q)?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Client</label>
                    <input type="text" name="client" placeholder="Client Name" value="<?=h($filter_client)?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="Completed" <?= $filter_status==='Completed'?'selected':'' ?>>Completed</option>
                        <option value="In Process" <?= $filter_status==='In Process'?'selected':'' ?>>In Progress</option>
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
                    <label class="form-label small mb-1 d-block">&nbsp;</label>
                    <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-search"></i></button>
                    <a href="jobmgmt_ap.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height:650px; overflow:auto;">
                <table class="table table-striped table-bordered align-middle table-sticky-assign" style="min-width: 1800px; border-collapse: separate; border-spacing: 0;">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Date</th>
                            <th>Client</th>
                            <th>Mail Subject</th>
                            <th>Process</th>
                            <th class="col-article" style="width: 135px; min-width: 135px; max-width: 135px;">Articles</th>
                            <th>Article Count</th>
                            <th>Supplements</th>
                            <th>Process TM</th>
                            <th>Suppl. TM</th>
                            <th>Note</th>
                            <th>Path</th>
                            <th>Remarks</th>
                            <th>Job Status</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Total Hrs</th>
                            <th>Dispatch Date</th>
                            <th>TAT (Days)</th>
                        </tr>
                    </thead>
                    <tbody>
                       <?php 
                       // Fetch employee names to display Name + ID
                       $emp_map = [];
                       $emp_query = $mysqli->query("SELECT employee_username, employee_name FROM employee_master");
                       if ($emp_query) {
                           while ($e = $emp_query->fetch_assoc()) {
                               $emp_map[$e['employee_username']] = $e['employee_name'];
                           }
                       }
                       
                       if (count($all_jobs) > 0): $i=1; foreach($all_jobs as $row): 
                           $p_mem = $row['process_member'];
                           $p_display = (!empty($p_mem) && isset($emp_map[$p_mem])) ? $emp_map[$p_mem] . ' (' . $p_mem . ')' : $p_mem;
                           
                           $s_mem = $row['supplements_member'];
                           $s_display = (!empty($s_mem) && isset($emp_map[$s_mem])) ? $emp_map[$s_mem] . ' (' . $s_mem . ')' : $s_mem;
                       ?>
                        <tr>
                            <td><?=$i++?></td>
                            <td><?= formatDateOnly($row['date'], 'd-m-Y') ?></td>
                            <td><?=h($row['client'])?></td>
                            <td><?=h($row['mail_subject'])?></td>
                            <td><span class="badge bg-secondary"><?=h($row['process'])?></span></td>
                            <td class="col-article" style="width: 135px; min-width: 135px; max-width: 135px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= h($row['articles'] ?? '') ?>"><?=h($row['articles'])?></td>
                            <td class="text-center"><?= !empty($row['article_count']) ? h($row['article_count']) : '0' ?></td>
                            <td><?=h($row['supplements'])?></td>
                            
                            <!-- Process TM -->
                            <td>
                                <?= getStatusBadge($row['process_status']) ?><br>
                                <small class="text-success fw-bold"><?=h($p_display)?></small>
                            </td>

                            <!-- Suppl TM -->
                            <td>
                                <?= getStatusBadge($row['supplements_status']) ?><br>
                                <small class="text-success fw-bold"><?=h($s_display)?></small>
                            </td>

                            <td><?=h($row['note'] ?? '—')?></td>
                            <td><?=h($row['path'] ?? '—')?></td>
                            <td>
                                <?php if (!empty($row['remarks'])): ?>
                                    <?php if ($row['remarks'] === 'Completed'): ?>
                                        <span class="badge bg-success"><?= h($row['remarks']) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><?= h($row['remarks']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            
                            <td>
                                <?php 
                                    if ($row['status'] === 'Completed') echo '<span class="badge bg-success">Completed</span>';
                                    else if ($row['status'] === 'Hold') echo '<span class="badge bg-danger">Hold</span>';
                                    else echo '<span class="badge bg-info">In Process</span>';
                                ?>
                            </td>

                            <td><?= !empty($row['start_time']) ? date('H:i', strtotime($row['start_time'])) : '—' ?></td>
                            <td><?= !empty($row['end_time']) ? date('H:i', strtotime($row['end_time'])) : '—' ?></td>
                            
                            <td><span class="fw-bold"><?= !empty($row['total_hrs']) ? h($row['total_hrs']) : '—' ?></span></td>

                            <td><?= formatDateOnly($row['dispatch_date'], 'd-m-Y') ?></td>
                            <?php
                                $live_tat = 0;
                                if (!empty($row['date']) && $row['date'] !== '0000-00-00') {
                                    $start = new DateTime($row['date']);
                                    $end = (!empty($row['dispatch_date']) && $row['dispatch_date'] !== '0000-00-00') ? new DateTime($row['dispatch_date']) : new DateTime();
                                    $live_tat = $start->diff($end)->format('%a');
                                }
                            ?>
                            <td><span class="badge bg-dark"><?= $live_tat ?> Days</span></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="19" class="text-center">No AP jobs found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>