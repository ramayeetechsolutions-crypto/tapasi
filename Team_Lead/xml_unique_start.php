<br><br><br>
<?php
require 'db.php';
session_start();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

function formatDateTime($datetime, $format = 'd-m-Y H:i') {
    if (empty($datetime) || $datetime == '0000-00-00 00:00:00' || $datetime == '0000-00-00') {
        return '—';
    }
    $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    if (!$dateObj) {
        $dateObj = DateTime::createFromFormat('Y-m-d', $datetime);
    }
    return $dateObj ? $dateObj->format($format) : '—';
}

$current_user = $_SESSION['username'] ?? '';
$role_name = $_SESSION['role_name'] ?? '';


// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_tracking') {
        $job_id = (int)$_POST['job_id'];
        $rec_date = $_POST['rec_date'];
        $journal_name_client = $_POST['journal_name_client'];
        $issue = $_POST['issue'];
        $articles = $_POST['articles'];
        $xml_status = $_POST['xml_status'];
        $scielo_ebsco_status = $_POST['scielo_ebsco_status'];
        $pubmed_biblat_status = $_POST['pubmed_biblat_status'];
        $html_status = $_POST['html_status'];
        $tat_to_client = (int)$_POST['tat_to_client'];
        $missed_tat = $_POST['missed_tat'];
        $instruction_to_image = $_POST['instruction_to_image'];
        $remarks = $_POST['remarks'];
        
        // Check if tracking already exists
        $check_sql = "SELECT id FROM unique_xml_jobs WHERE job_id = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("i", $job_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            // Update existing record
            $sql = "UPDATE unique_xml_jobs SET 
                    rec_date = ?, journal_name_client = ?, issue = ?, articles = ?,
                    xml_status = ?, scielo_ebsco_status = ?, pubmed_biblat_status = ?, html_status = ?,
                    tat_to_client = ?, missed_tat = ?, instruction_to_image = ?, remarks = ?,
                    updated_at = NOW(), created_by = ?
                    WHERE job_id = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ssssssssissssi", 
                $rec_date, $journal_name_client, $issue, $articles,
                $xml_status, $scielo_ebsco_status, $pubmed_biblat_status, $html_status,
                $tat_to_client, $missed_tat, $instruction_to_image, $remarks,
                $current_user, $job_id
            );
        } else {
            // Insert new record
            $sql = "INSERT INTO unique_xml_jobs 
                    (job_id, rec_date, journal_name_client, issue, articles, xml_status, 
                     scielo_ebsco_status, pubmed_biblat_status, html_status, tat_to_client, 
                     missed_tat, instruction_to_image, remarks, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("issssssssissss", 
                $job_id, $rec_date, $journal_name_client, $issue, $articles,
                $xml_status, $scielo_ebsco_status, $pubmed_biblat_status, $html_status,
                $tat_to_client, $missed_tat, $instruction_to_image, $remarks,
                $current_user
            );
        }
        
        if ($stmt->execute()) {
            $message = "Tracking information saved successfully!";
            $message_type = "success";
        } else {
            $message = "Error saving tracking information: " . $mysqli->error;
            $message_type = "danger";
        }
        $stmt->close();
    }
}

// Get filter parameters
$search = trim($_GET['search'] ?? '');
$filter_client = trim($_GET['client'] ?? '');
$filter_process = trim($_GET['process'] ?? '');
$filter_date_from = trim($_GET['date_from'] ?? '');
$filter_date_to = trim($_GET['date_to'] ?? '');
$show_tracked = isset($_GET['show_tracked']) ? $_GET['show_tracked'] : 'all';

// Build query for completed jobs
$where = [];
$params = [];
$types = '';

// Only show jobs where all required stages are completed
$where[] = "(
    (jm.job_name IN ('CE+PAG+XML', 'PAG+XML', 'CE+XML')) AND
    (
        (jm.job_name = 'CE+PAG+XML' AND 
         COALESCE(ce.status, 'Completed') = 'Completed' AND 
         COALESCE(le.status, 'Completed') = 'Completed' AND 
         COALESCE(pag.status, 'Completed') = 'Completed' AND 
         COALESCE(qc.status, 'Completed') = 'Completed')
        OR
        (jm.job_name = 'PAG+XML' AND 
         COALESCE(pag.status, 'Completed') = 'Completed' AND 
         COALESCE(qc.status, 'Completed') = 'Completed')
        OR
        (jm.job_name = 'CE+XML' AND 
         COALESCE(ce.status, 'Completed') = 'Completed' AND 
         COALESCE(le.status, 'Completed') = 'Completed')
    )
)";

