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

// Salary tracking function
function trackSalaryChange($mysqli, $employee_id, $old_salary, $new_salary, $tds_per, $changed_by) {
    if ($old_salary != $new_salary) {
        $stmt = $mysqli->prepare("INSERT INTO employee_salary_history (employee_id, old_salary, new_salary, tds_per, changed_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iddds", $employee_id, $old_salary, $new_salary, $tds_per, $changed_by);
        return $stmt->execute();
    }
    return true;
}

$today = date('Y-m-d');
$default_dob = date('Y-m-d', strtotime('-20 years'));

// --- POST handler (only for edit) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['employee_id'] ?? 0);
    
    // Only edit operation allowed
    if ($action === 'edit' && $id > 0) {
        // Get form data - only salary related fields
        $employee_name     = trim($_POST['employee_name'] ?? '');
        $employee_username = trim($_POST['employee_username'] ?? '');
        $email             = trim($_POST['email'] ?? '');
        $phone             = trim($_POST['phone'] ?? '');
        $date_of_birth     = trim($_POST['date_of_birth'] ?? '');
        $joining_date      = trim($_POST['joining_date'] ?? '') ?: $today;
        
        // Salary fields only
        $salary            = !empty($_POST['salary']) ? (float)$_POST['salary'] : 0;
        $tds_per           = !empty($_POST['tds_per']) ? (float)$_POST['tds_per'] : 0;
        
        $errors = [];
        
        // Validation
        if (!$employee_name) $errors[] = 'Employee Name is required.';
        if (!$employee_username) $errors[] = 'Username is required.';
        if (!is_valid_date($date_of_birth)) $errors[] = 'Valid Date of Birth is required.';
        if (!is_valid_date($joining_date)) $errors[] = 'Valid Joining Date is required.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid Email is required.';
        if (!$phone || !preg_match('/^\+?\d{10,15}$/', $phone)) $errors[] = 'Phone Number is required.';
        
        if ($salary < 0) {
            $errors[] = 'Salary cannot be negative.';
        }
        
        if ($tds_per < 0 || $tds_per > 100) {
            $errors[] = 'TDS Percentage must be between 0 and 100.';
        }

        if ($errors) redirect_with(['msg' => implode(' ', $errors), 'type' => 'danger']);

        // Get old salary for tracking
        $old_salary_stmt = $mysqli->prepare("SELECT salary, tds_per FROM employee_master WHERE employee_id = ?");
        $old_salary_stmt->bind_param("i", $id);
        $old_salary_stmt->execute();
        $old_data = $old_salary_stmt->get_result()->fetch_assoc();
        $old_salary_stmt->close();
        
        $old_salary = $old_data['salary'] ?? 0;
        $old_tds_per = $old_data['tds_per'] ?? 0;
        
        // Update only basic info and salary
        $update_fields = [];
        $update_params = [];
        $update_types = "";
        
        $update_fields[] = "employee_name = ?"; $update_params[] = $employee_name; $update_types .= "s";
        $update_fields[] = "employee_username = ?"; $update_params[] = $employee_username; $update_types .= "s";
        $update_fields[] = "email = ?"; $update_params[] = $email; $update_types .= "s";
        $update_fields[] = "phone = ?"; $update_params[] = $phone; $update_types .= "s";
        $update_fields[] = "date_of_birth = ?"; $update_params[] = $date_of_birth; $update_types .= "s";
        $update_fields[] = "joining_date = ?"; $update_params[] = $joining_date; $update_types .= "s";
        $update_fields[] = "salary = ?"; $update_params[] = $salary; $update_types .= "d";
        $update_fields[] = "tds_per = ?"; $update_params[] = $tds_per; $update_types .= "d";
        
        $update_params[] = $id; $update_types .= "i";
        
        $sql = "UPDATE employee_master SET " . implode(", ", $update_fields) . " WHERE employee_id = ?";
        
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            redirect_with(['msg' => 'Prepare failed: ' . $mysqli->error, 'type' => 'danger']);
        }
        
        $stmt->bind_param($update_types, ...$update_params);
        
        if ($stmt->execute()) {
            $salary_changed = ($old_salary != $salary) || ($old_tds_per != $tds_per);
            if ($salary_changed) {
                trackSalaryChange($mysqli, $id, $old_salary, $salary, $tds_per, $emp_username);
            }
            
            redirect_with(['msg' => "Employee '{$employee_name}' salary updated successfully" . ($salary_changed ? ' (Salary change tracked)' : ''), 'type' => 'info']);
        } else {
            redirect_with(['msg' => 'Error updating employee salary: ' . $stmt->error, 'type' => 'danger']);
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
        // Fetch employee data
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

// Get employees for DataTable - only salary related info
$sql = "SELECT e.employee_id, e.employee_name, e.employee_username, e.email, e.phone, 
               e.date_of_birth, e.joining_date, e.shift, e.salary, e.tds_per,
               t.team_name, d.designation_name,
               GROUP_CONCAT(p.process_name SEPARATOR ', ') as process_names,
               e.status
        FROM employee_master e 
        LEFT JOIN team_master t ON e.team_id = t.team_id
        LEFT JOIN designation_master d ON e.designation_id = d.designation_id
        LEFT JOIN process_master p ON FIND_IN_SET(p.process_id, e.process)
        GROUP BY e.employee_id
        ORDER BY e.employee_id DESC";

$employees_result = $mysqli->query($sql);

?>
<br><br><br>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Salary Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    
    <style>
        .table th { background-color: #2c3e50; color: white; }
        .salary-cell { text-align: right; font-weight: 500; }
        .tds-cell { text-align: center; font-weight: 500; }
        
        /* DataTable customizations */
        .table-compact th,
        .table-compact td {
            padding: 0.4rem 0.5rem;
            font-size: 0.875rem;
        }
        
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }
        
        /* Compact table styling */
        .dataTables_scrollBody {
            max-height: 600px !important;
        }

        /* Ensure horizontal scrolling works */
        .dataTables_scroll {
            overflow-x: auto;
        }
        
        .badge-success {
            background-color: #28a745;
        }
        
        .badge-secondary {
            background-color: #6c757d;
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
        
        <?php if ($mode === 'edit'): ?>
        
        <!-- Edit Form -->
        <div class="d-flex justify-content-between mb-3">
            <h5><i class="fas fa-user-edit me-2"></i>Edit Employee Salary</h5>
            <a href="employee_master.php" class="btn btn-sm btn-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card p-4 mx-auto" style="max-width:800px;">
            <form method="post" class="row g-3" onsubmit="return validateSalary()">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="employee_id" value="<?= $editRow['employee_id'] ?>">

                <!-- Basic Information -->
                <div class="col-md-6">
                    <label class="form-label">Employee Name *</label>
                    <input type="text" name="employee_name" class="form-control" required value="<?= h($editRow['employee_name'] ?? '') ?>" readonly>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Username *</label>
                    <input type="text" name="employee_username" class="form-control" required value="<?= h($editRow['employee_username'] ?? '') ?>" readonly>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Email *</label>
                    <input type="email" name="email" class="form-control" required value="<?= h($editRow['email'] ?? '') ?>" readonly>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Phone *</label>
                    <input type="text" name="phone" pattern="\+?\d{10,15}" class="form-control" required value="<?= h($editRow['phone'] ?? '') ?>" readonly>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Date of Birth *</label>
                    <input type="date" name="date_of_birth" class="form-control" required value="<?= h($editRow['date_of_birth'] ?? $default_dob) ?>" readonly>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Joining Date *</label>
                    <input type="date" name="joining_date" class="form-control" required value="<?= h($editRow['joining_date'] ?? $today) ?>" readonly>
                </div>

                <!-- Salary and TDS Section -->
                <div class="col-12 mt-4">
                    <h5 class="mb-3 border-bottom pb-2"><i class="fas fa-money-bill-wave me-2"></i>Salary Information</h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Salary (₹) *</label>
                            <input type="number" name="salary" id="salary" class="form-control" 
                                   value="<?= h($editRow['salary'] ?? '0') ?>" 
                                   placeholder="Enter salary" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">TDS Percentage (%) *</label>
                            <input type="number" name="tds_per" id="tds_per" class="form-control" 
                                   value="<?= h($editRow['tds_per'] ?? '0') ?>" 
                                   placeholder="Enter TDS %" step="0.01" min="0" max="100" required>
                            <div class="form-text">0 to 100%</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">TDS Amount (₹)</label>
                            <input type="text" id="tds_amount" class="form-control" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Net Salary (₹)</label>
                            <input type="text" id="net_salary" class="form-control" readonly>
                        </div>
                    </div>
                </div>

                <div class="col-12 mt-3 d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>
                        Update Salary
                    </button>
                    <a href="employee_master.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <script>
        function validateSalary() {
            const salary = parseFloat(document.getElementById('salary').value);
            const tdsPer = parseFloat(document.getElementById('tds_per').value);
            
            if (salary < 0) {
                alert("Salary cannot be negative!");
                return false;
            }
            
            if (tdsPer < 0 || tdsPer > 100) {
                alert("TDS Percentage must be between 0 and 100!");
                return false;
            }
            
            return true;
        }
        
        function calculateSalary() {
            const salary = parseFloat(document.getElementById('salary').value) || 0;
            const tdsPer = parseFloat(document.getElementById('tds_per').value) || 0;
            
            const tdsAmount = (salary * tdsPer) / 100;
            const netSalary = salary - tdsAmount;
            
            document.getElementById('tds_amount').value = '₹' + tdsAmount.toFixed(2);
            document.getElementById('net_salary').value = '₹' + netSalary.toFixed(2);
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('salary').addEventListener('input', calculateSalary);
            document.getElementById('tds_per').addEventListener('input', calculateSalary);
            calculateSalary();
        });
        </script>

        <?php else: ?>

        <!-- View Mode (Salary Details Only) -->
        <div class="d-flex justify-content-between mb-3">
            <h5><i class="fas fa-money-bill-wave me-2"></i>Employee Salary Master</h5>
        </div>

        <!-- Employees Table with DataTable -->
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
                                <th>Process</th>
                                <th>Salary (₹)</th>
                                <th>TDS %</th>
                                <th>TDS Amount (₹)</th>
                                <th>Net Salary (₹)</th>
                                <th>Status</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($employees_result && $employees_result->num_rows > 0): $i=0;?>
                                <?php while($r = $employees_result->fetch_assoc()): $i=$i+1;
                                    $tds_amount = ($r['salary'] * $r['tds_per']) / 100;
                                    $net_salary = $r['salary'] - $tds_amount;
                                ?>
                                <tr>
                                    <td class="fw-bold"><?= $i ?></td>
                                    <td><?= h($r['employee_name']) ?></td>
                                    <td><?= h($r['employee_username']) ?></td>
                                    <td><?= h($r['team_name'] ?: '—') ?></td>
                                    <td><?= h($r['designation_name'] ?: '—') ?></td>
                                    <td>
                                        <?php 
                                        $process_names = $r['process_names'] ?? '';
                                        if (strlen($process_names) > 30) {
                                            echo '<span title="' . h($process_names) . '">' . h(substr($process_names, 0, 30)) . '...</span>';
                                        } else {
                                            echo h($process_names ?: '—');
                                        }
                                        ?>
                                    </td>
                                    <td class="salary-cell">₹<?= number_format($r['salary'], 2) ?></td>
                                    <td class="tds-cell">
                                        <span class="badge bg-warning"><?= $r['tds_per'] ?>%</span>
                                    </td>
                                    <td class="salary-cell text-danger">₹<?= number_format($tds_amount, 2) ?></td>
                                    <td class="salary-cell text-success">₹<?= number_format($net_salary, 2) ?></td>
                                    <td>
                                        <span class="badge <?= $r['status'] == 'Active' ? 'bg-success' : 'bg-secondary' ?>">
                                            <?= h($r['status']) ?>
                                        </span>
                                    </td>

                                    <!-- Actions -->
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="employee_master-old.php?mode=edit&id=<?= $r['employee_id'] ?>" 
                                               class="btn btn-warning" 
                                               title="Edit Salary">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <button type="button" class="btn btn-info" 
                                                    onclick="viewSalaryHistory(<?= $r['employee_id'] ?>, '<?= h($r['employee_name']) ?>')"
                                                    title="View Salary History">
                                                <i class="fas fa-history"></i>
                                            </button>
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
                                            <p class="mb-0">No employee salary data available.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Salary History Modal -->
        <div class="modal fade" id="salaryHistoryModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
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
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    $(document).ready(function() {
        // Initialize DataTable with horizontal scrolling
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
            "scrollX": true,
            "scrollY": "400px",
            "scrollCollapse": true,
            "paging": true,
            "pageLength": 25,
            "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
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
    });

    // Salary History function
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