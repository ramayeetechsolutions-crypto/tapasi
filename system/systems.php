<br><br><br>
<?php
require 'db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Helper functions
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function redirect_with($params =[]) {
    $base = 'systems.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// ---------------------------------------------------------
// HANDLE IMPORT LOGIC (XLSX)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        $libPath = 'simplexlsx-master/src/SimpleXLSX.php'; // Ensure this path is correct
        if (!file_exists($libPath)) redirect_with(['msg' => 'Library not found', 'type' => 'danger']);
        
        require_once $libPath;
        $xlsx = \Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);

        if ($xlsx) {
            $rows = $xlsx->rows();
            $imp = 0; $upd = 0;
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header row
                
                $id_no       = trim($r[0] ?? '');
                $member_name = trim($r[1] ?? ''); // Added member_name after id_no
                $cabin_no    = trim($r[2] ?? '');
                $system_no   = trim($r[3] ?? ''); // Unique Key
                $os          = trim($r[4] ?? '');
                $processor   = trim($r[5] ?? '');
                $ram         = trim($r[6] ?? '');
                $cpu         = trim($r[7] ?? '');
                $cpu_no      = trim($r[8] ?? '');
                $monitor     = trim($r[9] ?? '');
                $monitor_no  = trim($r[10] ?? '');
                $keyboard    = trim($r[11] ?? '');
                $keyboard_no = trim($r[12] ?? '');
                $mouse       = trim($r[13] ?? '');
                $mouse_no    = trim($r[14] ?? '');
                $remarks     = trim($r[15] ?? '');

                if (empty($system_no)) continue;

                // Check if system exists
                $check = $mysqli->prepare("SELECT id FROM tbl_systems_v2 WHERE system_no = ?");
                $check->bind_param("s", $system_no);
                $check->execute();
                $exists = $check->get_result()->num_rows > 0;

                if ($exists) {
                    $sql = "UPDATE tbl_systems_v2 SET id_no=?, member_name=?, cabin_no=?, os=?, processor=?, ram=?, cpu=?, cpu_no=?, monitor=?, monitor_no=?, keyboard=?, keyboard_no=?, mouse=?, mouse_no=?, remarks=?, updated_at=NOW(), updated_ip=? WHERE system_no=?";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("sssssssssssssssss", $id_no, $member_name, $cabin_no, $os, $processor, $ram, $cpu, $cpu_no, $monitor, $monitor_no, $keyboard, $keyboard_no, $mouse, $mouse_no, $remarks, $_SERVER['REMOTE_ADDR'], $system_no);
                    $stmt->execute();
                    $upd++;
                } else {
                    $sql = "INSERT INTO tbl_systems_v2 (id_no, member_name, cabin_no, system_no, os, processor, ram, cpu, cpu_no, monitor, monitor_no, keyboard, keyboard_no, mouse, mouse_no, remarks, created_at, created_ip) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW(), ?)";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("sssssssssssssssss", $id_no, $member_name, $cabin_no, $system_no, $os, $processor, $ram, $cpu, $cpu_no, $monitor, $monitor_no, $keyboard, $keyboard_no, $mouse, $mouse_no, $remarks, $_SERVER['REMOTE_ADDR']);
                    $stmt->execute();
                    $imp++;
                }
            }
            redirect_with(['msg' => "Process Finished. $imp Added, $upd Updated.", 'type' => 'success']);
        }
    }
}

