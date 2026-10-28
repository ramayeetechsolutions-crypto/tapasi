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

function normalize_process($p){
    if(!$p) return '';
    $parts = array_map('trim', explode('+', strtoupper(str_replace(' ', '', $p))));
    sort($parts);
    return implode('+', $parts);
}

function canDoStage($empStages,$stage,$hier){
    foreach($empStages as $es){
        if(isset($hier[$es]) && in_array($stage,$hier[$es])) return true;
    }
    return false;
}

function redirect_to($params = []) {
    $url = 'jobs.php';
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    header("Location: $url");
    exit;
}

function formatDate($date, $format = 'd-m-Y') {
    if (!$date) return '';
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    return $dateObj ? $dateObj->format($format) : $date;
}

$processStageMap = [
    'CE'          => ['CE','LE'],
    'PAG'         => ['PAG','QC'],
    'CE+PAG'      => ['CE','LE','PAG','QC'],
    'CE+PAG+XML'  => ['CE','LE','PAG','QC','XML'],
    'CE+XML'      => ['CE','LE','XML'],
    'PAG+XML'     => ['PAG','QC','XML'],
    'XML'         => ['XML'],
];

$skillHierarchy = [
    'CE'  => ['CE','LE'],
    'PAG' => ['PAG','QC'],
    'XML' => ['XML'],
];

$logged_user = $_SESSION['username'] ?? '';
$role_name = $_SESSION['role_name'] ?? '';

// Get all Team Leaders (user_role_id = 3)
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

// Get search parameters
$search_client = trim($_GET['search_client'] ?? '');
$selected_tl = trim($_GET['selected_tl'] ?? '');
$sort = $_GET['sort'] ?? 'job_id';
$dir = strtoupper($_GET['dir'] ?? 'DESC');
if (!in_array($dir, ['ASC', 'DESC'], true)) $dir = 'DESC';

$allowed_sorts = ['job_id', 'job_date', 'client_name', 'job_name', 'article', 'assigned_to', 'due_date'];
if (!in_array($sort, $allowed_sorts, true)) $sort = 'job_id';

// --- Main query to fetch job list ---
$where_clauses = [];
$params = [];
$types = '';

if (!empty($search_client)) {
    $where_clauses[] = "c.client_name LIKE ?";
    $params[] = "%$search_client%";
    $types .= 's';
}

// FILTER: Only show jobs assigned to selected Team Leader
if (!empty($selected_tl)) {
    $where_clauses[] = "j.assigned_to = ?";
    $params[] = $selected_tl;
    $types .= 's';
}

$where_sql = '';
if ($where_clauses) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

