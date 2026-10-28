<?php
require 'db.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

function calculateAgeing($date1, $date2 = null) {
    if (empty($date1) || $date1 === '0000-00-00') return 0;
    $battery1Date = new DateTime($date1);
    if (!empty($date2) && $date2 !== '0000-00-00') {
        $battery2Date = new DateTime($date2);
        $startDate = ($battery1Date > $battery2Date) ? $battery1Date : $battery2Date;
    } else {
        $startDate = $battery1Date;
    }
    $today = new DateTime();
    $interval = $today->diff($startDate);
    return $interval->y * 12 + $interval->m;
}

function redirect_with($params = []) {
    $base = 'ups.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// ---------------------------------------------------------
// HANDLE IMPORT LOGIC (XLSX Format) - Based on keyboard.php
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'Library file not found.', 'type' => 'danger']);
        }
        require_once $libPath;

        $xlsx = (class_exists('Shuchkin\SimpleXLSX')) ? Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']) : SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);

        if ($xlsx) {
            $rows = $xlsx->rows();
            $imported_count = 0;
            $updated_count = 0;
            $skipped_count = 0;
            
            // Map Team Names to Team IDs
            $teams_map = [];
            $team_res = $mysqli->query("SELECT team_id, team_name FROM team_master");
            while($t = $team_res->fetch_assoc()) {
                $teams_map[strtolower(trim($t['team_name']))] = $t['team_id'];
            }

            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header

                // Mapping based on your Excel layout:
                // 0:UPS No | 1:Team | 2:Assigned Date | 3:Battery1 | 4:Battery2 | 5:Ageing (Ignore) | 6:Status | 7:Remarks
                $ups_no = trim($r[0] ?? '');
                $team_name = trim($r[1] ?? '');
                $assigned_date_raw = $r[2] ?? '';
                $b1_date_raw = $r[3] ?? '';
                $b2_date_raw = $r[4] ?? '';
                $status = trim($r[6] ?? 'active');
                $remarks = trim($r[7] ?? '');

                if (empty($ups_no)) {
                    $skipped_count++;
                    continue;
                }

                // Date Parsing
                $parseExcelDate = function($val) {
                    if (empty($val)) return null;
                    if (is_numeric($val)) {
                        $unix = ($val - 25569) * 86400;
                        return gmdate("Y-m-d", $unix);
                    }
                    $ts = strtotime($val);
                    return $ts ? date('Y-m-d', $ts) : null;
                };

                $assigned_date = $parseExcelDate($assigned_date_raw);
                $date_battery1 = $parseExcelDate($b1_date_raw);
                $date_battery2 = $parseExcelDate($b2_date_raw);
                $team_id = $teams_map[strtolower($team_name)] ?? null;
                $ageing = calculateAgeing($date_battery1, $date_battery2);

                // Check Existence
                $check = $mysqli->prepare("SELECT id FROM tbl_ups WHERE ups_no = ?");
                $check->bind_param("s", $ups_no);
                $check->execute();
                $exists = $check->get_result()->fetch_assoc();
                $check->close();

                if (!$exists) {
                    $stmt = $mysqli->prepare("INSERT INTO tbl_ups (ups_no, team_id, assigned_date, date_battery1, date_battery2, ageing, status, remarks, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $stmt->bind_param("sisssisss", $ups_no, $team_id, $assigned_date, $date_battery1, $date_battery2, $ageing, $status, $remarks, $ip);
                    if ($stmt->execute()) $imported_count++;
                    $stmt->close();
                } else {
                    $stmt = $mysqli->prepare("UPDATE tbl_ups SET team_id=?, assigned_date=?, date_battery1=?, date_battery2=?, ageing=?, status=?, remarks=?, updated_at=NOW(), updated_ip=? WHERE ups_no=?");
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $stmt->bind_param("isssissss", $team_id, $assigned_date, $date_battery1, $date_battery2, $ageing, $status, $remarks, $ip, $ups_no);
                    if ($stmt->execute()) $updated_count++;
                    $stmt->close();
                }
            }
            redirect_with(['msg' => "Process Complete. Imported: $imported_count, Updated: $updated_count, Skipped: $skipped_count", 'type' => 'success']);
        } else {
            redirect_with(['msg' => 'SimpleXLSX Error', 'type' => 'danger']);
        }
    }
}

