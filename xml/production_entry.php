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

// In this page, the current user IS the Team Member (TM)
$current_user_id = $_SESSION['uid']; // Ensure this matches your login session key for employee_id
$current_user_name = $_SESSION['username'] ?? 'User';

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function getWeekDates($week_number, $year = null) {
    if ($year === null) $year = date('Y');
    $first_day_of_year = date('Y-m-d', strtotime($year . '-01-01'));
    $day_of_week = date('w', strtotime($first_day_of_year));
    $first_monday = strtotime($first_day_of_year . ' +' . ((8 - $day_of_week) % 7) . ' days');
    $week_start = strtotime('+' . (($week_number - 1) * 7) . ' days', $first_monday);
    $week_end = strtotime('+4 days', $week_start);
    return ['from_date' => date('Y-m-d', $week_start), 'to_date' => date('Y-m-d', $week_end)];
}

// Determine active week and year
$week_filter = $_GET['week_filter'] ?? 'W' . date('W');
$year_filter = (int)($_GET['year_filter'] ?? date('Y'));
preg_match('/(?:W)?(\d+)/i', $week_filter, $matches);
$active_week = isset($matches[1]) ? (int)$matches[1] : (int)date('W');

// Handle Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_achievements') {
    $achievements = $_POST['achieved'] ?? [];
    
    $mysqli->begin_transaction();
    try {
        foreach ($achievements as $target_id => $value) {
            $target_id = (int)$target_id;
            $achieved_val = (int)$value;

            // Update only if the target record belongs to the logged-in TM
            $upd_stmt = $mysqli->prepare("UPDATE weekly_targets_xml SET achieved_target = ? WHERE id = ? AND tm_id = ?");
            $upd_stmt->bind_param("iii", $achieved_val, $target_id, $current_user_id);
            $upd_stmt->execute();
            $upd_stmt->close();
        }
        $mysqli->commit();
        $success_message = "Your progress has been updated successfully!";
    } catch (Exception $e) {
        $mysqli->rollback();
        $errors[] = "Error updating progress: " . $e->getMessage();
    }
}

// Fetch Targets assigned to THIS user for the selected week
$my_targets = [];
$query = "SELECT w.id, w.process_id, p.process_name, w.target_limit, w.achieved_target 
          FROM weekly_targets_xml w
          JOIN process_master p ON w.process_id = p.process_id
          WHERE w.tm_id = ? AND w.week_number = ? AND w.year = ?
          ORDER BY p.process_name ASC";

$stmt = $mysqli->prepare($query);
$stmt->bind_param("iii", $current_user_id, $active_week, $year_filter);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $my_targets[] = $row;
}
$stmt->close();

$active_week_dates = getWeekDates($active_week, $year_filter);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Weekly Progress</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .target-card { border-radius: 12px; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08); transition: transform 0.2s; }
        .target-card:hover { transform: translateY(-3px); }
        .process-title { color: #2c3e50; font-weight: 700; border-bottom: 2px solid #eef2f7; padding-bottom: 10px; margin-bottom: 15px; }
        .stat-box { background: #fdfdfd; border-radius: 8px; padding: 10px; border: 1px solid #eee; }
        .progress { height: 8px; border-radius: 4px; }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>

    <div class="container py-4">
        <div class="row mb-4 align-items-center">
            <div class="col-md-6">
                <h3 class="mb-0"><i class="fas fa-chart-line text-primary me-2"></i>My Weekly Targets</h3>
                <p class="text-muted">Logged in as: <strong><?= h($current_user_name) ?></strong></p>
            </div>
            <div class="col-md-6 text-md-end">
                <form method="get" class="d-flex justify-content-md-end gap-2">
                    <input type="number" name="week_filter" class="form-control" style="width: 80px;" value="<?= $active_week ?>" min="1" max="53">
                    <select name="year_filter" class="form-select" style="width: 100px;">
                        <option value="2024" <?= $year_filter == 2024 ? 'selected' : '' ?>>2024</option>
                        <option value="2025" <?= $year_filter == 2025 ? 'selected' : '' ?>>2025</option>
                    </select>
                    <button type="submit" class="btn btn-dark">Go</button>
                </form>
            </div>
        </div>

        <?php if(isset($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                <i class="fas fa-check-circle me-2"></i><?= h($success_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="alert alert-info border-0 shadow-sm d-flex justify-content-between align-items-center">
            <div>
                <i class="fas fa-calendar-week me-2"></i>
                Reporting for <strong>Week <?= $active_week ?></strong> 
                <span class="ms-2 opacity-75">(<?= date('d M', strtotime($active_week_dates['from_date'])) ?> - <?= date('d M Y', strtotime($active_week_dates['to_date'])) ?>)</span>
            </div>
        </div>

        <?php if(empty($my_targets)): ?>
            <div class="text-center py-5 bg-white rounded shadow-sm">
                <img src="https://illustrations.popsy.co/gray/no-messages.svg" alt="No data" style="width: 150px;" class="mb-3">
                <h5 class="text-muted">No targets assigned to you for this week.</h5>
                <p class="small text-secondary">Contact your Team Leader if you believe this is an error.</p>
            </div>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="action" value="update_achievements">
                
                <div class="row g-4">
                    <?php foreach($my_targets as $t): 
                        $pct = ($t['target_limit'] > 0) ? min(100, round(($t['achieved_target'] / $t['target_limit']) * 100)) : 0;
                        $color = ($pct >= 100) ? 'success' : ($pct > 50 ? 'primary' : 'warning');
                    ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card target-card h-100">
                            <div class="card-body">
                                <h5 class="process-title text-truncate" title="<?= h($t['process_name']) ?>">
                                    <?= h($t['process_name']) ?>
                                </h5>
                                
                                <div class="row g-2 mb-3">
                                    <div class="col-6 text-center stat-box">
                                        <small class="text-uppercase text-muted d-block" style="font-size: 0.65rem;">Target</small>
                                        <span class="h5 mb-0 fw-bold"><?= $t['target_limit'] ?></span>
                                    </div>
                                    <div class="col-6 text-center stat-box">
                                        <small class="text-uppercase text-muted d-block" style="font-size: 0.65rem;">Remaining</small>
                                        <span class="h5 mb-0 fw-bold text-danger">
                                            <?= max(0, $t['target_limit'] - $t['achieved_target']) ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-secondary">Update Completed Units:</label>
                                    <div class="input-group">
                                        <input type="number" 
                                               name="achieved[<?= $t['id'] ?>]" 
                                               class="form-control form-control-lg border-<?= $color ?>" 
                                               value="<?= h($t['achieved_target']) ?>" 
                                               min="0">
                                        <span class="input-group-text bg-<?= $color ?> text-white border-<?= $color ?>">
                                            <i class="fas fa-pencil-alt"></i>
                                        </span>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <div class="d-flex justify-content-between mb-1 small">
                                        <span>Progress</span>
                                        <span class="fw-bold"><?= $pct ?>%</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar bg-<?= $color ?>" role="progressbar" style="width: <?= $pct ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-5 mb-5 text-center">
                    <button type="submit" class="btn btn-primary btn-lg px-5 rounded-pill shadow">
                        <i class="fas fa-cloud-upload-alt me-2"></i> Submit Weekly Progress
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>