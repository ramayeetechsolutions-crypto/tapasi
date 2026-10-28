<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Helpers
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect_with($params = []) {
    $base = 'employee_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base"); exit;
}
function is_valid_date($d) {
    if (!$d) return false;
    $t = strtotime($d);
    if ($t === false) return false;
    return date('Y-m-d', $t) === $d;
}
function formatDate($d) {
    return (!empty($d) && $d != '0000-00-00') ? date('d-m-Y', strtotime($d)) : '—';
}

$today = date('Y-m-d');
$default_dob = date('Y-m-d', strtotime('-20 years'));

// --- AJAX for dynamic managers ---
if (isset($_GET['ajax']) && $_GET['ajax'] === 'team_leads') {
    $res = $mysqli->query("SELECT employee_name FROM employee_master WHERE user_role_id=3 AND status='Active'");
    $names = [];
    while ($r = $res->fetch_assoc()) $names[] = $r['employee_name'];
    header('Content-Type: application/json'); echo json_encode($names); exit;
}
if (isset($_GET['ajax']) && $_GET['ajax'] === 'admins') {
    $res = $mysqli->query("SELECT employee_name FROM employee_master WHERE user_role_id=5 AND status='Active'");
    $names = [];
    while ($r = $res->fetch_assoc()) $names[] = $r['employee_name'];
    header('Content-Type: application/json'); echo json_encode($names); exit;
}

// --- POST handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['employee_id'] ?? 0);
    $employee_name     = trim($_POST['employee_name'] ?? '');
    $employee_username = trim($_POST['employee_username'] ?? '');
    $password          = $_POST['password'] ?? '';
    $confirm_password  = $_POST['confirm_password'] ?? '';
    $email             = trim($_POST['email'] ?? '');
    $phone             = trim($_POST['phone'] ?? '');
    $date_of_birth     = trim($_POST['date_of_birth'] ?? '');
    $joining_date      = trim($_POST['joining_date'] ?? '') ?: $today;
   // $process           = isset($_POST['process']) ? (int)$_POST['process'] : null;
     //$process1           = isset($_POST['process1']) ? (int)$_POST['process1'] : null;
     if (isset($_POST['process'])) {
    // Convert array of IDs into comma-separated string
    $selected_processes = implode(",", $_POST['process']);  
} else {
    $selected_processes = "";
}
    $reporting_manager = trim($_POST['reporting_manager'] ?? '');
    $user_role_id      = isset($_POST['user_role_id']) ? (int)$_POST['user_role_id'] : 0;

    $errors = [];
    if ($action !== 'delete' && $action !== 'toggle') {
        if (!$employee_name) $errors[] = 'Employee Name is required.';
        if (!$employee_username) $errors[] = 'Username is required.';
        if (!is_valid_date($date_of_birth)) $errors[] = 'Valid Date of Birth is required.';
        if (!is_valid_date($joining_date)) $errors[] = 'Valid Joining Date is required.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid Email is required.';
        if (!$phone || !preg_match('/^\+?\d{10,15}$/', $phone)) $errors[] = 'Phone Number is required.';
        if ($user_role_id <= 0) $errors[] = 'User Role is required.';
        if (in_array($user_role_id, [3,4,5]) && !$reporting_manager) {
            $errors[] = 'Reporting Manager is required.';
        }
        if (($action === 'add' || ($action === 'edit' && $password !== ''))) {
            if (strlen($password) < 6) $errors[] = 'Password (min 6 chars) is required.';
            if ($password !== $confirm_password) $errors[] = 'Passwords do not match.';
        }
    }

    if ($employee_username) {
        $sql = "SELECT employee_id FROM employee_master WHERE employee_username = ?";
        $params = [$employee_username]; $types = 's';
        if ($action === 'edit') { $sql .= " AND employee_id != ?"; $params[] = $id; $types .= 'i'; }
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows > 0) { $errors[] = 'Username already exists.'; }
        $stmt->close();
    }

    if ($errors) redirect_with(['msg' => implode(' ', $errors), 'type' => 'danger']);

    if ($password) $password_hash = password_hash($password, PASSWORD_DEFAULT);
