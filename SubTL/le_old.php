<?php
require 'db.php';
session_start();

function h($s) { return htmlspecialchars($s, ENT_QUOTES); }
function formatDate($d) {
    return (!empty($d) && $d != '0000-00-00') ? date('d-m-Y', strtotime($d)) : '—';
}

$current_user = $_SESSION['username'] ?? '';
if (!$current_user) {
    echo "<div class='alert alert-danger'>Session expired. Please log in again.</div>";
    exit;
}

// Get employee id
$stmt = $mysqli->prepare("SELECT employee_id FROM employee_master WHERE employee_username=?");
$stmt->bind_param("s", $current_user);
$stmt->execute();
$stmt->bind_result($employee_id);
$stmt->fetch();
$stmt->close();

// Handle form submission (insert/update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_le') {
    $id = intval($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'In Progress';
    $word_count = intval($_POST['word_count'] ?? 0);
    $ref = trim($_POST['ref'] ?? '');
    $due_date = $_POST['due_date'] ?? null;

    // Ensure ref is never empty
    if (empty($ref) || $ref === '') {
        $ref = 'N/A';
    }

    if ($id > 0) {
        // Get job_id from existing LE record
        $stmt = $mysqli->prepare("SELECT job_id FROM le WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->bind_result($job_id);
        $stmt->fetch();
        $stmt->close();
    } else {
        $job_id = intval($_POST['job_id'] ?? 0);
        if ($job_id <= 0) {
            header("Location: le.php?msg=" . urlencode("Invalid job selected.") . "&type=danger");
            exit;
        }
    }

    // Get CE data (only needed for INSERT, not UPDATE)
    if ($id == 0) {
        $stmt = $mysqli->prepare("SELECT ce.date, ce.client, ce.issue, ce.process, ce.eq, ce.images, ce.tables_count 
            FROM ce WHERE ce.job_id=? AND ce.status='Completed' LIMIT 1");
        $stmt->bind_param("i", $job_id);
        $stmt->execute();
        $stmt->bind_result($job_date, $client_name, $journal_issue, $process_name, $eq, $images, $tables);
        $stmt->fetch();
        $stmt->close();

        if (!$job_date) {
            header("Location: le.php?msg=" . urlencode("CE data not found or not completed.") . "&type=danger");
            exit;
        }
    }

    $le_date = date('Y-m-d');
    
    // Done By is only set when status is Completed
    $done_by = ($status === 'Completed') ? $employee_id : null;
    
    // Calculate TAT (LE due_date - LE assigned date) in days
    if ($due_date) {
        $due_datetime = new DateTime($due_date);
        $le_datetime = new DateTime($le_date);
        $tat = $due_datetime->diff($le_datetime)->days;
        
        // If LE date is after due date, make TAT negative
        if ($le_datetime > $due_datetime) {
            $tat = -$tat;
        }
    } else {
        $tat = 0;
    }

    if ($id > 0) {
        // FIXED UPDATE: Only update the editable fields
        $stmt = $mysqli->prepare("UPDATE le SET 
            done_by=?, word_count=?, ref=?, tat=?, due_date=?, status=?, le_date=?, last_updated=NOW() 
            WHERE id=?");
        if (!$stmt) die("Prepare failed (UPDATE): " . $mysqli->error);

        // Use string types for simplicity to avoid type issues
        $stmt->bind_param(
            "ssssssss",  // All strings to avoid parameter type issues
            $done_by, $word_count, $ref, $tat, $due_date, $status, $le_date, $id
        );
        $stmt->execute();
        $stmt->close();
        
    } else {
       // ===== INSERT CE =====
$stmt = $mysqli->prepare("
    INSERT INTO ce 
        (job_id, date, client, issue, process, eq, images, tables_count, 
         done_by, word_count, ref, tat, status, ce_date, created_at, last_updated) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
");
if (!$stmt) {
    die("Prepare failed (INSERT CE): " . $mysqli->error);
}

$stmt->bind_param(
    "isssssiisissss",
    $job_id,        // i
    $job_date,      // s
    $client_name,   // s
    $journal_issue, // s
    $process_name,  // s
    $eq,            // s
    $images,        // i
    $tables,        // i
    $done_by,       // s (if emp_id → use i)
    $word_count,    // i
    $ref,           // s
    $tat,           // s (if days int → i)
    $status,        // s
    $ce_date        // s (DATE)
);

if (!$stmt->execute()) {
    die("Execute failed (INSERT CE): " . $stmt->error);
}
$stmt->close();


// ===== INSERT LE (initially with CE data) =====
$stmt = $mysqli->prepare("
    INSERT INTO le 
        (job_id, date, client, issue, process, eq, images, tables_count, 
         done_by, word_count, ref, tat, due_date, status, le_date, created_at, last_updated) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
");
if (!$stmt) {
    die("Prepare failed (INSERT LE): " . $mysqli->error);
}

$stmt->bind_param(
    "isssssiisisssss",
    $job_id,        // i
    $job_date,      // s
    $client_name,   // s
    $journal_issue, // s
    $process_name,  // s
    $eq,            // s
    $images,        // i
    $tables,        // i
    $done_by,       // s (if emp_id → use i)
    $word_count,    // i
    $ref,           // s
    $tat,           // s (if int → i)
    $due_date,      // s (DATE)
    $status,        // s
    $le_date        // s (DATE)
);

if (!$stmt->execute()) {
    die("Execute failed (INSERT LE): " . $stmt->error);
}
$stmt->close();
}
    // Update jobs table to assign this executive to LE stage
    $stmt = $mysqli->prepare("UPDATE jobs SET assigned_le = ? WHERE job_id = ?");
    $stmt->bind_param("ss", $current_user, $job_id);
    $stmt->execute();
    $stmt->close();

    header("Location: le.php?msg=" . urlencode("LE record saved successfully.") . "&type=success");
    exit;
}

// Handle filters/sorting
$q = trim($_GET['q'] ?? '');
$filter_status = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'job_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

$sortable = ['job_id', 'job_date', 'client_name', 'journal_issue', 'process', 'status', 'le_date'];
if (!in_array($sort, $sortable, true)) $sort = 'job_date';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

// QUERY: Show jobs where CE is completed AND process has LE stage
$sql = "SELECT j.job_id, j.job_date, c.client_name, j.journal_issue, j.article, jm.job_name as process,
    ce.eq, ce.images, ce.tables_count, ce.tat as ce_tat, ce.ce_date,
    le.id as le_id, le.done_by, le.word_count, le.ref, le.tat, le.due_date as le_due_date, le.status, le.le_date,
    j.assigned_le
FROM jobs j
JOIN client_master c ON j.client_id = c.client_id
JOIN job_master jm ON j.process_id = jm.job_id
JOIN ce ON ce.job_id = j.job_id
LEFT JOIN le ON le.job_id = j.job_id";

$cond = [];
$params = [];
$types = '';

// CONDITIONS:
$cond[] = "ce.status = 'Completed'";
$cond[] = "jm.job_name IN ('CE', 'CE+PAG', 'CE+PAG+XML')";
$cond[] = "(j.assigned_le IS NULL OR j.assigned_le = '' OR j.assigned_le = ?)";
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
        $cond[] = "le.id IS NULL";
    } else {
        $cond[] = "le.status = ?";
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
<meta charset="utf-8">
<title>My LE Jobs</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css">
<style>
  .modal-dialog { max-width: 420px; }
  .btn-brown { background-color: brown !important; color: white !important; border: none; }
  .btn-brown:hover { background-color: #5C2E0C !important; }
  table td, th { vertical-align: middle; padding: 7px 10px; font-size: 0.9rem; }
  .text-warning { color: #f39c12 !important; }
  .text-success { color: #27ae60 !important; }
</style>
</head>
<body>
<div class="container mt-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h5>My LE Jobs</h5>
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
      <a href="le.php" class="btn btn-secondary btn-sm">Clear</a>
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
              <th>EQ</th><th>Images</th><th>Tables</th><th>CE TAT</th><th>CE Date</th><th>Done By</th>
              <th>Word Count</th><th>Due Date</th><th>Ref</th><th>Status</th><th>LE Date</th><th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($results && $results->num_rows > 0): ?>
              <?php while ($row = $results->fetch_assoc()): ?>
                <?php
                // Display logic
                if (empty($row['le_id'])) {
                    // Job not taken by anyone yet (Need to Assign)
                    $display_status = 'Need to Assign';
                    $status_class = 'text-warning';
                    $editable = true;
                    $done_by_display = '—';
                    $le_date_display = '—';
                    $word_count_display = 0;
                    $due_date_display = '—';
                    $ref_display = 'N/A';
                } else {
                    // Job has LE entry - show actual LE data
                    $display_status = $row['status'] ?? 'In Progress';
                    $status_class = $display_status === 'Completed' ? 'text-success' : 'text-primary';
                    $editable = $display_status !== 'Completed';
                    
                    // Show actual LE values from database
                    $word_count_display = intval($row['word_count'] ?? 0);
                    $due_date_display = formatDate($row['le_due_date']);
                    $le_date_display = formatDate($row['le_date']);
                    $ref_display = ($row['ref'] === '0' || $row['ref'] === '' || $row['ref'] === null || $row['ref'] === 'NA') ? 'N/A' : $row['ref'];
                    
                    // Done By only shows when completed
                    $done_by_display = '—';
                    if ($display_status === 'Completed' && !empty($row['done_by'])) {
                        $done_by_display = $current_user;
                    }
                }
                
                // Format dates
                $job_date_formatted = formatDate($row['job_date']);
                $ce_date_formatted = formatDate($row['ce_date']);
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
                  <td><?= intval($row['ce_tat'] ?? 0) ?></td>
                  <td><?= $ce_date_formatted ?></td>
                  <td><?= h($done_by_display) ?></td>
                  <td><?= $word_count_display ?></td>
                  <td><?= $due_date_display ?></td>
                  <td><?= h($ref_display) ?></td>
                  <td><span class="<?= $status_class ?>"><?= h($display_status) ?></span></td>
                  <td><?= $le_date_display ?></td>
                  <td class="text-end">
                    <?php if ($editable): ?>
                      <button class="btn btn-sm btn-warning edit-btn"
                              data-bs-toggle="modal" data-bs-target="#editModal"
                              data-id="<?= $row['le_id'] ?? 0 ?>"
                              data-job_id="<?= $row['job_id'] ?>"
                              data-word_count="<?= intval($row['word_count'] ?? 0) ?>"
                              data-ref="<?= h($ref_display) ?>"
                              data-due_date="<?= $row['le_due_date'] ?? '' ?>"
                              data-status="<?= h($row['status'] ?? 'In Progress') ?>"
                              title="Edit"><i class="bi bi-pencil-square"></i></button>
                    <?php else: ?>
                      <span class="badge bg-success">Completed</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr><td colspan="18" class="text-center py-4">No LE jobs found.</td></tr>
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
      <h5 class="modal-title" id="editModalLabel">Edit LE Entry</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
      <input type="hidden" name="action" value="update_le">
      <input type="hidden" name="id" id="leId">
      <input type="hidden" name="job_id" id="jobId">

      <div class="row g-2">
        <div class="col-6">
          <label for="word_count" class="form-label">Word Count</label>
          <input type="number" min="0" class="form-control form-control-sm" id="word_count" name="word_count" value="0">
        </div>
        <div class="col-6">
          <label for="ref" class="form-label">Ref</label>
          <input type="text" class="form-control form-control-sm" id="ref" name="ref" value="N/A">
        </div>
        <div class="col-6">
          <label for="due_date" class="form-label">Due Date</label>
          <input type="date" class="form-control form-control-sm" id="due_date" name="due_date" required>
        </div>
        <div class="col-6">
          <label for="status" class="form-label">Status</label>
          <select class="form-select form-select-sm" id="status" name="status" required>
            <option value="In Progress">In Progress</option>
            <option value="Completed">Completed</option>
          </select>
        </div>
      </div>
      
      <div class="mt-2">
        <small class="text-muted">TAT will be auto-calculated based on Due Date - LE Date</small>
      </div>
    </div>
    <div class="modal-footer">
      <button type="submit" class="btn btn-brown btn-sm">Save</button>
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
    </div>
  </form>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.querySelectorAll('.edit-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.getElementById('leId').value = btn.dataset.id || '';
      document.getElementById('jobId').value = btn.dataset.job_id || '';
      document.getElementById('word_count').value = btn.dataset.word_count || '0';
      document.getElementById('ref').value = btn.dataset.ref || 'N/A';
      document.getElementById('due_date').value = btn.dataset.due_date || '<?= date('Y-m-d') ?>';
      document.getElementById('status').value = btn.dataset.status || 'In Progress';
    });
  });
</script>
</body>
</html>
