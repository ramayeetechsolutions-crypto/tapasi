<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

function redirect_with($params = []) {
    $base = 'job_management.php';
    if (!empty($params)) {
        $base .= '?' . http_build_query($params);
    }
    header("Location: $base");
    exit;
}

// Load active employees for assignment
$executives_array = [];
$execs_data = $mysqli->query("SELECT employee_id, employee_name FROM employee_master WHERE status='Active'");
while ($ex = $execs_data->fetch_assoc()) {
    $executives_array[$ex['employee_id']] = $ex['employee_name'];
}

$statuses = ['Pending', 'In Progress', 'Completed', 'Dispatched'];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $job_id = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
    $client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
    $journal_name = trim($_POST['journal_name'] ?? '');
    $article_title = trim($_POST['article_title'] ?? '');
    $no_of_pages = isset($_POST['no_of_pages']) ? (int)$_POST['no_of_pages'] : 0;
    $no_of_equations = isset($_POST['no_of_equations']) ? (int)$_POST['no_of_equations'] : 0;
    $no_of_images = isset($_POST['no_of_images']) ? (int)$_POST['no_of_images'] : 0;
    $no_of_tables = isset($_POST['no_of_tables']) ? (int)$_POST['no_of_tables'] : 0;
    $dispatch_date = $_POST['dispatch_date'] ?: null;
    $assigned_to = isset($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : 0;
    $status = $_POST['status'] ?? 'Pending';
    $rtat = isset($_POST['rtat']) ? (int)$_POST['rtat'] : 0;
    $atat = isset($_POST['atat']) ? (int)$_POST['atat'] : 0;
    $created_by = $_SESSION['username'] ?? 'System';

    $upload_dir = 'uploads/';
    if (!is_dir($upload_dir)) { @mkdir($upload_dir,0777,true); }

    $input_file_path = !empty($_FILES['input_file']['name']) ? $upload_dir.time().'_'.basename($_FILES['input_file']['name']) : null;
    if ($input_file_path) { @move_uploaded_file($_FILES['input_file']['tmp_name'], $input_file_path); }

    $output_file_path = !empty($_FILES['output_file']['name']) ? $upload_dir.time().'_'.basename($_FILES['output_file']['name']) : null;
    if ($output_file_path) { @move_uploaded_file($_FILES['output_file']['tmp_name'], $output_file_path); }

    // Stage fields
    $stages = ['ce','le','pag','qc'];
    foreach ($stages as $s) {
        ${$s.'_start'} = $_POST[$s.'_start'] ?: null;
        ${$s.'_end'} = $_POST[$s.'_end'] ?: null;
        ${$s.'_done_by'} = ($_POST[$s.'_done_by'] !== '') ? (int)$_POST[$s.'_done_by'] : null;
    }

    if ($action === 'add') {
        $sql = "INSERT INTO job_management
            (client_id,journal_name,article_title,no_of_pages,no_of_equations,no_of_images,no_of_tables,
            dispatch_date,assigned_to,status,input_file,output_file,
            ce_start,ce_end,ce_done_by,le_start,le_end,le_done_by,
            pag_start,pag_end,pag_done_by,qc_start,qc_end,qc_done_by,
            rtat,atat,created_by,created_date)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param(
            "issiiiisisssssissississiiis",
            $client_id,$journal_name,$article_title,$no_of_pages,$no_of_equations,
            $no_of_images,$no_of_tables,$dispatch_date,$assigned_to,$status,
            $input_file_path,$output_file_path,
            $ce_start,$ce_end,$ce_done_by,$le_start,$le_end,$le_done_by,
            $pag_start,$pag_end,$pag_done_by,$qc_start,$qc_end,$qc_done_by,
            $rtat,$atat,$created_by
        );
        $stmt->execute();
        $stmt->close();
        redirect_with(['msg'=>"Job added successfully",'type'=>'success']);
    }

    if ($action === 'edit') {
        $sql = "UPDATE job_management SET
            client_id=?, journal_name=?, article_title=?, no_of_pages=?, no_of_equations=?, no_of_images=?, no_of_tables=?,
            dispatch_date=?, assigned_to=?, status=?,
            input_file = IFNULL(?,input_file),
            output_file = IFNULL(?,output_file),
            ce_start=?, ce_end=?, ce_done_by=?,
            le_start=?, le_end=?, le_done_by=?,
            pag_start=?, pag_end=?, pag_done_by=?,
            qc_start=?, qc_end=?, qc_done_by=?,
            rtat=?, atat=?, last_updated=NOW()
            WHERE job_id=?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param(
            "issiiiisisssssissississiiii",
            $client_id,$journal_name,$article_title,$no_of_pages,$no_of_equations,
            $no_of_images,$no_of_tables,$dispatch_date,$assigned_to,$status,
            $input_file_path,$output_file_path,
            $ce_start,$ce_end,$ce_done_by,$le_start,$le_end,$le_done_by,
            $pag_start,$pag_end,$pag_done_by,$qc_start,$qc_end,$qc_done_by,
            $rtat,$atat,$job_id
        );
        $stmt->execute();
        $stmt->close();
        redirect_with(['msg'=>"Job updated successfully",'type'=>'success']);
    }

    if ($action === 'delete' && $job_id > 0) {
        $stmt = $mysqli->prepare("DELETE FROM job_management WHERE job_id=?");
        $stmt->bind_param("i",$job_id);
        $stmt->execute();
        $stmt->close();
        redirect_with(['msg'=>"Job deleted successfully",'type'=>'warning']);
    }
}

