<?php
// =========================================================================
// FILE: xml/dashboard_tl.php
// PURPOSE: XML & UQ Team Lead Executive Production Dashboard
// 100% Preserved Functionality • Senior Developer Executive UI
// =========================================================================

require 'db.php';
session_start();

$pageTitle = "Dashboard";
$currentPage = "dashboard";
include 'header.php';

// Get current user
$current_user = $_SESSION['username'] ?? '';
$employee_username = $current_user;

// Fetch employee name
$current_user_name = '';
$sql = "SELECT `employee_name` FROM `employee_master` WHERE `employee_username` = ?";
$stmt = $mysqli->prepare($sql);
if ($stmt) {
    $stmt->bind_param("s", $employee_username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $current_user_name = $row['employee_name'];
    }
    $stmt->close();
}

$today = date('Y-m-d');
$current_month_year = date('Y-m');

// --- 1. CLIENT LOGIC (Filter by UQ Clients: 1, 3, 5) ---
$uq_client_ids = [1, 3, 5];
$uq_clients_str = implode(',', $uq_client_ids);

$total_client_count = 0;
$active_client_count = 0;
$inactive_client_count = 0;

$client_res = $mysqli->query("SELECT status, COUNT(*) as count FROM client_master WHERE client_id IN ($uq_clients_str) GROUP BY status");
if ($client_res) {
    while ($c = $client_res->fetch_assoc()) {
        if ($c['status'] == 'Active') $active_client_count = (int)$c['count'];
        if ($c['status'] == 'Inactive') $inactive_client_count = (int)$c['count'];
    }
}
$total_client_count = $active_client_count + $inactive_client_count;

// --- 2. METRICS INITIALIZATION ---
$need_to_assign_count = 0;
$in_progress_count = 0;
$completed_count = 0;

$monthly_xml_articles = 0;
$monthly_ce_words = 0;
$monthly_pag_pages = 0;
$monthly_ap_articles = 0;

$jobs_with_images_ip_count = 0;
$jobs_with_tables_ip_count = 0;
$today_uq_processed_pages = 0;

$ename = $_SESSION["ename"] ?? $current_user_name;

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

