<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require 'db.php';

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Check if user is logged in and is a team leader
/*if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'team_leader') {
    header('Location: login.php');
    exit;
}*/

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

function getWeekNumber($date) {
    return date('W', strtotime($date));
}

function getWeekRange($date) {
    $week_start = date('d M', strtotime('monday this week', strtotime($date)));
    $week_end = date('d M', strtotime('sunday this week', strtotime($date)));
    return $week_start . ' - ' . $week_end;
}
$current_user = $_SESSION['username'] ?? '';

// Get employee name from employee_master table
$employee_username = $current_user;
$current_user_name = '';

// Prepare SQL query to fetch employee_name
$sql = "SELECT `employee_name` FROM `employee_master` WHERE `employee_username` = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("s", $employee_username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $current_user_name = $row['employee_name'];
}

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-t'); // Last day of current month
$employee_id = $_GET['employee_id'] ?? '';
$process = $_GET['process'] ?? '';
$team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : '';
$week_number = $_GET['week_number'] ?? '';

// Get current team leader's team
$team_leader_id = $_SESSION['uid'];
$team_info = $mysqli->query("SELECT team_id FROM employee_master WHERE employee_id = $team_leader_id")->fetch_assoc();
$current_team_id = $team_info['team_id'] ?? 0;

// If team_id not specified, use team leader's team
if (empty($team_id) && $current_team_id > 0) {
    $team_id = $current_team_id;
}

// Build base queries
$employee_cond = "em.status = 'Active'";
$count_cond = "wc.date BETWEEN '$start_date' AND '$end_date'";

if (!empty($employee_id)) {
    $employee_cond .= " AND em.employee_id = " . (int)$employee_id;
}

if (!empty($process)) {
    $employee_cond .= " AND (em.process LIKE '%$process%' OR em.process1 LIKE '%$process%')";
}

if (!empty($team_id)) {
    $employee_cond .= " AND em.team_id = " . (int)$team_id;
}

if (!empty($week_number)) {
    $count_cond .= " AND wc.week_number = " . (int)$week_number;
}

// Get employees under this team leader
$employees_sql = "SELECT em.employee_id, em.employee_name,em.employee_username, em.process, em.process1, 
                  em.team_id, t.team_name, em.designation_id, d.designation_name
                  FROM employee_master em
                  LEFT JOIN team_master t ON em.team_id = t.team_id
                  LEFT JOIN designation_master d ON em.designation_id = d.designation_id
                  WHERE reporting_manager='$current_user_name' AND $employee_cond
                  ORDER BY em.employee_id";

$employees_result = $mysqli->query($employees_sql);

// Initialize arrays to store data
$daily_totals = [];
$weekly_totals = [];
$employee_details = [];

if ($employees_result && $employees_result->num_rows > 0) {
    while ($emp = $employees_result->fetch_assoc()) {
        $emp_id = $emp['employee_username'];
        $employee_details[$emp_id] = $emp;
        
        // Get daily word counts for this employee
        $daily_sql = "SELECT wc.date, SUM(wc.count_value) as daily_total
                     FROM wordcount_master wc
                     WHERE wc.employee_id = '$emp_id' 
                     AND $count_cond
                     GROUP BY wc.date
                     ORDER BY wc.date";
     //   echo $daily_sql;
        $daily_result = $mysqli->query($daily_sql);
        while ($row = $daily_result->fetch_assoc()) {
            $date = $row['date'];
            $daily_totals[$emp_id][$date] = (int)$row['daily_total'];
            
            // Add to weekly total
            $week_no = getWeekNumber($date);
            if (!isset($weekly_totals[$emp_id][$week_no])) {
                $weekly_totals[$emp_id][$week_no] = 0;
            }
            $weekly_totals[$emp_id][$week_no] += (int)$row['daily_total'];
        }
    }
}

