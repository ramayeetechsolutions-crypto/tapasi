<?php
require_once __DIR__ . '/../db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = "Dashboard";
$currentPage = "dashboard";
include_once __DIR__ . '/header.php';

// Get current user
$current_user = $_SESSION['username'] ?? '';

// Count total clients
// $client_count = 0;
// $client_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM client_master WHERE status='Active'");
// if ($client_stmt) {
//     $client_stmt->execute();
//     $client_result = $client_stmt->get_result();
//     $client_data = $client_result->fetch_assoc();
//     $client_count = $client_data['total'];
//     $client_stmt->close();
// }

// // Count jobs by status
// $need_to_assign_count = 0;
// $in_progress_count = 0;
// $completed_count = 0;

// // Get all jobs and calculate status
// $jobs_sql = "
//     SELECT 
//         j.job_id, j.job_date,
//         jm.job_name AS process,
//         ce.status AS ce_status,
//         le.status AS le_status,
//         pag.status AS pag_status,
//         qc.status AS qc_status
//     FROM jobs j
//     JOIN job_master jm ON j.process_id = jm.job_id
//     LEFT JOIN ce ON ce.job_id = j.job_id
//     LEFT JOIN le ON le.job_id = j.job_id
//     LEFT JOIN pag ON pag.job_id = j.job_id
//     LEFT JOIN qc ON qc.job_id = j.job_id
// ";

// $jobs_stmt = $mysqli->prepare($jobs_sql);
// if ($jobs_stmt) {
//     $jobs_stmt->execute();
//     $jobs_result = $jobs_stmt->get_result();
    
//     // Process stage mapping
//     $processStageMap = [
//         'CE' => ['CE', 'LE'],
//         'PAG' => ['PAG', 'QC'],
//         'CE+PAG' => ['CE', 'LE', 'PAG', 'QC'],
//         'CE+PAG+XML' => ['CE', 'LE', 'PAG', 'QC', 'XML'],
//         'CE+XML' => ['CE', 'LE', 'XML'],
//         'PAG+XML' => ['PAG', 'QC', 'XML'],
//         'XML' => ['XML'],
//     ];
    
//     while ($row = $jobs_result->fetch_assoc()) {
//         $process_name = $row['process'];
//         $normalized_process = strtoupper(str_replace(' ', '', $process_name));
//         $required_stages = $processStageMap[$normalized_process] ?? [];
        
//         // Determine final status based on required stages
//         $all_completed = true;
//         $has_in_progress = false;
//         $has_need_to_assign = false;
        
//         foreach ($required_stages as $stage) {
//             $status_field = strtolower($stage) . '_status';
//             $stage_status = $row[$status_field] ?? 'Need to Assign';
            
//             if ($stage_status !== 'Completed') {
//                 $all_completed = false;
                
//                 if ($stage_status === 'In Progress') {
//                     $has_in_progress = true;
//                 } elseif ($stage_status === 'Need to Assign') {
//                     $has_need_to_assign = true;
//                 }
//             }
//         }
        
//         // Determine final status and count
//         if ($all_completed) {
//             $completed_count++;
//         } elseif ($has_in_progress) {
//             $in_progress_count++;
//         } elseif ($has_need_to_assign) {
//             $need_to_assign_count++;
//         } else {
//             $in_progress_count++;
//         }
//     }
//     $jobs_stmt->close();
// }

// // Count today's new jobs
// $today = date('Y-m-d');
// $today_jobs_count = 0;
// $today_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM jobs WHERE DATE(job_date) = ?");
// if ($today_stmt) {
//     $today_stmt->bind_param("s", $today);
//     $today_stmt->execute();
//     $today_result = $today_stmt->get_result();
//     $today_data = $today_result->fetch_assoc();
//     $today_jobs_count = $today_data['total'];
//     $today_stmt->close();
// }

// // Total jobs count
// $total_jobs_count = $need_to_assign_count + $in_progress_count + $completed_count;

// --- 1. CLIENT BREAKDOWN: Active vs Inactive ---
// --- 1. CLIENT BREAKDOWN ---
$total_client_count = 0; $active_client_count = 0; $inactive_client_count = 0;
$client_res = $mysqli->query("SELECT status, COUNT(*) as count FROM client_master GROUP BY status");
if ($client_res) {
    while($c = $client_res->fetch_assoc()){
        if($c['status'] == 'Active') $active_client_count = (int)$c['count'];
        if($c['status'] == 'Inactive') $inactive_client_count = (int)$c['count'];
    }
}
$total_client_count = $active_client_count + $inactive_client_count;

