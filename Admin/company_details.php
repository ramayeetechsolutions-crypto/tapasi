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

if (!function_exists('formatDateTime')) {
    function formatDateTime($dt) {
        if (empty($dt) || $dt === '0000-00-00 00:00:00') return '—';
        return date('d M, Y H:i', strtotime($dt));
    }
}

if (!function_exists('redirect_with')) {
    function redirect_with($params = []) {
        $base = 'company_details.php';
        if (!empty($params)) {
            $base .= '?' . http_build_query($params);
        }
        header("Location: $base");
        exit;
    }
}

// Fetch primary company profile
$company = null;
$stmt = $mysqli->prepare("SELECT * FROM company_master ORDER BY id LIMIT 1");
if ($stmt) {
    $stmt->execute();
    $company = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ---------- Handle POST Actions ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action   = $_POST['action'] ?? 'edit';
    $id       = (int)($_POST['company_id'] ?? ($company['id'] ?? 0));
    $name     = trim($_POST['company_name'] ?? '');
    $pan      = trim($_POST['pan_number'] ?? '');
    $gst      = trim($_POST['gst_number'] ?? '');
    $tan      = trim($_POST['tan_number'] ?? '');
    $sac      = trim($_POST['sac'] ?? '');
    $bank_name    = trim($_POST['bank_name'] ?? '');
    $bank_ac_name = trim($_POST['bank_account_name'] ?? '');
    $bank_ac_no   = trim($_POST['bank_account_number'] ?? '');
    $bank_ifsc    = trim($_POST['bank_ifsc_code'] ?? '');
    $bank_swift   = trim($_POST['bank_swift_code'] ?? '');
    $address      = trim($_POST['company_address'] ?? '');
    $raddress     = trim($_POST['reg_address'] ?? '');
    $esi_details  = trim($_POST['esi_details'] ?? '');
    $esi_link     = trim($_POST['esi_link'] ?? '');
    $epf_details  = trim($_POST['epf_details'] ?? '');
    $epf_link     = trim($_POST['epf_link'] ?? '');

    if ($name === '') {
        redirect_with(['msg' => 'Company Name is required.', 'type' => 'danger']);
    }

    if ($action === 'add' || $id === 0) {
        $stmt = $mysqli->prepare("INSERT INTO company_master 
            (company_name, pan_number, gst_number, tan_number, sac, bank_name, bank_account_name, bank_account_number, bank_ifsc_code, bank_swift_code, company_address, reg_address, esi_details, esi_link, epf_details, epf_link, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssssssssssssssss", 
            $name, $pan, $gst, $tan, $sac, $bank_name, $bank_ac_name, $bank_ac_no,
            $bank_ifsc, $bank_swift, $address, $raddress, $esi_details, $esi_link, $epf_details, $epf_link);
        if ($stmt->execute()) {
            redirect_with(['msg' => "Company '$name' added successfully.", 'type' => 'success']);
        } else {
            redirect_with(['msg' => 'Error adding company: ' . $stmt->error, 'type' => 'danger']);
        }
        $stmt->close();
    } else {
        $stmt = $mysqli->prepare("UPDATE company_master 
            SET company_name=?, pan_number=?, gst_number=?, tan_number=?, sac=?, bank_name=?, bank_account_name=?, bank_account_number=?, bank_ifsc_code=?, bank_swift_code=?, company_address=?, reg_address=?, esi_details=?, esi_link=?, epf_details=?, epf_link=?
            WHERE id=?");
        $stmt->bind_param("ssssssssssssssssi", 
            $name, $pan, $gst, $tan, $sac, $bank_name, $bank_ac_name, $bank_ac_no,
            $bank_ifsc, $bank_swift, $address, $raddress, $esi_details, $esi_link, $epf_details, $epf_link, $id);
        if ($stmt->execute()) {
            redirect_with(['msg' => "Company details updated successfully.", 'type' => 'success']);
        } else {
            redirect_with(['msg' => 'Error updating company: ' . $stmt->error, 'type' => 'danger']);
        }
        $stmt->close();
    }
}

$pageTitle = "Company Master";
$currentPage = "company_details.php";
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
                $toastBadge = 'Success';
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
                        <li class="breadcrumb-item"><a href="dashboard_md.php" class="text-decoration-none text-muted"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                        <li class="breadcrumb-item text-muted">Master</li>
                        <li class="breadcrumb-item active text-primary fw-semibold" aria-current="page">Company Master</li>
                    </ol>
                </nav>
                <h3 class="mb-0 fw-bold d-flex align-items-center" style="color: #1e293b; font-size: 1.45rem;">
                    <i class="fas fa-building text-primary me-2"></i>Company Master
                </h3>
                <p class="text-muted mb-0" style="font-size: 0.85rem; margin-top: 2px;">
                    Official corporate entity records, statutory compliance, and banking profiles.
                </p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <!-- TRIGGER ADD COMPANY MODAL -->
                <button type="button" class="btn btn-sm btn-primary px-3 shadow-sm d-flex align-items-center gap-2 erp-btn-add" data-bs-toggle="modal" data-bs-target="#companyModal" onclick="openAddCompanyModal()" style="font-weight: 600; border-radius: 8px;">
                    <i class="fas fa-plus"></i> <span>Add Company Details</span>
                </button>
                <?php if ($company): ?>
                    <!-- TRIGGER EDIT COMPANY MODAL -->
                    <button type="button" class="btn btn-sm btn-outline-primary px-3 shadow-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#companyModal" onclick="openEditCompanyModal()" style="font-weight: 600; border-radius: 8px;">
                        <i class="fas fa-edit"></i> <span>Edit Profile</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($company): ?>
            <!-- 1. CORPORATE HERO CARD -->
            <div class="card corporate-hero-card mb-4 border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="corporate-avatar shadow-sm">
                                <i class="fas fa-city"></i>
                            </div>
                            <div>
                                <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                    <h4 class="mb-0 fw-bold corporate-name"><?= h($company['company_name']) ?></h4>
                                    <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size: 0.72rem; font-weight: 600;">
                                        <i class="fas fa-check-circle me-1"></i>Active Entity
                                    </span>
                                </div>
                                <div class="d-flex flex-wrap align-items-center gap-3 text-muted" style="font-size: 0.84rem;">
                                    <span><i class="fas fa-id-card text-primary me-1"></i><strong>PAN:</strong> <?= h($company['pan_number'] ?: '—') ?></span>
                                    <span class="text-secondary">•</span>
                                    <span><i class="fas fa-file-invoice-dollar text-success me-1"></i><strong>GST:</strong> <?= h($company['gst_number'] ?: '—') ?></span>
                                    <span class="text-secondary">•</span>
                                    <span><i class="fas fa-hashtag text-info me-1"></i><strong>SAC:</strong> <?= h($company['sac'] ?: '—') ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="text-md-end text-start">
                            <div class="text-muted text-uppercase" style="font-size: 0.7rem; font-weight: 700; letter-spacing: 0.5px;">Record Created</div>
                            <div class="fw-semibold text-dark" style="font-size: 0.88rem;">
                                <i class="far fa-calendar-alt text-primary me-1"></i><?= formatDateTime($company['created_at']) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. DETAILS GRID (Banking + Compliance) -->
            <div class="row g-4 mb-4">
                <!-- CARD A: BANKING & FINANCIAL CREDENTIALS -->
                <div class="col-lg-6 col-md-12">
                    <div class="card erp-detail-card h-100 border-0 shadow-sm">
                        <div class="card-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center">
                                <span class="card-indicator bg-primary"></span>
                                <h5 class="mb-0 fw-bold text-dark fs-6">
                                    <i class="fas fa-university text-primary me-2"></i>Banking Details
                                </h5>
                            </div>
                            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2 py-1" style="font-size: 0.72rem;">
                                Primary Account
                            </span>
                        </div>
                        <div class="card-body p-4">
                            <div class="detail-list">
                                <div class="detail-item">
                                    <span class="detail-label">Bank Name</span>
                                    <div class="detail-value fw-semibold text-dark">
                                        <i class="fas fa-building-columns text-muted me-1"></i><?= h($company['bank_name'] ?: '—') ?>
                                    </div>
                                </div>

                                <div class="detail-item">
                                    <span class="detail-label">Account Holder</span>
                                    <div class="detail-value text-dark fw-semibold"><?= h($company['bank_account_name'] ?: '—') ?></div>
                                </div>

                                <div class="detail-item highlight-row">
                                    <span class="detail-label">Account Number</span>
                                    <div class="detail-value d-flex align-items-center gap-2">
                                        <span class="code-badge fs-6 fw-bold"><?= h($company['bank_account_number'] ?: '—') ?></span>
                                        <?php if (!empty($company['bank_account_number'])): ?>
                                            <button type="button" class="btn btn-sm btn-light copy-btn border" onclick="copyText('<?= h($company['bank_account_number']) ?>', this)" title="Copy Account Number">
                                                <i class="far fa-copy"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="detail-item">
                                    <span class="detail-label">IFSC Code</span>
                                    <div class="detail-value d-flex align-items-center gap-2">
                                        <span class="code-badge"><?= h($company['bank_ifsc_code'] ?: '—') ?></span>
                                        <?php if (!empty($company['bank_ifsc_code'])): ?>
                                            <button type="button" class="btn btn-sm btn-light copy-btn border" onclick="copyText('<?= h($company['bank_ifsc_code']) ?>', this)" title="Copy IFSC">
                                                <i class="far fa-copy"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="detail-item border-0 pb-0">
                                    <span class="detail-label">SWIFT Code</span>
                                    <div class="detail-value d-flex align-items-center gap-2">
                                        <span class="code-badge"><?= h($company['bank_swift_code'] ?: '—') ?></span>
                                        <?php if (!empty($company['bank_swift_code'])): ?>
                                            <button type="button" class="btn btn-sm btn-light copy-btn border" onclick="copyText('<?= h($company['bank_swift_code']) ?>', this)" title="Copy SWIFT">
                                                <i class="far fa-copy"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CARD B: STATUTORY & COMPLIANCE REGISTRATIONS -->
                <div class="col-lg-6 col-md-12">
                    <div class="card erp-detail-card h-100 border-0 shadow-sm">
                        <div class="card-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center">
                                <span class="card-indicator bg-success"></span>
                                <h5 class="mb-0 fw-bold text-dark fs-6">
                                    <i class="fas fa-shield-alt text-success me-2"></i>Statutory Compliance
                                </h5>
                            </div>
                            <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size: 0.72rem;">
                                Legal & Tax
                            </span>
                        </div>
                        <div class="card-body p-4">
                            <div class="detail-list">
                                <div class="detail-item highlight-row">
                                    <span class="detail-label">PAN Number</span>
                                    <div class="detail-value d-flex align-items-center gap-2">
                                        <span class="code-badge text-primary fw-bold"><?= h($company['pan_number'] ?: '—') ?></span>
                                        <?php if (!empty($company['pan_number'])): ?>
                                            <button type="button" class="btn btn-sm btn-light copy-btn border" onclick="copyText('<?= h($company['pan_number']) ?>', this)" title="Copy PAN">
                                                <i class="far fa-copy"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="detail-item">
                                    <span class="detail-label">GST / TAN</span>
                                    <div class="detail-value">
                                        <span class="badge bg-light text-dark border px-2 py-1 me-1">GST: <?= h($company['gst_number'] ?: '—') ?></span>
                                        <span class="badge bg-light text-secondary border px-2 py-1">TAN: <?= h($company['tan_number'] ?: '—') ?></span>
                                    </div>
                                </div>

                                <div class="detail-item">
                                    <span class="detail-label">SAC Code</span>
                                    <div class="detail-value">
                                        <span class="badge bg-light text-dark border px-2 py-1"><?= h($company['sac'] ?: '—') ?></span>
                                    </div>
                                </div>

                                <div class="detail-item">
                                    <span class="detail-label">ESI Registration</span>
                                    <div class="detail-value d-flex align-items-center gap-2 flex-wrap justify-content-end">
                                        <span class="code-badge"><?= h($company['esi_details'] ?: '—') ?></span>
                                        <?php if (!empty($company['esi_details'])): ?>
                                            <button type="button" class="btn btn-sm btn-light copy-btn border" onclick="copyText('<?= h($company['esi_details']) ?>', this)" title="Copy ESI">
                                                <i class="far fa-copy"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (!empty($company['esi_link'])): ?>
                                            <a href="<?= h($company['esi_link']) ?>" target="_blank" class="btn btn-sm btn-outline-primary px-2 py-0" style="font-size: 0.76rem; border-radius: 6px;" title="Open ESI Portal">
                                                <i class="fas fa-external-link-alt me-1"></i>Portal
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="detail-item border-0 pb-0">
                                    <span class="detail-label">EPF Registration</span>
                                    <div class="detail-value d-flex align-items-center gap-2 flex-wrap justify-content-end">
                                        <span class="code-badge"><?= h($company['epf_details'] ?: '—') ?></span>
                                        <?php if (!empty($company['epf_details'])): ?>
                                            <button type="button" class="btn btn-sm btn-light copy-btn border" onclick="copyText('<?= h($company['epf_details']) ?>', this)" title="Copy EPF">
                                                <i class="far fa-copy"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (!empty($company['epf_link'])): ?>
                                            <a href="<?= h($company['epf_link']) ?>" target="_blank" class="btn btn-sm btn-outline-primary px-2 py-0" style="font-size: 0.76rem; border-radius: 6px;" title="Open EPF Portal">
                                                <i class="fas fa-external-link-alt me-1"></i>Portal
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CARD C: CORPORATE ADDRESSES -->
                <div class="col-12">
                    <div class="card erp-detail-card border-0 shadow-sm">
                        <div class="card-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center">
                                <span class="card-indicator bg-danger"></span>
                                <h5 class="mb-0 fw-bold text-dark fs-6">
                                    <i class="fas fa-map-marked-alt text-danger me-2"></i>Corporate Addresses
                                </h5>
                            </div>
                            <span class="badge rounded-pill bg-light text-secondary border px-2 py-1" style="font-size: 0.72rem;">
                                Verified Locations
                            </span>
                        </div>
                        <div class="card-body p-4">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="address-box operating-address h-100">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <span class="address-tag text-primary">
                                                <i class="fas fa-building me-1"></i> Operating / Company Office
                                            </span>
                                            <?php if (!empty($company['company_address'])): ?>
                                                <button type="button" class="btn btn-sm btn-light copy-btn border" onclick="copyText('<?= addslashes(h($company['company_address'])) ?>', this)" title="Copy Address">
                                                    <i class="far fa-copy"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <p class="address-text mb-0"><?= nl2br(h($company['company_address'] ?: 'No address specified')) ?></p>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="address-box registered-address h-100">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <span class="address-tag text-success">
                                                <i class="fas fa-landmark me-1"></i> Registered Legal Office
                                            </span>
                                            <?php if (!empty($company['reg_address'])): ?>
                                                <button type="button" class="btn btn-sm btn-light copy-btn border" onclick="copyText('<?= addslashes(h($company['reg_address'])) ?>', this)" title="Copy Address">
                                                    <i class="far fa-copy"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <p class="address-text mb-0"><?= nl2br(h($company['reg_address'] ?: 'No registered address specified')) ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- EMPTY STATE IF NO COMPANY REGISTERED -->
            <div class="card border-0 shadow-sm text-center py-5">
                <div class="py-4">
                    <i class="fas fa-city text-muted opacity-25 fa-4x mb-3"></i>
                    <h5 class="text-secondary fw-bold">No Company Profile Found</h5>
                    <p class="text-muted small mb-3">Click below to define and configure your corporate entity profile.</p>
                    <button type="button" class="btn btn-primary px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#companyModal" onclick="openAddCompanyModal()">
                        <i class="fas fa-plus me-1"></i> Add Company Details
                    </button>
                </div>
            </div>
        <?php endif; ?>

    </div>
</main>

<!-- =========================================================================
     MODAL: ADD / EDIT COMPANY MASTER (NO PAGE FLIP)
     ========================================================================= -->
<div class="modal fade" id="companyModal" tabindex="-1" aria-labelledby="companyModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form method="post" autocomplete="off" id="companyForm" class="modal-content erp-modal-content border-0 shadow">
            <input type="hidden" name="action" id="modal_action" value="edit">
            <input type="hidden" name="company_id" id="modal_company_id" value="<?= (int)($company['id'] ?? 0) ?>">

            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <span class="header-icon-box me-2">
                        <i class="fas fa-building text-primary"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-dark fs-6" id="companyModalTitle">Modify Company Profile</h5>
                        <div class="text-muted" style="font-size: 0.75rem;">Configure corporate registration, tax IDs, banking, and physical addresses.</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                <!-- SECTION 1: CORPORATE ENTITY & TAX -->
                <div class="form-section-title mb-3 d-flex align-items-center">
                    <i class="fas fa-id-card text-primary me-2"></i>Corporate Entity & Tax Information
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Company Name <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="fas fa-building"></i></span>
                            <input type="text" name="company_name" id="modal_company_name" class="form-control border-start-0 ps-0" placeholder="e.g. TAPASI Solutions LLP" required value="<?= h($company['company_name'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">PAN Number</label>
                        <input type="text" name="pan_number" id="modal_pan_number" class="form-control" placeholder="AAVFT9468Q" value="<?= h($company['pan_number'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">SAC Code</label>
                        <input type="text" name="sac" id="modal_sac" class="form-control" placeholder="998319" value="<?= h($company['sac'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">GST Number</label>
                        <input type="text" name="gst_number" id="modal_gst_number" class="form-control" placeholder="33AAVFT9468Q1ZS" value="<?= h($company['gst_number'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">TAN Number</label>
                        <input type="text" name="tan_number" id="modal_tan_number" class="form-control" placeholder="MRIT05085D" value="<?= h($company['tan_number'] ?? '') ?>">
                    </div>
                </div>

                <!-- SECTION 2: BANKING CREDENTIALS -->
                <div class="form-section-title mb-3 d-flex align-items-center">
                    <i class="fas fa-university text-primary me-2"></i>Banking Credentials
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Bank Name</label>
                        <input type="text" name="bank_name" id="modal_bank_name" class="form-control" placeholder="ICICI Bank" value="<?= h($company['bank_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Account Holder Name</label>
                        <input type="text" name="bank_account_name" id="modal_bank_account_name" class="form-control" placeholder="TAPASI Solutions LLP" value="<?= h($company['bank_account_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Account Number</label>
                        <input type="text" name="bank_account_number" id="modal_bank_account_number" class="form-control" placeholder="777705395573" value="<?= h($company['bank_account_number'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">IFSC Code</label>
                        <input type="text" name="bank_ifsc_code" id="modal_bank_ifsc_code" class="form-control" placeholder="ICIC0006128" value="<?= h($company['bank_ifsc_code'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">SWIFT Code</label>
                        <input type="text" name="bank_swift_code" id="modal_bank_swift_code" class="form-control" placeholder="ICICINBBCTS" value="<?= h($company['bank_swift_code'] ?? '') ?>">
                    </div>
                </div>

                <!-- SECTION 3: ADDRESSES -->
                <div class="form-section-title mb-3 d-flex align-items-center">
                    <i class="fas fa-map-marker-alt text-primary me-2"></i>Official Addresses
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Operating / Company Office Address</label>
                        <textarea name="company_address" id="modal_company_address" rows="3" class="form-control" placeholder="Street, City, State, Pincode..."><?= h($company['company_address'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">Registered Legal Office Address</label>
                        <textarea name="reg_address" id="modal_reg_address" rows="3" class="form-control" placeholder="Registered office address..."><?= h($company['reg_address'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- SECTION 4: STATUTORY (ESI & EPF) -->
                <div class="form-section-title mb-3 d-flex align-items-center">
                    <i class="fas fa-shield-alt text-primary me-2"></i>Statutory Compliance & Portals (ESI & EPF)
                </div>
                <div class="row g-3 mb-2">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">ESI Registration Number</label>
                        <input type="text" name="esi_details" id="modal_esi_details" class="form-control" placeholder="63001170120001099" value="<?= h($company['esi_details'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">ESI Portal URL</label>
                        <input type="url" name="esi_link" id="modal_esi_link" class="form-control" placeholder="https://portal.esic.gov.in/" value="<?= h($company['esi_link'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">EPF Registration Number</label>
                        <input type="text" name="epf_details" id="modal_epf_details" class="form-control" placeholder="CBTRY3259697000" value="<?= h($company['epf_details'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-secondary" style="font-size: 0.85rem;">EPF Portal URL</label>
                        <input type="url" name="epf_link" id="modal_epf_link" class="form-control" placeholder="https://unifiedportal-emp.epfindia.gov.in/epfo/" value="<?= h($company['epf_link'] ?? '') ?>">
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-top px-4 py-2 d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                <button type="submit" class="btn btn-primary px-4 shadow-sm erp-btn-save d-flex align-items-center gap-2" id="modal_bottom_save_btn" style="font-weight: 600; border-radius: 8px;">
                    <i class="fas fa-save"></i> <span>Save Details</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Company Modal Handlers
const companyData = <?= json_encode($company ?: new stdClass()) ?>;

function openAddCompanyModal() {
    document.getElementById('companyModalTitle').textContent = 'Add New Company Details';
    document.getElementById('modal_action').value = 'add';
    document.getElementById('modal_company_id').value = '0';
    document.getElementById('companyForm').reset();
    const bottomBtn = document.getElementById('modal_bottom_save_btn');
    if (bottomBtn) bottomBtn.innerHTML = '<i class="fas fa-save me-1"></i> Save Details';
}

function openEditCompanyModal() {
    document.getElementById('companyModalTitle').textContent = 'Modify Company Master Profile';
    document.getElementById('modal_action').value = 'edit';
    document.getElementById('modal_company_id').value = companyData.id || '0';
    const bottomBtn = document.getElementById('modal_bottom_save_btn');
    if (bottomBtn) bottomBtn.innerHTML = '<i class="fas fa-save me-1"></i> Save Details';
    
    document.getElementById('modal_company_name').value = companyData.company_name || '';
    document.getElementById('modal_pan_number').value = companyData.pan_number || '';
    document.getElementById('modal_sac').value = companyData.sac || '';
    document.getElementById('modal_gst_number').value = companyData.gst_number || '';
    document.getElementById('modal_tan_number').value = companyData.tan_number || '';
    
    document.getElementById('modal_bank_name').value = companyData.bank_name || '';
    document.getElementById('modal_bank_account_name').value = companyData.bank_account_name || '';
    document.getElementById('modal_bank_account_number').value = companyData.bank_account_number || '';
    document.getElementById('modal_bank_ifsc_code').value = companyData.bank_ifsc_code || '';
    document.getElementById('modal_bank_swift_code').value = companyData.bank_swift_code || '';
    
    document.getElementById('modal_company_address').value = companyData.company_address || '';
    document.getElementById('modal_reg_address').value = companyData.reg_address || '';
    
    document.getElementById('modal_esi_details').value = companyData.esi_details || '';
    document.getElementById('modal_esi_link').value = companyData.esi_link || '';
    document.getElementById('modal_epf_details').value = companyData.epf_details || '';
    document.getElementById('modal_epf_link').value = companyData.epf_link || '';
}

// Copy to clipboard helper
function copyText(val, btn) {
    if (!val) return;
    navigator.clipboard.writeText(val).then(function() {
        const origHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check text-success"></i>';
        setTimeout(function() {
            btn.innerHTML = origHtml;
        }, 1500);
    }).catch(function() {
        const temp = document.createElement('textarea');
        temp.value = val;
        document.body.appendChild(temp);
        temp.select();
        document.execCommand('copy');
        document.body.removeChild(temp);
        const origHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check text-success"></i>';
        setTimeout(function() {
            btn.innerHTML = origHtml;
        }, 1500);
    });
}
</script>

<style>
/* Page Animations */
.main-content {
    animation: erpFadeInUp 0.35s ease-out;
}

/* Corporate Hero Card */
.corporate-hero-card {
    border-radius: 12px;
    background: #ffffff;
    border: 1px solid #edf2f7 !important;
}
.corporate-avatar {
    width: 60px;
    height: 60px;
    min-width: 60px;
    border-radius: 12px;
    background: linear-gradient(135deg, #0984e3 0%, #6c5ce7 100%);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
}
.corporate-name {
    font-size: 1.35rem;
    color: #1e293b;
    letter-spacing: -0.3px;
}

/* Detail Cards */
.erp-detail-card {
    border-radius: 12px;
    background: #ffffff;
    border: 1px solid #edf2f7 !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.erp-detail-card:hover {
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05) !important;
}
.card-indicator {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 8px;
}

/* Detail List & Items */
.detail-list {
    display: flex;
    flex-direction: column;
}
.detail-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f1f5f9;
}
.detail-item.highlight-row {
    background: rgba(9, 132, 227, 0.03);
    padding: 10px 12px;
    border-radius: 8px;
    margin: 4px 0;
}
.detail-label {
    font-size: 0.84rem;
    color: #64748b;
    font-weight: 500;
}
.detail-value {
    font-size: 0.88rem;
    text-align: right;
}
.code-badge {
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 0.88rem;
    font-weight: 600;
    background: #f1f5f9;
    color: #1e293b;
    padding: 3px 8px;
    border-radius: 6px;
    letter-spacing: 0.5px;
}

/* Copy Button */
.copy-btn {
    width: 28px;
    height: 28px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    color: #64748b;
    background: #ffffff;
    transition: all 0.2s ease;
}
.copy-btn:hover {
    background: #e2e8f0;
    color: #1e293b;
}

/* Address Boxes */
.address-box {
    padding: 16px;
    border-radius: 10px;
    border: 1px solid #edf2f7;
    transition: all 0.2s ease;
}
.address-box.operating-address {
    background: rgba(9, 132, 227, 0.04);
    border-left: 4px solid #0984e3;
}
.address-box.registered-address {
    background: rgba(39, 174, 96, 0.04);
    border-left: 4px solid #27ae60;
}
.address-box:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
}
.address-tag {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.address-text {
    font-size: 0.88rem;
    color: #334155;
    line-height: 1.5;
    font-weight: 500;
}

/* Form Styles & Header Box Icons */
.form-section-title {
    font-size: 0.85rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #334155;
    padding-bottom: 6px;
    border-bottom: 1px solid #eef2f6;
}

.header-icon-box {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: rgba(67, 97, 238, 0.1);
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.erp-modal-content {
    border-radius: 12px;
    background: #ffffff;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
    overflow: hidden;
}

.modal.fade .modal-dialog {
    transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.25s ease-out;
    transform: translateY(-15px) scale(0.98);
}
.modal.show .modal-dialog {
    transform: translateY(0) scale(1);
}

.erp-btn-add,
.erp-btn-save {
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
}
.erp-btn-add:hover,
.erp-btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(67, 97, 238, 0.25) !important;
}
</style>
