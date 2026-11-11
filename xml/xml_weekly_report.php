<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Get current user (team leader)
$current_username = $_SESSION['username'] ?? ''; // Adjust based on your session variable

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function calculateExperience($joining_date) {
    if (empty($joining_date)) return '0 Y 0 M';
    
    $join_date = new DateTime($joining_date);
    $current_date = new DateTime();
    $interval = $current_date->diff($join_date);
    
    $years = $interval->y;
    $months = $interval->m;
    
    return $years . ' Y ' . $months . ' M';
}

// Get current team leader's team_id
$team_leader_team = null;
$team_leader_name = ''; // Added to store TL name
if ($current_username) {
    $stmt = $mysqli->prepare("SELECT e.team_id, e.employee_name, t.team_name FROM employee_master e, team_master t WHERE e.employee_username = ? AND e.team_id=t.team_id AND e.status = 'Active'");
    $stmt->bind_param("s", $current_username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $team_leader_team = $row['team_id'];
        $team_leader_name = $row['employee_name']; // Fetching TL name
        $team_leader_team_name = $row['team_name'];
    }
    $stmt->close();
}

// Calculate Default Financial Week (Starting from First Monday of April)
$today = new DateTime();
$curr_y = (int)$today->format('Y');
if ((int)$today->format('n') < 4) { $curr_y--; } // If Jan-Mar, use previous year's April
$fy_start_date = new DateTime("first monday of april $curr_y");
$days_passed = $fy_start_date->diff($today)->days;
$default_week = floor($days_passed / 7) + 1;

// Get parameters
$week = $_GET['week'] ?? $default_week;
$year = $_GET['year'] ?? date('Y');


