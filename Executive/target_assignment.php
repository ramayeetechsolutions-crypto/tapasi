<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Check if user is logged in
if (!isset($_SESSION['uid'])) {
    header("Location: ../index.php");
    exit;
}

$current_user = $_SESSION['username'] ?? '';
$employee_username = $current_user;

// Get current user details
$sql = "SELECT `employee_name`,`employee_id`,`user_role_id` FROM `employee_master` WHERE `employee_username` = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("s", $employee_username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $current_user_name = $row['employee_name'];
    $current_user_id = $row['employee_id'] ?? 0;
    $current_user_role = $row['user_role_id'] ?? 0;
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

function getCompanyWeekDefinitions() {
    return [
        1 => ['start_m' => 4, 'start_d' => 1, 'end_m' => 4, 'end_d' => 4, 'month' => 'Apr'],
        2 => ['start_m' => 4, 'start_d' => 6, 'end_m' => 4, 'end_d' => 11, 'month' => 'Apr'],
        3 => ['start_m' => 4, 'start_d' => 13, 'end_m' => 4, 'end_d' => 18, 'month' => 'Apr'],
        4 => ['start_m' => 4, 'start_d' => 20, 'end_m' => 4, 'end_d' => 25, 'month' => 'Apr'],
        5 => ['start_m' => 4, 'start_d' => 27, 'end_m' => 5, 'end_d' => 2, 'month' => 'Apr-May'],
        6 => ['start_m' => 5, 'start_d' => 4, 'end_m' => 5, 'end_d' => 9, 'month' => 'May'],
        7 => ['start_m' => 5, 'start_d' => 11, 'end_m' => 5, 'end_d' => 16, 'month' => 'May'],
        8 => ['start_m' => 5, 'start_d' => 18, 'end_m' => 5, 'end_d' => 23, 'month' => 'May'],
        9 => ['start_m' => 5, 'start_d' => 25, 'end_m' => 5, 'end_d' => 30, 'month' => 'May'],
        10 => ['start_m' => 6, 'start_d' => 1, 'end_m' => 6, 'end_d' => 6, 'month' => 'Jun'],
        11 => ['start_m' => 6, 'start_d' => 8, 'end_m' => 6, 'end_d' => 13, 'month' => 'Jun'],
        12 => ['start_m' => 6, 'start_d' => 15, 'end_m' => 6, 'end_d' => 21, 'month' => 'Jun'],
        13 => ['start_m' => 6, 'start_d' => 22, 'end_m' => 6, 'end_d' => 27, 'month' => 'Jun'],
        14 => ['start_m' => 6, 'start_d' => 29, 'end_m' => 7, 'end_d' => 4, 'month' => 'Jun-Jul'],
        15 => ['start_m' => 7, 'start_d' => 6, 'end_m' => 7, 'end_d' => 11, 'month' => 'Jul'],
        16 => ['start_m' => 7, 'start_d' => 13, 'end_m' => 7, 'end_d' => 18, 'month' => 'Jul'],
        17 => ['start_m' => 7, 'start_d' => 20, 'end_m' => 7, 'end_d' => 25, 'month' => 'Jul'],
        18 => ['start_m' => 7, 'start_d' => 27, 'end_m' => 8, 'end_d' => 1, 'month' => 'Jul-Aug'],
        19 => ['start_m' => 8, 'start_d' => 3, 'end_m' => 8, 'end_d' => 8, 'month' => 'Aug'],
        20 => ['start_m' => 8, 'start_d' => 10, 'end_m' => 8, 'end_d' => 14, 'month' => 'Aug'],
        21 => ['start_m' => 8, 'start_d' => 17, 'end_m' => 8, 'end_d' => 22, 'month' => 'Aug'],
        22 => ['start_m' => 8, 'start_d' => 24, 'end_m' => 8, 'end_d' => 29, 'month' => 'Aug'],
        23 => ['start_m' => 8, 'start_d' => 31, 'end_m' => 9, 'end_d' => 5, 'month' => 'Aug-Sep'],
        24 => ['start_m' => 9, 'start_d' => 7, 'end_m' => 9, 'end_d' => 12, 'month' => 'Sep'],
        25 => ['start_m' => 9, 'start_d' => 14, 'end_m' => 9, 'end_d' => 19, 'month' => 'Sep'],
        26 => ['start_m' => 9, 'start_d' => 21, 'end_m' => 9, 'end_d' => 26, 'month' => 'Sep'],
        27 => ['start_m' => 9, 'start_d' => 28, 'end_m' => 10, 'end_d' => 3, 'month' => 'Sep-Oct'],
        28 => ['start_m' => 10, 'start_d' => 5, 'end_m' => 10, 'end_d' => 10, 'month' => 'Oct'],
        29 => ['start_m' => 10, 'start_d' => 12, 'end_m' => 10, 'end_d' => 17, 'month' => 'Oct'],
        30 => ['start_m' => 10, 'start_d' => 20, 'end_m' => 10, 'end_d' => 24, 'month' => 'Oct'],
        31 => ['start_m' => 10, 'start_d' => 26, 'end_m' => 10, 'end_d' => 31, 'month' => 'Oct'],
        32 => ['start_m' => 11, 'start_d' => 2, 'end_m' => 11, 'end_d' => 6, 'month' => 'Nov'],
        33 => ['start_m' => 11, 'start_d' => 9, 'end_m' => 11, 'end_d' => 14, 'month' => 'Nov'],
        34 => ['start_m' => 11, 'start_d' => 16, 'end_m' => 11, 'end_d' => 21, 'month' => 'Nov'],
        35 => ['start_m' => 11, 'start_d' => 23, 'end_m' => 11, 'end_d' => 28, 'month' => 'Nov'],
        36 => ['start_m' => 11, 'start_d' => 30, 'end_m' => 12, 'end_d' => 5, 'month' => 'Nov-Dec'],
        37 => ['start_m' => 12, 'start_d' => 7, 'end_m' => 12, 'end_d' => 12, 'month' => 'Dec'],
        38 => ['start_m' => 12, 'start_d' => 14, 'end_m' => 12, 'end_d' => 19, 'month' => 'Dec'],
        39 => ['start_m' => 12, 'start_d' => 21, 'end_m' => 12, 'end_d' => 26, 'month' => 'Dec'],
        40 => ['start_m' => 12, 'start_d' => 28, 'end_m' => 1, 'end_d' => 2, 'month' => 'Dec-Jan'],
        41 => ['start_m' => 1, 'start_d' => 4, 'end_m' => 1, 'end_d' => 9, 'month' => 'Jan'],
        42 => ['start_m' => 1, 'start_d' => 11, 'end_m' => 1, 'end_d' => 14, 'month' => 'Jan'],
        43 => ['start_m' => 1, 'start_d' => 18, 'end_m' => 1, 'end_d' => 23, 'month' => 'Jan'],
        44 => ['start_m' => 1, 'start_d' => 25, 'end_m' => 1, 'end_d' => 30, 'month' => 'Jan'],
        45 => ['start_m' => 2, 'start_d' => 1, 'end_m' => 2, 'end_d' => 6, 'month' => 'Feb'],
        46 => ['start_m' => 2, 'start_d' => 8, 'end_m' => 2, 'end_d' => 13, 'month' => 'Feb'],
        47 => ['start_m' => 2, 'start_d' => 15, 'end_m' => 2, 'end_d' => 20, 'month' => 'Feb'],
        48 => ['start_m' => 2, 'start_d' => 22, 'end_m' => 2, 'end_d' => 27, 'month' => 'Feb'],
        49 => ['start_m' => 3, 'start_d' => 1, 'end_m' => 3, 'end_d' => 6, 'month' => 'Mar'],
        50 => ['start_m' => 3, 'start_d' => 8, 'end_m' => 3, 'end_d' => 13, 'month' => 'Mar'],
        51 => ['start_m' => 3, 'start_d' => 15, 'end_m' => 3, 'end_d' => 20, 'month' => 'Mar'],
        52 => ['start_m' => 3, 'start_d' => 22, 'end_m' => 3, 'end_d' => 27, 'month' => 'Mar']
    ];
}

