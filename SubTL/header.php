<?php
// 1. START SESSION FIRST
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. SECURITY CHECK IMMEDIATELY (Before any DB queries)
// Note: Changed 'membername' to 'username' based on your previous files. 
// If your system actually uses 'membername', change it back.
if (!isset($_SESSION['username'])) {
    header("Location: ../index.php");
    exit;
}

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require 'db.php'; // Ensure db connection is available

// 3. NOW IT IS SAFE to grab session variables
$employee_id = $_SESSION['uid'] ?? 0; 
$username = htmlspecialchars($_SESSION['username'] ?? 'User');
$currentPage = basename($_SERVER['PHP_SELF']);

// --- DATABASE LOGIC FOR DYNAMIC MENU ---

// Step 1: Fetch employee process IDs
$sql = "SELECT process FROM employee_master WHERE employee_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();

$show_ce = $show_le = $show_pag = $show_qc = $show_faq = false;

if ($row = $result->fetch_assoc()) {
    $process_ids = $row['process'];
    if (!empty($process_ids)) {
        $sql2 = "SELECT process_name FROM process_master WHERE process_id IN ($process_ids)";
        $result2 = $mysqli->query($sql2);
        
        while ($row2 = $result2->fetch_assoc()) {
            if (strpos($row2['process_name'], 'CE') !== false) {
                $show_ce = true;
            }
            if (strpos($row2['process_name'], 'LE') !== false) {
                $show_le = true;
            }
            if (strpos($row2['process_name'], 'TS') !== false) {
                $show_pag = true;
            }
            if (strpos($row2['process_name'], 'QC') !== false) {
                $show_qc = true;
            }
            if (strpos($row2['process_name'], 'FQA') !== false) {
                $show_faq = true;
            }
        }
    }
}

// Query for unassigned counts
$sql = "SELECT COUNT(*) AS ce_unassigned_count
        FROM jobs j
        WHERE j.process_id IN (1,4,5)
          AND j.assigned_ce IS NULL;";

$result = $mysqli->query($sql);
$ce_unassigned_count = 0;
if ($result && $row = $result->fetch_assoc()) {
    $ce_unassigned_count = $row['ce_unassigned_count'];
}

$sql = "SELECT COUNT(*) AS le_unassigned_count
        FROM jobs j
        WHERE j.process_id IN (1,4,5)
          AND j.assigned_le IS NULL AND j.assigned_ce <>''";

$result = $mysqli->query($sql);
$le_unassigned_count = 0;
if ($result && $row = $result->fetch_assoc()) {
    $le_unassigned_count = $row['le_unassigned_count'];
}

$sql = "SELECT COUNT(*) AS pag_unassigned_count
        FROM jobs j
        WHERE j.process_id IN (2,4,5)
          AND j.assigned_pag IS NULL;";

$result = $mysqli->query($sql);
$pag_unassigned_count = 0;
if ($result && $row = $result->fetch_assoc()) {
    $pag_unassigned_count = $row['pag_unassigned_count'];
}

$sql = "SELECT COUNT(*) AS qc_unassigned_count
        FROM jobs j
        WHERE j.process_id IN (2,4,5)
          AND j.assigned_qc IS NULL;";

