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

// Get current user details
$sql = "SELECT `employee_name`, `employee_id`, `user_role_id` FROM `employee_master` WHERE `employee_username` = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("s", $current_user);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $current_user_name = $row['employee_name'];
    $current_user_id = $row['employee_id'] ?? 0;
}
$stmt->close();

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

// Set target date filter (defaults to today)
$target_date = $_GET['target_date'] ?? date('Y-m-d');

// Handle form submission (Saving Completed Counts)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_production') {
    $post_date = $_POST['target_date'];
    $completed_counts = $_POST['completed_counts'] ?? [];
    
    $mysqli->begin_transaction();
    try {
        foreach ($completed_counts as $proc_id => $achieved) {
            $proc_id = (int)$proc_id;
            $achieved = trim($achieved);
            
            // If empty, treat as 0
            $achieved_val = ($achieved === '') ? 0 : (int)$achieved;
            
            if ($achieved_val >= 0) {
                // Check if a daily production record already exists for THIS DATE in the NEW TABLE
                $check_stmt = $mysqli->prepare("SELECT id FROM daily_achieved_xml WHERE tm_id = ? AND process_id = ? AND target_date = ?");
                $check_stmt->bind_param("iis", $current_user_id, $proc_id, $post_date);
                $check_stmt->execute();
                $res = $check_stmt->get_result();
                
                if ($res->num_rows > 0) {
                    // Update existing daily achieved record
                    $row = $res->fetch_assoc();
                    $upd_stmt = $mysqli->prepare("UPDATE daily_achieved_xml SET achieved_target = ? WHERE id = ?");
                    $upd_stmt->bind_param("ii", $achieved_val, $row['id']);
                    $upd_stmt->execute();
                    $upd_stmt->close();
                } else {
                    // Insert new daily achieved record into the NEW TABLE
                    if ($achieved_val > 0) {
                        $ins_stmt = $mysqli->prepare("INSERT INTO daily_achieved_xml (tm_id, process_id, achieved_target, target_date, entry_date, status) VALUES (?, ?, ?, ?, NOW(), 'Active')");
                        $ins_stmt->bind_param("iiis", $current_user_id, $proc_id, $achieved_val, $post_date);
                        $ins_stmt->execute();
                        $ins_stmt->close();
                    }
                }
                $check_stmt->close();
            }
        }
        $mysqli->commit();
        $formatted_date = date('d M Y', strtotime($post_date));
        $success_message = "Your completed job counts for {$formatted_date} have been saved successfully!";
    } catch (Exception $e) {
        $mysqli->rollback();
        $errors[] = "Error saving production: " . $e->getMessage();
    }
}

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

// Combine Fixed Targets with Daily Production Data
$my_data = [];

// 1. Fetch Fixed Targets assigned by the TL from `daily_targets_xml`
$stmt_fixed = $mysqli->prepare("SELECT process_id, MAX(target_limit) as target_limit FROM daily_targets_xml WHERE tm_id = ? GROUP BY process_id");
$stmt_fixed->bind_param("i", $current_user_id);
$stmt_fixed->execute();
$res_fixed = $stmt_fixed->get_result();

while ($row = $res_fixed->fetch_assoc()) {
    $my_data[$row['process_id']] = [
        'limit' => $row['target_limit'],
        'achieved' => 0
    ];
}
$stmt_fixed->close();

// 2. Fetch Completed Targets (Achieved) for the Selected Date from `daily_achieved_xml`
$stmt_daily = $mysqli->prepare("SELECT process_id, achieved_target FROM daily_achieved_xml WHERE target_date = ? AND tm_id = ?");
$stmt_daily->bind_param("si", $target_date, $current_user_id);
$stmt_daily->execute();
$res_daily = $stmt_daily->get_result();

