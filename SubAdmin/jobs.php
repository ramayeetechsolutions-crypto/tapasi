<br><br><br>
<?php
// Start a session if one is not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    // Get the user ID from the session if it's set
    if(isset($_SESSION['uid'])) $exeid=$_SESSION['uid'];
}

// Include the database connection file
require 'db.php';

// Helper function to escape HTML special characters for security
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

// Function to normalize a process name string
// It trims whitespace, converts to uppercase, splits by '+', sorts, and re-joins
function normalize_process($p){
    if(!$p) return '';
    $parts = array_map('trim', explode('+', strtoupper(str_replace(' ', '', $p))));
    sort($parts);
    return implode('+', $parts);
}

// Redirects the user to a new URL, optionally with query parameters
function redirect_to($params = []) {
    $url = 'jobs.php';
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    header("Location: $url");
    exit;
}

// Formats a date string into a more readable format (e.g., d-m-Y)
function formatDate($date, $format = 'd-m-Y') {
    if (!$date) return '';
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    return $dateObj ? $dateObj->format($format) : $date;
}

// Maps normalized process names to their corresponding job stages
$processStageMap = [
    'CE'          => ['CE','LE'],
    'PAG'         => ['PAG','QC'],
    'CE+PAG'      => ['CE','LE','PAG','QC'],
    'CE+PAG+XML'  => ['CE','LE','PAG','QC','XML'],
    'CE+XML'      => ['CE','LE','XML'],
    'PAG+XML'     => ['PAG','QC','XML'],
    'XML'         => ['XML'],
];

// Get logged-in user's details from the session
$logged_user = $_SESSION['username'] ?? '';
$role_name = $_SESSION['role_name'] ?? '';

// Get search, sort, and direction parameters from the URL
$search_client = trim($_GET['search_client'] ?? '');
$sort = $_GET['sort'] ?? 'job_id';
$dir = strtoupper($_GET['dir'] ?? 'DESC');
if (!in_array($dir, ['ASC', 'DESC'], true)) $dir = 'DESC';

// Define allowed sortable columns
$allowed_sorts = ['job_id', 'job_date', 'client_name', 'job_name', 'article', 'assigned_to', 'due_date'];
if (!in_array($sort, $allowed_sorts, true)) $sort = 'job_id';

// Set a default due date for new jobs
$default_due_date = date('Y-m-d H:i:s', strtotime('+5 days'));

// Load all processes from the database into an array
$all_processes = [];
$res = $mysqli->query("SELECT job_id, job_name FROM job_master WHERE status='Active' ORDER BY job_name");
if (!$res) die('Error loading processes: ' . $mysqli->error);
while ($r = $res->fetch_assoc()) $all_processes[$r['job_id']] = $r['job_name'];

// TL can see all processes
$processes = $all_processes;

// Get all clients for the dropdown menu
$clients_res = $mysqli->query("SELECT client_id, client_name, team_client_name FROM client_master WHERE status='Active' ORDER BY team_client_name");
if (!$clients_res) die('Error fetching clients: ' . $mysqli->error);

