<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

// ---------- Helpers ----------
if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('formatDate')) {
    function formatDate($d) {
        return (!empty($d) && $d != '0000-00-00' && $d != '0000-00-00 00:00:00') ? date('d M, Y', strtotime($d)) : '—';
    }
}

if (!function_exists('redirect_with')) {
    function redirect_with($params = []) {
        $base = 'job_master.php';
        if (!empty($params)) {
            $base .= '?' . http_build_query($params);
        }
        header("Location: $base");
        exit;
    }
}

// ---------- Handle POST ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';
    $name   = trim($_POST['job_name'] ?? '');
    $desc   = trim($_POST['job_description'] ?? '');
    $user   = $_SESSION['username'] ?? 'Admin';

    if (($action === 'add' || $action === 'edit') && $name === '') {
        redirect_with(['msg' => 'Job Name is required.', 'type' => 'danger']);
    }

    if ($action === 'add') {
        $status = 'Active';
        $stmt = $mysqli->prepare("INSERT INTO job_master (job_name, job_description, status, created_by, created_date) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssss", $name, $desc, $status, $user);
        $stmt->execute();
        $stmt->close();
        redirect_with(['msg' => "Job '$name' created successfully.", 'type' => 'success']);
    }

    if ($action === 'edit') {
        $id = (int)($_POST['job_id'] ?? 0);
        if ($id > 0) {
            $stmt = $mysqli->prepare("UPDATE job_master SET job_name=?, job_description=? WHERE job_id=?");
            $stmt->bind_param("ssi", $name, $desc, $id);
            $stmt->execute();
            $stmt->close();
            redirect_with(['msg' => "Job updated successfully.", 'type' => 'success']);
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['job_id'] ?? 0);
        if ($id > 0) {
            $stmt = $mysqli->prepare("DELETE FROM job_master WHERE job_id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            redirect_with(['msg' => "Job deleted successfully.", 'type' => 'warning']);
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['job_id'] ?? 0);
        if ($id > 0) {
            $mysqli->query("UPDATE job_master SET status = IF(status='Active', 'Inactive', 'Active') WHERE job_id=$id");
            redirect_with(['msg' => "Job status updated.", 'type' => 'info']);
        }
    }
}

