<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';

// Check if user is logged in
if (!isset($_SESSION['uid']) || !isset($_SESSION['role_name'])) {
    header("Location: index.php");
    exit;
}

// Check session timeout
checkSessionTimeout($mysqli);
function checkSessionTimeout($mysqli) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
        // 30 minutes timeout
        if (isset($_SESSION['login_log_id']) && isset($_SESSION['uid'])) {
            $stmt = $mysqli->prepare("
                UPDATE employee_login_logs 
                SET logout_time = NOW(), status = 'timeout' 
                WHERE id = ? AND employee_id = ?
            ");
            $stmt->bind_param("ii", $_SESSION['login_log_id'], $_SESSION['uid']);
            $stmt->execute();
            $stmt->close();
        }
        
        session_unset();
        session_destroy();
        header("Location: index.php?error=session_timeout");
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// Check if user has permission to view logs (adjust roles as needed)
$allowed_roles = ['Super Admin', 'HR', 'Admin'];
if (!in_array($_SESSION['role_name'], $allowed_roles)) {
    header("Location: index.php");
    exit;
}

// Initialize filters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : '';

// Build WHERE clause for filters
$where_conditions = [];
$params = [];
$types = '';

// Date filter
if (!empty($start_date) && !empty($end_date)) {
    $where_conditions[] = "DATE(l.login_time) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= 'ss';
}

// Employee filter
if (!empty($employee_id)) {
    $where_conditions[] = "l.employee_id = ?";
    $params[] = $employee_id;
    $types .= 'i';
}

// Build final WHERE clause
$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(' AND ', $where_conditions);
}

// Get employee logs with filters
$query = "
    SELECT 
        l.*,
        e.employee_name,
        e.employee_username,
        r.role_name,
        TIMESTAMPDIFF(SECOND, l.login_time, COALESCE(l.logout_time, NOW())) as duration_seconds
    FROM employee_login_logs l
    LEFT JOIN employee_master e ON l.employee_id = e.employee_id
    LEFT JOIN user_role_master r ON e.user_role_id = r.role_id
    $where_clause
    ORDER BY l.login_time DESC
";

$stmt = $mysqli->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$logs = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get employees for filter dropdown
$employees_query = "
    SELECT employee_id, employee_name, employee_username 
    FROM employee_master 
    WHERE status = 'Active' 
    ORDER BY employee_name
";
$employees_result = $mysqli->query($employees_query);
$employees = $employees_result->fetch_all(MYSQLI_ASSOC);

// Calculate summary statistics
$total_logins = count($logs);
$total_hours = 0;
$active_sessions = 0;

foreach ($logs as $log) {
    $total_hours += $log['duration_seconds'];
    if ($log['status'] === 'active') {
        $active_sessions++;
    }
}

