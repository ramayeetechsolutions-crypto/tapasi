<?php
require_once __DIR__ . '/../db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// SimpleXLSX library path with fallback
$libPath = __DIR__ . '/simplexlsx-master/src/SimpleXLSX.php';
if (!file_exists($libPath)) {
    $libPath = __DIR__ . '/../SubAdmin/simplexlsx-master/src/SimpleXLSX.php';
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
        $base = 'client_master.php';
        if (!empty($params)) $base .= '?' . http_build_query($params);
        header("Location: $base");
        exit;
    }
}

// ---------------------------------------------------------
// EXCEL IMPORT HANDLER
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'SimpleXLSX library not found.', 'type' => 'danger']);
        }
        
        require_once $libPath;
        $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);

        if ($xlsx) {
            $rows = $xlsx->rows();
            $count_added = 0;
            $count_updated = 0;
            $count_skipped = 0;

            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header

                try {
                    $country           = trim($r[1] ?? 'India');
                    $currency          = trim($r[2] ?? '');
                    $payment           = trim($r[3] ?? '');
                    $frequency         = trim($r[4] ?? '');
                    
                    $date_raw          = $r[5] ?? '';
                    $date              = date('Y');
                    if (!empty($date_raw)) {
                        if (is_numeric($date_raw)) {
                            if ($date_raw >= 1900 && $date_raw <= 2100) {
                                $date = $date_raw;
                            } else {
                                $unix = ($date_raw - 25569) * 86400;
                                $date = gmdate("Y", (int)$unix);
                            }
                        } else {
                            $ts = strtotime($date_raw);
                            if ($ts !== false) {
                                $date = date('Y', $ts);
                            } else {
                                $date = substr(trim($date_raw), 0, 4);
                            }
                        }
                    }

                    $initial           = trim($r[6] ?? '');
                    $client_name       = trim($r[7] ?? '');
                    $company_name      = trim($r[8] ?? '');
                    $address           = trim($r[9] ?? '');
                    $gst_no            = trim($r[10] ?? '');
                    $invoice_email     = trim($r[11] ?? '');
                    $email_files       = trim($r[12] ?? '');
                    $email             = trim($r[13] ?? '');
                    $link              = trim($r[14] ?? '');
                    $phone             = trim($r[15] ?? '');
                    $contact_person    = trim($r[16] ?? '');
                    $gst_per           = (float)($r[17] ?? 0);
                    $cgst              = (float)($r[18] ?? 0);
                    $sgst              = (float)($r[19] ?? 0);
                    $payment_mode      = trim($r[20] ?? '');
                    $paypal_percentage = (float)($r[21] ?? 0);
                    $status            = trim($r[22] ?? 'Active');

                    $created_date_raw  = trim($r[23] ?? '');
                    $created_date      = date('Y-m-d H:i:s');
                    if (!empty($created_date_raw)) {
                        if (is_numeric($created_date_raw)) {
                            $unix = ($created_date_raw - 25569) * 86400;
                            $created_date = gmdate("Y-m-d H:i:s", (int)$unix);
                        } else {
                            $ts = strtotime($created_date_raw);
                            if ($ts !== false) $created_date = date('Y-m-d H:i:s', $ts);
                        }
                    }

                    if (empty($client_name)) {
                        $count_skipped++;
                        continue;
                    }
                    if (empty($country)) $country = 'India';
                    if (empty($currency)) $currency = ($country === 'India' ? 'INR' : 'USD');
                    if (empty($status)) $status = 'Active';
                    
                    $check = $mysqli->query("SELECT client_id FROM client_master WHERE client_name = '" . $mysqli->real_escape_string($client_name) . "'");
                    
                    if ($check && $check->num_rows > 0) {
                        $update_sql = "UPDATE client_master SET 
                            country=?, currency=?, payment=?, frequency=?, date=?, initial=?, 
                            company_name=?, address=?, gst_no=?, invoice_email=?, general_email=?, 
                            email=?, link=?, phone=?, contact_person=?, gst_per=?, cgst=?, sgst=?, 
                            payment_mode=?, paypal_percentage=?, status=?, created_date=? 
                            WHERE client_name=?";
                        $stmt_upd = $mysqli->prepare($update_sql);
                        if ($stmt_upd) {
                            $stmt_upd->bind_param("sssssssssssssssdddsssss", 
                                $country, $currency, $payment, $frequency, $date, $initial, 
                                $company_name, $address, $gst_no, $invoice_email, $email_files, 
                                $email, $link, $phone, $contact_person, $gst_per, $cgst, $sgst, 
                                $payment_mode, $paypal_percentage, $status, $created_date, $client_name);
                            $stmt_upd->execute();
                            $stmt_upd->close();
                            $count_updated++;
                        }
                    } else {
                        $insert_sql = "INSERT INTO client_master (
                            country, currency, payment, frequency, date, initial, client_name, 
                            company_name, address, gst_no, invoice_email, general_email, email, 
                            link, phone, contact_person, gst_per, cgst, sgst, payment_mode, 
                            paypal_percentage, status, created_date
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmt_ins = $mysqli->prepare($insert_sql);
                        if ($stmt_ins) {
                            $stmt_ins->bind_param("ssssssssssssssssdddssss", 
                                $country, $currency, $payment, $frequency, $date, $initial, $client_name, 
                                $company_name, $address, $gst_no, $invoice_email, $email_files, $email, 
                                $link, $phone, $contact_person, $gst_per, $cgst, $sgst, $payment_mode, 
                                $paypal_percentage, $status, $created_date);
                            $stmt_ins->execute();
                            $stmt_ins->close();
                            $count_added++;
                        }
                    }
                } catch (Exception $e) {
                    $count_skipped++;
                }
            }

            redirect_with([
                'msg' => "Excel Processed: $count_added added, $count_updated updated, $count_skipped skipped.",
                'type' => 'success'
            ]);
        } else {
            redirect_with(['msg' => 'Invalid Excel file: ' . Shuchkin\SimpleXLSX::parseError(), 'type' => 'danger']);
        }
    } else {
        redirect_with(['msg' => 'Please upload a valid Excel file.', 'type' => 'danger']);
    }
}