// Load all active employees for assignment dropdowns
$st = $mysqli->prepare("SELECT employee_id, employee_username, employee_name, user_role_id, COALESCE(jm.job_name, em.process) AS process
                        FROM employee_master em
                        LEFT JOIN job_master jm ON em.process = jm.job_id
                        WHERE em.status='Active'");
if (!$st) die('Error preparing employee query: ' . $mysqli->error);
$st->execute();
$resEmps = $st->get_result();
if (!$resEmps) die('Error executing employee query: ' . $st->error);
$employees = [];
while ($e = $resEmps->fetch_assoc()) {
    $e['label'] = $e['employee_name'] . ' (' . $e['process'] . ')';
    $employees[] = $e;
}
$st->close();

// --- Handle POST requests for form submissions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $job_id = (int)($_POST['job_id'] ?? 0);
    $job_date = $_POST['job_date'] ?: date('Y-m-d');
    $client_id = (int)($_POST['client_id'] ?? 0);
    $journal = trim($_POST['journal_issue'] ?? '');
    $article = trim($_POST['article'] ?? '');
    $process_id = (int)($_POST['process_id'] ?? 0);
    $due_date = $default_due_date;
    $due_date_ce = date('Y-m-d H:i:s', strtotime('+1 days'));
    $due_date_le = date('Y-m-d H:i:s', strtotime('+2 days'));
    $due_date_pag = date('Y-m-d H:i:s', strtotime('+3 days'));
    $due_date_qc = date('Y-m-d H:i:s', strtotime('+4 days'));

    // Check for duplicate journal/article entries
    $checkSql = "SELECT COUNT(*) FROM jobs WHERE journal_issue = ? AND article = ?";
    if ($action === 'edit') {
        $checkSql .= " AND job_id != ?";
    }
    $stmtC = $mysqli->prepare($checkSql);
    if (!$stmtC) die('Error preparing uniqueness check: ' . $mysqli->error);
    if ($action === 'edit') {
        $stmtC->bind_param('ssi', $journal, $article, $job_id);
    } else {
        $stmtC->bind_param('ss', $journal, $article);
    }
    $stmtC->execute();
    $stmtC->bind_result($dupCount);
    $stmtC->fetch();
    $stmtC->close();
    
    if ($dupCount > 0) {
        redirect_to(['msg' => 'Journal and Article combination already exists.', 'type' => 'danger']);
    }

    if ($action === 'add') {
        // Validation for required fields
        if (!$client_id || !$journal || !$article || !$process_id) {
            redirect_to(['msg' => 'Please fill all required fields', 'type' => 'danger']);
        }

        // Get the TL's employee name for assignment
        $uid = $_SESSION['uid'];
        $assigned_to = '';
       // echo "SELECT employee_name, reporting_manager FROM employee_master WHERE employee_id = '$uid'";
    //    die;
        $sql = "SELECT employee_name, reporting_manager FROM employee_master WHERE employee_id = ?";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) die("Error preparing: " . $mysqli->error);
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($row = $res->fetch_assoc()) {
            $assigned_to = $row['employee_name'];
        }
        $stmt->close();

        if (!$assigned_to) {
            redirect_to(['msg' => 'Could not determine assignment', 'type' => 'danger']);
        }

        // Insert job
        $stmt = $mysqli->prepare("
            INSERT INTO jobs (
                job_date, client_id, journal_issue, article, process_id,
                assigned_to, due_date, due_date_ce,
                due_date_le, due_date_pag, due_date_qc
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) die('Error preparing jobs insert: ' . $mysqli->error);

        $stmt->bind_param(
            'sisssssssss',
            $job_date,
            $client_id,
            $journal,
            $article,
            $process_id,
            $assigned_to,
            $due_date,
            $due_date_ce,
            $due_date_le,
            $due_date_pag,
            $due_date_qc
        );

        if (!$stmt->execute()) die('Jobs insert failed: ' . $stmt->error);
        $stmt->close();

        $job_id = $mysqli->insert_id;

        // Get client name
        $client_name = '';
        $stmt = $mysqli->prepare("SELECT client_name FROM client_master WHERE client_id = ?");
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $stmt->bind_result($client_name);
        $stmt->fetch();
        $stmt->close();

        // Get process name
        $process_name = '';
        $stmt = $mysqli->prepare("SELECT job_name FROM job_master WHERE job_id = ?");
        $stmt->bind_param("i", $process_id);
        $stmt->execute();
        $stmt->bind_result($process_name);
        $stmt->fetch();
        $stmt->close();

        // Insert CE and LE if process_id in [1,4,5,37]
        if (in_array($process_id, [1, 4, 5, 37])) {
            // CE
            $stmt = $mysqli->prepare("
                INSERT INTO ce (job_id, date, client, issue, articles, process, status, created_at, last_updated)
                VALUES (?, ?, ?, ?, ?, ?, 'Need to Assign', NOW(), NOW())
            ");
            $stmt->bind_param("isssss", $job_id, $job_date, $client_name, $journal, $article, $process_name);
            $stmt->execute();
            $stmt->close();

            // LE
            $stmt = $mysqli->prepare("
                INSERT INTO le (job_id, date, client, issue, articles, process, status, created_at, last_updated)
                VALUES (?, ?, ?, ?, ?, ?, 'Need to Assign', NOW(), NOW())
            ");
            $stmt->bind_param("isssss", $job_id, $job_date, $client_name, $journal, $article, $process_name);
            $stmt->execute();
            $stmt->close();
        }

        // Insert PAG and QC if process_id in [2,4,5]
        if (in_array($process_id, [2, 4, 5])) {
            // PAG
            $stmt = $mysqli->prepare("
                INSERT INTO pag (job_id, date, client, issue, articles, process, status, created_at, last_updated)
                VALUES (?, ?, ?, ?, ?, ?, 'Need to Assign', NOW(), NOW())
            ");
            $stmt->bind_param("isssss", $job_id, $job_date, $client_name, $journal, $article, $process_name);
            $stmt->execute();
            $stmt->close();

            // QC
            $stmt = $mysqli->prepare("
                INSERT INTO qc 
                (job_id, date, client, journal_issue, article, process, status, created_at, last_updated)
                VALUES (?, ?, ?, ?, ?, ?, 'Need to Assign', NOW(), NOW())
            ");
            $stmt->bind_param("isssss", $job_id, $job_date, $client_name, $journal, $article, $process_name);
            $stmt->execute();
            $stmt->close();
        }

        redirect_to(['msg' => 'Job added successfully', 'type' => 'success']);
    }
    elseif ($action === 'edit') {
        // Edit logic for TL
        $assigned_to = $_POST['assigned_to'] ?? '';
        if (!$assigned_to) {
            redirect_to(['msg' => 'Please assign TM', 'type' => 'danger']);
        }
        
        // Update the job record in the database
        $stmt = $mysqli->prepare("UPDATE jobs SET job_date=?, client_id=?, journal_issue=?, article=?, process_id=?, assigned_to=?, due_date=? WHERE job_id=?");
        if (!$stmt) die('Error preparing update: ' . $mysqli->error);
        $stmt->bind_param('sisssssi', $job_date, $client_id, $journal, $article, $process_id, $assigned_to, $due_date, $job_id);
        if (!$stmt->execute()) {
            die('Update failed: ' . $stmt->error);
        }
        $stmt->close();
        
        redirect_to(['msg' => 'Job updated successfully', 'type' => 'success']);

    } elseif ($action === 'delete') {
        // Delete a job record
        $stmt = $mysqli->prepare("DELETE FROM jobs WHERE job_id = ?");
        if (!$stmt) die('Error preparing delete: ' . $mysqli->error);
        $stmt->bind_param('i', $job_id);
        if (!$stmt->execute()) {
            die('Deletion failed: ' . $stmt->error);
        }
        $stmt->close();
        redirect_to(['msg' => 'Job deleted', 'type' => 'warning']);
    }
}

// --- Main query to fetch job list for TL ---
$where_clauses = [];
$params = [];
$types = '';

// Optional client name search filter
if (!empty($search_client)) {
    $where_clauses[] = "(c.client_name LIKE ? OR c.team_client_name LIKE ?)";
    $params[] = "%$search_client%";
    $params[] = "%$search_client%";
    $types .= 'ss';
}

// Build the final WHERE clause string
$where_sql = '';
if ($where_clauses) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

// The main SQL query with joins to get client and process names and calculate status
$sql = "SELECT j.job_id, j.job_date, j.journal_issue, j.article, j.assigned_to, 
               j.assigned_ce, j.assigned_le, j.assigned_pag, j.assigned_qc, j.assigned_xml, 
               j.due_date, j.process_id, c.client_name, c.team_client_name, p.job_name,
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

// Prepare and execute the query, binding parameters if necessary
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

// Check for edit mode and load job data if requested
$mode = $_GET['mode'] ?? '';
$edit_id = (int)($_GET['id'] ?? 0);
$edit_job = null;

if ($mode === 'edit' && $edit_id > 0) {
    $stmt = $mysqli->prepare("SELECT * FROM jobs WHERE job_id = ? LIMIT 1");
    if (!$stmt) die('Error preparing edit query: ' . $mysqli->error);
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_job = $result->fetch_assoc();
    $stmt->close();
}

require 'header.php';
?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <style>
        .btn-brown { background-color: brown !important; color: white !important; }
        .compact-form { max-height: 80vh; overflow-y: auto; }
        .text-warning { color: #f39c12 !important; }
        .text-success { color: #27ae60 !important; }
    </style>

    <div class="container-fluid py-3">
        <!-- Display status messages from the redirect_to function -->
        <?php if (!empty($_GET['msg'])): ?>
            <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show">
                <?= h($_GET['msg']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Form for adding/editing jobs -->
        <?php if ($mode === 'add' || $mode === 'edit'): ?>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5><?= $mode === 'edit' ? 'Edit' : 'Add' ?> Job</h5>
                <a href="jobs.php" class="btn btn-sm btn-secondary">Back</a>
            </div>

            <div class="card mx-auto compact-form" style="max-width:700px;">
                <div class="card-body">
                    <form method="post" autocomplete="off">
                        <input type="hidden" name="action" value="<?= $mode ?>" />
                        <?php if ($mode === 'edit'): ?>
                            <input type="hidden" name="job_id" value="<?= (int)$edit_job['job_id'] ?>" />
                        <?php endif; ?>

                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small">Job Date</label>
                                <input type="date" name="job_date" class="form-control form-control-sm" required value="<?= h($mode === 'edit' ? $edit_job['job_date'] : date('Y-m-d')) ?>" />
                            </div>
                        </div>
                        
                        <div class="mb-2">
                            <label class="form-label small">Client Name *</label>
                            <select name="client_id" class="form-select form-select-sm" required>
                                <option value="">Select Client</option>
                                <?php $clients_res->data_seek(0); while ($client = $clients_res->fetch_assoc()): ?>
                                    <option value="<?= $client['client_id'] ?>" <?= ($mode === 'edit' && $edit_job['client_id'] == $client['client_id']) ? 'selected' : '' ?>><?= h(!empty($client['team_client_name']) ? $client['team_client_name'] : $client['client_name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small">Journal/Issue *</label>
                                <input type="text" name="journal_issue" class="form-control form-control-sm" required value="<?= h($mode === 'edit' ? $edit_job['journal_issue'] : '') ?>" />
                            </div>
                            <div class="col-6">
                                <label class="form-label small">Article *</label>
                                <input type="text" name="article" class="form-control form-control-sm" required value="<?= h($mode === 'edit' ? $edit_job['article'] : '') ?>" />
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small">Process *</label>
                            <select name="process_id" class="form-select form-select-sm" required>
                                <option value="">Select Process</option>
                                <?php foreach ($processes as $pid => $pname): ?>
                                    <option value="<?= $pid ?>" <?= ($mode === 'edit' && $edit_job['process_id'] == $pid) ? 'selected' : '' ?>><?= h($pname) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="d-flex gap-2 pt-2">
                            <button type="submit" class="btn btn-sm btn-brown"><?= $mode === 'edit' ? 'Update' : 'Save' ?></button>
                            <a href="jobs.php" class="btn btn-sm btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

        <!-- Main job list display -->
        <?php else: ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5>Jobs</h5>
                <!--a href="jobs.php?mode=add" class="btn btn-sm btn-success"><i class="fa fa-plus"></i> Add Job</a-->
            </div>

            <!-- Filter form -->
            <form method="get" class="row gy-2 gx-2 mb-3 align-items-end">
                <div class="col-md-4">
                    <input type="text" name="search_client" value="<?= h($search_client) ?>" placeholder="Search by client" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
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
                <div class="col-md-3">
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
                                    <th>S.NO</th>
                                    <th>Date</th>
                                    <th>Client</th>
                                    <th>Journal/Issue</th>
                                    <th>Article</th>
                                    <th>Process</th>
                                    <th>Job Assign</th>
                                    <th>Status</th>
                                    <th>Due Date</th>
                                    <!--th>Actions</th-->
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
                                        <td><?= h(!empty($job['team_client_name']) ? $job['team_client_name'] : $job['client_name']) ?></td>
                                        <td><?= h($job['journal_issue']) ?></td>
                                        <td><?= h($job['article']) ?></td>
                                        <td><?= h($job['job_name']) ?></td>
                                        <td>
                                            <?php
                                            // Display a badge for each job stage with the assigned TM
                                            $process_name = $job['job_name'];
                                            $norm_proc = normalize_process($process_name);
                                            $stages = $processStageMap[$norm_proc] ?? [];

                                            // Define colors for each stage
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
                                                <span class="badge <?= $bg_class ?>" style="padding:6px 10px; font-size:0.85rem;">
                                                    <?= $display_text ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td>
                                            <?php
                                            // Determine badge color based on status
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
                                        <td><?= formatDate($job['due_date']) ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <!--a href="jobs.php?mode=edit&id=<?= (int)$job['job_id'] ?>" class="btn btn-warning" title="Edit"><i class="fa fa-edit"></i></a-->
                                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this job?');">
                                                    <input type="hidden" name="action" value="delete" />
                                                    <input type="hidden" name="job_id" value="<?= (int)$job['job_id'] ?>" />
                                                    <!--button type="submit" class="btn btn-danger" title="Delete"><i class="fa fa-trash"></i></button-->
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <tr>
                                        <td colspan="10" class="text-center py-3">
                                            No jobs found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>