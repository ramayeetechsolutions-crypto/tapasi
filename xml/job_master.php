<?php
// =========================================================================
// FILE: xml/job_master.php
// PURPOSE: XML Team Lead Job Master Catalog
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
        return (!empty($d) && $d != '0000-00-00' && $d != '0000-00-00 00:00:00') ? date('d-m-Y', strtotime($d)) : '—';
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
    $uid      = $_SESSION['uid'] ?? null;

    $errors = [];
    if (($action === 'add' || $action === 'edit') && $job_name === '') {
        $errors[] = 'Job Name is required.';
    }

    if (!empty($errors)) {
        redirect_with(['msg' => implode(' ', $errors), 'type' => 'danger']);
    }

    if ($action === 'add') {
        $rate_val = ($rate !== '') ? (float)$rate : null;
        $status = 'Active';
        $stmt = $mysqli->prepare("INSERT INTO job_master (job_name, rate, job_description, status, created_by, created_date) VALUES (?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("sdssi", $job_name, $rate_val, $desc, $status, $uid);
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
            $name = '';
            $res = $mysqli->prepare("SELECT job_name FROM job_master WHERE job_id=?");
            if ($res) {
                $res->bind_param("i", $id);
                $res->execute();
                $row = $res->get_result()->fetch_assoc();
                $name = $row['job_name'] ?? '';
                $res->close();
            }
            $del = $mysqli->prepare("DELETE FROM job_master WHERE job_id=?");
            if ($del) {
                $del->bind_param("i", $id);
                $del->execute();
                $del->close();
            }
            redirect_with(['msg' => "Job '{$name}' deleted", 'type' => 'warning']);
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['job_id'] ?? 0);
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT status, job_name FROM job_master WHERE job_id=?");
            if ($res) {
                $res->bind_param("i", $id);
                $res->execute();
                $row = $res->get_result()->fetch_assoc();
                $res->close();
                if ($row) {
                    $newStatus = ($row['status'] === 'Active') ? 'Inactive' : 'Active';
                    $upd = $mysqli->prepare("UPDATE job_master SET status=? WHERE job_id=?");
                    if ($upd) {
                        $upd->bind_param("si", $newStatus, $id);
                        $upd->execute();
                        $upd->close();
                        redirect_with(['msg' => "Job '{$row['job_name']}' status changed to {$newStatus}", 'type' => 'info']);
                    }
                }
            }
        }
    }

    redirect_with();
}

// ---------- View / Edit Mode ----------
$mode = $_GET['mode'] ?? 'view';
$editJob = null;
if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM job_master WHERE job_id=?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $editJob = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
    if (!$editJob) $mode = 'view';
}

// ---------- Filters & Sorting ----------
$q       = trim($_GET['q'] ?? '');
$fstatus = trim($_GET['status'] ?? '');
$sort    = trim($_GET['sort'] ?? 'created_date');
$dir     = strtolower(trim($_GET['dir'] ?? 'desc'));

$sortable = ['job_id', 'job_name', 'rate', 'status', 'created_date'];
if (!in_array($sort, $sortable, true)) $sort = 'created_date';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

// Build query
$sql  = "SELECT jm.job_id, jm.job_name, jm.rate, jm.status, jm.job_description, 
                jm.created_by, jm.created_date, em.role_name AS created_name
         FROM job_master jm
         LEFT JOIN user_role_master em ON jm.created_by = em.role_id";
$cond = []; 
$params = []; 
$types = '';