//echo "aaaaa".$selected_processes;
//die;
    if ($action === 'add') {
// On Add
$stmt = $mysqli->prepare("INSERT INTO employee_master 
    (employee_name, employee_username, password_hash, email, phone, date_of_birth, joining_date, process, reporting_manager, user_role_id, status) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");

$stmt->bind_param(
    "sssssssssi", 
    $employee_name, 
    $employee_username, 
    $password_hash, 
    $email, 
    $phone, 
    $date_of_birth, 
    $joining_date, 
    $selected_processes,   // only process
    $reporting_manager, 
    $user_role_id
);

        $stmt->execute(); $stmt->close();
        redirect_with(['msg' => "Employee '{$employee_name}' added", 'type' => 'success']);
    } elseif ($action === 'edit' && $id > 0) {
        if ($password) {
// On Edit with password
$stmt = $mysqli->prepare("UPDATE employee_master 
    SET employee_name=?, employee_username=?, password_hash=?, email=?, phone=?, date_of_birth=?, joining_date=?, process=?, reporting_manager=?, user_role_id=? 
    WHERE employee_id=?");

$stmt->bind_param(
    "sssssssssii", 
    $employee_name, 
    $employee_username, 
    $password_hash, 
    $email, 
    $phone, 
    $date_of_birth, 
    $joining_date, 
    $selected_processes, 
    $reporting_manager, 
    $user_role_id, 
    $id
);
} else {
          $stmt = $mysqli->prepare("UPDATE employee_master 
    SET employee_name=?, employee_username=?, email=?, phone=?, date_of_birth=?, joining_date=?, process=?, reporting_manager=?, user_role_id=? 
    WHERE employee_id=?");

$stmt->bind_param(
    "ssssssssii", 
    $employee_name, 
    $employee_username, 
    $email, 
    $phone, 
    $date_of_birth, 
    $joining_date, 
    $selected_processes, 
    $reporting_manager, 
    $user_role_id, 
    $id
);
}
        $stmt->execute(); $stmt->close();
        redirect_with(['msg' => "Employee '{$employee_name}' updated", 'type' => 'info']);


// On Edit without password

    } elseif ($action === 'delete' && $id > 0) {
        $stmt = $mysqli->prepare("DELETE FROM employee_master WHERE employee_id=?");
        $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();
        redirect_with(['msg' => "Employee deleted", 'type' => 'warning']);
    } elseif ($action === 'toggle' && $id > 0) {
        $stmt = $mysqli->prepare("SELECT status FROM employee_master WHERE employee_id=?");
        $stmt->bind_param("i", $id); $stmt->execute(); $res = $stmt->get_result()->fetch_assoc(); $stmt->close();
        $new_status = ($res['status'] == 'Active') ? 'Inactive' : 'Active';
        $stmt = $mysqli->prepare("UPDATE employee_master SET status=? WHERE employee_id=?");
        $stmt->bind_param("si", $new_status, $id); $stmt->execute(); $stmt->close();
        redirect_with(['msg' => "Status changed to {$new_status}", 'type' => 'info']);
    } else redirect_with();
}

// --- MODE ---
$mode = $_GET['mode'] ?? 'view';
$editRow = null;
if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM employee_master WHERE employee_id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else $mode = 'view';
}

// Filters
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'employee_id';
$dir = strtolower($_GET['dir'] ?? 'desc');
$sortable = ['employee_id','employee_name','employee_username','email','phone','date_of_birth','joining_date','process','reporting_manager','user_role_id','status'];
if (!in_array($sort,$sortable,true)) $sort='employee_id';
$dir = ($dir=='asc')?'asc':'desc';

$sql = "SELECT e.*, r.role_name, j.process_name AS process_name, j1.process_name AS process_name1 FROM employee_master e 
        LEFT JOIN user_role_master r ON e.user_role_id = r.role_id
        LEFT JOIN process_master j ON e.process = j.process_id
        LEFT JOIN process_master j1 ON e.process1 = j1.process_id
        ";
$cond=[];$params=[];$types='';
if ($q) { $cond[]="(e.employee_name LIKE ? OR e.employee_username LIKE ? OR e.email LIKE ?)"; $tval="%$q%"; $params=array_merge($params,[$tval,$tval,$tval]); $types.='sss'; }
if ($fstatus==='Active'||$fstatus==='Inactive'){ $cond[]="e.status=?"; $params[]=$fstatus; $types.='s'; }
if ($cond) $sql.=" WHERE ".implode(" AND ",$cond);
$sql.=" ORDER BY $sort $dir";
if ($types) { $stmt=$mysqli->prepare($sql); $stmt->bind_param($types,...$params); $stmt->execute(); $rows=$stmt->get_result(); $stmt->close(); }
else $rows=$mysqli->query($sql);

$roles=$mysqli->query("SELECT role_id, role_name FROM user_role_master WHERE role_name!='Super Admin' ORDER BY role_name");
$processes=$mysqli->query("SELECT process_id, process_name FROM process_master WHERE status='Active' ORDER BY process_id");
$processes1=$mysqli->query("SELECT process_id, process_name FROM process_master WHERE status='Active' ORDER BY process_id");

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Employee Master</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<style>.btn-brown{background:#795548;color:#fff;border:none}.btn-brown:hover{background:#5d4037;color:#fff}

/* Reduce vertical space */
.row.mb-1 {
    margin-bottom: 4px !important;
}

h6.mb-1 {
    margin-bottom: 4px !important;
    margin-top: 4px !important;
}

/* Compact HR */
hr.my-1 {
    margin: 4px 0 !important;
}

/* Checkboxes closer */
.form-check-label {
    display: inline-block;
    margin-bottom: 2px;
}

.col-md-3.mb-1 {
    margin-bottom: 4px !important;
}
.form-check-label {
    margin-right: 8px;
}

</style>
</head>
<body>
<div class="container mt-3">
<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show"><?= h($_GET['msg']) ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($mode==='add'||$mode==='edit'): ?>
<div class="d-flex justify-content-between mb-3"><h5><?= $mode==='edit'?'Edit':'Add' ?> Employee</h5><a href="employee_master.php" class="btn btn-sm btn-secondary">Back</a></div>
<div class="card p-4 mx-auto" style="max-width:900px;">
<form method="post" class="row g-3" onsubmit="return checkPasswordMatch()">
    <input type="hidden" name="action" value="<?= $mode==='edit'?'edit':'add' ?>">
    <?php if ($mode==='edit'): ?><input type="hidden" name="employee_id" value="<?= $editRow['employee_id'] ?>"><?php endif; ?>

    <div class="col-md-6"><label class="form-label">Employee Name *</label>
        <input type="text" name="employee_name" class="form-control" required value="<?= h($editRow['employee_name'] ?? $_POST['employee_name'] ?? '') ?>"></div>

    <div class="col-md-6"><label class="form-label">Username *</label>
        <input type="text" name="employee_username" class="form-control" required value="<?= h($editRow['employee_username'] ?? $_POST['employee_username'] ?? '') ?>"></div>

    <div class="col-md-6"><label class="form-label">Password <?= $mode==='edit'?'(leave blank to keep unchanged)':'*' ?></label>
        <div class="input-group">
            <input type="password" id="password" name="password" class="form-control" <?= $mode==='add'?'required minlength="6"':'' ?>>
            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password')"><i class="bi bi-eye"></i></button>
        </div>
    </div>

    <div class="col-md-6"><label class="form-label">Confirm Password <?= $mode==='edit'?'(only if changing password)':'*' ?></label>
        <div class="input-group">
            <input type="password" id="confirm_password" name="confirm_password" class="form-control" <?= $mode==='add'?'required minlength="6"':'' ?>>
            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')"><i class="bi bi-eye"></i></button>
        </div>
    </div>

    <div class="col-md-6"><label class="form-label">Email *</label>
        <input type="email" name="email" class="form-control" required value="<?= h($editRow['email'] ?? $_POST['email'] ?? '') ?>"></div>

    <div class="col-md-6"><label class="form-label">Phone *</label>
        <input type="text" name="phone" pattern="\+?\d{10,15}" class="form-control" required value="<?= h($editRow['phone'] ?? $_POST['phone'] ?? '') ?>"></div>

    <div class="col-md-6"><label class="form-label">Date of Birth *</label>
        <input type="date" name="date_of_birth" class="form-control" required value="<?= h($editRow['date_of_birth'] ?? $_POST['date_of_birth'] ?? $default_dob) ?>"></div>

    <div class="col-md-6"><label class="form-label">Joining Date *</label>
        <input type="date" name="joining_date" class="form-control" required value="<?= h($editRow['joining_date'] ?? $_POST['joining_date'] ?? $today) ?>"></div>

    <!--div class="col-md-6"><label class="form-label">Process</label>
        <select name="process" class="form-select">
            <option value="">Select Process</option>
            <?php if ($processes){ $processes->data_seek(0); while($p=$processes->fetch_assoc()){ $selected=(($editRow['process'] ?? $_POST['process'] ?? '')==$p['process_id'])?'selected':''; echo "<option value='{$p['process_id']}' $selected>".h($p['process_name'])."</option>"; }} ?>
        </select></div>
<div class="col-md-6"><label class="form-label">Process (Additional)</label>
        <select name="process1" class="form-select">
            <option value="">Select Process</option>
            <?php if ($processes1){ $processes1->data_seek(0); while($p=$processes1->fetch_assoc()){ $selected=(($editRow['process1'] ?? $_POST['process1'] ?? '')==$p['process_id'])?'selected':''; echo "<option value='{$p['process_id']}' $selected>".h($p['process_name'])."</option>"; }} ?>
        </select></div-->
<div class="row">
    
    <?php

// Fetch all processes
$processes = $mysqli->query("SELECT process_id, process_name 
                             FROM process_master 
                             WHERE status='Active' 
                             ORDER BY process_id");

$saved = [];
if (!empty($editRow['process'])) {
    $saved = explode(",", $editRow['process']); // previously saved processes
}

// Buckets
$ce = $pag = $others = [];

while ($row = $processes->fetch_assoc()) {
    $name = $row['process_name'];

    // --- CE Group ---
    if ($name === "CE" || str_ends_with($name, "-CE") || $name === "LE" || $name === "Sub TL") {
        $ce[] = $row;

    // --- PAG Group ---
    } elseif ($name === "PAG" || str_ends_with($name, "-PAG") || $name === "QC") {
        $pag[] = $row;

    // --- Others ---
    } else {
        $others[] = $row;
    }
}

// Helper to render group
function renderGroup($groupName, $items, $saved) {
    echo "<div class='mb-2'><h6 class='mb-1'><b>$groupName</b></h6>";
    echo "<div class='row'>";

    foreach ($items as $index => $row) {
        $checked = in_array($row['process_id'], $saved) ? 'checked' : '';
        echo '<div class="col-md-3 mb-1">
                <label class="form-check-label">
                    <input type="checkbox" class="form-check-input" 
                           name="process[]" 
                           value="'.$row['process_id'].'" '.$checked.'>
                    '.htmlspecialchars($row['process_name']).'
                </label>
              </div>';

        // close/open row every 4 items
        if (($index + 1) % 4 == 0 && $index + 1 < count($items)) {
            echo '</div><div class="row">';
        }
    }

    echo "</div></div><hr class='my-1'>";
}

// Render partitions
renderGroup("CE Processes", $ce, $saved);
renderGroup("PAG Processes", $pag, $saved);
renderGroup("XML Processes", $others, $saved);
?>

</div>



    <div class="col-md-6"><label class="form-label">User Role *</label>
        <select name="user_role_id" id="user_role_id" class="form-select" required>
            <option value="">Select Role</option>
            <?php if ($roles){ $roles->data_seek(0); while($r=$roles->fetch_assoc()){ $selected=((int)($editRow['user_role_id'] ?? $_POST['user_role_id'] ?? 0)===(int)$r['role_id'])?'selected':''; echo "<option value='{$r['role_id']}' $selected>".h($r['role_name'])."</option>"; }} ?>
        </select></div>

    <div class="col-md-6" id="rmContainer"></div>

    <div class="col-12 mt-3 d-flex justify-content-end gap-2"><button type="submit" class="btn btn-brown"><?= $mode==='edit'?'Update':'Save' ?></button><a href="employee_master.php" class="btn btn-secondary">Cancel</a></div>
</form>
</div>

<script>
function togglePassword(id){const input=document.getElementById(id);input.type=input.type==="password"?"text":"password";}
function checkPasswordMatch(){const pass=document.getElementById('password').value;const conf=document.getElementById('confirm_password').value;if(pass!==conf){alert("Passwords do not match!");return false;}return true;}
document.addEventListener('DOMContentLoaded',()=>{const roleSelect=document.getElementById('user_role_id');const rmContainer=document.getElementById('rmContainer');async function updateReportingManager(){const roleId=parseInt(roleSelect.value);let html='';if(roleId===5){html=`<label class="form-label">Reporting Manager *</label><input type="text" class="form-control" value="Super Admin" disabled><input type="hidden" name="reporting_manager" value="Super Admin">`;}else if(roleId===4){const response=await fetch('employee_master.php?ajax=team_leads');const data=await response.json();html=`<label class="form-label">Reporting Manager *</label><select name="reporting_manager" class="form-select" required><option value="">Select Team Lead</option>${data.map(name=>`<option value="${name}">${name}</option>`).join('')}</select>`;}else if(roleId===3){const response=await fetch('employee_master.php?ajax=admins');const data=await response.json();html=`<label class="form-label">Reporting Manager *</label><select name="reporting_manager" class="form-select" required><option value="">Select Admin</option>${data.map(name=>`<option value="${name}">${name}</option>`).join('')}</select>`;}else{let rmVal="<?= h($editRow['reporting_manager'] ?? $_POST['reporting_manager'] ?? '') ?>";html=`<label class="form-label">Reporting Manager *</label><input type="text" name="reporting_manager" class="form-control" value="${rmVal}" required />`;}rmContainer.innerHTML=html;const select=rmContainer.querySelector('select[name="reporting_manager"]');if(select){let selectedVal="<?= h($editRow['reporting_manager'] ?? $_POST['reporting_manager'] ?? '') ?>";if(selectedVal) select.value=selectedVal;}}roleSelect.addEventListener('change',updateReportingManager);updateReportingManager();});
</script>

<?php else: ?>
<div class="d-flex justify-content-between mb-3"><h5>Employee Master</h5><a href="employee_master.php?mode=add" class="btn btn-success btn-sm">+ Add </a></div>
    <div class="d-flex flex-wrap gap-2 mb-3">
<input type="text" id="searchInput" class="form-control form-control-sm" style="width:250px" placeholder="Search" value="<?=h($q)?>">
<select id="statusSelect" class="form-select form-select-sm" style="width:120px;">
<option value="">All</option><option value="Active" <?=$fstatus=='Active'?'selected':''?>>Active</option><option value="Inactive" <?=$fstatus=='Inactive'?'selected':''?>>Inactive</option>
</select>
<select id="sortSelect" class="form-select form-select-sm" style="width:160px;"><?php foreach($sortable as $col){$sel=$col==$sort?'selected':'';echo"<option value='$col' $sel>".ucwords(str_replace('_',' ',$col))."</option>";}?></select>
<select id="dirSelect" class="form-select form-select-sm" style="width:100px;"><option value="asc" <?=$dir=='asc'?'selected':''?>>Asc</option><option value="desc" <?=$dir=='desc'?'selected':''?>>Desc</option></select>
<button class="btn btn-primary btn-sm" onclick="filterTable()">Go</button>
<a href="employee_master.php" class="btn btn-secondary btn-sm">Clear</a>
</div>
<div class="table-responsive">
<table class="table table-bordered align-middle">
<thead class="table-light">
<tr>
    <th>ID</th><th>Name</th><th>Username</th><th>Email</th><th>Phone</th>
    <th>DOB</th><th>Joining</th><th>Process</th><th>Manager</th><th>Role</th><th>Status</th><th>Actions</th>
</tr>
</thead>
<tbody>
<?php if ($rows && $rows->num_rows>0): while($r=$rows->fetch_assoc()): 
    $process_name = ""; // reset for every row

    $process_ids = explode(",", $r['process']);
    if (!empty($process_ids)) {
        $names = [];
        foreach ($process_ids as $pid) {
            $pid = (int)$pid; // prevent SQL injection
            if ($pid > 0) {
                $p = $mysqli->query("SELECT process_name FROM process_master WHERE process_id = $pid")->fetch_assoc();
                if ($p) $names[] = $p['process_name'];
            }
        }
        $process_name = implode(", ", $names);
    }
?>


    <td><?= h($r['employee_id']) ?></td>
    <td><?= h($r['employee_name']) ?></td>
    <td><?= h($r['employee_username']) ?></td>
    <td><?= h($r['email']) ?></td>
    <td><?= h($r['phone']) ?></td>
    <td><?= formatDate($r['date_of_birth']) ?></td>
    <td><?= formatDate($r['joining_date']) ?></td>
    <td><?= h($process_name)?></td>
    
    <td><?= h($r['reporting_manager']) ?></td>
    <td><?= h($r['role_name']) ?></td>
    <td><form method="post" style="display:inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="employee_id" value="<?= $r['employee_id'] ?>"><button class="btn btn-sm <?= $r['status']=='Active'?'btn-success':'btn-secondary' ?>"><?= h($r['status']) ?></button></form></td>
    <td>
        <a href="employee_master.php?mode=edit&id=<?= $r['employee_id'] ?>" class="btn btn-sm btn-warning"><i class="fa fa-edit"></i></a>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this employee?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="employee_id" value="<?= $r['employee_id'] ?>">
            <button class="btn btn-sm btn-danger"><i class="fa fa-trash"></i></button>
        </form>
    </td>
</tr>
<?php endwhile; else: ?>
<tr><td colspan="12" class="text-center">No records found</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>?
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
