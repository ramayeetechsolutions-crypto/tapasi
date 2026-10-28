<br><br><br>
<?php 
// 1. Include the Database Connection
require 'db.php'; 

// 2. Include the Header/Navigation
require 'header.php'; 

// --- Database Connection Check ---
if (!isset($mysqli) || $mysqli->connect_error) {
    die("Database connection failed.");
}

// --- Data Fetching ---
$queries = [
    'complaint_count' => "SELECT COUNT(*) as count FROM tbl_complaints",
    'backup_count' => "SELECT COUNT(*) as count FROM tbl_backups",
    'wfh_count' => "SELECT COUNT(*) as count FROM tbl_wfh",
    'access_cards_count' => "SELECT COUNT(*) as count FROM tbl_access_cards",
    'accessories_count' => "SELECT COUNT(*) as count FROM tbl_accessories",
    'scrap_count' => "SELECT COUNT(*) as count FROM tbl_scrap",
    'system_count' => "SELECT COUNT(*) as count FROM tbl_systems_v2",
    'cpu_count' => "SELECT COUNT(*) as count FROM tbl_cpu",
    'keyboard_count' => "SELECT COUNT(*) as count FROM tbl_keyboard",
    'mouse_count' => "SELECT COUNT(*) as count FROM tbl_mouse",
    'awards_count' => "SELECT COUNT(*) as count FROM tbl_awards",
    'ups_count' => "SELECT COUNT(*) as count FROM tbl_ups"
];

$counts = [];
foreach ($queries as $key => $sql) {
    $result = $mysqli->query($sql);
    $counts[$key] = $result ? $result->fetch_assoc()['count'] : 0;
}
extract($counts);

// Fetch recent backups with all details
$recent_backups = $mysqli->query("SELECT id, team, folder_name, backup_date, backup_name, year, status, start_date, end_date FROM tbl_backups ORDER BY backup_date DESC, created_at DESC LIMIT 5");

// Fetch recent complaints
$recent_complaints = $mysqli->query("SELECT issues, created_by, complaint_date, status, complaint_type FROM tbl_complaints ORDER BY complaint_date DESC LIMIT 5");

// Fetch backup statistics
$backup_stats = $mysqli->query("SELECT 
    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_backups,
    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_backups,
    SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as inprogress_backups,
    SUM(CASE WHEN status = 'Failed' THEN 1 ELSE 0 END) as failed_backups
    FROM tbl_backups");
$backup_stats_data = $backup_stats->fetch_assoc();
?>

<style>
:root {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --secondary: #475569;
    --success: #16a34a;
    --warning: #ca8a04;
    --danger: #dc2626;
    --info: #0891b2;
    --light: #f1f5f9;
    --dark: #0f172a;
    --gray: #64748b;
    --light-gray: #f8fafc;
    --white: #ffffff;
    --border-radius: 16px;
    --box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

body {
    /* background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh; */
    background-color: #707981; 
    min-height: 100vh;
}

/* Main Container */
.dashboard-container {
    max-width: 1440px;
    margin: 0 auto;
    padding: 24px;
}

/* Header Styles */
.dashboard-header {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: var(--border-radius);
    padding: 24px 32px;
    margin-bottom: 24px;
    box-shadow: var(--box-shadow);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.header-title h1 {
    color: var(--dark);
    font-size: 2rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    margin-bottom: 8px;
}

.header-title p {
    color: var(--gray);
    font-size: 1rem;
    font-weight: 400;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: var(--border-radius);
    padding: 24px;
    box-shadow: var(--box-shadow);
    display: flex;
    align-items: center;
    transition: var(--transition);
    border: 1px solid rgba(255, 255, 255, 0.2);
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--primary), var(--info));
    opacity: 0;
    transition: var(--transition);
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 20px 30px -10px rgba(0, 0, 0, 0.2);
}

.stat-card:hover::before {
    opacity: 1;
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 20px;
    font-size: 24px;
    color: white;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.3);
}

.stat-content {
    flex: 1;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--dark);
    line-height: 1.2;
    margin-bottom: 4px;
}

