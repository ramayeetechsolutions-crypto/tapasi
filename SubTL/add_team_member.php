<?php
// =========================================================================
// FILE: SubTL/add_team_member.php
// PURPOSE: Add / Edit Sub-TL Team Member Assignment
// =========================================================================

require_once 'db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'add';
$emp_username = isset($_GET['emp_username']) ? $_GET['emp_username'] : '';
$tl_name = isset($_GET['tl_name']) ? $_GET['tl_name'] : '';

$selected_emp_id = "";
$selected_process = "";

if ($mode == 'edit') {
    $escaped_username = $mysqli->real_escape_string($emp_username);
    $edit_sql = "SELECT employee_id, process FROM employee_master WHERE employee_username = '$escaped_username'";
    $edit_res = $mysqli->query($edit_sql);
    if($edit_res && $row = $edit_res->fetch_assoc()){
        $selected_emp_id = $row['employee_id'];
        $selected_process = $row['process'];
    }
}

// 1. Fetch Employees (ID and Name) from employee_master
$emp_query = "SELECT employee_id, employee_username, employee_name FROM employee_master WHERE user_role_id = 4 AND status='Active' ORDER BY employee_username ASC";
$emp_result = $mysqli->query($emp_query);

// 2. Fetch processes
$proc_query = "SELECT process_id, process_name FROM process_master ORDER BY process_name ASC";
$proc_result = $mysqli->query($proc_query);

$pageTitle = ($mode == 'edit') ? 'Edit Member Assignment' : 'Add Team Member';
$currentPage = 'team_view.php';
require_once 'header.php';
?>

<!-- Select2 CSS for Searchable Dropdown -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
.main-content {
    padding-top: 85px !important;
    padding-bottom: 50px;
}
.form-card {
    max-width: 760px;
    margin: 20px auto;
    background: #ffffff;
    padding: 35px 40px;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
}
.form-label {
    font-weight: 600;
    color: #475569;
    font-size: 0.88rem;
    margin-bottom: 6px;
}
.select2-container--default .select2-selection--single {
    height: 42px;
    padding-top: 6px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 40px;
}
</style>

<main class="main-content">
    <div class="container-fluid px-lg-4 px-md-3 px-2">
        <div class="form-card">
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb mb-0" style="font-size: 0.78rem;">
                    <li class="breadcrumb-item"><a href="dashboard_subtl.php" class="text-decoration-none text-muted"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="team_view.php" class="text-decoration-none text-muted">Sub-TL Team Master</a></li>
                    <li class="breadcrumb-item active text-primary fw-semibold" aria-current="page"><?= ($mode == 'edit') ? 'Edit Assignment' : 'Add Member' ?></li>
                </ol>
            </nav>

            <div class="d-flex align-items-center gap-3 mb-4 pb-3 border-bottom">
                <div class="rounded-3 bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; font-size: 1.25rem;">
                    <i class="fas <?= ($mode == 'edit') ? 'fa-user-edit' : 'fa-user-plus'; ?>"></i>
                </div>
                <div>
                    <h4 class="fw-bold mb-0 text-dark"><?= ($mode == 'edit') ? 'Edit Member Assignment' : 'Add Team Member'; ?></h4>
                    <span class="text-muted small">Configure executive assignment and process responsibilities.</span>
                </div>
            </div>
            
            <form action="save_team_member.php" method="POST">
                <?php if($mode == 'edit'): ?>
                    <input type="hidden" name="employee_id" value="<?= htmlspecialchars($selected_emp_id) ?>">
                <?php endif; ?>

                <!-- Pre-filled Team Leader Name -->
                <div class="mb-3">
                    <label class="form-label">Team Leader Name</label>
                    <input type="text" class="form-control bg-light" name="reporting_manager" value="<?= htmlspecialchars($tl_name); ?>" readonly>
                </div>

                <!-- Searchable Employee Selection -->
                <div class="mb-3">
                    <label class="form-label">Select Employee (Search by ID or Name)</label>
                    <select name="employee_id" class="form-select searchable-select" required <?= ($mode == 'edit') ? 'disabled' : ''; ?>>
                        <option value="">-- Start typing ID or Name --</option>
                        <?php if ($emp_result): while($row = $emp_result->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($row['employee_id']); ?>" <?= ($row['employee_id'] == $selected_emp_id) ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($row['employee_username'] . " - " . $row['employee_name']); ?>
                            </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>

                <!-- Process Selection -->
                <div class="mb-4">
                    <label class="form-label">Assign Process</label>
                    <select name="process_id" class="form-select searchable-select" required>
                        <option value="">-- Search process --</option>
                        <?php 
                        $custom_processes = array(
                            "AP", "CE", "FQA", "LE", "PAG", "QC", "REF", "TAB", 
                            "Sub-TL", "TAB/IMG", "PRE-PAG", "QC/PAG", "AP_QC", 
                            "AP_CRX", "AP-QC", "CE-REF", "REF/Table"
                        );
                        
                        foreach($custom_processes as $p_name): 
                        ?>
                            <option value="<?= htmlspecialchars($p_name); ?>" <?= ($p_name == $selected_process) ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($p_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="d-flex justify-content-between align-items-center pt-3 border-top mt-4">
                    <a href="team_view.php" class="btn btn-outline-secondary px-4" style="border-radius: 8px;">
                        <i class="fas fa-arrow-left me-1"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary px-4 py-2 fw-semibold" style="border-radius: 8px;">
                        <i class="fas fa-save me-1"></i>
                        <?= ($mode == 'edit') ? 'Update Assignment' : 'Assign Member'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        $('.searchable-select').select2({
            width: '100%'
        });
    });
</script>
