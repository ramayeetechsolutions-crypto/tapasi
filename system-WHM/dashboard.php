<?php 
// 1. Include the Database Connection
require 'db.php'; 

// 2. Include the Header/Navigation (This file must contain the HTML start, <head>, and navigation)
require 'header.php'; 

// --- Database Connection Check (Ensure $mysqli is available from db.php) ---
if (!isset($mysqli) || $mysqli->connect_error) {
    die("Database connection failed.");
}

// --- Data Fetching ---
$queries = [
    'cpu_count' => "SELECT COUNT(*) as count FROM tbl_cpu",
    'keyboard_count' => "SELECT COUNT(*) as count FROM tbl_keyboard",
    'mouse_count' => "SELECT COUNT(*) as count FROM tbl_mouse",
    'system_count' => "SELECT COUNT(*) as count FROM tbl_systems",
    'ups_count' => "SELECT COUNT(*) as count FROM tbl_ups",
    //'battery_count' => "SELECT COUNT(*) as count FROM tbl_battery",
    'complaint_count' => "SELECT COUNT(*) as count FROM tbl_complaints",
    'wfh_count' => "SELECT COUNT(*) as count FROM tbl_wfh",
    'awards_count' => "SELECT COUNT(*) as count FROM tbl_awards",
    'access_cards_count' => "SELECT COUNT(*) as count FROM tbl_access_cards",
    'backup_count' => "SELECT COUNT(*) as count FROM tbl_backups",
    'scrap_count' => "SELECT COUNT(*) as count FROM tbl_scrap",
    'accessories_count' => "SELECT COUNT(*) as count FROM tbl_accessories"
];

$counts = [];
foreach ($queries as $key => $sql) {
    $result = $mysqli->query($sql);
    $counts[$key] = $result ? $result->fetch_assoc()['count'] : 0;
}
extract($counts);

// Fetch recent complaints
$recent_complaints = $mysqli->query("SELECT issues, created_by, complaint_date, status FROM tbl_complaints ORDER BY complaint_date DESC LIMIT 3");
 
// Fetch recent systems
$recent_systems = $mysqli->query("SELECT system_no, team, employee_name, status FROM tbl_systems ORDER BY created_at DESC LIMIT 3");
 
// Fetch system status distribution
$system_status = $mysqli->query("SELECT status, COUNT(*) as count FROM tbl_systems GROUP BY status");
$status_data = [];
if ($system_status) {
    while ($row = $system_status->fetch_assoc()) {
        $status_data[$row['status']] = $row['count'];
    }
}
 
// Fetch complaint status distribution
$complaint_statuses = $mysqli->query("SELECT issues, complaint_type, status FROM tbl_complaints ORDER BY complaint_date DESC LIMIT 3");
?>

<style>
:root {
    --primary: #3498db;
    --primary-dark: #2980b9;
    --secondary: #2c3e50;
    --success: #27ae60;
    --warning: #f39c12;
    --danger: #e74c3c;
    --info: #17a2b8;
    --light: #ecf0f1;
    --dark: #34495e;
    --gray: #95a5a6;
    --light-gray: #f8f9fa;
    --border-radius: 12px;
    --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    --transition: all 0.3s ease;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

body {
    background-color: #f5f7fa;
    color: #333;
    line-height: 1.6;
}

/* Header Styles */
.header {
    background: var(--secondary);
    color: white;
    padding: 0 20px;
    box-shadow: var(--box-shadow);
    position: sticky;
    top: 0;
    z-index: 1000;
}

.header-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    max-width: 1400px;
    margin: 0 auto;
    padding: 15px 0;
}

.logo {
    display: flex;
    align-items: center;
    gap: 10px;
}

.logo h1 {
    font-size: 1.5rem;
    font-weight: 600;
}

.logo i {
    color: var(--primary);
    font-size: 1.8rem;
}

.main-nav ul {
    display: flex;
    list-style: none;
    gap: 5px;
}

.nav-link {
    display: flex;
    align-items: center;
    padding: 10px 15px;
    color: rgba(255, 255, 255, 0.8);
    text-decoration: none;
    border-radius: var(--border-radius);
    transition: var(--transition);
    font-size: 0.9rem;
}

