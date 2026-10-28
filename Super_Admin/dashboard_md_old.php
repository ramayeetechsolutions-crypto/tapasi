<?php
require 'db.php';
session_start();

$pageTitle = "Dashboard";
$currentPage = "dashboard";
include 'header.php';

// Get current user
$current_user = $_SESSION['username'] ?? '';

// Count total clients
$client_count = 0;
$client_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM client_master");
if ($client_stmt) {
    $client_stmt->execute();
    $client_result = $client_stmt->get_result();
    $client_data = $client_result->fetch_assoc();
    $client_count = $client_data['total'];
    $client_stmt->close();
}

// Count active clients (clients with jobs)
$active_clients_count = 0;
$active_clients_stmt = $mysqli->prepare("SELECT COUNT(DISTINCT `client_id`) as total FROM `jobs`");
if ($active_clients_stmt) {
    $active_clients_stmt->execute();
    $active_clients_result = $active_clients_stmt->get_result();
    $active_clients_data = $active_clients_result->fetch_assoc();
    $active_clients_count = $active_clients_data['total'];
    $active_clients_stmt->close();
}

// Count left clients (inactive clients from client_master)
$left_clients_count = 0;
$left_clients_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM client_master WHERE status = 'Inactive'");
if ($left_clients_stmt) {
    $left_clients_stmt->execute();
    $left_clients_result = $left_clients_stmt->get_result();
    $left_clients_data = $left_clients_result->fetch_assoc();
    $left_clients_count = $left_clients_data['total'];
    $left_clients_stmt->close();
}

// Count inactive clients (clients without jobs but might still be active in client_master)
$inactive_clients_count = $client_count - $active_clients_count;

// Count jobs by status
$need_to_assign_count = 0;
$in_progress_count = 0;
$completed_count = 0;

// Get all jobs and calculate status
$jobs_sql = "
    SELECT 
        j.job_id, j.job_date,
        jm.job_name AS process,
        ce.status AS ce_status,
        le.status AS le_status,
        pag.status AS pag_status,
        qc.status AS qc_status
    FROM jobs j
    JOIN job_master jm ON j.process_id = jm.job_id
    LEFT JOIN ce ON ce.job_id = j.job_id
    LEFT JOIN le ON le.job_id = j.job_id
    LEFT JOIN pag ON pag.job_id = j.job_id
    LEFT JOIN qc ON qc.job_id = j.job_id
";

