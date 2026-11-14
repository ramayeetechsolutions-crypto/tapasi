<?php
// =========================================================================
// FILE: Super_Admin/employee_master-old.php
// PURPOSE: Super Admin Employee Master & Statutory Governance
// 100% Preserved Functionality • Upgraded Senior Developer Modern UI
// =========================================================================

ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php'; 
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// 1. CHECK LOGIN
if (!isset($_SESSION['username'])) {
    header("Location: ../index.php"); 
    exit;
}

// ---------- Helpers ----------
if (!function_exists('h')) {
    function h($s) { 
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
    }
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
    
    $uan_no     = $mysqli->real_escape_string($_POST['uan_no'] ?? '');
    $epf_name   = $mysqli->real_escape_string($_POST['epf_name'] ?? '');
    $esi_no     = $mysqli->real_escape_string($_POST['esi_no'] ?? '');
    $esi_name   = $mysqli->real_escape_string($_POST['esi_name'] ?? '');
    
    // Exit Details
    $dor_input  = trim($_POST['dor'] ?? '');
    $dor        = !empty($dor_input) ? "'".$mysqli->real_escape_string($dor_input)."'" : "NULL";
    
    $notice     = $mysqli->real_escape_string($_POST['notice_period'] ?? '');
    $remarks    = $mysqli->real_escape_string($_POST['remarks'] ?? '');

    // --- HISTORY TRACKING ---
    $get_curr = $mysqli->query("SELECT salary, salary_log FROM employee_master1 WHERE employee_id = $emp_id");
    $curr_row = $get_curr ? $get_curr->fetch_assoc() : null;
    $old_salary = (float)($curr_row['salary'] ?? 0);
    
    $history_list = json_decode($curr_row['salary_log'] ?? '[]', true);
    if (!is_array($history_list)) { 
        $history_list = []; 
    }

    if ($old_salary != $new_salary) {
        $history_list[] = [
            'date' => date('Y-m-d H:i:s'),
            'old'  => $old_salary,
            'new'  => $new_salary
        ];

        $changed_by = isset($_SESSION['employee_id']) ? (int)$_SESSION['employee_id'] : 0; 
        $insert_history_sql = "INSERT INTO employee_salary_history 
                               (employee_id, old_salary, new_salary, tds_per, changed_by, changed_at) 
                               VALUES 
                               ('$emp_id', '$old_salary', '$new_salary', '$tds_per', '$changed_by', NOW())";
        if (!$mysqli->query($insert_history_sql)) {
             error_log("History Insert Error: " . $mysqli->error);
        }
    }
    
    $new_log_str = $mysqli->real_escape_string(json_encode($history_list));

    // Update Master Table
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
        header("Location: employee_master-old.php?msg=updated&type=success"); 
        exit;
    } else {
        die("Error updating record: " . $mysqli->error);
    }
}

// 3. FETCH DATA
$query = "SELECT * FROM employee_master1 ORDER BY employee_id DESC";
$result = $mysqli->query($query);

$pageTitle = "Employee Master (Super Admin)";
$currentPage = "employee_master.php";
require_once __DIR__ . '/header.php';
?>

