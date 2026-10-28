<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent caching issues
/*header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
*/
// Check login
if (!isset($_SESSION['supername'])) {
    header("Location: ../index.php");
    exit;
}

// Detect current page
$currentPage = basename($_SERVER['PHP_SELF']); // e.g., job_master.php
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
            margin-top: 70px;
            padding: 30px 5%;
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

        /* Table header matching Dashboard/Master navbar grey */
        .table thead th,
        .table th,
        table thead th,
        table thead tr th,
        .table-light th,
        .table-dark th,
        .table > :not(caption) > thead > * > *,
        .table > thead > tr > th {
            background-color: #6c757d !important;
            color: #ffffff !important;
            --bs-table-bg: #6c757d !important;
            --bs-table-color: #ffffff !important;
            border-color: #5a6268 !important;
            font-weight: 600 !important;
            vertical-align: middle !important;
        }
    </style>
</head>
<body>
    <header>
        <div class="header-container">
            <div class="menu-toggle" id="menuToggle">
                <span></span>
                <span></span>
                <span></span>
            </div>
            
            <!-- Left Side Navigation with Improved Icons -->
            <nav class="main-nav" id="mainNav">
                <ul class="nav-menu">
                    <li><a href="dashboard_md.php" class="<?php echo ($currentPage == 'dashboard_md.php') ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    
                    <!-- Master Main Menu -->
                    <li>
                        <a href="#"><i class="fas fa-cogs"></i> Master</a>
                        <ul class="nav-dropdown">
                            <li><a href="company_master.php"><i class="fas fa-building"></i> Company</a></li>
                            <li><a href="language_master.php"><i class="fas fa-language"></i> Language</a></li>
                            <li><a href="currency_master.php"><i class="fas fa-coins"></i> Currency</a></li>
                            <li><a href="user_role_master.php"><i class="fas fa-user-cog"></i> User Role</a></li>
                            <li><a href="team_master.php"><i class="fas fa-users-cog"></i> Team</a></li>
                            <li><a href="designation_master.php"><i class="fas fa-id-badge"></i> Designation</a></li>
                            <li><a href="client_master.php"><i class="fas fa-user-tie"></i> Client</a></li>
                            <li><a href="job_master.php"><i class="fas fa-briefcase"></i> Job</a></li>
                            <li><a href="process_master.php"><i class="fas fa-briefcase"></i> Process</a></li>
                            <li><a href="users.php"><i class="fas fa-user-shield"></i> Users</a></li>
                            <li><a href="tds_master.php"><i class="fas fa-percentage"></i> TDS Members</a></li>
                            <li><a href="expenses_master.php"><i class="fas fa-file-invoice-dollar"></i> Expenses</a></li>
                        </ul>
                    </li>
                    <!-- My Account -->
                    <li>
                    <a href="#"><i class="fas fa-briefcase"></i> My Account</a>
                        <ul class="nav-dropdown">
                            <li><a href="employee_regby_emp.php"><i class="fas fa-users"></i> Update</a></li>
                            <li><a href="employee.php"><i class="fas fa-tasks"></i> Verify</a></li>
                            <li><a href="employee_leave.php"><i class="fas fa-tasks"></i> Permission/OT</a></li>
                            </ul>
                    </li>
                    <!-- Employee Management -->
                    <li>
                        <a href="#"><i class="fas fa-user-tie"></i> Employee</a>
                        <ul class="nav-dropdown">
                            <li><a href="employee_master.php"><i class="fas fa-user-edit"></i> Employee</a></li>
                            <li><a href="employee_logs.php"><i class="fas fa-clipboard-list"></i> Employee Logs</a></li>
                            <li><a href="employee_performance.php"><i class="fas fa-chart-line"></i> Employee Performance</a></li>
                             <li><a href="ot_approval.php"><i class="fas fa-check-circle"></i> OT Approvals</a></li>
                        </ul>
                    </li>
                    <!-- System Dropdown -->
                    <li>
                        <a href="#"><i class="fas fa-cog"></i> System</a>
                        <ul class="nav-dropdown">
                            <li>
                                <a href="my_system_details.php">
                                    <i class="fas fa-desktop"></i>System Details
                                </a>
                            </li>
                            <li>
                                <a href="ticketmanagementexecutive.php">
                                    <i class="fas fa-ticket-alt"></i> Complaints
                                </a>
                            </li>
                        </ul>
                    </li>
                    <!-- Job Management -->
                    <li>
                        <a href="#"><i class="fas fa-tasks"></i> Jobs</a>
                        <ul class="nav-dropdown">
                            <li><a href="jobs.php"><i class="fas fa-clipboard-check"></i> Job Status</a></li>
                            <li><a href="job_management.php"><i class="fas fa-chart-bar"></i> Job Progress</a></li>
                             <li><a href="ap_progress.php"><i class="fas fa-chart-gantt"></i> AP Progress</a></li>
                        </ul>
                    </li>
                    
                    <!-- Client Management -->
                    <li>
                        <a href="#"><i class="fas fa-handshake"></i> Client</a>
                        <ul class="nav-dropdown">
                            <li><a href="client_templates.php"><i class="fas fa-file-contract"></i> Client Templates</a></li>
                            <li><a href="client_invoices.php"><i class="fas fa-file-invoice"></i> Client Invoices</a></li>
                        </ul>
                    </li>
                    
                    <!-- Uploads Management -->
                    <li>
                        <a href="#"><i class="fas fa-cloud-upload-alt"></i> Uploads</a>
                        <ul class="nav-dropdown">
                            <li><a href="bankstatement.php"><i class="fas fa-university"></i> Bank Statement</a></li>
                            <li><a href="paypalstatement.php"><i class="fab fa-paypal"></i> PayPal Statement</a></li>
                        </ul>
                    </li>
                    
                    <!-- Transactions Management -->
                    <li>
                        <a href="#"><i class="fas fa-money-bill-wave"></i> Transactions</a>
                        <ul class="nav-dropdown">
                            <li><a href="expenses.php"><i class="fas fa-cash-register"></i> Cash Expenses</a></li>
                            <li><a href="commission.php"><i class="fas fa-hand-holding-usd"></i> Commission</a></li>
                            <li><a href="invoices1.php"><i class="fas fa-receipt"></i> Generated Invoices</a></li>
                            <li><a href="payment.php"><i class="fas fa-exchange-alt"></i> Payment Reconciliation</a></li>
                            <li><a href="tds.php"><i class="fas fa-chart-pie"></i> TDS Percentage</a></li>
                        </ul>
                    </li>
                    
                    <!-- Messaging -->
                    <li>
                        <a href="#"><i class="fas fa-comment-dots"></i> Messaging</a>
                        <ul class="nav-dropdown">
                            <li><a href="birthday_templates.php"><i class="fas fa-birthday-cake"></i> Birthday Templates</a></li>
                            <li><a href="team_message.php"><i class="fas fa-comments"></i> Team Messages</a></li>
                            <li><a href="client_message.php"><i class="fas fa-envelope-open-text"></i> Client Messages</a></li>
                        </ul>
                    </li>
                </ul>
            </nav>
            
            <!-- Right Side - User Menu and Logo -->
            <div class="header-right">
                <ul class="nav-menu">
                    <!-- Super Admin Dropdown -->
                    <li>
                        <a href="#"><i class="fas fa-crown"></i> Super Admin</a>
                        <ul class="nav-dropdown">
                            <li><a href="change_password.php"><i class="fas fa-key"></i> Change Password</a></li>
                            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                        </ul>
                    </li>
                </ul>
                <img src="tlogo.jpg" alt="Company Logo" class="logo-img" />
            </div>
        </div>
    </header>
</body>
</html>