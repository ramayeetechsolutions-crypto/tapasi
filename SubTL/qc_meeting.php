<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Helper function to safely print data and prevent errors
if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES);
    }
}

// Start session and check login
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['username'])) {
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    header("Location: ../index.php");
    exit;
}

require_once 'db.php';

// --- AUTO ENSURE COLUMNS EXIST IN weekly_targets ---
$chk_cols = $mysqli->query("SHOW COLUMNS FROM weekly_targets LIKE 'reason'");
if ($chk_cols && $chk_cols->num_rows == 0) {
    $mysqli->query("ALTER TABLE weekly_targets ADD COLUMN reason TEXT DEFAULT NULL");
}
$chk_leave = $mysqli->query("SHOW COLUMNS FROM weekly_targets LIKE 'leave_count'");
if ($chk_leave && $chk_leave->num_rows == 0) {
    $mysqli->query("ALTER TABLE weekly_targets ADD COLUMN leave_count INT DEFAULT 0");
}

// --- AJAX SAVE LOGIC FOR WEEKLY DATA & LEAVE ---
if (isset($_POST['ajax']) && $_POST['ajax'] == 'save_weekly_data') {
    header('Content-Type: application/json');
    $emp_id = $_POST['emp_id'] ?? '';
    $week_num = (int)($_POST['week_num'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $year = !empty($_POST['year']) ? (int)$_POST['year'] : date('Y');

    // Check if data already exists for this week
    $check_sql = "SELECT id FROM weekly_targets WHERE tm_id = ? AND week_number = ? AND year = ?";
    $stmt_chk = $mysqli->prepare($check_sql);
    $stmt_chk->bind_param("sii", $emp_id, $week_num, $year);
    $stmt_chk->execute();
    $res_chk = $stmt_chk->get_result();
    
    if ($res_chk->num_rows > 0) {
        $upd = $mysqli->prepare("UPDATE weekly_targets SET reason=? WHERE tm_id=? AND week_number=? AND year=?");
        $upd->bind_param("ssii", $reason, $emp_id, $week_num, $year);
        $upd->execute();
    } else {
        $ins = $mysqli->prepare("INSERT INTO weekly_targets (tm_id, week_number, year, reason, target_limit, achieved_target, assigned_date, status) VALUES (?, ?, ?, ?, 0, 0, NOW(), 'Active')");
        $ins->bind_param("siis", $emp_id, $week_num, $year, $reason);
        $ins->execute();
    }
    echo json_encode(['success' => true]);
    exit;
}

// --- AJAX INLINE SAVE FOR LEAVE ---
if (isset($_POST['ajax']) && $_POST['ajax'] == 'save_leave_count') {
    header('Content-Type: application/json');
    $emp_id = $_POST['emp_id'] ?? '';
    $week_num = (int)($_POST['week_num'] ?? 0);
    $leave_val = (int)($_POST['leave_val'] ?? 0);
    $year = date('Y');

    $check_sql = "SELECT id FROM weekly_targets WHERE tm_id = ? AND week_number = ? AND year = ?";
    $stmt_chk = $mysqli->prepare($check_sql);
    $stmt_chk->bind_param("sii", $emp_id, $week_num, $year);
    $stmt_chk->execute();
    $res_chk = $stmt_chk->get_result();

    if ($res_chk->num_rows > 0) {
        $upd = $mysqli->prepare("UPDATE weekly_targets SET leave_count=? WHERE tm_id=? AND week_number=? AND year=?");
        $upd->bind_param("isii", $leave_val, $emp_id, $week_num, $year);
        $upd->execute();
    } else {
        $ins = $mysqli->prepare("INSERT INTO weekly_targets (tm_id, week_number, year, leave_count, target_limit, achieved_target, assigned_date, status) VALUES (?, ?, ?, ?, 0, 0, NOW(), 'Active')");
        $ins->bind_param("siii", $emp_id, $week_num, $year, $leave_val);
        $ins->execute();
    }
    echo json_encode(['success' => true]);
    exit;
}

require 'header.php'; // Include your existing header

// --- APRIL-BASED OPERATIONAL WEEK DATE CALCULATOR (Matches target_assignment.php) ---
if (!function_exists('getOpWeekDates')) {
    function getOpWeekDates($week_number, $year = null) {
        if ($year === null) $year = date('Y');
        $april_first = strtotime($year . '-04-01');
        $day_of_week = date('N', $april_first);
        $first_monday = ($day_of_week == 1) ? $april_first : strtotime('+' . (8 - $day_of_week) . ' days', $april_first);
        $week_start = strtotime('+' . (($week_number - 1) * 7) . ' days', $first_monday);
        $week_end = strtotime('+5 days', $week_start); // Monday to Saturday
        return [
            'from_date' => date('Y-m-d', $week_start),
            'to_date' => date('Y-m-d', $week_end)
        ];
    }
}

// Current Operational Week (April based)
if (!function_exists('getCurrentOpWeek')) {
    function getCurrentOpWeek($year = null) {
        if ($year === null) $year = date('Y');
        $now = time();
        $april_first = strtotime($year . '-04-01');
        if ($now < $april_first) {
            $year = $year - 1;
            $april_first = strtotime($year . '-04-01');
        }
        $day_of_week = date('N', $april_first);
        $first_monday = ($day_of_week == 1) ? $april_first : strtotime('+' . (8 - $day_of_week) . ' days', $april_first);
        $diff_weeks = floor(($now - $first_monday) / (7 * 86400)) + 1;
        return max(1, min(53, (int)$diff_weeks));
    }
}

// Fiscal Weeks Calculation function (April 1st based)
if (!function_exists('getFiscalWeeks')) {
    function getFiscalWeeks($fy_year) {
        $weeks = [];
        $fy_start = new DateTime("{$fy_year}-04-01");
        $fy_end = new DateTime(($fy_year + 1) . "-03-31");
        $cur_start = clone $fy_start;
        $w = 1;
        while ($cur_start <= $fy_end) {
            $end = clone $cur_start;
            $end->modify('+5 days');
            if ($end > $fy_end) { $end = clone $fy_end; }
            $weeks[$w] = [
                'week' => $w,
                'start' => $cur_start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
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

// --- FILTERS & DYNAMIC WEEKS / MONTH LOGIC ---
$current_user = $_SESSION['username'] ?? '';

// Get Filters
$start_date_param = trim($_GET['start_date'] ?? '');
$end_date_param = trim($_GET['end_date'] ?? '');
$filter_tm = $_GET['filter_tm'] ?? '';
$filter_process = $_GET['filter_process'] ?? [];

$today_dt = new DateTime();
$curr_fy_y = (int)$today_dt->format('Y');
if ((int)$today_dt->format('n') < 4) { $curr_fy_y--; }

if (!empty($start_date_param) && !empty($end_date_param)) {
    $w1_from = $start_date_param;
    $w1_to = $end_date_param;
    $s_dt = new DateTime($w1_from);
    $fy_y = (int)$s_dt->format('Y');
    if ((int)$s_dt->format('n') < 4) { $fy_y--; }
    $filter_year = $fy_y;
    $filter_week = calculateFiscalWeekNumber($w1_from);
} else {
    $filter_week = (int)($_GET['filter_week'] ?? calculateFiscalWeekNumber(date('Y-m-d')));
    $filter_year = (int)($_GET['filter_year'] ?? $curr_fy_y);
    $fy_weeks = getFiscalWeeks($filter_year);
    if (isset($fy_weeks[$filter_week])) {
        $w1_from = $fy_weeks[$filter_week]['start'];
        $w1_to = $fy_weeks[$filter_week]['end'];
    } else {
        $w1_dates = getOpWeekDates($filter_week, $filter_year);
        $w1_from = $w1_dates['from_date'];
        $w1_to = $w1_dates['to_date'];
    }
}

// Calculate 4 Dynamic Weeks for Columns (Forward Order)
$w1 = (int)$filter_week;
$w2 = $w1 + 1 <= 53 ? $w1 + 1 : ($w1 + 1) - 53;
$w3 = $w2 + 1 <= 53 ? $w2 + 1 : ($w2 + 1) - 53;
$w4 = $w3 + 1 <= 53 ? $w3 + 1 : ($w3 + 1) - 53;

// 1. Get the logged-in user's employee_name / Reporting Manager (Login-wise dynamic)
$user_employee_name = '';
$logged_in_name = '';
$user_reporting_manager = '';
$user_sql = "SELECT employee_name, reporting_manager FROM employee_master WHERE employee_username = ?";
$stmt_user = $mysqli->prepare($user_sql);
if ($stmt_user) {
    $stmt_user->bind_param("s", $current_user);
    $stmt_user->execute();
    $stmt_user->bind_result($logged_in_name, $user_reporting_manager);
    $stmt_user->fetch();
    $stmt_user->close();
}
// If user has a reporting manager, use that; if user is TL/Admin with empty RM, use their own name
$user_employee_name = !empty($user_reporting_manager) ? $user_reporting_manager : $logged_in_name;

// Target Query
$target_sql = "SELECT SUM(target_limit) as total_target, SUM(leave_count) as total_leave FROM weekly_targets WHERE tm_id = ? AND week_number = ? AND year = ?";
$stmt_target = $mysqli->prepare($target_sql);

// QC Production: QC assigned to user, pages fetched from pag table (same as jobmgmt_qc.php)
$prod_sql = "
    SELECT COALESCE(SUM(p.pages), 0) as total_prod 
    FROM jobs j 
    LEFT JOIN qc q ON j.job_id = q.job_id 
    LEFT JOIN pag p ON j.job_id = p.job_id 
    WHERE (q.assigned_to_qc = ? OR q.done_by = ? OR j.assigned_to = ?)
    AND DATE(j.job_date) BETWEEN ? AND ?
";
$stmt_prod = $mysqli->prepare($prod_sql);

$error_sql = "SELECT COUNT(*) as error_count FROM article_errors WHERE team_error_made_by = ? AND DATE(date) BETWEEN ? AND ?";
$stmt_error = $mysqli->prepare($error_sql);

// Fallback query to get the latest target if current week has no target assigned
$target_fallback_sql = "SELECT target_limit FROM weekly_targets WHERE tm_id = ? ORDER BY id DESC LIMIT 1";
$stmt_target_fallback = $mysqli->prepare($target_fallback_sql);

// 2. Fetch all team members under this TL with Filters & Ascending Order
$team_data = [];
if (!empty($user_employee_name)) {
    $tm_sql = "SELECT employee_id, employee_username, employee_name, process, joining_date 
               FROM employee_master 
               WHERE reporting_manager = ? AND status = 'Active'";
    
    // Apply TM Filter
    if (!empty($filter_tm)) {
        $tm_sql .= " AND employee_username = '" . $mysqli->real_escape_string($filter_tm) . "'";
    }
    // Apply Process Filter (Multiple Checkboxes)
    if (!empty($filter_process) && is_array($filter_process)) {
        $process_conditions = [];
        foreach ($filter_process as $p_id) {
            $process_conditions[] = "FIND_IN_SET('" . $mysqli->real_escape_string($p_id) . "', process)";
        }
        $tm_sql .= " AND (" . implode(" OR ", $process_conditions) . ")";
    }
    
    $tm_sql .= " ORDER BY employee_username ASC";
    
    $stmt_tm = $mysqli->prepare($tm_sql);
    
    if ($stmt_tm) {
        $stmt_tm->bind_param("s", $user_employee_name);
        $stmt_tm->execute();
        $res_tm = $stmt_tm->get_result();
        
        while($row = $res_tm->fetch_assoc()) {
            $emp_username = $row['employee_username'];
            $emp_name = $row['employee_name'];
            
            // Process Name Conversion
            $process_ids = $row['process'];
            $process_names_array = [];
            if (!empty($process_ids) && $process_ids != '0') {
                $process_sql = "SELECT process_name FROM process_master WHERE process_id IN ($process_ids)";
                $process_res = $mysqli->query($process_sql);
                if ($process_res) {
                    while($p_row = $process_res->fetch_assoc()) {
                        $process_names_array[] = $p_row['process_name'];
                    }
                }
            }
            $process_name_final = !empty($process_names_array) ? implode(", ", $process_names_array) : 'CE';
            
            // Experience Calculation
            $exp_str = '-';
            if (!empty($row['joining_date']) && $row['joining_date'] != '0000-00-00') {
                try {
                    $doj_date = new DateTime($row['joining_date']);
                    $today = new DateTime('today');
                    if ($doj_date > $today) {
                        $exp_str = '0 Y 0 M';
                    } else {
                        $diff = $today->diff($doj_date);
                        $exp_str = $diff->y . ' Y ' . $diff->m . ' M';
                    }
                } catch (Exception $e) {
                    $exp_str = '-';
                }
            }
            
            // Get Target & Leave
            $target = 0;
            $leave_count = 0;
            if ($stmt_target) {
                $stmt_target->bind_param("sii", $emp_username, $w1, $filter_year);
                $stmt_target->execute();
                $res_target = $stmt_target->get_result()->fetch_assoc();
                $target = $res_target['total_target'] ?? 0;
                $leave_count = (int)($res_target['total_leave'] ?? 0);
                
                // Fallback target if empty
                if ($target == 0 && isset($stmt_target_fallback)) {
                    $stmt_target_fallback->bind_param("s", $emp_username);
                    $stmt_target_fallback->execute();
                    $res_fallback = $stmt_target_fallback->get_result()->fetch_assoc();
                    $base_target = $res_fallback['target_limit'] ?? 0;
                    $target = $base_target;
                }
            }
            
            // Get Production (QC Pages Count)
            $production = 0;
            if ($stmt_prod) {
                $stmt_prod->bind_param("sssss", $emp_username, $emp_username, $emp_username, $w1_from, $w1_to);
                $stmt_prod->execute();
                $res_prod = $stmt_prod->get_result()->fetch_assoc();
                $production = (int)($res_prod['total_prod'] ?? 0);
            }
            
            // Get Errors
            $errors = 0;
            if ($stmt_error) {
                $stmt_error->bind_param("sss", $emp_name, $w1_from, $w1_to);
                $stmt_error->execute();
                $res_error = $stmt_error->get_result()->fetch_assoc();
                $errors = $res_error['error_count'] ?? 0;
            }

            // Calculations
            $production_points = $production;
            $errors_points = $errors * 2;
            $prod_pct = ($target > 0) ? round(($production / $target) * 100, 2) : 0;

            $accuracy = 0;
            if ($production > 0) {
                $accuracy = round((($production - $errors_points) / $production) * 100, 2);
                if ($accuracy < 0) $accuracy = 0;
                if ($accuracy > 100) $accuracy = 100;
            }

            // --- NET PRODUCTION %: matches Production % when 0 errors, accounts for error impact against target ---
            $net_prod_pct = ($target > 0) ? round((($production - $errors_points) / $target) * 100, 2) : 0;
            if ($net_prod_pct < 0) $net_prod_pct = 0;
            $net_prod_decimal = $net_prod_pct / 100;
            
            $team_data[] = [
                'id' => $emp_username,
                'name' => $emp_name,
                'process' => $process_name_final,
                'exp' => $exp_str,
                'target' => $target,
                'production' => $production,
                'prod_points' => $production_points,
                'errors' => $errors,
                'err_points' => $errors_points,
                'prod_pct' => $prod_pct,
                'accuracy' => $accuracy,
                'net_prod_pct' => $net_prod_pct,
                'net_prod_decimal' => $net_prod_decimal,
                'leave' => $leave_count
            ];
        }
        $stmt_tm->close();
    }
}
if($stmt_target) $stmt_target->close();
if($stmt_prod) $stmt_prod->close();
if($stmt_error) $stmt_error->close();

// Fetch weekly reasons for team members
$weekly_reasons = [];
if (!empty($team_data)) {
    $emp_ids_clean = [];
    foreach ($team_data as $t_emp) {
        $emp_ids_clean[] = "'" . $mysqli->real_escape_string($t_emp['id']) . "'";
    }
    $in_ids = implode(',', $emp_ids_clean);
    $weeks_list = implode(',', [(int)$w1, (int)$w2, (int)$w3, (int)$w4]);
    $curr_y = (int)date('Y');
    $f_y = (int)$filter_year;
    $res_q = $mysqli->query("SELECT tm_id, week_number, reason FROM weekly_targets WHERE tm_id IN ($in_ids) AND week_number IN ($weeks_list) AND (year = $f_y OR year = $curr_y) AND reason IS NOT NULL AND TRIM(reason) != '' ORDER BY id ASC");
    if ($res_q) {
        while ($r_row = $res_q->fetch_assoc()) {
            $weekly_reasons[$r_row['tm_id']][$r_row['week_number']] = $r_row['reason'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QC Meeting Report</title>
    <!-- Include Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Include Chart.js and DataLabels Plugin -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    
    <style>
        /* Ash Page Header Banner (Matching Navbar Color) */
        .page-title-container {
            background: var(--ash, #6c757d);
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            margin-top: 75px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title-container h1,
        .page-title-container h2,
        .page-title-container h3,
        .page-title-container h4,
        .page-title-container h5,
        .page-title-container h6 {
            margin: 0 !important;
            font-size: 1.15rem !important;
            font-weight: 600 !important;
            color: white !important;
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            line-height: 1.2 !important;
        }

        .page-title-container p {
            color: rgba(255, 255, 255, 0.85) !important;
            margin: 2px 0 0 0 !important;
            font-size: 0.8rem !important;
            line-height: 1.2 !important;
        }

        .page-title-container i {
            color: white !important;
        }

        /* Header Action Button & Hover State (No pure white washout) */
        .page-title-container .btn-outline-light,
        .page-title-container .btn-outline-secondary {
            background-color: transparent !important;
            border-color: rgba(255, 255, 255, 0.85) !important;
            color: #ffffff !important;
            transition: all 0.2s ease-in-out !important;
        }

        .page-title-container .btn-outline-light *,
        .page-title-container .btn-outline-secondary * {
            color: #ffffff !important;
        }

        .page-title-container .btn-outline-light:hover,
        .page-title-container .btn-outline-light:focus,
        .page-title-container .btn-outline-light:active,
        .page-title-container .btn-outline-secondary:hover,
        .page-title-container .btn-outline-secondary:focus,
        .page-title-container .btn-outline-secondary:active {
            background-color: rgba(255, 255, 255, 0.2) !important;
            border-color: #ffffff !important;
            color: #ffffff !important;
        }

        .page-title-container .btn-outline-light:hover *,
        .page-title-container .btn-outline-light:focus *,
        .page-title-container .btn-outline-light:active *,
        .page-title-container .btn-outline-secondary:hover *,
        .page-title-container .btn-outline-secondary:focus *,
        .page-title-container .btn-outline-secondary:active * {
            color: #ffffff !important;
        }

        * { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
        body { background-color: #f8fafc; }
        
        .table-container {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            margin-bottom: 2rem;
        }
        
        .table { 
            margin-bottom: 0; 
            border-collapse: separate !important;
            border-spacing: 0 !important;
        }
        .table th,
        .table thead th {
            background-color: #5e6b77 !important; 
            color: #ffffff !important;
            font-weight: 600;
            font-size: 11px !important;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            padding: 6px 8px !important;
            border-top: 1px solid #dee2e6 !important;
            border-bottom: 1px solid #dee2e6 !important;
            border-right: 1px solid #dee2e6 !important;
            border-left: none !important;
            white-space: nowrap;
            text-align: center;
            vertical-align: middle;
            box-shadow: none !important;
        }
        .table th:first-child,
        .table thead th:first-child {
            border-left: 1px solid #dee2e6 !important;
        }
        .table td,
        .table tbody td {
            padding: 4px 6px !important;
            font-size: 10px !important;
            color: #334155 !important;
            vertical-align: middle !important;
            border-top: none !important;
            border-bottom: 1px solid #dee2e6 !important;
            border-right: 1px solid #dee2e6 !important;
            border-left: none !important;
            text-align: center;
            line-height: 1.25 !important;
        }
        .table td:first-child,
        .table tbody td:first-child {
            border-left: 1px solid #dee2e6 !important;
        }

        .table tbody td * {
            font-size: 10px !important;
        }

        .table tbody td small {
            font-size: 9px !important;
            line-height: 1.1 !important;
        }

        .table .badge {
            font-size: 10px !important;
            padding: 2px 6px !important;
            font-weight: 600 !important;
            border-radius: 4px !important;
        }
        .sticky-assign-col-last {
            border-right: 1px solid #dee2e6 !important;
        }
        .table> :not(caption)>*>*,
        .table-striped>tbody>tr:nth-of-type(odd)>*,
        .table-striped>tbody>tr:nth-of-type(even)>* {
            --bs-table-accent-bg: transparent !important;
            box-shadow: none !important;
        }

        /* Visible Zebra Striping matching xml/xml_unique_start.php (White and Light Ash) */
        .table tbody tr:nth-child(even) td {
            background-color: #f2f4f6 !important;
        }
        .table tbody tr:nth-child(odd) td {
            background-color: #ffffff !important;
        }
        .table tbody tr:hover td {
            background-color: #c4cbd3 !important;
            cursor: pointer;
            transition: background-color 0.1s ease;
        }
        .text-start { text-align: left !important; }
        
        .text-danger-fw { color: #dc2626; font-weight: 600; }
        .text-primary-fw { color: #2563eb; font-weight: 600; }

        .btn-edit {
            font-size: 0.75rem;
            padding: 0.3rem 0.6rem;
            color: #475569;
            background-color: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            text-decoration: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
        .btn-edit:hover {
            background-color: #e2e8f0;
            color: #1e293b;
        }
        .reason-cell-btn {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            color: #1e40af;
            background-color: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 4px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            max-width: 140px;
            text-align: left;
            cursor: pointer;
            transition: all 0.2s;
        }
        .reason-cell-btn:hover {
            background-color: #dbeafe;
            border-color: #93c5fd;
            color: #1d4ed8;
        }
        .reason-cell-btn .reason-text {
            display: inline-block;
            max-width: 105px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: middle;
            font-weight: 500;
        }
        
        .chart-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            margin-bottom: 2rem;
            padding: 0 !important;
        }
        .chart-header {
            background-color: var(--ash, #5e6b77) !important;
            color: #ffffff !important;
            padding: 10px 16px !important;
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            border-top-left-radius: 11px;
            border-top-right-radius: 11px;
        }
        .chart-header h5,
        .chart-header h6,
        .chart-header i {
            color: #ffffff !important;
            margin: 0 !important;
            font-weight: 700 !important;
            font-size: 1rem !important;
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
        }
        .chart-body {
            padding: 1.5rem;
        }
        .section-title {
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
    </style>
</head>
<body>

<div class="container-fluid px-4">
    <div class="page-title-container">
        <div>
            <h4 class="text-white">
                <i class="fas fa-check-double me-2"></i>
                QC Meeting
            </h4>
            <p>Weekly Production & Accuracy Report</p>
        </div>
        <div>
            <a href="jobmgmt_qc.php" class="btn btn-outline-light btn-sm" style="font-size: 0.8rem; padding: 4px 12px;">
                <i class="fas fa-arrow-left me-1"></i> Back to QC Reports
            </a>
        </div>
    </div>

<?php
        // Fetch values for Dropdowns
        $tm_filter_qry = $mysqli->query("SELECT employee_username, employee_name FROM employee_master WHERE reporting_manager = '$user_employee_name' AND status = 'Active' ORDER BY employee_name ASC");
        
        // Exact 17 processes from screenshot (CE & PAG Processes)
        $required_processes = [
            'CE', 'LE', 'Front Matter-CE', 'Content-CE', 'Equation-CE', 'Table-CE', 
            'Image-CE', 'Reference-CE', 'FQA-CE', 'Sub TL', 
            'QC', 'AP', 'TAB/IMG', 'PRE-PAG', 'AP_CRX', 'CE-REF', 'PAG'
        ];
        
        $proc_db_res = $mysqli->query("SELECT process_id, process_name FROM process_master");
        $process_db_map = [];
        if ($proc_db_res) {
            while ($p_row = $proc_db_res->fetch_assoc()) {
                $process_db_map[strtoupper(trim($p_row['process_name']))] = $p_row['process_id'];
            }
        }

        // Build list guaranteeing PAG is always included
        $process_filter_list = [];
        foreach ($required_processes as $p_name) {
            $upper = strtoupper($p_name);
            $p_id = $process_db_map[$upper] ?? $p_name;
            $process_filter_list[] = [
                'id' => $p_id,
                'name' => $p_name
            ];
        }
    ?>
    <!-- FILTER FORM (Matching XML Meeting Report) -->
    <div class="card mb-3 shadow-sm border-0">
        <div class="card-body py-3">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Start Date</label>
                    <input type="date" name="start_date" class="form-control form-control-sm" value="<?= h($w1_from) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">End Date</label>
                    <input type="date" name="end_date" class="form-control form-control-sm" value="<?= h($w1_to) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Select Week</label>
                    <select id="week_selector" class="form-select form-select-sm">
                        <option value="">Custom Date</option>
                        <?php
                        $fiscal_weeks = getFiscalWeeks($filter_year);
                        foreach ($fiscal_weeks as $fw) {
                            $w = $fw['week'];
                            $w_start = $fw['start'];
                            $w_end = $fw['end'];
                            $w_start_dmy = date('d-m-Y', strtotime($w_start));
                            $val = "{$w_start}|{$w_end}";
                            $selected = ($val === "$w1_from|$w1_to" || ((int)$filter_week === $w && empty($start_date_param))) ? 'selected' : '';
                            echo "<option value='{$val}' {$selected} data-week='{$w}' data-year='{$filter_year}'>Week {$w} ({$w_start_dmy})</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Select Month</label>
                    <select id="month_selector" class="form-select form-select-sm">
                        <option value="">Select Month</option>
                        <?php
                        $fy_base = $filter_year;
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
                            $selected = ($val === "$w1_from|$w1_to") ? 'selected' : '';
                            echo "<option value='{$val}' {$selected}>{$fym['name']} {$y_str}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Select Year</label>
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
                            $selected = ($val === "$w1_from|$w1_to") ? 'selected' : '';
                            echo "<option value='{$val}' {$selected}>{$fy} - {$fy_next} (April - March)</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Team Member</label>
                    <select name="filter_tm" class="form-select form-select-sm">
                        <option value="">All Team Members</option>
                        <?php if($tm_filter_qry) while($t = $tm_filter_qry->fetch_assoc()): ?>
                            <option value="<?= h($t['employee_username']) ?>" <?= $filter_tm == $t['employee_username'] ? 'selected' : '' ?>><?= h($t['employee_name']) ?> (<?= h($t['employee_username']) ?>)</option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Process</label>
                    <div style="position: relative;">
                        <button class="btn w-100 bg-white shadow-none" type="button" id="processBtn" onclick="toggleProcessMenu(event)" style="border: 1px solid #dee2e6; font-size: 0.875rem; padding: 0.25rem 0.5rem; text-align: left; display: flex; justify-content: space-between; align-items: center;">
                            Select Processes <i class="fas fa-chevron-down mt-1" style="font-size:0.7rem; color:#6c757d;"></i>
                        </button>
                        
                        <div id="processMenu" class="shadow-sm border bg-white" style="display: none; position: absolute; top: 100%; left: 0; right: 0; z-index: 1050; max-height: 250px; overflow-y: auto; padding: 10px; border-radius: 4px; margin-top: 2px;">
                            <?php foreach($process_filter_list as $p): ?>
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" name="filter_process[]" value="<?= h($p['id']) ?>" id="proc_<?= h($p['id']) ?>" <?= (is_array($filter_process) && (in_array((string)$p['id'], $filter_process) || in_array($p['name'], $filter_process))) ? 'checked' : '' ?>>
                                    <label class="form-check-label w-100" for="proc_<?= h($p['id']) ?>" style="cursor: pointer; font-size: 0.85rem;">
                                        <?= h($p['name']) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="filter_week" id="hidden_week" value="<?= h($filter_week) ?>">
                <input type="hidden" name="filter_year" id="hidden_year" value="<?= h($filter_year) ?>">

                <div class="col-12 mt-3">
                    <button type="submit" class="btn btn-primary btn-sm me-2"><i class="fas fa-filter me-1"></i> Apply Filters</button>
                    <a href="qc_meeting.php" class="btn btn-secondary btn-sm"><i class="fas fa-times me-1"></i> Clear Filters</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleProcessMenu(e) {
            e.preventDefault();
            var menu = document.getElementById('processMenu');
            if(menu.style.display === 'none' || menu.style.display === '') {
                menu.style.display = 'block';
            } else {
                menu.style.display = 'none';
            }
        }
        document.addEventListener('click', function(e) {
            var menu = document.getElementById('processMenu');
            var btn = document.getElementById('processBtn');
            if (menu && btn && !menu.contains(e.target) && !btn.contains(e.target)) {
                menu.style.display = 'none';
            }
        });

        // Auto-fill Start and End dates when a Week/Month/Year is selected
        document.addEventListener('DOMContentLoaded', function() {
            var weekSel = document.getElementById('week_selector');
            var monthSel = document.getElementById('month_selector');
            var yearSel = document.getElementById('year_selector');
            var startDateInput = document.querySelector('input[name="start_date"]');
            var endDateInput = document.querySelector('input[name="end_date"]');
            var hiddenWeek = document.getElementById('hidden_week');
            var hiddenYear = document.getElementById('hidden_year');

            if (weekSel) {
                weekSel.addEventListener('change', function() {
                    var val = this.value;
                    if (val) {
                        var dates = val.split('|');
                        if (startDateInput) startDateInput.value = dates[0];
                        if (endDateInput) endDateInput.value = dates[1];
                        if (monthSel) monthSel.value = '';
                        if (yearSel) yearSel.value = '';
                        var opt = this.options[this.selectedIndex];
                        if (hiddenWeek && opt.dataset.week) hiddenWeek.value = opt.dataset.week;
                        if (hiddenYear && opt.dataset.year) hiddenYear.value = opt.dataset.year;
                    }
                });
            }

            if (monthSel) {
                monthSel.addEventListener('change', function() {
                    var val = this.value;
                    if (val) {
                        var dates = val.split('|');
                        if (startDateInput) startDateInput.value = dates[0];
                        if (endDateInput) endDateInput.value = dates[1];
                        if (weekSel) weekSel.value = '';
                        if (yearSel) yearSel.value = '';
                        if (hiddenWeek) hiddenWeek.value = '';
                    }
                });
            }

            if (yearSel) {
                yearSel.addEventListener('change', function() {
                    var val = this.value;
                    if (val) {
                        var dates = val.split('|');
                        if (startDateInput) startDateInput.value = dates[0];
                        if (endDateInput) endDateInput.value = dates[1];
                        if (weekSel) weekSel.value = '';
                        if (monthSel) monthSel.value = '';
                        if (hiddenWeek) hiddenWeek.value = '';
                    }
                });
            }
        });
    </script>
    
    <!-- REPORT TABLE -->
    <h5 class="section-title"><i class="fas fa-table text-primary"></i> Weekly Report Data</h5>
    <div class="table-container">
        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered align-middle">
                <thead>
                    <tr>
                        <th colspan="17" style="background-color: #475569 !important; font-size:1rem;">
                            WEEKLY REPORT (<?= date('d-m-Y', strtotime($w1_from)) ?> to <?= date('d-m-Y', strtotime($w1_to)) ?>)
                        </th>
                    </tr>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Exp</th>
                        <th>Process</th>
                        <th>Target</th>
                        <th>Production</th>
                        <th>Prod Points</th>
                        <th>Errors</th>
                        <th>Err Points</th>
                        <th>Production %</th>
                        <th>Accuracy %</th>
                        <th>NET Prod %</th>
                        <th>Leave</th>
                        <th>Week <?= $w1 ?></th>
                        <th>Week <?= $w2 ?></th>
                        <th>Week <?= $w3 ?></th>
                        <th>Week <?= $w4 ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($team_data)): ?>
                        <?php foreach ($team_data as $emp): ?>
                            <tr>
                                <td class="fw-500"><?= h($emp['id']) ?></td>
                                <td class="text-start fw-500"><?= h($emp['name']) ?></td>
                                <td style="white-space: nowrap;"><?= h($emp['exp']) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= h($emp['process']) ?></span></td>
                                <td class="text-danger-fw"><?= h($emp['target']) ?></td>
                                <td><?= h($emp['production']) ?></td>
                                <td class="fw-bold text-success"><?= h($emp['prod_points']) ?></td>
                                <td class="fw-bold"><?= h($emp['errors']) ?></td>
                                <td class="fw-bold text-danger"><?= h($emp['err_points']) ?></td>
                                <td class="text-primary-fw"><?= h($emp['prod_pct']) ?>%</td>
                                <td class="text-primary-fw"><?= h($emp['accuracy']) ?>%</td>
                                <td class="text-primary-fw fw-bold"><?= h($emp['net_prod_pct']) ?>%</td>
                                <!-- EDITABLE LEAVE ROW -->
                                <td>
                                    <div class="d-flex align-items-center justify-content-center gap-1">
                                        <input type="number" min="0" max="31" 
                                               id="leave_<?= h($emp['id']) ?>" 
                                               value="<?= (int)$emp['leave'] ?>" 
                                               style="width: 48px; text-align: center; border: 1px solid #cbd5e1; border-radius: 4px; padding: 2px 4px; font-weight: 600; color: #dc2626;"
                                               onchange="saveLeaveInline('<?= h($emp['id']) ?>', <?= $w1 ?>, this.value)">
                                    </div>
                                </td>
                                <?php foreach ([$w1, $w2, $w3, $w4] as $wn): 
                                    $r_txt = trim($weekly_reasons[$emp['id']][$wn] ?? '');
                                ?>
                                    <td>
                                        <?php if ($r_txt !== ''): ?>
                                            <a href="javascript:void(0);" 
                                               class="reason-cell-btn" 
                                               data-emp-id="<?= h($emp['id']) ?>" 
                                               data-emp-name="<?= h($emp['name']) ?>" 
                                               data-week-num="<?= $wn ?>" 
                                               data-reason="<?= h($r_txt) ?>" 
                                               onclick="openEditModalFromEl(this)" 
                                               title="<?= h($r_txt) ?> (Click to edit)">
                                                <span class="reason-text"><?= h($r_txt) ?></span>
                                                <i class="fas fa-edit text-primary"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="javascript:void(0);" 
                                               class="btn-edit" 
                                               data-emp-id="<?= h($emp['id']) ?>" 
                                               data-emp-name="<?= h($emp['name']) ?>" 
                                               data-week-num="<?= $wn ?>" 
                                               data-reason="" 
                                               onclick="openEditModalFromEl(this)">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="16" class="text-center py-4">No team members found under your profile.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- BAR DIAGRAM -->
    <div class="chart-card mt-4">
        <div class="chart-header">
            <h5 class="mb-0 text-white"><i class="fas fa-chart-bar text-white"></i> Production vs Accuracy Chart</h5>
        </div>
        <div class="chart-body">
            <canvas id="productionChart" height="100"></canvas>
        </div>
    </div>

</div>

<!-- EDIT WEEKLY DATA MODAL -->
<div class="modal fade" id="editWeekModal" tabindex="-1" aria-labelledby="editWeekModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="editWeekForm">
          <div class="modal-header">
            <h5 class="modal-title" id="editWeekModalLabel" style="font-weight: 700; color: #1e293b;">Edit Weekly Data</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" id="editEmpId" name="emp_id">
            <input type="hidden" id="editWeekNum" name="week_num">
            
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Employee Name</label>
                <input type="text" class="form-control bg-light" id="editEmpName" readonly>
            </div>
            
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Reason (Optional)</label>
                <textarea class="form-control" id="editReason" name="reason" rows="2" placeholder="Enter reason for errors / production delay..."></textarea>
            </div>

            <!-- <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label text-muted small fw-bold">Target</label>
                    <input type="number" class="form-control" id="editTarget" name="target" placeholder="Enter target">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label text-muted small fw-bold">Production</label>
                    <input type="number" class="form-control" id="editProduction" name="production" placeholder="Enter production">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Errors</label>
                <input type="number" class="form-control" id="editErrors" name="errors" placeholder="Enter errors count">
            </div> -->
            
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            <button type="button" class="btn btn-primary" onclick="saveWeeklyData()">Save Changes</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- Include Bootstrap JS for Modal to work -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Scripts for Chart.js & Dynamic Data & Modals -->
<script>
    // --- MODAL JAVASCRIPT LOGIC ---
    function openEditModalFromEl(el) {
        const empId = el.getAttribute('data-emp-id') || '';
        const empName = el.getAttribute('data-emp-name') || '';
        const weekNum = el.getAttribute('data-week-num') || '';
        const reason = el.getAttribute('data-reason') || '';
        openEditModal(empId, empName, weekNum, reason);
    }

    function openEditModal(empId, empName, weekNum, currentReason = '') {
        // Set values in the modal
        document.getElementById('editEmpId').value = empId;
        document.getElementById('editEmpName').value = empName;
        document.getElementById('editWeekNum').value = weekNum;
        document.getElementById('editWeekModalLabel').innerText = "Edit Week " + weekNum + " Data";
        
        // Populate existing reason
        document.getElementById('editReason').value = currentReason || '';

        // Show the Bootstrap modal
        var editModal = new bootstrap.Modal(document.getElementById('editWeekModal'));
        editModal.show();
    }

    function saveWeeklyData() {
        let empId = document.getElementById('editEmpId').value;
        let weekNum = document.getElementById('editWeekNum').value;
        let reason = document.getElementById('editReason').value;

        // AJAX call to save data
        let formData = new URLSearchParams();
        formData.append('ajax', 'save_weekly_data');
        formData.append('emp_id', empId);
        formData.append('week_num', weekNum);
        formData.append('reason', reason);
        formData.append('year', '<?= (int)($filter_year ?? date('Y')) ?>');

        fetch('qc_meeting.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(response => {
            if (!response.ok) {
                throw new Error("HTTP error " + response.status);
            }
            return response.json();
        })
        .then(data => {
            if(data.success) {
                var editModalElement = document.getElementById('editWeekModal');
                var modalInstance = bootstrap.Modal.getInstance(editModalElement);
                if (modalInstance) {
                    modalInstance.hide();
                }
                location.reload(); 
            } else {
                alert("Error saving data: " + (data.error || "Unknown server error"));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert("Error saving data: " + error.message);
        });
    }

    // --- SAVE LEAVE INLINE VIA AJAX ---
    function saveLeaveInline(empId, weekNum, leaveVal) {
        let formData = new URLSearchParams();
        formData.append('ajax', 'save_leave_count');
        formData.append('emp_id', empId);
        formData.append('week_num', weekNum);
        formData.append('leave_val', leaveVal);

        fetch('qc_meeting.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(response => response.json())
        .then(data => {
            if(!data.success) {
                alert("Failed to update leave count.");
            }
        })
        .catch(err => console.error('Leave save error:', err));
    }

    // --- CHART JAVASCRIPT LOGIC ---
    Chart.register(ChartDataLabels);

    // Get Dynamic PHP Data to JS
    const teamData = <?= json_encode($team_data) ?>;
    
    // Extract data for chart
    const chartLabels = teamData.map(emp => emp.name.split(' ')); // Splits name into multiple lines if needed
    const netProdValues = teamData.map(emp => emp.net_prod_decimal);
    const accuracyValues = teamData.map(emp => emp.accuracy / 100);

    const ctx = document.getElementById('productionChart').getContext('2d');

    const data = {
        labels: chartLabels,
        datasets: [
            {
                label: 'NET Prod',
                data: netProdValues,
                backgroundColor: '#1d4ed8', // Changed to match your Blue
                borderRadius: 4,
                barPercentage: 0.8,
                categoryPercentage: 0.8
            },
            {
                label: 'Accuracy',
                data: accuracyValues,
                backgroundColor: '#15803d', // Changed to match your Green
                borderRadius: 4,
                barPercentage: 0.8,
                categoryPercentage: 0.8
            }
        ]
    };

    const config = {
        type: 'bar',
        data: data,
        options: {
            responsive: true,
            layout: { padding: { top: 30 } },
            scales: {
                y: {
                    beginAtZero: true,
                    // Dynamic Max Y-Axis value based on actual data
                    max: Math.max(1.5, ...netProdValues) + 0.2, 
                    grid: { color: '#f1f5f9' },
                    ticks: {
                        stepSize: 0.5,
                        font: { family: 'Inter', weight: '600', color: '#64748b' }
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: {
                        font: { family: 'Inter', weight: '600', size: 11, color: '#475569' },
                        autoSkip: false
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8,
                        font: { family: 'Inter', weight: '600', color: '#1e293b' },
                        padding: 20
                    }
                },
                tooltip: {
                    backgroundColor: '#1e293b',
                    titleFont: { family: 'Inter' },
                    bodyFont: { family: 'Inter' }
                },
                datalabels: {
                    anchor: 'end',
                    align: 'top',
                    formatter: function(value, context) {
                        if (value === 0) return ''; 
                        let formattedValue = (value * 100);
                        // parseFloat will automatically handle both exact decimals (13.56%) and whole numbers (100%) perfectly
                        return parseFloat(formattedValue.toFixed(2)) + '%';
                    },
                    color: function(context) { return context.dataset.backgroundColor; },
                    font: { family: 'Inter', weight: '700', size: 11 }
                }
            }
        }
    };

    new Chart(ctx, config);
</script>

</body>
</html>