if ($q !== '') { 
    $cond[] = "(jm.job_name LIKE ? OR jm.job_description LIKE ? OR CAST(jm.rate AS CHAR) LIKE ?)"; 
    $like = "%$q%"; 
    $params = [$like, $like, $like]; 
    $types = 'sss'; 
}
if ($fstatus === 'Active' || $fstatus === 'Inactive') { 
    $cond[] = "jm.status = ?"; 
    $params[] = $fstatus; 
    $types .= 's'; 
}
if ($cond) {
    $sql .= " WHERE " . implode(" AND ", $cond);
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

$pageTitle = "Job Master";
$currentPage = "job_master.php";
require 'header.php';
?>

<style>
/* =========================================================================
   SENIOR DEVELOPER STYLING & CLEAN CARD ARCHITECTURE
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
.filter-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
    border-radius: 14px;
}
.emp-card-container {
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
.status-pill-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.74rem;
    font-weight: 600;
    border: 1px solid transparent;
}
.status-pill-btn.active {
    background-color: #dcfce7;
    color: #15803d;
    border-color: #bbf7d0;
}
.status-pill-btn.inactive {
    background-color: #f1f5f9;
    color: #64748b;
    border-color: #e2e8f0;
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
                $toastTitle = 'Notice';
                $toastIcon  = 'fa-trash-alt';
                $toastBadge = 'Deleted';
                $toastClass = 'erp-toast-danger';
            } elseif (stripos($toastMsg, 'success') !== false || $toastType === 'success') {
                $toastTitle = 'Success';
                $toastIcon  = 'fa-check-circle';
                $toastBadge = 'Success';
                $toastClass = 'erp-toast-success';
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

        <!-- PAGE HEADER / ACTIONS -->
        <div class="row align-items-center mb-4">
            <div class="col-md-7 col-12 mb-md-0 mb-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="header-icon-box">
                        <i class="fas fa-briefcase text-primary fs-5"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.3px;">Job Master</h4>
                        <div class="text-muted small">Service catalog, production job definitions, and workflow specifications.</div>
                    </div>
                </div>
            </div>
            <div class="col-md-5 col-12 text-md-end text-start">
                <span class="badge rounded-pill bg-white text-secondary border px-3 py-2 shadow-sm" style="font-size: 0.82rem;">
                    <i class="fas fa-layer-group text-primary me-1"></i> Total: <strong><?= number_format($jobs ? $jobs->num_rows : 0) ?></strong> Jobs
                </span>
            </div>
        </div>

        <!-- FILTER & SEARCH PANEL -->
        <div class="card border-0 shadow-sm mb-4 filter-box rounded-3">
            <div class="card-body p-3">
                <form method="get" class="row g-2 align-items-center" autocomplete="off">
                    <!-- Search Input -->
                    <div class="col-lg-4 col-md-5 col-12">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-search"></i></span>
                            <input type="text" name="q" value="<?= h($q) ?>" class="form-control border-start-0 ps-0" placeholder="Search job name / desc / rate...">
                        </div>
                    </div>
                    <!-- Status Filter -->
                    <div class="col-lg-2 col-md-3 col-6">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="Active" <?= $fstatus==='Active'?'selected':'' ?>>Active Only</option>
                            <option value="Inactive" <?= $fstatus==='Inactive'?'selected':'' ?>>Inactive Only</option>
                        </select>
                    </div>
                    <!-- Sort Filter -->
                    <div class="col-lg-2 col-md-3 col-6">
                        <select name="sort" class="form-select">
                            <?php foreach($sortable as $s): ?>
                                <option value="<?= $s ?>" <?= $sort===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Action Buttons -->
                    <div class="col-lg-4 col-md-12 col-12 d-flex align-items-center gap-2">
                        <button type="submit" class="btn btn-primary px-3 shadow-sm d-flex align-items-center gap-1" style="font-weight: 600; border-radius: 8px;">
                            <i class="fas fa-filter"></i> <span>Search</span>
                        </button>
                        <a href="job_master.php" class="btn btn-outline-secondary px-3 d-flex align-items-center gap-1" style="border-radius: 8px;">
                            <i class="fas fa-undo"></i> <span>Clear</span>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- MAIN TABLE CARD -->
        <div class="card border-0 shadow-sm rounded-3 overflow-hidden emp-card-container">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-senior" style="width:100%">
                        <thead>
                            <tr>
                                <th width="65" class="text-center">S.NO</th>
                                <th>NAME</th>
                                <th class="text-center" width="130">STATUS</th>
                                <th>DESCRIPTION</th>
                                <th>CREATED BY</th>
                                <th class="text-center" width="140">CREATED</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($jobs && $jobs->num_rows > 0): 
                                $i = 0; 
                                while ($row = $jobs->fetch_assoc()): 
                                    $i++;
                            ?>
                            <tr>
                                <td class="text-center fw-bold text-secondary"><?= $i ?></td>
                                <td>
                                    <strong class="text-dark"><?= h($row['job_name']) ?></strong>
                                </td>
                                <td class="text-center">
                                    <span class="status-pill-btn <?= $row['status'] === 'Active' ? 'active' : 'inactive' ?>">
                                        <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                        <?= h($row['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($row['job_description'])): ?>
                                        <span class="text-muted small"><?= h($row['job_description']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted opacity-50">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $creator = trim((string)($row['created_name'] ?: $row['created_by']));
                                    if (!empty($creator)): ?>
                                        <span class="badge bg-light text-secondary border font-monospace"><?= h($creator) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted opacity-50">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="text-muted font-monospace small"><?= formatDate($row['created_date']) ?></span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
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
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
