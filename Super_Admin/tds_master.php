<?php
require 'db.php';
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

function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

function redirect_with($params = []) {
    $base = 'tds_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $member_id = trim($_POST['member_id'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $category = $_POST['category'] ?? '';
        $address = trim($_POST['address'] ?? '');
        $email_id = trim($_POST['email_id'] ?? '');
        $registration_date = $_POST['registration_date'] ?? '';
        $salary = !empty($_POST['salary']) ? (float)$_POST['salary'] : 0;
        $tds_per = !empty($_POST['tds_per']) ? (float)$_POST['tds_per'] : 0;
        
        // Basic validation
        $errors = [];
        if (empty($member_id)) $errors[] = 'Member ID is required';
        if (empty($full_name)) $errors[] = 'Full Name is required';
        if (empty($mobile)) $errors[] = 'Mobile is required';
        if (empty($category)) $errors[] = 'Category is required';
        if (empty($address)) $errors[] = 'Address is required';
        if (empty($email_id)) $errors[] = 'Email ID is required';
        if (empty($registration_date)) $errors[] = 'Registration Date is required';
        
        // Mobile validation
        if (!empty($mobile) && !preg_match('/^[0-9]{10}$/', $mobile)) {
            $errors[] = 'Mobile must be 10 digits';
        }
        
        // Email validation
        if (!empty($email_id) && !filter_var($email_id, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        }
        
        // Salary validation
        if ($salary < 0) {
            $errors[] = 'Salary cannot be negative';
        }
        
        // TDS Percentage validation
        if ($tds_per < 0 || $tds_per > 100) {
            $errors[] = 'TDS Percentage must be between 0 and 100';
        }
        
        if (!empty($errors)) {
            $_SESSION['form_data'] = [
                'member_id' => $member_id,
                'full_name' => $full_name,
                'mobile' => $mobile,
                'category' => $category,
                'address' => $address,
                'email_id' => $email_id,
                'registration_date' => $registration_date,
                'salary' => $salary,
                'tds_per' => $tds_per
            ];
            redirect_with(['msg' => implode(', ', $errors), 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Check if member_id or email already exists
            $check_stmt = $mysqli->prepare("SELECT id FROM tds_members WHERE member_id = ? OR email_id = ?");
            if ($check_stmt) {
                $check_stmt->bind_param("ss", $member_id, $email_id);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $check_stmt->close();
                    $_SESSION['form_data'] = [
                        'member_id' => $member_id,
                        'full_name' => $full_name,
                        'mobile' => $mobile,
                        'category' => $category,
                        'address' => $address,
                        'email_id' => $email_id,
                        'registration_date' => $registration_date,
                        'salary' => $salary,
                        'tds_per' => $tds_per
                    ];
                    redirect_with(['msg' => 'Member ID or Email already exists.', 'type' => 'danger']);
                }
                $check_stmt->close();
            }
            
            // Insert new member
            $stmt = $mysqli->prepare("INSERT INTO tds_members (member_id, full_name, mobile, category, address, email_id, registration_date, salary, tds_per, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");
            if ($stmt) {
                $stmt->bind_param("sssssssdd", $member_id, $full_name, $mobile, $category, $address, $email_id, $registration_date, $salary, $tds_per);
                
                if ($stmt->execute()) {
                    unset($_SESSION['form_data']);
                    redirect_with(['msg' => "Member '$full_name' added successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error adding member: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            } else {
                redirect_with(['msg' => 'Database preparation error', 'type' => 'danger']);
            }
        }
        
        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Check if member_id or email already exists (excluding current one)
                $check_stmt = $mysqli->prepare("SELECT id FROM tds_members WHERE (member_id = ? OR email_id = ?) AND id != ?");
                if ($check_stmt) {
                    $check_stmt->bind_param("ssi", $member_id, $email_id, $id);
                    $check_stmt->execute();
                    $check_stmt->store_result();
                    
                    if ($check_stmt->num_rows > 0) {
                        $check_stmt->close();
                        redirect_with(['msg' => 'Member ID or Email already exists.', 'type' => 'danger']);
                    }
                    $check_stmt->close();
                }
                
                // Update member
                $stmt = $mysqli->prepare("UPDATE tds_members SET member_id = ?, full_name = ?, mobile = ?, category = ?, address = ?, email_id = ?, registration_date = ?, salary = ?, tds_per = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("sssssssddi", $member_id, $full_name, $mobile, $category, $address, $email_id, $registration_date, $salary, $tds_per, $id);
                    
                    if ($stmt->execute()) {
                        redirect_with(['msg' => "Member updated successfully", 'type' => 'success']);
                    } else {
                        redirect_with(['msg' => 'Error updating member: ' . $stmt->error, 'type' => 'danger']);
                    }
                    $stmt->close();
                } else {
                    redirect_with(['msg' => 'Database preparation error', 'type' => 'danger']);
                }
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $full_name = '';
        
        if ($id > 0) {
            // Get member name for message
            $res = $mysqli->prepare("SELECT full_name FROM tds_members WHERE id = ?");
            if ($res) {
                $res->bind_param("i", $id);
                $res->execute();
                $res->bind_result($full_name);
                $res->fetch();
                $res->close();
                
                // Delete member
                $del = $mysqli->prepare("DELETE FROM tds_members WHERE id = ?");
                if ($del) {
                    $del->bind_param("i", $id);
                    $del->execute();
                    $del->close();
                    
                    redirect_with(['msg' => "Member '$full_name' deleted", 'type' => 'warning']);
                }
            }
        }
    }
    
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT status, full_name FROM tds_members WHERE id = ?");
            if ($res) {
                $res->bind_param("i", $id);
                $res->execute();
                $res->bind_result($current_status, $full_name);
                $res->fetch();
                $res->close();
                
                if ($full_name) {
                    $newStatus = ($current_status === 'Active') ? 'Inactive' : 'Active';
                    
                    $upd = $mysqli->prepare("UPDATE tds_members SET status = ? WHERE id = ?");
                    if ($upd) {
                        $upd->bind_param("si", $newStatus, $id);
                        $upd->execute();
                        $upd->close();
                        
                        redirect_with(['msg' => "Member '$full_name' status changed to $newStatus", 'type' => 'info']);
                    }
                }
            }
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editMember = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT id, member_id, full_name, mobile, category, address, email_id, registration_date, salary, tds_per, status, created_at FROM tds_members WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $editMember = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
    if (!$editMember) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fcategory = $_GET['category'] ?? '';
$fstatus = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query
$sql = "SELECT id, member_id, full_name, mobile, category, address, email_id, registration_date, salary, tds_per, status, created_at FROM tds_members";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(full_name LIKE ? OR member_id LIKE ? OR mobile LIKE ? OR email_id LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if ($fcategory === 'Audit' || $fcategory === 'Consultant') {
    $cond[] = "category = ?";
    $params[] = $fcategory;
    $types .= 's';
}

if ($fstatus === 'Active' || $fstatus === 'Inactive') {
    $cond[] = "status = ?";
    $params[] = $fstatus;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " WHERE " . implode(" AND ", $cond);
}

$sql .= " ORDER BY $sort $dir";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $members = $stmt->get_result();
    } else {
        $members = false;
    }
} else {
    $members = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$members) {
    $members = false;
}

// Get form data from session if exists (for repopulating after validation errors)
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TDS Members Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1600px;
            margin: 0 auto;
        }
        .table th {
            background-color: #2c3e50;
            color: white;
            border: none;
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
        .address-cell {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .salary-cell {
            text-align: right;
            font-weight: 500;
        }
        .tds-cell {
            text-align: center;
            font-weight: 500;
        }
        .tds-amount {
            font-size: 0.85em;
            color: #6c757d;
        }
        .form-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <?php if (file_exists('header.php')) require 'header.php'; ?>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
        
        <!-- Add/Edit Form -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-users me-2"></i><?= $mode === 'edit' ? 'Edit TDS Member' : 'Add New TDS Member' ?></h4>
            <a href="tds_master.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off" id="memberForm">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editMember['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="member_id" class="form-label">Member ID <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="member_id" name="member_id" 
                                       value="<?= h($editMember['member_id'] ?? $formData['member_id'] ?? '') ?>" 
                                       placeholder="Enter member ID" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="full_name" name="full_name" 
                                       value="<?= h($editMember['full_name'] ?? $formData['full_name'] ?? '') ?>" 
                                       placeholder="Enter full name" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="mobile" class="form-label">Mobile <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="mobile" name="mobile" 
                                       value="<?= h($editMember['mobile'] ?? $formData['mobile'] ?? '') ?>" 
                                       placeholder="Enter 10-digit mobile number" 
                                       pattern="[0-9]{10}" maxlength="10" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="category" class="form-label">Category <span class="text-danger">*</span></label>
                                <select class="form-select" id="category" name="category" required>
                                    <option value="">Select Category</option>
                                    <option value="Audit" <?= (($editMember['category'] ?? $formData['category'] ?? '') === 'Audit') ? 'selected' : '' ?>>Audit</option>
                                    <option value="Consultant" <?= (($editMember['category'] ?? $formData['category'] ?? '') === 'Consultant') ? 'selected' : '' ?>>Consultant</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="registration_date" class="form-label">Registration Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="registration_date" name="registration_date" 
                                       value="<?= h($editMember['registration_date'] ?? $formData['registration_date'] ?? '') ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email_id" class="form-label">Email ID <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email_id" name="email_id" 
                                       value="<?= h($editMember['email_id'] ?? $formData['email_id'] ?? '') ?>" 
                                       placeholder="Enter email address" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="salary" class="form-label">Salary (₹) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="salary" name="salary" 
                                       value="<?= h($editMember['salary'] ?? $formData['salary'] ?? '0') ?>" 
                                       placeholder="Enter salary" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="tds_per" class="form-label">TDS Percentage (%) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="tds_per" name="tds_per" 
                                       value="<?= h($editMember['tds_per'] ?? $formData['tds_per'] ?? '0') ?>" 
                                       placeholder="Enter TDS %" step="0.01" min="0" max="100" required>
                                <div class="form-text">0 to 100%</div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="address" class="form-label">Address <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="address" name="address" rows="3" 
                                  placeholder="Enter complete address" required><?= h($editMember['address'] ?? $formData['address'] ?? '') ?></textarea>
                    </div>

                    <?php if($mode === 'edit' && isset($editMember['salary']) && isset($editMember['tds_per'])): ?>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="alert alert-info">
                                <strong>TDS Calculation:</strong><br>
                                Salary: ₹<?= number_format($editMember['salary'], 2) ?><br>
                                TDS Percentage: <?= $editMember['tds_per'] ?>%<br>
                                <strong>TDS Amount: ₹<?= number_format(($editMember['salary'] * $editMember['tds_per']) / 100, 2) ?></strong>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Member' : 'Save Member' ?>
                        </button>
                        <a href="tds_master.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-users me-2"></i>TDS Members Master</h4>
            <a href="tds_master.php?mode=add" class="btn btn-success">
                <i class="fas fa-plus me-1"></i> Add Member
            </a>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control" 
                               placeholder="Search by name, ID, mobile, email..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="category" class="form-select">
                            <option value="">All Categories</option>
                            <option value="Audit" <?= $fcategory === 'Audit' ? 'selected' : '' ?>>Audit</option>
                            <option value="Consultant" <?= $fcategory === 'Consultant' ? 'selected' : '' ?>>Consultant</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="sort" class="form-select">
                            <option value="full_name" <?= $sort === 'full_name' ? 'selected' : '' ?>>Sort by Name</option>
                            <option value="member_id" <?= $sort === 'member_id' ? 'selected' : '' ?>>Sort by ID</option>
                            <option value="salary" <?= $sort === 'salary' ? 'selected' : '' ?>>Sort by Salary</option>
                            <option value="created_at" <?= $sort === 'created_at' ? 'selected' : '' ?>>Sort by Date</option>
                        </select>
                    </div>
                    <!-- <div class="col-md-2">
                        <select name="dir" class="form-select">
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option>
                        </select>
                    </div> -->
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="tds_master.php" class="btn btn-secondary">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Members Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="60">S.No</th>
                                <th>Member ID</th>
                                <th>Full Name</th>
                                <th>Mobile</th>
                                <th>Category</th>
                                <th>Email ID</th>
                                <th>Address</th>
                                <th width="100">Salary (₹)</th>
                                <th width="120">TDS % & Amount</th>
                                <th width="100">Reg Date</th>
                                <th width="100">Status</th>
                                <th width="150" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($members && $members->num_rows > 0): ?>
                                <?php while($row = $members->fetch_assoc()): 
                                    $tds_amount = ($row['salary'] * $row['tds_per']) / 100;
                                ?>
                                <tr>
                                    <td class="fw-bold"><?= (int)$row['id'] ?></td>
                                    <td>
                                        <span class="badge bg-primary"><?= h($row['member_id']) ?></span>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= h($row['full_name']) ?></span>
                                    </td>
                                    <td><?= h($row['mobile']) ?></td>
                                    <td>
                                        <span class="badge <?= $row['category'] === 'Audit' ? 'bg-warning' : 'bg-info' ?>">
                                            <?= h($row['category']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small><?= h($row['email_id']) ?></small>
                                    </td>
                                    <td class="address-cell" title="<?= h($row['address']) ?>">
                                        <?= h($row['address']) ?>
                                    </td>
                                    <td class="salary-cell">
                                        ₹<?= number_format($row['salary'], 2) ?>
                                    </td>
                                    <td class="tds-cell">
                                        <span class="badge bg-danger"><?= $row['tds_per'] ?>%</span>
                                        <div class="tds-amount">₹<?= number_format($tds_amount, 2) ?></div>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= h(formatDate($row['registration_date'])) ?></small>
                                    </td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $row['status'] === 'Active' ? 'btn-success' : 'btn-danger' ?>">
                                                <?= h($row['status']) ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="tds_master.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit Member">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete <?= h($row['full_name']) ?>?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Member">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="12" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-users fa-3x mb-3"></i>
                                            <h5>No TDS members found</h5>
                                            <p class="mb-0">Get started by adding your first TDS member.</p>
                                            <a href="tds_master.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Member
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

        <!-- Summary Card -->
        <?php if($members && $members->num_rows > 0): ?>
        <?php
            // Reset pointer and calculate totals
            $members->data_seek(0);
            $total_members = 0;
            $total_salary = 0;
            $total_tds = 0;
            
            while($row = $members->fetch_assoc()) {
                $total_members++;
                $total_salary += $row['salary'];
                $total_tds += ($row['salary'] * $row['tds_per']) / 100;
            }
        ?>
        <div class="card mt-4">
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <h5 class="text-primary"><?= $total_members ?></h5>
                        <small class="text-muted">Total Members</small>
                    </div>
                    <div class="col-md-3">
                        <h5 class="text-success">₹<?= number_format($total_salary, 2) ?></h5>
                        <small class="text-muted">Total Salary</small>
                    </div>
                    <div class="col-md-3">
                        <h5 class="text-danger">₹<?= number_format($total_tds, 2) ?></h5>
                        <small class="text-muted">Total TDS</small>
                    </div>
                    <div class="col-md-3">
                        <h5 class="text-info">₹<?= number_format($total_salary - $total_tds, 2) ?></h5>
                        <small class="text-muted">Net Amount</small>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert.isConnected) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                }, 5000);
            });

            // Mobile number validation
            const mobileInput = document.getElementById('mobile');
            if (mobileInput) {
                mobileInput.addEventListener('input', function() {
                    this.value = this.value.replace(/[^0-9]/g, '');
                    if (this.value.length > 10) {
                        this.value = this.value.slice(0, 10);
                    }
                });
            }

            // Form validation
            const form = document.getElementById('memberForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const category = document.getElementById('category').value;
                    if (!category) {
                        e.preventDefault();
                        alert('Please select a category');
                        return false;
                    }
                    return true;
                });
            }

            // Real-time TDS calculation
            const salaryInput = document.getElementById('salary');
            const tdsPerInput = document.getElementById('tds_per');
            
            function calculateTDS() {
                if (salaryInput && tdsPerInput) {
                    const salary = parseFloat(salaryInput.value) || 0;
                    const tdsPer = parseFloat(tdsPerInput.value) || 0;
                    const tdsAmount = (salary * tdsPer) / 100;
                    
                    // Update TDS amount display if exists
                    const tdsDisplay = document.getElementById('tdsAmountDisplay');
                    if (tdsDisplay) {
                        tdsDisplay.textContent = '₹' + tdsAmount.toFixed(2);
                    }
                }
            }
            
            if (salaryInput) salaryInput.addEventListener('input', calculateTDS);
            if (tdsPerInput) tdsPerInput.addEventListener('input', calculateTDS);
        });
    </script>
</body>
</html>