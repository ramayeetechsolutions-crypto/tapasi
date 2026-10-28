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

// 2. HANDLE UPDATES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_sa_details'])) {
    
    $emp_id = (int)$_POST['emp_id'];
    
    // Financials
    $new_salary = (float)$_POST['salary'];
    $tds_per    = (float)$_POST['tds_per'];
    
    // Statutory
    $elig_pf    = isset($_POST['is_eligible_pf']) ? 1 : 0;
    $elig_esi   = isset($_POST['is_eligible_esi']) ? 1 : 0;
    
    $uan_no     = $mysqli->real_escape_string($_POST['uan_no']);
    $epf_name   = $mysqli->real_escape_string($_POST['epf_name']);
    $esi_no     = $mysqli->real_escape_string($_POST['esi_no']);
    $esi_name   = $mysqli->real_escape_string($_POST['esi_name']);
    
    // Exit Details
    $dor_input  = $_POST['dor'];
    $dor        = !empty($dor_input) ? "'".$mysqli->real_escape_string($dor_input)."'" : "NULL";
    
    $notice     = $mysqli->real_escape_string($_POST['notice_period']);
    $remarks    = $mysqli->real_escape_string($_POST['remarks']);

    // --- HISTORY TRACKING ---
    // 1. Get current data to compare
    $get_curr = $mysqli->query("SELECT salary, salary_log FROM employee_master1 WHERE employee_id = $emp_id");
    $curr_row = $get_curr->fetch_assoc();
    $old_salary = (float)$curr_row['salary'];
    
    // Decode existing log (or create empty array if null)
    $history_list = json_decode($curr_row['salary_log'] ?? '[]', true);
    if (!is_array($history_list)) { $history_list = []; }

    // 2. If salary changed, handle history
    if ($old_salary != $new_salary) {
        
        // A. Existing JSON Logic (keeps the log in the master table)
        $history_list[] = [
            'date' => date('Y-m-d H:i:s'),
            'old'  => $old_salary,
            'new'  => $new_salary
        ];

        // B. NEW CODE: INSERT INTO TABLE employee_salary_history
        // Gets the ID of the logged-in admin. Defaults to 0 if session not set.
        $changed_by = isset($_SESSION['employee_id']) ? (int)$_SESSION['employee_id'] : 0; 

        $insert_history_sql = "INSERT INTO employee_salary_history 
                               (employee_id, old_salary, new_salary, tds_per, changed_by, changed_at) 
                               VALUES 
                               ('$emp_id', '$old_salary', '$new_salary', '$tds_per', '$changed_by', NOW())";
        
        // Execute the history insert (silently continue if it fails to avoid breaking the main update)
        if (!$mysqli->query($insert_history_sql)) {
             error_log("History Insert Error: " . $mysqli->error);
        }
        // -----------------------------------------------------------
    }
    
    // Encode back to JSON string for the master table
    $new_log_str = $mysqli->real_escape_string(json_encode($history_list));

    // 3. Update Master Table
    $sql = "UPDATE employee_master1 SET 
            salary = '$new_salary',
            salary_log = '$new_log_str',  
            tds_per = '$tds_per',
            is_eligible_pf = '$elig_pf',
            is_eligible_esi = '$elig_esi',
            uan_no = '$uan_no',
            epf_name = '$epf_name',
            esi_no = '$esi_no',
            esi_name = '$esi_name',
            dor = $dor,
            notice_period = '$notice',
            remarks = '$remarks'
            WHERE employee_id = $emp_id";

    if ($mysqli->query($sql)) {
        header("Location: employee_master-old.php?msg=updated"); exit;
    } else {
        die("Error updating record: " . $mysqli->error);
    }
}

// 3. FETCH DATA
$query = "SELECT * FROM employee_master1 ORDER BY employee_id DESC";
$result = $mysqli->query($query);

require 'header.php';
?>

