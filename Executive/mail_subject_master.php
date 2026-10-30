<br><br><br>
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
    .mail-id { font-family: monospace; background: #eee; padding: 2px 5px; border-radius: 3px; }
    .disabled-btn { cursor: not-allowed !important; opacity: 0.6; }
</style>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
            <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show">
                <?= h($_GET['msg']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
            <div class="card">
                <div class="card-header"><h4><?= $mode === 'edit' ? 'Edit' : 'Add' ?> Mail Subject</h4></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="<?= $mode ?>">
                        <?php if($mode === 'edit'): ?>
                            <input type="hidden" name="mail_id" value="<?= $editMailSubject['mail_id'] ?>">
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label">Mail Subject</label>
                            <input type="text" name="mail_subject" class="form-control" value="<?= h($editMailSubject['mail_subject'] ?? '') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Date</label>
                            <input type="date" name="date" class="form-control" value="<?= h($editMailSubject['date'] ?? date('Y-m-d')) ?>" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="mail_subject_master.php" class="btn btn-link">Cancel</a>
                    </form>
                </div>
            </div>

        <?php else: ?>

            <div class="d-flex justify-content-between mb-3">
                <h4>Mail Subject</h4>
                <a href="mail_subject_master.php?mode=add" class="btn btn-success btn-sm"><i class="fas fa-plus"></i> Add New</a>
            </div>

            <div class="card">
                <h4></h4>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <!-- <thead class="table-dark"> -->
                            <thead class="table-grey">
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
                                    <td><span class="mail-id"><?= $i ?></span></td>
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
                                                <i class="fas fa-edit"></i> Mail
                                            </a>

                                            <!-- Create/Open Job Link -->
                                            <a href="jobs.php?mode=add&mail_id=<?= (int)$row['mail_id'] ?>" class="btn btn-warning" title="Create Job">
                                                <i class="fas fa-briefcase"></i> Job
                                            </a>

                                            <form method="post" onsubmit="return confirm('Delete this subject?')" style="display:inline;">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="mail_id" value="<?= $row['mail_id'] ?>">
                                                <!--button type="submit" class="btn btn-danger" <?= $is_locked ? 'disabled title="Cannot delete: linked to a job"' : '' ?>>
                                                    <i class="fas fa-trash"></i>
                                                </button-->
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