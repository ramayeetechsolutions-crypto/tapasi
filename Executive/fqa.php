<br><br><br>
<?php
require 'db.php';
session_start();

function h($s) { return htmlspecialchars($s, ENT_QUOTES); }

$current_user = $_SESSION['username'] ?? '';
if (!$current_user) {
    echo "<div class='alert alert-danger'>Session expired. Please log in again.</div>";
    exit;
}

// Get employee id
$stmt = $mysqli->prepare("SELECT employee_id FROM employee_master WHERE employee_username=?");
if (!$stmt) die("Prepare failed: " . $mysqli->error);
$stmt->bind_param("s", $current_user);
$stmt->execute();
$stmt->bind_result($employee_id);
$stmt->fetch();
$stmt->close();

// --- Handle form submission (INSERT/UPDATE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_ce') {
    
    $ce_id = intval($_POST['id'] ?? 0);
    $job_id = intval($_POST['job_id'] ?? 0);

    if ($job_id <= 0) {
        header("Location: fqa.php?msg=" . urlencode("Invalid job selected.") . "&type=danger");
        exit;
    }
    
    // Parse inputs
    $status = $_POST['status'] ?? 'In Progress';
    $eq = $_POST['eq'] ?? '';
    $images = $_POST['images'] ?? 'N/A';
    $tables = $_POST['tables_count'] ?? 'N/A';
    $word_count = $_POST['word_count'] ?? 0;
    $ref = trim($_POST['ref'] ?? 'N/A');
    
    $tat = null;

    // 1. Fetch job details necessary for INSERT/UPDATE (including due date for TAT)
    $stmt = $mysqli->prepare("
        SELECT j.job_date, c.client_name, j.journal_issue, jm.job_name, j.due_date_ce
        FROM jobs j
        JOIN client_master c ON j.client_id = c.client_id
        JOIN job_master jm ON j.process_id = jm.job_id
        WHERE j.job_id=?");
    $stmt->bind_param("i", $job_id);
    $stmt->execute();
    $stmt->bind_result($job_date, $client_name, $journal_issue, $process_name, $due_date_ce);
    $stmt->fetch();
    $stmt->close();

    // 2. TAT Calculation Logic (Only if submitted status is 'Completed')
   // ...
    // 2. TAT Calculation Logic (Only if submitted status is 'Completed')
    if ($status === 'Completed') {
        // Use the current server time for TAT calculation reference
        $current_datetime = date('Y-m-d H:i:s');

        if ($due_date_ce) {
            try {
                // IMPORTANT: Calculate the difference between the Due Date and the Completion Time (NOW).
                // TAT = (Due Date - Completion Date). A positive result means early completion.
                // OR: TAT = (Completion Date - Due Date). A negative result means late completion.
                // We will use the second approach to show days late (positive) or days early (negative).
                
                $due_date = new DateTime($due_date_ce);
                $now = new DateTime($current_datetime);
                
                // Calculate difference: Completion Date - Due Date. 
                // A positive value means the completion was AFTER the due date (LATE).
                // A negative value means the completion was BEFORE the due date (EARLY).
                $interval = $due_date->diff($now);
                
                // %R: Sign (+ or -). %a: Total number of days.
                // We want the total difference in days. We flip the sign logic for clarity:
                // If $now > $due_date, the interval is positive. We want LATE completion to be POSITIVE TAT days.
                // If $now < $due_date, the interval is negative. We want EARLY completion to be NEGATIVE TAT days.
                
                // Since diff($date1, $date2) is usually date2 - date1, let's use the absolute days and apply the sign manually
                // or just rely on the formatted output.
                
                // The format '%R%a' gives the signed difference relative to the *interval direction*.
                // To reliably get (Completion Date - Due Date) difference, we use the simpler method:
                
                $diff_seconds = $now->getTimestamp() - $due_date->getTimestamp();
                // Convert seconds to days (86400 seconds in a day)
                $tat = round($diff_seconds / 86400); 
                
                // This 'tat' now correctly represents:
                // TAT > 0: Days LATE
                // TAT < 0: Days EARLY
                // TAT = 0: On time (within 12 hours of the due date, due to rounding)
                
            } catch (Exception $e) {
                error_log("Date calculation error: " . $e->getMessage());
                $tat = 0;
            }
        }
    }
// ... 
    if ($ce_id > 0) {
        // --- UPDATE CE ---
        $sql = "UPDATE ce SET 
            eq=?, 
            images=?, tables_count=?, word_count=?, ref=?, 
            status=?, last_updated=NOW()";
        
        $types = "ssssss";
        $params = [
            $eq,
            $images, $tables, $word_count, $ref, $status
        ];

        if ($status === 'Completed') {
            // Use SQL functions and PHP variables for completed status fields
            $sql .= ", done_by=?, tat=?, ce_date=NOW()";
            $types .= "ss";
            $params[] = $current_user;
            $params[] = $tat;
        }
        
        $sql .= " WHERE id=?";
        $types .= "i";
        $params[] = $ce_id;
        
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) die("Prepare failed (UPDATE): " . $mysqli->error);
        
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
        
    } else {
        // --- INSERT CE (First-time entry) ---
        
        // Determine done_by, tat, and ce_date placeholder for INSERT
        $insert_done_by = ($status === 'Completed') ? $current_user : null;
        $insert_tat = ($status === 'Completed') ? $tat : null;
        
        $fields = "job_id, date, client, issue, process, eq, images, tables_count, done_by, word_count, ref, tat, status, created_at, last_updated";
        $values = "?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()";
        $types_insert = "issssssissdss"; // Corrected parameter types

        $params_insert = [
            $job_id, $job_date, $client_name, $journal_issue, $process_name, $eq,
            $images, $tables, $insert_done_by, $word_count, $ref, $insert_tat, $status
        ];
        
        if ($status === 'Completed') {
            $fields .= ", ce_date";
            $values .= ", NOW()";
        }

        $sql = "INSERT INTO ce ($fields) VALUES ($values)";

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) die("Prepare failed (INSERT): " . $mysqli->error);

        // Bind parameters for INSERT
        $stmt->bind_param($types_insert, ...$params_insert);

        if (!$stmt->execute()) {
            die("Execute failed (INSERT): " . $stmt->error);
        }
        $stmt->close();
    }
    
    // ALWAYS update jobs table to assign this executive to CE stage when they save
    $stmt = $mysqli->prepare("UPDATE jobs SET assigned_ce = ? WHERE job_id = ?");
    $stmt->bind_param("si", $current_user, $job_id);
    $stmt->execute();
    $stmt->close();

    header("Location: fqa.php?msg=" . urlencode("CE record saved successfully.") . "&type=success");
    exit;
}