$sql = "SELECT j.job_id, j.job_date, j.journal_issue, j.mail_subject, j.article, 
               j.assigned_to, j.assigned_to_tm, j.notes, j.queries, j.word_count,
               j.assigned_ce, j.assigned_le, j.assigned_pag, j.assigned_qc, j.assigned_xml, 
               j.due_date, j.process_id, c.client_name, p.job_name,
               CASE 
                   WHEN p.job_name = 'CE' THEN 
                       CASE 
                           WHEN j.assigned_ce IS NULL AND j.assigned_le IS NULL THEN 'Pending'
                           WHEN j.assigned_le IS NULL THEN 'In Progress' 
                           WHEN j.assigned_ce IS NOT NULL AND j.assigned_le IS NOT NULL THEN 'Completed'
                           ELSE 'In Progress'
                       END
                   WHEN p.job_name = 'PAG' THEN 
                       CASE 
                           WHEN j.assigned_pag IS NULL AND j.assigned_qc IS NULL THEN 'Pending'
                           WHEN j.assigned_qc IS NULL THEN 'In Progress'
                           WHEN j.assigned_pag IS NOT NULL AND j.assigned_qc IS NOT NULL THEN 'Completed'
                           ELSE 'In Progress'
                       END
                   WHEN p.job_name = 'XML' THEN 
                       CASE 
                           WHEN j.assigned_xml IS NULL THEN 'Pending'
                           WHEN j.assigned_xml IS NOT NULL THEN 'Completed'
                           ELSE 'Pending'
                       END
                   WHEN p.job_name IN ('CE+PAG', 'CE+PAG+XML') THEN 
                       CASE 
                           WHEN j.assigned_ce IS NULL AND j.assigned_le IS NULL AND j.assigned_pag IS NULL AND j.assigned_qc IS NULL THEN 'Pending'
                           WHEN j.assigned_ce IS NOT NULL AND j.assigned_le IS NOT NULL AND j.assigned_pag IS NOT NULL AND j.assigned_qc IS NOT NULL THEN 'Completed'
                           ELSE 'In Progress'
                       END
                   WHEN p.job_name IN ('CE+XML', 'PAG+XML') THEN 
                       CASE 
                           WHEN (j.assigned_ce IS NULL AND j.assigned_le IS NULL AND j.assigned_xml IS NULL) OR 
                                (j.assigned_pag IS NULL AND j.assigned_qc IS NULL AND j.assigned_xml IS NULL) THEN 'Pending'
                           WHEN (j.assigned_ce IS NOT NULL AND j.assigned_le IS NOT NULL AND j.assigned_xml IS NOT NULL) OR 
                                (j.assigned_pag IS NOT NULL AND j.assigned_qc IS NOT NULL AND j.assigned_xml IS NOT NULL) THEN 'Completed'
                           ELSE 'In Progress'
                       END
                   ELSE 'Pending'
               END as status
        FROM jobs j
        JOIN client_master c ON j.client_id = c.client_id
        JOIN job_master p ON j.process_id = p.job_id
        $where_sql
        ORDER BY $sort $dir";