// ---------------------------------------------------------
// POST ACTIONS: ADD, EDIT, DELETE, TOGGLE
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $client_name         = trim($_POST['client_name'] ?? '');
        $initial             = trim($_POST['initial'] ?? '');
        $company             = trim($_POST['company_name'] ?? '');
        $contact             = trim($_POST['contact_person'] ?? '');
        $email               = trim($_POST['email'] ?? '');
        $general_email       = trim($_POST['general_email'] ?? '');
        $invoice_email       = trim($_POST['invoice_email'] ?? '');
        $production_email    = trim($_POST['production_email'] ?? '');
        $phone               = trim($_POST['phone'] ?? '');
        $additional_mobile   = trim($_POST['additional_mobile'] ?? '');
        $address             = trim($_POST['address'] ?? '');
        $additional_address  = trim($_POST['additional_address'] ?? '');
        $country             = trim($_POST['country'] ?? 'India');
        $gst                 = trim($_POST['gst_no'] ?? '');
        $pref                = trim($_POST['preferred_communication'] ?? '');
        $language            = trim($_POST['preferred_language'] ?? '');
        $currency            = trim($_POST['currency'] ?? 'INR');
        $paypal_email        = trim($_POST['paypal_email'] ?? '');
        $paypal_percentage   = (float)($_POST['paypal_percentage'] ?? 0);
        $date                = trim($_POST['date'] ?? date('Y'));
        $payment             = trim($_POST['payment'] ?? '');
        $frequency           = trim($_POST['frequency'] ?? '');
        $link                = trim($_POST['link'] ?? '');
        $payment_mode        = trim($_POST['payment_mode'] ?? '');
        $gst_per             = (float)($_POST['gst_per'] ?? 0);
        $cgst                = (float)($_POST['cgst'] ?? 0);
        $sgst                = (float)($_POST['sgst'] ?? 0);
        $word_rate           = (float)($_POST['word_rate'] ?? 0);
        $page_rate           = (float)($_POST['page_rate'] ?? 0);
        $math_rate           = (float)($_POST['math_rate'] ?? 0);
        $ref_rate            = (float)($_POST['ref_rate'] ?? 0);

        if ($client_name === '') {
            redirect_with(['msg' => 'Client Name is required.', 'type' => 'danger']);
        }

        if (strtolower($country) === 'india' || strtolower($country) === 'ind') {
            $paypal_email = '';
            $paypal_percentage = 0;
            $is_tn = (stripos($address, 'tamil') !== false || stripos($address, 'tamilnadu') !== false || stripos($address, 'tamil nadu') !== false);
            if (!$is_tn) {
                $cgst = 0;
                $sgst = 0;
            }
        } else {
            $gst = '';
            $gst_per = 0;
            $cgst = 0;
            $sgst = 0;
        }

        if ($action === 'add') {
            $status = 'Active';
            $stmt = $mysqli->prepare("INSERT INTO client_master (
                client_name, initial, company_name, contact_person, email, general_email, invoice_email, production_email,
                phone, additional_mobile, address, additional_address, country, gst_no, preferred_communication, preferred_language,
                word_rate, page_rate, math_rate, ref_rate, gst_per, cgst, sgst, currency, paypal_email, paypal_percentage, date,
                payment, frequency, link, payment_mode, status, created_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

            if ($stmt) {
                $stmt->bind_param(
                    "ssssssssssssssssdddddddssdssssss",
                    $client_name, $initial, $company, $contact, $email, $general_email, $invoice_email, $production_email,
                    $phone, $additional_mobile, $address, $additional_address, $country, $gst, $pref, $language,
                    $word_rate, $page_rate, $math_rate, $ref_rate, $gst_per, $cgst, $sgst, $currency, $paypal_email, $paypal_percentage, $date,
                    $payment, $frequency, $link, $payment_mode, $status
                );
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Client '$client_name' added successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error adding client: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            } else {
                redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
            }
        }

        if ($action === 'edit') {
            $id = (int)($_POST['client_id'] ?? 0);
            if ($id > 0) {
                $stmt = $mysqli->prepare("UPDATE client_master SET
                    client_name=?, initial=?, company_name=?, contact_person=?, email=?, general_email=?, invoice_email=?, production_email=?,
                    phone=?, additional_mobile=?, address=?, additional_address=?, country=?, gst_no=?, preferred_communication=?, preferred_language=?,
                    word_rate=?, page_rate=?, math_rate=?, ref_rate=?, gst_per=?, cgst=?, sgst=?, currency=?, paypal_email=?, paypal_percentage=?, date=?,
                    payment=?, frequency=?, link=?, payment_mode=?
                    WHERE client_id=?");
                
                if ($stmt) {
                    $stmt->bind_param(
                        "ssssssssssssssssdddddddssdsssssi",
                        $client_name, $initial, $company, $contact, $email, $general_email, $invoice_email, $production_email,
                        $phone, $additional_mobile, $address, $additional_address, $country, $gst, $pref, $language,
                        $word_rate, $page_rate, $math_rate, $ref_rate, $gst_per, $cgst, $sgst, $currency, $paypal_email, $paypal_percentage, $date,
                        $payment, $frequency, $link, $payment_mode, $id
                    );
                    if ($stmt->execute()) {
                        redirect_with(['msg' => "Client '$client_name' updated successfully", 'type' => 'success']);
                    } else {
                        redirect_with(['msg' => 'Error updating client: ' . $stmt->error, 'type' => 'danger']);
                    }
                    $stmt->close();
                } else {
                    redirect_with(['msg' => 'Database error: ' . $mysqli->error, 'type' => 'danger']);
                }
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['client_id'] ?? 0);
        $name = '';
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT client_name FROM client_master WHERE client_id=?");
            $res->bind_param("i", $id);
            $res->execute();
            $row = $res->get_result()->fetch_assoc();
            $res->close();
            if ($row) $name = $row['client_name'];

            $del = $mysqli->prepare("DELETE FROM client_master WHERE client_id=?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            redirect_with(['msg' => "Client '$name' deleted successfully", 'type' => 'warning']);
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['client_id'] ?? 0);
        if ($id > 0) {
            $res = $mysqli->prepare("SELECT status, client_name FROM client_master WHERE client_id=?");
            $res->bind_param("i", $id);
            $res->execute();
            $row = $res->get_result()->fetch_assoc();
            $res->close();
            if ($row) {
                $newStatus = ($row['status'] === 'Active') ? 'Inactive' : 'Active';
                $upd = $mysqli->prepare("UPDATE client_master SET status=? WHERE client_id=?");
                $upd->bind_param("si", $newStatus, $id);
                $upd->execute();
                $upd->close();
                redirect_with(['msg' => "Client '{$row['client_name']}' status changed to $newStatus", 'type' => 'info']);
            }
        }
    }

    if ($action !== 'import_excel') {
        redirect_with();
    }
}

// ---------------------------------------------------------
// DATA FETCHING & FILTERS
// ---------------------------------------------------------
$languages = [];
$langResult = $mysqli->query("SELECT language_name FROM language_master WHERE status='Active' ORDER BY language_name");
if ($langResult) {
    while ($r = $langResult->fetch_assoc()) {
        $languages[] = $r['language_name'];
    }
} else {
    $languages = ['English', 'Hindi', 'Spanish', 'French', 'German'];
}

$currencies = [];
$currencyResult = $mysqli->query("SELECT currency_code, currency_name FROM currency_master WHERE status='Active' ORDER BY currency_code");
if ($currencyResult) {
    while ($r = $currencyResult->fetch_assoc()) {
        $currencies[$r['currency_code']] = $r['currency_name'];
    }
} else {
    $currencies = ['USD' => 'US Dollar', 'INR' => 'Indian Rupee', 'EUR' => 'Euro', 'GBP' => 'British Pound'];
}

$q       = trim($_GET['q'] ?? '');
$fstatus = trim($_GET['status'] ?? '');
$sort    = trim($_GET['sort'] ?? 'created_date');
$dir     = strtolower(trim($_GET['dir'] ?? 'desc'));
$sortable = ['client_id','client_name','company_name','email','phone','status','created_date','word_rate','page_rate','gst_per','date'];
if (!in_array($sort, $sortable, true)) $sort = 'created_date';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

$sql = "SELECT cm.* FROM client_master cm";
$isPassive = ($fstatus === 'Passive');

if ($isPassive) {
    $sql .= " LEFT JOIN jobs j ON cm.client_id = j.client_id WHERE j.client_id IS NULL";
} else {
    $sql .= " WHERE 1=1";
}

$cond = []; $params = []; $types = '';
if ($q !== '' && !$isPassive) {
    $cond[] = "(cm.client_name LIKE ? OR cm.company_name LIKE ? OR cm.contact_person LIKE ? OR cm.email LIKE ? OR cm.phone LIKE ? OR cm.gst_no LIKE ? OR cm.initial LIKE ?)";
    $like = "%$q%";
    $params = array_fill(0, 7, $like);
    $types = str_repeat('s', count($params));
}
if (($fstatus === 'Active' || $fstatus === 'Inactive') && !$isPassive) {
    $cond[] = "cm.status=?";
    $params[] = $fstatus;
    $types .= 's';
}

if ($cond && !$isPassive) {
    $sql .= " AND " . implode(" AND ", $cond);
}

if ($isPassive) {
    $sql .= " GROUP BY cm.client_id";
}

$sql .= " ORDER BY $sort $dir";

$clients = false;
$error = '';
try {
    if ($types) {
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $clients = $stmt->get_result();
        } else {
            $error = "Prepare failed: " . $mysqli->error;
        }
    } else {
        $clients = $mysqli->query($sql);
    }
} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
}

