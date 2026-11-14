<?php
// Start session BEFORE any output
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
        $base = 'team_master.php';
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

    if ($action === 'add' || $action === 'edit') {
        $name = trim($_POST['team_name'] ?? '');

        if ($name === '') {
            redirect_with(['msg' => 'Team Name is required.', 'type' => 'danger']);
        }

        if ($action === 'add') {
            // Check if team already exists
            $check_stmt = $mysqli->prepare("SELECT team_id FROM team_master WHERE LOWER(team_name) = LOWER(?)");
            $check_stmt->bind_param("s", $name);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                redirect_with(['msg' => "Team '$name' already exists.", 'type' => 'danger']);
            }
            $check_stmt->close();

            $stmt = $mysqli->prepare("INSERT INTO team_master (team_name, status, created_date) VALUES (?, 'Active', NOW())");
            $stmt->bind_param("s", $name);

            if ($stmt->execute()) {
                redirect_with(['msg' => "Team '$name' added successfully.", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding team: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }

        if ($action === 'edit') {
            $id = (int)($_POST['team_id'] ?? 0);

            if ($id > 0) {
                // Check if duplicate exists for other ID
                $check_stmt = $mysqli->prepare("SELECT team_id FROM team_master WHERE LOWER(team_name) = LOWER(?) AND team_id != ?");
                $check_stmt->bind_param("si", $name, $id);
                $check_stmt->execute();
                $check_stmt->store_result();

                if ($check_stmt->num_rows > 0) {
                    $check_stmt->close();
                    redirect_with(['msg' => "Another team named '$name' already exists.", 'type' => 'danger']);
                }
                $check_stmt->close();

                $stmt = $mysqli->prepare("UPDATE team_master SET team_name = ? WHERE team_id = ?");
                $stmt->bind_param("si", $name, $id);

                if ($stmt->execute()) {
                    redirect_with(['msg' => "Team updated successfully.", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating team: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['team_id'] ?? 0);
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT team_name FROM team_master WHERE team_id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($team_name);
            $res->fetch();
            $res->close();

            $del = $mysqli->prepare("DELETE FROM team_master WHERE team_id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();

            redirect_with(['msg' => "Team '$team_name' deleted successfully.", 'type' => 'warning']);
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['team_id'] ?? 0);
        if ($id > 0) {
            $mysqli->query("UPDATE team_master SET status = IF(status='Active', 'Inactive', 'Active') WHERE team_id = $id");
            redirect_with(['msg' => "Team status updated.", 'type' => 'info']);
        }
    }

    redirect_with();
}

// ---------- View / Edit Fallback ----------
$mode = $_GET['mode'] ?? 'view';
$editTeam = null;
if ($mode === 'edit' && isset($_GET['id'])) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM team_master WHERE team_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editTeam = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

// ---------- Filter & Search Query ----------
$q       = trim($_GET['q'] ?? '');
$fstatus = trim($_GET['status'] ?? '');
$sort    = trim($_GET['sort'] ?? 'date_desc');
$where   = "WHERE 1=1";

if ($q !== '') {
    $escaped_q = $mysqli->real_escape_string($q);
    $where .= " AND team_name LIKE '%$escaped_q%'";
}
if ($fstatus !== '') {
    $escaped_status = $mysqli->real_escape_string($fstatus);
    $where .= " AND status='$escaped_status'";
}

switch ($sort) {
    case 'name_asc':
        $orderBy = "ORDER BY team_name ASC";
        break;
    case 'name_desc':
        $orderBy = "ORDER BY team_name DESC";
        break;
    case 'date_asc':
        $orderBy = "ORDER BY created_date ASC";
        break;
    case 'date_desc':
    default:
        $orderBy = "ORDER BY created_date DESC, team_id DESC";
        break;
}

$sql = "SELECT * FROM team_master $where $orderBy";
$teams = $mysqli->query($sql);
$total_displayed = $teams ? $teams->num_rows : 0;

// Metric counts
$metricsQuery = $mysqli->query("SELECT 
    COUNT(*) as total, 
    SUM(status='Active') as active, 
    SUM(status='Inactive') as inactive 
    FROM team_master");
$metrics = $metricsQuery ? $metricsQuery->fetch_assoc() : ['total' => 0, 'active' => 0, 'inactive' => 0];

$pageTitle = "Team Master";
$currentPage = "team_master.php";
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
                        <li class="breadcrumb-item"><a href="dashboard.php" class="text-decoration-none text-muted"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                        <li class="breadcrumb-item text-muted">Master</li>
                        <li class="breadcrumb-item active text-primary fw-semibold" aria-current="page">Team Master</li>
                    </ol>
                </nav>
                <h3 class="mb-0 fw-bold d-flex align-items-center" style="color: #1e293b; font-size: 1.45rem;">
                    <i class="fas fa-users-gear text-primary me-2"></i>Team Master
                </h3>
                <p class="text-muted mb-0" style="font-size: 0.85rem; margin-top: 2px;">
                    Organize functional production teams, departments, and project squads.
                </p>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge rounded-pill bg-light text-secondary border px-3 py-2 fw-medium erp-stat-pill" style="font-size: 0.82rem;">
                    <i class="fas fa-layer-group text-primary me-1"></i>Total: <strong><?= number_format($metrics['total'] ?? 0) ?></strong> Teams
                </span>
                <span class="badge rounded-pill bg-light text-success border px-3 py-2 fw-medium erp-stat-pill" style="font-size: 0.82rem;">
                    <i class="fas fa-check-circle text-success me-1"></i><strong><?= number_format($metrics['active'] ?? 0) ?></strong> Active
                </span>
                <!-- TRIGGER ADD TEAM MODAL -->
                <button type="button" class="btn btn-sm btn-primary px-3 shadow-sm d-flex align-items-center gap-2 erp-btn-add" data-bs-toggle="modal" data-bs-target="#addTeamModal" style="font-weight: 600; border-radius: 8px;">
                    <i class="fas fa-plus"></i> <span>Add New Team</span>
                </button>
            </div>
        </div>

        <!-- FILTER & SEARCH CARD -->
        <div class="card erp-filter-card mb-4 border-0 shadow-sm">
            <div class="card-body p-3">
                <form method="get" class="row g-2 align-items-center">
                    <div class="col-lg-4 col-md-5 col-12">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-search"></i></span>
                            <input type="text" name="q" value="<?= h($q) ?>" class="form-control border-start-0 ps-0" placeholder="Search teams by name...">
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-3 col-6">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active Only</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive Only</option>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-4 col-6">
                        <select name="sort" class="form-select">
                            <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Sort by Date (Newest)</option>
                            <option value="date_asc" <?= $sort === 'date_asc' ? 'selected' : '' ?>>Sort by Date (Oldest)</option>
                            <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Sort by Name (A-Z)</option>
                            <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Sort by Name (Z-A)</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-12 col-12 d-flex align-items-center gap-2">
                        <button type="submit" class="btn btn-dark px-3 shadow-sm d-flex align-items-center gap-1 flex-grow-1 justify-content-center erp-btn-filter" style="font-weight: 600; border-radius: 8px;">
                            <i class="fas fa-filter"></i> <span>Filter</span>
                        </button>
                        <a href="team_master.php" class="btn btn-outline-secondary px-3 d-flex align-items-center gap-1" style="border-radius: 8px;" title="Reset filters">
                            <i class="fas fa-undo"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- TEAM MASTER TABLE CARD -->
        <div class="card erp-table-card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 65px;">S.No</th>
                            <th>Team Details</th>
                            <th class="text-center" style="width: 140px;">Status</th>
                            <th style="width: 200px;">Created Date</th>
                            <th class="text-end pe-4 text-nowrap actions-cell" style="width: 110px; white-space: nowrap;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($teams && $teams->num_rows): $i = 0; while ($row = $teams->fetch_assoc()): $i++; ?>
                            <tr class="erp-table-row" style="--row-index: <?= $i ?>;">
                                <td class="text-center text-muted fw-semibold" style="font-size: 0.85rem;"><?= $i ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="team-avatar-box me-3">
                                            <i class="fas fa-users text-primary" style="font-size: 0.95rem;"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark team-title-text"><?= h($row['team_name']) ?></div>
                                            <div class="text-muted small" style="font-size: 0.76rem;">Production Squad / Department</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <form method="post" class="d-inline m-0 p-0">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="team_id" value="<?= (int)$row['team_id'] ?>">
                                        <button type="submit" class="badge-status-pill <?= $row['status'] === 'Active' ? 'status-active-pill' : 'status-inactive-pill' ?>" onclick="return confirm('Change status of <?= addslashes(h($row['team_name'])) ?>?')" title="Click to toggle status">
                                            <span class="status-dot me-1"></span> <?= h($row['status']) ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <div class="text-muted d-flex align-items-center" style="font-size: 0.82rem;">
                                        <i class="far fa-calendar-alt me-2 text-muted" style="font-size: 0.85rem;"></i><?= formatDate($row['created_date']) ?>
                                    </div>
                                </td>
                                <td class="text-end pe-4 text-nowrap actions-cell" style="white-space: nowrap; width: 110px;">
                                    <!-- Actions Strictly in Same Horizontal Line -->
                                    <div class="action-buttons-group d-inline-flex align-items-center justify-content-end gap-1 flex-nowrap" style="white-space: nowrap;">
                                        <!-- TRIGGER EDIT TEAM MODAL VIA DATA ATTRIBUTES -->
                                        <button type="button" 
                                                class="btn btn-sm btn-light action-btn btn-edit-team" 
                                                title="Edit Team"
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editTeamModal"
                                                data-id="<?= (int)$row['team_id'] ?>"
                                                data-name="<?= h($row['team_name']) ?>">
                                            <i class="fas fa-edit text-warning"></i>
                                        </button>
                                        <form method="post" class="d-inline m-0 p-0" onsubmit="return confirm('Are you sure you want to delete team: <?= addslashes(h($row['team_name'])) ?>?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="team_id" value="<?= (int)$row['team_id'] ?>">
                                            <button type="submit" 
                                                    class="btn btn-sm btn-light action-btn btn-delete-team" 
                                                    title="Delete Team">
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
                                        <i class="fas fa-users-gear text-muted opacity-25 fa-3x mb-3"></i>
                                        <h6 class="text-secondary fw-bold">No Teams Found</h6>
                                        <p class="text-muted small mb-0">No team records matched your search or filter criteria.</p>
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
     MODAL: ADD NEW TEAM (NO PAGE NAVIGATION)
     ========================================================================= -->
<div class="modal fade" id="addTeamModal" tabindex="-1" aria-labelledby="addTeamModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content erp-modal-content border-0 shadow">
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <span class="header-icon-box me-2">
                        <i class="fas fa-folder-plus text-primary"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-dark fs-6" id="addTeamModalLabel">New Team Definition</h5>
                        <div class="text-muted" style="font-size: 0.75rem;">Create a production team or operational squad.</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" autocomplete="off" id="addTeamForm">
                <input type="hidden" name="action" value="add">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Team Name <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-users"></i></span>
                            <input type="text" name="team_name" class="form-control border-start-0 ps-0" placeholder="e.g. QC, Editorial, XML, Sales" required autofocus>
                        </div>
                        <div class="form-text text-muted" style="font-size: 0.75rem;">Enter the unique designation or name for this team.</div>
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
     MODAL: EDIT TEAM (DYNAMIC JAVASCRIPT POPULATION)
     ========================================================================= -->
<div class="modal fade" id="editTeamModal" tabindex="-1" aria-labelledby="editTeamModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content erp-modal-content border-0 shadow">
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <span class="header-icon-box me-2">
                        <i class="fas fa-pen-to-square text-warning"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-dark fs-6" id="editTeamModalLabel">Modify Team Definition</h5>
                        <div class="text-muted" style="font-size: 0.75rem;">Update the team name in your organization directory.</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" autocomplete="off" id="editTeamForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="team_id" id="modal_edit_team_id" value="<?= (int)($editTeam['team_id'] ?? 0) ?>">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Team Name <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-users"></i></span>
                            <input type="text" name="team_name" id="modal_edit_team_name" class="form-control border-start-0 ps-0" placeholder="e.g. QC, Editorial, XML" required value="<?= h($editTeam['team_name'] ?? '') ?>">
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
    const editModalEl = document.getElementById('editTeamModal');
    if (editModalEl) {
        editModalEl.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (button && button.hasAttribute('data-id')) {
                const id   = button.getAttribute('data-id') || '';
                const name = button.getAttribute('data-name') || '';

                const inputId   = document.getElementById('modal_edit_team_id');
                const inputName = document.getElementById('modal_edit_team_name');

                if (inputId) inputId.value = id;
                if (inputName) inputName.value = name;
            }
        });
    }

    // Auto-open modal if URL has ?mode=add or ?mode=edit for backward compatibility
    <?php if ($mode === 'add'): ?>
    const addModal = new bootstrap.Modal(document.getElementById('addTeamModal'));
    addModal.show();
    <?php elseif ($mode === 'edit' && $editTeam): ?>
    const editModal = new bootstrap.Modal(document.getElementById('editTeamModal'));
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

.team-avatar-box {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #f1f5f9;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: transform 0.2s ease, background-color 0.2s ease;
}

.erp-table-card table tbody tr:hover .team-avatar-box {
    background: rgba(67, 97, 238, 0.12);
    transform: scale(1.08);
}

/* Team Title */
.team-title-text {
    font-size: 0.95rem;
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

.btn-edit-team:hover {
    background: #fffbeb !important;
    border-color: #fde68a !important;
}

.btn-delete-team:hover {
    background: #fef2f2 !important;
    border-color: #fecaca !important;
}

.actions-cell {
    white-space: nowrap !important;
    width: 110px !important;
    min-width: 110px !important;
}
</style>
