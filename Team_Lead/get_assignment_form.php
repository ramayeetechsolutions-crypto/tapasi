<br><br><br>
<?php
// Start a session if one is not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    if(isset($_SESSION['uid'])) $exeid=$_SESSION['uid'];
}
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

// Get parameters
$ce_id = isset($_GET['ce_id']) ? (int)$_GET['ce_id'] : 0;
$job_id = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;

if (!$ce_id || !$job_id) {
    echo '<div class="alert alert-danger">Invalid parameters</div>';
    exit;
}

// Get current user info for reporting manager
$current_user_id = $_SESSION['uid'] ?? 0;
$reporting_manager = '';
$current_user_name = '';
if ($current_user_id) {
    $stmt = $mysqli->prepare("SELECT employee_name, reporting_manager FROM employee_master WHERE employee_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $stmt->bind_result($current_user_name, $reporting_manager);
        $stmt->fetch();
        $stmt->close();
    }
}

// Determine effective reporting manager (if user is TL/Admin without RM, use their own name)
$effective_manager = !empty($reporting_manager) ? $reporting_manager : $current_user_name;

// Get TMs under same reporting manager
$tms = [];
if ($effective_manager) {
    $tm_query = "SELECT em.employee_id, em.employee_username, em.employee_name
                 FROM employee_master em 
                 JOIN user_role_master ur ON em.user_role_id = ur.role_id
                 WHERE (em.reporting_manager = ? OR em.reporting_manager = ?) 
                 AND em.status = 'Active' 
                 AND ur.role_name IN ('TM', 'TL', 'Admin')
                 ORDER BY em.employee_name";
    
    $stmt = $mysqli->prepare($tm_query);
    if ($stmt) {
        $stmt->bind_param("ss", $effective_manager, $current_user_name);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($tm = $result->fetch_assoc()) {
            $tms[] = $tm;
        }
        $stmt->close();
    }
}

// Get CE and Job details
$query = "SELECT ce.*, 
                 j.journal_issue, 
                 j.article,
                 j.process_id,
                 j.assigned_to,
                 j.due_date,
                 j.notes as job_notes,
                 j.queries as job_queries,
                 j.word_count as job_word_count,
                 j.due_date_ce,
                 j.due_date_le,
                 j.due_date_pag,
                 j.due_date_qc,
                 j.due_date_xml,
                 c.client_name,
                 c.client_id
          FROM ce 
          LEFT JOIN jobs j ON ce.job_id = j.job_id
          LEFT JOIN client_master c ON j.client_id = c.client_id
          WHERE ce.id = ? AND j.job_id = ?";

