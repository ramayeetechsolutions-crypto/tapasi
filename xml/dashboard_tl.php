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

// =========================================================================
// 1. CLIENT LOGIC: UQ CLIENT OVERVIEW (Active, Passive, Inactive)
// =========================================================================
$total_client_count = 0;
$active_client_count = 0;
$passive_client_count = 0;
$inactive_client_count = 0;

// Fetch Client Master data
$client_status_res = $mysqli->query("SELECT status, COUNT(*) as cnt FROM client_master GROUP BY status");
if ($client_status_res) {
    while ($cs = $client_status_res->fetch_assoc()) {
        if ($cs['status'] === 'Active') {
            $total_active_in_master = (int)$cs['cnt'];
        } elseif ($cs['status'] === 'Inactive') {
            $inactive_client_count = (int)$cs['cnt'];
        }
    }
}

// Active clients = Clients who currently have jobs in jobs / unique_xml_jobs
$active_clients_res = $mysqli->query("SELECT COUNT(DISTINCT client_id) as cnt FROM jobs WHERE client_id IN (SELECT client_id FROM client_master WHERE status = 'Active')");
if ($active_clients_res && $row_act = $active_clients_res->fetch_assoc()) {
    $active_client_count = (int)$row_act['cnt'];
}

// Passive clients = Active in master but NO jobs assigned
$passive_client_count = max(0, ($total_active_in_master ?? 0) - $active_client_count);
$total_client_count = $active_client_count + $passive_client_count + $inactive_client_count;


// =========================================================================
// 2. UQ PROCESS (unique_xml_jobs): MONTHLY PAGE STATS & JOB OVERVIEW
// =========================================================================
// Mela erukura cards ellam current month oda status kaattum (eg: 2026-11).
// Previous month (October) paakanum na URL-la ?month=2026-10 nu add pannikalam.
$current_month_year = $_GET['month'] ?? date('Y-m'); 
$today = $_GET['date'] ?? date('Y-m-d');

// Month wise metrics (Top Cards)
$monthly_xml_pages = 0;                   // NLM + JATS + CLOCKSS + SCIELO + EBSCO
$monthly_html_pages = 0;                  // HTML + NEW HTML
$monthly_doaj_pubmed_biblat_pages = 0;    // DOAJ + PUBMED + BIBLAT
$monthly_direct_html_pages = 0;           // DIRECT HTML

$total_jobs_month = 0;
$need_to_assign_count = 0;
$in_progress_count = 0;
$completed_count = 0;

// Today's metrics (UQ Process & Upload - Bottom Cards)
$today_received_pages = 0;
$today_uploaded_pages = 0;
$in_process_pages = 0;

// Today's Job In-Progress Distribution (Table & Image Tracking separated)
$today_jobs_assigned_tables = 0;
$today_table_completed = 0;
$today_table_inprogress = 0;
$today_table_pending = 0;

$today_jobs_assigned_images = 0;
$today_image_completed = 0;
$today_image_inprogress = 0;
$today_image_pending = 0;

// Query all UQ Process jobs from unique_xml_jobs
$uq_jobs_query = $mysqli->query("SELECT * FROM unique_xml_jobs ORDER BY id DESC");

