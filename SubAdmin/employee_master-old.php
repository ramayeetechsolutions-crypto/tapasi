<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}
$emp_username = $_SESSION["ename"] ?? '';

// Helpers
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function redirect_with($params = []) {
    $base = 'employee_master-old.php';
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

// Fetch dropdown data
$teams = $designations = $user_roles = $reporting_managers = [];

// Get teams
$team_result = $mysqli->query("SELECT team_id, team_name FROM team_master WHERE status='Active' ORDER BY team_name");
while ($row = $team_result->fetch_assoc()) {
    $teams[$row['team_id']] = $row['team_name'];
}

// Get designations
$desig_result = $mysqli->query("SELECT designation_id, designation_name FROM designation_master WHERE status='Active' ORDER BY designation_name");
while ($row = $desig_result->fetch_assoc()) {
    $designations[$row['designation_id']] = $row['designation_name'];
}

// Get user roles
$role_result = $mysqli->query("SELECT role_id, role_name FROM user_role_master WHERE status='Active' ORDER BY role_name");
while ($row = $role_result->fetch_assoc()) {
    $user_roles[$row['role_id']] = $row['role_name'];
}

// Get reporting managers - only employees with user_role_id = 3
$manager_result = $mysqli->query("SELECT employee_id, employee_name FROM employee_master WHERE status='Active' AND user_role_id = 3 ORDER BY employee_name");
while ($row = $manager_result->fetch_assoc()) {
    $reporting_managers[$row['employee_id']] = $row['employee_name'];
}

// --- POST handler (only for edit) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['employee_id'] ?? 0);
    
    // Only edit operation allowed
    if ($action === 'edit' && $id > 0) {
        $employee_name     = trim($_POST['employee_name'] ?? '');
        $employee_username = trim($_POST['employee_username'] ?? '');
        $email             = trim($_POST['email'] ?? '');
        $phone             = trim($_POST['phone'] ?? '');
        $date_of_birth     = trim($_POST['date_of_birth'] ?? '');
        $joining_date      = trim($_POST['joining_date'] ?? '') ?: $today;
        
        $team_id           = (int)($_POST['team_id'] ?? 0);
        $designation_id    = (int)($_POST['designation_id'] ?? 0);
        $user_role_id      = (int)($_POST['user_role_id'] ?? 0);
        $reporting_manager = !empty($_POST['reporting_manager']) ? (int)$_POST['reporting_manager'] : null;
        $shift             = trim($_POST['shift'] ?? '');
        $status            = trim($_POST['status'] ?? 'Active');
        
        $errors = [];
        

// $reporting_manager = !empty($_POST['reporting_manager']) ? (int)$_POST['reporting_manager'] : null;
$r_name = '';

if ($reporting_manager) {
    // Using the same MySQLi connection ($mysqli) from your existing code
    $sql = "SELECT employee_name FROM employee_master WHERE employee_id = " . $reporting_manager;
    $result = $mysqli->query($sql);
    
    if ($result && $row = $result->fetch_assoc()) {
        $r_name = $row['employee_name'];
    }
}

        if (!$employee_name) $errors[] = 'Employee Name is required.';
        if (!$employee_username) $errors[] = 'Username is required.';
        if (!is_valid_date($date_of_birth)) $errors[] = 'Valid Date of Birth is required.';
        if (!is_valid_date($joining_date)) $errors[] = 'Valid Joining Date is required.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid Email is required.';
        if (!$phone || !preg_match('/^\+?\d{10,15}$/', $phone)) $errors[] = 'Phone Number is required.';
        
        if ($team_id <= 0) $errors[] = 'Please select a Team.';
        if ($designation_id <= 0) $errors[] = 'Please select a Designation.';
        if ($user_role_id <= 0) $errors[] = 'Please select a User Role.';
        if (!$shift) $errors[] = 'Shift is required.';
        if (!$status) $errors[] = 'Status is required.';

        if ($errors) redirect_with(['msg' => implode(' ', $errors), 'type' => 'danger']);

        $sql = "UPDATE employee_master SET 
                employee_name = ?,
                employee_username = ?,
                email = ?,
                phone = ?,
                date_of_birth = ?,
                joining_date = ?,
                team_id = ?,
                designation_id = ?,
                user_role_id = ?,
                reporting_manager = ?,
                shift = ?,
                status = ?
                WHERE employee_id = ?";
        
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            redirect_with(['msg' => 'Prepare failed: ' . $mysqli->error, 'type' => 'danger']);
        }
        
        // FIXED: Corrected bind_param type string to 13 characters: ssssssiiiissi
        $stmt->bind_param(
            "ssssssiiisssi", 
            $employee_name, 
            $employee_username, 
            $email, 
            $phone, 
            $date_of_birth, 
            $joining_date,
            $team_id, 
            $designation_id, 
            $user_role_id, 
            $r_name, 
            $shift, 
            $status,
            $id
        );
        
        if ($stmt->execute()) {
            redirect_with(['msg' => "Employee '{$employee_name}' details updated successfully", 'type' => 'success']);
        } else {
            redirect_with(['msg' => 'Error updating employee details: ' . $stmt->error, 'type' => 'danger']);
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
        
        if (!$editRow) {
            $mode = 'view';
        }
    } else {
        $mode = 'view';
    }
}

