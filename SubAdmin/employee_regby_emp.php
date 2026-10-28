<?php
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php'; 
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. CHECK LOGIN
if (!isset($_SESSION['username'])) {
    header("Location: ../index.php"); exit;
}

$currentUser = $_SESSION['username'];
$msg = "";
$error = "";

// 2. HANDLE FORM SUBMISSION (UPDATE PROFILE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    
    // -- A. Sanitize Text Inputs --
    $emp_name       = $mysqli->real_escape_string($_POST['employee_name'] ?? '');
    
    $phone1         = $mysqli->real_escape_string($_POST['phone'] ?? '');
    $phone2         = $mysqli->real_escape_string($_POST['secondary_phone'] ?? '');
    
    // New Email Fields
    $email          = $mysqli->real_escape_string($_POST['email'] ?? '');
    $email_2        = $mysqli->real_escape_string($_POST['email_2'] ?? '');
    
    $temp_addr      = $mysqli->real_escape_string($_POST['address'] ?? ''); 
    $perm_addr      = $mysqli->real_escape_string($_POST['permanent_address'] ?? '');
    
    $gender         = $mysqli->real_escape_string($_POST['gender'] ?? '');
    $blood_group    = $mysqli->real_escape_string($_POST['blood_group'] ?? '');
    $qualification  = $mysqli->real_escape_string($_POST['qualification'] ?? '');
    
    // New Family & Marital Fields
    $father_name    = $mysqli->real_escape_string($_POST['father_name'] ?? '');
    $mother_name    = $mysqli->real_escape_string($_POST['mother_name'] ?? '');
    $marital_status = $mysqli->real_escape_string($_POST['marital_status'] ?? '');
    $spouse_name    = $mysqli->real_escape_string($_POST['spouse_name'] ?? '');
    $child_1        = $mysqli->real_escape_string($_POST['child_1'] ?? '');
    $child_2        = $mysqli->real_escape_string($_POST['child_2'] ?? '');
    
    $dom_input      = $_POST['marriage_date'] ?? '';
    $dom            = !empty($dom_input) ? "'".$mysqli->real_escape_string($dom_input)."'" : "NULL";

    // KYC
    $aadhar_no      = $mysqli->real_escape_string($_POST['aadhar_no'] ?? '');
    $aadhar_name    = $mysqli->real_escape_string($_POST['employee_name_aadhar'] ?? ''); 
    $pan_no         = $mysqli->real_escape_string($_POST['pan_no'] ?? '');
    $pan_name       = $mysqli->real_escape_string($_POST['employee_name_pan'] ?? '');

    // Bank
    $bank_name      = $mysqli->real_escape_string($_POST['bank_name'] ?? '');
    $branch         = $mysqli->real_escape_string($_POST['branch_name'] ?? '');
    $acc_type       = $mysqli->real_escape_string($_POST['account_type'] ?? '');
    $acc_no         = $mysqli->real_escape_string($_POST['account_no'] ?? '');
    $ifsc           = $mysqli->real_escape_string($_POST['ifsc_code'] ?? '');

    // Confirmation (Checkbox)
    $emp_confirm    = isset($_POST['emp_confirm']) ? 1 : 0;

    // -- B. Handle File Uploads --
    $uploadDir = "uploads/";
    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

    function handleUpload($fileInput, $targetDir, $prefix, $mysqli, $currentUsername) {
        if (isset($_FILES[$fileInput]) && $_FILES[$fileInput]['error'] === 0) {
            $ext = pathinfo($_FILES[$fileInput]['name'], PATHINFO_EXTENSION);
            $fileName = $prefix . "_" . $currentUsername . "_" . time() . "." . $ext;
            $targetPath = $targetDir . $fileName;
            if (move_uploaded_file($_FILES[$fileInput]['tmp_name'], $targetPath)) {
                return $targetPath;
            }
        }
        return false;
    }

    $passport_path = handleUpload('passport_photo', $uploadDir, 'PP', $mysqli, $currentUser);
    $aadhar_path   = handleUpload('aadhar_image', $uploadDir, 'AADHAR', $mysqli, $currentUser);
    $pan_path      = handleUpload('pan_image', $uploadDir, 'PAN', $mysqli, $currentUser);

    // Build SQL Dynamic Update (Included new DB columns)
    $sql = "UPDATE employee_master SET 
            employee_name = '$emp_name',
            phone = '$phone1', 
            secondary_phone = '$phone2',
            email = '$email',
            email_2 = '$email_2',
            father_name = '$father_name',
            mother_name = '$mother_name',
            marital_status = '$marital_status',
            spouse_name = '$spouse_name',
            child_1 = '$child_1',
            child_2 = '$child_2',
            address = '$temp_addr',
            permanent_address = '$perm_addr',
            gender = '$gender',
            blood_group = '$blood_group',
            qualification = '$qualification',
            marriage_date = $dom,
            
            aadhar_no = '$aadhar_no',
            employee_name_aadhar = '$aadhar_name',
            pan_no = '$pan_no',
            employee_name_pan = '$pan_name',
            
            bank_name = '$bank_name',
            branch_name = '$branch',
            account_type = '$acc_type',
            account_no = '$acc_no',
            ifsc_code = '$ifsc',
            
            emp_confirm = '$emp_confirm'";

    // Append File Paths only if new files were uploaded
    if ($passport_path) { $sql .= ", passport_photo = '$passport_path'"; }
    if ($aadhar_path)   { $sql .= ", aadhar_image_path = '$aadhar_path'"; }
    if ($pan_path)      { $sql .= ", pan_image_path = '$pan_path'"; }

    $sql .= " WHERE employee_username = '$currentUser'";

    if ($mysqli->query($sql)) {
        $msg = "Profile updated successfully!";
    } else {
        $error = "Error updating profile: " . $mysqli->error;
    }
}

