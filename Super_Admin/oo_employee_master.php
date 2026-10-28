<?php
// -------------------------------------------------------------
// FILE: employee_import.php
// PURPOSE: Employee Management Navigation Hub with Import Functionality
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

// -------------------------------------------------------------
// 4. LOAD EXCEL LIBRARY
// -------------------------------------------------------------
$possible_paths = [
    '../simplexlsx-master/src/SimpleXLSX.php',
    '../../simplexlsx-master/src/SimpleXLSX.php',
    $_SERVER['DOCUMENT_ROOT'] . '/tapasi/simplexlsx-master/src/SimpleXLSX.php',
    'SimpleXLSX.php'
];

$libPath = "";
$libraryLoaded = false;
foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        $libPath = $path;
        break;
    }
}

if ($libPath != "") {
    require_once $libPath;
    if (class_exists('Shuchkin\SimpleXLSX')) {
        $libraryLoaded = true;
    }
}

// -------------------------------------------------------------
// 5. DYNAMIC IMPORT LOGIC
// -------------------------------------------------------------
$msg = "";
$msg_type = "";

if (isset($_POST['import_btn'])) {

    if (!$libraryLoaded) {
        $msg = "Error: Excel Library missing.";
        $msg_type = "danger";
    } elseif (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === 0) {
        
        $fileName = $_FILES['import_file']['tmp_name'];
        
        try {
            if ($xlsx = Shuchkin\SimpleXLSX::parse($fileName)) {
                
                $rows = $xlsx->rows();
                $count = 0;
                $errors = 0;
                $last_error = "";

                // STEP A: GET HEADERS FROM ROW 0
                // We convert headers to lowercase to match easily
                $headers = array_map('strtolower', $rows[0]);
                $headerMap = array_flip($headers); 
                // $headerMap['bank_name'] will now give us the correct column number (e.g., 56)

                // Helper Function to get data by Column Name
                $getData = function($row, $colName) use ($headerMap) {
                    $colName = strtolower($colName);
                    if (isset($headerMap[$colName])) {
                        $index = $headerMap[$colName];
                        return isset($row[$index]) ? trim($row[$index]) : "";
                    }
                    return ""; // Column not found in Excel
                };

                // STEP B: LOOP DATA
                foreach ($rows as $index => $r) {
                    if ($index === 0) continue; // Skip Header

                    // 1. EXTRACT DATA USING COLUMN NAMES (Matches your Database/Excel Headers)
                    // Note: These names inside quotes must match your EXCEL HEADER ROW exactly
                    $emp_username = $getData($r, 'employee_username'); 
                    $emp_name     = $getData($r, 'employee_name'); 
                    $email        = $getData($r, 'email');
                    $phone        = $getData($r, 'phone');
                    $sec_phone    = $getData($r, 'secondary_phone');
                    $gender       = $getData($r, 'gender');
                    $dob          = $getData($r, 'date_of_birth'); 
                    $joining_date = $getData($r, 'joining_date'); 
                    
                    // Work Details
                    $designation  = $getData($r, 'designation_id'); // If excel has text, you might need logic to convert to ID
                    $manager      = $getData($r, 'reporting_manager'); 
                    $shift        = $getData($r, 'shift'); 
                    
                    // Personal
                    $father       = $getData($r, 'spouse_father_name');
                    $blood        = $getData($r, 'blood_group');
                    $qual         = $getData($r, 'qualification');
                    $temp_addr    = $getData($r, 'address');
                    $perm_addr    = $getData($r, 'permanent_address');
                    $mar_date     = $getData($r, 'marriage_date');

                    // Identity
                    $aadhar       = $getData($r, 'aadhar_no');
                    $aadhar_name  = $getData($r, 'employee_name_aadhar');
                    $pan          = $getData($r, 'pan_no');
                    $pan_name     = $getData($r, 'employee_name_pan');
                    
                    // Bank & Statutory
                    $bank         = $getData($r, 'bank_name');
                    $branch       = $getData($r, 'branch_name');
                    $ifsc         = $getData($r, 'ifsc_code'); 
                    $acc_no       = $getData($r, 'account_no'); 
                    $esi          = $getData($r, 'esi_no');
                    $uan          = $getData($r, 'uan_no');
                    
                    // Status
                    $status       = $getData($r, 'status');
                    if($status == "") $status = "Active";

                    // Images (Filenames from Excel)
                    $raw_aadhar_img = $getData($r, 'aadhar_image_path'); 
                    $raw_pan_img    = $getData($r, 'pan_image_path'); 
                    $raw_pp_img     = $getData($r, 'passport_photo');

                    // 2. SANITIZATION & DEFAULTS
                    $password=$emp_username."@2025";
                    $password_hash = md5($password); 
                    
                    // Date Formatting Check
                    if(empty($dob)) $dob = NULL;
                    if(empty($joining_date)) $joining_date = date('Y-m-d');
                    if(empty($mar_date)) $mar_date = NULL;
                    
                    if(empty($manager)) $manager = "Admin";

                    // SQL Escaping
                    $emp_username = $mysqli->real_escape_string($emp_username);
                    $emp_name     = $mysqli->real_escape_string($emp_name);
                    $email        = $mysqli->real_escape_string($email);
                    $bank         = $mysqli->real_escape_string($bank);
                    $branch       = $mysqli->real_escape_string($branch);
                    // ... (Add escape for others if they might contain quotes)

                    $created_by   = "Import";
                    $created_date = date('Y-m-d H:i:s');
                    $designation_id = is_numeric($designation) ? $designation : 0; 

                    if($emp_name != "") {
                        // 3. INSERT QUERY (Expanded to include all your new fields)
                        $sql = "INSERT INTO employee_master (
                            employee_username, employee_name, email, phone, secondary_phone,
                            gender, date_of_birth, joining_date, marriage_date,
                            designation_id, reporting_manager, shift,
                            spouse_father_name, blood_group, qualification,
                            aadhar_no, employee_name_aadhar, pan_no, employee_name_pan,
                            bank_name, branch_name, ifsc_code, account_no, esi_no, uan_no,
                            address, permanent_address, status,
                            password_hash, created_by, created_date, 
                            aadhar_image_path, pan_image_path, passport_photo
                        ) VALUES (
                            '$emp_username', '$emp_name', '$email', '$phone', '$sec_phone',
                            '$gender', " . ($dob ? "'$dob'" : "NULL") . ", '$joining_date', " . ($mar_date ? "'$mar_date'" : "NULL") . ",
                            '$designation_id', '$manager', '$shift',
                            '$father', '$blood', '$qual',
                            '$aadhar', '$aadhar_name', '$pan', '$pan_name',
                            '$bank', '$branch', '$ifsc', '$acc_no', '$esi', '$uan',
                            '$temp_addr', '$perm_addr', '$status',
                            '$password_hash', '$created_by', '$created_date',
                            '$raw_aadhar_img', '$raw_pan_img', '$raw_pp_img'
                        )";

                        if($mysqli->query($sql)) {
                            $count++;
                        } else {
                            $errors++;
                            $last_error = $mysqli->error; 
                        }
                    }
                }

                if ($count > 0) {
                    $msg = "Success! Imported $count employees.";
                    $msg_type = "success";
                    if($errors > 0) $msg .= " ($errors failed)";
                } else {
                    $msg = "Failed. Database Error: " . $last_error;
                    $msg_type = "danger";
                }

            } else {
                $msg = "Excel Parse Error: " . Shuchkin\SimpleXLSX::parseError();
                $msg_type = "danger";
            }
        } catch (Exception $e) {
            $msg = "Critical Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $msg = "Please upload a valid .xlsx file.";
        $msg_type = "warning";
    }
}
?>

