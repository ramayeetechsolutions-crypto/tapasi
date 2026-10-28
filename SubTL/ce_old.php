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

// Handle form submission (insert/update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_ce') {
    $id = intval($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'In Progress';

    // Parse inputs
    $eq = $_POST['eq'] ?? '';
    $images = intval($_POST['images'] ?? 0);
    $tables = intval($_POST['tables_count'] ?? 0);
    $word_count = intval($_POST['word_count'] ?? 0);
    $ref = trim($_POST['ref'] ?? 'N/A');
    
    if ($id > 0) {
        // get job_id for this CE record
        $stmt = $mysqli->prepare("SELECT job_id FROM ce WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->bind_result($job_id);
        $stmt->fetch();
        $stmt->close();
    } else {
        $job_id = intval($_POST['job_id'] ?? 0);
        if ($job_id <= 0) {
            header("Location: ce.php?msg=" . urlencode("Invalid job selected.") . "&type=danger");
            exit;
        }
    }

    // Fetch job details and calculate TAT
    $stmt = $mysqli->prepare("SELECT j.job_date, c.client_name, j.journal_issue, jm.job_name, j.due_date_ce 
        FROM jobs j 
        JOIN client_master c ON j.client_id = c.client_id 
        JOIN job_master jm ON j.process_id = jm.job_id 
        WHERE j.job_id=?");
    $stmt->bind_param("i", $job_id);
    $stmt->execute();
    $stmt->bind_result($job_date, $client_name, $journal_issue, $process_name, $due_date_ce);
    $stmt->fetch();
    $stmt->close();
if ($status == 'Completed') {
    $ce_date = date('Y-m-d H:i:s');
    $done_by = $employee_id;
    
    // Convert strings to DateTime first
    $due_datetime = new DateTime($due_date_ce);
    $ce_datetime  = new DateTime($ce_date);

    // Get only the date parts (ignore time)
    $due_date_only = new DateTime($due_datetime->format('Y-m-d'));
    $ce_date_only  = new DateTime($ce_datetime->format('Y-m-d'));

    // Calculate TAT (calendar days)
    $tat = $ce_date_only->diff($due_date_only)->days;

    // If CE date is after due date, make TAT negative
 //   if ($ce_datetime > $due_datetime) {
  //      $tat = -$tat;
//    }

    // Debug output
  //  echo "CE Date: " . $ce_date_only->format('Y-m-d') . "<br>";
//    echo "Due Date: " . $due_date_only->format('Y-m-d') . "<br>";
 //   echo "TAT: " . $tat . " days<br>";
  //  die;
}

    if ($id > 0) {
        // UPDATE CE
        $stmt = $mysqli->prepare("UPDATE ce SET 
            date=?, client=?, issue=?, process=?, eq=?, 
            images=?, tables_count=?, done_by=?, word_count=?, 
            ref=?, tat=?, status=?, ce_date=?, last_updated=NOW() 
            WHERE id=?");
        if (!$stmt) die("Prepare failed (UPDATE): " . $mysqli->error);

        $stmt->bind_param(
            "sssssiiisisssi",
            $job_date,
            $client_name,
            $journal_issue,
            $process_name,
            $eq,
            $images,
            $tables,
            $done_by,
            $word_count,
            $ref,
            $tat,
            $status,
            $ce_date,
            $id
        );
        $stmt->execute();
        $stmt->close();
        
    } else {
      // INSERT CE
$stmt = $mysqli->prepare("
    INSERT INTO ce 
        (job_id, date, client, issue, process, eq, images, tables_count, 
         done_by, word_count, ref, tat, status, ce_date, created_at, last_updated) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
");
if (!$stmt) {
    die("Prepare failed (INSERT): " . $mysqli->error);
}

// Bind params
$stmt->bind_param(
    "isssssiisissss",
    $job_id,        // i : job_id (INT)
    $job_date,      // s : date (DATE)
    $client_name,   // s : client (VARCHAR)
    $journal_issue, // s : issue (VARCHAR)
    $process_name,  // s : process (VARCHAR)
    $eq,            // s : eq (VARCHAR)
    $images,        // i : images (INT)
    $tables,        // i : tables_count (INT)
    $done_by,       // s : done_by (VARCHAR - username or emp code)
    $word_count,    // i : word_count (INT)
    $ref,           // s : ref (VARCHAR)
    $tat,           // s : tat (VARCHAR or DATE stored as string)
    $status,        // s : status (VARCHAR)
    $ce_date        // s : ce_date (DATE)
);

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

    header("Location: ce.php?msg=" . urlencode("CE record saved successfully.") . "&type=success");
    exit;
}

// Handle filters/sorting
$q = trim($_GET['q'] ?? '');
$filter_status = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'job_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

$sortable = ['job_id', 'job_date', 'client_name', 'journal_issue', 'process', 'status', 'ce_date'];
if (!in_array($sort, $sortable, true)) $sort = 'job_date';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

// Build query - CORRECTED: Only show unassigned jobs OR jobs assigned to current user
$sql = "SELECT j.job_id, j.job_date, c.client_name, j.journal_issue, j.article, jm.job_name as process, j.due_date,
    ce.id as ce_id, ce.eq, ce.images, ce.tables_count, ce.done_by, ce.word_count, ce.ref, ce.tat, ce.status, ce.ce_date,
    j.assigned_ce
FROM jobs j
JOIN client_master c ON j.client_id = c.client_id
JOIN job_master jm ON j.process_id = jm.job_id
LEFT JOIN ce ON ce.job_id = j.job_id";

$cond = [];
$params = [];
$types = '';

// FIXED: Show only CE/CE+PAG jobs AND (unassigned OR assigned to current user)
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

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>My CE Jobs</title>
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
</head>
<body>
<div class="container mt-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h5>My CE Jobs</h5>
  </div>

  <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
      <?= h($_GET['msg']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Filter Form -->
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
    <div class="col-auto">
      <select name="dir" class="form-select form-select-sm">
        <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Asc</option>
        <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Desc</option>
      </select>
    </div>
    <div class="col-auto">
      <button type="submit" class="btn btn-primary btn-sm">Go</button>
      <a href="ce.php" class="btn btn-secondary btn-sm">Clear</a>
    </div>
  </form>

  <!-- Table -->
  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>ID</th><th>Date</th><th>Client</th><th>Journal/Issue</th><th>Article ID</th><th>Process</th>
              <th>EQ</th><th>Images</th><th>Tables</th><th>Done By</th><th>Word Count</th>
              <th>Ref</th><th>TAT</th><th>Status</th><th>CE Date</th><th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($results && $results->num_rows > 0): ?>
              <?php while ($row = $results->fetch_assoc()): ?>
                <?php
                // Determine status and display info
                if (empty($row['ce_id'])) {
                    // Job not taken by anyone yet (Need to Assign)
                    $display_status = 'Need to Assign';
                    $status_class = 'text-warning';
                    $editable = true;
                    $done_by_display = '—';
                    $ce_date_display = '—';
                } else {
                    // Job has CE entry (taken by current user)
                    $display_status = $row['status'] ?? 'In Progress';
                    $status_class = $display_status === 'Completed' ? 'text-success' : 'text-primary';
                    $editable = $display_status !== 'Completed';
                    
                    // Get done by username (should be current user)
                    $done_by_display = $current_user;
                    
                    // Format CE date
                    $ce_date_display = '—';
                    if (!empty($row['ce_date']) && $row['ce_date'] !== '0000-00-00') {
                        $dt = DateTime::createFromFormat('Y-m-d', $row['ce_date']);
                        $ce_date_display = $dt ? $dt->format('d-m-Y') : $row['ce_date'];
                    }
                }
                
                // Format job date
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
                  <td><?= intval($row['images'] ?? 0) ?></td>
                  <td><?= intval($row['tables_count'] ?? 0) ?></td>
                  <td><?= h($done_by_display) ?></td>
                  <td><?= intval($row['word_count'] ?? 0) ?></td>
                  <td><?= h($row['ref'] ?? 'N/A') ?></td>
                  <td><?= intval($row['tat'] ?? 0) ?></td>
                  <td><span class="<?= $status_class ?>"><?= h($display_status) ?></span></td>
                  <td><?= $ce_date_display ?></td>
                  <td class="text-end">
                    <?php if ($editable): ?>
                      <button class="btn btn-sm btn-primary edit-btn"
                              data-bs-toggle="modal" data-bs-target="#editModal"
                              data-id="<?= $row['ce_id'] ?? 0 ?>"
                              data-job_id="<?= $row['job_id'] ?>"
                              data-eq="<?= h($row['eq'] ?? '') ?>"
                              data-images="<?= intval($row['images'] ?? 0) ?>"
                              data-tables_count="<?= intval($row['tables_count'] ?? 0) ?>"
                              data-word_count="<?= intval($row['word_count'] ?? 0) ?>"
                              data-ref="<?= h($row['ref'] ?? 'N/A') ?>"
                              data-status="<?= h($row['status'] ?? 'In Progress') ?>"
                              title="Manage"><i class="bi bi-gear"></i> Manage </button>
                    <?php else: ?>
                      <span class="badge bg-success">Completed</span>
                    <?php endif; ?>
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

<!-- Edit Modal -->
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
          <input type="number" min="0" class="form-control form-control-sm" id="images" name="images" value="0">
        </div>
        <div class="col-6">
          <label for="tables_count" class="form-label">Tables</label>
          <input type="number" min="0" class="form-control form-control-sm" id="tables_count" name="tables_count" value="0">
        </div>
        <div class="col-12">
          <label for="word_count" class="form-label">Word Count</label>
          <input type="number" min="0" class="form-control form-control-sm" id="word_count" name="word_count" value="0">
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
          <small class="text-muted">TAT will be auto-calculated based on Due Date - CE Date</small>
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
  document.querySelectorAll('.edit-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.getElementById('ceId').value = btn.dataset.id || '';
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
</body>
</html>