if ($jobs_res) {
    while ($row = $jobs_res->fetch_assoc()) {
        $pname = strtoupper(str_replace(' ', '', $row['process'] ?? ''));
        
        // Status Logic
        $processStageMap = [
            'CE' => ['CE', 'LE'],
            'PAG' => ['PAG', 'QC'],
            'CE+PAG' => ['CE', 'LE', 'PAG', 'QC'],
            'CE+PAG+XML' => ['CE', 'LE', 'PAG', 'QC', 'XML'],
            'CE+XML' => ['CE', 'LE', 'XML'],
            'PAG+XML' => ['PAG', 'QC', 'XML'],
            'XML' => ['XML']
        ];
        $required = $processStageMap[$pname] ?? [];
        $is_done = true; 
        $is_ip = false;

        foreach ($required as $s) {
            $st = $row[strtolower($s).'_status'] ?? 'Need to Assign';
            if ($st !== 'Completed') $is_done = false;
            if ($st === 'In Progress') $is_ip = true;
        }
        
        if ($is_done) {
            $completed_count++;
        } elseif ($is_ip) {
            $in_progress_count++;
            if ((int)($row['images'] ?? 0) > 0) $jobs_with_images_ip_count++;
            if ((int)($row['tables'] ?? 0) > 0) $jobs_with_tables_ip_count++;

            if (in_array($row['client_id'], $uq_client_ids) && strpos($pname, 'XML') !== false) {
                $today_uq_processed_pages += (int)($row['page_count'] ?? 0);
            }
        } else {
            $need_to_assign_count++;
        }

        // MONTHLY ACHIEVED CALCULATION
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
}
$total_jobs_count = $need_to_assign_count + $in_progress_count + $completed_count;

// UI PERCENTAGES
$nta_per = $total_jobs_count > 0 ? round(($need_to_assign_count / $total_jobs_count) * 100) : 0;
$ip_per  = $total_jobs_count > 0 ? round(($in_progress_count / $total_jobs_count) * 100) : 0;
$c_per   = $total_jobs_count > 0 ? round(($completed_count / $total_jobs_count) * 100) : 0;

$active_client_per = $total_client_count > 0 ? round(($active_client_count / $total_client_count) * 100) : 0;
$inactive_client_per = $total_client_count > 0 ? round(($inactive_client_count / $total_client_count) * 100) : 0;

// --- XML UQ TODAY'S RECEIVED AND UPLOADED PAGES ---
$today_uq_received_pages = 0;
$today_uq_uploaded_pages = 0;

$rec_res = $mysqli->query("SELECT COALESCE(SUM(page_count), 0) as total FROM jobs j JOIN job_master jm ON j.process_id = jm.job_id WHERE DATE(j.job_date) = '$today' AND j.client_id IN ($uq_clients_str) AND jm.job_name LIKE '%XML%'");
if ($rec_res) $today_uq_received_pages = (int)$rec_res->fetch_row()[0];

$up_res = $mysqli->query("SELECT COALESCE(SUM(page_count), 0) as total FROM jobs j JOIN job_master jm ON j.process_id = jm.job_id WHERE DATE(j.dispatch_date) = '$today' AND j.client_id IN ($uq_clients_str) AND jm.job_name LIKE '%XML%'");
if ($up_res) $today_uq_uploaded_pages = (int)$up_res->fetch_row()[0];
?>

<style>
/* =========================================================================
   SENIOR DEVELOPER EXECUTIVE DASHBOARD STYLING
   ========================================================================= */
.main-content {
    padding-top: 85px;
    padding-bottom: 50px;
    background-color: #f8fafc;
    min-height: 100vh;
}

/* Executive Hero Banner */
.dashboard-hero-banner {
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
    border-radius: 18px;
    padding: 24px 28px;
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.08);
}
.dashboard-hero-banner::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: 35%;
    background: radial-gradient(circle at top right, rgba(59, 130, 246, 0.15), transparent 70%);
    pointer-events: none;
}
.hero-chip-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 30px;
    font-size: 0.74rem;
    font-weight: 600;
    background: rgba(255, 255, 255, 0.1);
    color: #f8fafc;
    border: 1px solid rgba(255, 255, 255, 0.14);
    backdrop-filter: blur(4px);
}
.hero-chip-badge.live {
    background: rgba(16, 185, 129, 0.15);
    color: #34d399;
    border-color: rgba(16, 185, 129, 0.3);
}
.live-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background-color: #10b981;
    display: inline-block;
    box-shadow: 0 0 8px #10b981;
    animation: livePulse 2s infinite;
}
@keyframes livePulse {
    0% { transform: scale(0.95); opacity: 0.8; }
    50% { transform: scale(1.25); opacity: 1; }
    100% { transform: scale(0.95); opacity: 0.8; }
}
.hero-greeting {
    font-size: 1.65rem;
    letter-spacing: -0.4px;
}
.hero-name-highlight {
    background: linear-gradient(120deg, #60a5fa, #93c5fd);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}
.hero-subtitle {
    font-size: 0.88rem;
    max-width: 620px;
}
.hero-quick-pills {
    display: inline-flex;
    align-items: center;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    padding: 10px 18px;
    backdrop-filter: blur(8px);
}
.quick-pill-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 0 10px;
}
.quick-pill-label {
    font-size: 0.68rem;
    text-transform: uppercase;
    font-weight: 600;
    color: #94a3b8;
    letter-spacing: 0.5px;
}
.quick-pill-val {
    font-size: 1.15rem;
    font-weight: 800;
    color: #ffffff;
    line-height: 1.2;
}
.quick-pill-divider {
    width: 1px;
    height: 28px;
    background: rgba(255, 255, 255, 0.15);
}

/* Section Header Bar */
.dashboard-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 24px;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid #e2e8f0;
}
.section-icon-bubble {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}
.section-title-text {
    font-size: 0.84rem;
    font-weight: 800;
    color: #1e293b;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
.section-subtitle-text {
    display: block;
    font-size: 0.72rem;
    color: #64748b;
    font-weight: 500;
}
.section-badge-pill {
    font-size: 0.74rem;
    font-weight: 600;
    color: #475569;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    padding: 4px 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
}

/* Stat Cards Architecture */
.stat-card.compact-card {
    background: #ffffff;
    border-radius: 14px;
    padding: 16px 18px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
    display: flex;
    align-items: center;
    min-height: 88px;
    position: relative;
    overflow: hidden;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
.stat-card.compact-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    bottom: 0;
    width: 4px;
    background: var(--card-accent, #3b82f6);
    border-top-left-radius: 14px;
    border-bottom-left-radius: 14px;
    transition: width 0.2s ease;
}
.stat-card.compact-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.07);
    border-color: #cbd5e1;
}
.stat-card.compact-card:hover::before {
    width: 6px;
}

