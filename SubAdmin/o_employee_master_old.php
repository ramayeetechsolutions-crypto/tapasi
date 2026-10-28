<br><br><br>
<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$emp_username=$_SESSION["ename"];
// --- AJAX handlers MUST be at the TOP ---
if (isset($_GET['ajax'])) {
    if ($_GET['ajax'] === 'team_leads') {
        // Fetch Team Leads (Role ID 3 = TL)
        $res = $mysqli->query("SELECT employee_name FROM employee_master WHERE user_role_id=3 AND status='Active' ORDER BY employee_name");
        $names = [];
        while ($r = $res->fetch_assoc()) {
            $names[] = $r['employee_name'];
        }
        header('Content-Type: application/json');
        echo json_encode($names);
        exit;
    }
    
    if ($_GET['ajax'] === 'admins') {
        // Fetch Admins (Role ID 8 = Admin)
        $res = $mysqli->query("SELECT employee_name FROM employee_master WHERE user_role_id=8 AND status='Active' ORDER BY employee_name");
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
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect_with($params = []) {
    $base = 'employee_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base"); exit;
}
function is_valid_date($d) {
    if (!$d) return false;
    $t = strtotime($d);
    if ($t === false) return false;
    return date('Y-m-d', $t) === $d;
}
function formatDate($d) {
    return (!empty($d) && $d != '0000-00-00') ? date('d-m-Y', strtotime($d)) : '—';
}

// Image upload function
function uploadImage($file, $field_name, $employee_id) {
    if ($file['error'] !== UPLOAD_ERR_OK) return '';
    
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $max_size = 2 * 1024 * 1024; // 2MB
    
    if (!in_array($file['type'], $allowed_types)) {
        return false; // Invalid file type
    }
    
    if ($file['size'] > $max_size) {
        return false; // File too large
    }
    
    $upload_dir = '../Team_Lead/uploads/employee_docs/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = "emp_{$employee_id}_{$field_name}_" . time() . "." . $file_extension;
    $filepath = $upload_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return $filepath;
    }
    
    return '';
}

$today = date('Y-m-d');
$default_dob = date('Y-m-d', strtotime('-20 years'));

// --- POST handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['employee_id'] ?? 0);
    $employee_name     = trim($_POST['employee_name'] ?? '');
    $employee_username = trim($_POST['employee_username'] ?? '');
    $password          = $_POST['password'] ?? '';
    $confirm_password  = $_POST['confirm_password'] ?? '';
    $email             = trim($_POST['email'] ?? '');
    $phone             = trim($_POST['phone'] ?? '');
    $date_of_birth     = trim($_POST['date_of_birth'] ?? '');
    $joining_date      = trim($_POST['joining_date'] ?? '') ?: $today;
    
    // New additional fields
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
    
    // New fields: Team, Designation, Shift
    $team_id           = (int)($_POST['team_id'] ?? 0);
    $designation_id    = (int)($_POST['designation_id'] ?? 0);
    $shift             = trim($_POST['shift'] ?? 'General');
    
    // Process multiple selected processes
    if (isset($_POST['process'])) {
        $selected_processes = implode(",", $_POST['process']);  
    } else {
        $selected_processes = "";
    }
    
    $reporting_manager = trim($_POST['reporting_manager'] ?? '');
    $user_role_id      = isset($_POST['user_role_id']) ? (int)$_POST['user_role_id'] : 0;

    $errors = [];
    if ($action !== 'delete' && $action !== 'toggle') {
        if (!$employee_name) $errors[] = 'Employee Name is required.';
        if (!$employee_username) $errors[] = 'Username is required.';
        if (!is_valid_date($date_of_birth)) $errors[] = 'Valid Date of Birth is required.';
        if (!is_valid_date($joining_date)) $errors[] = 'Valid Joining Date is required.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid Email is required.';
        if (!$phone || !preg_match('/^\+?\d{10,15}$/', $phone)) $errors[] = 'Phone Number is required.';
        if ($user_role_id <= 0) $errors[] = 'User Role is required.';
        if ($team_id <= 0) $errors[] = 'Team is required.';
        if ($designation_id <= 0) $errors[] = 'Designation is required.';
        
        // PAN validation (if provided)
        if ($pan_no && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pan_no)) {
            $errors[] = 'Invalid PAN Number format.';
        }
        
        // Aadhar validation (if provided)
        if ($aadhar_no && !preg_match('/^\d{12}$/', $aadhar_no)) {
            $errors[] = 'Aadhar Number must be 12 digits.';
        }
        
        // FIX: Handle Reporting Manager validation with correct role IDs
        if ($user_role_id == 2 || $user_role_id == 8) {
            $reporting_manager = 'Super Admin';
        } elseif (in_array($user_role_id, [3, 4]) && !$reporting_manager) {
            $errors[] = 'Reporting Manager is required.';
        } 
        
        if (($action === 'add' || ($action === 'edit' && $password !== ''))) {
            if (strlen($password) < 6) $errors[] = 'Password (min 6 chars) is required.';
            if ($password !== $confirm_password) $errors[] = 'Passwords do not match.';
        }
    }

    if ($employee_username) {
        $sql = "SELECT employee_id FROM employee_master WHERE employee_username = ?";
        $params = [$employee_username]; $types = 's';
        if ($action === 'edit') { $sql .= " AND employee_id != ?"; $params[] = $id; $types .= 'i'; }
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows > 0) { $errors[] = 'Username already exists.'; }
        $stmt->close();
    }

    if ($errors) redirect_with(['msg' => implode(' ', $errors), 'type' => 'danger']);

    // Handle image uploads
    $aadhar_image_path = '';
    $pan_image_path = '';

    if ($action === 'add') {
        // Insert new employee
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        // First insert without images to get employee_id
        $stmt = $mysqli->prepare("INSERT INTO employee_master 
            (employee_name, employee_username, password_hash, email, phone, date_of_birth, joining_date, process, reporting_manager, user_role_id, status,
            pan_no, employee_name_pan, aadhar_no, esi_name, esi_no, epd_name, uan_no, member_id, address, referred_by, team_id, designation_id, shift) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if (!$stmt) {
            redirect_with(['msg' => 'Prepare failed: ' . $mysqli->error, 'type' => 'danger']);
        }

        $stmt->bind_param(
            "sssssssssissssssssssiiss", 
            $employee_name, 
            $employee_username, 
            $password_hash, 
            $email, 
            $phone, 
            $date_of_birth, 
            $joining_date, 
            $selected_processes,   
            $reporting_manager, 
            $user_role_id,
            $pan_no,
            $employee_name_pan,
            $aadhar_no,
            $esi_name,
            $esi_no,
            $epd_name,
            $uan_no,
            $member_id,
            $address,
            $referred_by,
            $team_id,
            $designation_id,
            $shift
        );

        if ($stmt->execute()) {
            $id = $mysqli->insert_id;
            
            // Now upload images with the actual employee_id
            $image_updates = [];
            
            if (isset($_FILES['aadhar_image']) && $_FILES['aadhar_image']['error'] === UPLOAD_ERR_OK) {
                $aadhar_image_path = uploadImage($_FILES['aadhar_image'], 'aadhar', $id);
                if ($aadhar_image_path === false) {
                    $errors[] = 'Invalid Aadhar image file. Only JPG, PNG, GIF allowed (max 2MB).';
                } elseif (!empty($aadhar_image_path)) {
                    $image_updates['aadhar_image_path'] = $aadhar_image_path;
                }
            }
            
            if (isset($_FILES['pan_image']) && $_FILES['pan_image']['error'] === UPLOAD_ERR_OK) {
                $pan_image_path = uploadImage($_FILES['pan_image'], 'pan', $id);
                if ($pan_image_path === false) {
                    $errors[] = 'Invalid PAN image file. Only JPG, PNG, GIF allowed (max 2MB).';
                } elseif (!empty($pan_image_path)) {
                    $image_updates['pan_image_path'] = $pan_image_path;
                }
            }
            
            // Update with image paths if any images were uploaded
            if (!empty($image_updates)) {
                $update_sql = "UPDATE employee_master SET ";
                $update_params = [];
                $update_types = "";
                
                foreach ($image_updates as $field => $value) {
                    $update_sql .= "$field = ?, ";
                    $update_params[] = $value;
                    $update_types .= "s";
                }
                
                $update_sql = rtrim($update_sql, ", ") . " WHERE employee_id = ?";
                $update_params[] = $id;
                $update_types .= "i";
                
                $update_stmt = $mysqli->prepare($update_sql);
                $update_stmt->bind_param($update_types, ...$update_params);
                $update_stmt->execute();
                $update_stmt->close();
            }
            
        } else {
            redirect_with(['msg' => 'Error adding employee: ' . $stmt->error, 'type' => 'danger']);
        }
        $stmt->close();
        
        if ($errors) redirect_with(['msg' => implode(' ', $errors), 'type' => 'danger']);
        redirect_with(['msg' => "Employee '{$employee_name}' added successfully", 'type' => 'success']);
        
    } elseif ($action === 'edit' && $id > 0) {
        // For edit, we have the ID
        $image_updates = [];
        
        if (isset($_FILES['aadhar_image']) && $_FILES['aadhar_image']['error'] === UPLOAD_ERR_OK) {
            $aadhar_image_path = uploadImage($_FILES['aadhar_image'], 'aadhar', $id);
            if ($aadhar_image_path === false) {
                $errors[] = 'Invalid Aadhar image file. Only JPG, PNG, GIF allowed (max 2MB).';
            } elseif (!empty($aadhar_image_path)) {
                $image_updates['aadhar_image_path'] = $aadhar_image_path;
            }
        }
        
        if (isset($_FILES['pan_image']) && $_FILES['pan_image']['error'] === UPLOAD_ERR_OK) {
            $pan_image_path = uploadImage($_FILES['pan_image'], 'pan', $id);
            if ($pan_image_path === false) {
                $errors[] = 'Invalid PAN image file. Only JPG, PNG, GIF allowed (max 2MB).';
            } elseif (!empty($pan_image_path)) {
                $image_updates['pan_image_path'] = $pan_image_path;
            }
        }
        
        if ($errors) redirect_with(['msg' => implode(' ', $errors), 'type' => 'danger']);
        
        // Build the update query dynamically based on whether password is being updated
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
        
        if ($password) {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $update_fields[] = "password_hash = ?";
            $update_params[] = $password_hash;
            $update_types .= "s";
        }
        
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
        
        $update_fields[] = "reporting_manager = ?";
        $update_params[] = $reporting_manager;
        $update_types .= "s";
        
        $update_fields[] = "user_role_id = ?";
        $update_params[] = $user_role_id;
        $update_types .= "i";
        
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
        
    } elseif ($action === 'delete' && $id > 0) {
        $stmt = $mysqli->prepare("DELETE FROM employee_master WHERE employee_id=?");
        $stmt->bind_param("i", $id); 
        if ($stmt->execute()) {
            redirect_with(['msg' => "Employee deleted successfully", 'type' => 'warning']);
        } else {
            redirect_with(['msg' => 'Error deleting employee: ' . $stmt->error, 'type' => 'danger']);
        }
        $stmt->close();
    } elseif ($action === 'toggle' && $id > 0) {
        $stmt = $mysqli->prepare("SELECT status FROM employee_master WHERE employee_id=?");
        $stmt->bind_param("i", $id); 
        $stmt->execute(); 
        $res = $stmt->get_result()->fetch_assoc(); 
        $stmt->close();
        $new_status = ($res['status'] == 'Active') ? 'Inactive' : 'Active';
        $stmt = $mysqli->prepare("UPDATE employee_master SET status=? WHERE employee_id=?");
        $stmt->bind_param("si", $new_status, $id); 
        if ($stmt->execute()) {
            redirect_with(['msg' => "Status changed to {$new_status}", 'type' => 'info']);
        } else {
            redirect_with(['msg' => 'Error updating status: ' . $stmt->error, 'type' => 'danger']);
        }
        $stmt->close();
    } else {
        redirect_with();
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
    } else {
        $mode = 'view';
    }
}

