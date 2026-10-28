<br><br><br>
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
$sql = "SELECT `reporting_manager`,employee_name FROM `employee_master` WHERE `employee_username` = ?";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("s", $employee_username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $current_user_name = $row['employee_name'];
    // Setting $ename as Reporting Manager (TL) so all queries work correctly
    $ename = $row['reporting_manager']; 
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
// $need_to_assign_count = 0;
// $in_progress_count = 0;
// $completed_count = 0;
// $ename=$_SESSION["ename"];
// // Get all jobs and calculate status
// $jobs_sql = "
//     SELECT 
//     j.job_id, 
//     j.job_date,
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
//     j.assigned_to='$current_user_name'
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

// Count today's new jobs
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

// Total jobs count
// $total_jobs_count = $need_to_assign_count + $in_progress_count + $completed_count;

// --- 1. CLIENT LOGIC: Original counts for Active/Inactive ---
$total_client_count = 0; $active_client_count = 0; $inactive_client_count = 0;
$client_res = $mysqli->query("SELECT status, COUNT(*) as count FROM client_master GROUP BY status");
while($c = $client_res->fetch_assoc()){
    if($c['status'] == 'Active') $active_client_count = (int)$c['count'];
    if($c['status'] == 'Inactive') $inactive_client_count = (int)$c['count'];
}
$total_client_count = $active_client_count + $inactive_client_count;

// --- 2. JOB METRICS: Querying by Reporting Manager ($ename) or SubTL ---
$need_to_assign_count = 0; $in_progress_count = 0; $completed_count = 0;
$total_ce_words = 0; $total_pag_pages = 0; $total_ap_articles = 0; $total_xml_articles = 0;

// Filter by $ename (Reporting Manager TL) so all team jobs are counted
$target_assignee = !empty($ename) ? $ename : $current_user_name;

$jobs_res = $mysqli->query("SELECT j.*, jm.job_name AS process, ce.status AS ce_status, le.status AS le_status, pag.status AS pag_status, qc.status AS qc_status 
                             FROM jobs j JOIN job_master jm ON j.process_id = jm.job_id 
                             LEFT JOIN ce ON ce.job_id = j.job_id LEFT JOIN le ON le.job_id = j.job_id 
                             LEFT JOIN pag ON pag.job_id = j.job_id LEFT JOIN qc ON qc.job_id = j.job_id 
                             WHERE j.assigned_to='$target_assignee'");

while ($row = $jobs_res->fetch_assoc()) {
    $pname = strtoupper(str_replace(' ', '', $row['process']));
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
$total_jobs_count = $need_to_assign_count + $in_progress_count + $completed_count;

// --- 3. PERCENTAGES for UI ---
$nta_per = $total_jobs_count > 0 ? round(($need_to_assign_count / $total_jobs_count) * 100) : 0;
$ip_per = $total_jobs_count > 0 ? round(($in_progress_count / $total_jobs_count) * 100) : 0;
$c_per = $total_jobs_count > 0 ? round(($completed_count / $total_jobs_count) * 100) : 0;

// --- 4. TODAY'S OVERVIEW LOGIC ---
$today = date('Y-m-d');

// 1. INPUT ARTICLE (Today's Received)
$input_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM jobs WHERE DATE(job_date) = ? AND (assigned_to = ? OR assigned_to = ?)");
$input_stmt->bind_param("sss", $today, $current_user_name, $ename);
$input_stmt->execute();
$today_input_count = $input_stmt->get_result()->fetch_assoc()['total'];
$input_stmt->close();

// 2. OUTPUT ARTICLE (Today's Completed in QC)
$output_stmt = $mysqli->prepare("SELECT COUNT(q.id) as total FROM qc q JOIN jobs j ON q.job_id = j.job_id WHERE q.status = 'Completed' AND DATE(q.last_updated) = ? AND (j.assigned_to = ? OR j.assigned_to = ?)");
$output_stmt->bind_param("sss", $today, $current_user_name, $ename);
$output_stmt->execute();
$today_output_count = $output_stmt->get_result()->fetch_assoc()['total'];
$output_stmt->close();

// 3. IN PROGRESS ARTICLE (Currently working in CE)
$ip_stmt = $mysqli->prepare("SELECT COUNT(c.id) as total FROM ce c JOIN jobs j ON c.job_id = j.job_id WHERE c.status = 'In Progress' AND (j.assigned_to = ? OR j.assigned_to = ?)");
$ip_stmt->bind_param("ss", $current_user_name, $ename);


// --- 5. PROCESS-SPECIFIC IN PROGRESS & TOTAL COUNTS (ACCURATE) ---

// --- CE Jobs ---
$ce_total = (int)$mysqli->query("SELECT COUNT(*) FROM ce")->fetch_row()[0]; // Result: 28
$ce_ip    = (int)$mysqli->query("SELECT COUNT(*) FROM ce WHERE status = 'In Progress'")->fetch_row()[0]; // Result: 10
$ce_per   = ($ce_total > 0) ? round(($ce_ip / $ce_total) * 100) : 0; // Calculation: (10/28)*100 = 36%

// --- LE Jobs ---
$le_total = (int)$mysqli->query("SELECT COUNT(*) FROM le")->fetch_row()[0];
$le_ip    = (int)$mysqli->query("SELECT COUNT(*) FROM le WHERE status = 'In Progress'")->fetch_row()[0];
$le_per   = ($le_total > 0) ? round(($le_ip / $le_total) * 100) : 0;

// --- TS Jobs (PAG Table) ---
$ts_total = (int)$mysqli->query("SELECT COUNT(*) FROM pag")->fetch_row()[0];
$ts_ip    = (int)$mysqli->query("SELECT COUNT(*) FROM pag WHERE status = 'In Progress'")->fetch_row()[0];
$ts_per   = ($ts_total > 0) ? round(($ts_ip / $ts_total) * 100) : 0;

// --- QC Jobs ---
$qc_total = (int)$mysqli->query("SELECT COUNT(*) FROM qc")->fetch_row()[0];
$qc_ip    = (int)$mysqli->query("SELECT COUNT(*) FROM qc WHERE status = 'In Progress'")->fetch_row()[0];
$qc_per   = ($qc_total > 0) ? round(($qc_ip / $qc_total) * 100) : 0;

// Today's Overview count update
$current_ip_count = $ce_ip;
?>

<main class="main-content">
    
    <div class="page-title-container">
        <h1><i class="fas fa-chart-line"></i>Dashboard</h1>
    </div>
    <!-- <p class="page-description">Welcome back! <?php echo $ename;?></p> -->
     <p class="page-description">Welcome back! <?php echo $current_user_name; ?></p>

    
    <!-- ROW 1: CLIENTS -->
    <div class="row mb-3 g-2">
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #4a69bd;"><div class="stat-icon" style="background:#4a69bd"><i class="fas fa-users"></i></div><div class="stat-info"><h3><?= $total_client_count ?></h3><p>Total Clients</p></div></div></div>
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #0984e3;"><div class="stat-icon" style="background:#0984e3"><i class="fas fa-user-check"></i></div><div class="stat-info"><h3><?= $active_client_count ?></h3><p>Active Clients</p></div></div></div>
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #d63031;"><div class="stat-icon" style="background:#d63031"><i class="fas fa-user-slash"></i></div><div class="stat-info"><h3><?= $inactive_client_count ?></h3><p>Inactive Clients</p></div></div></div>
    </div>

    <!-- ROW 2: PRODUCTION METRICS -->
    <div class="row mb-3 g-2">
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #6c5ce7;"><div class="stat-icon" style="background:#6c5ce7"><i class="fas fa-file-word"></i></div><div class="stat-info"><h3><?= number_format($total_ce_words) ?></h3><p>CE Words</p></div></div></div>
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #00d2d3;"><div class="stat-icon" style="background:#00d2d3"><i class="fas fa-file-alt"></i></div><div class="stat-info"><h3><?= $total_pag_pages ?></h3><p>TS Pages</p></div></div></div>
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #ff7675;"><div class="stat-icon" style="background:#ff7675"><i class="fas fa-exclamation-circle"></i></div><div class="stat-info"><h3><?= $total_ap_articles ?></h3><p>AP Articles</p></div></div></div>
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #d63031;"><div class="stat-icon" style="background:#d63031"><i class="fas fa-code"></i></div><div class="stat-info"><h3><?= $total_xml_articles ?></h3><p>XML Articles</p></div></div></div>
    </div>

    <!-- ROW 3: JOB STATUS -->
    <div class="row mb-4 g-2">
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #f39c12;"><div class="stat-icon" style="background:#f39c12"><i class="fas fa-clock"></i></div><div class="stat-info"><h3><?= $need_to_assign_count ?> <small class="text-dark fw-bold">(<?= $nta_per ?>%)</small></h3><p>Need to Assign</p></div></div></div>
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #0984e3;"><div class="stat-icon" style="background:#0984e3"><i class="fas fa-spinner"></i></div><div class="stat-info"><h3><?= $in_progress_count ?> <small class="text-dark fw-bold">(<?= $ip_per ?>%)</small></h3><p>In Progress</p></div></div></div>
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #27ae60;"><div class="stat-icon" style="background:#27ae60"><i class="fas fa-check-circle"></i></div><div class="stat-info"><h3><?= $completed_count ?> <small class="text-dark fw-bold">(<?= $c_per ?>%)</small></h3><p>Completed</p></div></div></div>
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #00cec9;"><div class="stat-icon" style="background:#00cec9"><i class="fas fa-tasks"></i></div><div class="stat-info"><h3><?= $total_jobs_count ?> <small class="text-dark fw-bold">(100%)</small></h3><p>Total Jobs</p></div></div></div>
    </div>

    <!-- <div class="stats-cards">
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
    </div>  -->

    <!-- Main Statistics Row (Side-by-Side Layout) -->
    <div class="row mt-4">
        
        <!-- LEFT SIDE: Today's Overview Card -->
        <div class="col-md-6">
            <div class="card h-100" style="border-radius: 12px; border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0" style="font-weight: 700; color: #2c3e50;">
                        <i class="fas fa-calendar-check me-2"></i>Today's Overview
                    </h5>
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

        <!-- RIGHT SIDE: Job In-Progress Distribution -->
        <div class="col-md-6">
    <div class="card h-100" style="border-radius: 12px; border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0" style="font-weight: 700; color: #2c3e50;">
                <i class="fas fa-chart-pie me-2"></i>Job In-Progress Distribution
            </h5>
        </div>
        <div class="card-body">
            <div class="progress-stats">
                <!-- CE Progress -->
                <div class="progress-item mb-4">
    <div class="d-flex justify-content-between mb-1">
        <span class="small fw-bold text-secondary">CE Jobs (In Progress)</span>
        <strong class="text-dark"><?= $ce_ip ?></strong>
    </div>
                    <div class="progress" style="height: 22px; border-radius: 12px; background-color: #f1f1f1;">
                        <div class="progress-bar bg-primary progress-bar-striped progress-bar-animated" 
                             style="width: <?= $ce_per ?>%; font-size: 11px; font-weight: 800; line-height: 22px;">
                             <?= $ce_per ?>%
                        </div>
                    </div>
                </div>
                
                <!-- LE Progress -->
                <div class="progress-item mb-4">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="small fw-bold text-secondary">LE Jobs (In Progress)</span>
                        <strong class="text-dark"><?= $le_ip ?></strong>
                    </div>
                    <div class="progress" style="height: 22px; border-radius: 12px; background-color: #f1f1f1;">
                        <div class="progress-bar bg-info progress-bar-striped progress-bar-animated" 
                             style="width: <?= $le_per ?>%; font-size: 11px; font-weight: 800; line-height: 22px;">
                             <?= $le_per ?>%
                        </div>
                    </div>
                </div>

                <!-- TS Progress -->
                <div class="progress-item mb-4">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="small fw-bold text-secondary">TS Jobs (In Progress)</span>
                        <strong class="text-dark"><?= $ts_ip ?></strong>
                    </div>
                    <div class="progress" style="height: 22px; border-radius: 12px; background-color: #f1f1f1;">
                        <div class="progress-bar bg-warning text-dark progress-bar-striped progress-bar-animated" 
                             style="width: <?= $ts_per ?>%; font-size: 11px; font-weight: 800; line-height: 22px;">
                             <?= $ts_per ?>%
                        </div>
                    </div>
                </div>

                <!-- QC Progress -->
                <div class="progress-item">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="small fw-bold text-secondary">QC Jobs (In Progress)</span>
                        <strong class="text-dark"><?= $qc_ip ?></strong>
                    </div>
                    <div class="progress" style="height: 22px; border-radius: 12px; background-color: #f1f1f1;">
                        <div class="progress-bar bg-danger progress-bar-striped progress-bar-animated" 
                             style="width: <?= $qc_per ?>%; font-size: 11px; font-weight: 800; line-height: 22px;">
                             <?= ($qc_per > 0) ? $qc_per.'%' : '0%' ?>
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
        </div>   -->

        
       
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
}

.stat-info p {
    margin: 5px 0 0 0;
    color: #7f8c8d;
    font-size: 0.9rem;
} */

      /* .progress-stats {
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
} */

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

/* .card {
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
}  */

 /* --- Dashboard Layout Styling --- */
.main-content { padding: 20px; background-color: #f8f9fa; }

/* Grid Card Styling */
.stat-card {
    background: white;
    border-radius: 10px;
    padding: 15px;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.04);
    border-left: 5px solid #007bff;
    display: flex;
    align-items: center;
    height: 85px; /* Fixed height for perfect alignment */
    transition: transform 0.2s ease-in-out;
}

.stat-card:hover { transform: translateY(-3px); }

/* Icon Circle Styling */
.stat-icon {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    margin-right: 15px;
    font-size: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;
}

/* Label & Number Text Styling */
.stat-info h3 {
    font-size: 1.4rem;
    margin: 0;
    font-weight: 700;
    color: #2c3e50;
    line-height: 1.1;
}

.stat-info p {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: #7f8c8d;
    margin: 2px 0 0 0;
    letter-spacing: 0.3px;
}

.stat-info small {
    font-size: 0.8rem;
    font-weight: 600;
}

/* Custom Header Banner */
.page-title-container {
    background: #5e6b77; /* Matching Grey Header */
    color: white;
    padding: 15px 20px;
    border-radius: 12px;
    margin-bottom: 10px;
}

.page-title-container h1 { margin: 0; font-size: 2.2rem; font-weight: 700; }
.page-description { color: #7f8c8d; font-size: 1rem; margin-bottom: 25px; }

/* Responsive adjustments */
@media (max-width: 768px) {
    .stat-card { height: auto; padding: 20px; }
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