.stat-icon {
    width: 48px;
    height: 48px;
    min-width: 48px;
    border-radius: 12px;
    margin-right: 14px;
    font-size: 19px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
    transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
.stat-card.compact-card:hover .stat-icon {
    transform: scale(1.08);
}

.stat-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-width: 0;
}
.stat-top-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
}
.stat-info h3 {
    font-size: 1.55rem;
    margin: 0;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.15;
    letter-spacing: -0.03em;
    font-variant-numeric: tabular-nums;
}
.stat-info p {
    font-size: 0.74rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #64748b;
    margin: 4px 0 0 0;
    letter-spacing: 0.035em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.stat-pill-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 9px;
    border-radius: 20px;
    font-size: 0.72rem;
    font-weight: 700;
    line-height: 1;
    letter-spacing: 0.01em;
    white-space: nowrap;
    border: 1px solid transparent;
}

/* Bottom Panels Styling */
.overview-panel-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.overview-panel-card:hover {
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);
}
.overview-panel-header {
    padding: 16px 20px;
    background: #ffffff;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.overview-panel-header h5 {
    font-size: 0.95rem;
    font-weight: 800;
    color: #1e293b;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.overview-panel-header .header-badge {
    font-size: 0.72rem;
    font-weight: 600;
    color: #64748b;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    padding: 3px 10px;
}

/* Today's Overview Grid Tiles */
.today-metric-tile {
    background: #f8fafc;
    border: 1px solid #edf2f7;
    border-radius: 12px;
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all 0.2s ease;
}
.today-metric-tile:hover {
    background: #ffffff;
    border-color: #cbd5e1;
    box-shadow: 0 4px 12px rgba(0,0,0,0.04);
    transform: translateY(-2px);
}
.today-metric-icon {
    width: 44px;
    height: 44px;
    min-width: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}
.today-metric-tile h4 {
    font-size: 1.45rem;
    font-weight: 800;
    color: #0f172a;
    margin: 0;
    line-height: 1.1;
}
.today-metric-tile p {
    font-size: 0.74rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 2px 0 0 0;
}

/* Distribution List Group */
.distribution-item-pill {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    border-radius: 12px;
    background: #f8fafc;
    border: 1px solid #edf2f7;
    transition: all 0.2s ease;
}
.distribution-item-pill:hover {
    background: #ffffff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.03);
    transform: translateX(3px);
}
.distribution-item-label {
    font-size: 0.86rem;
    font-weight: 700;
    color: #334155;
    display: flex;
    align-items: center;
    gap: 8px;
}
.distribution-item-badge {
    font-size: 0.88rem;
    font-weight: 800;
    border-radius: 20px;
    padding: 4px 12px;
}
</style>

