<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Prevent caching issues
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
*/
// Redirect to login if not logged in
if (!isset($_SESSION['salesname'])) {
    header("Location: ../index.php");
    exit;
}

// Detect current page for menu highlighting
$currentPage = basename($_SERVER['PHP_SELF']);
$username = htmlspecialchars($_SESSION['username'] ?? 'User');

// Helper arrays to keep parent menu highlighted when a child page is active
// 1. Employee Pages (Grouped together)
$employeePages = [
    'employee_leave.php', 
    'employee_registration.php', 
    'employee_regby_emp.php', 
    'employee_reg_sa.php', 
    'employee_import.php', 
    'imported_employees.php', 
    'employee_general_info.php', 
    'employee_official_info.php'
];

// 2. Master Pages (Removed employee items from here)
$masterPages = ['client_master.php', 'services_master.php', 'math_category_master.php'];

$clientPages = ['nda_details.php', 'client_followup.php', 'client_rate_list.php', 'inactive_clients.php'];
$servicePages = ['client_services.php', 'client_transactions.php'];
$calcPages = ['math_charges.php', 'sales_calculation.php'];
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
            padding: 0 20px; /* Reduces gap to just 20px from the edge */
            height: 70px;
            width: 100%;     /* Ensures it spans the full screen */
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
            margin-right: auto;
            /* Pushes right side elements to the far right */
        }

        .nav-menu {
            display: flex;
            list-style: none;
            transition: all 0.3s ease;
            margin-bottom: 0;
            /* Bootstrap fix */
            padding-left: 0;
        }

        .nav-menu li {
            position: relative;
        }

        /* --- UPDATED MENU STYLES START --- */
        .nav-menu a {
            text-decoration: none;
            color: var(--white);
            font-weight: 600;
            /* Made slightly bolder */
            font-size: 14px;
            /* Increased from 14px to 16px */
            transition: all 0.3s ease;
            position: relative;
            padding: 23px 15px;
            /* Increased padding from 15px to 20px */
            display: flex;
            align-items: center;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
            cursor: pointer;
        }

        .nav-menu a i {
            margin-right: 8px;
            font-size: 14px;
            /* Increased Icon size */
        }

        /* --- UPDATED MENU STYLES END --- */

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
            min-width: 240px;
            /* Made dropdown slightly wider */
            border-radius: 0 0 8px 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1001;
            list-style: none;
            padding: 0;
        }

        /* HOVER EFFECT */
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
            font-size: 14px;
            /* Increased Dropdown font size */
            display: flex;
            align-items: center;
            font-weight: 500;
        }

        .nav-dropdown a:last-child {
            border-bottom: none;
            border-radius: 0 0 8px 8px;
        }

        .nav-dropdown a:hover {
            background-color: #f8f9fa;
            color: var(--primary);
            border-bottom-color: #f0f0f0;
        }

        /* Header Right (User + Logo) */
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-left: auto; /* Forces this section to the far right */
        }

        .logo-img {
            height: 40px;
            width: auto;
        }

        .main-content {
            margin-top: 90px;
            padding: 30px 5%;
            transition: all 0.3s ease;
        }

        .logo-link {
            display: flex;
            align-items: center;
            text-decoration: none;
            margin-left: 10px;
        }

        /* User Menu Dropdown */
        .user-menu {
            position: relative;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .nav-menu a {
                padding: 23px 15px;
                font-size: 15px;
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
                font-size: 16px;
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
                padding: 0 15%;
            }

            .header-right {
                gap: 15px;
            }

            .logo-img {
                height: 35px;
            }
        }
    </style>
    <!-- Tapasi Global Typography & Font Stylesheet -->
    <link rel="stylesheet" href="../custom_style.css?v=<?= file_exists(__DIR__ . '/../custom_style.css') ? filemtime(__DIR__ . '/../custom_style.css') : 1 ?>">
</head>

