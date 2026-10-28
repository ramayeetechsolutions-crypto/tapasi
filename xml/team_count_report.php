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

// Use hardcoded array for processes instead of process_master
$processes_array = [
    ['process_id' => 1, 'process_name' => 'MK JATS'],
    ['process_id' => 2, 'process_name' => 'JATS QC'],
    ['process_id' => 11, 'process_name' => 'PMC JATS'], 
    ['process_id' => 3, 'process_name' => 'PMC JATS QC'],
    ['process_id' => 4, 'process_name' => 'AOP'],
    ['process_id' => 5, 'process_name' => 'AOP QC'],
    ['process_id' => 6, 'process_name' => 'UQ JATS'], 
    ['process_id' => 12, 'process_name' => 'DOAJ /Biblat /PubMed'], 
    ['process_id' => 7, 'process_name' => 'M2'],
    ['process_id' => 13, 'process_name' => 'HTML'], 
    ['process_id' => 8, 'process_name' => 'Table'],
    ['process_id' => 9, 'process_name' => 'Table QC'],
    ['process_id' => 10, 'process_name' => 'Word']
];

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
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

// Get current team leader's team
$team_leader_id = $_SESSION['uid'] ?? 0;
if ($team_leader_id > 0) {
    $team_info = $mysqli->query("SELECT team_id FROM employee_master WHERE employee_id = $team_leader_id")->fetch_assoc();
    $current_team_id = $team_info['team_id'] ?? 0;
} else {
    $current_team_id = 0;
}

// If team_id not specified, use team leader's team
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

// Generate mapping dictionary to match Process IDs from targets/achievements correctly
$process_map = [];
foreach ($processes_array as $p) {
    $process_map[$p['process_id']] = $p['process_name'];
    $process_map[$p['process_name']] = $p['process_name']; // Duplicate safely maps if string is recorded
}

// Initialize core arrays
$employee_details = [];
$daily_totals = [];
$process_daily_achieved = [];
$process_targets = [];
$fixed_targets = [];

// NEW: TL has direct reports (sees own employees). TM has none (sees whole team).
$safe_name = $mysqli->real_escape_string($current_user_name);
$is_tl = false;
$tl_check = $mysqli->query("SELECT 1 FROM employee_master WHERE reporting_manager='$safe_name' AND status='Active' LIMIT 1");
if ($tl_check && $tl_check->num_rows > 0) {
    $is_tl = true;
}
$manager_cond = $is_tl ? "em.reporting_manager='$safe_name'" : "1=1";

// 1. Fetch Employees
$employees_sql = "SELECT em.employee_id, em.employee_name, em.employee_username, em.process, em.process1, 
                  em.team_id, t.team_name, em.designation_id, d.designation_name
                  FROM employee_master em
                  LEFT JOIN team_master t ON em.team_id = t.team_id
                  LEFT JOIN designation_master d ON em.designation_id = d.designation_id
                  WHERE $manager_cond AND $employee_cond
                  ORDER BY em.process, em.employee_name";
$employees_result = $mysqli->query($employees_sql);

if ($employees_result && $employees_result->num_rows > 0) {
    while ($emp = $employees_result->fetch_assoc()) {
        $employee_details[$emp['employee_id']] = $emp;
    }
}

// 2. Fetch Fixed Targets (Grouped by Process dynamically)
$pt_sql = "SELECT tm_id, process_id, MAX(target_limit) as total_limit FROM daily_targets_xml GROUP BY tm_id, process_id";
$pt_res = @$mysqli->query($pt_sql); 

if ($pt_res) {
    while ($row = $pt_res->fetch_assoc()) {
        $tm_id = $row['tm_id'];
        $raw_pid = $row['process_id'] ?? '';
        $p_name = $process_map[$raw_pid] ?? (string)$raw_pid; 
        
        if (empty(trim($p_name))) $p_name = 'Unassigned';
        
        $process_targets[$tm_id][$p_name] = (int)$row['total_limit'];
        
        if (!isset($fixed_targets[$tm_id])) $fixed_targets[$tm_id] = 0;
        $fixed_targets[$tm_id] += (int)$row['total_limit'];
    }
} else {
    // Fallback if 'process' column isn't in target table
    $ft_sql = "SELECT tm_id, MAX(target_limit) as total_limit FROM daily_targets_xml GROUP BY tm_id";
    $ft_res = $mysqli->query($ft_sql);
    if ($ft_res) {
        while ($row = $ft_res->fetch_assoc()) {
            $fixed_targets[$row['tm_id']] = (int)$row['total_limit'];
        }
    }
}

// 3. Fetch Daily Achieved (Grouped by Process dynamically)
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
        
        if (!isset($daily_totals[$tm_id][$date])) $daily_totals[$tm_id][$date] = 0;
        $daily_totals[$tm_id][$date] += $ach;
    }
} else {
    // Fallback if 'process' column isn't in achieved table
    $da_sql = "SELECT target_date as date, tm_id, SUM(achieved_target) as daily_achieved
               FROM daily_achieved_xml 
               WHERE $count_cond
               GROUP BY target_date, tm_id";
    $da_res = $mysqli->query($da_sql);
    if ($da_res) {
        while ($row = $da_res->fetch_assoc()) {
            $date = $row['date'];
            $tm_id = $row['tm_id'];
            $ach = (int)$row['daily_achieved'];
            
            if (!isset($daily_totals[$tm_id][$date])) $daily_totals[$tm_id][$date] = 0;
            $daily_totals[$tm_id][$date] += $ach;
        }
    }
}

