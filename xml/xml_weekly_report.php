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
if (!function_exists('h')) {
    function h($s) { 
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
    }
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
$team_leader_team_name = '';
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
$start_date_param = trim($_GET['start_date'] ?? '');
$end_date_param = trim($_GET['end_date'] ?? '');
$employee_id = trim($_GET['employee_id'] ?? '');
$team_id = trim($_GET['team_id'] ?? '');
$process = trim($_GET['process'] ?? '');

if (!empty($start_date_param) && !empty($end_date_param)) {
    $start_date = $start_date_param;
    $end_date = $end_date_param;
    $s_dt = new DateTime($start_date);
    $fy_calc_y = (int)$s_dt->format('Y');
    if ((int)$s_dt->format('n') < 4) { $fy_calc_y--; }
    $fy_s = new DateTime("first monday of april $fy_calc_y");
    $days_p = $fy_s->diff($s_dt)->days;
    $week = floor($days_p / 7) + 1;
    $year = $fy_calc_y;
} else {
    $week = (int)($_GET['week'] ?? $default_week);
    $year = (int)($_GET['year'] ?? date('Y'));
    $dto = new DateTime("first monday of april $year");
    $week_offset = $week - 1;
    if ($week_offset > 0) {
        $dto->modify("+$week_offset weeks");
    }
    $start_date = $dto->format('Y-m-d');
    $dto->modify('+5 days'); // Monday to Saturday (6 days)
    $end_date = $dto->format('Y-m-d');
}

$week_str = "Week " . (int)$week . ", " . (int)$year;


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
    echo '<tr><td colspan="14" class="title">Meeting Report</td></tr>';
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

// Master tables for filter dropdowns
$teams_filter_res = $mysqli->query("SELECT team_id, team_name FROM team_master WHERE status = 'Active' ORDER BY team_name");
$teams_filter_list = [];
if ($teams_filter_res) {
    while ($t = $teams_filter_res->fetch_assoc()) { $teams_filter_list[] = $t; }
}

// --- NEW LOGIC START: TL Name-a kandupudikkom (TL & TM rendu perukkum orey team data show aaga) ---
$safe_name = $mysqli->real_escape_string($team_leader_name); // Logged in person's name
$actual_tl_name = ''; // Default 

$is_tl = false;
if (!empty($safe_name)) {
    // 1. Logged in person TL ah nu check panrom
    $tl_check = $mysqli->query("SELECT 1 FROM employee_master WHERE reporting_manager='$safe_name' AND status='Active' LIMIT 1");
    if ($tl_check && $tl_check->num_rows > 0) {
        $is_tl = true;
        $actual_tl_name = $safe_name; // Avaru TL na, avaru name thaan actual TL name
    } else {
        // 2. Logged in person TM ah irundha, avangoda TL (reporting_manager) yaru nu database-la irundhu edukkurom
        $rm_check = $mysqli->query("SELECT reporting_manager FROM employee_master WHERE employee_name='$safe_name' AND status='Active' LIMIT 1");
        if ($rm_check && $rm_check->num_rows > 0) {
            $rm_row = $rm_check->fetch_assoc();
            if (!empty($rm_row['reporting_manager'])) {
                $actual_tl_name = $mysqli->real_escape_string($rm_row['reporting_manager']);
            }
        }
    }
}

// Dropdown-la avanga Team Members Name mattum vara madhiri restrict panrom
$emp_filter_sql = "SELECT employee_id, employee_name, employee_username FROM employee_master WHERE status = 'Active' AND employee_name NOT LIKE '%Selvarani%'";
if (!empty($actual_tl_name)) {
    $emp_filter_sql .= " AND reporting_manager = '$actual_tl_name'";
}
$emp_filter_sql .= " ORDER BY employee_username ASC, employee_name ASC";

$all_emp_filter_res = $mysqli->query($emp_filter_sql);
$all_emp_filter_list = [];
if ($all_emp_filter_res) {
    while ($e = $all_emp_filter_res->fetch_assoc()) { $all_emp_filter_list[] = $e; }
}
// --- NEW LOGIC END ---

$all_proc_filter_res = $mysqli->query("SELECT process_id, process_name FROM process_master WHERE status = 'Active' ORDER BY process_name");
$all_proc_filter_list = [];
if ($all_proc_filter_res) {
    while ($p = $all_proc_filter_res->fetch_assoc()) { $all_proc_filter_list[] = $p; }
}

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

// Fetch Employees for Table & Chart (Avanga team data mattum varum)
$emp_conds = ["em.status = 'Active'"];

if (!empty($employee_id)) {
    $emp_conds[] = "em.employee_id = " . (int)$employee_id;
} elseif (!empty($team_id)) {
    $emp_conds[] = "em.team_id = " . (int)$team_id;
} elseif (!empty($actual_tl_name)) {
    // 3. Table & Chart-ku actual TL name vechu filter panrom (Idhu TL & TM rendu perukkume work aagum)
    $emp_conds[] = "em.reporting_manager = '$actual_tl_name'";
}

if (!empty($process)) {
    $p_esc = $mysqli->real_escape_string($process);
    $emp_conds[] = "(em.process LIKE '%$p_esc%' OR em.process1 LIKE '%$p_esc%')";
}

$employees_sql = "SELECT em.employee_id, em.employee_name, em.employee_username, em.team_id, em.joining_date, em.process
                  FROM employee_master em
                  WHERE " . implode(" AND ", $emp_conds) . "
                  ORDER BY em.employee_username ASC";
$employees_res = $mysqli->query($employees_sql)->fetch_all(MYSQLI_ASSOC);

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
              WHERE ((week = '$week_str' OR week LIKE '%$week%' OR week LIKE '%$formatted_week%') OR (error_date BETWEEN '$start_date' AND '$end_date')) AND status = 'Active'
              GROUP BY error_made_by";
$error_res = $mysqli->query($error_sql);
$employee_errors = [];
while ($row = $error_res->fetch_assoc()) {
    $err_emp = (string)($row['error_made_by'] ?? '');
    if ($err_emp !== '') {
        $employee_errors[$err_emp] = $row;
    }
}

// ADDED: Fetch process-wise targets & achieved for accurate "Sum of Process %" calculation
$pw_targets_sql = "SELECT t1.tm_id, t1.process_id, t1.target_limit FROM daily_targets_xml t1 INNER JOIN (SELECT tm_id, process_id, MAX(id) as latest_id FROM daily_targets_xml GROUP BY tm_id, process_id) t2 ON t1.id = t2.latest_id";
$pw_targets_res = $mysqli->query($pw_targets_sql);
$pw_targets = [];
if ($pw_targets_res) {
    while($r = $pw_targets_res->fetch_assoc()) { 
        $t_mid = (string)($r['tm_id'] ?? '');
        $p_id = (string)($r['process_id'] ?? '');
        if ($t_mid !== '' && $p_id !== '') {
            $pw_targets[$t_mid][$p_id] = $r['target_limit']; 
        }
    }
}

$pw_achieved_sql = "SELECT tm_id, process_id, SUM(achieved_target) as proc_achieved FROM daily_achieved_xml WHERE target_date BETWEEN '$start_date' AND '$end_date' GROUP BY tm_id, process_id";
$pw_achieved_res = $mysqli->query($pw_achieved_sql);
$pw_achieved = [];
if ($pw_achieved_res) {
    while($r = $pw_achieved_res->fetch_assoc()) { 
        $t_mid = (string)($r['tm_id'] ?? '');
        $p_id = (string)($r['process_id'] ?? '');
        if ($t_mid !== '' && $p_id !== '') {
            $pw_achieved[$t_mid][$p_id] = $r['proc_achieved']; 
        }
    }
}

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
    $raw_processes = explode(',', (string)($emp['process'] ?? ''));
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

// Compute Dynamic Header & Chart Period Label
$start_m_d = date('m-d', strtotime($start_date));
$end_m_d   = date('m-d', strtotime($end_date));
$start_y   = (int)date('Y', strtotime($start_date));
$end_y     = (int)date('Y', strtotime($end_date));

$period_label = '';
if ($start_m_d === '04-01' && $end_m_d === '03-31' && $end_y === ($start_y + 1)) {
    // 1. Financial Year (e.g., 2026 - 2027 (April - March))
    $period_label = "{$start_y} - {$end_y} (April - March)";
} else {
    $first_day = date('Y-m-01', strtotime($start_date));
    $last_day  = date('Y-m-t', strtotime($start_date));
    if ($start_date === $first_day && $end_date === $last_day) {
        // 2. Exact Month (e.g., October 2026)
        $period_label = date('F Y', strtotime($start_date));
    } elseif (date('Y-m', strtotime($start_date)) === date('Y-m', strtotime($end_date)) && (strtotime($end_date) - strtotime($start_date)) > 7 * 86400) {
        // 3. Date range inside same month
        $period_label = date('F Y', strtotime($start_date));
    } else {
        // 4. Week
        if (!empty($week)) {
            $period_label = "Week {$week}, {$year}";
        } else {
            $period_label = date('d-M-Y', strtotime($start_date)) . ' to ' . date('d-M-Y', strtotime($end_date));
        }
    }
}

// Find Selected Employee Name if filtered
$selected_employee_label = '';
if (!empty($employee_id)) {
    foreach ($all_emp_filter_list as $emp_item) {
        if ((string)$emp_item['employee_id'] === (string)$employee_id) {
            $e_code = !empty($emp_item['employee_username']) ? " (" . $emp_item['employee_username'] . ")" : "";
            $selected_employee_label = " - " . $emp_item['employee_name'] . $e_code;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting Report - Team <?= h($team_leader_team_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-top: 70px; padding: 20px; max-width: 1600px; margin-left: auto; margin-right: auto; }
        .card { box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); margin-bottom: 20px; }

        /* Ash Page Header Banner (Matching Navbar Color) */
        .page-title-container {
            background: var(--ash, #6c757d);
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title-container h1,
        .page-title-container h4 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .stat-card { border-radius: 10px; padding: 20px; text-align: center; color: white !important; }
        .stat-card.employees { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-card.target { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-card.achieved { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-card.errors { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .stat-card h3 { font-size: 2.5rem; margin: 10px 0; color: #ffffff !important; font-weight: bold; }
        .stat-card p { color: #ffffff !important; margin: 0; }
        .stat-card i { color: #ffffff !important; }
        .filter-box { background-color: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); }
        /* ADDED: Scrollable container for the table */
        .table-responsive {
            max-height: 65vh; /* Sets height so table scrolls internally */
            overflow-y: auto;
        }

        .table {
            border-collapse: separate !important;
            border-spacing: 0 !important;
            width: 100% !important;
            margin-bottom: 0 !important;
        }

        .table th,
        .table thead th {
            background-color: var(--ash, #5e6b77) !important;
            font-weight: bold;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            color: white !important;
            padding: 0.8rem 0.5rem;
            border-bottom: 1px solid #dee2e6 !important;
            border-top: 1px solid #dee2e6 !important;
            white-space: nowrap;
            /* ADDED: Sticky properties to freeze the header */
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table td,
        .table tbody td {
            vertical-align: middle !important;
            border-bottom: 1px solid #dee2e6 !important;
        }

        /* Alternating mild ash and white rows matching UQ Process */
        .table tbody tr:nth-child(even) td:not(.bg-warning):not(.bg-danger) {
            background-color: #f2f4f6 !important;
        }
        .table tbody tr:nth-child(odd) td:not(.bg-warning):not(.bg-danger) {
            background-color: #ffffff !important;
        }
        .table tbody tr:hover td:not(.bg-warning):not(.bg-danger) {
            background-color: #e9ecef !important;
        }

        /* Sticky S.No column row background sync */
        .table tbody tr:nth-child(even) td.sticky-col-1 {
            background-color: #f2f4f6 !important;
        }
        .table tbody tr:nth-child(odd) td.sticky-col-1 {
            background-color: #ffffff !important;
        }
        .table tbody tr:hover td.sticky-col-1 {
            background-color: #e9ecef !important;
        }

        /* Preserve summary row and error highlight styling */
        .table tbody tr.table-secondary td {
            background-color: #e2e3e5 !important;
            font-weight: bold;
        }
        .table tbody tr td.bg-warning {
            background-color: #ffc107 !important;
            color: #212529 !important;
        }
        .table tbody tr td.bg-danger {
            background-color: #dc3545 !important;
            color: #ffffff !important;
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
        <!-- Header with Title -->
        <div class="page-title-container">
            <div>
                <h4 class="mb-1 text-white"><i class="fas fa-file-alt me-2"></i>Meeting Report</h4>
                <p class="text-white-50 mb-0 small">Team: <?= h($team_leader_team_name) ?> | <?= h($period_label) ?> (<?= date('d-m-Y', strtotime($start_date)) ?> to <?= date('d-m-Y', strtotime($end_date)) ?>)<?= !empty($selected_employee_label) ? ' | ' . h(ltrim($selected_employee_label, ' - ')) : '' ?></p>
            </div>
        </div>

        <!-- Team Statistics Cards -->
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

        <!-- Filter Form Below Dashboard Cards (Like in Team Report) -->
        <div class="filter-box">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Start Date</label>
                    <input type="date" name="start_date" class="form-control form-control-sm" value="<?= h($start_date) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">End Date</label>
                    <input type="date" name="end_date" class="form-control form-control-sm" value="<?= h($end_date) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Select Week</label>
                    <select id="week_selector" class="form-select form-select-sm">
                        <option value="">Custom Date</option>
                        <?php
                        $current_month = date('n');
                        $fy_yr = date('Y');
                        if ($current_month < 4) { $fy_yr--; }
                        $dto_filter = new DateTime("first monday of april $fy_yr");
                        for ($w = 1; $w <= 52; $w++) {
                            $w_start = $dto_filter->format('Y-m-d');
                            $w_start_dmy = $dto_filter->format('d-m-Y');
                            $end_dto = clone $dto_filter;
                            $end_dto->modify('+5 days');
                            $w_end = $end_dto->format('Y-m-d');
                            $val = "{$w_start}|{$w_end}";
                            $selected = ($val === "$start_date|$end_date" || ((int)$week === $w && empty($start_date_param))) ? 'selected' : '';
                            echo "<option value='{$val}' {$selected} data-week='{$w}' data-year='{$fy_yr}'>Week {$w} ({$w_start_dmy})</option>";
                            $dto_filter->modify('+1 week');
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Select Month</label>
                    <select id="month_selector" class="form-select form-select-sm">
                        <option value="">Select Month</option>
                        <?php
                        $fy_base = date('Y');
                        if (date('n') < 4) { $fy_base--; }
                        $fy_months = [
                            ['m' => 4, 'y' => $fy_base, 'name' => 'April'],
                            ['m' => 5, 'y' => $fy_base, 'name' => 'May'],
                            ['m' => 6, 'y' => $fy_base, 'name' => 'June'],
                            ['m' => 7, 'y' => $fy_base, 'name' => 'July'],
                            ['m' => 8, 'y' => $fy_base, 'name' => 'August'],
                            ['m' => 9, 'y' => $fy_base, 'name' => 'September'],
                            ['m' => 10, 'y' => $fy_base, 'name' => 'October'],
                            ['m' => 11, 'y' => $fy_base, 'name' => 'November'],
                            ['m' => 12, 'y' => $fy_base, 'name' => 'December'],
                            ['m' => 1, 'y' => $fy_base + 1, 'name' => 'January'],
                            ['m' => 2, 'y' => $fy_base + 1, 'name' => 'February'],
                            ['m' => 3, 'y' => $fy_base + 1, 'name' => 'March']
                        ];
                        foreach ($fy_months as $fym) {
                            $m_str = str_pad($fym['m'], 2, '0', STR_PAD_LEFT);
                            $y_str = $fym['y'];
                            $m_start = "{$y_str}-{$m_str}-01";
                            $last_day = date('t', strtotime($m_start));
                            $m_end = "{$y_str}-{$m_str}-{$last_day}";
                            $val = "{$m_start}|{$m_end}";
                            $selected = ($val === "$start_date|$end_date") ? 'selected' : '';
                            echo "<option value='{$val}' {$selected}>{$fym['name']} {$y_str}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Select Year</label>
                    <select id="year_selector" class="form-select form-select-sm">
                        <option value="">Select Year</option>
                        <?php
                        $curr_fy_start = (int)date('Y');
                        if ((int)date('n') < 4) { $curr_fy_start--; }
                        for ($fy = $curr_fy_start + 1; $fy >= $curr_fy_start - 3; $fy--) {
                            $fy_next = $fy + 1;
                            $fy_start_date = "$fy-04-01";
                            $fy_end_date = "$fy_next-03-31";
                            $val = "{$fy_start_date}|{$fy_end_date}";
                            $selected = ($val === "$start_date|$end_date") ? 'selected' : '';
                            echo "<option value='{$val}' {$selected}>{$fy} - {$fy_next} (April - March)</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Employee</label>
                    <select name="employee_id" class="form-select form-select-sm">
                        <option value="">All Employees</option>
                        <?php foreach($all_emp_filter_list as $emp_opt): ?>
                            <option value="<?= $emp_opt['employee_id'] ?>" <?= (string)$employee_id === (string)$emp_opt['employee_id'] ? 'selected' : '' ?>>
                                <?= !empty($emp_opt['employee_username']) ? h($emp_opt['employee_username']) . ' - ' : '' ?><?= h($emp_opt['employee_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Team</label>
                    <select name="team_id" class="form-select form-select-sm">
                        <option value="">All Teams</option>
                        <?php foreach($teams_filter_list as $team_opt): ?>
                            <option value="<?= $team_opt['team_id'] ?>" <?= (string)$team_id === (string)$team_opt['team_id'] ? 'selected' : '' ?>>
                                <?= h($team_opt['team_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Process</label>
                    <select name="process" class="form-select form-select-sm">
                        <option value="">All Processes</option>
                        <?php foreach($all_proc_filter_list as $proc_opt): ?>
                            <option value="<?= h($proc_opt['process_name']) ?>" <?= $process === $proc_opt['process_name'] ? 'selected' : '' ?>>
                                <?= h($proc_opt['process_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="week" id="hidden_week" value="<?= h($week) ?>">
                <input type="hidden" name="year" id="hidden_year" value="<?= h($year) ?>">
                <div class="col-12 mt-3">
                    <button type="submit" class="btn btn-primary btn-sm me-2"><i class="fas fa-filter me-1"></i> Apply Filters</button>
                    <a href="xml_weekly_report.php" class="btn btn-secondary btn-sm"><i class="fas fa-times me-1"></i> Clear Filters</a>
                </div>
            </form>
        </div>

        <!-- Weekly Report Table (TABLE FIRST) -->
        <div class="card mb-4">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="fas fa-table me-2"></i>Production Details</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th class="sticky-col-1">S.No</th>
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
                                    <td class="fw-bold sticky-col-1"><?= $counter++ ?></td>
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

        <!-- Performance Chart (CHART NEXT) -->
        <div class="card mb-4">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Performance Chart - <?= h($period_label . $selected_employee_label) ?></h5>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="performanceChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-fill Start and End dates when a Week is selected
        $('#week_selector').change(function() {
            var val = $(this).val();
            if (val) {
                var dates = val.split('|');
                $('input[name="start_date"]').val(dates[0]);
                $('input[name="end_date"]').val(dates[1]);
                $('#month_selector').val('');
                $('#year_selector').val('');
                var selectedWeek = $(this).find('option:selected').data('week');
                var selectedYear = $(this).find('option:selected').data('year');
                if (selectedWeek) {
                    $('#hidden_week').val(selectedWeek);
                }
                if (selectedYear) {
                    $('#hidden_year').val(selectedYear);
                }
            }
        });

        // Auto-fill Start and End dates when a Month is selected
        $('#month_selector').change(function() {
            var val = $(this).val();
            if (val) {
                var dates = val.split('|');
                $('input[name="start_date"]').val(dates[0]);
                $('input[name="end_date"]').val(dates[1]);
                $('#week_selector').val('');
                $('#year_selector').val('');
                $('#hidden_week').val('');
            }
        });

        // Auto-fill Start and End dates when a Year is selected
        $('#year_selector').change(function() {
            var val = $(this).val();
            if (val) {
                var dates = val.split('|');
                $('input[name="start_date"]').val(dates[0]);
                $('input[name="end_date"]').val(dates[1]);
                $('#week_selector').val('');
                $('#month_selector').val('');
                $('#hidden_week').val('');
            }
        });

        // Calculate dynamic maximum for Y axis so values like 121.5% have proper headroom
        const netProdData = <?= json_encode($chart_net_prod) ?>;
        const accuracyData = <?= json_encode($chart_accuracy) ?>;
        const allValues = [...netProdData, ...accuracyData].map(Number).filter(v => !isNaN(v));
        const maxVal = allValues.length ? Math.max(...allValues) : 100;
        const computedYMax = Math.max(120, Math.ceil((maxVal + 18) / 20) * 20);

        // Plugin to display percentage on top of each bar
        const barTopLabelPlugin = {
            id: 'barTopLabelPlugin',
            afterDatasetsDraw(chart) {
                const ctx = chart.ctx;
                chart.data.datasets.forEach((dataset, datasetIndex) => {
                    const meta = chart.getDatasetMeta(datasetIndex);
                    if (!meta.hidden) {
                        meta.data.forEach((element, index) => {
                            const val = dataset.data[index];
                            if (val !== undefined && val !== null && !isNaN(val) && val > 0) {
                                ctx.save();
                                ctx.font = 'bold 11px sans-serif';
                                ctx.fillStyle = '#1e293b';
                                ctx.textAlign = 'center';
                                ctx.textBaseline = 'bottom';
                                ctx.fillText(val + '%', element.x, element.y - 3);
                                ctx.restore();
                            }
                        });
                    }
                });
            }
        };

        // Performance Chart
        const ctx = document.getElementById('performanceChart').getContext('2d');
        const performanceChart = new Chart(ctx, {
            type: 'bar',
            plugins: [barTopLabelPlugin],
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [{
                    label: 'NET Prod %',
                    data: netProdData,
                    backgroundColor: 'rgba(54, 162, 235, 0.7)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1,
                    yAxisID: 'y'
                }, {
                    label: 'Accuracy %',
                    data: accuracyData,
                    backgroundColor: '#198754',
                    borderColor: '#146c43',
                    borderWidth: 1,
                    type: 'bar',
                    yAxisID: 'y'
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
                        max: computedYMax,
                        ticks: {
                            stepSize: 20
                        },
                        title: {
                            display: true,
                            text: 'Percentage (%)'
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: <?= json_encode('Performance Comparison - ' . $period_label . $selected_employee_label) ?>
                    }
                }
            }
        });
    </script>
</body>
</html>