// Determine mode
$mode = $_GET['mode'] ?? 'view';
$editJob = null;
if ($mode==='edit') {
    $edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($edit_id>0) {
        $stmt = $mysqli->prepare("SELECT * FROM job_management WHERE job_id=?");
        $stmt->bind_param("i",$edit_id);
        $stmt->execute();
        $editJob = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editJob) $mode='view';
}

// Filters for search
$q       = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort    = $_GET['sort'] ?? 'job_id';
$dir     = strtolower($_GET['dir'] ?? 'desc');
$sortable = ['job_id','client_name','journal_name','article_title','dispatch_date','status','created_date'];
if (!in_array($sort,$sortable,true)) $sort='job_id';
$dir = ($dir==='asc')?'asc':'desc';

// Build SQL with filters
$sql = "SELECT j.job_id, c.client_name, j.journal_name, j.article_title,
    j.no_of_pages, j.no_of_equations, j.no_of_images, j.no_of_tables,
    j.dispatch_date, e.employee_name AS assigned_name, j.status,
    j.input_file, j.output_file, j.rtat, j.atat, j.created_by,
    j.created_date, j.last_updated
    FROM job_management j
    LEFT JOIN client_master c ON j.client_id=c.client_id
    LEFT JOIN employee_master e ON j.assigned_to=e.employee_id";

$cond = []; $params = []; $types = '';
if ($q!=='') {
    $cond[] = "(j.journal_name LIKE ? OR j.article_title LIKE ? OR c.client_name LIKE ?)";
    $like = "%$q%";
    $params = [$like,$like,$like];
    $types = 'sss';
}
if ($fstatus!=='' && in_array($fstatus,$statuses)) {
    $cond[] = "j.status=?";
    $params[] = $fstatus;
    $types .= 's';
}
if ($cond) $sql .= " WHERE ".implode(" AND ",$cond);
$sql .= " ORDER BY $sort $dir";

if ($types) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types,...$params);
    $stmt->execute();
    $jobs = $stmt->get_result();
} else {
    $jobs = $mysqli->query($sql);
}

require 'header.php';
?>

<div class="container mt-3">

