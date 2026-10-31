<?php
require 'db.php';
session_start();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

$current_user = $_SESSION['username'] ?? '';
if (!$current_user) {
    echo "<div class='alert alert-danger'>Session expired. Please log in again.</div>";
    exit;
}

// Handle form submission for QC verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_pag') {
    $job_id = intval($_POST['job_id'] ?? 0);
    $pages_verified = isset($_POST['pages_verified']) ? 1 : 0;
    $images_verified = isset($_POST['images_verified']) ? 1 : 0;
    $tables_verified = isset($_POST['tables_verified']) ? 1 : 0;
    
    if ($job_id <= 0) {
        header("Location: qc_verify.php?msg=Invalid job ID&type=danger");
        exit;
    }
    
    // Update pag table with verification status
    $stmt = $mysqli->prepare("UPDATE pag SET pages_verified = ?, images_verified = ?, tables_verified = ?, qc_checked_by = ?, qc_checked_date = NOW() WHERE job_id = ?");
    if ($stmt) {
        $stmt->bind_param("iiisi", $pages_verified, $images_verified, $tables_verified, $current_user, $job_id);
        if ($stmt->execute()) {
            header("Location: qc_verify.php?msg=QC verification saved&type=success");
        } else {
            header("Location: qc_verify.php?msg=Failed to save verification&type=danger");
        }
        $stmt->close();
    }
    exit;
}

// Get completed PAG jobs that need QC verification
$sql = "SELECT 
            j.job_id, 
            j.job_date, 
            c.client_name, 
            j.journal_issue, 
            j.article,
            pag.pages, 
            pag.images, 
            pag.tables_count,
            pag.pages_verified,
            pag.images_verified,
            pag.tables_verified,
            pag.pag_date,
            ep.employee_username AS pag_done_by
        FROM jobs j
        JOIN client_master c ON j.client_id = c.client_id
        JOIN pag ON pag.job_id = j.job_id
        LEFT JOIN employee_master ep ON pag.done_by = ep.employee_id
        WHERE pag.status = 'Completed'
        ORDER BY j.job_date DESC";

$results = $mysqli->query($sql);

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>QC Verification</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
    <style>
        .table-responsive { max-height: 600px; }
        .verified { color: green; }
        .pending { color: orange; }
    </style>
</head>
<body>
    <div class="container mt-3">
        <h2 class="mb-4">QC Verification</h2>
        
        <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show">
            <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Client</th>
                        <th>Issue</th>
                        <th>Pages</th>
                        <th>Images</th>
                        <th>Tables</th>
                        <th>PAG Date</th>
                        <th>QC Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($results->num_rows > 0): ?>
                        <?php $i = 1; while($row = $results->fetch_assoc()): 
                            $all_verified = $row['pages_verified'] && $row['images_verified'] && $row['tables_verified'];
                        ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= h($row['client_name']) ?></td>
                            <td><?= h($row['journal_issue']) ?></td>
                            <td>
                                <?= $row['pages'] ?>
                                <?= $row['pages_verified'] ? '<span class="verified">✓</span>' : '<span class="pending">?</span>' ?>
                            </td>
                            <td>
                                <?= $row['images'] ?>
                                <?= $row['images_verified'] ? '<span class="verified">✓</span>' : '<span class="pending">?</span>' ?>
                            </td>
                            <td>
                                <?= $row['tables_count'] ?>
                                <?= $row['tables_verified'] ? '<span class="verified">✓</span>' : '<span class="pending">?</span>' ?>
                            </td>
                            <td><?= !empty($row['pag_date']) ? date('d-m-Y', strtotime($row['pag_date'])) : '—' ?></td>
                            <td>
                                <?php if($all_verified): ?>
                                    <span class="badge bg-success">Verified</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary verify-btn" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#verifyModal"
                                    data-job_id="<?= $row['job_id'] ?>" 
                                    data-pages="<?= $row['pages'] ?>" 
                                    data-images="<?= $row['images'] ?>" 
                                    data-tables="<?= $row['tables_count'] ?>"
                                    data-pages_v="<?= $row['pages_verified'] ?>"
                                    data-images_v="<?= $row['images_verified'] ?>"
                                    data-tables_v="<?= $row['tables_verified'] ?>">
                                    Verify
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center py-4 text-muted">
                                No PAG jobs found for QC verification.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Verify Modal -->
    <div class="modal fade" id="verifyModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <input type="hidden" name="action" value="verify_pag">
                <input type="hidden" name="job_id" id="v_job_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">QC Verification</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <strong>Pages:</strong> <span id="v_pages"></span><br>
                        <strong>Images:</strong> <span id="v_images"></span><br>
                        <strong>Tables:</strong> <span id="v_tables"></span>
                    </div>
                    <hr>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="pages_verified" id="v_pages_check">
                        <label class="form-check-label" for="v_pages_check">Pages Verified</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="images_verified" id="v_images_check">
                        <label class="form-check-label" for="v_images_check">Images Verified</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="tables_verified" id="v_tables_check">
                        <label class="form-check-label" for="v_tables_check">Tables Verified</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Save Verification</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.verify-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('v_job_id').value = btn.dataset.job_id;
                document.getElementById('v_pages').textContent = btn.dataset.pages;
                document.getElementById('v_images').textContent = btn.dataset.images;
                document.getElementById('v_tables').textContent = btn.dataset.tables;
                
                document.getElementById('v_pages_check').checked = btn.dataset.pages_v === '1';
                document.getElementById('v_images_check').checked = btn.dataset.images_v === '1';
                document.getElementById('v_tables_check').checked = btn.dataset.tables_v === '1';
            });
        });
    </script>
</body>
</html>