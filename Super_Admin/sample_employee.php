<?php
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php'; 
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// 1. CHECK LOGIN
if (!isset($_SESSION['username'])) {
    header("Location: ../index.php"); 
    exit;
}

$emp_username = $_SESSION["ename"] ?? '';
$current_user_role = $_SESSION["role_id"] ?? 0;

// 2. HANDLE SALARY UPDATES (Super Admin & TL)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Super Admin salary update (from first script)
    if ($action === 'update_sa_details') {
        $emp_id = (int)$_POST['emp_id'];
        
        // Financials
        $salary     = (float)$_POST['salary'];
        $tds_per    = (float)$_POST['tds_per'];
        
        // Statutory
        $elig_pf    = isset($_POST['is_eligible_pf']) ? 1 : 0;
        $elig_esi   = isset($_POST['is_eligible_esi']) ? 1 : 0;
        
        $uan_no     = $mysqli->real_escape_string($_POST['uan_no']);
        $epf_name   = $mysqli->real_escape_string($_POST['epf_name']);
        $esi_no     = $mysqli->real_escape_string($_POST['esi_no']);
        
        // Exit Details
        $dor_input  = $_POST['dor'];
        $dor        = !empty($dor_input) ? "'".$mysqli->real_escape_string($dor_input)."'" : "NULL";
        
        $notice     = $mysqli->real_escape_string($_POST['notice_period']);
        $remarks    = $mysqli->real_escape_string($_POST['remarks']);

        // Get current salary to track changes
        $old_salary_stmt = $mysqli->prepare("SELECT salary, tds_per FROM employee_master WHERE employee_id = ?");
        $old_salary_stmt->bind_param("i", $emp_id);
        $old_salary_stmt->execute();
        $old_data = $old_salary_stmt->get_result()->fetch_assoc();
        $old_salary_stmt->close();
        
        $old_salary = $old_data['salary'] ?? 0;
        $old_tds_per = $old_data['tds_per'] ?? 0;

        // Update employee master
        $sql = "UPDATE employee_master SET 
                salary = '$salary',
                tds_per = '$tds_per',
                is_eligible_pf = '$elig_pf',
                is_eligible_esi = '$elig_esi',
                uan_no = '$uan_no',
                epf_name = '$epf_name',
                esi_no = '$esi_no',
                dor = $dor,
                notice_period = '$notice',
                remarks = '$remarks'
                WHERE employee_id = $emp_id";

        if ($mysqli->query($sql)) {
            // Track salary change in employee_salary_tds table
            trackSalaryChange($mysqli, $emp_id, $old_salary, $salary, $tds_per, $emp_username);
            header("Location: employee_master.php?msg=salary_updated&type=success"); 
            exit;
        } else {
            die("Error updating record: " . $mysqli->error);
        }
    }
    
    // Handle quick salary update (from grid)
    if ($action === 'quick_salary_update') {
        $emp_id = (int)$_POST['emp_id'];
        $salary = (float)$_POST['salary'];
        $tds_per = (float)$_POST['tds_per'];
        $salary_month = $_POST['salary_month'] ?? date('Y-m');
        $bonus = (float)($_POST['bonus'] ?? 0);
        $deductions = (float)($_POST['deductions'] ?? 0);
        
        // Validate permissions
        if ($current_user_role != 2 && $current_user_role != 8 && $current_user_role != 3) {
            header("Location: employee_master.php?msg=unauthorized&type=danger");
            exit;
        }
        
        // Get current employee data
        $stmt = $mysqli->prepare("SELECT salary as old_salary, tds_per as old_tds, employee_name, employee_username FROM employee_master WHERE employee_id = ?");
        $stmt->bind_param("i", $emp_id);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$employee) {
            header("Location: employee_master.php?msg=employee_not_found&type=danger");
            exit;
        }
        
        // Calculate salary components
        $basic_salary = $salary * 0.5; // 50% basic
        $hra = $salary * 0.2; // 20% HRA
        $conveyance = $salary * 0.1; // 10% conveyance
        $medical = $salary * 0.1; // 10% medical
        $special_allowance = $salary * 0.1; // 10% special allowance
        
        $tds_amount = ($salary * $tds_per) / 100;
        $gross_salary = $salary + $bonus;
        $total_deductions = $tds_amount + $deductions;
        $net_salary = $gross_salary - $total_deductions;
        
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // 1. Update employee_master table
            $update_stmt = $mysqli->prepare("UPDATE employee_master SET salary = ?, tds_per = ? WHERE employee_id = ?");
            $update_stmt->bind_param("ddi", $salary, $tds_per, $emp_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            // 2. Insert into employee_salary_tds table
            $insert_stmt = $mysqli->prepare("INSERT INTO employee_salary_tds (
                employee_id, employee_name, employee_username, 
                salary_date, salary_month, basic_salary, 
                hra, conveyance, medical, special_allowance,
                overtime_hours, overtime_rate, overtime_amount, 
                bonus, deductions, tds_percentage, tds_amount, 
                gross_salary, total_deductions, net_salary, 
                status, created_by
            ) VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?, ?, ?, 'Active', ?)");
            
            $insert_stmt->bind_param(
                "isssdddddddddddds", 
                $emp_id, $employee['employee_name'], $employee['employee_username'],
                $salary_month, $basic_salary, $hra, $conveyance, $medical, $special_allowance,
                $bonus, $deductions, $tds_per, $tds_amount,
                $gross_salary, $total_deductions, $net_salary, $emp_username
            );
            $insert_stmt->execute();
            $insert_stmt->close();
            
            // 3. Track salary change in history
            trackSalaryChange($mysqli, $emp_id, $employee['old_salary'], $salary, $tds_per, $emp_username);
            
            $mysqli->commit();
            header("Location: employee_master.php?msg=salary_updated_success&type=success");
            exit;
            
        } catch (Exception $e) {
            $mysqli->rollback();
            header("Location: employee_master.php?msg=error_updating_salary&type=danger");
            exit;
        }
    }
    
    // Existing POST handlers from second script...
    // ... [Keep all existing POST handlers from second script] ...
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function redirect_with($params = []) {
    $base = 'employee_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base"); 
    exit;
}

