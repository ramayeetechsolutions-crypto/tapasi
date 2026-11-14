<?php
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

$db_paths = [__DIR__ . '/../db.php', __DIR__ . '/db.php', 'db.php'];
$connected = false;
foreach ($db_paths as $p) {
    if (file_exists($p)) {
        require_once $p;
        $connected = true;
        break;
    }
}
if (!$connected) {
    die("Database connection failed.");
}

// ---------- Helpers ----------
if (!function_exists('h')) {
    function h($s) { 
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
    }
}

if (!function_exists('formatDate')) {
    function formatDate($d) {
        if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
        return date('d M, Y', strtotime($d));
    }
}

if (!function_exists('redirect_with')) {
    function redirect_with($params = []) {
        $base = 'expenses_master.php';
        if (!empty($params)) $base .= '?' . http_build_query($params);
        header("Location: $base");
        exit;
    }
}

// ---------- Handle POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $expense_name = trim($_POST['expense_name'] ?? '');

        if ($expense_name === '') {
            redirect_with(['msg' => 'Expense Name is required.', 'type' => 'danger']);
        }

        if ($action === 'add') {
            $chk = $mysqli->prepare("SELECT expense_id FROM expense_master WHERE expense_name = ?");
            if ($chk) {
                $chk->bind_param("s", $expense_name);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0) {
                    $chk->close();
                    redirect_with(['msg' => "Expense category '$expense_name' already exists.", 'type' => 'warning']);
                }
                $chk->close();
            }

            $stmt = $mysqli->prepare("INSERT INTO expense_master (expense_name, status, created_date) VALUES (?, 'Active', NOW())");
            if ($stmt) {
                $stmt->bind_param("s", $expense_name);
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Expense category '$expense_name' added successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error adding expense: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            } else {
                redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
            }
        }

        if ($action === 'edit') {
            $expense_id = (int)($_POST['expense_id'] ?? 0);
            if ($expense_id > 0) {
                $chk = $mysqli->prepare("SELECT expense_id FROM expense_master WHERE expense_name = ? AND expense_id != ?");
                if ($chk) {
                    $chk->bind_param("si", $expense_name, $expense_id);
                    $chk->execute();
                    $chk->store_result();
                    if ($chk->num_rows > 0) {
                        $chk->close();
                        redirect_with(['msg' => "Another expense category with name '$expense_name' already exists.", 'type' => 'warning']);
                    }
                    $chk->close();
                }

                $stmt = $mysqli->prepare("UPDATE expense_master SET expense_name = ? WHERE expense_id = ?");
                if ($stmt) {
                    $stmt->bind_param("si", $expense_name, $expense_id);
                    if ($stmt->execute()) {
                        redirect_with(['msg' => "Expense category '$expense_name' updated successfully", 'type' => 'success']);
                    } else {
                        redirect_with(['msg' => 'Error updating expense: ' . $stmt->error, 'type' => 'danger']);
                    }
                    $stmt->close();
                } else {
                    redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
                }
            }
        }
    }

    if ($action === 'delete') {
        $expense_id = (int)($_POST['expense_id'] ?? 0);
        if ($expense_id > 0) {
            $name = '';
            $q = $mysqli->prepare("SELECT expense_name FROM expense_master WHERE expense_id = ?");
            if ($q) {
                $q->bind_param("i", $expense_id);
                $q->execute();
                $res = $q->get_result()->fetch_assoc();
                $q->close();
                if ($res) $name = $res['expense_name'];
            }

            $del = $mysqli->prepare("DELETE FROM expense_master WHERE expense_id = ?");
            if ($del) {
                $del->bind_param("i", $expense_id);
                $del->execute();
                $del->close();
                redirect_with(['msg' => "Expense category '$name' deleted successfully", 'type' => 'warning']);
            }
        }
    }

    if ($action === 'toggle') {
        $expense_id = (int)($_POST['expense_id'] ?? 0);
        if ($expense_id > 0) {
            $q = $mysqli->prepare("SELECT status, expense_name FROM expense_master WHERE expense_id = ?");
            if ($q) {
                $q->bind_param("i", $expense_id);
                $q->execute();
                $res = $q->get_result()->fetch_assoc();
                $q->close();
                if ($res) {
                    $newStatus = ($res['status'] === 'Active') ? 'Inactive' : 'Active';
                    $upd = $mysqli->prepare("UPDATE expense_master SET status = ? WHERE expense_id = ?");
                    if ($upd) {
                        $upd->bind_param("si", $newStatus, $expense_id);
                        $upd->execute();
                        $upd->close();
                        redirect_with(['msg' => "Expense '{$res['expense_name']}' status changed to $newStatus", 'type' => 'info']);
                    }
                }
            }
        }
    }

    redirect_with();
}