$stmt = $mysqli->prepare($query);
$stmt->bind_param("ii", $ce_id, $job_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if (!$row) {
    echo '<div class="alert alert-warning">Record not found</div>';
    exit;
}
?>

<div class="container-fluid">
    <!-- Job Summary -->
    <div class="card mb-3">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Job Summary</h6>
        </div>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-4">
                    <small class="text-muted d-block">Client</small>
                    <strong><?= h($row['client_name'] ?? 'N/A') ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Journal/Issue</small>
                    <strong><?= h($row['issue'] ?? 'N/A') ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Article</small>
                    <strong><?= h($row['articles'] ?? 'N/A') ?></strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Process</small>
                    <strong><?= h($row['process'] ?? 'N/A') ?></strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Word Count</small>
                    <strong>
                        <?php 
                        $word_count = 0;
                        if (!empty($row['word_count']) && (int)$row['word_count'] > 0) {
                            $word_count = (int)$row['word_count'];
                        } elseif (!empty($row['job_word_count']) && (int)$row['job_word_count'] > 0) {
                            $word_count = (int)$row['job_word_count'];
                        }
                        echo number_format($word_count);
                        ?>
                    </strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Status</small>
                    <span class="badge bg-<?= $row['status'] == 'Need to Assign' ? 'warning' : 'info' ?>">
                        <?= h($row['status'] ?? 'N/A') ?>
                    </span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Due Date</small>
                    <strong><?= $row['due_date'] ? date('d-m-Y', strtotime($row['due_date'])) : 'N/A' ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Assignment Form -->
    <form method="post" action="ce_assign_tm.php" id="fullAssignmentForm">
        <input type="hidden" name="action" value="assign_tm">
        <input type="hidden" name="ce_id" value="<?= $ce_id ?>">
        <input type="hidden" name="job_id" value="<?= $job_id ?>">
        
        <div class="row g-3">
            <!-- Content Assignment -->
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-file-alt me-2 text-success"></i>Content Assignment</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Assign Content To <span class="text-danger">*</span></label>
                            <select name="assigned_to_content" class="form-select" required>
                                <option value="">-- Select TM for Content --</option>
                                <?php foreach ($tms as $tm): ?>
                                    <option value="<?= h($tm['employee_username']) ?>" 
                                        <?= ($row['assigned_to_content'] == $tm['employee_username']) ? 'selected' : '' ?>>
                                        <?= h($tm['employee_name']) ?> (<?= h($tm['employee_username']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($row['assigned_to_content']): ?>
                            <div class="alert alert-success py-2 mb-0">
                                <small>
                                    <i class="fas fa-check-circle me-1"></i>
                                    Currently assigned to: <strong><?= h($row['assigned_to_content']) ?></strong>
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Images Assignment -->
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-image me-2 text-purple"></i>Images Assignment</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Assign Images To</label>
                            <select name="assigned_to_img" class="form-select">
                                <option value="">-- Select TM for Images (Optional) --</option>
                                <?php foreach ($tms as $tm): ?>
                                    <option value="<?= h($tm['employee_username']) ?>" 
                                        <?= ($row['assigned_to_img'] == $tm['employee_username']) ? 'selected' : '' ?>>
                                        <?= h($tm['employee_name']) ?> (<?= h($tm['employee_username']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($row['assigned_to_img']): ?>
                            <div class="alert alert-success py-2 mb-0">
                                <small>
                                    <i class="fas fa-check-circle me-1"></i>
                                    Currently assigned to: <strong><?= h($row['assigned_to_img']) ?></strong>
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- CE Details -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-calendar me-2"></i>CE Details</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">CE Date</label>
                            <input type="date" name="ce_date" class="form-control" 
                                   value="<?= $row['ce_date'] ?? date('Y-m-d') ?>">
                        </div>
                        
                        <div class="row g-2">
                            <div class="col-6">
                                <small class="text-muted d-block">EQ</small>
                                <strong><?= h($row['eq'] ?? '-') ?></strong>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block">Images Count</small>
                                <strong><?= h($row['images'] ?? '-') ?></strong>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block">Tables</small>
                                <strong><?= h($row['tables_count'] ?? '0') ?></strong>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block">Reference</small>
                                <strong><?= h($row['ref'] ?? '-') ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Additional Information -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-paperclip me-2"></i>Additional Information</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" 
                                      placeholder="Additional instructions..."><?= h($row['job_notes'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Queries</label>
                            <textarea name="queries" class="form-control" rows="2" 
                                      placeholder="Any queries..."><?= h($row['job_queries'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Due Dates -->
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-clock me-2"></i>Due Dates</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-md-2">
                                <small class="text-muted d-block">CE Due</small>
                                <strong><?= $row['due_date_ce'] ? date('d-m-Y', strtotime($row['due_date_ce'])) : 'Not set' ?></strong>
                            </div>
                            <div class="col-md-2">
                                <small class="text-muted d-block">LE Due</small>
                                <strong><?= $row['due_date_le'] ? date('d-m-Y', strtotime($row['due_date_le'])) : 'Not set' ?></strong>
                            </div>
                            <div class="col-md-2">
                                <small class="text-muted d-block">PAG Due</small>
                                <strong><?= $row['due_date_pag'] ? date('d-m-Y', strtotime($row['due_date_pag'])) : 'Not set' ?></strong>
                            </div>
                            <div class="col-md-2">
                                <small class="text-muted d-block">QC Due</small>
                                <strong><?= $row['due_date_qc'] ? date('d-m-Y', strtotime($row['due_date_qc'])) : 'Not set' ?></strong>
                            </div>
                            <div class="col-md-2">
                                <small class="text-muted d-block">XML Due</small>
                                <strong><?= $row['due_date_xml'] ? date('d-m-Y', strtotime($row['due_date_xml'])) : 'Not set' ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-1"></i> Save Assignment
            </button>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                <i class="fas fa-times me-1"></i> Cancel
            </button>
        </div>
    </form>
</div>

<script>
// Form validation
document.getElementById('fullAssignmentForm').addEventListener('submit', function(e) {
    const contentSelect = this.querySelector('select[name="assigned_to_content"]');
    
    if (!contentSelect.value) {
        e.preventDefault();
        alert('Please select a TM for Content assignment.');
    }
});
</script>