if ($search) {
    $where[] = "(c.client_name LIKE ? OR j.journal_issue LIKE ? OR j.article LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
    $types .= "sss";
}

if ($filter_client) {
    $where[] = "c.client_name LIKE ?";
    $params[] = "%$filter_client%";
    $types .= "s";
}

if ($filter_process) {
    $where[] = "jm.job_name = ?";
    $params[] = $filter_process;
    $types .= "s";
}

if ($filter_date_from) {
    $where[] = "j.job_date >= ?";
    $params[] = $filter_date_from;
    $types .= "s";
}

if ($filter_date_to) {
    $where[] = "j.job_date <= ?";
    $params[] = $filter_date_to . ' 23:59:59';
    $types .= "s";
}

// Filter by tracking status
if ($show_tracked === 'tracked') {
    $where[] = "EXISTS (SELECT 1 FROM unique_xml_jobs WHERE job_id = j.job_id)";
} elseif ($show_tracked === 'untracked') {
    $where[] = "NOT EXISTS (SELECT 1 FROM unique_xml_jobs WHERE job_id = j.job_id)";
}

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Main query
$sql = "SELECT 
    j.job_id, j.job_date, c.client_name, j.journal_issue, j.article,
    jm.job_name AS process, j.dispatch_date,
    
    ce.ce_date, ce.done_by AS ce_done_by, ce.status AS ce_status,
    le.le_date, le.done_by AS le_done_by, le.status AS le_status,
    pag.pag_date, pag.done_by AS pag_done_by, pag.status AS pag_status,
    qc.qc_date, qc.done_by AS qc_done_by, qc.status AS qc_status,
    
    cjt.rec_date, cjt.journal_name_client, cjt.issue, cjt.articles,
    cjt.xml_status, cjt.scielo_ebsco_status, cjt.pubmed_biblat_status, cjt.html_status,
    cjt.tat_to_client, cjt.missed_tat, cjt.instruction_to_image, cjt.remarks
    
FROM jobs j
JOIN client_master c ON j.client_id = c.client_id
JOIN job_master jm ON j.process_id = jm.job_id

LEFT JOIN ce ON ce.job_id = j.job_id
LEFT JOIN le ON le.job_id = j.job_id
LEFT JOIN pag ON pag.job_id = j.job_id
LEFT JOIN qc ON qc.job_id = j.job_id

LEFT JOIN unique_xml_jobs cjt ON cjt.job_id = j.job_id

$where_sql
ORDER BY j.job_date DESC, j.job_id DESC";

