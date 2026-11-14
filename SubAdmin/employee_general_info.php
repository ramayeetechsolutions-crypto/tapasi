<?php
// -------------------------------------------------------------
// FILE: employee_general_info.php
// PURPOSE: Displays ONLY General/Personal Information (UI Enhanced)
// -------------------------------------------------------------

require 'db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// --- DELETE EMPLOYEE LOGIC ---
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    
    // Prepare delete statement
    $stmt = $mysqli->prepare("DELETE FROM employee_master WHERE employee_id = ?");
    $stmt->bind_param("i", $delete_id);
    
    if ($stmt->execute()) {
        $delete_message = "Employee deleted successfully!";
        $delete_type = "success";
    } else {
        $delete_message = "Error deleting employee: " . $mysqli->error;
        $delete_type = "danger";
    }
    $stmt->close();
    
    // Redirect to avoid resubmission on refresh
    header("Location: employee_general_info.php?message=" . urlencode($delete_message) . "&type=" . $delete_type);
    exit();
}

// --- EXPORT LOGIC (GENERAL INFO ONLY) ---
if (isset($_GET['export'])) {
    error_reporting(0); 
    ini_set('display_errors', 0);
    if (ob_get_level()) { ob_end_clean(); }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="general_info_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Header - General Columns Only
    fputcsv($output, array('Employee ID', 'Name', 'Phone', 'Secondary Phone', 'Email', 'DOB', 'Gender', 'Blood Group', 'Father/Spouse', 'Address', 'Aadhar No'), ",", "\"", "\\");
    
    $exp_query = "SELECT * FROM employee_master ORDER BY employee_id DESC";
    $exp_run = $mysqli->query($exp_query);
    
    while ($row = $exp_run->fetch_assoc()) {
        $dob = ($row['date_of_birth'] == '0000-00-00' || empty($row['date_of_birth'])) ? '' : date('d-m-Y', strtotime($row['date_of_birth']));
        $phone = "\t" . $row['phone'];
        $sec_phone = "\t" . $row['secondary_phone'];
        $aadhar = "\t" . $row['aadhar_no'];

        fputcsv($output, array(
            $row['employee_username'], 
            $row['employee_name'], 
            $phone,
            $sec_phone,
            $row['email'], 
            $dob,
            $row['gender'],
            $row['blood_group'],
            $row['spouse_father_name'],
            $row['address'],
            $aadhar
        ), ",", "\"", "\\");
    }
    fclose($output);
    exit();
}

$pageTitle = "General Employee Info";
$currentPage = "employee_master.php";
include 'header.php'; 

// --- PAGINATION ---
$results_per_page = 10; 
$page = (isset($_GET['page']) && is_numeric($_GET['page'])) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $results_per_page;

$count_query = "SELECT COUNT(*) AS total FROM employee_master";
$count_result = $mysqli->query($count_query);
$count_row = $count_result ? $count_result->fetch_assoc() : ['total' => 0];
$total_matching = (int)($count_row['total'] ?? 0);
$total_pages = ceil($total_matching / $results_per_page);
?>

