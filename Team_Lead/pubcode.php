<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db.php';

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

function redirect_with($params =[]) {
    $base = 'pubcode.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Fetch frequency options
function getFrequencyOptions() {
    return[
        'Monthly',
        'Bi-Monthly',
        'Quarterly',
        'Semi-Annual',
        'Annual',
        'Weekly',
        'Daily',
        'Irregular'
    ];
}

// Fetch specialty from journal_specialty_master
function getSpecialtyOptions($mysqli) {
    $specialties =[];
    $sql = "SELECT specialty_id, specialty_name FROM journal_specialty_master WHERE status = 'Active' ORDER BY specialty_name";
    $result = $mysqli->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $specialties[] = $row;
        }
    }
    return $specialties;
}

// Fetch origin options
function getOriginOptions() {
    return[
        'National',
        'International',
        'Regional',
        'Local'
    ];
}

// Fetch performance options
function getPerformanceOptions() {
    return [
        'Delayed',
        'Ontime'
    ];
}

// Fetch status options
function getStatusOptions() {
    return[
        'Active',
        'Onhold'
    ];
}

// Fetch managers from team_master
function getManagers($mysqli) {
    $managers =[];
    $sql = "SELECT team_id, team_name FROM team_master WHERE status = 'Active' ORDER BY team_name";
    $result = $mysqli->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $managers[] = $row;
        }
    }
    return $managers;
}

