<?php
// =========================================================================
// FILE: Sales/job_master.php
// PURPOSE: Sales Job Master Catalog & Rate Management
// 100% Preserved Functionality • Senior Developer Modern UI
// =========================================================================

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
        $base = 'job_master.php';
        if (!empty($params)) $base .= '?' . http_build_query($params);
        header("Location: $base");
        exit;
    }
}

// ---------- Handle POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $job_name = trim($_POST['job_name'] ?? '');
    $rate     = trim($_POST['rate'] ?? '');
    $desc     = trim($_POST['job_description'] ?? '');
    $uid      = 'Sales';

    if ($action === 'add') {
        $rate_val = ($rate !== '') ? (float)$rate : null;
        $stmt = $mysqli->prepare("INSERT INTO job_master (job_name, rate, job_description, status, created_by, created_date) VALUES (?, ?, ?, 'Active', ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("sdss", $job_name, $rate_val, $desc, $uid);
            $stmt->execute();
            $stmt->close();
            redirect_with(['msg' => "Job '{$job_name}' added successfully", 'type' => 'success']);
        }
    }

    if ($action === 'edit') {
        $id = (int)($_POST['job_id'] ?? 0);
        if ($id > 0) {
            $rate_val = ($rate !== '') ? (float)$rate : null;
            $stmt = $mysqli->prepare("UPDATE job_master SET job_name=?, rate=?, job_description=? WHERE job_id=?");
            if ($stmt) {
                $stmt->bind_param("sdsi", $job_name, $rate_val, $desc, $id);
                $stmt->execute();
                $stmt->close();
                redirect_with(['msg' => "Job '{$job_name}' updated successfully", 'type' => 'success']);
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['job_id'] ?? 0);
        if ($id > 0) {
            $stmt = $mysqli->prepare("DELETE FROM job_master WHERE job_id=?");
            if ($stmt) {
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
                redirect_with(['msg' => "Job deleted successfully", 'type' => 'warning']);
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['job_id'] ?? 0);
        if ($id > 0) {
            $stmt = $mysqli->prepare("UPDATE job_master SET status = IF(status='Active', 'Inactive', 'Active') WHERE job_id=?");
            if ($stmt) {
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
                redirect_with(['msg' => "Status updated successfully", 'type' => 'info']);
            }
        }
    }

    redirect_with();
}

// ---------- Filters & Query ----------
$q       = trim($_GET['q'] ?? '');
$fstatus = trim($_GET['status'] ?? '');
$sort    = trim($_GET['sort'] ?? 'job_id');
$dir     = strtolower(trim($_GET['dir'] ?? 'desc'));

$sortable = ['job_id', 'job_name', 'rate', 'status', 'created_date'];
if (!in_array($sort, $sortable, true)) $sort = 'job_id';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

$sql = "SELECT * FROM job_master WHERE 1=1";
$params = [];
$types = '';

if ($q !== '') {
    $sql .= " AND (job_name LIKE ? OR job_description LIKE ? OR CAST(rate AS CHAR) LIKE ?)";
    $like = "%$q%";
    $params = [$like, $like, $like];
    $types = 'sss';
}

if ($fstatus === 'Active' || $fstatus === 'Inactive') {
    $sql .= " AND status = ?";
    $params[] = $fstatus;
    $types .= 's';
}

$sql .= " ORDER BY $sort $dir";

if ($types) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $jobs = $stmt->get_result();
} else {
    $jobs = $mysqli->query($sql);
}

// Metrics
$metricsQuery = $mysqli->query("SELECT COUNT(*) as total, SUM(status='Active') as active FROM job_master");
$metrics = $metricsQuery ? $metricsQuery->fetch_assoc() : ['total' => 0, 'active' => 0];

$pageTitle = "Job Master";
$currentPage = "job_master.php";
require_once __DIR__ . '/header.php';
?>

<style>
/* =========================================================================
   SENIOR DEVELOPER STYLING & DESIGN SYSTEM
   ========================================================================= */
