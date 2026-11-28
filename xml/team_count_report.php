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
if (!function_exists('h')) {
    function h($s) { 
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
    }
}

if (!function_exists('formatDate')) {
    function formatDate($d) {
        if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
        return date('d-m-Y', strtotime($d));
    }
}

if (!function_exists('formatProcessHeader')) {
    function formatProcessHeader($name) {
        if ($name === 'PMC JATS QC') return 'PMC JATS<br>QC';
        if ($name === 'DOAJ /Biblat /PubMed') return 'DOAJ / Biblat<br>/ PubMed';
        if ($name === 'Table QC') return 'Table<br>QC';
        if ($name === 'JATS QC') return 'JATS<br>QC';
        if ($name === 'AOP QC') return 'AOP<br>QC';
        return h($name);
    }
}

if (!function_exists('getFiscalWeeks')) {
    function getFiscalWeeks($fy_year) {
        $cur_start = new DateTime("$fy_year-04-01");
        $fy_end = new DateTime(($fy_year + 1) . "-03-31");
        $weeks = [];
        $w = 1;
        while ($cur_start <= $fy_end) {
            $w_start = $cur_start->format('Y-m-d');
            $end = clone $cur_start;
            while ($end->format('N') != 6 && $end < $fy_end) {
                $end->modify('+1 day');
            }
            $w_end = $end->format('Y-m-d');
            $weeks[$w] = [
                'week' => $w,
                'start' => $w_start,
                'end' => $w_end,
                'year' => $fy_year
            ];
            $cur_start = clone $end;
            while ($cur_start->format('N') != 1 && $cur_start <= $fy_end) {
                $cur_start->modify('+1 day');
            }
            $w++;
        }
        return $weeks;
    }
}

