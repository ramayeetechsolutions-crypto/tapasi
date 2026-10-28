<br><br><br>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}
$emp_username = $_SESSION["ename"] ?? '';
$current_user_role = $_SESSION["role_name"] ?? 0;

// // Only allow TL access
// if ($current_user_role !== "TL") {
//     header("Location: access_denied.php");
//     exit;
// }

// Only allow TL and Sub TL access; redirect standard employees to their profile
$allowed_roles = ["TL", "Sub TL"];

if (!in_array($current_user_role, $allowed_roles)) {
    // Redirect non-TL users directly to employee profile page
    header("Location: employee.php");
    exit;
}
// --- AJAX handlers ---
if (isset($_GET['ajax'])) {
    if ($_GET['ajax'] === 'team_leaves') {
        $res = $mysqli->query("SELECT employee_name FROM employee_master WHERE user_role_id=3 AND status='Active' ORDER BY employee_name");
        $names = [];
        while ($r = $res->fetch_assoc()) {
            $names[] = $r['employee_name'];
        }
        header('Content-Type: application/json');
        echo json_encode($names);
        exit;
    }
}

// Helpers
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function redirect_with($params = []) {
    $base = 'employee_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base"); 
    exit;
}

function formatDate($d) {
    return (!empty($d) && $d != '0000-00-00') ? date('d-m-Y', strtotime($d)) : '—';
}

// Image upload function
function uploadImage($file, $field_name, $employee_id) {
    if ($file['error'] !== UPLOAD_ERR_OK) return '';
    
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $max_size = 2 * 1024 * 1024;
    
    if (!in_array($file['type'], $allowed_types)) return '';
    if ($file['size'] > $max_size) return '';
    
    $upload_dir = 'uploads/employee_docs/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = "emp_{$employee_id}_{$field_name}_" . time() . "." . $file_extension;
    $filepath = $upload_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) return $filepath;
    return '';
}

$today = date('Y-m-d');