<main class="main-content">
    <div class="container-fluid px-lg-4 px-md-3 px-2">
        
        <!-- =========================================================================
             HERO WELCOME BANNER (EXECUTIVE SUITE)
             ========================================================================= -->
        <div class="dashboard-hero-banner mb-4">
            <div class="row align-items-center g-3">
                <div class="col-lg-8 col-md-7 col-12">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <span class="hero-chip-badge live">
                            <span class="live-dot"></span> Live Operations
                        </span>
                        <span class="hero-chip-badge">
                            <i class="fas fa-code text-info me-1"></i> XML Production Hub
                        </span>
                        <span class="hero-chip-badge">
                            <i class="fas fa-calendar-alt text-warning me-1"></i> <?= date('F Y') ?>
                        </span>
                    </div>
                    <h2 class="hero-greeting fw-bold text-white mb-1">
                        Welcome back, <span class="hero-name-highlight"><?= htmlspecialchars($current_user_name ?: $ename) ?></span> 👋
                    </h2>
                    <p class="hero-subtitle text-white-50 mb-0">
                        Operational workload pipeline, real-time throughput metrics, and quality lifecycle monitoring.
                    </p>
                </div>
                <div class="col-lg-4 col-md-5 col-12 text-md-end text-start">
                    <div class="hero-quick-pills shadow-sm">
                        <div class="quick-pill-item">
                            <span class="quick-pill-label">Total Jobs</span>
                            <strong class="quick-pill-val"><?= number_format($total_jobs_count) ?></strong>
                        </div>
                        <div class="quick-pill-divider"></div>
                        <div class="quick-pill-item">
                            <span class="quick-pill-label">UQ Clients</span>
                            <strong class="quick-pill-val text-info"><?= number_format($active_client_count) ?></strong>
                        </div>
                        <div class="quick-pill-divider"></div>
                        <div class="quick-pill-item">
                            <span class="quick-pill-label">In Progress</span>
                            <strong class="quick-pill-val text-warning"><?= number_format($in_progress_count) ?></strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             SECTION 1: CLIENT OVERVIEW (3 CARDS)
             ========================================================================= -->
        <div class="dashboard-section-header">
            <div class="d-flex align-items-center gap-2">
                <div class="section-icon-bubble" style="background: rgba(79, 70, 229, 0.1); color: #4f46e5;">
                    <i class="fas fa-users"></i>
                </div>
                <div>
                    <h6 class="section-title-text mb-0">Client Overview</h6>
                    <span class="section-subtitle-text">Active UQ client accounts & operational portfolio</span>
                </div>
            </div>
            <span class="section-badge-pill">
                <i class="fas fa-building me-1 text-muted"></i> <?= number_format($total_client_count) ?> UQ Clients
            </span>
        </div>
        <div class="row mb-4 g-3">
            <!-- Total Clients -->
            <div class="col-md-4">
                <div class="stat-card compact-card" style="--card-accent: #4f46e5;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-top-row">
                            <h3><?= number_format($total_client_count) ?></h3>
                            <span class="stat-pill-badge" style="background: rgba(79, 70, 229, 0.1); color: #4f46e5; border-color: rgba(79, 70, 229, 0.2);">Total</span>
                        </div>
                        <p>Total Clients</p>
                    </div>
                </div>
            </div>
            <!-- Active Clients -->
            <div class="col-md-4">
                <div class="stat-card compact-card" style="--card-accent: #0284c7;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #0ea5e9 0%, #0369a1 100%);">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-top-row">
                            <h3 class="text-primary"><?= number_format($active_client_count) ?></h3>
                            <span class="stat-pill-badge" style="background: rgba(14, 165, 233, 0.1); color: #0284c7; border-color: rgba(14, 165, 233, 0.2);"><?= $active_client_per ?>%</span>
                        </div>
                        <p>Active Clients</p>
                    </div>
                </div>
            </div>
            <!-- Inactive Clients -->
            <div class="col-md-4">
                <div class="stat-card compact-card" style="--card-accent: #dc2626;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);">
                        <i class="fas fa-user-slash"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-top-row">
                            <h3 class="text-danger"><?= number_format($inactive_client_count) ?></h3>
                            <span class="stat-pill-badge" style="background: rgba(239, 68, 68, 0.1); color: #dc2626; border-color: rgba(239, 68, 68, 0.2);"><?= $inactive_client_per ?>%</span>
                        </div>
                        <p>Inactive Clients</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             SECTION 2: PRODUCTION OUTPUT (4 CARDS)
             ========================================================================= -->
        <div class="dashboard-section-header">
            <div class="d-flex align-items-center gap-2">
                <div class="section-icon-bubble" style="background: rgba(139, 92, 246, 0.1); color: #7c3aed;">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div>
                    <h6 class="section-title-text mb-0">Monthly Production Output</h6>
                    <span class="section-subtitle-text">Throughput volume & work breakdown for <?= date('F Y') ?></span>
                </div>
            </div>
            <span class="section-badge-pill">
                <i class="fas fa-calendar-check me-1 text-muted"></i> <?= date('F Y') ?>
            </span>
        </div>
        <div class="row mb-4 g-3">
            <!-- CE Words -->
            <div class="col-lg-3 col-md-6 col-12">
                <div class="stat-card compact-card" style="--card-accent: #7c3aed;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);">
                        <i class="fas fa-file-word"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-top-row">
                            <h3><?= number_format($monthly_ce_words) ?></h3>
                            <span class="stat-pill-badge" style="background: rgba(139, 92, 246, 0.1); color: #7c3aed; border-color: rgba(139, 92, 246, 0.2);">Words</span>
                        </div>
                        <p>CE Words</p>
                    </div>
                </div>
            </div>
            <!-- TS Pages -->
            <div class="col-lg-3 col-md-6 col-12">
                <div class="stat-card compact-card" style="--card-accent: #0891b2;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #06b6d4 0%, #0e7490 100%);">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-top-row">
                            <h3><?= number_format($monthly_pag_pages) ?></h3>
                            <span class="stat-pill-badge" style="background: rgba(6, 182, 212, 0.1); color: #0891b2; border-color: rgba(6, 182, 212, 0.2);">Pages</span>
                        </div>
                        <p>TS Pages</p>
                    </div>
                </div>
            </div>
            <!-- AP Articles -->
            <div class="col-lg-3 col-md-6 col-12">
                <div class="stat-card compact-card" style="--card-accent: #e11d48;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #f43f5e 0%, #be123c 100%);">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-top-row">
                            <h3><?= number_format($monthly_ap_articles) ?></h3>
                            <span class="stat-pill-badge" style="background: rgba(244, 63, 94, 0.1); color: #e11d48; border-color: rgba(244, 63, 94, 0.2);">Articles</span>
                        </div>
                        <p>AP Articles</p>
                    </div>
                </div>
            </div>
            <!-- XML Pages -->
            <div class="col-lg-3 col-md-6 col-12">
                <div class="stat-card compact-card" style="--card-accent: #c2410c;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #ea580c 0%, #9a3412 100%);">
                        <i class="fas fa-code"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-top-row">
                            <h3><?= number_format($monthly_xml_articles) ?></h3>
                            <span class="stat-pill-badge" style="background: rgba(234, 88, 12, 0.1); color: #c2410c; border-color: rgba(234, 88, 12, 0.2);">Pages</span>
                        </div>
                        <p>XML Pages</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             SECTION 3: JOB STATUS & LIFECYCLE (4 CARDS)
             ========================================================================= -->
        <div class="dashboard-section-header">
            <div class="d-flex align-items-center gap-2">
                <div class="section-icon-bubble" style="background: rgba(2, 132, 199, 0.1); color: #0284c7;">
                    <i class="fas fa-tasks"></i>
                </div>
                <div>
                    <h6 class="section-title-text mb-0">Job Status & Lifecycle</h6>
                    <span class="section-subtitle-text">Operational pipeline progression & assignment velocity</span>
                </div>
            </div>
            <span class="section-badge-pill">
                <i class="fas fa-layer-group me-1 text-muted"></i> Total: <?= number_format($total_jobs_count) ?>
            </span>
        </div>
        <div class="row mb-4 g-3">
            <!-- Total Jobs -->
            <div class="col-lg-3 col-md-6 col-12">
                <div class="stat-card compact-card" style="--card-accent: #0284c7;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);">
                        <i class="fas fa-list-check"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-top-row">
                            <h3><?= number_format($total_jobs_count) ?></h3>
                            <span class="stat-pill-badge" style="background: rgba(14, 165, 233, 0.1); color: #0284c7; border-color: rgba(14, 165, 233, 0.2);">100%</span>
                        </div>
                        <p>Total Jobs</p>
                    </div>
                </div>
            </div>
            <!-- Need to Assign -->
            <div class="col-lg-3 col-md-6 col-12">
                <div class="stat-card compact-card" style="--card-accent: #d97706;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b 0%, #b45309 100%);">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-top-row">
                            <h3 class="text-warning text-dark"><?= number_format($need_to_assign_count) ?></h3>
                            <span class="stat-pill-badge" style="background: rgba(245, 158, 11, 0.12); color: #b45309; border-color: rgba(245, 158, 11, 0.25);"><?= $nta_per ?>%</span>
                        </div>
                        <p>Need to Assign</p>
                    </div>
                </div>
            </div>
            <!-- In Progress -->
            <div class="col-lg-3 col-md-6 col-12">
                <div class="stat-card compact-card" style="--card-accent: #2563eb;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-top-row">
                            <h3 class="text-primary"><?= number_format($in_progress_count) ?></h3>
                            <span class="stat-pill-badge" style="background: rgba(37, 99, 235, 0.1); color: #2563eb; border-color: rgba(37, 99, 235, 0.2);"><?= $ip_per ?>%</span>
                        </div>
                        <p>In Progress</p>
                    </div>
                </div>
            </div>
            <!-- Completed -->
            <div class="col-lg-3 col-md-6 col-12">
                <div class="stat-card compact-card" style="--card-accent: #059669;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #10b981 0%, #047857 100%);">
                        <i class="fas fa-circle-check"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-top-row">
                            <h3 style="color: #059669;"><?= number_format($completed_count) ?></h3>
                            <span class="stat-pill-badge" style="background: rgba(16, 185, 129, 0.12); color: #047857; border-color: rgba(16, 185, 129, 0.25);"><?= $c_per ?>%</span>
                        </div>
                        <p>Completed</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             MAIN STATISTICS ROW (SIDE-BY-SIDE PANELS)
             ========================================================================= -->
        <div class="row g-4 mt-1">
            
            <!-- LEFT: Today's Overview (XML UQ) -->
            <div class="col-lg-6 col-12">
                <div class="overview-panel-card h-100">
                    <div class="overview-panel-header">
                        <h5>
                            <i class="fas fa-calendar-check text-primary"></i>
                            <span>Today's Overview (XML UQ)</span>
                        </h5>
                        <span class="header-badge">
                            <i class="fas fa-clock me-1 text-muted"></i> Daily Tally
                        </span>
                    </div>
                    <div class="p-4">
                        <div class="row g-3">
                            <div class="col-sm-4 col-12">
                                <div class="today-metric-tile">
                                    <div class="today-metric-icon bg-primary-subtle text-primary">
                                        <i class="fas fa-file-import"></i>
                                    </div>
                                    <div>
                                        <h4><?= number_format($today_uq_received_pages) ?></h4>
                                        <p>Received Pages</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-4 col-12">
                                <div class="today-metric-tile">
                                    <div class="today-metric-icon bg-success-subtle text-success">
                                        <i class="fas fa-file-export"></i>
                                    </div>
                                    <div>
                                        <h4><?= number_format($today_uq_uploaded_pages) ?></h4>
                                        <p>Uploaded Pages</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-4 col-12">
                                <div class="today-metric-tile">
                                    <div class="today-metric-icon bg-warning-subtle text-warning">
                                        <i class="fas fa-spinner fa-spin"></i>
                                    </div>
                                    <div>
                                        <h4><?= number_format($today_uq_processed_pages) ?></h4>
                                        <p>Processed Pages</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Job In-Progress Distribution -->
            <div class="col-lg-6 col-12">
                <div class="overview-panel-card h-100">
                    <div class="overview-panel-header">
                        <h5>
                            <i class="fas fa-list-alt text-info"></i>
                            <span>Job In-Progress Distribution</span>
                        </h5>
                        <span class="header-badge">
                            <i class="fas fa-arrows-split-up-and-left me-1 text-muted"></i> By Department
                        </span>
                    </div>
                    <div class="p-4">
                        <div class="d-flex flex-column gap-3">
                            <div class="distribution-item-pill">
                                <span class="distribution-item-label">
                                    <i class="fas fa-table text-info"></i> Number of Jobs Assigned to Tables
                                </span>
                                <span class="badge bg-info-subtle text-info border border-info-subtle distribution-item-badge">
                                    <?= (int)$jobs_with_tables_ip_count ?>
                                </span>
                            </div>
                            <div class="distribution-item-pill">
                                <span class="distribution-item-label">
                                    <i class="fas fa-image text-danger"></i> Number of Jobs Assigned to Images
                                </span>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle distribution-item-badge">
                                    <?= (int)$jobs_with_images_ip_count ?>
                                </span>
                            </div>
                            <div class="distribution-item-pill">
                                <span class="distribution-item-label">
                                    <i class="fas fa-circle-check text-success"></i> Completed Jobs
                                </span>
                                <span class="badge bg-success-subtle text-success border border-success-subtle distribution-item-badge">
                                    <?= (int)$completed_count ?>
                                </span>
                            </div>
                            <div class="distribution-item-pill">
                                <span class="distribution-item-label">
                                    <i class="fas fa-spinner fa-spin text-primary"></i> In-Process Jobs
                                </span>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle distribution-item-badge">
                                    <?= (int)$in_progress_count ?>
                                </span>
                            </div>
                            <div class="distribution-item-pill">
                                <span class="distribution-item-label">
                                    <i class="fas fa-clock text-warning"></i> Pending Jobs
                                </span>
                                <span class="badge bg-warning-subtle text-dark border border-warning-subtle distribution-item-badge">
                                    <?= (int)$need_to_assign_count ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</main>

<script>
    // Mobile menu toggle
    const menuToggle = document.createElement('div');
    menuToggle.className = 'menu-toggle';
    menuToggle.innerHTML = '<span></span><span></span><span></span>';
    
    const headerContainer = document.querySelector('.header-container');
    if (headerContainer) {
        headerContainer.insertBefore(menuToggle, headerContainer.firstChild);
        
        const mainNav = document.querySelector('.main-nav');
        if (mainNav) {
            menuToggle.addEventListener('click', () => {
                menuToggle.classList.toggle('active');
                mainNav.classList.toggle('active');
            });
            
            const navLinks = document.querySelectorAll('.nav-menu a');
            navLinks.forEach(link => {
                link.addEventListener('click', () => {
                    if (window.innerWidth <= 992) {
                        menuToggle.classList.remove('active');
                        mainNav.classList.remove('active');
                    }
                });
            });
        }
    }
</script>

</body>
</html>
