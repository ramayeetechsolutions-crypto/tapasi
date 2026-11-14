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

if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) {
        return '₹' . number_format((float)$amount, 2);
    }
}

if (!function_exists('redirect_with')) {
    function redirect_with($params = []) {
        $base = 'tds_master.php';
        if (!empty($params)) $base .= '?' . http_build_query($params);
        header("Location: $base");
        exit;
    }
}

// ---------- Handle POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $member_id         = trim($_POST['member_id'] ?? '');
        $full_name         = trim($_POST['full_name'] ?? '');
        $mobile            = trim($_POST['mobile'] ?? '');
        $category          = trim($_POST['category'] ?? '');
        $address           = trim($_POST['address'] ?? '');
        $email_id          = trim($_POST['email_id'] ?? '');
        $registration_date = trim($_POST['registration_date'] ?? '');
        $salary            = !empty($_POST['salary']) ? (float)$_POST['salary'] : 0.0;
        $tds_per           = !empty($_POST['tds_per']) ? (float)$_POST['tds_per'] : 0.0;

        $errors = [];
        if (empty($member_id)) $errors[] = 'Member ID is required';
        if (empty($full_name)) $errors[] = 'Full Name is required';
        if (empty($mobile)) $errors[] = 'Mobile is required';
        if (empty($category)) $errors[] = 'Category is required';
        if (empty($address)) $errors[] = 'Address is required';
        if (empty($email_id) || !filter_var($email_id, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid Email ID is required';
        if (empty($registration_date)) $errors[] = 'Registration Date is required';
        if ($salary < 0) $errors[] = 'Salary cannot be negative';
        if ($tds_per < 0 || $tds_per > 100) $errors[] = 'TDS Percentage must be between 0 and 100';

        if (!empty($errors)) {
            redirect_with(['msg' => implode(', ', $errors), 'type' => 'danger']);
        }

        if ($action === 'add') {
            $chk = $mysqli->prepare("SELECT id FROM tds_members WHERE member_id = ? OR email_id = ?");
            if ($chk) {
                $chk->bind_param("ss", $member_id, $email_id);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0) {
                    $chk->close();
                    redirect_with(['msg' => "Member ID '$member_id' or Email '$email_id' already exists.", 'type' => 'warning']);
                }
                $chk->close();
            }

            $stmt = $mysqli->prepare("INSERT INTO tds_members (member_id, full_name, mobile, category, address, email_id, registration_date, salary, tds_per, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW())");
            if ($stmt) {
                $stmt->bind_param("sssssssdd", $member_id, $full_name, $mobile, $category, $address, $email_id, $registration_date, $salary, $tds_per);
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Member '$full_name' added successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error adding member: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            } else {
                redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
            }
        }

        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $chk = $mysqli->prepare("SELECT id FROM tds_members WHERE (member_id = ? OR email_id = ?) AND id != ?");
                if ($chk) {
                    $chk->bind_param("ssi", $member_id, $email_id, $id);
                    $chk->execute();
                    $chk->store_result();
                    if ($chk->num_rows > 0) {
                        $chk->close();
                        redirect_with(['msg' => 'Member ID or Email already registered to another member.', 'type' => 'warning']);
                    }
                    $chk->close();
                }

                $stmt = $mysqli->prepare("UPDATE tds_members SET member_id=?, full_name=?, mobile=?, category=?, address=?, email_id=?, registration_date=?, salary=?, tds_per=? WHERE id=?");
                if ($stmt) {
                    $stmt->bind_param("sssssssddi", $member_id, $full_name, $mobile, $category, $address, $email_id, $registration_date, $salary, $tds_per, $id);
                    if ($stmt->execute()) {
                        redirect_with(['msg' => "Member '$full_name' updated successfully", 'type' => 'success']);
                    } else {
                        redirect_with(['msg' => 'Error updating member: ' . $stmt->error, 'type' => 'danger']);
                    }
                    $stmt->close();
                } else {
                    redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
                }
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $name = '';
            $q = $mysqli->prepare("SELECT full_name FROM tds_members WHERE id = ?");
            if ($q) {
                $q->bind_param("i", $id);
                $q->execute();
                $res = $q->get_result()->fetch_assoc();
                $q->close();
                if ($res) $name = $res['full_name'];
            }

            $del = $mysqli->prepare("DELETE FROM tds_members WHERE id = ?");
            if ($del) {
                $del->bind_param("i", $id);
                $del->execute();
                $del->close();
                redirect_with(['msg' => "Member '$name' deleted successfully", 'type' => 'warning']);
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $q = $mysqli->prepare("SELECT status, full_name FROM tds_members WHERE id = ?");
            if ($q) {
                $q->bind_param("i", $id);
                $q->execute();
                $res = $q->get_result()->fetch_assoc();
                $q->close();
                if ($res) {
                    $newStatus = ($res['status'] === 'Active') ? 'Inactive' : 'Active';
                    $upd = $mysqli->prepare("UPDATE tds_members SET status = ? WHERE id = ?");
                    if ($upd) {
                        $upd->bind_param("si", $newStatus, $id);
                        $upd->execute();
                        $upd->close();
                        redirect_with(['msg' => "Member '{$res['full_name']}' status changed to $newStatus", 'type' => 'info']);
                    }
                }
            }
        }
    }

    redirect_with();
}

