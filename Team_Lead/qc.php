<br><br><br>
<?php
require 'db.php';
session_start();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

$current_user = $_SESSION['username'] ?? '';
if (!$current_user) {
    echo "<div class='alert alert-danger'>Session expired. Please log in again.</div>";
    exit;
}

// Handle form submission for updating pag table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_pag') {
    $job_id = intval($_POST['job_id'] ?? 0);
    $pages = $_POST['pages'];
    $images = $_POST['images'];
    $tables_count = $_POST['tables_count'];
    
    if ($job_id <= 0) {
        header("Location: qc_simple.php?msg=" . urlencode("Invalid job ID") . "&type=danger");
        exit;
    }
    
    // Start transaction for multiple updates
    $mysqli->begin_transaction();
    
    try {
        // Update pag table directly
        
        $stmt = $mysqli->prepare("UPDATE pag SET pages = ?, images = ?, tables_count = ?, last_updated = NOW() WHERE job_id = ?");
        if (!$stmt) {
            throw new Exception("Prepare error for pag update: " . $mysqli->error);
        }
        $stmt->bind_param("sssi", $pages, $images, $tables_count, $job_id);
        if (!$stmt->execute()) {
            throw new Exception("Execute error for pag update: " . $stmt->error);
        }
        $stmt->close();
        
        // Update QC table status to 'Completed'
        //echo "UPDATE qc SET status = 'Completed', qc_date = NOW(),done_by='$current_user' WHERE job_id = '$job_id'";
        //die;
        $stmt = $mysqli->prepare("UPDATE qc SET status = 'Completed', qc_date = NOW(),done_by=? WHERE job_id = ?");
        if (!$stmt) {
            throw new Exception("Prepare error for qc update: " . $mysqli->error);
        }
        $stmt->bind_param("si", $current_user,$job_id);
        if (!$stmt->execute()) {
            throw new Exception("Execute error for qc update: " . $stmt->error);
        }
        $stmt->close();
        
        // Update assigned_qc in jobs table with current username
        $stmt = $mysqli->prepare("UPDATE jobs SET assigned_qc = ? WHERE job_id = ?");
        if (!$stmt) {
            throw new Exception("Prepare error for jobs update: " . $mysqli->error);
        }
        $stmt->bind_param("si", $current_user, $job_id);
        if (!$stmt->execute()) {
            throw new Exception("Execute error for jobs update: " . $stmt->error);
        }
        $stmt->close();
        
        // Commit all changes
        $mysqli->commit();
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode("PAG data updated successfully and QC marked as completed") . "&type=success");
        
    } catch (Exception $e) {
        // Rollback on any error
        $mysqli->rollback();
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode("Failed to update: " . $e->getMessage()) . "&type=danger");
    }
    exit;
}

// Get completed PAG jobs for QC
$sql = "SELECT 
            j.job_id, 
            j.job_date, 
            c.client_name, 
            j.journal_issue, 
            j.article,
            pag.pages, 
            pag.images, 
            pag.tables_count,
            pag.pag_date,
            pag.done_by AS pag_done_by,
            qc.status as qc_status,
            j.assigned_qc
        FROM jobs j
        JOIN client_master c ON j.client_id = c.client_id
        JOIN pag ON pag.job_id = j.job_id
        LEFT JOIN employee_master ep ON pag.done_by = ep.employee_id
        LEFT JOIN qc ON qc.job_id = j.job_id
        WHERE pag.status = 'Completed'
        ORDER BY j.job_date DESC";

$results = $mysqli->query($sql);


?>
<?php require 'header.php';?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" />
    <style>
        .modal-dialog { max-width: 400px; }
        .table-responsive { max-height: 600px; }
        table td, th { vertical-align: middle; padding: 8px 12px; }
        .btn-sm { padding: 4px 8px; font-size: 0.85rem; }
        .status-badge { font-size: 0.75rem; }
    </style>
        <h2 class="mb-4">QC - Edit PAG Data</h2>
        
        <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show">
            <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Results Table -->
        <div class="table-responsive">
            <table class="table table-striped table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Issue</th>
                        <th>Article</th>
                        <th>Pages</th>
                        <th>Images</th>
                        <th>Tables</th>
                        <th>PAG Date</th>
                        <th>PAG By</th>
                        <th>QC Status</th>
                        <th>Assigned QC</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($results->num_rows > 0): ?>
                        <?php $i = 1; while($row = $results->fetch_assoc()): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= !empty($row['job_date']) ? date('d-m-Y', strtotime($row['job_date'])) : '—' ?></td>
                            <td><?= h($row['client_name']) ?></td>
                            <td><?= h($row['journal_issue']) ?></td>
                            <td><?= h($row['article']) ?></td>
                            <td><?= h($row['pages'] ?? 0) ?></td>
                            <td><?= h($row['images'] ?? 0) ?></td>
                            <td><?= h($row['tables_count'] ?? 0) ?></td>
                            <td><?= !empty($row['pag_date']) ? date('d-m-Y', strtotime($row['pag_date'])) : '—' ?></td>
                            <td><?= h($row['pag_done_by'] ?? '—') ?></td>
                            <td>
                                <?php 
                                $qc_status = $row['qc_status'] ?? 'Pending';
                                $badge_class = $qc_status === 'Completed' ? 'bg-success' : 'bg-warning';
                                ?>
                                <span class="badge <?= $badge_class ?> status-badge"><?= h($qc_status) ?></span>
                            </td>
                            <td><?= h($row['assigned_qc'] ?? '—') ?></td>
                            <td>
                                <button class="btn btn-warning btn-sm edit-btn" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#editModal"
                                    data-job_id="<?= $row['job_id'] ?>" 
                                    data-pages="<?= $row['pages'] ?>" 
                                    data-images="<?= $row['images'] ?>" 
                                    data-tables="<?= $row['tables_count'] ?>">
                                    <i class="bi bi-pencil-square"></i> Manage
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="13" class="text-center py-4 text-muted">
                                <i class="bi bi-inbox"></i><br>
                                No completed PAG jobs found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit PAG Data</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_pag">
                    <input type="hidden" name="job_id" id="m_job_id">

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Pages</label>
                            <input type="text" name="pages" id="m_pages" class="form-control" min="0" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Images</label>
                            <input type="text" name="images" id="m_images" class="form-control" min="0" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Tables</label>
                            <input type="text" name="tables_count" id="m_tables" class="form-control" min="0" required>
                        </div>
                    </div>
                    <div class="alert alert-info mt-3">
                        <small>
                            <i class="bi bi-info-circle"></i> 
                            After saving, QC status will be marked as 'Completed' and assigned to <?= h($current_user) ?>
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Edit button functionality
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('m_job_id').value = btn.dataset.job_id;
                document.getElementById('m_pages').value = btn.dataset.pages || 0;
                document.getElementById('m_images').value = btn.dataset.images || 0;
                document.getElementById('m_tables').value = btn.dataset.tables || 0;
            });
        });

        // Auto-dismiss alerts
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });
    </script>
