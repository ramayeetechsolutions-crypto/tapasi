<?php
// Helper function to safely print data and prevent errors
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

// Start session and check login
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['username'])) {
    header("Location: ../index.php");
    exit;
}

require 'header.php'; // Include your existing header

// --- AJAX SAVE LOGIC FOR WEEKLY DATA ---
if (isset($_POST['ajax']) && $_POST['ajax'] == 'save_weekly_data') {
    header('Content-Type: application/json');
    $emp_id = $_POST['emp_id'] ?? '';
    $week_num = (int)$_POST['week_num'];
    $reason = trim($_POST['reason'] ?? '');
    $year = date('Y');

    $check_col2 = $mysqli->query("SHOW COLUMNS FROM weekly_targets LIKE 'reason'");
    if ($check_col2 && $check_col2->num_rows == 0) {
        $mysqli->query("ALTER TABLE weekly_targets ADD COLUMN reason TEXT DEFAULT NULL");
    }

    // Check if data already exists for this week
    $check_sql = "SELECT id FROM weekly_targets WHERE tm_id = ? AND week_number = ? AND year = ?";
    $stmt_chk = $mysqli->prepare($check_sql);
    $stmt_chk->bind_param("sii", $emp_id, $week_num, $year);
    $stmt_chk->execute();
    $res_chk = $stmt_chk->get_result();
    
    if ($res_chk->num_rows > 0) {
        // Only update reason
        $upd = $mysqli->prepare("UPDATE weekly_targets SET reason=? WHERE tm_id=? AND week_number=? AND year=?");
        $upd->bind_param("ssii", $reason, $emp_id, $week_num, $year);
        $upd->execute();
    } else {
        // Insert with 0 target limits if user hasn't been assigned a target yet
        $ins = $mysqli->prepare("INSERT INTO weekly_targets (tm_id, week_number, year, reason, target_limit, achieved_target, assigned_date, status) VALUES (?, ?, ?, ?, 0, 0, NOW(), 'Active')");
        $ins->bind_param("siis", $emp_id, $week_num, $year, $reason);
        $ins->execute();
    }
    echo json_encode(['success' => true]);
    exit;
}

// --- FILTERS & DYNAMIC WEEKS LOGIC ---
$current_user = $_SESSION['username'] ?? '';

// Get Filters
$filter_tm = $_GET['filter_tm'] ?? '';
$filter_process = $_GET['filter_process'] ?? []; // Changed to array for multiple checkboxes
$filter_week = $_GET['filter_week'] ?? date('W'); // Default to Current Week
$filter_year = date('Y'); // Auto fetch current year for exact data filtering

// Calculate 4 Dynamic Weeks for Columns
$w1 = (int)$filter_week;
$w2 = $w1 - 1 > 0 ? $w1 - 1 : 52 + ($w1 - 1);
$w3 = $w2 - 1 > 0 ? $w2 - 1 : 52 + ($w2 - 1);
$w4 = $w3 - 1 > 0 ? $w3 - 1 : 52 + ($w3 - 1);

// 1. Get the logged-in user's employee_name (Reporting Manager)
$user_employee_name = '';
$user_sql = "SELECT reporting_manager FROM employee_master WHERE employee_username = ?";
$stmt_user = $mysqli->prepare($user_sql);
if ($stmt_user) {
    $stmt_user->bind_param("s", $current_user);
    $stmt_user->execute();
    $stmt_user->bind_result($user_employee_name);
    $stmt_user->fetch();
    $stmt_user->close();
}

// Prepare Queries for Target, Production, and Errors (Filtered by Week AND Year)
$target_sql = "SELECT SUM(target_limit) as total_target FROM weekly_targets WHERE tm_id = ? AND week_number = ? AND year = ?";
$stmt_target = $mysqli->prepare($target_sql);

// Fallback query to get the latest target if current week has no target assigned
$target_fallback_sql = "SELECT target_limit FROM weekly_targets WHERE tm_id = ? ORDER BY id DESC LIMIT 1";
$stmt_target_fallback = $mysqli->prepare($target_fallback_sql);

