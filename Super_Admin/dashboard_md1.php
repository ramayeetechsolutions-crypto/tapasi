<?php
require 'header.php'; // Includes navbar with brown theme and session checks
require 'db.php';     // Database connection if needed
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ERP Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body { background:#f4f6f9; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }

/* Card styling */
.card { border:none; border-radius:12px; box-shadow:0 2px 6px rgba(0,0,0,0.08); transition:transform 0.2s; }
.card:hover { transform:translateY(-4px); }
.card h6 { font-size:14px; font-weight:600; color:#666; }
.card h4 { font-size:22px; font-weight:bold; margin:8px 0; }

/* Filter bar */
.filter-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; }
.btn-outline-secondary { border-radius:6px; }

/* Card borders matching theme */
.border-primary { border-left: 4px solid #8B6450 !important; } /* brown */
.border-success { border-left: 4px solid #28a745 !important; }
.border-warning { border-left: 4px solid #ffc107 !important; }
.border-danger { border-left: 4px solid #dc3545 !important; }
.border-info { border-left: 4px solid #6c757d !important; }
.border-secondary { border-left: 4px solid #6c757d !important; }
.border-dark { border-left: 4px solid #343a40 !important; }

/* Text colors */
.text-primary { color:#8B6450 !important; }
</style>
</head>
<body>

<div class="container mt-4">
  <!-- Filter Bar -->
  <div class="filter-bar">
    <div>
      <button class="btn btn-outline-secondary"><i class="fa fa-calendar"></i> Jul 26, 2025 - Jul 26, 2025</button>
      <button class="btn btn-outline-secondary">All Locations</button>
    </div>
    <a href="#" class="text-primary">Clear Filters</a>
  </div>

  <!-- Dashboard Cards -->
  <div class="row g-3">
    <div class="col-md-3">
      <div class="card shadow-sm border-start border-primary border-3">
        <div class="card-body"><h6>TOTAL JOBS</h6><h4>120</h4></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm border-start border-success border-3">
        <div class="card-body"><h6>COMPLETED JOBS</h6><h4>98</h4></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm border-start border-warning border-3">
        <div class="card-body"><h6>PENDING JOBS</h6><h4>15</h4></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm border-start border-danger border-3">
        <div class="card-body"><h6>REJECTED JOBS</h6><h4>7</h4></div>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-2">
    <div class="col-md-3">
      <div class="card shadow-sm border-start border-info border-3">
        <div class="card-body"><h6>TEAM PRODUCTIVITY</h6><h4>87%</h4></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm border-start border-secondary border-3">
        <div class="card-body"><h6>CUSTOMER EMAILS</h6><h4>34</h4></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm border-start border-success border-3">
        <div class="card-body"><h6>REVENUE</h6><h4>₹1,20,000</h4></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm border-start border-dark border-3">
        <div class="card-body"><h6>ACTIVE CUSTOMERS</h6><h4>12</h4></div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
