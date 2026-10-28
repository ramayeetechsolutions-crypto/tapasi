<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ---------- Helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDate($d) {
    return (!empty($d) && $d != '0000-00-00') ? date('d-m-Y', strtotime($d)) : '—';
}
function redirect_with($params = []) {
    $base = 'job_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// ---------- Handle POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $job_name = trim($_POST['job_name'] ?? '');
    $rate     = trim($_POST['rate'] ?? '');
    $desc     = trim($_POST['job_description'] ?? '');
    $uid      = $_SESSION['uid'] ?? null;

    $errors = [];
    if (($action === 'add' || $action === 'edit') && $job_name === '') $errors[] = 'Job Name is required.';

    if (!empty($errors)) {
        redirect_with(['msg'=>implode(' ', $errors),'type'=>'danger']);
    }

    if ($action === 'add') {
        $rate = ($rate !== '') ? (float)$rate : null;
        $status = 'Active';
        $stmt = $mysqli->prepare("INSERT INTO job_master (job_name, rate, job_description, status, created_by, created_date) VALUES (?,?,?,?,?, NOW())");
        $stmt->bind_param("sdssi", $job_name, $rate, $desc, $status, $uid);
        $stmt->execute();
        $stmt->close();
        redirect_with(['msg'=>"Job '$job_name' added successfully",'type'=>'success']);
    }

    if ($action === 'edit') {
        $id = (int)($_POST['job_id'] ?? 0);
        if ($id > 0) {
            $rate = ($rate !== '') ? (float)$rate : null;
            $stmt = $mysqli->prepare("UPDATE job_master SET job_name=?, rate=?, job_description=? WHERE job_id=?");
            $stmt->bind_param("sdsi", $job_name, $rate, $desc, $id);
            $stmt->execute();
            $stmt->close();
            redirect_with(['msg'=>"Job '$job_name' updated successfully",'type'=>'success']);
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['job_id'] ?? 0);
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT job_name FROM job_master WHERE job_id=?");
            $res->bind_param("i", $id);
            $res->execute();
            $row = $res->get_result()->fetch_assoc();
            $res->close();
            $name = $row['job_name'] ?? '';
            $del = $mysqli->prepare("DELETE FROM job_master WHERE job_id=?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
        }
        redirect_with(['msg'=>"Job '$name' deleted",'type'=>'warning']);
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['job_id'] ?? 0);
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT status, job_name FROM job_master WHERE job_id=?");
            $res->bind_param("i", $id);
            $res->execute();
            $row = $res->get_result()->fetch_assoc();
            $res->close();
            if ($row) {
                $newStatus = ($row['status'] === 'Active') ? 'Inactive' : 'Active';
                $upd = $mysqli->prepare("UPDATE job_master SET status=? WHERE job_id=?");
                $upd->bind_param("si", $newStatus, $id);
                $upd->execute();
                $upd->close();
                redirect_with(['msg'=>"Job '{$row['job_name']}' status changed to $newStatus",'type'=>'info']);
            }
        }
    }

    redirect_with();
}

// ---------- View / Edit ----------
$mode = $_GET['mode'] ?? 'view';
$editJob = null;
if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM job_master WHERE job_id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editJob = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editJob) $mode = 'view';
}

// ---------- Filters & Sorting ----------
$q       = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort    = $_GET['sort'] ?? 'created_date';
$dir     = strtolower($_GET['dir'] ?? 'desc');
$sortable = ['job_id','job_name','rate','status','created_date'];
if (!in_array($sort, $sortable, true)) $sort = 'created_date';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

// Build query with creator name
$sql  = "SELECT jm.job_id, jm.job_name, jm.rate, jm.status, jm.job_description, 
                jm.created_by, jm.created_date, em.employee_name AS created_name
         FROM job_master jm
         LEFT JOIN employee_master em ON jm.created_by = em.employee_id";
$cond = []; $params = []; $types = '';
if ($q !== '') { $cond[]="(jm.job_name LIKE ? OR jm.job_description LIKE ? OR CAST(jm.rate AS CHAR) LIKE ?)"; $like="%$q%"; $params=[$like,$like,$like]; $types='sss'; }
if ($fstatus==='Active'||$fstatus==='Inactive'){ $cond[]="jm.status=?"; $params[]=$fstatus; $types.='s'; }
if ($cond) $sql.=" WHERE ".implode(" AND ", $cond);
$sql.=" ORDER BY $sort $dir";

if ($types) { $stmt=$mysqli->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute(); $jobs=$stmt->get_result(); } 
else { $jobs = $mysqli->query($sql); }

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Job Master</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="container mt-3">

<?php if (!empty($_GET['msg'])): ?>
<div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
  <?= h($_GET['msg']) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if($mode==='add'||$mode==='edit'): ?>