if ($uq_jobs_query) {
    while ($j = $uq_jobs_query->fetch_assoc()) {
        $jid = (int)$j['id'];
        $rec_date = !empty($j['rec_date']) && $j['rec_date'] !== '0000-00-00' ? substr($j['rec_date'], 0, 10) : substr($j['created_at'] ?? '', 0, 10);
        $job_month = substr($rec_date, 0, 7);

        // Safe fetch from xml_job_articles (ignoring NULLs)
        $art_res = $mysqli->query("
            SELECT 
                SUM(COALESCE(NULLIF(nlm, ''), 0)) as s_nlm,
                SUM(COALESCE(NULLIF(jats, ''), 0)) as s_jats,
                SUM(COALESCE(NULLIF(clockss, ''), 0)) as s_clockss,
                SUM(COALESCE(NULLIF(scielo, ''), 0)) as s_scielo,
                SUM(COALESCE(NULLIF(ebsco, ''), 0)) as s_ebsco,
                SUM(COALESCE(NULLIF(html_1, ''), 0)) as s_html,
                SUM(COALESCE(NULLIF(new_html, ''), 0)) as s_new_html,
                SUM(COALESCE(NULLIF(direct_html, ''), 0)) as s_direct_html,
                SUM(COALESCE(NULLIF(doaj, ''), 0)) as s_doaj,
                SUM(COALESCE(NULLIF(pubmed, ''), 0)) as s_pubmed,
                SUM(COALESCE(NULLIF(biblat, ''), 0)) as s_biblat,
                SUM(COALESCE(NULLIF(article_count, ''), 0)) as s_art_count
            FROM xml_job_articles 
            WHERE xml_job_id = $jid
        ");
        $ad = $art_res ? $art_res->fetch_assoc() : null;
        $has_arts = ($ad && ((int)($ad['s_art_count'] ?? 0) > 0 || (int)($ad['s_nlm'] ?? 0) > 0 || (int)($ad['s_clockss'] ?? 0) > 0));

        if ($has_arts) {
            $job_xml = (int)($ad['s_nlm'] ?? 0) + (int)($ad['s_jats'] ?? 0) + (int)($ad['s_clockss'] ?? 0) + (int)($ad['s_scielo'] ?? 0) + (int)($ad['s_ebsco'] ?? 0);
            $job_html = (int)($ad['s_html'] ?? 0) + (int)($ad['s_new_html'] ?? 0);
            $job_doaj_pub = (int)($ad['s_doaj'] ?? 0) + (int)($ad['s_pubmed'] ?? 0) + (int)($ad['s_biblat'] ?? 0);
            $job_direct_html = (int)($ad['s_direct_html'] ?? 0);
            $job_total_pages = $job_xml + $job_html + $job_doaj_pub + $job_direct_html;
            if ($job_total_pages === 0) $job_total_pages = (int)($ad['s_art_count'] ?? 0);
        } else {
            $job_xml = (int)($j['nlm'] ?? 0) + (int)($j['jats'] ?? 0) + (int)($j['clockss'] ?? 0) + (int)($j['scielo'] ?? 0) + (int)($j['ebsco'] ?? 0);
            $job_html = (int)($j['html'] ?? 0) + (int)($j['new_html'] ?? 0);
            $job_doaj_pub = (int)($j['doaj'] ?? 0) + (int)($j['pubmed'] ?? 0) + (int)($j['biblat'] ?? 0);
            $job_direct_html = (int)($j['direct_html'] ?? 0);
            $job_total_pages = $job_xml + $job_html + $job_doaj_pub + $job_direct_html;
            if ($job_total_pages === 0) $job_total_pages = (int)($j['articles'] ?? 0);
        }

        // --- Monthly Filters (Top Cards: Current Month) ---
        if ($job_month === $current_month_year) {
            $total_jobs_month++;
            $monthly_xml_pages += $job_xml;
            $monthly_html_pages += $job_html;
            $monthly_doaj_pubmed_biblat_pages += $job_doaj_pub;
            $monthly_direct_html_pages += $job_direct_html;

            // Monthly Job Status Overview
            $st = strtolower(trim((string)($j['status'] ?? '')));
            $jst = strtolower(trim((string)($j['job_status'] ?? '')));

            if ($st === 'completed' || $jst === 'despatched') {
                $completed_count++;
            } elseif ($st === 'inprocess' || $st === 'in progress' || $jst === 'inprocess' || $jst === 'in progress') {
                $in_progress_count++;
            } else {
                $need_to_assign_count++;
            }
        }

        // --- Today's Metrics & Distribution (Bottom Cards: Only Today's Jobs) ---
        $is_today_job = ($rec_date === $today);

        if ($is_today_job) {
            // 1. Today Received Pages
            $today_received_pages += $job_total_pages;

            $st_today = strtolower(trim((string)($j['status'] ?? '')));
            $jst_today = strtolower(trim((string)($j['job_status'] ?? '')));
            $is_job_done = ($st_today === 'completed' || $jst_today === 'despatched');

            // 2. Table Tracking for Today
            $tbl_u = trim((string)($j['Table_user'] ?? ''));
            $is_tbl_completed = ($is_job_done || strpos($tbl_u, '✓') !== false || stripos($tbl_u, 'done') !== false || stripos($tbl_u, 'completed') !== false);
            $is_tbl_assigned = ($tbl_u !== '' && $tbl_u !== '-' && strtolower($tbl_u) !== 'n/a' && strtolower($tbl_u) !== 'na');

            if ($is_tbl_assigned) {
                $today_jobs_assigned_tables++;
            }

            if ($is_tbl_completed) {
                $today_table_completed++;
            } elseif ($is_tbl_assigned) {
                $today_table_inprogress++;
            } else {
                $today_table_pending++;
            }

            // 3. Image Tracking for Today
            $img_u = trim((string)($j['Image_user'] ?? ''));
            $inst_img = strtolower(trim((string)($j['instruction_to_image'] ?? '')));
            $is_img_na = ($inst_img === 'no images' || strtolower($img_u) === 'n/a' || strtolower($img_u) === 'na');

            if (!$is_img_na) {
                $is_img_assigned = ($img_u !== '' && $img_u !== '-');
                if ($is_img_assigned) {
                    $today_jobs_assigned_images++;
                }

                $is_img_completed = ($is_job_done || strpos($img_u, '✓') !== false || stripos($img_u, 'done') !== false || stripos($img_u, 'completed') !== false);
                if ($is_img_completed) {
                    $today_image_completed++;
                } elseif ($is_img_assigned) {
                    $today_image_inprogress++;
                } else {
                    $today_image_pending++;
                }
            }
        }

        // 3. Today Uploaded Pages (from despatched_date when despatched/completed today)
        $disp_date = !empty($j['despatched_date']) && $j['despatched_date'] !== '0000-00-00' ? substr($j['despatched_date'], 0, 10) : '';
        if ($disp_date === $today && (strtolower($j['job_status'] ?? '') === 'despatched' || strtolower($j['status'] ?? '') === 'completed')) {
            $today_uploaded_pages += $job_total_pages;
        }

        // 4. Inprocess Pages (Strictly Today's in-process jobs)
        $is_inprocess = (strtolower($j['status'] ?? '') === 'inprocess' || strtolower($j['job_status'] ?? '') === 'inprocess');
        if ($is_today_job && $is_inprocess) {
            $in_process_pages += $job_total_pages;
        }
    }
}
?>

<main class="main-content">
    
    <div class="page-title-container">
        <h1><i class="fas fa-chart-line me-2"></i>Dashboard</h1>
    </div>
    <p class="page-description">Welcome back! <?php echo htmlspecialchars($current_user_name ?: $ename); ?></p>

    <!-- ROW 1: CLIENT OVERVIEW -->
    <div class="row mb-3 g-2">
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #4a69bd;">
                <div class="stat-icon" style="background:#4a69bd"><i class="fas fa-users"></i></div>
                <div class="stat-info"><h3><?= number_format($total_client_count) ?></h3><p>Total Clients</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #0984e3;">
                <div class="stat-icon" style="background:#0984e3"><i class="fas fa-user-check"></i></div>
                <div class="stat-info"><h3><?= number_format($active_client_count) ?></h3><p>Active Clients</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #f39c12;">
                <div class="stat-icon" style="background:#f39c12"><i class="fas fa-user-clock"></i></div>
                <div class="stat-info"><h3><?= number_format($passive_client_count) ?></h3><p>Passive Clients</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #d63031;">
                <div class="stat-icon" style="background:#d63031"><i class="fas fa-user-slash"></i></div>
                <div class="stat-info"><h3><?= number_format($inactive_client_count) ?></h3><p>Inactive Clients</p></div>
            </div>
        </div>
    </div>

    <!-- ROW 2: PRODUCTION / PAGE STATISTICS -->
    <div class="row mb-3 g-2">
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #6c5ce7;">
                <div class="stat-icon" style="background:#6c5ce7"><i class="fas fa-code"></i></div>
                <div class="stat-info"><h3><?= number_format($monthly_xml_pages) ?></h3><p>XML Pages</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #00d2d3;">
                <div class="stat-icon" style="background:#00d2d3"><i class="fas fa-file-code"></i></div>
                <div class="stat-info"><h3><?= number_format($monthly_html_pages) ?></h3><p>HTML Pages</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #ff7675;">
                <div class="stat-icon" style="background:#ff7675"><i class="fas fa-book-open"></i></div>
                <div class="stat-info"><h3><?= number_format($monthly_doaj_pubmed_biblat_pages) ?></h3><p>DOAJ / PubMed / Biblat</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #d63031;">
                <div class="stat-icon" style="background:#d63031"><i class="fas fa-file-alt"></i></div>
                <div class="stat-info"><h3><?= number_format($monthly_direct_html_pages) ?></h3><p>Direct HTML Pages</p></div>
            </div>
        </div>
    </div>

    <!-- ROW 3: JOB OVERVIEW -->
    <div class="row mb-4 g-2">
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #00cec9;">
                <div class="stat-icon" style="background:#00cec9"><i class="fas fa-tasks"></i></div>
                <div class="stat-info"><h3><?= number_format($total_jobs_month) ?></h3><p>Total Jobs</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #f39c12;">
                <div class="stat-icon" style="background:#f39c12"><i class="fas fa-clock"></i></div>
                <div class="stat-info"><h3><?= number_format($need_to_assign_count) ?></h3><p>Need to Assign</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #0984e3;">
                <div class="stat-icon" style="background:#0984e3"><i class="fas fa-spinner"></i></div>
                <div class="stat-info"><h3><?= number_format($in_progress_count) ?></h3><p>In Progress</p></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card compact-card" style="border-left-color: #27ae60;">
                <div class="stat-icon" style="background:#27ae60"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info"><h3><?= number_format($completed_count) ?></h3><p>Completed</p></div>
            </div>
        </div>
    </div>

    <!-- ROW 4: TODAY'S OVERVIEW & JOB IN-PROGRESS DISTRIBUTION -->
    <div class="row mt-3">
        
        <!-- LEFT: Today's Overview (Image 3 Icon Style) -->
        <div class="col-md-6 mb-3">
            <div class="card h-100 shadow-sm border-0 dashboard-card">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 style="color: #000000; margin: 0; font-weight: 600;">
                        <i class="fas fa-calendar-check text-primary me-2"></i>Today's Overview
                    </h5>
                </div>
                <div class="card-body p-3">
                    <div class="today-stats d-flex flex-column gap-3">
                        
                        <!-- Today Received -->
                        <div class="today-stat-item d-flex align-items-center p-3 rounded-3">
                            <div class="today-stat-icon-box text-primary me-3">
                                <i class="fas fa-file-import"></i>
                            </div>
                            <div class="today-stat-info">
                                <h4 class="fw-bold mb-0 text-dark"><?= number_format($today_received_pages) ?></h4>
                                <p class="mb-0 text-muted" style="font-size: 0.85rem;">Today's Received Pages</p>
                            </div>
                        </div>

                        <!-- Today Uploaded -->
                        <div class="today-stat-item d-flex align-items-center p-3 rounded-3">
                            <div class="today-stat-icon-box text-success me-3">
                                <i class="fas fa-file-export"></i>
                            </div>
                            <div class="today-stat-info">
                                <h4 class="fw-bold mb-0 text-dark"><?= number_format($today_uploaded_pages) ?></h4>
                                <p class="mb-0 text-muted" style="font-size: 0.85rem;">Today's Uploaded Pages</p>
                            </div>
                        </div>

                        <!-- In-Process Pages -->
                        <div class="today-stat-item d-flex align-items-center p-3 rounded-3">
                            <div class="today-stat-icon-box text-warning me-3">
                                <i class="fas fa-spinner fa-spin"></i>
                            </div>
                            <div class="today-stat-info">
                                <h4 class="fw-bold mb-0 text-dark"><?= number_format($in_process_pages) ?></h4>
                                <p class="mb-0 text-muted" style="font-size: 0.85rem;">In-Process Pages</p>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: Job In-Progress Distribution (Table & Image Tracking Separated) -->
        <div class="col-md-6 mb-3">
            <div class="card h-100 shadow-sm border-0 dashboard-card">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 style="color: #2c3e50; margin: 0; font-weight: 700; font-size: 1.05rem;">
                        <i class="fas fa-list-alt text-primary me-2"></i>Job In-Progress Distribution
                    </h5>
                </div>
                <div class="card-body p-3">
                    
                    <!-- TABLE JOBS TRACKING -->
                    <div class="tracking-section mb-3">
                        <div class="tracking-title text-uppercase mb-2" style="font-size: 0.78rem; font-weight: 700; color: #636e72; letter-spacing: 0.5px;">
                            TABLE JOBS TRACKING
                        </div>
                        <div class="d-flex flex-column gap-2">
                            <!-- Number of Jobs Assigned to Tables -->
                            <div class="d-flex justify-content-between align-items-center px-3 py-2 rounded bg-light border-start border-4 border-info tracking-row">
                                <span class="fw-bold" style="font-size: 0.92rem; color: #2d3436;">Number of Jobs Assigned to Tables</span>
                                <span class="badge bg-info rounded-pill px-3 py-1 fw-bold" style="font-size: 0.85rem;"><?= $today_jobs_assigned_tables ?></span>
                            </div>
                            <!-- Completed -->
                            <div class="d-flex justify-content-between align-items-center px-3 py-2 rounded bg-light border-start border-4 border-success tracking-row">
                                <span class="fw-bold" style="font-size: 0.92rem; color: #2d3436;">Completed</span>
                                <span class="badge bg-success rounded-pill px-3 py-1 fw-bold" style="font-size: 0.85rem;"><?= $today_table_completed ?></span>
                            </div>
                            <!-- In-Process -->
                            <div class="d-flex justify-content-between align-items-center px-3 py-2 rounded bg-light border-start border-4 border-primary tracking-row">
                                <span class="fw-bold" style="font-size: 0.92rem; color: #2d3436;">In-Process</span>
                                <span class="badge bg-primary rounded-pill px-3 py-1 fw-bold" style="font-size: 0.85rem;"><?= $today_table_inprogress ?></span>
                            </div>
                            <!-- Pending -->
                            <div class="d-flex justify-content-between align-items-center px-3 py-2 rounded bg-light border-start border-4 border-warning tracking-row">
                                <span class="fw-bold" style="font-size: 0.92rem; color: #2d3436;">Pending</span>
                                <span class="badge bg-warning text-dark rounded-pill px-3 py-1 fw-bold" style="background-color: #f1c40f !important; color: #2d3436 !important; font-size: 0.85rem;"><?= $today_table_pending ?></span>
                            </div>
                        </div>
                    </div>

                    <hr style="margin: 16px 0; border: 0; border-top: 1px solid #dfe6e9;">

                    <!-- IMAGE JOBS TRACKING -->
                    <div class="tracking-section">
                        <div class="tracking-title text-uppercase mb-2" style="font-size: 0.78rem; font-weight: 700; color: #636e72; letter-spacing: 0.5px;">
                            IMAGE JOBS TRACKING
                        </div>
                        <div class="d-flex flex-column gap-2">
                            <!-- Number of Jobs Assigned to Images -->
                            <div class="d-flex justify-content-between align-items-center px-3 py-2 rounded bg-light border-start border-4 border-danger tracking-row">
                                <span class="fw-bold" style="font-size: 0.92rem; color: #2d3436;">Number of Jobs Assigned to Images</span>
                                <span class="badge bg-danger rounded-pill px-3 py-1 fw-bold" style="font-size: 0.85rem;"><?= $today_jobs_assigned_images ?></span>
                            </div>
                            <!-- Completed -->
                            <div class="d-flex justify-content-between align-items-center px-3 py-2 rounded bg-light border-start border-4 border-success tracking-row">
                                <span class="fw-bold" style="font-size: 0.92rem; color: #2d3436;">Completed</span>
                                <span class="badge bg-success rounded-pill px-3 py-1 fw-bold" style="font-size: 0.85rem;"><?= $today_image_completed ?></span>
                            </div>
                            <!-- In-Process -->
                            <div class="d-flex justify-content-between align-items-center px-3 py-2 rounded bg-light border-start border-4 border-primary tracking-row">
                                <span class="fw-bold" style="font-size: 0.92rem; color: #2d3436;">In-Process</span>
                                <span class="badge bg-primary rounded-pill px-3 py-1 fw-bold" style="font-size: 0.85rem;"><?= $today_image_inprogress ?></span>
                            </div>
                            <!-- Pending -->
                            <div class="d-flex justify-content-between align-items-center px-3 py-2 rounded bg-light border-start border-4 border-warning tracking-row">
                                <span class="fw-bold" style="font-size: 0.92rem; color: #2d3436;">Pending</span>
                                <span class="badge bg-warning text-dark rounded-pill px-3 py-1 fw-bold" style="background-color: #f1c40f !important; color: #2d3436 !important; font-size: 0.85rem;"><?= $today_image_pending ?></span>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </div>

</main>

<style>
/* Smooth Dashboard Entrance Animation */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.main-content {
    animation: fadeInUp 0.4s ease-out;
}

/* Palaya Compact Box Styling with Hover Animation */
.stat-card {
    background: white; border-radius: 8px; padding: 10px 15px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05); border-left: 5px solid #007bff;
    display: flex; align-items: center; height: 75px; margin-bottom: 5px;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.08);
}
.stat-icon {
    width: 40px; height: 40px; border-radius: 50%; margin-right: 12px;
    font-size: 15px; display: flex; align-items: center; justify-content: center; color: white;
    transition: transform 0.25s ease;
}
.stat-card:hover .stat-icon {
    transform: scale(1.08);
}

