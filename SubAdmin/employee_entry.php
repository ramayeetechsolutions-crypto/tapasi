<?php
// employee_entry.php
ini_set('display_errors', 1);

// 2. Report all errors
error_reporting(E_ALL);

require 'db.php'; // Adjust path to your db.php
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Helper functions (assuming they are in a global included file or defined here)
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

// Fetch data for edit mode
$mode = 'add';
$editEmployee = null;

if (!empty($_GET['id'])) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        // Select core fields for form
        $stmt = $mysqli->prepare("SELECT employee_id, employee_name, employee_username, email, phone, joining_date, designation_id, role_id, status FROM employee_master WHERE employee_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editEmployee = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($editEmployee) {
            $mode = 'edit';
        }
    }
}

// Fallback to default values for 'Add' mode
$employeeData = [
    'employee_id' => 0,
    'employee_name' => '',
    'employee_username' => '',
    'email' => '',
    'phone' => '',
    'joining_date' => date('Y-m-d'), // Default to today
    'designation_id' => 0,
    'user_role_id' => 0,
    'status' => 'Active',
];

if ($editEmployee) {
    $employeeData = array_merge($employeeData, $editEmployee);
}

// Fetch lists for select fields (Designations and Roles - simplified placeholders)
$designations = $mysqli->query("SELECT designation_id, designation_name FROM designation_master ORDER BY designation_name");
$roles = $mysqli->query("SELECT role_id, role_name FROM user_role_master ORDER BY role_name");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $mode === 'edit' ? 'Edit Employee' : 'Add New Employee' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Shared styles from your original code */
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { padding: 20px; max-width: 1200px; margin: 0 auto; }
        .card { box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); border: 1px solid rgba(0, 0, 0, 0.125); }
    </style>
</head>
<body>
    <?php require 'header.php'; // Assuming a header file exists ?>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-user-tie me-2"></i><?= $mode === 'edit' ? 'Edit Employee' : 'Add New Employee' ?></h4>
            <a href="employee_master.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" action="action/employee_master.php" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="employee_id" value="<?= (int)$employeeData['employee_id'] ?>">
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="employee_name" class="form-label">Employee Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="employee_name" name="employee_name" 
                                   value="<?= h($employeeData['employee_name']) ?>" placeholder="Full Name" required>
                        </div>
                        <div class="col-md-6">
                            <label for="employee_username" class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="employee_username" name="employee_username" 
                                   value="<?= h($employeeData['employee_username']) ?>" placeholder="Unique Username" required>
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?= h($employeeData['email']) ?>" placeholder="employee@company.com" required>
                        </div>
                        <div class="col-md-6">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control" id="phone" name="phone" 
                                   value="<?= h($employeeData['phone']) ?>" placeholder="e.g., 9876543210">
                        </div>
                        <div class="col-md-6">
                            <label for="joining_date" class="form-label">Joining Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="joining_date" name="joining_date" 
                                   value="<?= h($employeeData['joining_date']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="designation_id" class="form-label">Designation <span class="text-danger">*</span></label>
                            <select class="form-select" id="designation_id" name="designation_id" required>
                                <option value="0">Select Designation</option>
                                <?php if($designations): while($d = $designations->fetch_assoc()): ?>
                                <option value="<?= (int)$d['designation_id'] ?>" 
                                    <?= ((int)$employeeData['designation_id'] === (int)$d['designation_id']) ? 'selected' : '' ?>>
                                    <?= h($d['designation_name']) ?>
                                </option>
                                <?php endwhile; endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="user_role_id" class="form-label">User Role <span class="text-danger">*</span></label>
                            <select class="form-select" id="user_role_id" name="user_role_id" required>
                                <option value="0">Select User Role</option>
                                <?php if($roles): while($r = $roles->fetch_assoc()): ?>
                                <option value="<?= (int)$r['user_role_id'] ?>" 
                                    <?= ((int)$employeeData['user_role_id'] === (int)$r['user_role_id']) ? 'selected' : '' ?>>
                                    <?= h($r['role_name']) ?>
                                </option>
                                <?php endwhile; endif; ?>
                            </select>
                        </div>
                         <div class="col-md-6">
                            <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="Active" <?= $employeeData['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                                <option value="Inactive" <?= $employeeData['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Employee' : 'Save Employee' ?>
                        </button>
                        <a href="employee_master.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>