.stat-label {
    color: var(--gray);
    font-size: 0.875rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-label span {
    color: var(--primary);
    font-weight: 600;
    margin-left: 4px;
}

/* Icon Colors */
.icon-complaint { background: linear-gradient(135deg, #ef4444, #dc2626); }
.icon-backup { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
.icon-wfh { background: linear-gradient(135deg, #3b82f6, #2563eb); }
.icon-access { background: linear-gradient(135deg, #f59e0b, #d97706); }
.icon-accessories { background: linear-gradient(135deg, #10b981, #059669); }
.icon-scrap { background: linear-gradient(135deg, #6b7280, #4b5563); }
.icon-system { background: linear-gradient(135deg, #14b8a6, #0d9488); }
.icon-cpu { background: linear-gradient(135deg, #a855f7, #9333ea); }
.icon-keyboard { background: linear-gradient(135deg, #ec4899, #db2777); }
.icon-mouse { background: linear-gradient(135deg, #f97316, #ea580c); }
.icon-awards { background: linear-gradient(135deg, #eab308, #ca8a04); }
.icon-ups { background: linear-gradient(135deg, #06b6d4, #0891b2); }

/* Mini Stats Cards for Backup Section */
.backup-mini-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 24px;
    padding: 16px;
    background: var(--light);
    border-radius: 12px;
}

.mini-stat-card {
    background: var(--white);
    border-radius: 10px;
    padding: 12px;
    display: flex;
    align-items: center;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
    transition: var(--transition);
}

.mini-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
}

.mini-stat-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 10px;
    font-size: 16px;
    color: white;
}

.mini-icon-completed { background: linear-gradient(135deg, #22c55e, #16a34a); }
.mini-icon-pending { background: linear-gradient(135deg, #eab308, #ca8a04); }
.mini-icon-progress { background: linear-gradient(135deg, #3b82f6, #2563eb); }
.mini-icon-failed { background: linear-gradient(135deg, #ef4444, #dc2626); }

.mini-stat-content h5 {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--dark);
    line-height: 1.2;
    margin-bottom: 2px;
}

.mini-stat-content p {
    color: var(--gray);
    font-size: 0.7rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

/* Content Grid */
.content-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}

.content-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: var(--border-radius);
    padding: 24px;
    box-shadow: var(--box-shadow);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 2px solid var(--light);
}

.card-header h2 {
    color: var(--dark);
    font-size: 1.25rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.card-header h2 i {
    color: var(--primary);
    font-size: 1.5rem;
}

.view-link {
    color: var(--primary);
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
    padding: 8px 16px;
    border-radius: 30px;
    background: var(--light);
    transition: var(--transition);
}

.view-link:hover {
    background: var(--primary);
    color: white;
}

/* Table Styles */
.table-wrapper {
    overflow-x: auto;
    border-radius: 12px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    text-align: left;
    padding: 14px 12px;
    background: var(--light);
    color: var(--secondary);
    font-weight: 600;
    font-size: 0.813rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.data-table td {
    padding: 14px 12px;
    border-bottom: 1px solid var(--light);
    color: var(--dark);
    font-size: 0.875rem;
}

.data-table tbody tr {
    transition: var(--transition);
}

.data-table tbody tr:hover {
    background: var(--light);
}

/* Status Badges */
.status-badge {
    padding: 6px 12px;
    border-radius: 30px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: inline-block;
    white-space: nowrap;
}

.status-completed, .status-active { background: #dcfce7; color: #166534; }
.status-pending { background: #fef9c3; color: #854d0e; }
.status-in-progress { background: #dbeafe; color: #1e40af; }
.status-failed, .status-closed { background: #fee2e2; color: #991b1b; }

/* Empty State */
.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--gray);
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}

/* Responsive Design */
@media (max-width: 1280px) {
    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 1024px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .content-grid {
        grid-template-columns: 1fr;
    }
    
    .backup-mini-stats {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 640px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .backup-mini-stats {
        grid-template-columns: 1fr;
    }
    
    .dashboard-container {
        padding: 16px;
    }
    
    .stat-card {
        padding: 20px;
    }
    
    .stat-icon {
        width: 50px;
        height: 50px;
        font-size: 20px;
    }
    
    .stat-value {
        font-size: 1.5rem;
    }
}


/* This ensures the cards look the same but are clickable */
a.stat-card {
    text-decoration: none;
    color: inherit;
    display: flex; /* Maintain your existing layout */
    transition: transform 0.2s, box-shadow 0.2s;
}

a.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    color: inherit;
}
</style>

<div class="dashboard-container">
    <!-- Header -->
    <div class="dashboard-header">
        <div class="header-title">
            <h1>Dashboard Overview</h1>
            <p>Welcome back! Here's what's happening with your IT assets today.</p>
        </div>
    </div>

    <!-- Statistics Cards in Exact Order -->
    <div class="stats-grid"> <!-- Assuming this is your wrapper -->

        <!-- 1. Complaints -->
        <a href="ticketmanagementsystem.php" class="stat-card">
            <div class="stat-icon icon-complaint">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo htmlspecialchars($complaint_count); ?></div>
                <div class="stat-label">Complaints <span>Active</span></div>
            </div>
        </a>

        <!-- 2. Backups -->
        <a href="backup.php" class="stat-card">
            <div class="stat-icon icon-backup">
                <i class="fas fa-database"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo htmlspecialchars($backup_count); ?></div>
                <div class="stat-label">Backups <span>Total</span></div>
            </div>
        </a>

        <!-- 3. WFH Allocations -->
        <a href="wfh.php" class="stat-card">
            <div class="stat-icon icon-wfh">
                <i class="fas fa-home"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo htmlspecialchars($wfh_count); ?></div>
                <div class="stat-label">WFH Allocations <span>Remote</span></div>
            </div>
        </a>

        <!-- 4. Access Cards -->
        <a href="access_control.php" class="stat-card">
            <div class="stat-icon icon-access">
                <i class="fas fa-id-card"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo htmlspecialchars($access_cards_count); ?></div>
                <div class="stat-label">Access Cards <span>Active</span></div>
            </div>
        </a>

        <!-- 5. Accessories -->
        <a href="accessories.php" class="stat-card">
            <div class="stat-icon icon-accessories">
                <i class="fas fa-headphones"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo htmlspecialchars($accessories_count); ?></div>
                <div class="stat-label">Accessories <span>Items</span></div>
            </div>
        </a>

        <!-- 6. Scrap -->
        <a href="scrap.php" class="stat-card">
            <div class="stat-icon icon-scrap">
                <i class="fas fa-recycle"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo htmlspecialchars($scrap_count); ?></div>
                <div class="stat-label">Scrap <span>Items</span></div>
            </div>
        </a>

        <!-- 7. System -->
        <a href="systems.php" class="stat-card">
            <div class="stat-icon icon-system">
                <i class="fas fa-desktop"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo htmlspecialchars($system_count); ?></div>
                <div class="stat-label">System <span>Devices</span></div>
            </div>
        </a>

        <!-- 8. CPU -->
        <a href="cpu.php" class="stat-card">
            <div class="stat-icon icon-cpu">
                <i class="fas fa-microchip"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo htmlspecialchars($cpu_count); ?></div>
                <div class="stat-label">CPU <span>Devices</span></div>
            </div>
        </a>

        <!-- 9. Keyboard -->
        <a href="keyboard.php" class="stat-card">
            <div class="stat-icon icon-keyboard">
                <i class="fas fa-keyboard"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo htmlspecialchars($keyboard_count); ?></div>
                <div class="stat-label">Keyboard <span>Devices</span></div>
            </div>
        </a>

        <!-- 10. Mouse -->
        <a href="mouse.php" class="stat-card">
            <div class="stat-icon icon-mouse">
                <i class="fas fa-mouse"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo htmlspecialchars($mouse_count); ?></div>
                <div class="stat-label">Mouse <span>Devices</span></div>
            </div>
        </a>

        <!-- 11. Awards -->
        <a href="awards.php" class="stat-card">
            <div class="stat-icon icon-awards">
                <i class="fas fa-trophy"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo htmlspecialchars($awards_count); ?></div>
                <div class="stat-label">Awards <span>Total</span></div>
            </div>
        </a>

        <!-- 12. UPS -->
        <a href="ups.php" class="stat-card">
            <div class="stat-icon icon-ups">
                <i class="fas fa-bolt"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo htmlspecialchars($ups_count); ?></div>
                <div class="stat-label">UPS <span>Devices</span></div>
            </div>
        </a>

    </div>
    <!-- Content Grid: Backups Left, Complaints Right -->
    <div class="content-grid">
        <!-- Left Column - Recent Backups with Mini Stats -->
        <div class="content-card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
    <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
        <h4 style="display: flex; align-items: center; gap: 4px; margin: 0; font-size: 1.2rem;">
            <i class="fas fa-database" style="color: #2563eb;"></i>
        Recent Backups
        </h4>
        
        <!-- Mini Backup Statistics - Inline with Header -->
        <div style="display: flex; gap: 5px; background: #f1f5f9; padding: 4px 8px; border-radius: 30px;">
            <!-- Completed -->
            <div style="display: flex; align-items: center; gap: 2px; background: white; padding: 2px 4px; border-radius: 20px;">
                <div style="width: 18px; height: 18px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 9px; color: white; background: linear-gradient(135deg, #22c55e, #16a34a);">
                    <i class="fas fa-check-circle"></i>
                </div>
                <span style="font-weight: 700; font-size: 0.8rem; color: #0f172a;"><?php echo htmlspecialchars($backup_stats_data['completed_backups'] ?? 0); ?></span>
                <span style="color: #64748b; font-size: 0.55rem; font-weight: 500;">Completed</span>
            </div>
            
            <!-- Pending -->
            <div style="display: flex; align-items: center; gap: 2px; background: white; padding: 2px 4px; border-radius: 20px;">
                <div style="width: 18px; height: 18px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 9px; color: white; background: linear-gradient(135deg, #eab308, #ca8a04);">
                    <i class="fas fa-clock"></i>
                </div>
                <span style="font-weight: 700; font-size: 0.8rem; color: #0f172a;"><?php echo htmlspecialchars($backup_stats_data['pending_backups'] ?? 0); ?></span>
                <span style="color: #64748b; font-size: 0.55rem; font-weight: 500;">Pending</span>
            </div>
            
            <!-- In Progress -->
            <div style="display: flex; align-items: center; gap: 2px; background: white; padding: 2px 4px; border-radius: 20px;">
                <div style="width: 18px; height: 18px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 9px; color: white; background: linear-gradient(135deg, #3b82f6, #2563eb);">
                    <i class="fas fa-sync-alt fa-spin"></i>
                </div>
                <span style="font-weight: 700; font-size: 0.8rem; color: #0f172a;"><?php echo htmlspecialchars($backup_stats_data['inprogress_backups'] ?? 0); ?></span>
                <span style="color: #64748b; font-size: 0.55rem; font-weight: 500;">In-Progress</span>
            </div>
            
            <!-- Failed -->
            <div style="display: flex; align-items: center; gap: 2px; background: white; padding: 2px 4px; border-radius: 20px;">
                <div style="width: 18px; height: 18px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 9px; color: white; background: linear-gradient(135deg, #ef4444, #dc2626);">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <span style="font-weight: 700; font-size: 0.8rem; color: #0f172a;"><?php echo htmlspecialchars($backup_stats_data['failed_backups'] ?? 0); ?></span>
                <span style="color: #64748b; font-size: 0.55rem; font-weight: 500;">Failed</span>
            </div>
        </div>
    </div>
    
    <a href="backup.php" class="view-link" style="color: #2563eb; text-decoration: none; font-size: 0.8rem; font-weight: 500; padding: 5px 12px; border-radius: 20px; background: #f1f5f9; transition: all 0.3s ease; white-space: nowrap; margin-left: auto;">
        <i class="fas fa-arrow-right" style="font-size: 0.7rem; margin-left: 3px;"></i> View All
    </a>
</div>        
         
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Team</th>
                            <th>Backup Name</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($recent_backups && $recent_backups->num_rows > 0) {
                            while($backup = $recent_backups->fetch_assoc()): 
                                $status_class = strtolower(str_replace(' ', '-', $backup['status']));
                            ?>
                            <tr>
                                <td><strong>#<?php echo htmlspecialchars($backup['id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($backup['team']); ?></td>
                                <td><?php echo htmlspecialchars($backup['backup_name']); ?></td>
                                <td><?php echo date('d M Y', strtotime($backup['backup_date'])); ?></td>
                                <td><span class="status-badge status-<?php echo htmlspecialchars($status_class); ?>">
                                    <?php echo htmlspecialchars($backup['status']); ?>
                                </span></td>
                            </tr>
                        <?php endwhile; 
                        } else { ?>
                            <tr>
                                <td colspan="5" class="empty-state">
                                    <i class="fas fa-database"></i>
                                    <p>No backup records found</p>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right Column - Recent Complaints -->
        <div class="content-card">
            <div class="card-header">
                <h2>
                    <i class="fas fa-exclamation-triangle"></i>
                    Recent Complaints
                </h2>
                <a href="ticketmanagementsystem.php" class="view-link">View All</a>
            </div>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Issue</th>
                            <th>Created By</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($recent_complaints && $recent_complaints->num_rows > 0) {
                            while($complaint = $recent_complaints->fetch_assoc()):
                                $status_class = strtolower($complaint['status']);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars(substr($complaint['issues'], 0, 30)) . '...'; ?></td>
                                <td><?php echo htmlspecialchars($complaint['created_by']); ?></td>
                                <td><?php echo date('d M Y', strtotime($complaint['complaint_date'])); ?></td>
                                <td><span class="status-badge status-<?php echo htmlspecialchars($status_class); ?>">
                                    <?php echo htmlspecialchars($complaint['status']); ?>
                                </span></td>
                            </tr>
                        <?php endwhile; 
                        } else { ?>
                            <tr>
                                <td colspan="4" class="empty-state">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <p>No complaints found</p>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Font Awesome for icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

</body>
</html>
<?php
if (isset($mysqli)) {
    $mysqli->close();
}
?>