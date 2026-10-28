<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ---------- Helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDate($d){ return (!empty($d)&&$d!='0000-00-00')?date('d-m-Y',strtotime($d)):'—'; }
function redirect_with($params=[]){
    $base='process_master.php';
    if(!empty($params)) $base.='?'.http_build_query($params);
    header("Location:$base"); exit;
}

// ---------- Handle POST ----------
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    $name  = trim($_POST['process_name']??'');
    $desc  = trim($_POST['process_description']??'');
    $uid   = $_SESSION['uid']??null;

    $errors=[];
    if(($action==='add'||$action==='edit') && $name==='') $errors[]='Process Name is required.';
    if($errors) redirect_with(['msg'=>implode(' ',$errors),'type'=>'danger']);

    if($action==='add'){
        $status='Active';
        $stmt=$mysqli->prepare("INSERT INTO process_master (process_name,process_description,status,created_by,created_date) VALUES (?,?,?,?,NOW())");
        $stmt->bind_param("sssi",$name,$desc,$status,$uid);
        $stmt->execute(); $stmt->close();
        redirect_with(['msg'=>"Process '$name' added successfully",'type'=>'success']);
    }

    if($action==='edit'){
        $id=(int)($_POST['process_id']??0);
        if($id>0){
            $stmt=$mysqli->prepare("UPDATE process_master SET process_name=?,process_description=? WHERE process_id=?");
            $stmt->bind_param("ssi",$name,$desc,$id);
            $stmt->execute(); $stmt->close();
            redirect_with(['msg'=>"Process '$name' updated successfully",'type'=>'success']);
        }
    }

    if($action==='delete'){
        $id=(int)($_POST['process_id']??0);
        if($id>0){
            $res=$mysqli->prepare("SELECT process_name FROM process_master WHERE process_id=?");
            $res->bind_param("i",$id); $res->execute();
            $row=$res->get_result()->fetch_assoc(); $res->close();
            $del=$mysqli->prepare("DELETE FROM process_master WHERE process_id=?");
            $del->bind_param("i",$id); $del->execute(); $del->close();
            $name=$row['process_name']??'';
        }
        redirect_with(['msg'=>"Process '$name' deleted",'type'=>'warning']);
    }

    if($action==='toggle'){
        $id=(int)($_POST['process_id']??0);
        if($id>0){
            $res=$mysqli->prepare("SELECT status,process_name FROM process_master WHERE process_id=?");
            $res->bind_param("i",$id); $res->execute();
            $row=$res->get_result()->fetch_assoc(); $res->close();
            if($row){
                $newStatus=($row['status']==='Active')?'Inactive':'Active';
                $upd=$mysqli->prepare("UPDATE process_master SET status=? WHERE process_id=?");
                $upd->bind_param("si",$newStatus,$id); $upd->execute(); $upd->close();
                redirect_with(['msg'=>"Process '{$row['process_name']}' status changed to $newStatus",'type'=>'info']);
            }
        }
    }
    redirect_with();
}

// ---------- View/Edit ----------
$mode=$_GET['mode']??'view';
$editProcess=null;
if($mode==='edit'){
    $id=(int)($_GET['id']??0);
    if($id>0){
        $stmt=$mysqli->prepare("SELECT * FROM process_master WHERE process_id=?");
        $stmt->bind_param("i",$id); $stmt->execute();
        $editProcess=$stmt->get_result()->fetch_assoc(); $stmt->close();
    }
    if(!$editProcess) $mode='view';
}

// ---------- Fetch Data ----------
$sql="SELECT pm.*, em.employee_name AS created_name
      FROM process_master pm
      LEFT JOIN employee_master em ON pm.created_by=em.employee_id
      ORDER BY pm.created_date DESC";
$processes=$mysqli->query($sql);

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Process Master</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-3">

<?php if(!empty($_GET['msg'])): ?>
<div class="alert alert-<?=h($_GET['type']??'info')?>"><?=h($_GET['msg'])?></div>
<?php endif; ?>

<?php if($mode==='add'||$mode==='edit'): ?>
<!--h5><?=$mode==='edit'?'Edit Process':'Add Process'?></h5-->
<div class="row justify-content-center mt-5">
    <div class="col-lg-6 col-md-8">
        <div class="card shadow-lg p-4">
            <h4 class="card-title text-center mb-4">
                <?= $mode === 'edit' ? 'Edit Process' : 'Add New Process' ?>
            </h4>
            
            <form method="post">
                <input type="hidden" name="action" value="<?= $mode === 'edit' ? 'edit' : 'add' ?>">
                <?php if ($mode === 'edit'): ?>
                    <input type="hidden" name="process_id" value="<?= $editProcess['process_id'] ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label for="processName" class="form-label">Process Name *</label>
                    <input type="text" name="process_name" id="processName" class="form-control form-control-lg" required value="<?= h($editProcess['process_name'] ?? '') ?>">
                </div>

                <div class="mb-4">
                    <label for="processDesc" class="form-label">Description</label>
                    <textarea name="process_description" id="processDesc" rows="4" class="form-control"><?= h($editProcess['process_description'] ?? '') ?></textarea>
                </div>
                
                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <button type="submit" class="btn btn-primary btn-lg flex-grow-1">
                        <?= $mode === 'edit' ? 'Update Process' : 'Save Process' ?>
                    </button>
                    <a href="process_master.php" class="btn btn-outline-secondary btn-lg">Cancel</a>
                </div>
            </form>
        </div>
        </div>
</div>
<?php else: ?>
<div class="d-flex justify-content-between mb-2">
  <h5>Process Master</h5>
  <a href="process_master.php?mode=add" class="btn btn-success btn-sm">Add</a>
</div>
<table class="table table-bordered">
<thead><tr><th>ID</th><th>Name</th><th>Description</th><th>Status</th><th>Created By</th><th>Created</th><th>Actions</th></tr></thead>
<tbody>
<?php if($processes && $processes->num_rows): while($row=$processes->fetch_assoc()): ?>
<tr>
<td><?=$row['process_id']?></td>
<td><?=h($row['process_name'])?></td>
<td><?=h($row['process_description'])?></td>
<td>
<form method="post" style="display:inline;">
<input type="hidden" name="action" value="toggle">
<input type="hidden" name="process_id" value="<?=$row['process_id']?>">
<button class="btn btn-sm <?=$row['status']==='Active'?'btn-success':'btn-danger'?>"><?=$row['status']?></button>
</form>
</td>
<td><?=h($row['created_name']?:$row['created_by'])?></td>
<td><?=formatDate($row['created_date'])?></td>
<td>
<a href="process_master.php?mode=edit&id=<?=$row['process_id']?>" class="btn btn-warning btn-sm">Edit</a>
<form method="post" style="display:inline;" onsubmit="return confirm('Delete process <?=$row['process_name']?>?')">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="process_id" value="<?=$row['process_id']?>">
  <button class="btn btn-danger btn-sm">Delete</button>
</form>
</td>
</tr>
<?php endwhile; else: ?>
<tr><td colspan="7" class="text-center">No processes found.</td></tr>
<?php endif; ?>
</tbody>
</table>
<?php endif; ?>

</div>
</body>
</html>
