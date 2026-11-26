<?php
// For development
error_reporting(E_ALL);
ini_set('display_errors', 1);
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

function redirect_with($params = []) {
    $base = 'mail_subject_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $mail_subject = trim($_POST['mail_subject'] ?? '');
        $date = $_POST['date'] ?? '';
        $errors = [];
        
        if ($mail_subject === '') $errors[] = 'Mail Subject is required';
        if ($date === '') $errors[] = 'Date is required';
        
        if (!empty($errors)) {
            redirect_with(['msg' => implode('<br>', $errors), 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            $stmt = $mysqli->prepare("INSERT INTO mail_subject_master (mail_subject, date, status, created_date, last_updated) VALUES (?, ?, 'Active', NOW(), NOW())");
            $stmt->bind_param("ss", $mail_subject, $date);
            $stmt->execute();
            redirect_with(['msg' => "Added successfully", 'type' => 'success']);
        }
        
        if ($action === 'edit') {
            $mail_id = (int)($_POST['mail_id'] ?? 0);
            // Safety check: Don't update if it's already in jobs
            $check = $mysqli->prepare("SELECT COUNT(*) FROM jobs WHERE mail_subject = (SELECT mail_subject FROM mail_subject_master WHERE mail_id = ?)");
            $check->bind_param("i", $mail_id);
            $check->execute();
            if ($check->get_result()->fetch_row()[0] == 0) {
                $stmt = $mysqli->prepare("UPDATE mail_subject_master SET mail_subject = ?, date = ?, last_updated = NOW() WHERE mail_id = ?");
                $stmt->bind_param("ssi", $mail_subject, $date, $mail_id);
                $stmt->execute();
                redirect_with(['msg' => "Updated successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => "Cannot edit: This subject is linked to a job record.", 'type' => 'warning']);
            }
        }
    }
    
    if ($action === 'delete') {
        $mail_id = (int)($_POST['mail_id'] ?? 0);
        // Safety check: Don't delete if it's in jobs
        $check = $mysqli->prepare("SELECT COUNT(*) FROM jobs WHERE mail_subject = (SELECT mail_subject FROM mail_subject_master WHERE mail_id = ?)");
        $check->bind_param("i", $mail_id);
        $check->execute();
        if ($check->get_result()->fetch_row()[0] == 0) {
            $stmt = $mysqli->prepare("DELETE FROM mail_subject_master WHERE mail_id = ?");
            $stmt->bind_param("i", $mail_id);
            $stmt->execute();
            redirect_with(['msg' => 'Deleted successfully', 'type' => 'success']);
        } else {
            redirect_with(['msg' => "Cannot delete: This subject is linked to a job record.", 'type' => 'danger']);
        }
    }
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editMailSubject = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $mysqli->prepare("SELECT * FROM mail_subject_master WHERE mail_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $editMailSubject = $stmt->get_result()->fetch_assoc();
}

// Query Parameters
$q = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'mail_id';
$dir = strtolower($_GET['dir'] ?? 'desc');

// IMPROVED QUERY: Uses LEFT JOIN to count occurrences in 'jobs' table
$sql = "SELECT m.*, COUNT(j.mail_subject) as job_count 
        FROM mail_subject_master m 
        LEFT JOIN jobs j ON m.mail_subject = j.mail_subject";

$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "m.mail_subject LIKE ?";
    $params[] = "%$q%";
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " WHERE " . implode(" AND ", $cond);
}

$sql .= " GROUP BY m.mail_id ORDER BY $sort $dir";

// Execute
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $mail_subjects = $stmt->get_result();
} else {
    $mail_subjects = $mysqli->query($sql);
}
?>

<?php require 'header.php'; ?>
<style>
    body { background-color: #f8f9fa; }
    .main-content { padding: 20px; max-width: 1400px; margin: 0 auto; }
    .mail-id { font-family: inherit; }
    .disabled-btn { cursor: not-allowed !important; opacity: 0.6; }
    
    .table {
        border-collapse: separate !important;
        border-spacing: 0 !important;
    }
    .table th,
    .table thead th {
        background-color: #5e6b77 !important;
        color: #ffffff !important;
        border-top: 1px solid #dee2e6 !important;
        border-bottom: 1px solid #dee2e6 !important;
        border-right: 1px solid #dee2e6 !important;
        border-left: none !important;
        font-weight: 600;
        font-size: 12.5px;
        text-transform: uppercase;
        letter-spacing: 0.025em;
        padding: 8px 10px;
        white-space: nowrap;
        text-align: center;
        vertical-align: middle;
        box-shadow: none !important;
    }
    .table th:first-child,
    .table thead th:first-child {
        border-left: 1px solid #dee2e6 !important;
    }
    .table td,
    .table tbody td {
        padding: 6px 10px;
        font-size: 12.5px;
        color: #334155;
        vertical-align: middle;
        border-top: none !important;
        border-bottom: 1px solid #dee2e6 !important;
        border-right: 1px solid #dee2e6 !important;
        border-left: none !important;
        white-space: nowrap;
    }
    .table td:first-child,
    .table tbody td:first-child {
        border-left: 1px solid #dee2e6 !important;
    }
    .sticky-assign-col-last {
        border-right: 1px solid #dee2e6 !important;
    }
    .table> :not(caption)>*>*,
    .table-striped>tbody>tr:nth-of-type(odd)>*,
    .table-striped>tbody>tr:nth-of-type(even)>* {
        --bs-table-accent-bg: transparent !important;
        box-shadow: none !important;
    }
    .table tbody tr:nth-child(even) td {
        background-color: #f2f4f6 !important;
    }
    .table tbody tr:nth-child(odd) td {
        background-color: #ffffff !important;
    }
    .table tbody tr:hover td {
        background-color: #c4cbd3 !important;
        cursor: pointer;
        transition: background-color 0.1s ease;
    }
</style>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
            <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show">
                <?= h($_GET['msg']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
            <div class="page-title-container" style="background-color: #6c757d !important; background: #6c757d !important; color: #ffffff !important; padding: 12px 20px !important; border-radius: 12px !important; margin-top: 0 !important; margin-bottom: 20px !important; display: flex !important; justify-content: space-between !important; align-items: center !important;">
                <div>
                    <h4 class="fw-bold mb-0 text-white" style="color: #ffffff !important; font-size: 1.25rem !important;"><i class="fas <?= $mode === 'edit' ? 'fa-edit' : 'fa-plus-circle' ?> me-2" style="color: #ffffff !important;"></i><?= $mode === 'edit' ? 'Edit' : 'Add' ?> Mail Subject</h4>
                    <p class="mb-0 text-white opacity-75" style="color: rgba(255,255,255,0.85) !important;"><?= $mode === 'edit' ? 'Update mail subject details' : 'Create new mail subject entry' ?></p>
                </div>
                <div>
                    <a href="mail_subject_master.php" class="btn btn-outline-light text-white btn-sm shadow-sm">
                        <i class="fas fa-arrow-left me-1"></i> Back to List
                    </a>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body p-4">
                    <form method="post">
                        <input type="hidden" name="action" value="<?= $mode ?>">
                        <?php if($mode === 'edit'): ?>
                            <input type="hidden" name="mail_id" value="<?= $editMailSubject['mail_id'] ?>">
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Mail Subject</label>
                            <input type="text" name="mail_subject" class="form-control" value="<?= h($editMailSubject['mail_subject'] ?? '') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Date</label>
                            <input type="date" name="date" class="form-control" value="<?= h($editMailSubject['date'] ?? date('Y-m-d')) ?>" required>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">Save Changes</button>
                            <a href="mail_subject_master.php" class="btn btn-secondary px-4 fw-bold">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

        <?php else: ?>

            <div class="page-title-container" style="background-color: #6c757d !important; background: #6c757d !important; color: #ffffff !important; padding: 12px 20px !important; border-radius: 12px !important; margin-top: 0 !important; margin-bottom: 20px !important; display: flex !important; justify-content: space-between !important; align-items: center !important;">
                <div>
                    <h4 class="fw-bold mb-0 text-white" style="color: #ffffff !important; font-size: 1.25rem !important;"><i class="fas fa-envelope me-2" style="color: #ffffff !important;"></i>Mail Subject</h4>
                    <p class="mb-0 text-white opacity-75" style="color: rgba(255,255,255,0.85) !important;">Manage mail subject entries and job references</p>
                </div>
                <div>
                    <a href="mail_subject_master.php?mode=add" class="btn btn-success text-white fw-bold btn-sm shadow-sm">
                        <i class="fas fa-plus me-1"></i> Add New
                    </a>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped table-bordered align-middle mb-0">
                        <thead>
                           <tr>
                                <th>S.No</th>
                                <th>Mail Subject</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($mail_subjects && $mail_subjects->num_rows > 0): $i=0;?>
                                <?php while($row = $mail_subjects->fetch_assoc()): $i=$i+1;;
                                    $is_locked = ($row['job_count'] > 0); 
                                ?>
                                <tr>
                                    <td><?= $i ?></td>
                                  <td><?= h($row['mail_subject']) ?></td>
                                    <td><span class="badge bg-info"><?= formatDate($row['date']) ?></span></td>
                                    <td>
                                        <span class="badge <?= $is_locked ? 'bg-secondary' : 'bg-success' ?>">
                                            <?= $is_locked ? 'In Use' : 'Available' ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <!-- Edit Mail Subject Link -->
                                            <a href="mail_subject_master.php?mode=edit&id=<?= $row['mail_id'] ?>" class="btn btn-success" title="Edit Mail Subject">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>

                                            <!-- Create/Open Job Link -->
                                            <a href="jobs.php?mode=add&mail_id=<?= (int)$row['mail_id'] ?>" class="btn btn-warning" title="Create Job">
                                                <i class="fas fa-briefcase"></i> Job
                                            </a>

                                            <form method="post" onsubmit="return confirm('Delete this subject?')" style="display:inline-block; margin:0;">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="mail_id" value="<?= $row['mail_id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" style="border-top-left-radius: 0; border-bottom-left-radius: 0;" <?= $is_locked ? 'disabled title="Cannot delete: linked to a job"' : '' ?>>
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center p-4">No records found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>