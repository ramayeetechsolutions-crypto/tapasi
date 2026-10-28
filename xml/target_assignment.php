<br><br><br>
<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Check if user is logged in
if (!isset($_SESSION['uid'])) {
    header("Location: ../index.php");
    exit;
}

$current_user = $_SESSION['username'] ?? '';
$employee_username = $current_user;

// Get current user details
$sql = "SELECT `employee_name`,`employee_id`,`user_role_id` FROM `employee_master` WHERE `employee_username` = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("s", $employee_username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $current_user_name = $row['employee_name'];
    $current_user_id = $row['employee_id'] ?? 0;
    $current_user_role = $row['user_role_id'] ?? 0;
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

// Get TLs for dropdown
$tls = [];
if ($current_user_role == 3) {
    $stmt = $mysqli->prepare("SELECT employee_id, employee_name, employee_username FROM employee_master 
                             WHERE user_role_id = 3 AND status = 'Active' 
                             ORDER BY employee_name");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $tls[] = $row;
    }
    $stmt->close();
}

$selected_tl_id = isset($_GET['tl_id']) ? (int)$_GET['tl_id'] : 0;
if ($current_user_role == 3 && $selected_tl_id == 0) {
    $selected_tl_id = $current_user_id; 
} elseif ($current_user_role < 3) {
    $selected_tl_id = $current_user_id;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_matrix') {
    $targets_data = $_POST['targets'] ?? [];
    
    $mysqli->begin_transaction();
    try {
        foreach ($targets_data as $tm_id => $process_targets) {
            $tm_id = (int)$tm_id; 
            foreach ($process_targets as $proc_id => $limit) {
                $proc_id = (int)$proc_id;
                $limit = trim($limit);
                // Empty ah iruntha 0 nu vechikanum, appo thaan history delete aagathu
                $limit_val = ($limit === '') ? 0 : (int)$limit;
                
                if ($limit_val >= 0) {
                    // Check latest target for this employee and process
                    $check_stmt = $mysqli->prepare("SELECT id, target_limit FROM daily_targets_xml WHERE tm_id = ? AND process_id = ? ORDER BY id DESC LIMIT 1");
                    $check_stmt->bind_param("ii", $tm_id, $proc_id);
                    $check_stmt->execute();
                    $res = $check_stmt->get_result();

                    if ($res->num_rows > 0) {
                        $row = $res->fetch_assoc();
                        // Target number maari iruntha mattum puthu row (R1, R2) insert aagum
                        if ($row['target_limit'] != $limit_val) {
                            $ins_stmt = $mysqli->prepare("INSERT INTO daily_targets_xml (tl_id, tm_id, process_id, target_limit, assigned_by, assigned_date, status) VALUES (?, ?, ?, ?, ?, NOW(), 'Active')");
                            $ins_stmt->bind_param("iiiii", $selected_tl_id, $tm_id, $proc_id, $limit_val, $current_user_id);
                            $ins_stmt->execute();
                            $ins_stmt->close();
                        }
                    } else {
                        // First time assign pandrappa, 0 va iruntha insert panna theva illa (> 0 mattum)
                        if ($limit_val > 0) {
                            $ins_stmt = $mysqli->prepare("INSERT INTO daily_targets_xml (tl_id, tm_id, process_id, target_limit, assigned_by, assigned_date, status) VALUES (?, ?, ?, ?, ?, NOW(), 'Active')");
                            $ins_stmt->bind_param("iiiii", $selected_tl_id, $tm_id, $proc_id, $limit_val, $current_user_id);
                            $ins_stmt->execute();
                            $ins_stmt->close();
                        }
                    }
                    $check_stmt->close();
                }
            }
        }
        $mysqli->commit();
        $success_message = "Fixed targets saved successfully!";
    } catch (Exception $e) {
        $mysqli->rollback();
        $errors[] = "Error saving targets: " . $e->getMessage();
    }
}

// Fetch TMs
$tms = [];
$tl_name_for_query = $current_user_name;

if ($current_user_role == 3 && $selected_tl_id != $current_user_id) {
    foreach ($tls as $t) {
        if ($t['employee_id'] == $selected_tl_id) {
            $tl_name_for_query = $t['employee_name'];
            break;
        }
    }
}

$stmt = $mysqli->prepare("SELECT employee_id, employee_name, employee_username FROM employee_master WHERE reporting_manager = ? AND status = 'Active' ORDER BY employee_name");
$stmt->bind_param("s", $tl_name_for_query);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $tms[] = $row;
}
$stmt->close();