.nav-link:hover, .nav-link.active {
    background: rgba(255, 255, 255, 0.1);
    color: white;
}

.nav-link i {
    margin-right: 8px;
    font-size: 0.9rem;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
}

/* Main Content Styles */
.main-content {
    max-width: 1400px;
    margin: 0 auto;
    padding: 30px 20px;
}

.page-title-container {
    margin-bottom: 30px;
    background: white;
    padding: 25px;
    border-radius: var(--border-radius);
    box-shadow: var(--box-shadow);
}

.page-title-container h2 {
    color: var(--secondary);
    font-weight: 600;
    font-size: 1.8rem;
    margin-bottom: 5px;
}

.page-description {
    color: var(--gray);
    font-size: 1.1rem;
}

/* Stats Cards */
.stats-container {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 15px;
    margin-bottom: 30px;
}
.stat-card {
    background: white;
    border-radius: var(--border-radius);
    padding: 25px;
    box-shadow: var(--box-shadow);
    display: flex;
    align-items: center;
    transition: var(--transition);
    border-left: 4px solid var(--primary);
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
}

.stat-icon {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 20px;
    font-size: 28px;
    color: white;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
}

.stat-info h3 {
    font-size: 2.2rem;
    margin-bottom: 5px;
    color: var(--secondary);
    font-weight: 700;
}

.stat-info p {
    color: var(--gray);
    font-size: 0.95rem;
    font-weight: 500;
}