// --- POST handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['employee_id'] ?? 0);
    
    // Handle TL verification
    if ($action === 'tl_verify' && $id > 0) {
        $check_stmt = $mysqli->prepare("SELECT emp_confirm, tl_confirm FROM employee_master WHERE employee_id = ?");
        $check_stmt->bind_param("i", $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if ($check_result && $check_result['emp_confirm'] == 1 && $check_result['tl_confirm'] == 0) {
            $update_stmt = $mysqli->prepare("UPDATE employee_master SET tl_confirm = 1, verified_by = ?, verified_at = NOW(), status = 'TL Verified' WHERE employee_id = ?");
            $update_stmt->bind_param("si", $emp_username, $id);
            
            if ($update_stmt->execute()) {
                redirect_with(['msg' => "Employee verified successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error verifying employee: ' . $update_stmt->error, 'type' => 'danger']);
            }
            $update_stmt->close();
        } else {
            redirect_with(['msg' => 'Employee must be self-verified first or already TL verified', 'type' => 'danger']);
        }
        exit;
    }
    
    // Handle edit action (non-mandatory fields)
    if ($action === 'edit' && $id > 0) {
        // Check if employee is TL verified - cannot edit if verified
        $check_verify_stmt = $mysqli->prepare("SELECT tl_confirm FROM employee_master WHERE employee_id = ?");
        $check_verify_stmt->bind_param("i", $id);
        $check_verify_stmt->execute();
        $verify_result = $check_verify_stmt->get_result()->fetch_assoc();
        $check_verify_stmt->close();
        
        if ($verify_result && $verify_result['tl_confirm'] == 1) {
            redirect_with(['msg' => 'Cannot edit TL verified employee data', 'type' => 'danger']);
        }
        
        // Collect all fields (non-mandatory)
        $employee_name     = trim($_POST['employee_name'] ?? '');
        $employee_username = trim($_POST['employee_username'] ?? '');
        $email             = trim($_POST['email'] ?? '');
        $phone             = trim($_POST['phone'] ?? '');
        // $date_of_birth     = trim($_POST['date_of_birth'] ?? '');
        $date_of_birth     = !empty(trim($_POST['date_of_birth'] ?? '')) ? trim($_POST['date_of_birth']) : null;
        // $joining_date      = trim($_POST['joining_date'] ?? '');
        $joining_date      = !empty(trim($_POST['joining_date'] ?? '')) ? trim($_POST['joining_date']) : null;
        $pan_no            = trim($_POST['pan_no'] ?? '');
        $employee_name_pan = trim($_POST['employee_name_pan'] ?? '');
        $aadhar_no         = trim($_POST['aadhar_no'] ?? '');
        $esi_name          = trim($_POST['esi_name'] ?? '');
        $esi_no            = trim($_POST['esi_no'] ?? '');
        $epd_name          = trim($_POST['epd_name'] ?? '');
        $uan_no            = trim($_POST['uan_no'] ?? '');
        $member_id         = trim($_POST['member_id'] ?? '');
        $address           = trim($_POST['address'] ?? '');
        $referred_by       = trim($_POST['referred_by'] ?? '');
        $account_no        = trim($_POST['account_no'] ?? '');
        $ifsc_code         = trim($_POST['ifsc_code'] ?? '');
        
        // Team, Designation, Shift
        $team_id        = (int)($_POST['team_id'] ?? 0);
        $designation_id = (int)($_POST['designation_id'] ?? 0);
        $shift          = trim($_POST['shift'] ?? 'General');
        
        // Process multiple selected processes
        if (isset($_POST['process'])) {
            $selected_processes = implode(",", $_POST['process']);  
        } else {
            $selected_processes = "";
        }
        
        // Handle image uploads
        $aadhar_image_path = '';
        $pan_image_path = '';
        $image_updates = [];
        
        if (isset($_FILES['aadhar_image']) && $_FILES['aadhar_image']['error'] === UPLOAD_ERR_OK) {
            $aadhar_image_path = uploadImage($_FILES['aadhar_image'], 'aadhar', $id);
            if (!empty($aadhar_image_path)) {
                $image_updates['aadhar_image_path'] = $aadhar_image_path;
            }
        }
        
        if (isset($_FILES['pan_image']) && $_FILES['pan_image']['error'] === UPLOAD_ERR_OK) {
            $pan_image_path = uploadImage($_FILES['pan_image'], 'pan', $id);
            if (!empty($pan_image_path)) {
                $image_updates['pan_image_path'] = $pan_image_path;
            }
        }
        
        // Build the update query
        $update_fields = [];
        $update_params = [];
        $update_types = "";
        
        // Basic fields
        $update_fields[] = "employee_name = ?";
        $update_params[] = $employee_name;
        $update_types .= "s";
        
        $update_fields[] = "employee_username = ?";
        $update_params[] = $employee_username;
        $update_types .= "s";
        
        $update_fields[] = "email = ?";
        $update_params[] = $email;
        $update_types .= "s";
        
        $update_fields[] = "phone = ?";
        $update_params[] = $phone;
        $update_types .= "s";
        
        $update_fields[] = "date_of_birth = ?";
        $update_params[] = $date_of_birth;
        $update_types .= "s";
        
        $update_fields[] = "joining_date = ?";
        $update_params[] = $joining_date;
        $update_types .= "s";
        
        $update_fields[] = "process = ?";
        $update_params[] = $selected_processes;
        $update_types .= "s";
        
        $update_fields[] = "pan_no = ?";
        $update_params[] = $pan_no;
        $update_types .= "s";
        
        $update_fields[] = "employee_name_pan = ?";
        $update_params[] = $employee_name_pan;
        $update_types .= "s";
        
        $update_fields[] = "aadhar_no = ?";
        $update_params[] = $aadhar_no;
        $update_types .= "s";
        
        $update_fields[] = "esi_name = ?";
        $update_params[] = $esi_name;
        $update_types .= "s";
        
        $update_fields[] = "esi_no = ?";
        $update_params[] = $esi_no;
        $update_types .= "s";
        
        $update_fields[] = "epd_name = ?";
        $update_params[] = $epd_name;
        $update_types .= "s";
        
        $update_fields[] = "uan_no = ?";
        $update_params[] = $uan_no;
        $update_types .= "s";
        
        $update_fields[] = "member_id = ?";
        $update_params[] = $member_id;
        $update_types .= "s";
        
        $update_fields[] = "address = ?";
        $update_params[] = $address;
        $update_types .= "s";
        
        $update_fields[] = "referred_by = ?";
        $update_params[] = $referred_by;
        $update_types .= "s";
        
        $update_fields[] = "account_no = ?";
        $update_params[] = $account_no;
        $update_types .= "s";
        
        $update_fields[] = "ifsc_code = ?";
        $update_params[] = $ifsc_code;
        $update_types .= "s";
        
        $update_fields[] = "team_id = ?";
        $update_params[] = $team_id;
        $update_types .= "i";
        
        $update_fields[] = "designation_id = ?";
        $update_params[] = $designation_id;
        $update_types .= "i";
        
        $update_fields[] = "shift = ?";
        $update_params[] = $shift;
        $update_types .= "s";
        
        // Add image fields if they were uploaded
        foreach ($image_updates as $field => $value) {
            $update_fields[] = "$field = ?";
            $update_params[] = $value;
            $update_types .= "s";
        }
        
        $update_params[] = $id;
        $update_types .= "i";
        
        $sql = "UPDATE employee_master SET " . implode(", ", $update_fields) . " WHERE employee_id = ?";
        
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            redirect_with(['msg' => 'Prepare failed: ' . $mysqli->error, 'type' => 'danger']);
        }
        
        $stmt->bind_param($update_types, ...$update_params);
        
        if ($stmt->execute()) {
            redirect_with(['msg' => "Employee '{$employee_name}' updated successfully", 'type' => 'info']);
        } else {
            redirect_with(['msg' => 'Error updating employee: ' . $stmt->error, 'type' => 'danger']);
        }
        $stmt->close();
        exit;
    }
}

// --- MODE ---
$mode = $_GET['mode'] ?? 'view';
$editRow = null;
if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM employee_master WHERE employee_id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // Check if editing is allowed (not TL verified)
        if ($editRow && $editRow['tl_confirm'] == 1) {
            redirect_with(['msg' => 'Cannot edit TL verified employee data', 'type' => 'danger']);
        }
    } else {
        $mode = 'view';
    }
}

