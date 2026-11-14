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

// Initialize default values to prevent undefined variable warnings
$current_user_name = $_SESSION['teamname'] ?? $_SESSION['membername'] ?? $_SESSION['username'] ?? 'User';
$current_user_id = $_SESSION['uid'] ?? 0;
$current_user_role = 3;

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

if (!function_exists('getInitials')) {
    function getInitials($name) {
        $words = preg_split('/[\s\.\-_]+/', trim((string)$name));
        $initials = '';
        foreach ($words as $w) {
            if (!empty($w)) {
                $initials .= strtoupper($w[0]);
            }
            if (strlen($initials) >= 2) break;
        }
        return $initials ?: 'TM';
    }
}

if (!function_exists('getAvatarColors')) {
    function getAvatarColors($string) {
        $palettes = [
            ['bg' => '#e0f2fe', 'border' => '#bae6fd', 'text' => '#0369a1'],
            ['bg' => '#ecfdf5', 'border' => '#a7f3d0', 'text' => '#047857'],
            ['bg' => '#fef3c7', 'border' => '#fde68a', 'text' => '#b45309'],
            ['bg' => '#ede9fe', 'border' => '#ddd6fe', 'text' => '#6d28d9'],
            ['bg' => '#fce7f3', 'border' => '#fbcfe8', 'text' => '#be185d'],
            ['bg' => '#e0e7ff', 'border' => '#c7d2fe', 'text' => '#4338ca'],
            ['bg' => '#f1f5f9', 'border' => '#e2e8f0', 'text' => '#334155'],
        ];
        $hash = abs(crc32((string)$string));
        return $palettes[$hash % count($palettes)];
    }
}

if (!function_exists('getWeekDates')) {
    function getWeekDates($week_number, $year = null) {
        if ($year === null) $year = date('Y');
        
        // Financial / Operational year starts from April 1
        $april_first = strtotime($year . '-04-01');
        $day_of_week = date('N', $april_first); // 1 (Mon) to 7 (Sun)
        
        // First Monday on or after April 1
        if ($day_of_week == 1) {
            $first_monday = $april_first;
        } else {
            $first_monday = strtotime('+' . (8 - $day_of_week) . ' days', $april_first);
        }
        
        $week_start = strtotime('+' . (($week_number - 1) * 7) . ' days', $first_monday);
        $week_end = strtotime('+5 days', $week_start); // Monday to Saturday (e.g. 26 Oct to 31 Oct)
        
        return [
            'from_date' => date('Y-m-d', $week_start),
            'to_date' => date('Y-m-d', $week_end)
        ];
    }
}

// Get TLs for dropdown (only if current user is Manager/Admin)
$tls = [];
if ($current_user_role == 3) {
    $stmt = $mysqli->prepare("SELECT employee_id, employee_name, employee_username FROM employee_master 
                             WHERE user_role_id = 3 AND status = 'Active' 
                             ORDER BY employee_name");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $tls[] = $row;
    }
    $stmt->close();
}

// Get selected TL
$selected_tl_id = isset($_GET['tl_id']) ? (int)$_GET['tl_id'] : 0;
if ($current_user_role == 3) {
    $selected_tl_id = $current_user_id;
}