// Filters
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'employee_id';
$dir = strtolower($_GET['dir'] ?? 'desc');
$sortable = ['employee_id','employee_name','employee_username','email','phone','date_of_birth','joining_date','process','reporting_manager','user_role_id','status'];
if (!in_array($sort,$sortable,true)) $sort='employee_id';
$dir = ($dir=='asc')?'asc':'desc';

$sql = "SELECT e.*, r.role_name, t.team_name, d.designation_name 
        FROM employee_master e 
        LEFT JOIN user_role_master r ON e.user_role_id = r.role_id
        LEFT JOIN team_master t ON e.team_id = t.team_id
        LEFT JOIN designation_master d ON e.designation_id = d.designation_id
        ";
    //    echo "emp". $emp_username;
$cond=[];$params=[];$types='';
// Add reporting manager condition
/*$cond[]="e.reporting_manager=?"; 
$params[]=$emp_username; 
$types.='s';*/
if ($q) { 
    $cond[]="(e.employee_name LIKE ? OR e.employee_username LIKE ? OR e.email LIKE ?)"; 
    $tval="%$q%"; 
    $params=array_merge($params,[$tval,$tval,$tval]); 
    $types.='sss'; 
}
if ($fstatus==='Active'||$fstatus==='Inactive'){ 
    $cond[]="e.status=?"; 
    $params[]=$fstatus; 
    $types.='s'; 
}
if ($cond) $sql.=" WHERE ".implode(" AND ",$cond);
$sql.=" ORDER BY $sort $dir";