// Filters
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'employee_id';
$dir = strtolower($_GET['dir'] ?? 'asc');
$sortable = ['employee_id','employee_name','employee_username','date_of_birth','joining_date','process','status'];
if (!in_array($sort,$sortable,true)) $sort='employee_id';
$dir = ($dir=='asc')?'asc':'desc';

// TL can only see their team members
$sql = "SELECT 
    e.*,
    r.role_name,
    t.team_name,
    d.designation_name
FROM 
    employee_master e
LEFT JOIN 
    user_role_master r ON e.user_role_id = r.role_id 
LEFT JOIN 
    team_master t ON e.team_id = t.team_id 
LEFT JOIN 
    designation_master d ON e.designation_id = d.designation_id 
WHERE 
    e.reporting_manager = '$emp_username' OR e.employee_username = '$emp_username'";
    
$cond=[];$params=[];$types='';

if ($q) { 
    $cond[] = "(e.employee_name LIKE ? OR e.employee_username LIKE ?)"; 
    $tval="%$q%"; 
    $params=array_merge($params,[$tval,$tval]); 
    $types.='ss'; 
}
if ($fstatus==='Active'||$fstatus==='Inactive'){ 
    $cond[] = "e.status=?"; 
    $params[]=$fstatus; 
    $types.='s'; 
}

if ($cond) {
    $sql .= " AND " . implode(" AND ", $cond);
}

$sql .= " ORDER BY $sort $dir";

if ($types) { 
    $stmt=$mysqli->prepare($sql); 
    $stmt->bind_param($types,...$params); 
    $stmt->execute(); 
    $rows=$stmt->get_result(); 
    $stmt->close(); 
} else { 
    $rows=$mysqli->query($sql); 
}