// --- 2. JOB METRICS & STATUS ---
$need_to_assign_count = 0; $in_progress_count = 0; $completed_count = 0;
$total_ce_words = 0; $total_pag_pages = 0; $total_ap_articles = 0; $total_xml_articles = 0;

$jobs_res = $mysqli->query("SELECT j.*, jm.job_name AS process, ce.status AS ce_status, le.status AS le_status, pag.status AS pag_status, qc.status AS qc_status 
                             FROM jobs j JOIN job_master jm ON j.process_id = jm.job_id 
                             LEFT JOIN ce ON ce.job_id = j.job_id LEFT JOIN le ON le.job_id = j.job_id 
                             LEFT JOIN pag ON pag.job_id = j.job_id LEFT JOIN qc ON qc.job_id = j.job_id");

if ($jobs_res) {
    while ($row = $jobs_res->fetch_assoc()) {
        $pname = strtoupper(str_replace(' ', '', $row['process'] ?? ''));
        $total_ce_words += (int)($row['word_count'] ?? 0);
        $total_pag_pages += (int)($row['page_count'] ?? 0);
        if (strpos($pname, 'XML') !== false) $total_xml_articles++;
        if (strpos($pname, 'AP') !== false) $total_ap_articles++;

        $processStageMap = ['CE'=>['CE','LE'],'PAG'=>['PAG','QC'],'CE+PAG'=>['CE','LE','PAG','QC'],'CE+PAG+XML'=>['CE','LE','PAG','QC','XML'],'CE+XML'=>['CE','LE','XML'],'PAG+XML'=>['PAG','QC','XML'],'XML'=>['XML']];
        $required = $processStageMap[$pname] ?? [];
        $all_done = true; $is_ip = false;
        foreach ($required as $stage) {
            $st = $row[strtolower($stage) . '_status'] ?? 'Need to Assign';
            if ($st !== 'Completed') $all_done = false;
            if ($st === 'In Progress') $is_ip = true;
        }
        if ($all_done) $completed_count++; elseif ($is_ip) $in_progress_count++; else $need_to_assign_count++;
    }
}

// --- 3. TOTAL & PERCENTAGES (Calculate ONLY after counting jobs) ---
$total_jobs_count = $need_to_assign_count + $in_progress_count + $completed_count;
$nta_per = $total_jobs_count > 0 ? round(($need_to_assign_count / $total_jobs_count) * 100, 1) : 0;
$ip_per = $total_jobs_count > 0 ? round(($in_progress_count / $total_jobs_count) * 100, 1) : 0;
$c_per = $total_jobs_count > 0 ? round(($completed_count / $total_jobs_count) * 100, 1) : 0;

$active_per = $total_client_count > 0 ? round(($active_client_count / $total_client_count) * 100, 1) : 0;
$inactive_per = $total_client_count > 0 ? round(($inactive_client_count / $total_client_count) * 100, 1) : 0;

// --- 4. TODAY'S OVERVIEW LOGIC ---
$today = date('Y-m-d');
$today_jobs_count = 0;
$today_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM jobs WHERE DATE(job_date) = ?");
if ($today_stmt) {
    $today_stmt->bind_param("s", $today);
    $today_stmt->execute();
    $today_res = $today_stmt->get_result();
    $today_jobs_count = (int)($today_res->fetch_assoc()['total'] ?? 0);
    $today_stmt->close();
}

$ename = !empty($current_user) ? $current_user : ($username ?? 'Admin');

$total_jobs_count = $need_to_assign_count + $in_progress_count + $completed_count;

?>

<main class="main-content">
    
    <!-- EXECUTIVE DASHBOARD HERO BANNER -->
    <div class="dashboard-hero-banner mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <h1 class="hero-title" style="color: #ffffff !important;"><i class="fas fa-chart-line me-2" style="color: #ffffff !important;"></i>Dashboard</h1>
                <p class="hero-subtitle mb-0" style="color: rgba(255, 255, 255, 0.9) !important;">Welcome back, <strong style="color: #ffffff !important;"><?= htmlspecialchars($ename) ?></strong>! Here's real-time visibility into production, clients, and job workflows.</p>
            </div>
            <div class="hero-meta d-flex align-items-center gap-2">
                <span class="hero-date-badge"><i class="far fa-calendar-alt me-2"></i><?= date('l, d M Y') ?></span>
            </div>
        </div>
    </div>

    <!-- SECTION 1: CLIENT PORTFOLIO (3 Cards evenly distributed across full width) -->
    <div class="dashboard-section-header">
        <div class="d-flex align-items-center">
            <span class="section-indicator" style="background: #4a69bd;"></span>
            <span class="section-title-text"><i class="fas fa-users-cog me-2" style="color: #4a69bd;"></i>Client Portfolio</span>
        </div>
        <span class="section-badge-pill">Total: <?= number_format($total_client_count) ?> Clients</span>
    </div>
    <div class="row mb-4 g-3">
        <div class="col-lg-4 col-md-4 col-sm-12">
            <div class="stat-card compact-card" style="border-left-color: #4a69bd;">
                <div class="stat-icon" style="background: #4a69bd;"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <div class="stat-top-row">
                        <h3><?= number_format($total_client_count) ?></h3>
                        <span class="stat-pill-badge" style="background: rgba(74, 105, 189, 0.12); color: #4a69bd;">Total</span>
                    </div>
                    <p>Total Clients</p>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-4 col-sm-12">
            <div class="stat-card compact-card" style="border-left-color: #0984e3;">
                <div class="stat-icon" style="background: #0984e3;"><i class="fas fa-user-check"></i></div>
                <div class="stat-info">
                    <div class="stat-top-row">
                        <h3><?= number_format($active_client_count) ?></h3>
                        <span class="stat-pill-badge" style="background: rgba(9, 132, 227, 0.12); color: #0984e3;"><?= $active_per ?>%</span>
                    </div>
                    <p>Active Clients</p>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-4 col-sm-12">
            <div class="stat-card compact-card" style="border-left-color: #d63031;">
                <div class="stat-icon" style="background: #d63031;"><i class="fas fa-user-slash"></i></div>
                <div class="stat-info">
                    <div class="stat-top-row">
                        <h3><?= number_format($inactive_client_count) ?></h3>
                        <span class="stat-pill-badge" style="background: rgba(214, 48, 49, 0.12); color: #d63031;"><?= $inactive_per ?>%</span>
                    </div>
                    <p>Inactive Clients</p>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION 2: PRODUCTION OUTPUT (4 Cards) -->
    <div class="dashboard-section-header">
        <div class="d-flex align-items-center">
            <span class="section-indicator" style="background: #6c5ce7;"></span>
            <span class="section-title-text"><i class="fas fa-layer-group me-2" style="color: #6c5ce7;"></i>Monthly Production Output</span>
        </div>
        <span class="section-badge-pill"><?= date('F Y') ?></span>
    </div>
    <div class="row mb-4 g-3">
        <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6">
            <div class="stat-card compact-card" style="border-left-color: #6c5ce7;">
                <div class="stat-icon" style="background: #6c5ce7;"><i class="fas fa-file-word"></i></div>
                <div class="stat-info">
                    <div class="stat-top-row">
                        <h3><?= number_format($total_ce_words) ?></h3>
                        <span class="stat-pill-badge" style="background: rgba(108, 92, 231, 0.12); color: #6c5ce7;">Words</span>
                    </div>
                    <p>CE Words</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6">
            <div class="stat-card compact-card" style="border-left-color: #00d2d3;">
                <div class="stat-icon" style="background: #00d2d3;"><i class="fas fa-file-alt"></i></div>
                <div class="stat-info">
                    <div class="stat-top-row">
                        <h3><?= number_format($total_pag_pages) ?></h3>
                        <span class="stat-pill-badge" style="background: rgba(0, 210, 211, 0.12); color: #00a8a8;">Pages</span>
                    </div>
                    <p>PAG Pages</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6">
            <div class="stat-card compact-card" style="border-left-color: #ff7675;">
                <div class="stat-icon" style="background: #ff7675;"><i class="fas fa-exclamation-circle"></i></div>
                <div class="stat-info">
                    <div class="stat-top-row">
                        <h3><?= number_format($total_ap_articles) ?></h3>
                        <span class="stat-pill-badge" style="background: rgba(255, 118, 117, 0.15); color: #d63031;">Articles</span>
                    </div>
                    <p>AP Articles</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6">
            <div class="stat-card compact-card" style="border-left-color: #d63031;">
                <div class="stat-icon" style="background: #d63031;"><i class="fas fa-code"></i></div>
                <div class="stat-info">
                    <div class="stat-top-row">
                        <h3><?= number_format($total_xml_articles) ?></h3>
                        <span class="stat-pill-badge" style="background: rgba(214, 48, 49, 0.12); color: #d63031;">Articles</span>
                    </div>
                    <p>XML Articles</p>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION 3: JOB STATUS & LIFECYCLE -->
    <div class="dashboard-section-header">
        <div class="d-flex align-items-center">
            <span class="section-indicator" style="background: #00cec9;"></span>
            <span class="section-title-text"><i class="fas fa-tasks me-2" style="color: #00cec9;"></i>Job Workflow & Lifecycle</span>
        </div>
        <span class="section-badge-pill">Total: <?= number_format($total_jobs_count) ?> Jobs</span>
    </div>
    <div class="row mb-4 g-3">
        <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6">
            <div class="stat-card compact-card" style="border-left-color: #f39c12;">
                <div class="stat-icon" style="background: #f39c12;"><i class="fas fa-clock"></i></div>
                <div class="stat-info">
                    <div class="stat-top-row">
                        <h3 style="color: #d68910;"><?= number_format($need_to_assign_count) ?></h3>
                        <span class="stat-pill-badge" style="background: rgba(243, 156, 18, 0.12); color: #d68910;"><?= $nta_per ?>%</span>
                    </div>
                    <p>Need to Assign</p>
                    <div class="kpi-micro-progress">
                        <div class="kpi-micro-bar" style="width: <?= $nta_per ?>%; background: #f39c12;"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6">
            <div class="stat-card compact-card" style="border-left-color: #0984e3;">
                <div class="stat-icon" style="background: #0984e3;"><i class="fas fa-spinner"></i></div>
                <div class="stat-info">
                    <div class="stat-top-row">
                        <h3 style="color: #0984e3;"><?= number_format($in_progress_count) ?></h3>
                        <span class="stat-pill-badge" style="background: rgba(9, 132, 227, 0.12); color: #0984e3;"><?= $ip_per ?>%</span>
                    </div>
                    <p>In Progress</p>
                    <div class="kpi-micro-progress">
                        <div class="kpi-micro-bar" style="width: <?= $ip_per ?>%; background: #0984e3;"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6">
            <div class="stat-card compact-card" style="border-left-color: #27ae60;">
                <div class="stat-icon" style="background: #27ae60;"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <div class="stat-top-row">
                        <h3 style="color: #27ae60;"><?= number_format($completed_count) ?></h3>
                        <span class="stat-pill-badge" style="background: rgba(39, 174, 96, 0.12); color: #27ae60;"><?= $c_per ?>%</span>
                    </div>
                    <p>Completed</p>
                    <div class="kpi-micro-progress">
                        <div class="kpi-micro-bar" style="width: <?= $c_per ?>%; background: #27ae60;"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6">
            <div class="stat-card compact-card" style="border-left-color: #00cec9;">
                <div class="stat-icon" style="background: #00cec9;"><i class="fas fa-tasks"></i></div>
                <div class="stat-info">
                    <div class="stat-top-row">
                        <h3 style="color: #009688;"><?= number_format($total_jobs_count) ?></h3>
                        <span class="stat-pill-badge" style="background: rgba(0, 206, 201, 0.15); color: #009688;">100%</span>
                    </div>
                    <p>Total Jobs</p>
                    <div class="kpi-micro-progress">
                        <div class="kpi-micro-bar" style="width: 100%; background: #00cec9;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- LOWER PANELS: TODAY'S OVERVIEW & JOB STATUS DISTRIBUTION -->
    <div class="row g-3 mb-4"> 
        <div class="col-lg-6 col-md-12">
            <div class="card erp-panel-card h-100">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h5 class="mb-0"><i class="fas fa-calendar-check me-2 text-primary"></i>Today's Overview</h5>
                    <span class="badge rounded-pill bg-light text-secondary border fw-normal" style="font-size: 0.75rem;">Daily Snapshot</span>
                </div>
                <div class="card-body">
                    <div class="today-stats">
                        <div class="today-stat-item">
                            <div class="today-stat-icon-wrapper" style="background: rgba(9, 132, 227, 0.12); color: #0984e3;">
                                <i class="fas fa-plus-circle"></i>
                            </div>
                            <div class="today-stat-info flex-grow-1">
                                <h4><?= number_format($today_jobs_count) ?></h4>
                                <p>New Jobs Today</p>
                            </div>
                            <span class="badge rounded-pill bg-light text-primary border" style="font-size: 0.75rem;">Logged Today</span>
                        </div>
                        <div class="today-stat-item">
                            <div class="today-stat-icon-wrapper" style="background: rgba(39, 174, 96, 0.12); color: #27ae60;">
                                <i class="fas fa-building"></i>
                            </div>
                            <div class="today-stat-info flex-grow-1">
                                <h4><?= number_format($active_client_count) ?></h4>
                                <p>Active Clients</p>
                            </div>
                            <span class="badge rounded-pill bg-light text-success border" style="font-size: 0.75rem;"><?= $active_per ?>% Active</span>
                        </div>
                        <div class="today-stat-item">
                            <div class="today-stat-icon-wrapper" style="background: rgba(0, 206, 201, 0.12); color: #009688;">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="today-stat-info flex-grow-1">
                                <h4><?= number_format($total_jobs_count) ?></h4>
                                <p>Total Jobs Managed</p>
                            </div>
                            <span class="badge rounded-pill bg-light text-secondary border" style="font-size: 0.75rem;">All Processes</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6 col-md-12">
            <div class="card erp-panel-card h-100">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h5 class="mb-0"><i class="fas fa-chart-pie me-2 text-info"></i>Job Status Distribution</h5>
                    <span class="badge rounded-pill bg-light text-secondary border fw-normal" style="font-size: 0.75rem;">Pipeline Share</span>
                </div>
                <div class="card-body">
                    <div class="progress-stats">
                        <div class="progress-stat-block">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="progress-label d-flex align-items-center">
                                    <span class="legend-dot" style="background: #f39c12;"></span>Need to Assign
                                </span>
                                <span class="progress-val-badge">
                                    <strong><?= number_format($need_to_assign_count) ?></strong> jobs <span class="text-muted">(<?= $nta_per ?>%)</span>
                                </span>
                            </div>
                            <div class="modern-progress">
                                <div class="modern-progress-bar" style="width: <?= $nta_per ?>%; background: #f39c12;"></div>
                            </div>
                        </div>

                        <div class="progress-stat-block">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="progress-label d-flex align-items-center">
                                    <span class="legend-dot" style="background: #0984e3;"></span>In Progress
                                </span>
                                <span class="progress-val-badge">
                                    <strong><?= number_format($in_progress_count) ?></strong> jobs <span class="text-muted">(<?= $ip_per ?>%)</span>
                                </span>
                            </div>
                            <div class="modern-progress">
                                <div class="modern-progress-bar" style="width: <?= $ip_per ?>%; background: #0984e3;"></div>
                            </div>
                        </div>

                        <div class="progress-stat-block">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="progress-label d-flex align-items-center">
                                    <span class="legend-dot" style="background: #27ae60;"></span>Completed
                                </span>
                                <span class="progress-val-badge">
                                    <strong><?= number_format($completed_count) ?></strong> jobs <span class="text-muted">(<?= $c_per ?>%)</span>
                                </span>
                            </div>
                            <div class="modern-progress">
                                <div class="modern-progress-bar" style="width: <?= $c_per ?>%; background: #27ae60;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div> 
    </div>

</main>

<style>
/* Animations */
@keyframes erpFadeInUp {
    from {
        opacity: 0;
        transform: translateY(8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.main-content {
    animation: erpFadeInUp 0.35s ease-out;
}

/* Executive Hero Banner (Keeps exact dark slate theme #5e6b77) */
.dashboard-hero-banner {
    background: linear-gradient(135deg, #536270 0%, #3f4c57 100%);
    color: #ffffff;
    padding: 16px 24px;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(44, 62, 80, 0.12);
}
.hero-title,
.dashboard-hero-banner h1,
.dashboard-hero-banner .hero-title,
.dashboard-hero-banner h1 i,
.dashboard-hero-banner .hero-title i {
    margin: 0;
    font-size: 1.65rem !important;
    font-weight: 700 !important;
    letter-spacing: -0.5px;
    color: #ffffff !important;
}
.hero-subtitle {
    font-size: 0.88rem;
    color: rgba(255, 255, 255, 0.85);
    margin-top: 4px;
}
.hero-date-badge {
    background: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.25);
    color: #ffffff;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
    backdrop-filter: blur(4px);
}

/* Section Header Styling */
.dashboard-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: 20px 0 10px 0;
    padding-bottom: 6px;
    border-bottom: 1px solid #eef2f6;
}
.section-indicator {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 10px;
    display: inline-block;
}
.section-title-text {
    font-size: 0.86rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #334155;
}
.section-badge-pill {
    font-size: 0.75rem;
    color: #64748b;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 3px 10px;
    border-radius: 20px;
    font-weight: 500;
}

/* Modern Senior Developer ERP Cards */
.stat-card.compact-card {
    background: #ffffff;
    border-radius: 12px;
    padding: 14px 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04), 0 4px 12px rgba(0, 0, 0, 0.03);
    border: 1px solid #edf2f7;
    border-left: 5px solid #007bff;
    display: flex;
    align-items: center;
    min-height: 84px;
    transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}
.stat-card.compact-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
}

.stat-icon {
    width: 44px;
    height: 44px;
    min-width: 44px;
    border-radius: 10px;
    margin-right: 14px;
    font-size: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
    transition: transform 0.22s ease;
}
.stat-card.compact-card:hover .stat-icon {
    transform: scale(1.08);
}

.stat-info {
    flex-grow: 1;
    min-width: 0;
}
.stat-top-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
}
.stat-info h3 {
    font-size: 1.42rem;
    margin: 0;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.1;
    letter-spacing: -0.3px;
    white-space: nowrap;
}
.stat-info p {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #64748b;
    margin: 2px 0 0 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Pill badge separating counts from percentages/units */
.stat-pill-badge {
    font-size: 0.72rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 20px;
    line-height: 1.2;
    white-space: nowrap;
}

/* Micro progress bar inside status cards */
.kpi-micro-progress {
    height: 4px;
    background: #e2e8f0;
    border-radius: 2px;
    margin-top: 6px;
    overflow: hidden;
}
.kpi-micro-bar {
    height: 100%;
    border-radius: 2px;
    transition: width 0.8s ease-in-out;
}

/* Lower Section ERP Panel Cards */
.erp-panel-card {
    border: 1px solid #edf2f7;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04), 0 4px 12px rgba(0, 0, 0, 0.03);
    overflow: hidden;
    background: #ffffff;
}
.erp-panel-card .card-header {
    background: #ffffff;
    border-bottom: 1px solid #f1f5f9;
    padding: 16px 20px;
}
.erp-panel-card .card-header h5 {
    font-size: 1rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}
.erp-panel-card .card-body {
    padding: 20px;
}

/* Today's Stats List */
.today-stats {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.today-stat-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 16px;
    background: #f8fafc;
    border: 1px solid #f1f5f9;
    border-radius: 10px;
    transition: all 0.2s ease;
}
.today-stat-item:hover {
    background: #ffffff;
    border-color: #e2e8f0;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
    transform: translateX(3px);
}
.today-stat-icon-wrapper {
    width: 42px;
    height: 42px;
    min-width: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}
.today-stat-info h4 {
    margin: 0;
    font-size: 1.35rem;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.1;
}
.today-stat-info p {
    margin: 3px 0 0 0;
    color: #64748b;
    font-size: 0.8rem;
    font-weight: 500;
}

/* Sleek Modern Progress Bars for Status Distribution */
.progress-stats {
    display: flex;
    flex-direction: column;
    gap: 18px;
}
.progress-stat-block {
    display: flex;
    flex-direction: column;
}
.progress-label {
    font-weight: 600;
    color: #334155;
    font-size: 0.86rem;
}
.legend-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 8px;
    display: inline-block;
}
.progress-val-badge {
    font-size: 0.82rem;
    color: #1e293b;
}
.modern-progress {
    height: 8px;
    border-radius: 999px;
    background: #eef2f6;
    overflow: hidden;
    position: relative;
    margin-top: 4px;
}
.modern-progress-bar {
    height: 100%;
    border-radius: 999px;
    transition: width 1s cubic-bezier(0.16, 1, 0.3, 1);
}

@media (max-width: 768px) {
    .dashboard-hero-banner {
        padding: 14px 16px;
    }
    .hero-title {
        font-size: 1.4rem;
    }
    .stat-card.compact-card {
        padding: 12px 14px;
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
</script>

</body>
</html>