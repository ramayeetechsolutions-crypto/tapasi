<?php
require 'db.php';
session_start();

$pageTitle = "Dashboard";
$currentPage = "dashboard";
include 'header.php';

// Get current user
$current_user = $_SESSION['username'] ?? '';
$ename = $_SESSION['ename'] ?? '';

// Get employee details including reporting manager
$employee_username = $current_user;
$sql = "SELECT `reporting_manager`, `employee_name` FROM `employee_master` WHERE `employee_username` = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("s", $employee_username);
$stmt->execute();
$result = $stmt->get_result();

$reporting_manager = '';
$current_user_name = '';
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $current_user_name = $row['employee_name'];
    $reporting_manager = $row['reporting_manager'];
}

// Get current week target details
$current_week_number = date('W');
$current_year = date('Y');
$weekly_target_data = [
    'target_limit' => 0,
    'achieved_target' => 0,
    'status' => 'No Target Set',
    'percentage' => 0,
    'remaining' => 0,
    'week_number' => $current_week_number,
    'year' => $current_year
];

$target_stmt = $mysqli->prepare("SELECT target_limit, achieved_target, status FROM weekly_targets 
                               WHERE tm_id = ? AND week_number = ? AND year = ?");
if ($target_stmt) {
    $target_stmt->bind_param("sii", $employee_username, $current_week_number, $current_year);
    $target_stmt->execute();
    $target_result = $target_stmt->get_result();
    
    if ($target_row = $target_result->fetch_assoc()) {
        $target_limit = (int)$target_row['target_limit'];
        $achieved = (int)$target_row['achieved_target'];
        $remaining = max(0, $target_limit - $achieved);
        $percentage = $target_limit > 0 ? round(($achieved / $target_limit) * 100, 2) : 0;
        
        $weekly_target_data = [
            'target_limit' => $target_limit,
            'achieved_target' => $achieved,
            'status' => $target_row['status'],
            'percentage' => $percentage,
            'remaining' => $remaining,
            'week_number' => $current_week_number,
            'year' => $current_year
        ];
    }
    $target_stmt->close();
}

// Count total clients
$client_count = 0;
$client_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM client_master WHERE status='Active'");
if ($client_stmt) {
    $client_stmt->execute();
    $client_result = $client_stmt->get_result();
    $client_data = $client_result->fetch_assoc();
    $client_count = $client_data['total'];
    $client_stmt->close();
}

// Count jobs by status
$need_to_assign_count = 0;
$in_progress_count = 0;
$completed_count = 0;

// Get all jobs and calculate status
$jobs_sql = "
    SELECT 
        j.job_id, 
        j.job_date,
        jm.job_name AS process,
        ce.status AS ce_status,
        le.status AS le_status,
        pag.status AS pag_status,
        qc.status AS qc_status
    FROM 
        jobs j
    JOIN 
        job_master jm ON j.process_id = jm.job_id
    LEFT JOIN 
        ce ON ce.job_id = j.job_id
    LEFT JOIN 
        le ON le.job_id = j.job_id
    LEFT JOIN 
        pag ON pag.job_id = j.job_id
    LEFT JOIN 
        qc ON qc.job_id = j.job_id
    WHERE 
        j.assigned_to = ?
";

$jobs_stmt = $mysqli->prepare($jobs_sql);
if ($jobs_stmt) {
    $jobs_stmt->bind_param("s", $current_user_name);
    $jobs_stmt->execute();
    $jobs_result = $jobs_stmt->get_result();
    
    // Process stage mapping
    $processStageMap = [
        'CE' => ['CE', 'LE'],
        'PAG' => ['PAG', 'QC'],
        'CE+PAG' => ['CE', 'LE', 'PAG', 'QC'],
        'CE+PAG+XML' => ['CE', 'LE', 'PAG', 'QC', 'XML'],
        'CE+XML' => ['CE', 'LE', 'XML'],
        'PAG+XML' => ['PAG', 'QC', 'XML'],
        'XML' => ['XML'],
    ];
    
    while ($row = $jobs_result->fetch_assoc()) {
        $process_name = $row['process'];
        $normalized_process = strtoupper(str_replace(' ', '', $process_name));
        $required_stages = $processStageMap[$normalized_process] ?? [];
        
        // Determine final status based on required stages
        $all_completed = true;
        $has_in_progress = false;
        $has_need_to_assign = false;
        
        foreach ($required_stages as $stage) {
            $status_field = strtolower($stage) . '_status';
            $stage_status = $row[$status_field] ?? 'Need to Assign';
            
            if ($stage_status !== 'Completed') {
                $all_completed = false;
                
                if ($stage_status === 'In Progress') {
                    $has_in_progress = true;
                } elseif ($stage_status === 'Need to Assign') {
                    $has_need_to_assign = true;
                }
            }
        }
        
        // Determine final status and count
        if ($all_completed) {
            $completed_count++;
        } elseif ($has_in_progress) {
            $in_progress_count++;
        } elseif ($has_need_to_assign) {
            $need_to_assign_count++;
        } else {
            $in_progress_count++;
        }
    }
    $jobs_stmt->close();
}

// Count today's new jobs
$today = date('Y-m-d');
$today_jobs_count = 0;
$today_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM jobs WHERE DATE(job_date) = ? AND assigned_to = ?");
if ($today_stmt) {
    $today_stmt->bind_param("ss", $today, $current_user_name);
    $today_stmt->execute();
    $today_result = $today_stmt->get_result();
    $today_data = $today_result->fetch_assoc();
    $today_jobs_count = $today_data['total'];
    $today_stmt->close();
}

// Total jobs count
$total_jobs_count = $need_to_assign_count + $in_progress_count + $completed_count;
?>

<main class="main-content">
    
    <div class="page-title-container">
        <h1><i class="fas fa-chart-line"></i>Dashboard</h1>
    </div>
    <p class="page-description">Welcome back! <?php echo htmlspecialchars($ename); ?></p>
    
    <!-- Weekly Target Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card weekly-target-card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-lg-8 col-md-7 mb-3 mb-md-0">
                            <h5 class="card-title mb-3"><i class="fas fa-bullseye me-2 text-primary"></i>Weekly Target Progress</h5>
                            <div class="d-flex flex-wrap align-items-center gap-4">
                                <div class="target-stat">
                                    <div class="target-stat-label">Target</div>
                                    <div class="target-stat-value"><?= number_format($weekly_target_data['target_limit']) ?></div>
                                    <div class="target-stat-unit">words</div>
                                </div>
                                <div class="target-stat">
                                    <div class="target-stat-label">Achieved</div>
                                    <div class="target-stat-value <?= $weekly_target_data['achieved_target'] >= $weekly_target_data['target_limit'] ? 'text-success' : 'text-primary' ?>">
                                        <?= number_format($weekly_target_data['achieved_target']) ?>
                                    </div>
                                    <div class="target-stat-unit">words</div>
                                </div>
                                <div class="target-stat">
                                    <div class="target-stat-label">Remaining</div>
                                    <div class="target-stat-value <?= $weekly_target_data['remaining'] > 0 ? 'text-warning' : 'text-success' ?>">
                                        <?= number_format($weekly_target_data['remaining']) ?>
                                    </div>
                                    <div class="target-stat-unit">words</div>
                                </div>
                                <div class="target-stat">
                                    <div class="target-stat-label">Progress</div>
                                    <div class="target-stat-value <?= $weekly_target_data['percentage'] >= 100 ? 'text-success' : ($weekly_target_data['percentage'] >= 70 ? 'text-warning' : 'text-danger') ?>">
                                        <?= $weekly_target_data['percentage'] ?>%
                                    </div>
                                    <div class="target-stat-unit">complete</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-5">
                            <div class="progress-container">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="progress-label">Week <?= $weekly_target_data['week_number'] ?>, <?= $weekly_target_data['year'] ?></span>
                                    <span class="badge status-badge bg-<?= $weekly_target_data['status'] === 'Completed' ? 'success' : ($weekly_target_data['status'] === 'Active' ? 'primary' : 'secondary') ?>">
                                        <?= $weekly_target_data['status'] ?>
                                    </span>
                                </div>
                                <div class="progress weekly-progress" style="height: 12px;">
                                    <div class="progress-bar <?= $weekly_target_data['percentage'] >= 100 ? 'bg-success' : ($weekly_target_data['percentage'] >= 70 ? 'bg-warning' : 'bg-danger') ?>" 
                                         role="progressbar" 
                                         style="width: <?= min($weekly_target_data['percentage'], 100) ?>%"
                                         aria-valuenow="<?= $weekly_target_data['percentage'] ?>" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100">
                                    </div>
                                </div>
                                <div class="progress-info mt-2">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        <?php if($weekly_target_data['target_limit'] > 0): ?>
                                            <?= $weekly_target_data['percentage'] >= 100 ? 'Target completed!' : ($weekly_target_data['percentage'] >= 70 ? 'Good progress!' : 'Keep going!') ?>
                                        <?php else: ?>
                                            No target set for this week
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="stats-cards">
        <div class="stat-card">
            <div class="stat-icon clients">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($client_count) ?></h3>
                <p>Total Clients</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon pending">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($need_to_assign_count) ?></h3>
                <p>Need to Assign</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon progress">
                <i class="fas fa-spinner"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($in_progress_count) ?></h3>
                <p>In Progress</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon completed">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($completed_count) ?></h3>
                <p>Completed</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon today">
                <i class="fas fa-calendar-day"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($today_jobs_count) ?></h3>
                <p>Today's Jobs</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon total">
                <i class="fas fa-tasks"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($total_jobs_count) ?></h3>
                <p>Total Jobs</p>
            </div>
        </div>
    </div>

    <!-- Quick Stats Section -->
    <div class="row mt-4">
        <div class="col-lg-6 col-md-12 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5><i class="fas fa-calendar-check me-2"></i>Today's Overview</h5>
                </div>
                <div class="card-body">
                    <div class="today-stats">
                        <div class="today-stat-item">
                            <div class="today-stat-icon bg-primary">
                                <i class="fas fa-plus-circle"></i>
                            </div>
                            <div class="today-stat-info">
                                <h4><?= number_format($today_jobs_count) ?></h4>
                                <p>Today's Jobs</p>
                            </div>
                        </div>
                        <div class="today-stat-item">
                            <div class="today-stat-icon bg-success">
                                <i class="fas fa-building"></i>
                            </div>
                            <div class="today-stat-info">
                                <h4><?= number_format($client_count) ?></h4>
                                <p>Active Clients</p>
                            </div>
                        </div>
                        <div class="today-stat-item">
                            <div class="today-stat-icon bg-info">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="today-stat-info">
                                <h4><?= number_format($total_jobs_count) ?></h4>
                                <p>Total Jobs</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6 col-md-12 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie me-2"></i>Job Status Distribution</h5>
                </div>
                <div class="card-body">
                    <div class="progress-stats">
                        <div class="progress-item">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="progress-label">
                                    <i class="fas fa-clock text-warning me-2"></i>Need to Assign
                                </span>
                                <span class="progress-percentage"><?= $total_jobs_count > 0 ? round(($need_to_assign_count / $total_jobs_count) * 100, 1) : 0 ?>%</span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar bg-warning" 
                                     style="width: <?= $total_jobs_count > 0 ? ($need_to_assign_count / $total_jobs_count) * 100 : 0 ?>%">
                                </div>
                            </div>
                        </div>
                        <div class="progress-item">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="progress-label">
                                    <i class="fas fa-spinner text-info me-2"></i>In Progress
                                </span>
                                <span class="progress-percentage"><?= $total_jobs_count > 0 ? round(($in_progress_count / $total_jobs_count) * 100, 1) : 0 ?>%</span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar bg-info" 
                                     style="width: <?= $total_jobs_count > 0 ? ($in_progress_count / $total_jobs_count) * 100 : 0 ?>%">
                                </div>
                            </div>
                        </div>
                        <div class="progress-item">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="progress-label">
                                    <i class="fas fa-check-circle text-success me-2"></i>Completed
                                </span>
                                <span class="progress-percentage"><?= $total_jobs_count > 0 ? round(($completed_count / $total_jobs_count) * 100, 1) : 0 ?>%</span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar bg-success" 
                                     style="width: <?= $total_jobs_count > 0 ? ($completed_count / $total_jobs_count) * 100 : 0 ?>%">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Additional Info Section -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-user-tie me-2"></i>Employee Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 col-sm-6 mb-3">
                            <div class="info-item">
                                <div class="info-label">Employee Name</div>
                                <div class="info-value"><?= htmlspecialchars($current_user_name) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4 col-sm-6 mb-3">
                            <div class="info-item">
                                <div class="info-label">Username</div>
                                <div class="info-value"><?= htmlspecialchars($employee_username) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4 col-sm-6 mb-3">
                            <div class="info-item">
                                <div class="info-label">Reporting Manager</div>
                                <div class="info-value"><?= htmlspecialchars($reporting_manager) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4 col-sm-6 mb-3">
                            <div class="info-item">
                                <div class="info-label">Current Week</div>
                                <div class="info-value">Week <?= $current_week_number ?>, <?= $current_year ?></div>
                            </div>
                        </div>
                        <div class="col-md-4 col-sm-6 mb-3">
                            <div class="info-item">
                                <div class="info-label">Today's Date</div>
                                <div class="info-value"><?= date('F d, Y') ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</main>

<style>
/* Weekly Target Card */
.weekly-target-card {
    /* background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); */
    background: #5e6b77 !important;
    color: white;
    border: none;
    border-radius: 12px;
    overflow: hidden;
}

.weekly-target-card .card-body {
    padding: 25px;
}

.weekly-target-card .card-title {
    color: white;
    font-weight: 600;
    font-size: 1.3rem;
}

.target-stat {
    text-align: center;
    min-width: 100px;
}

.target-stat-label {
    font-size: 0.85rem;
    opacity: 0.9;
    margin-bottom: 5px;
}

.target-stat-value {
    font-size: 1.8rem;
    font-weight: 700;
    line-height: 1;
}

.target-stat-unit {
    font-size: 0.8rem;
    opacity: 0.8;
    margin-top: 2px;
}

.progress-container {
    background: rgba(255, 255, 255, 0.1);
    padding: 20px;
    border-radius: 10px;
    backdrop-filter: blur(10px);
}

.progress-label {
    font-weight: 600;
    color: white;
}

.status-badge {
    font-size: 0.75rem;
    padding: 5px 10px;
}

.weekly-progress {
    background-color: rgba(255, 255, 255, 0.2);
    border-radius: 10px;
}

.weekly-progress .progress-bar {
    border-radius: 10px;
}

.progress-info {
    font-size: 0.85rem;
}

/* Stats Cards */
.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    border-left: 4px solid #007bff;
    display: flex;
    align-items: center;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 20px;
    font-size: 24px;
    color: white;
}