// ---------- Data Fetching & Filtering ----------
$q       = trim($_GET['q'] ?? '');
$fstatus = trim($_GET['status'] ?? '');
$sort    = trim($_GET['sort'] ?? 'created_date');
$dir     = strtolower(trim($_GET['dir'] ?? 'desc'));

$sortable = ['expense_id', 'expense_name', 'status', 'created_date'];
if (!in_array($sort, $sortable, true)) $sort = 'created_date';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

$sql = "SELECT * FROM expense_master WHERE 1=1";
$params = [];
$types = '';

if ($q !== '') {
    $sql .= " AND expense_name LIKE ?";
    $like = "%$q%";
    $params[] = $like;
    $types .= 's';
}

if ($fstatus === 'Active' || $fstatus === 'Inactive') {
    $sql .= " AND status = ?";
    $params[] = $fstatus;
    $types .= 's';
}

$sql .= " ORDER BY $sort $dir";

$expenses = false;
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $expenses = $stmt->get_result();
    }
} else {
    $expenses = $mysqli->query($sql);
}

// KPI Metrics
$metricsQuery = $mysqli->query("SELECT 
    COUNT(*) as total, 
    SUM(status='Active') as active, 
    SUM(status='Inactive') as inactive 
    FROM expense_master");
$metrics = $metricsQuery ? $metricsQuery->fetch_assoc() : ['total' => 0, 'active' => 0, 'inactive' => 0];

$pageTitle = "Expense Master";
$currentPage = "expenses_master.php";

$header_paths = [__DIR__ . '/header.php', 'header.php'];
foreach ($header_paths as $hp) {
    if (file_exists($hp)) {
        require_once $hp;
        break;
    }
}
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
                $toastTitle = 'Successfully Processed';
                $toastIcon  = 'fa-check-circle';
                $toastBadge = 'Success';
                $toastClass = 'erp-toast-success';
            } elseif (stripos($toastMsg, 'status') !== false || stripos($toastMsg, 'toggle') !== false) {
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
            <div class="erp-toast-card <?= $toastClass ?>" id="erpToastCard" role="alert" aria-live="assertive">
                <div class="erp-toast-icon">
                    <i class="fas <?= $toastIcon ?>"></i>
                </div>
                <div class="erp-toast-content">
                    <div class="erp-toast-header">
                        <span class="erp-toast-title"><?= $toastTitle ?></span>
                        <span class="erp-toast-badge"><?= $toastBadge ?></span>
                    </div>
                    <div class="erp-toast-msg"><?= $toastMsg ?></div>
                </div>
                <button type="button" class="erp-toast-close" onclick="dismissErpToast()" aria-label="Close notification">&times;</button>
                <div class="erp-toast-progress"><div class="erp-toast-progress-bar"></div></div>
            </div>
        </div>
        <script>
            function dismissErpToast() {
                var el = document.getElementById('erpToastCard');
                if (el) {
                    el.classList.add('hide');
                    setTimeout(function() {
                        var wr = document.getElementById('erpToastWrapper');
                        if (wr) wr.remove();
                    }, 400);
                }
            }
            setTimeout(dismissErpToast, 4500);
        </script>
        <?php endif; ?>

        <!-- TOP BAR: TITLE & ACTIONS -->
        <div class="row align-items-center mb-4 gy-2">
            <div class="col-md-7 col-12">
                <div class="d-flex align-items-center gap-3">
                    <div class="header-icon-box shadow-sm" style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #e3f2fd, #bbdefb); display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-money-bill-wave text-primary fs-5"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.3px;">Expense Master</h4>
                        <div class="text-muted small">Manage organizational expense categories, overheads, and ledger accounts</div>
                    </div>
                </div>
            </div>
            <div class="col-md-5 col-12 text-md-end text-start">
                <button type="button" 
                        class="btn btn-primary px-3 py-2 shadow-sm erp-btn-add d-inline-flex align-items-center gap-2"
                        onclick="openAddExpenseModal()"
                        style="border-radius: 8px; font-weight: 600;">
                    <i class="fas fa-plus"></i>
                    <span>Add Expense</span>
                </button>
            </div>
        </div>

        <!-- KPI SUMMARY CARDS -->
        <div class="row g-3 mb-4">
            <div class="col-md-4 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Total Categories</div>
                            <div class="fs-4 fw-bold text-dark mt-1"><?= (int)($metrics['total'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-light text-primary" style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-tags fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Active Categories</div>
                            <div class="fs-4 fw-bold text-success mt-1"><?= (int)($metrics['active'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-success-subtle text-success" style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-check-circle fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Inactive Categories</div>
                            <div class="fs-4 fw-bold text-danger mt-1"><?= (int)($metrics['inactive'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-danger-subtle text-danger" style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-ban fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SEARCH AND FILTER TOOLBAR -->
        <div class="card border-0 shadow-sm rounded-3 mb-4 filter-card">
            <div class="card-body p-3">
                <form method="get" class="row g-2 align-items-center">
                    <div class="col-lg-6 col-md-5 col-12">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="fas fa-search"></i></span>
                            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search expense category name..." class="form-control border-start-0" autocomplete="off">
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-3 col-6">
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active Only</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive Only</option>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-4 col-6 d-flex gap-2">
                        <button type="submit" class="btn btn-dark w-100 py-2 d-flex align-items-center justify-content-center gap-1 shadow-sm">
                            <i class="fas fa-filter fs-6"></i> <span>Filter</span>
                        </button>
                        <?php if ($q !== '' || $fstatus !== ''): ?>
                            <a href="expenses_master.php" class="btn btn-outline-secondary px-3 py-2" title="Reset Filters">
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
                <table class="table table-hover align-middle mb-0 erp-modern-table" style="font-size: var(--erp-table-text-size, 13px); white-space: nowrap;">
                    <thead>
                        <tr>
                            <th class="text-center py-3" style="width: 55px;">S.NO</th>
                            <th class="py-3">EXPENSE CATEGORY NAME</th>
                            <th class="text-center py-3" style="width: 140px;">STATUS</th>
                            <th class="py-3" style="width: 160px;">CREATED DATE</th>
                            <th class="text-center py-3" style="width: 120px;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $i = 1;
                        if ($expenses && $expenses->num_rows > 0):
                            while ($row = $expenses->fetch_assoc()):
                                $expJson = json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                        ?>
                            <tr>
                                <td class="text-center fw-semibold text-secondary"><?= $i++ ?></td>
                                <td>
                                    <strong class="text-dark fs-6"><?= h($row['expense_name']) ?></strong>
                                </td>
                                <td class="text-center">
                                    <form method="post" class="d-inline m-0 p-0">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="expense_id" value="<?= (int)$row['expense_id'] ?>">
                                        <button type="submit" 
                                                class="badge-status-pill <?= $row['status'] === 'Active' ? 'status-active-pill' : 'status-inactive-pill' ?>" 
                                                onclick="return confirm('Change status of <?= addslashes(h($row['expense_name'])) ?>?')" 
                                                title="Click to toggle status">
                                            <i class="fas fa-circle me-1" style="font-size: 0.55rem;"></i>
                                            <?= h($row['status']) ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <span class="text-muted small"><?= formatDate($row['created_date']) ?></span>
                                </td>
                                <td class="text-center">
                                    <div class="d-inline-flex align-items-center justify-content-center gap-1 action-btn-group">
                                        <button type="button" 
                                                class="btn btn-sm btn-light action-btn btn-edit-expense" 
                                                onclick='openEditExpenseModal(<?= $expJson ?>)' 
                                                title="Edit Expense">
                                            <i class="fas fa-edit text-warning"></i>
                                        </button>
                                        <form method="post" class="d-inline m-0 p-0" onsubmit="return confirm('Are you sure you want to delete expense category: <?= addslashes(h($row['expense_name'])) ?>?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="expense_id" value="<?= (int)$row['expense_id'] ?>">
                                            <button type="submit" 
                                                    class="btn btn-sm btn-light action-btn btn-delete-expense" 
                                                    title="Delete Expense">
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
                                        <i class="fas fa-money-bill-wave text-muted opacity-25 fa-3x mb-3"></i>
                                        <h6 class="text-secondary fw-bold">No Expense Categories Found</h6>
                                        <p class="text-muted small mb-0">No records match your search or filter criteria.</p>
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
     MODAL: ADD / EDIT EXPENSE
     Minimal header: Icon + Title + Subtitle + (X) close.
     Save/Update button strictly in modal footer.
========================================================================== -->
<div class="modal fade" id="expenseModal" tabindex="-1" aria-labelledby="expenseModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content erp-modal-content border-0 shadow">
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-3">
                    <div class="modal-icon-badge" style="width: 40px; height: 40px; border-radius: 10px; background: #e3f2fd; color: #1976d2; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-money-bill-wave fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="expenseModalLabel">Add New Expense</h5>
                        <p class="text-muted small mb-0">Define an expense head or ledger category</p>
                    </div>
                </div>
                <!-- MINIMAL HEADER: CLOSE BUTTON ONLY -->
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" id="expenseForm" class="needs-validation">
                <input type="hidden" name="action" id="expense_action" value="add">
                <input type="hidden" name="expense_id" id="expense_id" value="">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Expense Category Name <span class="text-danger">*</span></label>
                        <input type="text" 
                               name="expense_name" 
                               id="expense_name" 
                               class="form-control" 
                               placeholder="e.g. Office Rent, Cloud Server, Internet Broadband, Travel" 
                               required>
                        <div class="form-text small" style="font-size: 0.75rem;">Enter a descriptive name for this expense account head.</div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top py-3 px-4 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary px-3 py-2 btn-sm" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary px-4 py-2 btn-sm shadow-sm" id="btnSubmitExpense">
                        <i class="fas fa-save me-1"></i> Save Expense
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let expenseModalInstance = null;

document.addEventListener('DOMContentLoaded', function() {
    const el = document.getElementById('expenseModal');
    if (el && typeof bootstrap !== 'undefined') {
        expenseModalInstance = new bootstrap.Modal(el);
    }
});

function openAddExpenseModal() {
    document.getElementById('expenseForm').reset();
    document.getElementById('expense_action').value = 'add';
    document.getElementById('expense_id').value = '';
    document.getElementById('expenseModalLabel').innerText = 'Add New Expense';
    document.getElementById('btnSubmitExpense').innerHTML = '<i class="fas fa-save me-1"></i> Save Expense';

    if (!expenseModalInstance) {
        expenseModalInstance = new bootstrap.Modal(document.getElementById('expenseModal'));
    }
    expenseModalInstance.show();
}

function openEditExpenseModal(data) {
    if (!data) return;
    document.getElementById('expense_action').value = 'edit';
    document.getElementById('expense_id').value = data.expense_id || '';
    document.getElementById('expense_name').value = data.expense_name || '';

    document.getElementById('expenseModalLabel').innerText = 'Edit Expense Category';
    document.getElementById('btnSubmitExpense').innerHTML = '<i class="fas fa-save me-1"></i> Update Expense';

    if (!expenseModalInstance) {
        expenseModalInstance = new bootstrap.Modal(document.getElementById('expenseModal'));
    }
    expenseModalInstance.show();
}
</script>