<div class="container-fluid py-4" style="margin-top: 60px;">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark"><i class="fas fa-user-shield me-2"></i>Employee Master (Super Admin)</h3>
            <p class="text-muted mb-0">Manage Salary, Statutory Eligibility, and Exit Details.</p>
        </div>
        <div>
            <a href="employee_master.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i> Back</a>
        </div>
    </div>

    <?php if (isset($_GET['msg']) && $_GET['msg'] == 'updated'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i> Employee details updated successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow border-0">
        <div class="card-header bg-primary text-white py-3">
            <h5 class="mb-0 fw-bold"><i class="fas fa-lock me-2"></i>Admin Control Panel</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-bordered mb-0 align-middle text-center">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th class="text-start">Name</th>
                            <th>Role</th>
                            <th>Salary</th>
                            <th>PF/ESI</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($row['employee_username'] ?? '') ?></td>
                                <td class="text-start">
                                    <div class="fw-bold"><?= htmlspecialchars($row['employee_name'] ?? '') ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($row['email'] ?? '') ?></small>
                                </td>
                                <td>
                                    <?php if($row['user_role_id'] == 1) echo '<span class="badge bg-danger">Admin</span>';
                                          elseif($row['user_role_id'] == 2) echo '<span class="badge bg-warning text-dark">TL</span>';
                                          else echo '<span class="badge bg-info text-dark">Emp</span>'; ?>
                                </td>
                                <td>
                                    <?php if($row['salary'] > 0): ?>
                                        <span class="text-success fw-bold">₹<?= number_format($row['salary'], 2) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($row['is_eligible_pf']): ?><span class="badge bg-success" title="PF Eligible">PF</span><?php endif; ?>
                                    <?php if($row['is_eligible_esi']): ?><span class="badge bg-primary" title="ESI Eligible">ESI</span><?php endif; ?>
                                    <?php if(!$row['is_eligible_pf'] && !$row['is_eligible_esi']): ?><span class="text-muted small">NA</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($row['dor'])): ?>
                                        <span class="badge bg-secondary">Resigned</span>
                                        <div class="small text-danger"><?= $row['dor'] ?></div>
                                    <?php else: ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-primary btn-sm px-3 edit-btn" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#saEditModal"
                                        data-id="<?= $row['employee_id'] ?>"
                                        data-name="<?= htmlspecialchars($row['employee_name'] ?? '') ?>"
                                        data-salary="<?= $row['salary'] ?>"
                                        data-tds="<?= $row['tds_per'] ?>"
                                        data-pf="<?= $row['is_eligible_pf'] ?>"
                                        data-esi="<?= $row['is_eligible_esi'] ?>"
                                        data-uan="<?= htmlspecialchars($row['uan_no'] ?? '') ?>"
                                        data-epfname="<?= htmlspecialchars($row['epf_name'] ?? '') ?>"
                                        data-esino="<?= htmlspecialchars($row['esi_no'] ?? '') ?>"
                                        data-esiname="<?= htmlspecialchars($row['esi_name'] ?? '') ?>"
                                        data-dor="<?= $row['dor'] ?>"
                                        data-notice="<?= htmlspecialchars($row['notice_period'] ?? '') ?>"
                                        data-remarks="<?= htmlspecialchars($row['remarks'] ?? '') ?>"
                                    >
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7">No employees found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- EDIT MODAL -->
<div class="modal fade" id="saEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Update Admin Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form method="post">
                    <input type="hidden" name="emp_id" id="edit_emp_id">
                    
                    <h5 class="text-primary border-bottom pb-2 mb-3">Employee: <span id="display_name" class="fw-bold text-dark"></span></h5>

                    <!-- Financials -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Salary (CTC) ₹</label>
                            <input type="number" step="0.01" name="salary" id="edit_salary" class="form-control" placeholder="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">TDS Percentage (%)</label>
                            <input type="number" step="0.01" name="tds_per" id="edit_tds" class="form-control" placeholder="0">
                        </div>
                    </div>

                    <!-- Statutory -->
                    <h6 class="text-primary fw-bold bg-light p-2 border rounded">Statutory Details</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="is_eligible_pf" id="edit_pf_check" onchange="toggleStatutory()">
                                <label class="form-check-label fw-bold" for="edit_pf_check">Is Eligible for PF?</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="is_eligible_esi" id="edit_esi_check" onchange="toggleStatutory()">
                                <label class="form-check-label fw-bold" for="edit_esi_check">Is Eligible for ESI?</label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- PF Fields -->
                    <div class="row g-3 mb-3 p-3 border rounded bg-light" id="pf_container" style="display:none;">
                        <div class="col-12"><small class="text-success fw-bold">PF Information:</small></div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">UAN Number</label>
                            <input type="text" name="uan_no" id="edit_uan" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Name in EPF</label>
                            <input type="text" name="epf_name" id="edit_epfname" class="form-control">
                        </div>
                    </div>

                    <!-- ESI Fields -->
                    <div class="row g-3 mb-4 p-3 border rounded bg-light" id="esi_container" style="display:none;">
                        <div class="col-12"><small class="text-primary fw-bold">ESI Information:</small></div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">ESI Number</label>
                            <input type="text" name="esi_no" id="edit_esino" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">ESI Name</label>
                            <input type="text" name="esi_name" id="edit_esiname" class="form-control">
                        </div>
                    </div>

                    <!-- Exit Details -->
                    <h6 class="text-secondary fw-bold bg-light p-2 border rounded">Exit / Separation Details</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Date of Resignation (DOR)</label>
                            <input type="date" name="dor" id="edit_dor" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Notice Period</label>
                            <input type="text" name="notice_period" id="edit_notice" class="form-control" placeholder="e.g. 30 Days">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Remarks</label>
                            <textarea name="remarks" id="edit_remarks" class="form-control" rows="2" placeholder="Admin remarks..."></textarea>
                        </div>
                    </div>

                    <div class="d-grid">
                        <button type="submit" name="update_sa_details" class="btn btn-primary btn-lg fw-bold">
                            <i class="fas fa-save me-2"></i> Update Admin Details
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle Statutory Input Fields
function toggleStatutory() {
    var pfCheck = document.getElementById('edit_pf_check');
    var esiCheck = document.getElementById('edit_esi_check');
    
    var pfBox = document.getElementById('pf_container');
    var esiBox = document.getElementById('esi_container');

    pfBox.style.display = (pfCheck.checked) ? 'flex' : 'none';
    esiBox.style.display = (esiCheck.checked) ? 'flex' : 'none';
}

