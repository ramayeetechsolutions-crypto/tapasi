<?php
require 'db.php';
//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);

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

function calculateAgeing($date1, $date2 = null) {
    if (empty($date1) || $date1 === '0000-00-00') return 0;
    
    $battery1Date = new DateTime($date1);
    
    if (!empty($date2) && $date2 !== '0000-00-00') {
        $battery2Date = new DateTime($date2);
        // Use the later date (newer battery)
        $startDate = ($battery1Date > $battery2Date) ? $battery1Date : $battery2Date;
    } else {
        $startDate = $battery1Date;
    }
    
    $today = new DateTime();
    $interval = $today->diff($startDate);
    return $interval->y * 12 + $interval->m; // Return months
}

// Function: Get battery warranty information from tbl_battery
function getBatteryWarrantyInfo($serial_no, $mysqli) {
    if (empty($serial_no)) {
        return null;
    }
    
    $stmt = $mysqli->prepare("SELECT purchase_date, warranty, status FROM tbl_battery WHERE serial_no = ?");
    $stmt->bind_param("s", $serial_no);
    $stmt->execute();
    $result = $stmt->get_result();
    $batteryInfo = $result->fetch_assoc();
    $stmt->close();
    
    return $batteryInfo;
}

// Function: Calculate warranty status using battery warranty from tbl_battery
function calculateWarrantyStatus($batteryDate, $warrantyYears = 2) {
    if (empty($batteryDate) || $batteryDate === '0000-00-00') {
        return ['status' => 'no_date', 'message' => 'No date set', 'class' => 'secondary', 'days_remaining' => null];
    }
    
    $installDate = new DateTime($batteryDate);
    $warrantyEnd = clone $installDate;
    $warrantyEnd->modify("+$warrantyYears years");
    
    $today = new DateTime();
    
    if ($today > $warrantyEnd) {
        return ['status' => 'expired', 'message' => 'Warranty expired', 'class' => 'danger', 'days_remaining' => 0];
    }
    
    $daysRemaining = $today->diff($warrantyEnd)->days;
    
    if ($daysRemaining <= 15) {
        return [
            'status' => 'critical', 
            'message' => "Warranty ends in $daysRemaining days", 
            'class' => 'danger', 
            'days_remaining' => $daysRemaining,
            'notification' => "Your UPS battery warranty is going to end in $daysRemaining days"
        ];
    } elseif ($daysRemaining <= 60) { // 2 months = ~60 days
        return [
            'status' => 'warning', 
            'message' => "Warranty ends in $daysRemaining days", 
            'class' => 'warning', 
            'days_remaining' => $daysRemaining,
            'notification' => "Your UPS battery warranty is going to end in 2 months"
        ];
    } else {
        return [
            'status' => 'good', 
            'message' => 'Warranty active', 
            'class' => 'success', 
            'days_remaining' => $daysRemaining,
            'notification' => ''
        ];
    }
}

function getAlertClass($ageing) {
    if ($ageing >= 36) return 'danger'; // 3 years or more - Critical
    if ($ageing >= 30) return 'warning'; // 2.5 years - Warning
    if ($ageing >= 24) return 'info'; // 2 years - Info
    return 'success'; // Less than 2 years - Good
}

