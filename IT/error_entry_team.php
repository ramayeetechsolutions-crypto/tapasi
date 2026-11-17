<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Session guard for IT
if (!isset($_SESSION['itname']) && !isset($_SESSION['infoname']) && !isset($_SESSION['uid'])) { 
    header("Location: ../index.php"); 
    exit; 
}

// Include SimpleXLSX library (for Excel import)
$libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = 'simplexlsx-master/SimpleXLSX.php';

// Helper functions
if (!function_exists('h')) {
    function h($s) { 
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
    }
}

if (!function_exists('formatDate')) {
    function formatDate($d) {
        if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
        return date('d-m-Y', strtotime($d));
    }
}

if (!function_exists('redirect_with')) {
    function redirect_with($params = []) {
        $base = 'error_entry_team.php';
        if (!empty($params)) $base .= '?' . http_build_query($params);
        header("Location: $base");
        exit;
    }
}

// Dropdown data
$employees = $mysqli->query("SELECT employee_name FROM employee_master WHERE status = 'Active' ORDER BY employee_name ASC");
$clients = $mysqli->query("SELECT DISTINCT client_name FROM client_master WHERE status = 'Active' AND client_name IS NOT NULL AND client_name != '' ORDER BY client_name");
$departments_from_master = $mysqli->query("SELECT team_name AS department_name FROM team_master WHERE status = 'Active' ORDER BY team_name");

// Handle Excel / CSV Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && ($_POST['action'] === 'import' || $_POST['action'] === 'import_excel')) {
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
        $rows = [];
        $parse_error = '';

        if ($ext === 'csv') {
            if (($handle = fopen($_FILES['excel_file']['tmp_name'], "r")) !== FALSE) {
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") rewind($handle);
                while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
                    $rows[] = $data;
                }
                fclose($handle);
            } else {
                $parse_error = "Failed to open CSV file.";
            }
        } else {
            if (!file_exists($libPath)) {
                redirect_with(['msg' => 'SimpleXLSX library not found.', 'type' => 'danger']);
            }
            require_once $libPath;
            $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
            if ($xlsx) {
                $rows = $xlsx->rows();
            } else {
                $parse_error = \Shuchkin\SimpleXLSX::parseError();
            }
        }

        if (!empty($rows)) {
            $count_added = 0;
            $count_skipped = 0;
            $errors = [];

            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Header row
                $sno               = trim($r[0] ?? '');
                $week              = trim($r[1] ?? '');
                $error_date        = trim($r[2] ?? '');
                $team              = trim($r[3] ?? '');
                $department        = trim($r[4] ?? '');
                $error_taken_by    = trim($r[5] ?? '');
                $internal_external = trim($r[6] ?? '');
                $client_name       = trim($r[7] ?? '');
                $article           = trim($r[8] ?? '');
                $error_type        = trim($r[9] ?? '');
                $error_subtype     = trim($r[10] ?? '');
                $yn                = trim($r[11] ?? '');
                $error_made_by     = trim($r[12] ?? '');
                $reason            = trim($r[13] ?? '');

                $formatted_date = !empty($error_date) ? date('Y-m-d', strtotime($error_date)) : null;

                if (empty($week) || empty($client_name)) {
                    $count_skipped++;
                    continue;
                }

                $stmt = $mysqli->prepare("INSERT INTO error_log 
                    (week, error_date, team, department, error_taken_by, internal_external, client_name, article, error_type, error_subtype, yn, error_made_by, reason, error_photo, status, created_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', 'Active', NOW())");
                $stmt->bind_param("sssssssssssss", 
                    $week, $formatted_date, $team, $department, $error_taken_by, $internal_external, $client_name, $article, $error_type, $error_subtype, $yn, $error_made_by, $reason);

                if ($stmt->execute()) {
                    $count_added++;
                } else {
                    $count_skipped++;
                }
                $stmt->close();
            }
            redirect_with(['msg' => "Import completed: $count_added added, $count_skipped skipped.", 'type' => 'success']);
        } else {
            redirect_with(['msg' => 'Failed to parse file: ' . ($parse_error ?: 'No data found'), 'type' => 'danger']);
        }
    } else {
        redirect_with(['msg' => 'Please select a valid CSV/Excel file.', 'type' => 'danger']);
    }
}

