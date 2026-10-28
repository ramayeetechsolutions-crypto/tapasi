<br><br><br>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Check session login
if (!isset($_SESSION['username'])) {
    header("Location: ../index.php");
    exit;
}

$session_username  = $_SESSION['username'] ?? ($_SESSION['ename'] ?? '');
$session_uid       = $_SESSION['uid'] ?? 0;

// Fetch the logged-in TL / Sub TL full details
$cur_user_query = "SELECT employee_id, employee_name, employee_username FROM employee_master WHERE employee_id = ? OR employee_username = ?";
$stmt_curr = $mysqli->prepare($cur_user_query);
$stmt_curr->bind_param("is", $session_uid, $session_username);
$stmt_curr->execute();
$current_emp = $stmt_curr->get_result()->fetch_assoc();
$stmt_curr->close();

$emp_fullname = $current_emp['employee_name'] ?? $session_username;

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

$today = date('Y-m-d');
$default_dob = date('Y-m-d', strtotime('-20 years'));

// --- POST handler for ADD EMPLOYEE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $employee_name     = trim($_POST['employee_name'] ?? '');
        $employee_username = trim($_POST['employee_username'] ?? '');
        $password          = $_POST['password'] ?? '';
        $confirm_password  = $_POST['confirm_password'] ?? '';
        $email             = trim($_POST['email'] ?? '');
        $phone             = trim($_POST['phone'] ?? '');
        $date_of_birth     = !empty(trim($_POST['date_of_birth'] ?? '')) ? trim($_POST['date_of_birth']) : null;
        $joining_date      = !empty(trim($_POST['joining_date'] ?? '')) ? trim($_POST['joining_date']) : $today;
        
        $team_id           = (int)($_POST['team_id'] ?? 0);
        $designation_id    = (int)($_POST['designation_id'] ?? 0);
        $shift             = trim($_POST['shift'] ?? 'General');
        $reporting_manager = $emp_fullname; // Auto-set logged in TL as reporting manager
        $user_role_id      = 4; // Default Team Member role ID

        // Process checkboxes
        $selected_processes = isset($_POST['process']) ? implode(",", $_POST['process']) : "";

        $errors = [];
        
        // Validation
        if (!$employee_name) $errors[] = 'Employee Name is required.';
        if (!$employee_username) $errors[] = 'Username / Employee ID is required.';
        if ($date_of_birth && !is_valid_date($date_of_birth)) $errors[] = 'Valid Date of Birth is required.';
        if ($joining_date && !is_valid_date($joining_date)) $errors[] = 'Valid Joining Date is required.';
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid Email is required.';
        if (strlen($password) < 6) $errors[] = 'Password (minimum 6 characters) is required.';
        if ($password !== $confirm_password) $errors[] = 'Passwords do not match.';

        // Check if username already exists
        if ($employee_username) {
            $stmt = $mysqli->prepare("SELECT employee_id FROM employee_master WHERE employee_username = ?");
            $stmt->bind_param("s", $employee_username);
            $stmt->execute(); 
            $stmt->store_result();
            if ($stmt->num_rows > 0) { 
                $errors[] = 'Employee ID / Username already exists.'; 
            }
            $stmt->close();
        }

        if ($errors) {
            redirect_with(['msg' => implode(' ', $errors), 'type' => 'danger']);
        }

        // Hash password using MD5
        $password_hash = md5($password);

        // INSERT new employee matching the database columns
        $sql = "INSERT INTO employee_master 
            (employee_name, employee_username, password_hash, email, phone, 
             date_of_birth, joining_date, team_id, designation_id, shift, process, 
             reporting_manager, user_role_id, status, emp_confirm, tl_confirm) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', 0, 0)";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sssssssiisssi", 
            $employee_name, 
            $employee_username, 
            $password_hash, 
            $email, 
            $phone, 
            $date_of_birth, 
            $joining_date, 
            $team_id,
            $designation_id,
            $shift,
            $selected_processes,
            $reporting_manager, 
            $user_role_id
        );

        if ($stmt->execute()) {
            redirect_with(['msg' => "Employee '{$employee_name}' added successfully", 'type' => 'success']);
        } else {
            redirect_with(['msg' => "Error adding employee: " . $mysqli->error, 'type' => 'danger']);
        }
        
        $stmt->close();
    }
}