// ---------------------------------------------------------
// CRUD OPERATIONS
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'add' || $action === 'edit') {
        // Updated field array order: added member_name, removed checked_by
        $fields =['id_no','member_name','cabin_no','system_no','os','processor','ram','cpu','cpu_no','monitor','monitor_no','keyboard','keyboard_no','mouse','mouse_no','remarks'];
        $v =[]; foreach($fields as $f) { $v[$f] = trim($_POST[$f] ?? ''); }

        if ($action === 'add') {
            $sql = "INSERT INTO tbl_systems_v2 (".implode(',',$fields).", created_at, created_ip) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW(), ?)";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param(str_repeat('s', 16).'s', ...array_values($v), ...[$_SERVER['REMOTE_ADDR']]);
        } else {
            $id = (int)$_POST['id'];
            $sql = "UPDATE tbl_systems_v2 SET id_no=?, member_name=?, cabin_no=?, system_no=?, os=?, processor=?, ram=?, cpu=?, cpu_no=?, monitor=?, monitor_no=?, keyboard=?, keyboard_no=?, mouse=?, mouse_no=?, remarks=?, updated_at=NOW(), updated_ip=? WHERE id=?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param(str_repeat('s', 16).'si', ...array_values($v), ...[$_SERVER['REMOTE_ADDR'], $id]);
        }
        $stmt->execute();
        redirect_with(['msg' => 'System saved successfully', 'type' => 'success']);
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $mysqli->query("DELETE FROM tbl_systems_v2 WHERE id=$id");
        redirect_with(['msg' => 'Deleted successfully', 'type' => 'danger']);
    }
}

