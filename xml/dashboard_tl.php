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

$current_user_name = '';
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $current_user_name = $row['employee_name'];
}
// Safe fallback for ename
$ename = !empty($_SESSION["ename"]) ? $_SESSION["ename"] : $current_user_name;

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

// // Initialize metric counters for Point 13
// $total_images = 0;
// $total_tables = 0;
// $total_equations = 0;

// $ename=$_SESSION["ename"];
// // Get all jobs and calculate status (Added images, tables, and equations fields)
// $jobs_sql = "
//     SELECT 
//     j.job_id, 
//     j.job_date,
//     j.images,
//     j.tables,
//     j.equations,
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
        
//         // Accumulate metric counts for assigned jobs
//         $total_images += (int)($row['images'] ?? 0);
//         $total_tables += (int)($row['tables'] ?? 0);
//         $total_equations += (int)($row['equations'] ?? 0);

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

// // Total jobs count
// $total_jobs_count = $need_to_assign_count + $in_progress_count + $completed_count;

// --- 1. CLIENT LOGIC: UQ Clients Only (Active/Inactive counts) ---
$total_client_count = 0; $active_client_count = 0; $inactive_client_count = 0;

$uq_query = "SELECT status, COUNT(*) as count FROM client_master 
             WHERE client_name LIKE '%UQ%' OR company_name LIKE '%UQ%' 
             GROUP BY status";

$client_res = $mysqli->query($uq_query);

if ($client_res) {
    while($c = $client_res->fetch_assoc()){
        if($c['status'] == 'Active') $active_client_count = (int)$c['count'];
        if($c['status'] == 'Inactive') $inactive_client_count = (int)$c['count'];
    }
}
$total_client_count = $active_client_count + $inactive_client_count;

// --- 2. JOB METRICS: Production Data & Status Calculation ---
$need_to_assign_count = 0; $in_progress_count = 0; $completed_count = 0;
// Monthly metrics variables
$monthly_ce_words = 0; $monthly_pag_pages = 0; $monthly_ap_articles = 0; $monthly_xml_articles = 0;

// For Job Distribution Card (Count of JOBS, not sum of items)
$jobs_with_images_ip_count = 0;
$jobs_with_tables_ip_count = 0;

// For Today's Overview (XML UQ Pages)
$today_uq_processed_pages = 0; 

// We already have UQ clients logic above. Let's make an array of IDs.
$uq_client_ids = [];
$uq_res_for_ids = $mysqli->query("SELECT client_id FROM client_master WHERE client_name LIKE '%UQ%' OR company_name LIKE '%UQ%'");
if($uq_res_for_ids) {
    while($r = $uq_res_for_ids->fetch_assoc()) {
        $uq_client_ids[] = $r['client_id'];
    }
}
$uq_clients_str = !empty($uq_client_ids) ? implode(',', $uq_client_ids) : "0";

// Get Current Month/Year and Today
$current_month_year = date('Y-m'); 
$today = date('Y-m-d');

// Fetch jobs assigned to user OR any XML process jobs
$target_assignee = $mysqli->real_escape_string(!empty($current_user_name) ? $current_user_name : $ename);