// Handle Form Submissions (Add / Edit / Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $week               = trim($_POST['week'] ?? '');
        $error_date         = trim($_POST['error_date'] ?? $_POST['date'] ?? '');
        $team               = trim($_POST['team'] ?? 'IT');
        $department         = trim($_POST['department'] ?? 'IT');
        $error_taken_by     = trim($_POST['error_taken_by'] ?? '');
        $internal_external  = trim($_POST['internal_external'] ?? '');
        $client_name        = trim($_POST['client_name'] ?? $_POST['client'] ?? '');
        $article            = trim($_POST['article'] ?? '');
        $error_type         = trim($_POST['error_type'] ?? $_POST['type_of_errors'] ?? '');
        $error_subtype      = trim($_POST['error_subtype'] ?? '');
        $yn                 = trim($_POST['yn'] ?? '');
        $error_made_by      = trim($_POST['error_made_by'] ?? $_POST['team_error_made_by'] ?? '');
        $reason             = trim($_POST['reason'] ?? $_POST['reason_for_errors'] ?? '');

        $formatted_date = !empty($error_date) ? date('Y-m-d', strtotime($error_date)) : null;

        if ($week === '' || $client_name === '') {
            redirect_with(['msg' => 'Week and Client Name are required.', 'type' => 'danger']);
        }

        // Photo Upload
        $error_photo = '';
        if (isset($_FILES['error_photo']) && $_FILES['error_photo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/error_photos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $ext = pathinfo($_FILES['error_photo']['name'], PATHINFO_EXTENSION);
            $error_photo = 'err_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            if (!move_uploaded_file($_FILES['error_photo']['tmp_name'], $uploadDir . $error_photo)) {
                $error_photo = '';
            }
        }

        if ($action === 'add') {
            $stmt = $mysqli->prepare("INSERT INTO error_log 
                (week, error_date, team, department, error_taken_by, internal_external, client_name, article, error_type, error_subtype, yn, error_made_by, reason, error_photo, status, created_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW())");
            $stmt->bind_param("ssssssssssssss", $week, $formatted_date, $team, $department, $error_taken_by, $internal_external, $client_name, $article, $error_type, $error_subtype, $yn, $error_made_by, $reason, $error_photo);
            if ($stmt->execute()) {
                redirect_with(['msg' => "Error log entry added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => "Error adding entry: " . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }

        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                if ($error_photo !== '') {
                    $stmt = $mysqli->prepare("UPDATE error_log SET 
                        week = ?, error_date = ?, team = ?, department = ?, error_taken_by = ?, internal_external = ?, client_name = ?, article = ?, error_type = ?, error_subtype = ?, yn = ?, error_made_by = ?, reason = ?, error_photo = ? 
                        WHERE id = ?");
                    $stmt->bind_param("ssssssssssssssi", $week, $formatted_date, $team, $department, $error_taken_by, $internal_external, $client_name, $article, $error_type, $error_subtype, $yn, $error_made_by, $reason, $error_photo, $id);
                } else {
                    $stmt = $mysqli->prepare("UPDATE error_log SET 
                        week = ?, error_date = ?, team = ?, department = ?, error_taken_by = ?, internal_external = ?, client_name = ?, article = ?, error_type = ?, error_subtype = ?, yn = ?, error_made_by = ?, reason = ? 
                        WHERE id = ?");
                    $stmt->bind_param("sssssssssssssi", $week, $formatted_date, $team, $department, $error_taken_by, $internal_external, $client_name, $article, $error_type, $error_subtype, $yn, $error_made_by, $reason, $id);
                }
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Error log updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => "Error updating entry: " . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $del = $mysqli->prepare("DELETE FROM error_log WHERE id = ?");
            $del->bind_param("i", $id);
            if ($del->execute()) {
                redirect_with(['msg' => "Error log entry deleted successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => "Error deleting entry: " . $del->error, 'type' => 'danger']);
            }
            $del->close();
        }
    }
}

// Mode
$mode = $_GET['mode'] ?? 'view';
$editEntry = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM error_log WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editEntry = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($editEntry && !empty($editEntry['error_date'])) {
            $editEntry['date'] = date('d-m-Y', strtotime($editEntry['error_date']));
        }
    }
    if (!$editEntry) $mode = 'view';
}

// Search and Filter variables
$q                  = trim($_GET['q'] ?? '');
$filter_week        = trim($_GET['week'] ?? '');
$filter_client      = trim($_GET['client'] ?? $_GET['client_name'] ?? '');
$filter_article     = trim($_GET['article'] ?? '');
$filter_employee    = trim($_GET['team'] ?? $_GET['employee'] ?? '');
$filter_status      = trim($_GET['status'] ?? '');
$filter_sort        = trim($_GET['sort'] ?? 'id');

// Build SQL query on error_log table (DESCENDING order, strictly IT section errors)
$sql = "SELECT id, week, error_date, team, department, error_taken_by, internal_external, client_name, article, error_type, error_subtype, yn, error_made_by, reason, error_photo, status, created_date 
        FROM error_log";
$cond = ["(team = 'IT' OR department = 'IT')"];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(client_name LIKE ? OR error_taken_by LIKE ? OR error_made_by LIKE ? OR error_type LIKE ? OR reason LIKE ? OR department LIKE ? OR team LIKE ? OR article LIKE ?)";
    $like = "%$q%";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like, $like]);
    $types .= 'ssssssss';
}

if ($filter_week !== '') {
    $cond[] = "week = ?";
    $params[] = $filter_week;
    $types .= 's';
}

if ($filter_client !== '') {
    $cond[] = "client_name = ?";
    $params[] = $filter_client;
    $types .= 's';
}

if ($filter_article !== '') {
    $cond[] = "article = ?";
    $params[] = $filter_article;
    $types .= 's';
}

if ($filter_employee !== '') {
    $cond[] = "(error_made_by = ? OR error_taken_by = ?)";
    $params[] = $filter_employee;
    $params[] = $filter_employee;
    $types .= 'ss';
}

if ($filter_status !== '') {
    $cond[] = "status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " WHERE " . implode(" AND ", $cond);
}

// Default sort is DESCENDING
if ($filter_sort === 'week') {
    $sql .= " ORDER BY week DESC";
} elseif ($filter_sort === 'client') {
    $sql .= " ORDER BY client_name ASC";
} elseif ($filter_sort === 'created_date') {
    $sql .= " ORDER BY created_date DESC";
} else {
    $sql .= " ORDER BY id DESC";
}

if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $entries = $stmt->get_result();
} else {
    $entries = $mysqli->query($sql);
}