function redirect_with($params = []) {
    $base = 'ups.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// ========== EXCEL IMPORT HANDLER ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        // Path to Library
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        
        if (!file_exists($libPath)) {
            // Fallback without src
            $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
        }

        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'Library file not found at: ' . $libPath, 'type' => 'danger']);
        }
        
        require_once $libPath;
        
        // Check for Namespace or Global class
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
            $count = 0;
            $errors = 0;
            $errorMessages = [];
            
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header
                
                // Skip empty rows
                if (empty($r[0]) && empty($r[1]) && empty($r[2]) && empty($r[3]) && empty($r[4])) {
                    continue;
                }
                
                // Map Excel Columns based on your image: UPS No(0), Team(1), Date_Bat 1(2), Date_Bat 2(3), Ageing(4), Remarks(5)
                // Added Battery Serial fields based on your form
                $ups_no         = $mysqli->real_escape_string(trim($r[0] ?? ''));
                $team           = $mysqli->real_escape_string(trim($r[1] ?? ''));
                $date_battery1  = $mysqli->real_escape_string(trim($r[2] ?? ''));
                $date_battery2  = $mysqli->real_escape_string(trim($r[3] ?? ''));
                $ageing         = $mysqli->real_escape_string(trim($r[4] ?? ''));
                $remarks        = $mysqli->real_escape_string(trim($r[5] ?? ''));
                $battery1_serial = $mysqli->real_escape_string(trim($r[6] ?? '')); // Column 7 if exists
                $battery2_serial = $mysqli->real_escape_string(trim($r[7] ?? '')); // Column 8 if exists
                $status         = 'active';
                
                // Validate required fields
                if (empty($ups_no)) {
                    $errorMessages[] = "Row " . ($k + 1) . ": UPS No is required";
                    $errors++;
                    continue;
                }
                
                // Validate and format dates
                $dateFormats = [
                    'Y-m-d',
                    'd/m/Y',
                    'm/d/Y',
                    'd-m-Y',
                    'm-d-Y',
                    'Y/m/d',
                    'd.m.Y',
                    'm.d.Y'
                ];
                
                // Format date_battery1
                if (!empty($date_battery1)) {
                    $formattedDate = false;
                    foreach ($dateFormats as $format) {
                        $date = DateTime::createFromFormat($format, $date_battery1);
                        if ($date) {
                            $formattedDate = $date->format('Y-m-d');
                            break;
                        }
                    }
                    
                    if ($formattedDate) {
                        $date_battery1 = $formattedDate;
                    } else {
                        $date_battery1 = '0000-00-00';
                        $errorMessages[] = "Row " . ($k + 1) . ": Invalid Battery 1 Date format";
                    }
                } else {
                    $date_battery1 = '0000-00-00';
                }
                
                // Format date_battery2
                if (!empty($date_battery2)) {
                    $formattedDate = false;
                    foreach ($dateFormats as $format) {
                        $date = DateTime::createFromFormat($format, $date_battery2);
                        if ($date) {
                            $formattedDate = $date->format('Y-m-d');
                            break;
                        }
                    }
                    
                    if ($formattedDate) {
                        $date_battery2 = $formattedDate;
                    } else {
                        $date_battery2 = '0000-00-00';
                        $errorMessages[] = "Row " . ($k + 1) . ": Invalid Battery 2 Date format";
                    }
                } else {
                    $date_battery2 = '0000-00-00';
                }
                
                // Calculate ageing if not provided or invalid
                if (empty($ageing) || !is_numeric($ageing)) {
                    if ($date_battery1 !== '0000-00-00' || $date_battery2 !== '0000-00-00') {
                        $ageing = calculateAgeing($date_battery1, $date_battery2);
                    } else {
                        $ageing = 0;
                    }
                }
                
                // Insert the record using prepared statement
                $stmt = $mysqli->prepare("INSERT INTO tbl_ups 
                    (ups_no, team, date_battery1, date_battery2, battery1_serial, battery2_serial, ageing, remarks, status, created_at, created_ip) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
                
                if (!$stmt) {
                    $errorMessages[] = "Row " . ($k + 1) . ": Database prepare error - " . $mysqli->error;
                    $errors++;
                    continue;
                }
                
                $stmt->bind_param("ssssssisss", 
                    $ups_no, 
                    $team, 
                    $date_battery1, 
                    $date_battery2,
                    $battery1_serial,
                    $battery2_serial,
                    $ageing, 
                    $remarks, 
                    $status,
                    $_SERVER['REMOTE_ADDR']
                );
                
                if ($stmt->execute()) {
                    $count++;
                } else {
                    // Check if it's a duplicate error
                    if (strpos($stmt->error, 'Duplicate entry') !== false) {
                        $errorMessages[] = "Row " . ($k + 1) . ": Duplicate UPS No - " . $ups_no;
                    } else {
                        $errorMessages[] = "Row " . ($k + 1) . ": Database error - " . $stmt->error;
                    }
                    $errors++;
                }
                $stmt->close();
            }
            
            // Store errors in session for display
            if (!empty($errorMessages)) {
                $_SESSION['import_errors'] = $errorMessages;
            }
            
            if ($count > 0) {
                redirect_with(['msg' => "Import Complete. Successfully added $count UPS record(s)." . ($errors > 0 ? " $errors record(s) had errors." : ""), 'type' => 'success']);
            } else {
                redirect_with(['msg' => "No records were imported. " . ($errors > 0 ? " $errors error(s) found." : ""), 'type' => 'warning']);
            }
            
        } else {
            $err = (method_exists('Shuchkin\SimpleXLSX', 'parseError')) ? Shuchkin\SimpleXLSX::parseError() : 'Unknown Error';
            redirect_with(['msg' => 'Excel Parse Error: ' . $err, 'type' => 'danger']);
        }
    } else {
        $errorMsg = 'No file uploaded.';
        if (isset($_FILES['excel_file']['error'])) {
            $uploadErrors = [
                1 => 'File exceeds upload_max_filesize',
                2 => 'File exceeds MAX_FILE_SIZE',
                3 => 'File partially uploaded',
                4 => 'No file uploaded',
                6 => 'Missing temporary folder',
                7 => 'Failed to write to disk',
                8 => 'PHP extension stopped the upload'
            ];
            $errorMsg = $uploadErrors[$_FILES['excel_file']['error']] ?? 'Upload error';
        }
        redirect_with(['msg' => 'File upload error: ' . $errorMsg, 'type' => 'danger']);
    }
}

