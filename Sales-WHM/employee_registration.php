<?php
ob_start(); // Prevent header errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php'; 

// 1. INCLUDE EXCEL LIBRARY
$paths = [
    '../simplexlsx-master/src/SimpleXLSX.php', 
    '../simplexlsx-master/SimpleXLSX.php',     
    '../../simplexlsx-master/src/SimpleXLSX.php' 
];

$xlsxLoaded = false;
foreach ($paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $xlsxLoaded = true;
        break;
    }
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 2. HANDLE FORM SUBMISSIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- A. ADD EMPLOYEE (Manual) ---
    if (isset($_POST['add_employee'])) {
        $emp_name       = $mysqli->real_escape_string($_POST['employee_name']);
        $emp_username   = $mysqli->real_escape_string($_POST['employee_username']);
        $email          = $mysqli->real_escape_string($_POST['email']);
        $password       = $_POST['password'];
        
        $dob_input      = $_POST['dob'];
        $dob            = !empty($dob_input) ? "'".$mysqli->real_escape_string($dob_input)."'" : "NULL";
        
        $doj_input      = $_POST['joining_date'];
        $doj            = !empty($doj_input) ? "'".$mysqli->real_escape_string($doj_input)."'" : "NULL";
        
        $role_id        = (int)$_POST['user_role_id'];
        $team_id        = (int)$_POST['team_id'];
        $desg_id        = (int)$_POST['designation_id'];
        $process_id     = (int)$_POST['process_id'];
        $process_name   = $mysqli->real_escape_string($_POST['process_name']);
        
        $manager        = $mysqli->real_escape_string($_POST['reporting_manager']);
        $shift          = $mysqli->real_escape_string($_POST['shift']);
        $referred_by    = $mysqli->real_escape_string($_POST['referred_by']);
        
        $created_by     = $_SESSION['username'] ?? 'System';
        $created_date   = date('Y-m-d H:i:s');
        $password_hash  = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO employee_master1 
                (employee_name, employee_username, email, password_hash, date_of_birth, joining_date, user_role_id, reporting_manager, team_id, designation_id, shift, process, process1, referred_by, created_by, created_date, status) 
                VALUES 
                ('$emp_name', '$emp_username', '$email', '$password_hash', $dob, $doj, '$role_id', '$manager', '$team_id', '$desg_id', '$shift', '$process_name', '$process_id', '$referred_by', '$created_by', '$created_date', 'Active')";

        if ($mysqli->query($sql)) {
            header("Location: employee_registration.php?msg=added"); exit;
        } else {
            die("SQL Error: " . $mysqli->error);
        }
    }

    // --- B. IMPORT EXCEL (Updated Logic) ---
    if (isset($_POST['import_excel'])) {
        if (!$xlsxLoaded) {
            $error = "Library SimpleXLSX not found. Checked paths: " . implode(", ", $paths);
        } elseif (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
            
            $xlsx = null;
            if (class_exists('Shuchkin\SimpleXLSX')) {
                $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
            } elseif (class_exists('SimpleXLSX')) {
                $xlsx = SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
            }

            if ($xlsx) {
                $count = 0;
                $rows = $xlsx->rows();
                $created_by   = $_SESSION['username'] ?? 'Import';
                $created_date = date('Y-m-d H:i:s');

                // Skip Header (Start from index 1)
                for ($i = 1; $i < count($rows); $i++) {
                    $r = $rows[$i];
                    
                    // Column Mapping:
                    // 0:Name, 1:EmpID, 2:Email, 3:DOB, 4:DOJ, 5:RoleID, 6:TeamID, 7:DesgID, 
                    // 8:Manager, 9:ProcessName, 10:ProcessID, 11:Shift, 12:RefBy, 13:PASSWORD
                    
                    $name       = $mysqli->real_escape_string($r[0] ?? '');
                    $username   = $mysqli->real_escape_string($r[1] ?? '');
                    $email      = $mysqli->real_escape_string($r[2] ?? '');
                    
                    if(empty($username) || empty($name)) continue;

                    $dob_val    = $mysqli->real_escape_string($r[3] ?? '');
                    $dob        = !empty($dob_val) ? "'$dob_val'" : "NULL";

                    $doj_val    = $mysqli->real_escape_string($r[4] ?? date('Y-m-d'));
                    $doj        = "'$doj_val'";
                    
                    $role_id    = (int)($r[5] ?? 3); 
                    $team_id    = (int)($r[6] ?? 0);
                    $desg_id    = (int)($r[7] ?? 0);
                    $manager    = $mysqli->real_escape_string($r[8] ?? '');
                    $proc_name  = $mysqli->real_escape_string($r[9] ?? '');
                    $proc_id    = (int)($r[10] ?? 0);
                    $shift      = $mysqli->real_escape_string($r[11] ?? 'Day');
                    $ref_by     = $mysqli->real_escape_string($r[12] ?? '');
                    
                    // --- NEW: Read Password from Column 13 ---
                    $raw_pass   = $r[13] ?? 'Welcome@123'; // Fallback only if column is empty
                    $password_hash = password_hash($raw_pass, PASSWORD_DEFAULT);

                    $check = $mysqli->query("SELECT employee_id FROM employee_master1 WHERE employee_username = '$username'");
                    if ($check->num_rows == 0) {
                        $sql_imp = "INSERT INTO employee_master1 
                        (employee_name, employee_username, email, password_hash, date_of_birth, joining_date, user_role_id, reporting_manager, team_id, designation_id, shift, process, process1, referred_by, created_by, created_date, status) 
                        VALUES 
                        ('$name', '$username', '$email', '$password_hash', $dob, $doj, '$role_id', '$manager', '$team_id', '$desg_id', '$shift', '$proc_name', '$proc_id', '$ref_by', '$created_by', '$created_date', 'Active')";
                        
                        if($mysqli->query($sql_imp)) { $count++; }
                    }
                }
                header("Location: employee_registration.php?msg=imported&count=$count"); exit;
            } else {
                $error = (class_exists('Shuchkin\SimpleXLSX')) ? Shuchkin\SimpleXLSX::parseError() : SimpleXLSX::parseError();
            }
        } else {
            $error = "File upload failed.";
        }
    }
}

