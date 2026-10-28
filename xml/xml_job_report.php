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

function formatDate($date, $format = 'd-m-Y') {
    if (!$date) return '';
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    return $dateObj ? $dateObj->format($format) : $date;
}

$logged_user = $_SESSION['username'] ?? '';
$role_name = $_SESSION['role_name'] ?? '';

// Handle filtering and sorting
$search = trim($_GET['search'] ?? '');
$sort = $_GET['sort'] ?? 'id';
$dir = strtoupper($_GET['dir'] ?? 'DESC');
if (!in_array($dir,['ASC', 'DESC'], true)) $dir = 'DESC';

$allowed_sorts =['id', 'job_id', 'rec_date', 'journal_name_client', 'tat_to_client', 'xml_status'];
if (!in_array($sort, $allowed_sorts, true)) $sort = 'id';

// Build the query for unique_xml_jobs
$where_clauses = ["1"];
$params =[];
$types = '';

if (!empty($search)) {
    $where_clauses[] = "(journal_name_client LIKE ? OR job_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}

$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

$sql = "SELECT `id`, `job_id`, `rec_date`, `journal_name_client`, `issue`, `articles`, 
               `xml_status`, `scielo_ebsco_status`, `pubmed_biblat_status`, `html_status`, 
               `tat_to_client`, `missed_tat`, `instruction_to_image`, `remarks`, 
               `created_at`, `created_by`, `updated_at`, `Image_user`, `Table_user`, 
               `WPContent_user`, `HTML_user`, `Scielo_user`, `QC_user`, `final Remarks`, 
               `Billed`, `status`, `updated_by` 
        FROM `unique_xml_jobs` 
        $where_sql 
        ORDER BY `$sort` $dir";

if (!empty($params)) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) die('Error preparing query: ' . $mysqli->error);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $jobs_res = $stmt->get_result();
    $stmt->close();
} else {
    $jobs_res = $mysqli->query($sql);
    if (!$jobs_res) die('Error executing query: ' . $mysqli->error);
}

// ---------------------------------------------------------
// CALCULATE STATISTICS FOR THE OVERALL REPORT
// ---------------------------------------------------------
$stats =[
    'total_jobs' => 0,
    'missed_tat' => 0,
    'met_tat' => 0,
    'billed' => 0,
    'unbilled' => 0,
    'xml_pending' => 0,
    'html_pending' => 0
];