// ---------- Data Fetching & Filtering ----------
$q         = trim($_GET['q'] ?? '');
$fcategory = trim($_GET['category'] ?? '');
$fstatus   = trim($_GET['status'] ?? '');
$sort      = trim($_GET['sort'] ?? 'created_at');
$dir       = strtolower(trim($_GET['dir'] ?? 'desc'));

$sortable = ['id', 'member_id', 'full_name', 'salary', 'tds_per', 'category', 'status', 'registration_date', 'created_at'];
if (!in_array($sort, $sortable, true)) $sort = 'created_at';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

$sql = "SELECT * FROM tds_members WHERE 1=1";
$params = [];
$types = '';

if ($q !== '') {
    $sql .= " AND (member_id LIKE ? OR full_name LIKE ? OR mobile LIKE ? OR email_id LIKE ?)";
    $like = "%$q%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'ssss';
}

if ($fcategory !== '') {
    $sql .= " AND category = ?";
    $params[] = $fcategory;
    $types .= 's';
}

if ($fstatus === 'Active' || $fstatus === 'Inactive') {
    $sql .= " AND status = ?";
    $params[] = $fstatus;
    $types .= 's';
}

$sql .= " ORDER BY $sort $dir";

$members = false;
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $members = $stmt->get_result();
    }
} else {
    $members = $mysqli->query($sql);
}

