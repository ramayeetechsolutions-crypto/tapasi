<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'db.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check login
if (!isset($_SESSION['uid'])) {
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

function h($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
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

<style>

body{
    background:#f5f7fb;
}

.main-content{
    padding:25px;
}

.card{
    border:none;
    border-radius:12px;
    box-shadow:0 3px 12px rgba(0,0,0,.08);
}

.card-header{
    background:#5c6670;
    color:#fff;
}

.table td{
    padding:12px;
}

.icon{
    width:28px;
    color:#0d6efd;
}

</style>

</head>

<body>

<?php require 'header.php'; ?>

<div class="main-content">

<div class="container-fluid">

<div class="mb-4">

<h3>
<i class="fas fa-desktop"></i>
System Details
</h3>

</div>

<?php if($system){ ?>

<div class="row">

<div class="col-lg-4">

<div class="card mb-4">

<div class="card-header">
<i class="fas fa-user"></i>
Employee Information
</div>

<div class="card-body">

<table class="table table-bordered">

<tr>
<th>Employee ID</th>
<td><?=h($system['id_no'])?></td>
</tr>

<tr>
<th>Employee Name</th>
<td><?=h($system['member_name'])?></td>
</tr>

<tr>
<th>Cabin No</th>
<td><?=h($system['cabin_no'])?></td>
</tr>

<tr>
<th>System No</th>
<td>
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

<div class="card mb-4">

<div class="card-header">
<i class="fas fa-microchip"></i>
Hardware Configuration
</div>

<div class="card-body">

<table class="table table-striped table-bordered">

<tr>
<th width="35%">Operating System</th>
<td><?=h($system['os'])?></td>
</tr>

<tr>
<th>Processor</th>
<td><?=h($system['processor'])?></td>
</tr>

<tr>
<th>RAM</th>
<td><?=h($system['ram'])?></td>
</tr>

<tr>
<th>CPU</th>
<td><?=h($system['cpu'])?></td>
</tr>

<tr>
<th>CPU Number</th>
<td><?=h($system['cpu_no'])?></td>
</tr>

<tr>
<th>Monitor</th>
<td><?=h($system['monitor'])?></td>
</tr>

<tr>
<th>Monitor Number</th>
<td><?=h($system['monitor_no'])?></td>
</tr>

<tr>
<th>Keyboard</th>
<td><?=h($system['keyboard'])?></td>
</tr>

<tr>
<th>Keyboard Number</th>
<td><?=h($system['keyboard_no'])?></td>
</tr>

<tr>
<th>Mouse</th>
<td><?=h($system['mouse'])?></td>
</tr>

<tr>
<th>Mouse Number</th>
<td><?=h($system['mouse_no'])?></td>
</tr>

<tr>
<th>Remarks</th>
<td>

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
<th>Last Updated</th>
<td>

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