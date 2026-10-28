<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ---------- Helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect_with($params = []) {
    $base = 'client_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// ---------- Handle POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $client_name = trim($_POST['client_name'] ?? '');

    if ($action === 'add' && $client_name !== '') {
        $contact = trim($_POST['contact_person'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $gst     = trim($_POST['gst_no'] ?? '');
        $pref    = trim($_POST['preferred_communication'] ?? '');
        $status  = 'Active';

        $stmt = $mysqli->prepare("INSERT INTO client_master 
            (client_name, contact_person, email, phone, address, country, gst_no, preferred_communication, status, created_date) 
            VALUES (?,?,?,?,?,?,?,?,?,NOW())");
        $stmt->bind_param("sssssssss", $client_name, $contact, $email, $phone, $address, $country, $gst, $pref, $status);
        $stmt->execute();
        $stmt->close();

        redirect_with(['msg'=>"Client '$client_name' added successfully",'type'=>'success']);
    }

    if ($action === 'edit') {
        $id = (int)($_POST['client_id'] ?? 0);
        if ($id > 0 && $client_name !== '') {
            $contact = trim($_POST['contact_person'] ?? '');
            $email   = trim($_POST['email'] ?? '');
            $phone   = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $country = trim($_POST['country'] ?? '');
            $gst     = trim($_POST['gst_no'] ?? '');
            $pref    = trim($_POST['preferred_communication'] ?? '');

            $stmt = $mysqli->prepare("UPDATE client_master SET 
                client_name=?, contact_person=?, email=?, phone=?, address=?, country=?, gst_no=?, preferred_communication=? 
                WHERE client_id=?");
            $stmt->bind_param("ssssssssi", $client_name, $contact, $email, $phone, $address, $country, $gst, $pref, $id);
            $stmt->execute();
            $stmt->close();

            redirect_with(['msg'=>"Client '$client_name' updated successfully",'type'=>'success']);
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['client_id'] ?? 0);
        $name = '';
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT client_name FROM client_master WHERE client_id=?");
            $res->bind_param("i", $id);
            $res->execute();
            $row = $res->get_result()->fetch_assoc();
            $res->close();
            if ($row) $name = $row['client_name'];

            $del = $mysqli->prepare("DELETE FROM client_master WHERE client_id=?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
        }
        redirect_with(['msg'=>"Client '$name' deleted",'type'=>'warning']);
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['client_id'] ?? 0);
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT status, client_name FROM client_master WHERE client_id=?");
            $res->bind_param("i", $id);
            $res->execute();
            $row = $res->get_result()->fetch_assoc();
            $res->close();
            if ($row) {
                $newStatus = ($row['status'] === 'Active') ? 'Inactive' : 'Active';
                $upd = $mysqli->prepare("UPDATE client_master SET status=? WHERE client_id=?");
                $upd->bind_param("si", $newStatus, $id);
                $upd->execute();
                $upd->close();
                redirect_with(['msg'=>"Client '{$row['client_name']}' status changed to $newStatus",'type'=>'info']);
            }
        }
    }

    redirect_with();
}

// ---------- View / Edit ----------
$mode = $_GET['mode'] ?? 'view';
$editClient = null;
if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM client_master WHERE client_id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editClient = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editClient) $mode = 'view';
}

// ---------- Filters & Sorting ----------
$q       = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort    = $_GET['sort'] ?? 'created_date';
$dir     = strtolower($_GET['dir'] ?? 'desc');
$sortable = ['client_id','client_name','email','phone','status','created_date'];
if (!in_array($sort, $sortable, true)) $sort = 'created_date';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

// Build query
$sql  = "SELECT client_id, client_name, contact_person, email, phone, address, country, gst_no, preferred_communication, status, created_date FROM client_master";
$cond = []; $params = []; $types = '';
if ($q !== '') { 
    $cond[]="(client_name LIKE ? OR contact_person LIKE ? OR email LIKE ? OR phone LIKE ? OR country LIKE ? OR gst_no LIKE ? OR preferred_communication LIKE ?)";
    $like="%$q%"; 
    $params=[$like,$like,$like,$like,$like,$like,$like]; 
    $types='sssssss'; 
}
if ($fstatus==='Active'||$fstatus==='Inactive'){ $cond[]="status=?"; $params[]=$fstatus; $types.='s'; }
if ($cond) $sql.=" WHERE ".implode(" AND ", $cond);
$sql.=" ORDER BY $sort $dir";

