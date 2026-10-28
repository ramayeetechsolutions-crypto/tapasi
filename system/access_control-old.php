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

function redirect_with($params = []) {
    $base = 'access_control.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $access_no = trim($_POST['access_no'] ?? '');
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $team_id = (int)($_POST['team_id'] ?? 0);
        $card_fee = trim($_POST['card_fee'] ?? '100');
        
        // Get Type and Date
        $issue_type = $_POST['issue_type'] ?? 'Card Fee';
        $issue_date = $_POST['issue_date'] ?? date('Y-m-d');

        // Fetch employee name and username
        $employee_name = '';
        $emp_username = '';
        if ($employee_id > 0) {
            $empStmt = $mysqli->prepare("SELECT employee_name, employee_username FROM employee_master WHERE employee_id = ?");
            $empStmt->bind_param("i", $employee_id);
            $empStmt->execute();
            $empResult = $empStmt->get_result();
            if ($empRow = $empResult->fetch_assoc()) {
                $employee_name = $empRow['employee_name'];
                $emp_username = $empRow['employee_username'];
            }
            $empStmt->close();
        }

        // Fetch team name
        $team_name = '';
        if ($team_id > 0) {
            $teamStmt = $mysqli->prepare("SELECT team_name FROM team_master WHERE team_id = ?");
            $teamStmt->bind_param("i", $team_id);
            $teamStmt->execute();
            $teamResult = $teamStmt->get_result();
            if ($teamRow = $teamResult->fetch_assoc()) {
                $team_name = $teamRow['team_name'];
            }
            $teamStmt->close();
        }

        // Basic validation
        if ($access_no === '') {
            redirect_with(['msg' => 'Access No is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Check exists logic
            $checkStmt = $mysqli->prepare("SELECT id FROM tbl_access_cards WHERE access_no = ?");
            $checkStmt->bind_param("s", $access_no);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                 redirect_with(['msg' => "Access No '$access_no' already exists!", 'type' => 'danger']);
            }
            $checkStmt->close();
            
            // INSERT with issue_type and issue_date
            $stmt = $mysqli->prepare("INSERT INTO tbl_access_cards (access_no, name, team, emp_id, card_fee, issue_type, issue_date, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->bind_param("ssssdsss", $access_no, $employee_name, $team_name, $emp_username, $card_fee, $issue_type, $issue_date, $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Access card added successfully", 'type' => 'success']);
            }
            $stmt->close();
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                // UPDATE with issue_type and issue_date
                $sql = "UPDATE tbl_access_cards SET 
                        access_no = ?, name = ?, team = ?, emp_id = ?, card_fee = ?, 
                        issue_type = ?, issue_date = ?, 
                        updated_at = NOW(), updated_ip = ? 
                        WHERE id = ?";
                
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssssdsssi", $access_no, $employee_name, $team_name, $emp_username, $card_fee, $issue_type, $issue_date, $_SERVER['REMOTE_ADDR'], $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Access card updated successfully", 'type' => 'success']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            $del = $mysqli->prepare("DELETE FROM tbl_access_cards WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Access card deleted", 'type' => 'warning']);
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editCard = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_access_cards WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editCard = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // If we have an edit card, also get the employee_id for the dropdown
        if ($editCard && !empty($editCard['emp_id'])) {
            $empStmt = $mysqli->prepare("SELECT employee_id FROM employee_master WHERE employee_username = ?");
            $empStmt->bind_param("s", $editCard['emp_id']);
            $empStmt->execute();
            $empResult = $empStmt->get_result();
            if ($empRow = $empResult->fetch_assoc()) {
                $editCard['employee_id'] = $empRow['employee_id'];
            }
            $empStmt->close();
        }
        
        // If we have an edit card, also get the team_id for the dropdown
        if ($editCard && !empty($editCard['team'])) {
            $teamStmt = $mysqli->prepare("SELECT team_id FROM team_master WHERE team_name = ?");
            $teamStmt->bind_param("s", $editCard['team']);
            $teamStmt->execute();
            $teamResult = $teamStmt->get_result();
            if ($teamRow = $teamResult->fetch_assoc()) {
                $editCard['team_id'] = $teamRow['team_id'];
            }
            $teamStmt->close();
        }
    }
    if (!$editCard) $mode = 'view';
}

