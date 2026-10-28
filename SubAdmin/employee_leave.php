<?php
// -------------------------------------------------------------
// FILE: employee_ot.php
// PURPOSE: Employee interface to Apply OT & View Status
// -------------------------------------------------------------

require 'db.php'; // Ensure your DB connection is here
session_start();

// [IMPORTANT] Replace with your actual session variable for logged-in employee
$employee_username = $_SESSION['username'] ?? '';
$employee_id = $_SESSION['uid'] ?? 0;

// --- 1. HANDLE FORM SUBMISSION (APPLY OT/PERMISSION) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_ot_request'])) {
    
    $request_type = $mysqli->real_escape_string($_POST['request_type']); // New Dropdown Field
    $ot_date = $_POST['ot_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $total_hours = !empty($_POST['total_hours']) ? $_POST['total_hours'] : 0; // Manual entry now
    $article = !empty($_POST['article']) ? $_POST['article'] : '';
    $count = !empty($_POST['count']) ? $_POST['count'] : '0';
    $reason = $mysqli->real_escape_string($_POST['reason']);

    $employee_process = $mysqli->real_escape_string($_POST['process']); // Get from dropdown

    $sql = "INSERT INTO leave_applications (employee_username, request_type, from_date, to_date, start_time, end_time, total_hours, process, article, count, reason, status) 
            VALUES ('$employee_username', '$request_type', '$ot_date', '$ot_date', '$start_time', '$end_time', '$total_hours', '$employee_process', '$article', '$count', '$reason', 'Pending')";

    if ($mysqli->query($sql)) {
        $msg = "Request submitted successfully!";
        $msg_type = "success";
    } else {
        $msg = "Error: " . $mysqli->error;
        $msg_type = "danger";
    }
}

// Get employee details
$employee_details =[];
if ($employee_username) {
    // Get employee details from employee_master
    $emp_query = "SELECT 
                    em.employee_name, 
                    em.process, 
                    em.process1,
                    pm.process_name as process_label,
                    pm1.process_name as process1_label
                  FROM employee_master em
                  LEFT JOIN process_master pm ON em.process = pm.process_id
                  LEFT JOIN process_master pm1 ON em.process1 = pm1.process_id
                  WHERE em.employee_username = '$employee_username'";
    $emp_result = $mysqli->query($emp_query);
    if ($emp_result && $emp_result->num_rows > 0) {
        $employee_details = $emp_result->fetch_assoc();
    }
}

