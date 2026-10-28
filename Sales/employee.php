<?php
ob_start();
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['salesname'])) { header('Location: ../index.php'); exit; }
$emp_username=$_SESSION["username"];

// Helpers
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect_with($params = []) {
    $base = 'employee.php';
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
    
    if ($action === 'verify' && $id > 0) {
        // Verify action - set emp_confirm to 1
        $stmt = $mysqli->prepare("UPDATE employee_master SET emp_confirm=1 WHERE employee_id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            redirect_with(['msg' => "Details verified successfully! You can no longer edit your details.", 'type' => 'success']);
        } else {
            redirect_with(['msg' => 'Error verifying details: ' . $stmt->error, 'type' => 'danger']);
        }
        $stmt->close();
    }
    elseif ($action === 'edit' && $id > 0) {
        // Check if employee is verified - if yes, don't allow editing
        $stmt = $mysqli->prepare("SELECT emp_confirm FROM employee_master WHERE employee_id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($result && $result['emp_confirm'] == 1) {
            redirect_with(['msg' => "Your details are already verified and cannot be edited.", 'type' => 'warning']);
        }
        
        $employee_name     = trim($_POST['employee_name'] ?? '');
        $employee_username = trim($_POST['employee_username'] ?? '');
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

        $errors = [];
        if (!$employee_name) $errors[] = 'Employee Name is required.';
        if (!$employee_username) $errors[] = 'Username is required.';
        if (!is_valid_date($date_of_birth)) $errors[] = 'Valid Date of Birth is required.';
        if (!is_valid_date($joining_date)) $errors[] = 'Valid Joining Date is required.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid Email is required.';
        if (!$phone || !preg_match('/^\+?\d{10,15}$/', $phone)) $errors[] = 'Phone Number is required.';
        
        // PAN validation (if provided)
        if ($pan_no && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pan_no)) {
            $errors[] = 'Invalid PAN Number format.';
        }
        
        // Aadhar validation (if provided)
        if ($aadhar_no && !preg_match('/^\d{12}$/', $aadhar_no)) {
            $errors[] = 'Aadhar Number must be 12 digits.';
        }

        if ($employee_username) {
            $sql = "SELECT employee_id FROM employee_master WHERE employee_username = ? AND employee_id != ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("si", $employee_username, $id);
            $stmt->execute(); $stmt->store_result();
            if ($stmt->num_rows > 0) { $errors[] = 'Username already exists.'; }
            $stmt->close();
        }

        if ($errors) redirect_with(['msg' => implode(' ', $errors), 'type' => 'danger']);

        // Handle image uploads
        $aadhar_image_path = '';
        $pan_image_path = '';

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
        
        // Build the update query - only editable fields
        $update_fields = [];
        $update_params = [];
        $update_types = "";
        
        // Only basic fields that employees can edit
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
            redirect_with(['msg' => "Your details updated successfully", 'type' => 'info']);
        } else {
            redirect_with(['msg' => 'Error updating details: ' . $stmt->error, 'type' => 'danger']);
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
        $stmt = $mysqli->prepare("SELECT *, emp_confirm FROM employee_master WHERE employee_id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // Check if employee is verified - if yes, redirect to view mode
        if ($editRow && $editRow['emp_confirm'] == 1) {
            redirect_with(['msg' => "Your details are verified and cannot be edited.", 'type' => 'warning']);
        }
    } else {
        $mode = 'view';
    }
}

// Filters - Only show current employee's data
$sql = "SELECT e.*, r.role_name, t.team_name, d.designation_name 
        FROM employee_master e 
        LEFT JOIN user_role_master r ON e.user_role_id = r.role_id
        LEFT JOIN team_master t ON e.team_id = t.team_id
        LEFT JOIN designation_master d ON e.designation_id = d.designation_id
        WHERE e.employee_username = ?";
        
$params = [$emp_username];
$types = 's';