// Get employees and teams for dropdowns
$employees = $mysqli->query("SELECT employee_id, employee_name, employee_username FROM employee_master WHERE status = 'Active' ORDER BY employee_name ASC");
$teams = $mysqli->query("SELECT team_id, team_name FROM team_master WHERE status = 'Active' ORDER BY team_name ASC");

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$sortBy = $_GET['sort_by'] ?? 'access_no';
$sortOrder = $_GET['sort_order'] ?? 'ASC';

// Validate sort column
$allowedSorts = ['id', 'access_no', 'name', 'team', 'emp_id'];
if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'access_no';
}

// Validate sort order
if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
    $sortOrder = 'ASC';
}

// Build query
$sql = "SELECT * FROM tbl_access_cards WHERE 1=1";

$params = [];
$types = '';

if ($q !== '') {
    $sql .= " AND (access_no LIKE ? OR name LIKE ? OR team LIKE ? OR emp_id LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

$sql .= " ORDER BY $sortBy $sortOrder";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $cards = $stmt->get_result();
} else {
    $cards = $mysqli->query($sql);
}

// Get total count
$totalCards = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_access_cards")->fetch_assoc()['cnt'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Card</title>
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
            <h4><i class="fas fa-id-card me-2"></i><?= $mode === 'edit' ? 'Edit Access Card' : 'Add New Access Card' ?></h4>
            <a href="access_control.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editCard['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="access_no" class="form-label">Access No. <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="access_no" name="access_no" 
                                       value="<?= h($editCard['access_no'] ?? '') ?>" 
                                       placeholder=" " required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="employee_id" class="form-label">Employee <span class="text-danger">*</span></label>
                              <?php  echo $editCard['emp_id'];?>
                                <select class="form-select" id="employee_id" name="employee_id" required>
                                    <option value="">Select Employee</option>
                                    <?php 
                                    
                                    if($employees):
                                        $employees->data_seek(0);
                                        while($emp = $employees->fetch_assoc()): 
                                            $selected = '';
                                            if ($mode === 'edit' && isset($editCard['emp_id']) && $editCard['emp_id'] == $emp['employee_username']) {
                                                $selected = 'selected';
                                            }
                                            ?>
                                            <option value="<?= $emp['employee_id'] ?>" <?= $selected ?>>
                                                <?= h($emp['employee_name']) ?> (<?= h($emp['employee_username']) ?>)
                                            </option>
                                        <?php endwhile;
                                    endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="team_id" class="form-label">Team</label>
                                <select class="form-select" id="team_id" name="team_id">
                                    <option value="">Select Team</option>
                                    <?php 
                                    if($teams):
                                        $teams->data_seek(0);
                                        while($team = $teams->fetch_assoc()): 
                                            $selected = '';
                                            if ($mode === 'edit' && isset($editCard['team_id']) && $editCard['team_id'] == $team['team_id']) {
                                                $selected = 'selected';
                                            }
                                            ?>
                                            <option value="<?= $team['team_id'] ?>" <?= $selected ?>>
                                                <?= h($team['team_name']) ?>
                                            </option>
                                        <?php endwhile;
                                    endif; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Issue Type, Date, and Fee Row -->
                    <div class="row">
                        <!-- Issue Type Column -->
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="issue_type" class="form-label">Card Status (Type)</label>
                                <select class="form-select" id="issue_type" name="issue_type" required>
                                    <option value="Card Fee" <?= (isset($editCard['issue_type']) && $editCard['issue_type'] == 'Card Fee') ? 'selected' : '' ?>>Card Fee</option>
                                    <option value="Card Missing" <?= (isset($editCard['issue_type']) && $editCard['issue_type'] == 'Card Missing') ? 'selected' : '' ?>>Card Missing</option>
                                    <option value="Fine" <?= (isset($editCard['issue_type']) && $editCard['issue_type'] == 'Fine') ? 'selected' : '' ?>>Fine</option>
                                </select>
                            </div>
                        </div>

                        <!-- Issue Date Column -->
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="issue_date" class="form-label">Issue Date</label>
                                <input type="date" class="form-control" id="issue_date" name="issue_date"
                                       value="<?= h($editCard['issue_date'] ?? date('Y-m-d')) ?>" required>
                            </div>
                        </div>

                        <!-- Fee Column -->
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="card_fee" class="form-label">Card Fee (₹)</label>
                                <input type="number" step="0.01" class="form-control" id="card_fee" name="card_fee"
                                       value="<?= $mode === 'edit' ? h($editCard['card_fee'] ?? '100') : '100' ?>" 
                                       placeholder="0.00">
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Card' : 'Save Card' ?>
                        </button>
                        <a href="access_control.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-id-card me-2"></i>Access Card (<?= $totalCards ?> Cards)</h4>
            <a href="access_control.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add Card
            </a>
        </div>

        <!-- Search and Filter Section -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control" 
                               placeholder="Search access no, employee, team..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="sort_by" class="form-select">
                            <option value="access_no" <?= $sortBy === 'access_no' ? 'selected' : '' ?>>Sort by Access No</option>
                            <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Sort by Name</option>
                            <option value="team" <?= $sortBy === 'team' ? 'selected' : '' ?>>Sort by Team</option>
                            <option value="emp_id" <?= $sortBy === 'emp_id' ? 'selected' : '' ?>>Sort by Emp ID</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="sort_order" class="form-select">
                            <option value="ASC" <?= $sortOrder === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                            <option value="DESC" <?= $sortOrder === 'DESC' ? 'selected' : '' ?>>Descending</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="access_control.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Cards Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>S.No</th>
                                <th>Name</th>
                                <th>Team</th>
                                <th>Emp ID</th>
                                <th>Status</th>
                                <th>Issue Date</th>
                                <th>Card Fee</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($cards && $cards->num_rows > 0): $i=0;?>
                                <?php while($row = $cards->fetch_assoc()): $i=$i+1;?>
                                <?php 
                                    // Logic for Refund Notification (only for Card Fee)
                                    $refundBadge = '';
                                    if ($row['issue_type'] === 'Card Fee' && !empty($row['issue_date'])) {
                                        $iDate = new DateTime($row['issue_date']);
                                        $now = new DateTime();
                                        $diff = $iDate->diff($now);
                                        // Calculate total months difference
                                        $months = $diff->m + ($diff->y * 12);
                                        
                                        if ($months >= 3) {
                                            $refundBadge = '<div class="mt-1"><span class="badge bg-success">Return ₹100</span></div>';
                                        }
                                    }
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?= $i //h($row['access_no']) ?></td>
                                    <td><?= h($row['name']) ?></td>
                                    <td><span class="badge bg-primary"><?= h($row['team']) ?></span></td>
                                    <td><code><?= h($row['emp_id']) ?></code></td>
                                    
                                    <!-- Status Column with proper display -->
                                    <td>
                                        <?php if($row['issue_type'] == 'Card Fee'): ?>
                                            <span class="badge bg-info text-dark">Card Fee</span>
                                        <?php elseif($row['issue_type'] == 'Card Missing'): ?>
                                            <span class="badge bg-danger">Card Missing</span>
                                        <?php elseif($row['issue_type'] == 'Fine'): ?>
                                            <span class="badge bg-warning text-dark">Fine</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?= h($row['issue_type']) ?></span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Date Column with Refund Logic -->
                                    <td>
                                        <?= $row['issue_date'] ? date('d-M-Y', strtotime($row['issue_date'])) : '-' ?>
                                        <?= $refundBadge ?>
                                    </td>

                                    <td>₹ <?= number_format($row['card_fee'], 2) ?></td>
                                    
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="access_control.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Delete access card <?= h($row['access_no']) ?>?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-id-card fa-3x mb-3"></i>
                                            <h5>No access cards found</h5>
                                            <p class="mb-0">Start managing employee access cards.</p>
                                            <a href="access_control.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Card
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            // Logic for Access Card Fee auto-population
            $('#issue_type').on('change', function() {
                var type = $(this).val();
                
                // If Card Fee or Card Missing is selected, set fee to 100
                if (type === 'Card Fee' || type === 'Card Missing') {
                    $('#card_fee').val('100');
                } 
                // If Fine is selected, set fee to 10
                else if (type === 'Fine') {
                    $('#card_fee').val('10');
                }
                // If nothing selected
                else {
                    $('#card_fee').val(''); 
                }
            });

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

            // Trigger change on page load for edit mode to set correct fee
            $('#issue_type').trigger('change');
        });
    </script>
</body>
</html>