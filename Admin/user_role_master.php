<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ---------- Helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDate($d) {
    return (!empty($d) && $d != '0000-00-00' && $d != '0000-00-00 00:00:00') ? date('d-m-Y', strtotime($d)) : '—';
}
function redirect_with($params = []) {
    $base = 'user_role_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// ---------- Handle POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $role_name = trim($_POST['role_name'] ?? '');
    $permissions = trim($_POST['permissions'] ?? '');

    $errors = [];
    if (($action === 'add' || $action === 'edit') && $role_name === '') $errors[] = 'Role Name is required.';
    if (($action === 'add' || $action === 'edit') && $permissions === '') $errors[] = 'Permissions are required.';

    if (!empty($errors)) {
        redirect_with(['msg'=>implode(' ', $errors),'type'=>'danger']);
    }

    if ($action === 'add') {
        $desc      = trim($_POST['role_description'] ?? '');
        $created_by= (int)($_SESSION['uid'] ?? 0);
        $status    = 'Active';

        $stmt = $mysqli->prepare("INSERT INTO user_role_master 
            (role_name, role_description, permissions, created_by, status, created_date) 
            VALUES (?,?,?,?,?,NOW())");
        $stmt->bind_param("sssis", $role_name, $desc, $permissions, $created_by, $status);
        $stmt->execute();
        $stmt->close();

        redirect_with(['msg'=>"Role '$role_name' added successfully",'type'=>'success']);
    }

    if ($action === 'edit') {
        $id = (int)($_POST['role_id'] ?? 0);
        if ($id > 0) {
            $desc      = trim($_POST['role_description'] ?? '');
            // Preserve original created_by, so fetch it first
            $res = $mysqli->prepare("SELECT created_by FROM user_role_master WHERE role_id=?");
            $res->bind_param("i", $id);
            $res->execute();
            $row = $res->get_result()->fetch_assoc();
            $res->close();
            $created_by = $row ? (int)$row['created_by'] : null;

            $stmt = $mysqli->prepare("UPDATE user_role_master SET 
                role_name=?, role_description=?, permissions=?, created_by=? 
                WHERE role_id=?");
            $stmt->bind_param("sssii", $role_name, $desc, $permissions, $created_by, $id);
            $stmt->execute();
            $stmt->close();

            redirect_with(['msg'=>"Role '$role_name' updated successfully",'type'=>'success']);
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['role_id'] ?? 0);
        $name = '';
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT role_name FROM user_role_master WHERE role_id=?");
            $res->bind_param("i", $id);
            $res->execute();
            $row = $res->get_result()->fetch_assoc();
            $res->close();
            if ($row) $name = $row['role_name'];

            $del = $mysqli->prepare("DELETE FROM user_role_master WHERE role_id=?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
        }
        redirect_with(['msg'=>"Role '$name' deleted",'type'=>'warning']);
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['role_id'] ?? 0);
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT status, role_name FROM user_role_master WHERE role_id=?");
            $res->bind_param("i", $id);
            $res->execute();
            $row = $res->get_result()->fetch_assoc();
            $res->close();
            if ($row) {
                $newStatus = ($row['status'] === 'Active') ? 'Inactive' : 'Active';
                $upd = $mysqli->prepare("UPDATE user_role_master SET status=? WHERE role_id=?");
                $upd->bind_param("si", $newStatus, $id);
                $upd->execute();
                $upd->close();
                redirect_with(['msg'=>"Role '{$row['role_name']}' status changed to $newStatus",'type'=>'info']);
            }
        }
    }

    redirect_with();
}

// ---------- View / Edit ----------
$mode = $_GET['mode'] ?? 'view';
$editRole = null;
if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM user_role_master WHERE role_id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editRole = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editRole) $mode = 'view';
}

// ---------- Filters & Sorting ----------
$q       = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort    = $_GET['sort'] ?? 'created_date';
$dir     = strtolower($_GET['dir'] ?? 'desc');
$sortable = ['role_id','role_name','status','created_date'];
if (!in_array($sort, $sortable, true)) $sort = 'created_date';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

// Build query with employee name joined
$sql  = "SELECT urm.role_id, urm.role_name, urm.role_description, urm.permissions,
                urm.status, urm.created_date, em.employee_name AS created_name, urm.created_by
         FROM user_role_master urm
         LEFT JOIN employee_master em ON urm.created_by = em.employee_id";
