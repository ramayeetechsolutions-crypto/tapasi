<?php
// -------------------------------------------------------------
// FILE: employee_general_info.php
// PURPOSE: Displays ONLY General/Personal Information
// -------------------------------------------------------------

require 'db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// --- DELETE EMPLOYEE LOGIC ---
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    
    // Optional: Add permission check here
    // if (!isset($_SESSION['admin']) || $_SESSION['admin'] != true) {
    //     die("Unauthorized access");
    // }
    
    // Optional: Add confirmation before delete
    if (!isset($_GET['confirm'])) {
        echo '<script>
            if(confirm("Are you sure you want to delete this employee? This action cannot be undone.")) {
                window.location.href = "employee_general_info.php?delete='.$delete_id.'&confirm=1";
            } else {
                window.location.href = "employee_general_info.php";
            }
        </script>';
        exit();
    }
    
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
    header('Content-Disposition: attachment; filename="general_info_'.date('Y-m-d').'.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Header - General Columns Only
    fputcsv($output, array('Employee ID', 'Name', 'Phone', 'Secondary Phone', 'Email', 'DOB', 'Gender', 'Blood Group', 'Father/Spouse', 'Address', 'Aadhar No'), ",", "\"", "\\");
    
    $exp_query = "SELECT * FROM employee_master ORDER BY employee_id DESC";
    $exp_run = $mysqli->query($exp_query);
    
    while($row = $exp_run->fetch_assoc()) {
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
include 'header.php'; 

// --- PAGINATION ---
$results_per_page = 10; 
$page = (isset($_GET['page']) && is_numeric($_GET['page'])) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $results_per_page;

$count_query = "SELECT COUNT(*) AS total FROM employee_master";
$count_result = $mysqli->query($count_query);
$count_row = $count_result->fetch_assoc();
$total_pages = ceil($count_row['total'] / $results_per_page);
?>

<style>
    .main-content { margin-top: 60px; }
    .table-responsive { overflow-x: auto; }
    .table thead th { background-color: #f8f9fa; color: #495057; font-weight: 600; font-size: 0.85rem; white-space: nowrap; border-bottom: 2px solid #dee2e6; vertical-align: middle; text-align: center; }
    .table tbody td { font-size: 0.9rem; vertical-align: middle; white-space: nowrap; color: #333; padding: 10px 15px; text-align: center; }
    .text-left-custom { text-align: left !important; }
    .toggle-btn { background: transparent; border: 1px solid #dee2e6; border-radius: 4px; padding: 2px 8px; color: #6c757d; cursor: pointer; transition: all 0.2s; }
    .toggle-btn:hover { background-color: #0d6efd; color: white; }
    .toggle-btn.active { background-color: #0d6efd; color: white; }
    .toggle-icon { font-size: 12px; transition: transform 0.3s ease; }
    .toggle-icon.rotated { transform: rotate(90deg); }
    .img-icon { width: 28px; height: 28px; object-fit: contain; }
    .expanded-row { background-color: #f8f9fa; border-left: 5px solid #0d6efd; text-align: left; padding: 20px; }
    .detail-section-title { color: #0d6efd; font-weight: 700; font-size: 0.95rem; text-transform: uppercase; border-bottom: 1px solid #e9ecef; padding-bottom: 8px; margin-bottom: 15px; margin-top: 10px; }
    .detail-label { font-size: 0.75rem; color: #6c757d; text-transform: uppercase; font-weight: 600; margin-bottom: 2px; }
    .detail-value { font-size: 0.95rem; color: #212529; font-weight: 500; word-wrap: break-word; white-space: normal; }
    .delete-btn { color: #dc3545; border: 1px solid #dc3545; background: transparent; }
    .delete-btn:hover { background: #dc3545; color: white; }
    .action-btns { white-space: nowrap; }
    .collapse.show + tr > td { border-top: none; }
</style>

<div class="container-fluid main-content py-4">
    <?php if (isset($_GET['message'])): ?>
        <div class="alert alert-<?php echo $_GET['type'] ?? 'info'; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_GET['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex justify-content-between mb-3">
                <a href="employee_master.php" class="btn btn-sm btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back to List
                </a>
          
        </div>
      <h4 class="text-secondary"><i class="fa fa-user"></i> General Employee Info</h4>
      <div>
          <a href="?export=true" class="btn btn-outline-primary shadow-sm me-2"><i class="fa fa-download"></i> Export CSV</a>
      </div>
    </div>

    <div class="card border-0 shadow-sm rounded-3 overflow-hidden">
      <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                <tr>
                    <th style="width: 50px;"></th> 
                    <th>#</th>
                    <th>Emp ID</th>
                    <th class="text-left-custom">Name</th>
                    <th>DOB</th>
                    <th>Gender</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Aadhar No</th>
                    <th>Aadhar Doc</th>
                    <th>Actions</th>
                </tr>
            </thead>
                <tbody>
                    <?php
                    $query = "SELECT * FROM employee_master ORDER BY employee_id DESC LIMIT $offset, $results_per_page";
                    $run = $mysqli->query($query);
                    $i = $offset; 

                    if($run && $run->num_rows > 0){
                        while($row = $run->fetch_assoc()){
                            $i++;
                            $uniqueId = $row['employee_id'];
                            
                            // Data Prep
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
                            ?>
                            
                            <tr>
                                <td>
                                    <button class="toggle-btn" type="button" data-bs-toggle="collapse" data-bs-target="#details_<?= $uniqueId ?>" aria-expanded="false" aria-controls="details_<?= $uniqueId ?>">
                                        <i class="fa fa-chevron-right toggle-icon"></i>
                                    </button>
                                </td>
                                <td class="text-muted"><?= $i ?></td>
                                <td class="fw-bold text-primary"><?= $emp_code ?></td>
                                <td class="text-left-custom fw-bold"><?= $name ?></td>
                                <td><?= $dob ?></td>
                                <td><?= $gender ?></td>
                                <td><?= $phone ?></td>
                                <td><?= $email ?></td>
                                <td><?= $aadhar_no ?></td>
                                <td>
                                    <?php if(!empty($aadharImgPath)): ?>
                                        <a href="<?= $aadharImgPath ?>" target="_blank"><img src="https://cdn-icons-png.flaticon.com/512/466/466146.png" class="img-icon"></a>
                                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                </td>
                                <td class="action-btns">
                                    <button onclick="confirmDelete(<?= $uniqueId ?>)" class="btn btn-sm delete-btn" title="Delete Employee">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </td>
                            </tr>

                            <!-- EXPANDED ROW (GENERAL ONLY) -->
                            <tr class="collapse" id="details_<?= $uniqueId ?>" data-bs-parent="tbody">
                                <td colspan="11" class="p-0">
                                    <div class="expanded-row">
                                        <!-- Header -->
                                        <div class="d-flex align-items-center mb-4 border-bottom pb-3">
                                            <div class="me-3">
                                                <?php if(!empty($ppImgPath)): ?>
                                                    <img src="<?= $ppImgPath ?>" class="rounded-circle" style="width: 60px; height: 60px; object-fit: cover; border: 2px solid #0d6efd;">
                                                <?php else: ?>
                                                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center" style="width: 60px; height: 60px; font-size: 24px;">
                                                        <?= strtoupper(substr($name,0,1)) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <h4 class="mb-0 text-dark"><?= $name ?></h4>
                                                <span class="text-muted small">ID: <strong><?= $emp_code ?></strong></span>
                                            </div>
                                            <div class="ms-auto">
                                                <button onclick="confirmDelete(<?= $uniqueId ?>)" class="btn delete-btn">
                                                    <i class="fa fa-trash me-1"></i> Delete Employee
                                                </button>
                                            </div>
                                        </div>

                                        <div class="row g-4">
                                            <!-- Personal -->
                                            <div class="col-md-6">
                                                <div class="p-3 bg-white rounded border h-100">
                                                    <div class="detail-section-title"><i class="fa fa-user me-1"></i> Personal</div>
                                                    <div class="mb-2"><div class="detail-label">Gender</div><div class="detail-value"><?= $gender ?></div></div>
                                                    <div class="mb-2"><div class="detail-label">Blood Group</div><div class="detail-value"><?= $blood ?></div></div>
                                                    <div class="mb-2"><div class="detail-label">Qualification</div><div class="detail-value"><?= $qual ?></div></div>
                                                    <div class="mb-2"><div class="detail-label">Father/Spouse</div><div class="detail-value"><?= $father ?></div></div>
                                                    <div class="mb-2"><div class="detail-label">Marriage Date</div><div class="detail-value"><?= $mar_date ?></div></div>
                                                </div>
                                            </div>
                                            <!-- Contact -->
                                            <div class="col-md-6">
                                                <div class="p-3 bg-white rounded border h-100">
                                                    <div class="detail-section-title"><i class="fa fa-address-book me-1"></i> Contact</div>
                                                    <div class="mb-2"><div class="detail-label">Email</div><div class="detail-value"><?= $email ?></div></div>
                                                    <div class="mb-2"><div class="detail-label">Phone 1</div><div class="detail-value"><?= $phone ?></div></div>
                                                    <div class="mb-2"><div class="detail-label">Phone 2</div><div class="detail-value"><?= $sec_phone ?></div></div>
                                                    <div class="mb-2"><div class="detail-label">Current Address</div><div class="detail-value small"><?= $temp_addr ?></div></div>
                                                    <div class="mb-2"><div class="detail-label">Permanent Address</div><div class="detail-value small"><?= $perm_addr ?></div></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo "<tr><td colspan='11' class='text-center py-5 text-muted'>No records found</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
      </div>

      <!-- Pagination Code -->
      <?php if ($total_pages > 1): ?>
      <div class="card-footer bg-light p-3">
          <nav aria-label="Page navigation">
            <ul class="pagination justify-content-end mb-0">
                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>"><a class="page-link" href="?page=<?= $page - 1 ?>">Previous</a></li>
                <?php for($p = 1; $p <= $total_pages; $p++): ?>
                    <li class="page-item <?= ($page == $p) ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a></li>
                <?php endfor; ?>
                <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>"><a class="page-link" href="?page=<?= $page + 1 ?>">Next</a></li>
            </ul>
          </nav>
      </div>
      <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize all collapse elements
    const collapseElements = document.querySelectorAll('.collapse');
    
    collapseElements.forEach(collapse => {
        // Listen for when a collapse is shown
        collapse.addEventListener('show.bs.collapse', function() {
            const targetId = this.id;
            const button = document.querySelector(`[data-bs-target="#${targetId}"]`);
            if (button) {
                const icon = button.querySelector('.toggle-icon');
                button.classList.add('active');
                icon.classList.add('rotated');
            }
        });
        
        // Listen for when a collapse is hidden
        collapse.addEventListener('hide.bs.collapse', function() {
            const targetId = this.id;
            const button = document.querySelector(`[data-bs-target="#${targetId}"]`);
            if (button) {
                const icon = button.querySelector('.toggle-icon');
                button.classList.remove('active');
                icon.classList.remove('rotated');
            }
        });
    });
    
    // Set initial state for any pre-opened collapse
    collapseElements.forEach(collapse => {
        if (collapse.classList.contains('show')) {
            const targetId = collapse.id;
            const button = document.querySelector(`[data-bs-target="#${targetId}"]`);
            if (button) {
                const icon = button.querySelector('.toggle-icon');
                button.classList.add('active');
                icon.classList.add('rotated');
            }
        }
    });
});

function confirmDelete(employeeId) {
    if (confirm('Are you sure you want to delete this employee? This action cannot be undone.')) {
        window.location.href = 'employee_general_info.php?delete=' + employeeId;
    }
}

// Close alert messages after 5 seconds
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        var bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);
</script>