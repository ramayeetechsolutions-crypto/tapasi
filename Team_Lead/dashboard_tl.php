<?php
require 'db.php';
session_start();

$pageTitle = "Dashboard";
$currentPage = "dashboard";
include 'header.php';

// Get current user
$current_user = $_SESSION['username'] ?? '';

// Get username from request (example: from form, session, or GET/POST)
$employee_username = $current_user; // Replace with actual username source

// Prepare SQL query to fetch employee_name
$sql = "SELECT `employee_name` FROM `employee_master` WHERE `employee_username` = ?";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("s", $employee_username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // Fetch the employee name
    $row = $result->fetch_assoc();
    $current_user_name = $row['employee_name'];

}
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
// // Count jobs by status
// $need_to_assign_count = 0;
// $in_progress_count = 0;
// $completed_count = 0;

// // Initialize metric counters (Point 3 Part 1)
// $total_ce_words = 0;
// $total_pag_pages = 0;
// $total_ap_errors = 0;

// $ename=$_SESSION["ename"];
// // Get all jobs, calculate status, and fetch counts
// $jobs_sql = "
//     SELECT 
//     j.job_id, 
//     j.job_date,
//     j.word_count,
//     j.page_count,
//     j.errors,
//     jm.job_name AS process,
//     ce.status AS ce_status,
//     le.status AS le_status,
//     pag.status AS pag_status,
//     qc.status AS qc_status
// FROM 
//     jobs j
// JOIN 
//     job_master jm ON j.process_id = jm.job_id
// LEFT JOIN 
//     ce ON ce.job_id = j.job_id
// LEFT JOIN 
//     le ON le.job_id = j.job_id
// LEFT JOIN 
//     pag ON pag.job_id = j.job_id
// LEFT JOIN 
//     qc ON qc.job_id = j.job_id
// WHERE 
//     j.assigned_to='$ename'
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

//         // Accumulate metric values inside the loop
//         $total_ce_words += (int)($row['word_count'] ?? 0);
//         $total_pag_pages += (int)($row['page_count'] ?? 0);
//         $total_ap_errors += (int)($row['errors'] ?? 0);
        
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

// --- 1. CLIENT LOGIC: Get separate counts for Active and Inactive ---
$total_client_count = 0;
$active_client_count = 0;
$inactive_client_count = 0;
$client_res = $mysqli->query("SELECT status, COUNT(*) as count FROM client_master GROUP BY status");
while($c = $client_res->fetch_assoc()){
    if($c['status'] == 'Active') $active_client_count = $c['count'];
    if($c['status'] == 'Inactive') $inactive_client_count = $c['count'];
}
$total_client_count = $active_client_count + $inactive_client_count;

// --- 2. JOB LOGIC: Initialize metrics ---
$need_to_assign_count = 0;
$in_progress_count = 0;
$completed_count = 0;
$total_ce_words = 0;
$total_pag_pages = 0;
$total_ap_articles = 0; 
$total_xml_articles = 0;

$ename = $_SESSION["ename"];

