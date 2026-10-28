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
    $base = 'salary.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Get all employees with TDS for dropdown
$employees_result = $mysqli->query("
    SELECT employee_id, employee_name, employee_username, salary, tds_per, designation_id, team_id 
    FROM employee_master 
    WHERE tds_per > 0 AND salary IS NOT NULL AND status = 'Active' 
    ORDER BY employee_name
");
$employees = [];
while($row = $employees_result->fetch_assoc()) {
    $employees[$row['employee_id']] = $row;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'generate' || $action === 'edit') {
        $employee_id = trim($_POST['employee_id'] ?? '');
        $salary_date = $_POST['salary_date'] ?? '';
        $salary_month = trim($_POST['salary_month'] ?? '');
        $basic_salary = !empty($_POST['basic_salary']) ? (float)$_POST['basic_salary'] : 0;
        $overtime_hours = !empty($_POST['overtime_hours']) ? (float)$_POST['overtime_hours'] : 0;
        $overtime_rate = !empty($_POST['overtime_rate']) ? (float)$_POST['overtime_rate'] : 0;
        $bonus = !empty($_POST['bonus']) ? (float)$_POST['bonus'] : 0;
        $deductions = !empty($_POST['deductions']) ? (float)$_POST['deductions'] : 0;
        
        // Basic validation
        $errors = [];
        if (empty($employee_id)) $errors[] = 'Employee is required';
        if (empty($salary_date)) $errors[] = 'Salary Date is required';
        if (empty($salary_month)) $errors[] = 'Salary Month is required';
        if (empty($basic_salary) || $basic_salary <= 0) $errors[] = 'Valid Basic Salary is required';
        
        if (!empty($errors)) {
            $_SESSION['form_data'] = [
                'employee_id' => $employee_id,
                'salary_date' => $salary_date,
                'salary_month' => $salary_month,
                'basic_salary' => $basic_salary,
                'overtime_hours' => $overtime_hours,
                'overtime_rate' => $overtime_rate,
                'bonus' => $bonus,
                'deductions' => $deductions
            ];
            redirect_with(['msg' => implode(', ', $errors), 'type' => 'danger']);
        }
        
        // Get employee details and calculate salary components
        $employee_name = $employees[$employee_id]['employee_name'] ?? '';
        $employee_username = $employees[$employee_id]['employee_username'] ?? '';
        $tds_percentage = $employees[$employee_id]['tds_per'] ?? 0;
        
        // Calculate salary components
        $overtime_amount = $overtime_hours * $overtime_rate;
        $gross_salary = $basic_salary + $overtime_amount + $bonus;
        $tds_amount = ($gross_salary * $tds_percentage) / 100;
        $total_deductions = $tds_amount + $deductions;
        $net_salary = $gross_salary - $total_deductions;
        
        if ($action === 'generate') {
            // Check if salary already exists for this employee and month
            $check_stmt = $mysqli->prepare("SELECT id FROM employee_salary_tds WHERE employee_id = ? AND salary_month = ?");
            $check_stmt->bind_param("ss", $employee_id, $salary_month);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                redirect_with(['msg' => "Salary for $employee_name ($salary_month) already exists", 'type' => 'warning']);
            }
            $check_stmt->close();
            
            // Insert new salary
            $stmt = $mysqli->prepare("INSERT INTO employee_salary_tds (
                employee_id, employee_name, employee_username, salary_date, salary_month, basic_salary, 
                overtime_hours, overtime_rate, overtime_amount, bonus, deductions, 
                tds_percentage, tds_amount, gross_salary, total_deductions, net_salary, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Generated')");
            
            if ($stmt) {
                $stmt->bind_param("sssssddddddddddd", 
                    $employee_id, $employee_name, $employee_username, $salary_date, $salary_month, $basic_salary,
                    $overtime_hours, $overtime_rate, $overtime_amount, $bonus, $deductions,
                    $tds_percentage, $tds_amount, $gross_salary, $total_deductions, $net_salary
                );
                
                if ($stmt->execute()) {
                    unset($_SESSION['form_data']);
                    redirect_with(['msg' => "Salary generated successfully for $employee_name", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error generating salary: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            } else {
                redirect_with(['msg' => 'Database preparation error', 'type' => 'danger']);
            }
        }
        
        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Update salary
                $stmt = $mysqli->prepare("UPDATE employee_salary_tds SET 
                    employee_id = ?, employee_name = ?, employee_username = ?, salary_date = ?, salary_month = ?, 
                    basic_salary = ?, overtime_hours = ?, overtime_rate = ?, overtime_amount = ?, 
                    bonus = ?, deductions = ?, tds_percentage = ?, tds_amount = ?, 
                    gross_salary = ?, total_deductions = ?, net_salary = ? 
                    WHERE id = ?");
                
                if ($stmt) {
                    $stmt->bind_param("sssssdddddddddddi", 
                        $employee_id, $employee_name, $employee_username, $salary_date, $salary_month, $basic_salary,
                        $overtime_hours, $overtime_rate, $overtime_amount, $bonus, $deductions,
                        $tds_percentage, $tds_amount, $gross_salary, $total_deductions, $net_salary, $id
                    );
                    
                    if ($stmt->execute()) {
                        redirect_with(['msg' => "Salary record updated successfully", 'type' => 'success']);
                    } else {
                        redirect_with(['msg' => 'Error updating salary: ' . $stmt->error, 'type' => 'danger']);
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
        
        if ($id > 0) {
            // Get salary details for message
            $res = $mysqli->prepare("SELECT employee_name, salary_month FROM employee_salary_tds WHERE id = ?");
            if ($res) {
                $res->bind_param("i", $id);
                $res->execute();
                $res->bind_result($employee_name, $salary_month);
                $res->fetch();
                $res->close();
                
                // Delete salary
                $del = $mysqli->prepare("DELETE FROM employee_salary_tds WHERE id = ?");
                if ($del) {
                    $del->bind_param("i", $id);
                    $del->execute();
                    $del->close();
                    
                    redirect_with(['msg' => "Salary record for $employee_name ($salary_month) deleted", 'type' => 'warning']);
                }
            }
        }
    }
    
    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT status, employee_name, salary_month FROM employee_salary_tds WHERE id = ?");
            if ($res) {
                $res->bind_param("i", $id);
                $res->execute();
                $res->bind_result($current_status, $employee_name, $salary_month);
                $res->fetch();
                $res->close();
                
                if ($employee_name) {
                    $newStatus = ($current_status === 'Generated') ? 'Paid' : 'Generated';
                    
                    $upd = $mysqli->prepare("UPDATE employee_salary_tds SET status = ? WHERE id = ?");
                    if ($upd) {
                        $upd->bind_param("si", $newStatus, $id);
                        $upd->execute();
                        $upd->close();
                        
                        redirect_with(['msg' => "Salary status for $employee_name ($salary_month) changed to $newStatus", 'type' => 'info']);
                    }
                }
            }
        }
    }
    
    if ($action === 'bulk_generate') {
        $salary_month = trim($_POST['bulk_month'] ?? '');
        $salary_date = $_POST['bulk_salary_date'] ?? '';
        
        if (empty($salary_month) || empty($salary_date)) {
            redirect_with(['msg' => 'Month and Salary Date are required for bulk generation', 'type' => 'danger']);
        }
        
        // Check if salaries already generated for this month
        $check_stmt = $mysqli->prepare("SELECT COUNT(*) FROM employee_salary_tds WHERE salary_month = ?");
        $check_stmt->bind_param("s", $salary_month);
        $check_stmt->execute();
        $check_stmt->bind_result($existing_count);
        $check_stmt->fetch();
        $check_stmt->close();
        
        if ($existing_count > 0) {
            redirect_with(['msg' => "Salaries for $salary_month already generated. Please delete existing records first.", 'type' => 'warning']);
        }
        
        // Generate salaries for all TDS employees
        $success_count = 0;
        $error_count = 0;
        
        foreach ($employees as $employee_id => $employee) {
            $basic_salary = $employee['salary'];
            $tds_percentage = $employee['tds_per'];
            $gross_salary = $basic_salary; // No overtime/bonus in bulk generation
            $tds_amount = ($gross_salary * $tds_percentage) / 100;
            $net_salary = $gross_salary - $tds_amount;
            
            $stmt = $mysqli->prepare("INSERT INTO employee_salary_tds (
                employee_id, employee_name, employee_username, salary_date, salary_month, basic_salary, 
                tds_percentage, tds_amount, gross_salary, total_deductions, net_salary, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Generated')");
            
            if ($stmt) {
                $stmt->bind_param("sssssdddddd", 
                    $employee_id, $employee['employee_name'], $employee['employee_username'], $salary_date, $salary_month, $basic_salary,
                    $tds_percentage, $tds_amount, $gross_salary, $tds_amount, $net_salary
                );
                
                if ($stmt->execute()) {
                    $success_count++;
                } else {
                    $error_count++;
                }
                $stmt->close();
            } else {
                $error_count++;
            }
        }
        
        redirect_with([
            'msg' => "Bulk salary generation completed: $success_count successful, $error_count failed", 
            'type' => $error_count > 0 ? 'warning' : 'success'
        ]);
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editSalary = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM employee_salary_tds WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $editSalary = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
    if (!$editSalary) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$femployee = $_GET['employee'] ?? '';
$fmonth = $_GET['month'] ?? '';
$fstatus = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'salary_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query
$sql = "SELECT * FROM employee_salary_tds";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(employee_name LIKE ? OR employee_id LIKE ? OR employee_username LIKE ? OR salary_month LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if ($femployee !== '') {
    $cond[] = "employee_id = ?";
    $params[] = $femployee;
    $types .= 's';
}

if ($fmonth !== '') {
    $cond[] = "salary_month = ?";
    $params[] = $fmonth;
    $types .= 's';
}

if ($fstatus === 'Generated' || $fstatus === 'Paid') {
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
        $salaries = $stmt->get_result();
    } else {
        $salaries = false;
    }
} else {
    $salaries = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$salaries) {
    $salaries = false;
}

// Get unique months for filter
$months_result = $mysqli->query("SELECT DISTINCT salary_month FROM employee_salary_tds ORDER BY FIELD(salary_month, 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December')");
$unique_months = [];
while($row = $months_result->fetch_assoc()) {
    $unique_months[] = $row['salary_month'];
}

// Get form data from session if exists
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1800px;
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
        .amount-cell {
            text-align: right;
            font-weight: 500;
        }
        .status-badge {
            font-size: 0.75em;
        }
        .calculation-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .bulk-generate-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
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

        <?php if($mode === 'generate' || $mode === 'edit'): ?>
        
        <!-- Generate/Edit Form -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-money-bill-wave me-2"></i><?= $mode === 'edit' ? 'Edit Salary Record' : 'Generate Salary' ?></h4>
            <a href="salary.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off" id="salaryForm">
                    <input type="hidden" name="action" value="<?= $mode === 'edit' ? 'edit' : 'generate' ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editSalary['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="employee_id" class="form-label">Employee <span class="text-danger">*</span></label>
                                <select class="form-select" id="employee_id" name="employee_id" required <?= $mode === 'edit' ? 'disabled' : '' ?>>
                                    <option value="">Select Employee</option>
                                    <?php foreach($employees as $employee_id => $employee): ?>
                                        <option value="<?= h($employee_id) ?>" 
                                            <?= (($editSalary['employee_id'] ?? $formData['employee_id'] ?? '') === $employee_id) ? 'selected' : '' ?>
                                            data-salary="<?= h($employee['salary']) ?>"
                                            data-tds-per="<?= h($employee['tds_per']) ?>">
                                            <?= h($employee['employee_name']) ?> (<?= h($employee['employee_username']) ?>) - Basic: ₹<?= number_format($employee['salary'], 2) ?> - TDS: <?= h($employee['tds_per']) ?>%
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if($mode === 'edit'): ?>
                                <input type="hidden" name="employee_id" value="<?= h($editSalary['employee_id']) ?>">
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="salary_date" class="form-label">Salary Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="salary_date" name="salary_date" 
                                       value="<?= h($editSalary['salary_date'] ?? $formData['salary_date'] ?? date('Y-m-d')) ?>" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="salary_month" class="form-label">Salary Month <span class="text-danger">*</span></label>
                                <select class="form-select" id="salary_month" name="salary_month" required>
                                    <option value="">Select Month</option>
                                    <?php 
                                    $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                                    foreach($months as $month): ?>
                                        <option value="<?= h($month) ?>" <?= (($editSalary['salary_month'] ?? $formData['salary_month'] ?? '') === $month) ? 'selected' : '' ?>>
                                            <?= h($month) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="basic_salary" class="form-label">Basic Salary (₹) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="basic_salary" name="basic_salary" 
                                       value="<?= h($editSalary['basic_salary'] ?? $formData['basic_salary'] ?? '0') ?>" 
                                       placeholder="Enter basic salary" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="overtime_hours" class="form-label">Overtime Hours</label>
                                <input type="number" class="form-control" id="overtime_hours" name="overtime_hours" 
                                       value="<?= h($editSalary['overtime_hours'] ?? $formData['overtime_hours'] ?? '0') ?>" 
                                       placeholder="Overtime hours" step="0.1" min="0">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="overtime_rate" class="form-label">Overtime Rate (₹/hr)</label>
                                <input type="number" class="form-control" id="overtime_rate" name="overtime_rate" 
                                       value="<?= h($editSalary['overtime_rate'] ?? $formData['overtime_rate'] ?? '0') ?>" 
                                       placeholder="Overtime rate per hour" step="0.01" min="0">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="bonus" class="form-label">Bonus (₹)</label>
                                <input type="number" class="form-control" id="bonus" name="bonus" 
                                       value="<?= h($editSalary['bonus'] ?? $formData['bonus'] ?? '0') ?>" 
                                       placeholder="Bonus amount" step="0.01" min="0">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="deductions" class="form-label">Other Deductions (₹)</label>
                                <input type="number" class="form-control" id="deductions" name="deductions" 
                                       value="<?= h($editSalary['deductions'] ?? $formData['deductions'] ?? '0') ?>" 
                                       placeholder="Other deductions" step="0.01" min="0">
                            </div>
                        </div>
                    </div>

                    <!-- Salary Calculation Preview -->
                    <div class="calculation-box" id="calculationPreview" style="display: none;">
                        <h6 class="mb-3">Salary Calculation Preview:</h6>
                        <div class="row">
                            <div class="col-md-3">
                                <strong>Basic Salary:</strong>
                                <div id="previewBasic">₹0.00</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Overtime Amount:</strong>
                                <div id="previewOvertime">₹0.00</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Bonus:</strong>
                                <div id="previewBonus">₹0.00</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Gross Salary:</strong>
                                <div id="previewGross" class="text-primary">₹0.00</div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-3">
                                <strong>TDS Percentage:</strong>
                                <div id="previewTdsPer">0%</div>
                            </div>
                            <div class="col-md-3">
                                <strong>TDS Amount:</strong>
                                <div id="previewTdsAmount" class="text-danger">₹0.00</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Other Deductions:</strong>
                                <div id="previewDeductions">₹0.00</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Total Deductions:</strong>
                                <div id="previewTotalDeductions" class="text-danger">₹0.00</div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <strong>Net Salary Payable:</strong>
                                <div id="previewNetSalary" class="text-success h4">₹0.00</div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-calculator me-1"></i>
                            <?= $mode === 'edit' ? 'Update Salary' : 'Generate Salary' ?>
                        </button>
                        <a href="salary.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-money-check me-2"></i>Salary Management</h4>
            <div>
                <a href="salary.php?mode=generate" class="btn btn-success me-2">
                    <i class="fas fa-plus me-1"></i> Generate Salary
                </a>
                <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#bulkModal">
                    <i class="fas fa-bolt me-1"></i> Bulk Generate
                </button>
            </div>
        </div>

        <!-- Bulk Generate Modal -->
        <div class="modal fade" id="bulkModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Bulk Salary Generation</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="post">
                        <div class="modal-body">
                            <input type="hidden" name="action" value="bulk_generate">
                            <div class="mb-3">
                                <label for="bulk_month" class="form-label">Salary Month <span class="text-danger">*</span></label>
                                <select class="form-select" id="bulk_month" name="bulk_month" required>
                                    <option value="">Select Month</option>
                                    <?php 
                                    $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                                    foreach($months as $month): ?>
                                        <option value="<?= h($month) ?>"><?= h($month) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="bulk_salary_date" class="form-label">Salary Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="bulk_salary_date" name="bulk_salary_date" 
                                       value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                This will generate salaries for all <?= count($employees) ?> TDS employees using their basic salary from employee master.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Generate All Salaries</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Bulk Generate Section -->
        <div class="bulk-generate-section">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5><i class="fas fa-bolt me-2"></i>Quick Bulk Generation</h5>
                    <p class="mb-0">Generate salaries for all TDS employees at once for the current month</p>
                </div>
                <div class="col-md-4 text-end">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="bulk_generate">
                        <input type="hidden" name="bulk_month" value="<?= date('F') ?>">
                        <input type="hidden" name="bulk_salary_date" value="<?= date('Y-m-d') ?>">
                        <button type="submit" class="btn btn-light btn-lg">
                            <i class="fas fa-bolt me-2"></i>Generate <?= date('F') ?> Salaries
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control" 
                               placeholder="Search by employee name, username, ID, month..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="employee" class="form-select">
                            <option value="">All Employees</option>
                            <?php foreach($employees as $employee_id => $employee): ?>
                                <option value="<?= h($employee_id) ?>" <?= $femployee === $employee_id ? 'selected' : '' ?>>
                                    <?= h($employee['employee_name']) ?> (<?= h($employee['employee_username']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="month" class="form-select">
                            <option value="">All Months</option>
                            <?php foreach($unique_months as $month): ?>
                                <option value="<?= h($month) ?>" <?= $fmonth === $month ? 'selected' : '' ?>>
                                    <?= h($month) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="Generated" <?= $fstatus === 'Generated' ? 'selected' : '' ?>>Generated</option>
                            <option value="Paid" <?= $fstatus === 'Paid' ? 'selected' : '' ?>>Paid</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="sort" class="form-select">
                            <option value="salary_date" <?= $sort === 'salary_date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="employee_name" <?= $sort === 'employee_name' ? 'selected' : '' ?>>Sort by Name</option>
                            <option value="salary_month" <?= $sort === 'salary_month' ? 'selected' : '' ?>>Sort by Month</option>
                            <option value="net_salary" <?= $sort === 'net_salary' ? 'selected' : '' ?>>Sort by Net Salary</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <select name="dir" class="form-select">
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Desc</option>
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Asc</option>
                        </select>
                    </div>
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="salary.php" class="btn btn-secondary">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Salaries Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="60">ID</th>
                                <!--th>Employee ID</th-->
                                <th>Employee Name</th>
                                <th>Username</th>
                                <th width="100">Date</th>
                                <th width="100">Month</th>
                                <th width="120">Basic (₹)</th>
                                <th width="120">Gross (₹)</th>
                                <th width="100">TDS %</th>
                                <th width="120">TDS Amount (₹)</th>
                                <th width="120">Net Salary (₹)</th>
                                <th width="100">Status</th>
                                <th width="150" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($salaries && $salaries->num_rows > 0): ?>
                                <?php 
                                $i = 1;
                                while($row = $salaries->fetch_assoc()): 
                                ?>
                                <tr>
                                    <td class="fw-bold"><?= $i; ?></td>
                                    <!--td>
                                        <span class="badge bg-primary"><?= h($row['employee_id']) ?></span>
                                    </td-->
                                    <td>
                                        <span class="fw-semibold"><?= h($row['employee_name']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?= h($row['employee_username']) ?></span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= h(formatDate($row['salary_date'])) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?= h($row['salary_month']) ?></span>
                                    </td>
                                    <td class="amount-cell">
                                        ₹<?= number_format($row['basic_salary'], 2) ?>
                                    </td>
                                    <td class="amount-cell">
                                        ₹<?= number_format($row['gross_salary'], 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-warning"><?= $row['tds_percentage'] ?>%</span>
                                    </td>
                                    <td class="amount-cell text-danger">
                                        ₹<?= number_format($row['tds_amount'], 2) ?>
                                    </td>
                                    <td class="amount-cell text-success">
                                        <strong>₹<?= number_format($row['net_salary'], 2) ?></strong>
                                    </td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $row['status'] === 'Paid' ? 'btn-success' : 'btn-warning' ?> status-badge">
                                                <?= h($row['status']) ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="salary.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit Salary">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this salary record?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Salary">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php 
                                $i++;
                                endwhile; 
                                ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="13" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-money-check fa-3x mb-3"></i>
                                            <h5>No salary records found</h5>
                                            <p class="mb-0">Get started by generating your first salary record.</p>
                                            <div class="mt-3">
                                                <a href="salary.php?mode=generate" class="btn btn-primary me-2">
                                                    <i class="fas fa-plus me-1"></i> Generate Salary
                                                </a>
                                                <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#bulkModal">
                                                    <i class="fas fa-bolt me-1"></i> Bulk Generate
                                                </button>
                                            </div>
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
        <?php if($salaries && $salaries->num_rows > 0): ?>
        <?php
            // Reset pointer and calculate totals
            $salaries->data_seek(0);
            $total_records = 0;
            $total_basic = 0;
            $total_gross = 0;
            $total_tds = 0;
            $total_net = 0;
            $generated_count = 0;
            $paid_count = 0;
            
            while($row = $salaries->fetch_assoc()) {
                $total_records++;
                $total_basic += $row['basic_salary'];
                $total_gross += $row['gross_salary'];
                $total_tds += $row['tds_amount'];
                $total_net += $row['net_salary'];
                if ($row['status'] === 'Generated') $generated_count++;
                if ($row['status'] === 'Paid') $paid_count++;
            }
        ?>
        <div class="card mt-4">
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-2">
                        <h5 class="text-primary"><?= $total_records ?></h5>
                        <small class="text-muted">Total Records</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-warning"><?= $generated_count ?></h5>
                        <small class="text-muted">Generated</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-success"><?= $paid_count ?></h5>
                        <small class="text-muted">Paid</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-info">₹<?= number_format($total_basic, 2) ?></h5>
                        <small class="text-muted">Total Basic</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-danger">₹<?= number_format($total_tds, 2) ?></h5>
                        <small class="text-muted">Total TDS</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-success">₹<?= number_format($total_net, 2) ?></h5>
                        <small class="text-muted">Total Net Payable</small>
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

    // Real-time salary calculation
    const employeeSelect = document.getElementById('employee_id');
    const basicSalaryInput = document.getElementById('basic_salary');
    const overtimeHoursInput = document.getElementById('overtime_hours');
    const overtimeRateInput = document.getElementById('overtime_rate');
    const bonusInput = document.getElementById('bonus');
    const deductionsInput = document.getElementById('deductions');
    const calculationPreview = document.getElementById('calculationPreview');
    
    function calculateSalary() {
        const selectedEmployee = employeeSelect ? employeeSelect.options[employeeSelect.selectedIndex] : null;
        const tdsPercentage = selectedEmployee ? parseFloat(selectedEmployee.getAttribute('data-tds-per')) || 0 : 0;
        const basicSalary = parseFloat(basicSalaryInput?.value) || 0;
        const overtimeHours = parseFloat(overtimeHoursInput?.value) || 0;
        const overtimeRate = parseFloat(overtimeRateInput?.value) || 0;
        const bonus = parseFloat(bonusInput?.value) || 0;
        const deductions = parseFloat(deductionsInput?.value) || 0;
        
        if (employeeSelect && employeeSelect.value && basicSalary > 0) {
            const overtimeAmount = overtimeHours * overtimeRate;
            const grossSalary = basicSalary + overtimeAmount + bonus;
            const tdsAmount = (grossSalary * tdsPercentage) / 100;
            const totalDeductions = tdsAmount + deductions;
            const netSalary = grossSalary - totalDeductions;
            
            // Update preview
            document.getElementById('previewBasic').textContent = '₹' + basicSalary.toLocaleString('en-IN', {minimumFractionDigits: 2});
            document.getElementById('previewOvertime').textContent = '₹' + overtimeAmount.toLocaleString('en-IN', {minimumFractionDigits: 2});
            document.getElementById('previewBonus').textContent = '₹' + bonus.toLocaleString('en-IN', {minimumFractionDigits: 2});
            document.getElementById('previewGross').textContent = '₹' + grossSalary.toLocaleString('en-IN', {minimumFractionDigits: 2});
            document.getElementById('previewTdsPer').textContent = tdsPercentage + '%';
            document.getElementById('previewTdsAmount').textContent = '₹' + tdsAmount.toLocaleString('en-IN', {minimumFractionDigits: 2});
            document.getElementById('previewDeductions').textContent = '₹' + deductions.toLocaleString('en-IN', {minimumFractionDigits: 2});
            document.getElementById('previewTotalDeductions').textContent = '₹' + totalDeductions.toLocaleString('en-IN', {minimumFractionDigits: 2});
            document.getElementById('previewNetSalary').textContent = '₹' + netSalary.toLocaleString('en-IN', {minimumFractionDigits: 2});
            
            // Show preview
            calculationPreview.style.display = 'block';
        } else {
            if (calculationPreview) calculationPreview.style.display = 'none';
        }
    }
    
    // Add event listeners only if elements exist
    if (employeeSelect) {
        employeeSelect.addEventListener('change', function() {
            const selectedEmployee = this.options[this.selectedIndex];
            if (selectedEmployee && selectedEmployee.value) {
                const defaultSalary = selectedEmployee.getAttribute('data-salary');
                if (basicSalaryInput) basicSalaryInput.value = defaultSalary || '0';
            }
            calculateSalary();
        });
    }
    
    if (basicSalaryInput) basicSalaryInput.addEventListener('input', calculateSalary);
    if (overtimeHoursInput) overtimeHoursInput.addEventListener('input', calculateSalary);
    if (overtimeRateInput) overtimeRateInput.addEventListener('input', calculateSalary);
    if (bonusInput) bonusInput.addEventListener('input', calculateSalary);
    if (deductionsInput) deductionsInput.addEventListener('input', calculateSalary);
    
   // Initial calculation if editing
    <?php 
    $shouldCalculate = false;
    if (($mode === 'edit' || $mode === 'generate') && (isset($editSalary) || isset($formData['employee_id']))) {
        $shouldCalculate = true;
    }
    ?>
    
    <?php if ($shouldCalculate): ?>
    calculateSalary();
    <?php endif; ?>
    </script>
</body>
</html>