// KPI Metrics
$metricsQuery = $mysqli->query("SELECT 
    COUNT(*) as total, 
    SUM(status='Active') as active, 
    SUM(status='Inactive') as inactive 
    FROM client_master");
$metrics = $metricsQuery ? $metricsQuery->fetch_assoc() : ['total' => 0, 'active' => 0, 'inactive' => 0];

$passiveQuery = $mysqli->query("SELECT COUNT(DISTINCT cm.client_id) as passive 
    FROM client_master cm 
    LEFT JOIN jobs j ON cm.client_id = j.client_id 
    WHERE j.client_id IS NULL");
$passiveCount = $passiveQuery ? ($passiveQuery->fetch_assoc()['passive'] ?? 0) : 0;

$pageTitle = "Client Master";
$currentPage = "client_master.php";
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
            } elseif (stripos($toastMsg, 'add') !== false || stripos($toastMsg, 'create') !== false || stripos($toastMsg, 'processed') !== false || $toastType === 'success') {
                $toastTitle = 'Successfully Processed';
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
                        <i class="fas fa-building text-primary fs-5"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.3px;">
                            Client Master 
                            <?php if ($isPassive): ?>
                                <span class="badge bg-warning text-dark fs-6 ms-2">Passive Clients</span>
                            <?php endif; ?>
                        </h4>
                        <div class="text-muted small">Manage client directory, tax configurations, and billing profiles.</div>
                    </div>
                </div>
            </div>
            <div class="col-md-5 col-12 text-md-end text-start d-flex justify-content-md-end justify-content-start gap-2">
                <button type="button" class="btn btn-warning text-dark fw-bold px-3 py-2 shadow-sm d-inline-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#importModal" style="border-radius: 8px;">
                    <i class="fas fa-file-excel"></i>
                    <span>Import Excel</span>
                </button>
                <button type="button" 
                        class="btn btn-primary px-3 py-2 shadow-sm erp-btn-add d-inline-flex align-items-center gap-2"
                        onclick="openAddClientModal()">
                    <i class="fas fa-plus"></i>
                    <span>Add Client</span>
                </button>
            </div>
        </div>

        <!-- KPI SUMMARY CARDS -->
        <div class="row g-3 mb-4">
            <div class="col-md-4 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Total Clients</div>
                            <div class="fs-4 fw-bold text-dark mt-1"><?= (int)($metrics['total'] ?? 0) ?></div>
                        </div>
                        <div class="stat-icon-circle bg-light text-primary">
                            <i class="fas fa-building"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 col-12">
                <div class="card stat-card border-0 shadow-sm p-3 rounded-3 h-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Active Clients</div>
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
                            <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Passive Clients (No Jobs)</div>
                            <div class="fs-4 fw-bold text-warning mt-1"><?= (int)$passiveCount ?></div>
                        </div>
                        <div class="stat-icon-circle bg-warning-subtle text-warning">
                            <i class="fas fa-user-clock"></i>
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
                                   placeholder="Search by client, invoice name, email, phone..." 
                                   value="<?= h($q) ?>" <?= $isPassive ? 'disabled' : '' ?>>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-3 col-6">
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                            <option value="Passive" <?= $fstatus === 'Passive' ? 'selected' : '' ?>>Passive (No Jobs Assigned)</option>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-4 col-6">
                        <select name="sort" class="form-select">
                            <option value="created_date" <?= $sort === 'created_date' ? 'selected' : '' ?>>Sort by Created Date</option>
                            <option value="client_name" <?= $sort === 'client_name' ? 'selected' : '' ?>>Sort by Client Name</option>
                            <option value="company_name" <?= $sort === 'company_name' ? 'selected' : '' ?>>Sort by Company Name</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-12 col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-dark w-100 py-2 d-flex align-items-center justify-content-center gap-1 shadow-sm">
                            <i class="fas fa-filter fs-6"></i> <span>Filter</span>
                        </button>
                        <?php if ($q !== '' || $fstatus !== '' || $sort !== 'created_date'): ?>
                            <a href="client_master.php" class="btn btn-outline-secondary px-3 py-2" title="Reset Filters">
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
                            <th class="py-3">COUNTRY</th>
                            <th class="py-3">CURRENCY</th>
                            <th class="py-3">PAYMENT</th>
                            <th class="py-3">FREQUENCY</th>
                            <th class="py-3">YOJ</th>
                            <th class="py-3">INITIAL</th>
                            <th class="py-3">CLIENT NAME</th>
                            <th class="py-3">INVOICE NAME</th>
                            <th class="py-3">INVOICE ADDRESS</th>
                            <th class="text-center py-3">STATUS</th>
                            <th class="text-center py-3" style="width: 130px;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $i = 1;
                        if ($clients && $clients->num_rows > 0):
                            while ($row = $clients->fetch_assoc()):
                                $clientJson = json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                        ?>
                            <tr>
                                <td class="text-center fw-semibold text-secondary">
                                    <?= $i++ ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border px-2 py-1">
                                        <?= h($row['country'] ?: 'India') ?>
                                    </span>
                                </td>
                                <td>
                                    <strong class="text-dark"><?= h($row['currency'] ?: 'INR') ?></strong>
                                </td>
                                <td><?= h($row['payment'] ?: '—') ?></td>
                                <td><?= h($row['frequency'] ?: '—') ?></td>
                                <td>
                                    <?php if (!empty($row['date'])): ?>
                                        <span class="badge bg-info-subtle text-info border border-info-subtle px-2"><?= h($row['date']) ?></span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="fw-semibold text-secondary"><?= h($row['initial'] ?: '—') ?></span>
                                </td>
                                <td>
                                    <a href="javascript:void(0)" class="fw-bold text-primary text-decoration-none" onclick='openEditClientModal(<?= $clientJson ?>)'>
                                        <?= h($row['client_name']) ?>
                                    </a>
                                </td>
                                <td>
                                    <div class="text-dark"><?= h($row['company_name'] ?: '—') ?></div>
                                </td>
                                <td>
                                    <div class="text-truncate text-muted" style="max-width: 200px;" title="<?= h($row['address']) ?>">
                                        <?= h($row['address'] ?: '—') ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <form method="post" class="d-inline m-0 p-0">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="client_id" value="<?= (int)$row['client_id'] ?>">
                                        <button type="submit" 
                                                class="badge-status-pill <?= $row['status'] === 'Active' ? 'status-active-pill' : 'status-inactive-pill' ?>" 
                                                onclick="return confirm('Change status of <?= addslashes(h($row['client_name'])) ?>?')" 
                                                title="Click to toggle status">
                                            <i class="fas fa-circle me-1" style="font-size: 0.55rem;"></i>
                                            <?= h($row['status']) ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="text-center">
                                    <!-- ACTIONS IN SAME LINE -->
                                    <div class="d-inline-flex align-items-center justify-content-center gap-1 action-btn-group">
                                        <a class="btn btn-sm btn-light action-btn btn-rate-list" 
                                           href="client_rate_list.php?client_id=<?= (int)$row['client_id'] ?>" 
                                           title="View Rate List">
                                            <i class="fas fa-list-alt text-info"></i>
                                        </a>
                                        <button type="button" 
                                                class="btn btn-sm btn-light action-btn btn-edit-client" 
                                                onclick='openEditClientModal(<?= $clientJson ?>)' 
                                                title="Edit Client">
                                            <i class="fas fa-edit text-warning"></i>
                                        </button>
                                        <form method="post" class="d-inline m-0 p-0" onsubmit="return confirm('Are you sure you want to delete client: <?= addslashes(h($row['client_name'])) ?>?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="client_id" value="<?= (int)$row['client_id'] ?>">
                                            <button type="submit" 
                                                    class="btn btn-sm btn-light action-btn btn-delete-client" 
                                                    title="Delete Client">
                                                <i class="fas fa-trash-alt text-danger"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr>
                                <td colspan="12" class="text-center py-5">
                                    <div class="empty-state py-4">
                                        <div class="empty-state-icon mb-3">
                                            <i class="fas fa-building fa-3x text-muted opacity-50"></i>
                                        </div>
                                        <h5 class="fw-semibold text-dark">No Clients Found</h5>
                                        <p class="text-muted small mb-3">Try adjusting your filters or search keywords, or add a new client.</p>
                                        <button type="button" class="btn btn-primary btn-sm px-3 py-2 shadow-sm" onclick="openAddClientModal()">
                                            <i class="fas fa-plus me-1"></i> Add Client
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white border-top py-3 px-4 d-flex align-items-center justify-content-between text-muted small">
                <div>Showing <strong><?= ($clients ? $clients->num_rows : 0) ?></strong> clients <?= $isPassive ? '(Passive)' : '' ?></div>
                <div>Tapasi Solutions LLP • ERP System</div>
            </div>
        </div>

    </div>
</main>

<!-- =========================================================================
     MODAL: ADD / EDIT CLIENT (IN-PAGE POPUP DIALOG)
     ========================================================================= -->
<div class="modal fade" id="clientModal" tabindex="-1" aria-labelledby="clientModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <form method="post" autocomplete="off" id="clientModalForm" class="modal-content erp-modal-content border-0 shadow">
            <input type="hidden" name="action" id="modal_client_action" value="add">
            <input type="hidden" name="client_id" id="modal_client_id" value="">

            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <span class="header-icon-box me-2">
                        <i class="fas fa-building text-primary"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-dark fs-6" id="clientModalTitle">Add New Client</h5>
                        <div class="text-muted" style="font-size: 0.75rem;">Configure corporate registration, tax IDs, contact directory, and billing settings.</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                
                <!-- SECTION 1: IDENTITY & BASIC PROFILE -->
                <div class="form-section-title mb-3 d-flex align-items-center text-primary fw-bold small text-uppercase" style="letter-spacing: 0.5px;">
                    <i class="fas fa-id-card me-2"></i> Section 1: Client & Company Identity
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-secondary small">Client Name <span class="text-danger">*</span></label>
                        <input type="text" name="client_name" id="modal_client_name" class="form-control form-control-sm" placeholder="e.g. Scigen Research & Innovation" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-secondary small">Invoice Name (Company)</label>
                        <input type="text" name="company_name" id="modal_company_name" class="form-control form-control-sm" placeholder="e.g. Scigen Research Ltd">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold text-secondary small">Initial / Code</label>
                        <input type="text" name="initial" id="modal_initial" class="form-control form-control-sm" placeholder="e.g. MELB">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold text-secondary small">Y0J (Year of Joining)</label>
                        <input type="number" name="date" id="modal_date" class="form-control form-control-sm" min="1990" max="2100" placeholder="YYYY" value="<?= date('Y') ?>">
                    </div>
                </div>

                <!-- SECTION 2: BILLING, CURRENCY & TAX CONFIGURATION -->
                <div class="form-section-title mb-3 d-flex align-items-center text-primary fw-bold small text-uppercase border-top pt-3" style="letter-spacing: 0.5px;">
                    <i class="fas fa-coins me-2"></i> Section 2: Billing, Currency & Taxes
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-secondary small">Country</label>
                        <input type="text" name="country" id="modal_country" class="form-control form-control-sm" placeholder="e.g. India" value="India" onchange="toggleModalTaxFields()" onkeyup="toggleModalTaxFields()">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-secondary small">Currency <span class="text-danger">*</span></label>
                        <select name="currency" id="modal_currency" class="form-select form-select-sm" required>
                            <?php foreach($currencies as $code => $cname): ?>
                                <option value="<?= h($code) ?>" <?= $code === 'INR' ? 'selected' : '' ?>><?= h($code) ?> - <?= h($cname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-secondary small">Payment Terms</label>
                        <select name="payment" id="modal_payment" class="form-select form-select-sm">
                            <option value="">Select Payment Terms</option>
                            <option value="Monthly">Monthly</option>
                            <option value="Bimonthly">Bimonthly</option>
                            <option value="Quarterly">Quarterly</option>
                            <option value="Mixed">Mixed</option>
                            <option value="Annually">Annually</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-secondary small">Frequency</label>
                        <select name="frequency" id="modal_frequency" class="form-select form-select-sm">
                            <option value="">Select Frequency</option>
                            <option value="Monthly">Monthly</option>
                            <option value="Bimonthly">Bimonthly</option>
                            <option value="Quarterly">Quarterly</option>
                            <option value="Random">Random</option>
                            <option value="Mixed">Mixed</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-secondary small">GST / Tax ID</label>
                        <input type="text" name="gst_no" id="modal_gst_no" class="form-control form-control-sm" placeholder="22AAAAA0000A1Z5">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-secondary small">Total GST %</label>
                        <input type="number" step="any" name="gst_per" id="modal_gst_per" class="form-control form-control-sm" placeholder="18">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-secondary small">CGST %</label>
                        <input type="number" step="0.01" name="cgst" id="modal_cgst" class="form-control form-control-sm" placeholder="9">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-secondary small">SGST %</label>
                        <input type="number" step="0.01" name="sgst" id="modal_sgst" class="form-control form-control-sm" placeholder="9">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-secondary small">Payment Mode</label>
                        <select name="payment_mode" id="modal_payment_mode" class="form-select form-select-sm">
                            <option value="Bank">Bank Transfer</option>
                            <option value="PayPal">PayPal</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-secondary small">PayPal %</label>
                        <input type="number" step="0.01" name="paypal_percentage" id="modal_paypal_percentage" class="form-control form-control-sm" placeholder="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-secondary small">PayPal Email</label>
                        <input type="email" name="paypal_email" id="modal_paypal_email" class="form-control form-control-sm" placeholder="billing@paypal.com">
                    </div>
                </div>

                <!-- SECTION 3: CONTACT & COMMUNICATION DIRECTORY -->
                <div class="form-section-title mb-3 d-flex align-items-center text-primary fw-bold small text-uppercase border-top pt-3" style="letter-spacing: 0.5px;">
                    <i class="fas fa-address-book me-2"></i> Section 3: Contact & Communication Directory
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-secondary small">Contact Person</label>
                        <input type="text" name="contact_person" id="modal_contact_person" class="form-control form-control-sm" placeholder="e.g. John Doe">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-secondary small">Primary Contact Phone</label>
                        <input type="text" name="phone" id="modal_phone" class="form-control form-control-sm" placeholder="+91 9876543210">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-secondary small">Additional Phone / Mobile</label>
                        <input type="text" name="additional_mobile" id="modal_additional_mobile" class="form-control form-control-sm" placeholder="Secondary contact">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-secondary small">Invoice Email</label>
                        <input type="email" name="invoice_email" id="modal_invoice_email" class="form-control form-control-sm" placeholder="accounts@client.com">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-secondary small">Files / Production Email</label>
                        <input type="email" name="general_email" id="modal_general_email" class="form-control form-control-sm" placeholder="files@client.com">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-secondary small">Payment Queries Email</label>
                        <input type="email" name="email" id="modal_email" class="form-control form-control-sm" placeholder="payments@client.com">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-secondary small">Additional Production Email</label>
                        <input type="text" name="production_email" id="modal_production_email" class="form-control form-control-sm" placeholder="team@client.com">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-secondary small">Preferred Communication</label>
                        <select name="preferred_communication" id="modal_preferred_communication" class="form-select form-select-sm">
                            <option value="Email">Email</option>
                            <option value="Phone">Phone</option>
                            <option value="Portal">Portal</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-secondary small">Preferred Language</label>
                        <select name="preferred_language" id="modal_preferred_language" class="form-select form-select-sm">
                            <?php foreach($languages as $lang): ?>
                                <option value="<?= h($lang) ?>"><?= h($lang) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold text-secondary small">Portal / Project Link</label>
                        <input type="url" name="link" id="modal_link" class="form-control form-control-sm" placeholder="https://client-portal.com">
                    </div>
                </div>

                <!-- SECTION 4: PHYSICAL ADDRESSES -->
                <div class="form-section-title mb-3 d-flex align-items-center text-primary fw-bold small text-uppercase border-top pt-3" style="letter-spacing: 0.5px;">
                    <i class="fas fa-map-marker-alt me-2"></i> Section 4: Physical Addresses
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-secondary small">Invoice Address</label>
                        <textarea name="address" id="modal_address" class="form-control form-control-sm" rows="3" placeholder="Registered billing address..." onkeyup="toggleModalTaxFields()"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-secondary small">Delivery / Alternate Address</label>
                        <textarea name="additional_address" id="modal_additional_address" class="form-control form-control-sm" rows="3" placeholder="Shipping or delivery destination address..."></textarea>
                    </div>
                </div>

            </div>

            <div class="modal-footer bg-light border-top px-4 py-2 d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                <button type="submit" class="btn btn-primary px-4 shadow-sm erp-btn-save d-flex align-items-center gap-2" style="font-weight: 600; border-radius: 8px;">
                    <i class="fas fa-save"></i> <span id="modal_client_submit_text">Save Details</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     MODAL: IMPORT FROM EXCEL
     ========================================================================= -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg erp-modal-content">
            <div class="modal-header bg-warning text-dark border-0 py-3 px-4">
                <h5 class="modal-title fw-bold fs-6">
                    <i class="fas fa-file-excel me-2"></i>Import Clients from Excel
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form action="" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_excel">
                
                <div class="modal-body p-4">
                    <div class="row bg-light rounded-3 p-3 mb-4 shadow-sm border g-3 align-items-center">
                        <div class="col-md-8">
                            <label class="form-label fw-bold text-dark small">Select Excel File (.xlsx, .xls)</label>
                            <input type="file" name="excel_file" class="form-control form-control-sm" accept=".xlsx, .xls" required>
                            <small class="text-muted d-block mt-1"><i class="fas fa-info-circle me-1"></i>Max file size: 10MB</small>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="button" class="btn btn-success btn-sm w-100 shadow-sm py-2" onclick="downloadSample()">
                                <i class="fas fa-download me-1"></i> Download Sample CSV
                            </button>
                        </div>
                    </div>
                    
                    <div class="alert alert-secondary border-0 shadow-sm mb-0">
                        <h6 class="fw-bold text-dark mb-2 small"><i class="fas fa-list-ol me-2 text-primary"></i>Strict 24-Column Structure Required:</h6>
                        <div class="table-responsive bg-white rounded border mb-2">
                            <table class="table table-sm table-bordered mb-0 align-middle text-center small" style="font-size: 11px;">
                                <thead class="table-light">
                                    <tr>
                                        <th>A</th><th>B</th><th>C</th><th>D</th><th>E</th><th>F</th><th>G</th><th>H</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Sl No.</td><td>Country</td><td>Currency</td><td>Payment</td>
                                        <td>Frequency</td><td>Y0J</td><td>Initial</td><td class="fw-bold text-primary">Client Name (Req)</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top px-4 py-2">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                    <button type="submit" class="btn btn-warning text-dark fw-bold px-4 shadow-sm" style="border-radius: 8px;">
                        <i class="fas fa-upload me-1"></i> Upload & Import
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Tax & PayPal toggle logic for modal
function toggleModalTaxFields() {
    const country = (document.getElementById('modal_country').value || '').trim().toLowerCase();
    const address = (document.getElementById('modal_address').value || '').toLowerCase();
    const currencySelect = document.getElementById('modal_currency');

    const gstNo = document.getElementById('modal_gst_no');
    const gstPer = document.getElementById('modal_gst_per');
    const cgst = document.getElementById('modal_cgst');
    const sgst = document.getElementById('modal_sgst');
    const paypalEmail = document.getElementById('modal_paypal_email');
    const paypalPercentage = document.getElementById('modal_paypal_percentage');

    const setFieldsState = (fields, disable, bgColor) => {
        fields.forEach(f => {
            if (f) {
                f.disabled = disable;
                f.style.backgroundColor = bgColor;
            }
        });
    };

    setFieldsState([gstNo, gstPer, cgst, sgst, paypalEmail, paypalPercentage], false, '');

    if (country === 'india' || country === 'ind') {
        const isTamilnadu = address.includes('tamil') || address.includes('tamilnadu') || address.includes('tamil nadu');
        if (isTamilnadu) {
            setFieldsState([paypalEmail, paypalPercentage], true, '#f1f5f9');
        } else {
            setFieldsState([cgst, sgst], true, '#f1f5f9');
        }
    } else {
        setFieldsState([gstNo, gstPer, cgst, sgst], true, '#f1f5f9');
    }
}

function openAddClientModal() {
    document.getElementById('modal_client_action').value = 'add';
    document.getElementById('modal_client_id').value = '';
    document.getElementById('clientModalTitle').textContent = 'Add New Client';
    document.getElementById('modal_client_submit_text').textContent = 'Save Details';

    document.getElementById('clientModalForm').reset();
    document.getElementById('modal_date').value = new Date().getFullYear();
    document.getElementById('modal_country').value = 'India';

    toggleModalTaxFields();
    const modal = new bootstrap.Modal(document.getElementById('clientModal'));
    modal.show();
}

function openEditClientModal(client) {
    if (!client) return;

    document.getElementById('modal_client_action').value = 'edit';
    document.getElementById('modal_client_id').value = client.client_id || '';
    document.getElementById('clientModalTitle').textContent = 'Modify Client Profile';
    document.getElementById('modal_client_submit_text').textContent = 'Save Details';

    // Populate fields
    const setVal = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.value = val ?? '';
    };

    setVal('modal_client_name', client.client_name);
    setVal('modal_initial', client.initial);
    setVal('modal_company_name', client.company_name);
    setVal('modal_contact_person', client.contact_person);
    setVal('modal_phone', client.phone);
    setVal('modal_additional_mobile', client.additional_mobile);
    setVal('modal_invoice_email', client.invoice_email);
    setVal('modal_general_email', client.general_email);
    setVal('modal_email', client.email);
    setVal('modal_production_email', client.production_email);
    setVal('modal_address', client.address);
    setVal('modal_additional_address', client.additional_address);
    setVal('modal_country', client.country || 'India');
    setVal('modal_gst_no', client.gst_no);
    setVal('modal_gst_per', client.gst_per);
    setVal('modal_cgst', client.cgst);
    setVal('modal_sgst', client.sgst);
    setVal('modal_currency', client.currency || 'INR');
    setVal('modal_paypal_email', client.paypal_email);
    setVal('modal_paypal_percentage', client.paypal_percentage);
    setVal('modal_date', client.date);
    setVal('modal_payment', client.payment);
    setVal('modal_frequency', client.frequency);
    setVal('modal_link', client.link);
    setVal('modal_payment_mode', client.payment_mode || 'Bank');
    setVal('modal_preferred_communication', client.preferred_communication || 'Email');
    setVal('modal_preferred_language', client.preferred_language || 'English');

    toggleModalTaxFields();
    const modal = new bootstrap.Modal(document.getElementById('clientModal'));
    modal.show();
}

function downloadSample() {
    const sampleContent = "Sl No.,Country,Currency,Payment,Frequency,Y0J,Initial,Client Name,Invoice name,Invoice address,GST,Email_Invoice,Email_Files,Email_Payment Queries,Link,Contact Number,Contact Person,GST %,CGST,SGST,Payment mode,PayPal %,Status,Created Date\n" +
                         "1,India,INR,Monthly,Monthly,2026,SCGN,Scigen Research,Scigen Research LLP,No 10 Main Road Chennai Tamilnadu,33AAAAA0000A1Z5,inv@scigen.com,files@scigen.com,pay@scigen.com,https://scigen.com,9876543210,Dr. Anand,18,9,9,Bank,0,Active,2026-03-02 10:00:00\n";
    const blob = new Blob([sampleContent], { type: 'text/csv;charset=utf-8;' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'sample_client_import.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// Check backward compatible ?mode=add or ?mode=edit in URL
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('mode') === 'add') {
        openAddClientModal();
    }
});
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

.btn-rate-list:hover {
    background: #eff6ff !important;
    border-color: #bfdbfe !important;
}

.btn-edit-client:hover {
    background: #fffbeb !important;
    border-color: #fde68a !important;
}

.btn-delete-client:hover {
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
