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

// ---------------------------------------------------------
// HANDLE IMPORT LOGIC (XLSX Format)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'Library file not found.', 'type' => 'danger']);
        }
        
        require_once $libPath;

        $xlsx = null;
        if (class_exists('Shuchkin\SimpleXLSX')) {
            $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        } elseif (class_exists('SimpleXLSX')) {
            $xlsx = SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        } else {
            redirect_with(['msg' => 'Class SimpleXLSX not found.', 'type' => 'danger']);
        }

        if ($xlsx) {
            $rows = $xlsx->rows();
            $imported_count = 0;
            $updated_count = 0;
            $skipped_count = 0;
            
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header Row

                // Columns: Purchase Date, Model, User, Payment, Payee(Vendor), Given Date, Status
                $purchase_date_raw = trim($r[0] ?? '');
                $model          = trim($r[1] ?? '');
                $user           = trim($r[2] ?? '');
                $payment        = trim($r[3] ?? '');
                $vendor         = trim($r[4] ?? '');
                $given_date_raw = trim($r[5] ?? '');
                $status         = trim($r[6] ?? 'Available');
                
                if (empty($model)) {
                    $skipped_count++;
                    continue;
                }

                // Handle Purchase Date
                $purchase_date = NULL;
                if (!empty($purchase_date_raw)) {
                    if (is_numeric($purchase_date_raw)) {
                        if (class_exists('Shuchkin\SimpleXLSX')) {
                            $purchase_date = date('Y-m-d', Shuchkin\SimpleXLSX::unixstamp($purchase_date_raw));
                        } else {
                            $unix = ($purchase_date_raw - 25569) * 86400;
                            $purchase_date = gmdate("Y-m-d", $unix);
                        }
                    } else {
                        $ts = strtotime($purchase_date_raw);
                        if ($ts !== false) $purchase_date = date('Y-m-d', $ts);
                    }
                }

                // Handle Given Date
                $given_date = NULL;
                if (!empty($given_date_raw)) {
                    if (is_numeric($given_date_raw)) {
                        if (class_exists('Shuchkin\SimpleXLSX')) {
                            $given_date = date('Y-m-d', Shuchkin\SimpleXLSX::unixstamp($given_date_raw));
                        } else {
                            $unix = ($given_date_raw - 25569) * 86400;
                            $given_date = gmdate("Y-m-d", $unix);
                        }
                    } else {
                        $ts = strtotime($given_date_raw);
                        if ($ts !== false) $given_date = date('Y-m-d', $ts);
                    }
                }

                // Check for duplicates (Model + User + Purchase Date)
                $checkStmt = $mysqli->prepare("SELECT id FROM tbl_monitor WHERE model = ? AND user = ? AND purchase_date = ?");
                $checkStmt->bind_param("sss", $model, $user, $purchase_date);
                $checkStmt->execute();
                $res = $checkStmt->get_result();
                
                if ($res->num_rows > 0) {
                    $existing_id = $res->fetch_assoc()['id'];
                    $updateStmt = $mysqli->prepare("UPDATE tbl_monitor SET payment = ?, vendor = ?, given_date = ?, status = ?, updated_ip = ?, updated_at = NOW() WHERE id = ?");
                    $updateStmt->bind_param("sssssi", $payment, $vendor, $given_date, $status, $_SERVER['REMOTE_ADDR'], $existing_id);
                    if ($updateStmt->execute()) $updated_count++;
                    $updateStmt->close();
                } else {
                    $insertStmt = $mysqli->prepare("INSERT INTO tbl_monitor (purchase_date, model, user, payment, vendor, given_date, status, created_ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $insertStmt->bind_param("ssssssss", $purchase_date, $model, $user, $payment, $vendor, $given_date, $status, $_SERVER['REMOTE_ADDR']);
                    if ($insertStmt->execute()) $imported_count++;
                    $insertStmt->close();
                }
                $checkStmt->close();
            }
            
            $msg = "Import Complete. Imported: $imported_count | Updated: $updated_count | Skipped: $skipped_count";
            $type = ($imported_count > 0 || $updated_count > 0) ? 'success' : 'warning';
            redirect_with(['msg' => $msg, 'type' => $type]);

        } else {
            $err = (method_exists('Shuchkin\SimpleXLSX', 'parseError')) ? Shuchkin\SimpleXLSX::parseError() : 'Unknown Error';
            redirect_with(['msg' => 'SimpleXLSX Parse Error: ' . $err, 'type' => 'danger']);
        }
    } else {
        redirect_with(['msg' => 'No file uploaded or file error.', 'type' => 'danger']);
    }
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
    if ($action !== 'import_excel') {
        redirect_with();
    }
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
    <title>Monitor</title>
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
                    <div class="col-md-4 mb-3"><label class="form-label">Payee</label><input type="text" class="form-control" name="vendor" value="<?= h($editData['vendor']??'') ?>"></div>
                    <div class="col-md-4 mb-3"><label class="form-label">Status</label><select class="form-select" name="status"><option value="Available" <?= ($editData['status']??'')=='Available'?'selected':'' ?>>Available</option><option value="Allocated" <?= ($editData['status']??'')=='Allocated'?'selected':'' ?>>Allocated</option><option value="In-Repair" <?= ($editData['status']??'')=='In-Repair'?'selected':'' ?>>In-Repair</option></select></div>
                    <div class="col-md-4 mb-3"><label class="form-label">Given Date</label><input type="date" class="form-control" name="given_date" value="<?= h($editData['given_date']??'') ?>"></div>
                </div>
                <div class="mt-3"><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save</button><a href="monitor.php" class="btn btn-outline-secondary ms-2">Cancel</a></div>
            </form>
        </div></div>
        <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-desktop me-2"></i>Monitor</h4>
            <div>
                <button type="button" class="btn btn-warning btn-sm me-2 fw-bold" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-excel me-1"></i> Import Excel
                </button>
                <a href="monitor.php?mode=add" class="btn btn-success btn-sm"><i class="fas fa-plus me-1"></i> Add New</a>
            </div>
        </div>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>S.No</th>
                                <th>Purchase Date</th>
                                <th>Model</th>
                                <th>User</th>
                                <th>Payment</th>
                                <th>Payee</th>
                                <th>Status</th>
                                <th>Given Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($result && $result->num_rows>0): $i=0;
                                while($row=$result->fetch_assoc()): $i=$i+1;?>
                            <tr>
                                <td><?= $i;?></td>
                                <td><?= h(formatDate($row['purchase_date'])) ?></td>
                                <td class="fw-bold"><?= h($row['model']) ?></td>
                                <td><?= h($row['user']) ?></td>
                                <td><?= h($row['payment']) ?></td>
                                <td><?= h($row['vendor']) ?></td>
                                <td><span class="badge bg-secondary"><?= h($row['status']) ?></span></td>
                                <td><?= h(formatDate($row['given_date'])) ?></td>
                                <td>
                                    <a href="monitor.php?mode=edit&id=<?= $row['id'] ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Delete?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                        <button class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="9" class="text-center py-4">No records found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- IMPORT MODAL -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-file-excel me-2"></i>Import Monitors from Excel
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <form action="" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Select Excel File (.xlsx)</label>
                                    <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                                    <small class="text-muted">Maximum file size: 5MB</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Download Sample</label>
                                <button type="button" class="btn btn-outline-success w-100" onclick="downloadSample()">
                                    <i class="fas fa-download me-1"></i> Sample Format
                                </button>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <h6 class="fw-bold"><i class="fas fa-info-circle me-1"></i> Excel Format Required:</h6>
                            <p class="mb-1"><strong>Columns in exact order (No extra columns):</strong></p>
                            <ol class="mb-2">
                                <li><strong>Purchase Date</strong> - YYYY-MM-DD or Excel Date</li>
                                <li><strong>Model</strong> - Monitor Name/Model (Mandatory)</li>
                                <li><strong>User</strong> - Assigned employee</li>
                                <li><strong>Payment</strong> - Payment details</li>
                                <li><strong>Payee</strong> - Vendor name</li>
                                <li><strong>Given Date</strong> - YYYY-MM-DD or Excel Date</li>
                                <li><strong>Status</strong> - Available / Allocated / In-Repair</li>
                            </ol>
                            <div class="border-top pt-2 mt-2">
                                <p class="mb-1"><strong><i class="fas fa-exclamation-triangle me-1"></i> Rules:</strong></p>
                                <ul class="mb-0">
                                    <li>If <strong>Model + User + Purchase Date</strong> matches, the record will be <span class="badge bg-warning text-dark">UPDATED</span>.</li>
                                    <li>If no match is found, a new record is <span class="badge bg-success">INSERTED</span>.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-dark"><i class="fas fa-upload me-1"></i> Import Data</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                var alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    var bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });

        function downloadSample() {
            const sampleContent = "Purchase Date,Model,User,Payment,Payee,Given Date,Status\n" +
                                 "2024-01-15,Dell 24 Inch IPS,John Doe,Credit Card,Dell India,2024-01-20,Allocated\n" +
                                 "2024-02-01,HP 27 Inch,,Online,Amazon,,Available";
            
            const blob = new Blob([sampleContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sample_monitor_import.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>