// Handle Excel Download - Simple .xls without libraries
if (isset($_GET['download']) && $_GET['download'] == 'excel') {
    // Fetch data for Excel with designation name and process name
    $week_str = "Week " . (int)$week . ", " . (int)$year;
    
    $sql = "SELECT 
        em.employee_id,
        em.employee_name,
        em.employee_username,
        em.designation_id,
        em.joining_date,
        COALESCE(wt.target_limit, 0) as target_limit,
        COALESCE(wt.achieved_target, 0) as achieved_target,
        COALESCE(ec.error_count, 0) as error_count,
        pm.process_name,
        d.designation_name
    FROM employee_master em
    LEFT JOIN weekly_targets wt ON em.employee_username = wt.tm_id AND wt.week_number = ? AND wt.year = ?
    LEFT JOIN (
        SELECT 
            error_made_by AS employee_username,
            COUNT(*) as error_count
        FROM error_log
        WHERE week = ? AND status = 'Active'
        GROUP BY error_made_by
) ec ON em.employee_username = ec.employee_username COLLATE utf8mb4_general_ci
    LEFT JOIN process_master pm ON em.designation_id = pm.process_id AND pm.status = 'Active'
    LEFT JOIN designation_master d ON em.designation_id = d.designation_id AND d.status = 'Active'
    WHERE em.status = 'Active' 
    AND em.reporting_manager = ?
    ORDER BY em.employee_username ASC";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("iiis", $week, $year, $week_str, $team_leader_name);
    $stmt->execute();
    $excel_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Set filename
    $filename = "Weekly_Report_Week_{$week}_{$year}_Team_{$team_leader_team_name}.xls";
    
    // Set headers for download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Start HTML table (Excel can open HTML as .xls)
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; }';
    echo 'th { background-color: #2c3e50; color: white; font-weight: bold; padding: 8px; border: 1px solid #ddd; text-align: center; }';
    echo 'td { padding: 8px; border: 1px solid #ddd; }';
    echo '.title { font-size: 18px; font-weight: bold; text-align: center; background-color: #f2f2f2; padding: 10px; }';
    echo '.subtitle { text-align: center; padding: 5px; }';
    echo '.header-row { background-color: #f8f9fa; }';
    echo '.summary-row { background-color: #e9ecef; font-weight: bold; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // Title
    echo '<table>';
    echo '<tr><td colspan="14" class="title">WEEKLY PRODUCTION REPORT</td></tr>';
    echo '<tr><td colspan="14" class="subtitle">Week: ' . $week . ', Year: ' . $year . '</td></tr>';
    echo '<tr><td colspan="14" class="subtitle">Team: ' . $team_leader_team_name . '</td></tr>';
    echo '<tr><td colspan="14">&nbsp;</td></tr>';
    
    // Table headers
    echo '<tr class="header-row">';
    echo '<th>S.No</th>';
    echo '<th>Employee ID</th>';
    echo '<th>Employee Name</th>';
    echo '<th>Experience</th>';
    echo '<th>Designation</th>';
    echo '<th>Process</th>';
    echo '<th>Target</th>';
    echo '<th>Achieved</th>';
    echo '<th>Points</th>';
    echo '<th>Errors</th>';
    echo '<th>Error Points</th>';
    echo '<th>Production %</th>';
    echo '<th>Accuracy %</th>';
    echo '<th>NET Prod %</th>';
    echo '</tr>';
    
    // Initialize totals
    $team_totals = [
        'target' => 0,
        'achieved' => 0,
        'points' => 0,
        'errors' => 0,
        'error_points' => 0
    ];
    
    // Write data rows
    $sno = 1;
    foreach ($excel_data as $emp) {
        // Calculate metrics
        $achieved = $emp['achieved_target'] > 0 ? $emp['achieved_target'] : 0;
        $points = $achieved / 250;
        $error_points = $emp['error_count'];
        $production_percentage = $emp['target_limit'] > 0 ? ($achieved / $emp['target_limit']) * 100 : 0;
        
        // Calculate accuracy
        if ($achieved > 0) {
            $accuracy_percentage = (($achieved - ($emp['error_count'] * 100)) / $achieved) * 100;
            $accuracy_percentage = max(0, min(100, $accuracy_percentage));
        } else {
            $accuracy_percentage = 0;
        }
        
        $net_prod = ($production_percentage * $accuracy_percentage) / 100;
        
        // Add to team totals
        $team_totals['target'] += $emp['target_limit'];
        $team_totals['achieved'] += $achieved;
        $team_totals['points'] += $points;
        $team_totals['errors'] += $emp['error_count'];
        $team_totals['error_points'] += $error_points;
        
        echo '<tr>';
        echo '<td>' . $sno++ . '</td>';
        echo '<td>' . htmlspecialchars($emp['employee_username']) . '</td>';
        echo '<td>' . htmlspecialchars($emp['employee_name']) . '</td>';
        echo '<td>' . htmlspecialchars(calculateExperience($emp['joining_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($emp['designation_name'] ?: $emp['designation_id']) . '</td>';
        echo '<td>' . htmlspecialchars($emp['process_name'] ?: $emp['designation_id']) . '</td>';
        echo '<td>' . number_format($emp['target_limit']) . '</td>';
        echo '<td>' . number_format($achieved) . '</td>';
        echo '<td>' . number_format($points, 2) . '</td>';
        echo '<td>' . $emp['error_count'] . '</td>';
        echo '<td>' . $error_points . '</td>';
        echo '<td>' . number_format($production_percentage, 1) . '%</td>';
        echo '<td>' . number_format($accuracy_percentage, 1) . '%</td>';
        echo '<td>' . number_format($net_prod, 1) . '%</td>';
        echo '</tr>';
    }
    
    // Team summary row
    $team_avg_production = $team_totals['target'] > 0 ? ($team_totals['achieved'] / $team_totals['target']) * 100 : 0;
    if ($team_totals['achieved'] > 0) {
        $team_avg_accuracy = (($team_totals['achieved'] - ($team_totals['errors'] * 100)) / $team_totals['achieved']) * 100;
        $team_avg_accuracy = max(0, min(100, $team_avg_accuracy));
    } else {
        $team_avg_accuracy = 0;
    }
    $team_avg_net = ($team_avg_production * $team_avg_accuracy) / 100;
    
    echo '<tr class="summary-row">';
    echo '<td colspan="6" style="text-align: right;"><strong>TEAM SUMMARY:</strong></td>';
    echo '<td>' . number_format($team_totals['target']) . '</td>';
    echo '<td>' . number_format($team_totals['achieved']) . '</td>';
    echo '<td>' . number_format($team_totals['points'], 2) . '</td>';
    echo '<td>' . $team_totals['errors'] . '</td>';
    echo '<td>' . $team_totals['error_points'] . '</td>';
    echo '<td>' . number_format($team_avg_production, 1) . '%</td>';
    echo '<td>' . number_format($team_avg_accuracy, 1) . '%</td>';
    echo '<td>' . number_format($team_avg_net, 1) . '%</td>';
    echo '</tr>';
    
    echo '</table>';
    echo '</body>';
    echo '</html>';
    exit();
}

// Get start and end dates for the selected week to fetch XML data (Starting from April First Monday)
$dto = new DateTime("first monday of april $year");

$week_offset = (int)$week - 1;
if ($week_offset > 0) {
    $dto->modify("+$week_offset weeks");
}

$start_date = $dto->format('Y-m-d');
$dto->modify('+5 days'); // Monday to Saturday (6 days)
$end_date = $dto->format('Y-m-d');
$week_str = "Week " . (int)$week . ", " . (int)$year;

// 1. Fetch Process Master Mapping (To convert IDs like 5,45 to Names)
$pm_sql = "SELECT process_id, process_name FROM process_master";
$pm_result = $mysqli->query($pm_sql);
$process_map = [];
if ($pm_result) {
    while($row = $pm_result->fetch_assoc()) {
        if (!empty($row['process_name'])) {
            $process_map[$row['process_id']] = trim($row['process_name']);
        }
    }
}

// Fetch Employees (Filtered by reporting_manager to match Assignment Page)
$employees_sql = "SELECT em.employee_id, em.employee_name, em.employee_username, em.team_id, em.joining_date, em.process
                  FROM employee_master em
                  WHERE em.status = 'Active' AND em.reporting_manager = ?
                  ORDER BY em.employee_username ASC";
$stmt = $mysqli->prepare($employees_sql);
$stmt->bind_param("s", $team_leader_name);
$stmt->execute();
$employees_res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 2. Fetch Latest Targets per employee from daily_targets_xml
$targets_sql = "SELECT t1.tm_id, SUM(t1.target_limit) as total_daily_target
                FROM daily_targets_xml t1
                INNER JOIN (
                    SELECT tm_id, process_id, MAX(id) as latest_id
                    FROM daily_targets_xml
                    GROUP BY tm_id, process_id
                ) t2 ON t1.id = t2.latest_id
                GROUP BY t1.tm_id";
$targets_res = $mysqli->query($targets_sql);
        $employee_targets = [];
        while ($row = $targets_res->fetch_assoc()) {
            $employee_targets[$row['tm_id']] = $row['total_daily_target']; // Removed * 6 to show exact total from assignment
}

// 3. Fetch Achieved from daily_achieved_xml
$achieved_sql = "SELECT tm_id, SUM(achieved_target) as total_achieved
                 FROM daily_achieved_xml
                 WHERE target_date BETWEEN '$start_date' AND '$end_date'
                 GROUP BY tm_id";
$achieved_res = $mysqli->query($achieved_sql);
$employee_achieved = [];
while ($row = $achieved_res->fetch_assoc()) {
    $employee_achieved[$row['tm_id']] = $row['total_achieved'];
}

// 4. Fetch Errors (Fixed Week Match & mapping by Name)
$formatted_week = sprintf("%02d", $week); // converts 7 to '07'
$error_sql = "SELECT error_made_by,
                     SUM(CASE WHEN internal_external = 'Internal' THEN 1 ELSE 0 END) as internal_errors,
                     SUM(CASE WHEN internal_external = 'External' THEN 1 ELSE 0 END) as external_errors
              FROM error_log
              WHERE (week = '$week_str' OR week LIKE '%$week%' OR week LIKE '%$formatted_week%') AND status = 'Active'
              GROUP BY error_made_by";
$error_res = $mysqli->query($error_sql);
$employee_errors = [];
while ($row = $error_res->fetch_assoc()) {
    $employee_errors[$row['error_made_by']] = $row;
}

// ADDED: Fetch process-wise targets & achieved for accurate "Sum of Process %" calculation
$pw_targets_sql = "SELECT t1.tm_id, t1.process_id, t1.target_limit FROM daily_targets_xml t1 INNER JOIN (SELECT tm_id, process_id, MAX(id) as latest_id FROM daily_targets_xml GROUP BY tm_id, process_id) t2 ON t1.id = t2.latest_id";
$pw_targets_res = $mysqli->query($pw_targets_sql);
$pw_targets = [];
while($r = $pw_targets_res->fetch_assoc()) { $pw_targets[$r['tm_id']][$r['process_id']] = $r['target_limit']; }

$pw_achieved_sql = "SELECT tm_id, process_id, SUM(achieved_target) as proc_achieved FROM daily_achieved_xml WHERE target_date BETWEEN '$start_date' AND '$end_date' GROUP BY tm_id, process_id";
$pw_achieved_res = $mysqli->query($pw_achieved_sql);
$pw_achieved = [];
while($r = $pw_achieved_res->fetch_assoc()) { $pw_achieved[$r['tm_id']][$r['process_id']] = $r['proc_achieved']; }

$sts = strtotime($start_date);
$ets = strtotime($end_date);
$num_days = max(1, floor(($ets - $sts) / 86400) + 1);

// 5. Process data and calculations
$performance_data = [];
$chart_labels = [];
$chart_net_prod = [];
$chart_accuracy = [];
$team_stats = [
    'total_employees' => 0, 'total_target' => 0, 'total_achieved' => 0,
    'total_errors' => 0, 'total_internal_errors' => 0, 'total_external_errors' => 0,
    'avg_production' => 0, 'avg_accuracy' => 0, 'avg_net_prod' => 0
];

foreach ($employees_res as $emp) {
    $username = $emp['employee_username'];
    
    // Convert comma-separated IDs (e.g., 5,45,46) to actual Process Names
    $raw_processes = explode(',', $emp['process']);
    $mapped_names = [];
    foreach($raw_processes as $pid) {
        $pid = trim($pid);
        if(!empty($pid) && isset($process_map[$pid])) {
            $mapped_names[] = $process_map[$pid];
        }
    }
    $emp['process_display_name'] = !empty($mapped_names) ? implode(', ', array_unique($mapped_names)) : 'Unassigned';
            
            // Used employee_id instead of username to fetch the mapped target correctly
            $emp_id = $emp['employee_id'];
            $emp['target_pages'] = $employee_targets[$emp_id] ?? 0;
            
            $emp['achieved_pages'] = $employee_achieved[$emp_id] ?? 0;

    // Match errors using Employee Name (or partial name from Excel imports like 'Deepa' matching 'T. Deepa')
    $emp_name = $emp['employee_name'];
    $int_err = 0;
    $ext_err = 0;
    
    foreach($employee_errors as $err_name => $err_data) {
        $e_name = trim($err_name);
        if(!empty($e_name) && $e_name !== 'NA') {
            if(strcasecmp($e_name, trim($emp_name)) === 0 || stripos(trim($emp_name), $e_name) !== false) {
                $int_err += $err_data['internal_errors'];
                $ext_err += $err_data['external_errors'];
            }
        }
    }
    
    $emp['internal_errors'] = $int_err;
    $emp['external_errors'] = $ext_err;

    // Calculations based on Excel Sheet
    $emp['internal_error_points'] = $emp['internal_errors'] * 2;
    $emp['external_error_points'] = $emp['external_errors'] * 4;
    $total_error_points = $emp['internal_error_points'] + $emp['external_error_points'];

    // ADDED: Calculate Production % as Sum of Process % (matching team_count_report.php)
    $sum_of_percentages = 0;
    if(isset($pw_targets[$emp_id])) {
        foreach($pw_targets[$emp_id] as $pid => $daily_target) {
            $p_total_target = $daily_target * $num_days;
            $p_achieved = $pw_achieved[$emp_id][$pid] ?? 0;
            if ($p_total_target > 0) {
                $sum_of_percentages += ($p_achieved / $p_total_target) * 100;
            } elseif ($p_achieved > 0) {
                $sum_of_percentages += 100;
            }
        }
    }
    $emp['production_percentage'] = $sum_of_percentages;

    // NET Prod % = Production % - (Internal Error Points + External Error Points sum)
    $emp['net_prod_percentage'] = $emp['production_percentage'] - $total_error_points;

    // Accuracy % = (NET Prod % / Production %) * 100
    if ($emp['production_percentage'] > 0) {
        $calculated_accuracy = ($emp['net_prod_percentage'] / $emp['production_percentage']) * 100;
        // Safety: Minus-la (-%) poga koodathu nu 0 limit panrom
        $emp['accuracy_percentage'] = $calculated_accuracy > 0 ? $calculated_accuracy : 0;
    } else {
        $emp['accuracy_percentage'] = 0;
    }

    $emp['experience'] = calculateExperience($emp['joining_date']);
    $performance_data[] = $emp;

    // Chart & Team stats
    $chart_labels[] = $emp['employee_name'];
    $chart_net_prod[] = round($emp['net_prod_percentage'], 2);
    $chart_accuracy[] = round($emp['accuracy_percentage'], 2);

    $team_stats['total_employees']++;
    $team_stats['sum_prod_percentage'] = ($team_stats['sum_prod_percentage'] ?? 0) + $emp['production_percentage']; // ADDED THIS LINE
    $team_stats['total_target'] += $emp['target_pages'];
    $team_stats['total_achieved'] += $emp['achieved_pages'];
    $team_stats['total_internal_errors'] += $emp['internal_errors'];
    $team_stats['total_external_errors'] += $emp['external_errors'];
    $team_stats['total_errors'] += ($emp['internal_errors'] + $emp['external_errors']);
}

// Team averages (Updated to SUM the individual production percentages as requested)
$team_stats['avg_production'] = $team_stats['sum_prod_percentage'] ?? 0;
// Team total error points sum (Internal + External)
$team_total_error_pts = ($team_stats['total_internal_errors'] * 2) + ($team_stats['total_external_errors'] * 4);
$team_stats['avg_net_prod'] = $team_stats['avg_production'] - $team_total_error_pts;

if ($team_stats['avg_production'] > 0) {
    $team_calc_acc = ($team_stats['avg_net_prod'] / $team_stats['avg_production']) * 100;
    // Safety: Minus-la (-%) poga koodathu nu 0 limit panrom
    $team_stats['avg_accuracy'] = $team_calc_acc > 0 ? $team_calc_acc : 0;
} else {
    $team_stats['avg_accuracy'] = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly Production Report - Team <?= h($team_leader_team_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { padding: 20px; max-width: 1600px; margin: 0 auto; }
        .card { box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); margin-bottom: 20px; }
        .stat-card { border-radius: 10px; padding: 20px; text-align: center; color: white; }
        .stat-card.employees { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-card.target { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-card.achieved { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-card.errors { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .stat-card h3 { font-size: 2.5rem; margin: 10px 0; }
        .table thead th {
            background-color: #707981 !important;
            font-weight: bold;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            color: white !important;
            padding: 0.8rem 0.5rem;
            border: 1px solid #5a6268 !important;
            white-space: nowrap;
        }
        .team-badge { font-size: 1.2em; padding: 10px 20px; }
        .chart-container { height: 400px; }
        .download-btn { font-size: 1.1em; }
        .metric-badge { font-size: 0.85em; }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <!-- Header with Title, Week Selector, and Download Button -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4><i class="fas fa-file-alt me-2"></i>WEEKLY PRODUCTION REPORT</h4>
                <p class="text-muted mb-0">Team: <?= h($team_leader_team_name) ?> | Week <?= $week ?>, <?= $year ?></p>
            </div>
            <div class="d-flex align-items-center gap-3">
                <!-- Week Selector Form -->
                <form method="get" class="row g-2">
                    <div class="col-auto">
                        <input type="number" name="week" class="form-control form-control-sm" value="<?= $week ?>" min="1" max="53" style="width: 80px;" placeholder="Week">
                    </div>
                    <div class="col-auto">
                        <input type="number" name="year" class="form-control form-control-sm" value="<?= $year ?>" min="2020" max="2030" style="width: 100px;" placeholder="Year">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-filter me-1"></i> Filter
                        </button>
                    </div>
                </form>
                
                <!-- Download Excel Button -->
              <!--a href="?week=<?= $week ?>&year=<?= $year ?>&download=excel" class="btn btn-success download-btn">
    <i class="fas fa-file-excel excel-icon me-2"></i> Download Excel
</a-->            </div>
        </div>

        <!-- Team Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card employees">
                    <i class="fas fa-users fa-2x"></i>
                    <h3><?= $team_stats['total_employees'] ?></h3>
                    <p>Team Members</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card target">
                    <i class="fas fa-bullseye fa-2x"></i>
                    <h3><?= number_format($team_stats['total_target']) ?></h3>
                    <p>Total Target</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card achieved">
                    <i class="fas fa-check-circle fa-2x"></i>
                    <h3><?= number_format($team_stats['total_achieved']) ?></h3>
                    <p>Total Achieved</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card errors">
                    <i class="fas fa-exclamation-triangle fa-2x"></i>
                    <h3><?= $team_stats['total_errors'] ?></h3>
                    <p>Total Errors</p>
                </div>
            </div>
        </div>

        <!-- Performance Chart -->
        <div class="card mb-4">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Performance Chart - Week <?= $week ?>, <?= $year ?></h5>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="performanceChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Weekly Report Table -->
        <div class="card">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="fas fa-table me-2"></i>Weekly Production Details</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th>S.No</th>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Exp</th>
                                <th>Process</th>
                                <th>Target Pages</th>
                                <th>Achieved Pages</th>
                                <th>Production %</th>
                                <th>Internal Error</th>
                                <th>Error Points</th>
                                <th>External Error</th>
                                <th>Error Points</th>
                                <th>NET Prod %</th>
                                <th>Accuracy %</th>
                                <th>Leave</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(!empty($performance_data)): ?>
                                <?php $counter = 1; ?>
                                <?php foreach($performance_data as $emp): ?>
                                <tr class="text-center align-middle">
                                    <td class="fw-bold"><?= $counter++ ?></td>
                                    <td><?= h($emp['employee_username']) ?></td>
                                    <td class="text-start text-nowrap"><?= h($emp['employee_name']) ?></td>
                                    <td><?= h($emp['experience']) ?></td>
                                    <td class="fw-bold"><?= h($emp['process_display_name'] ?? 'Unassigned') ?></td>
                                    <td><?= number_format($emp['target_pages']) ?></td>
                                    <td><?= number_format($emp['achieved_pages']) ?></td>
                                    <td><?= number_format($emp['production_percentage'], 1) ?>%</td>
                                    
                                    <!-- Colors based on excel logic: yellow for int error, red for ext error -->
                                    <td class="<?= $emp['internal_errors'] > 0 ? 'bg-warning text-dark fw-bold' : '' ?>"><?= $emp['internal_errors'] ?></td>
                                    <td class="<?= $emp['internal_error_points'] > 0 ? 'bg-warning text-dark' : '' ?>"><?= $emp['internal_error_points'] ?></td>
                                    
                                    <td class="<?= $emp['external_errors'] > 0 ? 'bg-danger text-white fw-bold' : '' ?>"><?= $emp['external_errors'] ?></td>
                                    <td class="<?= $emp['external_error_points'] > 0 ? 'bg-danger text-white' : '' ?>"><?= $emp['external_error_points'] ?></td>
                                    
                                    <td class="fw-bold"><?= number_format($emp['net_prod_percentage'], 1) ?></td>
                                    <td><?= number_format($emp['accuracy_percentage'], 2) ?>%</td>
                                    <td class="p-1">
                                        <input type="text" class="form-control form-control-sm text-center" name="leave[<?= h($emp['employee_username']) ?>]" value="0" style="min-width: 60px;">
                                    </td>
                                    <td class="p-1">
                                        <input type="text" class="form-control form-control-sm" name="remarks[<?= h($emp['employee_username']) ?>]" placeholder="Enter remarks..." style="min-width: 150px;">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <!-- Team Summary Row -->
                                <tr class="table-secondary fw-bold text-center">
                                    <td colspan="5" class="text-end">TEAM SUMMARY:</td>
                                    <td><?= number_format($team_stats['total_target']) ?></td>
                                    <td><?= number_format($team_stats['total_achieved']) ?></td>
                                    <td><?= number_format($team_stats['avg_production'], 1) ?></td>
                                    <td><?= $team_stats['total_internal_errors'] ?></td>
                                    <td><?= $team_stats['total_internal_errors'] * 2 ?></td>
                                    <td><?= $team_stats['total_external_errors'] ?></td>
                                    <td><?= $team_stats['total_external_errors'] * 4 ?></td>
                                    <td><?= number_format($team_stats['avg_net_prod'], 1) ?></td>
                                    <td><?= number_format($team_stats['avg_accuracy'], 2) ?>%</td>
                                    <td></td>
                                    <td></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="16" class="text-center py-4">
                                        <div class="text-muted">
                                            <i class="fas fa-clipboard-list fa-3x mb-3"></i>
                                            <h5>No production data available</h5>
                                            <p>No team members found or no data for Week <?= $week ?>, <?= $year ?></p>
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Performance Chart
        const ctx = document.getElementById('performanceChart').getContext('2d');
        const performanceChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [{
                    label: 'NET Prod %',
                    data: <?= json_encode($chart_net_prod) ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.7)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1,
                    yAxisID: 'y'
                }, {
                    label: 'Accuracy %',
                    data: <?= json_encode($chart_accuracy) ?>,
                    backgroundColor: 'rgba(75, 192, 192, 0.7)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1,
                    type: 'line',
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45
                        }
                    },
                    y: {
                        beginAtZero: true,
                        max: 120,
                        title: {
                            display: true,
                            text: 'NET Prod %'
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        max: 120,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Accuracy %'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'Performance Comparison - Week <?= $week ?>, <?= $year ?>'
                    }
                }
            }
        });
    </script>
</body>
</html>