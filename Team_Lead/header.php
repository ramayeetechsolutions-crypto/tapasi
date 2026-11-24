<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent caching issues
if (!headers_sent()) {
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
}

// Redirect to login if not logged in
if (!isset($_SESSION['teamname'])) {
    header("Location: ../index.php");
    exit;
}

// Detect current page for menu highlighting
$currentPage = basename($_SERVER['PHP_SELF']);
// Release session lock early to prevent session file blocking across requests/tabs
session_write_close();
$username    = htmlspecialchars($_SESSION['username'] ?? 'User');
$employee_id = $_SESSION['uid'] ?? 0; // set at login

require_once 'db.php'; // Ensure db connection is available

// Step 1: Fetch employee process IDs
$sql = "SELECT * FROM employee_master WHERE employee_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();

$tl = $team =false;

if ($row = $result->fetch_assoc()) {
    $tl=$row['user_role_id'];
    $team=$row['team_id'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tapasi Solutions LLP</title>
   <link rel="icon" type="image/png" href="slogo.png">
<link rel="shortcut icon" type="image/png" href="slogo.png">
<link rel="apple-touch-icon" href="slogo.png">
<meta name="theme-color" content="#ffffff">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        :root {
            --primary: #3498db;
            --primary-dark: #2980b9;
            --secondary: #2c3e50;
            --light: #f5f7fa;
            --dark: #34495e;
            --success: #2ecc71;
            --warning: #f39c12;
            --danger: #e74c3c;
            --gray: #95a5a6;
            --white: #ffffff;
            --ash: #6c757d;
            --ash-light: #8c959d;
            --ash-dark: #5a6268;
        }

        body {
            background-color: var(--light);
            color: var(--secondary);
            line-height: 1.6;
        }

        /* Header Styles */
        header {
            background-color: var(--ash);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 5%;
            height: 70px;
        }

        /* Mobile Menu Toggle */
        .menu-toggle {
            display: none;
            flex-direction: column;
            cursor: pointer;
            width: 30px;
            height: 25px;
            justify-content: space-between;
            margin-right: 20px;
        }

        .menu-toggle span {
            height: 3px;
            width: 100%;
            background-color: var(--white);
            border-radius: 2px;
            transition: all 0.3s ease;
        }

        /* Navigation Menu - Left Side */
        .main-nav {
            display: flex;
            align-items: center;
        }

        .nav-menu {
            display: flex;
            list-style: none;
            transition: all 0.3s ease;
        }

        .nav-menu li {
            position: relative;
        }

        .nav-menu a {
            text-decoration: none;
            color: var(--white);
            font-weight: 500;
            font-size: 14px;
            transition: all 0.3s ease;
            position: relative;
            padding: 23px 15px;
            display: flex;
            align-items: center;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
        }

        .nav-menu a i {
            margin-right: 8px;
            font-size: 14px;
        }

        .nav-menu a:hover {
            background-color: var(--ash-dark);
            border-bottom-color: var(--white);
        }

        .nav-menu a.active {
            background-color: var(--ash-dark);
            border-bottom-color: var(--primary);
        }

        /* Dropdown Menus */
        .nav-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            background-color: var(--white);
            min-width: 200px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1001;
            list-style: none;
            padding: 0;
        }

        .nav-menu li:hover .nav-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .nav-dropdown li {
            margin: 0;
        }

        .nav-dropdown a {
            color: var(--dark);
            padding: 12px 20px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
            display: flex;
            align-items: center;
        }

        .nav-dropdown a:last-child {
            border-bottom: none;
        }

        .nav-dropdown a:hover {
            background-color: #f8f9fa;
            color: var(--primary);
            border-bottom-color: #f0f0f0;
        }

        /* Header Right */
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logo-img {
            height: 40px;
            width: auto;
        }

        /* User Menu Dropdown */
        .user-menu {
            position: relative;
        }

        .user-profile {
            display: flex;
            align-items: center;
            cursor: pointer;
            padding: 10px 15px;
            border-radius: 4px;
            transition: background-color 0.3s ease;
        }

        .user-profile:hover {
            background-color: var(--ash-dark);
        }

        .user-name {
            font-weight: 500;
            color: var(--white);
            margin-right: 8px;
            font-size: 14px;
        }

        .user-profile i {
            color: var(--white);
            font-size: 12px;
            transition: transform 0.3s ease;
        }

        .user-profile.active i {
            transform: rotate(180deg);
        }

        .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            background-color: var(--white);
            min-width: 180px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1001;
            list-style: none;
            padding: 0;
        }

        .dropdown-menu.active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-menu li {
            margin: 0;
        }

        .dropdown-menu a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: var(--dark);
            text-decoration: none;
            transition: all 0.3s ease;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
        }

        .dropdown-menu a:last-child {
            border-bottom: none;
        }

        .dropdown-menu a:hover {
            background-color: #f8f9fa;
            color: var(--primary);
        }

        .dropdown-menu a i {
            margin-right: 10px;
            width: 16px;
            text-align: center;
            font-size: 12px;
        }

        /* Main Content */
        .main-content {
            margin-top: 0;
            padding: 15px 5%;
            transition: all 0.3s ease;
        }

        .page-title {
            font-size: 28px;
            margin-bottom: 10px;
            color: var(--secondary);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .page-title .logo-img {
            height: 35px;
        }

        .page-title i {
            margin-right: 10px;
            color: var(--primary);
        }

        .page-description {
            color: var(--gray);
            margin-bottom: 30px;
        }

        /* Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background-color: var(--white);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 24px;
        }

        .stat-icon.leads {
            background-color: rgba(52, 152, 219, 0.1);
            color: var(--primary);
        }

        .stat-icon.customers {
            background-color: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }

        .stat-icon.deals {
            background-color: rgba(155, 89, 182, 0.1);
            color: #9b59b6;
        }

        .stat-icon.revenue {
            background-color: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        .stat-info h3 {
            font-size: 24px;
            margin-bottom: 5px;
        }

        .stat-info p {
            color: var(--gray);
            font-size: 14px;
        }

        /* Content Sections */
        .content-section {
            background-color: var(--white);
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 20px;
            color: var(--secondary);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
        }

        .btn i {
            margin-right: 8px;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background-color: #f8f9fa;
        }

        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 1px solid #e0e0e0;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        tbody tr {
            transition: background-color 0.2s ease;
        }

        tbody tr:hover {
            background-color: #f8f9fa;
        }

        .status {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        .status-active {
            background-color: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }

        .status-pending {
            background-color: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        .status-closed {
            background-color: rgba(149, 165, 166, 0.1);
            color: var(--gray);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .action-edit {
            background-color: rgba(52, 152, 219, 0.1);
            color: var(--primary);
        }

        .action-edit:hover {
            background-color: var(--primary);
            color: var(--white);
        }

        .action-delete {
            background-color: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }

        .action-delete:hover {
            background-color: var(--danger);
            color: var(--white);
        }

        /* Job Master Specific Styles */
        .job-master-container {
            max-width: 100%;
            margin: 0 auto;
        }

        .job-form-container {
            max-width: 500px;
            margin: 0 auto;
        }

        .btn-brown {
            background-color: brown;
            color: white;
            border: none;
        }

        .btn-brown:hover {
            background-color: #8B4513;
            color: white;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .nav-menu a {
                padding: 23px 12px;
                font-size: 13px;
            }
            
            .nav-menu a i {
                margin-right: 6px;
                font-size: 13px;
            }
        }

        @media (max-width: 992px) {
            .menu-toggle {
                display: flex;
            }
            
            .main-nav {
                position: fixed;
                top: 70px;
                left: 0;
                width: 100%;
                background-color: var(--ash-dark);
                transform: translateY(-100%);
                opacity: 0;
                visibility: hidden;
                transition: all 0.3s ease;
                z-index: 999;
                flex-direction: column;
                align-items: flex-start;
                padding: 0;
            }
            
            .main-nav.active {
                transform: translateY(0);
                opacity: 1;
                visibility: visible;
            }
            
            .nav-menu {
                flex-direction: column;
                width: 100%;
                padding: 0;
            }
            
            .nav-menu li {
                width: 100%;
            }
            
            .nav-menu a {
                padding: 15px 20px;
                border-bottom: none;
                border-left: 3px solid transparent;
                width: 100%;
                font-size: 14px;
            }
            
            .nav-menu a.active {
                border-left-color: var(--primary);
                border-bottom: none;
            }

            .nav-dropdown {
                position: static;
                box-shadow: none;
                background-color: var(--ash);
                border-radius: 0;
                opacity: 1;
                visibility: visible;
                transform: none;
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.3s ease;
            }

            .nav-menu li:hover .nav-dropdown,
            .nav-menu li.active .nav-dropdown {
                max-height: 500px;
            }

            .nav-dropdown a {
                padding-left: 40px;
                color: var(--white);
                border-bottom-color: var(--ash-light);
            }

            .menu-toggle.active span:nth-child(1) {
                transform: rotate(45deg) translate(6px, 6px);
            }
            
            .menu-toggle.active span:nth-child(2) {
                opacity: 0;
            }
            
            .menu-toggle.active span:nth-child(3) {
                transform: rotate(-45deg) translate(6px, -6px);
            }
        }

        @media (max-width: 768px) {
            .header-container {
                padding: 0 4%;
            }
            
            .header-right {
                gap: 15px;
            }
            
            .logo-img {
                height: 35px;
            }
            
            .user-name {
                display: none;
            }
            
            .user-profile {
                padding: 8px 12px;
            }
            
            .main-content {
                padding: 20px 4%;
            }
            
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
        }

        @media (max-width: 576px) {
            .header-container {
                padding: 0 3%;
            }
            
            .logo-img {
                height: 30px;
            }
            
            .user-profile i {
                display: none;
            }
            
            .main-content {
                padding: 15px 3%;
            }
            
            .content-section {
                padding: 15px;
            }
            
            th, td {
                padding: 10px;
            }
        }

        @media (max-width: 400px) {
            .header-container {
                padding: 0 2%;
            }
            
            .logo-img {
                height: 25px;
            }
            
            .main-content {
                padding: 10px 2%;
            }
        }
    </style>
    <!-- Tapasi Global Typography & Font Stylesheet -->
    <link rel="stylesheet" href="../custom_style.css?v=<?= file_exists(__DIR__ . '/../custom_style.css') ? filemtime(__DIR__ . '/../custom_style.css') : 1 ?>">
</head>
<body>
    <header>
        <div class="header-container">
            <div class="menu-toggle" id="menuToggle">
                <span></span>
                <span></span>
                <span></span>
            </div>
            
            <!-- Left Side Navigation -->
            <nav class="main-nav" id="mainNav">
                <?php
                $masterPages = ['job_master.php', 'process_master.php', 'team_view.php'];
                $myAccountPages = ['employee_regby_emp.php', 'employee.php', 'employee_leave.php'];
                $employeePages = ['employee_master.php', 'target_assignment.php', 'teamleader_ot_approval.php'];
                $systemPages = ['my_system_details.php', 'ticketmanagementexecutive.php'];
                $reportPages = ['client_report.php', 'team_count_report.php', 'CE_report.php', 'error_entry_team.php', 'error_view_sales.php'];
                $jobsPages = ['job_management.php', 'tm_queries.php'];
                $xmlPages = ['template_master.php', 'specialty.php', 'xml_clients.php', 'pubcode.php', 'journal_master.php', 'xml_unique_start.php', 'unassigned_xml_jobs.php', 'alljobs_xml.php'];
                ?>
                <ul class="nav-menu">
                    <li><a href="dashboard_tl.php" class="<?php echo in_array($currentPage, ['dashboard', 'dashboard.php', 'dashboard_tl.php']) ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    
                    <!-- Master Main Menu -->
                     <!--li>
                        <a href="#"><i class="fas fa-star"></i> Team Lead</a>
                        <ul class="nav-dropdown">
                            <!--li><a href="change_password.php"><i class="fas fa-list"></i> Change Password</a></li>
                            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                            
                        </ul>
                    </li-->
                <!-- Master -->
                  <li>
                    <a href="#" class="<?= in_array($currentPage, $masterPages) ? 'active' : '' ?>"><i class="fas fa-sitemap"></i> Master</a>
                    <ul class="nav-dropdown">
                        <li><a href="job_master.php" class="<?= $currentPage === 'job_master.php' ? 'active' : '' ?>"><i class="fas fa-briefcase"></i> Job</a></li>
                        <li><a href="process_master.php" class="<?= $currentPage === 'process_master.php' ? 'active' : '' ?>"><i class="fas fa-diagram-project"></i> Process</a></li>
                        <li><a href="team_view.php" class="<?= $currentPage === 'team_view.php' ? 'active' : '' ?>"><i class="fas fa-users"></i> Team</a></li>
                    </ul>
                </li>
                <!-- My Account -->
<li>
    <a href="#" class="<?= in_array($currentPage, $myAccountPages) ? 'active' : '' ?>"><i class="fas fa-briefcase"></i> My Account</a>
    <ul class="nav-dropdown">
        <li><a href="employee_regby_emp.php" class="<?= $currentPage === 'employee_regby_emp.php' ? 'active' : '' ?>"><i class="fas fa-users"></i> Update</a></li>
        <li><a href="employee.php" class="<?= $currentPage === 'employee.php' ? 'active' : '' ?>"><i class="fas fa-tasks"></i> Verify</a></li>
        <li><a href="employee_leave.php" class="<?= $currentPage === 'employee_leave.php' ? 'active' : '' ?>"><i class="fas fa-tasks"></i> Permission/OT</a></li>
         </ul>
</li>
<!-- Employee -->
<li>
    <a href="#" class="<?= in_array($currentPage, $employeePages) ? 'active' : '' ?>"><i class="fas fa-user-tie"></i> Employee</a>
    <ul class="nav-dropdown">
        <li><a href="employee_master.php" class="<?= $currentPage === 'employee_master.php' ? 'active' : '' ?>"><i class="fas fa-id-card"></i> Profile</a></li>
        <li><a href="target_assignment.php" class="<?= $currentPage === 'target_assignment.php' ? 'active' : '' ?>"><i class="fas fa-bullseye"></i> Target</a></li>
         <li><a href="teamleader_ot_approval.php" class="<?= $currentPage === 'teamleader_ot_approval.php' ? 'active' : '' ?>"><i class="fas fa-bullseye"></i> OT Approvals</a></li>
    </ul>
</li>
<!-- System -->
<li>
    <a href="#" class="<?= in_array($currentPage, $systemPages) ? 'active' : '' ?>"><i class="fas fa-tools"></i> System</a>
    <ul class="nav-dropdown">
        <li>
            <a href="my_system_details.php" class="<?= $currentPage === 'my_system_details.php' ? 'active' : '' ?>">
                <i class="fas fa-desktop"></i>System Details
            </a>
        </li>
        <li><a href="ticketmanagementexecutive.php" class="<?= $currentPage === 'ticketmanagementexecutive.php' ? 'active' : '' ?>"><i class="fas fa-headset"></i> Complaints</a></li>
    </ul>
    
</li>
<!-- Reports -->
<li>
    <a href="#" class="<?= in_array($currentPage, $reportPages) ? 'active' : '' ?>"><i class="fas fa-file-invoice"></i> Reports</a>
    <ul class="nav-dropdown">
    <li>
        <a href="client_report.php" class="<?= $currentPage === 'client_report.php' ? 'active' : '' ?>"><i class="fas fa-chart-line"></i> Client Report</a></li>
         <li><a href="team_count_report.php" class="<?= $currentPage === 'team_count_report.php' ? 'active' : '' ?>"><i class="fas fa-diagram-project"></i> Team Report</a></li>
             <li class="nav-item">
        <a class="nav-link <?= $currentPage === 'CE_report.php' ? 'active' : '' ?>" href="CE_report.php">
            <i class="fa fa-line-chart"></i> Reports
        </a>
    </li>

         <li class="nav-item">
            <a class="nav-link <?= $currentPage === 'error_entry_team.php' ? 'active' : '' ?>" href="error_entry_team.php">
                <i class="fa fa-calculator"></i> Error Log (Team)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $currentPage === 'error_view_sales.php' ? 'active' : '' ?>" href="error_view_sales.php">
                <i class="fa fa-calculator"></i> Error Log (Sales)
            </a>
        </li>
    </ul>
</li>

<!-- Job Management (UQ Team Only - other teams show till Reports) -->
<?php if ($team == 2): ?>
<li>
    <a href="#" class="<?= in_array($currentPage, $jobsPages) ? 'active' : '' ?>"><i class="fas fa-briefcase"></i> Jobs</a>
    <ul class="nav-dropdown">
        <li><a href="job_management.php" class="<?= $currentPage === 'job_management.php' ? 'active' : '' ?>"><i class="fas fa-chart-pie"></i> Input & Output</a></li>
        <li><a href="tm_queries.php" class="<?= $currentPage === 'tm_queries.php' ? 'active' : '' ?>"><i class="fas fa-question-circle"></i> Queries</a></li>
    </ul>
</li>
<?php include __DIR__ . '/../uq_tl_nav.php'; ?>
<?php endif; ?>
<?php if (($tl==3) and ($team==3)): ?>
<li>
<a href="#" class="<?= in_array($currentPage, $xmlPages) ? 'active' : '' ?>"><i class="fas fa-cogs"></i> XML Jobs</a>
    <ul class="nav-dropdown">
    
<li class="nav-item">
       <li class="nav-item">
        <a class="nav-link <?= $currentPage === 'template_master.php' ? 'active' : '' ?>" href="template_master.php">
            <i class="fa fa-line-chart"></i> Template Master
        </a>
    </li> 
    <li class="nav-item">
        <a class="nav-link <?= $currentPage === 'specialty.php' ? 'active' : '' ?>" href="specialty.php">
            <i class="fa fa-line-chart"></i> Speciality Master
        </a>
    </li>
        <a class="nav-link <?= $currentPage === 'xml_clients.php' ? 'active' : '' ?>" href="xml_clients.php">
            <i class="fa fa-line-chart"></i> XML Clients
        </a>
    </li>
     <li class="nav-item">
        <a class="nav-link <?= $currentPage === 'pubcode.php' ? 'active' : '' ?>" href="pubcode.php">
            <i class="fa fa-line-chart"></i> Pub Code
        </a>
    </li>
     <li class="nav-item">
        <a class="nav-link <?= $currentPage === 'journal_master.php' ? 'active' : '' ?>" href="journal_master.php">
            <i class="fa fa-line-chart"></i> New Journals
        </a>
    </li>
     <li class="nav-item">
        <a class="nav-link <?= $currentPage === 'xml_unique_start.php' ? 'active' : '' ?>" href="xml_unique_start.php">
            <i class="fa fa-line-chart"></i> Unique XML Jobs
        </a>
    </li>
     <li class="nav-item">
        <a class="nav-link <?= $currentPage === 'unassigned_xml_jobs.php' ? 'active' : '' ?>" href="unassigned_xml_jobs.php">
            <i class="fa fa-line-chart"></i> XML Jobs
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $currentPage === 'alljobs_xml.php' ? 'active' : '' ?>" href="alljobs_xml.php">
            <i class="fa fa-line-chart"></i> All XML Jobs
        </a>
    </li>
     </ul>
     </li>
<?php endif; ?>

  
                    <!-- Invoice Management -->
                    <!--li>
                        <a href="#"><i class="fas fa-file-invoice"></i> Invoice</a>
                        <ul class="nav-dropdown">
                            <li><a href="gen_invoice.php"><i class="fas fa-file-invoice-dollar"></i> Generate Invoice</a></li>

<li><a href="gen_invoice1.php"><i class="fas fa-file-invoice"></i> Generate Invoice I/O</a></li>

                            <!--li><a href="list_invoice.php"><i class="fas fa-receipt"></i> Invoices</a></li-->
                            <!--li><a href="#"><i class="fas fa-file-alt"></i> Invoice Templates</a></li>
                        </ul>
                    </li-->
                    
                    <!--li><a href="reports.php" class="<?php echo ($currentPage == 'reports') ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i> Reports</a></li-->
                </ul>
            </nav>
            
            <!-- Right Side - User Menu and Logo -->
             <div class="header-right">
                   <ul class="nav-menu">
                    
                     <li>
                        <a href="#"><i class="fas fa-star"></i> TL- <?php echo $_SESSION["username"]?></a>
                        <ul class="nav-dropdown">
                            <?php if ($_SESSION["username"]=='TPS002') {?>
                            <li><a href="switch_tm.php"><i class="fas fa-list"></i> Switch to TL</a></li>
                            <?php }?>
                             <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                            
                        </ul>
                    </li>              
                    
                    </ul>
                      <img src="tlogo.jpg" alt="Company Logo" class="logo-img" />
                </div>
   
            </div>
        </div>
    </header>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile menu toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('menuToggle');
            const mainNav = document.getElementById('mainNav');
            const userProfile = document.getElementById('userProfile');
            const dropdownMenu = document.getElementById('dropdownMenu');
            
            // Mobile navigation toggle
            if (menuToggle && mainNav) {
                menuToggle.addEventListener('click', function() {
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
                
                // Close menu when clicking outside (for mobile)
                document.addEventListener('click', function(event) {
                    if (window.innerWidth <= 992) {
                        const isClickInsideNav = mainNav.contains(event.target);
                        const isClickOnToggle = menuToggle.contains(event.target);
                        
                        if (!isClickInsideNav && !isClickOnToggle && mainNav.classList.contains('active')) {
                            menuToggle.classList.remove('active');
                            mainNav.classList.remove('active');
                        }
                    }
                });
            }
            
            // User dropdown toggle
            if (userProfile && dropdownMenu) {
                userProfile.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userProfile.classList.toggle('active');
                    dropdownMenu.classList.toggle('active');
                });
                
                // Close dropdown when clicking outside
                document.addEventListener('click', function() {
                    userProfile.classList.remove('active');
                    dropdownMenu.classList.remove('active');
                });
            }
            
            // Handle window resize
            window.addEventListener('resize', function() {
                if (window.innerWidth > 992) {
                    menuToggle.classList.remove('active');
                    mainNav.classList.remove('active');
                }
            });
        });

        // Sticky Columns Support for TL/SubTL/Executive Assign & Report Pages (From S.No until Journal field, except WK Report)
        function applyStickyAssignColumns() {
            if (window.location.pathname.indexOf('wk_report') !== -1) return;

            var isAssignPage = window.location.pathname.indexOf('assign') !== -1 || window.location.pathname.indexOf('alljobs') !== -1;
            var isReportPage = window.location.pathname.indexOf('jobmgmt_') !== -1 || window.location.pathname.indexOf('report') !== -1;
            var tables = document.querySelectorAll('.table-sticky-assign' + (isAssignPage || isReportPage ? ', .table-responsive table, .table-container table' : ''));
            if (!tables || tables.length === 0) return;

            var processedTables = new Set();

            tables.forEach(function(table) {
                if (processedTables.has(table)) return;
                processedTables.add(table);

                var thead = table.querySelector('thead');
                if (!thead) return;
                var headerTr = thead.querySelector('tr');
                if (!headerTr) return;
                var ths = Array.from(headerTr.children);
                if (ths.length === 0) return;

                // 1. Locate the Journal column index (Freeze until Journal field from S.No)
                var journalIndex = -1;
                for (var i = 0; i < ths.length; i++) {
                    var text = ths[i].textContent.trim().toUpperCase();
                    if (text === 'JOURNAL' || text.indexOf('JOURNAL') !== -1) {
                        journalIndex = i;
                        break;
                    }
                }
                if (journalIndex === -1 && !table.classList.contains('table-sticky-assign')) {
                    return;
                }
                if (journalIndex === -1) {
                    // Fallback: If no Journal column, try Client or default to 4 (S.No to Client/Journal)
                    for (var i = 0; i < ths.length; i++) {
                        var text = ths[i].textContent.trim().toUpperCase();
                        if (text === 'CLIENT' || text.indexOf('CLIENT') !== -1) {
                            journalIndex = i;
                            break;
                        }
                    }
                }
                if (journalIndex === -1) {
                    journalIndex = Math.min(4, ths.length - 1);
                }

                var freezeIndex = journalIndex;
                table.classList.add('table-sticky-assign');

                // Ensure thead and tbody have proper stacking contexts so headers stay on top when scrolling vertically
                if (thead) {
                    thead.style.setProperty('position', 'sticky', 'important');
                    thead.style.setProperty('top', '0', 'important');
                    thead.style.setProperty('z-index', '150', 'important');
                }
                var tbody = table.querySelector('tbody');
                if (tbody) {
                    tbody.style.setProperty('position', 'relative', 'important');
                    tbody.style.setProperty('z-index', '1', 'important');
                }

                // If Articles column exists in the table, keep standard 135px width formatting
                for (var i = 0; i < ths.length; i++) {
                    var text = ths[i].textContent.trim().toUpperCase();
                    if ((text === 'ARTICLES' || text === 'ARTICLE') || (text.indexOf('ARTICLE') !== -1 && text.indexOf('COUNT') === -1)) {
                        ths[i].classList.add('col-article');
                        ths[i].style.setProperty('width', '135px', 'important');
                        ths[i].style.setProperty('min-width', '135px', 'important');
                        ths[i].style.setProperty('max-width', '135px', 'important');
                        
                        var tbodyRows = table.querySelectorAll('tbody tr');
                        tbodyRows.forEach(function(row) {
                            if (row.children[i]) {
                                var c = row.children[i];
                                c.classList.add('col-article');
                                c.style.setProperty('width', '135px', 'important');
                                c.style.setProperty('min-width', '135px', 'important');
                                c.style.setProperty('max-width', '135px', 'important');
                            }
                        });
                        break;
                    }
                }

                // 2. Measure cumulative left offsets
                var leftOffsets = [];
                var acc = 0;
                for (var i = 0; i <= freezeIndex; i++) {
                    leftOffsets[i] = acc;
                    acc += ths[i].offsetWidth;
                }

                // 3. Apply sticky left to header cells (0 to freezeIndex)
                for (var i = 0; i < ths.length; i++) {
                    var th = ths[i];
                    if (i <= freezeIndex) {
                        th.classList.add('sticky-assign-col');
                        th.style.setProperty('position', 'sticky', 'important');
                        th.style.setProperty('top', '0', 'important');
                        th.style.setProperty('left', leftOffsets[i] + 'px', 'important');
                        th.style.setProperty('z-index', (i === freezeIndex ? '165' : '160'), 'important');
                        th.style.setProperty('background-color', '#5e6b77', 'important');
                        th.style.setProperty('color', '#ffffff', 'important');
                        if (i === freezeIndex) {
                            th.classList.add('sticky-assign-col-last');
                        } else {
                            th.classList.remove('sticky-assign-col-last');
                        }
                    } else {
                        th.classList.remove('sticky-assign-col', 'sticky-assign-col-last');
                        th.style.setProperty('position', 'sticky', 'important');
                        th.style.setProperty('top', '0', 'important');
                        th.style.removeProperty('left');
                        th.style.setProperty('z-index', '150', 'important');
                        th.style.setProperty('background-color', '#5e6b77', 'important');
                        th.style.setProperty('color', '#ffffff', 'important');
                    }
                }

                // 4. Apply sticky left to all tbody rows (0 to freezeIndex) with White & Light Ash zebra striping
                var rows = table.querySelectorAll('tbody tr');
                rows.forEach(function(row, rowIdx) {
                    var isEven = (rowIdx % 2 === 1); // Row 1 (0) = White, Row 2 (1) = Light Ash
                    var rowBg = isEven ? '#f2f4f6' : '#ffffff';
                    var cells = row.children;
                    for (var i = 0; i < cells.length; i++) {
                        var cell = cells[i];
                        if (i <= freezeIndex) {
                            cell.classList.add('sticky-assign-col', 'sticky-assign-cell');
                            cell.style.setProperty('position', 'sticky', 'important');
                            cell.style.setProperty('left', leftOffsets[i] + 'px', 'important');
                            cell.style.setProperty('z-index', (i === freezeIndex ? '15' : '10'), 'important');
                            cell.style.setProperty('background-color', rowBg, 'important');
                            if (i === freezeIndex) {
                                cell.classList.add('sticky-assign-col-last');
                            } else {
                                cell.classList.remove('sticky-assign-col-last');
                            }
                        } else {
                            cell.classList.remove('sticky-assign-col', 'sticky-assign-cell', 'sticky-assign-col-last');
                            cell.style.removeProperty('position');
                            cell.style.removeProperty('left');
                            cell.style.removeProperty('z-index');
                            cell.style.removeProperty('background-color');
                        }
                    }

                    // Hover interaction for sticky columns
                    if (!row.dataset.stickyHoverBound) {
                        row.dataset.stickyHoverBound = '1';
                        row.addEventListener('mouseenter', function() {
                            var sCells = this.querySelectorAll('.sticky-assign-col, .sticky-assign-cell');
                            sCells.forEach(function(sc) { sc.style.setProperty('background-color', '#e9ecef', 'important'); });
                        });
                        row.addEventListener('mouseleave', function() {
                            var curEven = (Array.from(this.parentNode.children).indexOf(this) % 2 === 1);
                            var bg = curEven ? '#f2f4f6' : '#ffffff';
                            var sCells = this.querySelectorAll('.sticky-assign-col, .sticky-assign-cell');
                            sCells.forEach(function(sc) { sc.style.setProperty('background-color', bg, 'important'); });
                        });
                    }
                });
            });
        }

        window.addEventListener('DOMContentLoaded', applyStickyAssignColumns);
        window.addEventListener('load', applyStickyAssignColumns);
        window.addEventListener('resize', applyStickyAssignColumns);
        setTimeout(applyStickyAssignColumns, 200);
        setTimeout(applyStickyAssignColumns, 600);
    </script>
</body>
</html>