.main-content {
    padding-top: 85px;
    padding-bottom: 50px;
    background-color: #f8fafc;
    min-height: 100vh;
}
.header-icon-box {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: #eef2ff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.erp-filter-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
    border-radius: 14px;
}
.erp-table-card {
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
    overflow: hidden;
}
.table-senior {
    margin-bottom: 0;
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}
.table-senior thead th {
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
.table-senior tbody td {
    font-size: 0.86rem;
    vertical-align: middle;
    color: #334155;
    padding: 12px 16px;
    border-bottom: 1px solid #f1f5f9;
    transition: background-color 0.15s ease;
}
.table-senior tbody tr:hover td {
    background-color: #f8fafc;
}
.badge-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.74rem;
    font-weight: 600;
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.2s;
    background: none;
}
.badge-status-pill.status-active-pill {
    background-color: #dcfce7;
    color: #15803d;
    border-color: #bbf7d0;
}
.badge-status-pill.status-inactive-pill {
    background-color: #fee2e2;
    color: #b91c1c;
    border-color: #fecaca;
}
.badge-status-pill:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
}
.action-btn {
    width: 32px;
    height: 32px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    transition: all 0.2s;
}
.action-btn:hover {
    background: #f1f5f9;
    transform: translateY(-1px);
}
.erp-modal-content {
    border-radius: 16px;
    overflow: hidden;
}

