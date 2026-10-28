<?php
require 'db.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00') return '—';
    return date('d-m-Y', strtotime($d));
}

function redirect_with($params = []) {
    $base = 'awards.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $award_name = trim($_POST['award_name'] ?? '');
        $award_date = trim($_POST['award_date'] ?? '');
        $venue = trim($_POST['venue'] ?? '');
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $joined_date = trim($_POST['joined_date'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $joined_team = trim($_POST['joined_team'] ?? '');
        $current_team = trim($_POST['current_team'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        
        // Get employee name from employee_id
        $name = '';
        if ($employee_id > 0) {
            $empStmt = $mysqli->prepare("SELECT employee_name FROM employee_master WHERE employee_id = ?");
            $empStmt->bind_param("i", $employee_id);
            $empStmt->execute();
            $empStmt->bind_result($name);
            $empStmt->fetch();
            $empStmt->close();
        }
        
        // Basic validation
        if ($award_name === '') {
            redirect_with(['msg' => 'Award Name is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Insert new award
            $stmt = $mysqli->prepare("INSERT INTO tbl_awards (award_name, award_date, venue, name, joined_date, designation, joined_team, current_team, remarks, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->bind_param("ssssssssss", $award_name, $award_date, $venue, $name, $joined_date, $designation, $joined_team, $current_team, $remarks, $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Award record added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding award: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Update award
                $sql = "UPDATE tbl_awards SET 
                award_name = ?, 
                award_date = ?, 
                venue = ?, 
                name = ?, 
                joined_date = ?, 
                designation = ?, 
                joined_team = ?, 
                current_team = ?, 
                remarks = ?, 
                updated_at = NOW(), 
                updated_ip = ? 
                WHERE id = ?";

                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssssssssssi", $award_name, $award_date, $venue, $name, $joined_date, $designation, $joined_team, $current_team, $remarks, $_SERVER['REMOTE_ADDR'], $id);
        
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Award record updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating award: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            // Delete award
            $del = $mysqli->prepare("DELETE FROM tbl_awards WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Award record deleted", 'type' => 'warning']);
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editAward = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_awards WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editAward = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editAward) $mode = 'view';
}

// Get employees and teams for dropdowns
$employees = $mysqli->query("SELECT employee_id, employee_name, employee_username FROM employee_master WHERE status = 'Active' ORDER BY employee_name ASC");
$teams = $mysqli->query("SELECT team_id, team_name FROM team_master WHERE status = 'Active' ORDER BY team_name ASC");

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$team_filter = trim($_GET['team_filter'] ?? '');
$award_filter = trim($_GET['award_filter'] ?? '');
$sortBy = $_GET['sort_by'] ?? 'award_date';
$sortOrder = $_GET['sort_order'] ?? 'DESC';

// Validate sort column
$allowedSorts = ['id', 'award_name', 'award_date', 'name', 'joined_date'];
if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'award_date';
}

// Validate sort order
if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
    $sortOrder = 'DESC';
}

// Get unique award names for filter dropdown
$award_names = $mysqli->query("SELECT DISTINCT award_name FROM tbl_awards ORDER BY award_name");

// Build query
$sql = "SELECT * FROM tbl_awards WHERE 1=1";

$params = [];
$types = '';

if ($q !== '') {
    $sql .= " AND (award_name LIKE ? OR name LIKE ? OR venue LIKE ? OR designation LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

// Add team filter (searches in both joined_team and current_team)
if ($team_filter !== '') {
    $sql .= " AND (joined_team LIKE ? OR current_team LIKE ?)";
    $team_like = "%$team_filter%";
    $params[] = $team_like;
    $params[] = $team_like;
    $types .= 'ss';
}

// Add award name filter
if ($award_filter !== '') {
    $sql .= " AND award_name = ?";
    $params[] = $award_filter;
    $types .= 's';
}

$sql .= " ORDER BY $sortBy $sortOrder";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $awards = $stmt->get_result();
} else {
    $awards = $mysqli->query($sql);
}

// Group awards by award name and date
$groupedAwards = [];
if ($awards) {
    while ($row = $awards->fetch_assoc()) {
        $key = $row['award_name'] . '_' . $row['award_date'];
        if (!isset($groupedAwards[$key])) {
            $groupedAwards[$key] = [
                'award_name' => $row['award_name'],
                'award_date' => $row['award_date'],
                'venue' => $row['venue'],
                'recipients' => []
            ];
        }
        $groupedAwards[$key]['recipients'][] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Awards Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1800px;
            margin: 0 auto;
        }
        .award-section {
            margin-bottom: 30px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
        }
        .award-header {
            background: linear-gradient(135deg, #FFB900, #FF9500);
            color: white;
            padding: 15px 20px;
        }
        .award-header h5 {
            margin: 0;
            font-weight: 600;
        }
        .award-header p {
            margin: 5px 0 0 0;
            font-size: 14px;
            opacity: 0.9;
        }
        .table th {
            background-color: #2c3e50;
            color: white;
            border: none;
            font-size: 12px;
            white-space: nowrap;
        }
        .table td {
            vertical-align: middle;
            font-size: 13px;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
        }
        .select2-container .select2-selection--single {
            height: 38px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 38px;
        }
        .filter-badge {
            background-color: #e9ecef;
            padding: 5px 10px;
            border-radius: 20px;
            margin-right: 5px;
            margin-bottom: 5px;
            display: inline-block;
        }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
        
        <!-- Add/Edit Form -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-trophy me-2"></i><?= $mode === 'edit' ? 'Edit Award Record' : 'Add New Award' ?></h4>
            <a href="awards.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editAward['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="award_name" class="form-label">Award Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="award_name" name="award_name" 
                                       value="<?= h($editAward['award_name'] ?? '') ?>" 
                                       placeholder="Everlasting Excellence 2025" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="award_date" class="form-label">Award Date</label>
                                <input type="date" class="form-control" id="award_date" name="award_date"
                                       value="<?= h($editAward['award_date'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="venue" class="form-label">Venue</label>
                                <input type="text" class="form-control" id="venue" name="venue"
                                       value="<?= h($editAward['venue'] ?? '') ?>" 
                                       placeholder="Hotel Lakshmi">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="employee_id" class="form-label">Employee <span class="text-danger">*</span></label>
                                <select class="form-select select2" id="employee_id" name="employee_id" required>
                                    <option value="">Select Employee</option>
                                    <?php 
                                    if($employees):
                                        $employees->data_seek(0);
                                        while($emp = $employees->fetch_assoc()): ?>
                                            <option value="<?= $emp['employee_id'] ?>"
                                                <?= (isset($editAward['name']) && $editAward['name'] === $emp['employee_name']) ? 'selected' : '' ?>>
                                                <?= h($emp['employee_name']) ?> (<?= h($emp['employee_username']) ?>)
                                            </option>
                                        <?php endwhile;
                                    endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="joined_date" class="form-label">Joined Date</label>
                                <input type="date" class="form-control" id="joined_date" name="joined_date"
                                       value="<?= h($editAward['joined_date'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="designation" class="form-label">Designation</label>
                                <input type="text" class="form-control" id="designation" name="designation"
                                       value="<?= h($editAward['designation'] ?? '') ?>" 
                                       placeholder="Team Leader">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="joined_team" class="form-label">Joined Team</label>
                                <select class="form-select" id="joined_team" name="joined_team">
                                    <option value="">Select Team</option>
                                    <?php 
                                    if($teams):
                                        $teams->data_seek(0);
                                        while($team = $teams->fetch_assoc()): ?>
                                            <option value="<?= $team['team_name'] ?>"
                                                <?= (isset($editAward['joined_team']) && $editAward['joined_team'] == $team['team_name']) ? 'selected' : '' ?>>
                                                <?= h($team['team_name']) ?>
                                            </option>
                                        <?php endwhile;
                                    endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="current_team" class="form-label">Current Team</label>
                                <select class="form-select" id="current_team" name="current_team">
                                    <option value="">Select Team</option>
                                    <?php 
                                    if($teams):
                                        $teams->data_seek(0);
                                        while($team = $teams->fetch_assoc()): ?>
                                            <option value="<?= $team['team_name'] ?>"
                                                <?= (isset($editAward['current_team']) && $editAward['current_team'] == $team['team_name']) ? 'selected' : '' ?>>
                                                <?= h($team['team_name']) ?>
                                            </option>
                                        <?php endwhile;
                                    endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="remarks" class="form-label">Remarks</label>
                                <textarea class="form-control" id="remarks" name="remarks" rows="1"><?= h($editAward['remarks'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Award' : 'Save Award' ?>
                        </button>
                        <a href="awards.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-trophy me-2"></i>Employee Awards & Recognition</h4>
            <a href="awards.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add Award
            </a>
        </div>

        <!-- Search and Filter Section -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control" 
                               placeholder="Search awards, employees..." value="<?= h($q) ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <select name="team_filter" class="form-select">
                            <option value="">All Teams</option>
                            <?php 
                            // Get unique teams from both joined_team and current_team
                            $teams_query = $mysqli->query("SELECT DISTINCT joined_team as team FROM tbl_awards WHERE joined_team != '' AND joined_team IS NOT NULL UNION SELECT DISTINCT current_team FROM tbl_awards WHERE current_team != '' AND current_team IS NOT NULL ORDER BY team");
                            if($teams_query):
                                while($team_row = $teams_query->fetch_assoc()): 
                                    $team_name = $team_row['team'];
                                    if(!empty($team_name)):
                            ?>
                                <option value="<?= h($team_name) ?>" <?= $team_filter === $team_name ? 'selected' : '' ?>>
                                    <?= h($team_name) ?>
                                </option>
                            <?php 
                                    endif;
                                endwhile;
                            endif; 
                            ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <select name="award_filter" class="form-select">
                            <option value="">All Awards</option>
                            <?php 
                            if($award_names):
                                while($award_row = $award_names->fetch_assoc()): 
                            ?>
                                <option value="<?= h($award_row['award_name']) ?>" <?= $award_filter === $award_row['award_name'] ? 'selected' : '' ?>>
                                    <?= h($award_row['award_name']) ?>
                                </option>
                            <?php 
                                endwhile;
                            endif; 
                            ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <select name="sort_by" class="form-select">
                            <option value="award_date" <?= $sortBy === 'award_date' ? 'selected' : '' ?>>Sort by Award Year</option>
                            <option value="award_name" <?= $sortBy === 'award_name' ? 'selected' : '' ?>>Sort by Award Name</option>
                            <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Sort by Employee Name</option>
                            <option value="joined_date" <?= $sortBy === 'joined_date' ? 'selected' : '' ?>>Sort by Joined Date</option>
                        </select>
                    </div>
                    
                    <!--div class="col-md-1">
                        <select name="sort_order" class="form-select">
                            <option value="DESC" <?= $sortOrder === 'DESC' ? 'selected' : '' ?>>Desc</option>
                            <option value="ASC" <?= $sortOrder === 'ASC' ? 'selected' : '' ?>>Asc</option>
                        </select>
                    </div-->
                    
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-1"></i> Filter
                        </button>
                    </div>
                    
                    <?php if($team_filter || $award_filter || $q): ?>
                    <div class="col-12 mt-2">
                        <div class="d-flex flex-wrap align-items-center">
                            <span class="me-2">Active filters:</span>
                            <?php if($q): ?>
                                <span class="filter-badge">
                                    Search: <?= h($q) ?>
                                </span>
                            <?php endif; ?>
                            <?php if($team_filter): ?>
                                <span class="filter-badge">
                                    Team: <?= h($team_filter) ?>
                                </span>
                            <?php endif; ?>
                            <?php if($award_filter): ?>
                                <span class="filter-badge">
                                    Award: <?= h($award_filter) ?>
                                </span>
                            <?php endif; ?>
                            <a href="awards.php" class="btn btn-outline-secondary btn-sm ms-2">
                                <i class="fas fa-times me-1"></i> Clear All
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Awards Display (Grouped by Award Name & Date) -->
        <?php if(!empty($groupedAwards)): ?>
            <?php foreach($groupedAwards as $awardGroup): ?>
                <div class="award-section mb-4 p-3 border rounded shadow-sm">
                    <div class="award-header py-2 px-3 mb-3 rounded" style="background: linear-gradient(135deg, #FFB900, #FF9500); color: white;">
                        <h5 class="mb-1"><?= h($awardGroup['award_name']) ?></h5>
                        <small><i class="fas fa-calendar me-2"></i><?= formatDate($awardGroup['award_date']) ?> | <i class="fas fa-map-marker-alt me-2"></i><?= h($awardGroup['venue']) ?></small>
                    </div>

                    <div class="row row-cols-1 row-cols-md-3 g-3">
                        <?php foreach($awardGroup['recipients'] as $recipient): ?>
                            <div class="col">
                                <div class="card h-100 shadow-sm">
                                    <div class="card-body p-3">
                                        <h6 class="card-title fw-bold mb-1"><?= h($recipient['name']) ?></h6>
                                        <p class="mb-1"><strong>Joined Date:</strong> <?= formatDate($recipient['joined_date']) ?></p>
                                        <p class="mb-1"><strong>Designation:</strong> <?= h($recipient['designation']) ?></p>
                                        <p class="mb-1"><strong>Joined Team:</strong> <?= h($recipient['joined_team']) ?></p>
                                        <p class="mb-1"><strong>Current Team:</strong> <?= h($recipient['current_team']) ?></p>
                                        <?php if(!empty($recipient['remarks'])): ?>
                                            <p class="mb-0"><em><?= h($recipient['remarks']) ?></em></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-footer text-end p-2">
                                        <a href="awards.php?mode=edit&id=<?= (int)$recipient['id'] ?>" class="btn btn-sm btn-warning me-1" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete award record for <?= h($recipient['name']) ?>?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$recipient['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card text-center py-5">
                <i class="fas fa-trophy fa-3x text-muted mb-3"></i>
                <h5>No awards found</h5>
                <p class="mb-0">Start recognizing your employees' achievements!</p>
                <a href="awards.php?mode=add" class="btn btn-primary mt-2">
                    <i class="fas fa-plus me-1"></i> Add Award
                </a>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Select2 for employee dropdown
            $('.select2').select2({
                placeholder: 'Search employee...',
                allowClear: true
            });
            
            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert.isConnected) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                }, 5000);
            });
        });
    </script>
</body>
</html>