<br><br><br>
<?php
// Start a session if one is not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

function formatDate($date, $format = 'd-m-Y') {
    if (!$date) return '';
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    return $dateObj ? $dateObj->format($format) : $date;
}

function formatDateTime($datetime, $format = 'd-m-Y H:i:s') {
    if (!$datetime) return '';
    $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    return $dateObj ? $dateObj->format($format) : $datetime;
}

function formatDuration($minutes) {
    if (!$minutes || $minutes <= 0) return '-';
    
    // Convert to integer safely
    $minutes = (int)$minutes;
    
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    
    if ($hours > 0) {
        return sprintf('%dh %02dm', $hours, $mins);
    } else {
        return sprintf('%dm', $mins);
    }
}

$logged_user = $_SESSION['username'] ?? '';
$role_name = $_SESSION['role_name'] ?? '';

// Check access - only TL and above can view all reports, TM can view only their own
if (empty($logged_user)) {
    header('Location: login.php');
    exit;
}

// Filter parameters
$employee_filter = $_GET['employee'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$status_filter = $_GET['status'] ?? '';
$process_filter = $_GET['process'] ?? '';

// If TM, they can only view their own report
if ($role_name === 'TM') {
    $employee_filter = $logged_user;
}

// Build query for time tracking report
$where_clauses = [];
$params = [];
$types = '';

if (!empty($employee_filter)) {
    $where_clauses[] = "jtt.employee_username = ?";
    $params[] = $employee_filter;
    $types .= 's';
}

if (!empty($date_from)) {
    $where_clauses[] = "DATE(jtt.start_datetime) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $where_clauses[] = "DATE(jtt.start_datetime) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

if (!empty($status_filter)) {
    $where_clauses[] = "jtt.assigned_tm_status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($process_filter)) {
    $where_clauses[] = "p.job_name = ?";
    $params[] = $process_filter;
    $types .= 's';
}

$where_sql = '';
if ($where_clauses) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

// Get list of employees for filter dropdown (only for TL and above)
$employees_res = null;
if ($role_name !== 'TM') {
    $employees_res = $mysqli->query("
        SELECT DISTINCT employee_username 
        FROM job_time_tracking 
        ORDER BY employee_username
    ");
}

// Get list of processes for filter dropdown
$processes_res = $mysqli->query("
    SELECT DISTINCT p.job_name
    FROM job_time_tracking jtt
    JOIN job_master p ON jtt.process_id = p.job_id
    ORDER BY p.job_name
");

// Get time tracking data with summary
$sql = "SELECT jtt.tracking_id, jtt.employee_username, jtt.job_id, 
               jtt.process_id, jtt.start_datetime, jtt.end_datetime, 
               jtt.total_minutes, jtt.assigned_tm_status, jtt.created_at,
               j.journal_issue, j.article, j.assigned_to_tm, j.client_id,
               c.client_name,
               p.job_name as process_name
        FROM job_time_tracking jtt
        LEFT JOIN jobs j ON jtt.job_id = j.job_id
        LEFT JOIN client_master c ON j.client_id = c.client_id
        LEFT JOIN job_master p ON jtt.process_id = p.job_id
        $where_sql
        ORDER BY jtt.start_datetime DESC";

if ($where_sql && $types) {
    $stmt = $mysqli->prepare($sql);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $tracking_res = $stmt->get_result();
} else {
    $tracking_res = $mysqli->query($sql);
}

// Get summary statistics with proper type casting
$summary_sql = "SELECT 
                COUNT(*) as total_sessions,
                COUNT(DISTINCT jtt.job_id) as total_jobs,
                COUNT(DISTINCT jtt.employee_username) as total_employees,
                SUM(jtt.total_minutes) as total_minutes,
                AVG(jtt.total_minutes) as avg_minutes_per_session
                FROM job_time_tracking jtt
                $where_sql";

if ($where_sql && $types) {
    $stmt2 = $mysqli->prepare($summary_sql);
    if ($types) {
        $stmt2->bind_param($types, ...$params);
    }
    $stmt2->execute();
    $summary_result = $stmt2->get_result();
    $summary = $summary_result->fetch_assoc();
} else {
    $summary_result = $mysqli->query($summary_sql);
    $summary = $summary_result->fetch_assoc();
}

// Convert summary values to integers to avoid float-string issues
if ($summary) {
    $summary['total_minutes'] = (int)($summary['total_minutes'] ?? 0);
    $summary['avg_minutes_per_session'] = (int)($summary['avg_minutes_per_session'] ?? 0);
}

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Time Tracking Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <style>
        .card-stat {
            border-radius: 10px;
            transition: transform 0.2s;
        }
        .card-stat:hover {
            transform: translateY(-5px);
        }
        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        .badge-status {
            font-size: 0.75rem;
            padding: 4px 8px;
        }
        .export-buttons {
            margin-bottom: 20px;
        }
        .summary-card {
            border-left: 4px solid #0d6efd;
        }
        .in-progress {
            border-left: 4px solid #ffc107;
        }
        .completed {
            border-left: 4px solid #198754;
        }
        .not-started {
            border-left: 4px solid #6c757d;
        }
        .icon-circle {
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5>
                <?php if ($role_name === 'TM'): ?>
                    <i class="fas fa-chart-line text-primary"></i> My Time Tracking Report
                <?php else: ?>
                    <i class="fas fa-chart-bar text-primary"></i> Time Tracking Report
                <?php endif; ?>
            </h5>
            <a href="jobs.php" class="btn btn-sm btn-primary">
                <i class="fas fa-arrow-left"></i> Back to Jobs
            </a>
        </div>

        <!-- Summary Statistics -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card card-stat summary-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-2">Total Sessions</h6>
                                <h3 class="mb-0"><?= number_format($summary['total_sessions'] ?? 0) ?></h3>
                            </div>
                            <div class="icon-circle bg-primary text-white rounded-circle">
                                <i class="fas fa-clock fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card card-stat in-progress">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-2">Total Jobs</h6>
                                <h3 class="mb-0"><?= number_format($summary['total_jobs'] ?? 0) ?></h3>
                            </div>
                            <div class="icon-circle bg-warning text-white rounded-circle">
                                <i class="fas fa-tasks fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card card-stat completed">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-2">Total Time</h6>
                                <h3 class="mb-0"><?= formatDuration($summary['total_minutes'] ?? 0) ?></h3>
                            </div>
                            <div class="icon-circle bg-success text-white rounded-circle">
                                <i class="fas fa-hourglass-half fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card card-stat not-started">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-2">Avg Time/Session</h6>
                                <h3 class="mb-0"><?= formatDuration($summary['avg_minutes_per_session'] ?? 0) ?></h3>
                            </div>
                            <div class="icon-circle bg-info text-white rounded-circle">
                                <i class="fas fa-chart-pie fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter form -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="fas fa-filter"></i> Filter Options</h6>
            </div>
            <div class="card-body">
                <form method="get" class="row g-3">
                    <?php if ($role_name !== 'TM'): ?>
                    <div class="col-md-3">
                        <label class="form-label small">Employee</label>
                        <select name="employee" class="form-select form-select-sm">
                            <option value="">All Employees</option>
                            <?php if ($employees_res): 
                                $employees_res->data_seek(0); // Reset pointer
                                while ($emp = $employees_res->fetch_assoc()): ?>
                                <option value="<?= h($emp['employee_username']) ?>" 
                                    <?= $employee_filter == $emp['employee_username'] ? 'selected' : '' ?>>
                                    <?= h($emp['employee_username']) ?>
                                </option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="col-md-2">
                        <label class="form-label small">Date From</label>
                        <input type="date" name="date_from" value="<?= h($date_from) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Date To</label>
                        <input type="date" name="date_to" value="<?= h($date_to) ?>" class="form-control form-control-sm">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="Not Started" <?= $status_filter == 'Not Started' ? 'selected' : '' ?>>Not Started</option>
                            <option value="In Progress" <?= $status_filter == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="Completed" <?= $status_filter == 'Completed' ? 'selected' : '' ?>>Completed</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small">Process</label>
                        <select name="process" class="form-select form-select-sm">
                            <option value="">All Processes</option>
                            <?php 
                            $processes_res->data_seek(0); // Reset pointer
                            while ($proc = $processes_res->fetch_assoc()): ?>
                                <option value="<?= h($proc['job_name']) ?>" 
                                    <?= $process_filter == $proc['job_name'] ? 'selected' : '' ?>>
                                    <?= h($proc['job_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3 d-flex align-items-end">
                        <div class="btn-group w-100">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-search"></i> Filter
                            </button>
                            <a href="time_tracking_report.php" class="btn btn-secondary btn-sm">
                                <i class="fas fa-times"></i> Clear
                            </a>
                            <?php if ($role_name === 'TM'): ?>
                                <a href="time_tracking_report.php?employee=<?= urlencode($logged_user) ?>" 
                                   class="btn btn-info btn-sm">
                                    <i class="fas fa-user"></i> My Report
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Export buttons -->
        <div class="export-buttons mb-3">
            <div class="btn-group">
                <button type="button" class="btn btn-success btn-sm" onclick="exportToCSV()">
                    <i class="fas fa-file-csv"></i> Export to CSV
                </button>
                <button type="button" class="btn btn-danger btn-sm" onclick="printReport()">
                    <i class="fas fa-print"></i> Print Report
                </button>
            </div>
        </div>

        <!-- Time Tracking Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="timeTrackingTable">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Employee</th>
                                <th>Job ID</th>
                                <th>Client</th>
                                <th>Journal/Issue</th>
                                <th>Article</th>
                                <th>Process</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Tracked At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($tracking_res && $tracking_res->num_rows):
                                $i = 0;
                                $total_minutes = 0;
                                while ($track = $tracking_res->fetch_assoc()): 
                                $i++;
                                $duration = (int)($track['total_minutes'] ?? 0); // Cast to int
                                $total_minutes += $duration;
                                $status = $track['assigned_tm_status'] ?? 'Not Started';
                                
                                $status_colors = [
                                    'Not Started' => 'secondary',
                                    'In Progress' => 'warning',
                                    'Completed' => 'success'
                                ];
                                $status_color = $status_colors[$status] ?? 'secondary';
                            ?>
                                <tr>
                                    <td><?= $i ?></td>
                                    <td>
                                        <span class="badge bg-info"><?= h($track['employee_username']) ?></span>
                                    </td>
                                    <td>
                                        <a href="jobs.php?search_client=<?= urlencode($track['client_name'] ?? '') ?>" 
                                           class="text-decoration-none" title="View Job">
                                            #<?= $track['job_id'] ?>
                                        </a>
                                    </td>
                                    <td><?= h($track['client_name'] ?? 'N/A') ?></td>
                                    <td><?= h($track['journal_issue'] ?? 'N/A') ?></td>
                                    <td><?= h($track['article'] ?? 'N/A') ?></td>
                                    <td>
                                        <span class="badge bg-primary"><?= h($track['process_name'] ?? 'N/A') ?></span>
                                    </td>
                                    <td><?= formatDateTime($track['start_datetime'], 'd-m-Y H:i') ?></td>
                                    <td>
                                        <?php if ($track['end_datetime']): ?>
                                            <?= formatDateTime($track['end_datetime'], 'd-m-Y H:i') ?>
                                        <?php else: ?>
                                            <span class="badge bg-warning">In Progress</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($duration > 0): ?>
                                            <span class="badge bg-dark"><?= formatDuration($duration) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-status bg-<?= $status_color ?>">
                                            <?= h($status) ?>
                                        </span>
                                    </td>
                                    <td><?= formatDate($track['created_at'], 'd-m-Y') ?></td>
                                </tr>
                            <?php endwhile; ?>
                                <tr class="table-info fw-bold">
                                    <td colspan="9" class="text-end">Total Time:</td>
                                    <td>
                                        <span class="badge bg-dark fs-6"><?= formatDuration($total_minutes) ?></span>
                                    </td>
                                    <td colspan="2"></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="12" class="text-center py-4">
                                        <div class="text-muted">
                                            <i class="fas fa-clock fa-3x mb-3"></i>
                                            <h5>No time tracking records found</h5>
                                            <p>Try adjusting your filters or start tracking time on jobs</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/PapaParse/5.3.0/papaparse.min.js"></script>
    <script>
        // Export to CSV function
        function exportToCSV() {
            const table = document.getElementById('timeTrackingTable');
            const rows = table.querySelectorAll('tr');
            const csvData = [];
            
            // Add headers
            const headers = [];
            table.querySelectorAll('thead th').forEach(th => {
                headers.push(th.textContent.trim());
            });
            csvData.push(headers);
            
            // Add data rows (skip the total row)
            rows.forEach((row, index) => {
                if (index === 0 || row.classList.contains('table-info')) return; // Skip header and total row
                
                const rowData = [];
                row.querySelectorAll('td').forEach(td => {
                    // Remove badge HTML and get just the text
                    let text = td.textContent.trim();
                    // If there's a badge, get its text
                    const badge = td.querySelector('.badge');
                    if (badge) {
                        text = badge.textContent.trim();
                    }
                    rowData.push(text);
                });
                csvData.push(rowData);
            });
            
            // Convert to CSV string
            const csvString = PapaParse.unparse(csvData);
            
            // Create download link
            const blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'time_tracking_report_<?= date('Y-m-d') ?>.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            alert('CSV export started. Check your downloads folder.');
        }
        
        // Print report function
        function printReport() {
            const printContent = `
                <html>
                <head>
                    <title>Time Tracking Report - <?= date('Y-m-d') ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        h3 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th { background-color: #f8f9fa; font-weight: bold; padding: 10px; border: 1px solid #ddd; }
                        td { padding: 8px; border: 1px solid #ddd; }
                        .total-row { background-color: #e3f2fd; font-weight: bold; }
                        .footer { margin-top: 30px; text-align: center; color: #666; font-size: 12px; }
                        .print-date { float: right; font-size: 12px; color: #666; }
                    </style>
                </head>
                <body>
                    <h3>Time Tracking Report
                        <span class="print-date">Generated: <?= date('d-m-Y H:i') ?></span>
                    </h3>
                    ${document.getElementById('timeTrackingTable').outerHTML}
                    <div class="footer">
                        Report generated by Job Management System | <?= date('d-m-Y H:i:s') ?>
                    </div>
                </body>
                </html>
            `;
            
            const printWindow = window.open('', '_blank');
            printWindow.document.write(printContent);
            printWindow.document.close();
            printWindow.focus();
            setTimeout(() => {
                printWindow.print();
                printWindow.close();
            }, 500);
        }
        
        // Auto-refresh page every 5 minutes if there are active jobs
        <?php
        // Check if there are any active jobs for current user
        $active_check_sql = "SELECT COUNT(*) as active_count FROM job_time_tracking WHERE end_datetime IS NULL";
        if ($role_name === 'TM') {
            $active_check_sql .= " AND employee_username = ?";
            $active_stmt = $mysqli->prepare($active_check_sql);
            $active_stmt->bind_param("s", $logged_user);
            $active_stmt->execute();
            $active_result = $active_stmt->get_result();
        } else {
            $active_result = $mysqli->query($active_check_sql);
        }
        $active_data = $active_result->fetch_assoc();
        $has_active_jobs = ($active_data['active_count'] ?? 0) > 0;
        ?>
        
        <?php if ($has_active_jobs): ?>
        // Auto-refresh every 5 minutes (300000 milliseconds) if there are active jobs
        setTimeout(function() {
            location.reload();
        }, 300000);
        
        // Show notification about auto-refresh
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Page will auto-refresh in 5 minutes due to active jobs.');
        });
        <?php endif; ?>
    </script>
</body>
</html>