if ($jobs_res && $jobs_res->num_rows > 0) {
    while ($row = $jobs_res->fetch_assoc()) {
        $stats['total_jobs']++;
        
        // TAT Status
        if (strtolower(trim((string)$row['missed_tat'])) === 'yes') {
            $stats['missed_tat']++;
        } else {
            $stats['met_tat']++;
        }
        
        // Billing Status
        if (strtolower(trim((string)$row['Billed'])) === 'yes') {
            $stats['billed']++;
        } else {
            $stats['unbilled']++;
        }

        // XML Status
        if (empty($row['xml_status']) || strtolower(trim((string)$row['xml_status'])) === 'pending') {
            $stats['xml_pending']++;
        }

        // HTML Status
        if (empty($row['html_status']) || strtolower(trim((string)$row['html_status'])) === 'pending') {
            $stats['html_pending']++;
        }
    }
    // Reset the internal pointer of the result set so the table below can loop through it again
    $jobs_res->data_seek(0);
}

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unique XML Jobs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <style>
        .table th { font-size: 0.8rem; font-weight: 600; background-color: #f8f9fa; white-space: nowrap; }
        .table td { font-size: 0.8rem; vertical-align: middle; }
        .badge { font-size: 0.75rem; font-weight: 500; padding: 5px 8px; }
        .text-truncate-custom { max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; }
        .text-truncate-custom:hover { overflow: visible; white-space: normal; background: white; box-shadow: 0 0 10px rgba(0,0,0,0.1); z-index: 1000; position: relative; }
        .user-list { font-size: 0.75rem; list-style-type: none; padding-left: 0; margin-bottom: 0; }
        .user-list li { margin-bottom: 2px; }
        .status-badge { background-color: #6f42c1; color: white; }
        
        /* Dashboard Card Styles */
        .report-card { border: none; border-radius: 10px; transition: transform 0.2s; }
        .report-card:hover { transform: translateY(-3px); }
        .report-icon { font-size: 2.5rem; opacity: 0.3; }
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

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Unique XML Jobs Dashboard</h5>
            <a href="unassigned_xml_jobs.php" class="btn btn-sm btn-secondary">
                <i class="fas fa-sync-alt"></i> Refresh
            </a>
        </div>

        <!-- ========================================== -->
        <!-- OVERALL REPORT CARDS START HERE            -->
        <!-- ========================================== -->
        <div class="row mb-4 g-3">
            <!-- Total Jobs -->
            <div class="col-md-3 col-sm-6">
                <div class="card report-card bg-primary text-white shadow-sm h-100">
                    <div class="card-body p-3 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1 text-uppercase fw-bold" style="font-size: 0.75rem; letter-spacing: 0.5px;">Total Records</h6>
                            <h3 class="mb-0 fw-bold"><?= $stats['total_jobs'] ?></h3>
                        </div>
                        <i class="fas fa-layer-group report-icon"></i>
                    </div>
                </div>
            </div>
            
            <!-- Missed TAT -->
            <div class="col-md-3 col-sm-6">
                <div class="card report-card bg-<?= $stats['missed_tat'] > 0 ? 'danger' : 'success' ?> text-white shadow-sm h-100">
                    <div class="card-body p-3 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1 text-uppercase fw-bold" style="font-size: 0.75rem; letter-spacing: 0.5px;">Missed TAT</h6>
                            <h3 class="mb-0 fw-bold"><?= $stats['missed_tat'] ?></h3>
                        </div>
                        <i class="fas fa-clock report-icon"></i>
                    </div>
                </div>
            </div>

            <!-- Pending XML -->
            <div class="col-md-3 col-sm-6">
                <div class="card report-card bg-warning text-dark shadow-sm h-100">
                    <div class="card-body p-3 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1 text-uppercase fw-bold" style="font-size: 0.75rem; letter-spacing: 0.5px;">Pending XML</h6>
                            <h3 class="mb-0 fw-bold"><?= $stats['xml_pending'] ?></h3>
                        </div>
                        <i class="fas fa-code report-icon"></i>
                    </div>
                </div>
            </div>

            <!-- Billed Jobs -->
            <div class="col-md-3 col-sm-6">
                <div class="card report-card bg-info text-white shadow-sm h-100">
                    <div class="card-body p-3 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1 text-uppercase fw-bold" style="font-size: 0.75rem; letter-spacing: 0.5px;">Billed Jobs</h6>
                            <h3 class="mb-0 fw-bold"><?= $stats['billed'] ?></h3>
                        </div>
                        <i class="fas fa-file-invoice-dollar report-icon"></i>
                    </div>
                </div>
            </div>
        </div>
        <!-- ========================================== -->
        <!-- OVERALL REPORT CARDS END HERE              -->
        <!-- ========================================== -->

        <!-- Filter form -->
        <form method="get" class="row gy-2 gx-2 mb-3 align-items-end">
            <div class="col-md-4">
                <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search by Client/Journal or Job ID" class="form-control form-control-sm">
            </div>
            <div class="col-md-3">
                <select name="sort" class="form-select form-select-sm">
                    <option value="id" <?= $sort === 'id' ? 'selected' : '' ?>>ID</option>
                    <option value="job_id" <?= $sort === 'job_id' ? 'selected' : '' ?>>Job ID</option>
                    <option value="rec_date" <?= $sort === 'rec_date' ? 'selected' : '' ?>>Received Date</option>
                    <option value="journal_name_client" <?= $sort === 'journal_name_client' ? 'selected' : '' ?>>Journal / Client</option>
                    <option value="tat_to_client" <?= $sort === 'tat_to_client' ? 'selected' : '' ?>>TAT to Client</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="dir" class="form-select form-select-sm">
                    <option value="ASC" <?= $dir === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                    <option value="DESC" <?= $dir === 'DESC' ? 'selected' : '' ?>>Descending</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filter</button>
                <a href="unassigned_xml_jobs.php" class="btn btn-secondary btn-sm">Clear</a>
            </div>
        </form>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#ID</th>
                                <th>Job ID</th>
                                <th>Rec Date</th>
                                <th>Journal / Client</th>
                                <th>Issue / Articles</th>
                                <th>Statuses</th>
                                <th>Assigned Users</th>
                                <th>TAT info</th>
                                <th>Remarks & Instructions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($jobs_res && $jobs_res->num_rows):
                                while ($job = $jobs_res->fetch_assoc()): 
                            ?>
                                <tr>
                                    <td><?= (int)$job['id'] ?></td>
                                    <td><strong><?= h($job['job_id']) ?></strong></td>
                                    <td><?= formatDate($job['rec_date']) ?></td>
                                    <td>
                                        <span class="text-primary fw-bold"><?= h($job['journal_name_client']) ?></span>
                                    </td>
                                    <td>
                                        <div><strong>Issue:</strong> <?= h($job['issue']) ?: '-' ?></div>
                                        <div><strong>Articles:</strong> <?= h($job['articles']) ?: '-' ?></div>
                                    </td>
                                    <td>
                                        <ul class="user-list">
                                            <li><strong>XML:</strong> <span class="badge <?= empty($job['xml_status']) ? 'bg-secondary' : 'bg-info text-dark' ?>"><?= h($job['xml_status']) ?: 'Pending' ?></span></li>
                                            <li><strong>SciELO/EBSCO:</strong> <span class="badge <?= empty($job['scielo_ebsco_status']) ? 'bg-secondary' : 'bg-info text-dark' ?>"><?= h($job['scielo_ebsco_status']) ?: 'Pending' ?></span></li>
                                            <li><strong>PubMed/Biblat:</strong> <span class="badge <?= empty($job['pubmed_biblat_status']) ? 'bg-secondary' : 'bg-info text-dark' ?>"><?= h($job['pubmed_biblat_status']) ?: 'Pending' ?></span></li>
                                            <li><strong>HTML:</strong> <span class="badge <?= empty($job['html_status']) ? 'bg-secondary' : 'bg-info text-dark' ?>"><?= h($job['html_status']) ?: 'Pending' ?></span></li>
                                        </ul>
                                    </td>
                                    <td>
                                        <ul class="user-list">
                                            <li><strong>Image:</strong> <span class="text-muted"><?= h($job['Image_user']) ?: 'Unassigned' ?></span></li>
                                            <li><strong>Table:</strong> <span class="text-muted"><?= h($job['Table_user']) ?: 'Unassigned' ?></span></li>
                                            <li><strong>WP/Content:</strong> <span class="text-muted"><?= h($job['WPContent_user']) ?: 'Unassigned' ?></span></li>
                                            <li><strong>HTML:</strong> <span class="text-muted"><?= h($job['HTML_user']) ?: 'Unassigned' ?></span></li>
                                            <li><strong>SciELO:</strong> <span class="text-muted"><?= h($job['Scielo_user']) ?: 'Unassigned' ?></span></li>
                                            <li><strong>QC:</strong> <span class="text-muted"><?= h($job['QC_user']) ?: 'Unassigned' ?></span></li>
                                        </ul>
                                    </td>
                                    <td>
                                        <div><strong>Client TAT:</strong> <?= formatDate($job['tat_to_client']) ?: '-' ?></div>
                                        <div><strong>Missed:</strong> 
                                            <?php if(strtolower((string)$job['missed_tat']) === 'yes'): ?>
                                                <span class="badge bg-danger">Yes</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">No</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-1"><strong>Status:</strong> <?= h($job['status']) ?: '-' ?></div>
                                        <div class="mt-1"><strong>Billed:</strong> <?= h($job['Billed']) ?: '-' ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($job['instruction_to_image'])): ?>
                                            <div><strong class="text-primary">Image Instr:</strong> <span class="text-truncate-custom" title="<?= h($job['instruction_to_image']) ?>"><?= h($job['instruction_to_image']) ?></span></div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($job['remarks'])): ?>
                                            <div><strong class="text-warning">Remarks:</strong> <span class="text-truncate-custom" title="<?= h($job['remarks']) ?>"><?= h($job['remarks']) ?></span></div>
                                        <?php endif; ?>

                                        <?php if (!empty($job['final Remarks'])): ?>
                                            <div><strong class="text-success">Final Remarks:</strong> <span class="text-truncate-custom" title="<?= h($job['final Remarks']) ?>"><?= h($job['final Remarks']) ?></span></div>
                                        <?php endif; ?>
                                        
                                        <?php if(empty($job['instruction_to_image']) && empty($job['remarks']) && empty($job['final Remarks'])): ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        No XML jobs found in the unique queue.
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
</body>
</html>