$jobs_stmt = $mysqli->prepare($jobs_sql);
if ($jobs_stmt) {
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
$today_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM jobs WHERE DATE(job_date) = ?");
if ($today_stmt) {
    $today_stmt->bind_param("s", $today);
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
   <!-- Dashboard Header -->
<div class="dashboard-header">
    <div class="d-flex justify-content-between align-items-center py-3">
        <div>
            <h1 class="dashboard-title mb-1"><font color="white"><i class="fas fa-chart-line me-2"></i>Dashboard</font></h1>
            <p class="dashboard-subtitle mb-0 text-muted"><font color="white">Welcome back, <strong><?php echo htmlspecialchars($current_user); ?></strong></font></p>
        </div>
        <div class="dashboard-date">
            <span class="date-badge bg-white text-dark">
                <i class="fas fa-calendar-alt me-1"></i><?php echo date('M j, Y'); ?>
            </span>
        </div>
    </div>
</div>
    <!-- Quick Stats Row -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="stat-card elevated">
                <div class="stat-header">
                    <i class="fas fa-users stat-icon"></i>
                    <h6>Total Clients</h6>
                </div>
                <div class="stat-body">
                    <h2><?= number_format($client_count) ?></h2>
                    <div class="stat-trend">
                        <span class="trend-up"><i class="fas fa-arrow-up"></i> All Time</span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="stat-card elevated">
                <div class="stat-header">
                    <i class="fas fa-tasks stat-icon"></i>
                    <h6>Total Jobs</h6>
                </div>
                <div class="stat-body">
                    <h2><?= number_format($total_jobs_count) ?></h2>
                    <div class="stat-trend">
                        <span class="trend-up"><i class="fas fa-plus-circle"></i> <?= $today_jobs_count ?> today</span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="stat-card elevated">
                <div class="stat-header">
                    <i class="fas fa-clock stat-icon"></i>
                    <h6>Pending</h6>
                </div>
                <div class="stat-body">
                    <h2><?= number_format($need_to_assign_count) ?></h2>
                    <div class="stat-trend">
                        <span><?= $total_jobs_count > 0 ? round(($need_to_assign_count / $total_jobs_count) * 100, 1) : 0 ?>% of total</span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="stat-card elevated">
                <div class="stat-header">
                    <i class="fas fa-check-circle stat-icon"></i>
                    <h6>Completed</h6>
                </div>
                <div class="stat-body">
                    <h2><?= number_format($completed_count) ?></h2>
                    <div class="stat-trend">
                        <span><?= $total_jobs_count > 0 ? round(($completed_count / $total_jobs_count) * 100, 1) : 0 ?>% completion rate</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Row -->
    <div class="row">
        <!-- Clients Section -->
        <div class="col-lg-6 mb-4">
            <div class="dashboard-card elevated">
                <div class="card-header">
                    <h5><i class="fas fa-users me-2"></i>Clients Overview</h5>
                    <span class="badge bg-primary"><?= $client_count ?> total</span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="mini-stat">
                                <div class="d-flex align-items-center">
                                    <div class="mini-stat-icon bg-success">
                                        <i class="fas fa-user-check"></i>
                                    </div>
                                    <div class="ms-3">
                                        <h4><?= number_format($active_clients_count) ?></h4>
                                        <p class="text-muted mb-0">Active Clients</p>
                                        <small class="text-success">
                                            <?= $client_count > 0 ? round(($active_clients_count / $client_count) * 100, 1) : 0 ?>%
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <div class="mini-stat">
                                <div class="d-flex align-items-center">
                                    <div class="mini-stat-icon bg-warning">
                                        <i class="fas fa-user-clock"></i>
                                    </div>
                                    <div class="ms-3">
                                        <h4><?= number_format($inactive_clients_count) ?></h4>
                                        <p class="text-muted mb-0">Without Jobs</p>
                                        <small class="text-warning">
                                            <?= $client_count > 0 ? round(($inactive_clients_count / $client_count) * 100, 1) : 0 ?>%
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mini-stat">
                                <div class="d-flex align-items-center">
                                    <div class="mini-stat-icon bg-danger">
                                        <i class="fas fa-user-times"></i>
                                    </div>
                                    <div class="ms-3">
                                        <h4><?= number_format($left_clients_count) ?></h4>
                                        <p class="text-muted mb-0">Left Clients</p>
                                        <small class="text-danger">
                                            <?= $client_count > 0 ? round(($left_clients_count / $client_count) * 100, 1) : 0 ?>%
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mini-stat">
                                <div class="d-flex align-items-center">
                                    <div class="mini-stat-icon bg-info">
                                        <i class="fas fa-calendar-day"></i>
                                    </div>
                                    <div class="ms-3">
                                        <h4><?= number_format($today_jobs_count) ?></h4>
                                        <p class="text-muted mb-0">Today's Jobs</p>
                                        <small class="text-info">New today</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Client Distribution Chart -->
                    <div class="mt-4">
                        <h6 class="section-subtitle">Client Distribution</h6>
                        <div class="distribution-chart">
                            <div class="distribution-item">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Active Clients</span>
                                    <span><?= $client_count > 0 ? round(($active_clients_count / $client_count) * 100, 1) : 0 ?>%</span>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-success" style="width: <?= $client_count > 0 ? ($active_clients_count / $client_count) * 100 : 0 ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="distribution-item mt-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Clients Without Jobs</span>
                                    <span><?= $client_count > 0 ? round(($inactive_clients_count / $client_count) * 100, 1) : 0 ?>%</span>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-warning" style="width: <?= $client_count > 0 ? ($inactive_clients_count / $client_count) * 100 : 0 ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="distribution-item mt-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Left Clients</span>
                                    <span><?= $client_count > 0 ? round(($left_clients_count / $client_count) * 100, 1) : 0 ?>%</span>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-danger" style="width: <?= $client_count > 0 ? ($left_clients_count / $client_count) * 100 : 0 ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Jobs Section -->
        <div class="col-lg-6 mb-4">
            <div class="dashboard-card elevated">
                <div class="card-header">
                    <h5><i class="fas fa-tasks me-2"></i>Jobs Overview</h5>
                    <span class="badge bg-primary"><?= $total_jobs_count ?> total</span>
                </div>
                <div class="card-body">
                    <!-- Job Status Cards -->
                    <div class="row mb-4">
                        <div class="col-4">
                            <div class="job-status-card status-pending">
                                <div class="status-icon">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="status-info">
                                    <h3><?= number_format($need_to_assign_count) ?></h3>
                                    <p>To Assign</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-4">
                            <div class="job-status-card status-progress">
                                <div class="status-icon">
                                    <i class="fas fa-spinner"></i>
                                </div>
                                <div class="status-info">
                                    <h3><?= number_format($in_progress_count) ?></h3>
                                    <p>In Progress</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-4">
                            <div class="job-status-card status-completed">
                                <div class="status-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="status-info">
                                    <h3><?= number_format($completed_count) ?></h3>
                                    <p>Completed</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Job Status Distribution -->
                    <div class="mt-4">
                        <h6 class="section-subtitle">Job Status Distribution</h6>
                        <div class="distribution-chart">
                            <div class="distribution-item">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Need to Assign</span>
                                    <span><?= $total_jobs_count > 0 ? round(($need_to_assign_count / $total_jobs_count) * 100, 1) : 0 ?>%</span>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-warning" style="width: <?= $total_jobs_count > 0 ? ($need_to_assign_count / $total_jobs_count) * 100 : 0 ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="distribution-item mt-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>In Progress</span>
                                    <span><?= $total_jobs_count > 0 ? round(($in_progress_count / $total_jobs_count) * 100, 1) : 0 ?>%</span>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-info" style="width: <?= $total_jobs_count > 0 ? ($in_progress_count / $total_jobs_count) * 100 : 0 ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="distribution-item mt-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Completed</span>
                                    <span><?= $total_jobs_count > 0 ? round(($completed_count / $total_jobs_count) * 100, 1) : 0 ?>%</span>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-success" style="width: <?= $total_jobs_count > 0 ? ($completed_count / $total_jobs_count) * 100 : 0 ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Team Overview -->
                    <div class="mt-4">
                        <h6 class="section-subtitle">Team Overview</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="team-stat">
                                    <div class="team-icon bg-primary">
                                        <i class="fas fa-building"></i>
                                    </div>
                                    <div class="team-info">
                                        <h5><?= number_format($active_clients_count) ?></h5>
                                        <p class="text-muted mb-0">WK Team Jobs</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="team-stat">
                                    <div class="team-icon bg-success">
                                        <i class="fas fa-chart-line"></i>
                                    </div>
                                    <div class="team-info">
                                        <h5><?= number_format($total_jobs_count) ?></h5>
                                        <p class="text-muted mb-0">Unique Team Jobs</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
:root {
    --primary-color: #4361ee;
    --secondary-color: #3f37c9;
    --success-color: #4cc9f0;
    --warning-color: #f8961e;
    --danger-color: #f94144;
    --info-color: #4895ef;
    --light-color: #f8f9fa;
    --dark-color: #212529;
    --border-radius: 12px;
    --box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    --box-shadow-hover: 0 8px 30px rgba(0, 0, 0, 0.12);
}

/* Dashboard Header */
.dashboard-header {
    background: #495057; /* Dark gray */
    border-radius: var(--border-radius);
    margin-bottom: 25px;
    border: none;
}

.dashboard-header.bg-gray-dark {
    background: linear-gradient(135deg, #495057 0%, #343a40 100%);
}

.dashboard-title {
    font-size: 1.8rem;
    font-weight: 600;
    margin: 0;
}

.dashboard-subtitle {
    font-size: 0.95rem;
}

.date-badge {
    background: white;
    padding: 8px 16px;
    border-radius: 50px;
    font-weight: 500;
    font-size: 0.9rem;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
    border: none;
}

/* White text for dark background */
.text-white-50 {
    color: rgba(255, 255, 255, 0.75) !important;
}

/* Adjust padding for better spacing */
.py-3.px-4 {
    padding: 0.75rem 1.5rem !important;
}

/* Optional: Add a subtle shadow for depth */
.dashboard-header {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}
/* Card Styles */
.dashboard-card {
    background: white;
    border-radius: var(--border-radius);
    border: none;
    height: 100%;
}

.dashboard-card .card-header {
    background: white;
    border-bottom: 1px solid #eef2f7;
    padding: 20px 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.dashboard-card .card-header h5 {
    margin: 0;
    font-weight: 600;
    color: var(--dark-color);
}

.dashboard-card .card-body {
    padding: 25px;
}

.elevated {
    box-shadow: var(--box-shadow);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.elevated:hover {
    transform: translateY(-5px);
    box-shadow: var(--box-shadow-hover);
}

/* Stat Cards */
.stat-card {
    background: white;
    border-radius: var(--border-radius);
    padding: 25px;
    height: 100%;
    border-left: 4px solid var(--primary-color);
}

.stat-header {
    display: flex;
    align-items: center;
    margin-bottom: 15px;
}

.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    margin-right: 15px;
}

.stat-header h6 {
    color: #6c757d;
    font-weight: 600;
    margin: 0;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-body h2 {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--dark-color);
    margin-bottom: 10px;
}

.stat-trend {
    font-size: 0.85rem;
}

.trend-up {
    color: var(--success-color);
    font-weight: 500;
}

/* Mini Stats */
.mini-stat {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 10px;
    border-left: 4px solid;
}

.mini-stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
}

.mini-stat h4 {
    font-size: 1.8rem;
    font-weight: 700;
    margin-bottom: 5px;
}

/* Job Status Cards */
.job-status-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    border: 1px solid #eef2f7;
    transition: all 0.3s ease;
}

.job-status-card:hover {
    transform: translateY(-3px);
}

.status-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    margin: 0 auto 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
}

.status-pending .status-icon {
    background: linear-gradient(135deg, #f8961e, #f9c74f);
}

.status-progress .status-icon {
    background: linear-gradient(135deg, #4895ef, #4cc9f0);
}

.status-completed .status-icon {
    background: linear-gradient(135deg, #38b000, #80ed99);
}

.job-status-card h3 {
    font-size: 1.8rem;
    font-weight: 700;
    margin-bottom: 5px;
}

.job-status-card p {
    color: #6c757d;
    font-size: 0.9rem;
    margin: 0;
}

/* Distribution Chart */
.distribution-chart {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 10px;
}

.distribution-item span {
    font-size: 0.9rem;
    font-weight: 500;
}

.section-subtitle {
    font-size: 1rem;
    font-weight: 600;
    color: var(--dark-color);
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #eef2f7;
}

/* Team Stats */
.team-stat {
    display: flex;
    align-items: center;
    background: #f8f9fa;
    padding: 20px;
    border-radius: 10px;
}

.team-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
    margin-right: 15px;
}

.team-info h5 {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 5px;
}

/* Progress Bars */
.progress {
    border-radius: 10px;
    background: #e9ecef;
}

.progress-bar {
    border-radius: 10px;
}

/* Responsive Design */
@media (max-width: 768px) {
    .dashboard-header {
        padding: 20px;
    }
    
    .dashboard-title {
        font-size: 1.8rem;
    }
    
    .dashboard-subtitle {
        font-size: 1rem;
    }
    
    .date-badge {
        padding: 8px 15px;
        font-size: 0.9rem;
    }
    
    .stat-card {
        padding: 20px;
    }
    
    .stat-body h2 {
        font-size: 2rem;
    }
    
    .mini-stat {
        padding: 15px;
    }
    
    .job-status-card {
        padding: 15px;
        margin-bottom: 15px;
    }
    
    .dashboard-card .card-body {
        padding: 20px;
    }
}

@media (max-width: 576px) {
    .dashboard-title {
        font-size: 1.5rem;
    }
    
    .stat-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .stat-icon {
        margin-bottom: 10px;
    }
    
    .job-status-card {
        margin-bottom: 15px;
    }
    
    .team-stat {
        margin-bottom: 15px;
    }
}

/* Loading Animation */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.dashboard-card, .stat-card {
    animation: fadeIn 0.6s ease-out;
}
</style>

<script>
// Smooth animations on scroll
document.addEventListener('DOMContentLoaded', function() {
    // Animate elements on scroll
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('animated');
            }
        });
    }, observerOptions);

    // Observe all cards
    document.querySelectorAll('.stat-card, .dashboard-card').forEach(card => {
        observer.observe(card);
    });

    // Update dashboard in real-time (simulated)
    function updateDashboard() {
        const dateElement = document.querySelector('.date-badge');
        if (dateElement) {
            const now = new Date();
            const options = { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            };
            dateElement.innerHTML = `<i class="fas fa-calendar-alt me-2"></i>${now.toLocaleDateString('en-US', options)}`;
        }
    }

    // Update every minute
    setInterval(updateDashboard, 60000);

    // Mobile menu toggle (if needed)
    const menuToggle = document.querySelector('.menu-toggle');
    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            document.querySelector('.main-nav').classList.toggle('active');
        });
    }
});
</script>