// Handle POST actions for add/edit/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $ups_no = trim($_POST['ups_no'] ?? '');
        $team = trim($_POST['team'] ?? '');
        $date_battery1 = trim($_POST['date_battery1'] ?? '');
        $date_battery2 = trim($_POST['date_battery2'] ?? '');
        $battery1_serial = trim($_POST['battery1_serial'] ?? '');
        $battery2_serial = trim($_POST['battery2_serial'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        // Calculate ageing
        $ageing = calculateAgeing($date_battery1, $date_battery2);
        
        // Basic validation
        if ($ups_no === '') {
            redirect_with(['msg' => 'UPS No is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Insert new UPS
            $stmt = $mysqli->prepare("INSERT INTO tbl_ups (ups_no, team, date_battery1, date_battery2, battery1_serial, battery2_serial, ageing, remarks, status, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            
            $stmt->bind_param("ssssssisss", 
                $ups_no, 
                $team, 
                $date_battery1, 
                $date_battery2, 
                $battery1_serial, 
                $battery2_serial, 
                $ageing, 
                $remarks, 
                $status, 
                $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "UPS '$ups_no' added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding UPS: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Update UPS
                $sql = "UPDATE tbl_ups SET 
                        ups_no = ?, 
                        team = ?, 
                        date_battery1 = ?, 
                        date_battery2 = ?, 
                        battery1_serial = ?,
                        battery2_serial = ?,
                        ageing = ?, 
                        remarks = ?, 
                        status = ?, 
                        updated_at = NOW(), 
                        updated_ip = ? 
                        WHERE id = ?";
                
                $stmt = $mysqli->prepare($sql);
                
                $stmt->bind_param("ssssssisssi", 
                    $ups_no, 
                    $team, 
                    $date_battery1, 
                    $date_battery2, 
                    $battery1_serial, 
                    $battery2_serial, 
                    $ageing, 
                    $remarks, 
                    $status, 
                    $_SERVER['REMOTE_ADDR'], 
                    $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "UPS record updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating UPS: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $ups_no = '';
        
        if ($id > 0) {
            // Get UPS number for message
            $res = $mysqli->prepare("SELECT ups_no FROM tbl_ups WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($ups_no);
            $res->fetch();
            $res->close();
            
            // Delete UPS record
            $del = $mysqli->prepare("DELETE FROM tbl_ups WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "UPS '$ups_no' deleted", 'type' => 'warning']);
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editUPS = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_ups WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editUPS = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editUPS) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fteam = $_GET['team'] ?? '';
$falert = $_GET['alert'] ?? '';

// Build query
$sql = "SELECT * FROM tbl_ups WHERE 1=1";

$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(ups_no LIKE ? OR remarks LIKE ? OR battery1_serial LIKE ? OR battery2_serial LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if ($fteam !== '') {
    $cond[] = "team = ?";
    $params[] = $fteam;
    $types .= 's';
}

if ($falert !== '') {
    if ($falert === 'critical') {
        $cond[] = "ageing >= 36";
    } elseif ($falert === 'warning') {
        $cond[] = "ageing >= 30 AND ageing < 36";
    } elseif ($falert === 'info') {
        $cond[] = "ageing >= 24 AND ageing < 30";
    }
}

if (!empty($cond)) {
    $sql .= " AND " . implode(" AND ", $cond);
}

$sql .= " ORDER BY ageing DESC, ups_no ASC";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $upsRecords = $stmt->get_result();
} else {
    $upsRecords = $mysqli->query($sql);
}

// Get alert counts
$criticalCount = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_ups WHERE ageing >= 36")->fetch_assoc()['cnt'];
$warningCount = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_ups WHERE ageing >= 30 AND ageing < 36")->fetch_assoc()['cnt'];
$infoCount = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_ups WHERE ageing >= 24 AND ageing < 30")->fetch_assoc()['cnt'];
$totalUPS = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_ups")->fetch_assoc()['cnt'];

// Collect warranty notifications
$warrantyNotifications = [];
if ($upsRecords && $upsRecords->num_rows > 0) {
    $upsRecords->data_seek(0); // Reset pointer
    while($row = $upsRecords->fetch_assoc()) {
        // For Battery 1 - Check if serial exists in battery table
        $battery1Info = null;
        $battery1Warranty = 2; // Default warranty if not found
        if (!empty($row['battery1_serial'])) {
            $battery1Info = getBatteryWarrantyInfo($row['battery1_serial'], $mysqli);
            if ($battery1Info) {
                $battery1Warranty = $battery1Info['warranty'];
                // Use battery purchase date if available, otherwise use UPS installation date
                $battery1Date = !empty($battery1Info['purchase_date']) ? $battery1Info['purchase_date'] : $row['date_battery1'];
            } else {
                $battery1Date = $row['date_battery1'];
            }
        } else {
            $battery1Date = $row['date_battery1'];
        }
        
        // For Battery 2 - Check if serial exists in battery table
        $battery2Info = null;
        $battery2Warranty = 2; // Default warranty if not found
        if (!empty($row['battery2_serial'])) {
            $battery2Info = getBatteryWarrantyInfo($row['battery2_serial'], $mysqli);
            if ($battery2Info) {
                $battery2Warranty = $battery2Info['warranty'];
                // Use battery purchase date if available, otherwise use UPS installation date
                $battery2Date = !empty($battery2Info['purchase_date']) ? $battery2Info['purchase_date'] : $row['date_battery2'];
            } else {
                $battery2Date = $row['date_battery2'];
            }
        } else {
            $battery2Date = $row['date_battery2'];
        }
        
        $warrantyStatus1 = calculateWarrantyStatus($battery1Date, $battery1Warranty);
        $warrantyStatus2 = calculateWarrantyStatus($battery2Date, $battery2Warranty);
        
        if (!empty($warrantyStatus1['notification'])) {
            $warrantyNotifications[] = "UPS {$row['ups_no']} - Battery 1: {$warrantyStatus1['notification']}";
        }
        if (!empty($warrantyStatus2['notification'])) {
            $warrantyNotifications[] = "UPS {$row['ups_no']} - Battery 2: {$warrantyStatus2['notification']}";
        }
    }
    $upsRecords->data_seek(0); // Reset pointer again for display
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UPS & Battery Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1800px;
            margin: 0 auto;
        }
        .table th {
            background-color: #2c3e50;
            color: white;
            border: none;
            font-size: 12px;
            white-space: nowrap;
        }
        .table td {
            vertical-align: middle;
            font-size: 13px;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        .alert-stats {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            flex: 1;
            padding: 15px;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .stat-card h3 {
            margin: 0;
            font-size: 32px;
            font-weight: bold;
        }
        .stat-card p {
            margin: 5px 0 0 0;
            font-size: 14px;
        }
        .stat-critical { background: linear-gradient(135deg, #dc3545, #c82333); }
        .stat-warning { background: linear-gradient(135deg, #ffc107, #e0a800); }
        .stat-info { background: linear-gradient(135deg, #17a2b8, #138496); }
        .stat-success { background: linear-gradient(135deg, #28a745, #218838); }
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
        }
        .warranty-alert {
            font-size: 11px;
            margin-top: 2px;
            display: block;
        }
        .battery-info {
            font-size: 11px;
            color: #666;
        }
        .notification-panel {
            max-height: 200px;
            overflow-y: auto;
        }
        .warranty-badge {
            font-size: 10px;
            margin-left: 5px;
        }
        
        /* Import/Export buttons */
        .btn-import {
            background-color: #0dcaf0;
            border-color: #0dcaf0;
            color: white;
        }
        .btn-import:hover {
            background-color: #0bacd0;
            border-color: #0bacd0;
        }
        .btn-export {
            background-color: #20c997;
            border-color: #20c997;
            color: white;
        }
        .btn-export:hover {
            background-color: #1ba87e;
            border-color: #1ba87e;
        }
        
        /* Status summary cards */
        .status-summary-card {
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            margin-bottom: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }
        .status-summary-card:hover {
            transform: translateY(-5px);
        }
        .status-summary-card .count {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .status-summary-card .label {
            font-size: 0.9rem;
            text-transform: uppercase;
            font-weight: 600;
        }
        .summary-total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .summary-critical { background-color: #f8d7da; color: #721c24; border: 2px solid #f5c6cb; }
        .summary-warning { background-color: #fff3cd; color: #856404; border: 2px solid #ffeaa7; }
        .summary-info { background-color: #d1ecf1; color: #0c5460; border: 2px solid #bee5eb; }
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

        <!-- Warranty Notifications -->
        <?php if (!empty($warrantyNotifications)): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <h6 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Warranty Alerts</h6>
            <div class="notification-panel mt-2">
                <?php foreach($warrantyNotifications as $notification): ?>
                <div class="mb-1">• <?= h($notification) ?></div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
        
        <!-- Add/Edit Form -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-battery-three-quarters me-2"></i><?= $mode === 'edit' ? 'Edit UPS Record' : 'Add New UPS' ?></h4>
            <a href="ups.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editUPS['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="ups_no" class="form-label">UPS No. <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="ups_no" name="ups_no" 
                                       value="<?= h($editUPS['ups_no'] ?? '') ?>" 
                                       placeholder="UPS001" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="team" class="form-label">Team</label>
                                <select class="form-select" id="team" name="team">
                                    <option value="">Select Team</option>
                                    <option value="CE" <?= ($editUPS && $editUPS['team'] == 'CE') ? 'selected' : '' ?>>CE</option>
                                    <option value="PAG" <?= ($editUPS && $editUPS['team'] == 'PAG') ? 'selected' : '' ?>>PAG</option>
                                    <option value="XML" <?= ($editUPS && $editUPS['team'] == 'XML') ? 'selected' : '' ?>>XML</option>
                                    <option value="AP" <?= ($editUPS && $editUPS['team'] == 'AP') ? 'selected' : '' ?>>AP</option>
                                    <option value="UNIQUE" <?= ($editUPS && $editUPS['team'] == 'UNIQUE') ? 'selected' : '' ?>>UNIQUE</option>
                                    <option value="Sales" <?= ($editUPS && $editUPS['team'] == 'Sales') ? 'selected' : '' ?>>Sales</option>
                                    <option value="IT" <?= ($editUPS && $editUPS['team'] == 'IT') ? 'selected' : '' ?>>IT</option>
                                    <option value="Image" <?= ($editUPS && $editUPS['team'] == 'Image') ? 'selected' : '' ?>>Image</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0"><i class="fas fa-battery-full me-1"></i> Battery 1 Information</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="battery1_serial" class="form-label">Battery 1 Serial No.</label>
                                                <input type="text" class="form-control battery-serial" id="battery1_serial" name="battery1_serial"
                                                       value="<?= h($editUPS['battery1_serial'] ?? '') ?>"
                                                       placeholder="BAT00112345"
                                                       data-battery-date="date_battery1">
                                                <small class="text-muted">Enter serial to auto-pull warranty info</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="date_battery1" class="form-label">Battery 1 Installation Date</label>
                                                <input type="date" class="form-control battery-date" id="date_battery1" name="date_battery1"
                                                       value="<?= h($editUPS['date_battery1'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <?php if($mode === 'edit'): 
                                        // Get battery 1 warranty info
                                        $battery1Info = null;
                                        $battery1Warranty = 2;
                                        if (!empty($editUPS['battery1_serial'])) {
                                            $battery1Info = getBatteryWarrantyInfo($editUPS['battery1_serial'], $mysqli);
                                            if ($battery1Info) {
                                                $battery1Warranty = $battery1Info['warranty'];
                                                $battery1Date = !empty($battery1Info['purchase_date']) ? $battery1Info['purchase_date'] : $editUPS['date_battery1'];
                                            } else {
                                                $battery1Date = $editUPS['date_battery1'];
                                            }
                                        } else {
                                            $battery1Date = $editUPS['date_battery1'];
                                        }
                                        
                                        $warrantyStatus1 = calculateWarrantyStatus($battery1Date, $battery1Warranty);
                                    ?>
                                    <div class="alert alert-<?= $warrantyStatus1['class'] ?> py-2">
                                        <small>
                                            <i class="fas fa-info-circle me-1"></i>
                                            Battery 1 Warranty: <?= $warrantyStatus1['message'] ?>
                                            <?php if($battery1Info): ?>
                                            <span class="badge bg-dark warranty-badge"><?= $battery1Warranty ?> years warranty</span>
                                            <?php endif; ?>
                                            <?php if(!empty($warrantyStatus1['notification'])): ?>
                                            <br><strong><?= $warrantyStatus1['notification'] ?></strong>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0"><i class="fas fa-battery-full me-1"></i> Battery 2 Information</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="battery2_serial" class="form-label">Battery 2 Serial No.</label>
                                                <input type="text" class="form-control battery-serial" id="battery2_serial" name="battery2_serial"
                                                       value="<?= h($editUPS['battery2_serial'] ?? '') ?>"
                                                       placeholder="BAT00212345"
                                                       data-battery-date="date_battery2">
                                                <small class="text-muted">Enter serial to auto-pull warranty info</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="date_battery2" class="form-label">Battery 2 Installation Date</label>
                                                <input type="date" class="form-control battery-date" id="date_battery2" name="date_battery2"
                                                       value="<?= h($editUPS['date_battery2'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <?php if($mode === 'edit'): 
                                        // Get battery 2 warranty info
                                        $battery2Info = null;
                                        $battery2Warranty = 2;
                                        if (!empty($editUPS['battery2_serial'])) {
                                            $battery2Info = getBatteryWarrantyInfo($editUPS['battery2_serial'], $mysqli);
                                            if ($battery2Info) {
                                                $battery2Warranty = $battery2Info['warranty'];
                                                $battery2Date = !empty($battery2Info['purchase_date']) ? $battery2Info['purchase_date'] : $editUPS['date_battery2'];
                                            } else {
                                                $battery2Date = $editUPS['date_battery2'];
                                            }
                                        } else {
                                            $battery2Date = $editUPS['date_battery2'];
                                        }
                                        
                                        $warrantyStatus2 = calculateWarrantyStatus($battery2Date, $battery2Warranty);
                                    ?>
                                    <div class="alert alert-<?= $warrantyStatus2['class'] ?> py-2">
                                        <small>
                                            <i class="fas fa-info-circle me-1"></i>
                                            Battery 2 Warranty: <?= $warrantyStatus2['message'] ?>
                                            <?php if($battery2Info): ?>
                                            <span class="badge bg-dark warranty-badge"><?= $battery2Warranty ?> years warranty</span>
                                            <?php endif; ?>
                                            <?php if(!empty($warrantyStatus2['notification'])): ?>
                                            <br><strong><?= $warrantyStatus2['notification'] ?></strong>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="remarks" class="form-label">Remarks</label>
                                <textarea class="form-control" id="remarks" name="remarks" rows="2"><?= h($editUPS['remarks'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active" <?= ($editUPS && $editUPS['status'] == 'active') ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= ($editUPS && $editUPS['status'] == 'inactive') ? 'selected' : '' ?>>Inactive</option>
                                    <option value="maintenance" <?= ($editUPS && $editUPS['status'] == 'maintenance') ? 'selected' : '' ?>>Maintenance</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update UPS' : 'Save UPS' ?>
                        </button>
                        <a href="ups.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-battery-three-quarters me-2"></i>UPS & Battery Management (<?= $totalUPS ?> Records)</h4>
            <div class="d-flex gap-2">
                <!-- Import Button -->
                <button type="button" class="btn btn-import btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-import me-1"></i> Import Excel
                </button>
                <!-- Export Button -->
                <a href="export_ups.php" class="btn btn-export btn-sm">
                    <i class="fas fa-file-export me-1"></i> Export Excel
                </a>
                <!-- Add Button -->
                <a href="ups.php?mode=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Add UPS
                </a>
            </div>
        </div>

        <!-- IMPORT EXCEL MODAL -->
        <div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title"><i class="fas fa-file-excel"></i> Import UPS Records</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="import_excel">
                            <div class="mb-3">
                                <label class="form-label">Select Excel File</label>
                                <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls,.csv" required>
                                <div class="form-text small">
                                    Supported formats: .xlsx, .xls, .csv
                                </div>
                            </div>
                            
                            <div class="alert alert-warning small">
                                <strong>Excel Format Requirements:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>First row should be headers (will be skipped during import)</li>
                                    <li>Columns should be in this order:
                                        <ol class="mb-0 mt-1 small">
                                            <li><strong>UPS No</strong> (required)</li>
                                            <li><strong>Team</strong></li>
                                            <li><strong>Date_Bat 1</strong> (YYYY-MM-DD or DD/MM/YYYY)</li>
                                            <li><strong>Date_Bat 2</strong> (YYYY-MM-DD or DD/MM/YYYY)</li>
                                            <li><strong>Ageing</strong> (months, can be empty)</li>
                                            <li><strong>Remarks</strong></li>
                                            <li><strong>Battery 1 Serial</strong> (optional)</li>
                                            <li><strong>Battery 2 Serial</strong> (optional)</li>
                                        </ol>
                                    </li>
                                    <li class="mt-2">
                                        <a href="javascript:void(0);" onclick="downloadTemplate()" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-download me-1"></i> Download Template
                                        </a>
                                    </li>
                                </ul>
                            </div>
                            
                            <?php if(isset($_SESSION['import_errors']) && !empty($_SESSION['import_errors'])): ?>
                            <div class="alert alert-danger small">
                                <strong>Import Errors:</strong>
                                <ul class="mb-0 mt-2">
                                    <?php foreach($_SESSION['import_errors'] as $error): ?>
                                        <li><?= h($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php 
                            unset($_SESSION['import_errors']); 
                            endif; 
                            ?>
                            
                            <div class="d-flex justify-content-end">
                                <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Close</button>
                                <button type="submit" class="btn btn-info text-white">Import</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Summary Section -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="status-summary-card summary-total">
                    <div class="count"><?= $totalUPS ?></div>
                    <div class="label">Total UPS</div>
                    <small>All active and inactive</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="status-summary-card summary-critical">
                    <div class="count"><?= $criticalCount ?></div>
                    <div class="label">Critical</div>
                    <small>36+ months (Replace Now!)</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="status-summary-card summary-warning">
                    <div class="count"><?= $warningCount ?></div>
                    <div class="label">Warning</div>
                    <small>30-35 months (Replace Soon)</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="status-summary-card summary-info">
                    <div class="count"><?= $infoCount ?></div>
                    <div class="label">Monitor</div>
                    <small>24-29 months (Monitor)</small>
                </div>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search UPS, Serial No or Remarks..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="team" class="form-select form-select-sm">
                            <option value="">All Teams</option>
                            <option value="CE" <?= $fteam === 'CE' ? 'selected' : '' ?>>CE</option>
                            <option value="PAG" <?= $fteam === 'PAG' ? 'selected' : '' ?>>PAG</option>
                            <option value="XML" <?= $fteam === 'XML' ? 'selected' : '' ?>>XML</option>
                            <option value="UNIQUE" <?= $fteam === 'UNIQUE' ? 'selected' : '' ?>>UNIQUE</option>
                            <option value="Sales" <?= $fteam === 'Sales' ? 'selected' : '' ?>>Sales</option>
                            <option value="IT" <?= $fteam === 'IT' ? 'selected' : '' ?>>IT</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="alert" class="form-select form-select-sm">
                            <option value="">All Alerts</option>
                            <option value="critical" <?= $falert === 'critical' ? 'selected' : '' ?>>Critical (36+ months)</option>
                            <option value="warning" <?= $falert === 'warning' ? 'selected' : '' ?>>Warning (30-35 months)</option>
                            <option value="info" <?= $falert === 'info' ? 'selected' : '' ?>>Info (24-29 months)</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="ups.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- UPS Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>UPS No.</th>
                                <th>Team</th>
                                <th>Battery 1</th>
                                <th>Battery 2</th>
                                <th>Ageing (Months)</th>
                                <th>Battery Status</th>
                                <th>Warranty Status</th>
                                <th>Remarks</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($upsRecords && $upsRecords->num_rows > 0): ?>
                                <?php while($row = $upsRecords->fetch_assoc()): 
                                    $alertClass = getAlertClass($row['ageing']);
                                    $alertText = 'Good';
                                    if ($row['ageing'] >= 36) $alertText = 'REPLACE NOW!';
                                    elseif ($row['ageing'] >= 30) $alertText = 'Replace Soon';
                                    elseif ($row['ageing'] >= 24) $alertText = 'Monitor';
                                    
                                    // Get battery warranty information
                                    $battery1Info = null;
                                    $battery1Warranty = 2;
                                    if (!empty($row['battery1_serial'])) {
                                        $battery1Info = getBatteryWarrantyInfo($row['battery1_serial'], $mysqli);
                                        if ($battery1Info) {
                                            $battery1Warranty = $battery1Info['warranty'];
                                            $battery1Date = !empty($battery1Info['purchase_date']) ? $battery1Info['purchase_date'] : $row['date_battery1'];
                                        } else {
                                            $battery1Date = $row['date_battery1'];
                                        }
                                    } else {
                                        $battery1Date = $row['date_battery1'];
                                    }
                                    
                                    $battery2Info = null;
                                    $battery2Warranty = 2;
                                    if (!empty($row['battery2_serial'])) {
                                        $battery2Info = getBatteryWarrantyInfo($row['battery2_serial'], $mysqli);
                                        if ($battery2Info) {
                                            $battery2Warranty = $battery2Info['warranty'];
                                            $battery2Date = !empty($battery2Info['purchase_date']) ? $battery2Info['purchase_date'] : $row['date_battery2'];
                                        } else {
                                            $battery2Date = $row['date_battery2'];
                                        }
                                    } else {
                                        $battery2Date = $row['date_battery2'];
                                    }
                                    
                                    // Calculate warranty status using actual warranty periods
                                    $warrantyStatus1 = calculateWarrantyStatus($battery1Date, $battery1Warranty);
                                    $warrantyStatus2 = calculateWarrantyStatus($battery2Date, $battery2Warranty);
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?= h($row['ups_no']) ?></td>
                                    <td><?= h($row['team']) ?></td>
                                    <td>
                                        <div>
                                            <strong>Date:</strong> <?= formatDate($row['date_battery1']) ?><br>
                                            <?php if(!empty($row['battery1_serial'])): ?>
                                            <span class="battery-info">S/N: <?= h($row['battery1_serial']) ?></span>
                                            <?php if($battery1Info): ?>
                                            <span class="badge bg-secondary warranty-badge"><?= $battery1Warranty ?>Y</span>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if($warrantyStatus1['status'] !== 'no_date' && $warrantyStatus1['status'] !== 'good'): ?>
                                        <span class="badge bg-<?= $warrantyStatus1['class'] ?> warranty-alert">
                                            <i class="fas fa-exclamation-circle me-1"></i>
                                            Bat1: <?= $warrantyStatus1['message'] ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div>
                                            <strong>Date:</strong> <?= formatDate($row['date_battery2']) ?><br>
                                            <?php if(!empty($row['battery2_serial'])): ?>
                                            <span class="battery-info">S/N: <?= h($row['battery2_serial']) ?></span>
                                            <?php if($battery2Info): ?>
                                            <span class="badge bg-secondary warranty-badge"><?= $battery2Warranty ?>Y</span>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if($warrantyStatus2['status'] !== 'no_date' && $warrantyStatus2['status'] !== 'good'): ?>
                                        <span class="badge bg-<?= $warrantyStatus2['class'] ?> warranty-alert">
                                            <i class="fas fa-exclamation-circle me-1"></i>
                                            Bat2: <?= $warrantyStatus2['message'] ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $alertClass ?> fs-6">
                                            <?= $row['ageing'] ?> months
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $alertClass ?>">
                                            <?php if ($alertClass === 'danger'): ?>
                                                <i class="fas fa-exclamation-triangle"></i>
                                            <?php elseif ($alertClass === 'warning'): ?>
                                                <i class="fas fa-exclamation-circle"></i>
                                            <?php elseif ($alertClass === 'info'): ?>
                                                <i class="fas fa-info-circle"></i>
                                            <?php else: ?>
                                                <i class="fas fa-check-circle"></i>
                                            <?php endif; ?>
                                            <?= $alertText ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($warrantyStatus1['status'] !== 'no_date' || $warrantyStatus2['status'] !== 'no_date'): ?>
                                            <?php 
                                            // Show the most critical warranty status
                                            $warranties = [$warrantyStatus1, $warrantyStatus2];
                                            $criticalWarranty = array_reduce($warranties, function($carry, $item) {
                                                if ($item['status'] === 'no_date') return $carry;
                                                $priority = ['expired' => 4, 'critical' => 3, 'warning' => 2, 'good' => 1];
                                                if (!$carry || $priority[$item['status']] > $priority[$carry['status']]) {
                                                    return $item;
                                                }
                                                return $carry;
                                            }, null);
                                            ?>
                                            <?php if($criticalWarranty): ?>
                                            <span class="badge bg-<?= $criticalWarranty['class'] ?>">
                                                <?= $criticalWarranty['message'] ?>
                                            </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">No warranty data</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?= h($row['remarks']) ?></small></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="ups.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Delete UPS <?= h($row['ups_no']) ?>?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-battery-three-quarters fa-3x mb-3"></i>
                                            <h5>No UPS records found</h5>
                                            <p class="mb-0">Add UPS devices to track battery replacement.</p>
                                            <a href="ups.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add UPS
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

            // Auto-submit ONLY for the search/filter form (method="get")
            const searchForm = document.querySelector('form[method="get"]');
            if (searchForm) {
                const filterSelects = searchForm.querySelectorAll('select[name="team"], select[name="alert"]');
                filterSelects.forEach(select => {
                    select.addEventListener('change', function() {
                        searchForm.submit();
                    });
                });
            }

            // Auto-update battery date when serial is entered
            const batterySerials = document.querySelectorAll('.battery-serial');
            batterySerials.forEach(serialInput => {
                serialInput.addEventListener('blur', function() {
                    if (this.value.trim() !== '') {
                        const dateFieldId = this.getAttribute('data-battery-date');
                        const dateField = document.getElementById(dateFieldId);
                        if (dateField && (!dateField.value || dateField.value === '')) {
                            // Set today's date as default when serial is entered but no date is set
                            const today = new Date().toISOString().split('T')[0];
                            dateField.value = today;
                        }
                    }
                });
            });
        });

        // Function to download Excel template
        function downloadTemplate() {
            // Create CSV content based on your image format
            const csvContent = "UPS No.,Team,Date_Bat 1,Date_Bat 2,Ageing,Remarks,Battery1_Serial,Battery2_Serial\n" +
                               "UPS001,CE,2023-01-15,2023-02-20,24,First floor server room,BAT00112345,BAT00112346\n" +
                               "UPS002,PAG,2022-06-10,,30,Meeting room,,BAT00212347\n" +
                               "UPS003,XML,2021-11-05,2023-05-15,36,Data center,BAT00312348,BAT00312349\n" +
                               "UPS004,Sales,2023-03-22,,12,Reception area,BAT00412350,\n" +
                               "UPS005,IT,2020-08-17,2022-09-25,42,IT server rack,BAT00512351,BAT00512352";
            
            // Create blob and download link
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement("a");
            const url = URL.createObjectURL(blob);
            
            link.setAttribute("href", url);
            link.setAttribute("download", "UPS_Import_Template.csv");
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>
</html>