<body>
    <header>
        <div class="header-container">

            <!-- Mobile Menu Toggle -->
            <div class="menu-toggle" id="menuToggle">
                <span></span>
                <span></span>
                <span></span>
            </div>

            <!-- 1. LEFT SIDE: NAVIGATION MENU -->
               <!-- 1. LEFT SIDE: NAVIGATION MENU -->
            <nav class="main-nav" id="mainNav">
                <ul class="nav-menu">

                    <!-- Dashboard -->
                    <li>
                        <a href="dashboard.php"
                            class="<?php echo ($currentPage == 'dashboard.php') ? 'active' : ''; ?>">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>

                    <!-- NEW: Employee Details (Consolidated Menu) -->
                    <!--li>
                        <a href="#" onclick="return false;"
                            class="<?php echo (in_array($currentPage, $employeePages)) ? 'active' : ''; ?>">
                            <i class="fas fa-users"></i> Employee Details
                        </a>
                        <ul class="nav-dropdown">
                          
                            <li><a href="employee_leave.php"><i class="fas fa-calendar-check"></i> Leave & OT</a></li>
                            
                           
                            <li><a href="employee_registration.php"><i class="fas fa-user-plus"></i> Employee Registration (TL)</a></li>
                            <li><a href="employee_regby_emp.php"><i class="fas fa-user-edit"></i> Employee Profile (Self)</a></li>
                            <li><a href="employee_reg_sa.php"><i class="fas fa-user-shield"></i> SA Employee Master</a></li>
                            
                         
                            <li><a href="employee_import.php"><i class="fa fa-upload"></i> Import Employees</a></li>
                            <li><a href="imported_employees.php"><i class="fa fa-database"></i> Imported Employees</a></li>
                            
                            
                            <li><a href="employee_general_info.php"><i class="fa fa-info-circle"></i> Employee General Info</a></li>
                            <li><a href="employee_official_info.php"><i class="fa fa-briefcase"></i> Employee Official Info</a></li>
                        </ul>
                    </li-->

                    <!-- Master (Cleaned up) -->
                    <li>
                        <a href="#" onclick="return false;"
                            class="<?php echo (in_array($currentPage, $masterPages)) ? 'active' : ''; ?>">
                            <i class="fas fa-database"></i> Master
                        </a>
                        <ul class="nav-dropdown">
                            <li><a href="client_master.php"><i class="fas fa-users"></i> Client Master</a></li>
                            <li><a href="services_master.php"><i class="fas fa-cogs"></i> Services Master</a></li>
                            <li><a href="math_category_master.php"><i class="fas fa-layer-group"></i> Math Category Master</a></li>
                        </ul>
                    </li>

                    <!-- Client Management -->
                    <li>
                        <a href="#" onclick="return false;"
                            class="<?php echo (in_array($currentPage, $clientPages)) ? 'active' : ''; ?>">
                            <i class="fas fa-briefcase"></i> Client Management
                        </a>
                        <ul class="nav-dropdown">
                            <li><a href="nda_details.php"><i class="fas fa-file-contract"></i> NDA Details</a></li>
                            <li><a href="client_followup.php"><i class="fas fa-headset"></i> Client Follow-up</a></li>
                            <li><a href="client_rate_list.php"><i class="fas fa-list"></i> Client Rate List</a></li>
                            <li><a href="inactive_clients.php"><i class="fas fa-user-times"></i> Inactive Clients</a></li>
                        </ul>
                    </li>

                    <!-- Services -->
                    <li>
                        <a href="#" onclick="return false;"
                            class="<?php echo (in_array($currentPage, $servicePages)) ? 'active' : ''; ?>">
                            <i class="fas fa-concierge-bell"></i> Services
                        </a>
                        <ul class="nav-dropdown">
                            <li><a href="client_services.php"><i class="fas fa-diagram-project"></i> Client Services</a></li>
                            <!--li><a href="client_transactions.php"><i class="fas fa-file-invoice"></i> Extra Cost Heading</a></li-->
                             <li class="nav-item">
                        <a class="nav-link" href="invoice_data.php"><i class="fas fa-file-invoice"></i> Invoice Data</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="error_entry.php"><i class="fas fa-file-invoice"></i> Error Log</a>
                    </li>
                        </ul>
                    </li>

                    <!-- Calculations -->
                    <li>
                        <a href="#" onclick="return false;"
                            class="<?php echo (in_array($currentPage, $calcPages)) ? 'active' : ''; ?>">
                            <i class="fas fa-calculator"></i> Calculations
                        </a>
                        <ul class="nav-dropdown">
                            <li><a href="math_charges.php"><i class="fas fa-percentage"></i> Math Charges</a></li>
                             <li class="nav-item">
                        <a class="nav-link" href="extra_charges.php">
                            <i class="fas fa-hand-holding-usd"></i> Extra Charges
                        </a>
                    </li>
                     <li><a href="sales_calculation.php"><i class="fa fa-briefcase"></i> Sales Calculation</a></li>
                       <li><a href="publisher_payment.php"><i class="fa fa-database"></i> Sales OT</a></li>
                        </ul>
                    </li>
                    
                    <!-- Invoice Data -->
                    
                    <!-- Extra Charges -->
                   

                </ul>
            </nav>

            <!-- 2. RIGHT SIDE: USER PROFILE + LOGO -->
            <div class="header-right">
                <!-- User Profile Menu -->
                <ul class="nav-menu">
                    <li>
                        <a href="#" onclick="return false;"><i class="fas fa-user-circle"></i> TL-
                            <?php echo $username; ?></a>
                        <ul class="nav-dropdown" style="left: auto; right: 0; border-radius: 8px;">
                            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                        </ul>
                    </li>
                </ul>

                <!-- LOGO (Moved to far right) -->
                <a href="dashboard.php" class="logo-link">
                    <img src="tlogo.jpg" alt="Company Logo" class="logo-img" />
                </a>
            </div>

        </div>
    </header>

    <!-- Scripts moved to footer.php -->