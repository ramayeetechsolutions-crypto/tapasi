<br><br><br>
<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

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

$today = date('Y-m-d');
$default_dob = date('Y-m-d', strtotime('-20 years'));

// --- POST handler for ADD only ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $employee_name     = trim($_POST['employee_name'] ?? '');
        $employee_username = trim($_POST['employee_username'] ?? '');
        $password          = $_POST['password'] ?? '';
        $confirm_password  = $_POST['confirm_password'] ?? '';
        $email             = trim($_POST['email'] ?? '');
        $phone             = trim($_POST['phone'] ?? '');
        $date_of_birth     = trim($_POST['date_of_birth'] ?? '');
        $joining_date      = trim($_POST['joining_date'] ?? '') ?: '';
        $reporting_manager = trim($_POST['reporting_manager'] ?? '');
        $user_role_id      = isset($_POST['user_role_id']) ? (int)$_POST['user_role_id'] : 0;

        $errors = [];
        
        // Validation
        if (!$employee_name) $errors[] = 'Employee Name is required.';
        if (!$employee_username) $errors[] = 'Username is required.';
        if (!is_valid_date($date_of_birth)) $errors[] = 'Valid Date of Birth is required.';
        if (!is_valid_date($joining_date)) $errors[] = 'Valid Joining Date is required.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid Email is required.';
        if (!$phone || !preg_match('/^\+?\d{10,15}$/', $phone)) $errors[] = 'Phone Number is required.';
        if ($user_role_id <= 0) $errors[] = 'User Role is required.';
        if (strlen($password) < 6) $errors[] = 'Password (min 6 chars) is required.';
        if ($password !== $confirm_password) $errors[] = 'Passwords do not match.';
        if (!$reporting_manager) $errors[] = 'Reporting Manager is required.';

        // Check if username already exists
        if ($employee_username) {
            $stmt = $mysqli->prepare("SELECT employee_id FROM employee_master WHERE employee_username = ?");
            $stmt->bind_param("s", $employee_username);
            $stmt->execute(); 
            $stmt->store_result();
            if ($stmt->num_rows > 0) { 
                $errors[] = 'Username already exists.'; 
            }
            $stmt->close();
        }

        if ($errors) {
            redirect_with(['msg' => implode(' ', $errors), 'type' => 'danger']);
        }

        // Hash password using MD5 (as in your code)
        $password_hash = md5($password);

        // INSERT new employee
        $designation_id = isset($_POST['designation_id']) ? (int)$_POST['designation_id'] : 0;
        $shift = trim($_POST['shift'] ?? 'General');

        // Fetch Sales team ID (default 9)
        $sales_team_id = 9;
        $t_res = $mysqli->query("SELECT team_id FROM team_master WHERE team_name = 'Sales' LIMIT 1");
        if ($t_res && $t_row = $t_res->fetch_assoc()) {
            $sales_team_id = (int)$t_row['team_id'];
        }

        $stmt = $mysqli->prepare("INSERT INTO employee_master 
            (employee_name, employee_username, password_hash, email, phone, 
             date_of_birth, joining_date, reporting_manager, user_role_id, designation_id, shift, team_id, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");
        $stmt->bind_param("ssssssssiisi", 
            $employee_name, 
            $employee_username, 
            $password_hash, 
            $email, 
            $phone, 
            $date_of_birth, 
            $joining_date, 
            $reporting_manager, 
            $user_role_id,
            $designation_id,
            $shift,
            $sales_team_id
        );

        if ($stmt->execute()) {
            $stmt->close();
            $bcrypt_hash = password_hash($password, PASSWORD_DEFAULT);
            $u_stmt = $mysqli->prepare("INSERT INTO users (username, password_hash, role_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role_id = VALUES(role_id)");
            if ($u_stmt) {
                $u_stmt->bind_param("ssi", $employee_username, $bcrypt_hash, $user_role_id);
                $u_stmt->execute();
                $u_stmt->close();
            }
            redirect_with(['msg' => "Employee '{$employee_name}' added successfully", 'type' => 'success']);
        } else {
            redirect_with(['msg' => "Error adding employee: " . $mysqli->error, 'type' => 'danger']);
        }
        
        $stmt->close();
    }
}

// Get user roles for dropdown (excluding Super Admin)
$roles = $mysqli->query("SELECT role_id, role_name FROM user_role_master WHERE role_name != 'Super Admin' ORDER BY role_name");

// Get team leaders for reporting manager dropdown
$current_tl_name = $_SESSION['ename'] ?? $_SESSION['username'] ?? '';
$team_leaders = $mysqli->query("SELECT DISTINCT employee_name FROM employee_master WHERE (user_role_id IN (3, 9) OR employee_username = '$current_tl_name' OR employee_name = '$current_tl_name') AND status='Active' ORDER BY employee_name");

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Employee</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<style>
    .btn-brown {
        background: #795548;
        color: #fff;
        border: none;
    }
    .btn-brown:hover {
        background: #5d4037;
        color: #fff;
    }