// Form logic to handle '0000-00-00' empty dates so the HTML datepicker functions perfectly
$emp_dob = (!empty($editRow['date_of_birth']) && $editRow['date_of_birth'] != '0000-00-00') ? $editRow['date_of_birth'] : $default_dob;
$emp_doj = (!empty($editRow['joining_date']) && $editRow['joining_date'] != '0000-00-00') ? $editRow['joining_date'] : $today;

// Get employees for DataTable
$sql = "SELECT e.employee_id, e.employee_name, e.employee_username, e.email, e.phone, 
               e.date_of_birth, e.joining_date, e.shift, e.status, e.reporting_manager,
               t.team_name, d.designation_name, ur.role_name as user_role,
               rm.employee_name as reporting_manager_name,e.reporting_manager as reporting_manager,
               e.salary, e.tds_per
        FROM employee_master e 
        LEFT JOIN team_master t ON e.team_id = t.team_id
        LEFT JOIN designation_master d ON e.designation_id = d.designation_id
        LEFT JOIN user_role_master ur ON e.user_role_id = ur.role_id
        LEFT JOIN employee_master rm ON e.reporting_manager = rm.employee_id
        ORDER BY e.employee_id DESC";

$employees_result = $mysqli->query($sql);
?>
<br><br><br>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- DataTables & Select2 CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    
    <style>
        .table th { background-color: #2c3e50; color: white; }
        .salary-cell { text-align: right; font-weight: 500; }
        .tds-cell { text-align: center; font-weight: 500; }
        .table-compact th, .table-compact td { padding: 0.4rem 0.5rem; font-size: 0.875rem; }
        .dataTables_scrollBody { max-height: 600px !important; }
        .dataTables_scroll { overflow-x: auto; }
        .select2-container--bootstrap-5 .select2-selection { min-height: 38px; }
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
        
        <?php if ($mode === 'edit' && $editRow): ?>
        
        <!-- Edit Form -->
        <div class="d-flex justify-content-between mb-3">
            <h5><i class="fas fa-user-edit me-2"></i>Edit Employee Details</h5>
            <a href="employee_master-old.php" class="btn btn-sm btn-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card p-4 mx-auto" style="max-width:800px;">
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="employee_id" value="<?= $editRow['employee_id'] ?>">

                <div class="col-md-6">
                    <label class="form-label">Employee Name *</label>
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
                    <input type="text" name="phone" class="form-control" required value="<?= h($editRow['phone'] ?? '') ?>">
                </div>

                <!-- Date Of Birth - properly fetched -->
                <div class="col-md-6">
                    <label class="form-label">Date of Birth *</label>
                    <input type="date" name="date_of_birth" class="form-control" required value="<?= h($emp_dob) ?>">
                </div>

                <!-- Date Of Joining - properly fetched -->
                <div class="col-md-6">
                    <label class="form-label">Joining Date *</label>
                    <input type="date" name="joining_date" class="form-control" required value="<?= h($emp_doj) ?>">
                </div>

                <!-- Team, Role, Designation Section -->
                <div class="col-12 mt-4">
                    <h5 class="mb-3 border-bottom pb-2"><i class="fas fa-user-tie me-2"></i>Work Details</h5>
                    <div class="row g-3">
                        
                        <!-- Team - properly fetched -->
                        <div class="col-md-6">
                            <label class="form-label">Team *</label>
                            <select name="team_id" class="form-select select2-field" required>
                                <option value="">Select Team</option>
                                <?php foreach($teams as $id => $name): ?>
                                <option value="<?= $id ?>" <?= ($editRow['team_id'] ?? 0) == $id ? 'selected' : '' ?>>
                                    <?= h($name) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Designation - properly fetched -->
                        <div class="col-md-6">
                            <label class="form-label">Designation *</label>
                            <select name="designation_id" class="form-select select2-field" required>
                                <option value="">Select Designation</option>
                                <?php foreach($designations as $id => $name): ?>
                                <option value="<?= $id ?>" <?= ($editRow['designation_id'] ?? 0) == $id ? 'selected' : '' ?>>
                                    <?= h($name) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">User Role *</label>
                            <select name="user_role_id" class="form-select select2-field" required>
                                <option value="">Select User Role</option>
                                <?php foreach($user_roles as $id => $name): ?>
                                <option value="<?= $id ?>" <?= ($editRow['user_role_id'] ?? 0) == $id ? 'selected' : '' ?>>
                                    <?= h($name) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Reporting Manager - properly fetched -->
                        <div class="col-md-6">
                            <label class="form-label">Reporting Manager</label>
                            <select name="reporting_manager" class="form-select select2-field">
                                <option value="">Select Reporting Manager</option>
                                <?php foreach($reporting_managers as $id => $name): ?>
                                <option value="<?= $id ?>" <?= ($editRow['reporting_manager'] ?? '') == h($name) ? 'selected' : '' ?>>
                                    <?= h($name) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Shift *</label>
                            <select name="shift" class="form-select" required>
                                <option value="">Select Shift</option>
                                <option value="Day" <?= ($editRow['shift'] ?? '') == 'Day' ? 'selected' : '' ?>>Day</option>
                                <option value="Night" <?= ($editRow['shift'] ?? '') == 'Night' ? 'selected' : '' ?>>Night</option>
                                <option value="General" <?= ($editRow['shift'] ?? '') == 'General' ? 'selected' : '' ?>>General</option>
                                <option value="Rotational" <?= ($editRow['shift'] ?? '') == 'Rotational' ? 'selected' : '' ?>>Rotational</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Status *</label>
                            <select name="status" class="form-select" required>
                                <option value="Active" <?= ($editRow['status'] ?? '') == 'Active' ? 'selected' : '' ?>>Active</option>
                                <option value="Inactive" <?= ($editRow['status'] ?? '') == 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                <option value="On Leave" <?= ($editRow['status'] ?? '') == 'On Leave' ? 'selected' : '' ?>>On Leave</option>
                                <option value="Terminated" <?= ($editRow['status'] ?? '') == 'Terminated' ? 'selected' : '' ?>>Terminated</option>
                                <option value="Resigned" <?= ($editRow['status'] ?? '') == 'Resigned' ? 'selected' : '' ?>>Resigned</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="col-12 mt-3 d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>
                        Update Details
                    </button>
                    <a href="employee_master-old.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between mb-3">
            <h5><i class="fas fa-users me-2"></i>Employee Master</h5>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="employeesTable" class="table table-hover align-middle mb-0 table-compact" style="width:100%">
                        <thead class="table-light">
                            <tr>
                                <th width="50">#</th>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Team</th>
                                <th>Designation</th>
                                <th>User Role</th>
                                <th>Reporting Manager</th>
                                <th>Shift</th>
                                <th>Salary (₹)</th>
                                <th>TDS %</th>
                                <th>Status</th>
                                <th width="100" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($employees_result && $employees_result->num_rows > 0): $i=0;?>
                                <?php while($r = $employees_result->fetch_assoc()): $i=$i+1; ?>
                                <tr>
                                    <td class="fw-bold"><?= $i ?></td>
                                    <td><?= h($r['employee_name']) ?></td>
                                    <td><?= h($r['employee_username']) ?></td>
                                    <td><?= h($r['team_name'] ?: '—') ?></td>
                                    <td><?= h($r['designation_name'] ?: '—') ?></td>
                                    <td><?= h($r['user_role'] ?: '—') ?></td>
                                    <td><?= h($r['reporting_manager'] ?: '—') ?></td>
                                    <td><?= h($r['shift'] ?: '—') ?></td>
                                    <td class="salary-cell">₹<?= number_format($r['salary'] ?? 0, 2) ?></td>
                                    <td class="tds-cell">
                                        <?php if ($r['tds_per'] > 0): ?>
                                        <span class="badge bg-warning"><?= $r['tds_per'] ?>%</span>
                                        <?php else: ?>
                                        —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $status_class = match($r['status']) {
                                            'Active' => 'bg-success',
                                            'Inactive' => 'bg-secondary',
                                            'On Leave' => 'bg-info',
                                            'Terminated' => 'bg-danger',
                                            'Resigned' => 'bg-warning',
                                            default => 'bg-secondary'
                                        };
                                        ?>
                                        <span class="badge <?= $status_class ?>">
                                            <?= h($r['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="employee_master-old.php?mode=edit&id=<?= $r['employee_id'] ?>" 
                                               class="btn btn-warning" 
                                               title="Edit Details">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="12" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-users fa-3x mb-3"></i>
                                            <h5>No employees found</h5>
                                            <p class="mb-0">No employee data available.</p>
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
    </div>

    <!-- DataTables & jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
    
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    $(document).ready(function() {
        // Initialize Select2 for dropdowns
        $('.select2-field').select2({
            theme: 'bootstrap-5',
            width: '100%'
        });
        
        // Initialize DataTable
        var table = $('#employeesTable').DataTable({
            "dom": '<"row"<"col-md-6"B><"col-md-6"f>>rt<"row"<"col-md-6"l><"col-md-6"p>>',
            "buttons": [
                { extend: 'copy', className: 'btn btn-sm btn-secondary', text: '<i class="fas fa-copy me-1"></i>Copy' },
                { extend: 'excel', className: 'btn btn-sm btn-success', text: '<i class="fas fa-file-excel me-1"></i>Excel' },
                { extend: 'pdf', className: 'btn btn-sm btn-danger', text: '<i class="fas fa-file-pdf me-1"></i>PDF' },
                { extend: 'print', className: 'btn btn-sm btn-info', text: '<i class="fas fa-print me-1"></i>Print' }
            ],
            "scrollX": true,
            "scrollY": "400px",
            "scrollCollapse": true,
            "paging": true,
            "pageLength": 25,
            "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            "order": [[0, 'asc']]
        });

        // Style DataTable elements
        $('.dataTables_filter input').addClass('form-control form-control-sm');
        $('.dataTables_length select').addClass('form-select form-select-sm');
    });
    </script>
</body>
</html>