$jobs_res = $mysqli->query("
    SELECT j.*, jm.job_name AS process, 
           ce.status AS ce_status, le.status AS le_status, 
           pag.status AS pag_status, qc.status AS qc_status,
           (SELECT COALESCE(SUM(article_count), 0) FROM xml_job_articles WHERE xml_job_id = j.job_id) AS xml_sum
    FROM jobs j 
    JOIN job_master jm ON j.process_id = jm.job_id 
    LEFT JOIN ce ON ce.job_id = j.job_id 
    LEFT JOIN le ON le.job_id = j.job_id 
    LEFT JOIN pag ON pag.job_id = j.job_id 
    LEFT JOIN qc ON qc.job_id = j.job_id 
    WHERE (j.assigned_to = '$target_assignee' OR j.assigned_to = '$ename' OR jm.job_name LIKE '%XML%')
");

while ($row = $jobs_res->fetch_assoc()) {
    $pname = strtoupper(str_replace(' ', '', $row['process'] ?? ''));
    
    // Status Logic
    $processStageMap = ['CE'=>['CE','LE'],'PAG'=>['PAG','QC'],'CE+PAG'=>['CE','LE','PAG','QC'],'CE+PAG+XML'=>['CE','LE','PAG','QC','XML'],'CE+XML'=>['CE','LE','XML'],'PAG+XML'=>['PAG','QC','XML'],'XML'=>['XML']];
    $required = $processStageMap[$pname] ?? [];
    $is_done = true; $is_ip = false;

    foreach($required as $s) {
        $st = $row[strtolower($s).'_status'] ?? 'Need to Assign';
        if($st !== 'Completed') $is_done = false;
        if($st === 'In Progress') $is_ip = true;
    }
    
    if($is_done) {
        $completed_count++;
    } elseif($is_ip) {
        $in_progress_count++;
        
        // Track jobs assigned to images/tables (Count of jobs)
        if((int)($row['images'] ?? 0) > 0) $jobs_with_images_ip_count++;
        if((int)($row['tables'] ?? 0) > 0) $jobs_with_tables_ip_count++;

        // Track UQ XML Processed Pages (In Progress jobs for UQ XML)
        if (in_array($row['client_id'], $uq_client_ids) && strpos($pname, 'XML') !== false) {
            $today_uq_processed_pages += (int)($row['page_count'] ?? 0);
        }

    } else {
        $need_to_assign_count++;
    }

    // --- MONTHLY ACHIEVED CALCULATION ---
    $job_month_year = date('Y-m', strtotime($row['job_date']));
    if ($job_month_year === $current_month_year) {
        $monthly_xml_articles += (int)($row['xml_sum'] ?? 0);
        $monthly_ce_words += (int)($row['word_count'] ?? 0);
        $monthly_pag_pages += (int)($row['page_count'] ?? 0);
        if (strpos($pname, 'AP') !== false) {
            $monthly_ap_articles++;
        }
    }
}
$total_jobs_count = $need_to_assign_count + $in_progress_count + $completed_count;

// UI PERCENTAGES
$nta_per = $total_jobs_count > 0 ? round(($need_to_assign_count / $total_jobs_count) * 100) : 0;
$ip_per = $total_jobs_count > 0 ? round(($in_progress_count / $total_jobs_count) * 100) : 0;
$c_per = $total_jobs_count > 0 ? round(($completed_count / $total_jobs_count) * 100) : 0;


// --- XML UQ TODAY'S RECEIVED AND UPLOADED PAGES ---
$today_uq_received_pages = 0;
$today_uq_uploaded_pages = 0;

// Received today (Job Date)
$rec_res = $mysqli->query("SELECT COALESCE(SUM(page_count), 0) as total FROM jobs j JOIN job_master jm ON j.process_id = jm.job_id WHERE DATE(j.job_date) = '$today' AND j.client_id IN ($uq_clients_str) AND jm.job_name LIKE '%XML%'");
if($rec_res) $today_uq_received_pages = (int)$rec_res->fetch_row()[0];

// Uploaded today (Dispatch Date)
$up_res = $mysqli->query("SELECT COALESCE(SUM(page_count), 0) as total FROM jobs j JOIN job_master jm ON j.process_id = jm.job_id WHERE DATE(j.dispatch_date) = '$today' AND j.client_id IN ($uq_clients_str) AND jm.job_name LIKE '%XML%'");
if($up_res) $today_uq_uploaded_pages = (int)$up_res->fetch_row()[0];

?>

<main class="main-content">
    
    <div class="page-title-container">
        <h1><i class="fas fa-chart-line"></i>Dashboard</h1>
    </div>
    <p class="page-description">Welcome back! <?php echo htmlspecialchars($current_user_name ?: $ename); ?></p>

    <!-- ROW 1: CLIENTS -->
    <div class="row mb-3 g-2">
        <div class="col-md-4"><div class="stat-card compact-card" style="border-left-color: #4a69bd;"><div class="stat-icon" style="background:#4a69bd"><i class="fas fa-users"></i></div><div class="stat-info"><h3><?= $total_client_count ?></h3><p>Total Clients</p></div></div></div>
        <div class="col-md-4"><div class="stat-card compact-card" style="border-left-color: #0984e3;"><div class="stat-icon" style="background:#0984e3"><i class="fas fa-user-check"></i></div><div class="stat-info"><h3><?= $active_client_count ?></h3><p>Active Clients</p></div></div></div>
        <div class="col-md-4"><div class="stat-card compact-card" style="border-left-color: #d63031;"><div class="stat-icon" style="background:#d63031"><i class="fas fa-user-slash"></i></div><div class="stat-info"><h3><?= $inactive_client_count ?></h3><p>Inactive Clients</p></div></div></div>
    </div>

    <!-- ROW 2: PRODUCTION -->
    <div class="row mb-4 g-2">
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #6c5ce7;"><div class="stat-icon" style="background:#6c5ce7"><i class="fas fa-file-word"></i></div><div class="stat-info"><h3><?= number_format($monthly_ce_words) ?></h3><p>CE Words</p></div></div></div>
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #00d2d3;"><div class="stat-icon" style="background:#00d2d3"><i class="fas fa-file-alt"></i></div><div class="stat-info"><h3><?= number_format($monthly_pag_pages) ?></h3><p>TS Pages</p></div></div></div>
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #ff7675;"><div class="stat-icon" style="background:#ff7675"><i class="fas fa-exclamation-circle"></i></div><div class="stat-info"><h3><?= number_format($monthly_ap_articles) ?></h3><p>AP Articles</p></div></div></div>
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #d63031;"><div class="stat-icon" style="background:#d63031"><i class="fas fa-code"></i></div><div class="stat-info"><h3><?= number_format($monthly_xml_articles) ?></h3><p>XML Pages</p></div></div></div>
    </div>

    <!-- ROW 3: JOB STATUS -->
    <div class="row mb-3 g-2">
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #00cec9;"><div class="stat-icon" style="background:#00cec9"><i class="fas fa-tasks"></i></div><div class="stat-info"><h3><?= $total_jobs_count ?> <small class="text-dark fw-bold">(100%)</small></h3><p>Total Jobs</p></div></div></div>
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #f39c12;"><div class="stat-icon" style="background:#f39c12"><i class="fas fa-clock"></i></div><div class="stat-info"><h3><?= $need_to_assign_count ?> <small class="text-dark fw-bold">(<?= $nta_per ?>%)</small></h3><p>Need to Assign</p></div></div></div>
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #0984e3;"><div class="stat-icon" style="background:#0984e3"><i class="fas fa-spinner"></i></div><div class="stat-info"><h3><?= $in_progress_count ?> <small class="text-dark fw-bold">(<?= $ip_per ?>%)</small></h3><p>In Progress</p></div></div></div>
        <div class="col-md-3"><div class="stat-card compact-card" style="border-left-color: #27ae60;"><div class="stat-icon" style="background:#27ae60"><i class="fas fa-check-circle"></i></div><div class="stat-info"><h3><?= $completed_count ?> <small class="text-dark fw-bold">(<?= $c_per ?>%)</small></h3><p>Completed</p></div></div></div>
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
        </div>

        <div class="stat-card">
            <div class="stat-icon total">
                <i class="fas fa-tasks"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($total_jobs_count) ?></h3>
                <p>Total Jobs</p>
            </div>
        </div> -->

        <!-- Total Images Card -->
        <!-- <div class="stat-card" style="border-left-color: #f5576c;">
            <div class="stat-icon" style="background: linear-gradient(45deg, #fa709a, #fee140);">
                <i class="fas fa-image"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($total_images) ?></h3>
                <p>Total Images</p>
            </div>
        </div> -->

        <!-- Total Tables Card -->
        <!-- <div class="stat-card" style="border-left-color: #4facfe;">
            <div class="stat-icon" style="background: linear-gradient(45deg, #a1c4fd, #c2e9fb);">
                <i class="fas fa-table"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($total_tables) ?></h3>
                <p>Total Tables</p>
            </div>
        </div> -->

        <!-- Total Equations Card -->
        <!-- <div class="stat-card" style="border-left-color: #b19ffb;">
            <div class="stat-icon" style="background: linear-gradient(45deg, #fbc2eb, #a6c1ee);">
                <i class="fas fa-calculator"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($total_equations) ?></h3>
                <p>Total Equations</p>
            </div>
        </div>
    </div> -->

    <!-- Quick Stats Section -->
    <div class="row mt-4">
        
        <!-- LEFT: Today's Overview (XML UQ) -->
        <div class="col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-header">
                    <h5><i class="fas fa-calendar-check me-2"></i>Today's Overview</h5>
                </div>
                <div class="card-body">
                    <div class="today-stats">
                        <div class="today-stat-item">
                            <i class="fas fa-file-import text-primary"></i>
                            <div class="today-stat-info">
                                <h4><?= number_format($today_uq_received_pages) ?></h4>
                                <p>Today's Received Pages</p>
                            </div>
                        </div>

                        <div class="today-stat-item">
                            <i class="fas fa-file-export text-success"></i>
                            <div class="today-stat-info">
                                <h4><?= number_format($today_uq_uploaded_pages) ?></h4>
                                <p>Today's Uploaded Pages</p>
                            </div>
                        </div>

                        <div class="today-stat-item">
                            <i class="fas fa-spinner fa-spin text-warning"></i>
                            <div class="today-stat-info">
                                <h4><?= number_format($today_uq_processed_pages) ?></h4>
                                <p>Today's Processed Pages</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: Job In-Progress Distribution -->
        <div class="col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-header">
                    <h5><i class="fas fa-list-alt me-2"></i>Job In-Progress Distribution</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-column gap-3">
                        
                        <div class="d-flex justify-content-between align-items-center p-2 rounded bg-light border-start border-4 border-info">
                            <span class="fw-bold" style="font-size: 0.95rem; color: #2c3e50;">Number of Jobs Assigned to Tables</span>
                            <span class="badge bg-info rounded-pill" style="font-size: 0.9rem;"><?= $jobs_with_tables_ip_count ?></span>
                        </div>

                        <div class="d-flex justify-content-between align-items-center p-2 rounded bg-light border-start border-4 border-danger">
                            <span class="fw-bold" style="font-size: 0.95rem; color: #2c3e50;">Number of Jobs Assigned to Images</span>
                            <span class="badge bg-danger rounded-pill" style="font-size: 0.9rem;"><?= $jobs_with_images_ip_count ?></span>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center p-2 rounded bg-light border-start border-4 border-success">
                            <span class="fw-bold" style="font-size: 0.95rem; color: #2c3e50;">Completed Jobs</span>
                            <span class="badge bg-success rounded-pill" style="font-size: 0.9rem;"><?= $completed_count ?></span>
                        </div>

                        <div class="d-flex justify-content-between align-items-center p-2 rounded bg-light border-start border-4 border-primary">
                            <span class="fw-bold" style="font-size: 0.95rem; color: #2c3e50;">In-Process Jobs</span>
                            <span class="badge bg-primary rounded-pill" style="font-size: 0.9rem;"><?= $in_progress_count ?></span>
                        </div>

                        <div class="d-flex justify-content-between align-items-center p-2 rounded bg-light border-start border-4 border-warning">
                            <span class="fw-bold" style="font-size: 0.95rem; color: #2c3e50;">Pending Jobs</span>
                            <span class="badge bg-warning text-dark rounded-pill" style="font-size: 0.9rem;"><?= $need_to_assign_count ?></span>
                        </div>

                    </div>
                </div>
            </div>
        </div>

    </div> 
    <!-- End of row mt-4 -->
         <!-- <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie me-2"></i>Job Status Distribution</h5>
                </div>
                <div class="card-body">
                    <div class="progress-stats">
                        <div class="progress-item">
                            <span class="progress-label">Jobs Need to Assign</span>
                            <div class="progress">
                                <div class="progress-bar bg-warning" style="width: <?= $total_jobs_count > 0 ? ($need_to_assign_count / $total_jobs_count) * 100 : 0 ?>%">
                                    <?= $total_jobs_count > 0 ? round(($need_to_assign_count / $total_jobs_count) * 100, 1) : 0 ?>%
                                </div>
                            </div>
                        </div>
                        <div class="progress-item">
                            <span class="progress-label"> In Progress Jobs</span>
                            <div class="progress">
                                <div class="progress-bar bg-info" style="width: <?= $total_jobs_count > 0 ? ($in_progress_count / $total_jobs_count) * 100 : 0 ?>%">
                                    <?= $total_jobs_count > 0 ? round(($in_progress_count / $total_jobs_count) * 100, 1) : 0 ?>%
                                </div>
                            </div>
                        </div>
                        <div class="progress-item">
                            <span class="progress-label">Completed Jobs</span>
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

 /* Compact Box Styling for XML TL */
.stat-card {
    background: white; border-radius: 8px; padding: 10px 15px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05); border-left: 5px solid #007bff;
    display: flex; align-items: center; height: 75px; margin-bottom: 5px;
    transition: transform 0.2s ease;
}
.stat-card:hover { transform: translateY(-2px); }
.stat-icon {
    width: 40px; height: 40px; border-radius: 50%; margin-right: 12px;
    font-size: 15px; display: flex; align-items: center; justify-content: center; color: white;
}
.stat-info h3 { font-size: 1.25rem; margin: 0; font-weight: 700; color: #2d3436; line-height: 1.1; }
.stat-info p { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: #636e72; margin: 2px 0 0 0; }
.stat-info small { font-size: 0.8rem; }

.page-title-container {
    background: #5e6b77; color: white; padding: 12px 20px; border-radius: 12px; margin-bottom: 10px;
}
.page-title-container h1 { margin: 0; font-size: 2.2rem; font-weight: 700; }
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