// Get all processes for the dropdown
$all_processes = [];
$process_query = "SELECT process_id, process_name FROM process_master"; 
$process_result = $mysqli->query($process_query);
if ($process_result && $process_result->num_rows > 0) {
    while ($p_row = $process_result->fetch_assoc()) {
        $all_processes[] = $p_row;
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
    .type-OT_Claim { 
        background-color: #6f42c1; 
        color: white;
        font-weight: bold;
        padding: 4px 8px;
        border-radius: 4px;
    }
    
    .type-Permission { 
        background-color: #0dcaf0; 
        color: #000;
        font-weight: bold;
        padding: 4px 8px;
        border-radius: 4px;
    }
    
    .type-Comp_Off_Leave { 
        background-color: #198754; 
        color: white;
        font-weight: bold;
        padding: 4px 8px;
        border-radius: 4px;
    }
    
    .card-header-custom { 
        /* background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); */
         background: #5e6b77 !important;
        color: white;
        border-bottom: none;
    }
    
    .time-display {
        font-family: 'Courier New', monospace;
        font-weight: bold;
        color: #333;
    }
    
    .hours-badge {
        background: linear-gradient(45deg, #FF9800, #FF5722);
        color: white;
        padding: 4px 10px;
        border-radius: 20px;
        font-weight: bold;
        font-size: 14px;
    }
    
    .process-badge {
        background: linear-gradient(45deg, #2196F3, #21CBF3);
        color: white;
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: bold;
    }
    
    .article-badge {
        background: linear-gradient(45deg, #4CAF50, #8BC34A);
        color: white;
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: bold;
    }
</style>

<div class="container-fluid main-content py-4" style="margin-top: 60px;">

    <!-- Page Header & Buttons -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="text-secondary"><i class="fa fa-clock-o"></i> My OT & Permissions</h4>
            <p class="text-muted small mb-0">Submit and track your requests. Manage your OT credits.</p>
        </div>
        <div class="d-flex gap-2">
            
            <!-- OT Credits Summary -->
            <div class="card border-0 shadow-sm bg-gradient-primary text-white" style="background: linear-gradient(45deg, #2196F3, #21CBF3);">
                <div class="card-body py-2 px-3">
                    <small class="d-block">Available OT Credits</small>
                    <h5 class="mb-0"><?php echo getOTCredits($mysqli, $employee_username); ?> hours</h5>
                </div>
            </div>
            
            <!-- Button to Open Modal -->
            <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#applyOTModal">
                <i class="fa fa-plus-circle"></i> New Request
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

    <!-- CLAIMS HISTORY TABLE -->
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header card-header-custom py-3">
            <h6 class="mb-0 fw-bold text-white">Request History</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3">Date</th>
                            <th>Type</th>
                            <th>Process</th>
                            <th>Article</th>
                            <th>Time & Duration</th>
                            <th>Count</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $query = "SELECT la.*, 
                                 pm.process_name as process_label
                          FROM leave_applications la
                          LEFT JOIN process_master pm ON la.process = pm.process_id
                          WHERE la.employee_username = '$employee_username' 
                          AND la.request_type IN ('OT_Claim', 'Comp_Off_Leave', 'Permission') 
                          ORDER BY la.from_date DESC";
                        $result = $mysqli->query($query);

                        if ($result && $result->num_rows > 0) {
                            while ($row = $result->fetch_assoc()) {
                                $status = $row['status'];
                                $type_label = str_replace('_', ' ', $row['request_type']);
                                $ot_date = date('d-M-Y', strtotime($row['from_date']));
                                
                                // Format time display
                                $time_display = '';
                                if (!empty($row['start_time']) && !empty($row['end_time'])) {
                                    $start_time = date('g:i A', strtotime($row['start_time']));
                                    $end_time = date('g:i A', strtotime($row['end_time']));
                                    $time_display = "<span class='time-display'>{$start_time} to {$end_time}</span>";
                                }
                                
                                // Get process name from process_master if available
                                $process_display = !empty($row['process_label']) ? $row['process_label'] : $row['process'];
                                ?>
                                <tr>
                                    <td class="ps-3 fw-bold"><?= $ot_date ?></td>
                                    <td><span class="type-<?= $row['request_type'] ?>"><?= $type_label ?></span></td>
                                    <td>
                                        <?php if (!empty($process_display)): ?>
                                            <span class="process-badge"><?= htmlspecialchars($process_display) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['article'])): ?>
                                            <span class="article-badge"><?= htmlspecialchars($row['article']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= $time_display ?>
                                        <?php if (!empty($row['total_hours']) && $row['total_hours'] > 0): ?>
                                            <div class="mt-1"><span class="hours-badge"><?= $row['total_hours'] ?> hrs</span></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold"><?= !empty($row['count']) ? number_format($row['count']) : '-' ?></td>
                                    <td class="small text-secondary"><?= htmlspecialchars($row['reason']) ?></td>
                                    <td><span class="badge status-<?= $status ?>"><?= $status ?></span></td>
                                    <td class="small text-danger"><?= htmlspecialchars($row['admin_remarks'] ?? '-') ?></td>
                                </tr>
                                <?php
                            }
                        } else {
                            echo "<tr><td colspan='9' class='text-center py-4 text-muted'>No claims or requests found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ======================================== -->
<!-- APPLY OT/PERMISSION MODAL -->
<!-- ======================================== -->
<div class="modal fade" id="applyOTModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <!-- <h5 class="modal-title fw-bold text-primary">Submit OT / Permission Request</h5> -->
                 <h5 class="modal-title fw-bold" style="color: #5e6b77;">Submit OT / Permission Request</h5>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="row g-3">
                        
                        <!-- Request Type Dropdown -->
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Request Type <span class="text-danger">*</span></label>
                            <select name="request_type" class="form-select" required>
                                <option value="">-- Select Type --</option>
                                <option value="OT_Claim">OT Claim</option>
                                <option value="Permission">Permission</option>
                            </select>
                        </div>
                    
                        <!-- Employee Info (Readonly) -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Employee</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($employee_details['employee_name'] ?? '') . ' (' . $employee_username . ')' ?>" readonly>
                        </div>
                        
                        <!-- Process Info (Readonly) -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Process (Auto-filled)</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($employee_details['process_label'] ?? $employee_details['process'] ?? 'N/A') ?>" readonly>
                            <small class="text-muted">Fetched from your profile</small>
                        </div>
                        
                        <!-- Request Date -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Date <span class="text-danger">*</span></label>
                            <input type="date" name="ot_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        
                        <!-- Article -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Article</label>
                            <input type="text" name="article" class="form-control" placeholder="e.g., Article number or name">
                        </div>
                        
                        <!-- Time Section -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Start Time <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="time" name="start_time" id="start_time" class="form-control" required>
                                <span class="input-group-text"><i class="fa fa-clock-o"></i></span>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">End Time <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="time" name="end_time" id="end_time" class="form-control" required>
                                <span class="input-group-text"><i class="fa fa-clock-o"></i></span>
                            </div>
                        </div>
                        
                        <!-- Manual Hours Entry -->
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Total Hours</label>
                            <div class="input-group">
                                <input type="number" name="total_hours" id="total_hours" class="form-control" step="0.01" min="0" placeholder="e.g., 2.5">
                                <span class="input-group-text">hrs</span>
                            </div>
                            <small class="text-muted">Enter manually if required for OT</small>
                        </div>
                        
                        <!-- Count -->
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Count</label>
                            <div class="input-group">
                                <input type="number" name="count" class="form-control" placeholder="e.g., 1000" min="0">
                                <span class="input-group-text">units</span>
                            </div>
                            <small class="text-muted">Optional: Production count</small>
                        </div>
                        
                        <!-- Current OT Credits -->
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Current OT Credits</label>
                            <div class="input-group">
                                <input type="text" class="form-control" value="<?= getOTCredits($mysqli, $employee_username) ?>" readonly>
                                <span class="input-group-text">hrs</span>
                            </div>
                        </div>
                        
                        <!-- Reason -->
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Reason / Work Details <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="3" placeholder="Please specify your reason..." required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="submit_ot_request" class="btn btn-primary px-4">
                        <i class="fa fa-paper-plane"></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    window.onload = function() {
        // Set max date to today
        const today = new Date().toISOString().split('T')[0];
        const dateInput = document.querySelector('input[name="ot_date"]');
        if(dateInput) { dateInput.setAttribute('max', today); }
    }

    // Function to calculate time difference
    function calculateTotalHours(startInputId, endInputId, totalInputId) {
        const startTime = document.getElementById(startInputId).value;
        const endTime = document.getElementById(endInputId).value;

        if (startTime && endTime) {
            const start = new Date("01/01/2000 " + startTime);
            let end = new Date("01/01/2000 " + endTime);

            // If end time is lesser than start time (crossed midnight)
            if (end < start) {
                end.setDate(end.getDate() + 1);
            }

            const diff = end.getTime() - start.getTime();
            const hours = diff / 1000 / 60 / 60;

            document.getElementById(totalInputId).value = hours.toFixed(2);
        }
    }

    // Attach events for Apply Modal
    document.getElementById('start_time').addEventListener('change', function() { calculateTotalHours('start_time', 'end_time', 'total_hours'); });
    document.getElementById('end_time').addEventListener('change', function() { calculateTotalHours('start_time', 'end_time', 'total_hours'); });
</script>

<?php
// Function to calculate available OT credits
function getOTCredits($mysqli, $employee_username) {
    // 1. Calculate Earned OT hours (Approved OT Claims)
    $ot_query = "SELECT SUM(total_hours) as total_ot FROM leave_applications 
                 WHERE employee_username = '$employee_username' 
                 AND request_type = 'OT_Claim' 
                 AND status = 'Approved'";
    $ot_result = $mysqli->query($ot_query);
    $ot_hours = $ot_result->fetch_assoc()['total_ot'] ?? 0;
    
    // 2. Calculate Used hours (Approved Comp-Off Leaves + Approved Permissions)
    $used_query = "SELECT SUM(total_hours) as total_used FROM leave_applications 
                   WHERE employee_username = '$employee_username' 
                   AND request_type IN ('Comp_Off_Leave', 'Permission') 
                   AND status = 'Approved'";
    $used_result = $mysqli->query($used_query);
    $used_hours = $used_result->fetch_assoc()['total_used'] ?? 0;
    
    // 3. Deduct used hours from total OT hours
    $available = $ot_hours - $used_hours;
    return $available > 0 ? round($available, 2) : 0;
}
?>