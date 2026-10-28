<?php
// -------------------------------------------------------------
// FILE: employee_import.php
// PURPOSE: Employee Management Navigation Hub (Import functionality removed)
// -------------------------------------------------------------

// 1. ENABLE ERROR REPORTING
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2. CONNECT TO DATABASE
$db_paths = ['db.php', '../db.php'];
$db_connected = false;
foreach ($db_paths as $path) {
    if (file_exists($path)) {
        require $path;
        $db_connected = true;
        break;
    }
}
if (!$db_connected) {
    die("<div style='margin-top:100px; color:red; text-align:center;'>CRITICAL ERROR: db.php not found.</div>");
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 3. INCLUDE HEADER
$header_paths = ['header.php', '../header.php'];
foreach ($header_paths as $path) {
    if (file_exists($path)) {
        include $path;
        break;
    }
}
?>

<!-- HTML CONTENT -->
<div class="container py-5" style="margin-top: 120px; position: relative; z-index: 1;">
    
    <!-- Main Card -->
    <div class="card shadow-lg border-0 rounded-4 overflow-hidden">
        <!-- Header Section -->
        <div class="card-header bg-gradient-primary text-white py-4">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h2 class="mb-1 fw-bold"><i class="fas fa-users me-2"></i>Employee Management</h2>
                    <p class="mb-0 opacity-75">Central hub for all employee operations</p>
                </div>
                <div class="bg-white rounded-circle p-3 shadow">
                    <!-- <i class="fas fa-user-cog text-primary fa-2x"></i> -->
                     <i class="fas fa-user-cog fa-2x" style="color: #707981 !important;"></i>
                </div>
            </div>
        </div>
        
        <!-- Body Section -->
        <div class="card-body p-5">
            <!-- Welcome Message -->
            <div class="text-center mb-5">
                <h3 class="text-dark mb-3">Employee Data Management Portal</h3>
                <p class="text-muted">Select an option below to manage employee information</p>
            </div>
            
            <!-- Navigation Cards Grid -->
            <div class="row g-4">
                <!-- Employee General Info Card -->
                <div class="col-md-4">
                    <div class="card h-100 border-0 shadow-sm hover-shadow transition-all">
                        <div class="card-body text-center p-4">
                            <div class="icon-wrapper bg-info bg-opacity-10 rounded-circle p-4 d-inline-flex mb-3">
                                <i class="fas fa-id-card fa-3x text-info"></i>
                            </div>
                            <h4 class="card-title mb-3">General Information</h4>
                            <p class="card-text text-muted mb-4">
                                View and manage personal details, contact information, and basic employee data
                            </p>
                            <a href="employee_general_info.php" class="btn btn-info px-4 py-2 rounded-pill">
                                <i class="fas fa-external-link-alt me-2"></i>Access Module
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Employee Official Info Card -->
                <div class="col-md-4">
                    <div class="card h-100 border-0 shadow-sm hover-shadow transition-all">
                        <div class="card-body text-center p-4">
                            <div class="icon-wrapper bg-success bg-opacity-10 rounded-circle p-4 d-inline-flex mb-3">
                                <i class="fas fa-briefcase fa-3x text-success"></i>
                            </div>
                            <h4 class="card-title mb-3">Official Information</h4>
                            <p class="card-text text-muted mb-4">
                                Manage work details, designation, reporting structure, and employment information
                            </p>
                            <a href="employee_official_info.php" class="btn btn-success px-4 py-2 rounded-pill">
                                <i class="fas fa-external-link-alt me-2"></i>Access Module
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Edit Salary Card -->
                <div class="col-md-4">
                    <div class="card h-100 border-0 shadow-sm hover-shadow transition-all">
                        <div class="card-body text-center p-4">
                            <div class="icon-wrapper bg-warning bg-opacity-10 rounded-circle p-4 d-inline-flex mb-3">
                                <i class="fas fa-money-bill-wave fa-3x text-warning"></i>
                            </div>
                            <h4 class="card-title mb-3">Edit Employee</h4>
                            <p class="card-text text-muted mb-4">
                                Update and manage employee salary details, allowances, and compensation structure
                            </p>
                            <a href="employee_master-old.php" class="btn btn-warning px-4 py-2 rounded-pill">
                                <i class="fas fa-external-link-alt me-2"></i>Access Module
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats Section -->
            <!--div class="row mt-5 pt-4">
                <div class="col-12">
                    <div class="card border-0 bg-light">
                        <div class="card-body p-4">
                            <h5 class="mb-3"><i class="fas fa-chart-line me-2 text-primary"></i>Quick Actions</h5>
                            <div class="d-flex flex-wrap gap-3">
                                <a href="#" class="btn btn-outline-primary rounded-pill px-4">
                                    <i class="fas fa-search me-2"></i>Search Employee
                                </a>
                                <a href="#" class="btn btn-outline-secondary rounded-pill px-4">
                                    <i class="fas fa-file-export me-2"></i>Export Data
                                </a>
                                <a href="#" class="btn btn-outline-info rounded-pill px-4">
                                    <i class="fas fa-chart-bar me-2"></i>View Reports
                                </a>
                                <a href="#" class="btn btn-outline-success rounded-pill px-4">
                                    <i class="fas fa-user-plus me-2"></i>Add New Employee
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div-->
        </div>
        
        <!-- Footer Section -->
        <!--div class="card-footer bg-light py-3">
            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i> Last updated: <?php echo date('F j, Y'); ?>
                </small>
                <small class="text-muted">
                    <i class="fas fa-database me-1"></i> Employee Management System v2.0
                </small>
            </div>
        </div-->
    </div>
</div>

<!-- Custom CSS for hover effects -->
<style>
.hover-shadow {
    transition: all 0.3s ease;
}
.hover-shadow:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.1) !important;
}
.icon-wrapper {
    transition: all 0.3s ease;
}
.hover-shadow:hover .icon-wrapper {
    transform: scale(1.1);
}
.bg-gradient-primary {
    /* background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); */
    background-color: #707981 !important;
}
.rounded-4 {
    border-radius: 20px !important;
}
.rounded-pill {
    border-radius: 50rem !important;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>