if ($types) { $stmt=$mysqli->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute(); $clients=$stmt->get_result(); } 
else { $clients = $mysqli->query($sql); }

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
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5><?= $mode==='edit'?'Edit Client':'Add Client' ?></h5>
  <a href="client_master.php" class="btn btn-sm btn-secondary">Back</a>
</div>
<div class="card mb-3 mx-auto" style="max-width:650px;">
  <div class="card-body">
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="<?= $mode==='edit'?'edit':'add' ?>">
      <?php if($mode==='edit'): ?><input type="hidden" name="client_id" value="<?= (int)$editClient['client_id'] ?>"><?php endif; ?>

      <div class="mb-2"><label class="form-label small">Client Name</label>
        <input type="text" name="client_name" class="form-control form-control-sm" required value="<?= h($editClient['client_name'] ?? '') ?>" style="width:300px;">
      </div>
      <div class="mb-2"><label class="form-label small">Contact Person</label>
        <input type="text" name="contact_person" class="form-control form-control-sm" required value="<?= h($editClient['contact_person'] ?? '') ?>" style="width:300px;">
      </div>
      <div class="mb-2"><label class="form-label small">Email</label>
        <input type="email" name="email" class="form-control form-control-sm" required value="<?= h($editClient['email'] ?? '') ?>" style="width:300px;">
      </div>
      <div class="mb-2"><label class="form-label small">Phone</label>
        <input type="text" name="phone" class="form-control form-control-sm" required value="<?= h($editClient['phone'] ?? '') ?>" style="width:200px;">
      </div>
      <div class="mb-2"><label class="form-label small">Address</label>
        <textarea name="address" class="form-control form-control-sm" required rows="2"><?= h($editClient['address'] ?? '') ?></textarea>
      </div>
      <div class="mb-2"><label class="form-label small">Country</label>
        <input type="text" name="country" class="form-control form-control-sm" required value="<?= h($editClient['country'] ?? '') ?>" style="width:200px;">
      </div>
      <div class="mb-2"><label class="form-label small">GST / Tax No <small class="text-muted">(GSTIN/VAT/TAX ID)</small></label>
        <input type="text" name="gst_no" class="form-control form-control-sm" value="<?= h($editClient['gst_no'] ?? '') ?>" style="width:200px;">
      </div>
      <div class="mb-2"><label class="form-label small">Preferred Communication</label>
        <select name="preferred_communication" class="form-select form-select-sm" required style="width:200px;">
          <option value="">Select</option>
          <option value="Email" <?= ($editClient['preferred_communication'] ?? '')==='Email'?'selected':'' ?>>Email</option>
          <option value="Phone" <?= ($editClient['preferred_communication'] ?? '')==='Phone'?'selected':'' ?>>Phone</option>
          <option value="Portal" <?= ($editClient['preferred_communication'] ?? '')==='Portal'?'selected':'' ?>>Portal</option>
        </select>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-sm btn-warning"><?= $mode==='edit'?'Update':'Save' ?></button>
        <a href="client_master.php" class="btn btn-sm btn-outline-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-2">
  <h5>Client Master</h5>
  <a href="client_master.php?mode=add" class="btn btn-sm btn-success"><i class="fa fa-plus"></i> Add</a>
</div>