if ($where_sql && $types) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) die('Error preparing jobs query: ' . $mysqli->error);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $jobs_res = $stmt->get_result();
    $stmt->close();
} else {
    $jobs_res = $mysqli->query($sql);
    if (!$jobs_res) die('Error executing jobs query: ' . $mysqli->error);
}

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jobs Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <style>
        .btn-brown { background-color: brown !important; color: white !important; }
        .compact-form { max-height: 80vh; overflow-y: auto; }
        .text-truncate { max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .table th { font-size: 0.85rem; font-weight: 600; background-color: #f8f9fa; }
        .table td { font-size: 0.85rem; vertical-align: middle; }
        .badge { font-size: 0.75rem; font-weight: 500; }
        .text-truncate:hover { overflow: visible; white-space: normal; background: white; box-shadow: 0 0 10px rgba(0,0,0,0.1); z-index: 1000; position: relative; }
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

        <!-- Main job list display -->
        <div class="d-flex align-items-center mb-2">
            <h5 class="mb-0 flex-grow-1">Jobs</h5>
        </div>

        <!-- Filter form -->
        <form method="get" class="row gy-2 gx-2 mb-3 align-items-end">
            <div class="col-md-3">
                <input type="text" name="search_client" value="<?= h($search_client) ?>" placeholder="Search by client" class="form-control form-control-sm">
            </div>
            <div class="col-md-3">
                <select name="selected_tl" class="form-select form-select-sm">
                    <option value="">All Team Leaders</option>
                    <?php foreach ($team_leaders as $tl): ?>
                        <option value="<?= h($tl['employee_name']) ?>" <?= $selected_tl === $tl['employee_name'] ? 'selected' : '' ?>>
                            <?= h($tl['employee_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <select name="sort" class="form-select form-select-sm">
                    <?php foreach ($allowed_sorts as $s): ?>
                        <option value="<?= $s ?>" <?= $sort === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- <div class="col-md-2">
                <select name="dir" class="form-select form-select-sm">
                    <option value="ASC" <?= $dir === 'ASC' ? 'selected' : '' ?>>Asc</option>
                    <option value="DESC" <?= $dir === 'DESC' ? 'selected' : '' ?>>Desc</option>
                </select>
            </div> -->
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="jobs.php" class="btn btn-secondary btn-sm">Clear</a>
            </div>
        </form>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>S.No</th>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Journal/Issue</th>
                                <th>Article</th>
                                <th>Mail Subject</th>
                                <th>Process</th>
                                <th>Assigned TM</th>
                                <th>Job Assign</th>
                                <th>Status</th>
                                <th>Notes</th>
                                <th>Queries</th>
                                <th>Word Count</th>
                                <th>Due Date</th>
                                <th>Assigned TL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($jobs_res && $jobs_res->num_rows):
                                $i=0;
                                while ($job = $jobs_res->fetch_assoc()): 
                                $i=$i+1;
                            ?>
                                <tr>
                                    <td><?= $i; ?></td>
                                    <td><?= formatDate($job['job_date']) ?></td>
                                    <td><?= h($job['client_name']) ?></td>
                                    <td><?= h($job['journal_issue']) ?></td>
                                    <td><?= h($job['article']) ?></td>
                                    <td><?= h($job['mail_subject']) ?></td>
                                    <td><?= h($job['job_name']) ?></td>
                                    
                                    <!-- Assigned TM Column -->
                                    <td>
                                        <?php if (!empty($job['assigned_to_tm'])): ?>
                                            <span class="badge bg-info text-white">
                                                <?= h($job['assigned_to_tm']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td>
                                        <?php
                                        $process_name = $job['job_name'];
                                        $norm_proc = normalize_process($process_name);
                                        $stages = $processStageMap[$norm_proc] ?? [];

                                        $bg_colors = [
                                            'CE'  => 'bg-primary text-white',
                                            'LE'  => 'bg-info text-dark',
                                            'PAG' => 'bg-success text-white',
                                            'QC'  => 'bg-warning text-dark',
                                            'XML' => 'bg-danger text-white'
                                        ];

                                        foreach ($stages as $stage):
                                            $stage_field = 'assigned_' . strtolower($stage);
                                            $stage_assigned = $job[$stage_field] ?? null;

                                            if ($stage_assigned) {
                                                $display_text = $stage . '-' . h($stage_assigned); 
                                                $bg_class = $bg_colors[$stage] ?? 'bg-light text-dark';
                                            } else {
                                                $display_text = $stage . '-Awaiting'; 
                                                $bg_class = 'bg-secondary text-white';
                                            }
                                        ?>
                                            <span class="badge <?= $bg_class ?> d-block mb-1" style="padding:4px 8px; font-size:0.8rem;">
                                                <?= $display_text ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </td>
                                    
                                    <td>
                                        <?php
                                        $status = $job['status'] ?? 'Pending';
                                        $status_colors = [
                                            'Pending' => 'bg-warning text-dark',
                                            'In Progress' => 'bg-info text-white', 
                                            'Completed' => 'bg-success text-white'
                                        ];
                                        $badge_class = $status_colors[$status] ?? 'bg-secondary text-white';
                                        ?>
                                        <span class="badge <?= $badge_class ?>" style="padding:6px 10px; font-size:0.85rem;">
                                            <?= h($status) ?>
                                        </span>
                                    </td>
                                    
                                    <!-- Notes Column -->
                                    <td>
                                        <?php if (!empty($job['notes'])): ?>
                                            <span class="d-inline-block text-truncate" style="max-width: 150px;" title="<?= h($job['notes']) ?>">
                                                <?= h(substr($job['notes'], 0, 50)) . (strlen($job['notes']) > 50 ? '...' : '') ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Queries Column -->
                                    <td>
                                        <?php if (!empty($job['queries'])): ?>
                                            <span class="d-inline-block text-truncate" style="max-width: 150px;" title="<?= h($job['queries']) ?>">
                                                <?= h(substr($job['queries'], 0, 50)) . (strlen($job['queries']) > 50 ? '...' : '') ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Word Count Column -->
                                    <td>
                                        <?php if (!empty($job['word_count']) && $job['word_count'] > 0): ?>
                                            <span class="badge bg-primary text-white">
                                                <?= number_format($job['word_count']) ?> words
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td><?= formatDate($job['due_date']) ?></td>
                                    
                                    <!-- Assigned TL Column -->
                                    <td>
                                        <?php if (!empty($job['assigned_to'])): ?>
                                            <span class="badge bg-success text-white">
                                                <?= h($job['assigned_to']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr>
                                    <td colspan="15" class="text-center py-3">
                                        No jobs found.
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