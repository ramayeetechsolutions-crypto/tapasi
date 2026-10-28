<br><br>
<?php
// ap_progress.php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Get current user
$current_user = $_SESSION['username'] ?? 'System';

// Initialize message variables
$message = '';
$message_type = '';

// Get AP ID from URL
$ap_id = isset($_GET['ap_id']) ? intval($_GET['ap_id']) : 0;

// Fetch AP details
$ap_details = null;
if ($ap_id > 0) {
    $ap_query = $mysqli->query("SELECT * FROM ap_details WHERE id = $ap_id");
    if ($ap_query && $ap_row = $ap_query->fetch_assoc()) {
        $ap_details = $ap_row;
    }
}

// Redirect if no valid AP ID
//if (!$ap_details) {
  //  header('Location: ap_management.php');
//    exit();
//}

// Get employees for dropdown
$employees_query = $mysqli->query("SELECT employee_username, employee_name FROM employee_master WHERE status = 'Active' ORDER BY employee_name");
$employees = [];
if ($employees_query) {
    while ($row = $employees_query->fetch_assoc()) {
        $employees[] = $row;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_progress'])) {
        // Add new progress record
        $article_name = $mysqli->real_escape_string($_POST['article_name']);
        $complexity = $_POST['complexity'];
        $employee_username = $_POST['employee_username'];
        $location = $mysqli->real_escape_string($_POST['location']);
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $dispatch_date = $_POST['dispatch_date'];
        $status = $_POST['status'];
        $qc_status = $_POST['qc_status'];
        $qc_remarks = $mysqli->real_escape_string($_POST['qc_remarks']);
        $remarks = $mysqli->real_escape_string($_POST['remarks']);
        
        // Calculate TAT in minutes
        $tat_minutes = 0;
        if (!empty($start_time) && !empty($end_time)) {
            $start = new DateTime($start_time);
            $end = new DateTime($end_time);
            $interval = $start->diff($end);
            $tat_minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
        }
        
        // Set QC checked info if status is not Pending
        $qc_checked_by = ($qc_status != 'Pending') ? $current_user : null;
        $qc_checked_date = ($qc_status != 'Pending') ? date('Y-m-d H:i:s') : null;
        
        $sql = "INSERT INTO ap_progress (ap_id, article_name, complexity, employee_username, location, 
                start_time, end_time, tat_minutes, dispatch_date, status, qc_status, qc_remarks, 
                qc_checked_by, qc_checked_date, remarks, created_by) 
                VALUES ($ap_id, '$article_name', '$complexity', '$employee_username', '$location', 
                '$start_time', '$end_time', $tat_minutes, '$dispatch_date', '$status', 
                '$qc_status', '$qc_remarks', '$qc_checked_by', '$qc_checked_date', '$remarks', '$current_user')";
        
        if ($mysqli->query($sql)) {
            updateAPStatus($ap_id, $mysqli);
            $message = "Progress record added successfully!";
            $message_type = "success";
        } else {
            $message = "Error adding progress: " . $mysqli->error;
            $message_type = "danger";
        }
    }
    elseif (isset($_POST['update_progress'])) {
        // Update existing progress record
        $id = intval($_POST['id']);
        $article_name = $mysqli->real_escape_string($_POST['article_name']);
        $complexity = $_POST['complexity'];
        $employee_username = $_POST['employee_username'];
        $location = $mysqli->real_escape_string($_POST['location']);
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $dispatch_date = $_POST['dispatch_date'];
        $status = $_POST['status'];
        $qc_status = $_POST['qc_status'];
        $qc_remarks = $mysqli->real_escape_string($_POST['qc_remarks']);
        $remarks = $mysqli->real_escape_string($_POST['remarks']);
        
        // Calculate TAT in minutes
        $tat_minutes = 0;
        if (!empty($start_time) && !empty($end_time)) {
            $start = new DateTime($start_time);
            $end = new DateTime($end_time);
            $interval = $start->diff($end);
            $tat_minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
        }
        
        // Update QC checked info if status changed from Pending
        $qc_update = '';
        if (isset($_POST['qc_status_changed']) && $_POST['qc_status_changed'] == '1') {
            $qc_checked_by = $current_user;
            $qc_checked_date = date('Y-m-d H:i:s');
            $qc_update = ", qc_checked_by = '$qc_checked_by', qc_checked_date = '$qc_checked_date'";
        }
        
        $sql = "UPDATE ap_progress SET 
                article_name = '$article_name',
                complexity = '$complexity',
                employee_username = '$employee_username',
                location = '$location',
                start_time = '$start_time',
                end_time = '$end_time',
                tat_minutes = $tat_minutes,
                dispatch_date = '$dispatch_date',
                status = '$status',
                qc_status = '$qc_status',
                qc_remarks = '$qc_remarks',
                remarks = '$remarks'
                $qc_update
                WHERE id = $id AND ap_id = $ap_id";
        
        if ($mysqli->query($sql)) {
            updateAPStatus($ap_id, $mysqli);
            $message = "Progress record updated successfully!";
            $message_type = "success";
        } else {
            $message = "Error updating progress: " . $mysqli->error;
            $message_type = "danger";
        }
    }
    elseif (isset($_POST['delete_progress'])) {
        // Delete progress record
        $id = intval($_POST['id']);
        
        $sql = "DELETE FROM ap_progress WHERE id = $id AND ap_id = $ap_id";
        
        if ($mysqli->query($sql)) {
            updateAPStatus($ap_id, $mysqli);
            $message = "Progress record deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Error deleting progress: " . $mysqli->error;
            $message_type = "danger";
        }
    }
    
    // QC Update Only
    elseif (isset($_POST['update_qc_only'])) {
        $id = intval($_POST['id']);
        $qc_status = $_POST['qc_status'];
        $qc_remarks = $mysqli->real_escape_string($_POST['qc_remarks']);
        
        $sql = "UPDATE ap_progress SET 
                qc_status = '$qc_status',
                qc_remarks = '$qc_remarks',
                qc_checked_by = '$current_user',
                qc_checked_date = NOW()
                WHERE id = $id AND ap_id = $ap_id";
        
        if ($mysqli->query($sql)) {
            $message = "QC status updated successfully!";
            $message_type = "success";
        } else {
            $message = "Error updating QC: " . $mysqli->error;
            $message_type = "danger";
        }
    }
    
    // Auto-create progress records based on articles count
    elseif (isset($_POST['auto_create_articles'])) {
        $articles_count = $ap_details['articles'];
        
        if ($articles_count > 0) {
            $created_count = 0;
            for ($i = 1; $i <= $articles_count; $i++) {
                $article_name = "Article " . $i;
                $sql = "INSERT INTO ap_progress (ap_id, article_name, complexity, status, qc_status, created_by) 
                        VALUES ($ap_id, '$article_name', 'AP_CRX', 'Pending', 'Pending', '$current_user')";
                if ($mysqli->query($sql)) {
                    $created_count++;
                }
            }
            
            if ($created_count > 0) {
                updateAPStatus($ap_id, $mysqli);
                $message = "Auto-created $created_count progress records!";
                $message_type = "success";
            } else {
                $message = "Error creating progress records";
                $message_type = "danger";
            }
        }
    }
}

