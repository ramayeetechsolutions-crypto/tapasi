<?php

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
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

function calculateGrade($total_score) {
    if ($total_score >= 18) return 'A';
    if ($total_score >= 15) return 'B';
    if ($total_score >= 12) return 'C';
    return 'D';
}

function redirect_with($params = []) {
    $base = 'employee_performance.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Check and create table if not exists
$check_table = $mysqli->query("SHOW TABLES LIKE 'employee_performance'");
if ($check_table->num_rows == 0) {
    $create_table_sql = "CREATE TABLE employee_performance (
        performance_id INT PRIMARY KEY AUTO_INCREMENT,
        employee_id INT NOT NULL,
        review_year YEAR NOT NULL,
        quarter ENUM('Q1', 'Q2', 'Q3', 'Q4') NOT NULL,
        production_score DECIMAL(3,1) NOT NULL,
        accuracy_score DECIMAL(3,1) NOT NULL,
        attendance_score DECIMAL(3,1) NOT NULL,
        punctuality_score DECIMAL(2,1) NOT NULL,
        permission_score DECIMAL(2,1) NOT NULL,
        rules_regulation_score DECIMAL(2,1) NOT NULL,
        phone_usage_score DECIMAL(2,1) NOT NULL,
        training_score DECIMAL(2,1) NOT NULL,
        client_handling_score DECIMAL(2,1) NOT NULL,
        leadership_score DECIMAL(2,1) NOT NULL,
        self_discipline_score DECIMAL(2,1) NOT NULL,
        involvement_score DECIMAL(2,1) NOT NULL,
        commitment_score DECIMAL(2,1) NOT NULL,
        total_score DECIMAL(4,2) NOT NULL,
        grade CHAR(1) NOT NULL,
        comments TEXT,
        reviewed_by INT,
        review_date DATE,
        created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    
    if ($mysqli->query($create_table_sql)) {
        // Table created successfully
    } else {
        die("Error creating table: " . $mysqli->error);
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $review_year = (int)($_POST['review_year'] ?? date('Y'));
        $quarter = $_POST['quarter'] ?? '';
        
        // Debug: Check what values we're getting
        error_log("Employee ID: $employee_id, Year: $review_year, Quarter: $quarter");
        
        // Main category scores (5 points each)
        $production_score = min(5, max(0, (float)($_POST['production_score'] ?? 0)));
        $accuracy_score = min(5, max(0, (float)($_POST['accuracy_score'] ?? 0)));
        $attendance_score = min(5, max(0, (float)($_POST['attendance_score'] ?? 0)));
        
        // Attitude sub-category scores (0.5 points each)
        $punctuality_score = min(0.5, max(0, (float)($_POST['punctuality_score'] ?? 0)));
        $permission_score = min(0.5, max(0, (float)($_POST['permission_score'] ?? 0)));
        $rules_regulation_score = min(0.5, max(0, (float)($_POST['rules_regulation_score'] ?? 0)));
        $phone_usage_score = min(0.5, max(0, (float)($_POST['phone_usage_score'] ?? 0)));
        $training_score = min(0.5, max(0, (float)($_POST['training_score'] ?? 0)));
        $client_handling_score = min(0.5, max(0, (float)($_POST['client_handling_score'] ?? 0)));
        $leadership_score = min(0.5, max(0, (float)($_POST['leadership_score'] ?? 0)));
        $self_discipline_score = min(0.5, max(0, (float)($_POST['self_discipline_score'] ?? 0)));
        $involvement_score = min(0.5, max(0, (float)($_POST['involvement_score'] ?? 0)));
        $commitment_score = min(0.5, max(0, (float)($_POST['commitment_score'] ?? 0)));
        
        $comments = trim($_POST['comments'] ?? '');
        
        // Calculate total score and grade
        $attitude_total = $punctuality_score + $permission_score + $rules_regulation_score + 
                         $phone_usage_score + $training_score + $client_handling_score + 
                         $leadership_score + $self_discipline_score + $involvement_score + $commitment_score;
        
        $total_score = $production_score + $accuracy_score + $attendance_score + $attitude_total;
        $grade = calculateGrade($total_score);
        
        // Validation
        if ($employee_id <= 0) {
            redirect_with(['msg' => 'Please select an employee.', 'type' => 'danger']);
        }
        
        if (!in_array($quarter, ['Q1', 'Q2', 'Q3', 'Q4'])) {
            redirect_with(['msg' => 'Please select a valid quarter.', 'type' => 'danger']);
        }
        
        if ($review_year < 2000 || $review_year > 2100) {
            redirect_with(['msg' => 'Please select a valid year.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Check if performance record already exists for this employee, year, and quarter
            $check_stmt = $mysqli->prepare("SELECT performance_id FROM employee_performance WHERE employee_id = ? AND review_year = ? AND quarter = ?");
            if (!$check_stmt) {
                redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
            }
            
            $check_stmt->bind_param("iis", $employee_id, $review_year, $quarter);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                redirect_with(['msg' => 'Performance record already exists for this employee, year, and quarter.', 'type' => 'danger']);
            }
            $check_stmt->close();
            
            // Insert new performance record
            $stmt = $mysqli->prepare("INSERT INTO employee_performance 
                (employee_id, review_year, quarter, production_score, accuracy_score, attendance_score, 
                 punctuality_score, permission_score, rules_regulation_score, phone_usage_score, 
                 training_score, client_handling_score, leadership_score, self_discipline_score, 
                 involvement_score, commitment_score, total_score, grade, comments, reviewed_by, review_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())");
            
            if (!$stmt) {
                redirect_with(['msg' => 'Prepare failed: ' . $mysqli->error, 'type' => 'danger']);
            }
            
            $reviewed_by = $_SESSION['employee_id'] ?? 1; // Get from session or default
            
            $bind_result = $stmt->bind_param("iisdddddddddddddddsi", 
                $employee_id, $review_year, $quarter, $production_score, $accuracy_score, $attendance_score,
                $punctuality_score, $permission_score, $rules_regulation_score, $phone_usage_score,
                $training_score, $client_handling_score, $leadership_score, $self_discipline_score,
                $involvement_score, $commitment_score, $total_score, $grade, $comments, $reviewed_by);
            
            if (!$bind_result) {
                redirect_with(['msg' => 'Bind failed: ' . $stmt->error, 'type' => 'danger']);
            }
            
            if ($stmt->execute()) {
                redirect_with(['msg' => 'Performance record added successfully', 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding performance record: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $performance_id = (int)($_POST['performance_id'] ?? 0);
            
            if ($performance_id > 0) {
                // Check if performance record exists (excluding current one for duplicate check)
                $check_stmt = $mysqli->prepare("SELECT performance_id FROM employee_performance WHERE employee_id = ? AND review_year = ? AND quarter = ? AND performance_id != ?");
                $check_stmt->bind_param("iisi", $employee_id, $review_year, $quarter, $performance_id);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $check_stmt->close();
                    redirect_with(['msg' => 'Performance record already exists for this employee, year, and quarter.', 'type' => 'danger']);
                }
                $check_stmt->close();
                
                // Update performance record
                $stmt = $mysqli->prepare("UPDATE employee_performance SET 
                    employee_id = ?, review_year = ?, quarter = ?, production_score = ?, accuracy_score = ?, 
                    attendance_score = ?, punctuality_score = ?, permission_score = ?, rules_regulation_score = ?, 
                    phone_usage_score = ?, training_score = ?, client_handling_score = ?, leadership_score = ?, 
                    self_discipline_score = ?, involvement_score = ?, commitment_score = ?, total_score = ?, 
                    grade = ?, comments = ?, updated_date = NOW() WHERE performance_id = ?");
                
                $stmt->bind_param("iisddddddddddddddddsi", 
                    $employee_id, $review_year, $quarter, $production_score, $accuracy_score, $attendance_score,
                    $punctuality_score, $permission_score, $rules_regulation_score, $phone_usage_score,
                    $training_score, $client_handling_score, $leadership_score, $self_discipline_score,
                    $involvement_score, $commitment_score, $total_score, $grade, $comments, $performance_id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => 'Performance record updated successfully', 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating performance record: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $performance_id = (int)($_POST['performance_id'] ?? 0);
        
        if ($performance_id > 0) {
            $del = $mysqli->prepare("DELETE FROM employee_performance WHERE performance_id = ?");
            $del->bind_param("i", $performance_id);
            
            if ($del->execute()) {
                redirect_with(['msg' => 'Performance record deleted successfully', 'type' => 'warning']);
            } else {
                redirect_with(['msg' => 'Error deleting performance record: ' . $del->error, 'type' => 'danger']);
            }
            $del->close();
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editPerformance = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM employee_performance WHERE performance_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editPerformance = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editPerformance) $mode = 'view';
}

// Get active employees for dropdown
$employees_result = $mysqli->query("SELECT employee_id, employee_name, employee_username FROM employee_master WHERE status = 'Active' ORDER BY employee_name");

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fyear = $_GET['year'] ?? '';
$fquarter = $_GET['quarter'] ?? '';
$femployee = $_GET['employee'] ?? '';
$sort = $_GET['sort'] ?? 'review_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query
$sql = "SELECT ep.*, em.employee_name, em.designation_id, em.process 
        FROM employee_performance ep 
        JOIN employee_master em ON ep.employee_id = em.employee_id";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "em.employee_name LIKE ?";
    $like = "%$q%";
    $params[] = $like;
    $types .= 's';
}

if ($fyear !== '') {
    $cond[] = "ep.review_year = ?";
    $params[] = $fyear;
    $types .= 's';
}

if ($fquarter !== '') {
    $cond[] = "ep.quarter = ?";
    $params[] = $fquarter;
    $types .= 's';
}

if ($femployee !== '') {
    $cond[] = "ep.employee_id = ?";
    $params[] = $femployee;
    $types .= 'i';
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
        $performances = $stmt->get_result();
    } else {
        $performances = false;
    }
} else {
    $performances = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$performances) {
    $performances = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Performance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1400px;
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
        .score-input {
            max-width: 100px;
        }
        .grade-A { background-color: #d4edda; color: #155724; }
        .grade-B { background-color: #fff3cd; color: #856404; }
        .grade-C { background-color: #f8d7da; color: #721c24; }
        .grade-D { background-color: #d1ecf1; color: #0c5460; }
        .category-section {
            border-left: 4px solid #007bff;
            padding-left: 15px;
            margin-bottom: 20px;
        }
        .subcategory-section {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
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
            <h4><i class="fas fa-chart-line me-2"></i><?= $mode === 'edit' ? 'Edit Performance Record' : 'Add New Performance Record' ?></h4>
            <a href="employee_performance.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off" id="performanceForm">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="performance_id" value="<?= (int)($editPerformance['performance_id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="employee_id" class="form-label">Employee <span class="text-danger">*</span></label>
                                <select class="form-select" id="employee_id" name="employee_id" required>
                                    <option value="">Select Employee</option>
                                    <?php if($employees_result && $employees_result->num_rows > 0): ?>
                                        <?php while($emp = $employees_result->fetch_assoc()): ?>
                                        <option value="<?= (int)$emp['employee_id'] ?>" 
                                            <?= (isset($editPerformance['employee_id']) && $editPerformance['employee_id'] == $emp['employee_id']) ? 'selected' : '' ?>>
                                            <?= h($emp['employee_name']) ?> (<?= h($emp['employee_username']) ?>)
                                        </option>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <option value="">No employees found</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="review_year" class="form-label">Review Year <span class="text-danger">*</span></label>
                                <select class="form-select" id="review_year" name="review_year" required>
                                    <?php for($year = date('Y'); $year >= 2020; $year--): ?>
                                    <option value="<?= $year ?>" <?= (isset($editPerformance['review_year']) && $editPerformance['review_year'] == $year) ? 'selected' : '' ?>>
                                        <?= $year ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="quarter" class="form-label">Quarter <span class="text-danger">*</span></label>
                                <select class="form-select" id="quarter" name="quarter" required>
                                    <option value="">Select Quarter</option>
                                    <option value="Q1" <?= (isset($editPerformance['quarter']) && $editPerformance['quarter'] == 'Q1') ? 'selected' : '' ?>>Q1 (Jan-Mar)</option>
                                    <option value="Q2" <?= (isset($editPerformance['quarter']) && $editPerformance['quarter'] == 'Q2') ? 'selected' : '' ?>>Q2 (Apr-Jun)</option>
                                    <option value="Q3" <?= (isset($editPerformance['quarter']) && $editPerformance['quarter'] == 'Q3') ? 'selected' : '' ?>>Q3 (Jul-Sep)</option>
                                    <option value="Q4" <?= (isset($editPerformance['quarter']) && $editPerformance['quarter'] == 'Q4') ? 'selected' : '' ?>>Q4 (Oct-Dec)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Main Categories -->
                    <div class="category-section">
                        <h5 class="text-primary mb-3">Main Performance Categories (5 points each)</h5>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="production_score" class="form-label">Production Score</label>
                                    <input type="number" class="form-control score-input" id="production_score" name="production_score" 
                                           min="0" max="5" step="0.1" 
                                           value="<?= isset($editPerformance['production_score']) ? h($editPerformance['production_score']) : '0' ?>" required>
                                    <div class="form-text">Score out of 5</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="accuracy_score" class="form-label">Accuracy Score</label>
                                    <input type="number" class="form-control score-input" id="accuracy_score" name="accuracy_score" 
                                           min="0" max="5" step="0.1" 
                                           value="<?= isset($editPerformance['accuracy_score']) ? h($editPerformance['accuracy_score']) : '0' ?>" required>
                                    <div class="form-text">Score out of 5</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="attendance_score" class="form-label">Attendance Score</label>
                                    <input type="number" class="form-control score-input" id="attendance_score" name="attendance_score" 
                                           min="0" max="5" step="0.1" 
                                           value="<?= isset($editPerformance['attendance_score']) ? h($editPerformance['attendance_score']) : '0' ?>" required>
                                    <div class="form-text">Score out of 5</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Attitude Sub-categories -->
                    <div class="category-section">
                        <h5 class="text-primary mb-3">Attitude & Behavior (0.5 points each)</h5>
                        <div class="subcategory-section">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="punctuality_score" class="form-label">Punctuality</label>
                                        <input type="number" class="form-control score-input" id="punctuality_score" name="punctuality_score" 
                                               min="0" max="0.5" step="0.1" 
                                               value="<?= isset($editPerformance['punctuality_score']) ? h($editPerformance['punctuality_score']) : '0' ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="permission_score" class="form-label">Permission</label>
                                        <input type="number" class="form-control score-input" id="permission_score" name="permission_score" 
                                               min="0" max="0.5" step="0.1" 
                                               value="<?= isset($editPerformance['permission_score']) ? h($editPerformance['permission_score']) : '0' ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="rules_regulation_score" class="form-label">Rules & Regulation</label>
                                        <input type="number" class="form-control score-input" id="rules_regulation_score" name="rules_regulation_score" 
                                               min="0" max="0.5" step="0.1" 
                                               value="<?= isset($editPerformance['rules_regulation_score']) ? h($editPerformance['rules_regulation_score']) : '0' ?>" required>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="phone_usage_score" class="form-label">Phone Usage</label>
                                        <input type="number" class="form-control score-input" id="phone_usage_score" name="phone_usage_score" 
                                               min="0" max="0.5" step="0.1" 
                                               value="<?= isset($editPerformance['phone_usage_score']) ? h($editPerformance['phone_usage_score']) : '0' ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="training_score" class="form-label">Training</label>
                                        <input type="number" class="form-control score-input" id="training_score" name="training_score" 
                                               min="0" max="0.5" step="0.1" 
                                               value="<?= isset($editPerformance['training_score']) ? h($editPerformance['training_score']) : '0' ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="client_handling_score" class="form-label">Client Handling</label>
                                        <input type="number" class="form-control score-input" id="client_handling_score" name="client_handling_score" 
                                               min="0" max="0.5" step="0.1" 
                                               value="<?= isset($editPerformance['client_handling_score']) ? h($editPerformance['client_handling_score']) : '0' ?>" required>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="leadership_score" class="form-label">Leadership</label>
                                        <input type="number" class="form-control score-input" id="leadership_score" name="leadership_score" 
                                               min="0" max="0.5" step="0.1" 
                                               value="<?= isset($editPerformance['leadership_score']) ? h($editPerformance['leadership_score']) : '0' ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="self_discipline_score" class="form-label">Self Discipline</label>
                                        <input type="number" class="form-control score-input" id="self_discipline_score" name="self_discipline_score" 
                                               min="0" max="0.5" step="0.1" 
                                               value="<?= isset($editPerformance['self_discipline_score']) ? h($editPerformance['self_discipline_score']) : '0' ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="involvement_score" class="form-label">Involvement</label>
                                        <input type="number" class="form-control score-input" id="involvement_score" name="involvement_score" 
                                               min="0" max="0.5" step="0.1" 
                                               value="<?= isset($editPerformance['involvement_score']) ? h($editPerformance['involvement_score']) : '0' ?>" required>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="commitment_score" class="form-label">Commitment</label>
                                        <input type="number" class="form-control score-input" id="commitment_score" name="commitment_score" 
                                               min="0" max="0.5" step="0.1" 
                                               value="<?= isset($editPerformance['commitment_score']) ? h($editPerformance['commitment_score']) : '0' ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Comments -->
                    <div class="mb-3">
                        <label for="comments" class="form-label">Comments & Feedback</label>
                        <textarea class="form-control" id="comments" name="comments" rows="3" 
                                  placeholder="Enter performance comments and feedback..."><?= isset($editPerformance['comments']) ? h($editPerformance['comments']) : '' ?></textarea>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Performance' : 'Save Performance' ?>
                        </button>
                        <a href="employee_performance.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-chart-line me-2"></i>Employee Performance Management</h4>
            <a href="employee_performance.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add Performance Record
            </a>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center" id="filterForm">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search employees..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="year" class="form-select form-select-sm">
                            <option value="">All Years</option>
                            <?php for($year = date('Y'); $year >= 2020; $year--): ?>
                            <option value="<?= $year ?>" <?= $fyear == $year ? 'selected' : '' ?>><?= $year ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="quarter" class="form-select form-select-sm">
                            <option value="">All Quarters</option>
                            <option value="Q1" <?= $fquarter === 'Q1' ? 'selected' : '' ?>>Q1</option>
                            <option value="Q2" <?= $fquarter === 'Q2' ? 'selected' : '' ?>>Q2</option>
                            <option value="Q3" <?= $fquarter === 'Q3' ? 'selected' : '' ?>>Q3</option>
                            <option value="Q4" <?= $fquarter === 'Q4' ? 'selected' : '' ?>>Q4</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="employee" class="form-select form-select-sm">
                            <option value="">All Employees</option>
                            <?php 
                            $emp_filter_result = $mysqli->query("SELECT employee_id, employee_name FROM employee_master WHERE status = 'Active' ORDER BY employee_name");
                            if ($emp_filter_result && $emp_filter_result->num_rows > 0):
                                while($emp = $emp_filter_result->fetch_assoc()): ?>
                                <option value="<?= (int)$emp['employee_id'] ?>" <?= $femployee == $emp['employee_id'] ? 'selected' : '' ?>>
                                    <?= h($emp['employee_name']) ?>
                                </option>
                                <?php endwhile;
                            endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="review_date" <?= $sort === 'review_date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="employee_name" <?= $sort === 'employee_name' ? 'selected' : '' ?>>Sort by Name</option>
                            <option value="total_score" <?= $sort === 'total_score' ? 'selected' : '' ?>>Sort by Score</option>
                        </select>
                    </div>
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="employee_performance.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Performance Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="50">S.No</th>
                                <th>Employee</th>
                                <th width="80">Year</th>
                                <th width="60">Quarter</th>
                                <th width="80">Production</th>
                                <th width="80">Accuracy</th>
                                <th width="80">Attendance</th>
                                <th width="80">Attitude</th>
                                <th width="80">Total</th>
                                <th width="60">Grade</th>
                                <th width="120">Review Date</th>
                                <th width="150" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($performances && $performances->num_rows > 0): ?>
                                <?php while($row = $performances->fetch_assoc()): 
                                    $attitude_total = $row['punctuality_score'] + $row['permission_score'] + $row['rules_regulation_score'] + 
                                                    $row['phone_usage_score'] + $row['training_score'] + $row['client_handling_score'] + 
                                                    $row['leadership_score'] + $row['self_discipline_score'] + $row['involvement_score'] + $row['commitment_score'];
                                ?>
                                <tr>
                                    <td class="fw-bold"><?= (int)$row['performance_id'] ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= h($row['employee_name']) ?></div>
                                        <small class="text-muted"><?= h($row['process'] ?? 'N/A') ?></small>
                                    </td>
                                    <td><?= h($row['review_year']) ?></td>
                                    <td><span class="badge bg-info"><?= h($row['quarter']) ?></span></td>
                                    <td><?= h($row['production_score']) ?>/5</td>
                                    <td><?= h($row['accuracy_score']) ?>/5</td>
                                    <td><?= h($row['attendance_score']) ?>/5</td>
                                    <td><?= h(number_format($attitude_total, 1)) ?>/5</td>
                                    <td class="fw-bold"><?= h($row['total_score']) ?>/20</td>
                                    <td>
                                        <span class="badge grade-<?= $row['grade'] ?> p-2"><?= h($row['grade']) ?></span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= h(formatDate($row['review_date'])) ?></small>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="employee_performance.php?mode=edit&id=<?= (int)$row['performance_id'] ?>" 
                                               class="btn btn-warning" title="Edit Performance">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this performance record?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="performance_id" value="<?= (int)$row['performance_id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Performance">
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
                                            <i class="fas fa-chart-line fa-3x mb-3"></i>
                                            <h5>No performance records found</h5>
                                            <p class="mb-0">Get started by adding your first performance record.</p>
                                            <a href="employee_performance.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Performance Record
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

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-dismiss alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert.isConnected) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                }, 5000);
            });

            // Auto-submit form on filter changes
            const filterSelects = document.querySelectorAll('#filterForm select');
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    document.getElementById('filterForm').submit();
                });
            });

            // Form validation
            const performanceForm = document.getElementById('performanceForm');
            if (performanceForm) {
                performanceForm.addEventListener('submit', function(e) {
                    const employeeId = document.getElementById('employee_id').value;
                    const quarter = document.getElementById('quarter').value;
                    
                    if (!employeeId || !quarter) {
                        e.preventDefault();
                        alert('Please select both Employee and Quarter');
                        return false;
                    }
                });
            }
        });
    </script>
</body>
</html>