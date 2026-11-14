<?php
// -------------------------------------------------------------
// FILE: employee_master.php
// PURPOSE: Employee Management Enterprise Hub & Navigation Center
// -------------------------------------------------------------

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Connect to Database
$db_paths = ['db.php', '../db.php'];
$db_connected = false;
foreach ($db_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $db_connected = true;
        break;
    }
}
if (!$db_connected) {
    die("<div style='margin-top:100px; color:red; text-align:center;'>CRITICAL ERROR: db.php not found.</div>");
}

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Fetch Live Metrics for the Hub
$kpi_query = $mysqli->query("SELECT 
    COUNT(*) as total,
    SUM(status = 'Active') as active_count,
    SUM(salary) as total_payroll,
    SUM(aadhar_doc IS NOT NULL AND aadhar_doc != '') as aadhar_count,
    COUNT(DISTINCT team_id) as total_teams
    FROM employee_master");
$kpi = $kpi_query ? $kpi_query->fetch_assoc() : [
    'total' => 0, 'active_count' => 0, 'total_payroll' => 0,
    'aadhar_count' => 0, 'total_teams' => 0
];

$pageTitle = "Employee Hub";
$currentPage = "employee_master.php";

$header_paths = ['header.php', '../header.php'];
foreach ($header_paths as $path) {
    if (file_exists($path)) {
        include_once $path;
        break;
    }
}
?>

<style>
    .hub-container {
        margin-top: 95px;
        margin-bottom: 60px;
        position: relative;
        z-index: 1;
    }
    .hero-card {
        background: #ffffff;
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
        border: 1px solid #edf2f7;
        overflow: hidden;
    }
    .hero-banner {
        background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
        color: #ffffff;
        padding: 40px 45px;
        position: relative;
    }
    .hero-banner::after {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        bottom: 0;
        width: 35%;
        background: radial-gradient(circle at top right, rgba(59, 130, 246, 0.15), transparent 70%);
        pointer-events: none;
    }
    .nav-card {
        border-radius: 16px;
        border: 1px solid #f1f5f9;
        background: #ffffff;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        height: 100%;
        position: relative;
        overflow: hidden;
    }
    .nav-card:hover {
        transform: translateY(-6px);
        box-shadow: 0 16px 32px rgba(0, 0, 0, 0.08);
        border-color: #cbd5e1;
    }
    .nav-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        transition: opacity 0.3s ease;
    }
    .card-general::before { background: linear-gradient(90deg, #38bdf8, #0284c7); }
    .card-official::before { background: linear-gradient(90deg, #4ade80, #16a34a); }
    .card-salary::before { background: linear-gradient(90deg, #fbbf24, #d97706); }

    .icon-wrapper-hub {
        width: 68px;
        height: 68px;
        border-radius: 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        margin-bottom: 20px;
        transition: transform 0.3s ease;
    }
    .nav-card:hover .icon-wrapper-hub {
        transform: scale(1.1);
    }
    .hub-metric-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 30px;
        font-size: 0.8rem;
        font-weight: 600;
        background: rgba(255, 255, 255, 0.12);
        color: #f8fafc;
        border: 1px solid rgba(255, 255, 255, 0.18);
    }
    .feature-list {
        list-style: none;
        padding: 0;
        margin: 0 0 24px 0;
        text-align: left;
    }
    .feature-list li {
        font-size: 0.84rem;
        color: #64748b;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .feature-list li i {
        font-size: 0.72rem;
    }
</style>

<div class="container hub-container">
    <div class="hero-card">
        
        <!-- Enterprise Hero Banner -->
        <div class="hero-banner">
            <div class="row align-items-center gy-3">
                <div class="col-lg-7 col-12">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <span class="hub-metric-badge">
                            <i class="fas fa-layer-group text-info"></i> Enterprise Directory
                        </span>
                        <span class="hub-metric-badge">
                            <i class="fas fa-circle-check text-success"></i> System v2.4
                        </span>
                    </div>
                    <h2 class="fw-bold mb-2 text-white" style="font-size: 2rem; letter-spacing: -0.5px;">
                        Employee Management Hub
                    </h2>
                    <p class="text-white-50 mb-0" style="font-size: 0.95rem; max-width: 580px;">
                        Centralized administrative workspace for workforce records, official employment contracts, compensation governance, and compliance.
                    </p>
                </div>
                <div class="col-lg-5 col-12 text-lg-end text-start">
                    <div class="d-inline-flex flex-wrap gap-3 justify-content-lg-end">
                        <div class="bg-white bg-opacity-10 rounded-3 p-3 text-start border border-white border-opacity-10" style="min-width: 140px;">
                            <div class="text-white-50 small text-uppercase fw-semibold" style="font-size: 0.7rem;">Active Workforce</div>
                            <div class="fs-4 fw-bold text-white mt-1"><?= (int)$kpi['active_count'] ?> <span class="fs-6 fw-normal text-white-50">/ <?= (int)$kpi['total'] ?></span></div>
                        </div>
                        <div class="bg-white bg-opacity-10 rounded-3 p-3 text-start border border-white border-opacity-10" style="min-width: 150px;">
                            <div class="text-white-50 small text-uppercase fw-semibold" style="font-size: 0.7rem;">Monthly Payroll</div>
                            <div class="fs-4 fw-bold text-success mt-1">&#8377;<?= number_format((float)$kpi['total_payroll'], 2) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Portal Navigation Cards Grid -->
        <div class="p-4 p-md-5 bg-light bg-opacity-50">
            <div class="text-center mb-5">
                <h4 class="fw-bold text-dark mb-1">Select a Functional Module</h4>
                <p class="text-muted small mb-0">Navigate to specialized dashboards to view or update workforce records</p>
            </div>

            <div class="row g-4 justify-content-center">
                <!-- 1. General Information Card -->
                <div class="col-lg-4 col-md-6 col-12">
                    <div class="nav-card card-general p-4 text-center">
                        <div>
                            <div class="icon-wrapper-hub" style="background: rgba(56, 189, 248, 0.12); color: #0284c7;">
                                <i class="fas fa-id-card"></i>
                            </div>
                            <h4 class="fw-bold text-dark mb-2">General Information</h4>
                            <p class="text-muted small mb-3">
                                Personal records, contact directories, identity documentation, and addresses.
                            </p>
                            <ul class="feature-list">
                                <li><i class="fas fa-check text-info"></i> Full Personal Profiles & Age Computation</li>
                                <li><i class="fas fa-check text-info"></i> Aadhar Documentation & Identity Verification</li>
                                <li><i class="fas fa-check text-info"></i> Contact Directory & Address Records</li>
                            </ul>
                        </div>
                        <div>
                            <a href="employee_general_info.php" class="btn btn-info text-white w-100 py-2 rounded-pill fw-semibold shadow-sm d-flex align-items-center justify-content-center gap-2">
                                <span>Access Directory</span>
                                <i class="fas fa-arrow-right fs-7"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- 2. Official Information Card -->
                <div class="col-lg-4 col-md-6 col-12">
                    <div class="nav-card card-official p-4 text-center">
                        <div>
                            <div class="icon-wrapper-hub" style="background: rgba(74, 222, 128, 0.12); color: #16a34a;">
                                <i class="fas fa-briefcase"></i>
                            </div>
                            <h4 class="fw-bold text-dark mb-2">Official Information</h4>
                            <p class="text-muted small mb-3">
                                Employment agreements, banking parameters, PAN records, and statutory compliance.
                            </p>
                            <ul class="feature-list">
                                <li><i class="fas fa-check text-success"></i> Employment Dates & Work Shifts</li>
                                <li><i class="fas fa-check text-success"></i> Bank Accounts, IFSC & PAN Compliance</li>
                                <li><i class="fas fa-check text-success"></i> UAN Numbers & TDS Withholdings</li>
                            </ul>
                        </div>
                        <div>
                            <a href="employee_official_info.php" class="btn btn-success text-white w-100 py-2 rounded-pill fw-semibold shadow-sm d-flex align-items-center justify-content-center gap-2">
                                <span>Access Official</span>
                                <i class="fas fa-arrow-right fs-7"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- 3. Compensation & Hierarchy Card -->
                <div class="col-lg-4 col-md-6 col-12">
                    <div class="nav-card card-salary p-4 text-center">
                        <div>
                            <div class="icon-wrapper-hub" style="background: rgba(251, 191, 36, 0.15); color: #d97706;">
                                <i class="fas fa-users-gear"></i>
                            </div>
                            <h4 class="fw-bold text-dark mb-2">Employee & Salary Master</h4>
                            <p class="text-muted small mb-3">
                                Manage organizational hierarchy, role assignments, compensation, and shifts.
                            </p>
                            <ul class="feature-list">
                                <li><i class="fas fa-check text-warning"></i> Team, Designation & Role Assignments</li>
                                <li><i class="fas fa-check text-warning"></i> Reporting Lines & Operational Shifts</li>
                                <li><i class="fas fa-check text-warning"></i> In-Page Modal Updates & CSV Export</li>
                            </ul>
                        </div>
                        <div>
                            <a href="employee_master-old.php" class="btn btn-warning text-dark w-100 py-2 rounded-pill fw-semibold shadow-sm d-flex align-items-center justify-content-center gap-2">
                                <span>Manage Directory</span>
                                <i class="fas fa-arrow-right fs-7"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Quick Info -->
            <div class="row mt-5">
                <div class="col-12">
                    <div class="p-3 rounded-3 bg-white border d-flex flex-wrap align-items-center justify-content-between gap-3 shadow-sm">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-light p-2 text-primary">
                                <i class="fas fa-shield-halved fs-5"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-semibold text-dark">Data Security & Compliance</h6>
                                <p class="text-muted small mb-0">All employee modifications are tracked with strict authorization controls.</p>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <a href="employee_master-old.php?export=true" class="btn btn-sm btn-outline-secondary px-3 py-2 rounded-pill fw-medium">
                                <i class="fas fa-download me-1"></i> Export Master CSV
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