// Get all unique dates in range for table headers
$date_range_sql = "SELECT DISTINCT date FROM wordcount_master 
                   WHERE date BETWEEN '$start_date' AND '$end_date'
                   ORDER BY date";
$date_result = $mysqli->query($date_range_sql);
$dates = [];
while ($row = $date_result->fetch_assoc()) {
    $dates[] = $row['date'];
}

// Get unique week numbers
$week_sql = "SELECT DISTINCT week_number FROM wordcount_master 
             WHERE date BETWEEN '$start_date' AND '$end_date'
             ORDER BY week_number";
$week_result = $mysqli->query($week_sql);
$week_numbers = [];
while ($row = $week_result->fetch_assoc()) {
    $week_numbers[] = $row['week_number'];
}

// Get all teams for filter dropdown
$teams_sql = "SELECT team_id, team_name FROM team_master ORDER BY team_name";
$teams_result = $mysqli->query($teams_sql);

// Get all processes for filter dropdown
$processes_sql = "SELECT DISTINCT process_name FROM process_master ORDER BY process_name";
$processes_result = $mysqli->query($processes_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Word Count Report - Team Leader</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            margin: 0 auto;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
            margin-bottom: 20px;
        }
        .table th {
            /* background-color: #2c3e50; */
            background-color: #707981 !important;
            color: white;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .table-secondary th {
            background-color: #6c757d;
        }
        .employee-name {
            font-weight: 600;
            color: #2c3e50;
        }
        .total-cell {
            background-color: #e3f2fd;
            font-weight: bold;
        }
        .daily-total {
            background-color: #f1f8e9;
            font-weight: 600;
        }
        .weekly-total {
            background-color: #fff3e0;
            font-weight: 600;
        }
        .process-badge {
            font-size: 0.7rem;
            margin-right: 3px;
        }
        .date-header {
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            text-align: center;
            font-size: 0.8rem;
            width: 30px;
        }
        .sticky-col {
            position: sticky;
            left: 0;
            background-color: white;
            z-index: 5;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
        }
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
        }
        .filter-box {
            background-color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .table-container {
            max-height: 70vh;
            overflow-x: auto;
            overflow-y: auto;
        }
        .week-range {
            font-size: 0.8rem;
            color: #6c757d;
            margin-left: 5px;
        }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-chart-bar me-2"></i>Employee Word Count Report</h4>
            <div class="btn-group">
                <button class="btn btn-primary" onclick="exportToExcel()">
                    <i class="fas fa-file-excel me-1"></i> Export Excel
                </button>
                <button class="btn btn-success" onclick="window.print()">
                    <i class="fas fa-print me-1"></i> Print
                </button>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card summary-card">
                    <div class="card-body text-center">
                        <i class="fas fa-users fa-2x mb-2"></i>
                        <h6>Total Employees</h6>
                        <div class="stat-number"><?= count($employee_details) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="card-body text-center">
                        <i class="fas fa-calendar-alt fa-2x mb-2"></i>
                        <h6>Reporting Period</h6>
                        <div class="stat-number"><?= date('d M', strtotime($start_date)) ?> - <?= date('d M', strtotime($end_date)) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="card-body text-center">
                        <i class="fas fa-font fa-2x mb-2"></i>
                        <h6>Days in Report</h6>
                        <div class="stat-number"><?= count($dates) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                    <div class="card-body text-center">
                        <i class="fas fa-tasks fa-2x mb-2"></i>
                        <h6>Total Weeks</h6>
                        <div class="stat-number"><?= count($week_numbers) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Form -->
        <div class="filter-box">
            <form method="get" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?= h($start_date) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?= h($end_date) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Employee</label>
                    <select name="employee_id" class="form-select">
                        <option value="">All Employees</option>
                        <?php foreach($employee_details as $id => $emp): ?>
                            <option value="<?= $id ?>" <?= $employee_id == $id ? 'selected' : '' ?>>
                                <?= h($emp['employee_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Week Number</label>
                    <select name="week_number" class="form-select">
                        <option value="">All Weeks</option>
                        <?php foreach($week_numbers as $week): ?>
                            <option value="<?= $week ?>" <?= $week_number == $week ? 'selected' : '' ?>>
                                Week <?= $week ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Team</label>
                    <select name="team_id" class="form-select">
                        <option value="">All Teams</option>
                        <?php while($team = $teams_result->fetch_assoc()): ?>
                            <option value="<?= $team['team_id'] ?>" <?= $team_id == $team['team_id'] ? 'selected' : '' ?>>
                                <?= h($team['team_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Process</label>
                    <select name="process" class="form-select">
                        <option value="">All Processes</option>
                        <?php while($proc = $processes_result->fetch_assoc()): ?>
                            <option value="<?= $proc['process'] ?>" <?= $process == $proc['process'] ? 'selected' : '' ?>>
                                <?= h($proc['process']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-12 mt-3">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-filter me-1"></i> Apply Filters
                    </button>
                    <a href="wordcount_report_tl.php" class="btn btn-secondary">
                        <i class="fas fa-times me-1"></i> Clear Filters
                    </a>
                </div>
            </form>
        </div>

        <!-- Report Table -->
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0">Word Count Details</h5>
                <small class="text-muted">Showing data from <?= formatDate($start_date) ?> to <?= formatDate($end_date) ?></small>
            </div>
            <div class="card-body p-0">
                <div class="table-container">
                    <table class="table table-bordered table-striped mb-0" id="reportTable">
                        <thead>
                            <tr>
                                <th class="sticky-col" style="min-width: 200px;">Employee Details</th>
                                <?php if (!empty($dates)): ?>
                                    <!-- Daily Headers -->
                                    <?php 
                                    $current_week = null;
                                    $week_start_col = 1; // Start after employee details column
                                    ?>
                                    <?php foreach($dates as $index => $date): 
                                        $week_no = getWeekNumber($date);
                                        $day_name = date('D', strtotime($date));
                                        
                                        // Check for week change
                                        if ($current_week !== $week_no && $current_week !== null) {
                                            // Add weekly total column for previous week
                                            echo "<th class='table-secondary text-center' style='min-width: 80px;'>";
                                            echo "Week " . $current_week . " Total<br>";
                                            echo "<small class='week-range'>" . getWeekRange($date) . "</small>";
                                            echo "</th>";
                                        }
                                        
                                        if ($current_week !== $week_no) {
                                            $current_week = $week_no;
                                        }
                                    ?>
                                        <th class="text-center" style="min-width: 70px;">
                                            <?= date('d', strtotime($date)) ?><br>
                                            <small class="text-muted"><?= $day_name ?></small>
                                        </th>
                                    <?php endforeach; ?>
                                    
                                    <!-- Add weekly total for last week -->
                                    <?php if ($current_week !== null): ?>
                                        <th class="table-secondary text-center" style="min-width: 80px;">
                                            Week <?= $current_week ?> Total<br>
                                            <small class="week-range"><?= getWeekRange(end($dates)) ?></small>
                                        </th>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <!-- <th class="table-dark text-center" style="min-width: 100px;">Grand Total</th> -->
                                 <th class="text-center" style="min-width: 100px; background-color: #707981 !important;">Grand Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($employee_details)): ?>
                                <?php foreach($employee_details as $emp_id => $emp): ?>
                                <tr>
                                    <!-- Employee Details Column -->
                                    <td class="sticky-col" style="background-color: white;">
                                        <div class="employee-name"><?= h($emp['employee_username']) ?></div>
                                        <div class="employee-name"><?= h($emp['employee_name']) ?></div>
                                        <div class="text-muted small">
                                            <div><strong>Team:</strong> <?= h($emp['team_name'] ?? '—') ?></div>
                                            <div><strong>Designation:</strong> <?= h($emp['designation_name'] ?? '—') ?></div>
                                            <div>
                                                <strong>Process:</strong> 
                                                <?php if ($emp['process']): ?>
                                                    <span class="badge bg-primary process-badge"><?= h($emp['process']) ?></span>
                                                <?php endif; ?>
                                                <?php if ($emp['process1']): ?>
                                                    <span class="badge bg-info process-badge"><?= h($emp['process1']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <?php 
                                    $employee_total = 0;
                                    $current_week = null;
                                    $week_total = 0;
                                    $col_index = 0;
                                    ?>
                                    
                                    <!-- Daily Counts -->
                                    <?php foreach($dates as $date): 
                                        $week_no = getWeekNumber($date);
                                        
                                        // Check for week change
                                        if ($current_week !== null && $current_week !== $week_no) {
                                            // Output weekly total
                                            echo "<td class='weekly-total text-center'><strong>" . number_format($week_total) . "</strong></td>";
                                            $week_total = 0;
                                        }
                                        
                                        $current_week = $week_no;
                                        $daily_count = $daily_totals[$emp_id][$date] ?? 0;
                                        $employee_total += $daily_count;
                                        $week_total += $daily_count;
                                    ?>
                                        <td class="text-center <?= $daily_count > 0 ? 'daily-total' : '' ?>">
                                            <?= $daily_count > 0 ? number_format($daily_count) : '—' ?>
                                        </td>
                                    <?php endforeach; ?>
                                    
                                    <!-- Weekly total for last week -->
                                    <?php if ($current_week !== null): ?>
                                        <td class="weekly-total text-center">
                                            <strong><?= number_format($week_total) ?></strong>
                                        </td>
                                    <?php endif; ?>
                                    
                                    <!-- Grand Total -->
                                    <td class="total-cell text-center">
                                        <strong><?= number_format($employee_total) ?></strong>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <!-- Totals Row -->
                                <!-- <tr class="table-dark">
                                    <td class="sticky-col" style="background-color: #343a40; color: white;"> -->
                                        <tr style="background-color: #707981 !important; color: white !important;">
                                        <td class="sticky-col" style="background-color: #707981 !important; color: white;">
                                        <strong>TOTALS</strong>
                                    </td>
                                    <?php 
                                    $grand_total = 0;
                                    $current_week = null;
                                    $week_column_total = 0;
                                    $day_totals = [];
                                    
                                    // Initialize day totals
                                    foreach($dates as $date) {
                                        $day_totals[$date] = 0;
                                    }
                                    
                                    // Calculate totals
                                    foreach($employee_details as $emp_id => $emp) {
                                        foreach($dates as $date) {
                                            $daily_count = $daily_totals[$emp_id][$date] ?? 0;
                                            $day_totals[$date] += $daily_count;
                                            $grand_total += $daily_count;
                                        }
                                    }
                                    
                                    // Output daily totals and calculate week totals
                                    foreach($dates as $date): 
                                        $week_no = getWeekNumber($date);
                                        
                                        if ($current_week !== null && $current_week !== $week_no) {
                                            echo "<td class='text-center'><strong>" . number_format($week_column_total) . "</strong></td>";
                                            $week_column_total = 0;
                                        }
                                        
                                        $current_week = $week_no;
                                        $week_column_total += $day_totals[$date];
                                    ?>
                                        <td class="text-center">
                                            <strong><?= number_format($day_totals[$date]) ?></strong>
                                        </td>
                                    <?php endforeach; ?>
                                    
                                    <!-- Weekly total for last week -->
                                    <?php if ($current_week !== null): ?>
                                        <td class="text-center">
                                            <strong><?= number_format($week_column_total) ?></strong>
                                        </td>
                                    <?php endif; ?>
                                    
                                    <!-- Grand Total -->
                                    <td class="text-center">
                                        <strong><?= number_format($grand_total) ?></strong>
                                    </td>
                                </tr>
                                
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?= count($dates) + 3 ?>" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-chart-bar fa-3x mb-3"></i>
                                            <h5>No data found</h5>
                                            <p>No word count data available for the selected filters.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Statistics Section -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">Top Performers (Total Words)</h6>
                    </div>
                    <div class="card-body">
                        <?php 
                        $employee_totals = [];
                        foreach($employee_details as $emp_id => $emp) {
                            $total = 0;
                            foreach($dates as $date) {
                                $total += $daily_totals[$emp_id][$date] ?? 0;
                            }
                            if ($total > 0) {
                                $employee_totals[$emp_id] = $total;
                            }
                        }
                        arsort($employee_totals);
                        $top_performers = array_slice($employee_totals, 0, 5, true);
                        ?>
                        
                        <?php if (!empty($top_performers)): ?>
                            <ol class="list-group list-group-numbered">
                                <?php foreach($top_performers as $emp_id => $total): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                    <div class="ms-2 me-auto">
                                        <div class="fw-bold"><?= h($employee_details[$emp_id]['employee_name']) ?></div>
                                        <small class="text-muted"><?= h($employee_details[$emp_id]['team_name'] ?? '—') ?></small>
                                    </div>
                                    <span class="badge bg-primary rounded-pill"><?= number_format($total) ?></span>
                                </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">No word count data available</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">Weekly Summary</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Week</th>
                                        <th>Date Range</th>
                                        <th class="text-end">Total Words</th>
                                        <th class="text-end">Avg per Day</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $week_summary = [];
                                    foreach($week_numbers as $week) {
                                        $week_total = 0;
                                        $week_days = 0;
                                        
                                        foreach($dates as $date) {
                                            if (getWeekNumber($date) == $week) {
                                                foreach($employee_details as $emp_id => $emp) {
                                                    $week_total += $daily_totals[$emp_id][$date] ?? 0;
                                                }
                                                $week_days++;
                                            }
                                        }
                                        
                                        if ($week_total > 0) {
                                            $week_summary[$week] = [
                                                'total' => $week_total,
                                                'days' => $week_days,
                                                'avg' => $week_days > 0 ? round($week_total / $week_days) : 0
                                            ];
                                        }
                                    }
                                    
                                    foreach($week_summary as $week => $data):
                                    ?>
                                    <tr>
                                        <td><strong>Week <?= $week ?></strong></td>
                                        <td><?= getWeekRange($dates[array_search($week, array_column(array_map(function($d) { 
                                            return ['date' => $d, 'week' => getWeekNumber($d)]; 
                                        }, $dates), 'week'))] ?? $dates[0]) ?></td>
                                        <td class="text-end"><strong><?= number_format($data['total']) ?></strong></td>
                                        <td class="text-end"><?= number_format($data['avg']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTable
            $('#reportTable').DataTable({
                paging: false,
                searching: false,
                info: false,
                ordering: false,
                scrollX: true,
                scrollY: '60vh',
                scrollCollapse: true,
                fixedColumns: {
                    left: 1
                }
            });
            
            // Auto-update end date when start date changes
            $('input[name="start_date"]').change(function() {
                var startDate = new Date(this.value);
                var endDate = new Date($('input[name="end_date"]').val());
                
                if (startDate > endDate) {
                    // Set end date to 30 days after start date
                    var newEndDate = new Date(startDate);
                    newEndDate.setDate(newEndDate.getDate() + 30);
                    $('input[name="end_date"]').val(newEndDate.toISOString().split('T')[0]);
                }
            });
        });
        
        function exportToExcel() {
            // Create a simple Excel export
            let table = document.getElementById('reportTable');
            let html = table.outerHTML;
            
            // Create a Blob with the HTML content
            let blob = new Blob([html], { type: 'application/vnd.ms-excel' });
            
            // Create a download link
            let link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'WordCount_Report_<?= date('Y-m-d') ?>.xls';
            link.click();
        }
    </script>
</body>
</html>