// Fetch templates from template_master
function getTemplateOptions($mysqli) {
    $templates =[];
    $sql = "SELECT template_id, template_name FROM template_master WHERE status = 'Active' ORDER BY template_name";
    $result = $mysqli->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $templates[] = $row;
        }
    }
    return $templates;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ---------------------------------------------------------
    // HANDLE IMPORT LOGIC (XLSX Format)
    // ---------------------------------------------------------
    if ($action === 'import_excel') {
        if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
            
            // Locate the SimpleXLSX Library
            $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
            if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
            if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

            if (!file_exists($libPath)) {
                redirect_with(['msg' => 'Library file not found.', 'type' => 'danger']);
            }
            
            require_once $libPath;

            $xlsx = null;
            if (class_exists('Shuchkin\SimpleXLSX')) {
                $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
            } elseif (class_exists('SimpleXLSX')) {
                $xlsx = SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
            } else {
                redirect_with(['msg' => 'Class SimpleXLSX not found.', 'type' => 'danger']);
            }

            if ($xlsx) {
                $rows = $xlsx->rows();
                $imported_count = 0;
                $updated_count = 0;
                $skipped_count = 0;
                $error_details =[];
                
                // Pre-fetch relational ID mappings
                $spec_map =[];
                $res = $mysqli->query("SELECT specialty_id, specialty_name FROM journal_specialty_master");
                if($res) while($row = $res->fetch_assoc()) $spec_map[strtolower(trim($row['specialty_name']))] = $row['specialty_id'];

                $team_map =[];
                $res = $mysqli->query("SELECT team_id, team_name FROM team_master");
                if($res) while($row = $res->fetch_assoc()) $team_map[strtolower(trim($row['team_name']))] = $row['team_id'];

                $tpl_map =[];
                $res = $mysqli->query("SELECT template_id, template_name FROM template_master");
                if($res) while($row = $res->fetch_assoc()) $tpl_map[strtolower(trim($row['template_name']))] = $row['template_id'];
                
                foreach ($rows as $k => $r) {
                    if ($k === 0) continue; // Skip Header

                    // Excel Columns order exact match to the UI request
                    $journal_ref = trim($r[0] ?? '');
                    // $r[1] is textual Journal ID. Skipping since DB uses auto-increment ID
                    $journal_name = trim($r[2] ?? '');
                    $pubcode = trim($r[3] ?? '');
                    $shortcode = trim($r[4] ?? '');
                    $frequency = trim($r[5] ?? '');
                    $issues_till_2023 = (int)trim($r[6] ?? '0');
                    $point_of_revenue = trim($r[7] ?? '');
                    
                    $specialty_raw = trim($r[8] ?? '');
                    $specialty_id = !empty($specialty_raw) ? ($spec_map[strtolower($specialty_raw)] ?? 0) : 0;
                    
                    $origin = trim($r[9] ?? '');
                    $avg_pages_per_issue = (int)trim($r[10] ?? '0');
                    $avg_articles_per_issue = (int)trim($r[11] ?? '0');
                    
                    $perf_raw = trim($r[12] ?? '');
                    if(strtolower($perf_raw) === 'on time') $perf_raw = 'Ontime';
                    $performance = $perf_raw;
                    
                    $avg_article_inflow_month = trim($r[13] ?? '');
                    
                    $template_raw = trim($r[14] ?? '');
                    $template_id = !empty($template_raw) ? ($tpl_map[strtolower($template_raw)] ?? 0) : 0;
                    
                    $new_tl = trim($r[15] ?? '');
                    $new_pe = trim($r[16] ?? '');
                    
                    $manager_raw = trim($r[17] ?? '');
                    $manager_id = !empty($manager_raw) ? ($team_map[strtolower($manager_raw)] ?? 0) : 0;
                    
                    $email_id = trim($r[18] ?? '');
                    $pe_contact_number = trim($r[19] ?? '');
                    
                    $status_raw = trim($r[20] ?? 'Active');
                    if(empty($status_raw)) $status_raw = 'Active';
                    if(strtolower(str_replace(' ', '', $status_raw)) === 'onhold') $status_raw = 'Onhold';
                    $status = $status_raw;
                    
                    $live_on_ejp_raw = strtolower(trim($r[21] ?? ''));
                    $live_on_ejp = ($live_on_ejp_raw === 'yes' || $live_on_ejp_raw === '1' || $live_on_ejp_raw === 'true') ? 1 : 0;
                    
                    $url = trim($r[22] ?? '');

                    if (empty($journal_name)) {
                        $skipped_count++;
                        $error_details[] = "Row " . ($k + 1) . ": Missing Journal Name";
                        continue;
                    }

                    // Check existence by journal_name
                    $checkStmt = $mysqli->prepare("SELECT journal_id FROM pubcode WHERE journal_name = ?");
                    $checkStmt->bind_param("s", $journal_name);
                    $checkStmt->execute();
                    $checkRes = $checkStmt->get_result();
                    $exists = $checkRes->num_rows > 0;
                    $row_id = $exists ? $checkRes->fetch_assoc()['journal_id'] : 0;
                    $checkStmt->close();

                    if (!$exists) {
                        $insertStmt = $mysqli->prepare("INSERT INTO pubcode (journal_name, journal_ref, pubcode, shortcode, frequency, issues_till_2023, point_of_revenue, specialty_id, origin, avg_pages_per_issue, avg_articles_per_issue, performance, avg_article_inflow_month, template_id, new_tl, new_pe, manager_id, email_id, pe_contact_number, status, live_on_ejp, url, created_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        // Fixed type binding: sssssisisiiisiississis
                        $insertStmt->bind_param("sssssisisiiisiississis", $journal_name, $journal_ref, $pubcode, $shortcode, $frequency, $issues_till_2023, $point_of_revenue, $specialty_id, $origin, $avg_pages_per_issue, $avg_articles_per_issue, $performance, $avg_article_inflow_month, $template_id, $new_tl, $new_pe, $manager_id, $email_id, $pe_contact_number, $status, $live_on_ejp, $url);
                        
                        if ($insertStmt->execute()) {
                            $imported_count++;
                        } else {
                            $skipped_count++;
                            $error_details[] = "Row " . ($k + 1) . ": " . $mysqli->error;
                        }
                        $insertStmt->close();
                    } else {
                        $updateStmt = $mysqli->prepare("UPDATE pubcode SET journal_ref=?, pubcode=?, shortcode=?, frequency=?, issues_till_2023=?, point_of_revenue=?, specialty_id=?, origin=?, avg_pages_per_issue=?, avg_articles_per_issue=?, performance=?, avg_article_inflow_month=?, template_id=?, new_tl=?, new_pe=?, manager_id=?, email_id=?, pe_contact_number=?, status=?, live_on_ejp=?, url=?, updated_date=NOW() WHERE journal_id=?");
                        // Fixed type binding: ssssisisiiisiississisi
                        $updateStmt->bind_param("ssssisisiiisiississisi", $journal_ref, $pubcode, $shortcode, $frequency, $issues_till_2023, $point_of_revenue, $specialty_id, $origin, $avg_pages_per_issue, $avg_articles_per_issue, $performance, $avg_article_inflow_month, $template_id, $new_tl, $new_pe, $manager_id, $email_id, $pe_contact_number, $status, $live_on_ejp, $url, $row_id);
                        
                        if ($updateStmt->execute()) {
                            $updated_count++;
                        }
                        $updateStmt->close();
                    }
                }
                
                $msg = "Process Complete.<br>";
                $msg .= "<strong>Imported:</strong> $imported_count new journals.<br>";
                $msg .= "<strong>Updated:</strong> $updated_count existing journals.<br>";
                $msg .= "<strong>Skipped:</strong> $skipped_count rows.";
                
                if (!empty($error_details)) {
                    $msg .= "<br><small class='text-danger'>" . implode("<br>", array_slice($error_details, 0, 5)) . "</small>";
                    if (count($error_details) > 5) {
                        $msg .= "<br>... and " . (count($error_details) - 5) . " more errors.";
                    }
                }
                
                $type = ($imported_count > 0 || $updated_count > 0) ? 'success' : 'warning';
                redirect_with(['msg' => $msg, 'type' => $type]);
            } else {
                $err = (method_exists('Shuchkin\SimpleXLSX', 'parseError')) ? Shuchkin\SimpleXLSX::parseError() : 'Unknown Error';
                redirect_with(['msg' => 'SimpleXLSX Parse Error: ' . $err, 'type' => 'danger']);
            }
        } else {
            redirect_with(['msg' => 'No file uploaded or file error.', 'type' => 'danger']);
        }
    }
    
    if ($action === 'add' || $action === 'edit') {
        $journal_name = trim($_POST['journal_name'] ?? '');
        $journal_ref = trim($_POST['journal_ref'] ?? '');
        $pubcode = trim($_POST['pubcode'] ?? '');
        $shortcode = trim($_POST['shortcode'] ?? '');
        $frequency = trim($_POST['frequency'] ?? '');
        $issues_till_2023 = (int)trim($_POST['issues_till_2023'] ?? '');
        $point_of_revenue = trim($_POST['point_of_revenue'] ?? '');
        $specialty_id = (int)trim($_POST['specialty_id'] ?? '');
        $origin = trim($_POST['origin'] ?? '');
        $avg_pages_per_issue = (int)trim($_POST['avg_pages_per_issue'] ?? '');
        $avg_articles_per_issue = (int)trim($_POST['avg_articles_per_issue'] ?? '');
        $performance = trim($_POST['performance'] ?? '');
        $avg_article_inflow_month = trim($_POST['avg_article_inflow_month'] ?? '');
        $template_id = (int)trim($_POST['template_id'] ?? '');
        $new_tl = trim($_POST['new_tl'] ?? '');
        $new_pe = trim($_POST['new_pe'] ?? '');
        $manager_id = (int)trim($_POST['manager_id'] ?? '');
        $email_id = trim($_POST['email_id'] ?? '');
        $pe_contact_number = trim($_POST['pe_contact_number'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');
        $live_on_ejp = isset($_POST['live_on_ejp']) ? 1 : 0;
        $url = trim($_POST['url'] ?? '');
        
        // Basic validation
        if (empty($journal_name)) {
            redirect_with(['msg' => 'Journal Name is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Check if journal already exists
            $check_stmt = $mysqli->prepare("SELECT journal_id FROM pubcode WHERE journal_name = ?");
            $check_stmt->bind_param("s", $journal_name);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                redirect_with(['msg' => 'Journal with this name already exists.', 'type' => 'danger']);
            }
            $check_stmt->close();
            
            // Insert new journal
            $stmt = $mysqli->prepare("INSERT INTO pubcode (journal_name, journal_ref, pubcode, shortcode, frequency, issues_till_2023, point_of_revenue, specialty_id, origin, avg_pages_per_issue, avg_articles_per_issue, performance, avg_article_inflow_month, template_id, new_tl, new_pe, manager_id, email_id, pe_contact_number, status, live_on_ejp, url, created_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            // Fixed type binding mapping
            $stmt->bind_param("sssssisisiiisiississis", $journal_name, $journal_ref, $pubcode, $shortcode, $frequency, $issues_till_2023, $point_of_revenue, $specialty_id, $origin, $avg_pages_per_issue, $avg_articles_per_issue, $performance, $avg_article_inflow_month, $template_id, $new_tl, $new_pe, $manager_id, $email_id, $pe_contact_number, $status, $live_on_ejp, $url);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Journal '$journal_name' added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding journal: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $journal_id = (int)($_POST['journal_id'] ?? 0);
            
            if ($journal_id > 0) {
                // Check if journal already exists (excluding current one)
                $check_stmt = $mysqli->prepare("SELECT journal_id FROM pubcode WHERE journal_name = ? AND journal_id != ?");
                $check_stmt->bind_param("si", $journal_name, $journal_id);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $check_stmt->close();
                    redirect_with(['msg' => 'Journal with this name already exists.', 'type' => 'danger']);
                }
                $check_stmt->close();
                
                // Update journal
                $stmt = $mysqli->prepare("UPDATE pubcode SET journal_name = ?, journal_ref = ?, pubcode = ?, shortcode = ?, frequency = ?, issues_till_2023 = ?, point_of_revenue = ?, specialty_id = ?, origin = ?, avg_pages_per_issue = ?, avg_articles_per_issue = ?, performance = ?, avg_article_inflow_month = ?, template_id = ?, new_tl = ?, new_pe = ?, manager_id = ?, email_id = ?, pe_contact_number = ?, status = ?, live_on_ejp = ?, url = ?, updated_date = NOW() WHERE journal_id = ?");
                // Fixed type binding length to 23
                $stmt->bind_param("sssssisisiiisiississisi", $journal_name, $journal_ref, $pubcode, $shortcode, $frequency, $issues_till_2023, $point_of_revenue, $specialty_id, $origin, $avg_pages_per_issue, $avg_articles_per_issue, $performance, $avg_article_inflow_month, $template_id, $new_tl, $new_pe, $manager_id, $email_id, $pe_contact_number, $status, $live_on_ejp, $url, $journal_id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Journal updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating journal: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $journal_id = (int)($_POST['journal_id'] ?? 0);
        $journal_name = '';
        
        if ($journal_id > 0) {
            $res = $mysqli->prepare("SELECT journal_name FROM pubcode WHERE journal_id = ?");
            $res->bind_param("i", $journal_id);
            $res->execute();
            $res->bind_result($journal_name);
            $res->fetch();
            $res->close();
            
            $del = $mysqli->prepare("DELETE FROM pubcode WHERE journal_id = ?");
            $del->bind_param("i", $journal_id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Journal '$journal_name' deleted", 'type' => 'warning']);
        }
    }
    
    if ($action === 'toggle_status') {
        $journal_id = (int)($_POST['journal_id'] ?? 0);
        
        if ($journal_id > 0) {
            $res = $mysqli->prepare("SELECT status, journal_name FROM pubcode WHERE journal_id = ?");
            $res->bind_param("i", $journal_id);
            $res->execute();
            $res->bind_result($current_status, $journal_name);
            $res->fetch();
            $res->close();
            
            if ($journal_name) {
                // Toggle between Active and Inactive/Onhold
                $newStatus = (strtolower($current_status) === 'active') ? 'Onhold' : 'Active';
                
                $upd = $mysqli->prepare("UPDATE pubcode SET status = ?, updated_date = NOW() WHERE journal_id = ?");
                $upd->bind_param("si", $newStatus, $journal_id);
                $upd->execute();
                $upd->close();
                
                redirect_with(['msg' => "Journal '$journal_name' status changed to $newStatus", 'type' => 'info']);
            }
        }
    }
    
    if ($action === 'toggle_ejp') {
        $journal_id = (int)($_POST['journal_id'] ?? 0);
        
        if ($journal_id > 0) {
            $res = $mysqli->prepare("SELECT live_on_ejp, journal_name FROM pubcode WHERE journal_id = ?");
            $res->bind_param("i", $journal_id);
            $res->execute();
            $res->bind_result($current_ejp, $journal_name);
            $res->fetch();
            $res->close();
            
            if ($journal_name) {
                $newEJP = $current_ejp ? 0 : 1;
                $ejpStatus = $newEJP ? 'Live' : 'Not Live';
                
                $upd = $mysqli->prepare("UPDATE pubcode SET live_on_ejp = ?, updated_date = NOW() WHERE journal_id = ?");
                $upd->bind_param("ii", $newEJP, $journal_id);
                $upd->execute();
                $upd->close();
                
                redirect_with(['msg' => "Journal '$journal_name' eJP status changed to $ejpStatus", 'type' => 'info']);
            }
        }
    }
    
    if ($action !== 'import_excel') {
        redirect_with();
    }
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editJournal = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT jm.*, tm.team_name as manager_name, jsm.specialty_name, tpl.template_name FROM pubcode jm LEFT JOIN team_master tm ON jm.manager_id = tm.team_id LEFT JOIN journal_specialty_master jsm ON jm.specialty_id = jsm.specialty_id LEFT JOIN template_master tpl ON jm.template_id = tpl.template_id WHERE jm.journal_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editJournal = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editJournal) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$fspecialty = $_GET['specialty'] ?? '';
$forigin = $_GET['origin'] ?? '';
$sort = $_GET['sort'] ?? 'created_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Validate sort column
$allowed_sorts =['journal_name', 'pubcode', 'frequency', 'specialty_name', 'origin', 'status', 'live_on_ejp', 'created_date', 'updated_date'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'created_date';
}

// Build query - JOIN with specialty and template tables
$sql = "SELECT jm.*, tm.team_name as manager_name, jsm.specialty_name, tpl.template_name 
        FROM pubcode jm 
        LEFT JOIN team_master tm ON jm.manager_id = tm.team_id
        LEFT JOIN journal_specialty_master jsm ON jm.specialty_id = jsm.specialty_id
        LEFT JOIN template_master tpl ON jm.template_id = tpl.template_id";
$cond = [];
$params =[];
$types = '';

if ($q !== '') {
    $cond[] = "(jm.journal_name LIKE ? OR jm.journal_ref LIKE ? OR jm.pubcode LIKE ? OR jm.shortcode LIKE ? OR jm.email_id LIKE ? OR tm.team_name LIKE ? OR jsm.specialty_name LIKE ? OR tpl.template_name LIKE ?)";
    $like = "%$q%";
    $params = array_fill(0, 8, $like);
    $types .= str_repeat('s', 8);
}

if ($fstatus !== '') {
    $cond[] = "jm.status = ?";
    $params[] = $fstatus;
    $types .= 's';
}

if ($fspecialty !== '') {
    $cond[] = "jm.specialty_id = ?";
    $params[] = $fspecialty;
    $types .= 'i';
}

if ($forigin !== '') {
    $cond[] = "jm.origin = ?";
    $params[] = $forigin;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " WHERE " . implode(" AND ", $cond);
}

$sql .= " ORDER BY $sort $dir";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $journals = $stmt->get_result();
    } else {
        $journals = false;
        error_log("Query preparation failed: " . $mysqli->error);
    }
} else {
    $journals = $mysqli->query($sql);
    if (!$journals) {
        error_log("Query execution failed: " . $mysqli->error);
    }
}

// Get options
$frequency_options = getFrequencyOptions();
$specialty_options = getSpecialtyOptions($mysqli);
$origin_options = getOriginOptions();
$performance_options = getPerformanceOptions();
$status_options = getStatusOptions();
$template_options = getTemplateOptions($mysqli);
$managers = getManagers($mysqli);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journals Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 2500px;
            margin: 0 auto;
        }
        .table th {
            background-color: #2c3e50;
            color: white;
            border: none;
            white-space: nowrap;
        }
        .table td {
            vertical-align: middle;
            font-size: 0.9rem;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
        }
        .journal-name-cell {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
            position: relative;
        }
        .journal-name-cell:hover::after {
            content: attr(title);
            position: absolute;
            top: 100%;
            left: 0;
            background: white;
            border: 1px solid #ddd;
            padding: 10px;
            z-index: 1000;
            max-width: 400px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            white-space: normal;
            word-wrap: break-word;
        }
        .badge-small {
            font-size: 0.75em;
            padding: 0.25em 0.5em;
        }
        .email-cell {
            max-width: 150px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .url-cell {
            max-width: 120px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .stats-cell {
            text-align: center;
            font-weight: bold;
        }
        .form-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #007bff;
        }
        .form-section h6 {
            color: #007bff;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
        
        <!-- Add/Edit Form -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-book me-2"></i><?= $mode === 'edit' ? 'Edit Journal' : 'Add New Journal' ?></h4>
            <a href="pubcode.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="journal_id" value="<?= (int)($editJournal['journal_id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="form-section">
                        <h6><i class="fas fa-info-circle me-2"></i>Basic Information</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="journal_name" class="form-label">Journal Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="journal_name" name="journal_name" 
                                           value="<?= isset($editJournal['journal_name']) ? h($editJournal['journal_name']) : '' ?>" 
                                           placeholder="Enter journal name" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="journal_ref" class="form-label">Journal Ref</label>
                                    <input type="text" class="form-control" id="journal_ref" name="journal_ref" 
                                           value="<?= isset($editJournal['journal_ref']) ? h($editJournal['journal_ref']) : '' ?>" 
                                           placeholder="Journal reference">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="pubcode" class="form-label">PubCode</label>
                                    <input type="text" class="form-control" id="pubcode" name="pubcode" 
                                           value="<?= isset($editJournal['pubcode']) ? h($editJournal['pubcode']) : '' ?>" 
                                           placeholder="Publication code">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="shortcode" class="form-label">ShortCode</label>
                                    <input type="text" class="form-control" id="shortcode" name="shortcode" 
                                           value="<?= isset($editJournal['shortcode']) ? h($editJournal['shortcode']) : '' ?>" 
                                           placeholder="Short code">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="frequency" class="form-label">Frequency</label>
                                    <select class="form-control" id="frequency" name="frequency">
                                        <option value="">-- Select Frequency --</option>
                                        <?php foreach($frequency_options as $option): ?>
                                            <option value="<?= h($option) ?>" <?= (isset($editJournal['frequency']) && $editJournal['frequency'] === $option) ? 'selected' : '' ?>>
                                                <?= h($option) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h6><i class="fas fa-chart-bar me-2"></i>Statistics & Performance</h6>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="issues_till_2023" class="form-label">No. of issues till 2023</label>
                                    <input type="number" class="form-control" id="issues_till_2023" name="issues_till_2023" 
                                           value="<?= isset($editJournal['issues_till_2023']) ? h($editJournal['issues_till_2023']) : '' ?>" 
                                           placeholder="0" min="0">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="point_of_revenue" class="form-label">Point of Revenue</label>
                                    <input type="text" class="form-control" id="point_of_revenue" name="point_of_revenue" 
                                           value="<?= isset($editJournal['point_of_revenue']) ? h($editJournal['point_of_revenue']) : '' ?>" 
                                           placeholder="Revenue source">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="avg_pages_per_issue" class="form-label">Avg. pages/Issue</label>
                                    <input type="number" class="form-control" id="avg_pages_per_issue" name="avg_pages_per_issue" 
                                           value="<?= isset($editJournal['avg_pages_per_issue']) ? h($editJournal['avg_pages_per_issue']) : '' ?>" 
                                           placeholder="0" min="0">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="avg_articles_per_issue" class="form-label">Avg. no. of articles/Issue</label>
                                    <input type="number" class="form-control" id="avg_articles_per_issue" name="avg_articles_per_issue" 
                                           value="<?= isset($editJournal['avg_articles_per_issue']) ? h($editJournal['avg_articles_per_issue']) : '' ?>" 
                                           placeholder="0" min="0">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="avg_article_inflow_month" class="form-label">Avg. Article inflow in a Month</label>
                                    <input type="text" class="form-control" id="avg_article_inflow_month" name="avg_article_inflow_month" 
                                           value="<?= isset($editJournal['avg_article_inflow_month']) ? h($editJournal['avg_article_inflow_month']) : '' ?>" 
                                           placeholder="e.g., 10-15 articles">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="performance" class="form-label">Performance</label>
                                    <select class="form-control" id="performance" name="performance">
                                        <option value="">-- Select Performance --</option>
                                        <?php foreach($performance_options as $option): ?>
                                            <option value="<?= h($option) ?>" <?= (isset($editJournal['performance']) && $editJournal['performance'] === $option) ? 'selected' : '' ?>>
                                                <?= h($option) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h6><i class="fas fa-tags me-2"></i>Classification & Details</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="specialty_id" class="form-label">Journal Specialty</label>
                                    <select class="form-control" id="specialty_id" name="specialty_id">
                                        <option value="">-- Select Specialty --</option>
                                        <?php foreach($specialty_options as $specialty): ?>
                                            <option value="<?= $specialty['specialty_id'] ?>" <?= (isset($editJournal['specialty_id']) && $editJournal['specialty_id'] == $specialty['specialty_id']) ? 'selected' : '' ?>>
                                                <?= h($specialty['specialty_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="origin" class="form-label">Origin</label>
                                    <select class="form-control" id="origin" name="origin">
                                        <option value="">-- Select Origin --</option>
                                        <?php foreach($origin_options as $option): ?>
                                            <option value="<?= h($option) ?>" <?= (isset($editJournal['origin']) && $editJournal['origin'] === $option) ? 'selected' : '' ?>>
                                                <?= h($option) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="template_id" class="form-label">Template</label>
                                    <select class="form-control" id="template_id" name="template_id">
                                        <option value="">-- Select Template --</option>
                                        <?php foreach($template_options as $template): ?>
                                            <option value="<?= $template['template_id'] ?>" <?= (isset($editJournal['template_id']) && $editJournal['template_id'] == $template['template_id']) ? 'selected' : '' ?>>
                                                <?= h($template['template_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h6><i class="fas fa-users me-2"></i>Team & Contact Information</h6>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="new_tl" class="form-label">New TL</label>
                                    <input type="text" class="form-control" id="new_tl" name="new_tl" 
                                           value="<?= isset($editJournal['new_tl']) ? h($editJournal['new_tl']) : '' ?>" 
                                           placeholder="Team Leader">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="new_pe" class="form-label">New PE</label>
                                    <input type="text" class="form-control" id="new_pe" name="new_pe" 
                                           value="<?= isset($editJournal['new_pe']) ? h($editJournal['new_pe']) : '' ?>" 
                                           placeholder="Project Executive">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="manager_id" class="form-label">Manager</label>
                                    <select class="form-control" id="manager_id" name="manager_id">
                                        <option value="">-- Select Manager --</option>
                                        <?php foreach($managers as $manager): ?>
                                            <option value="<?= $manager['team_id'] ?>" <?= (isset($editJournal['manager_id']) && $editJournal['manager_id'] == $manager['team_id']) ? 'selected' : '' ?>>
                                                <?= h($manager['team_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="pe_contact_number" class="form-label">PE Contact Number</label>
                                    <input type="text" class="form-control" id="pe_contact_number" name="pe_contact_number" 
                                           value="<?= isset($editJournal['pe_contact_number']) ? h($editJournal['pe_contact_number']) : '' ?>" 
                                           placeholder="Contact number">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="email_id" class="form-label">E-mail ID</label>
                                    <input type="email" class="form-control" id="email_id" name="email_id" 
                                           value="<?= isset($editJournal['email_id']) ? h($editJournal['email_id']) : '' ?>" 
                                           placeholder="email@example.com">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h6><i class="fas fa-globe me-2"></i>Status & Online Presence</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-control" id="status" name="status">
                                        <?php foreach($status_options as $option): ?>
                                            <option value="<?= h($option) ?>" <?= (isset($editJournal['status']) && $editJournal['status'] === $option) ? 'selected' : '' ?>>
                                                <?= h($option) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="url" class="form-label">URL</label>
                                    <input type="url" class="form-control" id="url" name="url" 
                                           value="<?= isset($editJournal['url']) ? h($editJournal['url']) : '' ?>" 
                                           placeholder="https://example.com">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <div class="form-check form-switch mt-4">
                                        <input class="form-check-input" type="checkbox" id="live_on_ejp" name="live_on_ejp" 
                                               <?= (isset($editJournal['live_on_ejp']) && $editJournal['live_on_ejp']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="live_on_ejp">Live on eJP</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Journal' : 'Add Journal' ?>
                        </button>
                        <a href="pubcode.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4><i class="fas fa-book me-2"></i>Journals Master</h4>
                <p class="text-muted mb-0">Manage publication codes | Import Excel to bulk add/update</p>
            </div>
            <div>
                <button type="button" class="btn btn-warning text-dark fw-bold btn-sm me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-excel me-2"></i> Import Excel
                </button>
                <a href="pubcode.php?mode=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Add New Journal
                </a>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search by name, pubcode, email..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <?php foreach($status_options as $option): ?>
                                <option value="<?= h($option) ?>" <?= $fstatus === $option ? 'selected' : '' ?>>
                                    <?= h($option) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="specialty" class="form-select form-select-sm">
                            <option value="">All Specialties</option>
                            <?php foreach($specialty_options as $specialty): ?>
                                <option value="<?= $specialty['specialty_id'] ?>" <?= $fspecialty == $specialty['specialty_id'] ? 'selected' : '' ?>>
                                    <?= h($specialty['specialty_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="origin" class="form-select form-select-sm">
                            <option value="">All Origins</option>
                            <?php foreach($origin_options as $option): ?>
                                <option value="<?= h($option) ?>" <?= $forigin === $option ? 'selected' : '' ?>>
                                    <?= h($option) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="journal_name" <?= $sort === 'journal_name' ? 'selected' : '' ?>>Sort by Name</option>
                            <option value="pubcode" <?= $sort === 'pubcode' ? 'selected' : '' ?>>Sort by PubCode</option>
                            <option value="frequency" <?= $sort === 'frequency' ? 'selected' : '' ?>>Sort by Frequency</option>
                            <option value="status" <?= $sort === 'status' ? 'selected' : '' ?>>Sort by Status</option>
                            <option value="created_date" <?= $sort === 'created_date' ? 'selected' : '' ?>>Sort by Created Date</option>
                        </select>
                    </div>
                    <!-- <div class="col-md-1">
                        <select name="dir" class="form-select form-select-sm">
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Asc</option>
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Desc</option>
                        </select>
                    </div> -->
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="pubcode.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Journals Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <!-- Exact Order Grid Match -->
                            <tr>
                                <th>Journal Ref</th>
                                <th>Journal ID</th>
                                <th>Journal Name</th>
                                <th>PubCode</th>
                                <th>ShortCode</th>
                                <th>Frequency</th>
                                <th>No. of issues till 2023</th>
                                <th>Point of revenue</th>
                                <th>Journal specialty</th>
                                <th>Origin</th>
                                <th>Avg. pages/Issue</th>
                                <th>Avg. no. of articles/Issue</th>
                                <th>Performance</th>
                                <th>Average Article inflow in a Month</th>
                                <th>Template</th>
                                <th>New TL</th>
                                <th>New PE</th>
                                <th>Manager</th>
                                <th>E-mail ID</th>
                                <th>PE Contact Number</th>
                                <th>Status</th>
                                <th>Live on eJP</th>
                                <th>URL</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($journals && $journals->num_rows > 0): ?>
                                <?php while($row = $journals->fetch_assoc()): ?>
                                <tr>
                                    <td><small><?= h($row['journal_ref']) ?></small></td>
                                    <td class="fw-bold"><?= (int)$row['journal_id'] ?></td>
                                    <td class="journal-name-cell" title="<?= h($row['journal_name']) ?>">
                                        <?= h(mb_strlen($row['journal_name']) > 20 ? mb_substr($row['journal_name'], 0, 20) . '...' : $row['journal_name']) ?>
                                    </td>
                                    <td><span class="badge bg-secondary badge-small"><?= h($row['pubcode']) ?></span></td>
                                    <td><small><?= h($row['shortcode']) ?></small></td>
                                    <td>
                                        <?php if(!empty($row['frequency'])): ?>
                                            <span class="badge bg-info badge-small text-dark"><?= h($row['frequency']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="stats-cell"><?= (int)$row['issues_till_2023'] ?></td>
                                    <td><small><?= h($row['point_of_revenue']) ?></small></td>
                                    <td>
                                        <?php if(!empty($row['specialty_name'])): ?>
                                            <span class="badge bg-primary badge-small"><?= h($row['specialty_name']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['origin'])): ?>
                                            <span class="badge bg-warning text-dark badge-small"><?= h($row['origin']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="stats-cell"><?= (int)$row['avg_pages_per_issue'] ?></td>
                                    <td class="stats-cell"><?= (int)$row['avg_articles_per_issue'] ?></td>
                                    <td>
                                        <?php if(!empty($row['performance'])): ?>
                                            <?php 
                                            $performance_class = '';
                                            switch($row['performance']) {
                                                case 'Delayed': $performance_class = 'danger'; break;
                                                case 'Ontime': 
                                                case 'On Time': $performance_class = 'success'; break;
                                                default: $performance_class = 'secondary';
                                            }
                                            ?>
                                            <span class="badge bg-<?= $performance_class ?> badge-small"><?= h($row['performance']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?= h($row['avg_article_inflow_month']) ?></small></td>
                                    <td>
                                        <?php if(!empty($row['template_name'])): ?>
                                            <small><?= h($row['template_name']) ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?= h($row['new_tl']) ?></small></td>
                                    <td><small><?= h($row['new_pe']) ?></small></td>
                                    <td><small><?= h($row['manager_name']) ?></small></td>
                                    <td class="email-cell" title="<?= h($row['email_id']) ?>">
                                        <?= !empty($row['email_id']) ? h(mb_strlen($row['email_id']) > 15 ? mb_substr($row['email_id'], 0, 15) . '...' : $row['email_id']) : '—' ?>
                                    </td>
                                    <td><small><?= h($row['pe_contact_number']) ?></small></td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="journal_id" value="<?= (int)$row['journal_id'] ?>">
                                            <button type="submit" class="btn btn-xs <?= strtolower(str_replace(' ', '', $row['status'])) === 'active' ? 'btn-success' : 'btn-danger' ?>">
                                                <?= h($row['status']) ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_ejp">
                                            <input type="hidden" name="journal_id" value="<?= (int)$row['journal_id'] ?>">
                                            <button type="submit" class="btn btn-xs <?= $row['live_on_ejp'] ? 'btn-success' : 'btn-secondary' ?>">
                                                <?= $row['live_on_ejp'] ? 'Yes' : 'No' ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="url-cell" title="<?= h($row['url']) ?>">
                                        <?php if(!empty($row['url'])): ?>
                                            <a href="<?= h($row['url']) ?>" target="_blank" class="btn btn-link btn-sm">
                                                <i class="fas fa-external-link-alt"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="pubcode.php?mode=edit&id=<?= (int)$row['journal_id'] ?>" 
                                               class="btn btn-warning" title="Edit Journal">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete <?= h($row['journal_name']) ?>?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="journal_id" value="<?= (int)$row['journal_id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Journal">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="24" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-book fa-3x mb-3"></i>
                                            <h5>No journals found</h5>
                                            <p class="mb-0">Get started by adding your first journal.</p>
                                            <a href="pubcode.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Journal
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <!-- IMPORT MODAL -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-file-excel me-2"></i>Import PubCode Journals from Excel
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <form action="" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Select Excel File (.xlsx)</label>
                                    <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                                    <small class="text-muted">Maximum file size: 5MB</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Download Sample</label>
                                <button type="button" class="btn btn-outline-success w-100" onclick="downloadSample()">
                                    <i class="fas fa-download me-1"></i> Sample Format
                                </button>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <h6 class="fw-bold"><i class="fas fa-info-circle me-1"></i> Excel Format Required:</h6>
                            <p class="mb-1"><strong>Columns in exact order (All columns mapped by position):</strong></p>
                            <div class="row">
                                <div class="col-md-4">
                                    <ol class="mb-0 small">
                                        <li>Journal Ref</li>
                                        <li>Journal ID (Ignored in DB insert)</li>
                                        <li><strong>Journal Name (Required)</strong></li>
                                        <li>PubCode</li>
                                        <li>ShortCode</li>
                                        <li>Frequency</li>
                                        <li>No. of issues till 2023</li>
                                        <li>Point of revenue</li>
                                    </ol>
                                </div>
                                <div class="col-md-4">
                                    <ol start="9" class="mb-0 small">
                                        <li>Journal specialty</li>
                                        <li>Origin</li>
                                        <li>Avg. pages/Issue</li>
                                        <li>Avg. no. of articles/Issue</li>
                                        <li>Performance</li>
                                        <li>Average Article inflow/Month</li>
                                        <li>Template</li>
                                        <li>New TL</li>
                                    </ol>
                                </div>
                                <div class="col-md-4">
                                    <ol start="17" class="mb-0 small">
                                        <li>New PE</li>
                                        <li>Manager</li>
                                        <li>E-mail ID</li>
                                        <li>PE Contact Number</li>
                                        <li>Status</li>
                                        <li>Live on eJP (Yes/No)</li>
                                        <li>URL</li>
                                    </ol>
                                </div>
                            </div>
                            
                            <div class="border-top pt-2 mt-2">
                                <p class="mb-1"><strong><i class="fas fa-exclamation-triangle me-1"></i> Rules:</strong></p>
                                <ul class="mb-0 small">
                                    <li>New records will be <span class="badge bg-success">INSERTED</span></li>
                                    <li>Existing records (matching Journal Name) will be <span class="badge bg-warning text-dark">UPDATED</span></li>
                                    <li>Rows missing "Journal Name" will be <span class="badge bg-danger">SKIPPED</span></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i> Close
                        </button>
                        <button type="submit" class="btn btn-dark">
                            <i class="fas fa-upload me-1"></i> Import Data
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert.isConnected) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                }, 5000);
            });

            // Auto-submit form on filter changes - ONLY for search form (GET method forms)
            const searchForms = document.querySelectorAll('form[method="get"]');
            searchForms.forEach(form => {
                const filterSelects = form.querySelectorAll('select[name="status"], select[name="specialty"], select[name="origin"], select[name="sort"], select[name="dir"]');
                filterSelects.forEach(select => {
                    select.addEventListener('change', function() {
                        form.submit();
                    });
                });
            });
        });

        // Download sample CSV formatted for PubCode Journals
        function downloadSample() {
            const sampleContent = "Journal Ref,Journal ID,Journal Name,PubCode,ShortCode,Frequency,No. of issues till 2023,Point of revenue,Journal specialty,Origin,Avg. pages/Issue,Avg. no. of articles/Issue,Performance,Average Article inflow in a Month,Template,New TL,New PE,Manager,E-mail ID,PE Contact Number,Status,Live on eJP,URL\n" +
                                 "sjopthal,Sudanese J of Ophthalmol,Sudanese Journal of Ophthalmology,SJIO,sjio,Monthly,3,Print,Ophthalmology,Sudan,40,6,Delayed,2,Template 2,Pallavi,Avinash,Chetan,email@wolterskluwer.com,2261151815,Onhold,No,https://example.com/sjio\n" +
                                 "ajts,Asian J Transfus Sci,Asian Journal of Transfusion Science,AJTS,ajts,Quarterly,2,Print,Science,India,80,13,On Time,5,Template 2,Pallavi,Avinash,Chetan,email@wolterskluwer.com,2261151815,Active,YES,https://example.com/ajts";
            
            const blob = new Blob([sampleContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sample_pubcode_import.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>