// Get all unique dates in range for table headers
$date_range_sql = "SELECT DISTINCT target_date as date FROM daily_achieved_xml 
                   WHERE target_date BETWEEN '$start_date' AND '$end_date'
                   ORDER BY target_date";
$date_result = $mysqli->query($date_range_sql);
$dates = [];
while ($row = $date_result->fetch_assoc()) {
    $dates[] = $row['date'];
}

// Group Employees by their Primary Process (For Main Daily Table)
$grouped_employees = [];
if (!empty($employee_details)) {
    foreach($employee_details as $emp_id => $emp) {
        $proc = !empty($emp['process']) ? $emp['process'] : 'Unassigned';
        $grouped_employees[$proc][$emp_id] = $emp;
    }
}

// Master tables for dropdowns
$teams_sql = "SELECT team_id, team_name FROM team_master ORDER BY team_name";
$teams_result = $mysqli->query($teams_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Team Targets Report - Team Leader</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { padding: 20px; margin: 0 auto; }
        .card { box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); border: 1px solid rgba(0, 0, 0, 0.125); margin-bottom: 20px; }
        .table th { background-color: #5e6b77 !important;  color: #ffffff !important; position: sticky; top: 0; z-index: 10; }
        .employee-name { font-weight: 600; color: #2c3e50; }
        .total-cell { background-color: #e3f2fd; }
        .daily-total { background-color: #f1f8e9; }
        .sticky-col { position: sticky; left: 0; background-color: white; z-index: 5; box-shadow: 2px 0 5px rgba(0,0,0,0.1); }
        .summary-card { color: white; border-radius: 10px; }
        .stat-number { font-size: 2rem; font-weight: bold; }
        .filter-box { background-color: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
        .table-container { width: 100%; } 
        .target-missed { color: #dc3545; }
        .target-met { color: #198754; }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <br><br><br>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-bullseye me-2"></i>Production Reports</h4>
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
                            <option value="<?= $emp['employee_id'] ?>" <?= (int)$employee_id === (int)$emp['employee_id'] ? 'selected' : '' ?>>
                                <?= h($emp['employee_name']) ?>
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


        
                <!-- NEW: day-wise view (when a specific employee is selected) -->
        <?php if ($is_tl && (int)$employee_id > 0 && count($employee_details) === 1): ?>
            <?php 
                $emp = reset($employee_details);
                $emp_id = $emp['employee_id'];
            ?>
            <div class="card mt-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Day-Wise Performance: <?= h($emp['employee_name']) ?></h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-container">
                        <table class="table table-bordered table-striped align-middle mb-0" id="dailyReportTable">
                            <thead>
                                <tr>
                                    <th class="sticky-col" style="min-width: 200px; z-index: 99 !important;">Employee Name</th>
                                    <?php foreach($processes_array as $proc): ?>
                                        <th class="text-center" style="min-width: 100px;"><?= h($proc['process_name']) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $process_totals = [];
                                foreach($processes_array as $proc) {
                                    $process_totals[$proc['process_name']] = 0;
                                }

                                foreach($dates as $date): 
                                    $daily_row_total = 0;
                                    foreach($processes_array as $proc) {
                                        $daily_row_total += $process_daily_achieved[$emp_id][$proc['process_name']][$date] ?? 0;
                                    }

                                    // skip days where the employee did no work
                                    if ($daily_row_total == 0) {
                                        continue; 
                                    }
                                ?>
                                <tr>
                                    <td class="sticky-col" style="background-color: white;">
                                        <div class="employee-name"><?= h($emp['employee_name']) ?></div>
                                        <div class="text-muted small">ID: <?= h($emp['employee_username']) ?></div>
                                        <div class="text-primary small fw-bold mt-1"><i class="fas fa-calendar-alt me-1"></i><?= formatDate($date) ?></div>
                                    </td>
                                    <?php foreach($processes_array as $proc): 
                                        $p_name = $proc['process_name'];
                                        $achieved = $process_daily_achieved[$emp_id][$p_name][$date] ?? 0;
                                        $process_totals[$p_name] += $achieved;
                                    ?>
                                        <td class="text-center">
                                            <?= $achieved > 0 ? number_format($achieved) : '' ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-dark">
                                <tr>
                                    <td class="sticky-col text-end fw-bold" style="background-color: #212529; color: white;">Total</td>
                                    <?php foreach($processes_array as $proc): ?>
                                        <td class="text-center fw-bold"><?= $process_totals[$proc['process_name']] > 0 ? number_format($process_totals[$proc['process_name']]) : '0' ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

        <?php else: ?>
        <!-- END NEW -->

        <!-- NEW TABLE: Process-Wise Employee Performance -->
        <div class="card mt-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">Process-Wise Performance per Employee</h5>
                    <small class="text-muted">Formula logic: SUM(ARRAYFORMULA(Achieved / Target * 100))</small>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-container">
                    <table class="table table-bordered table-striped align-middle mb-0" id="processReportTable">
                        <thead>
                            <tr>
                                <th class="sticky-col" style="min-width: 250px; z-index: 99 !important;">Employee Name</th>
                                <?php foreach($processes_array as $proc): ?>
                                    <th class="text-center" style="min-width: 150px;"><?= h($proc['process_name']) ?></th>
                                <?php endforeach; ?>
                                <th class="table-dark text-center" style="min-width: 160px;">Overall Process %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Safely calculate the actual number of days based on the date filter
                            $start_ts = strtotime($start_date);
                            $end_ts = strtotime($end_date);
                            $num_days = max(1, floor(($end_ts - $start_ts) / 86400) + 1);

                            foreach($employee_details as $emp_id => $emp): 
                                // Variables for the Spreadsheet Formula logic
                                $sum_of_percentages = 0; 
                                $emp_overall_achieved = 0;
                                $emp_overall_target = 0;
                            ?>
                            <tr>
                                <td class="sticky-col" style="background-color: white;">
                                    <div class="employee-name"><?= h($emp['employee_name']) ?></div>
                                    <div class="text-muted small">ID: <?= h($emp['employee_username']) ?></div>
                                </td>
                                
                                <?php foreach($processes_array as $proc): 
                                    $p_name = $proc['process_name'];
                                    
                                    // 1. Get Achieved for process
                                    $p_achieved = 0;
                                    foreach($dates as $date) {
                                        $p_achieved += $process_daily_achieved[$emp_id][$p_name][$date] ?? 0;
                                    }
                                    
                                    // 2. Get Target for process
                                    $p_daily_target = $process_targets[$emp_id][$p_name] ?? 0;
                                    $p_total_target = $p_daily_target * $num_days;
                                    
                                    // 3. Calculate percentage and SUM it exactly like ARRAYFORMULA
                                    $p_percent = 0;
                                    if ($p_total_target > 0) {
                                        $p_percent = ($p_achieved / $p_total_target) * 100;
                                        // Add exactly this percentage to the running total (matches ARRAYFORMULA SUM)
                                        $sum_of_percentages += $p_percent;
                                    } elseif ($p_achieved > 0) {
                                        // Achieved something but target was 0
                                        $p_percent = 100;
                                        $sum_of_percentages += 100;
                                    }
                                    
                                    // Overall totals for record keeping
                                    $emp_overall_achieved += $p_achieved;
                                    $emp_overall_target += $p_total_target;

                                    $cell_class = ($p_total_target > 0 && $p_achieved >= $p_total_target) ? 'text-success' : (($p_total_target > 0) ? 'text-danger' : 'text-muted');
                                ?>
                                    <td class="text-center">
                                        <?php if($p_total_target > 0 || $p_achieved > 0): ?>
                                            <div class="fw-bold <?= $cell_class ?> fs-6"><?= round($p_percent, 1) ?>%</div>
                                            <!-- Subtitle text formatted exactly like the screenshot calculation -->
                                            <div class="small text-muted mt-1" style="font-size: 0.75rem;">
                                                <?= number_format($p_achieved) ?> / <?= number_format($p_total_target) ?> * 100
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted opacity-50">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                
                                <?php 
                                    // 4. The final summed percentage from the formula loop
                                    $overall_percent = round($sum_of_percentages, 0); 
                                    $o_class = ($overall_percent >= 100) ? 'text-success' : 'text-dark';
                                ?>
                                <td class="table-light text-center align-middle">
                                    <?php if($emp_overall_target > 0 || $emp_overall_achieved > 0): ?>
                                        <strong class="<?= $o_class ?> fs-5"><?= $overall_percent ?>%</strong>
                                        <div class="small text-muted mt-1" style="font-size: 0.75rem;">
                                            Sum of Process %
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php endif; ?>

    </div>

    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            var commonOptions = {
                paging: false, searching: false, info: false, ordering: false,
                scrollX: true, scrollY: '60vh', scrollCollapse: true,
                fixedColumns: { left: 1 }
            };
            
            if ($('#processReportTable').length) {
                $('#processReportTable').DataTable({
                    ...commonOptions,
                    scrollY: '60vh'
                });
            }
            
                        // NEW: day-wise table
            if ($('#dailyReportTable').length) {
                $('#dailyReportTable').DataTable({
                    ...commonOptions,
                    scrollY: '60vh'
                });
            }

            $('input[name="start_date"]').change(function() {
                var startDate = new Date(this.value);
                var endDate = new Date($('input[name="end_date"]').val());
                if (startDate > endDate) {
                    var newEndDate = new Date(startDate);
                    newEndDate.setDate(newEndDate.getDate() + 30);
                    $('input[name="end_date"]').val(newEndDate.toISOString().split('T')[0]);
                }
            });
        });
    </script>
</body>
</html>