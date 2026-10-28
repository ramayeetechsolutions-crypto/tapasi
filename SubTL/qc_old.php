<?php
require 'db.php';
session_start();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

$current_user = $_SESSION['username'] ?? '';
if (!$current_user) {
    echo "<div class='alert alert-danger'>Session expired. Please log in again.</div>";
    exit;
}

// get employee_id
$employee_id = null;
$stmt = $mysqli->prepare("SELECT employee_id FROM employee_master WHERE employee_username=?");
if ($stmt) {
    $stmt->bind_param("s", $current_user);
    $stmt->execute();
    $stmt->bind_result($employee_id);
    $stmt->fetch();
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_qc') {
    $job_id = intval($_POST['job_id'] ?? 0);
    $pages = intval($_POST['pages'] ?? 0);
    $tables_count = intval($_POST['tables_count'] ?? 0);
    $due_date = $_POST['due_date'] ?? null;
    $rtat = intval($_POST['rtat'] ?? 0);
    $atat = intval($_POST['atat'] ?? 0);
    $dispatch_date = $_POST['dispatch_date'] ?? null;
    $upload_by = trim($_POST['upload_by'] ?? '');
    $path = trim($_POST['path'] ?? '');
    $status = $_POST['status'] ?? 'In Progress';

    // job snapshot
    $snap = $mysqli->prepare("SELECT j.job_date, c.client_name, j.journal_issue, j.article, jm.job_name
                              FROM jobs j
                              JOIN client_master c ON j.client_id=c.client_id
                              JOIN job_master jm ON j.process_id=jm.job_id
                              WHERE j.job_id=? LIMIT 1");
    $snap->bind_param("i", $job_id);
    $snap->execute();
    $snap->bind_result($job_date, $client_name, $journal_issue, $article, $process_name);
    $snap->fetch();
    $snap->close();

    // get images from pag
    $images = 0;
    $pstmt = $mysqli->prepare("SELECT images FROM pag WHERE job_id=? LIMIT 1");
    $pstmt->bind_param("i", $job_id);
    $pstmt->execute();
    $pstmt->bind_result($images);
    $pstmt->fetch();
    $pstmt->close();

    // check existing QC
    $stmt = $mysqli->prepare("SELECT id FROM qc WHERE job_id=? LIMIT 1");
    $stmt->bind_param("i", $job_id);
    $stmt->execute();
    $stmt->bind_result($qcd_id);
    $exists = $stmt->fetch();
    $stmt->close();

    if ($exists) {
        $upd = $mysqli->prepare("UPDATE qc 
            SET pages=?, images=?, tables_count=?, due_date=?, 
                rtat=?, atat=?, dispatch_date=?, upload_by=?, path=?, status=?, 
                last_updated=NOW()
            WHERE job_id=?");
        $upd->bind_param("iiisiiisssi",
            $pages, $images, $tables_count, $due_date,
            $rtat, $atat, $dispatch_date, $upload_by, $path, $status, $job_id
        );
        $upd->execute();
        $upd->close();
    } else {
        $ins = $mysqli->prepare("INSERT INTO qc 
            (job_id, date, client, journal_issue, article, pages, images, tables_count, process, 
             due_date, rtat, atat, dispatch_date, upload_by, path, status, created_at, last_updated)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $ins->bind_param("issssiiissssssss",
            $job_id, $job_date, $client_name, $journal_issue, $article,
            $pages, $images, $tables_count, $process_name,
            $due_date, $rtat, $atat, $dispatch_date, $upload_by, $path, $status
        );
        $ins->execute();
        $ins->close();
    }

    header("Location: qc.php?msg=" . urlencode("QC saved.") . "&type=success");
    exit;
}

// Filters / sorting
$q = trim($_GET['q'] ?? '');
$filter_client = trim($_GET['client'] ?? '');
$filter_article = trim($_GET['article'] ?? '');
$filter_user = trim($_GET['user'] ?? '');
$filter_process = trim($_GET['process'] ?? '');
$sort = $_GET['sort'] ?? 'j.job_date';
$dir = $_GET['dir'] ?? 'DESC';
$dir = strtoupper($dir)==='ASC' ? 'ASC' : 'DESC';

$valid_sorts = ['job_id','j.job_date','client_name','journal_issue','process','pag_date'];
if (!in_array($sort, $valid_sorts)) $sort = 'j.job_date';

$where = [];
$params = [];
$types = '';

$where[] = " ( (jm.job_name = 'PAG' AND pag.status='Completed') 
           OR (jm.job_name = 'CE+PAG' AND ce.status='Completed' AND le.status='Completed' AND pag.status='Completed') ) ";

if ($q) {
    $where[] = "(c.client_name LIKE ? OR j.journal_issue LIKE ?)";
    $params[]="%$q%"; $params[]="%$q%"; $types.='ss';
}
if ($filter_client) { $where[]="c.client_name LIKE ?"; $params[]="%$filter_client%"; $types.='s'; }
if ($filter_article){ $where[]="j.article LIKE ?"; $params[]="%$filter_article%"; $types.='s'; }
if ($filter_process){ $where[]="jm.job_name=?"; $params[]=$filter_process; $types.='s'; }
if ($filter_user) {
    $where[]="(ec.employee_username=? OR el.employee_username=? OR ep.employee_username=? OR qcd.upload_by=?)";
    $params=array_merge($params,[$filter_user,$filter_user,$filter_user,$filter_user]); $types.='ssss';
}

$where_sql = $where ? 'WHERE '.implode(' AND ',$where) : '';

$sql = "SELECT j.job_id, j.job_date, c.client_name, j.journal_issue, j.article,
       qcd.pages, pag.images, qcd.tables_count, qcd.due_date,
       jm.job_name AS process,
       ce.ce_date, ec.employee_username AS ce_done_by,
       le.le_date, el.employee_username AS le_done_by,
       pag.pag_date, ep.employee_username AS pag_done_by,
       qcd.rtat, qcd.atat, qcd.dispatch_date, qcd.upload_by, qcd.path, qcd.status
    FROM jobs j
    JOIN client_master c ON j.client_id = c.client_id
    JOIN job_master jm ON j.process_id = jm.job_id
    LEFT JOIN ce ON ce.job_id = j.job_id
    LEFT JOIN employee_master ec ON ce.done_by = ec.employee_id
    LEFT JOIN le ON le.job_id = j.job_id
    LEFT JOIN employee_master el ON le.done_by = el.employee_id
    LEFT JOIN pag ON pag.job_id = j.job_id
    LEFT JOIN employee_master ep ON pag.done_by = ep.employee_id
    LEFT JOIN qc qcd ON qcd.job_id = j.job_id
    $where_sql
    ORDER BY $sort $dir";

$stmt = $mysqli->prepare($sql);
if (!$stmt) die("Prepare failed: ".$mysqli->error);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$results=$stmt->get_result();

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>My QC Jobs</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" />
<style>
.modal-dialog{max-width:420px;}
label{font-size:0.9rem;}
input,select{font-size:0.9rem;padding:5px 7px;}
.btn-primary{background:#8B4513;border:none;}
.btn-primary:hover{background:#5C2E0C;}
.btn-warning{border-radius:5px;}
table td,th{vertical-align:middle;padding:7px 10px;white-space:nowrap;font-size:0.9rem;}
</style>
</head>
<body>
<div class="container mt-3">
<h2>My QC Jobs</h2>
<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-<?=h($_GET['type']??'info')?> alert-dismissible fade show">
<?=h($_GET['msg'])?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Filter Form -->
<form method="get" class="row gy-2 gx-2 mb-3 align-items-center">
<div class="col-auto"><input type="text" name="q" placeholder="Search client / issue" value="<?=h($q)?>" class="form-control form-control-sm"/></div>
<div class="col-auto"><input type="text" name="client" placeholder="Client" value="<?=h($filter_client)?>" class="form-control form-control-sm"/></div>
<div class="col-auto"><input type="text" name="article" placeholder="Article" value="<?=h($filter_article)?>" class="form-control form-control-sm"/></div>
<div class="col-auto"><input type="text" name="user" placeholder="User" value="<?=h($filter_user)?>" class="form-control form-control-sm"/></div>
<div class="col-auto">
<select name="process" class="form-select form-select-sm">
<option value="">All</option>
<option value="PAG" <?=$filter_process==='PAG'?'selected':''?>>PAG</option>
<option value="CE+PAG" <?=$filter_process==='CE+PAG'?'selected':''?>>CE+PAG</option>
</select>
</div>
<div class="col-auto">
<select name="sort" class="form-select form-select-sm">
<option value="j.job_date" <?=$sort==='j.job_date'?'selected':''?>>Date</option>
<option value="client_name" <?=$sort==='client_name'?'selected':''?>>Client</option>
<option value="journal_issue" <?=$sort==='journal_issue'?'selected':''?>>Issue</option>
<option value="process" <?=$sort==='process'?'selected':''?>>Process</option>
<option value="pag_date" <?=$sort==='pag_date'?'selected':''?>>PAG Date</option>
</select>
</div>
<div class="col-auto">
<select name="dir" class="form-select form-select-sm">
<option value="DESC" <?=$dir==='DESC'?'selected':''?>>Desc</option>
<option value="ASC" <?=$dir==='ASC'?'selected':''?>>Asc</option>
</select>
</div>
<div class="col-auto">
<button class="btn btn-primary btn-sm" type="submit">Go</button>
<a href="qc.php" class="btn btn-outline-secondary btn-sm">Clear</a>
<button type="button" onclick="exportTableToExcel('qcTable','qc_jobs')" class="btn btn-success btn-sm">Export</button>
</div>
</form>

<!-- Table -->
<div class="table-responsive" style="max-height:640px;overflow:auto;">
<table class="table table-striped table-bordered" id="qcTable">
<thead class="table-light">
<tr>
<th>#</th><th>Date</th><th>Client</th><th>Issue</th><th>Article</th>
<th>Pages</th><th>Images (PAG)</th><th>Tables</th><th>Process</th><th>Due</th>
<th>CE Date</th><th>CE By</th><th>LE Date</th><th>LE By</th>
<th>PAG Date</th><th>PAG By</th><th>R-TAT</th><th>A-TAT</th>
<th>Dispatch</th><th>Upload By</th><th>Path</th><th>Status</th><th>Actions</th>
</tr>
</thead>
<tbody>
<?php if ($results->num_rows>0): $i=1; while($row=$results->fetch_assoc()): ?>
<tr>
<td><?=$i++?></td>
<td><?=!empty($row['job_date'])?date('d-m-Y',strtotime($row['job_date'])):'—'?></td>
<td><?=h($row['client_name'])?></td>
<td><?=h($row['journal_issue'])?></td>
<td><?=h($row['article'])?></td>
<td><?=intval($row['pages'])?></td>
<td><?=intval($row['images'])?></td>
<td><?=intval($row['tables_count'])?></td>
<td><?=h($row['process'])?></td>
<td><?=!empty($row['due_date'])?date('d-m-Y',strtotime($row['due_date'])):'—'?></td>
<td><?=!empty($row['ce_date'])?date('d-m-Y',strtotime($row['ce_date'])):'—'?></td>
<td><?=h($row['ce_done_by']??'—')?></td>
<td><?=!empty($row['le_date'])?date('d-m-Y',strtotime($row['le_date'])):'—'?></td>
<td><?=h($row['le_done_by']??'—')?></td>
<td><?=!empty($row['pag_date'])?date('d-m-Y',strtotime($row['pag_date'])):'—'?></td>
<td><?=h($row['pag_done_by']??'—')?></td>
<td><?=intval($row['rtat']??0)?></td>
<td><?=intval($row['atat']??0)?></td>
<td><?=!empty($row['dispatch_date'])?date('d-m-Y',strtotime($row['dispatch_date'])):'—'?></td>
<td><?=h($row['upload_by']??'—')?></td>
<td><?=!empty($row['path'])?'<a href="'.h($row['path']).'" target="_blank">File</a>':'—'?></td>
<td><?=h($row['status']??'In Progress')?></td>
<td>
<?php if(($row['status']??'')!=='Completed'): ?>
<button class="btn btn-warning btn-sm edit-btn" data-bs-toggle="modal" data-bs-target="#editModal"
 data-job_id="<?=$row['job_id']?>" data-pages="<?=$row['pages']?>" data-tables="<?=$row['tables_count']?>"
 data-due="<?=$row['due_date']?>" data-rtat="<?=$row['rtat']?>" data-atat="<?=$row['atat']?>"
 data-dispatch="<?=$row['dispatch_date']?>" data-upload="<?=h($row['upload_by'])?>" data-path="<?=h($row['path'])?>" data-status="<?=$row['status']?>">
 <i class="bi bi-pencil-square"></i></button>
<?php else: ?><span class="badge bg-success">Completed</span><?php endif; ?>
</td>
</tr>
<?php endwhile; else: ?>
<tr><td colspan="23" class="text-center">No QC records found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog">
<form method="post" class="modal-content">
<div class="modal-header">
<h5 class="modal-title">Edit QC Details</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="action" value="save_qc">
<input type="hidden" name="job_id" id="m_job_id">

<div class="row g-2">
  <div class="col-6">
    <label class="form-label">Pages</label>
    <input type="number" name="pages" id="m_pages" class="form-control form-control-sm">
  </div>
  <div class="col-6">
    <label class="form-label">Tables</label>
    <input type="number" name="tables_count" id="m_tables" class="form-control form-control-sm">
  </div>
  <div class="col-6">
    <label class="form-label">R-TAT</label>
    <input type="number" name="rtat" id="m_rtat" class="form-control form-control-sm">
  </div>
  <div class="col-6">
    <label class="form-label">A-TAT</label>
    <input type="number" name="atat" id="m_atat" class="form-control form-control-sm">
  </div>
  <div class="col-6">
    <label class="form-label">Due Date</label>
    <input type="date" name="due_date" id="m_due_date" class="form-control form-control-sm">
  </div>
  <div class="col-6">
    <label class="form-label">Dispatch Date</label>
    <input type="date" name="dispatch_date" id="m_dispatch" class="form-control form-control-sm">
  </div>
  <div class="col-6">
    <label class="form-label">Upload By</label>
    <input type="text" name="upload_by" id="m_upload" class="form-control form-control-sm">
  </div>
  <div class="col-6">
    <label class="form-label">Path / File</label>
    <input type="text" name="path" id="m_path" class="form-control form-control-sm">
  </div>
  <div class="col-12">
    <label class="form-label">Status</label>
    <select name="status" id="m_status" class="form-select form-select-sm">
      <option value="In Progress">In Progress</option>
      <option value="Completed">Completed</option>
    </select>
  </div>
</div>
</div>
<div class="modal-footer">
<button type="submit" class="btn btn-primary btn-sm">Save</button>
<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
</div>
</form>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.edit-btn').forEach(btn=>{
 btn.addEventListener('click',()=>{
  document.getElementById('m_job_id').value=btn.dataset.job_id;
  document.getElementById('m_pages').value=btn.dataset.pages||'';
  document.getElementById('m_tables').value=btn.dataset.tables||'';
  document.getElementById('m_due_date').value=btn.dataset.due||'';
  document.getElementById('m_rtat').value=btn.dataset.rtat||'';
  document.getElementById('m_atat').value=btn.dataset.atat||'';
  document.getElementById('m_dispatch').value=btn.dataset.dispatch||'';
  document.getElementById('m_upload').value=btn.dataset.upload||'';
  document.getElementById('m_path').value=btn.dataset.path||'';
  document.getElementById('m_status').value=btn.dataset.status||'In Progress';
 });
});
function exportTableToExcel(tableID,filename=''){
 var table=document.getElementById(tableID);
 var html=table.outerHTML.replace(/ /g,'%20');
 var link=document.createElement("a");
 filename=filename?filename+'.xls':'export.xls';
 link.href='data:application/vnd.ms-excel,'+html;
 link.download=filename;
 document.body.appendChild(link);
 link.click();
 document.body.removeChild(link);
}
</script>
</body>
</html>
