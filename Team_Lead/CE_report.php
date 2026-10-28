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
if ($current_username) {
    $stmt = $mysqli->prepare("SELECT e.team_id, team_name FROM employee_master e, team_master t WHERE employee_username = ? AND e.team_id=t.team_id AND e.status = 'Active'");
    $stmt->bind_param("s", $current_username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $team_leader_team = $row['team_id'];
        $team_leader_team_name = $row['team_name'];
    }
    $stmt->close();
}

// Get parameters
$week = $_GET['week'] ?? date('W');
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
    AND em.team_id = ?
    ORDER BY em.employee_name ASC";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("iiis", $week, $year, $week_str, $team_leader_team);
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

// Build the SQL query with designation name and process name
$week_str = "Week " . (int)$week . ", " . (int)$year;

$sql = "SELECT 
    em.employee_id,
    em.employee_name,
    em.employee_username,
    em.team_id,
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
AND em.team_id = ?
ORDER BY em.employee_name ASC";
// Prepare and execute
$stmt = $mysqli->prepare($sql);
if ($stmt) {
    $stmt->bind_param("iiis", $week, $year, $week_str, $team_leader_team);
    $stmt->execute();
    $performance_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $performance_data = [];
    echo "Error preparing query: " . htmlspecialchars($mysqli->error);
}

// Calculate metrics for each employee and prepare chart data
$chart_labels = [];
$chart_net_prod = [];
$chart_accuracy = [];
$team_stats = [
    'total_employees' => 0,
    'total_target' => 0,
    'total_achieved' => 0,
    'total_errors' => 0,
    'total_points' => 0,
    'total_error_points' => 0,
    'avg_production' => 0,
    'avg_accuracy' => 0,
    'avg_net_prod' => 0
];

