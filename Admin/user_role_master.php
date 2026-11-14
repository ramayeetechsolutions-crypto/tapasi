<?php
require_once __DIR__ . '/../db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

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
        $base = 'user_role_master.php';
        if (!empty($params)) $base .= '?' . http_build_query($params);
        header("Location: $base");
        exit;
    }
}

// ---------- Handle POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $role_name = trim($_POST['role_name'] ?? '');
    $permissions = trim($_POST['permissions'] ?? '');

    $errors = [];
    if (($action === 'add' || $action === 'edit') && $role_name === '') $errors[] = 'Role Name is required.';
    if (($action === 'add' || $action === 'edit') && $permissions === '') $errors[] = 'Permissions are required.';

    if (!empty($errors)) {
        redirect_with(['msg' => implode(' ', $errors), 'type' => 'danger']);
    }

    if ($action === 'add') {
        $desc       = trim($_POST['role_description'] ?? '');
        $created_by = (int)($_SESSION['uid'] ?? 0);
        $status     = 'Active';

        $stmt = $mysqli->prepare("INSERT INTO user_role_master 
            (role_name, role_description, permissions, created_by, status, created_date) 
            VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssis", $role_name, $desc, $permissions, $created_by, $status);
        $stmt->execute();
        $stmt->close();

        redirect_with(['msg' => "Role '$role_name' added successfully", 'type' => 'success']);
    }

    if ($action === 'edit') {
        $id = (int)($_POST['role_id'] ?? 0);
        if ($id > 0) {
            $desc = trim($_POST['role_description'] ?? '');
            $stmt = $mysqli->prepare("UPDATE user_role_master SET role_name=?, role_description=?, permissions=? WHERE role_id=?");
            $stmt->bind_param("sssi", $role_name, $desc, $permissions, $id);
            $stmt->execute();
            $stmt->close();

            redirect_with(['msg' => "Role '$role_name' updated successfully", 'type' => 'success']);
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['role_id'] ?? 0);
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT role_name FROM user_role_master WHERE role_id=?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($rname);
            $res->fetch();
            $res->close();

            $del = $mysqli->prepare("DELETE FROM user_role_master WHERE role_id=?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();

            redirect_with(['msg' => "Role '$rname' deleted successfully", 'type' => 'warning']);
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['role_id'] ?? 0);
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT status, role_name FROM user_role_master WHERE role_id=?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($curStatus, $rname);
            $res->fetch();
            $res->close();

            if ($rname) {
                $newStatus = ($curStatus === 'Active') ? 'Inactive' : 'Active';
                $upd = $mysqli->prepare("UPDATE user_role_master SET status=? WHERE role_id=?");
                $upd->bind_param("si", $newStatus, $id);
                $upd->execute();
                $upd->close();

                redirect_with(['msg' => "Role '$rname' status changed to $newStatus", 'type' => 'info']);
            }
        }
    }

    redirect_with();
}

// ---------- Filters & Sorting ----------
$q       = trim($_GET['q'] ?? '');
$fstatus = trim($_GET['status'] ?? '');
$sort    = trim($_GET['sort'] ?? 'date_desc');

$where = "WHERE 1=1";
if ($q !== '') {
    $eq = $mysqli->real_escape_string($q);
    $where .= " AND (urm.role_name LIKE '%$eq%' OR urm.role_description LIKE '%$eq%' OR urm.permissions LIKE '%$eq%')";
}
if ($fstatus === 'Active' || $fstatus === 'Inactive') {
    $where .= " AND urm.status='$fstatus'";
}

switch ($sort) {
    case 'name_asc':
        $orderBy = "ORDER BY urm.role_name ASC";
        break;
    case 'name_desc':
        $orderBy = "ORDER BY urm.role_name DESC";
        break;
    case 'date_asc':
        $orderBy = "ORDER BY urm.created_date ASC";
        break;
    case 'date_desc':
    default:
        $orderBy = "ORDER BY urm.created_date DESC, urm.role_id DESC";
        break;
}