$total_hours = round($total_hours / 3600, 2); // Convert seconds to hours
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Login Logs - Tapasi Solutions LLP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <style>
        :root {
            --primary: #3498db;
            --primary-dark: #2980b9;
            --secondary: #2c3e50;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --light: #f8f9fa;
            --dark: #343a40;
        }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            /* background: linear-gradient(135deg, var(--secondary) 0%, var(--dark) 100%); */
            background-color: #707981 !important;
            color: white;
            border-radius: 10px 10px 0 0 !important;
            padding: 15px 20px;
        }
        
        .stat-card {
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            color: white;
            margin-bottom: 20px;
        }
        
        .stat-card.total { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); }
        .stat-card.hours { background: linear-gradient(135deg, var(--success) 0%, #229954 100%); }
        .stat-card.active { background: linear-gradient(135deg, var(--warning) 0%, #e67e22 100%); }
        
        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .stat-card .number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .filter-form {
            background: var(--light);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .btn-primary {
            background: var(--primary);
            border: none;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .status-active { background: #d4edda; color: #155724; }
        .status-logout { background: #e2e3e5; color: #383d41; }
        .status-timeout { background: #fff3cd; color: #856404; }
        
        .table th {
            background: var(--light);
            border-bottom: 2px solid #dee2e6;
        }
        
        .duration-cell {
            font-weight: bold;
            text-align: center;
        }
        
        .export-btn {
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    <!-- Header -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background: linear-gradient(135deg, var(--secondary) 0%, var(--dark) 100%);">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-chart-line"></i> Employee Login Logs
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['ename'] ?? $_SESSION['username']); ?>
                </span>
                <a class="btn btn-outline-light btn-sm" href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a class="btn btn-light btn-sm ms-2" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Summary Cards -->
        <div class="row">
            <div class="col-md-4">
                <div class="stat-card total">
                    <i class="fas fa-sign-in-alt"></i>
                    <div class="number"><?php echo " " .$total_logins; ?></div>
                    <div>Total Logins</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card hours">
                    <i class="fas fa-clock"></i>
                    <div class="number"><?php echo " " .$total_hours; ?></div>
                    <div>Total Hours</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card active">
                    <i class="fas fa-user-clock"></i>
                    <div class="number"><?php echo " " .$active_sessions; ?></div>
                    <div>Active Sessions</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><font color="white"><i class="fas fa-filter"></i>Filters</font></h5>
            </div>
            <div class="card-body">
                <form method="GET" class="filter-form">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" 
                                   value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" 
                                   value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="employee_id" class="form-label">Employee</label>
                            <select class="form-select" id="employee_id" name="employee_id">
                                <option value="">All Employees</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?php echo $employee['employee_id']; ?>" 
                                        <?php echo ($employee_id == $employee['employee_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($employee['employee_name'] . ' (' . $employee['employee_username'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search"></i> Filter
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Export Button -->
        <div class="d-flex justify-content-end mb-3">
            <a href="export_logs.php?<?php echo http_build_query($_GET); ?>" class="btn btn-success export-btn">
                <i class="fas fa-file-excel"></i> Export to Excel
            </a>
        </div>

        <!-- Logs Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><font color="white"><i class="fas fa-list"></i> Login Logs</font></h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table id="logsTable" class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>S.No</th>
                                <th>Employee</th>
                                <th>Role</th>
                                <th>Login Time</th>
                                <th>Logout Time</th>
                                <th>Duration</th>
                                <th>IP Address</th>
                                <th>Status</th>
                                <th>User Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="9" class="text-center">No logs found for the selected criteria.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $index => $log): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($log['employee_name']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($log['employee_username']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['role_name']); ?></td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($log['login_time'])); ?></td>
                                        <td>
                                            <?php if ($log['logout_time']): ?>
                                                <?php echo date('M j, Y g:i A', strtotime($log['logout_time'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="duration-cell">
                                            <?php echo formatDuration($log['duration_seconds']); ?>
                                        </td>
                                        <td>
                                            <code><?php echo htmlspecialchars($log['ip_address']); ?></code>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $log['status']; ?>">
                                                <?php echo ucfirst($log['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo ucfirst($log['user_type']); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize DataTable
            $('#logsTable').DataTable({
                "pageLength": 25,
                "order": [[3, 'desc']], // Sort by login time descending
                "responsive": true
            });
            
            // Set end date max to today
            const today = new Date().toISOString().split('T')[0];
            $('#start_date').attr('max', today);
            $('#end_date').attr('max', today);
            
            // Validate date range
            $('#start_date, #end_date').change(function() {
                const startDate = new Date($('#start_date').val());
                const endDate = new Date($('#end_date').val());
                
                if (startDate > endDate) {
                    alert('Start date cannot be after end date!');
                    $('#start_date').val('');
                    $('#end_date').val('');
                }
            });
        });
    </script>
</body>
</html>

<?php
/**
 * Format duration in seconds to human readable format
 */
function formatDuration($seconds) {
    if ($seconds < 60) {
        return "{$seconds}s";
    }
    
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $remaining_seconds = $seconds % 60;
    
    if ($hours > 0) {
        return sprintf("%dh %02dm %02ds", $hours, $minutes, $remaining_seconds);
    } else {
        return sprintf("%02dm %02ds", $minutes, $remaining_seconds);
    }
}
?>