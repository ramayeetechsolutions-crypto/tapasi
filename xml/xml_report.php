<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require 'db.php';

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Check if user is logged in
/*if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'team_leader') {
    header('Location: login.php');
    exit;
}*/

// Use hardcoded array for processes consistently
$processes_array = [
    ['process_id' => 1, 'process_name' => 'MK JATS'],
    ['process_id' => 2, 'process_name' => 'JATS QC'],
    ['process_id' => 3, 'process_name' => 'PMC JATS QC'],
    ['process_id' => 4, 'process_name' => 'AOP'],
    ['process_id' => 5, 'process_name' => 'AOP QC'], 
    ['process_id' => 6, 'process_name' => 'Unique'],
    ['process_id' => 7, 'process_name' => 'M2'],
    ['process_id' => 8, 'process_name' => 'Table'],
    ['process_id' => 9, 'process_name' => 'Table QC'],
    ['process_id' => 10, 'process_name' => 'Word'],
    ['process_id' => 11, 'process_name' => 'TML/PMC JATS']
];

// Generate mapping dictionary to match Process IDs correctly
$process_map = [];
foreach ($processes_array as $p) {
    $process_map[$p['process_id']] = $p['process_name'];
    $process_map[$p['process_name']] = $p['process_name'];
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

$current_user = $_SESSION['username'] ?? '';

// Get employee name from employee_master table
$employee_username = $current_user;
$current_user_name = '';

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
$start_date = $_GET['start_date'] ?? date('Y-m-01'); 
$end_date = $_GET['end_date'] ?? date('Y-m-t'); 
$employee_id = $_GET['employee_id'] ?? '';
$process = $_GET['process'] ?? '';
$team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : '';

// Get current team leader's team
$team_leader_id = $_SESSION['uid'] ?? 0;
if ($team_leader_id > 0) {
    $team_info = $mysqli->query("SELECT team_id FROM employee_master WHERE employee_id = $team_leader_id")->fetch_assoc();
    $current_team_id = $team_info['team_id'] ?? 0;
} else {
    $current_team_id = 0;
}

if (empty($team_id) && $current_team_id > 0) {
    $team_id = $current_team_id;
}

// Build base queries
$employee_cond = "em.status = 'Active'";
$count_cond = "target_date BETWEEN '$start_date' AND '$end_date'";

if (!empty($employee_id)) {
    $employee_cond .= " AND em.employee_id = " . (int)$employee_id;
}
if (!empty($process)) {
    $employee_cond .= " AND (em.process LIKE '%$process%' OR em.process1 LIKE '%$process%')";
}
if (!empty($team_id)) {
    $employee_cond .= " AND em.team_id = " . (int)$team_id;
}

// Initialize arrays
$employee_details = [];
$process_daily_achieved = [];
$process_targets = [];

// 1. Fetch Employees
$employees_sql = "SELECT em.employee_id, em.employee_name, em.employee_username, em.process, em.process1, 
                  em.team_id, t.team_name, em.designation_id, d.designation_name
                  FROM employee_master em
                  LEFT JOIN team_master t ON em.team_id = t.team_id
                  LEFT JOIN designation_master d ON em.designation_id = d.designation_id
                  WHERE reporting_manager='$current_user_name' AND $employee_cond
                  ORDER BY em.process, em.employee_name";
$employees_result = $mysqli->query($employees_sql);

if ($employees_result && $employees_result->num_rows > 0) {
    while ($emp = $employees_result->fetch_assoc()) {
        $employee_details[$emp['employee_id']] = $emp;
    }
}

// 2. Fetch Fixed Targets Grouped by Process
$pt_sql = "SELECT tm_id, process_id, SUM(target_limit) as total_limit FROM daily_targets_xml GROUP BY tm_id, process_id";
$pt_res = @$mysqli->query($pt_sql); 

if ($pt_res) {
    while ($row = $pt_res->fetch_assoc()) {
        $tm_id = $row['tm_id'];
        $raw_pid = $row['process_id'] ?? '';
        $p_name = $process_map[$raw_pid] ?? (string)$raw_pid; 
        if (empty(trim($p_name))) $p_name = 'Unassigned';
        $process_targets[$tm_id][$p_name] = (int)$row['total_limit'];
    }
}

// 3. Fetch Daily Achieved Grouped by Process
$pa_sql = "SELECT target_date as date, tm_id, process_id, SUM(achieved_target) as daily_achieved
           FROM daily_achieved_xml 
           WHERE $count_cond
           GROUP BY target_date, tm_id, process_id";
$pa_res = @$mysqli->query($pa_sql);

if ($pa_res) {
    while ($row = $pa_res->fetch_assoc()) {
        $date = $row['date'];
        $tm_id = $row['tm_id'];
        $raw_pid = $row['process_id'] ?? '';
        $p_name = $process_map[$raw_pid] ?? (string)$raw_pid; 
        if (empty(trim($p_name))) $p_name = 'Unassigned';
        
        $ach = (int)$row['daily_achieved'];
        if (!isset($process_daily_achieved[$tm_id][$p_name][$date])) {
            $process_daily_achieved[$tm_id][$p_name][$date] = 0;
        }
        $process_daily_achieved[$tm_id][$p_name][$date] += $ach;
    }
}

// Get all unique dates
$date_range_sql = "SELECT DISTINCT target_date as date FROM daily_achieved_xml 
                   WHERE target_date BETWEEN '$start_date' AND '$end_date'
                   ORDER BY target_date";
$date_result = $mysqli->query($date_range_sql);
$dates = [];
while ($row = $date_result->fetch_assoc()) {
    $dates[] = $row['date'];
}

// Use actual duration for targeting
$start_ts = strtotime($start_date);
$end_ts = strtotime($end_date);
$num_days = max(1, floor(($end_ts - $start_ts) / 86400) + 1);


/* =========================================
   PREPARE DATA FOR CHARTS
========================================= */

// Overall Employee Chart arrays (Line)
$emp_chart_labels = [];
$emp_chart_percentages = []; // Holds the SUM of all (*100) percentages

// Individual Process Breakdowns arrays (Bar)
$emp_proc_datasets_raw = [];

// Initialize structure for Bar dataset
foreach($processes_array as $proc) {
    $emp_proc_datasets_raw[$proc['process_name']] = [
        'label' => $proc['process_name'],
        'data' => [],
        'raw_achieved' => [],
        'raw_target' => [],
        'has_data' => false
    ];
}

foreach($employee_details as $emp_id => $emp) {
    $sum_of_percentages = 0;
    $has_any_data = false;
    
    foreach($processes_array as $proc) {
        $p_name = $proc['process_name'];
        $p_achieved = 0;
        
        foreach($dates as $date) {
            $p_achieved += $process_daily_achieved[$emp_id][$p_name][$date] ?? 0;
        }
        
        $p_daily_target = $process_targets[$emp_id][$p_name] ?? 0;
        $p_total_target = $p_daily_target * $num_days;
        
        // Calculate (Achieved / Target) * 100 for THIS SPECIFIC PROCESS
        $p_percent = 0;
        if ($p_total_target > 0) {
            $p_percent = ($p_achieved / $p_total_target) * 100;
            $sum_of_percentages += $p_percent;
        } elseif ($p_achieved > 0) {
            $p_percent = 100;
            $sum_of_percentages += 100;
        }
        
        // Push process specific data into Bar Chart array
        $emp_proc_datasets_raw[$p_name]['data'][] = round($p_percent, 1);
        $emp_proc_datasets_raw[$p_name]['raw_achieved'][] = $p_achieved;
        $emp_proc_datasets_raw[$p_name]['raw_target'][] = $p_total_target;
        
        if ($p_total_target > 0 || $p_achieved > 0) {
            $emp_proc_datasets_raw[$p_name]['has_data'] = true;
            $has_any_data = true;
        }
    }
    
    // Push Overall sum into Line Chart arrays
    if ($has_any_data) {
        $emp_chart_labels[] = $emp['employee_name'];
        $emp_chart_percentages[] = round($sum_of_percentages, 0); 
    }
}

// Filter out processes that have NO data to keep Bar Chart clean, assign distinct colors
$emp_proc_final_datasets = [];
$color_palette = ['#0d6efd', '#6610f2', '#6f42c1', '#d63384', '#dc3545', '#fd7e14', '#ffc107', '#198754', '#20c997', '#0dcaf0'];
$c_idx = 0;

foreach($emp_proc_datasets_raw as $p_name => $ds) {
    if ($ds['has_data']) {
        $color = $color_palette[$c_idx % count($color_palette)];
        $emp_proc_final_datasets[] = [
            'label' => $p_name,
            'data' => $ds['data'],
            'raw_achieved' => $ds['raw_achieved'], // Used for tooltip
            'raw_target' => $ds['raw_target'],     // Used for tooltip
            'backgroundColor' => $color . 'CC',    // 80% opacity
            'borderColor' => $color,
            'borderWidth' => 1
        ];
        $c_idx++;
    }
}

// Dropdowns
$teams_sql = "SELECT team_id, team_name FROM team_master ORDER BY team_name";
$teams_result = $mysqli->query($teams_sql);
$all_emp_sql = "SELECT employee_id, employee_name FROM employee_master WHERE reporting_manager='$current_user_name' AND status='Active' ORDER BY employee_name";
$all_emp_result = $mysqli->query($all_emp_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Performance Charts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { padding: 20px; margin: 0 auto; }
        .card { box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); border: 1px solid rgba(0, 0, 0, 0.125); margin-bottom: 20px; background-color: white; border-radius: 10px; }
        .filter-box { background-color: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); border: 1px solid rgba(0, 0, 0, 0.125); }
        .chart-container { position: relative; height: 450px; width: 100%; padding: 15px; }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <br><br><br>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-chart-bar me-2"></i>Employee Performance Charts</h4>
            <div class="btn-group">
                <a href="team_count_report.php" class="btn btn-outline-primary">
                    <i class="fas fa-table me-1"></i> Switch to Grid View
                </a>
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
                        <?php while($emp = $all_emp_result->fetch_assoc()): ?>
                            <option value="<?= $emp['employee_id'] ?>" <?= $employee_id == $emp['employee_id'] ? 'selected' : '' ?>>
                                <?= h($emp['employee_name']) ?>
                            </option>
                        <?php endwhile; ?>
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
                        <?php foreach($processes_array as $proc): ?>
                            <option value="<?= $proc['process_name'] ?>" <?= $process == $proc['process_name'] ? 'selected' : '' ?>>
                                <?= h($proc['process_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 mt-3">
                    <button type="submit" class="btn btn-primary me-2"><i class="fas fa-filter me-1"></i> Apply Filters</button>
                    <a href="<?= basename($_SERVER['PHP_SELF']) ?>" class="btn btn-secondary"><i class="fas fa-times me-1"></i> Clear Filters</a>
                </div>
            </form>
        </div>

        <div class="row">
            <!-- Chart 1: Bar Chart (Employee-Wise Process Breakdown) -->
            <div class="col-12 mb-4">
                <div class="card border-primary">
                    <div class="card-header bg-white border-primary">
                        <h5 class="mb-0 text-primary"><i class="fas fa-bars me-2"></i>Employee Process Performance (Bar Chart)</h5>
                        <small class="text-muted">Stacked bars representing individual process calculation: (Achieved / Target) * 100</small>
                    </div>
                    <div class="card-body chart-container">
                        <?php if (empty($emp_chart_labels) || empty($emp_proc_final_datasets)): ?>
                            <div class="d-flex justify-content-center align-items-center h-100 text-muted">
                                <div><i class="fas fa-chart-bar fa-3x mb-3 d-block text-center"></i>No data available.</div>
                            </div>
                        <?php else: ?>
                            <canvas id="employeeBarChart"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Chart 2: Line Chart (Overall Employee Sum) -->
            <div class="col-12 mb-4">
                <div class="card border-success">
                    <div class="card-header bg-white border-success">
                        <h5 class="mb-0 text-success"><i class="fas fa-chart-line me-2"></i>Overall Employee Performance (Line Chart)</h5>
                        <small class="text-muted">Summing the percentages: SUM(ARRAYFORMULA(Achieved / Target * 100))</small>
                    </div>
                    <div class="card-body chart-container">
                        <?php if (empty($emp_chart_labels)): ?>
                            <div class="d-flex justify-content-center align-items-center h-100 text-muted">
                                <div><i class="fas fa-chart-line fa-3x mb-3 d-block text-center"></i>No data available.</div>
                            </div>
                        <?php else: ?>
                            <canvas id="employeeLineChart"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        $(document).ready(function() {
            $('input[name="start_date"]').change(function() {
                var startDate = new Date(this.value);
                var endDate = new Date($('input[name="end_date"]').val());
                if (startDate > endDate) {
                    var newEndDate = new Date(startDate);
                    newEndDate.setDate(newEndDate.getDate() + 30);
                    $('input[name="end_date"]').val(newEndDate.toISOString().split('T')[0]);
                }
            });

            <?php if (!empty($emp_chart_labels)): ?>
            // ----------------------------------------------------
            // 1. BAR CHART: Employee Process Breakdowns (Stacked)
            // ----------------------------------------------------
            new Chart(document.getElementById('employeeBarChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode($emp_chart_labels) ?>,
                    datasets: <?= json_encode($emp_proc_final_datasets) ?>
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { 
                        legend: { position: 'top' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    let percent = context.raw;
                                    let achieved = context.dataset.raw_achieved[context.dataIndex];
                                    let target = context.dataset.raw_target[context.dataIndex];
                                    
                                    if(target === 0 && achieved === 0) return null;
                                    
                                    // Shows exactly like your formula: (A / T * 100)
                                    return `${label}: ${percent}% (${achieved} / ${target} * 100)`;
                                }
                            },
                            filter: function(tooltipItem) {
                                let achieved = tooltipItem.dataset.raw_achieved[tooltipItem.dataIndex];
                                let target = tooltipItem.dataset.raw_target[tooltipItem.dataIndex];
                                return (target > 0 || achieved > 0);
                            }
                        }
                    },
                    scales: {
                        x: { stacked: true },
                        y: { 
                            stacked: true,
                            beginAtZero: true, 
                            title: { display: true, text: 'Percentage (%)' }
                        }
                    }
                }
            });

            // ----------------------------------------------------
            // 2. LINE CHART: Employee Overall Sum
            // ----------------------------------------------------
            new Chart(document.getElementById('employeeLineChart').getContext('2d'), {
                type: 'line',
                data: {
                    labels: <?= json_encode($emp_chart_labels) ?>,
                    datasets: [
                        {
                            label: 'Sum of Process Percentages (%)',
                            data: <?= json_encode($emp_chart_percentages) ?>,
                            backgroundColor: 'rgba(25, 135, 84, 0.2)', // Light Green Fill
                            borderColor: 'rgb(25, 135, 84)',           // Dark Green Line
                            borderWidth: 3,
                            pointBackgroundColor: 'rgb(25, 135, 84)',
                            pointRadius: 5,
                            fill: true, 
                            tension: 0.3
                        }
                    ]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Total Overall: ${context.raw}%`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Overall Summed %' }
                        }
                    }
                } 
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>