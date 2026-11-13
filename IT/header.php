<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login if not logged in
if (!isset($_SESSION['itname']) && !isset($_SESSION['infoname']) && !isset($_SESSION['uid'])) {
    header("Location: ../index.php");
    exit;
}

// Detect current page for menu highlighting
$currentPage = basename($_SERVER['PHP_SELF']);
$username = htmlspecialchars($_SESSION['username'] ?? 'User');

// Helper arrays to keep parent menu highlighted when a child page is active
$myAccountPages = ['employee_regby_emp.php', 'employee.php'];
$systemPages = ['my_system_details.php', 'ticketmanagementexecutive.php'];
$reportPages = ['error_entry_team.php', 'error_entry.php'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tapasi Solutions LLP - IT</title>
    <link rel="icon" type="image/jpeg" href="tlogo.jpg">
    <link rel="shortcut icon" type="image/jpeg" href="tlogo.jpg">
    <link rel="apple-touch-icon" href="tlogo.jpg">
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
            padding: 0 20px;
            height: 70px;
            width: 100%;
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
            margin-bottom: 0;
            padding-left: 0;
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
            display: flex;
            flex-direction: column;
        }

        .nav-dropdown li {
            display: block;
            width: 100%;
            margin: 0;
        }

        .nav-dropdown a {
            display: flex;
            align-items: center;
            width: 100%;
            color: var(--dark);
            padding: 12px 20px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .nav-dropdown a:last-child {
            border-bottom: none;
        }

        .nav-dropdown a:hover {
            background-color: #f8f9fa;
            color: var(--primary);
        }

        .nav-menu li:hover .nav-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        /* Header Right */
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logo-link {
            display: flex;
            align-items: center;
            text-decoration: none;
        }

        .logo-img {
            height: 40px;
            width: auto;
            border-radius: 4px;
            background-color: #fff;
            padding: 2px;
        }

        /* User Menu */
        .user-menu {
            position: relative;
        }

        /* Responsive Styles */
        @media (max-width: 992px) {
            .menu-toggle {
                display: flex;
            }

            .main-nav {
                position: fixed;
                top: 70px;
                left: -100%;
                width: 80%;
                max-width: 300px;
                height: calc(100vh - 70px);
                background-color: var(--ash);
                transition: all 0.3s ease;
                overflow-y: auto;
                box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
            }

            .main-nav.active {
                left: 0;
            }

            .nav-menu {
                flex-direction: column;
                width: 100%;
            }

            .nav-menu a {
                padding: 15px 20px;
                border-bottom: 1px solid var(--ash-dark);
            }

            .nav-dropdown {
                position: static;
                opacity: 1;
                visibility: visible;
                transform: none;
                box-shadow: none;
                background-color: var(--ash-dark);
                display: none;
            }

            .nav-menu li:hover .nav-dropdown {
                display: block;
            }

            .nav-dropdown a {
                color: var(--white);
                padding-left: 40px;
            }

            .nav-dropdown a:hover {
                background-color: var(--ash);
                color: var(--white);
            }
        }
    </style>
</head>

<body>
    <!-- Header -->
    <header>
        <div class="header-container">
            <!-- Mobile Menu Toggle Button -->
            <div class="menu-toggle" id="menuToggle">
                <span></span>
                <span></span>
                <span></span>
            </div>

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

                    <!-- My Account -->
                    <li>
                        <a href="#" onclick="return false;"
                            class="<?php echo (in_array($currentPage, $myAccountPages)) ? 'active' : ''; ?>">
                            <i class="fas fa-user-circle"></i> My Account
                        </a>
                        <ul class="nav-dropdown">
                            <li><a href="employee_regby_emp.php"><i class="fas fa-user-edit"></i> Update Profile</a></li>
                            <li><a href="employee.php"><i class="fas fa-check-circle"></i> Verify Details</a></li>
                        </ul>
                    </li>

                    <!-- System -->
                    <li>
                        <a href="#" onclick="return false;"
                            class="<?php echo (in_array($currentPage, $systemPages)) ? 'active' : ''; ?>">
                            <i class="fas fa-tools"></i> System
                        </a>
                        <ul class="nav-dropdown">
                            <li><a href="my_system_details.php"><i class="fas fa-desktop"></i> System Details</a></li>
                            <li><a href="ticketmanagementexecutive.php"><i class="fas fa-headset"></i> Complaints</a></li>
                        </ul>
                    </li>

                    <!-- Reports -->
                    <li>
                        <a href="#" onclick="return false;"
                            class="<?php echo (in_array($currentPage, $reportPages)) ? 'active' : ''; ?>">
                            <i class="fas fa-file-invoice"></i> Reports
                        </a>
                        <ul class="nav-dropdown">
                            <li><a href="error_entry_team.php"><i class="fas fa-exclamation-triangle"></i> Error Log</a></li>
                        </ul>
                    </li>

                </ul>
            </nav>

            <!-- 2. RIGHT SIDE: USER PROFILE + LOGO -->
            <div class="header-right">
                <!-- User Profile Menu -->
                <ul class="nav-menu">
                    <li>
                        <a href="#" onclick="return false;"><i class="fas fa-user-circle"></i> IT - <?php echo $username; ?></a>
                        <ul class="nav-dropdown" style="left: auto; right: 0; border-radius: 8px;">
                            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                        </ul>
                    </li>
                </ul>

                <!-- Company Logo -->
                <a href="dashboard.php" class="logo-link">
                    <img src="tlogo.jpg" alt="Company Logo" class="logo-img" />
                </a>
            </div>

        </div>
    </header>
