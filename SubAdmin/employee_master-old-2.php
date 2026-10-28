<br><br><br>
<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}
$emp_username = $_SESSION["ename"] ?? '';
$current_user_role = $_SESSION["role_name"] ?? 0;

// DEBUG: Check session values
error_log("DEBUG: emp_username = " . $emp_username);
error_log("DEBUG: current_user_role = " . $current_user_role);

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
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function redirect_with($params = []) {
    $base = 'employee_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base"); 
    exit;
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
    
    $upload_dir = 'uploads/employee_docs/';
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
    
    // Handle TL verification
    if ($action === 'tl_verify' && $id > 0) {
        // Check if current user is TL (role_id = 3)
        if ($current_user_role == "TL") {
            // Check if employee is already verified by employee
            $check_stmt = $mysqli->prepare("SELECT emp_confirm, tl_confirm FROM employee_master WHERE employee_id = ?");
            $check_stmt->bind_param("i", $id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();
            
            if ($check_result && $check_result['emp_confirm'] == 1 && $check_result['tl_confirm'] == 0) {
                // Update TL verification
                $update_stmt = $mysqli->prepare("UPDATE employee_master SET tl_confirm = 1, verified_by = ?, verified_at = NOW(), status = 'TL Verified' WHERE employee_id = ?");
                $update_stmt->bind_param("si", $emp_username, $id);
                
                if ($update_stmt->execute()) {
                    redirect_with(['msg' => "Employee verified successfully by TL", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error verifying employee: ' . $update_stmt->error, 'type' => 'danger']);
                }
                $update_stmt->close();
            } else {
                redirect_with(['msg' => 'Employee must be self-verified first or already TL verified', 'type' => 'danger']);
            }
        } else {
            redirect_with(['msg' => 'Only Team Leads can verify employees', 'type' => 'danger']);
        }
        exit;
    }
    
    // Handle employee self-verification
    if ($action === 'emp_verify' && $id > 0) {
        // Check if current user is the employee themselves
        $check_stmt = $mysqli->prepare("SELECT employee_username, emp_confirm FROM employee_master WHERE employee_id = ?");
        $check_stmt->bind_param("i", $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if ($check_result && $check_result['employee_username'] === $emp_username && $check_result['emp_confirm'] == 0) {
            // Update employee self-verification
            $update_stmt = $mysqli->prepare("UPDATE employee_master SET emp_confirm = 1 WHERE employee_id = ?");
            $update_stmt->bind_param("i", $id);
            
            if ($update_stmt->execute()) {
                redirect_with(['msg' => "Employee self-verified successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error self-verifying: ' . $update_stmt->error, 'type' => 'danger']);
            }
            $update_stmt->close();
        } else {
            redirect_with(['msg' => 'You can only verify your own account or account already verified', 'type' => 'danger']);
        }
        exit;
    }

    // Existing CRUD operations with verification checks
    $employee_name     = trim($_POST['employee_name'] ?? '');
    $employee_username = trim($_POST['employee_username'] ?? '');
    $password          = $_POST['password'] ?? '';
    $confirm_password  = $_POST['confirm_password'] ?? '';
    $email             = trim($_POST['email'] ?? '');
    $phone             = trim($_POST['phone'] ?? '');
    $date_of_birth     = trim($_POST['date_of_birth'] ?? '');
    $joining_date      = trim($_POST['joining_date'] ?? '') ?: $today;
    
    // Additional fields
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
    
    // Check if editing is allowed (not TL verified)
    if ($action === 'edit' && $id > 0) {
        $check_verify_stmt = $mysqli->prepare("SELECT tl_confirm FROM employee_master WHERE employee_id = ?");
        $check_verify_stmt->bind_param("i", $id);
        $check_verify_stmt->execute();
        $verify_result = $check_verify_stmt->get_result()->fetch_assoc();
        $check_verify_stmt->close();
        
        if ($verify_result && $verify_result['tl_confirm'] == 1) {
            redirect_with(['msg' => 'Cannot edit TL verified employee data', 'type' => 'danger']);
        }
    }

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
            pan_no, employee_name_pan, aadhar_no, esi_name, esi_no, epd_name, uan_no, member_id, address, referred_by, team_id, designation_id, shift, emp_confirm, tl_confirm) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)");

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
        // Check if employee is TL verified before deletion
        $check_verify_stmt = $mysqli->prepare("SELECT tl_confirm FROM employee_master WHERE employee_id = ?");
        $check_verify_stmt->bind_param("i", $id);
        $check_verify_stmt->execute();
        $verify_result = $check_verify_stmt->get_result()->fetch_assoc();
        $check_verify_stmt->close();
        
        if ($verify_result && $verify_result['tl_confirm'] == 1) {
            redirect_with(['msg' => 'Cannot delete TL verified employee', 'type' => 'danger']);
        }
        
        $stmt = $mysqli->prepare("DELETE FROM employee_master WHERE employee_id=?");
        $stmt->bind_param("i", $id); 
        if ($stmt->execute()) {
            redirect_with(['msg' => "Employee deleted successfully", 'type' => 'warning']);
        } else {
            redirect_with(['msg' => 'Error deleting employee: ' . $stmt->error, 'type' => 'danger']);
        }
        $stmt->close();
    } elseif ($action === 'toggle' && $id > 0) {
        // Check if employee is TL verified before status change
        $check_verify_stmt = $mysqli->prepare("SELECT tl_confirm FROM employee_master WHERE employee_id = ?");
        $check_verify_stmt->bind_param("i", $id);
        $check_verify_stmt->execute();
        $verify_result = $check_verify_stmt->get_result()->fetch_assoc();
        $check_verify_stmt->close();
        
        if ($verify_result && $verify_result['tl_confirm'] == 1) {
            redirect_with(['msg' => 'Cannot change status of TL verified employee', 'type' => 'danger']);
        }
        
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
        
        // Check if editing is allowed (not TL verified)
        if ($editRow && $editRow['tl_confirm'] == 1) {
            redirect_with(['msg' => 'Cannot edit TL verified employee data', 'type' => 'danger']);
        }
    } else {
        $mode = 'view';
    }
}

// Pagination configuration
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $records_per_page;

// Filters
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'employee_id';
$dir = strtolower($_GET['dir'] ?? 'asc'); // Changed default to ascending

$sortable = ['employee_id','employee_name','employee_username','email','phone','date_of_birth','joining_date','process','reporting_manager','user_role_id','status'];
if (!in_array($sort,$sortable,true)) $sort='employee_id';
$dir = ($dir=='desc')?'desc':'asc';

// Base SQL for counting total records
$count_sql = "SELECT COUNT(*) as total 
              FROM employee_master e 
              LEFT JOIN user_role_master r ON e.user_role_id = r.role_id
              LEFT JOIN team_master t ON e.team_id = t.team_id
              LEFT JOIN designation_master d ON e.designation_id = d.designation_id
              WHERE 1=1";

// Base SQL for fetching records
$sql = "SELECT e.*, r.role_name, t.team_name, d.designation_name 
        FROM employee_master e 
        LEFT JOIN user_role_master r ON e.user_role_id = r.role_id
        LEFT JOIN team_master t ON e.team_id = t.team_id
        LEFT JOIN designation_master d ON e.designation_id = d.designation_id
        WHERE 1=1";

$cond=[];$params=[];$types='';

if ($q) { 
    $cond[] = "(e.employee_name LIKE ? OR e.employee_username LIKE ? OR e.email LIKE ?)"; 
    $tval="%$q%"; 
    $params=array_merge($params,[$tval,$tval,$tval]); 
    $types.='sss'; 
}
if ($fstatus==='Active'||$fstatus==='Inactive'){ 
    $cond[] = "e.status=?"; 
    $params[]=$fstatus; 
    $types.='s'; 
}

if ($cond) {
    $sql .= " AND " . implode(" AND ", $cond);
    $count_sql .= " AND " . implode(" AND ", $cond);
}

// Get total records count
if ($types) { 
    $stmt=$mysqli->prepare($count_sql); 
    $stmt->bind_param($types,...$params); 
    $stmt->execute(); 
    $total_result = $stmt->get_result()->fetch_assoc();
    $total_records = $total_result['total'];
    $stmt->close(); 
} else { 
    $total_result = $mysqli->query($count_sql)->fetch_assoc();
    $total_records = $total_result['total'];
}

$total_pages = ceil($total_records / $records_per_page);
if ($page > $total_pages && $total_pages > 0) {
    $page = $total_pages;
    $offset = ($page - 1) * $records_per_page;
}

// Add sorting and pagination to main query
$sql .= " ORDER BY $sort $dir";
$sql .= " LIMIT $offset, $records_per_page";

// Fetch paginated records
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
        
        /* Verification badges */
        .verification-badge { font-size: 0.75em; }
        
        /* Scrollable table styling */
        .table-container {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
        }
        
        .table-scrollable {
            min-width: 1800px;
            margin-bottom: 0;
        }
        
        .table-scrollable thead th {
            position: sticky;
            top: 0;
            background-color: #2c3e50;
            z-index: 10;
        }
        
        /* Fixed first column for better navigation */
        .table-scrollable th:first-child,
        .table-scrollable td:first-child {
            position: sticky;
            left: 0;
            background-color: #f8f9fa;
            z-index: 5;
            border-right: 2px solid #dee2e6;
        }
        
        .table-scrollable thead th:first-child {
            background-color: #2c3e50;
            z-index: 15;
        }
        
        /* Fixed last column (actions) for better navigation */
        .table-scrollable th:last-child,
        .table-scrollable td:last-child {
            position: sticky;
            right: 0;
            background-color: #f8f9fa;
            z-index: 5;
            border-left: 2px solid #dee2e6;
        }
        
        .table-scrollable thead th:last-child {
            background-color: #2c3e50;
            z-index: 15;
        }
        
        /* Scrollbar styling */
        .table-container::-webkit-scrollbar {
            height: 12px;
        }
        
        .table-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 6px;
        }
        
        .table-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 6px;
        }
        
        .table-container::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        /* Pagination styling */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            padding: 10px 0;
        }
        
        .pagination-info {
            font-size: 0.9rem;
            color: #6c757d;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .table-container {
                font-size: 0.875rem;
            }
            
            .table-scrollable {
                min-width: 2000px;
            }
            
            .pagination-container {
                flex-direction: column;
                gap: 10px;
            }
        }
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

        <!-- DEBUG: Show current user info (remove in production) -->
        <div class="alert alert-info d-none">
            <strong>Debug Info:</strong><br>
            Current User: <?= h($emp_username) ?><br>
            Current Role: <?= $current_user_role ?><br>
            Is TL: <?= ($current_user_role == "TL") ? 'Yes' : 'No' ?>
        </div>

        <?php if ($mode==='add'||$mode==='edit'): ?>
        
        <!-- Add/Edit Form (same as before, shortened for brevity) -->
        <!-- ... existing add/edit form code ... -->

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between mb-3">
            <h5><i class="fas fa-users me-2"></i>Employee Master</h5>
            <a href="employee_master.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add Employee
            </a>
        </div>

        <!-- Search and Filter Form -->
       <div class="card mb-4">
  <div class="card-body">
    <form method="get" class="row g-3 align-items-center">
      <input type="hidden" name="page" value="1">

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

      <!-- ✅ Buttons Row -->
      <div class="col-12 d-flex align-items-center mt-2">
        <button type="submit" class="btn btn-primary btn-sm me-2">
          <i class="fas fa-search me-1"></i> Search
        </button>

        <a href="employee_master.php" class="btn btn-secondary btn-sm me-2">
          <i class="fas fa-times me-1"></i> Clear
        </a>

        <!-- ✅ Import Excel Button (Green) -->
        <button type="button" class="btn btn-success btn-sm d-flex align-items-center" 
                data-bs-toggle="modal" data-bs-target="#importModal">
          <i class="bi bi-file-earmark-excel me-1"></i> Import Excel
        </button>
      </div>
    </form>
  </div>