<?php if (!empty($_GET['msg'])): ?>
<div class="alert alert-<?= h($_GET['type']??'info') ?> alert-dismissible fade show" role="alert">
    <?= h($_GET['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if($mode==='add'||$mode==='edit'): ?>
<div class="d-flex justify-content-between align-items-center mb-2">
    <h5><?= $mode==='edit'?'Edit Job':'Add Job' ?></h5>
    <a href="job_management.php" class="btn btn-secondary">Back</a>
</div>
<div class="card mb-3 mx-auto" style="max-width:980px;">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="action" value="<?= $mode==='edit'?'edit':'add' ?>">
            <?php if($mode==='edit'): ?>
                <input type="hidden" name="job_id" value="<?= (int)$editJob['job_id'] ?>">
            <?php endif; ?>

            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label">Client <span class="text-danger">*</span></label>
                    <select name="client_id" class="form-select" required>
                        <option value="">Select Client</option>
                        <?php 
                        $clients = $mysqli->query("SELECT client_id, client_name FROM client_master WHERE status='Active' ORDER BY client_name");
                        while ($client = $clients->fetch_assoc()): ?>
                            <option value="<?= (int)$client['client_id'] ?>" <?= (($editJob['client_id']??0)==$client['client_id'])?'selected':'' ?>>
                                <?= h($client['client_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Journal Name <span class="text-danger">*</span></label>
                    <input type="text" name="journal_name" class="form-control" required value="<?= h($editJob['journal_name']??'') ?>">
                </div>
                <div class="col-md-12">
                    <label class="form-label">Article Title <span class="text-danger">*</span></label>
                    <input type="text" name="article_title" class="form-control" required value="<?= h($editJob['article_title']??'') ?>">
                </div>
                <div class="col-md-3"><label class="form-label">Pages</label><input type="number" name="no_of_pages" class="form-control" value="<?= h($editJob['no_of_pages']??0) ?>"></div>
                <div class="col-md-3"><label class="form-label">Equations</label><input type="number" name="no_of_equations" class="form-control" value="<?= h($editJob['no_of_equations']??0) ?>"></div>
                <div class="col-md-3"><label class="form-label">Images</label><input type="number" name="no_of_images" class="form-control" value="<?= h($editJob['no_of_images']??0) ?>"></div>
                <div class="col-md-3"><label class="form-label">Tables</label><input type="number" name="no_of_tables" class="form-control" value="<?= h($editJob['no_of_tables']??0) ?>"></div>
                <div class="col-md-4"><label class="form-label">Dispatch Date</label><input type="date" name="dispatch_date" class="form-control" value="<?= h($editJob['dispatch_date']??'') ?>"></div>
                <div class="col-md-4"><label class="form-label">Assigned To *</label>
                    <select name="assigned_to" class="form-select" required>
                        <option value="">Select Employee</option>
                        <?php foreach($executives_array as $emp_id=>$emp_name): ?>
                            <option value="<?= (int)$emp_id ?>" <?= (($editJob['assigned_to']??0)==$emp_id)?'selected':'' ?>><?= h($emp_name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4"><label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach($statuses as $st): ?>
                            <option value="<?= h($st) ?>" <?= (($editJob['status']??'Pending')===$st)?'selected':'' ?>><?= h($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6"><label class="form-label">Input File</label><input type="file" name="input_file" class="form-control">
                    <?php if(!empty($editJob['input_file'])): ?><small><a href="<?= h($editJob['input_file']) ?>" target="_blank">Current file</a></small><?php endif; ?>
                </div>
                <div class="col-md-6"><label class="form-label">Output File</label><input type="file" name="output_file" class="form-control">
                    <?php if(!empty($editJob['output_file'])): ?><small><a href="<?= h($editJob['output_file']) ?>" target="_blank">Current file</a></small><?php endif; ?>
                </div>
                <div class="col-md-6"><label class="form-label">RTAT</label><input type="number" name="rtat" class="form-control" value="<?= h($editJob['rtat']??0) ?>"></div>
                <div class="col-md-6"><label class="form-label">ATAT</label><input type="number" name="atat" class="form-control" value="<?= h($editJob['atat']??0) ?>"></div>
            </div>

            <div class="mt-3 d-flex justify-content-end gap-2">
                <button type="submit" class="btn btn-warning"><?= $mode==='edit'?'Update':'Save' ?></button>
                <a href="job_management.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<!-- Toolbar like client_master -->
<div class="d-flex justify-content-between align-items-center mb-2">
    <h5>Job Master</h5>
    <a href="job_management.php?mode=add" class="btn btn-success"><i class="fa fa-plus"></i> Add Job</a>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search journal/title/client" style="width:220px;" value="<?= h($q) ?>">
    <select id="statusSelect" class="form-select form-select-sm" style="width:140px;">
        <option value="">All Statuses</option>
        <?php foreach($statuses as $st): ?>
            <option value="<?= h($st) ?>" <?= ($fstatus===$st)?'selected':'' ?>><?= h($st) ?></option>
        <?php endforeach; ?>
    </select>
    <select id="sortSelect" class="form-select form-select-sm" style="width:150px;">
        <option value="job_id" <?= ($sort==='job_id')?'selected':'' ?>>Job ID</option>
        <option value="client_name" <?= ($sort==='client_name')?'selected':'' ?>>Client Name</option>
        <option value="journal_name" <?= ($sort==='journal_name')?'selected':'' ?>>Journal Name</option>
        <option value="article_title" <?= ($sort==='article_title')?'selected':'' ?>>Article Title</option>
        <option value="dispatch_date" <?= ($sort==='dispatch_date')?'selected':'' ?>>Dispatch Date</option>
        <option value="status" <?= ($sort==='status')?'selected':'' ?>>Status</option>
        <option value="created_date" <?= ($sort==='created_date')?'selected':'' ?>>Created Date</option>
    </select>
    <select id="dirSelect" class="form-select form-select-sm" style="width:100px;">
        <option value="asc" <?= ($dir==='asc')?'selected':'' ?>>Asc</option>
        <option value="desc" <?= ($dir==='desc')?'selected':'' ?>>Desc</option>
    </select>
    <button id="applyFilter" class="btn btn-sm btn-primary"><i class="fa fa-search"></i> Apply</button>
    <button id="clearFilter" class="btn btn-sm btn-outline-dark"><i class="fa fa-times"></i> Clear</button>
</div>

<div class="card">
<div class="card-body p-0 table-responsive">
    <table class="table table-striped table-bordered align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th>Job ID</th>
                <th>Client</th>
                <th>Journal</th>
                <th>Article</th>
                <th>Pages</th>
                <th>Equations</th>
                <th>Images</th>
                <th>Tables</th>
                <th>Dispatch</th>
                <th>Assigned To</th>
                <th>Status</th>
                <th>Input</th>
                <th>Output</th>
                <th>RTAT</th>
                <th>ATAT</th>
                <th>Created By</th>
                <th>Created</th>
                <th>Updated</th>
                <th class="text-center">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if($jobs->num_rows>0): while($row=$jobs->fetch_assoc()): ?>
            <tr>
                <td><?= h($row['job_id']) ?></td>
                <td><?= h($row['client_name']) ?></td>
                <td><?= h($row['journal_name']) ?></td>
                <td><?= h($row['article_title']) ?></td>
                <td><?= h($row['no_of_pages']) ?></td>
                <td><?= h($row['no_of_equations']) ?></td>
                <td><?= h($row['no_of_images']) ?></td>
                <td><?= h($row['no_of_tables']) ?></td>
                <td><?= h($row['dispatch_date']) ?></td>
                <td><?= h($row['assigned_name']) ?></td>
                <td><?= h($row['status']) ?></td>
                <td><?= !empty($row['input_file'])?'<a href="'.h($row['input_file']).'" target="_blank">Download</a>':'' ?></td>
                <td><?= !empty($row['output_file'])?'<a href="'.h($row['output_file']).'" target="_blank">Download</a>':'' ?></td>
                <td><?= h($row['rtat']) ?></td>
                <td><?= h($row['atat']) ?></td>
                <td><?= h($row['created_by']) ?></td>
                <td><?= h($row['created_date']) ?></td>
                <td><?= h($row['last_updated']) ?></td>
                <td class="text-center" style="white-space: nowrap;">
                    <a href="job_management.php?mode=edit&id=<?= (int)$row['job_id'] ?>" class="btn btn-sm btn-warning me-1"><i class="fa fa-edit"></i></a>
                    <form method="post" class="d-inline" onsubmit="return confirm('Are you sure?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="job_id" value="<?= (int)$row['job_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger"><i class="fa fa-trash"></i></button>
                    </form>
                </td>
            </tr>
        <?php endwhile; else: ?>
            <tr><td colspan="19" class="text-center">No jobs found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
    const applyFilter = ()=>{
        const q = document.getElementById('searchInput').value.trim();
        const status = document.getElementById('statusSelect').value;
        const sort = document.getElementById('sortSelect').value;
        const dir = document.getElementById('dirSelect').value;
        const params = new URLSearchParams();
        if(q) params.set('q',q);
        if(status) params.set('status',status);
        if(sort) params.set('sort',sort);
        if(dir) params.set('dir',dir);
        window.location.href='job_management.php?'+params.toString();
    };
    document.getElementById('applyFilter').addEventListener('click',applyFilter);
    document.getElementById('clearFilter').addEventListener('click',()=>{
        document.getElementById('searchInput').value='';
        document.getElementById('statusSelect').value='';
        document.getElementById('sortSelect').value='job_id';
        document.getElementById('dirSelect').value='desc';
        applyFilter();
    });
});
</script>

<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</div>
</body>
</html>