// 3. FETCH DATA FOR TABLE VIEW
$emp_query = "SELECT * FROM employee_master1 ORDER BY employee_id DESC";
$employees = $mysqli->query($emp_query);

function getRoleName($id) {
    switch($id) {
        case 1: return '<span class="badge bg-danger">Admin</span>';
        case 2: return '<span class="badge bg-warning text-dark">TL</span>';
        default: return '<span class="badge bg-info text-dark">Employee</span>';
    }
}

require 'header.php'; 
?>

<!-- Main Content -->
<div class="container-fluid py-4" style="margin-top: 60px;">

    <?php $isAddMode = isset($_GET['action']) && $_GET['action'] == 'add'; ?>

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark"><i class="fas fa-users me-2"></i>Employee Registration</h3>
            <p class="text-muted mb-0">Manage and Register Employees (TL Access)</p>
        </div>
        <div class="d-flex gap-2">
            <?php if (!$isAddMode): ?>
                <button class="btn btn-info text-white fw-bold" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-excel me-2"></i> Import Excel
                </button>
                <a href="employee_registration.php?action=add" class="btn btn-success fw-bold">
                    <i class="fas fa-plus me-2"></i> Add New Employee
                </a>
            <?php else: ?>
                <a href="employee_registration.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i> Back to List
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Messages -->
    <?php if (isset($_GET['msg'])): ?>
        <?php if ($_GET['msg'] == 'added'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i> Employee added successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php elseif ($_GET['msg'] == 'imported'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i> Imported <?= htmlspecialchars($_GET['count']) ?> records successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i> <?= $error ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>


    <!-- ========================================== -->
    <!-- VIEW 1: TABLE VIEW (FIXED NULL ERROR)      -->
    <!-- ========================================== -->
    <?php if (!$isAddMode): ?>
        <div class="card shadow border-0">
            <div class="card-header bg-dark text-white py-3">
                <h5 class="mb-0 fw-bold"><i class="fas fa-list me-2"></i>Employee List</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0 text-center align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="py-3">#</th>
                                <th class="py-3 text-start">Employee Name</th>
                                <th class="py-3">Emp ID</th>
                                <th class="py-3 text-start">Email</th>
                                <th class="py-3">Role</th>
                                <th class="py-3">Join Date</th>
                                <th class="py-3">Process</th>
                                <th class="py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($employees->num_rows > 0): ?>
                                <?php while($row = $employees->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold text-secondary"><?= $row['employee_id'] ?></td>
                                    
                                    <td class="text-start fw-bold">
                                        <?= htmlspecialchars($row['employee_name'] ?? '') ?>
                                    </td>
                                    
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <?= htmlspecialchars($row['employee_username'] ?? '') ?>
                                        </span>
                                    </td>
                                    
                                    <td class="text-start small">
                                        <?= htmlspecialchars($row['email'] ?? '') ?>
                                    </td>
                                    
                                    <td><?= getRoleName($row['user_role_id']) ?></td>
                                    
                                    <td><?= htmlspecialchars($row['joining_date'] ?? '') ?></td>
                                    
                                    <td><?= htmlspecialchars($row['process'] ?? '') ?></td>
                                    
                                    <td>
                                        <span class="badge bg-success">Active</span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="py-5 text-muted">No employees found. Click "Add New Employee" to start.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <!-- ========================================== -->
    <!-- VIEW 2: FORM VIEW (ADD NEW)                -->
    <!-- ========================================== -->
    <?php else: ?>
        <div class="card shadow border-0">
            <div class="card-header bg-primary text-white py-3">
                <h5 class="mb-0 fw-bold"><i class="fas fa-edit me-2"></i>New Employee Details</h5>
            </div>
            <div class="card-body p-4">
                <form method="post" onsubmit="return validateForm()">
                    
                    <h6 class="text-primary fw-bold text-uppercase mb-3 small border-bottom pb-2">Login & Personal Info</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Employee ID <span class="text-danger">*</span></label>
                            <input type="text" name="employee_username" class="form-control" placeholder="e.g. EMP001" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Employee Name <span class="text-danger">*</span></label>
                            <input type="text" name="employee_name" class="form-control" placeholder="Full Name" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" placeholder="email@company.com" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Date of Birth</label>
                            <input type="date" name="dob" class="form-control" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" id="password" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" id="confirm_password" class="form-control" required>
                            <div id="passError" class="text-danger small mt-1" style="display:none;">Passwords do not match!</div>
                        </div>
                    </div>

                    <h6 class="text-primary fw-bold text-uppercase mb-3 small border-bottom pb-2">Organizational Details</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Date of Joining <span class="text-danger">*</span></label>
                            <input type="date" name="joining_date" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">User Role <span class="text-danger">*</span></label>
                            <select name="user_role_id" class="form-select" required>
                                <option value="">Select Role</option>
                                <option value="1">Admin</option>
                                <option value="2">Team Leader (TL)</option>
                                <option value="3">Employee</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Designation <span class="text-danger">*</span></label>
                            <select name="designation_id" class="form-select" required>
                                <option value="">Select Designation</option>
                                <option value="1">Software Engineer</option>
                                <option value="2">Senior Executive</option>
                                <option value="3">Manager</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Team <span class="text-danger">*</span></label>
                            <select name="team_id" class="form-select" required>
                                <option value="">Select Team</option>
                                <option value="1">Development</option>
                                <option value="2">Sales</option>
                                <option value="3">Support</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Reporting Manager</label>
                            <input type="text" name="reporting_manager" class="form-control" placeholder="Manager Name">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Process Name</label>
                            <input type="text" name="process_name" class="form-control" placeholder="e.g. US Mortgage">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Process ID / Type</label>
                            <select name="process_id" class="form-select" required>
                                <option value="1">Process Type 1</option>
                                <option value="2">Process Type 2</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Shift</label>
                            <select name="shift" class="form-select">
                                <option value="Day">Day</option>
                                <option value="Night">Night</option>
                                <option value="Rotational">Rotational</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Referred By</label>
                            <input type="text" name="referred_by" class="form-control" placeholder="Referrer Name">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 border-top pt-3">
                        <button type="reset" class="btn btn-light border px-4">Reset</button>
                        <button type="submit" name="add_employee" class="btn btn-success px-4 fw-bold">
                            <i class="fas fa-save me-2"></i> Save Employee
                        </button>
                    </div>

                </form>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- IMPORT EXCEL MODAL -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-file-import me-2"></i>Import Employees</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form method="post" enctype="multipart/form-data">
                    <div class="text-center mb-4">
                        <i class="fas fa-file-excel fa-3x text-success mb-3"></i>
                        <p class="text-muted">Upload an Excel (.xlsx) file to bulk import employees.</p>
                        <p class="small text-danger fw-bold">Ensure Password is in the Last Column</p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Choose Excel File</label>
                        <input type="file" name="excel_file" class="form-control form-control-lg" accept=".xlsx" required>
                    </div>
                    
                    <div class="alert alert-light border small">
                        <strong>Expected Columns (Order):</strong><br>
                        Name, Emp ID, Email, DOB, DOJ, Role ID, Team ID, Desg ID, Manager, Process Name, Process ID, Shift, Ref By, <strong>Password</strong>.
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" name="import_excel" class="btn btn-info text-white btn-lg fw-bold">
                            <i class="fas fa-upload me-2"></i> Upload & Import
                        </button>
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function validateForm() {
    var pass = document.getElementById("password").value;
    var conf = document.getElementById("confirm_password").value;
    var errorMsg = document.getElementById("passError");

    if (pass !== conf) {
        errorMsg.style.display = "block";
        document.getElementById("confirm_password").focus();
        return false;
    }
    errorMsg.style.display = "none";
    return true;
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>