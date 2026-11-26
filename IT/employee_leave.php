<?php
// IT/employee_leave.php
// PURPOSE: IT TL interface to Apply OT/Permission & View Approval Status from Super Admin

require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Session guard for IT
if (!isset($_SESSION['itname']) && !isset($_SESSION['infoname']) && !isset($_SESSION['uid'])) { 
    header("Location: ../index.php"); 
    exit; 
}

$employee_username = $_SESSION['username'] ?? '';
$employee_id = $_SESSION['uid'] ?? 0;

// Function to calculate available OT credits
function getOTCredits($mysqli, $employee_username) {
    if (!$employee_username) return 0;
    $ot_query = "SELECT SUM(total_hours) as total_ot FROM leave_applications 
                 WHERE employee_username = '$employee_username' 
                 AND request_type = 'OT_Claim' 
                 AND status = 'Approved'";
    $ot_result = $mysqli->query($ot_query);
    $ot_hours = ($ot_result && $ot_row = $ot_result->fetch_assoc()) ? ($ot_row['total_ot'] ?? 0) : 0;
    
    $used_query = "SELECT SUM(total_hours) as total_used FROM leave_applications 
                   WHERE employee_username = '$employee_username' 
                   AND request_type IN ('Comp_Off_Leave', 'Permission') 
                   AND status = 'Approved'";
    $used_result = $mysqli->query($used_query);
    $used_hours = ($used_result && $used_row = $used_result->fetch_assoc()) ? ($used_row['total_used'] ?? 0) : 0;
    
    $available = (float)$ot_hours - (float)$used_hours;
    return $available > 0 ? round($available, 2) : 0;
}

