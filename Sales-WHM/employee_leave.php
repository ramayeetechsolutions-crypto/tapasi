<?php
// -------------------------------------------------------------
// FILE: employee_leave.php
// PURPOSE: Employee interface to Apply Leave, Claim OT & View Status
// -------------------------------------------------------------

require 'db.php'; // Ensure your DB connection is here
session_start();

// [IMPORTANT] Replace '1' with your actual session variable for logged-in employee
$employee_id = $_SESSION['employee_id'] ?? 1; 

// --- 1. HANDLE FORM SUBMISSION (APPLY LEAVE / OT) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_request'])) {
    
    $type = $_POST['request_type'];
    $from = $_POST['from_date'];
    $to   = $_POST['to_date'];
    $reason = $mysqli->real_escape_string($_POST['reason']);
    
    // Logic: If Permission or OT Claim, usually single day, so To Date = From Date
    if($type == 'Permission' || $type == 'OT_Claim') {
        $to = $from;
    }

    $sql = "INSERT INTO leave_applications (employee_id, request_type, from_date, to_date, reason, status) 
            VALUES ('$employee_id', '$type', '$from', '$to', '$reason', 'Pending')";

    if ($mysqli->query($sql)) {
        $msg = "Application submitted successfully!";
        $msg_type = "success";
    } else {
        $msg = "Error: " . $mysqli->error;
        $msg_type = "danger";
    }
}

include 'header.php'; 
?>

<style>
    /* Status Badges */
    .status-Pending { background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
    .status-Approved { background-color: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
    .status-Rejected { background-color: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }
    
    /* Type Badges */
    .type-OT_Claim { color: #6f42c1; font-weight: bold; }
    .type-Leave { color: #0d6efd; font-weight: bold; }
    .type-Permission { color: #fd7e14; font-weight: bold; }
    
    .card-header-custom { background: #f8f9fa; border-bottom: 2px solid #e9ecef; }
</style>

<div class="container-fluid main-content py-4" style="margin-top: 60px;">

    <!-- Page Header & Buttons -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="text-secondary"><i class="fa fa-calendar-check-o"></i> My Leave & OT Manager</h4>
            <p class="text-muted small mb-0">Manage your leaves, permissions, and overtime claims.</p>
        </div>
        <div>
            <!-- Button to Open Modal -->
            <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#applyModal">
                <i class="fa fa-paper-plane"></i> New Request
            </button>
        </div>
    </div>

    <!-- Feedback Message -->
    <?php if(isset($msg)): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show" role="alert">
            <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- LEAVE HISTORY TABLE -->
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header card-header-custom py-3">
            <h6 class="mb-0 fw-bold text-primary">Request History</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3">Applied On</th>
                            <th>Request Type</th>
                            <th>Dates / Duration</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Admin Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $query = "SELECT * FROM leave_applications WHERE employee_id = '$employee_id' ORDER BY id DESC";
                        $result = $mysqli->query($query);

                        if ($result && $result->num_rows > 0) {
                            while ($row = $result->fetch_assoc()) {
                                $status = $row['status'];
                                $type_label = str_replace('_', ' ', $row['request_type']); // Remove underscore
                                
                                // Date Formatting
                                $apply_date = date('d-M-Y', strtotime($row['apply_date']));
                                $from_fmt = date('d-M-Y', strtotime($row['from_date']));
                                $to_fmt = date('d-M-Y', strtotime($row['to_date']));
                                
                                $date_display = ($row['from_date'] == $row['to_date']) ? $from_fmt : "$from_fmt <span class='text-muted small'>to</span> $to_fmt";
                                ?>
                                <tr>
                                    <td class="ps-3 text-muted small"><?= $apply_date ?></td>
                                    <td><span class="type-<?= $row['request_type'] ?>"><?= $type_label ?></span></td>
                                    <td class="fw-bold"><?= $date_display ?></td>
                                    <td class="small text-secondary"><?= htmlspecialchars($row['reason']) ?></td>
                                    <td><span class="badge status-<?= $status ?>"><?= $status ?></span></td>
                                    <td class="small text-danger"><?= htmlspecialchars($row['admin_remarks'] ?? '-') ?></td>
                                </tr>
                                <?php
                            }
                        } else {
                            echo "<tr><td colspan='6' class='text-center py-4 text-muted'>No leave history found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ======================================== -->
<!-- APPLY LEAVE / OT MODAL -->
<!-- ======================================== -->
<div class="modal fade" id="applyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-primary">Submit New Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    
                    <!-- 1. Select Type -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">Request Type</label>
                        <select name="request_type" id="reqType" class="form-select" required onchange="toggleDateInputs()">
                            <option value="" disabled selected>-- Select Type --</option>
                            <option value="Leave">Regular Leave (CL/SL)</option>
                            <option value="Permission">Permission (Short Duration)</option>
                            <option value="OT_Claim">OT Claim (I worked Extra/Holiday)</option>
                            <option value="Comp_Off_Leave">Comp-Off Leave (Use OT Credit)</option>
                        </select>
                        <div id="otHelp" class="form-text text-primary d-none small">
                            <i class="fa fa-info-circle"></i> Use 'OT Claim' to bank a day. Use 'Comp-Off Leave' to spend it.
                        </div>
                    </div>

                    <!-- 2. Date Selection -->
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">From Date</label>
                            <input type="date" name="from_date" class="form-control" required>
                        </div>
                        <div class="col-6" id="toDateGroup">
                            <label class="form-label">To Date</label>
                            <input type="date" name="to_date" class="form-control">
                        </div>
                    </div>

                    <!-- 3. Reason -->
                    <div class="mb-3 mt-3">
                        <label class="form-label">Reason / Details</label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="e.g., Sick leave, Personal work, or OT worked on Sunday Project X..." required></textarea>
                    </div>

                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="submit_request" class="btn btn-primary px-4">Submit for Approval</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Script to handle UI changes -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleDateInputs() {
        var type = document.getElementById('reqType').value;
        var toDateGroup = document.getElementById('toDateGroup');
        var otHelp = document.getElementById('otHelp');

        // Show/Hide OT Help Text
        if (type === 'OT_Claim' || type === 'Comp_Off_Leave') {
            otHelp.classList.remove('d-none');
        } else {
            otHelp.classList.add('d-none');
        }

        // Hide "To Date" for single-day events like Permission or OT Earning
        if (type === 'Permission' || type === 'OT_Claim') {
            toDateGroup.style.display = 'none';
            // Remove 'required' from hidden input to prevent form error
            toDateGroup.querySelector('input').removeAttribute('required');
        } else {
            toDateGroup.style.display = 'block';
            toDateGroup.querySelector('input').setAttribute('required', 'required');
        }
    }
</script>