// ---------------------------------------------------------
// FETCH DATA
// ---------------------------------------------------------
$mode = $_GET['mode'] ?? 'view';
$editItem = null;
if ($mode === 'edit') {
    $res = $mysqli->query("SELECT * FROM tbl_systems_v2 WHERE id=".(int)$_GET['id']);
    $editItem = $res->fetch_assoc();
}
$q = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM tbl_systems_v2";
if($q) $sql .= " WHERE system_no LIKE '%$q%' OR id_no LIKE '%$q%' OR member_name LIKE '%$q%'";
$data = $mysqli->query($sql . " ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Systems V2</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .table { font-size: 12px; }
        .table th { background-color: #2c3e50; color: white; white-space: nowrap; }
        /* Image-Style Buttons */
        .btn-edit-square { 
            background-color: #ffc107; color: black; border: none; 
            padding: 8px 12px; border-radius: 4px; font-size: 16px;
        }
        .btn-delete-square { 
            background-color: #dc3545; color: white; border: none; 
            padding: 8px 12px; border-radius: 4px; font-size: 16px;
        }
        .btn-edit-square:hover { background-color: #e0a800; }
        .btn-delete-square:hover { background-color: #c82333; }
        .card-header { background-color: #f8f9fa; font-weight: bold; }
    </style>
</head>
<body class="bg-light">
    <?php require 'header.php'; ?>

    <div class="container-fluid p-4">
        <?php if (!empty($_GET['msg'])): ?>
            <div class="alert alert-<?= h($_GET['type']) ?> alert-dismissible fade show"><?= h($_GET['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
            <div class="card shadow-sm">
                <div class="card-header"><?= $mode==='edit'?'Edit System':'Add New System' ?></div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="action" value="<?= $mode ?>">
                        <?php if($editItem): ?><input type="hidden" name="id" value="<?= $editItem['id'] ?>"><?php endif; ?>
                        
                        <?php 
                        $fields =['id_no'=>'ID No','member_name'=>'Member Name','cabin_no'=>'Cabin No','system_no'=>'System No','os'=>'OS','processor'=>'Processor','ram'=>'RAM','cpu'=>'CPU Model','cpu_no'=>'CPU No','monitor'=>'Monitor Model','monitor_no'=>'Monitor No','keyboard'=>'Keyboard Model','keyboard_no'=>'Keyboard No','mouse'=>'Mouse Model','mouse_no'=>'Mouse No'];
                        foreach($fields as $name => $label): ?>
                            <div class="col-md-2">
                                <label class="form-label small fw-bold"><?= $label ?></label>
                                <input type="text" name="<?= $name ?>" class="form-control form-control-sm" value="<?= h($editItem[$name] ?? '') ?>">
                            </div>
                        <?php endforeach; ?>
                        <div class="col-md-12">
                            <label class="form-label small fw-bold">Remarks</label>
                            <textarea name="remarks" class="form-control form-control-sm"><?= h($editItem['remarks'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12 mt-4">
                            <button type="submit" class="btn btn-primary">Save System</button>
                            <a href="systems.php" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4><i class="fas fa-desktop me-2"></i>System Inventory</h4>
                <div>
                    <button type="button" class="btn btn-warning btn-sm me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                        <i class="fas fa-file-excel me-1"></i> Import Excel
                    </button>
                    <a href="systems.php?mode=add" class="btn btn-success btn-sm">
                        <i class="fas fa-plus me-1"></i> Add System
                    </a>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-body p-2">
                    <form method="get" class="row g-2 align-items-center">
                        <div class="col-md-4"><input type="text" name="q" class="form-control form-control-sm" placeholder="Search system, ID, or member..." value="<?= h($q) ?>"></div>
                        <div class="col-md-2"><button type="submit" class="btn btn-dark btn-sm w-100">Search</button></div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID No</th><th>Member Name</th><th>Cabin</th><th>System No</th><th>OS</th><th>Processor</th><th>RAM</th><th>CPU</th><th>CPU No</th><th>Monitor</th><th>Monitor No</th><th>Keyboard</th><th>Keyboard No</th><th>Mouse</th><th>Mouse No</th><th>Remarks</th><th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $data->fetch_assoc()): ?>
                            <tr>
                                <td><?= h($row['id_no']) ?></td>
                                <td><?= h($row['member_name']) ?></td>
                                <td><?= h($row['cabin_no']) ?></td>
                                <td class="fw-bold text-primary"><?= h($row['system_no']) ?></td>
                                <td><?= h($row['os']) ?></td>
                                <td><?= h($row['processor']) ?></td>
                                <td><?= h($row['ram']) ?></td>
                                <td><?= h($row['cpu']) ?></td>
                                <td><?= h($row['cpu_no']) ?></td>
                                <td><?= h($row['monitor']) ?></td>
                                <td><?= h($row['monitor_no']) ?></td>
                                <td><?= h($row['keyboard']) ?></td>
                                <td><?= h($row['keyboard_no']) ?></td>
                                <td><?= h($row['mouse']) ?></td>
                                <td><?= h($row['mouse_no']) ?></td>
                                <td><?= h($row['remarks']) ?></td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1">
                                        <a href="systems.php?mode=edit&id=<?= $row['id'] ?>" class="btn-edit-square" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="post" onsubmit="return confirm('Delete this system?')" style="display:inline;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn-delete-square" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Import Modal -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    <div class="modal-header bg-warning">
                        <h5 class="modal-title fw-bold">Import Systems from Excel</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row align-items-end mb-3">
                            <div class="col-md-8">
                                <label class="form-label fw-bold">Select Excel File (.xlsx)</label>
                                <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="btn btn-outline-success w-100" onclick="downloadSample()">
                                    <i class="fas fa-download me-1"></i> Download Sample
                                </button>
                            </div>
                        </div>
                        <div class="alert alert-info py-2 small mb-0">
                            <strong>Note:</strong> Columns must be in this order: <br>
                            1. Id_no, 2. Member Name, 3. cabin_no, 4. system_no (Unique), 5. os, 6. processor, 7. RAM, 8. cpu, 9. cpu_no, 10. monitor, 11. monitor_no, 12. keyboard, 13. keyboard_no, 14. mouse, 15. mouse_no, 16. remarks
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-dark">Start Import</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function downloadSample() {
        const headers =["Id_no", "Member Name", "cabin_no", "system_no", "os", "processor", "RAM", "cpu", "cpu_no", "monitor", "monitor_no", "keyboard", "keyboard_no", "mouse", "mouse_no", "remarks"];
        const data =["ASSET-001", "Alice Smith", "C-101", "SYS-505", "Windows 11", "Intel i5", "16GB", "Dell Optiplex", "CPU-SN123", "Dell 24", "MON-SN456", "Logitech", "KBD-789", "Logitech", "MSE-000", "New System"];
        
        let csvContent = "data:text/csv;charset=utf-8," 
            + headers.join(",") + "\n"
            + data.join(",");

        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", "system_inventory_sample.csv");
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    </script>
</body>
</html>