// Fetch all jobs to calculate statuses and production sums
$jobs_res = $mysqli->query("SELECT j.*, jm.job_name AS process, ce.status AS ce_status, le.status AS le_status, pag.status AS pag_status, qc.status AS qc_status 
                             FROM jobs j JOIN job_master jm ON j.process_id = jm.job_id 
                             LEFT JOIN ce ON ce.job_id = j.job_id LEFT JOIN le ON le.job_id = j.job_id 
                             LEFT JOIN pag ON pag.job_id = j.job_id LEFT JOIN qc ON qc.job_id = j.job_id 
                             WHERE j.assigned_to='$ename'");

while ($row = $jobs_res->fetch_assoc()) {
    $pname = strtoupper(str_replace(' ', '', $row['process']));
    
    //PRODUCTION SUMS
    $total_ce_words += (int)($row['word_count'] ?? 0);
    $total_pag_pages += (int)($row['page_count'] ?? 0);
    
    // ARTICLE CATEGORIES
    if (strpos($pname, 'XML') !== false) $total_xml_articles++;
    if (strpos($pname, 'AP') !== false) $total_ap_articles++;

    // STATUS LOGIC
    $processStageMap = ['CE'=>['CE','LE'],'PAG'=>['PAG','QC'],'CE+PAG'=>['CE','LE','PAG','QC'],'CE+PAG+XML'=>['CE','LE','PAG','QC','XML'],'CE+XML'=>['CE','LE','XML'],'PAG+XML'=>['PAG','QC','XML'],'XML'=>['XML']];
    $required = $processStageMap[$pname] ?? [];
    $is_done = true; $is_ip = false;

    foreach($required as $s) {
        $st = $row[strtolower($s).'_status'] ?? 'Need to Assign';
        if($st !== 'Completed') $is_done = false;
        if($st === 'In Progress') $is_ip = true;
    }

    if($is_done) $completed_count++; 
    elseif($is_ip) $in_progress_count++; 
    else $need_to_assign_count++;
}
$total_jobs_count = $need_to_assign_count + $in_progress_count + $completed_count;

// --- 3. UI PERCENTAGES ---
$nta_per = $total_jobs_count > 0 ? round(($need_to_assign_count / $total_jobs_count) * 100) : 0;
$ip_per = $total_jobs_count > 0 ? round(($in_progress_count / $total_jobs_count) * 100) : 0;
$c_per = $total_jobs_count > 0 ? round(($completed_count / $total_jobs_count) * 100) : 0;

// --- 4. TODAY'S OVERVIEW & PROCESS COUNTS (CORRECTED) ---
$today = date('Y-m-d');
$ename = $_SESSION["ename"]; 

// Input/Output Logic (Keeping TL filter for these)
$today_input_count = $mysqli->query("SELECT COUNT(*) FROM jobs WHERE DATE(job_date) = '$today' AND assigned_to = '$ename'")->fetch_row()[0];
$today_output_count = $mysqli->query("SELECT COUNT(q.id) FROM qc q JOIN jobs j ON q.job_id = j.job_id WHERE q.status = 'Completed' AND DATE(q.last_updated) = '$today' AND j.assigned_to = '$ename'")->fetch_row()[0];

// --- 5. DIRECT PROCESS COUNTS (To match your tables exactly) ---
// CE In-Progress (Ippo ithu 10-nu correct-ah varum)
$ce_ip = $mysqli->query("SELECT COUNT(*) FROM ce WHERE status = 'In Progress'")->fetch_row()[0];

// LE In-Progress (Unna screenshot padi 7 varum)
$le_ip = $mysqli->query("SELECT COUNT(*) FROM le WHERE status = 'In Progress'")->fetch_row()[0];

// PAG In-Progress (Unna screenshot padi 6 varum)
$pag_ip = $mysqli->query("SELECT COUNT(*) FROM pag WHERE status = 'In Progress'")->fetch_row()[0];

// QC In-Progress
$qc_ip = $mysqli->query("SELECT COUNT(*) FROM qc WHERE status = 'In Progress'")->fetch_row()[0];

// Today's Overview "In Progress Articles" count
$current_ip_count = $ce_ip; 

// Percentage Calculation (Base is Total Jobs 30)
$total_assigned = ($total_jobs_count > 0) ? $total_jobs_count : 1;
$ce_per  = round(($ce_ip / $total_assigned) * 100);
$le_per  = round(($le_ip / $total_assigned) * 100);
$pag_per = round(($pag_ip / $total_assigned) * 100);
$qc_per  = round(($qc_ip / $total_assigned) * 100);


?>

<main class="main-content">
    
    <div class="page-title-container">
        <h1><i class="fas fa-chart-line"></i>Dashboard</h1>
    </div>
    <p class="page-description">Welcome back! <?php echo $ename;?></p>
    
    <!-- <div class="stats-cards">
        <div class="stat-card">
            <div class="stat-icon clients">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($client_count) ?></h3>
                <p>Total Clients</p>
            </div>
        </div> -->
        
        <!-- <div class="stat-card">
            <div class="stat-icon pending">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($need_to_assign_count) ?></h3>
                <p>Need to Assign</p>
            </div>
        </div> -->
        
        <!-- <div class="stat-card">
            <div class="stat-icon progress">
                <i class="fas fa-spinner"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($in_progress_count) ?></h3>
                <p>In Progress</p>
            </div>
        </div> -->
        
        <!-- <div class="stat-card">
            <div class="stat-icon completed">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($completed_count) ?></h3>
                <p>Completed</p>
            </div>
        </div> -->
        
        <!--div class="stat-card">
            <div class="stat-icon today">
                <i class="fas fa-calendar-day"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($today_jobs_count) ?></h3>
                <p>Today's New Jobs</p>
            </div>
        </div-->
        
        <!-- <div class="stat-card">
            <div class="stat-icon total">
                <i class="fas fa-tasks"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($total_jobs_count) ?></h3>
                <p>Total Jobs</p>
            </div>
        </div>
    </div> -->

    <!-- CE Words Card -->
        <!-- <div class="stat-card" style="border-left-color: #667eea;">
            <div class="stat-icon" style="background: linear-gradient(45deg, #667eea, #764ba2);">
                <i class="fas fa-file-word"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($total_ce_words) ?></h3>
                <p>CE Words</p>
            </div>
        </div> -->

        <!-- PAG Pages Card -->
        <!-- <div class="stat-card" style="border-left-color: #38f9d7;">
            <div class="stat-icon" style="background: linear-gradient(45deg, #43e97b, #38f9d7);">
                <i class="fas fa-file-alt"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($total_pag_pages) ?></h3>
                <p>PAG Pages</p>
            </div>
        </div> -->

        <!-- AP Errors Card -->
        <!-- <div class="stat-card" style="border-left-color: #f5576c;">
            <div class="stat-icon" style="background: linear-gradient(45deg, #f093fb, #f5576c);">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($total_ap_errors) ?></h3>
                <p>AP Errors</p>
            </div>
        </div> -->

        <!-- ROW 1: CLIENTS -->
    <div class="row mb-3 g-2">
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #4a69bd;">
                <div class="stat-icon clients"><i class="fas fa-users"></i></div>
                <div class="stat-info"><h3><?= $total_client_count ?></h3><p>Total Clients</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #4a69bd;">
                <div class="stat-icon clients" style="background:#0984e3"><i class="fas fa-user-check"></i></div>
                <div class="stat-info"><h3 class="text-primary"><?= $active_client_count ?></h3><p>Active Clients</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #4a69bd;">
                <div class="stat-icon clients" style="background:#d63031"><i class="fas fa-user-slash"></i></div>
                <div class="stat-info"><h3 class="text-danger"><?= $inactive_client_count ?></h3><p>Inactive Clients</p></div>
            </div>
        </div>
    </div>

    <!-- ROW 2: PRODUCTION METRICS -->
    <div class="row mb-3 g-2">
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #6c5ce7;">
                <div class="stat-icon" style="background: #6c5ce7;"><i class="fas fa-file-word"></i></div>
                <div class="stat-info"><h3><?= number_format($total_ce_words) ?></h3><p>CE Words</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #00d2d3;">
                <div class="stat-icon" style="background: #00d2d3;"><i class="fas fa-file-alt"></i></div>
                <div class="stat-info"><h3><?= $total_pag_pages ?></h3><p>TS Pages</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #ff7675;">
                <div class="stat-icon" style="background: #ff7675;"><i class="fas fa-exclamation-circle"></i></div>
                <div class="stat-info"><h3><?= $total_ap_articles ?></h3><p>AP Articles</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #d63031;">
                <div class="stat-icon" style="background: #d63031;"><i class="fas fa-code"></i></div>
                <div class="stat-info"><h3><?= $total_xml_articles ?></h3><p>XML Articles</p></div>
            </div>
        </div>
    </div>

    <!-- ROW 3: JOB STATUS & PERCENTAGES -->
    <div class="row mb-4 g-2">
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #fdcb6e;">
                <div class="stat-icon pending" style="background:#f39c12"><i class="fas fa-clock"></i></div>
                <div class="stat-info"><h3><?= $need_to_assign_count ?> <small class="text-dark fw-bold" style="opacity: 1;">(<?= $nta_per ?>%)</small></h3><p>Need to Assign</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #0984e3;">
                <div class="stat-icon progress"><i class="fas fa-spinner"></i></div>
                <div class="stat-info"><h3><?= $in_progress_count ?> <small class="text-dark fw-bold" style="opacity: 1;">(<?= $ip_per ?>%)</small></h3><p>In Progress</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #27ae60;">
                <div class="stat-icon completed"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info"><h3><?= $completed_count ?> <small class="text-dark fw-bold" style="opacity: 1;">(<?= $c_per ?>%)</small></h3><p>Completed</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #00cec9;">
                <div class="stat-icon total" style="background:#00cec9"><i class="fas fa-tasks"></i></div>
                <div class="stat-info"><h3><?= $total_jobs_count ?> <small class="text-dark fw-bold" style="opacity: 1;">(100%)</small>
</h3><p>Total Jobs</p></div>
            </div>
        </div>
    </div>

    <!-- Main Statistics Row (Side-by-Side) -->
    <div class="row mt-4">
        
        <!-- LEFT: Today's Overview -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5><i class="fas fa-calendar-check me-2"></i>Today's Overview</h5>
                </div>
                <div class="card-body">
                    <div class="today-stats">
                        <div class="today-stat-item">
                            <i class="fas fa-file-import text-primary"></i>
                            <div class="today-stat-info">
                                <h4><?= number_format($today_input_count) ?></h4>
                                <p>Input Article</p>
                            </div>
                        </div>
                        <div class="today-stat-item">
                            <i class="fas fa-file-export text-success"></i>
                            <div class="today-stat-info">
                                <h4><?= number_format($today_output_count) ?></h4>
                                <p>Output Article</p>
                            </div>
                        </div>
                        <div class="today-stat-item">
                            <i class="fas fa-spinner fa-spin text-warning"></i>
                            <div class="today-stat-info">
                                <h4><?= number_format($current_ip_count) ?></h4>
                                <p>In Progress Articles</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: Job In-Progress Distribution with Percentage -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie me-2"></i>Job In-Progress Distribution</h5>
                </div>
                <div class="card-body">
                    <div class="progress-stats">
                        
                        <!-- CE Bar -->
                        <div class="progress-item mb-4">
                            <span class="progress-label d-flex justify-content-between mb-1">
                                <span class="small fw-bold">CE Jobs (In Progress)</span>
                                <strong><?= $ce_ip ?></strong>
                            </span>
                            <div class="progress" style="height: 22px; border-radius: 12px; background-color: #f0f0f0;">
                                <div class="progress-bar bg-primary progress-bar-striped progress-bar-animated" 
                                     style="width: <?= $ce_per ?>%; font-size: 11px; font-weight: 800; line-height: 22px;">
                                     <?= $ce_per ?>%
                                </div>
                            </div>
                        </div>
                        
                        <!-- LE Bar -->
                        <div class="progress-item mb-4">
                            <span class="progress-label d-flex justify-content-between mb-1">
                                <span class="small fw-bold">LE Jobs (In Progress)</span>
                                <strong><?= $le_ip ?></strong>
                            </span>
                            <div class="progress" style="height: 22px; border-radius: 12px; background-color: #f0f0f0;">
                                <div class="progress-bar bg-info progress-bar-striped progress-bar-animated" 
                                     style="width: <?= $le_per ?>%; font-size: 11px; font-weight: 800; line-height: 22px;">
                                     <?= $le_per ?>%
                                </div>
                            </div>
                        </div>

                        <!-- PAG Bar -->
                        <div class="progress-item mb-4">
                            <span class="progress-label d-flex justify-content-between mb-1">
                                <span class="small fw-bold">TS Jobs (In Progress)</span>
                                <strong><?= $pag_ip ?></strong>
                            </span>
                            <div class="progress" style="height: 22px; border-radius: 12px; background-color: #f0f0f0;">
                                <div class="progress-bar bg-warning text-dark progress-bar-striped progress-bar-animated" 
                                     style="width: <?= $pag_per ?>%; font-size: 11px; font-weight: 800; line-height: 22px;">
                                     <?= $pag_per ?>%
                                </div>
                            </div>
                        </div>

                        <!-- QC Bar -->
                        <div class="progress-item">
                            <span class="progress-label d-flex justify-content-between mb-1">
                                <span class="small fw-bold">QC Jobs (In Progress)</span>
                                <strong><?= $qc_ip ?></strong>
                            </span>
                            <div class="progress" style="height: 22px; border-radius: 12px; background-color: #f0f0f0;">
                                <div class="progress-bar bg-danger progress-bar-striped progress-bar-animated" 
                                     style="width: <?= $qc_per ?>%; font-size: 11px; font-weight: 800; line-height: 22px;">
                                     <?= ($qc_per > 0) ? $qc_per.'%' : '' ?>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

    </div>
        
        <!-- <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-calendar-check me-2"></i>Today's Overview</h5>
                </div>
                <div class="card-body">
                    <div class="today-stats">
                        <div class="today-stat-item">
                            <i class="fas fa-plus-circle text-primary"></i>
                            <div class="today-stat-info">
                                <h4><?= number_format($today_jobs_count) ?></h4>
                                <p>Today's Job</p>
                            </div>
                        </div>
                        <div class="today-stat-item">
                            <i class="fas fa-building text-success"></i>
                            <div class="today-stat-info">
                                <h4><?= number_format($client_count) ?></h4>
                                <p>Active Clients</p>
                            </div>
                        </div>
                        <div class="today-stat-item">
                            <i class="fas fa-chart-line text-info"></i>
                            <div class="today-stat-info">
                                <h4><?= number_format($total_jobs_count) ?></h4>
                                <p>Total Jobs</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div> -->
         <!-- <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie me-2"></i>Job Status Distribution</h5>
                </div>
                <div class="card-body">
                    <div class="progress-stats">
                        <div class="progress-item">
                            <span class="progress-label">Need to Assign</span>
                            <div class="progress">
                                <div class="progress-bar bg-warning" style="width: <?= $total_jobs_count > 0 ? ($need_to_assign_count / $total_jobs_count) * 100 : 0 ?>%">
                                    <?= $total_jobs_count > 0 ? round(($need_to_assign_count / $total_jobs_count) * 100, 1) : 0 ?>%
                                </div>
                            </div>
                        </div>
                        <div class="progress-item">
                            <span class="progress-label">In Progress</span>
                            <div class="progress">
                                <div class="progress-bar bg-info" style="width: <?= $total_jobs_count > 0 ? ($in_progress_count / $total_jobs_count) * 100 : 0 ?>%">
                                    <?= $total_jobs_count > 0 ? round(($in_progress_count / $total_jobs_count) * 100, 1) : 0 ?>%
                                </div>
                            </div>
                        </div>
                        <div class="progress-item">
                            <span class="progress-label">Completed</span>
                            <div class="progress">
                                <div class="progress-bar bg-success" style="width: <?= $total_jobs_count > 0 ? ($completed_count / $total_jobs_count) * 100 : 0 ?>%">
                                    <?= $total_jobs_count > 0 ? round(($completed_count / $total_jobs_count) * 100, 1) : 0 ?>%
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div> -->
        
    </div>

</main>

<style>
/* .stats-cards {
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
} */

/* Styling for the small compact grid boxes */
.stat-card {
    background: white;
    border-radius: 8px; 
    padding: 10px 15px; 
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05); 
    border-left: 5px solid #007bff;
    display: flex;
    align-items: center;
    transition: transform 0.2s ease;
    height: 75px; /* compact box height */
}