document.addEventListener('DOMContentLoaded', function () {
    const editButtons = document.querySelectorAll('.edit-btn');
    editButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            // Fill ID & Name
            document.getElementById('edit_emp_id').value = this.getAttribute('data-id');
            document.getElementById('display_name').textContent = this.getAttribute('data-name');
            
            // Financials
            document.getElementById('edit_salary').value = this.getAttribute('data-salary');
            document.getElementById('edit_tds').value = this.getAttribute('data-tds');
            
            // Checkboxes
            var isPf = (this.getAttribute('data-pf') == '1');
            var isEsi = (this.getAttribute('data-esi') == '1');
            
            document.getElementById('edit_pf_check').checked = isPf;
            document.getElementById('edit_esi_check').checked = isEsi;
            
            // Text Fields
            document.getElementById('edit_uan').value = this.getAttribute('data-uan');
            document.getElementById('edit_epfname').value = this.getAttribute('data-epfname');
            document.getElementById('edit_esino').value = this.getAttribute('data-esino');
            document.getElementById('edit_esiname').value = this.getAttribute('data-esiname');
            
            // Exit Details
            document.getElementById('edit_dor').value = this.getAttribute('data-dor');
            document.getElementById('edit_notice').value = this.getAttribute('data-notice');
            document.getElementById('edit_remarks').value = this.getAttribute('data-remarks');
            
            // Run visibility check
            toggleStatutory();
        });
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>