// Fetch unique filter values for IT errors
$unique_weeks = $mysqli->query("SELECT DISTINCT week FROM error_log WHERE week != '' AND (team = 'IT' OR department = 'IT') ORDER BY week DESC");
$unique_clients = $mysqli->query("SELECT DISTINCT client_name FROM error_log WHERE client_name != '' AND (team = 'IT' OR department = 'IT') ORDER BY client_name ASC");
$unique_articles = $mysqli->query("SELECT DISTINCT article FROM error_log WHERE article != '' AND (team = 'IT' OR department = 'IT') ORDER BY article ASC");
$all_employees = $mysqli->query("SELECT employee_name AS name FROM employee_master WHERE status = 'Active' AND team_id = 8 ORDER BY employee_name ASC");
if (!$all_employees || $all_employees->num_rows === 0) {
    $all_employees = $mysqli->query("SELECT DISTINCT error_made_by AS name FROM error_log WHERE error_made_by != '' AND (team = 'IT' OR department = 'IT') ORDER BY error_made_by ASC");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Article Error Management - IT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1800px;
            margin: 75px auto 0 auto;
        }
        .main-content .alert {
            margin-top: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            border-radius: 8px;
        }
        .table thead th {
            background-color: #707981 !important;
            font-weight: bold;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            color: white !important;
            padding: 0.8rem 0.5rem;
            border: 1px solid #5a6268 !important;
        }
        .table td {
            vertical-align: middle;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
        }
        .form-label {
            font-weight: 500;
        }
        .select2-container--default .select2-selection--single {
            height: 38px;
            padding: 5px;
            border: 1px solid #ced4da;
        }
        .badge {
            font-size: 0.85em;
        }
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($mode === 'add' || $mode === 'edit'): ?>
        
        <!-- Add / Edit Form -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-file-alt me-2"></i><?= $mode === 'edit' ? 'Edit Error Log Entry' : 'Add New Error Log Entry' ?></h4>
            <a href="error_entry_team.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" autocomplete="off" id="errorLogForm">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if ($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editEntry['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <!-- Row 1 -->
                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="week" class="form-label">Week <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="week" name="week" 
                                       value="<?= h($editEntry['week'] ?? '') ?>" 
                                       placeholder="e.g., Week 1, Jan-2024" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="date" class="form-label">Date</label>
                                <input type="date" class="form-control" id="date" name="error_date" 
                                       value="<?= h($editEntry['error_date'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="team" class="form-label">Team</label>
                                <select class="form-control select2" id="team" name="team">
                                    <option value="IT" <?= ($editEntry['team'] ?? 'IT') === 'IT' ? 'selected' : '' ?>>IT</option>
                                    <option value="WK" <?= ($editEntry['team'] ?? '') === 'WK' ? 'selected' : '' ?>>WK</option>
                                    <option value="UQ" <?= ($editEntry['team'] ?? '') === 'UQ' ? 'selected' : '' ?>>UQ</option>
                                    <option value="XML" <?= ($editEntry['team'] ?? '') === 'XML' ? 'selected' : '' ?>>XML</option>
                                    <option value="Sales" <?= ($editEntry['team'] ?? '') === 'Sales' ? 'selected' : '' ?>>Sales</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="department" class="form-label">Department</label>
                                <select class="form-control select2" id="department" name="department">
                                    <option value="IT" <?= ($editEntry['department'] ?? 'IT') === 'IT' ? 'selected' : '' ?>>IT</option>
                                    <option value="XML" <?= ($editEntry['department'] ?? '') === 'XML' ? 'selected' : '' ?>>XML</option>
                                    <option value="AP" <?= ($editEntry['department'] ?? '') === 'AP' ? 'selected' : '' ?>>AP</option>
                                    <option value="Development" <?= ($editEntry['department'] ?? '') === 'Development' ? 'selected' : '' ?>>Development</option>
                                    <option value="FREELANCER" <?= ($editEntry['department'] ?? '') === 'FREELANCER' ? 'selected' : '' ?>>FREELANCER</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Row 2 -->
                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="error_taken_by" class="form-label">Error Taken By</label>
                                <input type="text" class="form-control" id="error_taken_by" name="error_taken_by" 
                                       value="<?= h($editEntry['error_taken_by'] ?? '') ?>" placeholder="e.g. Sales">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="internal_external" class="form-label">Internal / External</label>
                                <select class="form-select" id="internal_external" name="internal_external">
                                    <option value="">Select</option>
                                    <option value="Internal" <?= ($editEntry['internal_external'] ?? '') === 'Internal' ? 'selected' : '' ?>>Internal</option>
                                    <option value="External" <?= ($editEntry['internal_external'] ?? '') === 'External' ? 'selected' : '' ?>>External</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="client_name" class="form-label">Client Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="client_name" name="client_name" 
                                       value="<?= h($editEntry['client_name'] ?? '') ?>" placeholder="Client name" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="article" class="form-label">Article</label>
                                <input type="text" class="form-control" id="article" name="article" 
                                       value="<?= h($editEntry['article'] ?? '') ?>" placeholder="Article name / ID">
                            </div>
                        </div>
                    </div>

                    <!-- Row 3 -->
                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="error_type" class="form-label">Error Type</label>
                                <input type="text" class="form-control" id="error_type" name="error_type" 
                                       value="<?= h($editEntry['error_type'] ?? '') ?>" placeholder="e.g. Technical Issue">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="error_subtype" class="form-label">Error Subtype</label>
                                <input type="text" class="form-control" id="error_subtype" name="error_subtype" 
                                       value="<?= h($editEntry['error_subtype'] ?? '') ?>" placeholder="e.g. Missed out Crx">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="yn" class="form-label">Y / N</label>
                                <select class="form-select" id="yn" name="yn">
                                    <option value="">Select</option>
                                    <option value="Yes" <?= ($editEntry['yn'] ?? '') === 'Yes' ? 'selected' : '' ?>>Yes</option>
                                    <option value="No" <?= ($editEntry['yn'] ?? '') === 'No' ? 'selected' : '' ?>>No</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="error_made_by" class="form-label">Error Made By</label>
                                <input type="text" class="form-control" id="error_made_by" name="error_made_by" 
                                       value="<?= h($editEntry['error_made_by'] ?? '') ?>" placeholder="Person name">
                            </div>
                        </div>
                    </div>

                    <!-- Row 4 -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="reason" class="form-label">Reason / Comment</label>
                                <textarea class="form-control" id="reason" name="reason" rows="2" placeholder="Describe the error or reason"><?= h($editEntry['reason'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="error_photo" class="form-label">Photo / Attachment</label>
                                <input type="file" class="form-control" id="error_photo" name="error_photo" accept="image/*">
                                <?php if (!empty($editEntry['error_photo'])): ?>
                                <small class="text-muted mt-1 d-block">Current: <?= h($editEntry['error_photo']) ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="error_entry_team.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><?= $mode === 'edit' ? 'Update Entry' : 'Save Entry' ?></button>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode: Exact UI matching Executive Screenshot -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-file-alt me-2"></i>Error Logs</h4>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <!-- Action Buttons on Top Right -->
                <div class="d-flex justify-content-end mb-2 gap-2">
                    <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#importExcelModal">
                        <i class="fas fa-file-import me-1"></i> Import Excel
                    </button>
                    <button type="button" class="btn btn-success btn-sm" onclick="exportToExcel()">
                        <i class="fas fa-file-excel me-1"></i> Export Excel
                    </button>
                    <a href="error_entry_team.php?mode=add" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus me-1"></i> Add New
                    </a>
                </div>
                
                <form method="get" class="row g-3 align-items-center" id="searchForm">
                    <div class="col-md-2">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Global search..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="week" class="form-select form-select-sm">
                            <option value="">All Weeks</option>
                            <?php if ($unique_weeks && $unique_weeks->num_rows > 0): ?>
                                <?php $unique_weeks->data_seek(0); ?>
                                <?php while ($w = $unique_weeks->fetch_assoc()): ?>
                                <option value="<?= h($w['week']) ?>" <?= $filter_week === $w['week'] ? 'selected' : '' ?>>
                                    <?= h($w['week']) ?>
                                </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="client" class="form-select form-select-sm">
                            <option value="">All Clients</option>
                            <?php if ($unique_clients && $unique_clients->num_rows > 0): ?>
                                <?php $unique_clients->data_seek(0); ?>
                                <?php while ($c = $unique_clients->fetch_assoc()): ?>
                                <option value="<?= h($c['client_name']) ?>" <?= $filter_client === $c['client_name'] ? 'selected' : '' ?>>
                                    <?= h($c['client_name']) ?>
                                </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="article" class="form-select form-select-sm">
                            <option value="">All Articles</option>
                            <?php if ($unique_articles && $unique_articles->num_rows > 0): ?>
                                <?php $unique_articles->data_seek(0); ?>
                                <?php while ($art = $unique_articles->fetch_assoc()): ?>
                                <option value="<?= h($art['article']) ?>" <?= $filter_article === $art['article'] ? 'selected' : '' ?>>
                                    <?= h($art['article']) ?>
                                </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="team" class="form-select form-control-sm">
                            <option value="">All employees</option>
                            <?php if ($all_employees && $all_employees->num_rows > 0): ?>
                                <?php $all_employees->data_seek(0); ?>
                                <?php while ($emp = $all_employees->fetch_assoc()): ?>
                                <option value="<?= h($emp['name']) ?>" <?= $filter_employee === $emp['name'] ? 'selected' : '' ?>>
                                    <?= h($emp['name']) ?>
                                </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <select name="status" class="form-select form-control-sm">
                            <option value="">All Status</option>
                            <option value="Active" <?= $filter_status === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $filter_status === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <select name="sort" class="form-select form-control-sm">
                            <option value="id" <?= $filter_sort === 'id' ? 'selected' : '' ?>>ID (DESC)</option>
                            <option value="created_date" <?= $filter_sort === 'created_date' ? 'selected' : '' ?>>Date</option>
                            <option value="week" <?= $filter_sort === 'week' ? 'selected' : '' ?>>Week</option>
                            <option value="client" <?= $filter_sort === 'client' ? 'selected' : '' ?>>Client</option>
                        </select>
                    </div>
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="error_entry_team.php" class="btn btn-secondary btn-sm me-2">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                        <span class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            <?php if ($entries && $entries->num_rows > 0): ?>
                                <?= $entries->num_rows ?> entries found
                            <?php else: ?>
                                No entries found
                            <?php endif; ?>
                        </span>
                    </div>
                </form>
            </div>
        </div>

        <!-- Import Excel Modal -->
        <div class="modal fade" id="importExcelModal" tabindex="-1" aria-labelledby="importExcelModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form action="error_entry_team.php" method="post" enctype="multipart/form-data">
                        <div class="modal-header">
                            <h5 class="modal-title" id="importExcelModalLabel"><i class="fas fa-file-import me-2"></i>Import Excel File</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="action" value="import">
                            <div class="mb-3">
                                <label for="excel_file" class="form-label">Choose File (.csv, .xlsx, .xls)</label>
                                <input class="form-control" type="file" id="excel_file" name="excel_file" accept=".csv, .xlsx, .xls" required>
                                <div class="form-text mt-2">Select the file to upload (CSV format is highly recommended).</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success"><i class="fas fa-upload me-1"></i> Upload</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Error Logs Table (Exact UI matching Executive Screenshot) -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="errorLogTable">
                        <thead>
                            <tr>
                                <th>S.NO</th>
                                <th>WEEK</th>
                                <th>DATE</th>
                                <th>TEAM</th>
                                <th>DEPARTMENT</th>
                                <th>ERROR TAKEN BY</th>
                                <th>INTERNAL / EXTERNAL</th>
                                <th>CLIENT NAME</th>
                                <th>ARTICLE</th>
                                <th>ERROR TYPE</th>
                                <th>ERROR SUBTYPE</th>
                                <th>Y/N</th>
                                <th>ERROR MADE BY</th>
                                <th>REASON FOR ERRORS</th>
                                <th class="text-center export-remove">PHOTO</th>
                                <th class="text-center export-remove">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($entries && $entries->num_rows > 0): ?>
                                <?php while ($row = $entries->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?= (int)$row['id'] ?></td>
                                    <td><span class="badge bg-primary"><?= h($row['week']) ?></span></td>
                                    <td><small class="text-muted"><?= h(formatDate($row['error_date'])) ?></small></td>
                                    <td><?= !empty($row['team']) ? '<span class="badge bg-secondary">' . h($row['team']) . '</span>' : '—' ?></td>
                                    <td><?= !empty($row['department']) ? '<span class="badge bg-secondary">' . h($row['department']) . '</span>' : '—' ?></td>
                                    <td><?= h($row['error_taken_by'] ?: '—') ?></td>
                                    <td><?= h($row['internal_external'] ?: '—') ?></td>
                                    <td><span class="fw-semibold"><?= h($row['client_name']) ?></span></td>
                                    <td><?= h($row['article'] ?: '—') ?></td>
                                    <td><?= h($row['error_type'] ?: '—') ?></td>
                                    <td>
                                        <?php if (!empty($row['error_subtype'])): ?>
                                            <span class="badge bg-info text-dark"><?= h($row['error_subtype']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['yn'] === 'Yes'): ?>
                                            <span class="badge bg-success">Yes</span>
                                        <?php elseif ($row['yn'] === 'No'): ?>
                                            <span class="badge bg-danger">No</span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($row['error_made_by'] ?: '—') ?></td>
                                    <td><?= h($row['reason'] ?: '—') ?></td>
                                    
                                    <!-- Photo Column -->
                                    <td class="text-center export-remove">
                                        <?php if (!empty($row['error_photo'])): ?>
                                            <a href="uploads/error_photos/<?= h($row['error_photo']) ?>" target="_blank" class="btn btn-sm btn-outline-info" title="View Image">
                                                <i class="fas fa-image"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted"><i class="fas fa-image text-black-50"></i></span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Actions Column -->
                                    <td class="text-center export-remove">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="error_entry_team.php?mode=edit&id=<?= (int)$row['id'] ?>" class="btn btn-warning" title="Edit Entry">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this error log entry?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Entry">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="16" class="text-center text-muted py-4">No error log entries found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            $('.select2').select2({ width: '100%', allowClear: true });
        });

        // Export Table to Excel (SheetJS)
        function exportToExcel() {
            const table = document.getElementById('errorLogTable');
            if (!table) return;
            const exportTable = table.cloneNode(true);
            exportTable.querySelectorAll('.export-remove').forEach(el => el.remove());
            const ws = XLSX.utils.table_to_sheet(exportTable);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Article Errors");
            const date = new Date().toISOString().slice(0, 10);
            XLSX.writeFile(wb, `Article_Errors_${date}.xlsx`);
        }
    </script>
</body>
</html>