</style>
</head>
<body>
<div class="container mt-3">
    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show">
            <?= h($_GET['msg']) ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between mb-3">
        <h5>Add New Employee</h5>
        <a href="employee_master.php" class="btn btn-sm btn-secondary">Back to List</a>
    </div>
    
    <div class="card p-4 mx-auto" style="max-width:900px;">
        <form method="post" class="row g-3" onsubmit="return checkPasswordMatch()" autocomplete="off">
            <input type="hidden" name="action" value="add">

            <div class="col-md-6">
                <label class="form-label">Employee Name *</label>
                <input type="text" name="employee_name" class="form-control" required 
                       value="<?= h($_POST['employee_name'] ?? '') ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">Username *</label>
                <input type="text" name="employee_username" class="form-control" required autocomplete="off" 
                       value="<?= h($_POST['employee_username'] ?? '') ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">Password *</label>
                <div class="input-group">
                    <input type="password" id="password" name="password" class="form-control" required minlength="6" autocomplete="new-password">
                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password')">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">Confirm Password *</label>
                <div class="input-group">
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="6" autocomplete="new-password">
                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">Email *</label>
                <input type="email" name="email" class="form-control" required 
                       value="<?= h($_POST['email'] ?? '') ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">Phone *</label>
                <input type="text" name="phone" pattern="\+?\d{10,15}" class="form-control" required 
                       value="<?= h($_POST['phone'] ?? '') ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">Date of Birth *</label>
                <input type="date" name="date_of_birth" class="form-control" required 
                       value="<?= h($_POST['date_of_birth'] ?? '') ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">Joining Date *</label>
                <input type="date" name="joining_date" class="form-control" required 
                       value="<?= h($_POST['joining_date'] ?? '') ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">User Role *</label>
                <select name="user_role_id" class="form-select" required>
                    <option value="">Select Role</option>
                    <?php 
                    if ($roles) { 
                        while($r = $roles->fetch_assoc()) { 
                            $selected = ((int)($_POST['user_role_id'] ?? 0) === (int)$r['role_id']) ? 'selected' : '';
                            echo "<option value='{$r['role_id']}' $selected>" . h($r['role_name']) . "</option>";
                        } 
                    } 
                    ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Reporting Manager *</label>
                <select name="reporting_manager" class="form-select" required>
                    <option value="">Select Team Leader</option>
                    <?php
                    $selected_rm = $_POST['reporting_manager'] ?? $current_tl_name;
                    $tl_names = [];
                    if ($team_leaders && $team_leaders->num_rows > 0) {
                        while($tl = $team_leaders->fetch_assoc()) {
                            $tl_names[] = $tl['employee_name'];
                        }
                    }
                    if ($current_tl_name && !in_array($current_tl_name, $tl_names)) {
                        $tl_names[] = $current_tl_name;
                    }
                    foreach ($tl_names as $tl_name) {
                        $selected = ($selected_rm == $tl_name) ? 'selected' : '';
                        echo "<option value='" . h($tl_name) . "' $selected>" . h($tl_name) . "</option>";
                    }
                    ?>
                </select>
            </div>

                        <div class="col-md-6">
                <label class="form-label">Designation</label>
                <select name="designation_id" class="form-select">
                    <option value="">Select Designation</option>
                    <?php 
                    $desigs = $mysqli->query("SELECT designation_id, designation_name FROM designation_master WHERE status='Active' ORDER BY designation_name");
                    if ($desigs) { 
                        while($d = $desigs->fetch_assoc()) { 
                            $selected = ((int)($_POST['designation_id'] ?? 0) === (int)$d['designation_id']) ? 'selected' : '';
                            echo "<option value='{$d['designation_id']}' $selected>" . h($d['designation_name']) . "</option>";
                        } 
                    } 
                    ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Shift</label>
                <select name="shift" class="form-select">
                    <option value="General" <?= ($_POST['shift'] ?? 'General') === 'General' ? 'selected' : '' ?>>General</option>
                    <option value="WFH" <?= ($_POST['shift'] ?? '') === 'WFH' ? 'selected' : '' ?>>WFH</option>
                </select>
            </div>

            <div class="col-12 mt-3 d-flex justify-content-end gap-2">
                <button type="submit" class="btn btn-brown">Save Employee</button>
                <button type="reset" class="btn btn-secondary">Reset</button>
            </div>
        </form>
    </div>
</div>

<script>
function togglePassword(id) {
    const input = document.getElementById(id);
    input.type = input.type === "password" ? "text" : "password";
}

function checkPasswordMatch() {
    const pass = document.getElementById('password').value;
    const conf = document.getElementById('confirm_password').value;
    if (pass !== conf) {
        alert("Passwords do not match!");
        return false;
    }
    return true;
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>