<?php
// =========================================================================
// FILE: SubTL/process_master.php
// PURPOSE: SubTL Process Master Catalog & Workflow Definition
// 100% Preserved Functionality • Senior Developer Modern UI
// =========================================================================

// Start session BEFORE any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

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
        $base = 'process_master.php';
        if (!empty($params)) {
            $base .= '?' . http_build_query($params);
        }
        header("Location: $base");
        exit;
    }
}

// ---------- Handle POST ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action  = $_POST['action'] ?? '';
    $name    = trim($_POST['process_name'] ?? '');
    $desc    = trim($_POST['process_description'] ?? '');
    $creator = $_SESSION['username'] ?? 'SubTL';

    if (($action === 'add' || $action === 'edit') && $name === '') {
        redirect_with(['msg' => 'Process Name is required.', 'type' => 'danger']);
    }

    if ($action === 'add') {
        $status = 'Active';
        $stmt = $mysqli->prepare("INSERT INTO process_master (process_name, process_description, status, created_by, created_date) VALUES (?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("ssss", $name, $desc, $status, $creator);
            $stmt->execute();
            $stmt->close();
            redirect_with(['msg' => "Process '{$name}' created successfully.", 'type' => 'success']);
        }
    }

    if ($action === 'edit') {
        $id = (int)($_POST['process_id'] ?? 0);
        if ($id > 0) {
            $stmt = $mysqli->prepare("UPDATE process_master SET process_name=?, process_description=? WHERE process_id=?");
            if ($stmt) {
                $stmt->bind_param("ssi", $name, $desc, $id);
                $stmt->execute();
                $stmt->close();
                redirect_with(['msg' => "Process updated successfully.", 'type' => 'success']);
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['process_id'] ?? 0);
        if ($id > 0) {
            $stmt = $mysqli->prepare("DELETE FROM process_master WHERE process_id=?");
            if ($stmt) {
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
                redirect_with(['msg' => "Process removed successfully.", 'type' => 'warning']);
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['process_id'] ?? 0);
        if ($id > 0) {
            $mysqli->query("UPDATE process_master SET status = IF(status='Active', 'Inactive', 'Active') WHERE process_id=$id");
            redirect_with(['msg' => "Process status updated.", 'type' => 'info']);
        }
    }
}

// ---------- View / Edit Fallback Data ----------
$mode = $_GET['mode'] ?? 'view';
$editProcess = null;
if ($mode === 'edit' && isset($_GET['id'])) {
    $stmt = $mysqli->prepare("SELECT * FROM process_master WHERE process_id=?");
    if ($stmt) {
        $stmt->bind_param("i", $_GET['id']);
        $stmt->execute();
        $editProcess = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

// ---------- Filter & Search Query ----------
$q       = trim($_GET['q'] ?? '');
$fstatus = trim($_GET['status'] ?? '');
$where   = "WHERE 1=1";

if ($q !== '') {
    $escaped_q = $mysqli->real_escape_string($q);
    $where .= " AND (process_name LIKE '%$escaped_q%' OR process_description LIKE '%$escaped_q%')";
}
if ($fstatus !== '') {
    $escaped_status = $mysqli->real_escape_string($fstatus);
    $where .= " AND status='$escaped_status'";
}

$sql = "SELECT * FROM process_master $where ORDER BY process_id DESC";
$processes = $mysqli->query($sql);
$total_displayed = $processes ? $processes->num_rows : 0;

// Metric counts
$metricsQuery = $mysqli->query("SELECT 
    COUNT(*) as total, 
    SUM(status='Active') as active, 
    SUM(status='Inactive') as inactive 
    FROM process_master");
$metrics = $metricsQuery ? $metricsQuery->fetch_assoc() : ['total' => 0, 'active' => 0, 'inactive' => 0];

$pageTitle = "Process Master";
$currentPage = "process_master.php";
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
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 erp-page-header">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1" style="font-size: 0.78rem;">
                        <li class="breadcrumb-item"><a href="dashboard_exec.php" class="text-decoration-none text-muted"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                        <li class="breadcrumb-item text-muted">Master</li>
                        <li class="breadcrumb-item active text-primary fw-semibold" aria-current="page">Process Master</li>
                    </ol>
                </nav>
                <h3 class="mb-0 fw-bold d-flex align-items-center" style="color: #1e293b; font-size: 1.45rem;">
                    <i class="fas fa-sitemap text-primary me-2"></i>Process Master
                </h3>
                <p class="text-muted mb-0" style="font-size: 0.85rem; margin-top: 2px;">
                    Standardize production workflows, operational steps, and milestones across jobs.
                </p>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge rounded-pill bg-light text-secondary border px-3 py-2 fw-medium erp-stat-pill" style="font-size: 0.82rem;">
                    <i class="fas fa-layer-group text-primary me-1"></i>Total: <strong><?= number_format($metrics['total'] ?? 0) ?></strong> Processes
                </span>
                <span class="badge rounded-pill bg-light text-success border px-3 py-2 fw-medium erp-stat-pill" style="font-size: 0.82rem;">
                    <i class="fas fa-check-circle text-success me-1"></i><strong><?= number_format($metrics['active'] ?? 0) ?></strong> Active
                </span>
                <!-- TRIGGER ADD PROCESS MODAL -->
                <button type="button" class="btn btn-sm btn-primary px-3 shadow-sm d-flex align-items-center gap-2 erp-btn-add" data-bs-toggle="modal" data-bs-target="#addProcessModal" style="font-weight: 600; border-radius: 8px;">
                    <i class="fas fa-plus"></i> <span>Add New Process</span>
                </button>
            </div>
        </div>

        <!-- FILTER & SEARCH CARD -->
        <div class="card erp-filter-card mb-4 border-0 shadow-sm">
            <div class="card-body p-3">
                <form method="get" class="row g-2 align-items-center" autocomplete="off">
                    <div class="col-lg-5 col-md-6 col-12">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-search"></i></span>
                            <input type="text" name="q" value="<?= h($q) ?>" class="form-control border-start-0 ps-0" placeholder="Search by process name or description...">
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
                        <button type="submit" class="btn btn-primary px-3 shadow-sm d-flex align-items-center gap-1 erp-btn-filter" style="font-weight: 600; border-radius: 8px;">
                            <i class="fas fa-filter"></i> <span>Filter</span>
                        </button>
                        <a href="process_master.php" class="btn btn-outline-secondary px-3 d-flex align-items-center gap-1" style="border-radius: 8px;">
                            <i class="fas fa-undo"></i> <span>Reset</span>
                        </a>
                        <?php if ($q !== '' || $fstatus !== ''): ?>
                            <span class="text-muted small ms-auto d-none d-xl-inline">
                                Found <strong><?= $total_displayed ?></strong> match<?= $total_displayed === 1 ? '' : 'es' ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- PROCESS MASTER TABLE CARD -->
        <div class="card erp-table-card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 65px;">S.No</th>
                            <th>Process Details</th>
                            <th class="text-center" style="width: 140px;">Status</th>
                            <th style="width: 220px;">Metadata</th>
                            <th class="text-end pe-4 text-nowrap actions-cell" style="width: 110px; white-space: nowrap;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($processes && $processes->num_rows): $i = 0; while ($row = $processes->fetch_assoc()): $i++; ?>
                            <tr class="erp-table-row" style="--row-index: <?= $i ?>;">
                                <td class="text-center text-muted fw-semibold" style="font-size: 0.85rem;"><?= $i ?></td>
                                <td>
                                    <div class="d-flex align-items-start">
                                        <div class="process-avatar-box me-2 mt-1">
                                            <i class="fas fa-cogs text-primary" style="font-size: 0.85rem;"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark process-title-text"><?= h($row['process_name']) ?></div>
                                            <?php if (!empty($row['process_description'])): ?>
                                                <div class="text-muted small text-truncate" style="max-width: 420px; font-size: 0.8rem;"><?= h($row['process_description']) ?></div>
                                            <?php else: ?>
                                                <div class="text-muted small fst-italic" style="font-size: 0.78rem;">No description provided</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <form method="post" class="d-inline m-0 p-0">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="process_id" value="<?= (int)$row['process_id'] ?>">
                                        <button type="submit" class="badge-status-pill <?= $row['status'] === 'Active' ? 'status-active-pill' : 'status-inactive-pill' ?>" onclick="return confirm('Change status of <?= addslashes(h($row['process_name'])) ?>?')" title="Click to toggle status">
                                            <span class="status-dot me-1"></span> <?= h($row['status']) ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <div class="d-flex align-items-center">
                                            <div class="user-avatar-circle me-2">
                                                <i class="fas fa-user text-muted" style="font-size: 0.72rem;"></i>
                                            </div>
                                            <span class="small fw-semibold text-secondary" style="font-size: 0.82rem;"><?= h($row['created_by']) ?></span>
                                        </div>
                                        <div class="text-muted d-flex align-items-center" style="font-size: 0.76rem; padding-left: 2px;">
                                            <i class="far fa-calendar-alt me-2 text-muted" style="font-size: 0.78rem;"></i><?= formatDate($row['created_date']) ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end pe-4 text-nowrap actions-cell" style="white-space: nowrap; width: 110px;">
                                    <!-- Actions Strictly in Same Horizontal Line -->
                                    <div class="action-buttons-group d-inline-flex align-items-center justify-content-end gap-1 flex-nowrap" style="white-space: nowrap;">
                                        <!-- TRIGGER EDIT PROCESS MODAL VIA DATA ATTRIBUTES -->
                                        <button type="button" 
                                                class="btn btn-sm btn-light action-btn btn-edit-process" 
                                                title="Edit Process"
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editProcessModal"
                                                data-id="<?= (int)$row['process_id'] ?>"
                                                data-name="<?= h($row['process_name']) ?>"
                                                data-description="<?= h($row['process_description'] ?? '') ?>">
                                            <i class="fas fa-edit text-warning"></i>
                                        </button>
                                        <form method="post" class="d-inline m-0 p-0" onsubmit="return confirm('Are you sure you want to delete process: <?= addslashes(h($row['process_name'])) ?>?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="process_id" value="<?= (int)$row['process_id'] ?>">
                                            <button type="submit" 
                                                    class="btn btn-sm btn-light action-btn btn-delete-process" 
                                                    title="Delete Process">
                                                <i class="fas fa-trash-alt text-danger"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-5">
                                    <div class="py-4">
                                        <i class="fas fa-sitemap text-muted opacity-25 fa-3x mb-3"></i>
                                        <h6 class="text-secondary fw-bold">No Processes Found</h6>
                                        <p class="text-muted small mb-0">No process records matched your search or filter criteria.</p>
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
     MODAL: ADD NEW PROCESS (NO PAGE NAVIGATION)
     ========================================================================= -->
<div class="modal fade" id="addProcessModal" tabindex="-1" aria-labelledby="addProcessModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content erp-modal-content border-0 shadow">
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <span class="header-icon-box me-2">
                        <i class="fas fa-folder-plus text-primary"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-dark fs-6" id="addProcessModalLabel">New Process Definition</h5>
                        <div class="text-muted" style="font-size: 0.75rem;">Add a new operational step or stage to the catalog.</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" autocomplete="off" id="addProcessForm">
                <input type="hidden" name="action" value="add">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Process Name <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-tag"></i></span>
                            <input type="text" name="process_name" class="form-control border-start-0 ps-0" placeholder="e.g. Quality Inspection, Direct HTML, Pagination" required autofocus>
                        </div>
                        <div class="form-text text-muted" style="font-size: 0.75rem;">Give a distinct title or abbreviation for the operational step.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Process Description</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted align-items-start pt-2"><i class="fas fa-align-left"></i></span>
                            <textarea name="process_description" class="form-control border-start-0 ps-0" rows="3" placeholder="Briefly specify the tasks, deliverables, or quality criteria for this process..."></textarea>
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
     MODAL: EDIT PROCESS (DYNAMIC JAVASCRIPT POPULATION)
     ========================================================================= -->
<div class="modal fade" id="editProcessModal" tabindex="-1" aria-labelledby="editProcessModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content erp-modal-content border-0 shadow">
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <span class="header-icon-box me-2">
                        <i class="fas fa-pen-to-square text-warning"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-dark fs-6" id="editProcessModalLabel">Modify Process Definition</h5>
                        <div class="text-muted" style="font-size: 0.75rem;">Update the process title or operational description.</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" autocomplete="off" id="editProcessForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="process_id" id="modal_edit_process_id" value="<?= (int)($editProcess['process_id'] ?? 0) ?>">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Process Name <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-tag"></i></span>
                            <input type="text" name="process_name" id="modal_edit_process_name" class="form-control border-start-0 ps-0" placeholder="e.g. Quality Inspection, Direct HTML" required value="<?= h($editProcess['process_name'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Process Description</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted align-items-start pt-2"><i class="fas fa-align-left"></i></span>
                            <textarea name="process_description" id="modal_edit_process_description" class="form-control border-start-0 ps-0" rows="3" placeholder="Briefly specify the tasks, deliverables, or quality criteria..."><?= h($editProcess['process_description'] ?? '') ?></textarea>
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
// Dynamic Edit Modal Population
document.addEventListener('DOMContentLoaded', function () {
    const editModalEl = document.getElementById('editProcessModal');
    if (editModalEl) {
        editModalEl.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (button && button.hasAttribute('data-id')) {
                const id   = button.getAttribute('data-id') || '';
                const name = button.getAttribute('data-name') || '';
                const desc = button.getAttribute('data-description') || '';

                const inputId   = document.getElementById('modal_edit_process_id');
                const inputName = document.getElementById('modal_edit_process_name');
                const inputDesc = document.getElementById('modal_edit_process_description');

                if (inputId) inputId.value = id;
                if (inputName) inputName.value = name;
                if (inputDesc) inputDesc.value = desc;
            }
        });
    }

    // Auto-open modal if URL has ?mode=add or ?mode=edit for backward compatibility
    <?php if ($mode === 'add'): ?>
    const addModal = new bootstrap.Modal(document.getElementById('addProcessModal'));
    addModal.show();
    <?php elseif ($mode === 'edit' && $editProcess): ?>
    const editModal = new bootstrap.Modal(document.getElementById('editProcessModal'));
    editModal.show();
    <?php endif; ?>
});
</script>

<style>
/* =========================================================================
   SENIOR DEVELOPER ERP ANIMATIONS & MODAL STYLES
   ========================================================================= */

@keyframes erpFadeInUp {
    0% {
        opacity: 0;
        transform: translateY(12px);
    }
    100% {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes erpRowEntrance {
    0% {
        opacity: 0;
        transform: translateY(8px);
    }
    100% {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes erpPulseGreen {
    0%, 100% {
        box-shadow: 0 0 0 0 rgba(39, 174, 96, 0.45);
    }
    50% {
        box-shadow: 0 0 0 5px rgba(39, 174, 96, 0);
    }
}

.main-content {
    animation: erpFadeInUp 0.35s cubic-bezier(0.16, 1, 0.3, 1) both;
}

.erp-page-header {
    animation: erpFadeInUp 0.3s ease-out both;
}

.erp-filter-card {
    animation: erpFadeInUp 0.35s ease-out 0.05s both;
}

.erp-table-card {
    animation: erpFadeInUp 0.4s ease-out 0.1s both;
}

/* Staggered Row Transitions */
.erp-table-row {
    animation: erpRowEntrance 0.3s ease-out both;
    animation-delay: calc(min(var(--row-index, 1), 20) * 0.018s);
    transition: background-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
}

.erp-table-card table tbody tr:hover {
    background-color: #f8fafc !important;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.03);
}

.erp-table-card table thead th {
    background-color: #5a6a7a !important;
    color: #ffffff !important;
    font-weight: 700;
    font-size: 0.76rem;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    white-space: nowrap;
    border-bottom: 1px solid #4a5568;
    vertical-align: middle;
    padding: 12px 16px;
}

/* Form, Filter & Modal Cards */
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

/* Header Box Icons */
.header-icon-box {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: rgba(67, 97, 238, 0.1);
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.process-avatar-box {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    background: #f1f5f9;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: transform 0.2s ease, background-color 0.2s ease;
}

.erp-table-card table tbody tr:hover .process-avatar-box {
    background: rgba(67, 97, 238, 0.12);
    transform: scale(1.08);
}

/* User Avatar */
.user-avatar-circle {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: #f1f5f9;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

/* Process Title */
.process-title-text {
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

.status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    display: inline-block;
}

.status-active-pill {
    background-color: rgba(39, 174, 96, 0.12);
    color: #27ae60;
    border-color: rgba(39, 174, 96, 0.25);
}

.status-active-pill .status-dot {
    background-color: #27ae60;
    animation: erpPulseGreen 2s infinite ease-in-out;
}

.status-inactive-pill {
    background-color: rgba(214, 48, 49, 0.12);
    color: #d63031;
    border-color: rgba(214, 48, 49, 0.25);
}

.status-inactive-pill .status-dot {
    background-color: #d63031;
}

/* Stat Pill Badges in Header */
.erp-stat-pill {
    transition: all 0.2s ease;
}
.erp-stat-pill:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
}

/* Button Animations */
.erp-btn-add,
.erp-btn-filter,
.erp-btn-save {
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
}

.erp-btn-add:hover,
.erp-btn-filter:hover,
.erp-btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(67, 97, 238, 0.25) !important;
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

.btn-edit-process:hover {
    background: #fffbeb !important;
    border-color: #fde68a !important;
}

.btn-delete-process:hover {
    background: #fef2f2 !important;
    border-color: #fecaca !important;
}

.actions-cell {
    white-space: nowrap !important;
    width: 110px !important;
    min-width: 110px !important;
}
</style>
