<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    if(isset($_SESSION['uid'])) $exeid=$_SESSION['uid'];
}

// ... (Your existing header.php code up to $username = ...)

// --- DATABASE LOGIC FOR DYNAMIC MENU ---
require 'db.php'; // Ensure db connection is available


session_start();
$employee_id = $_SESSION['uid']; // set at login

// Step 1: Fetch employee process IDs
$sql = "SELECT process FROM employee_master WHERE employee_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();

$show_ce = $show_le = $show_pag = $show_qc = false;

if ($row = $result->fetch_assoc()) {
    // Convert comma-separated IDs to array
   // $process_ids = array_map('trim', explode(',', $row['process']));
    $process_ids=$row['process'];
 //   if ($process_ids!='') {
        // Step 2: Fetch process names from process_master
   //     $placeholders = implode(',', array_fill(0, count($process_ids), '?'));
      //  $types = str_repeat('i', count($process_ids));

        $sql2 = "SELECT process_name FROM process_master WHERE process_id IN ($process_ids)";
      //  echo $sql2;
   
$result2 = $mysqli->query($sql2);
        $process_names = [];
        
        while ($row2 = $result2->fetch_assoc()) {
            $process_names[] = $row2['process_name'];
            if (strpos($row2['process_name'], 'CE') !== false) {
            $show_ce = true;
            } else {
            $process_value = $row2['process_name']; // or leave empty
            }
            if (strpos($row2['process_name'], 'LE') !== false) {
            $show_le = true;
            } else {
            $process_value = $row2['process_name']; // or leave empty
            }
            if (strpos($row2['process_name'], 'PAG') !== false) {
            $show_pag = true;
            } else {
            $process_value = $row2['process_name']; // or leave empty
            }
            if (strpos($row2['process_name'], 'QC') !== false) {
            $show_qc = true;
            } else {
            $process_value = $row2['process_name']; // or leave empty
            }
        }

        // Step 3: Set flags based on process names
     /*   if (in_array('CE', $process_names)) $show_ce = true;
        if (in_array('LE', $process_names)) $show_le = true;
        if (in_array('PAG', $process_names)) $show_pag = true;
        if (in_array('QC', $process_names)) $show_qc = true;*/
    }
//}
//echo "QC".$show_qc."LE".$show_le;
//die;

// ----------------------------------------



// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Redirect to login if not logged in
if (!isset($_SESSION['uid'])) {
    header("Location: ../index.php");
    exit;
}

$currentPage = basename($_SERVER['PHP_SELF']);
$username = htmlspecialchars($_SESSION['username'] ?? 'User');



$uid = $_SESSION['uid'] ?? 0;
$process = '';
/*echo $uid;
die;
if ($uid > 0) {
    $stmt = $con->prepare("SELECT process, process1 FROM employee_master WHERE employee_id = ?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $process  = $row['process'];
    }
    $stmt->close();
}*/
// Query
$sql = "SELECT COUNT(*) AS ce_unassigned_count
FROM jobs j
WHERE j.process_id IN (1,4,5)
  AND j.assigned_ce IS NULL;
";

$result = $mysqli->query($sql);

$ce_unassigned_count = 0; // default

if ($result && $row = $result->fetch_assoc()) {
    $ce_unassigned_count = $row['ce_unassigned_count'];
    
}
$sql = "SELECT COUNT(*) AS le_unassigned_count
FROM jobs j
WHERE j.process_id IN (1,4,5)
  AND j.assigned_le IS NULL AND j.assigned_ce <>''";

$result = $mysqli->query($sql);

$le_unassigned_count = 0; // default

if ($result && $row = $result->fetch_assoc()) {
    $le_unassigned_count = $row['le_unassigned_count'];
    
}
$sql = "SELECT COUNT(*) AS pag_unassigned_count
FROM jobs j
WHERE j.process_id IN (2,4,5)
  AND j.assigned_pag IS NULL;
";

$result = $mysqli->query($sql);

$pag_unassigned_count = 0; // default

if ($result && $row = $result->fetch_assoc()) {
    $pag_unassigned_count = $row['pag_unassigned_count'];
    
}


$sql = "SELECT COUNT(*) AS qc_unassigned_count
FROM jobs j
WHERE j.process_id IN (2,4,5)
  AND j.assigned_qc IS NULL;
";

$result = $mysqli->query($sql);

$qc_unassigned_count = 0; // default

if ($result && $row = $result->fetch_assoc()) {
    $qc_unassigned_count = $row['qc_unassigned_count'];
    
}

