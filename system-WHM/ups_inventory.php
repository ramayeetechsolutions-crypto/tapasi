<?php
// --- ENABLE ERROR REPORTING (To fix 500 Errors) ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// --- HELPER FUNCTIONS ---
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00') return '—';
    return date('d-m-Y', strtotime($d));
}

// Calculate Age in Months
function getAgeMonths($date) {
    if (empty($date) || $date === '0000-00-00') return 0;
    try {
        $d1 = new DateTime($date);
        $d2 = new DateTime();
        $interval = $d1->diff($d2);
        return ($interval->y * 12) + $interval->m;
    } catch (Exception $e) {
        return 0;
    }
}

// Get Badge based on age
function getAgeStatus($months) {
    if ($months >= 36) return ['class' => 'danger', 'label' => 'Critical'];
    if ($months >= 30) return ['class' => 'warning', 'label' => 'Warning'];
    if ($months >= 24) return ['class' => 'info', 'label' => 'Info'];
    return ['class' => 'success', 'label' => 'Good'];
}

function redirect_with($params = []) {
    $base = 'ups_inventory.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base"); exit;
}

// --- HANDLE FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        // Generic Fields
        $ups_no = trim($_POST['ups_no'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $purchase_date = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $vendor = trim($_POST['vendor'] ?? '');
        $payment = trim($_POST['payment'] ?? '');
        $user = trim($_POST['user'] ?? '');
        $team = trim($_POST['team'] ?? '');
        
        // Battery Specifics
        $bat1_serial = trim($_POST['bat1_serial'] ?? '');
        $bat1_date = !empty($_POST['bat1_date']) ? $_POST['bat1_date'] : null;
        $bat2_serial = trim($_POST['bat2_serial'] ?? '');
        $bat2_date = !empty($_POST['bat2_date']) ? $_POST['bat2_date'] : null;
        $status = $_POST['status'] ?? 'Active';
        $remarks = trim($_POST['remarks'] ?? '');

        if ($ups_no === '') redirect_with(['msg' => 'UPS No is required', 'type' => 'danger']);

        if ($action === 'add') {
            $stmt = $mysqli->prepare("INSERT INTO tbl_ups_details (ups_no, model, purchase_date, vendor, payment, user, team, bat1_serial, bat1_date, bat2_serial, bat2_date, status, remarks, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            if ($stmt) {
                $stmt->bind_param("ssssssssssssss", $ups_no, $model, $purchase_date, $vendor, $payment, $user, $team, $bat1_serial, $bat1_date, $bat2_serial, $bat2_date, $status, $remarks, $_SERVER['REMOTE_ADDR']);
                if ($stmt->execute()) redirect_with(['msg' => "UPS Added Successfully", 'type' => 'success']);
                else redirect_with(['msg' => "Error: " . $stmt->error, 'type' => 'danger']);
                $stmt->close();
            } else {
                redirect_with(['msg' => "Database Prepare Error: " . $mysqli->error, 'type' => 'danger']);
            }
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $mysqli->prepare("UPDATE tbl_ups_details SET ups_no=?, model=?, purchase_date=?, vendor=?, payment=?, user=?, team=?, bat1_serial=?, bat1_date=?, bat2_serial=?, bat2_date=?, status=?, remarks=?, updated_at=NOW(), updated_ip=? WHERE id=?");
                if ($stmt) {
                    $stmt->bind_param("ssssssssssssssi", $ups_no, $model, $purchase_date, $vendor, $payment, $user, $team, $bat1_serial, $bat1_date, $bat2_serial, $bat2_date, $status, $remarks, $_SERVER['REMOTE_ADDR'], $id);
                    if ($stmt->execute()) redirect_with(['msg' => "UPS Updated Successfully", 'type' => 'success']);
                    else redirect_with(['msg' => "Error: " . $stmt->error, 'type' => 'danger']);
                    $stmt->close();
                } else {
                    redirect_with(['msg' => "Database Prepare Error: " . $mysqli->error, 'type' => 'danger']);
                }
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $mysqli->query("DELETE FROM tbl_ups_details WHERE id=$id");
            redirect_with(['msg' => "Deleted Successfully", 'type' => 'warning']);
        }
    }
    redirect_with();
}

// --- DATA FETCHING ---
$mode = $_GET['mode'] ?? 'view';
$editData = null;
if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $res = $mysqli->query("SELECT * FROM tbl_ups_details WHERE id=$id");
        if ($res) $editData = $res->fetch_assoc();
    }
    if (!$editData) $mode = 'view';
}