// --- 1. HANDLE FORM SUBMISSION (APPLY OT/PERMISSION) ---
if (($_SERVER['REQUEST_METHOD'] ?? '') == 'POST' && isset($_POST['submit_ot_request'])) {
    
    $request_type = $mysqli->real_escape_string($_POST['request_type']);
    $ot_date = $_POST['ot_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $total_hours = !empty($_POST['total_hours']) ? $_POST['total_hours'] : 0;
    $reason = $mysqli->real_escape_string($_POST['reason']);

    $sql = "INSERT INTO leave_applications (employee_username, request_type, from_date, to_date, start_time, end_time, total_hours, process, article, count, reason, status) 
            VALUES ('$employee_username', '$request_type', '$ot_date', '$ot_date', '$start_time', '$end_time', '$total_hours', '', '', '0', '$reason', 'Pending')";

    if ($mysqli->query($sql)) {
        $msg = "Request submitted successfully! Sent to Super Admin for approval.";
        $msg_type = "success";
    } else {
        $msg = "Error: " . $mysqli->error;
        $msg_type = "danger";
    }
}

// --- 1.1 HANDLE FORM SUBMISSION (UPDATE OT/PERMISSION) ---
if (($_SERVER['REQUEST_METHOD'] ?? '') == 'POST' && isset($_POST['update_ot_request'])) {
    
    $request_id = (int)$_POST['request_id']; 
    $request_type = $mysqli->real_escape_string($_POST['request_type']);
    $ot_date = $_POST['ot_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $total_hours = !empty($_POST['total_hours']) ? $_POST['total_hours'] : 0;
    $reason = $mysqli->real_escape_string($_POST['reason']);

    $update_sql = "UPDATE leave_applications SET 
                   request_type = '$request_type', 
                   from_date = '$ot_date', 
                   to_date = '$ot_date', 
                   start_time = '$start_time', 
                   end_time = '$end_time', 
                   total_hours = '$total_hours', 
                   reason = '$reason' 
                   WHERE id = '$request_id' AND employee_username = '$employee_username' AND status = 'Pending'";

    if ($mysqli->query($update_sql)) {
        $msg = "Request updated successfully!";
        $msg_type = "success";
    } else {
        $msg = "Error updating: " . $mysqli->error;
        $msg_type = "danger";
    }
}

// Get employee details
$employee_details = [];
if ($employee_username) {
    $emp_query = "SELECT 
                    em.employee_name, 
                    tm.team_name
                  FROM employee_master em
                  LEFT JOIN team_master tm ON em.team_id = tm.team_id
                  WHERE em.employee_username = '$employee_username'";
    $emp_result = $mysqli->query($emp_query);
    if ($emp_result && $emp_result->num_rows > 0) {
        $employee_details = $emp_result->fetch_assoc();
    }
}
$team_display = !empty($employee_details['team_name']) ? $employee_details['team_name'] : 'IT';

// Get all processes for dropdown
$all_processes = [];
$process_query = "SELECT process_id, process_name FROM process_master WHERE status='Active' ORDER BY process_name"; 
$process_result = $mysqli->query($process_query);
if ($process_result && $process_result->num_rows > 0) {
    while ($p_row = $process_result->fetch_assoc()) {
        $all_processes[] = $p_row;
    }
}

$pageTitle = "My OT & Permissions";
$currentPage = "employee_leave.php";
include 'header.php'; 
?>

<style>
    body {
        background-color: var(--light, #f5f7fa);
    }

    .main-content {
        margin-top: 0 !important;
        padding: 10px 4% !important;
        transition: all 0.3s ease;
    }

    /* Ash Page Header Banner */
    .page-title-container {
        background: var(--ash, #6c757d);
        color: white;
        padding: 12px 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .page-title-container h1 {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 700;
        color: white;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    /* OT Credits Badge */
    .ot-credits-card {
        background: linear-gradient(135deg, #0d6efd, #0dcaf0);
        color: #ffffff;
        border-radius: 8px;
        padding: 6px 16px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
        display: flex;
        flex-direction: column;
        justify-content: center;
        text-align: left;
    }
    
    .ot-credits-card .ot-label {
        font-size: 11px;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.5px;
        opacity: 0.95;
        line-height: 1.2;
    }
    
    .ot-credits-card .ot-value {
        font-size: 1.15rem;
        font-weight: 700;
        line-height: 1.2;
    }
    
    /* Header Action Button (+ New Request) */
    .btn-header-action {
        background-color: #198754 !important;
        color: #ffffff !important;
        border: 1px solid #198754 !important;
        font-weight: 600 !important;
        padding: 8px 18px !important;
        border-radius: 8px !important;
        text-decoration: none !important;
        display: inline-flex !important;
        align-items: center !important;
        font-size: 14px !important;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
        transition: all 0.2s ease;
        cursor: pointer;
    }
    
    .btn-header-action:hover {
        background-color: #157347 !important;
        color: #ffffff !important;
        border-color: #146c43 !important;
        transform: translateY(-1px);
        box-shadow: 0 3px 6px rgba(0, 0, 0, 0.12) !important;
    }
    
    .btn-header-action i {
        color: #ffffff !important;
        margin-right: 6px !important;
    }
    .btn-header-action span {
        color: #ffffff !important;
        font-weight: 600 !important;
    }

    /* Table Styling & Sticky Header */
    .table {
        border-collapse: separate;
        border-spacing: 0;
    }
    .table thead th {
        position: sticky;
        top: 70px;
        z-index: 900;
        background-color: var(--ash, #6c757d) !important;
        color: white !important;
        border: 1px solid #6c757d !important;
        font-weight: 700;
        font-size: 13px;
        letter-spacing: 0.5px;
        padding: 12px 14px;
        text-transform: uppercase;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15);
    }
    .table tbody td {
        font-size: 13.5px;
        padding: 12px 14px;
        vertical-align: middle;
        color: #333333;
        background-color: #ffffff;
    }
    .table-container {
        overflow: visible;
    }

    .status-Pending { background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
    .status-Approved { background-color: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
    .status-Rejected { background-color: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }
    
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

<main class="main-content">
    <div class="container-fluid p-0">

        <!-- Page Header -->
        <div class="page-title-container">
            <div>
                <h1 class="mb-0">
                    <i class="fas fa-clock"></i> My OT & Permissions
                </h1>
                <p class="mb-0 text-white-50 small mt-1">Submit and track your requests. Reviewed by Super Admin.</p>
            </div>
            <div class="d-flex align-items-center gap-3">
                
                <!-- OT Credits Summary -->
                <div class="ot-credits-card">
                    <span class="ot-label">Available OT Credits</span>
                    <span class="ot-value"><?php echo getOTCredits($mysqli, $employee_username); ?> hours</span>
                </div>
                
                <!-- Button to Open Modal -->
                <button type="button" class="btn btn-success btn-header-action text-white" data-bs-toggle="modal" data-bs-target="#applyOTModal">
                    <i class="fas fa-plus-circle text-white"></i>
                    <span class="text-white">New Request</span>
                </button>
            </div>
        </div>

        <!-- Feedback Message -->
        <?php if(isset($msg)): ?>
            <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show border-0 shadow-sm" role="alert">
                <?= $msg ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- CLAIMS HISTORY TABLE -->
        <div class="card border-0 shadow-sm rounded-3 mb-4" style="overflow: visible;">
            <div class="card-header bg-secondary py-3">
                <h6 class="mb-0 fw-bold text-white">Request History</h6>
            </div>
            <div class="card-body p-0" style="overflow: visible;">
                <div class="table-container" style="overflow: visible;">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3">Date</th>
                                <th>Type</th>
                                <th>Team</th>
                                <th>Time & Duration</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Remarks</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $query = "SELECT la.* 
                              FROM leave_applications la
                              WHERE la.employee_username = '$employee_username' 
                              AND la.request_type IN ('OT_Claim', 'Comp_Off_Leave', 'Permission') 
                              ORDER BY la.from_date DESC";
                            $result = $mysqli->query($query);

                            if ($result && $result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    $status = $row['status'];
                                    $type_label = str_replace('_', ' ', $row['request_type']);
                                    $ot_date = date('d-M-Y', strtotime($row['from_date']));
                                    
                                    $time_display = '';
                                    if (!empty($row['start_time']) && !empty($row['end_time'])) {
                                        $start_time = date('g:i A', strtotime($row['start_time']));
                                        $end_time = date('g:i A', strtotime($row['end_time']));
                                        $time_display = "<span class='time-display'>{$start_time} to {$end_time}</span>";
                                    }
                                    ?>
                                    <tr>
                                        <td class="ps-3 fw-bold"><?= $ot_date ?></td>
                                        <td><span class="type-<?= $row['request_type'] ?>"><?= $type_label ?></span></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($team_display) ?></span></td>
                                        <td>
                                            <?= $time_display ?>
                                            <?php if (!empty($row['total_hours']) && $row['total_hours'] > 0): ?>
                                                <div class="mt-1"><span class="hours-badge"><?= $row['total_hours'] ?> hrs</span></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-secondary"><?= htmlspecialchars($row['reason']) ?></td>
                                        <td><span class="badge status-<?= $status ?>"><?= $status ?></span></td>
                                        <td class="small text-danger"><?= htmlspecialchars($row['remarks'] ?? $row['admin_remarks'] ?? '-') ?></td>
                                        <td class="text-center">
                                            <?php if($status == 'Pending'): ?>
                                                <button class="btn btn-sm btn-outline-primary edit-btn" 
                                                    data-id="<?= $row['id'] ?>" 
                                                    data-type="<?= $row['request_type'] ?>"
                                                    data-date="<?= $row['from_date'] ?>"
                                                    data-start="<?= $row['start_time'] ?>"
                                                    data-end="<?= $row['end_time'] ?>"
                                                    data-hours="<?= $row['total_hours'] ?>"
                                                    data-reason="<?= htmlspecialchars($row['reason'] ?? '') ?>"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editOTModal"
                                                    title="Edit Request">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted small">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                echo "<tr><td colspan='8' class='text-center py-4 text-muted'>No claims or requests found.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- APPLY OT/PERMISSION MODAL -->
<div class="modal fade" id="applyOTModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header text-white" style="background-color: #6c757d !important; background: #6c757d !important;">
                <h5 class="modal-title fw-bold text-white">Submit OT / Permission Request</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Request Type <span class="text-danger">*</span></label>
                            <select name="request_type" class="form-select" required>
                                <option value="">-- Select Type --</option>
                                <option value="OT_Claim">OT Claim</option>
                                <option value="Permission">Permission</option>
                            </select>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Date <span class="text-danger">*</span></label>
                            <input type="date" name="ot_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Start Time <span class="text-danger">*</span></label>
                            <input type="time" name="start_time" id="start_time" class="form-control" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">End Time <span class="text-danger">*</span></label>
                            <input type="time" name="end_time" id="end_time" class="form-control" required>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Total Hours</label>
                            <div class="input-group">
                                <input type="number" name="total_hours" id="total_hours" class="form-control" step="0.01" min="0" placeholder="e.g., 2.5">
                                <span class="input-group-text">hrs</span>
                            </div>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Reason <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="3" placeholder="Please specify your reason..." required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="submit_ot_request" class="btn btn-primary px-4">
                        <i class="fas fa-paper-plane"></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EDIT OT/PERMISSION MODAL -->
<div class="modal fade" id="editOTModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header text-white" style="background-color: #6c757d !important; background: #6c757d !important;">
                <h5 class="modal-title fw-bold text-white">Edit Request</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="request_id" id="edit_request_id">
                
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Request Type <span class="text-danger">*</span></label>
                            <select name="request_type" id="edit_request_type" class="form-select" required>
                                <option value="OT_Claim">OT Claim</option>
                                <option value="Permission">Permission</option>
                            </select>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Date <span class="text-danger">*</span></label>
                            <input type="date" name="ot_date" id="edit_ot_date" class="form-control" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Start Time <span class="text-danger">*</span></label>
                            <input type="time" name="start_time" id="edit_start_time" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">End Time <span class="text-danger">*</span></label>
                            <input type="time" name="end_time" id="edit_end_time" class="form-control" required>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Total Hours</label>
                            <div class="input-group">
                                <input type="number" name="total_hours" id="edit_total_hours" class="form-control" step="0.01">
                                <span class="input-group-text">hrs</span>
                            </div>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Reason <span class="text-danger">*</span></label>
                            <textarea name="reason" id="edit_reason" class="form-control" rows="3" required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_ot_request" class="btn btn-primary px-4">
                        <i class="fas fa-save"></i> Update Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const editButtons = document.querySelectorAll('.edit-btn');
        
        editButtons.forEach(button => {
            button.addEventListener('click', function() {
                document.getElementById('edit_request_id').value = this.getAttribute('data-id');
                document.getElementById('edit_request_type').value = this.getAttribute('data-type');
                document.getElementById('edit_ot_date').value = this.getAttribute('data-date');
                document.getElementById('edit_start_time').value = this.getAttribute('data-start');
                document.getElementById('edit_end_time').value = this.getAttribute('data-end');
                document.getElementById('edit_reason').value = this.getAttribute('data-reason');
                document.getElementById('edit_total_hours').value = this.getAttribute('data-hours');
            });
        });
    });

    function calculateTotalHours(startInputId, endInputId, totalInputId) {
        const startTime = document.getElementById(startInputId).value;
        const endTime = document.getElementById(endInputId).value;

        if (startTime && endTime) {
            const start = new Date("01/01/2000 " + startTime);
            let end = new Date("01/01/2000 " + endTime);

            if (end < start) {
                end.setDate(end.getDate() + 1);
            }

            const diff = end.getTime() - start.getTime();
            const hours = diff / 1000 / 60 / 60;
            document.getElementById(totalInputId).value = hours.toFixed(2);
        }
    }

    document.getElementById('start_time').addEventListener('change', function() { calculateTotalHours('start_time', 'end_time', 'total_hours'); });
    document.getElementById('end_time').addEventListener('change', function() { calculateTotalHours('start_time', 'end_time', 'total_hours'); });
    document.getElementById('edit_start_time').addEventListener('change', function() { calculateTotalHours('edit_start_time', 'edit_end_time', 'edit_total_hours'); });
    document.getElementById('edit_end_time').addEventListener('change', function() { calculateTotalHours('edit_start_time', 'edit_end_time', 'edit_total_hours'); });
</script>
</body>
</html>