function getWeekDates($week_number, $year = null) {
    if ($year === null) $year = (int)date('Y');
    else $year = (int)$year;
    
    $defs = getCompanyWeekDefinitions();
    if (isset($defs[$week_number])) {
        $w = $defs[$week_number];
        $sy = ($w['start_m'] >= 4) ? $year : ($year + 1);
        $ey = ($w['end_m'] >= 4 && $w['end_m'] >= $w['start_m']) ? $year : ($year + 1);
        
        $from_date = sprintf('%04d-%02d-%02d', $sy, $w['start_m'], $w['start_d']);
        $to_date = sprintf('%04d-%02d-%02d', $ey, $w['end_m'], $w['end_d']);
        
        return [
            'from_date' => $from_date,
            'to_date' => $to_date,
            'month' => $w['month']
        ];
    }
    
    return [
        'from_date' => date('Y-m-d'),
        'to_date' => date('Y-m-d'),
        'month' => ''
    ];
}

// Resolve logged-in user's Team Leader ID (for TL, SubTL, and TM)
$my_tl_id = 0;
if ($current_user_role == 3) {
    $my_tl_id = (int)$current_user_id;
} elseif (in_array((int)$current_user_role, [4, 11])) {
    $tl_lookup = $mysqli->prepare("SELECT tl.employee_id FROM employee_master me 
                                   INNER JOIN employee_master tl ON tl.user_role_id = 3 AND (
                                       CAST(me.reporting_manager AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(tl.employee_name AS CHAR) COLLATE utf8mb4_unicode_ci
                                       OR CAST(me.reporting_manager AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(tl.employee_username AS CHAR) COLLATE utf8mb4_unicode_ci
                                       OR EXISTS (
                                           SELECT 1 FROM employee_master sub
                                           WHERE (
                                               CAST(me.reporting_manager AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(sub.employee_name AS CHAR) COLLATE utf8mb4_unicode_ci
                                               OR CAST(me.reporting_manager AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(sub.employee_username AS CHAR) COLLATE utf8mb4_unicode_ci
                                           )
                                           AND (
                                               CAST(sub.reporting_manager AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(tl.employee_name AS CHAR) COLLATE utf8mb4_unicode_ci
                                               OR CAST(sub.reporting_manager AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(tl.employee_username AS CHAR) COLLATE utf8mb4_unicode_ci
                                           )
                                       )
                                   )
                                   WHERE me.employee_id = ? LIMIT 1");
    $tl_lookup->bind_param("i", $current_user_id);
    $tl_lookup->execute();
    $tl_res = $tl_lookup->get_result();
    if ($tl_r = $tl_res->fetch_assoc()) {
        $my_tl_id = (int)$tl_r['employee_id'];
    }
    $tl_lookup->close();
}

// Exact Database-Matched Team Member Lists (Verified from latest Weekly Report images):
// 1. CE Team (Total 12 members: K. Rajaramya, G. Saranya, E. Nandhini, T. Suvitha, D. Rohini, V. Indira, T. Lalitha, V. Malavika, G. Praveena, B. Shalini, S. Chandraleka, P. Rekha)
$ce_team_members = [
    'TPS004', 'TPS051', 'TPS053', 'TPSC43', 'TPS064', 'TPS078', 'TPSC03', 
    'TPS038', 'TPS076', 'TPSC51', 'TPS079', 'TPS093', 'TPSN07', 'TPSC33'
];

// 2. LE Team (Total 6 members: S. Dhanalakshmi, N. Ramya, S. Abinaya, B. Arulmozhi, S. Kiruthika Singaravel, T. Priyadharshini)
$le_team_members = [
    'TPS005', 'TPS011', 'TPS045', 'TPSC42', 'TPS101', 'TPS074', 'TPS095', 'TPSC47'
];

// 3. TS (PAG) & QC Team (Total 9 members: S. Manjuparkavi, V. Hariswetha, P. Kaviya, R. Anitha, V. Bharathi, P. Shanthi, Z. Shakina Banu, V. Jayachandran, S. Athithya)
$ts_qc_team_members = [
    'TPS032', 'TPS066', 'TPS070', 'TPS080', 'TPS033', 'TPS036', 'TPS072', 'TPSC41', 'TPSC21'
];

$all_uq_members = array_unique(array_merge($ce_team_members, $le_team_members, $ts_qc_team_members));

// Check if logged-in user belongs to UQ team (TL T.G. Bragadeeshwaran TPS002 / employee_id = 2)
$is_uq_team = ($my_tl_id == 2 || $current_user === 'TPS002' || stripos($current_user_name, 'Bragadeeshwaran') !== false || in_array($current_user, $all_uq_members));

// Check if logged-in user is Team Leader (TL)
$is_tl = ($current_user_role == 3 || $current_user === 'TPS002' || stripos($current_user_name, 'Bragadeeshwaran') !== false);
$assign_tl_id = ($my_tl_id > 0) ? $my_tl_id : 0;

$tms = [];
$active_list = [];

if ($is_uq_team) {
    // Resolve Team Filter Selection (CE, LE, TS, QC)
    $selected_team = $_GET['team_filter'] ?? '';

    // If no team selected in filter, auto-detect logged-in user's team for SubTL/TM:
    if (empty($selected_team) && !$is_tl) {
        if (in_array($current_user, $ce_team_members)) {
            $selected_team = 'CE';
        } elseif (in_array($current_user, $le_team_members)) {
            $selected_team = 'LE';
        } elseif (in_array($current_user, $ts_qc_team_members)) {
            $selected_team = 'TS';
        } else {
            $selected_team = 'CE';
        }
    }

    // Select active member list according to Team Filter:
    if ($selected_team === 'CE') {
        $active_list = $ce_team_members;
    } elseif ($selected_team === 'LE') {
        $active_list = $le_team_members;
    } elseif ($selected_team === 'TS' || $selected_team === 'QC') {
        $active_list = $ts_qc_team_members;
    } else {
        $active_list = $all_uq_members;
    }

    // Fetch TMs for Filter Dropdown, Assign Form, and Target Revision
    if (!empty($active_list)) {
        $in_list = "'" . implode("','", array_map([$mysqli, 'real_escape_string'], $active_list)) . "'";
        $query = "SELECT DISTINCT employee_id, employee_name, employee_username 
                  FROM employee_master 
                  WHERE employee_username IN ($in_list)
                    AND (status = 'Active' OR status IS NULL OR status = '')
                  ORDER BY employee_username ASC";
        $result = $mysqli->query($query);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $tms[] = $row;
            }
        }
    }
} else {
    // Non-UQ Team (e.g. R. Shanthi, S. Sivakumar, D. Mohan, M. Selvarani)
    $selected_team = '';
    
    // Resolve TL names / reporting manager names
    $tl_rep_names = [];
    if ($my_tl_id > 0) {
        $tl_lookup_q = $mysqli->query("SELECT employee_name, employee_username FROM employee_master WHERE employee_id = " . (int)$my_tl_id);
        if ($tl_lookup_q && $tl_r = $tl_lookup_q->fetch_assoc()) {
            $tl_rep_names[] = $tl_r['employee_name'];
            $tl_rep_names[] = str_replace(' ', '', $tl_r['employee_name']);
            $tl_rep_names[] = $tl_r['employee_username'];
        }
    }
    if (!empty($current_user_name)) {
        $tl_rep_names[] = $current_user_name;
        $tl_rep_names[] = str_replace(' ', '', $current_user_name);
        $tl_rep_names[] = $current_user;
    }
    $tl_rep_names = array_unique(array_filter($tl_rep_names));
    
    if (!empty($tl_rep_names)) {
        $in_tl_names = "'" . implode("','", array_map([$mysqli, 'real_escape_string'], $tl_rep_names)) . "'";
        $query = "SELECT DISTINCT employee_id, employee_name, employee_username 
                  FROM employee_master 
                  WHERE reporting_manager IN ($in_tl_names)
                    AND (status = 'Active' OR status IS NULL OR status = '')
                  ORDER BY employee_name ASC";
        $result = $mysqli->query($query);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $tms[] = $row;
                $active_list[] = $row['employee_username'];
            }
        }
    }
}

// Handle form submission (Only TL & SubTL allowed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((int)$current_user_role, [3, 11])) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'assign_target') {
        $tm_id = $_POST['tm_id'] ?? '';
        $target_limit = (int)($_POST['target_limit'] ?? 0);
        $week_input = $_POST['week_input'] ?? '';
        $year = (int)($_POST['year'] ?? date('Y'));
        
        // Validation
        $errors = [];
        
        if ($tm_id == '') {
            $errors[] = "Please select a Team Member";
        }
        
        if ($target_limit <= 0) {
            $errors[] = "Target limit must be greater than 0";
        }
        
        if (empty($week_input)) {
            $errors[] = "Week is required (e.g., W1, W2)";
        }
        
        // Parse week input (W1, W2, etc.)
        preg_match('/W(\d+)/i', $week_input, $matches);
        $week_number = isset($matches[1]) ? (int)$matches[1] : 0;
        
        if ($week_number < 1 || $week_number > 53) {
            $errors[] = "Week number must be between 1 and 53";
        }
        
        if (empty($errors)) {
            // Get dates for the week
            $week_dates = getWeekDates($week_number, $year);
            $from_date = $week_dates['from_date'];
            $to_date = $week_dates['to_date'];
            
            // Check for overlapping targets (exclude week_number = 0 target revision entries)
            $check_stmt = $mysqli->prepare("SELECT id FROM weekly_targets 
                                           WHERE tm_id = ? 
                                           AND week_number > 0
                                           AND (
                                               (from_date <= ? AND to_date >= ?) OR
                                               (from_date <= ? AND to_date >= ?) OR
                                               (from_date >= ? AND to_date <= ?)
                                           )");
            $check_stmt->bind_param("sssssss", $tm_id, $to_date, $from_date, $from_date, $to_date, $from_date, $to_date);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $errors[] = "Target already exists for this TM in the selected week";
            }
            $check_stmt->close();
        }
        
        if (empty($errors)) {
            // Insert target assignment
            $stmt = $mysqli->prepare("INSERT INTO weekly_targets 
                                     (tl_id, tm_id, target_limit, from_date, to_date, week_number, year, assigned_by, assigned_date) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $assigned_by = $current_user_id;
            $stmt->bind_param("isissiii", $assign_tl_id, $tm_id, $target_limit, $from_date, $to_date, $week_number, $year, $assigned_by);
            
            if ($stmt->execute()) {
                $success_message = "Target assigned successfully for Week $week_number!";
            } else {
                $errors[] = "Error assigning target: " . $stmt->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'update_progress') {
        $target_id = (int)($_POST['target_id'] ?? 0);
        $status = $_POST['status'] ?? 'Active';
        
        if ($target_id > 0) {
            // Get target details
            $target_stmt = $mysqli->prepare("SELECT tm_id, from_date, to_date FROM weekly_targets WHERE id = ?");
            $target_stmt->bind_param("i", $target_id);
            $target_stmt->execute();
            $target_result = $target_stmt->get_result();
            
            if ($target_row = $target_result->fetch_assoc()) {
                $tm_id = $target_row['tm_id'];
                $from_date = $target_row['from_date'];
                $to_date = $target_row['to_date'];
                
                // Get TM's username
                $tm_stmt = $mysqli->prepare("SELECT employee_username FROM employee_master WHERE employee_username = ?");
                $tm_stmt->bind_param("s", $tm_id);
                $tm_stmt->execute();
                $tm_result = $tm_stmt->get_result();
                
                $achieved_target = 0;
                if ($tm_row = $tm_result->fetch_assoc()) {
                    $tm_username = $tm_row['employee_username'];
                    
                    // Calculate achieved target
                    $achieved_query = "SELECT COUNT(*) as job_count 
                                      FROM jobs 
                                      WHERE (
                                          job_date BETWEEN ? AND ?
                                      ) AND (
                                          assigned_ce = ? OR 
                                          assigned_le = ? OR 
                                          assigned_pag = ? OR 
                                          assigned_qc = ? OR 
                                          assigned_xml = ?
                                      )";
                    
                    $achieved_stmt = $mysqli->prepare($achieved_query);
                    $achieved_stmt->bind_param("sssssss", $from_date, $to_date, 
                                              $tm_username, $tm_username, $tm_username, 
                                              $tm_username, $tm_username);
                    $achieved_stmt->execute();
                    $achieved_result = $achieved_stmt->get_result();
                    
                    if ($achieved_row = $achieved_result->fetch_assoc()) {
                        $achieved_target = $achieved_row['job_count'];
                    }
                    $achieved_stmt->close();
                }
                $tm_stmt->close();
                
                // Update weekly_targets
                $update_stmt = $mysqli->prepare("UPDATE weekly_targets 
                                               SET achieved_target = ?, status = ? 
                                               WHERE id = ?");
                $update_stmt->bind_param("isi", $achieved_target, $status, $target_id);
                
                if ($update_stmt->execute()) {
                    $success_message = "Progress updated successfully! Achieved target: " . $achieved_target;
                } else {
                    $errors[] = "Error updating progress: " . $update_stmt->error;
                }
                $update_stmt->close();
            }
            $target_stmt->close();
        }
    } elseif ($action === 'delete_target') {
        $target_id = (int)($_POST['target_id'] ?? 0);
        
        if ($target_id > 0) {
            $stmt = $mysqli->prepare("DELETE FROM weekly_targets WHERE id = ?");
            $stmt->bind_param("i", $target_id);
            
            if ($stmt->execute()) {
                $success_message = "Target deleted successfully!";
            }
            $stmt->close();
        }
    } elseif ($action === 'update_target') {
        $target_id = (int)($_POST['target_id'] ?? 0);
        $target_limit = (int)($_POST['target_limit'] ?? 0);
        $week_input = $_POST['week_input'] ?? '';
        $year = (int)($_POST['year'] ?? date('Y'));
        
        if ($target_id > 0 && $target_limit > 0 && !empty($week_input)) {
            preg_match('/W(\d+)/i', $week_input, $matches);
            $week_number = isset($matches[1]) ? (int)$matches[1] : 0;
            
            if ($week_number >= 1 && $week_number <= 53) {
                $week_dates = getWeekDates($week_number, $year);
                
                $stmt = $mysqli->prepare("UPDATE weekly_targets 
                                         SET target_limit = ?, week_number = ?, year = ?, 
                                         from_date = ?, to_date = ? 
                                         WHERE id = ?");
                $stmt->bind_param("iiissi", $target_limit, $week_number, $year, 
                                 $week_dates['from_date'], $week_dates['to_date'], $target_id);
                
                if ($stmt->execute()) {
                    $success_message = "Weekly Target updated successfully!";
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'save_revisions') {
        $tm_ids = $_POST['tm_id_rev'] ?? [];
        $rev_months = $_POST['rev_month'] ?? [];
        $current_targets = $_POST['current_target'] ?? [];
        $revision_targets = $_POST['revision_target'] ?? [];
        $success_count = 0;
        
        for ($i = 0; $i < count($tm_ids); $i++) {
            $tm = $tm_ids[$i];
            $rm = $rev_months[$i] ?? '';
            $ct = !empty($current_targets[$i]) ? (int)$current_targets[$i] : 0;
            $rt = !empty($revision_targets[$i]) ? (int)$revision_targets[$i] : 0;
            
            // Only process if month is selected
            if (!empty($rm)) {
                $check = $mysqli->prepare("SELECT id FROM weekly_targets WHERE tm_id = ? AND revision_month = ?");
                $check->bind_param("ss", $tm, $rm);
                $check->execute();
                $res = $check->get_result();
                
                if ($res->num_rows > 0) {
                    $row = $res->fetch_assoc();
                    $upd = $mysqli->prepare("UPDATE weekly_targets SET target_limit = ?, revision_target = ? WHERE id = ?");
                    $upd->bind_param("iii", $ct, $rt, $row['id']);
                    if($upd->execute()) $success_count++;
                    $upd->close();
                } else {
                    // Fix for strict mode: replaced '0000-00-00' with CURDATE() and added 'status'
                        $ins = $mysqli->prepare("INSERT INTO weekly_targets (tl_id, tm_id, revision_month, target_limit, revision_target, week_number, year, assigned_by, assigned_date, from_date, to_date, status) VALUES (?, ?, ?, ?, ?, 0, 0, ?, NOW(), CURDATE(), CURDATE(), 'Active')");
                        $ins->bind_param("issiii", $assign_tl_id, $tm, $rm, $ct, $rt, $current_user_id);
                        if($ins->execute()) $success_count++;
                        $ins->close();
                }
                $check->close();
            }
        }
        if ($success_count > 0) {
            $success_message = "$success_count Target Revisions saved successfully!";
        }
    }
}

// Get existing targets
$targets = [];
$target_query = "SELECT 
    wt.id,
    wt.tl_id,
    wt.tm_id,
    wt.target_limit,
    wt.achieved_target,
    wt.from_date,
    wt.to_date,
    wt.week_number,
    wt.year,
    wt.status,
    wt.assigned_date,
    COALESCE(NULLIF(tl.employee_name, ''), tm.reporting_manager) as tl_name,
    tm.employee_name as tm_name,
    tm.employee_username as tm_username,
    ass.employee_name as assigned_by_name
FROM weekly_targets wt
LEFT JOIN employee_master tl ON wt.tl_id = tl.employee_id
LEFT JOIN employee_master tm ON wt.tm_id = tm.employee_username
LEFT JOIN employee_master ass ON wt.assigned_by = ass.employee_id
WHERE wt.week_number > 0";

$params = [];
$types = '';

if ($is_uq_team) {
    if (!empty($active_list)) {
        $in_ph = implode(',', array_fill(0, count($active_list), '?'));
        $target_query .= " AND wt.tm_id IN ($in_ph)";
        foreach ($active_list as $u) {
            $params[] = $u;
            $types .= 's';
        }
    }
} else {
    // Non-UQ team: show targets only for their team members or assigned under their TL
    if (!empty($active_list)) {
        $in_ph = implode(',', array_fill(0, count($active_list), '?'));
        $target_query .= " AND (wt.tm_id IN ($in_ph) OR wt.tl_id = ?)";
        foreach ($active_list as $u) {
            $params[] = $u;
            $types .= 's';
        }
        $params[] = $my_tl_id;
        $types .= 'i';
    } else {
        $target_query .= " AND wt.tl_id = ?";
        $params[] = $my_tl_id;
        $types .= 'i';
    }
}

// Apply Team Member filter if specified
if (isset($_GET['tm_filter']) && $_GET['tm_filter'] !== '') {
    $target_query .= " AND wt.tm_id = ?";
    $params[] = $_GET['tm_filter'];
    $types .= 's';
}

// Apply week filter if specified
if (isset($_GET['week_filter']) && $_GET['week_filter']) {
    $week_filter = $_GET['week_filter'];
    if ($week_filter === 'current_week') {
        $target_query .= " AND wt.week_number = WEEK(CURDATE(), 1) AND wt.year = YEAR(CURDATE())";
    } elseif ($week_filter === 'next_week') {
        $target_query .= " AND wt.week_number = WEEK(DATE_ADD(CURDATE(), INTERVAL 1 WEEK), 1) AND wt.year = YEAR(CURDATE())";
    } elseif ($week_filter === 'previous_week') {
        $target_query .= " AND wt.week_number = WEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1) AND wt.year = YEAR(CURDATE())";
    } elseif (preg_match('/W(\d+)/i', $week_filter, $matches)) {
        $week_num = (int)$matches[1];
        $target_query .= " AND wt.week_number = ?";
        $params[] = $week_num;
        $types .= 'i';
    }
}

// Apply Year filter independently (works with All Weeks too)
if (isset($_GET['year_filter']) && $_GET['year_filter'] !== '') {
    $target_query .= " AND wt.year = ?";
    $params[] = (int)$_GET['year_filter'];
    $types .= 'i';
}

$target_query .= " ORDER BY wt.id DESC";

if (!empty($params)) {
    $stmt = $mysqli->prepare($target_query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $mysqli->query($target_query);
}

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $targets[] = $row;
    }
}

// Calculate percentage achieved
foreach ($targets as &$target) {
    if ($target['target_limit'] > 0) {
        $target['achieved_percentage'] = round(($target['achieved_target'] / $target['target_limit']) * 100, 2);
    } else {
        $target['achieved_percentage'] = 0;
    }
}
unset($target); // FIX: Breaks the reference to prevent overwriting the array in the next loop

$pageTitle = "Weekly Target Assignment";
require 'header.php';
?>

<style>
    body {
        background-color: var(--light, #f5f7fa);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    .main-content {
        padding: 4px 3% 20px 3%;
        margin: 0 auto;
        margin-top: 0;
    }

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
    }
    .page-title-container h1, .page-title-container h4 {
        margin: 0;
        font-size: 1.15rem;
        font-weight: 600;
        color: white;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* Sticky Table Header */
    .table thead th {
        position: -webkit-sticky !important;
        position: sticky !important;
        top: 70px !important;
        z-index: 800 !important;
        background-color: var(--ash, #6c757d) !important;
        font-weight: 600;
        font-size: 0.82rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        color: white !important;
        padding: 0.75rem 0.5rem;
        border: 1px solid #6c757d !important;
        box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.15);
    }
    .table {
        border-collapse: separate;
        border-spacing: 0;
    }
    .card, .card-body, .table-responsive, .table-container {
        overflow: visible !important;
    }

    .table td {
        vertical-align: middle;
    }
    .card {
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        border: 1px solid rgba(0, 0, 0, 0.125);
        margin-bottom: 20px;
    }
    .card-header {
        background-color: var(--ash, #6c757d) !important;
        color: white !important;
    }
    .progress {
        height: 20px;
    }
    .bg-target {
        background-color: #6f42c1;
    }
    .filter-section {
        background-color: #ffffff;
        border-radius: 12px;
        padding: 16px 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        border: 1px solid #e9ecef;
    }
    .week-badge {
        font-size: 1.1em;
        padding: 8px 12px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }
    .target-cell {
        background-color: #e9ecef;
        padding: 10px;
        border-radius: 5px;
        text-align: center;
        font-weight: bold;
    }
    .week-input-container {
        display: flex;
        gap: 10px;
    }
    .week-input-container input {
        text-transform: uppercase;
    }
    .progress-cell {
        min-width: 200px;
    }
</style>

<div class="main-content">
    <div class="page-title-container">
        <h1 class="mb-0">
            <i class="fas fa-bullseye"></i> Employee Target
        </h1>
    </div>

    <?php if(isset($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= h($success_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if(!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <ul class="mb-0">
            <?php foreach($errors as $error): ?>
            <li><?= h($error) ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Filter Section -->
    <div class="filter-section">
        <form method="get" class="row g-2 align-items-end">
            <!-- Team Filter (CE, LE, TS, QC) - Only for UQ Team -->
            <?php if ($is_uq_team): ?>
            <div class="col-md-2">
                <label for="team_filter" class="form-label small fw-semibold">Team</label>
                <select name="team_filter" id="team_filter" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php if ($is_tl): ?>
                    <option value="" <?= empty($_GET['team_filter']) ? 'selected' : '' ?>>All Teams</option>
                    <?php endif; ?>
                    <option value="CE" <?= $selected_team === 'CE' ? 'selected' : '' ?>>CE</option>
                    <option value="LE" <?= $selected_team === 'LE' ? 'selected' : '' ?>>LE</option>
                    <option value="TS" <?= $selected_team === 'TS' ? 'selected' : '' ?>>TS (PAG)</option>
                    <option value="QC" <?= $selected_team === 'QC' ? 'selected' : '' ?>>QC</option>
                </select>
            </div>
            <?php endif; ?>

            <div class="col-md">
                <label for="tm_filter" class="form-label small fw-semibold">Team Member</label>
                <select name="tm_filter" id="tm_filter" class="form-select form-select-sm">
                    <option value="">All Team Members</option>
                    <?php foreach($tms as $tm): ?>
                    <option value="<?= h($tm['employee_username']) ?>" <?= (isset($_GET['tm_filter']) && $_GET['tm_filter'] == $tm['employee_username']) ? 'selected' : '' ?>>
                        <?= h($tm['employee_name']) ?> (<?= h($tm['employee_username']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label for="week_filter" class="form-label small fw-semibold">Week</label>
                <select name="week_filter" id="week_filter" class="form-select form-select-sm">
                    <option value="">All Weeks</option>
                    <?php 
                    $filter_year = $_GET['year_filter'] ?? date('Y');
                    for($w=1; $w<=52; $w++): 
                        $w_dates = getWeekDates($w, $filter_year);
                        $from_txt = date('d-M-y', strtotime($w_dates['from_date']));
                        $to_txt = date('d-M-y', strtotime($w_dates['to_date']));
                    ?>
                        <option value="W<?= $w ?>" <?= (isset($_GET['week_filter']) && $_GET['week_filter'] == 'W'.$w) ? 'selected' : '' ?>>
                            W<?= str_pad($w, 2, '0', STR_PAD_LEFT) ?> (<?= $from_txt ?> to <?= $to_txt ?>)
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label for="year_filter" class="form-label small fw-semibold">Year</label>
                <select name="year_filter" id="year_filter" class="form-select form-select-sm">
                    <?php for($y = 2023; $y <= 2030; $y++): ?>
                    <option value="<?= $y ?>" <?= ($_GET['year_filter'] ?? date('Y')) == $y ? 'selected' : '' ?>>
                        <?= $y ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="col-md-auto d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm px-3">
                    <i class="fas fa-filter me-1"></i> Apply Filters
                </button>
                <a href="target_assignment.php" class="btn btn-outline-secondary btn-sm px-3">
                    <i class="fas fa-undo me-1"></i> Reset
                </a>
            </div>
        </form>
    </div>

        <!-- ROW 1: Left: Assign Weekly Target (View Only) & Quick Stats (col-md-4) | Right: Assigned Targets Table (col-md-8) -->
        <div class="row">
            <!-- Left Side: Assign Target Form (View Only) & Quick Stats -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Assign Weekly Target</h5>
                    </div>
                    <div class="card-body">
                        <form autocomplete="off">
                            <div class="mb-3">
                                <label for="tm_id" class="form-label">Team Member</label>
                                <select name="tm_id" id="tm_id" class="form-select" disabled>
                                    <option value="<?= h($current_user) ?>" selected>
                                        <?= h($current_user_name) ?> (<?= h($current_user) ?>)
                                    </option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="target_limit" class="form-label">Target Limit</label>
                                <input type="number" class="form-control" id="target_limit" name="target_limit" 
                                       readonly disabled placeholder="Assigned by Team Leader">
                            </div>
                            
                            <div class="mb-3">
                                <label for="week_input" class="form-label">Week</label>
                                <div class="week-input-container">
                                    <select class="form-select" id="week_input" name="week_input" disabled>
                                        <option value="">Select Week</option>
                                        <?php 
                                        $curr_form_year = date('Y');
                                        for($w=1; $w<=52; $w++): 
                                            $w_dates = getWeekDates($w, $curr_form_year);
                                            $from_txt = date('d-M-y', strtotime($w_dates['from_date']));
                                            $to_txt = date('d-M-y', strtotime($w_dates['to_date']));
                                        ?>
                                            <option value="W<?= $w ?>">W<?= str_pad($w, 2, "0", STR_PAD_LEFT) ?> (<?= $from_txt ?> to <?= $to_txt ?>)</option>
                                        <?php endfor; ?>
                                    </select>
                                    <input type="number" class="form-control" id="year" name="year" 
                                           value="<?= date('Y') ?>" style="width: 100px;" readonly disabled>
                                </div>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i> Week assigned by TL / SubTL
                                </div>
                            </div>
                            
                            <div id="weekDates" class="alert alert-info">
                                <small><i class="fas fa-lock me-1"></i> Target assignment is view-only for team members</small>
                            </div>
                            
                            <div class="d-grid">
                                <button type="button" class="btn btn-success" disabled style="opacity: 0.65; cursor: not-allowed;">
                                    <i class="fas fa-paper-plane me-1"></i> Assign Target
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Quick Stats</h6>
                    </div>
                    <div class="card-body">
                        <?php if(!empty($targets)): ?>
                        <?php 
                        $total_target = array_sum(array_column($targets, 'target_limit'));
                        $total_achieved = array_sum(array_column($targets, 'achieved_target'));
                        $overall_percentage = $total_target > 0 ? round(($total_achieved / $total_target) * 100, 2) : 0;
                        ?>
                        <div class="text-center">
                            <div class="display-6 fw-bold 
                                <?= $overall_percentage >= 100 ? 'text-success' : 
                                   ($overall_percentage >= 70 ? 'text-warning' : 'text-danger') ?>">
                                <?= $overall_percentage ?>%
                            </div>
                            <small class="text-muted">Overall Achievement Rate</small>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-6 text-center">
                                <div class="h4 fw-bold text-primary"><?= count($targets) ?></div>
                                <small class="text-muted">Targets</small>
                            </div>
                            <div class="col-6 text-center">
                                <div class="h4 fw-bold text-success"><?= $total_achieved ?></div>
                                <small class="text-muted">Achieved</small>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="text-center text-muted">
                            <i class="fas fa-chart-bar fa-2x mb-2"></i>
                            <p>No data available</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Side: Assigned Targets Table -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list-check me-2"></i>Assigned Targets</h5>
                        <span class="badge bg-light text-dark">
                            <?= count($targets) ?> Target(s)
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-container" style="overflow: visible;">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th width="50">S.No</th>
                                        <th>Team Member</th>
                                        <th width="100">Week</th>
                                        <th width="130" class="text-center">Target Limit</th>
                                        <th width="240">Progress</th>
                                        <th width="100">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(!empty($targets)): ?>
                                        <?php $sno = 1; foreach($targets as $target): ?>
                                        <tr>
                                            <td class="fw-bold"><?= $sno++ ?></td>
                                            <td>
                                                <div class="fw-semibold"><?= h($target['tm_name']) ?></div>
                                                <small class="text-muted"><?= h($target['tm_username']) ?></small>
                                            </td>
                                            <td>
                                                <span class="badge week-badge">W<?= h($target['week_number']) ?></span>
                                                <small class="d-block text-muted"><?= h($target['year']) ?></small>
                                                <small class="d-block"><?= formatDate($target['from_date']) ?> - <?= formatDate($target['to_date']) ?></small>
                                            </td>
                                            <td class="text-center">
                                                <div class="target-cell">
                                                    <?= h($target['target_limit']) ?>
                                                </div>
                                            </td>
                                            <td class="progress-cell">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <small>Achieved: <?= h($target['achieved_target']) ?></small>
                                                    <small class="fw-bold 
                                                        <?= $target['achieved_percentage'] >= 100 ? 'text-success' : 
                                                           ($target['achieved_percentage'] >= 70 ? 'text-warning' : 'text-danger') ?>">
                                                        <?= h($target['achieved_percentage']) ?>%
                                                    </small>
                                                </div>
                                                <div class="progress">
                                                    <div class="progress-bar <?= $target['achieved_percentage'] >= 100 ? 'bg-success' : ($target['achieved_percentage'] >= 70 ? 'bg-warning' : 'bg-danger') ?>" 
                                                         role="progressbar" 
                                                         style="width: <?= min($target['achieved_percentage'], 100) ?>%">
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge 
                                                    <?= $target['status'] == 'Active' ? 'bg-primary' : 
                                                       ($target['status'] == 'Completed' ? 'bg-success' : 'bg-danger') ?>">
                                                    <?= h($target['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-5">
                                                <div class="text-muted">
                                                    <i class="fas fa-bullseye fa-3x mb-3"></i>
                                                    <h5>No targets found</h5>
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
        </div>

        <!-- ROW 2: Target Revision Table (Full Width) -->
        <div class="row mt-4">
            <div class="col-12">
                <form method="post">
                    <input type="hidden" name="action" value="save_revisions">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-eye me-2"></i>Target Revision</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-container" style="overflow: visible;">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th width="120">ID</th>
                                            <th width="200">Month with year</th>
                                            <th>Team Member</th>
                                            <th width="180">Current Target</th>
                                            <th width="180">Target Revision</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                            // Fetch saved revisions from DB to show them in input fields
                                            $revisions = [];
                                            $rev_res = $mysqli->query("SELECT tm_id, revision_month, target_limit, revision_target FROM weekly_targets WHERE week_number = 0 ORDER BY id ASC");
                                            if ($rev_res && $rev_res->num_rows > 0) {
                                                while($row = $rev_res->fetch_assoc()) {
                                                    $revisions[$row['tm_id']] = $row;
                                                }
                                            }
                                        ?>
                                        <?php if(!empty($tms)): ?>
                                            <?php foreach($tms as $tm): 
                                                $tm_un = $tm['employee_username'];
                                                $saved_month = isset($revisions[$tm_un]) ? $revisions[$tm_un]['revision_month'] : '';
                                                $saved_ct = isset($revisions[$tm_un]) ? $revisions[$tm_un]['target_limit'] : '';
                                                $saved_rt = isset($revisions[$tm_un]) ? $revisions[$tm_un]['revision_target'] : '';
                                            ?>
                                            <tr>
                                                <td class="fw-bold">
                                                    <?= h($tm['employee_username']) ?>
                                                    <input type="hidden" name="tm_id_rev[]" value="<?= h($tm['employee_username']) ?>">
                                                </td>
                                                <td>
                                                    <input type="month" class="form-control form-control-sm" name="rev_month[]" value="<?= h($saved_month) ?>" readonly disabled>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?= h($tm['employee_name']) ?></div>
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control form-control-sm" name="current_target[]" placeholder="—" min="0" value="<?= h($saved_ct) ?>" readonly disabled>
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control form-control-sm" name="revision_target[]" placeholder="—" min="0" value="<?= h($saved_rt) ?>" readonly disabled>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4 text-muted">
                                                    No team members found
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </div>

    <!-- Progress Update Modal -->
    <div class="modal fade" id="progressModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Target Progress</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="progressForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_progress">
                        <input type="hidden" name="target_id" id="modal_target_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Team Member</label>
                            <input type="text" class="form-control" id="modal_tm_name" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Week</label>
                            <input type="text" class="form-control" id="modal_week" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Calculated Achieved Target</label>
                            <div class="alert alert-info">
                                <h4 class="text-center" id="achieved_count">Calculating...</h4>
                                <p class="text-center small mb-0">This is automatically calculated from assigned jobs</p>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select name="status" id="status" class="form-select">
                                <option value="Active">Active</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Progress</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Target Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Target</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="editForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_target">
                        <input type="hidden" name="target_id" id="edit_target_id">
                        
                        <div class="mb-3">
                            <label for="edit_target_limit" class="form-label">Target Limit</label>
                            <input type="number" class="form-control" id="edit_target_limit" name="target_limit" min="1" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_week_input" class="form-label">Week</label>
                            <div class="week-input-container">
                                <select class="form-select" id="edit_week_input" name="week_input" required>
                                    <option value="">Select Week</option>
                                    <?php for($w=1; $w<=53; $w++): ?>
                                        <option value="W<?= $w ?>">W<?= str_pad($w, 2, "0", STR_PAD_LEFT) ?></option>
                                    <?php endfor; ?>
                                </select>
                                <input type="number" class="form-control" id="edit_year" name="year" 
                                       min="2023" max="2030" style="width: 100px;">
                            </div>
                        </div>
                        
                        <div id="editWeekDates" class="alert alert-info">
                            <small>Week dates will be calculated automatically</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Target</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const companyWeeks = <?= json_encode(getCompanyWeekDefinitions()) ?>;

        function updateWeekSelectOptions(selectEl, year) {
            if (!selectEl) return;
            const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
            Array.from(selectEl.options).forEach(opt => {
                const m = opt.value.match(/W(\d+)/i);
                if (m) {
                    const wn = parseInt(m[1]);
                    const w = companyWeeks[wn];
                    if (w) {
                        const sy = (w.start_m >= 4) ? year : (year + 1);
                        const ey = (w.end_m >= 4 && w.end_m >= w.start_m) ? year : (year + 1);
                        const syShort = String(sy).slice(-2);
                        const eyShort = String(ey).slice(-2);
                        const sStr = `${String(w.start_d).padStart(2, '0')}-${monthNames[w.start_m - 1]}-${syShort}`;
                        const eStr = `${String(w.end_d).padStart(2, '0')}-${monthNames[w.end_m - 1]}-${eyShort}`;
                        opt.textContent = `W${String(wn).padStart(2, '0')} (${sStr} to ${eStr})`;
                    }
                }
            });
        }

        function calculateWeekDates(weekInput, yearInput, displayDiv) {
            const weekMatch = weekInput.value.match(/W(\d+)/i);
            if (weekMatch) {
                const weekNumber = parseInt(weekMatch[1]);
                const year = parseInt(yearInput.value) || new Date().getFullYear();
                const w = companyWeeks[weekNumber];
                if (w) {
                    const sy = (w.start_m >= 4) ? year : (year + 1);
                    const ey = (w.end_m >= 4 && w.end_m >= w.start_m) ? year : (year + 1);
                    const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                    const startDateStr = `${String(w.start_d).padStart(2, '0')}-${monthNames[w.start_m - 1]}-${sy}`;
                    const endDateStr = `${String(w.end_d).padStart(2, '0')}-${monthNames[w.end_m - 1]}-${ey}`;
                    displayDiv.innerHTML = `
                        <div class="fw-bold">Week ${weekNumber}, ${year} (${w.month})</div>
                        <small>${startDateStr} to ${endDateStr}</small>
                    `;
                }
            }
        }

        // Calculate and display week dates when week input changes
        document.addEventListener('DOMContentLoaded', function() {
            const weekInput = document.getElementById('week_input');
            const yearInput = document.getElementById('year');
            const weekDatesDiv = document.getElementById('weekDates');
            
            const editWeekInput = document.getElementById('edit_week_input');
            const editYearInput = document.getElementById('edit_year');
            const editWeekDatesDiv = document.getElementById('editWeekDates');
            
            // For new target form
            if (weekInput) {
                weekInput.addEventListener('change', function() {
                    calculateWeekDates(weekInput, yearInput, weekDatesDiv);
                });
            }
            
            if (yearInput) {
                yearInput.addEventListener('change', function() {
                    updateWeekSelectOptions(weekInput, parseInt(this.value) || new Date().getFullYear());
                    calculateWeekDates(weekInput, yearInput, weekDatesDiv);
                });
            }
            
            // For edit form
            if (editWeekInput) {
                editWeekInput.addEventListener('change', function() {
                    calculateWeekDates(editWeekInput, editYearInput, editWeekDatesDiv);
                });
            }
            
            if (editYearInput) {
                editYearInput.addEventListener('change', function() {
                    updateWeekSelectOptions(editWeekInput, parseInt(this.value) || new Date().getFullYear());
                    calculateWeekDates(editWeekInput, editYearInput, editWeekDatesDiv);
                });
            }

            // For top filter
            const filterYearSelect = document.getElementById('year_filter');
            if (filterYearSelect) {
                filterYearSelect.addEventListener('change', function() {
                    updateWeekSelectOptions(document.getElementById('week_filter'), parseInt(this.value) || new Date().getFullYear());
                });
            }
            
            // Calculate initial week dates
            if (weekInput && yearInput && weekDatesDiv) {
                calculateWeekDates(weekInput, yearInput, weekDatesDiv);
            }
        });
        
        // Update progress
        function updateProgress(targetId, tmName) {
            document.getElementById('modal_target_id').value = targetId;
            document.getElementById('modal_tm_name').value = tmName;
            
            fetch(`calculate_achieved_target.php?target_id=${targetId}`)
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        document.getElementById('achieved_count').textContent = data.achieved_target;
                        document.getElementById('status').value = data.status;
                        
                        const achievedEl = document.getElementById('achieved_count');
                        if (data.achieved_target >= data.target_limit) {
                            achievedEl.className = "text-center text-success";
                        } else if (data.achieved_target >= (data.target_limit * 0.7)) {
                            achievedEl.className = "text-center text-warning";
                        } else {
                            achievedEl.className = "text-center text-danger";
                        }
                    } else {
                        document.getElementById('achieved_count').textContent = "Error calculating";
                        document.getElementById('achieved_count').className = "text-center text-danger";
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('achieved_count').textContent = "Error calculating";
                    document.getElementById('achieved_count').className = "text-center text-danger";
                });
            
            const modal = new bootstrap.Modal(document.getElementById('progressModal'));
            modal.show();
        }
        
        // Edit target
        function editTarget(targetId, targetLimit, weekInput, year) {
            document.getElementById('edit_target_id').value = targetId;
            document.getElementById('edit_target_limit').value = targetLimit;
            document.getElementById('edit_week_input').value = weekInput;
            document.getElementById('edit_year').value = year;
            
            // Trigger date calculation
            const editWeekInput = document.getElementById('edit_week_input');
            const editYearInput = document.getElementById('edit_year');
            const editWeekDatesDiv = document.getElementById('editWeekDates');
            
            updateWeekSelectOptions(editWeekInput, parseInt(year) || new Date().getFullYear());
            calculateWeekDates(editWeekInput, editYearInput, editWeekDatesDiv);
            
            const modal = new bootstrap.Modal(document.getElementById('editModal'));
            modal.show();
        }
            
            const modal = new bootstrap.Modal(document.getElementById('editModal'));
            modal.show();
        }
        
        // Auto-dismiss alerts
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert:not(.alert-info)');
            alerts.forEach(alert => {
                if (alert.isConnected) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            });
        }, 5000);
    </script>
</body>
</html>