<?php
// Start session and check login
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['username'])) {
    header("Location: ../index.php");
    exit;
}

require 'header.php'; // Include your existing header

// --- AJAX SAVE LOGIC FOR WEEKLY DATA ---
if (isset($_POST['ajax']) && $_POST['ajax'] == 'save_weekly_data') {
    header('Content-Type: application/json');
    $emp_id = $_POST['emp_id'] ?? '';
    $week_num = (int)$_POST['week_num'];
    $target = (float)$_POST['target'];
    $production = (float)$_POST['production'];
    $errors = (int)$_POST['errors'];
    $year = date('Y');

    // Auto-fix: Add 'errors' column to weekly_targets if it doesn't exist
    $check_col = $mysqli->query("SHOW COLUMNS FROM weekly_targets LIKE 'errors'");
    if ($check_col && $check_col->num_rows == 0) {
        $mysqli->query("ALTER TABLE weekly_targets ADD COLUMN errors INT DEFAULT 0");
    }

    // Check if data already exists for this week
    $check_sql = "SELECT id FROM weekly_targets WHERE tm_id = ? AND week_number = ? AND year = ?";
    $stmt_chk = $mysqli->prepare($check_sql);
    $stmt_chk->bind_param("sii", $emp_id, $week_num, $year);
    $stmt_chk->execute();
    $res_chk = $stmt_chk->get_result();
    
    if ($res_chk->num_rows > 0) {
        // Update existing record
        $upd = $mysqli->prepare("UPDATE weekly_targets SET target_limit=?, achieved_target=?, errors=? WHERE tm_id=? AND week_number=? AND year=?");
        $upd->bind_param("ddisii", $target, $production, $errors, $emp_id, $week_num, $year);
        $upd->execute();
    } else {
        // Insert new record
        $ins = $mysqli->prepare("INSERT INTO weekly_targets (tm_id, week_number, year, target_limit, achieved_target, errors, assigned_date, status) VALUES (?, ?, ?, ?, ?, ?, NOW(), 'Active')");
        $ins->bind_param("siiddi", $emp_id, $week_num, $year, $target, $production, $errors);
        $ins->execute();
    }
    echo json_encode(['success' => true]);
    exit;
}

// --- APRIL-BASED OPERATIONAL WEEK DATE CALCULATOR ---
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

// --- FILTERS & DYNAMIC WEEKS / MONTH LOGIC ---
$current_user = $_SESSION['username'] ?? '';

// Get Filters
$filter_mode = $_GET['filter_mode'] ?? 'week'; // 'week' or 'month'
$filter_tm = $_GET['filter_tm'] ?? '';
$filter_process = $_GET['filter_process'] ?? []; // Array for multi-select checkboxes
$filter_week = $_GET['filter_week'] ?? getCurrentOpWeek();
$filter_month = (int)($_GET['filter_month'] ?? date('n'));
$filter_year = date('Y');

// Calculate 4 Dynamic Weeks for Columns
$w1 = (int)$filter_week;
$w2 = $w1 - 1 > 0 ? $w1 - 1 : 52 + ($w1 - 1);
$w3 = $w2 - 1 > 0 ? $w2 - 1 : 52 + ($w2 - 1);
$w4 = $w3 - 1 > 0 ? $w3 - 1 : 52 + ($w3 - 1);

// Dates for Week 1 (Operational Week Range)
$w1_dates = getOpWeekDates($w1, $filter_year);
$w1_from = $w1_dates['from_date'];
$w1_to = $w1_dates['to_date'];

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

// Fallback query for target
$target_fallback_sql = "SELECT target_limit FROM weekly_targets WHERE tm_id = ? ORDER BY id DESC LIMIT 1";
$stmt_target_fallback = $mysqli->prepare($target_fallback_sql);

// Target Query
if ($filter_mode === 'month') {
    $target_sql = "SELECT SUM(target_limit) as total_target, SUM(leave_count) as total_leave FROM weekly_targets WHERE tm_id = ? AND year = ?";
    $stmt_target = $mysqli->prepare($target_sql);
} else {
    $target_sql = "SELECT SUM(target_limit) as total_target, SUM(leave_count) as total_leave FROM weekly_targets WHERE tm_id = ? AND week_number = ? AND year = ?";
    $stmt_target = $mysqli->prepare($target_sql);
}