<style>
    .main-content {
        margin-top: 80px;
        padding-bottom: 50px;
    }
    .header-bar {
        background: transparent;
        padding: 0 0 16px 0;
    }
    .page-main-heading {
        color: #1e293b;
        font-weight: 700;
        font-size: 1.35rem;
        letter-spacing: -0.3px;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .back-btn-pill {
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 7px 16px;
        font-size: 0.84rem;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;
    }
    .back-btn-pill:hover {
        background: #e2e8f0;
        color: #1e293b;
        transform: translateX(-2px);
    }
    .export-btn-pill {
        border-radius: 8px;
        padding: 7px 16px;
        font-size: 0.84rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;
    }
    .export-btn-pill:hover {
        transform: translateY(-1px);
    }
    .emp-card-container {
        background: #ffffff;
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
        overflow: hidden;
    }
    .table-responsive {
        overflow-x: auto;
    }
    .table-senior {
        margin-bottom: 0;
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    .table-senior thead th {
        background-color: #f8fafc;
        color: #475569;
        font-weight: 700;
        font-size: 0.74rem;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        white-space: nowrap;
        border-bottom: 1px solid #e2e8f0;
        vertical-align: middle;
        text-align: center;
        padding: 12px 14px;
    }
    .table-senior tbody td {
        font-size: 0.86rem;
        vertical-align: middle;
        white-space: nowrap;
        color: #334155;
        padding: 12px 14px;
        text-align: center;
        border-bottom: 1px solid #f1f5f9;
        transition: background-color 0.15s ease;
    }
    .table-senior tbody tr:hover td {
        background-color: #f8fafc;
    }
    .text-left-custom {
        text-align: left !important;
    }
    .toggle-btn {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        width: 30px;
        height: 30px;
        padding: 0;
        color: #64748b;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
    }
    .toggle-btn:hover {
        background-color: #3b82f6;
        color: #ffffff;
        border-color: #3b82f6;
    }
    .toggle-btn.active {
        background-color: #3b82f6;
        color: #ffffff;
        border-color: #3b82f6;
    }
    .toggle-icon {
        font-size: 11px;
        transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .toggle-icon.rotated {
        transform: rotate(90deg);
    }
    .emp-code-pill {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-weight: 700;
        font-size: 0.8rem;
        color: #0284c7;
        background: #e0f2fe;
        padding: 3px 8px;
        border-radius: 6px;
        border: 1px solid #bae6fd;
    }
    .gender-badge {
        font-size: 0.74rem;
        font-weight: 600;
        padding: 3px 8px;
        border-radius: 20px;
    }
    .gender-badge.male {
        background: #e0f2fe;
        color: #0369a1;
    }
    .gender-badge.female {
        background: #fce7f3;
        color: #be185d;
    }
    .gender-badge.other {
        background: #f1f5f9;
        color: #475569;
    }
    .doc-badge-link {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        padding: 4px 10px;
        border-radius: 6px;
        color: #2563eb;
        text-decoration: none;
        font-size: 0.76rem;
        font-weight: 600;
        transition: all 0.2s ease;
    }
    .doc-badge-link:hover {
        background: #2563eb;
        color: #ffffff;
        border-color: #2563eb;
    }
    .delete-action-btn {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        background: rgba(239, 68, 68, 0.08);
        border: 1px solid rgba(239, 68, 68, 0.25);
        color: #dc2626;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        transition: all 0.2s ease;
    }
    .delete-action-btn:hover {
        background: #dc2626;
        color: #ffffff;
        border-color: #dc2626;
        transform: scale(1.05);
    }
    .expanded-container {
        background: #f8fafc;
        border-left: 4px solid #3b82f6;
        padding: 22px 24px;
        text-align: left;
    }
    .detail-card-panel {
        background: #ffffff;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        padding: 18px 20px;
        height: 100%;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
    }
    .detail-heading {
        color: #0f172a;
        font-weight: 700;
        font-size: 0.88rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 10px;
        margin-bottom: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .detail-item {
        margin-bottom: 10px;
    }
    .detail-label-text {
        font-size: 0.72rem;
        color: #64748b;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.4px;
        margin-bottom: 2px;
    }
    .detail-value-text {
        font-size: 0.88rem;
        color: #1e293b;
        font-weight: 500;
        word-break: break-word;
    }
    .pagination-footer {
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        padding: 14px 20px;
    }
    .collapse.show + tr > td {
        border-top: none;
    }
</style>

<div class="container-fluid main-content px-lg-4 px-md-3 px-2">
    
    <!-- Toast / Alert Message -->
    <?php if (isset($_GET['message'])): 
        $msgType = htmlspecialchars($_GET['type'] ?? 'info');
        $msgText = htmlspecialchars($_GET['message']);
    ?>
        <div class="alert alert-<?= $msgType ?> alert-dismissible fade show shadow-sm border-0 mb-3" role="alert" style="border-radius: 10px;">
            <i class="fas <?= $msgType === 'success' ? 'fa-check-circle' : 'fa-info-circle' ?> me-2"></i>
            <?= $msgText ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Top Header Bar -->
    <div class="header-bar d-flex flex-wrap align-items-center justify-content-between gap-3 mb-2">
        <div>
            <a href="employee_master.php" class="back-btn-pill shadow-sm" title="Back to Employee Hub">
                <i class="fas fa-arrow-left"></i>
                <span>Back to List</span>
            </a>
        </div>
        <div class="text-center">
            <h4 class="page-main-heading">
                <i class="fas fa-id-card text-primary"></i>
                <span>General Employee Info</span>
                <span class="badge bg-light text-secondary border fs-7 fw-normal ms-2" style="font-size: 0.75rem;"><?= $total_matching ?> Total</span>
            </h4>
        </div>
        <div>
            <a href="?export=true" class="btn btn-outline-primary export-btn-pill shadow-sm" title="Export all general information as CSV">
                <i class="fas fa-file-csv fs-6"></i>
                <span>Export CSV</span>
            </a>
        </div>
    </div>

    <!-- Main Card & Data Table -->
    <div class="emp-card-container">
        <div class="table-responsive">
            <table class="table table-senior table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width: 45px;"></th> 
                        <th style="width: 50px;">#</th>
                        <th>Emp ID</th>
                        <th class="text-left-custom">Name</th>
                        <th>DOB</th>
                        <th>Gender</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Aadhar No</th>
                        <th>Aadhar Doc</th>
                        <th style="width: 90px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $query = "SELECT * FROM employee_master ORDER BY employee_id DESC LIMIT $offset, $results_per_page";
                    $run = $mysqli->query($query);
                    $i = $offset; 

                    if ($run && $run->num_rows > 0) {
                        while ($row = $run->fetch_assoc()) {
                            $i++;
                            $uniqueId = $row['employee_id'];
                            
                            // Data Prep (Preserving 100% of Original Data Processing)
                            $name       = htmlspecialchars($row['employee_name'] ?? '');
                            $emp_code   = htmlspecialchars($row['employee_username'] ?? '');
                            $email      = htmlspecialchars($row['email'] ?? '-');
                            $phone      = htmlspecialchars($row['phone'] ?? '-');
                            $sec_phone  = htmlspecialchars($row['secondary_phone'] ?? '-');
                            $dob        = ($row['date_of_birth'] && $row['date_of_birth'] != '0000-00-00') ? date('d-m-Y', strtotime($row['date_of_birth'])) : '-';
                            $mar_date   = ($row['marriage_date'] && $row['marriage_date'] != '0000-00-00') ? date('d-m-Y', strtotime($row['marriage_date'])) : '-';
                            $gender     = htmlspecialchars($row['gender'] ?? '-');
                            $blood      = htmlspecialchars($row['blood_group'] ?? '-');
                            $qual       = htmlspecialchars($row['qualification'] ?? '-');
                            $father     = htmlspecialchars($row['spouse_father_name'] ?? '-');
                            $temp_addr  = htmlspecialchars($row['address'] ?? '-');
                            $perm_addr  = htmlspecialchars($row['permanent_address'] ?? '-');
                            $aadhar_no  = htmlspecialchars($row['aadhar_no'] ?? '-');
                            $aadharImgPath = $row['aadhar_image_path'] ?? '';
                            $ppImgPath  = $row['passport_photo'] ?? '';

                            // Gender Badge Styling
                            $genderClass = 'other';
                            if (strtolower($gender) === 'male' || strtolower($gender) === 'm') $genderClass = 'male';
                            elseif (strtolower($gender) === 'female' || strtolower($gender) === 'f') $genderClass = 'female';
                    ?>
                            <tr>
                                <td>
                                    <button class="toggle-btn" type="button" data-bs-toggle="collapse" data-bs-target="#details_<?= $uniqueId ?>" aria-expanded="false" aria-controls="details_<?= $uniqueId ?>" title="Click to view details">
                                        <i class="fas fa-chevron-right toggle-icon"></i>
                                    </button>
                                </td>
                                <td class="text-muted fw-semibold" style="font-size: 0.8rem;"><?= $i ?></td>
                                <td>
                                    <span class="emp-code-pill"><?= $emp_code ?></span>
                                </td>
                                <td class="text-left-custom fw-bold text-dark">
                                    <?= $name ?>
                                </td>
                                <td class="text-muted"><?= $dob ?></td>
                                <td>
                                    <?php if ($gender !== '-'): ?>
                                        <span class="gender-badge <?= $genderClass ?>"><?= $gender ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="font-monospace text-secondary" style="font-size: 0.84rem;"><?= $phone ?></span>
                                </td>
                                <td>
                                    <span class="text-secondary" style="font-size: 0.84rem;"><?= $email ?></span>
                                </td>
                                <td>
                                    <span class="font-monospace text-dark" style="font-size: 0.84rem;"><?= $aadhar_no ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($aadharImgPath)): ?>
                                        <a href="<?= $aadharImgPath ?>" target="_blank" class="doc-badge-link" title="Open Aadhar Document">
                                            <i class="fas fa-file-pdf text-danger"></i>
                                            <span>View Doc</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button onclick="confirmDelete(<?= $uniqueId ?>, '<?= addslashes($name) ?>')" class="delete-action-btn delete-btn" title="Delete Employee">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </td>
                            </tr>

                            <!-- EXPANDED ROW (GENERAL ONLY - PRESERVED 100%) -->
                            <tr class="collapse" id="details_<?= $uniqueId ?>" data-bs-parent="tbody">
                                <td colspan="11" class="p-0">
                                    <div class="expanded-container">
                                        <!-- Header within expanded card -->
                                        <div class="d-flex align-items-center mb-4 border-bottom pb-3">
                                            <div class="me-3">
                                                <?php if (!empty($ppImgPath)): ?>
                                                    <img src="<?= $ppImgPath ?>" class="rounded-circle shadow-sm" style="width: 56px; height: 56px; object-fit: cover; border: 2px solid #3b82f6;">
                                                <?php else: ?>
                                                    <div class="rounded-circle text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 56px; height: 56px; font-size: 20px; font-weight: 700; background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                                                        <?= strtoupper(substr($name, 0, 1)) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <h5 class="mb-1 text-dark fw-bold"><?= $name ?></h5>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="emp-code-pill" style="font-size: 0.72rem;">ID: <?= $emp_code ?></span>
                                                    <span class="text-muted small">General & Contact Profile</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row g-3">
                                            <!-- Column 1: Personal Details -->
                                            <div class="col-md-6 col-12">
                                                <div class="detail-card-panel">
                                                    <div class="detail-heading">
                                                        <i class="fas fa-user text-primary"></i>
                                                        <span>Personal Details</span>
                                                    </div>
                                                    <div class="row g-2">
                                                        <div class="col-sm-6 col-12 detail-item">
                                                            <div class="detail-label-text">Father / Spouse Name</div>
                                                            <div class="detail-value-text"><?= $father ?></div>
                                                        </div>
                                                        <div class="col-sm-6 col-12 detail-item">
                                                            <div class="detail-label-text">Blood Group</div>
                                                            <div class="detail-value-text">
                                                                <?php if ($blood !== '-'): ?>
                                                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fas fa-droplet me-1"></i><?= $blood ?></span>
                                                                <?php else: ?>
                                                                    -
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <div class="col-sm-6 col-12 detail-item">
                                                            <div class="detail-label-text">Qualification</div>
                                                            <div class="detail-value-text"><?= $qual ?></div>
                                                        </div>
                                                        <div class="col-sm-6 col-12 detail-item">
                                                            <div class="detail-label-text">Marriage Date</div>
                                                            <div class="detail-value-text"><?= $mar_date ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Column 2: Contact Details -->
                                            <div class="col-md-6 col-12">
                                                <div class="detail-card-panel">
                                                    <div class="detail-heading">
                                                        <i class="fas fa-address-book text-success"></i>
                                                        <span>Contact Information</span>
                                                    </div>
                                                    <div class="row g-2">
                                                        <div class="col-sm-6 col-12 detail-item">
                                                            <div class="detail-label-text">Primary Phone</div>
                                                            <div class="detail-value-text font-monospace"><i class="fas fa-phone text-muted me-1" style="font-size: 0.75rem;"></i><?= $phone ?></div>
                                                        </div>
                                                        <div class="col-sm-6 col-12 detail-item">
                                                            <div class="detail-label-text">Secondary Phone</div>
                                                            <div class="detail-value-text font-monospace"><?= $sec_phone ?></div>
                                                        </div>
                                                        <div class="col-12 detail-item">
                                                            <div class="detail-label-text">Email Address</div>
                                                            <div class="detail-value-text"><i class="fas fa-envelope text-muted me-1" style="font-size: 0.75rem;"></i><?= $email ?></div>
                                                        </div>
                                                        <div class="col-12 detail-item">
                                                            <div class="detail-label-text">Current Address</div>
                                                            <div class="detail-value-text small text-secondary"><?= $temp_addr ?></div>
                                                        </div>
                                                        <div class="col-12 detail-item">
                                                            <div class="detail-label-text">Permanent Address</div>
                                                            <div class="detail-value-text small text-secondary"><?= $perm_addr ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </td>
                            </tr>
                    <?php
                        }
                    } else {
                        echo "<tr><td colspan='11' class='text-center py-5 text-muted'><i class='fas fa-user-slash fa-2x mb-2 d-block text-secondary opacity-50'></i>No records found</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="text-muted small">
                Showing <span class="fw-semibold"><?= min($offset + 1, $total_matching) ?></span> to <span class="fw-semibold"><?= min($offset + $results_per_page, $total_matching) ?></span> of <span class="fw-semibold"><?= $total_matching ?></span> employees
            </div>
            <nav aria-label="Page navigation">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page - 1 ?>" style="border-radius: 6px 0 0 6px;">Previous</a>
                    </li>
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <li class="page-item <?= ($page == $p) ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page + 1 ?>" style="border-radius: 0 6px 6px 0;">Next</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Smooth toggle animation for chevron
    const collapseElements = document.querySelectorAll('.collapse');
    collapseElements.forEach(collapse => {
        collapse.addEventListener('show.bs.collapse', function() {
            const targetId = this.id;
            const button = document.querySelector(`[data-bs-target="#${targetId}"]`);
            if (button) {
                const icon = button.querySelector('.toggle-icon');
                button.classList.add('active');
                if (icon) icon.classList.add('rotated');
            }
        });
        
        collapse.addEventListener('hide.bs.collapse', function() {
            const targetId = this.id;
            const button = document.querySelector(`[data-bs-target="#${targetId}"]`);
            if (button) {
                const icon = button.querySelector('.toggle-icon');
                button.classList.remove('active');
                if (icon) icon.classList.remove('rotated');
            }
        });
    });
});

function confirmDelete(employeeId, employeeName) {
    if (window.erpPopConfirm) {
        window.erpPopConfirm({
            title: 'Delete Employee',
            message: 'Are you sure you want to permanently delete employee ' + (employeeName ? employeeName : '') + '? This action cannot be undone.',
            targetName: employeeName,
            type: 'danger',
            btnText: 'Yes, Delete',
            onConfirm: function() {
                window.location.href = 'employee_general_info.php?delete=' + employeeId;
            }
        });
    } else {
        if (confirm('Are you sure you want to delete this employee? This action cannot be undone.')) {
            window.location.href = 'employee_general_info.php?delete=' + employeeId;
        }
    }
}

// Auto dismiss standard alerts after 4.5s
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        var bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 4500);
</script>
