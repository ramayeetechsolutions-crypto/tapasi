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
        $base = 'currency_master.php';
        if (!empty($params)) $base .= '?' . http_build_query($params);
        header("Location: $base");
        exit;
    }
}

// ---------- Handle POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $currency_code   = trim(strtoupper($_POST['currency_code'] ?? ''));
        $currency_name   = trim($_POST['currency_name'] ?? '');
        $currency_symbol = trim($_POST['currency_symbol'] ?? '');
        $exchange_rate   = (float)($_POST['exchange_rate'] ?? 1.0);
        $auto_update     = isset($_POST['auto_update']) && $_POST['auto_update'] == '1' ? 1 : 0;

        if ($currency_code === '' || strlen($currency_code) < 2 || strlen($currency_code) > 5) {
            redirect_with(['msg' => 'Valid Currency Code (e.g. USD, INR) is required.', 'type' => 'danger']);
        }
        if ($currency_name === '') {
            redirect_with(['msg' => 'Currency Name is required.', 'type' => 'danger']);
        }
        if ($exchange_rate <= 0) {
            $exchange_rate = 1.0;
        }

        if ($action === 'add') {
            // Check uniqueness
            $chk = $mysqli->prepare("SELECT currency_id FROM currency_master WHERE currency_code = ? OR currency_name = ?");
            if ($chk) {
                $chk->bind_param("ss", $currency_code, $currency_name);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0) {
                    $chk->close();
                    redirect_with(['msg' => "Currency '$currency_code' or '$currency_name' already exists.", 'type' => 'warning']);
                }
                $chk->close();
            }

            $stmt = $mysqli->prepare("INSERT INTO currency_master (currency_code, currency_name, currency_symbol, exchange_rate, auto_update, status, created_date, last_updated) VALUES (?, ?, ?, ?, ?, 'Active', NOW(), NOW())");
            if ($stmt) {
                $stmt->bind_param("sssdi", $currency_code, $currency_name, $currency_symbol, $exchange_rate, $auto_update);
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Currency '$currency_code - $currency_name' added successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error adding currency: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            } else {
                redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
            }
        }

        if ($action === 'edit') {
            $currency_id = (int)($_POST['currency_id'] ?? 0);
            if ($currency_id > 0) {
                // Check uniqueness excluding current record
                $chk = $mysqli->prepare("SELECT currency_id FROM currency_master WHERE (currency_code = ? OR currency_name = ?) AND currency_id != ?");
                if ($chk) {
                    $chk->bind_param("ssi", $currency_code, $currency_name, $currency_id);
                    $chk->execute();
                    $chk->store_result();
                    if ($chk->num_rows > 0) {
                        $chk->close();
                        redirect_with(['msg' => "Another currency with code '$currency_code' or name '$currency_name' already exists.", 'type' => 'warning']);
                    }
                    $chk->close();
                }

                $stmt = $mysqli->prepare("UPDATE currency_master SET currency_code=?, currency_name=?, currency_symbol=?, exchange_rate=?, auto_update=?, last_updated=NOW() WHERE currency_id=?");
                if ($stmt) {
                    $stmt->bind_param("sssdii", $currency_code, $currency_name, $currency_symbol, $exchange_rate, $auto_update, $currency_id);
                    if ($stmt->execute()) {
                        redirect_with(['msg' => "Currency '$currency_code' updated successfully", 'type' => 'success']);
                    } else {
                        redirect_with(['msg' => 'Error updating currency: ' . $stmt->error, 'type' => 'danger']);
                    }
                    $stmt->close();
                } else {
                    redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
                }
            }
        }
    }

    if ($action === 'delete') {
        $currency_id = (int)($_POST['currency_id'] ?? 0);
        if ($currency_id > 0) {
            $name = '';
            $q = $mysqli->prepare("SELECT currency_code, currency_name FROM currency_master WHERE currency_id = ?");
            if ($q) {
                $q->bind_param("i", $currency_id);
                $q->execute();
                $res = $q->get_result()->fetch_assoc();
                $q->close();
                if ($res) $name = $res['currency_code'] . ' (' . $res['currency_name'] . ')';
            }

            $del = $mysqli->prepare("DELETE FROM currency_master WHERE currency_id = ?");
            if ($del) {
                $del->bind_param("i", $currency_id);
                $del->execute();
                $del->close();
                redirect_with(['msg' => "Currency '$name' deleted successfully", 'type' => 'warning']);
            }
        }
    }

    if ($action === 'toggle') {
        $currency_id = (int)($_POST['currency_id'] ?? 0);
        if ($currency_id > 0) {
            $q = $mysqli->prepare("SELECT status, currency_code FROM currency_master WHERE currency_id = ?");
            if ($q) {
                $q->bind_param("i", $currency_id);
                $q->execute();
                $res = $q->get_result()->fetch_assoc();
                $q->close();
                if ($res) {
                    $newStatus = ($res['status'] === 'Active') ? 'Inactive' : 'Active';
                    $upd = $mysqli->prepare("UPDATE currency_master SET status = ?, last_updated = NOW() WHERE currency_id = ?");
                    if ($upd) {
                        $upd->bind_param("si", $newStatus, $currency_id);
                        $upd->execute();
                        $upd->close();
                        redirect_with(['msg' => "Currency '{$res['currency_code']}' status changed to $newStatus", 'type' => 'info']);
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
$sort    = trim($_GET['sort'] ?? 'currency_code');
$dir     = strtolower(trim($_GET['dir'] ?? 'asc'));

$sortable = ['currency_id', 'currency_code', 'currency_name', 'exchange_rate', 'status', 'created_date', 'last_updated'];
if (!in_array($sort, $sortable, true)) $sort = 'currency_code';
$dir = ($dir === 'desc') ? 'desc' : 'asc';

$sql = "SELECT * FROM currency_master WHERE 1=1";
$params = [];
$types = '';

if ($q !== '') {
    $sql .= " AND (currency_code LIKE ? OR currency_name LIKE ? OR currency_symbol LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($fstatus === 'Active' || $fstatus === 'Inactive') {
    $sql .= " AND status = ?";
    $params[] = $fstatus;
    $types .= 's';
}

$sql .= " ORDER BY $sort $dir";

$currencies = false;
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $currencies = $stmt->get_result();
    }
} else {
    $currencies = $mysqli->query($sql);
}

// KPI Metrics
$metricsQuery = $mysqli->query("SELECT 
    COUNT(*) as total, 
    SUM(status='Active') as active, 
    SUM(status='Inactive') as inactive,
    SUM(auto_update=1) as auto_sync
    FROM currency_master");
$metrics = $metricsQuery ? $metricsQuery->fetch_assoc() : ['total' => 0, 'active' => 0, 'inactive' => 0, 'auto_sync' => 0];

$pageTitle = "Currency Master";
$currentPage = "currency_master.php";

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
                    <div class="header-icon-box shadow-sm" style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #e8f5e9, #c8e6c9); display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-coins text-success fs-5"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.3px;">Currency Master</h4>
                        <div class="text-muted small">Manage international currencies, symbols, and exchange rates</div>
                    </div>
                </div>
            </div>
            <div class="col-md-5 col-12 text-md-end text-start">
                <button type="button" 
                        class="btn btn-primary px-3 py-2 shadow-sm erp-btn-add d-inline-flex align-items-center gap-2"
                        onclick="openAddCurrencyModal()"
                        style="border-radius: 8px; font-weight: 600;">
                    <i class="fas fa-plus"></i>
                    <span>Add Currency</span>
                </button>
            </div>
        </div>

        <!-- KPI SUMMARY CARDS -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Total Currencies</div>
                            <div class="fs-4 fw-bold text-dark mt-1"><?= (int)($metrics['total'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-light text-primary" style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-globe fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Active Currencies</div>
                            <div class="fs-4 fw-bold text-success mt-1"><?= (int)($metrics['active'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-success-subtle text-success" style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-check-circle fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Inactive Currencies</div>
                            <div class="fs-4 fw-bold text-danger mt-1"><?= (int)($metrics['inactive'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-danger-subtle text-danger" style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-ban fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Auto Sync</div>
                            <div class="fs-4 fw-bold text-info mt-1"><?= (int)($metrics['auto_sync'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-info-subtle text-info" style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-sync-alt fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SEARCH AND FILTER TOOLBAR -->
        <div class="card border-0 shadow-sm rounded-3 mb-4 filter-card">
            <div class="card-body p-3">
                <form method="get" class="row g-2 align-items-center">
                    <div class="col-lg-5 col-md-4 col-12">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="fas fa-search"></i></span>
                            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search currency code, name, or symbol..." class="form-control border-start-0" autocomplete="off">
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-3 col-6">
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active Only</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive Only</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-3 col-6">
                        <select name="sort" class="form-select">
                            <option value="currency_code" <?= $sort === 'currency_code' ? 'selected' : '' ?>>Code (A-Z)</option>
                            <option value="currency_name" <?= $sort === 'currency_name' ? 'selected' : '' ?>>Name</option>
                            <option value="exchange_rate" <?= $sort === 'exchange_rate' ? 'selected' : '' ?>>Rate</option>
                            <option value="created_date" <?= $sort === 'created_date' ? 'selected' : '' ?>>Created Date</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-2 col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-dark w-100 py-2 d-flex align-items-center justify-content-center gap-1 shadow-sm">
                            <i class="fas fa-filter fs-6"></i> <span>Filter</span>
                        </button>
                        <?php if ($q !== '' || $fstatus !== '' || $sort !== 'currency_code'): ?>
                            <a href="currency_master.php" class="btn btn-outline-secondary px-3 py-2" title="Reset Filters">
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
                            <th class="text-center py-3" style="width: 50px;">S.NO</th>
                            <th class="py-3">CURRENCY CODE</th>
                            <th class="py-3">CURRENCY NAME</th>
                            <th class="text-center py-3">SYMBOL</th>
                            <th class="text-end py-3">EXCHANGE RATE</th>
                            <th class="text-center py-3">AUTO SYNC</th>
                            <th class="text-center py-3">STATUS</th>
                            <th class="py-3">LAST UPDATED</th>
                            <th class="text-center py-3" style="width: 120px;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $i = 1;
                        if ($currencies && $currencies->num_rows > 0):
                            while ($row = $currencies->fetch_assoc()):
                                $currJson = json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                        ?>
                            <tr>
                                <td class="text-center fw-semibold text-secondary"><?= $i++ ?></td>
                                <td>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 fw-bold fs-7">
                                        <?= h($row['currency_code']) ?>
                                    </span>
                                </td>
                                <td>
                                    <strong class="text-dark"><?= h($row['currency_name']) ?></strong>
                                </td>
                                <td class="text-center">
                                    <span class="fs-6 fw-bold text-dark"><?= h($row['currency_symbol']) ?></span>
                                </td>
                                <td class="text-end">
                                    <span class="font-monospace fw-semibold"><?= number_format((float)$row['exchange_rate'], 4) ?></span>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($row['auto_update'])): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2">
                                            <i class="fas fa-check me-1"></i> Auto
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border px-2">Manual</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <form method="post" class="d-inline m-0 p-0">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="currency_id" value="<?= (int)$row['currency_id'] ?>">
                                        <button type="submit" 
                                                class="badge-status-pill <?= $row['status'] === 'Active' ? 'status-active-pill' : 'status-inactive-pill' ?>" 
                                                onclick="return confirm('Change status of <?= addslashes(h($row['currency_code'])) ?>?')" 
                                                title="Click to toggle status">
                                            <i class="fas fa-circle me-1" style="font-size: 0.55rem;"></i>
                                            <?= h($row['status']) ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <span class="text-muted small"><?= formatDate($row['last_updated'] ?? $row['created_date']) ?></span>
                                </td>
                                <td class="text-center">
                                    <div class="d-inline-flex align-items-center justify-content-center gap-1 action-btn-group">
                                        <button type="button" 
                                                class="btn btn-sm btn-light action-btn btn-edit-currency" 
                                                onclick='openEditCurrencyModal(<?= $currJson ?>)' 
                                                title="Edit Currency">
                                            <i class="fas fa-edit text-warning"></i>
                                        </button>
                                        <form method="post" class="d-inline m-0 p-0" onsubmit="return confirm('Are you sure you want to delete currency: <?= addslashes(h($row['currency_code'])) ?> (<?= addslashes(h($row['currency_name'])) ?>)?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="currency_id" value="<?= (int)$row['currency_id'] ?>">
                                            <button type="submit" 
                                                    class="btn btn-sm btn-light action-btn btn-delete-currency" 
                                                    title="Delete Currency">
                                                <i class="fas fa-trash-alt text-danger"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-5">
                                    <div class="py-4">
                                        <i class="fas fa-coins text-muted opacity-25 fa-3x mb-3"></i>
                                        <h6 class="text-secondary fw-bold">No Currencies Found</h6>
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
     MODAL: ADD / EDIT CURRENCY
     Strict Rule: Minimal header with Icon + Title + Subtitle + (X) close.
     Save/Update buttons strictly placed in modal footer.
========================================================================== -->
<div class="modal fade" id="currencyModal" tabindex="-1" aria-labelledby="currencyModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content erp-modal-content border-0 shadow">
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-3">
                    <div class="modal-icon-badge" style="width: 40px; height: 40px; border-radius: 10px; background: #e8f5e9; color: #2e7d32; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-coins fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="currencyModalLabel">Add New Currency</h5>
                        <p class="text-muted small mb-0">Configure international currency and exchange rate</p>
                    </div>
                </div>
                <!-- MINIMAL HEADER: CLOSE BUTTON ONLY -->
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" id="currencyForm" class="needs-validation">
                <input type="hidden" name="action" id="currency_action" value="add">
                <input type="hidden" name="currency_id" id="currency_id" value="">

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Currency Code <span class="text-danger">*</span></label>
                            <input type="text" 
                                   name="currency_code" 
                                   id="currency_code" 
                                   class="form-control form-control-sm text-uppercase" 
                                   maxlength="5" 
                                   placeholder="e.g. USD, EUR, INR" 
                                   required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Currency Symbol <span class="text-danger">*</span></label>
                            <input type="text" 
                                   name="currency_symbol" 
                                   id="currency_symbol" 
                                   class="form-control form-control-sm" 
                                   placeholder="e.g. $, €, ₹, £" 
                                   required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold text-secondary">Currency Name <span class="text-danger">*</span></label>
                            <input type="text" 
                                   name="currency_name" 
                                   id="currency_name" 
                                   class="form-control form-control-sm" 
                                   placeholder="e.g. US Dollar, Euro, Indian Rupee" 
                                   required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Exchange Rate <span class="text-danger">*</span></label>
                            <input type="number" 
                                   step="0.0001" 
                                   min="0.0001" 
                                   name="exchange_rate" 
                                   id="exchange_rate" 
                                   class="form-control form-control-sm" 
                                   value="1.0000" 
                                   required>
                            <div class="form-text small" style="font-size: 0.72rem;">Relative conversion multiplier</div>
                        </div>
                        <div class="col-md-6 d-flex align-items-center mt-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="auto_update" id="auto_update" value="1">
                                <label class="form-check-label small fw-semibold text-secondary" for="auto_update">
                                    Enable Auto Rate Sync
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top py-3 px-4 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary px-3 py-2 btn-sm" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary px-4 py-2 btn-sm shadow-sm" id="btnSubmitCurrency">
                        <i class="fas fa-save me-1"></i> Save Currency
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let currencyModalInstance = null;

document.addEventListener('DOMContentLoaded', function() {
    const el = document.getElementById('currencyModal');
    if (el && typeof bootstrap !== 'undefined') {
        currencyModalInstance = new bootstrap.Modal(el);
    }
});

function openAddCurrencyModal() {
    document.getElementById('currencyForm').reset();
    document.getElementById('currency_action').value = 'add';
    document.getElementById('currency_id').value = '';
    document.getElementById('currencyModalLabel').innerText = 'Add New Currency';
    document.getElementById('btnSubmitCurrency').innerHTML = '<i class="fas fa-save me-1"></i> Save Currency';
    document.getElementById('exchange_rate').value = '1.0000';
    document.getElementById('auto_update').checked = false;

    if (!currencyModalInstance) {
        currencyModalInstance = new bootstrap.Modal(document.getElementById('currencyModal'));
    }
    currencyModalInstance.show();
}

function openEditCurrencyModal(data) {
    if (!data) return;
    document.getElementById('currency_action').value = 'edit';
    document.getElementById('currency_id').value = data.currency_id || '';
    document.getElementById('currency_code').value = data.currency_code || '';
    document.getElementById('currency_name').value = data.currency_name || '';
    document.getElementById('currency_symbol').value = data.currency_symbol || '';
    document.getElementById('exchange_rate').value = data.exchange_rate || '1.0000';
    document.getElementById('auto_update').checked = (parseInt(data.auto_update) === 1);

    document.getElementById('currencyModalLabel').innerText = 'Edit Currency (' + (data.currency_code || '') + ')';
    document.getElementById('btnSubmitCurrency').innerHTML = '<i class="fas fa-save me-1"></i> Update Currency';

    if (!currencyModalInstance) {
        currencyModalInstance = new bootstrap.Modal(document.getElementById('currencyModal'));
    }
    currencyModalInstance.show();
}
</script>