// --- Fetch and Display Logic ---
$q = trim($_GET['q'] ?? '');
$filter_status = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'job_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

$sortable = ['job_id', 'job_date', 'client_name', 'journal_issue', 'process', 'status', 'ce_date'];
if (!in_array($sort, $sortable, true)) $sort = 'job_date';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

// Build query
$sql = "SELECT j.job_id, j.job_date, c.client_name, j.journal_issue, j.article, jm.job_name as process, j.due_date,
    ce.id as ce_id, ce.eq, ce.images, ce.tables_count, ce.done_by, ce.word_count, ce.ref, ce.tat, ce.status, ce.ce_date, ce.created_at,
    j.assigned_ce
FROM jobs j
JOIN client_master c ON j.client_id = c.client_id
JOIN job_master jm ON j.process_id = jm.job_id
LEFT JOIN ce ON ce.job_id = j.job_id";

$cond = [];
$params = [];
$types = '';

// Show only CE/CE+PAG jobs AND (unassigned OR assigned to current user)
$cond[] = "(jm.job_name LIKE '%CE%' OR jm.job_name = 'CE')";
$cond[] = "(j.assigned_ce IS NULL OR j.assigned_ce = '' OR j.assigned_ce = ?)";
$params[] = $current_user;
$types .= 's';