</div>
<!--Import Button-->  
        <style>
    body {
        background-color: #f8f9fa;
    }
    .import-btn {
        background-color: #0d6efd;
        color: white;
        border-radius: 8px;
        padding: 10px 20px;
        font-weight: 500;
        transition: 0.3s;
    }
    .import-btn:hover {
        background-color: #0b5ed7;
    }
    .modal-header {
        background-color: #0d6efd;
        color: white;
    }
</style>
<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="importModalLabel">Import Excel File</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form action="import_excel.php" method="post" enctype="multipart/form-data">
        <div class="modal-body text-center">
            <label class="form-label">Select Excel File (.xlsx or .xls)</label>
            <input type="file" name="excelFile" accept=".xls,.xlsx" class="form-control" required>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success">Upload</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
        <!-- Employees Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-container">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="50">ID 
                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'employee_id', 'dir' => $sort == 'employee_id' && $dir == 'asc' ? 'desc' : 'asc'])) ?>" class="text-white">
                                    <i class="fas fa-sort<?= $sort == 'employee_id' ? ($dir == 'asc' ? '-up' : '-down') : '' ?>"></i>
                                </a>
                        </th>
                        <th>Username</th>
                        <th>Name</th>
                        <th>DOB</th>
                        <th>DOJ</th>
                        <th>Manager</th>
                        <th>Shift</th>
                        <th>Team</th>
                        <th>Designation</th>
                        <th>Role</th>
                        <th>Process</th>
                        <th>Account No</th>
                        <th>IFSC Code</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Address</th>
                        <th>PAN No</th>
                        <th>PAN Image</th>
                        <th>Aadhar No</th>
                        <th>Aadhar Image</th>
                        <th>UAN No</th>
                        <th>ESI No</th>
                        <th>Member ID</th>
                        <th>Referred By</th>
                        <th width="80">Emp Verified</th>
                        <th width="80">TL Verified</th>
                        <th width="100">Status</th>
                        <th width="150" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows && $rows->num_rows > 0): ?>
                        <?php 
                        $serial_number = $offset + 1;
                        while($r = $rows->fetch_assoc()): 
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
                            <td class="fw-bold"><?= $serial_number ?></td>
                            <td><?= h($r['employee_username']) ?></td>
                            <td><?= h($r['employee_name']) ?></td>
                            <td><?= formatDate($r['date_of_birth']) ?></td>
                            <td><?= formatDate($r['joining_date']) ?></td>
                            <td><?= h($r['reporting_manager']) ?></td>
                            <td><?= h($r['shift'] ?: 'General') ?></td>
                            <td><?= h($r['team_name'] ?: '—') ?></td>
                            <td><?= h($r['designation_name'] ?: '—') ?></td>
                            <td><?= h($r['role_name']) ?></td>
                            <td><?= h($process_name) ?: '—' ?></td>
                            <td><?= h($r['account_no'] ?: '—') ?></td>
                            <td><?= h($r['ifsc_code'] ?: '—') ?></td>
                             <td><?= h($r['email']) ?></td>
                            <td><?= h($r['phone']) ?></td>
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
                           
                            <td><?= h($r['pan_no'] ?: '—') ?></td>
                            <td>
                                <?php if (!empty($r['pan_image_path'])): ?>
                                    <img src="<?= h($r['pan_image_path']) ?>" alt="PAN" class="image-preview" onclick="viewImage('<?= h($r['pan_image_path']) ?>')" title="Click to view PAN">
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?= h($r['aadhar_no'] ?: '—') ?></td>
                            <td>
                                <?php if (!empty($r['aadhar_image_path'])): ?>
                                    <img src="<?= h($r['aadhar_image_path']) ?>" alt="Aadhar" class="image-preview" onclick="viewImage('<?= h($r['aadhar_image_path']) ?>')" title="Click to view Aadhar">
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?= h($r['uan_no'] ?: '—') ?></td>
                            <td><?= h($r['esi_no'] ?: '—') ?></td>
                            <td><?= h($r['member_id'] ?: '—') ?></td>
                            <td><?= h($r['referred_by'] ?: '—') ?></td>
                            
                            <!-- Employee Verification Status -->
                            <td class="text-center">
                                <?php if ($r['emp_confirm'] == 1): ?>
                                    <span class="badge bg-success verification-badge">Yes</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary verification-badge">No</span>
                                    <?php if ($r['employee_username'] === $emp_username): ?>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="action" value="emp_verify">
                                            <input type="hidden" name="employee_id" value="<?= $r['employee_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary mt-1" 
                                                    onclick="return confirm('Confirm self-verification?')">
                                                <i class="fas fa-user-check me-1"></i>Verify
                                            </button>
                                        </form>
                                    <?php endif; ?>
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
                                    <?php if ($r['emp_confirm'] == 1 && $current_user_role == "TL"): ?>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="action" value="tl_verify">
                                            <input type="hidden" name="employee_id" value="<?= $r['employee_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-warning mt-1" 
                                                    onclick="return confirm('Confirm TL verification? This will lock the employee data.')">
                                                <i class="fas fa-check-circle me-1"></i>TL Verify
                                            </button>
                                        </form>
                                    <?php elseif ($r['emp_confirm'] == 0 && $current_user_role == "TL"): ?>
                                        <small class="d-block text-muted">Waiting for emp verification</small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Status with verification info -->
                            <td>
                                <?php if ($r['tl_confirm'] == 1): ?>
                                    <span class="badge bg-info">TL Verified</span>
                                <?php else: ?>
                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="employee_id" value="<?= $r['employee_id'] ?>">
                                        <button type="submit" class="btn btn-sm <?= $r['status']=='Active'?'btn-success':'btn-secondary' ?>" 
                                                <?= $r['tl_confirm'] == 1 ? 'disabled' : '' ?>>
                                            <?= h($r['status']) ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Actions with verification check -->
                            <td class="text-center">
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="employee_master.php?mode=edit&id=<?= $r['employee_id'] ?>" 
                                       class="btn btn-warning <?= $r['tl_confirm'] == 1 ? 'disabled' : '' ?>" 
                                       title="<?= $r['tl_confirm'] == 1 ? 'Cannot edit TL verified data' : 'Edit Employee' ?>"
                                       <?= $r['tl_confirm'] == 1 ? 'onclick="return false;"' : '' ?>>
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="post" style="display:inline" 
                                          onsubmit="return confirm('Are you sure you want to delete <?= h($r['employee_name']) ?>?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="employee_id" value="<?= $r['employee_id'] ?>">
                                        <button type="submit" class="btn btn-danger <?= $r['tl_confirm'] == 1 ? 'disabled' : '' ?>" 
                                                title="<?= $r['tl_confirm'] == 1 ? 'Cannot delete TL verified employee' : 'Delete Employee' ?>"
                                                <?= $r['tl_confirm'] == 1 ? 'onclick="return false;"' : '' ?>>
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php $serial_number++; endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="28" class="text-center py-5">
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

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-container">
                    <div class="pagination-info">
                        Showing <?= $offset + 1 ?> to <?= min($offset + $records_per_page, $total_records) ?> of <?= $total_records ?> entries
                    </div>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <!-- First Page -->
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                            </li>
                            
                            <!-- Previous Page -->
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            </li>
                            
                            <!-- Page Numbers -->
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++): 
                            ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <!-- Next Page -->
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                            </li>
                            
                            <!-- Last Page -->
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
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