function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

function formatDate($d) {
    return (!empty($d) && $d != '0000-00-00') ? date('d-m-Y', strtotime($d)) : '—';
}

// Salary tracking function (updated)
function trackSalaryChange($mysqli, $employee_id, $old_salary, $new_salary, $tds_per, $changed_by) {
    if ($old_salary != $new_salary) {
        $stmt = $mysqli->prepare("INSERT INTO employee_salary_history (employee_id, old_salary, new_salary, tds_per, changed_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iddds", $employee_id, $old_salary, $new_salary, $tds_per, $changed_by);
        return $stmt->execute();
    }
    return true;
}

// AJAX handlers
if (isset($_GET['ajax'])) {
    if ($_GET['ajax'] === 'get_employee_salary_data') {
        $emp_id = (int)($_GET['emp_id'] ?? 0);
        
        // Get employee data
        $stmt = $mysqli->prepare("SELECT 
            e.employee_id, e.employee_name, e.employee_username,
            e.salary, e.tds_per, e.team_id, e.designation_id,
            t.team_name, d.designation_name
            FROM employee_master e
            LEFT JOIN team_master t ON e.team_id = t.team_id
            LEFT JOIN designation_master d ON e.designation_id = d.designation_id
            WHERE e.employee_id = ?");
        $stmt->bind_param("i", $emp_id);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // Get latest salary record
        $stmt = $mysqli->prepare("SELECT * FROM employee_salary_tds 
            WHERE employee_id = ? 
            ORDER BY salary_date DESC LIMIT 1");
        $stmt->bind_param("i", $emp_id);
        $stmt->execute();
        $salary_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $response = [
            'employee' => $employee,
            'salary_data' => $salary_data
        ];
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// Fetch employees for DataTable
$sql = "SELECT 
    e.*,
    t.team_name, 
    d.designation_name,
    r.role_name,
    GROUP_CONCAT(DISTINCT p.process_name SEPARATOR ', ') as process_names,
    (SELECT COUNT(*) FROM employee_salary_tds est WHERE est.employee_id = e.employee_id) as salary_records_count,
    (SELECT MAX(salary_date) FROM employee_salary_tds est WHERE est.employee_id = e.employee_id) as last_salary_update
    FROM employee_master e 
    LEFT JOIN user_role_master r ON e.user_role_id = r.role_id
    LEFT JOIN team_master t ON e.team_id = t.team_id
    LEFT JOIN designation_master d ON e.designation_id = d.designation_id
    LEFT JOIN process_master p ON FIND_IN_SET(p.process_id, e.process)
    GROUP BY e.employee_id
    ORDER BY e.employee_id DESC";

$employees_result = $mysqli->query($sql);

// Get roles, teams, designations for filters
$roles = $mysqli->query("SELECT role_id, role_name FROM user_role_master WHERE role_name!='Super Admin' ORDER BY role_name");
$teams = $mysqli->query("SELECT team_id, team_name FROM team_master WHERE status='Active' ORDER BY team_name");
$designations = $mysqli->query("SELECT designation_id, designation_name FROM designation_master WHERE status='Active' ORDER BY designation_name");

require 'header.php';
?>

<div class="container-fluid py-4" style="margin-top: 60px;">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark"><i class="fas fa-users me-2"></i>Employee Master</h3>
            <p class="text-muted mb-0">Manage all employee details including salary, statutory, and exit information.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="employee_master.php?mode=add" class="btn btn-success">
                <i class="fas fa-plus me-2"></i> Add Employee
            </a>
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i> Back
            </a>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): 
        $msg_types = [
            'salary_updated' => ['type' => 'success', 'text' => 'Salary updated successfully!'],
            'salary_updated_success' => ['type' => 'success', 'text' => 'Salary updated and recorded successfully!'],
            'updated' => ['type' => 'success', 'text' => 'Employee details updated successfully!'],
            'unauthorized' => ['type' => 'danger', 'text' => 'You are not authorized to perform this action.'],
            'error_updating_salary' => ['type' => 'danger', 'text' => 'Error updating salary. Please try again.']
        ];
        
        if (isset($msg_types[$_GET['msg']])) {
            $msg = $msg_types[$_GET['msg']];
        } else {
            $msg = ['type' => $_GET['type'] ?? 'info', 'text' => $_GET['msg']];
        }
    ?>
        <div class="alert alert-<?= h($msg['type']) ?> alert-dismissible fade show" role="alert">
            <i class="fas <?= $msg['type'] == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> me-2"></i>
            <?= h($msg['text']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Employee Master DataTable -->
    <div class="card shadow border-0">
        <div class="card-header bg-primary text-white py-3">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold"><i class="fas fa-table me-2"></i>Employee Master List</h5>
                <div class="d-flex gap-2">
                    <button class="btn btn-light btn-sm" onclick="exportToExcel()">
                        <i class="fas fa-file-excel me-1"></i> Export
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="employeesTable" class="table table-hover table-bordered mb-0 align-middle" style="width:100%">
                    <thead class="table-light">
                        <tr>
                            <th width="50">#</th>
                            <th>Employee ID</th>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Team</th>
                            <th>Designation</th>
                            <th>Salary (₹)</th>
                            <th>TDS %</th>
                            <th>TDS Amt (₹)</th>
                            <th>PF/ESI</th>
                            <th>Bank Details</th>
                            <th>Last Salary Update</th>
                            <th>Records</th>
                            <th>Status</th>
                            <th width="220" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($employees_result->num_rows > 0): $i=0;?>
                            <?php while($row = $employees_result->fetch_assoc()): $i++; 
                                $tds_amount = ($row['salary'] * $row['tds_per']) / 100;
                                $has_bank = !empty($row['account_no']) && !empty($row['ifsc_code']);
                            ?>
                            <tr>
                                <td class="fw-bold"><?= $i ?></td>
                                <td>
                                    <span class="badge bg-info"><?= h($row['employee_username']) ?></span>
                                </td>
                                <td>
                                    <div class="fw-bold"><?= h($row['employee_name'] ?? '') ?></div>
                                    <small class="text-muted"><?= h($row['email'] ?? '') ?></small><br>
                                    <small class="text-muted"><?= h($row['phone'] ?? '') ?></small>
                                </td>
                                <td>
                                    <?php if($row['user_role_id'] == 1) echo '<span class="badge bg-danger">Admin</span>';
                                          elseif($row['user_role_id'] == 2) echo '<span class="badge bg-danger">Super Admin</span>';
                                          elseif($row['user_role_id'] == 3) echo '<span class="badge bg-warning text-dark">TL</span>';
                                          elseif($row['user_role_id'] == 8) echo '<span class="badge bg-primary">Admin</span>';
                                          else echo '<span class="badge bg-secondary">Emp</span>'; ?>
                                </td>
                                <td><?= h($row['team_name'] ?? '—') ?></td>
                                <td><?= h($row['designation_name'] ?? '—') ?></td>
                                <td class="text-end fw-bold text-success">
                                    ₹<?= number_format($row['salary'], 2) ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-warning text-dark"><?= $row['tds_per'] ?>%</span>
                                </td>
                                <td class="text-end text-danger">
                                    ₹<?= number_format($tds_amount, 2) ?>
                                </td>
                                <td>
                                    <?php if($row['is_eligible_pf']): ?>
                                        <span class="badge bg-success me-1" title="PF Eligible">PF</span>
                                    <?php endif; ?>
                                    <?php if($row['is_eligible_esi']): ?>
                                        <span class="badge bg-primary" title="ESI Eligible">ESI</span>
                                    <?php endif; ?>
                                    <?php if(!$row['is_eligible_pf'] && !$row['is_eligible_esi']): ?>
                                        <span class="text-muted small">NA</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($has_bank): ?>
                                        <span class="badge bg-success">✓</span>
                                        <small class="text-muted d-block"><?= substr(h($row['account_no']), -4) ?></small>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">✗</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($row['last_salary_update'])): ?>
                                        <?= date('d-m-Y', strtotime($row['last_salary_update'])) ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if($row['salary_records_count'] > 0): ?>
                                        <span class="badge bg-info"><?= $row['salary_records_count'] ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($row['dor'])): ?>
                                        <span class="badge bg-secondary">Resigned</span>
                                        <div class="small text-danger"><?= date('d-m-Y', strtotime($row['dor'])) ?></div>
                                    <?php else: ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <!-- Edit Employee Button -->
                                        <a href="employee_master.php?mode=edit&id=<?= $row['employee_id'] ?>" 
                                           class="btn btn-warning" title="Edit Employee">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <!-- Quick Salary Update Button -->
                                        <?php if($current_user_role == 2 || $current_user_role == 8 || $current_user_role == 3): ?>
                                        <button class="btn btn-primary quick-salary-btn" 
                                                data-id="<?= $row['employee_id'] ?>"
                                                title="Update Salary">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </button>
                                        <?php endif; ?>
                                        
                                        <!-- Super Admin Edit Button -->
                                        <?php if($current_user_role == 2 || $current_user_role == 8): ?>
                                        <button class="btn btn-info sa-edit-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#saEditModal"
                                                data-id="<?= $row['employee_id'] ?>"
                                                data-name="<?= h($row['employee_name'] ?? '') ?>"
                                                data-salary="<?= $row['salary'] ?>"
                                                data-tds="<?= $row['tds_per'] ?>"
                                                data-pf="<?= $row['is_eligible_pf'] ?>"
                                                data-esi="<?= $row['is_eligible_esi'] ?>"
                                                data-uan="<?= h($row['uan_no'] ?? '') ?>"
                                                data-epfname="<?= h($row['epf_name'] ?? '') ?>"
                                                data-esino="<?= h($row['esi_no'] ?? '') ?>"
                                                data-dor="<?= $row['dor'] ?>"
                                                data-notice="<?= h($row['notice_period'] ?? '') ?>"
                                                data-remarks="<?= h($row['remarks'] ?? '') ?>"
                                                title="Super Admin Edit">
                                            <i class="fas fa-user-shield"></i>
                                        </button>
                                        <?php endif; ?>
                                        
                                        <!-- View Salary History -->
                                        <button type="button" class="btn btn-secondary" 
                                                onclick="viewSalaryHistory(<?= $row['employee_id'] ?>, '<?= h($row['employee_name']) ?>')"
                                                title="View Salary History">
                                            <i class="fas fa-history"></i>
                                        </button>
                                        
                                        <!-- Delete Button -->
                                        <?php if($current_user_role == 2 || $current_user_role == 8): ?>
                                        <form method="post" style="display:inline" 
                                              onsubmit="return confirm('Are you sure you want to delete <?= h($row['employee_name']) ?>?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="employee_id" value="<?= $row['employee_id'] ?>">
                                            <button type="submit" class="btn btn-danger" title="Delete Employee">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="15" class="text-center py-5">
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
</div>

<!-- Quick Salary Update Modal -->
<div class="modal fade" id="quickSalaryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-money-bill-wave me-2"></i>Update Salary</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="quickSalaryForm" method="post">
                    <input type="hidden" name="action" value="quick_salary_update">
                    <input type="hidden" name="emp_id" id="qs_emp_id">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Employee</label>
                        <div id="qs_employee_info" class="alert alert-info py-2">
                            <i class="fas fa-user me-2"></i>
                            <span id="qs_employee_name"></span>
                        </div>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Salary Month</label>
                            <input type="month" name="salary_month" id="qs_salary_month" 
                                   class="form-control" value="<?= date('Y-m') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Salary (₹)</label>
                            <input type="number" step="0.01" name="salary" id="qs_salary" 
                                   class="form-control" required min="0">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">TDS Percentage (%)</label>
                            <input type="number" step="0.01" name="tds_per" id="qs_tds_per" 
                                   class="form-control" required min="0" max="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Bonus (₹)</label>
                            <input type="number" step="0.01" name="bonus" id="qs_bonus" 
                                   class="form-control" value="0" min="0">
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Other Deductions (₹)</label>
                            <input type="number" step="0.01" name="deductions" id="qs_deductions" 
                                   class="form-control" value="0" min="0">
                            <div class="form-text">Additional deductions besides TDS</div>
                        </div>
                    </div>
                    
                    <div class="mt-4 d-grid">
                        <button type="submit" class="btn btn-success btn-lg fw-bold">
                            <i class="fas fa-save me-2"></i> Update Salary
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- SUPER ADMIN EDIT MODAL -->
<div class="modal fade" id="saEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-shield me-2"></i>Update Admin Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="post">
                    <input type="hidden" name="action" value="update_sa_details">
                    <input type="hidden" name="emp_id" id="edit_emp_id">
                    
                    <h5 class="text-primary border-bottom pb-2 mb-3">
                        Employee: <span id="display_name" class="fw-bold text-dark"></span>
                    </h5>

                    <!-- 1. FINANCIALS -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Salary (CTC) ₹</label>
                            <input type="number" step="0.01" name="salary" id="edit_salary" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">TDS Percentage (%)</label>
                            <input type="number" step="0.01" name="tds_per" id="edit_tds" class="form-control" required min="0" max="100">
                        </div>
                    </div>

                    <!-- 2. STATUTORY (PF/ESI) -->
                    <h6 class="text-primary fw-bold bg-light p-2 border rounded">Statutory Details</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="is_eligible_pf" id="edit_pf_check">
                                <label class="form-check-label fw-bold" for="edit_pf_check">Is Eligible for PF?</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="is_eligible_esi" id="edit_esi_check">
                                <label class="form-check-label fw-bold" for="edit_esi_check">Is Eligible for ESI?</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">UAN Number</label>
                            <input type="text" name="uan_no" id="edit_uan" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Name in EPF</label>
                            <input type="text" name="epf_name" id="edit_epfname" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">ESI Number</label>
                            <input type="text" name="esi_no" id="edit_esino" class="form-control">
                        </div>
                    </div>

                    <!-- 3. EXIT DETAILS -->
                    <h6 class="text-secondary fw-bold bg-light p-2 border rounded">Exit / Separation Details</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Date of Resignation (DOR)</label>
                            <input type="date" name="dor" id="edit_dor" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Notice Period</label>
                            <input type="text" name="notice_period" id="edit_notice" class="form-control" placeholder="e.g. 30 Days">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Remarks</label>
                            <textarea name="remarks" id="edit_remarks" class="form-control" rows="2" placeholder="Admin remarks..."></textarea>
                        </div>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg fw-bold">
                            <i class="fas fa-save me-2"></i> Update Admin Details
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Salary History Modal -->
<div class="modal fade" id="salaryHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Salary History - <span id="historyEmployeeName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="salaryHistoryContent">
                    Loading salary history...
                </div>
            </div>
        </div>
    </div>
</div>

<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    var table = $('#employeesTable').DataTable({
        "dom": '<"row"<"col-md-6"B><"col-md-6"f>>rt<"row"<"col-md-6"l><"col-md-6"p>>',
        "buttons": [
            {
                extend: 'copy',
                className: 'btn btn-sm btn-secondary',
                text: '<i class="fas fa-copy me-1"></i>Copy'
            },
            {
                extend: 'excel',
                className: 'btn btn-sm btn-success',
                text: '<i class="fas fa-file-excel me-1"></i>Excel'
            },
            {
                extend: 'pdf',
                className: 'btn btn-sm btn-danger',
                text: '<i class="fas fa-file-pdf me-1"></i>PDF'
            },
            {
                extend: 'print',
                className: 'btn btn-sm btn-info',
                text: '<i class="fas fa-print me-1"></i>Print'
            }
        ],
        "responsive": true,
        "pageLength": 25,
        "order": [[0, 'asc']],
        "language": {
            "search": "Search employees:",
            "lengthMenu": "Show _MENU_ entries",
            "info": "Showing _START_ to _END_ of _TOTAL_ employees",
            "infoEmpty": "Showing 0 to 0 of 0 employees",
            "infoFiltered": "(filtered from _MAX_ total employees)",
            "paginate": {
                "first": "First",
                "last": "Last",
                "next": "Next",
                "previous": "Previous"
            }
        }
    });

    // Style DataTable elements
    $('.dataTables_filter input').addClass('form-control form-control-sm');
    $('.dataTables_length select').addClass('form-select form-select-sm');

    // Quick Salary Update
    $(document).on('click', '.quick-salary-btn', function() {
        const empId = $(this).data('id');
        $('#qs_emp_id').val(empId);
        
        // Load employee data
        fetch(`employee_master.php?ajax=get_employee_salary_data&emp_id=${empId}`)
            .then(response => response.json())
            .then(data => {
                if (data.employee) {
                    const emp = data.employee;
                    $('#qs_employee_name').text(`${emp.employee_name} (${emp.employee_username}) - ${emp.designation_name}`);
                    $('#qs_salary').val(emp.salary);
                    $('#qs_tds_per').val(emp.tds_per);
                    
                    // If there's existing salary data, pre-fill
                    if (data.salary_data) {
                        $('#qs_bonus').val(data.salary_data.bonus || 0);
                        $('#qs_deductions').val(data.salary_data.deductions || 0);
                    }
                }
            });
        
        $('#quickSalaryModal').modal('show');
    });

    // Super Admin Edit
    $(document).on('click', '.sa-edit-btn', function() {
        document.getElementById('edit_emp_id').value = this.getAttribute('data-id');
        document.getElementById('display_name').textContent = this.getAttribute('data-name');
        document.getElementById('edit_salary').value = this.getAttribute('data-salary');
        document.getElementById('edit_tds').value = this.getAttribute('data-tds');
        document.getElementById('edit_pf_check').checked = (this.getAttribute('data-pf') == '1');
        document.getElementById('edit_esi_check').checked = (this.getAttribute('data-esi') == '1');
        document.getElementById('edit_uan').value = this.getAttribute('data-uan');
        document.getElementById('edit_epfname').value = this.getAttribute('data-epfname');
        document.getElementById('edit_esino').value = this.getAttribute('data-esino');
        document.getElementById('edit_dor').value = this.getAttribute('data-dor');
        document.getElementById('edit_notice').value = this.getAttribute('data-notice');
        document.getElementById('edit_remarks').value = this.getAttribute('data-remarks');
    });
});

// Export to Excel
function exportToExcel() {
    const table = $('#employeesTable').DataTable();
    table.button('.buttons-excel').trigger();
}

// View Salary History
function viewSalaryHistory(employeeId, employeeName) {
    document.getElementById('historyEmployeeName').textContent = employeeName;
    
    document.getElementById('salaryHistoryContent').innerHTML = 
        '<div class="text-center"><div class="spinner-border"></div><p>Loading salary history...</p></div>';
    
    fetch(`get_salary_history.php?employee_id=${employeeId}`)
        .then(response => response.text())
        .then(data => {
            document.getElementById('salaryHistoryContent').innerHTML = data;
        })
        .catch(error => {
            document.getElementById('salaryHistoryContent').innerHTML = 
                '<div class="alert alert-danger">Error loading salary history</div>';
        });
    
    new bootstrap.Modal(document.getElementById('salaryHistoryModal')).show();
}
</script>
</body>
</html>