if (!function_exists('calculateFiscalWeekNumber')) {
    function calculateFiscalWeekNumber($dateStr) {
        if (!$dateStr) return 1;
        $d = new DateTime($dateStr);
        $year = (int)$d->format('Y');
        $month = (int)$d->format('m');
        $fiscalStart = ($month < 4) ? new DateTime(($year - 1) . '-04-01') : new DateTime($year . '-04-01');
        if ($d < $fiscalStart) return 1;
        $cur = clone $fiscalStart;
        $sundays = 0;
        while ($cur < $d) {
            if ($cur->format('w') == 0) $sundays++;
            $cur->modify('+1 day');
        }
        return (1 + $sundays);
    }
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

// Calculate Default Financial Week (Starts April 1st) - matching xml_weekly_report.php
$today = new DateTime();
$curr_y = (int)$today->format('Y');
if ((int)$today->format('n') < 4) { $curr_y--; } // If Jan-Mar, use previous year's April
$default_week = calculateFiscalWeekNumber($today->format('Y-m-d'));

// Get filter parameters
$start_date_param = trim($_GET['start_date'] ?? '');
$end_date_param = trim($_GET['end_date'] ?? '');
$filter_employee_id = $_GET['employee_id'] ?? '';
$employee_id = $filter_employee_id;
$process = $_GET['process'] ?? '';
$team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : '';

if (!empty($start_date_param) && !empty($end_date_param)) {
    $start_date = $start_date_param;
    $end_date = $end_date_param;
    $s_dt = new DateTime($start_date);
    $fy_calc_y = (int)$s_dt->format('Y');
    if ((int)$s_dt->format('n') < 4) { $fy_calc_y--; }
    $week = calculateFiscalWeekNumber($start_date);
    $year = $fy_calc_y;
} else {
    $year = (int)($_GET['year'] ?? $curr_y);
    $week = (int)($_GET['week'] ?? $default_week);
    $fy_weeks = getFiscalWeeks($year);
    if (isset($fy_weeks[$week])) {
        $start_date = $fy_weeks[$week]['start'];
        $end_date = $fy_weeks[$week]['end'];
    } else {
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d');
    }
}

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
                  ORDER BY em.employee_username ASC, em.employee_name ASC";
$employees_result = $mysqli->query($employees_sql);

if ($employees_result && $employees_result->num_rows > 0) {
    while ($emp = $employees_result->fetch_assoc()) {
        $employee_details[$emp['employee_id']] = $emp;
    }
    // Sort employee details by employee ID (username) in ascending order
    uasort($employee_details, function($a, $b) {
        return strnatcasecmp($a['employee_username'] ?? '', $b['employee_username'] ?? '');
    });
}

// Fetch all employees for dropdown (respects manager & team filter, unaffected by single employee filter)
$dropdown_emp_cond = "em.status = 'Active'";
if (!empty($team_id)) {
    $dropdown_emp_cond .= " AND em.team_id = " . (int)$team_id;
}
$dropdown_emp_sql = "SELECT em.employee_id, em.employee_name, em.employee_username 
                     FROM employee_master em 
                     WHERE $manager_cond AND $dropdown_emp_cond 
                     ORDER BY em.employee_name";
$dropdown_emp_res = $mysqli->query($dropdown_emp_sql);
$dropdown_employees = [];
if ($dropdown_emp_res && $dropdown_emp_res->num_rows > 0) {
    while ($de = $dropdown_emp_res->fetch_assoc()) {
        $dropdown_employees[$de['employee_id']] = $de;
    }
}

// Fetch latest target assignment / revised date for each employee
$employee_revised_dates = [];
$rev_sql = "SELECT tm_id, MAX(assigned_date) as last_revised_date 
            FROM daily_targets_xml 
            WHERE assigned_date IS NOT NULL AND assigned_date > '2000-01-01'
            GROUP BY tm_id";
$rev_res = $mysqli->query($rev_sql);
if ($rev_res) {
    while ($r_row = $rev_res->fetch_assoc()) {
        $employee_revised_dates[$r_row['tm_id']] = $r_row['last_revised_date'];
    }
}

// 2. Fetch Fixed Targets (Grouped by Process dynamically)
// FIX: Using INNER JOIN with MAX(id) to get the LATEST target revision instead of the MAX target number
$pt_sql = "SELECT t1.tm_id, t1.process_id, t1.target_limit as total_limit 
           FROM daily_targets_xml t1
           INNER JOIN (
               SELECT tm_id, process_id, MAX(id) as latest_id 
               FROM daily_targets_xml 
               GROUP BY tm_id, process_id
           ) t2 ON t1.id = t2.latest_id";
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
    $ft_sql = "SELECT t1.tm_id, t1.target_limit as total_limit 
               FROM daily_targets_xml t1
               INNER JOIN (
                   SELECT tm_id, MAX(id) as latest_id 
                   FROM daily_targets_xml 
                   GROUP BY tm_id
               ) t2 ON t1.id = t2.latest_id";
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

/* =========================================
   PREPARE DATA FOR CHARTS (ADDED)
========================================= */

$start_ts = strtotime($start_date);
$end_ts = strtotime($end_date);
$num_days_chart = max(1, floor(($end_ts - $start_ts) / 86400) + 1);

$emp_chart_labels = [];
$emp_chart_percentages = [];
$emp_proc_datasets_raw = [];

foreach($processes_array as $proc) {
    $emp_proc_datasets_raw[$proc['process_name']] = [
        'label' => $proc['process_name'],
        'data' => [],
        'raw_achieved' => [],
        'raw_target' => [],
        'has_data' => false
    ];
}

$chart_employees = [];

foreach($employee_details as $emp_id => $emp) {
    $sum_of_percentages = 0;
    $has_any_data = false;
    $emp_proc_data = [];
    
    foreach($processes_array as $proc) {
        $p_name = $proc['process_name'];
        $p_achieved = 0;
        
        foreach($dates as $date) {
            $p_achieved += $process_daily_achieved[$emp_id][$p_name][$date] ?? 0;
        }
        
        $p_daily_target = $process_targets[$emp_id][$p_name] ?? 0;
        $p_total_target = $p_daily_target * $num_days_chart;
        
        $p_percent = 0;
        if ($p_total_target > 0) {
            $p_percent = ($p_achieved / $p_total_target) * 100;
            $sum_of_percentages += $p_percent;
        } elseif ($p_achieved > 0) {
            $p_percent = 100;
            $sum_of_percentages += 100;
        }
        
        $emp_proc_data[$p_name] = [
            'percent' => round($p_percent, 1),
            'achieved' => $p_achieved,
            'target' => $p_total_target
        ];
        
        if ($p_total_target > 0 || $p_achieved > 0) {
            $has_any_data = true;
        }
    }
    
    if ($has_any_data) {
        $chart_employees[] = [
            'id' => $emp_id,
            'name' => $emp['employee_name'],
            'total_percentage' => round($sum_of_percentages, 0),
            'processes' => $emp_proc_data
        ];
    }
}

// Sort employees descending by overall percentage (highest to lowest / desc to asc)
usort($chart_employees, function($a, $b) {
    if ($b['total_percentage'] === $a['total_percentage']) {
        return strcmp($a['name'], $b['name']);
    }
    return ($b['total_percentage'] < $a['total_percentage']) ? -1 : 1;
});

foreach($chart_employees as $c_emp) {
    $emp_chart_labels[] = $c_emp['name'];
    $emp_chart_percentages[] = $c_emp['total_percentage'];
    
    foreach($processes_array as $proc) {
        $p_name = $proc['process_name'];
        $p_info = $c_emp['processes'][$p_name] ?? ['percent' => 0, 'achieved' => 0, 'target' => 0];
        
        $emp_proc_datasets_raw[$p_name]['data'][] = $p_info['percent'];
        $emp_proc_datasets_raw[$p_name]['raw_achieved'][] = $p_info['achieved'];
        $emp_proc_datasets_raw[$p_name]['raw_target'][] = $p_info['target'];
        
        if ($p_info['target'] > 0 || $p_info['achieved'] > 0) {
            $emp_proc_datasets_raw[$p_name]['has_data'] = true;
        }
    }
}

$emp_proc_final_datasets = [];
$color_palette = ['#0d6efd', '#6610f2', '#6f42c1', '#d63384', '#dc3545', '#fd7e14', '#ffc107', '#198754', '#20c997', '#0dcaf0'];
$c_idx = 0;

foreach($emp_proc_datasets_raw as $p_name => $ds) {
    if ($ds['has_data']) {
        $color = $color_palette[$c_idx % count($color_palette)];
        $emp_proc_final_datasets[] = [
            'label' => $p_name,
            'data' => $ds['data'],
            'raw_achieved' => $ds['raw_achieved'],
            'raw_target' => $ds['raw_target'],
            'backgroundColor' => $color . 'CC',
            'borderColor' => $color,
            'borderWidth' => 1
        ];
        $c_idx++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production Report - Team Leader</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Global Tapasi Stylesheet -->
    <link rel="stylesheet" href="../custom_style.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-top: 70px; padding: 20px; margin-left: auto; margin-right: auto; }
        .card { box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); border: 1px solid rgba(0, 0, 0, 0.125); margin-bottom: 20px; }

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
        
        /* Table Grid & Column Border Fixes - Unified Single Table */
        .table-container { 
            width: 100%; 
            position: relative;
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #dee2e6;
            margin-bottom: 20px;
        }

        .table-responsive,
        .table-container > .table-responsive { 
            width: 100%; 
            max-height: 70vh !important; 
            overflow-y: auto !important; 
            overflow-x: auto !important; 
            position: relative !important;
            -webkit-overflow-scrolling: touch;
        }

        #processReportTable,
        #dailyReportTable {
            border-collapse: separate !important;
            border-spacing: 0 !important;
            width: 100% !important;
            margin-bottom: 0 !important;
        }

        #processReportTable thead,
        #dailyReportTable thead {
            position: static !important;
        }

        #processReportTable thead th,
        #dailyReportTable thead th { 
            background-color: #5e6b77 !important;  
            color: #ffffff !important; 
            position: sticky !important; 
            top: 0 !important; 
            z-index: 100 !important;
            border: 1px solid #4f5a64 !important;
            vertical-align: middle !important;
            text-align: center !important;
            padding: 8px 6px !important;
            font-size: 0.8rem;
            font-weight: 600;
            line-height: 1.2;
            white-space: normal !important;
            box-sizing: border-box !important;
        }

        #processReportTable td,
        #dailyReportTable td { 
            border: 1px solid #dee2e6 !important;
            vertical-align: middle !important;
            padding: 6px 4px !important; 
            font-size: 0.78rem;
        }

        .proc-col {
            min-width: 58px !important;
            max-width: 75px !important;
            text-align: center !important;
        }
        .proc-col-wide {
            min-width: 78px !important;
            max-width: 95px !important;
            text-align: center !important;
        }

        .employee-name { font-weight: 700; font-size: 0.85rem; color: #1a252f; white-space: normal; line-height: 1.2; }
        .total-cell { background-color: #e3f2fd; }
        .daily-total { background-color: #f1f8e9; }
        .sticky-col { 
            position: sticky !important; 
            left: 0 !important; 
            z-index: 10 !important; 
            box-shadow: 2px 0 5px rgba(0,0,0,0.12); 
            width: 135px !important; 
            min-width: 135px !important; 
            max-width: 145px !important; 
            padding: 4px 6px !important; 
            box-sizing: border-box !important;
        }

        #processReportTable tbody tr:nth-child(even) td.sticky-col,
        #dailyReportTable tbody tr:nth-child(even) td.sticky-col {
            background-color: #f2f4f6 !important;
        }
        #processReportTable tbody tr:nth-child(odd) td.sticky-col,
        #dailyReportTable tbody tr:nth-child(odd) td.sticky-col {
            background-color: #ffffff !important;
        }
        #processReportTable tbody tr:hover td.sticky-col,
        #dailyReportTable tbody tr:hover td.sticky-col {
            background-color: #e9ecef !important;
        }

        #processReportTable thead th.sticky-col,
        #dailyReportTable thead th.sticky-col {
            position: sticky !important;
            top: 0 !important;
            left: 0 !important;
            z-index: 120 !important;
            background-color: #5e6b77 !important;
            color: #ffffff !important;
            border: 1px solid #4f5a64 !important;
            box-shadow: 2px 0 5px rgba(0,0,0,0.12);
            box-sizing: border-box !important;
        }

        #dailyReportTable tfoot td.sticky-col {
            position: sticky !important;
            left: 0 !important;
            z-index: 10 !important;
            background-color: #212529 !important;
            color: #ffffff !important;
        }
        #processReportTable .fs-6 { font-size: 0.82rem !important; }
        .summary-card { color: white; border-radius: 10px; }
        .stat-number { font-size: 2rem; font-weight: bold; }
        .filter-box { background-color: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
        .target-missed { color: #dc3545; }
        .target-met { color: #198754; }
    </style>
</head>
<body>
    <?php 
    require 'header.php'; 
    // Restore filtered employee_id (prevent header.php from overwriting it with $_SESSION['uid'])
    $employee_id = $filter_employee_id;
    ?>
    
    <div class="main-content">
        <div class="page-title-container" style="background-color: #6c757d !important; background: #6c757d !important; color: #ffffff !important; padding: 12px 20px !important; border-radius: 12px !important; margin-bottom: 20px !important; display: flex !important; justify-content: space-between !important; align-items: center !important;">
            <h4 class="mb-0 text-white" style="color: #ffffff !important; font-size: 1.25rem !important; margin: 0 !important;"><i class="fas fa-bullseye me-2" style="color: #ffffff !important;"></i>Production Report</h4>
        </div>

        <!-- Filter Form -->
        <div class="filter-box">
            <form method="get" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?= h($start_date) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?= h($end_date) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Select Week</label>
                    <select id="week_selector" class="form-select">
                        <option value="">Custom Date</option>
                        <?php
                        $current_month = (int)date('n');
                        $year = (int)date('Y');
                        
                        // Financial year logic: If month is Jan, Feb, or Mar, use previous year's April
                        if ($current_month < 4) {
                            $year--; 
                        }
                        
                        $fiscal_weeks = getFiscalWeeks($year);
                        foreach ($fiscal_weeks as $fw) {
                            $w_num = $fw['week'];
                            $w_start = $fw['start'];
                            $w_end = $fw['end'];
                            $w_start_fmt = date('d-M-y', strtotime($w_start));
                            $w_end_fmt = date('d-M-y', strtotime($w_end));
                            $val = "{$w_start}|{$w_end}";
                            $selected = ($val === "$start_date|$end_date" || ((int)$week === $w_num && empty($start_date_param))) ? 'selected' : '';
                            echo "<option value='{$val}' {$selected}>Week {$w_num} ({$w_start_fmt} to {$w_end_fmt})</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Select Month</label>
                    <select id="month_selector" class="form-select">
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
                    <label class="form-label">Select Year</label>
                    <select id="year_selector" class="form-select">
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
                    <label class="form-label">Employee</label>
                    <select name="employee_id" class="form-select">
                        <option value="">All Employees</option>
                        <?php foreach($dropdown_employees as $id => $emp): ?>
                            <option value="<?= $emp['employee_id'] ?>" <?= (int)$employee_id === (int)$emp['employee_id'] ? 'selected' : '' ?>>
                                <?= !empty($emp['employee_username']) ? h($emp['employee_username']) . ' - ' : '' ?><?= h($emp['employee_name']) ?>
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
                <div class="col-md-4 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary text-nowrap"><i class="fas fa-filter me-1"></i> Apply Filters</button>
                    <a href="<?= basename($_SERVER['PHP_SELF']) ?>" class="btn btn-secondary text-nowrap"><i class="fas fa-times me-1"></i> Clear Filters</a>
                </div>
            </form>
        </div>


        
        <!-- TABLE: Process-Wise Employee Performance -->
        <div class="card mt-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">Process-Wise Performance per Employee</h5>
                    <small class="text-muted">Formula logic: SUM(ARRAYFORMULA(Achieved / Target * 100))</small>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-container">
                    <div class="table-responsive">
                    <table class="table table-bordered table-striped align-middle mb-0" id="processReportTable">
                        <thead>
                            <tr>
                                <th class="sticky-col">Employee Name</th>
                                <?php foreach($processes_array as $proc): 
                                    $is_wide = ($proc['process_name'] === 'DOAJ /Biblat /PubMed' || $proc['process_name'] === 'PMC JATS QC');
                                    $col_cls = $is_wide ? 'proc-col-wide' : 'proc-col';
                                ?>
                                    <th class="text-center <?= $col_cls ?>"><?= formatProcessHeader($proc['process_name']) ?></th>
                                <?php endforeach; ?>
                                <th class="table-dark text-center proc-col">Overall<br>Process %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($employee_details)): ?>
                                <tr>
                                    <td colspan="<?= count($processes_array) + 2 ?>" class="text-center text-muted py-4">
                                        <i class="fas fa-info-circle me-1"></i> No employee records found matching the selected filters.
                                    </td>
                                </tr>
                            <?php else: ?>
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
                                <td class="sticky-col">
                                    <div class="employee-name"><?= h($emp['employee_name']) ?></div>
                                    <div class="text-muted small">ID: <?= h($emp['employee_username']) ?></div>
                                    <?php 
                                    $rev_date = $employee_revised_dates[$emp_id] ?? '';
                                    if (!empty($rev_date)): 
                                    ?>
                                        <div class="text-secondary small mt-1" style="font-size: 0.72rem; white-space: nowrap;">
                                            <i class="far fa-calendar-alt me-1 text-primary"></i><?= date('d-M-Y', strtotime($rev_date)) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                
                                <?php foreach($processes_array as $proc): 
                                    $p_name = $proc['process_name'];
                                    $is_wide = ($p_name === 'DOAJ /Biblat /PubMed' || $p_name === 'PMC JATS QC');
                                    $col_cls = $is_wide ? 'proc-col-wide' : 'proc-col';
                                    
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
                                    <td class="text-center <?= $col_cls ?>">
                                        <?php if($p_total_target > 0 || $p_achieved > 0): ?>
                                            <div class="fw-bold <?= $cell_class ?> fs-6"><?= round($p_percent, 1) ?>%</div>
                                            <!-- Subtitle text formatted exactly like the screenshot calculation -->
                                            <div class="small text-muted" style="font-size: 0.68rem; white-space: nowrap;">
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
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Day-wise breakdown (when a specific employee is selected) -->
        <?php if (!empty($employee_id) && count($employee_details) === 1): ?>
            <?php 
                $emp = reset($employee_details);
                $emp_id = $emp['employee_id'];
                $rev_date = $employee_revised_dates[$emp_id] ?? '';
            ?>
            <div class="card mt-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Day-Wise Performance: <?= h($emp['employee_name']) ?></h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-container">
                        <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle mb-0" id="dailyReportTable">
                            <thead>
                                <tr>
                                    <th class="sticky-col">Employee Name</th>
                                    <?php foreach($processes_array as $proc): 
                                        $is_wide = ($proc['process_name'] === 'DOAJ /Biblat /PubMed' || $proc['process_name'] === 'PMC JATS QC');
                                        $col_cls = $is_wide ? 'proc-col-wide' : 'proc-col';
                                    ?>
                                        <th class="text-center <?= $col_cls ?>"><?= formatProcessHeader($proc['process_name']) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $process_totals = [];
                                foreach($processes_array as $proc) {
                                    $process_totals[$proc['process_name']] = 0;
                                }

                                $rendered_daily_rows = 0;
                                foreach($dates as $date): 
                                    $daily_row_total = 0;
                                    foreach($processes_array as $proc) {
                                        $daily_row_total += $process_daily_achieved[$emp_id][$proc['process_name']][$date] ?? 0;
                                    }

                                    // skip days where the employee did no work
                                    if ($daily_row_total == 0) {
                                        continue; 
                                    }
                                    $rendered_daily_rows++;
                                ?>
                                <tr>
                                    <td class="sticky-col">
                                        <div class="employee-name"><?= h($emp['employee_name']) ?></div>
                                        <div class="text-muted small">ID: <?= h($emp['employee_username']) ?></div>
                                        <div class="text-primary small fw-bold mt-1"><i class="fas fa-calendar-alt me-1"></i><?= formatDate($date) ?></div>
                                        <?php if (!empty($rev_date)): ?>
                                            <div class="text-secondary small mt-1" style="font-size: 0.72rem; white-space: nowrap;">
                                                <i class="far fa-calendar-alt me-1 text-primary"></i>Rev: <?= date('d-M-Y', strtotime($rev_date)) ?>
                                            </div>
                                        <?php endif; ?>
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
                                <?php if ($rendered_daily_rows === 0): ?>
                                    <tr>
                                        <td colspan="<?= count($processes_array) + 1 ?>" class="text-center text-muted py-4">
                                            <i class="fas fa-info-circle me-1"></i> No daily production records found for <?= h($emp['employee_name']) ?> between <?= formatDate($start_date) ?> and <?= formatDate($end_date) ?>.
                                        </td>
                                    </tr>
                                <?php endif; ?>
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
            </div>
        <?php endif; ?>
        
        <!-- Employee Performance Charts Heading Added Here -->
        <div class="page-title-container mt-4 mb-3">
            <h4 class="mb-0 text-white"><i class="fas fa-chart-bar me-2"></i>Employee Performance Charts</h4>
        </div>

        <!-- Graphical Reports Added Here -->
        <div class="row">
            <!-- Chart 1: Bar Chart -->
            <div class="col-12 mb-4">
                <div class="card border-primary">
                    <div class="card-header bg-white border-primary">
                        <h5 class="mb-0 text-primary"><i class="fas fa-bars me-2"></i>Employee Process Performance (Bar Chart)</h5>
                        <small class="text-muted">Stacked bars representing individual process calculation: (Achieved / Target) * 100</small>
                    </div>
                    <div class="card-body chart-container" style="position: relative; height: 330px; width: 100%; padding: 15px;">
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

            <!-- Chart 2: Line Chart -->
            <div class="col-12 mb-4">
                <div class="card border-primary">
                    <div class="card-header bg-white border-primary">
                        <h5 class="mb-0 text-primary"><i class="fas fa-chart-line me-2"></i>Overall Employee Performance (Line Chart)</h5>
                        <small class="text-muted">Summing the percentages: SUM(ARRAYFORMULA(Achieved / Target * 100))</small>
                    </div>
                    <div class="card-body chart-container" style="position: relative; height: 330px; width: 100%; padding: 15px;">
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
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
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
            // NEW: Auto-fill Start and End dates when a Week is selected
            $('#week_selector').change(function() {
                var val = $(this).val();
                if (val) {
                    var dates = val.split('|'); // Split the value to get Start (Monday) and End date (Saturday)
                    $('input[name="start_date"]').val(dates[0]);
                    $('input[name="end_date"]').val(dates[1]);
                    $('#month_selector').val('');
                    $('#year_selector').val('');
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
                }
            });

            <?php if (!empty($emp_chart_labels)): ?>
            const empOverallPercentages = <?= json_encode($emp_chart_percentages) ?>;

            // Plugin to show overall process % on top of each stacked bar
            const topLabelPlugin = {
                id: 'topLabelPlugin',
                afterDatasetsDraw(chart) {
                    const ctx = chart.ctx;
                    const metaList = chart.data.datasets.map((d, i) => chart.getDatasetMeta(i));
                    chart.data.labels.forEach((label, i) => {
                        let topY = null;
                        let barX = null;
                        metaList.forEach(meta => {
                            if (meta && meta.data && meta.data[i] && !meta.hidden) {
                                const bar = meta.data[i];
                                if (bar.y !== undefined && (topY === null || bar.y < topY)) {
                                    topY = bar.y;
                                    barX = bar.x;
                                }
                            }
                        });
                        const totalVal = empOverallPercentages[i];
                        if (topY !== null && barX !== null && totalVal !== undefined && totalVal > 0) {
                            ctx.save();
                            ctx.font = 'bold 11px sans-serif';
                            ctx.fillStyle = '#1e293b';
                            ctx.textAlign = 'center';
                            ctx.fillText(totalVal + '%', barX, topY - 5);
                            ctx.restore();
                        }
                    });
                }
            };

            // ----------------------------------------------------
            // 1. BAR CHART: Employee Process Breakdowns (Stacked)
            // ----------------------------------------------------
            new Chart(document.getElementById('employeeBarChart').getContext('2d'), {
                type: 'bar',
                plugins: [topLabelPlugin],
                data: {
                    labels: <?= json_encode($emp_chart_labels) ?>,
                    datasets: <?= json_encode($emp_proc_final_datasets) ?>
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    layout: {
                        padding: { top: 20 }
                    },
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
                            suggestedMax: 100,
                            ticks: {
                                stepSize: 20
                            },
                            grace: '10%',
                            title: { display: true, text: 'Percentage (%)' }
                        }
                    }
                }
            });

            // Plugin to show values on top of points in line chart
            const lineDataLabelPlugin = {
                id: 'lineDataLabelPlugin',
                afterDatasetsDraw(chart) {
                    const ctx = chart.ctx;
                    const meta = chart.getDatasetMeta(0);
                    if (meta && meta.data) {
                        meta.data.forEach((point, i) => {
                            const val = chart.data.datasets[0].data[i];
                            if (val !== undefined) {
                                ctx.save();
                                ctx.font = 'bold 11px sans-serif';
                                ctx.fillStyle = 'rgb(13, 110, 253)';
                                ctx.textAlign = 'center';
                                ctx.fillText(val + '%', point.x, point.y - 8);
                                ctx.restore();
                            }
                        });
                    }
                }
            };

            // Plugin to draw Dark Green horizontal line at 100%
            const targetLine100Plugin = {
                id: 'targetLine100Plugin',
                afterDraw(chart) {
                    const yScale = chart.scales.y;
                    if (!yScale) return;
                    const y100 = yScale.getPixelForValue(100);
                    const left = chart.chartArea.left;
                    const right = chart.chartArea.right;
                    
                    if (y100 !== undefined && y100 >= chart.chartArea.top && y100 <= chart.chartArea.bottom) {
                        const ctx = chart.ctx;
                        ctx.save();
                        ctx.beginPath();
                        ctx.moveTo(left, y100);
                        ctx.lineTo(right, y100);
                        ctx.strokeStyle = '#0f5132'; // Dark Green
                        ctx.lineWidth = 2.5;
                        ctx.stroke();
                        
                        // Badge label
                        ctx.font = 'bold 11px sans-serif';
                        ctx.fillStyle = '#0f5132';
                        ctx.textAlign = 'right';
                        ctx.fillText('100% Target', right - 6, y100 - 6);
                        ctx.restore();
                    }
                }
            };

            // ----------------------------------------------------
            // 2. LINE CHART: Employee Overall Sum
            // ----------------------------------------------------
            new Chart(document.getElementById('employeeLineChart').getContext('2d'), {
                type: 'line',
                plugins: [lineDataLabelPlugin, targetLine100Plugin],
                data: {
                    labels: <?= json_encode($emp_chart_labels) ?>,
                    datasets: [
                        {
                            label: 'Sum of Process Percentages (%)',
                            data: <?= json_encode($emp_chart_percentages) ?>,
                            backgroundColor: 'rgba(13, 110, 253, 0.18)', 
                            borderColor: 'rgb(13, 110, 253)',           
                            borderWidth: 3,
                            pointBackgroundColor: 'rgb(13, 110, 253)',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 1.5,
                            pointRadius: 5.5,
                            pointHoverRadius: 7.5,
                            fill: true, 
                            tension: 0.3
                        }
                    ]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    layout: {
                        padding: { top: 20 }
                    },
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
                            suggestedMax: 100,
                            ticks: {
                                stepSize: 20
                            },
                            grace: '10%',
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