$result = $mysqli->query($sql);
$qc_unassigned_count = 0;
if ($result && $row = $result->fetch_assoc()) {
    $qc_unassigned_count = $row['qc_unassigned_count'];
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
            width: 16px;
            text-align: center;
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
            min-width: 220px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1001;
            list-style: none;
            padding: 8px 0;
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
            padding: 10px 20px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
            display: flex;
            align-items: center;
            white-space: nowrap;
        }

        .nav-dropdown a:last-child {
            border-bottom: none;
        }

        .nav-dropdown a:hover {
            background-color: #f8f9fa;
            color: var(--primary);
            border-bottom-color: #f0f0f0;
        }

        .nav-dropdown a i {
            margin-right: 10px;
            width: 18px;
            text-align: center;
            color: var(--ash);
        }

        .nav-dropdown a:hover i {
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

        /* User Menu */
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
            padding: 8px 0;
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
            padding: 10px 20px;
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

        /* Count Badge */
        .count-badge {
            background-color: red;
            color: white;
            border-radius: 50%;
            padding: 3px 7px;
            font-size: 11px;
            font-weight: bold;
            margin-left: 5px;
            animation: blinker 1s linear infinite;
        }

        .ce-badge { background-color: #e74c3c; }
        .le-badge { background-color: #3498db; }
        .pag-badge { background-color: #f39c12; }
        .qc-badge { background-color: #27ae60; }

        @keyframes blinker {
            50% { opacity: 0; }
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
                    <!-- Dashboard -->
                    <?php
                    $dash_url = "dashboard_exec.php";
                    if (($_SESSION["role_name"] ?? '') === 'TL') {
                        $dash_url = "../Team_Lead/dashboard_tl.php";
                    } elseif (($_SESSION["role_name"] ?? '') === 'TM') {
                        $dash_url = "../Executive/dashboard_exec.php";
                    }
                    ?>
                    <li>
                        <a href="<?= $dash_url ?>" class="<?php echo ($currentPage == 'dashboard_exec.php' || $currentPage == 'dashboard_tl.php') ? 'active' : ''; ?>">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <!-- Masters -->
                    <li>
                        <a href="#"><i class="fas fa-sitemap"></i> Master</a>
                        <ul class="nav-dropdown">
                            <li><a href="job_master.php"><i class="fas fa-briefcase"></i> Job</a></li>
                            <li><a href="process_master.php"><i class="fas fa-diagram-project"></i> Process</a></li>
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
                    <!-- Employee -->
                    <li>
                        <a href="#"><i class="fas fa-user-tie"></i> Employee</a>
                        <ul class="nav-dropdown">
                            <li><a href="employee_master.php"><i class="fas fa-id-card"></i> Profile</a></li>
                            <li><a href="target_assignment.php"><i class="fas fa-bullseye"></i> Target</a></li>
                            <li><a href="teamleader_ot_approval.php"><i class="fas fa-bullseye"></i> OT Approvals</a></li>
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
                    <li>
                        <a href="#"><i class="fas fa-file-invoice"></i> Reports</a>
                        <ul class="nav-dropdown">
                          <li>
                                <a href="error_entry_team.php">
                                    <i class="fas fa-exclamation-triangle"></i> Error Log
                                </a>
                            </li>
                        </ul>
                    </li>  
                    <!-- Jobs Dropdown -->
                    <li>
                        <a href="#"><i class="fas fa-briefcase"></i> Jobs</a>
                        <ul class="nav-dropdown">
                            <?php if ($show_faq): ?>
                            <!-- <li>
                                <a href="final_job.php" class="<?= $currentPage === 'final_job.php' ? 'active' : '' ?>">
                                    <i class="fas fa-chart-line"></i> Job Progress (FQA)
                                </a>
                            </li> -->
                            <?php endif; ?>
                            
                            <?php if ($show_pag): ?>
                            <li>
                                <a href="alljobs_qc.php" class="<?= $currentPage === 'alljobs_qc.php' ? 'active' : '' ?>">
                                    <i class="fas fa-clipboard-check"></i> QC Check
                                </a>
                            </li>
                            <?php endif;?>

                            <li>
                                <a href="job_management.php" class="<?= $currentPage === 'job_management.php' ? 'active' : '' ?>">
                                    <i class="fas fa-chart-pie"></i> Input & Output
                                </a>
                            </li>
                            <li>
                                <a href="tm_queries.php">
                                    <i class="fas fa-question-circle"></i> Queries
                                </a>
                            </li>
                        </ul>
                    </li>
                    
                    
                    <?php include __DIR__ . '/../uq_process_nav.php'; ?>
                    </ul>
            </nav>
            
            <!-- Right Side - User Menu and Logo -->
            <div class="header-right">
                <ul class="nav-menu">
                    <li>
                        <a href="#">
                                <i class="fas fa-user"></i> <?= (($_SESSION["role_name"] ?? "") === "TM" ? "TM- " : "TL- ") . htmlspecialchars($_SESSION["username"]) ?>

                        </a>
                        <ul class="nav-dropdown">
                            <li>
                                <a href="../logout.php">
                                    <i class="fas fa-sign-out-alt"></i> Logout
                                </a>
                            </li>
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