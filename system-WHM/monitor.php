<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDate($d) { if (empty($d) || $d === '0000-00-00') return '—'; return date('d-m-Y', strtotime($d)); }
function redirect_with($params = []) {
    $base = 'monitor.php'; 
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base"); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add' || $action === 'edit') {
        $purchase_date = $_POST['purchase_date'] ?? '';
        $model = trim($_POST['model'] ?? '');
        $user = trim($_POST['user'] ?? '');
        $payment = trim($_POST['payment'] ?? '');
        $vendor = trim($_POST['vendor'] ?? '');
        $status = $_POST['status'] ?? 'Available';
        $given_date = $_POST['given_date'] ?? '';
        
        if ($purchase_date === '') redirect_with(['msg' => 'Purchase Date is required.', 'type' => 'danger']);
        
        if ($action === 'add') {
            $stmt = $mysqli->prepare("INSERT INTO tbl_monitor (purchase_date, model, user, payment, vendor, given_date, status, created_ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssssssss", $purchase_date, $model, $user, $payment, $vendor, $given_date, $status, $_SERVER['REMOTE_ADDR']);
            if ($stmt->execute()) redirect_with(['msg' => "Monitor added successfully", 'type' => 'success']);
            $stmt->close();
        } elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $mysqli->prepare("UPDATE tbl_monitor SET purchase_date = ?, model = ?, user = ?, payment = ?, vendor = ?, given_date = ?, status = ?, updated_ip = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("ssssssssi", $purchase_date, $model, $user, $payment, $vendor, $given_date, $status, $_SERVER['REMOTE_ADDR'], $id);
            if ($stmt->execute()) redirect_with(['msg' => "Monitor updated successfully", 'type' => 'success']);
            $stmt->close();
        }
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $mysqli->query("DELETE FROM tbl_monitor WHERE id = $id");
        redirect_with(['msg' => "Deleted successfully", 'type' => 'warning']);
    }
    redirect_with();
}

$mode = $_GET['mode'] ?? 'view';
$editData = null;
if ($mode === 'edit' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $editData = $mysqli->query("SELECT * FROM tbl_monitor WHERE id = $id")->fetch_assoc();
}

$q = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM tbl_monitor";
if ($q !== '') $sql .= " WHERE (model LIKE '%$q%' OR user LIKE '%$q%')";
$result = $mysqli->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Monitor Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>body{background:#f8f9fa;font-family:'Segoe UI',sans-serif}.main-content{padding:20px;max-width:1200px;margin:0 auto}.table th{background:#2c3e50;color:white}td{vertical-align:middle}.card{box-shadow:0 .125rem .25rem rgba(0,0,0,.075)}</style>
</head>
<body>
    <?php require 'header.php'; ?>
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?><div class="alert alert-<?= h($_GET['type']??'info') ?> alert-dismissible fade show"><?= h($_GET['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-desktop me-2"></i><?= $mode === 'edit' ? 'Edit Monitor' : 'Add New Monitor' ?></h4>
            <a href="monitor.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
        </div>
        <div class="card"><div class="card-body">
            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="<?= $mode ?>">
                <?php if($mode === 'edit'): ?><input type="hidden" name="id" value="<?= (int)($editData['id']??0) ?>"><?php endif; ?>
                <div class="row">
                    <div class="col-md-4 mb-3"><label class="form-label">Purchase Date *</label><input type="date" class="form-control" name="purchase_date" value="<?= h($editData['purchase_date']??date('Y-m-d')) ?>" required></div>
                    <div class="col-md-4 mb-3"><label class="form-label">Model *</label><input type="text" class="form-control" name="model" value="<?= h($editData['model']??'') ?>" placeholder="Enter Model" required></div>
                    <div class="col-md-4 mb-3"><label class="form-label">User</label><input type="text" class="form-control" name="user" value="<?= h($editData['user']??'') ?>" placeholder="Assigned to"></div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3"><label class="form-label">Payment</label><input type="text" class="form-control" name="payment" value="<?= h($editData['payment']??'') ?>"></div>
                    <div class="col-md-4 mb-3"><label class="form-label">Vendor</label><input type="text" class="form-control" name="vendor" value="<?= h($editData['vendor']??'') ?>"></div>
                    <div class="col-md-4 mb-3"><label class="form-label">Status</label><select class="form-select" name="status"><option value="Available" <?= ($editData['status']??'')=='Available'?'selected':'' ?>>Available</option><option value="Allocated" <?= ($editData['status']??'')=='Allocated'?'selected':'' ?>>Allocated</option><option value="In-Repair" <?= ($editData['status']??'')=='In-Repair'?'selected':'' ?>>In-Repair</option></select></div>
                    <div class="col-md-4 mb-3"><label class="form-label">Given Date</label><input type="date" class="form-control" name="given_date" value="<?= h($editData['given_date']??'') ?>"></div>
                </div>
                <div class="mt-3"><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save</button><a href="monitor.php" class="btn btn-outline-secondary ms-2">Cancel</a></div>
            </form>
        </div></div>
        <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-desktop me-2"></i>Monitor Management</h4>
            <a href="monitor.php?mode=add" class="btn btn-success btn-sm"><i class="fas fa-plus me-1"></i> Add New</a>
        </div>
        <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Purchase Date</th><th>Model</th><th>User</th><th>Payment</th><th>Vendor</th><th>Status</th><th>Given Date</th><th>Actions</th></tr></thead>
        <tbody><?php if($result && $result->num_rows>0): while($row=$result->fetch_assoc()): ?>
        <tr><td><?= h(formatDate($row['purchase_date'])) ?></td><td class="fw-bold"><?= h($row['model']) ?></td><td><?= h($row['user']) ?></td><td><?= h($row['payment']) ?></td><td><?= h($row['vendor']) ?></td><td><span class="badge bg-secondary"><?= h($row['status']) ?></span></td><td><?= h(formatDate($row['given_date'])) ?></td>
        <td><a href="monitor.php?mode=edit&id=<?= $row['id'] ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a><form method="post" style="display:inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $row['id'] ?>"><button class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button></form></td></tr>
        <?php endwhile; else: ?><tr><td colspan="8" class="text-center py-4">No records found</td></tr><?php endif; ?></tbody></table></div></div></div>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>