<!-- Toolbar -->
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search name/contact/email/phone" style="width:220px;" value="<?= h($q) ?>">
  <select id="statusSelect" class="form-select form-select-sm" style="width:120px;">
    <option value="">All statuses</option>
    <option value="Active" <?= $fstatus==='Active'?'selected':'' ?>>Active</option>
    <option value="Inactive" <?= $fstatus==='Inactive'?'selected':'' ?>>Inactive</option>
  </select>
  <select id="sortSelect" class="form-select form-select-sm" style="width:140px;">
    <option value="created_date" <?= $sort==='created_date'?'selected':'' ?>>Created</option>
    <option value="client_name" <?= $sort==='client_name'?'selected':'' ?>>Name</option>
    <option value="email" <?= $sort==='email'?'selected':'' ?>>Email</option>
    <option value="phone" <?= $sort==='phone'?'selected':'' ?>>Phone</option>
    <option value="status" <?= $sort==='status'?'selected':'' ?>>Status</option>
    <option value="client_id" <?= $sort==='client_id'?'selected':'' ?>>ID</option>
  </select>
  <select id="dirSelect" class="form-select form-select-sm" style="width:100px;">
    <option value="asc" <?= $dir==='asc'?'selected':'' ?>>Asc</option>
    <option value="desc" <?= $dir==='desc'?'selected':'' ?>>Desc</option>
  </select>
  <button id="applyFilter" class="btn btn-sm btn-primary"><i class="fa fa-search"></i></button>
  <button id="clearFilter" class="btn btn-sm btn-outline-dark"><i class="fa fa-times"></i> Clear</button>
</div>

<!-- Client Table -->
<div class="card">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
<thead class="table-light">
<tr>
<th>ID</th>
<th>Name</th>
<th>Contact</th>
<th>Email</th>
<th>Phone</th>
<th>Address</th>
<th>Country</th>
<th>GST / Tax No</th>
<th>Preferred Communication</th>
<th>Status</th>
<th>Created</th>
<th class="text-end">Actions</th>
</tr>
</thead>
<tbody>
<?php if($clients && $clients->num_rows): while($row=$clients->fetch_assoc()): ?>
<tr>
<td><?= (int)$row['client_id'] ?></td>
<td><?= h($row['client_name']) ?></td>
<td><?= h($row['contact_person']) ?></td>
<td><?= h($row['email']) ?></td>
<td><?= h($row['phone']) ?></td>
<td><?= h($row['address']) ?></td>
<td><?= h($row['country']) ?></td>
<td><?= h($row['gst_no']) ?></td>
<td><?= h($row['preferred_communication']) ?></td>
<td>
<form method="post" style="display:inline;" onsubmit="return confirm('Change status of <?= h($row['client_name']) ?>?')">
<input type="hidden" name="action" value="toggle">
<input type="hidden" name="client_id" value="<?= (int)$row['client_id'] ?>">
<button type="submit" class="btn btn-sm <?= $row['status']==='Active'?'btn-success':'btn-danger' ?>"><?= h($row['status']) ?></button>
</form>
</td>
<td><?= h($row['created_date']) ?></td>
<td class="text-end">
<a class="btn btn-sm btn-warning" href="client_master.php?mode=edit&id=<?= (int)$row['client_id'] ?>"><i class="fa fa-edit"></i></a>
<form method="post" style="display:inline;" onsubmit="return confirm('Delete client <?= h($row['client_name']) ?>?')">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="client_id" value="<?= (int)$row['client_id'] ?>">
<button class="btn btn-sm btn-danger"><i class="fa fa-trash"></i></button>
</form>
</td>
</tr>
<?php endwhile; else: ?>
<tr><td colspan="12" class="text-center py-4">No clients found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>

<?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
    const applyFilter=()=>{
        const q=document.getElementById('searchInput').value.trim();
        const status=document.getElementById('statusSelect').value;
        const sort=document.getElementById('sortSelect').value;
        const dir=document.getElementById('dirSelect').value;
        const params=new URLSearchParams();
        if(q) params.set('q',q);
        if(status) params.set('status',status);
        if(sort) params.set('sort',sort);
        if(dir) params.set('dir',dir);
        window.location.href='client_master.php?'+params.toString();
    };
    document.getElementById('applyFilter').addEventListener('click',applyFilter);
    document.getElementById('clearFilter').addEventListener('click',()=>{
        document.getElementById('searchInput').value='';
        document.getElementById('statusSelect').value='';
        document.getElementById('sortSelect').value='created_date';
        document.getElementById('dirSelect').value='desc';
        applyFilter();
    });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
