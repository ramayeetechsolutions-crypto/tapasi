<br><br>
<?php
// Start session BEFORE any output
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require 'db.php'; 

// ---------- Helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDate($d){ 
    return (!empty($d) && $d != '0000-00-00') ? date('d M, Y', strtotime($d)) : '—'; 
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

require 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Google Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">

    <style>
        :root {
            --primary-bg: #f8fafc;
            --accent-color: #6366f1; /* Modern Indigo */
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--primary-bg);
            color: #1e293b;
        }

        .container { max-width: 1100px; padding-top: 40px; }

        /* Card Styling */
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
        }

        /* Modern Table */
        .table-container {
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }

        .table thead th {
            background-color: #707981 !important;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.025em;
            color: white !important;
            padding: 15px;
            border: 1px solid #5a6268 !important;
        }

        .table tbody td {
            padding: 15px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
        }

        /* Status Badges */
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

        /* Action Buttons */
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

        /* Form Styling */
        .form-label { font-weight: 500; color: #334155; }
        .form-control {
            border-radius: 8px;
            padding: 10px 15px;
            border: 1px solid #e2e8f0;
        }
        .form-control:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .page-header {
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
    </style>
</head>
<body>

<div class="container mb-5">
    
    <!-- Alert Messages -->
    <?php if(!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i> <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if($mode === 'add' || $mode === 'edit'): ?>
        <!-- FORM VIEW -->
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="card p-4 p-md-5">
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
                            <label class="form-label">Process Name <span class="text-danger">*</span></label>
                            <input type="text" name="process_name" class="form-control" placeholder="e.g. Quality Inspection" required value="<?= h($editProcess['process_name'] ?? '') ?>">
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Description</label>
                            <textarea name="process_description" rows="3" class="form-control" placeholder="Describe the steps or purpose..."><?= h($editProcess['process_description'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary py-2 fw-semibold">
                                <?= $mode === 'edit' ? 'Update Details' : 'Save Process' ?>
                            </button>
                            <a href="process_master.php" class="btn btn-link text-muted text-decoration-none">Back to list</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- TABLE VIEW -->
        <div class="page-header">
            <div>
                <h3 class="fw-bold mb-1">Process Master</h3>
                <p class="text-muted small mb-0">Total Processes: <?= $processes->num_rows ?></p>
            </div>
            <a href="process_master.php?mode=add" class="btn btn-primary px-4 shadow-sm">
                <i class="fa fa-plus me-2"></i>Add Process
            </a>
        </div>
        
        <div class="table-container shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th width="60" class="text-center">S.No</th>
                            <th>Process Details</th>
                            <th class="text-center">Status</th>
                            <th>Metadata</th>
                            <th width="120" class="text-center">Actions</th>
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
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>