//echo  $ce_unassigned_count;
//die;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Tapasi ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <style>
        .navbar { background:#CBA677 !important; }
        .navbar .nav-link, .navbar-brand { color:#4E3620 !important; font-weight:500; }
        .navbar .nav-link:hover, .navbar .nav-link.active {
            background:#8B6450 !important;
            color:#fff !important;
            border-radius:8px;
            padding:6px 12px;
        }
        .header-logo img { height:50px; width:auto; object-fit:contain; margin:5px 15px; }
        .navbar .dropdown-toggle:focus,
        .navbar .dropdown-toggle:active,
        .navbar .dropdown-menu.show {
            background:#CBA677 !important;
            color:#4E3620 !important;
        }
        .navbar .dropdown-menu .dropdown-item:hover {
            background:#8B6450 !important;
            color:#fff !important;
        }
   /*Blinking*/
     .count-badge {
    background-color: red;
    color: white;
    border-radius: 50%;
    padding: 3px 7px;
    font-size: 12px;
    font-weight: bold;
    vertical-align: middle;
    margin-left: 5px;
    animation: blinker 1s linear infinite;
}

/* Different colors for each */
.ce-badge  { background-color: #e74c3c; } /* Red */
.le-badge  { background-color: #3498db; } /* Blue */
.pag-badge { background-color: #f39c12; } /* Orange */
.qc-badge  { background-color: #27ae60; } /* Green */

@keyframes blinker {
    50% { opacity: 0; }
}

    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg">
    <div class="container-fluid">
        <div class="header-logo">
            <img src="tlogo.jpg" alt="Tapasi Solutions LLP" />
        </div>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
       <ul class="navbar-nav me-auto mb-2 mb-lg-0">

    <li class="nav-item">
        <a class="nav-link <?= $currentPage === 'dashboard_exec.php' ? 'active' : '' ?>" href="dashboard_exec.php">
            <i class="fa fa-home"></i> Dashboard
        </a>
    </li>
                
    <li class="nav-item">
        <a class="nav-link <?= $currentPage === 'jobs.php' ? 'active' : '' ?>" href="jobs.php">
            <i class="fa fa-users"></i> Manage Job
        </a>
    </li>
            <?php if ($show_ce): ?>    
    <li class="nav-item">
        <a class="nav-link <?= $currentPage === 'ce.php' ? 'active' : '' ?>" href="ce.php">
            <i class="fa fa-file-text"></i> CE 
            <?php if ($ce_unassigned_count > 0): ?>
                <span class="count-badge ce-badge">
                    <?= $ce_unassigned_count ?>
                </span>
            <?php endif; ?>
        </a>
    </li>    
    <?php endif; ?>
<?php if ($show_le): ?>
    <li class="nav-item">
        <a class="nav-link <?= $currentPage === 'le.php' ? 'active' : '' ?>" href="le.php">
            <i class="fa fa-globe"></i> LE
            <?php if ($le_unassigned_count > 0): ?>
                <span class="count-badge le-badge">
                    <?= $le_unassigned_count ?>
                </span>
            <?php endif; ?>
        </a>
    </li> 
<?php endif; ?>

<?php if ($show_pag): ?>
    <li class="nav-item">
        <a class="nav-link <?= $currentPage === 'pag.php' ? 'active' : '' ?>" href="pag.php">
            <i class="fa fa-file-text-o"></i> PAG
            <?php if ($pag_unassigned_count > 0): ?>
                <span class="count-badge pag-badge">
                    <?= $pag_unassigned_count ?>
                </span>
            <?php endif; ?>
        </a>
    </li> 
<?php endif; ?>

<?php if ($show_qc): ?>
    <li class="nav-item">
        <a class="nav-link <?= $currentPage === 'qc.php' ? 'active' : '' ?>" href="qc.php">
            <i class="fa fa-check-square-o"></i> QC
            <?php if ($qc_unassigned_count > 0): ?>
                <span class="count-badge qc-badge">
                    <?= $qc_unassigned_count ?>
                </span>
            <?php endif; ?>
        </a>
    </li> 
<?php endif; ?>
<li class="nav-item">
        <a class="nav-link <?= $currentPage === 'fqa.php' ? 'active' : '' ?>" href="fqa.php">
            <i class="fa fa-line-chart"></i> FQA Check
        </a>
    </li>

<?php if ($process=="12"){?>
    <li class="nav-item">
        <a class="nav-link <?= $currentPage === 'final_job.php' ? 'active' : '' ?>" href="final_job.php">
            <i class="fa fa-line-chart"></i> Job Progress (FQA)
        </a>
    </li>
<?php } ?>

    <li class="nav-item">
        <a class="nav-link <?= $currentPage === 'job_management.php' ? 'active' : '' ?>" href="job_management.php">
            <i class="fa fa-line-chart"></i> Job Progress
        </a>
    </li>
  <li class="nav-item">
        <a class="nav-link <?= $currentPage === 'alljobs.php' ? 'active' : '' ?>" href="alljobs.php">
            <i class="fa fa-line-chart"></i> File Upload(CE)
        </a>
    </li>
<li class="nav-item">
        <a class="nav-link <?= $currentPage === 'alljobs_qc.php' ? 'active' : '' ?>" href="alljobs_qc.php">
            <i class="fa fa-line-chart"></i> File Upload(QC)
        </a>
    </li>


</ul>     <ul class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" role="button" aria-expanded="false">
                        <i class="fa fa-user-circle"></i> <?= $username ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container mt-4">