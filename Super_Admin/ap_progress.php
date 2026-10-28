<br><br><br>
<?php
// ap_progress.php - Display ALL AP Records
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Get employees for reference
$employees_query = $mysqli->query("SELECT employee_username, employee_name FROM employee_master WHERE status = 'Active' ORDER BY employee_name");
$employees = [];
if ($employees_query) {
    while ($row = $employees_query->fetch_assoc()) {
        $employees[$row['employee_username']] = $row['employee_name'];
    }
}

// Fetch ALL AP details
$ap_query = $mysqli->query("SELECT * FROM ap_details ORDER BY id DESC");
$all_ap_records = [];
if ($ap_query) {
    $all_ap_records = $ap_query->fetch_all(MYSQLI_ASSOC);
}

// Fetch ALL progress records
$progress_query = $mysqli->query("
    SELECT ap.* 
    FROM ap_progress ap 
    ORDER BY ap.id ASC
");
$progress_records = [];
if ($progress_query) {
    $progress_records = $progress_query->fetch_all(MYSQLI_ASSOC);
}

// Group progress records by AP ID
$progress_by_ap = [];
foreach ($progress_records as $progress) {
    $ap_id = $progress['ap_id'];
    if (!isset($progress_by_ap[$ap_id])) {
        $progress_by_ap[$ap_id] = [];
    }
    $progress_by_ap[$ap_id][] = $progress;
}

// Calculate statistics for ALL APs
$total_articles = count($progress_records);
$completed_articles = 0;
$total_tat = 0;
$qc_passed = 0;
$qc_failed = 0;

foreach ($progress_records as $progress) {
    if ($progress['status'] == 'Completed') {
        $completed_articles++;
        $total_tat += $progress['tat_minutes'];
    }
    if ($progress['qc_status'] == 'Passed') {
        $qc_passed++;
    } elseif ($progress['qc_status'] == 'Failed') {
        $qc_failed++;
    }
}

$completion_rate = $total_articles > 0 ? round(($completed_articles / $total_articles) * 100, 2) : 0;
$avg_tat = $completed_articles > 0 ? round($total_tat / $completed_articles, 2) : 0;
$qc_pass_rate = $total_articles > 0 ? round(($qc_passed / $total_articles) * 100, 2) : 0;

include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All AP Progress Tracking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .card { box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); }
        .table th { background-color: #2c3e50; color: white; }
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 0.85em; font-weight: 500; }
        .status-Pending { background-color: #fff3cd; color: #856404; }
        .status-In-Progress { background-color: #d1ecf1; color: #0c5460; }
        .status-Completed { background-color: #d4edda; color: #155724; }
        .status-On-Hold { background-color: #f8d7da; color: #721c24; }
        .qc-badge { padding: 4px 8px; border-radius: 4px; font-size: 0.85em; font-weight: 500; }
        .qc-Pending { background-color: #e2e3e5; color: #383d41; }
        .qc-Passed { background-color: #d4edda; color: #155724; }
        .qc-Failed { background-color: #f8d7da; color: #721c24; }
        .qc-Revised { background-color: #fff3cd; color: #856404; }
        .complexity-badge { font-size: 0.8em; padding: 3px 6px; }
        .complexity-AP_CRX { background-color: #0dcaf0; color: white; }
        .complexity-PAG_QC { background-color: #20c997; color: white; }
        .complexity-AP_QC { background-color: #ffc107; color: black; }
        .complexity-CE_REF { background-color: #6f42c1; color: white; }
        /* .ap-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .stat-card { border-radius: 10px; padding: 15px; text-align: center; color: white; margin-bottom: 15px; }
        .stat-total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); } */
        /* 1. Change main top banner to Grey and text to White */
.ap-header { background-color: #707981 !important; color: white !important; } 

.stat-card { border-radius: 10px; padding: 15px; text-align: center; color: white; margin-bottom: 15px; }

/* 2. Change first statistics card to Grey */
 /* .stat-total { background-color: #707981 !important; } */
 .stat-total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important; }

/* 3. Forces ALL card headers (Black and Sky Blue) to Grey with White text */
.card-header {
    background-color: #707981 !important;
    color: white !important;
}

/* 4. Ensures titles (h5) and icons (i) inside headers are also White */
.card-header h5, .card-header i {
    color: white !important;
}
        .stat-completed { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-qc { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-tat { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .employee-badge { background-color: #6c757d; color: white; font-size: 0.8em; padding: 2px 6px; }
        .location-badge { background-color: #17a2b8; color: white; font-size: 0.8em; padding: 2px 6px; }
        .view-only-badge { background-color: #dc3545; color: white; padding: 5px 10px; border-radius: 20px; font-size: 0.8em; }
        .ap-summary-card { border-left: 5px solid #007bff; }
        .ap-summary-completed { border-left: 5px solid #28a745; }
        .ap-summary-progress { border-left: 5px solid #ffc107; }
        .ap-summary-pending { border-left: 5px solid #6c757d; }
        .progress-count { font-size: 1.2rem; font-weight: bold; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="container-fluid mt-4">
        <!-- Main Header Card -->
        <div class="card mb-4 ap-header border-0">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-1"><i class="fas fa-file-alt me-2"></i>All AP Progress Tracking</h4>
                        <h5 class="mb-0">Complete AP Management Dashboard</h5>
                        <p class="mb-0 small opacity-75">
                            Showing <?= count($all_ap_records) ?> AP records with <?= $total_articles ?> total articles
                        </p>
                    </div>
                    <div class="d-flex align-items-center">
                        <span class="view-only-badge me-3">
                            <i class="fas fa-eye me-1"></i> View Only Mode
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card stat-total">
                    <i class="fas fa-folder fa-2x mb-2"></i>
                    <h4><?= count($all_ap_records) ?></h4>
                    <p>Total APs</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-completed">
                    <i class="fas fa-file-alt fa-2x mb-2"></i>
                    <h4><?= $total_articles ?></h4>
                    <p>Total Articles</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-qc">
                    <i class="fas fa-check-circle fa-2x mb-2"></i>
                    <h4><?= $completed_articles ?></h4>
                    <p>Completed Articles</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-tat">
                    <i class="fas fa-clock fa-2x mb-2"></i>
                    <h4><?= $qc_pass_rate ?>%</h4>
                    <p>QC Pass Rate</p>
                </div>
            </div>
        </div>

        <!-- AP List Summary -->
        <div class="card mb-4">
            <!-- <div class="card-header bg-secondary text-white"> -->
                <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>AP Summary List (<?= count($all_ap_records) ?> APs)</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php if (empty($all_ap_records)): ?>
                        <div class="col-12 text-center py-4 text-muted">
                            <i class="fas fa-folder-open fa-3x mb-3"></i>
                            <h5>No AP records found</h5>
                            <p>No AP details have been created yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($all_ap_records as $ap): ?>
                            <?php
                            // Get progress records for this AP
                            $ap_progress = isset($progress_by_ap[$ap['id']]) ? $progress_by_ap[$ap['id']] : [];
                            $ap_article_count = count($ap_progress);
                            $ap_completed = 0;
                            foreach ($ap_progress as $progress) {
                                if ($progress['status'] == 'Completed') {
                                    $ap_completed++;
                                }
                            }
                            $ap_completion_rate = $ap_article_count > 0 ? round(($ap_completed / $ap_article_count) * 100, 2) : 0;
                            
                            // Determine card class based on status
                            $card_class = 'ap-summary-card';
                            if ($ap['status'] == 'Completed') {
                                $card_class = 'ap-summary-completed';
                            } elseif ($ap['status'] == 'In Progress') {
                                $card_class = 'ap-summary-progress';
                            } elseif ($ap['status'] == 'Pending') {
                                $card_class = 'ap-summary-pending';
                            }
                            ?>
                            <div class="col-md-4 mb-3">
                                <div class="card h-100 <?= $card_class ?>">
                                    <div class="card-body">
                                        <h6 class="card-title">
                                            AP #<?= $ap['id'] ?>: <?= htmlspecialchars($ap['client_name']) ?>
                                        </h6>
                                        <p class="card-text small mb-2">
                                            <i class="fas fa-newspaper me-1"></i> 
                                            Issue: <?= htmlspecialchars($ap['issue']) ?>
                                        </p>
                                        <p class="card-text small mb-2">
                                            <i class="fas fa-calendar-day me-1"></i>
                                            Due: <?= date('d-M-Y', strtotime($ap['due_date'])) ?>
                                        </p>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="badge bg-<?= $ap['priority'] == 'High' ? 'danger' : ($ap['priority'] == 'Medium' ? 'warning' : 'secondary') ?>">
                                                <?= $ap['priority'] ?>
                                            </span>
                                            <span class="status-badge status-<?= str_replace(' ', '-', $ap['status']) ?>">
                                                <?= $ap['status'] ?>
                                            </span>
                                        </div>
                                        <div class="progress mb-2" style="height: 6px;">
                                            <div class="progress-bar bg-success" role="progressbar" 
                                                 style="width: <?= $ap_completion_rate ?>%" 
                                                 aria-valuenow="<?= $ap_completion_rate ?>" 
                                                 aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                        <div class="d-flex justify-content-between small text-muted">
                                            <span><?= $ap_article_count ?> articles</span>
                                            <span><?= $ap_completion_rate ?>% complete</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- All Progress Records Table -->
        <div class="card">
            <!-- <div class="card-header bg-dark text-white"> -->
                <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-tasks me-2"></i>All Article Progress Details (<?= $total_articles ?> Articles)</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                            <tr>
                                <th>S.No</th>
                                <th>AP ID</th>
                                <th>Client</th>
                                <th>Article</th>
                                <th>Complexity</th>
                                <th>Employee</th>
                                <th>Location</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>TAT</th>
                                <th>Dispatch</th>
                                <th>Status</th>
                                <th>QC Status</th>
                                <th>QC By</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($progress_records)): ?>
                                <tr>
                                    <td colspan="15" class="text-center py-4 text-muted">
                                        <i class="fas fa-tasks fa-3x mb-3"></i>
                                        <h5>No progress records found</h5>
                                        <p>No article progress has been tracked yet</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $counter = 1; ?>
                                <?php foreach ($progress_records as $progress): ?>
                                    <?php
                                    // Get AP details for this progress record
                                    $ap_info = null;
                                    foreach ($all_ap_records as $ap) {
                                        if ($ap['id'] == $progress['ap_id']) {
                                            $ap_info = $ap;
                                            break;
                                        }
                                    }
                                    
                                    // Format TAT
                                    $tat_hours = floor($progress['tat_minutes'] / 60);
                                    $tat_min = $progress['tat_minutes'] % 60;
                                    $tat_display = $progress['tat_minutes'] > 0 ? 
                                        sprintf("%dh %02dm", $tat_hours, $tat_min) : '-';
                                    
                                    // Format times
                                    $start_time_display = $progress['start_time'] ? 
                                        date('d-M-Y H:i', strtotime($progress['start_time'])) : '-';
                                    $end_time_display = $progress['end_time'] ? 
                                        date('d-M-Y H:i', strtotime($progress['end_time'])) : '-';
                                    
                                    // Get employee name
                                    $employee_name = '';
                                    if ($progress['employee_username'] && isset($employees[$progress['employee_username']])) {
                                        $employee_name = $employees[$progress['employee_username']];
                                    }
                                    
                                    // Check dispatch vs due date
                                    $dispatch_info = '';
                                    if ($progress['dispatch_date'] && $ap_info) {
                                        $dispatch_date = new DateTime($progress['dispatch_date']);
                                        $due_date = new DateTime($ap_info['due_date']);
                                        $interval = $due_date->diff($dispatch_date);
                                        $days_diff = $interval->days * ($interval->invert ? -1 : 1);
                                        
                                        if ($days_diff < 0) {
                                            $dispatch_info = '<span class="badge bg-success ms-1">' . abs($days_diff) . 'd early</span>';
                                        } elseif ($days_diff > 0) {
                                            $dispatch_info = '<span class="badge bg-danger ms-1">+' . $days_diff . 'd late</span>';
                                        } else {
                                            $dispatch_info = '<span class="badge bg-info ms-1">On time</span>';
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <td class="fw-bold"><?= $counter++ ?></td>
                                        <td>
                                            <span class="badge bg-primary">
                                                AP #<?= $progress['ap_id'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($ap_info): ?>
                                                <small><?= htmlspecialchars($ap_info['client_name']) ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">Unknown</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?= htmlspecialchars($progress['article_name']) ?></strong></td>
                                        <td>
                                            <span class="badge complexity-badge complexity-<?= str_replace('/', '_', $progress['complexity']) ?>">
                                                <?= $progress['complexity'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($employee_name): ?>
                                                <span class="employee-badge">
                                                    <?= htmlspecialchars($employee_name) ?>
                                                </span>
                                            <?php elseif ($progress['employee_username']): ?>
                                                <span class="employee-badge">
                                                    <?= htmlspecialchars($progress['employee_username']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($progress['location']): ?>
                                                <span class="location-badge"><?= htmlspecialchars($progress['location']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small"><?= $start_time_display ?></td>
                                        <td class="small"><?= $end_time_display ?></td>
                                        <td><span class="badge bg-secondary"><?= $tat_display ?></span></td>
                                        <td>
                                            <?php if ($progress['dispatch_date']): ?>
                                                <?= date('d-M-Y', strtotime($progress['dispatch_date'])) ?>
                                                <?= $dispatch_info ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= str_replace(' ', '-', $progress['status']) ?>">
                                                <?= $progress['status'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="qc-badge qc-<?= $progress['qc_status'] ?>">
                                                <?= $progress['qc_status'] ?>
                                            </span>
                                        </td>
                                        <td class="small">
                                            <?= htmlspecialchars($progress['qc_checked_by'] ?: '-') ?>
                                        </td>
                                        <td class="small">
                                            <div class="mb-1">
                                                <?= htmlspecialchars($progress['remarks'] ?: '-') ?>
                                            </div>
                                            <?php if ($progress['qc_remarks']): ?>
                                                <div>
                                                    <small class="text-danger">QC: <?= htmlspecialchars($progress['qc_remarks']) ?></small>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Summary Statistics -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <!-- <div class="card-header bg-info text-white"> -->
                        <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Summary Statistics</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 text-center">
                                <div class="display-4 text-primary"><?= count($all_ap_records) ?></div>
                                <div class="text-muted">Total APs</div>
                            </div>
                            <div class="col-md-3 text-center">
                                <div class="display-4 text-success"><?= $total_articles ?></div>
                                <div class="text-muted">Total Articles</div>
                            </div>
                            <div class="col-md-3 text-center">
                                <div class="display-4 text-warning"><?= $completion_rate ?>%</div>
                                <div class="text-muted">Overall Completion Rate</div>
                            </div>
                            <div class="col-md-3 text-center">
                                <div class="display-4 text-danger"><?= $avg_tat ?> min</div>
                                <div class="text-muted">Average TAT</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>