$stmt = $mysqli->prepare($sql); 
$stmt->bind_param($types, ...$params); 
$stmt->execute(); 
$rows = $stmt->get_result(); 
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Employee Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .btn-brown{background:#795548;color:#fff;border:none}.btn-brown:hover{background:#5d4037;color:#fff}
        .btn-verify{background:#28a745;color:#fff;border:none}.btn-verify:hover{background:#218838;color:#fff}
        .readonly-field { background-color: #f8f9fa; cursor: not-allowed; }
        .verified-badge { background-color: #28a745; color: white; padding: 5px 10px; border-radius: 15px; font-size: 12px; }
        
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
        
        /* 2-column table styling */
        .details-table { width: 100%; border-collapse: collapse; }
        .details-table .field-name { 
            font-weight: 600; 
            width: 35%; 
            background-color: #f8f9fa; 
            padding: 12px 15px; 
            border: 1px solid #dee2e6; 
            color: #495057;
        }
        .details-table .field-value { 
            padding: 12px 15px; 
            border: 1px solid #dee2e6; 
            background-color: white;
        }
        .details-table tr:hover .field-value { 
            background-color: #f8f9fa; 
        }
        .details-table tr:hover .field-name { 
            background-color: #e9ecef; 
        }
        
        .status-badge { font-size: 12px; padding: 4px 8px; }
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

        <?php if ($mode==='edit'): ?>
        
        <!-- Edit Form -->
        <div class="d-flex justify-content-between mb-3">
            <h5><i class="fas fa-user me-2"></i>Edit My Details</h5>
            <a href="employee.php" class="btn btn-sm btn-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to View
            </a>
        </div>

        <div class="card p-4 mx-auto" style="max-width:1200px;">
            <form method="post" class="row g-3" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="employee_id" value="<?= $editRow['employee_id'] ?>">

                <!-- Basic Information -->
                <div class="col-md-6">
                    <label class="form-label">Name *</label>
                    <input type="text" name="employee_name" class="form-control" required value="<?= h($editRow['employee_name'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Username *</label>
                    <input type="text" name="employee_username" class="form-control" required value="<?= h($editRow['employee_username'] ?? '') ?>">
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

                <!-- Non-editable Fields (Readonly) -->
                <div class="col-md-4">
                    <label class="form-label">Team</label>
                    <input type="text" class="form-control readonly-field" value="<?= h($editRow['team_name'] ?? '') ?>" readonly>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Designation</label>
                    <input type="text" class="form-control readonly-field" value="<?= h($editRow['designation_name'] ?? '') ?>" readonly>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Shift</label>
                    <input type="text" class="form-control readonly-field" value="<?= h($editRow['shift'] ?? 'General') ?>" readonly>
                </div>

                <div class="col-md-6">
                    <label class="form-label">User Role</label>
                    <input type="text" class="form-control readonly-field" value="<?= h($editRow['role_name'] ?? '') ?>" readonly>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Reporting Manager</label>
                    <input type="text" class="form-control readonly-field" value="<?= h($editRow['reporting_manager'] ?? '') ?>" readonly>
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

                <!-- Process Assignment (Readonly) -->
                <div class="col-12">
                    <div class="section-title">Process Assignment</div>
                    <?php
                    $process_name = "";
                    $process_ids = explode(",", $employee_data['process'] ?? '');
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
                    <input type="text" class="form-control readonly-field" value="<?= h($process_name) ?: '—' ?>" readonly>
                </div>

                <div class="col-12 mt-3 d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-brown">
                        <i class="fas fa-save me-1"></i>
                        Update My Details
                    </button>
                    <a href="employee.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <!-- Image Modal -->
        <div id="imageModal" class="image-modal">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <img class="modal-content" id="modalImage">
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
        </script>

        <?php else: ?>

        <!-- View Mode - Only show current employee's data -->
        <?php 
        // Get the employee data
        $employee_data = null;
        if ($rows && $rows->num_rows > 0) {
            $employee_data = $rows->fetch_assoc();
        }
        ?>
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5><i class="fas fa-user me-2"></i>My Employee Details</h5>
            <div class="d-flex gap-2">
                <?php if ($employee_data && $employee_data['emp_confirm'] == 0): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Are you sure you want to verify your details? Once verified, you cannot edit them again.')">
                        <input type="hidden" name="action" value="verify">
                        <input type="hidden" name="employee_id" value="<?= $employee_data['employee_id'] ?>">
                        <button type="submit" class="btn btn-verify btn-sm">
                            <i class="fas fa-check-circle me-1"></i> Verify Details
                        </button>
                    </form>
                    <a href="employee.php?mode=edit&id=<?= $employee_data['employee_id'] ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-edit me-1"></i> Edit My Details
                    </a>
                <?php elseif ($employee_data && $employee_data['emp_confirm'] == 1): ?>
                    <span class="verified-badge">
                        <i class="fas fa-check-circle me-1"></i> Verified
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- 2-Column Table Layout -->
        <div class="card">
            <div class="card-body p-0">
                <?php if ($employee_data): 
                    $process_name = "";
                    $process_ids = explode(",", $employee_data['process'] ?? '');
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
                 <table class="details-table">
                    <tr>
                        <td class="field-name">ID</td>
                        <td class="field-value"><?= h($employee_data['employee_id']) ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">Name</td>
                        <td class="field-value"><?= h($employee_data['employee_name']) ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">Username</td>
                        <td class="field-value"><?= h($employee_data['employee_username']) ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">Email</td>
                        <td class="field-value"><?= h($employee_data['email']) ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">Phone</td>
                        <td class="field-value"><?= h($employee_data['phone']) ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">Date of Birth</td>
                        <td class="field-value"><?= formatDate($employee_data['date_of_birth']) ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">Joining Date</td>
                        <td class="field-value"><?= formatDate($employee_data['joining_date']) ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">Team</td>
                        <td class="field-value"><?= h($employee_data['team_name'] ?: '—') ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">Designation</td>
                        <td class="field-value"><?= h($employee_data['designation_name'] ?: '—') ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">Process</td>
                        <td class="field-value"><?= h($process_name) ?: '—' ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">User Role</td>
                        <td class="field-value"><?= h($employee_data['role_name']) ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">Reporting Manager</td>
                        <td class="field-value"><?= h($employee_data['reporting_manager']) ?></td>
                    </tr>
                    
                    <tr>
                        <td class="field-name">Shift</td>
                        <td class="field-value"><?= h($employee_data['shift'] ?: 'General') ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">Address</td>
                        <td class="field-value"><?= h($employee_data['address'] ?: '—') ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">PAN No</td>
                        <td class="field-value"><?= h($employee_data['pan_no'] ?: '—') ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">PAN Image</td>
                        <td class="field-value">
                            <?php if (!empty($employee_data['pan_image_path'])): ?>
                                <img src="<?= "../Team_Lead/". h($employee_data['pan_image_path']) ?>" alt="PAN" class="image-preview" onclick="viewImage('<?= "../Team_Lead/".h($employee_data['pan_image_path']) ?>')" title="Click to view PAN">
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="field-name">Aadhar No</td>
                        <td class="field-value"><?= h($employee_data['aadhar_no'] ?: '—') ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">Aadhar Image</td>
                        <td class="field-value">
                            <?php if (!empty($employee_data['aadhar_image_path'])): ?>
                                <img src="<?= "../Team_Lead/". h($employee_data['aadhar_image_path']) ?>" alt="Aadhar" class="image-preview" onclick="viewImage('<?= "../Team_Lead/".h($employee_data['aadhar_image_path']) ?>')" title="Click to view Aadhar">
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <td class="field-name">UAN No</td>
                        <td class="field-value"><?= h($employee_data['uan_no'] ?: '—') ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">ESI No</td>
                        <td class="field-value"><?= h($employee_data['esi_no'] ?: '—') ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">Member ID</td>
                        <td class="field-value"><?= h($employee_data['member_id'] ?: '—') ?></td>
                    </tr>
                    
                    <tr>
                        <td class="field-name">Referred By</td>
                        <td class="field-value"><?= h($employee_data['referred_by'] ?: '—') ?></td>
                    </tr>
                    <tr>
                        <td class="field-name">Status</td>
                        <td class="field-value">
                            <span class="badge <?= $employee_data['status']=='Active'?'bg-success':'bg-secondary' ?> status-badge">
                                <?= h($employee_data['status']) ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="field-name">Verification Status</td>
                        <td class="field-value">
                            <?php if ($employee_data['emp_confirm'] == 1): ?>
                                <span class="verified-badge">
                                    <i class="fas fa-check-circle me-1"></i> Verified
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning status-badge">
                                    <i class="fas fa-clock me-1"></i> Pending Verification
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php else: ?>
                    <div class="text-center py-5">
                        <div class="text-muted">
                            <i class="fas fa-user fa-3x mb-3"></i>
                            <h5>No employee data found</h5>
                            <p class="mb-0">Please contact administrator.</p>
                        </div>
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