// Define Processes
$processes = [
    ['process_id' => 1, 'process_name' => 'MK JATS'],
    ['process_id' => 2, 'process_name' => 'JATS QC'],
    ['process_id' => 11, 'process_name' => 'PMC JATS'], 
    ['process_id' => 3, 'process_name' => 'PMC JATS QC'],
    ['process_id' => 4, 'process_name' => 'AOP'],
    ['process_id' => 5, 'process_name' => 'AOP QC'],
    ['process_id' => 6, 'process_name' => 'UQ JATS'], 
    ['process_id' => 12, 'process_name' => 'DOAJ /Biblat /PubMed'], 
    ['process_id' => 7, 'process_name' => 'M2'],
    ['process_id' => 13, 'process_name' => 'HTML'], 
    ['process_id' => 8, 'process_name' => 'Table'],
    ['process_id' => 9, 'process_name' => 'Table QC'],
    ['process_id' => 10, 'process_name' => 'Word']
];

// Fetch existing fixed target matrix data
$matrix_data = [];
$row_totals = [];
$tm_revisions = [];
$temp_history = [];

$selected_tm_id = isset($_GET['tm_id']) ? (int)$_GET['tm_id'] : 0;

if (!empty($tms) && !empty($processes)) {
    // Fetch order by ID for chronological history
    $target_query = "SELECT tm_id, process_id, target_limit, assigned_date FROM daily_targets_xml WHERE tl_id = ? ORDER BY id ASC";
    $stmt = $mysqli->prepare($target_query);
    $stmt->bind_param("i", $selected_tl_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    while ($row = $res->fetch_assoc()) {
        $tm = $row['tm_id'];
        $proc = $row['process_id'];
        $limit = $row['target_limit'];
        $date = $row['assigned_date'];

        $matrix_data[$tm][$proc] = ['limit' => $limit];
        $temp_history[$tm][$date][$proc] = $limit;
    }
    $stmt->close();

    foreach ($temp_history as $tm => $dates) {
        $current_state = []; // Maintains all previous targets
        foreach ($dates as $date => $procs) {
            foreach ($procs as $p => $l) {
                $current_state[$p] = $l; // Updates only the changed ones
            }
            $tm_revisions[$tm][] = $current_state; // Saves the full snapshot
        }
    }

    foreach ($matrix_data as $tm => $procs) {
        $row_totals[$tm] = 0;
        foreach ($procs as $data) {
            $row_totals[$tm] += $data['limit'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Fixed Targets</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-color: #f5f7f9;
            --card-bg: #ffffff;
            --text-main: #333333;
            --text-muted: #6c757d;
            --border-light: #eaedf1;
            --border-input: #dce1e7;
            --accent-blue: #0d6efd;
            --cell-active-bg: #eef5ff;
            --cell-active-border: #b6d4fe;
        }

        body {
            background-color: var(--bg-color);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: var(--text-main);
        }

        .main-container {
            padding: 24px;
            max-width: 100%;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .page-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-panel {
            background: var(--card-bg);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            margin-bottom: 24px;
            border-left: 4px solid var(--accent-blue);
        }
        .filter-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .form-control, .form-select {
            border-color: var(--border-input);
            box-shadow: none !important;
            font-size: 0.95rem;
        }

        .table-container {
            background: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            overflow-x: auto;
            overflow-y: auto;
            max-height: calc(100vh - 280px);
            border: 1px solid var(--border-light);
        }
        
        .table {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .table thead th {
            background-color: #5e6b77 !important;
            color: #ffffff !important;
            font-weight: 600;
            font-size: 0.85rem;
            border-bottom: 1px solid var(--border-light);
            border-right: 1px solid var(--border-light);
            padding: 12px 8px;
            text-align: center;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table thead .col-sno, 
        .table thead .col-total, 
        .table thead .col-name {
            z-index: 15;
            background-color: #5e6b77 !important;
            color: #ffffff !important;
        }

        .table tbody td {
            vertical-align: middle;
            border-bottom: 1px solid var(--border-light);
            border-right: 1px solid var(--border-light);
            padding: 8px;
        }

        .sticky-col { position: sticky; background: #fff; z-index: 5; }
        .col-sno { left: 0; width: 50px; text-align: center; font-weight: 600; color: #555; }
        .col-total { left: 50px; width: 70px; text-align: center; font-weight: 700; color: #2c3e50; }
        .col-name { 
            left: 120px; 
            min-width: 220px; 
            box-shadow: 2px 0 5px rgba(0,0,0,0.03);
            border-right: 2px solid var(--border-light) !important;
        }
        
        .table thead .col-sno, 
        .table thead .col-total, 
        .table thead .col-name {
            z-index: 15;
            background-color: #fafbfc;
        }

        .emp-name { font-weight: 600; color: #333; font-size: 0.95rem; line-height: 1.2; }
        .emp-id { font-size: 0.75rem; color: #888; margin-top: 2px; }

        .target-cell { padding: 6px !important; background: transparent; }
        
        .target-input {
            width: 100%;
            min-width: 65px;
            height: 34px;
            border: 1px solid var(--border-input); 
            border-radius: 4px;
            text-align: center;
            font-size: 0.95rem;
            color: #333;
            background-color: #ffffff;
            transition: all 0.2s ease;
            outline: none;
        }

        .target-input:hover { border-color: #adb5bd; }
        .target-input:focus {
            background-color: var(--cell-active-bg);
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.15);
        }

        .target-input.has-value {
            background-color: var(--cell-active-bg);
            border-color: var(--cell-active-border);
            color: #052c65;
            font-weight: 500;
        }

        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        input[type="number"] { -moz-appearance: textfield; }

        .action-footer {
            background: #fff;
            padding: 16px 24px;
            border-radius: 8px;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
        }
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: #c1c1c1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #a8a8a8; }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-container">
        
        <!-- Header -->
        <div class="page-header">
            <h2 class="page-title">
                <i class="fas fa-bullseye text-primary"></i> Employee Target
            </h2>
        </div>

        <?php if(isset($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="fas fa-check-circle me-2"></i><?= h($success_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Filter Panel -->
        <div class="filter-panel">
            <form method="get" class="row g-3 align-items-end">
                <?php if($current_user_role == 3): ?>
                <div class="col-md-4">
                    <label class="filter-label">Team Member</label>
                    <select name="tm_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">All Team Members (Latest Targets)</option>
                        <?php foreach($tms as $tm): ?>
                            <option value="<?= $tm['employee_id'] ?>" <?= $selected_tm_id == $tm['employee_id'] ? 'selected' : '' ?>>
                                <?= h($tm['employee_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary px-4 fw-semibold">
                        <i class="fas fa-search me-2"></i>Load Grid
                    </button>
                    <a href="target_assignment.php" class="btn btn-light border px-4 ms-2">Reset</a>
                </div>
            </form>
        </div>

        <?php if(empty($tms)): ?>
            <div class="text-center p-5 bg-white rounded shadow-sm">
                <i class="fas fa-users-slash fa-3x mb-3 text-muted"></i>
                <h5 class="text-secondary">No Team Members Found</h5>
            </div>
        <?php else: ?>
            <form method="post" id="matrixForm">
                <input type="hidden" name="action" value="save_matrix">
                
                <!-- Main Data Grid -->
                <div class="table-container">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th class="sticky-col col-sno">S.No</th>
                                <th class="sticky-col col-total" title="Total Assigned Targets">Total</th>
                                <th class="sticky-col col-name text-start ps-3">Team Member</th>
                                
                                <?php foreach($processes as $proc): ?>
                                    <th title="<?= h($proc['process_name']) ?>">
                                        <?= h($proc['process_name']) ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sno = 1; 

                            if ($selected_tm_id > 0) {
                                // ----- FILTER VIEW: SPECIFIC MEMBER HISTORY -----
                                $revisions = $tm_revisions[$selected_tm_id] ?? [];
                                $selected_tm_name = '';
                                foreach ($tms as $t) {
                                    if ($t['employee_id'] == $selected_tm_id) {
                                        $selected_tm_name = $t['employee_name']; break;
                                    }
                                }

                                if (empty($revisions)) $revisions[0] = [];

                                foreach ($revisions as $idx => $rev_data):
                                    $label = ($idx == 0) ? "Assigned Target" : "R" . $idx . ": Revised Target " . $idx;
                                    $is_latest = ($idx == count($revisions) - 1);
                                    
                                    $emp_total = 0;
                                    foreach ($rev_data as $val) $emp_total += $val;
                            ?>
                                <tr style="<?= !$is_latest ? 'background-color:#fcfcfc;' : '' ?>">
                                    <td class="sticky-col col-sno"><?= $sno++ ?></td>
                                    <td class="sticky-col col-total"><?= $emp_total > 0 ? $emp_total : '-' ?></td>
                                    <td class="sticky-col col-name ps-3">
                                        <div class="emp-name"><?= h($selected_tm_name) ?></div>
                                        <div class="emp-id text-primary fw-bold"><?= $label ?></div>
                                    </td>
                                    
                                    <?php foreach($processes as $proc): 
                                        $target_val = $rev_data[$proc['process_id']] ?? '';
                                        $display_val = ($target_val !== '') ? $target_val : '';
                                        $has_value_class = ($target_val !== '' && $target_val > 0) ? 'has-value' : '';
                                    ?>
                                    <td class="target-cell">
                                        <?php if ($is_latest): ?>
                                            <input type="number" name="targets[<?= $selected_tm_id ?>][<?= $proc['process_id'] ?>]" value="<?= h($display_val) ?>" class="target-input <?= $has_value_class ?>" min="0" placeholder="-" data-empid="<?= $selected_tm_id ?>">
                                        <?php else: ?>
                                            <input type="text" value="<?= h($display_val) ?>" class="target-input" disabled style="background-color: transparent; border: 1px dashed #ccc; color:#888;">
                                        <?php endif; ?>
                                    </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php 
                                endforeach; 
                            } else {
                                // ----- DEFAULT VIEW: ALL MEMBERS LATEST TARGET -----
                                foreach($tms as $tm): 
                                    $emp_total = $row_totals[$tm['employee_id']] ?? 0;
                            ?>
                                <tr>
                                    <td class="sticky-col col-sno"><?= $sno++ ?></td>
                                    <td class="sticky-col col-total row-total" id="total_<?= $tm['employee_id'] ?>">
                                        <?= $emp_total > 0 ? $emp_total : '-' ?>
                                    </td>
                                    <td class="sticky-col col-name ps-3">
                                        <div class="emp-name"><?= h($tm['employee_name']) ?></div>
                                        <div class="emp-id"><?= h($tm['employee_username']) ?></div>
                                    </td>
                                    
                                    <?php foreach($processes as $proc): 
                                        $cell_data = $matrix_data[$tm['employee_id']][$proc['process_id']] ?? null;
                                        $target_val = $cell_data ? $cell_data['limit'] : '';
                                        $display_val = ($target_val !== '') ? $target_val : '';
                                        $has_value_class = ($target_val !== '' && $target_val > 0) ? 'has-value' : '';
                                    ?>
                                    <td class="target-cell">
                                        <input type="number" name="targets[<?= $tm['employee_id'] ?>][<?= $proc['process_id'] ?>]" value="<?= h($display_val) ?>" class="target-input <?= $has_value_class ?>" min="0" placeholder="-" data-empid="<?= $tm['employee_id'] ?>">
                                    </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php 
                                endforeach; 
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Bottom Action Bar -->
                <div class="action-footer">
                    <div class="text-muted small">
                        <i class="fas fa-info-circle me-1 text-primary"></i> 
                        Empty cells clear targets. The table updates totals automatically.
                    </div>
                    <button type="submit" class="btn btn-primary px-5 py-2 fw-semibold">
                        <i class="fas fa-save me-2"></i> Save Fixed Assignments
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            // Auto-hide success alerts
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert-success');
                alerts.forEach(alert => {
                    if(typeof bootstrap !== 'undefined'){
                        new bootstrap.Alert(alert).close();
                    }
                });
            }, 4000);

            // Handle Input Visuals and Live Totals
            const inputs = document.querySelectorAll('.target-input');
            
            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    // Toggle highlight class based on value
                    if(this.value !== '' && parseFloat(this.value) > 0) {
                        this.classList.add('has-value');
                    } else {
                        this.classList.remove('has-value');
                    }
                    
                    // Calculate live row totals
                    const empId = this.getAttribute('data-empid');
                    const rowInputs = document.querySelectorAll(`.target-input[data-empid="${empId}"]`);
                    let total = 0;
                    
                    rowInputs.forEach(inp => {
                        const val = parseFloat(inp.value);
                        if(!isNaN(val)) total += val;
                    });
                    
                    const totalCell = document.getElementById(`total_${empId}`);
                    if(totalCell) {
                        totalCell.textContent = total > 0 ? total : '-';
                    }
                });
            });
        });
    </script>
    <script>
document.addEventListener("DOMContentLoaded", function() {
    const firstRowInputs = document.querySelectorAll('table tbody tr:first-child input');

    firstRowInputs.forEach(function(input, columnIndex) {
        
        // Change from 'input' to 'dblclick'
        input.addEventListener('dblclick', function() {
            let valueToCopy = this.value;
            
            if(confirm("Copy '" + valueToCopy + "' to all rows in this column?")) {
                let allRows = document.querySelectorAll('table tbody tr');
                for (let i = 1; i < allRows.length; i++) {
                    let targetInput = allRows[i].querySelectorAll('input')[columnIndex];
                    if (targetInput) {
                        targetInput.value = valueToCopy;
                    }
                }
            }
        });
        
        // Optional: Add a title tooltip to let users know about this feature
        input.title = "Double-click to apply this value to all rows below";
    });
});
</script>
</body>
</html>