// Fetch Dropdowns
$teams = $mysqli->query("SELECT team_id, team_name FROM team_master WHERE status='Active' ORDER BY team_name");
$designations = $mysqli->query("SELECT designation_id, designation_name FROM designation_master WHERE status='Active' ORDER BY designation_name");

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Employee - Team Lead</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .section-title { font-weight: 600; color: #495057; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 1px solid #dee2e6; }
    </style>
</head>
<body>
    <div class="container-fluid mt-3" style="margin-top: 80px;">
        
        <!-- Header -->
        <div class="d-flex justify-content-between mb-3">
            <h5><i class="fas fa-user-plus me-2"></i>Add New Employee</h5>
            <a href="employee_master.php" class="btn btn-sm btn-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card p-4 mx-auto" style="max-width:1200px;">
            <form method="post" class="row g-3" onsubmit="return checkPasswordMatch()">
                <input type="hidden" name="action" value="add">

                <!-- Basic Information -->
                <div class="col-md-6">
                    <label class="form-label">Employee ID <span class="text-danger">*</span></label>
                    <input type="text" name="employee_username" class="form-control" placeholder="e.g. TPS123" required value="<?= h($_POST['employee_username'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Employee Name <span class="text-danger">*</span></label>
                    <input type="text" name="employee_name" class="form-control" placeholder="Full Name" required value="<?= h($_POST['employee_name'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Password <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="password" id="password" name="password" class="form-control" required minlength="6" placeholder="Min 6 characters">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password')">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="6" placeholder="Re-type password">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="email@example.com" value="<?= h($_POST['email'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" placeholder="10-digit phone number" value="<?= h($_POST['phone'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="date_of_birth" class="form-control" value="<?= h($_POST['date_of_birth'] ?? $default_dob) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Joining Date</label>
                    <input type="date" name="joining_date" class="form-control" value="<?= h($_POST['joining_date'] ?? $today) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Team</label>
                    <select name="team_id" class="form-select">
                        <option value="">Select Team</option>
                        <?php if ($teams): while($r=$teams->fetch_assoc()): ?>
                            <option value="<?= $r['team_id'] ?>"><?= h($r['team_name']) ?></option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Designation</label>
                    <select name="designation_id" class="form-select">
                        <option value="">Select Designation</option>
                        <?php if ($designations): while($r=$designations->fetch_assoc()): ?>
                            <option value="<?= $r['designation_id'] ?>"><?= h($r['designation_name']) ?></option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Shift</label>
                    <select name="shift" class="form-select">
                        <option value="General" selected>General</option>
                        <option value="WFH">WFH</option>
                    </select>
                </div>

                <!-- Processes Section (Exact Layout of Edit Page) -->
                <div class="col-12">
                    <div class="section-title">Process Assignment</div>
                    <div class="row">
                        <?php
                        // Fetch all processes
                        $processes = $mysqli->query("SELECT process_id, process_name FROM process_master WHERE status='Active' ORDER BY process_id");

                        // Buckets
                        $ce = $pag = $others = [];
                        if ($processes) {
                            while ($row = $processes->fetch_assoc()) {
                                $name = $row['process_name'];
                                if ($name === "CE" || str_ends_with($name, "-CE") || $name === "LE" || $name === "Sub TL") {
                                    $ce[] = $row;
                                } elseif ($name === "PAG" || str_ends_with($name, "-PAG") || $name === "QC" || $name === "TAB/IMG" || $name === "PRE-PAG" || $name === "AP" || $name === "AP_CRX" || $name === "CE-REF") {
                                    $pag[] = $row;
                                } else {
                                    $others[] = $row;
                                }
                            }
                        }

                        // Helper to render switch groups
                        function renderGroup($groupName, $items) {
                            echo "<div class='mb-2'><h6 class='mb-1'><b>$groupName</b></h6>";
                            echo "<div class='row'>";
                            foreach ($items as $index => $row) {
                                echo '<div class="col-md-3 mb-2">
                                        <div class="form-check form-switch">
                                            <input type="checkbox" class="form-check-input" 
                                                   name="process[]" 
                                                   value="'.$row['process_id'].'" 
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

                        renderGroup("CE Processes", $ce);
                        renderGroup("PAG Processes", $pag);
                        renderGroup("XML Processes", $others);
                        ?>
                    </div>
                </div>

                <div class="col-12 mt-3 d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Employee
                    </button>
                    <a href="employee_master.php" class="btn btn-secondary">Cancel</a>
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