// ---------- Data Fetching ----------
$mode = $_GET['mode'] ?? 'view';
$editJob = null;
if ($mode === 'edit' && isset($_GET['id'])) {
    $stmt = $mysqli->prepare("SELECT * FROM job_master WHERE job_id=?");
    $stmt->bind_param("i", $_GET['id']);
    $stmt->execute();
    $editJob = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$q       = trim($_GET['q'] ?? '');
$fstatus = trim($_GET['status'] ?? '');
$where   = "WHERE 1=1";

if ($q !== '') {
    $escaped_q = $mysqli->real_escape_string($q);
    $where .= " AND (job_name LIKE '%$escaped_q%' OR job_description LIKE '%$escaped_q%')";
}
if ($fstatus !== '') {
    $escaped_status = $mysqli->real_escape_string($fstatus);
    $where .= " AND status='$escaped_status'";
}

$sql = "SELECT * FROM job_master $where ORDER BY job_id DESC";
$jobs = $mysqli->query($sql);
$total_jobs = $jobs ? $jobs->num_rows : 0;

$pageTitle = "Job Master";
$currentPage = "job_master.php";
require_once __DIR__ . '/header.php';
?>

<main class="main-content" style="padding-top: 85px; padding-bottom: 50px;">
    <div class="container-fluid px-lg-4 px-md-3 px-2">

        <!-- FLOATING ERP TOAST NOTIFICATION -->
        <?php if (!empty($_GET['msg'])): 
            $toastMsg  = h($_GET['msg']);
            $toastType = strtolower(trim($_GET['type'] ?? 'info'));
            
            $toastTitle = 'Notification';
            $toastIcon  = 'fa-info-circle';
            $toastBadge = 'Notice';
            $toastClass = 'erp-toast-info';
            
            if (stripos($toastMsg, 'delete') !== false || stripos($toastMsg, 'remove') !== false || $toastType === 'danger' || $toastType === 'warning') {
                $toastTitle = 'Record Deleted';
                $toastIcon  = 'fa-trash-alt';
                $toastBadge = 'Deleted';
                $toastClass = 'erp-toast-danger';
            } elseif (stripos($toastMsg, 'add') !== false || stripos($toastMsg, 'create') !== false || $toastType === 'success') {
                $toastTitle = 'Successfully Added';
                $toastIcon  = 'fa-check-circle';
                $toastBadge = 'Added';
                $toastClass = 'erp-toast-success';
            } elseif (stripos($toastMsg, 'status') !== false || stripos($toastMsg, 'toggle') !== false) {
                $toastTitle = 'Status Updated';
                $toastIcon  = 'fa-toggle-on';
                $toastBadge = 'Status';
                $toastClass = 'erp-toast-info';
            } elseif (stripos($toastMsg, 'update') !== false || stripos($toastMsg, 'modified') !== false) {
                $toastTitle = 'Successfully Updated';
                $toastIcon  = 'fa-pen-to-square';
                $toastBadge = 'Updated';
                $toastClass = 'erp-toast-info';
            }
        ?>
        <div class="erp-toast-wrapper" id="erpToastWrapper" role="region" aria-label="Notifications">
            <div class="erp-toast-card <?= $toastClass ?>" id="erpToastCard" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="erp-toast-icon-box">
                    <i class="fas <?= $toastIcon ?>"></i>
                </div>
                <div class="erp-toast-content">
                    <div class="erp-toast-header">
                        <span class="erp-toast-badge"><?= $toastBadge ?></span>
                        <strong class="erp-toast-title"><?= $toastTitle ?></strong>
                        <span class="erp-toast-time">Just now</span>
                    </div>
                    <div class="erp-toast-body">
                        <?= $toastMsg ?>
                    </div>
                </div>
                <button type="button" class="erp-toast-close" onclick="dismissErpToast()" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
                <div class="erp-toast-progress-bar">
                    <div class="erp-toast-progress-fill"></div>
                </div>
            </div>
        </div>

        <script>
        function dismissErpToast() {
            const toast = document.getElementById('erpToastCard');
            if (!toast) return;
            toast.classList.add('erp-toast-hiding');
            setTimeout(() => {
                const wrapper = document.getElementById('erpToastWrapper');
                if (wrapper) wrapper.remove();
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('msg');
                    url.searchParams.delete('type');
                    window.history.replaceState({}, document.title, url.toString());
                } catch(e) {}
            }, 300);
        }

        document.addEventListener('DOMContentLoaded', function() {
            const toast = document.getElementById('erpToastCard');
            if (toast) {
                setTimeout(dismissErpToast, 4500);
            }
        });
        </script>
        <?php endif; ?>

        <!-- PAGE HEADER & BREADCRUMB -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1" style="font-size: 0.78rem;">
                        <li class="breadcrumb-item"><a href="dashboard.php" class="text-decoration-none text-muted"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                        <li class="breadcrumb-item text-muted">Master</li>
                        <li class="breadcrumb-item active text-primary fw-semibold" aria-current="page">Job Master</li>
                    </ol>
                </nav>
                <h3 class="mb-0 fw-bold d-flex align-items-center" style="color: #1e293b; font-size: 1.45rem;">
                    <i class="fas fa-briefcase text-primary me-2"></i>Job Master
                </h3>
                <p class="text-muted mb-0" style="font-size: 0.85rem; margin-top: 2px;">
                    Manage your company's service catalog, production jobs, and workflows.
                </p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge rounded-pill bg-light text-secondary border px-3 py-2 fw-medium" style="font-size: 0.82rem;">
                    <i class="fas fa-layer-group text-primary me-1"></i>Total: <strong><?= number_format($total_jobs) ?></strong> Jobs
                </span>
                <!-- TRIGGER ADD JOB MODAL -->
                <button type="button" class="btn btn-sm btn-primary px-3 shadow-sm d-flex align-items-center gap-2 erp-btn-add" data-bs-toggle="modal" data-bs-target="#addJobModal" style="font-weight: 600; border-radius: 8px;">
                    <i class="fas fa-plus"></i> <span>Add New Job</span>
                </button>
            </div>
        </div>

        <!-- FILTER & SEARCH CARD -->
        <div class="card erp-filter-card mb-4 border-0 shadow-sm">
            <div class="card-body p-3">
                <form method="get" class="row g-2 align-items-center">
                    <div class="col-lg-5 col-md-6 col-12">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-search"></i></span>
                            <input type="text" name="q" value="<?= h($q) ?>" class="form-control border-start-0 ps-0" placeholder="Search by name or description...">
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-3 col-6">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active Only</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive Only</option>
                        </select>
                    </div>
                    <div class="col-lg-4 col-md-3 col-6 d-flex align-items-center gap-2">
                        <button type="submit" class="btn btn-dark px-3 shadow-sm d-flex align-items-center gap-1" style="font-weight: 600; border-radius: 8px;">
                            <i class="fas fa-filter"></i> <span>Filter</span>
                        </button>
                        <a href="job_master.php" class="btn btn-outline-secondary px-3 d-flex align-items-center gap-1" style="border-radius: 8px;">
                            <i class="fas fa-undo"></i> <span>Reset</span>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- JOB MASTER TABLE CARD -->
        <div class="card erp-table-card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 65px;">S.No</th>
                            <th>Job Information</th>
                            <th>Created By</th>
                            <th>Created Date</th>
                            <th class="text-center" style="width: 140px;">Status</th>
                            <th class="text-end pe-4 text-nowrap actions-cell" style="width: 110px; white-space: nowrap;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($jobs && $jobs->num_rows): $i = 0; while ($row = $jobs->fetch_assoc()): $i++; ?>
                            <tr>
                                <td class="text-center text-muted fw-semibold" style="font-size: 0.85rem;"><?= $i ?></td>
                                <td>
                                    <div class="fw-bold text-dark job-title-text"><?= h($row['job_name']) ?></div>
                                    <?php if (!empty($row['job_description'])): ?>
                                        <div class="text-muted small text-truncate" style="max-width: 380px; font-size: 0.8rem;"><?= h($row['job_description']) ?></div>
                                    <?php else: ?>
                                        <div class="text-muted small fst-italic" style="font-size: 0.78rem;">No description provided</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="user-avatar-circle me-2">
                                            <i class="fas fa-user text-muted" style="font-size: 0.75rem;"></i>
                                        </div>
                                        <span class="small fw-semibold text-secondary"><?= h($row['created_by']) ?></span>
                                    </div>
                                </td>
                                <td class="small text-secondary">
                                    <i class="far fa-calendar-alt text-muted me-1"></i><?= formatDate($row['created_date']) ?>
                                </td>
                                <td class="text-center">
                                    <form method="post" class="d-inline m-0 p-0">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="job_id" value="<?= (int)$row['job_id'] ?>">
                                        <button type="submit" class="badge-status-pill <?= $row['status'] === 'Active' ? 'status-active-pill' : 'status-inactive-pill' ?>" onclick="return confirm('Change status of <?= addslashes(h($row['job_name'])) ?>?')" title="Click to toggle status">
                                            <i class="fas fa-circle me-1" style="font-size: 0.45rem;"></i> <?= h($row['status']) ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="text-end pe-4 text-nowrap actions-cell" style="white-space: nowrap; width: 110px;">
                                    <!-- Actions Strictly in Same Horizontal Line -->
                                    <div class="action-buttons-group d-inline-flex align-items-center justify-content-end gap-1 flex-nowrap" style="white-space: nowrap;">
                                        <!-- TRIGGER EDIT JOB MODAL -->
                                        <button type="button" 
                                                class="btn btn-sm btn-light action-btn btn-edit-job" 
                                                title="Edit Job"
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editJobModal"
                                                data-id="<?= (int)$row['job_id'] ?>"
                                                data-name="<?= h($row['job_name']) ?>"
                                                data-description="<?= h($row['job_description'] ?? '') ?>">
                                            <i class="fas fa-edit text-warning"></i>
                                        </button>
                                        <form method="post" class="d-inline m-0 p-0" onsubmit="return confirm('Are you sure you want to delete this job?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="job_id" value="<?= (int)$row['job_id'] ?>">
                                            <button type="submit" 
                                                    class="btn btn-sm btn-light action-btn btn-delete-job" 
                                                    title="Delete Job">
                                                <i class="fas fa-trash-alt text-danger"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <div class="py-4">
                                        <i class="fas fa-briefcase text-muted opacity-25 fa-3x mb-3"></i>
                                        <h6 class="text-secondary fw-bold">No Jobs Found</h6>
                                        <p class="text-muted small mb-0">No job records matched your search or filter criteria.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</main>

