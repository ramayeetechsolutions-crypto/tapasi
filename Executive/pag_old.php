<?php
require 'db.php';
session_start();

function h($s) { return htmlspecialchars($s, ENT_QUOTES); }

function formatDate($date) {
    if (empty($date) || $date == '0000-00-00') { return '—'; }
    return date('d-m-Y', strtotime($date));
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_pag') {
    $id = intval($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'In Progress';
    $pages = intval($_POST['pages'] ?? 0);
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    
    // Get job_id
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT job_id FROM pag WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->bind_result($job_id);
        $stmt->fetch();
        $stmt->close();
    } else {
        $job_id = intval($_POST['job_id'] ?? 0);
        if ($job_id <= 0) {
            header("Location: pag.php?msg=" . urlencode("Invalid job selected.") . "&type=danger");
            exit;
        }
    }

    // Get job info and determine data source
    $stmt = $mysqli->prepare("SELECT j.job_date, c.client_name, j.journal_issue, j.article, jm.job_name, j.due_date,
                                     le.images as le_images, le.tables_count as le_tables, le.tat as le_tat
                              FROM jobs j 
                              JOIN client_master c ON j.client_id = c.client_id 
                              JOIN job_master jm ON j.process_id = jm.job_id 
                              LEFT JOIN le ON le.job_id = j.job_id
                              WHERE j.job_id=?");
    $stmt->bind_param("i", $job_id);
    $stmt->execute();
    $stmt->bind_result($job_date, $client_name, $journal_issue, $article, $process_name, $job_due_date,
                       $le_images, $le_tables, $le_tat);
    $stmt->fetch();
    $stmt->close();

    // Determine images, tables, and tat source based on process
    if (in_array($process_name, ['CE+PAG', 'CE+PAG+XML'])) {
        // Coming from LE - use LE data (read-only in form)
        $images = $le_images ?: 0;
        $tables = $le_tables ?: 0;
        $tat = $le_tat ?: 0; // Use LE's TAT
    } else {
        // Direct PAG/PAG+XML - use form data (editable) and calculate TAT
        $images = intval($_POST['images'] ?? 0);
        $tables = intval($_POST['tables_count'] ?? 0);
        
        // Calculate TAT (PAG date - JOB due_date) in days
        $pag_date = date('Y-m-d');
        if ($job_due_date) {
            $job_due_datetime = new DateTime($job_due_date);
            $pag_datetime = new DateTime($pag_date);
            $tat = $pag_datetime->diff($job_due_datetime)->days;
            
            // If PAG date is after job due date, make TAT negative
            if ($pag_datetime > $job_due_datetime) {
                $tat = -$tat;
            }
        } else {
            $tat = 0;
        }
    }

    $pag_date = date('Y-m-d');
    $done_by = $employee_id;

    if ($id > 0) {
        // Update existing PAG record
        $stmt = $mysqli->prepare("UPDATE pag 
            SET pages=?, images=?, tables_count=?, a_tat=?, status=?, due_date=?, done_by=?, pag_date=?, last_updated=NOW() 
            WHERE id=?");
        $stmt->bind_param("iiiissisi", $pages, $images, $tables, $tat, $status, $due_date, $done_by, $pag_date, $id);
        $stmt->execute();
        $stmt->close();
    } else {
        // Insert new PAG record
        $stmt = $mysqli->prepare("INSERT INTO pag 
            (job_id, date, client, issue, articles, process, pages, images, tables_count, a_tat, status, due_date, done_by, pag_date, created_at, last_updated) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        if (!$stmt) die("Prepare failed (INSERT): " . $mysqli->error);
        $stmt->bind_param("isssssiiississ", 
            $job_id, $job_date, $client_name, $journal_issue, $article, $process_name, 
            $pages, $images, $tables, $tat, $status, $due_date, $done_by, $pag_date
        );
        $stmt->execute();
        $stmt->close();
    }

    // Update jobs table to assign this executive to PAG stage
    $stmt = $mysqli->prepare("UPDATE jobs SET assigned_pag = ? WHERE job_id = ?");
    $stmt->bind_param("si", $current_user, $job_id);
    $stmt->execute();
    $stmt->close();

    header("Location: pag.php?msg=" . urlencode("PAG details saved successfully.") . "&type=success");
    exit;
}

// Handle filters/sorting
$q = trim($_GET['q'] ?? '');
$filter_status = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'job_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

$sortable = ['job_id', 'job_date', 'client_name', 'journal_issue', 'article', 'process', 'status', 'pag_date'];
if (!in_array($sort, $sortable, true)) $sort = 'job_date';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

// Build query - Show PAG jobs based on process type and completion status
$sql = "SELECT j.job_id, j.job_date, c.client_name, j.journal_issue, j.article, j.due_date,
               jm.job_name AS process, ce.status AS ce_status, le.status AS le_status, 
               le.images as le_images, le.tables_count as le_tables, le.tat as le_tat,
               pag.id AS pag_id, pag.pages, pag.images, pag.tables_count, pag.done_by, 
               pag.a_tat, pag.status, pag.due_date as pag_due_date, pag.pag_date,
               j.assigned_pag
        FROM jobs j 
        JOIN client_master c ON j.client_id = c.client_id 
        JOIN job_master jm ON j.process_id = jm.job_id 
        LEFT JOIN ce ON ce.job_id = j.job_id 
        LEFT JOIN le ON le.job_id = j.job_id AND le.status = 'Completed'
        LEFT JOIN pag ON pag.job_id = j.job_id";

$cond = [];
$params = [];
$types = '';

// Show PAG jobs based on process type and prerequisites
$cond[] = "(jm.job_name LIKE '%PAG%' OR jm.job_name IN ('PAG', 'PAG+XML', 'CE+PAG', 'CE+PAG+XML'))";

// For CE+PAG processes, LE must be completed first
$cond[] = "(
    (jm.job_name IN ('PAG', 'PAG+XML') AND (j.assigned_pag IS NULL OR j.assigned_pag = '' OR j.assigned_pag = ?)) OR
    (jm.job_name IN ('CE+PAG', 'CE+PAG+XML') AND le.status = 'Completed' AND (j.assigned_pag IS NULL OR j.assigned_pag = '' OR j.assigned_pag = ?))
)";
$params[] = $current_user;
$params[] = $current_user;
$types .= 'ss';

if ($q !== '') {
    $cond[] = "(c.client_name LIKE ? OR j.journal_issue LIKE ? OR j.article LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if (in_array($filter_status, ['In Progress', 'Completed', 'Need to Assign'])) {
    if ($filter_status === 'Need to Assign') {
        $cond[] = "pag.id IS NULL";
    } else {
        $cond[] = "pag.status = ?";
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
<title>My PAG Jobs</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" />
<style>
.modal-dialog{max-width:500px}
.btn-brown{background-color:brown !important;color:white !important;border:none}
.btn-brown:hover{background-color:#5C2E0C !important}
.btn-warning{border-radius:5px}
table td,th{vertical-align:middle;padding:6px 8px;font-size:.9rem}
.readonly-field{background-color:#f8f9fa !important;}
.text-warning { color: #f39c12 !important; }
.text-success { color: #27ae60 !important; }
</style>
</head>
<body>
<div class="container mt-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h5>My PAG Jobs</h5>
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
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search client, issue, or article..." autocomplete="off" class="form-control form-control-sm" />
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
      <a href="pag.php" class="btn btn-secondary btn-sm">Clear</a>
    </div>
  </form>

  <!-- Table -->
  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>ID</th><th>Date</th><th>Client</th><th>Issue</th><th>Article</th><th>Process</th>
              <th>Pages</th><th>Images</th><th>Tables</th><th>Done By</th><th>TAT</th><th>PAG Due Date</th>
              <th>Status</th><th>PAG Date</th><th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($results && $results->num_rows > 0): ?>
              <?php while ($row = $results->fetch_assoc()): ?>
                <?php
                // Determine if coming from LE (read-only images/tables/tat)
                $from_le = in_array($row['process'], ['CE+PAG', 'CE+PAG+XML']);
                
                // Determine status and display info
                if (empty($row['pag_id'])) {
                    // Job not taken by anyone yet (Need to Assign)
                    $display_status = 'Need to Assign';
                    $status_class = 'text-warning';
                    $editable = true;
                    $done_by_display = '—';
                    $pag_date_display = '—';
                    
                    // For Need to Assign jobs from LE processes, show LE data
                    if ($from_le) {
                        $display_images = $row['le_images'] ?? 0;
                        $display_tables = $row['le_tables'] ?? 0;
                        $display_tat = $row['le_tat'] ?? 0;
                    } else {
                        $display_images = 0;
                        $display_tables = 0;
                        $display_tat = 0;
                    }
                    $display_pages = 0;
                    
                } else {
                    // Job has PAG entry (taken by current user)
                    $display_status = $row['status'] ?? 'In Progress';
                    $status_class = $display_status === 'Completed' ? 'text-success' : 'text-primary';
                    $editable = $display_status !== 'Completed';
                    
                    // Get done by username (should be current user)
                    $done_by_display = $current_user;
                    
                    // Format PAG date
                    $pag_date_display = formatDate($row['pag_date']);
                    
                    // For completed PAG jobs, show appropriate data based on source
                    if ($from_le) {
                        // From LE process - show LE data for images/tables/tat
                        $display_images = $row['le_images'] ?? 0;
                        $display_tables = $row['le_tables'] ?? 0;
                        $display_tat = $row['le_tat'] ?? 0;
                    } else {
                        // Direct PAG process - show PAG data
                        $display_images = $row['images'] ?? 0;
                        $display_tables = $row['tables_count'] ?? 0;
                        $display_tat = $row['a_tat'] ?? 0;
                    }
                    $display_pages = $row['pages'] ?? 0;
                }
                
                // Format dates
                $job_date_formatted = formatDate($row['job_date']);
                $pag_due_date_formatted = formatDate($row['pag_due_date']);
                ?>
                <tr>
                  <td><?= (int)$row['job_id'] ?></td>
                  <td><?= $job_date_formatted ?></td>
                  <td><?= h($row['client_name']) ?></td>
                  <td><?= h($row['journal_issue']) ?></td>
                  <td><?= h($row['article'] ?? '') ?></td>
                  <td><?= h($row['process']) ?></td>
                  <td><?= intval($display_pages) ?></td>
                  <td><?= intval($display_images) ?></td>
                  <td><?= intval($display_tables) ?></td>
                  <td><?= h($done_by_display) ?></td>
                  <td><?= intval($display_tat) ?></td>
                  <td><?= $pag_due_date_formatted ?></td>
                  <td><span class="<?= $status_class ?>"><?= h($display_status) ?></span></td>
                  <td><?= $pag_date_display ?></td>
                  <td class="text-end">
                    <?php if ($editable): ?>
                      <button class="btn btn-sm btn-warning edit-btn"
                              data-bs-toggle="modal" data-bs-target="#editModal"
                              data-id="<?= $row['pag_id'] ?? 0 ?>"
                              data-job_id="<?= $row['job_id'] ?>"
                              data-pages="<?= intval($display_pages) ?>"
                              data-images="<?= intval($row['images'] ?? 0) ?>"
                              data-tables_count="<?= intval($row['tables_count'] ?? 0) ?>"
                              data-le_images="<?= intval($row['le_images'] ?? 0) ?>"
                              data-le_tables="<?= intval($row['le_tables'] ?? 0) ?>"
                              data-pag_due_date="<?= $row['pag_due_date'] ?? '' ?>"
                              data-status="<?= h($row['status'] ?? 'In Progress') ?>"
                              data-from_le="<?= $from_le ? '1' : '0' ?>"
                              title="Edit"><i class="bi bi-pencil-square"></i></button>
                    <?php else: ?>
                      <span class="badge bg-success">Completed</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr><td colspan="15" class="text-center py-4">No PAG jobs found.</td></tr>
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
      <h5 class="modal-title" id="editModalLabel">Edit PAG Details</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
      <input type="hidden" name="action" value="update_pag">
      <input type="hidden" name="id" id="pagId">
      <input type="hidden" name="job_id" id="jobId">

      <div class="row g-2">
        <!-- Always editable -->
        <div class="col-6">
          <label for="pages" class="form-label">Pages *</label>
          <input type="number" min="0" class="form-control form-control-sm" id="pages" name="pages" required>
        </div>
        <div class="col-6">
          <label for="due_date" class="form-label">PAG Due Date *</label>
          <input type="date" class="form-control form-control-sm" id="due_date" name="due_date" required>
        </div>
        
        <!-- Conditional editability based on process -->
        <div class="col-6">
          <label for="images" class="form-label">Images <small id="images_note"></small></label>
          <input type="number" min="0" class="form-control form-control-sm" id="images" name="images">
        </div>
        <div class="col-6">
          <label for="tables_count" class="form-label">Tables <small id="tables_note"></small></label>
          <input type="number" min="0" class="form-control form-control-sm" id="tables_count" name="tables_count">
        </div>
        
        <div class="col-12">
          <label for="status" class="form-label">Status *</label>
          <select class="form-select form-select-sm" id="status" name="status" required>
            <option value="In Progress">In Progress</option>
            <option value="Completed">Completed</option>
          </select>
        </div>
        
        <div class="col-12">
          <small class="text-muted">TAT will be auto-calculated based on Job Due Date - PAG Date</small>
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
      document.getElementById('pagId').value = btn.dataset.id || '';
      document.getElementById('jobId').value = btn.dataset.job_id || '';
      document.getElementById('pages').value = btn.dataset.pages || '0';
      document.getElementById('due_date').value = btn.dataset.pag_due_date || '';
      document.getElementById('status').value = btn.dataset.status || 'In Progress';
      
      // Handle read-only fields based on process type
      const fromLE = btn.dataset.from_le === '1';
      const imagesField = document.getElementById('images');
      const tablesField = document.getElementById('tables_count');
      const imagesNote = document.getElementById('images_note');
      const tablesNote = document.getElementById('tables_note');
      
      if (fromLE) {
        // Use LE data for images, tables (read-only)
        imagesField.value = btn.dataset.le_images || '0';
        tablesField.value = btn.dataset.le_tables || '0';
        imagesField.readOnly = true;
        tablesField.readOnly = true;
        imagesField.classList.add('readonly-field');
        tablesField.classList.add('readonly-field');
        imagesNote.textContent = '(from LE)';
        tablesNote.textContent = '(from LE)';
      } else {
        // Use PAG data for images, tables (editable)
        imagesField.value = btn.dataset.images || '0';
        tablesField.value = btn.dataset.tables_count || '0';
        imagesField.readOnly = false;
        tablesField.readOnly = false;
        imagesField.classList.remove('readonly-field');
        tablesField.classList.remove('readonly-field');
        imagesNote.textContent = '';
        tablesNote.textContent = '';
      }
    });
  });
</script>
</body>
</html>
