<?php
// Start session BEFORE any output
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once 'db.php'; 

// ---------- Helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDate($d){ 
    return (!empty($d) && $d != '0000-00-00') ? date('d-m-Y', strtotime($d)) : '—'; 
}
function redirect_with($params=[]){
    $base='process_master.php';
    if(!empty($params)) $base.='?'.http_build_query($params);
    header("Location: $base"); 
    exit;
}

// ---------- Handle POST ----------
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action = $_POST['action'] ?? '';
    $name   = trim($_POST['process_name'] ?? '');
    $desc   = trim($_POST['process_description'] ?? '');
    $creator = $_SESSION['username'] ?? 'TL';

    if(($action==='add' || $action==='edit') && $name==='') {
        redirect_with(['msg'=>'Process Name is required.','type'=>'danger']);
    }

    if($action==='add'){
        $status='Active';
        $stmt=$mysqli->prepare("INSERT INTO process_master (process_name,process_description,status,created_by,created_date) VALUES (?,?,?,?,NOW())");
        $stmt->bind_param("ssss", $name, $desc, $status, $creator);
        $stmt->execute(); 
        redirect_with(['msg'=>"Process '$name' created successfully",'type'=>'success']);
    }

    if($action==='edit'){
        $id=(int)($_POST['process_id']??0);
        $stmt=$mysqli->prepare("UPDATE process_master SET process_name=?,process_description=? WHERE process_id=?");
        $stmt->bind_param("ssi",$name,$desc,$id);
        $stmt->execute(); 
        redirect_with(['msg'=>"Process updated successfully",'type'=>'success']);
    }

    if($action==='delete'){
        $id=(int)($_POST['process_id']??0);
        $mysqli->query("DELETE FROM process_master WHERE process_id=$id");
        redirect_with(['msg'=>"Process removed",'type'=>'warning']);
    }

    if($action==='toggle'){
        $id=(int)($_POST['process_id']??0);
        $mysqli->query("UPDATE process_master SET status = IF(status='Active', 'Inactive', 'Active') WHERE process_id=$id");
        redirect_with(['msg'=>"Status updated",'type'=>'info']);
    }
}

$mode = $_GET['mode'] ?? 'view';
$editProcess = null;
if($mode === 'edit' && isset($_GET['id'])){
    $stmt = $mysqli->prepare("SELECT * FROM process_master WHERE process_id=?");
    $stmt->bind_param("i", $_GET['id']); 
    $stmt->execute();
    $editProcess = $stmt->get_result()->fetch_assoc(); 
}

$processes = $mysqli->query("SELECT * FROM process_master ORDER BY created_date DESC");

