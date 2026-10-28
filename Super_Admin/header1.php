<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent caching issues
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Check login
if (!isset($_SESSION['uid'])) {
    header("Location: ../index.php");
    exit;
}

// Detect current page
$currentPage = basename($_SERVER['PHP_SELF']); // e.g., job_master.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tapasi ERP</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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

    /* Dropdown hover/active */
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
      <img src="tlogo.jpg" alt="Tapasi Solutions LLP">
    </div>  

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <!-- Dashboard -->
        <li class="nav-item">
          <a class="nav-link <?= $currentPage === 'dashboard_md.php' ? 'active' : '' ?>" href="dashboard_md.php">
            <i class="fa fa-home"></i> Dashboard
          </a>
        </li>

        <!-- Master -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= in_array($currentPage, ['job_master.php','client_master.php','employee_master.php','user_role_master.php']) ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown">
            <i class="fa fa-users"></i> Master
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?= $currentPage==='job_master.php'?'active':'' ?>" href="job_master.php">Job Master</a></li>
            <li><a class="dropdown-item <?= $currentPage==='client_master.php'?'active':'' ?>" href="client_master.php">Client Master</a></li>
            <!--li><a class="dropdown-item <?= $currentPage==='employee_master.php'?'active':'' ?>" href="employee_master.php">Executive Master</a></li-->
            <li><a class="dropdown-item <?= $currentPage==='user_role_master.php'?'active':'' ?>" href="user_role_master.php">User Role Master</a></li>
          </ul>
        
 <!-- COMPANY -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= in_array($currentPage, ['company_details.php']) ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown">
            <i class="fa fa-box"></i> Company
          </a>
          <ul class="dropdown-menu">
           <li><a class="dropdown-item <?= $currentPage==='company_details.php'?'active':'' ?>" href="company_details.php">Company Details</a></li> 
          </ul>
        </li>        
 <!-- COMPANY -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= in_array($currentPage, ['list_invoice.php']) ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown">
            <i class="fa fa-box"></i> Invoice
          </a>
          <ul class="dropdown-menu">
           <li><a class="dropdown-item <?= $currentPage==='list_invoice.php'?'active':'' ?>" href="list_invoice.php">Invoice</a></li> 
          </ul>
        </li>        
       
      <!-- Profile -->
      <ul class="navbar-nav ms-auto">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
            <i class="fa fa-user-circle"></i> <?= htmlspecialchars($_SESSION['username']); ?>
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