// 3. FETCH EXISTING DATA
// $query = "SELECT * FROM employee_master WHERE employee_username = '$currentUser'";
// $result = $mysqli->query($query);
// if ($result->num_rows > 0) {
//     $emprow = $result->fetch_assoc();
// } else {
//     die("Employee record not found in database for user: " . htmlspecialchars($currentUser));
// }

// require 'header.php';
// 3. FETCH EXISTING DATA
$query = "SELECT * FROM employee_master WHERE employee_username = '$currentUser'";
$result = $mysqli->query($query);

$employeeFound = false;

if ($result && $result->num_rows > 0) {
    $emprow = $result->fetch_assoc();
    $employeeFound = true;
}

require 'header.php';
?>
<?php
if (!$employeeFound) {
?>

<div class="container-fluid py-5" style="margin-top:50px;">
    <div class="row">
        <div class="col-12">

            <h2>
                <i class="fa fa-user"></i>
                My Employee Details
            </h2>

            <div class="card mt-3">
                <div class="card-body text-center" style="padding:80px;">

                    <i class="fa fa-user fa-4x text-secondary mb-3"></i>

                    <h3>No employee data found</h3>

                    <p class="text-muted">
                        Please contact administrator.
                    </p>

                </div>
            </div>

        </div>
    </div>
</div>

<?php
exit;
}
?>
<div class="container-fluid py-5" style="margin-top: 50px;">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="fw-bold text-dark"><i class="fas fa-user-edit me-2"></i>Employee Self-Registration</h2>
                    <p class="text-muted">Complete your profile details for verification.</p>
                </div>
                <div>
                    <?php if(isset($emprow['emp_confirm']) && $emprow['emp_confirm'] == 1): ?>
                        <span class="badge bg-success fs-6 px-3 py-2"><i class="fas fa-check-circle me-2"></i> Submitted by You</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark fs-6 px-3 py-2"><i class="fas fa-pen me-2"></i> Pending Submission</span>
                    <?php endif; ?>

                    <?php if(isset($emprow['tl_verified']) && $emprow['tl_verified'] == 1): ?>
                        <span class="badge bg-primary fs-6 px-3 py-2 ms-2"><i class="fas fa-check-double me-2"></i> Verified by TL</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($msg): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i> <?= $msg ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i> <?= $error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
                
                <!-- SECTION 1: PASSPORT PHOTO & BASIC INFO -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-secondary text-white fw-bold">
                        <i class="fas fa-camera me-2"></i> 1. Photo & Identity
                    </div>
                    <div class="card-body">
                        <div class="row align-items-center">
                            <!-- Passport Photo Upload & Preview -->
                            <div class="col-md-3 text-center mb-3 mb-md-0">
                                <div class="border rounded p-2 d-inline-block bg-light">
                                    <?php if(!empty($emprow['passport_photo'])): ?>
                                        <img id="preview_pp" src="<?= $emprow['passport_photo'] ?>" alt="Profile" class="img-fluid" style="width: 150px; height: 180px; object-fit: cover;">
                                    <?php else: ?>
                                        <img id="preview_pp" src="#" alt="Preview" class="img-fluid" style="width: 150px; height: 180px; object-fit: cover; display:none;">
                                        <div id="no_photo_text" style="width: 150px; height: 180px; display:flex; align-items:center; justify-content:center; color:#ccc; border:1px dashed #ccc;">No Photo</div>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-2">
                                    <label class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-upload me-1"></i> Upload Photo
                                        <input type="file" name="passport_photo" accept="image/*" onchange="previewImage(this, 'preview_pp', 'no_photo_text')" hidden>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Basic Info -->
                            <div class="col-md-9">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">ID</label> 
                                        <input type="text" class="form-control bg-light" value="<?php echo $emprow['employee_username'] ?>" readonly>
                                        <div class="form-text">Cannot be changed.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Full Name</label>
                                        <input type="text" name="employee_name" class="form-control" value="<?php echo $emprow['employee_name']?>">
                                    </div>
                                    
                                    <!-- Added Email-1 and Email-2 -->
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Email-1</label>
                                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($emprow['email'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Email-2</label>
                                        <input type="email" name="email_2" class="form-control" value="<?= htmlspecialchars($emprow['email_2'] ?? '') ?>">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Gender</label>
                                        <select name="gender" class="form-select">
                                            <option value="">Select</option>
                                            <option value="Male" <?= ($emprow['gender'] ?? '') == 'Male' ? 'selected' : '' ?>>Male</option>
                                            <option value="Female" <?= ($emprow['gender'] ?? '') == 'Female' ? 'selected' : '' ?>>Female</option>
                                            <option value="Other" <?= ($emprow['gender'] ?? '') == 'Other' ? 'selected' : '' ?>>Other</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Blood Group</label>
                                        <input type="text" name="blood_group" class="form-control" value="<?= htmlspecialchars($emprow['blood_group'] ?? '') ?>" placeholder="e.g. O+">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Qualification</label>
                                        <input type="text" name="qualification" class="form-control" value="<?= htmlspecialchars($emprow['qualification'] ?? '') ?>" placeholder="e.g. B.Tech">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: CONTACT & FAMILY -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-secondary text-white fw-bold">
                        <i class="fas fa-address-card me-2"></i> 2. Contact & Family Details
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Phone Contact 1 <span class="text-danger">*</span></label>
                                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($emprow['phone'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Phone Contact 2 (Alt)</label>
                                <input type="text" name="secondary_phone" class="form-control" value="<?= htmlspecialchars($emprow['secondary_phone'] ?? '') ?>">
                            </div>
                            
                            <!-- Modified Father & Mother Fields -->
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Father Name</label>
                                <input type="text" name="father_name" class="form-control" value="<?= htmlspecialchars($emprow['father_name'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Mother Name</label>
                                <input type="text" name="mother_name" class="form-control" value="<?= htmlspecialchars($emprow['mother_name'] ?? '') ?>">
                            </div>

                            <!-- Marital Status Dropdown -->
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Marital Status</label>
                                <select name="marital_status" id="marital_status" class="form-select" onchange="toggleFamilyFields()">
                                    <option value="">Select</option>
                                    <option value="Single" <?= ($emprow['marital_status'] ?? '') == 'Single' ? 'selected' : '' ?>>Single</option>
                                    <option value="Married" <?= ($emprow['marital_status'] ?? '') == 'Married' ? 'selected' : '' ?>>Married</option>
                                    <!-- <option value="Divorced" <?= ($emprow['marital_status'] ?? '') == 'Divorced' ? 'selected' : '' ?>>Divorced</option>
                                    <option value="Widowed" <?= ($emprow['marital_status'] ?? '') == 'Widowed' ? 'selected' : '' ?>>Widowed</option> -->
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Date of Marriage (DOM)</label>
                                <input type="date" name="marriage_date" class="form-control" value="<?= htmlspecialchars($emprow['marriage_date'] ?? '') ?>">
                            </div>

                            <!-- Hidden Dependent Section (Shown if Married) -->
                            <div class="col-12" id="family_details_section" style="display: <?= ($emprow['marital_status'] ?? '') == 'Married' ? 'block' : 'none' ?>;">
                                <div class="row g-3 p-3 border rounded bg-light">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Spouse Name</label>
                                        <input type="text" name="spouse_name" class="form-control" value="<?= htmlspecialchars($emprow['spouse_name'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Child-1 Name</label>
                                        <input type="text" name="child_1" class="form-control" value="<?= htmlspecialchars($emprow['child_1'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Child-2 Name</label>
                                        <input type="text" name="child_2" class="form-control" value="<?= htmlspecialchars($emprow['child_2'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6 mt-3">
                                <label class="form-label fw-bold">Temporary Address</label>
                                <textarea name="address" class="form-control" rows="3"><?= htmlspecialchars($emprow['address'] ?? '') ?></textarea>
                            </div>
                            <div class="col-md-6 mt-3">
                                <label class="form-label fw-bold">Permanent Address</label>
                                <textarea name="permanent_address" class="form-control" rows="3"><?= htmlspecialchars($emprow['permanent_address'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: KYC DOCUMENTS -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-secondary text-white fw-bold">
                        <i class="fas fa-id-card me-2"></i> 3. KYC Documents
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <!-- Aadhar -->
                            <div class="col-md-6 border-end">
                                <h6 class="text-primary fw-bold border-bottom pb-2">Aadhar Details</h6>
                                <div class="mb-3">
                                    <label class="form-label">Aadhar Number</label>
                                    <input type="text" name="aadhar_no" class="form-control" value="<?= htmlspecialchars($emprow['aadhar_no'] ?? '') ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Name as per Aadhar</label>
                                    <input type="text" name="employee_name_aadhar" class="form-control" value="<?= htmlspecialchars($emprow['employee_name_aadhar'] ?? '') ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Upload Aadhar Image</label>
                                    <input type="file" name="aadhar_image" class="form-control" accept="image/*" onchange="previewImage(this, 'preview_aadhar', null)">
                                    
                                    <div class="mt-2 border rounded bg-light text-center p-1" style="min-height:50px;">
                                        <?php if(!empty($emprow['aadhar_image_path'])): ?>
                                            <img id="preview_aadhar" src="<?= $emprow['aadhar_image_path'] ?>" class="img-fluid" style="max-height: 150px;">
                                        <?php else: ?>
                                            <img id="preview_aadhar" src="#" class="img-fluid" style="max-height: 150px; display:none;">
                                            <small class="text-muted" id="preview_aadhar_txt">No File</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- PAN -->
                            <div class="col-md-6">
                                <h6 class="text-primary fw-bold border-bottom pb-2">PAN Details</h6>
                                <div class="mb-3">
                                    <label class="form-label">PAN Number</label>
                                    <input type="text" name="pan_no" class="form-control" value="<?= htmlspecialchars($emprow['pan_no'] ?? '') ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Name on PAN</label>
                                    <input type="text" name="employee_name_pan" class="form-control" value="<?= htmlspecialchars($emprow['employee_name_pan'] ?? '') ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Upload PAN Image</label>
                                    <input type="file" name="pan_image" class="form-control" accept="image/*" onchange="previewImage(this, 'preview_pan', null)">
                                    
                                    <div class="mt-2 border rounded bg-light text-center p-1" style="min-height:50px;">
                                        <?php if(!empty($emprow['pan_image_path'])): ?>
                                            <img id="preview_pan" src="<?= $emprow['pan_image_path'] ?>" class="img-fluid" style="max-height: 150px;">
                                        <?php else: ?>
                                            <img id="preview_pan" src="#" class="img-fluid" style="max-height: 150px; display:none;">
                                            <small class="text-muted" id="preview_pan_txt">No File</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 4: BANK & STATUTORY -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-secondary text-white fw-bold">
                        <i class="fas fa-university me-2"></i> 4. Bank & Statutory Details
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Bank Name</label>
                                <input type="text" name="bank_name" class="form-control" value="<?= htmlspecialchars($emprow['bank_name'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Branch Name</label>
                                <input type="text" name="branch_name" class="form-control" value="<?= htmlspecialchars($emprow['branch_name'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Account Type</label>
                                <select name="account_type" class="form-select">
                                    <option value="Savings" <?= ($emprow['account_type'] ?? '') == 'Savings' ? 'selected' : '' ?>>Savings</option>
                                    <option value="Current" <?= ($emprow['account_type'] ?? '') == 'Current' ? 'selected' : '' ?>>Current</option>
                                    <option value="Salary" <?= ($emprow['account_type'] ?? '') == 'Salary' ? 'selected' : '' ?>>Salary Account</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Account Number</label>
                                <input type="text" name="account_no" class="form-control" value="<?= htmlspecialchars($emprow['account_no'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">IFSC Code</label>
                                <input type="text" name="ifsc_code" class="form-control" value="<?= htmlspecialchars($emprow['ifsc_code'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CONFIRMATION -->
                <div class="card shadow-sm border-0 mb-5">
                    <div class="card-body bg-light">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="emp_confirm" id="empConfirm" <?= (isset($emprow['emp_confirm']) && $emprow['emp_confirm'] == 1) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold text-dark" for="empConfirm">
                                I hereby declare that the details furnished above are true and correct to the best of my knowledge and belief.
                            </label>
                        </div>
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <button type="submit" name="update_profile" class="btn btn-primary btn-lg fw-bold px-5">
                                <i class="fas fa-save me-2"></i> Update Profile
                            </button>
                        </div>
                    </div>
                </div>

            </form>

        </div>
    </div>
</div>

<!-- JavaScript for Toggling Form and Image Preview -->
<script>
// Logic to show/hide family details based on marital status
function toggleFamilyFields() {
    var ms = document.getElementById("marital_status").value;
    var familySection = document.getElementById("family_details_section");
    
    if (ms === "Married") {
        familySection.style.display = "block";
    } else {
        familySection.style.display = "none";
    }
}

// Image Preview Logic
function previewImage(input, imgId, textId) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        
        reader.onload = function (e) {
            var img = document.getElementById(imgId);
            img.src = e.target.result;
            img.style.display = 'block';

            if(textId) {
                var txt = document.getElementById(textId);
                if(txt) txt.style.display = 'none';
            }
        }
        
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
<script>
// Modern Bootstrap Validation
(function () {
    'use strict'
    
    // Fetch all forms we want to apply custom Bootstrap validation to
    var forms = document.querySelectorAll('.needs-validation');

    // Loop over them and prevent submission if invalid
    Array.prototype.slice.call(forms).forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
                
                // Find the first field with an error and scroll to it smoothly
                var firstError = form.querySelector(':invalid');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstError.focus();
                }
            }
            // This class tells Bootstrap to show the red/green borders
            form.classList.add('was-validated');
        }, false);
    });
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>