// Fetch All Data
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$sql = "SELECT * FROM tbl_ups_details";
if ($q !== '') {
    $safeQ = $mysqli->real_escape_string($q);
    $sql .= " WHERE ups_no LIKE '%$safeQ%' OR bat1_serial LIKE '%$safeQ%' OR model LIKE '%$safeQ%'";
}
$result = $mysqli->query($sql);

// Calculate Dashboard Stats
$stats = ['critical' => 0, 'warning' => 0, 'info' => 0];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $age1 = getAgeMonths($row['bat1_date']);
        $age2 = getAgeMonths($row['bat2_date']);
        $maxAge = max($age1, $age2); 
        
        if ($maxAge >= 36) $stats['critical']++;
        elseif ($maxAge >= 30) $stats['warning']++;
        elseif ($maxAge >= 24) $stats['info']++;
    }
    $result->data_seek(0); 
}

$teams = $mysqli->query("SELECT team_name FROM team_master ORDER BY team_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UPS & Battery Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .main-content { padding: 20px; max-width: 1600px; margin: 0 auto; }
        .stat-card { border: none; color: white; border-radius: 8px; }
        .bg-critical { background-color: #dc3545; }
        .bg-warning-custom { background-color: #ffc107; color: #000; }
        .bg-info-custom { background-color: #17a2b8; }
        .card-header-blue { background-color: #0d6efd; color: white; font-weight: 500; }
        .table th { background: #f8f9fa; font-size: 13px; font-weight: 600; }
        .table td { vertical-align: middle; font-size: 13px; }
        .badge-year { background: #6c757d; font-size: 10px; margin-left: 5px; }
        .battery-block { padding: 5px; border-left: 3px solid #dee2e6; }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show">
            <?= h($_GET['msg']) ?><button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($mode === 'add' || $mode === 'edit'): ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-car-battery me-2"></i><?= $mode === 'edit' ? 'Edit UPS' : 'Add New UPS' ?></h4>
            <a href="ups_inventory.php" class="btn btn-secondary btn-sm">Back to List</a>
        </div>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="<?= $mode ?>">
            <?php if($mode === 'edit'): ?><input type="hidden" name="id" value="<?= (int)($editData['id'] ?? 0) ?>"><?php endif; ?>
            
            <div class="card shadow-sm mb-4"><div class="card-body">
                <!-- Row 1: Basic UPS Info -->
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">UPS No / ID <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="ups_no" value="<?= h($editData['ups_no'] ?? 'UPS001') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Model</label>
                        <input type="text" class="form-control" name="model" value="<?= h($editData['model'] ?? '') ?>" placeholder="e.g. APC 600VA">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="Active" <?= ($editData['status']??'')=='Active'?'selected':'' ?>>Active</option>
                            <option value="Inactive" <?= ($editData['status']??'')=='Inactive'?'selected':'' ?>>Inactive</option>
                        </select>
                    </div>
                </div>

                <!-- Row 2: Purchase Info -->
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Purchase Date</label>
                        <input type="date" class="form-control" name="purchase_date" value="<?= h($editData['purchase_date'] ?? date('Y-m-d')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Vendor</label>
                        <input type="text" class="form-control" name="vendor" value="<?= h($editData['vendor'] ?? '') ?>" placeholder="Vendor Name">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Payment Ref</label>
                        <input type="text" class="form-control" name="payment" value="<?= h($editData['payment'] ?? '') ?>" placeholder="PO Number / Ref">
                    </div>
                </div>

                <!-- Row 3: Assignment -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Team</label>
                        <select class="form-select" name="team">
                            <option value="">Select Team</option>
                            <?php if($teams): while($t = $teams->fetch_assoc()): ?>
                                <option value="<?= h($t['team_name']) ?>" <?= ($editData['team']??'') == $t['team_name'] ? 'selected':'' ?>><?= h($t['team_name']) ?></option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Assigned User</label>
                        <input type="text" class="form-control" name="user" value="<?= h($editData['user'] ?? '') ?>" placeholder="User Name">
                    </div>
                </div>

                <!-- Batteries Section -->
                <div class="row">
                    <div class="col-md-6"><div class="card"><div class="card-header card-header-blue">Battery 1</div><div class="card-body">
                        <div class="mb-3"><label class="form-label">Serial No.</label><input type="text" class="form-control" name="bat1_serial" value="<?= h($editData['bat1_serial'] ?? '') ?>"></div>
                        <div class="mb-3"><label class="form-label">Install Date</label><input type="date" class="form-control" name="bat1_date" value="<?= h($editData['bat1_date'] ?? '') ?>"></div>
                    </div></div></div>
                    <div class="col-md-6"><div class="card"><div class="card-header" style="background:#17a2b8;color:white">Battery 2</div><div class="card-body">
                        <div class="mb-3"><label class="form-label">Serial No.</label><input type="text" class="form-control" name="bat2_serial" value="<?= h($editData['bat2_serial'] ?? '') ?>"></div>
                        <div class="mb-3"><label class="form-label">Install Date</label><input type="date" class="form-control" name="bat2_date" value="<?= h($editData['bat2_date'] ?? '') ?>"></div>
                    </div></div></div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-md-12 mb-3"><label class="form-label">Remarks</label><textarea class="form-control" name="remarks" rows="2"><?= h($editData['remarks'] ?? '') ?></textarea></div>
                </div>
                <div class="mt-3"><button type="submit" class="btn btn-primary">Save UPS</button><a href="ups_inventory.php" class="btn btn-outline-secondary ms-2">Cancel</a></div>
            </div></div>
        </form>
        <?php else: ?>
        <h4 class="mb-4"><i class="fas fa-battery-half me-2"></i>UPS & Battery Management</h4>
        
        <!-- Dashboard Stats -->
        <div class="row mb-4">
            <div class="col-md-4"><div class="card stat-card bg-critical p-3"><h2><?= $stats['critical'] ?></h2><small>Critical (36+ months)</small></div></div>
            <div class="col-md-4"><div class="card stat-card bg-warning-custom p-3"><h2><?= $stats['warning'] ?></h2><small>Warning (30-35 months)</small></div></div>
            <div class="col-md-4"><div class="card stat-card bg-info-custom p-3"><h2><?= $stats['info'] ?></h2><small>Info (24-29 months)</small></div></div>
        </div>

        <div class="card mb-4"><div class="card-body"><form method="get" class="row g-2"><div class="col-md-8"><input type="text" name="q" class="form-control" placeholder="Search UPS No, Model, Serial..." value="<?= h($q) ?>"></div><div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Search</button></div><div class="col-md-2"><a href="ups_inventory.php?mode=add" class="btn btn-success w-100">Add</a></div></form></div></div>
        
        <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>UPS Info</th><th>Vendor/Purchase</th><th>Batteries</th><th>Ageing</th><th>Warranty</th><th>Actions</th></tr></thead><tbody>
        <?php if($result && $result->num_rows > 0): while($row = $result->fetch_assoc()): 
            $age1 = getAgeMonths($row['bat1_date']); $age2 = getAgeMonths($row['bat2_date']); $maxAge = max($age1, $age2); $statusInfo = getAgeStatus($maxAge); ?>
        <tr>
            <td><strong><?= h($row['ups_no']) ?></strong><br><small><?= h($row['model']) ?></small><br><span class="badge bg-secondary"><?= h($row['team']) ?></span></td>
            <td><small>Vendor: <?= h($row['vendor']) ?></small><br><small>Date: <?= formatDate($row['purchase_date']) ?></small></td>
            <td>
                <div class="battery-block"><small>B1: <?= h($row['bat1_serial']) ?></small> <span class="badge badge-year"><?= round($age1/12, 1) ?>Y</span></div>
                <div class="battery-block"><small>B2: <?= h($row['bat2_serial']) ?></small> <span class="badge badge-year"><?= round($age2/12, 1) ?>Y</span></div>
            </td>
            <td><span class="badge bg-<?= $statusInfo['class'] ?>"><?= $maxAge ?> months</span></td>
            <td><?= $maxAge > 24 ? '<span class="badge bg-danger">Expired</span>' : '<span class="badge bg-success">Valid</span>' ?></td>
            <td><a href="ups_inventory.php?mode=edit&id=<?= $row['id'] ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a><form method="post" style="display:inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $row['id'] ?>"><button class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button></form></td>
        </tr><?php endwhile; else: ?><tr><td colspan="6" class="text-center py-5">No records found.</td></tr><?php endif; ?>
        </tbody></table></div></div></div>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>