$sql = "SELECT urm.role_id, urm.role_name, urm.role_description, urm.permissions,
               urm.status, urm.created_date, em.employee_name AS created_name, urm.created_by
        FROM user_role_master urm
        LEFT JOIN employee_master em ON urm.created_by = em.employee_id
        $where
        $orderBy";
$roles = $mysqli->query($sql);
$total_displayed = $roles ? $roles->num_rows : 0;

// Metric counts
$metricsQuery = $mysqli->query("SELECT 
    COUNT(*) as total, 
    SUM(status='Active') as active, 
    SUM(status='Inactive') as inactive 
    FROM user_role_master");
$metrics = $metricsQuery ? $metricsQuery->fetch_assoc() : ['total' => 0, 'active' => 0, 'inactive' => 0];

$pageTitle = "User Role Master";
$currentPage = "user_role_master.php";
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
                        <i class="fas fa-user-shield text-primary fs-5"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.3px;">User Role Master</h4>
                        <div class="text-muted small">Configure application access levels, privileges, and system permissions.</div>
                    </div>
                </div>
            </div>
            <div class="col-md-5 col-12 text-md-end text-start">
                <button type="button" 
                        class="btn btn-primary px-3 py-2 shadow-sm erp-btn-add d-inline-flex align-items-center gap-2"
                        data-bs-toggle="modal" 
                        data-bs-target="#addRoleModal">
                    <i class="fas fa-plus"></i>
                    <span>Add Role</span>
                </button>
            </div>
        </div>

        <!-- KPI SUMMARY CARDS -->
        <div class="row g-3 mb-4">
            <div class="col-md-4 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Total Roles</div>
                            <div class="fs-4 fw-bold text-dark mt-1"><?= (int)($metrics['total'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-light text-primary">
                            <i class="fas fa-user-shield"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Active Roles</div>
                            <div class="fs-4 fw-bold text-success mt-1"><?= (int)($metrics['active'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-success-subtle text-success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Inactive Roles</div>
                            <div class="fs-4 fw-bold text-secondary mt-1"><?= (int)($metrics['inactive'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-light text-secondary">
                            <i class="fas fa-ban"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SEARCH AND FILTER PANEL -->
        <div class="card border-0 shadow-sm mb-4 filter-box rounded-3">
            <div class="card-body p-3">
                <form method="get" class="row g-2 align-items-center" autocomplete="off">
                    <div class="col-lg-4 col-md-5 col-12">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted ps-3">
                                <i class="fas fa-search"></i>
                            </span>
                            <input type="text" name="q" class="form-control border-start-0 ps-0" 
                                   placeholder="Search by role name, description, permissions..." 
                                   value="<?= h($q) ?>">
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-3 col-6">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-4 col-6">
                        <select name="sort" class="form-select">
                            <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Sort by Date (Newest)</option>
                            <option value="date_asc" <?= $sort === 'date_asc' ? 'selected' : '' ?>>Sort by Date (Oldest)</option>
                            <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Sort by Name (A → Z)</option>
                            <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Sort by Name (Z → A)</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-12 col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-dark w-100 py-2 d-flex align-items-center justify-content-center gap-1 shadow-sm">
                            <i class="fas fa-filter fs-6"></i> <span>Filter</span>
                        </button>
                        <?php if ($q !== '' || $fstatus !== '' || $sort !== 'date_desc'): ?>
                            <a href="user_role_master.php" class="btn btn-outline-secondary px-3 py-2" title="Reset Filters">
                                <i class="fas fa-rotate-left"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- DATA TABLE CARD -->
        <div class="card border-0 shadow-sm rounded-3 overflow-hidden table-container">
            <div class="table-responsive m-0 p-0">
                <table class="table table-hover align-middle mb-0 erp-modern-table" style="font-size: var(--erp-table-text-size, 12.5px);">
                    <thead>
                        <tr>
                            <th class="text-center py-3" style="width: 70px;">S.NO</th>
                            <th class="py-3" style="width: 220px;">ROLE INFORMATION</th>
                            <th class="py-3">PERMISSIONS</th>
                            <th class="text-center py-3" style="width: 140px;">STATUS</th>
                            <th class="py-3" style="width: 160px;">CREATED DATE</th>
                            <th class="text-center py-3" style="width: 120px;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $i = 1;
                        if ($roles && $roles->num_rows > 0):
                            while ($row = $roles->fetch_assoc()):
                        ?>
                            <tr>
                                <td class="text-center fw-semibold text-secondary" style="font-size: 0.85rem;">
                                    <?= $i++ ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="user-avatar-circle me-3">
                                            <i class="fas fa-user-shield text-secondary"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark fs-6 role-title"><?= h($row['role_name']) ?></div>
                                            <div class="text-muted" style="font-size: 0.72rem;"><?= !empty($row['role_description']) ? h($row['role_description']) : 'No description provided' ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-secondary" style="font-size: 0.8rem; max-width: 340px; word-break: break-word;">
                                        <span class="badge bg-light text-dark border me-1"><i class="fas fa-key text-muted me-1"></i><?= h($row['permissions']) ?></span>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <form method="post" class="d-inline m-0 p-0">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="role_id" value="<?= (int)$row['role_id'] ?>">
                                        <button type="submit" 
                                                class="badge-status-pill <?= $row['status'] === 'Active' ? 'status-active-pill' : 'status-inactive-pill' ?>" 
                                                onclick="return confirm('Change status of <?= addslashes(h($row['role_name'])) ?>?')" 
                                                title="Click to toggle status">
                                            <i class="fas fa-circle me-1" style="font-size: 0.55rem;"></i>
                                            <?= h($row['status']) ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <div class="text-secondary fw-semibold">
                                        <i class="far fa-calendar-alt me-1 text-muted"></i>
                                        <?= formatDate($row['created_date']) ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <!-- ACTIONS IN SAME LINE -->
                                    <div class="d-inline-flex align-items-center justify-content-center gap-1 action-btn-group">
                                        <button type="button" 
                                                class="btn btn-sm btn-light action-btn btn-edit-role" 
                                                onclick="openEditRoleModal(<?= (int)$row['role_id'] ?>, '<?= addslashes(h($row['role_name'])) ?>', '<?= addslashes(h($row['role_description'] ?? '')) ?>', '<?= addslashes(h($row['permissions'] ?? '')) ?>')"
                                                title="Edit Role"
                                                data-id="<?= (int)$row['role_id'] ?>"
                                                data-name="<?= h($row['role_name']) ?>">
                                            <i class="fas fa-edit text-warning"></i>
                                        </button>
                                        <form method="post" class="d-inline m-0 p-0" onsubmit="return confirm('Are you sure you want to delete role: <?= addslashes(h($row['role_name'])) ?>?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="role_id" value="<?= (int)$row['role_id'] ?>">
                                            <button type="submit" 
                                                    class="btn btn-sm btn-light action-btn btn-delete-role" 
                                                    title="Delete Role">
                                                <i class="fas fa-trash-alt text-danger"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <div class="empty-state py-4">
                                        <div class="empty-state-icon mb-3">
                                            <i class="fas fa-user-shield fa-3x text-muted opacity-50"></i>
                                        </div>
                                        <h5 class="fw-semibold text-dark">No Roles Found</h5>
                                        <p class="text-muted small mb-3">Try adjusting your filters or search keywords, or add a new role.</p>
                                        <button type="button" class="btn btn-primary btn-sm px-3 py-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#addRoleModal">
                                            <i class="fas fa-plus me-1"></i> Add Role
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white border-top py-3 px-4 d-flex align-items-center justify-content-between text-muted small">
                <div>Showing <strong><?= $total_displayed ?></strong> records</div>
                <div>Tapasi Solutions LLP • ERP System</div>
            </div>
        </div>

    </div>
</main>

<!-- =========================================================================
     MODAL: ADD NEW ROLE (NO PAGE NAVIGATION)
     ========================================================================= -->
<div class="modal fade" id="addRoleModal" tabindex="-1" aria-labelledby="addRoleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content erp-modal-content border-0 shadow">
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <span class="header-icon-box me-2">
                        <i class="fas fa-user-shield text-primary"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-dark fs-6" id="addRoleModalLabel">New Role Definition</h5>
                        <div class="text-muted" style="font-size: 0.75rem;">Create a new authorization role and define system permissions.</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" autocomplete="off" id="addRoleForm">
                <input type="hidden" name="action" value="add">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Role Name <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-tag"></i></span>
                            <input type="text" name="role_name" class="form-control border-start-0 ps-0" placeholder="e.g. Finance Auditor, Dispatch Manager, Senior Admin" required autofocus>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Role Description</label>
                        <textarea name="role_description" class="form-control" rows="2" placeholder="Brief outline of responsibilities..."></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Permissions <span class="text-danger">*</span></label>
                        <textarea name="permissions" class="form-control" rows="2" placeholder="e.g. read, write, export, approve" required></textarea>
                        <div class="form-text text-muted" style="font-size: 0.75rem;">Specify access tags, module scopes, or permission keys.</div>
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
     MODAL: EDIT ROLE (NO PAGE NAVIGATION)
     ========================================================================= -->
<div class="modal fade" id="editRoleModal" tabindex="-1" aria-labelledby="editRoleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content erp-modal-content border-0 shadow">
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <span class="header-icon-box me-2">
                        <i class="fas fa-pen-to-square text-warning"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-dark fs-6" id="editRoleModalLabel">Modify Role</h5>
                        <div class="text-muted" style="font-size: 0.75rem;">Update role details, scope, or access privileges.</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" autocomplete="off" id="editRoleForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="role_id" id="modal_edit_role_id" value="">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Role Name <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-tag"></i></span>
                            <input type="text" name="role_name" id="modal_edit_role_name" class="form-control border-start-0 ps-0" placeholder="e.g. Finance Auditor" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Role Description</label>
                        <textarea name="role_description" id="modal_edit_role_description" class="form-control" rows="2" placeholder="Brief outline..."></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Permissions <span class="text-danger">*</span></label>
                        <textarea name="permissions" id="modal_edit_permissions" class="form-control" rows="2" required></textarea>
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
function openEditRoleModal(id, name, desc, perm) {
    document.getElementById('modal_edit_role_id').value = id;
    document.getElementById('modal_edit_role_name').value = name;
    document.getElementById('modal_edit_role_description').value = desc;
    document.getElementById('modal_edit_permissions').value = perm;
    
    const editModal = new bootstrap.Modal(document.getElementById('editRoleModal'));
    editModal.show();
}
</script>

<style>
/* Page Styles */
.header-icon-box {
    width: 42px;
    height: 42px;
    background: rgba(52, 152, 219, 0.1);
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.stat-icon-circle {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

.stat-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.08) !important;
}

.user-avatar-circle {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    flex-shrink: 0;
}

/* Badge Status Pill */
.badge-status-pill {
    display: inline-flex;
    align-items: center;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all 0.2s ease;
}

.status-active-pill {
    background-color: #e8f8f0;
    color: #10b981;
    border-color: #a7f3d0;
}

.status-active-pill:hover {
    background-color: #d1fae5;
    transform: scale(1.02);
}

.status-inactive-pill {
    background-color: #f1f5f9;
    color: #64748b;
    border-color: #cbd5e1;
}

.status-inactive-pill:hover {
    background-color: #e2e8f0;
    transform: scale(1.02);
}

/* Action Button Group (Single line) */
.action-btn-group {
    display: inline-flex !important;
    align-items: center !important;
    flex-direction: row !important;
    gap: 6px !important;
    white-space: nowrap !important;
}

.action-btn {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    transition: all 0.15s ease;
    padding: 0;
}

.action-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.08);
}

.btn-edit-role:hover {
    background: #fffbeb !important;
    border-color: #fde68a !important;
}

.btn-delete-role:hover {
    background: #fef2f2 !important;
    border-color: #fecaca !important;
}

.erp-btn-add {
    background: #3498db;
    border-color: #3498db;
    font-weight: 600;
    border-radius: 8px;
    transition: all 0.2s ease;
}

.erp-btn-add:hover {
    background: #2980b9;
    border-color: #2980b9;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(52, 152, 219, 0.25);
}

/* Modal Styling */
.erp-modal-content {
    border-radius: 14px;
    overflow: hidden;
}
</style>