/* Icons resized for small boxes */
.stat-icon {
    width: 40px; 
    height: 40px;
    border-radius: 50%;
    margin-right: 12px;
    font-size: 15px; 
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
}

.stat-icon.clients { background: #4a69bd; }
.stat-icon.pending { background: #f39c12; }
.stat-icon.progress { background: #0984e3; }
.stat-icon.completed { background: #27ae60; }

/* Number and Label sizes */
.stat-info h3 {
    font-size: 1.25rem; 
    margin: 0;
    font-weight: 700;
}

.stat-info p {
    font-size: 0.7rem; 
    font-weight: 600;
    text-transform: uppercase;
    color: #636e72;
    margin: 0;
}

.stat-info small {
    font-size: 0.8rem;
    font-weight: normal;
}

.stat-info p {
    margin: 5px 0 0 0;
    color: #7f8c8d;
    font-size: 0.9rem;
}

.progress-stats {
    display: flex;
    flex-direction: column;
    gap: 15px;
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
}

.progress {
    height: 25px;
    border-radius: 12px;
    overflow: hidden;
}

.progress-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.8rem;
    color: white;
}

.today-stats {
    display: flex;
    flex-direction: column;
    gap: 20px;
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

.today-stat-item i {
    font-size: 2rem;
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

.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
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

.page-title-container {
  background: var(--ash-dark);
    color: white;
    padding: 10px;
    border-radius: 12px;
    margin-bottom: 10px;
}

.page-title-container h1 {
    margin: 0;
    font-size: 2.5rem;
    font-weight: 700;
}

.page-description {
    color: #7f8c8d;
    font-size: 1.1rem;
    margin-bottom: 30px;
}

@media (max-width: 768px) {
    .stats-cards {
        grid-template-columns: 1fr;
    }
    
    .stat-card {
        padding: 20px;
    }
    
    .page-title-container h1 {
        font-size: 2rem;
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

 