<?php 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

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
            redirect_with(['msg'=>"Job '$name' deleted",'type'=>'warning']);
        }
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
                jm.created_by, jm.created_date, em.role_name AS created_name
         FROM job_master jm
         LEFT JOIN user_role_master em ON jm.created_by = em.role_id";
$cond = []; $params = []; $types = '';
if ($q !== '') { 
    $cond[] = "(jm.job_name LIKE ? OR jm.job_description LIKE ? OR CAST(jm.rate AS CHAR) LIKE ?)"; 
    $like = "%$q%"; 
    $params = [$like, $like, $like]; 
    $types = 'sss'; 
}
if ($fstatus === 'Active' || $fstatus === 'Inactive') { 
    $cond[] = "jm.status = ?"; 
    $params[] = $fstatus; 
    $types .= 's'; 
}
if ($cond) {
    $sql .= " WHERE " . implode(" AND ", $cond);
}
$sql .= " ORDER BY $sort $dir";

if ($types) { 
    $stmt = $mysqli->prepare($sql); 
    $stmt->bind_param($types, ...$params); 
    $stmt->execute(); 
    $jobs = $stmt->get_result(); 
} else { 
    $jobs = $mysqli->query($sql); 
}

$pageTitle = "Job Master";
$currentPage = "job_master.php";
require 'header.php';
?>