$prod_sql = "
    SELECT SUM(j.word_count) as total_prod 
    FROM jobs j 
    LEFT JOIN ce c ON j.job_id = c.job_id 
    WHERE (c.assigned_to_content = ? OR c.assigned_to_img = ? OR j.assigned_to = ?)
    AND WEEK(j.job_date, 1) = ? AND YEAR(j.job_date) = ?
";
$stmt_prod = $mysqli->prepare($prod_sql);

$error_sql = "SELECT COUNT(*) as error_count FROM article_errors WHERE team_error_made_by = ? AND WEEK(date, 1) = ? AND YEAR(date) = ?";
$stmt_error = $mysqli->prepare($error_sql);

// 2. Fetch all team members under this TL with Filters & Ascending Order
$team_data = [];
if (!empty($user_employee_name)) {
    $tm_sql = "SELECT employee_id, employee_username, employee_name, process, joining_date 
               FROM employee_master 
               WHERE reporting_manager = ? AND status = 'Active'";
    
    // Apply TM Filter
    if (!empty($filter_tm)) {
        $tm_sql .= " AND employee_username = '" . $mysqli->real_escape_string($filter_tm) . "'";
    }
    // Apply Process Filter (Multiple Checkboxes)
    if (!empty($filter_process) && is_array($filter_process)) {
        $process_conditions = [];
        foreach ($filter_process as $p_id) {
            $process_conditions[] = "FIND_IN_SET('" . $mysqli->real_escape_string($p_id) . "', process)";
        }
        $tm_sql .= " AND (" . implode(" OR ", $process_conditions) . ")";
    }
    
    // ASCENDING ORDER BY ID
    $tm_sql .= " ORDER BY employee_username ASC";
    
    $stmt_tm = $mysqli->prepare($tm_sql);
    
    if ($stmt_tm) {
        $stmt_tm->bind_param("s", $user_employee_name);
        $stmt_tm->execute();
        $res_tm = $stmt_tm->get_result();
        
        while($row = $res_tm->fetch_assoc()) {
            $emp_username = $row['employee_username'];
            $emp_name = $row['employee_name'];
            
            // Process Name Conversion
            $process_ids = $row['process'];
            $process_names_array = [];
            if (!empty($process_ids) && $process_ids != '0') {
                $process_sql = "SELECT process_name FROM process_master WHERE process_id IN ($process_ids)";
                $process_res = $mysqli->query($process_sql);
                if ($process_res) {
                    while($p_row = $process_res->fetch_assoc()) {
                        $process_names_array[] = $p_row['process_name'];
                    }
                }
            }
            $process_name_final = !empty($process_names_array) ? implode(", ", $process_names_array) : 'CE';
            
            // Experience Calculation
            $exp_str = '-';
            if (!empty($row['joining_date']) && $row['joining_date'] != '0000-00-00') {
                $doj_date = new DateTime($row['joining_date']);
                $today = new DateTime();
                $diff = $today->diff($doj_date);
                $exp_str = $diff->y . ' Y ' . $diff->m . ' M';
            }
            
            // Get Target for selected week
            $target = 0;
            if ($stmt_target) {
                $stmt_target->bind_param("sii", $emp_username, $w1, $filter_year); 
                $stmt_target->execute();
                $res_target = $stmt_target->get_result()->fetch_assoc();
                $target = $res_target['total_target'] ?? 0;
                
                // Fallback if no target assigned for the current week
                if ($target == 0 && isset($stmt_target_fallback)) {
                    $stmt_target_fallback->bind_param("s", $emp_username);
                    $stmt_target_fallback->execute();
                    $res_fallback = $stmt_target_fallback->get_result()->fetch_assoc();
                    $target = $res_fallback['target_limit'] ?? 0;
                }
            }
            
            // Get Production for selected week
            $production = 0;
            if ($stmt_prod) {
                $stmt_prod->bind_param("sssii", $emp_username, $emp_username, $emp_username, $w1, $filter_year); 
                $stmt_prod->execute();
                $res_prod = $stmt_prod->get_result()->fetch_assoc();
                $production = $res_prod['total_prod'] ?? 0;
            }
            
            // Get Errors for selected week
            $errors = 0;
            if ($stmt_error) {
                $stmt_error->bind_param("sii", $emp_name, $w1, $filter_year); 
                $stmt_error->execute();
                $res_error = $stmt_error->get_result()->fetch_assoc();
                $errors = $res_error['error_count'] ?? 0;
            }

            // Calculations
            $production_points = $target > 0 ? round($production / ($target / 100), 2) : 0;
            $errors_points = $errors * 2;
            $prod_pct = $production_points; 

            $accuracy = 100;
            if ($production_points > 0) {
                $accuracy = round((($production_points - $errors_points) / $production_points) * 100, 2);
                if ($accuracy < 0) $accuracy = 0;
            } else if ($errors_points > 0) {
                $accuracy = 0;
            }

            $net_production = $production - ($errors_points / 100);
            $net_prod_pct = $target > 0 ? round($net_production / ($target / 100), 2) : 0;
            $net_prod_decimal = $net_prod_pct / 100;
            
            $team_data[] = [
                'id' => $emp_username,
                'name' => $emp_name,
                'process' => $process_name_final,
                'exp' => $exp_str,
                'target' => $target,
                'production' => $production,
                'prod_points' => $production_points,
                'errors' => $errors,
                'err_points' => $errors_points,
                'prod_pct' => $prod_pct,
                'accuracy' => $accuracy,
                'net_prod_pct' => $net_prod_pct,
                'net_prod_decimal' => $net_prod_decimal,
                'leave' => 0 // Default value for Leave
            ];
        }
        $stmt_tm->close();
    }
}
if($stmt_target) $stmt_target->close();
if($stmt_prod) $stmt_prod->close();
if($stmt_error) $stmt_error->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CE Meeting Report</title>
    <!-- Include Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Include Chart.js and DataLabels Plugin -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    
    <style>
        * { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
        body { background-color: #f8fafc; }
        
        .page-header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 0;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-top: 70px;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            margin-bottom: 2rem;
        }
        
        .table { margin-bottom: 0; }
        .table thead th {
            background-color: #707981 !important; 
            color: white !important;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            padding: 0.8rem 0.5rem;
            border: 1px solid #5a6268 !important;
            white-space: nowrap;
            text-align: center;
            vertical-align: middle;
        }
        .table tbody td {
            padding: 0.6rem 0.4rem;
            font-size: 0.85rem;
            color: #334155;
            vertical-align: middle;
            border: 1px solid #cbd5e1;
            text-align: center;
        }
        .table tbody tr:hover { background-color: #f8fafc; }
        .text-start { text-align: left !important; }
        
        .text-danger-fw { color: #dc2626; font-weight: 600; }
        .text-primary-fw { color: #2563eb; font-weight: 600; }

        .btn-edit {
            font-size: 0.75rem;
            padding: 0.3rem 0.6rem;
            color: #475569;
            background-color: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            text-decoration: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
        .btn-edit:hover {
            background-color: #e2e8f0;
            color: #1e293b;
        }
        
        .chart-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            margin-bottom: 2rem;
        }
        .section-title {
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
    </style>
</head>
<body>

<div class="page-header">
    <div class="container-fluid px-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-1" style="font-weight: 700; color: #1e293b;">
                    <i class="fas fa-handshake me-2" style="color: #667eea;"></i>
                    CE Meeting
                </h4>
                <p class="mb-0 text-muted small">Weekly Production & Accuracy Report</p>
            </div>
            <div>
                <a href="jobmgmt_ce.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back to Reports
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-4">

<?php
        // Fetch values for Dropdowns
        $tm_filter_qry = $mysqli->query("SELECT employee_username, employee_name FROM employee_master WHERE reporting_manager = '$user_employee_name' AND status = 'Active' ORDER BY employee_name ASC");
        $proc_filter_qry = $mysqli->query("SELECT process_id, process_name FROM process_master ORDER BY process_name ASC");
    ?>
    <!-- FILTER FORM -->
    <div class="card mb-3 shadow-sm border-0">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold mb-1">Team Member</label>
                    <select name="filter_tm" class="form-select form-select-sm">
                        <option value="">All Team Members</option>
                        <?php if($tm_filter_qry) while($t = $tm_filter_qry->fetch_assoc()): ?>
                            <option value="<?= h($t['employee_username']) ?>" <?= $filter_tm == $t['employee_username'] ? 'selected' : '' ?>><?= h($t['employee_name']) ?> (<?= h($t['employee_username']) ?>)</option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Process</label>
                    <div style="position: relative;">
                        <!-- Custom Button (Bootstrap JS remove panniyachu) -->
                        <button class="btn w-100 bg-white shadow-none" type="button" id="processBtn" onclick="toggleProcessMenu(event)" style="border: 1px solid #dee2e6; font-size: 0.875rem; padding: 0.25rem 0.5rem; text-align: left; display: flex; justify-content: space-between; align-items: center;">
                            Select Processes <i class="fas fa-chevron-down mt-1" style="font-size:0.7rem; color:#6c757d;"></i>
                        </button>
                        
                        <!-- Custom Menu box (Pure CSS/JS la open aagum) -->
                        <div id="processMenu" class="shadow-sm border bg-white" style="display: none; position: absolute; top: 100%; left: 0; right: 0; z-index: 1050; max-height: 200px; overflow-y: auto; padding: 10px; border-radius: 4px; margin-top: 2px;">
                            <?php if($proc_filter_qry) while($p = $proc_filter_qry->fetch_assoc()): ?>
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" name="filter_process[]" value="<?= h($p['process_id']) ?>" id="proc_<?= h($p['process_id']) ?>" <?= (is_array($filter_process) && in_array($p['process_id'], $filter_process)) ? 'checked' : '' ?>>
                                    <label class="form-check-label w-100" for="proc_<?= h($p['process_id']) ?>" style="cursor: pointer; font-size: 0.85rem;">
                                        <?= h($p['process_name']) ?>
                                    </label>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>

                <!-- Custom Dropdown Click Logic added here -->
                <script>
                    function toggleProcessMenu(e) {
                        e.preventDefault();
                        var menu = document.getElementById('processMenu');
                        // Open and Close click logic
                        if(menu.style.display === 'none' || menu.style.display === '') {
                            menu.style.display = 'block';
                        } else {
                            menu.style.display = 'none';
                        }
                    }
                    
                    // Click outside to close the dropdown
                    document.addEventListener('click', function(e) {
                        var menu = document.getElementById('processMenu');
                        var btn = document.getElementById('processBtn');
                        if (menu && btn && !menu.contains(e.target) && !btn.contains(e.target)) {
                            menu.style.display = 'none';
                        }
                    });
                </script>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Week</label>
                    <select name="filter_week" class="form-select form-select-sm">
                        <?php for($i=1; $i<=53; $i++): ?>
                            <option value="<?= $i ?>" <?= $w1 == $i ? 'selected' : '' ?>>Week <?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm me-1"><i class="fas fa-filter"></i> Filter</button>
                    <a href="ce_meeting.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i> Clear</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- WEEKLY REPORT TABLE -->
    <h5 class="section-title"><i class="fas fa-table text-primary"></i> Weekly Report Data</h5>
    <div class="table-container">
        <div class="table-responsive">
            <table class="table table-hover table-bordered">
                <thead>
                    <tr>
                        <!-- Main Header spans across all 17 columns -->
                        <th colspan="17" style="background-color: #475569 !important; font-size:1rem;">WEEKLY REPORT</th>
                    </tr>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Exp</th>
                        <th>Process</th>
                        <th>Target</th>
                        <th>Production</th>
                        <th>Prod Points</th>
                        <th>Errors</th>
                        <th>Err Points</th>
                        <th>Production %</th>
                        <th>Accuracy %</th>
                        <th>NET Prod %</th>
                        <th>Leave</th>
                        <!-- Week columns moved to the second row -->
                        <th>Week <?= $w1 ?></th>
                        <th>Week <?= $w2 ?></th>
                        <th>Week <?= $w3 ?></th>
                        <th>Week <?= $w4 ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($team_data)): ?>
                        <?php foreach ($team_data as $emp): ?>
                            <tr>
                                <td class="fw-500"><?= h($emp['id']) ?></td>
                                <td class="text-start fw-500"><?= h($emp['name']) ?></td>
                                <td><?= h($emp['exp']) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= h($emp['process']) ?></span></td>
                                <td class="text-danger-fw"><?= h($emp['target']) ?></td>
                                <td><?= h($emp['production']) ?></td>
                                <td class="fw-bold text-success"><?= h($emp['prod_points']) ?></td>
                                <td class="fw-bold"><?= h($emp['errors']) ?></td>
                                <td class="fw-bold text-danger"><?= h($emp['err_points']) ?></td>
                                <td class="text-primary-fw"><?= h($emp['prod_pct']) ?>%</td>
                                <td class="text-primary-fw"><?= h($emp['accuracy']) ?>%</td>
                                <td class="text-primary-fw"><?= h($emp['net_prod_pct']) ?>%</td>
                                <td class="fw-bold text-danger"><?= h($emp['leave']) ?></td>
                                <!-- EDIT OPTIONS RESTORED WITH MODAL TRIGGERS -->
                                <td><a href="javascript:void(0);" class="btn-edit" onclick="openEditModal('<?= h($emp['id']) ?>', '<?= h($emp['name']) ?>', <?= $w1 ?>)"><i class="fas fa-edit"></i> Edit</a></td>
                                <td><a href="javascript:void(0);" class="btn-edit" onclick="openEditModal('<?= h($emp['id']) ?>', '<?= h($emp['name']) ?>', <?= $w2 ?>)"><i class="fas fa-edit"></i> Edit</a></td>
                                <td><a href="javascript:void(0);" class="btn-edit" onclick="openEditModal('<?= h($emp['id']) ?>', '<?= h($emp['name']) ?>', <?= $w3 ?>)"><i class="fas fa-edit"></i> Edit</a></td>
                                <td><a href="javascript:void(0);" class="btn-edit" onclick="openEditModal('<?= h($emp['id']) ?>', '<?= h($emp['name']) ?>', <?= $w4 ?>)"><i class="fas fa-edit"></i> Edit</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="16" class="text-center py-4">No team members found under your profile.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- BAR DIAGRAM -->
    <h5 class="section-title mt-4"><i class="fas fa-chart-bar text-primary"></i> Production vs Accuracy Chart</h5>
    <div class="chart-card">
        <canvas id="productionChart" height="100"></canvas>
    </div>

</div>

<!-- EDIT WEEKLY DATA MODAL -->
<div class="modal fade" id="editWeekModal" tabindex="-1" aria-labelledby="editWeekModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="editWeekForm">
          <div class="modal-header">
            <h5 class="modal-title" id="editWeekModalLabel" style="font-weight: 700; color: #1e293b;">Edit Weekly Data</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" id="editEmpId" name="emp_id">
            <input type="hidden" id="editWeekNum" name="week_num">
            
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Employee Name</label>
                <input type="text" class="form-control bg-light" id="editEmpName" readonly>
            </div>
            
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Reason (Optional)</label>
                <textarea class="form-control" id="editReason" name="reason" rows="2" placeholder="Enter reason for errors / production delay..."></textarea>
            </div>

            <!-- <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label text-muted small fw-bold">Target</label>
                    <input type="number" class="form-control" id="editTarget" name="target" placeholder="Enter target">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label text-muted small fw-bold">Production</label>
                    <input type="number" class="form-control" id="editProduction" name="production" placeholder="Enter production">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Errors</label>
                <input type="number" class="form-control" id="editErrors" name="errors" placeholder="Enter errors count">
            </div> -->
            
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            <button type="button" class="btn btn-primary" onclick="saveWeeklyData()">Save Changes</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- Include Bootstrap JS for Modal to work -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Scripts for Chart.js & Dynamic Data & Modals -->
<script>
    // --- MODAL JAVASCRIPT LOGIC ---
    function openEditModal(empId, empName, weekNum) {
        // Set values in the modal
        document.getElementById('editEmpId').value = empId;
        document.getElementById('editEmpName').value = empName;
        document.getElementById('editWeekNum').value = weekNum;
        document.getElementById('editWeekModalLabel').innerText = "Edit Week " + weekNum + " Data";
        
        // Clear previous input field
        document.getElementById('editReason').value = '';

        // Show the Bootstrap modal
        var editModal = new bootstrap.Modal(document.getElementById('editWeekModal'));
        editModal.show();
    }

    function saveWeeklyData() {
        let empId = document.getElementById('editEmpId').value;
        let weekNum = document.getElementById('editWeekNum').value;
        let reason = document.getElementById('editReason').value;

        // AJAX call to save data
        let formData = new URLSearchParams();
        formData.append('ajax', 'save_weekly_data');
        formData.append('emp_id', empId);
        formData.append('week_num', weekNum);
        formData.append('reason', reason);

        fetch('ce_meeting.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                // Hide modal and refresh page to show new updated data
                var editModalElement = document.getElementById('editWeekModal');
                var modalInstance = bootstrap.Modal.getInstance(editModalElement);
                modalInstance.hide();
                location.reload(); 
            } else {
                alert("Error saving data!");
            }
        })
        .catch(error => console.error('Error:', error));
    }

    // --- CHART JAVASCRIPT LOGIC ---
    Chart.register(ChartDataLabels);

    // Get Dynamic PHP Data to JS
    const teamData = <?= json_encode($team_data) ?>;
    
    // Extract data for chart
    const chartLabels = teamData.map(emp => emp.name.split(' ')); // Splits name into multiple lines if needed
    const netProdValues = teamData.map(emp => emp.net_prod_decimal);
    const accuracyValues = teamData.map(emp => emp.accuracy / 100);

    const ctx = document.getElementById('productionChart').getContext('2d');

    const data = {
        labels: chartLabels,
        datasets: [
            {
                label: 'NET Prod',
                data: netProdValues,
                backgroundColor: '#1d4ed8', // Changed to match your Blue
                borderRadius: 4,
                barPercentage: 0.8,
                categoryPercentage: 0.8
            },
            {
                label: 'Accuracy',
                data: accuracyValues,
                backgroundColor: '#15803d', // Changed to match your Green
                borderRadius: 4,
                barPercentage: 0.8,
                categoryPercentage: 0.8
            }
        ]
    };

    const config = {
        type: 'bar',
        data: data,
        options: {
            responsive: true,
            layout: { padding: { top: 30 } },
            scales: {
                y: {
                    beginAtZero: true,
                    // Dynamic Max Y-Axis value based on actual data
                    max: Math.max(1.5, ...netProdValues) + 0.2, 
                    grid: { color: '#f1f5f9' },
                    ticks: {
                        stepSize: 0.5,
                        font: { family: 'Inter', weight: '600', color: '#64748b' }
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: {
                        font: { family: 'Inter', weight: '600', size: 11, color: '#475569' },
                        autoSkip: false
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8,
                        font: { family: 'Inter', weight: '600', color: '#1e293b' },
                        padding: 20
                    }
                },
                tooltip: {
                    backgroundColor: '#1e293b',
                    titleFont: { family: 'Inter' },
                    bodyFont: { family: 'Inter' }
                },
                datalabels: {
                    anchor: 'end',
                    align: 'top',
                    formatter: function(value, context) {
                        if (value === 0) return ''; 
                        let formattedValue = (value * 100);
                        // parseFloat will automatically handle both exact decimals (13.56%) and whole numbers (100%) perfectly
                        return parseFloat(formattedValue.toFixed(2)) + '%';
                    },
                    color: function(context) { return context.dataset.backgroundColor; },
                    font: { family: 'Inter', weight: '700', size: 11 }
                }
            }
        }
    };

    new Chart(ctx, config);
</script>

</body>
</html>