// Get all data for dropdowns
$teams = $mysqli->query("SELECT team_id, team_name FROM team_master WHERE status='Active' ORDER BY team_name");
$designations = $mysqli->query("SELECT designation_id, designation_name FROM designation_master WHERE status='Active' ORDER BY designation_name");
$processes = $mysqli->query("SELECT process_id, process_name FROM process_master WHERE status='Active' ORDER BY process_id");
$processes1 = $mysqli->query("SELECT process_id, process_name FROM process_master WHERE status='Active' ORDER BY process_id");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Management - Team Lead</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .table thead th { 
            position: sticky; 
            top: 0; 
            z-index: 100; 
            background-color: #5e6b77 !important; 
            color: #ffffff !important; 
            border-bottom: 2px solid #444; /* Keeps the line visible */
        }

        /* Ensures the table doesn't have overflow issues on some screens */
        .table-responsive {
            max-height: 80vh; /* Limits table height so scrolling happens inside the box */
            overflow-y: auto;
        }
        .verification-badge { font-size: 0.75em; }
        .btn-verify { font-size: 0.8rem; padding: 2px 8px; }
        .image-preview { max-width: 80px; max-height: 60px; cursor: pointer; border: 1px solid #ddd; border-radius: 3px; }
        .image-preview:hover { border-color: #007bff; }
        .image-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.9); }
        .modal-content { margin: auto; display: block; width: 80%; max-width: 700px; max-height: 80%; margin-top: 5%; }
        .close-modal { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer; }
        .section-title { font-weight: 600; color: #495057; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 1px solid #dee2e6; }
        .additional-fields-section { background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin-top: 10px; }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="container-fluid mt-3">
        <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show">
            <?= h($_GET['msg']) ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($mode === 'edit'): ?>
        
        <!-- Edit Form -->
        <div class="d-flex justify-content-between mb-3">
            <h5><i class="fas fa-user-edit me-2"></i>Edit Employee: <?= h($editRow['employee_name'] ?? '') ?></h5>
            <a href="employee_master.php" class="btn btn-sm btn-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card p-4 mx-auto" style="max-width:1200px;">
            <form method="post" class="row g-3" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="employee_id" value="<?= $editRow['employee_id'] ?>">

                <!-- Basic Information -->
                <div class="col-md-6">
                    <label class="form-label">Employee ID</label>
                    <input type="text" name="employee_username" class="form-control" value="<?= h($editRow['employee_username'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Employee Name</label>
                    <input type="text" name="employee_name" class="form-control" value="<?= h($editRow['employee_name'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= h($editRow['email'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= h($editRow['phone'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="date_of_birth" class="form-control" value="<?= h($editRow['date_of_birth'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Joining Date</label>
                    <input type="date" name="joining_date" class="form-control" value="<?= h($editRow['joining_date'] ?? $today) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Team</label>
                    <select name="team_id" class="form-select">
                        <option value="">Select Team</option>
                        <?php if ($teams): 
                            $teams->data_seek(0); 
                            while($r=$teams->fetch_assoc()): 
                                $selected = ((int)($editRow['team_id'] ?? 0) === (int)$r['team_id']) ? 'selected' : ''; 
                        ?>
                            <option value="<?= $r['team_id'] ?>" <?= $selected ?>><?= h($r['team_name']) ?></option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Designation</label>
                    <select name="designation_id" class="form-select">
                        <option value="">Select Designation</option>
                        <?php if ($designations): 
                            $designations->data_seek(0); 
                            while($r=$designations->fetch_assoc()): 
                                $selected = ((int)($editRow['designation_id'] ?? 0) === (int)$r['designation_id']) ? 'selected' : ''; 
                        ?>
                            <option value="<?= $r['designation_id'] ?>" <?= $selected ?>><?= h($r['designation_name']) ?></option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Shift</label>
                    <select name="shift" class="form-select">
                        <option value="General" <?= ($editRow['shift'] ?? 'General') === 'General' ? 'selected' : '' ?>>General</option>
                        <option value="WFH" <?= ($editRow['shift'] ?? '') === 'WFH' ? 'selected' : '' ?>>WFH</option>
                    </select>
                </div>

                
               <!-- Processes Section -->
                <div class="col-12">
                    <div class="section-title">Process Assignment</div>
                    <div class="row">
                        <?php
                        // Fetch all processes
                        $processes = $mysqli->query("SELECT process_id, process_name FROM process_master WHERE status='Active' ORDER BY process_id");
                        $saved = [];
                        if (!empty($editRow['process'])) {
                            $saved = array_map('intval', explode(",", $editRow['process'])); 
                        }

                        // Buckets
                        $ce = $pag = $others = [];
                        while ($row = $processes->fetch_assoc()) {
                            $name = $row['process_name'];
                            if ($name === "CE" || str_ends_with($name, "-CE") || $name === "LE" || $name === "Sub TL") {
                                $ce[] = $row;
                            } elseif ($name === "PAG" || str_ends_with($name, "-PAG") || $name === "QC") {
                                $pag[] = $row;
                            } else {
                                $others[] = $row;
                            }
                        }

                        // Helper to render group
                        function renderGroup($groupName, $items, $saved) {
                            echo "<div class='mb-2'><h6 class='mb-1'><b>$groupName</b></h6>";
                            echo "<div class='row'>";
                            foreach ($items as $index => $row) {
                                $process_id = (int)$row['process_id'];
                                $checked = in_array($process_id, $saved, true) ? 'checked' : '';
                                echo '<div class="col-md-3 mb-2">
                                        <div class="form-check form-switch">
                                            <input type="checkbox" class="form-check-input" 
                                                   name="process[]" 
                                                   value="'.$row['process_id'].'" '.$checked.' 
                                                   id="process_'.$row['process_id'].'"
                                                   role="switch">
                                            <label class="form-check-label" for="process_'.$row['process_id'].'">
                                                '.htmlspecialchars($row['process_name']).'
                                            </label>
                                        </div>
                                      </div>';
                                if (($index + 1) % 4 == 0 && $index + 1 < count($items)) {
                                    echo '</div><div class="row">';
                                }
                            }
                            echo "</div></div><hr class='my-1'>";
                        }

                        renderGroup("CE Processes", $ce, $saved);
                        renderGroup("PAG Processes", $pag, $saved);
                        renderGroup("XML Processes", $others, $saved);
                        ?>
                    </div>
                </div>

                <div class="col-12 mt-3 d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Update Employee
                    </button>
                    <a href="employee_master.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between mb-3">
            <h5><i class="fas fa-users me-2"></i>Employee Profile</h5>
        </div>
<div class="d-flex justify-content-between mb-3"><a href="employee_add.php?mode=add" class="btn btn-success btn-sm">+ Add </a></div>
        <!-- Search and Filter -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search by name or ID..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="Active" <?= $fstatus==='Active'?'selected':'' ?>>Active</option>
                            <option value="Inactive" <?= $fstatus==='Inactive'?'selected':'' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="sort" class="form-select form-select-sm">
                            <?php foreach($sortable as $col): 
                                $sel = $col==$sort ? 'selected' : ''; 
                                $display = ucwords(str_replace('_',' ',$col)); 
                            ?>
                                <option value="<?= $col ?>" <?= $sel ?>><?= $display ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- <div class="col-md-2">
                        <select name="dir" class="form-select form-select-sm">
                            <option value="asc" <?= $dir=='asc'?'selected':'' ?>>Asc</option>
                            <option value="desc" <?= $dir=='desc'?'selected':'' ?>>Desc</option>
                        </select>
                    </div> -->
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="employee_master.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Employees Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="50">S. No.
                                    <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'employee_id', 'dir' => $sort == 'employee_id' && $dir == 'asc' ? 'desc' : 'asc'])) ?>" class="text-white">
                                        <i class="fas fa-sort<?= $sort == 'employee_id' ? ($dir == 'asc' ? '-up' : '-down') : '' ?>"></i>
                                    </a>
                                </th>
                                <th>ID</th>
                                <th>Name</th>
                                <th>DOB</th>
                                <th>DOJ</th>
                                <th>Team</th>
                                <th>Designation</th>
                                <th>Process</th>
                                <th width="80">Emp Verified</th>
                                <th width="100">TL Verified</th>
                                <th width="100">Status</th>
                                <th width="80">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows && $rows->num_rows > 0): ?>
                                <?php $serial_number = 1; ?>
                                <?php while($r = $rows->fetch_assoc()): 
                                    $process_name = "";
                                    $process_ids = !empty($r['process']) ? explode(",", $r['process']) : [];
                                    if (!empty($process_ids)) {
                                        $names = [];
                                        foreach ($process_ids as $pid) {
                                            $pid = (int)$pid;
                                            if ($pid > 0) {
                                                $stmt_p = $mysqli->prepare("SELECT process_name FROM process_master WHERE process_id = ?");
                                                $stmt_p->bind_param("i", $pid);
                                                $stmt_p->execute();
                                                $p = $stmt_p->get_result()->fetch_assoc();
                                                $stmt_p->close();
                                                
                                                if ($p) $names[] = $p['process_name'];
                                            }
                                        }
                                        $process_name = implode(", ", $names);
                                    }
                                ?>
                                <tr>
                                    <td class="fw-bold"><?= $serial_number ?></td>
                                    <td><?= h($r['employee_username']) ?></td>
                                    <td><?= h($r['employee_name']) ?></td>
                                    <td><?= formatDate($r['date_of_birth']) ?></td>
                                    <td><?= formatDate($r['joining_date']) ?></td>
                                    <td><?= h($r['team_name'] ?: '—') ?></td>
                                    <td><?= h($r['designation_name'] ?: '—') ?></td>
                                    <td><?= h($process_name) ?: '—' ?></td>
                                    
                                    <!-- Employee Verification Status -->
                                    <td class="text-center">
                                        <?php if ($r['emp_confirm'] == 1): ?>
                                            <span class="badge bg-success verification-badge">Yes</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary verification-badge">No</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- TL Verification Status -->
                                    <td class="text-center">
                                        <?php if ($r['tl_confirm'] == 1): ?>
                                            <span class="badge bg-success verification-badge">Yes</span>
                                            <?php if ($r['verified_by']): ?>
                                                <small class="d-block text-muted">By: <?= h($r['verified_by']) ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-secondary verification-badge">No</span>
                                            <?php if ($r['emp_confirm'] == 1): ?>
                                                <form method="post" style="display:inline">
                                                    <input type="hidden" name="action" value="tl_verify">
                                                    <input type="hidden" name="employee_id" value="<?= $r['employee_id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-warning btn-verify" 
                                                            onclick="return confirm('Confirm TL verification for <?= h($r['employee_name']) ?>? This will lock the employee data.')">
                                                        <i class="fas fa-check-circle me-1"></i>Verify
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <small class="d-block text-muted">Waiting for emp verification</small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Status -->
                                    <td>
                                        <?php if ($r['tl_confirm'] == 1): ?>
                                            <span class="badge bg-info">TL Verified</span>
                                        <?php else: ?>
                                            <span class="badge <?= $r['status']=='Active'?'bg-success':'bg-secondary' ?>">
                                                <?= h($r['status']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Actions -->
                                    <td class="text-center">
                                        <?php //if ($r['tl_confirm'] == 0): ?>
                                            <a href="employee_master.php?mode=edit&id=<?= $r['employee_id'] ?>" 
                                               class="btn btn-sm btn-warning" title="Edit Employee">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        <?php //else: ?>
                                            <!--span class="text-muted" title="Cannot edit TL verified data">
                                                <i class="fas fa-lock"></i>
                                            </span-->
                                        <?php //endif; ?>
                                    </td>
                                </tr>
                                <?php $serial_number++; endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="14" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-users fa-3x mb-3"></i>
                                            <h5>No team members found</h5>
                                            <p class="mb-0">No employees are assigned to your team.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php endif; ?>

        <!-- Image Modal -->
        <div id="imageModal" class="image-modal">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <img class="modal-content" id="modalImage">
        </div>
    </div>

    <script>
    // Image viewing functions
    function viewImage(src) {
        const modal = document.getElementById('imageModal');
        const modalImg = document.getElementById('modalImage');
        modal.style.display = "block";
        modalImg.src = src;
    }

    function closeModal() {
        document.getElementById('imageModal').style.display = "none";
    }

    // Close modal when clicking outside the image
    window.onclick = function(event) {
        const modal = document.getElementById('imageModal');
        if (event.target === modal) {
            closeModal();
        }
    }
    
    // Auto-dismiss alerts after 5 seconds
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>