<style>
    body {
        background-color: var(--light, #f5f7fa);
    }
    .main-content {
        margin-top: 70px;
        padding: 25px 4%;
        transition: all 0.3s ease;
    }
    .page-title-container {
        background: var(--ash, #6c757d);
        color: white;
        padding: 10px 18px;
        border-radius: 12px;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .page-title-container h1 {
        margin: 0;
        font-size: 2rem;
        font-weight: 700;
        color: white;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .go-bar-card {
        border-radius: 10px;
        background: #ffffff;
        border: 1px solid #e9ecef;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
    }
    .go-bar-input {
        height: 40px;
        font-size: 14px;
        border-radius: 6px;
    }
    .table {
        border-collapse: separate;
        border-spacing: 0;
    }
    .table thead th {
        position: sticky;
        top: 70px;
        z-index: 900;
        background-color: var(--ash, #6c757d) !important;
        color: white !important;
        border: 1px solid #6c757d !important;
        font-weight: 700;
        font-size: 13px;
        letter-spacing: 0.5px;
        padding: 12px 14px;
        text-transform: uppercase;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15);
    }
    .table thead tr th:first-child {
        border-top-left-radius: 8px;
    }
    .table thead tr th:last-child {
        border-top-right-radius: 8px;
    }
    .table tbody td {
        font-size: 13.5px;
        padding: 12px 14px;
        vertical-align: middle;
        color: #333333;
        background-color: #ffffff;
    }
    .table-container {
        overflow: visible;
    }
    .btn-header-action {
        background-color: #ffffff !important;
        color: #212529 !important;
        border: 1px solid #ced4da !important;
        font-weight: 600 !important;
        padding: 6px 16px !important;
        border-radius: 6px !important;
        text-decoration: none !important;
        display: inline-flex !important;
        align-items: center !important;
        font-size: 14px !important;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
    }
    .btn-header-action:hover {
        background-color: #f8f9fa !important;
        color: #0d6efd !important;
    }
    .btn-header-action i {
        color: #0d6efd !important;
        margin-right: 6px !important;
    }
    .btn-header-action span {
        color: #212529 !important;
        font-weight: 600 !important;
    }
</style>

<main class="main-content">
    <div class="container-fluid p-0">

        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show shadow-sm" role="alert">
            <i class="fas fa-info-circle me-1"></i> <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($mode === 'add' || $mode === 'edit'): ?>
        <!-- Add/Edit Form -->
        <div class="mx-auto" style="max-width: 550px;">
            <div class="page-title-container" style="background-color: #6c757d !important; background: #6c757d !important; color: #ffffff !important; padding: 12px 20px !important; border-radius: 12px !important; margin-bottom: 20px !important; display: flex !important; justify-content: space-between !important; align-items: center !important;">
                <h5 class="mb-0 text-white" style="color: #ffffff !important;"><i class="fas fa-briefcase me-2"></i><?= $mode==='edit' ? 'Edit Job' : 'Add Job' ?></h5>
                <a href="job_master.php" class="btn btn-secondary btn-sm" style="margin-left: auto !important;"><i class="fas fa-arrow-left me-1"></i> Back</a>
            </div>

            <div class="card mb-4 shadow-sm border-0">
            <div class="card-body p-4">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode==='edit' ? 'edit' : 'add' ?>">
                    <?php if ($mode==='edit'): ?>
                        <input type="hidden" name="job_id" value="<?= (int)$editJob['job_id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Job Name <span class="text-danger">*</span></label>
                        <input type="text" name="job_name" class="form-control" required value="<?= h($editJob['job_name'] ?? '') ?>" placeholder="Enter job name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Rate</label>
                        <input type="number" step="0.01" name="rate" class="form-control" value="<?= h($editJob['rate'] ?? '') ?>" placeholder="Enter rate">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Description</label>
                        <textarea name="job_description" class="form-control" rows="3" placeholder="Enter job description"><?= h($editJob['job_description'] ?? '') ?></textarea>
                    </div>
                    <div class="d-flex gap-2 justify-content-end">
                        <a href="job_master.php" class="btn btn-sm btn-outline-secondary px-3">Cancel</a>
                        <button type="submit" class="btn btn-sm px-4 fw-semibold" style="background-color:brown;color:white;">
                            <?= $mode==='edit' ? 'Update' : 'Save' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        </div>

        <?php else: ?>

        <!-- Job Master Header Banner with Ash Color -->
        <div class="page-title-container">
            <h1>
                <i class="fas fa-briefcase"></i> Job Master
            </h1>
        </div>

        <!-- Elaborated Go Bar (Elongated Horizontal Search & Filter Bar) -->
        <div class="card mb-3 go-bar-card">
            <div class="card-body p-2 p-md-3">
                <form method="get" class="d-flex flex-wrap align-items-center gap-2">
                    
                    <!-- Search Input (Elongated / Expanded across available width) -->
                    <div class="input-group" style="flex: 2 1 320px;">
                        <span class="input-group-text bg-white text-muted border-end-0">
                            <i class="fas fa-search"></i>
                        </span>
                        <input type="text" name="q" value="<?= h($q) ?>" 
                               placeholder="Search job name / desc / rate" 
                               autocomplete="off" 
                               class="form-control border-start-0 go-bar-input">
                        <?php if ($q !== ''): ?>
                            <a href="job_master.php?status=<?= urlencode($fstatus) ?>&sort=<?= urlencode($sort) ?>" 
                               class="input-group-text bg-white text-muted text-decoration-none" title="Clear search">
                                <i class="fas fa-times"></i>
                            </a>
                        <?php endif; ?>
                    </div>

                    <!-- Status Filter Dropdown -->
                    <div style="flex: 1 1 150px; max-width: 200px;">
                        <select name="status" class="form-select go-bar-input">
                            <option value="">All</option>
                            <option value="Active" <?= $fstatus==='Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $fstatus==='Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>

                    <!-- Sort Column Dropdown -->
                    <div style="flex: 1 1 180px; max-width: 230px;">
                        <select name="sort" class="form-select go-bar-input">
                            <?php foreach($sortable as $s): ?>
                                <option value="<?= $s ?>" <?= $sort===$s ? 'selected' : '' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Action Buttons: Go & Clear -->
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4 fw-semibold d-flex align-items-center justify-content-center go-bar-input">
                            <i class="fas fa-filter me-1"></i> Apply Filters
                        </button>
                        <a href="job_master.php" class="btn btn-outline-secondary px-3 d-flex align-items-center justify-content-center go-bar-input">
                            <i class="fas fa-undo me-1"></i> Reset
                        </a>
                    </div>

                </form>
            </div>
        </div>

        <!-- Job Table -->
        <div class="card shadow-sm border-0 mb-4" style="border-radius: 8px; overflow: visible;">
            <div class="card-body p-0" style="overflow: visible;">
                <div class="table-container" style="overflow: visible;">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 70px;">S.NO</th>
                                <th>NAME</th>
                                <th style="width: 120px;">STATUS</th>
                                <th>DESCRIPTION</th>
                                <th>CREATED BY</th>
                                <th style="width: 140px;">CREATED</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($jobs && $jobs->num_rows): $i = 0; while($row = $jobs->fetch_assoc()): $i++; ?>
                            <tr>
                                <td><?= $i ?></td>
                                <td class="fw-semibold text-dark"><?= h($row['job_name']) ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm <?= $row['status']==='Active' ? 'btn-success' : 'btn-danger' ?>" style="border-radius: 4px; pointer-events: none; padding: 3px 12px; font-size: 12px;">
                                        <?= h($row['status']) ?>
                                    </button>
                                </td>
                                <td><?= h($row['job_description']) ?></td>
                                <td><?= h($row['created_name'] ?: $row['created_by']) ?></td>
                                <td><?= formatDate($row['created_date']) ?></td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    <i class="fas fa-folder-open me-2"></i> No jobs found.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php endif; ?>

    </div>
</main>