<!-- =========================================================================
     MODAL: ADD NEW JOB (NO PAGE NAVIGATION)
     ========================================================================= -->
<div class="modal fade" id="addJobModal" tabindex="-1" aria-labelledby="addJobModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content erp-modal-content border-0 shadow">
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <span class="user-avatar-circle me-2" style="background: rgba(52, 152, 219, 0.1);">
                        <i class="fas fa-briefcase text-primary"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-dark fs-6" id="addJobModalLabel">Register New Job</h5>
                        <div class="text-muted" style="font-size: 0.75rem;">Define a new service or workflow role.</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" autocomplete="off" id="addJobForm">
                <input type="hidden" name="action" value="add">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Job Title <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-briefcase"></i></span>
                            <input type="text" name="job_name" class="form-control border-start-0 ps-0" placeholder="e.g. Graphic Designing, XML Pagination" required autofocus>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Description</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted align-items-start pt-2"><i class="fas fa-align-left"></i></span>
                            <textarea name="job_description" class="form-control border-start-0 ps-0" rows="3" placeholder="Briefly outline job tasks and specifications..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top px-4 py-2 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 shadow-sm erp-btn-save d-flex align-items-center gap-2" style="font-weight: 600; border-radius: 8px;">
                        <i class="fas fa-save"></i> <span>Save Details</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: EDIT JOB (DYNAMIC JAVASCRIPT POPULATION)
     ========================================================================= -->