// Handle Manual POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action']) || (isset($_POST['action']) && $_POST['action'] !== 'import_excel')) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $ups_no = trim($_POST['ups_no'] ?? '');
        $team_id = !empty($_POST['team_id']) ? (int)$_POST['team_id'] : null;
        $assigned_date = trim($_POST['assigned_date'] ?? '');
        $date_battery1 = trim($_POST['date_battery1'] ?? '');
        $date_battery2 = trim($_POST['date_battery2'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $status = $_POST['status'] ?? 'active';
        $ageing = calculateAgeing($date_battery1, $date_battery2);
        
        if ($ups_no === '') redirect_with(['msg' => 'UPS No is required.', 'type' => 'danger']);
        
        if ($action === 'add') {
            $stmt = $mysqli->prepare("INSERT INTO tbl_ups (ups_no, team_id, assigned_date, date_battery1, date_battery2, ageing, remarks, status, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->bind_param("sisssisss", $ups_no, $team_id, $assigned_date, $date_battery1, $date_battery2, $ageing, $remarks, $status, $_SERVER['REMOTE_ADDR']);
            if ($stmt->execute()) redirect_with(['msg' => "UPS '$ups_no' added successfully", 'type' => 'success']);
            $stmt->close();
        } elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $mysqli->prepare("UPDATE tbl_ups SET ups_no=?, team_id=?, assigned_date=?, date_battery1=?, date_battery2=?, ageing=?, remarks=?, status=?, updated_at=NOW(), updated_ip=? WHERE id=?");
            $stmt->bind_param("sisssisssi", $ups_no, $team_id, $assigned_date, $date_battery1, $date_battery2, $ageing, $remarks, $status, $_SERVER['REMOTE_ADDR'], $id);
            if ($stmt->execute()) redirect_with(['msg' => "Record updated successfully", 'type' => 'success']);
            $stmt->close();
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $del = $mysqli->prepare("DELETE FROM tbl_ups WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            redirect_with(['msg' => "UPS record deleted", 'type' => 'warning']);
        }
    }
}

// View Logic
$mode = $_GET['mode'] ?? 'view';
$editUPS = null;
if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $mysqli->prepare("SELECT * FROM tbl_ups WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $editUPS = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$q = trim($_GET['q'] ?? '');
$fteam_id = (int)($_GET['team_id'] ?? 0);
$falert = $_GET['alert'] ?? '';

$sql = "SELECT u.*, t.team_name FROM tbl_ups u LEFT JOIN team_master t ON u.team_id = t.team_id WHERE 1=1";
if ($q !== '') $sql .= " AND (u.ups_no LIKE '%$q%' OR u.remarks LIKE '%$q%')";
if ($fteam_id > 0) $sql .= " AND u.team_id = $fteam_id";
if ($falert === 'critical') $sql .= " AND u.ageing >= 36";
if ($falert === 'warning') $sql .= " AND u.ageing >= 30 AND u.ageing < 36";
$sql .= " ORDER BY u.ageing DESC";
$upsRecords = $mysqli->query($sql);

$allTeams = [];
$tRes = $mysqli->query("SELECT team_id, team_name FROM team_master WHERE status = 'active'");
while($tr = $tRes->fetch_assoc()) $allTeams[] = $tr;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>UPS & Battery Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .main-content { padding: 20px; max-width: 1600px; margin: 0 auto; }
        .table th { background-color: #2c3e50; color: white; font-size: 12px; }
        .team-badge { background-color: #6c757d; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
            <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show"><?= h($_GET['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
            <div class="d-flex justify-content-between mb-4">
                <h4><?= $mode === 'edit' ? 'Edit UPS' : 'Add New UPS' ?></h4>
                <a href="ups.php" class="btn btn-secondary btn-sm">Back to List</a>
            </div>
            <div class="card"><div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?><input type="hidden" name="id" value="<?= $editUPS['id'] ?>"><?php endif; ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">UPS No.</label>
                            <input type="text" name="ups_no" class="form-control" value="<?= h($editUPS['ups_no'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Team</label>
                            <select name="team_id" class="form-select">
                                <option value="">Select Team</option>
                                <?php foreach($allTeams as $t): ?>
                                    <option value="<?= $t['team_id'] ?>" <?= ($editUPS && $editUPS['team_id'] == $t['team_id']) ? 'selected' : '' ?>><?= h($t['team_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">UPS Assigned Date</label>
                            <input type="date" name="assigned_date" class="form-control" value="<?= h($editUPS['assigned_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Battery 1 Installation</label>
                            <input type="date" name="date_battery1" class="form-control" value="<?= h($editUPS['date_battery1'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Battery 2 Installation</label>
                            <input type="date" name="date_battery2" class="form-control" value="<?= h($editUPS['date_battery2'] ?? '') ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Remarks</label>
                            <input type="text" name="remarks" class="form-control" value="<?= h($editUPS['remarks'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" <?= ($editUPS && $editUPS['status'] == 'active') ? 'selected' : '' ?>>Active</option>
                                <option value="maintenance" <?= ($editUPS && $editUPS['status'] == 'maintenance') ? 'selected' : '' ?>>Maintenance</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-4">Save Record</button>
                </form>
            </div></div>
        <?php else: ?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4>UPS & Battery Management</h4>
                <div>
                    <button type="button" class="btn btn-warning btn-sm fw-bold me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                        <i class="fas fa-file-excel me-1"></i> Import Excel
                    </button>
                    <a href="ups.php?mode=add" class="btn btn-success btn-sm"><i class="fas fa-plus"></i> Add UPS</a>
                </div>
            </div>

            <div class="card mb-4"><div class="card-body">
                <form method="get" class="row g-2">
                    <div class="col-md-4"><input type="text" name="q" class="form-control form-control-sm" placeholder="Search UPS..." value="<?= h($q) ?>"></div>
                    <div class="col-md-3"><select name="team_id" class="form-select form-select-sm"><option value="">All Teams</option><?php foreach($allTeams as $t): ?><option value="<?= $t['team_id'] ?>" <?= $fteam_id == $t['team_id'] ? 'selected' : '' ?>><?= $t['team_name'] ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><button type="submit" class="btn btn-primary btn-sm">Search</button> <a href="ups.php" class="btn btn-secondary btn-sm">Clear</a></div>
                </form>
            </div></div>

            <div class="card"><div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>UPS No.</th>
                            <th>Team</th>
                            <th>UPS Assigned Date</th>
                            <th>Battery 1</th>
                            <th>Battery 2</th>
                            <th>Ageing</th>
                            <th>Status</th>
                            <th>Remarks</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i=0;
                        while($row = $upsRecords->fetch_assoc()): $i=$i+1;;
                            $age = $row['ageing'];
                            $cls = ($age >= 36) ? 'danger' : (($age >= 24) ? 'warning' : 'success');
                        ?>
                        <tr>
                            <td><?= $i ?></td>
                            <td><strong><?= h($row['ups_no']) ?></strong></td>
                            <td><span class="team-badge"><?= h($row['team_name'] ?? 'N/A') ?></span></td>
                            <td><?= formatDate($row['assigned_date']) ?></td>
                            <td><?= formatDate($row['date_battery1']) ?></td>
                            <td><?= formatDate($row['date_battery2']) ?></td>
                            <td><span class="badge bg-<?= $cls ?>"><?= $age ?> Months</span></td>
                            <td><span class="badge bg-<?= $row['status']=='active'?'primary':'secondary'?>"><?= $row['status'] ?></span></td>
                            <td><small><?= h($row['remarks']) ?></small></td>
                            <td class="text-center">
    <div class="btn-group btn-group-sm" role="group">
        <!-- Edit Button -->
        <a href="ups.php?mode=edit&id=<?= (int)$row['id'] ?>" 
           class="btn btn-warning" title="Edit">
            <i class="fas fa-edit"></i>
        </a>
        
        <!-- Delete Button Container -->
        <form method="post" style="display:inline-block;" 
              onsubmit="return confirm('Are you sure you want to delete UPS: <?= h($row['ups_no']) ?>?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
            <button type="submit" class="btn btn-danger" title="Delete" style="border-top-left-radius: 0; border-bottom-left-radius: 0;">
                <i class="fas fa-trash"></i>
            </button>
        </form>
    </div>
</td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div></div>
        <?php endif; ?>
    </div>

    <!-- IMPORT MODAL -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title fw-bold"><i class="fas fa-file-excel me-2"></i>Import UPS Excel</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label fw-bold">Select File (.xlsx)</label>
                                <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Template</label>
                                <button type="button" class="btn btn-outline-success w-100" onclick="downloadUPSSample()">Download CSV</button>
                            </div>
                        </div>
                        <div class="alert alert-info mt-3 small">
                            <strong>Required Columns:</strong> 1. UPS No, 2. Team Name, 3. UPS Assigned Date, 4. Battery1 Date, 5. Battery2 Date, 6. Ageing (Calculated), 7. Status, 8. Remarks.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-dark">Import Data</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function downloadUPSSample() {
            const content = "UPS No,Team,UPS Assigned Date,Battery1,Battery2,Ageing,Status,Remarks\n" +
                            "UPS001,CE,01/01/2024,06/21/2024,06/21/2024,,active,Sample Remark";
            const blob = new Blob([content], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = 'ups_sample.csv';
            document.body.appendChild(a); a.click();
            document.body.removeChild(a);
        }
    </script>
</body>
</html>