/* Icon Colors */
.icon-cpu { background: linear-gradient(45deg, #3498db, #2980b9); }
.icon-keyboard { background: linear-gradient(45deg, #9b59b6, #8e44ad); }
.icon-mouse { background: linear-gradient(45deg, #e74c3c, #c0392b); }
.icon-system { background: linear-gradient(45deg, #2ecc71, #27ae60); }
.icon-ups { background: linear-gradient(45deg, #f39c12, #e67e22); }
/*.icon-battery { background: linear-gradient(45deg, #1abc9c, #16a085); }*/
.icon-complaint { background: linear-gradient(45deg, #e74c3c, #c0392b); }
.icon-wfh { background: linear-gradient(45deg, #3498db, #2980b9); }
.icon-awards { background: linear-gradient(45deg, #9b59b6, #8e44ad); }
.icon-access { background: linear-gradient(45deg, #f39c12, #e67e22); }
.icon-backup { background: linear-gradient(45deg, #1abc9c, #16a085); }
.icon-scrap { background: linear-gradient(45deg, #95a5a6, #7f8c8d); }
.icon-accessories { background: linear-gradient(45deg, #e67e22, #d35400); }

/* Charts and Tables */
.charts-container {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 25px;
    margin-bottom: 30px;
}

.chart-card, .recent-card {
    background: white;
    border-radius: var(--border-radius);
    padding: 25px;
    box-shadow: var(--box-shadow);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid var(--light);
}

.card-header h3 {
    color: var(--secondary);
    font-size: 1.3rem;
    font-weight: 600;
}

.view-all {
    color: var(--primary);
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 500;
    transition: var(--transition);
}

.view-all:hover {
    color: var(--primary-dark);
    text-decoration: underline;
}

.chart-placeholder {
    height: 300px;
    background: linear-gradient(135deg, var(--light-gray), #e9ecef);
    border-radius: var(--border-radius);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--gray);
    text-align: center;
}

.chart-placeholder i {
    margin-bottom: 15px;
    opacity: 0.7;
}

.chart-placeholder h4 {
    margin-bottom: 10px;
    color: var(--dark);
    font-weight: 600;
}

.chart-placeholder p {
    font-size: 0.95rem;
}

.recent-list {
    list-style: none;
}

.recent-item {
    display: flex;
    align-items: center;
    padding: 15px 0;
    border-bottom: 1px solid var(--light);
    transition: var(--transition);
}

.recent-item:hover {
    background: var(--light-gray);
    border-radius: var(--border-radius);
    padding-left: 10px;
    padding-right: 10px;
}

.recent-item:last-child {
    border-bottom: none;
}

.recent-icon {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    color: white;
    font-size: 16px;
    box-shadow: 0 3px 6px rgba(0, 0, 0, 0.1);
}

.recent-info h4 {
    font-size: 1rem;
    margin-bottom: 5px;
    color: var(--dark);
    font-weight: 600;
}

.recent-info p {
    font-size: 0.85rem;
    color: var(--gray);
}

/* Tables Section */
.tables-container {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 25px;
}

.table-card {
    background: white;
    border-radius: var(--border-radius);
    padding: 25px;
    box-shadow: var(--box-shadow);
}

.table-responsive {
    overflow-x: auto;
    border-radius: var(--border-radius);
}

table {
    width: 100%;
    border-collapse: collapse;
}

th, td {
    padding: 15px;
    text-align: left;
    border-bottom: 1px solid var(--light);
}

th {
    background: var(--light-gray);
    color: var(--secondary);
    font-weight: 600;
    font-size: 0.9rem;
}

tr {
    transition: var(--transition);
}

tr:hover {
    background: var(--light-gray);
}

.status {
    padding: 8px 15px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-active {
    background: rgba(39, 174, 96, 0.15);
    color: var(--success);
    border: 1px solid rgba(39, 174, 96, 0.3);
}

.status-pending {
    background: rgba(243, 156, 18, 0.15);
    color: var(--warning);
    border: 1px solid rgba(243, 156, 18, 0.3);
}

.status-closed {
    background: rgba(231, 76, 60, 0.15);
    color: var(--danger);
    border: 1px solid rgba(231, 76, 60, 0.3);
}

/* Responsive Design */
@media (max-width: 1200px) {
    .charts-container {
        grid-template-columns: 1fr;
    }
    
    .tables-container {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 1200px) {
    .stats-container {
        grid-template-columns: repeat(4, 1fr);
    }
}

@media (max-width: 992px) {
    .main-nav ul {
        flex-wrap: wrap;
        justify-content: center;
    }
    
    .header-container {
        flex-direction: column;
        gap: 15px;
    }
    
    .stats-container {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-container {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .nav-link span {
        display: none;
    }
    
    .nav-link i {
        margin-right: 0;
        font-size: 1.1rem;
    }
    
    .stat-card {
        padding: 20px;
    }
    
    .stat-icon {
        width: 60px;
        height: 60px;
        font-size: 24px;
    }
    
    .stat-info h3 {
        font-size: 1.8rem;
    }
}

@media (max-width: 480px) {
    .stats-container {
        grid-template-columns: 1fr;
    }
}
.nav-link span {
        display: none;
    }
    
    .nav-link i {
        margin-right: 0;
        font-size: 1.1rem;
    }
    
    .stat-card {
        padding: 20px;
    }
    
    .stat-icon {
        width: 50px;
        height: 50px;
        font-size: 24px;
    }
    
    .stat-info h3 {
        font-size: 1.8rem;
    }
}

.menu-toggle {
    display: none;
    flex-direction: column;
    justify-content: space-between;
    width: 25px;
    height: 20px;
    cursor: pointer;
}

.menu-toggle span {
    height: 3px;
    width: 100%;
    background: white;
    border-radius: 3px;
    transition: var(--transition);
}

@media (max-width: 768px) {
    .menu-toggle {
        display: flex;
    }
    
    .main-nav {
        display: none;
        width: 100%;
    }
    
    .main-nav.active {
        display: block;
    }
    
    .main-nav ul {
        flex-direction: column;
        gap: 10px;
    }
    
    .nav-link {
        justify-content: center;
        padding: 12px;
    }
    
    .nav-link span {
        display: inline;
    }
}
</style>
<br><br>
<main class="main-content">
    <div class="page-title-container">
        <h2>Dashboard Overview</h2>
        <p class="page-description">Welcome to your IT Asset Management System</p>
    </div>

    <div class="stats-container">
        <div class="stat-card">
            <div class="stat-icon icon-cpu">
                <i class="fas fa-microchip"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo htmlspecialchars($cpu_count); ?></h3>
                <p>CPU Devices</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-keyboard">
                <i class="fas fa-keyboard"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo htmlspecialchars($keyboard_count); ?></h3>
                <p>Keyboards</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-mouse">
                <i class="fas fa-mouse"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo htmlspecialchars($mouse_count); ?></h3>
                <p>Mouse</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-system">
                <i class="fas fa-desktop"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo htmlspecialchars($system_count); ?></h3>
                <p>Systems</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-ups">
                <i class="fas fa-bolt"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo htmlspecialchars($ups_count); ?></h3>
                <p>UPS Devices</p>
            </div>
        </div>
       <!-- <div class="stat-card">
            <div class="stat-icon icon-battery">
                <i class="fas fa-battery-full"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo htmlspecialchars($battery_count); ?></h3>
                <p>Batteries</p>
            </div>
        </div>-->
        <div class="stat-card">
            <div class="stat-icon icon-complaint">
                <i class="fas fa-tools"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo htmlspecialchars($complaint_count); ?></h3>
                <p>Complaints</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-wfh">
                <i class="fas fa-home"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo htmlspecialchars($wfh_count); ?></h3>
                <p>WFH Allocations</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-awards">
                <i class="fas fa-trophy"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo htmlspecialchars($awards_count); ?></h3>
                <p>Awards</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-access">
                <i class="fas fa-id-card"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo htmlspecialchars($access_cards_count); ?></h3>
                <p>Access Cards</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-backup">
                <i class="fas fa-database"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo htmlspecialchars($backup_count); ?></h3>
                <p>Backups</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-scrap">
                <i class="fas fa-recycle"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo htmlspecialchars($scrap_count); ?></h3>
                <p>Scrap Items</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-accessories">
                <i class="fas fa-mobile-alt"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo htmlspecialchars($accessories_count); ?></h3>
                <p>Accessories</p>
            </div>
        </div>
    </div>

    
    <div class="tables-container">
        <div class="table-card">
            <div class="card-header">
                <h3>Recent Systems</h3>
                <a href="systems.php" class="view-all">View All</a>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>System No</th>
                            <th>Team</th>
                            <th>Employee</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($recent_systems && $recent_systems->num_rows > 0) {
                            while($system = $recent_systems->fetch_assoc()): 
                                $status_class = strtolower($system['status']) == 'active' ? 'active' : 'pending';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($system['system_no']); ?></td>
                                <td><?php echo htmlspecialchars($system['team']); ?></td>
                                <td><?php echo htmlspecialchars($system['employee_name']); ?></td>
                                <td><span class="status status-<?php echo htmlspecialchars($status_class); ?>">
                                    <?php echo ucfirst(htmlspecialchars($system['status'])); ?>
                                </span></td>
                            </tr>
                        <?php endwhile; 
                        } else {
                            echo '<tr><td colspan="4" style="text-align: center; padding: 20px; color: var(--gray);">No recent systems found.</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="table-card">
            <div class="card-header">
                <h3>Complaint Status Overview</h3>
                <a href="ticketmanagementsystem.php" class="view-all">View All</a>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Issue</th>
                            <th>Type</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($complaint_statuses && $complaint_statuses->num_rows > 0) {
                            while($complaint = $complaint_statuses->fetch_assoc()):
                                $status_lower = strtolower($complaint['status']);
                                $status_class = 'active'; 
                                if($status_lower == 'closed') $status_class = 'closed';
                                elseif($status_lower == 'pending') $status_class = 'pending';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($complaint['issues']); ?></td>
                                <td><?php echo htmlspecialchars($complaint['complaint_type']); ?></td>
                                <td><span class="status status-<?php echo htmlspecialchars($status_class); ?>">
                                    <?php echo htmlspecialchars($complaint['status']); ?></span>
                                </td>
                            </tr>
                        <?php endwhile; 
                        } else {
                            echo '<tr><td colspan="3" style="text-align: center; padding: 20px; color: var(--gray);">No complaint status data found.</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script>
    // Mobile menu toggle
    document.addEventListener('DOMContentLoaded', function() {
        const menuToggle = document.querySelector('.menu-toggle');
        const mainNav = document.querySelector('.main-nav');
        
        if (menuToggle && mainNav) {
            menuToggle.addEventListener('click', function() {
                mainNav.classList.toggle('active');
            });
        }
    });
</script>

</body>
</html>
<?php
// Close database connection
if (isset($mysqli)) {
    $mysqli->close();
}
?>