$stmt = $mysqli->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$results = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Completed Jobs Tracking</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        body { background-color: #f5f7fa; font-size: 14px; }
        .card { border: none; border-radius: 8px; box-shadow: 0 1px 10px rgba(0,0,0,0.05); }
        .table th { background-color: #2c3e50; color: white; border: none; font-weight: 600; font-size: 13px; padding: 8px 12px; }
        .table td { vertical-align: middle; padding: 8px 12px; font-size: 13px; }
        .status-badge { padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .status-completed { background-color: #28a745; color: white; }
        .status-pending { background-color: #6c757d; color: white; }
        .tracked-badge { background-color: #17a2b8; color: white; }
        .untracked-badge { background-color: #dc3545; color: white; }
        .modal-lg { max-width: 900px; }
        .filter-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .stat-card { transition: transform 0.3s; padding: 10px; }
        .stat-card:hover { transform: translateY(-3px); }
        .action-btn { padding: 3px 8px; font-size: 11px; }
        .highlight-row { transition: background-color 0.2s; }
        .highlight-row:hover { background-color: #e8f4fd; }
        .compact-filter .form-label { font-size: 11px; margin-bottom: 2px; color: #6c757d; }
        .compact-filter .form-control, 
        .compact-filter .form-select { font-size: 12px; padding: 3px 6px; height: 30px; }
        .compact-filter .btn { padding: 3px 10px; font-size: 12px; }
        .stat-card h6 { font-size: 12px; margin-bottom: 5px; }
        .stat-card h2 { font-size: 20px; margin: 0; }
        .stat-card i { font-size: 24px !important; }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="container-fluid mt-2">
        <?php if (isset($message)): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show py-2" style="font-size: 13px;">
                <?= h($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" style="padding: 8px;"></button>
            </div>
        <?php endif; ?>

        <!-- Header Card - Made more compact -->
        <div class="row mb-2">
            <div class="col-12">
                <div class="card filter-card py-1">
                    <div class="card-body py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="card-title mb-0 text-white" style="font-size: 16px;">
                                    <i class="fas fa-clipboard-check me-2"></i>Completed Jobs Tracking
                                </h5>
                                <p class="mb-0 text-white opacity-75" style="font-size: 12px;">
                                    Track completed CE+PAG+XML, PAG+XML, and CE+XML jobs
                                </p>
                            </div>
                            <div>
                                <button class="btn btn-light btn-sm py-1 px-2" onclick="exportToExcel()" style="font-size: 12px;">
                                    <i class="fas fa-file-excel me-1"></i> Export
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards - Made more compact -->
        <div class="row mb-2">
            <div class="col-md-3">
                <div class="card stat-card bg-primary text-white py-2">
                    <div class="card-body py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title mb-0">Total Jobs</h6>
                                <h2 class="mt-1 mb-0"><?= $results->num_rows ?></h2>
                            </div>
                            <i class="fas fa-tasks opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card bg-success text-white py-2">
                    <div class="card-body py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title mb-0">CE+PAG+XML</h6>
                                <?php
                                $count_cepagxml = 0;
                                $results->data_seek(0);
                                while($row = $results->fetch_assoc()) {
                                    if ($row['process'] == 'CE+PAG+XML') $count_cepagxml++;
                                }
                                $results->data_seek(0);
                                ?>
                                <h2 class="mt-1 mb-0"><?= $count_cepagxml ?></h2>
                            </div>
                            <i class="fas fa-layer-group opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card bg-info text-white py-2">
                    <div class="card-body py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title mb-0">PAG+XML</h6>
                                <?php
                                $count_pagxml = 0;
                                $results->data_seek(0);
                                while($row = $results->fetch_assoc()) {
                                    if ($row['process'] == 'PAG+XML') $count_pagxml++;
                                }
                                $results->data_seek(0);
                                ?>
                                <h2 class="mt-1 mb-0"><?= $count_pagxml ?></h2>
                            </div>
                            <i class="fas fa-code opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card bg-warning text-dark py-2">
                    <div class="card-body py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title mb-0">CE+XML</h6>
                                <?php
                                $count_cexml = 0;
                                $results->data_seek(0);
                                while($row = $results->fetch_assoc()) {
                                    if ($row['process'] == 'CE+XML') $count_cexml++;
                                }
                                $results->data_seek(0);
                                ?>
                                <h2 class="mt-1 mb-0"><?= $count_cexml ?></h2>
                            </div>
                            <i class="fas fa-file-alt opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Compact Filter Card -->
        <div class="row mb-2">
            <div class="col-12">
                <div class="card">
                    <div class="card-body p-2">
                        <form method="get" class="row g-1 compact-filter align-items-end">
                            <div class="col-md-2 col-sm-6">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" placeholder="Search..." 
                                       value="<?= h($search) ?>" class="form-control">
                            </div>
                            <div class="col-md-2 col-sm-6">
                                <label class="form-label">Client</label>
                                <input type="text" name="client" placeholder="Client" 
                                       value="<?= h($filter_client) ?>" class="form-control">
                            </div>
                            <div class="col-md-2 col-sm-6">
                                <label class="form-label">Process</label>
                                <select name="process" class="form-select">
                                    <option value="">All</option>
                                    <option value="CE+PAG+XML" <?= $filter_process == 'CE+PAG+XML' ? 'selected' : '' ?>>CE+PAG+XML</option>
                                    <option value="PAG+XML" <?= $filter_process == 'PAG+XML' ? 'selected' : '' ?>>PAG+XML</option>
                                    <option value="CE+XML" <?= $filter_process == 'CE+XML' ? 'selected' : '' ?>>CE+XML</option>
                                </select>
                            </div>
                            <div class="col-md-2 col-sm-6">
                                <label class="form-label">From</label>
                                <input type="date" name="date_from" value="<?= h($filter_date_from) ?>" 
                                       class="form-control">
                            </div>
                            <div class="col-md-2 col-sm-6">
                                <label class="form-label">To</label>
                                <input type="date" name="date_to" value="<?= h($filter_date_to) ?>" 
                                       class="form-control">
                            </div>
                            <div class="col-md-2 col-sm-6">
                                <label class="form-label">Tracking</label>
                                <select name="show_tracked" class="form-select">
                                    <option value="all" <?= $show_tracked == 'all' ? 'selected' : '' ?>>All</option>
                                    <option value="tracked" <?= $show_tracked == 'tracked' ? 'selected' : '' ?>>Tracked</option>
                                    <option value="untracked" <?= $show_tracked == 'untracked' ? 'selected' : '' ?>>Untracked</option>
                                </select>
                            </div>
                            <div class="col-md-12 d-flex gap-1 mt-1">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="fas fa-filter me-1"></i> Filter
                                </button>
                                <a href="xml_unique_start.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-times me-1"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Table -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="trackingTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Job Date</th>
                                        <th>Client</th>
                                        <th>Journal/Issue</th>
                                        <th>Article</th>
                                        <th>Process</th>
                                        <th>Complete</th>
                                        <th>Rec. Date</th>
                                        <th>Journal Name</th>
                                        <th>Issue</th>
                                        <th>Articles</th>
                                        <th>XML</th>
                                        <th>Scielo/EBSCO</th>
                                        <th>PubMed/Biblat</th>
                                        <th>HTML</th>
                                        <th>TAT Days</th>
                                        <th>Missed TAT</th>
                                        <th>Image Inst.</th>
                                        <th>Remarks</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($results->num_rows > 0): 
                                        $i = 1;
                                        while($row = $results->fetch_assoc()): 
                                            $is_tracked = !empty($row['rec_date']);
                                    ?>
                                    <tr class="highlight-row <?= $is_tracked ? 'table-success' : '' ?>">
                                        <td><?= $i++ ?></td>
                                        <td><?= formatDateTime($row['job_date'], 'd-m-Y') ?></td>
                                        <td><strong><?= h($row['client_name']) ?></strong></td>
                                        <td><?= h($row['journal_issue']) ?></td>
                                        <td><?= h($row['article']) ?></td>
                                        <td>
                                            <span class="badge bg-primary" style="font-size: 11px;"><?= h($row['process']) ?></span>
                                        </td>
                                        <td>
                                            <?php
                                            $stages = [];
                                            if (!empty($row['ce_status']) && $row['ce_status'] == 'Completed') $stages[] = 'CE';
                                            if (!empty($row['le_status']) && $row['le_status'] == 'Completed') $stages[] = 'LE';
                                            if (!empty($row['pag_status']) && $row['pag_status'] == 'Completed') $stages[] = 'PAG';
                                            if (!empty($row['qc_status']) && $row['qc_status'] == 'Completed') $stages[] = 'QC';
                                            echo implode(', ', $stages);
                                            ?>
                                        </td>
                                        <td><?= $is_tracked ? formatDateTime($row['rec_date'], 'd-m-Y') : '—' ?></td>
                                        <td><?= h($row['journal_name_client'] ?? '—') ?></td>
                                        <td><?= h($row['issue'] ?? '—') ?></td>
                                        <td><?= h($row['articles'] ?? '—') ?></td>
                                        <td>
                                            <?php if ($is_tracked && !empty($row['xml_status'])): ?>
                                                <span class="badge bg-info" style="font-size: 11px;">
                                                    <?= h($row['xml_status']) ?>
                                                </span>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($is_tracked && !empty($row['scielo_ebsco_status'])): ?>
                                                <span class="badge bg-info" style="font-size: 11px;">
                                                    <?= h($row['scielo_ebsco_status']) ?>
                                                </span>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($is_tracked && !empty($row['pubmed_biblat_status'])): ?>
                                                <span class="badge bg-info" style="font-size: 11px;">
                                                    <?= h($row['pubmed_biblat_status']) ?>
                                                </span>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($is_tracked && !empty($row['html_status'])): ?>
                                                <span class="badge bg-info" style="font-size: 11px;">
                                                    <?= h($row['html_status']) ?>
                                                </span>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $is_tracked ? h($row['tat_to_client']) . ' d' : '—' ?></td>
                                        <td>
                                            <?php if ($is_tracked): ?>
                                                <span class="badge bg-<?= $row['missed_tat'] == 'Yes' ? 'danger' : 'success' ?>" style="font-size: 11px;">
                                                    <?= h($row['missed_tat'] ?? 'No') ?>
                                                </span>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($is_tracked && !empty($row['instruction_to_image'])): ?>
                                                <span class="text-truncate d-inline-block" style="max-width: 100px; font-size: 11px;" 
                                                      title="<?= h($row['instruction_to_image']) ?>">
                                                    <?= substr(h($row['instruction_to_image']), 0, 20) . '...' ?>
                                                </span>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($is_tracked && !empty($row['remarks'])): ?>
                                                <span class="text-truncate d-inline-block" style="max-width: 100px; font-size: 11px;" 
                                                      title="<?= h($row['remarks']) ?>">
                                                    <?= substr(h($row['remarks']), 0, 20) . '...' ?>
                                                </span>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary action-btn" 
                                                    onclick="openTrackingModal(<?= $row['job_id'] ?>)"
                                                    title="Edit Tracking">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($is_tracked): ?>
                                                <button class="btn btn-sm btn-info action-btn" 
                                                        onclick="viewDetails(<?= $row['job_id'] ?>)"
                                                        title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <?php else: ?>
                                    <tr>
                                        <td colspan="20" class="text-center py-3" style="font-size: 13px;">
                                            <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                                            <h6 class="text-muted">No completed jobs found</h6>
                                            <p class="text-muted mb-0">Try adjusting your filters</p>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tracking Modal -->
    <div class="modal fade" id="trackingModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" id="trackingForm">
                    <input type="hidden" name="action" value="save_tracking">
                    <input type="hidden" name="job_id" id="modalJobId">
                    
                    <div class="modal-header bg-primary text-white py-2">
                        <h5 class="modal-title" style="font-size: 16px;">Job Tracking Details</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body py-3">
                        <div class="row mb-2" style="font-size: 13px;">
                            <div class="col-md-6">
                                <label class="form-label">Job ID</label>
                                <input type="text" id="displayJobId" class="form-control form-control-sm" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Client Name</label>
                                <input type="text" id="displayClientName" class="form-control form-control-sm" readonly>
                            </div>
                        </div>
                        
                        <div class="row mb-2" style="font-size: 13px;">
                            <div class="col-md-6">
                                <label class="form-label">Journal/Issue</label>
                                <input type="text" id="displayJournalIssue" class="form-control form-control-sm" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Article</label>
                                <input type="text" id="displayArticle" class="form-control form-control-sm" readonly>
                            </div>
                        </div>
                        
                        <hr class="my-2">
                        
                        <div class="row g-2" style="font-size: 13px;">
                            <div class="col-md-4">
                                <label class="form-label">Rec. Date *</label>
                                <input type="date" name="rec_date" id="rec_date" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Journal Name Client</label>
                                <input type="text" name="journal_name_client" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Issue</label>
                                <input type="text" name="issue" class="form-control form-control-sm">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Articles</label>
                                <input type="text" name="articles" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">TAT to Client (days)</label>
                                <input type="number" name="tat_to_client" class="form-control form-control-sm" min="0">
                            </div>
                            
                            <!-- Text inputs instead of dropdowns -->
                            <div class="col-md-3">
                                <label class="form-label">XML Status</label>
                                <input type="text" name="xml_status" class="form-control form-control-sm" placeholder="e.g., Done, Pending">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Scielo/EBSCO</label>
                                <input type="text" name="scielo_ebsco_status" class="form-control form-control-sm" placeholder="e.g., Done, Pending">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">PubMed/Biblat</label>
                                <input type="text" name="pubmed_biblat_status" class="form-control form-control-sm" placeholder="e.g., Done, Pending">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">HTML Status</label>
                                <input type="text" name="html_status" class="form-control form-control-sm" placeholder="e.g., Done, Pending">
                            </div>
                            
                            <!-- Missed TAT as text input -->
                            <div class="col-md-3">
                                <label class="form-label">Missed TAT</label>
                                <input type="text" name="missed_tat" class="form-control form-control-sm" placeholder="e.g., No, Yes">
                            </div>
                            
                            <div class="col-md-9">
                                <label class="form-label">Instruction to Image</label>
                                <input type="text" name="instruction_to_image" class="form-control form-control-sm">
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Remarks</label>
                                <textarea name="remarks" class="form-control form-control-sm" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm">Save Tracking</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
    // Open tracking modal
    function openTrackingModal(jobId) {
        // Reset form
        document.getElementById('trackingForm').reset();
        document.getElementById('modalJobId').value = jobId;
        
        // Get job details from the table row
        const row = document.querySelector(`button[onclick="openTrackingModal(${jobId})"]`).closest('tr');
        const cells = row.querySelectorAll('td');
        
        // Fill display fields
        document.getElementById('displayJobId').value = jobId;
        document.getElementById('displayClientName').value = cells[2].innerText;
        document.getElementById('displayJournalIssue').value = cells[3].innerText;
        document.getElementById('displayArticle').value = cells[4].innerText;
        
        // Fill form fields if already tracked
        const isTracked = cells[7].innerText !== '—';
        if (isTracked) {
            // Parse date from d-m-Y to Y-m-d
            const recDate = cells[7].innerText;
            if (recDate && recDate !== '—') {
                const parts = recDate.split('-');
                if (parts.length === 3) {
                    document.getElementById('rec_date').value = `${parts[2]}-${parts[1]}-${parts[0]}`;
                }
            }
            
            document.querySelector('input[name="journal_name_client"]').value = cells[8].innerText !== '—' ? cells[8].innerText : '';
            document.querySelector('input[name="issue"]').value = cells[9].innerText !== '—' ? cells[9].innerText : '';
            document.querySelector('input[name="articles"]').value = cells[10].innerText !== '—' ? cells[10].innerText : '';
            
            // Set status text inputs
            setInputValue('xml_status', getStatusFromCell(cells[11]));
            setInputValue('scielo_ebsco_status', getStatusFromCell(cells[12]));
            setInputValue('pubmed_biblat_status', getStatusFromCell(cells[13]));
            setInputValue('html_status', getStatusFromCell(cells[14]));
            
            // Set TAT
            const tatText = cells[15].innerText;
            if (tatText !== '—') {
                const tatDays = tatText.replace(' d', '').trim();
                document.querySelector('input[name="tat_to_client"]').value = tatDays;
            }
            
            // Set Missed TAT as text input
            const missedTatText = cells[16].innerText;
            if (missedTatText !== '—') {
                setInputValue('missed_tat', missedTatText);
            }
            
            // Set Instruction to Image
            const instructionText = cells[17].querySelector('span');
            if (instructionText && instructionText.title) {
                document.querySelector('input[name="instruction_to_image"]').value = instructionText.title;
            }
            
            // Set Remarks
            const remarksText = cells[18].querySelector('span');
            if (remarksText && remarksText.title) {
                document.querySelector('textarea[name="remarks"]').value = remarksText.title;
            }
        } else {
            // Set today's date as default
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('rec_date').value = today;
            // Set default for Missed TAT
            document.querySelector('input[name="missed_tat"]').value = 'No';
        }
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('trackingModal'));
        modal.show();
    }
    
    function getStatusFromCell(cell) {
        const badge = cell.querySelector('.badge');
        if (badge) {
            return badge.innerText;
        } else if (cell.innerText && cell.innerText !== '—') {
            return cell.innerText;
        }
        return '';
    }
    
    function setInputValue(inputName, value) {
        const input = document.querySelector(`input[name="${inputName}"]`);
        if (input && value !== '—' && value) {
            input.value = value;
        }
    }
    
    function viewDetails(jobId) {
        // Implement view details functionality
        alert(`View details for Job ID: ${jobId}`);
    }
    
    function exportToExcel() {
        let table = document.getElementById('trackingTable');
        let html = table.outerHTML;
        
        // Clean up HTML for Excel
        html = html.replace(/<button[^>]*>.*?<\/button>/gi, '');
        html = html.replace(/<i[^>]*>.*?<\/i>/gi, '');
        
        let blob = new Blob([`<html><head><meta charset="UTF-8"></head><body>${html}</body></html>`], {
            type: 'application/vnd.ms-excel'
        });
        
        let link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'xml_unique_start_' + new Date().toISOString().split('T')[0] + '.xls';
        link.click();
    }
    
    // Initialize date picker
    flatpickr("input[type=date]", {
        dateFormat: "Y-m-d",
        allowInput: true
    });
    </script>
</body>
</html>