<style>
    .header-icon-box {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: #eef2ff;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .emp-card-container {
        background: #ffffff;
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
        overflow: hidden;
    }
    .table-senior {
        margin-bottom: 0;
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    .table-senior thead th {
        background-color: #5a6a7a !important;
        color: #ffffff !important;
        font-weight: 700;
        font-size: 0.76rem;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        white-space: nowrap;
        border-bottom: 1px solid #4a5568;
        vertical-align: middle;
        padding: 12px 14px;
    }
    .table-senior tbody td {
        font-size: 0.86rem;
        vertical-align: middle;
        white-space: nowrap;
        color: #334155;
        padding: 11px 14px;
        border-bottom: 1px solid #f1f5f9;
        transition: background-color 0.15s ease;
    }
    .table-senior tbody tr:hover td {
        background-color: #f8fafc;
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
        display: inline-block;
    }
    .btn-edit-employee {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        background: #ffc107;
        border: 1px solid #ffb300;
        color: #000000;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        transition: all 0.2s ease;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .btn-edit-employee:hover {
        background: #e0a800;
        color: #000000;
        transform: translateY(-1px);
        box-shadow: 0 3px 6px rgba(0,0,0,0.15);
    }

    /* Floating ERP Toast */
    .erp-toast-wrapper {
        position: fixed;
        top: 25px;
        right: 25px;
        z-index: 1090;
        pointer-events: auto;
    }
    .erp-toast-card {
        min-width: 330px;
        max-width: 420px;
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        border: 1px solid #e2e8f0;
        display: flex;
        align-items: flex-start;
        padding: 14px 16px;
        position: relative;
        overflow: hidden;
        animation: erpToastSlideIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    @keyframes erpToastSlideIn { from { transform: translateX(110%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
    @keyframes erpToastSlideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(110%); opacity: 0; } }
    .erp-toast-icon-box {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        flex-shrink: 0;
        margin-right: 12px;
        background: #dcfce7;
        color: #16a34a;
    }
    .erp-toast-content { flex: 1; padding-right: 10px; }
    .erp-toast-header { display: flex; align-items: center; gap: 6px; margin-bottom: 2px; }
    .erp-toast-title { font-size: 0.88rem; font-weight: 700; color: #1e293b; }
    .erp-toast-badge {
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        padding: 2px 6px;
        border-radius: 4px;
        background: #f1f5f9;
        color: #475569;
    }
    .erp-toast-body { font-size: 0.82rem; color: #475569; line-height: 1.35; }
    .erp-toast-close {
        background: none;
        border: none;
        color: #94a3b8;
        font-size: 14px;
        cursor: pointer;
        padding: 4px;
        line-height: 1;
        transition: color 0.15s;
    }
    .erp-toast-close:hover { color: #1e293b; }
    .erp-toast-progress-bar {
        position: absolute;
        bottom: 0;
        left: 0;
        height: 3px;
        width: 100%;
        background: rgba(0, 0, 0, 0.05);
    }
    .erp-toast-progress-fill {
        height: 100%;
        width: 100%;
        background: #16a34a;
        animation: erpToastTimer 4.5s linear forwards;
    }
    @keyframes erpToastTimer { from { width: 100%; } to { width: 0%; } }
</style>

<main class="main-content" style="padding-top: 85px; padding-bottom: 50px;">
    <div class="container-fluid px-lg-4 px-md-3 px-2">

        <!-- FLOATING ERP TOAST NOTIFICATION -->
        <?php if (!empty($_GET['msg'])): 
            $toastMsg = ($_GET['msg'] === 'updated') ? 'Employee details updated successfully!' : h($_GET['msg']);
        ?>
        <div class="erp-toast-wrapper" id="erpToastWrapper" role="region" aria-label="Notifications">
            <div class="erp-toast-card" id="erpToastCard" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="erp-toast-icon-box">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="erp-toast-content">
                    <div class="erp-toast-header">
                        <span class="erp-toast-badge">Updated</span>
                        <strong class="erp-toast-title">Successfully Updated</strong>
                        <span class="erp-toast-time">Just now</span>
                    </div>
                    <div class="erp-toast-body">
                        <?= $toastMsg ?>
                    </div>
                </div>
                <button type="button" class="erp-toast-close" onclick="dismissErpToast()" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
                <div class="erp-toast-progress-bar">
                    <div class="erp-toast-progress-fill"></div>
                </div>
            </div>
        </div>

        <script>
            function dismissErpToast() {
                const card = document.getElementById('erpToastCard');
                if (card) {
                    card.style.animation = 'erpToastSlideOut 0.3s cubic-bezier(0.4, 0, 0.2, 1) forwards';
                    setTimeout(() => {
                        const wrapper = document.getElementById('erpToastWrapper');
                        if (wrapper) wrapper.remove();
                    }, 300);
                }
            }

            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(dismissErpToast, 4500);
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('msg');
                    url.searchParams.delete('type');
                    window.history.replaceState({}, document.title, url.toString());
                } catch(e) {}
            });
        </script>
        <?php endif; ?>

        <!-- PAGE HEADER / ACTIONS -->
        <div class="row align-items-center mb-4">
            <div class="col-md-7 col-12 mb-md-0 mb-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="header-icon-box">
                        <i class="fas fa-user-shield text-primary fs-5"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.3px;">Employee Master (Super Admin)</h4>
                        <div class="text-muted small">Manage Salary, Statutory Eligibility, and Exit Details.</div>
                    </div>
                </div>
            </div>
            <div class="col-md-5 col-12 text-md-end text-start">
                <a href="employee_master.php" class="btn btn-outline-secondary px-3 py-2 rounded-pill shadow-sm d-inline-flex align-items-center gap-2 text-decoration-none" style="font-size: 0.84rem; font-weight: 600;">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Hub</span>
                </a>
            </div>
        </div>

        <!-- MAIN TABLE CARD -->
        <div class="card border-0 shadow-sm rounded-3 overflow-hidden emp-card-container">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-senior text-center" style="width:100%">
                        <thead>
                            <tr>
                                <th width="60">ID</th>
                                <th class="text-start">NAME</th>
                                <th>ROLE</th>
                                <th>SALARY (₹)</th>
                                <th>PF/ESI</th>
                                <th>STATUS</th>
                                <th width="90">ACTION</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <span class="emp-code-pill font-monospace"><?= h($row['employee_username'] ?? '') ?></span>
                                    </td>
                                    <td class="text-start">
                                        <div class="fw-bold text-dark"><?= h($row['employee_name'] ?? '') ?></div>
                                        <small class="text-muted"><?= h($row['email'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <?php if($row['user_role_id'] == 1) echo '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">Admin</span>';
                                              elseif($row['user_role_id'] == 2) echo '<span class="badge bg-warning-subtle text-dark border border-warning-subtle px-2 py-1">TL</span>';
                                              else echo '<span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1">Emp</span>'; ?>
                                    </td>
                                    <td>
                                        <?php if($row['salary'] > 0): ?>
                                            <span class="fw-semibold text-success">&#8377;<?= number_format($row['salary'], 2) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($row['is_eligible_pf']): ?><span class="badge bg-success-subtle text-success border border-success-subtle me-1" title="PF Eligible">PF</span><?php endif; ?>
                                        <?php if($row['is_eligible_esi']): ?><span class="badge bg-primary-subtle text-primary border border-primary-subtle" title="ESI Eligible">ESI</span><?php endif; ?>
                                        <?php if(!$row['is_eligible_pf'] && !$row['is_eligible_esi']): ?><span class="text-muted small">—</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['dor'])): ?>
                                            <span class="badge bg-secondary-subtle text-secondary border px-2 py-1">Resigned</span>
                                            <div class="small text-danger mt-1"><?= h($row['dor']) ?></div>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">
                                                <i class="fas fa-circle me-1" style="font-size:0.5rem;"></i>Active
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" 
                                                class="btn-edit-employee edit-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#saEditModal"
                                                data-id="<?= $row['employee_id'] ?>"
                                                data-name="<?= h($row['employee_name'] ?? '') ?>"
                                                data-salary="<?= $row['salary'] ?>"
                                                data-tds="<?= $row['tds_per'] ?>"
                                                data-pf="<?= $row['is_eligible_pf'] ?>"
                                                data-esi="<?= $row['is_eligible_esi'] ?>"
                                                data-uan="<?= h($row['uan_no'] ?? '') ?>"
                                                data-epfname="<?= h($row['epf_name'] ?? '') ?>"
                                                data-esino="<?= h($row['esi_no'] ?? '') ?>"
                                                data-esiname="<?= h($row['esi_name'] ?? '') ?>"
                                                data-dor="<?= h($row['dor'] ?? '') ?>"
                                                data-notice="<?= h($row['notice_period'] ?? '') ?>"
                                                data-remarks="<?= h($row['remarks'] ?? '') ?>"
                                                title="Edit Details">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-users fa-3x mb-3 text-secondary opacity-50"></i>
                                        <h5 class="fw-semibold">No records found</h5>
                                        <p class="mb-0 small">No employee statutory records available.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</main>

<!-- =========================================================================
     EDIT MODAL (STRICT: NO SAVE BUTTON IN HEADER - ONLY IN FOOTER)
     ========================================================================= -->
<div class="modal fade" id="saEditModal" tabindex="-1" aria-labelledby="saEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-3 overflow-hidden">
            
            <!-- Strict Header: Title, Subtitle, Close ✕ ONLY -->
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <span class="header-icon-box me-2" style="width: 36px; height: 36px; border-radius: 8px; background: rgba(59, 130, 246, 0.1); display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-user-shield text-primary fs-5"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-dark fs-6" id="saEditModalLabel">Update Admin & Statutory Details</h5>
                        <div class="text-muted" style="font-size: 0.75rem;">Financials, PF/ESI statutory eligibility, and exit parameters.</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form method="post" action="employee_master-old.php" autocomplete="off">
                <input type="hidden" name="emp_id" id="edit_emp_id">
                
                <div class="modal-body p-4">
                    <div class="p-2 mb-3 bg-light rounded border d-flex align-items-center gap-2">
                        <span class="text-secondary small fw-semibold">Employee:</span>
                        <strong class="text-primary fs-6" id="display_name"></strong>
                    </div>

                    <!-- Financials -->
                    <h6 class="fw-bold text-primary mb-3 d-flex align-items-center gap-2" style="font-size: 0.85rem;">
                        <i class="fas fa-indian-rupee-sign"></i> Compensation
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary" style="font-size: 0.82rem;">Basic / Gross Salary (₹)</label>
                            <input type="number" step="0.01" name="salary" id="edit_salary" class="form-control form-control-sm" placeholder="0.00" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary" style="font-size: 0.82rem;">TDS Percentage (%)</label>
                            <input type="number" step="0.01" name="tds_per" id="edit_tds" class="form-control form-control-sm" placeholder="0.00">
                        </div>
                    </div>

                    <!-- Statutory -->
                    <h6 class="fw-bold text-primary mb-3 d-flex align-items-center gap-2" style="font-size: 0.85rem;">
                        <i class="fas fa-file-contract"></i> Statutory Compliance
                    </h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" name="is_eligible_pf" id="edit_pf_check" onchange="toggleStatutory()">
                                <label class="form-check-label fw-semibold text-secondary small" for="edit_pf_check">Is Eligible for PF?</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" name="is_eligible_esi" id="edit_esi_check" onchange="toggleStatutory()">
                                <label class="form-check-label fw-semibold text-secondary small" for="edit_esi_check">Is Eligible for ESI?</label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- PF Fields -->
                    <div class="row g-3 mb-3 p-3 border rounded-3 bg-light" id="pf_container" style="display:none;">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">UAN Number</label>
                            <input type="text" name="uan_no" id="edit_uan" class="form-control form-control-sm" placeholder="12 digit UAN">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">EPF Name</label>
                            <input type="text" name="epf_name" id="edit_epfname" class="form-control form-control-sm" placeholder="Name as per EPF">
                        </div>
                    </div>

                    <!-- ESI Fields -->
                    <div class="row g-3 mb-4 p-3 border rounded-3 bg-light" id="esi_container" style="display:none;">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">ESI Insurance Number</label>
                            <input type="text" name="esi_no" id="edit_esino" class="form-control form-control-sm" placeholder="17 digit ESI No">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">ESI Name</label>
                            <input type="text" name="esi_name" id="edit_esiname" class="form-control form-control-sm" placeholder="Name as per ESI">
                        </div>
                    </div>

                    <!-- Exit Details -->
                    <h6 class="fw-bold text-primary mb-3 d-flex align-items-center gap-2" style="font-size: 0.85rem;">
                        <i class="fas fa-door-open"></i> Exit & Transition Management
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Date of Resignation / Relieving</label>
                            <input type="date" name="dor" id="edit_dor" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Notice Period Status</label>
                            <select name="notice_period" id="edit_notice" class="form-select form-select-sm">
                                <option value="">Select Status</option>
                                <option value="Serving Notice">Serving Notice</option>
                                <option value="Waived Off">Waived Off</option>
                                <option value="Completed">Completed</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-secondary small">Administrative Remarks</label>
                            <textarea name="remarks" id="edit_remarks" class="form-control form-control-sm" rows="2" placeholder="Exit clearance notes, handover details, etc."></textarea>
                        </div>
                    </div>
                </div>

                <!-- Strict Footer: Cancel & Update Details -->
                <div class="modal-footer bg-light border-top px-4 py-2 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                    <button type="submit" name="update_sa_details" class="btn btn-primary px-4 shadow-sm erp-btn-save d-flex align-items-center gap-2" style="font-weight: 600; border-radius: 8px;">
                        <i class="fas fa-save"></i> <span>Update Details</span>
                    </button>
                </div>
            </form>
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

    if (pfBox) pfBox.style.display = (pfCheck && pfCheck.checked) ? 'flex' : 'none';
    if (esiBox) esiBox.style.display = (esiCheck && esiCheck.checked) ? 'flex' : 'none';
}

document.addEventListener('DOMContentLoaded', function () {
    const editButtons = document.querySelectorAll('.edit-btn');
    editButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('edit_emp_id').value = this.getAttribute('data-id') || '';
            document.getElementById('display_name').textContent = this.getAttribute('data-name') || '';
            
            document.getElementById('edit_salary').value = this.getAttribute('data-salary') || '0';
            document.getElementById('edit_tds').value = this.getAttribute('data-tds') || '0';
            
            var isPf = (this.getAttribute('data-pf') == '1');
            var isEsi = (this.getAttribute('data-esi') == '1');
            
            document.getElementById('edit_pf_check').checked = isPf;
            document.getElementById('edit_esi_check').checked = isEsi;
            
            document.getElementById('edit_uan').value = this.getAttribute('data-uan') || '';
            document.getElementById('edit_epfname').value = this.getAttribute('data-epfname') || '';
            document.getElementById('edit_esino').value = this.getAttribute('data-esino') || '';
            document.getElementById('edit_esiname').value = this.getAttribute('data-esiname') || '';
            
            document.getElementById('edit_dor').value = this.getAttribute('data-dor') || '';
            document.getElementById('edit_notice').value = this.getAttribute('data-notice') || '';
            document.getElementById('edit_remarks').value = this.getAttribute('data-remarks') || '';
            
            toggleStatutory();
        });
    });
});
</script>
</body>
</html>