// KPI Metrics
$metricsQuery = $mysqli->query("SELECT 
    COUNT(*) as total, 
    SUM(status='Active') as active, 
    SUM(status='Inactive') as inactive,
    SUM(category='Audit') as audit_count,
    SUM(category='Consultant') as consultant_count
    FROM tds_members");
$metrics = $metricsQuery ? $metricsQuery->fetch_assoc() : ['total' => 0, 'active' => 0, 'inactive' => 0, 'audit_count' => 0, 'consultant_count' => 0];

$pageTitle = "TDS Master";
$currentPage = "tds_master.php";

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
                    <div class="header-icon-box shadow-sm" style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #e0f2f1, #b2dfdb); display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-file-invoice-dollar text-teal fs-5" style="color: #00796b;"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.3px;">TDS Members Master</h4>
                        <div class="text-muted small">Manage TDS registered personnel, consultant compensation, and withholding rates</div>
                    </div>
                </div>
            </div>
            <div class="col-md-5 col-12 text-md-end text-start">
                <button type="button" 
                        class="btn btn-primary px-3 py-2 shadow-sm erp-btn-add d-inline-flex align-items-center gap-2"
                        onclick="openAddTdsModal()"
                        style="border-radius: 8px; font-weight: 600;">
                    <i class="fas fa-plus"></i>
                    <span>Add Member</span>
                </button>
            </div>
        </div>

        <!-- KPI SUMMARY CARDS -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Total Members</div>
                            <div class="fs-4 fw-bold text-dark mt-1"><?= (int)($metrics['total'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-light text-primary" style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-users fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Active Members</div>
                            <div class="fs-4 fw-bold text-success mt-1"><?= (int)($metrics['active'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-success-subtle text-success" style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-user-check fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Audit Members</div>
                            <div class="fs-4 fw-bold text-info mt-1"><?= (int)($metrics['audit_count'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-info-subtle text-info" style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-clipboard-check fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Consultants</div>
                            <div class="fs-4 fw-bold text-warning mt-1"><?= (int)($metrics['consultant_count'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-warning-subtle text-warning" style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-user-tie fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SEARCH AND FILTER TOOLBAR -->
        <div class="card border-0 shadow-sm rounded-3 mb-4 filter-card">
            <div class="card-body p-3">
                <form method="get" class="row g-2 align-items-center">
                    <div class="col-lg-4 col-md-4 col-12">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="fas fa-search"></i></span>
                            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search name, ID, phone, email..." class="form-control border-start-0" autocomplete="off">
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-3 col-6">
                        <select name="category" class="form-select">
                            <option value="">All Categories</option>
                            <option value="Audit" <?= $fcategory === 'Audit' ? 'selected' : '' ?>>Audit</option>
                            <option value="Consultant" <?= $fcategory === 'Consultant' ? 'selected' : '' ?>>Consultant</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-2 col-6">
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active Only</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive Only</option>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-3 col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-dark w-100 py-2 d-flex align-items-center justify-content-center gap-1 shadow-sm">
                            <i class="fas fa-filter fs-6"></i> <span>Filter</span>
                        </button>
                        <?php if ($q !== '' || $fcategory !== '' || $fstatus !== ''): ?>
                            <a href="tds_master.php" class="btn btn-outline-secondary px-3 py-2" title="Reset Filters">
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
                <table class="table table-hover align-middle mb-0 erp-modern-table" style="font-size: var(--erp-table-text-size, 12.5px); white-space: nowrap;">
                    <thead>
                        <tr>
                            <th class="text-center py-3" style="width: 50px;">S.NO</th>
                            <th class="py-3">MEMBER ID</th>
                            <th class="py-3">FULL NAME</th>
                            <th class="py-3">CATEGORY</th>
                            <th class="py-3">CONTACT INFO</th>
                            <th class="text-end py-3">SALARY</th>
                            <th class="text-center py-3">TDS %</th>
                            <th class="text-center py-3">STATUS</th>
                            <th class="py-3">REG. DATE</th>
                            <th class="text-center py-3" style="width: 110px;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $i = 1;
                        if ($members && $members->num_rows > 0):
                            while ($row = $members->fetch_assoc()):
                                $tdsJson = json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                        ?>
                            <tr>
                                <td class="text-center fw-semibold text-secondary"><?= $i++ ?></td>
                                <td>
                                    <span class="badge bg-light text-dark border px-2 py-1 font-monospace">
                                        <?= h($row['member_id']) ?>
                                    </span>
                                </td>
                                <td>
                                    <strong class="text-dark fs-6"><?= h($row['full_name']) ?></strong>
                                </td>
                                <td>
                                    <span class="badge <?= $row['category'] === 'Audit' ? 'bg-info-subtle text-info border border-info-subtle' : 'bg-warning-subtle text-dark border border-warning-subtle' ?> px-2 py-1">
                                        <?= h($row['category']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div><i class="fas fa-envelope text-muted me-1 small"></i><?= h($row['email_id']) ?></div>
                                    <div class="text-muted small"><i class="fas fa-phone text-muted me-1 small"></i><?= h($row['mobile']) ?></div>
                                </td>
                                <td class="text-end font-monospace fw-semibold text-dark">
                                    <?= formatCurrency($row['salary']) ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary-subtle text-secondary px-2 py-1">
                                        <?= number_format((float)$row['tds_per'], 2) ?>%
                                    </span>
                                </td>
                                <td class="text-center">
                                    <form method="post" class="d-inline m-0 p-0">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button type="submit" 
                                                class="badge-status-pill <?= $row['status'] === 'Active' ? 'status-active-pill' : 'status-inactive-pill' ?>" 
                                                onclick="return confirm('Change status of <?= addslashes(h($row['full_name'])) ?>?')" 
                                                title="Click to toggle status">
                                            <i class="fas fa-circle me-1" style="font-size: 0.55rem;"></i>
                                            <?= h($row['status']) ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <span class="text-muted small"><?= formatDate($row['registration_date']) ?></span>
                                </td>
                                <td class="text-center">
                                    <div class="d-inline-flex align-items-center justify-content-center gap-1 action-btn-group">
                                        <button type="button" 
                                                class="btn btn-sm btn-light action-btn btn-edit-tds" 
                                                onclick='openEditTdsModal(<?= $tdsJson ?>)' 
                                                title="Edit Member">
                                            <i class="fas fa-edit text-warning"></i>
                                        </button>
                                        <form method="post" class="d-inline m-0 p-0" onsubmit="return confirm('Are you sure you want to delete member: <?= addslashes(h($row['full_name'])) ?>?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" 
                                                    class="btn btn-sm btn-light action-btn btn-delete-tds" 
                                                    title="Delete Member">
                                                <i class="fas fa-trash-alt text-danger"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr>
                                <td colspan="10" class="text-center py-5">
                                    <div class="py-4">
                                        <i class="fas fa-file-invoice-dollar text-muted opacity-25 fa-3x mb-3"></i>
                                        <h6 class="text-secondary fw-bold">No TDS Members Found</h6>
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
     MODAL: ADD / EDIT TDS MEMBER
     Minimal header: Icon + Title + Subtitle + (X) close.
     Save/Update button strictly in modal footer.
========================================================================== -->
<div class="modal fade" id="tdsModal" tabindex="-1" aria-labelledby="tdsModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content erp-modal-content border-0 shadow">
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-3">
                    <div class="modal-icon-badge" style="width: 40px; height: 40px; border-radius: 10px; background: #e0f2f1; color: #00796b; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-file-invoice-dollar fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="tdsModalLabel">Add New TDS Member</h5>
                        <p class="text-muted small mb-0">Configure member details, compensation, and TDS tax rate</p>
                    </div>
                </div>
                <!-- MINIMAL HEADER: CLOSE BUTTON ONLY -->
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" id="tdsForm" class="needs-validation">
                <input type="hidden" name="action" id="tds_action" value="add">
                <input type="hidden" name="id" id="tds_id" value="">

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Member ID <span class="text-danger">*</span></label>
                            <input type="text" name="member_id" id="tds_member_id" class="form-control form-control-sm" placeholder="e.g. TDS-001" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" id="tds_full_name" class="form-control form-control-sm" placeholder="e.g. John Doe" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Category <span class="text-danger">*</span></label>
                            <select name="category" id="tds_category" class="form-select form-select-sm" required>
                                <option value="">Select Category</option>
                                <option value="Audit">Audit</option>
                                <option value="Consultant">Consultant</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Mobile Number <span class="text-danger">*</span></label>
                            <input type="text" name="mobile" id="tds_mobile" pattern="[0-9]{10}" maxlength="10" class="form-control form-control-sm" placeholder="10-digit mobile number" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email_id" id="tds_email_id" class="form-control form-control-sm" placeholder="e.g. name@domain.com" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Registration Date <span class="text-danger">*</span></label>
                            <input type="date" name="registration_date" id="tds_registration_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Gross Salary / Retainer (₹) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" name="salary" id="tds_salary" class="form-control form-control-sm" placeholder="0.00" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">TDS Percentage (%) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" max="100" name="tds_per" id="tds_tds_per" class="form-control form-control-sm" placeholder="e.g. 10.00" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold text-secondary">Physical Address <span class="text-danger">*</span></label>
                            <textarea name="address" id="tds_address" class="form-control form-control-sm" rows="2" placeholder="Full residential/office address" required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top py-3 px-4 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary px-3 py-2 btn-sm" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary px-4 py-2 btn-sm shadow-sm" id="btnSubmitTds">
                        <i class="fas fa-save me-1"></i> Save Member
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let tdsModalInstance = null;

document.addEventListener('DOMContentLoaded', function() {
    const el = document.getElementById('tdsModal');
    if (el && typeof bootstrap !== 'undefined') {
        tdsModalInstance = new bootstrap.Modal(el);
    }
});

function openAddTdsModal() {
    document.getElementById('tdsForm').reset();
    document.getElementById('tds_action').value = 'add';
    document.getElementById('tds_id').value = '';
    document.getElementById('tds_registration_date').value = '<?= date('Y-m-d') ?>';
    document.getElementById('tdsModalLabel').innerText = 'Add New TDS Member';
    document.getElementById('btnSubmitTds').innerHTML = '<i class="fas fa-save me-1"></i> Save Member';

    if (!tdsModalInstance) {
        tdsModalInstance = new bootstrap.Modal(document.getElementById('tdsModal'));
    }
    tdsModalInstance.show();
}

function openEditTdsModal(data) {
    if (!data) return;
    document.getElementById('tdsForm').reset();
    document.getElementById('tds_action').value = 'edit';
    document.getElementById('tds_id').value = data.id || '';
    document.getElementById('tds_member_id').value = data.member_id || '';
    document.getElementById('tds_full_name').value = data.full_name || '';
    document.getElementById('tds_category').value = data.category || '';
    document.getElementById('tds_mobile').value = data.mobile || '';
    document.getElementById('tds_email_id').value = data.email_id || '';
    document.getElementById('tds_registration_date').value = data.registration_date || '';
    document.getElementById('tds_salary').value = data.salary || '';
    document.getElementById('tds_tds_per').value = data.tds_per || '';
    document.getElementById('tds_address').value = data.address || '';

    document.getElementById('tdsModalLabel').innerText = 'Edit TDS Member: ' + (data.full_name || '');
    document.getElementById('btnSubmitTds').innerHTML = '<i class="fas fa-save me-1"></i> Update Member';

    if (!tdsModalInstance) {
        tdsModalInstance = new bootstrap.Modal(document.getElementById('tdsModal'));
    }
    tdsModalInstance.show();
}
</script>
