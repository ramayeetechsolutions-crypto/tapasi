<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'db.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check login
if (!isset($_SESSION['itname']) && !isset($_SESSION['infoname']) && !isset($_SESSION['uid'])) {
    header("Location: ../index.php");
    exit;
}

$current_username = $_SESSION['username'];

// Get employee details
$empStmt = $mysqli->prepare("
    SELECT employee_name, employee_id
    FROM employee_master
    WHERE employee_username = ?
");
$empStmt->bind_param("s", $current_username);
$empStmt->execute();
$empResult = $empStmt->get_result();

$employee = $empResult->fetch_assoc();

// Get assigned system
$stmt = $mysqli->prepare("
    SELECT *
    FROM tbl_systems_v2
    WHERE id_no = ?
    LIMIT 1
");
$stmt->bind_param("s", $current_username);
$stmt->execute();
$result = $stmt->get_result();

$system = $result->fetch_assoc();

if (!function_exists('h')) {
function h($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>System Details</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="../custom_style.css?v=<?= file_exists(__DIR__ . '/../custom_style.css') ? filemtime(__DIR__ . '/../custom_style.css') : 1 ?>">

<style>

body{
    background:#f5f7fb;
}

.main-content{
    padding:25px;
    margin-top: 70px;
}

.card{
    border: 1px solid #e9ecef;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
}

.card-header{
    background: var(--ash, #6c757d) !important;
    color:#fff !important;
}

.table td{
    padding:12px;
}

.icon{
    width:28px;
    color:#0d6efd;
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
.page-title-container h1, .page-title-container h3 {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 600;
    color: white;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Redesigned 2-column table styling with zebra striping and mild lines (matching Verify Details page) */
.details-table { 
    width: 100%; 
    border-collapse: collapse; 
    border: 1px solid #e9ecef;
    background-color: #ffffff;
    font-size: 0.875rem;
}
.details-table td,
.details-table .field-name,
.details-table .field-value { 
    padding: 12px 18px; 
    border: 1px solid #e9ecef; 
    vertical-align: middle;
    line-height: 1.5;
}
.details-table .field-name { 
    font-weight: 700 !important; 
    width: 35%; 
    color: #1e293b !important;
    letter-spacing: 0.01em;
}
.details-table .field-value { 
    width: 65%;
    color: #1e293b;
    font-weight: 500;
}
/* Zebra Striping: Odd rows */
.details-table tbody tr:nth-child(odd),
.details-table tr:nth-child(odd) {
    background-color: #ffffff;
}
.details-table tbody tr:nth-child(odd) .field-name,
.details-table tr:nth-child(odd) .field-name {
    background-color: #f8fafc;
    color: #1e293b !important;
    font-weight: 700 !important;
}
.details-table tbody tr:nth-child(odd) .field-value,
.details-table tr:nth-child(odd) .field-value {
    background-color: #ffffff;
}
/* Zebra Striping: Even rows */
.details-table tbody tr:nth-child(even),
.details-table tr:nth-child(even) {
    background-color: #f4f6f9;
}
.details-table tbody tr:nth-child(even) .field-name,
.details-table tr:nth-child(even) .field-name {
    background-color: #eef2f6;
    color: #1e293b !important;
    font-weight: 700 !important;
}
.details-table tbody tr:nth-child(even) .field-value,
.details-table tr:nth-child(even) .field-value {
    background-color: #f8fafc;
}
/* Smooth Interactive Hover */
.details-table tbody tr:hover,
.details-table tr:hover {
    background-color: #eef6fc;
}
.details-table tbody tr:hover .field-name,
.details-table tr:hover .field-name {
    background-color: #e2eaf2;
    color: #0f172a !important;
    font-weight: 700 !important;
}
.details-table tbody tr:hover .field-value,
.details-table tr:hover .field-value {
    background-color: #f0f7ff;
}

</style>

</head>

<body>

<?php require 'header.php'; ?>

<div class="main-content">

<div class="container-fluid">

<?php if($system){ ?>

<div class="page-title-container" style="background-color: #6c757d !important; background: #6c757d !important; color: #ffffff !important; padding: 12px 20px !important; border-radius: 12px !important; margin-bottom: 20px !important; display: flex !important; justify-content: space-between !important; align-items: center !important;">
    <h1 class="mb-0 text-white" style="font-size: 1.25rem !important; color: #ffffff !important;">
        <i class="fas fa-desktop me-2" style="color: #ffffff !important;"></i> System Details
    </h1>
</div>

<div class="row">

<div class="col-lg-4">

<div class="card mb-4" style="border: 1px solid #e9ecef !important; border-radius: 12px !important; overflow: hidden !important;">

<div class="card-header" style="background: var(--ash, #6c757d) !important; color: #fff !important; font-weight: 600; padding: 12px 18px;">
<i class="fas fa-user me-2"></i> Employee Information
</div>

<div class="card-body p-0">

<table class="details-table">

<tr>
<td class="field-name">Employee ID</td>
<td class="field-value"><?=h($system['id_no'])?></td>
</tr>

<tr>
<td class="field-name">Employee Name</td>
<td class="field-value"><?=h($system['member_name'])?></td>
</tr>

<tr>
<td class="field-name">Cabin No</td>
<td class="field-value"><?=h($system['cabin_no'])?></td>
</tr>

<tr>
<td class="field-name">System No</td>
<td class="field-value">
<span class="badge bg-primary fs-6">
<?=h($system['system_no'])?>
</span>
</td>
</tr>

</table>

</div>

</div>

</div>


<div class="col-lg-8">

<div class="card mb-4" style="border: 1px solid #e9ecef !important; border-radius: 12px !important; overflow: hidden !important;">

<div class="card-header" style="background: var(--ash, #6c757d) !important; color: #fff !important; font-weight: 600; padding: 12px 18px;">
<i class="fas fa-microchip me-2"></i> Hardware Configuration
</div>

<div class="card-body p-0">

<table class="details-table">

<tr>
<td class="field-name" style="width: 35%;">Operating System</td>
<td class="field-value"><?=h($system['os'])?></td>
</tr>

<tr>
<td class="field-name">Processor</td>
<td class="field-value"><?=h($system['processor'])?></td>
</tr>

<tr>
<td class="field-name">RAM</td>
<td class="field-value"><?=h($system['ram'])?></td>
</tr>

<tr>
<td class="field-name">CPU</td>
<td class="field-value"><?=h($system['cpu'])?></td>
</tr>

<tr>
<td class="field-name">CPU Number</td>
<td class="field-value"><?=h($system['cpu_no'])?></td>
</tr>

<tr>
<td class="field-name">Monitor</td>
<td class="field-value"><?=h($system['monitor'])?></td>
</tr>

<tr>
<td class="field-name">Monitor Number</td>
<td class="field-value"><?=h($system['monitor_no'])?></td>
</tr>

<tr>
<td class="field-name">Keyboard</td>
<td class="field-value"><?=h($system['keyboard'])?></td>
</tr>

<tr>
<td class="field-name">Keyboard Number</td>
<td class="field-value"><?=h($system['keyboard_no'])?></td>
</tr>

<tr>
<td class="field-name">Mouse</td>
<td class="field-value"><?=h($system['mouse'])?></td>
</tr>

<tr>
<td class="field-name">Mouse Number</td>
<td class="field-value"><?=h($system['mouse_no'])?></td>
</tr>

<tr>
<td class="field-name">Remarks</td>
<td class="field-value">

<?php
if(trim($system['remarks'])=="")
{
    echo "<span class='text-muted'>No remarks</span>";
}
else
{
    echo nl2br(h($system['remarks']));
}
?>

</td>
</tr>

<tr>
<td class="field-name">Last Updated</td>
<td class="field-value">

<?php

if(!empty($system['updated_at']))
{
    echo date("d-m-Y h:i A",strtotime($system['updated_at']));
}
else
{
    echo date("d-m-Y h:i A",strtotime($system['created_at']));
}

?>

</td>
</tr>

</table>

</div>

</div>

</div>

</div>

<?php } else { ?>

<div class="row justify-content-center">

<div class="col-lg-8">

<div class="page-title-container" style="background-color: #6c757d !important; background: #6c757d !important; color: #ffffff !important; padding: 12px 20px !important; border-radius: 12px !important; margin-bottom: 20px !important; display: flex !important; justify-content: space-between !important; align-items: center !important;">
    <h1 class="mb-0 text-white" style="font-size: 1.25rem !important; color: #ffffff !important;">
        <i class="fas fa-desktop me-2" style="color: #ffffff !important;"></i> System Details
    </h1>
</div>

<div class="card">

<div class="card-body text-center p-5">

<i class="fas fa-desktop fa-5x text-secondary mb-4"></i>

<h2 class="text-warning">

No System Assigned

</h2>

<p class="lead">

Hello <strong><?=h($employee['employee_name'] ?? $_SESSION['username'])?></strong>,

</p>

<p>

There is currently <strong>no computer/system assigned</strong> to your Employee ID.

</p>

<p>

Please contact the <strong>System Administrator / IT Department</strong> for assistance.

</p>

<hr>

<div class="row">

<div class="col-md-6">

<h6>Employee ID</h6>

<span class="badge bg-primary fs-6">

<?=h($_SESSION['username'])?>

</span>

</div>

<div class="col-md-6">

<h6>Status</h6>

<span class="badge bg-danger fs-6">

Not Assigned

</span>

</div>

</div>

</div>

</div>

</div>

</div>

<?php } ?>

</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>