.stat-icon.clients { background: linear-gradient(45deg, #667eea, #764ba2); }
.stat-icon.pending { background: linear-gradient(45deg, #f093fb, #f5576c); }
.stat-icon.progress { background: linear-gradient(45deg, #4facfe, #00f2fe); }
.stat-icon.completed { background: linear-gradient(45deg, #43e97b, #38f9d7); }
.stat-icon.today { background: linear-gradient(45deg, #fa709a, #fee140); }
.stat-icon.total { background: linear-gradient(45deg, #a8edea, #fed6e3); }

.stat-info h3 {
    font-size: 2rem;
    font-weight: bold;
    margin: 0;
    color: #2c3e50;
}

.stat-info p {
    margin: 5px 0 0 0;
    color: #7f8c8d;
    font-size: 0.9rem;
}

/* Today Stats */
.today-stats {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.today-stat-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 10px;
    transition: background-color 0.3s ease;
}

.today-stat-item:hover {
    background: #e9ecef;
}

.today-stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
}

.today-stat-info h4 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: bold;
    color: #2c3e50;
}

.today-stat-info p {
    margin: 5px 0 0 0;
    color: #7f8c8d;
    font-size: 0.9rem;
}

/* Progress Stats */
.progress-stats {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.progress-item {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.progress-label {
    font-weight: 600;
    color: #2c3e50;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
}

.progress-percentage {
    font-weight: 600;
    color: #2c3e50;
}

.progress {
    height: 10px;
    border-radius: 5px;
    overflow: hidden;
}

.progress-bar {
    border-radius: 5px;
}

/* Info Items */
.info-item {
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
}

.info-label {
    font-size: 0.85rem;
    color: #6c757d;
    margin-bottom: 5px;
}

.info-value {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
}

/* General Card Styling */
.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    margin-bottom: 0;
}

.card-header {
    background: white;
    border-bottom: 1px solid #e9ecef;
    padding: 20px;
    border-radius: 12px 12px 0 0 !important;
}

.card-header h5 {
    margin: 0;
    color: #2c3e50;
    font-weight: 600;
}

.card-body {
    padding: 25px;
}

/* Page Header */
.page-title-container {
    /* background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); */
    background: #5e6b77 !important;
    color: white;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
}

.page-title-container h1 {
    margin: 0;
    font-size: 2.5rem;
    font-weight: 700;
}

.page-description {
    color: #6c757d;
    font-size: 1.1rem;
    margin-bottom: 30px;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .stats-cards {
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    }
}

@media (max-width: 992px) {
    .weekly-target-card .row > div {
        margin-bottom: 20px;
    }
    
    .target-stat {
        min-width: 80px;
    }
    
    .target-stat-value {
        font-size: 1.5rem;
    }
}

@media (max-width: 768px) {
    .stats-cards {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
    
    .stat-card {
        padding: 15px;
    }
    
    .stat-icon {
        width: 50px;
        height: 50px;
        font-size: 20px;
        margin-right: 15px;
    }
    
    .stat-info h3 {
        font-size: 1.5rem;
    }
    
    .page-title-container h1 {
        font-size: 2rem;
    }
    
    .today-stat-icon {
        width: 40px;
        height: 40px;
        font-size: 1.2rem;
    }
    
    .today-stat-info h4 {
        font-size: 1.2rem;
    }
}

@media (max-width: 576px) {
    .stats-cards {
        grid-template-columns: 1fr;
    }
    
    .target-stat {
        min-width: 70px;
    }
    
    .target-stat-value {
        font-size: 1.3rem;
    }
    
    .weekly-target-card .card-body {
        padding: 15px;
    }
    
    .progress-container {
        padding: 15px;
    }
}
</style>

<script>
// Mobile menu toggle
const menuToggle = document.createElement('div');
menuToggle.className = 'menu-toggle';
menuToggle.innerHTML = '<span></span><span></span><span></span>';

const headerContainer = document.querySelector('.header-container');
headerContainer.insertBefore(menuToggle, headerContainer.firstChild);

const mainNav = document.querySelector('.main-nav');

menuToggle.addEventListener('click', () => {
    menuToggle.classList.toggle('active');
    mainNav.classList.toggle('active');
});

// Close menu when clicking on a link (for mobile)
const navLinks = document.querySelectorAll('.nav-menu a');
navLinks.forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 992) {
            menuToggle.classList.remove('active');
            mainNav.classList.remove('active');
        }
    });
});

// Add animation to stats cards on page load
document.addEventListener('DOMContentLoaded', function() {
    const statCards = document.querySelectorAll('.stat-card');
    statCards.forEach((card, index) => {
        setTimeout(() => {
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
});
</script>

</body>
</html>