foreach ($performance_data as &$emp) {
    // Calculate all metrics
    $emp['achieved'] = $emp['achieved_target'] > 0 ? $emp['achieved_target'] : 0;
    $emp['points'] = $emp['achieved'] / 250; // 250 words = 1 point
    $emp['error_points'] = $emp['error_count']; // Assuming 1 error = 1 point deduction
    $emp['production_percentage'] = $emp['target_limit'] > 0 ? ($emp['achieved'] / $emp['target_limit']) * 100 : 0;
    
    // Calculate accuracy: (Achieved words - (Errors * 100)) / Achieved words * 100
    // Assuming each error deducts 100 words from accuracy calculation
    if ($emp['achieved'] > 0) {
        $emp['accuracy_percentage'] = (($emp['achieved'] - ($emp['error_count'] * 100)) / $emp['achieved']) * 100;
        $emp['accuracy_percentage'] = max(0, min(100, $emp['accuracy_percentage'])); // Clamp between 0-100
    } else {
        $emp['accuracy_percentage'] = 0;
    }
    
    // Calculate NET Production: (Production % * Accuracy %) / 100
    $emp['net_prod_percentage'] = ($emp['production_percentage'] * $emp['accuracy_percentage']) / 100;
    
    // Format experience
    $emp['experience'] = calculateExperience($emp['joining_date']);
    
    // Use designation name if available, otherwise use designation_id
    $emp['designation_display'] = $emp['designation_name'] ?: $emp['designation_id'];
    
    // Use process name if available, otherwise use designation_id
    $emp['process_display'] = $emp['process_name'] ?: $emp['designation_id'];
    
    // Add to chart data
    $chart_labels[] = $emp['employee_name'] . ' (' . $emp['designation_display'] . ')';
    $chart_net_prod[] = round($emp['net_prod_percentage'], 2);
    $chart_accuracy[] = round($emp['accuracy_percentage'], 2);
    
    // Add to team stats
    $team_stats['total_employees']++;
    $team_stats['total_target'] += $emp['target_limit'];
    $team_stats['total_achieved'] += $emp['achieved'];
    $team_stats['total_errors'] += $emp['error_count'];
    $team_stats['total_points'] += $emp['points'];
    $team_stats['total_error_points'] += $emp['error_points'];
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
            <div class="card-header bg-primary text-white">
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
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="fas fa-table me-2"></i>Weekly Production Details</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>S.No</th>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Exp</th>
                                <th>Process</th>
                                <th>Target</th>
                                <th>Achieved</th>
                                <th>Points<br><small class="text-light">(250 words = 1 point)</small></th>
                                <th>Errors</th>
                                <th>Error Points</th>
                                <th>Production %</th>
                                <th>Accuracy %</th>
                                <th>NET Prod %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(!empty($performance_data)): ?>
                                <?php $counter = 1; ?>
                                <?php foreach($performance_data as $emp): ?>
                                <tr>
                                    <td class="fw-bold"><?= $counter++ ?></td>
                                    <td><span class="badge bg-secondary"><?= h($emp['employee_username']) ?></span></td>
                                    <td>
                                        <strong><?= h($emp['employee_name']) ?></strong><br>
                                        <small class="text-muted"><?= h($emp['designation_name']) ?></small>
                                    </td>
                                    <td><?= h($emp['experience']) ?></td>
                                    <td><span class="badge bg-info"><?= h($emp['process_name']) ?></span></td>
                                    <td class="text-end"><?= number_format($emp['target_limit']) ?></td>
                                    <td class="text-end">
                                        <?= number_format($emp['achieved']) ?>
                                        <?php if($emp['achieved'] > 0): ?>
                                            <span class="badge bg-success metric-badge ms-1">
                                                <?= number_format($emp['achieved_target'] > 0 ? $emp['achieved_target'] : 0) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if($emp['points'] > 0): ?>
                                            <span class="badge bg-primary metric-badge">
                                                <?= number_format($emp['points'], 2) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">0.00</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if($emp['error_count'] > 0): ?>
                                            <span class="badge bg-danger"><?= $emp['error_count'] ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-success">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if($emp['error_points'] > 0): ?>
                                            <span class="badge bg-warning"><?= $emp['error_points'] ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-success">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge 
                                            <?= $emp['production_percentage'] >= 100 ? 'bg-success' : 
                                              ($emp['production_percentage'] >= 80 ? 'bg-info' : 
                                              ($emp['production_percentage'] >= 60 ? 'bg-warning' : 'bg-danger')) ?>">
                                            <?= number_format($emp['production_percentage'], 1) ?>%
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge 
                                            <?= $emp['accuracy_percentage'] >= 100 ? 'bg-success' : 
                                              ($emp['accuracy_percentage'] >= 95 ? 'bg-info' : 
                                              ($emp['accuracy_percentage'] >= 90 ? 'bg-warning' : 'bg-danger')) ?>">
                                            <?= number_format($emp['accuracy_percentage'], 1) ?>%
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge 
                                            <?= $emp['net_prod_percentage'] >= 100 ? 'bg-success' : 
                                              ($emp['net_prod_percentage'] >= 85 ? 'bg-info' : 
                                              ($emp['net_prod_percentage'] >= 70 ? 'bg-warning' : 'bg-danger')) ?>">
                                            <?= number_format($emp['net_prod_percentage'], 1) ?>%
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <!-- Team Summary Row -->
                                <tr class="table-secondary fw-bold">
                                    <td colspan="5" class="text-end">TEAM SUMMARY:</td>
                                    <td class="text-end"><?= number_format($team_stats['total_target']) ?></td>
                                    <td class="text-end"><?= number_format($team_stats['total_achieved']) ?></td>
                                    <td class="text-end"><?= number_format($team_stats['total_points'], 2) ?></td>
                                    <td class="text-center"><?= $team_stats['total_errors'] ?></td>
                                    <td class="text-center"><?= $team_stats['total_error_points'] ?></td>
                                    <td class="text-center">
                                        <span class="badge 
                                            <?= $team_stats['avg_production'] >= 100 ? 'bg-success' : 
                                              ($team_stats['avg_production'] >= 80 ? 'bg-info' : 
                                              ($team_stats['avg_production'] >= 60 ? 'bg-warning' : 'bg-danger')) ?>">
                                            <?= number_format($team_stats['avg_production'], 1) ?>%
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge 
                                            <?= $team_stats['avg_accuracy'] >= 100 ? 'bg-success' : 
                                              ($team_stats['avg_accuracy'] >= 95 ? 'bg-info' : 
                                              ($team_stats['avg_accuracy'] >= 90 ? 'bg-warning' : 'bg-danger')) ?>">
                                            <?= number_format($team_stats['avg_accuracy'], 1) ?>%
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge 
                                            <?= $team_stats['avg_net_prod'] >= 100 ? 'bg-success' : 
                                              ($team_stats['avg_net_prod'] >= 85 ? 'bg-info' : 
                                              ($team_stats['avg_net_prod'] >= 70 ? 'bg-warning' : 'bg-danger')) ?>">
                                            <?= number_format($team_stats['avg_net_prod'], 1) ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="13" class="text-center py-4">
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