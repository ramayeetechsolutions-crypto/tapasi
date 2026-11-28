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
if (!headers_sent()) {
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
}

require 'db.php'; // Ensure db connection is available

// 3. NOW IT IS SAFE to grab session variables
$employee_id = $_SESSION['uid'] ?? 0; 
$username = htmlspecialchars($_SESSION['username'] ?? 'User');
$currentPage = basename($_SERVER['PHP_SELF']);
// Release session lock early to prevent session file blocking across requests/tabs
session_write_close();

// --- DATABASE LOGIC FOR DYNAMIC MENU ---

// Step 1: Fetch employee process IDs and team_id
$sql = "SELECT process, team_id FROM employee_master WHERE employee_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();

$show_ce = $show_le = $show_pag = $show_qc = $show_faq = false;
$user_team_id = 0;

if ($row = $result->fetch_assoc()) {
    $user_team_id = (int)($row['team_id'] ?? 0);
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

                    $masterPages = ['job_master.php', 'process_master.php', 'team_view.php', 'add_team_member.php'];
                    $myAccountPages = ['employee_regby_emp.php', 'employee.php', 'employee_leave.php'];
                    $employeePages = ['employee_master.php', 'target_assignment.php', 'teamleader_ot_approval.php'];
                    $systemPages = ['my_system_details.php', 'ticketmanagementexecutive.php'];
                    $reportPages = ['error_entry_team.php'];
                    $jobsPages = ['job_management.php', 'tm_queries.php'];
                    ?>
                    <li>
                        <a href="<?= $dash_url ?>" class="<?php echo ($currentPage == 'dashboard_exec.php' || $currentPage == 'dashboard_tl.php') ? 'active' : ''; ?>">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <!-- Masters -->
                    <li>
                        <a href="#" class="<?= in_array($currentPage, $masterPages) ? 'active' : '' ?>"><i class="fas fa-sitemap"></i> Master</a>
                        <ul class="nav-dropdown">
                            <li><a href="job_master.php" class="<?= $currentPage === 'job_master.php' ? 'active' : '' ?>"><i class="fas fa-briefcase"></i> Job</a></li>
                            <li><a href="process_master.php" class="<?= $currentPage === 'process_master.php' ? 'active' : '' ?>"><i class="fas fa-diagram-project"></i> Process</a></li>
                            <li><a href="team_view.php" class="<?= in_array($currentPage, ['team_view.php', 'add_team_member.php']) ? 'active' : '' ?>"><i class="fas fa-users"></i> Team</a></li>
                        </ul>
                    </li>
                    <!-- My Account Dropdown -->
                    <li>
                        <a href="#" class="<?= in_array($currentPage, $myAccountPages) ? 'active' : '' ?>"><i class="fas fa-user-circle"></i> My Account</a>
                        <ul class="nav-dropdown">
                            <li>
                                <a href="employee_regby_emp.php" class="<?= $currentPage === 'employee_regby_emp.php' ? 'active' : '' ?>">
                                    <i class="fas fa-user-edit"></i> Update Profile
                                </a>
                            </li>
                            <li>
                                <a href="employee.php" class="<?= $currentPage === 'employee.php' ? 'active' : '' ?>">
                                    <i class="fas fa-check-circle"></i> Verify Details
                                </a>
                            </li>
                            <li>
                                <a href="employee_leave.php" class="<?= $currentPage === 'employee_leave.php' ? 'active' : '' ?>">
                                    <i class="fas fa-calendar-alt"></i> Permission & OT
                                </a>
                            </li>
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
                    <!-- System Dropdown -->
                    <li>
                        <a href="#" class="<?= in_array($currentPage, $systemPages) ? 'active' : '' ?>"><i class="fas fa-cog"></i> System</a>
                        <ul class="nav-dropdown">
                            <li>
                                <a href="my_system_details.php" class="<?= $currentPage === 'my_system_details.php' ? 'active' : '' ?>">
                                    <i class="fas fa-desktop"></i>System Details
                                </a>
                            </li>
                            <li>
                                <a href="ticketmanagementexecutive.php" class="<?= $currentPage === 'ticketmanagementexecutive.php' ? 'active' : '' ?>">
                                    <i class="fas fa-ticket-alt"></i> Complaints
                                </a>
                            </li>
                        </ul>
                    </li>
                    <li>
                        <a href="#" class="<?= in_array($currentPage, $reportPages) ? 'active' : '' ?>"><i class="fas fa-file-invoice"></i> Reports</a>
                        <ul class="nav-dropdown">
                          <li>
                                <a href="error_entry_team.php" class="<?= $currentPage === 'error_entry_team.php' ? 'active' : '' ?>">
                                    <i class="fas fa-exclamation-triangle"></i> Error Log
                                </a>
                            </li>
                        </ul>
                    </li>  
                    <!-- Jobs Dropdown (UQ Team Only - other teams show till Reports) -->
                    <?php if (($user_team_id ?? 0) == 2): ?>
                    <li>
                        <a href="#" class="<?= in_array($currentPage, $jobsPages) ? 'active' : '' ?>"><i class="fas fa-briefcase"></i> Jobs</a>
                        <ul class="nav-dropdown">
                            <li>
                                <a href="job_management.php" class="<?= $currentPage === 'job_management.php' ? 'active' : '' ?>">
                                    <i class="fas fa-chart-pie"></i> Input & Output
                                </a>
                            </li>
                            <li>
                                <a href="tm_queries.php" class="<?= $currentPage === 'tm_queries.php' ? 'active' : '' ?>">
                                    <i class="fas fa-question-circle"></i> Queries
                                </a>
                            </li>
                        </ul>
                    </li>
                    <?php include __DIR__ . '/../uq_process_nav.php'; ?>
                    <?php endif; ?>
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

        // Sticky Columns Support for TL/SubTL/Executive Assign & Report Pages (From S.No until Journal field, except WK Report)
        function applyStickyAssignColumns() {
            if (window.location.pathname.indexOf('wk_report') !== -1) return;

            var isAssignPage = window.location.pathname.indexOf('assign') !== -1 || window.location.pathname.indexOf('alljobs') !== -1;
            var isReportPage = (window.location.pathname.indexOf('jobmgmt_') !== -1 || window.location.pathname.indexOf('report') !== -1);
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
                    if (row.classList.contains('row-dark-purple') || row.classList.contains('row-purple')) {
                        rowBg = '#d8b4fe';
                    } else if (row.classList.contains('row-light-purple')) {
                        rowBg = '#f3e8ff';
                    }
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
                            var hoverBg = '#e9ecef';
                            if (this.classList.contains('row-dark-purple') || this.classList.contains('row-purple')) {
                                hoverBg = '#c49bfb';
                            } else if (this.classList.contains('row-light-purple')) {
                                hoverBg = '#ede9fe';
                            }
                            var sCells = this.querySelectorAll('.sticky-assign-col, .sticky-assign-cell');
                            sCells.forEach(function(sc) { sc.style.setProperty('background-color', hoverBg, 'important'); });
                        });
                        row.addEventListener('mouseleave', function() {
                            var curEven = (Array.from(this.parentNode.children).indexOf(this) % 2 === 1);
                            var bg = curEven ? '#f2f4f6' : '#ffffff';
                            if (this.classList.contains('row-dark-purple') || this.classList.contains('row-purple')) {
                                bg = '#d8b4fe';
                            } else if (this.classList.contains('row-light-purple')) {
                                bg = '#f3e8ff';
                            }
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