.stat-info h3 { font-size: 1.25rem; margin: 0; font-weight: 700; color: #2d3436; line-height: 1.1; }
.stat-info p { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: #636e72; margin: 2px 0 0 0; }
.stat-info small { font-size: 0.8rem; }

.page-title-container {
    background: var(--ash, #6c757d); color: white; padding: 12px 20px; border-radius: 12px; margin-bottom: 10px;
}
.page-title-container h1 { margin: 0; font-size: 2.2rem; font-weight: 700; }
.page-description { color: #7f8c8d; font-size: 1rem; margin-bottom: 15px; }

/* Dashboard Cards with Subtle Hover Lift */
.dashboard-card {
    transition: all 0.25s ease;
}
.dashboard-card:hover {
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.07) !important;
}

/* Today's Overview (Image 3 Style) */
.today-stat-item {
    background: #f8f9fa;
    border-radius: 10px;
    transition: all 0.25s ease;
}
.today-stat-item:hover {
    background: #eef2f7;
    transform: translateX(4px);
}
.today-stat-icon-box {
    font-size: 1.85rem;
    width: 42px;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Tracking Rows Hover Animation (Image 2) */
.tracking-row {
    transition: all 0.25s ease;
}
.tracking-row:hover {
    transform: translateX(4px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
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