<!-- Add/Edit Form -->
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5><?= $mode==='edit'?'Edit Job':'Add Job' ?></h5>
  <a href="job_master.php" class="btn btn-sm btn-secondary">Back</a>
</div>
<div class="card mb-3 mx-auto" style="max-width:500px;">
  <div class="card-body">
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="<?= $mode==='edit'?'edit':'add' ?>">
      <?php if($mode==='edit'): ?><input type="hidden" name="job_id" value="<?= (int)$editJob['job_id'] ?>"><?php endif; ?>
      <div class="mb-2">
        <label class="form-label small">Job Name *</label>
        <input type="text" name="job_name" class="form-control form-control-sm" required value="<?= h($editJob['job_name'] ?? '') ?>">
      </div>
      <div class="mb-2">
        <label class="form-label small">Rate</label>
        <input type="number" step="0.01" name="rate" class="form-control form-control-sm" value="<?= h($editJob['rate'] ?? '') ?>">
      </div>
      <div class="mb-2">
        <label class="form-label small">Description</label>
        <textarea name="job_description" class="form-control form-control-sm" rows="2"><?= h($editJob['job_description'] ?? '') ?></textarea>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-sm" style="background-color:brown;color:white;"><?= $mode==='edit'?'Update':'Save' ?></button>
        <a href="job_master.php" class="btn btn-sm btn-outline-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-2">
  <h5>Job Master</h5>
  <a href="job_master.php?mode=add" class="btn btn-sm btn-success"><i class="fa fa-plus"></i> Add</a>
</div>

<!-- Filter Form -->
<form method="get" class="row gy-2 gx-2 mb-3 align-items-center">
  <div class="col-auto">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search job name / desc / rate" autocomplete="off" class="form-control form-control-sm">
  </div>
  <div class="col-auto">
    <select name="status" class="form-select form-select-sm">
      <option value="">All</option>
      <option value="Active" <?= $fstatus==='Active'?'selected':'' ?>>Active</option>
      <option value="Inactive" <?= $fstatus==='Inactive'?'selected':'' ?>>Inactive</option>
    </select>
  </div>
  <div class="col-auto">
    <select name="sort" class="form-select form-select-sm">
      <?php foreach($sortable as $s): ?>
        <option value="<?= $s ?>" <?= $sort===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <select name="dir" class="form-select form-select-sm">
      <option value="asc" <?= $dir==='asc'?'selected':'' ?>>Asc</option>
      <option value="desc" <?= $dir==='desc'?'selected':'' ?>>Desc</option>
    </select>
  </div>
  <div class="col-auto">
    <button type="submit" class="btn btn-primary btn-sm">Go</button>
    <a href="job_master.php" class="btn btn-secondary btn-sm">Clear</a>
  </div>
</form>

<!-- Job Table -->
<div class="card">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
<thead class="table-light">
<tr>
<th>ID</th>
<th>Name</th>
<th>Rate</th>
<th>Status</th>
<th>Description</th>
<th>Created By</th>
<th>Created</th>
<th class="text-end">Actions</th>
</tr>
</thead>
<tbody>
<?php if($jobs && $jobs->num_rows): while($row=$jobs->fetch_assoc()): ?>
<tr>
<td><?= (int)$row['job_id'] ?></td>
<td><?= h($row['job_name']) ?></td>
<td><?= $row['rate']!==null?number_format($row['rate'],2):'' ?></td>
<td>
<form method="post" style="display:inline;" onsubmit="return confirm('Change status of <?= h($row['job_name']) ?>?')">
<input type="hidden" name="action" value="toggle">
<input type="hidden" name="job_id" value="<?= (int)$row['job_id'] ?>">
<button type="submit" class="btn btn-sm <?= $row['status']==='Active'?'btn-success':'btn-danger' ?>"><?= h($row['status']) ?></button>
</form>
</td>
<td><?= h($row['job_description']) ?></td>
<td><?= h($row['created_name'] ?: $row['created_by']) ?></td>
<td><?= formatDate($row['created_date']) ?></td>
<td class="text-end">
<a class="btn btn-sm btn-warning" href="job_master.php?mode=edit&id=<?= (int)$row['job_id'] ?>"><i class="fa fa-edit"></i></a>
<form method="post" style="display:inline;" onsubmit="return confirm('Delete job <?= h($row['job_name']) ?>?')">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="job_id" value="<?= (int)$row['job_id'] ?>">
<button class="btn btn-sm btn-danger"><i class="fa fa-trash"></i></button>
</form>
</td>
</tr>
<?php endwhile; else: ?>
<tr><td colspan="8" class="text-center py-4">No jobs found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>

<?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
