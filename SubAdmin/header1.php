<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent caching issues
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Redirect to login if not logged in
if (!isset($_SESSION['uid'])) {
    header("Location: ../index.php");
    exit;
}

// Detect current page for menu highlighting
$currentPage = basename($_SERVER['PHP_SELF']);
$username    = htmlspecialchars($_SESSION['username'] ?? 'User');
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
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg">
  <div class="container-fluid">
    <div class="header-logo">
      <img src="tlogo.jpg" alt="Tapasi Solutions LLP" />
    </div>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <!-- Left menu -->
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">

        <!-- Dashboard -->
        <li class="nav-item">
          <a class="nav-link <?= $currentPage==='dashboard.php'?'active':'' ?>" href="dashboard.php">
            <i class="fa fa-home"></i> Dashboard
          </a>
        </li>

        <!-- Master -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= in_array($currentPage, ['user_role_master.php','job_master.php','client_master.php','employee_master.php']) ? 'active' : '' ?>"
             href="#" data-bs-toggle="dropdown">
            <i class="fa fa-users"></i> Master
          </a>
          <ul class="dropdown-menu">
                    <li><a class="dropdown-item <?= $currentPage==='company_details.php'?'active':'' ?>" href="company_details.php">Company Master</a></li> 

            <li><a class="dropdown-item <?= $currentPage==='user_role_master.php'?'active':'' ?>" href="user_role_master.php">User Role Master</a></li>
            <li><a class="dropdown-item <?= $currentPage==='job_master.php'?'active':'' ?>" href="job_master.php">Job Master</a></li>
             <li><a class="dropdown-item <?= $currentPage==='process_master.php'?'active':'' ?>" href="process_master.php">Process Master</a></li>
            <li><a class="dropdown-item <?= $currentPage==='client_master.php'?'active':'' ?>" href="client_master.php">Client Master</a></li>
            <li><a class="dropdown-item <?= $currentPage==='employee_master.php'?'active':'' ?>" href="employee_master.php">Employee Master</a></li>
          </ul>
        </li>

        <!-- JOB -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
            <i class="fa fa-box"></i> JOB
          </a>
          <ul class="dropdown-menu">
              <li><a class="dropdown-item <?= $currentPage==='team_view.php'?'active':'' ?>" href="team_view.php">Teams</a></li> 
            <li><a class="dropdown-item <?= $currentPage==='jobs.php'?'active':'' ?>" href="jobs.php">Manage Job</a></li> 
               <li><a class="dropdown-item <?= $currentPage==='job_management.php'?'active':'' ?>" href="job_management.php">Job Progress</a></li> 
               <li><a class="dropdown-item <?= $currentPage==='input_output_view.php'?'active':'' ?>" href="input_output_view.php">Input-Output</a></li> 
                 <li><a class="dropdown-item <?= $currentPage==='gen_invoice.php'?'active':'' ?>" href="gen_invoice.php">Generate Invoice</a></li> 
                 <li><a class="dropdown-item <?= $currentPage==='gen_invoice1.php'?'active':'' ?>" href="gen_invoice1.php">Generate Invoice New</a></li> 
          </ul>
        </li>

      </ul>
      <!-- Right menu (HR/User dropdown) -->
      <ul class="navbar-nav ms-auto">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
            <i class="fa fa-user-circle"></i> <?= $username ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="change_password.php">Change Password</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>

<div class="container mt-4">