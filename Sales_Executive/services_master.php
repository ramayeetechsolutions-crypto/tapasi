<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Helper
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Refactored to use sessions for messages to prevent URL-based XSS
function redirect_with($params =[]) {
    if (isset($params['msg'])) {
        $_SESSION['flash_msg'] = $params['msg'];
        $_SESSION['flash_type'] = $params['type'] ?? 'info';
        unset($params['msg'], $params['type']);
    }
    $base = 'services_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
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
            $error_details =[];
            
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header Row

                // --- COLUMN MAPPING ---
                // 0: Category, 1: Service Name, 2: Description
                
                $category     = trim($r[0] ?? '');
                $service_name = trim($r[1] ?? '');
                $description  = trim($r[2] ?? '');
                
                if (empty($service_name)) {
                    $skipped_count++;
                    $error_details[] = "Row " . ($k + 1) . ": Missing Service Name";
                    continue;
                }

                $checkStmt = $mysqli->prepare("SELECT service_id FROM services_master WHERE service_name = ?");
                $checkStmt->bind_param("s", $service_name);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->num_rows > 0;
                $checkStmt->close();

                if (!$exists) {
                    $insertStmt = $mysqli->prepare("INSERT INTO services_master (category, service_name, description) VALUES (?, ?, ?)");
                    $insertStmt->bind_param("sss", $category, $service_name, $description);
                    
                    if ($insertStmt->execute()) {
                        $imported_count++;
                    } else {
                        $skipped_count++;
                        $error_details[] = "Row " . ($k + 1) . ": " . $mysqli->error;
                    }
                    $insertStmt->close();
                } else {
                    $updateStmt = $mysqli->prepare("UPDATE services_master SET category = ?, description = ?, updated_date = NOW() WHERE service_name = ?");
                    $updateStmt->bind_param("sss", $category, $description, $service_name);
                    
                    if ($updateStmt->execute()) {
                        $updated_count++;
                    }
                    $updateStmt->close();
                }
            }
            
            $msg = "Process Complete.<br>";
            $msg .= "<strong>Imported:</strong> $imported_count new services.<br>";
            $msg .= "<strong>Updated:</strong> $updated_count existing services.<br>";
            $msg .= "<strong>Skipped:</strong> $skipped_count rows.";
            
            if (!empty($error_details)) {
                $msg .= "<br><small class='text-danger'>" . implode("<br>", array_slice($error_details, 0, 5)) . "</small>";
            }
            
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