// Get TMs for the selected TL (Only UQ Team Members)
$tms = [];
if ($current_user_role > 0) {
    $query = "SELECT DISTINCT em.employee_id, em.employee_name, em.employee_username 
              FROM employee_master em
              LEFT JOIN team_master tm_tbl ON em.team_id = tm_tbl.team_id
              WHERE (
                  tm_tbl.team_name LIKE '%UQ%'
                  OR em.team_id LIKE '%UQ%'
                  OR em.process LIKE '%UQ%'
                  OR EXISTS (
                      SELECT 1 FROM process_master pm 
                      WHERE FIND_IN_SET(pm.process_id, em.process) 
                      AND pm.process_name LIKE '%UQ%'
                  )
              ) 
              AND em.status = 'Active' 
              ORDER BY em.employee_username ASC";
              
    $stmt = $mysqli->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $tms[] = $row;
    }
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            
            // Check for overlapping targets
            $check_stmt = $mysqli->prepare("SELECT id FROM weekly_targets 
                                           WHERE tm_id = ? 
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
            $stmt->bind_param("isissiii", $selected_tl_id, $tm_id, $target_limit, $from_date, $to_date, $week_number, $year, $assigned_by);
            
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
                $tm_stmt->bind_param("i", $tm_id);
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
                        $ins->bind_param("issiii", $selected_tl_id, $tm, $rm, $ct, $rt, $current_user_id);
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
    tl.employee_name as tl_name,
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

if ($selected_tl_id > 0) {
    if ($current_user_role == 2) {
        $target_query .= " AND wt.tl_id = ?";
        $params[] = $selected_tl_id;
        $types .= 'i';
    } elseif ($current_user_role == 3 && isset($_GET['tl_id'])) {
        $target_query .= " AND wt.tl_id = ?";
        $params[] = $selected_tl_id;
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
        
        if (isset($_GET['year_filter']) && $_GET['year_filter']) {
            $target_query .= " AND wt.year = ?";
            $params[] = $_GET['year_filter'];
            $types .= 'i';
        }
    }
}

$target_query .= " ORDER BY wt.year DESC, wt.week_number DESC, tm.employee_name";

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

$pageTitle = "Employee Target Assignment - Tapasi ERP";
$currentPage = "target_assignment.php";
require_once __DIR__ . '/header.php';
?>
<!-- Fonts & Page Specific Styles -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        /* =========================================================================
           SENIOR DEVELOPER STYLING - EMPLOYEE TARGET MANAGEMENT
           Brand Palette: Ash Slate (#2c3e50 / #34495e) & Action Blue (#3498db)
           ========================================================================= */
        :root {
            --primary: #3498db;
            --primary-hover: #2980b9;
            --primary-light: #ebf5fb;
            --primary-border: #aed6f1;
            --ash-dark: #2c3e50;
            --ash-medium: #34495e;
            --ash-deep: #243342;
            --ash-slate: #5a6a7a;
            --ash-bg: #f8fafc;
            --ash-border: #e2e8f0;
            --emerald: #10b981;
            --emerald-light: #ecfdf5;
            --amber: #f59e0b;
            --amber-light: #fffbeb;
            --rose: #ef4444;
            --rose-light: #fef2f2;
        }

        body {
            background-color: var(--ash-bg);
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #1e293b;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .main-content {
            padding: 90px 28px 40px 28px;
            max-width: 1480px;
            margin: 0 auto;
        }

        /* 1. Page Hero Header Banner */
        .page-hero-banner {
            background: #ffffff;
            border-radius: 14px;
            padding: 20px 24px;
            border: 1px solid var(--ash-border);
            box-shadow: 0 4px 16px rgba(44, 62, 80, 0.04);
            margin-bottom: 22px;
        }

        .hero-icon-pill {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--ash-dark) 0%, var(--ash-medium) 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            box-shadow: 0 4px 12px rgba(44, 62, 80, 0.2);
            flex-shrink: 0;
        }

        .hero-title {
            font-size: 1.45rem;
            font-weight: 800;
            color: var(--ash-dark);
            letter-spacing: -0.02em;
        }

        .hero-subtitle {
            font-size: 0.86rem;
            color: var(--ash-slate);
            font-weight: 500;
        }

        .hero-stat-badge {
            background: #f8fafc;
            border: 1px solid var(--ash-border);
            padding: 8px 16px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
        }

        /* 2. Floating Filter Card */
        .filter-card-modern {
            background: #ffffff;
            border-radius: 14px;
            padding: 18px 22px;
            border: 1px solid var(--ash-border);
            box-shadow: 0 4px 16px rgba(44, 62, 80, 0.04);
            margin-bottom: 22px;
        }

        .form-label-custom {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--ash-medium);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
        }

        .custom-input-modern, .custom-select-modern {
            border-radius: 9px;
            border: 1.5px solid var(--ash-border);
            padding: 9px 13px;
            font-size: 0.88rem;
            color: #1e293b;
            font-family: inherit;
            transition: all 0.2s ease;
            background-color: #ffffff;
        }

        .custom-input-modern:focus, .custom-select-modern:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3.5px rgba(52, 152, 219, 0.18);
            outline: none;
        }

        .btn-primary-modern {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover) 100%);
            color: #ffffff;
            border: none;
            border-radius: 9px;
            padding: 10px 18px;
            font-weight: 700;
            font-size: 0.88rem;
            box-shadow: 0 2px 8px rgba(52, 152, 219, 0.28);
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-primary-modern:hover {
            background: linear-gradient(135deg, var(--primary-hover) 0%, #1f618d 100%);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.38);
        }

        .btn-secondary-modern {
            background: #f1f5f9;
            color: var(--ash-slate);
            border: 1px solid var(--ash-border);
            border-radius: 9px;
            padding: 10px 18px;
            font-weight: 700;
            font-size: 0.88rem;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        .btn-secondary-modern:hover {
            background: #e2e8f0;
            color: var(--ash-dark);
        }

        /* 3. Master Cards */
        .card-modern {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid var(--ash-border);
            box-shadow: 0 4px 18px rgba(44, 62, 80, 0.05);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .card-header-ash {
            background: linear-gradient(135deg, var(--ash-dark) 0%, var(--ash-medium) 100%) !important;
            color: #ffffff !important;
            padding: 16px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .card-header-ash h5,
        .card-header-ash h6,
        .card-header-ash .card-title,
        .card-header-ash i,
        .card-header-ash .header-icon-box i {
            color: #ffffff !important;
        }

        .card-header-ash small {
            color: rgba(255, 255, 255, 0.78) !important;
        }

        .target-count-pill,
        .card-header-ash .target-count-pill {
            background-color: #ffffff !important;
            color: #1e293b !important;
            font-weight: 700 !important;
            font-size: 0.82rem !important;
            padding: 6px 14px !important;
            border-radius: 50rem !important;
            border: 1px solid rgba(226, 232, 240, 0.95) !important;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08) !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
        }

        .target-count-pill *,
        .card-header-ash .target-count-pill * {
            color: #1e293b !important;
        }

        .target-count-pill i,
        .card-header-ash .target-count-pill i {
            color: #3498db !important;
        }

        .header-icon-box {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
        }

        /* Dynamic Week Preview Card */
        .week-dates-preview-card {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 18px;
            transition: all 0.25s ease;
        }

        /* Assign Target Button */
        .btn-assign-target {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.95rem;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
            transition: all 0.25s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-assign-target:hover:not(:disabled) {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-1.5px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.35);
        }

        .btn-assign-target:disabled {
            background: #cbd5e1;
            color: #64748b;
            box-shadow: none;
            cursor: not-allowed;
            transform: none;
        }

        /* 4. Table Styling */
        .table-targets {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 0;
        }

        .table-targets thead th {
            background-color: #707981 !important;
            color: #ffffff !important;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 14px;
            border: none;
            vertical-align: middle;
        }

        .table-targets tbody td {
            padding: 14px 16px;
            vertical-align: middle;
            border-top: 1px solid #f1f5f9;
            font-size: 0.88rem;
        }

        .table-targets tbody tr:hover {
            background-color: #f8fafc;
        }

        /* Avatar Circle */
        .tm-avatar {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.8rem;
            flex-shrink: 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }

        /* Week Badge */
        .week-badge-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 10px;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: #ffffff;
            font-weight: 700;
            font-size: 0.8rem;
            border-radius: 7px;
            letter-spacing: 0.3px;
            box-shadow: 0 2px 6px rgba(99, 102, 241, 0.22);
        }

        .week-date-range {
            font-size: 0.76rem !important;
            white-space: nowrap !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 3px !important;
            color: #475569 !important;
            font-weight: 500 !important;
            line-height: 1.4 !important;
        }

        .week-date-range i {
            color: #3498db !important;
            font-size: 0.78rem !important;
            flex-shrink: 0 !important;
        }

        /* Target Limit Box */
        .target-limit-badge {
            display: inline-block;
            padding: 6px 14px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.92rem;
            color: var(--ash-dark);
            font-variant-numeric: tabular-nums;
        }

        /* Status Pill */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.3px;
        }

        .status-pill.status-active {
            background-color: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .status-pill.status-completed {
            background-color: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .status-pill.status-cancelled {
            background-color: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }

        .status-dot-active {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background-color: var(--emerald);
            box-shadow: 0 0 6px var(--emerald);
        }

        /* Action Buttons */
        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid transparent;
            transition: all 0.2s ease;
            cursor: pointer;
            font-size: 0.82rem;
        }

        .action-btn-progress {
            background: #f0fdf4;
            color: #15803d;
            border-color: #bbf7d0;
        }

        .action-btn-progress:hover {
            background: #16a34a;
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(22, 163, 74, 0.25);
            transform: translateY(-1px);
        }

        .action-btn-edit {
            background: #fffbeb;
            color: #b45309;
            border-color: #fde68a;
        }

        .action-btn-edit:hover {
            background: #f59e0b;
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(245, 158, 11, 0.25);
            transform: translateY(-1px);
        }

        .action-btn-delete {
            background: #fef2f2;
            color: #b91c1c;
            border-color: #fecaca;
        }

        .action-btn-delete:hover {
            background: #ef4444;
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(239, 68, 68, 0.25);
            transform: translateY(-1px);
        }

        /* Modal Modern Styling */
        .modal-content-modern {
            border-radius: 14px;
            border: 1px solid var(--ash-border);
            box-shadow: 0 20px 40px rgba(44, 62, 80, 0.16);
            overflow: hidden;
        }

        .modal-header-ash {
            background: linear-gradient(135deg, var(--ash-dark) 0%, var(--ash-medium) 100%);
            color: #ffffff;
            padding: 16px 22px;
            border: none;
        }
    </style>
    
    <div class="main-content">
        <!-- 1. Page Header Banner -->
        <div class="page-hero-banner">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="hero-icon-pill">
                        <i class="fas fa-bullseye"></i>
                    </div>
                    <div>
                        <h3 class="hero-title mb-0">Employee Target</h3>
                        <p class="hero-subtitle mb-0">Configure weekly production targets, monitor milestone progress, and track team performance metrics.</p>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="hero-stat-badge">
                        <i class="fas fa-layer-group text-primary"></i>
                        <span class="fw-bold text-dark"><?= count($targets) ?></span>
                        <span class="text-muted small">Total Targets</span>
                    </div>
                    <?php if(!empty($targets)): ?>
                    <?php 
                    $total_target_all = array_sum(array_column($targets, 'target_limit'));
                    $total_achieved_all = array_sum(array_column($targets, 'achieved_target'));
                    $overall_rate = $total_target_all > 0 ? round(($total_achieved_all / $total_target_all) * 100, 1) : 0;
                    ?>
                    <div class="hero-stat-badge">
                        <i class="fas fa-chart-line text-success"></i>
                        <span class="fw-bold text-dark"><?= $overall_rate ?>%</span>
                        <span class="text-muted small">Avg Achieved</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 2. Alert Notifications -->
        <?php if(isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 rounded-3 border-0 shadow-sm" role="alert">
            <i class="fas fa-circle-check fs-5 text-success"></i>
            <div><?= h($success_message) ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if(!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 border-0 shadow-sm" role="alert">
            <div class="d-flex align-items-center gap-2 mb-1 fw-bold">
                <i class="fas fa-circle-exclamation fs-5 text-danger"></i>
                <span>Please correct the following:</span>
            </div>
            <ul class="mb-0 ps-4 small">
                <?php foreach($errors as $error): ?>
                <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- 3. Filter Section -->
        <div class="filter-card-modern">
            <form method="get" class="row g-3 align-items-end">
                <?php if($current_user_role == 3): ?>
                <div class="col-lg-3 col-md-6">
                    <label for="tl_id" class="form-label-custom">
                        <i class="fas fa-user-tie text-primary me-1"></i> Select Team Leader
                    </label>
                    <select name="tl_id" id="tl_id" class="form-select custom-select-modern" onchange="this.form.submit()">
                        <option value="">All Team Leaders</option>
                        <?php foreach($tls as $tl): ?>
                        <option value="<?= h($tl['employee_username'] ?? $tl['employee_id']) ?>" 
                                <?= $selected_tl_id == ($tl['employee_username'] ?? $tl['employee_id']) ? 'selected' : '' ?>>
                            <?= h($tl['employee_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="<?= $current_user_role == 3 ? 'col-lg-3 col-md-6' : 'col-lg-4 col-md-5' ?>">
                    <label for="week_filter" class="form-label-custom">
                        <i class="fas fa-calendar-week text-primary me-1"></i> Filter by Week
                    </label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted border-end-0"><i class="fas fa-hashtag"></i></span>
                        <input type="text" name="week_filter" id="week_filter" class="form-control custom-input-modern border-start-0" 
                               placeholder="e.g. W32, W1" value="<?= h($_GET['week_filter'] ?? '') ?>">
                    </div>
                </div>
                
                <div class="<?= $current_user_role == 3 ? 'col-lg-2 col-md-4' : 'col-lg-3 col-md-3' ?>">
                    <label for="year_filter" class="form-label-custom">
                        <i class="far fa-calendar-alt text-primary me-1"></i> Year
                    </label>
                    <select name="year_filter" id="year_filter" class="form-select custom-select-modern">
                        <?php for($y = 2023; $y <= 2030; $y++): ?>
                        <option value="<?= $y ?>" <?= ($_GET['year_filter'] ?? date('Y')) == $y ? 'selected' : '' ?>>
                            <?= $y ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="<?= $current_user_role == 3 ? 'col-lg-2 col-md-4' : 'col-lg-2 col-md-2' ?>">
                    <button type="submit" class="btn btn-primary-modern w-100">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                </div>
                
                <div class="<?= $current_user_role == 3 ? 'col-lg-2 col-md-4' : 'col-lg-2 col-md-2' ?>">
                    <a href="target_assignment.php" class="btn btn-secondary-modern w-100">
                        <i class="fas fa-rotate-left me-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>

        <div class="row g-4">
            <!-- Left Column: Form & Quick Stats -->
            <div class="col-lg-4 col-md-5">
                <!-- Assign Target Form Card -->
                <div class="card-modern">
                    <div class="card-header card-header-ash">
                        <div class="d-flex align-items-center gap-2">
                            <div class="header-icon-box">
                                <i class="fas fa-plus text-white"></i>
                            </div>
                            <div>
                                <h5 class="mb-0 fw-bold text-white">Assign Weekly Target</h5>
                                <small class="text-white-50">Set deliverable quotas for team members</small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <form method="post" autocomplete="off">
                            <input type="hidden" name="action" value="assign_target">
                            
                            <!-- Team Member Selector -->
                            <div class="mb-3">
                                <label for="tm_id" class="form-label-custom">
                                    <i class="fas fa-user-circle text-primary me-1"></i> Team Member <span class="text-danger">*</span>
                                </label>
                                <select name="tm_id" id="tm_id" class="form-select custom-select-modern" required <?= count($tms) == 0 ? 'disabled' : '' ?>>
                                    <option value="">Select Team Member</option>
                                    <?php foreach($tms as $tm): ?>
                                    <option value="<?= h($tm['employee_username']) ?>">
                                        <?= h($tm['employee_name']) ?> (<?= h($tm['employee_username']) ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if(count($tms) == 0): ?>
                                <div class="mt-2 p-2 rounded-2 bg-warning-subtle text-warning-emphasis border border-warning-subtle small d-flex align-items-center gap-2">
                                    <i class="fas fa-triangle-exclamation"></i>
                                    <span>No team members found for the selected TL</span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Target Limit -->
                            <div class="mb-3">
                                <label for="target_limit" class="form-label-custom">
                                    <i class="fas fa-crosshairs text-primary me-1"></i> Target Limit <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted border-end-0"><i class="fas fa-bullseye"></i></span>
                                    <input type="number" class="form-control custom-input-modern border-start-0" id="target_limit" name="target_limit" 
                                           min="1" required placeholder="Enter target number">
                                </div>
                            </div>
                            
                            <!-- Week and Year -->
                            <div class="mb-3">
                                <label for="week_input" class="form-label-custom">
                                    <i class="fas fa-calendar-days text-primary me-1"></i> Target Week <span class="text-danger">*</span>
                                </label>
                                <div class="row g-2">
    <div class="col-7">
        <select class="form-select custom-select-modern" id="week_input" name="week_input" required>
            <option value="">Select Week</option>
            <?php for($w=1; $w<=53; $w++): ?>
                <option value="W<?= $w ?>">W<?= str_pad($w, 2, "0", STR_PAD_LEFT) ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="col-5">
        <input type="number" class="form-control custom-input-modern" id="year" name="year" 
               value="<?= date('Y') ?>" min="2023" max="2030">
    </div>
</div>
                                <div class="form-text mt-1 text-muted small">
                                    <i class="fas fa-info-circle me-1 text-primary"></i> Enter week number (W1 to W53)
                                </div>
                            </div>
                            
                            <!-- Dynamic Week Date Range Preview -->
                            <div id="weekDates" class="week-dates-preview-card">
                                <div class="d-flex align-items-center gap-2 text-muted small">
                                    <i class="fas fa-calculator text-primary"></i>
                                    <span>Week dates will be calculated automatically</span>
                                </div>
                            </div>
                            
                            <!-- Submit Button -->
                            <button type="submit" class="btn-assign-target" <?= count($tms) == 0 ? 'disabled' : '' ?>>
                                <i class="fas fa-paper-plane me-2"></i> Assign Target
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Quick Performance Stats Card -->
                <div class="card-modern">
                    <div class="card-header card-header-ash">
                        <div class="d-flex align-items-center gap-2">
                            <div class="header-icon-box">
                                <i class="fas fa-chart-pie text-white"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold text-white">Performance Summary</h6>
                                <small class="text-white-50">Current period metrics</small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <?php if(!empty($targets)): ?>
                        <?php 
                        $total_target = array_sum(array_column($targets, 'target_limit'));
                        $total_achieved = array_sum(array_column($targets, 'achieved_target'));
                        $overall_percentage = $total_target > 0 ? round(($total_achieved / $total_target) * 100, 2) : 0;
                        ?>
                        <div class="p-3 rounded-3 text-center mb-3 bg-light border">
                            <span class="text-muted small text-uppercase fw-bold letter-spacing-1">Overall Achievement Rate</span>
                            <div class="display-6 fw-bold my-1 
                                <?= $overall_percentage >= 100 ? 'text-success' : 
                                   ($overall_percentage >= 70 ? 'text-warning-emphasis' : 'text-danger') ?>">
                                <?= $overall_percentage ?>%
                            </div>
                            <div class="progress" style="height: 6px; border-radius: 4px; background: #e2e8f0;">
                                <div class="progress-bar <?= $overall_percentage >= 100 ? 'bg-success' : ($overall_percentage >= 70 ? 'bg-warning' : 'bg-danger') ?>" 
                                     style="width: <?= min(100, $overall_percentage) ?>%;"></div>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="p-3 text-center rounded-3 bg-light border">
                                    <span class="text-muted d-block small mb-1"><i class="fas fa-bullseye text-primary me-1"></i> Targets</span>
                                    <span class="h4 fw-bold text-dark mb-0"><?= count($targets) ?></span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 text-center rounded-3 bg-light border">
                                    <span class="text-muted d-block small mb-1"><i class="fas fa-check-double text-success me-1"></i> Achieved</span>
                                    <span class="h4 fw-bold text-success mb-0"><?= number_format($total_achieved) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-chart-simple fa-2x mb-2 text-secondary opacity-50"></i>
                            <p class="mb-0 small">No target performance data to display</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column: Assigned Targets Table -->
            <div class="col-lg-8 col-md-7">
                <div class="card-modern">
                    <div class="card-header card-header-ash d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <div class="header-icon-box">
                                <i class="fas fa-list-check text-white"></i>
                            </div>
                            <div>
                                <h5 class="mb-0 fw-bold text-white">Assigned Targets</h5>
                                <small class="text-white-50">Production milestones and fulfillment tracking</small>
                            </div>
                        </div>
                        <span class="badge target-count-pill" style="background-color: #ffffff !important; color: #1e293b !important; font-weight: 700 !important; font-size: 0.82rem !important; padding: 6px 14px !important; border-radius: 50rem !important; border: 1px solid rgba(226, 232, 240, 0.95) !important; box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08) !important; display: inline-flex !important; align-items: center !important;">
                            <i class="fas fa-bullseye me-1 text-primary" style="color: #3498db !important;"></i> <span style="color: #1e293b !important; font-weight: 700 !important;"><?= count($targets) ?> Target(s)</span>
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-targets align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th width="60">ID</th>
                                        <?php if($current_user_role == 3): ?>
                                        <th>Team Leader</th>
                                        <?php endif; ?>
                                        <th>Team Member</th>
                                        <th style="min-width: 185px; width: 185px;">Week</th>
                                        <th width="120" class="text-center">Target Limit</th>
                                        <th width="210">Progress</th>
                                        <th width="110" class="text-center">Status</th>
                                        <th width="120" class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(!empty($targets)): ?>
                                        <?php foreach($targets as $target): ?>
                                        <?php 
                                            $initials = getInitials($target['tm_name'] ?: $target['tm_username']);
                                            $avatarStyle = getAvatarColors($target['tm_username'] ?: $target['tm_name']);
                                        ?>
                                        <tr>
                                            <!-- ID -->
                                            <td>
                                                <span class="badge bg-light text-secondary border font-monospace">
                                                    #<?= h($target['id']) ?>
                                                </span>
                                            </td>

                                            <?php if($current_user_role == 3): ?>
                                            <td>
                                                <small class="fw-semibold text-secondary"><?= h($target['tl_name']) ?></small>
                                            </td>
                                            <?php endif; ?>

                                            <!-- Team Member -->
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="tm-avatar" style="background-color: <?= $avatarStyle['bg'] ?>; border: 1px solid <?= $avatarStyle['border'] ?>; color: <?= $avatarStyle['text'] ?>;">
                                                        <?= $initials ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold text-dark"><?= h($target['tm_name'] ?: $target['tm_username']) ?></div>
                                                        <span class="badge bg-light text-muted border py-0 px-1 font-monospace" style="font-size: 0.72rem;">
                                                            <?= h($target['tm_username']) ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- Week -->
                                            <td>
                                                <div class="d-flex flex-column gap-1">
                                                    <div class="d-flex align-items-center">
                                                        <span class="week-badge-pill">W<?= h($target['week_number']) ?></span>
                                                        <span class="text-dark small fw-bold" style="font-size: 0.82rem; margin-left: 6px;"><?= h($target['year']) ?></span>
                                                    </div>
                                                    <div class="week-date-range" style="font-size: 0.76rem; white-space: nowrap; color: #475569; font-weight: 500;">
                                                        <i class="far fa-calendar-alt text-primary me-1"></i><span><?= formatDate($target['from_date']) ?></span>&nbsp;&rarr;&nbsp;<span><?= formatDate($target['to_date']) ?></span>
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- Target Limit -->
                                            <td class="text-center">
                                                <div class="target-limit-badge">
                                                    <?= number_format($target['target_limit']) ?>
                                                </div>
                                            </td>

                                            <!-- Progress -->
                                            <td>
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <small class="fw-semibold text-secondary">
                                                        Achieved: <strong class="text-dark"><?= number_format($target['achieved_target']) ?></strong>
                                                    </small>
                                                    <span class="badge <?= $target['achieved_percentage'] >= 100 ? 'bg-success-subtle text-success' : ($target['achieved_percentage'] >= 70 ? 'bg-warning-subtle text-warning-emphasis' : 'bg-danger-subtle text-danger') ?> fw-bold" style="font-size: 0.72rem;">
                                                        <?= $target['achieved_percentage'] ?>%
                                                    </span>
                                                </div>
                                                <div class="progress" style="height: 7px; border-radius: 6px; background-color: #e2e8f0;">
                                                    <div class="progress-bar <?= $target['achieved_percentage'] >= 100 ? 'bg-success' : ($target['achieved_percentage'] >= 70 ? 'bg-warning' : 'bg-danger') ?>" 
                                                         role="progressbar" 
                                                         style="width: <?= min(100, $target['achieved_percentage']) ?>%; border-radius: 6px;" 
                                                         aria-valuenow="<?= $target['achieved_percentage'] ?>" 
                                                         aria-valuemin="0" 
                                                         aria-valuemax="100">
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- Status -->
                                            <td class="text-center">
                                                <?php if($target['status'] === 'Active'): ?>
                                                    <span class="status-pill status-active">
                                                        <span class="status-dot-active"></span> Active
                                                    </span>
                                                <?php elseif($target['status'] === 'Completed'): ?>
                                                    <span class="status-pill status-completed">
                                                        <i class="fas fa-circle-check"></i> Completed
                                                    </span>
                                                <?php else: ?>
                                                    <span class="status-pill status-cancelled">
                                                        <i class="fas fa-circle-xmark"></i> <?= h($target['status']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Actions -->
                                            <td class="text-center">
                                                <div class="d-flex align-items-center justify-content-center gap-1">
                                                    <!-- Progress Recalculate Modal Button -->
                                                    <button type="button" class="action-btn action-btn-progress" title="Recalculate & Update Progress"
                                                            onclick="updateProgress(<?= $target['id'] ?>, '<?= h(addslashes($target['tm_name'] ?: $target['tm_username'])) ?>', 'Week <?= h($target['week_number']) ?>, <?= h($target['year']) ?>')">
                                                        <i class="fas fa-arrows-rotate"></i>
                                                    </button>

                                                    <!-- Edit Target Modal Button -->
                                                    <button type="button" class="action-btn action-btn-edit" title="Edit Target"
                                                            onclick="editTarget(<?= $target['id'] ?>, <?= h($target['target_limit']) ?>, 'W<?= h($target['week_number']) ?>', <?= h($target['year']) ?>)">
                                                        <i class="fas fa-pen-to-square"></i>
                                                    </button>

                                                    <!-- Delete Target Form -->
                                                    <form method="post" class="d-inline m-0 p-0" onsubmit="return confirm('Are you sure you want to delete this target?')">
                                                        <input type="hidden" name="action" value="delete_target">
                                                        <input type="hidden" name="target_id" value="<?= h($target['id']) ?>">
                                                        <button type="submit" class="action-btn action-btn-delete" title="Delete Target">
                                                            <i class="fas fa-trash-can"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="<?= $current_user_role == 3 ? 8 : 7 ?>" class="text-center py-5">
                                                <div class="text-muted">
                                                    <div class="mb-3">
                                                        <i class="fas fa-bullseye fa-3x text-secondary opacity-50"></i>
                                                    </div>
                                                    <h5 class="fw-bold text-dark">No Targets Found</h5>
                                                    <p class="mb-0 small text-secondary">Assign your first weekly target using the form on the left.</p>
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
    </div>

    <!-- Progress Update Modal (Senior Developer Styled) -->
    <div class="modal fade" id="progressModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-modern">
                <div class="modal-header modal-header-ash d-flex justify-content-between align-items-center">
                    <h5 class="modal-title mb-0 fw-bold text-white">
                        <i class="fas fa-sliders me-2"></i> Update Target Progress
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" id="progressForm">
                    <div class="modal-body p-4">
                        <input type="hidden" name="action" value="update_progress">
                        <input type="hidden" name="target_id" id="modal_target_id">
                        
                        <div class="mb-3">
                            <label class="form-label-custom">
                                <i class="fas fa-user text-primary me-1"></i> Team Member
                            </label>
                            <input type="text" class="form-control custom-input-modern bg-light" id="modal_tm_name" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label-custom">
                                <i class="fas fa-calendar-week text-primary me-1"></i> Target Period
                            </label>
                            <input type="text" class="form-control custom-input-modern bg-light" id="modal_week" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label-custom">
                                <i class="fas fa-calculator text-primary me-1"></i> Calculated Achieved Target
                            </label>
                            <div class="p-3 rounded-3 bg-light border text-center">
                                <h3 class="fw-bold mb-1 text-primary" id="achieved_count">
                                    <i class="fas fa-spinner fa-spin me-2"></i> Calculating...
                                </h3>
                                <small class="text-muted d-block">Automatically calculated from jobs assigned during the week</small>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="status" class="form-label-custom">
                                <i class="fas fa-flag text-primary me-1"></i> Status
                            </label>
                            <select name="status" id="status" class="form-select custom-select-modern">
                                <option value="Active">Active</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer bg-light px-4 py-3 border-top">
                        <button type="button" class="btn btn-secondary-modern" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary-modern">
                            <i class="fas fa-check me-1"></i> Update Progress
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Target Modal (Senior Developer Styled) -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-modern">
                <div class="modal-header modal-header-ash d-flex justify-content-between align-items-center">
                    <h5 class="modal-title mb-0 fw-bold text-white">
                        <i class="fas fa-pen-to-square me-2"></i> Edit Weekly Target
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" id="editForm">
                    <div class="modal-body p-4">
                        <input type="hidden" name="action" value="update_target">
                        <input type="hidden" name="target_id" id="edit_target_id">
                        
                        <div class="mb-3">
                            <label for="edit_target_limit" class="form-label-custom">
                                <i class="fas fa-crosshairs text-primary me-1"></i> Target Limit <span class="text-danger">*</span>
                            </label>
                            <input type="number" class="form-control custom-input-modern" id="edit_target_limit" name="target_limit" min="1" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_week_input" class="form-label-custom">
                                <i class="fas fa-calendar-days text-primary me-1"></i> Week & Year <span class="text-danger">*</span>
                            </label>
                            <div class="row g-2">
                                <div class="col-7">
                                    <input type="text" class="form-control custom-input-modern" id="edit_week_input" name="week_input" 
                                           required pattern="[Ww]?\d+" title="Format: W1, W2, etc.">
                                </div>
                                <div class="col-5">
                                    <input type="number" class="form-control custom-input-modern" id="edit_year" name="year" 
                                           min="2023" max="2030">
                                </div>
                            </div>
                        </div>
                        
                        <div id="editWeekDates" class="week-dates-preview-card">
                            <small class="text-muted">Week dates will be calculated automatically</small>
                        </div>
                    </div>
                    <div class="modal-footer bg-light px-4 py-3 border-top">
                        <button type="button" class="btn btn-secondary-modern" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary-modern">
                            <i class="fas fa-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const weekInput = document.getElementById('week_input');
            const yearInput = document.getElementById('year');
            const weekDatesDiv = document.getElementById('weekDates');
            
            const editWeekInput = document.getElementById('edit_week_input');
            const editYearInput = document.getElementById('edit_year');
            const editWeekDatesDiv = document.getElementById('editWeekDates');
            
            function calculateWeekDates(wInput, yInput, displayDiv) {
                if (!wInput || !yInput || !displayDiv) return;
                const weekMatch = wInput.value.match(/W?(\d+)/i);
                if (weekMatch) {
                    const weekNumber = parseInt(weekMatch[1], 10);
                    const year = parseInt(yInput.value, 10);
                    
                    if (weekNumber >= 1 && weekNumber <= 53) {
                        // Calculate week dates (Monday to Friday)
                        const aprilFirst = new Date(year, 3, 1);
                        const dayOfWeek = aprilFirst.getDay();
                        const diffToMonday = (dayOfWeek === 1) ? 0 : (dayOfWeek === 0 ? 1 : (8 - dayOfWeek));
                        const firstMonday = new Date(aprilFirst);
                        firstMonday.setDate(firstMonday.getDate() + diffToMonday);
                        
                        const weekStart = new Date(firstMonday);
                        weekStart.setDate(weekStart.getDate() + (weekNumber - 1) * 7);
                        
                        const weekEnd = new Date(weekStart);
                        weekEnd.setDate(weekEnd.getDate() + 5); // Monday to Friday (5 days)
                        
                        const formatDate = (date) => {
                            return date.toLocaleDateString('en-IN', {
                                day: '2-digit',
                                month: 'short',
                                year: 'numeric'
                            });
                        };
                        
                        displayDiv.innerHTML = `
                            <div class="d-flex align-items-center justify-content-between">
                                <div class="fw-bold text-dark"><i class="fas fa-calendar-check text-primary me-1"></i> Week ${weekNumber}, ${year}</div>
                                <span class="badge bg-primary-subtle text-primary fw-semibold" style="font-size: 0.72rem;">5 Work Days</span>
                            </div>
                            <div class="mt-1 small text-secondary">
                                <span>${formatDate(weekStart)}</span> &rarr; <span class="fw-semibold text-dark">${formatDate(weekEnd)}</span>
                            </div>
                        `;
                        return;
                    }
                }
                displayDiv.innerHTML = '<small class="text-muted"><i class="fas fa-info-circle me-1"></i> Enter a valid week (W1 - W53)</small>';
            }
            
            // Listeners for new target form
            if (weekInput && yearInput) {
                weekInput.addEventListener('input', function() {
                    calculateWeekDates(weekInput, yearInput, weekDatesDiv);
                });
                yearInput.addEventListener('change', function() {
                    calculateWeekDates(weekInput, yearInput, weekDatesDiv);
                });
                weekInput.addEventListener('blur', function() {
                    const val = this.value.trim().toUpperCase();
                    if (val && !val.startsWith('W') && /^\d+$/.test(val)) {
                        this.value = 'W' + val;
                    }
                });
                // Initial calculation
                calculateWeekDates(weekInput, yearInput, weekDatesDiv);
            }
            
            // Listeners for edit form
            if (editWeekInput && editYearInput) {
                editWeekInput.addEventListener('input', function() {
                    calculateWeekDates(editWeekInput, editYearInput, editWeekDatesDiv);
                });
                editYearInput.addEventListener('change', function() {
                    calculateWeekDates(editWeekInput, editYearInput, editWeekDatesDiv);
                });
                editWeekInput.addEventListener('blur', function() {
                    const val = this.value.trim().toUpperCase();
                    if (val && !val.startsWith('W') && /^\d+$/.test(val)) {
                        this.value = 'W' + val;
                    }
                });
            }
        });
        
        // Progress modal invocation
        function updateProgress(targetId, tmName, weekText) {
            document.getElementById('modal_target_id').value = targetId;
            document.getElementById('modal_tm_name').value = tmName;
            if (document.getElementById('modal_week') && weekText) {
                document.getElementById('modal_week').value = weekText;
            }
            
            const achievedEl = document.getElementById('achieved_count');
            achievedEl.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Calculating...';
            achievedEl.className = "fw-bold mb-1 text-primary";
            
            fetch(`calculate_achieved_target.php?target_id=${targetId}`)
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        achievedEl.textContent = Number(data.achieved_target).toLocaleString();
                        document.getElementById('status').value = data.status;
                        
                        if (data.achieved_target >= data.target_limit) {
                            achievedEl.className = "fw-bold mb-1 text-success";
                        } else if (data.achieved_target >= (data.target_limit * 0.7)) {
                            achievedEl.className = "fw-bold mb-1 text-warning";
                        } else {
                            achievedEl.className = "fw-bold mb-1 text-danger";
                        }
                    } else {
                        achievedEl.textContent = "Error calculating";
                        achievedEl.className = "fw-bold mb-1 text-danger";
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    achievedEl.textContent = "Error calculating";
                    achievedEl.className = "fw-bold mb-1 text-danger";
                });
            
            const modal = new bootstrap.Modal(document.getElementById('progressModal'));
            modal.show();
        }
        
        // Edit modal invocation
        function editTarget(targetId, targetLimit, weekInput, year) {
            document.getElementById('edit_target_id').value = targetId;
            document.getElementById('edit_target_limit').value = targetLimit;
            document.getElementById('edit_week_input').value = weekInput;
            document.getElementById('edit_year').value = year;
            
            const editWeekInput = document.getElementById('edit_week_input');
            const editYearInput = document.getElementById('edit_year');
            const editWeekDatesDiv = document.getElementById('editWeekDates');
            
            const weekMatch = weekInput.match(/W?(\d+)/i);
            if (weekMatch) {
                const weekNumber = parseInt(weekMatch[1], 10);
                const firstDayOfYear = new Date(year, 0, 1);
                const dayOfWeek = firstDayOfYear.getDay();
                const diffToMonday = dayOfWeek === 0 ? -6 : 1 - dayOfWeek;
                firstDayOfYear.setDate(firstDayOfYear.getDate() + diffToMonday);
                
                const weekStart = new Date(firstDayOfYear);
                weekStart.setDate(weekStart.getDate() + (weekNumber - 1) * 7);
                
                const weekEnd = new Date(weekStart);
                weekEnd.setDate(weekEnd.getDate() + 4);
                
                const formatDate = (date) => {
                    return date.toLocaleDateString('en-IN', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric'
                    });
                };
                
                editWeekDatesDiv.innerHTML = `
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="fw-bold text-dark"><i class="fas fa-calendar-check text-primary me-1"></i> Week ${weekNumber}, ${year}</div>
                        <span class="badge bg-primary-subtle text-primary fw-semibold" style="font-size: 0.72rem;">5 Work Days</span>
                    </div>
                    <div class="mt-1 small text-secondary">
                        <span>${formatDate(weekStart)}</span> &rarr; <span class="fw-semibold text-dark">${formatDate(weekEnd)}</span>
                    </div>
                `;
            }
            
            const modal = new bootstrap.Modal(document.getElementById('editModal'));
            modal.show();
        }
        
        // Auto-dismiss transient alerts
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
