<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent caching issues
/*header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");*/

// Redirect to login if not logged in

if (!isset($_SESSION['teamname'])) {
    header("Location: ../index.php");
    exit;
}

// Detect current page for menu highlighting
$currentPage = basename($_SERVER['PHP_SELF']);
$username    = htmlspecialchars($_SESSION['username'] ?? 'User');
$employee_id = $_SESSION['uid']; // set at login

// Step 1: Fetch employee process IDs
$sql = "SELECT * FROM employee_master WHERE employee_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();

$tl = $team = false;

if ($row = $result->fetch_assoc()) {
    $tl=$row['user_role_id'];
    $team=$row['team_id'];
   // echo $tl;
   // die;
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
            position: relative;
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

        /* Nested Sub-Dropdown Menus */
        .nav-sub-dropdown {
            position: absolute;
            top: 0;
            left: 100%;
            background-color: var(--white);
            min-width: 220px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            opacity: 0;
            visibility: hidden;
            transform: translateX(-10px);
            transition: all 0.3s ease;
            z-index: 1002;
            list-style: none;
            padding: 0;
        }

        .nav-dropdown li:hover > .nav-sub-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateX(0);
        }

        .nav-sub-dropdown li {
            margin: 0;
        }

        .nav-sub-dropdown a {
            color: var(--dark);
            padding: 12px 20px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
            display: flex;
            align-items: center;
        }

        .nav-sub-dropdown a:last-child {
            border-bottom: none;
        }

        .nav-sub-dropdown a:hover {
            background-color: #f8f9fa;
            color: var(--primary);
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

            /* Sub-dropdown overrides for mobile */
            .nav-sub-dropdown {
                position: static;
                box-shadow: none;
                background-color: rgba(0, 0, 0, 0.1);
                border-radius: 0;
                opacity: 1;
                visibility: visible;
                transform: none;
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.3s ease;
            }

            .nav-dropdown li:hover > .nav-sub-dropdown,
            .nav-dropdown li.active > .nav-sub-dropdown {
                max-height: 500px;
            }

            .nav-sub-dropdown a {
                padding-left: 60px;
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
                <ul class="nav-menu">
                    <li><a href="dashboard_tl.php" class="<?php echo ($currentPage == 'dashboard') ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>

                    <li>
                        <a href="#"><i class="fas fa-sitemap"></i> Master</a>
                        <ul class="nav-dropdown">
                            <li><a href="job_master.php"><i class="fas fa-briefcase"></i> Job</a></li>
                            <li><a href="process_master.php"><i class="fas fa-diagram-project"></i> Process</a></li>
                            <!-- <li>
                                <a class="nav-link <?= $currentPage === 'template_master.php'?>" href="template_master.php">
                                    <i class="fa fa-line-chart"></i> Template Master
                                </a>
                            </li> 
                            <li>
                                <a class="nav-link <?= $currentPage === 'specialty.php'?>" href="specialty.php">
                                    <i class="fa fa-line-chart"></i> Speciality Master
                                </a>
                            </li> -->
                            <li><a href="team_view.php"><i class="fas fa-users"></i> Team</a></li>
                        </ul>
                    </li>
<!-- My Account Dropdown -->
                    <li>
                        <a href="#"><i class="fas fa-user-circle"></i> My Account</a>
                        <ul class="nav-dropdown">
                            <li>
                                <a href="employee_regby_emp.php">
                                    <i class="fas fa-user-edit"></i> Update Profile
                                </a>
                            </li>
                            <li>
                                <a href="employee.php">
                                    <i class="fas fa-check-circle"></i> Verify Details
                                </a>
                            </li>
                            <li>
                                <a href="employee_leave.php">
                                    <i class="fas fa-calendar-alt"></i> Permission & OT
                                </a>
                            </li>
                        </ul>
                    </li>
        <!-- Employee Dropdown -->
            
                  <li>
                        <a href="#"><i class="fas fa-user-tie"></i> Employee</a>
                        <ul class="nav-dropdown">
                            <?php 
                            $user_role = $_SESSION["role_name"] ?? '';
                            $is_tl = in_array($user_role, ['TL', 'Sub TL']) || ($tl == '3' || $tl == 'TL' || $tl == 'Team Leader');

                            // Dynamic profile URL based on role
                            $profile_link = $is_tl ? 'employee_master.php' : 'employee.php';
                            $target_link  = $is_tl ? 'target_assignment.php' : 'target_executive.php';
                            $target_name  = $is_tl ? 'Target' : 'Production Entry';
                            ?>
                            <li><a href="<?= $profile_link ?>"><i class="fas fa-id-card"></i> Profile</a></li>
                            <li><a href="<?= $target_link ?>"><i class="fas fa-bullseye"></i> <?= $target_name ?></a></li>
                            
                            <?php if ($is_tl): ?>
                                <li><a href="teamleader_ot_approval.php"><i class="fas fa-bullseye"></i> OT Approvals</a></li>
                            <?php else: ?>
                                <li><a href="employee_leave.php"><i class="fas fa-calendar-alt"></i> OT Claims</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                    <li>
                        <a href="#"><i class="fas fa-tools"></i> System</a>
                        <ul class="nav-dropdown">
                            <li>
                                <a href="my_system_details.php">
                                    <i class="fas fa-desktop"></i>System Details
                                </a>
                            </li>
                            <li><a href="ticketmanagementexecutive.php"><i class="fas fa-headset"></i> Complaints</a></li>
                        </ul>
                    </li>
                    <li>
                        <a href="#"><i class="fas fa-briefcase"></i> Reports</a>
                        <ul class="nav-dropdown">
                            <li><a href="client_report.php"><i class="fas fa-chart-line"></i> Client Report</a></li>
                            <li><a href="team_count_report.php"><i class="fas fa-diagram-project"></i> Team Report</a></li>
                            <li>
                                <a class="nav-link" href="xml_report.php">
                                    <i class="fa fa-line-chart"></i> Graphical Reports
                                </a>
                            </li>
                            <li>
    <a class="dropdown-item" href="error_entry_wk.php">
        <i class="fas fa-calculator me-2 text-muted"></i> Error Log (WK)
    </a>
</li>

<li>
    <a class="dropdown-item" href="error_entry_uq.php">
        <i class="fas fa-calculator me-2 text-muted"></i> Error Log (UQ)
    </a>
</li>
                            <!-- <li>
                                <a class="nav-link" href="error_entry.php">
                                    <i class="fa fa-calculator"></i> Error Log
                                </a>
                            </li> -->
                            <!--li>
                                <a class="nav-link" href="error_view_sales.php">
                                    <i class="fa fa-calculator"></i> Error Log (Sales)
                                </a>
                            </li-->
                        </ul>
                    </li>

                    <!-- UPDATED XML JOBS SECTION -->
                    <li>
                        <a href="#"><i class="fas fa-cogs"></i> XML Jobs</a>
                        <ul class="nav-dropdown">
                            
                            <!-- 1. Jobs Unique -->
                            <li>
                                <a href="#">
                                    <i class="fas fa-tasks"></i> UQ Jobs 
                                    <i class="fas fa-chevron-right" style="margin-left: auto; font-size: 10px;"></i>
                                </a>
                                <ul class="nav-sub-dropdown">
                                    <li>
                                        <a href="xml_clients.php">
                                            <i class="fas fa-users"></i> UQ Clients
                                        </a>
                                    </li>
                                    <li>
                                        <a href="xml_unique_start.php" >
                                            <i class="fas fa-cogs"></i> UQ Process
                                        </a>
                                    </li>
                                    <li>
                                         <a href="upload_xml.php">
                                            <i class="fas fa-upload"></i> UQ Upload                                        </a>
                                    </li>
                                </ul>
                            </li>
                            
                            <!-- 2. Jobs WK -->
                            <li>
                                <a href="#">
                                    <i class="fas fa-book-open"></i> WK Jobs
                                    <i class="fas fa-chevron-right" style="margin-left: auto; font-size: 10px;"></i>
                                </a>
                                <ul class="nav-sub-dropdown">
                                    <li>
                                        <a href="pubcode.php" >
                                            <i class="fas fa-code"></i> WK Pubcode
                                        </a>
                                    </li>
                                  
                                    <li>
                                        <a href="#">
                                            <i class="fas fa-sitemap"></i> WK Process
                                        </a>
                                    </li>
                                    <li>
                                        <a href="#">
                                            <i class="fas fa-upload"></i> WK Upload
                                        </a>
                                    </li>
                                    <li>
                                        <a href="#">
                                            <i class="fas fa-project-diagram"></i> WK PMC JATS Process
                                        </a>
                                    </li>
                                </ul>
                            </li>
  <li>
                                        <a href="journal_master.php" >
                                            <i class="fas fa-book"></i> New Journals
                                        </a>
                                    </li>
                            <!-- Keeping unassigned/all jobs links from the original file under the main dropdown -->
                            <li>
                                <a class="nav-link" href="xml.php">
                                    <i class="fa fa-list"></i> XML Jobs (Unassigned)
                                </a>
                            </li>
                            <!--li>
                                <a class="nav-link" href="alljobs_xml.php">
                                    <i class="fa fa-list-alt"></i> All XML Jobs
                                </a>
                            </li-->

                        </ul>
                    </li>

                    
                    
  
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
                        // Keep dropdowns open if a sub-menu triggers click
                        if (link.nextElementSibling && link.nextElementSibling.classList.contains('nav-sub-dropdown')) {
                            return; 
                        }
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
    </script>
</body>
</html>