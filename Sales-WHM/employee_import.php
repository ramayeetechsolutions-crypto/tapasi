<?php
// -------------------------------------------------------------
// FILE: employee_import.php
// PURPOSE: Robust Import using Dynamic Header Mapping
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
                    $password_hash = md5('123456'); 
                    
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
                        $sql = "INSERT INTO employee_master1 (
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
<div class="container py-5" style="margin-top: 120px; position: relative; z-index: 1;">

    <?php if (!$libraryLoaded): ?>
    <div class="alert alert-danger shadow-sm">
        <h4><i class="fa fa-exclamation-triangle"></i> Library Missing</h4>
        <p>System cannot find <code>SimpleXLSX.php</code>.</p>
    </div>
    <?php endif; ?>

    <?php if ($msg != ""): ?>
    <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show" role="alert">
      <?= $msg ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="card shadow-lg text-center p-4 border-0 rounded-4">
        <h3 class="mb-3 text-primary fw-bold">Import Employees</h3>
        <!--<p class="text-muted">-->
        <!--    The system now automatically matches Excel headers to Database columns.<br>-->
        <!--    <small class="text-info">Ensure Excel headers match DB names (e.g., 'bank_name', 'phone')</small>-->
        <!--</p>-->
        
        <div class="mt-4">
            <?php if ($libraryLoaded): ?>
                
                <div class="row justify-content-center">
                    <div class="col-md-6">
                        <div class="card bg-light border p-3">
                            <h6 class="text-start">Upload Excel</h6>
                            <form method="post" enctype="multipart/form-data" class="d-flex gap-2">
                                <input type="file" name="import_file" class="form-control" accept=".xlsx" required>
                                <button type="submit" name="import_btn" class="btn btn-primary">Import</button>
                            </form>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <button class="btn btn-secondary px-5" disabled>Library Missing</button>
            <?php endif; ?>
            
            <div class="mt-4">
                <a href="imported_employees.php" class="btn btn-link fw-bold">
                    <i class="fa fa-list"></i> View Employee List
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>