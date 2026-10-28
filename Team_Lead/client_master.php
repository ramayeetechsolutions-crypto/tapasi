<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ---------- Helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}
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

    // Basic validation
    if (($action === 'add' || $action === 'edit') && $client_name === '') {
        redirect_with(['msg'=>'Client Name is required.','type'=>'danger']);
    }

    if ($action === 'add') {
        // Fields (note: status NOT in the add form, default to Active)
        $company = trim($_POST['company_name'] ?? '');
        $contact = trim($_POST['contact_person'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $country = trim($_POST['country'] ?? 'India');
        $gst     = trim($_POST['gst_no'] ?? '');
        $pref    = trim($_POST['preferred_communication'] ?? '');
        $status  = 'Active';

        $stmt = $mysqli->prepare("INSERT INTO client_master 
            (client_name, company_name, contact_person, email, phone, address, country, gst_no, preferred_communication, status, created_date)
            VALUES (?,?,?,?,?,?,?,?,?,?, NOW())");
        $stmt->bind_param("ssssssssss", $client_name, $company, $contact, $email, $phone, $address, $country, $gst, $pref, $status);
        $stmt->execute();
        $stmt->close();

        redirect_with(['msg'=>"Client '$client_name' added successfully",'type'=>'success']);
    }

    if ($action === 'edit') {
        $id = (int)($_POST['client_id'] ?? 0);
        if ($id > 0) {
            $company = trim($_POST['company_name'] ?? '');
            $contact = trim($_POST['contact_person'] ?? '');
            $email   = trim($_POST['email'] ?? '');
            $phone   = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $country = trim($_POST['country'] ?? 'India');
            $gst     = trim($_POST['gst_no'] ?? '');
            $pref    = trim($_POST['preferred_communication'] ?? '');

            // Update without changing status (status toggled only from list)
            $stmt = $mysqli->prepare("UPDATE client_master SET
                client_name=?, company_name=?, contact_person=?, email=?, phone=?, address=?, country=?, gst_no=?, preferred_communication=?
                WHERE client_id=?");
            // 9 strings + 1 int
            $stmt->bind_param("sssssssssi", $client_name, $company, $contact, $email, $phone, $address, $country, $gst, $pref, $id);
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
$sortable = ['client_id','client_name','company_name','email','phone','status','created_date'];
if (!in_array($sort, $sortable, true)) $sort = 'created_date';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

// Build query
$sql  = "SELECT client_id, client_name, company_name, contact_person, email, phone, address, country, gst_no, preferred_communication, status, created_date
         FROM client_master";
$cond = []; $params = []; $types = '';
if ($q !== '') {
    $cond[] = "(client_name LIKE ? OR company_name LIKE ? OR contact_person LIKE ? OR email LIKE ? OR phone LIKE ? OR address LIKE ? OR country LIKE ? OR gst_no LIKE ? OR preferred_communication LIKE ?)";
    $like = "%$q%";
    $params = [$like, $like, $like, $like, $like, $like, $like, $like, $like];
    $types = str_repeat('s', count($params));
}
if ($fstatus === 'Active' || $fstatus === 'Inactive') { $cond[] = "status=?"; $params[] = $fstatus; $types .= 's'; }
if ($cond) $sql .= " WHERE " . implode(" AND ", $cond);
$sql .= " ORDER BY $sort $dir";

if ($types) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $clients = $stmt->get_result();
} else {
    $clients = $mysqli->query($sql);
}

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Client Master</title>
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
  <h5><?= $mode==='edit'?'Edit Client':'Add Client' ?></h5>
  <a href="client_master.php" class="btn btn-sm btn-secondary">Back</a>
</div>
<div class="card mb-3 mx-auto" style="max-width:900px;">
  <div class="card-body">
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="<?= $mode==='edit'?'edit':'add' ?>">
      <?php if($mode==='edit'): ?><input type="hidden" name="client_id" value="<?= (int)$editClient['client_id'] ?>"><?php endif; ?>

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label small">Client Name <span class="text-danger">*</span></label>
          <input type="text" name="client_name" class="form-control form-control-sm" required value="<?= h($editClient['client_name'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label small">Company Name</label>
          <input type="text" name="company_name" class="form-control form-control-sm" value="<?= h($editClient['company_name'] ?? '') ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label small">Contact Person <span class="text-danger">*</span></label>
          <input type="text" name="contact_person" class="form-control form-control-sm" required value="<?= h($editClient['contact_person'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label small">Email <span class="text-danger">*</span></label>
          <input type="email" name="email" class="form-control form-control-sm" required value="<?= h($editClient['email'] ?? '') ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label small">Phone <span class="text-danger">*</span></label>
          <input type="text" name="phone" class="form-control form-control-sm" required value="<?= h($editClient['phone'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label small">Country <span class="text-danger">*</span></label>
          <input type="text" name="country" class="form-control form-control-sm" required value="<?= h($editClient['country'] ?? 'India') ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label small">GST / Tax No</label>
          <input type="text" name="gst_no" class="form-control form-control-sm" value="<?= h($editClient['gst_no'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label small">Preferred Communication <span class="text-danger">*</span></label>
          <select name="preferred_communication" class="form-select form-select-sm" required>
            <option value="">Select</option>
            <option value="Email" <?= ($editClient['preferred_communication'] ?? '')==='Email'?'selected':'' ?>>Email</option>
            <option value="Phone" <?= ($editClient['preferred_communication'] ?? '')==='Phone'?'selected':'' ?>>Phone</option>
            <option value="Portal" <?= ($editClient['preferred_communication'] ?? '')==='Portal'?'selected':'' ?>>Portal</option>
          </select>
        </div>

        <div class="col-md-12">
          <label class="form-label small">Address <span class="text-danger">*</span></label>
          <textarea name="address" class="form-control form-control-sm" rows="2" required><?= h($editClient['address'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="d-flex justify-content-end gap-2 mt-4">
        <button type="submit" class="btn btn-sm" style="background-color:brown; color:white;"><?= $mode==='edit'?'Update':'Save' ?></button>
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

<!-- Filter Form -->
<form method="get" class="row gy-2 gx-2 mb-3 align-items-center">
  <div class="col-auto">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search client / company / contact / phone / gst" autocomplete="off" class="form-control form-control-sm">
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
    <a href="client_master.php" class="btn btn-secondary btn-sm">Clear</a>
  </div>
</form>

<!-- Client Table -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Client Name</th>
            <th>Company Name</th>
            <th>Contact Person</th>
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
            <td><?= h($row['company_name']) ?></td>
            <td><?= h($row['contact_person']) ?></td>
            <td><?= h($row['email']) ?></td>
            <td><?= h($row['phone']) ?></td>
            <td><?= h($row['address']) ?></td>
            <td><?= h($row['country']) ?></td>
            <td><?= h($row['gst_no']) ?></td>
            <td><?= h($row['preferred_communication']) ?></td>
            <td>
              <form method="post" style="display:inline;" onsubmit="return confirm('Change status of <?= h($row['client_name']) ?> from <?= $row['status'] ?> to <?= $row['status']==='Active'?'Inactive':'Active' ?>?')">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="client_id" value="<?= (int)$row['client_id'] ?>">
                <button type="submit" class="btn btn-sm <?= $row['status']==='Active'?'btn-success':'btn-danger' ?>"><?= h($row['status']) ?></button>
              </form>
            </td>
            <td><?= h(formatDate($row['created_date'])) ?></td>
          <td class="text-end">
    <div class="d-inline-flex align-items-center client-action-group">
        <a class="btn btn-sm btn-warning" href="client_master.php?mode=edit&id=<?= (int)$row['client_id'] ?>" title="Edit Client">
            <i class="fa fa-edit"></i>
        </a>
        
        <form method="post" style="display:inline;" onsubmit="return confirm('Delete client <?= h($row['client_name']) ?>?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="client_id" value="<?= (int)$row['client_id'] ?>">
            <button class="btn btn-sm btn-danger" title="Delete Client">
                <i class="fa fa-trash"></i>
            </button>
        </form>
       <?php $target_month = date('Y-m'); 
       $target_month = date('Y-m');
$client_id = $row['client_id'];

// check if invoice exists for this client & month
$stmt = $mysqli->prepare("
    SELECT invoice_no 
    FROM client_invoice 
    WHERE client_id = ? 
      AND DATE_FORMAT(invoice_date, '%Y-%m') = ?
    LIMIT 1
");
$stmt->bind_param("is", $client_id, $target_month);
$stmt->execute();
$stmt->bind_result($existing_invoice_no);
$stmt->fetch();
$stmt->close();
 $numberOnly = preg_replace('/^[A-Z]+[0-9]+\/?/', '', $existing_invoice_no);
// decide link
if ($numberOnly) {
    $link = "generate_invoice.php?invoice_id=" . urlencode($numberOnly)."&invoice_month={$target_month}";
    $title = "View Invoice";
     $btnClass = "btn-success";   // green for existing invoice
    $icon = "fa-file-invoice-dollar"; // invoice exists
} else {
    $link = "create_invoice.php?client_id={$client_id}&invoice_month={$target_month}";
    $title = "Create Invoice";
      $btnClass = "btn-primary";   // blue for create
    $icon = "fa-file-circle-plus"; // new invoice icon
}?>
<a href="<?= $link ?>" class="btn <?= $btnClass ?>" title="<?= $title ?>">
   <i class="fa <?= $icon ?>"></i>
</a>
</div>
</td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="13" class="text-center py-4">No clients found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php endif; ?>

</div>

<script>
function applyFilter(){
    const q=document.querySelector('input[name="q"]').value.trim();
    const status=document.querySelector('select[name="status"]').value;
    const sort=document.querySelector('select[name="sort"]').value;
    const dir=document.querySelector('select[name="dir"]').value;
    const params=new URLSearchParams();
    if(q) params.set('q',q);
    if(status) params.set('status',status);
    if(sort) params.set('sort',sort);
    if(dir) params.set('dir',dir);
    window.location.href='client_master.php?'+params.toString();
}

// live search as typing
const qInput = document.querySelector('input[name="q"]');
if (qInput) {
    qInput.addEventListener('keyup', function(e){
        if (this.value.length >= 2 || this.value.length === 0) {
            // you can debounce if needed; for now perform immediate filtering via submit
            applyFilter();
        }
    });
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