$pageTitle = "Process Master";
$currentPage = "process_master.php";
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
        margin-bottom: 20px;
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
    .status-pill {
        padding: 4px 12px;
        border-radius: 50px;
        font-size: 0.75rem;
        font-weight: 600;
        border: none;
        transition: 0.2s;
    }
    .status-active { background: #dcfce7; color: #166534; }
    .status-inactive { background: #fee2e2; color: #991b1b; }
    .status-pill:hover { opacity: 0.8; transform: translateY(-1px); }

    .btn-action {
        width: 32px;
        height: 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        transition: 0.2s;
        text-decoration: none;
    }
    .btn-edit { background: #fef9c3; color: #854d0e; }
    .btn-delete { background: #fee2e2; color: #991b1b; border: none; }
    .btn-action:hover { transform: scale(1.1); }
    .btn-header-action {
        background-color: #198754 !important;
        color: #ffffff !important;
        border: 1px solid #198754 !important;
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
        background-color: #157347 !important;
        color: #ffffff !important;
        border-color: #146c43 !important;
    }
    .btn-header-action i {
        color: #ffffff !important;
        margin-right: 6px !important;
    }
    .btn-header-action span {
        color: #ffffff !important;
        font-weight: 600 !important;
    }
</style>

<main class="main-content">
    <div class="container-fluid p-0">

        <!-- Alert Messages -->
        <?php if(!empty($_GET['msg'])): ?>
            <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show border-0 shadow-sm mb-3" role="alert">
                <i class="fa-solid fa-circle-info me-2"></i> <?= h($_GET['msg']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
            <!-- FORM VIEW -->
            <div class="row justify-content-center">
                <div class="col-lg-6">
                    <div class="page-title-container" style="background-color: #6c757d !important; background: #6c757d !important; color: #ffffff !important; padding: 12px 20px !important; border-radius: 12px !important; margin-bottom: 20px !important; display: flex !important; justify-content: space-between !important; align-items: center !important;">
                        <h4 class="mb-0 text-white" style="color: #ffffff !important; font-size: 1.25rem !important;"><i class="fa-solid fa-gears me-2"></i><?= $mode === 'edit' ? 'Update Process' : 'New Process Definition' ?></h4>
                        <a href="process_master.php" class="btn btn-secondary btn-sm" style="margin-left: auto !important;"><i class="fas fa-arrow-left me-1"></i> Back</a>
                    </div>
                    <div class="card p-4 p-md-5 shadow-sm border-0" style="border-radius: 12px;">
                        <div class="text-center mb-4">
                            <div class="bg-light d-inline-block p-3 rounded-circle mb-3">
                                <i class="fa-solid <?= $mode==='edit'?'fa-pen-to-square':'fa-folder-plus' ?> fa-2x text-primary"></i>
                            </div>
                            <h4 class="fw-bold"><?= $mode === 'edit' ? 'Update Process' : 'New Process Definition' ?></h4>
                            <p class="text-muted small">Standardize your workflow by defining process details.</p>
                        </div>
                        
                        <form method="post">
                            <input type="hidden" name="action" value="<?= $mode === 'edit' ? 'edit' : 'add' ?>">
                            <?php if ($mode === 'edit'): ?>
                                <input type="hidden" name="process_id" value="<?= $editProcess['process_id'] ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Process Name <span class="text-danger">*</span></label>
                                <input type="text" name="process_name" class="form-control" placeholder="e.g. Quality Inspection" required value="<?= h($editProcess['process_name'] ?? '') ?>">
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold">Description</label>
                                <textarea name="process_description" rows="3" class="form-control" placeholder="Describe the steps or purpose..."><?= h($editProcess['process_description'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary py-2 fw-semibold">
                                    <?= $mode === 'edit' ? 'Update Details' : 'Save Process' ?>
                                </button>
                                <a href="process_master.php" class="btn btn-link text-muted text-decoration-none">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Process Master Header Banner with Ash Color -->
            <div class="page-title-container">
                <h1>
                    <i class="fa-solid fa-gears"></i> Process Master
                </h1>
                <a href="process_master.php?mode=add" class="btn btn-success btn-header-action text-white">
                    <i class="fa fa-plus text-white"></i>
                    <span class="text-white">Add Process</span>
                </a>
            </div>
            
            <!-- Process Table -->
            <div class="card shadow-sm border-0 mb-4" style="border-radius: 8px; overflow: visible;">
                <div class="card-body p-0" style="overflow: visible;">
                    <div class="table-container" style="overflow: visible;">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th width="70" class="text-center">S.NO</th>
                                    <th>PROCESS DETAILS</th>
                                    <th class="text-center" style="width: 130px;">STATUS</th>
                                    <th>METADATA</th>
                                    <th width="120" class="text-center">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if($processes && $processes->num_rows): $i=0; while($row=$processes->fetch_assoc()): $i++; ?>
                                    <tr>
                                        <td class="text-center text-muted small"><?= $i ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= h($row['process_name']) ?></div>
                                            <div class="text-muted extra-small" style="font-size: 0.8rem;"><?= h($row['process_description'] ?: 'No description provided') ?></div>
                                        </td>
                                        <td class="text-center">
                                            <form method="post">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="process_id" value="<?= $row['process_id'] ?>">
                                                <button type="submit" class="status-pill <?= $row['status'] === 'Active' ? 'status-active' : 'status-inactive' ?>" onclick="return confirm('Change status?')">
                                                    <i class="fa-solid fa-circle me-1" style="font-size: 0.5rem;"></i> <?= $row['status'] ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td>
                                            <div class="small"><i class="fa-regular fa-user me-1 text-muted"></i> <?= h($row['created_by']) ?></div>
                                            <div class="text-muted" style="font-size: 0.75rem;"><i class="fa-regular fa-calendar me-1"></i> <?= formatDate($row['created_date']) ?></div>
                                        </td>
                                        <td class="text-center">
                                            <a href="process_master.php?mode=edit&id=<?= $row['process_id'] ?>" class="btn-action btn-edit me-1" title="Edit">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </a>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this process?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="process_id" value="<?= $row['process_id'] ?>">
                                                <button class="btn-action btn-delete" title="Delete">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5 text-muted">
                                            <i class="fa-solid fa-inbox fa-3x mb-3 opacity-25"></i>
                                            <p>No processes found. Click "Add Process" to start.</p>
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