// Handle POST actions for Add, Edit, Delete, Toggle Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] !== 'import_excel') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['service_id'] ?? 0);
    
    if ($action === 'add_service' || $action === 'update_service') {
        $cat = trim($_POST['category'] ?? '');
        $name = trim($_POST['service_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if ($action === 'add_service') {
            $stmt = $mysqli->prepare("INSERT INTO services_master (category, service_name, description) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $cat, $name, $desc);
        } else {
            $stmt = $mysqli->prepare("UPDATE services_master SET category=?, service_name=?, description=?, updated_date=NOW() WHERE service_id=?");
            $stmt->bind_param("sssi", $cat, $name, $desc, $id);
        }
        $stmt->execute();
        redirect_with(['msg' => 'Success', 'type' => 'success']);
    }

    if ($action === 'delete_service') {
        $mysqli->query("DELETE FROM services_master WHERE service_id = $id");
        redirect_with(['msg' => 'Deleted', 'type' => 'warning']);
    }

    if ($action === 'toggle_status') {
        $mysqli->query("UPDATE services_master SET is_active = NOT is_active, updated_date = NOW() WHERE service_id = $id");
        redirect_with(['msg' => 'Status Updated', 'type' => 'info']);
    }
}

// Data Fetching
$q = $_GET['q'] ?? '';
$cat_f = $_GET['category_filter'] ?? '';
$status_f = $_GET['status_filter'] ?? ''; // New variable for Status filter

$sql = "SELECT * FROM services_master WHERE (service_name LIKE ? OR category LIKE ?)";

if ($cat_f !== '') {
    $sql .= " AND category='" . $mysqli->real_escape_string($cat_f) . "'";
}
if ($status_f !== '') {
    $sql .= " AND is_active='" . (int)$status_f . "'";
}

$sql .= " ORDER BY category, service_name";

$stmt = $mysqli->prepare($sql);
$term = "%$q%";
$stmt->bind_param("ss", $term, $term);
$stmt->execute();
$services = $stmt->get_result();

$categories =[];
$res = $mysqli->query("SELECT DISTINCT category FROM services_master WHERE category != '' ORDER BY category");
while($r = $res->fetch_assoc()) $categories[] = $r['category'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Services Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background:#f4f6f9; font-size: 0.85rem; }
        .stats-card { padding: 15px; color: white; border-radius: 8px; margin-bottom: 15px; }
        .table thead th { background-color: #6c757d !important; color: white !important; border-bottom: none !important; } 
    </style>
</head>
<body>
    
    <?php require 'header.php'; ?>
    <div class="container-fluid py-3" style="margin-top: 60px;">
        <h3>Services Master</h3>
        <!-- Render Secure Flash Messages -->
        <?php if (!empty($_SESSION['flash_msg'])): ?>
            <div class="alert alert-<?= h($_SESSION['flash_type'] ?? 'info') ?> alert-dismissible fade show py-2">
                <?= $_SESSION['flash_msg'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>
        <?php endif; ?>

        <!-- Statistics Row -->
        <div class="row g-2 mb-3">
            <?php
            $counts = [['Total', "SELECT COUNT(*) FROM services_master", 'bg-primary'],['Active', "SELECT COUNT(*) FROM services_master WHERE is_active=1", 'bg-success'],['Categories',"SELECT COUNT(DISTINCT service_id) FROM client_services WHERE is_active=1", 'bg-warning']
            ];
            foreach($counts as $c): $val = @$mysqli->query($c[1])->fetch_row()[0] ?? 0; ?>
            <div class="col-md-4">
                <div class="stats-card <?= $c[2] ?>">
                    <h4 class="mb-0"><?= $val ?></h4>
                    <small><?= $c[0] ?></small>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body py-2">
                <form class="row g-2 align-items-center">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control form-control-sm" placeholder="Sort by Service Name" value="<?= h($q) ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <select name="category_filter" class="form-select form-select-sm">
                            <option value="">Sort by Categories</option>
                            <?php foreach($categories as $cat): ?>
                                <option value="<?= h($cat) ?>" <?= $cat_f == $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- New Status Filter Dropdown -->
                    <div class="col-md-2">
                        <select name="status_filter" class="form-select form-select-sm">
                            <option value="">Sort by Status</option>
                            <option value="1" <?= $status_f === '1' ? 'selected' : '' ?>>Active</option>
                            <option value="0" <?= $status_f === '0' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="col-md-1">
                        <button class="btn btn-sm btn-primary w-100">Filter</button>
                    </div>
                    
                    <div class="col-md-4 text-end">
                        <button type="button" class="btn btn-warning btn-sm me-1 text-dark" data-bs-toggle="modal" data-bs-target="#importModal">
                            <i class="fas fa-file-excel"></i> Import Excel
                        </button>
                        <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#serviceModal" onclick="clearForm()">
                            <i class="fas fa-plus"></i> Add Service
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Category</th>
                            <th>Service Name</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i=1; while($row = $services->fetch_assoc()): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><span class="badge bg-light text-dark border"><?= h($row['category']) ?></span></td>
                            <td><strong><?= h($row['service_name']) ?></strong></td>
                            <td><small class="text-muted"><?= h($row['description']) ?></small></td>
                            <td><span class="badge bg-<?= $row['is_active']?'success':'danger'?>"><?= $row['is_active']?'Active':'Inactive'?></span></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-warning" onclick="editService(<?= h(json_encode($row)) ?>)"><i class="fas fa-edit"></i></button>
                                    <form method="post" style="display:inline;"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="service_id" value="<?= $row['service_id']?>"><button class="btn btn-outline-secondary"><i class="fas fa-sync"></i></button></form>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete_service"><input type="hidden" name="service_id" value="<?= $row['service_id']?>"><button class="btn btn-outline-danger"><i class="fas fa-trash"></i></button></form>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Unified Modal for Add/Edit -->
    <div class="modal fade" id="serviceModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <div class="modal-header py-2 bg-dark text-white">
                    <h6 class="modal-title" id="mTitle">Add Service</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="mAction" value="add_service">
                    <input type="hidden" name="service_id" id="mId">
                    <div class="mb-2">
                        <label class="form-label mb-0">Category</label>
                        <!-- Allows typing a new category or selecting existing one by mixing datalist or keeping it simple. For simplicity, just text input. -->
                        <input type="text" list="categoryOptions" class="form-control form-control-sm" name="category" id="mCat" required>
                        <datalist id="categoryOptions">
                            <?php foreach($categories as $cat): ?><option value="<?= h($cat) ?>"></option><?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="mb-2">
                        <label class="form-label mb-0">Service Name</label>
                        <input type="text" class="form-control form-control-sm" name="service_name" id="mName" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label mb-0">Description</label>
                        <textarea class="form-control form-control-sm" name="description" id="mDesc" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer py-1">
                    <button type="submit" class="btn btn-sm btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- IMPORT MODAL -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">Import Services from Excel</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-12 mb-2">
                                <label class="fw-bold mb-1">Select Excel File (.xlsx)</label>
                                <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                            </div>
                            <div class="col-md-12 mt-2">
                                <button type="button" class="btn btn-outline-success btn-sm w-100" onclick="downloadSample()">Download Sample Format</button>
                            </div>
                        </div>
                        <div class="alert alert-danger mt-3 mb-0 pb-2">
                            <strong>Crucial Step:</strong> The sample downloads as a <code>.csv</code> file. You <strong>MUST</strong> open it in Excel, click <em>Save As</em>, and save it as an <strong>Excel Workbook (.xlsx)</strong> before importing.
                        </div>
                        <div class="alert alert-info mt-2">
                            <h6>Required Column Order:</h6>
                            <ol class="mb-0">
                                <li>Category</li>
                                <li><strong>Service Name (Mandatory)</strong></li>
                                <li>Description</li>
                            </ol>
                            <hr class="my-2">
                            <small><em>Note: If the Service Name already exists, the Category and Description will be updated.</em></small>
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
        const modal = new bootstrap.Modal(document.getElementById('serviceModal'));
        
        function clearForm() {
            document.getElementById('mTitle').innerText = "Add Service";
            document.getElementById('mAction').value = "add_service";
            document.getElementById('mId').value = "";
            document.getElementById('mCat').value = "";
            document.getElementById('mName').value = "";
            document.getElementById('mDesc').value = "";
        }

        function editService(data) {
            document.getElementById('mTitle').innerText = "Edit Service";
            document.getElementById('mAction').value = "update_service";
            document.getElementById('mId').value = data.service_id;
            document.getElementById('mCat').value = data.category;
            document.getElementById('mName').value = data.service_name;
            document.getElementById('mDesc').value = data.description;
            modal.show();
        }

        function downloadSample() {
            const headers = "Category,Service Name,Description\n";
            const row1 = "Development,Web Application Development,Full stack web application creation\n";
            const row2 = "Design,Logo Creation,Custom logo and branding materials";
            
            const blob = new Blob([headers + row1 + row2], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'services_sample.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            
            alert("Important: The file downloaded is a CSV.\n\nThe system only accepts .xlsx files. Please open this file in Excel and 'Save As -> Excel Workbook (.xlsx)' before uploading.");
        }
    </script>
</body>
</html>