/* Floating ERP Toast */
.erp-toast-wrapper {
    position: fixed;
    top: 25px;
    right: 25px;
    z-index: 1090;
    pointer-events: auto;
}
.erp-toast-card {
    min-width: 330px;
    max-width: 420px;
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
    border: 1px solid #e2e8f0;
    display: flex;
    align-items: flex-start;
    padding: 14px 16px;
    position: relative;
    overflow: hidden;
    animation: erpToastSlideIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
@keyframes erpToastSlideIn {
    from { transform: translateX(110%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
@keyframes erpToastSlideOut {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(110%); opacity: 0; }
}
.erp-toast-icon-box {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
    margin-right: 12px;
}
.erp-toast-success .erp-toast-icon-box { background: #dcfce7; color: #16a34a; }
.erp-toast-danger .erp-toast-icon-box { background: #fee2e2; color: #dc2626; }
.erp-toast-info .erp-toast-icon-box { background: #e0f2fe; color: #0284c7; }
.erp-toast-content { flex: 1; padding-right: 10px; }
.erp-toast-header { display: flex; align-items: center; gap: 6px; margin-bottom: 2px; }
.erp-toast-title { font-size: 0.88rem; font-weight: 700; color: #1e293b; }
.erp-toast-badge {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    padding: 2px 6px;
    border-radius: 4px;
    background: #f1f5f9;
    color: #475569;
}
.erp-toast-body { font-size: 0.82rem; color: #475569; line-height: 1.35; }
.erp-toast-close {
    background: none;
    border: none;
    color: #94a3b8;
    font-size: 14px;
    cursor: pointer;
    padding: 4px;
    line-height: 1;
    transition: color 0.15s;
}
.erp-toast-close:hover { color: #1e293b; }
.erp-toast-progress-bar {
    position: absolute;
    bottom: 0;
    left: 0;
    height: 3px;
    width: 100%;
    background: rgba(0, 0, 0, 0.05);
}
.erp-toast-progress-fill {
    height: 100%;
    width: 100%;
    animation: erpToastTimer 4.5s linear forwards;
}
.erp-toast-success .erp-toast-progress-fill { background: #16a34a; }
.erp-toast-danger .erp-toast-progress-fill { background: #dc2626; }
.erp-toast-info .erp-toast-progress-fill { background: #0284c7; }
@keyframes erpToastTimer { from { width: 100%; } to { width: 0%; } }
</style>

<div class="main-content">
    <div class="container-fluid px-lg-4 px-md-3 px-2">

        <!-- FLOATING ERP TOAST NOTIFICATION -->
        <?php if (!empty($_GET['msg'])): 
            $toastMsg  = h($_GET['msg']);
            $toastType = strtolower(trim($_GET['type'] ?? 'info'));
            
            $toastTitle = 'Notification';
            $toastIcon  = 'fa-info-circle';
            $toastBadge = 'Notice';
            $toastClass = 'erp-toast-info';
            
            if (stripos($toastMsg, 'delete') !== false || $toastType === 'warning' || $toastType === 'danger') {
                $toastTitle = 'Record Deleted';
                $toastIcon  = 'fa-trash-alt';
                $toastBadge = 'Deleted';
                $toastClass = 'erp-toast-danger';
            } elseif (stripos($toastMsg, 'add') !== false || stripos($toastMsg, 'create') !== false || $toastType === 'success') {
                $toastTitle = 'Successfully Added';
                $toastIcon  = 'fa-check-circle';
                $toastBadge = 'Added';
                $toastClass = 'erp-toast-success';
            } elseif (stripos($toastMsg, 'status') !== false) {
                $toastTitle = 'Status Updated';
                $toastIcon  = 'fa-toggle-on';
                $toastBadge = 'Status';
                $toastClass = 'erp-toast-info';
            } elseif (stripos($toastMsg, 'update') !== false) {
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
                const card = document.getElementById('erpToastCard');
                if (card) {
                    card.style.animation = 'erpToastSlideOut 0.3s cubic-bezier(0.4, 0, 0.2, 1) forwards';
                    setTimeout(() => {
                        const wrapper = document.getElementById('erpToastWrapper');
                        if (wrapper) wrapper.remove();
                    }, 300);
                }
            }

            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(dismissErpToast, 4500);
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('msg');
                    url.searchParams.delete('type');
                    window.history.replaceState({}, document.title, url.toString());
                } catch(e) {}
            });
        </script>
        <?php endif; ?>

        <!-- PAGE HEADER & ACTIONS -->
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
                    Sales department service catalog, production jobs, and rate management.
                </p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge rounded-pill bg-light text-secondary border px-3 py-2 fw-medium" style="font-size: 0.82rem;">
                    <i class="fas fa-layer-group text-primary me-1"></i>Total: <strong><?= number_format($metrics['total'] ?? 0) ?></strong> Jobs
                </span>
                <!-- TRIGGER ADD JOB MODAL -->
                <button type="button" class="btn btn-primary px-3 shadow-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addJobModal" style="font-weight: 600; border-radius: 8px;">
                    <i class="fas fa-plus"></i> <span>Add New Job</span>
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
                            <input type="text" name="q" value="<?= h($q) ?>" class="form-control border-start-0 ps-0" placeholder="Search by name, description or rate...">
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
                        <button type="submit" class="btn btn-primary px-3 shadow-sm d-flex align-items-center gap-1" style="font-weight: 600; border-radius: 8px;">
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
                <table class="table table-hover align-middle mb-0 table-senior">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 65px;">S.NO</th>
                            <th>NAME</th>
                            <th>RATE (₹)</th>
                            <th class="text-center" style="width: 130px;">STATUS</th>
                            <th>DESCRIPTION</th>
                            <th>CREATED BY</th>
                            <th class="text-center" style="width: 140px;">CREATED</th>
                            <th class="text-end pe-4" style="width: 110px;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($jobs && $jobs->num_rows): $i = 0; while ($row = $jobs->fetch_assoc()): $i++; ?>
                            <tr>
                                <td class="text-center fw-bold text-secondary"><?= $i ?></td>
                                <td>
                                    <strong class="text-dark"><?= h($row['job_name']) ?></strong>
                                </td>
                                <td>
                                    <?php if (!empty($row['rate']) && (float)$row['rate'] > 0): ?>
                                        <span class="badge bg-light text-dark border font-monospace">₹<?= number_format((float)$row['rate'], 2) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted opacity-50">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <form method="post" class="d-inline m-0 p-0">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="job_id" value="<?= (int)$row['job_id'] ?>">
                                        <button type="submit" class="badge-status-pill <?= $row['status'] === 'Active' ? 'status-active-pill' : 'status-inactive-pill' ?>" title="Click to toggle status">
                                            <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                            <?= h($row['status']) ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <?php if (!empty($row['job_description'])): ?>
                                        <span class="text-muted small"><?= h($row['job_description']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted opacity-50">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-secondary border font-monospace"><?= h($row['created_by']) ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="text-muted font-monospace small"><?= formatDate($row['created_date']) ?></span>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-inline-flex align-items-center justify-content-end gap-1">
                                        <!-- TRIGGER EDIT JOB MODAL -->
                                        <button type="button" 
                                                class="action-btn text-warning" 
                                                title="Edit Job"
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editJobModal"
                                                data-id="<?= (int)$row['job_id'] ?>"
                                                data-name="<?= h($row['job_name']) ?>"
                                                data-rate="<?= h($row['rate'] ?? '') ?>"
                                                data-description="<?= h($row['job_description'] ?? '') ?>">
                                            <i class="fas fa-pen-to-square"></i>
                                        </button>
                                        <form method="post" class="d-inline m-0 p-0" onsubmit="return confirm('Are you sure you want to delete this job?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="job_id" value="<?= (int)$row['job_id'] ?>">
                                            <button type="submit" 
                                                    class="action-btn text-danger" 
                                                    title="Delete Job">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="fas fa-briefcase fa-3x mb-3 text-secondary opacity-50"></i>
                                    <h5 class="fw-semibold">No jobs found</h5>
                                    <p class="mb-0 small">Try adjusting your search criteria or filters.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- =========================================================================
     MODAL: ADD NEW JOB (NO PAGE NAVIGATION)
     ========================================================================= -->
<div class="modal fade" id="addJobModal" tabindex="-1" aria-labelledby="addJobModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content erp-modal-content border-0 shadow">
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <div class="header-icon-box me-2" style="width: 38px; height: 38px;">
                        <i class="fas fa-briefcase text-primary"></i>
                    </div>
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
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Rate (₹)</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted">₹</span>
                            <input type="number" step="0.01" name="rate" class="form-control border-start-0 ps-0" placeholder="0.00">
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
                    <button type="submit" class="btn btn-primary px-4 shadow-sm d-flex align-items-center gap-2" style="font-weight: 600; border-radius: 8px;">
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
                    <div class="header-icon-box me-2" style="width: 38px; height: 38px; background: #fef3c7;">
                        <i class="fas fa-pen-to-square text-warning"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark fs-6" id="editJobModalLabel">Modify Job Details</h5>
                        <div class="text-muted" style="font-size: 0.75rem;">Update the job title, rate, and descriptions.</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" autocomplete="off" id="editJobForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="job_id" id="modal_edit_job_id" value="">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Job Title <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-briefcase"></i></span>
                            <input type="text" name="job_name" id="modal_edit_job_name" class="form-control border-start-0 ps-0" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Rate (₹)</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted">₹</span>
                            <input type="number" step="0.01" name="rate" id="modal_edit_rate" class="form-control border-start-0 ps-0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Description</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted align-items-start pt-2"><i class="fas fa-align-left"></i></span>
                            <textarea name="job_description" id="modal_edit_description" class="form-control border-start-0 ps-0" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top px-4 py-2 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                    <button type="submit" class="btn btn-warning px-4 shadow-sm text-dark d-flex align-items-center gap-2" style="font-weight: 600; border-radius: 8px;">
                        <i class="fas fa-check"></i> <span>Update Job</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const editModal = document.getElementById('editJobModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            if (!button) return;
            
            const id   = button.getAttribute('data-id') || '';
            const name = button.getAttribute('data-name') || '';
            const rate = button.getAttribute('data-rate') || '';
            const desc = button.getAttribute('data-description') || '';
            
            document.getElementById('modal_edit_job_id').value = id;
            document.getElementById('modal_edit_job_name').value = name;
            document.getElementById('modal_edit_rate').value = rate;
            document.getElementById('modal_edit_description').value = desc;
        });
    }
});
</script>
</body>
</html>