// Error Query
if ($filter_mode === 'month') {
    $error_sql = "SELECT COUNT(*) as error_count FROM article_errors WHERE team_error_made_by = ? AND MONTH(date) = ? AND YEAR(date) = ?";
    $stmt_error = $mysqli->prepare($error_sql);
} else {
    $error_sql = "SELECT COUNT(*) as error_count FROM article_errors WHERE team_error_made_by = ? AND DATE(date) BETWEEN ? AND ?";
    $stmt_error = $mysqli->prepare($error_sql);
}

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
    
    // ASCENDING ORDER BY ID
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
            $process_name_final = !empty($process_names_array) ? implode(", ", $process_names_array) : 'LE';
            
            // Experience Calculation
            $exp_str = '-';
            if (!empty($row['joining_date']) && $row['joining_date'] != '0000-00-00') {
                $doj_date = new DateTime($row['joining_date']);
                $today = new DateTime();
                $diff = $today->diff($doj_date);
                $exp_str = $diff->y . ' Y ' . $diff->m . ' M';
            }
            
            // Get Target & Leave
            $target = 0;
            $leave_count = 0;
            if ($stmt_target) {
                if ($filter_mode === 'month') {
                    $stmt_target->bind_param("si", $emp_username, $filter_year);
                } else {
                    $stmt_target->bind_param("sii", $emp_username, $w1, $filter_year);
                }
                $stmt_target->execute();
                $res_target = $stmt_target->get_result()->fetch_assoc();
                $target = $res_target['total_target'] ?? 0;
                $leave_count = (int)($res_target['total_leave'] ?? 0);
                
                // Fallback target
                if ($target == 0 && isset($stmt_target_fallback)) {
                    $stmt_target_fallback->bind_param("s", $emp_username);
                    $stmt_target_fallback->execute();
                    $res_fallback = $stmt_target_fallback->get_result()->fetch_assoc();
                    $base_target = $res_fallback['target_limit'] ?? 0;
                    $target = ($filter_mode === 'month') ? ($base_target * 4) : $base_target;
                }
            }
            
            // Check if member needs WC-ExRef calculation
            $wc_exref_members = ['T. Priyadharshini', 'S. Kiruthika Singaravel', 'B. Arulmozhi', 'S. Abinaya'];
            $use_wc_exref = in_array(trim($emp_name), $wc_exref_members);

            // Dynamic Production Column Selection
            $column_to_sum = $use_wc_exref 
                ? "COALESCE(NULLIF(CAST(l.wc_exref AS UNSIGNED), 0), NULLIF(l.word_count, 0), NULLIF(j.word_count, 0), 0)"
                : "COALESCE(NULLIF(l.word_count, 0), NULLIF(c.word_count, 0), NULLIF(j.word_count, 0), 0)";

            $production = 0;
            if ($filter_mode === 'month') {
                $dyn_prod_sql = "
                    SELECT SUM($column_to_sum) as total_prod 
                    FROM jobs j 
                    LEFT JOIN le l ON j.job_id = l.job_id 
                    LEFT JOIN ce c ON j.job_id = c.job_id
                    WHERE (l.assigned_to_le = ? OR l.assigned_to_le_qc = ? OR j.assigned_to = ?)
                    AND MONTH(j.job_date) = ? AND YEAR(j.job_date) = ?
                ";
                $stmt_p = $mysqli->prepare($dyn_prod_sql);
                if ($stmt_p) {
                    $stmt_p->bind_param("sssii", $emp_username, $emp_username, $emp_username, $filter_month, $filter_year);
                    $stmt_p->execute();
                    $production = $stmt_p->get_result()->fetch_assoc()['total_prod'] ?? 0;
                    $stmt_p->close();
                }
            } else {
                $dyn_prod_sql = "
                    SELECT SUM($column_to_sum) as total_prod 
                    FROM jobs j 
                    LEFT JOIN le l ON j.job_id = l.job_id 
                    LEFT JOIN ce c ON j.job_id = c.job_id
                    WHERE (l.assigned_to_le = ? OR l.assigned_to_le_qc = ? OR j.assigned_to = ?)
                    AND DATE(j.job_date) BETWEEN ? AND ?
                ";
                $stmt_p = $mysqli->prepare($dyn_prod_sql);
                if ($stmt_p) {
                    $stmt_p->bind_param("sssss", $emp_username, $emp_username, $emp_username, $w1_from, $w1_to);
                    $stmt_p->execute();
                    $production = $stmt_p->get_result()->fetch_assoc()['total_prod'] ?? 0;
                    $stmt_p->close();
                }
            }
            
            // Get Errors
            $errors = 0;
            if ($stmt_error) {
                if ($filter_mode === 'month') {
                    $stmt_error->bind_param("sii", $emp_name, $filter_month, $filter_year);
                } else {
                    $stmt_error->bind_param("sss", $emp_name, $w1_from, $w1_to);
                }
                $stmt_error->execute();
                $res_error = $stmt_error->get_result()->fetch_assoc();
                $errors = $res_error['error_count'] ?? 0;
            }

            // Calculations based on Exact Given Formulas
            $production_points = $target > 0 ? round($production / ($target / 100), 2) : 0;
            $errors_points = $errors * 2;
            $prod_pct = $production_points; // Production % = (Production Points / Target) * Target = Prod Points %

            $accuracy = 100;
            if ($production_points > 0) {
                $accuracy = round((($production_points - $errors_points) / $production_points) * 100, 2);
                if ($accuracy < 0) $accuracy = 0;
            } else if ($errors_points > 0) {
                $accuracy = 0;
            }

            // Net Production % = Production Points - Errors Points
            $net_prod_pct = $target > 0 ? round($production_points - $errors_points, 2) : 0;
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
if(isset($stmt_target) && $stmt_target) $stmt_target->close();
if(isset($stmt_prod) && $stmt_prod) $stmt_prod->close();
if(isset($stmt_error) && $stmt_error) $stmt_error->close();

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LE Meeting Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    
    <style>
        * { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
        body { background-color: #f8fafc; }
        
        .page-header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 0;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-top: 70px;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            margin-bottom: 2rem;
        }
        
        .table { margin-bottom: 0; }
        .table thead th {
            background-color: #707981 !important; 
            color: white !important;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            padding: 0.8rem 0.5rem;
            border: 1px solid #5a6268 !important;
            white-space: nowrap;
            text-align: center;
            vertical-align: middle;
        }
        .table tbody td {
            padding: 0.6rem 0.4rem;
            font-size: 0.85rem;
            color: #334155;
            vertical-align: middle;
            border: 1px solid #cbd5e1;
            text-align: center;
        }
        .table tbody tr:hover { background-color: #f8fafc; }
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
        
        .chart-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            margin-bottom: 2rem;
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

<div class="page-header">
    <div class="container-fluid px-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-1" style="font-weight: 700; color: #1e293b;">
                    <i class="fas fa-handshake me-2" style="color: #667eea;"></i>
                    LE Meeting
                </h4>
                <p class="mb-0 text-muted small">Weekly Production & Accuracy Report</p>
            </div>
            <div>
                <a href="jobmgmt_le.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back to LE Reports
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-4">

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
    <!-- FILTER FORM -->
    <div class="card mb-3 shadow-sm border-0">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
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
                </script>

                <!-- Report View: Weekly or Monthly -->
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Report View</label>
                    <select name="filter_mode" id="filterModeSelect" class="form-select form-select-sm" onchange="toggleFilterType()">
                        <option value="week" <?= $filter_mode === 'week' ? 'selected' : '' ?>>Weekly Base</option>
                        <option value="month" <?= $filter_mode === 'month' ? 'selected' : '' ?>>Monthly Base</option>
                    </select>
                </div>

                <!-- Week Dropdown -->
                <div class="col-md-2" id="weekFilterDiv" style="<?= $filter_mode === 'month' ? 'display:none;' : '' ?>">
                    <label class="form-label small fw-bold mb-1">Week</label>
                    <select name="filter_week" class="form-select form-select-sm">
                        <?php for($i=1; $i<=53; $i++): ?>
                            <option value="<?= $i ?>" <?= $w1 == $i ? 'selected' : '' ?>>Week <?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <!-- Month Dropdown -->
                <div class="col-md-2" id="monthFilterDiv" style="<?= $filter_mode === 'month' ? '' : 'display:none;' ?>">
                    <label class="form-label small fw-bold mb-1">Month</label>
                    <select name="filter_month" class="form-select form-select-sm">
                        <?php for($m=1; $m<=12; $m++): 
                            $m_name = date('F', mktime(0, 0, 0, $m, 1));
                        ?>
                            <option value="<?= $m ?>" <?= $filter_month == $m ? 'selected' : '' ?>><?= $m_name ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm me-1"><i class="fas fa-filter"></i> Filter</button>
                    <a href="le_meeting.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i> Clear</a>
                </div>
            </form>
        </div>
    </div>

    <script>
    function toggleFilterType() {
        var mode = document.getElementById('filterModeSelect').value;
        if (mode === 'month') {
            document.getElementById('weekFilterDiv').style.display = 'none';
            document.getElementById('monthFilterDiv').style.display = 'block';
        } else {
            document.getElementById('weekFilterDiv').style.display = 'block';
            document.getElementById('monthFilterDiv').style.display = 'none';
        }
    }
    </script>
    
    <!-- WEEKLY REPORT TABLE -->
    <h5 class="section-title"><i class="fas fa-table text-primary"></i> Weekly Report Data</h5>
    <div class="table-container">
        <div class="table-responsive">
            <table class="table table-hover table-bordered">
                <thead>
                    <tr>
                        <th colspan="17" style="background-color: #475569 !important; font-size:1rem;">WEEKLY REPORT</th>
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
                        <!-- Week columns moved to the second row -->
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
                                <td><?= h($emp['exp']) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= h($emp['process']) ?></span></td>
                                <td class="text-danger-fw"><?= h($emp['target']) ?></td>
                                <td><?= h($emp['production']) ?></td>
                                <td class="fw-bold text-success"><?= h($emp['prod_points']) ?></td>
                                <td class="fw-bold"><?= h($emp['errors']) ?></td>
                                <td class="fw-bold text-danger"><?= h($emp['err_points']) ?></td>
                                <td class="text-primary-fw"><?= h($emp['prod_pct']) ?>%</td>
                                <td class="text-primary-fw"><?= h($emp['accuracy']) ?>%</td>
                                <td class="text-primary-fw"><?= h($emp['net_prod_pct']) ?>%</td>
                                <td class="fw-bold text-danger"><?= h($emp['leave']) ?></td>
                                <!-- EDIT OPTIONS RESTORED WITH MODAL TRIGGERS -->
                                <td><a href="javascript:void(0);" class="btn-edit" onclick="openEditModal('<?= h($emp['id']) ?>', '<?= h($emp['name']) ?>', <?= $w1 ?>)"><i class="fas fa-edit"></i> Edit</a></td>
                                <td><a href="javascript:void(0);" class="btn-edit" onclick="openEditModal('<?= h($emp['id']) ?>', '<?= h($emp['name']) ?>', <?= $w2 ?>)"><i class="fas fa-edit"></i> Edit</a></td>
                                <td><a href="javascript:void(0);" class="btn-edit" onclick="openEditModal('<?= h($emp['id']) ?>', '<?= h($emp['name']) ?>', <?= $w3 ?>)"><i class="fas fa-edit"></i> Edit</a></td>
                                <td><a href="javascript:void(0);" class="btn-edit" onclick="openEditModal('<?= h($emp['id']) ?>', '<?= h($emp['name']) ?>', <?= $w4 ?>)"><i class="fas fa-edit"></i> Edit</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="15" class="text-center py-4">No team members found under your profile.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- BAR DIAGRAM -->
    <h5 class="section-title mt-4"><i class="fas fa-chart-bar text-primary"></i> Production vs Accuracy Chart</h5>
    <div class="chart-card">
        <canvas id="productionChart" height="100"></canvas>
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
    function openEditModal(empId, empName, weekNum) {
        document.getElementById('editEmpId').value = empId;
        document.getElementById('editEmpName').value = empName;
        document.getElementById('editWeekNum').value = weekNum;
        document.getElementById('editWeekModalLabel').innerText = "Edit Week " + weekNum + " Data";
        
        document.getElementById('editReason').value = '';

        var editModal = new bootstrap.Modal(document.getElementById('editWeekModal'));
        editModal.show();
    }

    function saveWeeklyData() {
        let empId = document.getElementById('editEmpId').value;
        let weekNum = document.getElementById('editWeekNum').value;
        let reason = document.getElementById('editReason').value;

        let formData = new URLSearchParams();
        formData.append('ajax', 'save_weekly_data');
        formData.append('emp_id', empId);
        formData.append('week_num', weekNum);
        formData.append('reason', reason);

        fetch('le_meeting.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                var editModalElement = document.getElementById('editWeekModal');
                var modalInstance = bootstrap.Modal.getInstance(editModalElement);
                modalInstance.hide();
                location.reload(); 
            } else {
                alert("Error saving data!");
            }
        })
        .catch(error => console.error('Error:', error));
    }

    // --- CHART JAVASCRIPT LOGIC ---
    Chart.register(ChartDataLabels);

    const teamData = <?= json_encode($team_data) ?>;
    
    const chartLabels = teamData.map(emp => emp.name.split(' ')); 
    const netProdValues = teamData.map(emp => emp.net_prod_decimal);
    const accuracyValues = teamData.map(emp => emp.accuracy / 100);

    const ctx = document.getElementById('productionChart').getContext('2d');

    const data = {
        labels: chartLabels,
        datasets: [
            {
                label: 'NET Prod',
                data: netProdValues,
                backgroundColor: '#1d4ed8', // Blue color
                borderRadius: 4,
                barPercentage: 0.8,
                categoryPercentage: 0.8
            },
            {
                label: 'Accuracy',
                data: accuracyValues,
                backgroundColor: '#15803d', // Green color
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