// Function to update AP status based on progress
function updateAPStatus($ap_id, $mysqli) {
    // Get all progress records for this AP
    $progress_query = $mysqli->query("SELECT status FROM ap_progress WHERE ap_id = $ap_id");
    $total_articles = 0;
    $completed_articles = 0;
    $in_progress_articles = 0;
    $on_hold_articles = 0;
    
    if ($progress_query) {
        while ($row = $progress_query->fetch_assoc()) {
            $total_articles++;
            if ($row['status'] == 'Completed') {
                $completed_articles++;
            } elseif ($row['status'] == 'In Progress') {
                $in_progress_articles++;
            } elseif ($row['status'] == 'On Hold') {
                $on_hold_articles++;
            }
        }
    }
    
    // Determine overall AP status
    $ap_status = 'Pending';
    if ($total_articles > 0) {
        if ($completed_articles == $total_articles) {
            $ap_status = 'Completed';
        } elseif ($in_progress_articles > 0 || $completed_articles > 0) {
            $ap_status = 'In Progress';
        } elseif ($on_hold_articles > 0) {
            $ap_status = 'On Hold';
        }
    }
    
    // Update AP status
    $mysqli->query("UPDATE ap_details SET status = '$ap_status' WHERE id = $ap_id");
}

// Fetch all progress records for this AP with employee names
$progress_query = $mysqli->query("
    SELECT ap.*, em.employee_name 
    FROM ap_progress ap 
    LEFT JOIN employee_master em ON ap.employee_username = em.employee_username 
    WHERE ap.ap_id = $ap_id 
    ORDER BY ap.id ASC
");
$progress_records = [];
if ($progress_query) {
    $progress_records = $progress_query->fetch_all(MYSQLI_ASSOC);
}

// Calculate statistics
$total_articles = count($progress_records);
$completed_articles = 0;
$total_tat = 0;
$qc_passed = 0;
$qc_failed = 0;

foreach ($progress_records as $progress) {
    if ($progress['status'] == 'Completed') {
        $completed_articles++;
        $total_tat += $progress['tat_minutes'];
    }
    if ($progress['qc_status'] == 'Passed') {
        $qc_passed++;
    } elseif ($progress['qc_status'] == 'Failed') {
        $qc_failed++;
    }
}

$completion_rate = $total_articles > 0 ? round(($completed_articles / $total_articles) * 100, 2) : 0;
$avg_tat = $completed_articles > 0 ? round($total_tat / $completed_articles, 2) : 0;
$qc_pass_rate = $total_articles > 0 ? round(($qc_passed / $total_articles) * 100, 2) : 0;

// Include header
include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AP Progress Tracking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .card { box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); }
        .table th { background-color: #2c3e50; color: white; }
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 0.85em; font-weight: 500; }
        .status-Pending { background-color: #fff3cd; color: #856404; }
        .status-In-Progress { background-color: #d1ecf1; color: #0c5460; }
        .status-Completed { background-color: #d4edda; color: #155724; }
        .status-On-Hold { background-color: #f8d7da; color: #721c24; }
        .qc-badge { padding: 4px 8px; border-radius: 4px; font-size: 0.85em; font-weight: 500; }
        .qc-Pending { background-color: #e2e3e5; color: #383d41; }
        .qc-Passed { background-color: #d4edda; color: #155724; }
        .qc-Failed { background-color: #f8d7da; color: #721c24; }
        .qc-Revised { background-color: #fff3cd; color: #856404; }
        .complexity-badge { font-size: 0.8em; padding: 3px 6px; }
        .complexity-AP_CRX { background-color: #0dcaf0; color: white; }
        .complexity-PAG_QC { background-color: #20c997; color: white; }
        .complexity-AP_QC { background-color: #ffc107; color: black; }
        .complexity-CE_REF { background-color: #6f42c1; color: white; }
        .ap-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .stat-card { border-radius: 10px; padding: 15px; text-align: center; color: white; margin-bottom: 15px; }
        .stat-total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-completed { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-qc { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-tat { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .btn-time-now { font-size: 0.8em; padding: 2px 6px; }
        .employee-badge { background-color: #6c757d; color: white; font-size: 0.8em; padding: 2px 6px; }
        .location-badge { background-color: #17a2b8; color: white; font-size: 0.8em; padding: 2px 6px; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="container-fluid mt-4">
        <!-- AP Header Card -->
        <div class="card mb-4 ap-header border-0">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-1"><i class="fas fa-file-alt me-2"></i>AP Progress Tracking</h4>
                        <h5 class="mb-0">Client: <?= htmlspecialchars($ap_details['client_name']) ?> | AP #<?= $ap_id ?></h5>
                        <p class="mb-0 small opacity-75">
                            Issue: <?= htmlspecialchars($ap_details['issue']) ?> | 
                            Articles: <?= $ap_details['articles'] ?> | 
                            Due: <?= date('d-M-Y', strtotime($ap_details['due_date'])) ?> |
                            AP Status: <span class="badge status-<?= str_replace(' ', '-', $ap_details['status']) ?>"><?= $ap_details['status'] ?></span>
                        </p>
                    </div>
                    <div>
                        <a href="ap_management.php" class="btn btn-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i> Back to AP List
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Message Display -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card stat-total">
                    <i class="fas fa-file-alt fa-2x mb-2"></i>
                    <h4><?= $total_articles ?></h4>
                    <p>Total Articles</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-completed">
                    <i class="fas fa-check-circle fa-2x mb-2"></i>
                    <h4><?= $completed_articles ?></h4>
                    <p>Completed</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-qc">
                    <i class="fas fa-clipboard-check fa-2x mb-2"></i>
                    <h4><?= $qc_pass_rate ?>%</h4>
                    <p>QC Pass Rate</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-tat">
                    <i class="fas fa-clock fa-2x mb-2"></i>
                    <h4><?= $avg_tat ?> min</h4>
                    <p>Avg TAT</p>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Article Progress Management</h6>
                        <small class="text-muted">Track individual article progress, assign employees, and QC status</small>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProgressModal">
                            <i class="fas fa-plus-circle me-2"></i>Add Article
                        </button>
                        <?php if ($ap_details['articles'] > 0 && $total_articles == 0): ?>
                            <form method="POST" style="display: inline;">
                                <button type="submit" name="auto_create_articles" class="btn btn-success ms-2">
                                    <i class="fas fa-magic me-2"></i>Auto Create <?= $ap_details['articles'] ?> Articles
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Progress Records Table -->
        <div class="card">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="fas fa-tasks me-2"></i>Article Progress Details</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Article</th>
                                <th>Complexity</th>
                                <th>Employee</th>
                                <th>Location</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>TAT</th>
                                <th>Dispatch</th>
                                <th>Status</th>
                                <th>QC Status</th>
                                <th>Remarks</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($progress_records)): ?>
                                <tr>
                                    <td colspan="13" class="text-center py-4 text-muted">
                                        <i class="fas fa-tasks fa-3x mb-3"></i>
                                        <h5>No progress records found</h5>
                                        <p>Click "Add Article" to start tracking progress</p>
                                        <?php if ($ap_details['articles'] > 0): ?>
                                            <p class="small">or use "Auto Create" to generate <?= $ap_details['articles'] ?> articles</p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $counter = 1; ?>
                                <?php foreach ($progress_records as $progress): ?>
                                    <?php
                                    // Format TAT
                                    $tat_hours = floor($progress['tat_minutes'] / 60);
                                    $tat_min = $progress['tat_minutes'] % 60;
                                    $tat_display = $progress['tat_minutes'] > 0 ? 
                                        sprintf("%dh %02dm", $tat_hours, $tat_min) : '-';
                                    
                                    // Format times
                                    $start_time_display = $progress['start_time'] ? 
                                        date('d-M-Y H:i', strtotime($progress['start_time'])) : '-';
                                    $end_time_display = $progress['end_time'] ? 
                                        date('d-M-Y H:i', strtotime($progress['end_time'])) : '-';
                                    
                                    // Check dispatch vs due date
                                    $dispatch_info = '';
                                    if ($progress['dispatch_date']) {
                                        $dispatch_date = new DateTime($progress['dispatch_date']);
                                        $due_date = new DateTime($ap_details['due_date']);
                                        $interval = $due_date->diff($dispatch_date);
                                        $days_diff = $interval->days * ($interval->invert ? -1 : 1);
                                        
                                        if ($days_diff < 0) {
                                            $dispatch_info = '<span class="badge bg-success ms-1">' . abs($days_diff) . 'd early</span>';
                                        } elseif ($days_diff > 0) {
                                            $dispatch_info = '<span class="badge bg-danger ms-1">+' . $days_diff . 'd late</span>';
                                        } else {
                                            $dispatch_info = '<span class="badge bg-info ms-1">On time</span>';
                                        }
                                    }
                                    
                                    // QC checked info
                                    $qc_info = '';
                                    if ($progress['qc_checked_by'] && $progress['qc_checked_date']) {
                                        $qc_info = '<br><small class="text-muted">By: ' . $progress['qc_checked_by'] . 
                                                  ' on ' . date('d-M H:i', strtotime($progress['qc_checked_date'])) . '</small>';
                                    }
                                    ?>
                                    <tr>
                                        <td class="fw-bold"><?= $counter++ ?></td>
                                        <td><strong><?= htmlspecialchars($progress['article_name']) ?></strong></td>
                                        <td>
                                            <span class="badge complexity-badge complexity-<?= str_replace('/', '_', $progress['complexity']) ?>">
                                                <?= $progress['complexity'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($progress['employee_username']): ?>
                                                <span class="employee-badge">
                                                    <?= htmlspecialchars($progress['employee_name'] ?? $progress['employee_username']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($progress['location']): ?>
                                                <span class="location-badge"><?= htmlspecialchars($progress['location']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small"><?= $start_time_display ?></td>
                                        <td class="small"><?= $end_time_display ?></td>
                                        <td><span class="badge bg-secondary"><?= $tat_display ?></span></td>
                                        <td>
                                            <?php if ($progress['dispatch_date']): ?>
                                                <?= date('d-M-Y', strtotime($progress['dispatch_date'])) ?>
                                                <?= $dispatch_info ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= str_replace(' ', '-', $progress['status']) ?>">
                                                <?= $progress['status'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="qc-badge qc-<?= $progress['qc_status'] ?>">
                                                <?= $progress['qc_status'] ?>
                                            </span>
                                            <?= $qc_info ?>
                                        </td>
                                        <td class="small">
                                            <?= htmlspecialchars($progress['remarks'] ?: '-') ?>
                                            <?php if ($progress['qc_remarks']): ?>
                                                <br><small class="text-danger">QC: <?= htmlspecialchars($progress['qc_remarks']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button type="button" class="btn btn-outline-primary" 
                                                        data-bs-toggle="modal" data-bs-target="#editProgressModal"
                                                        onclick="editProgress(<?= htmlspecialchars(json_encode($progress)) ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-info" 
                                                        data-bs-toggle="modal" data-bs-target="#qcModal"
                                                        onclick="editQC(<?= htmlspecialchars(json_encode($progress)) ?>)">
                                                    <i class="fas fa-clipboard-check"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger" 
                                                        data-bs-toggle="modal" data-bs-target="#deleteProgressModal"
                                                        onclick="setDeleteId(<?= $progress['id'] ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Progress Modal -->
    <div class="modal fade" id="addProgressModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add Article Progress</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Article Name *</label>
                                <input type="text" name="article_name" class="form-control" placeholder="Enter article name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Complexity *</label>
                                <select name="complexity" class="form-select" required>
                                    <option value="AP_CRX">AP_CRX</option>
                                    <option value="PAG/QC">PAG/QC</option>
                                    <option value="AP_QC">AP_QC</option>
                                    <option value="CE_REF">CE_REF</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Assigned Employee</label>
                                <select name="employee_username" class="form-select">
                                    <option value="">Select Employee</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?= $emp['employee_username'] ?>">
                                            <?= htmlspecialchars($emp['employee_name'] . ' (' . $emp['employee_username'] . ')') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Location</label>
                                <input type="text" name="location" class="form-control" placeholder="Enter location">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Start Time</label>
                                <div class="input-group">
                                    <input type="datetime-local" name="start_time" class="form-control" id="start_time">
                                    <button type="button" class="btn btn-outline-secondary btn-time-now" onclick="setCurrentTime('start_time')">
                                        <i class="fas fa-clock"></i> Now
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Time</label>
                                <div class="input-group">
                                    <input type="datetime-local" name="end_time" class="form-control" id="end_time">
                                    <button type="button" class="btn btn-outline-secondary btn-time-now" onclick="setCurrentTime('end_time')">
                                        <i class="fas fa-clock"></i> Now
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Dispatch Date</label>
                                <input type="date" name="dispatch_date" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="Pending">Pending</option>
                                    <option value="In Progress">In Progress</option>
                                    <option value="Completed">Completed</option>
                                    <option value="On Hold">On Hold</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">QC Status</label>
                                <select name="qc_status" class="form-select">
                                    <option value="Pending">Pending</option>
                                    <option value="Passed">Passed</option>
                                    <option value="Failed">Failed</option>
                                    <option value="Revised">Revised</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">QC Remarks</label>
                                <textarea name="qc_remarks" class="form-control" rows="2" placeholder="QC remarks"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">General Remarks</label>
                                <textarea name="remarks" class="form-control" rows="2" placeholder="General remarks"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_progress" class="btn btn-primary">Save Progress</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Progress Modal -->
    <div class="modal fade" id="editProgressModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Article Progress</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="id" id="edit_id">
                    <input type="hidden" name="qc_status_changed" id="qc_status_changed" value="0">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Article Name *</label>
                                <input type="text" name="article_name" id="edit_article_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Complexity *</label>
                                <select name="complexity" id="edit_complexity" class="form-select" required>
                                    <option value="AP_CRX">AP_CRX</option>
                                    <option value="PAG/QC">PAG/QC</option>
                                    <option value="AP_QC">AP_QC</option>
                                    <option value="CE_REF">CE_REF</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Assigned Employee</label>
                                <select name="employee_username" id="edit_employee_username" class="form-select">
                                    <option value="">Select Employee</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?= $emp['employee_username'] ?>">
                                            <?= htmlspecialchars($emp['employee_name'] . ' (' . $emp['employee_username'] . ')') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Location</label>
                                <input type="text" name="location" id="edit_location" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Start Time</label>
                                <div class="input-group">
                                    <input type="datetime-local" name="start_time" id="edit_start_time" class="form-control">
                                    <button type="button" class="btn btn-outline-secondary btn-time-now" onclick="setCurrentTime('edit_start_time')">
                                        <i class="fas fa-clock"></i> Now
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Time</label>
                                <div class="input-group">
                                    <input type="datetime-local" name="end_time" id="edit_end_time" class="form-control">
                                    <button type="button" class="btn btn-outline-secondary btn-time-now" onclick="setCurrentTime('edit_end_time')">
                                        <i class="fas fa-clock"></i> Now
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Dispatch Date</label>
                                <input type="date" name="dispatch_date" id="edit_dispatch_date" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select name="status" id="edit_status" class="form-select">
                                    <option value="Pending">Pending</option>
                                    <option value="In Progress">In Progress</option>
                                    <option value="Completed">Completed</option>
                                    <option value="On Hold">On Hold</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">QC Status</label>
                                <select name="qc_status" id="edit_qc_status" class="form-select" onchange="document.getElementById('qc_status_changed').value='1'">
                                    <option value="Pending">Pending</option>
                                    <option value="Passed">Passed</option>
                                    <option value="Failed">Failed</option>
                                    <option value="Revised">Revised</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">QC Remarks</label>
                                <textarea name="qc_remarks" id="edit_qc_remarks" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">General Remarks</label>
                                <textarea name="remarks" id="edit_remarks" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_progress" class="btn btn-warning">Update Progress</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- QC Only Modal -->
    <div class="modal fade" id="qcModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-clipboard-check me-2"></i>Update QC Status</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="id" id="qc_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Article</label>
                                <input type="text" id="qc_article_name" class="form-control" readonly>
                            </div>
                            <div class="col-12">
                                <label class="form-label">QC Status *</label>
                                <select name="qc_status" id="qc_qc_status" class="form-select" required>
                                    <option value="Pending">Pending</option>
                                    <option value="Passed">Passed</option>
                                    <option value="Failed">Failed</option>
                                    <option value="Revised">Revised</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">QC Remarks</label>
                                <textarea name="qc_remarks" id="qc_qc_remarks" class="form-control" rows="3" placeholder="Enter QC remarks"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_qc_only" class="btn btn-info">Update QC</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteProgressModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="id" id="delete_progress_id">
                    <div class="modal-body">
                        <p>Are you sure you want to delete this progress record?</p>
                        <p class="text-danger"><strong>This action cannot be undone.</strong></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_progress" class="btn btn-danger">Delete Record</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editProgress(progress) {
            document.getElementById('edit_id').value = progress.id;
            document.getElementById('edit_article_name').value = progress.article_name;
            document.getElementById('edit_complexity').value = progress.complexity;
            document.getElementById('edit_employee_username').value = progress.employee_username || '';
            document.getElementById('edit_location').value = progress.location || '';
            document.getElementById('edit_start_time').value = progress.start_time ? progress.start_time.substring(0, 16) : '';
            document.getElementById('edit_end_time').value = progress.end_time ? progress.end_time.substring(0, 16) : '';
            document.getElementById('edit_dispatch_date').value = progress.dispatch_date || '';
            document.getElementById('edit_status').value = progress.status;
            document.getElementById('edit_qc_status').value = progress.qc_status;
            document.getElementById('edit_qc_remarks').value = progress.qc_remarks || '';
            document.getElementById('edit_remarks').value = progress.remarks || '';
            document.getElementById('qc_status_changed').value = '0';
        }
        
        function editQC(progress) {
            document.getElementById('qc_id').value = progress.id;
            document.getElementById('qc_article_name').value = progress.article_name;
            document.getElementById('qc_qc_status').value = progress.qc_status;
            document.getElementById('qc_qc_remarks').value = progress.qc_remarks || '';
        }

        function setDeleteId(id) {
            document.getElementById('delete_progress_id').value = id;
        }
        
        function setCurrentTime(fieldId) {
            const now = new Date();
            const localDateTime = now.toISOString().slice(0, 16);
            document.getElementById(fieldId).value = localDateTime;
        }
        
        // Auto-set dispatch date to due date
        document.addEventListener('DOMContentLoaded', function() {
            const dispatchDateInput = document.querySelector('input[name="dispatch_date"]');
            const editDispatchDateInput = document.getElementById('edit_dispatch_date');
            
            // Set default dispatch date to AP due date
            const dueDate = '<?= $ap_details['due_date'] ?>';
            if (dueDate && dispatchDateInput && !dispatchDateInput.value) {
                dispatchDateInput.value = dueDate;
            }
        });
    </script>
</body>
</html>