if ($types) { 
    $stmt=$mysqli->prepare($sql); 
    $stmt->bind_param($types,...$params); 
    $stmt->execute(); 
    $rows=$stmt->get_result(); 
    $stmt->close(); 
} else { 
    $rows=$mysqli->query($sql); 
}

$roles=$mysqli->query("SELECT role_id, role_name FROM user_role_master WHERE role_name!='Super Admin' ORDER BY role_name");
$processes=$mysqli->query("SELECT process_id, process_name FROM process_master WHERE status='Active' ORDER BY process_id");
$processes1=$mysqli->query("SELECT process_id, process_name FROM process_master WHERE status='Active' ORDER BY process_id");
$teams=$mysqli->query("SELECT team_id, team_name FROM team_master WHERE status='Active' ORDER BY team_name");
$designations=$mysqli->query("SELECT designation_id, designation_name FROM designation_master WHERE status='Active' ORDER BY designation_name");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .btn-brown{background:#795548;color:#fff;border:none}.btn-brown:hover{background:#5d4037;color:#fff}

        /* Reduce vertical space */
        .row.mb-1 { margin-bottom: 4px !important; }
        h6.mb-1 { margin-bottom: 4px !important; margin-top: 4px !important; }
        hr.my-1 { margin: 4px 0 !important; }
        .form-check-label { display: inline-block; margin-bottom: 2px; }
        .col-md-3.mb-1 { margin-bottom: 4px !important; }
        .form-check-label { margin-right: 8px; }

        /* Additional fields styling */
        .additional-fields-section { background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin-top: 10px; }
        .section-title { font-weight: 600; color: #495057; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 1px solid #dee2e6; }

        /* Image preview styling */
        .image-preview { max-width: 100px; max-height: 100px; cursor: pointer; border: 1px solid #ddd; border-radius: 4px; }
        .image-preview:hover { border-color: #007bff; }

        /* Modal for image viewing */
        .image-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.9); }
        .modal-content { margin: auto; display: block; width: 80%; max-width: 700px; max-height: 80%; margin-top: 5%; }
        .close-modal { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer; }
        .close-modal:hover { color: #bbb; }
        
        .table th { background-color: #2c3e50; color: white; }
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

        <?php if ($mode==='add'||$mode==='edit'): ?>
        
        <!-- Add/Edit Form -->
        <div class="d-flex justify-content-between mb-3">
            <h5><i class="fas fa-user me-2"></i><?= $mode==='edit'?'Edit':'Add' ?> Employee</h5>
            <a href="employee_master.php" class="btn btn-sm btn-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card p-4 mx-auto" style="max-width:1200px;">
            <form method="post" class="row g-3" onsubmit="return checkPasswordMatch()" enctype="multipart/form-data">
                <input type="hidden" name="action" value="<?= $mode==='edit'?'edit':'add' ?>">
                <?php if ($mode==='edit'): ?>
                <input type="hidden" name="employee_id" value="<?= $editRow['employee_id'] ?>">
                <?php endif; ?>

                <!-- Basic Information -->
                <div class="col-md-6">
                    <label class="form-label">Employee Name *</label>
                    <input type="text" name="employee_name" class="form-control" required value="<?= h($editRow['employee_name'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Username *</label>
                    <input type="text" name="employee_username" class="form-control" required value="<?= h($editRow['employee_username'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Password <?= $mode==='edit'?'(leave blank to keep unchanged)':'*' ?></label>
                    <div class="input-group">
                        <input type="password" id="password" name="password" class="form-control" <?= $mode==='add'?'required minlength="6"':'' ?>>
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password')">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Confirm Password <?= $mode==='edit'?'(only if changing password)':'*' ?></label>
                    <div class="input-group">
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" <?= $mode==='add'?'required minlength="6"':'' ?>>
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Email *</label>
                    <input type="email" name="email" class="form-control" required value="<?= h($editRow['email'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Phone *</label>
                    <input type="text" name="phone" pattern="\+?\d{10,15}" class="form-control" required value="<?= h($editRow['phone'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Date of Birth *</label>
                    <input type="date" name="date_of_birth" class="form-control" required value="<?= h($editRow['date_of_birth'] ?? $default_dob) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Joining Date *</label>
                    <input type="date" name="joining_date" class="form-control" required value="<?= h($editRow['joining_date'] ?? $today) ?>">
                </div>

                <!-- New Fields: Team, Designation, Shift -->
                <div class="col-md-4">
                    <label class="form-label">Team *</label>
                    <select name="team_id" class="form-select" required>
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
                    <label class="form-label">Designation *</label>
                    <select name="designation_id" class="form-select" required>
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
                    <label class="form-label">Shift *</label>
                    <select name="shift" class="form-select" required>
                        <option value="General" <?= ($editRow['shift'] ?? 'General') === 'General' ? 'selected' : '' ?>>General</option>
                        <option value="WFH" <?= ($editRow['shift'] ?? '') === 'WFH' ? 'selected' : '' ?>>WFH</option>
                    </select>
                </div>

                <!-- Document Images Section -->
                <div class="col-12 additional-fields-section">
                    <div class="section-title">Document Images</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Aadhar Card Image</label>
                            <input type="file" name="aadhar_image" class="form-control" accept="image/jpeg,image/jpg,image/png,image/gif">
                            <small class="text-muted">Max 2MB, JPG, PNG, GIF</small>
                            <?php if ($mode==='edit' && !empty($editRow['aadhar_image_path'])): ?>
                                <div class="mt-2">
                                    <img src="<?= h($editRow['aadhar_image_path']) ?>" alt="Aadhar Card" class="image-preview" onclick="viewImage('<?= h($editRow['aadhar_image_path']) ?>')">
                                    <small class="text-muted d-block">Current Aadhar Image</small>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">PAN Card Image</label>
                            <input type="file" name="pan_image" class="form-control" accept="image/jpeg,image/jpg,image/png,image/gif">
                            <small class="text-muted">Max 2MB, JPG, PNG, GIF</small>
                            <?php if ($mode==='edit' && !empty($editRow['pan_image_path'])): ?>
                                <div class="mt-2">
                                    <img src="<?= h($editRow['pan_image_path']) ?>" alt="PAN Card" class="image-preview" onclick="viewImage('<?= h($editRow['pan_image_path']) ?>')">
                                    <small class="text-muted d-block">Current PAN Image</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Additional Information Section -->
                <div class="col-12 additional-fields-section">
                    <div class="section-title">Additional Information</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">PAN No</label>
                            <input type="text" name="pan_no" class="form-control" pattern="[A-Z]{5}[0-9]{4}[A-Z]{1}" 
                                   placeholder="e.g., ABCDE1234F" value="<?= h($editRow['pan_no'] ?? '') ?>">
                            <small class="text-muted">Format: ABCDE1234F</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Employee Name (in PAN)</label>
                            <input type="text" name="employee_name_pan" class="form-control" 
                                   value="<?= h($editRow['employee_name_pan'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Aadhar No</label>
                            <input type="text" name="aadhar_no" class="form-control" pattern="\d{12}" 
                                   placeholder="12-digit number" value="<?= h($editRow['aadhar_no'] ?? '') ?>">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">ESI Name</label>
                            <input type="text" name="esi_name" class="form-control" 
                                   value="<?= h($editRow['esi_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ESI No</label>
                            <input type="text" name="esi_no" class="form-control" 
                                   value="<?= h($editRow['esi_no'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">EPD Name</label>
                            <input type="text" name="epd_name" class="form-control" 
                                   value="<?= h($editRow['epd_name'] ?? '') ?>">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">UAN No</label>
                            <input type="text" name="uan_no" class="form-control" 
                                   value="<?= h($editRow['uan_no'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Member ID</label>
                            <input type="text" name="member_id" class="form-control" 
                                   value="<?= h($editRow['member_id'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Referred By</label>
                            <input type="text" name="referred_by" class="form-control" 
                                   value="<?= h($editRow['referred_by'] ?? '') ?>">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="3"><?= h($editRow['address'] ?? '') ?></textarea>
                        </div>
                    </div>
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

                <div class="col-md-6">
                    <label class="form-label">User Role *</label>
                    <select name="user_role_id" id="user_role_id" class="form-select" required>
                        <option value="">Select Role</option>
                        <?php if ($roles): 
                            $roles->data_seek(0); 
                            while($r=$roles->fetch_assoc()): 
                                $selected = ((int)($editRow['user_role_id'] ?? 0) === (int)$r['role_id']) ? 'selected' : ''; 
                        ?>
                            <option value="<?= $r['role_id'] ?>" <?= $selected ?>><?= h($r['role_name']) ?></option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>

                <div class="col-md-6" id="rmContainer"></div>

                <div class="col-12 mt-3 d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-brown">
                        <i class="fas fa-save me-1"></i>
                        <?= $mode==='edit'?'Update':'Save' ?> Employee
                    </button>
                    <a href="employee_master.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <!-- Image Modal -->
        <div id="imageModal" class="image-modal">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <img class="modal-content" id="modalImage">
        </div>

        <script>
        function togglePassword(id){
            const input=document.getElementById(id);
            input.type=input.type==="password"?"text":"password";
        }

        function checkPasswordMatch(){
            const pass=document.getElementById('password').value;
            const conf=document.getElementById('confirm_password').value;
            if(pass!==conf){
                alert("Passwords do not match!");
                return false;
            }
            return true;
        }

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

        document.addEventListener('DOMContentLoaded', () => {
            const roleSelect = document.getElementById('user_role_id');
            const rmContainer = document.getElementById('rmContainer');
            const selectedManager = `<?= h($editRow['reporting_manager'] ?? '') ?>`;

            function updateReportingManager() {
                const roleId = parseInt(roleSelect.value);
                let html = '';

                if (roleId === 2) {
                    html = `<label class="form-label">Reporting Manager *</label>
                            <input type="text" class="form-control" value="Super Admin" readonly>
                            <input type="hidden" name="reporting_manager" value="Super Admin">`;
                    rmContainer.innerHTML = html;
                    
                } else if (roleId === 4) {
                    loadManagers('team_leads', 'Team Lead');
                    
                } else if (roleId === 3) {
                    loadManagers('admins', 'Admin');
                    
                } else if (roleId === 8) {
                    html = `<label class="form-label">Reporting Manager *</label>
                            <input type="text" class="form-control" value="Super Admin" readonly>
                            <input type="hidden" name="reporting_manager" value="Super Admin">`;
                    rmContainer.innerHTML = html;
                        
                } else {
                    html = `<label class="form-label">Reporting Manager *</label>
                            <input type="text" name="reporting_manager" class="form-control" 
                                   value="${selectedManager}" required>`;
                    rmContainer.innerHTML = html;
                }
            }

            function loadManagers(ajaxType, managerType) {
                let html = `<label class="form-label">Reporting Manager *</label>
                           <select name="reporting_manager" class="form-select" required>
                               <option value="">Loading ${managerType}s...</option>
                           </select>`;
                rmContainer.innerHTML = html;

                fetch(`employee_master.php?ajax=${ajaxType}`)
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok');
                        return response.json();
                    })
                    .then(data => {
                        let options = `<option value="">Select ${managerType}</option>`;
                        if (data && data.length > 0) {
                            data.forEach(name => {
                                const selected = name === selectedManager ? 'selected' : '';
                                options += `<option value="${name}" ${selected}>${name}</option>`;
                            });
                        } else {
                            options = `<option value="">No ${managerType}s available</option>`;
                        }
                        
                        const select = rmContainer.querySelector('select[name="reporting_manager"]');
                        if (select) select.innerHTML = options;
                    })
                    .catch(error => {
                        console.error('Error loading managers:', error);
                        html = `<label class="form-label">Reporting Manager *</label>
                               <input type="text" name="reporting_manager" class="form-control" 
                                      value="${selectedManager}" placeholder="Enter ${managerType} name" required>
                               <small class="text-muted">Could not load ${managerType} list</small>`;
                        rmContainer.innerHTML = html;
                    });
            }

            roleSelect.addEventListener('change', updateReportingManager);
            updateReportingManager();
        });
        </script>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between mb-3">
            <h5><i class="fas fa-users me-2"></i>Employee Master</h5>
            <!--a href="employee_master.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add Employee
            </a-->
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search employees..." value="<?= h($q) ?>">
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
                    <div class="col-md-2">
                        <select name="dir" class="form-select form-select-sm">
                            <option value="asc" <?= $dir=='asc'?'selected':'' ?>>Asc</option>
                            <option value="desc" <?= $dir=='desc'?'selected':'' ?>>Desc</option>
                        </select>
                    </div>
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
                                <th width="50">ID</th>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>DOB</th>
                                <th>Joining</th>
                                <th>Team</th>
                                <th>Designation</th>
                                <th>Shift</th>
                                <th>PAN No</th>
                                <th>Aadhar No</th>
                                <th>Aadhar Image</th>
                                <th>PAN Image</th>
                                <th>UAN No</th>
                                <th>ESI No</th>
                                <th>Member ID</th>
                                <th>Process</th>
                                <th>Manager</th>
                                <th>Role</th>
                                <th>Address</th>
                                <th>Referred By</th>
                                <th width="100">Status</th>
                                <th width="120" class="text-center"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows && $rows->num_rows > 0): ?>
                                <?php while($r = $rows->fetch_assoc()): 
                                    $process_name = "";
                                    $process_ids = explode(",", $r['process']);
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
                                    <td class="fw-bold"><?= h($r['employee_id']) ?></td>
                                    <td><?= h($r['employee_name']) ?></td>
                                    <td><?= h($r['employee_username']) ?></td>
                                    <td><?= h($r['email']) ?></td>
                                    <td><?= h($r['phone']) ?></td>
                                    <td><?= formatDate($r['date_of_birth']) ?></td>
                                    <td><?= formatDate($r['joining_date']) ?></td>
                                    <td><?= h($r['team_name'] ?: '—') ?></td>
                                    <td><?= h($r['designation_name'] ?: '—') ?></td>
                                    <td><?= h($r['shift'] ?: 'General') ?></td>
                                    <td><?= h($r['pan_no'] ?: '—') ?></td>
                                    <td><?= h($r['aadhar_no'] ?: '—') ?></td>
                                    <td>
                                        <?php if (!empty($r['aadhar_image_path'])): ?>
                                            <img src="<?= "../Team_Lead/".h($r['aadhar_image_path']) ?>" alt="Aadhar" class="image-preview" onclick="viewImage('<?= "../Team_Lead/".h($r['aadhar_image_path']) ?>')" title="Click to view Aadhar">
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($r['pan_image_path'])): ?>
                                            <img src="<?= "../Team_Lead/".h($r['pan_image_path']) ?>" alt="PAN" class="image-preview" onclick="viewImage('<?= "../Team_Lead/".h($r['pan_image_path']) ?>')" title="Click to view PAN">
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($r['uan_no'] ?: '—') ?></td>
                                    <td><?= h($r['esi_no'] ?: '—') ?></td>
                                    <td><?= h($r['member_id'] ?: '—') ?></td>
                                    <td><?= h($process_name) ?: '—' ?></td>
                                    <td><?= h($r['reporting_manager']) ?></td>
                                    <td><?= h($r['role_name']) ?></td>
                                    <td>
                                        <?php 
                                        $address = $r['address'] ?? '';
                                        if (strlen($address) > 50) {
                                            echo '<span title="' . h($address) . '">' . h(substr($address, 0, 50)) . '...</span>';
                                        } else {
                                            echo h($address ?: '—');
                                        }
                                        ?>
                                    </td>
                                    <td><?= h($r['referred_by'] ?: '—') ?></td>
                                    <td>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="employee_id" value="<?= $r['employee_id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $r['status']=='Active'?'btn-success':'btn-secondary' ?>">
                                                <?= h($r['status']) ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-center">
                                        <!--div class="btn-group btn-group-sm" role="group">
                                            <a href="employee_master.php?mode=edit&id=<?= $r['employee_id'] ?>" class="btn btn-warning" title="Edit Employee">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Are you sure you want to delete <?= h($r['employee_name']) ?>?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="employee_id" value="<?= $r['employee_id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Employee">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div-->
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="24" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-users fa-3x mb-3"></i>
                                            <h5>No employees found</h5>
                                            <p class="mb-0">Get started by adding your first employee.</p>
                                            <a href="employee_master.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Employee
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Image Modal -->
        <div id="imageModal" class="image-modal">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <img class="modal-content" id="modalImage">
        </div>

        <?php endif; ?>
    </div>

    <script>
    function filterTable() {
        const search = document.getElementById('searchInput').value;
        const status = document.getElementById('statusSelect').value;
        const sort = document.getElementById('sortSelect').value;
        const dir = document.getElementById('dirSelect').value;
        
        const params = new URLSearchParams();
        if (search) params.set('q', search);
        if (status) params.set('status', status);
        if (sort) params.set('sort', sort);
        if (dir) params.set('dir', dir);
        
        window.location.href = 'employee_master.php?' + params.toString();
    }

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
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>