<div class="modal fade" id="editJobModal" tabindex="-1" aria-labelledby="editJobModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content erp-modal-content border-0 shadow">
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <span class="user-avatar-circle me-2" style="background: rgba(243, 156, 18, 0.1);">
                        <i class="fas fa-pen-to-square text-warning"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-dark fs-6" id="editJobModalLabel">Modify Job Details</h5>
                        <div class="text-muted" style="font-size: 0.75rem;">Update the job title and descriptions.</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" autocomplete="off" id="editJobForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="job_id" id="modal_edit_job_id" value="<?= (int)($editJob['job_id'] ?? 0) ?>">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Job Title <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-briefcase"></i></span>
                            <input type="text" name="job_name" id="modal_edit_job_name" class="form-control border-start-0 ps-0" placeholder="e.g. Graphic Designing, XML Pagination" required value="<?= h($editJob['job_name'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Description</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted align-items-start pt-2"><i class="fas fa-align-left"></i></span>
                            <textarea name="job_description" id="modal_edit_job_description" class="form-control border-start-0 ps-0" rows="3" placeholder="Briefly outline job tasks and specifications..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top px-4 py-2 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 shadow-sm erp-btn-save d-flex align-items-center gap-2" style="font-weight: 600; border-radius: 8px;">
                        <i class="fas fa-save"></i> <span>Save Details</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Dynamic Edit Job Modal Population
document.addEventListener('DOMContentLoaded', function () {
    const editModalEl = document.getElementById('editJobModal');
    if (editModalEl) {
        editModalEl.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (button && button.hasAttribute('data-id')) {
                const id   = button.getAttribute('data-id') || '';
                const name = button.getAttribute('data-name') || '';
                const desc = button.getAttribute('data-description') || '';

                const inputId   = document.getElementById('modal_edit_job_id');
                const inputName = document.getElementById('modal_edit_job_name');
                const inputDesc = document.getElementById('modal_edit_job_description');

                if (inputId) inputId.value = id;
                if (inputName) inputName.value = name;
                if (inputDesc) inputDesc.value = desc;
            }
        });
    }

    // Auto-open modal if URL has ?mode=add or ?mode=edit for backward compatibility
    <?php if ($mode === 'add'): ?>
    const addModal = new bootstrap.Modal(document.getElementById('addJobModal'));
    addModal.show();
    <?php elseif ($mode === 'edit' && $editJob): ?>
    const editModal = new bootstrap.Modal(document.getElementById('editJobModal'));
    editModal.show();
    <?php endif; ?>
});
</script>

<style>
/* Page Animations */
.main-content {
    animation: erpFadeInUp 0.35s ease-out;
}

/* Filter, Table & Modal Cards */
.erp-filter-card,
.erp-table-card,
.erp-modal-content {
    border-radius: 12px;
    background: #ffffff;
    border: 1px solid #edf2f7 !important;
}

.erp-modal-content {
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
    overflow: hidden;
}

/* Modal Smooth Entrance */
.modal.fade .modal-dialog {
    transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.25s ease-out;
    transform: translateY(-15px) scale(0.98);
}
.modal.show .modal-dialog {
    transform: translateY(0) scale(1);
}

/* User Avatar */
.user-avatar-circle {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #f1f5f9;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

/* Job Title */
.job-title-text {
    font-size: 0.92rem;
    color: #1e293b;
    letter-spacing: -0.2px;
}

/* Status Pill Buttons */
.badge-status-pill {
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.75rem;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
}
.badge-status-pill:hover {
    transform: scale(1.05);
}
.status-active-pill {
    background-color: rgba(39, 174, 96, 0.12);
    color: #27ae60;
    border-color: rgba(39, 174, 96, 0.25);
}
.status-inactive-pill {
    background-color: rgba(214, 48, 49, 0.12);
    color: #d63031;
    border-color: rgba(214, 48, 49, 0.25);
}

/* ACTION BUTTONS: GUARANTEED SAME HORIZONTAL LINE */
.action-buttons-group {
    display: inline-flex !important;
    flex-direction: row !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 6px !important;
    flex-wrap: nowrap !important;
    white-space: nowrap !important;
}

.action-btn {
    width: 32px !important;
    height: 32px !important;
    min-width: 32px !important;
    padding: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-radius: 8px !important;
    border: 1px solid #e2e8f0 !important;
    background: #ffffff !important;
    transition: all 0.2s ease !important;
}

.action-btn:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08) !important;
}

.btn-edit-job:hover {
    background: #fffbeb !important;
    border-color: #fde68a !important;
}

.btn-delete-job:hover {
    background: #fef2f2 !important;
    border-color: #fecaca !important;
}

.actions-cell {
    white-space: nowrap !important;
    width: 110px !important;
    min-width: 110px !important;
}
</style>