$cond = []; $params = []; $types = '';
if ($q !== '') { 
    $cond[]="(urm.role_name LIKE ? OR urm.role_description LIKE ? OR urm.permissions LIKE ?)";
    $like="%$q%"; 
    $params=[$like,$like,$like]; 
    $types='sss'; 
}
if ($fstatus==='Active'||$fstatus==='Inactive'){ $cond[]="urm.status=?"; $params[]=$fstatus; $types.='s'; }
if ($cond) $sql.=" WHERE ".implode(" AND ", $cond);
$sql.=" ORDER BY $sort $dir";


if ($types) {
    $stmt=$mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $roles=$stmt->get_result();
} else {
    $roles = $mysqli->query($sql);
}

require 'header.php';
?>

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
  <h5><?= $mode==='edit'?'Edit Role':'Add Role' ?></h5>
  <a href="user_role_master.php" class="btn btn-sm btn-secondary">Back</a>
</div>
<div class="card mb-3 mx-auto" style="max-width:600px;">
  <div class="card-body">
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="<?= $mode==='edit'?'edit':'add' ?>">
      <?php if($mode==='edit'): ?>
        <input type="hidden" name="role_id" value="<?= (int)$editRole['role_id'] ?>">
      <?php endif; ?>

      <div class="mb-2">
        <label class="form-label small">Role Name *</label>
        <input type="text" name="role_name" class="form-control form-control-sm" required value="<?= h($editRole['role_name'] ?? '') ?>">
      </div>
      <div class="mb-2">
        <label class="form-label small">Role Description</label>
        <textarea name="role_description" class="form-control form-control-sm" rows="2"><?= h($editRole['role_description'] ?? '') ?></textarea>
      </div>
      <div class="mb-2">
        <label class="form-label small">Permissions *</label>
        <textarea name="permissions" class="form-control form-control-sm" rows="2" required><?= h($editRole['permissions'] ?? '') ?></textarea>
      </div>
      
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-sm" style="background-color:brown;color:white;"><?= $mode==='edit'?'Update':'Save' ?></button>
        <a href="user_role_master.php" class="btn btn-sm btn-outline-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<!-- Roles List -->
<div class="d-flex justify-content-between align-items-center mb-2">
  <h5>User Role Master</h5>
  <a href="user_role_master.php?mode=add" class="btn btn-sm btn-success"><i class="fa fa-plus"></i> Add</a>
</div>

<!-- Filter Form -->
<form method="get" class="row gy-2 gx-2 mb-3 align-items-center">
  <div class="col-auto">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search role name / desc / permissions" autocomplete="off" class="form-control form-control-sm">
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
        <option value="<?= $s ?>" <?= $sort===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
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
    <a href="user_role_master.php" class="btn btn-secondary btn-sm">Clear</a>
  </div>
</form>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Role Name</th>
            <th>Description</th>
            <th>Permissions</th>
            <th>Created By</th>
            <th>Status</th>
            <th>Created</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if($roles && $roles->num_rows): while($row=$roles->fetch_assoc()): ?>
          <tr>
            <td><?= (int)$row['role_id'] ?></td>
            <td><?= h($row['role_name']) ?></td>
            <td><?= h($row['role_description']) ?></td>
            <td><?= h($row['permissions']) ?></td>
            <td><?= h($row['created_name'] ?: $row['created_by']) ?></td>
            <td>
              <form method="post" style="display:inline;" onsubmit="return confirm('Change status of <?= h($row['role_name']) ?> from <?= $row['status'] ?> to <?= $row['status']==='Active'?'Inactive':'Active' ?>?')">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="role_id" value="<?= (int)$row['role_id'] ?>">
                <button type="submit" class="btn btn-sm <?= $row['status']==='Active'?'btn-success':'btn-danger' ?>">
                  <?= h($row['status']) ?>
                </button>
              </form>
            </td>
            <td><?= h(formatDate($row['created_date'])) ?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-warning" href="user_role_master.php?mode=edit&id=<?= (int)$row['role_id'] ?>"><i class="fa fa-edit"></i></a>
              <form method="post" style="display:inline;" onsubmit="return confirm('Delete role <?= h($row['role_name']) ?>?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="role_id" value="<?= (int)$row['role_id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger"><i class="fa fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="8" class="text-center py-4">No roles found.</td></tr>
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