while ($row = $res_daily->fetch_assoc()) {
    if (!isset($my_data[$row['process_id']])) {
        $my_data[$row['process_id']] = ['limit' => 0];
    }
    $my_data[$row['process_id']]['achieved'] = $row['achieved_target'];
}
$stmt_daily->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Production Entry</title>
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
        }

        body {
            background-color: var(--bg-color);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: var(--text-main);
        }

        .main-container {
            padding: 24px;
            max-width: 900px;
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
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .table-container {
            background: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            border: 1px solid var(--border-light);
            overflow: hidden;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table thead th {
            background-color: #fafbfc;
            color: #495057;
            font-weight: 600;
            font-size: 0.9rem;
            padding: 16px;
            border-bottom: 2px solid var(--border-light);
        }

        .table tbody td {
            vertical-align: middle;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-light);
            font-size: 0.95rem;
        }

        .process-name {
            font-weight: 600;
            color: #2c3e50;
        }

        .target-badge {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            color: #495057;
            display: inline-block;
            min-width: 60px;
            text-align: center;
        }

        .target-input {
            width: 120px;
            height: 40px;
            border: 2px solid var(--border-input); 
            border-radius: 6px;
            text-align: center;
            font-size: 1rem;
            font-weight: 600;
            color: #0d6efd;
            transition: all 0.2s ease;
            outline: none;
        }

        .target-input:focus {
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.15);
            background-color: var(--cell-active-bg);
        }

        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        input[type="number"] { -moz-appearance: textfield; }

        .action-footer {
            background: #fff;
            padding: 20px 24px;
            border-top: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .total-row td {
            background-color: #fafbfc;
            font-weight: 700;
            font-size: 1.1rem;
            color: #2c3e50;
        }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-container">
        
        <!-- Header -->
        <div class="page-header">
            <h2 class="page-title">
                <i class="fas fa-clipboard-check text-primary"></i> Daily Production Entry
            </h2>
        </div>

        <?php if(isset($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="fas fa-check-circle me-2"></i><?= h($success_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Top Panel: Date Filter & User Info -->
        <div class="filter-panel">
            <div>
                <h5 class="mb-1 text-dark fw-bold"><?= h($current_user_name) ?></h5>
                <span class="text-muted small">Enter your completed jobs for the selected date.</span>
            </div>
            
            <form method="get" class="d-flex align-items-center gap-3">
                <div>
                    <label class="text-muted small fw-bold text-uppercase mb-1 d-block">Select Date</label>
                    <input type="date" name="target_date" class="form-control fw-bold shadow-sm" 
                           value="<?= h($target_date) ?>" onchange="this.form.submit()">
                </div>
            </form>
        </div>

        <!-- Main Data Grid -->
        <form method="post">
            <input type="hidden" name="action" value="save_production">
            <input type="hidden" name="target_date" value="<?= h($target_date) ?>">
            
            <div class="table-container">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th style="width: 50%;">Process Name</th>
                            <th style="width: 25%; text-align: center;">Fixed Assigned Target</th>
                            <th style="width: 25%; text-align: center;">Completed Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_target = 0;
                        $total_achieved = 0;
                        
                        foreach($processes as $proc): 
                            $proc_id = $proc['process_id'];
                            $data = $my_data[$proc_id] ?? null;
                            
                            $limit = $data ? (int)$data['limit'] : 0;
                            $achieved = $data ? (int)$data['achieved'] : 0;
                            
                            $total_target += $limit;
                            $total_achieved += $achieved;
                        ?>
                        <tr>
                            <td class="process-name">
                                <i class="fas fa-layer-group text-muted me-2"></i> 
                                <?= h($proc['process_name']) ?>
                            </td>
                            <td class="text-center">
                                <span class="target-badge" title="Target Assigned by TL">
                                    <?= $limit > 0 ? $limit : '-' ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <input type="number" 
                                       name="completed_counts[<?= $proc_id ?>]" 
                                       value="<?= $achieved > 0 ? $achieved : '' ?>" 
                                       class="target-input count-input" 
                                       min="0"
                                       placeholder="0">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <!-- Total Row -->
                        <tr class="total-row">
                            <td class="text-end pe-4">Total:</td>
                            <td class="text-center text-primary"><?= $total_target > 0 ? $total_target : '-' ?></td>
                            <td class="text-center text-success" id="grand_total"><?= $total_achieved ?></td>
                        </tr>
                    </tbody>
                </table>
                
                <!-- Bottom Action Bar -->
                <div class="action-footer">
                    <div class="text-muted small">
                        <i class="fas fa-info-circle me-1 text-primary"></i> 
                        Update your counts and click save. Leave blank if no jobs were completed.
                    </div>
                    <button type="submit" class="btn btn-primary px-4 py-2 fw-semibold shadow-sm">
                        <i class="fas fa-save me-2"></i> Save Production
                    </button>
                </div>
            </div>
        </form>
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

            // Live calculation of grand total
            const inputs = document.querySelectorAll('.count-input');
            const totalDisplay = document.getElementById('grand_total');
            
            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    let total = 0;
                    inputs.forEach(inp => {
                        const val = parseInt(inp.value);
                        if(!isNaN(val)) total += val;
                    });
                    totalDisplay.textContent = total;
                });
            });
        });
    </script>
</body>
</html>