if ($q !== '') {
    $cond[] = "(c.client_name LIKE ? OR j.journal_issue LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

if (in_array($filter_status, ['In Progress', 'Completed', 'Need to Assign'])) {
    if ($filter_status === 'Need to Assign') {
        $cond[] = "ce.id IS NULL";
    } else {
        $cond[] = "ce.status = ?";
        $params[] = $filter_status;
        $types .= 's';
    }
}

if ($cond) {
    $sql .= ' WHERE ' . implode(' AND ', $cond);
}
$sql .= " ORDER BY $sort $dir";

if ($types) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) die("Prepare failed: " . $mysqli->error);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $results = $stmt->get_result();
} else {
    $results = $mysqli->query($sql);
}
?>
<?php require 'header.php';?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" />
<style>
    .modal-dialog { max-width: 420px; }
    label { font-size: 0.9rem; }
    input, select { font-size: 0.9rem; padding: 5px 7px; }
    .btn-brown { background-color: brown !important; color: white !important; border: none; }
    .btn-brown:hover { background-color: #5C2E0C !important; }
    .btn-edit { background: #ffc107; color: black; border-radius: 5px; }
    .btn-edit:hover { background: #e0a800; }
    table td, th { vertical-align: middle; padding: 7px 10px; font-size: 0.9rem; }
    .text-warning { color: #f39c12 !important; }
    .text-success { color: #27ae60 !important; }
</style>
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5>My CE Jobs</h5>
    </div>

    <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="get" class="row gy-2 gx-2 mb-3 align-items-center">
        <div class="col-auto">
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search client name or journal issue" autocomplete="off" class="form-control form-control-sm" />
        </div>
        <div class="col-auto">
            <select name="status" class="form-select form-select-sm">
                <option value="">All Status</option>
                <option value="Need to Assign" <?= $filter_status === 'Need to Assign' ? 'selected' : '' ?>>Need to Assign</option>
                <option value="In Progress" <?= $filter_status === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                <option value="Completed" <?= $filter_status === 'Completed' ? 'selected' : '' ?>>Completed</option>
            </select>
        </div>
        <div class="col-auto">
            <select name="sort" class="form-select form-select-sm">
                <?php foreach ($sortable as $s): ?>
                    <option value="<?= $s ?>" <?= $sort === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <!-- <div class="col-auto">
            <select name="dir" class="form-select form-select-sm">
                <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Asc</option>
                <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Desc</option>
            </select>
        </div> -->
        <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-sm">Go</button>
            <a href="fqa.php" class="btn btn-secondary btn-sm">Clear</a>
        </div>
    </form>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th><th>Date</th><th>Client</th><th>Journal/Issue</th><th>Article ID</th><th>Process</th>
                            <th>EQ</th><th>Images</th><th>Tables</th><th>Done By</th><th>Word Count</th>
                            <th>Ref</th><th>TAT</th><th>Status</th><th>CE Date</th><th>Start Time</th><th>End Time</th><th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($results && $results->num_rows > 0): ?>
                            <?php while ($row = $results->fetch_assoc()): ?>
                                <?php
                                // Display logic
                                // 
                                // Micro-Detailed Display Logic
                                $ce_date_display = '—';
                                $start_time_display = '—';
                                $end_time_display = '—';

                                if (empty($row['ce_id'])) {
                                    // Row logic for jobs not yet started
                                    $display_status = 'Need to Assign'; 
                                    $status_class = 'text-warning';
                                    $editable = true;
                                    $done_by_display = h($row['assigned_ce'] ?? '—'); 
                                } else {
                                    // Row logic for jobs in progress or completed
                                    $display_status = $row['status'] ?? 'In Progress';
                                    $status_class = $display_status === 'Completed' ? 'text-success' : 'text-primary';
                                    $editable = $display_status !== 'Completed';
                                    $done_by_display = h($row['done_by'] ?? $row['assigned_ce'] ?? '—'); 
                                    
                                    // A. Calculate CE Date (Date only) and End Time (Date + Hour)
                                    if (!empty($row['ce_date']) && $row['ce_date'] !== '0000-00-00 00:00:00' && $row['ce_date'] !== '0000-00-00') {
                                        $dtEnd = DateTime::createFromFormat('Y-m-d H:i:s', $row['ce_date']);
                                        if (!$dtEnd) $dtEnd = DateTime::createFromFormat('Y-m-d', $row['ce_date']); 
                                        
                                        $ce_date_display = $dtEnd ? $dtEnd->format('d-m-Y') : $row['ce_date'];
                                        $end_time_display = $dtEnd ? $dtEnd->format('d-m-Y H:i') : $row['ce_date'];
                                    }

                                    // B. Calculate Start Time (Date + Hour from created_at)
                                    if (!empty($row['created_at']) && $row['created_at'] !== '0000-00-00 00:00:00') {
                                        $dtStart = DateTime::createFromFormat('Y-m-d H:i:s', $row['created_at']);
                                        $start_time_display = $dtStart ? $dtStart->format('d-m-Y H:i') : $row['created_at'];
                                    }
                                }
                                
                                $job_date_formatted = '—';
                                if (!empty($row['job_date'])) {
                                    $dt = DateTime::createFromFormat('Y-m-d', $row['job_date']);
                                    $job_date_formatted = $dt ? $dt->format('d-m-Y') : $row['job_date'];
                                }
                                ?>
                                <tr>
                                    <td><?= (int)$row['job_id'] ?></td>
                                    <td><?= $job_date_formatted ?></td>
                                    <td><?= h($row['client_name']) ?></td>
                                    <td><?= h($row['journal_issue']) ?></td>
                                    <td><?= h($row['article'] ?? '—') ?></td>
                                    <td><?= h($row['process']) ?></td>
                                    <td><?= h($row['eq'] ?? '—') ?></td>
                                    <td><?= h($row['images'] ?? '_') ?></td>
                                    <td><?= h($row['tables_count'] ?? '_') ?></td>
                                    <td><?= h($done_by_display) ?></td>
                                    <td><?= intval($row['word_count'] ?? 0) ?></td>
                                    <td><?= h($row['ref'] ?? 'N/A') ?></td>
                                    <td><?= h($row['tat'] ?? '—') ?></td>
                                    <td><span class="<?= $status_class ?>"><?= h($display_status) ?></span></td>
                                    <td><?= $ce_date_display ?></td>
                                    <td><?= $start_time_display ?></td>
                                    <td><?= $end_time_display ?></td>
                                    <td class="text-end">
                                        <?php //if ($editable): ?>
                                         <button class="btn btn-sm btn-primary edit-btn"
        data-bs-toggle="modal" data-bs-target="#editModal"
        data-id="<?= $row['ce_id'] ?? 0 ?>"
        data-job_id="<?= $row['job_id'] ?>"
        data-eq="<?= h($row['eq'] ?? '0') ?>"
        data-images="<?= h($row['images'] ?? '0') ?>"
        data-tables_count="<?= h($row['tables_count'] ?? '0') ?>"
        data-word_count="<?= h($row['word_count'] ?? '0') ?>"
        data-ref="<?= h($row['ref'] ?? 'N/A') ?>"
        data-status="<?= h($row['status'] ?? 'In Progress') ?>"
        title="Manage"><i class="bi bi-gear"></i> Manage </button>
        <?php //else: ?>
                                            <!--span class="badge bg-success">Completed</span-->
                                        <?php //endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="16" class="text-center py-4">No CE jobs found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
<div class="modal-dialog">
    <form id="editForm" method="post" class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title" id="editModalLabel">Edit CE Entry</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" name="action" value="update_ce">
            <input type="hidden" name="id" id="ceId">
            <input type="hidden" name="job_id" id="jobId">

            <div class="row g-2">
                <div class="col-12">
                    <label for="eq" class="form-label">EQ *</label>
                    <input type="text" class="form-control form-control-sm" id="eq" name="eq" required>
                </div>
                <div class="col-6">
                    <label for="images" class="form-label">Images</label>
                    <input type="text"  class="form-control form-control-sm" id="images" name="images">
                </div>
                <div class="col-6">
                    <label for="tables_count" class="form-label">Tables</label>
                    <input type="text"  class="form-control form-control-sm" id="tables_count" name="tables_count">
                </div>
                <div class="col-12">
                    <label for="word_count" class="form-label">Word Count</label>
                    <input type="number"  class="form-control form-control-sm" id="word_count" name="word_count">
                </div>
                <div class="col-12">
                    <label for="ref" class="form-label">Reference</label>
                    <input type="text" class="form-control form-control-sm" id="ref" name="ref" value="N/A">
                </div>
                <div class="col-12">
                    <label for="status" class="form-label">Status *</label>
                    <select class="form-select form-select-sm" id="status" name="status" required>
                        <option value="In Progress">In Progress</option>
                        <option value="Completed">Completed</option>
                    </select>
                </div>
                <div class="col-12">
                    <small class="text-muted">Set Status to **Completed** to auto-calculate CE Date and TAT.</small>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="submit" class="btn btn-brown btn-sm">Save Changes</button>
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        </div>
    </form>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
   // Handles populating the modal fields when the "Manage" button is clicked
document.querySelectorAll('.edit-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
        document.getElementById('ceId').value = btn.dataset.id || '0';
        document.getElementById('jobId').value = btn.dataset.job_id || '';
        document.getElementById('eq').value = btn.dataset.eq || '';
        document.getElementById('images').value = btn.dataset.images || '0';
        document.getElementById('tables_count').value = btn.dataset.tables_count || '0';
        document.getElementById('word_count').value = btn.dataset.word_count || '0';
        document.getElementById('ref').value = btn.dataset.ref || 'N/A';
        document.getElementById('status').value = btn.dataset.status || 'In Progress';
    });
});
</script>