<!-- HTML CONTENT -->
<div class="container py-5" style="margin-top: 50px; position: relative; z-index: 1;">
    
    <!-- Messages Display -->
    <?php if ($msg != ""): ?>
    <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show" role="alert">
        <?= $msg ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Main Card -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <!-- Header Section -->
        <div class="card-header bg-gradient-primary text-white py-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-0 fw-bold"><i class="fas fa-users me-2"></i>Employee Management</h4>
                    <p class="mb-0 opacity-75 small">Central hub for all employee operations</p>
                </div>
                <div class="bg-white rounded-circle p-2 shadow">
                    <i class="fas fa-user-cog text-primary fa-lg"></i>
                </div>
            </div>
        </div>
        
        <!-- Body Section -->
        <div class="card-body p-4">
            <!-- Welcome Message -->
            <div class="text-center mb-4">
                <h5 class="text-dark mb-2">Employee Data Management Portal</h5>
                <p class="text-muted small">Select an option below to manage employee information</p>
            </div>
            
            <!-- Navigation Cards Grid - Compact Size -->
            <div class="row g-3">
                <!-- Import Employees Card -->
                <div class="col-md-4">
                    <div class="card h-100 border-0 shadow-sm hover-shadow transition-all">
                        <div class="card-body text-center p-3">
                            <div class="icon-wrapper bg-primary bg-opacity-10 rounded-circle p-3 d-inline-flex mb-2">
                                <i class="fas fa-file-import fa-2x text-primary"></i>
                            </div>
                            <h6 class="card-title mb-2">Import Employees</h6>
                            <p class="card-text text-muted small mb-3">
                                Bulk import employee data from Excel files
                            </p>
                            
                            <?php if (!$libraryLoaded): ?>
                                <div class="alert alert-danger py-1 px-2 small mt-2 mb-0">
                                    <i class="fa fa-exclamation-triangle"></i> Library not found
                                </div>
                            <?php else: ?>
                                <button type="button" class="btn btn-primary btn-sm px-3 py-1 rounded-pill" data-bs-toggle="modal" data-bs-target="#importModal">
                                    <i class="fas fa-upload me-1"></i>Import Excel
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Employee General Info Card -->
                <div class="col-md-4">
                    <div class="card h-100 border-0 shadow-sm hover-shadow transition-all">
                        <div class="card-body text-center p-3">
                            <div class="icon-wrapper bg-info bg-opacity-10 rounded-circle p-3 d-inline-flex mb-2">
                                <i class="fas fa-id-card fa-2x text-info"></i>
                            </div>
                            <h6 class="card-title mb-2">General Information</h6>
                            <p class="card-text text-muted small mb-3">
                                Personal details, contact info, and basic data
                            </p>
                            <a href="employee_general_info.php" class="btn btn-info btn-sm px-3 py-1 rounded-pill">
                                <i class="fas fa-external-link-alt me-1"></i>Access
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Employee Official Info Card -->
                <div class="col-md-4">
                    <div class="card h-100 border-0 shadow-sm hover-shadow transition-all">
                        <div class="card-body text-center p-3">
                            <div class="icon-wrapper bg-warning bg-opacity-10 rounded-circle p-3 d-inline-flex mb-2">
                                <i class="fas fa-briefcase fa-2x text-warning"></i>
                            </div>
                            <h6 class="card-title mb-2">Official Information</h6>
                            <p class="card-text text-muted small mb-3">
                                Work details, designation, and employment info
                            </p>
                            <a href="employee_official_info.php" class="btn btn-warning btn-sm px-3 py-1 rounded-pill">
                                <i class="fas fa-external-link-alt me-1"></i>Access
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Edit Employee Card -->
                <div class="col-md-4">
                    <div class="card h-100 border-0 shadow-sm hover-shadow transition-all">
                        <div class="card-body text-center p-3">
                            <div class="icon-wrapper bg-purple bg-opacity-10 rounded-circle p-3 d-inline-flex mb-2">
                                <i class="fas fa-edit fa-2x" style="color: #6f42c1;"></i>
                            </div>
                            <h6 class="card-title mb-2">Edit Employee</h6>
                            <p class="card-text text-muted small mb-3">
                                Update salary, allowances, and compensation
                            </p>
                            <a href="employee_master-old.php" class="btn btn-sm px-3 py-1 rounded-pill" style="background-color: #6f42c1; color: white;">
                                <i class="fas fa-external-link-alt me-1"></i>Access
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions Row - Compact -->
            <!--div class="row mt-4">
                <div class="col-12">
                    <div class="card border-0 bg-light py-2">
                        <div class="card-body p-3">
                            <h6 class="mb-2"><i class="fas fa-bolt me-2 text-primary"></i>Quick Actions</h6>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="employee_general_info.php?export=true" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                                    <i class="fas fa-file-export me-1"></i>Export General
                                </a>
                                <a href="employee_official_info.php?export=true" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                                    <i class="fas fa-file-export me-1"></i>Export Official
                                </a>
                                <button class="btn btn-outline-info btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#downloadTemplateModal">
                                    <i class="fas fa-download me-1"></i>Template
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div-->

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h6 class="modal-title mb-0" id="importModalLabel"><i class="fas fa-file-import me-2"></i>Import Employees from Excel</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <div class="row g-3">
                    <div class="col-md-8">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-3">
                                <h6 class="mb-3"><i class="fas fa-upload me-2"></i>Upload Excel File</h6>
                                <form method="post" enctype="multipart/form-data" id="importForm">
                                    <div class="mb-3">
                                        <label class="form-label small">Select Excel File (.xlsx)</label>
                                        <input type="file" name="import_file" class="form-control form-control-sm" accept=".xlsx" required>
                                        <div class="form-text small">Maximum file size: 10MB. Ensure file is in .xlsx format.</div>
                                    </div>
                                    <div class="alert alert-info py-2 px-3 small">
                                        <h6 class="mb-1"><i class="fas fa-info-circle me-1"></i>Important Instructions:</h6>
                                        <ul class="mb-0 small">
                                            <li>First row must contain column headers</li>
                                            <li>Headers should match database field names</li>
                                            <li>Use exact column names like 'employee_username'</li>
                                            <li>Date format: YYYY-MM-DD</li>
                                        </ul>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 bg-light">
                            <div class="card-body p-3">
                                <h6 class="small"><i class="fas fa-check-circle me-1 text-success"></i>Required Fields</h6>
                                <ul class="small mb-2">
                                    <li>employee_username</li>
                                    <li>employee_name</li>
                                    <li>email</li>
                                    <li>phone</li>
                                </ul>
                                
                                <hr class="my-2">
                                
                                <h6 class="small"><i class="fas fa-download me-1 text-primary"></i>Need a Template?</h6>
                                <p class="small mb-2">Download our pre-formatted Excel template.</p>
                                <button class="btn btn-outline-primary btn-sm w-100" data-bs-toggle="modal" data-bs-target="#downloadTemplateModal">
                                    <i class="fas fa-download me-1"></i>Download Template
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="import_btn" form="importForm" class="btn btn-primary btn-sm">
                    <i class="fas fa-upload me-1"></i>Start Import
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Download Template Modal -->
<div class="modal fade" id="downloadTemplateModal" tabindex="-1" aria-labelledby="downloadTemplateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-info text-white py-2">
                <h6 class="modal-title mb-0" id="downloadTemplateModalLabel"><i class="fas fa-download me-2"></i>Download Template</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <div class="text-center mb-3">
                    <i class="fas fa-file-excel text-success fa-3x mb-2"></i>
                    <h6 class="mb-1">Employee Import Template</h6>
                    <p class="text-muted small">Pre-formatted Excel template with all required columns.</p>
                </div>
                
                <div class="text-center mt-3">
                    <a href="templates/employee_import_template.xlsx" class="btn btn-success btn-sm px-4">
                        <i class="fas fa-download me-1"></i>Download
                    </a>
                    <p class="small text-muted mt-2">.xlsx format | ~25KB</p>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Custom CSS for hover effects -->
<style>
.hover-shadow {
    transition: all 0.3s ease;
}
.hover-shadow:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.08) !important;
}
.icon-wrapper {
    transition: all 0.3s ease;
}
.hover-shadow:hover .icon-wrapper {
    transform: scale(1.05);
}
.bg-gradient-primary {
    background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
}
.rounded-4 {
    border-radius: 15px !important;
}
.rounded-pill {
    border-radius: 50rem !important;
}
.card {
    font-size: 0.9rem;
}
.card-title {
    font-size: 0.95rem;
    font-weight: 600;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Show import modal if there was an error
    <?php if (isset($_POST['import_btn']) && $msg_type === 'danger'): ?>
        var importModal = new bootstrap.Modal(document.getElementById('importModal'));
        importModal.show();
    <?php endif; ?>
    
    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        var alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
});
</script>